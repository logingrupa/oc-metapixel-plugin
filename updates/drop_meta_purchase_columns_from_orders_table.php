<?php

declare(strict_types=1);

namespace Logingrupa\Metapixelshopaholic\Updates;

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

/**
 * Class DropMetaPurchaseColumnsFromOrdersTable
 *
 * REFAC-01 — Phase 3.1 event-log refactor. Tears the two persistent-state
 * columns (`meta_purchase_event_id`, `meta_purchase_event_time`) off
 * `lovata_orders_shopaholic_orders`. Phase 3.1 moves the Purchase idempotency
 * fence and the paired browser-side `event_time` source onto the new
 * plugin-owned `logingrupa_metapixel_event_log` table (see
 * `create_metapixel_event_log_table.php`).
 *
 * MIG-02 lock: `dropIndex` MUST precede `dropColumn` inside the same closure.
 * SQLite cannot drop a column that still has a live index attached; Laravel 12
 * schema builder validates the ordering. MySQL handles the implicit index drop
 * but explicit ordering is correctness-positive and matches the existing
 * `down()` of the now-deleted `add_meta_purchase_event_id_to_orders_table.php`.
 *
 * Reversible `down()` re-adds both columns + the original index — byte-for-byte
 * mirrors the deleted source migration's `up()` body so an operator running
 * `php artisan october:up -r {plugin}` against v1.1.0 → v1.0.3 gets the columns
 * back (T-3.1-01 disposition mitigate).
 *
 * Idempotent — `up()` short-circuits when neither column exists, `down()`
 * skips columns that already exist.
 *
 * @see plugins/logingrupa/metapixelshopaholic/.planning/phases/03.1-event-log-refactor/BRIEF.md REFAC-01
 * @see plugins/logingrupa/metapixelshopaholic/updates/create_metapixel_event_log_table.php — replacement table
 */
class DropMetaPurchaseColumnsFromOrdersTable extends Migration
{
    const TABLE_NAME = 'lovata_orders_shopaholic_orders';
    const COLUMN_ID = 'meta_purchase_event_id';
    const COLUMN_TIME = 'meta_purchase_event_time';
    const INDEX_NAME = 'lovata_orders_shopaholic_orders_meta_purchase_event_id_index';

    /**
     * Apply migration — drop the index first (MIG-02), then both columns.
     */
    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE_NAME)) {
            return;
        }

        if (!Schema::hasColumn(self::TABLE_NAME, self::COLUMN_ID)
            && !Schema::hasColumn(self::TABLE_NAME, self::COLUMN_TIME)) {
            return;
        }

        Schema::table(self::TABLE_NAME, function (Blueprint $obTable): void {
            if (Schema::hasColumn(self::TABLE_NAME, self::COLUMN_ID)) {
                // MIG-02: drop the index BEFORE the column. SQLite errors out
                // when a still-indexed column is dropped; Laravel 12 schema
                // builder validates the ordering. MySQL handles the implicit
                // drop, but explicit ordering keeps both drivers happy.
                $obTable->dropIndex(self::INDEX_NAME);
            }
            $obTable->dropColumn([self::COLUMN_ID, self::COLUMN_TIME]);
        });
    }

    /**
     * Rollback migration — re-add both columns + the index. Mirrors the
     * deleted source migration's `up()` body byte-for-byte (the rollback
     * trajectory is v1.1.0 → v1.0.3 which expects the columns present).
     */
    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE_NAME)) {
            return;
        }

        Schema::table(self::TABLE_NAME, function (Blueprint $obTable): void {
            if (!Schema::hasColumn(self::TABLE_NAME, self::COLUMN_ID)) {
                $obTable->string(self::COLUMN_ID, 36)->nullable()->after('secret_key')->index();
            }

            if (!Schema::hasColumn(self::TABLE_NAME, self::COLUMN_TIME)) {
                $obTable->unsignedBigInteger(self::COLUMN_TIME)->nullable()->after(self::COLUMN_ID);
            }
        });
    }
}
