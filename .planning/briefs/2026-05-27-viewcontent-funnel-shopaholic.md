# Brief: ViewContent funnel completion (Shopaholic PDP + offer-switch)

**Date:** 2026-05-27
**Target release:** v2.0 (blocks Phase 5 wave 6 — plans 05-08 through 05-14)
**Plugin:** `logingrupa/oc-metapixel-plugin` (`Logingrupa\Metapixel`)
**Repo:** `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/`
**Theme (separate repo, reference only):** `/home/forge/nailscosmetics.lv/themes/logingrupa-naisstore/`

## Goal

Close the Meta Pixel conversion funnel for Shopaholic operators at offer-level grain:

```
ViewContent (offer SKU)  →  AddToCart (offer SKU)  →  Purchase (offer SKU per line item)
        NEW                       EXISTS ✓                    EXISTS ✓
```

Zero theme code required from operator. Plugin auto-fires on Shopaholic PDP render AND on offer-selector change. Browser fbq + Server CAPI share `event_id` for Meta deduplication.

## Locked decisions (operator-confirmed 2026-05-27)

| # | Decision | Rationale |
|---|---|---|
| D-1 | **View grain = per-pageload AND per-offer-switch** | Operator wants offer-level funnel — measure how many offers viewed, added, purchased. Each switch is its own ViewContent with new event_id + new offer SKU. |
| D-2 | **Pixel emit = refactor PixelHead to flush LAST (`cms.page.beforeRenderPage`)** | Move PixelHead emission out of `onRun()` to lifecycle's end. Then page-tier components (ProductPage) can push events to `ThemeEventCollector` AFTER they resolve, before PixelHead flushes. Single emit point. ACCEPTED RISK: changes PixelHead public timing contract — must document in CHANGELOG breaking-changes section. |
| D-3 | **Trigger = `shopaholic.product.open` event** | Lovata fires `Event::fire('shopaholic.product.open', [$obElement])` in `plugins/lovata/shopaholic/components/ProductPage.php:71` (inside `getElementObject()`). Only fires when product exists, is active, and matches site. Native fail-safe for 404. Cleaner than `cms.page.beforeDisplay` + component sniffing. |
| D-4 | **Ship target = v2.0** | Blocks plans 05-08 (smoke + screenshots), 05-09 (README), 05-12 (CHANGELOG), 05-13 (security sweep), 05-14 (v2.0.0 tag). README + CHANGELOG MUST cover ViewContent scope and PixelHead lifecycle change. |
| D-5 | **content_ids on PDP = `['SKU-{product_id}-{offer_id}']`** | Matches existing CartPosition + Order resolver convention (`ShopaholicCartPositionValueResolver.php:106-107`, `ShopaholicOrderValueResolver.php:147-148`). Single-offer products use bare `SKU-{product_id}` (offer count check via `OfferCollection`). |
| D-6 | **Universal offer selector = `[name="offer_id"]`** | Shopaholic canonical form field name. Plugin JS hooks ALL DOM changes to any `<select>`, `<input type="radio">`, `<input type="hidden">` with `name="offer_id"`. Theme-agnostic — confirmed via `themes/logingrupa-naisstore/partials/ajax/ajax_product_card_detailed_offers/*.htm` lines 32-73 + `partials/product/cart-position-list/cart-position-list-present-box.htm:20`. |

## Current state (verified, do NOT re-derive)

### Already correct — DO NOT TOUCH
| Class | File | Status |
|---|---|---|
| `ShopaholicCartPositionAdapter` + `ValueResolver` | `classes/adapter/shopaholic/ShopaholicCartPosition*.php` | AddToCart with offer SKU ✓ |
| `ShopaholicOrderAdapter` + `ValueResolver` | `classes/adapter/shopaholic/ShopaholicOrder*.php` | Purchase per-line-item offer SKU ✓ |
| `CartPositionWatcher` | `classes/event/adapter/shopaholic/CartPositionWatcher.php` | Subscribes `eloquent.created/updated: CartPosition` → dispatches CAPI ✓ |
| `OrderStatusWatcher` | `classes/event/adapter/shopaholic/OrderStatusWatcher.php` | Status code + `wasChanged('status_id')` guards → Purchase CAPI ✓ |
| `CapturesRequestUserData` trait | `classes/event/CapturesRequestUserData.php` | Injects `client_ip_address`/`client_user_agent`/`fbp`/`fbc` from `$_SERVER`/`$_COOKIE` into `$arPayload['data'][0]['user_data']` ✓ |
| `SendCapiEvent::handle()` | `classes/queue/SendCapiEvent.php:120,269,273` | Injects `Settings.test_event_code` into top-level payload via `withTestEventCode()` ✓ |
| `ThemeActionAdapter` | `classes/adapter/theme/ThemeActionAdapter.php:28-47` | `SUPPORTED_EVENTS` already lists ViewContent for both `['capi','pixel']` channels ✓ |
| `ThemeActionEvent::fromArray` | `classes/adapter/theme/ThemeActionEvent.php:32-45` | Synthetic subject from `name` + `action_key` + arbitrary `$arData` payload ✓ |
| `ThemeAjaxHandler` | `classes/adapter/theme/ThemeAjaxHandler.php` | `cms.ajax.beforeRunHandler` interceptor + rate-limit + allowlist ✓ |

### Must change
| Class | Change |
|---|---|
| `components/PixelHead.php` | Move flush from `onRun()` to `cms.page.beforeRenderPage` listener. Preserve action_key shape `base:pageview:{site_id}:{event_id}`. Document timing change in PHPDoc + CHANGELOG. |

### Must add
| Class | Purpose |
|---|---|
| `classes/adapter/shopaholic/ShopaholicProductValueResolver.php` | Resolves `value` (active offer's `price_value`), `currency` (`CurrencyHelper::instance()->getActiveCurrencyCode()`), `content_ids` (`['SKU-{pid}[-{oid}]']`), `content_name` (`$obProduct->name`), `content_type='product'` |
| `classes/adapter/shopaholic/ShopaholicProductAdapter.php` | EventSubjectAdapter for `Lovata\Shopaholic\Models\Product`. Subject type alias = `'shopaholic.product'`. Routes to ValueResolver above. `getSiteId()` reads from `$obProduct->site_id` (NOT request context — PHPStan disallowed-calls bans `Request::*`/`SiteManager::*` in `classes/adapter/`). |
| `classes/event/adapter/shopaholic/ProductPageWatcher.php` | Subscribes `shopaholic.product.open`. Receives `Product` model. Resolves default-selected offer (first active in `$obProduct->offer` collection, or null if no offers). Builds payload via `PayloadBuilder` + `UserDataHasher`. Generates `$sEventId` (UUIDv4). Action_key = `viewcontent:{product_id}:{event_id}` (per-pageload uniqueness per D-3 anchor). Uses `CapturesRequestUserData::injectRequestUserData()`. Pushes to `ThemeEventCollector` (now safe per D-2). Dispatches `SendCapiEvent::dispatch('ViewContent', $arPayload, $obProduct, ShopaholicProductAdapter::class)`. Guard: `PluginGuard::isDisabled()` + `PluginManager::exists('Lovata.Shopaholic')` (in `Plugin::boot()`, mirror existing pattern at `Plugin.php:75-80`). |
| `components/pixelhead/offer-switch.js` (or appropriate asset location) | Vanilla JS (no jQuery). Delegated `change` listener on `document` matching `[name="offer_id"]`. On change: read new offer_id + product_id from form context, POST to AJAX handler with `name='ViewContent'` + `data={content_ids,value,currency,offer_id,product_id}`. ThemeAjaxHandler fires CAPI mirror. Browser fbq fires immediately with same event_id (generated client-side). PixelHead injects this script into page (it's already operator-wired component — auto-loads when PixelHead in layout). |
| AJAX endpoint contract | New AJAX handler name (e.g. `onMetapixelOfferSwitch`) OR reuse existing ThemeActionEvent path. Confirm whether `ThemeAjaxHandler` interceptor already supports this shape — if yes, document the JS payload contract. If no, extend. |
| `tests/Feature/Adapter/Shopaholic/ProductPageWatcherTest.php` | New. See test matrix below. |
| `tests/Feature/Adapter/Shopaholic/ShopaholicProductValueResolverTest.php` | New. Asserts SKU format (single vs multi-offer), price reads through `OfferItem::price_value` (active price-type + currency conversion applied), currency via `CurrencyHelper`. |
| `tests/Feature/Component/PixelHeadDeferredFlushTest.php` | New. Asserts emission moves to `cms.page.beforeRenderPage`, base PageView still emitted, race-fence still works, collector accepts late pushes. |

## Reference ground truth (verified line numbers)

- **Trigger event:** `plugins/lovata/shopaholic/components/ProductPage.php:71` — `Event::fire('shopaholic.product.open', [$obElement])`
- **Price source:** `plugins/lovata/shopaholic/classes/item/OfferItem.php:212-224` — `getPriceValueAttribute()` reads active price-type + applies `CurrencyHelper::instance()->convert()`
- **Currency source:** `plugins/lovata/shopaholic/classes/helper/CurrencyHelper.php:84` — `getActiveCurrencyCode(): ?string`
- **SKU rule (catalog feed ground truth):** `plugins/logingrupa/facebookcatalogshopaholic/classes/helper/ExportCatalogFacebookHelper.php:41,64`
- **Existing SKU helper analog:** `plugins/logingrupa/storeextender/classes/event/cart/CartComponentHandler.php:153-164` (`buildSkuId`)
- **PixelHead current flush:** `components/PixelHead.php:57` (`onRun()`) → action_key `components/PixelHead.php:98` (`'base:pageview:{site_id}:{event_id}'`) → test_event_code at line 108/117
- **CartPositionWatcher reference pattern:** `classes/event/adapter/shopaholic/CartPositionWatcher.php:28-87`
- **Lifecycle anchor:** `cms.page.beforeRenderPage` fires at `vendor/october/.../Cms/Classes/Controller.php:421` — AFTER all components' `onRun()` complete

## Constraints (parent + plugin CLAUDE.md — non-negotiable)

- **Hungarian notation:** `$obProductItem`, `$arPayload`, `$sEventId`, `$iProductId`, `$bIsActive`, `$fPrice`. PHPMD `ShortVariable min=4`.
- **PHP 8.3 + 8.4 dual support.** No 8.4-only syntax.
- **Tiger-Style fail-fast.** Boundary catches log + rethrow OR dead-letter. Every `catch` documents reason.
- **PHPStan level 10 + PHPMD + Pint clean.** Run `composer qa` before each commit. Binaries at `/home/forge/nailscosmetics.lv/vendor/bin`.
- **PHPStan disallowed-calls:** no `Request::*` / `SiteManager::*` / `request()` inside `classes/queue|event|adapter/*`. Use `$_SERVER` + `$_COOKIE` (see `CapturesRequestUserData` trait).
- **CommonSettings JSON blob:** any new Settings field = just add to `models/settings/fields.yaml`. No migration.
- **Coverage gate ≥ 90%** on full-Lovata CI cell. Tag new adapter tests with `#[PHPUnit\Framework\Attributes\Group('adapter')]` so minimal-install cell excludes via `--exclude-group=adapter`.
- **`PluginGuard::isDisabled()` MUST be checked before any emission** (honors empty pixel_id disabled mode).
- **`PluginManager::exists('Lovata.Shopaholic')` MUST guard subscriber registration** in `Plugin::boot()` (mirror existing pattern at `Plugin.php:75-80`).
- **Composer dependency boundary:** ShopaholicProductAdapter is the ONLY new file allowed to import `Lovata\Shopaholic\*`. Enforced by `composer deps`.

## Test matrix (Pest + PHPUnit class-based)

`ProductPageWatcherTest` MUST assert:
1. ViewContent fires when `shopaholic.product.open` dispatches with valid Product → CAPI dispatched + collector receives push
2. ViewContent does NOT fire when `PluginGuard::isDisabled()` returns true (empty pixel_id)
3. ViewContent does NOT fire when `Lovata.Shopaholic` plugin absent (subscriber never registered)
4. ProductPageWatcher does NOT throw when Product has zero offers (single-offer SKU fallback `SKU-{pid}`)
5. content_ids = `['SKU-{pid}-{oid}']` when Product has multiple offers (first-active-offer SKU)
6. content_ids = `['SKU-{pid}']` when Product has exactly one offer
7. CAPI payload + browser fbq emit MATCHING event_id (dedup contract)
8. user_data populated from `$_SERVER` (`HTTP_USER_AGENT`, `REMOTE_ADDR`/`HTTP_X_FORWARDED_FOR`) + `$_COOKIE` (`_fbp`, `_fbc`)
9. `Settings.test_event_code` non-empty → both CAPI payload top-level AND inline fbq script include it
10. EventLog UNIQUE race-fence does NOT block per-pageload duplicates (action_key includes per-request event_id)
11. Offer-switch AJAX endpoint accepts `[name="offer_id"]` change payload → fires new ViewContent with new event_id + new offer SKU

`PixelHeadDeferredFlushTest` MUST assert:
1. Base PageView emits at `cms.page.beforeRenderPage`, NOT at `onRun`
2. Collector accepts pushes between PixelHead's `onRun` and `cms.page.beforeRenderPage` fire
3. action_key shape unchanged (`base:pageview:{site_id}:{event_id}`)
4. test_event_code still flows to fbq script

## Process (start here)

### Step 1 — re-read locked decisions
Read this file. Read parent CLAUDE.md + plugin CLAUDE.md. Read each "current state" file in the table above (verify line numbers haven't drifted since 2026-05-27).

### Step 2 — verify ThemeAjaxHandler offer-switch contract
Check `classes/adapter/theme/ThemeAjaxHandler.php:63-99`. Determine:
- Does it accept arbitrary AJAX handler names (`onMetapixelOfferSwitch`)?
- Or does it require synthetic events through `ThemeActionEvent::fromArray`?
- What's the existing payload contract (`name`, `action_key`, `data` shape)?

Decide: extend existing handler OR add new dedicated `onMetapixelOfferSwitch` AJAX endpoint. Document in plan.

### Step 3 — discuss-phase
Invoke `/gsd-discuss-phase` with this brief loaded. Outcome: lock any remaining ambiguity (offer-resolution edge cases, JS asset delivery mechanism, AJAX endpoint shape). DO NOT re-derive D-1 through D-6 — they are locked.

### Step 4 — plan-phase
Invoke `/gsd-plan-phase`. Output: PLAN.md with task breakdown, file inventory, LOC estimate, dependency graph. Plan-checker MUST verify goal-backward (does plan close the funnel? do tests cover the matrix?).

### Step 5 — execute-phase
Wave 1: PixelHead refactor + tests (must pass before Wave 2 — collector contract change)
Wave 2 (parallel): ShopaholicProductValueResolver + ShopaholicProductAdapter + ProductPageWatcher + unit tests
Wave 3: JS offer-switch + AJAX endpoint + integration test
Wave 4: CHANGELOG entry (breaking-change callout for PixelHead timing) + README update

### Step 6 — unblock Phase 5
Plans 05-08, 05-09, 05-12, 05-13, 05-14 resume after this phase ships.

## Out of scope (defer to v2.1)

- InitiateCheckout event (Cart → Checkout transition)
- Search event (Filter component)
- Per-currency conversion validation (currently delegates to Lovata's `CurrencyHelper::convert()`)

## Risk register

| Risk | Mitigation |
|---|---|
| PixelHead timing change breaks downstream operators relying on `onRun` emit | CHANGELOG breaking-change callout. --> no callout, becasue this is fresh plugin no one USES!!!!!!!  no legacy support needed! 
| Offer-switch JS fires on irrelevant `[name="offer_id"]` instances (e.g. cart modal selector) | Scope JS to PDP only — gate by checking `document.body` data attr or container class set by ProductPageWatcher when product context is present. |
| Multi-offer product with NO active offer → null pointer | Watcher guard: `if ($obProduct->offer->isEmpty()) { fire with SKU-{pid} bare, no offer_id }` — test case 4 + 6. |
| Currency conversion happens twice if payload passes through CartPosition path | Not applicable — ViewContent path is product-level, doesn't touch CartPosition. Verify in test 5. |
| AJAX endpoint becomes attack surface for arbitrary CAPI emission | Reuse ThemeAjaxHandler rate-limit + allowlist (already implemented per `tests/Feature/Adapter/Theme/ThemeAjaxHandlerRateLimitTest.php` + `ThemeAjaxHandlerAllowlistTest.php`). |

## Reference commits
- `0658788` — `feat(pixelhead): restore base-pixel emission` — current architecture baseline
- `106e671` — `fix(pixelhead): per-request action_key for race-fence` — action_key pattern to mirror
- `5700c1f` — `fix(watchers): inject request user_data` — `CapturesRequestUserData` trait introduced
- `c79c8c4` — `fix(capi): inject Settings.test_event_code top-level` — test_event_code in CAPI payload
- `ebee0fd` — `fix(pixel): emit test_event_code in browser fbq` — test_event_code in fbq scripts
