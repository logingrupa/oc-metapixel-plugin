# Feature Landscape — Logingrupa.Metapixel v2.0

**Domain:** Generic Meta Pixel + Conversions API tracking plugin for OctoberCMS (marketplace-grade)
**Researched:** 2026-05-15
**Mode:** Ecosystem (Project Research)
**Confidence:** HIGH for adapter event surfaces and cart-plugin shapes (verified against upstream docs/source); MEDIUM for marketplace-buyer expectations (extrapolated from WordPress/WooCommerce competitors — no comparable OctoberCMS tracking plugin exists in the marketplace to benchmark against directly)

---

## Executive Summary

v2.0 is entering a **near-empty competitive lane** on OctoberCMS. The marketplace lists no
first-party Meta Pixel + CAPI plugin in the e-commerce category — only generic Facebook-SDK
helpers (`algad-facebook`, `kenshin-facebook`) that do not ship Pixel, CAPI, or dedup logic.
Three viable cart ecosystems exist (Shopaholic 11k+ installs, OFFLINE Mall 11,783 installs,
Meloncart paid first-party launched in v4.2 with 50+ event hooks). Each ships **distinct event
names and Order shapes** — confirming the adapter-pattern bet.

The feature set splits cleanly into:

- **Table stakes:** what every marketplace buyer expects when paying $0–$199 (adapter registry,
  ShopaholicAdapter, ThemeActionAdapter, Settings UI, FailedEvents backend, README, install < 10 min)
- **Differentiators:** what beats PixelYourSite-class WordPress competitors and current OC stack
  (debug/test-events panel, dedup verification table, per-site Multisite Settings, custom-adapter
  authoring guide, dual PHP 8.3/8.4 support, EventLog UNIQUE race-fence audit trail)
- **Anti-features:** WordPress-plugin feature creep (GA4, GTM, Pinterest, TikTok multi-pixel
  routing into a single plugin) that bloats scope and dilutes the value proposition

The v1.x primitives (`MetaClient`, `PayloadBuilder`, `UserDataHasher`, `EventLog`, `FailedEvent`,
`PluginGuard`, `SiteResolver`, `EnsureFbpFbcCookies`) all carry forward — v2.0 is a refactor
above those primitives plus a new adapter layer plus marketplace polish, NOT a rewrite.

---

## Adapter-Pattern Feature Boundary (lock-in)

| Adapter | Recommendation | Rationale |
|---|---|---|
| **ShopaholicAdapter** | SHIP in v2.0 | Powers nailscosmetics.* live sites; port from v1.x. Non-negotiable. |
| **ThemeActionAdapter** | SHIP in v2.0 | Generic Twig + Larajax wiring. Operators without a supported cart get value. |
| **MallAdapter** (OFFLINE Mall) | DEFER| 11,783  installs; clean `mall.cart.*` + `mall.checkout.*` event surface verified. Largest non-Shopaholic install base in OC e-commerce. |
| **MeloncartAdapter** | DEFER to v3 | Paid plugin ($199), launched 2026 in OC v4.2, smaller install base, untested in production. Document the adapter interface so it CAN be added by third parties or in v2.1. |
| **MicroCartAdapter** | DEFER | OFFLINE MicroCart is intentionally minimal — ThemeActionAdapter covers its surface. |

**Net:** four adapters in v2.0: Shopaholic + ThemeAction + a base abstract that proves
the registry. 

---

## Table Stakes — must-ship marketplace baseline

Features that a buyer paying for or installing a Meta Pixel + CAPI plugin will expect on day one.
Missing any of these means "product feels incomplete" and reviews tank.

| Feature | Why Expected | Complexity | v1.x Dependencies | Notes |
|---|---|---|---|---|
| **AdapterRegistry::register(EventSubjectAdapter)** | Core extensibility contract. Without it the plugin is "Shopaholic-coupled v1.x with a rename." | MED | NEW | Singleton bound in plugin service container. Idempotent registration. Lazy boot. |
| **EventSubjectAdapter interface** | Marketplace cart-plugin authors need a stable contract to target. | LOW | NEW | `getSubjectType(): string`, `extractUserData($ob): array`, `extractContents($ob): array`, `extractValue($ob): float`, `extractCurrency($ob): string`, `getEventTimestamp($ob): int`. |
| **ValueResolver interface (per-event)** | Decouples Purchase value-resolution from cart-specific tax/discount/shipping math. | LOW | NEW (extracts logic from v1.x PayloadBuilder) | Each adapter ships its own resolver; plugin core never branches on `instanceof Order`. |
| **ShopaholicAdapter** | nailscosmetics.* live + 11k installs in upstream Shopaholic. Required for non-regression on operator sites. | MED | OrderStatusWatcher + PayloadBuilder + EventLogWriter + SiteResolver (all v1.x) | Listens on Status `new-payment-received`, extracts CartPosition[]/OrderPosition[] uniformly. SiteResolver::forOrder unchanged. |
| **MallAdapter (OFFLINE Mall)** | 11,783 installs, active development, well-documented event API. | MED | Reuses MetaClient + PayloadBuilder + EventLogWriter (no cart-plugin specifics) | Hooks: `mall.checkout.succeeded` (Purchase), `mall.cart.product.added` (AddToCart), `mall.order.payment_state.changed` (status flip → Purchase if `paid`). Polymorphic subject_type `'mall.order'`. |
| **ThemeActionAdapter (Twig + Larajax API)** | Operators without a supported cart still need to track PageView, ViewContent, Lead, custom events without writing PHP. | MED | Reuses PixelHead component + MetaClient | Twig API: `{% set arMetaEvent = { name: 'Lead', value: 0, ...} %}` inside partial; Larajax handler `metapixel::onTrackEvent` posts arbitrary `{event_name, custom_data}` server-side. Operator wires from any theme partial. |
| **Per-site Settings via Multisite trait** | OctoberCMS v4 multisite is first-class; buyers running .no/.lv/.lt need per-site Pixel IDs. | MED | Settings model (v1.x) | Add `use \October\Rain\Database\Traits\Multisite;` + `$propagatable = [];` on Settings. Operator-supplied `pixel_id` + `capi_access_token` override per site row. Falls back to global if site row null. |
| **TrustedHosts allowlist (operator-supplied)** | v1.x hardcoded HOST_INDEX_MAP for nailscosmetics.* won't fly for marketplace. | LOW | `EnsureFbpFbcCookies` middleware (v1.x) | Settings field: textarea, one host per line. Optional bundle of `jeremykendall/php-domain-parser` for multi-TLD index derivation. Empty allowlist = log warning + cookie middleware disabled (never throw). |
| **FailedEvents backend list (Replay button)** | Buyers expect to see why CAPI failed and recover from transient failures. | MED | FailedEvent model + SendCapiEvent job (v1.x) | Lovata-style `Backend\Behaviors\ListController` on `controllers/FailedEvents`. Columns: timestamp, subject_type, subject_id, event_name, error_class, error_message, attempts. Toolbar: "Replay selected" → re-dispatches `SendCapiEvent::dispatch()`. |
| **Composer suggest pattern** | Plugin must install on OctoberCMS WITHOUT Shopaholic, Mall, or any cart plugin. | LOW | composer.json (v1.x) | Move `lovata/shopaholic-plugin`, `offline/oc-mall-plugin`, `meloncart/meloncart-plugin` (if real) from `require` to `suggest`. Plugin boots in "ThemeActionAdapter only" mode. |
| **PluginGuard empty-config policy** | v1.x already enforces: missing `pixel_id` → `Log::warning` + disabled flag. Re-derive verbatim for v2.0. | LOW | PluginGuard (v1.x) | Never throw at boot. Boundary throws at event-dispatch time. |
| **README.md install guide (< 10 min)** | Marketplace gold standard. Without it nobody completes setup. | MED | NEW | Required sections: prerequisites, `composer require`, Settings walkthrough (Pixel ID + CAPI token + Test Event Code acquisition), per-cart adapter activation, first-event verification via Meta Test Events tool, troubleshooting. |
| **lang/{en,lv,ru}/lang.php** | Lovata ecosystem norm. Settings labels and FailedEvents columns must be translatable. | LOW | v1.x lang structure | en + lv + ru baseline; extensible to other locales via standard OC i18n. |
| **CI green on dual install paths** | Buyer trust. Plugin must `composer qa` PASS both with and without Shopaholic. | MED | GitHub Actions workflow (v1.x) | Matrix: PHP 8.3 + 8.4 × {with Shopaholic, without Shopaholic}. Pest 4 integration tests assert ThemeActionAdapter works in "no cart plugin" mode. ≥ 90% coverage. |
| **Plugin manifest + namespace rename** | Generic name signals generic capability. `Logingrupa.Metapixelshopaholic` → `Logingrupa.Metapixel`. | LOW | Plugin.php + plugin.yaml | composer package `logingrupa/oc-metapixel-plugin`. Backwards-compat alias for v1.x DB rows is NOT required (legacy frozen on branch). |
| **Marketplace listing assets** | Standard buyer expectation: icon, screenshots, description, changelog. | LOW | Plugin assets dir | At minimum: 1 plugin icon, 3 screenshots (Settings, FailedEvents list, Meta Test Events confirming dedup). version.yaml-driven changelog. |

**Total table-stakes count:** 15 features. All have v1.x primitives backing them or are
low-complexity additions. None require external services or new architectural concepts beyond
the adapter contract.

---

## Differentiators — beats competitor plugins

Features that distinguish this plugin from PixelYourSite-class WordPress competitors AND from
the (currently empty) OctoberCMS Meta Pixel category. Not expected — but valuable enough to
mention on the marketplace listing's first paragraph.

| Feature | Value Proposition | Complexity | v1.x Dependencies | Notes |
|---|---|---|---|---|
| **Built-in Debug/Test-Events panel** | Buyers don't need to leave OctoberCMS backend to verify Pixel + CAPI dedup. Meta's own Test Events tool requires switching between Events Manager and your site. | HIGH | NEW (builds on EventLog) | Backend page: "Last 100 events" table from `logingrupa_metapixel_event_log` + sister table for outgoing-payload audit. Shows: event_id, channel (pixel vs capi), event_name, subject, status (sent/dead-lettered/queued), payload preview. Filter by site. PixelYourSite-equivalent feature. |
| **Dedup verification view** | Buyers can answer "is my Pixel + CAPI actually deduping?" without setting up Meta Events Manager. Plugin shows the same event_id firing on both channels. | MED | EventLog UNIQUE race-fence (v1.x) | Same Debug panel: group EventLog rows by `(subject_type, subject_id, event_name)`, show 2-channel coverage. Red badge if Pixel fires without CAPI or vice versa. |
| **Per-site multi-pixel routing** | Multi-site operators (us!) and agency users running multiple brands from one OctoberCMS install can route events to different Pixels per site row. v1.x already SiteResolver-aware; v2.0 surfaces it in UI. | MED | SiteResolver::forOrder + Settings (v1.x) | Multisite trait on Settings → each `Site` gets its own `pixel_id` + `capi_access_token`. No code changes to MetaClient; it reads from Settings at dispatch time. |
| **Custom-adapter authoring guide** | Third-party cart plugins (or operators with custom carts) can write their own adapter without forking the plugin. WordPress competitors require monkey-patching. | MED | NEW (docs only — code surface already covered by registry) | `README.md` or `docs/CUSTOM-ADAPTERS.md`: example WooCommerce-style adapter for a hypothetical cart, step-by-step `AdapterRegistry::register()` call from third-party `Plugin::boot()`, contract for value-resolution + content-id derivation. Working code example. |
| **Dual PHP 8.3 + 8.4 support** | Live OC sites span PHP versions. Pinning 8.4 only locks out half the buyer base. | LOW (avoid 8.4-only syntax) | composer.json + CI matrix | composer.json `"php": "^8.3 \|\| ^8.4"`. No property hooks, no asymmetric visibility, no `array_find`/`any`/`all`, no `#[\Deprecated]`. CI matrix tests both. |
| **EventLog UNIQUE race-fence as marketing point** | "Survives status flip-flops, queue retries, dual-server races without double-firing Purchase." This is a Phase 3.1 hardening that none of the WordPress competitors document; lead with it. | LOW (already shipped) | EventLog model + UNIQUE constraint (v1.x) | README "Why this plugin" section. Zero code work. |
| **Server-side event_id (UUIDv4) propagated to frontend** | Solves the "server and browser disagree on event_id" footgun that 90% of WP plugins get wrong. v1.x already enforces server → frontend direction; v2.0 surfaces it as a differentiator. | LOW (already shipped) | UUIDv4 generation + PixelHead component (v1.x) | README "Dedup contract" section + architecture diagram. |
| **`_fbp` / `_fbc` cookie middleware** | Most plugins (WordPress and otherwise) assume Pixel writes these cookies. Pixel often doesn't (ad-blocker, slow script load, iOS Safari). This middleware writes them server-side — the same fix that closed live nailscosmetics.lv bug in v1.x. | LOW (already shipped) | EnsureFbpFbcCookies middleware (v1.x) | README "Why CAPI match quality stays > 8" section. v2.0 generalizes HOST_INDEX_MAP to operator allowlist. |
| **Larastan level 10 + PHPMD Toolbox + Pest 4 coverage badge** | Marketplace buyers reading composer.json or README will see this. Signals operator-grade maintenance. | LOW (already shipped) | Tooling (v1.x Phase 1) | README badges. |
| **Per-adapter setup walkthroughs** | Each adapter (Shopaholic, Mall, ThemeAction) gets its own < 5-minute setup section. | MED | NEW (docs) | `docs/adapters/shopaholic.md`, `docs/adapters/mall.md`, `docs/adapters/theme-action.md`. Each: minimal Plugin.php snippet + Twig snippet + verification step. |

**Total differentiator count:** 10. Half are zero-code (surface existing v1.x as marketing
points in README); half need new work but extend existing primitives.

---

## Anti-Features — explicitly NOT building in v2.0

Features that would dilute the value proposition, blow scope, or duplicate competitor
positioning. Listed so the requirements step doesn't accidentally adopt them.

| Anti-Feature | Why Avoid | What to Do Instead |
|---|---|---|
| **GA4 / Google Tag Manager / Pinterest / TikTok pixel routing in one plugin** | This is PixelYourSite's bloat. Sub-optimal for any single channel and impossible to test exhaustively. Marketing complexity > engineering complexity. | Ship Meta-only. Document that operators wanting GA4 can run a second tracker plugin alongside. v2.x could spawn `logingrupa/oc-ga4-plugin` reusing the same adapter contract. |
| **GDPR / cookie-consent banner integration** | Live theme has no banner. Carrying forward from v1.x out-of-scope decision. Banner integration is theme-specific, not plugin-specific. | Document that operators are responsible for cookie consent. Plugin honours a single `consent_given` Settings toggle OR a documented `Event::listen('metapixel.event.before_dispatch', fn() => false)` hookpoint. Compliance = operator concern. |
| **Dynamic Product Ads (DPA) catalog export** | Already shipped by `logingrupa/facebookcatalogshopaholic` on nailscosmetics.lv. Out of scope. | Document compatibility (same `content_ids` format). Operators run both plugins. |
| **Slack / email / Telegram dead-letter alerting** | Carrying forward from v1.x DROPPED OPS-01..03. Operations channel concern, not Pixel concern. | FailedEvents backend list + `Log::error` is enough. v2.x add Settings dropdown if operator demand emerges. |
| **Built-in A/B testing or custom-audience builder** | Meta Ads Manager already does this. Duplicating it = bait-and-switch. | Stay in lane: track events accurately; let Ads Manager handle audiences. |
| **Plugin-internal dashboard with charts/conversion-rate trends** | Same as above. Operator pays for Ads Manager. Charts in a plugin = stale data + maintenance burden. | Debug panel only (last-100-events log + dedup verification). No aggregation. |
| **Pixel + CAPI for non-Meta channels (LinkedIn, Twitter, Snapchat)** | Each has its own SDK, its own dedup model, its own event schema. Different plugin. | Document in README scope statement. |
| **Subclassing upstream Order / Cart models** | Violates CLAUDE.md architecture rules. Breaks on upstream version bumps. Already proven painful in v1.x. | Adapter pattern uses `Model::extend()` + `addDynamicMethod()` + `Event::listen()`. NEVER `extends Order`. |
| **Re-implementing Lovata Item/Collection/Store for our own models** | EventLog + FailedEvent are admin-only audit logs. Frontend never touches them. Item/Collection cost > value. | Keep `October\Rain\Database\Model` plain. Documented in v1.x. |
| **Custom Graph API version other than v20** | Carrying forward from v1.x. Single pinned version = predictable. | Bump when Meta forces it. Operator override Setting if absolutely needed. |
| **`declare(strict_types=1)` enforcement** | Zero ecosystem usage in Lovata/Logingrupa codebase. Carrying forward from v1.x. | Optional per-file. Larastan level 10 catches most type issues anyway. |
| **`assert()` usage anywhere** | Prod `zend.assertions=0` makes `assert()` a silent no-op. Enforced via `spaze/phpstan-disallowed-calls`. | Explicit `throw` at boundaries. |
| **CampaignpricingShopaholic pricing-tier integration** | Carrying forward from v1.x DROPPED CAT-01. Adapter pattern means per-cart value-resolution; campaign math lives in ShopaholicAdapter's ValueResolver. | If campaign tiers matter for nailscosmetics, ship in ShopaholicAdapter as the only adapter that knows about them. Other adapters unaffected. |

---

## Feature Dependencies

```
AdapterRegistry  ← required by all four adapters
    ↓
EventSubjectAdapter interface  ← required by all four adapters
ValueResolver interface         ← required by all four adapters
    ↓
ShopaholicAdapter ←─── reuses OrderStatusWatcher + SiteResolver + PayloadBuilder + UserDataHasher (v1.x)
MallAdapter      ←─── reuses MetaClient + EventLogWriter + UserDataHasher (NEW: maps OFFLINE Mall Order/Cart → contracts)
ThemeActionAdapter ─── reuses PixelHead component + MetaClient (NEW: Larajax handler + Twig API)

EventLog (v1.x) ← required by all adapters for race-fence idempotency
FailedEvent (v1.x) ← required by SendCapiEvent for dead-letter
SendCapiEvent (v1.x) ← required by all adapters for outbound CAPI

PluginGuard (v1.x) ← required by all adapters at dispatch boundary
SiteResolver (v1.x) ← required for per-site multi-pixel routing
Settings (v1.x + Multisite trait) ← required for per-site routing UI

EnsureFbpFbcCookies (v1.x) ← required for CAPI user-match baseline
TrustedHosts allowlist Setting ← required by EnsureFbpFbcCookies (NEW Setting field)

FailedEvents backend list controller ← required by replay UX (NEW)
Debug/Test-Events panel ← required by differentiator story (NEW, builds on EventLog)
README + custom-adapter authoring guide ← required by marketplace launch (NEW)
CI matrix (PHP 8.3/8.4 × with/without cart plugin) ← required for dual-install confidence (extends v1.x workflow)
```

---

## Cart-Plugin Event Surfaces (verified — informs adapter design)

### Shopaholic (live, v1.x covers it)

| Stage | Hook | Used By v1.x |
|---|---|---|
| Order paid | `eloquent.afterUpdate` on `Lovata\OrdersShopaholic\Models\Order` (Status FK to `new-payment-received` ID=5) | OrderStatusWatcher (v1.x) → Purchase |
| Cart add | `shopaholic.cart.add` (only native cart event) | Not wired in v1.x; v2.0 ShopaholicAdapter or ThemeActionAdapter |
| Other | `Component::extend` + `addDynamicMethod` fallbacks (cart update/remove have no native events) | v1.x avoided; v2.0 keeps avoiding |

### OFFLINE Mall (verified via docs)

| Stage | Hook | v2.0 MallAdapter Maps To |
|---|---|---|
| Cart add | `mall.cart.product.added` | AddToCart |
| Cart remove | `mall.cart.product.removed` | (custom event or unmapped) |
| Cart quantity change | `mall.cart.product.quantityChanged` | (custom event or unmapped) |
| Checkout success | `mall.checkout.succeeded` | Purchase (fired immediately if paid; else wait on payment_state) |
| Payment state flip | `mall.order.payment_state.changed` | Purchase if new state = `paid` |
| Customer signup | `mall.customer.afterSignup` | CompleteRegistration |

**Mall Order shape (verified):** `total_post_taxes` (use for Purchase value), `currency` (string),
`order_products` (one-to-many line items, each with product_id/variant_id/quantity/price),
`customer` (one-to-many; hashed for user_data), `billing_address` + `shipping_address`,
`session_id` + `payment_hash` (for anonymous external_id fallback).

### Meloncart (verified via docs — DEFERRED to v2.1)

| Stage | Hook | v2.1 MeloncartAdapter Would Map To |
|---|---|---|
| Order create | `shop.beforeCreateOrderRecord` / `shop.beforeUpdateOrderRecord` | Bookkeeping only |
| Order paid | `shop.order.orderPaid` | Purchase |
| New order | `shop.newOrder` | (not Purchase — wait for paid) |
| Order status update | `shop.beforeUpdateOrderStatus` | Status-aware Purchase trigger (mirror v1.x Shopaholic pattern) |
| Cart add | `shop.cart.addProduct` | AddToCart |
| Cart remove | `shop.cart.afterRemoveItem` | (custom event) |
| Cart quantity change | `shop.cart.setQuantity` | (custom event) |
| Checkout coupon | `shop.checkout.beforeSetCouponCode` | (custom event) |

**Meloncart caveat:** Order model + cart structure not documented in the materials reviewed.
The 50+ event count is comparable to Mall's ~25, suggesting fine-grained extensibility — but
the v2.0 ship-list will not block on a paid-plugin adapter. Document the EventSubjectAdapter
interface so a third-party (or Meloncart's authors) can write it.

### ThemeActionAdapter (NEW, generic)

| Stage | Hook | Maps To |
|---|---|---|
| PageView | `cms.page.init` event OR PixelHead component on layout | PageView (browser-only — already shipped in v1.x via PixelHead) |
| ViewContent (PDP) | Twig `{% set arMetaEvent = {name:'ViewContent', content_ids:[...], value:..., currency:...} %}` in product page partial | ViewContent (browser + optional CAPI) |
| Lead form submit | Larajax POST to `metapixel::onTrackEvent` from form handler | Lead (CAPI) |
| Custom event | Same Larajax handler with `{name: 'YourCustomName'}` | Custom (fbq trackCustom + CAPI custom) |

**ThemeActionAdapter design point:** the Larajax handler is the ONLY new HTTP surface this
plugin exposes. Everything else hangs off OctoberCMS Event::subscribe + Component::extend.
Single handler signed/CSRF-protected via OC's built-in token middleware.

---

## MVP Recommendation (v2.0 baseline ship)

Prioritise in this order. Each block is testable independently and shippable independently if
scope slips.

1. **Adapter contracts** (LOW–MED): `EventSubjectAdapter`, `ValueResolver`, `AdapterRegistry`. No
   adapter implementations yet. Pest tests assert registry round-trip + boot-time auto-detection.
2. **ShopaholicAdapter** (MED): Port v1.x OrderStatusWatcher behind the contract. Non-regression
   gate — nailscosmetics.* must keep firing Purchase identically.
3. **ThemeActionAdapter** (MED): Larajax handler + Twig API + PixelHead reuse. Operator can ship
   Meta Pixel without any cart plugin installed.
4. **Settings rework + TrustedHosts allowlist** (LOW–MED): Multisite trait, operator-supplied
   trusted_hosts. v1.x HOST_INDEX_MAP retired.
5. **FailedEvents backend list + Replay button** (MED): Lovata-style ListController. Replay
   re-dispatches SendCapiEvent.
6. **MallAdapter** (MED): Wire `mall.checkout.succeeded` + `mall.cart.product.added`. Smaller
   surface than Shopaholic (no per-status Purchase trigger logic — Mall fires `succeeded`
   directly).
7. **README + custom-adapter authoring guide** (MED): Cannot launch to marketplace without
   this. Time-boxed to two days max.
8. **CI matrix (PHP 8.3/8.4 × with/without Shopaholic)** (MED): Composer suggest validation.
9. **Debug/Test-Events panel** (HIGH — could defer to v2.0.1 if scope slips): Differentiator,
   not table-stake. Ship if time allows; otherwise market the EventLog DB table + a Test
   Events Code Settings field that lets operators use Meta's own tool.
10. **Plugin manifest rename + composer package rename** (LOW): Final step, gates v2.0 tag.

**Defer to v2.1:**
- MeloncartAdapter (paid plugin, smaller install base, untested in this codebase)
- Slack/email dead-letter alerting (v1.x DROPPED OPS-01..03)
- CampaignpricingShopaholic pricing tiers (DROPPED CAT-01)

---

## Marketplace-Buyer Expectations (extrapolated — MEDIUM confidence)

No comparable OctoberCMS Meta Pixel plugin exists to benchmark against. Below extrapolated from
WordPress (PixelYourSite, Meta for WooCommerce, Pixel Cat) and from OctoberCMS marketplace
norms (Mall, Shopaholic, Meloncart README/docs structure).

**Buyer's < 10-minute success path:**

1. `composer require logingrupa/oc-metapixel-plugin` (or marketplace one-click install)
2. Activate plugin in backend
3. Settings → Meta Pixel → paste Pixel ID + CAPI Access Token + (optional) Test Event Code
4. Activate adapter for installed cart (or use ThemeActionAdapter)
5. Add `{% component 'metaPixelHead' %}` to layout (or theme already has it via PixelHead)
6. Open Meta Events Manager → Test Events tab → see browser + CAPI events firing with same event_id
7. (Optional) Open plugin Debug panel → see same events from inside OctoberCMS backend

If any of these steps take more than two clicks or two minutes, abandon rate climbs. README
must walk through every step with screenshots.

**Buyer's red-flag list (avoid):**

- No README, README in only one language, README with broken links
- Settings form that crashes on empty save (PluginGuard already prevents this for v1.x)
- No FailedEvents UI ("where did my event go?")
- No translatable strings (Lovata ecosystem norm requires en/lv/ru)
- Composer require fails because of hard `lovata/*` requires
- composer qa not green out of the box
- No mention of dual-channel dedup contract (every WP competitor advertises this)
- Pixel ID format validation throwing fatals at backend save (Settings must be tolerant; PluginGuard handles dispatch-time validation)

---

## Sources

### OctoberCMS marketplace + cart plugins
- [OctoberCMS plugins/e-commerce category](https://octobercms.com/plugins/e-commerce) — confirmed Mall, Shopaholic, Meloncart, MicroCart as the four shipping carts; no first-party Meta Pixel plugin in the category. **HIGH**
- [eCommerce for October CMS v4.2 is here!](https://octobercms.com/blog/post/ecommerce-for-october-cms-here) — Meloncart launch announcement, 50+ event hooks. **HIGH**
- [media1.ee: October CMS v4.2 Release Meloncart](https://media1.ee/en/blog/october-cms-v42-release-and-official-ecommerce-plugin) — pricing context, multi-store support confirmed. **MEDIUM**
- [Meloncart website](https://meloncart.com/) — 50+ event hooks, custom payment/shipping/price-rule extensibility. **HIGH** (vendor site)
- [Meloncart developer/hooks/events](https://meloncart.com/developer/hooks/events) — verbatim event list (`shop.order.orderPaid`, `shop.cart.addProduct`, etc). **HIGH**
- [OFFLINE Mall plugin page](https://octobercms.com/plugin/offline-mall) — 11,783 installs, active development (3.8.36 on 2026-04-30), Gold partner. **HIGH**
- [OFFLINE Mall events documentation](https://offline-gmbh.github.io/oc-mall-plugin/development/core/events.html) — verbatim event list (`mall.checkout.succeeded`, `mall.cart.product.added`, etc). **HIGH**
- [OFFLINE oc-mall-plugin Order.php](https://github.com/OFFLINE-GmbH/oc-mall-plugin/blob/develop/models/Order.php) — Order model field list and event hooks. **HIGH**

### OctoberCMS platform docs
- [Model Settings documentation](https://docs.octobercms.com/3.x/extend/settings/model-settings.html) — SettingModel base class for per-site settings. **HIGH**
- [Multisite trait documentation](https://docs.octobercms.com/3.x/extend/database/traits.html) — `October\Rain\Database\Traits\Multisite` + `$propagatable` + `$propagatableSync`. **HIGH**
- [Multisite resources documentation](https://docs.octobercms.com/3.x/cms/resources/multisite.html) — Site Manager + sync semantics. **HIGH**
- [Extending Plugins documentation](https://docs.octobercms.com/3.x/extend/system/plugins.html) — Event::subscribe + Plugin.php boot/register conventions. **HIGH**
- [Extension Methodology](https://docs.octobercms.com/3.x/extend/extending.html) — event-driven extensibility as canonical pattern. **HIGH**
- [Marketplace developer guide](https://octobercms.com/help/guidelines/developer) — `-plugin` suffix, table prefixes, namespace conventions. **HIGH**

### Meta Pixel + CAPI contract
- [Meta Pixel Reference docs](https://developers.facebook.com/docs/meta-pixel/reference/) — standard event list, parameter shape, fbq syntax. **HIGH**
- [Meta Pixel Track Multiple Events](https://developers.facebook.com/docs/meta-pixel/guides/track-multiple-events/) — custom event syntax, 50-char name limit. **HIGH**
- [Meta Conversions API setup guide (Stape)](https://stape.io/helpdesk/documentation/how-to-set-up-meta-conversions-api) — Test Event Code workflow, EMQ scoring. **MEDIUM**

### Competitor analysis (WordPress)
- [PixelYourSite WordPress plugin](https://wordpress.org/plugins/pixelyoursite/) — feature taxonomy: API logs, Test Events integration, deduplication. **MEDIUM** (vendor / WP.org reviews)
- [PixelYourSite changelog](https://www.pixelyoursite.com/pixelyoursite-pro-change-log) — API logs permission gating, Settings UI structure. **MEDIUM**
- [Meta Pixel for WooCommerce setup guide](https://www.customerlabs.com/blog/how-to-setup-facebook-pixel-and-conversions-api-on-woocommerce/) — automatic event tracking expectations. **MEDIUM**
- [Meta Events Manager Debug Guide](https://www.conversios.io/blog/pixel-firing-no-conversions-fix/) — buyer's expected debug workflow + Test Events tool baseline. **MEDIUM**
- [seresa.io: WooCommerce marketing pixels audit](https://seresa.io/blog/marketing-pixels-tags/how-to-audit-your-woocommerce-marketing-pixels-the-15-minute-check-that-reveals-what-is-actually-firing) — what buyers check post-install. **LOW**

### Existing v1.x primitives reviewed
- `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixelshopaholic/classes/event/OrderStatusWatcher.php`
- `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixelshopaholic/classes/exception/*.php` (8 exception classes)
- `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixelshopaholic/classes/helper/{EventLogWriter,PluginGuard,SiteResolver}.php`
- `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixelshopaholic/classes/meta/{MetaClient,PayloadBuilder,UserDataHasher}.php`
- `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixelshopaholic/classes/queue/SendCapiEvent.php`
- `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixelshopaholic/middleware/EnsureFbpFbcCookies.php`
- `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixelshopaholic/models/{EventLog,FailedEvent,Settings}.php`
- `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixelshopaholic/components/{PixelHead,PurchasePixel}.php`
- `/home/forge/nailscosmetics.lv/plugins/lovata/shopaholic/Plugin.php` (extension pattern reference)
- `/home/forge/nailscosmetics.lv/plugins/lovata/ordersshopaholic/Plugin.php` (Cart, MakeOrder component surfaces)
- `/home/forge/nailscosmetics.lv/plugins/lovata/toolbox/Plugin.php` (base extensibility patterns)
