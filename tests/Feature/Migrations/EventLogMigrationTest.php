<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Logingrupa\Metapixel\Updates\CreateMetapixelEventLogTable;

final class EventLogMigrationTest extends MetapixelTestCase
{
    private const TABLE = 'logingrupa_metapixel_event_log';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists(self::TABLE);
        parent::tearDown();
    }

    public function test_up_creates_table_with_required_columns(): void
    {
        (new CreateMetapixelEventLogTable)->up();

        $this->assertTrue(Schema::hasTable(self::TABLE));

        $arExpectedColumns = [
            'id',
            'event_id',
            'event_name',
            'channel',
            'subject_type',
            'subject_id',
            'secret_key',
            'site_id',
            'event_time',
            'fired_at',
            'created_at',
            'updated_at',
        ];
        foreach ($arExpectedColumns as $sColumn) {
            $this->assertTrue(
                Schema::hasColumn(self::TABLE, $sColumn),
                sprintf('Expected column "%s" to exist on %s', $sColumn, self::TABLE)
            );
        }
    }

    public function test_down_drops_the_table(): void
    {
        (new CreateMetapixelEventLogTable)->up();
        $this->assertTrue(Schema::hasTable(self::TABLE));

        (new CreateMetapixelEventLogTable)->down();
        $this->assertFalse(Schema::hasTable(self::TABLE));
    }

    public function test_unique_constraint_blocks_duplicate_inserts(): void
    {
        (new CreateMetapixelEventLogTable)->up();

        $arRow = [
            'event_id' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
            'event_name' => 'Purchase',
            'channel' => 'capi',
            'subject_type' => 'shopaholic.order',
            'subject_id' => 42,
            'secret_key' => 'abc123',
            'site_id' => 1,
            'event_time' => 1700000000,
            'fired_at' => '2025-01-01 00:00:00',
            'created_at' => '2025-01-01 00:00:00',
            'updated_at' => '2025-01-01 00:00:00',
        ];

        $bFirst = DB::table(self::TABLE)->insert($arRow);
        $this->assertTrue($bFirst);

        $arRow['event_id'] = '11111111-2222-3333-4444-555555555555';
        $iAffected = DB::table(self::TABLE)->insertOrIgnore($arRow);
        $this->assertSame(0, $iAffected, 'UNIQUE on (subject_type, subject_id, event_name, channel, site_id) MUST block duplicate insert.');
    }

    public function test_up_is_idempotent(): void
    {
        (new CreateMetapixelEventLogTable)->up();
        (new CreateMetapixelEventLogTable)->up();

        $this->assertTrue(Schema::hasTable(self::TABLE));
    }
}
