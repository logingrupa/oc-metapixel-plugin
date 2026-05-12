---
phase: 1
plan: 1
name: Tooling scaffold
status: ready
type: execute
wave: 1
depends_on: []
autonomous: true
requirements: [TOOL-01, TOOL-02, TOOL-03, TOOL-04, TOOL-05, TOOL-06, TOOL-07, TOOL-08]
acceptance: composer qa exits zero on fresh clone
files_modified:
  - plugins/logingrupa/metapixelshopaholic/composer.json
  - plugins/logingrupa/metapixelshopaholic/phpstan.neon
  - plugins/logingrupa/metapixelshopaholic/phpmd.xml
  - plugins/logingrupa/metapixelshopaholic/pint.json
  - plugins/logingrupa/metapixelshopaholic/rector.php
  - plugins/logingrupa/metapixelshopaholic/phpunit.xml
  - plugins/logingrupa/metapixelshopaholic/Plugin.php
  - plugins/logingrupa/metapixelshopaholic/tests/Pest.php
  - plugins/logingrupa/metapixelshopaholic/tests/MetapixelTestCase.php
  - plugins/logingrupa/metapixelshopaholic/tests/Unit/SanityTest.php
  - plugins/logingrupa/metapixelshopaholic/.github/workflows/metapixel-qa.yml
  - plugins/logingrupa/metapixelshopaholic/.gitignore
  - plugins/logingrupa/metapixelshopaholic/.editorconfig
must_haves:
  truths:
    - "composer qa exits zero on a fresh clone inside plugin root"
    - "phpstan analyse runs at level 10 with larastan + disallowed-calls + universalObjectCrates and reports 0 errors"
    - "phpmd runs the Toolbox-derived ruleset with LongVariable max=40 and reports 0 warnings on the scaffold"
    - "pint --test exits clean against pint.json (Laravel preset + ordered_imports alpha + no_unused_imports + single_quote + binary_operator_spaces single_space)"
    - "Pest exits zero — a single trivial test passes or zero tests is acceptable"
    - "GitHub Actions metapixel-qa.yml runs composer install + composer qa on PHP 8.4 for push to master and PRs touching plugins/logingrupa/metapixelshopaholic/**"
    - "Plugin namespace is Logingrupa\\Metapixelshopaholic (not LoginGrupa) and the package name is logingrupa/oc-metapixel-plugin"
  artifacts:
    - path: "composer.json"
      provides: "Composer package definition + qa script chain (pint-test → analyse → phpmd → test-cov)"
    - path: "phpstan.neon"
      provides: "phpstan level 10 + larastan + universalObjectCrates + disallowed-calls for assert() and @ suppression"
    - path: "phpmd.xml"
      provides: "Toolbox PHPMD ruleset with LongVariable max=40"
    - path: "pint.json"
      provides: "Laravel preset + ordered_imports alpha + no_unused_imports + single_quote + binary_operator_spaces single_space"
    - path: "rector.php"
      provides: "PHP 8.4 upgrade set + CODE_QUALITY + DEAD_CODE + EARLY_RETURN + TYPE_DECLARATION"
    - path: "tests/MetapixelTestCase.php"
      provides: "October CMS test harness (PHPUnit 12 / Pest 4 compatible) mirroring CampaignPricingTestCase exactly"
    - path: "tests/Pest.php"
      provides: "Pest bootstrap binding MetapixelTestCase to tests/Unit and tests/Feature"
    - path: ".github/workflows/metapixel-qa.yml"
      provides: "CI gate running composer qa on PHP 8.4 for push to master and PRs touching the plugin"
    - path: "Plugin.php"
      provides: "Minimal plugin shell so October recognises the plugin during Pest boot (no boot/register logic; deferred to Phase 2)"
  key_links:
    - from: "composer.json scripts.qa"
      to: "pint-test → analyse → phpmd → test-cov"
      via: "Composer script aliases resolved relative to plugin root"
    - from: "phpstan.neon includes"
      to: "../../../vendor/larastan/larastan/extension.neon + ../../../vendor/spaze/phpstan-disallowed-calls/extension.neon"
      via: "Sibling-vendor path (plugin lives 3 levels below repo root)"
    - from: "tests/Pest.php uses()"
      to: "MetapixelTestCase"
      via: "Pest binding so every test in tests/Unit + tests/Feature runs through October's harness"
    - from: ".github/workflows/metapixel-qa.yml"
      to: "composer qa"
      via: "actions runner executes the same script chain a developer runs locally"
---

<objective>
Ship the empty plugin scaffold so that `composer qa` exits zero on a fresh clone. No business code. This phase locks the quality bar (PHP 8.4, phpstan level 10, Toolbox-flavoured phpmd, Laravel Pint, Rector, Pest 4 + October harness, GitHub Actions) so every subsequent phase fails loudly the moment it slips.

Purpose: subsequent phases (Skeleton → Purchase → Funnel → Hardening) can safely add code knowing pint/phpstan/phpmd/pest run green out of the gate. The CI workflow makes that gate visible on every PR.

Output: 13 scaffold files at the plugin root; nothing under `classes/`, `components/`, `models/`, `middleware/`, or `updates/` yet — those land in Phase 2+.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
</execution_context>

<context>
@plugins/logingrupa/metapixelshopaholic/.planning/PROJECT.md
@plugins/logingrupa/metapixelshopaholic/.planning/REQUIREMENTS.md
@plugins/logingrupa/metapixelshopaholic/.planning/ROADMAP.md
@plugins/logingrupa/metapixelshopaholic/.planning/STATE.md
@plugins/logingrupa/metapixelshopaholic/.planning/phases/01-tooling/01-CONTEXT.md

# Reference scaffold (Logingrupa sibling plugin — pattern source, NOT to copy verbatim)
@plugins/logingrupa/campaignpricingshopaholic/composer.json
@plugins/logingrupa/campaignpricingshopaholic/phpstan.neon
@plugins/logingrupa/campaignpricingshopaholic/pint.json
@plugins/logingrupa/campaignpricingshopaholic/rector.php
@plugins/logingrupa/campaignpricingshopaholic/phpunit.xml
@plugins/logingrupa/campaignpricingshopaholic/phpmd.xml
@plugins/logingrupa/campaignpricingshopaholic/tests/CampaignPricingTestCase.php

# Verbatim source for phpmd.xml (the LongVariable max=40 variant)
@plugins/lovata/toolbox/PHPMD_custom.xml

# Repo conventions
@phpcs.xml
@phpunit.xml
</context>

<interfaces>
<!-- Locked from REQUIREMENTS.md, PROJECT.md, 01-CONTEXT.md. Executor must not deviate. -->

Plugin namespace: `Logingrupa\Metapixelshopaholic` (NOT `LoginGrupa`)
Composer package name: `logingrupa/oc-metapixel-plugin`
October installer-name: `metapixelshopaholic`
Plugin code (October): `Logingrupa.Metapixelshopaholic`

Plugin root (absolute): `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixelshopaholic/`
Repo root (absolute): `/home/forge/nailscosmetics.lv/`
Plugin is exactly 3 levels deep — vendor paths are `../../../vendor/...` from plugin root.

Required runtime deps (TOOL-01):
  php ^8.4
  october/system ^4.0
  october/rain ^4.0
  lovata/toolbox-plugin ^2.2
  lovata/ordersshopaholic-plugin ^1.33
  lovata/shopaholic-plugin ^1.32
  lovata/buddies-plugin ^1.10
  guzzlehttp/guzzle ^7.8
  ramsey/uuid ^4.7

Required dev deps (TOOL-01):
  pestphp/pest ^4.1
  pestphp/pest-plugin-drift ^4.0
  phpunit/phpunit ^12
  larastan/larastan ^3.0
  spaze/phpstan-disallowed-calls ^4.0
  phpmd/phpmd ^2.15
  laravel/pint ^1.26
  rector/rector ^2.0
  mockery/mockery ^1.6

composer scripts (TOOL-01):
  test-cov   → ../../../vendor/bin/pest --configuration phpunit.xml --coverage
  test       → ../../../vendor/bin/pest --configuration phpunit.xml
  analyse    → ../../../vendor/bin/phpstan analyse --configuration=phpstan.neon
  baseline   → ../../../vendor/bin/phpstan analyse --configuration=phpstan.neon --generate-baseline=phpstan-baseline.neon
  phpmd      → ../../../vendor/bin/phpmd Plugin.php text phpmd.xml   # only Plugin.php exists in Phase 1; later phases extend
  pint       → ../../../vendor/bin/pint . --config=pint.json
  pint-test  → ../../../vendor/bin/pint . --config=pint.json --test
  rector-dry → ../../../vendor/bin/rector process --config=rector.php --dry-run
  rector     → ../../../vendor/bin/rector process --config=rector.php
  qa         → ["@pint-test", "@analyse", "@phpmd", "@test-cov"]

PSR-4 autoload:
  "Logingrupa\\Metapixelshopaholic\\": ""

October extra block:
  "october": { "plugin": "Logingrupa.Metapixelshopaholic", "installer-name": "metapixelshopaholic" }

phpstan.neon must declare:
  level: 10
  paths: [Plugin.php]                    # tests/, lang/, partials/, updates/, .github/ excluded
  excludePaths: [tests, updates, lang, partials, .github]
  tmpDir: ../../../storage/temp/phpstan/metapixel
  reportUnmatchedIgnoredErrors: true
  treatPhpDocTypesAsCertain: true
  checkUninitializedProperties: true
  universalObjectCratesClasses:
    - Lovata\Toolbox\Classes\Item\ElementItem
    - Lovata\Toolbox\Classes\Collection\ElementCollection
  disallowedFunctionCalls:
    - function: 'assert()'
      message: 'use throw — assert() is a silent no-op when zend.assertions=0 (production default)'
    - function: '@'                       # spaze syntax for error-suppression operator
      message: 'no @ suppression — handle errors explicitly'
  includes:
    - ../../../vendor/larastan/larastan/extension.neon
    - ../../../vendor/spaze/phpstan-disallowed-calls/extension.neon

phpmd.xml: verbatim copy of plugins/lovata/toolbox/PHPMD_custom.xml with the single edit `LongVariable maximum` 25 → 40. All other rules preserved exactly.

pint.json: Laravel preset (NOT psr12 as in CampaignPricing — REQUIREMENTS.md TOOL-04 explicitly says Laravel) plus:
  ordered_imports: { sort_algorithm: alpha }
  no_unused_imports: true
  single_quote: true
  binary_operator_spaces: { default: single_space }
  exclude: [updates]

rector.php: paths = [Plugin.php]; LevelSetList::UP_TO_PHP_84 + SetList::CODE_QUALITY + SetList::DEAD_CODE + SetList::EARLY_RETURN + SetList::TYPE_DECLARATION; skip [lang, updates, tests, partials, .github].

tests/MetapixelTestCase.php: byte-for-byte mirror of CampaignPricingTestCase.php, with the single change namespace `Logingrupa\Metapixelshopaholic\Tests` and class name `MetapixelTestCase`. Bootstrap path inside `createApplication()` stays `__DIR__.'/../../../../bootstrap/app.php'` (Pest runs from plugin root → tests/ → 4 levels up to repo root).

tests/Pest.php: `uses(Logingrupa\Metapixelshopaholic\Tests\MetapixelTestCase::class)->in('Unit', 'Feature');`

phpunit.xml at plugin root:
  bootstrap: ../../../modules/system/tests/bootstrap.php
  testsuite: tests/Unit + tests/Feature directories
  env: APP_ENV=testing, CACHE_DRIVER=array, SESSION_DRIVER=array, DB_CONNECTION=sqlite, DB_DATABASE=:memory:

Plugin.php skeleton:
  namespace Logingrupa\Metapixelshopaholic;
  class Plugin extends \System\Classes\PluginBase
  {
      public function pluginDetails(): array
      {
          return [
              'name'        => 'Metapixel Shopaholic',
              'description' => 'Meta Pixel + CAPI server-deduplicated tracking for Lovata Shopaholic.',
              'author'      => 'Logingrupa',
              'icon'        => 'icon-shopping-cart',
          ];
      }
  }
  No boot(), no register(), no event subscribers — those land in Phase 2 (SKEL-01).

tests/Unit/SanityTest.php (Pest): one trivial passing test so Pest does not exit with "no tests" non-zero on some configurations:
  it('boots the october harness', function () { expect(true)->toBeTrue(); });

.github/workflows/metapixel-qa.yml:
  trigger: push to master + pull_request paths-filtering plugins/logingrupa/metapixelshopaholic/**
  matrix: php 8.4
  steps: checkout, setup-php@v2 with php-version 8.4, composer install --no-progress --prefer-dist, composer qa (run from plugin dir)
  NOTE: workflow runs from repo root context; cd into plugin dir before composer install.

.gitignore at plugin root:
  /vendor/
  /.phpunit.result.cache
  /.phpunit.cache/
  /phpstan-baseline.neon          # generated on demand, not committed in Phase 1
  /.phpstan-cache/
  /node_modules/
  /.idea/
  /.vscode/

.editorconfig (Claude's discretion — minimal, repo-consistent):
  root = true
  [*]
  charset = utf-8
  end_of_line = lf
  insert_final_newline = true
  trim_trailing_whitespace = true
  indent_style = space
  indent_size = 4
  [*.{yml,yaml}]
  indent_size = 2
  [*.md]
  trim_trailing_whitespace = false
</interfaces>

## Goal

Land the quality-bar plumbing for `Logingrupa.Metapixelshopaholic` so that `composer qa` exits zero on a fresh clone inside the plugin root. Every config file is wired to the locked decisions in REQUIREMENTS.md TOOL-01..TOOL-08 and 01-CONTEXT.md. No business code is written in this phase — only the scaffold, the minimal `Plugin.php` stub October needs to recognise the plugin during Pest boot, and a single trivial passing test. CI runs the same `composer qa` chain on every push to master and on PRs touching the plugin path.

## Requirements covered

| Req     | Task(s)         | Notes |
|---------|-----------------|-------|
| TOOL-01 | Task 1          | composer.json with deps + qa script chain (pint-test → analyse → phpmd → test-cov). |
| TOOL-02 | Task 2          | phpstan.neon level 10 + larastan + spaze/phpstan-disallowed-calls (assert + @) + universalObjectCrates for ElementItem and ElementCollection + reportUnmatchedIgnoredErrors + treatPhpDocTypesAsCertain + checkUninitializedProperties. |
| TOOL-03 | Task 3          | phpmd.xml = verbatim Toolbox copy with LongVariable max=40 (was 25). |
| TOOL-04 | Task 4          | pint.json Laravel preset + ordered_imports alpha + no_unused_imports + single_quote + binary_operator_spaces single_space + exclude updates. |
| TOOL-05 | Task 4          | rector.php with LevelSetList::UP_TO_PHP_84 + CODE_QUALITY + DEAD_CODE + EARLY_RETURN + TYPE_DECLARATION (bundled with pint in Task 4 because both are style/automation configs). |
| TOOL-06 | Task 5          | Pest scaffold — Pest.php, MetapixelTestCase.php (mirror CampaignPricingTestCase), phpunit.xml, Unit/SanityTest.php, minimal Plugin.php so October recognises the plugin during boot. |
| TOOL-07 | Task 6          | .github/workflows/metapixel-qa.yml runs composer install + composer qa on PHP 8.4 for push to master + PRs touching plugin path. |
| TOOL-08 | Verification    | The full end-of-plan verification block runs `composer install` + `composer qa` and asserts exit zero. |

## Files to create

Absolute paths under `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixelshopaholic/`:

- `composer.json`
- `phpstan.neon`
- `phpmd.xml`
- `pint.json`
- `rector.php`
- `phpunit.xml`
- `Plugin.php`
- `tests/Pest.php`
- `tests/MetapixelTestCase.php`
- `tests/Unit/SanityTest.php`
- `.github/workflows/metapixel-qa.yml`
- `.gitignore`
- `.editorconfig`

No files outside the plugin root are created. The plugin's own `composer.json` lives at the plugin root — the repo-level `/home/forge/nailscosmetics.lv/composer.json` is NOT modified.

## Tasks

### Task 1: composer.json with deps + qa chain

Wires the package, all runtime and dev deps from TOOL-01, PSR-4 autoload, October `extra` block, and the seven composer scripts (`test`, `test-cov`, `analyse`, `baseline`, `phpmd`, `pint`, `pint-test`, `rector-dry`, `rector`) plus the `qa` aggregate that chains `pint-test → analyse → phpmd → test-cov`. Required because every other task depends on the composer autoloader + script resolver.

- **files_modified:** `plugins/logingrupa/metapixelshopaholic/composer.json`
- **verification:**
  ```
  cd /home/forge/nailscosmetics.lv/plugins/logingrupa/metapixelshopaholic \
    && php -r '$j=json_decode(file_get_contents("composer.json"),true); \
      $req=$j["require"]; $dev=$j["require-dev"]; $qa=$j["scripts"]["qa"]; \
      $need=["php","october/system","october/rain","lovata/toolbox-plugin","lovata/ordersshopaholic-plugin","lovata/shopaholic-plugin","lovata/buddies-plugin","guzzlehttp/guzzle","ramsey/uuid"]; \
      foreach($need as $k){ if(!isset($req[$k])){fwrite(STDERR,"missing require: $k\n");exit(1);} } \
      $needDev=["pestphp/pest","pestphp/pest-plugin-drift","phpunit/phpunit","larastan/larastan","spaze/phpstan-disallowed-calls","phpmd/phpmd","laravel/pint","rector/rector","mockery/mockery"]; \
      foreach($needDev as $k){ if(!isset($dev[$k])){fwrite(STDERR,"missing require-dev: $k\n");exit(1);} } \
      $want=["@pint-test","@analyse","@phpmd","@test-cov"]; \
      if($qa !== $want){fwrite(STDERR,"qa chain wrong: ".json_encode($qa)."\n");exit(1);} \
      if($j["name"] !== "logingrupa/oc-metapixel-plugin"){fwrite(STDERR,"wrong package name\n");exit(1);} \
      if($j["extra"]["october"]["plugin"] !== "Logingrupa.Metapixelshopaholic"){fwrite(STDERR,"wrong october plugin code\n");exit(1);} \
      echo "OK\n";'
  ```
- **assertion:** `composer.json` declares the locked package name `logingrupa/oc-metapixel-plugin`, every TOOL-01 runtime and dev dep is present, `scripts.qa` equals `["@pint-test","@analyse","@phpmd","@test-cov"]`, and the October `extra` block uses plugin code `Logingrupa.Metapixelshopaholic`.

### Task 2: phpstan.neon level 10 + larastan + disallowed-calls + universalObjectCrates

Creates `phpstan.neon` at the plugin root with level 10, both extensions, both universalObjectCrates classes (ElementItem AND ElementCollection — REQUIREMENTS.md TOOL-02 lists both), the three strictness flags, and the `disallowedFunctionCalls` block banning `assert()` and `@` suppression with explanatory messages. tmpDir scoped to `../../../storage/temp/phpstan/metapixel`.

- **files_modified:** `plugins/logingrupa/metapixelshopaholic/phpstan.neon`
- **verification:**
  ```
  cd /home/forge/nailscosmetics.lv/plugins/logingrupa/metapixelshopaholic \
    && grep -E '^[[:space:]]*level:[[:space:]]*10[[:space:]]*$' phpstan.neon \
    && grep -F 'larastan/extension.neon' phpstan.neon \
    && grep -F 'spaze/phpstan-disallowed-calls' phpstan.neon \
    && grep -F 'Lovata\Toolbox\Classes\Item\ElementItem' phpstan.neon \
    && grep -F 'Lovata\Toolbox\Classes\Collection\ElementCollection' phpstan.neon \
    && grep -F 'reportUnmatchedIgnoredErrors: true' phpstan.neon \
    && grep -F 'treatPhpDocTypesAsCertain: true' phpstan.neon \
    && grep -F 'checkUninitializedProperties: true' phpstan.neon \
    && grep -F "function: 'assert()'" phpstan.neon \
    && echo OK
  ```
- **assertion:** phpstan.neon declares level 10, includes larastan + disallowed-calls extensions, lists both ElementItem and ElementCollection as universalObjectCrates, has all three strictness flags set to true, and bans `assert()` via `disallowedFunctionCalls`.

### Task 3: phpmd.xml = Toolbox copy with LongVariable max=40

Copies `plugins/lovata/toolbox/PHPMD_custom.xml` to the plugin root as `phpmd.xml` and edits the single `LongVariable maximum` value from 25 to 40. Every other rule (CyclomaticComplexity reportLevel=10, ExcessiveClassLength minimum=1000, ShortVariable minimum=4, etc.) is preserved verbatim. The ruleset `name` attribute is updated to `MetapixelShopaholic`.

- **files_modified:** `plugins/logingrupa/metapixelshopaholic/phpmd.xml`
- **verification:**
  ```
  cd /home/forge/nailscosmetics.lv/plugins/logingrupa/metapixelshopaholic \
    && grep -A1 'LongVariable' phpmd.xml | grep -F 'value="40"' \
    && grep -A1 'ShortVariable' phpmd.xml | grep -F 'value="4"' \
    && grep -A2 'CyclomaticComplexity' phpmd.xml | grep -F 'reportLevel' | grep -F 'value="10"' \
    && grep -A1 'ExcessiveClassLength' phpmd.xml | grep -F 'value="1000"' \
    && echo OK
  ```
- **assertion:** `phpmd.xml` is the Toolbox ruleset verbatim except `LongVariable maximum=40` (was 25); CyclomaticComplexity reportLevel=10, ExcessiveClassLength minimum=1000, and ShortVariable minimum=4 are unchanged.

### Task 4: pint.json + rector.php

Both are style/automation configs and share a single edit window. `pint.json` uses the Laravel preset (NOT psr12 — REQUIREMENTS.md TOOL-04 says Laravel explicitly) with `ordered_imports.sort_algorithm: alpha`, `no_unused_imports: true`, `single_quote: true`, `binary_operator_spaces.default: single_space`, and `exclude: ["updates"]`. `rector.php` declares paths `[Plugin.php]`, applies `LevelSetList::UP_TO_PHP_84` + `SetList::CODE_QUALITY` + `SetList::DEAD_CODE` + `SetList::EARLY_RETURN` + `SetList::TYPE_DECLARATION`, and skips `[lang, updates, tests, partials, .github]`.

- **files_modified:**
  - `plugins/logingrupa/metapixelshopaholic/pint.json`
  - `plugins/logingrupa/metapixelshopaholic/rector.php`
- **verification:**
  ```
  cd /home/forge/nailscosmetics.lv/plugins/logingrupa/metapixelshopaholic \
    && php -r 'exit((json_decode(file_get_contents("pint.json"),true)["preset"]==="laravel")?0:1);' \
    && grep -F '"sort_algorithm": "alpha"' pint.json \
    && grep -F '"no_unused_imports": true' pint.json \
    && grep -F '"single_quote": true' pint.json \
    && grep -F 'binary_operator_spaces' pint.json \
    && grep -F '"updates"' pint.json \
    && grep -F 'LevelSetList' rector.php \
    && grep -F 'php84: true' rector.php \
    && grep -F 'codeQuality: true' rector.php \
    && grep -F 'deadCode: true' rector.php \
    && grep -F 'typeDeclarations: true' rector.php \
    && grep -F 'EARLY_RETURN' rector.php \
    && echo OK
  ```
- **assertion:** `pint.json` has the Laravel preset plus the four locked rules and excludes `updates`; `rector.php` targets PHP 8.4 with all four locked sets (CODE_QUALITY, DEAD_CODE, EARLY_RETURN, TYPE_DECLARATION).

### Task 5: Pest scaffold + minimal Plugin.php + phpunit.xml + sanity test

Mirror `CampaignPricingTestCase.php` byte-for-byte into `tests/MetapixelTestCase.php` (changing only the namespace to `Logingrupa\Metapixelshopaholic\Tests` and class name to `MetapixelTestCase`). Create `tests/Pest.php` binding the test case to `Unit` and `Feature` directories. Create `phpunit.xml` at the plugin root pointing bootstrap to `../../../modules/system/tests/bootstrap.php` with testsuite directories `tests/Unit` + `tests/Feature` and the SQLite-in-memory env block. Create `tests/Unit/SanityTest.php` with a single passing Pest assertion. Create the minimal `Plugin.php` shell (namespace `Logingrupa\Metapixelshopaholic`, class `Plugin extends \System\Classes\PluginBase`, only `pluginDetails(): array`).

- **files_modified:**
  - `plugins/logingrupa/metapixelshopaholic/tests/MetapixelTestCase.php`
  - `plugins/logingrupa/metapixelshopaholic/tests/Pest.php`
  - `plugins/logingrupa/metapixelshopaholic/tests/Unit/SanityTest.php`
  - `plugins/logingrupa/metapixelshopaholic/phpunit.xml`
  - `plugins/logingrupa/metapixelshopaholic/Plugin.php`
- **verification:**
  ```
  cd /home/forge/nailscosmetics.lv/plugins/logingrupa/metapixelshopaholic \
    && grep -F 'namespace Logingrupa\Metapixelshopaholic\Tests' tests/MetapixelTestCase.php \
    && grep -F 'abstract class MetapixelTestCase extends TestCase' tests/MetapixelTestCase.php \
    && grep -F 'InteractsWithAuthentication' tests/MetapixelTestCase.php \
    && grep -F 'PerformsMigrations' tests/MetapixelTestCase.php \
    && grep -F 'PerformsRegistrations' tests/MetapixelTestCase.php \
    && grep -F 'protected function setUp(): void' tests/MetapixelTestCase.php \
    && grep -F 'MetapixelTestCase::class' tests/Pest.php \
    && grep -F 'namespace Logingrupa\Metapixelshopaholic;' Plugin.php \
    && grep -F 'extends \System\Classes\PluginBase' Plugin.php \
    && grep -F 'pluginDetails(): array' Plugin.php \
    && ! grep -F 'function boot' Plugin.php \
    && ! grep -F 'function register' Plugin.php \
    && grep -F '../../../modules/system/tests/bootstrap.php' phpunit.xml \
    && grep -F 'DB_DATABASE' phpunit.xml | grep -F ':memory:' \
    && echo OK
  ```
- **assertion:** `MetapixelTestCase` mirrors the CampaignPricing pattern (same traits + protected `setUp(): void`), `Pest.php` binds it to Unit + Feature, `Plugin.php` has only `pluginDetails()` (no `boot()`/`register()`), `phpunit.xml` boots October's harness against SQLite-in-memory, and a trivial Pest assertion exists in `tests/Unit/SanityTest.php`.

### Task 6: GitHub Actions workflow + .gitignore + .editorconfig

Create `.github/workflows/metapixel-qa.yml` with triggers `on.push.branches: [master]` and `on.pull_request.paths: ['plugins/logingrupa/metapixelshopaholic/**']`; single PHP 8.4 job that checks out, sets up PHP 8.4, runs `composer install --no-progress --prefer-dist` inside the plugin directory, then runs `composer qa` inside the same directory. Create `.gitignore` (vendor, .phpunit.result.cache, etc.) and `.editorconfig` (4-space PHP, 2-space YAML, LF endings).

- **files_modified:**
  - `plugins/logingrupa/metapixelshopaholic/.github/workflows/metapixel-qa.yml`
  - `plugins/logingrupa/metapixelshopaholic/.gitignore`
  - `plugins/logingrupa/metapixelshopaholic/.editorconfig`
- **verification:**
  ```
  cd /home/forge/nailscosmetics.lv/plugins/logingrupa/metapixelshopaholic \
    && grep -F 'php-version' .github/workflows/metapixel-qa.yml | grep -F "'8.4'" \
    && grep -F 'composer install' .github/workflows/metapixel-qa.yml \
    && grep -F 'composer qa' .github/workflows/metapixel-qa.yml \
    && grep -F 'plugins/logingrupa/metapixelshopaholic/**' .github/workflows/metapixel-qa.yml \
    && grep -F 'branches:' .github/workflows/metapixel-qa.yml | head -1 \
    && grep -F '/vendor' .gitignore \
    && grep -F '.phpunit.result.cache' .gitignore \
    && grep -F 'end_of_line = lf' .editorconfig \
    && echo OK
  ```
- **assertion:** the workflow file triggers on push to master + PRs touching the plugin path, runs on PHP 8.4, and executes `composer install` followed by `composer qa` inside the plugin dir; `.gitignore` ignores vendor and phpunit cache files; `.editorconfig` enforces LF line endings.

## Verification

End-to-end gate. Run from a fresh clone — `vendor/` may or may not exist at repo level. Plugin lives 3 levels below repo root, so `composer install` is the repo-level command that pulls in larastan, phpstan, phpmd, pest, pint, rector, etc. The plugin's `composer.json` declares its own deps for marketplace consumption, but in the dev tree we rely on the repo-level vendor.

```bash
# 1) Repo-level dependency resolution (pulls phpstan/larastan/pest/pint/phpmd/rector binaries to ../../../vendor/bin)
cd /home/forge/nailscosmetics.lv
composer install --no-interaction --no-progress --prefer-dist

# 2) Plugin-level QA gate
cd /home/forge/nailscosmetics.lv/plugins/logingrupa/metapixelshopaholic
composer install --no-interaction --no-progress --prefer-dist || true   # plugin composer.json may be a no-op in dev tree; tolerate
composer qa
echo "qa exit: $?"   # must be 0

# 3) Spot-checks on the qa sub-steps so a future regression is bisectable
../../../vendor/bin/pint . --config=pint.json --test
../../../vendor/bin/phpstan analyse --configuration=phpstan.neon
../../../vendor/bin/phpmd Plugin.php text phpmd.xml
../../../vendor/bin/pest --configuration phpunit.xml

# 4) Confirm the disallowed-calls extension actually trips on assert() — meta-check
echo '<?php namespace Logingrupa\Metapixelshopaholic; class _Probe { public function x(): void { assert(true); } }' > /tmp/_probe.php
../../../vendor/bin/phpstan analyse --configuration=phpstan.neon --no-progress /tmp/_probe.php && { echo "FAIL: disallowed-calls did not catch assert()"; exit 1; } || echo "OK: assert() blocked"
rm -f /tmp/_probe.php
```

Pass criteria (all must hold):
- Step 2: `composer qa` exits zero.
- Step 3: every sub-step exits zero independently.
- Step 4: phpstan rejects an `assert()` call in a probe file (proves the disallowed-calls extension is wired, not just included as text).

## Open questions

None. All eight TOOL-XX requirements are fully locked by REQUIREMENTS.md and 01-CONTEXT.md; the only Claude's-discretion items (.gitignore content, .editorconfig content, the precise `tmpDir` path for phpstan) have explicit defaults specified above. No deferral back to user.
