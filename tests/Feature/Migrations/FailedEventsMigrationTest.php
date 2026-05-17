<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Logingrupa\Metapixel\Updates\CreateMetapixelFailedEventsTable;

final class FailedEventsMigrationTest extends MetapixelTestCase
{
    private const TABLE = 'logingrupa_metapixel_failed_events';

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
        (new CreateMetapixelFailedEventsTable)->up();

        $this->assertTrue(Schema::hasTable(self::TABLE));

        $arExpectedColumns = [
            'id',
            'event_id',
            'event_name',
            'adapter_type',
            'subject_type',
            'subject_id',
            'payload',
            'graph_error',
            'http_status',
            'attempts',
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
        (new CreateMetapixelFailedEventsTable)->up();
        $this->assertTrue(Schema::hasTable(self::TABLE));

        (new CreateMetapixelFailedEventsTable)->down();
        $this->assertFalse(Schema::hasTable(self::TABLE));
    }

    public function test_unique_allows_different_http_status_for_same_event_id(): void
    {
        (new CreateMetapixelFailedEventsTable)->up();

        $sEventId = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $arRowBase = [
            'event_id' => $sEventId,
            'event_name' => 'Purchase',
            'adapter_type' => 'Logingrupa\Metapixel\Adapter\Shopaholic\ShopaholicOrderAdapter',
            'subject_type' => 'shopaholic.order',
            'subject_id' => 99,
            'payload' => '{"data":[]}',
            'graph_error' => null,
            'attempts' => 1,
            'created_at' => '2025-01-01 00:00:00',
            'updated_at' => '2025-01-01 00:00:00',
        ];

        $arRow400 = array_merge($arRowBase, ['http_status' => 400]);
        $arRow500 = array_merge($arRowBase, ['http_status' => 500]);

        $this->assertTrue(DB::table(self::TABLE)->insert($arRow400));
        $this->assertTrue(DB::table(self::TABLE)->insert($arRow500));
        $this->assertSame(2, DB::table(self::TABLE)->where('event_id', $sEventId)->count());
    }

    public function test_unique_blocks_duplicate_event_id_and_http_status(): void
    {
        (new CreateMetapixelFailedEventsTable)->up();

        $arRow = [
            'event_id' => '11112222-3333-4444-5555-666677778888',
            'event_name' => 'Purchase',
            'adapter_type' => null,
            'subject_type' => null,
            'subject_id' => null,
            'payload' => '{"data":[]}',
            'graph_error' => 'first error',
            'http_status' => 400,
            'attempts' => 1,
            'created_at' => '2025-01-01 00:00:00',
            'updated_at' => '2025-01-01 00:00:00',
        ];

        $this->assertTrue(DB::table(self::TABLE)->insert($arRow));

        $arDup = $arRow;
        $arDup['graph_error'] = 'second error';
        $iAffected = DB::table(self::TABLE)->insertOrIgnore($arDup);

        $this->assertSame(0, $iAffected, 'UNIQUE on (event_id, http_status) MUST block second insert with same pair.');
    }

    public function test_up_is_idempotent(): void
    {
        (new CreateMetapixelFailedEventsTable)->up();
        (new CreateMetapixelFailedEventsTable)->up();

        $this->assertTrue(Schema::hasTable(self::TABLE));
    }
}
