<?php

declare(strict_types=1);

namespace Logingrupa\Metapixelshopaholic\Classes\Event;

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixelshopaholic\Classes\Exception\MetaPixelException;
use Logingrupa\Metapixelshopaholic\Classes\Helper\SiteResolver;
use Logingrupa\Metapixelshopaholic\Classes\Meta\PayloadBuilder;
use Logingrupa\Metapixelshopaholic\Classes\Queue\SendCapiEvent;
use Logingrupa\Metapixelshopaholic\Models\EventLog;
use Logingrupa\Metapixelshopaholic\Models\Settings;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Models\Status;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Phase 3 PAY-03 / PAY-10 / PAY-11 dispatch site — fires exactly ONE
 * deduplicated Purchase event via the Conversions API when an Order
 * transitions to (or is created already at) the paid status.
 *
 * Subscribed by Plugin::boot() via `Event::subscribe(OrderStatusWatcher::class)`
 * BEFORE the CLI gate so:
 *   - storefront PayPal IPN status flip → CAPI + Pixel-twin dedup (PAY-10).
 *   - backend admin status flip from bank-transfer-pending → new-payment-
 *     received fires single-channel CAPI (PAY-11).
 *   - queue worker / CLI Order reloads also see the model events.
 *
 * Phase 3.1 REFAC-07 — race-fence migrated to event_log:
 *   - Idempotency fence is now `EventLog::where(subject_type=Order,
 *     subject_id=$obOrder->id, event_name='Purchase', channel='capi',
 *     site_id=<active>)->exists()` (alreadyDispatched helper).
 *   - The legacy two-column atomic CAS on Lovata's `orders` table is
 *     GONE along with the columns themselves (Wave-1 REFAC-01 migration).
 *   - The refire-away-clear branch from Phase 3 (WR-01 column-clear) is
 *     also GONE — nulling deleted columns is a no-op. Refire semantics
 *     shift to BRIEF REFAC-07 lines 178-180:
 *       - refire ON  → watcher always proceeds; EventLogWriter's UNIQUE
 *                       collision in SendCapiEvent handles same-order rebroadcast.
 *       - refire OFF → watcher pre-checks alreadyDispatched to skip.
 *   - Race-fence ATOMICITY lives entirely inside SendCapiEvent →
 *     EventLogWriter::record (WR-12 atomic UPDATE is GONE — UNIQUE
 *     INSERT IGNORE on the event_log table replaces it).
 *
 * fireForwardDispatch is now <35 LOC (Tiger-Style <70 LOC).
 *
 * Tiger-Style boundary catch: PayloadBuilder may throw
 * OrderHasNoCurrencyException / OrderHasNoItemsException — log warning and
 * return (DO NOT rethrow — would break Order::save() cascade through Lovata
 * OrderProcessor / Campaign / PromoMechanism).
 *
 * Multi-site (T-3.1-16 mitigation): alreadyDispatched branches on
 * SiteResolver::forOrder(\$obOrder) — `whereNull('site_id')` for single-site
 * installs / CLI / queue, `where('site_id', $iSiteId)` for multi-site.
 * One site's "already dispatched" decision never suppresses another site's
 * legitimate dispatch for the same Order id (rare-but-valid scenario in
 * October 4 multi-site installations).
 *
 * Phase 3.1-07 REFAC-13: alreadyDispatched resolves via SiteResolver::forOrder
 * — eliminates cross-context site_id divergence (writer admin context vs
 * reader frontend context). Order.site_id stamped by Lovata MakeOrder at
 * create = deterministic source of truth. Closes 2026-05-14 prod bug.
 *
 * Threat model (T-03-26..28 + Phase 3.1 T-3.1-16):
 *   - The legacy idempotency column is no longer written here (columns
 *     deleted in Wave-1 REFAC-01).
 *   - Status + idempotency fences guard against spoof-dispatch (T-03-28).
 *   - Multi-site scope prevents cross-site dispatch suppression (T-3.1-16).
 */
final class OrderStatusWatcher
{
    /**
     * WR-08 lock: in-process status_id → code cache. Each handleUpdated may
     * fire one Status::where('id', $id)->value('code') query (current status),
     * plus the disabled flag lookup. On a bulk-admin save of N orders this
     * becomes N status table queries without caching.
     *
     * Caching at the class-static level (request-scoped) collapses the
     * lookups to 1 query per distinct status_id per request. Status table is
     * a very small bounded set (Lovata ships < 10 codes by default; .lv
     * operator adds a handful). The map is reset between requests because
     * PHP-FPM workers reset class statics on shutdown; tests can call
     * flushStatusCache() in tearDown.
     *
     * Phase 3.1 REFAC-07: the second cache hit (status_id original-value
     * lookup via the removed isAwayFromPaid helper) is gone with the
     * deleted refire-away-clear branch.
     *
     * @var array<int, string>
     */
    private static array $arStatusCodeCache = [];

    /**
     * Bind `eloquent.updated` + `eloquent.created` on the Order model.
     *
     * Plugin::boot() calls `Event::subscribe(OrderStatusWatcher::class)`;
     * Laravel resolves the class via the container and calls subscribe()
     * with the global event dispatcher.
     */
    public function subscribe(Dispatcher $obEvents): void
    {
        $obEvents->listen('eloquent.updated: '.Order::class, [$this, 'handleUpdated']);
        $obEvents->listen('eloquent.created: '.Order::class, [$this, 'handleCreated']);
    }

    /**
     * Reset the in-process status_id → code cache. Test hook (tearDown).
     */
    public static function flushStatusCache(): void
    {
        self::$arStatusCodeCache = [];
    }

    /**
     * Look up a status code from status_id, caching the result per-request.
     * Returns '' when the status_id has no matching row (orphan reference).
     */
    private function lookupStatusCode(int $iStatusId): string
    {
        if ($iStatusId <= 0) {
            return '';
        }
        if (! isset(self::$arStatusCodeCache[$iStatusId])) {
            $mCode = Status::where('id', $iStatusId)->value('code');
            self::$arStatusCodeCache[$iStatusId] = is_scalar($mCode) ? (string) $mCode : '';
        }

        return self::$arStatusCodeCache[$iStatusId];
    }

    /**
     * Order model updated. Refire-aware status fence → EventLog existence
     * fence → dispatch. Phase 3.1 REFAC-07: the refire-away-clear column
     * branch is GONE (columns deleted).
     */
    public function handleUpdated(Order $obOrder): void
    {
        if ($this->isPluginDisabled()) {
            return;
        }

        $sPaidCode = $this->readPaidStatusCode();

        // Status fence: only fire when the CURRENT status code matches the
        // configured paid_status_code (default 'new-payment-received').
        if (! $this->isAtPaidStatus($obOrder, $sPaidCode)) {
            return;
        }

        // Idempotency fence (REFAC-07 semantics):
        //   refire_purchase_on_status_flip=OFF (default) → skip if event_log
        //     has a 'Purchase'/'capi' row for this Order on the active site.
        //   refire_purchase_on_status_flip=ON            → proceed regardless;
        //     SendCapiEvent's EventLogWriter::record will collide on UNIQUE
        //     if no flip-away preceded → no double-POST.
        if (! $this->readRefireFlag() && $this->alreadyDispatched($obOrder)) {
            return;
        }

        $this->fireForwardDispatch($obOrder);
    }

    /**
     * Order model created. CONTEXT Area 2 Q2 — covers admin-created-already-paid
     * orders (imports, seeds, manual entry). Refire-flip logic does not apply
     * (no original status to flip away from). Same gating as the forward-fire
     * branch of handleUpdated, with the EventLog existence fence in place of
     * the deleted column-NULL guard.
     */
    public function handleCreated(Order $obOrder): void
    {
        if ($this->isPluginDisabled()) {
            return;
        }

        if (! $this->isAtPaidStatus($obOrder, $this->readPaidStatusCode())) {
            return;
        }

        if (! $this->readRefireFlag() && $this->alreadyDispatched($obOrder)) {
            return;
        }

        $this->fireForwardDispatch($obOrder);
    }

    /**
     * Generate UUIDv4 + event_time, build payload, schedule SendCapiEvent
     * dispatch after the wrapping transaction commits.
     *
     * Phase 3.1 REFAC-07:
     *   - WR-12 atomic-CAS UPDATE on the orders table is GONE (columns
     *     deleted). Race-fence atomicity now lives inside SendCapiEvent →
     *     EventLogWriter::record (UNIQUE INSERT IGNORE on event_log).
     *   - WR-13 lock preserved — afterCommit-deferred queue dispatch so a
     *     rolled-back transaction never POSTs to Meta.
     *
     * The dispatch tail is shared between handleUpdated and handleCreated
     * to keep both methods' cyclomatic complexity ≤ 10 (PHPMD).
     */
    private function fireForwardDispatch(Order $obOrder): void
    {
        $sUuid = Uuid::uuid4()->toString();
        $iEventTime = time();
        $iOrderId = $this->intOrZero($obOrder->getAttribute('id'));

        if ($iOrderId <= 0) {
            // Defensive — handleUpdated/handleCreated only fire on persisted
            // Order instances, but fail-safe rather than dispatch on id=0.
            return;
        }

        try {
            $arPayload = (new PayloadBuilder)->buildPurchaseEventPayload(
                $obOrder,
                $sUuid,
                $iEventTime,
            );
        } catch (MetaPixelException $obException) {
            // Boundary catch: PayloadBuilder precondition failure (no
            // currency, no items, invalid event_id). Log warning and
            // return — do NOT rethrow (would cascade through Order::save).
            // The manual replay path (Phase 5 HARD-02) is the operator
            // recovery for a precondition-failure dispatch.
            Log::warning('Metapixel: PayloadBuilder precondition failed — Purchase NOT dispatched', [
                'meta_pixel.order_id' => $iOrderId,
                'meta_pixel.event_id' => $sUuid,
                'meta_pixel.exception' => get_class($obException),
                'meta_pixel.message' => $obException->getMessage(),
            ]);

            return;
        }

        $sOrderNumber = $this->stringOrEmpty($obOrder->getAttribute('order_number'));
        DB::afterCommit(static function () use ($arPayload, $iOrderId, $sOrderNumber, $sUuid, $obOrder): void {
            // SCE-03 v1.1.0 update: third arg is $obOrder (Phase 3.1 REFAC-06).
            SendCapiEvent::dispatch('Purchase', $arPayload, $obOrder);
            Log::info('Metapixel: Purchase dispatched', [
                'meta_pixel.order_id' => $iOrderId,
                'meta_pixel.order_number' => $sOrderNumber,
                'meta_pixel.event_id' => $sUuid,
            ]);
        });
    }

    /**
     * Existence check on event_log for CAPI Purchase row scoped to this
     * Order + Order.site_id. Replaces legacy Phase-3 column-NULL check
     * on Lovata's orders table (columns deleted Wave-1 REFAC-01).
     *
     * Phase 3.1-07 REFAC-13: resolves via SiteResolver::forOrder(\$obOrder)
     * — eliminates cross-context site_id divergence (writer admin context
     * vs reader frontend context). Same Order, same value, every caller.
     *
     * Multi-site (T-3.1-16): forOrder null → whereNull('site_id') matches
     * single-site / pre-Lovata-v1.33 rows. forOrder int → where equality.
     * MySQL UNIQUE NULL-distinct keeps single-site + multi-site rows on
     * same table without collision.
     */
    private function alreadyDispatched(Order $obOrder): bool
    {
        $iSubjectId = $this->intOrZero($obOrder->getAttribute('id'));
        if ($iSubjectId <= 0) {
            return false;
        }
        $iSiteId = SiteResolver::forOrder($obOrder);

        $obQuery = EventLog::where('subject_type', Order::class)
            ->where('subject_id', $iSubjectId)
            ->where('event_name', EventLog::EVENT_PURCHASE)
            ->where('channel', EventLog::CHANNEL_CAPI);

        if ($iSiteId === null) {
            $obQuery->whereNull('site_id');
        } else {
            $obQuery->where('site_id', $iSiteId);
        }

        return $obQuery->exists();
    }

    private function isPluginDisabled(): bool
    {
        // Phase 2 02-04 PixelHeadTest confirms the `metapixel.disabled`
        // container binding is reliable post-boot. Throwable from App::make
        // is treated as defensive — if the singleton was flushed between
        // boot and handler-fire, treat as disabled (fail-safe).
        try {
            return (bool) App::make('metapixel.disabled');
        } catch (Throwable $obException) {
            Log::warning('Metapixel: PluginGuard container lookup failed — treating as disabled', [
                'meta_pixel.exception' => get_class($obException),
                'meta_pixel.message' => $obException->getMessage(),
            ]);

            return true;
        }
    }

    private function readPaidStatusCode(): string
    {
        $mValue = Settings::get('paid_status_code', 'new-payment-received');

        return is_scalar($mValue) ? (string) $mValue : 'new-payment-received';
    }

    private function readRefireFlag(): bool
    {
        $mValue = Settings::get('refire_purchase_on_status_flip', false);

        return is_scalar($mValue) ? (bool) $mValue : false;
    }

    /**
     * Resolve the Order's current status code. Prefers the eager-loaded
     * `status` relation when available; falls back to the cached status_id
     * lookup (WR-08) to keep the test harness (no eager-load chain) green
     * AND avoid N+1 queries on bulk admin saves.
     */
    private function isAtPaidStatus(Order $obOrder, string $sPaidCode): bool
    {
        $mRelation = $obOrder->getRelationValue('status');
        if (is_object($mRelation) && method_exists($mRelation, 'getAttribute')) {
            $mCode = $mRelation->getAttribute('code');
            if (is_scalar($mCode)) {
                return (string) $mCode === $sPaidCode;
            }
        }

        $iStatusId = $this->intOrZero($obOrder->getAttribute('status_id'));

        return $this->lookupStatusCode($iStatusId) === $sPaidCode;
    }

    private function stringOrEmpty(mixed $mValue): string
    {
        if ($mValue === null) {
            return '';
        }
        if (! is_scalar($mValue)) {
            return '';
        }

        return (string) $mValue;
    }

    private function intOrZero(mixed $mValue): int
    {
        if (is_int($mValue)) {
            return $mValue;
        }
        if (is_string($mValue) && is_numeric($mValue)) {
            return (int) $mValue;
        }
        if (is_float($mValue)) {
            return (int) $mValue;
        }

        return 0;
    }
}
