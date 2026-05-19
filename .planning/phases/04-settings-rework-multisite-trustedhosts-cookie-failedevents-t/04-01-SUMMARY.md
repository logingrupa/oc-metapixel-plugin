---
phase: 04-settings-rework-multisite-trustedhosts-cookie-failedevents-t
plan: 01
subsystem: settings
tags: [multisite, settings, phpstan, disallowed-calls, credentials, P-10]

requires:
  - phase: 02-bootstrap
    provides: Settings model with $propagatable = [] descendant lock + lookupForSite(?int) stub callsite preserved in SendCapiEvent::handle
  - phase: 03-payload
    provides: AddPayloadToMetapixelEventLogTable migration pattern (idempotent additive shape) + EventLogWriter UNIQUE race-fence with NULL-distinct site_id

provides:
  - Per-site pixel_id + capi_access_token routing via Settings::lookupForSite($iSiteId)
  - D-01 silent default-row fallback for empty/null per-site values
  - PHPStan-enforced ban on direct Settings::get('pixel_id'|'capi_access_token') outside Settings.php + PluginGuard.php
  - AddMultisitePixelIdAndToken schema-additive no-op migration registered in version.yaml 1.0.2
  - 22 Wave 0 test methods exercising MULT-01..06 invariants
  - tests/fixtures/sites.php hermetic 2-site seeder

affects: [04-02-hosts-cookies, 04-03-trusted-hosts-middleware, 04-04-failed-events, 04-05-translations]

tech-stack:
  added: []
  patterns:
    - "Direct DB read for credential lookup — bypasses SettingModel cache to avoid getCacheKey() cross-context collisions"
    - "PHPStan disallowedStaticCalls + allowIn + allowExceptParamsAnywhere — param-value-aware disallow with per-file whitelist"

key-files:
  created:
    - plugins/logingrupa/metapixel/updates/AddMultisitePixelIdAndToken.php
    - plugins/logingrupa/metapixel/tests/Unit/Models/SettingsMultisiteTraitTest.php
    - plugins/logingrupa/metapixel/tests/Feature/MultisiteEventLogRoutingTest.php
    - plugins/logingrupa/metapixel/tests/Feature/Migrations/AddMultisitePixelIdAndTokenTest.php
    - plugins/logingrupa/metapixel/tests/fixtures/sites.php
  modified:
    - plugins/logingrupa/metapixel/models/Settings.php
    - plugins/logingrupa/metapixel/updates/version.yaml
    - plugins/logingrupa/metapixel/phpstan.neon
    - plugins/logingrupa/metapixel/tests/Feature/Settings/SettingsLookupForSiteTest.php
    - plugins/logingrupa/metapixel/tests/MetapixelTestCase.php

key-decisions:
  - "Direct-DB lookup helpers replace planner's Site::withContext + Settings::get closures (Rule 1 deviation — see Deviations below)"
  - "Default-row fallback: prefer site_id IS NULL row; fall back to first row matching settings code so single-site installs that save under the active site still resolve credentials"
  - "PHPStan disallowed-call rule lives in disallowedStaticCalls (not disallowedMethodCalls — static calls route differently in spaze/phpstan-disallowed-calls)"
  - "PluginGuard.php whitelisted alongside Settings.php — PluginGuard reads pixel_id at boot to gate the plugin and does not leak credentials via Multisite propagation"

patterns-established:
  - "Direct-DB credential reads via DB::table('system_settings') with explicit whereNull('site_id') / where('site_id', $iSiteId) — sidesteps SettingModel cache for Multisite-aware lookups"
  - "MetapixelTestCase pins System::hasDatabase() truthy via Manifest + reflection so deferred plugin boot callbacks don't silently drop to defaults"
  - "Hermetic 2-site SQLite seed (tests/fixtures/sites.php) for cross-site test isolation without booting the full October core"

requirements-completed: [MULT-01, MULT-02, MULT-03, MULT-04, MULT-05, MULT-06]

duration: 1h 35m
completed: 2026-05-19
---

# Phase 4 Plan 01: Multisite Settings Rework Summary

**Per-site CAPI credential routing on October 4 Multisite trait with D-01 silent default-row fallback; PHPStan disallowed-calls enforces the P-10 lock at static-analysis time.**

## Performance

- **Duration:** ~1h 35m
- **Started:** 2026-05-19T20:23:00Z (HEAD assertion)
- **Completed:** 2026-05-19T21:58:25Z
- **Tasks:** 4 of 4 (Task 0 Wave 0 + Tasks 1-3 implementation)
- **Files modified:** 10 (5 created + 5 modified)

## Accomplishments

- Per-site `pixel_id` + `capi_access_token` routing through `Settings::lookupForSite(?int)` with D-01 silent fallback (verified via 5 feature tests including empty-string + null fallback paths).
- PHPStan disallowedStaticCalls rule that flags direct `Settings::get('pixel_id'|'capi_access_token')` outside the two whitelisted files (`models/Settings.php` + `classes/helper/PluginGuard.php`) — verified by injecting a violator into `classes/test/Violator.php` (transient) and observing two errors for the credential keys plus zero errors for `test_event_code`.
- `AddMultisitePixelIdAndToken` schema-additive no-op migration registered in `version.yaml` 1.0.2 — idempotent under both table-present and table-absent setUp branches.
- 22 Wave 0 Pest test methods landed across 4 test files (≥ 21 required by acceptance) + the shared `tests/fixtures/sites.php` 2-site seeder.

## Task Commits

Each task was committed atomically on the worktree branch `worktree-agent-abcb3f6153d3e1cfc`:

1. **Task 0: Wave 0 RED test scaffolds + 2-site fixture** — `3dff5b0` (test)
2. **Task 1: Settings.php per-site lookupForSite body** — `d1a73a4` (feat)
3. **Task 2: AddMultisitePixelIdAndToken migration + version.yaml** — `db0e1ca` (feat)
4. **Task 3: phpstan disallowed-calls + direct-DB lookup helpers** — `dc51cf4` (feat)

## Files Created/Modified

**Created:**

- `plugins/logingrupa/metapixel/updates/AddMultisitePixelIdAndToken.php` — Schema-additive no-op migration (`Schema::hasTable('system_settings')` guard + empty `up()` body; empty `down()`). Marketplace install-log traceability anchor for MULT-06.
- `plugins/logingrupa/metapixel/tests/Unit/Models/SettingsMultisiteTraitTest.php` — 4 reflection-based tests asserting MULT-01 (`$propagatable` declared at descendant level) + MULT-02 (`pixel_id` + `capi_access_token` NOT in `$propagatable`).
- `plugins/logingrupa/metapixel/tests/Feature/MultisiteEventLogRoutingTest.php` — 10 tests covering the MULT-05 D-04 8-path matrix (2 subjects × 2 sites × 2 channels) + a NULL-distinct UNIQUE assertion.
- `plugins/logingrupa/metapixel/tests/Feature/Migrations/AddMultisitePixelIdAndTokenTest.php` — 3 idempotency tests (table present, table absent, down no-op).
- `plugins/logingrupa/metapixel/tests/fixtures/sites.php` — Hermetic 2-site `system_site_definitions` seeder callable + `Site::resetCache()`.

**Modified:**

- `plugins/logingrupa/metapixel/models/Settings.php` — Phase 2 `lookupForSite` stub replaced with a direct-DB per-site lookup pair (`readCredentialsInGlobalContext` + `readCredentialsForSiteContext` + `readCredentialsFromRow` shared decoder). Explicit `protected $propagatable = []` declaration at the descendant level (D-20 marketplace audit anchor) preserved with an updated docblock.
- `plugins/logingrupa/metapixel/updates/version.yaml` — Bumped to 1.0.2, registers `AddMultisitePixelIdAndToken.php`.
- `plugins/logingrupa/metapixel/phpstan.neon` — Added `disallowedStaticCalls` block with the D-02 rule: `method: Logingrupa\Metapixel\Models\Settings::get()` + `allowIn: [models/Settings.php, classes/helper/PluginGuard.php]` + `allowExceptParamsAnywhere: [pixel_id, capi_access_token]`.
- `plugins/logingrupa/metapixel/tests/Feature/Settings/SettingsLookupForSiteTest.php` — Phase 2 stub replaced; 5 feature tests cover MULT-03 + D-01 (default-only / per-site / empty-pixel fallback / null-token fallback / return-shape).
- `plugins/logingrupa/metapixel/tests/MetapixelTestCase.php` — Added `ensureMigrationsTableForHasDatabaseProbe` so `System::hasDatabase()` returns true in tests. Clears `Facade::$resolvedInstance` before pinning so `Manifest::put` and `system.helper` reflection target the current app's bindings, not a stale singleton from a prior `refreshApplication`.

## Deviations from Plan

### Rule 1 (auto-fix bug) — Direct-DB helpers replace `Site::withContext` + `Settings::get` closures

**Found during:** Task 1 execution (running `SettingsLookupForSiteTest`).

**Issue:** The planner's `Settings::lookupForSite` body (per `04-RESEARCH.md` Example 2 lines 1591-1619) wrapped `Settings::get('pixel_id', '')` in `Site::withGlobalContext(closure)` for the default-row read and `Site::withContext($iSiteId, closure)` for the per-site read, with `Settings::clearInternalCache()` inside each closure to bust the `SettingModel::$instances` static cache (Pitfall 1 anchor).

Empirically this design **does not produce context-correct reads** because:

- `SettingModel::getCacheKey()` reads `$this->site_id ?: Site::getSiteIdFromContext()`.
- `Site::getSiteIdFromContext()` returns `siteContext->id ?? activeSite->id`. `Site::withGlobalContext` only flips the `globalContext` flag; it does NOT update `siteContext`.
- Therefore the cache key inside `Site::withGlobalContext` is **identical** to the cache key inside `Site::withContext($iSiteId)` when `$iSiteId` matches the active site. Both reads collide on one Cache facade entry via `QueryBuilder::remember(1440)`.
- `Settings::clearInternalCache()` only clears the `$instances` map, not the Cache facade entry; `(new self)->clearCache()` could clear the latter but the next read would re-cache under the same key — only swapping the SQL filter via `MultisiteScope`.
- Diagnostic test (committed temporarily and removed): the second read (`readCredentialsForSiteContext`) received the **first read's cached row** with `pixel_id=''`, breaking D-01 fallback for `test_lookup_for_site_empty_per_site_pixel_falls_back_to_default`.

**Fix:** `readCredentialsInGlobalContext` + `readCredentialsForSiteContext` now run direct `DB::table('system_settings')` queries with explicit `whereNull('site_id')` (for the default row) and `where('site_id', $iSiteId)` (for the per-site row). The shared `readCredentialsFromRow` private decodes the JSON `value` column and runtime-guards string types per the Phase 2 level-10 PHPStan mixed-cast lock. This sidesteps the SettingModel cache layer entirely and routes purely by the row's `site_id` column — the canonical Multisite row-layer semantics.

The default-row read **additionally falls back to "first row matching the settings code"** when no `site_id IS NULL` row exists, so single-site installs that always `Settings::set` under the active site (which Multisite stamps with the active `site_id`, never null) still resolve credentials. This preserves the Phase 2 Queue + Backbone test behaviour without requiring those tests to seed under `Site::withGlobalContext`.

**Files modified:** `models/Settings.php` (Task 1 + Task 3 commits).

**Acceptance-criteria impact:** The Task 1 acceptance criteria required the literal substrings `Site::withContext($iSiteId,`, `Site::withGlobalContext(`, `self::clearInternalCache()` (×2), and `use October\Rain\Support\Facades\Site;`. These are no longer present in the executable body (only in docblock comments explaining the change). The semantic acceptance — per-site routing with D-01 silent fallback — is met and verified by all 5 SettingsLookupForSiteTest cases.

### Rule 3 (auto-fix blocking issue) — MetapixelTestCase hasDatabase pin

**Found during:** Task 1 execution (running `SettingsLookupForSiteTest`).

**Issue:** `System::hasDatabase()` checks `Manifest::get('database.check')` and `Schema::hasTable('migrations')`. The Phase 2 `MetapixelTestCase` did not provision a `migrations` table, so `hasDatabase()` returned false → `SettingModel::getSettingsRecord()` short-circuited to null → `Settings::get` always returned defaults. While the Rule 1 fix above (direct-DB helpers) bypasses this for credential reads, **other code paths** in the plugin (e.g., `Settings::getThemeCustomEventNames`, `PluginGuard::isDisabled`) still depend on `Settings::get` and thus on `hasDatabase()`. To keep Phase 2 + Phase 3 tests passing after my Settings.php change, the test base case must produce a truthy `hasDatabase()`.

**Fix:** Added `ensureMigrationsTableForHasDatabaseProbe($app)` to `MetapixelTestCase`. Called from both `createApplication()` (initial app) and `setUp()` (post-`refreshApplication` re-pin). Clears Laravel's `Facade::$resolvedInstance` static cache before pinning so `Manifest::put` + `system.helper` reflection land on the current app's bindings (not a stale singleton carried over from a prior test's `refreshApplication`).

**Files modified:** `tests/MetapixelTestCase.php` (Task 3 commit).

**Scope:** This is shared test infrastructure that affects every test extending `MetapixelTestCase`. Without it, my Settings.php change would have regressed 9 Queue / Backbone / Theme tests that pre-existed in Phase 2/3. Final test count: 279 passed, 1 pre-existing master failure unaffected by my changes (`ThemeMarkupTagsTwigTest::plugin_boot_listener_mounts_collector_on_thisvariable_when_event_fires`).

### Auto-fixed Issues

**None.** The Rule 1 and Rule 3 deviations above cover the two changes outside the plan's `files_modified` list. No bugs were silently fixed; both are documented above with rationale.

## Self-Check: PASSED

**File existence checks:**

- ✅ `plugins/logingrupa/metapixel/.claude/worktrees/agent-abcb3f6153d3e1cfc/models/Settings.php` (modified, 199 lines)
- ✅ `plugins/logingrupa/metapixel/.claude/worktrees/agent-abcb3f6153d3e1cfc/updates/AddMultisitePixelIdAndToken.php` (created)
- ✅ `plugins/logingrupa/metapixel/.claude/worktrees/agent-abcb3f6153d3e1cfc/updates/version.yaml` (modified)
- ✅ `plugins/logingrupa/metapixel/.claude/worktrees/agent-abcb3f6153d3e1cfc/phpstan.neon` (modified)
- ✅ `plugins/logingrupa/metapixel/.claude/worktrees/agent-abcb3f6153d3e1cfc/tests/Unit/Models/SettingsMultisiteTraitTest.php` (created, 4 tests)
- ✅ `plugins/logingrupa/metapixel/.claude/worktrees/agent-abcb3f6153d3e1cfc/tests/Feature/Settings/SettingsLookupForSiteTest.php` (modified, 5 tests)
- ✅ `plugins/logingrupa/metapixel/.claude/worktrees/agent-abcb3f6153d3e1cfc/tests/Feature/MultisiteEventLogRoutingTest.php` (created, 10 tests)
- ✅ `plugins/logingrupa/metapixel/.claude/worktrees/agent-abcb3f6153d3e1cfc/tests/Feature/Migrations/AddMultisitePixelIdAndTokenTest.php` (created, 3 tests)
- ✅ `plugins/logingrupa/metapixel/.claude/worktrees/agent-abcb3f6153d3e1cfc/tests/fixtures/sites.php` (created)
- ✅ `plugins/logingrupa/metapixel/.claude/worktrees/agent-abcb3f6153d3e1cfc/tests/MetapixelTestCase.php` (modified — Rule 3 deviation)

**Commit existence checks (per `git log --oneline 2dc6df5..HEAD`):**

- ✅ `3dff5b0` Wave 0 test scaffolds
- ✅ `d1a73a4` Settings::lookupForSite implementation
- ✅ `db0e1ca` AddMultisitePixelIdAndToken migration
- ✅ `dc51cf4` PHPStan disallowed-calls + direct-DB helpers

**Test totals:**

- Phase 4 Plan 01-scoped tests: 22 of 22 passing (SettingsMultisiteTraitTest: 4, SettingsLookupForSiteTest: 5, MultisiteEventLogRoutingTest: 10, AddMultisitePixelIdAndTokenTest: 3)
- Full plugin suite: 279 of 280 passing (1 pre-existing master failure in `ThemeMarkupTagsTwigTest`, unrelated to this plan)

**Hungarian / Tiger-Style / Pint compliance:**

- ✅ All new PHP locals follow Hungarian notation (`$obQuery`, `$obSchema`, `$obRow`, `$mDecoded`, `$mPixel`, `$mToken`, `$sDefaultPixel`, `$sDefaultToken`, `$sSitePixel`, `$sSiteToken`, `$arNullSite`, etc.).
- ✅ October model property exceptions respected (`$propagatable`, `$settingsCode`, `$settingsFields` remain Laravel-standard).
- ✅ No `assert()`, no `@phpstan-ignore`, no `// CR-XX` / `// Phase N` source markers (workflow refs only in commit messages).
- ✅ `vendor/bin/pint --test plugins/logingrupa/metapixel/.claude/worktrees/agent-abcb3f6153d3e1cfc/models/Settings.php` exits 0.
- ✅ `vendor/bin/phpstan analyse -c <neon> --no-progress` exits 0 on the master plugin tree (verified via absolute-path-rewritten config — worktree itself is not part of the phpstan source path).
- ✅ `vendor/bin/phpmd plugins/logingrupa/metapixel/.claude/worktrees/agent-abcb3f6153d3e1cfc/models/Settings.php text plugins/logingrupa/metapixel/phpmd.xml` exits 0 (no violations).

## Threat Flags

None. The plan's `<threat_model>` STRIDE register covered T-04-MULT-01..04. All four mitigations are in place:

- T-04-MULT-01 (Information Disclosure, lookupForSite) — D-01 fallback verified; D-02 disallowed-calls rule enforced.
- T-04-MULT-02 (Information Disclosure, $propagatable drift) — explicit declaration verified by 4 reflection tests.
- T-04-MULT-03 (Tampering, $instances cache leak) — sidestepped entirely by Rule 1 deviation (direct-DB reads bypass the cache).
- T-04-MULT-04 (DoS, migration runs before system_settings exists) — `Schema::hasTable` guard verified by `test_up_is_idempotent_when_system_settings_table_absent`.
