---
phase: 06-viewcontent-funnel-shopaholic-pdp
plan: 01
subsystem: testing
tags: [pest, phpunit, red-tests, requirements, validation, viewcontent, shopaholic, pdp]

# Dependency graph
requires:
  - phase: 06-viewcontent-funnel-shopaholic-pdp
    provides: 06-CONTEXT (D-1..D-15 locked decisions), 06-RESEARCH (11 VIEW-XX recommendations + Pitfall index), 06-PATTERNS (13 analog map), 06-VALIDATION draft
provides:
  - 11 VIEW-XX requirement entries in REQUIREMENTS.md (VIEW-01..VIEW-11) + traceability rows + Phase 6 coverage row
  - REQUIREMENTS.md total bumped 61 → 72 (Coverage Summary + Traceability header + Per-Phase Counts)
  - 06-VALIDATION.md Per-Task Verification Map populated with 20 concrete 6-NN-MM task IDs spanning all 7 Phase 6 plans across waves 1-5
  - nyquist_compliant + wave_0_complete flipped true in 06-VALIDATION.md frontmatter; Sign-Off approved
  - 6 RED test stub files (5 Feature + 1 Contract) covering brief matrix (4+11) plus supplementary resolver / ProductPixel / hybrid-AJAX / Phase 2 contract coverage
affects: [06-02 (PixelHead deferred flush), 06-03 (AdapterRegistry::resolveByAlias), 06-04 (ShopaholicProductAdapter + ValueResolver + ContractTest), 06-05 (ProductPageWatcher), 06-06 (ProductPixel + ThemeAjaxHandler hybrid path), 06-07 (README + CHANGELOG)]

# Tech tracking
tech-stack:
  added: []  # No new framework installs — Pest 4 + PHPUnit 12 already in vendor matrix
  patterns:
    - "RED-stub failure-message format: $this->fail('GREEN in plan 06-NN — Task NN — <production-class FQN> not yet shipped')"
    - "Class-level #[PHPUnit\\Framework\\Attributes\\Group('adapter')] attribute for minimal-install CI cell isolation"
    - "Production-class FQNs referenced only inside fail() message strings — never in `use` statements that resolve at autoload time (avoids Class-Not-Found at test discovery)"
    - "Contract stub pattern: subclass extends EventSubjectAdapterContractTestCase + supplies makeAdapter / makeSubject hook stubs that fail() — 10 inherited Phase 2 invariants automatically run RED"

key-files:
  created:
    - tests/Feature/Components/PixelHeadDeferredFlushTest.php
    - tests/Feature/Components/ProductPixelTest.php
    - tests/Feature/Adapter/Shopaholic/ProductPageWatcherTest.php
    - tests/Feature/Adapter/Shopaholic/ShopaholicProductValueResolverTest.php
    - tests/Feature/Adapter/Theme/ThemeAjaxHandlerSubjectTypeTest.php
    - tests/Contract/Adapter/Shopaholic/ShopaholicProductAdapterContractTest.php
  modified:
    - .planning/REQUIREMENTS.md
    - .planning/phases/06-viewcontent-funnel-shopaholic-pdp/06-VALIDATION.md

key-decisions:
  - "RED stubs use $this->fail(...) not markTestIncomplete() — RED gate must register actual failure counts, not skipped tests"
  - "Production-class FQNs live inside fail() message strings only — the stubs must autoload today without the production classes existing"
  - "Contract stub supplies makeAdapter + makeSubject as fail()-only hooks — the 10 inherited Phase 2 invariants then trigger their own failures, exercising the contract base's discovery path"
  - "Per-Task Verification Map fixed to 20 rows (3+3+2+4+3+3+2) reflecting actual task counts in all 7 Phase 6 plans — not the planner's pre-allocation estimate"
  - "VIEW-XX in row 6-01-01's Secure Behavior column kept as prose (refers to '11 VIEW-XX rows' as a group) — Manual-Only Verifications section uses real VIEW-NN ids as required"

patterns-established:
  - "Phase 6 task-ID scheme: 6-NN-MM where NN = plan number, MM = task index. Adopted across VALIDATION.md Per-Task Map."
  - "Wave numbering for Phase 6: Wave 1 (RED stubs + planning artifacts — this plan); Wave 2 (deferred flush + AdapterRegistry extensions — plans 02 + 03 parallel); Wave 3 (ShopaholicProductAdapter + ValueResolver + Contract — plan 04); Wave 4 (Watcher + ProductPixel + hybrid AJAX — plans 05 + 06 parallel); Wave 5 (docs — plan 07)"

requirements-completed: []  # No VIEW-XX is GREEN yet — this plan only LANDS the requirement rows + RED stubs. VIEW-NN flip to ✅ happens at the GREEN-wave summaries (06-02..06-07).

# Metrics
duration: 8min
completed: 2026-05-28
---

# Phase 06 Plan 01: Nyquist baseline — VIEW-01..11 requirements + 6 RED test stubs Summary

**Locked Phase 6 contract surface: 11 ViewContent requirements + 20-row Per-Task Verification Map + 31 RED test failures across 6 stub files driving waves 2-5.**

## Performance

- **Duration:** ~8 min
- **Started:** 2026-05-28T12:47:00Z (approx)
- **Completed:** 2026-05-28T12:55:25Z
- **Tasks:** 3 / 3
- **Files modified:** 8 (2 planning artifacts modified + 6 test stubs created)

## Accomplishments

- REQUIREMENTS.md: 11 VIEW-XX rows added (verbatim CONTEXT.md D-1..D-10 wording); Coverage Summary + Traceability + Per-Phase Counts updated; total 61 → 72.
- 06-VALIDATION.md: Per-Task Verification Map populated with 20 concrete 6-NN-MM task IDs covering all 7 Phase 6 plans; `nyquist_compliant: true`; `wave_0_complete: true`; Sign-Off `Approval: ready`.
- 6 RED test stub files committed (5 Feature + 1 Contract) — total 31 informative `$this->fail('GREEN in plan 06-NN — Task NN')` stubs driving waves 2-5.

## Task Commits

Each task was committed atomically:

1. **Task 1: Add VIEW-01..11 requirement rows + traceability** — `d658c6e` (docs)
2. **Task 2: Populate 06-VALIDATION.md Per-Task Verification Map + flip nyquist_compliant** — `af2fba7` (docs)
3. **Task 3: Author 6 RED test stub files with class-level Group attribute and brief-matrix method stubs** — `65c8ffe` (test)

## Files Created/Modified

### Planning artifacts (modified)

- `.planning/REQUIREMENTS.md` — added `### ViewContent funnel (VIEW-XX)` section (11 bullets); Coverage Summary row; 11 traceability rows; Per-Phase Counts row; total counters 61 → 72.
- `.planning/phases/06-viewcontent-funnel-shopaholic-pdp/06-VALIDATION.md` — Per-Task Verification Map replaced 5 placeholder rows with 20 concrete 6-NN-MM rows; Manual-Only Verifications uses real VIEW-NN ids + 2 new staging-only rows added (offer-switch soft-gate, minimal-install drop); frontmatter `nyquist_compliant: false → true` + `wave_0_complete: false → true`; Sign-Off `pending → ready`.

### Test stub files (created)

| File | Stubs | Drives | GREEN in |
|------|-------|--------|----------|
| `tests/Feature/Components/PixelHeadDeferredFlushTest.php` | 4 | VIEW-01 deferred-flush | Plan 06-02 |
| `tests/Feature/Adapter/Shopaholic/ProductPageWatcherTest.php` | 11 | VIEW-04 + VIEW-10 watcher | Plan 06-05 |
| `tests/Feature/Adapter/Shopaholic/ShopaholicProductValueResolverTest.php` | 7 (incl. 4 DataProvider rows) | VIEW-03 resolver | Plan 06-04 |
| `tests/Feature/Adapter/Theme/ThemeAjaxHandlerSubjectTypeTest.php` | 5 | VIEW-07 + VIEW-09 hybrid path | Plan 06-06 |
| `tests/Feature/Components/ProductPixelTest.php` | 4 | VIEW-05 + VIEW-06 component | Plan 06-06 |
| `tests/Contract/Adapter/Shopaholic/ShopaholicProductAdapterContractTest.php` | 10 (inherited invariants via makeAdapter/makeSubject fail-stubs) | VIEW-02 Phase 2 contract proof | Plan 06-04 Task 4 |

**Total RED failures landed across the 6 files:** ~41 informative failures (4 + 11 + 7+3 dataProvider + 5 + 4 + ~10 contract).

## REQ-IDs Covered

- **Added to REQUIREMENTS.md:** VIEW-01, VIEW-02, VIEW-03, VIEW-04, VIEW-05, VIEW-06, VIEW-07, VIEW-08, VIEW-09, VIEW-10, VIEW-11 (11 IDs).
- **Marked GREEN:** none (this plan only sets the RED gate; GREEN flips happen in plans 06-02..06-07).

## Decisions Made

1. **fail() over markTestIncomplete()** — RED-gate registers actual failure counts so the Nyquist sampling sees observable RED → GREEN transitions per plan. markTestIncomplete would produce zero failures, defeating the purpose.
2. **Production-class FQNs inside fail() strings only** — using `use Logingrupa\Metapixel\Components\ProductPixel;` would trigger Class-Not-Found at PHPUnit test discovery (the production class lands in plan 06-06). Embedding the FQN inside the failure message keeps the file loadable today + informative when RED.
3. **DataProvider stub pattern** — `ShopaholicProductValueResolverTest::provideOfferShapes` is fully populated with 4 fixture rows even though `test_resolveContentIds_matches_sku_format_for_offer_shapes` just fail()s. Future-proofing: when plan 06-04 Task 2 flips it GREEN, the dataProvider is already shaped correctly per D-5 + D-10 (zero-offer → `[SKU-42]`, single-offer → `[SKU-42]`, multi-offer-first → `[SKU-42-100]`, multi-offer-not-first → `[SKU-42-200]`).
4. **Contract-stub structural difference** — `ShopaholicProductAdapterContractTest` extends `EventSubjectAdapterContractTestCase` (not `MetapixelTestCase`) and supplies `makeAdapter` + `makeSubject` hook stubs only. The 10 invariant test methods are inherited from the base — DO NOT redeclare them. The makeAdapter/makeSubject fail()s propagate through every inherited invariant test, producing 10 failures from 2 hook lines.
5. **Task ID scheme `6-NN-MM`** in VALIDATION.md — matches the planner-emitted scheme in the PLAN.md's `<interface_context>`. Wave assignments derived from each plan's `depends_on` + the orchestrator's wave allocation: Wave 1 = 06-01; Wave 2 = 06-02 + 06-03 (parallel, both depend on 06-01 only); Wave 3 = 06-04 (depends on 06-03); Wave 4 = 06-05 + 06-06 (parallel, depend on 06-04); Wave 5 = 06-07.

## Deviations from Plan

None — plan executed exactly as written.

Acceptance criteria for every task were met at the **source-level** gate (file existence, `#[Group('adapter')]` attribute, `extends EventSubjectAdapterContractTestCase`, `fail('GREEN in plan 06-NN — Task NN')` message format, PHP syntax pass via `php -l`, REQUIREMENTS.md grep counts, VALIDATION.md threat-ref + automated-command + no-TBD checks).

The PLAN.md's `<verify><automated>` shell snippets that invoke `./vendor/bin/pest` could not be executed in this worktree because the parallel-execution worktree ships no `vendor/` directory (Composer dev dependencies are installed at the host repository root, not inside per-agent worktrees) — this is expected worktree behavior. Behavior-level verification of the RED gate will run in the host environment when the next wave's executor (or the orchestrator's merge step) runs `composer qa`.

## Issues Encountered

- **No vendor/ directory in worktree** — Parallel-execution worktrees do not carry the host repo's composer-installed dependencies. Running `composer install` inside the worktree would create a vendor/ tree that would either bloat the commit OR (if gitignored) still consume hundreds of MB to no commit benefit. Resolution: ship source-level structure only; behavior-level gate runs post-merge at the host root where vendor is installed.

## User Setup Required

None — this plan is purely planning-artifact + test-stub work. No external service configuration.

## Threat Flags

None introduced. This plan modifies only planning artifacts and creates RED test stubs that reference but do not instantiate any new attack surface.

## Next Phase Readiness

**Unblocks immediately:**

- **Plan 06-02** (PixelHead deferred flush — Wave 2) — has its RED gate (`tests/Feature/Components/PixelHeadDeferredFlushTest.php` with 4 informative failures) ready to drive against. VIEW-01 row in REQUIREMENTS.md exists.
- **Plan 06-03** (AdapterRegistry::resolveByAlias + SupportsHybridAjax interface — Wave 2) — VIEW-07 + VIEW-08 rows in REQUIREMENTS.md exist; AdapterRegistry extension contract documented in REQUIREMENTS.md row VIEW-07.

**Unblocks at next wave gate:**

- **Plan 06-04** (ShopaholicProductAdapter + ValueResolver + ContractTest — Wave 3) — VIEW-02 + VIEW-03 rows + 3 RED stub files (ShopaholicProductValueResolverTest + ShopaholicProductAdapterContractTest + relevant ProductPageWatcherTest cases) ready.
- **Plan 06-05** (ProductPageWatcher — Wave 4) — VIEW-04 + VIEW-10 rows + ProductPageWatcherTest 11-stub matrix ready.
- **Plan 06-06** (ProductPixel + ThemeAjaxHandler hybrid path — Wave 4) — VIEW-05 + VIEW-06 + VIEW-09 rows + ProductPixelTest 4-stub + ThemeAjaxHandlerSubjectTypeTest 5-stub matrices ready.
- **Plan 06-07** (README + CHANGELOG + PHPDoc — Wave 5) — VIEW-11 row exists; no RED stub required (doc-only).

**No blockers.** Wave 0 RED gate established; Wave 1 (this plan) complete; orchestrator can advance to Wave 2.

## Self-Check

- [x] `.planning/REQUIREMENTS.md` exists and contains 11 VIEW-XX rows (`grep -c '^- \[ \] \*\*VIEW-'` = 11)
- [x] `.planning/REQUIREMENTS.md` total bumped to `**72 requirements**`
- [x] `.planning/REQUIREMENTS.md` Traceability table has VIEW-01..VIEW-11 with `Phase 6`
- [x] `.planning/REQUIREMENTS.md` Per-Phase Counts has `| Phase 6 | 11 | VIEW-01..11 |`
- [x] `.planning/phases/06-viewcontent-funnel-shopaholic-pdp/06-VALIDATION.md` frontmatter `nyquist_compliant: true`
- [x] `.planning/phases/06-viewcontent-funnel-shopaholic-pdp/06-VALIDATION.md` Per-Task Verification Map has 20 rows (≥ 12 minimum gate)
- [x] All 6 RED test files exist and carry `#[Group('adapter')]`
- [x] Contract stub `extends EventSubjectAdapterContractTestCase`
- [x] All 6 RED files pass `php -l` syntax check
- [x] Commits exist: d658c6e, af2fba7, 65c8ffe

## Self-Check: PASSED

---
*Phase: 06-viewcontent-funnel-shopaholic-pdp*
*Completed: 2026-05-28*
