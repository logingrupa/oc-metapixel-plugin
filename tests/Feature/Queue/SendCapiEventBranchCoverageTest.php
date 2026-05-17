<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Meta\MetaClient;
use Logingrupa\Metapixel\Classes\Queue\SendCapiEvent;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\Doubles\SpyMetaClient;
use Logingrupa\Metapixel\Tests\Doubles\TestSubject;
use Logingrupa\Metapixel\Tests\Doubles\TestSubjectAdapter;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Logingrupa\Metapixel\Updates\CreateMetapixelEventLogTable;
use Logingrupa\Metapixel\Updates\CreateMetapixelFailedEventsTable;

final class SendCapiEventBranchCoverageTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
        (new CreateMetapixelEventLogTable)->up();
        (new CreateMetapixelFailedEventsTable)->up();
        Settings::clearInternalCache();
        Settings::set(['pixel_id' => 'PIXEL-42', 'capi_access_token' => 'TOKEN-XYZ']);
        // Non-null site_id forces the UNIQUE race-fence constraint to actually fire
        // (SQLite + MySQL InnoDB treat multiple NULL values in a UNIQUE column as
        // DISTINCT — see plan 02-04 EventLogWriterRaceFenceTest comments).
        $this->app->bind(TestSubjectAdapter::class, fn () => new TestSubjectAdapter(1));
        app(AdapterRegistry::class)->register(TestSubject::class, TestSubjectAdapter::class);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('logingrupa_metapixel_event_log');
        Schema::dropIfExists('logingrupa_metapixel_failed_events');
        Event::forget(SendCapiEvent::HOOK_BEFORE_DISPATCH);
        Event::forget(SendCapiEvent::HOOK_AFTER_DISPATCH);
        Event::forget(SendCapiEvent::HOOK_DEAD_LETTER);
        app()->forgetInstance(AdapterRegistry::class);
        parent::tearDown();
    }

    public function test_race_fence_loser_bails_no_http_call(): void
    {
        // Pre-seed the race-fence row so the second insert collides.
        $obSpyClient = new SpyMetaClient;
        $obJob1 = new SendCapiEvent('Purchase', $this->makePayload(), new TestSubject, TestSubjectAdapter::class);
        $obJob1->handle(app(AdapterRegistry::class), $obSpyClient);
        $this->assertSame(1, $obSpyClient->iCallCount, 'first insert wins, HTTP called once');

        $obJob2 = new SendCapiEvent('Purchase', $this->makePayloadAlt(), new TestSubject, TestSubjectAdapter::class);
        $obJob2->handle(app(AdapterRegistry::class), $obSpyClient);
        $this->assertSame(1, $obSpyClient->iCallCount, 'second insert collides, no HTTP call');

        $this->assertSame(1, DB::table('logingrupa_metapixel_event_log')->count());
    }

    public function test_after_dispatch_listener_exception_is_swallowed(): void
    {
        Event::listen(SendCapiEvent::HOOK_AFTER_DISPATCH, function (): void {
            throw new RuntimeException('after_dispatch boom');
        });

        Log::shouldReceive('warning')->atLeast()->once();
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('critical')->zeroOrMoreTimes();

        $obMock = new MockHandler([
            new Response(200, [], json_encode(['events_received' => 1]) ?: ''),
        ]);
        $obClient = new Client(['handler' => HandlerStack::create($obMock)]);

        $obJob = new SendCapiEvent('Purchase', $this->makePayload(), new TestSubject, TestSubjectAdapter::class);
        $obJob->handle(app(AdapterRegistry::class), new MetaClient($obClient));

        // dispatch completed despite listener throw — race-fence row exists, no FailedEvent
        $this->assertSame(1, DB::table('logingrupa_metapixel_event_log')->count());
        $this->assertSame(0, DB::table('logingrupa_metapixel_failed_events')->count());
    }

    public function test_dead_letter_listener_exception_is_swallowed(): void
    {
        Event::listen(SendCapiEvent::HOOK_DEAD_LETTER, function (): void {
            throw new RuntimeException('dead_letter boom');
        });

        Log::shouldReceive('warning')->atLeast()->once();
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('critical')->zeroOrMoreTimes();

        $obMock = new MockHandler([
            new Response(400, [], json_encode(['error' => ['message' => 'bad']]) ?: ''),
        ]);
        $obClient = new Client(['handler' => HandlerStack::create($obMock)]);

        $obJob = new SendCapiEvent('Purchase', $this->makePayload(), new TestSubject, TestSubjectAdapter::class);
        $obJob->handle(app(AdapterRegistry::class), new MetaClient($obClient));

        // FailedEvent written despite dead_letter listener throwing.
        $this->assertSame(1, DB::table('logingrupa_metapixel_failed_events')->count());
    }

    public function test_write_failed_event_db_failure_is_swallowed(): void
    {
        // Drop the failed_events table so FailedEvent::create raises a QueryException.
        Schema::dropIfExists('logingrupa_metapixel_failed_events');

        Log::shouldReceive('warning')->atLeast()->once();
        Log::shouldReceive('critical')->zeroOrMoreTimes();
        Log::shouldReceive('info')->zeroOrMoreTimes();

        $obMock = new MockHandler([
            new Response(400, [], json_encode(['error' => ['message' => 'bad']]) ?: ''),
        ]);
        $obClient = new Client(['handler' => HandlerStack::create($obMock)]);

        $obJob = new SendCapiEvent('Purchase', $this->makePayload(), new TestSubject, TestSubjectAdapter::class);
        // The handle path should not throw despite the DB write failure (fail-safe peer-wins).
        $obJob->handle(app(AdapterRegistry::class), new MetaClient($obClient));

        $this->assertTrue(true, 'handle returned without throwing after writeFailedEvent DB failure');
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

    /** @return array<string, mixed> */
    private function makePayloadAlt(): array
    {
        return ['data' => [[
            'event_id' => 'uuid-2',
            'event_time' => 1700000001,
            'event_name' => 'Purchase',
            'action_source' => 'website',
            'user_data' => [],
            'custom_data' => [],
        ]]];
    }
}
