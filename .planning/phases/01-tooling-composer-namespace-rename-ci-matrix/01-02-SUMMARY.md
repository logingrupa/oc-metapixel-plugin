---
phase: 01-tooling-composer-namespace-rename-ci-matrix
plan: 02
subsystem: tooling-quality-toolchain
tags: [tooling, phpstan, rector, pint, phpmd, composer-dependency-analyser, qa]
requires:
  - "01-01 (minimal scaffold with Plugin.php + composer.json + namespace Logingrupa\\Metapixel)"
provides:
  - "phpstan.neon — level 10 + phpVersion 80300 + larastan + spaze/phpstan-disallowed-calls banning PHP 8.4-only syntax (assert(), @, array_find, array_find_key, array_any, array_all, \\Deprecated attribute)"
  - "rector.php — withPhpSets(php83: true) + prepared sets (deadCode, codeQuality, typeDeclarations, earlyReturn)"
  - "pint.json — Laravel preset + nullable_type_declaration_for_default_null_value + ordered_imports alpha + no_unused_imports + single_quote + binary_operator_spaces single_space"
  - "phpmd.xml — Lovata.Toolbox-derived ruleset (CyclomaticComplexity reportLevel=10, ExcessiveClassLength minimum=1000, LongVariable max=40, ShortVariable min=4, ExcessiveClassComplexity max=50)"
  - "composer-dependency-analyser.php — shipmonk Configuration that allowlists Lovata cart imports only inside classes/adapter/shopaholic/ (P-03 prevention pre-wire for Phase 3)"
  - "composer.json scripts.qa chain: [@pint-test, @analyse, @phpmd, @test-cov]"
  - "composer.json require-dev with pestphp/pest ^4.1, larastan/larastan ^3.0, spaze/phpstan-disallowed-calls ^4.0, phpmd/phpmd ^2.15, laravel/pint ^1.26, rector/rector ^2.0, mockery/mockery ^1.6, shipmonk/composer-dependency-analyser ^1.8, phpunit/phpunit ^12, pestphp/pest-plugin-drift ^4.0"
  - "Plugin.php re-formatted by pint (single_line_empty_body collapses empty methods; binary_operator_spaces=single_space removes array-key alignment)"
affects:
  - "Future code under Plugin.php, classes/, models/, components/, middleware/, controllers/ — must pass phpstan level 10 + phpmd thresholds + pint rules at commit time"
  - "Phase 3 adapter directory: classes/adapter/shopaholic/ is pre-allowlisted for Lovata\\Shopaholic\\* + Lovata\\OrdersShopaholic\\* imports; everywhere else, dep-analyser flags as error"
  - "PHP 8.4-only syntax is rejected at static-analysis layer — P-06 prevention applies at commit time once tests/CI land in plan 01-03"
tech-stack:
  added:
    - "pestphp/pest ^4.1 (test runner)"
    - "pestphp/pest-plugin-drift ^4.0 (test drift detection)"
    - "phpunit/phpunit ^12 (Pest 4 underlying engine)"
    - "larastan/larastan ^3.0 (Laravel-aware PHPStan extension)"
    - "spaze/phpstan-disallowed-calls ^4.0 (function/class ban list extension)"
    - "phpmd/phpmd ^2.15 (mess detector)"
    - "laravel/pint ^1.26 (code formatter)"
    - "rector/rector ^2.0 (automated refactoring)"
    - "mockery/mockery ^1.6 (test doubles)"
    - "shipmonk/composer-dependency-analyser ^1.8 (composer hygiene)"
  patterns:
    - "Lovata.Toolbox PHPMD ruleset as canonical baseline (CyclomaticComplexity=10, ExcessiveClassLength=1000, NPathComplexity=200)"
    - "Composer scripts.qa fast-fail chain (formatting then types then complexity then tests)"
    - "Path-scoped composer-dependency-analyser allowlist via ignoreErrorsOnPackageAndPath (pre-wire for Phase 3 adapter dir)"
key-files:
  created:
    - "phpstan.neon"
    - "rector.php"
    - "pint.json"
    - "phpmd.xml"
    - "composer-dependency-analyser.php"
  modified:
    - "composer.json (require-dev populated, scripts.qa chain wired)"
    - "Plugin.php (pint auto-fix: empty bodies collapsed, array-key alignment removed)"
  deleted: []
decisions:
  - "Plan's Task 8 (parent repo composer.json edit) SKIPPED — plugin is standalone per Option-A path interpretation (locked in plan 01-01). All dev deps live in PLUGIN composer.json require-dev; host repo separately supplies analyser/test binaries via its own vendor at integration time."
  - "scripts.qa chain = [@pint-test, @analyse, @phpmd, @test-cov] — deps-check is a SEPARATE composer script (`composer deps`) NOT in qa chain. Rationale: aligns with executor override; TOOL-11 is satisfied by the config file's existence + the standalone script, not by qa-chain inclusion."
  - "Plugin scripts use plain command names (`pint`, `phpstan analyse`, `phpmd Plugin.php text phpmd.xml`, `pest`) — relies on composer's automatic vendor/bin PATH injection during `composer run-script`. Works once plugin's local vendor/ is populated."
  - "phpstan.neon includes use LOCAL `vendor/larastan/...` paths (NOT `../../../vendor/...`). Aligns with plugin-standalone model from 01-01."
  - "phpstan tmpDir set to `.phpstan-cache` (plugin-local). v1.x used host-relative `../../../storage/temp/phpstan/metapixel` — that path doesn't exist in standalone model. `.phpstan-cache` is in .gitignore from 01-01."
  - "rector uses fluent API `withPhpSets(php83: true)` + `withPreparedSets(deadCode, codeQuality, typeDeclarations, earlyReturn)` — matches plan must-have artifact contains `php83: true` AND the user's UP_TO_PHP_83 conceptual equivalent."
  - "rector dry-run flags `SafeDeclareStrictTypesRector` would add `declare(strict_types=1);` to Plugin.php. NOT applied — project lock (per plan task 2 notes): strict_types is optional, ecosystem norm is no enforcement. Rector is not in the qa chain — purely informational."
  - "phpmd.xml ruleset name = `Metapixel` (was `MetapixelShopaholic` in v1.x). ExcessiveClassComplexity threshold = 50 (canonical Toolbox; v1.x raised to 55 for PayloadBuilder/PurchasePixel exception — stripped per `fresh & simple` project lock)."
  - "ShortMethodName exceptions=`up` retained — October migrations require `up()` as canonical method name."
  - "Smoke testing executed via host repo's `/home/forge/nailscosmetics.lv/vendor/bin/` (NOT via `composer run-script`). Reason: standalone `composer install` fails (October private packages + lovata/toolbox-plugin not on packagist), same blocker as plan 01-01. Smoke proves CONFIG VALIDITY; integration smoke happens once plugin is consumed inside host repo."
metrics:
  duration_minutes: 14
  commits_produced: 1
  tasks_completed: 7
  tasks_skipped: 1
  files_created: 5
  files_modified: 2
  completed: "2026-05-16"
---

# Phase 01 Plan 02: Tooling — phpstan/rector/pint/phpmd/dep-analyser configs + composer qa chain Summary

## One-liner

Shipped v2.0 quality-toolchain configs (phpstan level 10 + PHP 8.3 grammar lock, rector php83, pint Laravel + nullable rule, phpmd from Lovata.Toolbox baseline, shipmonk composer-dependency-analyser with classes/adapter/shopaholic/ pre-allowlist) and wired `composer qa` chain in the plugin's composer.json. PHP 8.4-only syntax (`array_find`, `array_any`, `array_all`, `array_find_key`, `#[\Deprecated]` attribute, `@` suppression, `assert()`) is rejected at the static-analysis layer before any business code lands in Phase 2.

## Execution Summary

| Task | Description | Outcome | Commit |
|------|-------------|---------|--------|
| 1 | Write `phpstan.neon` (TOOL-04) | PASS — level 10, phpVersion 80300, larastan + disallowed-calls, all 6 PHP 8.4 bans + Deprecated attribute ban | `62dae98` |
| 2 | Write `rector.php` (TOOL-05) | PASS — withPhpSets(php83: true), all 4 prepared sets, file_exists-filtered paths | `62dae98` |
| 3 | Write `pint.json` (TOOL-06) | PASS — Laravel preset + nullable_type_declaration_for_default_null_value + ordering + single_quote + single_space | `62dae98` |
| 4 | Write `phpmd.xml` (TOOL-07) | PASS — ruleset name "Metapixel", Lovata.Toolbox baseline, v1.x carry-over tolerances stripped | `62dae98` |
| 5 | Write `composer-dependency-analyser.php` (TOOL-11) | PASS — `php -l` clean; pre-wires `classes/adapter/shopaholic/` allowlist for Lovata cart packages | `62dae98` |
| 6 | Extend `composer.json` — require-dev + scripts.qa chain (TOOL-10) | PASS — JSON valid, 10 dev deps added (pest/larastan/spaze/phpmd/pint/rector/mockery/shipmonk/phpunit/pest-plugin-drift), qa chain wired | `62dae98` |
| 7 | Single atomic tooling-configs commit | PASS — 7 files in one commit (6 configs + pint-fixed Plugin.php) | `62dae98` |
| 8 | Parent repo composer.json edit | **SKIPPED** — plugin is standalone per Option-A from 01-01; no parent composer.json to edit (see Deviations) | (no commit) |
| 9 | Smoke-test qa chain on empty scaffold | PASS — pint-test/analyse/phpmd all green via host vendor (see Smoke Results below) | (verification-only) |

## Commits Produced (1)

**`62dae98`** — `chore(tooling): phpstan + rector + pint + phpmd + dep-analyser + qa chain`

7 files (+273 / -12):

- `phpstan.neon` (new — 53 lines)
- `rector.php` (new — 26 lines)
- `pint.json` (new — 18 lines)
- `phpmd.xml` (new — 89 lines)
- `composer-dependency-analyser.php` (new — 62 lines)
- `composer.json` (modified — require-dev populated + scripts.qa chain wired)
- `Plugin.php` (modified by pint auto-fix — single_line_empty_body + binary_operator_spaces)

## Smoke Test Results

Smoke executed via host repo's `/home/forge/nailscosmetics.lv/vendor/bin/*` because standalone `composer install` fails on October private packages (same blocker as plan 01-01, documented there).

### Pint — `pint . --config=pint.json --test`

After initial auto-fix pass on Plugin.php + composer-dependency-analyser.php:

```
{"tool":"pint","result":"passed"}
```

Exit code: 0. The auto-fix output (recorded in the committed Plugin.php + composer-dependency-analyser.php) collapsed empty method bodies and removed array-key alignment to align with the new `binary_operator_spaces: single_space` rule. Re-run after fix shows 0 issues.

### PHPStan — `phpstan analyse --configuration=phpstan.neon --no-progress`

```
 [OK] No errors
```

Exit code: 0. Plugin.php passes phpstan at level 10 with `phpVersion: 80300`. The `universalObjectCratesClasses` whitelist is parsed without error. The disallowedFunctionCalls + disallowedClasses lists are accepted by spaze/phpstan-disallowed-calls extension. No PHP 8.4-only construct present in Plugin.php to trigger bans (correctness verification waits for tests in plan 01-03).

### PHPMD — `phpmd Plugin.php text phpmd.xml`

```
(no output)
```

Exit code: 0. Plugin.php has 1 short method (`pluginDetails`), zero violations of CyclomaticComplexity / ExcessiveClassLength / LongVariable / ShortVariable / ExcessiveClassComplexity thresholds. The Toolbox-derived ruleset parses cleanly.

### Rector — `rector process --config=rector.php --dry-run --no-progress-bar`

```
1 file would have been changed (dry-run) by Rector
Applied rules:
 * AddOverrideAttributeToOverriddenMethodsRector
 * SafeDeclareStrictTypesRector
```

Exit code: 0. Rector is INFORMATIONAL only (not in qa chain). The suggested `declare(strict_types=1);` addition is NOT applied per project lock (strict_types optional per file; ecosystem norm = no enforcement). The `#[\Override]` attribute suggestion is also informational. No action required.

### composer-dependency-analyser

Not smoke-tested: `shipmonk/composer-dependency-analyser` is NOT in the host repo's require-dev (it lives only in the plugin's require-dev). The binary will become available once the plugin is consumed inside a host repo whose composer adds the package. Config syntax verified via `php -l composer-dependency-analyser.php` (exit 0).

### Test step (`pest`)

Skipped — phpunit.xml + tests/ scaffold lands in plan 01-03 per plan's deferral note. The `test` and `test-cov` composer scripts are wired but will fail until plan 01-03 adds the test infrastructure. This is the expected exit gate for plan 01-02.

## Deviations from Plan

### Rule 3 — Auto-fix blocking issue

**1. Standalone `composer install` blocked — used host vendor for smoke tests**

- **Found during:** Task 9 smoke setup. `composer install` exits non-zero: "Root composer.json requires october/system ^4.0 ... but it does not match" and "lovata/toolbox-plugin not found in any version on default packagist".
- **Root cause:** Plugin is a standalone git repo whose `require:` packages are October-private (not on packagist). Same blocker as plan 01-01, documented there. Per Option-A path interpretation, the host repo will supply these at integration time.
- **Fix:** Temporary symlink `vendor → /home/forge/nailscosmetics.lv/vendor` (host repo's vendor) for smoke tests. Symlink removed before commit; vendor/ is gitignored regardless.
- **Files affected:** none committed (symlink was transient).
- **Smoke verdict:** pint-test PASS / analyse PASS / phpmd PASS. Config validity proven via real tool execution.

### Rule 2 — Scope correction / executor instruction adherence

**2. Task 8 SKIPPED — no parent composer.json to edit**

- **Found during:** Pre-execution. Per executor's `<critical_path_correction>`: "Plan task 8 mentions repo-root composer.json (parent nailscosmetics.lv) — SKIP. Plugin is standalone; all dev deps live in PLUGIN composer.json require-dev. Parent doesn't track plugin."
- **Issue:** Plan was drafted under monorepo assumption that the parent repo would gate the shipmonk dep-analyser binary. Under Option-A (plugin is standalone), there is no parent composer.json under the plugin's control. The host nailscosmetics.lv repo is separate from the plugin's git repo.
- **Fix:** Skip Task 8 entirely. shipmonk lives only in PLUGIN require-dev. Smoke verification of deps-check defers to host-repo integration.
- **Files affected:** none.
- **Commits saved:** 1 (plan expected 2 commits; produced 1).
- **Impact on plan success criterion #7 / #10:** Plan stated "exactly two commits added". Under Option-A this becomes one commit. Success criterion adapted accordingly.

### Rule 2 — Executor override of must-have artifact

**3. `composer qa` chain omits `@deps-check`**

- **Found during:** Task 6 execution. Plan must-have artifact says `scripts.qa` contains `@deps-check`; executor's explicit `<execute>` block specifies `["@pint-test", "@analyse", "@phpmd", "@test-cov"]` (no deps-check).
- **Issue:** Two sources of truth conflict. Executor instructions explicitly override.
- **Fix:** Followed executor instruction. `deps-check` is a SEPARATE composer script (`composer deps`) — TOOL-11 is satisfied by the config file's existence + the standalone runnable script. The qa chain stays formatting/types/complexity/tests fast-fail.
- **Files affected:** `composer.json` scripts block.
- **Risk:** A future developer running `composer qa` would not catch `Lovata\Shopaholic\*` import drift outside `classes/adapter/shopaholic/` automatically. Mitigation: phase-3 adapter plan or a CI workflow step (plan 01-03 owns CI) MUST add `composer deps` as a separate CI step. Documented as handoff to plan 01-03 below.

### Rule 2 — Pint auto-fix applied to Plugin.php and composer-dependency-analyser.php

**4. Pint reformatted both files; auto-fix included in commit**

- **Found during:** Task 9 first smoke run. Pint flagged `single_line_empty_body` (empty `register()` + `boot()` on multi-line) + `binary_operator_spaces=single_space` (array-key arrows aligned with multiple spaces) on Plugin.php; flagged `new_with_parentheses` + `method_argument_space` + `concat_space` on composer-dependency-analyser.php.
- **Fix:** Ran `pint . --config=pint.json` (auto-fix). Both files re-formatted, fold into the task-7 commit. Plan 01-01's Plugin.php prose intent is preserved; only whitespace and empty-body shape changed.
- **Files affected:** `Plugin.php`, `composer-dependency-analyser.php` (both in task-7 commit).
- **Behavior change:** zero (pure cosmetic).

## Verification — Success Criteria

| # | Criterion | Status |
|---|-----------|--------|
| 1 | `phpstan.neon` exists with phpVersion 80300, level 10, larastan + disallowed-calls includes, all PHP 8.4-only function bans + Deprecated attribute ban | PASS |
| 2 | `rector.php` exists with `withPhpSets(php83: true)`; no `php84` references | PASS (`grep -q 'php83: true'` matches; `grep -q 'php84'` returns nothing) |
| 3 | `pint.json` exists with `nullable_type_declaration_for_default_null_value: true`; JSON valid | PASS |
| 4 | `phpmd.xml` exists with `name="Metapixel"`; XML-valid (verified via python xml.etree.ElementTree fallback — xmllint not installed on Forge host) | PASS |
| 5 | `composer-dependency-analyser.php` exists; `php -l` clean; allowlists `classes/adapter/shopaholic/` for Lovata cart packages | PASS |
| 6 | `composer.json` has `scripts.qa = ["@pint-test", "@analyse", "@phpmd", "@test-cov"]` and require-dev includes `shipmonk/composer-dependency-analyser` | PASS (note: executor override drops `@deps-check` from qa chain vs plan's must-have; see Deviation 3) |
| 7 | Repo-root `composer.json` has shipmonk in require-dev; `vendor/bin/composer-dependency-analyser` executable | **DEVIATED** — Task 8 skipped under Option-A. Plugin is standalone; no parent composer.json edits. shipmonk lives in PLUGIN require-dev only. |
| 8 | `composer pint-test`, `composer analyse`, `composer phpmd`, `composer deps-check` exit 0 on empty scaffold | PARTIAL — pint-test/analyse/phpmd PASS (via host vendor; standalone install blocked). deps-check skipped: shipmonk not in host vendor. |
| 9 | `composer test` acknowledged as deferred to plan 01-03 | PASS (recorded above) |
| 10 | Exactly two commits added | **DEVIATED** — 1 commit produced (Task 8 skipped). Recorded under Deviation 2. |

## Handoff to Plan 01-03

- **Phpstan paths list:** currently `[Plugin.php]` only. When tests/ lands in plan 01-03, no edit needed — `tests` is in phpstan `excludePaths` (because tests are owned by Pest/phpunit, not phpstan). Phase 2 will reopen `paths` to add `classes`, `models`, `components` as those dirs land.
- **PHPMD command:** currently `phpmd Plugin.php text phpmd.xml`. When Phase 2 lands classes/ and models/, the command must be extended (e.g. `phpmd Plugin.php,classes,models text phpmd.xml`). Plan 01-03 does NOT touch this (tests are excluded from phpmd by convention).
- **Pest scaffold (plan 01-03):** Create `phpunit.xml` (or `phpunit.xml.dist`) at plugin root; create `tests/Pest.php` + `tests/TestCase.php`. Once these exist, `composer test` / `composer test-cov` will execute (currently fails with "phpunit.xml not found").
- **CI matrix (plan 01-03):** `.github/workflows/metapixel-qa.yml` MUST add a `composer deps` step alongside `composer qa` to enforce TOOL-11 in CI even though deps-check is not in the qa chain. Specifically: CI step ordering should be `composer install --prefer-dist` then `composer qa` then `composer deps` (deps-check needs vendor/ to scan composer.lock; runs after qa to keep fast-fail order).
- **Shipmonk binary availability:** Once plugin is consumed inside a host repo whose `composer install` pulls the plugin's require-dev, `vendor/bin/composer-dependency-analyser` becomes available. Until then, deps-check is a no-op locally. Plan 01-03 CI runs from a clean checkout of the plugin repo — `composer install --dev` in CI will populate vendor and unlock the binary.
- **Strict_types policy:** rector dry-run will continue to suggest `declare(strict_types=1);`. Documented as informational. If a future plan flips the policy, drop `SafeDeclareStrictTypesRector` from rector or add it to `withSkip()`.

## Drift Log — `files_modified` Frontmatter

Plan `files_modified` listed:

- `plugins/logingrupa/metapixel/phpstan.neon` — landed at `phpstan.neon` (plugin-relative). Drift: path prefix dropped per Option-A.
- `plugins/logingrupa/metapixel/rector.php` — landed at `rector.php`. Drift: path prefix dropped.
- `plugins/logingrupa/metapixel/pint.json` — landed at `pint.json`. Drift: path prefix dropped.
- `plugins/logingrupa/metapixel/phpmd.xml` — landed at `phpmd.xml`. Drift: path prefix dropped.
- `plugins/logingrupa/metapixel/composer-dependency-analyser.php` — landed at `composer-dependency-analyser.php`. Drift: path prefix dropped.
- `plugins/logingrupa/metapixel/composer.json` — landed at `composer.json`. Drift: path prefix dropped.
- `composer.json` (REPO-ROOT) — **NOT TOUCHED**. Drift: file omitted per Option-A skip of Task 8.
- `composer.lock` (REPO-ROOT) — **NOT TOUCHED**. Drift: file omitted per Task 8 skip.

Additional file modified (NOT in plan frontmatter):

- `Plugin.php` — Pint auto-fix output. Drift: pint reformatted Plugin.php (single_line_empty_body, binary_operator_spaces). Bundled in task-7 commit. Justification: Rule 2 — without this, `composer pint-test` would fail and the qa chain wouldn't be green on the empty scaffold.

## Self-Check: PASSED

Verified post-write:

```
test -f phpstan.neon            → FOUND
test -f rector.php              → FOUND
test -f pint.json               → FOUND
test -f phpmd.xml               → FOUND
test -f composer-dependency-analyser.php → FOUND
test -f composer.json           → FOUND (modified)
git log | grep 62dae98          → FOUND
```

All six artifacts present. Commit `62dae98` in git log. Smoke-tested tools exit 0.
