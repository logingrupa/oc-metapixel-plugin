---
phase: quick-260616-wcp
plan: 01
subsystem: metapixel
tags: [meta-pixel, capi, test-events, payload, bugfix]
requires: []
provides:
  - deferred-fbq-test-event-code-parity
  - contentless-pageview-payload-gating
affects:
  - components/PixelHead.php
  - classes/meta/PayloadBuilder.php
tech-stack:
  added: []
  patterns:
    - "fbq 4th-arg options object composed from eventID + test_event_code via private helper"
    - "custom_data product-identity keys gated on non-empty resolved content_ids (never on event name)"
key-files:
  created: []
  modified:
    - components/PixelHead.php
    - classes/meta/PayloadBuilder.php
    - tests/Feature/Components/PixelHeadDeferredFlushTest.php
    - tests/Unit/Meta/PayloadBuilderTest.php
decisions:
  - "num_items + contents kept unconditional in PayloadBuilder; gate scoped strictly to content_type + content_ids"
  - "Operated in separate worktree; used sync-validate-restore against live plugin dir per worktree caveat"
metrics:
  duration: ~25m
  completed: 2026-06-16
---

# Quick 260616-wcp: PixelHead test_event_code + contentless PageView noise Summary

Two independent Phase-6 follow-up bugfixes: deferred browser fbq() blocks now carry `test_event_code` (so pushed ViewContent/etc. surface in Meta's Test Events tab), and contentless PageView CAPI payloads no longer ship product-shaped `content_type`/`content_ids` noise.

## What changed

### Concern 1 — `components/PixelHead.php` (commit 15ff54c)
`flushDeferredFromController()` now reads `Settings::get('test_event_code', '')` once at the top of the try block (mirroring `emitBasePixel`), guards the mixed return with `is_string()`, and JS-encodes it once via `json_encode(..., self::JS)` when non-empty. A new private helper `buildFbqOptionsObject($mEventId, ?string $sTestCodeJson)` composes the fbq() 4th-argument object from the optional `eventID` and optional `test_event_code`, returning `''` when neither is present so the caller emits a plain 3-arg `fbq("track", NAME, DATA)`. This keeps `flushDeferredFromController` readable and under 70 lines while making production output (no test code set) byte-identical to before: `{eventID: X}` for the event_id branch, no 4th arg for the no-event_id branch.

### Concern 2 — `classes/meta/PayloadBuilder.php` (commit d85ad79)
`buildEventPayload()` resolves `content_ids` into `$arContentIds` first, builds the base `custom_data` with only the always-present keys (`currency`, `value`, `num_items`, `contents`), then conditionally adds `content_ids` + `content_type => 'product'` only when `$arContentIds !== []`. The `$arEventExtras` `array_merge` overlay stays AFTER the gate, so an extras-supplied `content_type` still overrides the default even on contentless events. No comparison against `$sEventName` was introduced — the gate is purely on the resolved array.

## num_items keep-vs-gate decision (Concern 2)
`num_items` and `contents` are kept UNCONDITIONAL. Rationale: `num_items` is a generic counter (resolver returns `0` for contentless, a meaningful count otherwise) — it is not product-shaped the way `content_type`/`content_ids` are, Meta tolerates `0`, and gating it would add branching with no diagnostic benefit. `contents` likewise stays unconditional (the theme resolver returns `[]` for contentless, the correct neutral value). The gate is scoped strictly to the two product-identity keys that caused the noise, keeping the change minimal.

## Worktree / live-dir situation
Operated in a SEPARATE worktree (`/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/.claude/worktrees/agent-a2c7d9e1ae3d20180`), NOT the live plugin dir. Followed the sync-validate-restore pattern from `06-02-SUMMARY.md`: edited the 4 files in the worktree, copied them into the live plugin dir, ran pest + phpstan against the live dir, then `git checkout --` restored the live dir to its clean committed state before committing in the worktree. Live dir confirmed clean (only `?? .claude/`) before the canonical commits landed on the worktree branch.

## Verification
- `pest tests/Feature/Components/PixelHeadDeferredFlushTest.php tests/Unit/Meta/PayloadBuilderTest.php` — 11 passed, 56 assertions (4 new tests: test_event_code present/absent on both deferred branches; empty-content_ids omission; non-empty product-shape retention).
- `phpstan analyse --memory-limit=512M` — full 47-file scope, no errors (L10, phpVersion 80300).
- H-9 grep gate (`$sEventName` comparison) — no match (correct: gate is on content_ids array, not event name).
- Line coverage on changed source: `PayloadBuilder.php` 100.0%, `PixelHead.php` 91.3% (both > 90%).

## Deviations from Plan
None — plan executed exactly as written. Both concerns landed as two atomic commits, one per concern, in the prescribed commit-message form.

## Deferred Issues (out of scope — pre-existing, NOT caused by this plan)
5 documentation/asset tests fail on the pristine baseline (verified by running them in isolation — they read README.md / `lang/en` / screenshots, none of which this plan touched):
- `ReadmeStructureTest > readme contains seven named sections`
- `ReadmeStructureTest > readme install block shows october up`
- `ReadmeStructureTest > readme install block shows vcs repositories pattern`
- `ReadmeStructureTest > readme anchors field labels from lang en`
- `AssetsExistTest > five screenshots present with padded prefix`

These cause Pest to suppress the full-suite coverage Total table (Pest hides the coverage summary whenever any test fails), so the aggregate ≥90% gate could not render its summary line during this run. The two source files changed by this plan are individually at 100% / 91.3%. Logged to `deferred-items.md`; candidate for a follow-up `/gsd-quick` docs/asset sync pass. Not fixed here per SCOPE BOUNDARY (unrelated to PixelHead/PayloadBuilder).

## Self-Check: PASSED
- components/PixelHead.php — FOUND (modified, committed 15ff54c)
- classes/meta/PayloadBuilder.php — FOUND (modified, committed d85ad79)
- tests/Feature/Components/PixelHeadDeferredFlushTest.php — FOUND (committed 15ff54c)
- tests/Unit/Meta/PayloadBuilderTest.php — FOUND (committed d85ad79)
- commit 15ff54c — FOUND
- commit d85ad79 — FOUND
