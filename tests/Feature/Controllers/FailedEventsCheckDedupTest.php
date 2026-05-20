<?php

use Illuminate\Http\Request;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Exception\MetaApiPermanentException;
use Logingrupa\Metapixel\Classes\Meta\MetaClient;
use Logingrupa\Metapixel\Controllers\FailedEvents;
use Logingrupa\Metapixel\Models\FailedEvent;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Logingrupa\Metapixel\Updates\AddDedupColumnsToFailedEvents;
use Logingrupa\Metapixel\Updates\CreateMetapixelFailedEventsTable;

/**
 * Wave 0 RED — fails until plan 04-04 production code ships.
 *
 * FAIL-03 — Controllers\FailedEvents::onCheckDedup calls
 * MetaClient::fetchTestEventsStatus, parses the (Meta Graph) JSON response
 * tolerantly (?? null on every field read), and writes 3 inline columns
 * (dedup_pct, emq, dedup_checked_at) onto the FailedEvent row. Returns the
 * 3 values + the list-refresh partial for live JSON refresh. Tolerates
 * missing fields without throwing (Pattern 10 lock).
 */
final class FailedEventsCheckDedupTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
        (new CreateMetapixelFailedEventsTable)->up();
        (new AddDedupColumnsToFailedEvents)->up();

        Settings::clearInternalCache();
        Settings::set([
            'pixel_id' => 'PIXEL-DEDUP',
            'capi_access_token' => 'TOKEN-DEDUP',
        ]);

        // WR-06 — bind the Flash facade root via the container 'flash' binding
        // instead of Mockery's alias: pattern. See FailedEventsReplayTest setUp
        // for the full rationale (alias-mock side effects survive the process).
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

    private function seedRow(string $sEventName = 'Purchase'): FailedEvent
    {
        $obRow = new FailedEvent;
        $obRow->event_id = 'event-dedup-1';
        $obRow->event_name = $sEventName;
        $obRow->adapter_type = 'whatever';
        $obRow->payload = ['data' => [['event_name' => $sEventName]]];
        $obRow->http_status = 400;
        $obRow->attempts = 1;
        $obRow->save();

        return $obRow;
    }

    private function makeController(array $arDedupResponse, bool $bThrow = false, ?Throwable $obException = null): TestableFailedEventsForDedup
    {
        $obFakeClient = new class($arDedupResponse, $bThrow, $obException) extends MetaClient
        {
            public function __construct(
                private array $arResponse,
                private bool $bThrow,
                private ?Throwable $obException,
            ) {
                parent::__construct(null);
            }

            public function fetchTestEventsStatus(string $sPixelId, string $sToken, string $sTestEventCode = '', string $sEventId = ''): array
            {
                if ($this->bThrow && $this->obException !== null) {
                    throw $this->obException;
                }

                return $this->arResponse;
            }
        };
        $this->app->instance(MetaClient::class, $obFakeClient);

        return new TestableFailedEventsForDedup;
    }

    private function bindRequestWithRecordId(int $iId): void
    {
        $this->app->bind('request', fn () => Request::create('/', 'POST', ['record_id' => $iId]));
    }

    public function test_on_check_dedup_writes_dedup_columns_from_meta_response(): void
    {
        $obRow = $this->seedRow('Purchase');
        $this->bindRequestWithRecordId((int) $obRow->id);

        $obController = $this->makeController([
            'event_match_quality' => ['Purchase' => 8.4],
            'deduplication_rate' => ['Purchase' => 0.83],
            'raw' => ['id' => 'PIXEL-DEDUP'],
        ]);

        $mResponse = $obController->onCheckDedup();
        $this->assertIsArray($mResponse);
        $this->assertArrayHasKey('#failedEventList', $mResponse);

        $obFresh = FailedEvent::find($obRow->id);
        $this->assertEqualsWithDelta(83.0, (float) $obFresh->dedup_pct, 0.01);
        $this->assertEqualsWithDelta(8.4, (float) $obFresh->emq, 0.01);
        $this->assertNotNull($obFresh->dedup_checked_at);
    }

    public function test_on_check_dedup_tolerates_missing_event_match_quality(): void
    {
        $obRow = $this->seedRow('Purchase');
        $this->bindRequestWithRecordId((int) $obRow->id);

        $obController = $this->makeController([
            'event_match_quality' => null,
            'deduplication_rate' => ['Purchase' => 0.5],
            'raw' => [],
        ]);

        $obController->onCheckDedup();

        $obFresh = FailedEvent::find($obRow->id);
        $this->assertEqualsWithDelta(50.0, (float) $obFresh->dedup_pct, 0.01);
        $this->assertNull($obFresh->emq);
        $this->assertNotNull($obFresh->dedup_checked_at);
    }

    public function test_on_check_dedup_tolerates_completely_empty_response(): void
    {
        $obRow = $this->seedRow('Purchase');
        $this->bindRequestWithRecordId((int) $obRow->id);

        $obController = $this->makeController([
            'event_match_quality' => null,
            'deduplication_rate' => null,
            'raw' => [],
        ]);

        $obController->onCheckDedup();

        $obFresh = FailedEvent::find($obRow->id);
        $this->assertNull($obFresh->dedup_pct);
        $this->assertNull($obFresh->emq);
        $this->assertNotNull($obFresh->dedup_checked_at, 'dedup_checked_at always updated to mark a check ran');
    }

    public function test_on_check_dedup_returns_json_response_for_live_refresh(): void
    {
        $obRow = $this->seedRow('Purchase');
        $this->bindRequestWithRecordId((int) $obRow->id);

        $obController = $this->makeController([
            'event_match_quality' => ['Purchase' => 9.1],
            'deduplication_rate' => ['Purchase' => 0.91],
            'raw' => [],
        ]);

        $mResponse = $obController->onCheckDedup();
        $this->assertIsArray($mResponse);
        $this->assertArrayHasKey('dedup_pct', $mResponse);
        $this->assertArrayHasKey('emq', $mResponse);
        $this->assertArrayHasKey('checked_at', $mResponse);
    }

    public function test_on_check_dedup_metapixel_exception_flashes_error_no_column_write(): void
    {
        $obRow = $this->seedRow('Purchase');
        // pre-set the row's dedup values; failure must NOT overwrite.
        $obRow->dedup_pct = 50.0;
        $obRow->emq = 4.4;
        $obRow->save();
        $this->bindRequestWithRecordId((int) $obRow->id);

        $obController = $this->makeController(
            [],
            true,
            new MetaApiPermanentException('metapixel: graph quality 400', 400),
        );

        $obController->onCheckDedup();

        $obFresh = FailedEvent::find($obRow->id);
        $this->assertEqualsWithDelta(50.0, (float) $obFresh->dedup_pct, 0.01, 'dedup_pct must NOT be overwritten on permanent failure');
        $this->assertEqualsWithDelta(4.4, (float) $obFresh->emq, 0.01, 'emq must NOT be overwritten on permanent failure');
    }
}

/**
 * Same test harness rationale as FailedEventsReplayTest.
 */
final class TestableFailedEventsForDedup extends FailedEvents
{
    public function __construct() {}

    protected function listRefresh(): string
    {
        return '<list-stub />';
    }
}
