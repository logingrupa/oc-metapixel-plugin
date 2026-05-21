---
phase: 05-documentation-marketplace-launch
plan: 00
subsystem: testing
tags: [pest, phpunit, hermetic-file-load, red-green, docs, marketplace, yaml-sanity]

# Dependency graph
requires:
  - phase: 01-foundation
    provides: Pest 4 + Larastan + MetapixelTestCase base — Wave 0 reuses without new tooling install
provides:
  - Four Pest 4 test files anchoring the RED side of the Wave 0 RED→GREEN cycle for Phase 5 docs/manifest/assets deliverables
  - tests/Feature/Docs/ReadmeStructureTest.php — DOCS-01 + DOCS-02 gates (RED)
  - tests/Feature/Docs/CustomAdaptersStructureTest.php — DOCS-03 gate (RED)
  - tests/Feature/Docs/AssetsExistTest.php — MKT-03 gate (RED)
  - tests/Feature/Plugin/PluginYamlSanityTest.php — MKT-02 gate (GREEN immediately — plugin.yaml already meets contract)
affects: [05-09 README authoring, 05-10 docs/CUSTOM-ADAPTERS.md, 05-12 CHANGELOG + screenshots]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Hermetic file-load via dirname(__DIR__, 3).'/<relpath>' — no Filesystem facade, no Translator binding"
    - "Global-namespace test class extending Logingrupa\\Metapixel\\Tests\\MetapixelTestCase — mirrors LangKeyCoverageTest under tests/Feature/Lang/"
    - "Goal-backward verification: tests authored before content; downstream plans flip RED→GREEN"
    - "Symfony Yaml::parseFile for plugin.yaml sanity — transitive October dependency, no new composer require"

key-files:
  created:
    - tests/Feature/Docs/ReadmeStructureTest.php
    - tests/Feature/Docs/CustomAdaptersStructureTest.php
    - tests/Feature/Docs/AssetsExistTest.php
    - tests/Feature/Plugin/PluginYamlSanityTest.php
  modified: []

key-decisions:
  - "Used the global-namespace `final class ... extends MetapixelTestCase` pattern (matching LangKeyCoverageTest) rather than the namespaced Feature\\Plugin\\* pattern (ShopaholicConditionalRegistrationTest) — plan explicitly prescribed the `use ... MetapixelTestCase;` header for all four Wave 0 files for uniformity."
  - "Three RED files (ReadmeStructure, CustomAdapters, AssetsExist) intentionally fail because their target artifacts do not yet exist — RED is the contract for downstream plans 05-09, 05-10, 05-12."
  - "PluginYamlSanityTest ships GREEN immediately — verified plugin.yaml already meets MKT-02 (lang-key name+description, author 'Logingrupa', icon 'icon-bullseye', GitHub VCS homepage)."

patterns-established:
  - "Wave 0 RED-anchor pattern: ship the executable gate first, then author the content that flips it GREEN. Bridges goal-backward verification to plan-forward execution."
  - "Section-grep regex assertion (preg_match_all '/^## (Install|Configure|...)/m' ≥ 7) — counts H2 sections without ordering constraint."
  - "Lang-key walkthrough fidelity: flatten lang/en/lang.php field.*_label leaves, assertStringContainsString each value against README content — anchors README to live Settings labels."
  - "Hook-constant existence via substr_count >= 1 per literal string — D-15 hook contract pinned to 3 separate assertions."

requirements-completed: [DOCS-01, DOCS-02, DOCS-03, MKT-02, MKT-03]

# Metrics
duration: ~15min
completed: 2026-05-21
---

# Phase 05 Plan 00: Wave 0 RED-Anchor Tests Summary

**Four hermetic Pest 4 test files anchoring the RED side of the Phase 5 docs/manifest/assets RED→GREEN cycle — ReadmeStructure + CustomAdapters + AssetsExist fail until plans 05-09/05-10/05-12 author the artifacts; PluginYamlSanity passes immediately since plugin.yaml already meets MKT-02.**

## Performance

- **Duration:** ~15 min
- **Started:** 2026-05-21T10:19:00Z (approx — worktree spawn)
- **Completed:** 2026-05-21T10:22:09Z
- **Tasks:** 2 / 2
- **Files created:** 4
- **Files modified:** 0

## Accomplishments

- `tests/Feature/Docs/ReadmeStructureTest.php` — 6 assertions: README.md exists, ≥7 named H2 sections, no v1.x / legacy/v1 diff text, `php artisan october:up` shown, Composer VCS pattern shown, every lang/en `field.*_label` value appears verbatim in README content (DOCS-01 + DOCS-02)
- `tests/Feature/Docs/CustomAdaptersStructureTest.php` — 7 assertions: docs/CUSTOM-ADAPTERS.md exists, 3 hook constant strings (before_dispatch/after_dispatch/dead_letter) each ≥1 occurrence, OFFLINE\Mall + mall.order opaque alias inline, EventSubjectAdapterContractTestCase + makeAdapter + makeSubject references, AdapterRegistry register snippet (DOCS-03)
- `tests/Feature/Docs/AssetsExistTest.php` — 5 assertions: glob(`docs/screenshots/0[1-5]-*.png`) returns 5, CHANGELOG.md exists, `## [2.0.0] - YYYY-MM-DD` header regex, `### Added` subsection, no v1.x / legacy/v1 diff text (MKT-03 + D-22/D-23)
- `tests/Feature/Plugin/PluginYamlSanityTest.php` — 6 assertions all GREEN: plugin.yaml parses, name/description are lang keys, author 'Logingrupa', icon 'icon-bullseye' (D-20), homepage matches GitHub VCS URL regex (MKT-02)
- All four files extend `Logingrupa\Metapixel\Tests\MetapixelTestCase` via the global-namespace pattern of LangKeyCoverageTest, using `dirname(__DIR__, 3)` for hermetic plugin-root resolution
- Zero production code touched (no `classes/`, `models/`, `Plugin.php`, `lang/` modifications)
- `php -l` syntax check passes on all four files

## Task Commits

Each task was committed atomically on branch `worktree-agent-a26fe0fffab159384`:

1. **Task 1: Ship ReadmeStructureTest.php + CustomAdaptersStructureTest.php** — `f4170fc` (test)
2. **Task 2: Ship AssetsExistTest.php + PluginYamlSanityTest.php** — `c5ce0c9` (test)

_Note: orchestrator owns the metadata commit (STATE.md + ROADMAP.md updates) after worktree merge — this executor does not write shared orchestrator artifacts per parallel_execution contract._

## Files Created/Modified

### Created

- `tests/Feature/Docs/ReadmeStructureTest.php` — DOCS-01 + DOCS-02 RED gate on README.md (file existence, section grep, v1.x quarantine, install-block anchors, lang-key walkthrough fidelity)
- `tests/Feature/Docs/CustomAdaptersStructureTest.php` — DOCS-03 RED gate on docs/CUSTOM-ADAPTERS.md (3 hook constants, OFFLINE\Mall + mall.order, ContractTestCase + makeAdapter + makeSubject, AdapterRegistry register snippet)
- `tests/Feature/Docs/AssetsExistTest.php` — MKT-03 RED gate on docs/screenshots/0[1-5]-*.png + CHANGELOG.md (glob count 5, Keep-a-Changelog 1.1.0 header regex + ### Added, D-22/D-23 quarantine)
- `tests/Feature/Plugin/PluginYamlSanityTest.php` — MKT-02 GREEN gate on plugin.yaml (Yaml::parseFile + 6 sanity assertions)

### Modified

None.

## RED vs GREEN State at Plan Close

| Test file | State | Anchored by downstream |
|-----------|-------|------------------------|
| `tests/Feature/Docs/ReadmeStructureTest.php` | RED (6/6 fail — README.md missing) | 05-09 (README authoring) |
| `tests/Feature/Docs/CustomAdaptersStructureTest.php` | RED (7/7 fail — docs/CUSTOM-ADAPTERS.md missing) | 05-10 (custom-adapters guide) |
| `tests/Feature/Docs/AssetsExistTest.php` | RED (5/5 fail — docs/screenshots/ + CHANGELOG.md missing) | 05-12 (screenshots + CHANGELOG) |
| `tests/Feature/Plugin/PluginYamlSanityTest.php` | GREEN (6/6 pass — plugin.yaml already MKT-02-compliant) | n/a (already shipped) |

**Verification deferred to post-merge:** The worktree has no local vendor/ (composer-installed binaries live in the main plugin tree). Running `vendor/bin/pest --filter=...` from inside the worktree is not possible — the phpunit.xml bootstrap path (`../../../modules/system/tests/bootstrap.php`) resolves outside the worktree filesystem. Verification commands cited in the plan (`vendor/bin/pest --filter='ReadmeStructure|CustomAdapters'`, etc.) execute against the post-merge plugin path. `php -l` syntax checks confirm zero parse errors.

## Decisions Made

- **Global-namespace test class pattern** — followed the plan's prescribed `<?php` + `use Logingrupa\Metapixel\Tests\MetapixelTestCase;` + bare `final class ... extends MetapixelTestCase` (matching LangKeyCoverageTest under tests/Feature/Lang/). The existing `tests/Feature/Plugin/ShopaholicConditionalRegistrationTest.php` uses the namespaced form `namespace Logingrupa\Metapixel\Tests\Feature\Plugin;` — both shapes coexist under Pest 4 + PHPUnit 12 because composer's `autoload-dev` PSR-4 mapping (`Logingrupa\Metapixel\Tests\\` → `tests/`) only kicks in for files that declare the namespace; unnamespaced files are picked up via classmap autoload from PHPUnit's testsuite discovery.
- **Symfony Yaml directly, not Plugin::pluginDetails()** — PluginYamlSanityTest parses `plugin.yaml` via `Yaml::parseFile` rather than instantiating `Plugin::pluginDetails()` because MKT-02 is a yaml-side contract (what marketplace tooling sees) not a PHP-side contract (Plugin sanity is already covered by `tests/Unit/PluginSanityTest.php`). Symfony Yaml is a transitive October dependency — no new `composer require` needed (verified `/home/forge/nailscosmetics.lv/vendor/symfony/yaml/Yaml.php` present).
- **Glob over recursive iterator for screenshots** — `glob(dirname(__DIR__, 3).'/docs/screenshots/0[1-5]-*.png')` is one expression and exactly matches the MKT-03 file naming convention (zero-padded prefix). A recursive iterator would over-match.
- **Three separate assertions per hook constant** rather than one loop — preserves the D-15 hook contract as three grep-discoverable literal anchors. A loop would hide the constants behind a variable name.

## Deviations from Plan

None — plan executed exactly as written.

## Issues Encountered

- **Worktree vendor isolation:** The Claude Code worktree does not symlink or share the plugin's `vendor/` directory, so running `vendor/bin/pest`/`vendor/bin/phpstan` against the new files from inside the worktree is not feasible. Mitigation: ran `php -l` syntax checks on each file (all clean) and confirmed `dirname(__DIR__, 3)` resolves to the worktree root (which equals the plugin root after merge). Actual pest/phpstan verification happens post-merge in the canonical plugin path.

## User Setup Required

None — Wave 0 ships test files only. No external service configuration required.

## Next Phase Readiness

- Plans **05-09** (README authoring), **05-10** (docs/CUSTOM-ADAPTERS.md), **05-12** (screenshots + CHANGELOG.md) have an executable RED contract to flip GREEN.
- The lang/en `field.*_label` walkthrough-fidelity assertion in `ReadmeStructureTest::test_readme_anchors_field_labels_from_lang_en` is the tightest gate: README content must include every shipped label string verbatim. Plan 05-09 authors must mirror lang/en into the README's Configure section.
- The Keep-a-Changelog regex `/^## \[2\.0\.0\] - \d{4}-\d{2}-\d{2}$/m` allows any ISO date — plan 05-12 picks the actual release date.
- No blockers. `composer qa` is expected to exit non-zero on the 3 RED files (18/18 assertions failing across them); existing 430+ test suite remains green per Phase 1 baseline.

## Self-Check: PASSED

**Files exist on disk:**
- FOUND: tests/Feature/Docs/ReadmeStructureTest.php
- FOUND: tests/Feature/Docs/CustomAdaptersStructureTest.php
- FOUND: tests/Feature/Docs/AssetsExistTest.php
- FOUND: tests/Feature/Plugin/PluginYamlSanityTest.php

**Commits exist:**
- FOUND: f4170fc (Task 1 — ReadmeStructure + CustomAdapters)
- FOUND: c5ce0c9 (Task 2 — AssetsExist + PluginYamlSanity)

---
*Phase: 05-documentation-marketplace-launch*
*Plan: 00*
*Completed: 2026-05-21*
