# Logingrupa.Metapixel Plugin

## Current State

**Shipped:** v1.1.1 (2026-05-14) — Shopaholic-coupled Meta Pixel + CAPI plugin. 5 phases delivered (Phase 1 Tooling, Phase 2 Skeleton+cookie, Phase 3.1 Event-log refactor, Phase 3.1-07 Multi-site symmetry, Phase 3.1-08 Cleanup). 16 of 21 plans (76%). 28 of 50 v1 requirements validated. composer qa green: 177/0 tests, 82.8% coverage. Git tag `v1.1.1` annotated local-only (operator push deferred). Legacy codebase frozen on branch `legacy/v1.1.1`.

**Phase 4 (Funnel) + Phase 5 (Hardening + launch) DROPPED** at milestone close (2026-05-15). Architecture pivot to v2.0 generic-event-tracking marketplace plugin. See [`milestones/v1.1.1-ROADMAP.md`](milestones/v1.1.1-ROADMAP.md) for full v1.1.1 archive and [`milestones/v1.1.1-REQUIREMENTS.md`](milestones/v1.1.1-REQUIREMENTS.md) for requirement outcomes.

## Current Milestone: v2.0.0 — Generic-event-tracking marketplace plugin

**Goal:** Decouple plugin from Shopaholic via Lovata-style extensible adapter pattern. Marketplace-grade Meta Pixel + Conversions API plugin sellable to any OctoberCMS operator regardless of cart-plugin. Third parties can register custom adapters without modifying plugin core. PHP 8.3 + 8.4 dual support.

**Target features:**
- **Generic core** — `MetaClient` + `PayloadBuilder` + `UserDataHasher` + `EventLogWriter` decoupled from `Order` model. Generic event envelope shape.
- **Adapter contracts** — `EventSubjectAdapter` + `ValueResolver` interfaces. `AdapterRegistry::register()` callable from any plugin's `Plugin::boot()`. Boot-time auto-detection of shipped adapters.
- **Lovata-style extensibility** — `Event::fire('metapixel.event.before_dispatch', ...)` decision-point hooks; `Component::extend(...)`, `addDynamicMethod()` patterns for third-party hookpoints; service-container bindings for HTTP client swap.
- **ShopaholicAdapter** — port v1.x Order/Cart/CartPosition logic behind adapter. v2.0 still works on nailscosmetics.* sites.
- **ThemeActionAdapter** — generic theme-action tracking: PageView, ViewContent, custom events via Twig + Larajax API. Operators wire any event from theme without writing PHP.
- **Settings rework** — `trusted_hosts` (operator allowlist + `jeremykendall/php-domain-parser` for multi-TLD index derivation), Multisite trait on `pixel_id` + `capi_access_token` (per-site overrides). All v1.x Settings keep working.
- **Plugin manifest rename** — namespace `Logingrupa\Metapixel`, dir `plugins/logingrupa/metapixel/`, composer package generic name, generic description.
- **Composer suggest pattern** — `lovata/shopaholic-plugin` becomes `suggest:` not `require:`. Plugin works without Shopaholic.
- **Documentation** — `README.md` install guide (< 10 min: composer require → first CAPI event). Per-adapter setup. Pixel + CAPI token acquisition walkthrough. Custom-adapter authoring guide.
- **FailedEvents backend audit** — replay + dedup status check (v1.x HARD-01..03 re-derived against new namespace).
- **Translations** — en/lv/ru lang files for Settings + FailedEvents UI.
- **CI green** — `composer qa` exits 0 on fresh OctoberCMS + Shopaholic install AND fresh OctoberCMS without Shopaholic. ≥90% coverage.
- **Marketplace launch** — installable via `composer require logingrupa/oc-metapixel-plugin` from clean OctoberCMS 4.x.

**Locked v2.0 decisions** (carry forward from v1.x — do not re-derive):
- CR-02 TrustedHosts allowlist (operator-supplied Settings + `jeremykendall/php-domain-parser` for multi-TLD)
- CR-03 fbclid `[A-Za-z0-9_-]` charset, ≤255 chars
- event_id direction = server → frontend only; dedup contract on same UUIDv4 + event_time
- EventLog UNIQUE race-fence on `(subject_type, subject_id, event_name, channel, site_id)`
- PluginGuard empty-pixel-id → disabled + warn (never throw at boot)
- Tooling: phpstan lvl 10 + larastan + universalObjectCrates + phpmd Toolbox + Pest 4 + pint
- No `assert()`, no `declare(strict_types=1)` enforcement (optional per-file)
- Fail-fast `throw` at boundaries; catch only to log-and-rethrow OR dead-letter-persist
- Settings extends `Lovata\Toolbox\Models\CommonSettings`
- Hungarian notation (`$ob`, `$s`, `$i`, `$f`, `$b`, `$ar`); PHPMD `ShortVariable min=4`

**v2.0 code style additions** (from `feedback-lovata-extensibility-pattern` memory):
- DRY — no repeated logic across adapters; lift to abstract/trait
- SRP — adapters do NOT mix value-resolution + event-dispatch + payload-build
- Self-explanatory variable names — prefer `$obSubjectAdapter` over `$mId` or `$tmp`
- Laravel short docblocks — one-line summary + `@param` + `@return`; no multi-paragraph narrative
- No phase/CR/incident markers in code; workflow refs go in commits/PRs only

**v2.0 architecture lock-in:**
- Namespace: `Logingrupa\Metapixel` (drop "Shopaholic")
- Plugin dir: `plugins/logingrupa/metapixel/`
- Composer: `lovata/shopaholic-plugin` in `suggest:` not `require:`
- PHP support: `"php": "^8.3 || ^8.4"` — avoid PHP 8.4-only syntax (no property hooks, no asymmetric visibility, no `array_find`/`array_any`/`array_all`, no `#[\Deprecated]`)
- Multisite trait on `pixel_id` + `capi_access_token`

**Out of scope for v2.0** (carried forward unchanged):
- GDPR / cookie-consent banner integration — live theme has no banner
- Custom Graph API endpoint version other than v20 — pinned
- `declare(strict_types=1)` enforcement — optional per-file

---

<details>
<summary><b>v1.x Archive — Shopaholic-coupled plugin (2026-04-22 → 2026-05-14)</b></summary>

## What This Is (v1.x)

An OctoberCMS 4.x + Lovata Shopaholic ecosystem plugin (`Logingrupa\Metapixelshopaholic`) that ships Meta Pixel and Conversions API (CAPI) tracking as a dual-channel, server-deduplicated integration. Every user action fires both a browser `fbq('track', ...)` call and a server-side `POST https://graph.facebook.com/v20.0/{pixel_id}/events` with an identical `event_id`, so Meta dedupes on its side and both channels contribute to attribution. Installable via Composer from a private GitHub repo.

## Core Value (v1.x — delivered for Shopaholic only)

Meta Ads Manager sees every purchase (including bank-transfer and admin-marked-paid orders that today never reach the browser), dedup stays ≥ 80 %, and EMQ ≥ 8 for Purchase — unlocking CAPI-driven optimisation that today is broken by empty `_fbp`/`_fbc` cookies and a Pixel-only pipeline.

## Out of Scope (v1.x)

- **Consent/GDPR banner integration** — live theme has no banner. Re-visit when stakeholder ships one.
- **External dead-letter alerting (Slack/email/Telegram)** — v1 is log-only + backend `FailedEvents` list with `onReplay`. Deferred to v1.1 behind Settings dropdown.
- **Native upstream events for cart update/remove, wishlist add, user register** — proposed as upstream PRs to lovata/ordersshopaholic + lovata/wishlistshopaholic + lovata/buddies, not blocking v1.
- **Campaign-plugin pricing tiers in ViewContent `content_price`** — depends on `Logingrupa.CampaignpricingShopaholic` maturity; post-v1.
- **Model factories in lovata/shopaholic + lovata/ordersshopaholic** — plugin ships its own test factories; upstream contribution deferred.
- **New `content_id` format dropdown** — format is forced to `SKU-{product_id}[-{offer_id}]` to match the Facebook Catalog feed exporter. No toggle.

## Context (v1.x)

- **Live Pixel ID** on nailscosmetics.lv: `2291486191076331`.
- **Live bug driving S1:** `_fbp` / `_fbc` cookies observed empty 2026-04-22 → middleware fix (`EnsureFbpFbcCookies`) alone restored CAPI user-matching baseline.
- **Paid status resolved:** `new-payment-received` (Status ID=5, custom). PayPal + Vipps gateways auto-set via `PaymentMethod.after_status_id=5`; bank transfer + COD have null `after_status_id` → admin manually flips to ID=5 (this flip is what the `OrderStatusWatcher` observes). Base Lovata `complete` status is "shipped/done", NOT "paid".
- **content_ids format resolved:** `SKU-{product_id}` (single-offer) / `SKU-{product_id}-{offer_id}` (multi-offer) — matches the Facebook Catalog feed at `plugins/logingrupa/facebookcatalogshopaholic/classes/helper/ExportCatalogFacebookHelper.php:356` and existing tracking at `plugins/logingrupa/storeextender/classes/event/cart/CartComponentHandler.php:137-149`. Pixel + Catalog must emit the exact same id for Meta to match.
- **Lead form resolved:** salon application form at `themes/logingrupa-naisstore/pages/salon/application-form.htm:13-74` (native `onSend`, not Renatio.FormBuilder). v1 hooks Meta `Lead` there.
- **Event hooks corrected (v2→v3):** `shopaholic.cart.element.after.*`, `shopaholic.favorite.*`, `lovata.buddies.user.after.register` DO NOT EXIST in Lovata. Only `shopaholic.cart.add` fires natively. v3 uses `eloquent.created`, `model.afterUpdate`, and `Component::extend` + `addDynamicMethod` fallbacks.
- **AJAX transport:** Larajax IS installed at `vendor/larajax/larajax/` but theme pattern is `jax.ajax('Component::onHandler', ...)` against October component handlers, NOT `Larajax::get/post(...)` route facades. Plugin ships no routes; extends existing Lovata components.

## Constraints (v1.x)

- **Tech stack:** PHP 8.4, OctoberCMS v4, Laravel 12, `october/rain ^4.0`, `october/all ^4.0`, Lovata Shopaholic ecosystem (`lovata/toolbox-plugin ^2.2`, `lovata/ordersshopaholic-plugin ^1.33`, `lovata/shopaholic-plugin ^1.32`, `lovata/buddies-plugin ^1.10`).
- **Runtime deps:** `guzzlehttp/guzzle ^7.8`, `ramsey/uuid ^4.7`.
- **Dev deps:** `pestphp/pest ^4.1`, `pestphp/pest-plugin-drift ^4.0`, `phpunit/phpunit ^12`, `larastan/larastan ^3.0`, `spaze/phpstan-disallowed-calls ^4.0`, `phpmd/phpmd ^2.15`, `laravel/pint ^1.26`, `rector/rector ^2.0`, `mockery/mockery ^1.6`.
- **Naming:** Lovata.Toolbox Hungarian notation mandatory (`$ob`, `$s`, `$i`, `$f`, `$b`, `$a`). PHPMD `ShortVariable min=4` allows them.
- **No jQuery:** Vanilla JS + Larajax only.
- **Fail-fast:** Explicit `throw` at function boundaries. NO `assert()` — prod `zend.assertions=0` silently no-ops. Enforced via `spaze/phpstan-disallowed-calls`.
- **Boot-time safety:** Missing `pixel_id` = `Log::warning` + plugin-disabled flag (NOT throw — would cascade-break Campaigns/PromoMechanism/Order flow). Event-time missing = throw `MissingPixelConfigException`. CAPI job failure = retry 3× then dead-letter; Pixel still fires client-side (graceful degradation of backup channel).
- **Folder layout (Lovata singular):** `classes/{event,queue,helper,meta,exception}/` + `middleware/` at plugin root.
- **Multi-site deployability:** Same plugin must run on nailscosmetics.no/.lv/.lt without modification. Currency settable.
- **Production non-regression:** Plugin must NOT break existing Campaigns, PromoMechanism, Order, or Checkout flows.
- **Composer package:** Installable from private GitHub repo as `logingrupa/oc-metapixel-plugin`.
- **No `declare(strict_types=1)` enforcement** — zero ecosystem usage in Lovata/Logingrupa files. Optional per-file.

## Key Decisions (v1.x — locked, carry forward to v2.0 unless flagged SUPERSEDED)

| Decision | Rationale | Outcome |
|---|---|---|
| `event_id` direction = server → frontend (never reverse) | Meta dedupes on `event_id` match within ±10 s window. Server-authoritative UUID v4 + matching `event_time` is the only deterministic source. | VALIDATED Phase 3 + 3.1 |
| `content_ids` = `SKU-{product_id}[-{offer_id}]` (not SKU, not barcode) | Must match Facebook Catalog feed exporter exactly. | VALIDATED Phase 3 (Plan 03-04) |
| Paid-status trigger = `new-payment-received` (default, configurable dropdown) | Live DB audit: PayPal + Vipps `after_status_id=5`; bank transfer admin-flipped to ID=5. | VALIDATED Phase 3 (Plan 03-06) |
| ~~Idempotency via new `meta_purchase_event_id VARCHAR(36) NULL INDEX` column on `lovata_orders_shopaholic_orders`~~ | ~~Stores the UUID persistently so status flip-flops never re-fire Purchase.~~ | **SUPERSEDED 2026-05-13 by Phase 3.1** — plugin should not mutate Shopaholic's table (SRP); columns also can't suppress browser re-fires across devices |
| Idempotency via plugin-owned `logingrupa_metapixel_event_log` table (polymorphic subject, multi-site `site_id` scope, UNIQUE(subject_type, subject_id, event_name, channel, site_id)) | Plugin owns its own audit log — third-party operators can audit foreign-schema mutations of their own tables, not the plugin's; UNIQUE-constraint race-fence atomically replaces atomic-CAS-on-orders; second `channel='pixel'` row suppresses browser re-fires across devices/sessions/time independent of Meta's eventID dedup window. | VALIDATED Phase 3.1 (Plans 03.1-01..06) |
| SiteResolver::forOrder reads Order.site_id (Lovata-authoritative column), not Request::host | Cross-context (admin vs frontend) determinism. Order.site_id stamped by Lovata OrderProcessor at order create. | VALIDATED Phase 3.1-07 (Plan 03.1-07) |
| Boot-time missing pixel_id = log + disabled flag (NOT throw) | Throwing cascades through Campaigns/PromoMechanism boot chain and nukes the whole site. Event-time localises failure. | VALIDATED Phase 2 (Plan 02-02) |
| No `assert()` anywhere — explicit `throw` only | Prod `zend.assertions=0` makes `assert()` a silent no-op. | VALIDATED Phase 1 (spaze/phpstan-disallowed-calls) |
| Lead event hooks salon application-form `onSend` | Only functional lead form on the site. | DROPPED Phase 4 (v2.0 adapter pattern) |
| v1 dead-letter sink = Log + backend `FailedEvents` list + `onReplay` | No current Slack/email ops channel. | DROPPED Phase 5 (deferred to v2.0) |
| Anonymous `external_id` = `sha256($obOrder->secret_key)` for guests | Orders already carry `secret_key`. | VALIDATED Phase 3 (Plan 03-04) |
| User_data hashes cached per-request via `CCache` tag `meta-pixel-user-hash` | Avoids duplicate sha256 within a request. | VALIDATED Phase 3 (Plan 03-04) |
| Fail-fast via `throw`, catch only to log-and-rethrow OR dead-letter-persist | Tiger-style. Every `catch` has a documented reason. | VALIDATED Phase 3 |
| FailedEvent = plain `October\Rain\Database\Model` (no Toolbox Item wrapper) | Admin-only audit log, never exposed to frontend. | VALIDATED Phase 3 (Plan 03-01) |
| Settings extends `Lovata\Toolbox\Models\CommonSettings` (not plain `Model`) | Ecosystem norm. Auto-caches, supports Multisite trait. | VALIDATED Phase 2 (Plan 02-01) |
| CR-02 TrustedHosts allowlist (HOST_INDEX_MAP for nailscosmetics.{no,lv,lt}) | Naive `count(explode('.', host)) - 1` is exploitable + wrong for multi-TLD. Allowlist prevents host-spoofing. | VALIDATED Phase 2 (Plan 02-03); v2.0 generalizes to operator-supplied Settings |
| CR-03 fbclid charset/length validation `[A-Za-z0-9_-]` ≤255 chars | Reject injection at the cookie-write boundary. | VALIDATED Phase 2 (Plan 02-03) |

## v1.1.1 Closed Milestone

See [`milestones/v1.1.1-ROADMAP.md`](milestones/v1.1.1-ROADMAP.md) and [`milestones/v1.1.1-REQUIREMENTS.md`](milestones/v1.1.1-REQUIREMENTS.md) for full archive.

**Pivot rationale:** v1.x is too Shopaholic-coupled to extend cleanly to MelonCart, Mall, or generic theme tracking. v2.0 introduces adapter pattern. See `project-metapixel-v2-reset` claude memory.

</details>

---

*Last updated: 2026-05-15 — milestone v1.1.1 archived; pending `/gsd-new-milestone` for v2.0*
