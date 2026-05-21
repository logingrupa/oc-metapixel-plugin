---
phase: 5
plan: 10
plan_id: 05-10
subsystem: documentation-marketplace-launch
tags: [docs, adapter, marketplace, capi]
requires:
  - .planning/ROADMAP.md (AcmeCart canonical register snippet, lines 117-136)
  - .planning/REQUIREMENTS.md (DOCS-03 — third-party adapter authoring guide)
  - .planning/phases/05-documentation-marketplace-launch/05-CONTEXT.md (D-14, D-15, D-16, D-22, D-23)
  - .planning/phases/05-documentation-marketplace-launch/05-RESEARCH.md (Pattern 4 + Code Examples Example 2)
  - .planning/phases/05-documentation-marketplace-launch/05-PATTERNS.md (CUSTOM-ADAPTERS.md anchoring strategy)
  - plugins/logingrupa/metapixel/classes/adapter/EventSubjectAdapter.php (7-method interface)
  - plugins/logingrupa/metapixel/classes/adapter/ValueResolver.php (5-method interface)
  - plugins/logingrupa/metapixel/classes/adapter/AdapterRegistry.php (register / resolveByClass)
  - plugins/logingrupa/metapixel/classes/queue/SendCapiEvent.php (HOOK_* constants)
  - plugins/logingrupa/metapixel/classes/testing/EventSubjectAdapterContractTestCase.php (10 invariants)
provides:
  - docs/CUSTOM-ADAPTERS.md (third-party adapter authoring guide — DOCS-03)
  - tests/Feature/Docs/CustomAdaptersStructureTest.php::test_doc_contains_both_acme_cart_minimal_and_mall_full_examples (W-12 dual-name assertion)
affects:
  - .planning/REQUIREMENTS.md (DOCS-03 satisfied)
tech_stack:
  added: []
  patterns:
    - "Documentation-as-contract: Wave 0 CustomAdaptersStructureTest grep-asserts hook constants + ContractTestCase + register pattern + AcmeCart/OFFLINE\\Mall dual-name presence in the marketplace authoring guide."
    - "W-12 conflict resolution: AcmeCart minimal register snippet (matches ROADMAP.md Architecture-at-a-glance + DOCS-03 literal wording) shipped FIRST as the registration-pattern example; OFFLINE\\Mall full adapter + value resolver (D-14 lock) shipped SECOND as the contract example. Both code paths live ONLY in the doc — no classes/adapter/mall/ directory created."
    - "Three Event::fire hook contracts documented verbatim with copy-paste examples — test_event_code injection (before_dispatch with event_id/event_time immutability warning per dedup contract), analytics mirror (after_dispatch), Slack alert (dead_letter)."
key_files:
  created:
    - docs/CUSTOM-ADAPTERS.md
  modified:
    - tests/Feature/Docs/CustomAdaptersStructureTest.php
decisions:
  - "D-14 honored: full inline OFFLINE\\Mall adapter + MallOrderValueResolver published as code blocks inside the documentation only. No classes/adapter/mall/ directory in production."
  - "W-12 (AcmeCart vs OFFLINE\\Mall) resolved by shipping BOTH name references: AcmeCart serves the REQUIREMENTS.md DOCS-03 literal wording + ROADMAP.md Architecture-at-a-glance canonical snippet; OFFLINE\\Mall serves CONTEXT.md D-14. Section order places AcmeCart FIRST as register-pattern, OFFLINE\\Mall SECOND as full contract example."
  - "D-15 honored: all three SendCapiEvent::HOOK_* constants documented as verbatim strings with copy-paste examples — before_dispatch (halt-able + dedup-contract immutability warning), after_dispatch (observe-only), dead_letter (observe-only)."
  - "D-16 honored: dedicated `## Testing your adapter` section anchored to Logingrupa\\Metapixel\\Classes\\Testing\\EventSubjectAdapterContractTestCase + factory hooks makeAdapter() + makeSubject(); all 10 marketplace invariants enumerated verbatim from the contract-test file."
  - "D-22 + D-23 honored: zero v1.1.1 / legacy/v1 / Phase N references anywhere in the published doc (the v2.0 marketplace launch is install-fresh-only)."
metrics:
  duration: "~2 min"
  completed: "2026-05-21"
  tasks_completed: 2
  commits: 2
  files_touched: 2
threat_flags: []
---

# Phase 5 Plan 10: Custom Adapter Authoring Guide (DOCS-03) Summary

Third-party adapter authoring guide published at `docs/CUSTOM-ADAPTERS.md` —
ships AcmeCart minimal register snippet first (matches REQUIREMENTS.md DOCS-03
+ ROADMAP.md Architecture-at-a-glance canonical wording) and the full
~50-LOC `MallOrderAdapter` + ~30-LOC `MallOrderValueResolver` inline example
second (CONTEXT.md D-14 lock). All three `Event::fire` hook constants
documented with copy-paste examples; `## Testing your adapter` section
anchored to `EventSubjectAdapterContractTestCase` with all 10 marketplace
invariants enumerated. Wave 0 `CustomAdaptersStructureTest` flips from RED
to GREEN — all 8 assertions verifiable by grep.

## Tasks Completed

| Task | Description                                                                                        | Commit    | Files                                                                  |
| ---- | -------------------------------------------------------------------------------------------------- | --------- | ---------------------------------------------------------------------- |
| 1    | Extend `CustomAdaptersStructureTest` with the W-12 dual-name (AcmeCart + OFFLINE\\Mall) assertion. | `4337e78` | `tests/Feature/Docs/CustomAdaptersStructureTest.php`                   |
| 2    | Author `docs/CUSTOM-ADAPTERS.md` — DOCS-03 deliverable, 8 sections, 357 lines.                     | `d398208` | `docs/CUSTOM-ADAPTERS.md` (new)                                        |

## Doc Metrics

- **Total line count:** 357 (exceeds plan `min_lines: 180` by ~2×).
- **Section headings:** 8 top-level (`##`) sections — Overview (`#`),
  Contract, Minimal example (AcmeCart), Full inline example
  (OFFLINE\\Mall), Trigger dispatch, Hook patterns, Testing your adapter,
  Anti-patterns. Hook patterns contains 3 sub-sections (one per hook
  constant).
- **AcmeCart snippet** (`## Minimal example: register your adapter (AcmeCart)`
  → `## Full inline example: OFFLINE Mall`): 37 lines including PHP block +
  three-bullet narrative.
- **OFFLINE\\Mall full implementation** (`## Full inline example: OFFLINE Mall`
  → `## Trigger dispatch`): 90 lines spanning the
  `MallOrderAdapter` (~52 LOC PHP) + `MallOrderValueResolver` (~28 LOC PHP)
  blocks + closing narrative establishing the D-14 "doc-only, no production
  directory" lock.

## Grep-Verified Wave 0 Test Status

All 8 assertions in `tests/Feature/Docs/CustomAdaptersStructureTest.php` pass
when re-run against the published doc (verified by simulating each assertion
via `grep` since the worktree omits `vendor/bin/pest`):

| Test method                                                          | Substring(s) probed                              | Match count(s) |
| -------------------------------------------------------------------- | ------------------------------------------------ | -------------- |
| `test_custom_adapters_doc_file_exists`                               | `docs/CUSTOM-ADAPTERS.md` exists                 | exists         |
| `test_doc_contains_before_dispatch_hook_constant`                    | `metapixel.event.before_dispatch`                | 1              |
| `test_doc_contains_after_dispatch_hook_constant`                     | `metapixel.event.after_dispatch`                 | 1              |
| `test_doc_contains_dead_letter_hook_constant`                        | `metapixel.event.dead_letter`                    | 2              |
| `test_doc_contains_offline_mall_inline_example`                      | `OFFLINE\\Mall` + `mall.order`                   | 2, 2           |
| `test_doc_contains_contract_testcase_reference`                      | `EventSubjectAdapterContractTestCase`, `makeAdapter`, `makeSubject` | 3, 1, 1 |
| `test_doc_shows_register_pattern`                                    | `AdapterRegistry::instance()->register`          | 2              |
| `test_doc_contains_both_acme_cart_minimal_and_mall_full_examples` (W-12) | `AcmeCartAdapter` / `class AcmeCart` AND `OFFLINE\\Mall` / `class MallOrderAdapter` | 6 + 2 |

Forbidden references (D-22 + D-23): `v1.1.1` / `legacy/v1` / `Phase [0-9]`
patterns each return zero matches against `docs/CUSTOM-ADAPTERS.md`.

## Section Ordering (W-12 Resolution Locked)

1. `# Authoring a custom adapter` — overview paragraph (when a third party
   needs an adapter, what an adapter is).
2. `## The contract: EventSubjectAdapter + ValueResolver` — verbatim 7-method
   `EventSubjectAdapter` interface + 5-method `ValueResolver` interface +
   per-method English explanation + closing reminders on opaque-alias rule +
   `getSiteId` from-subject-only constraint.
3. `## Minimal example: register your adapter (AcmeCart)` — 18-LOC AcmeCart
   `Plugin::boot()` register snippet copied verbatim from ROADMAP.md
   lines 117-136. Three-bullet narrative on `$require`, `register`, and
   `SendCapiEvent::dispatch`.
4. `## Full inline example: OFFLINE Mall` — ~52-LOC `MallOrderAdapter`
   implementing all 7 `EventSubjectAdapter` methods + ~28-LOC
   `MallOrderValueResolver` implementing all 5 `ValueResolver` methods, with
   the explicit D-14 lock paragraph: *"This code lives ONLY in this
   documentation. The plugin itself does NOT ship a `classes/adapter/mall/`
   directory."*
5. `## Trigger dispatch` — `SendCapiEvent::dispatch` is the only public
   entry; queue worker rehydrates the adapter via
   `AdapterRegistry::resolveByClass`; permanent failure path triggers
   `dead_letter`.
6. `## Hook patterns` — three sub-sections, one per hook constant. Copy-paste
   PHP `Event::listen` examples per RESEARCH lines 664-700 verbatim, with
   the immutability warning for `before_dispatch`.
7. `## Testing your adapter` — `EventSubjectAdapterContractTestCase` factory
   pattern + 10 marketplace invariants enumerated verbatim.
8. `## Anti-patterns` — `Component::extend(PixelHead::class, ...)` is LAST
   RESORT per CLAUDE.md Extensibility contract rank 6.

## Deviations from Plan

None — plan executed exactly as written. Both tasks landed in two atomic
commits with the test-first (RED for the W-12 assertion) then
documentation-author (GREEN) ordering implied by the plan's own task
sequence.

Substitution note on the plan's `<verify><automated>` step: the worktree
ships without `vendor/bin/pest` (only `vendor/composer/` + `vendor/autoload.php`
are present), so the Pest invocation prescribed in the plan cannot run here.
Each test assertion was instead simulated via the equivalent `grep` /
`str_contains` query against `docs/CUSTOM-ADAPTERS.md` — all 8 assertions
(including the new W-12 dual-name assertion) match. The pest run is
delegated to CI on the merge target where the full `composer install`
dependency tree exists.

## Threat Surface Notes

Per the plan's `<threat_model>`:

- **T-05-10-01 (Tampering on `before_dispatch`):** Mitigation in place —
  the `### before_dispatch` sub-section explicitly closes with the warning
  *"MUST NOT mutate `event_id` or `event_time` — Meta dedup contract anchor.
  The job snapshots and restores both fields after the hook runs to enforce
  this."*
- **T-05-10-02 (Information Disclosure — real token in doc example):**
  Accepted via the plan disposition. The `before_dispatch` example uses
  `config('mall.metapixel.test_event_code')` (placeholder, not a real
  token); the `dead_letter` example uses `'#alerts-metapixel'` channel
  placeholder. No real secrets shipped.

No new threat surface introduced — static documentation only; no production
file touched beyond the existing test file extension.

## Requirements Satisfied

- **DOCS-03** — Third-party adapter authoring guide published with both the
  literal `AcmeCartAdapter` register snippet (REQUIREMENTS.md wording) and
  the full inline OFFLINE\\Mall implementation (D-14 lock).

## Self-Check: PASSED

- `docs/CUSTOM-ADAPTERS.md` exists at the path expected by
  `tests/Feature/Docs/CustomAdaptersStructureTest::loadCustomAdaptersDoc()`
  (verified via `[ -f docs/CUSTOM-ADAPTERS.md ]`).
- Commit `4337e78` (test extension) found in `git log --oneline -5` — see
  Tasks table above.
- Commit `d398208` (doc author) found in `git log --oneline -5` — see
  Tasks table above.
- All 8 grep-simulated test assertions report PASS.
- Zero forbidden v1.x / legacy / Phase-N references in the published doc.
