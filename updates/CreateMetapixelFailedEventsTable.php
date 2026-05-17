<?php

namespace Logingrupa\Metapixel\Updates;

use Illuminate\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

/**
 * Dead-letter queue for permanently failed CAPI dispatches.
 *
 * UNIQUE on (event_id, http_status) prevents flooding when one event retries
 * against the same failure mode — a second 400 for the same event_id is a
 * no-op insertOrIgnore. subject_type + subject_id columns (nullable) are
 * populated by SendCapiEvent.writeFailedEvent when the adapter is resolvable;
 * Phase 4 admin UI uses them for re-resolution.
 */
class CreateMetapixelFailedEventsTable extends Migration
{
    public const TABLE = 'logingrupa_metapixel_failed_events';

    public function up()
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $obTable) {
            $obTable->engine = 'InnoDB';
            $obTable->increments('id');
            $obTable->string('event_id', 36);
            $obTable->string('event_name', 64);
            $obTable->string('adapter_type', 255)->nullable();
            $obTable->string('subject_type', 255)->nullable();
            $obTable->unsignedInteger('subject_id')->nullable();
            $obTable->longText('payload');
            $obTable->text('graph_error')->nullable();
            $obTable->unsignedSmallInteger('http_status')->nullable();
            $obTable->unsignedInteger('attempts')->default(0);
            $obTable->timestamps();

            $obTable->unique(
                ['event_id', 'http_status'],
                'metapixel_failed_events_event_status_unique'
            );

            $obTable->index('event_name', 'metapixel_failed_events_event_name_index');
            $obTable->index('adapter_type', 'metapixel_failed_events_adapter_type_index');
            $obTable->index('http_status', 'metapixel_failed_events_http_status_index');
        });
    }

    public function down()
    {
        Schema::dropIfExists(self::TABLE);
    }
}
