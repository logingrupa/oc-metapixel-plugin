---
phase: 02-adapter-system-core-contracts-registry-extension-hooks
plan: 1
subsystem: adapter-core
tags: [adapter, registry, interface, service-container, singleton, php83-83, lovata-toolbox, hungarian-notation, fail-fast]

requires:
  - phase: 01-tooling-composer-namespace-rename-ci-matrix
    provides: phpstan/phpmd/pest harness, MetapixelTestCase, plugin scaffold
provides:
  - EventSubjectAdapter interface (7 methods — opaque alias, subject id, site id from subject, secret key, ValueResolver factory, raw user data, supported events matrix)
  - ValueResolver interface (5 methods — content ids, value, currency, contents, num items)
  - AdapterRegistry final class — service-container singleton with register/all/resolveFor/resolveByClass; idempotent + order-agnostic register; is_a hierarchy walk on resolve; null on miss
  - Plugin::register() binds AdapterRegistry singleton
  - 6 shared test doubles under tests/doubles/ — FakeAdapter, FakeValueResolver, TestSubject, TestSubjectAdapter, ZeroIdSubjectAdapter, FakeStubAdapter
  - 5 unit test files under tests/Unit/Adapter/ — 11 tests / 21 assertions cover P-02 invariant
  - phpstan.neon and phpmd script extended to scan classes/
  - phpunit.xml source include extended with ./classes for coverage scope
affects:
  - 02-02 (PHPStan SiteManager/Request bans inside classes/adapter/)
  - 02-03a/03b (Settings + storage layer reference AdapterRegistry)
  - 02-04 (EventLogWriter consults AdapterRegistry::resolveFor for opaque subject_type)
  - 02-05 (SendCapiEvent::handle uses AdapterRegistry::resolveByClass)
  - 02-06 (FakeAdapterContractTest + ContractTestCase use the doubles)
  - 02-07 (PHPStan disallowed-calls scopes adapter dir)
  - phase 03 (ShopaholicOrderAdapter + ThemeActionAdapter implement these interfaces)

tech-stack:
  added: []
  patterns:
    - "AdapterRegistry as service-container singleton bound via Plugin::register()"
    - "Tests bind AdapterRegistry directly via \\$this->app->singleton in setUp() — never via (new Plugin)->register() (H-8 lock)"
    - "October ClassLoader convention — lowercase directory paths for namespaced PSR-4 classes under plugins/<vendor>/<plugin>/"
    - "Hungarian notation across registry + interfaces (\\$obSubject, \\$arAdapterMap, \\$sSubjectClass, \\$sAdapterClass)"
    - "L-4 lock — \\Illuminate\\Support\\Facades\\App via FQN import in adapter code"

key-files:
  created:
    - classes/adapter/EventSubjectAdapter.php
    - classes/adapter/ValueResolver.php
    - classes/adapter/AdapterRegistry.php
    - tests/doubles/FakeAdapter.php
    - tests/doubles/FakeValueResolver.php
    - tests/doubles/TestSubject.php
    - tests/doubles/TestSubjectAdapter.php
    - tests/doubles/ZeroIdSubjectAdapter.php
    - tests/doubles/FakeStubAdapter.php
    - tests/Unit/Adapter/AdapterRegistryTest.php
    - tests/Unit/Adapter/AdapterRegistrySingletonBindingTest.php
    - tests/Unit/Adapter/AdapterRegistryInvalidAdapterTest.php
    - tests/Unit/Adapter/AdapterRegistryBootOrderTest.php
    - tests/Unit/Adapter/AdapterRegistryFlushTest.php
  modified:
    - Plugin.php (imports AdapterRegistry, binds singleton in register())
    - phpstan.neon (paths now include classes)
    - composer.json (phpmd script extended to scan classes/)
    - phpunit.xml (source <include> adds <directory>./classes</directory>)

key-decisions:
  - "Plugin path convention: classes/adapter/ and tests/doubles/ MUST be lowercase folder names. October Rain ClassLoader normalizes namespaced lookups by lowercasing all folder portions before the file basename — PascalCase folder names (e.g., classes/Adapter/, tests/Doubles/) cause autoload misses on Linux. Namespaces stay PascalCase (Logingrupa\\Metapixel\\Classes\\Adapter\\…) because PHP namespace resolution is case-insensitive; only filesystem paths matter. Matches Lovata.Toolbox convention (classes/{collection,event,helper,store,…}) and the pre-existing composer-dependency-analyser.php rule path /classes/adapter/shopaholic."
  - "AdapterRegistry::\\$arAdapterMap PHPDoc key type is array<string, class-string<EventSubjectAdapter>> — not array<class-string, …>. register() accepts a plain string subject FQN; PHPStan level 10 cannot narrow a string parameter to class-string without an extra runtime check we deliberately do not add (registry is order-agnostic on the subject side)."
  - "Test files under tests/Unit/Adapter/ are NOT namespaced. Pest discovers them via the phpunit.xml <directory> scanner. Matches the existing Phase 1 PluginSanityTest convention."
  - "Test setUp pattern locked across Phase 2: \\$this->app->singleton(AdapterRegistry::class) — bypasses Plugin::register() entirely. (new \\Logingrupa\\Metapixel\\Plugin)->register() TypeErrors because PluginBase::__construct(Application \\$app) requires container injection. H-8 anchor."

patterns-established:
  - "AdapterRegistry singleton binding pattern: \\$this->app->singleton(AdapterRegistry::class) in Plugin::register(); third parties register adapters from their own boot() via AdapterRegistry::register(\\$sSubjectClass, \\$sAdapterClass)"
  - "is_a-walk semantics for hierarchy resolution: foreach map in insertion order, return first is_a match; sibling-class collision documented as undefined (priority API deferred to v2.1)"
  - "InvalidArgumentException on register() with non-EventSubjectAdapter class — fail-fast at registration time, not at queue-time"
  - "Pattern for fresh-per-test isolation: \\$this->app->forgetInstance(AdapterRegistry::class) drops the resolved singleton, next make() returns a fresh empty registry"
  - "Shared test doubles ship under tests/doubles/ (autoload-dev) — every downstream plan imports by FQN to eliminate inline-class redeclaration collisions across test files"

requirements-completed:
  - ADAP-01
  - ADAP-02
  - ADAP-03

duration: ~12 min
completed: 2026-05-17
---

# Phase 02 Plan 01: Interface contracts + AdapterRegistry singleton + shared test doubles Summary

**Adapter system core landed — EventSubjectAdapter + ValueResolver interfaces, AdapterRegistry final class (singleton-bound + is_a hierarchy walk + idempotent register), Plugin::register() binding, and 6 shared test doubles ready for downstream Phase 2 plans to import by FQN.**

## Performance

- **Duration:** ~12 min (2026-05-17 20:55:27Z → 21:07:04Z)
- **Tasks:** 6 (all auto-mode, no checkpoints)
- **Commits:** 7 (5 task commits + 1 Rule-1 case-rename fix + 1 Task-6 QA-feedback fix)
- **Files created:** 14
- **Files modified:** 4

## Accomplishments

- Shipped the two locked Phase 2 contract interfaces verbatim from RESEARCH.md §4.1 / §4.2 — `EventSubjectAdapter` (7 methods) + `ValueResolver` (5 methods). PHPDocs document the opaque-alias (P-05) and cross-context-determinism (P-01) constraints in prose, not by marker.
- Shipped `AdapterRegistry` final class with 4 public methods (register / all / resolveFor / resolveByClass). `is_subclass_of` guard with `InvalidArgumentException` (fail-fast at register-time). `is_a()` hierarchy walk inside `resolveFor` with foreach insertion-order semantics, returning null on miss. Class-level PHPDoc documents singleton binding, test-swap idiom, and the sibling-class collision gotcha (priority deferred to v2.1).
- Wired `Plugin::register()` to bind `AdapterRegistry` as a service-container singleton. `boot()` stays empty until plan 02-03b (`registerSettings()`).
- Shipped 6 shared test doubles under `tests/doubles/` (autoload-dev only) so plans 02-04 / 02-06 / 02-07 import by FQN — eliminates H-6 / M-9 inline-class redeclaration collisions across test files. `SpyMetaClient` deferred to plan 02-05 Task 6 alongside `MetaClient`.
- Shipped 5 unit test files exercising T1–T5 from RESEARCH §6: idempotent register, is_a hierarchy walk, null-on-miss, FQN list, same-pair idempotency, resolveByClass FQN rehydrate, singleton-instance identity, container instance() swap, InvalidArgumentException on bad adapter class, **P-02 boot-order invariance** (the P-02 anchor — both A,B and B,A registration orders resolve identically), and per-test forgetInstance flush.
- `composer qa` smoke chain exits 0: pint-test passed, phpstan level 10 phpVersion 80300 no errors, phpmd no violations, pest 14 tests / 26 assertions / **100% coverage on Plugin.php + AdapterRegistry + EventSubjectAdapter + ValueResolver**.

## Task Commits

Each task was committed atomically:

1. **Task 1: Create EventSubjectAdapter + ValueResolver interfaces** — `d10234e` (feat)
2. **Task 2: Create AdapterRegistry final class** — `9ff2f72` (feat)
3. **Task 3: Wire Plugin::register() singleton binding + expand phpstan/phpmd to classes** — `9bf5473` (feat)
4. **Task 4: Ship 6 shared test doubles under tests/doubles/** — `9694d30` (test)
5. **[Rule 1 auto-fix] Lowercase classes/adapter + tests/doubles for October ClassLoader** — `b40012f` (fix)
6. **Task 5: AdapterRegistry unit tests T1-T5 (P-02 anchor)** — `44df886` (test)
7. **[Task 6 / Rule 1+3 auto-fix] pint phpdoc cleanup + phpstan property type variance + phpunit coverage scope** — `c5fda33` (fix)

`docs(02-01)` metadata commit ships separately with this SUMMARY.md + STATE.md + ROADMAP.md + REQUIREMENTS.md.

## Files Created/Modified

### Created (14)

- `classes/adapter/EventSubjectAdapter.php` — interface contract (ADAP-01).
- `classes/adapter/ValueResolver.php` — interface contract (ADAP-02).
- `classes/adapter/AdapterRegistry.php` — service-container singleton registry (ADAP-03).
- `tests/doubles/FakeAdapter.php` — D-08 fluent EventSubjectAdapter double.
- `tests/doubles/FakeValueResolver.php` — D-10 constructor-defaults ValueResolver double.
- `tests/doubles/TestSubject.php` — DTO subject fixture.
- `tests/doubles/TestSubjectAdapter.php` — adapter whose getSubjectId reads `$obSubject->iId`.
- `tests/doubles/ZeroIdSubjectAdapter.php` — variant returning 0 for EventLogWriter `<= 0` reject branch.
- `tests/doubles/FakeStubAdapter.php` — immutable minimal stub for hook-isolation tests.
- `tests/Unit/Adapter/AdapterRegistryTest.php` — T1 register + resolveFor + is_a walk coverage (6 tests).
- `tests/Unit/Adapter/AdapterRegistrySingletonBindingTest.php` — T2 singleton / instance-swap (2 tests).
- `tests/Unit/Adapter/AdapterRegistryInvalidAdapterTest.php` — T3 InvalidArgumentException on bad adapter (1 test).
- `tests/Unit/Adapter/AdapterRegistryBootOrderTest.php` — T4 P-02 boot-order invariance (1 test).
- `tests/Unit/Adapter/AdapterRegistryFlushTest.php` — T5 forgetInstance per-test reset (1 test).

### Modified (4)

- `Plugin.php` — imports `AdapterRegistry`, binds `$this->app->singleton(AdapterRegistry::class)` in `register()`. Class-level PHPDoc refreshed (no phase markers).
- `phpstan.neon` — paths list extends from `[Plugin.php]` to `[Plugin.php, classes]`.
- `composer.json` — `phpmd` script scans `Plugin.php,classes` (was `Plugin.php` only).
- `phpunit.xml` — `<source><include>` adds `<directory>./classes</directory>` so coverage scope tracks the new adapter folder. Closes the STATE.md pending todo "Phase 2+ phpunit.xml source include reopen when classes/, models/, … land" for the `classes/` portion.

## Decisions Made

- **Folder casing is lowercase under plugins/.** Discovered during Task 5 pest run. October Rain's `ClassLoader::load` normalises namespaced lookups by lowercasing every folder before the file basename. PascalCase folders (`classes/Adapter/`, `tests/Doubles/`) caused autoload misses on Linux and surfaced as `InvalidArgumentException("must implement EventSubjectAdapter")` because `is_subclass_of` returns false when the interface cannot autoload. Renamed both to lowercase. Matches the Lovata.Toolbox convention (`classes/{collection,event,helper,store,…}`) and the existing `composer-dependency-analyser.php` path `__DIR__.'/classes/adapter/shopaholic'`. Namespaces stay PascalCase (PHP is case-insensitive on namespaces) — only filesystem paths changed.
- **Test files under `tests/Unit/Adapter/` are NOT namespaced** — they are pure global-scope PHPUnit classic-style test classes, discovered by pest via the phpunit.xml `<directory>` scanner. Matches the Phase 1 PluginSanityTest precedent (L-8 lock confirmation).
- **`AdapterRegistry::$arAdapterMap` PHPDoc key type is `array<string, class-string<EventSubjectAdapter>>`** — not `array<class-string, …>`. `register()` accepts a plain string subject FQN; PHPStan level 10 cannot narrow `string` to `class-string` without an extra runtime check, and the registry does NOT need that narrowing on the subject side (the adapter-side narrowing IS enforced by `is_subclass_of`).
- **H-8 test setUp pattern locked across all Phase 2 tests:** `$this->app->singleton(AdapterRegistry::class)` direct bind in `setUp()`. NEVER `(new \Logingrupa\Metapixel\Plugin)->register()` — `PluginBase::__construct(Application $app)` requires container injection and the bare instantiation TypeErrors.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] Lowercase `classes/adapter/` + `tests/doubles/` folder paths**

- **Found during:** Task 5 (initial pest run failed with `InvalidArgumentException("Adapter Logingrupa\Metapixel\Tests\Doubles\FakeAdapter must implement Logingrupa\Metapixel\Classes\Adapter\EventSubjectAdapter")` on 7 of 11 tests).
- **Issue:** Plan listed paths as `classes/Adapter/` and `tests/Doubles/` (PascalCase folders). October Rain's `ClassLoader::load` normalises namespaced PSR-style lookups by lowercasing every folder portion before the file basename. Linux is case-sensitive, so the PascalCase folders caused autoload misses; `is_subclass_of($sAdapterClass, EventSubjectAdapter::class)` returned false because the interface failed to autoload, and the guard threw.
- **Fix:** `git mv classes/Adapter classes/adapter` and `git mv tests/Doubles tests/doubles`. Composer dump-autoload re-run. Pest then passed 11/11.
- **Files modified:** 9 file renames (3 `classes/adapter/*` + 6 `tests/doubles/*`).
- **Verification:** `vendor/bin/pest --testsuite='Metapixel Unit Tests'` → 14 tests / 26 assertions / 100% coverage.
- **Committed in:** `b40012f` (separate Rule 1 fix commit).
- **Rationale:** Matches the Lovata.Toolbox convention (`classes/{collection,event,helper,store,…}` — all lowercase) and the pre-existing `composer-dependency-analyser.php` path `__DIR__.'/classes/adapter/shopaholic'`. The CLAUDE.md addendum + ARCHITECTURE.md class-shape sections already used lowercase forms; the plan's PascalCase variant was the outlier.

**2. [Rule 1 — Bug] AdapterRegistry property PHPDoc key-type variance**

- **Found during:** Task 6 (`composer qa` smoke run, phpstan level 10).
- **Issue:** `private array $arAdapterMap` PHPDoc said `array<class-string, class-string<EventSubjectAdapter>>` but `register()` accepts a plain `string $sSubjectClass`. PHPStan cannot narrow `string` → `class-string` without an extra runtime check.
- **Fix:** Widened key type to `array<string, class-string<EventSubjectAdapter>>`. Value type still `class-string<EventSubjectAdapter>` — that narrowing IS enforced by the `is_subclass_of` guard.
- **Files modified:** `classes/adapter/AdapterRegistry.php` (1 line).
- **Verification:** `vendor/bin/phpstan analyse` reports no errors.
- **Committed in:** `c5fda33`.

**3. [Rule 3 — Block fix] pint superfluous-PHPDoc removal**

- **Found during:** Task 6 (`composer qa` smoke run, pint-test).
- **Issue:** EventSubjectAdapter + ValueResolver had `@return` PHPDoc tags duplicating the already-typed return signatures. Laravel preset `no_superfluous_phpdoc_tags + phpdoc_trim` rules flag these.
- **Fix:** Ran `pint` to auto-strip the redundant `@return` lines (one-line PHPDoc summaries remain).
- **Files modified:** `classes/adapter/EventSubjectAdapter.php`, `classes/adapter/ValueResolver.php`.
- **Verification:** `vendor/bin/pint --test` exits 0 (passed).
- **Committed in:** `c5fda33`.

**4. [Rule 2 — Missing critical functionality] phpunit.xml coverage scope**

- **Found during:** Task 6 first coverage run reported only Plugin.php in the coverage report (denominator).
- **Issue:** `phpunit.xml`'s `<source><include>` only listed `Plugin.php`. The new `classes/adapter/*` files were entirely outside the coverage scope, so the `--min=90` gate passed trivially without actually measuring the new code. STATE.md already flagged this as a Phase 2+ pending todo ("phpunit.xml source include reopen when classes/, models/, … land").
- **Fix:** Added `<directory>./classes</directory>` to `<source><include>`. Coverage now reports 100% on AdapterRegistry + EventSubjectAdapter + ValueResolver alongside Plugin.php.
- **Files modified:** `phpunit.xml`.
- **Verification:** `vendor/bin/pest --coverage --min=90` → "Total: 100.0 %" with all 4 files listed.
- **Committed in:** `c5fda33`.
- **Rationale:** Without this fix the coverage gate gives a false-positive pass. Closes part of the STATE.md pending todo for `classes/`; downstream phases will append `models/`, `components/`, `middleware/`, `controllers/`, `console/` as those land.

---

**Total deviations:** 4 auto-fixed (Rule 1 × 2, Rule 2 × 1, Rule 3 × 1)
**Impact on plan:** All auto-fixes necessary for correctness (autoload, type variance) or to make the coverage gate meaningful (Rule 2). No scope creep — every fix is inside the plan's stated artifact set.

## Issues Encountered

- **Plugin's `vendor/autoload.php` is NOT loaded by host bootstrap.** Host project's `bootstrap/autoload.php` loads the host composer autoload only. The plugin's PSR-4 (capital `Classes/Adapter/`) is never registered at runtime; October Rain's ClassLoader (lowercase-folder convention) is the only autoloader that finds the plugin's namespaced classes. Plan task descriptions assumed the plugin PSR-4 would resolve `classes/Adapter/EventSubjectAdapter.php` directly — it doesn't, because plugin composer install is blocked on October private packages (same standalone-install limitation Phase 1 carried). Resolution: renamed folders to lowercase (Deviation 1).

## Self-Check: PASSED

- All 14 created files exist on disk under `plugins/logingrupa/metapixel/`.
- All 7 commit hashes (`d10234e`, `9ff2f72`, `9bf5473`, `9694d30`, `b40012f`, `44df886`, `c5fda33`) present in `git log --oneline`.
- `vendor/bin/pest --testsuite='Metapixel Unit Tests' --coverage --min=90` exits 0 with **14 tests / 26 assertions / 100% coverage on all 4 in-scope production files**.
- `vendor/bin/pint --test` exits 0.
- `vendor/bin/phpstan analyse --configuration /tmp/metapixel-phpstan-smoke.neon` reports "No errors" (level 10, phpVersion 80300).
- `vendor/bin/phpmd Plugin.php,classes text phpmd.xml` exits 0.
- `deps` (`composer-dependency-analyser`) deferred to CI per the standalone-install limitation documented in Phase 1 plans 01-02 + 01-03 — unchanged carry-forward.

## composer qa tail (smoke run from `plugins/logingrupa/metapixel/`)

```
=== 1/4 pint-test ===
{"tool":"pint","result":"passed"}

=== 2/4 phpstan analyse (level 10, phpVersion 80300) ===
 [OK] No errors

=== 3/4 phpmd Plugin.php,classes ===
phpmd exit=0

=== 4/4 pest --coverage --min=90 (Unit suite) ===
  PASS  AdapterRegistryBootOrderTest
  ✓ resolution outcome is invariant across registration order

  PASS  AdapterRegistryFlushTest
  ✓ app forget instance re binds fresh singleton

  PASS  AdapterRegistryInvalidAdapterTest
  ✓ register throws when adapter class does not implement event subject adapter

  PASS  AdapterRegistrySingletonBindingTest
  ✓ singleton binding returns same instance
  ✓ app instance swaps fresh registry for test isolation

  PASS  AdapterRegistryTest
  ✓ register and resolve for returns adapter instance
  ✓ resolve for walks class hierarchy via is a
  ✓ resolve for returns null when subject not registered
  ✓ all returns list of registered adapter class names
  ✓ register same pair twice is idempotent
  ✓ resolve by class returns adapter instance by fqn

  PASS  PluginSanityTest
  ✓ plugin class loads via psr4 autoload
  ✓ plugin details returns lang keys under renamed namespace
  ✓ register and boot are callable without error

  Tests:    14 passed (26 assertions)
  Duration: 0.43s

  Plugin .............................................................. 100.0%
  classes/adapter/AdapterRegistry ..................................... 100.0%
  classes/adapter/EventSubjectAdapter ................................. 100.0%
  classes/adapter/ValueResolver ....................................... 100.0%
  ────────────────────────────────────────────────────────────────────────────
                                                                Total: 100.0 %
```

## Five test method names (pest output)

| # | Test class | Test method | Status |
|---|---|---|---|
| T1 | AdapterRegistryTest | test_register_and_resolve_for_returns_adapter_instance | PASS |
| T1 | AdapterRegistryTest | test_resolve_for_walks_class_hierarchy_via_is_a | PASS |
| T1 | AdapterRegistryTest | test_resolve_for_returns_null_when_subject_not_registered | PASS |
| T1 | AdapterRegistryTest | test_all_returns_list_of_registered_adapter_class_names | PASS |
| T1 | AdapterRegistryTest | test_register_same_pair_twice_is_idempotent | PASS |
| T1 | AdapterRegistryTest | test_resolve_by_class_returns_adapter_instance_by_fqn | PASS |
| T2 | AdapterRegistrySingletonBindingTest | test_singleton_binding_returns_same_instance | PASS |
| T2 | AdapterRegistrySingletonBindingTest | test_app_instance_swaps_fresh_registry_for_test_isolation | PASS |
| T3 | AdapterRegistryInvalidAdapterTest | test_register_throws_when_adapter_class_does_not_implement_event_subject_adapter | PASS |
| T4 | AdapterRegistryBootOrderTest | test_resolution_outcome_is_invariant_across_registration_order | PASS |
| T5 | AdapterRegistryFlushTest | test_app_forget_instance_re_binds_fresh_singleton | PASS |

11 new tests + 3 PluginSanityTest = 14 total, all PASS, 26 assertions.

## Coverage report

| File | Coverage |
|---|---|
| Plugin.php | 100.0 % |
| classes/adapter/AdapterRegistry.php | 100.0 % |
| classes/adapter/EventSubjectAdapter.php | 100.0 % (interface — no executable code) |
| classes/adapter/ValueResolver.php | 100.0 % (interface — no executable code) |
| **Total** | **100.0 %** |

PHPStan exclusion note: the two interface files report 100% trivially because they declare no executable code beyond signatures (PHPStan + pest count them as fully covered by mere class-load).

## Doubles inventory

6 files under `tests/doubles/` (autoload-dev only — never loaded by production composer install --no-dev):

| Class | Role |
|---|---|
| `Logingrupa\Metapixel\Tests\Doubles\FakeAdapter` | Fluent EventSubjectAdapter double with 7 `with*` setters (D-08). Defaults: subject_type='fake.subject', subject_id=1, site_id=null, supportedEvents=['Purchase'=>['capi','pixel']]. |
| `Logingrupa\Metapixel\Tests\Doubles\FakeValueResolver` | Constructor-defaults ValueResolver double (D-10). Defaults: contentIds=['SKU-1'], value=10.0, currency='EUR', contents=[1-line], numItems=1. |
| `Logingrupa\Metapixel\Tests\Doubles\TestSubject` | Plain DTO with `public int $iId = 42`. |
| `Logingrupa\Metapixel\Tests\Doubles\TestSubjectAdapter` | Adapter whose getSubjectId reads `$obSubject->iId`; constructor-supplied `$iSiteId` override. |
| `Logingrupa\Metapixel\Tests\Doubles\ZeroIdSubjectAdapter` | extends TestSubjectAdapter — getSubjectId returns 0 (T17 EventLogWriter reject branch). |
| `Logingrupa\Metapixel\Tests\Doubles\FakeStubAdapter` | Immutable minimal EventSubjectAdapter for hook-isolation unit tests (plan 02-05). |

**SpyMetaClient deferred** to plan 02-05 Task 6 — lands alongside `Classes\Meta\MetaClient` so it can `extends MetaClient` without breaking php -l / phpstan on a forward reference.

## Phase 2 plan-state update

Plan **02-01 CLOSED**. Wave 1 partially complete:

- **02-02 (Multi-pixel + Settings stub + PHPStan SiteManager/Request ban)** — unblocked (Wave 1 sibling — sequential because both modify `phpstan.neon`; already executing per orchestrator note).
- **02-03a (storage layer — migrations + EventLog + FailedEvent models + Settings + PluginGuard)** — unblocked (Wave 2 sequential predecessor).
- **02-03b, 02-04, 02-05, 02-06, 02-07** — still blocked transitively on 02-03a (Wave 2 → Wave 3 → … chain).

## Next Phase Readiness

- Plan 02-02 next on the same branch (orchestrator-driven sequential — same `phpstan.neon` file edit boundary).
- `vendor/autoload.php` regenerated with `--dev` so plan 02-02 tests can load `Logingrupa\Metapixel\Classes\Adapter\…` without additional dump-autoload calls.
- `tests/doubles/` populated with 6 of the 7 planned fixtures (SpyMetaClient lands in 02-05).
- AdapterRegistry singleton-binding pattern is the carrier for D-14 across the rest of Phase 2. Every Phase 2 test must use the H-8 `$this->app->singleton(AdapterRegistry::class)` setUp idiom — already locked in plan 02-01..02-07 frontmatters.

---

*Phase: 02-adapter-system-core-contracts-registry-extension-hooks*
*Plan: 1*
*Completed: 2026-05-17*
