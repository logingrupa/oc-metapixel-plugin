<?php

declare(strict_types=1);

namespace Logingrupa\Metapixelshopaholic\Classes\Meta;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixelshopaholic\Classes\Exception\MetaApiPermanentException;
use Logingrupa\Metapixelshopaholic\Classes\Exception\MetaApiTransientException;
use Logingrupa\Metapixelshopaholic\Classes\Exception\MetaPixelException;
use Logingrupa\Metapixelshopaholic\Classes\Exception\MissingCapiTokenException;
use Logingrupa\Metapixelshopaholic\Classes\Exception\MissingPixelConfigException;
use Logingrupa\Metapixelshopaholic\Models\Settings;
use Throwable;

/**
 * Single HTTP boundary to Meta Graph API v20.0 `/events` (PAY-01). Stateless,
 * single-shot, retry-free — retry/backoff is the queue-job layer (plan 03-05).
 * Constructor-injectable `?ClientInterface` for `MockHandler`-backed tests.
 * Settings (`pixel_id`, `capi_access_token`, `test_event_code`) are read lazily
 * in `send()` so missing config throws at event-time. HTTP 408/429/500/502/503/
 * 504 or `ConnectException` → `MetaApiTransientException`; anything else →
 * `MetaApiPermanentException`. `'http_errors' => false` puts classification on
 * a single `getStatusCode()` switch. T-03-12: log calls never include the
 * access token. T-03-15: 5-second `timeout` caps the worker block per attempt.
 */
class MetaClient
{
    public const string GRAPH_VERSION = 'v20.0';

    private const int DEFAULT_TIMEOUT = 5;

    /** @var list<int> */
    private const array TRANSIENT_STATUS_CODES = [408, 429, 500, 502, 503, 504];

    private readonly ClientInterface $obClient;

    public function __construct(?ClientInterface $obClient = null)
    {
        $this->obClient = $obClient ?? new Client([
            'base_uri' => 'https://graph.facebook.com/'.self::GRAPH_VERSION.'/',
            'timeout' => self::DEFAULT_TIMEOUT,
            'http_errors' => false,
        ]);
    }

    /**
     * Send a CAPI events payload to Meta Graph API v20.
     *
     * @param  array<string, mixed>  $arPayload  envelope with key "data" => list of event records
     * @return array<string, mixed> decoded Graph API response
     *
     * @throws MissingPixelConfigException empty pixel_id Setting
     * @throws MissingCapiTokenException empty capi_access_token Setting
     * @throws MetaApiTransientException HTTP 408/429/500/502/503/504 or ConnectException
     * @throws MetaApiPermanentException any other HTTP error
     */
    public function send(array $arPayload): array
    {
        $sPixelId = $this->readSetting('pixel_id');
        if ($sPixelId === '') {
            throw new MissingPixelConfigException(
                'Meta Pixel ID is not configured',
                ['setting_key' => 'pixel_id'],
            );
        }

        $sAccessToken = $this->readSetting('capi_access_token');
        if ($sAccessToken === '') {
            throw new MissingCapiTokenException(
                'Meta CAPI access token is not configured',
                ['setting_key' => 'capi_access_token'],
            );
        }

        $sTestEventCode = $this->readSetting('test_event_code');
        $arQuery = ['access_token' => $sAccessToken];
        if ($sTestEventCode !== '') {
            $arQuery['test_event_code'] = $sTestEventCode;
        }

        try {
            $obResponse = $this->obClient->request('POST', $sPixelId.'/events', [
                'query' => $arQuery,
                'json' => $arPayload,
            ]);
        } catch (ConnectException $obException) {
            throw $this->makeTransientException(
                'Meta CAPI network failure: '.$obException->getMessage(),
                null,
                $obException,
            );
        } catch (RequestException $obException) {
            $obFailureResponse = $obException->getResponse();
            $iStatus = $obFailureResponse !== null ? $obFailureResponse->getStatusCode() : null;
            throw $this->classifyException($iStatus, $obException);
        }

        return $this->classifyResponse($obResponse->getStatusCode(), (string) $obResponse->getBody());
    }

    /**
     * 2xx → decode body; transient codes → throw transient; rest → permanent.
     *
     * @return array<string, mixed>
     */
    private function classifyResponse(int $iStatus, string $sBody): array
    {
        if ($iStatus >= 200 && $iStatus < 300) {
            return $this->decodeResponseBody($sBody);
        }
        if (in_array($iStatus, self::TRANSIENT_STATUS_CODES, true)) {
            throw $this->makeTransientException(
                'Meta CAPI transient error (HTTP '.$iStatus.'): '.$sBody,
                $iStatus,
            );
        }
        throw $this->makePermanentException(
            'Meta CAPI permanent error (HTTP '.$iStatus.'): '.$sBody,
            $iStatus,
        );
    }

    /**
     * Decode JSON to `array<string, mixed>` (key-loop narrows phpstan level 10).
     *
     * @return array<string, mixed>
     */
    private function decodeResponseBody(string $sBody): array
    {
        $mDecoded = json_decode($sBody, true);
        if (! is_array($mDecoded)) {
            return [];
        }
        $arResult = [];
        foreach ($mDecoded as $mKey => $mValue) {
            if (is_string($mKey)) {
                $arResult[$mKey] = $mValue;
            }
        }

        return $arResult;
    }

    /** Settings scalar-string read; empty-string fallback. Mirrors PluginGuard::prime() lines 134-136. */
    private function readSetting(string $sKey): string
    {
        $mValue = Settings::get($sKey, '');

        return is_scalar($mValue) ? (string) $mValue : '';
    }

    /** Classify a non-network `RequestException` to transient vs permanent by status code. */
    private function classifyException(?int $iStatus, RequestException $obException): MetaPixelException
    {
        if ($iStatus !== null && in_array($iStatus, self::TRANSIENT_STATUS_CODES, true)) {
            return $this->makeTransientException(
                'Meta CAPI transient error (HTTP '.$iStatus.'): '.$obException->getMessage(),
                $iStatus,
                $obException,
            );
        }

        return $this->makePermanentException(
            'Meta CAPI permanent error: '.$obException->getMessage(),
            $iStatus,
            $obException,
        );
    }

    /** Log warning + build `MetaApiTransientException` (T-03-12: token never reaches the log sink). */
    private function makeTransientException(string $sMessage, ?int $iStatus, ?Throwable $obPrevious = null): MetaApiTransientException
    {
        Log::warning($sMessage, ['meta_pixel.http_status' => $iStatus]);

        return new MetaApiTransientException(
            $sMessage,
            ['http_status' => $iStatus, 'graph_error' => $sMessage],
            $obPrevious,
        );
    }

    /** Log error + build `MetaApiPermanentException` (dead-letter at queue layer, no retry). */
    private function makePermanentException(string $sMessage, ?int $iStatus, ?Throwable $obPrevious = null): MetaApiPermanentException
    {
        Log::error($sMessage, ['meta_pixel.http_status' => $iStatus]);

        return new MetaApiPermanentException(
            $sMessage,
            ['http_status' => $iStatus, 'graph_error' => $sMessage],
            $obPrevious,
        );
    }
}
