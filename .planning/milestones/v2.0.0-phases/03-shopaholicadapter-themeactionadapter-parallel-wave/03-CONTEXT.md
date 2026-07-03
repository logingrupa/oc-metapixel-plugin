# Phase 3: ShopaholicAdapter + ThemeActionAdapter parallel wave — Context

**Gathered:** 2026-05-18
**Status:** Ready for planning

<domain>
## Phase Boundary

Ship two production adapters against the Phase 2 locked backbone (`MetaClient` + `PayloadBuilder` + `UserDataHasher` + `EventLogWriter` + `AdapterRegistry` + 3 `Event::fire` hooks). All adapter code is **fresh**, not a v1.x port. Phase 2's contract test base (`Logingrupa\Metapixel\Classes\Testing\EventSubjectAdapterContractTestCase`) governs invariants for both adapters.

**Two adapter dirs ship:**

1. `classes/adapter/shopaholic/` — ONLY directory allowed to import `Lovata\OrdersShopaholic\*` (composer-dependency-analyser enforced). Houses two adapter classes — `ShopaholicOrderAdapter` (alias `'shopaholic.order'`, dispatches `Purchase` on paid-status flip) and `ShopaholicCartPositionAdapter` (alias `'shopaholic.cart_position'`, dispatches `AddToCart` on cart-position created/updated). Each adapter pairs with its own `ValueResolver`. Watchers live under `classes/event/adapter/shopaholic/`. Registered conditionally in `Plugin::boot()` only when `PluginManager::instance()->exists('Lovata.OrdersShopaholic')` is true.

2. `classes/adapter/theme/` — generic adapter for operator-fired theme events (PageView, ViewContent, Lead, custom). Owns Twig API (`{% do this.metapixel.pushEvent(...) %}`), `ThemeEventCollector` (request-scoped accumulator), `ThemeAjaxHandler` Larajax route (`Metapixel::onFireEvent` with allowlist + CSRF + rate-limit + JS-escape — P-09 prevention).

3. `components/EventPixel.php` — browser-side fbq emitter for server-confirmed subjects. Reads EventLog by (subject, event_name, channel='capi'), emits inline `fbq('track', name, custom_data, {eventID})` with the server-supplied event_id, writes `channel='pixel'` row on `onMarkFired`. Generic across adapters via `subject_class` + `subject_slug_field` component properties.

Phase 3 owns 12 requirements (SHOP-01..05 + THEM-01..07) and prevents pitfalls **P-03** (Lovata import isolation at adapter dir boundary), **P-05** (subject_type opaque alias `'shopaholic.order'` / `'shopaholic.cart_position'`), **P-09** (Larajax open-relay surface), **P-11** (`PluginManager::exists` autoload race gate).

</domain>

<decisions>
## Implementation Decisions

### ShopaholicAdapter event scope (Area 1)

- **D-01:** Phase 3 first-party Shopaholic ships **Purchase + AddToCart**. Lead + Search + checkout-funnel events are ThemeAdapter responsibility (operator-supplied Twig wiring), not first-party Shopaholic.
- **D-02:** **Two adapter classes** in `classes/adapter/shopaholic/`, not one mega-adapter. Each adapter owns ONE subject kind + ONE event (SRP at file level):
  - `ShopaholicOrderAdapter` — subject `Lovata\OrdersShopaholic\Models\Order`, alias `'shopaholic.order'`, event `Purchase`, getSupportedEvents → `['Purchase' => ['capi','pixel']]`.
  - `ShopaholicCartPositionAdapter` — subject `Lovata\OrdersShopaholic\Models\CartPosition`, alias `'shopaholic.cart_position'`, event `AddToCart`, getSupportedEvents → `['AddToCart' => ['capi','pixel']]`.
- **D-03:** Watchers split symmetrically under `classes/event/adapter/shopaholic/`:
  - `OrderStatusWatcher` — binds `eloquent.updated` + `eloquent.created` on `Order`; on paid-status match + EventLog row absent, dispatches `SendCapiEvent::dispatch('Purchase', $arPayload, $obOrder, ShopaholicOrderAdapter::class)`.
  - `CartPositionWatcher` — binds `eloquent.created` + `eloquent.updated` on `CartPosition`; on row-create or quantity-change, dispatches `SendCapiEvent::dispatch('AddToCart', $arPayload, $obCartPosition, ShopaholicCartPositionAdapter::class)`. EventLog UNIQUE race-fence on `(subject_type='shopaholic.cart_position', subject_id, event_name='AddToCart', channel, site_id)` is the dedup anchor — qty-bump updates do NOT re-fire because subject_id stays constant.
- **D-04:** Each adapter pairs with its own `ValueResolver` in the same dir (`ShopaholicOrderValueResolver`, `ShopaholicCartPositionValueResolver`). content_ids in both = `SKU-{product_id}[-{offer_id}]` (matches Catalog feed exporter — P-05/D-DD carry-forward). Shared SKU-formatting helper extracted to a private trait if logic duplicates.
- **D-05:** PageView + ViewContent are NOT shipped via the Shopaholic adapter dir. They live in ThemeAdapter at theme layout level (operator wires `{% do this.metapixel.pushEvent({name:'ViewContent', content_ids:['SKU-' ~ product.id], ...}) %}` once in their PDP layout). October's `lovata_popularity_shopaholic_products` table is unrelated — that is October's Popularity plugin counting views internally, not a Meta event log.

### EventLog payload column + 7-day TTL purge (Area 2)

- **D-06:** EventLog gains a `payload longText NULL` column via NEW Phase 3 migration `add_payload_to_metapixel_event_log_table.php`. Phase 2 migration `create_metapixel_event_log_table.php` is NOT amended — additive migration only. Idempotent. Marketplace fresh-install picks up both migrations together in October's standard `october:migrate` ordering.
- **D-07:** `EventLogWriter::record` signature gains a trailing `array $arPayload` parameter. SendCapiEvent::handle passes `$this->arPayload` through. Phase 2 callsites that did NOT carry payload (none in production at this point) updated. PHPStan level 10 stays green.
- **D-08:** New console command `Logingrupa\Metapixel\Console\PurgeEventLog` registered as `metapixel:purge-event-log`. Deletes EventLog rows where `created_at < now() - 7 days`. Registered in `Plugin::registerSchedule(Schedule $obSchedule): void { $obSchedule->command('metapixel:purge-event-log')->daily(); }`. TTL window matches the practical Meta dual-channel match window — pixel-twin will not arrive after 7 days on any real-world thank-you-page flow.
- **D-09:** `EventPixel::onRun()` reads the EventLog row directly (`DB::table('logingrupa_metapixel_event_log')->where([...])->first(['event_id','event_time','payload'])`); emits inline `<script>fbq('track', name, $arPayload['custom_data'], {eventID: $arRow->event_id});</script>`. No adapter re-resolve at render time. Frozen audit guarantees pixel-emit parity with server-emit even if subject mutates between dispatch and render.
- **D-10:** Industry parallel — Snowplow `atomic.events` shape (dedup keys + raw payload in same table) + bounded retention. The "EventLog is a dispatched-event ledger" mental model resolves the SRP concern (one concern: "what was emitted to Meta"). Not "dedup table that grew payload".

### ThemeAjaxHandler allowlist (Area 3)

- **D-11:** `ThemeAjaxHandler` (the Larajax `Metapixel::onFireEvent` handler) validates incoming `event_name` against TWO sources combined per request:
  1. `const META_STANDARD = ['PageView','ViewContent','AddToCart','AddToWishlist','InitiateCheckout','Purchase','Lead','CompleteRegistration','Search','Subscribe','Contact','FindLocation','Donate','CustomizeProduct','SubmitApplication','AddPaymentInfo','StartTrial','Schedule'];` (18 Meta-standard event names per Graph API v23.0).
  2. `Settings::get('theme_custom_event_names', [])` — operator-supplied list, sanitized at SAVE time (alpha-num + underscore, ≤ 50 chars; bad entries flash admin warning + dropped). Operator never sees regex syntax.
- **D-12:** Settings field shape: Lovata.Toolbox CommonSettings textarea field `theme_custom_event_names` (one event name per line). commentAbove text translates en/lv (Phase 4 LANG-01) — `lang.settings.theme_custom_event_names.label` + `.commentAbove` keys reserved now, populated Phase 4.
- **D-13:** Dev-savvy operators who want server-side custom events (`Logingrupa_SalonBooked` from a Booking model, `Acme_ColorPicked` from a custom widget) do NOT touch ThemeAjaxHandler. They write their own adapter + register from their `Plugin::boot()`:
  ```php
  AdapterRegistry::instance()->register(SalonBooking::class, SalonBookingAdapter::class);
  SendCapiEvent::dispatch('Logingrupa_SalonBooked', $arPayload, $obBooking, SalonBookingAdapter::class);
  ```
  This is the Phase 2 extensibility contract — no fork required, ranked path #1 in plugin CLAUDE.md "Extensibility contract".
- **D-14:** P-09 prevention surface preserved: CSRF token check (October's `cms.ajax.beforeRunHandler` event integration) + per-IP+session rate-limit (Laravel `Illuminate\Cache\RateLimiter`, default 30 req / 60 s) + JS-escape on returned `<script>` fragment (October's `e()` helper). Sanitization at SAVE boundary (operator-visible) + allowlist match at request boundary (handler-side) — two-zone defence.

### ThemeAdapter site_id source + Plan ordering (Area 4)

- **D-15:** **ThemeAdapter is the ONE documented P-01 exception.** `ThemeActionAdapter::getSiteId(object $obSubject): ?int` reads `arPayload['site_id']` first; if missing or non-int, falls back to `\October\Rain\Support\Facades\Site::getCurrent()?->getId()`. Documented in class-level PHPDoc + plugin CLAUDE.md "Locked decisions" section. Rationale: theme events fire in-request by definition (no queue rehydrate cross-context drift possible); forcing operator boilerplate `site_id: this.site.id` on every Twig call is anti-DX with no real safety gain.
- **D-16:** PHPStan disallowed-calls config splits by sub-directory. The ban on `SiteManager::*` / `Site::*` / `request()` / `Request::*` applies to:
  - `classes/queue/` ✅ (Phase 2 lock unchanged)
  - `classes/event/` ✅ (Phase 2 lock unchanged)
  - `classes/adapter/shopaholic/` ✅ (Phase 3 — strict for Order/CartPosition path; P-01 anchor)
  - `classes/adapter/theme/` ❌ (EXCLUDED — Site::getCurrent() permitted here only; sole documented exception)
- **D-17:** Plan execution **sequential, Shopaholic-first**. Plans 03-01 → 03-08 ship in linear order. Dogfood on nailscosmetics.* happens before any Theme adapter work begins. Tests cumulative — each plan green before next starts. Phase 4 multisite work depends on per-site Shopaholic Order flow having already shipped + validated.
- **D-18:** Plan outline (planner refines exact task counts):
  - **03-01** — EventLog `payload` column migration + EventLogWriter sig change + `PurgeEventLog` console command + Schedule daily wire-up. (Foundation for SHOP + THEM.)
  - **03-02** — ShopaholicOrderAdapter + ShopaholicOrderValueResolver + OrderStatusWatcher + conditional `Plugin::boot()` registration (SHOP-01..04).
  - **03-03** — ShopaholicCartPositionAdapter + ShopaholicCartPositionValueResolver + CartPositionWatcher.
  - **03-04** — SHOP-05 integration test (status flip → dispatch → race-fence → MetaClient mock → payload assert; second admin-flip dedup test).
  - **03-05** — ThemeActionEvent value object + ThemeActionAdapter (with D-15 fallback) + getSiteId tests (THEM-01..02).
  - **03-06** — ThemeEventCollector request-scoped singleton + `Plugin::registerMarkupTags()` Twig API `this.metapixel.pushEvent()` (THEM-03..04).
  - **03-07** — ThemeAjaxHandler `Metapixel::onFireEvent` + META_STANDARD const + Settings textarea + CSRF + rate-limit + JS-escape + P-09 fuzzing tests (THEM-05).
  - **03-08** — EventPixel component + PixelHead extension with collector emit + `onMarkFired` AJAX writes channel='pixel' row (THEM-06..07).

### Carried forward (already locked by Phase 2 / project; do NOT re-derive)

- **D-19:** ShopaholicOrderAdapter::getSubjectType returns the opaque alias `'shopaholic.order'` (NOT class FQN — P-05). ShopaholicCartPositionAdapter returns `'shopaholic.cart_position'`. Alias contract enforced by EventSubjectAdapter PHPDoc + contract test invariant 01.
- **D-20:** content_ids format = `SKU-{product_id}[-{offer_id}]` for ALL Shopaholic adapters. Matches Facebook Catalog feed exporter `plugins/logingrupa/facebookcatalogshopaholic/classes/helper/ExportCatalogFacebookHelper.php:356` — pixel + catalog MUST emit same id for Meta product-matching.
- **D-21:** `PluginManager::instance()->exists('Lovata.OrdersShopaholic')` gate before adapter registration in `Plugin::boot()`. Prevents class_exists autoload race + Run B (minimal install, no Lovata) booting cleanly without Shopaholic plugins (P-11). composer-dependency-analyser enforces ZERO `Lovata\OrdersShopaholic\*` imports outside `classes/adapter/shopaholic/` (P-03).
- **D-22:** `PayloadBuilder::buildEventPayload(string $sEventName, EventSubjectAdapter, object, ValueResolver, string $sEventId, int $iEventTime, array $arEventExtras): array` — event-agnostic; adapter supplies event-specific `custom_data` overrides via `$arEventExtras` (D-21 from Phase 2). NO event-name comparison anywhere in PayloadBuilder body (Phase 2 H-9 grep gate stays green).
- **D-23:** `before_dispatch` listener is halt-able via Event::fire(...,true) third-arg semantics; `after_dispatch` + `dead_letter` observe-only (P-08 Phase 2 lock). Snapshot+restore on `event_id` + `event_time` ensures listeners cannot break dedup contract.
- **D-24:** Every Phase 3 adapter test class extends `Logingrupa\Metapixel\Classes\Testing\EventSubjectAdapterContractTestCase` (the abstract contract base shipped Phase 2 plan 02-07). Two new contract test classes — `ShopaholicOrderAdapterContractTest` + `ShopaholicCartPositionAdapterContractTest` + `ThemeActionAdapterContractTest`. Each supplies `makeAdapter()` + `makeSubject()` factory methods; the 10 invariants are inherited. Adapter tests tagged `#[Group('adapter')]` for `pest --exclude-group=adapter` minimal-install CI cell.
- **D-25:** Lowercase folder convention persists — `classes/adapter/shopaholic/`, `classes/adapter/theme/`, `classes/event/adapter/shopaholic/`, `tests/Feature/Adapter/Shopaholic/`, `tests/Feature/Adapter/Theme/`. Namespaces PascalCase (`Logingrupa\Metapixel\Classes\Adapter\Shopaholic\…`). October Rain ClassLoader autoload requirement (Phase 2 plan 02-01 lock).
- **D-26:** Hungarian notation everywhere — `$obOrder`, `$obCartPosition`, `$obAdapter`, `$arPayload`, `$sEventName`, `$sEventId`, `$iSiteId`, `$bIsActive`. PHPMD `ShortVariable min=4` enforced.
- **D-27:** Final classes, ≤70 LOC methods, one responsibility per class. Each Watcher = single subscribe method + single handler method.
- **D-28:** `@phpstan-ignore` is banned project-wide. When level 10 mixed-narrowing fails on `Settings::get(...)` or `json_decode`, extract a private runtime-guard helper (existing `Settings::lookupForSite` + `MetaClient::decodeBody` + `SendCapiEvent::firstEventRecord` pattern).
- **D-29:** Larajax transport — theme calls `jax.ajax('Metapixel::onFireEvent', {data: {...}})` against the October component-handler route, NOT `Larajax::get/post()` route facades. Plugin ships ZERO routes; the `cms.ajax.beforeRunHandler` event integration is THE wire-up surface.

### Claude's Discretion

User did not explicitly direct on these — planner proceeds with stated default unless conflict surfaces:

- **OrderStatusWatcher trigger code:** `paid_status_code` resolves from Settings via dropdown sourced from `Lovata\OrdersShopaholic\Models\Status::lists()` (ROADMAP "Settings UX" section). Single-value, not multi-select. Default value `new-payment-received` matches nailscosmetics.* baseline. Operator may flip.
- **CartPositionWatcher trigger semantics:** dispatch on `eloquent.created` (first-time-add); on `eloquent.updated`, dispatch ONLY when EventLog row absent for the same (subject_type, subject_id, AddToCart, capi, site_id) tuple. Qty-bump is NOT a new AddToCart by Meta convention. UNIQUE race-fence + per-subject_id key is the dedup anchor; no extra Watcher-side logic needed beyond the trigger guard.
- **ThemeEventCollector flush boundary:** explicit `flush()` called by PixelHead component after emit + tests' `tearDown()`. No magic terminating-event flush — keeps test isolation explicit (Phase 2 PluginGuard reset pattern parallel).
- **PixelHead-EventPixel coexistence:** PixelHead emits accumulator events on its own render (theme-side, in-request); EventPixel handles the server-confirmed-elsewhere path (e.g. CAPI fired from queue worker → customer hits thank-you page later). Separate components, no overlap.
- **Test directory layout:** `tests/Feature/Adapter/Shopaholic/` (DB-backed, Run A only) + `tests/Feature/Adapter/Theme/` (no Lovata) + `tests/Contract/Adapter/Shopaholic/` + `tests/Contract/Adapter/Theme/` (contract base subclasses).
- **`Logingrupa\Metapixel\Plugin::registerComponents()`** registers `EventPixel`, and `PixelHead` (operator places `{% component 'eventPixel' %}` on thank-you template + `{% component 'pixelHead' %}` in layout `<head>`).

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase scope + requirements

- `.planning/ROADMAP.md` §"Phase 3: ShopaholicAdapter + ThemeActionAdapter parallel wave" — Goal statement, depends-on, success criteria SC1–SC5.
- `.planning/ROADMAP.md` §"Twig API — operator-supplied theme events" — operator-facing API shape (PageView/ViewContent/AddToCart sample twig blocks).
- `.planning/ROADMAP.md` §"Custom adapter example — third-party cart plugin" — reference shape for marketplace third-party adapters.
- `.planning/REQUIREMENTS.md` §"ShopaholicAdapter (Phase 3)" — SHOP-01..05 verbatim specs.
- `.planning/REQUIREMENTS.md` §"ThemeActionAdapter (Phase 3)" — THEM-01..07 verbatim specs.
- `.planning/PROJECT.md` §"Current Milestone: v2.0.0" — target features, v2.0 build philosophy (simple logic, no over-engineering, no BC shims, no v1.x port).

### Phase 2 carry-forward (consumed by Phase 3 implementations)

- `.planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-CONTEXT.md` — D-01..D-22 Phase 2 lock list; D-21 PayloadBuilder event-agnostic + $arEventExtras contract; D-11 contract test base location.
- `classes/adapter/EventSubjectAdapter.php` — 7-method contract Phase 3 adapters implement.
- `classes/adapter/ValueResolver.php` — 5-method contract for content_ids/value/currency/contents/num_items resolution.
- `classes/adapter/AdapterRegistry.php` — `register($sSubjectClass, $sAdapterClass)` + `resolveFor($obSubject)` + `resolveByClass($sAdapterClass)` API.
- `classes/queue/SendCapiEvent.php` — 4-arg constructor `(string, array, object, string $sAdapterClass)`; `handle()` orchestration; 3 hook fire-sites; FailedEvent writer signature.
- `classes/helper/EventLogWriter.php` — race-fence record signature (sig change in Phase 3 D-07).
- `classes/helper/SiteResolver.php` — `forSubject(object, EventSubjectAdapter): ?int` — used by Shopaholic dispatch path (theme path bypasses per D-15).
- `classes/meta/PayloadBuilder.php` — event-agnostic envelope build; adapter supplies `$arEventExtras`.
- `classes/meta/MetaClient.php` — `sendForPixel(string $sPixelId, string $sToken, array $arPayload): array` (Graph API v23.0 pinned).
- `classes/testing/EventSubjectAdapterContractTestCase.php` — 10-invariant abstract base; Phase 3 adapter contract tests extend it.
- `models/EventLog.php` — Phase 3 receives the `payload` column addition + writer-side sig update.
- `models/FailedEvent.php` — `$jsonable = ['payload']` + 9 fillable columns (subject_type/id populated by SendCapiEvent::writeFailedEvent when adapter resolves).
- `models/Settings.php` — Phase 3 gains `theme_custom_event_names` textarea field via `$settingsFields` YAML.
- `tests/MetapixelTestCase.php` + `tests/ShopaholicAdapterTestCase.php` — Phase 3 adapter tests extend appropriate base; Adapter tests tagged `#[Group('adapter')]`.

### Pitfall ownership (Phase 3 anchors)

- `.planning/research/PITFALLS.md` §P-03 Hidden Lovata imports outside adapter dir — composer-dependency-analyser config + adapter-dir boundary test.
- `.planning/research/PITFALLS.md` §P-05 EventLog subject_type alias ambiguity — Phase 3 returns alias strings, NOT FQN.
- `.planning/research/PITFALLS.md` §P-09 Larajax open relay — THEM-05 allowlist + CSRF + rate-limit + JS-escape + fuzzing test matrix.
- `.planning/research/PITFALLS.md` §P-11 class_exists autoloader race — `PluginManager::exists` gate before adapter register.

### Lovata-ecosystem references (Shopaholic adapter only — outside dir would violate P-03)

- `plugins/lovata/ordersshopaholic/models/Order.php` — `site_id`, `secret_key`, `status_id` columns + relations (PriceList, OrderPosition, currency).
- `plugins/lovata/ordersshopaholic/models/CartPosition.php` — `id`, `cart_id`, `offer_id`, `quantity`, `price` columns (subject for AddToCart adapter).
- `plugins/lovata/ordersshopaholic/models/Status.php` — `Status::lists()` source for `paid_status_code` Settings dropdown.
- `plugins/lovata/ordersshopaholic/classes/processor/OrderProcessor.php` — `$this->arOrderData['site_id'] = Site::getSiteIdFromContext();` — confirms Order.site_id authoritative source.
- `plugins/logingrupa/facebookcatalogshopaholic/classes/helper/ExportCatalogFacebookHelper.php:356` — content_ids format anchor for ShopaholicOrderValueResolver + ShopaholicCartPositionValueResolver.

### Theme + AJAX wire-up references

- `modules/cms/classes/controller/HasAjaxRequests.php` — `cms.ajax.beforeRunHandler` event; ThemeAjaxHandler subscribes here to intercept `Metapixel::onFireEvent`.
- `vendor/larajax/larajax/` — Larajax transport already installed by host; theme uses `jax.ajax('Metapixel::onFireEvent', {data:{...}})` shape.
- `plugins/lovata/toolbox/classes/event/ModelHandler.php` — base for Watcher subscribers if pattern fits (Phase 3 may extend; OrderStatusWatcher could ship as plain Eloquent-event subscriber instead — planner decides).

### Forward-reference (read for context, do NOT implement in Phase 3)

- `.planning/REQUIREMENTS.md` §"Multisite + Settings rework (Phase 4)" — Phase 3 ships `Settings::lookupForSite()` consumer side; Multisite trait + per-site rows land Phase 4.
- `.planning/REQUIREMENTS.md` §"TrustedHosts + php-domain-parser (Phase 4)" — Phase 3 EventPixel renders within trusted-host context but does not gate; Phase 4 generalises EnsureFbpFbcCookies.
- `.planning/REQUIREMENTS.md` §"FailedEvents backend audit (Phase 4)" — FAIL-01..03 admin UI replays FailedEvents written by Phase 3 dispatches (Phase 3 ensures subject_type + subject_id populate on every non-binding-error failure path).
- `.planning/REQUIREMENTS.md` §"Translations (Phase 4)" — Phase 3 reserves `lang.settings.theme_custom_event_names.*` keys; en/lv values populate Phase 4.

### Tooling deltas (Phase 3 reopens)

- `phpstan.neon` — append `classes/adapter/shopaholic` + `classes/adapter/theme` to `disallowed-calls.disallowIn` deny-list for the SiteManager/Request ban; EXCLUDE `classes/adapter/theme` from the deny-list (D-16 documented exception). Append `classes/event/adapter/shopaholic` to `disallowIn` as well.
- `composer-dependency-analyser.php` — restrict `Lovata\OrdersShopaholic\*` imports to `classes/adapter/shopaholic/` + `classes/event/adapter/shopaholic/` directories only.
- `phpunit.xml` — add `<directory>./components</directory>` to `<source><include>` (EventPixel + PixelHead); add `<directory>./console</directory>` (PurgeEventLog command); add `tests/Contract/Adapter/Shopaholic` + `tests/Contract/Adapter/Theme` + `tests/Feature/Adapter/Shopaholic` + `tests/Feature/Adapter/Theme` test directories.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets (already shipped Phase 2)

- `Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry` (`classes/adapter/AdapterRegistry.php`) — singleton bound in `Plugin::register()`. Phase 3 `Plugin::boot()` registers two Shopaholic adapter classes conditionally + the ThemeActionAdapter unconditionally.
- `Logingrupa\Metapixel\Classes\Queue\SendCapiEvent` (`classes/queue/SendCapiEvent.php`) — 4-arg constructor takes `(string $sEventName, array $arPayload, object $obSubject, string $sAdapterClass)`. Reused verbatim by both Watchers and ThemeAjaxHandler.
- `Logingrupa\Metapixel\Classes\Helper\EventLogWriter` (`classes/helper/EventLogWriter.php`) — Phase 3 changes the signature to accept `array $arPayload` trailing arg. INSERT IGNORE race-fence stays. Three call sites: SendCapiEvent::handle (current), Watcher pre-dispatch dedup pre-check (new Phase 3 Watcher logic), EventPixel onMarkFired AJAX (new Phase 3 Theme component).
- `Logingrupa\Metapixel\Classes\Helper\SiteResolver::forSubject` — Shopaholic dispatch path uses it directly. Theme path bypasses (D-15 exception — fallback to Site::getCurrent()).
- `Logingrupa\Metapixel\Classes\Meta\PayloadBuilder::buildEventPayload` — event-agnostic envelope build. Phase 3 adapters supply event-specific overrides via `$arEventExtras` (D-21 from Phase 2).
- `Logingrupa\Metapixel\Classes\Meta\MetaClient::sendForPixel` — per-call credentials, Graph API v23.0 pinned. No Phase 3 changes.
- `Logingrupa\Metapixel\Classes\Testing\EventSubjectAdapterContractTestCase` — abstract contract base; Phase 3 adapter contract tests inherit.
- `Lovata\Toolbox\Models\CommonSettings` — `Settings` extends this; Phase 3 adds `theme_custom_event_names` textarea field + sanitization in `beforeSave()` model event.
- `Lovata\Toolbox\Classes\Event\ModelHandler` — base for any model-event subscriber. OrderStatusWatcher + CartPositionWatcher MAY extend it OR ship as plain `Event::listen('eloquent.updated: …')` subscribers. Planner decides based on whether Toolbox base adds value (cache invalidation + subscribe-helper) for this case.
- Phase 1 toolchain — Pest 4 + spaze/phpstan-disallowed-calls + composer-dependency-analyser + Pint + Rector. All Phase 3 code passes `composer qa` end-to-end.

### Established Patterns

- **Adapter-dir Lovata import isolation:** `composer-dependency-analyser.php` restricts `Lovata\OrdersShopaholic\*` to `classes/adapter/shopaholic/` only (P-03). Phase 3 extends the rule to also permit `classes/event/adapter/shopaholic/` (Watchers + their subscribe binding files).
- **Watcher contract:** subscribe binds `eloquent.updated|created`; handler is `≤ 70 LOC`; one responsibility. v1.x's `OrderStatusWatcher` (367 LOC, multi-concern) is the anti-pattern reference — Phase 3 fresh code keeps Watchers below 70 LOC each. Tiger-Style fail-fast on any payload-build failure: log + return (do NOT rethrow — would cascade-break Order::save in Shopaholic).
- **Final classes by default** — adapters, value resolvers, watchers, components, console commands. `final` everywhere unless designed-for-extension.
- **Service-container singletons** — `AdapterRegistry` bound in `Plugin::register()`; tests rebind via `$this->app->instance(AdapterRegistry::class, fresh)` for isolation. Phase 3 Watcher unit tests follow Phase 2 H-8 setUp pattern (NEVER `new Plugin()` bare).
- **Hungarian notation** — every Phase 3 file uses `$obOrder`, `$obCartPosition`, `$obAdapter`, `$arPayload`, `$sEventName`, `$iSiteId`. PHPMD `ShortVariable min=4` enforced.
- **Lowercase folder + PascalCase namespace** — `classes/adapter/shopaholic/ShopaholicOrderAdapter.php` (file path lowercase folder, PascalCase basename). October Rain ClassLoader autoload constraint (Phase 2 plan 02-01).
- **Migration PascalCase basenames** — `add_payload_to_metapixel_event_log_table.php` snake_case is INCORRECT for FQN-loadable updates. Phase 3 migration follows PascalCase H-5 pattern from Phase 2 — `AddPayloadToMetapixelEventLogTable.php`.
- **Larastan + universalObjectCrates** — already covers `Lovata\Toolbox\Classes\Item\ElementItem` + `ElementCollection`. Phase 3 ShopaholicCartPositionValueResolver may iterate `OrderPositionCollection` / `OfferItem` — covered.

### Integration Points

- `Plugin::boot()` — Phase 3 adds:
  ```php
  if (PluginManager::instance()->exists('Lovata.OrdersShopaholic')) {
      AdapterRegistry::instance()->register(\Lovata\OrdersShopaholic\Models\Order::class, ShopaholicOrderAdapter::class);
      AdapterRegistry::instance()->register(\Lovata\OrdersShopaholic\Models\CartPosition::class, ShopaholicCartPositionAdapter::class);
      Event::subscribe(OrderStatusWatcher::class);
      Event::subscribe(CartPositionWatcher::class);
  }
  AdapterRegistry::instance()->register(ThemeActionEvent::class, ThemeActionAdapter::class);
  Event::subscribe(ThemeAjaxHandler::class);
  ```
- `Plugin::registerComponents()` — NEW Phase 3 method. Registers `pixelHead` + `eventPixel` components.
- `Plugin::registerMarkupTags()` — NEW Phase 3 method. Adds `this.metapixel.pushEvent($arEvent)` Twig helper backed by ThemeEventCollector.
- `Plugin::registerSchedule(Schedule $obSchedule)` — NEW Phase 3 method. Wires `metapixel:purge-event-log` to daily run.
- `Plugin::register()` — Phase 3 binds `ThemeEventCollector` as request-scoped singleton via `$this->app->singleton(ThemeEventCollector::class)`.

</code_context>

<specifics>
## Specific Ideas

- **No fork ever, two extension paths instead.** D-13 + D-15 combine: non-dev operator adds custom event names via Settings textarea; savvy dev writes own adapter via `AdapterRegistry::register` from their `Plugin::boot()` (Phase 2 extensibility ranks 1–6 in CLAUDE.md). Marketplace anti-fork stance is the project's strongest hard-constraint.
- **Frozen-payload audit > re-resolve.** User explicitly weighed performance + third-party-author burden + post-paid mutation determinism and locked option 2 (EventLog.payload column + 7-day TTL purge). Industry parallel: Snowplow atomic.events shape.
- **SRP at file level, polymorphic dedup at table level.** Two shopaholic adapters in one dir > one mega-adapter with type-switching; single EventLog table with subject_type opaque alias > per-event-name tables. The split-file/share-table combo is the SRP+DRY anchor.
- **One documented P-01 exception (ThemeAdapter only).** Theme events are request-bound by definition; forcing operator to pass site_id on every Twig call site is anti-DX. PHPStan deny-list config splits by sub-directory to encode this asymmetry.
- **Reviewer smell test:** any Phase 3 file >100 LOC gets challenged. Watchers <70 LOC, Adapters <80 LOC, ValueResolvers <100 LOC, EventPixel + PixelHead <120 LOC each, ThemeAjaxHandler <150 LOC including 18-name allowlist constant.

</specifics>

<deferred>
## Deferred Ideas

- **FailedEvents admin UI + Replay + CheckDedup** — Phase 4 (FAIL-01..03). Phase 3 ensures FailedEvent.subject_type + subject_id populate on every non-BindingResolutionException failure path; Phase 4 reads these for re-resolve.
- **Multisite trait on Settings::pixel_id + capi_access_token** — Phase 4 (MULT-01..06). Phase 3 calls `Settings::lookupForSite($iSiteId)` (Phase 2 stub returns default row regardless of $iSiteId); Phase 4 implements per-site row routing without changing the public signature.
- **TrustedHosts + jeremykendall/php-domain-parser** — Phase 4 (HOST-01..06). Phase 3 EventPixel + theme cookie writes operate within the active request host; Phase 4 generalises EnsureFbpFbcCookies for marketplace operators.
- **Translations** — Phase 4 (LANG-01). Phase 3 reserves `lang.settings.theme_custom_event_names.label` + `.commentAbove` + `lang.console.metapixel_purge_event_log.*` keys; en/lv values populate Phase 4.
- **Lead event server-side adapter** — neither Phase 3 first-party. Operator-supplied via ThemeAdapter Twig API (`pushEvent({name:'Lead', ...})` from custom form `onSubmit` handler). Server-side first-party Lead adapter (e.g. RenatioFormBuilder integration) deferred to v2.1.
- **MallAdapter + MeloncartAdapter** — v2.1 (MALL-01 + MELON-01). Phase 3 contract test base (extended from Phase 2 D-11) shapes the path; v2.1 ships concrete adapters.
- **Additional Event::fire hooks** (adapter.resolve, value.resolve, user_data.resolve, pixel.before_render, settings.lookup) — v2.1 (EXT-01..05). Phase 3 sticks with the 3 hooks shipped Phase 2.
- **Debug / Test-Events backend panel** — v2.1 (DBG-01). Phase 3 EventLog table + 7-day TTL purge supplies the data shape; v2.1 ships the UI.
- **Auto PSL refresh cron** — v2.x (PSL-01). Phase 4 manual `metapixel:refresh-psl` artisan; v2.x adds operator-opt-in scheduler.
- **ThemeAjaxHandler rate-limit configurability** — Phase 3 ships fixed default (30 req / 60 s per IP+session). If operator demand surfaces, Phase 4 may add a Settings field. Out of Phase 3 scope.
- **EventPixel + PixelHead coexistence migration doc** — Phase 5 README documents the cutover for operators with a legacy `partials/facebook_pixel.htm` ad-hoc include.

</deferred>

---

*Phase: 03-shopaholicadapter-themeactionadapter-parallel-wave*
*Context gathered: 2026-05-18*
