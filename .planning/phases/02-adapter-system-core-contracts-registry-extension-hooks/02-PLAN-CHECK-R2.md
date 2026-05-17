# Phase 2 Plan Check — Round 2

**Verdict:** PASS-WITH-NOTES

The Round 1 REVISE feedback is substantially addressed. All 9 HIGH items the planner claimed closed are verified in code. The 8 MEDIUM items claimed closed are verified. The 4 LOW items claimed closed are verified. The plan-set is executable.

Two non-blocking notes remain:
1. Plan 02-01 frontmatter (`files_modified`, `truths`, `artifacts`) still lists `SpyMetaClient.php` as a Wave-1 deliverable, while Task 4 prose explicitly defers it to plan 02-05 Task 6 — this is an internal frontmatter-vs-prose contradiction (cosmetic, not goal-blocking, since plan 02-05 frontmatter+Task 6 correctly own SpyMetaClient).
2. Plan 02-07 Task 1 verify step relies on `composer update orchestra/testbench` resolving from `../../../vendor/`; orchestra/testbench is currently absent from project vendor (`/home/forge/nailscosmetics.lv/vendor/orchestra/` does not exist) — operator must install before executing plan 02-07.

Neither blocks Phase 2 goal achievement. Orchestrator may proceed; flag both notes in the commit message.

---

## Round 1 items — verification

### HIGH

| ID | Verdict | Evidence |
|----|---------|----------|
| H-1 | **VERIFIED** | `02-PLAN-2` lines 93-125, 231-294 use `disallowIn` deny-list scoped to `classes/Queue/*`, `classes/Event/*`, `classes/Adapter/*`. `classes/Helper/*` and `classes/Meta/*` are NOT in any allow-list (no `allowIn` keyword anywhere; verify step at line 297 explicitly `! grep -q 'allowIn:'`). The 3 rules (SiteManager, Site facade, Request::*) + the `request()` function ban all carry the same 3-dir `disallowIn`. RESEARCH §5.1 verbatim. |
| H-2 | **VERIFIED** | `02-PLAN-6` Task 1 (lines 319-350) implements `writeFailedEvent(Throwable, ?int $iHttpStatus, ?EventSubjectAdapter $obAdapter)`. BindingResolutionException path (line 193) passes null. Other call sites (lines 228, 249) pass the resolved adapter. `failed()` (line 236-251) resolves the adapter via `app(AdapterRegistry::class)->resolveByClass(...)` per L-5 before calling writeFailedEvent. T18 verifies the null path; T21 verifies subject_type='fake.subject' / subject_id=42 from TestSubjectAdapter. Migration columns shipped by plan 02-03a (line 104, fillable includes subject_type + subject_id). |
| H-3 | **VERIFIED** | `02-PLAN-7` Task 2 line 144 uses `Orchestra\Testbench\TestCase`. Task 1 line 297 adds `orchestra/testbench ^9.0` to `require-dev`. Verify step line 379 confirms `extends TestCase` + `use Orchestra\\Testbench\\TestCase` + `! grep MetapixelTestCase`. No `addPathToExclude('/classes/Testing')` in composer-dependency-analyser (line 280: "composer-dependency-analyser.php is NOT touched in this plan"). |
| H-4 | **VERIFIED** | `02-PLAN-5` Task 1 (lines 372-417) replaces broken `\|\|` shell logic. Adds Guzzle to plugin `composer.json` `require:` only — explicitly forbids `composer update` from plugin dir (line 398: "DO NOT run `composer update` from the plugin dir"). Verify uses `composer validate --no-check-publish` + `php -r 'require_once "../../../vendor/autoload.php"; class_exists("GuzzleHttp\\Client") || exit(1);'` (line 415). |
| H-6 | **VERIFIED** | `02-PLAN-1` Task 4 ships 6 shared doubles in `tests/Doubles/` (FakeAdapter, FakeValueResolver, TestSubject, TestSubjectAdapter, ZeroIdSubjectAdapter, FakeStubAdapter) — SpyMetaClient correctly deferred to plan 02-05 Task 6 because it extends MetaClient which lands in Wave 3 (dependency is sound). Plans 02-04 (lines 410-412), 02-06 (lines 369-373), 02-07 (lines 405, 535) all import by FQN. Verify steps grep `! ^class (TestSubject\|FakeStubAdapter\|SpyMetaClient)` to prove no inline declarations. |
| H-8 | **VERIFIED** | Plans 02-01 (line 175-181), 02-03b (line 412), 02-04 (lines 336-338, 419), 02-05 (line 540), 02-06 (lines 514-516, 598-600), 02-07 (lines 226-227, 543-545) all use `$this->app->singleton(AdapterRegistry::class)` direct bind. Every plan's verify step includes `! grep -E '\(new\s+\\?Logingrupa\\\\Metapixel\\\\Plugin\)'` to prove no PluginBase instantiation. |
| H-9 | **VERIFIED** | `02-PLAN-5` Task 3 verify (line 482) combined regex: `! grep -E '\$sEventName\s*(===\|!==\|==)\|switch\s*\(\s*\$sEventName\|match\s*\(\s*\$sEventName\|in_array\s*\(\s*\$sEventName'`. Catches all 4 anti-patterns the original gate missed. |
| M-2 | **VERIFIED** | Plan 02-03 split into 02-03a (5 tasks / 12 files: migrations + EventLog/FailedEvent models + classmap) and 02-03b (5 tasks / 14 files: Settings + PluginGuard + 5 exceptions + lang). Both ≤ scope budget. `02-INDEX.md` index (line 36-37) lists both. Dependency graph (line 50-55) shows both Wave 2 parallel, both unblocking Wave 3 plans 02-04 + 02-05. |
| H-7 | **VERIFIED** (downgraded to warning in R1) | `02-PLAN-7` Task 4 line 596-599 uses `Middleware::history($arHistory)` and asserts `count($arHistory) === 1` instead of MockHandler internal queue count. |

### MEDIUM

| ID | Verdict | Evidence |
|----|---------|----------|
| M-3 | **VERIFIED** | `02-PLAN-4` truths line 32 says "two SEQUENTIAL insertOrIgnore calls with identical key … Concurrent contention is NOT exercised in SQLite-in-memory test env". Task 4 inline comment (lines 437-440) reinforces. Objective paragraph (line 64) explicit. |
| M-4 | **VERIFIED** | `02-PLAN-5` Task 2 verify (line 446): `! grep -q 'arMemo'` AND `! grep -q 'function reset'`. UserDataHasher shape (lines 271-313) has no memo property, no reset method. Task 5 verify (line 580): `! grep -E 'test_per_request_memo\|test_reset_clears_memo'` proves the 2 memo tests are dropped. Truths line 38 explicit. |
| M-5 | **VERIFIED** | `02-PLAN-7` Task 4 test_serialize_round_trip_job_unserializes_and_runs_handle (lines 621-637) does `serialize($obJob)` + `unserialize($sBlob)` + `->handle(...)` against an MetaClient mock, asserts 1 EventLog row written. ~17 LOC. Verify line 659 greps the test method name. |
| M-6 | **VERIFIED** | `02-PLAN-7` Task 2 (lines 182-187) EventSubjectAdapterContractTestCase has `protected function tearDown(): void { app()->forgetInstance(AdapterRegistry::class); parent::tearDown(); }`. Verify line 379 greps `forgetInstance(AdapterRegistry::class)`. |
| M-7 | **VERIFIED** | `02-PLAN-7` Task 5 (lines 692-707) scaffolds `02-VERIFICATION-INPUTS.md` with an explicit "## ROADMAP.md SC5 mismatch (M-7 — orchestrator action)" section. The plan does NOT modify ROADMAP.md itself (per the M-7 finding's prescription). Verify line 778 greps `M-7`. |
| M-8 | **VERIFIED** | `02-PLAN-2` Task 5 verify (line 455) ends with `\| wc -l \| xargs test 3 -le` (at least 3 files). No longer `test 3 -eq`. Plan-set frontmatter at line 506 cross-references. |
| M-9 | **VERIFIED** | Resolved via H-6 — all hook+queue test files in 02-06 import shared doubles; 02-04 imports shared doubles; 02-07 imports shared doubles. Verify steps across plans 02-04/06/07 grep `! ^class (TestSubject\|TestSubjectAdapter\|SpyMetaClient\|FakeStubAdapter)`. |

### LOW

| ID | Verdict | Evidence |
|----|---------|----------|
| L-4 | **VERIFIED** | Plans 02-01 (line 132, 244), 02-03b (line 248, 258), 02-04 (line 193, 305), 02-06 (line 472), 02-07 (line 528, 530) — all use `use Illuminate\\Support\\Facades\\Log;` (and Event / App / DB) FQN. Verify steps grep the FQN form explicitly. |
| L-5 | **VERIFIED** | `02-PLAN-6` Task 1 failed() implementation (lines 236-251) resolves adapter via `app(AdapterRegistry::class)->resolveByClass(...)`, snapshots http_status from the throwable instance (line 248 — `instanceof MetaApiTransientException ? getHttpStatus()`), passes adapter to writeFailedEvent. Documented in must_haves truths line 46. |
| L-6 | **VERIFIED** | `02-PLAN-7` Task 3 — Pest.php is NOT in the `files` list (line 387-388). Plan body line 491 says "L-6: NO Pest.php edit in this plan. The original plan included a comment block … drop per CLAUDE.md 'no comment pollution'". |
| L-8 | **VERIFIED** | All test files across plans 02-01 (line 404), 02-03b (line 408), 02-04 (line 332), 02-05 (line 573), 02-06, 02-07 (line 408, 540) use `final class FooTest extends MetapixelTestCase` or `final class FakeAdapterContractTest extends EventSubjectAdapterContractTestCase`. No `pest()->extend(...)` anywhere. |

---

## New issues introduced in Round 1 revision

### N-1 (WARNING, cosmetic) — Plan 02-01 frontmatter-vs-prose contradiction on SpyMetaClient

**File:** `02-01-PLAN.md`

Frontmatter declares SpyMetaClient.php as a deliverable in 3 places:
- Line 20: `files_modified` list includes `plugins/logingrupa/metapixel/tests/Doubles/SpyMetaClient.php`
- Line 50: truths declares `tests/Doubles/ ships 7 shared fixtures (... + SpyMetaClient)`
- Line 73-75: artifacts list includes SpyMetaClient.php
- Line 166 (interfaces block): "SpyMetaClient.php" appears in the fixture table

But Task 4's prose decision (lines 340-348) explicitly defers SpyMetaClient to plan 02-05 Task 6 because it extends MetaClient (lands Wave 3). The verify step (line 364) lists only 6 doubles, NOT 7. The done state (line 366) explicit: "SpyMetaClient deferred to plan 02-05 Task 6". Plan 02-05 frontmatter (line 16) AND Plan 02-05 Task 6 (lines 585-608) DO ship SpyMetaClient.

**Impact:** Cosmetic — the prose decision overrides; executor will follow Task 4's "ship 6 not 7" instruction. But the must_haves truth "7 shared fixtures" will fail its own verify step (line 364 lists 6 files, will pass) and the artifacts table includes an artifact that doesn't ship.

**Fix (cheap):** Plan 02-01 frontmatter cleanup before execution:
- Remove `tests/Doubles/SpyMetaClient.php` from line 20 files_modified
- Change line 50 truths from "7 shared fixtures … + SpyMetaClient" to "6 shared fixtures (FakeAdapter + FakeValueResolver + TestSubject + TestSubjectAdapter + ZeroIdSubjectAdapter + FakeStubAdapter)"
- Drop the SpyMetaClient artifact entry at lines 73-75
- Drop the SpyMetaClient row from the interfaces table at line 166

Not a BLOCKER because:
- Plan 02-05 correctly owns SpyMetaClient
- Plan 02-01 Task 4 prose correctly tells executor to ship 6 not 7
- Verify steps grep the 6 files only — Wave 1 still passes its gate
- H-6 invariant ("downstream plans import SpyMetaClient by FQN") is still satisfied because plan 02-05 ships it in Wave 3 (before 02-06 + 02-07 which need it in Wave 4 + 5)

### N-2 (WARNING, operator action) — orchestra/testbench not currently installed in project vendor

**File:** Plan 02-07 Task 1, project vendor state

Project root vendor (`/home/forge/nailscosmetics.lv/vendor/orchestra/`) does not exist. Plan 02-07 Task 1 verify step (line 328) `php -r 'require_once "../../../vendor/autoload.php"; class_exists("Orchestra\\Testbench\\TestCase")'` will fail.

**Cause:** Orchestra Testbench is a Laravel test harness — not a Lovata/October core dep. Project root's composer.json must be updated to allow the new constraint to propagate.

**Fix (operator action, H-4 pattern):** When plan 02-07 executes, operator runs from project root:
```
composer require --dev orchestra/testbench:^9.0
```
Either as a top-level project dep OR (preferred) just install the plugin's require-dev via `composer update logingrupa/oc-metapixel-plugin --with-all-dependencies`.

Plan 02-07 Task 1 prose (line 305-313) names this operator step. Verify gate (line 328) WILL fail until the operator runs the install. This is expected and documented — same H-4 pattern as Guzzle in 02-05.

**Not a BLOCKER** because:
- Plan 02-07 is Wave 5 (final plan) — operator install happens before that wave ships
- H-4 lock at project level says "operator runs composer update from project root" — Plan 02-07 inherits the pattern
- The verify gate at task 1 is a pre-execution sanity check, not a quiet-fail

### N-3 (INFO, non-blocking) — Plan 02-01 frontmatter says "plan_count: 8" but 8 plans = 02-01..02-07 with 03 split into a/b. Index correctly says 8.

`02-INDEX.md` line 3 `plan_count: 8`; index table (lines 33-41) lists exactly 8: 01, 02, 03a, 03b, 04, 05, 06, 07. Wave assignments correct: Wave 1 (01, 02), Wave 2 (03a, 03b), Wave 3 (04, 05), Wave 4 (06), Wave 5 (07).

### Dependency graph re-trace

```
Wave 1 (parallel, no deps): 02-01, 02-02
Wave 2 (parallel, both deps on 02-01): 02-03a, 02-03b
Wave 3 (parallel, deps on 02-01 + 02-03a [+ 02-03b for 05]): 02-04, 02-05
Wave 4: 02-06 (deps on 02-01, 02-03a, 02-03b, 02-04, 02-05)
Wave 5: 02-07 (deps on 02-01, 02-02, 02-05, 02-06)
```

All `depends_on` frontmatter values verified against the 8 plan files. No cycles, no forward references, no orphan dependencies. Wave 3 unblocks cleanly when BOTH 02-03a and 02-03b commit (plan 02-04 `depends_on: [02-01, 02-03a]`; plan 02-05 `depends_on: [02-01, 02-03a, 02-03b]`). Wave 4 depends on the full Wave 3 chain.

### Cross-plan refs

REQ-ID coverage matrix (02-INDEX.md lines 67-79) lists 11/11 ADAP-* with proper plan ownership including the 02-03 split → 02-03a (storage) supports ADAP-08/09/10 indirectly, 02-03b (config) supports ADAP-09/10 indirectly. Primary ownership matrix matches: ADAP-04/05/10 → 02-06, ADAP-07/08/09 → 02-05, ADAP-06 → 02-04 (primary) + 02-02 (PHPStan), ADAP-11 → 02-07.

Pitfall ownership matrix (02-INDEX.md lines 83-90) reflects the split (P-05 owned by 02-01 interface + 02-03a storage + 02-04 write site + 02-07 contract test).

---

## REQ + Pitfall coverage matrices (updated)

### REQ coverage

| REQ-ID | Plan(s) | Status |
|--------|---------|--------|
| ADAP-01 | 02-01 Task 1 | COVERED |
| ADAP-02 | 02-01 Task 1 | COVERED |
| ADAP-03 | 02-01 Tasks 2 + 3 | COVERED |
| ADAP-04 | 02-06 Task 1 (3 hooks) | COVERED |
| ADAP-05 | 02-06 Task 1 (listener-isolation try/catch) | COVERED |
| ADAP-06 | 02-04 (logic) + 02-02 (PHPStan, H-1 disallowIn) | COVERED |
| ADAP-07 | 02-05 Task 3 (PayloadBuilder + H-9 grep) | COVERED |
| ADAP-08 | 02-05 Task 2 (UserDataHasher, M-4 stateless) | COVERED |
| ADAP-09 | 02-05 Task 4 (MetaClient v23.0 per-call) | COVERED |
| ADAP-10 | 02-06 Task 1 + H-2 writeFailedEvent | COVERED |
| ADAP-11 | 02-07 Tasks 2 + 3 + 4 | COVERED |

### Pitfall coverage

| Pitfall | Status | Mechanism |
|---------|--------|-----------|
| P-01 | CLOSED | Interface contract (02-01) + SiteResolver logic (02-04 Task 1) + PHPStan disallowIn deny-list (02-02 Task 2 H-1) + static-source regex test (02-04 Task 3) + ContractTestCase invariant 04 (02-07 Task 2) |
| P-02 | CLOSED | Idempotent register (02-01 Task 2) + Plugin::register singleton bind (02-01 Task 3) + boot-order test (02-01 Task 5 T4) + H-8 setUp pattern |
| P-05 | CLOSED | Opaque alias interface contract (02-01) + no MorphTo + alias-comment migration (02-03a) + EventLogWriter writes adapter alias not get_class (02-04 Task 2) + ContractTestCase invariant 01 (02-07) |
| P-08 | CLOSED | Snapshot+restore in fireBeforeDispatchHalt (02-06 Task 1) + listener-isolation try/catch on all 3 fire sites + T12 test enforcement (02-06 Task 2) + L-5 failed() consistency |
| P-13 | CLOSED | CLAUDE.md addendum ranking Component::extend as LAST RESORT with onMetapixel* prefix mandate (02-02 Task 4) |

---

## End-to-end re-trace (Purchase via FakeAdapter through the revised plans)

1. **Test setUp** (BackboneIntegrationTest, 02-07): `$this->app->singleton(AdapterRegistry::class)` direct bind (H-8 fix lands — no `(new Plugin)->register()` TypeError). Run both migrations (event_log + failed_events from 02-03a). Register `\stdClass` → `FakeAdapter::class`. `Settings::set(['pixel_id' => 'PIXEL-1', 'capi_access_token' => 'TOKEN-1'])`. ✓ proceeds past step 3a (H-8 verified).

2. **PayloadBuilder.buildEventPayload** (02-05): subject-agnostic envelope. H-9 combined grep gate verifies no switch / match / === / !== / in_array on $sEventName. ✓

3. **SendCapiEvent.handle** (02-06 Task 1):
   - 3a. `$obAdapter = $obRegistry->resolveByClass(FakeAdapter::class)` ✓ (H-8 fix means setUp populated registry)
   - 3b. `fireBeforeDispatchHalt` — snapshot event_id + event_time, fire hook, restore from snapshot (P-08). Returns false (no halt). ✓
   - 3c. `SiteResolver::forSubject($obSubject, $obAdapter)` → null (FakeAdapter default) ✓
   - 3d. `EventLogWriter::record(...)` (02-04 Task 2) → `$obRegistry->resolveFor($obSubject)->getSubjectType($obSubject)` → 'fake.subject' opaque alias. insertOrIgnore returns 1 (race won). ✓
   - 3e. `Settings::lookupForSite(null)` → `{pixel_id: 'PIXEL-1', capi_access_token: 'TOKEN-1'}` ✓ (Phase 2 stub)
   - 3f. `MetaClient::sendForPixel('PIXEL-1', 'TOKEN-1', $arPayload)` (02-05 Task 4). URL `https://graph.facebook.com/v23.0/PIXEL-1/events`. MockHandler returns 200 + `{events_received: 1, fbtrace_id: 'trace-1'}`. ✓
   - 3g. `fireAfterDispatch($arResponse)` — observe-only, after_dispatch listener receives the response. ✓

4. **Failure path (Permanent — H-2 verification)**:
   - MockHandler returns 400 → MetaApiPermanentException(400) (02-05).
   - SendCapiEvent.handle catches Permanent (02-06 line 226-231) → `$this->writeFailedEvent($obException, 400, $obAdapter)` — adapter IS in scope ✓
   - writeFailedEvent (02-06 lines 325-350) populates `subject_type=$obAdapter->getSubjectType($obSubject)='fake.subject'` AND `subject_id=$obAdapter->getSubjectId($obSubject)=42` (TestSubject default iId). ✓ H-2 fix verified — Phase 4 admin UI can re-resolve.
   - `fireDeadLetter($obException)` — dead_letter listener observes exception. ✓

5. **Failure path (BindingResolutionException — H-2 null path)**:
   - Pass adapter class string `'NonExistent\Foo\BarAdapter'`.
   - resolveByClass throws BindingResolutionException.
   - `Log::critical(...)` then `writeFailedEvent($obException, null, null)` ← **null adapter, legitimate** (line 193 of 02-06 Task 1).
   - FailedEvent row has `subject_type=null, subject_id=null`. ✓ T18 enforces.

6. **Production-path serialize (M-5 verification, 02-07 Task 4)**:
   - `serialize($obJob)` → `unserialize($sBlob)` → `$obRehydrated->handle(...)`. EventLog row written. ✓ Catches SerializesModels-for-stdClass failure mode.

No gaps. The H-2 + H-8 fixes thread cleanly through the full pipeline. The N-1 cosmetic frontmatter issue does NOT affect this trace (executor follows Task 4 prose, ships 6 not 7 doubles, and 02-05 Task 6 ships SpyMetaClient in Wave 3 — well before BackboneIntegrationTest in Wave 5).

---

## Build philosophy slip-check

CLAUDE.md "Simple > clever. Five readable lines beat one clever line." + "No over-engineering. Build only for current need."

- **M-4 (UserDataHasher memo dropped)** — exemplary application of the philosophy. ~15 LOC + 1 test method saved. ✓
- **H-6 (shared doubles)** — net code reduction (single fixture file vs N inline declarations across N test files). ✓
- **02-PLAN-4 Task 1 SiteResolver** — one-line body, 25 LOC file. ✓
- **02-PLAN-5 Task 3 PayloadBuilder** — ≤ 60 LOC, no clever event-name branching. ✓
- **No new over-engineering introduced in R1.**

No build-philosophy slip detected.

---

## Summary

The R1 revision closes every HIGH and MEDIUM issue cited in the R1 plan-check, with evidence visible in the plan files. The end-to-end trace verifies H-2 + H-8 actually fire, and H-1 / H-9 / M-4 grep gates are tight enough to catch realistic regressions. PASS-WITH-NOTES is the right verdict — execution can proceed.

**Outstanding notes (non-blocking, fix-before-or-during-execution):**
- N-1: Plan 02-01 frontmatter still lists SpyMetaClient as a deliverable despite Task 4 prose deferring it to plan 02-05. Cosmetic cleanup recommended before execute-phase begins (~5 LOC edit in 02-PLAN-1 frontmatter).
- N-2: orchestra/testbench is not yet in project vendor. Plan 02-07 Task 1 verify gate will fail at execution time until operator runs `composer require --dev orchestra/testbench:^9.0` from project root. The plan body documents this as the H-4 pattern — operator action.
- M-7 (already flagged in plan 02-07): ROADMAP.md SC5 wording mentions 4 v1.x test files that OQ-1 reframes as Phase 3. Plan 02-07's 02-VERIFICATION-INPUTS.md scaffold flags this for orchestrator action. ROADMAP.md edit happens outside Phase 2's plan scope.

Orchestrator may commit artifacts and close plan-phase. Plans are ready for `/gsd:execute-phase 02-adapter-system-core-contracts-registry-extension-hooks`.
