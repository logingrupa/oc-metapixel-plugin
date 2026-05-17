<?php

use Illuminate\Support\Facades\Event;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Meta\MetaClient;
use Logingrupa\Metapixel\Classes\Queue\SendCapiEvent;
use Logingrupa\Metapixel\Tests\Doubles\FakeStubAdapter;
use Logingrupa\Metapixel\Tests\Doubles\SpyMetaClient;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;

final class BeforeDispatchHaltTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
        app(AdapterRegistry::class)->register(stdClass::class, FakeStubAdapter::class);
        $this->app->instance(MetaClient::class, new SpyMetaClient);
    }

    protected function tearDown(): void
    {
        Event::forget(SendCapiEvent::HOOK_BEFORE_DISPATCH);
        Event::forget(SendCapiEvent::HOOK_AFTER_DISPATCH);
        Event::forget(SendCapiEvent::HOOK_DEAD_LETTER);
        app()->forgetInstance(AdapterRegistry::class);
        app()->forgetInstance(MetaClient::class);
        parent::tearDown();
    }

    public function test_listener_returning_false_halts_dispatch_no_http_call(): void
    {
        Event::listen(SendCapiEvent::HOOK_BEFORE_DISPATCH, fn () => false);

        $obSpyClient = new SpyMetaClient;
        $obJob = new SendCapiEvent('Purchase', $this->makePayload(), new stdClass, FakeStubAdapter::class);
        $obJob->handle(app(AdapterRegistry::class), $obSpyClient);

        $this->assertSame(0, $obSpyClient->iCallCount, 'no HTTP call when before_dispatch halts');
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
