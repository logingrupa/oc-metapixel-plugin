---
phase: 01-tooling-composer-namespace-rename-ci-matrix
plan: 01
subsystem: tooling-scaffold
tags: [tooling, composer, namespace-rename, scaffold]
requires: []
provides:
  - "Plugin directory at plugins/logingrupa/metapixel/ (filesystem-renamed from metapixelshopaholic/)"
  - "Plugin.php with namespace Logingrupa\\Metapixel — empty boot/register scaffold"
  - "composer.json TOOL-01 shape: name logingrupa/oc-metapixel-plugin, PHP ^8.3 || ^8.4, Lovata cart plugins in suggest:"
  - "plugin.yaml with lang-keyed name/description (logingrupa.metapixel::lang.plugin.*)"
  - "lang/en/lang.php + lang/lv/lang.php with plugin.name + plugin.description keys"
  - "legacy/v1.1.1 branch preserves full v1.x source — retrievable via git show legacy/v1.1.1:<path>"
affects:
  - "October PluginManager identifier locks to Logingrupa.Metapixel"
  - "PSR-4 autoload root: Logingrupa\\Metapixel\\ → \"\""
  - "Test namespace tail pre-wired: Logingrupa\\Metapixel\\Tests\\ → \"tests/\""
tech-stack:
  added: []
  patterns:
    - "October-CMS plugin scaffold pattern (Plugin.php + plugin.yaml + lang/{en,lv}/lang.php)"
    - "Composer suggest pattern for cart-plugin extensibility (Lovata cart plugins in suggest:, never require:)"
key-files:
  created:
    - "Plugin.php"
    - "plugin.yaml"
    - "composer.json"
    - "lang/en/lang.php"
    - "lang/lv/lang.php"
  modified: []
  deleted:
    - "v1.x source tree: Plugin.php, classes/, models/, components/, controllers/, middleware/, lang/, updates/, tests/, .github/workflows/, composer.json (v1.x), plugin.yaml (v1.x), phpstan.neon, phpstan-baseline.neon, phpmd.xml, pint.json, rector.php, phpunit.xml — preserved on legacy/v1.1.1"
decisions:
  - "Option A path interpretation: plugin is standalone git repo, not monorepo subdir. Tasks 2 + 6 deviated from plan; documented in 'Deviations' below."
  - "Filesystem mv (containing dir rename) deferred to AFTER internal commits — done as post-execution OS mv. No git commit recorded for the dir rename (plugin's own repo cannot rename its own root; parent repo doesn't track plugin)."
  - "composer.json scripts.qa = [] for now — chain populated in plan 01-02 after phpstan/phpmd/pint/rector configs land."
  - "composer.json require-dev = {} for now — test framework + linters land in plans 01-02 + 01-03."
  - "composer.json license: proprietary (per execute objective override, not the plan's MIT — plan author's draft predates the proprietary lock)."
metrics:
  duration_minutes: 12
  commits_produced: 2
  completed: "2026-05-16"
---

# Phase 01 Plan 01: Tooling — composer + namespace rename + scaffold

## One-liner

v1.x plugin source removed from master, v2.0 minimal scaffold (Plugin.php + plugin.yaml + composer.json + lang en/lv) written under new namespace `Logingrupa\Metapixel`, containing directory filesystem-renamed `metapixelshopaholic/` → `metapixel/`. v1.x preserved on `legacy/v1.1.1` branch. Plugin's own git repo retains full commit history across the rename.

## Execution Summary

| Task | Description | Outcome | Commit |
|------|-------------|---------|--------|
| 1 | Verify `legacy/v1.1.1` branch + `v1.1.1` tag | PASS — both pre-existed | (no commit, git-ref bookkeeping) |
| 2 | Rename plugin dir `metapixelshopaholic/` → `metapixel/` | **Deferred to post-execution** (see Deviations) | (no commit, filesystem mv outside plugin's repo) |
| 3 | Namespace + lang-key rewrite | **No surviving targets** — all v1.x files marked for deletion in Task 4 (see Deviations) | (no commit) |
| 4 | Delete v1.x source tree | PASS — 71 files deleted | `86517a7` |
| 5 | Write v2.0 minimal scaffold | PASS — 5 files created, all syntax-valid | `89bc952` |
| 6 | composer validate / install probe | `composer validate --strict` exits 0 (PASS). `composer install` blocked by standalone-repo limitation (host repo provides October + Lovata; this is expected and documented) | (no commit — composer.lock not generated; gitignored anyway) |
| post | Filesystem mv `metapixelshopaholic/` → `metapixel/` | PASS — plugin repo reachable at new path; git history intact | (non-commit, OS-level mv) |

## Commits Produced (2)

1. **`86517a7`** — `chore(v2.0): remove v1.x source — preserved on legacy/v1.1.1`
   - 71 files deleted (-11562 lines): v1.x Plugin.php, classes/, models/, components/, controllers/, middleware/, lang/, updates/, tests/, .github/workflows/, composer.json, plugin.yaml, phpstan.neon, phpstan-baseline.neon, phpmd.xml, pint.json, rector.php, phpunit.xml.
   - Retained: `.planning/`, `.gitignore`, `.editorconfig`.

2. **`89bc952`** — `feat(v2.0): minimal v2.0 scaffold — Plugin.php + plugin.yaml + composer.json + lang/{en,lv}/lang.php`
   - 5 files created (+104 lines):
     - `Plugin.php` (namespace `Logingrupa\Metapixel`, empty `boot()`/`register()`, `$require = ['Lovata.Toolbox']`)
     - `plugin.yaml` (lang-keyed name/description)
     - `composer.json` (TOOL-01 shape; PSR-4 `Logingrupa\Metapixel\` → `""`, autoload-dev `Logingrupa\Metapixel\Tests\` → `"tests/"`)
     - `lang/en/lang.php` + `lang/lv/lang.php` (plugin.name + plugin.description only)

## Verification Outcomes

**Success criterion 1 — `legacy/v1.1.1` branch + v1.1.1 tag:**
- `git rev-parse legacy/v1.1.1` → `3f32ca6b9bae9c70df4c949601ae5abe1ca5b189` ✓
- `git rev-parse v1.1.1` → `73f99a493ee7862c676745d6ab8b86f3c31df2cb` ✓
- `git show legacy/v1.1.1:composer.json | head -3` → v1.x composer.json header returned ✓

**Success criterion 2 — `plugins/logingrupa/metapixel/` contains expected files:**
- `Plugin.php`, `plugin.yaml`, `composer.json`, `lang/en/lang.php`, `lang/lv/lang.php` ✓
- `.planning/`, `.gitignore`, `.editorconfig` retained ✓
- Untracked/ignored: `.claude/worktrees/`, `.phpunit.result.cache` (carryover from v1.x dev, gitignored)
- No source dirs (classes/, models/, components/, controllers/, middleware/, updates/, tests/, .github/)

**Success criterion 3 — old path gone:**
- `[ -d /home/forge/nailscosmetics.lv/plugins/logingrupa/metapixelshopaholic ]` → false ✓ (filesystem mv complete)

**Success criterion 4 — grep checks return zero outside `.planning/`:**

```
=== Logingrupa\Metapixelshopaholic outside .planning ===     → 0 ✓
=== logingrupa.metapixelshopaholic outside .planning ===     → 0 ✓
=== Logingrupa.Metapixelshopaholic outside .planning ===     → 0 ✓
=== metapixelshopaholic (bare) outside .planning ===         → 0 ✓
```

**Success criterion 5 — composer.json TOOL-01 shape:**
- `name`: `logingrupa/oc-metapixel-plugin` ✓
- `php`: `^8.3 || ^8.4` ✓
- `PSR-4`: `Logingrupa\Metapixel\` → `""` ✓
- `lovata/shopaholic-plugin` placement: only in `suggest:`, NOT in `require:` ✓
- `lovata/ordersshopaholic-plugin` placement: only in `suggest:`, NOT in `require:` ✓
- `require-dev`: `{}` (deferred to plan 01-02) — diverges from plan's pre-fill but consistent with build-philosophy lock

**Success criterion 6 — composer validate / install:**
- `composer validate --strict` → `./composer.json is valid` ✓
- `composer install` (standalone) — blocked by missing `october/system` + `lovata/toolbox-plugin` on default packagist (expected; standalone repo cannot resolve October-private packages — host repo supplies them at install time). This is the standard October-plugin pattern: the plugin's composer.json declares the contract; install happens inside the host repo. Documented as Option-A deviation, not a failure.
- PHP class-existence probe deferred to in-host smoke test (post-mv, host's PluginManager will boot the renamed plugin on next request).

**Success criterion 7 — exactly one commit per task:**
- Plan expected 5 commits. Under Option A interpretation: 2 commits produced (Tasks 4 + 5). Tasks 1, 2, 3, 6 produced no commits — see Deviations below.

## Deviations from Plan

### Auto-applied (Rule 2 — interpretation correction, no behavior shift)

**1. [Rule 2 — Option-A path interpretation] Plugin is standalone git repo, not monorepo subdir**

- **Found during:** Pre-execution verification (executor prompt explicitly documents this).
- **Issue:** Plan was drafted under monorepo assumption (`plugins/logingrupa/metapixel/Plugin.php`). Actual structure: plugin is its own git repo with files at REPO ROOT (`Plugin.php`, `composer.json`, `.planning/`).
- **Fix:** All plan paths reinterpreted as plugin-repo-root paths. `git show legacy/v1.1.1:plugins/logingrupa/metapixelshopaholic/X` → `git show legacy/v1.1.1:X`.
- **Files affected:** all of them.
- **Commits:** baked into every commit naturally — no separate commit.

**2. [Rule 2 — scope correction] Task 2 deferred to post-execution filesystem mv (not a git commit)**

- **Found during:** Pre-execution.
- **Issue:** Plan's Task 2 specified `git mv plugins/logingrupa/metapixelshopaholic plugins/logingrupa/metapixel`. Under Option A, the plugin's own git repo cannot rename its own root; the parent forge repo doesn't track this plugin (verified via `git ls-files` in parent — plugin path absent).
- **Fix:** Tasks 3-6 ran inside the plugin repo at its current path (`metapixelshopaholic/`). After internal commits landed, performed OS-level `mv` of the containing directory from `metapixelshopaholic/` to `metapixel/`. The plugin repo's `.git/` followed the rename intact; `git log` at new path shows full history.
- **Files affected:** containing dir name only — no file contents.
- **Commits:** zero (filesystem op outside plugin repo's scope).

**3. [Rule 2 — scope correction] Task 3 had no surviving targets**

- **Found during:** Task 3 startup.
- **Issue:** Plan's Task 3 mechanical-namespace rewrite assumed v1.x files would survive into Task 4. Under Option A combined with build-philosophy lock ("fresh & simple, no over-engineering, no v1.x carry-forward"), Task 4 deletes everything; nothing survives that needs rewriting. The `.github/workflows/metapixel-qa.yml` was the only candidate file with old-name refs, but it's slated for v2 rewrite in plan 01-03.
- **Fix:** Skipped Task 3 entirely. Verified post-Task-4 + post-Task-5 that the grep checks for old-name references return zero in the new tree.
- **Commits:** zero.

**4. [Rule 2 — scope correction] Task 6 composer install impossible standalone**

- **Found during:** Task 6 execution.
- **Issue:** Plan's Task 6 expected `composer install --no-progress --prefer-dist` to resolve `october/system ^4.0` + `lovata/toolbox-plugin ^2.2`. Default packagist doesn't carry October-private packages; they live in the host monorepo's vendor/ which is provisioned via the host's own composer install.
- **Fix:** Substituted `composer validate --strict` (exits 0 → composer.json is structurally correct). Full install verification deferred to host-level smoke test (post-mv, host's `October::onCommand` or PluginManager will resolve `Logingrupa\Metapixel\Plugin` via the host autoloader).
- **Commits:** zero (no composer.lock generated; gitignored anyway).

**5. [Rule 2 — license correction] composer.json license is `proprietary`, not plan's `MIT`**

- **Found during:** Task 5 file write.
- **Issue:** Plan task 5.1 specified `license: MIT`. Execute objective (and project's CLAUDE.md "production must not break … Composer package installable from private GitHub repo" + "marketplace-grade plugin") indicates proprietary licensing for v2.0.
- **Fix:** Wrote `"license": "proprietary"` per execute objective.
- **Commits:** baked into `89bc952`.

**6. [Rule 2 — composer.json minimization] composer.json minimal — TOOL-01 shape only, full deps deferred**

- **Found during:** Task 5 file write.
- **Issue:** Plan task 5.1 specified richer `require:` (incl. `guzzlehttp/guzzle`, `ramsey/uuid`, `jeremykendall/php-domain-parser`, `october/rain`) and substantial `require-dev:` (pest, larastan, phpmd, pint, rector, mockery, dev-only Lovata cart plugins). Execute objective explicitly says `"require-dev": {} — deferred to plan 01-02"`.
- **Fix:** Wrote minimal composer.json per execute objective: `require:` = `{php, october/system, lovata/toolbox-plugin}`; `require-dev:` = `{}`; `scripts.qa: []`; cart deps in `suggest:` only. Phase 4 deps (`php-domain-parser`) deferred to phase 4.
- **Commits:** baked into `89bc952`.

## Auth Gates

None — all operations local; no Composer authentication required.

## Threat Flags

None — this plan ships no new network surface (Plugin.php has empty boot/register). The composer.json `suggest:` block lists Lovata cart plugins for documentation; it does NOT cause Composer to auto-install them.

## Known Stubs

`Plugin.php` ships with empty `boot()` + `register()` bodies. This is intentional per plan's `<context>` lock ("Phase 1 ships an empty boot/register") and `success_criteria` (Plugin.php is a scaffold; Phase 2 wires AdapterRegistry + PluginGuard + Settings).

Other "stubs":
- `composer.json` `scripts.qa: []` — populated in plan 01-02 after phpstan/phpmd/pint/rector configs land.
- `composer.json` `require-dev: {}` — populated in plan 01-02 (linters) + 01-03 (Pest).
- `lang/lv/lang.php` minimal — business lang keys land per owning phase (no early bloat).

These are NOT bugs — they are explicit Phase 1 boundaries.

## Reachability Audit

| Must-have truth | Status | Evidence |
|---|---|---|
| v1.x source preserved on `legacy/v1.1.1` branch | ✓ | `git show legacy/v1.1.1:composer.json` returns v1.x header. SHA `3f32ca6b9bae9c70df4c949601ae5abe1ca5b189`. |
| Master tree contains `plugins/logingrupa/metapixel/Plugin.php` with namespace `Logingrupa\Metapixel` | ✓ | `php -l Plugin.php` syntax-valid; file head shows `namespace Logingrupa\Metapixel;`. |
| Master tree no longer contains `plugins/logingrupa/metapixelshopaholic/` | ✓ | `[ -d ... ]` returns false (filesystem mv complete). |
| `.planning/` lives inside `plugins/logingrupa/metapixel/.planning/` | ✓ | `ls .planning/STATE.md` succeeds at new path. |
| `composer.json` advertises name `logingrupa/oc-metapixel-plugin`, php `^8.3 || ^8.4`, PSR-4 `Logingrupa\Metapixel\`, Lovata cart plugins in `suggest:` (not `require:`) | ✓ | JSON parse + grep + python assertion all passed. |
| `composer install` completes with exit 0 | DEFERRED (Option A) | `composer validate --strict` exits 0; full install is host-repo concern. |

## Handoff Notes for Plan 01-02

**composer.json `scripts` section status:**
- Currently: `"scripts": { "qa": [] }`.
- Plan 01-02 expands to the full `qa` chain after `phpstan.neon` (TOOL-04), `rector.php` (TOOL-05), `pint.json` (TOOL-06), `phpmd.xml` (TOOL-07) configs land.
- Expected final shape: `qa: ["@pint-test", "@analyse", "@phpmd", "@test-cov"]`.

**composer.json `require-dev` status:**
- Currently: `{}`.
- Plan 01-02 adds: `larastan/larastan ^3.0`, `spaze/phpstan-disallowed-calls ^4.0`, `phpmd/phpmd ^2.15`, `laravel/pint ^1.26`, `rector/rector ^2.0`, `shipmonk/composer-dependency-analyser` (TOOL-11).
- Plan 01-03 adds: `pestphp/pest ^4.1`, `pestphp/pest-plugin-drift ^4.0`, `phpunit/phpunit ^12`, `mockery/mockery ^1.6`, dev-only Lovata cart plugins (`^1.32`, `^1.33`, `^1.10`).

**Phase 4 deferred dep (per STACK.md "ADD php-domain-parser ^6.4"):**
- `jeremykendall/php-domain-parser ^6.4` was specified in the plan's Task 5 for `require:` — DEFERRED to Phase 4 (HOST-01..06 TrustedHosts) per build-philosophy lock ("install for current need only"). When Phase 4 lifts cookies + multi-TLD index derivation work, that plan adds the dep.

**CI workflow (.github/workflows/metapixel-qa.yml):**
- Plan 01-03 writes this fresh per TOOL-09 matrix spec (php 8.3 + 8.4 × full-lovata + minimal). The old v1.x workflow file was DELETED in Task 4.

**Test scaffold pre-wiring:**
- `composer.json` already declares `autoload-dev.psr-4: Logingrupa\Metapixel\Tests\ → "tests/"`. Plan 01-03 creates `tests/` + `MetapixelTestCase.php` + `ShopaholicAdapterTestCase.php` and the namespace will resolve cleanly.

## Self-Check: PASSED

Verifications run:

```bash
[ -f /home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/Plugin.php ]            && echo FOUND  # FOUND
[ -f /home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/composer.json ]         && echo FOUND  # FOUND
[ -f /home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/plugin.yaml ]           && echo FOUND  # FOUND
[ -f /home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/lang/en/lang.php ]      && echo FOUND  # FOUND
[ -f /home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/lang/lv/lang.php ]      && echo FOUND  # FOUND
[ -d /home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/.planning ]             && echo FOUND  # FOUND
[ ! -d /home/forge/nailscosmetics.lv/plugins/logingrupa/metapixelshopaholic ]           && echo "OLD GONE"  # OLD GONE
git log --oneline --all | grep -q "86517a7" && echo "COMMIT 86517a7 FOUND"   # FOUND
git log --oneline --all | grep -q "89bc952" && echo "COMMIT 89bc952 FOUND"   # FOUND
git rev-parse legacy/v1.1.1                                                   # 3f32ca6b9bae9c70df4c949601ae5abe1ca5b189
git rev-parse v1.1.1                                                          # 73f99a493ee7862c676745d6ab8b86f3c31df2cb
```

All artifacts present at the new path. Both commits reachable via `git log`. Old containing dir gone.
