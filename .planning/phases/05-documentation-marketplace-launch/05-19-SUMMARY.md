---
phase: 05-documentation-marketplace-launch
plan: 19
subsystem: docs
tags: [readme, install, docs-01, gap-closure, marketplace-launch]
requires:
  - README.md (existing marketplace surface from 05-09)
  - tests/Feature/Docs/ReadmeStructureTest.php (existing gate from 05-09)
provides:
  - README fresh-install-executable Install section (project:set + -W documented)
  - README ordered quick-start box (zero to first Meta Test Events hit)
  - ReadmeStructureTest install-fidelity + quick-start assertions
affects:
  - Launch Milestone timed clean-room dry-run (dead-end removed)
tech-stack:
  added: []
  patterns:
    - Doc-gate lock via ReadmeStructureTest substring assertions (T-05-19-02 mitigation)
key-files:
  created: []
  modified:
    - README.md
    - tests/Feature/Docs/ReadmeStructureTest.php
decisions:
  - "D1 wording fix: README uses 'Meta Events Manager' matching the live Meta UI and lang/en pixel_id_comment; zero 'Meta Business Manager' remain."
  - "Quick-start shipped as H3 under Install (not a new H2) so the 7-section regex gate stays intact."
  - "project:set documented as `<license>` placeholder token — never a real key (T-05-19-01 mitigation)."
metrics:
  duration: ~6 min
  completed: 2026-07-03
  tasks: 2
  files: 2
status: complete
---

# Phase 5 Plan 19: README Fresh-Install Fidelity Summary

Closed Gap 1 (UAT test 7, SC1/DOCS-01): the README install path is now executable end-to-end on a genuinely fresh OctoberCMS 4.x app. Two undocumented prerequisites that dead-ended a clean-room buyer — the `-W` require flag and the `php artisan project:set <license>` gateway registration — are now documented with one-line justifications, the Business→Events Manager wording is corrected, and a single ordered quick-start box reaches the first Meta Test Events hit. The ReadmeStructure gate locks all of it GREEN.

## What Shipped

**Task 1 — Install section corrected for fresh-install reality (`4867fb5`)**
- Added a `php artisan project:set <license>` prerequisite step BEFORE the require command, with a one-line why: it registers the authenticated `gateway.octobercms.com` repository so `october/system` and the `lovata/*` backbone resolve on a fresh install.
- Changed the require command to `composer require logingrupa/oc-metapixel-plugin -W`, with a one-line why for `-W`: a fresh October lockfile pins `composer/installers` at the ~1.0 line that `lovata/toolbox-plugin ^2.2` must move.
- Kept the VCS `repositories` JSON block and `php artisan october:up` exactly as-is (both asserted by the gate).
- Changed both user-facing "Meta Business Manager" references to "Meta Events Manager" (D1) without renaming the `## Acquire Meta credentials` H2.

**Task 2 — Ordered quick-start box + gate lock (`ca1ed9d`)**
- Added an H3 "Quick start — first event in 10 minutes" box directly after the Install section (H3, not a new H2, so the 7-section regex is unaffected): 7 ordered steps from adding the VCS entry through `project:set`, `require -W`, `october:up`, the four required Settings fields, mounting `pixelHead`, to confirming the hit in Meta Test Events.
- Extended `ReadmeStructureTest` with `test_readme_install_documents_fresh_install_prerequisites` (locks `php artisan project:set` + `oc-metapixel-plugin -W`) and `test_readme_ships_ordered_quick_start` (locks `Quick start`, `project:set`, `Test Events`).

## Verification

- `../../../vendor/bin/pest --configuration phpunit.xml --filter=ReadmeStructure` — GREEN, 8 passed (21 assertions): 6 prior + 2 new methods.
- Baseline was 6 passed (16 assertions) before this plan — no prior assertion regressed.
- `pint --test` GREEN on the changed test file.
- phpstan does not scan `tests/` (paths cover `classes/`, `models/`, `Plugin.php`); phpmd scans source only. No runtime code touched — the diff is README.md + ReadmeStructureTest.php exclusively, so the `composer qa` chain behaviour is unchanged.
- Manual carry-forward (not automatable here — no fresh October sandbox in this environment): the stopwatched < 10-minute clean-room dry-run remains an operator/Launch-Milestone acceptance step. This plan removes the documented dead-end that blocked it.

## Threat Mitigations Applied

- **T-05-19-01** (info disclosure): `project:set` is documented with a `<license>` placeholder token — no real license key embedded in the README.
- **T-05-19-02** (doc gate drift): the two install-fidelity assertions fail the build if the install prose regresses.

## Deviations from Plan

None - plan executed exactly as written.

## Known Stubs

None.

## Self-Check: PASSED
- README.md — FOUND (modified, 2 commits)
- tests/Feature/Docs/ReadmeStructureTest.php — FOUND (modified)
- Commit 4867fb5 — FOUND
- Commit ca1ed9d — FOUND
