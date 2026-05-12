# Phase 2: Skeleton + cookie fix — Pattern Map

**Mapped:** 2026-05-12
**Files analyzed:** 11 new + 1 modified
**Analogs found:** 10 / 12 (Plugin.php = `goodsreceivedshopaholic/Plugin.php` exact; middleware = no Lovata analog, copy Laravel `AddQueuedCookiesToResponse` shape)

---

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|---|---|---|---|---|
| `Plugin.php` (rewrite) | plugin-root boot | request-boot wiring | `plugins/logingrupa/goodsreceivedshopaholic/Plugin.php` | exact |
| `plugin.yaml` | config / metadata | static | `plugins/logingrupa/campaignpricingshopaholic/plugin.yaml` | exact |
| `models/Settings.php` | model (SettingModel) | persisted config | `plugins/logingrupa/goodsreceivedshopaholic/models/Settings.php` | exact |
| `models/settings/fields.yaml` | config (backend form) | static | `plugins/logingrupa/goodsreceivedshopaholic/models/settings/fields.yaml` + `plugins/lovata/shopaholic/models/settings/fields.yaml` (dropdown) | role-match |
| `middleware/EnsureFbpFbcCookies.php` | middleware | request-response (cookie set) | `vendor/laravel/framework/src/Illuminate/Cookie/Middleware/AddQueuedCookiesToResponse.php` | shape-match (no Lovata/Logingrupa precedent) |
| `classes/helper/PluginGuard.php` | helper (singleton) | settings read + memo | `plugins/lovata/toolbox/classes/helper/UserHelper.php` + `plugins/logingrupa/goodsreceivedshopaholic/classes/support/SettingsAccessor.php` | exact (two parents, deliberate hybrid) |
| `components/PixelHead.php` | component | request-response (onRun + Twig vars) | `plugins/logingrupa/storeextender/components/LazyPromoBlockLoader.php` | exact |
| `components/pixelhead/default.htm` | view / Twig partial | rendered HTML | `plugins/logingrupa/campaignpricingshopaholic/components/campaignpricing/default.htm` + `themes/logingrupa-naisstore/partials/facebook_pixel.htm` | exact (component partial) + reference (target script shape) |
| `lang/en/lang.php` | config (i18n) | static keys | `plugins/logingrupa/goodsreceivedshopaholic/lang/en/lang.php` | exact |
| `lang/lv/lang.php` | config (i18n) | static keys | `plugins/logingrupa/campaignpricingshopaholic/lang/lv/lang.php` | exact (Latvian translation pattern) |
| `lang/ru/lang.php` | config (i18n) | static keys | `plugins/logingrupa/campaignpricingshopaholic/lang/ru/lang.php` | exact |
| `tests/Feature/BootsWithoutPixelIdTest.php` | test (Pest feature) | boot harness assertion | `plugins/logingrupa/metapixelshopaholic/tests/Unit/SanityTest.php` (Phase 1) | exact |
| `tests/Feature/EnsureFbpFbcCookiesTest.php` | test (Pest feature) | middleware request/response | `plugins/logingrupa/goodsreceivedshopaholic/tests/unit/Support/SettingsAccessorTest.php` (Pest pattern only) | role-match |
| `tests/Feature/SettingsRegistrationTest.php` | test (Pest feature) | Settings model save/load | `plugins/logingrupa/goodsreceivedshopaholic/tests/unit/Support/SettingsAccessorTest.php` | exact |

**Modified file (Phase 1 → Phase 2):** `tests/MetapixelTestCase.php` — add `flushPluginSingletons()` hook (mirror `GoodsReceivedTestCase::flushPluginSingletons()` at `plugins/logingrupa/goodsreceivedshopaholic/tests/GoodsReceivedTestCase.php:58-64,99-104`) so `PluginGuard::flush()` runs in `tearDown()` per Phase 2 CONTEXT Area 4 Q3.

---

## Reusable Assets (already on disk, do not recreate)

| Asset | Path | Used by |
|---|---|---|
| `Lovata\Toolbox\Models\CommonSettings` | `plugins/lovata/toolbox/models/CommonSettings.php` | `models/Settings.php` parent — provides `Multisite` trait + `RainLab.Translate.Behaviors.TranslatableModel` implement + `Settings::get($sCode, $default)` static |
| `October\Rain\Support\Traits\Singleton` | (vendor; used by all Toolbox helpers) | `classes/helper/PluginGuard.php` trait |
| `System\Classes\PluginManager::instance()->exists('Lovata.Buddies')` | precedent in `plugins/lovata/toolbox/classes/helper/UserHelper.php:138-145` | Reuse pattern in `PluginGuard` if optional plugin gating is needed |
| `Lovata\OrdersShopaholic\Models\Status` | `plugins/lovata/ordersshopaholic/models/Status.php` | `Settings::getPaidStatusCodeOptions()` → `Status::lists('name','code')` (uses `CodeField` scope, see line 39) |
| `Logingrupa\Metapixelshopaholic\Tests\MetapixelTestCase` | `plugins/logingrupa/metapixelshopaholic/tests/MetapixelTestCase.php` | Already mirrors `CampaignPricingTestCase`; all Phase 2 feature tests extend this |

---

## Established Patterns (cross-cutting; apply to all relevant Phase 2 files)

### P1 — Hungarian notation in Lovata.Toolbox style
- `$ob` object/model/collection, `$ar` array, `$i` int, `$s` string, `$b` bool, `$f` float
- Confirmed by `plugins/logingrupa/storeextender/classes/event/cart/CartComponentHandler.php` and `plugins/logingrupa/goodsreceivedshopaholic/classes/support/SettingsAccessor.php`
- phpmd.xml `LongVariable max=40`, `ShortVariable min=4`

### P2 — Plugin namespace casing
- `Logingrupa\Metapixelshopaholic` (CamelCase plugin segment, **no** capital `S` in `Shopaholic`)
- Per LR-01 locked decision (CONTEXT Area 4 Q1). Sibling `Logingrupa\Campaignpricingshopaholic` uses identical style.

### P3 — `#[\Override]` attribute on every overridden method
- `plugins/logingrupa/campaignpricingshopaholic/Plugin.php:33,67` and `plugins/logingrupa/goodsreceivedshopaholic/Plugin.php:34,59,117,164` annotate `pluginDetails()`, `register()`, `boot()`, `registerComponents()`, `registerPermissions()`, `registerSettings()`.
- Apply on `Plugin::pluginDetails()`, `Plugin::boot()`, `Plugin::registerSettings()`, `Plugin::registerComponents()`.

### P4 — `public $require` is a `list<string>`
- `plugins/logingrupa/campaignpricingshopaholic/Plugin.php:22-27` and `plugins/logingrupa/goodsreceivedshopaholic/Plugin.php:21-28` both declare `@var list<string>` PHPDoc and use array-of-plugin-codes.
- Phase 2 value (CONTEXT Area 1 Q4): `['Lovata.Toolbox', 'Lovata.Shopaholic', 'Lovata.OrdersShopaholic']` — Buddies dropped to `composer.json` `suggest`.

### P5 — `pluginDetails()` returns lang-key strings, not literals
- `plugins/logingrupa/campaignpricingshopaholic/Plugin.php:36-41` returns `'logingrupa.campaignpricingshopaholic::lang.plugin.name'` etc.
- Current `Plugin.php:21-26` ships literals (`'Metapixel Shopaholic'`). Phase 2 swaps to `'logingrupa.metapixelshopaholic::lang.plugin.name'` so RainLab.Translate resolves.

### P6 — Event::subscribe registration in `boot()`
- `plugins/logingrupa/campaignpricingshopaholic/Plugin.php:47-61` uses `Event::subscribe(ClassName::class)` for stateless and `(new Handler())->subscribe()` for instance-bound.
- Phase 2 boot() body is intentionally minimal (CONTEXT Area 1 Q1): NO `Event::subscribe(...)` calls; only middleware push + PluginGuard prime. Handlers ship Phase 3+.

### P7 — Tiger-Style fail-fast
- Boundary layer only catches (PixelHead onRun, ElementPage 404). Business code throws.
- Every `catch` logs + rethrows OR carries an explicit `// silent: reason …` comment.
- Confirmed by `plugins/logingrupa/storeextender/Plugin.php:138-145` (Event listener catches inside callbacks documented).

### P8 — Backend form fields reference lang keys
- All `label`/`commentAbove` in `models/settings/fields.yaml` use the `'logingrupa.metapixelshopaholic::lang.field.*'` format. See `plugins/logingrupa/goodsreceivedshopaholic/models/settings/fields.yaml:8-29` for canonical examples.

---

## Per-File Analogs

### `Plugin.php` (rewrite — plugin-root boot, request-boot wiring)

**Analog:** `plugins/logingrupa/goodsreceivedshopaholic/Plugin.php`

**Imports + class header pattern** (`Plugin.php:1-43`):
```php
<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic;

use Backend;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Event;
use Logingrupa\GoodsReceivedShopaholic\Console\RecomputeActiveFromStock;
use Logingrupa\GoodsReceivedShopaholic\Models\Settings;
use System\Classes\PluginBase;

class Plugin extends PluginBase
{
    /**
     * Required plugins
     * @var list<string>
     */
    public $require = [
        'Lovata.Toolbox',
        'Lovata.Shopaholic',
    ];

    /**
     * Returns information about this plugin
     * @return array<string, string>
     */
    #[\Override]
    public function pluginDetails(): array
    {
        return [
            'name'        => 'logingrupa.goodsreceivedshopaholic::lang.plugin.name',
            'description' => 'logingrupa.goodsreceivedshopaholic::lang.plugin.description',
            'author'      => 'Logingrupa',
            'icon'        => 'icon-truck',
        ];
    }
```

**`registerSettings()` pattern** (`Plugin.php:164-177`):
```php
#[\Override]
public function registerSettings(): array
{
    return [
        'goodsreceived-settings' => [
            'label'       => 'logingrupa.goodsreceivedshopaholic::lang.settings.label',
            'description' => 'logingrupa.goodsreceivedshopaholic::lang.settings.description',
            'category'    => 'lovata.shopaholic::lang.tab.settings',
            'icon'        => 'icon-truck',
            'class'       => Settings::class,
            'order'       => 500,
        ],
    ];
}
```

**Middleware registration — Laravel-native** (the audit at `.planning/audits/04-architecture.md:88-97` cites `$this->registerMiddleware([...])` but `PluginBase` has **no such method** — verified at `modules/system/classes/PluginBase.php`, lines 40-291 list every `register*` method; middleware is absent). Use Laravel's HTTP Kernel directly (signature confirmed at `vendor/laravel/framework/src/Illuminate/Foundation/Http/Kernel.php:362-369`):
```php
// In Plugin::boot(), wrapped to skip backend + console contexts
public function boot(): void
{
    if (App::runningInBackend() || App::runningInConsole()) {
        return;
    }

    /** @var \Illuminate\Contracts\Http\Kernel $obKernel */
    $obKernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
    $obKernel->pushMiddleware(\Logingrupa\Metapixelshopaholic\Middleware\EnsureFbpFbcCookies::class);

    // Prime PluginGuard so the disabled flag is computed once per request.
    \Logingrupa\Metapixelshopaholic\Classes\Helper\PluginGuard::instance();
}
```
Backend-gated boot pattern source: `plugins/logingrupa/goodsreceivedshopaholic/Plugin.php:75-104` (`if (! App::runningInBackend()) { return; }`).

**Why not `declare(strict_types=1)`:** PROJECT.md constraint at line 64 — "zero ecosystem usage in Lovata/Logingrupa files. Optional per-file." `campaignpricingshopaholic/Plugin.php` omits it; `goodsreceivedshopaholic/Plugin.php` includes it. Phase 2 decision deferred to planner discretion (CONTEXT does not lock).

---

### `plugin.yaml` (metadata, optional under October 4 auto-discovery)

**Analog:** `plugins/logingrupa/campaignpricingshopaholic/plugin.yaml` (lines 1-6):
```yaml
plugin:
    name: 'logingrupa.campaignpricingshopaholic::lang.plugin.name'
    description: 'logingrupa.campaignpricingshopaholic::lang.plugin.description'
    author: Logingrupa
    icon: icon-tags
    homepage: 'https://logingrupa.lv'
```
**Decision pending in plan** (CONTEXT Claude's Discretion bullet 6): October 4 auto-discovers via `pluginDetails()`. If included, must mirror `pluginDetails()` exactly (same lang-key references, same `icon`). Recommend `icon: icon-shopping-cart` to match the existing literal in current `Plugin.php:25`.

---

### `models/Settings.php` (model, persisted config)

**Analog:** `plugins/logingrupa/goodsreceivedshopaholic/models/Settings.php` (lines 1-47) — strong, with one variant decision:

```php
<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Models;

use October\Rain\Database\Traits\Multisite;
use System\Models\SettingModel;

class Settings extends SettingModel
{
    use Multisite;

    public const SETTINGS_CODE = 'logingrupa_goodsreceivedshopaholic_settings';

    /** @var string */
    public $settingsCode = self::SETTINGS_CODE;

    /** @var string */
    public $settingsFields = 'fields.yaml';

    /** @var array<int, string> */
    protected $propagatable = [];
}
```

**Lovata-canonical alternative (recommended by SKEL-02 + PROJECT.md Key Decisions line 82):** extend `Lovata\Toolbox\Models\CommonSettings` (which already wires `Multisite` + `RainLab.Translate.Behaviors.TranslatableModel` + `propagatable = []`) instead of bare `SettingModel`:
```php
<?php namespace Logingrupa\Metapixelshopaholic\Models;

use Lovata\OrdersShopaholic\Models\Status;
use Lovata\Toolbox\Models\CommonSettings;

class Settings extends CommonSettings
{
    const SETTINGS_CODE = 'logingrupa_metapixelshopaholic_settings';

    public $settingsCode = 'logingrupa_metapixelshopaholic_settings';

    public $translatable = ['pixel_id'];

    /**
     * Dropdown options for paid_status_code field
     * @return array<string, string>
     */
    public function getPaidStatusCodeOptions(): array
    {
        return (array) Status::lists('name', 'code');
    }

    /**
     * Dropdown options for queue_connection field
     * @return array<string, string>
     */
    public function getQueueConnectionOptions(): array
    {
        return [
            'database' => 'database',
            'redis'    => 'redis',
            'sync'     => 'sync',
        ];
    }
}
```
`CommonSettings` reference: `plugins/lovata/toolbox/models/CommonSettings.php:11-41`. The `Status::lists('name','code')` is the SKEL-02-locked call (specifics-line 110 of CONTEXT). `getPaidStatusCodeOptions()` naming matches the `getXxxOptions()` convention seen in `plugins/lovata/shopaholic/models/Settings.php:25-61`.

---

### `models/settings/fields.yaml` (10 field definitions)

**Analog A — switch + text + comment pattern:** `plugins/logingrupa/goodsreceivedshopaholic/models/settings/fields.yaml:6-29`:
```yaml
fields:
    enabled:
        label: 'logingrupa.goodsreceivedshopaholic::lang.field.enabled'
        commentAbove: 'logingrupa.goodsreceivedshopaholic::lang.field.enabled_comment'
        type: switch
        default: false
```

**Analog B — dropdown with `options: methodName` pattern:** `plugins/lovata/shopaholic/models/settings/fields.yaml:90-96`:
```yaml
default_product_page_id:
    label: lovata.shopaholic::lang.field.default_product_page
    tab: lovata.shopaholic::lang.tab.page_settings
    type: dropdown
    span: left
    emptyOption: lovata.toolbox::lang.field.empty
    options: getPageIdListOptions
```
- Method `getXxxOptions` on the model is auto-invoked by October's form builder.
- Apply to `paid_status_code` → `options: getPaidStatusCodeOptions`; `queue_connection` → `options: getQueueConnectionOptions`.

**Required Phase 2 field set** (SKEL-02 + CONTEXT decisions): `pixel_id` (text, translatable), `capi_access_token` (password), `test_event_code` (text), `currency_code` (text, default `EUR`), `phone_country_code` (text, default `371`), `send_hashed_pii` (switch, on), `queue_connection` (dropdown), `paid_status_code` (dropdown, default `new-payment-received`), `refire_purchase_on_status_flip` (switch, off), `ensure_fbp_fbc_server_side` (switch, on).

**Tab grouping decision pending** (CONTEXT Claude's Discretion bullet 5): single tab vs split `Tracking` / `Compliance` / `Advanced`. Lovata convention groups by `tab:` key — see `plugins/lovata/shopaholic/models/settings/fields.yaml:29,37,42` for multi-tab example.

---

### `middleware/EnsureFbpFbcCookies.php` (Laravel HTTP middleware)

**Analog (shape only — no Lovata precedent):** `vendor/laravel/framework/src/Illuminate/Cookie/Middleware/AddQueuedCookiesToResponse.php:8-44`:
```php
class AddQueuedCookiesToResponse
{
    public function __construct(CookieJar $cookies)
    {
        $this->cookies = $cookies;
    }

    public function handle($request, Closure $next)
    {
        $response = $next($request);

        foreach ($this->cookies->getQueuedCookies() as $cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }
}
```

**Target shape for `EnsureFbpFbcCookies::handle`** (per SKEL-03 + CONTEXT Area 3 Q1-Q4):
```php
<?php namespace Logingrupa\Metapixelshopaholic\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

class EnsureFbpFbcCookies
{
    /** Meta-spec _fbp/_fbc 90-day expiry. */
    private const COOKIE_TTL_SECONDS = 60 * 60 * 24 * 90;

    /** Meta-spec subdomain-index cap (per spec line in CONTEXT specifics:107). */
    private const SUBDOMAIN_INDEX_CAP = 2;

    public function handle(Request $obRequest, Closure $fnNext)
    {
        /** @var \Symfony\Component\HttpFoundation\Response $obResponse */
        $obResponse = $fnNext($obRequest);

        $iSubdomainIndex = min(self::SUBDOMAIN_INDEX_CAP, count(explode('.', $obRequest->getHost())) - 1);
        $iCreationTimeMs = time() * 1000;
        $bSecure = $obRequest->secure();

        if ($obRequest->cookie('_fbp') === null) {
            $sFbp = sprintf('fb.%d.%d.%s', $iSubdomainIndex, $iCreationTimeMs, bin2hex(random_bytes(8)));
            $obResponse->headers->setCookie(
                Cookie::create('_fbp', $sFbp, time() + self::COOKIE_TTL_SECONDS, '/', null, $bSecure, false, false, 'lax')
            );
        }

        $sFbclid = (string) $obRequest->query('fbclid', '');
        if ($sFbclid !== '' && $obRequest->cookie('_fbc') === null) {
            $sFbc = sprintf('fb.%d.%d.%s', $iSubdomainIndex, $iCreationTimeMs, $sFbclid);
            $obResponse->headers->setCookie(
                Cookie::create('_fbc', $sFbc, time() + self::COOKIE_TTL_SECONDS, '/', null, $bSecure, false, false, 'lax')
            );
        }

        return $obResponse;
    }
}
```

**Constraints embedded in CONTEXT (binding decisions):**
- `httpOnly = false` (browser reads `_fbp` for `fbq`) — Area 3 Q3
- Implicit current-host scope (NO `domain=.nailscosmetics.lv`) — Specifics line 108
- `SameSite=Lax` — Area 3 Q3
- `_fbc` only generated when `fbclid` query param present — Area 3 Q4

---

### `classes/helper/PluginGuard.php` (Singleton helper)

**Analog A — Singleton trait + `init()` hook:** `plugins/lovata/toolbox/classes/helper/UserHelper.php:14-17,136-147`:
```php
class UserHelper
{
    use Singleton;

    /** @var string */
    protected $sPluginName;

    // …

    /**
     * Init data
     */
    protected function init()
    {
        $obPluginManager = PluginManager::instance();
        if ($obPluginManager->exists('Lovata.Buddies')) {
            $this->obHelper = app(BuddiesUserHelper::class);
            $this->sPluginName = 'Lovata.Buddies';
        } elseif ($obPluginManager->exists('RainLab.User')) {
            $this->obHelper = app(RainLabUserHelper::class);
            $this->sPluginName = 'RainLab.User';
        }
    }
}
```

**Analog B — memoized Settings reads + `flush()` for tests:** `plugins/logingrupa/goodsreceivedshopaholic/classes/support/SettingsAccessor.php:30-101`:
```php
final class SettingsAccessor
{
    private const KEY_ENABLED = 'enabled';
    // … other keys

    private static ?array $arCache = null;

    public static function isEnabled(): bool
    {
        return self::get(self::KEY_ENABLED);
    }

    public static function flush(): void
    {
        self::$arCache = null;
    }

    private static function get(string $sKey): bool
    {
        self::$arCache ??= [
            self::KEY_ENABLED => (bool) Settings::get(self::KEY_ENABLED),
            // … other keys
        ];

        return self::$arCache[$sKey];
    }
}
```

**Hybrid target shape for `PluginGuard`** (CONTEXT Area 1 Q2-Q3: Toolbox Singleton trait + memoized `isDisabled()` + container singleton bridge):
```php
<?php namespace Logingrupa\Metapixelshopaholic\Classes\Helper;

use App;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixelshopaholic\Models\Settings;
use October\Rain\Support\Traits\Singleton;

class PluginGuard
{
    use Singleton;

    /** @var bool|null */
    protected $bIsDisabled = null;

    /** @var string|null */
    protected $sPixelId = null;

    public function isDisabled(): bool
    {
        $this->prime();
        return (bool) $this->bIsDisabled;
    }

    public function getPixelId(): ?string
    {
        $this->prime();
        return $this->sPixelId;
    }

    public static function flush(): void
    {
        // Reset Toolbox Singleton-trait instance + container singleton bridge.
        if (App::bound('metapixel.disabled')) {
            App::forgetInstance('metapixel.disabled');
        }
        self::forgetInstance();   // from October\Rain\Support\Traits\Singleton
    }

    protected function init(): void
    {
        $this->prime();

        // Container-singleton bridge (CONTEXT Area 1 Q3): per-request lifecycle,
        // queryable from handlers via `App::make('metapixel.disabled')`.
        App::singleton('metapixel.disabled', fn() => $this->isDisabled());
    }

    protected function prime(): void
    {
        if ($this->bIsDisabled !== null) {
            return;
        }

        $sPixelId = (string) Settings::get('pixel_id', '');
        if ($sPixelId === '') {
            Log::warning('Metapixel: pixel_id not configured — plugin disabled');
            $this->bIsDisabled = true;
            return;
        }

        $this->sPixelId = $sPixelId;
        $this->bIsDisabled = false;
    }
}
```
- The `Singleton` trait provides static `::instance()` and `::forgetInstance()` (used by `UserHelper`, `PriceHelper`).
- `init()` is auto-invoked by the trait on first `::instance()` call (see `plugins/lovata/toolbox/classes/helper/PriceHelper.php:65-90`).
- `flush()` is the testing contract — wired into `MetapixelTestCase::flushPluginSingletons()` per Phase 2 modification to mirror `GoodsReceivedTestCase:99-104`.

---

### `components/PixelHead.php` (component)

**Analog:** `plugins/logingrupa/storeextender/components/LazyPromoBlockLoader.php:16-67`:
```php
class LazyPromoBlockLoader extends \Cms\Classes\ComponentBase
{
    /**
     * @return array
     */
    public function componentDetails()
    {
        return [
            'name'        => 'Lazy Promo Block Loader',
            'description' => 'Lazy-loads promo block product tabs via AJAX with skeleton placeholders',
        ];
    }

    /**
     * @return array
     */
    public function defineProperties()
    {
        return [
            'sorting' => [
                'title'       => 'Promo block sorting',
                // … (omitted)
            ],
        ];
    }

    /**
     * Prepare component data for rendering.
     */
    public function onRun()
    {
        $this->addJs('/plugins/logingrupa/storeextender/assets/js/lazy-tab-control.js');

        $obPromoBlockCollection = PromoBlockCollection::make()->active()->sort(
            $this->property('sorting', 'default')
        );

        $this->page['obPromoBlockList'] = $obPromoBlockCollection;
    }
}
```

**Lang-keyed `componentDetails()` variant** (`plugins/logingrupa/campaignpricingshopaholic/components/CampaignPricing.php:23-30`):
```php
#[\Override]
public function componentDetails(): array
{
    return [
        'name'        => 'logingrupa.campaignpricingshopaholic::lang.component.name',
        'description' => 'logingrupa.campaignpricingshopaholic::lang.component.description',
    ];
}
```

**Target shape for `PixelHead::onRun()`** (per SKEL-04 + CONTEXT Area 2 Q3 — UUIDv4 + `event_time` + Twig var):
```php
public function onRun(): void
{
    $obGuard = \Logingrupa\Metapixelshopaholic\Classes\Helper\PluginGuard::instance();
    if ($obGuard->isDisabled()) {
        return;
    }

    $this->page['arMetaEvent'] = [
        'event_id'    => \Ramsey\Uuid\Uuid::uuid4()->toString(),
        'event_time'  => time(),
        'event_name'  => 'PageView',
        'custom_data' => [],
    ];

    $this->page['sMetaPixelId'] = $obGuard->getPixelId();
}
```
`ramsey/uuid` is already in `composer.json` (line 21).

**Component alias decision pending** (CONTEXT Claude's Discretion bullet 4): `pixelHead` (camelCase like `CampaignPricing`) vs `metaPixelHead`. Phase 4 FUN-01 references `<PixelHead />` so recommend `pixelHead` for symmetry.

---

### `components/pixelhead/default.htm` (Twig component partial)

**Analog A — partial header + var declarations:** `plugins/logingrupa/campaignpricingshopaholic/components/campaignpricing/default.htm:1-29`:
```twig
{##}
{# Campaign Pricing Tiers — default component partial #}
{# … #}
{# @var obProduct \Lovata\Shopaholic\Classes\Item\ProductItem       #}
{# @var obOffer   \Lovata\Shopaholic\Classes\Item\OfferItem (set by AJAX handler or page) #}

{% set obOffer = obOffer is not empty ? obOffer : obProduct.offer.first() %}

{% if obOffer is not empty %}
    {% set obCampaignPricingList = obOffer.campaign_pricing_list %}

    {% if obCampaignPricingList is not empty and obCampaignPricingList.count() > 0 %}
    <div class="campaign-pricing-tiers">
        …
    </div>
    {% endif %}
{% endif %}
```

**Analog B — target script shape (NOT copy: this is the buggy partial we coexist with, not replace):** `themes/logingrupa-naisstore/partials/facebook_pixel.htm:1-5`:
```twig
{% if this.theme.facebook_pixel_id is not empty %}
    <!-- Facebook Pixel Code -->
    <script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?…</script>
    …
{% endif %}
```

**Target shape for `components/pixelhead/default.htm`** (SKEL-04 + CONTEXT Area 2 Q3-Q4 — `fbq('init', id)` with NO PII, `fbq('track','PageView', Object.assign(...), {eventID})`):
```twig
{# @var sMetaPixelId string #}
{# @var arMetaEvent  array{event_id: string, event_time: int, event_name: string, custom_data: array} #}

{% if sMetaPixelId is not empty and arMetaEvent is not empty %}
<!-- Metapixel — server-side eventID injection -->
<script>
    !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
    fbq('init', '{{ sMetaPixelId }}');
    fbq('track', '{{ arMetaEvent.event_name }}', Object.assign({event_time: {{ arMetaEvent.event_time }}}, {{ arMetaEvent.custom_data|json_encode|raw }}), {eventID: '{{ arMetaEvent.event_id }}'});
</script>
<noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id={{ sMetaPixelId }}&ev={{ arMetaEvent.event_name }}&noscript=1"/></noscript>
{% endif %}
```

---

### `lang/en/lang.php` (i18n keys)

**Analog:** `plugins/logingrupa/goodsreceivedshopaholic/lang/en/lang.php:1-23` (showing structure):
```php
<?php

return [
    'plugin' => [
        'name'        => 'Goods Received Notes',
        'description' => 'Distributor goods-received note import — parses HTM delivery receipts and increments offer stock.',
    ],
    'settings' => [
        'label'       => 'Goods Received',
        'description' => 'Configure stock-import behavior, active-flag automation, and per-site toggles.',
    ],
    'field' => [
        'enabled'                       => 'Enable goods-received import',
        'enabled_comment'               => 'Master toggle for HTM invoice parsing and stock writes on this site.',
        …
    ],
    // 'menu', 'permission', 'flash', 'tab', 'controller', 'column', 'exception', 'validation' …
];
```

**Required keys for Phase 2** (SKEL-06 + CONTEXT Area 4 Q4 — stubbed English; lv/ru repeat English):
- `plugin.name`, `plugin.description`
- `settings.label`, `settings.description`
- `component.name`, `component.description`
- `field.<key>` + `field.<key>_comment` for each of the 10 SKEL-02 fields (`pixel_id`, `capi_access_token`, `test_event_code`, `currency_code`, `phone_country_code`, `send_hashed_pii`, `queue_connection`, `paid_status_code`, `refire_purchase_on_status_flip`, `ensure_fbp_fbc_server_side`)
- `tab.tracking`, `tab.compliance`, `tab.advanced` (only if multi-tab decision made)

---

### `lang/lv/lang.php`, `lang/ru/lang.php`

**Analog:** `plugins/logingrupa/campaignpricingshopaholic/lang/lv/lang.php:1-44` and `…/lang/ru/lang.php` — same key tree as `en` with translated values. Phase 2 stubs them with the **English values** (CONTEXT Area 4 Q4); full translation deferred to Phase 5 HARD-04. RainLab.Translate's `|_` filter falls through to fallback locale gracefully, but keys MUST exist or the filter renders the raw `logingrupa.metapixelshopaholic::lang.foo` string.

---

### `tests/Feature/BootsWithoutPixelIdTest.php` (Pest feature test)

**Analog A — Phase 1 PHPUnit-style sanity:** `plugins/logingrupa/metapixelshopaholic/tests/Unit/SanityTest.php:24-31`:
```php
final class SanityTest extends MetapixelTestCase
{
    public function test_boots_the_october_harness(): void
    {
        $this->assertNotNull($this->app, 'October harness must populate $this->app via createApplication().');
        $this->assertTrue(Schema::hasTable('system_settings'), 'System migrations must have populated the system_settings table.');
    }
}
```

**Analog B — Pest DSL with Settings + flush:** `plugins/logingrupa/goodsreceivedshopaholic/tests/unit/Support/SettingsAccessorTest.php:55-90`:
```php
uses(SettingsAccessorTestCase::class);

beforeEach(function (): void {
    SettingsAccessor::flush();
    Settings::clearInternalCache();
    Settings::set('enabled', false);
    // …
    SettingsAccessor::flush();
});

it('returns true from isEnabled when Settings.enabled=true', function (): void {
    Settings::set('enabled', true);
    SettingsAccessor::flush();

    expect(SettingsAccessor::isEnabled())->toBe(true);
});
```

**Settings `system_settings` table bootstrap (test setUp):** `plugins/logingrupa/goodsreceivedshopaholic/tests/unit/Support/SettingsAccessorTest.php:30-53`:
```php
abstract class SettingsAccessorTestCase extends GoodsReceivedTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        \Schema::create('system_settings', function ($obTable): void {
            $obTable->increments('id');
            $obTable->string('item')->nullable()->index();
            $obTable->mediumtext('value')->nullable();
            $obTable->unsignedInteger('site_id')->nullable();
            $obTable->unsignedInteger('site_root_id')->nullable();
            $obTable->unsignedInteger('site_group_id')->nullable();
        });
    }

    protected function tearDown(): void
    {
        \Schema::dropIfExists('system_settings');
        parent::tearDown();
    }
}
```
This bypass exists because SQLite cannot drop indexed columns; the full Lovata.Shopaholic migration chain fails. Phase 2 tests need the same minimal hermetic table for any Settings read.

**Target assertions for `BootsWithoutPixelIdTest`** (CONTEXT Area 4 Q3):
1. `Log::shouldReceive('warning')->once()->with('Metapixel: pixel_id not configured — plugin disabled')` — assert via Log facade spy.
2. `PluginGuard::instance()->isDisabled()` returns `true` with empty Settings.
3. The plugin's `boot()` does not throw — assertion: `expect(fn() => app(Plugin::class)->boot())->not->toThrow(\Throwable::class)`.

---

### `tests/Feature/EnsureFbpFbcCookiesTest.php` (middleware test)

**Analog A — Pest assertion shape:** same as `SettingsAccessorTest` above for `uses(...) + beforeEach + it(…)`.

**Target assertions** (CONTEXT Area 4 Q3):
1. Request without `_fbp` cookie → response sets `_fbp` cookie matching `/^fb\.\d\.\d{13}\.[0-9a-f]{16}$/` (Meta-spec format, time-ms + bin2hex(8)).
2. Request with existing `_fbp` cookie → response does NOT overwrite.
3. Subdomain-index derivation: host `nailscosmetics.lv` → 1, `www.nailscosmetics.lv` → 2, `foo.bar.baz.lv` → 2 (capped).
4. Request with `?fbclid=ABC` → response sets `_fbc` cookie with format `fb.{index}.{ts}.ABC`.
5. Request without `fbclid` → response does NOT set `_fbc`.

**Driving the middleware in test:** instantiate directly and pass a synthetic `Request` + `$fnNext` returning a fresh `Response`. Avoid integration HTTP (slow, requires routing).
```php
$obRequest = \Illuminate\Http\Request::create('https://nailscosmetics.lv/foo?fbclid=ABC', 'GET');
$obMiddleware = new EnsureFbpFbcCookies();
$obResponse = $obMiddleware->handle($obRequest, fn() => new \Illuminate\Http\Response());

$arCookies = collect($obResponse->headers->getCookies())->keyBy(fn($c) => $c->getName());
expect($arCookies->get('_fbp')->getValue())->toMatch('/^fb\.1\.\d{13}\.[0-9a-f]{16}$/');
expect($arCookies->get('_fbc')->getValue())->toEndWith('.ABC');
```

---

### `tests/Feature/SettingsRegistrationTest.php` (Settings save/load)

**Analog:** `plugins/logingrupa/goodsreceivedshopaholic/tests/unit/Support/SettingsAccessorTest.php` (full file, lines 1-140).

**Target assertions** (CONTEXT Area 4 Q3):
1. `Settings::set('pixel_id', '2291486191076331'); Settings::clearInternalCache(); expect(Settings::get('pixel_id'))->toBe('2291486191076331')` — round-trip.
2. `(new Settings())->getPaidStatusCodeOptions()` returns a non-empty array — requires the `lovata_ordersshopaholic_statuses` table migrated. Use `migrateModules()` (already invoked by `MetapixelTestCase::setUp` when `autoMigrate=true`).
3. Plugin's `registerSettings()` returns the expected key `logingrupa-metapixelshopaholic-settings` mapped to `Settings::class`.

---

## Shared Patterns (cross-cutting)

### S1 — Settings model class form-field auto-method-binding
**Source:** `plugins/lovata/shopaholic/models/Settings.php:25-31`:
```php
public function getDimensionsMeasureOptions()
{
    $arResult = (array) Measure::orderBy('name', 'asc')->pluck('name', 'id')->all();
    return $arResult;
}
```
**Apply to:** `models/Settings.php` `getPaidStatusCodeOptions()` (`Status::lists('name','code')` per CONTEXT specifics:110), `getQueueConnectionOptions()` (static array). Method name must match `options:` key in `fields.yaml` modulo `get`/`Options` wrapper.

### S2 — Test harness singleton flush hook
**Source:** `plugins/logingrupa/goodsreceivedshopaholic/tests/GoodsReceivedTestCase.php:58-64,99-104`:
```php
protected function tearDown(): void
{
    $this->flushModelEventListeners();
    $this->flushPluginSingletons();
    parent::tearDown();
    unset($this->app);
}

protected function flushPluginSingletons(): void
{
    \Logingrupa\GoodsReceivedShopaholic\Classes\Support\SettingsAccessor::flush();
}
```
**Apply to:** `tests/MetapixelTestCase.php` — add `flushPluginSingletons()` calling `PluginGuard::flush()` so the disabled-flag memo doesn't bleed across tests.

### S3 — phpmd scope widening (MR-02)
**Source:** `plugins/logingrupa/metapixelshopaholic/composer.json:44` currently:
```
"phpmd": "../../../vendor/bin/phpmd Plugin.php text phpmd.xml"
```
**Apply (CONTEXT Area 4 Q2):**
```
"phpmd": "../../../vendor/bin/phpmd Plugin.php,classes,middleware,models,components,controllers,updates text phpmd.xml"
```

### S4 — `if (App::make('metapixel.disabled')) { return; }` short-circuit
**Contract documented in PluginGuard PHPDoc** (CONTEXT specifics:111). Every Phase 3+ event handler MUST start with this guard. Phase 2 only ships the guard wiring, no handlers consume it yet — but the PHPDoc in `PluginGuard.php` should declare it so Phase 3 planner has the contract pinned.

---

## No Analog Found

| File | Role | Data Flow | Reason / Mitigation |
|---|---|---|---|
| `middleware/EnsureFbpFbcCookies.php` | middleware | request-response | No middleware exists in any Lovata/Logingrupa plugin (verified by `find … -path '*middleware*' -name '*.php'`). Audit `.planning/audits/04-architecture.md:80-97` confirms. Mitigation: copy **shape only** from Laravel `AddQueuedCookiesToResponse` + apply Hungarian notation + Meta-spec from CONTEXT Area 3. |
| `plugin.yaml` | metadata | static | Optional under October 4 auto-discovery (CONTEXT Claude's Discretion bullet 6). If planner includes, mirror `campaignpricingshopaholic/plugin.yaml` exactly. |

---

## Metadata

**Analog search scope:**
- `plugins/logingrupa/campaignpricingshopaholic/` — closest sibling (Phase 1 boot pattern, Pest TestCase precedent)
- `plugins/logingrupa/goodsreceivedshopaholic/` — closest sibling (Settings model + Settings dropdown + Singleton flush + SettingsAccessor memo)
- `plugins/logingrupa/storeextender/` — component `onRun()` + `defineProperties()` pattern
- `plugins/lovata/toolbox/` — `UserHelper` (Singleton + init() + PluginManager), `CommonSettings` (Multisite trait + Translate behavior), `PriceHelper` (Singleton+Settings)
- `plugins/lovata/shopaholic/` — `registerSettings()` array, `Settings.php` `getXxxOptions()` dropdown methods, `fields.yaml` tabbed structure
- `plugins/lovata/ordersshopaholic/models/Status.php` — `CodeField` scope + `Status::lists('name','code')`
- `themes/logingrupa-naisstore/partials/facebook_pixel.htm` — current buggy partial (coexistence target, not replacement)
- `vendor/laravel/framework/src/Illuminate/Cookie/Middleware/AddQueuedCookiesToResponse.php` — middleware shape
- `vendor/laravel/framework/src/Illuminate/Foundation/Http/Kernel.php:347-369` — `prependMiddleware()` / `pushMiddleware()` API
- `modules/system/classes/PluginBase.php:40-291` — confirmed NO `registerMiddleware()` method exists (corrects CONTEXT and audit terminology)

**Files scanned:** ~35 PHP / 6 YAML / 3 lang / 1 Twig partial
**Pattern extraction date:** 2026-05-12
