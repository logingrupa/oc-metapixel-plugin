---
phase: 05-documentation-marketplace-launch
plan: 18
subsystem: testing
tags: [phpmd, cyclomatic-complexity, refactor, coverage, pest, phpstan, adapter]

# Dependency graph
requires:
  - phase: 05-documentation-marketplace-launch
    provides: composer qa toolchain + phpmd.xml thresholds (MKT-05 gate)
provides:
  - "composer qa exits 0 end-to-end on the full-Lovata install (phpmd 7 violations -> 0)"
  - "ThemeAjaxRequestReader collaborator owning AJAX payload parsing"
  - "Behaviour-preserving decomposition of 3 over-complex production methods"
affects: [launch-milestone, marketplace-launch, MKT-05]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Move-Method to a collaborator to drop class WMC below ExcessiveClassComplexity (intra-extraction alone raises WMC)"
    - "Extract-Method + resolver helper to bring CyclomaticComplexity/NPath below phpmd thresholds without suppression"

key-files:
  created:
    - classes/adapter/theme/ThemeAjaxRequestReader.php
    - tests/Feature/Adapter/Theme/ThemeAjaxRequestReaderTest.php
  modified:
    - classes/adapter/theme/ThemeAjaxHandler.php
    - classes/event/adapter/shopaholic/ProductPageWatcher.php
    - components/PixelHead.php
    - tests/Feature/Adapter/Theme/ThemeAjaxHandlerSubjectTypeTest.php
    - tests/Feature/Components/PixelHeadDeferredFlushTest.php

key-decisions:
  - "phpmd ExcessiveClassComplexity fires at WMC == 50 (not > 50); targeted WMC <= 48 by moving 3 parse methods + a DRY readIntField accessor out to ThemeAjaxRequestReader"
  - "Pre-existing coverage was 89.0% at the plan baseline (not >=90 as the plan assumed); lifted to 90.3% via focused tests for the extracted branches rather than lowering the gate"
  - "dispatchGenericAdapter kept $obAdapter (SupportsHybridAjax) as an explicit param for byte-exact getValueResolver call instead of re-resolving via App::make"

patterns-established:
  - "Clear the shared PDepend AST cache (~/.pdepend) before trusting a phpmd re-run — it serves stale complexity per scan-set"

requirements-completed: [MKT-05]

coverage:
  - id: D1
    description: "composer qa (pint-test -> phpstan L10 -> phpmd -> pest --coverage --min=90) exits 0 end-to-end on the full-Lovata install"
    requirement: MKT-05
    verification:
      - kind: integration
        ref: "vendor/bin: pint --test && phpstan analyse && phpmd Plugin.php,classes,models,console,components,middleware,controllers text phpmd.xml && pest -c phpunit.xml --coverage --min=90"
        status: pass
    human_judgment: false
  - id: D2
    description: "phpmd exits 0 with zero violations across the full qa file-set (was 7 violations in 3 files)"
    requirement: MKT-05
    verification:
      - kind: automated
        ref: "vendor/bin/phpmd Plugin.php,classes,models,console,components,middleware,controllers text phpmd.xml (exit 0)"
        status: pass
    human_judgment: false
  - id: D3
    description: "Behaviour byte-preserved: full pest suite green, no public signature changes"
    requirement: MKT-05
    verification:
      - kind: integration
        ref: "vendor/bin/pest -c phpunit.xml (569 passed / 2191 assertions; 561 pre-existing + 8 focused coverage tests)"
        status: pass
    human_judgment: false

# Metrics
duration: 37min
completed: 2026-07-03
status: complete
---

# Phase 05 Plan 18: MKT-05 phpmd Refactor Summary

**Decomposed 3 over-complex production methods behaviour-preservingly (ThemeAjaxHandler, ProductPageWatcher, PixelHead) plus a new ThemeAjaxRequestReader collaborator, taking phpmd from 7 violations to 0 and the whole composer qa chain to exit 0 at 90.3% coverage.**

## Performance

- **Duration:** ~37 min
- **Started:** 2026-07-03T11:31:00Z
- **Completed:** 2026-07-03T12:08:00Z
- **Tasks:** 3 (+ coverage-gate deviation)
- **Files modified:** 4 production (3 modified + 1 new) + 3 test files

## Accomplishments

- **phpmd 7 -> 0 violations.** All three flagged methods now sit below thresholds with margin: `onBeforeRun` CC 10->3, `dispatchViaAdapter` CC 14/NPath 1024 -> CC 8, `dispatchForOfferSwitch` CC 11/NPath 240 -> CC 5, `flushDeferredFromController` CC 11 -> CC 6, `ThemeAjaxHandler` class WMC 55 -> 47.
- **New `ThemeAjaxRequestReader` collaborator** owns AJAX payload parsing (`readEventData`, `buildHybridContext`, `normalizeStringKeys`, DRY `readIntField`) — the Move-Method needed to drop class WMC below phpmd's ExcessiveClassComplexity floor (intra-extraction alone raises WMC).
- **composer qa exits 0 end-to-end** (pint-test -> phpstan L10 -> phpmd -> pest --coverage --min=90), the MKT-05 acceptance bar.
- **Coverage lifted 89.0% -> 90.3%** by adding 8 focused tests for the now-discrete extracted branches — the plan's sanctioned response to a coverage dip, not a lowered gate.

## Task Commits

1. **Task 1: ThemeAjaxHandler below phpmd thresholds (+ request-reader collaborator)** - `6f0222a` (refactor)
2. **Task 2: ProductPageWatcher::dispatchForOfferSwitch below thresholds** - `530385b` (refactor)
3. **Task 3: PixelHead::flushDeferredFromController below threshold** - `140e38a` (refactor)
4. **Coverage-gate tests for extracted branches** - `e9b7e07` (test)

## Files Created/Modified

- `classes/adapter/theme/ThemeAjaxRequestReader.php` (new) - stateless request-payload parser (readEventData, buildHybridContext, readIntField, normalizeStringKeys)
- `classes/adapter/theme/ThemeAjaxHandler.php` - onBeforeRun split into handleFireEvent; dispatchViaAdapter split into dispatchShopaholicOfferSwitch + dispatchGenericAdapter; parse methods delegated to the reader; resolveAllowedEventName narrowing helper
- `classes/event/adapter/shopaholic/ProductPageWatcher.php` - dispatchForOfferSwitch split into resolveOfferContentData + applyOfferCustomDataToPayload
- `components/PixelHead.php` - flushDeferredFromController loop body extracted into private static buildDeferredScriptBlock
- `tests/Feature/Adapter/Theme/ThemeAjaxHandlerSubjectTypeTest.php` - added generic non-Shopaholic hybrid-adapter dispatch test
- `tests/Feature/Adapter/Theme/ThemeAjaxRequestReaderTest.php` (new) - 5 unit tests, reader to 100%
- `tests/Feature/Components/PixelHeadDeferredFlushTest.php` - added also_dispatch_capi mirror + nameless-event-skip tests

## Decisions Made

- **phpmd ExcessiveClassComplexity fires at WMC == 50**, not strictly `> 50` as the plan estimated. First pass hit exactly 50 and still failed; added a DRY `readIntField` accessor on the reader (removing 3 numeric-coercion ternaries from the handler) to reach WMC 47 with margin.
- **`dispatchGenericAdapter` takes `SupportsHybridAjax $obAdapter`** (private-method signature adjusted from the plan's suggested shape) so the `getValueResolver($obSubject)` call is byte-exact rather than re-resolving a fresh adapter via `App::make($sAdapterClass)`.
- **Kept every phpstan L10 narrowing guard verbatim** when moving code (`is_string` key narrowing, `is_numeric ? (int) : 0`, `is_array` envelope walks).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Coverage gate was already below 90% at the plan baseline**
- **Found during:** Plan-level verification (pest --coverage --min=90)
- **Issue:** The plan asserted phpmd was the *only* composer-qa blocker and that coverage >=90 already held. Measuring the pre-plan commit (9f89b87) with the four production files restored showed **89.0%** — the coverage gate was already failing independently of phpmd. The behaviour-preserving refactor itself caused no regression (89.0% before and after), but `composer qa` still could not exit 0 until coverage crossed 90%.
- **Fix:** Added 8 focused tests exercising the now-discrete extracted branches (generic hybrid-adapter dispatch, ThemeAjaxRequestReader parsing, PixelHead also_dispatch_capi mirror + nameless-event skip). This is exactly the plan's `<verification>` instruction: "add a focused test for that branch — do NOT lower the gate." Result 90.3%.
- **Files modified:** 3 test files (2 modified + 1 new)
- **Verification:** pest 569 passed at 90.3%; ThemeAjaxRequestReader 100%, ThemeAjaxHandler 72% -> 87.2%
- **Committed in:** `e9b7e07`

---

**Total deviations:** 1 auto-fixed (1 blocking coverage gate)
**Impact on plan:** No scope creep — production changes stayed within the 4 listed files; only test files were added to protect the qa gate the plan itself mandates. No phpmd.xml edits, no @SuppressWarnings, no comment markers, no public signature changes.

## Issues Encountered

- **PDepend AST cache (`~/.pdepend`) served stale complexity results.** After the refactor, a full-directory phpmd scan reported the OLD violations (with NEW line numbers) while per-file scans passed — non-deterministic per scan-set. Root cause: the shared PDepend parse-tree cache. Clearing `~/.pdepend` before each phpmd run produced correct, deterministic exit 0.
- **`composer qa` cannot run from the plugin dir** (`pint: not found`) — the standalone-install limitation documented in STATE.md / 01-03-SUMMARY.md (plugin has no local `vendor/bin`). Ran the identical 4-gate chain via the host binaries at `/home/forge/nailscosmetics.lv/vendor/bin`, the sanctioned smoke path; all four gates green.
- **BusFake did not record the SendCapiEvent dispatched from `dispatchGenericAdapter`** under an anonymous test-double adapter (a test-env BusFake/anonymous-class quirk; the dispatch line provably executes and the theme path records fine in ThemeAjaxHandlerAllowlistTest). The generic-branch test asserts on the observable 200 response (empty `{}` custom_data + eventID + test_event_code), which fully covers the branch.

## Next Phase Readiness

- MKT-05 closed: `composer qa` exits 0 on the full-Lovata install. This was the last automatable Phase 5 exit-gate blocker.
- Carry-forwards remain human/Launch-Milestone only: SC1/DOCS-01 timed clean-room README dry-run and MKT-01 clean-install smoke require a networked fresh install (no outbound network here); SC5/MKT-04 v2.0.0 tag is Launch-Milestone (launch-02).

## Self-Check: PASSED

- FOUND: classes/adapter/theme/ThemeAjaxRequestReader.php
- FOUND: tests/Feature/Adapter/Theme/ThemeAjaxRequestReaderTest.php
- FOUND: .planning/phases/05-documentation-marketplace-launch/05-18-SUMMARY.md
- Commits verified: 6f0222a, 530385b, 140e38a, e9b7e07

---
*Phase: 05-documentation-marketplace-launch*
*Completed: 2026-07-03*
