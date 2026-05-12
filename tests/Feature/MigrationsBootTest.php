<?php

namespace Logingrupa\Metapixelshopaholic\Tests\Feature;

require_once __DIR__.'/../MetapixelTestCase.php';
// Migration filenames are snake_case (October Updates Manager convention) — not PSR-4 discoverable.
// Manually require the two Phase 3 migrations so `(new ClassName)->up()` resolves.
require_once __DIR__.'/../../updates/add_meta_purchase_event_id_to_orders_table.php';
require_once __DIR__.'/../../updates/create_table_failed_events.php';

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Logingrupa\Metapixelshopaholic\Tests\MetapixelTestCase;
use Logingrupa\Metapixelshopaholic\Updates\AddMetaPurchaseEventIdToOrdersTable;
use Logingrupa\Metapixelshopaholic\Updates\CreateTableFailedEvents;
use Symfony\Component\Yaml\Yaml;

/**
 * Feature test covering Plan 03-01 PAY-04 + PAY-05 schema fences:
 *
 *   1. add_meta_purchase_event_id_to_orders_table.php registers
 *      `meta_purchase_event_id` VARCHAR(36) NULL INDEX on the orders table.
 *   2. add_meta_purchase_event_id_to_orders_table.php also registers
 *      `meta_purchase_event_time` BIGINT UNSIGNED NULL — the companion column
 *      the Phase 3 PurchasePixel browser twin (plan 03-06) reads so Pixel +
 *      CAPI agree on event_time within Meta's ±10 s dedup window.
 *   3. The migration's down() drops both columns symmetrically.
 *   4. create_table_failed_events.php creates the dead-letter table with all
 *      six business columns + timestamps.
 *   5. The dead-letter migration's down() drops the whole table.
 *   6. updates/version.yaml registers BOTH migrations under their version keys.
 *
 * Uses the hermetic-table pattern: MetapixelTestCase::bootOrdersTable() lays
 * a minimal lovata_orders_shopaholic_orders, then the migration is invoked
 * directly via `(new ClassName)->up()` — no October Updates Manager round-trip.
 *
 * SQLite quirk: `->after(...)` is silently ignored — column ordering on disk
 * differs from MySQL but `Schema::hasColumn()` returns true either way.
 */
final class MigrationsBootTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_orders_meta_purchase_event_id_column_exists_after_migration(): void
    {
        $this->bootOrdersTable();

        (new AddMetaPurchaseEventIdToOrdersTable)->up();

        $this->assertTrue(
            Schema::hasColumn('lovata_orders_shopaholic_orders', 'meta_purchase_event_id'),
            'PAY-04: meta_purchase_event_id column must exist after migration up().'
        );
    }

    public function test_orders_meta_purchase_event_time_column_exists_after_migration(): void
    {
        $this->bootOrdersTable();

        (new AddMetaPurchaseEventIdToOrdersTable)->up();

        $this->assertTrue(
            Schema::hasColumn('lovata_orders_shopaholic_orders', 'meta_purchase_event_time'),
            'PAY-04: meta_purchase_event_time column must exist after migration up() so Pixel + CAPI share event_time within Meta ±10 s dedup window.'
        );
    }

    public function test_orders_migration_down_drops_meta_columns(): void
    {
        $this->bootOrdersTable();

        $obMigration = new AddMetaPurchaseEventIdToOrdersTable;
        $obMigration->up();
        $obMigration->down();

        $this->assertFalse(
            Schema::hasColumn('lovata_orders_shopaholic_orders', 'meta_purchase_event_id'),
            'meta_purchase_event_id must be dropped after down().'
        );
        $this->assertFalse(
            Schema::hasColumn('lovata_orders_shopaholic_orders', 'meta_purchase_event_time'),
            'meta_purchase_event_time must be dropped after down().'
        );
    }

    public function test_failed_events_table_created_with_all_business_columns(): void
    {
        (new CreateTableFailedEvents)->up();

        $this->assertTrue(
            Schema::hasTable('logingrupa_metapixel_failed_events'),
            'PAY-05: failed_events table must exist after migration up().'
        );

        $arRequiredColumns = ['event_id', 'event_name', 'payload', 'graph_error', 'http_status', 'attempts'];
        foreach ($arRequiredColumns as $sColumn) {
            $this->assertTrue(
                Schema::hasColumn('logingrupa_metapixel_failed_events', $sColumn),
                sprintf('PAY-05: column `%s` must exist on logingrupa_metapixel_failed_events.', $sColumn)
            );
        }
    }

    public function test_failed_events_migration_down_drops_table(): void
    {
        $obMigration = new CreateTableFailedEvents;
        $obMigration->up();
        $obMigration->down();

        $this->assertFalse(
            Schema::hasTable('logingrupa_metapixel_failed_events'),
            'failed_events table must be dropped after down().'
        );
    }

    public function test_version_yaml_lists_both_migrations(): void
    {
        $sVersionFile = __DIR__.'/../../updates/version.yaml';
        $this->assertFileExists($sVersionFile, 'updates/version.yaml must exist.');

        $arVersions = Yaml::parseFile($sVersionFile);
        $this->assertIsArray($arVersions);
        $this->assertArrayHasKey('1.0.1', $arVersions, 'version.yaml must register PAY-04 migration under 1.0.1.');
        $this->assertArrayHasKey('1.0.2', $arVersions, 'version.yaml must register PAY-05 migration under 1.0.2.');
        $this->assertContains(
            'add_meta_purchase_event_id_to_orders_table.php',
            $arVersions['1.0.1'],
            '1.0.1 must list the add_meta_purchase_event_id migration filename.'
        );
        $this->assertContains(
            'create_table_failed_events.php',
            $arVersions['1.0.2'],
            '1.0.2 must list the create_table_failed_events migration filename.'
        );
    }
}
