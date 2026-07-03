---
phase: 05-documentation-marketplace-launch
plan: 22
subsystem: docs
tags: [readme, october-migrate, pixelhead, composer, dev-master, uat, launch]

# Dependency graph
requires:
  - phase: 05-documentation-marketplace-launch
    provides: "05-09 README marketplace surface + ReadmeStructureTest doc-gate; 05-14 launch-02 tag/publish plan"
provides:
  - "README corrected for a fresh-October-4.3 clean-room buyer: october:migrate (not deprecated october:up), [pixelHead] INI declaration alongside the Twig mount, and a :dev-master pre-release install fallback"
  - "ReadmeStructureTest pins october:migrate as the doc-gate instruction"
  - "launch-02-PLAN.md Step F.2/F.3 re-verify the verbatim stable install command post-tag and drop the pre-release note once it resolves"
affects: [launch-02, uat-test-7, docs-01]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Doc-gate pins the corrected command via positive assertion only (no negative literal embedded in shipped test source)"
    - "Pre-release install fallback pins a single package with :dev-master past a fresh-root minimum-stability=stable filter without loosening the whole project"

key-files:
  created:
    - .planning/phases/05-documentation-marketplace-launch/05-22-SUMMARY.md
  modified:
    - README.md
    - tests/Feature/Docs/ReadmeStructureTest.php
    - .planning/launch/launch-02-PLAN.md

key-decisions:
  - "October 4.3 deprecated october:up to a no-op — README + doc-gate now use october:migrate, the command that applies plugin migrations"
  - "The [pixelHead] INI declaration is documented as mandatory — the Twig tag alone renders an empty string (HTTP 200, zero fbq(), no log signature)"
  - "Primary `oc-metapixel-plugin -W` command retained (doc-gate asserts the substring); :dev-master fallback documented as pre-release-only, to be dropped by launch-02 Step F.3 once the stable tag resolves"

patterns-established:
  - "Post-tag doc re-verification: README verbatim install command is re-run on a fresh clean-room October root immediately after the v2.0.0 tag push (launch-02 Step F.2), and the pre-release note is removed once the stable command resolves (Step F.3)"

requirements-completed: [DOCS-01]

coverage:
  - id: D1
    description: "Every october:up occurrence in README replaced with october:migrate; doc-gate pins october:migrate"
    requirement: "DOCS-01"
    verification:
      - kind: unit
        ref: "tests/Feature/Docs/ReadmeStructureTest.php#test_readme_install_block_shows_october_migrate"
        status: pass
      - kind: other
        ref: "grep -v '^#' README.md | grep -c 'october:up' == 0"
        status: pass
    human_judgment: false
  - id: D2
    description: "[pixelHead] INI declaration shown alongside the Twig mount in quick-start step 6 and Theme walkthrough step 1"
    requirement: "DOCS-01"
    verification:
      - kind: other
        ref: "grep -c '\\[pixelHead\\]' README.md >= 2 (actual 3)"
        status: pass
      - kind: unit
        ref: "tests/Feature/Docs/ReadmeStructureTest.php (8 tests / 21 assertions green)"
        status: pass
    human_judgment: false
  - id: D3
    description: ":dev-master pre-release install fallback documented in README (Install + quick-start step 3); primary -W command retained"
    requirement: "DOCS-01"
    verification:
      - kind: other
        ref: "grep -c 'dev-master -W' README.md == 2; primary 'oc-metapixel-plugin -W' retained"
        status: pass
    human_judgment: false
  - id: D4
    description: "launch-02 Step F.2/F.3 re-verify the verbatim stable install command post-tag and drop the README pre-release note once it resolves"
    requirement: "DOCS-01"
    verification:
      - kind: other
        ref: "grep -ci 'oc-metapixel-plugin -W' .planning/launch/launch-02-PLAN.md == 2"
        status: pass
    human_judgment: false
  - id: D5
    description: "Timed clean-room README dry-run (UAT test 7, SC1 stopwatch gate) re-run verbatim after the v2.0.0 tag push"
    verification: []
    human_judgment: true
    rationale: "Operator-gated timed dry-run on a fresh October install — must run after launch-02 pushes the v2.0.0 tag; cannot be automated pre-tag while the remote is tagless"

# Metrics
duration: 9min
completed: 2026-07-03
status: complete
---

# Phase 5 Plan 22: UAT Test 7 README Dry-Run Gap Closure Summary

**Corrected the three clean-room README dead-ends from the live UAT test 7 run — october:up → october:migrate, the required [pixelHead] INI declaration, and a :dev-master pre-release install fallback — with the doc-gate pinning the fix and launch-02 scheduling the post-tag verbatim re-verify.**

## Performance

- **Duration:** ~9 min
- **Tasks:** 3
- **Files modified:** 3 (README.md, tests/Feature/Docs/ReadmeStructureTest.php, .planning/launch/launch-02-PLAN.md)

## Accomplishments
- Replaced all four `php artisan october:up` occurrences (install block, settings fallback, quick-start step 4, troubleshoot table) with `october:migrate` — October 4.3 deprecated the old command to a no-op that applies zero plugin migrations — and renamed/repointed the doc-gate test so it pins `october:migrate`.
- Documented the mandatory `[pixelHead]` INI declaration alongside the `{% component 'pixelHead' %}` Twig mount in both quick-start step 6 and Theme walkthrough step 1, stating plainly the Twig tag alone is a silent no-op.
- Added a `:dev-master -W` pre-release install fallback to the README Install block and quick-start step 3 (fresh October root defaults to `minimum-stability=stable`; tagless remote refuses the plain `-W`), retaining the primary `oc-metapixel-plugin -W` command the doc-gate asserts.
- Extended launch-02-PLAN.md with Step F.2 (verbatim `-W` re-verify on a fresh clean-room October root post-tag) and Step F.3 (drop the README pre-release note once it resolves) plus matching verification bullets.

## Task Commits

Each task was committed atomically:

1. **Task 1: Replace deprecated october:up + pin the fix in the doc-gate** - `d4a733a` (fix)
2. **Task 2: Show the required [pixelHead] INI declaration alongside the Twig mount** - `7b8c124` (docs)
3. **Task 3: Document the pre-release install fallback + cross-reference launch-02** - `16e1e07` (docs)

## Files Created/Modified
- `README.md` - october:up → october:migrate (×4), [pixelHead] INI declaration in quick-start + Theme walkthrough, :dev-master pre-release install note (Install + quick-start)
- `tests/Feature/Docs/ReadmeStructureTest.php` - renamed test to `test_readme_install_block_shows_october_migrate`, needle + message + docblock repointed to october:migrate
- `.planning/launch/launch-02-PLAN.md` - Step F.2 verbatim re-verify + Step F.3 drop-note sub-steps + two verification bullets

## Decisions Made
- Followed the plan's explicit instruction to NOT embed a negative assertion for the old `october:up` literal in the test body — the positive `october:migrate` assertion plus the grep gate are sufficient and avoid shipping the deprecated string in test source.
- Kept the primary `oc-metapixel-plugin -W` command intact (doc-gate `test_readme_install_documents_fresh_install_prerequisites` asserts the `oc-metapixel-plugin -W` substring); the `:dev-master` fallback is documented as pre-release-only.

## Deviations from Plan
None - plan executed exactly as written.

## Issues Encountered
None. Baseline was 8 tests / 21 assertions green; the doc-gate stayed green (8/21) after every task.

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- The three README dead-ends proven by the live clean-room run (metapixel-test7, October v4.3.1) are removed. A buyer following the README verbatim now runs commands that apply migrations, render the Pixel, and (via the pre-release fallback) resolve the package on a tagless remote.
- **Open gate (operator, post-tag):** re-run UAT test 7 acceptance — the timed ≤10-min clean-room README dry-run (SC1 / DOCS-01 stopwatch) — once launch-02 pushes the v2.0.0 tag, and drop the README pre-release note per launch-02 Step F.3 once the verbatim `-W` command resolves the stable tag. Tag push remains operator-gated (launch-02, LAUNCH SCHEDULED signal); not executed here.

## Self-Check: PASSED

- All 3 modified files + SUMMARY.md present on disk.
- All 3 task commits (`d4a733a`, `7b8c124`, `16e1e07`) present in git history.
- Doc-gate ReadmeStructure: 8 tests / 21 assertions green after every task; residual `october:up` count 0.

---
*Phase: 05-documentation-marketplace-launch*
*Completed: 2026-07-03*
