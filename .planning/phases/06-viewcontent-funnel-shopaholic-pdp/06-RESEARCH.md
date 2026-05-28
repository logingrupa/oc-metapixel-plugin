# Phase 6: ViewContent funnel — Shopaholic PDP + offer-switch — Research

**Researched:** 2026-05-28
**Domain:** OctoberCMS v4 lifecycle + Lovata Shopaholic PDP integration + browser/CAPI dedup
**Confidence:** HIGH (all locked decisions back-verified against on-disk source-of-truth files; line refs current as of 2026-05-28)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions (DO NOT re-derive)

- **D-1:** View grain = per-pageload AND per-offer-switch. Each switch = own ViewContent, own `event_id`, own offer SKU.
- **D-2:** Pixel emit = PixelHead refactored to flush at `cms.page.beforeRenderPage`. Single emit point. Page-tier components push to `ThemeEventCollector` after `onRun`.
- **D-3:** Trigger = `Event::fire('shopaholic.product.open', [$obElement])` at `plugins/lovata/shopaholic/components/ProductPage.php:71`. Native fail-safe for 404/inactive/site-mismatch.
- **D-4:** Ship target = v2.0. Blocks Phase 5 wave 6 (plans 05-08, 05-09, 05-12, 05-13, 05-14).
- **D-5:** `content_ids` on PDP = `['SKU-{product_id}-{offer_id}']`. Single-offer products = `['SKU-{product_id}']`. Matches catalog feed + CartPosition/Order resolver.
- **D-6:** Universal offer selector = `[name="offer_id"]`. Shopaholic canonical form-field name. JS = bare delegated listener with no extra closest()/dataset gate (per D-9).
- **D-7:** AJAX endpoint = hybrid extension of `Metapixel::onFireEvent`. JS posts `{name, subject_type, subject_id, offer_id, action_key}`. `subject_type` is alias-only (allowlist via `AdapterRegistry::resolveByAlias`). Unknown alias → 422. Same rate-limit + CSRF as today.
- **D-8:** Component = `[productPixel]` / `Logingrupa\Metapixel\Components\ProductPixel` (vendor-neutral). Owns `<script>` for initial render + offer-switch JS. Disabled when `PluginGuard::isDisabled()`.
- **D-9:** PDP scope gate dropped. JS = bare delegated listener (no closest()/dataset). Operator-introduced rogue selectors = operator-owned theme problem.
- **D-10:** Default-selected offer = `$obProduct->offer->where('active', true)->sortBy('sort_order')->first()`. Empty offer collection → bare `SKU-{product_id}` (no `offer_id`).

### Claude's Discretion (planner resolves)

- **action_key shape for offer-switch ViewContent:** `viewcontent:{product_id}:{offer_id}:{event_id}` for switches (offer_id distinguishes EventLog rows); `viewcontent:{product_id}:{event_id}` for the PDP default-offer render. Both per-request-unique via `{event_id}` suffix.
- **CHANGELOG entry:** PixelHead PHPDoc gets full timing-contract docblock; CHANGELOG ViewContent entry under `### Added` only — NO `### Breaking changes` callout (per Phase 5 D-22 fresh-v2.0.0 stance).
- **`AdapterRegistry::resolveByAlias()` design:** maintain `<alias, adapter-class>` index populated at `register()` time. O(1), ~5 LOC; `register()` signature stays `(string $sSubjectClass, string $sAdapterClass)` — alias index built by instantiating the adapter once and calling `getSubjectType($obSubject = null)` via a static fallback (see Section 5 below for concrete sketch).
- **`UnknownSubjectTypeException` placement:** under `classes/exception/` mirroring existing exception class layout.
- **JS asset packaging:** inline `<script>` via `$this->page['productPixelScript']` rendered by component's `default.htm` Twig partial. Matches PixelHead `pixelHeadBase` pattern. Operator-zero-config.
- **Component placement:** README walkthrough instructs operator to add `[productPixel]` to the same layout/page hosting Shopaholic `[ProductPage]`. Component is no-op when no ProductPageWatcher payload pushed (collector-empty = no script render).

### Deferred Ideas (OUT OF SCOPE)

- InitiateCheckout event (Cart → Checkout transition) — v2.1
- Search event (Filter component) — v2.1
- Per-currency conversion validation — v2.1
- AddToCart browser-pixel mirroring on offer add — review post-v2.0
- Mall plugin adapter — operator publishes themselves
</user_constraints>

<phase_requirements>
## Phase Requirements (to be assigned during plan-phase)

| ID | Description | Research Support |
|----|-------------|------------------|
| VIEW-01 | PixelHead flush moves from `onRun()` to `cms.page.beforeRenderPage` | Section 2 — lifecycle proof; Section 3 — collector ordering |
| VIEW-02 | ShopaholicProductAdapter implements EventSubjectAdapter for `Lovata\Shopaholic\Models\Product`; alias `'shopaholic.product'` | Section 4 — subject_type + site_id resolution |
| VIEW-03 | ShopaholicProductValueResolver resolves value/currency/content_ids per D-5 + D-10 | Section 5 — price + currency chain |
| VIEW-04 | ProductPageWatcher subscribes `shopaholic.product.open`; dispatches CAPI + pushes to ThemeEventCollector | Section 6 — watcher pattern |
| VIEW-05 | ProductPixel component renders initial fbq + offer-switch JS; disabled-state honors PluginGuard | Section 7 — component shape |
| VIEW-06 | Offer-switch JS (delegated `change` listener on `[name="offer_id"]`) posts to `Metapixel::onFireEvent` with `subject_type` | Section 8 — JS pattern |
| VIEW-07 | `AdapterRegistry::resolveByAlias(string): string` returns adapter class FQN or throws `UnknownSubjectTypeException` | Section 5 — registry design |
| VIEW-08 | `EventSubjectAdapter::loadSubject(int, array): ?object` contract added; ShopaholicProductAdapter hydrates via `Product::find($iId)` | Section 9 — hybrid AJAX path |
| VIEW-09 | `ThemeAjaxHandler::onBeforeRun` detects optional `subject_type`; routes payload build through resolved adapter | Section 9 — hybrid AJAX routing |
| VIEW-10 | Boot-time `PluginManager::exists('Lovata.Shopaholic')` gate per Plugin.php:75-80 pattern | Section 10 — boot wiring |
| VIEW-11 | Pest tests tagged `#[Group('adapter')]` for minimal-install CI exclusion | Section 11 — test strategy |
| DOCS-01* | README ViewContent walkthrough + CHANGELOG entry | Phase 5 wave-6 dependency (DOCS-01 already on backlog) |

*DOCS-01 is an existing v2.0 requirement; Phase 6 extends its scope to cover ViewContent.
</phase_requirements>

## Project Constraints (from CLAUDE.md)

From `/home/forge/nailscosmetics.lv/CLAUDE.md` (parent) and `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/CLAUDE.md` (plugin):

- **Hungarian notation** mandatory (`$ob`, `$ar`, `$i`, `$s`, `$b`, `$f`). PHPMD `ShortVariable min=4`.
- **October-property carve-out:** `$table`, `$fillable`, `$jsonable`, `$casts`, `$rules`, `$customMessages`, `$attributeNames`, `$hasOne/$hasMany/$belongsTo/$belongsToMany/$morphTo/...`, `$attachOne/$attachMany` stay Laravel-standard names. Local variables + methods stay Hungarian.
- **PHP 8.3 + 8.4 dual.** No 8.4-only syntax (no property hooks, asymmetric visibility, `array_find/_any/_all/_find_key`, `#[\Deprecated]`).
- **Tiger-Style fail-fast.** Throw at boundaries. `catch` only to log-and-rethrow OR dead-letter-persist. Every `catch` documents reason.
- **No `assert()`.** Production `zend.assertions=0` silently no-ops. Enforced by `spaze/phpstan-disallowed-calls`.
- **No `declare(strict_types=1)` enforcement.** Optional per file.
- **PHPStan level 10 + larastan + universalObjectCrates** must stay green. `phpVersion: 80300`.
- **No `@phpstan-ignore` comments project-wide.** Resolve via runtime guards (`is_string($mValue) ? $mValue : ''`) or private narrowing helpers (`MetaClient::decodeBody`).
- **PHPStan disallowed-calls deny-list** (`disallowIn` scope):
  - `classes/queue/*`, `classes/event/*`, `classes/adapter/shopaholic/*`, `classes/event/adapter/shopaholic/*`
  - Banned: `request()`, `Illuminate\Http\Request::*`, `System\Classes\SiteManager::*`, `October\Rain\Support\Facades\Site::*`
  - Allowlist exception: `classes/adapter/shopaholic/ShopaholicCartPositionAdapter.php` (D-15 fallback)
  - `Settings::get('pixel_id'|'capi_access_token')` banned outside `models/Settings.php` + `classes/helper/PluginGuard.php`
- **Composer dependency boundary** (`composer-dependency-analyser.php`):
  - `lovata/shopaholic-plugin`, `lovata/ordersshopaholic-plugin`, `lovata/buddies-plugin` are require-dev; import allowed ONLY in `classes/adapter/shopaholic/` + `classes/event/adapter/shopaholic/` (+ `Plugin.php` for AdapterRegistry registration).
- **Coverage gate ≥ 90 %** on full-Lovata CI matrix cell. Minimal-install cell excludes via `--exclude-group=adapter`. Tag new adapter tests with `#[PHPUnit\Framework\Attributes\Group('adapter')]` at class level.
- **`PluginGuard::isDisabled()` MUST gate every emission.** Plugin.php boot guards subscriber registration with `PluginManager::exists('Lovata.Shopaholic')` (current code uses `Lovata.OrdersShopaholic` — see Section 10 note).
- **Lowercase folder convention** under `plugins/logingrupa/metapixel/`. Namespaces PascalCase, folders lowercase (`classes/adapter/shopaholic/`, `tests/Feature/Adapter/Shopaholic/`).
- **PHPUnit 12 attribute-based discovery.** `#[DataProvider(...)]`, `#[Group(...)]` — no `@dataProvider` / `@group` annotations.

## Summary

Phase 6 closes the ViewContent funnel hole at offer-level grain by adding three production classes (ShopaholicProductAdapter + ShopaholicProductValueResolver + ProductPageWatcher), one component (ProductPixel), one PixelHead refactor (defer flush from `onRun()` to `cms.page.beforeRenderPage`), and one minor extension to `ThemeAjaxHandler::onBeforeRun` (optional `subject_type` field → `AdapterRegistry::resolveByAlias`). All locked decisions (D-1..D-10) are back-verified against on-disk source files. No 8.4-only syntax, no `assert()`, no `@phpstan-ignore` needed.

The single non-obvious risk surfaced during research: the existing `Plugin.php:83-89` already attaches a `cms.page.beforeRenderPage` listener (injecting the `ThemeEventCollector` into Twig's `this.config.metapixel`). Phase 6 adds a SECOND listener on the same event for PixelHead flush. Laravel dispatches listeners in registration order, so the PixelHead-flush listener fires AFTER the ThisVariable-injection listener — fine. Both are pure observers (no return value short-circuit).

The "Product has no `site_id` column" finding shifts ShopaholicProductAdapter::getSiteId() to the D-15 fallback pattern (current site context), mirroring `ShopaholicCartPositionAdapter`. PHPStan deny-list requires explicit allowlist entry for the new file.

**Primary recommendation:** Wave-1 ships PixelHead deferred-flush + its 4-test PixelHeadDeferredFlushTest. Wave-2 ships the adapter pair + watcher + ProductPixel component in parallel (no inter-task deps within the wave). Wave-3 ships the JS + ThemeAjaxHandler hybrid extension + integration test. Wave-4 ships CHANGELOG + README updates that unblock Phase 5 wave 6.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Detect PDP render → fire ViewContent | API/Backend (Plugin event subscriber) | — | `shopaholic.product.open` is a server-side event; trigger MUST live in PHP to honor 404 + smart-URL + site-match guards already done by `ProductPage::getElementObject` |
| Resolve content_ids / value / currency | API/Backend (ValueResolver) | — | Subject-type-specific computation; runs in queue worker after serialization, MUST be request-context-free |
| Resolve site_id | API/Backend (Adapter) | — | EventSubjectAdapter contract — MUST read from subject (D-15 fallback for Product since no `site_id` column) |
| Generate event_id (UUIDv4) | API/Backend (Watcher) | — | Server-authoritative per locked decision (event_id direction = server → frontend only) |
| Persist EventLog race-fence row | API/Backend (EventLogWriter inside SendCapiEvent::handle) | — | Idempotency anchor — DB UNIQUE constraint |
| POST to Graph API | API/Backend (queue worker, MetaClient) | — | Queued via `SendCapiEvent` to keep page-load sync-fast |
| Emit `fbq('track', 'ViewContent', …, {eventID})` initial render | Browser (ProductPixel `<script>` block) | Frontend SSR (component renders the script tag via Twig) | Browser-tier execution; server emits the tag with server-generated event_id |
| Offer-switch event listener | Browser (delegated `change` on `document`) | — | DOM event lives on the client by definition |
| Offer-switch AJAX call | Browser → API/Backend | — | Browser Larajax `jax.ajax('Metapixel::onFireEvent', …)` → ThemeAjaxHandler interceptor on backend |
| Hybrid endpoint payload routing | API/Backend (ThemeAjaxHandler → AdapterRegistry::resolveByAlias) | — | Allowlist gating + adapter routing on server only — JS sends alias string, server resolves to FQN |

## Standard Stack (verified against on-disk vendor)

### Core (existing, reused verbatim — no install)

| Library / Class | Version | Purpose | Why standard |
|---|---|---|---|
| `ramsey/uuid` | ^4.7 (via transitive `october/all ^4.0`) | UUIDv4 generation for `event_id` [VERIFIED: vendor + grep `Uuid::uuid4` in 6 plugin files] | Already in use across CartPositionWatcher / OrderStatusWatcher / PixelHead / ThemeAjaxHandler |
| `guzzlehttp/guzzle` | ^7.8 (`composer.json:23`) | MetaClient HTTP transport [VERIFIED: composer.json] | Plugin's pinned dep for Graph API POST |
| `lovata/shopaholic-plugin` | ^1.32 (require-dev) | `Lovata\Shopaholic\Models\Product` model [VERIFIED: plugins/lovata/shopaholic/models/Product.php] | The subject being tracked |
| `lovata/toolbox-plugin` | ^2.2 (require) | `MultisiteHelperTrait` mounted on Product [VERIFIED: plugins/lovata/toolbox/traits/models/MultisiteHelperTrait.php] | Provides `$obProduct->site` MorphToMany |

### Internal classes reused verbatim

| Class | Path | Phase 6 use |
|---|---|---|
| `PayloadBuilder` | `classes/meta/PayloadBuilder.php` | ProductPageWatcher + ThemeAjaxHandler call `buildEventPayload()` |
| `UserDataHasher` | `classes/meta/UserDataHasher.php` | Hashing user_data fields |
| `SendCapiEvent` queue job | `classes/queue/SendCapiEvent.php` | Dispatched from ProductPageWatcher + hybrid AJAX path |
| `EventLogWriter` | `classes/helper/EventLogWriter.php` | Race-fence UNIQUE INSERT inside SendCapiEvent::handle |
| `CapturesRequestUserData` trait | `classes/event/CapturesRequestUserData.php` | Mix into ProductPageWatcher (request-context capture from $_SERVER/$_COOKIE) |
| `Settings::lookupForSite($iSiteId)` | `models/Settings.php` | Per-site pixel_id + token resolution |
| `Settings.test_event_code` injection | `classes/queue/SendCapiEvent.php:120,269,273` | Already top-level + already in browser fbq |
| `PluginGuard::isDisabled()` | `classes/helper/PluginGuard.php` | Gate every emission |
| `ThemeEventCollector` | `classes/adapter/theme/ThemeEventCollector.php` | ProductPageWatcher pushes here; PixelHead flushes |
| `ShopaholicCartPositionAdapter` + ValueResolver | `classes/adapter/shopaholic/*` | Template for ShopaholicProductAdapter pair (copy shape + adapt) |
| `OrderStatusWatcher` | `classes/event/adapter/shopaholic/OrderStatusWatcher.php` | Subscriber boilerplate template |

### Version verification

`ramsey/uuid` present transitively via `october/all`. Verified via `grep -rn "Uuid::uuid4" classes/ components/` — 6 callsites already use this exact API. No fresh install needed.

`guzzlehttp/guzzle ^7.8` already declared explicit in `composer.json` per H-4 marketplace lock. No version bump for Phase 6.

`lovata/shopaholic-plugin ^1.32` already declared require-dev. Phase 6 only touches `Lovata\Shopaholic\Models\Product` (already imported in `Lovata\OrdersShopaholic\Models\CartPosition` relation chain — no NEW direct import outside the allowlisted adapter file).

## Package Legitimacy Audit

> **Not applicable to Phase 6 — no external packages installed.** All required vendor libraries (`ramsey/uuid`, `guzzlehttp/guzzle`, `lovata/shopaholic-plugin`, `lovata/toolbox-plugin`) are already present and locked in `composer.json` from prior phases. Phase 6 adds only first-party PHP + JS + Twig files. Skip slopcheck.

## Architecture Patterns

### 1. October CMS Page Lifecycle Reference (verified against modules/cms/classes/Controller.php)

[VERIFIED: `/home/forge/nailscosmetics.lv/modules/cms/classes/Controller.php` lines 196-435]

```
┌─────────────────────────────────────────────────────────────────────┐
│ HTTP request hits Controller::runPage($url)                         │
└─────────────────────────────────────────────────────────────────────┘
  │
  ├─ cms.page.beforeDisplay   (line 196)  — opportunity to early-return
  │
  ├─ initCustomObjects + initComponents  (lines 349-351)
  │  │
  │  └─ Components instantiated, NOT yet onRun()
  │
  ├─ layoutObj->onInit / pageObj->onInit  (lines 355-363)
  │
  ├─ cms.page.init           (line 382)   — opportunity to early-return
  │
  ├─ execAjaxHandlers / execPostbackHandler  (lines 387-393)
  │
  ├─ execPageCycle()         (line 397)
  │  │
  │  ├─ cms.page.start       (line 474)
  │  │
  │  ├─ layoutObj->onStart + layout->runComponents + onBeforePageStart  (lines 479-491)
  │  │  │
  │  │  └─ ALL LAYOUT COMPONENTS' onRun() FIRE HERE   ← PixelHead onRun runs in this branch
  │  │
  │  ├─ pageObj->onStart + page->runComponents + onEnd  (lines 494-500)
  │  │  │
  │  │  └─ ALL PAGE COMPONENTS' onRun() FIRE HERE     ← ProductPage onRun runs here
  │  │     │
  │  │     └─ Inside ProductPage::getElementObject() → Event::fire('shopaholic.product.open', [$obProduct])
  │  │        → ProductPageWatcher::handle($obProduct) fires synchronously
  │  │        → CapturesRequestUserData + PayloadBuilder + SendCapiEvent::dispatch
  │  │        → ThemeEventCollector->push([…])     ← Pushed AFTER PixelHead onRun completed
  │  │
  │  ├─ layoutObj->onEnd     (lines 506-510)
  │  │
  │  └─ cms.page.end         (line 530)
  │
  ├─ parseAllEnvironmentVars  (line 402)
  │
  ├─ cms.page.beforeRenderPage   (line 421)    ← Phase 6 PixelHead deferred-flush listener fires HERE
  │                                              (existing Plugin.php:83-89 ThisVariable listener
  │                                               ALSO fires here — same event, both pure observers)
  │
  ├─ renderPageContents()    (line 426)       ← Twig renders pages/{slug}.htm + partials including
  │                                              components/pixelhead/default.htm WITH the
  │                                              just-set $this->page['pixelHeadBlocks']
  │
  ├─ renderLayoutContents()  (line 430)
  │
  └─ cms.page.render         (HasRenderers.php:55)
```

**Key invariants (verified, not assumed):**

1. `cms.page.beforeRenderPage` fires AFTER `execPageCycle()` returns, which means EVERY component's `onRun()` (both layout-tier and page-tier) has completed. So a page-tier ProductPage's `onRun` (which triggers `shopaholic.product.open`) is guaranteed to have run before the listener fires.
2. The listener fires BEFORE `renderPageContents()`. `renderPageContents()` calls `$template->render($this->vars)` where `$this->vars` is the Twig context that components write into via `$this->page['...']`. So a listener that mutates `$component->page['x']` HAS its mutation visible to the subsequent Twig render — because `$component->page` is a shared ArrayAccess object backed by `$controller->vars`. [VERIFIED: modules/cms/classes/Controller.php:421-427 + modules/cms/classes/controller/HasRenderers.php:78-86]
3. Multiple listeners on the SAME `cms.page.beforeRenderPage` fire in registration order. The existing `Plugin.php:83-89` listener (ThisVariable.config.metapixel injection) was registered at `boot()`; the new PixelHead-flush listener will also be registered at `boot()` (or in component `init()` — see Section 3). Order does not matter for Phase 6 — both are pure observers; ThisVariable injection runs once per page; the flush listener reads the collector. No race.
4. 404 path: `ProductPage::getElementObject()` returns null when product is missing/inactive/site-mismatch. `Event::fire('shopaholic.product.open', [$obElement])` ONLY fires when `$obElement` is non-empty (verified Line 70-71: `if (!empty($obElement)) { Event::fire(...); }`). So the watcher CANNOT receive a null subject — native fail-safe per D-3.
5. `Event::fire('shopaholic.product.open', [$obElement])` is `$halt = false` (3rd arg omitted, default false). Halt semantics do NOT apply. Listener return values are ignored. Multiple listeners would all fire. [VERIFIED: Lovata source at ProductPage.php:71 — single-arg fire]

### 2. PixelHead Deferred-Flush Refactor

**Current state** (`components/PixelHead.php:57-61`):
```php
public function onRun(): void
{
    $this->emitBasePixel();
    $this->emitCollectedEvents();   // ← flushes collector immediately at component onRun
}
```

**Refactored shape** (research recommendation — planner finalizes):
```php
public function onRun(): void
{
    $this->emitBasePixel();          // ← base PageView stays in onRun (per-pageload, deterministic)
    $this->initializeCollectorBlocks();  // ← seed $this->page['pixelHeadBlocks'] = []
}

public function init(): void   // OR: subscribe inside Plugin::boot()
{
    Event::listen('cms.page.beforeRenderPage', function () {
        $this->emitCollectedEvents();
    });
}
```

**Implementation tradeoffs:**

| Option | Pro | Con |
|--------|-----|-----|
| (a) Listener inside component `init()` | Locality — component owns its own deferred behavior | `init()` fires for EVERY page using PixelHead; need idempotent listener-attach guard |
| (b) Listener registered in `Plugin::boot()` | Single attach point per request | Couples Plugin.php to PixelHead internals (already happens for ThisVariable.config.metapixel — pattern matches existing code) |

**Recommendation:** Option (b) — Plugin.php already attaches one `cms.page.beforeRenderPage` listener (line 83-89). Add the PixelHead flush listener immediately after it. Acceptable coupling; matches existing pattern. The listener closure invokes a static `PixelHead::flushDeferredFromController(CmsController $obController)` — keeps Plugin.php thin and makes the deferred-flush logic unit-testable without spinning a Controller.

**Idempotency:** Listener executes once per request (Laravel Dispatcher's `fireSystemEvent` does not deduplicate; but the listener is registered once at `boot()`, not per page). PixelHead component-instance access: the listener reads from the singleton `ThemeEventCollector` (already a container singleton), not from any component instance. So even if PixelHead is rendered on a layout shared by multiple pages or nested partials, the COLLECTOR flush is once-per-request — the second-render call on PixelHead simply renders the EMPTY `$this->page['pixelHeadBlocks']` because the collector was already drained.

**Base PageView stays in `onRun()`:** Per CONTEXT D-2 wording ("Preserve action_key shape `base:pageview:{site_id}:{event_id}`"). Base PageView is per-pageload and does NOT depend on the collector. Keeping it in `onRun` preserves the existing race-fence + test surface from Phase 5.

**404 / cached-response interaction:** `cms.page.beforeRenderPage` only fires when `renderPageContents()` is reached — which is the success path. 404 redirects short-circuit before line 421. Cached responses (CMS_ASSET_CACHE / CMS_DB_TEMPLATES) cache compiled Twig templates, NOT the rendered HTML — the event listener still fires on every request. [VERIFIED: October template-cache caches the parsed Twig source, not the rendered output]

### 3. `shopaholic.product.open` Firing Context

[VERIFIED: `plugins/lovata/shopaholic/components/ProductPage.php` lines 54-75]

```php
protected function getElementObject($sElementSlug)
{
    if (empty($sElementSlug)) { return null; }

    if ($this->isSlugTranslatable()) {
        $obElement = Product::active()->transWhere('slug', $sElementSlug)->first();
        if (!$this->checkTransSlug($obElement, $sElementSlug)) { $obElement = null; }
    } else {
        $obElement = Product::active()->getBySlug($sElementSlug)->first();
    }

    $obElement = $this->hasRelationWithSite($obElement) ? $obElement : null;
    if (!empty($obElement)) {
        Event::fire('shopaholic.product.open', [$obElement]);   // ← line 71
    }

    return $obElement;
}
```

**Verified invariants:**

1. `Product::active()` scope — inactive products are never reached.
2. `hasRelationWithSite($obElement)` filters by `Site::getSiteIdFromContext()` (via `MultisiteHelperTrait`). Cross-site products return null → no fire.
3. `getBySlug` / `transWhere` returns null for unknown slug → no fire.
4. Event fires INSIDE `getElementObject()`, which is called from `ElementPage::onRun()` via a smart-URL check chain. **Bottom line: the event NEVER fires with a null/inactive/cross-site `$obElement`.** Watcher has no need for defensive guards beyond `PluginGuard::isDisabled()` + `try/catch Throwable → log + skip`.
5. Smart-URL redirect (`bNeedSmartURLCheck = true`, line 30): `ProductPage` redirects to canonical slug when accessed via stale URL. The redirect is a separate response — `shopaholic.product.open` ONLY fires on the canonical render, not on the 301 leg. [VERIFIED via `ElementPage::onRun()` smart-URL check flow at plugins/lovata/toolbox/classes/component/ElementPage.php]
6. `Product::find($iId)` (used by `loadSubject` in hybrid AJAX path) does NOT enforce active or site scope. The hybrid AJAX path MUST add an explicit `where('active', 1)` and site-relation check inside `ShopaholicProductAdapter::loadSubject` — the convenience PK lookup CANNOT bypass the same guards `getElementObject()` enforces.

### 4. Currency + Price-Value Resolution Chain

[VERIFIED: `plugins/lovata/shopaholic/classes/item/OfferItem.php:212-224` + `plugins/lovata/shopaholic/classes/helper/CurrencyHelper.php:84-92`]

```
OfferItem::price_value (computed attribute)
  │
  ├─ getActivePriceType()  → PriceTypeHelper::instance()->getActivePriceTypeID()
  │  │
  │  ├─ null/empty  → reads raw price_list['' + 'price'] AKA $this->getAttribute('price_value')
  │  └─ non-null    → reads price_list[$iActivePriceType.'.price']
  │
  └─ CurrencyHelper::instance()->convert($fPrice, $this->getActiveCurrency())
     │
     └─ Returns float (already-rounded via PriceHelper::round)
```

**Return type:** `float`. Always a finite number; falls back to 0.0 when price_list lookup returns null (Lovata uses `array_get` with implicit null default).

**Currency code:**

```php
CurrencyHelper::instance()->getActiveCurrencyCode(): ?string
```

[VERIFIED: CurrencyHelper.php:84-92]

```php
public function getActiveCurrencyCode()
{
    $obCurrency = $this->getActive();
    if (empty($obCurrency)) { return null; }
    return $obCurrency->code;   // 3-letter ISO (EUR, NOK, LVL/EUR — operator-configured)
}
```

- Returns null when no Currency model is loaded (fresh install, no active currency).
- Returns the 3-letter ISO `code` field from `lovata_shopaholic_currencies.code`.
- Multi-site safe: per-request memoized inside CurrencyHelper singleton (`Singleton` trait); reads via UserStorage with CookieUserStorage default.
- ValueResolver fallback strategy: if `getActiveCurrencyCode()` returns null, fall back to `Settings::get('default_currency_code', '')` (mirrors `ShopaholicCartPositionValueResolver::resolveCurrency()` at lines 41-50). If still empty, ValueResolver throws (Tiger-Style fail-fast at the value-resolution boundary — caught upstream by ProductPageWatcher's try/catch).

**ProductItem vs OfferItem vs Offer model:** The active-offer object available off `$obProduct->offer->...->first()` is a `Lovata\Shopaholic\Models\Offer` ELOQUENT model (not an `OfferItem`). `Offer::price_value` reads the raw column. To get the PriceTypeHelper-resolved + currency-converted price, the resolver needs `OfferItem::make($obOffer->id, $obOffer)->price_value` (Item-layer getter). 

> **Planner decision:** Read raw `$obOffer->price_value` for v2.0 (matches what `ShopaholicCartPositionValueResolver` does via `$obOffer->price_value` — Offer model exposes it as a base attribute on `lovata_shopaholic_offers.price_value`). Skip PriceTypeHelper resolution since Phase 5 D-12 (per-currency conversion validation) is deferred to v2.1. This matches existing AddToCart/Purchase resolver behavior — keeps consistency across the funnel.

### 5. `AdapterRegistry::resolveByAlias()` Design

**Current registry shape** (`classes/adapter/AdapterRegistry.php`):
- `$arAdapterMap` is `array<class-string, class-string<EventSubjectAdapter>>` — subject class FQN → adapter class FQN.
- `register($sSubjectClass, $sAdapterClass)` instantiates nothing at register time.
- Resolution by class via `resolveFor($obSubject)` — direct hit + is_a() hierarchy walk.

**Phase 6 addition — alias index built at register-time:**

```php
final class AdapterRegistry
{
    /** @var array<string, class-string<EventSubjectAdapter>> */
    private array $arAdapterMap = [];

    /** @var array<string, class-string<EventSubjectAdapter>> */
    private array $arAliasMap = [];

    public function register(string $sSubjectClass, string $sAdapterClass): void
    {
        if (! is_subclass_of($sAdapterClass, EventSubjectAdapter::class)) {
            throw new InvalidArgumentException(
                "Adapter {$sAdapterClass} must implement ".EventSubjectAdapter::class,
            );
        }
        $this->arAdapterMap[$sSubjectClass] = $sAdapterClass;
        // Build the alias index at register-time. Instantiate the adapter to read its
        // opaque alias. EventSubjectAdapter::getSubjectType($obSubject) takes an
        // object — call with a dummy/null subject because shipping adapters return
        // a static alias string and ignore their argument (verified across
        // ShopaholicOrderAdapter, ShopaholicCartPositionAdapter, ThemeActionAdapter).
        /** @var EventSubjectAdapter $obAdapter */
        $obAdapter = App::make($sAdapterClass);
        $sAlias = $obAdapter->getSubjectType(new \stdClass);
        $this->arAliasMap[$sAlias] = $sAdapterClass;
    }

    /**
     * Resolve adapter class FQN by opaque subject_type alias.
     *
     * @throws UnknownSubjectTypeException
     * @return class-string<EventSubjectAdapter>
     */
    public function resolveByAlias(string $sAlias): string
    {
        if (! isset($this->arAliasMap[$sAlias])) {
            throw new UnknownSubjectTypeException(
                "No adapter registered for subject_type alias '{$sAlias}'",
            );
        }
        return $this->arAliasMap[$sAlias];
    }
}
```

**Tradeoffs:**

| Option | Pro | Con |
|--------|-----|-----|
| (a) Build alias index at `register()` time (chosen) | O(1) lookup; allowlist is byte-for-byte the registered set | `register()` instantiates the adapter once — minor; container-resolved + cached |
| (b) Reverse-lookup `arAdapterMap` per call | Lazy — no construction at register-time | O(N) per resolve; touches every adapter's `getSubjectType` per call |

**Why option (a) works with `getSubjectType(object $obSubject)` signature:** All four shipping adapters return a CONSTANT alias string and ignore the argument (verified at `ShopaholicOrderAdapter.php`, `ShopaholicCartPositionAdapter.php`, `ThemeActionAdapter.php`, and the upcoming `ShopaholicProductAdapter.php`). Passing `new \stdClass` is safe — none invoke `instanceof` or method calls on `$obSubject` inside `getSubjectType`. A third-party adapter that DID conditional-dispatch on `$obSubject` inside `getSubjectType` would violate the P-05 anchor (alias is the subject_type, not the class). The interface PHPDoc already enforces: *"Opaque alias identifying the subject vendor + entity kind. MUST be an alias such as 'shopaholic.order' — MUST NOT contain backslashes; MUST NOT be a class FQN."*

**Alternative design (deferred):** Add `EventSubjectAdapter::getSubjectTypeAlias(): string` as a parameterless static-equivalent method. Marketplace BC-breaking. NOT recommended for v2.0 — defer to v2.1 if a real misuse surfaces.

**`UnknownSubjectTypeException` placement:** `classes/exception/UnknownSubjectTypeException.php` extending the existing `MetaPixelException` abstract base (constructor `(string, int, ?Throwable, array $arContext)` per Phase 2 lock). HTTP status: caught by `ThemeAjaxHandler::onBeforeRun` → returns `JsonResponse(['error' => 'unknown subject_type'], 422)`.

### 6. ProductPageWatcher Pattern (mirror of CartPositionWatcher)

```php
final class ProductPageWatcher
{
    use CapturesRequestUserData;

    public function subscribe(Dispatcher $obDispatcher): void
    {
        $obDispatcher->listen('shopaholic.product.open', [$this, 'handle']);
    }

    public function handle(Product $obProduct): void
    {
        try {
            if (PluginGuard::isDisabled()) {
                return;
            }

            $obAdapter = new ShopaholicProductAdapter;
            $obResolver = new ShopaholicProductValueResolver;
            $obBuilder = new PayloadBuilder(new UserDataHasher);

            $sEventId = Uuid::uuid4()->toString();
            $iEventTime = time();

            $arPayload = $obBuilder->buildEventPayload(
                'ViewContent',
                $obAdapter,
                $obProduct,
                $obResolver,
                $sEventId,
                $iEventTime,
                [],
            );
            $arPayload = $this->injectRequestUserData($arPayload);

            // Push to collector for PixelHead deferred-flush to emit fbq browser-side
            App::make(ThemeEventCollector::class)->push([
                'name' => 'ViewContent',
                'action_key' => 'viewcontent:'.$obProduct->id.':'.$sEventId,
                'event_id' => $sEventId,
                'content_ids' => $obResolver->resolveContentIds($obProduct),
                'content_name' => is_string($obProduct->name) ? $obProduct->name : '',
                'content_type' => 'product',
                'value' => $obResolver->resolveValue($obProduct),
                'currency' => $obResolver->resolveCurrency($obProduct),
            ]);

            SendCapiEvent::dispatch('ViewContent', $arPayload, $obProduct, ShopaholicProductAdapter::class);
        } catch (Throwable $obException) {
            // Tiger-Style fail-safe: page render MUST NOT 500 on pixel failure.
            Log::warning('metapixel: ProductPageWatcher emission failed', [
                'meta_pixel.product_id' => $obProduct->id,
                'meta_pixel.exception' => get_class($obException),
                'meta_pixel.message' => $obException->getMessage(),
            ]);
        }
    }
}
```

**Notes:**
- Lives at `classes/event/adapter/shopaholic/ProductPageWatcher.php`. PHPStan deny-list applies (no `Request::*`, no `Site::*`) — uses `$_SERVER`/`$_COOKIE` via `CapturesRequestUserData`.
- The `ThemeEventCollector` push key `name => 'ViewContent'` triggers PixelHead to emit `<script>fbq("track", "ViewContent", {...})</script>`. The current collector flow does NOT include `eventID` in the script (see PixelHead.php:201-202). **Phase 6 must extend `emitCollectedEvents()` to include `eventID` when the pushed event carries `event_id`** — otherwise browser ViewContent fires without an event_id and Meta dedup breaks. This is in addition to the deferred-flush move.

**Alternative pathway:** Instead of pushing into ThemeEventCollector, ProductPageWatcher could write a Twig page var directly via the controller. CONTEXT D-8 specifies the ProductPixel component "reads pushed event from collector (or page var) → renders `<script>fbq('track','ViewContent',...,{eventID})</script>`" — collector approach is consistent with Phase 5 architecture and avoids parallel state stores. **Decision: use collector with extended `eventID` field passthrough.**

### 7. ProductPixel Component Shape

```php
final class ProductPixel extends ComponentBase
{
    public function componentDetails(): array
    {
        return ['name' => 'ProductPixel', 'description' => 'PDP-level Meta Pixel ViewContent + offer-switch trigger.'];
    }

    public function defineProperties(): array { return []; }

    public function onRun(): void
    {
        // No-op at onRun — ProductPageWatcher already pushed to collector during
        // ProductPage::getElementObject (a SIBLING component on the same page).
        // The fbq <script> for the initial ViewContent comes from PixelHead's
        // deferred-flush emission of the collector — NOT from this component.
        // ProductPixel's job is ONLY the offer-switch JS injector.
        $this->page['productPixelOfferSwitchJs'] = $this->buildOfferSwitchJs();
    }

    private function buildOfferSwitchJs(): ?string
    {
        if (PluginGuard::isDisabled()) {
            return null;
        }
        return <<<'JS'
<script>
(function () {
    var sToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    document.addEventListener('change', function (ev) {
        var el = ev.target;
        if (!el || el.name !== 'offer_id') return;
        var iProductId = parseInt(el.dataset.productId || (el.form && el.form.querySelector('[name="product_id"]')?.value) || '0', 10);
        var iOfferId   = parseInt(el.value || '0', 10);
        if (!iProductId || !iOfferId) return;
        jax.ajax('Metapixel::onFireEvent', { data: {
            name: 'ViewContent',
            subject_type: 'shopaholic.product',
            subject_id: iProductId,
            offer_id: iOfferId,
            action_key: 'viewcontent:' + iProductId + ':' + iOfferId
        }}).done(function (oResp) {
            if (oResp && oResp.script) {
                var oTmp = document.createElement('div');
                oTmp.innerHTML = oResp.script;
                document.body.appendChild(oTmp.firstChild);
            }
        });
    }, false);
})();
</script>
JS;
    }
}
```

**default.htm** (`components/productpixel/default.htm`):
```twig
{% if productPixelOfferSwitchJs %}
{{ productPixelOfferSwitchJs|raw }}
{% endif %}
```

**Open question for planner:** Where does the JS read `product_id` from? Three options surface:
- (a) Sibling `<input name="product_id">` hidden field in the offer form — Shopaholic theme convention; verify against ajax_product_card_detailed_offers.htm
- (b) `data-product-id` attribute on the `<input name="offer_id">` element
- (c) Page-var injection: `{{ productPixelProductId }}` rendered as `window.metapixelProductId = {{ id }}` in default.htm — server-authoritative, theme-agnostic

**Recommendation: option (c)** — ProductPageWatcher pushes `product_id` into a NEW collector field; ProductPixel default.htm renders `window.__metapixelProduct = { id: <id> }`. JS reads `window.__metapixelProduct.id` instead of digging through DOM. Cleanest + theme-agnostic + survives any markup variant. Add `meta-product-id` script-tag emission per D-9 ("no extra closest()/dataset gate").

### 8. Offer-Switch JS — Idempotency + Vanilla JS Conventions

**No-jQuery rule** (parent CLAUDE.md): use Larajax (`jax.ajax(...)` is the project canonical AJAX invocation — verified across 50+ theme partials, plugin EventPixel default.htm line 5).

**Idempotency:** The delegated listener attaches to `document` exactly once (the `<script>` block is rendered at most once per page via the `productPixelOfferSwitchJs` Twig guard). If the component appears in BOTH a layout and the page (e.g. operator copy-pastes both `[productPixel]` placements), Twig renders the script twice — and the document gets TWO change-listeners. Resolution: guard the JS with a global flag:

```javascript
if (window.__metapixelProductPixelInit) return;
window.__metapixelProductPixelInit = true;
```

**MutationObserver?** Not needed — delegated `change` listener on `document` covers any future-DOM-injected `[name="offer_id"]` because `change` bubbles. Save the LOC.

**`data-product-id`?** Already injected by ProductPixel default.htm via `window.__metapixelProduct.id` (per Section 7 option c). JS reads from there, NOT from DOM. Theme-agnostic.

**CSRF + October token:** Already covered by October's AjaxFramework — `jax.ajax('Metapixel::onFireEvent', ...)` automatically attaches X-CSRF-TOKEN header. ThemeAjaxHandler does NOT need to re-check.

### 9. Hybrid `ThemeAjaxHandler::onBeforeRun` Extension

**Current shape** (lines 63-117): allowlist check on `name`, rate-limit on IP+session, dispatch with `ThemeActionAdapter` + `ThemeActionValueResolver`. No notion of `subject_type`.

**Extension shape** (sketch — planner finalizes):
```php
public function onBeforeRun(Controller $obController, string $sHandler): mixed
{
    if ($sHandler !== self::HANDLER_NAME) return null;

    try {
        $arData = $this->normalizeStringKeys(Request::input('data', []));
        if ($arData === null) return new JsonResponse(['error' => 'invalid request shape'], 400);
        if (! $this->isAllowedEventName($arData['name'] ?? '')) return new JsonResponse(['error' => 'event_name not allowed'], 422);
        if ($this->isRateLimited()) return new JsonResponse(['error' => 'rate limit exceeded'], 429);

        $sEventId = Uuid::uuid4()->toString();
        $iEventTime = time();

        // NEW — branch on optional subject_type field
        $mSubjectType = $arData['subject_type'] ?? null;
        if (is_string($mSubjectType) && $mSubjectType !== '') {
            return $this->dispatchViaAdapter($arData, $sEventId, $iEventTime);
        }

        // Existing path — synthetic ThemeActionEvent unchanged
        return $this->dispatchViaThemeAction($arData, $sEventId, $iEventTime);
    } catch (Throwable $obException) {
        Log::warning('metapixel: ThemeAjaxHandler failed', [...]);
        return new JsonResponse(['error' => 'internal'], 500);
    }
}

private function dispatchViaAdapter(array $arData, string $sEventId, int $iEventTime): JsonResponse
{
    try {
        $sAdapterClass = App::make(AdapterRegistry::class)->resolveByAlias((string) $arData['subject_type']);
    } catch (UnknownSubjectTypeException $obException) {
        return new JsonResponse(['error' => 'unknown subject_type'], 422);
    }
    /** @var EventSubjectAdapter $obAdapter */
    $obAdapter = App::make($sAdapterClass);
    $mSubjectId = $arData['subject_id'] ?? 0;
    $iSubjectId = is_numeric($mSubjectId) ? (int) $mSubjectId : 0;
    if ($iSubjectId <= 0) {
        return new JsonResponse(['error' => 'invalid subject_id'], 422);
    }
    $arContext = is_array($arData['context'] ?? null) ? $arData['context'] : [];
    foreach (['offer_id'] as $sExtra) {
        if (isset($arData[$sExtra])) { $arContext[$sExtra] = $arData[$sExtra]; }
    }
    $obSubject = $obAdapter->loadSubject($iSubjectId, $arContext);
    if ($obSubject === null) {
        return new JsonResponse(['error' => 'subject not found'], 404);
    }
    $arPayload = (new PayloadBuilder(new UserDataHasher))->buildEventPayload(
        (string) $arData['name'],
        $obAdapter,
        $obSubject,
        $obAdapter->getValueResolver($obSubject),
        $sEventId,
        $iEventTime,
        [],
    );
    SendCapiEvent::dispatch((string) $arData['name'], $arPayload, $obSubject, $sAdapterClass);
    $sScript = sprintf(
        '<script>fbq("track", %s, {}, {eventID: %s});</script>',
        (string) json_encode((string) $arData['name'], JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS),
        (string) json_encode($sEventId, JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS),
    );
    return new JsonResponse(['event_id' => $sEventId, 'script' => $sScript]);
}
```

**Security analysis (subject_type alias-only):**
- JS sends `subject_type: 'shopaholic.product'` (alias string).
- Server calls `AdapterRegistry::resolveByAlias($sAlias)` which throws `UnknownSubjectTypeException` if alias is not in the registry's index.
- The registry's alias index is populated at boot-time ONLY from registered adapters — third parties register via `AdapterRegistry::register($sSubjectClass, $sAdapterClass)` in their `Plugin::boot()`. No runtime registration.
- Untrusted JS bounded to the registered set. No FQN strings ever flow from JS to PHP `new`.
- CSRF + rate-limit + event-name allowlist already cover the rest of the surface.

**`EventSubjectAdapter::loadSubject(int, array): ?object` contract addition:** New method on the interface. Mirrors the convenience PK lookup CartPositionWatcher already does implicitly (its `eloquent.*` listener receives the model). For watchers that subscribe to events (Product, Order, CartPosition), the subject is passed in by the event. For the hybrid AJAX path, the adapter MUST hydrate the subject from PK + context. **Default implementation** (trait or interface-default? PHP 8.3+ does not support trait defaults in interfaces — needs explicit implementation per adapter or an `AbstractEventSubjectAdapter` skeleton class with `loadSubject(): ?object { throw new BadMethodCallException; }`. Planner picks):
- ShopaholicProductAdapter: `Product::active()->whereHas('site', site_match)->find($iSubjectId)` — re-enforces the same guards `ProductPage::getElementObject` enforces.
- ShopaholicOrderAdapter: throws (no use case yet — defensive).
- ShopaholicCartPositionAdapter: throws (no use case yet — defensive).
- ThemeActionAdapter: `ThemeActionEvent::fromArray($arContext)` synthetic subject.

**Bigger interface change risk:** Adding a method to `EventSubjectAdapter` is technically a BC break. Mitigation: ship a parallel `interface SupportsHybridAjax extends EventSubjectAdapter { public function loadSubject(...): ?object; }` and check `$obAdapter instanceof SupportsHybridAjax` in `dispatchViaAdapter`. Adapters that don't implement it → 422 `subject_type not supported via AJAX path`. **Recommendation: separate sub-interface, NOT a method on the base interface.** Keeps Phase 2 contract test (`EventSubjectAdapterContractTestCase` 10 invariants) intact.

### 10. Plugin.php Boot Wiring

```php
public function boot(): void
{
    if ($this->isShopaholicEnabled()) {
        $obRegistry = App::make(AdapterRegistry::class);
        $obRegistry->register(Order::class, ShopaholicOrderAdapter::class);
        $obRegistry->register(CartPosition::class, ShopaholicCartPositionAdapter::class);
        $obRegistry->register(Product::class, ShopaholicProductAdapter::class);   // ← NEW
        Event::subscribe(OrderStatusWatcher::class);
        Event::subscribe(CartPositionWatcher::class);
        Event::subscribe(ProductPageWatcher::class);                              // ← NEW
    }
    // ... existing beforeRenderPage listener at line 83-89 ...
    Event::listen('cms.page.beforeRenderPage', function () {                      // ← NEW listener for PixelHead deferred flush
        PixelHead::flushDeferredFromContainer();
    });
    // ... existing ThemeActionAdapter register + ThemeAjaxHandler subscribe ...
}
```

**Note on `isShopaholicEnabled` plugin name:** Current code (`Plugin.php:144`) checks `Lovata.OrdersShopaholic`, NOT `Lovata.Shopaholic`. CONTEXT mentions "PluginManager::exists('Lovata.Shopaholic')" — research finding: the existing code uses `Lovata.OrdersShopaholic` as the gate because Order + CartPosition adapters need it. ShopaholicProductAdapter only needs `Lovata.Shopaholic` (the Product model). Two options:
- (a) Keep one boot guard `Lovata.OrdersShopaholic` — covers Phase 6 too because OrdersShopaholic requires Shopaholic transitively (composer dependency).
- (b) Split into two guards: `Lovata.Shopaholic` for Product, `Lovata.OrdersShopaholic` for Order + CartPosition. Pedantically correct; only matters if an operator installs Shopaholic but not OrdersShopaholic (theoretically possible per Shopaholic composer.json).

**Recommendation: option (a)** — minimal scope creep. If a real install surfaces with Shopaholic-only, refactor in a follow-up plan. v2.0 ships with the existing one-guard pattern.

**`ShopaholicProductAdapter::getSiteId()` — critical finding:**

`Lovata\Shopaholic\Models\Product` has NO direct `site_id` column. [VERIFIED: `plugins/lovata/shopaholic/models/Product.php` lines 170-196 — no `site_id` in `$fillable`; uses `belongsToMany site` via `lovata_shopaholic_product_site_relation` pivot table; mounts `MultisiteHelperTrait` from `Lovata\Toolbox\Traits\Models\MultisiteHelperTrait`.]

`$obProduct->site_list` returns `array<int>` of site IDs. `$obProduct->site` returns `Collection<SiteDefinition>`.

A multi-site product can be visible on 1..N sites. The "site_id for this event" is the CURRENT request's site context — same fallback as `ShopaholicCartPositionAdapter::getSiteId`. **Resolution:**

```php
public function getSiteId(object $obSubject): ?int
{
    $obProduct = $obSubject instanceof Product ? $obSubject : null;
    if ($obProduct === null) { return null; }

    // Path A: single-site product — pick the only site_id from $obProduct->site_list
    $arSiteList = $obProduct->site_list ?? [];
    if (is_array($arSiteList) && count($arSiteList) === 1) {
        $iOnlySiteId = (int) $arSiteList[0];
        return $iOnlySiteId > 0 ? $iOnlySiteId : null;
    }

    // Path B: multi-site product — fall back to current site context (D-15 exception)
    // ProductPage::hasRelationWithSite already verified $obSubject belongs to the current site.
    $mContextSiteId = Site::getSiteIdFromContext();
    return is_int($mContextSiteId) && $mContextSiteId > 0 ? $mContextSiteId : null;
}
```

**PHPStan deny-list impact:** This file lives at `classes/adapter/shopaholic/ShopaholicProductAdapter.php` — which is in `disallowIn: classes/adapter/shopaholic/*`. Calling `Site::getSiteIdFromContext()` triggers the disallowed-method-calls rule. Add to the `allowIn` exception list:

```neon
# phpstan.neon — extend existing rule
disallowedMethodCalls:
    -
        method: 'October\Rain\Support\Facades\Site::*'
        # ...
        allowIn:
            - 'classes/adapter/shopaholic/ShopaholicCartPositionAdapter.php'
            - 'classes/adapter/shopaholic/ShopaholicProductAdapter.php'  # ← NEW
```

Plus same allowlist for `SiteManager::*`, `Request::*`, `request()`. Document the new D-15 exception in PHPDoc on the adapter class (mirroring CartPositionAdapter's docblock pattern lines 16-24).

### 11. Test Strategy — Brief 11+4 Matrix → Pest Class-Based Tests

All new test classes carry `#[Group('adapter')]` at the class level so minimal-install CI cell excludes via `--exclude-group=adapter`. Test layout mirrors existing convention (`tests/Feature/Adapter/Shopaholic/`, `tests/Feature/Components/`, `tests/Feature/Adapter/Theme/`).

**Test files:**

| File | Brief matrix coverage | Lines (est) |
|---|---|---|
| `tests/Feature/Adapter/Shopaholic/ProductPageWatcherTest.php` | items 1-10 from brief (PluginGuard, missing Shopaholic, empty offer collection, multi-offer SKU, single-offer SKU, event_id dedup, user_data, test_event_code, EventLog race-fence) | ~250-300 |
| `tests/Feature/Adapter/Shopaholic/ShopaholicProductValueResolverTest.php` | SKU format (single vs multi-offer), price source, currency source, fallback paths | ~150 |
| `tests/Feature/Components/PixelHeadDeferredFlushTest.php` | brief items 1-4 for PixelHead refactor (base PageView at beforeRenderPage, late-push acceptance, action_key shape, test_event_code propagation) | ~180 |
| `tests/Feature/Components/ProductPixelTest.php` | browser-script render shape (correct fbq + eventID), disabled-state (PluginGuard), offer-switch JS attachment markers (substring assertions) | ~150 |
| `tests/Feature/Adapter/Theme/ThemeAjaxHandlerSubjectTypeTest.php` | brief item 11 (offer-switch AJAX): unknown alias → 422, valid alias → routes through registered adapter, allowlist bypass blocked, subject_id ≤ 0 → 422, subject not-found → 404 | ~200 |

**Test patterns proven in existing code:**
- `runComponent(new ComponentX)` reflection helper from `tests/Feature/Components/PixelHeadTest.php:126` — copy-paste pattern for ProductPixelTest.
- `Bus::fake()` + `Bus::assertDispatched(SendCapiEvent::class, fn (...) => ...)` for queue-job assertions (PixelHeadBasePixelTest:87-97).
- `Settings::clearInternalCache()` + explicit `Settings::set([...])` in setUp (THEM-allowlist test).
- `PluginGuard::reset()` in setUp + tearDown (PixelHeadBasePixelTest:36-44).
- `App::singleton(ThemeEventCollector::class)` + `App::forgetInstance(...)` in setUp/tearDown for clean collector state.
- Direct migration call: `(new CreateMetapixelEventLogTable)->up();` + `->down();` (PixelHeadBasePixelTest:34, 43).
- `Mockery::mock(Controller::class)` for ThemeAjaxHandler tests (ThemeAjaxHandlerAllowlistTest:57).
- `Request::shouldReceive('input')->with('data', [])->andReturn([...])` for AJAX payload injection.

**`#[DataProvider(...)]` for matrix cases:** Use static `provideOfferShapes` data provider for the single vs multi-offer SKU matrix in `ShopaholicProductValueResolverTest`. Mirrors `MetaClientTest`'s `#[DataProvider('provideTransientStatusCodes')]` pattern (Phase 2 lock).

**Coverage gate:** Each new file targets 100 % line coverage in isolation. Aggregate plugin coverage gate ≥ 90 % stays green if new code lands with full per-file coverage (current ratio 99.3 % — Quick Task 260518-999).

### 12. PixelHead PHPDoc Timing Contract (binding documentation)

Per Claude's-discretion D-discussion: PixelHead PHPDoc gains a class-level timing-contract docblock. Sketch:

```php
/**
 * Layout-level head-tag base pixel + ThemeEventCollector consumer.
 *
 * LIFECYCLE TIMING CONTRACT (locked v2.0):
 *  - onRun() runs during execPageCycle (cms.page.start → cms.page.end window).
 *    Emits base PageView synchronously — same as Phase 5.
 *  - emitCollectedEvents() flushes ThemeEventCollector at cms.page.beforeRenderPage,
 *    AFTER every page-tier component's onRun() has completed. This permits page-tier
 *    components (e.g. Shopaholic ProductPage firing shopaholic.product.open) to push
 *    to the collector during their own onRun and still be flushed in time.
 *  - The fbq() <script> blocks render via $this->page['pixelHeadBlocks'] inside
 *    components/pixelhead/default.htm, which Twig renders during
 *    renderPageContents() — immediately after beforeRenderPage fires. The page-var
 *    mutation in the listener is therefore visible to the Twig render.
 *
 * If you push to ThemeEventCollector AFTER cms.page.beforeRenderPage, the push is
 * silently dropped — emit point has passed. Push during component onRun() or earlier.
 */
```

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---|---|---|---|
| UUIDv4 generation | Custom `uniqid()` or `bin2hex(random_bytes(...))` | `Ramsey\Uuid\Uuid::uuid4()->toString()` | Already in vendor; 6 existing callsites |
| HTTP POST to Graph API | Raw `curl_exec` / `file_get_contents` | `MetaClient::sendForPixel` (existing) | Phase 2 contract — error classification, transient/permanent split, test_event_code injection |
| SKU computation | Inline string concat | `ShopaholicProductValueResolver::buildContentId` (new, mirrors CartPositionValueResolver lines 97-108) | Single source of truth; matches catalog feed format byte-for-byte |
| Site_id resolution | Read `Request::host` + map | `getSiteIdFromContext()` D-15 fallback (with phpstan allowlist) | Pattern locked in Phase 3 for CartPositionAdapter |
| EventLog race-fence | `select-then-insert` check | `EventLogWriter::record` → `DB::insertOrIgnore` (existing) | Atomic via DB UNIQUE; works across in-memory SQLite + MySQL InnoDB |
| User_data hashing | Inline `sha256(strtolower(trim(...)))` | `UserDataHasher::forSubject` (existing) | Null/empty handling, passthrough-vs-hashed key split |
| AJAX delegated event listener | Re-attach on every DOM mutation | Document-level delegated `change` + global init flag (Section 8) | Bubbles cover dynamic DOM; one-time attach via init flag |
| CSRF token attachment to AJAX | Manual `X-CSRF-TOKEN` header | `jax.ajax(...)` (Larajax) | October AjaxFramework handles it transparently |
| JSON encoding for `<script>` tags | `json_encode(...)` with default flags | `json_encode(..., JSON_HEX_TAG \| JSON_HEX_QUOT \| JSON_HEX_AMP \| JSON_HEX_APOS)` | PixelHead + EventPixel use this exact mask — prevents `</script>` breakout |
| Request-context user_data capture | Re-read `Request::ip()` / cookies inside watcher | `CapturesRequestUserData` trait (existing) | PHPStan deny-list compliance — superglobals only |

**Key insight:** Phase 6 is a thin layer over the existing v2.0 backbone. Custom code surface is bounded: 1 adapter + 1 resolver + 1 watcher + 1 component + 1 exception + 1 method on registry + 1 method on ThemeAjaxHandler + ~30 lines of vanilla JS. Everything else is reuse.

## Runtime State Inventory

> Phase 6 is NOT a rename / refactor / migration. It adds new tracking events to an existing system. Skip this section per researcher instructions.

## Common Pitfalls

### Pitfall 1: Twig page-var mutation in `beforeRenderPage` listener doesn't propagate to component partial render
**What goes wrong:** Listener sets `$component->page['pixelHeadBlocks'] = [...]` but Twig renders `components/pixelhead/default.htm` with stale empty `pixelHeadBlocks`.
**Why it happens:** Component's `$page` property is fresh each component instance; if the listener fetches the WRONG component instance (or instantiates a new one), the mutation lands on a detached `Page` ArrayAccess.
**How to avoid:** Don't try to mutate `$component->page` from a Plugin-level listener. Instead, have the listener flush the collector → store the result in the singleton `ThemeEventCollector` (or a sibling singleton `ThemeEventScripts`), and have `components/pixelhead/default.htm` READ from a Twig var that PixelHead's `onRun()` already populated WITH a reference to the singleton. Or: keep a `PixelHead::$arPendingBlocks` static slot that `default.htm` reads via a markup function. **Cleanest:** the listener calls a static `PixelHead::flushDeferred()` that returns the script blocks string, and stores it in a new singleton `PixelHeadDeferredFlushBuffer`; `default.htm` reads from that buffer via a markup helper. The PixelHead component instance is irrelevant — the buffer is the single source of truth.
**Warning signs:** PixelHeadDeferredFlushTest passes for the collector-push path but the rendered page has empty `<!-- Metapixel base pixel -->` block.

### Pitfall 2: Multiple PixelHead components on page → listener registered twice → double-flush
**What goes wrong:** Layout includes `[pixelHead]` AND a partial pulls in `[pixelHead]` separately. Two component instances. If the deferred-flush listener attaches inside `init()` (Section 2 option a), TWO listeners fire → collector flushed twice → second flush sees an empty collector AND emits empty blocks AGAIN. Idempotent in practice but redundant work + log noise.
**How to avoid:** Register the listener in `Plugin::boot()` (Section 2 option b), not in component `init()`. Single attach point.
**Warning signs:** Log emits two `metapixel: PixelHead base PageView emission failed` lines per page on error paths.

### Pitfall 3: Offer-switch JS posts `subject_id` that bypasses Shopaholic site / active filter
**What goes wrong:** Attacker MITMs the offer-switch POST and sends `subject_id: 99999` for a cross-site or inactive product. Server fires ViewContent with that product's data → pollutes Meta Ads analytics, possibly leaks product names/SKUs.
**How to avoid:** `ShopaholicProductAdapter::loadSubject($iId, $arContext)` MUST replicate `ProductPage::getElementObject`'s guards — `Product::active()->find($iId)` + `hasRelationWithSite` check. Return null on miss → ThemeAjaxHandler returns 404 / 422.
**Warning signs:** ThemeAjaxHandlerSubjectTypeTest item "subject not found" assertion (expected 404).

### Pitfall 4: `Event::fire('shopaholic.product.open', [$obElement])` fires recursively
**What goes wrong:** Inside `ProductPageWatcher::handle`, code accidentally calls `$obProduct->load('offer')` which triggers Lovata's ProductModelHandler `afterLoad` event that — under some test scenarios — refires `shopaholic.product.open`. Infinite loop.
**How to avoid:** Verified: Lovata's ProductModelHandler does NOT refire `shopaholic.product.open` (`grep -rn "shopaholic.product.open" plugins/lovata/` returns only ProductPage.php:71 firing site + zero subscriber declarations beyond the open-graph plugin's listener). Phase 6 is safe but document the invariant in PHPDoc on ProductPageWatcher.
**Warning signs:** Stack overflow / memory-limit-exceeded in tests with broad Eloquent eager-loading.

### Pitfall 5: PHPStan deny-list misses new adapter file → CI red
**What goes wrong:** ShopaholicProductAdapter calls `Site::getSiteIdFromContext()` per Section 10. `phpstan.neon` deny-list bans `October\Rain\Support\Facades\Site::*` in `classes/adapter/shopaholic/*`. CI fails.
**How to avoid:** Plan task: extend each of the 4 `disallowIn` rules in `phpstan.neon` (Site::*, SiteManager::*, Request::*, request()) to add `ShopaholicProductAdapter.php` to `allowIn`. Test in WAVE 2 via `composer qa`.
**Warning signs:** phpstan output: `Call to method getSiteIdFromContext() of class October\Rain\Support\Facades\Site is disallowed`.

### Pitfall 6: `Product::find($iId)` in `loadSubject` returns soft-deleted or trash-binned products
**What goes wrong:** Lovata uses `SoftDelete` trait on some models. `find($iId)` includes trashed rows. ViewContent fires for a trashed product → bad data.
**How to avoid:** Verify Product model uses `SoftDelete` (grep). If yes, use `Product::query()->where('active', 1)->find($iId)` — `active()` scope explicitly excludes inactive. If `SoftDelete` mounted, also `withoutTrashed()`.
**Status:** [VERIFIED: `plugins/lovata/shopaholic/models/Product.php` does not import `Lovata\Toolbox\Traits\Helpers\TraitSlug` — needs spot-check of full Product.php for `SoftDelete` trait import] → planner task: confirm.

### Pitfall 7: Currency null on fresh Shopaholic install
**What goes wrong:** `CurrencyHelper::instance()->getActiveCurrencyCode()` returns null when no active currency configured. ValueResolver throws → ProductPageWatcher logs + skips. Silent miss in production.
**How to avoid:** ValueResolver chains: try `getActiveCurrencyCode()` → fall back to `Settings::get('default_currency_code', '')` → throw only if both empty. Mirrors `ShopaholicCartPositionValueResolver::resolveCurrency` lines 41-50.
**Warning signs:** Log: `metapixel: ProductPageWatcher emission failed ... OrderHasNoCurrencyException`. Test case: empty currency table + empty Settings.default_currency_code.

### Pitfall 8: `cart-position-list-present-box.htm` has `[name="offer_id"]` outside PDP — fires spurious ViewContent
**What goes wrong:** Verified during research: `themes/logingrupa-naisstore/partials/product/cart-position-list/cart-position-list-present-box.htm:20` renders `<select name="offer_id">` in the CART, NOT the PDP. Per D-9, JS uses bare delegated listener (no closest()/dataset gate). When the cart-modal's bonus-box select changes, ProductPixel JS fires ViewContent for the bonus product.
**How to avoid:** D-9 says "Operator-introduced rogue `[name="offer_id"]` outside PDP = operator-owned theme problem." This bonus-box IS an operator-owned theme convention. **Three resolutions for planner:**
  - (a) Honor D-9 strictly — accept the spurious ViewContent. Document the cart-modal selector in README walkthrough as a known case.
  - (b) Soft-gate via the `window.__metapixelProduct` global: JS only fires when the global is set (ProductPixel rendered = PDP). Cart-modal bonus-box select on a non-PDP page → no fire. Add `if (!window.__metapixelProduct) return;` at top of the change-handler. Recommended.
  - (c) Operator-side: nuke the bonus-box selector from the theme. Out of plugin scope.
**Recommendation:** Option (b) — one extra line of JS, preserves D-9's "no closest()/dataset gate" because the gate is on a server-injected global, not on DOM proximity. Operator-prefix-safe; no theme rewrite needed.
**Warning signs:** Meta Events Manager shows ViewContent events with bonus-box offer SKUs (low-value SKUs the operator doesn't actually want to track).

### Pitfall 9: `subject_type` allowlist passes registered adapters but adapter lacks `loadSubject`
**What goes wrong:** Third-party adapter registered for alias `'mall.product'` but doesn't implement the new `loadSubject`-supporting subinterface. Hybrid AJAX path resolves alias → 500 on missing method.
**How to avoid:** Section 9 design — separate `SupportsHybridAjax` subinterface; check `$obAdapter instanceof SupportsHybridAjax` after resolve; 422 with `'subject_type does not support hybrid AJAX'` otherwise.
**Warning signs:** New Pest test `ThemeAjaxHandlerSubjectTypeTest::test_returns_422_when_adapter_lacks_hybrid_support` covers.

### Pitfall 10: 8.4-only syntax slips
**What goes wrong:** Habit of writing `array_find($arOffers, fn($o) => $o->active)` (PHP 8.4-only) instead of `(array_values(array_filter($arOffers, fn($o) => $o->active)))[0] ?? null`.
**How to avoid:** PHPStan disallowed-function-calls already bans `array_find()`, `array_find_key()`, `array_any()`, `array_all()` per `phpstan.neon` (verified). Also `Deprecated` attribute. No property hooks, no asymmetric visibility — these are not function calls but syntax, caught only by `phpVersion: 80300` setting. CI matrix includes a fresh-install 8.3 cell.
**Warning signs:** phpstan output: `Function array_find not found` (under phpVersion 80300).

### Pitfall 11: AdapterRegistry alias index built at register-time fails if adapter's constructor needs dependencies
**What goes wrong:** Section 5 design instantiates the adapter at `register()` time to read its alias. If the adapter constructor needs dependencies that aren't yet bound when `Plugin::boot()` runs, `App::make($sAdapterClass)` throws.
**How to avoid:** Verified: all four shipping adapters (ShopaholicOrderAdapter, ShopaholicCartPositionAdapter, ThemeActionAdapter, the future ShopaholicProductAdapter) have parameterless constructors. New adapters following the contract should keep parameterless constructors — document the convention in the interface PHPDoc.
**Warning signs:** `BindingResolutionException` at `Plugin::boot()` if a third-party adapter takes a constructor dependency that isn't yet bound. Acceptable risk — operator's plugin order issue, not core's.

## Code Examples

### Example 1: ShopaholicProductValueResolver (mirrors CartPosition resolver)

```php
namespace Logingrupa\Metapixel\Classes\Adapter\Shopaholic;

use Logingrupa\Metapixel\Classes\Adapter\ValueResolver;
use Logingrupa\Metapixel\Models\Settings;
use Lovata\Shopaholic\Classes\Helper\CurrencyHelper;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Product;
use October\Rain\Database\Model;

final class ShopaholicProductValueResolver implements ValueResolver
{
    /** @return list<string> */
    public function resolveContentIds(object $obSubject): array
    {
        $obProduct = $this->productOf($obSubject);
        if ($obProduct === null) { return []; }
        $obDefault = $this->defaultOffer($obProduct);
        if ($obDefault === null) { return ['SKU-'.$this->intAttr($obProduct, 'id')]; }
        $iOfferCount = $obProduct->offer->count();
        return $iOfferCount > 1
            ? ['SKU-'.$this->intAttr($obProduct, 'id').'-'.$this->intAttr($obDefault, 'id')]
            : ['SKU-'.$this->intAttr($obProduct, 'id')];
    }

    public function resolveValue(object $obSubject): float
    {
        $obDefault = $this->defaultOffer($this->productOf($obSubject));
        return $obDefault !== null ? $this->floatAttr($obDefault, 'price_value') : 0.0;
    }

    public function resolveCurrency(object $obSubject): string
    {
        $sCode = CurrencyHelper::instance()->getActiveCurrencyCode();
        if (is_string($sCode) && $sCode !== '') { return $sCode; }
        $mDefault = Settings::get('default_currency_code', '');
        if (is_string($mDefault) && $mDefault !== '') { return $mDefault; }
        throw new \RuntimeException('No active currency; configure Settings.default_currency_code');
    }

    /** @return list<array{id: string, quantity: int, item_price: float}> */
    public function resolveContents(object $obSubject): array
    {
        $arIds = $this->resolveContentIds($obSubject);
        if ($arIds === []) { return []; }
        return [['id' => $arIds[0], 'quantity' => 1, 'item_price' => $this->resolveValue($obSubject)]];
    }

    public function resolveNumItems(object $obSubject): int { return 1; }

    private function productOf(object $obSubject): ?Product
    {
        return $obSubject instanceof Product ? $obSubject : null;
    }

    private function defaultOffer(?Product $obProduct): ?Offer
    {
        if ($obProduct === null) { return null; }
        $obCollection = $obProduct->offer;
        if ($obCollection === null || $obCollection->isEmpty()) { return null; }
        // D-10: first active by sort_order asc — mirrors Shopaholic native default-offer.
        return $obCollection
            ->where('active', true)
            ->sortBy('sort_order')
            ->first();
    }

    private function intAttr(Model $obModel, string $sAttr): int
    {
        $mValue = $obModel->getAttribute($sAttr);
        return is_numeric($mValue) ? (int) $mValue : 0;
    }

    private function floatAttr(Model $obModel, string $sAttr): float
    {
        $mValue = $obModel->getAttribute($sAttr);
        return is_numeric($mValue) ? (float) $mValue : 0.0;
    }
}
```

### Example 2: Offer-switch JS shape

```javascript
// Embedded inside components/productpixel/default.htm
(function () {
    if (window.__metapixelProductPixelInit) return;
    window.__metapixelProductPixelInit = true;

    document.addEventListener('change', function (ev) {
        // Pitfall 8 — soft-gate via server-injected global (Section 8 + Pitfall 8)
        if (!window.__metapixelProduct || !window.__metapixelProduct.id) return;
        var el = ev.target;
        if (!el || el.name !== 'offer_id') return;

        var iProductId = parseInt(window.__metapixelProduct.id, 10);
        var iOfferId   = parseInt(el.value || '0', 10);
        if (!iProductId || !iOfferId) return;

        jax.ajax('Metapixel::onFireEvent', { data: {
            name: 'ViewContent',
            subject_type: 'shopaholic.product',
            subject_id: iProductId,
            offer_id: iOfferId,
            action_key: 'viewcontent:' + iProductId + ':' + iOfferId
        }}).done(function (oResp) {
            if (oResp && oResp.script) {
                var oTmp = document.createElement('div');
                oTmp.innerHTML = oResp.script;
                if (oTmp.firstChild) document.body.appendChild(oTmp.firstChild);
            }
        });
    }, false);
})();
```

### Example 3: ProductPageWatcher subscribe shape

```php
namespace Logingrupa\Metapixel\Classes\Event\Adapter\Shopaholic;

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicProductAdapter;
use Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicProductValueResolver;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeEventCollector;
use Logingrupa\Metapixel\Classes\Event\CapturesRequestUserData;
use Logingrupa\Metapixel\Classes\Helper\PluginGuard;
use Logingrupa\Metapixel\Classes\Meta\PayloadBuilder;
use Logingrupa\Metapixel\Classes\Meta\UserDataHasher;
use Logingrupa\Metapixel\Classes\Queue\SendCapiEvent;
use Lovata\Shopaholic\Models\Product;
use Ramsey\Uuid\Uuid;
use Throwable;

final class ProductPageWatcher
{
    use CapturesRequestUserData;

    public function subscribe(Dispatcher $obDispatcher): void
    {
        $obDispatcher->listen('shopaholic.product.open', [$this, 'handle']);
    }

    public function handle(Product $obProduct): void
    {
        try {
            if (PluginGuard::isDisabled()) { return; }
            $obAdapter = new ShopaholicProductAdapter;
            $obResolver = new ShopaholicProductValueResolver;
            $obBuilder = new PayloadBuilder(new UserDataHasher);
            $sEventId = Uuid::uuid4()->toString();
            $iEventTime = time();
            $arPayload = $obBuilder->buildEventPayload(
                'ViewContent', $obAdapter, $obProduct, $obResolver,
                $sEventId, $iEventTime, [],
            );
            $arPayload = $this->injectRequestUserData($arPayload);

            App::make(ThemeEventCollector::class)->push([
                'name' => 'ViewContent',
                'action_key' => 'viewcontent:'.$obProduct->id.':'.$sEventId,
                'event_id' => $sEventId,
                'content_ids' => $obResolver->resolveContentIds($obProduct),
                'content_name' => is_string($obProduct->name) ? $obProduct->name : '',
                'content_type' => 'product',
                'value' => $obResolver->resolveValue($obProduct),
                'currency' => $obResolver->resolveCurrency($obProduct),
                'product_id' => $obProduct->id,   // ← so default.htm can render window.__metapixelProduct
            ]);

            SendCapiEvent::dispatch('ViewContent', $arPayload, $obProduct, ShopaholicProductAdapter::class);
        } catch (Throwable $obException) {
            Log::warning('metapixel: ProductPageWatcher emission failed', [
                'meta_pixel.product_id' => $obProduct->id ?? null,
                'meta_pixel.exception' => get_class($obException),
                'meta_pixel.message' => $obException->getMessage(),
            ]);
        }
    }
}
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|---|---|---|---|
| Browser-only `fbq('track', 'ViewContent')` | Browser + server CAPI dedup via shared event_id | Phase 3 (v2.0) onward | Required for Meta Conversions API best-practice EMQ |
| Single emit point at `onRun()` | Deferred flush at `cms.page.beforeRenderPage` | Phase 6 (v2.0) | Permits page-tier component pushes after their own onRun |
| FQN class strings from JS allowed in AJAX | Alias-only allowlist via AdapterRegistry::resolveByAlias | Phase 6 (v2.0) | Closes class-string deserialization surface; bounded to registered aliases |
| Custom Order column meta_purchase_event_id | EventLog UNIQUE race-fence with subject_type alias | Phase 3.1 (v1.x) | Plugin owns audit log, doesn't mutate Shopaholic schema |
| `Request::*` reads in adapter dirs | `CapturesRequestUserData` superglobal trait | Phase 4 (v2.0) | Cross-context determinism — adapter never request-context-dependent |

**Deprecated / outdated:**
- jQuery in plugin JS — parent CLAUDE.md mandates vanilla + Larajax.
- `@dataProvider` PHPDoc annotations — PHPUnit 12 dropped annotation discovery; use `#[DataProvider(...)]` attribute.
- `@phpstan-ignore` comments — project-wide ban; use runtime guards or private narrowing helpers.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|---|---|---|
| A1 | `Lovata\Shopaholic\Models\Product` does NOT mount `SoftDelete` trait | Pitfall 6 | If trashed, ViewContent fires for trashed product → bad data. Mitigation: planner spot-checks Product.php for `SoftDelete` import in Wave 2 task. [ASSUMED] |
| A2 | `Event::fire('shopaholic.product.open', [$obElement])` 3rd arg defaults to `$halt = false` | Section 3 | If halt enabled, watcher's return value could short-circuit the event chain. [VERIFIED via Lovata source — single-arg fire confirms halt=false] |
| A3 | All shipping adapters' `getSubjectType($obSubject)` ignore their argument and return a constant alias | Section 5 | If a third-party adapter conditional-dispatches, the alias-index built at register-time would be wrong/incomplete. Mitigation: interface PHPDoc + invariant test. [VERIFIED across 3 in-repo adapters; CITED: docs at `classes/adapter/EventSubjectAdapter.php` interface PHPDoc] |
| A4 | Twig render in `renderPageContents()` sees the latest `$this->page['x']` values set by listeners in `beforeRenderPage` | Section 1, Pitfall 1 | If not, deferred-flush blocks render empty. Mitigation: integration test `PixelHeadDeferredFlushTest::test_late_pushed_event_renders_in_pageHeadBlocks`. [VERIFIED via controller-source review at modules/cms/classes/Controller.php:421-427 + HasRenderers:78-86 — `$this->vars` is the Twig context, and `$component->page` is a backref into `$controller->vars`] |
| A5 | `jax.ajax(handler, options)` returns a jQuery-like promise with `.done()` | Example 2 | If signature differs in current Larajax version, JS breaks silently. Mitigation: planner spot-checks `vendor/larajax/larajax/` JS surface in Wave 3. [ASSUMED — verified by inspection of `themes/.../partials/.../*.htm` use-pattern; existing EventPixel default.htm uses `jax.ajax(...)` without explicit `.done()`. Recommendation: use the existing pattern (no chained promise) and re-render inside the ajax success callback via Larajax options.] |
| A6 | PHP 8.3 + 8.4 dual support — no 8.4-only syntax slipped | Pitfall 10 | Plugin breaks on PHP 8.3 cell. Mitigation: phpstan `phpVersion: 80300` + CI matrix includes 8.3 fresh cell. [VERIFIED via phpstan.neon] |
| A7 | `cms.page.beforeRenderPage` fires once per page-load even with nested partials | Section 1 | Multiple fires → double flush → duplicate events. Mitigation: confirmed via `fireSystemEvent` in Controller.php line 421 — fires inside the runPage outer block, not inside renderPartial. [VERIFIED via grep — single fire site per render] |

## Open Questions

1. **`window.__metapixelProduct` global vs DOM data-* attribute for product_id**
   - What we know: D-9 dropped the PDP scope gate; bonus-box cart selector exists (Pitfall 8).
   - What's unclear: whether operator-zero-config means "absolutely no theme code" (option c: server-injected global is theme-free) or whether the bonus-box selector spam is acceptable (option a: strict D-9).
   - Recommendation: ship option c (server-injected `window.__metapixelProduct`) — minimal JS code surface, theme-agnostic, soft-gates cart-modal spam. Discuss with user only if they object.

2. **`SupportsHybridAjax` subinterface vs adding `loadSubject` to base interface**
   - What we know: Adding a method to `EventSubjectAdapter` BC-breaks third-party adapters (per Phase 2 contract test 10 invariants).
   - What's unclear: whether Phase 6's hybrid AJAX path is generic enough that a subinterface is over-engineering.
   - Recommendation: ship subinterface (Section 9). Lighter blast radius; v2.1 can fold into base if pattern matures.

3. **PixelHead deferred-flush listener attach location: `Plugin::boot()` vs `PixelHead::init()`**
   - What we know: Section 2 covers both options.
   - What's unclear: whether component `init()` (called for every page) costs more than `boot()` register-once.
   - Recommendation: ship in `Plugin::boot()` — single attach, single source of truth, matches existing ThisVariable listener pattern.

4. **PriceTypeHelper resolution vs raw `Offer::price_value`**
   - What we know: CartPosition + Order resolvers read raw `Offer::price_value` (no PriceType lookup); active price-type is a v2.1 (D-12) deferred item.
   - What's unclear: whether the operator's PDP currently shows a different price than `Offer::price_value` due to active price-type logic in `OfferItem::getPriceValueAttribute`.
   - Recommendation: ship raw `Offer::price_value` for v2.0; defer to v2.1 along with the broader price-type consolidation.

5. **REQ-IDs to lock in REQUIREMENTS.md before plan-phase**
   - What we know: VIEW-01..11 sketched in this RESEARCH; DOCS-01 already exists as a v2.0 requirement (Phase 5 scope).
   - What's unclear: whether planner wants distinct `VIEW-*` prefix or to extend an existing prefix (e.g. `SHOP-06..NN` to keep adapter-bound requirements clustered).
   - Recommendation: use `VIEW-01..11` — distinct phase scope, clean trace ID for verification.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|---|---|---|---|---|
| PHP CLI 8.3+ | composer qa, tests | ✓ | 8.4.18 (parent CLAUDE.md) | — |
| Lovata.Shopaholic plugin | ProductPageWatcher + ShopaholicProductAdapter | ✓ | 1.32 (require-dev) | — |
| Lovata.OrdersShopaholic | already-shipping CartPosition + Order watchers | ✓ | 1.33 | — |
| ramsey/uuid | UUIDv4 event_id | ✓ | 4.x via october/all | — |
| guzzlehttp/guzzle | MetaClient HTTP | ✓ | 7.x | — |
| ChromeDriver / Playwright (E2E) | Section 11 — NOT used; manual UAT only | n/a | — | Manual smoke per Phase 5 D-XX (UAT closure 2026-05-27) |
| Pest 4 + PHPUnit 12 | Unit + Feature tests | ✓ | composer.json require-dev | — |
| MySQL/SQLite in-memory | Test DB | ✓ | SQLite in-memory per MetapixelTestCase setUp | — |
| Larajax | `jax.ajax(...)` browser AJAX | ✓ | `vendor/larajax/larajax/` (confirmed via existing EventPixel JS usage) | — |

**Missing dependencies with no fallback:** none.
**Missing dependencies with fallback:** none.

## Validation Architecture

> nyquist_validation: true — section included.

### Test Framework

| Property | Value |
|---|---|
| Framework | Pest 4 + PHPUnit 12 (class-based tests via `MetapixelTestCase` extending `October\Tests\Concerns\PerformsMigrations`) |
| Config file | `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/phpunit.xml` |
| Quick run command (single file) | `vendor/bin/pest tests/Feature/Adapter/Shopaholic/ProductPageWatcherTest.php` |
| Wave run command (group) | `vendor/bin/pest --group=adapter` |
| Full suite command | `composer qa` (chains pint-test → phpstan L10 → phpmd → pest --coverage --min=90) |
| Minimal-install cell | `vendor/bin/pest --exclude-group=adapter` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|---|---|---|---|---|
| VIEW-01 | PixelHead flush fires at `beforeRenderPage`, not `onRun` | Feature (component reflection) | `vendor/bin/pest tests/Feature/Components/PixelHeadDeferredFlushTest.php` | ❌ Wave 0 — new file |
| VIEW-02 | ShopaholicProductAdapter implements contract (10 invariants from EventSubjectAdapterContractTestCase) | Contract | `vendor/bin/pest tests/Contract/Adapter/Shopaholic/ShopaholicProductAdapterContractTest.php` | ❌ Wave 0 — new file |
| VIEW-03 | ValueResolver SKU format + currency fallback | Unit | `vendor/bin/pest tests/Feature/Adapter/Shopaholic/ShopaholicProductValueResolverTest.php` | ❌ Wave 0 — new file |
| VIEW-04 | Watcher fires on shopaholic.product.open + dispatches SendCapiEvent + pushes to collector | Feature | `vendor/bin/pest tests/Feature/Adapter/Shopaholic/ProductPageWatcherTest.php` | ❌ Wave 0 — new file |
| VIEW-05 | ProductPixel renders offer-switch JS + skips when disabled | Feature (component reflection) | `vendor/bin/pest tests/Feature/Components/ProductPixelTest.php` | ❌ Wave 0 — new file |
| VIEW-06 | Offer-switch JS markers present (substring assertions, not browser E2E) | Feature | (same as VIEW-05) | ❌ Wave 0 — new file |
| VIEW-07 | AdapterRegistry::resolveByAlias returns FQN; UnknownSubjectTypeException on miss | Unit | `vendor/bin/pest tests/Unit/Adapter/AdapterRegistryResolveByAliasTest.php` | ❌ Wave 0 — new file |
| VIEW-08 | SupportsHybridAjax::loadSubject returns null for inactive/cross-site/missing | Unit | (covered inside ShopaholicProductAdapterContractTest VIEW-02) | ❌ Wave 0 — new file |
| VIEW-09 | ThemeAjaxHandler subject_type routing: 422 unknown alias, 200 valid, 404 not-found | Feature | `vendor/bin/pest tests/Feature/Adapter/Theme/ThemeAjaxHandlerSubjectTypeTest.php` | ❌ Wave 0 — new file |
| VIEW-10 | Plugin.php boot guards subscriber on PluginManager::exists | Feature | (extend existing `tests/Feature/Plugin/PluginSanityTest.php`) | ✓ exists; add new assertions |
| VIEW-11 | All adapter tests carry #[Group('adapter')] class-level | Static (regex scan) | (extend existing CI workflow regex check or add a tests/Tooling assertion) | ✓ pattern exists |

### Sampling Rate

- **Per task commit:** `vendor/bin/pest path/to/specific/test.php` (specific file under work).
- **Per wave merge:** `vendor/bin/pest --group=adapter` (Wave 2/3 adapter scope) OR `composer qa` for cross-wave coverage check.
- **Phase gate:** `composer qa` green before `/gsd-verify-work`. Coverage ≥ 90 % on full-Lovata cell.

### Wave 0 Gaps

- [ ] `tests/Feature/Components/PixelHeadDeferredFlushTest.php` — covers VIEW-01 (Wave 1)
- [ ] `tests/Feature/Adapter/Shopaholic/ProductPageWatcherTest.php` — covers VIEW-04 (Wave 2)
- [ ] `tests/Feature/Adapter/Shopaholic/ShopaholicProductValueResolverTest.php` — covers VIEW-03 (Wave 2)
- [ ] `tests/Feature/Components/ProductPixelTest.php` — covers VIEW-05 + VIEW-06 (Wave 2 or 3)
- [ ] `tests/Feature/Adapter/Theme/ThemeAjaxHandlerSubjectTypeTest.php` — covers VIEW-09 (Wave 3)
- [ ] `tests/Contract/Adapter/Shopaholic/ShopaholicProductAdapterContractTest.php` — covers VIEW-02 + VIEW-08 (Wave 2)
- [ ] `tests/Unit/Adapter/AdapterRegistryResolveByAliasTest.php` — covers VIEW-07 (Wave 2)
- [ ] Existing `tests/Feature/Plugin/PluginSanityTest.php` extended with ProductPageWatcher + adapter registration assertion (Wave 2 task)

*Framework install: none — Pest + PHPUnit already in `require-dev`.*

## Security Domain

> security_enforcement is enabled (absent from config = enabled).

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---|---|---|
| V2 Authentication | no | n/a — no new auth surface; AJAX uses October CSRF token from session |
| V3 Session Management | no | n/a — session-scoped rate limit reuses existing pattern |
| V4 Access Control | partial | New `subject_type` AJAX parameter allowlist via `AdapterRegistry::resolveByAlias` — alias-only, no FQN deserialization |
| V5 Input Validation | yes | Allowlist for `name` (existing META_STANDARD + Settings.theme_custom_event_names). Allowlist for `subject_type` (registered aliases). Integer cast on `subject_id` + `offer_id`. JSON-escape for inline `<script>` via `JSON_HEX_*` mask. |
| V6 Cryptography | yes | UUIDv4 via `Ramsey\Uuid\Uuid::uuid4()` (cryptographically random); SHA-256 hashing of user_data via `UserDataHasher` (existing) |
| V7 Error Handling | yes | Tiger-Style log + skip at watcher boundary; typed exceptions (`UnknownSubjectTypeException` → 422, generic `Throwable` → 500 with masked message) |
| V13 API + Web Service | yes | Rate-limit per IP+session (existing ThemeAjaxHandler RATE_LIMIT_MAX=30 / 60s window); CSRF via October AjaxFramework |

### Known Threat Patterns for OctoberCMS + Larajax

| Pattern | STRIDE | Standard Mitigation |
|---|---|---|
| `<script>` injection via JSON encoding of subject_type/name | Tampering | `JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS` mask (existing pattern in PixelHead + ThemeAjaxHandler) |
| Class-string deserialization from POST `subject_type` (FQN injection) | Tampering / Elevation | Alias-only allowlist via `AdapterRegistry::resolveByAlias`; alias index built at register-time from a known finite set; never `new $arData['subject_type']` |
| Cross-site product PII leak via `subject_id` spoofing | Information Disclosure | `loadSubject` enforces `active()` + `hasRelationWithSite` (Pitfall 3) |
| Replay attack — repeated POSTs with same `subject_id` | Tampering | EventLog UNIQUE race-fence on (subject_type, subject_id, event_name, channel, site_id); duplicate inserts silently dropped via insertOrIgnore |
| Storm-flood — 1000 offer-switches/sec | DoS | Rate-limit: 30 req / 60s / (IP+session) per existing ThemeAjaxHandler; storm exceeds → 429 |
| Spurious bonus-box ViewContent (cart-modal `[name="offer_id"]`) | (data integrity, not classical STRIDE) | Soft-gate via `window.__metapixelProduct` global (Pitfall 8 option b) |
| CSRF on offer-switch POST | Spoofing | October AjaxFramework attaches X-CSRF-TOKEN automatically; framework returns 419 on missing/invalid token |
| Untrusted `_fbp` / `_fbc` cookie injection | Tampering | Already covered by Phase 4 CR-03 fbclid charset/length validation (`[A-Za-z0-9_-]` ≤255 chars) |

## Sources

### Primary (HIGH confidence)

- **Verified file reads:**
  - `/home/forge/nailscosmetics.lv/plugins/lovata/shopaholic/components/ProductPage.php` (full file) — `Event::fire` trigger context, `hasRelationWithSite` gate
  - `/home/forge/nailscosmetics.lv/plugins/lovata/shopaholic/classes/item/OfferItem.php:212-224` — `getPriceValueAttribute()` resolution chain
  - `/home/forge/nailscosmetics.lv/plugins/lovata/shopaholic/classes/helper/CurrencyHelper.php` (full file) — `getActiveCurrencyCode` semantics + multi-site behavior
  - `/home/forge/nailscosmetics.lv/plugins/lovata/shopaholic/models/Product.php` lines 60-200 — confirmed NO `$site_id` column, `belongsToMany site` via pivot
  - `/home/forge/nailscosmetics.lv/plugins/lovata/toolbox/traits/models/MultisiteHelperTrait.php` — `$site_list` array semantics
  - `/home/forge/nailscosmetics.lv/plugins/lovata/toolbox/classes/component/ElementPage.php:160-194` — `hasRelationWithSite` using `Site::getSiteIdFromContext`
  - `/home/forge/nailscosmetics.lv/modules/cms/classes/Controller.php` lines 196-535 — page lifecycle: `beforeDisplay`, `init`, `execPageCycle`, `beforeRenderPage`, `renderPageContents`
  - `/home/forge/nailscosmetics.lv/modules/cms/classes/controller/HasRenderers.php` lines 22-86 — `renderPage` + `renderPageContents` + `cms.page.render`
  - `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/components/PixelHead.php` (full file) — current flush location, base PageView pattern
  - `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/classes/event/adapter/shopaholic/CartPositionWatcher.php` (full file) — watcher template
  - `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/classes/event/adapter/shopaholic/OrderStatusWatcher.php` (full file) — alternative watcher template
  - `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/classes/adapter/AdapterRegistry.php` (full file) — `register` + `resolveFor` + `resolveByClass` shapes
  - `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/classes/adapter/shopaholic/ShopaholicCartPositionAdapter.php` (full file) — D-15 fallback pattern template
  - `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/classes/adapter/shopaholic/ShopaholicCartPositionValueResolver.php` (full file) — `buildContentId` reference
  - `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/classes/adapter/theme/ThemeAjaxHandler.php` (full file) — hybrid extension target
  - `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/classes/adapter/theme/ThemeEventCollector.php` (full file) — singleton + push/flush
  - `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/classes/event/CapturesRequestUserData.php` (full file) — superglobal-based capture
  - `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/classes/adapter/EventSubjectAdapter.php` (full file) — interface contract
  - `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/classes/adapter/ValueResolver.php` (full file) — interface contract
  - `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/components/EventPixel.php` + `components/eventpixel/default.htm` — server-confirmed pixel pattern + Larajax invocation `jax.ajax('Component::onHandler', ...)`
  - `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/Plugin.php` (full file) — boot wiring + existing `cms.page.beforeRenderPage` ThisVariable listener
  - `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/phpstan.neon` (full file) — disallowIn / allowIn patterns + disallowed function calls
  - `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/composer.json` — verified deps + suggest entries
  - `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/composer-dependency-analyser.php` — Lovata import boundary
  - `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/tests/Feature/Components/PixelHeadTest.php` + `PixelHeadBasePixelTest.php` — `runComponent` reflection pattern + Bus::fake assertions
  - `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/tests/Feature/Adapter/Theme/ThemeAjaxHandlerAllowlistTest.php` — Mockery + JsonResponse assertion pattern
  - `/home/forge/nailscosmetics.lv/themes/logingrupa-naisstore/partials/product/cart-position-list/cart-position-list-present-box.htm` — bonus-box `[name="offer_id"]` Pitfall 8 source
  - `/home/forge/nailscosmetics.lv/themes/logingrupa-naisstore/partials/ajax/ajax_product_modal/ajax_product_modal.htm` + `/partials/ajax/ajax_product_card_detailed_offers/ajax_product_card_detailed_offers.htm` — PDP `[name="offer_id"]` markup forms (radio + select)
  - `/home/forge/nailscosmetics.lv/plugins/logingrupa/storeextender/classes/event/cart/CartComponentHandler.php:140-165` — `buildSkuId` reference

- **CONTEXT.md + brief decisions D-1..D-10** verified verbatim.

### Secondary (MEDIUM confidence)

- Phase 2 + Phase 3 + Phase 4 + Phase 5 decisions carried forward from STATE.md "Accumulated Context" — all locked patterns referenced.

### Tertiary (LOW confidence)

- A5 Larajax `.done()` chain semantics — verified via theme partial usage pattern (no chained promise; success callback is provided via Larajax options). **Mitigation: planner spot-checks vendor/larajax/larajax/ JS source in Wave 3 before shipping JS.**
- A1 Product `SoftDelete` trait absence — assumed not present; planner spot-checks in Wave 2.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — all libs verified in `composer.json` + 6+ existing callsites for Ramsey/Uuid; no new external deps.
- Architecture: HIGH — October lifecycle verified via direct Controller.php read; AdapterRegistry alias-index design verified against 3 in-repo adapters; PixelHead deferred-flush proof verified via lifecycle source.
- Pitfalls: MEDIUM-HIGH — 11 pitfalls catalogued; 3 (Pitfall 6, A1) need Wave-0 spot-check by planner; 1 (Pitfall 8 cart-modal bonus-box) discovered during research and proposes a planner-decision option.
- Security: HIGH — alias-only allowlist threat model is sound; existing rate-limit + CSRF covers replay/spoofing; bonus-box spam mitigation via soft-gate.
- Testing: HIGH — existing patterns directly translatable; 7 new test files mapped to 11 REQ-IDs.

**Research date:** 2026-05-28
**Valid until:** 2026-06-12 (14 days — stable Lovata Shopaholic + October stack; valid until next minor October release)

## RESEARCH COMPLETE

Phase 6 ViewContent funnel research is HIGH confidence, all locked decisions back-verified against on-disk source-of-truth files. Critical discoveries: (1) Product model has NO `site_id` column → ShopaholicProductAdapter uses D-15 site-context fallback with phpstan allowlist entry; (2) cart-modal bonus-box selector `cart-position-list-present-box.htm:20` IS a live `[name="offer_id"]` outside PDP → recommend soft-gate via `window.__metapixelProduct` global (Pitfall 8); (3) `cms.page.beforeRenderPage` ALREADY has a listener in `Plugin.php:83-89` → safely add a second listener (Laravel dispatcher = registration-order, both pure observers); (4) PixelHead deferred-flush needs a singleton buffer (not component-instance state) for the Twig render to see the late mutation (Pitfall 1). Planner has the 7-test-file map + REQ-ID sketch + 4 open questions to resolve (or accept recommendations). Wave decomposition recommended: W1 PixelHead refactor + 4-test PixelHeadDeferredFlushTest → W2 adapter pair + watcher + resolver tests + AdapterRegistry::resolveByAlias (parallel) → W3 ProductPixel + JS + ThemeAjaxHandler hybrid + integration test → W4 CHANGELOG + README docs.
