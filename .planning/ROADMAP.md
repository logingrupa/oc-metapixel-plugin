# Roadmap: Logingrupa.MetapixelShopaholic v1.0.0

## Overview

Five sequential phases (S0→S4 in PLAN.md terms) ship a production-grade Meta Pixel + CAPI plugin. The core dedup contract — same server-generated `event_id` + `event_time` on browser `fbq()` and server `POST /events` — is enforced end-to-end from Phase 3 onward. Phase 1 locks the quality bar (composer qa green on empty plugin) before any business code is written. Phase 2 fixes the live `_fbp`/`_fbc` empty-cookie bug alone. Phase 3 closes the attribution gap for bank-transfer and admin-marked-paid orders. Phase 4 completes the funnel catalogue across PDP, cart, checkout, lead form, and registration. Phase 5 hardens operations + ships the Composer marketplace listing.

## Phases

**Phase Numbering:**
- Integer phases (1, 2, 3, 4, 5): Planned milestone work.
- Decimal phases (2.1, 2.2): Urgent insertions (marked with INSERTED).

Decimal phases appear between their surrounding integers in numeric order.

- [x] **Phase 1: Tooling** — `composer qa` green on empty plugin (composer.json, phpstan lvl 10 + larastan + universalObjectCrates, phpmd copy of Toolbox, pint, rector, Pest 4 + MetapixelTestCase, CI). ✓ 2026-05-12
- [ ] **Phase 2: Skeleton + cookie fix** — Plugin.php, Settings extending CommonSettings, `EnsureFbpFbcCookies` middleware. Fixes live empty-cookie bug.
- [ ] **Phase 3: Purchase end-to-end** — MetaClient, SendCapiEvent queue job, OrderStatusWatcher, idempotency via `meta_purchase_event_id` column, PayloadBuilder + UserDataHasher + custom exception hierarchy. Dedup verified ≥ 80 % / EMQ ≥ 8 in Test Events.
- [ ] **Phase 4: Funnel completion** — PageView, ViewContent, ViewCategory, Search, AddToCart, AddToWishlist, InitiateCheckout, AddPaymentInfo, Lead, CompleteRegistration, Contact. All share event_id + event_time. content_ids format locked to Facebook Catalog feed.
- [ ] **Phase 5: Hardening + launch** — FailedEvents backend list + onReplay + onCheckDedup, lang/{en,lv,ru}, README with runbook, Composer marketplace listing, coverage ≥ 90 %.

## Phase Details

### Phase 1: Tooling

**Goal:** The plugin scaffold enforces the quality bar before any business code is written — `composer qa` green on an empty plugin, so subsequent phases fail loudly the moment they slip.
**Depends on:** Nothing (first phase).
**Requirements:** TOOL-01, TOOL-02, TOOL-03, TOOL-04, TOOL-05, TOOL-06, TOOL-07, TOOL-08
**Success Criteria** (what must be TRUE):
  1. Running `composer qa` inside `plugins/logingrupa/metapixelshopaholic/` exits zero on a fresh clone (pint-test clean, phpstan level 10 zero errors, phpmd zero warnings, pest zero failures).
  2. `phpstan analyse` treats `Lovata\Toolbox\Classes\Item\ElementItem` and `ElementCollection` as universal object crates (no `Access to an undefined property` errors from `__get()` magic).
  3. `assert(...)` usage anywhere under `classes/`, `components/`, `controllers/`, `middleware/`, `models/`, `Plugin.php` fails `composer analyse` via `spaze/phpstan-disallowed-calls`.
  4. GitHub Actions `metapixel-qa.yml` workflow triggers on push/PR touching the plugin and runs `composer qa` on PHP 8.4.
  5. `tests/MetapixelTestCase.php` boots the October CMS test harness (`runOctoberUpCommand()`) successfully in a minimal test.
**Plans:** TBD

### Phase 2: Skeleton + cookie fix

**Goal:** Plugin boots on OctoberCMS, Settings are editable in backend, and the live empty `_fbp`/`_fbc` cookie bug is fixed — standalone value even before any event fires.
**Depends on:** Phase 1.
**Requirements:** SKEL-01, SKEL-02, SKEL-03, SKEL-04, SKEL-05, SKEL-06
**Success Criteria** (what must be TRUE):
  1. Activating the plugin on a clean OctoberCMS 4.x + Shopaholic install boots cleanly (no exceptions, no cascade breakage of Campaigns/PromoMechanism/Order).
  2. Backend → Settings → Meta Pixel renders all 10 Settings fields with `paid_status_code` dropdown populated from live Status codes (`new-payment-received` selectable).
  3. On a fresh browser session, `_fbp` and `_fbc` cookies are set server-side by `EnsureFbpFbcCookies` middleware within the first request (no cookie = middleware sets one; cookie exists = middleware no-ops).
  4. Booting with empty `pixel_id` logs `Log::warning('Metapixel: pixel_id not configured — plugin disabled')` and does NOT throw. Verified by feature test.
  5. The theme's existing `facebook_pixel.htm` partial renders unchanged when no `arMetaEvent` is set (no regression), and renders event metadata + `fbq('track', ..., {eventID})` when it IS set.
**Plans:** TBD

### Phase 3: Purchase end-to-end

**Goal:** A paid order — including bank-transfer and admin-marked-paid orders previously invisible to Meta — fires a deduplicated Purchase event via CAPI. Status flip-flops never re-fire.
**Depends on:** Phase 2.
**Requirements:** PAY-01, PAY-02, PAY-03, PAY-04, PAY-05, PAY-06, PAY-07, PAY-08, PAY-09, PAY-10, PAY-11
**Success Criteria** (what must be TRUE):
  1. A test PayPal order reaching `Status.code = 'new-payment-received'` fires exactly one Purchase CAPI event with `event_id` persisted to `lovata_orders_shopaholic_orders.meta_purchase_event_id`, and Meta Test Events shows matching Pixel + CAPI pair deduplicated.
  2. Manually flipping a bank-transfer order to `new-payment-received` in the backend fires one Purchase CAPI event (no Pixel twin — Meta accepts the single event).
  3. Flipping the same order away from and back to `new-payment-received` does NOT re-fire Purchase (DB column is populated).
  4. `composer qa` green with PAY-* classes added, coverage includes every `PayloadBuilder` precondition throw and the `SendCapiEvent` retry + dead-letter branches (Guzzle mocked via `MockHandler`).
  5. Meta Events Manager → Test Events reports dedup ≥ 80 % and EMQ ≥ 8 for Purchase using `test_event_code`.
**Plans:** TBD

### Phase 4: Funnel completion

**Goal:** Every funnel-stage user action fires a deduplicated Pixel + CAPI pair (or CAPI-only where no browser twin applies). `content_ids` match the Facebook Catalog feed exactly, unlocking dynamic-ads relevance.
**Depends on:** Phase 3.
**Requirements:** FUN-01, FUN-02, FUN-03, FUN-04, FUN-05, FUN-06, FUN-07, FUN-08, FUN-09, FUN-10, FUN-11, FUN-12, FUN-13, FUN-14
**Success Criteria** (what must be TRUE):
  1. PDP load fires ViewContent with `content_ids: ['SKU-{product_id}']` (single-offer) or `['SKU-{product_id}-{offer_id}']` (multi-offer), deduplicated across Pixel + CAPI. Format byte-for-byte matches `ExportCatalogFacebookHelper.php:356` output.
  2. Adding an item to cart fires AddToCart deduplicated; `jax.ajax('Cart::onMetaTrackAddToCart')` returns the `meta` envelope that browser uses for `fbq('track', 'AddToCart', ..., {eventID})`.
  3. Navigating `/lv/checkout` fires `InitiateCheckout` (correct spelling, replaces legacy `InitiatedCheckout`) deduplicated with cart contents + total.
  4. Submitting the salon application form at `/saloniem/pieteikt-salonu` fires a Lead event deduplicated (browser uses `event_id` from the form's JSON response) and the mail still sends.
  5. Registering a new user via Buddies fires a CompleteRegistration event deduplicated.
  6. Every `fbq('track', ...)` call includes `event_time` matching the paired CAPI dispatch (asserted via integration tests that capture dispatched jobs + browser payloads).
**Plans:** TBD

### Phase 5: Hardening + launch

**Goal:** Backend audit + replay for failed events, translations complete, README with runbook, and the package installs cleanly as `logingrupa/oc-metapixel-plugin` on a fresh OctoberCMS + Shopaholic install.
**Depends on:** Phase 4.
**Requirements:** HARD-01, HARD-02, HARD-03, HARD-04, HARD-05, HARD-06, HARD-07, HARD-08
**Success Criteria** (what must be TRUE):
  1. A dead-lettered event appears in the backend `FailedEvents` list with attempts, http_status, and a truncated graph_error. Clicking Replay re-dispatches it and on 200 OK flashes success + clears the row via counter bump.
  2. `FailedEvents::onCheckDedup` returns live dedup % and EMQ for each event from the Meta Test Events endpoint.
  3. Backend UI labels render in English, Latvian, and Russian via RainLab.Translate (Lang files populated for Settings, FailedEvents, any user-facing strings).
  4. `README.md` documents installation, Settings, the dedup contract, all 5 resolved open questions, and the Log::* context troubleshooting runbook.
  5. On a clean OctoberCMS 4.x + Shopaholic install, `composer require logingrupa/oc-metapixel-plugin` installs the plugin end-to-end without errors and plugin appears in `php artisan plugin:list` as `Logingrupa.Metapixelshopaholic`.
  6. `pest --coverage --min=90` passes; optional `tests/Integration/MetaTestEventsApiSmokeTest.php` runs green when `META_TEST_TOKEN` is set.
**Plans:** TBD

## Progress

**Execution Order:**
Phases execute in numeric order: 1 → 2 → 3 → 4 → 5

| Phase | Plans Complete | Status | Completed |
|---|---|---|---|
| 1. Tooling | 1/1 | Complete | 2026-05-12 |
| 2. Skeleton + cookie fix | 0/- | Not started | - |
| 3. Purchase end-to-end | 0/- | Not started | - |
| 4. Funnel completion | 0/- | Not started | - |
| 5. Hardening + launch | 0/- | Not started | - |
