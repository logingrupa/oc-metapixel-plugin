<?php

declare(strict_types=1);

namespace Logingrupa\Metapixelshopaholic\Updates;

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

/**
 * Class CreateMetapixelEventLogTable
 *
 * REFAC-02 — plugin-owned event_log table. SINGLE source of truth for
 * "has this Meta event fired for this subject" across CAPI + Pixel channels.
 *
 * 5-column UNIQUE composite (subject_type, subject_id, event_name, channel,
 * site_id) is entire race-fence:
 *   - Concurrent CAPI dispatches same Order (PayPal return + IPN race) — one
 *     INSERT wins, second fails on duplicate key.
 *   - Browser Pixel re-fires across devices/sessions/time — second AJAX
 *     INSERT no-ops via INSERT IGNORE in EventLogWriter::record.
 *   - Cross-site collision multi-site October — site_id scope keeps them
 *     separate. NULL site_id distinct under MySQL UNIQUE semantics;
 *     SQLite treats NULL as absent so single-site NULL-rows coexist.
 *
 * Three read-side indices:
 *   metapixel_event_log_event_id_index           admin replay by event_id
 *   metapixel_event_log_secret_key_index         PurchasePixel resolves CAPI row from URL slug
 *   metapixel_event_log_subject_index            OrderStatusWatcher already-dispatched gate
 *
 * Index names under MySQL 64-char limit (longest=48). Idempotent up() +
 * reversible down().
 *
 * @see plugins/logingrupa/metapixelshopaholic/.planning/phases/03.1-event-log-refactor/BRIEF.md REFAC-02
 */
class CreateMetapixelEventLogTable extends Migration
{
    const TABLE = 'logingrupa_metapixel_event_log';

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
            $obTable->unsignedBigInteger('event_time');     // Meta Unix timestamp (paired browser+server)
            $obTable->timestamp('fired_at');
            $obTable->timestamps();

            // 5-column UNIQUE race-fence. NULL site_id distinct under MySQL UNIQUE.
            $obTable->unique(
                ['subject_type', 'subject_id', 'event_name', 'channel', 'site_id'],
                'metapixel_event_log_subject_event_channel_unique',
            );

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

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
}
