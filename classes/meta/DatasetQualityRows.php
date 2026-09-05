<?php

namespace Logingrupa\Metapixel\Classes\Meta;

/**
 * Turns a MetaClient::fetchDatasetQuality response into one row per event
 * name for the Failed events panel: composite EMQ (0 to 10) and coverage
 * (percentage of browser Pixel events matched with a server twin).
 */
final class DatasetQualityRows
{
    /**
     * @param  array<string, mixed>  $arResponse
     * @return list<array{event_name: string, emq: ?float, coverage: ?float}>
     */
    public static function fromResponse(array $arResponse): array
    {
        $arEmq = self::numericMap($arResponse['event_match_quality'] ?? null);
        $arCoverage = self::numericMap($arResponse['event_coverage'] ?? null);

        $arEventNames = array_unique(array_merge(array_keys($arEmq), array_keys($arCoverage)));
        sort($arEventNames);

        $arRows = [];
        foreach ($arEventNames as $sEventName) {
            $arRows[] = [
                'event_name' => $sEventName,
                'emq' => $arEmq[$sEventName] ?? null,
                'coverage' => $arCoverage[$sEventName] ?? null,
            ];
        }

        return $arRows;
    }

    /**
     * Keeps only string keys with numeric values; anything else is dropped.
     *
     * @return array<string, float>
     */
    private static function numericMap(mixed $mField): array
    {
        if (! is_array($mField)) {
            return [];
        }

        $arOut = [];
        foreach ($mField as $mKey => $mValue) {
            if (! is_string($mKey) || $mKey === '' || ! is_numeric($mValue)) {
                continue;
            }
            $arOut[$mKey] = round((float) $mValue, 2);
        }

        return $arOut;
    }
}
