---
phase: 02-adapter-system-core-contracts-registry-extension-hooks
plan: 02-08
subsystem: queue
tags: [gap-closure, security, hook-contract, snapshot-restore, dedup]
requires:
  - classes/queue/SendCapiEvent.php (pre-existing fireBeforeDispatchHalt)
  - tests/Unit/Hook/BeforeDispatchPayloadMutationTest.php (pre-existing 2 baseline tests)
  - 02-REVIEW.md CR-01 (reviewer adopted fix code)
  - 02-HUMAN-UAT.md gap (operator decision)
provides:
  - Full-payload snapshot+restore on envelope shape destruction
  - Log::warning on shape-break path with meta_pixel.event_id correlation key
  - Three new regression tests pinning Case A, Case A-null, Case C behavior
affects:
  - classes/queue/SendCapiEvent.php
  - tests/Unit/Hook/BeforeDispatchPayloadMutationTest.php
tech-stack:
  added: []
  patterns:
    - Snapshot+restore guard at hook boundary (Tiger-Style fail-fast for security contracts)
    - PHPStan level 10 narrowing via ?? null + is_array (avoid mixed offset access)
key-files:
  created: []
  modified:
    - classes/queue/SendCapiEvent.php (fireBeforeDispatchHalt: snapshot + shape-break restore)
    - tests/Unit/Hook/BeforeDispatchPayloadMutationTest.php (+3 test methods, +88 lines)
decisions:
  - Restore full payload snapshot wholesale when listener destroys envelope shape (Case A / A-null), rather than attempting partial reconstruction. Listener-controlled state cannot be trusted once data[0] is gone.
  - Keep intact-shape mutation surface (Case C) documented: when data[0] survives as an array, only event_id/event_time are re-applied — listener-added fields propagate. Operator-trusted contract; this is the announced "mutation surface" per the extensibility contract.
  - PHPStan level 10 narrowing via local $mData variable. Reviewer's pseudocode used direct offset chaining which trips offsetAccess.nonOffsetAccessible on mixed-typed envelope keys; semantically identical refactor.
metrics:
  duration: ~10m
  completed: 2026-05-20T20:28:41Z
  tasks_total: 3
  tasks_completed: 3
  commits: 3
  files_modified: 2
  test_methods_added: 3
  total_assertions_added: 11
---

# Phase 02 Plan 08: CR-01 Gap Closure — before_dispatch Envelope-Shape Snapshot Restore

One-liner: Apply 02-REVIEW.md CR-01 adopted fix — `SendCapiEvent::fireBeforeDispatchHalt` now wholesale-restores the pre-hook payload snapshot when a `before_dispatch` listener destroys envelope shape (`unset data`, `data = null`), preserving the server-owned event_id + the full event record (event_name, user_data, custom_data, action_source). The intact-shape branch continues to re-apply event_id/event_time only, with the listener-added field surface documented + locked in tests.

## What changed

1. **`classes/queue/SendCapiEvent.php::fireBeforeDispatchHalt`** — captures `$arSnapshot = $this->arPayload` BEFORE `Event::fire`. After the listener returns, narrows `$arMutablePayload['data']` via `?? null` + `is_array` checks. When the shape is broken, emits `Log::warning('metapixel: before_dispatch listener destroyed envelope shape — restoring snapshot', ['meta_pixel.event_id' => $sEventId])` and assigns `$this->arPayload = $arSnapshot`. When intact, re-applies `event_id` + `event_time` over listener mutations (unchanged from prior contract). The Throwable catch branch is unchanged.

2. **`tests/Unit/Hook/BeforeDispatchPayloadMutationTest.php`** — three new test methods (no `#[Group('adapter')]` attribute — both CI Run A + Run B cells execute them):
   - `test_listener_unsetting_data_triggers_full_snapshot_restore` — Case A: `unset($arPayload['data'])` → spy receives `event_id='uuid-1'`, `event_time=1700000000`, `event_name='Purchase'` (proves snapshot, not just two-field restore).
   - `test_listener_nulling_data_triggers_full_snapshot_restore` — Case A-null: `$arPayload['data'] = null` → same restore semantics.
   - `test_listener_replacing_data_zero_preserves_server_owned_event_id_and_documents_mutation_surface` — Case C: `$arPayload['data'][0] = ['event_id' => 'attacker-uuid', 'extra' => 'x']` → server-owned `event_id`/`event_time` re-applied; listener-added `extra` survives (documented mutation surface).

## Tasks

| # | Type     | Name                                                                     | Commit  | Files                                                                                |
|---|----------|--------------------------------------------------------------------------|---------|--------------------------------------------------------------------------------------|
| 1 | auto     | Apply CR-01 snapshot+restore fix per reviewer pseudocode                 | 4cef148 | classes/queue/SendCapiEvent.php                                                      |
| 2 | auto     | Add three regression tests for Case A / Case A-null / Case C             | 895176b | tests/Unit/Hook/BeforeDispatchPayloadMutationTest.php                                |
| 3 | auto     | QA gate validation — six binaries green                                  | a9e1f01 | classes/queue/SendCapiEvent.php (PHPStan level 10 narrowing follow-up)               |

## Verification

All six QA gates executed against the effective post-merge state (worktree changes mirrored into plugin root for validation; plugin root restored to its pre-mirror state afterward — the orchestrator's merge of this worktree branch lands the real changes):

| Gate                                              | Result                                       |
|---------------------------------------------------|----------------------------------------------|
| `vendor/bin/pint --test`                          | `{"tool":"pint","result":"passed"}`          |
| `vendor/bin/phpstan analyse -c phpstan.neon`      | `[OK] No errors`                             |
| `vendor/bin/phpmd ... text phpmd.xml`             | clean (no output)                            |
| `vendor/bin/pest` (full)                          | 430 passed (1532 assertions)                 |
| `vendor/bin/pest --coverage --min=90`             | Total 90.2% — coverage gate met              |
| `vendor/bin/pest --exclude-group=adapter` (Run B) | 244 passed (834 assertions)                  |

Targeted regression run for the new tests against post-merge state: `BeforeDispatchPayloadMutationTest` — **5 passed (16 assertions)** (2 baseline + 3 new). Test count delta in full suite: 427 → 430 (+3), assertion delta 1521 → 1532 (+11).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] Reviewer's pseudocode tripped PHPStan level 10 `offsetAccess.nonOffsetAccessible`**

- **Found during:** Task 3 (QA gate validation, phpstan)
- **Issue:** The literal CR-01 reviewer code `! isset($arMutablePayload['data'][0]) || ! is_array($arMutablePayload['data'][0])` chained offset access on the `mixed` type of `$arMutablePayload['data']`, failing PHPStan level 10. Also affected the intact-shape branch lines `$arMutablePayload['data'][0]['event_id'] = $sEventId`.
- **Fix:** Extract `$mData = $arMutablePayload['data'] ?? null` then `! is_array($mData) || ! isset($mData[0]) || ! is_array($mData[0])`. Intact path mutates the local `$mData` and writes back via `$arMutablePayload['data'] = $mData`. Semantically identical — the shape-break guard and event_id re-application path are preserved.
- **Files modified:** `classes/queue/SendCapiEvent.php`
- **Commit:** a9e1f01 (separate commit, NOT amend of 4cef148, per git policy "always create NEW commits")

### Authentication Gates

None.

## CLAUDE.md Compliance

- **No comment pollution.** Zero `// CR-XX`, `// Phase 2`, `// Plan 8`, `// gap_closure` markers added to source. The CR-01 anchor is recorded in commit messages and this SUMMARY only.
- **Hungarian notation.** New local variable `$mData` follows the `$m` mixed-type prefix (per Lovata.Toolbox + this plugin's CLAUDE.md). Test variables `$obSpyClient`, `$obJob`, `$payload` reuse the existing naming established in the same test file's baseline tests.
- **No `declare(strict_types=1)` added.** Optional per file (CLAUDE.md).
- **No `assert()` calls.** Shape-break guard uses explicit `if`/`Log::warning`/return.
- **Tiger-Style fail-fast.** Throwable catch in `fireBeforeDispatchHalt` is unchanged (documented log-and-abstain for listener exceptions). The new shape-break branch logs and falls back to the snapshot — fail-safe within the documented "server owns event_id/event_time" contract.
- **PHP 8.3/8.4 dual.** No 8.4-only syntax (no property hooks, no asymmetric visibility, no `array_find*`). The `?? null` + `is_array` pattern is 8.0+ safe.
- **DRY + SRP.** Snapshot capture stays inside `fireBeforeDispatchHalt`; no helper extracted (single use site, function still <40 lines).
- **No over-engineering.** No new abstractions, interfaces, or config knobs. Reviewer's adopted fix code only.

## Known Stubs

None.

## Threat Flags

None — this plan tightens an existing security boundary (the `before_dispatch` listener contract) rather than introducing new surface. The `metapixel.event.before_dispatch` hook surface itself is already enumerated in the plugin's extensibility contract.

## Notes for the Orchestrator

- This worktree branch contains three commits (4cef148, 895176b, a9e1f01) that should land as the Phase 02-08 changeset after merge.
- STATE.md and ROADMAP.md were NOT modified per parallel_executor rules.
- Plugin root files were touched transiently during QA validation (pre-mirror save → mirror → run gates → restore from saved copy). Plugin root `classes/queue/SendCapiEvent.php` and `tests/Unit/Hook/BeforeDispatchPayloadMutationTest.php` byte-identical to their pre-Plan-08 state at SUMMARY commit time. The orchestrator's merge of this worktree branch is what lands the changes for real.

## Self-Check: PASSED

- File `classes/queue/SendCapiEvent.php` modified in worktree (confirmed via git diff against 4f548d8: 10 insertions, 5 deletions in commit 4cef148 + 5 insertions, 3 deletions in a9e1f01).
- File `tests/Unit/Hook/BeforeDispatchPayloadMutationTest.php` modified in worktree (confirmed: +88 lines in commit 895176b).
- Commits 4cef148, 895176b, a9e1f01 present in `git log --oneline -6` (worktree HEAD).
- All six QA gates green against effective post-merge state.
- Plugin root restored: `git status --short classes/queue/SendCapiEvent.php tests/Unit/Hook/BeforeDispatchPayloadMutationTest.php` returns empty.
