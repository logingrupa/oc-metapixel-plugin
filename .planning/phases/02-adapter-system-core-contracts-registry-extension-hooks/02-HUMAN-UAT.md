---
status: partial
phase: 02-adapter-system-core-contracts-registry-extension-hooks
source: [02-VERIFICATION.md]
started: 2026-05-17T22:00:00Z
updated: 2026-05-17T22:00:00Z
---

## Current Test

[awaiting human testing]

## Tests

### 1. composer qa on full-Lovata install
expected: pint-test passes, phpstan level 10 passes, phpmd passes, pest --coverage --min=90 passes (93 test methods across 42 test files)
result: [pending]
why_human: Cannot run composer qa from this orchestrator session without a full OctoberCMS + Lovata dependency tree (plugin's `vendor/` is not installed standalone). Confirms the documented acceptance gate is actually green. Operator runs `cd plugins/logingrupa/metapixel/ && composer install && composer qa` on a local dev install.

### 2. CR-01 envelope-destroyed bypass path behaviour
expected: Decide whether `before_dispatch` listener that does `unset($arPayload['data'])` is acceptable (MetaClient POSTs empty envelope → Meta 400 → FailedEvent written) or whether the snapshot/restore logic in `SendCapiEvent::fireBeforeDispatchHalt` (lines 176-181) should restore the full payload snapshot instead of conditionally restoring `event_id`/`event_time` only.
result: [pending]
why_human: Existing tests cover key-replacement mutation; clearing-path is observable in code but undecided. Code reviewer (CR-01 in 02-REVIEW.md) recommends full-snapshot fallback. Business decision: should an extension be allowed to destroy the envelope, or must it use `halt=true` to abort cleanly?

### 3. Multi-site operator awareness — Settings::lookupForSite
expected: On a live two-site October install (e.g., nailscosmetics.lv + nailscosmetics.no), both sites read the same default Settings row. Operator is aware per-site credentials land in Phase 4 (MULT-03). No silent mis-routing in production.
result: [pending]
why_human: `Settings::lookupForSite($iSiteId)` currently ignores `$iSiteId` (Phase 4 deferred). Acceptable per REQUIREMENTS.md MULT-03 scope, but a human should confirm no current operator depends on per-site pixel routing in Phase 2.

## Summary

total: 3
passed: 0
issues: 0
pending: 3
skipped: 0
blocked: 0

## Gaps
