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
 * Batch toolbar handlers — onReplayBatch / onCheckDedupBatch / onDeleteBatch.
 * Wire: data-request="onReplayBatch" + checked[] POST. Per-row replay /
 * dedup-check / delete iterates postCheckedIds() narrowed list<int> with a
 * findRow soft-find so a stale id mid-batch skips silently instead of 500.
 */
final class FailedEventsBatchTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
        (new CreateMetapixelFailedEventsTable)->up();
        (new AddDedupColumnsToFailedEvents)->up();

        Settings::clearInternalCache();
        Settings::set([
            'pixel_id' => 'PIXEL-BATCH',
            'capi_access_token' => 'TOKEN-BATCH',
        ]);

        app(AdapterRegistry::class)->register(stdClass::class, FakeAdapter::class);

        // WR-06 — bind the Flash facade root via container 'flash' instead of
        // Mockery alias: pattern. Per-test swap that tears down with Mockery::close().
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

    private function seedRow(string $sEventId, string $sAdapterType = FakeAdapter::class): FailedEvent
    {
        $obRow = new FailedEvent;
        $obRow->event_id = $sEventId;
        $obRow->event_name = 'Purchase';
        $obRow->adapter_type = $sAdapterType;
        $obRow->subject_type = 'fake.subject';
        $obRow->subject_id = 42;
        $obRow->payload = ['data' => [['event_id' => $sEventId, 'event_name' => 'Purchase']]];
        $obRow->http_status = 400;
        $obRow->graph_error = 'previous error';
        $obRow->attempts = 1;
        $obRow->save();

        return $obRow;
    }

    /**
     * Bind a Request carrying post('checked') = $arIds (list<int>) — matches the
     * `checked: $('.control-list').listWidget('getChecked')` toolbar wire.
     *
     * @param  list<int|string>  $arIds
     */
    private function bindRequestWithCheckedIds(array $arIds): void
    {
        $this->app->bind('request', fn () => Request::create('/', 'POST', ['checked' => $arIds]));
    }

    // -----------------------------------------------------------------------
    // onReplayBatch
    // -----------------------------------------------------------------------

    public function test_on_replay_batch_replays_every_checked_row(): void
    {
        $obRow1 = $this->seedRow('event-batch-1');
        $obRow2 = $this->seedRow('event-batch-2');
        $obRow3 = $this->seedRow('event-batch-3');
        $this->bindRequestWithCheckedIds([(int) $obRow1->id, (int) $obRow2->id, (int) $obRow3->id]);

        $obSpy = new SpyMetaClient;
        $this->app->instance(MetaClient::class, $obSpy);

        $obController = new TestableFailedEventsForBatch;
        $mResponse = $obController->onReplayBatch();

        $this->assertIsArray($mResponse);
        $this->assertArrayHasKey('#failedEventList', $mResponse);
        $this->assertSame(3, $obSpy->iCallCount, 'sendForPixel must fire once per checked row');

        // Each row attempts++ on success.
        $this->assertSame(2, (int) FailedEvent::find($obRow1->id)->attempts);
        $this->assertSame(2, (int) FailedEvent::find($obRow2->id)->attempts);
        $this->assertSame(2, (int) FailedEvent::find($obRow3->id)->attempts);
    }

    public function test_on_replay_batch_skips_stale_ids_silently(): void
    {
        $obRow = $this->seedRow('event-batch-real');
        // Mix one real id with two stale ids that no longer exist (operator
        // deleted them in a sibling tab before pressing the toolbar button).
        $this->bindRequestWithCheckedIds([(int) $obRow->id, 99998, 99999]);

        $obSpy = new SpyMetaClient;
        $this->app->instance(MetaClient::class, $obSpy);

        $obController = new TestableFailedEventsForBatch;
        $obController->onReplayBatch();

        // Only the real row should have dispatched.
        $this->assertSame(1, $obSpy->iCallCount, 'stale ids must be soft-skipped (no 500)');
    }

    public function test_on_replay_batch_empty_checked_list_is_noop(): void
    {
        $this->bindRequestWithCheckedIds([]);

        $obSpy = new SpyMetaClient;
        $this->app->instance(MetaClient::class, $obSpy);

        $obController = new TestableFailedEventsForBatch;
        $mResponse = $obController->onReplayBatch();

        $this->assertIsArray($mResponse);
        $this->assertSame(0, $obSpy->iCallCount, 'empty checked list must dispatch nothing');
    }

    public function test_on_replay_batch_records_http_status_from_metapixel_exception(): void
    {
        // Covers the getHttpStatus() branch (L212-214) where the exception
        // carries a concrete status — extends single-row coverage to batch.
        $obRow = $this->seedRow('event-batch-fail');
        $this->bindRequestWithCheckedIds([(int) $obRow->id]);

        $obFakeClient = new class extends MetaClient
        {
            public function __construct()
            {
                parent::__construct(null);
            }

            public function sendForPixel(string $sPixelId, string $sToken, array $arPayload): array
            {
                throw new MetaApiPermanentException('metapixel: pixel rejected', 422);
            }
        };
        $this->app->instance(MetaClient::class, $obFakeClient);

        $obController = new TestableFailedEventsForBatch;
        $obController->onReplayBatch();

        $obFresh = FailedEvent::find($obRow->id);
        $this->assertSame(422, (int) $obFresh->http_status);
        $this->assertStringContainsString('pixel rejected', (string) $obFresh->graph_error);
    }

    // -----------------------------------------------------------------------
    // onCheckDedupBatch
    // -----------------------------------------------------------------------

    public function test_on_check_dedup_batch_writes_columns_for_every_checked_row(): void
    {
        $obRow1 = $this->seedRow('event-dedup-1');
        $obRow2 = $this->seedRow('event-dedup-2');
        $this->bindRequestWithCheckedIds([(int) $obRow1->id, (int) $obRow2->id]);

        $obFakeClient = new class extends MetaClient
        {
            public int $iCallCount = 0;

            public function __construct()
            {
                parent::__construct(null);
            }

            public function fetchTestEventsStatus(string $sPixelId, string $sToken, string $sTestEventCode = '', string $sEventId = ''): array
            {
                $this->iCallCount++;

                return [
                    'event_match_quality' => ['Purchase' => 9.0],
                    'deduplication_rate' => ['Purchase' => 0.75],
                    'raw' => [],
                ];
            }
        };
        $this->app->instance(MetaClient::class, $obFakeClient);

        $obController = new TestableFailedEventsForBatch;
        $mResponse = $obController->onCheckDedupBatch();

        $this->assertIsArray($mResponse);
        $this->assertArrayHasKey('#failedEventList', $mResponse);
        $this->assertSame(2, $obFakeClient->iCallCount);

        $obFresh1 = FailedEvent::find($obRow1->id);
        $obFresh2 = FailedEvent::find($obRow2->id);
        $this->assertEqualsWithDelta(75.0, (float) $obFresh1->dedup_pct, 0.01);
        $this->assertEqualsWithDelta(75.0, (float) $obFresh2->dedup_pct, 0.01);
        $this->assertEqualsWithDelta(9.0, (float) $obFresh1->emq, 0.01);
        $this->assertEqualsWithDelta(9.0, (float) $obFresh2->emq, 0.01);
    }

    public function test_on_check_dedup_batch_skips_stale_ids_silently(): void
    {
        $obRow = $this->seedRow('event-dedup-real');
        $this->bindRequestWithCheckedIds([99998, (int) $obRow->id, 99999]);

        $obFakeClient = new class extends MetaClient
        {
            public int $iCallCount = 0;

            public function __construct()
            {
                parent::__construct(null);
            }

            public function fetchTestEventsStatus(string $sPixelId, string $sToken, string $sTestEventCode = '', string $sEventId = ''): array
            {
                $this->iCallCount++;

                return [
                    'event_match_quality' => ['Purchase' => 7.0],
                    'deduplication_rate' => ['Purchase' => 0.6],
                    'raw' => [],
                ];
            }
        };
        $this->app->instance(MetaClient::class, $obFakeClient);

        $obController = new TestableFailedEventsForBatch;
        $obController->onCheckDedupBatch();

        $this->assertSame(1, $obFakeClient->iCallCount, 'stale ids must be soft-skipped (no 500)');
    }

    public function test_on_check_dedup_batch_empty_checked_list_is_noop(): void
    {
        $this->bindRequestWithCheckedIds([]);

        $obFakeClient = new class extends MetaClient
        {
            public int $iCallCount = 0;

            public function __construct()
            {
                parent::__construct(null);
            }

            public function fetchTestEventsStatus(string $sPixelId, string $sToken, string $sTestEventCode = '', string $sEventId = ''): array
            {
                $this->iCallCount++;

                return ['event_match_quality' => null, 'deduplication_rate' => null, 'raw' => []];
            }
        };
        $this->app->instance(MetaClient::class, $obFakeClient);

        $obController = new TestableFailedEventsForBatch;
        $mResponse = $obController->onCheckDedupBatch();

        $this->assertIsArray($mResponse);
        $this->assertSame(0, $obFakeClient->iCallCount);
    }

    // -----------------------------------------------------------------------
    // onDeleteBatch
    // -----------------------------------------------------------------------

    public function test_on_delete_batch_removes_every_checked_row(): void
    {
        $obRow1 = $this->seedRow('event-delete-1');
        $obRow2 = $this->seedRow('event-delete-2');
        $obRow3 = $this->seedRow('event-delete-3');
        $this->bindRequestWithCheckedIds([(int) $obRow1->id, (int) $obRow3->id]);

        $obController = new TestableFailedEventsForBatch;
        $mResponse = $obController->onDeleteBatch();

        $this->assertIsArray($mResponse);
        $this->assertArrayHasKey('#failedEventList', $mResponse);
        $this->assertNull(FailedEvent::find($obRow1->id), 'row 1 deleted');
        $this->assertNotNull(FailedEvent::find($obRow2->id), 'row 2 untouched (unchecked)');
        $this->assertNull(FailedEvent::find($obRow3->id), 'row 3 deleted');
    }

    public function test_on_delete_batch_empty_checked_list_is_noop(): void
    {
        $obRow = $this->seedRow('event-survives');
        $this->bindRequestWithCheckedIds([]);

        $obController = new TestableFailedEventsForBatch;
        $mResponse = $obController->onDeleteBatch();

        $this->assertIsArray($mResponse);
        $this->assertNotNull(FailedEvent::find($obRow->id), 'no rows deleted when checked is empty');
    }

    public function test_on_delete_batch_filters_zero_or_negative_ids(): void
    {
        $obRow = $this->seedRow('event-filter-test');
        // 0 and negatives must be filtered by the iRecordId > 0 guard inside
        // onDeleteBatch (L136) — only the valid positive id is deleted.
        $this->bindRequestWithCheckedIds([0, (int) $obRow->id]);

        $obController = new TestableFailedEventsForBatch;
        $obController->onDeleteBatch();

        $this->assertNull(FailedEvent::find($obRow->id));
    }
}

/**
 * Same test harness rationale as FailedEventsReplayTest / CheckDedupTest —
 * bypasses heavy backend Controller boot and stubs listRefresh().
 */
final class TestableFailedEventsForBatch extends FailedEvents
{
    public function __construct() {}

    protected function listRefresh(): string
    {
        return '<list-stub />';
    }
}
