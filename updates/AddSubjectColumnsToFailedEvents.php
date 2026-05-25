<?php

namespace Logingrupa\Metapixel\Updates;

use Illuminate\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

/**
 * Additive migration adding adapter_type / subject_type / subject_id columns to
 * the FailedEvents table. The original CreateMetapixelFailedEventsTable
 * migration declared these columns from v2.0 onward, but v1.x deploys
 * recorded that file as already-applied in system_plugin_history under
 * version 1.0.0 and the modified Create migration is skipped on re-run —
 * v2.0's edit-in-place is invisible to October's tracker.
 *
 * Required by SendCapiEvent::writeFailedEvent which calls
 * FailedEvent::create(['adapter_type' => ..., 'subject_type' => ...,
 * 'subject_id' => ...]). Without these columns every dead-letter insert
 * throws QueryException and the dispatch is silently lost.
 *
 * up() + down() are idempotent — re-running either is a no-op when the
 * columns are already in/out of the table.
 */
class AddSubjectColumnsToFailedEvents extends Migration
{
    public const TABLE = 'logingrupa_metapixel_failed_events';

    public function up()
    {
        if (Schema::hasColumn(self::TABLE, 'subject_type')) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $obTable): void {
            $obTable->string('adapter_type', 255)->nullable()->after('event_name');
            $obTable->string('subject_type', 255)->nullable()->after('adapter_type');
            $obTable->unsignedInteger('subject_id')->nullable()->after('subject_type');
            $obTable->index('adapter_type', 'metapixel_failed_events_adapter_type_index');
        });
    }

    public function down()
    {
        if (! Schema::hasColumn(self::TABLE, 'subject_type')) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $obTable): void {
            $obTable->dropIndex('metapixel_failed_events_adapter_type_index');
            $obTable->dropColumn(['adapter_type', 'subject_type', 'subject_id']);
        });
    }
}
