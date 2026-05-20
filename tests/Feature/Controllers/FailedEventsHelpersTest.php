<?php

use Illuminate\Http\Request;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
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
 * Narrowing-helper coverage — postRecordId / postCheckedIds /
 * extractMetricForEventName / findRowOrFail stale path. The helpers are
 * private; exercise their branches indirectly through the public AJAX
 * handlers that wrap them (Tiger-Style: test through the public surface,
 * not via reflection-into-implementation).
 */
final class FailedEventsHelpersTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
        (new CreateMetapixelFailedEventsTable)->up();
        (new AddDedupColumnsToFailedEvents)->up();

        Settings::clearInternalCache();
        Settings::set([
            'pixel_id' => 'PIXEL-HELP',
            'capi_access_token' => 'TOKEN-HELP',
        ]);

        app(AdapterRegistry::class)->register(stdClass::class, FakeAdapter::class);

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

    private function seedRow(): FailedEvent
    {
        $obRow = new FailedEvent;
        $obRow->event_id = 'event-helpers-1';
        $obRow->event_name = 'Purchase';
        $obRow->adapter_type = FakeAdapter::class;
        $obRow->subject_type = 'fake.subject';
        $obRow->subject_id = 42;
        $obRow->payload = ['data' => [['event_id' => 'event-helpers-1']]];
        $obRow->attempts = 1;
        $obRow->save();

        return $obRow;
    }

    /**
     * @param  array<string, mixed>  $arPost
     */
    private function bindPostRequest(array $arPost): void
    {
        $this->app->bind('request', fn () => Request::create('/', 'POST', $arPost));
    }

    // -----------------------------------------------------------------------
    // postRecordId narrowing (L339-350)
    // -----------------------------------------------------------------------

    public function test_post_record_id_accepts_digit_string_via_on_replay(): void
    {
        // Laravel's Request::create casts the array value to string at the
        // input layer — so a "real" backend POST always lands as a string here.
        // This exercises the is_string + ctype_digit branch (L345-347).
        $obRow = $this->seedRow();
        $this->bindPostRequest(['record_id' => (string) $obRow->id]);

        $obSpy = new SpyMetaClient;
        $this->app->instance(MetaClient::class, $obSpy);

        $obController = new TestableFailedEventsForHelpers;
        $obController->onReplay();

        $this->assertSame(1, $obSpy->iCallCount, 'digit-string record_id must coerce to int');
    }

    public function test_post_record_id_rejects_non_digit_string(): void
    {
        // is_string but NOT ctype_digit ("abc") → fall-through to return 0
        // (L349). The 0 then trips findRowOrFail's iRecordId <= 0 guard (L391-394).
        $this->bindPostRequest(['record_id' => 'abc']);

        $obSpy = new SpyMetaClient;
        $this->app->instance(MetaClient::class, $obSpy);

        $obController = new TestableFailedEventsForHelpers;

        $this->expectException(RuntimeException::class);
        $obController->onReplay();
        $this->assertSame(0, $obSpy->iCallCount);
    }

    public function test_post_record_id_rejects_empty_string(): void
    {
        // is_string but the empty-string check ($mRecordId !== '') guards
        // against ctype_digit('') === false — covers the early-bail branch.
        $this->bindPostRequest(['record_id' => '']);

        $obSpy = new SpyMetaClient;
        $this->app->instance(MetaClient::class, $obSpy);

        $obController = new TestableFailedEventsForHelpers;

        $this->expectException(RuntimeException::class);
        $obController->onReplay();
        $this->assertSame(0, $obSpy->iCallCount);
    }

    public function test_post_record_id_rejects_non_scalar_array(): void
    {
        // post('record_id') returns an array → neither is_int nor is_string;
        // falls through to return 0 (L349).
        $this->bindPostRequest(['record_id' => ['nested']]);

        $obSpy = new SpyMetaClient;
        $this->app->instance(MetaClient::class, $obSpy);

        $obController = new TestableFailedEventsForHelpers;

        $this->expectException(RuntimeException::class);
        $obController->onReplay();
        $this->assertSame(0, $obSpy->iCallCount);
    }

    // -----------------------------------------------------------------------
    // postCheckedIds narrowing (L357-373)
    // -----------------------------------------------------------------------

    public function test_post_checked_ids_skips_non_digit_string_entries(): void
    {
        $obRow = $this->seedRow();
        // Mixed array: valid digit-string + non-digit string + nested array.
        // Only the valid digit-string id must coerce; the others get dropped
        // by the elseif guard (L367-369) leaving the foreach loop's $arIds.
        $this->bindPostRequest(['checked' => [(string) $obRow->id, 'abc', ['nested']]]);

        $obSpy = new SpyMetaClient;
        $this->app->instance(MetaClient::class, $obSpy);

        $obController = new TestableFailedEventsForHelpers;
        $obController->onReplayBatch();

        $this->assertSame(1, $obSpy->iCallCount, 'only the digit-string id should dispatch');
    }

    public function test_post_checked_ids_returns_empty_when_post_is_not_array(): void
    {
        // post('checked') returns a string instead of array → ! is_array → []
        // (L360-362). Batch handler runs with no dispatch.
        $this->bindPostRequest(['checked' => 'not-an-array']);

        $obSpy = new SpyMetaClient;
        $this->app->instance(MetaClient::class, $obSpy);

        $obController = new TestableFailedEventsForHelpers;
        $obController->onReplayBatch();

        $this->assertSame(0, $obSpy->iCallCount);
    }

    // -----------------------------------------------------------------------
    // extractMetricForEventName narrowing (L306-320)
    // -----------------------------------------------------------------------

    public function test_extract_metric_returns_null_when_field_is_not_array(): void
    {
        // event_match_quality NOT an array → ! is_array branch (L308) → null.
        $obRow = $this->seedRow();
        $this->bindPostRequest(['record_id' => (string) $obRow->id]);

        $obController = $this->makeDedupController([
            'event_match_quality' => 'not-an-array',
            'deduplication_rate' => 'also-not-an-array',
            'raw' => [],
        ]);

        $obController->onCheckDedup();

        $obFresh = FailedEvent::find($obRow->id);
        $this->assertNull($obFresh->emq, 'non-array event_match_quality → null');
        $this->assertNull($obFresh->dedup_pct, 'non-array deduplication_rate → null');
    }

    public function test_extract_metric_returns_null_when_event_name_key_absent(): void
    {
        // event_match_quality has the wrong event-name key → array_key_exists
        // false branch (L311-313) → null.
        $obRow = $this->seedRow();
        $this->bindPostRequest(['record_id' => (string) $obRow->id]);

        $obController = $this->makeDedupController([
            'event_match_quality' => ['Lead' => 4.4],
            'deduplication_rate' => ['Lead' => 0.4],
            'raw' => [],
        ]);

        $obController->onCheckDedup();

        $obFresh = FailedEvent::find($obRow->id);
        $this->assertNull($obFresh->emq, 'row event_name=Purchase but map has only Lead → null');
        $this->assertNull($obFresh->dedup_pct);
    }

    public function test_extract_metric_returns_null_when_value_non_numeric(): void
    {
        // event_match_quality[Purchase] is a string → ! is_numeric branch
        // (L315-317) → null.
        $obRow = $this->seedRow();
        $this->bindPostRequest(['record_id' => (string) $obRow->id]);

        $obController = $this->makeDedupController([
            'event_match_quality' => ['Purchase' => 'not-a-number'],
            'deduplication_rate' => ['Purchase' => ['nested-array']],
            'raw' => [],
        ]);

        $obController->onCheckDedup();

        $obFresh = FailedEvent::find($obRow->id);
        $this->assertNull($obFresh->emq);
        $this->assertNull($obFresh->dedup_pct);
    }

    public function test_extract_metric_returns_null_when_event_name_empty(): void
    {
        // Seed a row whose event_name is empty string — the $sEventName === ''
        // half of the L308 guard (! is_array || $sEventName === '') fires
        // and short-circuits before the array_key_exists probe.
        $obRow = new FailedEvent;
        $obRow->event_id = 'event-empty-name';
        $obRow->event_name = '';
        $obRow->adapter_type = FakeAdapter::class;
        $obRow->payload = ['data' => []];
        $obRow->attempts = 1;
        $obRow->save();

        $this->bindPostRequest(['record_id' => (string) $obRow->id]);

        $obController = $this->makeDedupController([
            'event_match_quality' => ['Purchase' => 9.5],
            'deduplication_rate' => ['Purchase' => 0.95],
            'raw' => [],
        ]);

        $obController->onCheckDedup();

        $obFresh = FailedEvent::find($obRow->id);
        $this->assertNull($obFresh->emq, 'empty event_name short-circuits to null');
        $this->assertNull($obFresh->dedup_pct);
    }

    // -----------------------------------------------------------------------
    // findRowOrFail stale-id path (L397-400)
    // -----------------------------------------------------------------------

    public function test_find_row_or_fail_stale_positive_id_flashes_and_throws(): void
    {
        // Positive id that never existed (skip the iRecordId <= 0 guard at
        // L391-394 and hit the find-returns-null branch at L397-400 instead).
        $this->bindPostRequest(['record_id' => '99999']);

        $obSpy = new SpyMetaClient;
        $this->app->instance(MetaClient::class, $obSpy);

        $obController = new TestableFailedEventsForHelpers;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/failed event row 99999 not found/');
        $obController->onReplay();
        $this->assertSame(0, $obSpy->iCallCount);
    }

    public function test_find_row_or_fail_stale_id_via_check_dedup_throws(): void
    {
        // Same stale-id branch exercised via onCheckDedup — both AJAX handlers
        // share findRowOrFail so this asserts the helper is the single
        // user-input-boundary throw point.
        $this->bindPostRequest(['record_id' => '99998']);

        $obController = $this->makeDedupController([
            'event_match_quality' => null,
            'deduplication_rate' => null,
            'raw' => [],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/failed event row 99998 not found/');
        $obController->onCheckDedup();
    }

    /**
     * @param  array<string, mixed>  $arDedupResponse
     */
    private function makeDedupController(array $arDedupResponse): TestableFailedEventsForHelpers
    {
        $obFakeClient = new class($arDedupResponse) extends MetaClient
        {
            /**
             * @param  array<string, mixed>  $arResponse
             */
            public function __construct(private array $arResponse)
            {
                parent::__construct(null);
            }

            public function fetchTestEventsStatus(string $sPixelId, string $sToken, string $sTestEventCode = '', string $sEventId = ''): array
            {
                return $this->arResponse;
            }
        };
        $this->app->instance(MetaClient::class, $obFakeClient);

        return new TestableFailedEventsForHelpers;
    }
}

/**
 * Same test harness rationale as FailedEventsReplayTest / CheckDedupTest /
 * BatchTest — bypasses heavy backend Controller boot and stubs listRefresh().
 */
final class TestableFailedEventsForHelpers extends FailedEvents
{
    public function __construct() {}

    protected function listRefresh(): string
    {
        return '<list-stub />';
    }
}
