<?php

namespace Logingrupa\Metapixelshopaholic\Tests\Feature;

require_once __DIR__.'/../MetapixelTestCase.php';
// Migration filenames are snake_case (October Updates Manager convention) — not PSR-4 discoverable.
// Manually require the surviving Phase 3 + Phase 3.1 migrations so `(new ClassName)->up()` resolves.
require_once __DIR__.'/../../updates/create_table_failed_events.php';
require_once __DIR__.'/../../updates/add_unique_index_to_failed_events.php';
require_once __DIR__.'/../../updates/create_metapixel_event_log_table.php';
require_once __DIR__.'/../../updates/drop_meta_purchase_columns_from_orders_table.php';

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Logingrupa\Metapixelshopaholic\Tests\MetapixelTestCase;
use Logingrupa\Metapixelshopaholic\Updates\AddUniqueIndexToFailedEvents;
use Logingrupa\Metapixelshopaholic\Updates\CreateMetapixelEventLogTable;
use Logingrupa\Metapixelshopaholic\Updates\CreateTableFailedEvents;
use Logingrupa\Metapixelshopaholic\Updates\DropMetaPurchaseColumnsFromOrdersTable;
use Symfony\Component\Yaml\Yaml;

/**
 * Feature test covering the surviving migrations after Phase 3.1 REFAC-01 +
 * REFAC-02:
 *
 *   1. create_table_failed_events.php (Phase 3 PAY-05) creates the dead-letter
 *      table with all six business columns + timestamps.
 *   2. add_unique_index_to_failed_events.php (Phase 3 WR-07) registers the
 *      UNIQUE (event_id, http_status) idempotency index on the dead-letter
 *      table.
 *   3. create_metapixel_event_log_table.php (Phase 3.1 REFAC-02) creates the
 *      plugin-owned event_log table with the 5-column UNIQUE composite race
 *      fence + 3 secondary read-side indices.
 *   4. drop_meta_purchase_columns_from_orders_table.php (Phase 3.1 REFAC-01)
 *      tears the legacy dedup-fence columns off Lovata's orders table —
 *      down() restores them for rollback safety.
 *   5. updates/version.yaml registers the relevant migrations under their
 *      version keys (1.0.2, 1.0.3, 1.1.0).
 *
 * Uses the hermetic-table pattern: MetapixelTestCase::bootOrdersTable() lays
 * a minimal lovata_orders_shopaholic_orders, then the migration is invoked
 * directly via `(new ClassName)->up()` — no October Updates Manager round-trip.
 *
 * SQLite quirks:
 *   - `->after(...)` is silently ignored — column ordering on disk differs
 *     from MySQL but `Schema::hasColumn()` returns true either way.
 *   - dropIndex MUST precede dropColumn in the SAME closure (MIG-02 lock) —
 *     verified by the drop migration's up() body.
 *
 * Phase 3.1 NOTE: the original Phase-3 PAY-04 migration was DELETED in
 * Wave 1 REFAC-01 (Tiger-Style "no legacy code"). Its schema effects are
 * now covered by `DropMetaPurchaseColumnsFromOrdersTable::down()` which
 * re-adds the columns byte-for-byte for rollback safety v1.1.0 → v1.0.3.
 * Column names are referenced via the migration class constants
 * (COLUMN_ID / COLUMN_TIME) so the literal strings live in exactly ONE
 * place — the migration class itself.
 */
final class MigrationsBootTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
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

    public function test_event_log_table_created_with_all_schema_columns(): void
    {
        (new CreateMetapixelEventLogTable)->up();

        $this->assertTrue(
            Schema::hasTable('logingrupa_metapixel_event_log'),
            'REFAC-02: event_log table must exist after migration up().'
        );

        // The 11 business columns + id PK from the Wave-1 schema fingerprint.
        $arRequiredColumns = [
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
        foreach ($arRequiredColumns as $sColumn) {
            $this->assertTrue(
                Schema::hasColumn('logingrupa_metapixel_event_log', $sColumn),
                sprintf('REFAC-02: column `%s` must exist on logingrupa_metapixel_event_log.', $sColumn)
            );
        }
    }

    public function test_event_log_migration_down_drops_table(): void
    {
        $obMigration = new CreateMetapixelEventLogTable;
        $obMigration->up();
        $obMigration->down();

        $this->assertFalse(
            Schema::hasTable('logingrupa_metapixel_event_log'),
            'REFAC-02: event_log table must be dropped after down().'
        );
    }

    public function test_drop_meta_purchase_migration_down_restores_legacy_columns(): void
    {
        // REFAC-01 down() rollback safety lock — operators reverting v1.1.0
        // → v1.0.3 must see the legacy columns + index restored byte-for-byte
        // (T-3.1-01 mitigation).
        $this->bootOrdersTable();

        (new DropMetaPurchaseColumnsFromOrdersTable)->down();

        // Column names sourced from the migration class constants (DRY —
        // literal strings live only in the migration class body).
        $this->assertTrue(
            Schema::hasColumn(
                DropMetaPurchaseColumnsFromOrdersTable::TABLE_NAME,
                DropMetaPurchaseColumnsFromOrdersTable::COLUMN_ID,
            ),
            'REFAC-01 down() must re-add the dedup-fence id column for rollback safety.'
        );
        $this->assertTrue(
            Schema::hasColumn(
                DropMetaPurchaseColumnsFromOrdersTable::TABLE_NAME,
                DropMetaPurchaseColumnsFromOrdersTable::COLUMN_TIME,
            ),
            'REFAC-01 down() must re-add the dedup-fence time column for rollback safety.'
        );
    }

    public function test_version_yaml_lists_phase_31_and_failed_events_migrations(): void
    {
        $sVersionFile = __DIR__.'/../../updates/version.yaml';
        $this->assertFileExists($sVersionFile, 'updates/version.yaml must exist.');

        $arVersions = Yaml::parseFile($sVersionFile);
        $this->assertIsArray($arVersions);

        // 1.0.2 = PAY-05 failed_events create.
        $this->assertArrayHasKey('1.0.2', $arVersions, 'version.yaml must register PAY-05 migration under 1.0.2.');
        $this->assertContains(
            'create_table_failed_events.php',
            $arVersions['1.0.2'],
            '1.0.2 must list the create_table_failed_events migration filename.'
        );

        // 1.0.3 = WR-07 failed_events unique index.
        $this->assertArrayHasKey('1.0.3', $arVersions, 'version.yaml must register WR-07 migration under 1.0.3.');
        $this->assertContains(
            'add_unique_index_to_failed_events.php',
            $arVersions['1.0.3'],
            '1.0.3 must list the add_unique_index_to_failed_events migration filename.'
        );

        // 1.1.0 = Phase 3.1 event-log refactor (drop legacy + create event_log).
        $this->assertArrayHasKey('1.1.0', $arVersions, 'version.yaml must register Phase 3.1 migrations under 1.1.0.');
        $this->assertContains(
            'drop_meta_purchase_columns_from_orders_table.php',
            $arVersions['1.1.0'],
            '1.1.0 must list the drop_meta_purchase_columns migration filename.'
        );
        $this->assertContains(
            'create_metapixel_event_log_table.php',
            $arVersions['1.1.0'],
            '1.1.0 must list the create_metapixel_event_log_table migration filename.'
        );
    }

    public function test_failed_events_unique_index_prevents_duplicate_rows(): void
    {
        // WR-07 lock: confirm the unique index on (event_id, http_status) is
        // applied — a second insert with the same pair MUST raise (and our
        // silent catch in writeFailedEvent absorbs it).
        (new CreateTableFailedEvents)->up();
        (new AddUniqueIndexToFailedEvents)->up();

        \DB::table('logingrupa_metapixel_failed_events')->insert([
            'event_id' => 'wr07-uuid-001',
            'event_name' => 'Purchase',
            'payload' => '{}',
            'graph_error' => 'first',
            'http_status' => 400,
            'attempts' => 1,
            'created_at' => '2026-05-12 00:00:00',
            'updated_at' => '2026-05-12 00:00:00',
        ]);

        $bDuplicateRaised = false;
        try {
            \DB::table('logingrupa_metapixel_failed_events')->insert([
                'event_id' => 'wr07-uuid-001',
                'event_name' => 'Purchase',
                'payload' => '{}',
                'graph_error' => 'second',
                'http_status' => 400,
                'attempts' => 1,
                'created_at' => '2026-05-12 00:00:00',
                'updated_at' => '2026-05-12 00:00:00',
            ]);
        } catch (\Throwable $obException) {
            $bDuplicateRaised = true;
        }

        $this->assertTrue($bDuplicateRaised, 'WR-07: unique index MUST raise on duplicate (event_id, http_status).');
        $iCount = \DB::table('logingrupa_metapixel_failed_events')->where('event_id', 'wr07-uuid-001')->count();
        $this->assertSame(1, $iCount, 'WR-07: only ONE row may exist for the same (event_id, http_status) pair.');
    }
}
