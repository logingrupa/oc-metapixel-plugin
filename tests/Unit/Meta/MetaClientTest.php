<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Exception\MetaApiPermanentException;
use Logingrupa\Metapixel\Classes\Exception\MetaApiTransientException;
use Logingrupa\Metapixel\Classes\Exception\MissingCapiTokenException;
use Logingrupa\Metapixel\Classes\Exception\MissingPixelConfigException;
use Logingrupa\Metapixel\Classes\Meta\MetaClient;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;

final class MetaClientTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
    }

    public function test_send_for_pixel_returns_decoded_array_on_200(): void
    {
        $obMock = new MockHandler([
            new Response(200, [], json_encode(['events_received' => 1, 'fbtrace_id' => 'AAA']) ?: ''),
        ]);
        $obClient = new Client(['handler' => HandlerStack::create($obMock)]);

        $arResult = (new MetaClient($obClient))->sendForPixel('PIXEL-42', 'TOKEN-XYZ', ['data' => []]);

        $this->assertSame(1, $arResult['events_received']);
        $this->assertSame('AAA', $arResult['fbtrace_id']);
    }

    public function test_throws_missing_pixel_config_on_empty_pixel_id(): void
    {
        $this->expectException(MissingPixelConfigException::class);
        (new MetaClient)->sendForPixel('', 'TOKEN', ['data' => []]);
    }

    public function test_throws_missing_capi_token_on_empty_token(): void
    {
        $this->expectException(MissingCapiTokenException::class);
        (new MetaClient)->sendForPixel('PIXEL-42', '', ['data' => []]);
    }

    public static function provideTransientStatusCodes(): array
    {
        return [
            '408' => [408],
            '429' => [429],
            '500' => [500],
            '502' => [502],
            '503' => [503],
            '504' => [504],
        ];
    }

    #[DataProvider('provideTransientStatusCodes')]
    public function test_throws_transient_on_status(int $iStatus): void
    {
        $obMock = new MockHandler([
            new Response($iStatus, [], json_encode(['error' => ['message' => 'whoops']]) ?: ''),
        ]);
        $obClient = new Client(['handler' => HandlerStack::create($obMock)]);

        try {
            (new MetaClient($obClient))->sendForPixel('PIXEL-42', 'TOKEN', ['data' => []]);
            $this->fail("expected MetaApiTransientException for status {$iStatus}");
        } catch (MetaApiTransientException $obException) {
            $this->assertSame($iStatus, $obException->getHttpStatus());
        }
    }

    public static function providePermanentStatusCodes(): array
    {
        return [
            '400' => [400],
            '401' => [401],
            '403' => [403],
            '404' => [404],
        ];
    }

    #[DataProvider('providePermanentStatusCodes')]
    public function test_throws_permanent_on_status(int $iStatus): void
    {
        $obMock = new MockHandler([
            new Response($iStatus, [], json_encode(['error' => ['message' => 'rejected']]) ?: ''),
        ]);
        $obClient = new Client(['handler' => HandlerStack::create($obMock)]);

        try {
            (new MetaClient($obClient))->sendForPixel('PIXEL-42', 'TOKEN', ['data' => []]);
            $this->fail("expected MetaApiPermanentException for status {$iStatus}");
        } catch (MetaApiPermanentException $obException) {
            $this->assertSame($iStatus, $obException->getHttpStatus());
        }
    }

    public function test_connect_exception_rewrapped_as_transient(): void
    {
        $obConnect = new ConnectException('cURL: timeout', new Request('POST', 'https://graph.facebook.com'));
        $obMock = new MockHandler([$obConnect]);
        $obClient = new Client(['handler' => HandlerStack::create($obMock)]);

        try {
            (new MetaClient($obClient))->sendForPixel('PIXEL-42', 'TOKEN', ['data' => []]);
            $this->fail('expected MetaApiTransientException on ConnectException');
        } catch (MetaApiTransientException $obException) {
            $this->assertSame($obConnect, $obException->getPrevious(), 'original ConnectException MUST be carried as previous');
            $this->assertNull($obException->getHttpStatus(), 'connect failure has no HTTP status');
            $this->assertSame('metapixel: graph API connect failure: cURL: timeout', $obException->getMessage(), 'the curl cause travels in the message');
        }
    }

    public function test_connect_failure_message_drops_guzzle_docs_link_and_url(): void
    {
        $obConnect = new ConnectException(
            'cURL error 28: Resolving timed out after 5001 milliseconds (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://graph.facebook.com/v23.0/PIXEL-42/events',
            new Request('POST', 'https://graph.facebook.com'),
        );
        $obClient = new Client(['handler' => HandlerStack::create(new MockHandler([$obConnect]))]);

        try {
            (new MetaClient($obClient))->sendForPixel('PIXEL-42', 'TOKEN', ['data' => []]);
            $this->fail('expected MetaApiTransientException on ConnectException');
        } catch (MetaApiTransientException $obException) {
            $this->assertSame('metapixel: graph API connect failure: cURL error 28: Resolving timed out after 5001 milliseconds', $obException->getMessage());
        }
    }

    public function test_default_client_bounds_connect_time_separately(): void
    {
        $this->assertSame(2, MetaClient::CLIENT_OPTIONS['connect_timeout']);
        $this->assertSame(5, MetaClient::CLIENT_OPTIONS['timeout']);
    }

    public function test_dataset_quality_calls_the_dataset_quality_endpoint_with_bearer_token(): void
    {
        $arHistory = [];
        $obMock = new MockHandler([new Response(200, [], '{"web":[]}')]);
        $obStack = HandlerStack::create($obMock);
        $obStack->push(Middleware::history($arHistory));
        $obClient = new Client(['handler' => $obStack]);

        $arResult = (new MetaClient($obClient))->fetchDatasetQuality('PIXEL-42', 'TOKEN');

        $this->assertCount(1, $arHistory);
        /** @var RequestInterface $obRequest */
        $obRequest = $arHistory[0]['request'];
        $this->assertSame('GET', $obRequest->getMethod());
        $sUrl = (string) $obRequest->getUri();
        $this->assertStringStartsWith('https://graph.facebook.com/'.MetaClient::META_GRAPH_API_VERSION.'/dataset_quality?', $sUrl);
        $this->assertStringContainsString('dataset_id=PIXEL-42', $sUrl);
        $this->assertStringContainsString('fields='.rawurlencode('web{event_name,event_match_quality,event_coverage}'), $sUrl);
        $this->assertStringNotContainsString('TOKEN', $sUrl, 'token never travels in the URL');
        $this->assertSame('Bearer TOKEN', $obRequest->getHeaderLine('Authorization'));
        $this->assertSame(['event_match_quality' => [], 'event_coverage' => [], 'raw' => ['web' => []]], $arResult);
    }

    public function test_dataset_quality_indexes_emq_and_coverage_by_event_name(): void
    {
        $sBody = json_encode(['web' => [
            ['event_name' => 'InitiateCheckout'],
            ['event_name' => 'ViewContent', 'event_match_quality' => ['composite_score' => 4.5, 'match_key_feedback' => []], 'event_coverage' => ['percentage' => 0.1]],
            ['event_name' => 'AddToCart', 'event_match_quality' => ['composite_score' => 5.7], 'event_coverage' => ['percentage' => 100]],
            ['event_name' => '', 'event_match_quality' => ['composite_score' => 9]],
            'junk',
        ]]) ?: '';
        $obClient = new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, [], $sBody)]))]);

        $arResult = (new MetaClient($obClient))->fetchDatasetQuality('PIXEL-42', 'TOKEN');

        $this->assertSame(['ViewContent' => 4.5, 'AddToCart' => 5.7], $arResult['event_match_quality']);
        $this->assertSame(['ViewContent' => 0.1, 'AddToCart' => 100.0], $arResult['event_coverage']);
        $this->assertArrayHasKey('web', $arResult['raw']);
    }

    public function test_dataset_quality_permission_error_is_permanent(): void
    {
        $sBody = '{"error":{"message":"(#100) Missing Permission","type":"OAuthException","code":100}}';
        $obClient = new Client(['handler' => HandlerStack::create(new MockHandler([new Response(400, [], $sBody)]))]);

        try {
            (new MetaClient($obClient))->fetchDatasetQuality('PIXEL-42', 'TOKEN');
            $this->fail('expected MetaApiPermanentException on HTTP 400');
        } catch (MetaApiPermanentException $obException) {
            $this->assertSame(400, $obException->getHttpStatus());
            $this->assertSame('(#100) Missing Permission', $obException->getContext()['response']['error']['message'] ?? null);
        }
    }

    public function test_dataset_quality_requires_pixel_and_token(): void
    {
        $obClient = new MetaClient(new Client(['handler' => HandlerStack::create(new MockHandler([]))]));

        $this->expectException(MissingPixelConfigException::class);
        $obClient->fetchDatasetQuality('', 'TOKEN');
    }

    public function test_url_contains_graph_version_and_pixel_id_and_token_lives_in_body(): void
    {
        $arHistory = [];
        $obMock = new MockHandler([new Response(200, [], '{"events_received":1}')]);
        $obStack = HandlerStack::create($obMock);
        $obStack->push(Middleware::history($arHistory));
        $obClient = new Client(['handler' => $obStack]);

        (new MetaClient($obClient))->sendForPixel('PIXEL-42', 'TOKEN-XYZ', ['data' => [['event_name' => 'Purchase']]]);

        $this->assertCount(1, $arHistory);
        /** @var RequestInterface $obRequest */
        $obRequest = $arHistory[0]['request'];
        $sUrl = (string) $obRequest->getUri();

        // Graph version + pixel id in path
        $this->assertStringContainsString('/v23.0/PIXEL-42/events', $sUrl);
        // Token MUST NOT appear in URL (leaks via webserver access logs)
        $this->assertStringNotContainsString('access_token=', $sUrl);
        $this->assertStringNotContainsString('TOKEN-XYZ', $sUrl);

        // Token IS in body json
        $sBody = (string) $obRequest->getBody();
        $arBody = json_decode($sBody, associative: true);
        $this->assertSame('TOKEN-XYZ', $arBody['access_token']);
        $this->assertArrayHasKey('data', $arBody);
    }

    public function test_meta_graph_api_version_constant_pinned_to_v23(): void
    {
        $this->assertSame('v23.0', MetaClient::META_GRAPH_API_VERSION);
    }

    public function test_non_json_body_decodes_to_empty_array_on_2xx(): void
    {
        // Defensive: Meta upstream should always return JSON, but if it ever
        // returns a non-JSON body on a 2xx (proxy / edge cache mishap), we
        // must not crash the caller.
        $obMock = new MockHandler([new Response(200, [], 'not-json-at-all')]);
        $obClient = new Client(['handler' => HandlerStack::create($obMock)]);

        $arResult = (new MetaClient($obClient))->sendForPixel('PIXEL-42', 'TOKEN', ['data' => []]);

        $this->assertSame([], $arResult);
    }
}
