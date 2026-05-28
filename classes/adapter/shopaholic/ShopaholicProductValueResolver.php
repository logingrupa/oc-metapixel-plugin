<?php

namespace Logingrupa\Metapixel\Classes\Adapter\Shopaholic;

use Logingrupa\Metapixel\Classes\Adapter\ValueResolver;
use Logingrupa\Metapixel\Classes\Exception\OrderHasNoCurrencyException;
use Logingrupa\Metapixel\Models\Settings;
use Lovata\Shopaholic\Classes\Helper\CurrencyHelper;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Product;
use October\Rain\Database\Model;

/**
 * ValueResolver for Lovata\Shopaholic\Models\Product. Default offer per D-10 =
 * first active by sort_order ascending. content_ids per D-5 = SKU-{pid} when
 * single offer (or no default), SKU-{pid}-{oid} when product has multiple
 * offers. Currency chain: CurrencyHelper::instance()->getActiveCurrencyCode()
 * → Settings::get('default_currency_code') → throw OrderHasNoCurrencyException
 * (reused per D-discretion — no caller branches on exception type).
 */
final class ShopaholicProductValueResolver implements ValueResolver
{
    /** @return list<string> */
    public function resolveContentIds(object $obSubject): array
    {
        $obProduct = $this->productOf($obSubject);
        if ($obProduct === null) {
            return [];
        }

        $iProductId = $this->intAttr($obProduct, 'id');
        $obDefault = $this->defaultOffer($obProduct);
        if ($obDefault === null) {
            return [sprintf('SKU-%d', $iProductId)];
        }

        if ($obProduct->offer->count() > 1) {
            return [sprintf('SKU-%d-%d', $iProductId, $this->intAttr($obDefault, 'id'))];
        }

        return [sprintf('SKU-%d', $iProductId)];
    }

    public function resolveValue(object $obSubject): float
    {
        $obDefault = $this->defaultOffer($this->productOf($obSubject));
        if ($obDefault === null) {
            return 0.0;
        }

        return $this->floatAttr($obDefault, 'price_value');
    }

    public function resolveCurrency(object $obSubject): string
    {
        $mHelper = CurrencyHelper::instance();
        if ($mHelper instanceof CurrencyHelper) {
            $mActive = $mHelper->getActiveCurrencyCode();
            if (is_string($mActive) && $mActive !== '') {
                return $mActive;
            }
        }

        $mDefault = Settings::get('default_currency_code', '');
        if (is_string($mDefault) && $mDefault !== '') {
            return $mDefault;
        }

        throw new OrderHasNoCurrencyException(
            'Product has no currency context; configure Settings.default_currency_code',
        );
    }

    /** @return list<array{id: string, quantity: int, item_price: float}> */
    public function resolveContents(object $obSubject): array
    {
        $arIds = $this->resolveContentIds($obSubject);
        if ($arIds === []) {
            return [];
        }

        return [[
            'id' => $arIds[0],
            'quantity' => 1,
            'item_price' => $this->resolveValue($obSubject),
        ]];
    }

    public function resolveNumItems(object $obSubject): int
    {
        return 1;
    }

    private function productOf(object $obSubject): ?Product
    {
        return $obSubject instanceof Product ? $obSubject : null;
    }

    /**
     * Default offer per D-10: first active offer ordered by sort_order
     * ascending. Returns null when product is null, the offer collection is
     * absent, or no active offer exists.
     */
    private function defaultOffer(?Product $obProduct): ?Offer
    {
        if ($obProduct === null) {
            return null;
        }
        if ($obProduct->offer->isEmpty()) {
            return null;
        }
        $mDefault = $obProduct->offer->where('active', true)->sortBy('sort_order')->first();

        return $mDefault instanceof Offer ? $mDefault : null;
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
