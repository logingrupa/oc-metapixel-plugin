<?php

declare(strict_types=1);

namespace Logingrupa\Metapixelshopaholic\Classes\Meta;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Logingrupa\Metapixelshopaholic\Classes\Exception\InvalidEventIdException;
use Logingrupa\Metapixelshopaholic\Classes\Exception\OrderHasNoCurrencyException;
use Logingrupa\Metapixelshopaholic\Classes\Exception\OrderHasNoItemsException;
use Logingrupa\Metapixelshopaholic\Models\Settings;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Models\OrderPosition;
use Lovata\Shopaholic\Models\Offer;
use Ramsey\Uuid\Rfc4122\FieldsInterface as Rfc4122FieldsInterface;
use Ramsey\Uuid\Uuid;

/**
 * Build the Meta Graph API v20 Purchase event envelope from a paid Order.
 *
 * Phase 3 ships `buildPurchaseEventPayload(Order, eventId, eventTime): array`.
 * Phase 4 funnel events (`buildViewContentPayload`, `buildAddToCartPayload`,
 * `buildInitiateCheckoutPayload`, ...) will add public methods to this class.
 *
 * Contract:
 *  - `event_id` MUST be a valid UUID (validated via Ramsey UUID library) —
 *    server-generated UUIDv4 per PAY-03 (event_id direction is server → frontend only).
 *  - `content_ids` MUST be byte-for-byte match with
 *    `StoreExtender\CartComponentHandler::buildSkuId` so Meta product feed
 *    reconciliation works: single-offer → `SKU-{product_id}`,
 *    multi-offer → `SKU-{product_id}-{offer_id}`.
 *  - `custom_data.order_id` = `$obOrder->order_number` (e.g. '260512-0002'),
 *    NOT `$obOrder->id` (FUN-14 prerequisite).
 *  - Currency: 3-source fallback chain + fail-fast per CONTEXT.md Specifics
 *    line 158 — relation → direct property → Settings::get('currency_code',
 *    'EUR'); if all three sources are empty, throw OrderHasNoCurrencyException.
 *
 * Class file ≤ 250 LOC; phpstan level 10 strict; Hungarian notation throughout.
 */
class PayloadBuilder
{
    private const string EVENT_NAME_PURCHASE = 'Purchase';

    private const string ACTION_SOURCE = 'website';

    private const string CONTENT_TYPE = 'product';

    private const string DEFAULT_CURRENCY_CODE = 'EUR';

    private readonly UserDataHasher $obHasher;

    public function __construct(?UserDataHasher $obHasher = null)
    {
        $this->obHasher = $obHasher ?? new UserDataHasher;
    }

    /**
     * Build the Graph API envelope for a Purchase event.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidEventIdException when event_id is empty or not a valid UUID
     * @throws OrderHasNoCurrencyException when relation + currency_code + Settings all empty
     * @throws OrderHasNoItemsException when order_position is empty
     */
    public function buildPurchaseEventPayload(Order $obOrder, string $sEventId, int $iEventTime): array
    {
        $iOrderId = $this->intOrZero($obOrder->getAttribute('id'));
        $this->assertValidEventId($sEventId, $iOrderId);

        $sCurrency = $this->resolveCurrency($obOrder, $iOrderId);

        $arPositions = $this->resolveOrderPositions($obOrder, $iOrderId);

        $arContentsAggregate = $this->buildContents($arPositions);
        $arContents = $arContentsAggregate['contents'];
        $fTotalValue = $arContentsAggregate['total_value'];
        $iNumItems = $arContentsAggregate['num_items'];

        $arContentIds = array_column($arContents, 'id');

        $arUserData = $this->obHasher->forOrder($obOrder);
        $sEventSourceUrl = $this->resolveEventSourceUrl();

        return ['data' => [[
            'event_id' => $sEventId,
            'event_time' => $iEventTime,
            'event_name' => self::EVENT_NAME_PURCHASE,
            'action_source' => self::ACTION_SOURCE,
            'event_source_url' => $sEventSourceUrl,
            'user_data' => $arUserData,
            'custom_data' => [
                'order_id' => $this->stringOrEmpty($obOrder->getAttribute('order_number')),
                'currency' => $sCurrency,
                'value' => $fTotalValue,
                'num_items' => $iNumItems,
                'contents' => $arContents,
                'content_ids' => $arContentIds,
                'content_type' => self::CONTENT_TYPE,
            ],
        ]]];
    }

    /**
     * CR-02 lock: require UUID **version 4** specifically. `Uuid::isValid()`
     * alone returns true for any well-formed UUID — v1, v3, v4, v5, Nil.
     * The class contract (PAY-03 — "event_id direction is server → frontend
     * only, server-generated UUIDv4") and the InvalidEventIdException name
     * ("event_id is not a valid UUID v4") both require the stronger check.
     * Production dispatch site uses Uuid::uuid4() so this never trips today;
     * the validator guards future migrations that might backfill the column
     * from a different (v1 timestamp-based, v5 deterministic) source.
     *
     * @throws InvalidEventIdException
     */
    private function assertValidEventId(string $sEventId, int $iOrderId): void
    {
        if ($sEventId === '' || ! Uuid::isValid($sEventId)) {
            throw new InvalidEventIdException(
                'event_id is not a valid UUID',
                ['event_id' => $sEventId, 'order_id' => $iOrderId],
            );
        }

        $obFields = Uuid::fromString($sEventId)->getFields();
        // phpstan-strict narrowing: UuidInterface::getFields() returns the
        // generic FieldsInterface which does NOT expose getVersion(). The
        // concrete Rfc4122 / Nonstandard / Guid Fields subclasses all
        // implement Rfc4122\FieldsInterface. instanceof gates the version
        // lookup; if a UUID's fields don't extend the Rfc4122 contract (Nil
        // UUID, broken implementation, future ramsey/uuid major), we treat
        // that as a contract violation and throw.
        if (! $obFields instanceof Rfc4122FieldsInterface || $obFields->getVersion() !== 4) {
            throw new InvalidEventIdException(
                'event_id is not a valid UUIDv4',
                ['event_id' => $sEventId, 'order_id' => $iOrderId],
            );
        }
    }

    /**
     * 3-source currency fallback chain + fail-fast per CONTEXT.md Specifics
     * line 158. WR-03 lock: docblock corrected from "4-step" — the throw is
     * the fail-fast terminator after the 3 sources are exhausted, not a 4th
     * source. To add a real 4th source (e.g. Lovata\Shopaholic\Models\Currency
     * ::getDefault()) would require updating tests + CONTEXT; we keep the
     * Phase-3-locked 3-source contract.
     *
     *   1. $obOrder->currency relation populated (Currency::$code)
     *   2. $obOrder->currency_code accessor (denormalised — same path in
     *      current Lovata.OrdersShopaholic but kept for forward-compat)
     *   3. Settings::get('currency_code', 'EUR') — global multi-site fallback
     *      (default EUR; .no operator overrides to NOK)
     *   → Throw OrderHasNoCurrencyException if all three sources are empty
     *     (fail-fast terminator, not a 4th source).
     *
     * @throws OrderHasNoCurrencyException
     */
    private function resolveCurrency(Order $obOrder, int $iOrderId): string
    {
        $mCurrency = $obOrder->getRelationValue('currency');
        if (is_object($mCurrency) && method_exists($mCurrency, 'getAttribute')) {
            $sCode = $this->stringOrEmpty($mCurrency->getAttribute('code'));
            if ($sCode !== '') {
                return $sCode;
            }
        }

        $sFromField = $this->stringOrEmpty($obOrder->getAttribute('currency_code'));
        if ($sFromField !== '') {
            return $sFromField;
        }

        $sFromSettings = $this->stringOrEmpty(Settings::get('currency_code', self::DEFAULT_CURRENCY_CODE));
        if ($sFromSettings !== '') {
            return $sFromSettings;
        }

        throw new OrderHasNoCurrencyException(
            'Order has no currency: relation, currency_code, and Settings fallback all empty',
            [
                'order_id' => $iOrderId,
                'order_number' => $this->stringOrEmpty($obOrder->getAttribute('order_number')),
            ],
        );
    }

    /**
     * @return list<OrderPosition>
     *
     * @throws OrderHasNoItemsException
     */
    private function resolveOrderPositions(Order $obOrder, int $iOrderId): array
    {
        $mCollection = $obOrder->getRelationValue('order_position');
        $arPositions = [];
        if (is_iterable($mCollection)) {
            foreach ($mCollection as $obPosition) {
                if ($obPosition instanceof OrderPosition) {
                    $arPositions[] = $obPosition;
                }
            }
        }

        if ($arPositions === []) {
            throw new OrderHasNoItemsException(
                'Order has no positions — CAPI Purchase requires at least one item in contents[]',
                ['order_id' => $iOrderId],
            );
        }

        return $arPositions;
    }

    /**
     * @param  list<OrderPosition>  $arPositions
     * @return array{contents: list<array{id: string, quantity: int, item_price: float}>, total_value: float, num_items: int}
     */
    private function buildContents(array $arPositions): array
    {
        $arContents = [];
        $fTotalValue = 0.0;
        $iNumItems = 0;

        $iCostPriceTypeId = $this->readCostPriceTypeId();
        $bCostExcludesVat = $this->readCostExcludesVat();
        $arCostByOfferId = $iCostPriceTypeId > 0
            ? $this->loadCostPrices($this->extractOfferIds($arPositions), $iCostPriceTypeId)
            : [];

        foreach ($arPositions as $obPosition) {
            // OrderPosition is polymorphic — `item_type = Offer::class, item_id = {offerId}`.
            $iOfferId = $this->intOrZero($obPosition->getRawOriginal('item_id'));
            $iProductId = $this->resolveProductIdForOffer($iOfferId);
            $iQuantity = $this->intOrZero($obPosition->getAttribute('quantity'));
            // `getRawOriginal('price')` reads the raw DB cents bypassing the
            // PriceHelper formatter (which would round per Settings.decimals).
            $fSellPriceGross = $this->floatOrZero($obPosition->getRawOriginal('price'));
            $fTaxPercent = $this->floatOrZero($obPosition->getRawOriginal('tax_percent'));
            $fCostPrice = $arCostByOfferId[$iOfferId] ?? 0.0;

            $fItemValue = $this->resolveItemValue(
                $fSellPriceGross,
                $fCostPrice,
                $fTaxPercent,
                $iCostPriceTypeId > 0,
                $bCostExcludesVat,
            );

            $arContents[] = [
                'id' => $this->buildSkuId($iProductId, $iOfferId),
                'quantity' => $iQuantity,
                'item_price' => $fItemValue,
            ];
            $fTotalValue += $fItemValue * $iQuantity;
            $iNumItems += $iQuantity;
        }

        return [
            'contents' => $arContents,
            'total_value' => $fTotalValue,
            'num_items' => $iNumItems,
        ];
    }

    /**
     * Resolve the Meta `item_price` value for one position.
     *
     * Three modes:
     *   - **Revenue (margin OFF)** → `item_price = sell_gross`. Sent VAT-inclusive
     *     as the customer paid it. No cost row needed.
     *   - **Net margin (margin ON, cost excludes VAT)** → `item_price = (sell_gross / (1 + tax/100)) - cost`.
     *     Strips VAT from sell so both sides are net (BEZ PVN).
     *   - **Gross margin (margin ON, cost includes VAT)** → `item_price = sell_gross - cost`.
     *     Both already gross — no VAT adjustment.
     *
     * Missing cost row in margin mode → cost = 0.0 → item_price degrades to sell
     * (or sell_net if VAT stripped) rather than throwing. Tax_percent <= 0 →
     * no VAT stripping (defensive — protects tax-exempt sites).
     */
    private function resolveItemValue(
        float $fSellGross,
        float $fCost,
        float $fTaxPercent,
        bool $bMarginMode,
        bool $bCostExcludesVat
    ): float {
        if (! $bMarginMode) {
            return $fSellGross;
        }

        $fSellAligned = ($bCostExcludesVat && $fTaxPercent > 0.0)
            ? $fSellGross / (1.0 + ($fTaxPercent / 100.0))
            : $fSellGross;

        return $fSellAligned - $fCost;
    }

    /**
     * Read the cost-price type ID from Settings. Returns 0 (revenue mode) when
     * unset/invalid — Meta Purchase value = full sell revenue. Returns N > 0
     * (margin mode) → value = sum((sell - cost) * qty) where cost is fetched
     * from `lovata_shopaholic_prices` for `price_type_id = N`.
     */
    private function readCostPriceTypeId(): int
    {
        $mValue = Settings::get('cost_price_type_id', 0);

        return $this->intOrZero($mValue);
    }

    /**
     * Read the cost-excludes-VAT toggle. Default true — most accounting setups
     * store cost as NET (BEZ PVN) per Lovata's price-type naming convention.
     */
    private function readCostExcludesVat(): bool
    {
        return (bool) Settings::get('cost_price_excludes_vat', true);
    }

    /**
     * Extract distinct positive offer IDs from order positions.
     *
     * @param  list<OrderPosition>  $arPositions
     * @return list<int>
     */
    private function extractOfferIds(array $arPositions): array
    {
        $arIds = [];
        foreach ($arPositions as $obPosition) {
            $iOfferId = $this->intOrZero($obPosition->getRawOriginal('item_id'));
            if ($iOfferId > 0) {
                $arIds[$iOfferId] = $iOfferId;
            }
        }

        return array_values($arIds);
    }

    /**
     * Bulk-load cost prices for the given offer IDs.
     *
     * Single query against `lovata_shopaholic_prices` filtered by
     * `item_type = Offer::class AND price_type_id = $iPriceTypeId`. Returns
     * `[offerId => costPrice]`. Missing rows are absent from the map → the
     * caller treats absence as cost=0 (margin = sell).
     *
     * @param  list<int>  $arOfferIds
     * @return array<int, float>
     */
    private function loadCostPrices(array $arOfferIds, int $iPriceTypeId): array
    {
        if ($arOfferIds === [] || $iPriceTypeId <= 0) {
            return [];
        }

        $arRows = DB::table('lovata_shopaholic_prices')
            ->select('item_id', 'price')
            ->where('item_type', Offer::class)
            ->where('price_type_id', $iPriceTypeId)
            ->whereIn('item_id', $arOfferIds)
            ->get();

        $arResult = [];
        foreach ($arRows as $obRow) {
            $arResult[$this->intOrZero($obRow->item_id)] = $this->floatOrZero($obRow->price);
        }

        return $arResult;
    }

    /**
     * Byte-for-byte match with `StoreExtender\CartComponentHandler::buildSkuId`
     * (lines 137-149) and `FacebookCatalog\ExportCatalogFacebookHelper.php:356`.
     * Single-offer products → `SKU-{product_id}`; multi-offer → `SKU-{product_id}-{offer_id}`.
     */
    private function buildSkuId(int $iProductId, int $iOfferId): string
    {
        $iOfferCount = Offer::where('product_id', $iProductId)->count();

        return $iOfferCount <= 1 ? 'SKU-'.$iProductId : 'SKU-'.$iProductId.'-'.$iOfferId;
    }

    /**
     * Resolve product_id from an Offer row. OrderPosition stores the polymorphic
     * `item_id` (Offer.id) — Offer.product_id is the BelongsTo FK to Product.
     * Returns 0 when the offer can't be resolved (deleted offer, hermetic test
     * gap) — buildSkuId then emits `SKU-0` rather than throwing, preserving
     * envelope integrity for downstream Meta validation.
     */
    private function resolveProductIdForOffer(int $iOfferId): int
    {
        if ($iOfferId <= 0) {
            return 0;
        }
        $mProductId = Offer::where('id', $iOfferId)->value('product_id');

        return $this->intOrZero($mProductId);
    }

    /**
     * `request()->fullUrl()` when a Request is bound; null inside a queue
     * worker / CLI context with no request. Single silent catch with reason.
     *
     * WR-06 lock: narrow catch from \Throwable to
     * BindingResolutionException — the only way `app(Request::class)` fails
     * is container resolution. Catching Throwable would silently swallow
     * unrelated bugs (e.g. a future fullUrl() side-effect throw from a
     * Symfony Route resolver). The URL extraction is INSIDE the try so any
     * fullUrl() failure is also covered (defense-in-depth).
     */
    private function resolveEventSourceUrl(): ?string
    {
        try {
            $obRequest = app(Request::class);

            return $obRequest->fullUrl();
        } catch (BindingResolutionException) {
            // silent: no request in queue worker / CLI context.
            return null;
        }
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

    private function floatOrZero(mixed $mValue): float
    {
        if (is_float($mValue)) {
            return $mValue;
        }
        if (is_int($mValue)) {
            return (float) $mValue;
        }
        if (is_string($mValue) && is_numeric($mValue)) {
            return (float) $mValue;
        }

        return 0.0;
    }
}
