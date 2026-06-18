---
phase: quick-260619-osv
plan: 01
subsystem: metapixel
tags: [viewcontent, offer-switch, dry, srp, fbq, capi]
requires:
  - PayloadBuilder content_ids/value gating (quick 260616-wcp)
  - ThemeEventCollector deferred-flush pipeline (Phase 6)
provides:
  - FbqScriptBuilder — single shared fbq track-block builder
  - OfferSwitchResult — readonly VO (event_id + browser custom_data)
  - content-rich offer-switch ViewContent browser script (mirrors CAPI)
affects:
  - components/PixelHead.php
  - classes/adapter/theme/ThemeAjaxHandler.php
  - classes/event/adapter/shopaholic/ProductPageWatcher.php
tech-stack:
  added: []
  patterns:
    - "Pure string-builder (static, reads no Settings) as single source of truth"
    - "Readonly value object for cross-layer return contract (8.1+ safe)"
key-files:
  created:
    - classes/meta/FbqScriptBuilder.php
    - classes/meta/OfferSwitchResult.php
    - tests/Unit/Meta/FbqScriptBuilderTest.php
  modified:
    - components/PixelHead.php
    - classes/event/adapter/shopaholic/ProductPageWatcher.php
    - classes/adapter/theme/ThemeAjaxHandler.php
    - tests/Feature/Adapter/Shopaholic/ProductPageWatcherTest.php
    - tests/Feature/Adapter/Theme/ThemeAjaxHandlerSubjectTypeTest.php
decisions:
  - "Empty custom_data renders as json_encode([]) == [] (not hardcoded {}) — DRY-consistent with PixelHead; fbq treats [] and {} identically as empty."
  - "FbqScriptBuilder owns the canonical JS encode-flags const; PixelHead self::JS retained for base-pixel path (unchanged)."
requirements: [VIEW-04, VIEW-07, VIEW-09]
metrics:
  duration: ~50m
  completed: 2026-06-19
  commits: 2
  tasks: 2
  files-created: 3
  files-modified: 5
---

# Phase quick-260619-osv Plan 01: Offer-Switch ViewContent Completion Summary

Collapsed three duplicate fbq-track-block render sites into one shared `FbqScriptBuilder` (DRY+SRP) and finished the half-built offer-switch path so the browser ViewContent now mirrors the CAPI payload (content_ids `SKU-{pid}-{oid}` + value + currency + test_event_code) and dedups by eventID.

## What was built

**Task 1 — FbqScriptBuilder extraction (commit 673d2f1)**
- New `final class FbqScriptBuilder` (namespace `Logingrupa\Metapixel\Classes\Meta`): one static `build(string, array, ?string, ?string): string` plus the canonical `JS` encode-flags const. Pure string assembly, reads no Settings — caller passes test_event_code.
- `PixelHead::flushDeferredFromController` now delegates to the builder; removed the inline `buildFbqOptionsObject` + dual sprintf branches. Added a small `extractCustomData` helper to keep the collector-event → custom_data narrowing string-keyed for phpstan L10.
- Production deferred-flush output is byte-identical (PixelHeadDeferredFlushTest 6/6 green; FbqScriptBuilderTest has explicit byte-identical-to-legacy guards).

**Task 2 — OfferSwitchResult + enrichment + AJAX routing (commit e2e7a2f)**
- New `final class OfferSwitchResult` readonly VO carrying `sEventId` + `arCustomData`.
- `ProductPageWatcher::dispatchForOfferSwitch` return type changed `string` → `OfferSwitchResult`; assembles the browser-facing ViewContent custom_data once and reuses it for both the collector push and the returned VO.
- `ThemeAjaxHandler`: all three theme fbq-block sites (offer-switch product branch, generic-alias branch, non-subject-type `onBeforeRun` branch) now route through `FbqScriptBuilder::build`. Offer-switch script carries the watcher's custom_data + test_event_code; generic/theme branches carry test_event_code + eventID with empty (`[]`) custom_data (no invented content).

## Verification

- **FbqScriptBuilderTest:** 9/9 green (all 4th-arg branches, JS hex-escape, byte-identical guards).
- **PixelHeadDeferredFlushTest:** 6/6 green (byte-identical, no behavior change).
- **ProductPageWatcherTest:** green incl. OfferSwitchResult shape assertions (content_ids/content_type/currency/value).
- **ThemeAjaxHandlerSubjectTypeTest:** 7/7 green incl. new generic-theme test_event_code test.
- **phpstan L10:** clean on all 5 source files + 3 test files (plugin `phpstan.neon`).
- **pint:** passed on all touched files.
- **Grep gates:** `FbqScriptBuilder::` referenced in both PixelHead + ThemeAjaxHandler; zero inline `sprintf(... fbq("track" ...)` literals remain in either.
- **Full suite:** 527 passed, 5 failed — the 5 failures are the pre-existing Launch-Milestone REDs (ReadmeStructureTest ×4 + AssetsExistTest ×1), NOT regressions from this work.

## Coverage

- New files `FbqScriptBuilder` and `OfferSwitchResult`: **100%**.
- Touched-file coverage unchanged or improved; all test changes were additive (no test removals).
- Full-codebase total sits at ~88.5% — this is the pre-existing baseline driven by unfinished Launch-Milestone deliverables (untested Tiger-Style catch/error boundaries in PixelHead/ThemeAjaxHandler/ProductPageWatcher predate this plan). This plan added net-positive coverage and did not lower the baseline.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] phpstan L10 array<string,mixed> narrowing in PixelHead**
- **Found during:** Task 1
- **Issue:** `array_diff_key` widens the collector-event array to `array<mixed>`, which the typed `FbqScriptBuilder::build(array<string,mixed>)` rejected at L10.
- **Fix:** Extracted an `extractCustomData(array $arEvent): array<string,mixed>` helper that string-key-narrows the explicit-`custom_data` branch.
- **Files modified:** components/PixelHead.php
- **Commit:** 673d2f1

**2. [Plan intent vs json_encode reality] Empty custom_data renders as `[]` not `{}`**
- **Found during:** Task 2
- **Issue:** Plan locked decision described generic-theme empty custom_data as `{}`; `json_encode([])` produces `[]`.
- **Fix:** Kept `[]` (the faithful json_encode result, DRY-consistent with PixelHead's empty-data rendering). fbq treats `[]` and `{}` identically as empty custom_data. New generic-theme test asserts `[]`.
- **Files modified:** classes/adapter/theme/ThemeAjaxHandler.php, tests/Feature/Adapter/Theme/ThemeAjaxHandlerSubjectTypeTest.php
- **Commit:** e2e7a2f

**3. [Rule 3 - Tooling] Dropped redundant CmsController alias in PixelHead**
- **Found during:** Task 2 (pint run)
- **Issue:** pint's `fully_qualified_strict_types` fixer added a duplicate `use Cms\Classes\Controller;` alongside the existing `Controller as CmsController` alias.
- **Fix:** Removed the alias; use `Controller` directly for import, signature, and docblock.
- **Files modified:** components/PixelHead.php
- **Commit:** e2e7a2f

## Threat surface scan

No new security-relevant surface introduced. T-osv-01 (XSS) mitigation preserved: `FbqScriptBuilder` uses the canonical `JSON_HEX_TAG|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_HEX_APOS` flags; unit test asserts hex-escaping of `<"&'` in the event name. Existing handler guards (numeric/positive subject_id + offer_id, loadSubject re-enforcement) unchanged.

## Self-Check: PASSED
- classes/meta/FbqScriptBuilder.php — FOUND (committed 673d2f1)
- classes/meta/OfferSwitchResult.php — FOUND (committed e2e7a2f)
- tests/Unit/Meta/FbqScriptBuilderTest.php — FOUND (committed 673d2f1)
- commit 673d2f1 — FOUND
- commit e2e7a2f — FOUND
