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
use Logingrupa\Metapixel\Updates\CreateMetapixelFailedEventsTable;
use Logingrupa\Metapixel\Updates\ReplaceDedupColumnsWithReplayedAt;

/**
 * Narrowing-helper coverage — postRecordId / postCheckedIds / findRowOrFail
 * stale path. The helpers are private; exercise their branches indirectly
 * through the public AJAX handlers that wrap them (Tiger-Style: test through
 * the public surface, not via reflection-into-implementation).
 */
final class FailedEventsHelpersTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
        (new CreateMetapixelFailedEventsTable)->up();
        (new ReplaceDedupColumnsWithReplayedAt)->up();

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
        (new ReplaceDedupColumnsWithReplayedAt)->down();
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
    // postRecordId narrowing
    // -----------------------------------------------------------------------

    public function test_post_record_id_accepts_digit_string_via_on_replay(): void
    {
        // Laravel's Request::create casts the array value to string at the
        // input layer — so a "real" backend POST always lands as a string here.
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
        // is_string but NOT ctype_digit ("abc") → return 0, which trips
        // findRowOrFail's iRecordId <= 0 guard.
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
        // falls through to return 0.
        $this->bindPostRequest(['record_id' => ['nested']]);

        $obSpy = new SpyMetaClient;
        $this->app->instance(MetaClient::class, $obSpy);

        $obController = new TestableFailedEventsForHelpers;

        $this->expectException(RuntimeException::class);
        $obController->onReplay();
        $this->assertSame(0, $obSpy->iCallCount);
    }

    // -----------------------------------------------------------------------
    // postCheckedIds narrowing
    // -----------------------------------------------------------------------

    public function test_post_checked_ids_skips_non_digit_string_entries(): void
    {
        $obRow = $this->seedRow();
        // Mixed array: valid digit-string + non-digit string + nested array.
        // Only the valid digit-string id must coerce; the others get dropped.
        $this->bindPostRequest(['checked' => [(string) $obRow->id, 'abc', ['nested']]]);

        $obSpy = new SpyMetaClient;
        $this->app->instance(MetaClient::class, $obSpy);

        $obController = new TestableFailedEventsForHelpers;
        $obController->onReplayBatch();

        $this->assertSame(1, $obSpy->iCallCount, 'only the digit-string id should dispatch');
    }

    public function test_post_checked_ids_returns_empty_when_post_is_not_array(): void
    {
        // post('checked') returns a string instead of array → ! is_array → [].
        // Batch handler runs with no dispatch.
        $this->bindPostRequest(['checked' => 'not-an-array']);

        $obSpy = new SpyMetaClient;
        $this->app->instance(MetaClient::class, $obSpy);

        $obController = new TestableFailedEventsForHelpers;
        $obController->onReplayBatch();

        $this->assertSame(0, $obSpy->iCallCount);
    }

    // -----------------------------------------------------------------------
    // findRowOrFail stale-id path
    // -----------------------------------------------------------------------

    public function test_find_row_or_fail_stale_positive_id_flashes_and_throws(): void
    {
        // Positive id that never existed (skip the iRecordId <= 0 guard and
        // hit the find-returns-null branch instead).
        $this->bindPostRequest(['record_id' => '99999']);

        $obSpy = new SpyMetaClient;
        $this->app->instance(MetaClient::class, $obSpy);

        $obController = new TestableFailedEventsForHelpers;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/failed event row 99999 not found/');
        $obController->onReplay();
        $this->assertSame(0, $obSpy->iCallCount);
    }
}

/**
 * Same test harness rationale as FailedEventsReplayTest / BatchTest —
 * bypasses heavy backend Controller boot and stubs listRefresh().
 */
final class TestableFailedEventsForHelpers extends FailedEvents
{
    public function __construct() {}

    protected function listRefresh(): string
    {
        return '<list-stub />';
    }
}
