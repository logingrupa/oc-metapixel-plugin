<?php

declare(strict_types=1);

namespace Logingrupa\Metapixelshopaholic\Components;

use Cms\Classes\ComponentBase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixelshopaholic\Classes\Exception\MetaPixelException;
use Logingrupa\Metapixelshopaholic\Classes\Meta\PayloadBuilder;
use Logingrupa\Metapixelshopaholic\Models\Settings;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Models\Status;

/**
 * Phase 3 plan 03-06 — browser-side Meta Pixel twin for the thank-you page.
 *
 * Reads the persisted `meta_purchase_event_id` + `meta_purchase_event_time`
 * columns (written atomically by OrderStatusWatcher::handleUpdated /
 * handleCreated via a single saveQuietly) and emits the Pixel-side
 * `fbq('track', 'Purchase', custom_data, {eventID})` call. Meta dedups
 * the Pixel + CAPI pair by `event_id` within its ±10 s event_time window.
 *
 * Guard chain (render-nothing on ANY failure):
 *   1. PluginGuard disabled.
 *   2. Order not found by `orderSlug` (secret_key route binding).
 *   3. Status code !== Settings::get('paid_status_code', 'new-payment-received').
 *   4. `meta_purchase_event_id` is null (OrderStatusWatcher hasn't fired —
 *      e.g. user lands on /checkout/{slug} before PayPal IPN flips the
 *      status; Pixel correctly renders nothing rather than guess).
 *   5. `meta_purchase_event_time` is null (paired column — defensive).
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
 * Threat model (T-03-33..35):
 *   - event_id is server-generated UUIDv4 from OrderStatusWatcher; no
 *     user-controlled string reaches the column (T-03-33).
 *   - Status + event_id + event_time fences prevent Pixel-fires-for-
 *     non-paid-order (T-03-34).
 *   - custom_data fields are values the user already knows from their own
 *     checkout (order_id, value, currency, num_items) — non-PII (T-03-35).
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
            'description' => 'Browser-side Pixel twin for Purchase events. Reads persisted event_id + event_time so Meta can dedup Pixel + CAPI by event_id.',
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

        $mEventId = $obOrder->getAttribute('meta_purchase_event_id');
        $mEventTime = $obOrder->getAttribute('meta_purchase_event_time');
        if ($mEventId === null || $mEventTime === null) {
            return;
        }

        $sEventId = $this->stringOrEmpty($mEventId);
        $iEventTime = $this->intOrZero($mEventTime);
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
