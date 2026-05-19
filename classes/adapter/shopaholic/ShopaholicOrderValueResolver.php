<?php

namespace Logingrupa\Metapixel\Classes\Adapter\Shopaholic;

use Logingrupa\Metapixel\Classes\Adapter\ValueResolver;
use Logingrupa\Metapixel\Classes\Exception\OrderHasNoCurrencyException;
use Logingrupa\Metapixel\Models\Settings;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Models\OrderPosition;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Product;
use October\Rain\Database\Model;

/**
 * ValueResolver for Lovata\OrdersShopaholic\Models\Order. content_ids in
 * 'SKU-{product_id}[-{offer_id}]' matching the Facebook Catalog feed
 * (FacebookCatalogShopaholic ExportCatalogFacebookHelper line 356).
 */
final class ShopaholicOrderValueResolver implements ValueResolver
{
    /** @return list<string> */
    public function resolveContentIds(object $obSubject): array
    {
        $arResult = [];
        foreach ($this->positions($obSubject) as $obPos) {
            $obOffer = $this->offerOf($obPos);
            if ($obOffer !== null) {
                $arResult[] = $this->buildContentId($obOffer);
            }
        }

        return $arResult;
    }

    public function resolveValue(object $obSubject): float
    {
        return $this->floatAttr($this->orderOf($obSubject), 'total_price_value');
    }

    public function resolveCurrency(object $obSubject): string
    {
        $obOrder = $this->orderOf($obSubject);
        $sRelationCode = $this->currencyRelationCode($obOrder);
        if ($sRelationCode !== '') {
            return $sRelationCode;
        }
        $mField = $obOrder?->getAttribute('currency_code');
        if (is_string($mField) && $mField !== '') {
            return $mField;
        }
        $mDefault = Settings::get('default_currency_code', '');
        if (is_string($mDefault) && $mDefault !== '') {
            return $mDefault;
        }
        throw new OrderHasNoCurrencyException(
            'Order '.$this->stringAttr($obOrder, 'id').' has no currency relation, currency_code, or Settings default',
        );
    }

    /**
     * Read Order.currency relation code via getRelation('currency')->code at
     * runtime — sidesteps Lovata's @property string $code phpdoc which marks
     * the chain non-nullable but is null when currency_id is NULL at the DB.
     */
    private function currencyRelationCode(?Order $obOrder): string
    {
        if ($obOrder === null) {
            return '';
        }
        $mRelation = $obOrder->getRelationValue('currency');
        if (! is_object($mRelation)) {
            return '';
        }
        $mCode = $mRelation->code ?? null;

        return is_string($mCode) ? $mCode : '';
    }

    /** @return list<array{id: string, quantity: int, item_price: float}> */
    public function resolveContents(object $obSubject): array
    {
        $arResult = [];
        foreach ($this->positions($obSubject) as $obPos) {
            $obOffer = $this->offerOf($obPos);
            if ($obOffer !== null) {
                $arResult[] = [
                    'id' => $this->buildContentId($obOffer),
                    'quantity' => $this->intAttr($obPos, 'quantity'),
                    'item_price' => $this->floatAttr($obPos, 'price_value'),
                ];
            }
        }

        return $arResult;
    }

    public function resolveNumItems(object $obSubject): int
    {
        $iTotal = 0;
        foreach ($this->positions($obSubject) as $obPos) {
            $iTotal += $this->intAttr($obPos, 'quantity');
        }

        return $iTotal;
    }

    /** @return iterable<OrderPosition> */
    private function positions(object $obSubject): iterable
    {
        $mPositions = $this->orderOf($obSubject)?->order_position;

        return is_iterable($mPositions) ? $mPositions : [];
    }

    private function orderOf(object $obSubject): ?Order
    {
        return $obSubject instanceof Order ? $obSubject : null;
    }

    private function offerOf(OrderPosition $obPos): ?Offer
    {
        return $obPos->item instanceof Offer ? $obPos->item : null;
    }

    /**
     * Read the BelongsTo $product relation via getRelationValue() + runtime
     * narrowing to Product — Lovata's phpdoc declares the relation non-nullable
     * but resolves to null at the DB when product_id is unset or points at a
     * deleted product (Pitfall 1).
     */
    private function productOf(Offer $obOffer): ?Product
    {
        $mProduct = $obOffer->getRelationValue('product');

        return $mProduct instanceof Product ? $mProduct : null;
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

    private function intAttr(Model $obModel, string $sAttr): int
    {
        $mValue = $obModel->getAttribute($sAttr);

        return is_numeric($mValue) ? (int) $mValue : 0;
    }

    private function floatAttr(?Model $obModel, string $sAttr): float
    {
        $mValue = $obModel?->getAttribute($sAttr);

        return is_numeric($mValue) ? (float) $mValue : 0.0;
    }

    private function stringAttr(?Model $obModel, string $sAttr): string
    {
        $mValue = $obModel?->getAttribute($sAttr);
        if (is_string($mValue)) {
            return $mValue;
        }
        if (is_int($mValue) || is_float($mValue)) {
            return (string) $mValue;
        }

        return '';
    }
}
