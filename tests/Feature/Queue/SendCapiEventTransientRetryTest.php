<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Exception\MetaApiTransientException;
use Logingrupa\Metapixel\Classes\Meta\MetaClient;
use Logingrupa\Metapixel\Classes\Queue\SendCapiEvent;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\Doubles\TestSubject;
use Logingrupa\Metapixel\Tests\Doubles\TestSubjectAdapter;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Logingrupa\Metapixel\Updates\AddPayloadToMetapixelEventLogTable;
use Logingrupa\Metapixel\Updates\CreateMetapixelEventLogTable;
use Logingrupa\Metapixel\Updates\CreateMetapixelFailedEventsTable;

final class SendCapiEventTransientRetryTest extends MetapixelTestCase
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
        Event::forget(SendCapiEvent::HOOK_AFTER_DISPATCH);
        Event::forget(SendCapiEvent::HOOK_DEAD_LETTER);
        app()->forgetInstance(AdapterRegistry::class);
        parent::tearDown();
    }

    public function test_transient_failure_rethrows_for_laravel_queue_retry(): void
    {
        $obMock = new MockHandler([
            new Response(503, [], json_encode(['error' => ['message' => 'Service Unavailable']]) ?: ''),
        ]);
        $obClient = new Client(['handler' => HandlerStack::create($obMock)]);

        $this->expectException(MetaApiTransientException::class);

        $obJob = new SendCapiEvent('Purchase', $this->makePayload(), new TestSubject, TestSubjectAdapter::class);
        $obJob->handle(app(AdapterRegistry::class), new MetaClient($obClient));
    }

    public function test_retry_after_transient_failure_proceeds_past_fence_and_delivers(): void
    {
        // Non-null site_id so the UNIQUE fence actually engages (NULL site_id
        // rows never collide under SQL NULL-inequality semantics).
        $this->app->instance(TestSubjectAdapter::class, new TestSubjectAdapter(1));

        $obMock = new MockHandler([
            new Response(503, [], json_encode(['error' => ['message' => 'Service Unavailable']]) ?: ''),
            new Response(200, [], json_encode(['events_received' => 1, 'fbtrace_id' => 'trace-retry']) ?: ''),
        ]);
        $obClient = new Client(['handler' => HandlerStack::create($obMock)]);
        $obJob = new SendCapiEvent('Purchase', $this->makePayload(), new TestSubject, TestSubjectAdapter::class);

        // Attempt 1: fence row is written, Meta 503s, job rethrows so Laravel
        // schedules a retry.
        try {
            $obJob->handle(app(AdapterRegistry::class), new MetaClient($obClient));
            $this->fail('attempt 1 must rethrow MetaApiTransientException');
        } catch (MetaApiTransientException $obException) {
            // expected — Laravel would now retry per $tries/$backoff
        }

        $bAfterDispatchFired = false;
        Event::listen(SendCapiEvent::HOOK_AFTER_DISPATCH, function () use (&$bAfterDispatchFired): void {
            $bAfterDispatchFired = true;
        });

        // Attempt 2: fence collides with attempt 1's OWN row (same event_id) —
        // must be treated as a retry of self, not a duplicate peer.
        $obJob->handle(app(AdapterRegistry::class), new MetaClient($obClient));

        $this->assertTrue($bAfterDispatchFired, 'retry attempt must proceed past the fence and deliver to Meta');
        $this->assertSame(0, $obMock->count(), 'both mocked HTTP responses consumed — the retry actually sent');
        $this->assertSame(0, DB::table('logingrupa_metapixel_failed_events')->count());
    }

    public function test_duplicate_peer_row_with_different_event_id_stays_fenced(): void
    {
        // Non-null site_id so the UNIQUE fence actually engages (NULL site_id
        // rows never collide under SQL NULL-inequality semantics).
        $this->app->instance(TestSubjectAdapter::class, new TestSubjectAdapter(1));

        // Peer job already delivered this fence tuple under a DIFFERENT event_id.
        $arPeerPayload = $this->makePayload();
        $arPeerPayload['data'][0]['event_id'] = 'uuid-PEER';
        $obPeerMock = new MockHandler([
            new Response(200, [], json_encode(['events_received' => 1]) ?: ''),
        ]);
        $obPeerJob = new SendCapiEvent('Purchase', $arPeerPayload, new TestSubject, TestSubjectAdapter::class);
        $obPeerJob->handle(app(AdapterRegistry::class), new MetaClient(new Client(['handler' => HandlerStack::create($obPeerMock)])));

        // This job (event_id uuid-1) loses the fence to the peer — no HTTP call.
        $obMock = new MockHandler([
            new Response(200, [], json_encode(['events_received' => 1]) ?: ''),
        ]);
        $obJob = new SendCapiEvent('Purchase', $this->makePayload(), new TestSubject, TestSubjectAdapter::class);
        $obJob->handle(app(AdapterRegistry::class), new MetaClient(new Client(['handler' => HandlerStack::create($obMock)])));

        $this->assertSame(1, $obMock->count(), 'fenced duplicate peer must not reach Meta');
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
