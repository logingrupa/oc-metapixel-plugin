# Phase 5: Documentation + marketplace launch — Pattern Map

**Mapped:** 2026-05-21
**Files analyzed:** 38 (new + modified) — docs, manifest, tests, theme strip, source decorator strips, planning-doc rewrites, screenshots, smoke log
**Analogs found:** 36 / 38 (95 % — 2 files have no in-tree analog and use external conventions instead)

## File Classification

| New / Modified File | New? | Role | Data Flow | Closest Analog | Match Quality |
|---------------------|------|------|-----------|----------------|---------------|
| `README.md` | new | documentation (single-page walkthrough) | static — read by GitHub + Composer + OctoberCMS marketplace | `plugins/lovata/subscriptionsshopaholic/README.md` (richest sibling) + `plugins/lovata/paypalshopaholic/README.md` (install block) + `plugins/lovata/toolbox/README.md` (badges + features list) | role-match (sibling Lovata convention; our README extends with Troubleshooting + Twin walkthroughs per DOCS-02) |
| `CHANGELOG.md` | new | documentation (release notes) | static — read by GitHub, marketplace | none in-plugin; external convention | external (Keep-a-Changelog 1.1.0 — RESEARCH § Code Examples Example 3 verbatim) |
| `docs/CUSTOM-ADAPTERS.md` | new | documentation (developer authoring guide) | static — read by third-party plugin authors | none in-plugin (new genre); contract anchored to `classes/testing/EventSubjectAdapterContractTestCase.php` + `classes/queue/SendCapiEvent.php` hook constants | partial (no doc analog; anchor to in-tree contract artifacts) |
| `docs/screenshots/01-settings.png` | new | binary asset (PNG screenshot) | static — read by GitHub | none in-plugin | external (5 screenshots from live smoke per D-17/D-18) |
| `docs/screenshots/02-failed-events-list.png` | new | binary asset (PNG screenshot) | static — read by GitHub | none in-plugin | external |
| `docs/screenshots/03-replay-flow.png` | new | binary asset (PNG screenshot) | static — read by GitHub | none in-plugin | external |
| `docs/screenshots/04-check-dedup.png` | new | binary asset (PNG screenshot) | static — read by GitHub | none in-plugin | external |
| `docs/screenshots/05-twig-api.png` | new | binary asset (PNG screenshot) | static — read by GitHub | none in-plugin | external |
| `.planning/phases/05-.../05-SMOKE-LOG.md` | new | audit-trail (markdown) | static — operator-authored during 05-08 | `.planning/phases/04-.../04-VERIFICATION.md` (sibling smoke-log shape) | role-match |
| `tests/Feature/Docs/ReadmeStructureTest.php` | new | Pest test (file-load + grep) | request-response (filesystem read) | `tests/Feature/Lang/LangKeyCoverageTest.php` (hermetic file-load pattern) | exact |
| `tests/Feature/Docs/CustomAdaptersStructureTest.php` | new | Pest test (file-load + grep) | request-response (filesystem read) | `tests/Feature/Lang/LangKeyCoverageTest.php` (hermetic file-load pattern) | exact |
| `tests/Feature/Docs/AssetsExistTest.php` | new | Pest test (file existence + glob) | request-response (filesystem read) | `tests/Feature/Lang/LangKeyCoverageTest.php` (file-exists assertions on lang/ files) | exact |
| `tests/Feature/Plugin/PluginYamlSanityTest.php` | new | Pest test (yaml parse + assertions) | request-response (filesystem read) | `tests/Unit/PluginSanityTest.php` (in-tree pluginDetails / registerSettings asserts) + `tests/Feature/Plugin/ShopaholicConditionalRegistrationTest.php` (Feature-path location) | exact |
| `tests/Feature/Docs/NoV1xReferencesTest.php` *(optional D-23 gate)* | new | Pest test (recursive grep) | request-response (filesystem read) | `tests/Feature/Lang/LangKeyCoverageTest.php::test_no_ru_lang_file_shipped` (literal anchor + assertFalse pattern) | exact |
| `Plugin.php` | modify | plugin registration (docblock decorator strip — D-23) | n/a — behavior unchanged | self (current Plugin.php — `Phase 3 D-08` reference on `registerSchedule()` line 148) | exact (self-strip) |
| `classes/queue/SendCapiEvent.php` | modify | queue job (docblock decorator strip — D-23) | n/a — behavior unchanged | self (lines 45 + 250 — "Phase 4 admin UI" decorators) | exact (self-strip) |
| `classes/testing/EventSubjectAdapterContractTestCase.php` | modify | contract test base (docblock decorator strip — D-23) | n/a — behavior unchanged | self (lines 14-15, 34, 36 — 4× "Phase 2 / Phase 3" refs) | exact (self-strip) |
| `classes/adapter/shopaholic/ShopaholicCartPositionAdapter.php` | modify | adapter (docblock decorator strip — D-23) | n/a — behavior unchanged | self (line 70 — "Phase 4 cookie middleware" ref) | exact (self-strip) |
| `classes/adapter/shopaholic/ShopaholicSettingsOptions.php` | modify | static options helper (docblock decorator strip — D-23) | n/a — behavior unchanged | self (line 38 — "Extend at Phase 4" ref) | exact (self-strip) |
| `lang/en/lang.php` | modify | translations (audit only — clean) | static data | self (current file — grep returns 0 v1.x hits per RESEARCH 6) | exact (verify-only, no change) |
| `lang/lv/lang.php` | modify | translations (audit only — clean) | static data | self (current file) | exact (verify-only, no change) |
| `plugin.yaml` | modify | YAML manifest (audit only — already MKT-02 ready) | declarative manifest | self (current file already lang-key-driven with `icon-bullseye` — verified) | exact (verify-only) |
| `composer.json` | modify | dependency manifest (keywords + license decision) | declarative manifest | self (current `composer.json` — adds `keywords` array; license re-evaluation per Open Question 2) | exact |
| `.planning/ROADMAP.md` | modify | planning doc (MKT-04 wording rewrite — D-23) | static | self (MKT-04 currently says "v1.1.1 + legacy/v1.1.1 branch preserved" → rewrite per D-23) | exact (self-edit) |
| `.planning/REQUIREMENTS.md` | modify | planning doc (MKT-04 wording rewrite — D-23) | static | self (line 111 + 112 — MKT-03 + MKT-04 wording) | exact (self-edit) |
| `.planning/STATE.md` | modify | planning doc (operator-infra redaction — D-26) | static | self (sweep grep redaction per RESEARCH Example 4) | exact (self-redact) |
| `themes/logingrupa-naisstore/partials/facebook_pixel.htm` | **DELETE** | theme partial (legacy pixel — D-02 strip target) | static — Twig template | self (current 7-LOC partial — RESEARCH Runtime State Inventory verified) | n/a (delete-only) |
| `themes/logingrupa-naisstore/layouts/main.htm` | modify | theme layout (remove `{% partial 'facebook_pixel' %}` line 149 + add `{% component 'pixelHead' %}` per D-04) | static — Twig template | self + RESEARCH § Code Examples (PixelHead placement in `<head>`) | exact (self-edit) |
| `themes/logingrupa-naisstore/layouts/content.htm` | modify | theme layout (remove facebook_pixel partial line 104) | static — Twig template | self | exact (self-edit) |
| `themes/logingrupa-naisstore/layouts/light.htm` | modify | theme layout (remove facebook_pixel partial line 76) | static — Twig template | self | exact (self-edit) |
| `themes/logingrupa-naisstore/layouts/catalog_default.htm` | modify | theme layout (remove facebook_pixel partial line 100) | static — Twig template | self | exact (self-edit) |
| `themes/logingrupa-naisstore/pages/checkout.htm` | modify | theme page (remove inline `fbq('track','InitiatedCheckout',…)` lines 93-94) | static — Twig template | self | exact (self-edit) |
| `themes/logingrupa-naisstore/pages/order-complete.htm` | modify | theme page (remove disabled-but-still-present fbq block lines 20-35; add `[EventPixel]` per D-04) | static — Twig template | self + RESEARCH § Code Examples (EventPixel placement on event-emitting page) | exact (self-edit) |
| `themes/logingrupa-naisstore/pages/order-complete-proforma.htm` | modify | theme page (remove inline `fbq('trackCustom','ViewdOrderProformaPage',…)` lines 13-26) | static — Twig template | self | exact (self-edit) |
| `themes/logingrupa-naisstore/partials/form/checkout-form/tracking/facebook-purchase-tracking.js` | **DELETE** | webpack source JS (legacy tracking — D-02 strip target) | bundled-JS source | self (current 38-LOC file; already commented-out import in caller verified) | n/a (delete-only) |
| `themes/logingrupa-naisstore/partials/shared/tracking/facebook-add-to-cart.js` | **DELETE** | webpack source JS (legacy tracking — D-02 strip target) | bundled-JS source | self (current 36-LOC file) | n/a (delete-only) |
| `themes/logingrupa-naisstore/partials/shared/tracking/facebook-view-content.js` | **DELETE** | webpack source JS (legacy tracking — D-02 strip target) | bundled-JS source | self (current 30-LOC file) | n/a (delete-only) |
| `themes/logingrupa-naisstore/partials/shared/controls/add-to-cart-control.js` | modify | webpack source JS (remove `trackFacebookAddToCart` import line 13 + usage) | bundled-JS source | self | exact (self-edit) |
| `themes/logingrupa-naisstore/partials/shared/controls/product-detail-control.js` | modify | webpack source JS (remove `trackFacebookViewContent` import line 13 + usage) | bundled-JS source | self | exact (self-edit) |
| `themes/logingrupa-naisstore/partials/product/search-result/search.js` | modify | webpack source JS (remove `_fbq('track','Search',…)` block lines 33-40) | bundled-JS source | self | exact (self-edit) |
| `themes/logingrupa-naisstore/configs/fields.yaml` | modify | YAML config (remove `facebook_pixel_id` + `facebook_domain_verification_id` theme-settings lines 447-456) | declarative form schema | self | exact (self-edit) |
| `themes/logingrupa-naisstore/assets/js/common.js` | rebuild | bundled webpack output | bundled-JS artifact | n/a — regenerated by `pnpm run prod` in theme dir | external (build artifact) |

---

## Pattern Assignments

### `README.md` (single-page documentation, new)

**Analogs (ordered by quality):**
1. `plugins/lovata/subscriptionsshopaholic/README.md` — richest sibling structure (Heading → Overview → Installation → Configuration → Live demo → What is Shopaholic? → Quality standards → Get involved → License). Our README adopts the heading order but expands per DOCS-02.
2. `plugins/lovata/paypalshopaholic/README.md` — install block shape (`composer require` shown as code-fence + 2-step Artisan + Composer).
3. `plugins/lovata/toolbox/README.md` — features-list bullets pattern.

**Heading skeleton to copy** (from `plugins/lovata/subscriptionsshopaholic/README.md` lines 1-20):
```markdown
# Plugin Name plugin for October CMS

[One-line description sentence.]

## Overview

This plugin allows to:
* **bullet 1**;
* **bullet 2**;
...

## Installation

You can install this plugin using October CMS backend Dashboard or by adding them
to the registered project in your October CMS Marketplace profile.

You can find CLI way below to install the plugin.

### Artisan
[...]

### Composer
[...]
```

**Install block shape to copy** (from `plugins/lovata/subscriptionsshopaholic/README.md` lines 22-30):
```markdown
### Artisan

Using the Laravel's CLI is the fastest way to get started. Just run the following commands in a project's root directory:

\`\`\`bash
php artisan plugin:install Lovata.SubscriptionsShopaholic
\`\`\`
```

**Phase 5 extension over the Lovata convention** (per DOCS-02 mandate):
- Add `## Configure → ## Acquire Meta credentials → ## Shopaholic walkthrough → ## Theme walkthrough → ## FailedEvents UI → ## Troubleshooting → ## Multi-site routing → ## CHANGELOG + License` sections.
- Inline screenshots from `docs/screenshots/0[1-5]-*.png` using GitHub-relative refs (e.g., `![Settings](docs/screenshots/01-settings.png)`).
- **NEVER** screenshot Meta Business Manager (D-12 lock — numbered text steps only).
- README walkthrough copies the validated step sequence verbatim from `05-SMOKE-LOG.md` (D-08).
- Troubleshooting section follows the markdown-table shape in `## Pattern Assignments → Troubleshooting` below.
- **No "v1.x" / "legacy" references anywhere** (D-22 + D-23).

**Install block (Phase 5 derivation — per D-25 VCS install path):**
```markdown
## Install

\`\`\`bash
composer require logingrupa/oc-metapixel-plugin
php artisan october:up
\`\`\`

If your project does not yet trust the GitHub VCS source, add this repository
to your project's `composer.json` before running `composer require`:

\`\`\`json
{
  "repositories": [
    {"type": "vcs", "url": "https://github.com/logingrupa/oc-metapixel-plugin"}
  ]
}
\`\`\`
```

**Troubleshooting markdown-table pattern** (from RESEARCH § Pattern 3 — anchored to real `Log::warning` / `Log::critical` sites enumerated via `grep -rn "Log::" plugins/logingrupa/metapixel/classes/`):

```markdown
| Symptom | `Log::*` signature (grep `storage/logs/laravel.log`) | Fix |
|---------|------------------------------------------------------|-----|
| FailedEvents pile up, no events reach Meta | `metapixel: adapter rehydrate failed — dead-lettered` | Worker process restarted with stale queue — clear `failed_events` table and re-dispatch; verify `php artisan queue:work` is running |
| No EventLog row written after Order paid | `metapixel: EventLogWriter rejected subject_id <= 0` | Order has no ID — Lovata model save mis-ordered |
| `_fbp` / `_fbc` cookies not set | `metapixel: untrusted host — cookie skipped` | Add host to Settings → Trusted Hosts (one per line) |
| Invalid fbclid in URL | `metapixel: fbclid rejected — invalid charset` | No action — fail-safe path; `_fbc` skipped, event still fires |
```

---

### `CHANGELOG.md` (release notes, new)

**Analog:** none in-plugin. Use Keep-a-Changelog 1.1.0 convention verbatim (https://keepachangelog.com/en/1.1.0/).

**Content from RESEARCH § Code Examples Example 3 (line 743-774 of `05-RESEARCH.md`):**
```markdown
# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - YYYY-MM-DD

Initial public release.

### Added

- Generic adapter pattern (`EventSubjectAdapter` + `ValueResolver` + `AdapterRegistry`) — track any subject through one CAPI + Pixel pipeline
- First-party ShopaholicOrderAdapter — tracks `Lovata\OrdersShopaholic\Models\Order` Purchase events with `SKU-{product_id}[-{offer_id}]` content_ids
- First-party ThemeActionAdapter — generic Twig API (`{% do this.metapixel.pushEvent(...) %}`) + Larajax handler for any OctoberCMS theme
- Three extension hooks: `metapixel.event.before_dispatch` (halt-able), `after_dispatch` (observe), `dead_letter` (alert)
- Multisite-native Settings — per-site `pixel_id` + `capi_access_token`; `$propagatable = []` prevents cross-site token leak
- TrustedHosts allowlist + PSL-aware multi-TLD subdomain index
- `EnsureFbpFbcCookies` middleware — kill switch + fbclid validation
- FailedEvents backend UI — list view, Replay, CheckDedup
- EventLog UNIQUE race-fence — peer-wins idempotency
- Graph API pinned to `v23.0`
- English + Latvian translations
- PHP 8.3 + 8.4 dual support
- `composer qa` chain (Run A full-Lovata + Run B minimal-install CI matrix)
- `EventSubjectAdapterContractTestCase` (10 invariants)
- `docs/CUSTOM-ADAPTERS.md` authoring guide with OFFLINE Mall inline example

[2.0.0]: https://github.com/logingrupa/oc-metapixel-plugin/releases/tag/v2.0.0
```

**Lock**: ZERO "v1.x diff" text. Single `## [2.0.0]` section. Date format `YYYY-MM-DD` per Keep-a-Changelog (planner Discretion).

---

### `docs/CUSTOM-ADAPTERS.md` (developer authoring guide, new)

**Anchors (no doc-format analog in-plugin — use in-tree contract artifacts verbatim):**
1. **Interface signatures** copy from `plugins/logingrupa/metapixel/classes/adapter/EventSubjectAdapter.php` (7 methods) lines 12-68 — already documented with one-paragraph docblocks per method:

```php
interface EventSubjectAdapter
{
    public function getSubjectType(object $obSubject): string;   // opaque alias
    public function getSubjectId(object $obSubject): int;        // positive
    public function getSiteId(object $obSubject): ?int;          // from subject ONLY
    public function getSecretKey(object $obSubject): ?string;    // nullable token
    public function getValueResolver(object $obSubject): ValueResolver;
    public function getUserData(object $obSubject): array;       // 13-key Meta CAPI set
    public function getSupportedEvents(): array;                 // Map<event, list<channel>>
}
```

2. **Hook constants** copy from `plugins/logingrupa/metapixel/classes/queue/SendCapiEvent.php` lines 27-47 + 56-60 — already documented with signatures verbatim in class docblock:

```php
public const HOOK_BEFORE_DISPATCH = 'metapixel.event.before_dispatch';
public const HOOK_AFTER_DISPATCH  = 'metapixel.event.after_dispatch';
public const HOOK_DEAD_LETTER     = 'metapixel.event.dead_letter';

/*
 * before_dispatch — halt-able via Event::fire(..., true).
 *   Signature: function(string, array &$arPayload, object): mixed
 *   Return false to veto. Mutating event_id/event_time forbidden.
 *
 * after_dispatch — observe-only successful-dispatch tap.
 *   Signature: function(string, array, object, array $arGraphResponse): mixed
 *
 * dead_letter — observe-only permanent-failure alert.
 *   Signature: function(string, array, object, \Throwable): mixed
 */
```

3. **Inline OFFLINE\Mall example** copy from RESEARCH § Code Examples Example 2 (line 600-657 of `05-RESEARCH.md`) — ~50 LOC adapter + ~30 LOC value resolver. Code lives ONLY in doc as code blocks (D-14 — NO `classes/adapter/mall/` directory).

4. **Three hook examples** copy verbatim from RESEARCH § Code Examples Example 2 lines 662-701:
   - `before_dispatch` → inject `test_event_code` from app env for staging
   - `after_dispatch` → mirror EventLog to analytics dashboard
   - `dead_letter` → post to Slack webhook on permanent CAPI failure

5. **`## Testing your adapter` section** copy from `plugins/logingrupa/metapixel/classes/testing/EventSubjectAdapterContractTestCase.php` lines 18-31 + 10 invariant test method names enumerated from same file:

```php
abstract class EventSubjectAdapterContractTestCase extends MetapixelTestCase
{
    abstract protected function makeAdapter(): EventSubjectAdapter;
    abstract protected function makeSubject(): object;
    // 10 invariant test methods: invariant_01_subject_type_is_opaque_alias_format,
    // invariant_02_subject_id_is_positive_int, ... (enumerate from file)
}
```

**Anti-patterns section to copy** from plugin `CLAUDE.md` § Extensibility contract (rank 6 — last resort):
> `Component::extend(PixelHead::class, ...) + addDynamicMethod(...)` — LAST RESORT. Use ONLY when an Event::fire hook does not exist for your use case. Unbounded surface (every method can be replaced) — third parties must scope dynamic methods with an `onMetapixel*` prefix to avoid collisions.

**Lock:** Section ordering per RESEARCH § Pattern 4 (Overview → Contract → Inline example → Register → Trigger dispatch → 3 hooks → Testing → Anti-patterns).

---

### Wave 0 Tests — Pest test files

All four Wave 0 tests follow the same hermetic file-load pattern. Single canonical analog: `plugins/logingrupa/metapixel/tests/Feature/Lang/LangKeyCoverageTest.php`.

**Analog: `tests/Feature/Lang/LangKeyCoverageTest.php` lines 1-15 (imports + class header):**

```php
<?php

use Logingrupa\Metapixel\Tests\MetapixelTestCase;

/*
 * Note: PHPUnit's classic `extends MetapixelTestCase` model is used here
 * (mirrors tests/Unit/PluginSanityTest.php) because Pest's $rootPath
 * resolution under `vendor/bin/pest --configuration phpunit.xml` does not
 * always pick up the Pest.php binding. The explicit extends keeps this
 * smoke test working under any Pest invocation shape.
 */

final class LangKeyCoverageTest extends MetapixelTestCase
{
    // ...
}
```

**Hermetic file-load helper to copy** (`LangKeyCoverageTest.php` lines 22-33):

```php
private function loadEnLang(): array
{
    return require dirname(__DIR__, 3).'/lang/en/lang.php';
}
```

Adapt to other file paths:
- `dirname(__DIR__, 3).'/README.md'` → README structure test
- `dirname(__DIR__, 3).'/docs/CUSTOM-ADAPTERS.md'` → custom-adapters test
- `dirname(__DIR__, 3).'/docs/screenshots/0'.$i.'-*.png'` (glob) → assets-exist test
- `dirname(__DIR__, 3).'/plugin.yaml'` → plugin.yaml sanity test

**File-exists assertion pattern** (`LangKeyCoverageTest.php` lines 76-103):

```php
public function test_en_lang_file_exists_and_returns_array(): void
{
    $sPath = dirname(__DIR__, 3).'/lang/en/lang.php';
    $this->assertFileExists($sPath, 'lang/en/lang.php must ship with the plugin.');

    $arEn = $this->loadEnLang();
    $this->assertIsArray($arEn);
}

public function test_no_ru_lang_file_shipped(): void
{
    // D-17 lock — Russian translations dropped in v2.0; operators
    // self-service via custom lang overrides outside the plugin tree.
    $sPath = dirname(__DIR__, 3).'/lang/ru/lang.php';
    $this->assertFalse(
        file_exists($sPath),
        'lang/ru/lang.php must NOT ship — D-17 locks Russian out of v2.0 marketplace plugin.',
    );
}
```

**Grep-and-count assertion pattern** (Phase 5 derivation — same style as `LangKeyCoverageTest::test_lv_strings_are_not_machine_translation_artifacts`):

```php
public function test_readme_contains_seven_named_sections(): void
{
    $sReadme = (string) file_get_contents(dirname(__DIR__, 3).'/README.md');
    $iCount = preg_match_all('/^## (Install|Configure|Acquire|Shopaholic|Theme|FailedEvents|Troubleshoot)/m', $sReadme);
    $this->assertGreaterThanOrEqual(7, $iCount, 'README must include 7 named sections per DOCS-02.');
}

public function test_readme_has_no_v1x_references(): void
{
    $sReadme = (string) file_get_contents(dirname(__DIR__, 3).'/README.md');
    $this->assertStringNotContainsString('v1.1.1', $sReadme, 'D-22 lock — README is fresh v2.0 surface, no v1.x diff text.');
    $this->assertStringNotContainsString('legacy/v1', $sReadme, 'D-23 lock — no legacy branch references on public surface.');
}
```

#### `tests/Feature/Docs/ReadmeStructureTest.php`

**Assertions:**
- `assertFileExists` README.md
- Section regex grep ≥ 7 named sections (Install, Configure, Acquire Meta, Shopaholic, Theme, FailedEvents, Troubleshoot)
- `assertStringNotContainsString('v1.', $sReadme)`
- `assertStringNotContainsString('legacy/v1', $sReadme)`
- Every `lang/en/lang.php` field label string also appears in README (DOCS-02 walkthrough fidelity — load `lang/en/lang.php` like LangKeyCoverageTest and grep each `field.*_label` value against the README contents)

#### `tests/Feature/Docs/CustomAdaptersStructureTest.php`

**Assertions:**
- `assertFileExists docs/CUSTOM-ADAPTERS.md`
- `substr_count` for each hook constant string: `metapixel.event.before_dispatch`, `…after_dispatch`, `…dead_letter` each ≥ 1
- `substr_count('OFFLINE\\Mall', $sDoc) >= 1` (OFFLINE\Mall inline example)
- `assertStringContainsString('EventSubjectAdapterContractTestCase', $sDoc)` (Testing section)
- `assertStringContainsString('AdapterRegistry::instance()->register', $sDoc)` (registration snippet)

#### `tests/Feature/Docs/AssetsExistTest.php`

**Assertions:**
- `glob(dirname(__DIR__, 3).'/docs/screenshots/0[1-5]-*.png')` returns count 5
- `assertFileExists` for `CHANGELOG.md`
- `assertRegExp('/^## \\[2\\.0\\.0\\] - \\d{4}-\\d{2}-\\d{2}$/m', $sChangelog)` (Keep-a-Changelog section header date format)
- `assertStringContainsString('### Added', $sChangelog)` (KaC subsection)
- `assertStringNotContainsString('v1.1.1', $sChangelog)` (D-22 lock — no v1.x diff)

#### `tests/Feature/Plugin/PluginYamlSanityTest.php`

**Analog:** Combine `tests/Unit/PluginSanityTest.php` (in-tree `pluginDetails` asserts at lines 22-30 + `registerSettings` asserts at lines 42-53) with `tests/Feature/Lang/LangKeyCoverageTest.php` file-load shape.

**In-tree PluginSanityTest excerpt to mirror** (lines 22-30 of `tests/Unit/PluginSanityTest.php`):

```php
public function test_plugin_details_returns_lang_keys_under_renamed_namespace(): void
{
    $obPlugin = new Plugin($this->app);
    $arDetails = $obPlugin->pluginDetails();

    $this->assertSame('logingrupa.metapixel::lang.plugin.name', $arDetails['name']);
    $this->assertSame('logingrupa.metapixel::lang.plugin.description', $arDetails['description']);
    $this->assertSame('Logingrupa', $arDetails['author']);
}
```

**Phase 5 derivation — parse `plugin.yaml` directly (not via `Plugin::pluginDetails()`) to verify yaml-side configuration matches MKT-02:**

```php
use Symfony\Component\Yaml\Yaml;

public function test_plugin_yaml_fields_are_generic_and_lang_key_driven(): void
{
    $sPath = dirname(__DIR__, 3).'/plugin.yaml';
    $this->assertFileExists($sPath);

    $arYaml = Yaml::parseFile($sPath);
    $arPlugin = $arYaml['plugin'] ?? [];

    $this->assertSame('logingrupa.metapixel::lang.plugin.name', $arPlugin['name']);
    $this->assertSame('logingrupa.metapixel::lang.plugin.description', $arPlugin['description']);
    $this->assertSame('Logingrupa', $arPlugin['author']);
    $this->assertSame('icon-bullseye', $arPlugin['icon']);
    $this->assertMatchesRegularExpression(
        '#^https://github\.com/logingrupa/oc-metapixel-plugin$#',
        $arPlugin['homepage'],
    );
}
```

#### `tests/Feature/Docs/NoV1xReferencesTest.php` *(optional D-23 gate)*

**Pattern:** recursive grep against in-plugin source files (`lang/`, `Plugin.php`, `classes/`, `components/`, `controllers/`) asserting zero `Phase [1-5]` / `v1\.|legacy/v1` matches in public-facing surface. Anchored to `tests/Feature/Lang/LangKeyCoverageTest.php::test_no_ru_lang_file_shipped` literal-anchor style.

```php
public function test_plugin_php_has_no_phase_n_decorators(): void
{
    $sSource = (string) file_get_contents(dirname(__DIR__, 3).'/Plugin.php');
    $this->assertDoesNotMatchRegularExpression(
        '/Phase [0-9]/',
        $sSource,
        'D-23 lock — no "Phase N" docblock decorators on public source.',
    );
}
```

---

### `Plugin.php` (modify — D-23 docblock decorator strip)

**Analog: self.** Behavior MUST remain unchanged (Tiger-Style lock — `// silent: behavior unchanged` per CLAUDE.md).

**Concrete strip target** (current `Plugin.php` lines 147-156):

```php
    /**
     * Wire the daily TTL purge of EventLog rows older than 7 days (Phase 3 D-08).      ← STRIP "(Phase 3 D-08)"
     * October fires console.schedule on each `php artisan schedule:run` and forwards
     * to every plugin's registerSchedule. Param is untyped to match
     * PluginBase::registerSchedule($schedule) signature (LSP variance — RESEARCH       ← STRIP "RESEARCH pitfall 7" or rephrase
     * pitfall 7); the concrete Illuminate\Console\Scheduling\Schedule is documented
     * via @param.
     *
     * @param  Schedule  $obSchedule
     */
```

**Post-strip target:**

```php
    /**
     * Wire the daily TTL purge of EventLog rows older than 7 days.
     * October fires console.schedule on each `php artisan schedule:run` and forwards
     * to every plugin's registerSchedule. Param is untyped to match
     * PluginBase::registerSchedule($schedule) signature (LSP variance); the concrete
     * Illuminate\Console\Scheduling\Schedule is documented via @param.
     *
     * @param  Schedule  $obSchedule
     */
```

---

### `classes/queue/SendCapiEvent.php` (modify — D-23 docblock decorator strip)

**Analog: self.** Two strip sites:

**Line 45 (class-level docblock):**
> `* writeFailedEvent populates FailedEvent.subject_type + subject_id from the resolved adapter when available (enables Phase 4 admin UI re-resolution).`

→ Rewrite as: `* writeFailedEvent populates FailedEvent.subject_type + subject_id from the resolved adapter when available (enables admin UI re-resolution).`

**Line 248-252 (writeFailedEvent docblock):**
> `* subject_id are populated from it so Phase 4 admin UI can re-resolve the subject`

→ Rewrite as: `* subject_id are populated from it so admin UI can re-resolve the subject`

---

### `classes/testing/EventSubjectAdapterContractTestCase.php` (modify — D-23 docblock decorator strip — 4 sites)

**Analog: self.** Four strip sites in class-level docblock (lines 13-40):

| Line | Current | Rewrite |
|------|---------|---------|
| 14 | `adapters (FakeAdapter in Phase 2, ShopaholicOrderAdapter + ThemeActionAdapter` | `adapters (FakeAdapter, ShopaholicOrderAdapter + ThemeActionAdapter` |
| 15 | `in Phase 3) extend this base` | `extend this base` |
| 34 | `the Phase 2 marketplace contract.` | `the marketplace contract.` |
| 36 | `Extending MetapixelTestCase is a Phase 2 YAGNI choice — Phase 2 has exactly` | `Extending MetapixelTestCase is a YAGNI choice — the suite has exactly` (also rewrite sentence ending "Revisit at v2.1" to "Revisit when first third-party adapter ships") |

---

### `classes/adapter/shopaholic/ShopaholicCartPositionAdapter.php` (modify — D-23 docblock decorator strip)

**Analog: self.** Single strip site at line 70:

> `EventPixel render path; Phase 4 cookie middleware sets them at the`

→ Rewrite as: `EventPixel render path; the cookie middleware sets them at the`

---

### `classes/adapter/shopaholic/ShopaholicSettingsOptions.php` (modify — D-23 docblock decorator strip)

**Analog: self.** Single strip site at line 38:

> `(NOK, EUR, USD, GBP). Extend at Phase 4 if operator demand surfaces.`

→ Rewrite as: `(NOK, EUR, USD, GBP). Extend when operator demand surfaces.`

---

### `lang/en/lang.php` + `lang/lv/lang.php` (verify-only — no change)

**Analog: self.** Verified clean per RESEARCH 6 grep (zero `v1\.|legacy/v1|Phase [0-9]` hits). No edit required. Optional D-23 gate test `tests/Feature/Docs/NoV1xReferencesTest.php` enforces this invariant going forward.

---

### `plugin.yaml` (verify-only — already MKT-02 ready)

**Analog: self.** Current file (verified):

```yaml
plugin:
    name: 'logingrupa.metapixel::lang.plugin.name'
    description: 'logingrupa.metapixel::lang.plugin.description'
    author: Logingrupa
    icon: icon-bullseye
    homepage: 'https://github.com/logingrupa/oc-metapixel-plugin'
```

All 5 MKT-02 criteria satisfied: lang-key name, lang-key description, author `Logingrupa`, icon `icon-bullseye` (FA glyph, D-20 lock — no PNG required), homepage points at the GitHub VCS install URL (D-25). No change. Sanity test (`PluginYamlSanityTest`) locks it.

---

### `composer.json` (modify — keywords + license decision)

**Analog: self.** Current state ships:

```json
{
    "name": "logingrupa/oc-metapixel-plugin",
    "type": "october-plugin",
    "description": "Meta Pixel + Conversions API for OctoberCMS",
    "license": "proprietary",
    "require": { "php": "^8.3 || ^8.4", "october/system": "^4.0", ... },
    "suggest": { ... },
    "extra": { "october": { "plugin": "Logingrupa.Metapixel", ... } }
}
```

**Two Phase 5 modifications:**

1. **Add `keywords` array** (planner Discretion per Claude's Discretion list — pick subset of `meta-pixel`, `conversions-api`, `capi`, `october-cms`, `shopaholic`, `tracking`, `analytics`).

```json
"keywords": ["october-cms", "october-plugin", "meta-pixel", "conversions-api", "capi", "shopaholic", "tracking", "analytics"]
```

2. **`license` re-evaluation** (Deferred Idea per 05-CONTEXT.md line 172 + Open Question 2 in RESEARCH). Operator decision required in plan 05-14. Default recommendation per RESEARCH: `MIT` for ecosystem-standard composer-installable plugins; operator may choose `proprietary` to retain commercial control even with public source.

---

### Theme legacy strip — `themes/logingrupa-naisstore/` (D-02 + D-04)

**Analog: RESEARCH § Runtime State Inventory line 419-435** — verified file inventory.

**Two cutover passes, gated by UAT (D-03):**

#### Pass 1 — strip (05-02, before UAT Gate 1)

| File | Action | Lines |
|------|--------|-------|
| `partials/facebook_pixel.htm` | **DELETE** | 7 LOC — entire file |
| `layouts/main.htm` | edit | remove line 149 `{% partial 'facebook_pixel' obUser=obUser%}` |
| `layouts/content.htm` | edit | remove line 104 same include |
| `layouts/light.htm` | edit | remove line 76 same include |
| `layouts/catalog_default.htm` | edit | remove line 100 same include |
| `pages/checkout.htm` | edit | remove lines 91-99 `{% scripts %} ... fbq('track','InitiatedCheckout',{content_ids:[...]}) ... {% scripts %}` block |
| `pages/order-complete.htm` | edit | remove lines 20-35 disabled `{# ... fbq('trackCustom','ViewdOrderCompleatedStatusPage', ...) ... #}` block |
| `pages/order-complete-proforma.htm` | edit | remove lines 13-26 `{% scripts %} ... fbq('trackCustom','ViewdOrderProformaPage', ...) ... {% scripts %}` block |
| `partials/form/checkout-form/tracking/facebook-purchase-tracking.js` | **DELETE** | 38 LOC — already commented-out import in caller (verified `checkout-form-validation.js:2`) |
| `partials/shared/tracking/facebook-add-to-cart.js` | **DELETE** | 36 LOC — caller `add-to-cart-control.js:13` must drop its import (see below) |
| `partials/shared/tracking/facebook-view-content.js` | **DELETE** | 30 LOC — caller `product-detail-control.js:13` must drop its import |
| `partials/shared/controls/add-to-cart-control.js` | edit | line 13 `import { trackFacebookAddToCart } from '../tracking/facebook-add-to-cart';` → DELETE; plus drop the `trackFacebookAddToCart(...)` call site |
| `partials/shared/controls/product-detail-control.js` | edit | line 13 `import { trackFacebookViewContent } from '../tracking/facebook-view-content';` → DELETE; plus drop the call site |
| `partials/product/search-result/search.js` | edit | lines 33-40 — remove `_fbq('track','Search',{search_string:...,url_path:...})` block (keep the surrounding ShopaholicSearch flow intact) |
| `configs/fields.yaml` | edit | remove lines 447-456 (`facebook_pixel_id` + `facebook_domain_verification_id` fields) — operator clears theme settings to match |
| `assets/js/common.js` | rebuild | regenerated by `pnpm run prod` in theme directory after all source-file deletes/edits land |

**UAT Gate 1 pass criterion (D-05 — RESEARCH § Pattern 1):**
- Meta Pixel Helper Chrome extension shows 0 events on `/`, `/catalog`, `/product`, `/checkout`, `/order-complete`
- Test Events live view shows 0 new events in last minute
- `SELECT count(*) FROM logingrupa_metapixel_event_log WHERE created_at >= NOW() - INTERVAL 1 MINUTE` returns 0

#### Pass 2 — add PixelHead in layouts (05-04, after UAT Gate 1)

| File | Action | Twig snippet to insert |
|------|--------|------------------------|
| `layouts/main.htm` (line ~149, where `facebook_pixel` partial was) | edit | `{% component 'pixelHead' %}` — RESEARCH § Code Examples states "Operator inserts `{% component 'pixelHead' %}` in `<head>`". Component registration confirmed at `plugins/logingrupa/metapixel/Plugin.php:111`. |
| `layouts/content.htm`, `light.htm`, `catalog_default.htm` | edit | same `{% component 'pixelHead' %}` insertion |

**UAT Gate 2 pass criterion (D-05):**
- Pixel Helper shows exactly 1 PageView event per page-load
- Test Events live view shows "Browser" + "Server" sources for same event_id with "Deduplicated" label
- `SELECT count(*) FROM logingrupa_metapixel_event_log WHERE event_name='PageView' AND channel='capi' AND created_at >= NOW() - INTERVAL 1 MINUTE` returns 1

#### Pass 3 — add EventPixel on event pages (05-06, after UAT Gate 2)

| File | Action | Twig snippet to insert |
|------|--------|------------------------|
| `pages/order-complete.htm` | edit | NOTE — file already has `[purchasePixel]` declared in INI section (line 10) + `{% component 'purchasePixel' %}` rendered (line 18). The `purchasePixel` component IS the EventPixel render — confirmed at `plugins/logingrupa/metapixel/Plugin.php:111-112` registers PixelHead + EventPixel; verify the existing `purchasePixel` alias resolves to `EventPixel::class` and ships server-confirmed render |
| `pages/<other event-emitting pages from smoke>` | edit | add `[EventPixel]` component INI block + `{% component 'eventPixel' %}` render per RESEARCH § Code Examples |

**UAT Gate 3 pass criterion (D-05):**
- Place test order on `your-staging-host.example`
- Pixel Helper shows Purchase event with `eventID` field populated
- Test Events live view shows same `event_id`, "Deduplicated" label
- `SELECT * FROM logingrupa_metapixel_event_log WHERE event_name='Purchase' AND created_at >= NOW() - INTERVAL 2 MINUTE` returns 2 rows: `channel='capi'` + `channel='pixel'` with identical `event_id`

---

### `.planning/ROADMAP.md` + `.planning/REQUIREMENTS.md` (modify — MKT-04 wording rewrite — D-23)

**Analog: self.** Locked rewrites enumerated in RESEARCH Runtime State Inventory:

- REQUIREMENTS.md line 111 MKT-03: `… CHANGELOG.md documenting v2.0.0 changes vs v1.x legacy branch.` → `… CHANGELOG.md documenting the v2.0.0 initial public release.`
- REQUIREMENTS.md line 112 MKT-04: `… v1.1.1 + legacy/v1.1.1 branch preserved (operator may stay on legacy indefinitely).` → `… v2.0.0 annotated tag from master at smoke-validated commit.`
- REQUIREMENTS.md line 150 Backward-compat row: `Operators stay on legacy/v1.1.1 branch indefinitely. Fresh installs only.` → `Fresh installs only.`
- REQUIREMENTS.md lines 11, 14, 27, 43, 45, 47, 55, 65, 76, 85, 91, 97, 101, 107 all carry `Phase N` headers — rewrite phase-grouping headers to drop the `(Phase N — …)` parenthetical decoration on public-shipped REQUIREMENTS.md. Planner's exact rewrite is Discretion.

**Lock:** Net effect is a reader of the public repo finds no trace of v1.x. Every line in the table above MUST be rewritten before plan 05-14 public flip.

---

### `.planning/STATE.md` (modify — operator-infra redaction — D-26)

**Analog: self + RESEARCH Example 4** (pre-flip security sweep recipe).

**Sweep recipe (RESEARCH § Code Examples Example 4 lines 778-799):**

```bash
# Step 1 — Secrets in git history
git log --all -p 2>&1 | grep -iE 'pixel_id\s*[=:]\s*[0-9]{10,}|access_token\s*[=:]\s*EAA[A-Za-z0-9]{20,}' | grep -vE '1234567890|000000000000000|REDACTED_FOR_DEMO|placeholder'

# Step 2 — Operator-infra refs
grep -rnE 'new\.nailscosmetics\.lv|forge\.laravel\.com|\b10\.[0-9]|\b192\.168\.' .planning/ | grep -v archive/

# Step 3 — Verify legacy archive stays local
git ls-remote --tags origin 'v1*'    # MUST be empty
git ls-remote --heads origin 'legacy/*'    # MUST be empty
```

**Lock:** RESEARCH Runtime State Inventory verified 12 hits in 4 files (`05-CONTEXT.md`, `05-DISCUSSION-LOG.md`, `milestones/v1.1.1-ROADMAP.md`, `research/PITFALLS.md`). Planner's edit redacts each to `your-staging-host.example` OR removes from public-shipped surface entirely.

---

### `.planning/phases/05-.../05-SMOKE-LOG.md` (new — operator-authored during 05-08)

**Analog:** `.planning/phases/04-settings-rework-multisite-trustedhosts-cookie-failedevents-t/04-VERIFICATION.md` (sibling phase smoke-log shape).

**Required fields per D-08:**
- timestamp (operator entry per step)
- env name (`your-staging-host.example`)
- exact button clicks (operator narrative)
- EventLog row count (`SELECT count(*), event_name, channel FROM logingrupa_metapixel_event_log GROUP BY event_name, channel`)
- Meta Test Events screenshot count
- `fbp`/`fbc` cookie values (sample row from operator's browser devtools — redact-friendly format)
- `event_id` sample (UUID v4)
- pass/fail per step

**README walkthrough copies the validated step sequence verbatim from this file (D-08).**

---

## Shared Patterns

### Hermetic File-Load Test Pattern

**Source:** `plugins/logingrupa/metapixel/tests/Feature/Lang/LangKeyCoverageTest.php` lines 22-33
**Apply to:** All four Wave 0 test files (`ReadmeStructureTest`, `CustomAdaptersStructureTest`, `AssetsExistTest`, `PluginYamlSanityTest`, `NoV1xReferencesTest`)

```php
private function loadReadme(): string
{
    return (string) file_get_contents(dirname(__DIR__, 3).'/README.md');
}
```

Uses `dirname(__DIR__, 3)` to reach the plugin root from `tests/Feature/Docs/`. Same root-resolution pattern used by `LangKeyCoverageTest` for `lang/{en,lv}/lang.php`. No Translator/Filesystem facade required.

### Test Class Extends Pattern

**Source:** `plugins/logingrupa/metapixel/tests/Unit/PluginSanityTest.php` lines 1-15
**Apply to:** All Wave 0 tests

```php
<?php

use Logingrupa\Metapixel\Tests\MetapixelTestCase;

final class ReadmeStructureTest extends MetapixelTestCase
{
    // ...
}
```

**Why classic `extends` not Pest closures:** Per `tests/Pest.php` line 21-23, Pest's `uses(MetapixelTestCase::class)->in(__DIR__.'/Feature')` does cover the Feature dir — but the docs cite Pest+PHPUnit12 root-resolution quirk under `vendor/bin/pest --configuration phpunit.xml`. Classic extends is the safe path mirrored across all in-tree tests.

### Tiger-Style Docblock Hygiene Strip Pattern

**Source:** plugin `CLAUDE.md` § Code style — "No comment pollution"
**Apply to:** 13 docblock decorator sites enumerated in RESEARCH Runtime State Inventory (5 PHP files) + 4 planning-doc files (ROADMAP, REQUIREMENTS, STATE, language references)

**Rule:** Strip every `Phase [1-5]`, `(Phase N D-XX)`, `RESEARCH pitfall N`, `Plan N`, `legacy/v1`, `v1\.` reference from public-shipped surface. Behavior MUST NOT change — strip is pure docblock/comment text. Every `catch` retains its existing fail-reason comment per Tiger-Style (no comments removed from `catch` blocks).

### Keep-a-Changelog 1.1.0 Pattern

**Source:** https://keepachangelog.com/en/1.1.0/ (external — no in-plugin analog)
**Apply to:** `CHANGELOG.md`

```markdown
# Changelog
[header preamble]

## [VERSION] - YYYY-MM-DD

### Added
### Changed
### Deprecated
### Removed
### Fixed
### Security

[VERSION]: https://github.com/.../releases/tag/vVERSION
```

**Phase 5 special**: Only `### Added` subsection (initial release — nothing changed/deprecated/removed/fixed/security-vs-prior). Single `## [2.0.0] - YYYY-MM-DD` section.

### GitHub VCS Composer Install Pattern

**Source:** https://getcomposer.org/doc/05-repositories.md (external)
**Apply to:** `README.md` Install section; verification step in plan 05-14

```json
{
  "repositories": [
    {"type": "vcs", "url": "https://github.com/logingrupa/oc-metapixel-plugin"}
  ],
  "require": {
    "logingrupa/oc-metapixel-plugin": "^2.0"
  }
}
```

### Annotated Git Tag Pattern

**Source:** plugin `CLAUDE.md` § Reference (v1.1.1 precedent — but `legacy/v1.1.1` archive stays local-only per D-24)
**Apply to:** Plan 05-14 — `git tag -a v2.0.0 -m "v2.0.0 — generic-event-tracking marketplace plugin"`

### Gated UAT Cutover Pattern

**Source:** RESEARCH § Pattern 1 lines 287-310
**Apply to:** Plans 05-02 → 05-03 → 05-04 → 05-05 → 05-06 → 05-07 (theme legacy strip wave)

Three named gates, each with three-source convergence check (Pixel Helper + Test Events + EventLog DB tail). Each gate documented as three separate task-checkboxes (Pitfall 4 — operator MUST tick all three, not one combined "PASS").

---

## No Analog Found

Files with no close match in the codebase (planner uses RESEARCH patterns + external conventions instead):

| File | Role | Data Flow | Reason |
|------|------|-----------|--------|
| `docs/CUSTOM-ADAPTERS.md` | developer authoring guide | static — read by third-party plugin authors | No doc-format analog in-plugin. Anchor to in-tree code artifacts (`EventSubjectAdapter` interface, `SendCapiEvent` hook constants, `EventSubjectAdapterContractTestCase` 10 invariants) + RESEARCH § Code Examples Example 2. |
| `docs/screenshots/0[1-5]-*.png` | binary PNG assets | static | No in-plugin PNG asset analog. Produced as side-effect of D-08 live smoke per D-17. |

---

## Metadata

**Analog search scope:**
- `plugins/logingrupa/metapixel/` (plugin tree — primary)
- `plugins/lovata/{subscriptionsshopaholic,paypalshopaholic,filtershopaholic,toolbox}/` (sibling Lovata READMEs for single-page convention)
- `plugins/lovata/ordersshopaholic/controllers/paymentmethods/` (Lovata controller sibling — referenced by Phase 4 PATTERNS.md for FailedEvents shape, useful background)
- `themes/logingrupa-naisstore/` (legacy strip target enumeration per RESEARCH Runtime State Inventory)

**Files scanned:** 38 metapixel plugin files (Plugin.php, classes/, components/, tests/, lang/, composer.json, plugin.yaml) + 4 Lovata sibling READMEs + 12 theme files (RESEARCH-enumerated inventory)

**Pattern extraction date:** 2026-05-21

**Notes for planner:**
- 28 of 38 files use self-analog (modify-only or verify-only). Strip operations are pure docblock/comment edits; behavior MUST stay unchanged per Tiger-Style.
- 4 Wave 0 test files share one canonical analog (`tests/Feature/Lang/LangKeyCoverageTest.php`) — extract assertion helpers once into PATTERNS-referenced helpers; planner produces independent test files.
- 2 docs files (`README.md`, `CHANGELOG.md`) follow Lovata sibling + Keep-a-Changelog conventions verbatim — both partial-match in-tree, both external-spec-locked.
- 1 docs file (`docs/CUSTOM-ADAPTERS.md`) has no doc analog — anchor to in-tree code artifacts + RESEARCH § Code Examples Example 2.
- Theme legacy strip (15 file actions across 3 waves) is pure self-edit + delete with UAT gates between waves. No new code introduced.
- 5 PNG screenshots are side-effects of D-08 live smoke — Plan 05-12 captures, no Wave 0 generation required.
