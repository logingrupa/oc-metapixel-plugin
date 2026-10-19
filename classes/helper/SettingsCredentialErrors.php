<?php

namespace Logingrupa\Metapixel\Classes\Helper;

use Closure;
use Illuminate\Support\Facades\Lang;
use Logingrupa\Metapixel\Classes\Meta\CredentialFormat;

/**
 * Settings form errors for the pixel id and access token fields.
 */
final class SettingsCredentialErrors
{
    private const LANG_PREFIX = 'logingrupa.metapixel::lang.validation.';

    /**
     * Errors keyed by field. Only null and '' count as empty; messages never echo the value.
     *
     * @return array<string, array<int, string>>
     */
    public static function forValues(mixed $mPixel, mixed $mToken): array
    {
        $arErrorKeys = array_filter([
            'pixel_id' => self::errorKey($mPixel, CredentialFormat::pixelIdErrorKey(...), 'pixel_id_digits'),
            'capi_access_token' => self::errorKey($mToken, CredentialFormat::accessTokenErrorKey(...), 'capi_access_token_prefix'),
        ]);

        $arErrors = [];
        foreach ($arErrorKeys as $sField => $sErrorKey) {
            $mMessage = Lang::get(self::LANG_PREFIX.$sErrorKey);
            $arErrors[$sField] = [is_string($mMessage) ? $mMessage : self::LANG_PREFIX.$sErrorKey];
        }

        return $arErrors;
    }

    /**
     * @param  Closure(string): ?string  $fnStringRule
     */
    private static function errorKey(mixed $mValue, Closure $fnStringRule, string $sNonStringKey): ?string
    {
        if ($mValue === null) {
            return null;
        }

        return is_string($mValue) ? $fnStringRule($mValue) : $sNonStringKey;
    }
}
