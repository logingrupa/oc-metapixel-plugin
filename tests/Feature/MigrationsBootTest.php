<?php

namespace Logingrupa\Metapixelshopaholic\Tests\Feature;

require_once __DIR__.'/../MetapixelTestCase.php';
// Migration filenames snake_case (October Updates Manager convention) — not PSR-4 discoverable.
// Manual require so `(new ClassName)->up()` resolves.
require_once __DIR__.'/../../updates/create_table_failed_events.php';
require_once __DIR__.'/../../updates/create_metapixel_event_log_table.php';

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Logingrupa\Metapixelshopaholic\Tests\MetapixelTestCase;
use Logingrupa\Metapixelshopaholic\Updates\CreateMetapixelEventLogTable;
use Logingrupa\Metapixelshopaholic\Updates\CreateTableFailedEvents;
use Symfony\Component\Yaml\Yaml;

/**
 * Migration boot test — only 2 surviving migrations post-cleanup:
 *   1. create_table_failed_events.php (PAY-05 + WR-07 unique idx inline)
 *   2. create_metapixel_event_log_table.php (REFAC-02 race-fence + 3 read-side idx)
 *
 * Hermetic pattern: MetapixelTestCase fixtures lay test schema; migration
 * invoked direct via `(new ClassName)->up()` — no Updates Manager round-trip.
 *
 * SQLite quirks: `->after(...)` silently ignored; column ordering on disk
 * differs from MySQL but `Schema::hasColumn()` returns true either way.
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
            'PAY-05: failed_events table must exist after up().'
        );

        $arRequiredColumns = ['event_id', 'event_name', 'payload', 'graph_error', 'http_status', 'attempts'];
        foreach ($arRequiredColumns as $sColumn) {
            $this->assertTrue(
                Schema::hasColumn('logingrupa_metapixel_failed_events', $sColumn),
                sprintf('PAY-05: column `%s` must exist.', $sColumn)
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
            'REFAC-02: event_log table must exist after up().'
        );

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
                sprintf('REFAC-02: column `%s` must exist.', $sColumn)
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

    public function test_version_yaml_lists_surviving_migrations(): void
    {
        $sVersionFile = __DIR__.'/../../updates/version.yaml';
        $this->assertFileExists($sVersionFile, 'updates/version.yaml must exist.');

        $arVersions = Yaml::parseFile($sVersionFile);
        $this->assertIsArray($arVersions);

        // 1.1.0 = consolidated DB install — both surviving migrations.
        $this->assertArrayHasKey('1.1.0', $arVersions, 'version.yaml must register 1.1.0 with both create migrations.');
        $this->assertContains(
            'create_table_failed_events.php',
            $arVersions['1.1.0'],
            '1.1.0 must list create_table_failed_events.'
        );
        $this->assertContains(
            'create_metapixel_event_log_table.php',
            $arVersions['1.1.0'],
            '1.1.0 must list create_metapixel_event_log_table.'
        );

        // 1.1.1 = code-only refactor (no migration filename listed).
        $this->assertArrayHasKey('1.1.1', $arVersions, 'version.yaml must register 1.1.1 code refactor.');
    }

    public function test_failed_events_unique_index_prevents_duplicate_rows(): void
    {
        // WR-07: unique index on (event_id, http_status) inline in CreateTableFailedEvents::up().
        // Second insert same pair MUST raise (silent catch in writeFailedEvent absorbs it).
        (new CreateTableFailedEvents)->up();

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
