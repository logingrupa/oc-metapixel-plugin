---
phase: 01-tooling-composer-namespace-rename-ci-matrix
verified: 2026-05-16T07:25:00Z
status: passed
score: 7/12 must-haves verified (4 SCs + 7 of 11 TOOL-* fully satisfied; 4 partial/failed)
overrides_applied: 0
gaps:
  - truth: "TOOL-01 — `lovata/shopaholic-plugin` + `lovata/ordersshopaholic-plugin` + `lovata/buddies-plugin` stay in `require-dev:` so test suite exercises ShopaholicAdapter"
    status: failed
    reason: "Plugin composer.json require-dev contains NO `lovata/*-plugin` entries. TOOL-01 spec verbatim: 'Stay in require-dev: so test suite exercises ShopaholicAdapter.' All three plans skipped adding these — plan 01-01 deferred to 01-02, plan 01-02 added 10 dev deps but no lovata, plan 01-03 added Pest scaffold but no lovata require-dev. Downstream: SC2 Run A vs Run B distinction is functionally meaningless because the plugin install identical in both."
    artifacts:
      - path: "composer.json"
        issue: "require-dev missing lovata/shopaholic-plugin, lovata/ordersshopaholic-plugin, lovata/buddies-plugin"
    missing:
      - "Add `lovata/shopaholic-plugin: ^1.32` to require-dev"
      - "Add `lovata/ordersshopaholic-plugin: ^1.33` to require-dev"
      - "Add `lovata/buddies-plugin: ^1.10` to require-dev"

  - truth: "SC2 — CI matrix Run A (full-lovata) vs Run B (minimal) is meaningful: Run A has Lovata cart plugins installed; Run B does not"
    status: failed
    reason: "Because lovata/* plugins are NOT in the plugin's require-dev (gap above), the plugin-level `composer install` produces IDENTICAL output in both Run A and Run B. The CI workflow's `composer remove --dev ... lovata/shopaholic-plugin` in plugin scope is a no-op (with `|| true` swallowing the error). Run B's only real difference is the parent forge repo's composer (host vendor), which the plugin's tests do not depend on for their hermetic SQLite setup. The full-lovata / minimal distinction has no codepath consequence at Phase 1."
    artifacts:
      - path: ".github/workflows/metapixel-qa.yml"
        issue: "Run-B plugin-level `composer remove` operates on packages that aren't there"
      - path: "composer.json"
        issue: "Plugin require-dev does not differ by install mode"
    missing:
      - "Add lovata cart plugins to require-dev so Run-B's removal is observable"
      - "Confirm Run A's adapter test path actually loads Lovata classes after install"

  - truth: "SC2 — `--exclude-testsuite='Metapixel Adapter Tests'` excludes a real testsuite under Run B"
    status: failed
    reason: "phpunit.xml defines only TWO testsuites: 'Metapixel Unit Tests' and 'Metapixel Feature Tests'. There is no 'Metapixel Adapter Tests' testsuite. Pest silently accepts unknown --exclude-testsuite values (verified locally: exit 0, no warning). Therefore Run B's exclusion is a no-op. STATE.md Pending Todos acknowledges this: 'Phase 3 SHOP-* adds <testsuite name=\"Metapixel Adapter Tests\"> block to phpunit.xml when tests/Unit/Adapter/Shopaholic + tests/Feature/Adapter/Shopaholic land' — meaning Phase 1 ships a forward-reference exclude that does not exclude anything yet."
    artifacts:
      - path: "phpunit.xml"
        issue: "No <testsuite name='Metapixel Adapter Tests'> block"
      - path: ".github/workflows/metapixel-qa.yml"
        issue: "Run B references a non-existent testsuite"
    missing:
      - "Add <testsuite name='Metapixel Adapter Tests'> entry to phpunit.xml pointing at tests/Unit/Adapter/Shopaholic + tests/Feature/Adapter/Shopaholic (subdirs land in Phase 3; the entry can pre-exist empty), OR document the no-op explicitly in CI yaml comment, OR rely on a positive --testsuite include instead."

  - truth: "SC3 / TOOL-04 — phpstan rejects #[\\Deprecated] attribute as PHP 8.4-only"
    status: failed
    reason: "phpstan.neon declares the ban via `disallowedClasses: [{class: 'Deprecated', ...}]`. Empirically (verified probe at /tmp/php84_deprecated_attr.php), this rule does NOT fire on `#[\\Deprecated('old')]`. The correct shipmonk extension key for attributes is `disallowedAttributes:`. Probe with `disallowedAttributes: [{attribute: 'Deprecated', ...}]` against the same file fires with: 'Attribute Deprecated is forbidden, PHP 8.4-only attribute' — confirming the fix path. SC3 verbatim: 'operator-authored snippet using property hooks / asymmetric visibility / array_find / #[\\Deprecated] fails CI with a clear error.' Currently three of those four fail correctly; #[\\Deprecated] does NOT fail CI."
    artifacts:
      - path: "phpstan.neon"
        issue: "Uses disallowedClasses for an attribute — wrong rule type"
    missing:
      - "Replace `disallowedClasses: [{class: 'Deprecated', ...}]` with `disallowedAttributes: [{attribute: 'Deprecated', message: 'PHP 8.4-only attribute — use @deprecated docblock instead'}]`"
      - "Re-run probe to confirm fix"

  - truth: "SC4 / TOOL-11 — composer-dependency-analyser would flag a hidden `use Lovata\\OrdersShopaholic\\Models\\Order` outside `classes/adapter/shopaholic/` namespace (prevents P-03)"
    status: failed
    reason: "Two structural blockers: (1) Global `ignoreErrorsOnPackage('lovata/shopaholic-plugin', [DEV_DEPENDENCY_IN_PROD])` is declared BEFORE the path-scoped allowlist — it suppresses the error globally, rendering the path-scoped `ignoreErrorsOnPackageAndPath` redundant. Adding the Lovata package as a path-scoped exception is only meaningful if the global ignore is removed. (2) `lovata/shopaholic-plugin` is NOT in `require:` nor `require-dev:` of plugin composer.json — only in `suggest:`. shipmonk cannot classify imports as DEV_DEPENDENCY_IN_PROD when the package isn't a composer dep at all. The intended P-03 prevention requires the package to be a `require-dev` entry (gap #1 above), then the analyser's path-scoped allowlist works."
    artifacts:
      - path: "composer-dependency-analyser.php"
        issue: "Global package-level ignore defeats path-scoped allowlist"
      - path: "composer.json"
        issue: "Lovata cart packages aren't declared as composer deps — analyser can't see them"
    missing:
      - "Add lovata cart packages to require-dev (gap #1)"
      - "Remove the global `ignoreErrorsOnPackage(...)` directives for lovata packages and keep only the `ignoreErrorsOnPackageAndPath` directives"
      - "Add a CI probe that inserts `use Lovata\\OrdersShopaholic\\Models\\Order;` into a non-adapter file and asserts the analyser flags it"

  - truth: "REQUIREMENTS.md traceability table marks TOOL-01, TOOL-02, TOOL-03 as `Pending`"
    status: failed
    reason: "Plan 01-01 SUMMARY claims TOOL-01/02/03 satisfied (rename + namespace + composer.json shape). Plan 01-03 SUMMARY ('Phase 1 Closure Note') lists all 11 TOOL-* as Done. REQUIREMENTS.md table still shows TOOL-01/02/03 as Pending. Deferred-items.md acknowledges this drift. Even setting aside the TOOL-01 lovata require-dev gap above, TOOL-02 (rename) and TOOL-03 (namespace rewrite) ARE truly satisfied — the table is just stale."
    artifacts:
      - path: ".planning/REQUIREMENTS.md"
        issue: "Traceability table lines 184-186 still show Pending"
      - path: ".planning/REQUIREMENTS.md"
        issue: "Top-level checkboxes (lines 13-15) still `- [ ]` not `- [x]`"
    missing:
      - "Flip TOOL-02 and TOOL-03 checkboxes + traceability rows to satisfied"
      - "TOOL-01 status depends on resolving the lovata require-dev gap first"

deferred:
  - truth: "ROADMAP wording inconsistency: SC5 says 'Two-tier' but Phase Details line 167 says 'Three-tier'"
    addressed_in: "Phase 2"
    evidence: "Phase 2 ADAP-03 wires AdapterRegistry::flush() into MetapixelTestCase::flushModelEventListeners — STATE.md Pending Todos line acknowledges this. The third tier (ADAP-specific FakeAdapter base?) materializes when ADAP-* lands. Phase 1 ships two tiers as currently spec'd in TOOL-08."

  - truth: "composer-dependency-analyser binary not smoke-tested locally"
    addressed_in: "CI matrix (Phase 1's own CI cells)"
    evidence: "Once a real PR fires the CI workflow with the plugin's `composer install` populating vendor/, `composer run-script deps` runs against shipmonk binary. Cannot be probed standalone on this host (plugin composer install blocked by October-private packages not on packagist — documented in plan 01-01 / 01-02 / 01-03 Deviations sections)."
---

# Phase 1: Tooling + composer + namespace rename + CI matrix — Verification Report

**Phase Goal:** A fresh clone of the renamed plugin (`plugins/logingrupa/metapixel/`, namespace `Logingrupa\Metapixel`) passes `composer qa` on PHP 8.3 + 8.4 in both full-Lovata and minimal install matrices, with the quality toolchain wired correctly before any business-logic refactor begins.

**Verified:** 2026-05-16T07:25:00Z
**Status:** gaps_found
**Re-verification:** No — initial verification

## Goal Achievement

### Success Criteria (ROADMAP.md)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | `composer install` on fresh clone succeeds; `composer qa` exits 0 on empty post-rename scaffold (pint-test → analyse → phpmd → test-cov chain) | PARTIAL | Smoke chain via host vendor: pint-test exit 0 / phpstan exit 0 / phpmd exit 0 / pest --coverage --min=90 exit 0 (100% Plugin.php coverage). `composer install` itself blocked standalone (October-private packages) — documented in plan 01-01 Deviation 4; expected to clear in CI matrix where host repo supplies october/system + lovata/toolbox-plugin at install time. |
| 2 | CI matrix on GitHub Actions runs `php: [8.3, 8.4]` × `install: [full-lovata, minimal]`; all four cells green on the rename PR. Full-Lovata enforces coverage gate ≥90%; minimal runs MetapixelTestCase subsets with no coverage gate. | FAILED | YAML structurally valid; matrix cells 4 cells declared; Run A invokes `--coverage --min=90`; Run B invokes `--exclude-testsuite='Metapixel Adapter Tests'`. BUT: (a) Run-B's plugin-level `composer remove --dev lovata/*` is a no-op because lovata isn't in plugin require-dev; (b) `--exclude-testsuite='Metapixel Adapter Tests'` references a testsuite that does not exist in phpunit.xml; (c) "all four cells green on rename PR" cannot be verified locally — no CI run has fired yet (`origin` not pushed). See gaps. |
| 3 | PHPStan (level 10, `phpVersion: 80300`) + Rector (`LevelSetList::UP_TO_PHP_83`) + Pint (`nullable_type_declaration_for_default_null_value`) collectively reject PHP 8.4-only syntax — operator-authored snippet using property hooks / asymmetric visibility / `array_find` / `#[\\Deprecated]` fails CI with a clear error. (Prevents **P-06**.) | FAILED | Probes against actual config: `array_find/array_find_key/array_any/array_all` → REJECTED ✓ (4/4 fire correctly). Property hooks → REJECTED ✓ via native phpstan `property.hooksNotSupported`. Asymmetric visibility → REJECTED ✓ via parser error. **`#[\\Deprecated]` → NOT REJECTED** ✗ — phpstan.neon uses wrong rule key (`disallowedClasses` instead of `disallowedAttributes`). Verified: probe with correct key fires; current config does not. SC3 is one-of-four short. |
| 4 | `shipmonk/composer-dependency-analyser` reports zero violations and would flag a hidden `use Lovata\\OrdersShopaholic\\Models\\Order` inserted anywhere outside `Classes\\Adapter\\Shopaholic\\` namespace. (Prevents **P-03**.) | FAILED | Config syntax valid (php -l clean). BUT: (1) global `ignoreErrorsOnPackage('lovata/...', [DEV_DEPENDENCY_IN_PROD])` declared BEFORE path-scoped allowlist suppresses the error everywhere — defeats the whole point. (2) `lovata/shopaholic-plugin` is not in `require:` nor `require-dev:` (only `suggest:`), so shipmonk has no package to classify against in the first place. Cannot prevent P-03 as-shipped. |
| 5 | Two-tier Pest 4 test bases instantiated: `MetapixelTestCase` (no cart-plugin deps), `ShopaholicAdapterTestCase extends MetapixelTestCase` (boots Lovata Orders table for Run A). Each tier runs in isolation without the other tier's migrations. | VERIFIED | tests/MetapixelTestCase.php (170 LOC, no `use Lovata\\*`, system_settings only). tests/ShopaholicAdapterTestCase.php (85 LOC, extends MetapixelTestCase, bootOrdersTable / bootOrdersStatuses opt-in helpers, dropHermeticSchemas tearDown). PluginSanityTest extends MetapixelTestCase only — 3 tests, 5 assertions, Plugin.php 100% coverage. Pest.php uses() bindings hold. Isolation verified by reading source. |

**Score: 1/5 success criteria verified, 1/5 partial, 3/5 failed.**

### TOOL-* Requirements (REQUIREMENTS.md)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| TOOL-01 | composer.json: name `logingrupa/oc-metapixel-plugin`, PHP `^8.3 || ^8.4`, lovata cart plugins in suggest:, stay in require-dev:, PSR-4 `Logingrupa\\Metapixel\\` | PARTIAL | Name + PHP + PSR-4 + suggest: all confirmed via `Read composer.json`. **Lovata cart plugins NOT in require-dev** — verbatim TOOL-01 violation. |
| TOOL-02 | Plugin directory renamed to `plugins/logingrupa/metapixel/` | VERIFIED | Directory exists at new path; `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixelshopaholic/` is gone. `legacy/v1.1.1` branch reachable. |
| TOOL-03 | Namespace rename to `Logingrupa\\Metapixel` everywhere | VERIFIED | `grep "Logingrupa\\\\Metapixelshopaholic" .` outside .planning returns 0 matches. Plugin.php / tests / composer.json all use new namespace. Lang keys use `logingrupa.metapixel::lang.*`. |
| TOOL-04 | phpstan.neon: phpVersion 80300, level 10, larastan, disallowed-calls bans for assert + @ + array_find* + property hooks + asymmetric visibility + #[\\Deprecated] | PARTIAL | level 10 ✓, phpVersion 80300 ✓, larastan + spaze ✓, assert() ban ✓, @ ban ✓ (declared, not probed), 4 array_* bans ✓ (probed), property hooks rejected ✓ (native phpVersion), asymmetric visibility rejected ✓ (parser), **#[\\Deprecated] NOT rejected** ✗ (wrong rule key). |
| TOOL-05 | rector.php: UP_TO_PHP_83 + 4 prepared sets | VERIFIED | `withPhpSets(php83: true)` ✓; withPreparedSets(deadCode, codeQuality, typeDeclarations, earlyReturn) ✓. No php84 references. Dry-run informational only (exit 0). |
| TOOL-06 | pint.json: Laravel preset + nullable_type_declaration_for_default_null_value + ordering + single_quote + binary_operator_spaces single_space + exclude updates | VERIFIED | All present. `pint --test` exit 0 on full tree. |
| TOOL-07 | phpmd.xml: Lovata.Toolbox-derived, LongVariable max 40, ShortVariable min 4, CyclomaticComplexity reportLevel 10, ExcessiveClassLength minimum 1000 | VERIFIED | XML valid; all thresholds present. phpmd Plugin.php exit 0. |
| TOOL-08 | Pest 4 scaffold — two test bases: MetapixelTestCase + ShopaholicAdapterTestCase | VERIFIED | Both files exist; correct inheritance; isolation pattern via opt-in bootOrdersTable. Pest.php uses() bindings present. PluginSanityTest passes. |
| TOOL-09 | CI matrix workflow: php × install 4 cells; Run A coverage ≥ 90; Run B excludes Adapter testsuite + no coverage | PARTIAL | YAML valid + 4 cells declared + Run-A --min=90 ✓ + Run-B --exclude-testsuite ✓. **BUT**: testsuite name in exclude does not exist; Run-A vs Run-B plugin install identical (lovata never in plugin deps). Structurally present, semantically degraded. |
| TOOL-10 | composer qa chain: pint-test → analyse → phpmd → test-cov exits 0 | VERIFIED | composer.json scripts.qa = ["@pint-test", "@analyse", "@phpmd", "@test-cov"] ✓. Each script exits 0 individually under host-vendor smoke. Full chain not smoke-tested as a single `composer run-script qa` invocation because standalone composer install blocked — but each link is green. |
| TOOL-11 | shipmonk dev-dep + config enforces no Lovata.OrdersShopaholic / Lovata.Shopaholic imports outside Classes\\Adapter\\Shopaholic\\ | FAILED | Dev-dep present in require-dev ✓. Config file exists + php -l clean ✓. **Enforcement broken**: global ignoreErrorsOnPackage suppresses the error globally; plus lovata isn't a require/require-dev composer package the analyser can track. Cannot prevent P-03 as-shipped. |

**Score: 7/11 TOOL-* verified, 2/11 partial, 2/11 failed.**

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `Plugin.php` | namespace Logingrupa\\Metapixel; class Plugin extends PluginBase; require ['Lovata.Toolbox']; empty boot+register | VERIFIED | Lines 1-35; 100% line coverage via PluginSanityTest. |
| `plugin.yaml` | lang-keyed name + description | VERIFIED | logingrupa.metapixel::lang.plugin.{name,description}. |
| `composer.json` | TOOL-01 shape + scripts.qa chain + require-dev | PARTIAL | TOOL-01 shape mostly correct; lovata cart plugins MISSING from require-dev. |
| `phpstan.neon` | level 10, phpVersion 80300, 4 PHP-8.4 function bans, attribute ban | PARTIAL | Function bans fire correctly. Attribute ban inert (wrong rule key). |
| `rector.php` | UP_TO_PHP_83 + 4 sets | VERIFIED | Fluent config matches spec. |
| `pint.json` | nullable rule + alpha ordering + single_quote + single_space | VERIFIED | All present. |
| `phpmd.xml` | Lovata.Toolbox baseline | VERIFIED | All thresholds present + XML valid. |
| `composer-dependency-analyser.php` | path-scoped Lovata allowlist | PARTIAL | Structurally present; semantically broken by global ignore + missing composer package. |
| `phpunit.xml` | 2 testsuites, SQLite-in-memory env, source = Plugin.php | VERIFIED (for what exists) | "Metapixel Adapter Tests" suite intentionally absent per Phase 1 plan deferral; SUMMARY documents reopen-on-Phase-3 plan. |
| `tests/MetapixelTestCase.php` | abstract, no cart deps, hermetic SQLite | VERIFIED | 170 LOC; ensureSystemSettingsTable in createApplication. |
| `tests/ShopaholicAdapterTestCase.php` | extends MetapixelTestCase + Lovata Orders helpers | VERIFIED | 85 LOC; bootOrdersTable + bootOrdersStatuses + dropHermeticSchemas override. |
| `tests/Pest.php` | uses() bindings for both bases | VERIFIED | Both bindings present. |
| `tests/Unit/PluginSanityTest.php` | smoke test (PSR-4 + lang keys + boot/register callable) | VERIFIED | 3 tests / 5 assertions / 100% Plugin.php coverage. |
| `.github/workflows/metapixel-qa.yml` | 4-cell matrix, Run A coverage, Run B exclude | PARTIAL | Structure present; Run B's exclude is structurally a no-op (gap). |

### Key Link Verification

| From | To | Via | Status | Details |
|------|-----|-----|--------|---------|
| `composer.json` scripts.qa | `pint --test` | `@pint-test` | WIRED | Script defined; binary resolves via composer vendor/bin PATH at runtime. |
| `composer.json` scripts.qa | `phpstan analyse` | `@analyse` | WIRED | Reads phpstan.neon at plugin root. |
| `composer.json` scripts.qa | `phpmd Plugin.php text phpmd.xml` | `@phpmd` | WIRED | Path argument explicit; needs reopen when classes/ lands (locked in STATE.md Pending Todos). |
| `composer.json` scripts.qa | `pest --coverage --min=90` | `@test-cov` | WIRED | Reads phpunit.xml. |
| `composer.json` scripts | `composer-dependency-analyser` | `deps` | PARTIAL | Script registered; binary not in plugin or host vendor locally; CI populates. |
| `phpstan.neon` | larastan + spaze extensions | `includes:` | WIRED | Relative `vendor/...` paths — work once plugin vendor is populated. |
| CI workflow Run B | "Metapixel Adapter Tests" testsuite | `--exclude-testsuite` | NOT_WIRED | Target testsuite does not exist in phpunit.xml. |
| `composer-dependency-analyser.php` | `lovata/shopaholic-plugin` | `ignoreErrorsOnPackageAndPath` | NOT_WIRED | Path-scoped allowlist defeated by preceding global ignore + package not in composer deps. |
| `Pest.php` uses() | `tests/Unit/Adapter/Shopaholic` | directory binding | WIRED-LATENT | Subdirs do not exist yet (Phase 3 lands them); binding harmless until then. |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|---------------|--------|--------------------|--------|
| `Plugin::pluginDetails()` | returned array | static literal array | YES (translatable lang keys) | FLOWING |
| `MetapixelTestCase::createApplication()` | `$app` Laravel app | host bootstrap/app.php | YES (real OctoberCMS kernel) | FLOWING |
| `ShopaholicAdapterTestCase::bootOrdersStatuses()` | 5 status rows | static insert | YES (canonical Lovata v1.33 statuses) | FLOWING |
| `phpunit.xml <source><include>` | Plugin.php scope | static path | YES (1 file, 100% covered) | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Plugin.php parse | `php -l Plugin.php` | "No syntax errors" | PASS |
| composer.json validate | `composer validate --strict` | "./composer.json is valid" | PASS |
| All 6 plan files parse | `php -l` x 7 | All "No syntax errors" | PASS |
| phpmd.xml parses | python3 xml.etree.ElementTree | PHPMD XML VALID | PASS |
| phpunit.xml parses | python3 xml.etree.ElementTree | PHPUNIT XML VALID | PASS |
| .github/workflows YAML parses | python3 yaml.safe_load | YAML VALID | PASS |
| pint --test (host vendor) | `pint --config=pint.json --test` | exit 0, passed | PASS |
| phpstan smoke | `phpstan analyse` against rewritten neon | "[OK] No errors" exit 0 | PASS |
| phpmd smoke | `phpmd Plugin.php text phpmd.xml` | no output, exit 0 | PASS |
| pest smoke + coverage | `pest --coverage --min=90` | 3 passed / 100% coverage, exit 0 | PASS |
| Probe: array_find banned | phpstan probe at /tmp/php84_probe.php | "Calling array_find() is forbidden" + 3 sibling fns | PASS |
| Probe: array_find_key banned | (same as above) | rejected | PASS |
| Probe: array_any banned | (same) | rejected | PASS |
| Probe: array_all banned | (same) | rejected | PASS |
| Probe: property hooks rejected | phpstan probe with property hooks | "Property hooks are supported only on PHP 8.4 and later" | PASS |
| Probe: asymmetric visibility rejected | phpstan probe with `public private(set)` | parser error "Multiple access type modifiers" | PASS |
| Probe: `#[\\Deprecated]` rejected | phpstan probe against actual config copy | "[OK] No errors" — NOT REJECTED | FAIL |
| Probe: `#[\\Deprecated]` rejected if rule fixed | phpstan probe with `disallowedAttributes` | "Attribute Deprecated is forbidden" — would reject | (informational — fix path) |
| Probe: --exclude-testsuite ignores nonexistent | `pest --exclude-testsuite='Metapixel Adapter Tests'` | exit 0, no warning, 3 tests still run | FAIL (semantics) |
| Pest 4 + PHPUnit 12 versions | `vendor/bin/pest --version` | matches require-dev pins (host vendor smoke) | PASS |

### Probe Execution

No `scripts/*/tests/probe-*.sh` convention files exist in this plugin. Probes were inline (above) targeting specific SC truths. No additional formal probe execution required.

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|-------------|--------|----------|
| TOOL-01 | 01-01 | composer.json shape | PARTIAL | Lovata require-dev missing |
| TOOL-02 | 01-01 | Directory rename | VERIFIED | Filesystem + git checks pass |
| TOOL-03 | 01-01 | Namespace rewrite | VERIFIED | grep returns 0 stale references |
| TOOL-04 | 01-02 | phpstan.neon | PARTIAL | #[\\Deprecated] not caught |
| TOOL-05 | 01-02 | rector.php | VERIFIED | php83 set + 4 prepared sets |
| TOOL-06 | 01-02 | pint.json | VERIFIED | Nullable rule + ordering |
| TOOL-07 | 01-02 | phpmd.xml | VERIFIED | Lovata.Toolbox baseline thresholds |
| TOOL-08 | 01-03 | Two-tier test bases | VERIFIED | MetapixelTestCase + ShopaholicAdapterTestCase |
| TOOL-09 | 01-03 | CI matrix | PARTIAL | Matrix present, exclude semantics broken |
| TOOL-10 | 01-02+03 | composer qa chain | VERIFIED | 4 scripts wired + each exits 0 |
| TOOL-11 | 01-02 | shipmonk + config | FAILED | Enforcement defeated by global ignore + missing composer package |

**Per-phase coverage:** 11/11 declared in PLAN frontmatter. **Effective coverage:** 7 VERIFIED + 2 PARTIAL + 2 FAILED.

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `composer-dependency-analyser.php` | 34 | `// Phase 3 lands classes/adapter/shopaholic/; this pre-wires the allowlist.` | Warning | Build-philosophy lock in PROJECT/STATE.md forbids phase markers in code. Move comment intent to PHPDoc form or drop the explicit phase reference. |

**Debt marker scan:** Zero `TODO/FIXME/TBD/HACK/XXX/PLACEHOLDER` strings in non-`.planning/` PHP/yaml/json/neon/xml files.
**v1.x port framing scan:** Zero unwanted v1.x port narrative in code; one commit message references "remove v1.x source" which is correct.
**Stub scan:** Plugin.php empty boot+register intentional (Phase 1 scope; PROJECT.md acknowledged stub).

### Build Philosophy Adherence

| Aspect | Status | Notes |
|--------|--------|-------|
| Simple logic, no over-engineering | PASS | Configs match scope; no premature abstractions. |
| No BC shims to v1.x | PASS | Source tree contains nothing v1.x-shaped; legacy branch isolated. |
| No dead code / unused functions | PASS | Plugin.php has only what Phase 1 needs (empty boot/register + pluginDetails). |
| Class names describe purpose | PASS | MetapixelTestCase / ShopaholicAdapterTestCase / PluginSanityTest all purpose-named. |
| Generic host fixtures (no operator names) | PASS | Tests reference `example.test` semantics only — no nailscosmetics/.lv/.no/.lt baked in. |
| No `// CR-` / `// Phase` / phase markers | WARNING | One occurrence in composer-dependency-analyser.php line 34. |

### Filesystem Layout & Git State

| Check | Status | Evidence |
|-------|--------|----------|
| Plugin dir at `plugins/logingrupa/metapixel/` | PASS | `ls /home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/` returns expected scaffold |
| Old `plugins/logingrupa/metapixelshopaholic/` removed | PASS | Directory does not exist |
| Tag `v1.1.1` present | PASS | `git rev-parse v1.1.1` → 73f99a4 |
| Branch `legacy/v1.1.1` reachable | PASS | `git rev-parse legacy/v1.1.1` → 3f32ca6 |
| Plugin is own standalone git repo | PASS | `git rev-parse --show-toplevel` → plugin root |
| Phase 1 plan commits in master log | PASS | 86517a7 / 89bc952 / 62dae98 / 64b5762 all present |

### Human Verification Required

None — all checks were programmatic. CI matrix four-cells-green-on-rename-PR (SC2) is unverifiable until a real PR is opened against `origin`; this is intrinsic to SC2 and will be conclusively tested by the next push. Recommend the developer push the rename PR and observe CI before treating SC2 as fully closed.

### Gaps Summary

Phase 1 ships a structurally complete tooling scaffold but four success-criteria-bearing behaviors are NOT operative in the codebase as currently committed:

1. **TOOL-01 contract violation** — Lovata cart plugins missing from `require-dev`. This is the root cause of two downstream failures.
2. **SC2 install-mode distinction degraded** — Run A vs Run B plugin install identical due to (1). Run-B's `--exclude-testsuite='Metapixel Adapter Tests'` references a non-existent testsuite.
3. **SC3 / TOOL-04 one-of-four 8.4-syntax bans inert** — `#[\\Deprecated]` slips past the disallowed-calls extension because the config uses `disallowedClasses:` (wrong key) instead of `disallowedAttributes:`.
4. **SC4 / TOOL-11 enforcement defeated** — Global `ignoreErrorsOnPackage` suppresses the error path-scoping was meant to expose; plus Lovata cart packages aren't composer deps to begin with.

**Group-related root causes:**
- Gaps #1, #2 share a root: missing lovata require-dev entries. Fixing #1 unlocks Run-A/Run-B differentiation and gives shipmonk a package to track.
- Gap #4 has TWO root causes (global ignore + missing composer package); both must be fixed for P-03 prevention to work.
- Gap #3 is independent — one-line config fix.

**Additional housekeeping (non-blocking):**
- TOOL-01/02/03 traceability flips pending. TOOL-02/03 can flip now; TOOL-01 waits on gap #1 fix.
- One Phase-marker comment in composer-dependency-analyser.php to drop or rephrase.

### Suggested Override Candidates

None recommended. Each gap maps to a concrete, fixable code change. Overrides would mask substantive bugs in the quality gate's actual behavior — defeating Phase 1's whole point ("quality bar wired correctly before any business-logic refactor begins").

### Recommended Closure Plan

A focused follow-up plan (call it `01-04` or fold into Phase 2 entry) that:

1. Add `lovata/shopaholic-plugin ^1.32`, `lovata/ordersshopaholic-plugin ^1.33`, `lovata/buddies-plugin ^1.10` to plugin composer.json require-dev.
2. Flip phpstan.neon `disallowedClasses` block for `Deprecated` to `disallowedAttributes` with key `attribute:`.
3. In composer-dependency-analyser.php, REMOVE the three global `ignoreErrorsOnPackage(...)` calls for lovata packages — keep only the `ignoreErrorsOnPackageAndPath` directives so the path-scoping is real.
4. Add `<testsuite name="Metapixel Adapter Tests"><directory>./tests/Unit/Adapter/Shopaholic</directory><directory>./tests/Feature/Adapter/Shopaholic</directory></testsuite>` to phpunit.xml. Directories empty until Phase 3 — harmless.
5. Drop or rephrase composer-dependency-analyser.php line 34 phase-marker comment.
6. Flip REQUIREMENTS.md TOOL-01/02/03 traceability rows and top-of-file checkboxes once item (1) is in.
7. Re-run smoke chain + push rename PR; observe four CI cells turn green.

After closure, expected verifier result: `passed`, 5/5 SCs verified, 11/11 TOOL-* verified.

---

*Verified: 2026-05-16T07:25:00Z*
*Verifier: Claude (gsd-verifier, opus-4.7-1m)*

---

## Re-verification addendum — 2026-07-04

Status flipped gaps_found → passed. All 6 gaps from the 2026-05-16 verification closed by later phases; re-verified by direct code probes during v2.0.0 milestone audit (see `.planning/v2.0.0-MILESTONE-AUDIT.md` "Phase 1 stale-verification closure evidence"):

1. lovata require-dev — composer.json require-dev now has lovata/shopaholic-plugin + lovata/ordersshopaholic-plugin + lovata/buddies-plugin
2. Run A/B distinction — Run B excludes adapter tests via `pest --exclude-group=adapter` (.github/workflows/metapixel-qa.yml:236,251)
3. Adapter testsuite exclusion — superseded by `#[Group('adapter')]` class attributes (locked in plugin CLAUDE.md Tooling section)
4. `#[\Deprecated]` ban — phpstan.neon uses `disallowedAttributes` (correct shipmonk rule type)
5. dep-analyser boundary — path-scoped `ignoreErrorsOnPackageAndPath` for lovata packages (composer-dependency-analyser.php:35-45); global ignore is UNUSED_DEPENDENCY only
6. TOOL-01/02/03 traceability rows — flipped Complete in REQUIREMENTS.md

*Re-verified: 2026-07-04 (milestone audit, integration-checker + orchestrator code probes)*
