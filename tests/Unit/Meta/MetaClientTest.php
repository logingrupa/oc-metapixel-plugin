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
        }
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
        /** @var \Psr\Http\Message\RequestInterface $obRequest */
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
}
