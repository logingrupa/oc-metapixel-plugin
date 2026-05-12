---
phase: 1
plan: 1
status: findings_addressed
date: 2026-05-12
remediation_date: 2026-05-12
---

# Phase 1: Code Review — Tooling Scaffold

**Reviewed:** 2026-05-12
**Depth:** standard (cross-file forward-compat traced)
**Files Reviewed:** 14
**Status:** findings_found (1 BLOCKER, 1 HIGH, 4 MEDIUM, 3 LOW, 2 NIT)

## Summary

Phase 1 ships tooling only — `composer.json`, `phpstan.neon`, `phpmd.xml`, `pint.json`,
`rector.php`, `phpunit.xml`, a 28-line `Plugin.php` stub, a test-harness mirror, a
single sanity test, and a GitHub Actions workflow. No business logic.

The scaffold is largely sound and faithfully mirrors the `campaignpricingshopaholic`
analog. The PHPStan `(?)` optional-path syntax for forward-compat is correct.
The `Plugin.php` stub is minimal and typed at phpstan level 10.

One **BLOCKER**: the GH Actions workflow runs `composer install` against a root
`composer.json` that requires **15 private GitHub SSH repositories** with no SSH
key or `GITHUB_TOKEN` → composer auth configured. CI will fail at first push.

One **HIGH**: `phpunit.xml` `<env>` directives lack `force="true"`, which on
hosts that pre-export `DB_CONNECTION=mysql` (the Forge `.env` does) will cause
SanityTest to attempt the **production MySQL DB** instead of the in-memory SQLite.
The analog plugins all use `force="true"` as defence. This works today only because
Laravel's `Dotenv::safeLoad()` is immutable and PHPUnit sets vars before bootstrap;
the safety net breaks the moment a developer or CI shell pre-exports the var.

The remaining MEDIUM findings concern **forward-compat reopens of the scaffold**:
every static-analysis / lint / coverage config currently scopes itself to only
`Plugin.php`. Phase 2-5 *will* need to re-edit `phpstan.neon`, `phpmd.xml`,
`rector.php`, and `phpunit.xml` to add `classes/`, `models/`, `components/`,
`middleware/`. This is expected, but the verification doc should explicitly call
out which lines need reopening so it's not a surprise. Documented below.

LOW findings cover namespace casing drift from the convention, missing `permissions:`
block on the workflow, `lang/` directory missing from pint exclude, and pinning of
third-party GH actions by tag rather than SHA. Two NITs cover Hungarian-notation
in the test harness mirror (waived as analog mirror) and a redundant `tests (?)`
optional marker.

---

## BLOCKER Findings

### BR-01: CI workflow has no auth for private SSH composer repositories

**File:** `.github/workflows/metapixel-qa.yml:24-25` (the `composer install` step)
**Severity:** BLOCKER
**Issue:**
The repo-root `composer.json` declares **15 git repositories** using `git@github.com:...`
SSH URLs for private Logingrupa plugins (`oc-extendpromomechanism-plugin`,
`oc-storeextender-plugin`, etc., `composer.json:137-210`). The CI step
`run: composer install --no-progress --prefer-dist --no-interaction` will execute
on a GH-hosted runner with no SSH agent, no deploy key, and no `GITHUB_TOKEN`-based
HTTPS rewrite. Composer will fail with `Failed to clone … via … the SSH key …` on
the first private repo it touches, and `composer qa` will never run.

This is the entire QA gate. It does not work in CI as written.

**Fix (choose one):**
- **Option A (cleanest — only requires the plugin being reviewed):** change the
  workflow to install only inside the plugin directory, not the repo root.
  The plugin's own `composer.json` (`plugins/logingrupa/metapixelshopaholic/composer.json`)
  has *no* private repositories — only public Packagist deps. Replace lines 24-29:
  ```yaml
        - name: composer install (plugin)
          working-directory: plugins/logingrupa/metapixelshopaholic
          run: composer install --no-progress --prefer-dist --no-interaction

        - name: composer qa (plugin)
          working-directory: plugins/logingrupa/metapixelshopaholic
          run: composer qa
  ```
  Caveat: this also drops access to the shared root `vendor/` that `composer qa`
  scripts reference via `../../../vendor/bin/...`. Verify the plugin's own
  `composer install` populates `plugins/logingrupa/metapixelshopaholic/vendor/bin/`
  and update the script paths in `composer.json:40-48`.
- **Option B (preserve current shared-vendor layout):** add an SSH-key / deploy-key
  step before `composer install`:
  ```yaml
        - name: Configure SSH for private composer repos
          uses: webfactory/ssh-agent@v0.9.0
          with:
            ssh-private-key: ${{ secrets.LOGINGRUPA_COMPOSER_SSH_KEY }}
  ```
  and create a GH org-level secret `LOGINGRUPA_COMPOSER_SSH_KEY`. Document the
  rotation policy.
- **Option C:** rewrite all 15 `git@github.com:` URLs to HTTPS and supply
  `secrets.GITHUB_TOKEN` via `composer config github-oauth.github.com ...` —
  but the default `GITHUB_TOKEN` cannot read repos outside the current
  repository, so this requires a PAT secret anyway. Less clean than B.

**Why BLOCKER not HIGH:** Phase 1's stated success criterion #4 is "GitHub Actions
workflow triggers on push/PR and runs `composer qa`". The current YAML cannot
complete that flow on the first push. The verification doc PASS for criterion 4
is labelled **"(config-presence)"** with "CI execution proof is deferred to first
push/PR" — that proof will not arrive without one of the above fixes.

---

## HIGH Findings

### HR-01: `phpunit.xml` `<env>` directives missing `force="true"` — risk of tests hitting production MySQL

**File:** `plugins/logingrupa/metapixelshopaholic/phpunit.xml:23-27`
**Severity:** HIGH
**Issue:**
```xml
<env name="APP_ENV" value="testing"/>
<env name="CACHE_DRIVER" value="array"/>
<env name="SESSION_DRIVER" value="array"/>
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```
PHPUnit `<env>` semantics: without `force="true"`, the variable is set **only if
not already defined** in the parent process environment. The Forge `.env` (and
`.env.example`) sets `DB_CONNECTION=mysql`. If any developer or CI runner pre-exports
that variable into the shell before `composer test` (e.g. `set -a; source .env; set +a`,
which several deployment recipes do), PHPUnit will skip the override and Laravel's
`Dotenv::safeLoad()` (immutable repo, `LoadEnvironmentVariables.php:29`) will also
skip — bootstrap reaches `MetapixelTestCase::setUp() → migrateModules()` against the
**production MySQL database**. `migrateModules()` calls `Artisan::call('october:up')`
which would actually run migrations against prod.

This is currently latent because today's local invocation (`composer test`) inherits
a clean PHPUnit-first env, so PHPUnit successfully wins the race. But:

1. The analog plugins (`postnordshippingshopaholic/phpunit.xml`,
   `retrypaymentshopaholic/phpunit.xml`, `goodsreceivedshopaholic/phpunit.xml`) **all
   use `force="true"`** on every `<env>` line — this scaffold drifted from the
   established defence pattern with no documented reason.
2. Phase 5 hardening or any future shell-level wrapper that pre-exports env will
   silently flip behaviour.

**Fix:** add `force="true"` to all five `<env>` entries:
```xml
<env name="APP_ENV" value="testing" force="true"/>
<env name="CACHE_DRIVER" value="array" force="true"/>
<env name="SESSION_DRIVER" value="array" force="true"/>
<env name="DB_CONNECTION" value="sqlite" force="true"/>
<env name="DB_DATABASE" value=":memory:" force="true"/>
```

---

## MEDIUM Findings

### MR-01: `phpstan.neon` `paths:` whitelist will need reopening every phase

**File:** `plugins/logingrupa/metapixelshopaholic/phpstan.neon:7-8`
**Severity:** MEDIUM
**Issue:** `paths:` only lists `Plugin.php`. Phase 2 (SKEL-01 service skeleton)
adds `classes/`, Phase 3 (event handlers + components) adds `components/` and
extends `classes/event/`, Phase 4 (CAPI + middleware) adds `middleware/` and
`classes/capi/`. Each addition is invisible to `composer analyse` until this file
is re-edited. There is no auto-discovery — phpstan will not scan files outside
`paths:` even if a sibling file `use`s them.

**Fix (preferred):** widen `paths:` now to anticipate the documented Phase 2-5
layout, and rely on `(?)` optional semantics so missing-yet directories don't
explode:
```neon
paths:
    - Plugin.php
    - classes (?)
    - components (?)
    - middleware (?)
    - models (?)
    - console (?)
    - controllers (?)
```
**Or (acceptable):** document in `01-VERIFICATION.md` (D-section) the exact lines
that Phase 2-5 will reopen, so the reopen is expected work, not drive-by.

### MR-02: `phpmd` composer script only scans `Plugin.php`

**File:** `plugins/logingrupa/metapixelshopaholic/composer.json:44`
**Severity:** MEDIUM
**Issue:** `"phpmd": "../../../vendor/bin/phpmd Plugin.php text phpmd.xml"` —
`phpmd`'s first arg is a comma-separated list of paths/files. As written, the
only file ever scanned is `Plugin.php`. Adding `classes/CapiClient.php` in Phase 2
will silently pass phpmd because it isn't in scope. `phpmd.xml` itself is fine.

**Fix:** widen the path list, matching Phase 2-5 layout:
```json
"phpmd": "../../../vendor/bin/phpmd Plugin.php,classes,components,middleware,models text phpmd.xml --ignore-violations-on-exit"
```
(Note: `--ignore-violations-on-exit` is **not** wanted in CI — drop it. Only listed
here so missing dirs don't crash; better is to wait until each dir exists, then
add it in the phase that introduces it. If chosen, document the reopen lines in
`01-VERIFICATION.md`.)

### MR-03: `rector.php` `withPaths()` only lists `Plugin.php`

**File:** `plugins/logingrupa/metapixelshopaholic/rector.php:8-10`
**Severity:** MEDIUM
**Issue:** Same forward-compat concern as MR-01/MR-02 — `withPaths([__DIR__.'/Plugin.php'])`
limits rector to one file. Each new directory in Phase 2-5 needs an explicit add.

**Fix:** widen to anticipated layout. Unlike phpstan, rector does **not** have an
`optional path` syntax — it errors on missing dirs. Cleanest option: use a
conditional `array_filter` of dirs that exist:
```php
->withPaths(array_filter([
    __DIR__.'/Plugin.php',
    __DIR__.'/classes',
    __DIR__.'/components',
    __DIR__.'/middleware',
    __DIR__.'/models',
], fn($p) => file_exists($p)))
```
Or document the reopen lines in `01-VERIFICATION.md`.

### MR-04: `phpunit.xml` `<source>` only covers `Plugin.php` — coverage gates lose teeth in Phase 2+

**File:** `plugins/logingrupa/metapixelshopaholic/phpunit.xml:17-21`
**Severity:** MEDIUM
**Issue:**
```xml
<source>
    <include>
        <file>./Plugin.php</file>
    </include>
</source>
```
This is what `composer test-cov` (pest --coverage) instruments. In Phase 2+ when
real business classes appear under `classes/`, `models/`, `components/`,
`middleware/`, they will be **excluded from the coverage denominator**, so a
"100% coverage" headline will be wildly misleading. The analog
(`goodsreceivedshopaholic/phpunit.xml:29-42`) shows the right shape:
```xml
<source>
    <include>
        <directory>classes</directory>
        <directory>components</directory>
        <directory>models</directory>
        <file>Plugin.php</file>
    </include>
    <exclude>
        <directory>classes/dto</directory>
        <directory>classes/exception</directory>
    </exclude>
</source>
```
**Fix:** widen `<source>` to match anticipated Phase 2-5 layout (PHPUnit accepts
non-existent dirs in `<source>` without erroring), then exclude pure DTO / exception
namespaces once they exist. Document explicitly in `01-VERIFICATION.md` which lines
Phase 2-5 will reopen.

---

## LOW Findings

### LR-01: Plugin namespace casing drifts from the established Logingrupa convention

**File:** `plugins/logingrupa/metapixelshopaholic/Plugin.php:3`, `composer.json:36,53`
**Severity:** LOW
**Issue:** Namespace is `Logingrupa\Metapixelshopaholic` (lowercase `shopaholic`).
Every other Logingrupa plugin in this repo uses **PascalCase compound names**:
- `Logingrupa\GoodsReceivedShopaholic`
- `Logingrupa\PostNordShippingShopaholic`
- `Logingrupa\ExtendPromoMechanism`

October's `PluginManager::getIdentifier()` (`modules/system/classes/PluginManager.php:572`)
normalizes case-insensitively, so this works. But:
1. The `extra.october.plugin` value `Logingrupa.Metapixelshopaholic`
   (`composer.json:53`) leaks the lowercase identifier into config UIs and DB
   `system_settings` keys, where convention would expect `Logingrupa.MetapixelShopaholic`.
2. Future developers grepping for `MetapixelShopaholic` (the natural compound
   spelling) will miss this plugin.
3. CLAUDE.md "Plugin namespace `Logingrupa\PluginName`" example implies the PascalCase
   convention.

**Fix:** rename namespace to `Logingrupa\MetapixelShopaholic` everywhere
(Plugin.php line 3, composer.json `autoload.psr-4` key line 36, `extra.october.plugin`
line 53, MetapixelTestCase.php line 3, SanityTest.php line 3, Pest.php line 3).
**Do this in Phase 1 before any business code lands** — renaming after Phase 2-3
classes are written touches every `use` statement and every DB migration class name.
Cost now: ~6 file edits. Cost in Phase 3: ~50+.

### LR-02: GH Actions workflow missing `permissions:` block

**File:** `.github/workflows/metapixel-qa.yml:11-14`
**Severity:** LOW
**Issue:** No top-level or job-level `permissions:` block. The default
`GITHUB_TOKEN` then has the org/repo default (often `contents: write` for
classic setups). For a read-only QA workflow this violates least-privilege.

**Fix:** add at workflow top-level (after `on:`):
```yaml
permissions:
  contents: read
```

### LR-03: `pint.json` exclude list missing `lang/`, `partials/`, `tests/`

**File:** `plugins/logingrupa/metapixelshopaholic/pint.json:13-15`
**Severity:** LOW
**Issue:** `pint.json` excludes only `updates`. `phpstan.neon`, `rector.php`, and
`phpmd.xml` all also exclude `lang`, `partials`, `tests`. October `lang/en/lang.php`
files are PHP arrays that pint's Laravel preset will gladly reformat — usually
benign but it has bitten Lovata plugins before (re-ordering keys, changing array
syntax). Phase 2 likely adds `lang/`.

**Fix:** widen exclude:
```json
"exclude": [
    "updates",
    "lang",
    "partials",
    "tests"
]
```

(Keep `tests` excluded only if you want pint NOT to touch the analog-mirror
test case — given the verification doc says pint already reformatted the
test files, the team has implicitly decided pint *should* touch them. Pick
one and document.)

---

## NIT Findings

### NR-01: Hungarian notation absent in `MetapixelTestCase.php` local vars

**File:** `plugins/logingrupa/metapixelshopaholic/tests/MetapixelTestCase.php:79,96,98`
**Severity:** NIT
**Issue:** Local vars `$reflectClass`, `$reflect`, `$path`, `$pluginPath`, `$result`,
`$class` violate the CLAUDE.md Hungarian-notation convention (should be `$obReflectClass`,
`$sPath`, `$sPluginPath`, etc.).
**Waiver rationale:** the file is documented as "byte-for-byte mirror" of
`CampaignPricingTestCase.php`, itself lifted from October's upstream `PluginTestCase`.
Diverging from the upstream would make future merges harder. **Accept as-is.**
Flagged for completeness only; no fix required.

### NR-02: `phpstan.neon` `tests (?)` optional marker is redundant — `tests/` always exists

**File:** `plugins/logingrupa/metapixelshopaholic/phpstan.neon:10`
**Severity:** NIT
**Issue:** `tests (?)` marks the path as optional, but `tests/` exists at every
phase (it was created in Phase 1). The `(?)` adds no value and creates the false
impression that tests may be absent. Drop `(?)` from this line; keep on
`updates`, `lang`, `partials`, `.github`.
**Fix:** change line 10 from `- tests (?)` to `- tests`.

---

## Convention Adherence Audit

- **Hungarian notation in PHP:** `Plugin.php` has no variables → N/A. `MetapixelTestCase.php`
  is analog mirror → waived (NR-01). No business code yet to enforce against.
- **Plugin namespace:** `Logingrupa\Metapixelshopaholic` — uses `Logingrupa` (correct,
  not `LoginGrupa`) but lowercase `Metapixelshopaholic` drifts from PascalCase
  compound convention (LR-01).
- **PSR-2 style:** confirmed via `composer pint-test` per verification doc.
- **No `assert()` anywhere:** confirmed — phpstan disallowedFunctionCalls + meta-probe
  in verification doc proves the gate fires.
- **No `@` error suppression:** confirmed — phpstan disallowedFunctionCalls catches it.
- **PHP 8.4 strict types:** `rector.php` declares `strict_types=1`. `Plugin.php` does
  not declare it but has no business logic; Phase 2 stubs should add `declare(strict_types=1)`
  at top of every new file (not a Phase 1 defect, but flag for Phase 2 review).

## Forward-Compat Reopens (consolidated)

Phase 2-5 will need to re-edit these specific lines to add new paths. Document this
in `01-VERIFICATION.md` so the reopens are expected, not drive-by:

| File                          | Line(s) | What needs adding                                       | Phase  |
|-------------------------------|---------|---------------------------------------------------------|--------|
| `phpstan.neon`                | 7-8     | `classes`, `components`, `middleware`, `models`         | 2, 3, 4 |
| `phpmd.xml` consumer (`composer.json`) | 44 | Same dirs in comma-separated `phpmd` script         | 2, 3, 4 |
| `rector.php`                  | 8-10    | Same dirs in `withPaths()`                              | 2, 3, 4 |
| `phpunit.xml`                 | 17-21   | Same dirs in `<source><include>`                        | 2, 3, 4 |
| `pint.json` (if LR-03 applied) | 13-15  | `lang`, `partials` (added once they exist)              | 2      |

Recommendation: **fix MR-01 through MR-04 in Phase 1 follow-up** by widening
all four configs to the full anticipated layout with optional/conditional path
markers. Cost: <30min. Avoids 4 separate phase reopens.

---

_Reviewed: 2026-05-12T00:00:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard (cross-file forward-compat traced)_

---

## Remediation log

**Date:** 2026-05-12
**Fixer:** Claude (gsd-code-fixer)
**Final `composer qa` status:** green (pint passed, phpstan 0 errors, phpmd 0 warnings,
pest 1 passed/2 assertions). `test-cov` exits 1 only because the local PHP CLI lacks a
coverage driver — pre-existing condition documented in `01-VERIFICATION.md`; CI runner
has pcov by default.

| ID    | Status   | Commit    | Notes |
|-------|----------|-----------|-------|
| BR-01 | deferred | —         | CI auth — requires architectural decision (private GH PAT secret, plugin-local install restructure, or own-repo CI). Out of scope for a fix-up round; escalated to user. |
| HR-01 | fixed    | `945f07d` | Added `force="true"` to every `<env>` in `phpunit.xml`. Aligned with analog plugins (postnordshipping/retrypayment/goodsreceived). |
| MR-01 | partial  | `f77d2d9` | PHPStan v2 does **not** honor the `(?)` optional-path marker inside `paths:` (verified empirically — `Class 'classes' not found.`). Cannot widen `paths:` without breaking the gate. Instead, the file now carries a doc-comment listing exactly which phase reopens which path; this is the "acceptable alternative" the review explicitly allows. |
| MR-02 | deferred | —         | phpmd 2.15 errors on missing paths in its comma-separated list; `--ignore-violations-on-exit` would silence ALL violations and weaken the gate. Per-phase reopen documented in `phpstan.neon` (same schedule). Skipped per "do not weaken the gate" rule. |
| MR-03 | fixed    | `f77d2d9` | Widened `withPaths()` in `rector.php` via `array_filter()/file_exists()` to anticipate Phase 2-5 directories. Hungarian-notated closure arg `$sPath`. |
| MR-04 | fixed    | `f77d2d9` | Widened `phpunit.xml` `<source><include>` to anticipated Phase 2-5 directories. PHPUnit 12 silently tolerates non-existent dirs. |
| LR-01 | deferred | —         | Namespace rename `Metapixelshopaholic` → `MetapixelShopaholic` locked by `CONTEXT.md` and `REQUIREMENTS.md`. Renaming touches the plan + every shipped file. Out of scope for a fix-up round; escalated to user. |
| LR-02 | fixed    | `07babc0` | Added `permissions: contents: read` at both workflow-top-level and job-level in `metapixel-qa.yml`. |
| LR-03 | fixed    | `d392355` | Widened `pint.json` `exclude` to `[updates, lang, partials, tests]`. Aligns with phpstan/rector/phpmd exclude lists. |
| NR-01 | deferred | —         | Test-harness mirror; waived in the review itself ("byte-for-byte mirror of CampaignPricingTestCase"). |
| NR-02 | deferred | —         | NIT; not actioned. |

**Deferred findings — escalation notes:**

- **BR-01** (CI auth): Three viable options were enumerated in the review. None is a fix-up — each
  changes either the architecture (Option A: plugin-local install + retarget script paths) or the
  secrets surface (Option B: SSH key org-secret; Option C: PAT). User must pick. Until then, the
  CI workflow will fail on first push to master / PR touching the plugin path. **Recommend Option B**
  (`webfactory/ssh-agent` + `LOGINGRUPA_COMPOSER_SSH_KEY` org-secret) — preserves the shared-vendor
  layout used by every existing repo workflow and is the only option compatible with the current
  `git@github.com:...` SSH URLs in repo-root `composer.json:137-210`.

- **LR-01** (namespace rename `Metapixelshopaholic` → `MetapixelShopaholic`): Decision is locked by
  `CONTEXT.md` line 35 ("Plugin namespace: `Logingrupa\Metapixelshopaholic`") and `REQUIREMENTS.md`
  TOOL-06. Renaming requires re-opening the plan, re-running `composer dump-autoload`, updating
  `Plugin.php`, `composer.json` `autoload.psr-4` + `extra.october.plugin`, `MetapixelTestCase.php`,
  `SanityTest.php`, `Pest.php`, and any future `use` statements. Recommended **before** Phase 2
  business code lands — but the rename itself must be a separate planning task, not a fix-up.

- **MR-02** (phpmd path widening): Won't apply without weakening the gate. Reopens at Phase 2
  (add `classes/`), Phase 4 (add `components/`), Phase 5 (add `controllers/`) — same schedule
  as the PHPStan reopen documented inline in `phpstan.neon`.

_Remediated: 2026-05-12_
_Fixer: Claude (gsd-code-fixer)_
