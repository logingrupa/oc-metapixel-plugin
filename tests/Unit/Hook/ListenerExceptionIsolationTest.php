<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Meta\MetaClient;
use Logingrupa\Metapixel\Classes\Queue\SendCapiEvent;
use Logingrupa\Metapixel\Tests\Doubles\FakeStubAdapter;
use Logingrupa\Metapixel\Tests\Doubles\SpyMetaClient;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Logingrupa\Metapixel\Updates\CreateMetapixelEventLogTable;

final class ListenerExceptionIsolationTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
        (new CreateMetapixelEventLogTable)->up();
        app(AdapterRegistry::class)->register(stdClass::class, FakeStubAdapter::class);
        $this->app->instance(MetaClient::class, new SpyMetaClient);
    }

    protected function tearDown(): void
    {
        (new CreateMetapixelEventLogTable)->down();
        Event::forget(SendCapiEvent::HOOK_BEFORE_DISPATCH);
        Event::forget(SendCapiEvent::HOOK_AFTER_DISPATCH);
        Event::forget(SendCapiEvent::HOOK_DEAD_LETTER);
        app()->forgetInstance(AdapterRegistry::class);
        app()->forgetInstance(MetaClient::class);
        parent::tearDown();
    }

    public function test_throwing_listener_does_not_halt_dispatch_logs_warning(): void
    {
        Event::listen(SendCapiEvent::HOOK_BEFORE_DISPATCH, function (): void {
            throw new RuntimeException('listener boom');
        });

        Log::shouldReceive('warning')->atLeast()->once();
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('critical')->zeroOrMoreTimes();

        $obSpyClient = new SpyMetaClient;
        $obJob = new SendCapiEvent('Purchase', $this->makePayload(), new stdClass, FakeStubAdapter::class);
        $obJob->handle(app(AdapterRegistry::class), $obSpyClient);

        $this->assertSame(1, $obSpyClient->iCallCount, 'dispatch continues despite listener exception');
    }

    /** @return array<string, mixed> */
    private function makePayload(): array
    {
        return ['data' => [[
            'event_id' => 'uuid-1',
            'event_time' => 1700000000,
            'event_name' => 'Purchase',
            'action_source' => 'website',
            'user_data' => [],
            'custom_data' => [],
        ]]];
    }
}
