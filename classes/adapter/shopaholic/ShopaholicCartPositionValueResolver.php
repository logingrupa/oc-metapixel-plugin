<?php

namespace Logingrupa\Metapixel\Classes\Adapter\Shopaholic;

use Logingrupa\Metapixel\Classes\Adapter\ValueResolver;
use Logingrupa\Metapixel\Classes\Exception\OrderHasNoCurrencyException;
use Logingrupa\Metapixel\Models\Settings;
use Lovata\OrdersShopaholic\Models\CartPosition;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Product;
use October\Rain\Database\Model;

/**
 * ValueResolver for Lovata\OrdersShopaholic\Models\CartPosition. Accesses Offer
 * via the MorphTo $item relation with null-guard (Pitfall 1). Reuses the
 * SKU-{product_id}[-{offer_id}] content_ids format byte-for-byte with the
 * Order resolver + FacebookCatalog feed exporter.
 */
final class ShopaholicCartPositionValueResolver implements ValueResolver
{
    /** @return list<string> */
    public function resolveContentIds(object $obSubject): array
    {
        $obOffer = $this->offerOf($obSubject);

        return $obOffer !== null ? [$this->buildContentId($obOffer)] : [];
    }

    public function resolveValue(object $obSubject): float
    {
        $obPosition = $this->positionOf($obSubject);
        if ($obPosition === null) {
            return 0.0;
        }
        $iQuantity = $this->intAttr($obPosition, 'quantity');
        $obOffer = $this->offerOf($obSubject);

        return $iQuantity * ($obOffer !== null ? $this->floatAttr($obOffer, 'price_value') : 0.0);
    }

    public function resolveCurrency(object $obSubject): string
    {
        $mDefault = Settings::get('default_currency_code', '');
        if (is_string($mDefault) && $mDefault !== '') {
            return $mDefault;
        }
        throw new OrderHasNoCurrencyException(
            'CartPosition has no currency context; configure Settings.default_currency_code',
        );
    }

    /** @return list<array{id: string, quantity: int, item_price: float}> */
    public function resolveContents(object $obSubject): array
    {
        $obOffer = $this->offerOf($obSubject);
        $obPosition = $this->positionOf($obSubject);
        if ($obOffer === null || $obPosition === null) {
            return [];
        }

        return [[
            'id' => $this->buildContentId($obOffer),
            'quantity' => $this->intAttr($obPosition, 'quantity'),
            'item_price' => $this->floatAttr($obOffer, 'price_value'),
        ]];
    }

    public function resolveNumItems(object $obSubject): int
    {
        $obPosition = $this->positionOf($obSubject);

        return $obPosition !== null ? $this->intAttr($obPosition, 'quantity') : 0;
    }

    private function positionOf(object $obSubject): ?CartPosition
    {
        return $obSubject instanceof CartPosition ? $obSubject : null;
    }

    /**
     * Read the MorphTo $item relation via getRelationValue() + runtime is_object()
     * check — sidesteps Lovata's @property Offer $item phpdoc which declares the
     * relation non-nullable but resolves to null when item_id points at a deleted
     * Offer or a non-Offer morphable type (Pitfall 1).
     */
    private function offerOf(object $obSubject): ?Offer
    {
        $obPosition = $this->positionOf($obSubject);
        if ($obPosition === null) {
            return null;
        }
        $mItem = $obPosition->getRelationValue('item');

        return $mItem instanceof Offer ? $mItem : null;
    }

    private function buildContentId(Offer $obOffer): string
    {
        $obProduct = $this->productOf($obOffer);
        if ($obProduct === null) {
            return sprintf('SKU-%d', 0);
        }
        $iProductId = $this->intAttr($obProduct, 'id');

        return $obProduct->offer->count() > 1
            ? sprintf('SKU-%d-%d', $iProductId, $this->intAttr($obOffer, 'id'))
            : sprintf('SKU-%d', $iProductId);
    }

    /**
     * Read the BelongsTo $product relation via getRelationValue() + runtime
     * narrowing to Product — Lovata's phpdoc declares the relation non-nullable
     * but resolves to null at the DB when product_id is unset (Pitfall 1).
     */
    private function productOf(Offer $obOffer): ?Product
    {
        $mProduct = $obOffer->getRelationValue('product');

        return $mProduct instanceof Product ? $mProduct : null;
    }

    private function intAttr(Model $obModel, string $sAttr): int
    {
        $mValue = $obModel->getAttribute($sAttr);

        return is_numeric($mValue) ? (int) $mValue : 0;
    }

    private function floatAttr(Model $obModel, string $sAttr): float
    {
        $mValue = $obModel->getAttribute($sAttr);

        return is_numeric($mValue) ? (float) $mValue : 0.0;
    }
}
