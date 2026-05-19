<?php

namespace Logingrupa\Metapixel\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

/**
 * Schema-additive only; Multisite routes per-site via row layer, not column
 * layer. The system_settings table already carries site_id + site_root_id
 * from October core; this migration exists for marketplace install-log
 * traceability of MULT-06 — operators can grep AddMultisitePixelIdAndToken
 * to confirm the upgrade ran. up() is a guard-only no-op; down() is empty.
 */
class AddMultisitePixelIdAndToken extends Migration
{
    public const TABLE = 'system_settings';

    public function up()
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }
        // No-op: row-layer routing requires no schema change here.
    }

    public function down()
    {
        // No-op.
    }
}
