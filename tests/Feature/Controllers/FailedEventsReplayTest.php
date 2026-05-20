<?php

use Illuminate\Http\Request;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Exception\MetaApiPermanentException;
use Logingrupa\Metapixel\Classes\Meta\MetaClient;
use Logingrupa\Metapixel\Controllers\FailedEvents;
use Logingrupa\Metapixel\Models\FailedEvent;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\Doubles\FakeAdapter;
use Logingrupa\Metapixel\Tests\Doubles\SpyMetaClient;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Logingrupa\Metapixel\Updates\AddDedupColumnsToFailedEvents;
use Logingrupa\Metapixel\Updates\CreateMetapixelFailedEventsTable;

/**
 * Wave 0 RED — fails until plan 04-04 production code ships.
 *
 * FAIL-02 — Controllers\FailedEvents::onReplay re-fires the persisted payload
 * through MetaClient::sendForPixel synchronously. Pattern 9 lock — on success
 * attempts++ / graph_error null / http_status 200; on MetaPixelException
 * attempts++ / graph_error set / Flash::error; uses Settings::lookupForSite(null)
 * (D-01 Option A — no site_id column on FailedEvent in v2.0). findOrFail
 * validates record_id (Pitfall 10 — no Validation trait on the model).
 */
final class FailedEventsReplayTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
        (new CreateMetapixelFailedEventsTable)->up();
        (new AddDedupColumnsToFailedEvents)->up();

        Settings::clearInternalCache();
        Settings::set([
            'pixel_id' => 'PIXEL-REPLAY',
            'capi_access_token' => 'TOKEN-REPLAY',
        ]);

        app(AdapterRegistry::class)->register(stdClass::class, FakeAdapter::class);

        // WR-06 — bind the Flash facade root via the container 'flash' binding
        // instead of Mockery's alias: pattern. Alias mocks register a class
        // alias that survives the test process; this swap is per-test and
        // tears down cleanly with Mockery::close() in tearDown().
        $obFlash = Mockery::mock();
        $obFlash->shouldReceive('error')->andReturnNull();
        $obFlash->shouldReceive('success')->andReturnNull();
        $obFlash->shouldReceive('warning')->andReturnNull();
        $this->app->instance('flash', $obFlash);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        (new AddDedupColumnsToFailedEvents)->down();
        (new CreateMetapixelFailedEventsTable)->down();
        app()->forgetInstance(AdapterRegistry::class);
        parent::tearDown();
    }

    private function seedRow(string $sAdapterType = FakeAdapter::class): FailedEvent
    {
        $obRow = new FailedEvent;
        $obRow->event_id = 'event-uuid-1';
        $obRow->event_name = 'Purchase';
        $obRow->adapter_type = $sAdapterType;
        $obRow->subject_type = 'fake.subject';
        $obRow->subject_id = 42;
        $obRow->payload = ['data' => [['event_id' => 'event-uuid-1', 'event_name' => 'Purchase']]];
        $obRow->http_status = 400;
        $obRow->graph_error = 'previous error';
        $obRow->attempts = 1;
        $obRow->save();

        return $obRow;
    }

    private function makeController(): TestableFailedEventsForReplay
    {
        return new TestableFailedEventsForReplay;
    }

    private function bindRequestWithRecordId(int $iId): void
    {
        $this->app->bind('request', fn () => Request::create('/', 'POST', ['record_id' => $iId]));
    }

    public function test_on_replay_success_increments_attempts_and_clears_graph_error(): void
    {
        $obRow = $this->seedRow();
        $this->bindRequestWithRecordId((int) $obRow->id);

        $obSpy = new SpyMetaClient;
        $this->app->instance(MetaClient::class, $obSpy);

        $obController = $this->makeController();
        $mResponse = $obController->onReplay();

        $this->assertIsArray($mResponse);
        $this->assertArrayHasKey('#failedEventList', $mResponse);
        $this->assertSame(1, $obSpy->iCallCount, 'sendForPixel must be called exactly once');
        $this->assertSame('PIXEL-REPLAY', $obSpy->sLastPixelId);

        $obFresh = FailedEvent::find($obRow->id);
        $this->assertSame(2, (int) $obFresh->attempts);
        $this->assertNull($obFresh->graph_error);
        // CR-02 — success clears the stale http_status from the prior failure;
        // sendForPixel returns the decoded body (NOT the HTTP code) so the
        // honest audit signal is "no failure on the latest attempt".
        $this->assertNull($obFresh->http_status);
    }

    public function test_on_replay_metapixel_exception_writes_graph_error(): void
    {
        $obRow = $this->seedRow();
        $this->bindRequestWithRecordId((int) $obRow->id);

        $obFakeClient = new class extends MetaClient
        {
            public function __construct()
            {
                parent::__construct(null);
            }

            public function sendForPixel(string $sPixelId, string $sToken, array $arPayload): array
            {
                throw new MetaApiPermanentException('metapixel: Invalid pixel_id', 400);
            }
        };
        $this->app->instance(MetaClient::class, $obFakeClient);

        $obController = $this->makeController();
        $obController->onReplay();

        $obFresh = FailedEvent::find($obRow->id);
        $this->assertSame(2, (int) $obFresh->attempts);
        $this->assertStringContainsString('Invalid pixel_id', (string) $obFresh->graph_error);
        // CR-02 — http_status now reflects the actual upstream code from THIS
        // attempt via MetaApiPermanentException::getHttpStatus() (not the
        // stale value from the original failure that seeded the row).
        $this->assertSame(400, (int) $obFresh->http_status);
    }

    public function test_on_replay_throwable_writes_graph_error_with_throwable_message(): void
    {
        $obRow = $this->seedRow();
        $this->bindRequestWithRecordId((int) $obRow->id);

        $obFakeClient = new class extends MetaClient
        {
            public function __construct()
            {
                parent::__construct(null);
            }

            public function sendForPixel(string $sPixelId, string $sToken, array $arPayload): array
            {
                throw new RuntimeException('boom');
            }
        };
        $this->app->instance(MetaClient::class, $obFakeClient);

        $obController = $this->makeController();
        $obController->onReplay();

        $obFresh = FailedEvent::find($obRow->id);
        $this->assertSame(2, (int) $obFresh->attempts);
        $this->assertStringContainsString('boom', (string) $obFresh->graph_error);
        // CR-02 — non-HTTP failure (timeout, parser, ...) has no upstream
        // status code; clear the stale value so the audit column does not lie.
        $this->assertNull($obFresh->http_status);
    }

    public function test_on_replay_unregistered_adapter_type_flashes_error_no_dispatch(): void
    {
        $obRow = $this->seedRow('Nonexistent\\Adapter\\Class');
        $this->bindRequestWithRecordId((int) $obRow->id);

        $obSpy = new SpyMetaClient;
        $this->app->instance(MetaClient::class, $obSpy);

        $obController = $this->makeController();
        $mResponse = $obController->onReplay();

        $this->assertIsArray($mResponse);
        $this->assertSame(0, $obSpy->iCallCount, 'sendForPixel MUST NOT be called when adapter unresolvable');
    }

    public function test_on_replay_record_id_zero_or_missing_rejects(): void
    {
        $this->bindRequestWithRecordId(0);

        $obSpy = new SpyMetaClient;
        $this->app->instance(MetaClient::class, $obSpy);

        $obController = $this->makeController();

        // WR-04 — findRowOrFail soft-finds and flashes on stale-page-load
        // scenarios rather than letting Eloquent's ModelNotFoundException
        // bubble as a backend AJAX 500. RuntimeException short-circuits the
        // handler before any Meta API dispatch.
        $this->expectException(\RuntimeException::class);
        $obController->onReplay();
        $this->assertSame(0, $obSpy->iCallCount);
    }

    public function test_on_replay_uses_default_credentials_via_lookup_for_site_null(): void
    {
        $obRow = $this->seedRow();
        $this->bindRequestWithRecordId((int) $obRow->id);

        $obSpy = new SpyMetaClient;
        $this->app->instance(MetaClient::class, $obSpy);

        $obController = $this->makeController();
        $obController->onReplay();

        // D-01 Option A — site_id is null → Settings::lookupForSite(null) returns
        // the default-row credentials (PIXEL-REPLAY / TOKEN-REPLAY seeded in setUp).
        $this->assertSame('PIXEL-REPLAY', $obSpy->sLastPixelId);
        $this->assertSame('TOKEN-REPLAY', $obSpy->sLastToken);
    }

    public function test_on_replay_returns_failed_event_list_refresh_partial(): void
    {
        $obRow = $this->seedRow();
        $this->bindRequestWithRecordId((int) $obRow->id);

        $obSpy = new SpyMetaClient;
        $this->app->instance(MetaClient::class, $obSpy);

        $obController = $this->makeController();
        $mResponse = $obController->onReplay();

        $this->assertIsArray($mResponse);
        $this->assertArrayHasKey('#failedEventList', $mResponse);
        $this->assertIsString($mResponse['#failedEventList']);
    }
}

/**
 * Test harness — bypasses the heavy backend Controller boot (Skin / Auth /
 * SiteSwitcher widget) but reuses every method body verbatim from the
 * production class. listRefresh() is overridden to return a static string so
 * we never enter makePartial('list') which would require a fully booted
 * ListController behavior + the backend layout pipeline.
 */
final class TestableFailedEventsForReplay extends FailedEvents
{
    public function __construct() {}

    protected function listRefresh(): string
    {
        return '<list-stub />';
    }
}
