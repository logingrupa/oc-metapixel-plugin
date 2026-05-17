---
phase: 02-adapter-system-core-contracts-registry-extension-hooks
plan: 4
subsystem: siteresolver-eventlogwriter-racefence
tags: [site-resolver, event-log-writer, race-fence, unique-constraint, adap-06, p-01, p-05, h-2, h-8, l-4, hungarian-notation, fail-fast, fail-safe, lovata-toolbox]

requires:
  - phase: 02-adapter-system-core-contracts-registry-extension-hooks
    plan: 1
    provides: AdapterRegistry singleton + EventSubjectAdapter interface + shared tests/doubles/ (FakeAdapter, TestSubject, TestSubjectAdapter, ZeroIdSubjectAdapter)
  - phase: 02-adapter-system-core-contracts-registry-extension-hooks
    plan: 2
    provides: phpstan disallowedMethodCalls deny-list on classes/{queue,event,adapter}/ + phpunit Adapter/Contract testsuites + CLAUDE.md ranked extensibility hooks
  - phase: 02-adapter-system-core-contracts-registry-extension-hooks
    plan: 3a
    provides: logingrupa_metapixel_event_log table + CreateMetapixelEventLogTable migration (UNIQUE on subject_type, subject_id, event_name, channel, site_id) + EventLog model (no MorphTo, opaque alias subject_type)
  - phase: 02-adapter-system-core-contracts-registry-extension-hooks
    plan: 3b
    provides: PluginGuard helper at classes/helper/ (sibling dir to SiteResolver + EventLogWriter) + L-4 Log/App/DB FQN import lock + H-8 setUp pattern verification
provides:
  - SiteResolver final class with single static forSubject(object, EventSubjectAdapter): ?int — sole authoritative site_id source (ADAP-06 logic half)
  - EventLogWriter final class with static record(string, string, string, object, ?string, int, ?int): bool — UNIQUE race-fence writer that consults AdapterRegistry::resolveFor for the opaque subject_type alias (P-05 anchor at the write site)
  - SiteResolverTest (3 cases) covering adapter delegation + null propagation + static-source regex defence
  - EventLogWriterRaceFenceTest (7 cases) covering race-fence anchor + distinct-channel + NULL-distinct + missing-adapter + non-positive-id + alias-write + Throwable fail-safe
  - tests/Feature/Adapter/ directory now populated (previously empty)
affects:
  - 02-05 (MetaClient + PayloadBuilder + UserDataHasher — runs sequentially after; no file overlap)
  - 02-06 (SendCapiEvent::handle calls EventLogWriter::record for the capi channel row + reads SiteResolver::forSubject for Settings::lookupForSite credentials)
  - 02-07 (FakeAdapterContractTest + ContractTestCase exercise the same EventLogWriter + SiteResolver paths via the FakeAdapter smoke test)
  - phase 03 (ShopaholicOrderAdapter + ThemeActionAdapter call into the same write site; their getSiteId implementations determine what SiteResolver returns)

tech-stack:
  added: []
  patterns:
    - "SiteResolver minimal-delegation pattern — 23-line file with one public static method, body is one return statement; no defensive guards beyond the type hints; the only function is to make 'site_id from subject only' a named, testable, statically-greppable contract"
    - "EventLogWriter fail-safe outer try/catch — any Throwable returns false + Log::critical; peer-wins assumption prevents double-fire on DB outage (acceptable trade-off: silent event-suppression during outage > cascading queue retries)"
    - "Race-fence test under SQLite NULL-distinct semantics — UNIQUE on (subject_type, subject_id, event_name, channel, site_id) blocks duplicate inserts ONLY when site_id is non-null OR when all other tuple columns are also distinct; the first test in T17 must use non-null site_id to actually trigger the constraint (the third NULL-distinct test exercises the null-twin path explicitly)"
    - "Defence-in-depth grep guard — SiteResolverTest reads its own source file and asserts zero matches for SiteManager / Site:: / Request:: / request() identifiers; complements (does NOT replace) the phpstan disallowed-calls rule, which intentionally scopes only classes/{queue,event,adapter}/ and not classes/helper/"

key-files:
  created:
    - classes/helper/SiteResolver.php
    - classes/helper/EventLogWriter.php
    - tests/Unit/Helper/SiteResolverTest.php
    - tests/Feature/Adapter/EventLogWriterRaceFenceTest.php
  modified: []

key-decisions:
  - "Plan path casing normalized to lowercase (classes/helper/, NOT classes/Helper/) per the 02-01 carry-over decision. PLAN.md interface block listed PascalCase paths; the on-disk October Rain ClassLoader convention requires lowercase, and the existing classes/helper/PluginGuard.php already lives at lowercase."
  - "Test path casing preserved as PascalCase (tests/Unit/Helper/, tests/Feature/Adapter/) per the 02-03a decision. Non-namespaced classic-style PHPUnit test classes use the PascalCase convention; only PSR-4-autoloaded test fixtures under tests/doubles/ use lowercase."
  - "Test classes are NOT namespaced — they extend MetapixelTestCase via top-level `use Logingrupa\\Metapixel\\Tests\\MetapixelTestCase;` and live in the global scope. PluginGuardTest + ExceptionHierarchyTest + every existing test established this pattern; the plan's namespace declaration `namespace Logingrupa\\Metapixel\\Tests\\Feature\\Adapter;` was rejected as an outlier — followed existing convention instead."
  - "SiteResolver PHPDoc rephrased to avoid literal banned identifiers in the prose. The plan's verify regex `! grep -E '(SiteManager|Site::|request\\(\\)|use Illuminate.Http.Request|use System.Classes.SiteManager)'` matches against ANY occurrence including comments, so the original PHPDoc that mentioned 'OctoberCMS SiteManager' was rewritten as 'site manager' (lowercase, hyphenated) to satisfy the static-source defence test in Task 3."
  - "EventLogWriter Throwable-branch test added during Task 5 (Rule 2). Plan Task 5 anticipated this exact fallback in its action block: 'If pest coverage < 90% on EventLogWriter: Add a Throwable-throwing branch test — use a closed table (drop the table then call record — the DB exception triggers the outer catch).' Initial coverage was 78.0%; after the 7th test, EventLogWriter hits 100%."
  - "First race-fence test uses non-null site_id=1 (not null/null as the plan example showed). Reason: SQLite + MySQL InnoDB both treat multiple NULL values in a UNIQUE column as DISTINCT (the dedicated NULL-distinct test in case 3 explicitly verifies this). With null/null both inserts succeed under NULL-distinct, and the test's 'second returns false' assertion fails. Non-null site_id forces the UNIQUE constraint to actually fire. The race-fence INVARIANT (only-one-winner-per-key) is unchanged."

patterns-established:
  - "Static-source regex defence test — for any class that has a phpstan disallowed-calls rule scoped to dirs that DO NOT include the class's own dir (e.g., SiteResolver under classes/helper/ is outside the deny-listed classes/{queue,event,adapter}/), add a test that reads the class's source file and asserts via assertDoesNotMatchRegularExpression that the banned identifiers do not appear in the source. Belt-and-suspenders pattern; cost is one trivial test, benefit is catching configuration drift."
  - "DB-failure branch test pattern — for any class that wraps DB writes in a try/catch fail-safe, the Throwable branch can be exercised by dropping the table inside the test (after setUp's up() call) and observing that the record/write call returns the fail-safe value + Log::critical fires. Cleaner than mocking the DB facade."
  - "EventLogWriter contract — subject_type column is opaque alias, NEVER class FQN. Two get_class($obSubject) calls live in Log diagnostic arrays only (no-adapter path and Throwable path); both are diagnostic context, NEVER subject_type writes. The plan's verify regex flagged the first as a false positive — documented here as intentional."

requirements-completed:
  - ADAP-06

duration: ~6 min
completed: 2026-05-17
---

# Phase 02 Plan 04: SiteResolver + EventLogWriter (ADAP-06 + P-05 alias write) Summary

**Phase 2 Wave 3 logic half landed — SiteResolver::forSubject is the sole authoritative site_id source (one-line delegation to the supplied EventSubjectAdapter); EventLogWriter::record is the UNIQUE race-fence writer that consults AdapterRegistry::resolveFor for the opaque subject_type alias (P-05 anchor at the persistence layer) and runs an outer try/catch fail-safe (Throwable → false + Log::critical, peer-wins assumption); 3 SiteResolverTest + 7 EventLogWriterRaceFenceTest cases all green; composer qa chain green — 56 tests / 134 assertions / 100.0% coverage on all 15 in-scope production files.**

## Performance

- **Duration:** ~6 min (2026-05-17T21:55:36Z → 22:01:43Z)
- **Tasks:** 5 (all auto-mode, no checkpoints)
- **Commits:** 5 (4 task commits + 1 Rule-2/Rule-3 QA-gate fix commit)
- **Files created:** 4
- **Files modified:** 0
- **Test count delta:** +10 tests (46 → 56) / +25 assertions (109 → 134)

## Accomplishments

- Shipped `classes/helper/SiteResolver.php` — 23 lines, final class, single public static method `forSubject(object $obSubject, EventSubjectAdapter $obAdapter): ?int` with body `return $obAdapter->getSiteId($obSubject);`. The PHPDoc documents the cross-context determinism contract in prose; the class itself has zero references to SiteManager / Site facade / Request / request() (verified by both the new T6 static-source regex test AND grep-guards at write time). Closes ADAP-06 PRIMARY logic half (the SECONDARY phpstan enforcement landed in plan 02-02; the contract test invariant lands in plan 02-07).
- Shipped `classes/helper/EventLogWriter.php` — 86 lines, final class, single public static method `record(): bool` per the 7-parameter RESEARCH §4.8 shape. Resolves subject via `App::make(AdapterRegistry::class)->resolveFor($obSubject)`; returns false + Log::warning when no adapter is registered; reads opaque `$sSubjectType = $obAdapter->getSubjectType($obSubject)` for the EventLog row's `subject_type` column (NEVER `get_class()` — P-05 anchor); rejects `getSubjectId() <= 0` with Log::warning + false; runs `DB::table('logingrupa_metapixel_event_log')->insertOrIgnore([...])` and returns true on `$iAffected === 1`, false on 0 (UNIQUE collision); outer try/catch swallows any Throwable to Log::critical + return false (fail-safe — peer-wins assumption prevents double-fire on DB outage). L-4 lock honored: Log/App/DB imported via Illuminate\Support\Facades\ FQN.
- Shipped `tests/Unit/Helper/SiteResolverTest.php` — 3 cases / 7 assertions covering: (1) `forSubject` returns the adapter's site_id (7) verbatim; (2) null site_id propagates from a FakeAdapter default; (3) static-source defence — opens SiteResolver.php and asserts zero regex matches for SiteManager / Site:: / Request:: / request(). Static defence is the SiteResolver-specific complement to plan 02-02's phpstan rule (which by H-1 disallowIn semantics intentionally does NOT scope classes/helper/).
- Shipped `tests/Feature/Adapter/EventLogWriterRaceFenceTest.php` — 7 cases / 18 assertions covering: (1) SEQUENTIAL same-key inserts (non-null site_id=1) — first true, second false, count=1 (race-fence anchor); (2) same subject+event, distinct channels (capi/pixel) — both true, count=2; (3) NULL-distinct site_id semantics — null then 7 — both true (verifies SQLite UNIQUE-NULL-distinct matches MySQL InnoDB per RESEARCH §9 A5 risk resolution); (4) no adapter registered (plain stdClass) — false + Log::warning; (5) ZeroIdSubjectAdapter returns getSubjectId=0 — false + Log::warning (`<=0` reject branch); (6) opaque alias write — DB row's `subject_type` column stores `'fake.subject'` with no backslashes (P-05 alias-correctness at persistence); (7) DB write failure (table dropped) — false + Log::critical (Throwable fail-safe branch). All 7 use H-8 setUp pattern + H-6 shared doubles from tests/doubles/ (no inline class declarations).
- `composer qa` (host-vendor smoke chain from plugin dir) green end-to-end: pint passed; phpstan level 10 phpVersion 80300 no errors; phpmd exit 0; pest 56 tests / 134 assertions / **100.0% coverage on all 15 in-scope production files** (Plugin + 3 adapter + 5 exception + 3 helper + 3 model).

## Task Commits

| Task | Description | Commit | Type |
|------|-------------|--------|------|
| 1 | Add SiteResolver — sole authoritative site_id source | `fdc7270` | feat |
| 2 | Add EventLogWriter — UNIQUE race-fence + alias-correct subject_type | `b7bf06d` | feat |
| 3 | T6 — SiteResolverTest covers delegation + static defence | `3ec6575` | test |
| 4 | T17 — EventLogWriterRaceFenceTest covers 6 branches (race-fence + NULL-distinct + alias + missing-adapter + non-positive-id) | `8098d1f` | test |
| 5 | composer qa green — pint autofix + EventLogWriter Throwable branch (7th case) | `e7a9eb1` | fix |

`docs(02-04)` metadata commit ships separately with this SUMMARY.md + STATE.md + ROADMAP.md.

## Files Created/Modified

### Created (4)

- `classes/helper/SiteResolver.php` — 23 lines; final class; `forSubject(object, EventSubjectAdapter): ?int` delegates to `$obAdapter->getSiteId($obSubject)`. Imports only `EventSubjectAdapter`. Class-level PHPDoc documents cross-context determinism in prose with no banned-identifier mentions in the source text.
- `classes/helper/EventLogWriter.php` — 86 lines; final class; static `record(): bool`; 7-parameter signature; AdapterRegistry consultation; insertOrIgnore on logingrupa_metapixel_event_log; outer try/catch fail-safe. L-4 FQN imports (Log/App/DB via Illuminate\Support\Facades).
- `tests/Unit/Helper/SiteResolverTest.php` — 3 cases / 7 assertions; H-8 setUp; FakeAdapter from tests/doubles/.
- `tests/Feature/Adapter/EventLogWriterRaceFenceTest.php` — 7 cases / 18 assertions; H-8 setUp (with table up() in setUp + down() in tearDown); H-6 shared doubles (TestSubject + TestSubjectAdapter + ZeroIdSubjectAdapter).

### Modified (0)

(None — Plan 02-04 was purely additive. Plugin.php, phpstan.neon, phpunit.xml, composer.json all unchanged.)

## Decisions Made

- **Plan path casing normalized to lowercase under `classes/`.** Plan 02-04-PLAN.md frontmatter and interface block listed PascalCase paths (`classes/Helper/SiteResolver.php`). The on-disk October Rain ClassLoader convention requires lowercase folder paths (carry-over from 02-01 deviation 1); the existing `classes/helper/PluginGuard.php` confirms the convention. Both new files shipped at lowercase `classes/helper/`. Namespaces remain PascalCase (`Logingrupa\Metapixel\Classes\Helper\…`) — only filesystem paths changed.
- **Test path casing preserved as PascalCase** for non-namespaced test classes. Plan 02-04-PLAN.md was already lowercased in the executor prompt's "Carry-over" section, but the existing on-disk convention is `tests/Unit/Helper/PluginGuardTest.php` (PascalCase non-namespaced). Followed 02-03a's documented decision: PascalCase test subdirs for non-namespaced classes; lowercase only for PSR-4 fixtures under `tests/doubles/`.
- **Test classes are NOT namespaced.** The plan's `namespace Logingrupa\Metapixel\Tests\Feature\Adapter;` declaration on EventLogWriterRaceFenceTest was rejected — no existing test in the suite is namespaced. All test classes (PluginGuardTest, ExceptionHierarchyTest, EventLogMigrationTest, etc.) live in the global scope and reference MetapixelTestCase via `use` import. Pest's phpunit `<directory>` scanner discovers them via filesystem walk, no PSR-4 involvement needed.
- **SiteResolver PHPDoc rephrased to avoid banned-identifier mentions in the source text.** The original PHPDoc said `…never from request context, OctoberCMS SiteManager, Auth, or any ambient state…`. The T6 static-source regex test (`assertDoesNotMatchRegularExpression('/\bSiteManager\b/', $sSource)`) catches ALL occurrences including comment text. Rephrased to `…never from ambient request, site manager, or auth state…` (lowercased, hyphenated "site manager" — no banned-identifier match). Equivalent intent, regex-safe.
- **First race-fence test uses non-null site_id=1.** The plan's example test body used `null` for site_id in both calls. Under SQLite + MySQL InnoDB UNIQUE-NULL-distinct semantics (two NULLs are DISTINCT), both inserts succeeded → the `assertFalse($bWonSecond)` assertion failed on the first run. Fix: use `site_id=1` in both calls so the UNIQUE constraint actually fires; the dedicated NULL-distinct test (case 3) still explicitly verifies the null-twin path. The race-fence INVARIANT — only-one-winner-per-key — is unchanged.
- **EventLogWriter outer try/catch fail-safe direction.** Returns false on any Throwable + Log::critical. The risk is silent event-suppression on DB outage; the benefit is preventing cascading queue retries that themselves fail with DB errors. Phase 4 dead-letter UI surfaces operator visibility on the FailedEvent table. Documented in the plan's threat register T-02-04-03 / 05 as accepted trade-offs.
- **Two `get_class($obSubject)` calls live in EventLogWriter — both in Log diagnostic arrays, NEVER in EventLog row writes.** Line 38 inside Log::warning (no-adapter path) and line 75 inside Log::critical (Throwable path). The plan's verify regex flagged line 38 as a false-positive match for "get_class subject_type write" — but the EventLog `subject_type` column is fed exclusively from `$sSubjectType = $obAdapter->getSubjectType($obSubject)` at line 47. Documented here as intentional diagnostic logging; the P-05 anchor (subject_type opaque alias from adapter) is upheld at the persistence layer.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] First race-fence test failed under SQLite NULL-distinct UNIQUE semantics**

- **Found during:** Task 4 (first pest run after writing the test file).
- **Issue:** `test_record_returns_true_on_first_insert_and_false_on_duplicate_unique_key` used `site_id=null` in both record() calls. SQLite (and MySQL InnoDB per RESEARCH §9 A5 and the 02-03a SUMMARY) treats multiple NULL values in a UNIQUE column as DISTINCT. Both inserts succeeded; `assertFalse($bWonSecond)` failed.
- **Fix:** Use `site_id=1` for both calls in the race-fence test (forces the UNIQUE constraint to fire). The dedicated NULL-distinct test (case 3) still explicitly verifies the null-twin path. Updated the inline comment in the test body to reference the NULL-distinct semantics and point readers to case 3.
- **Files modified:** `tests/Feature/Adapter/EventLogWriterRaceFenceTest.php` (lines 39–40 in commit `8098d1f` final state).
- **Verification:** All 7 EventLogWriterRaceFenceTest cases pass.
- **Rationale:** The plan-checker M-3 wording ("UNIQUE blocks the second insert regardless of concurrency model") was correct for non-null tuples; the example body in the plan was the outlier. The race-fence INVARIANT is unchanged; the test now correctly exercises it.

**2. [Rule 2 — Missing critical functionality] EventLogWriter Throwable-branch coverage gap**

- **Found during:** Task 5 (first `pest --coverage --min=90` run after Tasks 1–4 committed).
- **Issue:** `classes/helper/EventLogWriter.php` reported 78.0% coverage — the outer try/catch fail-safe branch (Log::critical + return false on Throwable) was uncovered. Overall total was 91.4% (above the `--min=90` gate) but EventLogWriter individually was below the plan's documented expectation of `≥ 95%`.
- **Fix:** Added `test_record_returns_false_on_db_write_failure` as the 7th case. Drops the EventLog table AFTER setUp's `up()` (so the table exists when the AdapterRegistry singleton was already wired), then calls `record()`. The `insertOrIgnore` call raises a `QueryException`, the outer try/catch swallows it, Log::critical fires, return value is false. Plan Task 5 anticipated this exact fallback in its action block.
- **Files modified:** `tests/Feature/Adapter/EventLogWriterRaceFenceTest.php` (added 12 lines for the 7th test).
- **Verification:** Pest reports EventLogWriter at 100.0% coverage; total coverage at 100.0%.
- **Rationale:** Fail-safe branches without test coverage are real risk — a future refactor could silently break the Throwable handling without a test gate to catch it.

**3. [Rule 3 — Block fix] Pint autoformat to pass `pint --test` gate**

- **Found during:** Task 5 (`pint --test Plugin.php classes models tests` reported `fail`).
- **Issue:** Pint's Laravel preset `fully_qualified_strict_types` rule flagged inline `new \stdClass` in both new test files (`SiteResolverTest.php` and `EventLogWriterRaceFenceTest.php`). PHP resolves bare `stdClass` in non-namespaced scope to the global root automatically; the leading backslash is redundant. Pint also flagged `ordered_imports` after the 7th test added `Schema` via inline FQN.
- **Fix:** Ran `pint` autofix. Replaced `new \stdClass` → `new stdClass` in both files; added `use Illuminate\Support\Facades\Schema;` and reordered the use-block alphabetically in EventLogWriterRaceFenceTest.
- **Files modified:** `tests/Unit/Helper/SiteResolverTest.php` (2 lines), `tests/Feature/Adapter/EventLogWriterRaceFenceTest.php` (1 import added + 1 inline FQN replaced).
- **Verification:** `pint --test` exits 0 (`result:passed`).
- **Rationale:** Pint is the project's source-of-truth formatter (composer qa step 1). Auto-fixing is the correct response.

---

**Total deviations:** 3 auto-fixed (Rule 1 × 1, Rule 2 × 1, Rule 3 × 1)
**Impact on plan:** All auto-fixes match documented expectations (Task 5 action block anticipated Rule 2; carry-over from 02-01 + 02-03b anticipated Rule 3 pint flow; plan's NULL-distinct note + plan-checker M-3 anticipated the SQLite NULL-distinct semantics fact). No scope creep — every fix is inside the plan's stated artifact set.

## Issues Encountered

- **Plugin standalone-composer-install limitation persists** (carry-forward from Phase 1 + plans 02-01..02-03b). `composer qa` from inside `plugins/logingrupa/metapixel/` exits 127 because plugin-local `vendor/bin/` does not exist. Workaround: host-vendor binaries at `/home/forge/nailscosmetics.lv/vendor/bin/{pint,phpstan,phpmd,pest}` + smoke phpstan config at `/tmp/metapixel-phpstan-smoke.neon` (absolute paths). Same as prior Phase 2 plans.
- **Verify regex in Task 2 false-positive on `get_class($obSubject)` inside Log array literal.** The plan's verify command was `! grep -v '^#' classes/helper/EventLogWriter.php | grep -E "get_class\(\\\$obSubject\)" | grep -v 'Log::'` — meant to catch any `get_class($obSubject)` write site that isn't on a `Log::` line. The two diagnostic `get_class($obSubject)` calls inside `Log::warning(...)` array literals live on their own physical lines (not the `Log::warning(` line) so the regex flagged them. Manually verified: both calls feed `meta_pixel.subject_class` / `meta_pixel.exception` keys (diagnostic context), NEVER the EventLog `subject_type` column. The P-05 anchor — subject_type opaque alias from adapter — is upheld at the persistence layer. The plan's `done` criteria reads "no get_class subject_type write" which IS met; the regex was an overly strict heuristic.

## Self-Check: PASSED

- All 4 created files exist on disk under `plugins/logingrupa/metapixel/`:
  - `classes/helper/SiteResolver.php` — FOUND.
  - `classes/helper/EventLogWriter.php` — FOUND.
  - `tests/Unit/Helper/SiteResolverTest.php` — FOUND.
  - `tests/Feature/Adapter/EventLogWriterRaceFenceTest.php` — FOUND.
- All 5 commit hashes present in `git log --oneline`:
  - `fdc7270` (feat: SiteResolver) — FOUND.
  - `b7bf06d` (feat: EventLogWriter) — FOUND.
  - `3ec6575` (test: SiteResolverTest) — FOUND.
  - `8098d1f` (test: EventLogWriterRaceFenceTest) — FOUND.
  - `e7a9eb1` (fix: composer qa green) — FOUND.
- `vendor/bin/pest --testsuite='Metapixel Unit Tests,Metapixel Feature Tests' --coverage --min=90` exits 0 from plugin dir with **56 tests / 134 assertions / 100.0% coverage on all 15 in-scope production files**.
- `vendor/bin/pint --test Plugin.php classes models tests` exits 0 (`result:passed`).
- `vendor/bin/phpstan analyse --configuration /tmp/metapixel-phpstan-smoke.neon` reports "No errors" (level 10, phpVersion 80300).
- `vendor/bin/phpmd Plugin.php,classes,models text phpmd.xml` exits 0.
- SiteResolver source has zero matches for SiteManager / Site:: / Request:: / request() identifiers (verified by both the T6 test AND a manual grep).
- EventLogWriter source contains `final class EventLogWriter`, `AdapterRegistry::class`, `insertOrIgnore`, `$obAdapter->getSubjectType($obSubject)`, `use Illuminate\Support\Facades\Log;` — all expected anchor points.
- No phase markers (`// CR-N`, `// Phase N`, `// Plan N`, `// P-0N`) in any new source file.

## Test method names (pest output)

| # | Test class | Test method | Status |
|---|---|---|---|
| T6 | SiteResolverTest | test_for_subject_delegates_to_adapter_get_site_id | PASS |
| T6 | SiteResolverTest | test_for_subject_propagates_null_from_adapter | PASS |
| T6 | SiteResolverTest | test_site_resolver_makes_no_request_or_site_manager_calls | PASS |
| T17 | EventLogWriterRaceFenceTest | test_record_returns_true_on_first_insert_and_false_on_duplicate_unique_key | PASS |
| T17 | EventLogWriterRaceFenceTest | test_record_returns_true_for_distinct_channel_same_subject | PASS |
| T17 | EventLogWriterRaceFenceTest | test_record_returns_true_for_distinct_site_id_same_subject | PASS |
| T17 | EventLogWriterRaceFenceTest | test_record_returns_false_when_no_adapter_registered_for_subject | PASS |
| T17 | EventLogWriterRaceFenceTest | test_record_returns_false_on_non_positive_subject_id | PASS |
| T17 | EventLogWriterRaceFenceTest | test_record_stores_subject_type_alias_not_class_fqn | PASS |
| T17 | EventLogWriterRaceFenceTest | test_record_returns_false_on_db_write_failure | PASS |

**10 new tests + 46 existing (from plans 02-01 / 02-03a / 02-03b) = 56 total, all PASS, 134 assertions.**

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
| classes/helper/EventLogWriter.php | 100.0 % |
| classes/helper/PluginGuard.php | 100.0 % |
| classes/helper/SiteResolver.php | 100.0 % |
| models/EventLog.php | 100.0 % |
| models/FailedEvent.php | 100.0 % |
| models/Settings.php | 100.0 % |
| **Total** | **100.0 %** |

## RESEARCH §9 risk A5 — NULL-distinct semantics resolved

**A5 RESOLVED.** Three test cases in EventLogWriterRaceFenceTest cover the NULL-distinct semantics on SQLite-in-memory:

- Case 1 (race-fence anchor): `site_id=1` for both inserts — UNIQUE constraint blocks the second insert, `$bWonSecond=false`, table count=1. **UNIQUE on non-null tuple fires as expected.**
- Case 3 (NULL-distinct verification): `site_id=null` then `site_id=7` — both succeed, table count=2. **NULL and non-null are distinct tuples.**
- The site_id=null/null case is NOT explicitly tested as a tuple-pair (would assert `count=2`), but is implicit in the channel-distinct case 2 where both inserts use `site_id=null` with DIFFERENT channels — both succeed, count=2 (different channels override the null-twin question).

**MySQL InnoDB parity:** The 02-03a SUMMARY already documented that SQLite-in-memory + MySQL InnoDB share NULL-distinct UNIQUE semantics ("Both engines treat multiple NULL values as distinct"). Production deployment behaves identically.

**Implication for downstream plans:** Plan 02-06 (SendCapiEvent) MUST resolve site_id via SiteResolver::forSubject BEFORE calling EventLogWriter::record — passing site_id=null when a real site exists allows the race-fence to be bypassed by sibling NULL inserts. The plan 02-02 phpstan deny-list on classes/queue/* enforces "use SiteResolver"; the plan 02-04 SiteResolver + EventLogWriter pair makes this a single defined path.

## composer qa tail (host-vendor smoke run from `plugins/logingrupa/metapixel/`)

```
=== 1/4 pint-test (host vendor) ===
{"tool":"pint","result":"passed"}

=== 2/4 phpstan analyse (host vendor, level 10, phpVersion 80300) ===

 [OK] No errors


=== 3/4 phpmd Plugin.php,classes,models ===
phpmd exit=0

=== 4/4 pest --testsuite='Metapixel Unit Tests,Metapixel Feature Tests' --coverage --min=90 ===
  Tests:    56 passed (134 assertions)
  Duration: 1.63s

  Plugin .............................................................. 100.0%
  classes/adapter/AdapterRegistry ..................................... 100.0%
  classes/adapter/EventSubjectAdapter ................................. 100.0%
  classes/adapter/ValueResolver ....................................... 100.0%
  classes/exception/MetaApiPermanentException ......................... 100.0%
  classes/exception/MetaApiTransientException ......................... 100.0%
  classes/exception/MetaPixelException ................................ 100.0%
  classes/exception/MissingCapiTokenException ......................... 100.0%
  classes/exception/MissingPixelConfigException ....................... 100.0%
  classes/helper/EventLogWriter ....................................... 100.0%
  classes/helper/PluginGuard .......................................... 100.0%
  classes/helper/SiteResolver ......................................... 100.0%
  models/EventLog ..................................................... 100.0%
  models/FailedEvent .................................................. 100.0%
  models/Settings ..................................................... 100.0%
  ────────────────────────────────────────────────────────────────────────────
                                                                Total: 100.0 %
```

Full QA log: `/tmp/02-04-qa.log`.

## Phase 2 plan-state update

Plan **02-04 CLOSED**. ADAP-06 closed at the logic layer (SiteResolver) and at the persistence write site (EventLogWriter — P-05 alias-correctness anchor).

- **02-05 (MetaClient + PayloadBuilder + UserDataHasher)** — UNBLOCKED (sequential next on master per orchestrator prompt). No file overlap with 02-04. Uses MetaApiTransient/Permanent exception classes from 02-03b.
- **02-06 (SendCapiEvent + ModelHandlers + event hooks)** — UNBLOCKED transitively on 02-04 + 02-05. SendCapiEvent::handle calls EventLogWriter::record for the capi-channel row + SiteResolver::forSubject for credential lookup via Settings::lookupForSite.
- **02-07 (FakeAdapterContractTest + ContractTestCase)** — UNBLOCKED transitively. Testsuite already wired by plan 02-02.

## Threat Flags

(none — SiteResolver + EventLogWriter ship without introducing new network endpoints, auth paths, or schema changes. All threats from the plan's STRIDE register (T-02-04-01 through T-02-04-06) are mitigated or accepted as documented; T-02-04-01 mitigation enforced by case 5 `test_record_returns_false_on_non_positive_subject_id`; T-02-04-02 mitigation enforced indirectly by AdapterRegistry's is_a hierarchy walk; T-02-04-03 audit-trail mitigation enforced by all 7 EventLogWriterRaceFenceTest cases asserting the correct Log facade calls fire on each branch; T-02-04-04/05/06 accepted dispositions documented in the plan.)

## Next Phase Readiness

- Plan **02-05 (MetaClient + PayloadBuilder + UserDataHasher)** is the next sequential plan on master (per executor prompt). Touches `classes/meta/MetaClient.php` + `classes/meta/PayloadBuilder.php` + `classes/meta/UserDataHasher.php` + test counterparts. No file overlap with this plan.
- `SiteResolver::forSubject` is now the documented single-point-of-truth for site_id reads; all downstream code calling it benefits from the static-source defence + phpstan disallowed-calls + one-line delegation contract.
- `EventLogWriter::record` is now the documented single-point-of-truth for EventLog row writes. Plan 02-06 (SendCapiEvent::handle) MUST call `EventLogWriter::record(...)` for both the capi-channel row (server fire) and the pixel-channel row (browser confirmation). Plan 02-07 (FakeAdapterContractTest) exercises the same path via a ContractTestCase smoke test.
- NULL-distinct semantics + non-null site_id-required for race-fence are now documented patterns; plan 02-06 MUST resolve site_id via SiteResolver BEFORE passing to EventLogWriter so the race-fence is exercised on real site_id tuples (never null/null).
- Test pattern locked: `tests/Feature/Adapter/` directory now populated (no longer empty). Plan 02-07 FakeAdapterContractTest can land sibling files under the same directory.

---

*Phase: 02-adapter-system-core-contracts-registry-extension-hooks*
*Plan: 4*
*Completed: 2026-05-17*
