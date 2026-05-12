<?php

namespace Logingrupa\Metapixelshopaholic\Tests\Unit;

require_once __DIR__.'/../MetapixelTestCase.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Logingrupa\Metapixelshopaholic\Classes\Exception\MetaApiPermanentException;
use Logingrupa\Metapixelshopaholic\Classes\Exception\MetaApiTransientException;
use Logingrupa\Metapixelshopaholic\Classes\Exception\MissingCapiTokenException;
use Logingrupa\Metapixelshopaholic\Classes\Exception\MissingPixelConfigException;
use Logingrupa\Metapixelshopaholic\Classes\Helper\PluginGuard;
use Logingrupa\Metapixelshopaholic\Classes\Meta\MetaClient;
use Logingrupa\Metapixelshopaholic\Models\Settings;
use Logingrupa\Metapixelshopaholic\Tests\MetapixelTestCase;

/**
 * Unit test locking the Plan 03-03 PAY-01 MetaClient HTTP-boundary contract.
 *
 * Seven send-time invariants (matching the plan's <behavior> bullets):
 *
 *   1. 200 success → returns the decoded JSON array.
 *   2. 503 transient → MetaApiTransientException with arContext.http_status.
 *   3. Each of [408, 429, 500, 502, 504] → MetaApiTransientException
 *      (data-driven; 503 already covered by test 2).
 *   4. 400 permanent → MetaApiPermanentException with arContext.http_status.
 *   5. ConnectException (network failure) → MetaApiTransientException with
 *      arContext.http_status === null.
 *   6. Empty pixel_id Setting → MissingPixelConfigException (no HTTP call).
 *   7. Empty capi_access_token Setting → MissingCapiTokenException (no HTTP).
 *   8. Non-empty test_event_code Setting → appears in the outgoing query
 *      string captured by Middleware::history().
 *
 * Test isolation uses the round-trip pattern from BootsWithoutPixelIdTest:
 * `Settings::set(...) → Settings::clearInternalCache() → Cache::flush() →
 * PluginGuard::flush()`. If the documented HR-02 multi-test flap surfaces,
 * the helper `setSetting()` is the swap point for reflection priming
 * (see PixelHeadTest::primePluginGuardEnabled).
 *
 * MockHandler-backed Guzzle Client is constructor-injected into MetaClient,
 * so no Http::fake() facade pollution and no live network calls. Each test
 * builds its own (Client, history-ref) tuple via makeClientWithMockResponses.
 */
final class MetaClientTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // system_settings provisioned by MetapixelTestCase::createApplication;
        // bootSystemSettings() is the explicit guard for tests that may run
        // before any other test has touched the schema.
        $this->bootSystemSettings();
        Cache::flush();
        Settings::clearInternalCache();
        PluginGuard::flush();
    }

    public function test_send_returns_decoded_array_on_200(): void
    {
        $this->setSetting('pixel_id', '2291486191076331');
        $this->setSetting('capi_access_token', 'EAA-test-token');
        $obClient = $this->makeClientWithMockResponses([
            new Response(200, [], '{"events_received": 1, "fbtrace_id": "abc"}'),
        ]);

        $arResult = $obClient->send(['data' => [['event_id' => 'abc']]]);

        $this->assertSame(1, $arResult['events_received'], '200 response body must round-trip via json_decode.');
        $this->assertSame('abc', $arResult['fbtrace_id'], 'fbtrace_id must round-trip via json_decode.');
    }

    public function test_send_throws_transient_on_503_status(): void
    {
        $this->setSetting('pixel_id', '2291486191076331');
        $this->setSetting('capi_access_token', 'EAA-test-token');
        $obClient = $this->makeClientWithMockResponses([
            new Response(503, [], 'service unavailable'),
        ]);

        $bThrown = false;
        try {
            $obClient->send(['data' => [['event_id' => 'abc']]]);
        } catch (MetaApiTransientException $obException) {
            $bThrown = true;
            $this->assertSame(503, $obException->arContext['http_status'], 'arContext.http_status must equal 503.');
        }
        $this->assertTrue($bThrown, 'HTTP 503 must throw MetaApiTransientException.');
    }

    public function test_send_throws_transient_on_each_transient_status_code(): void
    {
        // Pest 4 does not enumerate PHPUnit `@dataProvider` test methods on
        // class-style tests reliably; an inline loop is equivalent for the
        // 5-row case and avoids the Pest/PHPUnit boundary friction.
        foreach ([408, 429, 500, 502, 504] as $iStatusCode) {
            $this->setSetting('pixel_id', '2291486191076331');
            $this->setSetting('capi_access_token', 'EAA-test-token');
            $obClient = $this->makeClientWithMockResponses([
                new Response($iStatusCode, [], 'transient'),
            ]);

            $bThrown = false;
            try {
                $obClient->send(['data' => [['event_id' => 'abc']]]);
            } catch (MetaApiTransientException $obException) {
                $bThrown = true;
                $this->assertSame($iStatusCode, $obException->arContext['http_status'], "HTTP {$iStatusCode} must surface in arContext.");
            }
            $this->assertTrue($bThrown, "HTTP {$iStatusCode} must be classified transient.");
        }
    }

    public function test_send_throws_permanent_on_400_status(): void
    {
        $this->setSetting('pixel_id', '2291486191076331');
        $this->setSetting('capi_access_token', 'EAA-test-token');
        $obClient = $this->makeClientWithMockResponses([
            new Response(400, [], '{"error":{"message":"bad payload"}}'),
        ]);

        $bThrown = false;
        try {
            $obClient->send(['data' => [['event_id' => 'abc']]]);
        } catch (MetaApiPermanentException $obException) {
            $bThrown = true;
            $this->assertSame(400, $obException->arContext['http_status'], 'arContext.http_status must equal 400.');
        }
        $this->assertTrue($bThrown, 'HTTP 400 must throw MetaApiPermanentException.');
    }

    public function test_send_throws_permanent_on_401_403_404_422_statuses(): void
    {
        foreach ([401, 403, 404, 422] as $iStatusCode) {
            $this->setSetting('pixel_id', '2291486191076331');
            $this->setSetting('capi_access_token', 'EAA-test-token');
            $obClient = $this->makeClientWithMockResponses([
                new Response($iStatusCode, [], '{"error":{"message":"permanent"}}'),
            ]);

            $bThrown = false;
            try {
                $obClient->send(['data' => [['event_id' => 'abc']]]);
            } catch (MetaApiPermanentException $obException) {
                $bThrown = true;
                $this->assertSame($iStatusCode, $obException->arContext['http_status'], "arContext.http_status must equal {$iStatusCode}.");
            }
            $this->assertTrue($bThrown, "HTTP {$iStatusCode} must throw MetaApiPermanentException.");
        }
    }

    public function test_send_throws_transient_on_connect_exception(): void
    {
        $this->setSetting('pixel_id', '2291486191076331');
        $this->setSetting('capi_access_token', 'EAA-test-token');
        $obClient = $this->makeClientWithMockResponses([
            new ConnectException('Connection refused', new Request('POST', '/')),
        ]);

        $bThrown = false;
        try {
            $obClient->send(['data' => [['event_id' => 'abc']]]);
        } catch (MetaApiTransientException $obException) {
            $bThrown = true;
            $this->assertNull($obException->arContext['http_status'], 'arContext.http_status must be null for ConnectException.');
        }
        $this->assertTrue($bThrown, 'ConnectException must be classified transient.');
    }

    public function test_send_throws_missing_pixel_config_when_pixel_id_empty(): void
    {
        $this->setSetting('pixel_id', '');
        $this->setSetting('capi_access_token', 'EAA-test-token');
        $arHistory = [];
        $obClient = $this->makeClientWithMockResponses([
            new Response(200, [], '{"events_received": 1}'),
        ], $arHistory);

        $bThrown = false;
        try {
            $obClient->send(['data' => [['event_id' => 'abc']]]);
        } catch (MissingPixelConfigException $obException) {
            $bThrown = true;
            $this->assertSame('pixel_id', $obException->arContext['setting_key']);
        }
        $this->assertTrue($bThrown, 'Empty pixel_id must throw MissingPixelConfigException.');
        $this->assertCount(0, $arHistory, 'MissingPixelConfigException must throw BEFORE any HTTP call (history must be empty).');
    }

    public function test_send_throws_missing_capi_token_when_token_empty(): void
    {
        $this->setSetting('pixel_id', '2291486191076331');
        $this->setSetting('capi_access_token', '');
        $arHistory = [];
        $obClient = $this->makeClientWithMockResponses([
            new Response(200, [], '{"events_received": 1}'),
        ], $arHistory);

        $bThrown = false;
        try {
            $obClient->send(['data' => [['event_id' => 'abc']]]);
        } catch (MissingCapiTokenException $obException) {
            $bThrown = true;
            $this->assertSame('capi_access_token', $obException->arContext['setting_key']);
        }
        $this->assertTrue($bThrown, 'Empty capi_access_token must throw MissingCapiTokenException.');
        $this->assertCount(0, $arHistory, 'MissingCapiTokenException must throw BEFORE any HTTP call.');
    }

    public function test_send_includes_test_event_code_in_query_when_set(): void
    {
        $this->setSetting('pixel_id', '2291486191076331');
        $this->setSetting('capi_access_token', 'EAA-test-token');
        $this->setSetting('test_event_code', 'TEST123');
        $arHistory = [];
        $obClient = $this->makeClientWithMockResponses([
            new Response(200, [], '{"events_received": 1}'),
        ], $arHistory);

        $obClient->send(['data' => [['event_id' => 'abc']]]);

        $this->assertCount(1, $arHistory, 'Exactly one HTTP call must be issued.');
        $sQuery = $arHistory[0]['request']->getUri()->getQuery();
        $this->assertStringContainsString('test_event_code=TEST123', $sQuery, 'Outgoing query string must include test_event_code when Setting is set.');
        $this->assertStringContainsString('access_token=EAA-test-token', $sQuery, 'Outgoing query string must include access_token.');
    }

    public function test_send_omits_test_event_code_from_query_when_unset(): void
    {
        $this->setSetting('pixel_id', '2291486191076331');
        $this->setSetting('capi_access_token', 'EAA-test-token');
        $this->setSetting('test_event_code', '');
        $arHistory = [];
        $obClient = $this->makeClientWithMockResponses([
            new Response(200, [], '{"events_received": 1}'),
        ], $arHistory);

        $obClient->send(['data' => [['event_id' => 'abc']]]);

        $this->assertCount(1, $arHistory);
        $sQuery = $arHistory[0]['request']->getUri()->getQuery();
        $this->assertStringNotContainsString('test_event_code', $sQuery, 'test_event_code must be absent from query when Setting is empty.');
    }

    public function test_send_posts_to_pixel_id_events_path(): void
    {
        $this->setSetting('pixel_id', '2291486191076331');
        $this->setSetting('capi_access_token', 'EAA-test-token');
        $arHistory = [];
        $obClient = $this->makeClientWithMockResponses([
            new Response(200, [], '{"events_received": 1}'),
        ], $arHistory);

        $obClient->send(['data' => [['event_id' => 'abc']]]);

        $this->assertCount(1, $arHistory);
        $obRequest = $arHistory[0]['request'];
        $this->assertSame('POST', $obRequest->getMethod(), 'HTTP method must be POST.');
        $this->assertStringContainsString('2291486191076331/events', (string) $obRequest->getUri(), 'Path must be {pixel_id}/events.');
    }

    public function test_graph_version_constant_is_v20(): void
    {
        $this->assertSame('v20.0', MetaClient::GRAPH_VERSION, 'GRAPH_VERSION constant must be locked to v20.0 per PROJECT.md out-of-scope.');
    }

    /**
     * Prime the Settings model's in-memory instance directly via reflection
     * — sidesteps HR-02 (the SQLite-Multisite round-trip flaps when multiple
     * Settings::set / Settings::get pairs run in the same test). Mirrors the
     * PluginGuard reflection-priming pattern used in PixelHeadTest. The
     * resulting Settings::get('key') call executes the real production read
     * path against the primed instance — only the underlying DB write is
     * bypassed.
     */
    private function setSetting(string $sKey, mixed $mValue): void
    {
        $obInstance = Settings::instance();
        $obInstance->setAttribute($sKey, $mValue);
    }

    /**
     * Build a MetaClient wired to a MockHandler-backed Guzzle Client. The
     * `$arHistory` parameter is taken BY REFERENCE so the caller owns the
     * capture buffer in its own scope — array-destructuring of a returned
     * tuple silently breaks by-reference semantics, hence the explicit
     * reference parameter.
     *
     * @param  list<Response|\Exception>  $arResponses
     * @param  array<int, array<string, mixed>>  $arHistory  reference buffer
     */
    private function makeClientWithMockResponses(array $arResponses, array &$arHistory = []): MetaClient
    {
        $obMock = new MockHandler($arResponses);
        $obStack = HandlerStack::create($obMock);
        $obStack->push(Middleware::history($arHistory));
        $obGuzzle = new Client(['handler' => $obStack, 'http_errors' => false]);

        return new MetaClient($obGuzzle);
    }
}
