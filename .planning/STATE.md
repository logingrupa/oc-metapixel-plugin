---
gsd_state_version: 1.0
milestone: v2.0.0
milestone_name: Generic-event-tracking marketplace plugin
status: executing
stopped_at: Plan 02-04 closed (commits fdc7270, b7bf06d, 3ec6575, 8098d1f, e7a9eb1) — Wave 3 SiteResolver + EventLogWriter shipped; plan 02-05 next sequentially on master
last_updated: "2026-05-17T22:05:11.872Z"
last_activity: 2026-05-17 — Plan 02-04 closed (SiteResolver + EventLogWriter shipped; ADAP-06 closed)
progress:
  total_phases: 5
  completed_phases: 1
  total_plans: 11
  completed_plans: 8
  percent: 73
---

# Project State

## Active Milestone: v2.0.0 — Generic-event-tracking marketplace plugin

**Goal:** Decouple plugin from Shopaholic via Lovata-style extensible adapter pattern. Marketplace-grade Meta Pixel + CAPI plugin sellable to any OctoberCMS operator regardless of cart-plugin. Third parties can register custom adapters without modifying plugin core. PHP 8.3 + 8.4 dual support.

See `.planning/PROJECT.md` "Current Milestone" section for full feature list + locked decisions.
See `.planning/ROADMAP.md` for 5-phase v2.0.0 roadmap with success criteria.
See `.planning/REQUIREMENTS.md` for 61 v2 requirements + traceability table.

## Current Position

Phase: 02 (adapter-system-core-contracts-registry-extension-hooks) — EXECUTING
Plan: 6 of 8
Plans: 02-01..02-07 (with 02-03a + 02-03b split) — RESEARCH.md + 8 PLAN files + 2 PLAN-CHECK reports committed
Status: 02-04 CLOSED — SiteResolver (sole authoritative site_id source) + EventLogWriter (UNIQUE race-fence writer; AdapterRegistry-resolved opaque subject_type — P-05 anchor at persistence; outer try/catch fail-safe). 10 new tests (3 SiteResolverTest + 7 EventLogWriterRaceFenceTest) cover everything at 100%. composer qa green (host vendor) — 56 tests / 134 assertions / 100.0% coverage on 15 in-scope production files. ADAP-06 closed.
Last activity: 2026-05-17 — Plan 02-04 closed (commits fdc7270, b7bf06d, 3ec6575, 8098d1f, e7a9eb1)

**Next action:** Plan 02-05 (MetaClient + PayloadBuilder + UserDataHasher) sequential next on master. Plan 02-06 + 02-07 follow.

## Roadmap Snapshot

| Phase | Name | Requirements | Status |
|-------|------|--------------|--------|
| 1 | Tooling + composer + namespace rename + CI matrix | TOOL-01..11 (11) | Executed (3/3 plans) — pending verification |
| 2 | Adapter system core | ADAP-01..11 (11) | Executing (5/8 plans — ADAP-01/02/03/06 closed; 02-02 P-01 static enforcement live; 02-03a storage backbone live; 02-03b Settings + PluginGuard + exception hierarchy live; 02-04 SiteResolver + EventLogWriter live) |
| 3 | ShopaholicAdapter + ThemeActionAdapter | SHOP-01..05 + THEM-01..07 (12) | Not started |
| 4 | Settings rework — Multisite + TrustedHosts + Cookie + FailedEvents + translations | MULT-01..06 + HOST-01..06 + COOK-01..03 + FAIL-01..03 + LANG-01 (19) | Not started |
| 5 | Documentation + marketplace launch | DOCS-01..03 + MKT-01..05 (8) | Not started |

**Coverage:** 61/61 v2 requirements mapped (100%). 0 orphaned.

## Previously Shipped: v1.1.1

Closed 2026-05-15. Partial close — Phase 4 + 5 dropped on architecture pivot. See [`milestones/v1.1.1-ROADMAP.md`](milestones/v1.1.1-ROADMAP.md) for full archive.

- 5 phases complete (1, 2, 3.1, 3.1-07, 3.1-08)
- 28/50 v1 requirements validated; 22 dropped; 2 staging-deferred to operator
- 16/21 plans (76%)
- 207 commits, 11,027 PHP lines
- 177 tests / 0 failed (82.8% coverage)
- composer qa green end-to-end
- Tag `v1.1.1` annotated local-only at SHA `3f32ca6`
- Legacy branch `legacy/v1.1.1` preserves full v1.x codebase + tests + `.planning/`

## Accumulated Context

### Decisions carried forward from v1.x (locked, do NOT re-derive)

- **event_id direction = server → frontend.** Meta dedupes on event_id match within ±10s. Never reverse.
- **CR-02 TrustedHosts allowlist.** v1.x hardcoded HOST_INDEX_MAP; v2.0 operator-supplies via Settings + `jeremykendall/php-domain-parser` for multi-TLD index derivation. Untrusted host → skip cookies (fail-safe). Owned by Phase 4 (HOST-01..06).
- **CR-03 fbclid validation.** `[A-Za-z0-9_-]` charset, ≤255 chars. Invalid → skip `_fbc`. Carried forward in Phase 4 COOK-02.
- **Idempotency via EventLog UNIQUE race-fence.** `(subject_type, subject_id, event_name, channel, site_id)`. `EventLogWriter::record` returns false on collision or DB failure (fail-safe). Kept verbatim per ARCHITECTURE.md §2.
- **Multi-site site_id from Subject model attribute.** v1.x reads `Order.site_id` (Lovata column); v2.0 generalizes to `EventSubjectAdapter::getSiteId(object $obSubject): ?int`. Owned by Phase 2 ADAP-01/ADAP-06.
- **PluginGuard pattern.** Empty `pixel_id` → `Log::warning` + disabled flag, never throw at boot. Carried forward verbatim per ARCHITECTURE.md §2.
- **No `assert()`** — prod `zend.assertions=0` silently no-ops. Enforced by `spaze/phpstan-disallowed-calls`. Locked in Phase 1 TOOL-04.
- **content_ids format = `SKU-{product_id}[-{offer_id}]`** for Shopaholic adapter (matches Facebook Catalog feed). Other adapters define own format via `ValueResolver::resolveContentIds()`. Owned by Phase 3 SHOP-02.
- **Anonymous external_id** = sha256 of subject's unique token (Order.secret_key, Session id, etc.) per adapter. Owned by Phase 2 ADAP-08.

### v2.0 architectural decisions (new — locked at milestone start)

- **Namespace:** `Logingrupa\Metapixel` (drop "Shopaholic"). Owned by Phase 1 TOOL-03.
- **Plugin dir:** `plugins/logingrupa/metapixel/`. Owned by Phase 1 TOOL-02.
- **PHP support:** `"php": "^8.3 || ^8.4"` — avoid 8.4-only syntax (no property hooks, asymmetric visibility, `array_find`/`array_any`/`array_all`/`array_find_key`, `#[\Deprecated]`). Enforced by Phase 1 TOOL-04..06.
- **Composer suggest pattern** — `lovata/shopaholic-plugin` becomes `suggest:`. Plugin works without Shopaholic. Owned by Phase 1 TOOL-01.
- **Graph API pinned to `v23.0`** — v20 expires 2026-09-24. Owned by Phase 2 ADAP-09.
- **Lovata-style extensibility:**
  - `AdapterRegistry::register(string $sSubjectClass, string $sAdapterClass)` — third parties register custom adapters from their `Plugin::boot()`. Owned by Phase 2 ADAP-03.
  - Three `Event::fire` decision-point hooks (`metapixel.event.before_dispatch`, `after_dispatch`, `dead_letter`) with documented payload mutability contracts. Owned by Phase 2 ADAP-04.
  - Five additional hooks (adapter.resolve, value.resolve, user_data.resolve, pixel.before_render, settings.lookup) DEFERRED to v2.1 until real third-party use case surfaces.
  - `Component::extend(...)` + `addDynamicMethod(...)` on PixelHead and FailedEvents controller (operator-prefix convention for namespacing).
- **Multisite trait** on `pixel_id` + `capi_access_token` Settings fields (per-site overrides). `$propagatable = []` lock prevents cross-site token leak. Owned by Phase 4 MULT-01..06.
- **Build philosophy (from `feedback-no-overengineering-fresh-simple` memory):** Simple logic, fresh ideas, no over-engineering. No BC shims (operators stay on `legacy/v1.1.1` branch). No dead code, no unused functions, no premature abstractions. Build for current need only.
- **Code style additions (from `feedback-lovata-extensibility-pattern` memory):** DRY, SRP, self-explanatory variable names (no `$mId`, `$tmp`), Laravel short docblocks (one-line summary + `@param` + `@return`; no multi-paragraph narrative), no phase/CR/incident markers in code.

### v2.0 Phase 2 decisions (added during execution)

- **Lowercase folder convention under `plugins/<vendor>/<plugin>/`** — October Rain `ClassLoader::load` normalises namespaced PSR-style lookups by lowercasing every folder portion before the file basename. PascalCase folders (e.g., `classes/Adapter/`, `tests/Doubles/`) cause autoload misses on Linux because the host bootstrap registers October Rain's ClassLoader (the plugin's own `vendor/autoload.php` is NOT loaded by host bootstrap). All v2.0 plan paths therefore ship lowercase: `classes/adapter/`, `tests/doubles/`, etc. Namespaces stay PascalCase (`Logingrupa\Metapixel\Classes\Adapter\…`) — PHP namespace resolution is case-insensitive. Owned by Phase 2 plan 02-01.
- **H-8 test setUp pattern locked across Phase 2:** every Phase 2 test that needs `AdapterRegistry` MUST bind it directly via `$this->app->singleton(AdapterRegistry::class)` in setUp(). NEVER `(new \Logingrupa\Metapixel\Plugin)->register()` — `PluginBase::__construct(Application $app)` requires container injection and the bare instantiation TypeErrors. Plan 02-01 anchors; plans 02-02..02-07 enforce in their setUp() pattern.
- **AdapterRegistry::$arAdapterMap PHPDoc key type is `array<string, …>`, NOT `array<class-string, …>`.** `register()` accepts a plain string subject FQN; PHPStan level 10 cannot narrow `string` to `class-string` without an extra runtime check the registry deliberately does not add (no benefit — `is_subclass_of` on the adapter side already enforces the value-type contract). Owned by Phase 2 plan 02-01.
- **Site facade FQN verified as `October\Rain\Support\Facades\Site`** (NOT `October\Rain\Cms\Site` as RESEARCH §5.1 assumed — that namespace does not exist in this October build; `vendor/october/rain/src/` contains no `Cms/` subdir). **SiteManager FQN verified as `System\Classes\SiteManager`** (at `modules/system/classes/SiteManager.php` line 18). `phpstan.neon` bans both FQNs (belt-and-suspenders) + `Illuminate\Http\Request::*` + global `request()` helper, all four via H-1 `disallowIn` deny-list scoped to lowercase `classes/queue/*`, `classes/event/*`, `classes/adapter/*`. P-01 cross-context-resolution-drift is now statically enforced. Owned by Phase 2 plan 02-02.
- **PHPStan disallowed-calls uses H-1 `disallowIn` deny-list (NOT `allowIn` allow-list).** Outside the three adapter/queue/event dirs the banned calls are PERMITTED — middleware/, controllers/, components/ legitimately read Request; `classes/helper/` + `classes/meta/` MAY call SiteManager but `SiteResolver` itself MUST NOT (enforced by Plan 02-04 Task 3's static-source regex grep test on `SiteResolver.php`, not by phpstan rule — defence-in-depth). Owned by Phase 2 plan 02-02.
- **Plugin `CLAUDE.md` "Extensibility contract" ranks third-party hooks 1–6 in order of preference** (P-13 convention lock). Event::fire hooks rank 2–4; `Component::extend` + `addDynamicMethod` rank 6 as LAST RESORT with mandatory `onMetapixel*` dynamic-method prefix to avoid third-party collisions. `metapixel.event.before_dispatch` listeners MUST NOT mutate `event_id` or `event_time` (dedup contract anchor — Meta dedupes server-pixel on `event_id` match within ±10s of `event_time`). Owned by Phase 2 plan 02-02.
- **Migration file naming convention: PascalCase basenames matching class FQN** (H-5 spike resolution). Plugin cannot run standalone `composer install` (private October packages not on a public registry) → autoload-dev classmap declared in `composer.json` never registers. October Rain ClassLoader's `loadUpperOrLower` resolves `Logingrupa\Metapixel\Updates\CreateMetapixelEventLogTable` via the `upperClass` branch (lowercase folder + PascalCase basename). October's `Updater::resolve` `require`s files by path from `version.yaml` — runtime migration path does not need autoload. Lovata snake_case migration convention is reserved for files that do not need FQN resolution from tests/phpstan. Owned by Phase 2 plan 02-03a. **Going forward, all plugin code that must be FQN-loadable from tests uses PascalCase basenames matching the class name.**
- **Storage backbone shape locked**: `logingrupa_metapixel_event_log` (UNIQUE on subject_type/subject_id/event_name/channel/site_id — race-fence anchor) + `logingrupa_metapixel_failed_events` (UNIQUE on event_id/http_status; nullable subject_type+subject_id columns enable H-2 admin UI re-resolution). EventLog model has NO `subject()` MorphTo — subject_type is opaque alias (P-05 anchor enforced by `assertFalse(method_exists(EventLog::class, 'subject'))` in T25). FailedEvent.payload cast to array. Owned by Phase 2 plan 02-03a.
- **Settings model extends Lovata.Toolbox CommonSettings** (NOT October's `SettingModel` directly) — inherits Multisite trait + RainLab.Translate behavior + `$settingsFields` convention. Phase 2 stub: `$propagatable = []` lock + static `lookupForSite(?int $iSiteId): array{pixel_id, capi_access_token}` that ignores $iSiteId. Phase 4 MULT-01..02 introduces the per-field whitelist (pixel_id + capi_access_token enter $propagatable); MULT-03 re-implements lookupForSite to honor per-site rows without changing the public signature. Owned by Phase 2 plan 02-03b.
- **PluginGuard cascade-safety pattern locked.** `final class PluginGuard` exposes `isDisabled(): bool` (memoised via static `?bool $bIsDisabled`) + `reset(): void`. Empty `Settings::get('pixel_id', '')` → `Log::warning` + cached true; non-empty → false. NEVER throws at boot — throwing would cascade through October's plugin chain and break unrelated plugins (Campaigns, PromoMechanism). Production memo lifecycle: one Log::warning per request. Test isolation: explicit reset() in setUp + tearDown. Owned by Phase 2 plan 02-03b.
- **Exception hierarchy locked.** `MetaPixelException` (abstract base extends `\RuntimeException`; constructor `(string, int, ?Throwable, array $arContext)` + `getContext(): array`) → 4 finals: `MissingPixelConfigException` + `MissingCapiTokenException` (event-fire-time empty credentials) + `MetaApiTransientException` (HTTP 408/429/5xx + ConnectException → caller retries) + `MetaApiPermanentException` (HTTP 4xx non-408/429 → caller persists FailedEvent + fires dead_letter). The 2 HTTP exceptions carry `?int $iHttpStatus` + `getHttpStatus(): ?int`; iHttpStatus also surfaces via RuntimeException's int $code (via `?? 0`). Constructor shape `(string, ?int, ?Throwable, array)` consistent across both HTTP exceptions so SendCapiEvent::failed can stamp http_status the same way writeFailedEvent does (plan 02-06 L-5 cross-check). Owned by Phase 2 plan 02-03b.
- **PHPStan level 10 mixed-cast pattern locked.** `Settings::get` returns `mixed` (inherited from October's `SettingModel`); naive `(string) Settings::get(...)` fires `cast.string` identifier. CLAUDE.md project lock forbids `@phpstan-ignore` comments. Replaced with `$mValue = Settings::get(...); is_string($mValue) ? $mValue : ''` runtime guard. Applied in PluginGuard::isDisabled + Settings::lookupForSite. Apply same pattern to any future Settings::get callsite at phpstan level 10. Owned by Phase 2 plan 02-03b.
- **`@method` docblock pattern for Lovata.Toolbox CommonSettings descendants.** Declare `@method static mixed get(string $sCode, mixed $mDefault = null)` + `@method static void set(array<string, mixed> $arValues)` on the subclass to satisfy larastan inheritance resolution at phpstan level 10. Lovata's own CommonSettings does not declare them; descendants need it because larastan does not walk through October's SettingModel→Model dynamic-method chain at full strictness. Owned by Phase 2 plan 02-03b.
- **`Settings::clearInternalCache()` test-isolation pattern.** SettingModel keeps a static `$instances` cache that survives across tests (DB resets per test via in-memory SQLite, instance cache does not). Tests asserting fresh Setting state MUST call `Settings::clearInternalCache()` in setUp(). If plan 02-04+ tests need it broadly, consider moving to MetapixelTestCase::setUp() — deferred until a second consumer surfaces. Owned by Phase 2 plan 02-03b.
- **Coverage gate carrier pattern: any plan that adds methods to Plugin.php MUST also extend PluginSanityTest** to cover them — otherwise the Plugin.php denominator grows + coverage % drops below the 90% gate even when the new files themselves are at 100%. Plan 02-03b extended PluginSanityTest with `test_register_settings_returns_descriptor_for_settings_model` to close the gate on `registerSettings()`. Owned by Phase 2 plan 02-03b.
- **SiteResolver shape locked.** `final class SiteResolver` under `classes/helper/` exposes one public static method `forSubject(object $obSubject, EventSubjectAdapter $obAdapter): ?int` with body `return $obAdapter->getSiteId($obSubject);` — one line, no defensive guards beyond type hints. PHPDoc documents cross-context determinism in prose with lowercased "site manager" / "request" wording so the T6 static-source regex defence test (`assertDoesNotMatchRegularExpression('/\bSiteManager\b/', $sSource)`) finds zero matches. Three layers of guard: (1) phpstan disallowed-calls on classes/{queue,event,adapter}/ (plan 02-02); (2) T6 static-source regex on SiteResolver.php source (plan 02-04); (3) the one-line delegating body itself. SiteResolver lives OUTSIDE the phpstan deny-list scope by design — defence-in-depth via the test, not via phpstan. Owned by Phase 2 plan 02-04.
- **EventLogWriter shape locked.** `final class EventLogWriter` under `classes/helper/` exposes one public static method `record(string $sEventId, string $sEventName, string $sChannel, object $obSubject, ?string $sSecretKey, int $iEventTime, ?int $iSiteId): bool`. Resolves adapter via `App::make(AdapterRegistry::class)->resolveFor($obSubject)` — null adapter → Log::warning + false; reads opaque `subject_type` via `$obAdapter->getSubjectType($obSubject)` (P-05 anchor); rejects `getSubjectId() <= 0` → Log::warning + false; calls `DB::table('logingrupa_metapixel_event_log')->insertOrIgnore([...])` → returns `$iAffected === 1`; outer try/catch swallows any Throwable to Log::critical + false (fail-safe — peer-wins assumption). Two `get_class($obSubject)` calls live in Log diagnostic arrays only (never as subject_type writes). L-4 lock: Log/App/DB imported via `Illuminate\Support\Facades\` FQN. Owned by Phase 2 plan 02-04.
- **NULL-distinct UNIQUE semantics on race-fence — write-site implication.** SQLite-in-memory + MySQL InnoDB both treat multiple NULL values in a UNIQUE column as DISTINCT. The race-fence anchor test in EventLogWriterRaceFenceTest MUST use non-null site_id to actually exercise the constraint; a dedicated NULL-distinct test (case 3) verifies the null-twin path. Plan 02-06 (SendCapiEvent) MUST resolve site_id via SiteResolver::forSubject BEFORE calling EventLogWriter::record — passing site_id=null when a real site exists silently disables the race-fence for sibling NULL inserts. Owned by Phase 2 plan 02-04; consumed by plan 02-06.

### Pitfall ownership (each CRITICAL/HIGH pitfall mapped to a phase)

See ROADMAP.md "Pitfall Coverage Map" section.

Anchored CRITICALs:

- **P-01 Cross-context resolution drift** (Phase 3.1-07 production bug anchor): Phase 2 ADAP-06 prevents via SiteResolver::forSubject (plan 02-04 logic) + PHPStan disallowed-calls (plan 02-02 enforcement) + contract test invariant (plan 02-07). **Logic + static enforcement layers closed**; contract test invariant pending plan 02-07.
- **P-03 Hidden Lovata imports outside adapter dir**: Phase 1 TOOL-11 prevents via composer-dependency-analyser; Phase 3 SHOP-04 enforces at adapter boundary.
- **P-05 EventLog subject_type alias ambiguity**: Phase 2 ADAP-01 locks alias-string convention (plan 02-01); EventLog model has no MorphTo (plan 02-03a); EventLogWriter reads subject_type via `$obAdapter->getSubjectType($obSubject)` not get_class (plan 02-04 write-site anchor); Phase 3 SHOP-01 returns `'shopaholic.order'`. **Write-site anchor closed**; persistence-side already closed in plan 02-03a (no MorphTo).
- **P-06 PHP 8.4-only syntax slips**: Phase 1 TOOL-04 phpstan `phpVersion: 80300` + TOOL-05 Rector UP_TO_PHP_83 + TOOL-06 Pint nullable rule.
- **P-15 TrustedHosts marketplace blocker**: Phase 4 HOST-01..06 MUST close before Phase 5 marketplace launch.

### Pending Todos

- `/gsd-verify-phase 01` to verify Phase 1 execution outcomes (3 plans / 11 TOOL-* requirements).
- Phase 2 PHPStan `paths` reopen: when components/ lands, append to phpstan.neon paths list (Plan 02-01 added `classes`, Plan 02-03a added `models`).
- Phase 2+ phpunit.xml `<source><include>` reopen: when components/, middleware/, controllers/, console/ land, add each as `<directory>` entry alongside existing `Plugin.php` + `./classes` + `./models` (Plan 02-01 added `./classes`; Plan 02-02 added `./models`).
- Phase 3 SHOP-* adds `<testsuite name="Metapixel Adapter Tests">` block to phpunit.xml when tests/Unit/Adapter/Shopaholic + tests/Feature/Adapter/Shopaholic land (Run B's --exclude-testsuite='Metapixel Adapter Tests' becomes a real exclude then; currently a no-op).
- Phase 2 ADAP-03 wires AdapterRegistry::flush() call into MetapixelTestCase::flushModelEventListeners() (currently absent — Phase 1 plan 01-03 intentionally did not add a placeholder comment).
- Phase 2 plans 02-02..02-07 MUST use lowercase folder paths (`classes/{adapter,helper,meta,queue,exception,testing}/`, `tests/{doubles,unit,feature,contract}/…`) for October Rain ClassLoader autoload — locked by 02-01 deviation 1. Namespaces stay PascalCase. Plan markdown files that show `classes/Adapter/`, `tests/Doubles/`, etc., should be treated as folder-name typos and shipped lowercase.

### Blockers/Concerns

(none — Plan 01-03 shipped cleanly; standalone-repo composer install limitation persists from 01-01/01-02 — smoke tests executed via host vendor binaries, documented in 01-03-SUMMARY.md "Smoke-Test Path Deviations". Full qa chain integration smoke (including composer-dependency-analyser) deferred to CI matrix.)

## Session Continuity

Last session: 2026-05-17T22:05:11.854Z

Stopped at: Plan 02-04 closed (commits fdc7270, b7bf06d, 3ec6575, 8098d1f, e7a9eb1) — Wave 3 SiteResolver + EventLogWriter shipped; plan 02-05 next sequentially on master

Resume file: .planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-05-PLAN.md

## Performance Metrics

| Phase | Plan | Duration | Tasks | Files | Date |
|-------|------|----------|-------|-------|------|
| 1 | 01-01 | ~12 min | 6 (4 active, 2 deferred) | 5 created, 71 deleted | 2026-05-16 |
| 1 | 01-02 | ~14 min | 9 (7 active, 1 skipped, 1 smoke-only) | 5 created, 2 modified | 2026-05-16 |
| 1 | 01-03 | ~18 min | 8 (7 active, 1 smoke-only) | 6 created, 0 modified | 2026-05-16 |
| 2 | 02-01 | ~12 min | 6 tasks (all active) | 14 created, 4 modified | 2026-05-17 |
| 2 | 02-02 | ~4 min | 5 tasks (all active; T1 spike + T5 QA-gate non-committing) | 1 created, 3 modified | 2026-05-17 |
| 2 | 02-03a | ~7 min | 5 tasks (4 active + 1 H-5 rename fix; T5 QA-gate non-committing) | 9 created, 2 modified | 2026-05-17 |
| 2 | 02-03b | ~9 min | 5 tasks (4 feat/test + 1 QA-gate fix) | 12 created, 4 modified | 2026-05-17 |
| 2 | 02-04 | ~6 min | 5 tasks (2 feat + 2 test + 1 QA-gate fix) | 4 created, 0 modified | 2026-05-17 |
