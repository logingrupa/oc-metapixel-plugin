<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Queue\SendCapiEvent;
use Logingrupa\Metapixel\Tests\Doubles\SpyMetaClient;
use Logingrupa\Metapixel\Tests\Doubles\TestSubject;
use Logingrupa\Metapixel\Tests\Doubles\TestSubjectAdapter;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Logingrupa\Metapixel\Updates\CreateMetapixelEventLogTable;
use Logingrupa\Metapixel\Updates\CreateMetapixelFailedEventsTable;

final class SendCapiEventHaltTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
        (new CreateMetapixelEventLogTable)->up();
        (new CreateMetapixelFailedEventsTable)->up();
        app(AdapterRegistry::class)->register(TestSubject::class, TestSubjectAdapter::class);
    }

    protected function tearDown(): void
    {
        (new CreateMetapixelEventLogTable)->down();
        (new CreateMetapixelFailedEventsTable)->down();
        Event::forget(SendCapiEvent::HOOK_BEFORE_DISPATCH);
        Event::forget(SendCapiEvent::HOOK_AFTER_DISPATCH);
        Event::forget(SendCapiEvent::HOOK_DEAD_LETTER);
        app()->forgetInstance(AdapterRegistry::class);
        parent::tearDown();
    }

    public function test_halt_listener_skips_race_fence_and_http_call(): void
    {
        Event::listen(SendCapiEvent::HOOK_BEFORE_DISPATCH, fn () => false);

        $obSpyClient = new SpyMetaClient;
        $obJob = new SendCapiEvent('Purchase', $this->makePayload(), new TestSubject, TestSubjectAdapter::class);
        $obJob->handle(app(AdapterRegistry::class), $obSpyClient);

        $this->assertSame(0, $obSpyClient->iCallCount, 'no HTTP call when halted');
        $this->assertSame(0, DB::table('logingrupa_metapixel_event_log')->count(), 'no race-fence write when halted');
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
