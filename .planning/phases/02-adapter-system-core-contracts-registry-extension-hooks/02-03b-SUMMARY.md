---
phase: 02-adapter-system-core-contracts-registry-extension-hooks
plan: 3b
subsystem: settings-pluginguard-exceptions
tags: [settings, common-settings, plugin-guard, exception-hierarchy, register-settings, lang, hungarian-notation, fail-fast, lovata-toolbox]

requires:
  - phase: 02-adapter-system-core-contracts-registry-extension-hooks
    plan: 1
    provides: AdapterRegistry singleton-binding + EventSubjectAdapter/ValueResolver interfaces + tests/doubles/ + lowercase folder convention
  - phase: 02-adapter-system-core-contracts-registry-extension-hooks
    plan: 2
    provides: phpstan disallowedMethodCalls + phpunit Adapter/Contract testsuites + CLAUDE.md ranked extensibility hooks
  - phase: 02-adapter-system-core-contracts-registry-extension-hooks
    plan: 3a
    provides: phpstan paths include ./models + composer autoload-dev classmap pattern (PascalCase basenames for FQN-loadable code) + EventLog/FailedEvent models for downstream EventLogWriter (02-04) and SendCapiEvent (02-06)
provides:
  - Settings model extending Lovata.Toolbox CommonSettings (single-row in Phase 2) with $propagatable=[] lock + static lookupForSite(?int $iSiteId): array{pixel_id, capi_access_token} stub
  - models/settings/fields.yaml (3 fields — pixel_id text, capi_access_token password, test_event_code text — all using lang keys)
  - PluginGuard final class with memoised isDisabled(): bool + reset(): void; empty pixel_id → Log::warning + cached disabled flag; never throws at boot (cascade-safety)
  - 5-class exception hierarchy under classes/exception/ — MetaPixelException (abstract base extends RuntimeException; getContext()) + MissingPixelConfigException + MissingCapiTokenException + MetaApiTransientException (getHttpStatus) + MetaApiPermanentException (getHttpStatus)
  - Plugin::registerSettings() returning settings descriptor wiring Settings model under category 'Marketing'
  - lang/en/lang.php + lang/lv/lang.php extended with settings.* tree (label/description/category + 3 fields with label+commentAbove keys); LV translation hand-written
  - 4 new test files / 13 cases / 23 assertions: PluginGuardTest, ExceptionHierarchyTest, SettingsLookupForSiteTest, SettingsCommonSettingsParentTest
  - PluginSanityTest extended with test_register_settings_returns_descriptor_for_settings_model (1 case, 6 assertions) to close coverage gate

affects:
  - 02-04 (SiteResolver + EventLogWriter — EventLogWriter consults PluginGuard at the write boundary)
  - 02-05 (MetaClient throws MetaApiTransient/Permanent; PayloadBuilder calls Settings::lookupForSite for per-site test_event_code)
  - 02-06 (SendCapiEvent::handle uses Settings::lookupForSite for credentials, MetaPixelException::getContext for FailedEvent payloads)
  - phase 03 (any adapter's ValueResolver may read Settings::get; PluginGuard short-circuits event dispatch chain at the head)
  - phase 04 (MULT-01..02 introduces the per-field whitelist on Settings — pixel_id + capi_access_token enter $propagatable; MULT-03 re-implements lookupForSite to honor per-site rows)

tech-stack:
  added: []
  patterns:
    - "Settings extends Lovata.Toolbox CommonSettings (NOT October's SettingModel directly) — inherits Multisite trait + RainLab.Translate behavior + $settingsFields convention"
    - "PluginGuard memoisation via private static ?bool $bIsDisabled — single Log::warning per request lifecycle; reset() drops the memo for test isolation"
    - "Exception hierarchy with array $arContext base + ?int $iHttpStatus on 2 HTTP subclasses — caller (SendCapiEvent) stamps both fields onto FailedEvent rows via the same constructor shape (L-5)"
    - "PHPStan level 10 mixed-cast pattern: `$mValue = Settings::get(...)`; `is_string($mValue) ? $mValue : ''` (cast.string identifier rejects naive `(string) Settings::get(...)`)"
    - "@method docblocks on Settings declare static get/set signatures for larastan + phpstan resolution (Lovata's CommonSettings exposes them via SettingModel inheritance but no native typehints)"
    - "Test isolation pattern for SettingModel: Settings::clearInternalCache() in setUp() — static $instances cache survives between tests (DB resets per test via in-memory SQLite, instance cache does not)"

key-files:
  created:
    - models/Settings.php
    - models/settings/fields.yaml
    - classes/helper/PluginGuard.php
    - classes/exception/MetaPixelException.php
    - classes/exception/MissingPixelConfigException.php
    - classes/exception/MissingCapiTokenException.php
    - classes/exception/MetaApiTransientException.php
    - classes/exception/MetaApiPermanentException.php
    - tests/Unit/Helper/PluginGuardTest.php
    - tests/Unit/ExceptionHierarchyTest.php
    - tests/Feature/Settings/SettingsLookupForSiteTest.php
    - tests/Feature/Settings/SettingsCommonSettingsParentTest.php
  modified:
    - Plugin.php (added registerSettings(); pint refactored use-block + brace style)
    - lang/en/lang.php (settings.* tree added)
    - lang/lv/lang.php (settings.* tree added — hand-written LV)
    - tests/Unit/PluginSanityTest.php (test_register_settings_returns_descriptor_for_settings_model)

key-decisions:
  - "Test directory casing: PascalCase preserved (tests/Unit/Helper/, tests/Feature/Settings/). Plan 02-03a established the same convention. Lowercase test dirs are reserved for PSR-4-namespaced fixtures (tests/doubles/). Non-namespaced classic-style test classes live under PascalCase tests/Feature/* + tests/Unit/* — phpunit's <directory> scanner finds them regardless of casing."
  - "PHPStan level 10 mixed-cast workaround chosen over @phpstan-ignore: `Settings::get` returns mixed (inherited from October's SettingModel); naive `(string) self::get(...)` fires cast.string at level 10. Replaced with `is_string` runtime guard. PluginGuard and Settings::lookupForSite both adopt this pattern. Type-safe at runtime, phpstan-clean at static-analysis time, no @phpstan-ignore."
  - "Settings::clearInternalCache() in SettingsLookupForSiteTest::setUp(): SettingModel's static $instances cache survives between tests because the SettingModel class itself is loaded once per test process. DB resets (in-memory SQLite recreate per test) drop the underlying rows but the static cached Settings instance still answers from its prior in-memory state. clearInternalCache() drops the static cache so each test sees a fresh resolved instance."
  - "PluginGuard tearDown resets the memo even though tests use unique values per case — defence-in-depth across test files. Without it, if a PluginGuardTest case set the memo to true, a subsequent test in a different file calling PluginGuard::isDisabled() would observe the leaked true without an explicit reset. The static memo persists across the test process lifetime."
  - "PluginSanityTest extended (not a new test file) to close coverage on Plugin::registerSettings(). Coverage gate failed at 84.1% with just the 4 new test files because Plugin.php sat at 47.4% (registerSettings + boot uncovered). Without this addition the --min=90 gate fails. PluginSanityTest already used `new Plugin($this->app)` for register()/boot() coverage — extending it for registerSettings() is the same pattern."
  - "MetaApi*Exception constructor signature: (string $sMessage = '', ?int $iHttpStatus = null, ?Throwable $obPrevious = null, array $arContext = []). The iHttpStatus is forwarded into RuntimeException's int $code constructor parameter (via ?? 0) so the exception's getCode() also surfaces the HTTP status (additional access path beyond getHttpStatus()). Boundary exceptions (MissingPixelConfig + MissingCapiToken) inherit the abstract base constructor unchanged."

patterns-established:
  - "Settings::clearInternalCache() in setUp pattern — required for any test asserting against fresh Setting state. Add to MetapixelTestCase later if plan 02-04+ tests need it broadly (deferred until a second consumer surfaces — Tiger-Style 'don't abstract on a sample of one')."
  - "@method docblock for Lovata.Toolbox CommonSettings descendants — declare `@method static mixed get(...)` + `@method static void set(array<string, mixed>)` on the subclass to satisfy larastan inheritance resolution at phpstan level 10. Lovata's own CommonSettings does not declare them; descendants need it because larastan does not walk through October's SettingModel->Model dynamic-method chain at full strictness."
  - "Coverage gate carrier pattern: any plan that adds methods to Plugin.php MUST also extend PluginSanityTest to cover them — otherwise the Plugin.php denominator grows + coverage % drops below the 90% gate even when the new files themselves are at 100%."

requirements-completed: []

duration: ~9 min
completed: 2026-05-17
---

# Phase 02 Plan 03b: Settings + PluginGuard + Exception Hierarchy Summary

**Phase 2 config half landed — Settings extends Lovata.Toolbox CommonSettings with single-row Phase 2 stub + `lookupForSite` credential contract; PluginGuard memoises empty-pixel-id check with Log::warning + cached disabled flag (never throws at boot); 5-class exception hierarchy (abstract MetaPixelException base + MissingPixelConfig/MissingCapiToken + MetaApiTransient/Permanent with getHttpStatus); Plugin::registerSettings wires the Settings UI; lang/en + lang/lv carry hand-written settings strings; 4 new tests + 1 PluginSanityTest extension cover everything at 100%. composer qa green (host vendor) — 46 tests / 109 assertions / 100.0% coverage on all 13 in-scope production files.**

## Performance

- **Duration:** ~9 min (2026-05-17 — Wave 2 sequential after 02-03a)
- **Tasks:** 5 (all auto-mode, no checkpoints)
- **Commits:** 5 (4 task commits + 1 QA-gate fix commit)
- **Files created:** 12
- **Files modified:** 4
- **Test count delta:** +14 tests (32 → 46) / +29 assertions (80 → 109)

## Accomplishments

- Shipped Settings model extending `Lovata\Toolbox\Models\CommonSettings` with `$propagatable = []` lock and static `lookupForSite(?int $iSiteId): array{pixel_id, capi_access_token}` Phase 2 stub. lookupForSite is the credential-lookup contract that plan 02-06's `SendCapiEvent::handle` will call; Phase 4 MULT-03 re-implements it for per-site routing without changing the public signature.
- Shipped `models/settings/fields.yaml` with 3 fields (pixel_id text, capi_access_token password, test_event_code text) all using lang keys. October convention `$settingsFields = 'fields.yaml'` resolves to `models/settings/fields.yaml` (snake-case version of model class as directory).
- Shipped `PluginGuard` final class. Static `isDisabled(): bool` is memoised via `private static ?bool $bIsDisabled`. Empty `Settings::get('pixel_id', '')` → `Log::warning('metapixel: pixel_id is empty — plugin running in disabled mode (events suppressed)')` + returns true. Non-empty → returns false. `reset()` clears the memo. PluginGuard NEVER throws at boot — throwing would cascade through October's plugin chain and break unrelated plugins (Campaigns, PromoMechanism). Imports `Illuminate\Support\Facades\Log` FQN (L-4 lock).
- Shipped 5-class exception hierarchy under `classes/exception/`:
  - `MetaPixelException` (abstract) extends `\RuntimeException`; constructor `(string $sMessage = '', int $iCode = 0, ?Throwable $obPrevious = null, array $arContext = [])`; protected `$arContext` + `getContext(): array` getter.
  - `MissingPixelConfigException` (final) — event-fire-time empty pixel_id (boot-time empty is PluginGuard's territory).
  - `MissingCapiTokenException` (final) — event-fire-time empty capi_access_token; pixel dispatch unaffected.
  - `MetaApiTransientException` (final) — HTTP 408/429/5xx + ConnectException; `private ?int $iHttpStatus` + `getHttpStatus(): ?int`; iHttpStatus also forwarded into RuntimeException's `int $code` via `?? 0`.
  - `MetaApiPermanentException` (final) — HTTP 4xx (other than 408/429); same shape as Transient. SendCapiEvent persists a FailedEvent row + fires `metapixel.event.dead_letter` on this one.
- Wired `Plugin::registerSettings()` returning a single 'settings' descriptor binding the Settings model under category 'Marketing', icon `icon-bullseye`, order 500. `register()` keeps its plan-02-01 AdapterRegistry singleton-binding unchanged — `registerSettings()` is the separate October-convention method.
- Extended `lang/en/lang.php` + `lang/lv/lang.php` with the `settings.*` tree (label/description/category + 3 fields with label + commentAbove keys). Latvian translation hand-written (not machine-translated). Meta product names (Pixel, CAPI, Conversions API, Test Events) kept untranslated as proper nouns per LV market convention.
- Shipped 4 new test files / 13 cases covering T7 + T15 + T23 + T24 from RESEARCH §6. All 4 files use the H-8 `$this->app->singleton(AdapterRegistry::class)` setUp pattern (no `(new Plugin)` instantiations). PluginGuardTest also wires tearDown to reset the memo for defence-in-depth across test files.
- Extended `PluginSanityTest` with `test_register_settings_returns_descriptor_for_settings_model` to close the coverage gate on `Plugin::registerSettings()` (without it Plugin.php sat at 47.4% and dragged total below 90%).
- `composer qa` (host-vendor) green: pint passed, phpstan level 10 no errors, phpmd exit 0, pest **46 tests / 109 assertions / 100.0% coverage on all 13 in-scope production files**.

## Task Commits

| Task | Description | Commit | Type |
|------|-------------|--------|------|
| 1 | Settings model + fields.yaml | `2b21b7e` | feat |
| 2 | PluginGuard helper + 5-class exception hierarchy | `2bc77d4` | feat |
| 3 | Plugin::registerSettings + lang strings (en + lv) | `ca3752f` | feat |
| 4 | T7 + T15 + T23 + T24 — guard + exceptions + settings tests | `7665737` | test |
| 5 | composer qa green — pint autofix + phpstan mixed-cast + coverage gate | `e5f471a` | fix |

`docs(02-03b)` metadata commit ships separately with this SUMMARY.md + STATE.md + ROADMAP.md.

## Files Created/Modified

### Created (12)

- `models/Settings.php` — Settings extends CommonSettings; lookupForSite stub; @method docblocks for get/set; $propagatable = [] lock.
- `models/settings/fields.yaml` — 3 lang-keyed fields.
- `classes/helper/PluginGuard.php` — final class; memoised isDisabled + reset; Log::warning on empty pixel_id.
- `classes/exception/MetaPixelException.php` — abstract base.
- `classes/exception/MissingPixelConfigException.php` — final boundary.
- `classes/exception/MissingCapiTokenException.php` — final boundary.
- `classes/exception/MetaApiTransientException.php` — final HTTP-aware (getHttpStatus).
- `classes/exception/MetaApiPermanentException.php` — final HTTP-aware (getHttpStatus).
- `tests/Unit/Helper/PluginGuardTest.php` — T7 (3 cases / 4 assertions).
- `tests/Unit/ExceptionHierarchyTest.php` — T15 (5 cases / 11 assertions).
- `tests/Feature/Settings/SettingsLookupForSiteTest.php` — T23 (3 cases / 3 assertions).
- `tests/Feature/Settings/SettingsCommonSettingsParentTest.php` — T24 (2 cases / 2 assertions).

### Modified (4)

- `Plugin.php` — adds `registerSettings()`; pint refactored use-block (added `use Logingrupa\Metapixel\Models\Settings`) + single-line empty body on `boot()`.
- `lang/en/lang.php` — `settings.*` tree added under existing `plugin.*` tree.
- `lang/lv/lang.php` — same shape, hand-written LV translation.
- `tests/Unit/PluginSanityTest.php` — added `test_register_settings_returns_descriptor_for_settings_model` (6 assertions).

## Decisions Made

- **Test directory casing: PascalCase preserved.** `tests/Unit/Helper/` + `tests/Feature/Settings/` follow plan 02-03a's `tests/Feature/Models/` + `tests/Feature/Migrations/` precedent. Plan 02-01's lowercase convention applies only to PSR-4-namespaced fixtures (`tests/doubles/`). Non-namespaced classic-style test classes live under PascalCase test subdirectories — phpunit's `<directory>` scanner picks them up regardless.
- **PHPStan level 10 mixed-cast workaround.** `Settings::get` returns `mixed` (inherited from October's `SettingModel`); naive `(string) self::get(...)` fires `cast.string` identifier at level 10 (and the CLAUDE.md project lock forbids `@phpstan-ignore` comments). Replaced with `is_string` runtime guard returning `''` fallback. Both `PluginGuard::isDisabled` and `Settings::lookupForSite` adopt this pattern. Result is type-safe at runtime + phpstan-clean at static-analysis time.
- **`Settings::clearInternalCache()` in `SettingsLookupForSiteTest::setUp()`.** SettingModel keeps a static `$instances` cache that survives across tests (DB resets per test via in-memory SQLite, instance cache does not). Without `clearInternalCache()` the third test (`test_lookup_for_site_returns_empty_strings_when_unset`) saw the previous test's `pixel_id=X` value leak in. Initial run reproduced this; one-line fix resolves it.
- **PluginGuard tearDown resets the memo.** Defence-in-depth across test files — without it, if a PluginGuardTest case set the memo to true, a subsequent test in a different file calling `PluginGuard::isDisabled()` would observe the leaked true without an explicit reset. The static memo persists across the test process lifetime (not the request lifetime).
- **PluginSanityTest extended (not a new test file) to cover `Plugin::registerSettings()`.** Coverage gate failed at 84.1% with just the 4 new test files because Plugin.php sat at 47.4% (registerSettings + boot uncovered). PluginSanityTest already uses `new Plugin($this->app)` for register/boot coverage — adding a `registerSettings` test follows the same pattern. After the addition, Plugin.php reports 100.0% and total coverage hits 100.0%.
- **Pint autofix changes accepted as-is:** removed superfluous `@var` PHPDoc on PluginGuard's `private static ?bool` (Pint's `no_superfluous_phpdoc_tags`); removed `@param` PHPDocs from MetaApi* exceptions where the param types are already declared (same rule); single-line empty body on `boot()` and 2 boundary exception bodies (`single_line_empty_body`); FQN `\Logingrupa\Metapixel\Models\Settings::class` replaced with imported `Settings::class` in Plugin.php (`fully_qualified_strict_types` + `ordered_imports`). All changes are stylistic; no semantic impact.
- **`@method` docblocks on Settings for static `get`/`set`.** Lovata's `CommonSettings` exposes them via inherited `SettingModel`->`Model` dynamic-method chain but no native typehints. larastan does not walk that chain at full strictness. Declared `@method static mixed get(string $sCode, mixed $mDefault = null)` + `@method static void set(array<string, mixed> $arValues)` on Settings to satisfy phpstan level 10 resolution at the call sites (PluginGuard, SettingsLookupForSiteTest, PluginGuardTest).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] SettingsLookupForSiteTest third case leaked previous test's value**

- **Found during:** Task 4 (first pest run after writing all 4 test files).
- **Issue:** `test_lookup_for_site_returns_empty_strings_when_unset` expected `['pixel_id' => '', 'capi_access_token' => '']` but observed `['pixel_id' => 'X', 'capi_access_token' => 'Y']` because the previous test `test_lookup_for_site_null_returns_stored_credentials` had set `pixel_id=X, capi_access_token=Y` and the static `SettingModel::$instances` cache survived the per-test DB reset.
- **Fix:** Added `Settings::clearInternalCache()` to `SettingsLookupForSiteTest::setUp()`. SettingModel exposes this static method specifically for this purpose. All 3 cases now see a fresh resolved instance.
- **Files modified:** `tests/Feature/Settings/SettingsLookupForSiteTest.php` (1 line in setUp).
- **Verification:** Pest re-run — all 13 new test cases pass.
- **Rationale:** Tests must be deterministic (Tiger-Style: "same input → same output"). Test order should not affect outcomes. The static instance cache is a real production concern too — any caller mutating Settings::set followed by Settings::lookupForSite within the same request lifecycle benefits from the cache; tests are the only place where state mutation across test boundaries surfaces it.

**2. [Rule 3 — Block fix] Pint autoformat to pass `pint --test` gate**

- **Found during:** Task 5 (initial `pint --test` failed with 6 files needing fixes).
- **Issue:** Laravel preset's `no_superfluous_phpdoc_tags`, `no_empty_phpdoc`, `single_line_empty_body`, `fully_qualified_strict_types`, `braces_position`, `ordered_imports` rules flagged 6 files. Plain `pint --test` reported `result:fail`.
- **Fix:** Ran `pint` (auto-fix). Removed redundant `@var` PHPDocs on private/static properties (types already declared); collapsed empty bodies to single-line `{}`; collapsed `\Logingrupa\Metapixel\Models\Settings::class` to imported `Settings::class` in Plugin.php; alphabetized imports.
- **Files modified:** Plugin.php, classes/exception/MetaApiPermanentException.php, classes/exception/MetaApiTransientException.php, classes/exception/MissingPixelConfigException.php, classes/exception/MissingCapiTokenException.php, classes/helper/PluginGuard.php.
- **Verification:** `pint --test` exits 0 (`result:passed`).
- **Rationale:** Pint is the project's source-of-truth formatter (composer qa step 1). Auto-fixing is the correct response; refusing to apply Pint's rules would block the gate.

**3. [Rule 1 — Bug] PHPStan level 10 mixed-cast errors on Settings::get reads**

- **Found during:** Task 5 (phpstan analyse fired 4 errors).
- **Issue:** `(string) Settings::get('pixel_id', '')` fires `cast.string` identifier at level 10 because `Settings::get` returns `mixed` (inherited from October's `SettingModel`). The CLAUDE.md project lock forbids `@phpstan-ignore` comments. Plus the `@method static void set(array $arValues)` PHPDoc fired `missingType.iterableValue` because the array key+value types were not specified.
- **Fix:** PluginGuard::isDisabled — replaced `(string) Settings::get('pixel_id', '')` with `$mPixelId = Settings::get('pixel_id', ''); $sPixelId = is_string($mPixelId) ? $mPixelId : '';`. Same pattern in Settings::lookupForSite for both pixel_id and capi_access_token. Settings @method set docblock widened from `array $arValues` to `array<string, mixed> $arValues`.
- **Files modified:** `classes/helper/PluginGuard.php`, `models/Settings.php`.
- **Verification:** `phpstan analyse` exits 0 (no errors).
- **Rationale:** Tiger-Style fail-fast preserved (no silent type coercion); runtime behavior matches the original intent (return string fallback if Settings::get returns non-string somehow); zero @phpstan-ignore comments added.

**4. [Rule 2 — Missing critical functionality] Plugin::registerSettings coverage gate**

- **Found during:** Task 5 (pest --coverage --min=90 failed at 84.1%).
- **Issue:** Without a test for Plugin::registerSettings, Plugin.php covered only 47.4% (lines for registerSettings + boot uncovered). 4 new files at 100% + Plugin.php at 47.4% averaged to 84.1% total — below the 90% gate.
- **Fix:** Added `test_register_settings_returns_descriptor_for_settings_model` to PluginSanityTest. Asserts: descriptor has 'settings' key; class is Settings::class; label/category lang keys; icon-bullseye; order 500.
- **Files modified:** `tests/Unit/PluginSanityTest.php` (+1 method, +6 assertions).
- **Verification:** Pest now reports Plugin.php at 100.0% + total at 100.0%.
- **Rationale:** Coverage gate exists for a reason — production code without test coverage is a real risk. Extending PluginSanityTest (which already had the pattern of `new Plugin($this->app)`->register/boot) is the right placement.

---

**Total deviations:** 4 auto-fixed (Rule 1 × 2, Rule 2 × 1, Rule 3 × 1)
**Impact on plan:** All auto-fixes necessary for correctness (test isolation, mixed-cast type safety) or to pass the QA gates (pint formatter, coverage gate). No scope creep — every fix is inside the plan's stated artifact set.

## Issues Encountered

- **Plugin standalone-composer-install limitation persists** (carry-forward from Phase 1 + plans 02-01..02-03a). `composer qa` from inside `plugins/logingrupa/metapixel/` exits 127 because plugin-local `vendor/bin/` does not exist. Workaround: host-vendor binaries at `/home/forge/nailscosmetics.lv/vendor/bin/{pint,phpstan,phpmd,pest}` + smoke phpstan config at `/tmp/metapixel-phpstan-smoke.neon` (absolute paths). Same as prior Phase 2 plans.
- **SettingModel static `$instances` cache requires explicit clear in test setUp.** Not a defect per se; documented as a pattern for future plans that need fresh Setting state between tests. If plan 02-04 or 02-06 grows multiple test files asserting against Settings, consider moving `Settings::clearInternalCache()` to MetapixelTestCase::setUp(). Deferred until a second consumer surfaces (Tiger-Style: don't abstract on a sample of one).

## Self-Check: PASSED

- All 12 created files exist on disk under `plugins/logingrupa/metapixel/`.
- All 5 commit hashes (`2b21b7e`, `2bc77d4`, `ca3752f`, `7665737`, `e5f471a`) present in `git log --oneline`.
- `vendor/bin/pest --testsuite='Metapixel Unit Tests,Metapixel Feature Tests' --coverage --min=90` exits 0 from plugin dir with **46 tests / 109 assertions / 100.0% coverage on all 13 in-scope production files**.
- `vendor/bin/pint --test Plugin.php classes models` exits 0 (`result:passed`).
- `vendor/bin/phpstan analyse --configuration /tmp/metapixel-phpstan-smoke.neon` reports "No errors" (level 10, phpVersion 80300).
- `vendor/bin/phpmd Plugin.php,classes,models text phpmd.xml` exits 0.
- All 4 new test files + PluginSanityTest pass H-8 lock: no `(new Plugin)->register()` to bind AdapterRegistry; `$this->app->singleton(AdapterRegistry::class)` direct bind.
- `composer validate --no-check-publish` reports `./composer.json is valid` (unchanged this plan).

## Test method names (pest output)

| # | Test class | Test method | Status |
|---|---|---|---|
| T7 | PluginGuardTest | test_is_disabled_returns_true_when_pixel_id_is_empty | PASS |
| T7 | PluginGuardTest | test_is_disabled_returns_false_when_pixel_id_is_set | PASS |
| T7 | PluginGuardTest | test_reset_clears_the_memo | PASS |
| T15 | ExceptionHierarchyTest | test_meta_pixel_exception_is_abstract_runtime_exception | PASS |
| T15 | ExceptionHierarchyTest | test_missing_pixel_config_extends_base_and_carries_context | PASS |
| T15 | ExceptionHierarchyTest | test_missing_capi_token_extends_base | PASS |
| T15 | ExceptionHierarchyTest | test_meta_api_transient_carries_http_status_and_context | PASS |
| T15 | ExceptionHierarchyTest | test_meta_api_permanent_carries_http_status_and_context | PASS |
| T23 | SettingsLookupForSiteTest | test_lookup_for_site_null_returns_stored_credentials | PASS |
| T23 | SettingsLookupForSiteTest | test_lookup_for_site_with_id_returns_same_as_null_in_phase_2_stub | PASS |
| T23 | SettingsLookupForSiteTest | test_lookup_for_site_returns_empty_strings_when_unset | PASS |
| T24 | SettingsCommonSettingsParentTest | test_settings_extends_common_settings | PASS |
| T24 | SettingsCommonSettingsParentTest | test_propagatable_is_empty_array_lock | PASS |
| — | PluginSanityTest | test_register_settings_returns_descriptor_for_settings_model | PASS |

**13 new + 1 extension to existing PluginSanityTest = 14 test cases / 29 assertions added; total 46 tests / 109 assertions in the suite.**

## Coverage report (from plugin dir)

| File | Coverage |
|---|---|
| Plugin.php | 100.0 % |
| classes/adapter/AdapterRegistry.php | 100.0 % |
| classes/adapter/EventSubjectAdapter.php | 100.0 % (interface) |
| classes/adapter/ValueResolver.php | 100.0 % (interface) |
| classes/exception/MetaApiPermanentException.php | 100.0 % |
| classes/exception/MetaApiTransientException.php | 100.0 % |
| classes/exception/MetaPixelException.php | 100.0 % |
| classes/exception/MissingCapiTokenException.php | 100.0 % |
| classes/exception/MissingPixelConfigException.php | 100.0 % |
| classes/helper/PluginGuard.php | 100.0 % |
| models/EventLog.php | 100.0 % |
| models/FailedEvent.php | 100.0 % |
| models/Settings.php | 100.0 % |
| **Total** | **100.0 %** |

## composer qa tail (host-vendor smoke run from `plugins/logingrupa/metapixel/`)

```
=== 1/4 pint-test (host vendor) ===
{"tool":"pint","result":"passed"}

=== 2/4 phpstan analyse (host vendor, level 10, phpVersion 80300) ===

 [OK] No errors


=== 3/4 phpmd Plugin.php,classes,models ===
phpmd exit=0

=== 4/4 pest --testsuite='Metapixel Unit Tests,Metapixel Feature Tests' --coverage --min=90 ===
  Tests:    46 passed (109 assertions)
  Duration: 1.35s

  Plugin .............................................................. 100.0%
  classes/adapter/AdapterRegistry ..................................... 100.0%
  classes/adapter/EventSubjectAdapter ................................. 100.0%
  classes/adapter/ValueResolver ....................................... 100.0%
  classes/exception/MetaApiPermanentException ......................... 100.0%
  classes/exception/MetaApiTransientException ......................... 100.0%
  classes/exception/MetaPixelException ................................ 100.0%
  classes/exception/MissingCapiTokenException ......................... 100.0%
  classes/exception/MissingPixelConfigException ....................... 100.0%
  classes/helper/PluginGuard .......................................... 100.0%
  models/EventLog ..................................................... 100.0%
  models/FailedEvent .................................................. 100.0%
  models/Settings ..................................................... 100.0%
  ────────────────────────────────────────────────────────────────────────────
                                                                Total: 100.0 %
```

Full QA log: `/tmp/02-03b-qa.log`.

## Phase 2 plan-state update

Plan **02-03b CLOSED**. Wave 2 complete (02-03a + 02-03b shipped sequentially on master).

- **02-04 (SiteResolver + EventLogWriter)** — UNBLOCKED. Requires 02-03a (EventLog model) + 02-03b (PluginGuard at the write boundary).
- **02-05 (MetaClient + PayloadBuilder + UserDataHasher)** — UNBLOCKED. Uses MetaApiTransient/Permanent exception classes; PayloadBuilder reads Settings::lookupForSite for per-site test_event_code.
- **02-06 (SendCapiEvent + ModelHandlers + event hooks)** — UNBLOCKED. Will call `Settings::lookupForSite` for credentials; throws Missing* exceptions when credentials are empty; persists FailedEvent rows on MetaApiPermanent via `MetaPixelException::getContext` payloads.
- **02-07 (FakeAdapterContractTest + ContractTestCase)** — UNBLOCKED. Testsuite already wired by plan 02-02.

## Threat Flags

(none — Settings + PluginGuard + exception hierarchy ship without introducing new network endpoints, auth paths, or schema changes. All threats from the plan's STRIDE register (T-02-03b-01 through T-02-03b-05) are mitigated or accepted as documented; T-02-03b-04 information disclosure mitigation enforced by the opt-in context array — callers control what they pass to `MetaPixelException::__construct`.)

## Phpstan ignoreErrors entries added (for 02-07 review)

(none — all phpstan level 10 errors fixed via runtime guards or PHPDoc widening, not via @phpstan-ignore. CLAUDE.md project lock forbids @phpstan-ignore comments; this plan honored that lock without exception.)

## Next Phase Readiness

- Wave 2 complete. Orchestrator can now spawn Wave 3 plans (02-04 + 02-05 in parallel; 02-06 + 02-07 follow).
- `Settings::clearInternalCache()` test-isolation pattern documented for future plans that grow multiple Setting-asserting test files.
- `@method` docblock pattern on Settings is the carrier for Lovata.Toolbox CommonSettings descendants at phpstan level 10 — apply same shape to any future settings class.
- Mixed-cast workaround (`is_string` runtime guard) replaces naive `(string) $mValue` at every Settings::get callsite in plans 02-04, 02-05, 02-06 — applies wherever a phpstan level 10 mixed-cast error appears.
- PluginGuard memo lifecycle pattern: production = single Log::warning per request; tests = explicit `reset()` in setUp + tearDown for cross-test isolation.

---

*Phase: 02-adapter-system-core-contracts-registry-extension-hooks*
*Plan: 3b*
*Completed: 2026-05-17*
