<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Logingrupa\Metapixel\Updates\AddDedupColumnsToFailedEvents;
use Logingrupa\Metapixel\Updates\CreateMetapixelFailedEventsTable;

/**
 * Wave 0 RED — fails until plan 04-04 production code ships.
 *
 * Schema-level invariants for the additive dedup-columns migration:
 * existence + nullable DECIMAL/DATETIME types + idempotent up()/down().
 * Mirrors AddPayloadColumnTest verbatim shape (04-PATTERNS.md lines 252-313).
 */
final class AddDedupColumnsToFailedEventsTest extends MetapixelTestCase
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
        (new AddDedupColumnsToFailedEvents)->down();
        (new CreateMetapixelFailedEventsTable)->down();
        app()->forgetInstance(AdapterRegistry::class);
        parent::tearDown();
    }

    public function test_up_adds_three_dedup_columns(): void
    {
        (new AddDedupColumnsToFailedEvents)->up();

        $this->assertTrue(Schema::hasColumn(self::TABLE, 'dedup_pct'));
        $this->assertTrue(Schema::hasColumn(self::TABLE, 'emq'));
        $this->assertTrue(Schema::hasColumn(self::TABLE, 'dedup_checked_at'));
    }

    public function test_up_is_idempotent(): void
    {
        (new AddDedupColumnsToFailedEvents)->up();
        (new AddDedupColumnsToFailedEvents)->up();

        $this->assertTrue(Schema::hasColumn(self::TABLE, 'dedup_pct'));
    }

    public function test_down_drops_three_dedup_columns(): void
    {
        (new AddDedupColumnsToFailedEvents)->up();
        (new AddDedupColumnsToFailedEvents)->down();

        $this->assertFalse(Schema::hasColumn(self::TABLE, 'dedup_pct'));
        $this->assertFalse(Schema::hasColumn(self::TABLE, 'emq'));
        $this->assertFalse(Schema::hasColumn(self::TABLE, 'dedup_checked_at'));
    }

    public function test_down_is_idempotent(): void
    {
        (new AddDedupColumnsToFailedEvents)->up();
        (new AddDedupColumnsToFailedEvents)->down();
        (new AddDedupColumnsToFailedEvents)->down();

        $this->assertFalse(Schema::hasColumn(self::TABLE, 'dedup_pct'));
    }

    public function test_column_types_decimal_and_datetime(): void
    {
        (new AddDedupColumnsToFailedEvents)->up();

        $arInfo = DB::select(sprintf('PRAGMA table_info(%s)', self::TABLE));
        $arByName = [];
        foreach ($arInfo as $obRow) {
            $arByName[$obRow->name] = $obRow;
        }

        $this->assertArrayHasKey('dedup_pct', $arByName);
        $this->assertArrayHasKey('emq', $arByName);
        $this->assertArrayHasKey('dedup_checked_at', $arByName);

        // SQLite reports decimal columns as `numeric` / `decimal(...)` and
        // datetime columns as `datetime` — regex-match each to stay portable.
        $this->assertMatchesRegularExpression('/numeric|decimal/i', $arByName['dedup_pct']->type);
        $this->assertMatchesRegularExpression('/numeric|decimal/i', $arByName['emq']->type);
        $this->assertMatchesRegularExpression('/datetime/i', $arByName['dedup_checked_at']->type);

        $this->assertSame(0, (int) $arByName['dedup_pct']->notnull);
        $this->assertSame(0, (int) $arByName['emq']->notnull);
        $this->assertSame(0, (int) $arByName['dedup_checked_at']->notnull);
    }
}
