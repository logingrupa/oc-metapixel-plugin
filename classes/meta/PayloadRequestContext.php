<?php

namespace Logingrupa\Metapixel\Classes\Meta;

/**
 * Merges request-derived context into a built CAPI envelope: passthrough
 * user_data (client_ip_address, client_user_agent, fbp, fbc) where the
 * subject left them empty, plus the top-level event_source_url. Pure; the
 * callers own the request access.
 */
final class PayloadRequestContext
{
    /**
     * @param  array<string, mixed>  $arPayload  output of PayloadBuilder::buildEventPayload
     * @param  array<string, mixed>  $arUserData  request-derived user_data; null values are skipped
     * @return array<string, mixed>
     */
    public static function merge(array $arPayload, array $arUserData, ?string $sEventSourceUrl): array
    {
        $mData = $arPayload['data'] ?? null;
        if (! is_array($mData) || ! isset($mData[0]) || ! is_array($mData[0])) {
            return $arPayload;
        }

        $arEnvelope = $mData[0];
        $arEnvelope['user_data'] = self::fillEmptyUserData($arEnvelope['user_data'] ?? null, $arUserData);
        if ($sEventSourceUrl !== null && ! isset($arEnvelope['event_source_url'])) {
            $arEnvelope['event_source_url'] = $sEventSourceUrl;
        }

        $mData[0] = $arEnvelope;
        $arPayload['data'] = $mData;

        return $arPayload;
    }

    /**
     * Existing non-empty values win over the request values — the subject may
     * have a more specific source.
     *
     * @param  array<string, mixed>  $arUserData
     * @return array<mixed>
     */
    private static function fillEmptyUserData(mixed $mExisting, array $arUserData): array
    {
        $arResult = is_array($mExisting) ? $mExisting : [];
        foreach ($arUserData as $sKey => $mValue) {
            if ($mValue === null) {
                continue;
            }
            $mCurrent = $arResult[$sKey] ?? null;
            if ($mCurrent === null || $mCurrent === '') {
                $arResult[$sKey] = $mValue;
            }
        }

        return $arResult;
    }
}
