<?php

namespace Logingrupa\Metapixel\Updates;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use October\Rain\Database\Updates\Migration;
use Schema;

/**
 * Drops the per-row dataset quality columns (dedup_pct, emq,
 * dedup_checked_at) and adds replayed_at. Rows whose graph_error is already
 * null were replayed before this migration, so they receive updated_at as
 * their replayed_at.
 *
 * up() + down() are idempotent.
 */
class ReplaceDedupColumnsWithReplayedAt extends Migration
{
    public const TABLE = 'logingrupa_metapixel_failed_events';

    public function up()
    {
        if (Schema::hasColumn(self::TABLE, 'dedup_pct')) {
            Schema::table(self::TABLE, function (Blueprint $obTable): void {
                $obTable->dropColumn(['dedup_pct', 'emq', 'dedup_checked_at']);
            });
        }

        if (Schema::hasColumn(self::TABLE, 'replayed_at')) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $obTable): void {
            $obTable->dateTime('replayed_at')->nullable()->after('graph_error');
        });

        DB::table(self::TABLE)
            ->whereNull('graph_error')
            ->update(['replayed_at' => DB::raw('updated_at')]);
    }

    public function down()
    {
        if (Schema::hasColumn(self::TABLE, 'replayed_at')) {
            Schema::table(self::TABLE, function (Blueprint $obTable): void {
                $obTable->dropColumn('replayed_at');
            });
        }

        if (Schema::hasColumn(self::TABLE, 'dedup_pct')) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $obTable): void {
            $obTable->decimal('dedup_pct', 5, 2)->nullable()->after('graph_error');
            $obTable->decimal('emq', 4, 2)->nullable()->after('dedup_pct');
            $obTable->dateTime('dedup_checked_at')->nullable()->after('emq');
        });
    }
}
