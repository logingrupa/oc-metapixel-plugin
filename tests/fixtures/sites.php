<?php

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use October\Rain\Support\Facades\Site;

/**
 * Hermetic 2-site fixture for MULT-05 routing tests. Creates a minimal
 * `system_site_definitions` table (October core columns subset) and seeds
 * rows id=1 + id=2 so `Site::withContext(1, fn)` and `Site::withContext(2, fn)`
 * both resolve. Resets the SiteManager in-memory cache after seeding so the
 * test sees the freshly-inserted rows.
 *
 * @return callable(SchemaBuilder, ConnectionInterface): void
 */
return static function (SchemaBuilder $obSchema, ConnectionInterface $obConn): void {
    if (! $obSchema->hasTable('system_site_definitions')) {
        $obSchema->create('system_site_definitions', static function ($obTable): void {
            $obTable->increments('id');
            $obTable->string('name')->nullable();
            $obTable->string('code')->index()->nullable();
            $obTable->integer('sort_order')->nullable();
            $obTable->boolean('is_custom_url')->default(0);
            $obTable->string('app_url')->nullable();
            $obTable->string('theme')->nullable();
            $obTable->string('locale')->nullable();
            $obTable->string('fallback_locale')->nullable();
            $obTable->string('timezone')->nullable();
            $obTable->boolean('is_host_restricted')->default(0);
            $obTable->mediumText('allow_hosts')->nullable();
            $obTable->boolean('is_prefixed')->default(0);
            $obTable->string('route_prefix')->nullable();
            $obTable->boolean('is_styled')->default(0);
            $obTable->string('color_foreground')->nullable();
            $obTable->string('color_background')->nullable();
            $obTable->boolean('is_role_restricted')->default(0);
            $obTable->mediumText('allow_roles')->nullable();
            $obTable->boolean('is_primary')->default(0);
            $obTable->boolean('is_enabled')->default(0);
            $obTable->boolean('is_enabled_edit')->default(0);
            $obTable->timestamps();
        });
    }

    $obConn->table('system_site_definitions')->insert([
        [
            'id' => 1,
            'name' => 'Site One',
            'code' => 'site-one',
            'sort_order' => 1,
            'is_primary' => 1,
            'is_enabled' => 1,
            'is_enabled_edit' => 1,
            'created_at' => '2026-05-19 00:00:00',
            'updated_at' => '2026-05-19 00:00:00',
        ],
        [
            'id' => 2,
            'name' => 'Site Two',
            'code' => 'site-two',
            'sort_order' => 2,
            'is_primary' => 0,
            'is_enabled' => 1,
            'is_enabled_edit' => 1,
            'created_at' => '2026-05-19 00:00:00',
            'updated_at' => '2026-05-19 00:00:00',
        ],
    ]);

    // Drop SiteManager's in-memory + Manifest cache so listSites() re-queries
    // the freshly-seeded rows.
    Site::resetCache();
};
