<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Meta\MetaClient;
use Logingrupa\Metapixel\Classes\Queue\SendCapiEvent;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\Doubles\TestSubject;
use Logingrupa\Metapixel\Tests\Doubles\TestSubjectAdapter;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Logingrupa\Metapixel\Updates\AddPayloadToMetapixelEventLogTable;
use Logingrupa\Metapixel\Updates\CreateMetapixelEventLogTable;
use Logingrupa\Metapixel\Updates\CreateMetapixelFailedEventsTable;
use Psr\Http\Message\RequestInterface;

/**
 * A long-running worker reads credentials from the shared row snapshot on every
 * job, so a settings save between two jobs reaches the next send.
 */
final class SendCapiEventCredentialFreshnessTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
        (new CreateMetapixelEventLogTable)->up();
        (new AddPayloadToMetapixelEventLogTable)->up();
        (new CreateMetapixelFailedEventsTable)->up();
        Settings::clearInternalCache();
        Settings::set(['pixel_id' => '420000000000042', 'capi_access_token' => 'EAATOKEN-OLD']);
        app(AdapterRegistry::class)->register(TestSubject::class, TestSubjectAdapter::class);
    }

    protected function tearDown(): void
    {
        (new AddPayloadToMetapixelEventLogTable)->down();
        (new CreateMetapixelEventLogTable)->down();
        (new CreateMetapixelFailedEventsTable)->down();
        app()->forgetInstance(AdapterRegistry::class);
        parent::tearDown();
    }

    public function test_the_next_job_sends_the_token_saved_after_the_previous_job(): void
    {
        $arHistory = [];
        $obStack = HandlerStack::create(new MockHandler([
            new Response(200, [], '{"events_received":1}'),
            new Response(200, [], '{"events_received":1}'),
        ]));
        $obStack->push(Middleware::history($arHistory));
        $obMetaClient = new MetaClient(new Client(['handler' => $obStack]));

        $this->runJob('uuid-1', 42, $obMetaClient);
        Settings::set(['capi_access_token' => 'EAATOKEN-NEW']);
        $this->runJob('uuid-2', 43, $obMetaClient);

        $this->assertCount(2, $arHistory);
        $this->assertSame('EAATOKEN-OLD', $this->sentToken($arHistory[0]));
        $this->assertSame('EAATOKEN-NEW', $this->sentToken($arHistory[1]));
    }

    private function runJob(string $sEventId, int $iSubjectId, MetaClient $obMetaClient): void
    {
        $obSubject = new TestSubject;
        $obSubject->iId = $iSubjectId;
        $arPayload = ['data' => [[
            'event_id' => $sEventId,
            'event_time' => 1700000000,
            'event_name' => 'Purchase',
            'action_source' => 'website',
            'user_data' => ['client_ip_address' => '203.0.113.9'],
            'custom_data' => [],
        ]]];

        (new SendCapiEvent('Purchase', $arPayload, $obSubject, TestSubjectAdapter::class))
            ->handle(app(AdapterRegistry::class), $obMetaClient);
    }

    /** @param  array<string, mixed>  $arTransaction */
    private function sentToken(array $arTransaction): mixed
    {
        $obRequest = $arTransaction['request'];
        $this->assertInstanceOf(RequestInterface::class, $obRequest);
        $arBody = json_decode((string) $obRequest->getBody(), true);
        $this->assertIsArray($arBody);

        return $arBody['access_token'] ?? null;
    }
}
