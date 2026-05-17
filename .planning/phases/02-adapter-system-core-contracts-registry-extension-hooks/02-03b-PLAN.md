---
phase: 02-adapter-system-core-contracts-registry-extension-hooks
plan: 3b
slug: settings-pluginguard-exceptions
type: execute
wave: 2
depends_on:
  - 02-01
files_modified:
  - plugins/logingrupa/metapixel/models/Settings.php
  - plugins/logingrupa/metapixel/models/settings/fields.yaml
  - plugins/logingrupa/metapixel/classes/Helper/PluginGuard.php
  - plugins/logingrupa/metapixel/classes/Exception/MetaPixelException.php
  - plugins/logingrupa/metapixel/classes/Exception/MissingPixelConfigException.php
  - plugins/logingrupa/metapixel/classes/Exception/MissingCapiTokenException.php
  - plugins/logingrupa/metapixel/classes/Exception/MetaApiTransientException.php
  - plugins/logingrupa/metapixel/classes/Exception/MetaApiPermanentException.php
  - plugins/logingrupa/metapixel/Plugin.php
  - plugins/logingrupa/metapixel/lang/en/lang.php
  - plugins/logingrupa/metapixel/lang/lv/lang.php
  - plugins/logingrupa/metapixel/tests/Feature/Settings/SettingsLookupForSiteTest.php
  - plugins/logingrupa/metapixel/tests/Feature/Settings/SettingsCommonSettingsParentTest.php
  - plugins/logingrupa/metapixel/tests/Unit/Helper/PluginGuardTest.php
  - plugins/logingrupa/metapixel/tests/Unit/ExceptionHierarchyTest.php
autonomous: true
requirements: []
maps_to:
  pitfalls: []
  decisions:
    - D-01
must_haves:
  truths:
    - "`Settings` extends `Lovata\\Toolbox\\Models\\CommonSettings`, declares `$propagatable = []`, and exposes `lookupForSite(?int $iSiteId): array` returning the default-row values regardless of $iSiteId (Phase 2 stub — Multisite trait field-whitelist lands Phase 4 MULT-01..02)."
    - "`PluginGuard::isDisabled()` returns true when `Settings::get('pixel_id', '')` is empty and writes `Log::warning`; returns false on non-empty; the result is memoised; `reset()` clears the memo for tests."
    - "5 exception classes exist under `classes/Exception/`: `MetaPixelException` (abstract base extends `\\RuntimeException`), `MissingPixelConfigException`, `MissingCapiTokenException`, `MetaApiTransientException`, `MetaApiPermanentException`. The 2 MetaApi* exceptions carry HTTP-status context."
    - "`Plugin.php` adds `registerSettings()` returning the Settings descriptor; `register()` already binds AdapterRegistry from plan 02-01."
    - "lang/en/lang.php + lang/lv/lang.php carry the Settings UI strings."
    - "T7 + T15 + T23 + T24 from RESEARCH §6 pass: PluginGuardTest, ExceptionHierarchyTest, SettingsLookupForSiteTest, SettingsCommonSettingsParentTest."
    - "`composer qa` exits 0 from `plugins/logingrupa/metapixel/`."
  artifacts:
    - path: "plugins/logingrupa/metapixel/models/Settings.php"
      provides: "Settings extends CommonSettings; Phase 2 single-row; lookupForSite stub."
      contains: "extends CommonSettings"
    - path: "plugins/logingrupa/metapixel/classes/Helper/PluginGuard.php"
      provides: "Boot-time guard: empty pixel_id → disabled + Log::warning."
      contains: "isDisabled"
    - path: "plugins/logingrupa/metapixel/classes/Exception/MetaPixelException.php"
      provides: "Abstract base for all plugin exceptions."
      contains: "abstract class MetaPixelException"
    - path: "plugins/logingrupa/metapixel/Plugin.php"
      provides: "registerSettings() returning the Settings descriptor."
      contains: "registerSettings"
  key_links:
    - from: "plugins/logingrupa/metapixel/classes/Helper/PluginGuard.php"
      to: "plugins/logingrupa/metapixel/models/Settings.php"
      via: "Settings::get('pixel_id', '') call"
      pattern: "Settings::get\\('pixel_id'"
    - from: "plugins/logingrupa/metapixel/Plugin.php"
      to: "plugins/logingrupa/metapixel/models/Settings.php"
      via: "registerSettings descriptor 'class' field"
      pattern: "Settings::class"
---

<objective>
Ship the Phase 2 settings layer + boot-time guard + exception hierarchy. This is the second half of the M-2 split (parallel-Wave-2 with plan 02-03a). Settings extends Lovata.Toolbox CommonSettings with Phase 2 single-row shape; PluginGuard memoises the empty-pixel-id check (log + disable, never throw at boot); 5 exception classes form the hierarchy MetaClient + SendCapiEvent throw against; Plugin.php gains `registerSettings()`; lang/en + lang/lv get the Settings UI strings.

Purpose: every downstream Phase 2 dispatch class depends on this. `SendCapiEvent` (plan 02-06) uses Settings::lookupForSite for credentials + writeFailedEvent uses MetaPixelException::getContext. `MetaClient` (plan 02-05) throws MetaApiTransient/Permanent + MissingPixel/Token. `PluginGuard` is checked by every event-fire path (Phase 3+ watchers will use it as a boot gate).

Output: 1 Settings model + 1 fields.yaml + 1 PluginGuard helper + 5 exception classes + 1 Plugin.php edit + 2 lang files + 4 test files.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@plugins/logingrupa/metapixel/CLAUDE.md
@plugins/logingrupa/metapixel/.planning/PROJECT.md
@plugins/logingrupa/metapixel/.planning/REQUIREMENTS.md
@plugins/logingrupa/metapixel/.planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-CONTEXT.md
@plugins/logingrupa/metapixel/.planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-RESEARCH.md
@plugins/logingrupa/metapixel/Plugin.php
@plugins/logingrupa/metapixel/tests/MetapixelTestCase.php
@plugins/logingrupa/metapixel/lang/en/lang.php
@plugins/logingrupa/metapixel/lang/lv/lang.php

<interfaces>
RESEARCH §4.10–§4.11 backbone shapes:

- **Settings** (§4.11): extends `Lovata\Toolbox\Models\CommonSettings`. `$settingsCode = 'logingrupa_metapixel_settings'`. `$settingsFields = 'fields.yaml'`. `$propagatable = []`. `lookupForSite(?int $iSiteId): array{pixel_id, capi_access_token}` returns default-row values regardless of $iSiteId (Phase 2 stub — Phase 4 MULT-03 re-implements). `[VERIFIED: plugins/lovata/toolbox/models/CommonSettings.php — CommonSettings already use Multisite + has protected $propagatable = []]`.
- **PluginGuard** (§4.10): final class. `isDisabled(): bool` static. Memoised via static `?bool $bIsDisabled = null`. Reads `Settings::get('pixel_id', '')`. Empty → `Log::warning('metapixel: pixel_id is empty — plugin running in disabled mode (events suppressed)')` + return true. Non-empty → false. `reset(): void` static — clears the memo for tests.

Exception hierarchy (D-01 Claude's Discretion in 02-CONTEXT.md + §4.4 MetaClient exception types):

- `abstract class MetaPixelException extends \RuntimeException` — base. Holds optional `array $arContext` for log payload.
- `final class MissingPixelConfigException extends MetaPixelException` — thrown when Settings::lookupForSite returns empty pixel_id at event-fire time (boot-time empty is handled by PluginGuard, NOT this exception).
- `final class MissingCapiTokenException extends MetaPixelException` — thrown when Settings::lookupForSite returns empty capi_access_token.
- `final class MetaApiTransientException extends MetaPixelException` — HTTP 408/429/5xx + ConnectException. Holds `?int $iHttpStatus` getter.
- `final class MetaApiPermanentException extends MetaPixelException` — HTTP 4xx (other than 408/429). Holds `?int $iHttpStatus` getter.

L-4 lock: PluginGuard imports `Illuminate\Support\Facades\Log` FQN. Settings imports `Lovata\Toolbox\Models\CommonSettings`. Exceptions import `RuntimeException` + `Throwable` from global namespace.

Plugin::registerSettings() (§4.15):

```
public function registerSettings(): array
{
    return [
        'settings' => [
            'label' => 'logingrupa.metapixel::lang.settings.label',
            'description' => 'logingrupa.metapixel::lang.settings.description',
            'category' => 'logingrupa.metapixel::lang.settings.category',
            'icon' => 'icon-bullseye',
            'class' => \Logingrupa\Metapixel\Models\Settings::class,
            'order' => 500,
        ],
    ];
}
```

Lang strings needed (lang/en/lang.php + lang/lv/lang.php — same key structure; LV translation MUST be operator-quality, not machine-translated):

```
return [
    'plugin' => [
        'name' => 'Meta Pixel + Conversions API',
        'description' => 'Server-deduplicated Meta Pixel + CAPI tracking via the EventSubjectAdapter contract.',
    ],
    'settings' => [
        'label' => 'Meta Pixel + CAPI',
        'description' => 'Configure the Pixel ID, CAPI access token, and Test Events code for Meta tracking.',
        'category' => 'Marketing',
        'fields' => [
            'pixel_id_label' => 'Pixel ID',
            'pixel_id_comment' => 'Your Meta Pixel ID (digits-only). Acquire from Meta Events Manager > Data sources > Pixel > Settings.',
            'capi_access_token_label' => 'CAPI Access Token',
            'capi_access_token_comment' => 'Conversions API access token. Acquire from Meta Events Manager > Settings > Generate access token.',
            'test_event_code_label' => 'Test Events Code',
            'test_event_code_comment' => 'Optional. Routes events to Meta Test Events panel for verification. Leave blank in production.',
        ],
    ],
];
```

Settings/fields.yaml (October backend form definition):

```yaml
fields:
    pixel_id:
        label: logingrupa.metapixel::lang.settings.fields.pixel_id_label
        commentAbove: logingrupa.metapixel::lang.settings.fields.pixel_id_comment
        type: text
        span: full
    capi_access_token:
        label: logingrupa.metapixel::lang.settings.fields.capi_access_token_label
        commentAbove: logingrupa.metapixel::lang.settings.fields.capi_access_token_comment
        type: password
        span: full
    test_event_code:
        label: logingrupa.metapixel::lang.settings.fields.test_event_code_label
        commentAbove: logingrupa.metapixel::lang.settings.fields.test_event_code_comment
        type: text
        span: full
```
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Write Settings model + fields.yaml</name>
  <files>
    plugins/logingrupa/metapixel/models/Settings.php
    plugins/logingrupa/metapixel/models/settings/fields.yaml
  </files>
  <behavior>
    - `Settings` extends `Lovata\Toolbox\Models\CommonSettings`; declares `$propagatable = []`; exposes static `lookupForSite(?int $iSiteId): array{pixel_id: string, capi_access_token: string}`.
    - `Settings/fields.yaml` defines 3 fields (pixel_id text, capi_access_token password, test_event_code text) using lang keys.
    - Both files php -l clean (fields.yaml validated via OctoberCMS load-on-boot).
  </behavior>
  <action>
Create `models/Settings.php`:

```
<?php

namespace Logingrupa\Metapixel\Models;

use Lovata\Toolbox\Models\CommonSettings;

/**
 * Plugin settings (single-row in Phase 2). The Multisite trait + per-field
 * whitelist on pixel_id + capi_access_token lands Phase 4 (MULT-01..02).
 *
 * lookupForSite is the credential-lookup contract callers (SendCapiEvent::handle)
 * use. Phase 2 stub returns the default row regardless of $iSiteId; Phase 4
 * MULT-03 re-implements to honor the Multisite per-site row routing.
 */
class Settings extends CommonSettings
{
    public $settingsCode = 'logingrupa_metapixel_settings';

    public $settingsFields = 'fields.yaml';

    /** @var list<string> */
    protected $propagatable = [];

    /**
     * Multisite-aware credential lookup. Phase 2 stub ignores $iSiteId.
     *
     * @return array{pixel_id: string, capi_access_token: string}
     */
    public static function lookupForSite(?int $iSiteId): array
    {
        return [
            'pixel_id' => (string) self::get('pixel_id', ''),
            'capi_access_token' => (string) self::get('capi_access_token', ''),
        ];
    }
}
```

Create `models/settings/fields.yaml` per the shape in `<interfaces>`. October convention: the Settings model's `$settingsFields` value `'fields.yaml'` resolves to `models/settings/fields.yaml` (snake-case version of model class name as directory). `[VERIFIED: plugins/lovata/shopaholic/models/settings/fields.yaml — same pattern]`.
  </action>
  <verify>
    <automated>test -f plugins/logingrupa/metapixel/models/Settings.php &amp;&amp; test -f plugins/logingrupa/metapixel/models/settings/fields.yaml &amp;&amp; php -l plugins/logingrupa/metapixel/models/Settings.php | grep -q 'No syntax errors' &amp;&amp; grep -q 'extends CommonSettings' plugins/logingrupa/metapixel/models/Settings.php &amp;&amp; grep -q 'lookupForSite' plugins/logingrupa/metapixel/models/Settings.php &amp;&amp; grep -q 'pixel_id:' plugins/logingrupa/metapixel/models/settings/fields.yaml</automated>
  </verify>
  <done>Settings.php + fields.yaml exist + Settings extends CommonSettings + has lookupForSite + $propagatable=[].</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Write PluginGuard helper + 5 exception classes</name>
  <files>
    plugins/logingrupa/metapixel/classes/Helper/PluginGuard.php
    plugins/logingrupa/metapixel/classes/Exception/MetaPixelException.php
    plugins/logingrupa/metapixel/classes/Exception/MissingPixelConfigException.php
    plugins/logingrupa/metapixel/classes/Exception/MissingCapiTokenException.php
    plugins/logingrupa/metapixel/classes/Exception/MetaApiTransientException.php
    plugins/logingrupa/metapixel/classes/Exception/MetaApiPermanentException.php
  </files>
  <behavior>
    - `PluginGuard` final class; static `isDisabled(): bool` memoised; static `reset(): void` clears memo; reads `Settings::get('pixel_id', '')`; empty → `Log::warning` + true; non-empty → false.
    - `MetaPixelException` abstract class extends `\RuntimeException`; constructor accepts `string $sMessage`, `?int $iCode = 0`, `?\Throwable $obPrevious = null`, `array $arContext = []`; getter `getContext(): array`.
    - 2 boundary exceptions (`MissingPixelConfigException`, `MissingCapiTokenException`) final, extend MetaPixelException.
    - 2 HTTP exceptions (`MetaApiTransientException`, `MetaApiPermanentException`) final, extend MetaPixelException, add `?int $iHttpStatus` constructor arg + `getHttpStatus(): ?int` getter.
    - All 6 files php -l clean.
    - All use `Illuminate\Support\Facades\Log` FQN (L-4).
  </behavior>
  <action>
Create `classes/Helper/PluginGuard.php`:

```
<?php

namespace Logingrupa\Metapixel\Classes\Helper;

use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixel\Models\Settings;

/**
 * Boot-time + event-time guard. Empty pixel_id → log + disable; never throws.
 *
 * Throwing at boot would cascade through OctoberCMS' plugin chain and break
 * unrelated plugins (Campaigns, PromoMechanism, etc). We disable softly here
 * and surface a single Log::warning per request via the memo.
 */
final class PluginGuard
{
    private static ?bool $bIsDisabled = null;

    public static function isDisabled(): bool
    {
        if (self::$bIsDisabled !== null) {
            return self::$bIsDisabled;
        }

        $sPixelId = (string) Settings::get('pixel_id', '');
        if ($sPixelId === '') {
            Log::warning('metapixel: pixel_id is empty — plugin running in disabled mode (events suppressed)');
            return self::$bIsDisabled = true;
        }

        return self::$bIsDisabled = false;
    }

    public static function reset(): void
    {
        self::$bIsDisabled = null;
    }
}
```

Create `classes/Exception/MetaPixelException.php` per the shape in `<interfaces>` — abstract base extends `\RuntimeException`; constructor takes `(string, int, ?Throwable, array)`; protected `$arContext` property; `getContext(): array` getter. Short Laravel docblock noting "Base exception for all Logingrupa.Metapixel plugin failures. Carries an optional context array for structured Log::* payloads."

Create `classes/Exception/MissingPixelConfigException.php` — `final class … extends MetaPixelException {}`. Short docblock: "Thrown at event-fire time when Settings::lookupForSite returns an empty pixel_id. Boot-time empty pixel_id is handled by PluginGuard (log + disable + no throw) — this exception only fires when an event has slipped past the guard for the current site row."

Create `classes/Exception/MissingCapiTokenException.php` — `final class … extends MetaPixelException {}`. Short docblock.

Create `classes/Exception/MetaApiTransientException.php` — `final class` extends MetaPixelException; private `?int $iHttpStatus = null` property; constructor `(string $sMessage = '', ?int $iHttpStatus = null, ?Throwable $obPrevious = null, array $arContext = [])` — calls `parent::__construct($sMessage, $iHttpStatus ?? 0, $obPrevious, $arContext)` then sets `$this->iHttpStatus`; `getHttpStatus(): ?int` getter. Short docblock: "Transient Graph API failure: 408 / 429 / 5xx + ConnectException. Caller (SendCapiEvent::handle) rethrows to trigger Laravel queue retry/backoff."

Create `classes/Exception/MetaApiPermanentException.php` — same shape; docblock: "Permanent Graph API failure: 4xx (other than 408/429). Caller persists a FailedEvent row and fires metapixel.event.dead_letter — does NOT retry."

L-5 (consistency tweak): both `MetaApi*Exception` constructors keep the same `(string, ?int, ?Throwable, array)` signature so SendCapiEvent's `failed()` can stamp http_status the same way `writeFailedEvent` does. Not strictly required for Phase 2 — flagged here for plan 02-06 cross-check.

All 6 files use the same `\RuntimeException` base via the abstract — final subclasses inherit `getContext()` automatically. The two HTTP exceptions add `getHttpStatus()` for the caller to record HTTP status on FailedEvent rows.
  </action>
  <verify>
    <automated>test -f plugins/logingrupa/metapixel/classes/Helper/PluginGuard.php &amp;&amp; for f in plugins/logingrupa/metapixel/classes/Exception/MetaPixelException.php plugins/logingrupa/metapixel/classes/Exception/MissingPixelConfigException.php plugins/logingrupa/metapixel/classes/Exception/MissingCapiTokenException.php plugins/logingrupa/metapixel/classes/Exception/MetaApiTransientException.php plugins/logingrupa/metapixel/classes/Exception/MetaApiPermanentException.php; do test -f "$f" || { echo "missing $f"; exit 1; }; php -l "$f" | grep -q 'No syntax errors' || exit 1; done &amp;&amp; grep -q 'final class PluginGuard' plugins/logingrupa/metapixel/classes/Helper/PluginGuard.php &amp;&amp; grep -q 'use Illuminate\\\\Support\\\\Facades\\\\Log;' plugins/logingrupa/metapixel/classes/Helper/PluginGuard.php &amp;&amp; grep -q 'abstract class MetaPixelException' plugins/logingrupa/metapixel/classes/Exception/MetaPixelException.php &amp;&amp; grep -q 'getHttpStatus' plugins/logingrupa/metapixel/classes/Exception/MetaApiTransientException.php &amp;&amp; grep -q 'getHttpStatus' plugins/logingrupa/metapixel/classes/Exception/MetaApiPermanentException.php</automated>
  </verify>
  <done>All 6 files exist + php -l clean; PluginGuard is final + has isDisabled/reset + imports Log FQN; exception hierarchy: abstract base + 2 boundary + 2 HTTP.</done>
</task>

<task type="auto">
  <name>Task 3: Wire Plugin::registerSettings() + lang files</name>
  <files>
    plugins/logingrupa/metapixel/Plugin.php
    plugins/logingrupa/metapixel/lang/en/lang.php
    plugins/logingrupa/metapixel/lang/lv/lang.php
  </files>
  <action>
Edit `Plugin.php` — read the file first (after plan 02-01 added the `use AdapterRegistry` + singleton bind). Add `registerSettings()` method below `boot()`:

```
/**
 * @return array<string, array<string, mixed>>
 */
public function registerSettings(): array
{
    return [
        'settings' => [
            'label' => 'logingrupa.metapixel::lang.settings.label',
            'description' => 'logingrupa.metapixel::lang.settings.description',
            'category' => 'logingrupa.metapixel::lang.settings.category',
            'icon' => 'icon-bullseye',
            'class' => \Logingrupa\Metapixel\Models\Settings::class,
            'order' => 500,
        ],
    ];
}
```

Edit `lang/en/lang.php`. Read existing first — Phase 1 may have shipped a minimal `plugin.name`/`plugin.description`. PRESERVE those keys exactly; ADD the `settings.*` tree per the shape in `<interfaces>`.

Edit `lang/lv/lang.php` with operator-quality Latvian translations (NOT machine-translated — read the Phase 1 Latvian file for tone/quality benchmark). Keep "Meta Pixel", "CAPI", "Conversions API", "Test Events" untranslated — these are Meta product names, treated as proper nouns in LV market context.

Sample LV translation (operator-quality):

```
return [
    'plugin' => [
        'name' => 'Meta Pixel + Conversions API',
        'description' => 'Servera-pusē dublēta Meta Pixel + CAPI izsekošana caur EventSubjectAdapter līgumu.',
    ],
    'settings' => [
        'label' => 'Meta Pixel + CAPI',
        'description' => 'Konfigurējiet Pixel ID, CAPI piekļuves marķieri un Test Events kodu Meta izsekošanai.',
        'category' => 'Mārketings',
        'fields' => [
            'pixel_id_label' => 'Pixel ID',
            'pixel_id_comment' => 'Jūsu Meta Pixel ID (tikai cipari). Iegūstams no Meta Events Manager > Datu avoti > Pixel > Iestatījumi.',
            'capi_access_token_label' => 'CAPI piekļuves marķieris',
            'capi_access_token_comment' => 'Conversions API piekļuves marķieris. Iegūstams no Meta Events Manager > Iestatījumi > Ģenerēt piekļuves marķieri.',
            'test_event_code_label' => 'Test Events kods',
            'test_event_code_comment' => 'Neobligāts. Pārvirza notikumus uz Meta Test Events paneli pārbaudei. Atstājiet tukšu produkcijā.',
        ],
    ],
];
```

L-1 caveat: the LV term "marķieris" for "token" is borrowed-Russian-flavored — accept as-is; native speaker review can refine in a separate translation-polish commit if needed.
  </action>
  <verify>
    <automated>grep -q 'registerSettings' plugins/logingrupa/metapixel/Plugin.php &amp;&amp; grep -q 'Logingrupa\\\\Metapixel\\\\Models\\\\Settings::class' plugins/logingrupa/metapixel/Plugin.php &amp;&amp; php -l plugins/logingrupa/metapixel/Plugin.php | grep -q 'No syntax errors' &amp;&amp; php -l plugins/logingrupa/metapixel/lang/en/lang.php | grep -q 'No syntax errors' &amp;&amp; php -l plugins/logingrupa/metapixel/lang/lv/lang.php | grep -q 'No syntax errors' &amp;&amp; grep -q 'pixel_id_label' plugins/logingrupa/metapixel/lang/en/lang.php &amp;&amp; grep -q 'pixel_id_label' plugins/logingrupa/metapixel/lang/lv/lang.php</automated>
  </verify>
  <done>Plugin.php has registerSettings; lang/en and lang/lv carry the settings tree.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 4: Write 4 settings/guard/exception tests (T7, T15, T23, T24)</name>
  <files>
    plugins/logingrupa/metapixel/tests/Unit/Helper/PluginGuardTest.php
    plugins/logingrupa/metapixel/tests/Unit/ExceptionHierarchyTest.php
    plugins/logingrupa/metapixel/tests/Feature/Settings/SettingsLookupForSiteTest.php
    plugins/logingrupa/metapixel/tests/Feature/Settings/SettingsCommonSettingsParentTest.php
  </files>
  <behavior>
    - T7 `PluginGuardTest::test_*` — empty pixel_id → isDisabled true + Log::warning; non-empty → false; reset clears memo. Uses `Log::shouldReceive('warning')->once()` via Mockery.
    - T15 `ExceptionHierarchyTest::test_*` — instantiate each exception, assert it extends MetaPixelException, assert getContext returns the passed array, assert MetaApi*Exception's getHttpStatus returns the passed code.
    - T23 `SettingsLookupForSiteTest::test_returns_default_row_regardless_of_site_id` — store pixel_id='X', token='Y' via `Settings::set([...])`, call `Settings::lookupForSite(null)` + `Settings::lookupForSite(7)` → both return the same {pixel_id:'X', capi_access_token:'Y'} (Phase 2 stub).
    - T24 `SettingsCommonSettingsParentTest::test_*` — assert `is_a(Settings::class, CommonSettings::class, true)`; assert reflection of `propagatable` property returns `[]`.
    - All tests pass.
  </behavior>
  <action>
Follow Phase 1 PluginSanityTest's class-style convention (L-8 classic Pest). Hungarian-notation locals. No phase markers.

T7 `PluginGuardTest.php`:

```
<?php

use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Helper\PluginGuard;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;

final class PluginGuardTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
        PluginGuard::reset();
    }

    public function test_is_disabled_returns_true_when_pixel_id_is_empty(): void
    {
        Settings::set(['pixel_id' => '']);
        Log::shouldReceive('warning')->once();
        $this->assertTrue(PluginGuard::isDisabled());
    }

    public function test_is_disabled_returns_false_when_pixel_id_is_set(): void
    {
        Settings::set(['pixel_id' => '1234567890']);
        $this->assertFalse(PluginGuard::isDisabled());
    }

    public function test_reset_clears_the_memo(): void
    {
        Settings::set(['pixel_id' => '1234567890']);
        PluginGuard::isDisabled();  // memoise false

        Settings::set(['pixel_id' => '']);
        $this->assertFalse(PluginGuard::isDisabled(), 'memoised value wins until reset');

        PluginGuard::reset();
        Log::shouldReceive('warning')->once();
        $this->assertTrue(PluginGuard::isDisabled());
    }
}
```

T15 `ExceptionHierarchyTest.php`: 5 tests assert (a) `MetaPixelException` is abstract + extends `\RuntimeException`; (b) `MissingPixelConfigException` instance carries context array; (c) `MissingCapiTokenException` instance is_a MetaPixelException; (d) `MetaApiTransientException` carries http_status via getHttpStatus; (e) `MetaApiPermanentException` same. All tests use the H-8 setUp pattern (singleton bind).

T23 `SettingsLookupForSiteTest.php`: 2 tests assert (a) `Settings::lookupForSite(null)` returns `{pixel_id, capi_access_token}` matching `Settings::set()` values; (b) `Settings::lookupForSite(7)` returns same as `Settings::lookupForSite(null)` (stub ignores $iSiteId). Plus assert empty-strings when unset.

T24 `SettingsCommonSettingsParentTest.php`: 2 tests assert (a) `is_a(Settings::class, CommonSettings::class, true)`; (b) reflection of `propagatable` property returns `[]`.

All 4 test files use the H-8 setUp pattern: `$this->app->singleton(AdapterRegistry::class)` direct bind — never `(new Plugin)->register()`.
  </action>
  <verify>
    <automated>for f in plugins/logingrupa/metapixel/tests/Unit/Helper/PluginGuardTest.php plugins/logingrupa/metapixel/tests/Unit/ExceptionHierarchyTest.php plugins/logingrupa/metapixel/tests/Feature/Settings/SettingsLookupForSiteTest.php plugins/logingrupa/metapixel/tests/Feature/Settings/SettingsCommonSettingsParentTest.php; do test -f "$f" || { echo "missing $f"; exit 1; }; php -l "$f" | grep -q 'No syntax errors' || exit 1; done &amp;&amp; ! grep -rE '\(new\s+\\?Logingrupa\\\\Metapixel\\\\Plugin\)' plugins/logingrupa/metapixel/tests/Unit/Helper/ plugins/logingrupa/metapixel/tests/Unit/ExceptionHierarchyTest.php plugins/logingrupa/metapixel/tests/Feature/Settings/ &amp;&amp; cd plugins/logingrupa/metapixel &amp;&amp; ../../../vendor/bin/pest tests/Unit/Helper tests/Unit/ExceptionHierarchyTest.php tests/Feature/Settings --configuration phpunit.xml 2&gt;&amp;1 | tail -10 | grep -Eq '(PASS|OK|Tests:.*passed|passed)'</automated>
  </verify>
  <done>4 test files exist + php -l clean; H-8 setUp pattern enforced (no `(new Plugin)` instantiations); pest run on the 4 test files exits 0.</done>
</task>

<task type="auto">
  <name>Task 5: composer qa + commit</name>
  <files>
    plugins/logingrupa/metapixel/models/Settings.php
    plugins/logingrupa/metapixel/models/settings/fields.yaml
    plugins/logingrupa/metapixel/classes/Helper/PluginGuard.php
    plugins/logingrupa/metapixel/classes/Exception/
    plugins/logingrupa/metapixel/Plugin.php
    plugins/logingrupa/metapixel/lang/
    plugins/logingrupa/metapixel/tests/Unit/Helper/PluginGuardTest.php
    plugins/logingrupa/metapixel/tests/Unit/ExceptionHierarchyTest.php
    plugins/logingrupa/metapixel/tests/Feature/Settings/
  </files>
  <action>
From `plugins/logingrupa/metapixel/`:

```
composer qa 2>&1 | tee /tmp/02-03b-qa.log | tail -30
```

If phpstan flags level 10 errors in the new files:

- `Lovata\Toolbox\Models\CommonSettings` not found → larastan should resolve it; if not, add to phpstan.neon `universalObjectCratesClasses` or `excludePaths` for the inheritance check.
- `Settings::set` / `Settings::get` not found → these come from October's `SettingModel` base via CommonSettings; if larastan misses them, add a class-level PHPDoc `@method static void set(array $arValues)` + `@method static mixed get(string $sKey, mixed $mDefault = null)` to `Settings.php`.

If phpmd fires: every helper / exception method is straight-line; no risk.

If `pest --coverage --min=90`:
- Settings::lookupForSite has 2 branches — both covered by T23.
- PluginGuard.isDisabled has 3 branches (memo hit, empty pixel, non-empty pixel) — covered by T7.
- Exceptions are mostly constructors — `__construct` + `getContext` + `getHttpStatus` covered by T15.

Expected: coverage ≥ 90% across all new code.

Commit:

```
git add plugins/logingrupa/metapixel/models/Settings.php \
        plugins/logingrupa/metapixel/models/settings/ \
        plugins/logingrupa/metapixel/classes/Helper/PluginGuard.php \
        plugins/logingrupa/metapixel/classes/Exception/ \
        plugins/logingrupa/metapixel/Plugin.php \
        plugins/logingrupa/metapixel/lang/ \
        plugins/logingrupa/metapixel/tests/Unit/Helper/PluginGuardTest.php \
        plugins/logingrupa/metapixel/tests/Unit/ExceptionHierarchyTest.php \
        plugins/logingrupa/metapixel/tests/Feature/Settings/

git commit -m "$(cat <<'EOF'
feat(metapixel): Settings + PluginGuard + exception hierarchy (Phase 2 config half)

Settings extends Lovata.Toolbox CommonSettings, ships Phase 2 single-row
shape with lookupForSite(?int \$iSiteId) stub returning the default row
(Phase 4 MULT-03 swaps in Multisite per-site routing).

PluginGuard final class memoises empty-pixel-id check with Log::warning +
disabled flag; never throws at boot (would cascade-break Campaigns +
PromoMechanism chains). Imports Illuminate\Support\Facades\Log FQN (L-4).

Exception hierarchy: abstract MetaPixelException base + 4 final subclasses
(MissingPixelConfigException, MissingCapiTokenException,
MetaApiTransientException, MetaApiPermanentException). HTTP exceptions
carry getHttpStatus(); all carry getContext().

Plugin.php gains registerSettings(); lang/en + lang/lv carry the Settings
UI strings (operator-quality LV translation, not machine-translated).

Four tests: PluginGuardTest (memo + reset + Log::warning expectation),
ExceptionHierarchyTest (5 exception classes), SettingsLookupForSiteTest
(stub returns default row), SettingsCommonSettingsParentTest (extends +
propagatable=[]). All use the \$this->app->singleton(AdapterRegistry::class)
direct setUp pattern.

Plan 02-03a shipped storage layer (migrations + EventLog/FailedEvent
models) in parallel Wave 2. Wave 3 (plans 02-04 + 02-05) unblocks when
BOTH 02-03a AND 02-03b commit.
EOF
)"
```
  </action>
  <verify>
    <automated>cd plugins/logingrupa/metapixel &amp;&amp; composer qa 2&gt;&amp;1 | tail -5 | grep -Eq '(OK|PASS|0 errors|tests passed|No issues found)' &amp;&amp; git log -1 --pretty=format:'%s' | grep -q 'Settings.*PluginGuard.*exception' &amp;&amp; git diff-tree --no-commit-id --name-only -r HEAD | grep -c '^plugins/logingrupa/metapixel/' | xargs test 14 -le</automated>
  </verify>
  <done>composer qa exits 0; commit on HEAD touches ≥ 14 files; commit message references Settings + PluginGuard + exception hierarchy + plan 02-03a parallel-Wave-2 note.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Settings::set in tests vs production seeds | Tests call `Settings::set([...])` to inject fixture data — the same API is used by production Settings UI on save. The Phase 2 stub `lookupForSite` ignores `$iSiteId` so multi-site test setups cannot inject distinct per-site values yet (that's Phase 4). |
| PluginGuard memo across requests | Static memo `?bool $bIsDisabled = null` persists for the lifetime of the PHP-FPM request. tearDown in MetapixelTestCase does NOT reset it automatically — tests must call `PluginGuard::reset()` in setUp. Plan 02-07's contract base does this for future test consumers. |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-02-03b-01 | Repudiation | Operator wonders why CAPI events aren't firing — no record of "plugin disabled" state | mitigate | PluginGuard writes `Log::warning('metapixel: pixel_id is empty …')` on the first check per request. Surface visible in Forge logs / OctoberCMS event log. |
| T-02-03b-02 | Tampering | A misbehaving plugin extends Settings and changes `$propagatable` to leak per-site data | accept | `$propagatable = []` is the Phase 2 lock — Phase 4 MULT-01..02 adds the explicit whitelist (`pixel_id`, `capi_access_token`). Tampering requires file-system access to plugin source; out-of-band attack surface. |
| T-02-03b-03 | Elevation of Privilege | Settings::set bypasses authorization | accept | OctoberCMS backend permission system gates Settings UI access. Tests use Settings::set directly (model-level write); production write paths require backend auth. |
| T-02-03b-04 | Information Disclosure | Exception context arrays may leak secrets in logs | mitigate | MetaPixelException::getContext is opt-in — callers control what they pass. Plan 02-05 MetaClient passes `{url, response}` (URL has no token; response body is Graph's JSON). Plan 02-06 SendCapiEvent.writeFailedEvent only persists exception message + json_encoded context — not the raw payload's hashed user_data. |
| T-02-03b-05 | Denial of Service | Settings.get reads from disk on every PluginGuard::isDisabled call | accept | OctoberCMS caches CommonSettings reads via its own framework cache. PluginGuard's memo wraps a second layer — single Settings::get per request after the first. |

</threat_model>

<verification>
## Goal-Backward Reachability Audit

1. "Settings extends CommonSettings + lookupForSite stub works" — Task 1 + T23/T24.
2. "PluginGuard memoises empty-pixel disabled state with Log::warning" — Task 2 + T7.
3. "5 exception classes form a hierarchy from MetaPixelException base" — Task 2 + T15.
4. "Plugin.php registerSettings descriptor surfaces in OctoberCMS backend" — Task 3 (runtime smoke happens at Phase 5 when an operator boots a fresh OctoberCMS; Phase 2 verifies syntactic correctness via php -l + phpstan).
5. "composer qa exits 0 with new code" — Task 5 verifies.

No must-have is UNREACHABLE.

## Multi-Source Coverage Audit

| Source item | Type | Coverage | Notes |
|-------------|------|----------|-------|
| RESEARCH §4.10 PluginGuard shape | Reference | Task 2 | Memoised isDisabled + reset; never throws |
| RESEARCH §4.11 Settings shape (extends CommonSettings, propagatable=[], lookupForSite stub) | Reference | Task 1 | All three honored |
| RESEARCH §4.15 Plugin::registerSettings | Reference | Task 3 | Settings descriptor returned |
| RESEARCH §6 T7, T15, T23, T24 tests | Reference | Task 4 | All 4 tests land |
| Locked decision "PluginGuard pattern: empty pixel_id → Log::warning + disabled flag, NEVER throw at boot" (project lock, CLAUDE.md "## Locked decisions") | Constraint | Task 2 | PluginGuard.isDisabled returns bool, never throws |
| Locked decision "Settings extends Lovata.Toolbox CommonSettings" (PROJECT.md key decisions) | Constraint | Task 1 | extends CommonSettings |
| Locked decision "Hungarian notation" | Constraint | All tasks | $obException, $obPrevious, $arContext, etc. |
| Locked decision "No declare(strict_types=1) enforcement" | Constraint | All tasks | No declare statement in any new file |
| Plan-checker M-2 (plan-3 split) | Revision | This plan IS the config half | 02-03a shipped storage layer in parallel Wave 2 |
| Plan-checker H-8 (Plugin instantiation in tests) | Revision | Task 4 | All 4 test setUps use `$this->app->singleton(AdapterRegistry::class)` direct bind |
| Plan-checker L-4 (Log facade FQN) | Revision | Task 2 | PluginGuard imports `Illuminate\Support\Facades\Log` |
| Plan-checker L-8 (classic Pest style) | Revision | Task 4 | All 4 test files use `final class FooTest extends MetapixelTestCase` |
| Plan-checker L-5 (failed() snapshot consistency) | Revision (note for 02-06) | Task 2 — MetaApi* exception constructor shape preserved | SendCapiEvent.failed() can stamp http_status the same way writeFailedEvent does |

No gaps.

## Acceptance gate

`composer qa` exits 0 from `plugins/logingrupa/metapixel/` after Task 5's commit.
</verification>

<success_criteria>
Plan 02-03b ships when ALL of the following hold:

1. `models/Settings.php` extends `Lovata\Toolbox\Models\CommonSettings`; `$propagatable = []`; static `lookupForSite(?int $iSiteId): array` returns default row regardless of $iSiteId.
2. `models/settings/fields.yaml` defines 3 fields using lang keys.
3. `classes/Helper/PluginGuard.php` is final; static isDisabled memoised + reset; Log::warning on empty pixel_id; never throws; imports `Illuminate\Support\Facades\Log` FQN (L-4).
4. 5 exception classes under `classes/Exception/`: abstract MetaPixelException base + 4 finals (MissingPixelConfigException, MissingCapiTokenException, MetaApiTransientException, MetaApiPermanentException). Last two carry getHttpStatus(); all carry getContext().
5. Plugin.php registerSettings() returns descriptor with class = Settings::class.
6. lang/en/lang.php + lang/lv/lang.php carry the settings.* tree; LV is operator-quality.
7. All 4 test files exist + pass; test method count ≥ 9 in aggregate; all use H-8 setUp pattern.
8. composer qa exits 0; coverage ≥ 90% on new code.
9. Single commit on HEAD; commit message references Settings + PluginGuard + exception hierarchy + plan 02-03a parallel-Wave-2 note.
10. No comment pollution in new source files.
</success_criteria>

<output>
After completion, create `plugins/logingrupa/metapixel/.planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-03b-SUMMARY.md` documenting:

- Single commit SHA.
- Composer qa output tail (last 30 lines).
- Test pass counts per file: PluginGuardTest, ExceptionHierarchyTest, SettingsLookupForSiteTest, SettingsCommonSettingsParentTest.
- Coverage numbers per new file: aim to record line + branch coverage for classes/Helper/PluginGuard.php, classes/Exception/*, models/Settings.php.
- Phase 2 plan-state update: 02-03b closed; 02-03a should be closed in parallel (Wave 2); plans 02-04 (SiteResolver + EventLogWriter) + 02-05 (MetaClient + PayloadBuilder + UserDataHasher) unblock when BOTH 02-03a AND 02-03b commit.
- Any phpstan ignoreErrors entries added (flag for plan 02-07 review).
</output>

## Revision History
- 2026-05-17 R1: Created as second half of plan-checker M-2 split (originally part of monolithic 02-03 storage+settings plan, now decomposed into 02-03a storage layer + 02-03b settings/guard/exceptions running parallel in Wave 2). Adopts H-8 setUp pattern across the 4 new tests, L-4 Log facade FQN import in PluginGuard, L-8 classic Pest style in all test files, and notes L-5 constructor shape preservation for plan 02-06 failed() consistency.
