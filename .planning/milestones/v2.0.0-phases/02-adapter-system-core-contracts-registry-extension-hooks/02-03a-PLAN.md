---
phase: 02-adapter-system-core-contracts-registry-extension-hooks
plan: 3a
slug: storage-models-migrations
type: execute
wave: 2
depends_on:
  - 02-01
files_modified:
  - plugins/logingrupa/metapixel/updates/version.yaml
  - plugins/logingrupa/metapixel/updates/create_metapixel_event_log_table.php
  - plugins/logingrupa/metapixel/updates/create_metapixel_failed_events_table.php
  - plugins/logingrupa/metapixel/models/EventLog.php
  - plugins/logingrupa/metapixel/models/FailedEvent.php
  - plugins/logingrupa/metapixel/composer.json
  - plugins/logingrupa/metapixel/phpstan.neon
  - plugins/logingrupa/metapixel/tests/Feature/Models/EventLogModelTest.php
  - plugins/logingrupa/metapixel/tests/Feature/Models/FailedEventModelTest.php
  - plugins/logingrupa/metapixel/tests/Feature/Migrations/EventLogMigrationTest.php
  - plugins/logingrupa/metapixel/tests/Feature/Migrations/FailedEventsMigrationTest.php
autonomous: true
requirements: []
maps_to:
  pitfalls:
    - P-05
  decisions:
    - D-01
    - D-04
    - D-05
    - D-06
    - D-07
must_haves:
  truths:
    - "`logingrupa_metapixel_event_log` table exists after `php artisan october:up` with a UNIQUE constraint on `(subject_type, subject_id, event_name, channel, site_id)`."
    - "`logingrupa_metapixel_failed_events` table exists after `october:up` with a UNIQUE constraint on `(event_id, http_status)`."
    - "`EventLog` model has fillable + casts shape matching the migration columns and does NOT declare a MorphTo relation (subject_type is an opaque alias, not a class FQN — P-05 anchor)."
    - "`FailedEvent` model has fillable + casts shape and casts `payload` as array. Has `subject_type` + `subject_id` columns populated by plan 02-06 SendCapiEvent.writeFailedEvent when the adapter is resolvable (H-2 enables Phase 4 admin UI re-resolution)."
    - "phpstan.neon `paths:` adds `models` (already had `Plugin.php` + `classes` from plan 02-01)."
    - "composer.json autoload-dev gains `classmap: [\"updates/\"]` so migration test classes resolve under PSR-4 (Task 5 spike confirms; fallback: rename to PascalCase filenames if classmap fails)."
    - "T25–T28 from RESEARCH §6 pass: EventLogModelTest, FailedEventModelTest, EventLogMigrationTest, FailedEventsMigrationTest."
    - "`composer qa` exits 0 from `plugins/logingrupa/metapixel/`."
  artifacts:
    - path: "plugins/logingrupa/metapixel/updates/create_metapixel_event_log_table.php"
      provides: "EventLog migration with UNIQUE race-fence constraint."
      contains: "UNIQUE"
    - path: "plugins/logingrupa/metapixel/updates/create_metapixel_failed_events_table.php"
      provides: "FailedEvent dead-letter table migration."
      contains: "logingrupa_metapixel_failed_events"
    - path: "plugins/logingrupa/metapixel/updates/version.yaml"
      provides: "OctoberCMS-tracked migration manifest."
      contains: "create_metapixel_event_log_table"
    - path: "plugins/logingrupa/metapixel/models/EventLog.php"
      provides: "Append-only event log model."
      contains: "class EventLog"
    - path: "plugins/logingrupa/metapixel/models/FailedEvent.php"
      provides: "Dead-letter model (admin UI Phase 4); subject_type + subject_id columns enable Phase 4 re-resolution (H-2)."
      contains: "class FailedEvent"
  key_links:
    - from: "plugins/logingrupa/metapixel/updates/version.yaml"
      to: "plugins/logingrupa/metapixel/updates/create_metapixel_event_log_table.php"
      via: "version manifest migration entry"
      pattern: "create_metapixel_event_log_table\\.php"
    - from: "plugins/logingrupa/metapixel/models/EventLog.php"
      to: "logingrupa_metapixel_event_log table"
      via: "$table property + fillable shape"
      pattern: "logingrupa_metapixel_event_log"
---

<objective>
Ship the Phase 2 storage layer: two fresh October 4 migrations + 2 models. This is the first half of the M-2 split (plan-checker R1 — 02-03 was 6 tasks / 25+ files which exceeded the planner scope budget). Plan 02-03b ships in parallel (Settings + PluginGuard + exceptions + lang files + Plugin::registerSettings).

Purpose: every downstream Phase 2 class needs this storage layer. `EventLogWriter` (plan 02-04) writes the EventLog row. `SendCapiEvent` (plan 02-06) writes FailedEvent on permanent failure (with subject_type + subject_id populated when adapter is resolvable — H-2).

Output: 2 migrations + 1 version.yaml + 2 models + 1 phpstan path edit + 1 composer.json classmap edit + 4 test files.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@plugins/logingrupa/metapixel/CLAUDE.md
@plugins/logingrupa/metapixel/.planning/PROJECT.md
@plugins/logingrupa/metapixel/.planning/ROADMAP.md
@plugins/logingrupa/metapixel/.planning/REQUIREMENTS.md
@plugins/logingrupa/metapixel/.planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-CONTEXT.md
@plugins/logingrupa/metapixel/.planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-RESEARCH.md
@plugins/logingrupa/metapixel/Plugin.php
@plugins/logingrupa/metapixel/phpstan.neon
@plugins/logingrupa/metapixel/tests/MetapixelTestCase.php

<interfaces>
Locked decisions (D-04..D-07 from 02-CONTEXT.md):

- 2 separate tables, fresh October 4 migration syntax. No `legacy/v1.1.1` migration files reused.
- `logingrupa_metapixel_event_log` — success log + race-fence. Columns: id, subject_type (string, opaque alias), subject_id (bigint), event_name (string), channel (enum capi|pixel as string), site_id (int, nullable), event_id (UUID, indexed), event_time (int Unix seconds), secret_key (string nullable), fired_at, created_at, updated_at. UNIQUE on (subject_type, subject_id, event_name, channel, site_id).
- `logingrupa_metapixel_failed_events` — dead-letter queue. Columns: id, event_id, event_name, adapter_type (string nullable), subject_type (nullable — populated by SendCapiEvent.writeFailedEvent when adapter is resolvable per H-2), subject_id (nullable — same), payload (json/longText), http_status (smallInt unsigned nullable), graph_error (text nullable), attempts (int default 0), created_at, updated_at. UNIQUE on (event_id, http_status).
- Phase 2 ships table + minimal model only for FailedEvent. Admin UI + Replay/CheckDedup land in Phase 4 (FAIL-01..03).

RESEARCH §4.12–§4.14 backbone shapes:

- **EventLog model** (§4.12): plain `October\Rain\Database\Model`. `$table = 'logingrupa_metapixel_event_log'`. `$fillable = ['event_id', 'event_name', 'channel', 'subject_type', 'subject_id', 'secret_key', 'site_id', 'event_time', 'fired_at']`. `$casts = ['subject_id' => 'int', 'site_id' => 'int', 'event_time' => 'int']`. Constants `CHANNEL_CAPI = 'capi'`, `CHANNEL_PIXEL = 'pixel'`. No `subject()` MorphTo (P-05 — subject_type is alias, not class FQN). `scopeForSubject($obQuery, string $sSubjectType, int $iSubjectId)`.
- **FailedEvent model** (§4.13): plain `October\Rain\Database\Model`. `$table = 'logingrupa_metapixel_failed_events'`. `$fillable = ['event_id', 'event_name', 'adapter_type', 'subject_type', 'subject_id', 'payload', 'http_status', 'graph_error', 'attempts']`. `$casts = ['payload' => 'array', 'attempts' => 'int', 'http_status' => 'int']`.

Migration filename pattern (October 4): the file MUST be inside `updates/` with a name that `version.yaml` references. Lovata-style precedent uses descriptive names without a numeric prefix. Filenames: `create_metapixel_event_log_table.php`, `create_metapixel_failed_events_table.php`.

version.yaml format:

```
1.0.0:
    - Initial release.
    - create_metapixel_event_log_table.php
    - create_metapixel_failed_events_table.php
```

`[VERIFIED: Lovata.Shopaholic updates/version.yaml — multi-version manifest with migration file references on indented lines under each semver key.]`

PHP 8.3/8.4 dual rule reminder from plugin CLAUDE.md:
- No property hooks (`public string $field { get => ... }`).
- No asymmetric visibility (`public private(set) string $field`).
- No `array_find/any/all/find_key`.
- No `#[\Deprecated]`.
- `declare(strict_types=1)` optional (don't add).

PHPMD `ShortVariable min=4` — all locals use Hungarian prefix (`$obTable`, `$iAffected`, `$sNow`).

H-2 boundary (relevant to FailedEvent shape, implemented in plan 02-06): `writeFailedEvent` in SendCapiEvent accepts `?EventSubjectAdapter $obAdapter = null` and populates `subject_type` + `subject_id` from the adapter when non-null. The BindingResolutionException path passes null (legitimate — adapter does not exist). Every other call site passes the resolved adapter. This plan ships the columns + model; plan 02-06 ships the populating logic.
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Write 2 migrations + version.yaml</name>
  <files>
    plugins/logingrupa/metapixel/updates/version.yaml
    plugins/logingrupa/metapixel/updates/create_metapixel_event_log_table.php
    plugins/logingrupa/metapixel/updates/create_metapixel_failed_events_table.php
  </files>
  <behavior>
    - `version.yaml` declares `1.0.0` initial version with both migration files listed.
    - `create_metapixel_event_log_table.php` defines `class CreateMetapixelEventLogTable extends Migration` with `up()` creating the table per §4.14 + `down()` doing `Schema::dropIfExists`. `up()` is idempotent (`if (Schema::hasTable(...)) return`).
    - `create_metapixel_failed_events_table.php` defines `class CreateMetapixelFailedEventsTable extends Migration` with parallel structure.
    - Both migrations use Hungarian-notation parameters (`$obTable`).
    - Both migrations declare `const TABLE = '...'` for the table name.
    - UNIQUE constraints have explicit names ≤ 64 chars (MySQL hard limit).
    - php -l clean on both files.
  </behavior>
  <action>
Create `updates/version.yaml`:

```
1.0.0:
    - Initial release.
    - create_metapixel_event_log_table.php
    - create_metapixel_failed_events_table.php
```

Create `updates/create_metapixel_event_log_table.php` per the §4.14 shape: namespace `Logingrupa\Metapixel\Updates`; class `CreateMetapixelEventLogTable extends Migration`; `const TABLE = 'logingrupa_metapixel_event_log'`; `up()` idempotency guard via `Schema::hasTable(self::TABLE)` early return; Schema::create with InnoDB engine + bigIncrements id + string columns (event_id 36, event_name 64, channel 16, subject_type 255) + unsignedInteger subject_id + nullable string secret_key 64 + nullable unsignedInteger site_id + unsignedBigInteger event_time + timestamp fired_at + standard timestamps; UNIQUE constraint name `metapixel_event_log_subject_channel_site_unique` on `(subject_type, subject_id, event_name, channel, site_id)`; supporting indexes for event_id + (secret_key, event_name, channel, site_id) + (subject_type, subject_id, site_id); `down()` is `Schema::dropIfExists(self::TABLE)`.

Create `updates/create_metapixel_failed_events_table.php` per the §4.14 shape: same imports + namespace; class `CreateMetapixelFailedEventsTable extends Migration`; `const TABLE = 'logingrupa_metapixel_failed_events'`; same idempotency guard; Schema::create with InnoDB + increments id + string event_id 36 + string event_name 64 + nullable string adapter_type 255 + nullable string subject_type 255 + nullable unsignedInteger subject_id + longText payload + nullable text graph_error + nullable unsignedSmallInteger http_status + unsignedInteger attempts default 0 + standard timestamps; UNIQUE constraint name `metapixel_failed_events_event_status_unique` on `(event_id, http_status)`; supporting indexes on event_name + adapter_type + http_status; `down()` symmetric.

The `subject_type` + `subject_id` columns on failed_events are populated by plan 02-06 SendCapiEvent.writeFailedEvent when adapter resolution succeeds (H-2 — enables Phase 4 admin UI re-resolution path). Phase 2 ships the columns + model fillable entries; the populating logic lives in plan 02-06.

Note: index names match the MySQL 64-char limit (e.g. `metapixel_event_log_subject_channel_site_unique` = 47 chars).

SQLite-in-memory test env: SQLite ignores `engine = 'InnoDB'` and treats UNIQUE NULL columns as distinct (matches MySQL InnoDB behavior — fence works identically in both).
  </action>
  <verify>
    <automated>test -f plugins/logingrupa/metapixel/updates/version.yaml &amp;&amp; test -f plugins/logingrupa/metapixel/updates/create_metapixel_event_log_table.php &amp;&amp; test -f plugins/logingrupa/metapixel/updates/create_metapixel_failed_events_table.php &amp;&amp; php -l plugins/logingrupa/metapixel/updates/create_metapixel_event_log_table.php | grep -q 'No syntax errors' &amp;&amp; php -l plugins/logingrupa/metapixel/updates/create_metapixel_failed_events_table.php | grep -q 'No syntax errors' &amp;&amp; grep -q '1.0.0:' plugins/logingrupa/metapixel/updates/version.yaml &amp;&amp; grep -q 'metapixel_event_log_subject_channel_site_unique' plugins/logingrupa/metapixel/updates/create_metapixel_event_log_table.php &amp;&amp; grep -q 'metapixel_failed_events_event_status_unique' plugins/logingrupa/metapixel/updates/create_metapixel_failed_events_table.php</automated>
  </verify>
  <done>Both migration files exist + php -l clean; version.yaml declares 1.0.0; UNIQUE constraint names present.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Write EventLog + FailedEvent models</name>
  <files>
    plugins/logingrupa/metapixel/models/EventLog.php
    plugins/logingrupa/metapixel/models/FailedEvent.php
  </files>
  <behavior>
    - Both models extend `October\Rain\Database\Model`.
    - `EventLog` has `$table`, `$fillable`, `$casts`, constants `CHANNEL_CAPI`, `CHANNEL_PIXEL`, `scopeForSubject` query scope.
    - `EventLog` has NO `subject()` MorphTo (P-05 — alias is not a class FQN).
    - `FailedEvent` has `$table`, `$fillable` (9 keys including `subject_type` + `subject_id` for H-2), `$casts` (payload → array).
    - Both files php -l clean.
    - Both namespace `Logingrupa\Metapixel\Models`.
  </behavior>
  <action>
Create `models/EventLog.php`: class extends `October\Rain\Database\Model`; `$table = 'logingrupa_metapixel_event_log'`; `public const CHANNEL_CAPI = 'capi'`; `public const CHANNEL_PIXEL = 'pixel'`; `$fillable` lists the 9 columns from §4.12; `$casts` casts subject_id/site_id/event_time to int; `scopeForSubject($obQuery, string $sSubjectType, int $iSubjectId)` returns a where-clause-narrowed builder. Short Laravel docblock on the class (one-line paragraph explaining append-only + alias-not-FQN). PHPDoc on scopeForSubject documents the opaque-alias filter pattern.

Create `models/FailedEvent.php`: class extends `October\Rain\Database\Model`; `$table = 'logingrupa_metapixel_failed_events'`; `$fillable` lists the 9 columns including `subject_type` + `subject_id` (H-2 — these columns are populated by plan 02-06's writeFailedEvent when the adapter is resolvable); `$casts` casts payload to array + attempts/http_status to int. Short class docblock noting Phase 4 ships admin UI (FAIL-01..03); Phase 2 ships only table + model.

No phase markers / CR markers / class-level "v1.x had …" narration. Short Laravel docblocks only.
  </action>
  <verify>
    <automated>test -f plugins/logingrupa/metapixel/models/EventLog.php &amp;&amp; test -f plugins/logingrupa/metapixel/models/FailedEvent.php &amp;&amp; php -l plugins/logingrupa/metapixel/models/EventLog.php | grep -q 'No syntax errors' &amp;&amp; php -l plugins/logingrupa/metapixel/models/FailedEvent.php | grep -q 'No syntax errors' &amp;&amp; grep -q 'CHANNEL_CAPI' plugins/logingrupa/metapixel/models/EventLog.php &amp;&amp; grep -q 'scopeForSubject' plugins/logingrupa/metapixel/models/EventLog.php &amp;&amp; ! grep -q 'morphTo' plugins/logingrupa/metapixel/models/EventLog.php &amp;&amp; grep -q "'payload' => 'array'" plugins/logingrupa/metapixel/models/FailedEvent.php &amp;&amp; grep -q "'subject_type'" plugins/logingrupa/metapixel/models/FailedEvent.php &amp;&amp; grep -q "'subject_id'" plugins/logingrupa/metapixel/models/FailedEvent.php</automated>
  </verify>
  <done>Both model files exist + php -l clean; EventLog has CHANNEL_CAPI constant + scopeForSubject + NO morphTo; FailedEvent fillable includes subject_type + subject_id (H-2) + casts payload to array.</done>
</task>

<task type="auto">
  <name>Task 3: Update phpstan paths + composer.json classmap for updates/</name>
  <files>
    plugins/logingrupa/metapixel/phpstan.neon
    plugins/logingrupa/metapixel/composer.json
  </files>
  <action>
Edit `phpstan.neon` paths block. Plan 02-01 added `classes`. This plan adds `models`:

```
paths:
    - Plugin.php
    - classes
    - models
```

If plan 02-02's edits already turned the paths block into a multi-line one, this is a one-line add. If the block is still single-line, expand it.

Edit `composer.json` autoload-dev to add a classmap entry for `updates/`:

```
"autoload-dev": {
    "psr-4": {
        "Logingrupa\\Metapixel\\Tests\\": "tests/"
    },
    "classmap": [
        "updates/"
    ]
}
```

Run `composer dump-autoload --no-interaction` from the plugin dir so the classmap picks up the migration class FQNs by class name (not file path). This is what Lovata.Toolbox does (`plugins/lovata/toolbox/composer.json` has `classmap` for its updates dir).

H-5 spike (Task 5 — composer qa run): if `composer dump-autoload --classmap-authoritative` fails to resolve `Logingrupa\Metapixel\Updates\CreateMetapixelEventLogTable::class` (because snake_case files don't match PSR-4 but classmap is dev-only), fall back to renaming the migration files to PascalCase (`CreateMetapixelEventLogTable.php`) + update version.yaml entries to match. Validation deferred to Task 5's composer dump-autoload smoke.
  </action>
  <verify>
    <automated>grep -E '^\s+- models\s*$' plugins/logingrupa/metapixel/phpstan.neon &amp;&amp; grep -q '"classmap"' plugins/logingrupa/metapixel/composer.json &amp;&amp; cd plugins/logingrupa/metapixel &amp;&amp; composer validate --no-check-publish 2&gt;&amp;1 | tail -3 | grep -qE '(valid|Composer schema)'</automated>
  </verify>
  <done>phpstan paths include models; composer.json autoload-dev gains classmap for updates/; composer.json schema valid.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 4: Write 4 storage-layer tests (T25–T28)</name>
  <files>
    plugins/logingrupa/metapixel/tests/Feature/Models/EventLogModelTest.php
    plugins/logingrupa/metapixel/tests/Feature/Models/FailedEventModelTest.php
    plugins/logingrupa/metapixel/tests/Feature/Migrations/EventLogMigrationTest.php
    plugins/logingrupa/metapixel/tests/Feature/Migrations/FailedEventsMigrationTest.php
  </files>
  <behavior>
    - T25 `EventLogModelTest::test_*` — fillable shape matches the migration; no `subject()` MorphTo method exists; `scopeForSubject` returns a query builder.
    - T26 `FailedEventModelTest::test_*` — fillable shape (9 keys including subject_type + subject_id); `payload` cast to array.
    - T27 `EventLogMigrationTest::test_*` — run up() + verify Schema::hasTable; verify columns via `Schema::getColumnListing`; assert UNIQUE blocks duplicates by `insertOrIgnore` returning 0; up() is idempotent (second call no-throw).
    - T28 `FailedEventsMigrationTest::test_*` — same pattern for failed_events; insert two rows with same event_id but different http_status → both succeed; insert duplicate (400, 400) → blocked.
    - All tests pass.
  </behavior>
  <action>
Follow Phase 1 PluginSanityTest's class-style convention (L-8 — classic Pest). Hungarian-notation locals. No phase markers.

EventLogModelTest: assert fillable equals expected 9-key list (sort both before assertSame); assert CHANNEL_CAPI/CHANNEL_PIXEL constants; assert `! method_exists(EventLog::class, 'subject')` (P-05 enforcement); assert `scopeForSubject` returns an `October\Rain\Database\Builder` instance via `(new EventLog)->newQuery()->forSubject('shopaholic.order', 42)`.

FailedEventModelTest: assert fillable equals expected 9-key list (including subject_type + subject_id — H-2); assert payload cast to array via `$obFailed = new FailedEvent; $obFailed->payload = ['data' => [['event_name' => 'Purchase']]]; assertIsArray + same data`.

EventLogMigrationTest:
- `test_up_creates_table_with_required_columns` — `(new CreateMetapixelEventLogTable)->up()`; assertTrue Schema::hasTable('logingrupa_metapixel_event_log'); loop the 8 expected columns + assertTrue Schema::hasColumn; `(new CreateMetapixelEventLogTable)->down()`; assertFalse Schema::hasTable.
- `test_unique_constraint_blocks_duplicate_inserts` — up(); insert row via `DB::table()->insert([...])`; `$iAffected = DB::table()->insertOrIgnore([same row])`; assertSame(0, $iAffected); down().
- `test_up_is_idempotent` — up(); second up() must not throw; assertTrue Schema::hasTable; down().

FailedEventsMigrationTest: parallel structure. Test the UNIQUE on (event_id, http_status) explicitly — insert two rows with same event_id but http_status 400 and 500 → both inserted (assertSame(2, count())); insert duplicate (400, 400) → insertOrIgnore returns 0.

All 4 test files use H-8 setUp pattern: `$this->app->singleton(AdapterRegistry::class)` (even if these tests don't use the registry, they inherit the project-wide setUp idiom for consistency). Each test body that interacts with the table runs the migration up() at start + down() at end (or in tearDown). MetapixelTestCase's existing tearDown handles the broader cleanup.

`composer dump-autoload --no-interaction` runs before pest execution so the classmap picks up the migration classes.

NO inline test fixture classes — these tests work with the migration + model directly, no adapter / subject fixtures needed.
  </action>
  <verify>
    <automated>for f in plugins/logingrupa/metapixel/tests/Feature/Models/EventLogModelTest.php plugins/logingrupa/metapixel/tests/Feature/Models/FailedEventModelTest.php plugins/logingrupa/metapixel/tests/Feature/Migrations/EventLogMigrationTest.php plugins/logingrupa/metapixel/tests/Feature/Migrations/FailedEventsMigrationTest.php; do test -f "$f" || { echo "missing $f"; exit 1; }; php -l "$f" | grep -q 'No syntax errors' || exit 1; done &amp;&amp; cd plugins/logingrupa/metapixel &amp;&amp; composer dump-autoload --no-interaction 2&gt;&amp;1 | grep -qE '(Generated|generated)' &amp;&amp; ../../../vendor/bin/pest tests/Feature/Models tests/Feature/Migrations --configuration phpunit.xml 2&gt;&amp;1 | tail -10 | grep -Eq '(PASS|OK|Tests:.*passed|passed)'</automated>
  </verify>
  <done>4 test files exist + php -l clean; composer dump-autoload re-runs; pest run on the 4 test files exits 0.</done>
</task>

<task type="auto">
  <name>Task 5: composer qa + commit (with H-5 classmap spike)</name>
  <files>
    plugins/logingrupa/metapixel/updates/
    plugins/logingrupa/metapixel/models/
    plugins/logingrupa/metapixel/phpstan.neon
    plugins/logingrupa/metapixel/composer.json
    plugins/logingrupa/metapixel/composer.lock
    plugins/logingrupa/metapixel/tests/Feature/Models/
    plugins/logingrupa/metapixel/tests/Feature/Migrations/
  </files>
  <action>
From `plugins/logingrupa/metapixel/`:

```
composer dump-autoload --classmap-authoritative --no-interaction
php -r 'require_once "vendor/autoload.php"; class_exists("Logingrupa\\Metapixel\\Updates\\CreateMetapixelEventLogTable") || exit(1); echo "ok\n";'
composer qa 2>&1 | tee /tmp/02-03a-qa.log | tail -30
```

H-5 spike branch: if the class_exists check fails, the classmap-only autoload didn't resolve the snake_case migration filenames. Fall back to renaming the migration files to PascalCase:

```
mv updates/create_metapixel_event_log_table.php updates/CreateMetapixelEventLogTable.php
mv updates/create_metapixel_failed_events_table.php updates/CreateMetapixelFailedEventsTable.php
```

Update `version.yaml` to reference the new names. Re-run `composer dump-autoload --classmap-authoritative` + class_exists smoke. Both resolution paths (snake_case via classmap, PascalCase via PSR-4) work with October's plugin manager — October reads migration files by name from version.yaml + `require`s them by path.

If phpstan flags level 10 errors in the new files:
- `Lovata\Toolbox\Models\CommonSettings` not found → this is plan 02-03b's concern; should not appear in this plan.
- Migration class file FQN resolution via classmap should be transparent to phpstan once `composer dump-autoload` runs.

Commit:

```
git add plugins/logingrupa/metapixel/updates/ \
        plugins/logingrupa/metapixel/models/ \
        plugins/logingrupa/metapixel/phpstan.neon \
        plugins/logingrupa/metapixel/composer.json \
        plugins/logingrupa/metapixel/composer.lock \
        plugins/logingrupa/metapixel/tests/Feature/Models/ \
        plugins/logingrupa/metapixel/tests/Feature/Migrations/

git commit -m "$(cat <<'EOF'
feat(metapixel): storage layer — migrations + EventLog/FailedEvent models (Phase 2 storage half)

Two fresh October 4 migrations: logingrupa_metapixel_event_log (UNIQUE
race-fence on subject_type, subject_id, event_name, channel, site_id —
P-05 anchor: subject_type is opaque alias) + logingrupa_metapixel_failed_events
(dead-letter audit, UNIQUE on event_id + http_status, subject_type +
subject_id columns enable Phase 4 admin UI re-resolution per H-2).

Models EventLog (no MorphTo — alias is not a class FQN) and FailedEvent
(payload cast to array; 9-key fillable including subject_type + subject_id).

phpstan.neon paths add models; composer.json gets a classmap entry for
updates/ so PSR-4 + classmap autoload the migration classes cleanly.

Four tests: EventLog + FailedEvent model tests (fillable + casts + no MorphTo),
migration tests (up/down idempotent + UNIQUE blocks duplicates).

Plan 02-03b ships Settings + PluginGuard + exception hierarchy in parallel
(Wave 2 — both unblock Wave 3 plans 02-04 + 02-05).
EOF
)"
```
  </action>
  <verify>
    <automated>cd plugins/logingrupa/metapixel &amp;&amp; composer qa 2&gt;&amp;1 | tail -5 | grep -Eq '(OK|PASS|0 errors|tests passed|No issues found)' &amp;&amp; git log -1 --pretty=format:'%s' | grep -q 'storage layer' &amp;&amp; git diff-tree --no-commit-id --name-only -r HEAD | grep -c '^plugins/logingrupa/metapixel/' | xargs test 9 -le</automated>
  </verify>
  <done>composer qa exits 0; commit on HEAD touches ≥ 9 files; commit message references storage layer + H-2 + plan 02-03b parallel-Wave-2 note.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Migration files autoload via classmap | `composer.json` `classmap: ["updates/"]` exposes migration class FQNs to PSR-4 callers. Third parties writing custom Settings models would not be affected — they manage their own migrations. |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-02-03a-01 | Tampering | A future plan adds a `subject()` MorphTo to EventLog and breaks P-05 | mitigate | T25 test asserts `! method_exists(EventLog::class, 'subject')` — explicit test failure if anyone adds the relation. CLAUDE.md `## Locked decisions` already names P-05 anchor; commit message reinforces. |
| T-02-03a-02 | Spoofing | A second migration with the same UNIQUE constraint name conflicts on MySQL | accept | Index names use `metapixel_*` prefix; per-table specificity in name (`metapixel_event_log_subject_channel_site_unique` vs `metapixel_failed_events_event_status_unique`) prevents collision. Documented in Task 1. |
| T-02-03a-03 | Information Disclosure | FailedEvent.payload column stores full Graph API payload including hashed user_data | accept | Phase 2 ships only the table + model; admin UI Phase 4 will mask sensitive fields per FAIL-01 column-spec discipline. Hashed user_data is sha256 (Phase 5 README documents). Raw email/phone never in payload — adapter hashes before payload assembly (Plan 02-05 UserDataHasher). |
| T-02-03a-04 | Denial of Service | A bursty failure mode floods the failed_events table | mitigate | UNIQUE on (event_id, http_status) limits one row per (event, status) pair — a retry that hits the same 500 inserts no new row. Phase 4 admin UI shows attempt count instead. |
| T-02-03a-05 | Repudiation | Phase 4 admin UI replay cannot find the subject for a failed_events row | mitigate | H-2 — `subject_type` + `subject_id` columns are populated by plan 02-06 SendCapiEvent.writeFailedEvent when the adapter is resolvable; only the BindingResolutionException early-return path leaves them null (the adapter literally does not exist, so re-resolution is impossible). Phase 4 admin UI can filter on adapter_type + adapter-resolvable rows for replay. |

</threat_model>

<verification>
## Goal-Backward Reachability Audit

1. "EventLog + FailedEvent tables exist with UNIQUE constraints" — Task 1 + Task 4 T27/T28 verify.
2. "FailedEvent has subject_type + subject_id columns + fillable for H-2" — Tasks 1 + 2 + T26 fillable test.
3. "composer qa exits 0 with classes/ + models/ + classmap autoload" — Task 5 verifies; H-5 spike branch covers PascalCase fallback.

No must-have is UNREACHABLE.

## Multi-Source Coverage Audit

| Source item | Type | Coverage | Notes |
|-------------|------|----------|-------|
| CONTEXT D-04 (2 tables, fresh October 4 migration syntax) | Decision | Task 1 | Two fresh migration files; no legacy/v1.1.1 carry-forward |
| CONTEXT D-05 (logingrupa_metapixel_event_log + UNIQUE race-fence) | Decision | Task 1 | UNIQUE on (subject_type, subject_id, event_name, channel, site_id) |
| CONTEXT D-06 (logingrupa_metapixel_failed_events + table+model only in Phase 2) | Decision | Tasks 1, 2 | Table + model ship; admin UI deferred to Phase 4 FAIL-01..03 |
| CONTEXT D-07 (two-table rationale — different access patterns) | Decision | Task 1 | Reflected in index choices (event_log has subject + secret_key + event_id indexes; failed_events has event_name + adapter_type + http_status indexes) |
| RESEARCH §4.12 EventLog model (no MorphTo) | Reference | Task 2 | T25 enforces |
| RESEARCH §4.13 FailedEvent model | Reference | Task 2 | T26 enforces payload cast; fillable includes subject_type + subject_id (H-2) |
| RESEARCH §4.14 migration shapes | Reference | Task 1 | UNIQUE constraint names + InnoDB engine + idempotency guards |
| RESEARCH §6 T25–T28 tests | Reference | Task 4 | All 4 tests land |
| PITFALLS P-05 (alias not FQN) | Pitfall | Tasks 1, 2 | Migration column comment + EventLog model has no MorphTo + T25 asserts |
| Plan-checker M-2 (plan-3 split) | Revision | This plan IS the storage half | 02-03b ships Settings + PluginGuard + exceptions in parallel Wave 2 |
| Plan-checker H-2 (FailedEvent subject_type/id populated) | Revision | Task 1 + Task 2 + T26 | Columns + fillable shipped here; populating logic ships in plan 02-06 |

No gaps. RESEARCH §9 A2 (CCache memo in cache.default=array env) — not relevant to this plan; plan 02-05 owns UserDataHasher's CCache.

## Acceptance gate

`composer qa` exits 0 from `plugins/logingrupa/metapixel/` after Task 5's commit.
</verification>

<success_criteria>
Plan 02-03a ships when ALL of the following hold:

1. `updates/version.yaml` declares `1.0.0` with both migration files; both migration files exist with idempotent up() + down(); UNIQUE constraint names ≤ 64 chars.
2. `models/EventLog.php` extends `October\Rain\Database\Model`; has CHANNEL_CAPI / CHANNEL_PIXEL + scopeForSubject + NO morphTo.
3. `models/FailedEvent.php` extends `October\Rain\Database\Model`; casts payload to array; fillable includes subject_type + subject_id (H-2).
4. phpstan.neon paths include `models`.
5. composer.json autoload-dev gains `classmap: ["updates/"]`; composer dump-autoload re-runs successfully; H-5 spike resolves snake_case → classmap autoload OR fallback PascalCase rename.
6. All 4 test files exist + pass; test method count ≥ 9 in aggregate (4 model fillable/cast + 2 migration up/down + 2 UNIQUE-blocks + 1 idempotency).
7. composer qa exits 0; coverage ≥ 90% on new code.
8. Single commit on HEAD; commit message references storage layer + H-2 + plan 02-03b parallel-Wave-2 note.
9. No comment pollution in new source files.
</success_criteria>

<output>
After completion, create `plugins/logingrupa/metapixel/.planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-03a-SUMMARY.md` documenting:

- Single commit SHA.
- Composer qa output tail (last 30 lines).
- Test pass counts per file: EventLogModelTest, FailedEventModelTest, EventLogMigrationTest, FailedEventsMigrationTest.
- Coverage numbers per new file: aim to record line + branch coverage for models/EventLog.php + models/FailedEvent.php.
- Confirm SQLite UNIQUE NULL-distinct semantics in T27 + T28 — the race-fence test inserts same key twice; second insertOrIgnore returns 0.
- H-5 spike outcome: which autoload path landed (classmap-with-snake_case OR PascalCase-with-PSR-4)?
- Phase 2 plan-state update: 02-03a closed; 02-03b shipping in parallel (Wave 2); plans 02-04 (SiteResolver + EventLogWriter) + 02-05 (MetaClient + PayloadBuilder + UserDataHasher) unblock when BOTH 02-03a AND 02-03b commit.
</output>

## Revision History
- 2026-05-17 R1: Address plan-checker M-2 (split from old 02-03 into 02-03a storage-layer half — migrations + EventLog/FailedEvent models + classmap + T25–T28). H-2 reflected in FailedEvent fillable + truths + threat model entry T-02-03a-05 documenting the populating logic lives in plan 02-06 SendCapiEvent.writeFailedEvent.
