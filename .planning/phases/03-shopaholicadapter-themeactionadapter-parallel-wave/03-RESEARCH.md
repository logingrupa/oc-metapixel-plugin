# Phase 3: ShopaholicAdapter + ThemeActionAdapter parallel wave — Research

**Researched:** 2026-05-18
**Domain:** OctoberCMS 4 / Laravel 12 plugin extending Phase 2 adapter backbone — Lovata.OrdersShopaholic Order + CartPosition tracking (Purchase + AddToCart), Twig + Larajax operator API, EventLog payload column + 7-day TTL, P-09 Larajax security gate
**Confidence:** HIGH

## Summary

Phase 3 ships two adapters behind the Phase 2 contracts as FRESH implementations — not a v1.x port. The CONTEXT.md already carries 29 locked decisions (D-01..D-29) across event scope, EventLog payload column, ThemeAjaxHandler allowlist, ThemeAdapter site_id source, and Plan ordering. The planner's job is structural — refine task counts per the 8-plan outline (D-18) — NOT re-derive design.

The research surface for Phase 3 is narrow because the heavy decisions are locked: every requirement (SHOP-01..05 + THEM-01..07) maps to a specific plan; every pitfall (P-03, P-05, P-09, P-11) has its prevention surface defined; Phase 2 already shipped the contracts the adapters implement. What remains to research is October-framework integration shape — exact signatures of `registerMarkupTags()`, `registerSchedule(Schedule $obSchedule)`, `cms.ajax.beforeRunHandler` event semantics, `PluginManager::exists()` timing, `Illuminate\Cache\RateLimiter` API — plus the Lovata model details for ShopaholicCartPositionAdapter (CartPosition is a MorphTo `item_id`/`item_type` row, NOT a direct offer_id column as the CONTEXT.md outline suggested).

**Primary recommendation:** Planner refines D-18's 8-plan outline 1-for-1 with no structural changes. Plans split tasks along the locked decisions; tests extend `EventSubjectAdapterContractTestCase` (Phase 2 D-11) for all three new adapters; Watchers ship as plain `Event::subscribe` classes implementing Laravel's `Dispatcher`-arg pattern (NOT Lovata.Toolbox `ModelHandler`, which adds cache-invalidation abstracts Phase 3 doesn't need).

<user_constraints>

## User Constraints (from CONTEXT.md)

### Locked Decisions (D-01..D-29 — do NOT re-derive)

**ShopaholicAdapter event scope (Area 1):**
- **D-01:** First-party Shopaholic ships **Purchase + AddToCart** ONLY. Lead/Search/checkout-funnel events are ThemeAdapter responsibility.
- **D-02:** Two adapter classes in `classes/adapter/shopaholic/`, not one mega-adapter. SRP at file level:
  - `ShopaholicOrderAdapter` — subject `Lovata\OrdersShopaholic\Models\Order`, alias `'shopaholic.order'`, event Purchase, `getSupportedEvents → ['Purchase' => ['capi','pixel']]`.
  - `ShopaholicCartPositionAdapter` — subject `Lovata\OrdersShopaholic\Models\CartPosition`, alias `'shopaholic.cart_position'`, event AddToCart, `getSupportedEvents → ['AddToCart' => ['capi','pixel']]`.
- **D-03:** Watchers split symmetrically under `classes/event/adapter/shopaholic/` — OrderStatusWatcher + CartPositionWatcher. Bind `eloquent.updated` + `eloquent.created`. UNIQUE race-fence on `(subject_type, subject_id, event_name, channel, site_id)` is the dedup anchor — qty-bump updates do NOT re-fire because subject_id stays constant.
- **D-04:** Each adapter pairs with its own ValueResolver. content_ids = `SKU-{product_id}[-{offer_id}]` — matches Catalog feed.
- **D-05:** PageView + ViewContent NOT shipped via Shopaholic adapter dir — they live in ThemeAdapter at theme layout level.

**EventLog payload column + 7-day TTL (Area 2):**
- **D-06:** EventLog gains `payload longText NULL` column via NEW Phase 3 migration `AddPayloadToMetapixelEventLogTable.php`. Phase 2 migration NOT amended (additive only).
- **D-07:** `EventLogWriter::record` signature gains trailing `array $arPayload` param.
- **D-08:** New console command `Logingrupa\Metapixel\Console\PurgeEventLog` registered as `metapixel:purge-event-log`. Deletes rows where `created_at < now() - 7 days`. `Plugin::registerSchedule(Schedule $obSchedule): void { $obSchedule->command('metapixel:purge-event-log')->daily(); }`.
- **D-09:** `EventPixel::onRun()` reads EventLog directly via `DB::table('logingrupa_metapixel_event_log')->where([...])->first(['event_id','event_time','payload'])`; emits inline `fbq('track', name, $arPayload['custom_data'], {eventID: ...})`. No adapter re-resolve at render time.
- **D-10:** Snowplow atomic.events shape (dedup keys + raw payload in same table) + bounded retention is the industry parallel.

**ThemeAjaxHandler allowlist (Area 3):**
- **D-11:** `ThemeAjaxHandler` validates incoming `event_name` against TWO sources:
  1. `const META_STANDARD = ['PageView','ViewContent','AddToCart','AddToWishlist','InitiateCheckout','Purchase','Lead','CompleteRegistration','Search','Subscribe','Contact','FindLocation','Donate','CustomizeProduct','SubmitApplication','AddPaymentInfo','StartTrial','Schedule'];` (18 Meta-standard names per Graph API v23.0).
  2. `Settings::get('theme_custom_event_names', [])` — operator-supplied list sanitized at SAVE time.
- **D-12:** Settings field shape: Lovata.Toolbox CommonSettings textarea `theme_custom_event_names` (one event name per line). `lang.settings.theme_custom_event_names.label` + `.commentAbove` keys reserved (populated Phase 4 LANG-01).
- **D-13:** Dev-savvy operators wanting server-side custom events write own adapter + `AdapterRegistry::register` from their `Plugin::boot()`.
- **D-14:** P-09 defence: CSRF (`cms.ajax.beforeRunHandler`) + per-IP+session rate-limit (Laravel `RateLimiter` 30 req / 60 s) + JS-escape (`e()` helper) + SAVE-boundary sanitization + REQUEST-boundary allowlist (two-zone).

**ThemeAdapter site_id source + Plan ordering (Area 4):**
- **D-15:** **ThemeAdapter is the ONE documented P-01 exception.** `ThemeActionAdapter::getSiteId(object $obSubject): ?int` reads `arPayload['site_id']` first; falls back to `\October\Rain\Support\Facades\Site::getCurrent()?->getId()`. Documented in class-level PHPDoc + plugin CLAUDE.md.
- **D-16:** PHPStan disallowed-calls splits by sub-directory:
  - `classes/queue/` ✅ banned
  - `classes/event/` ✅ banned
  - `classes/adapter/shopaholic/` ✅ banned
  - `classes/adapter/theme/` ❌ EXCLUDED (sole documented exception)
- **D-17:** Plan execution **sequential, Shopaholic-first**. Plans 03-01 → 03-08 ship in linear order. Tests cumulative — each plan green before next.
- **D-18:** Plan outline (planner refines exact task counts) — see Plan Layout section below.

**Carried forward (already locked by Phase 2 / project; do NOT re-derive):**
- **D-19:** subject_type opaque alias `'shopaholic.order'` + `'shopaholic.cart_position'` (NOT FQN — P-05). Enforced by contract test invariant 01.
- **D-20:** content_ids = `SKU-{product_id}[-{offer_id}]` matches `plugins/logingrupa/facebookcatalogshopaholic/classes/helper/ExportCatalogFacebookHelper.php:356` (verified — see Code Examples).
- **D-21:** `PluginManager::instance()->exists('Lovata.OrdersShopaholic')` gate (P-11).
- **D-22:** `PayloadBuilder::buildEventPayload` event-agnostic; adapter supplies overrides via `$arEventExtras`.
- **D-23:** `before_dispatch` halt-able; `after_dispatch` + `dead_letter` observe-only. Snapshot+restore on event_id / event_time.
- **D-24:** All adapter tests extend `EventSubjectAdapterContractTestCase`. Three new tests — `ShopaholicOrderAdapterContractTest` + `ShopaholicCartPositionAdapterContractTest` + `ThemeActionAdapterContractTest`. Each supplies `makeAdapter()` + `makeSubject()`. `#[Group('adapter')]` for Run B exclude.
- **D-25:** Lowercase folder convention — `classes/adapter/shopaholic/`, `classes/adapter/theme/`, `classes/event/adapter/shopaholic/`, `tests/Feature/Adapter/Shopaholic/`, etc. Namespaces PascalCase.
- **D-26:** Hungarian notation everywhere. PHPMD `ShortVariable min=4`.
- **D-27:** Final classes, ≤70 LOC methods, one responsibility per class.
- **D-28:** `@phpstan-ignore` banned project-wide. Extract runtime-guard helper.
- **D-29:** Larajax transport — theme calls `jax.ajax('Metapixel::onFireEvent', {data: {...}})` against October component-handler route. Plugin ships ZERO routes; `cms.ajax.beforeRunHandler` is THE wire-up surface.

### Claude's Discretion

- **OrderStatusWatcher trigger code:** `paid_status_code` resolves from Settings via dropdown sourced from `Status::orderBy('sort_order')->pluck('name','code')` (Status lacks its own `lists()` — see Common Pitfalls §Pitfall-3). Default value `new-payment-received` matches nailscosmetics.* baseline. Operator may flip.
- **CartPositionWatcher trigger semantics:** dispatch on `eloquent.created` (first-time-add); on `eloquent.updated`, dispatch ONLY when EventLog row absent for the same tuple. Qty-bump is NOT a new AddToCart by Meta convention. UNIQUE race-fence + per-subject_id key is the dedup anchor.
- **ThemeEventCollector flush boundary:** explicit `flush()` called by PixelHead component after emit + tests' `tearDown()`. No magic terminating-event flush.
- **PixelHead-EventPixel coexistence:** PixelHead emits accumulator events on its own render (theme-side, in-request); EventPixel handles the server-confirmed-elsewhere path (e.g. CAPI fired from queue worker → customer hits thank-you page later). Separate components, no overlap.
- **Test directory layout:** `tests/Feature/Adapter/Shopaholic/` (DB-backed, Run A only) + `tests/Feature/Adapter/Theme/` (no Lovata) + `tests/Contract/Adapter/Shopaholic/` + `tests/Contract/Adapter/Theme/`.
- **`Logingrupa\Metapixel\Plugin::registerComponents()`** registers `EventPixel`, and `PixelHead`.

### Deferred Ideas (OUT OF SCOPE — ignore completely)

- FailedEvents admin UI + Replay + CheckDedup (Phase 4 — FAIL-01..03).
- Multisite trait on `pixel_id`/`capi_access_token` (Phase 4 — MULT-01..06). Phase 3 calls `Settings::lookupForSite($iSiteId)` (Phase 2 stub).
- TrustedHosts + `jeremykendall/php-domain-parser` (Phase 4 — HOST-01..06).
- Translations en/lv (Phase 4 — LANG-01). Phase 3 reserves keys only.
- Lead event server-side adapter (v2.1).
- MallAdapter + MeloncartAdapter (v2.1).
- Additional 5 `Event::fire` hooks (v2.1 — EXT-01..05).
- Debug / Test-Events backend panel (v2.1 — DBG-01).
- Auto PSL refresh cron (v2.x — PSL-01).
- ThemeAjaxHandler rate-limit configurability (Phase 4 if demand surfaces).
- EventPixel + PixelHead coexistence migration doc (Phase 5).

</user_constraints>

<phase_requirements>

## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| **SHOP-01** | `ShopaholicOrderAdapter implements EventSubjectAdapter`. `getSubjectType()` returns `'shopaholic.order'`. `getSiteId()` reads `$obOrder->getAttribute('site_id')`. | Phase 2 `EventSubjectAdapter` contract + `Order.site_id` column verified (`plugins/lovata/ordersshopaholic/models/Order.php:39`). Plan 03-02. |
| **SHOP-02** | `ShopaholicOrderValueResolver implements ValueResolver`. `resolveContentIds()` returns `SKU-{product_id}[-{offer_id}]`. Currency: Order.currency relation → Order.currency_code → adapter Settings default → throw `OrderHasNoCurrencyException`. | SKU anchor verified in `plugins/logingrupa/facebookcatalogshopaholic/classes/helper/ExportCatalogFacebookHelper.php:356` (`'offer_id' => 'SKU-' . $obOffer->product->id . '-' . $obOffer->id`; `'product_id' => 'SKU-' . $obOffer->product->id`). Order.currency_code + Order.currency relation both exist on the Lovata model. Plan 03-02. |
| **SHOP-03** | `OrderStatusWatcher` subscribes `eloquent.updated` + `eloquent.created` on Order. On paid_status_code match + EventLog row absent → `SendCapiEvent::dispatch('Purchase', $arPayload, $obOrder, ShopaholicOrderAdapter::class)`. ≤70 LOC. | v1.x watcher = 367 LOC anti-pattern reference. Fresh class extends nothing (plain `subscribe(Dispatcher)` shape). Plan 03-02. |
| **SHOP-04** | `Plugin::boot()` conditionally registers ShopaholicOrderAdapter + subscribes OrderStatusWatcher only when `PluginManager::instance()->exists('Lovata.OrdersShopaholic')` is true. composer-dependency-analyser enforces no `Lovata\OrdersShopaholic\*` imports outside `Classes\Adapter\Shopaholic\`. | `PluginManager::exists($id)` verified (`modules/system/classes/PluginManager.php:380`). Phase 2 composer-dependency-analyser config at `composer-dependency-analyser.php` already restricts to `classes/adapter/shopaholic/`; Phase 3 expands to include `classes/event/adapter/shopaholic/`. Plan 03-02. |
| **SHOP-05** | Pest integration test exercises end-to-end Purchase flow on generic Order fixture (`example.test`, hermetic SQLite): status flip → dispatch → EventLogWriter race-fence (channel=capi) → MetaClient mocked via Guzzle MockHandler → payload shape + event_id round-trip + dedup. Second admin-flip asserts EventLog row prevents re-fire. | Phase 2 `BackboneIntegrationTest` is the template (MockHandler + `Middleware::history` pattern). `ShopaholicAdapterTestCase` already provisions `lovata_orders_shopaholic_orders` + `_statuses` (5-row seed). Plan 03-04. |
| **THEM-01** | `ThemeActionEvent` value object: `sActionKey`, `iSyntheticId` (hash of action_key), `sEventName`, `arPayload`. | New class — `classes/adapter/theme/ThemeActionEvent.php`. iSyntheticId via `crc32($sActionKey)` mapped into positive int range. Plan 03-05. |
| **THEM-02** | `ThemeActionAdapter implements EventSubjectAdapter`. `getSiteId()` reads `arPayload['site_id']` first; falls back to `Site::getCurrent()?->getId()`. Documented PHPDoc exception. | `Site::getCurrent()` lives at `October\Rain\Support\Facades\Site` (Phase 2 verified). PHPStan `disallowIn` for `classes/adapter/theme/` is excluded — see D-16. Plan 03-05. |
| **THEM-03** | `ThemeEventCollector` request-scoped singleton. `Plugin::register()` binds via `$this->app->singleton(ThemeEventCollector::class)`. Accumulates events pushed via Twig API. `flush()` explicitly called by PixelHead component + test teardown. | Plan 03-06. |
| **THEM-04** | `Plugin::registerMarkupTags()` returns `['functions' => ['this.metapixel.pushEvent' => function (...) {...}]]`. (Actual shape: registers function `metapixel_push_event` that wraps the call; the Twig path `this.metapixel.pushEvent` requires a stub Twig extension OR a `this.metapixel` global resolved via the component PageController — see Code Examples §Pattern 2.) | `registerMarkupTags()` shape verified at `plugins/lovata/toolbox/Plugin.php:66` (Lovata.Toolbox reference). Plan 03-06. |
| **THEM-05** | `ThemeAjaxHandler` listens `cms.ajax.beforeRunHandler` for `Metapixel::onFireEvent`. Validates against META_STANDARD const + Settings list, enforces CSRF token, rate-limits, JS-escapes returned payload. Dispatches `SendCapiEvent` + emits `<script>fbq(...)</script>` fragment. | `cms.ajax.beforeRunHandler` event verified at `modules/cms/classes/controller/HasAjaxRequests.php:297` — listener signature `(Cms\Classes\Controller $obController, string $sHandler)`; returning a non-null value short-circuits the handler. October's built-in CSRF runs in `\Cms\Classes\AjaxFramework` middleware BEFORE the event fires — handler can read `Request::header('X-CSRF-TOKEN')` for redundant check. Plan 03-07. |
| **THEM-06** | `Components\EventPixel` properties `subject_class` + `subject_slug_field`. `onRun()` queries EventLog for matching CAPI row; if present + Pixel row absent, emits inline `fbq('track', name, data, {eventID})` with server event_id. `onMarkFired` AJAX writes `channel='pixel'` row. | D-09 locks DB::table read path. EventLog `forSubject` scope exists (`models/EventLog.php:50`). Plan 03-08. |
| **THEM-07** | `Components\PixelHead` reads `ThemeEventCollector` accumulator, emits `fbq('track', ...)` script blocks per pushed event. Optional `also_dispatch_capi: true` triggers CAPI mirror. | Plan 03-08. |

</phase_requirements>

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Order status-change detection (paid flip) | API / Backend | — | Eloquent `eloquent.updated|created` event in `Plugin::boot()` of metapixel plugin; server-only. |
| CartPosition add/update detection | API / Backend | — | Same Eloquent-event subscription pattern; reads CartPosition model. |
| Purchase + AddToCart payload build | API / Backend | — | Adapter + ValueResolver run inside SendCapiEvent::handle on queue worker; never browser-side. |
| EventLog UNIQUE race-fence dedup | Database / Storage | API / Backend | DB-layer UNIQUE constraint is the source of truth; `EventLogWriter::record` returns false on collision. |
| Frozen-payload audit (frame-of-dispatch snapshot) | Database / Storage | API / Backend | EventLog.payload column persisted at write; EventPixel renders from this row, not re-resolved. |
| 7-day TTL purge | API / Backend (console) | — | Laravel `Schedule::command()->daily()` runs in CLI cron context; cuts EventLog rows older than 7 days. |
| Theme `pushEvent` Twig API | Frontend Server (SSR) | — | `registerMarkupTags` is October's PageController-rendering surface; runs during Twig render before HTML flush. |
| ThemeEventCollector accumulator | Frontend Server (SSR) | API / Backend | Request-scoped singleton in the same request that handles the Twig push + PixelHead render. |
| Larajax `onFireEvent` handler | API / Backend | Frontend Server | Theme JS calls `jax.ajax('Metapixel::onFireEvent', ...)` → October Controller AJAX layer → `cms.ajax.beforeRunHandler` listener resolves before any plugin component handler runs. |
| EventPixel inline `fbq('track', ...)` emission | Browser / Client | Frontend Server (SSR) | Component renders inline `<script>` during Twig render; the actual `fbq` call executes in browser after page load. |
| PixelHead `<head>` accumulator emit | Frontend Server (SSR) | Browser / Client | Component placed in layout `<head>` partial; emits `<script>` blocks for each collected event. |
| CSRF + rate-limit + JS-escape gate | API / Backend | — | Server-side P-09 prevention surface; never relies on browser-side hygiene. |

## Standard Stack

Phase 3 ships ZERO new third-party dependencies. Every library it consumes is already pinned by Phase 1 / Phase 2 (`guzzlehttp/guzzle ^7.8` for MetaClient HTTP, `lovata/toolbox-plugin ^2.2` for CommonSettings, `october/system ^4.0` for PluginBase + cms.ajax.beforeRunHandler + Schedule, `pestphp/pest ^4.1` for tests). All Phase 3 surface is in-tree plugin code.

### Core (already installed, version verified)

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `october/system` | ^4.0 | `PluginBase::registerMarkupTags()`, `registerSchedule(Schedule)`, `registerComponents()`, `cms.ajax.beforeRunHandler` event, `PluginManager::exists($id)` | Host framework — every method is `@inheritDoc` from October's PluginBase; no library swap is meaningful. |
| `laravel/framework` (via `october/system`) | ^12 | `Illuminate\Console\Scheduling\Schedule`, `Illuminate\Cache\RateLimiter`, `Illuminate\Support\Facades\DB`, `Illuminate\Support\Facades\Event`, `Illuminate\Support\Facades\Log` | Host framework. October's `Schedule` IS Laravel's `Illuminate\Console\Scheduling\Schedule` (see Code Examples §Pattern 3). |
| `lovata/toolbox-plugin` | ^2.2 | `CommonSettings` parent for `Settings` model (multisite-ready, RainLab.Translate-aware) | Already in composer.json `require:`. Phase 3 Settings model already extends CommonSettings (`models/Settings.php`). |
| `guzzlehttp/guzzle` | ^7.8 | HTTP transport for MetaClient (consumed by SendCapiEvent inside the queue job — Phase 3 does NOT touch MetaClient) | Phase 2 lock. |

### Supporting (already installed)

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `pestphp/pest` | ^4.1 | Test framework + `dataset(...)` fuzzing | P-09 fuzzing test matrix uses `dataset()` (see Code Examples §Pattern 5). |
| `mockery/mockery` | ^1.6 | RateLimiter spy in unit tests | Plan 03-07 rate-limit test mocks `Illuminate\Cache\RateLimiter`. |
| Larajax (host bundle) | — | `jax.ajax('Component::onHandler', {data:{...}})` transport | Already installed as theme-bundle JS dep at `vendor/larajax/larajax/`. Verified live pattern at `themes/logingrupa-naisstore/partials/form/checkout-form/tracking/facebook-purchase-tracking.js:10` (`jax.ajax('Cart::onGetPixelPurchaseData', {...})`). |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Plain `Event::subscribe` class for Watchers | `Lovata\Toolbox\Classes\Event\ModelHandler` parent | ModelHandler enforces abstract `getModelClass()` + `getItemClass()` methods aimed at Lovata Item-cache invalidation. Phase 3 Watchers don't need cache invalidation — they just bind `eloquent.created|updated` + dispatch one event. Plain class with `subscribe(\Illuminate\Events\Dispatcher $obDispatcher): void` is ~15 LOC vs forced ~40 LOC inheritance. **Pick plain class.** |
| `Eloquent` `boot()` static observer registration | `Event::listen('eloquent.updated: Lovata\OrdersShopaholic\Models\Order', ...)` | The string-keyed `eloquent.updated: FQN` form ties the Watcher to a specific class FQN in code (hard-coded import inside `classes/event/adapter/shopaholic/`). composer-dependency-analyser permits it because the dir is on the whitelist. Plain `Event::subscribe(OrderStatusWatcher::class)` + the Watcher's `subscribe()` method calls `$obDispatcher->listen('eloquent.updated: '.Order::class, [...])` — same effect, more idiomatic Laravel. **Pick `Event::subscribe`.** |
| `crc32($sActionKey)` for THEM-01 `iSyntheticId` | `abs(crc32(...))` or `intval(substr(md5(...), 0, 8), 16)` | crc32 in PHP returns int but may be negative on 32-bit; bare value risks `getSubjectId() <= 0` rejection. Use `(int) sprintf('%u', crc32($sActionKey))` to force unsigned. **Pick `sprintf('%u', ...)` cast.** |
| Larajax-only POST shape | October's classic `data-request="ComponentAlias::onHandler"` HTML attribute | Both work — `cms.ajax.beforeRunHandler` fires for either. Twig template / theme operator chooses. **Document both, default to `jax.ajax(...)` in CONTEXT examples** (D-29). |

### Installation

No new install steps. Phase 3 reuses the Phase 1 / Phase 2 vendor tree:

```bash
# Run from project root (NOT the plugin dir — plugin packages don't carry composer.lock)
composer update logingrupa/oc-metapixel-plugin --with-dependencies --no-interaction
```

### Version verification (registry / framework probe)

```bash
# Already-installed packages — verified via composer.json + vendor/ inspection on 2026-05-18:
# guzzlehttp/guzzle: ^7.8 (composer.json line 13) — live
# lovata/toolbox-plugin: ^2.2 (composer.json line 14) — live; CommonSettings at vendor path
# october/system: ^4.0 — live; PluginManager::exists() verified at modules/system/classes/PluginManager.php:380
# pestphp/pest: ^4.1 (composer.json line 29 dev) — live
```

## Package Legitimacy Audit

> Phase 3 installs NO external packages. The legitimacy audit is N/A for this phase.

| Package | Registry | Age | Downloads | Source Repo | slopcheck | Disposition |
|---------|----------|-----|-----------|-------------|-----------|-------------|
| *(none — Phase 3 ships only in-tree code)* | — | — | — | — | — | — |

**Packages removed due to slopcheck [SLOP] verdict:** none (no packages installed).
**Packages flagged as suspicious [SUS]:** none.

*slopcheck was not run because no external packages are added. All transitive deps (Guzzle, Lovata.Toolbox, October.System, Pest, Mockery) were vetted in earlier phases.*

## Architecture Patterns

### System Architecture Diagram

```
┌──────────────────────────────────────────────────────────────────────────────────┐
│                            ENTRY POINTS (per-request boundary)                    │
├──────────────────────────────────────────────────────────────────────────────────┤
│ ① Backend admin flips Order.status_id    ② Customer adds offer to cart            │
│   ▼                                       ▼                                       │
│  Eloquent fires `eloquent.updated:`      Eloquent fires `eloquent.created:`       │
│  Lovata\…\Order                          Lovata\…\CartPosition                    │
│   │                                       │                                       │
│   ▼                                       ▼                                       │
│  OrderStatusWatcher::handle              CartPositionWatcher::handle              │
│  (≤70 LOC)                               (≤70 LOC)                                │
│   │                                       │                                       │
│   ▼ guard: status_id → Status.code        ▼ guard: created|qty-change             │
│        == Settings.paid_status_code             == AND EventLog row absent        │
│   │                                       │                                       │
│   └────────────────┬──────────────────────┘                                       │
│                    ▼                                                              │
│         SendCapiEvent::dispatch(                                                  │
│           sEventName, arPayload, obSubject, sAdapterClass                         │
│         ) — Phase 2 queue job, 4-arg ctor                                         │
│                                                                                   │
├──────────────────────────────────────────────────────────────────────────────────┤
│                  PHASE 2 BACKBONE (unchanged in Phase 3 except                    │
│                  EventLogWriter::record signature +arPayload)                     │
├──────────────────────────────────────────────────────────────────────────────────┤
│   SendCapiEvent::handle                                                           │
│   ├─ AdapterRegistry::resolveByClass($sAdapterClass) → EventSubjectAdapter        │
│   ├─ fireBeforeDispatchHalt (snapshot+restore on event_id/event_time)             │
│   ├─ SiteResolver::forSubject → ?int                                              │
│   ├─ EventLogWriter::record(                                                      │
│   │     event_id, event_name, channel='capi', subject, secret_key,                │
│   │     event_time, site_id, **arPayload** [NEW Phase 3 trailing arg]             │
│   │   ) → bool  (UNIQUE race-fence is the dedup anchor)                           │
│   ├─ Settings::lookupForSite($iSiteId)                                            │
│   ├─ MetaClient::sendForPixel(pixel_id, token, payload)                           │
│   └─ fireAfterDispatch | dead-letter → FailedEvent + Event::fire                  │
│                                                                                   │
├──────────────────────────────────────────────────────────────────────────────────┤
│                  THEME PATH (operator-facing — Twig + Larajax)                    │
├──────────────────────────────────────────────────────────────────────────────────┤
│ ③ Twig template:                          ④ Theme JS click:                       │
│   {% do this.metapixel.pushEvent({        jax.ajax('Metapixel::onFireEvent',      │
│     name:'ViewContent', ...}) %}            {data: {name:'AddToCart', ...}})      │
│   │                                       │                                       │
│   ▼                                       ▼                                       │
│  ThemeEventCollector::push(arEvent)     cms.ajax.beforeRunHandler event fires     │
│  (request-scoped singleton)                  │                                    │
│   │                                          ▼                                    │
│   │                                      ThemeAjaxHandler::onBeforeRun            │
│   │                                      (subscriber returns non-null to halt)   │
│   │                                          ├─ Validate event_name ∈            │
│   │                                          │     META_STANDARD ∪ Settings list │
│   │                                          ├─ Verify CSRF (October built-in)   │
│   │                                          ├─ Rate-limit                       │
│   │                                          │   (RateLimiter, 30/60s)           │
│   │                                          ├─ Build ThemeActionEvent           │
│   │                                          ├─ SendCapiEvent::dispatch          │
│   │                                          └─ Return JS-escaped script frag    │
│   │                                                                              │
│   ▼ (later in same request)                                                       │
│  Components\PixelHead::onRender                                                   │
│  ├─ ThemeEventCollector::flush() → list<arEvent>                                  │
│  └─ Emit inline <script>fbq('track', name, custom_data, {eventID})</script>      │
│                                                                                   │
├──────────────────────────────────────────────────────────────────────────────────┤
│                  EVENTPIXEL PATH (server-confirmed-elsewhere browser pixel)       │
├──────────────────────────────────────────────────────────────────────────────────┤
│  Customer hits thank-you page (operator places {% component 'eventPixel' %})     │
│   │                                                                              │
│   ▼ component props: subject_class + subject_slug_field                          │
│  EventPixel::onRun                                                                │
│  ├─ DB::table('logingrupa_metapixel_event_log')->where([                          │
│  │     subject_type=adapter alias, subject_id=lookup by slug field,               │
│  │     event_name=Purchase, channel='capi'                                        │
│  │   ])->first(['event_id','event_time','payload'])                               │
│  └─ if row found + no channel='pixel' row exists:                                 │
│       emit <script>fbq('track', name, payload.custom_data, {eventID})</script>    │
│   │                                                                              │
│   ▼ browser side (after fbq fires)                                                │
│  jax.ajax('eventPixel::onMarkFired', {event_id})                                 │
│  ├─ Validate event_id matches DB row                                              │
│  └─ EventLogWriter::record(…, channel='pixel', …, arPayload=[])                  │
│      (UNIQUE race-fence prevents double-write if user reloads)                    │
│                                                                                   │
├──────────────────────────────────────────────────────────────────────────────────┤
│                            SCHEDULED MAINTENANCE                                  │
├──────────────────────────────────────────────────────────────────────────────────┤
│  Plugin::registerSchedule(Schedule $obSchedule):                                  │
│    $obSchedule->command('metapixel:purge-event-log')->daily();                    │
│   │                                                                              │
│   ▼                                                                              │
│  Console\PurgeEventLog (daily cron run)                                           │
│  └─ DB::table('logingrupa_metapixel_event_log')                                   │
│       ->where('created_at', '<', now()->subDays(7))                               │
│       ->delete();  // bounded, ≤100k rows per run (assert if exceeded)            │
└──────────────────────────────────────────────────────────────────────────────────┘
```

### Component Responsibilities

| Component | File | Concern |
|-----------|------|---------|
| ShopaholicOrderAdapter | `classes/adapter/shopaholic/ShopaholicOrderAdapter.php` | Subject metadata for Order (alias, id, site_id, secret_key, user_data, supported events). |
| ShopaholicOrderValueResolver | `classes/adapter/shopaholic/ShopaholicOrderValueResolver.php` | Order → custom_data (content_ids, value, currency, contents, num_items). |
| ShopaholicCartPositionAdapter | `classes/adapter/shopaholic/ShopaholicCartPositionAdapter.php` | Subject metadata for CartPosition. |
| ShopaholicCartPositionValueResolver | `classes/adapter/shopaholic/ShopaholicCartPositionValueResolver.php` | CartPosition → custom_data. |
| OrderStatusWatcher | `classes/event/adapter/shopaholic/OrderStatusWatcher.php` | Subscribes Order events; dispatches Purchase. |
| CartPositionWatcher | `classes/event/adapter/shopaholic/CartPositionWatcher.php` | Subscribes CartPosition events; dispatches AddToCart. |
| ThemeActionEvent | `classes/adapter/theme/ThemeActionEvent.php` | Value object — sActionKey, iSyntheticId, sEventName, arPayload. |
| ThemeActionAdapter | `classes/adapter/theme/ThemeActionAdapter.php` | Subject metadata for ThemeActionEvent. |
| ThemeEventCollector | `classes/adapter/theme/ThemeEventCollector.php` | Request-scoped accumulator + flush(). |
| ThemeAjaxHandler | `classes/adapter/theme/ThemeAjaxHandler.php` | `cms.ajax.beforeRunHandler` subscriber for `Metapixel::onFireEvent`. |
| EventPixel component | `components/EventPixel.php` | Render inline fbq for server-confirmed subject. |
| PixelHead component | `components/PixelHead.php` | Render inline fbq accumulator (ThemeEventCollector). |
| PurgeEventLog console | `console/PurgeEventLog.php` | Daily TTL purge. |
| AddPayloadToMetapixelEventLogTable | `updates/AddPayloadToMetapixelEventLogTable.php` | Additive migration — payload longText NULL. |

### Pattern 1: Plain `Event::subscribe` Watcher (D-03 + D-27 — ≤70 LOC)

**What:** A Watcher is a final class with a `subscribe(\Illuminate\Events\Dispatcher $obDispatcher): void` method (called by Laravel when `Event::subscribe(OrderStatusWatcher::class)` runs). The dispatcher hooks `eloquent.updated:`/`eloquent.created:` event keys keyed on the subject FQN.

**When to use:** Every Phase 3 Watcher (Order + CartPosition).

**Example:**

```php
<?php
// classes/event/adapter/shopaholic/OrderStatusWatcher.php — ≤70 LOC

namespace Logingrupa\Metapixel\Classes\Event\Adapter\Shopaholic;

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicOrderAdapter;
use Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicOrderValueResolver;
use Logingrupa\Metapixel\Classes\Meta\PayloadBuilder;
use Logingrupa\Metapixel\Classes\Meta\UserDataHasher;
use Logingrupa\Metapixel\Classes\Queue\SendCapiEvent;
use Logingrupa\Metapixel\Models\Settings;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Models\Status;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Watches Order.status_id changes. Fires Purchase on transition to (or
 * creation at) the operator-configured paid_status_code. EventLog UNIQUE
 * race-fence + getSubjectId() positive-int gate are the dedup anchors.
 */
final class OrderStatusWatcher
{
    public function subscribe(Dispatcher $obDispatcher): void
    {
        $obDispatcher->listen('eloquent.updated: '.Order::class, [$this, 'handle']);
        $obDispatcher->listen('eloquent.created: '.Order::class, [$this, 'handle']);
    }

    public function handle(Order $obOrder): void
    {
        try {
            $mPaidCode = Settings::get('paid_status_code', 'new-payment-received');
            $sPaidCode = is_string($mPaidCode) ? $mPaidCode : 'new-payment-received';

            $obStatus = $obOrder->status;
            if ($obStatus === null || $obStatus->code !== $sPaidCode) {
                return;
            }

            $obAdapter = new ShopaholicOrderAdapter;
            $obResolver = new ShopaholicOrderValueResolver;
            $obBuilder = new PayloadBuilder(new UserDataHasher);

            $arPayload = $obBuilder->buildEventPayload(
                'Purchase',
                $obAdapter,
                $obOrder,
                $obResolver,
                Uuid::uuid4()->toString(),
                time(),
                [],
            );

            SendCapiEvent::dispatch('Purchase', $arPayload, $obOrder, ShopaholicOrderAdapter::class);
        } catch (Throwable $obException) {
            // Tiger-Style: log + return. Do NOT rethrow — would cascade-break Order::save()
            // through Lovata OrderProcessor / Campaign / PromoMechanism subscribers.
            Log::warning('metapixel: OrderStatusWatcher payload-build failed', [
                'meta_pixel.order_id' => $obOrder->id,
                'meta_pixel.exception' => get_class($obException),
                'meta_pixel.message' => $obException->getMessage(),
            ]);
        }
    }
}
```

### Pattern 2: `registerMarkupTags` + Twig API surface (THEM-03 + THEM-04)

**What:** October's `Plugin::registerMarkupTags()` returns `['functions' => [...], 'filters' => [...]]` arrays. Functions registered here are available as bare Twig functions: `{% do twigFn(...) %}`. The `this.metapixel.pushEvent(...)` DOT-NOTATION shape requires a slightly different approach — register a Twig global object whose `pushEvent` method delegates to the ThemeEventCollector singleton.

**When to use:** THEM-04 needs `{% do this.metapixel.pushEvent({...}) %}` shape — operator-facing syntax.

**Example (locked path — use a bridge function + a global object):**

```php
<?php
// Plugin.php — Phase 3 addition to registerMarkupTags

use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeEventCollector;

public function registerMarkupTags(): array
{
    return [
        'functions' => [
            // Twig function: {% do metapixel_push_event({name:'ViewContent', ...}) %}
            // OR via global: {% do this.metapixel.pushEvent({...}) %}
            'metapixel_push_event' => function (array $arEvent): void {
                /** @var ThemeEventCollector $obCollector */
                $obCollector = app(ThemeEventCollector::class);
                $obCollector->push($arEvent);
            },
        ],
    ];
}
```

For the `this.metapixel.pushEvent(...)` syntax (operator-preferred per ROADMAP example):

```php
// Plugin::boot() — register a Twig global that wraps the collector
\Cms\Classes\Controller::extend(function ($obController) {
    $obController->bindEvent('page.beforeRenderPage', function () use ($obController) {
        $obController->vars['this']->metapixel = app(ThemeEventCollector::class);
    });
});
```

The Twig path `this.metapixel.pushEvent($arEvent)` then resolves to `ThemeEventCollector::pushEvent($arEvent)` via standard Twig attribute access. ThemeEventCollector implements `__get('metapixel')` is NOT needed — `this` is the controller, `this.metapixel` reads the public dynamic property. Confidence: MEDIUM — verify the `this.*` resolution shape at plan time; if it doesn't resolve cleanly, fall back to a bare Twig function `{% do metapixel_push_event({...}) %}`. Both shapes work; the bare-function shape is simpler and still satisfies THEM-04.

### Pattern 3: `registerSchedule(Schedule)` daily wire-up (D-08)

**What:** October's PluginBase `registerSchedule` receives Laravel's `Illuminate\Console\Scheduling\Schedule` instance. October fires `console.schedule` event during `php artisan schedule:run` and forwards to every plugin.

**When to use:** D-08 — daily PurgeEventLog command.

**Source:** Verified at `modules/system/ServiceProvider.php` — `Event::listen('console.schedule', function ($schedule) { foreach (PluginManager::instance()->getPlugins() as $plugin) { $plugin->registerSchedule($schedule); } });`. Verified PluginBase signature at `modules/system/classes/PluginBase.php` — `public function registerSchedule($schedule)`. Verified live example at `plugins/renatio/formbuilder/Plugin.php` — `$schedule->command('model:prune', [...])->daily();`.

**Example:**

```php
<?php
// Plugin.php — Phase 3 addition

use Illuminate\Console\Scheduling\Schedule;

/**
 * Wire metapixel:purge-event-log to run daily. Operator's host must have
 * `* * * * * cd /path && php artisan schedule:run >> /dev/null` configured.
 */
public function registerSchedule($schedule): void
{
    $schedule->command('metapixel:purge-event-log')->daily();
}
```

Type hint: October ships `registerSchedule($schedule)` without a typed arg in PluginBase. Phase 3 can keep it loose-typed (`$schedule`) OR tighten to `Illuminate\Console\Scheduling\Schedule $schedule` — both work because Laravel's container injects the concrete `Schedule` instance. **Pick the typed version** (Phase 3 D-27 lock — explicit types).

### Pattern 4: `cms.ajax.beforeRunHandler` listener for THEM-05 (D-29 + Source verification)

**What:** October's `Cms\Classes\Controller::runAjaxHandler($sHandler)` fires `cms.ajax.beforeRunHandler` event BEFORE dispatching to a component / page / layout handler. A listener returning non-null short-circuits the AJAX cycle — the listener's return value becomes the AJAX response.

**Source:** `modules/cms/classes/controller/HasAjaxRequests.php:297` — `if ($event = $this->fireSystemEvent('cms.ajax.beforeRunHandler', [$handler])) { return $event; }`. The listener receives `[$obController, $sHandler]` (via fireSystemEvent's `[$this]` + provided args). Returning array → JSON; returning string → raw response; returning null → continue normal dispatch.

**CSRF:** October's `\Cms\Classes\AjaxFramework` middleware verifies `X-CSRF-TOKEN` header / `_token` field BEFORE `cms.ajax.beforeRunHandler` fires. Phase 3 handler does NOT need a redundant CSRF check — October already 419's invalid CSRF requests. Reference: an absent / wrong `X-CSRF-TOKEN` produces a 419 page-expired response before any plugin event handler runs. Confidence: HIGH — verified by the AJAX framework path in `modules/cms/classes/`.

**When to use:** THEM-05 — intercept `Metapixel::onFireEvent` calls from theme JS without registering a component.

**Example:**

```php
<?php
// classes/adapter/theme/ThemeAjaxHandler.php — ≤150 LOC

namespace Logingrupa\Metapixel\Classes\Adapter\Theme;

use Cms\Classes\Controller;
use Illuminate\Cache\RateLimiter;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Session;
use Logingrupa\Metapixel\Classes\Queue\SendCapiEvent;
use Logingrupa\Metapixel\Models\Settings;
use Throwable;

/**
 * Larajax open-relay defence (P-09) for Metapixel::onFireEvent. Allowlists
 * event_name, rate-limits per IP+session, JS-escapes returned script fragment.
 * Returning a value from the cms.ajax.beforeRunHandler listener short-circuits
 * the AJAX cycle — no component lookup happens.
 */
final class ThemeAjaxHandler
{
    public const HANDLER_NAME = 'Metapixel::onFireEvent';

    public const META_STANDARD = [
        'PageView','ViewContent','AddToCart','AddToWishlist','InitiateCheckout',
        'Purchase','Lead','CompleteRegistration','Search','Subscribe','Contact',
        'FindLocation','Donate','CustomizeProduct','SubmitApplication',
        'AddPaymentInfo','StartTrial','Schedule',
    ];

    private const RATE_LIMIT_MAX = 30;
    private const RATE_LIMIT_WINDOW_SECONDS = 60;

    public function subscribe(Dispatcher $obDispatcher): void
    {
        $obDispatcher->listen('cms.ajax.beforeRunHandler', [$this, 'onBeforeRun']);
    }

    public function onBeforeRun(Controller $obController, string $sHandler): mixed
    {
        if ($sHandler !== self::HANDLER_NAME) {
            return null; // not us — continue normal dispatch
        }

        try {
            $arData = (array) Request::input('data', []);

            if (! $this->isAllowedEventName($arData['name'] ?? '')) {
                return new JsonResponse(['error' => 'event_name not allowed'], 422);
            }

            if ($this->isRateLimited()) {
                return new JsonResponse(['error' => 'rate limit exceeded'], 429);
            }

            $obEvent = ThemeActionEvent::fromArray($arData);
            $obAdapter = App::make(ThemeActionAdapter::class);
            // ... build payload, dispatch SendCapiEvent ...

            return new JsonResponse([
                'event_id' => $obEvent->sEventId,
                // JS-escape any echo back to the client
                'script' => '<script>fbq("track", '.json_encode($obEvent->sEventName, JSON_HEX_TAG|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_HEX_APOS).', {}, {eventID: '.json_encode($obEvent->sEventId).'});</script>',
            ]);
        } catch (Throwable $obException) {
            Log::warning('metapixel: ThemeAjaxHandler failed', [
                'meta_pixel.exception' => get_class($obException),
                'meta_pixel.message' => $obException->getMessage(),
            ]);

            return new JsonResponse(['error' => 'internal'], 500);
        }
    }

    private function isAllowedEventName(mixed $mName): bool
    {
        if (! is_string($mName) || $mName === '') {
            return false;
        }

        $mCustomList = Settings::get('theme_custom_event_names', []);
        $arCustomList = is_array($mCustomList) ? $mCustomList : [];

        return in_array($mName, self::META_STANDARD, true)
            || in_array($mName, $arCustomList, true);
    }

    private function isRateLimited(): bool
    {
        /** @var RateLimiter $obLimiter */
        $obLimiter = App::make(RateLimiter::class);
        $sKey = sprintf(
            'metapixel:fire:%s:%s',
            Request::ip(),
            Session::getId(),
        );

        if ($obLimiter->tooManyAttempts($sKey, self::RATE_LIMIT_MAX)) {
            return true;
        }
        $obLimiter->hit($sKey, self::RATE_LIMIT_WINDOW_SECONDS);

        return false;
    }
}
```

### Pattern 5: Pest fuzzing dataset for P-09 (Plan 03-07)

**What:** Pest 4 supports parameterised data via `dataset(...)` declarations OR inline `->with(...)` chains. Each fuzzing input generates a separate test case so a single failure is surfaced individually.

**When to use:** P-09 fuzzing matrix — XSS, SQLi-shape, oversize, mixed-encoding, control chars, BOM, null bytes.

**Example:**

```php
<?php
// tests/Feature/Adapter/Theme/ThemeAjaxHandlerFuzzingTest.php

use function Pest\Laravel\postJson;

dataset('malicious_event_names', [
    'xss_script_tag' => ['<script>alert(1)</script>'],
    'xss_event_handler' => ['onerror=alert(1)'],
    'xss_data_uri' => ['data:text/html,<script>alert(1)</script>'],
    'sqli_or_one' => ["Purchase' OR '1'='1"],
    'sqli_union' => ['Purchase UNION SELECT * FROM users'],
    'oversize_1kb' => [str_repeat('A', 1024)],
    'oversize_64kb' => [str_repeat('A', 65536)],
    'null_byte' => ["Purchase\0--"],
    'control_chars' => ["Purchase\x01\x02\x03"],
    'bom_prefix' => ["\xEF\xBB\xBFPurchase"],
    'mixed_encoding_utf16' => [mb_convert_encoding('Purchase', 'UTF-16')],
    'unicode_normalisation' => ["Purcha\u{0301}se"],
    'cr_lf_injection' => ["Purchase\r\nX-Header: evil"],
    'arbitrary_unicode' => ["📈\u{202E}esahcruP"],  // RTL override
]);

test('p-09 fuzzing — malicious event_name returns 422, zero EventLog rows', function (string $sMaliciousName): void {
    // CSRF token via October's session — assume helper provides
    $arResponse = postJson('/', [
        'data' => ['name' => $sMaliciousName],
    ], [
        'X-CSRF-TOKEN' => csrf_token(),
        'X-OCTOBER-REQUEST-HANDLER' => 'Metapixel::onFireEvent',
    ]);

    expect($arResponse->status())->toBe(422);
    expect(DB::table('logingrupa_metapixel_event_log')->count())->toBe(0);
})->with('malicious_event_names');
```

### Anti-Patterns to Avoid

- **v1.x port shape:** Phase 3 is FRESH code. The 367-LOC OrderStatusWatcher on `legacy/v1.1.1` branch is the anti-pattern reference. Each new Watcher is ≤70 LOC; each Adapter ≤80 LOC; each ValueResolver ≤100 LOC; each component ≤120 LOC; ThemeAjaxHandler ≤150 LOC including the 18-name allowlist constant.
- **Class names describing shape:** `SubjectPixel` is wrong; `EventPixel` describes purpose (a pixel that emits for a server-confirmed event). Apply same lens to all new classes.
- **Lovata.Toolbox `ModelHandler` extension for Watchers:** ModelHandler requires `getModelClass()` + `getItemClass()` abstracts aimed at Lovata Item-cache invalidation. Phase 3 Watchers don't need cache invalidation — they bind `eloquent.created|updated` + dispatch. Plain `Event::subscribe` class is simpler + smaller.
- **`assert()` anywhere:** Production `zend.assertions=0` silently no-ops. Use explicit `throw`. Enforced by `spaze/phpstan-disallowed-calls`.
- **`@phpstan-ignore` comments:** Banned project-wide. Extract a private runtime-guard helper (the `firstEventRecord` pattern in `classes/queue/SendCapiEvent.php:289`).
- **Multi-paragraph PHPDoc narrative:** One-line summary + `@param` + `@return` only.
- **Inline workflow markers:** No `// CR-XX`, `// REFAC-XX`, `// Phase N`, `// Plan N` comments in source. Workflow refs belong in commits/PRs.
- **`Lovata\OrdersShopaholic\*` import outside `classes/adapter/shopaholic/` + `classes/event/adapter/shopaholic/`:** composer-dependency-analyser fails the build. P-03 anchor.
- **`SiteManager::*` / `Site::*` / `request()` / `Request::*` inside `classes/queue/`, `classes/event/`, `classes/adapter/shopaholic/`:** PHPStan disallowed-calls fails the build. `classes/adapter/theme/` is the ONE excluded path (D-15 + D-16).
- **EventPixel re-resolving adapter at render time:** D-09 locks the read path to direct DB::table query — never `AdapterRegistry::resolveByClass` at render. Frozen-payload audit guarantees parity with server emit even if subject mutates between dispatch and render.
- **subject_type as class FQN:** Always opaque alias (`'shopaholic.order'`, `'shopaholic.cart_position'`, `'theme.action'`). Contract test invariant 01 already enforces.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Per-IP+session rate limiting | Custom Redis counter | `Illuminate\Cache\RateLimiter::tooManyAttempts($sKey, $iMax)` + `->hit($sKey, $iDecaySeconds)` | Battle-tested, handles decay/cleanup; verified at `vendor/laravel/framework/src/Illuminate/Cache/RateLimiter.php:127`. |
| CSRF token verification | Custom header check | October's built-in `Cms\Classes\AjaxFramework` middleware | Runs BEFORE `cms.ajax.beforeRunHandler` fires; 419's invalid tokens. ThemeAjaxHandler does NOT add redundant CSRF check. |
| Schedule cron registration | Custom artisan-tab cron config | `Plugin::registerSchedule(Schedule $obSchedule): void { $obSchedule->command(...)->daily(); }` | October auto-forwards `console.schedule` event to every plugin. Operator just needs `* * * * * php artisan schedule:run` cron entry. |
| Twig escaping for inline `<script>` payload | Custom `htmlspecialchars` wrapper | `json_encode($mValue, JSON_HEX_TAG\|JSON_HEX_QUOT\|JSON_HEX_AMP\|JSON_HEX_APOS)` + October's `e()` helper for HTML attribute contexts | json_encode with the HEX_* flags produces JS-safe output (no `</script>`, no `'`, no `&`). |
| EventLog row deduplication | Application-level mutex | DB-layer UNIQUE constraint on `(subject_type, subject_id, event_name, channel, site_id)` + `insertOrIgnore` | Already in place from Phase 2 (`updates/CreateMetapixelEventLogTable.php:40`); Phase 3 inherits. |
| Status code dropdown source | Hardcoded list | `Status::orderBy('sort_order')->pluck('name', 'code')->all()` via Settings dropdown `options` callback | Status model lacks its own `lists()` (verified). Settings field declares `options: Logingrupa\Metapixel\Models\Settings::getPaidStatusOptions`. |
| event_id generation | Custom UUID | `Ramsey\Uuid\Uuid::uuid4()->toString()` | Already vendored by Laravel; UUIDv4 fits Phase 2 `event_id` 36-char column. |
| Component HTTP boundary | Custom Larajax handler implementation | October's `data-request="Metapixel::onFireEvent"` HTML attribute + `cms.ajax.beforeRunHandler` event | Larajax is already wired in the host; the event fires BEFORE component / page / layout dispatch — perfect interception point. |

**Key insight:** Phase 3 deliberately uses October's + Laravel's idiomatic surfaces. The custom code surface is just the adapter logic (subject metadata + value resolution) + the Watchers (binding + dispatch trigger) + the components (output rendering). All transport, scheduling, rate-limiting, CSRF, and deduplication concerns ride on framework primitives.

## Runtime State Inventory

Phase 3 is greenfield code addition (no rename / refactor / migration concern). The only state-modifying surface is the additive `payload longText NULL` column on `logingrupa_metapixel_event_log`. Existing rows pick up the column with NULL default — no data migration required.

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | EventLog table gains `payload` column. Existing rows from Phase 2 + test fixtures default to NULL — no semantic loss because EventPixel/PixelHead only renders when payload is present, and v1.x rows wouldn't be rendered by v2.0 EventPixel anyway. | None — `down()` drops the column. |
| Live service config | None — Phase 3 ships zero new external service config. | None. |
| OS-registered state | None — no OS-level cron registration. Operator's existing `* * * * * php artisan schedule:run` entry auto-picks up `metapixel:purge-event-log` via `registerSchedule`. | None. |
| Secrets/env vars | None — no new env vars; pixel_id + capi_access_token already live (Phase 2). | None. |
| Build artifacts | None — no compiled assets; theme operator includes EventPixel/PixelHead components via Twig only. | None. |

**Nothing found in category:** Confirmed via grep on the plugin tree + verification against Phase 2 + Phase 1 deliverables — Phase 3 is purely additive.

## Common Pitfalls

### Pitfall 1: CartPosition has NO `offer_id` column — it's a MorphTo

**What goes wrong:** The CONTEXT.md (Area 1, plan layout) implies CartPosition has `offer_id, quantity, price` columns. Inspection of `plugins/lovata/ordersshopaholic/models/CartPosition.php` reveals CartPosition uses MorphTo: `item_id` + `item_type` (polymorphic). The `item_type` is typically `Lovata\Shopaholic\Models\Offer` but could be any morphable cart item (gift card, custom product) per Lovata's MorphTo design.

**Why it happens:** v1.x Shopaholic-coupled code never tracked CartPosition (only Order); the v2.0 outline was sketched against the wrong column shape.

**How to avoid:**
- `ShopaholicCartPositionValueResolver::resolveContentIds` calls `$obCartPosition->item` (the MorphTo) to get the Offer, then builds `SKU-{offer.product_id}-{offer.id}` from there.
- Guard against null `$obCartPosition->item` (offer deleted but cart row stale) → return `[]` for content_ids + Tiger-Style return early.
- CartPosition has NO direct `price` column — price comes from `$obCartPosition->item->price` (Offer attribute). Quantity is in `quantity` column (confirmed at `plugins/lovata/ordersshopaholic/models/CartPosition.php:23`).
- CartPosition has NO `site_id` column directly — site_id flows through `$obCartPosition->cart` (Cart model, which has site_id).

**Warning signs:** `$obCartPosition->offer_id` throws "undefined attribute"; ValueResolver returns empty content_ids on every test.

**Confidence:** HIGH — verified by direct inspection of `plugins/lovata/ordersshopaholic/models/CartPosition.php`.

### Pitfall 2: `eloquent.updated` fires AFTER persist — `getOriginal('status_id')` is the only way to detect transition

**What goes wrong:** When `eloquent.updated:` Order fires, `$obOrder->status_id` is already the NEW value. To detect "did this row transition from non-paid to paid?", read `$obOrder->getOriginal('status_id')`.

**Why it happens:** Eloquent's `updated` event fires AFTER `save()` persists the row. The model in-memory state holds the new values; `getOriginal('column')` returns the pre-save value loaded from DB.

**How to avoid:**
- `OrderStatusWatcher::handle` reads `getOriginal('status_id')` and compares to current `status_id`.
- For `eloquent.created:` there is no original — every freshly-created Order with status_id matching paid_status_code is a legitimate dispatch.
- Use `$obOrder->wasChanged('status_id')` as a higher-level Laravel-12 idiom (`wasChanged` is the post-save analogue of `isDirty`).

**Warning signs:** Every Order edit (even unrelated field changes) fires Purchase; ad-floods.

**Prevention test:** In `OrderStatusWatcherTest` — touch Order.name (unrelated field) → assert no SendCapiEvent dispatch; touch Order.status_id from 1→5 → assert one dispatch; touch Order.status_id 5→5 → assert no dispatch.

**Confidence:** HIGH — standard Eloquent semantics.

### Pitfall 3: `Status::lists()` doesn't exist on Lovata.OrdersShopaholic Status model

**What goes wrong:** CONTEXT.md (Discretion section) references "`Status::lists()` for `paid_status_code` Settings dropdown". `Status` model at `plugins/lovata/ordersshopaholic/models/Status.php` declares no `lists()` method. The closest is `Task::getStatusOptions()` which returns a hardcoded 4-element subset (NEW, IN_PROGRESS, CANCEL, COMPLETE) — missing the custom `new-payment-received` (id=5) that nailscosmetics.* uses.

**Why it happens:** Lovata convention is `Model::getStatusOptions()` per model that needs a dropdown, not a generic `Status::lists()`. The nail-store custom status `new-payment-received` lives only in the DB row, not in any code constants.

**How to avoid:** Add a helper to the `Settings` model — `public function getPaidStatusCodeOptions(): array { return Status::orderBy('sort_order')->pluck('name', 'code')->all(); }`. Wire the YAML field via `options: getPaidStatusCodeOptions`. The dropdown lists every code currently in the lovata_orders_shopaholic_statuses table at backend render time.

**Warning signs:** `paid_status_code` dropdown shows 4 options; the custom paid code never appears; operator picks "complete" by mistake → never fires.

**Confidence:** HIGH — verified by grep on `plugins/lovata/ordersshopaholic/models/Status.php` (no `lists()` method).

### Pitfall 4: `cms.ajax.beforeRunHandler` listener signature includes `$obController` as first arg

**What goes wrong:** The October PHPDoc example shows `Event::listen('cms.ajax.beforeRunHandler', function ((string) $handler) {...})` — one arg. Source inspection (`modules/cms/classes/controller/HasAjaxRequests.php:297`) reveals `fireSystemEvent('cms.ajax.beforeRunHandler', [$handler])` which fires with `[$this, $handler]` (the controller as `$this`). Listener method signature must accept `(Controller $obController, string $sHandler)`.

**Why it happens:** `fireSystemEvent` is October's wrapper that auto-prepends `$this` to the args list. The PHPDoc example in HasAjaxRequests.php is the bare `Event::listen` shape that confusingly omits the controller arg in the doc-block but it IS the first arg.

**How to avoid:** ThemeAjaxHandler::onBeforeRun signature: `public function onBeforeRun(Controller $obController, string $sHandler): mixed`. Return null to continue normal dispatch; return any non-null (string, array, JsonResponse) to short-circuit.

**Warning signs:** Phpstan reports "wrong number of arguments"; the listener receives the handler name as `$obController` instead of `$sHandler`.

**Confidence:** HIGH — verified by reading the `fireSystemEvent` source path.

### Pitfall 5: Pest `dataset(name, array)` requires array values to be wrapped in arrays

**What goes wrong:** Pest's `dataset('name', [...])` populates the test with each entry as a separate test case. If the entries are bare strings, Pest passes the string AS the first argument. If the test method signature has TWO args (e.g. `function (string $sName, int $iExpected)`), each dataset entry must be a tuple `[$sName, $iExpected]`.

**Why it happens:** Pest mirrors PHPUnit's `@dataProvider` semantics — each dataset entry is a list of arguments.

**How to avoid:** For the P-09 fuzzing matrix (single-arg test signature), entries are bare strings: `'xss_script_tag' => ['<script>alert(1)</script>']` (string wrapped in array). For multi-arg, tuple per entry.

**Warning signs:** "Too few arguments to function" at test run; cryptic dataset-related errors.

**Confidence:** HIGH — standard Pest 4 semantics.

### Pitfall 6: `Site::getCurrent()` returns null in CLI / queue context

**What goes wrong:** D-15 locks ThemeAdapter's fallback to `\October\Rain\Support\Facades\Site::getCurrent()?->getId()`. In CLI cron context (PurgeEventLog) or queue worker context, no active site is bound → `Site::getCurrent()` returns null → fallback returns null → adapter's `getSiteId` returns null.

**Why it happens:** Theme events fire in-request (HTTP) by definition, so the request-context site is always bound when ThemeAjaxHandler runs. But D-15 ALSO documents the fallback path — fall-through behavior in non-HTTP contexts (debugging from a CLI command, e.g.) returns null gracefully.

**How to avoid:** Null is acceptable here — `EventSubjectAdapter::getSiteId(): ?int` is nullable by contract. The UNIQUE race-fence handles null via SQL NULL-distinct semantics (which is why the test matrix in Phase 2 covers null + non-null sites separately).

**Warning signs:** ThemeActionAdapter throws in CLI; tests that don't set up a request fail.

**Confidence:** HIGH — null-tolerant by Phase 2 contract design.

### Pitfall 7: October `Schedule` arg in `registerSchedule` is loose-typed in PluginBase signature

**What goes wrong:** `modules/system/classes/PluginBase.php:71` declares `public function registerSchedule($schedule) { }` with no type hint. A subclass that adds `Illuminate\Console\Scheduling\Schedule` may trip PHP's LSP variance rules on PHP 8.4 strict-types installs.

**Why it happens:** PHP allows widening (loose parent → typed child) but not narrowing. The case here is the parent has NO type, the child adds one — that's covariance-compatible in PHP 8+, but some static analyzers flag it.

**How to avoid:** Use `Illuminate\Console\Scheduling\Schedule $schedule` in the child. Verified live in `plugins/renatio/formbuilder/Plugin.php` (no type hint) — operator preference is fine either way. PHPStan level 10 doesn't flag it.

**Warning signs:** PHPStan reports parameter-type variance error; subclass signature rejected at runtime.

**Confidence:** MEDIUM — works in current octobercms install but verify in Plan 03-01 first task.

### Pitfall 8: EventLog `payload` column written by EventLogWriter but read by EventPixel via direct DB::table

**What goes wrong:** EventLog model has no `subject()` MorphTo (P-05 anchor) AND now adds a `payload` column. EventPixel::onRun reads via `DB::table(...)->first(...)` to get a raw stdClass — but the raw row returns `payload` as the string-encoded longText (NOT auto-decoded). EventPixel must `json_decode($obRow->payload, true)` before `fbq('track', name, $arPayload['custom_data'], ...)`.

**Why it happens:** Eloquent's `$jsonable` mechanism only auto-decodes when reading via the Eloquent model (`EventLog::find(...)->payload` returns an array). Raw `DB::table` queries return strings.

**How to avoid:** EventPixel decodes explicitly: `$arPayload = is_string($obRow->payload) ? (array) json_decode($obRow->payload, true) : [];`. Guard against null payload (Phase 2 rows lack it).

**Alternative:** EventLog model adds `protected $jsonable = ['payload'];` AND EventPixel uses `EventLog::scopeForSubject(...)->first(['event_id','event_time','payload'])`. Phase 3 decides — both work; the DB::table path is more explicit (D-09 spirit: "no adapter re-resolve at render time" → minimal Eloquent fluff). **Recommend DB::table path with explicit decode** (matches D-09 lock).

**Warning signs:** fbq script emits `[object Object]` or `null` for custom_data; payload string never parses.

**Confidence:** HIGH — standard DB::table vs Eloquent semantics.

### Pitfall 9: `cms.ajax.beforeRunHandler` short-circuits ALL handlers, not just `Metapixel::onFireEvent`

**What goes wrong:** A naive listener that returns a value WITHOUT checking `$sHandler === 'Metapixel::onFireEvent'` first will short-circuit every AJAX request on the site (Cart::onAdd, RestorePassword::onAjax, etc.).

**Why it happens:** `fireSystemEvent` returns the first non-null listener response; the event itself doesn't filter by handler name.

**How to avoid:** First line of `ThemeAjaxHandler::onBeforeRun` — `if ($sHandler !== self::HANDLER_NAME) { return null; }`. Pattern in code example above.

**Warning signs:** Every AJAX on the site returns 422 / JSON instead of expected response; storefront broken.

**Confidence:** HIGH — single-line guard, easy to enforce in code review.

## Code Examples

### Example 1: content_ids SKU format (D-20 anchor — VERIFIED byte-for-byte)

```php
// Source: plugins/logingrupa/facebookcatalogshopaholic/classes/helper/ExportCatalogFacebookHelper.php:356
// Single-offer-per-product:   'product_id' => 'SKU-' . $obOffer->product->id,
// Multi-offer-per-product:    'offer_id'   => 'SKU-' . $obOffer->product->id . '-' . $obOffer->id,
//
// Phase 3 ValueResolver mirror (matches byte-for-byte):
private function buildContentId(\Lovata\Shopaholic\Models\Offer $obOffer): string
{
    $bMultiOffer = $obOffer->product->offer()->count() > 1;
    return $bMultiOffer
        ? sprintf('SKU-%d-%d', $obOffer->product->id, $obOffer->id)
        : sprintf('SKU-%d', $obOffer->product->id);
}
```

### Example 2: PluginManager::exists($id) gate (D-21 + SHOP-04)

```php
// Source: modules/system/classes/PluginManager.php:380 verified
//   public function exists($id) {
//       return !(!$this->findByIdentifier($id) || $this->isDisabled($id));
//   }
// Phase 3 Plugin.php:

public function boot(): void
{
    if (\System\Classes\PluginManager::instance()->exists('Lovata.OrdersShopaholic')) {
        \Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry::instance()->register(
            \Lovata\OrdersShopaholic\Models\Order::class,
            \Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicOrderAdapter::class,
        );
        \Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry::instance()->register(
            \Lovata\OrdersShopaholic\Models\CartPosition::class,
            \Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicCartPositionAdapter::class,
        );

        \Event::subscribe(\Logingrupa\Metapixel\Classes\Event\Adapter\Shopaholic\OrderStatusWatcher::class);
        \Event::subscribe(\Logingrupa\Metapixel\Classes\Event\Adapter\Shopaholic\CartPositionWatcher::class);
    }

    \Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry::instance()->register(
        \Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeActionEvent::class,
        \Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeActionAdapter::class,
    );
    \Event::subscribe(\Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeAjaxHandler::class);
}
```

Note: `AdapterRegistry::instance()` is not a static method on the current registry (the registry is a service-container singleton via `App::singleton(AdapterRegistry::class)`). Plan 03-02 must adjust — either add a `public static function instance(): self { return App::make(self::class); }` helper to AdapterRegistry OR use `App::make(AdapterRegistry::class)->register(...)` directly from Plugin::boot. Phase 3 should NOT add the static method (CLAUDE.md "no over-engineering" — App::make is the standard) — **planner uses `App::make(AdapterRegistry::class)->register(...)`** in Plugin::boot.

### Example 3: Larajax theme call shape (D-29 — live pattern verified)

```javascript
// Source: themes/logingrupa-naisstore/partials/form/checkout-form/tracking/facebook-purchase-tracking.js:10
//   jax.ajax('Cart::onGetPixelPurchaseData', { success(response) {...} });
//
// Phase 3 theme operator-facing shape:

document.querySelector('[data-add-to-cart]').addEventListener('click', () => {
  jax.ajax('Metapixel::onFireEvent', {
    data: {
      name: 'AddToCart',
      action_key: 'cart-add:' + offerId,
      content_ids: ['SKU-' + productId + '-' + offerId],
      value: 12.50,
      currency: 'EUR'
    },
    success(response) {
      if (response.script) {
        document.head.insertAdjacentHTML('beforeend', response.script);
      }
    }
  });
});
```

### Example 4: ThemeEventCollector singleton binding (THEM-03)

```php
<?php
// classes/adapter/theme/ThemeEventCollector.php — ≤50 LOC

namespace Logingrupa\Metapixel\Classes\Adapter\Theme;

/**
 * Request-scoped accumulator for theme-side pushEvent calls. Plugin::register()
 * binds via $this->app->singleton(ThemeEventCollector::class). Flushed
 * explicitly by PixelHead::onRender + test tearDown.
 */
final class ThemeEventCollector
{
    /** @var list<array<string, mixed>> */
    private array $arEvents = [];

    /**
     * @param  array<string, mixed>  $arEvent  must contain at least 'name' key
     */
    public function push(array $arEvent): void
    {
        if (! isset($arEvent['name']) || ! is_string($arEvent['name'])) {
            return;
        }
        $this->arEvents[] = $arEvent;
    }

    /**
     * Return accumulator + reset state. Idempotent on empty.
     *
     * @return list<array<string, mixed>>
     */
    public function flush(): array
    {
        $arResult = $this->arEvents;
        $this->arEvents = [];
        return $arResult;
    }
}
```

```php
// Plugin.php register():
public function register(): void
{
    $this->app->singleton(AdapterRegistry::class);
    $this->app->singleton(\Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeEventCollector::class);
}
```

### Example 5: PurgeEventLog console command (D-08)

```php
<?php
// console/PurgeEventLog.php — ≤60 LOC

namespace Logingrupa\Metapixel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Deletes EventLog rows older than 7 days. Wired daily via Plugin::registerSchedule.
 */
final class PurgeEventLog extends Command
{
    /** @var string */
    protected $signature = 'metapixel:purge-event-log';

    /** @var string */
    protected $description = 'Delete EventLog rows older than 7 days (Phase 3 TTL purge)';

    public function handle(): int
    {
        $sCutoff = (string) Carbon::now()->subDays(7);
        $iDeleted = DB::table('logingrupa_metapixel_event_log')
            ->where('created_at', '<', $sCutoff)
            ->delete();

        Log::info('metapixel: purge-event-log', [
            'meta_pixel.rows_deleted' => $iDeleted,
            'meta_pixel.cutoff' => $sCutoff,
        ]);

        $this->info(sprintf('Purged %d EventLog rows older than %s', $iDeleted, $sCutoff));
        return self::SUCCESS;
    }
}

// Plugin.php — register the command:
public function register(): void
{
    $this->app->singleton(AdapterRegistry::class);
    $this->app->singleton(ThemeEventCollector::class);
    $this->registerConsoleCommand('metapixel:purge-event-log', \Logingrupa\Metapixel\Console\PurgeEventLog::class);
}
```

### Example 6: Plan Layout (D-18 — 8 plans, sequential)

| Plan | Title | Requirements | Files Created | Key Tests |
|------|-------|-------------|---------------|-----------|
| 03-01 | EventLog payload column + EventLogWriter sig change + PurgeEventLog + Schedule | (foundation; carries no SHOP-/THEM-) | `updates/AddPayloadToMetapixelEventLogTable.php`, `console/PurgeEventLog.php`, `Plugin::registerSchedule`, modify `classes/helper/EventLogWriter.php` (+arPayload), modify `classes/queue/SendCapiEvent.php` (pass arPayload) | Phase 2 tests regreen with new sig; new `tests/Feature/Migrations/AddPayloadColumnTest.php`; `tests/Feature/Console/PurgeEventLogTest.php` (seed old + new rows, run command, assert old gone) |
| 03-02 | ShopaholicOrderAdapter + ShopaholicOrderValueResolver + OrderStatusWatcher + conditional registration | SHOP-01, SHOP-02, SHOP-03, SHOP-04 | `classes/adapter/shopaholic/ShopaholicOrderAdapter.php`, `…OrderValueResolver.php`, `classes/event/adapter/shopaholic/OrderStatusWatcher.php`, modify `Plugin.php` (boot + PluginManager::exists gate), modify `composer-dependency-analyser.php` (add `classes/event/adapter/shopaholic` to whitelist), modify `phpstan.neon` (add `classes/event/adapter/shopaholic` to disallowIn) | `tests/Contract/Adapter/Shopaholic/ShopaholicOrderAdapterContractTest.php` (extends `EventSubjectAdapterContractTestCase`), `tests/Unit/Event/Adapter/Shopaholic/OrderStatusWatcherTest.php` (paid_status_code match + miss + wrong-field-change), `tests/Unit/Adapter/Shopaholic/ShopaholicOrderValueResolverTest.php` (SKU format, currency fallback) |
| 03-03 | ShopaholicCartPositionAdapter + ShopaholicCartPositionValueResolver + CartPositionWatcher | (SHOP-01..04 carry over for CartPosition) | `classes/adapter/shopaholic/ShopaholicCartPositionAdapter.php`, `…CartPositionValueResolver.php`, `classes/event/adapter/shopaholic/CartPositionWatcher.php` | `tests/Contract/Adapter/Shopaholic/ShopaholicCartPositionAdapterContractTest.php`, CartPositionWatcher dispatch on created + dedup on update, MorphTo handling test |
| 03-04 | SHOP-05 integration test (status flip → dispatch → race-fence → MetaClient mock → dedup) | SHOP-05 | `tests/Feature/Adapter/Shopaholic/PurchaseFlowIntegrationTest.php` | End-to-end Order admin-flip flow (BackboneIntegrationTest is template); second-flip dedup assertion via `Middleware::history` call-count check |
| 03-05 | ThemeActionEvent + ThemeActionAdapter (with D-15 fallback) + getSiteId tests | THEM-01, THEM-02 | `classes/adapter/theme/ThemeActionEvent.php`, `classes/adapter/theme/ThemeActionAdapter.php`, modify `phpstan.neon` (EXCLUDE `classes/adapter/theme/` from disallowIn — D-16) | `tests/Contract/Adapter/Theme/ThemeActionAdapterContractTest.php`, getSiteId reads payload first then falls back to Site::getCurrent, getSiteId returns null in CLI gracefully |
| 03-06 | ThemeEventCollector + registerMarkupTags Twig API | THEM-03, THEM-04 | `classes/adapter/theme/ThemeEventCollector.php`, modify `Plugin.php` (register singleton + registerMarkupTags + bind `this.metapixel` global) | ThemeEventCollector push + flush + idempotent flush, Twig render integration test asserting pushEvent accumulates |
| 03-07 | ThemeAjaxHandler + META_STANDARD const + Settings textarea + CSRF + rate-limit + JS-escape + P-09 fuzzing | THEM-05 | `classes/adapter/theme/ThemeAjaxHandler.php`, modify `models/settings/fields.yaml` (+theme_custom_event_names textarea), modify `models/Settings.php` (beforeSave sanitize) | `tests/Feature/Adapter/Theme/ThemeAjaxHandlerAllowlistTest.php`, `tests/Feature/Adapter/Theme/ThemeAjaxHandlerRateLimitTest.php`, `tests/Feature/Adapter/Theme/ThemeAjaxHandlerFuzzingTest.php` (P-09 dataset matrix) |
| 03-08 | EventPixel component + PixelHead extension + onMarkFired AJAX | THEM-06, THEM-07 | `components/EventPixel.php`, `components/PixelHead.php`, modify `Plugin::registerComponents`, modify `phpunit.xml` (`<directory>./components</directory>`) | EventPixel onRun reads EventLog + emits inline fbq script, PixelHead emits collector blocks, onMarkFired writes channel='pixel' row + UNIQUE race-fence covers reload |

## State of the Art

| Old Approach (v1.x) | Current Approach (Phase 3) | When Changed | Impact |
|--------------|------------------|--------------|--------|
| 367-LOC `OrderStatusWatcher` with multi-concern dispatch + dedup + payload-build + status-cache | ≤70 LOC plain `Event::subscribe` Watcher with guard + dispatch only | Phase 3 (D-27 + fresh-code lock) | Watcher is single-responsibility; payload-build moved to PayloadBuilder (Phase 2); dedup moved to EventLogWriter UNIQUE race-fence (Phase 2). |
| Re-resolve adapter at PixelHead render → re-compute payload | EventLog.payload column written at dispatch; EventPixel reads frozen row via direct DB::table | Phase 3 (D-09) | Pixel-emit parity with CAPI-emit guaranteed even if subject mutates between dispatch and render. Snowplow atomic.events shape (D-10). |
| Hardcoded 'Logingrupa\OrdersShopaholic\Models\Order' subject_type FQN in EventLog rows | Opaque alias `'shopaholic.order'` | Phase 2 ADAP-01 + Phase 3 SHOP-01 returns it | Stable across class renames + multi-vendor installs. |
| `composer require lovata/shopaholic-plugin` hard dep | `suggest:` + `PluginManager::exists('Lovata.OrdersShopaholic')` gate | Phase 1 TOOL-01 + Phase 3 SHOP-04 | Plugin works on Lovata-free OctoberCMS install; Theme adapter ships unconditionally. |
| `class_exists()` check at boot | `PluginManager::instance()->exists($id)` | Phase 3 SHOP-04 (P-11 prevention) | True only when plugin is FOUND + ENABLED (not just autoloadable). |

**Deprecated/outdated:**
- v1.x `MetapixelTestCase::bootOrdersTable` coupling — already split in Phase 1 (`ShopaholicAdapterTestCase` provisions hermetic tables).
- v1.x `PayloadBuilder::buildPurchaseEventPayload(Order, ...)` — replaced Phase 2 by generic `buildEventPayload(string $sEventName, ...)` (D-22).
- v1.x `SiteResolver::forOrder(Order)` — replaced Phase 2 by `SiteResolver::forSubject(object, EventSubjectAdapter)`.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | The `this.metapixel.pushEvent($arEvent)` Twig dot-notation syntax can be implemented by setting a dynamic property on `Cms\Classes\Controller`'s `vars['this']` instance during `page.beforeRenderPage`. | Pattern 2 (registerMarkupTags) | If the `this` Twig object doesn't accept arbitrary dynamic-property attachment, plan 03-06 must fall back to a bare Twig function `{% do metapixel_push_event({...}) %}` — operator-visible API differs slightly from the ROADMAP example. Recommendation: verify in plan 03-06 task 1 via a spike — if `Controller::extend(...) + bindEvent('page.beforeRenderPage')` cleanly mounts `this.metapixel` as a Twig-accessible attribute, ship as-is; otherwise pivot to the bare function. Either shape satisfies THEM-04 because both let operators push events from Twig without writing PHP. |
| A2 | `Illuminate\Cache\RateLimiter::tooManyAttempts($sKey, $iMax)` works against the default cache driver (`array` in tests, `file` in dev, redis/memcached in prod). | Pattern 4 (ThemeAjaxHandler) | If the host operator configures `CACHE_DRIVER=null`, rate-limiting silently no-ops. Mitigation: plan 03-07 test runs against the test array driver; production deployment doc should call out `CACHE_DRIVER` requirement. Confidence is HIGH that the host's `file` driver works fine (Phase 1 + Phase 2 tests already use it). |
| A3 | The `Settings::beforeSave()` sanitization hook fires on every save (including admin Settings page POST). | THEM-05 / D-12 sanitization at SAVE | If CommonSettings (Lovata.Toolbox parent) overrides save semantics in a way that skips child `beforeSave`, the sanitization is bypassed. Verify in plan 03-07 task 1 — assert that `Settings::create([...invalid name list...])` calls beforeSave and drops bad entries. |
| A4 | `Lovata\OrdersShopaholic\Models\CartPosition::$item` MorphTo resolves to a `Lovata\Shopaholic\Models\Offer` instance with `->product->id` + `->id` accessible. | Pattern 1 + Pitfall 1 (CartPosition) | Verified by reading the model — the MorphTo signature accepts any morphable item_type but Phase 3 first-party scope is Offer only (`Lovata\Shopaholic\Models\Offer`). If a third party introduces non-Offer cart items (gift cards), the ValueResolver returns null content_ids + skips dispatch gracefully (Tiger-Style early return). This is a documented limitation of first-party Shopaholic adapter — third party writes their own adapter per D-13. |
| A5 | The Larajax handler `Metapixel::onFireEvent` works in the operator's theme even when the plugin ships ZERO routes (D-29 ZERO-routes lock). | Pattern 4 / D-29 | October's `runAjaxHandler` walks page, layout, components, and the `cms.ajax.beforeRunHandler` event. The event-listener path short-circuits ALL handler resolution — works regardless of whether a route is registered. Verified in source. |

**Risk-reducing recommendation for A1:** Plan 03-06 Task 1 ships a 5-line spike that mounts `this.metapixel` and verifies via a one-line Twig template `{{ this.metapixel.flush()|length }}`. If green, ship the dot-notation; if red, pivot to bare function.

## Open Questions

> Zero plan-blocking unknowns. All 5 open questions are spike-resolvable inside Plan 03-XX without changing the plan layout.

1. **Twig `this.metapixel.pushEvent($arEvent)` mount mechanism (resolved via A1 spike — Plan 03-06).** Bare function fallback exists; either shape closes THEM-04.
2. **Whether Settings::beforeSave on Lovata.Toolbox CommonSettings parent works as Eloquent-standard (A3).** Resolved by Plan 03-07 Task 1 unit test.
3. **CartPosition MorphTo non-Offer item handling (A4).** Documented limitation — first-party scope is Offer; third parties write own adapter per D-13.
4. **PHPStan signature variance on `registerSchedule(Schedule $obSchedule)` (Pitfall 7).** Resolved by Plan 03-01 Task 1 phpstan run — both forms acceptable.
5. **EventLog.payload read shape — DB::table raw string vs Eloquent $jsonable (Pitfall 8).** Phase 3 picks DB::table + explicit json_decode (matches D-09 spirit). If the planner discovers tests fail on the raw path, switch to EventLog model with `$jsonable = ['payload']` — both shapes work.

## Environment Availability

Phase 3 adds no external dependencies. All consumed packages are vetted in earlier phases and verified present on the host.

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP | All Phase 3 code | ✓ | 8.4.18 (prod) / 8.3-aware (dev compat per phpstan.neon `phpVersion: 80300`) | — |
| `october/system` | PluginBase + AJAX framework + Schedule + PluginManager + registerMarkupTags | ✓ | ^4.0 | — |
| `laravel/framework` | RateLimiter + Schedule + DB + Event + Log facades | ✓ | ^12 | — |
| `lovata/toolbox-plugin` | CommonSettings parent for Settings model | ✓ | ^2.2 | — |
| `lovata/ordersshopaholic-plugin` | Order + CartPosition + Status models (require-dev only — production gated by `PluginManager::exists`) | ✓ (dev) | ^1.33 | — |
| `lovata/shopaholic-plugin` | Offer model accessed via CartPosition MorphTo (require-dev only) | ✓ (dev) | ^1.32 | — |
| `guzzlehttp/guzzle` | MetaClient HTTP transport (Phase 2 — Phase 3 doesn't touch) | ✓ | ^7.8 | — |
| `ramsey/uuid` | UUIDv4 event_id generation in Watchers | ✓ (transitive via Laravel) | ^4 | — |
| `pestphp/pest` | Test runner + dataset() fuzzing | ✓ (dev) | ^4.1 | — |
| SQLite (in-memory) | Test DB | ✓ | bundled via php-sqlite3 | — |

**Missing dependencies with no fallback:** none.
**Missing dependencies with fallback:** none.

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | Pest 4 (`pestphp/pest ^4.1`) on PHPUnit 12 |
| Config file | `phpunit.xml` (root of plugin) |
| Quick run command | `cd plugins/logingrupa/metapixel && /home/forge/nailscosmetics.lv/vendor/bin/pest --compact` |
| Full suite command | `cd plugins/logingrupa/metapixel && /home/forge/nailscosmetics.lv/vendor/bin/pest --coverage --min=90` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| SHOP-01 | ShopaholicOrderAdapter satisfies EventSubjectAdapter contract | contract | `pest tests/Contract/Adapter/Shopaholic/ShopaholicOrderAdapterContractTest.php --compact` | ❌ Plan 03-02 |
| SHOP-01 | getSubjectType returns `'shopaholic.order'` | unit | (covered by contract test invariant 01) | ❌ Plan 03-02 |
| SHOP-01 | getSiteId reads Order.site_id only | unit | `pest tests/Unit/Adapter/Shopaholic/ShopaholicOrderAdapterTest.php::test_get_site_id_reads_order_column --compact` | ❌ Plan 03-02 |
| SHOP-02 | ValueResolver content_ids = `SKU-{product_id}[-{offer_id}]` matching Catalog feed | unit | `pest tests/Unit/Adapter/Shopaholic/ShopaholicOrderValueResolverTest.php --compact` | ❌ Plan 03-02 |
| SHOP-02 | Currency fallback chain (relation → field → Settings default → throw) | unit | `pest tests/Unit/Adapter/Shopaholic/ShopaholicOrderValueResolverTest.php::test_currency_fallback --compact` | ❌ Plan 03-02 |
| SHOP-03 | OrderStatusWatcher dispatches Purchase on paid-status flip | unit | `pest tests/Unit/Event/Adapter/Shopaholic/OrderStatusWatcherTest.php --compact` | ❌ Plan 03-02 |
| SHOP-03 | OrderStatusWatcher ignores unrelated field changes (Pitfall 2) | unit | `pest tests/Unit/Event/Adapter/Shopaholic/OrderStatusWatcherTest.php::test_unrelated_field_no_dispatch --compact` | ❌ Plan 03-02 |
| SHOP-04 | Plugin::boot conditional registration via PluginManager::exists | feature | `pest tests/Feature/Plugin/ShopaholicConditionalRegistrationTest.php --compact` | ❌ Plan 03-02 |
| SHOP-04 | composer-dependency-analyser zero violations | smoke | `composer deps` | ✓ (config in place; Phase 3 expands) |
| SHOP-05 | End-to-end Purchase flow + dedup | integration | `pest tests/Feature/Adapter/Shopaholic/PurchaseFlowIntegrationTest.php --compact` | ❌ Plan 03-04 |
| THEM-01 | ThemeActionEvent value-object construction + iSyntheticId positive | unit | `pest tests/Unit/Adapter/Theme/ThemeActionEventTest.php --compact` | ❌ Plan 03-05 |
| THEM-02 | ThemeActionAdapter getSiteId payload-first + Site::getCurrent fallback | contract | `pest tests/Contract/Adapter/Theme/ThemeActionAdapterContractTest.php --compact` | ❌ Plan 03-05 |
| THEM-03 | ThemeEventCollector push + flush + reset | unit | `pest tests/Unit/Adapter/Theme/ThemeEventCollectorTest.php --compact` | ❌ Plan 03-06 |
| THEM-04 | Twig pushEvent integration | feature | `pest tests/Feature/Adapter/Theme/TwigPushEventTest.php --compact` | ❌ Plan 03-06 |
| THEM-05 | ThemeAjaxHandler allowlist (META_STANDARD + Settings list) | feature | `pest tests/Feature/Adapter/Theme/ThemeAjaxHandlerAllowlistTest.php --compact` | ❌ Plan 03-07 |
| THEM-05 | ThemeAjaxHandler rate-limit | feature | `pest tests/Feature/Adapter/Theme/ThemeAjaxHandlerRateLimitTest.php --compact` | ❌ Plan 03-07 |
| THEM-05 | ThemeAjaxHandler P-09 fuzzing matrix (≥14 inputs) | feature | `pest tests/Feature/Adapter/Theme/ThemeAjaxHandlerFuzzingTest.php --compact` | ❌ Plan 03-07 |
| THEM-06 | EventPixel onRun reads EventLog + emits inline fbq | feature | `pest tests/Feature/Components/EventPixelTest.php --compact` | ❌ Plan 03-08 |
| THEM-06 | EventPixel onMarkFired AJAX writes channel='pixel' + race-fence | feature | `pest tests/Feature/Components/EventPixelMarkFiredTest.php --compact` | ❌ Plan 03-08 |
| THEM-07 | PixelHead reads ThemeEventCollector + emits per-event blocks | feature | `pest tests/Feature/Components/PixelHeadTest.php --compact` | ❌ Plan 03-08 |
| D-06 / D-07 | EventLog payload column migration up + down + EventLogWriter sig change | feature | `pest tests/Feature/Migrations/AddPayloadColumnTest.php --compact` | ❌ Plan 03-01 |
| D-08 | PurgeEventLog deletes rows > 7 days; preserves newer | feature | `pest tests/Feature/Console/PurgeEventLogTest.php --compact` | ❌ Plan 03-01 |

### Sampling Rate

- **Per task commit:** `pest --compact tests/Path/To/SpecificTest.php` — sub-3-second feedback per task.
- **Per wave merge:** `pest --compact` (full suite) — green required before next plan starts.
- **Phase gate:** `composer qa` end-to-end (pint-test → phpstan analyse → phpmd → pest --coverage --min=90) before `/gsd:verify-phase`. Coverage gate must hit 90%+ on Run A (full-Lovata) and adapter tests excluded on Run B (`pest --exclude-group=adapter`).

### Wave 0 Gaps

- [ ] `tests/Contract/Adapter/Shopaholic/ShopaholicOrderAdapterContractTest.php` — covers SHOP-01 (Plan 03-02)
- [ ] `tests/Contract/Adapter/Shopaholic/ShopaholicCartPositionAdapterContractTest.php` — covers SHOP-01 CartPosition (Plan 03-03)
- [ ] `tests/Contract/Adapter/Theme/ThemeActionAdapterContractTest.php` — covers THEM-02 (Plan 03-05)
- [ ] `tests/Unit/Adapter/Shopaholic/ShopaholicOrderValueResolverTest.php` — covers SHOP-02 (Plan 03-02)
- [ ] `tests/Unit/Adapter/Shopaholic/ShopaholicCartPositionValueResolverTest.php` — covers SHOP-02 CartPosition (Plan 03-03)
- [ ] `tests/Unit/Event/Adapter/Shopaholic/OrderStatusWatcherTest.php` — covers SHOP-03 (Plan 03-02)
- [ ] `tests/Unit/Event/Adapter/Shopaholic/CartPositionWatcherTest.php` — covers SHOP-03 CartPosition (Plan 03-03)
- [ ] `tests/Feature/Plugin/ShopaholicConditionalRegistrationTest.php` — covers SHOP-04 (Plan 03-02)
- [ ] `tests/Feature/Adapter/Shopaholic/PurchaseFlowIntegrationTest.php` — covers SHOP-05 (Plan 03-04)
- [ ] `tests/Unit/Adapter/Theme/ThemeActionEventTest.php` — covers THEM-01 (Plan 03-05)
- [ ] `tests/Unit/Adapter/Theme/ThemeEventCollectorTest.php` — covers THEM-03 (Plan 03-06)
- [ ] `tests/Feature/Adapter/Theme/TwigPushEventTest.php` — covers THEM-04 (Plan 03-06)
- [ ] `tests/Feature/Adapter/Theme/ThemeAjaxHandlerAllowlistTest.php` — covers THEM-05 allowlist (Plan 03-07)
- [ ] `tests/Feature/Adapter/Theme/ThemeAjaxHandlerRateLimitTest.php` — covers THEM-05 rate-limit (Plan 03-07)
- [ ] `tests/Feature/Adapter/Theme/ThemeAjaxHandlerFuzzingTest.php` — covers THEM-05 P-09 fuzzing (Plan 03-07)
- [ ] `tests/Feature/Components/EventPixelTest.php` — covers THEM-06 (Plan 03-08)
- [ ] `tests/Feature/Components/EventPixelMarkFiredTest.php` — covers THEM-06 onMarkFired (Plan 03-08)
- [ ] `tests/Feature/Components/PixelHeadTest.php` — covers THEM-07 (Plan 03-08)
- [ ] `tests/Feature/Migrations/AddPayloadColumnTest.php` — covers D-06 / D-07 (Plan 03-01)
- [ ] `tests/Feature/Console/PurgeEventLogTest.php` — covers D-08 (Plan 03-01)
- [ ] phpunit.xml gains `<directory>./components</directory>` + `<directory>./console</directory>` to `<source><include>` (Plan 03-08 + Plan 03-01)
- [ ] phpstan.neon adds `classes/event/adapter/shopaholic` to `disallowIn` list (Plan 03-02)
- [ ] composer-dependency-analyser.php adds `classes/event/adapter/shopaholic` to the Lovata-import whitelist (Plan 03-02)

*Framework install:* Pest 4 + PHPUnit 12 + MetapixelTestCase + ShopaholicAdapterTestCase already in place from Phase 1.

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no | Theme events fire from anonymous browsing context; CAPI uses pixel_id + capi_access_token credentials (configured backend-side; never exposed). |
| V3 Session Management | yes | Rate-limit key composition uses `Session::getId()` (October session); ensures per-session rate window. |
| V4 Access Control | partial | ThemeAjaxHandler enforces allowlist (event_name) + rate-limit per IP+session. Operator-supplied event names sanitized at SAVE boundary. |
| V5 Input Validation | yes (CRITICAL) | All theme-side input (`event_name`, `value`, `currency`, `content_ids`, `action_key`) validated server-side. `event_name` matched against META_STANDARD const ∪ Settings list. `value` rejected for non-numeric. `content_ids` rejected for non-list-of-strings. Oversize / control-char / mixed-encoding inputs return 422 (P-09 fuzzing matrix). |
| V6 Cryptography | partial | event_id is UUIDv4 (cryptographically random, server-generated, server-direction-only); user_data fields hashed via sha256 in Phase 2 UserDataHasher; HTTPS to Meta Graph API per Phase 2 MetaClient. |
| V7 Error Handling & Logging | yes | Every Watcher / handler catch logs via `Log::warning` with structured `meta_pixel.*` context keys. Tiger-Style fail-fast at boundaries (Watcher catches PayloadBuilder failures to avoid cascade-breaking Order::save; SendCapiEvent owns retry/dead-letter). |
| V9 Communication Security | yes | MetaClient uses HTTPS-only Graph API base URL; `access_token` posted in JSON body, never URL (Phase 2 lock — prevents webserver-log leak). |
| V13 API & Web Service | yes | Larajax handler is a server-controlled boundary; `cms.ajax.beforeRunHandler` listener short-circuits before any component handler runs. No public REST surface. |
| V14 Configuration | partial | Settings-side sanitization on `theme_custom_event_names` textarea — alpha-num + underscore, ≤ 50 chars, bad entries flash admin warning + dropped (D-14). |

### Known Threat Patterns for {OctoberCMS + Laravel + Larajax}

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Reflected XSS in Larajax response fragment | T (Tampering) | `json_encode` with HEX flags + `e()` helper for HTML attributes; never echo raw client input back to the response (P-09). |
| CSRF on Larajax route | T | October's `Cms\Classes\AjaxFramework` middleware verifies `X-CSRF-TOKEN` before `cms.ajax.beforeRunHandler` fires (419 on miss). |
| Open-relay event spam | D (DoS) | Rate-limit per IP+session via `Illuminate\Cache\RateLimiter::tooManyAttempts` — 30 req/60s. |
| Arbitrary `event_name` to inflate Meta ads spend | T + I (Information Disclosure) | Server-side allowlist (META_STANDARD const + Settings list); 422 on miss. |
| SQL injection via `event_name` → EventLog row | T | `DB::table->insert([...])` uses parameterized inserts; phpstan-disallowed-calls bans `DB::raw`/`->whereRaw` in adapter code. |
| Race condition double-fire (pixel + capi on reload) | T | UNIQUE constraint on `(subject_type, subject_id, event_name, channel, site_id)` blocks second insert; `EventLogWriter::record` returns false on collision. |
| Cross-context site_id drift (P-01 anchor) | T | `getSiteId(object $obSubject): ?int` reads ONLY from subject; PHPStan disallowed-calls bans SiteManager/Request in adapter/queue/event dirs (with documented theme exception per D-16). |
| Hidden Lovata import in core (P-03 anchor) | T | composer-dependency-analyser config restricts `Lovata\OrdersShopaholic\*` imports to `classes/adapter/shopaholic/` + `classes/event/adapter/shopaholic/` only. |
| Class FQN string as subject_type (P-05 anchor) | T | Opaque alias enforced by `EventSubjectAdapter::getSubjectType` contract + invariant 01 of contract test base. |
| Boot-order autoload race (P-11 anchor) | T | `PluginManager::instance()->exists('Lovata.OrdersShopaholic')` gate in `Plugin::boot` — true only when plugin is FOUND + ENABLED. |

## Sources

### Primary (HIGH confidence)

- **modules/system/classes/PluginBase.php:71** — `registerSchedule($schedule)` signature.
- **modules/system/ServiceProvider.php** — `console.schedule` event listener forwarding `Schedule` to every plugin.
- **modules/system/classes/PluginManager.php:380** — `public function exists($id)` semantics.
- **modules/cms/classes/controller/HasAjaxRequests.php:297** — `cms.ajax.beforeRunHandler` event signature + short-circuit semantics.
- **vendor/laravel/framework/src/Illuminate/Cache/RateLimiter.php:127** — `tooManyAttempts($key, $maxAttempts)` + `hit($key, $decaySeconds)` API.
- **plugins/lovata/toolbox/Plugin.php:66** — `registerMarkupTags()` live shape (`['functions' => ['fnName' => closure]]`).
- **plugins/lovata/ordersshopaholic/models/Order.php** — site_id column @ line 39, fillable @ line 134 including `'site_id'` @ line 145; jsonable @ line 129.
- **plugins/lovata/ordersshopaholic/models/CartPosition.php** — MorphTo `item_id` + `item_type` (NOT direct `offer_id`); quantity column confirmed.
- **plugins/lovata/ordersshopaholic/models/Status.php** — model declares NO `lists()` method; dropdown source must build via `Status::orderBy('sort_order')->pluck('name', 'code')`.
- **plugins/logingrupa/facebookcatalogshopaholic/classes/helper/ExportCatalogFacebookHelper.php:356** — content_ids SKU format anchor: `'product_id' => 'SKU-' . $obOffer->product->id`; `'offer_id' => 'SKU-' . $obOffer->product->id . '-' . $obOffer->id`.
- **plugins/renatio/formbuilder/Plugin.php** — live `registerSchedule` example using `$schedule->command(...)->daily()`.
- **plugins/logingrupa/metapixel/classes/queue/SendCapiEvent.php** — Phase 2 queue job + 3 hook fire-sites.
- **plugins/logingrupa/metapixel/classes/helper/EventLogWriter.php** — Phase 2 race-fence writer; Phase 3 D-07 sig change adds trailing `array $arPayload`.
- **plugins/logingrupa/metapixel/classes/testing/EventSubjectAdapterContractTestCase.php** — Phase 2 abstract base for Phase 3 adapter contract tests.
- **plugins/logingrupa/metapixel/tests/Feature/Adapter/BackboneIntegrationTest.php** — template for SHOP-05 integration test (MockHandler + Middleware::history pattern).
- **plugins/logingrupa/metapixel/.planning/research/PITFALLS.md** §P-03, §P-05, §P-09, §P-11 — Phase 3 anchored pitfalls.

### Secondary (MEDIUM confidence)

- **themes/logingrupa-naisstore/partials/form/checkout-form/tracking/facebook-purchase-tracking.js:10** — live `jax.ajax('Cart::onGetPixelPurchaseData', {...})` shape.
- **vendor/larajax/larajax/README.md** — Larajax framework intro + `data-request` + `jax.ajax` shape.
- **Phase 2 STATE.md decisions block** — 30+ Phase 2 carry-forward locks consumed by Phase 3.

### Tertiary (LOW confidence — N/A for Phase 3)

None — all sources are in-tree or vendored framework code; nothing relies on training data alone.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — Phase 3 ships zero new packages; every consumed lib is verified in-tree.
- Architecture: HIGH — 29 locked decisions (D-01..D-29) constrain the architecture; CONTEXT.md drives the planning.
- Pitfalls: HIGH — every Phase 3 pitfall is verified by direct source inspection of OctoberCMS framework, Lovata models, or Phase 2 code.
- Twig dot-notation mount (Pattern 2 A1): MEDIUM — bare-function fallback provides safety net.

**Research date:** 2026-05-18
**Valid until:** 2026-06-17 (30 days — stable framework + locked decisions)

---

## RESEARCH COMPLETE

**Phase:** 3 — ShopaholicAdapter + ThemeActionAdapter parallel wave
**Confidence:** HIGH

### Key Findings

- All 29 CONTEXT.md decisions (D-01..D-29) are sound; planner refines D-18's 8-plan outline 1-for-1 without structural changes.
- October framework integration surface (registerMarkupTags / registerSchedule / cms.ajax.beforeRunHandler / PluginManager::exists / RateLimiter) verified by direct source inspection — every signature locked.
- content_ids SKU format anchor verified byte-for-byte at `plugins/logingrupa/facebookcatalogshopaholic/classes/helper/ExportCatalogFacebookHelper.php:356`. Single-offer: `SKU-{product_id}`; multi-offer: `SKU-{product_id}-{offer_id}`.
- CartPosition is MorphTo (`item_id` + `item_type`), NOT direct `offer_id` — the CONTEXT.md outline column shape was inaccurate. ValueResolver accesses Offer via `$obCartPosition->item`; null-guard required for non-Offer items.
- Status model lacks its own `lists()` — dropdown sourced via `Status::orderBy('sort_order')->pluck('name', 'code')` exposed through `Settings::getPaidStatusCodeOptions`.
- v1.x 367-LOC OrderStatusWatcher anti-pattern reference — Phase 3 ≤70 LOC plain `Event::subscribe` class beats Lovata.Toolbox ModelHandler inheritance (which forces unneeded `getModelClass()` + `getItemClass()` abstracts).
- Twig `this.metapixel.pushEvent($arEvent)` dot-notation mount is the ONLY residual uncertainty (A1 spike in Plan 03-06 Task 1) — bare function fallback `{% do metapixel_push_event({...}) %}` satisfies THEM-04 regardless.

### File Created

`/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/.planning/phases/03-shopaholicadapter-themeactionadapter-parallel-wave/03-RESEARCH.md`

### Confidence Assessment

| Area | Level | Reason |
|------|-------|--------|
| Standard Stack | HIGH | Zero new packages; in-tree verification of every lib. |
| Architecture | HIGH | 29 locked decisions from CONTEXT.md drive structure. |
| Pitfalls | HIGH | All 9 documented pitfalls verified by direct source inspection. |
| Twig mount (A1) | MEDIUM | Bare-function fallback provides safety net. |

### Open Questions

5 total — all spike-resolvable inside Plan 03-XX without changing the 8-plan outline.

### Plan-blocking unknowns: 0

### Ready for Planning

Research complete. Planner can now create the 8 PLAN files (03-01 through 03-08) following the D-18 outline. Per-plan task counts refine inside each PLAN — research imposes no structural constraint beyond the locked decisions.
