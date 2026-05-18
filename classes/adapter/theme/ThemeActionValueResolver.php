<?php

namespace Logingrupa\Metapixel\Classes\Adapter\Theme;

use Logingrupa\Metapixel\Classes\Adapter\ValueResolver;

/**
 * ValueResolver for ThemeActionEvent — reads everything from arPayload with
 * runtime-guarded fallbacks. Non-ThemeActionEvent subjects return safe defaults.
 */
final class ThemeActionValueResolver implements ValueResolver
{
    /** @return list<string> */
    public function resolveContentIds(object $obSubject): array
    {
        if (! $obSubject instanceof ThemeActionEvent) {
            return [];
        }
        $mIds = $obSubject->arPayload['content_ids'] ?? [];
        if (! is_array($mIds)) {
            return [];
        }
        $arResult = [];
        foreach ($mIds as $mId) {
            if (is_string($mId)) {
                $arResult[] = $mId;
            }
        }

        return $arResult;
    }

    public function resolveValue(object $obSubject): float
    {
        if (! $obSubject instanceof ThemeActionEvent) {
            return 0.0;
        }
        $mValue = $obSubject->arPayload['value'] ?? 0.0;

        return is_numeric($mValue) ? (float) $mValue : 0.0;
    }

    public function resolveCurrency(object $obSubject): string
    {
        if (! $obSubject instanceof ThemeActionEvent) {
            return 'EUR';
        }
        $mCur = $obSubject->arPayload['currency'] ?? 'EUR';

        return is_string($mCur) && $mCur !== '' ? $mCur : 'EUR';
    }

    /** @return list<array{id: string, quantity: int, item_price: float}> */
    public function resolveContents(object $obSubject): array
    {
        if (! $obSubject instanceof ThemeActionEvent) {
            return [];
        }
        $mContents = $obSubject->arPayload['contents'] ?? [];
        if (! is_array($mContents)) {
            return [];
        }
        $arResult = [];
        foreach ($mContents as $mItem) {
            if (! is_array($mItem)) {
                continue;
            }
            $mContentId = $mItem['id'] ?? null;
            $mQuantity = $mItem['quantity'] ?? null;
            $mItemPrice = $mItem['item_price'] ?? null;
            if (! is_scalar($mContentId) || ! is_numeric($mQuantity) || ! is_numeric($mItemPrice)) {
                continue;
            }
            $arResult[] = [
                'id' => (string) $mContentId,
                'quantity' => (int) $mQuantity,
                'item_price' => (float) $mItemPrice,
            ];
        }

        return $arResult;
    }

    public function resolveNumItems(object $obSubject): int
    {
        if (! $obSubject instanceof ThemeActionEvent) {
            return 0;
        }
        $mNum = $obSubject->arPayload['num_items'] ?? null;
        if (is_numeric($mNum)) {
            return (int) $mNum;
        }

        return count($this->resolveContents($obSubject));
    }
}
