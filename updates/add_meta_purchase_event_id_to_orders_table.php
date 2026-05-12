<?php

namespace Logingrupa\Metapixelshopaholic\Updates;

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

/**
 * Class AddMetaPurchaseEventIdToOrdersTable
 *
 * PAY-04 — adds two persistent-state columns to `lovata_orders_shopaholic_orders`:
 *   - `meta_purchase_event_id`  VARCHAR(36) NULL INDEX  (after `secret_key`)
 *   - `meta_purchase_event_time` BIGINT UNSIGNED NULL    (after the event_id column)
 *
 * Both columns are mandatory for the Phase 3 Purchase event dedup contract:
 *
 *   - `meta_purchase_event_id` is the DB-level idempotency fence — non-null = the
 *     CAPI Purchase event has already been dispatched (or is in-flight in the
 *     queue). OrderStatusWatcher (plan 03-06) writes a UUIDv4 here via
 *     `saveQuietly`. Plan 03-06 OrderStatusWatcher entry-tests this column.
 *
 *   - `meta_purchase_event_time` is the SAME Unix-seconds timestamp captured on
 *     the CAPI dispatch side AND emitted into the browser-side Pixel `event_time`
 *     parameter by the Phase 3 PurchasePixel browser twin. Meta dedups paired
 *     (event_id, event_name, event_time) within a ±10 s window — without a
 *     persisted server-side event_time the browser cannot re-emit the same value
 *     after a page reload + redirect, so Meta would see two events with the same
 *     event_id but different event_times and dedup would fail. Storing event_time
 *     server-side and rehydrating it into Twig is the canonical fix.
 *
 * Reversible `down()` drops both columns. Idempotent — `up()` no-ops if either
 * column is already present, `down()` no-ops if either column is missing.
 *
 * Note: `->after(...)` is a MySQL-only Blueprint hint; SQLite silently ignores
 * it during hermetic test runs (see Phase 3 PATTERNS.md "Bug-watch" note).
 *
 * @see plugins/logingrupa/extendshopaholic/updates/table_update_shipping_type_add_external_id_field.php — analog
 */
class AddMetaPurchaseEventIdToOrdersTable extends Migration
{
    const TABLE_NAME = 'lovata_orders_shopaholic_orders';
    const COLUMN_ID = 'meta_purchase_event_id';
    const COLUMN_TIME = 'meta_purchase_event_time';

    /**
     * Apply migration — add both meta_purchase_event_id + meta_purchase_event_time columns.
     */
    public function up(): void
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

    /**
     * Rollback migration — drop both columns.
     */
    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE_NAME)) {
            return;
        }

        if (!Schema::hasColumn(self::TABLE_NAME, self::COLUMN_ID)
            && !Schema::hasColumn(self::TABLE_NAME, self::COLUMN_TIME)) {
            return;
        }

        Schema::table(self::TABLE_NAME, function (Blueprint $obTable): void {
            $obTable->dropColumn([self::COLUMN_ID, self::COLUMN_TIME]);
        });
    }
}
