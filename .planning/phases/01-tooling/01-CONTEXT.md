# Phase 1: Tooling - Context

**Gathered:** 2026-05-12
**Status:** Ready for planning
**Mode:** Auto-generated (smart discuss infrastructure shortcut)

<domain>
## Phase Boundary

The plugin scaffold enforces the quality bar before any business code is written — `composer qa` green on an empty plugin, so subsequent phases fail loudly the moment they slip. Scope = `composer.json`, `phpstan.neon` (level 10 + larastan + universalObjectCrates + spaze/phpstan-disallowed-calls), `phpmd.xml` (Toolbox copy, LongVariable max=40), `pint.json`, `rector.php`, Pest 4 scaffold + `tests/MetapixelTestCase.php`, GitHub Actions `metapixel-qa.yml`. No business code, no models, no events, no components.

Out of scope: Plugin.php boot logic, Settings model, middleware, CAPI client, queue jobs, payload builders — all deferred to Phase 2+.

</domain>

<decisions>
## Implementation Decisions

### Locked by REQUIREMENTS.md (v1.0.0)

- TOOL-01: composer deps + `qa` script chain (pint-test → analyse → phpmd → test-cov)
- TOOL-02: phpstan level 10, larastan, spaze/phpstan-disallowed-calls (`assert()` + `@` banned), universalObjectCrates for `ElementItem`/`ElementCollection`, `reportUnmatchedIgnoredErrors: true`, `treatPhpDocTypesAsCertain: true`, `checkUninitializedProperties: true`
- TOOL-03: phpmd.xml = verbatim copy of `plugins/lovata/toolbox/PHPMD_custom.xml` with `LongVariable max=40` (Hungarian notation accommodation), Class ≤ 1000 LOC, CC ≥ 10, `ShortVariable min=4`
- TOOL-04: pint.json = Laravel preset + `ordered_imports: alpha`, `no_unused_imports`, `single_quote`, `binary_operator_spaces: single_space`, `exclude: [updates]`
- TOOL-05: rector.php = `LevelSetList::UP_TO_PHP_84` + `SetList::{CODE_QUALITY, DEAD_CODE, EARLY_RETURN, TYPE_DECLARATION}`
- TOOL-06: Pest 4 scaffold — `tests/Pest.php`, `tests/MetapixelTestCase.php` copied from `plugins/logingrupa/campaignpricingshopaholic/tests/CampaignPricingTestCase.php` pattern (extends `System\Tests\Bootstrap\TestCase`, uses `InteractsWithAuthentication`/`PerformsMigrations`/`PerformsRegistrations`, `setUp(): void` calls `$this->runOctoberUpCommand()`)
- TOOL-07: `.github/workflows/metapixel-qa.yml` runs `composer install` + `composer qa` on PHP 8.4 for push to master + PRs touching plugin path
- TOOL-08: `composer qa` exits zero on empty scaffold

### Carried from PROJECT.md / v3 plan synthesis

- No `assert()` anywhere — enforced by `spaze/phpstan-disallowed-calls`
- Folder layout = Lovata singular (`classes/{event,queue,helper,meta,exception}/` + `middleware/` at plugin root) — but Phase 1 only needs `tests/`, root config files, and `.github/workflows/`
- Plugin namespace: `Logingrupa\Metapixelshopaholic`
- composer package name: `logingrupa/oc-metapixel-plugin`

### Claude's Discretion

- Exact dev-dep version pinning within `^` ranges of REQUIREMENTS.md
- composer script step ordering inside `qa` chain (must end with test-cov)
- `.gitignore`, `.editorconfig`, `phpmd.xml` ruleset XML formatting
- README.md stub content (full runbook deferred to Phase 5 per HARD-04)
- Exact `MetapixelTestCase` class structure (mirror `CampaignPricingTestCase` exactly)
- CI matrix shape — single PHP 8.4 job is sufficient per TOOL-07

</decisions>

<code_context>
## Existing Code Insights

### Reusable References (read during plan-phase)

- `plugins/lovata/toolbox/PHPMD_custom.xml` — source for `phpmd.xml` copy
- `plugins/logingrupa/campaignpricingshopaholic/tests/CampaignPricingTestCase.php` — pattern for `MetapixelTestCase.php`
- `plugins/logingrupa/campaignpricingshopaholic/composer.json` — likely closest analog for composer scaffolding
- `plugins/logingrupa/campaignpricingshopaholic/phpstan.neon` (if exists) — local phpstan precedent
- Root `phpcs.xml` — only existing lint config (PSR-2)
- Root `phpunit.xml` — existing test harness reference

### Established Patterns

- Hungarian notation (`$ob`/`$ar`/`$i`/`$s`/`$b`/`$f`) — `phpmd.xml` `LongVariable max=40` accommodates
- Plugin namespace casing: `Logingrupa\PluginName` (NOT `LoginGrupa`)
- October CMS test harness via `System\Tests\Bootstrap\TestCase` + `runOctoberUpCommand()`
- SQLite in-memory DB for tests

### Integration Points

- `.github/workflows/` — new workflow added; existing CI workflows (if any) untouched
- Root composer ecosystem unchanged — this plugin has its own `composer.json` at plugin root
- `composer qa` runs from plugin root only — root project `composer.json` not modified

</code_context>

<specifics>
## Specific Ideas

- `composer qa` is the single gate: pint-test → analyse → phpmd → test-cov. If any step fails, `qa` fails.
- Empty plugin = no `classes/`, no `models/`, no `components/` yet. Only `tests/`, `composer.json`, `phpstan.neon`, `phpmd.xml`, `pint.json`, `rector.php`, `.github/workflows/metapixel-qa.yml`, minimal `Plugin.php` stub (registers nothing yet — or deferred to Phase 2).
- If Plugin.php is required for October to recognise the plugin during Pest boot, scaffold the absolute minimum: namespace + class extending `System\Classes\PluginBase` + empty `pluginDetails()`. No `boot()`, no `register()`, no event subscribers.
- phpmd `LongVariable max=40` is a hard requirement — Hungarian notation `$obProductItemCollectionWithSomething` regularly exceeds 25 chars.

</specifics>

<deferred>
## Deferred Ideas

- Settings model + `models/settings/fields.yaml` → Phase 2 (SKEL-02)
- Middleware → Phase 2 (SKEL-03)
- CAPI client, queue jobs, payload builders, exception hierarchy → Phase 3 (PAY-01 through PAY-11)
- All funnel-event components → Phase 4 (FUN-01 through FUN-14)
- README runbook, translations, FailedEvents backend, Composer marketplace listing → Phase 5 (HARD-01 through HARD-08)
- `composer require logingrupa/oc-metapixel-plugin` end-to-end install verification → Phase 5 (HARD-05)
- Coverage ≥ 90 % gate → Phase 5 (HARD-06). Phase 1 only requires `pest` runs 0 tests / 0 failures.

</deferred>
