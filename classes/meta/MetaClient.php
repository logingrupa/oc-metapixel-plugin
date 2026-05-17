<?php

namespace Logingrupa\Metapixel\Classes\Meta;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use Logingrupa\Metapixel\Classes\Exception\MetaApiPermanentException;
use Logingrupa\Metapixel\Classes\Exception\MetaApiTransientException;
use Logingrupa\Metapixel\Classes\Exception\MissingCapiTokenException;
use Logingrupa\Metapixel\Classes\Exception\MissingPixelConfigException;

/**
 * Meta Conversions API HTTP boundary. Per-call credentials — caller resolves
 * pixel_id + capi_access_token via Settings::lookupForSite(\$iSiteId) so
 * multi-pixel routing works at queue time. Graph API version pinned to v23.0
 * (v20 expires 2026-09-24); no operator override.
 *
 * Classifies HTTP responses: 2xx returns decoded body; 408/429/5xx +
 * ConnectException throw MetaApiTransientException so the caller can retry;
 * any other HTTP error throws MetaApiPermanentException so the caller can
 * dead-letter. Token is sent in the POST body, never the URL query string —
 * Meta accepts both but webserver access logs leak the URL.
 */
class MetaClient
{
    public const META_GRAPH_API_VERSION = 'v23.0';

    private const META_GRAPH_API_BASE = 'https://graph.facebook.com';

    private const DEFAULT_TIMEOUT_SECONDS = 5;

    /** @var list<int> */
    private const TRANSIENT_STATUS_CODES = [408, 429, 500, 502, 503, 504];

    public function __construct(private readonly ?ClientInterface $obClient = null) {}

    /**
     * @param  array<string, mixed>  $arPayload  envelope with key 'data' => list of event records
     * @return array<string, mixed>
     *
     * @throws MissingPixelConfigException
     * @throws MissingCapiTokenException
     * @throws MetaApiTransientException
     * @throws MetaApiPermanentException
     */
    public function sendForPixel(string $sPixelId, string $sToken, array $arPayload): array
    {
        if ($sPixelId === '') {
            throw new MissingPixelConfigException('metapixel: pixel_id is empty at dispatch time');
        }
        if ($sToken === '') {
            throw new MissingCapiTokenException('metapixel: capi_access_token is empty at dispatch time');
        }

        $sUrl = sprintf(
            '%s/%s/%s/events',
            self::META_GRAPH_API_BASE,
            self::META_GRAPH_API_VERSION,
            $sPixelId,
        );

        $obClient = $this->obClient ?? new Client(['timeout' => self::DEFAULT_TIMEOUT_SECONDS]);

        try {
            $obResponse = $obClient->request('POST', $sUrl, [
                'json' => array_merge($arPayload, ['access_token' => $sToken]),
                'http_errors' => false,
            ]);
        } catch (ConnectException $obException) {
            throw new MetaApiTransientException(
                'metapixel: graph API connect failure',
                null,
                $obException,
                ['url' => $sUrl],
            );
        }

        $iStatus = $obResponse->getStatusCode();
        $sBody = (string) $obResponse->getBody();
        $arDecoded = $this->decodeBody($sBody);

        if ($iStatus >= 200 && $iStatus < 300) {
            return $arDecoded;
        }

        if (in_array($iStatus, self::TRANSIENT_STATUS_CODES, true)) {
            throw new MetaApiTransientException(
                'metapixel: graph API transient '.$iStatus,
                $iStatus,
                null,
                ['response' => $arDecoded],
            );
        }

        throw new MetaApiPermanentException(
            'metapixel: graph API permanent '.$iStatus,
            $iStatus,
            null,
            ['response' => $arDecoded],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(string $sBody): array
    {
        $mDecoded = json_decode($sBody, associative: true);
        if (! is_array($mDecoded)) {
            return [];
        }

        $arResult = [];
        foreach ($mDecoded as $mKey => $mValue) {
            $arResult[(string) $mKey] = $mValue;
        }

        return $arResult;
    }
}
