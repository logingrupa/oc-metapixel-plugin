---
phase: 05-documentation-marketplace-launch
plan: 12
plan_id: 05-12
subsystem: marketplace
tags: [changelog, plugin-yaml, composer-keywords, mkt-02, mkt-03, d-22, d-23, keep-a-changelog]
requires:
  - phase: 05-11
    provides: D-23 lock enforced on Plugin.php + classes/ + lang/ + planning docs
provides:
  - CHANGELOG.md (fresh v2.0.0 entry, Keep-a-Changelog 1.1.0, zero v1.x diff text)
  - composer.json with keywords[] array for marketplace discoverability
  - plugin.yaml verified MKT-02-ready (no edit needed)
affects: [05-13, 05-14]
tech-stack:
  added: []
  patterns:
    - "CHANGELOG.md = fresh v2.0.0 initial release entry (D-22 lock — no v1.x diff history)"
key-files:
  created:
    - CHANGELOG.md
  modified:
    - composer.json
key-decisions:
  - "plugin.yaml unchanged — already matches D-20 (icon-bullseye, homepage GitHub VCS URL, author Logingrupa, name+description lang keys). PluginYamlSanityTest 6/6 GREEN pre-plan."
  - "CHANGELOG.md = single `## [2.0.0] - 2026-05-27` section with `### Added` subsection enumerating all v2.0 deliverables. No `### Changed`/`### Removed`/`### Fixed` subsections (initial release, nothing to diff against)."
  - "Zero v1.x diff text per D-22 lock; zero `legacy/v1` references per D-23 lock; AssetsExistTest enforces both via assertStringNotContainsString."
  - "composer.json keywords[] uses 9-item array: october-cms, october-plugin, meta-pixel, conversions-api, capi, facebook-pixel, shopaholic, tracking, analytics."
  - "License field stays `proprietary` — re-evaluation deferred to plan 05-14 (operator decision per plan frontmatter)."
  - "5 PNG screenshots NOT shipped this plan — plan 05-08 owns them (operator-shot, blocked on operator action). AssetsExistTest screenshots assertion stays RED until 05-08 closes; CHANGELOG + composer assertions flip GREEN."
patterns-established:
  - "Marketplace manifest = composer.json keywords[] + plugin.yaml metadata (verified by PluginYamlSanityTest) + CHANGELOG.md release notes"
requirements-completed: [MKT-02, MKT-03]
duration: ~30min
completed: 2026-05-27
---

# Phase 5 Plan 12 — CHANGELOG.md + composer keywords + plugin.yaml verify

**Marketplace manifest + release notes shipped; 4 of 5 AssetsExistTest assertions flipped GREEN.**

## Performance

- **Started:** 2026-05-27
- **Completed:** 2026-05-27
- **Duration:** ~30 min
- **Tasks:** 4/4
- **Files created:** 1 (CHANGELOG.md)
- **Files modified:** 1 (composer.json)
- **Files verified-no-edit:** 1 (plugin.yaml)

## Accomplishments

- Authored fresh `CHANGELOG.md` Keep-a-Changelog 1.1.0 — single `## [2.0.0] - 2026-05-27` section, `### Added` subsection enumerating 17 v2.0 deliverables (adapter pipeline, ShopaholicAdapter, ThemeActionAdapter, EventPixel, PixelHead, 3 Event::fire hooks, Multisite settings, TrustedHosts allowlist, EnsureFbpFbcCookies middleware, FailedEvents controller, PluginGuard, Graph API v23.0 pin, en/lv translations, docs/CUSTOM-ADAPTERS.md, composer qa toolchain, PHP 8.3+8.4 CI matrix).
- D-22 lock holds: zero v1.x diff text. D-23 lock holds: zero `legacy/v1` references.
- Added `keywords[]` array to `composer.json` (9 items) for marketplace discoverability.
- Verified `plugin.yaml` matches D-20 (icon-bullseye + homepage + author + lang keys) — PluginYamlSanityTest 6/6 GREEN unchanged.

## Task Commits

Single commit (all 3 files atomic per plan): see git log for the commit hash committed by the orchestrator after this plan closes.

## Files Created/Modified

- `CHANGELOG.md` — new, 26 lines (header + 1 release section + 17 Added bullets). Format: Keep a Changelog 1.1.0.
- `composer.json` — added `keywords[]` between `description` and `license` (9 entries).
- `plugin.yaml` — verified, no edit. Matches MKT-02 acceptance contract.

## Test Status

```
PluginYamlSanityTest: 6/6 GREEN (was GREEN, unchanged).
AssetsExistTest: 4/5 GREEN (was 0/5 RED).
  ✓ test_changelog_file_exists
  ✓ test_changelog_has_v2_section_header_with_iso_date
  ✓ test_changelog_has_added_subsection
  ✓ test_changelog_has_no_v1x_diff_text
  ✗ test_five_screenshots_present_with_padded_prefix (owned by plan 05-08 — operator-shot, still RED)
```

ReadmeStructureTest stays RED (6 assertions — owned by plan 05-09, operator-deferred per 05-UAT-CUTOVER.md).

## Cross-references

- **D-22 lock:** CHANGELOG.md fresh v2.0 surface (no v1.1.1 diff text).
- **D-23 lock:** No `legacy/v1` references anywhere in v2.0 public surface.
- **D-20:** plugin.yaml icon-bullseye + homepage URL canonical.

## Self-Check: PASSED

- [x] CHANGELOG.md ships at plugin root (`plugins/logingrupa/metapixel/CHANGELOG.md`)
- [x] `## [2.0.0] - 2026-05-27` header matches `/^## \[2\.0\.0\] - \d{4}-\d{2}-\d{2}$/m`
- [x] `### Added` subsection present
- [x] Zero `v1.1.1` substrings in CHANGELOG.md
- [x] Zero `legacy/v1` substrings in CHANGELOG.md
- [x] composer.json `keywords[]` array present with 9 marketplace-relevant entries
- [x] plugin.yaml unchanged — PluginYamlSanityTest 6/6 GREEN
- [x] Pest local run confirms 4 of 5 AssetsExistTest assertions flipped GREEN (screenshots remain operator-blocked per plan 05-08)
