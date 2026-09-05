<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Logingrupa\Metapixel\Updates\AddDedupColumnsToFailedEvents;
use Logingrupa\Metapixel\Updates\CreateMetapixelFailedEventsTable;
use Logingrupa\Metapixel\Updates\ReplaceDedupColumnsWithReplayedAt;

/**
 * Schema invariants for the dedup-columns-to-replayed_at migration on both
 * a fresh table and one that already carries the three dedup columns, plus
 * the backfill of replayed_at for rows replayed before the migration.
 */
final class ReplaceDedupColumnsWithReplayedAtTest extends MetapixelTestCase
{
    private const TABLE = 'logingrupa_metapixel_failed_events';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
        (new CreateMetapixelFailedEventsTable)->up();
    }

    protected function tearDown(): void
    {
        (new ReplaceDedupColumnsWithReplayedAt)->down();
        (new AddDedupColumnsToFailedEvents)->down();
        (new CreateMetapixelFailedEventsTable)->down();
        app()->forgetInstance(AdapterRegistry::class);
        parent::tearDown();
    }

    public function test_up_on_fresh_table_adds_replayed_at_only(): void
    {
        (new ReplaceDedupColumnsWithReplayedAt)->up();

        $this->assertTrue(Schema::hasColumn(self::TABLE, 'replayed_at'));
        $this->assertFalse(Schema::hasColumn(self::TABLE, 'dedup_pct'));
    }

    public function test_up_on_legacy_table_drops_the_three_dedup_columns(): void
    {
        (new AddDedupColumnsToFailedEvents)->up();
        (new ReplaceDedupColumnsWithReplayedAt)->up();

        $this->assertTrue(Schema::hasColumn(self::TABLE, 'replayed_at'));
        $this->assertFalse(Schema::hasColumn(self::TABLE, 'dedup_pct'));
        $this->assertFalse(Schema::hasColumn(self::TABLE, 'emq'));
        $this->assertFalse(Schema::hasColumn(self::TABLE, 'dedup_checked_at'));
    }

    public function test_up_backfills_replayed_at_from_updated_at_for_rows_without_graph_error(): void
    {
        DB::table(self::TABLE)->insert([
            $this->makeRow('uuid-replayed', null, '2026-09-01 10:00:00'),
            $this->makeRow('uuid-failed', 'boom', '2026-09-01 11:00:00'),
        ]);

        (new ReplaceDedupColumnsWithReplayedAt)->up();

        $arByEvent = DB::table(self::TABLE)->pluck('replayed_at', 'event_id')->all();
        $this->assertSame('2026-09-01 10:00:00', $arByEvent['uuid-replayed']);
        $this->assertNull($arByEvent['uuid-failed']);
    }

    public function test_up_and_down_are_idempotent(): void
    {
        (new ReplaceDedupColumnsWithReplayedAt)->up();
        (new ReplaceDedupColumnsWithReplayedAt)->up();
        $this->assertTrue(Schema::hasColumn(self::TABLE, 'replayed_at'));

        (new ReplaceDedupColumnsWithReplayedAt)->down();
        (new ReplaceDedupColumnsWithReplayedAt)->down();
        $this->assertFalse(Schema::hasColumn(self::TABLE, 'replayed_at'));
        $this->assertTrue(Schema::hasColumn(self::TABLE, 'dedup_pct'), 'down restores the legacy columns');
    }

    /** @return array<string, mixed> */
    private function makeRow(string $sEventId, ?string $sGraphError, string $sUpdatedAt): array
    {
        return [
            'event_id' => $sEventId,
            'event_name' => 'Purchase',
            'adapter_type' => 'fake',
            'payload' => '{"data":[]}',
            'graph_error' => $sGraphError,
            'attempts' => 2,
            'created_at' => '2026-09-01 09:00:00',
            'updated_at' => $sUpdatedAt,
        ];
    }
}
