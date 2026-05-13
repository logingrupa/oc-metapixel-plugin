<?php

declare(strict_types=1);

namespace Logingrupa\Metapixelshopaholic\Updates;

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

/**
 * Class CreateMetapixelEventLogTable
 *
 * REFAC-02 — Phase 3.1 event-log refactor. Creates the plugin-owned
 * `logingrupa_metapixel_event_log` table, the SINGLE source of truth for
 * "has this Meta event fired for this subject" across CAPI + Pixel channels.
 *
 * The 5-column UNIQUE composite key
 *   (subject_type, subject_id, event_name, channel, site_id)
 * is the entire race-fence of Phase 3.1 — protecting against:
 *
 *   - Concurrent CAPI dispatches for the same Order (PayPal return + IPN race) —
 *     one INSERT wins, the second fails with duplicate key.
 *   - Browser Pixel re-fires across devices/sessions/time — second AJAX INSERT
 *     no-ops via INSERT IGNORE in EventLogWriter::record.
 *   - Cross-site collision when a multi-site October installation runs the same
 *     Order id on two sites — site_id scope keeps them separate (NULL site_id is
 *     treated as a distinct value by MySQL UNIQUE; SQLite treats NULL as
 *     "absent" so multiple NULL-site rows can co-exist on single-site installs).
 *
 * Three secondary read-side indices feed PurchasePixel + OrderStatusWatcher:
 *
 *   - `metapixel_event_log_event_id_index` — admin replay by event_id.
 *   - `metapixel_event_log_secret_key_index` (secret_key, event_name, channel,
 *     site_id) — PurchasePixel resolves the CAPI row directly from the URL slug.
 *   - `metapixel_event_log_subject_index` (subject_type, subject_id, site_id) —
 *     OrderStatusWatcher gates "already dispatched" without scanning by slug.
 *
 * Index names stay under MySQL's 64-char identifier limit (longest = 48 chars).
 *
 * Reversible `down()` drops the table. Idempotent — `up()` no-ops if the table
 * already exists.
 *
 * @see plugins/logingrupa/metapixelshopaholic/.planning/phases/03.1-event-log-refactor/BRIEF.md REFAC-02 (SQL block lines 26-44)
 * @see plugins/logingrupa/metapixelshopaholic/updates/create_table_failed_events.php — sibling create-table precedent
 * @see plugins/logingrupa/backinstockshopaholic/updates/create_table_offersubscribers.php — sibling UNIQUE composite shape
 */
class CreateMetapixelEventLogTable extends Migration
{
    const TABLE = 'logingrupa_metapixel_event_log';

    /**
     * Apply migration — create the event_log table with the 5-column UNIQUE
     * race-fence plus three read-side indices.
     */
    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $obTable): void {
            $obTable->engine = 'InnoDB';
            $obTable->bigIncrements('id');
            $obTable->string('event_id', 36);
            $obTable->string('event_name', 64);             // Purchase / AddToCart / ViewContent / Lead / ...
            $obTable->string('channel', 16);                // 'capi' | 'pixel'
            $obTable->string('subject_type', 255);          // polymorphic FK type
            $obTable->unsignedInteger('subject_id');        // polymorphic FK id
            $obTable->string('secret_key', 64)->nullable(); // direct slug index for /checkout/{slug}
            $obTable->unsignedInteger('site_id')->nullable();
            $obTable->unsignedBigInteger('event_time');     // Meta-spec Unix timestamp (paired browser+server)
            $obTable->timestamp('fired_at');                // when this row was inserted
            $obTable->timestamps();

            // 5-column UNIQUE race-fence per BRIEF.md line 40. NULL site_id is
            // treated as a distinct value by MySQL UNIQUE — single-site
            // installs (SiteResolver returns null) coexist correctly with
            // multi-site installs on the same DB instance.
            $obTable->unique(
                ['subject_type', 'subject_id', 'event_name', 'channel', 'site_id'],
                'metapixel_event_log_subject_event_channel_unique',
            );

            // Three secondary read-side indices per BRIEF.md lines 41-43.
            $obTable->index('event_id', 'metapixel_event_log_event_id_index');
            $obTable->index(
                ['secret_key', 'event_name', 'channel', 'site_id'],
                'metapixel_event_log_secret_key_index',
            );
            $obTable->index(
                ['subject_type', 'subject_id', 'site_id'],
                'metapixel_event_log_subject_index',
            );
        });
    }

    /**
     * Rollback migration — drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
}
