<?php

declare(strict_types=1);

namespace Logingrupa\Metapixelshopaholic\Classes\Event;

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixelshopaholic\Classes\Exception\MetaPixelException;
use Logingrupa\Metapixelshopaholic\Classes\Meta\PayloadBuilder;
use Logingrupa\Metapixelshopaholic\Classes\Queue\SendCapiEvent;
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
 * Idempotency fence (PAY-03): the `meta_purchase_event_id` DB column on
 * `lovata_orders_shopaholic_orders` is the DB-level dedup guard — a paid
 * status save with the column already populated is a no-op. The companion
 * `meta_purchase_event_time` column lets the browser-side PurchasePixel twin
 * (components/PurchasePixel.php) read the SAME timestamp the server used so
 * Meta dedups Pixel + CAPI within its ±10 s event_time window.
 *
 * Write-path discipline: every model mutation inside a handler MUST use
 * `saveQuietly()` (no observer recursion). Two writes are possible per
 * handleUpdated call:
 *   1. Away-transition clear (refire-flip ON only) — clears BOTH columns.
 *   2. Forward-fire (paid + event_id IS NULL) — sets BOTH columns + dispatch.
 *
 * Tiger-Style boundary catch: PayloadBuilder may throw
 * OrderHasNoCurrencyException / OrderHasNoItemsException — log warning and
 * return (DO NOT rethrow — would break Order::save() cascade through Lovata
 * OrderProcessor / Campaign / PromoMechanism).
 *
 * Threat model (T-03-26..28):
 *   - meta_purchase_event_id is only written here (T-03-26 mitigation).
 *   - saveQuietly suppresses model observers — no infinite recursion (T-03-27).
 *   - Status + idempotency fences guard against spoof-dispatch (T-03-28).
 */
final class OrderStatusWatcher
{
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
     * Order model updated. Refire-flip away-transition clear → status fence →
     * idempotency fence → UUID + event_time persist → dispatch.
     */
    public function handleUpdated(Order $obOrder): void
    {
        if ($this->isPluginDisabled()) {
            return;
        }

        $sPaidCode = $this->readPaidStatusCode();
        $bRefire = $this->readRefireFlag();

        // Refire-flip ON branch: status flipped AWAY from paid → clear both
        // dedup columns so the next back-to-paid flip re-fires. Phase-3
        // default ($bRefire === false) skips this entirely; status flip-flop
        // never re-fires because the fence below stays populated.
        if ($bRefire && $this->isAwayFromPaid($obOrder, $sPaidCode)) {
            $obOrder->setAttribute('meta_purchase_event_id', null);
            $obOrder->setAttribute('meta_purchase_event_time', null);
            $obOrder->saveQuietly();
            Log::info('Metapixel: cleared meta_purchase_event_id + event_time on away-transition', [
                'meta_pixel.order_id' => $this->intOrZero($obOrder->getAttribute('id')),
                'meta_pixel.order_number' => $this->stringOrEmpty($obOrder->getAttribute('order_number')),
            ]);
            // Continue — the same handleUpdated call may also be the
            // back-to-paid transition (rare but valid: a single save that
            // crosses from paid → other → paid is collapsed by Eloquent
            // into one event firing, so the away-clear above happens and
            // then the status fence below admits the forward-fire).
        }

        // Status fence: only fire when the CURRENT status code matches the
        // configured paid_status_code (default 'new-payment-received').
        if (! $this->isAtPaidStatus($obOrder, $sPaidCode)) {
            return;
        }

        // Idempotency fence: column already populated = Purchase already
        // dispatched (or in flight in the queue). No-op.
        if ($obOrder->getAttribute('meta_purchase_event_id') !== null) {
            return;
        }

        $this->fireForwardDispatch($obOrder);
    }

    /**
     * Order model created. CONTEXT Area 2 Q2 — covers admin-created-already-paid
     * orders (imports, seeds, manual entry). Refire-flip logic does not apply
     * (no original status to flip away from). Same guard + dispatch as the
     * forward-fire branch of handleUpdated.
     */
    public function handleCreated(Order $obOrder): void
    {
        if ($this->isPluginDisabled()) {
            return;
        }

        $sPaidCode = $this->readPaidStatusCode();

        if (! $this->isAtPaidStatus($obOrder, $sPaidCode)) {
            return;
        }

        if ($obOrder->getAttribute('meta_purchase_event_id') !== null) {
            return;
        }

        $this->fireForwardDispatch($obOrder);
    }

    /**
     * Generate UUIDv4 + event_time, persist BOTH columns atomically via a
     * single saveQuietly, build payload (soft-skip on MetaPixelException),
     * dispatch SendCapiEvent. The dispatch tail is shared between
     * handleUpdated and handleCreated to keep both methods' cyclomatic
     * complexity ≤ 10 (PHPMD).
     */
    private function fireForwardDispatch(Order $obOrder): void
    {
        $sUuid = Uuid::uuid4()->toString();
        $iEventTime = time();

        // Persist BOTH columns BEFORE dispatch so the browser-side
        // PurchasePixel twin (components/PurchasePixel.php) reads the same
        // event_time as the CAPI dispatch (±10 s Meta dedup window). If
        // PayloadBuilder later throws, the columns are still set — the
        // Phase-5 manual replay path (FailedEvent table) is the recovery,
        // NOT re-firing on a re-save.
        $obOrder->setAttribute('meta_purchase_event_id', $sUuid);
        $obOrder->setAttribute('meta_purchase_event_time', $iEventTime);
        $obOrder->saveQuietly();

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
            // The saveQuietly above already wrote the UUID; the manual
            // replay path (Phase 5 HARD-02) is the operator recovery.
            Log::warning('Metapixel: PayloadBuilder precondition failed — Purchase NOT dispatched', [
                'meta_pixel.order_id' => $this->intOrZero($obOrder->getAttribute('id')),
                'meta_pixel.event_id' => $sUuid,
                'meta_pixel.exception' => get_class($obException),
                'meta_pixel.message' => $obException->getMessage(),
            ]);

            return;
        }

        SendCapiEvent::dispatch('Purchase', $arPayload);
        Log::info('Metapixel: Purchase dispatched', [
            'meta_pixel.order_id' => $this->intOrZero($obOrder->getAttribute('id')),
            'meta_pixel.order_number' => $this->stringOrEmpty($obOrder->getAttribute('order_number')),
            'meta_pixel.event_id' => $sUuid,
        ]);
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
     * `status` relation when available; falls back to a direct query on
     * `status_id` to keep the test harness (no eager-load chain) green.
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
        if ($iStatusId <= 0) {
            return false;
        }

        $sCode = $this->stringOrEmpty(Status::where('id', $iStatusId)->value('code'));

        return $sCode === $sPaidCode;
    }

    /**
     * True iff the order's status_id was dirty on save AND the original
     * status code equals the paid code AND the new status code does NOT.
     * (The "AWAY from paid" transition that the refire-flip ON branch
     * uses to clear the dedup columns.)
     */
    private function isAwayFromPaid(Order $obOrder, string $sPaidCode): bool
    {
        if (! $obOrder->isDirty('status_id')) {
            return false;
        }

        $iOriginalStatusId = $this->intOrZero($obOrder->getOriginal('status_id'));
        if ($iOriginalStatusId <= 0) {
            return false;
        }

        $sOriginalCode = $this->stringOrEmpty(Status::where('id', $iOriginalStatusId)->value('code'));
        if ($sOriginalCode !== $sPaidCode) {
            return false;
        }

        return ! $this->isAtPaidStatus($obOrder, $sPaidCode);
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
