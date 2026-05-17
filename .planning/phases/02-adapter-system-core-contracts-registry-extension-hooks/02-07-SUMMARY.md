---
phase: 02-adapter-system-core-contracts-registry-extension-hooks
plan: 7
subsystem: fake-adapter-contract-test-base-smoke
tags: [contract-test-base, fake-adapter, backbone-integration, adap-11, m-5-serialize-smoke, m-6-teardown, m-7-roadmap-flag, h-7-middleware-history, h-8-singleton-bind, r2-yagni-override, phase-2-close]

requires:
  - phase: 02-adapter-system-core-contracts-registry-extension-hooks
    plan: 1
    provides: FakeAdapter + FakeValueResolver + TestSubject + TestSubjectAdapter (tests/doubles/) + EventSubjectAdapter + ValueResolver + AdapterRegistry interfaces (classes/adapter/)
  - phase: 02-adapter-system-core-contracts-registry-extension-hooks
    plan: 3a
    provides: CreateMetapixelEventLogTable + CreateMetapixelFailedEventsTable migrations
  - phase: 02-adapter-system-core-contracts-registry-extension-hooks
    plan: 4
    provides: EventLogWriter::record UNIQUE race-fence writer + SiteResolver::forSubject
  - phase: 02-adapter-system-core-contracts-registry-extension-hooks
    plan: 5
    provides: MetaClient::sendForPixel + PayloadBuilder::buildEventPayload + UserDataHasher::forSubject
  - phase: 02-adapter-system-core-contracts-registry-extension-hooks
    plan: 6
    provides: SendCapiEvent queue job + 3 Event::fire hooks (before_dispatch halt-able + after_dispatch + dead_letter)

provides:
  - "EventSubjectAdapterContractTestCase abstract base under classes/testing/ (production namespace Logingrupa\\Metapixel\\Classes\\Testing) — 10 invariant tests (subject_type alias, subject_id positive int, getSiteId deterministic across successive calls, getSiteId returns ?int with no Request side-effect, getSecretKey ?string, getValueResolver returns ValueResolver, getUserData allowed-key set, getSupportedEvents shape, registry round-trip, PayloadBuilder envelope shape)"
  - "M-6 tearDown forgets AdapterRegistry singleton between tests (prevents invariant 09's register call from leaking across tests)"
  - "FakeAdapterContractTest in tests/Contract/Adapter/ — extends the base + supplies makeAdapter()/makeSubject() → all 10 invariants pass against FakeAdapter (ADAP-11 smoke proof)"
  - "ContractTestCaseSmokeTest in tests/Feature/Adapter/ — SC1 round-trip smoke + registry round-trip (2 tests, 10 assertions)"
  - "BackboneIntegrationTest in tests/Feature/Adapter/ — SC1 + SC5 end-to-end (3 tests: happy-path with after_dispatch listener + dedup with Middleware::history for accurate call-count per H-7 + M-5 serialize round-trip)"
  - "02-VERIFICATION-INPUTS.md scaffolded for gsd-verifier handoff — SC1..SC5 evidence checklists + M-7 ROADMAP.md SC5 mismatch flagged for orchestrator action + pitfall closure table + out-of-scope notes + filesystem path-case notes"
  - "phpunit.xml <source><exclude> exempts classes/testing/ from coverage (test-helper code, not production behaviour) — coverage gate ≥ 90% holds without dilution"
  - "phpstan.neon excludePaths adds classes/testing/ — cross-namespace import of Logingrupa\\Metapixel\\Tests\\MetapixelTestCase from production-scan dir is treated as test-helper code (Rule 3 fix; same shape as phpunit.xml exclude)"

affects:
  - 02-VERIFICATION-INPUTS.md (next step: /gsd:verify-phase 02-adapter-system-core-contracts-registry-extension-hooks consumes this scaffold; produces 02-VERIFICATION.md)
  - ROADMAP.md SC5 (M-7 flag: orchestrator action pending — wording references 4 v1.x test files that OQ-1 reframes as Phase 3 work)
  - phase 03 (Phase 3 first-party adapters extend EventSubjectAdapterContractTestCase: ShopaholicOrderAdapterContractTest + ThemeActionAdapterContractTest follow the FakeAdapterContractTest pattern)
  - phase 05 (docs/CUSTOM-ADAPTERS.md will document the contract base usage pattern for marketplace third parties + revisit orchestra/testbench require-dev when first real third party authors an adapter outside this repo)

tech-stack:
  added: []
  patterns:
    - "Contract test base as abstract class with abstract makeAdapter() + makeSubject() factory hooks — concrete subclasses (FakeAdapterContractTest, future Phase 3 ShopaholicOrderAdapterContractTest) inherit all 10 invariants free + supply only the subject + adapter construction. Pattern locks the EventSubjectAdapter marketplace contract while allowing per-adapter subject construction flexibility."
    - "M-5 serialize round-trip smoke pattern — synchronous tests skip Laravel queue worker's serialize/unserialize cycle. M-5 test catches SerializesModels-for-subject failure modes via explicit `unserialize(serialize($obJob))->handle(...)` round-trip with the same DB + Settings setup as the happy-path test."
    - "Middleware::history pattern for HTTP call-count assertion (H-7 downgrade) — MockHandler internal queue count is not a reliable assertion target because pending mocks remain queued. Push Middleware::history($arHistory) onto the HandlerStack and assert count($arHistory) for the actual number of Guzzle::request calls."
    - "phpstan classes/testing/ exclude pattern — when production-namespace code imports test-helper code (cross-namespace, first-party only per R2 YAGNI), phpstan trips on October test trait resolution. Add the dir to phpstan excludePaths + phpunit.xml <source><exclude>. Treat as test-helper, not production. Same shape as autoload-dev test code but lives in a production PSR-4 dir for marketplace API exposure."

key-files:
  created:
    - classes/testing/EventSubjectAdapterContractTestCase.php
    - tests/Contract/Adapter/FakeAdapterContractTest.php
    - tests/Feature/Adapter/ContractTestCaseSmokeTest.php
    - tests/Feature/Adapter/BackboneIntegrationTest.php
    - .planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-VERIFICATION-INPUTS.md
  modified:
    - phpunit.xml (add <exclude> block for classes/testing in <source>)
    - phpstan.neon (add classes/testing to excludePaths)

key-decisions:
  - "R2 YAGNI override carried through: orchestra/testbench DROPPED (commit db89398). EventSubjectAdapterContractTestCase extends Logingrupa\\Metapixel\\Tests\\MetapixelTestCase (Phase 1 base, no Lovata). Zero v2.0 third-party adapter consumers; revisit at v2.1 when first real third party ships outside this repo. Phase 5 docs/CUSTOM-ADAPTERS.md will document the eventual swap-to-Testbench OR copy-this-file pattern."
  - "Filesystem path lowercase carry-over from plan 02-01 deviation 1 — `classes/testing/` on disk despite plan 02-07 frontmatter writing `classes/Testing/` (treated as a folder-name typo). Namespace stays PascalCase: `Logingrupa\\Metapixel\\Classes\\Testing\\EventSubjectAdapterContractTestCase`. October Rain ClassLoader lowercase-normalises folder portion before basename — PHP namespace resolution is case-insensitive."
  - "phpstan.neon classes/testing/ exclude (Rule 3 fix beyond plan). The contract base imports Logingrupa\\Metapixel\\Tests\\MetapixelTestCase from production PSR-4 root `classes/`. phpstan production-scan path includes `classes`, so phpstan tries to load MetapixelTestCase + its October test traits (InteractsWithAuthentication etc.) which require the test bootstrap. Add `classes/testing` to phpstan excludePaths — symmetric with phpunit.xml's <source><exclude> for the same dir. Acceptable because classes/testing/ ships test-helper code, not production runtime behaviour. The contract base is itself only ever instantiated via concrete test subclasses + Pest harness."
  - "BackboneIntegrationTest uses TestSubject + TestSubjectAdapter (not FakeAdapter + stdClass) — TestSubjectAdapter is the existing race-fence-tested adapter that returns deterministic positive subject_id from TestSubject.iId, plus accepts a constructor-injected ?int $iSiteId for the non-null-site_id dedup case (NULL-distinct UNIQUE lesson from plan 02-04). FakeAdapter + stdClass at the contract layer (FakeAdapterContractTest) + TestSubjectAdapter + TestSubject at the integration layer (BackboneIntegrationTest) — same EventSubjectAdapter contract, different concrete shape per test concern."
  - "M-7 flag mechanism — ROADMAP.md SC5 wording references 4 v1.x test files (`OrderStatusWatcherEventLogTest`, `PurchasePixelEventLogGateTest`, `SendCapiEventEventLogTest`, `MultiSiteEventLogTest`) that OQ-1 reframes as Phase 3 work alongside ShopaholicOrderAdapter (SHOP-03). 02-VERIFICATION-INPUTS.md flags the mismatch + suggests replacement wording; orchestrator (NOT this plan) applies the ROADMAP.md edit. Plan 02-07 frontmatter must_haves explicitly lock 'this plan does NOT update ROADMAP.md, just flags for orchestrator surface'."
  - "Single commit for Phase 2 close per plan Task 5 explicit guidance — overrides the standard per-task commit protocol. Plan 02-07 closes Phase 2 with one atomic feat() commit covering all 7 file changes (5 created + 2 modified). 3edf0d6 carries the M-7 flag wording in the body as documentation."

patterns-established:
  - "EventSubjectAdapterContractTestCase pattern locked — Phase 3 ShopaholicOrderAdapterContractTest + ThemeActionAdapterContractTest will extend this base + supply makeAdapter()/makeSubject() that construct real Order / theme-action subjects + ShopaholicOrderAdapter / ThemeActionAdapter instances. All 10 invariants inherit free. Any future v2.x adapter author follows the same pattern. Marketplace contract is the abstract base + the 10 invariants — versioned by major version bumps (breaking the contract requires v3.0)."
  - "M-5 serialize round-trip smoke — apply to any new ShouldQueue job before merging to ensure SerializesModels handles the constructor's subject type correctly. Phase 3 SendShopaholicCapiEvent + SendThemeActionCapiEvent (if separate jobs land) need the same smoke."
  - "Middleware::history > MockHandler queue count for HTTP-call-count assertions (H-7). Carry forward to Phase 3 integration tests + Phase 4 FailedEvents Replay tests."
  - "phpstan + phpunit.xml symmetric exclusion of classes/testing/ — when first-party production-PSR-4 code is test-helper-shaped (imports tests/MetapixelTestCase, ships abstract test base), exclude from BOTH static-analysis paths AND coverage source. Same shape applies to any future testing helper that lands in classes/testing/."

requirements-completed:
  - ADAP-11

phase-completed:
  - Phase 2 (adapter-system-core-contracts-registry-extension-hooks) — all 11 ADAP-* requirements + 5 in-Phase-2 pitfalls (P-01 + P-02 + P-05 + P-08 + P-13) closed across 8 plans (02-01 + 02-02 + 02-03a + 02-03b + 02-04 + 02-05 + 02-06 + 02-07)

duration: ~8 min
completed: 2026-05-17
---

# Phase 02 Plan 07: Contract Test Base + FakeAdapter Smoke + Backbone Integration (ADAP-11) — Phase 2 Close

**Phase 2 final-plan landed — EventSubjectAdapterContractTestCase abstract base under classes/testing/ (production namespace) extends MetapixelTestCase per R2 YAGNI override; 10 invariants enforce the EventSubjectAdapter marketplace contract; FakeAdapterContractTest proves the base passes against FakeAdapter (ADAP-11 smoke); ContractTestCaseSmokeTest closes SC1 round-trip envelope shape; BackboneIntegrationTest closes SC1 + SC5 end-to-end with M-5 serialize round-trip smoke + H-7 Middleware::history dedup call-count; phpunit.xml + phpstan.neon symmetric-exclude classes/testing/ from coverage + production scan; 02-VERIFICATION-INPUTS.md scaffolded with M-7 ROADMAP.md SC5 mismatch flagged for orchestrator action; composer qa green; 111 tests / 332 assertions / 99.3% total coverage; Phase 2 closed.**

## Performance

- **Duration:** ~8 min (2026-05-17T22:54:52Z → 23:02:40Z)
- **Tasks:** 5 (Task 1 skipped per R2 YAGNI override; Tasks 2..5 active)
- **Commits:** 1 (Phase 2 close per plan Task 5 explicit single-commit guidance)
- **Files created:** 5 (1 production class + 3 test files + 1 verification scaffold)
- **Files modified:** 2 (phpunit.xml exclude + phpstan.neon exclude)
- **Test count delta:** +15 tests (96 → 111) / +73 assertions (259 → 332)

## Task Commits

Plan 02-07 ships one atomic feat() commit (plan-mandated single-commit for Phase 2 close).

| Commit | Description |
|--------|-------------|
| `3edf0d6` | feat(02-07): contract test base + FakeAdapter smoke + backbone integration (ADAP-11) — Phase 2 close |

(Per-task commits would have been: T2 contract base, T3 smoke + contract test, T4 backbone integration, T5 phpunit.xml + phpstan.neon + verification scaffold. Plan Task 5 explicitly mandated one commit, so the staging was held until composer qa proved green.)

## Files Created

| Path | Purpose |
|------|---------|
| `classes/testing/EventSubjectAdapterContractTestCase.php` | Abstract base — 10 EventSubjectAdapter contract invariants; tearDown forgets AdapterRegistry singleton (M-6) |
| `tests/Contract/Adapter/FakeAdapterContractTest.php` | Concrete contract test — extends base + supplies FakeAdapter + stdClass; all 10 invariants pass (ADAP-11 smoke) |
| `tests/Feature/Adapter/ContractTestCaseSmokeTest.php` | SC1 round-trip envelope smoke (2 tests: PayloadBuilder envelope + registry round-trip) |
| `tests/Feature/Adapter/BackboneIntegrationTest.php` | SC1 + SC5 end-to-end integration (3 tests: happy-path + dedup with Middleware::history H-7 + M-5 serialize round-trip) |
| `.planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-VERIFICATION-INPUTS.md` | gsd-verifier handoff scaffold — SC1..SC5 checklists + M-7 ROADMAP.md flag + pitfall table + out-of-scope notes + filesystem path-case notes |

## Files Modified

| Path | Change |
|------|--------|
| `phpunit.xml` | Added `<source><exclude><directory>./classes/testing</directory></exclude></source>` block — coverage gate excludes test-helper code |
| `phpstan.neon` | Added `classes/testing (?)` to `excludePaths` — same shape as phpunit coverage exclude; necessary because contract base imports `Logingrupa\Metapixel\Tests\MetapixelTestCase` (first-party only, per R2) and phpstan otherwise tries to load October test traits not present outside test bootstrap |

## composer qa Output (Tail)

Host-vendor smoke (plugin standalone composer-install limitation persists from Phase 1 + every Phase 2 plan):

```
$ /home/forge/nailscosmetics.lv/vendor/bin/pint --test
{"tool":"pint","result":"passed"}

$ /home/forge/nailscosmetics.lv/vendor/bin/phpstan analyse --memory-limit=2G -c /tmp/metapixel-phpstan-smoke.neon
 19/19 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%
 [OK] No errors

$ /home/forge/nailscosmetics.lv/vendor/bin/phpmd Plugin.php,classes,models text phpmd.xml
(no output — no violations)

$ /home/forge/nailscosmetics.lv/vendor/bin/pest --testsuite='Metapixel Unit Tests,Metapixel Feature Tests' --coverage --min=90
  Tests:    101 passed (282 assertions)
  Duration: 3.23s

  Plugin .................................................. 100.0%
  classes/adapter/AdapterRegistry ......................... 100.0%
  classes/adapter/EventSubjectAdapter ..................... 100.0%
  classes/adapter/ValueResolver ........................... 100.0%
  classes/exception/MetaApiPermanentException ............. 100.0%
  classes/exception/MetaApiTransientException ............. 100.0%
  classes/exception/MetaPixelException .................... 100.0%
  classes/exception/MissingCapiTokenException ............. 100.0%
  classes/exception/MissingPixelConfigException ........... 100.0%
  classes/helper/EventLogWriter ........................... 100.0%
  classes/helper/PluginGuard .............................. 100.0%
  classes/helper/SiteResolver ............................. 100.0%
  classes/meta/MetaClient ................................. 100.0%
  classes/meta/PayloadBuilder ............................. 100.0%
  classes/meta/UserDataHasher ............................. 100.0%
  classes/queue/SendCapiEvent ................. 295, 298 / 98.3%
  models/EventLog ......................................... 100.0%
  models/FailedEvent ...................................... 100.0%
  models/Settings ......................................... 100.0%
  ─────────────────────────────────────────────────────────
                                              Total: 99.3 %

$ /home/forge/nailscosmetics.lv/vendor/bin/pest --testsuite='Metapixel Contract Tests' --no-coverage
  Tests:    10 passed (50 assertions)
  Duration: 0.27s

$ /home/forge/nailscosmetics.lv/vendor/bin/pest --testsuite='Metapixel Unit Tests,Metapixel Feature Tests,Metapixel Contract Tests' --no-coverage
  Tests:    111 passed (332 assertions)
  Duration: 2.56s
```

Coverage gate ≥ 90% on production code holds (99.3% total / 98.3% on SendCapiEvent — ≥ 95% per-class gate also holds; only uncovered lines are defensive null-safety guards inside `SendCapiEvent::firstEventRecord` for malformed payload shapes that the typed constructor prevents).

`composer-dependency-analyser` not installed at host vendor — deferred to CI matrix per Phase 1 STATE.md blockers note. Carry-forward across all Phase 2 plans.

## Test Counts

### This plan (Plan 02-07)

| Test file | Tests | Assertions |
|-----------|-------|------------|
| classes/testing/EventSubjectAdapterContractTestCase.php (10 invariant methods, run via FakeAdapterContractTest) | 10 | 50 |
| tests/Contract/Adapter/FakeAdapterContractTest.php (subclass — inherits the 10 from the base) | (10 from base) | (50 from base) |
| tests/Feature/Adapter/ContractTestCaseSmokeTest.php | 2 | 10 |
| tests/Feature/Adapter/BackboneIntegrationTest.php | 3 | 13 |
| **Plan 02-07 total (concrete)** | **15** | **73** |

### Phase 2 aggregate

| Plan | Tests added |
|------|-------------|
| 02-01 | ~22 (initial scaffold + AdapterRegistry suite) |
| 02-02 | (static-enforcement only — phpstan config; no new tests beyond Plugin.php sanity) |
| 02-03a | ~13 (migrations + EventLog + FailedEvent models) |
| 02-03b | ~14 (PluginGuard + Exception hierarchy + Settings::lookupForSite) |
| 02-04 | ~14 (EventLogWriter race-fence + SiteResolver) |
| 02-05 | ~23 (MetaClient + PayloadBuilder + UserDataHasher) |
| 02-06 | ~16 (SendCapiEvent + 3 Event::fire hooks + branch coverage) |
| 02-07 | +15 (this plan — contract base + smoke + integration) |
| **Phase 2 aggregate** | **111 tests / 332 assertions** |

## Deviations from Plan

### Rule 3 — Auto-fixed blocking issues

**1. [Rule 3 - Blocker] phpstan trips on October test traits when classes/testing/ is in production-scan paths**

- **Found during:** Task 5 composer qa run (phpstan step).
- **Issue:** EventSubjectAdapterContractTestCase imports `Logingrupa\Metapixel\Tests\MetapixelTestCase` from `classes/testing/` (production PSR-4 namespace root `classes/`). phpstan production-scan path includes `classes`, so it tries to load MetapixelTestCase, which uses `October\Tests\Concerns\InteractsWithAuthentication`, `PerformsMigrations`, `PerformsRegistrations`. Those traits live under `modules/system/tests/` and are not loaded outside the test bootstrap. phpstan fatals: `Trait "October\Tests\Concerns\InteractsWithAuthentication" not found`.
- **Fix:** Add `classes/testing (?)` to `phpstan.neon` excludePaths + `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/classes/testing (?)` to `/tmp/metapixel-phpstan-smoke.neon` excludePaths. Symmetric with phpunit.xml's `<source><exclude><directory>./classes/testing</directory></exclude>`. Treats classes/testing/ as test-helper code (which it is — only ever instantiated via Pest subclasses). The R2 YAGNI override anticipated this acceptable-flag scenario for composer-dependency-analyser; same rationale applies to phpstan.
- **Files modified:** `phpstan.neon` + (development-time) `/tmp/metapixel-phpstan-smoke.neon`.
- **Verification:** `phpstan analyse` reports `[OK] No errors`; all 111 tests still pass.
- **Committed in:** `3edf0d6` (Phase 2 close commit).

### Notes on the plan's PascalCase typos

Plan 02-07 frontmatter `files_modified` and various `<artifacts>` paths use PascalCase `classes/Testing/` and `tests/Contract/Adapter/` etc. Phase 2 plan 02-01 deviation 1 locked lowercase folder names under `classes/` (October Rain ClassLoader normalises folder portions to lowercase). Per the deviation lock, all plan paths that show PascalCase folder names are treated as folder-name typos and shipped lowercase. Plan 02-07 frontmatter is treated the same: artifact lands at `classes/testing/EventSubjectAdapterContractTestCase.php` on disk. Test directory paths (`tests/Contract/Adapter/`, `tests/Feature/Adapter/`) stay PascalCase per the repo's existing test directory convention.

### Plan Task 5 explicit single-commit guidance honored

Task 5's `<action>` block mandates a single commit for Phase 2 close. Standard executor protocol commits per-task; here the plan-level instruction overrides. Tasks 2..4 were drafted and validated in-tree (php -l + pest --no-coverage smoke per task) but not staged until Task 5 ran composer qa green. The Phase-2-close commit `3edf0d6` carries all 7 file changes.

### `composer qa` script chain entry point

`composer qa` from plugin dir exits 127 (`pint: not found`) because plugin lacks standalone vendor/. Same limitation as every Phase 2 plan — documented in STATE.md "blockers/concerns" carry-forward. Host-vendor binaries at `/home/forge/nailscosmetics.lv/vendor/bin/{pint,phpstan,phpmd,pest}` run each step manually. Plugin-isolated composer qa is deferred to CI matrix.

## Phase 2 Closure — Cross-Plan Aggregate

| Plan | Requirements closed | Pitfalls closed | Key artifact |
|------|---------------------|-----------------|--------------|
| 02-01 | ADAP-01 (EventSubjectAdapter), ADAP-02 (ValueResolver), ADAP-03 (AdapterRegistry) | P-02 (boot-order race) | classes/adapter/{EventSubjectAdapter,ValueResolver,AdapterRegistry}.php + tests/doubles/* |
| 02-02 | (static-enforcement only) | P-01 phpstan layer (partial — runtime layer closes in 02-04 + 02-07) | phpstan.neon deny-list scoped to classes/{queue,event,adapter}/ |
| 02-03a | ADAP-07 (storage) | P-05 persistence-side (no MorphTo on EventLog) | updates/CreateMetapixel{EventLog,FailedEvents}Table.php + models/{EventLog,FailedEvent}.php |
| 02-03b | ADAP-08 (Settings stub) | P-13 (Plugin CLAUDE.md preference ranking) | models/Settings.php + classes/exception/{MetaPixel,MissingPixel,MissingCapi,MetaApi*}Exception.php + classes/helper/PluginGuard.php |
| 02-04 | ADAP-06 (SiteResolver), ADAP-07 (write-site EventLogWriter) | P-01 runtime layer + P-05 write-site (subject_type via adapter, not get_class) | classes/helper/{SiteResolver,EventLogWriter}.php + tests/Feature/Adapter/EventLogWriterRaceFenceTest.php |
| 02-05 | ADAP-09 (Graph API v23.0 pin) | (no new pitfall closures) | classes/meta/{MetaClient,PayloadBuilder,UserDataHasher}.php + tests/Unit/Meta/* |
| 02-06 | ADAP-04 (3 Event::fire hooks), ADAP-05 (listener-isolation), ADAP-10 (4-arg SendCapiEvent) | P-08 (Event::fire mutable payload via snapshot+restore) | classes/queue/SendCapiEvent.php + tests/Unit/Hook/* + tests/Feature/Queue/* |
| 02-07 | ADAP-11 (backbone tests adapt via FakeAdapter) | P-01 contract-test layer (invariants 03 + 04) | classes/testing/EventSubjectAdapterContractTestCase.php + tests/Contract/Adapter/FakeAdapterContractTest.php + tests/Feature/Adapter/{ContractTestCaseSmokeTest,BackboneIntegrationTest}.php |
| **Phase 2 aggregate** | **11 / 11 ADAP-***  | **P-01 + P-02 + P-05 + P-08 + P-13 (5 / 5 in-Phase-2 pitfalls)** | — |

## M-7 ROADMAP.md SC5 Status

Flagged in 02-VERIFICATION-INPUTS.md "ROADMAP.md SC5 mismatch (M-7 — orchestrator action)" section. Current ROADMAP.md SC5 wording references 4 v1.x test files (`OrderStatusWatcherEventLogTest`, `PurchasePixelEventLogGateTest`, `SendCapiEventEventLogTest`, `MultiSiteEventLogTest`) that OQ-1 reframes as Phase 3 work alongside ShopaholicOrderAdapter. Plan 02-07 explicitly does NOT update ROADMAP.md (per plan must_haves lock); orchestrator surfaces the mismatch + applies the fix. Suggested replacement wording present in 02-VERIFICATION-INPUTS.md.

## Pending Orchestrator Actions (post-verification)

1. `/gsd:verify-phase 02-adapter-system-core-contracts-registry-extension-hooks` to produce 02-VERIFICATION.md.
2. Apply M-7 ROADMAP.md SC5 wording fix.
3. Flip `.planning/REQUIREMENTS.md` ADAP-01..11 from `[ ]` to `[x]`.
4. Update `.planning/ROADMAP.md` Phase 2 status to "Complete".
5. Advance STATE.md `Current Position` to Phase 3.

## Phase 2 → Phase 3 Handoff

Phase 3 (ShopaholicAdapter + ThemeActionAdapter) consumes:

- `Logingrupa\Metapixel\Classes\Testing\EventSubjectAdapterContractTestCase` — ShopaholicOrderAdapterContractTest + ThemeActionAdapterContractTest extend this base + supply makeAdapter() / makeSubject().
- `Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry` — Phase 3 plugins' boot() calls `AdapterRegistry::instance()->register($sSubjectClass, $sAdapterClass)`.
- `Logingrupa\Metapixel\Classes\Queue\SendCapiEvent::dispatch($sEventName, $arPayload, $obSubject, $sAdapterClass)` — Phase 3 OrderStatusWatcher + ThemeAjaxHandler fire jobs via this entry point.
- The 3 Event::fire hooks at `metapixel.event.{before_dispatch,after_dispatch,dead_letter}` — Phase 3 first-party hooks listen here (e.g. log-each-dispatch for FailedEvents admin UI).
- The race-fence + dead-letter + retry-exhaustion infrastructure — no Phase 3 plumbing change needed; just register adapters + dispatch.

## Self-Check: PASSED

- **Created files exist:** `classes/testing/EventSubjectAdapterContractTestCase.php` FOUND; `tests/Contract/Adapter/FakeAdapterContractTest.php` FOUND; `tests/Feature/Adapter/ContractTestCaseSmokeTest.php` FOUND; `tests/Feature/Adapter/BackboneIntegrationTest.php` FOUND; `.planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-VERIFICATION-INPUTS.md` FOUND.
- **Modified files exist:** `phpunit.xml` updated (exclude block present); `phpstan.neon` updated (classes/testing in excludePaths).
- **Commit exists:** `3edf0d6` FOUND on master.
- **composer qa green:** pint passed; phpstan 19/19 files [OK] No errors; phpmd zero violations; pest 101 passed / 282 assertions (Unit + Feature) + 10 passed / 50 assertions (Contract); coverage 99.3% total / 98.3% SendCapiEvent (≥ 90% gate holds).
- **All Phase 2 ADAP-* closed:** ADAP-01..11 (11 / 11).
- **All in-Phase-2 pitfalls closed:** P-01 + P-02 + P-05 + P-08 + P-13 (5 / 5).
