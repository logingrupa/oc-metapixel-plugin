# Phase 6: ViewContent funnel — Shopaholic PDP + offer-switch — Context

**Gathered:** 2026-05-28
**Status:** Ready for planning
**Brief origin:** `.planning/briefs/2026-05-27-viewcontent-funnel-shopaholic.md`

<domain>
## Phase Boundary

Phase 6 closes the Meta conversion funnel at offer-level grain by adding the ViewContent event for Shopaholic operators with **zero theme code**. Plugin auto-fires on PDP render via `shopaholic.product.open` AND on every `[name="offer_id"]` change in the browser. Browser fbq and server CAPI share a server-generated UUIDv4 `event_id` for Meta deduplication.

```
ViewContent (offer SKU)  →  AddToCart (offer SKU)  →  Purchase (offer SKU per line item)
       NEW                       EXISTS ✓                    EXISTS ✓
```

Scope = brief deliverables verbatim:

1. **PixelHead refactor** — move flush from `onRun()` to `cms.page.beforeRenderPage` so page-tier components can push to `ThemeEventCollector` AFTER their `onRun` resolves but before PixelHead emits. Same single emit point. Preserves `base:pageview:{site_id}:{event_id}` action_key shape + base PageView dispatch.
2. **ShopaholicProductValueResolver + ShopaholicProductAdapter** — new adapter pair for `Lovata\Shopaholic\Models\Product`. Subject type alias = `'shopaholic.product'`. Resolves `value` (active offer's `price_value`), `currency` (`CurrencyHelper::instance()->getActiveCurrencyCode()`), `content_ids` (`['SKU-{pid}[-{oid}]']`), `content_name` (`$obProduct->name`), `content_type = 'product'`.
3. **ProductPageWatcher** — `shopaholic.product.open` subscriber. Builds payload via `PayloadBuilder` + `UserDataHasher`, injects `CapturesRequestUserData`, generates UUIDv4, dispatches `SendCapiEvent`, pushes to `ThemeEventCollector` for PixelHead flush.
4. **ProductPixel component** — vendor-neutral PDP-level browser pixel + offer-switch JS injector. Component alias `[productPixel]`. Replaces brief's `components/pixelhead/offer-switch.js` working name.
5. **Hybrid AJAX path** — extend existing `Metapixel::onFireEvent` handler with optional `subject_type` alias field. When present, `AdapterRegistry::resolveByAlias()` routes payload build through the registered adapter (ShopaholicProductAdapter for `'shopaholic.product'`).
6. **Tests** — three new test classes (matrix in brief Section "Test matrix"). Tagged `#[Group('adapter')]` so minimal-install CI cell excludes them.
7. **Docs** — README.md ViewContent walkthrough + CHANGELOG.md entry. Phase 5 plans 05-08..05-14 unblock after Phase 6 closes.

Out of scope (deferred to v2.1): InitiateCheckout, Search, per-currency conversion validation.

</domain>

<decisions>
## Implementation Decisions

### Carried forward (locked by brief — DO NOT re-derive)

- **D-1:** View grain = per-pageload AND per-offer-switch. Each switch = own ViewContent, own `event_id`, own offer SKU.
- **D-2:** Pixel emit = PixelHead refactored to flush at `cms.page.beforeRenderPage`. Single emit point. Page-tier components push to `ThemeEventCollector` after `onRun`.
- **D-3:** Trigger = `Event::fire('shopaholic.product.open', [$obElement])` at `plugins/lovata/shopaholic/components/ProductPage.php:71`. Native fail-safe for 404/inactive/site-mismatch.
- **D-4:** [informational] Ship target = v2.0. Blocks Phase 5 wave 6 (plans 05-08, 05-09, 05-12, 05-13, 05-14). _Meta-timing decision — not directly implementable in code; ROADMAP-tracked, surfaces in Plan 06-07 docs._
- **D-5:** `content_ids` on PDP = `['SKU-{product_id}-{offer_id}']`. Single-offer products = `['SKU-{product_id}']`. Matches catalog feed + CartPosition/Order resolver convention.
- **D-6:** Universal offer selector = `[name="offer_id"]`. Shopaholic canonical form-field name. Confirmed PDP-only by Shopaholic convention.

### Discussion-locked (Phase 6 — 2026-05-28)

- **D-7:** **AJAX endpoint shape = hybrid extension of `Metapixel::onFireEvent`** (NOT new dedicated endpoint). JS posts `{name:'ViewContent', subject_type:'shopaholic.product', subject_id:<product_id>, offer_id:<offer_id>, action_key:'viewcontent:<pid>:<oid>:<eid>'}`. `ThemeAjaxHandler::onBeforeRun` detects `subject_type` field. When present, calls `AdapterRegistry::resolveByAlias($sSubjectType)` → returns adapter class OR throws `UnknownSubjectTypeException` → 422. Server builds payload via resolved adapter + its `ValueResolver`. Returns `{event_id, script}` same as today. **Security:** `subject_type` is alias-only (NOT FQN), allowlist gated through `AdapterRegistry`. Untrusted JS bounded to registered subjects. Existing rate-limit + October CSRF token cover other threat surface. **Extensibility:** Mall plugin registers `AdapterRegistry::register('mall.product', MallProductAdapter::class)` in its `Plugin::boot()` — same JS path works without core changes.

- **D-8:** **Component name = `[productPixel]` / `Logingrupa\Metapixel\Components\ProductPixel`** (vendor-neutral). Mirrors existing `[eventPixel]` naming (server-confirmed browser pixel per event on order-complete). Drop brief's `components/pixelhead/offer-switch.js` working name. Component owns:
  - `<script>` block emitting `fbq('track','ViewContent',{...},{eventID:<sEventId>})` for initial PDP render (event_id passed in via collector / page var from ProductPageWatcher).
  - Inline offer-switch JS (delegated `change` listener on `document` matching `[name="offer_id"]`, posts to `Metapixel::onFireEvent` with `subject_type:'shopaholic.product'`).
  - Browser injects returned `script` payload (which contains `fbq('track', ...)` with server-generated event_id).
  - Disabled when `PluginGuard::isDisabled()`.

- **D-9:** **PDP scope gate dropped.** Cart has no offer selector (Shopaholic CartPositionList renders line items, no `[name="offer_id"]` radio/select inputs). `[name="offer_id"]` is PDP-only by Shopaholic convention per D-6. JS = bare delegated listener with no extra closest()/dataset gate. Brief Risk register row about "cart modal selector" is loose phrasing — no real cart modal selector exists in Shopaholic + theme. Operator-introduced rogue `[name="offer_id"]` outside PDP = operator-owned theme problem.

- **D-10:** **Default-selected offer on PDP render = first active offer by `sort_order` asc** — `$obProduct->offer->where('active', true)->sortBy('sort_order')->first()`. Matches Shopaholic native default-offer behavior + cart-add path. Out-of-stock first-active acceptable (matches what theme displays). Empty offer collection → `content_ids` falls back to bare `SKU-{product_id}` with no `offer_id`.

### Claude's discretion (planner resolves)

- **action_key shape for offer-switch ViewContent:** brief D-3 anchor proposes `viewcontent:{product_id}:{event_id}` for PDP render. Planner extends to `viewcontent:{product_id}:{offer_id}:{event_id}` for offer-switch (offer_id available in switch context, distinguishes log rows when reviewing EventLog). Both per-request-unique via `{event_id}` suffix — race-fence safe.
- **CHANGELOG entry:** brief D-2 says document PixelHead timing change in CHANGELOG breaking-changes section. Brief Risk register override says "no callout because fresh plugin no one USES". Resolution: PixelHead PHPDoc gets full timing-contract docblock (binding documentation for future operators), CHANGELOG ViewContent entry lists ProductPixel + ProductPageWatcher + ShopaholicProductAdapter under `### Added` — no breaking-changes subsection (consistent with Phase 5 D-22 fresh-v2.0.0 stance).
- **`AdapterRegistry::resolveByAlias()` signature + index storage:** new method, sibling to existing `register($sSubjectClass, $sAdapterClass)`. Planner picks between (a) reverse-lookup over existing registry via adapter's `getSubjectTypeAlias()` per call, OR (b) maintain a `<alias, adapter-class>` array filled at register-time. Option (b) is O(1) lookup, ~5 LOC added. Probably (b).
- **`UnknownSubjectTypeException` placement:** under `classes/exception/` mirroring existing exception class layout.
- **JS asset packaging:** inline `<script>` via `$this->page['productPixelScript']` rendered by component's default.htm Twig partial. Matches PixelHead `pixelHeadBase` pattern. Operator-zero-config.
- **Component placement in product layout:** README walkthrough instructs operator to add `[productPixel]` to the same layout/page that hosts Shopaholic `[ProductPage]` component. Component is no-op when no ProductPageWatcher payload pushed to collector (e.g. layout shared across PDP + non-PDP pages — collector-empty = no script render).

</decisions>

<canonical_refs>
## Canonical References (MANDATORY downstream reading)

### Phase brief (origin)
- `.planning/briefs/2026-05-27-viewcontent-funnel-shopaholic.md` — operator-confirmed locked decisions D-1..D-6, test matrix, risk register, reference commits, current-state inventory

### Parent context
- `/home/forge/nailscosmetics.lv/CLAUDE.md` — Hungarian notation, Tiger-Style fail-fast, Lovata.Toolbox patterns
- `plugins/logingrupa/metapixel/CLAUDE.md` — plugin identity, locked decisions from v1.1.1, adapter pattern boundaries, October-property-name carve-outs, build philosophy
- `.planning/PROJECT.md` — milestone goal + carry-forward decisions
- `.planning/REQUIREMENTS.md` — 61 v2.0 REQ-IDs (relevant: DOCS-01 ViewContent walkthrough)
- `.planning/ROADMAP.md` — v2.0.0 milestone roadmap (Phase 6 needs insertion via `/gsd-phase` before plan-phase)

### Prior phase context
- `.planning/phases/05-documentation-marketplace-launch/05-CONTEXT.md` — Phase 5 D-1..D-27 (notably D-22 fresh v2.0.0 CHANGELOG stance, D-04 PixelHead+EventPixel naming family)
- `.planning/phases/04-settings-rework-multisite-trustedhosts-cookie-failedevents-t/` — TrustedHosts + CapturesRequestUserData trait + cookie middleware groundwork
- `.planning/phases/03-shopaholicadapter-themeactionadapter-parallel-wave/` — original Shopaholic + Theme adapter pair (template for ShopaholicProductAdapter)
- `.planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/` — `EventSubjectAdapter` + `ValueResolver` + `AdapterRegistry` contracts

### Source-of-truth files (verified line refs from brief)
- `plugins/lovata/shopaholic/components/ProductPage.php:71` — `Event::fire('shopaholic.product.open', [$obElement])` trigger
- `plugins/lovata/shopaholic/classes/item/OfferItem.php:212-224` — `getPriceValueAttribute()` (active price-type + CurrencyHelper conversion)
- `plugins/lovata/shopaholic/classes/helper/CurrencyHelper.php:84` — `getActiveCurrencyCode()`
- `plugins/logingrupa/facebookcatalogshopaholic/classes/helper/ExportCatalogFacebookHelper.php:41,64` — SKU rule ground truth (catalog feed)
- `plugins/logingrupa/storeextender/classes/event/cart/CartComponentHandler.php:153-164` — `buildSkuId` analog
- `plugins/logingrupa/metapixel/components/PixelHead.php:57,98,108` — current flush location + action_key + test_event_code injection
- `plugins/logingrupa/metapixel/classes/event/adapter/shopaholic/CartPositionWatcher.php:28-87` — watcher pattern to mirror
- `plugins/logingrupa/metapixel/classes/adapter/theme/ThemeAjaxHandler.php:29-117` — extension target for D-7 hybrid endpoint
- `plugins/logingrupa/metapixel/classes/adapter/AdapterRegistry.php` — needs `resolveByAlias()` addition
- `plugins/logingrupa/metapixel/classes/adapter/shopaholic/ShopaholicCartPositionAdapter.php` — template for ShopaholicProductAdapter
- `plugins/logingrupa/metapixel/classes/adapter/shopaholic/ShopaholicCartPositionValueResolver.php:106-107` — SKU format reference for ShopaholicProductValueResolver
- `plugins/logingrupa/metapixel/classes/event/CapturesRequestUserData.php` — trait to mix into ProductPageWatcher
- October lifecycle anchor: `vendor/october/.../Cms/Classes/Controller.php:421` — `cms.page.beforeRenderPage`

### Reference commits
- `0658788` feat(pixelhead): restore base-pixel emission
- `106e671` fix(pixelhead): per-request action_key for race-fence (action_key pattern)
- `5700c1f` fix(watchers): inject request user_data (CapturesRequestUserData trait introduced)
- `c79c8c4` fix(capi): inject Settings.test_event_code top-level
- `ebee0fd` fix(pixel): emit test_event_code in browser fbq

</canonical_refs>

<code_context>
## Reusable Assets + Patterns

### Reuse verbatim — do not modify
| Class | File | Role in Phase 6 |
|---|---|---|
| `PayloadBuilder` | `classes/meta/PayloadBuilder.php` | ProductPageWatcher + ThemeAjaxHandler call `buildEventPayload()` |
| `UserDataHasher` | `classes/meta/UserDataHasher.php` | Hashing user_data fields |
| `SendCapiEvent` queue job | `classes/queue/SendCapiEvent.php` | Dispatched from ProductPageWatcher + ThemeAjaxHandler hybrid path |
| `EventLogWriter` | `classes/helper/EventLogWriter.php` | Race-fence UNIQUE INSERT |
| `CapturesRequestUserData` trait | `classes/event/CapturesRequestUserData.php` | Mix into ProductPageWatcher (request-context capture from $_SERVER/$_COOKIE) |
| `Settings::lookupForSite($iSiteId)` | `models/Settings.php` | Per-site pixel_id + token resolution |
| `Settings.test_event_code` | inj at `SendCapiEvent::handle:120,269,273` | Already top-level + already in browser fbq |
| `PluginGuard::isDisabled()` | `classes/helper/PluginGuard.php` | Gate every emission |
| `ThemeEventCollector` | `classes/adapter/theme/ThemeEventCollector.php` | ProductPageWatcher pushes here, PixelHead flushes |
| Existing `ShopaholicCartPositionAdapter` + `Resolver` | `classes/adapter/shopaholic/*` | Template — copy shape into ProductAdapter pair |
| Existing `OrderStatusWatcher` | `classes/event/adapter/shopaholic/OrderStatusWatcher.php` | Subscriber boilerplate template |

### Modify
| Class | File | Change |
|---|---|---|
| `PixelHead` | `components/PixelHead.php` | Move `emitCollectedEvents()` invocation from `onRun()` to a new `cms.page.beforeRenderPage` listener. Base PageView emission stays in `onRun` (per-request action_key already request-unique via UUIDv4). PHPDoc gains lifecycle-contract note. |
| `AdapterRegistry` | `classes/adapter/AdapterRegistry.php` | Add `resolveByAlias(string $sAlias): string` + internal `<alias, adapter-class>` index populated at `register()` time. |
| `ThemeAjaxHandler::onBeforeRun` | `classes/adapter/theme/ThemeAjaxHandler.php:63-117` | Detect optional `subject_type` field. When present: resolve via `AdapterRegistry::resolveByAlias()`, load subject via adapter's `loadSubject(int $iSubjectId, array $arContext)` contract, build payload via adapter + its resolver. When absent: existing ThemeActionEvent path unchanged. |
| `EventSubjectAdapter` interface | `classes/adapter/EventSubjectAdapter.php` | Add `loadSubject(int $iSubjectId, array $arContext): ?object` contract — third parties hydrate their subject from PK + context (`offer_id`, etc.). Returns null → 422 `unknown subject` from ThemeAjaxHandler. |

### Add
| Path | Purpose |
|---|---|
| `classes/adapter/shopaholic/ShopaholicProductAdapter.php` | EventSubjectAdapter for `Lovata\Shopaholic\Models\Product`. Subject type alias `'shopaholic.product'`. `getSiteId()` reads `$obProduct->site_id`. `loadSubject($iId, $arContext)` hydrates via `Product::find($iId)`. ONLY file allowed to import `Lovata\Shopaholic\Models\Product`. |
| `classes/adapter/shopaholic/ShopaholicProductValueResolver.php` | Resolves value/currency/content_ids/content_name/content_type. Default offer = `$obProduct->offer->where('active', true)->sortBy('sort_order')->first()`. Empty collection → bare `SKU-{pid}`. |
| `classes/event/adapter/shopaholic/ProductPageWatcher.php` | Subscribes `shopaholic.product.open`. Generates UUIDv4 event_id. action_key = `viewcontent:{pid}:{eid}` (PDP-render default-offer case; switch path adds `{oid}`). Pushes to ThemeEventCollector. Dispatches CAPI. Uses `CapturesRequestUserData`. Guards: `PluginGuard::isDisabled()` + `PluginManager::exists('Lovata.Shopaholic')` (boot-time). |
| `components/ProductPixel.php` | PDP browser pixel component + offer-switch JS injector. Reads pushed event from collector (or page var) → renders `<script>fbq('track','ViewContent',...,{eventID})</script>` + delegated change listener inline. Component alias `[productPixel]`. |
| `components/productpixel/default.htm` | Twig: emit script blocks when `productPixelView` and `productPixelOfferSwitchJs` page vars set. No-op otherwise. |
| `classes/exception/UnknownSubjectTypeException.php` | Thrown by `AdapterRegistry::resolveByAlias()` on miss. Caught at `ThemeAjaxHandler::onBeforeRun` → 422. |
| `tests/Feature/Adapter/Shopaholic/ProductPageWatcherTest.php` | Brief test matrix items 1-11 |
| `tests/Feature/Adapter/Shopaholic/ShopaholicProductValueResolverTest.php` | SKU format (single vs multi-offer), price source, currency source |
| `tests/Feature/Component/PixelHeadDeferredFlushTest.php` | Brief test matrix items 1-4 for PixelHead refactor |
| `tests/Feature/Component/ProductPixelTest.php` | Browser-script render shape, disabled-state, offer-switch JS attachment markers |
| `tests/Feature/Adapter/Theme/ThemeAjaxHandlerSubjectTypeTest.php` | Hybrid path: unknown alias → 422, valid alias → routes through registered adapter, allowlist bypass blocked |

### Plugin.php boot wiring
- Register `ProductPageWatcher` via `Event::subscribe` IF `PluginManager::exists('Lovata.Shopaholic')` (mirror existing pattern `Plugin.php:75-80`).
- Register `[productPixel]` component alias.
- Register `AdapterRegistry::register('shopaholic.product', ShopaholicProductAdapter::class)` (sibling to existing `'shopaholic.order'` / `'shopaholic.cartposition'` registrations).

### Composer dependency boundary
- `ShopaholicProductAdapter` is the ONLY new file allowed to import `Lovata\Shopaholic\*`. Enforced by `composer deps` (composer-dependency-analyser).
- All other new files import only Logingrupa\Metapixel\* + Laravel/October base + Ramsey\Uuid + Throwable.

### Tiger-Style boundaries
- ProductPageWatcher try/catch wraps payload build + CAPI dispatch + collector push. Catch logs `Log::warning('metapixel: ProductPageWatcher emission failed', ...)` and skips — page render must NOT 500 on pixel failure.
- ThemeAjaxHandler hybrid path inherits existing `Throwable` boundary — `UnknownSubjectTypeException` → 422 typed response, other throwables → existing 500 fallback.
- `subject_type` allowlist via AdapterRegistry — no FQN strings deserialized from JS, no class-string `new` on untrusted input.

</code_context>

<deferred>
## Deferred Ideas (out of Phase 6 scope)

- InitiateCheckout event (Cart → Checkout transition) — v2.1
- Search event (Filter component) — v2.1
- Per-currency conversion validation (currently delegates to Lovata's `CurrencyHelper::convert()`) — v2.1
- AddToCart browser-pixel mirroring on offer add (currently CAPI-only via CartPositionWatcher) — review post-v2.0 once funnel telemetry surfaces
- Mall plugin adapter (MallProductAdapter) — operator publishes themselves via `AdapterRegistry::register('mall.product', ...)` — docs example only per Phase 5 D-14

</deferred>

<next_steps>
## Next Steps

1. **Insert Phase 6 into ROADMAP.md** via `/gsd-phase add` (slot AFTER Phase 5, BEFORE archived "complete-milestone" steps). Phase 5 wave 6 plans (05-08, 05-09, 05-12, 05-13, 05-14) note Phase 6 as blocker.
2. **Plan-phase:** `/gsd-plan-phase 6` — researcher reads this CONTEXT + brief + canonical_refs source files. Planner produces `06-PLAN.md` with wave-based task breakdown matching brief Step 5 (Wave 1 PixelHead refactor → Wave 2 adapter pair + watcher + tests parallel → Wave 3 ProductPixel + JS + AJAX → Wave 4 docs).
3. **Execute-phase:** standard wave execution. Plan-checker MUST verify goal-backward — does plan close the funnel? Do tests cover the brief's 11-row matrix?
4. **Unblock Phase 5:** Plans 05-08 (smoke), 05-09 (README — must cover ViewContent), 05-12 (CHANGELOG — must list ViewContent additions), 05-13 (security sweep), 05-14 (v2.0.0 tag) resume after Phase 6 closes.

</next_steps>
</content>
</invoke>