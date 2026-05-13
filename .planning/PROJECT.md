# Logingrupa.MetapixelShopaholic Plugin

## What This Is

An OctoberCMS 4.x + Lovata Shopaholic ecosystem plugin (`Logingrupa\Metapixelshopaholic`) that ships Meta Pixel and Conversions API (CAPI) tracking as a dual-channel, server-deduplicated integration. Every user action fires both a browser `fbq('track', ...)` call and a server-side `POST https://graph.facebook.com/v20.0/{pixel_id}/events` with an identical `event_id`, so Meta dedupes on its side and both channels contribute to attribution. Installable via Composer from a private GitHub repo.

## Core Value

Meta Ads Manager sees every purchase (including bank-transfer and admin-marked-paid orders that today never reach the browser), dedup stays ≥ 80 %, and EMQ ≥ 8 for Purchase — unlocking CAPI-driven optimisation that today is broken by empty `_fbp`/`_fbc` cookies and a Pixel-only pipeline.

## Requirements

### Validated

(None yet — pre-v1.0.0)

### Active

Full list lives in `REQUIREMENTS.md`, grouped under:
- Tooling (composer, phpstan, phpmd, pint, rector, Pest 4, CI)
- Skeleton + cookie fix (Plugin.php, Settings, `EnsureFbpFbcCookies` middleware)
- Purchase end-to-end (MetaClient, SendCapiEvent queue, OrderStatusWatcher, idempotency column)
- Funnel completion (ViewContent, AddToCart, InitiateCheckout, AddPaymentInfo, ViewCategory, Search, Lead, CompleteRegistration, Contact, PageView)
- Hardening / Launch (FailedEvents backend list, translations, README, marketplace listing)

### Out of Scope

- **Consent/GDPR banner integration** — live theme has no banner. Re-visit when stakeholder ships one.
- **External dead-letter alerting (Slack/email/Telegram)** — v1 is log-only + backend `FailedEvents` list with `onReplay`. Deferred to v1.1 behind Settings dropdown.
- **Native upstream events for cart update/remove, wishlist add, user register** — proposed as upstream PRs to lovata/ordersshopaholic + lovata/wishlistshopaholic + lovata/buddies, not blocking v1.
- **Campaign-plugin pricing tiers in ViewContent `content_price`** — depends on `Logingrupa.CampaignpricingShopaholic` maturity; post-v1.
- **Model factories in lovata/shopaholic + lovata/ordersshopaholic** — plugin ships its own test factories; upstream contribution deferred.
- **New `content_id` format dropdown** — format is forced to `SKU-{product_id}[-{offer_id}]` to match the Facebook Catalog feed exporter. No toggle.

## Context

- **Live Pixel ID** on nailscosmetics.lv: `2291486191076331`.
- **Live bug driving S1:** `_fbp` / `_fbc` cookies observed empty 2026-04-22 → middleware fix (`EnsureFbpFbcCookies`) alone restores CAPI user-matching baseline.
- **Paid status resolved:** `new-payment-received` (Status ID=5, custom). PayPal + Vipps gateways auto-set via `PaymentMethod.after_status_id=5`; bank transfer + COD have null `after_status_id` → admin manually flips to ID=5 (this flip is what the `OrderStatusWatcher` observes). Base Lovata `complete` status is "shipped/done", NOT "paid".
- **content_ids format resolved:** `SKU-{product_id}` (single-offer) / `SKU-{product_id}-{offer_id}` (multi-offer) — matches the Facebook Catalog feed at `plugins/logingrupa/facebookcatalogshopaholic/classes/helper/ExportCatalogFacebookHelper.php:356` and existing tracking at `plugins/logingrupa/storeextender/classes/event/cart/CartComponentHandler.php:137-149`. Pixel + Catalog must emit the exact same id for Meta to match.
- **Lead form resolved:** salon application form at `themes/logingrupa-naisstore/pages/salon/application-form.htm:13-74` (native `onSend`, not Renatio.FormBuilder). v1 hooks Meta `Lead` there.
- **Event hooks corrected (v2→v3):** `shopaholic.cart.element.after.*`, `shopaholic.favorite.*`, `lovata.buddies.user.after.register` DO NOT EXIST in Lovata. Only `shopaholic.cart.add` fires natively. v3 uses `eloquent.created`, `model.afterUpdate`, and `Component::extend` + `addDynamicMethod` fallbacks.
- **AJAX transport:** Larajax IS installed at `vendor/larajax/larajax/` but theme pattern is `jax.ajax('Component::onHandler', ...)` against October component handlers, NOT `Larajax::get/post(...)` route facades. Plugin ships no routes; extends existing Lovata components.
- **Supporting docs** (already committed):
  - `PLAN.md` — v3 codebase-aligned plan
  - `PLAN-v2-original.md` — superseded reference
  - `SUMMARY.md` — v2→v3 delta
  - `audits/01..07.md` — codebase evidence per section
  - `answers/*.md` — resolved open questions

## Constraints

- **Tech stack:** PHP 8.4, OctoberCMS v4, Laravel 12, `october/rain ^4.0`, `october/all ^4.0`, Lovata Shopaholic ecosystem (`lovata/toolbox-plugin ^2.2`, `lovata/ordersshopaholic-plugin ^1.33`, `lovata/shopaholic-plugin ^1.32`, `lovata/buddies-plugin ^1.10`).
- **Runtime deps:** `guzzlehttp/guzzle ^7.8`, `ramsey/uuid ^4.7`.
- **Dev deps:** `pestphp/pest ^4.1`, `pestphp/pest-plugin-drift ^4.0`, `phpunit/phpunit ^12`, `larastan/larastan ^3.0`, `spaze/phpstan-disallowed-calls ^4.0`, `phpmd/phpmd ^2.15`, `laravel/pint ^1.26`, `rector/rector ^2.0`, `mockery/mockery ^1.6`.
- **Naming:** Lovata.Toolbox Hungarian notation mandatory (`$ob`, `$s`, `$i`, `$f`, `$b`, `$a`). PHPMD `ShortVariable min=4` allows them.
- **No jQuery:** Vanilla JS + Larajax only.
- **Fail-fast:** Explicit `throw` at function boundaries. NO `assert()` — prod `zend.assertions=0` silently no-ops. Enforced via `spaze/phpstan-disallowed-calls`.
- **Boot-time safety:** Missing `pixel_id` = `Log::warning` + plugin-disabled flag (NOT throw — would cascade-break Campaigns/PromoMechanism/Order flow). Event-time missing = throw `MissingPixelConfigException`. CAPI job failure = retry 3× then dead-letter; Pixel still fires client-side (graceful degradation of backup channel).
- **Folder layout (Lovata singular):** `classes/{event,queue,helper,meta,exception}/` + `middleware/` at plugin root. NO `classes/listeners/jobs/middleware/helpers/`.
- **Multi-site deployability:** Same plugin must run on nailscosmetics.no/.lv/.lt without modification. Currency settable.
- **Production non-regression:** Plugin must NOT break existing Campaigns, PromoMechanism, Order, or Checkout flows.
- **Composer package:** Installable from private GitHub repo as `logingrupa/oc-metapixel-plugin`.
- **No `declare(strict_types=1)` enforcement** — zero ecosystem usage in Lovata/Logingrupa files. Optional per-file.

## Key Decisions

| Decision | Rationale | Outcome |
|---|---|---|
| `event_id` direction = server → frontend (never reverse) | Meta dedupes on `event_id` match within ±10 s window. Server-authoritative UUID v4 + matching `event_time` is the only deterministic source. | — Pending |
| `content_ids` = `SKU-{product_id}[-{offer_id}]` (not SKU, not barcode) | Must match Facebook Catalog feed exporter exactly; mismatch = Meta cannot match products. | Resolved 2026-04-22 |
| Paid-status trigger = `new-payment-received` (default, configurable dropdown) | Live DB audit: PayPal + Vipps `after_status_id=5`; bank transfer admin-flipped to ID=5. Base Lovata `complete` means "shipped/done" — wrong for CAPI Purchase. | Resolved 2026-04-22 |
| ~~Idempotency via new `meta_purchase_event_id VARCHAR(36) NULL INDEX` column on `lovata_orders_shopaholic_orders`~~ | ~~Stores the UUID persistently so status flip-flops (paid → refund → paid) never re-fire Purchase. DB-level guard, not in-memory.~~ | **SUPERSEDED 2026-05-13 by Phase 3.1** — plugin should not mutate Shopaholic's table (SRP); columns also can't suppress browser re-fires across devices beyond Meta's 7-day eventID dedup window |
| Idempotency via plugin-owned `logingrupa_metapixel_event_log` table (polymorphic subject, multi-site `site_id` scope, UNIQUE(subject_type, subject_id, event_name, channel, site_id)) | Plugin owns its own audit log — third-party operators can audit foreign-schema mutations of their own tables, not the plugin's; UNIQUE-constraint race-fence atomically replaces atomic-CAS-on-orders; second `channel='pixel'` row suppresses browser re-fires across devices/sessions/time independent of Meta's eventID dedup window; multi-site `site_id` scope lets two sites independently dispatch for the same Order id; designed to carry Phase 4 funnel events (AddToCart, ViewContent, Lead, ...) without further schema change. | Decided 2026-05-13 (Phase 3.1 INSERTED) — Pending implementation |
| Boot-time missing pixel_id = log + disabled flag (NOT throw) | Throwing cascades through Campaigns/PromoMechanism boot chain and nukes the whole site. Event-time localises failure. | — Pending |
| No `assert()` anywhere — explicit `throw` only | Prod `zend.assertions=0` makes `assert()` a silent no-op. `throw` gives identical dev behaviour plus prod safety. Enforced by `spaze/phpstan-disallowed-calls`. | — Pending |
| Lead event hooks salon application-form `onSend` (not separate plugin endpoint) | That's the only functional lead form on the site. Inline wiring keeps the Meta plugin the sole owner of CAPI dispatch. | — Pending |
| v1 dead-letter sink = Log + backend `FailedEvents` list + `onReplay` | No current Slack/email ops channel. External alerts deferred to v1.1 behind Settings dropdown. | — Pending |
| Anonymous `external_id` = `sha256($obOrder->secret_key)` for guests | Orders already carry `secret_key` (guest purchase URL token). Stable per-order without requiring a User row. | — Pending |
| User_data hashes cached per-request via `CCache` tag `meta-pixel-user-hash` | Avoids duplicate sha256 of email/phone within a request when multiple events fire (ViewContent + AddToCart + …). | — Pending |
| Fail-fast via `throw`, catch only to log-and-rethrow OR dead-letter-persist | Tiger-style. `catch (\Throwable) {}` = bug multiplier. Every `catch` has a documented reason. | — Pending |
| FailedEvent = plain `October\Rain\Database\Model` (no Toolbox Item wrapper) | Admin-only audit log, never exposed to frontend. Item wrapper gains nothing. | — Pending |
| Settings extends `Lovata\Toolbox\Models\CommonSettings` (not plain `Model`) | Ecosystem norm. Auto-caches, supports Multisite trait, pairs with `Settings::get('key', $default)` convention. | — Pending |

## Current Milestone: v1.0.0 MetapixelShopaholic

**Goal:** Ship production-ready Meta Pixel + CAPI plugin for Lovata Shopaholic with server-deduplicated event_id pipeline.

**Target features:**
- Dual-channel event dedup contract (same `event_id` + `event_time` on Pixel and CAPI)
- `_fbp` / `_fbc` cookies always set server-side (middleware fix)
- Purchase event fires on `new-payment-received` status with DB-level idempotency (plugin-owned `logingrupa_metapixel_event_log` table, multi-site `site_id` scoped — Phase 3.1; supersedes original `meta_purchase_event_id` column approach)
- Full funnel catalogue (PageView, ViewContent, ViewCategory, Search, AddToCart, AddToWishlist, InitiateCheckout, AddPaymentInfo, Purchase, Lead, CompleteRegistration, Contact)
- Backend `FailedEvents` admin list with `onReplay` and dedup status check

## Evolution

This document evolves at phase transitions and milestone boundaries.

**After each phase transition** (via `/gsd-transition`):
1. Requirements invalidated? → Move to Out of Scope with reason
2. Requirements validated? → Move to Validated with phase reference
3. New requirements emerged? → Add to Active
4. Decisions to log? → Add to Key Decisions
5. "What This Is" still accurate? → Update if drifted

**After each milestone** (via `/gsd-complete-milestone`):
1. Full review of all sections
2. Core Value check — still the right priority?
3. Audit Out of Scope — reasons still valid?
4. Update Context with current state

---
*Last updated: 2026-04-22 — milestone v1.0.0 initialized*
