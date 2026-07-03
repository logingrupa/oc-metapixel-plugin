<?php

namespace Logingrupa\Metapixel\Classes\Adapter\Theme;

use Illuminate\Support\Facades\Request;

/**
 * Reads the Metapixel AJAX event payload from the request. Holds the
 * request-payload parsing responsibility split out of ThemeAjaxHandler:
 * normalises both supported transport shapes and narrows the hybrid-AJAX
 * loadSubject context to a string-keyed array. Stateless; classes/adapter/theme/
 * is outside the phpstan Request/SiteManager disallow scope (D-16).
 */
final class ThemeAjaxRequestReader
{
    /**
     * Read the AJAX event payload from either supported transport shape.
     * Larajax nests fields under data[]; October's native $.request posts
     * options.data as top-level form fields. Nested values win; known
     * top-level fields fill the gaps.
     *
     * @return array<string, mixed>|null null when the nested payload is not an array
     */
    public function readEventData(): ?array
    {
        $arData = $this->normalizeStringKeys(Request::input('data', []));
        if ($arData === null) {
            return null;
        }

        foreach (['name', 'subject_type', 'subject_id', 'offer_id', 'action_key'] as $sField) {
            if (array_key_exists($sField, $arData)) {
                continue;
            }
            $mTopLevelValue = Request::input($sField);
            if ($mTopLevelValue !== null) {
                $arData[$sField] = $mTopLevelValue;
            }
        }

        return $arData;
    }

    /**
     * Coerce a parsed payload field to an int (0 when absent or non-numeric).
     *
     * @param  array<string, mixed>  $arData
     */
    public function readIntField(array $arData, string $sField): int
    {
        $mValue = $arData[$sField] ?? 0;

        return is_numeric($mValue) ? (int) $mValue : 0;
    }

    /**
     * Narrow $arData['context'] to a string-keyed array (phpstan level 10
     * requires explicit string-key narrowing on the SupportsHybridAjax
     * loadSubject contract) + overlay top-level offer_id when present.
     *
     * @param  array<string, mixed>  $arData
     * @return array<string, mixed>
     */
    public function buildHybridContext(array $arData): array
    {
        $arContext = [];
        $mContext = $arData['context'] ?? null;
        if (is_array($mContext)) {
            foreach ($mContext as $mKey => $mValue) {
                if (is_string($mKey)) {
                    $arContext[$mKey] = $mValue;
                }
            }
        }
        foreach (['offer_id'] as $sExtra) {
            if (isset($arData[$sExtra])) {
                $arContext[$sExtra] = $arData[$sExtra];
            }
        }

        return $arContext;
    }

    /**
     * Narrow Request::input to a string-keyed array, or null when unusable.
     *
     * @return array<string, mixed>|null
     */
    private function normalizeStringKeys(mixed $mInput): ?array
    {
        if (! is_array($mInput)) {
            return null;
        }
        $arResult = [];
        foreach ($mInput as $mKey => $mValue) {
            if (! is_string($mKey)) {
                return null;
            }
            $arResult[$mKey] = $mValue;
        }

        return $arResult;
    }
}
