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

    /**
     * connect_timeout bounds DNS + TCP + TLS setup; a dead lookup costs 2s, not
     * the full 5s request budget.
     *
     * @var array<string, int>
     */
    public const CLIENT_OPTIONS = ['timeout' => 5, 'connect_timeout' => 2];

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

        try {
            $obResponse = $this->client()->request('POST', $sUrl, [
                'json' => array_merge($arPayload, ['access_token' => $sToken]),
                'http_errors' => false,
            ]);
        } catch (ConnectException $obException) {
            throw new MetaApiTransientException(
                $this->connectFailureMessage('metapixel: graph API connect failure', $obException),
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
     * Meta Dataset Quality API — GET /dataset_quality?dataset_id={pixel_id}
     * &fields=web{event_name,event_match_quality,event_coverage}. Returns two
     * event-name keyed maps: composite EMQ score (0-10) and event coverage, the
     * percentage of browser Pixel events Meta matched with a server twin. The
     * API exposes no separate deduplication metric. Events Meta has no data
     * for are absent from the maps; the raw decoded body rides along.
     *
     * @return array{event_match_quality: array<string, float>, event_coverage: array<string, float>, raw: array<string, mixed>}
     *
     * @throws MissingPixelConfigException
     * @throws MissingCapiTokenException
     * @throws MetaApiTransientException
     * @throws MetaApiPermanentException
     */
    public function fetchDatasetQuality(string $sPixelId, string $sToken): array
    {
        if ($sPixelId === '') {
            throw new MissingPixelConfigException('metapixel: pixel_id is empty at dataset quality fetch');
        }
        if ($sToken === '') {
            throw new MissingCapiTokenException('metapixel: capi_access_token is empty at dataset quality fetch');
        }

        $sUrl = sprintf(
            '%s/%s/dataset_quality?dataset_id=%s&fields=%s',
            self::META_GRAPH_API_BASE,
            self::META_GRAPH_API_VERSION,
            rawurlencode($sPixelId),
            rawurlencode('web{event_name,event_match_quality,event_coverage}'),
        );

        try {
            // Token in Authorization header — class docblock policy: NEVER in URL
            // query string (webserver access logs leak the URL). Matches the
            // sendForPixel POST-body transport choice (DRY rationale).
            $obResponse = $this->client()->request('GET', $sUrl, [
                'http_errors' => false,
                'headers' => ['Authorization' => 'Bearer '.$sToken],
            ]);
        } catch (ConnectException $obException) {
            throw new MetaApiTransientException(
                $this->connectFailureMessage('metapixel: dataset quality fetch connect failure', $obException),
                null,
                $obException,
                ['url' => $sUrl],
            );
        }

        $iStatus = $obResponse->getStatusCode();
        $arDecoded = $this->decodeBody((string) $obResponse->getBody());

        if ($iStatus >= 200 && $iStatus < 300) {
            return [
                'event_match_quality' => $this->indexWebMetric($arDecoded, 'event_match_quality', 'composite_score'),
                'event_coverage' => $this->indexWebMetric($arDecoded, 'event_coverage', 'percentage'),
                'raw' => $arDecoded,
            ];
        }

        if (in_array($iStatus, self::TRANSIENT_STATUS_CODES, true)) {
            throw new MetaApiTransientException(
                'metapixel: dataset quality fetch transient '.$iStatus,
                $iStatus,
                null,
                ['response' => $arDecoded],
            );
        }

        throw new MetaApiPermanentException(
            'metapixel: dataset quality fetch permanent '.$iStatus,
            $iStatus,
            null,
            ['response' => $arDecoded],
        );
    }

    /**
     * event_name => numeric value of $sMetric.$sKey across the "web" list of a
     * Dataset Quality response. Entries without the metric are skipped.
     *
     * @param  array<string, mixed>  $arDecoded
     * @return array<string, float>
     */
    private function indexWebMetric(array $arDecoded, string $sMetric, string $sKey): array
    {
        $mWeb = $arDecoded['web'] ?? null;
        if (! is_array($mWeb)) {
            return [];
        }

        $arResult = [];
        foreach ($mWeb as $mEntry) {
            if (! is_array($mEntry)) {
                continue;
            }
            $mName = $mEntry['event_name'] ?? null;
            $mMetric = $mEntry[$sMetric] ?? null;
            $mValue = is_array($mMetric) ? ($mMetric[$sKey] ?? null) : null;
            if (is_string($mName) && $mName !== '' && is_numeric($mValue)) {
                $arResult[$mName] = (float) $mValue;
            }
        }

        return $arResult;
    }

    private function client(): ClientInterface
    {
        return $this->obClient ?? new Client(self::CLIENT_OPTIONS);
    }

    /**
     * Prefix plus the curl cause ("cURL error 28: Resolving timed out after
     * 5001 milliseconds"), without Guzzle's trailing docs link and URL.
     */
    private function connectFailureMessage(string $sPrefix, ConnectException $obException): string
    {
        $sCause = $obException->getMessage();
        $mTrimmed = strstr($sCause, ' (see ', true);
        if (is_string($mTrimmed) && $mTrimmed !== '') {
            $sCause = $mTrimmed;
        }

        return $sCause === '' ? $sPrefix : $sPrefix.': '.$sCause;
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
