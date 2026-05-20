---
phase: 1
slug: tooling-composer-namespace-rename-ci-matrix
status: approved
nyquist_compliant: false
wave_0_complete: true
created: 2026-05-20
mode: reconstruct  # State B — VERIFICATION.md + 3 SUMMARYs existed; no prior VALIDATION.md
---

# Phase 1 — Validation Strategy

> Retroactive Nyquist validation contract reconstructed from existing PLAN / SUMMARY / VERIFICATION artifacts.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 4 (PHPUnit 12 transport) + PHPStan 10 + Pint + PHPMD + Rector (informational) + composer-dependency-analyser |
| **Config file** | `phpunit.xml` (Pest), `phpstan.neon`, `pint.json`, `phpmd.xml`, `rector.php`, `composer-dependency-analyser.php` |
| **Quick run command** | `cd plugins/logingrupa/metapixel && composer test -- --filter={TestName}` |
| **Full suite command** | `cd plugins/logingrupa/metapixel && composer qa` |
| **Estimated runtime** | ~45 seconds (qa full); ~5 seconds (focused filter) |

---

## Sampling Rate

- **After every task commit:** focused Pest filter (`composer test -- --filter=ComposerJsonShapeTest`)
- **After every plan wave:** `composer qa` (pint-test → phpstan analyse → phpmd → test-cov)
- **Before `/gsd:verify-work`:** `composer qa` exit 0, coverage ≥ 90 %
- **Max feedback latency:** 45 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 01-01-05 | 01 | 1 | TOOL-01 | T-01-03 | `composer.json` declares `name: logingrupa/oc-metapixel-plugin`, PHP `^8.3 \|\| ^8.4`, PSR-4 root + tests, lovata cart plugins in BOTH `suggest:` (operator hint) AND `require-dev:` (test suite exercise post-43351ca), `license: proprietary` | unit | `composer test -- --filter=ComposerJsonShapeTest` | ✅ | ✅ green |
| 01-01-02 | 01 | 1 | TOOL-02 | — | Plugin dir renamed `metapixelshopaholic/` → `metapixel/`; PluginManager identifier locks to `Logingrupa.Metapixel`; namespace + lang keys load via PSR-4 from new root | unit (transitive) | `composer test -- --filter=PluginSanityTest` | ✅ | ✅ green |
| 01-01-03 | 01 | 1 | TOOL-03 | T-01-01 | Namespace rewrite to `Logingrupa\Metapixel` everywhere; lang namespace `logingrupa.metapixel::`; zero stale `Metapixelshopaholic` references outside `.planning/` | unit (transitive) | `composer test -- --filter=PluginSanityTest` | ✅ | ✅ green |
| 01-02-01 | 02 | 1 | TOOL-04 | T-01-06 | `phpstan.neon`: level 10, phpVersion 80300, `disallowedAttributes` bans `Deprecated` (post-43351ca; previous `disallowedClasses` key was inert), `disallowedFunctionCalls` bans 4 PHP-8.4 functions (`array_find`, `array_find_key`, `array_any`, `array_all`) + `assert()`, `disallowedMethodCalls` scopes `SiteManager` / `Site` / `Request` to `classes/queue`, `classes/event`, `classes/adapter` paths | unit | `composer test -- --filter=PhpstanConfigShapeTest` | ✅ | ✅ green |
| 01-02-02 | 02 | 1 | TOOL-05 | — | `rector.php`: `withPhpSets(php83: true)`, no `php84` set, 4 prepared sets (deadCode, codeQuality, typeDeclarations, earlyReturn) | unit | `composer test -- --filter=RectorConfigShapeTest` | ✅ | ✅ green |
| 01-02-03 | 02 | 1 | TOOL-06 | — | `pint.json`: Laravel preset + `nullable_type_declaration_for_default_null_value` + ordering + single quote + binary_operator_spaces single_space + excludes `updates/`; `pint --test` exits 0 | CI gate | `composer pint-test` | ✅ | ✅ green |
| 01-02-04 | 02 | 1 | TOOL-07 | — | `phpmd.xml`: Lovata.Toolbox baseline; `ShortVariable min=4`, `LongVariable max=40`, `CyclomaticComplexity reportLevel=10`, `ExcessiveClassLength minimum=1000`; phpmd exits 0 | CI gate | `composer phpmd` | ✅ | ✅ green |
| 01-03-02 | 03 | 1 | TOOL-08 | — | Two-tier Pest 4 test bases: `MetapixelTestCase` (no cart deps, hermetic SQLite, `autoMigrate=false`, `autoRegister=false`) + `ShopaholicAdapterTestCase extends MetapixelTestCase` (opt-in `bootOrdersTable` + `bootOrdersStatuses` helpers); each tier runs in isolation | unit | `composer test -- --filter=PluginSanityTest` | ✅ | ✅ green |
| 01-03-06 | 03 | 1 | TOOL-09 | T-01-04 | `.github/workflows/metapixel-qa.yml`: 4-cell matrix `php: [8.3, 8.4]` × `install: [full-lovata, minimal]`; Run A invokes `--coverage --min=90`; Run B invokes `--exclude-group=adapter` (matches `#[Group('adapter')]` class attributes per CLAUDE.md); no stale `'Metapixel Adapter Tests'` testsuite reference | unit | `composer test -- --filter=CiWorkflowMatrixTest` | ✅ | ❌ **red — BLOCKER active (YAML uses stale `--exclude-testsuite='Metapixel Adapter Tests'`)** |
| 01-02-06 | 02 | 1 | TOOL-10 | — | `composer.json` `scripts.qa` chain: pint-test → analyse → phpmd → test-cov, each delegating to correct binary with correct flags | unit | `composer test -- --filter=ComposerQaChainTest` | ✅ | ✅ green |
| 01-02-05 | 02 | 1 | TOOL-11 | T-01-05 | `composer-dependency-analyser.php`: path-scoped allowlist for `lovata/shopaholic-plugin` + `lovata/ordersshopaholic-plugin` + `lovata/buddies-plugin` covering `classes/adapter/shopaholic` AND `classes/event/adapter/shopaholic` paths; no bare `ignoreErrorsOnPackage` globally suppressing lovata (post-43351ca); `Plugin.php` allowlist gap surfaced for engineer decision | unit | `composer test -- --filter=ComposerDependencyAnalyserScopeTest` | ✅ | ❌ **red — WARNING active (Plugin.php top-level Lovata imports outside allowlist)** |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

All test infrastructure pre-existed before retroactive validation:

- ✅ `tests/MetapixelTestCase.php` — base class (no cart deps, hermetic SQLite)
- ✅ `tests/ShopaholicAdapterTestCase.php` — opt-in Lovata Orders boot helpers
- ✅ `tests/Pest.php` — uses() bindings for both bases
- ✅ `tests/Unit/PluginSanityTest.php` — smoke test (PSR-4 autoload + lang keys + boot/register callable)
- ✅ `phpunit.xml` — Pest 4 config
- ✅ `phpstan.neon` + `pint.json` + `phpmd.xml` + `rector.php` + `composer-dependency-analyser.php` — tooling configs

Retroactive tests written by /gsd:validate-phase 1 (2026-05-20):

- ✅ `tests/Unit/Tooling/ComposerJsonShapeTest.php` — TOOL-01 shape lock
- ✅ `tests/Unit/Tooling/PhpstanConfigShapeTest.php` — TOOL-04 ban shape lock
- ✅ `tests/Unit/Tooling/RectorConfigShapeTest.php` — TOOL-05 php83-only lock
- ✅ `tests/Unit/Tooling/CiWorkflowMatrixTest.php` — TOOL-09 matrix + exclude-group correctness (fails today; surfaces BLOCKER)
- ✅ `tests/Unit/Tooling/ComposerQaChainTest.php` — TOOL-10 qa chain shape lock
- ✅ `tests/Unit/Tooling/ComposerDependencyAnalyserScopeTest.php` — TOOL-11 path-scoped allowlist lock (Plugin.php sub-assertion fails today; surfaces WARNING)

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| CI matrix 4 cells turn green on rename PR | TOOL-09 | Requires real GitHub Actions run against `origin`; cannot fire from local | Push rename PR → observe Actions tab → confirm Run A (full-lovata × 8.3+8.4) green with coverage ≥90, Run B (minimal × 8.3+8.4) green without coverage gate AND with adapter group properly excluded |
| `composer install` on a fresh clone (October-private packages resolved via host vendor) | TOOL-01 + TOOL-10 | Standalone `composer install` blocked by `october/system` + `lovata/toolbox-plugin` not on packagist; only resolvable inside CI/Forge with host-vendor reach | Run `composer install` inside CI cell after host vendor populated → confirm exit 0 + lockfile parity |

---

## Validation Sign-Off

- [x] All TOOL-* requirements have `<automated>` verify (Pest test) OR CI-gate verify (pint / phpmd / phpstan / composer-dependency-analyser binary)
- [x] Sampling continuity: 11/11 TOOL-* requirements have automated checks; no 3-consecutive gap
- [x] Wave 0 covers all MISSING references — 6 retroactive tests written for previously CI-gate-only requirements
- [x] No watch-mode flags
- [x] Feedback latency < 45 s
- [ ] **`nyquist_compliant: true` NOT set** — TOOL-09 + TOOL-11 sub-assertion still red. Two BLOCKER/WARNING items from milestone audit confirmed by these tests:
  - **TOOL-09:** fix `.github/workflows/metapixel-qa.yml:99` → `--exclude-group=adapter`
  - **TOOL-11:** decide on `Plugin.php` allowlist entry OR fully-qualify Lovata cart class references in Plugin.php

**Approval:** approved 2026-05-20 (retroactive — Phase 1 originally executed without VALIDATION.md; this file backfills the contract; two assertions left red are deliberate to surface live production issues)

---

## Validation Audit 2026-05-20

| Metric | Count |
|--------|-------|
| Gaps found | 6 |
| Resolved (green automated tests) | 4 (TOOL-01, TOOL-04, TOOL-05, TOOL-10) |
| Escalated (red automated tests surfacing production drift) | 2 (TOOL-09, TOOL-11) |

Escalated items require production-code fix (not test-code) — engineer addresses YAML + analyser config in a follow-up commit. After fix, both tests pass and frontmatter flips to `nyquist_compliant: true`.
