<?php

namespace Logingrupa\Metapixel\Classes\Meta;

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
}
