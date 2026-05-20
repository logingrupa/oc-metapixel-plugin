---
status: complete
phase: 02-adapter-system-core-contracts-registry-extension-hooks
source: [02-VERIFICATION.md]
started: 2026-05-17T22:00:00Z
updated: 2026-05-20T00:30:00Z
---

## Current Test

[testing complete]

## Tests

### 1. composer qa on full-Lovata install
expected: pint-test passes, phpstan level 10 passes, phpmd passes, pest --coverage --min=90 passes (93 test methods across 42 test files)
result: pass
verified_by: orchestrator 2026-05-20 — ran four QA gates via root vendor binaries (`/home/forge/nailscosmetics.lv/vendor/bin/{pint,phpstan,phpmd,pest}`) against plugin's `phpstan.neon`, `phpmd.xml`, `phpunit.xml`. Results: pint `{"tool":"pint","result":"passed"}`; phpstan level 10 `[OK] No errors`; phpmd clean (no output); pest `427 passed (1521 assertions)`, coverage `Total: 90.2 %` (meets ≥90 gate). Plugin's local `vendor/larastan` + `vendor/spaze` are symlinks into root project vendor, so phpstan extensions resolved correctly without a standalone `composer install`.

### 2. CR-01 envelope-destroyed bypass path behaviour
expected: Decide whether `before_dispatch` listener that does `unset($arPayload['data'])` is acceptable (MetaClient POSTs empty envelope → Meta 400 → FailedEvent written) or whether the snapshot/restore logic in `SendCapiEvent::fireBeforeDispatchHalt` (lines 176-181) should restore the full payload snapshot instead of conditionally restoring `event_id`/`event_time` only.
result: issue
decision: Adopt CR-01 fix — full-snapshot fallback + Log::warning on shape destruction. Adopted by operator 2026-05-20.
severity: major

### 3. Multi-site operator awareness — Settings::lookupForSite
expected: On a live two-site October install (e.g., nailscosmetics.lv + nailscosmetics.no), both sites read the same default Settings row. Operator is aware per-site credentials land in Phase 4 (MULT-03). No silent mis-routing in production.
result: pass
note: Stale UAT premise corrected. Per-site routing IS implemented in Phase 2 — models/Settings.php:62-79 reads per-site Multisite row when $iSiteId !== null and falls back to default-row when per-site value empty (D-01). REQUIREMENTS.md MULT-01/02/03 all marked complete. Operator confirms (2026-05-20) aware of backend site-picker workflow and silent default-row fallback; no operator depends on legacy shared-row behavior.

## Summary

total: 3
passed: 2
issues: 1
pending: 0
skipped: 0
blocked: 0

## Gaps

- truth: "before_dispatch hook MUST preserve server-owned event_id/event_time AND envelope integrity even when listener corrupts data[0] shape (sets data=null, unsets data, replaces data[0] with arbitrary record)"
  status: failed
  reason: "Operator adopted CR-01 reviewer recommendation. Current SendCapiEvent::fireBeforeDispatchHalt (classes/queue/SendCapiEvent.php:164-195) only restores event_id/event_time conditionally on intact data[0] shape; Case A (unset data) ships empty envelope to Meta, Case C (data[0] replaced with attacker record keeping event_id) ships arbitrary content under legit event_id."
  severity: major
  test: 2
  artifacts:
    - classes/queue/SendCapiEvent.php:164-195
    - .planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-REVIEW.md (CR-01)
  missing:
    - Snapshot of full $this->arPayload before Event::fire
    - Shape-break detection (!isset(data[0]) || !is_array(data[0])) → Log::warning + restore $arSnapshot
    - Test: listener unset($arPayload['data']) → assert $this->arPayload['data'][0]['event_id'] still equals original UUID after hook
    - Test: listener $arPayload['data'][0] = ['event_id' => $original, 'extra' => 'x'] → assert no event_name/user_data leak (either restore snapshot or document mutation surface)
