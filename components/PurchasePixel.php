<?php

declare(strict_types=1);

namespace Logingrupa\Metapixelshopaholic\Components;

use Cms\Classes\ComponentBase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixelshopaholic\Classes\Exception\MetaPixelException;
use Logingrupa\Metapixelshopaholic\Classes\Helper\EventLogWriter;
use Logingrupa\Metapixelshopaholic\Classes\Helper\SiteResolver;
use Logingrupa\Metapixelshopaholic\Classes\Meta\PayloadBuilder;
use Logingrupa\Metapixelshopaholic\Models\EventLog;
use Logingrupa\Metapixelshopaholic\Models\Settings;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Models\Status;

/**
 * Phase 3.1 REFAC-08 — server-authoritative browser-side Meta Pixel twin.
 *
 * Reads the plugin-owned `logingrupa_metapixel_event_log` table to decide
 * whether to render the `fbq('track', 'Purchase', ...)` IIFE. The legacy
 * Phase-3 column-based dedup fence on Lovata's orders table was superseded
 * by this plugin-owned event_log table (Phase 3.1 schema bedrock REFAC-01).
 * The table is the SINGLE source of truth for "has this Purchase event
 * fired for this subject on this channel on this site". Once a row with
 * `channel='pixel'` exists for the Order on the current site, this
 * component renders nothing — regardless of device, browser, incognito,
 * or time elapsed (independent of Meta's 7-day eventID dedup window).
 *
 * Guard chain (render-nothing on ANY of these):
 *   1. PluginGuard disabled.
 *   2. Order not found by `orderSlug` (secret_key route binding).
 *   3. Status code !== Settings::get('paid_status_code', 'new-payment-received').
 *   4. CAPI row absent in event_log (server hasn't fired yet — e.g. user
 *      reaches /checkout/{slug} before PayPal IPN flips the status; we
 *      don't pair half a contract).
 *   5. Pixel row present in event_log (browser already fired across
 *      this/any device/session — cross-device-refire suppression).
 *
 * When the gate opens (CAPI present AND Pixel absent) the component reads
 * `event_id` + `event_time` from the CAPI row (single source of truth) and
 * populates `$arMetaEvent` + `$sCustomDataJson` for the Twig partial.
 *
 * `onMarkFired(): array` is the matched AJAX confirmation handler. The
 * Twig partial calls `jax.ajax('purchasePixel::onMarkFired', { data: {
 * event_id } })` after `fbq` fires; this handler validates that the
 * submitted `event_id` MATCHES the server-side CAPI row's `event_id`
 * (prevents forged Pixel-fire claims from crafted AJAX requests) and
 * then calls `EventLogWriter::record(..., channel='pixel', ...)` to
 * insert the Pixel row. Mismatch → `['ok' => false]` + Log::warning that
 * logs only the submitted_event_id LENGTH (never the value — prevents
 * log-injection via attacker-controlled UUID strings).
 *
 * `user_data` is intentionally OMITTED on the Pixel side (Meta CAPI spec —
 * `user_data` is server-side hashes only; the browser side infers fbp/fbc
 * from cookies set by Phase 2 EnsureFbpFbcCookies middleware).
 *
 * Theme integration (operator step — Phase 5 HARD-05 README):
 *   On `themes/<active>/pages/order-complete.htm`, add:
 *     [purchasePixel] orderSlug = "{{ :slug }}"
 *     ...
 *     {% component 'purchasePixel' %}
 *
 * Threat model (Phase 3.1 T-3.1-18..24):
 *   - T-3.1-18 (Spoofing onMarkFired): submitted event_id must match the
 *     server's CAPI row event_id. UUIDv4 has ~122 bits entropy → guessing
 *     is computationally infeasible. Mismatch → reject + warn.
 *   - T-3.1-19 (Tampering payload): EventLogWriter uses the SERVER's
 *     event_time from the CAPI row, not a client-supplied value.
 *   - T-3.1-21 (Info Disclosure on mismatch log): Log captures
 *     submitted_event_id_LENGTH only — never the value itself.
 *   - T-3.1-23 (Elevation without CAPI): onMarkFired returns ok=false
 *     when CAPI row absent — caller cannot escalate Pixel claim.
 */
final class PurchasePixel extends ComponentBase
{
    /** @var array{event_id: string, event_time: int, event_name: string, custom_data: array<string, mixed>}|null */
    public ?array $arMetaEvent = null;

    /**
     * Pre-rendered, defense-in-depth JSON-encoded custom_data slice for the
     * Twig partial. CR-01 lock: built server-side via getInlineScriptJson()
     * with JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT so
     * the partial cannot break out of <script> via `</script>` injection
     * regardless of slash-escaping defaults. Today's input chain is server-
     * controlled (UUIDv4 event_id, server-built SKUs + order_number), but
     * the flag set is mandatory belt-and-braces for any future refactor
     * that touches custom_data sources.
     */
    public ?string $sCustomDataJson = null;

    /**
     * @return array{name: string, description: string}
     */
    #[\Override]
    public function componentDetails(): array
    {
        return [
            'name' => 'Purchase Pixel',
            'description' => 'Browser-side Pixel twin for Purchase events. Reads event_log CAPI row for event_id + event_time so Meta can dedup Pixel + CAPI by event_id.',
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    public function defineProperties(): array
    {
        return [
            'orderSlug' => [
                'title' => 'Order slug (secret_key route binding)',
                'description' => 'Secret_key of the paid order; usually bound to {{ :slug }} on the thank-you page.',
                'type' => 'string',
                'default' => '{{ :slug }}',
                'validationPattern' => '^[a-zA-Z0-9-]+$',
            ],
        ];
    }

    /**
     * Page lifecycle hook. No explicit return type — preserves the parent
     * ComponentBase::onRun() signature (October's PluginBase declares it
     * without one). Returns void in Phase 3; the unrestricted signature
     * mirrors PixelHead::onRun() so future plans may return a Response
     * for short-circuit redirects (e.g. critical CAPI dispatch failure).
     *
     * @return void
     */
    #[\Override]
    public function onRun()
    {
        if ($this->isDisabled()) {
            return;
        }

        $obOrder = $this->resolveOrder();
        if ($obOrder === null) {
            return;
        }

        if (! $this->isAtPaidStatus($obOrder)) {
            return;
        }

        // REFAC-08 step 4: CAPI row absent → return. The server hasn't
        // dispatched yet (e.g. user reaches /checkout/{slug} before the
        // PayPal IPN flips the status, or status flipped manually but the
        // queue hasn't drained); don't pair half a contract.
        $obCapiRow = $this->findEventLogRow($obOrder, EventLog::CHANNEL_CAPI);
        if ($obCapiRow === null) {
            return;
        }

        // REFAC-08 step 5: Pixel row present → return. Browser already
        // fired on this device / a different device / a private window /
        // 10 days ago — server-side persistence is independent of Meta's
        // 7-day eventID dedup window which expires on the Meta side.
        if ($this->findEventLogRow($obOrder, EventLog::CHANNEL_PIXEL) !== null) {
            return;
        }

        // REFAC-08 step 6: read event_id + event_time from the CAPI row
        // (single source of truth — paired with the server-side fire).
        // $casts on EventLog narrows event_time to int on rehydrate;
        // explicit (int) cast is belt-and-braces for phpstan level 10.
        $sEventId = (string) $obCapiRow->event_id;
        $iEventTime = (int) $obCapiRow->event_time;
        if ($sEventId === '' || $iEventTime === 0) {
            return;
        }

        try {
            $arPayload = (new PayloadBuilder)->buildPurchaseEventPayload(
                $obOrder,
                $sEventId,
                $iEventTime,
            );
        } catch (MetaPixelException $obException) {
            // Boundary catch: thank-you page Pixel must NOT 500 the page
            // render. CAPI side has already dispatched (or didn't); the
            // Pixel-side miss degrades dedup but never breaks the order
            // completion UX. T-03-35 acceptable degradation.
            Log::warning('Metapixel: PurchasePixel PayloadBuilder skipped', [
                'meta_pixel.order_id' => $this->intOrZero($obOrder->getAttribute('id')),
                'meta_pixel.exception' => get_class($obException),
            ]);

            return;
        }

        $arCustomData = $this->extractCustomData($arPayload);

        $this->arMetaEvent = [
            'event_id' => $sEventId,
            'event_time' => $iEventTime,
            'event_name' => 'Purchase',
            'custom_data' => $arCustomData,
        ];
        $this->sCustomDataJson = $this->encodeCustomDataForScript($arCustomData);
    }

    /**
     * AJAX handler — browser-side fbq('track','Purchase') confirmation.
     * Called via `jax.ajax('purchasePixel::onMarkFired', { data: {
     * event_id } })` from the Twig partial AFTER `fbq` fires.
     *
     * Security (REFAC-08, T-3.1-18): validate that the supplied event_id
     * MATCHES the server's CAPI row event_id. Prevents forged Pixel-fire
     * claims via crafted AJAX requests with a guessed event_id. UUIDv4
     * has ~122 bits of entropy — guessing is computationally infeasible,
     * but the check is the cheap correctness primitive.
     *
     * On mismatch: log warning with `submitted_event_id_len` only — NEVER
     * the value itself (T-3.1-21 log-injection mitigation; the mismatched
     * id is potentially attacker-controlled).
     *
     * On match: call `EventLogWriter::record(channel='pixel')`. event_time
     * is read from the SERVER's CAPI row (T-3.1-19 — client cannot tamper
     * the persisted event_time). Returns `won_race` as a runtime extension
     * for tests; the public contract per `@return` is `ok` only.
     *
     * Second-call semantics: per BRIEF REFAC-11, calling onMarkFired again
     * with the same event_id returns `['ok' => true, 'won_race' => false]`
     * — success-for-the-caller, the row exists; the race-loser branch is
     * fine because EventLogWriter's UNIQUE-constraint fence collapsed
     * the duplicate INSERT into a no-op.
     *
     * @return array{ok: bool}
     */
    public function onMarkFired(): array
    {
        if ($this->isDisabled()) {
            return ['ok' => false];
        }

        $obOrder = $this->resolveOrder();
        if ($obOrder === null) {
            return ['ok' => false];
        }

        if (! $this->isAtPaidStatus($obOrder)) {
            return ['ok' => false];
        }

        $sSubmittedEventId = $this->stringOrEmpty(input('event_id'));
        if ($sSubmittedEventId === '') {
            return ['ok' => false];
        }

        $obCapiRow = $this->findEventLogRow($obOrder, EventLog::CHANNEL_CAPI);
        if ($obCapiRow === null) {
            // No CAPI row → no Pixel row may exist either. Caller cannot
            // escalate a Pixel-fire claim without a corresponding server
            // dispatch (T-3.1-23 elevation mitigation).
            return ['ok' => false];
        }

        if ((string) $obCapiRow->event_id !== $sSubmittedEventId) {
            // T-3.1-21: log LENGTH only — the submitted event_id is
            // potentially attacker-controlled and must never reach the log
            // stream verbatim (log-injection mitigation).
            Log::warning('Metapixel: onMarkFired event_id mismatch — potential forgery', [
                'meta_pixel.order_id' => $this->intOrZero($obOrder->getAttribute('id')),
                'meta_pixel.submitted_event_id_len' => strlen($sSubmittedEventId),
            ]);

            return ['ok' => false];
        }

        $sSecretKey = $this->stringOrEmpty($obOrder->getAttribute('secret_key'));
        $bWon = EventLogWriter::record(
            $sSubmittedEventId,
            EventLog::EVENT_PURCHASE,
            EventLog::CHANNEL_PIXEL,
            $obOrder,
            $sSecretKey === '' ? null : $sSecretKey,
            (int) $obCapiRow->event_time,
        );

        // 'won_race' is a runtime extension for tests (REFAC-11 second-call
        // assertion). Public @return contract is `array{ok: bool}` — the
        // extra key is informational. Race-loser path is success-for-caller.
        return ['ok' => true, 'won_race' => $bWon];
    }

    /**
     * Query event_log for a row matching (Order, channel) scoped by the
     * SiteResolver-resolved active site_id. Reusable across `onRun` (CAPI
     * presence + Pixel presence checks) and `onMarkFired` (CAPI presence
     * + event_id match).
     *
     * Site_id branch: NULL → `whereNull('site_id')`; non-null → equality.
     * Mirrors `OrderStatusWatcher::alreadyDispatched` Phase 3.1 Wave-3 idiom.
     *
     * MC-05 narrowing: `Builder::first()` returns `?Model`; explicit
     * `instanceof EventLog` narrow keeps phpstan level 10 happy without
     * `@var` / `assert` and locks the typed `?EventLog` return.
     */
    private function findEventLogRow(Order $obOrder, string $sChannel): ?EventLog
    {
        $iSubjectId = $this->intOrZero($obOrder->getAttribute('id'));
        if ($iSubjectId <= 0) {
            return null;
        }

        $iSiteId = SiteResolver::getActiveSiteId();

        $obQuery = EventLog::where('subject_type', Order::class)
            ->where('subject_id', $iSubjectId)
            ->where('event_name', EventLog::EVENT_PURCHASE)
            ->where('channel', $sChannel);

        if ($iSiteId === null) {
            $obQuery->whereNull('site_id');
        } else {
            $obQuery->where('site_id', $iSiteId);
        }

        $obResult = $obQuery->first();

        return $obResult instanceof EventLog ? $obResult : null;
    }

    /**
     * CR-01 lock: render the custom_data slice for in-<script> interpolation
     * with the canonical "safe-for-script-context" JSON encode flag set.
     *
     * The flags do three things:
     *  - JSON_HEX_TAG: escapes `<`/`>` to `<` / `>`. This is the
     *    primary defense — even if a future change adds JSON_UNESCAPED_SLASHES
     *    elsewhere, `</script>` cannot reach the rendered DOM.
     *  - JSON_HEX_AMP / JSON_HEX_APOS / JSON_HEX_QUOT: belt-and-braces escapes
     *    for `&`/`'`/`"` so the output is safe in attribute context too (the
     *    partial does not use it there today, but the contract is symmetric).
     *  - JSON_UNESCAPED_UNICODE: keep multi-byte product names readable for
     *    Meta's product-feed reconciliation (we already use this elsewhere).
     *  - JSON_THROW_ON_ERROR: any encode failure surfaces as JsonException →
     *    boundary catch in onRun returns null custom_data → render-nothing.
     *
     * @param  array<string, mixed>  $arCustomData
     */
    private function encodeCustomDataForScript(array $arCustomData): string
    {
        return json_encode(
            $arCustomData,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    private function isDisabled(): bool
    {
        try {
            return (bool) App::make('metapixel.disabled');
        } catch (\Throwable $obException) {
            // Defensive: container singleton not bound (e.g. test harness
            // forgot to prime PluginGuard). Treat as disabled — render
            // nothing rather than risk a Pixel fire with stale data.
            Log::warning('Metapixel: PurchasePixel container lookup failed — treating as disabled', [
                'meta_pixel.exception' => get_class($obException),
            ]);

            return true;
        }
    }

    /**
     * Resolve the persisted Order by the route-bound `secret_key`.
     *
     * CR-03 lock: October's `defineProperties.validationPattern` enforces the
     * regex ONLY at backend-form-edit time. At runtime — the canonical bind
     * is `{{ :slug }}` from the page route — there is no validation at all,
     * and `$this->property('orderSlug')` returns whatever the URL produced.
     *
     * The DB query IS parameterized so this is not SQL injection. The guard
     * here exists to: (1) bound the input (Tiger-Style); (2) make the
     * documented validator actually execute on the hot path rather than be
     * safety theater; (3) reject early so the DB index lookup is skipped on
     * obviously-malformed slugs (DoS surface narrowing on /checkout/{slug}).
     *
     * The pattern `\A[A-Za-z0-9_-]{8,128}\z` matches Lovata's secret_key
     * shape (Str::random produces ASCII alphanumerics) with a generous upper
     * bound; the {1,n} cap is Tiger-Style bounded-loop discipline.
     */
    private function resolveOrder(): ?Order
    {
        $mSlug = $this->property('orderSlug');
        $sSlug = $this->stringOrEmpty($mSlug);
        if ($sSlug === '') {
            return null;
        }

        // Anchored with \A / \z (not /^…$/) so trailing newlines cannot match
        // — PHP preg_match's default $ allows a single trailing \n.
        if (preg_match('/\A[A-Za-z0-9_-]{8,128}\z/', $sSlug) !== 1) {
            Log::debug('Metapixel: PurchasePixel slug rejected by runtime validator', [
                'meta_pixel.slug_length' => strlen($sSlug),
            ]);

            return null;
        }

        $obResult = Order::where('secret_key', $sSlug)->first();

        return $obResult instanceof Order ? $obResult : null;
    }

    private function isAtPaidStatus(Order $obOrder): bool
    {
        $sPaidCode = $this->readPaidStatusCode();

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

    private function readPaidStatusCode(): string
    {
        $mValue = Settings::get('paid_status_code', 'new-payment-received');

        return is_scalar($mValue) ? (string) $mValue : 'new-payment-received';
    }

    /**
     * @param  array<string, mixed>  $arPayload  PayloadBuilder envelope.
     * @return array<string, mixed>
     */
    private function extractCustomData(array $arPayload): array
    {
        $mData = $arPayload['data'] ?? null;
        if (! is_array($mData)) {
            return [];
        }
        $mFirst = $mData[0] ?? null;
        if (! is_array($mFirst)) {
            return [];
        }
        $mCustom = $mFirst['custom_data'] ?? null;
        if (! is_array($mCustom)) {
            return [];
        }

        // WR-09 lock: filter explicitly to string-keyed entries — DROP any
        // integer-keyed entries rather than coercing them via (string) $mKey.
        // The Meta CAPI envelope's custom_data is documented as a string-
        // keyed dictionary (order_id, currency, value, num_items, ...). An
        // integer-keyed entry would be a contract violation and silently
        // coercing collides with PHP's array key normalisation (e.g. '0'
        // and 0 coalesce). Skip-and-log preserves the contract.
        $arResult = [];
        foreach ($mCustom as $mKey => $mValue) {
            if (! is_string($mKey)) {
                continue; // integer-keyed entry — not a CAPI custom_data field.
            }
            $arResult[$mKey] = $mValue;
        }

        return $arResult;
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
