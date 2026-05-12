# Phase 2: Skeleton + cookie fix - Context

**Gathered:** 2026-05-12
**Status:** Ready for planning

<domain>
## Phase Boundary

Plugin boots on OctoberCMS, Settings are editable in backend, and the live empty `_fbp`/`_fbc` cookie bug is fixed — standalone value even before any event fires. Scope = `Plugin.php` boot wiring (minimal — Phase 2 surface only), `models/Settings.php` extending `Lovata\Toolbox\Models\CommonSettings` with the 10 fields locked in SKEL-02, `models/settings/fields.yaml`, `middleware/EnsureFbpFbcCookies.php` (Meta-spec `fb.{idx}.{ts}.{rand}` format, registered via `registerMiddleware`), `classes/helper/PluginGuard.php` (boot-time `pixel_id` missing → log warn + container-singleton disabled flag, NO throw), `components/PixelHead.php` (renders alongside the theme's existing `partials/facebook_pixel.htm`, never replaces), `plugin.yaml`, `lang/{en,lv,ru}/lang.php` scaffolding (keys present, values stubbed). Feature tests: `BootsWithoutPixelIdTest`, `EnsureFbpFbcCookiesTest`, `SettingsRegistrationTest`.

Out of scope: `MetaClient`, `SendCapiEvent` queue job, `OrderStatusWatcher`, `meta_purchase_event_id` migration, `FailedEvent` model + table, `PayloadBuilder`, `UserDataHasher`, exception hierarchy → Phase 3. All funnel components (`ProductPagePixel`, `CategoryPagePixel`, `Cart::onMetaTrackAddToCart`, etc.) → Phase 4. Full lang translations, README runbook, `FailedEvents` backend controller → Phase 5.

</domain>

<decisions>
## Implementation Decisions

### Locked by REQUIREMENTS.md (v1.0.0 SKEL-01..06)

- SKEL-01: `Plugin.php` boot wiring + `plugin.yaml` declaring metadata + requirements
- SKEL-02: `models/Settings.php` extends `Lovata\Toolbox\Models\CommonSettings`, `settingsCode = 'logingrupa_metapixelshopaholic_settings'`. Fields: `pixel_id` (text, translatable), `capi_access_token` (password), `test_event_code` (text), `currency_code` (text, default `EUR`), `phone_country_code` (text, default `371`), `send_hashed_pii` (switch, on), `queue_connection` (dropdown redis/database/sync, default `database`), `paid_status_code` (dropdown from `Status::lists('name','code')`, default `new-payment-received`), `refire_purchase_on_status_flip` (switch, off), `ensure_fbp_fbc_server_side` (switch, on). `getPaidStatusCodeOptions()` lists all Shopaholic statuses
- SKEL-03: `middleware/EnsureFbpFbcCookies.php` at plugin root, Meta-spec format `fb.{subdomain-index}.{creation-timestamp}.{random}`, registered via `Plugin::boot()` → `$this->registerMiddleware([...])`
- SKEL-04: Extends theme's existing `facebook_pixel.htm` via component `PixelHead` on layout — never replaces. Twig consumes `arMetaEvent {event_id, event_time, event_name, custom_data}` → `fbq('track', name, Object.assign({event_time}, data), {eventID})`
- SKEL-05: Boot-time missing `pixel_id` = `Log::warning('Metapixel: pixel_id not configured — plugin disabled')` + plugin-wide disabled flag. Event handlers short-circuit while disabled. Does NOT throw at boot (would cascade-break Campaigns/PromoMechanism). Feature-tested booting with empty Settings
- SKEL-06: `lang/{en,lv,ru}/lang.php` RainLab.Translate-compatible scaffolding for Settings labels (content stubbed; full translations in Phase 5 HARD-04)

### Area 1 — Plugin.php boot strategy

- Q1: Register only Phase 2 surface in `boot()` — middleware + Settings backend registration. Handler `Event::subscribe(...)` calls deferred to Phase 3+ when the concrete handler classes ship (registering missing classes = unbootable plugin)
- Q2: `classes/helper/PluginGuard.php` is the single source of truth for `pixel_id` presence — exposes `isDisabled(): bool` reading Settings. Called from `Plugin::boot()` AND from every future event handler. Toolbox `UserHelper` pattern (Singleton)
- Q3: Disabled flag carrier = container singleton `App::singleton('metapixel.disabled', fn() => PluginGuard::instance()->isDisabled())`. Per-request lifecycle; resettable in feature tests via `App::forgetInstance('metapixel.disabled')`
- Q4: `Plugin.php` `public $require = ['Lovata.Toolbox', 'Lovata.Shopaholic', 'Lovata.OrdersShopaholic']`. **Drop Lovata.Buddies hard-require.** Composer.json moves `lovata/buddies-plugin` from `require` → `suggest`. Runtime user-plugin detection via `Lovata\Toolbox\Classes\Helper\UserHelper` which already abstracts `Lovata.Buddies` vs `RainLab.User` via `PluginManager::instance()->exists(...)` (see `plugins/lovata/toolbox/classes/helper/UserHelper.php`). Phase 4 `CompleteRegistration` handler dynamically binds `eloquent.created` on whichever User model is active

### Area 2 — Theme integration

- Q1: New component `PixelHead` renders **alongside** the existing `themes/logingrupa-naisstore/partials/facebook_pixel.htm`. Theme owners decide when to swap; current partial keeps working during cutover
- Q2: `PixelHead` reads `Settings::get('pixel_id')` — plugin Settings owns it, NOT `theme.facebook_pixel_id`. Phase 5 README documents the migration step (copy theme value → plugin setting at activation)
- Q3: `PixelHead::onRun()` builds `$arMetaEvent = ['event_id' => UUIDv4, 'event_time' => time(), 'event_name' => 'PageView', 'custom_data' => []]`. Twig emits `fbq('track','PageView', Object.assign({event_time:{{ arMetaEvent.event_time }}}, {{ arMetaEvent.custom_data|json_encode|raw }}), {eventID:'{{ arMetaEvent.event_id }}'})`. Phase 2 fires PageView client-side only — CAPI twin for PageView lands in Phase 4 FUN-01
- Q4: `fbq('init', pixel_id)` — **no PII**. UserDataHasher (Phase 3) ships hashed PII to CAPI server-side only. Mixing browser PII in `fbq('init', ...)` would double-source `external_id` and break dedup. Current theme partial's PII-in-init init pattern is the bug we're correcting

### Area 3 — `EnsureFbpFbcCookies` middleware

- Q1: **Global middleware** registered via `$this->registerMiddleware([EnsureFbpFbcCookies::class])` in `Plugin::boot()`. Runs on every storefront request so `_fbp` exists before the first `fbq()` call
- Q2: Subdomain-index derived from `Request::getHost()` — `count(explode('.', $host)) - 1`, capped at 2 per Meta spec. `nailscosmetics.lv`→1, `www.nailscosmetics.lv`→2, deeper subdomains→2 (Meta cap)
- Q3: Cookie attributes: 90-day expiry (`time() + 60*60*24*90`), path `/`, `secure` when `Request::secure()`, `httpOnly = false` (browser must read `_fbp` for `fbq`), `SameSite=Lax`
- Q4: `_fbc` generated **only when `fbclid` query param present** in the request. Format `fb.{subdomain-index}.{event-time}.{fbclid}`. Never invent a synthetic `_fbc`. Existing `_fbc` cookie left untouched

### Area 4 — Tests + namespace + phpmd

- Q1: **Keep current namespace** `Logingrupa\Metapixelshopaholic`. composer.json, Plugin.php, `psr-4` autoload, and sibling `Logingrupa\Campaignpricingshopaholic` all use lowercase-after-prefix. Rename = mass path churn for cosmetic gain. LR-01 closed
- Q2: **Widen phpmd script** in composer.json: `"phpmd": "../../../vendor/bin/phpmd Plugin.php,classes,middleware,models,components,controllers,updates text phpmd.xml"`. Phase 2 adds `models/`, `middleware/`, `classes/helper/`, `components/` — all must lint. MR-02 closed
- Q3: 3 feature tests in `tests/Feature/`: `BootsWithoutPixelIdTest` (boots clean with empty Settings, logs warning, disabled flag = true, no throw), `EnsureFbpFbcCookiesTest` (cookie set when missing, untouched when present, subdomain-index derived correctly, fbclid present → `_fbc` set), `SettingsRegistrationTest` (Settings model loads, `pixel_id` saves+reads, `paid_status_code` dropdown populated from `Status::lists`). Target ≥ 80% line coverage on `Plugin.php` + `models/Settings.php` + `middleware/EnsureFbpFbcCookies.php` + `classes/helper/PluginGuard.php`. ≥ 90% milestone gate deferred to Phase 5 HARD-06
- Q4: `lang/{en,lv,ru}/lang.php` scaffolding with keys for every fields.yaml label. Values stubbed in English (`lv`/`ru` repeat English string). Full translation deferred to Phase 5 HARD-04. RainLab.Translate needs keys present at runtime for `|_` filter to resolve

### Claimed Open Todos from Phase 1

- LR-01 (namespace) → Closed (keep `Logingrupa\Metapixelshopaholic`)
- MR-02 (phpmd scope) → Closed (widen script per Area 4 Q2)
- BR-01 (CI auth for private composer deps) → Deferred to Phase 5 launch. Not blocking Phase 2 local QA

### Claude's Discretion

- Exact `Plugin.php` `boot()` body ordering (middleware register → Settings register → PluginGuard prime)
- `PluginGuard` exact API beyond `isDisabled(): bool` (e.g. add `getPixelId()`, `isCookieMiddlewareEnabled()` accessors)
- `EnsureFbpFbcCookies` random-segment generator — `bin2hex(random_bytes(8))` vs `Str::random(10)`
- `PixelHead` component alias (`pixelHead` vs `metaPixelHead`)
- `models/settings/fields.yaml` tab grouping (single tab vs split into "Tracking", "Compliance", "Advanced")
- `plugin.yaml` exact key set (October auto-discovers from `pluginDetails()` — yaml may be a duplicate)
- Pest test directory layout (`tests/Feature/` vs `tests/Unit/` vs flat)
- Exact `lang.php` key naming (`metapixel.settings.pixel_id` vs `logingrupa.metapixelshopaholic::lang.settings.pixel_id`)

</decisions>

<code_context>
## Existing Code Insights

### Reusable Assets

- **`Lovata\Toolbox\Models\CommonSettings`** — parent for `Settings.php`. Auto-caches, supports Multisite trait, pairs with `Settings::get('key', $default)`
- **`Lovata\Toolbox\Classes\Helper\UserHelper`** (`plugins/lovata/toolbox/classes/helper/UserHelper.php`) — Singleton abstracting `Lovata.Buddies` vs `RainLab.User`. Phase 4 `CompleteRegistration` listener will use this to bind on whichever user model is active. Removes need to hard-require Lovata.Buddies
- **`System\Classes\PluginManager::instance()->exists('Lovata.Buddies')`** — runtime user-plugin detection pattern used by `UserHelper`. Reusable in `PluginGuard` to gate optional features
- **`Logingrupa\Campaignpricingshopaholic\Plugin`** (`plugins/logingrupa/campaignpricingshopaholic/Plugin.php`) — closest sibling pattern: `$require`, `pluginDetails()` returning lang strings, `boot()` with `Event::subscribe(...)` + `(new Handler())->subscribe()` calls
- **`plugins/logingrupa/campaignpricingshopaholic/tests/CampaignPricingTestCase.php`** — already mirrored in `tests/MetapixelTestCase.php` (Phase 1). Pest 4 boot pattern

### Established Patterns

- **Hungarian notation** — `$obSettings`, `$arFieldList`, `$sCookieName`, `$bIsDisabled`. `phpmd.xml` `LongVariable max=40` accommodates
- **`October\Rain\Support\Traits\Singleton`** for helper classes (UserHelper precedent). Use for `PluginGuard`
- **`addEventListener()` / `Event::subscribe()`** in `Plugin::boot()` — handler registration pattern
- **`$this->registerMiddleware([...])`** in `Plugin::boot()` — middleware registration pattern (Laravel-native, inherited from `PluginBase`)
- **`Settings::get('key', $default)`** — preferred over `Config::get(...)` for runtime-mutable values
- **`fbq` initialization** lives in `themes/logingrupa-naisstore/partials/facebook_pixel.htm` (currently emits `fbq('init', id, {em, fn, ln, ph, external_id})` + `fbq('track', 'PageView')`). PixelHead component renders ALONGSIDE — not a replacement

### Integration Points

- **Theme layout** — theme owner includes `{% component 'pixelHead' %}` next to (or replacing) `{% partial 'facebook_pixel' %}`. Documented in Phase 5 README. Phase 2 ships the component; theme integration is documentation, not code change to theme
- **Backend Settings page** — `Settings` model auto-appears under "Logingrupa" group via `CommonSettings` registration. Verify in feature test
- **Composer autoload** — `Logingrupa\\Metapixelshopaholic\\` already mapped at plugin root (composer.json line 32). New `models/`, `middleware/`, `classes/`, `components/` directories follow this prefix
- **PluginManager** — October enumerates plugins via `pluginDetails()`. `plugin.yaml` is optional metadata; OctoberCMS 4 auto-discovers. Decide during plan whether to include yaml

</code_context>

<specifics>
## Specific Ideas

- **Cookie bug repro** — production observed 2026-04-22: `_fbp` and `_fbc` arrive empty in CAPI envelopes. Root cause: theme partial relies on `fbevents.js` to set `_fbp`, but `fbevents.js` doesn't run early enough on first paint / SSR-cached responses / non-JS bots. Server-side middleware setting `_fbp` before the browser sees the response fixes this for 100% of requests
- **Meta `_fbp` spec** — format `fb.{subdomain-index}.{creation-time-ms}.{random}`. `subdomain-index`: 0 for `.com`-level, 1 for `domain.tld`, 2 for `sub.domain.tld`. Cap at 2. `creation-time-ms` = `time() * 1000` (Meta accepts seconds * 1000 even though field name says ms). `random` = 10-digit random
- **Multi-site cookie domain** — same plugin code on `.no`/`.lv`/`.lt` → cookie set with implicit current-host scope. Do NOT set `domain=.nailscosmetics.lv` explicitly; let browser bind to host. This avoids accidental leaks across TLDs
- **PixelHead component coexistence** — do NOT race with the theme partial's `fbq('track', 'PageView')`. Theme partial fires PageView without `eventID`; PixelHead fires PageView WITH `eventID`. Both reach Meta — Meta dedupes by `eventID + event_name + ±10s window`. The theme partial's call lacks `eventID` → won't dedupe → Meta counts it as a second event. Phase 5 migration step: theme owner removes the `fbq('track', 'PageView')` line from the partial once they include `{% component 'pixelHead' %}`. Document this in README runbook
- **Settings `paid_status_code` dropdown** — must call `\Lovata\OrdersShopaholic\Models\Status::lists('name', 'code')` (NOT `pluck`). `OrdersShopaholic` is hard-required so the class is guaranteed present
- **Disabled flag short-circuit pattern** — every event handler shipped Phase 3+ must `if (App::make('metapixel.disabled')) { return; }` at top. Doc this in PluginGuard PHPDoc as the contract

</specifics>

<deferred>
## Deferred Ideas

- **CAPI PageView twin (FUN-01)** → Phase 4. Phase 2 PixelHead fires client-side only
- **`MetaClient` Guzzle wrapper, queue job, exception hierarchy** → Phase 3 (PAY-01..09)
- **`meta_purchase_event_id` migration, `OrderStatusWatcher`** → Phase 3 (PAY-03, PAY-04)
- **`FailedEvent` model + table + backend controller** → Phase 3/5
- **`UserDataHasher`** → Phase 3 (PAY-07)
- **Funnel event components** (`ProductPagePixel`, `CategoryPagePixel`, `CheckoutPixel`, Cart/Wishlist/MakeOrder extensions, Lead salon form, CompleteRegistration on User) → Phase 4
- **Full translations** (en/lv/ru content beyond stubs) → Phase 5 HARD-04
- **README runbook + Composer marketplace listing** → Phase 5 HARD-05
- **CI auth for private composer deps (BR-01)** → Phase 5 launch task
- **PixelHead coverage of `event_time` on non-PageView events** → Phase 4 (FUN-12 — every fbq passes event_time)

</deferred>
