<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Logingrupa\Metapixel\Updates\CreateMetapixelOrderBrowserContextsTable;

/**
 * Schema invariants of the order browser context table: columns, one row
 * per order, idempotent up().
 */
final class OrderBrowserContextsMigrationTest extends MetapixelTestCase
{
    private const TABLE = 'logingrupa_metapixel_order_browser_contexts';

    protected function setUp(): void
    {
        parent::setUp();
        (new CreateMetapixelOrderBrowserContextsTable)->up();
    }

    protected function tearDown(): void
    {
        (new CreateMetapixelOrderBrowserContextsTable)->down();
        parent::tearDown();
    }

    public function test_table_has_the_context_columns(): void
    {
        foreach (['order_id', 'client_ip_address', 'client_user_agent', 'fbp', 'fbc', 'event_source_url', 'created_at'] as $sColumn) {
            $this->assertTrue(Schema::hasColumn(self::TABLE, $sColumn), $sColumn);
        }
    }

    public function test_order_id_is_unique(): void
    {
        DB::table(self::TABLE)->insert(['order_id' => 7, 'client_ip_address' => '203.0.113.1']);

        $this->expectException(QueryException::class);
        DB::table(self::TABLE)->insert(['order_id' => 7, 'client_ip_address' => '203.0.113.2']);
    }

    public function test_up_is_idempotent(): void
    {
        (new CreateMetapixelOrderBrowserContextsTable)->up();
        $this->assertTrue(Schema::hasTable(self::TABLE));
    }
}
