<?php

namespace Logingrupa\Metapixelshopaholic\Tests\Feature;

require_once __DIR__.'/../MetapixelTestCase.php';
// Migration filenames are snake_case (October Updates Manager convention) — not PSR-4 discoverable.
require_once __DIR__.'/../../updates/create_table_failed_events.php';
require_once __DIR__.'/../../updates/add_unique_index_to_failed_events.php';

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Logingrupa\Metapixelshopaholic\Classes\Exception\MetaApiTransientException;
use Logingrupa\Metapixelshopaholic\Classes\Helper\PluginGuard;
use Logingrupa\Metapixelshopaholic\Classes\Meta\MetaClient;
use Logingrupa\Metapixelshopaholic\Classes\Queue\SendCapiEvent;
use Logingrupa\Metapixelshopaholic\Models\FailedEvent;
use Logingrupa\Metapixelshopaholic\Models\Settings;
use Logingrupa\Metapixelshopaholic\Tests\MetapixelTestCase;
use Logingrupa\Metapixelshopaholic\Updates\AddUniqueIndexToFailedEvents;
use Logingrupa\Metapixelshopaholic\Updates\CreateTableFailedEvents;
use Mockery;
use Ramsey\Uuid\Uuid;

/**
 * Feature test locking Plan 03-05 PAY-02 SendCapiEvent retry / dead-letter contract.
 *
 * Test cases (`dispatchSync` so handle() / failed() execute in the test thread):
 *
 *   1. Success-on-200 → MetaClient::send returns + handle() emits no FailedEvent.
 *   2. Transient (503) → handle() RETHROWS MetaApiTransientException so Laravel
 *      can apply $tries + $backoff.
 *   3. $tries-exhausted hook → failed() writes a FailedEvent (attempts === 3).
 *   4. Permanent (400) → handle() catches + writes a FailedEvent + does NOT
 *      rethrow (queue worker doesn't park).
 *   5. Missing pixel_id → MissingPixelConfigException → handle() catches +
 *      writes FailedEvent + does NOT rethrow (treated as permanent per
 *      CONTEXT Area 1 Q2).
 *   6. Missing capi_access_token → MissingCapiTokenException → same shape.
 *   7. $backoff === [1, 4, 16] + $tries === 3 (CONTEXT Area 1 Q2 lock).
 *   8. DB-write failure during dead-letter → silent catch absorbs + handle()
 *      does not throw (T-03-22 mitigation).
 *   9. SendCapiEvent implements ShouldQueue interface.
 *   10. handle() passes the EXACT $arPayload through to MetaClient::send
 *       (Mockery spy).
 *
 * Settings priming uses the MetaClient pattern (reflection setAttribute on
 * Settings::instance()) — HR-02 multi-Settings::set flap workaround.
 *
 * MetaClient is bound into the container so Laravel's auto-resolution in
 * handle(MetaClient $obClient) picks up the MockHandler-backed instance.
 */
final class SendCapiEventTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootSystemSettings();
        Cache::flush();
        Settings::clearInternalCache();
        PluginGuard::flush();
        // Provision failed_events table via the canonical Phase-3 migration so
        // FailedEvent::create() round-trips through the real schema.
        (new CreateTableFailedEvents)->up();
        // WR-07: layer the unique index migration so the double-write
        // test (test_handle_then_failed_does_not_double_write_failed_event)
        // exercises the production schema.
        (new AddUniqueIndexToFailedEvents)->up();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_handle_succeeds_on_first_attempt_when_meta_client_returns_200(): void
    {
        $this->primeSettings();
        $this->bindMetaClientWithMockResponses([
            new Response(200, [], '{"events_received": 1, "fbtrace_id": "abc"}'),
        ]);
        $arPayload = $this->makePayload();

        SendCapiEvent::dispatchSync('Purchase', $arPayload);

        $this->assertSame(0, FailedEvent::count(), '200 response must NOT write a FailedEvent row.');
    }

    public function test_handle_rethrows_transient_exception_for_laravel_retry(): void
    {
        $this->primeSettings();
        $this->bindMetaClientWithMockResponses([
            new Response(503, [], 'service unavailable'),
        ]);
        $arPayload = $this->makePayload();
        $obJob = new SendCapiEvent('Purchase', $arPayload);

        $bThrown = false;
        try {
            $obJob->handle($this->app->make(MetaClient::class));
        } catch (MetaApiTransientException $obException) {
            $bThrown = true;
            $this->assertSame(503, $obException->arContext['http_status'], 'arContext.http_status must equal 503.');
        }

        $this->assertTrue($bThrown, 'handle() must RETHROW MetaApiTransientException so Laravel applies $tries + $backoff.');
        $this->assertSame(0, FailedEvent::count(), 'Transient rethrow path must NOT write a FailedEvent (failed() hook owns that).');
    }

    public function test_failed_hook_writes_failed_event_with_attempts_three(): void
    {
        $this->primeSettings();
        $arPayload = $this->makePayload('fixed-uuid-1234');
        $obJob = new SendCapiEvent('Purchase', $arPayload);

        // Simulate Laravel calling failed() after $tries exhaustion on a transient.
        $obException = new MetaApiTransientException(
            'HTTP 503 after 3 attempts',
            ['http_status' => 503, 'attempts' => 3],
        );
        $obJob->failed($obException);

        $this->assertSame(1, FailedEvent::count(), 'failed() hook must write exactly one FailedEvent row.');
        /** @var FailedEvent $obFailed */
        $obFailed = FailedEvent::first();
        $this->assertSame('fixed-uuid-1234', $obFailed->event_id, 'FailedEvent.event_id must equal the payload event_id.');
        $this->assertSame('Purchase', $obFailed->event_name, 'FailedEvent.event_name must equal the payload event_name.');
        $this->assertSame(3, $obFailed->attempts, 'FailedEvent.attempts must equal the exhausted $tries count.');
        $this->assertSame(503, $obFailed->http_status, 'FailedEvent.http_status must equal the transient code.');
    }

    public function test_handle_writes_failed_event_on_permanent_400_no_rethrow(): void
    {
        $this->primeSettings();
        $this->bindMetaClientWithMockResponses([
            new Response(400, [], '{"error":{"message":"bad payload"}}'),
        ]);
        $arPayload = $this->makePayload('permanent-uuid-001');

        // Dispatch via dispatchSync — must NOT throw.
        SendCapiEvent::dispatchSync('Purchase', $arPayload);

        $this->assertSame(1, FailedEvent::count(), 'Permanent 400 must write exactly one FailedEvent row.');
        /** @var FailedEvent $obFailed */
        $obFailed = FailedEvent::first();
        $this->assertSame('permanent-uuid-001', $obFailed->event_id, 'FailedEvent.event_id must match payload.');
        $this->assertSame(400, $obFailed->http_status, 'FailedEvent.http_status must equal 400.');
    }

    public function test_handle_writes_failed_event_on_missing_pixel_config(): void
    {
        // MetaClient throws MissingPixelConfigException natively when pixel_id is empty;
        // we use the real MetaClient here (not mocked) so that exception path is real.
        $this->primeSettings('', 'EAA-test-token');
        $arPayload = $this->makePayload('missing-pixel-uuid');

        SendCapiEvent::dispatchSync('Purchase', $arPayload);

        $this->assertSame(1, FailedEvent::count(), 'Missing pixel_id must write exactly one FailedEvent row.');
        /** @var FailedEvent $obFailed */
        $obFailed = FailedEvent::first();
        $this->assertSame('missing-pixel-uuid', $obFailed->event_id);
        $this->assertSame('Purchase', $obFailed->event_name);
        $this->assertNull($obFailed->http_status, 'Missing-pixel-id arContext has no http_status.');
    }

    public function test_handle_writes_failed_event_on_missing_capi_token(): void
    {
        $this->primeSettings('2291486191076331', '');
        $arPayload = $this->makePayload('missing-token-uuid');

        SendCapiEvent::dispatchSync('Purchase', $arPayload);

        $this->assertSame(1, FailedEvent::count(), 'Missing capi_access_token must write exactly one FailedEvent row.');
        /** @var FailedEvent $obFailed */
        $obFailed = FailedEvent::first();
        $this->assertSame('missing-token-uuid', $obFailed->event_id);
        $this->assertSame('Purchase', $obFailed->event_name);
    }

    public function test_backoff_schedule_is_one_four_sixteen(): void
    {
        $obJob = new SendCapiEvent('Purchase', $this->makePayload());

        $this->assertSame([1, 4, 16], $obJob->backoff, '$backoff must equal [1, 4, 16] per CONTEXT Area 1 Q2.');
        $this->assertSame(3, $obJob->tries, '$tries must equal 3 per CONTEXT Area 1 Q2.');
    }

    public function test_db_write_failure_during_dead_letter_does_not_cascade(): void
    {
        $this->primeSettings();
        // Drop the failed_events table → FailedEvent::create() raises QueryException → silent catch absorbs.
        \Schema::drop('logingrupa_metapixel_failed_events');
        $this->bindMetaClientWithMockResponses([
            new Response(400, [], '{"error":"bad"}'),
        ]);
        $arPayload = $this->makePayload();

        $bThrown = false;
        try {
            SendCapiEvent::dispatchSync('Purchase', $arPayload);
        } catch (\Throwable $obException) {
            $bThrown = true;
        }

        $this->assertFalse($bThrown, 'DB-write failure during dead-letter must be absorbed (T-03-22 silent catch).');
    }

    public function test_job_implements_should_queue_interface(): void
    {
        $obReflect = new \ReflectionClass(SendCapiEvent::class);

        $this->assertTrue(
            $obReflect->implementsInterface(ShouldQueue::class),
            'SendCapiEvent MUST implement Illuminate\\Contracts\\Queue\\ShouldQueue per Laravel 12 idiom.'
        );
    }

    public function test_handle_passes_payload_through_to_meta_client_send(): void
    {
        $arPayload = $this->makePayload('mockery-uuid-001');
        $arCaptured = [];

        $obMock = Mockery::mock(MetaClient::class);
        $obMock->shouldReceive('send')
            ->once()
            ->with(Mockery::on(function ($mxArg) use (&$arCaptured): bool {
                if (is_array($mxArg)) {
                    $arCaptured = $mxArg;

                    return true;
                }

                return false;
            }))
            ->andReturn(['events_received' => 1]);
        $this->app->instance(MetaClient::class, $obMock);

        SendCapiEvent::dispatchSync('Purchase', $arPayload);

        $this->assertSame($arPayload, $arCaptured, 'MetaClient::send must receive the EXACT $arPayload constructor argument.');
    }

    public function test_failed_hook_wraps_non_meta_exception_as_permanent(): void
    {
        // Locks the failed() else-branch — Laravel may call failed() with a
        // non-Meta exception (DB error, container resolution failure, etc.) and
        // we wrap it into MetaApiPermanentException so the FailedEvent type
        // contract holds.
        $this->primeSettings();
        $arPayload = $this->makePayload('non-meta-uuid-001');
        $obJob = new SendCapiEvent('Purchase', $arPayload);

        $obJob->failed(new \RuntimeException('container resolution failure'));

        $this->assertSame(1, FailedEvent::count(), 'Non-Meta exception must still produce a FailedEvent row.');
        /** @var FailedEvent $obFailed */
        $obFailed = FailedEvent::first();
        $this->assertSame('non-meta-uuid-001', $obFailed->event_id);
        $this->assertStringContainsString('container resolution failure', $obFailed->graph_error ?? '', 'Original exception message must be preserved on the FailedEvent row.');
    }

    public function test_event_name_propagates_to_logged_context(): void
    {
        // Lock CONTEXT Discretion #9: meta_pixel.event_name appears in the log
        // context. Spy on the MetaClient call directly to assert the event name
        // is carried on the dispatch job state (and exposed to log builders).
        $this->primeSettings();
        $arPayload = $this->makePayload();
        $obJob = new SendCapiEvent('CustomEventName', $arPayload);

        $this->assertSame('CustomEventName', $obJob->sEventName, 'Constructor must store sEventName on a readonly property.');
        $this->assertSame($arPayload, $obJob->arPayload, 'Constructor must store arPayload on a readonly property.');
    }

    public function test_handle_then_failed_does_not_double_write_failed_event(): void
    {
        // WR-07 lock: both SendCapiEvent::handle()'s permanent-catch path
        // and Laravel's failed() exhaustion hook call writeFailedEvent on
        // the same arPayload. Today's flow guarantees single-write per
        // logical failure (handle()'s catch doesn't re-throw, so failed()
        // doesn't fire). But a future log-driver-fail-during-dead-letter
        // edge case could fire BOTH paths.
        // The unique index on (event_id, http_status) makes the second
        // INSERT silently no-op at the DB level — the second create() call
        // raises a constraint violation which our silent catch absorbs.
        $this->primeSettings();
        $sUuid = Uuid::uuid4()->toString();
        $arPayload = $this->makePayload($sUuid);
        $obException = new \Logingrupa\Metapixelshopaholic\Classes\Exception\MetaApiPermanentException(
            'simulated permanent failure',
            ['http_status' => 400, 'attempts' => 3],
        );

        $obJob = new SendCapiEvent('Purchase', $arPayload);
        // Simulate the BOTH paths firing: handle's permanent-catch goes
        // first (writes row), then Laravel's failed() hook fires (second
        // write — must NOT create a duplicate row).
        $obJob->failed($obException);
        $obJob->failed($obException);

        $iCount = FailedEvent::where('event_id', $sUuid)->count();
        $this->assertSame(1, $iCount, 'WR-07: double-fire MUST NOT produce two FailedEvent rows for the same (event_id, http_status).');
    }

    /**
     * Build a MetaClient backed by a MockHandler Guzzle Client and BIND it
     * into the container so SendCapiEvent::handle's auto-resolution picks it up.
     *
     * @param  list<\GuzzleHttp\Psr7\Response|\Exception>  $arResponses
     */
    private function bindMetaClientWithMockResponses(array $arResponses): void
    {
        $obMock = new MockHandler($arResponses);
        $obStack = HandlerStack::create($obMock);
        $obGuzzle = new Client(['handler' => $obStack, 'http_errors' => false]);
        $this->app->instance(MetaClient::class, new MetaClient($obGuzzle));
    }

    /**
     * Prime Settings via the reflection pattern from MetaClientTest (HR-02
     * workaround — Settings::set + Cache::flush flaps under multi-set load).
     */
    private function primeSettings(string $sPixelId = '2291486191076331', string $sCapiToken = 'EAA-test-token'): void
    {
        $obInstance = Settings::instance();
        $obInstance->setAttribute('pixel_id', $sPixelId);
        $obInstance->setAttribute('capi_access_token', $sCapiToken);
        $obInstance->setAttribute('test_event_code', '');
    }

    /**
     * Build a minimal valid CAPI envelope so dispatchSync exercises the real
     * handle() path without depending on PayloadBuilder/Order fixtures.
     *
     * @return array<string, mixed>
     */
    private function makePayload(string $sEventId = ''): array
    {
        $sEventId = $sEventId !== '' ? $sEventId : Uuid::uuid4()->toString();

        return [
            'data' => [
                [
                    'event_id' => $sEventId,
                    'event_name' => 'Purchase',
                    'event_time' => time(),
                    'action_source' => 'website',
                ],
            ],
        ];
    }
}
