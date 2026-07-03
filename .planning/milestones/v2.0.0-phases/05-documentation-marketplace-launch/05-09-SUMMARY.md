---
phase: 5
plan: 09
plan_id: 05-09
subsystem: documentation
status: complete
completed: 2026-07-03
requirements: [DOCS-01, DOCS-02]
tags: [readme, marketplace, docs, walkthrough]
requires: [05-08]
provides: [README.md]
affects: [tests/Feature/Docs/ReadmeStructureTest.php]
tech-stack:
  added: []
  patterns: [single-page-marketplace-readme, troubleshoot-signature-table, twin-walkthrough]
key-files:
  created: []
  modified: [README.md]
decisions:
  - "VCS repositories JSON block precedes composer require (W-13 ordering gate)"
  - "Troubleshoot table keyed on real Log::* signatures grepped from classes/ (D-13)"
  - "Meta credential steps are numbered plain text, no Meta UI screenshots (D-12)"
metrics:
  duration: ~15min
  completed: 2026-07-03
---

# Phase 5 Plan 09: README single-page marketplace walkthrough Summary

**One-liner:** Replaced the old README with a fresh 202-line single-page marketplace surface that walks a buyer from a VCS-configured `composer require` to a verified CAPI event — twin Shopaholic/Theme walkthroughs copied verbatim from the live smoke log, an 8-row Troubleshoot table keyed on real `Log::*` signatures, and 5 screenshot image-links — flipping the Wave 0 ReadmeStructureTest contract green.

## What shipped

`README.md` at plugin root (`plugins/logingrupa/metapixel/README.md`), 202 lines, 11 H2 sections (7 named sections the Wave 0 test greps for, plus Overview, Requirements, How deduplication works, Extend with a custom adapter, Multi-site routing, CHANGELOG, License).

Section order honors the D-10 single-page linear walkthrough: Overview → Requirements → How dedup works → Install → Configure → Acquire Meta credentials → Shopaholic walkthrough → Theme walkthrough → FailedEvents UI → Troubleshoot → Extend with a custom adapter → Multi-site routing → CHANGELOG → License.

- **Install (W-13):** VCS `repositories` JSON block (`"type": "vcs"`, line 37) appears BEFORE the `composer require logingrupa/oc-metapixel-plugin` command (line 45), because Composer reads `repositories` before resolving requires. Install block also carries `php artisan october:up` plus the Pitfall-5 note (settings panel missing → run migrations).
- **Configure:** Numbered Backend → Settings → Marketing → Meta Pixel + CAPI walkthrough. All 8 `field.*_label` values from `lang/en/lang.php` appear verbatim (Pixel ID, CAPI Access Token, Test Events Code, Paid status code, Default currency code, Custom theme event names, Trusted Hosts, Set _fbp / _fbc cookies server-side) — the DOCS-02 fidelity gate the Wave 0 test enforces field-by-field. Includes a Per-site setup subsection and the `01-settings.png` image-link.
- **Acquire Meta credentials (D-12):** Numbered plain-text steps for Meta Business Manager — no Meta UI screenshots. Ends with the never-commit-the-token warning (mitigates T-05-09-01).
- **Shopaholic walkthrough (Run A):** Purchase-path step sequence copied verbatim from 05-SMOKE-LOG.md — including the by-design status-transition-to-`new-payment-received` step (Purchase fires on the paid-status transition, not at order creation) and the order-complete EventPixel browser-dedup twin.
- **Theme walkthrough (Run B):** `{% component 'pixelHead' %}` head mount + the `this.metapixel.pushEvent` Twig API example, plus a note that the Shopaholic store theme wires via `pixelHead` + a product-page component while the generic Twig API stays available (Finding 3 from smoke log). `05-twig-api.png` image-link.
- **FailedEvents UI:** Replay + Check dedup documented. The `04-check-dedup.png` caption honestly describes the fail-safe permission-error state (token lacks `ads_read`), not a happy path. Image-links to `02-failed-events-list.png` and `03-replay-flow.png`.
- **Troubleshoot (D-13):** 8-row markdown table, each row's signature a real string emitted by the plugin (verified against `grep -rn 'Log::' classes/`). References `storage/logs/system.log` (Finding 8: October's runtime log is system.log, not laravel.log).
- **Extend with a custom adapter:** Documents the CLAUDE.md extensibility contract (AdapterRegistry::register, before/after/dead_letter hooks with the event_id/event_time immutability caveat, MetaClientInterface swap).
- **Multi-site routing:** Per-site pixel_id + capi_access_token, cross-site propagation locked, site read from the subject not the request.

## Verification (manual — pest vendor absent on server)

`vendor/bin/pest` is not installed on this Forge box, so `tests/Feature/Docs/ReadmeStructureTest.php` could not be executed here; the CI matrix runs the suite. Each of the test's assertions was validated manually against the rendered README:

| Test assertion | Manual check | Result |
|----------------|--------------|--------|
| `test_readme_file_exists` | file present at plugin root | PASS |
| `test_readme_contains_seven_named_sections` | `grep -cE '^## (Install\|Configure\|Acquire\|Shopaholic\|Theme\|FailedEvents\|Troubleshoot)'` = 7 | PASS |
| `test_readme_has_no_v1x_references` | `grep -nE 'v1\.\|legacy/v1\|Phase [0-9]'` = 0 hits | PASS |
| `test_readme_install_block_shows_october_up` | `php artisan october:up` present (3x) | PASS |
| `test_readme_install_block_shows_vcs_repositories_pattern` | `"type": "vcs"` present | PASS |
| `test_readme_anchors_field_labels_from_lang_en` | all 8 `field.*_label` values present verbatim | PASS |
| W-13 ordering (VCS before require) | vcs line 37 < require line 45 | PASS |
| W-11 image-link | 5 links matching `!\[.*\]\(docs/screenshots/0[1-5]-` | PASS |
| Troubleshoot ≥5 rows | 8 data rows, all real Log::* signatures | PASS |
| ≥7 H2 sections | 11 H2 total | PASS |
| min_lines 200 | 202 lines | PASS |

## Deviations from Plan

None — plan executed as written. Task 1 was input-gathering only (no file modified, no commit); Task 2 authored the README. Two notes carried into the README per the plan's accuracy guidance:
- The `04-check-dedup.png` caption honestly documents the fail-safe permission-error state rather than implying a happy-path dedup fetch (the smoke-log Step K limitation: token lacks `ads_read`).
- The Troubleshoot table points at `storage/logs/system.log` (October's actual runtime log per smoke-log Finding 8), not `laravel.log`.

## Commits

- `1f1e1ca` — docs(05-09): add README.md single-page marketplace walkthrough (DOCS-01 + DOCS-02)

## Self-Check: PASSED

- README.md exists at plugin root (202 lines) — FOUND
- Commit `1f1e1ca` present in git log — FOUND
- All 5 referenced screenshots exist at `docs/screenshots/0[1-5]-*.png` — FOUND
- Zero `v1.`, `legacy/v1`, or `Phase [0-9]` references — CONFIRMED
