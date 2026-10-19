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
}
