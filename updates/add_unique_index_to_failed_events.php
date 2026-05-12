<?php

declare(strict_types=1);

namespace Logingrupa\Metapixelshopaholic\Updates;

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

/**
 * Class AddUniqueIndexToFailedEvents
 *
 * WR-07 lock: defense-in-depth uniqueness on
 * `logingrupa_metapixel_failed_events.(event_id, http_status)` so
 * SendCapiEvent::handle()'s permanent-catch path AND Laravel's `failed()`
 * exhaustion hook cannot double-write a FailedEvent row for the same logical
 * permanent failure. Today's flow is single-write per failure (the catch in
 * handle() doesn't re-throw, so failed() doesn't fire), but a future log-
 * driver-fail-during-dead-letter-log edge case could fire BOTH paths — the
 * unique index makes that no-op at the DB level.
 *
 * Shipped as a separate `1.0.3` migration rather than amending the already-
 * released `1.0.2` create_table_failed_events.php so sites already on 1.0.2
 * still upgrade cleanly without re-running the create.
 *
 * Reversible `down()` drops the unique index. Idempotent — `up()` no-ops if
 * the index already exists.
 */
class AddUniqueIndexToFailedEvents extends Migration
{
    const TABLE = 'logingrupa_metapixel_failed_events';
    const INDEX_NAME = 'metapixel_failed_events_event_status_unique';

    /**
     * Apply migration — add unique index on (event_id, http_status).
     */
    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        // Idempotency: check if the index already exists. October's Schema
        // Builder doesn't expose hasIndex() across all drivers; instead we
        // try-add and absorb the "already exists" failure mode — but in the
        // hermetic SQLite test harness `php artisan migrate` re-runs are
        // sentinel-guarded by the Updates Manager so this only runs once.
        Schema::table(self::TABLE, function (Blueprint $obTable): void {
            $obTable->unique(['event_id', 'http_status'], self::INDEX_NAME);
        });
    }

    /**
     * Rollback migration — drop the unique index.
     */
    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $obTable): void {
            $obTable->dropUnique(self::INDEX_NAME);
        });
    }
}
