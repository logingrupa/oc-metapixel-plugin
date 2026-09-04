<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Queue\SendCapiEvent;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\Doubles\SpyMetaClient;
use Logingrupa\Metapixel\Tests\Doubles\TestSubject;
use Logingrupa\Metapixel\Tests\Doubles\TestSubjectAdapter;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Logingrupa\Metapixel\Updates\AddPayloadToMetapixelEventLogTable;
use Logingrupa\Metapixel\Updates\CreateMetapixelEventLogTable;
use Logingrupa\Metapixel\Updates\CreateMetapixelFailedEventsTable;

/**
 * A crawler user agent in user_data skips the whole CAPI pipeline: no Meta
 * call, no event log row, no hook. A browser user agent goes through.
 */
final class SendCapiEventCrawlerSkipTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
        (new CreateMetapixelEventLogTable)->up();
        (new AddPayloadToMetapixelEventLogTable)->up();
        (new CreateMetapixelFailedEventsTable)->up();
        Settings::clearInternalCache();
        Settings::set(['pixel_id' => 'PIXEL-42', 'capi_access_token' => 'TOKEN-XYZ']);
        app(AdapterRegistry::class)->register(TestSubject::class, TestSubjectAdapter::class);
    }

    protected function tearDown(): void
    {
        (new AddPayloadToMetapixelEventLogTable)->down();
        (new CreateMetapixelEventLogTable)->down();
        (new CreateMetapixelFailedEventsTable)->down();
        Event::forget(SendCapiEvent::HOOK_BEFORE_DISPATCH);
        app()->forgetInstance(AdapterRegistry::class);
        parent::tearDown();
    }

    public function test_crawler_user_agent_skips_send_log_and_hooks(): void
    {
        $bHookFired = false;
        Event::listen(SendCapiEvent::HOOK_BEFORE_DISPATCH, function () use (&$bHookFired): void {
            $bHookFired = true;
        });
        $obSpy = new SpyMetaClient;

        $obJob = new SendCapiEvent('PageView', $this->makePayload('Mozilla/5.0 (compatible; DotBot/1.2; +https://opensiteexplorer.org/dotbot)'), new TestSubject, TestSubjectAdapter::class);
        $obJob->handle(app(AdapterRegistry::class), $obSpy);

        $this->assertSame(0, $obSpy->iCallCount);
        $this->assertSame(0, DB::table('logingrupa_metapixel_event_log')->count());
        $this->assertSame(0, DB::table('logingrupa_metapixel_failed_events')->count());
        $this->assertFalse($bHookFired);
    }

    public function test_browser_user_agent_is_sent(): void
    {
        $obSpy = new SpyMetaClient;

        $obJob = new SendCapiEvent('PageView', $this->makePayload('Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) Safari/604.1'), new TestSubject, TestSubjectAdapter::class);
        $obJob->handle(app(AdapterRegistry::class), $obSpy);

        $this->assertSame(1, $obSpy->iCallCount);
        $this->assertSame(1, DB::table('logingrupa_metapixel_event_log')->count());
    }

    /** @return array<string, mixed> */
    private function makePayload(string $sUserAgent): array
    {
        return ['data' => [[
            'event_id' => 'uuid-crawler-1',
            'event_time' => 1700000000,
            'event_name' => 'PageView',
            'action_source' => 'website',
            'user_data' => ['client_user_agent' => $sUserAgent, 'client_ip_address' => '203.0.113.9'],
            'custom_data' => [],
        ]]];
    }
}
