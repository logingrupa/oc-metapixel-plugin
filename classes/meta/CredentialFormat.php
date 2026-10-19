<?php

namespace Logingrupa\Metapixel\Classes\Meta;

use Illuminate\Support\Facades\Lang;

/**
 * Meta credential format rule: pixel ids are digits only, CAPI access tokens start with EAA.
 */
final class CredentialFormat
{
    public static function isPixelId(string $sValue): bool
    {
        return preg_match('/^\d+\z/', $sValue) === 1;
    }

    public static function isAccessToken(string $sValue): bool
    {
        return str_starts_with($sValue, 'EAA');
    }

    /**
     * Validation lang key for a malformed pixel id, null when empty or valid.
     */
    public static function pixelIdErrorKey(string $sValue): ?string
    {
        if ($sValue === '' || self::isPixelId($sValue)) {
            return null;
        }

        return str_contains($sValue, '@') ? 'pixel_id_email' : 'pixel_id_digits';
    }

    /**
     * Validation lang key for a malformed access token, null when empty or valid.
     */
    public static function accessTokenErrorKey(string $sValue): ?string
    {
        return $sValue === '' || self::isAccessToken($sValue) ? null : 'capi_access_token_prefix';
    }

    /**
     * Settings form errors keyed by field. Empty values pass; messages never echo the value.
     *
     * @return array<string, list<string>>
     */
    public static function fieldErrors(mixed $mPixel, mixed $mToken): array
    {
        $arErrorKeys = array_filter([
            'pixel_id' => self::pixelIdErrorKey(is_string($mPixel) ? $mPixel : ''),
            'capi_access_token' => self::accessTokenErrorKey(is_string($mToken) ? $mToken : ''),
        ]);

        $arErrors = [];
        foreach ($arErrorKeys as $sField => $sErrorKey) {
            $sLangKey = 'logingrupa.metapixel::lang.validation.'.$sErrorKey;
            $mMessage = Lang::get($sLangKey);
            $arErrors[$sField] = [is_string($mMessage) ? $mMessage : $sLangKey];
        }

        return $arErrors;
    }
}
