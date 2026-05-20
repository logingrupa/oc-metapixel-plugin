<?php

namespace Logingrupa\Metapixel\Updates;

use Illuminate\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

/**
 * Additive migration adding dedup-status columns to the FailedEvents table:
 * dedup_pct DECIMAL(5,2), emq DECIMAL(4,2), dedup_checked_at DATETIME — all
 * nullable. Populated by Controllers\FailedEvents::onCheckDedup which calls
 * MetaClient::fetchTestEventsStatus against the Meta Dataset Quality endpoint.
 *
 * up() + down() are idempotent — re-running either is a no-op when the
 * columns are already in/out of the table.
 */
class AddDedupColumnsToFailedEvents extends Migration
{
    public const TABLE = 'logingrupa_metapixel_failed_events';

    public function up()
    {
        if (Schema::hasColumn(self::TABLE, 'dedup_pct')) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $obTable): void {
            $obTable->decimal('dedup_pct', 5, 2)->nullable()->after('graph_error');
            $obTable->decimal('emq', 4, 2)->nullable()->after('dedup_pct');
            $obTable->dateTime('dedup_checked_at')->nullable()->after('emq');
        });
    }

    public function down()
    {
        if (! Schema::hasColumn(self::TABLE, 'dedup_pct')) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $obTable): void {
            $obTable->dropColumn(['dedup_pct', 'emq', 'dedup_checked_at']);
        });
    }
}
