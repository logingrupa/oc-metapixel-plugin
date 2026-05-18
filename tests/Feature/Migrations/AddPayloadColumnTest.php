<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Logingrupa\Metapixel\Updates\AddPayloadToMetapixelEventLogTable;
use Logingrupa\Metapixel\Updates\CreateMetapixelEventLogTable;

/**
 * Phase 3 D-06 — Schema-level invariants for the additive payload column
 * migration: existence, nullable longText/text on SQLite introspection, and
 * idempotent up() when the column already exists.
 */
final class AddPayloadColumnTest extends MetapixelTestCase
{
    private const TABLE = 'logingrupa_metapixel_event_log';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
        (new CreateMetapixelEventLogTable)->up();
        (new AddPayloadToMetapixelEventLogTable)->up();
    }

    protected function tearDown(): void
    {
        (new AddPayloadToMetapixelEventLogTable)->down();
        (new CreateMetapixelEventLogTable)->down();
        app()->forgetInstance(AdapterRegistry::class);
        parent::tearDown();
    }

    public function test_payload_column_exists_after_migration(): void
    {
        $this->assertTrue(Schema::hasColumn(self::TABLE, 'payload'));
    }

    public function test_payload_column_is_nullable_longtext(): void
    {
        $arInfo = DB::select(sprintf('PRAGMA table_info(%s)', self::TABLE));
        $obPayloadRow = null;
        foreach ($arInfo as $obRow) {
            if ($obRow->name === 'payload') {
                $obPayloadRow = $obRow;
                break;
            }
        }

        $this->assertNotNull($obPayloadRow, 'payload column row must exist in PRAGMA table_info');
        $this->assertMatchesRegularExpression('/text/i', $obPayloadRow->type);
        $this->assertSame(0, $obPayloadRow->notnull, 'payload column must be nullable');
    }

    public function test_migration_up_is_idempotent_when_column_already_present(): void
    {
        // setUp already ran up() once. Run it a second time — must not throw.
        (new AddPayloadToMetapixelEventLogTable)->up();
        $this->assertTrue(Schema::hasColumn(self::TABLE, 'payload'));
    }
}
