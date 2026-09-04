<?php

namespace Logingrupa\Metapixel\Classes\Helper;

/**
 * Current request URL for the Meta CAPI event_source_url field. Reads
 * $_SERVER so the request-free layers (classes/event, classes/adapter) can
 * share it with the components. Null outside HTTP or when the pieces do not
 * form a valid http(s) URL of sane length.
 */
final class EventSourceUrl
{
    private const MAX_LENGTH = 2048;

    public static function current(): ?string
    {
        return self::fromServer($_SERVER);
    }

    /**
     * @param  array<mixed>  $arServer  $_SERVER-shaped map
     */
    public static function fromServer(array $arServer): ?string
    {
        $sHost = self::stringValue($arServer, 'HTTP_HOST');
        $sUri = self::stringValue($arServer, 'REQUEST_URI');
        if ($sHost === '' || $sUri === '' || ! str_starts_with($sUri, '/')) {
            return null;
        }

        $sUrl = self::scheme($arServer).'://'.$sHost.$sUri;
        if (strlen($sUrl) > self::MAX_LENGTH) {
            return null;
        }
        if (filter_var($sUrl, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $sUrl;
    }

    /**
     * @param  array<mixed>  $arServer
     */
    private static function scheme(array $arServer): string
    {
        if (strtolower(self::stringValue($arServer, 'HTTP_X_FORWARDED_PROTO')) === 'https') {
            return 'https';
        }

        $sHttps = strtolower(self::stringValue($arServer, 'HTTPS'));

        return $sHttps !== '' && $sHttps !== 'off' ? 'https' : 'http';
    }

    /**
     * @param  array<mixed>  $arServer
     */
    private static function stringValue(array $arServer, string $sKey): string
    {
        $mValue = $arServer[$sKey] ?? null;

        return is_string($mValue) ? $mValue : '';
    }
}
