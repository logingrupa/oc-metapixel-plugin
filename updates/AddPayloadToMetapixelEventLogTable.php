<?php

namespace Logingrupa\Metapixel\Updates;

use Illuminate\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

/**
 * Additive migration adding the EventLog `payload` longText NULL column.
 *
 * Phase 3 D-06 lock — Phase 2 base migration CreateMetapixelEventLogTable is
 * NOT amended; the payload column lands via a separate migration so the
 * marketplace fresh-install picks it up via October's standard
 * `october:migrate` ordering. `up()` + `down()` are idempotent — re-running
 * either is a no-op when the column is already in/out of the table.
 */
class AddPayloadToMetapixelEventLogTable extends Migration
{
    public const TABLE = 'logingrupa_metapixel_event_log';

    public function up()
    {
        if (Schema::hasColumn(self::TABLE, 'payload')) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $obTable): void {
            $obTable->longText('payload')->nullable()->after('event_time');
        });
    }

    public function down()
    {
        if (! Schema::hasColumn(self::TABLE, 'payload')) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $obTable): void {
            $obTable->dropColumn('payload');
        });
    }
}
