<?php

namespace Logingrupa\Metapixel\Updates;

use Illuminate\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

/**
 * Append-only event log + UNIQUE race-fence for browser/server dedup.
 *
 * UNIQUE on (subject_type, subject_id, event_name, channel, site_id) gates
 * EventLogWriter::record() — a concurrent second insert returns false rather
 * than firing the same Meta event twice.
 */
class CreateMetapixelEventLogTable extends Migration
{
    public const TABLE = 'logingrupa_metapixel_event_log';

    public function up()
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $obTable) {
            $obTable->engine = 'InnoDB';
            $obTable->bigIncrements('id');
            $obTable->string('event_id', 36);
            $obTable->string('event_name', 64);
            $obTable->string('channel', 16);
            $obTable->string('subject_type', 255);
            $obTable->unsignedInteger('subject_id');
            $obTable->string('secret_key', 64)->nullable();
            $obTable->unsignedInteger('site_id')->nullable();
            $obTable->unsignedBigInteger('event_time');
            $obTable->timestamp('fired_at')->nullable();
            $obTable->timestamps();

            $obTable->unique(
                ['subject_type', 'subject_id', 'event_name', 'channel', 'site_id'],
                'metapixel_event_log_subject_channel_site_unique'
            );

            $obTable->index('event_id', 'metapixel_event_log_event_id_index');
            $obTable->index(
                ['secret_key', 'event_name', 'channel', 'site_id'],
                'metapixel_event_log_secret_key_event_index'
            );
            $obTable->index(
                ['subject_type', 'subject_id', 'site_id'],
                'metapixel_event_log_subject_site_index'
            );
        });
    }

    public function down()
    {
        Schema::dropIfExists(self::TABLE);
    }
}
