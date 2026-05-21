---
phase: 5
plan: 11
plan_id: 05-11
subsystem: docs-marketplace
tags: [d-23, release-hygiene, docblock-strip, gate-test]
requires: [05-00]
provides:
  - "D-23 lock — public-shipped surface (Plugin.php, classes/, lang/) carries no Phase N decorators or v1.x narrative"
  - "NoV1xReferencesTest gate prevents regression on future PRs"
affects:
  - "Plugin.php docblock — registerSchedule narrative"
  - "classes/queue/SendCapiEvent.php docblocks — class + writeFailedEvent"
  - "classes/testing/EventSubjectAdapterContractTestCase.php class docblock"
  - "classes/adapter/shopaholic/ShopaholicCartPositionAdapter.php getUserData docblock"
  - "classes/adapter/shopaholic/ShopaholicSettingsOptions.php getDefaultCurrencyCodeOptions docblock"
  - ".planning/ROADMAP.md MKT-* prose"
  - ".planning/REQUIREMENTS.md MKT-* + Out-of-Scope + category-headers prose"
tech-stack:
  added: []
  patterns:
    - "Recursive *.php scan via RecursiveIteratorIterator anchored to LangKeyCoverageTest hermetic file-load idiom"
key-files:
  created:
    - tests/Feature/Docs/NoV1xReferencesTest.php
  modified:
    - Plugin.php
    - classes/queue/SendCapiEvent.php
    - classes/testing/EventSubjectAdapterContractTestCase.php
    - classes/adapter/shopaholic/ShopaholicCartPositionAdapter.php
    - classes/adapter/shopaholic/ShopaholicSettingsOptions.php
    - .planning/ROADMAP.md
    - .planning/REQUIREMENTS.md
decisions:
  - "Stripped 13 Phase-N decorators across 5 PHP files; behavior unchanged (Tiger-Style pure docblock/comment edit)"
  - "Rewrote MKT-03 + MKT-04 + Out-of-Scope row + Build philosophy + TOOL-02 + ROADMAP Phase-5 Success Criteria 4-5 + Numbering + Pitfall map + Shipped Milestones to drop every v1.1.1 + legacy/v1.1.1 substring per strict D-23 verify gate"
  - "Stripped (Phase N — ...) parentheticals from 11 category headers in REQUIREMENTS.md"
  - "NoV1xReferencesTest scope = Plugin.php + classes/ + lang/{en,lv}/lang.php (matches key_links contract; .planning/ explicitly out-of-scope of test enforcement per plan key_links)"
metrics:
  duration: "~12 minutes"
  completed: 2026-05-21
  tasks_executed: 3
  files_changed: 8
  decorators_stripped: 13
  planning_doc_lines_rewritten: "~12 prose lines across ROADMAP.md + REQUIREMENTS.md"
---

# Phase 5 Plan 11: v1.x reference strip + D-23 gate Summary

Stripped 13 in-code Phase-N docblock decorators across 5 PHP files, rewrote MKT-03 + MKT-04 + adjacent prose in ROADMAP.md + REQUIREMENTS.md to remove every v1.1.1 / legacy/v1.1.1-branch reference per D-23, and shipped `tests/Feature/Docs/NoV1xReferencesTest.php` as a 5-assertion gate preventing regression on the public-shipped surface.

## Outcome

| Task | Action | Commit | Files |
|------|--------|--------|-------|
| 1 | Strip Phase-N decorators from 5 PHP files (docblock-only) | `ea94c6e` | Plugin.php, classes/queue/SendCapiEvent.php, classes/testing/EventSubjectAdapterContractTestCase.php, classes/adapter/shopaholic/ShopaholicCartPositionAdapter.php, classes/adapter/shopaholic/ShopaholicSettingsOptions.php |
| 2 | Rewrite MKT-03 + MKT-04 + adjacent prose in ROADMAP.md + REQUIREMENTS.md per D-23 | `44c79dd` | .planning/ROADMAP.md, .planning/REQUIREMENTS.md |
| 3 | Ship D-23 gate test `NoV1xReferencesTest` (5 assertions) | `7e42a72` | tests/Feature/Docs/NoV1xReferencesTest.php |

## Task 1 — Phase-N decorator strip (commit `ea94c6e`)

Locked rewrites applied verbatim from `.planning/phases/05-documentation-marketplace-launch/05-PATTERNS.md` lines 420-501:

- `Plugin.php` line 148 — registerSchedule docblock: removed `(Phase 3 D-08)` parenthetical AND `RESEARCH pitfall 7` ref; rephrased as plain LSP-variance prose.
- `classes/queue/SendCapiEvent.php` two sites:
  - Class-level docblock line 45 — `(enables Phase 4 admin UI re-resolution)` → `(enables admin UI re-resolution)`.
  - `writeFailedEvent` docblock line 249-252 — `Phase 4 admin UI can re-resolve` → `admin UI can re-resolve`.
- `classes/testing/EventSubjectAdapterContractTestCase.php` four sites in class docblock:
  - Line 14-15 — `(FakeAdapter in Phase 2, ShopaholicOrderAdapter + ThemeActionAdapter in Phase 3) extend this base` → `(FakeAdapter, ShopaholicOrderAdapter + ThemeActionAdapter) extend this base`.
  - Line 34 — `the Phase 2 marketplace contract.` → `the marketplace contract.`.
  - Line 36 — `Extending MetapixelTestCase is a Phase 2 YAGNI choice — Phase 2 has exactly` → `Extending MetapixelTestCase is a YAGNI choice — the suite has exactly`.
  - Line 39 — `Revisit at v2.1 when the first real third-party adapter ships.` → `Revisit when first third-party adapter ships.`.
- `classes/adapter/shopaholic/ShopaholicCartPositionAdapter.php` line 70 — `Phase 4 cookie middleware sets them at the` → `the cookie middleware sets them at the`.
- `classes/adapter/shopaholic/ShopaholicSettingsOptions.php` line 38 — `(NOK, EUR, USD, GBP). Extend at Phase 4 if operator demand surfaces.` → `(NOK, EUR, USD, GBP). Extend when operator demand surfaces.`.

Verification: `grep -rnE 'Phase\s+[0-9]' Plugin.php classes/` returns 0 hits. `grep -rnE 'legacy/v1|v1\.' Plugin.php classes/` returns 0 hits. Behavior unchanged (pure docblock/comment edit; Tiger-Style invariant T-05-11-02 mitigation).

## Task 2 — Planning-doc prose rewrites (commit `44c79dd`)

D-23 strict verify gate requires zero `v1.1.1` substring AND zero `legacy/v1.1.1 branch preserved` literal across both `.planning/ROADMAP.md` and `.planning/REQUIREMENTS.md`. Achieved via the following prose rewrites (STATE.md untouched — orchestrator owns tracking blocks per system reminder):

### REQUIREMENTS.md
- Line 7 — Build philosophy header: dropped `legacy/v1.1.1 branch indefinitely` clause.
- Line 14 — TOOL-02: dropped `v1.x source on \`legacy/v1.1.1\` branch` clause.
- Line 111 — MKT-03: `CHANGELOG.md documenting v2.0.0 changes vs v1.x legacy branch.` → `CHANGELOG.md documenting the v2.0.0 initial public release.`
- Line 112 — MKT-04: `Plugin git tag v2.0.0 annotated. Pushed to remote. v1.1.1 + legacy/v1.1.1 branch preserved (operator may stay on legacy indefinitely).` → `Plugin git tag v2.0.0 annotated. Pushed to remote.`
- Line 150 — Out-of-Scope row "Backward-compat migration from v1.x": Reason column `Operators stay on \`legacy/v1.1.1\` branch indefinitely. Fresh installs only.` → `Fresh installs only.`
- Lines 11/27/45/55/65/76/85/91/97/101/107 — 11 category headers stripped of `(Phase N — …)` parentheticals (e.g. `### Tooling (Phase 1 — composer.json + namespace rename + CI)` → `### Tooling`).

### ROADMAP.md
- Line 9 — Numbering: `v1.x phases archived under \`.planning/archive/v1.1.1/phases/\`` → `prior-milestone phases archived under \`.planning/archive/\``.
- Line 11 — Build philosophy: dropped `legacy/v1.1.1 branch indefinitely` + `NOT v1.x ports` clauses.
- Lines 255-256 — Phase 5 Success Criteria 4 + 5: dropped `vs \`legacy/v1.1.1\` branch` (criterion 4) + dropped `legacy/v1.1.1 branch preserved on origin (operator may stay on legacy indefinitely — no BC shim, no upgrade migration in v2.0)` tail (criterion 5).
- Line 294 — Pitfall map suffix: `Operators stay on \`legacy/v1.1.1\` branch.` → `Fresh installs only.`
- Line 308 — Shipped Milestones row: rewrote to `Prior milestone` label + dropped explicit `v1.1.1` link references + pointed to archive directory.

### Verification gate (Task 2 verify command)

```text
REQ-v1.1.1=0 ROAD-v1.1.1=0 legacy-branch-preserved=0 MKT-03-old=0
ok-all-clean
```

All four assertions pass. Strict D-23 hygiene achieved.

### Out-of-scope (left intact, per design)

The strings `v1.x` and `Backward-compat migration from v1.x` remain in narrative scope strings (e.g. `Reuses v1.x DECISIONS`, the Out-of-Scope row label that the plan explicitly says to keep, plan-name references like `05-11-PLAN.md — v1.x reference strip`). These are historical-context refs documenting project history; they do not violate the strict verify gate (which targets `v1\.1\.1`) and are out of scope of the NoV1xReferencesTest test surface (which targets Plugin.php + classes/ + lang/ — `.planning/` is not asserted, per the plan's `key_links` contract).

## Task 3 — D-23 gate test (commit `7e42a72`)

Created `tests/Feature/Docs/NoV1xReferencesTest.php` with 5 PHPUnit-classic-style test methods extending `MetapixelTestCase`, anchored to the LangKeyCoverageTest hermetic file-load pattern:

1. `test_plugin_php_has_no_phase_n_decorators` — `assertDoesNotMatchRegularExpression('/Phase\s+[0-9]/', Plugin.php contents)`.
2. `test_plugin_php_has_no_legacy_v1_references` — `assertDoesNotMatchRegularExpression('/legacy\/v1/', Plugin.php contents)`.
3. `test_classes_dir_has_no_phase_n_decorators` — `RecursiveIteratorIterator` scan of every `*.php` under `classes/`, per-file `assertDoesNotMatchRegularExpression`.
4. `test_lang_en_has_no_v1x_references` — flatten `lang/en/lang.php` leaf string values, `assertStringNotContainsString('v1.' / 'legacy/v1')` per leaf.
5. `test_lang_lv_has_no_v1x_references` — symmetric on `lang/lv/lang.php`.

Manual gate simulation against the post-strip tree (the worktree has no `vendor/`, so `vendor/bin/pest` cannot run here — see "Deferred verification" below):

```text
=== test_plugin_php_has_no_phase_n_decorators ===     PASS
=== test_plugin_php_has_no_legacy_v1_references ===   PASS
=== test_classes_dir_has_no_phase_n_decorators ===    PASS
=== test_lang_en_has_no_v1x_references ===            PASS
=== test_lang_lv_has_no_v1x_references ===            PASS
=== syntax check NoV1xReferencesTest.php ===          No syntax errors detected
```

## Deferred verification

The Task 1 + Task 3 verify commands include `vendor/bin/pest --filter=…`. The worktree at `.claude/worktrees/agent-a14d6efd6555bce94/` carries no `vendor/` directory (no composer install was run for this worktree). The full test-suite run is deferred to the merged trunk where `vendor/` is present:

- Task 1 — `vendor/bin/pest --filter='PluginSanity|SendCapiEventBranchCoverage|ShopaholicCartPosition'` deferred. Justification: the strip is pure docblock/comment text — no behavior change is mechanically possible. Threat T-05-11-02 (strip accidentally removes load-bearing code) is mitigated by inspection: each edit was verified line-by-line to remove only narrative text, leaving every executable token + PHP signature intact.
- Task 3 — `vendor/bin/pest --filter='NoV1xReferencesTest'` deferred to merged trunk. Manual gate simulation (above) confirms all 5 assertions PASS against the current source tree. PHP syntax verified clean via `php -l`.

## Deviations from Plan

None — plan executed exactly per the locked PATTERNS rewrite tables. The plan's Task 2 `done` block carried a soft note that the ROADMAP Shipped Milestones row "may remain" with `legacy/v1.1.1` references; however the plan's strict automated verify command requires zero `v1.1.1` substring across both planning files. The strict verify gate is the contract this plan must satisfy, so the Shipped Milestones row was rewritten to a generic "Prior milestone" label that drops the v1.1.1 substring while pointing to the archive directory `.planning/archive/` (and the archived files at `.planning/milestones/v1.1.1-{ROADMAP,REQUIREMENTS}.md` remain in place per D-24 — they are not deleted, only the inbound link's label is genericized). This is consistent with the plan's stated D-23 goal: "a reader of the public repo finds no trace of v1.x."

## Known Stubs

None.

## Threat Flags

None — Task 1 + Task 2 are pure prose/docblock edits with zero new code paths. Task 3 introduces only a test file that reads disk-resident files via PHP's standard SPL iterators; no network, no DB, no user-input boundary, no new trust-boundary surface.

## TDD Gate Compliance

The plan is `type: execute` (not `type: tdd`). Per the v2.0 build philosophy + tdd guide, the D-23 gate test ships **as documentation gate** rather than RED-then-GREEN behavior development. The gate's role is to **lock in** the post-strip state and prevent regression — it was authored to pass against the freshly-stripped tree, which is the desired post-condition. No RED commit is required because the gate is a regression-prevention assertion, not a feature spec.

## Self-Check: PASSED

- [x] Plugin.php — present, stripped. Hash `git log -1 --pretty=%h Plugin.php` = `ea94c6e`.
- [x] classes/queue/SendCapiEvent.php — present, stripped. `git log -1 --pretty=%h` = `ea94c6e`.
- [x] classes/testing/EventSubjectAdapterContractTestCase.php — present, stripped. `git log -1 --pretty=%h` = `ea94c6e`.
- [x] classes/adapter/shopaholic/ShopaholicCartPositionAdapter.php — present, stripped. `git log -1 --pretty=%h` = `ea94c6e`.
- [x] classes/adapter/shopaholic/ShopaholicSettingsOptions.php — present, stripped. `git log -1 --pretty=%h` = `ea94c6e`.
- [x] .planning/ROADMAP.md — modified per D-23. `git log -1 --pretty=%h` = `44c79dd`.
- [x] .planning/REQUIREMENTS.md — modified per D-23. `git log -1 --pretty=%h` = `44c79dd`.
- [x] tests/Feature/Docs/NoV1xReferencesTest.php — created, 149 lines, syntax-clean. `git log -1 --pretty=%h` = `7e42a72`.
- [x] All 3 commits exist on `worktree-agent-a14d6efd6555bce94`: `ea94c6e`, `44c79dd`, `7e42a72`.
- [x] STATE.md untouched (orchestrator owns).
- [x] ROADMAP.md progress table at lines 297-304 untouched (orchestrator owns).
