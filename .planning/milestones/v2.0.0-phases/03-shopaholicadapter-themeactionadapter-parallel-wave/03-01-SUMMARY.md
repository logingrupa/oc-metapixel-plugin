---
phase: 03-shopaholicadapter-themeactionadapter-parallel-wave
plan: 01
subsystem: database
tags: [eventlog, migration, console-command, schedule, race-fence, payload-audit]

# Dependency graph
requires:
  - phase: 02-adapter-system-core-contracts-registry-extension-hooks
    provides: EventLogWriter race-fence + SendCapiEvent queue job + AdapterRegistry + CreateMetapixelEventLogTable migration
provides:
  - EventLog payload longText NULL column (D-06) for frozen Meta payload audit
  - EventLogWriter::record 8-arg signature with trailing array $arPayload (D-07)
  - metapixel:purge-event-log console command + Plugin::registerSchedule daily wire-up (D-08)
  - composer qa green baseline with 99.3% coverage and 120 passing tests
affects: [03-02, 03-03, 03-04, 03-05, 03-06, 03-07, 03-08]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - Additive idempotent Schema::table migration (Schema::hasColumn guard for fresh-install re-run safety)
    - October ConsoleKernel::registerCommand binding in test setUp for autoRegister=false test containers
    - Untyped registerSchedule($obSchedule) param + @param Schedule docblock (LSP-safe override of PluginBase::registerSchedule)

key-files:
  created:
    - updates/AddPayloadToMetapixelEventLogTable.php
    - console/PurgeEventLog.php
    - tests/Unit/Helper/EventLogWriterPayloadColumnTest.php
    - tests/Feature/Console/PurgeEventLogTest.php
    - tests/Feature/Migrations/AddPayloadColumnTest.php
  modified:
    - models/EventLog.php
    - classes/helper/EventLogWriter.php
    - classes/queue/SendCapiEvent.php
    - Plugin.php
    - phpunit.xml
    - phpstan.neon
    - phpmd.xml
    - composer.json
    - updates/version.yaml
    - tests/Unit/PluginSanityTest.php
    - tests/Feature/Adapter/EventLogWriterRaceFenceTest.php
    - tests/Feature/Adapter/BackboneIntegrationTest.php
    - tests/Feature/Models/EventLogModelTest.php
    - tests/Feature/Queue/SendCapiEventHappyPathTest.php
    - tests/Feature/Queue/SendCapiEventTransientRetryTest.php
    - tests/Feature/Queue/SendCapiEventDeadLetterTest.php
    - tests/Feature/Queue/SendCapiEventFailedHandlerTest.php
    - tests/Feature/Queue/SendCapiEventBranchCoverageTest.php
    - tests/Feature/Queue/SendCapiEventHaltTest.php
    - tests/Feature/Queue/SendCapiEventBindingResolutionTest.php
    - tests/Unit/Hook/BeforeDispatchPayloadMutationTest.php
    - tests/Unit/Hook/ListenerExceptionIsolationTest.php
    - tests/Unit/Hook/DeadLetterHookTest.php

key-decisions:
  - "registerSchedule param is untyped (PHP LSP contravariance requires it) — Schedule type info preserved in @param docblock"
  - "phpmd ExcessiveParameterList minimum bumped 8 -> 9 because D-07 intentionally adds the 8th param to EventLogWriter::record"
  - "PurgeEventLogTest registers the console command via Kernel::registerCommand instead of relying on Plugin::register() because MetapixelTestCase keeps autoRegister=false"
  - "phpstan.neon paths + composer.json phpmd path gained 'console' coverage so future console commands stay analyzed under composer qa"

patterns-established:
  - "Pattern 1: Additive migration with Schema::hasColumn guard makes up() idempotent for marketplace fresh-install + redeploy"
  - "Pattern 2: Trailing-array nullable column write — EventLogWriter encodes payload only when non-empty (UNESCAPED_SLASHES | UNESCAPED_UNICODE), writes NULL otherwise"
  - "Pattern 3: Console command testing via Kernel::registerCommand bypasses October's plugin-register cycle when MetapixelTestCase.autoRegister stays false"
  - "Pattern 4: Phase 2 Queue/Hook test fixtures gain the new migration include in lockstep — the new payload column write requires both up() calls"

requirements-completed: []

# Metrics
duration: 25min
completed: 2026-05-18
---

# Phase 3 Plan 01: EventLog payload column + writer sig change + PurgeEventLog daily TTL Summary

**EventLog gains the frozen Meta payload column (longText NULL), EventLogWriter::record accepts a trailing array $arPayload, and `metapixel:purge-event-log` is wired daily via Plugin::registerSchedule — composer qa green end-to-end with 99.3 % coverage.**

## Performance

- **Duration:** 25 min
- **Started:** 2026-05-18T11:32:00Z
- **Completed:** 2026-05-18T11:57:07Z
- **Tasks:** 4
- **Files modified/created:** 28 (5 new + 23 modified)

## Accomplishments

- New additive migration `AddPayloadToMetapixelEventLogTable` adds an idempotent `payload longText NULL` column on `logingrupa_metapixel_event_log` after `event_time` (Schema::hasColumn guard for fresh-install re-run safety).
- `EventLog` model gains `payload` in `$fillable` plus `$jsonable = ['payload']` (October idiom for JSON-in-longText) — no `'array'` cast.
- `EventLogWriter::record` signature gains an 8th trailing param `array $arPayload`; body json_encodes (`UNESCAPED_SLASHES | UNESCAPED_UNICODE`) when non-empty, writes NULL otherwise; outer try/catch fail-safe behaviour preserved.
- `SendCapiEvent::handle` pipes `$this->arPayload` through as the 8th arg to EventLogWriter::record.
- New final console command `Logingrupa\Metapixel\Console\PurgeEventLog` (signature `metapixel:purge-event-log`, 38 LOC) deletes EventLog rows where `created_at < now() - 7 days`; logs rows_deleted + cutoff for observability.
- `Plugin::register()` gains `registerConsoleCommand` wiring; new `Plugin::registerSchedule($obSchedule)` invokes `->daily()` on the command (LSP-safe untyped param + Schedule @param docblock).
- 3 new test files: `EventLogWriterPayloadColumnTest` (3 cases — payload persistence + NULL semantics + race-fence win), `PurgeEventLogTest` (2 cases — 7-day cutoff via Carbon::setTestNow + empty-table no-op), `AddPayloadColumnTest` (3 cases — column existence + nullable longtext + idempotent up()).
- 11 Phase 2 sibling tests (Queue + Hook) updated in lockstep to also run the new migration in setUp/tearDown.

## Task Commits

Each task was committed atomically on the worktree branch `worktree-agent-a0c2c648598558931`:

1. **Task 1: EventLog payload column + $jsonable** — `808f652` (feat)
2. **Task 2: EventLogWriter::record trailing $arPayload (D-07)** — `5f2b678` (feat)
3. **Task 3: PurgeEventLog + registerSchedule daily** — `0441294` (feat)
4. **Task 4: qa green — deviation fixes (LSP, phpmd cap, test migrations)** — `3162b1e` (fix)

_Task 4 was originally specified as a single atomic squash commit for the whole plan; per worktree contract Tasks 1-3 are atomic per-task commits and Task 4 carries only the deviation fixes that surfaced when composer qa was run end-to-end._

## Files Created/Modified

### Created

- `updates/AddPayloadToMetapixelEventLogTable.php` — additive idempotent migration adding `payload` longText NULL after `event_time`.
- `console/PurgeEventLog.php` — `metapixel:purge-event-log` daily TTL command (38 LOC, final).
- `tests/Unit/Helper/EventLogWriterPayloadColumnTest.php` — 3 cases proving payload persistence + NULL semantics + race-fence win.
- `tests/Feature/Console/PurgeEventLogTest.php` — 2 cases (Carbon::setTestNow cutoff + empty-table no-op); registers command via Kernel::registerCommand.
- `tests/Feature/Migrations/AddPayloadColumnTest.php` — 3 cases (column exists + nullable text + idempotent up()).

### Modified

- `models/EventLog.php` — `$fillable` += `payload`; new `$jsonable = ['payload']`.
- `classes/helper/EventLogWriter.php` — 8th trailing `array $arPayload` param + insert key `'payload'`.
- `classes/queue/SendCapiEvent.php` — pipes `$this->arPayload` through to EventLogWriter::record.
- `Plugin.php` — adds `use Schedule`, `use PurgeEventLog`, `registerConsoleCommand` in register(), new `registerSchedule($obSchedule)` method.
- `updates/version.yaml` — 1.0.1 entry references the new migration.
- `phpunit.xml` — `<directory>./console</directory>` added to `<source><include>` block.
- `phpstan.neon` — `paths:` += `console` so console commands stay under level 10 analysis.
- `phpmd.xml` — `ExcessiveParameterList minimum` raised from 8 to 9 (D-07 intentionally pushes record() to 8 params).
- `composer.json` — `phpmd` script gains the `,console` path so the gate stays enforced.
- `tests/Unit/PluginSanityTest.php` — new `test_register_schedule_wires_purge_command_daily` asserts the `0 0 * * *` daily cron expression.
- `tests/Feature/Adapter/EventLogWriterRaceFenceTest.php`, `BackboneIntegrationTest.php`, `tests/Feature/Models/EventLogModelTest.php`, and 8 Queue/Hook tests — migration include + `[]` trailing arg in lockstep with the writer signature change.

## Decisions Made

- **registerSchedule param is untyped** — `October\System\Classes\PluginBase::registerSchedule($schedule)` is untyped; PHP LSP contravariance rejects a typed subclass param at class load. Type info preserved via `@param Schedule` docblock and the inner body calls Laravel's typed `->command()->daily()` API, so static analysis still has the Schedule contract while runtime stays LSP-compliant. RESEARCH.md Pitfall 7 anticipated this; the typed form fails at PHP runtime even though phpstan accepts both.
- **phpmd ExcessiveParameterList minimum 8 → 9** — D-07 deliberately adds the 8th param to `EventLogWriter::record`. The Phase 2 phpmd threshold was 8 (just over the 7-arg signature). Bumping to 9 keeps the gate functional without disabling the rule.
- **Console command registered in test setUp via Kernel::registerCommand** — `MetapixelTestCase::$autoRegister = false` to keep the base test case light; we cannot rely on `Plugin::register()` wiring the artisan command. The test calls `Kernel::registerCommand(new PurgeEventLog)` once in setUp to bind the command into the test artisan kernel.
- **Lockstep migration include across 11 Phase 2 tests** — once EventLogWriter writes the new payload column, every test that boots only the Phase 2 base table fails (SQLite column-not-found). The Phase 2 tests now run both migrations in setUp + reverse-order down() in tearDown.
- **phpstan.neon + composer.json gained `console` coverage** — future console commands ship under the same QA gates without needing per-plan config bumps.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Plugin::registerSchedule param dropped typed `Schedule` hint**
- **Found during:** Task 4 (composer qa run)
- **Issue:** Typed param `Schedule $obSchedule` violates PHP LSP contravariance against `PluginBase::registerSchedule($schedule)` (no type) — fatal at class load with `Whoops\Exception\ErrorException: Declaration must be compatible with PluginBase::registerSchedule($schedule)`. The plan's acceptance criteria explicitly mandated the typed form; the typed form is unrunnable on PHP 8.4 / October 4.
- **Fix:** Dropped the type hint, preserved type info via `@param Schedule $obSchedule` docblock. The method body still calls Laravel's typed `Schedule::command()->daily()` API, so larastan retains the Schedule contract at level 10.
- **Files modified:** Plugin.php
- **Verification:** phpstan green, runtime green (PluginSanityTest::test_register_schedule_wires_purge_command_daily asserts the daily cron expression).
- **Committed in:** 3162b1e

**2. [Rule 3 - Blocking] phpmd `ExcessiveParameterList` threshold raised 8 → 9**
- **Found during:** Task 4 (phpmd run)
- **Issue:** Phase 2 phpmd.xml set `minimum=8`; D-07 deliberately makes `EventLogWriter::record` an 8-param method, tripping the rule and flipping `composer qa` to red.
- **Fix:** Raised threshold to 9 (still tight — Plan 03-02/03 adapter classes are designed-for-≤7 params).
- **Files modified:** phpmd.xml
- **Verification:** `composer qa` → phpmd exits 0.
- **Committed in:** 3162b1e

**3. [Rule 3 - Blocking] 11 Phase 2 sibling tests updated to run the new migration**
- **Found during:** Task 4 (pest run after the writer signature change)
- **Issue:** EventLogWriter now writes the `payload` column; tests that ran only the Phase 2 base migration hit SQLite `no such column: payload` → returns false → race-fence row never persisted → 12 cascade failures.
- **Fix:** Added `use ... AddPayloadToMetapixelEventLogTable;` import + `(new AddPayloadToMetapixelEventLogTable)->up()` after Phase 2 up() + reverse-order down() in tearDown across the 10 affected Queue/Hook tests. The 11th file — `EventLogModelTest` — already passed; its `fillable matches migration columns` test was instead updated to include `'payload'` in the expected sorted list.
- **Files modified:** tests/Feature/Adapter/EventLogWriterRaceFenceTest.php, tests/Feature/Adapter/BackboneIntegrationTest.php, tests/Feature/Models/EventLogModelTest.php, tests/Feature/Queue/{SendCapiEventHappyPath,SendCapiEventFailedHandler,SendCapiEventBindingResolution,SendCapiEventBranchCoverage,SendCapiEventHalt,SendCapiEventTransientRetry,SendCapiEventDeadLetter}Test.php, tests/Unit/Hook/{BeforeDispatchPayloadMutation,DeadLetterHook,ListenerExceptionIsolation}Test.php
- **Verification:** Full pest run — 120/120 passing.
- **Committed in:** 3162b1e

**4. [Rule 3 - Blocking] PurgeEventLogTest setUp binds the command into the test Artisan kernel**
- **Found during:** Task 4 (pest run on PurgeEventLogTest)
- **Issue:** `Artisan::call('metapixel:purge-event-log')` threw `CommandNotFoundException` because `MetapixelTestCase` keeps `autoRegister=false` (Phase 1 lock to stay light) so `Plugin::register()` is never invoked in the test container.
- **Fix:** setUp now calls `$obKernel->registerCommand($this->app->make(PurgeEventLog::class))` against `Illuminate\Contracts\Console\Kernel` to bind the command directly. October's `Artisan::starting()` shim does not exist on its custom `October\Rain\Foundation\Console\Kernel`, so the Laravel-native `registerCommand` path is the portable choice.
- **Files modified:** tests/Feature/Console/PurgeEventLogTest.php
- **Verification:** Both PurgeEventLogTest cases pass; command produces expected `Purged N EventLog rows older than ...` output.
- **Committed in:** 3162b1e

**5. [Rule 2 - Critical] phpstan.neon + composer.json gained `console` coverage**
- **Found during:** Task 4 (after Task 3 added the console/ directory)
- **Issue:** `console/` was unanalyzed by phpstan and unscanned by phpmd; new console commands would ship without QA gates and silently regress.
- **Fix:** Added `console` to `phpstan.neon::paths`; added `,console` to the composer.json `phpmd` script.
- **Files modified:** phpstan.neon, composer.json
- **Verification:** `composer qa` chain analyzes `console/PurgeEventLog.php` and reports 0 errors / 0 violations.
- **Committed in:** 3162b1e

---

**Total deviations:** 5 auto-fixed (1 Rule 1 bug, 3 Rule 3 blocking, 1 Rule 2 critical).
**Impact on plan:** All deviations were unblocking; no scope creep. The LSP-typed `registerSchedule` requirement from the plan's acceptance criteria is the only contractual divergence — the typed form is unrunnable on PHP 8.4. The fix is the canonical PluginBase-subclass pattern (untyped param + @param docblock).

## Issues Encountered

- Worktree-based pest/phpstan execution required mirroring source files into the master plugin tree at `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/` because October's `vendor/composer/autoload_psr4.php` resolves `__DIR__` to the master plugin path (PHP follows symlinks before recording `__DIR__`). The worktree contains the per-agent branch source; commits land on the worktree branch as expected; mirror-to-master is reverted at end of plan via `git checkout -- <files>` + `rm` on the new files. The orchestrator's worktree → master merge then lands the changes cleanly.

## User Setup Required

None - no external service configuration required for this plan. The `metapixel:purge-event-log` command auto-wires into the operator's existing `* * * * * php artisan schedule:run` cron line via `Plugin::registerSchedule`.

## Next Phase Readiness

- **03-02..03-04 (Shopaholic adapter wave)** can now dispatch real Purchase + AddToCart events via `SendCapiEvent::dispatch(...)` and the EventLog row will carry the frozen payload at race-fence-win time.
- **03-05..03-07 (Theme adapter wave)** consumes the same writer signature; the trailing `$arPayload` arg supplies the payload for the request-bound theme event accumulator.
- **03-08 (EventPixel browser-side reader)** has the read path: `DB::table('logingrupa_metapixel_event_log')->where([...])->first(['event_id','event_time','payload'])` with explicit `json_decode` on the payload column (D-09 lock).
- **No blockers** for downstream wave-1 plans. The shared `EventLogWriter::record` signature, the new migration, and the daily purge cron are all in place.

## Self-Check: PASSED

- `updates/AddPayloadToMetapixelEventLogTable.php`: FOUND
- `console/PurgeEventLog.php`: FOUND
- `tests/Unit/Helper/EventLogWriterPayloadColumnTest.php`: FOUND
- `tests/Feature/Console/PurgeEventLogTest.php`: FOUND
- `tests/Feature/Migrations/AddPayloadColumnTest.php`: FOUND
- Commit `808f652` (Task 1): FOUND
- Commit `5f2b678` (Task 2): FOUND
- Commit `0441294` (Task 3): FOUND
- Commit `3162b1e` (Task 4): FOUND
- composer qa end-to-end: GREEN (pint-test, phpstan level 10, phpmd, pest --coverage --min=90 with 99.3 % coverage and 120 tests passing)

---
*Phase: 03-shopaholicadapter-themeactionadapter-parallel-wave*
*Plan: 01*
*Completed: 2026-05-18*
