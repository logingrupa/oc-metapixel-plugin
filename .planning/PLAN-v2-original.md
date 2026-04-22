# LoginGrupa.MetaPixelShopaholic — Implementation Plan v2

> Plugin author: **LoginGrupa**
> Target stack: **OctoberCMS 4.3** + **Lovata Shopaholic** + **Larajax** + **Laravel 11 queue**
> Goal: send Meta the **maximum** of allowed data, deduplicated between Pixel + Conversions API, with **one canonical Purchase trigger**.
> First user: **nailscosmetics.lv** (PRIMINENCE SIA), Pixel ID `2291486191076331`.

---

## 0. What this v2 changes vs v1

This rewrite is based on a live walk-through of nailscosmetics.lv on 2026-04-22 (test order `260422-0002`). Concretely:

1. **`event_id` direction is now explicitly defined** — server generates, frontend consumes. No exceptions.
2. **Maximum-data payloads** for every event (was: minimum).
3. **Larajax** is the AJAX transport, not native `$.request()`.
4. **Single Purchase rule**: fires once per order on `OrderStatus → Paid` — payment-method-agnostic. Card/PayPal → instantly when gateway flips status. Bank transfer → when admin marks paid.
5. Adds the **`EnsureFbpFbcCookies` middleware** to fix the empty-cookies bug observed live.
6. Renames the misspelled `InitiatedCheckout` and `ViewdOrderCompleatedStatusPage` events.

---

## 1. The `event_id` flow — answering the direct question

> **Q: How do we get the same `event_id` on both browser Pixel and server CAPI? Server → frontend, or frontend → server?**
> **A: Server generates, frontend consumes. Always.**

Why this direction (and not the reverse):

- If the browser generated the id, an ad-blocker / tracker / extension could block the AJAX response, and we'd CAPI-fire an event the Pixel never matched. Our dedup ratio would crater and Meta would treat half the events as duplicates of nothing.
- The server always runs. UUID generation is free. We control the lifecycle.

Two patterns cover everything:

### Pattern A — Server-rendered page events (PageView, ViewContent, ViewCategory, InitiateCheckout)

```
Browser GET /lv/p/{slug}
        ↓
OctoberCMS controller renders Twig
        ↓
Plugin component generates event_id (UUID v4) + builds custom_data
        ↓
TWO things happen in the same request:
  A) Twig outputs:
       <script>
         window.__metaEvt = {
           event_id:   '{{ event_id }}',
           event_time: {{ event_time }},
           data:       {{ custom_data | json_encode | raw }}
         };
         fbq('track', 'ViewContent', window.__metaEvt.data,
             { eventID: window.__metaEvt.event_id });
       </script>
  B) Plugin queues:
       SendCapiEvent::dispatch($event_id, $event_time, 'ViewContent',
                               $custom_data, $user_data,
                               'website', $event_source_url);
        ↓
Queue worker POSTs Graph API with the SAME event_id → Meta dedupes.
```

### Pattern B — AJAX events (AddToCart, AddToWishlist, Search, etc.)

```
Frontend: larajax.post('cart/add', { offer_id, quantity })
        ↓
Backend handler:
  1. Calls Shopaholic's onAdd processor.
  2. Generates event_id + builds custom_data + user_data.
  3. Dispatches SendCapiEvent job.
  4. Returns response with `meta` envelope:
       {
         ok: true,
         cart: {...},
         meta: { event_name, event_id, custom_data }
       }
        ↓
Frontend onSuccess:
  fbq('track', resp.meta.event_name,
              resp.meta.custom_data,
              { eventID: resp.meta.event_id });
```

### Pattern C — Backend-only events (Purchase on bank transfer)

When the customer is not in the browser (admin marked an invoice as paid in the OctoberCMS backend), only CAPI fires. The `event_id` is generated server-side, **stored on the order row** (column `meta_purchase_event_id`), and used:

- Once for the CAPI Purchase event right now.
- Again later, on the optional thank-you page render, if the customer ever returns — same id reused, Meta still dedupes.

---

## 2. Event catalogue — maximum data on every event

Every event below carries the **full `user_data` block from §3** in addition to its `custom_data`. The `custom_data` shown is the maximum we can legitimately produce.

### 2.1 PageView
- **When**: every page load (after consent).
- **`custom_data`**: `{}` (Meta does not accept extra fields here, and adding noise hurts EMQ for other events).

### 2.2 ViewContent (PDP)
- **When**: user lands on `/lv/p/{slug}`.
- **`custom_data`**:
  ```json
  {
    "content_ids":     ["SKU-648-6415"],
    "content_type":    "product",
    "content_name":    "Roku un ķermeņa losjons, 200ml",
    "content_category":"Cosmetics > Body lotions",
    "value":            8.90,
    "currency":        "EUR",
    "contents":[{"id":"SKU-648-6415","quantity":1,"item_price":8.90}]
  }
  ```

### 2.3 ViewCategory (PLP) — `trackCustom`
- **When**: user lands on `/lv/category/{slug}` or any listing page.
- **`custom_data`**: `{ content_ids: [first 10 offer ids], content_type: "product", content_category, content_name }`

### 2.4 Search
- **When**: header search field submit.
- **`custom_data`**: `{ search_string, content_ids: [first 10 results], content_type: "product" }`

### 2.5 AddToCart
- **When**: `Cart::onAdd` Larajax handler returns ok.
- **`custom_data`**:
  ```json
  {
    "content_ids":  ["SKU-648-6415"],
    "content_type": "product",
    "content_name": "Roku un ķermeņa losjons, 200ml",
    "value":         8.90,
    "currency":     "EUR",
    "contents":[{"id":"SKU-648-6415","quantity":1,"item_price":8.90}],
    "num_items":     1
  }
  ```

### 2.6 AddToWishlist
- **When**: wishlist add Larajax handler returns ok.
- **`custom_data`**: `{ content_ids, content_type, content_name, value, currency }`

### 2.7 InitiateCheckout
- **When**: user lands on `/lv/checkout`.
- Replaces today's misspelled `InitiatedCheckout` (use the standard event name so Meta can use it for optimisation).
- **`custom_data`**:
  ```json
  {
    "content_ids":  ["SKU-A","SKU-B"],
    "content_type": "product",
    "contents":     [{"id":"SKU-A","quantity":1,"item_price":8.90}, {"id":"SKU-B","quantity":2,"item_price":4.50}],
    "num_items":     3,
    "value":        17.90,
    "currency":     "EUR"
  }
  ```

### 2.8 AddPaymentInfo
- **When**: user picks a `payment_method_id` radio.
- **`custom_data`**: `{ contents, content_ids, num_items, value, currency }`

### 2.9 Purchase — **the unified rule**
- **When**: `OrderStatus` transitions to **Paid** (whatever the Shopaholic code is on this install — likely `samaksats` or `paid`). Once. Per order. Never again.
- Applies to **every** payment method:
  - **Card / PayPal / Stripe / Klix**: fires the moment the gateway callback flips the order to Paid (usually within seconds of checkout).
  - **Bank transfer (invoice)**: fires when the admin marks the order Paid in the OctoberCMS backend. This is the missing event today — bank-transfer orders are currently invisible to Meta.
- **Idempotency**: column `meta_purchase_event_id` on the orders table. If non-null when the watcher runs → noop. If null → generate UUID, save, fire, never re-fire (even if status flaps back and forth).
- **`custom_data`**:
  ```json
  {
    "content_ids":  ["SKU-A","SKU-B"],
    "content_type": "product",
    "contents":     [{"id":"SKU-A","quantity":1,"item_price":8.90}, {"id":"SKU-B","quantity":2,"item_price":4.50}],
    "num_items":     3,
    "value":        17.90,
    "currency":     "EUR",
    "order_id":     "260422-0002"
  }
  ```
- **Pixel mirror**: if a customer happens to be on the thank-you page when status flips (card path), Pixel also fires with the same `event_id`. For bank-transfer Paid (admin action), only CAPI fires — and that's correct, because there's no browser to render in.

> The current `trackCustom ViewdOrderCompleatedStatusPage` (sic) event stays — useful for retargeting people who saw the bank requisites — but **rename it to `ViewedOrderConfirmation`** and treat it as marketing telemetry, NOT as Purchase.

### 2.10 Lead
- **When**: MS course lead form submitted (or any other lead form).
- **`custom_data`**: `{ content_name, content_category: "course", value, currency }`

### 2.11 CompleteRegistration
- **When**: new `Lovata.Buddies` user account created.
- **`custom_data`**: `{ content_name: "registration", status: true, value: 0, currency: "EUR" }`

### 2.12 Contact (`trackCustom`)
- **When**: click-to-call / WhatsApp / mailto links.

---

## 3. The `user_data` block (sent on every event)

Hashing rules per Meta's [normalization spec](https://developers.facebook.com/docs/marketing-api/conversions-api/parameters/customer-information-parameters/): lowercase, trim, then SHA-256.

| Field | Source | Hash? | Notes |
|---|---|---|---|
| `em` | order email / user email | sha256 | lowercase + trim |
| `ph` | order phone | sha256 | strip non-digits, prepend `371` if missing country code, no `+` |
| `fn`, `ln` | first / last name | sha256 | lowercase + trim |
| `ct`, `st`, `zp`, `country` | shipping address | sha256 | lowercase, no spaces; country = ISO-2 lowercase |
| `external_id` | `Buddies.user.id` (or session-stable anon id) | sha256 | |
| `client_ip_address` | `request()->ip()` | no | |
| `client_user_agent` | `request()->userAgent()` | no | |
| `fbp` | `_fbp` cookie | no | see fix below |
| `fbc` | `_fbc` cookie OR `?fbclid=` | no | see fix below |

**Minimum to reach EMQ ≥ 8**: `em` + `ph` + `client_ip_address` + `client_user_agent` + `fbp` + `fbc`.
**To push EMQ → 10**: add `fn` + `ln` + `ct` + `zp` + `country` + `external_id` (which we do at order time).

### Critical fix — empty `_fbp` / `_fbc` at nailscosmetics.lv (observed live)

Today the cookies are **empty at runtime** — Pixel is loaded but no fbp/fbc is set. Two reasons it can happen: cookie banner blocking Meta's own writes, or a domain-config mismatch. Either way Pixel is half-blind.

**Fix in plugin:** a Laravel middleware `EnsureFbpFbcCookies`:

1. On every request lacking `_fbp`, set it server-side: `fb.{subdomainIndex}.{creationTime}.{randomNumber}`.
2. On any request carrying `?fbclid=...`, set `_fbc` immediately: `fb.{subdomainIndex}.{creationTime}.{fbclid}`. Persist 90 days.

This guarantees `fbp` and `fbc` always reach CAPI even if Meta's snippet is blocked.

---

## 4. Event hooks in OctoberCMS 4.3 + Lovata Shopaholic

Subscribed in `Plugin.php → boot()`:

```php
// Cart events
Event::listen('shopaholic.cart.element.after.add',     CartListener::class.'@added');
Event::listen('shopaholic.cart.element.after.update',  CartListener::class.'@updated');
Event::listen('shopaholic.cart.element.after.remove',  CartListener::class.'@removed');

// Wishlist (oc-favorites-shopaholic-plugin)
Event::listen('shopaholic.favorite.element.after.add', WishlistListener::class.'@added');

// Order lifecycle (Lovata.OrdersShopaholic)
Event::listen('shopaholic.order.created', OrderListener::class.'@created');           // → InitiateCheckout-completed signal
\Lovata\OrdersShopaholic\Models\Order::extend(function ($model) {
    $model->bindEvent('model.afterUpdate', function () use ($model) {
        app(OrderStatusWatcher::class)->handle($model);                                // → Purchase (only if status flipped to Paid)
    });
});

// User registration (Lovata.Buddies)
Event::listen('lovata.buddies.user.after.register',    UserListener::class.'@registered');
```

`OrderStatusWatcher::handle($order)` logic:

```php
if ($order->status->code !== Settings::get('paid_status_code', 'paid')) return;
if ($order->meta_purchase_event_id) return;                  // already fired

$eventId = (string) Str::uuid();
$order->meta_purchase_event_id = $eventId;
$order->saveQuietly();

SendCapiEvent::dispatch(
    PayloadBuilder::purchase($order, $eventId)
);
```

For events Shopaholic doesn't expose natively (Search, ViewCategory) the theme calls `larajax.post('meta-pixel/track', {...})` and the backend handler does the dual-fire.

---

## 5. Why Larajax (and how)

OctoberCMS 4.3 ships its own `$.request()`, but **Larajax adds**:

- Promise / `await` syntax — clean integration with the JS we wrap around `fbq()`.
- Auto CSRF, auto JSON, auto `X-Requested-With`.
- Clean `response.meta` channel — perfect for shipping `event_id` + `custom_data` back to the browser without polluting the response body.

### Larajax routes (registered in `routes.php`)

```php
Larajax::get ('meta-pixel/init',  [PixelController::class, 'init']);   // returns event_id + base config + user_data hashes
Larajax::post('meta-pixel/track', [PixelController::class, 'track']);  // fires arbitrary event (Search, ViewCategory, etc.)
Larajax::post('cart/add',         [CartController::class, 'add']);     // wraps Shopaholic's onAdd, returns meta block
```

### Frontend wrapper (single tiny script the theme includes)

```js
import { larajax } from 'larajax';

export async function metaTrack(eventName, customData = {}) {
  const r = await larajax.post('meta-pixel/track', { event_name: eventName, custom_data: customData });
  fbq('track', eventName, r.meta.custom_data, { eventID: r.meta.event_id });
}

document.addEventListener('click', async (e) => {
  const btn = e.target.closest('.btn-add-to-cart');
  if (!btn) return;
  e.preventDefault();
  const r = await larajax.post('cart/add', {
    offer_id: btn.dataset.offerId,
    quantity: btn.dataset.quantity || 1
  });
  fbq('track', 'AddToCart', r.meta.custom_data, { eventID: r.meta.event_id });
  // existing cart UI refresh continues
});
```

---

## 6. Architecture (folders & classes)

```
plugins/logingrupa/metapixelshopaholic/
├── Plugin.php
├── plugin.yaml
├── classes/
│   ├── meta/
│   │   ├── MetaClient.php              # Guzzle wrapper, Graph API v20
│   │   ├── PayloadBuilder.php          # { data: [event] } structure
│   │   ├── UserDataHasher.php          # SHA-256 normalisation
│   │   ├── ContentMapper.php           # Shopaholic Offer → Meta contents[]
│   │   └── EventIdGenerator.php
│   ├── listeners/
│   │   ├── CartListener.php
│   │   ├── OrderListener.php
│   │   ├── OrderStatusWatcher.php      # Purchase brain
│   │   ├── UserListener.php
│   │   └── WishlistListener.php
│   ├── jobs/
│   │   └── SendCapiEvent.php           # Queued, 3× retry, dead-letter on fail
│   ├── middleware/
│   │   └── EnsureFbpFbcCookies.php     # Fixes the empty-cookies bug
│   └── helpers/
│       ├── Consent.php                 # Reads cookie-banner state
│       └── ViewBag.php                 # Per-request event_ids for Twig
├── controllers/
│   └── PixelController.php             # Larajax endpoints
├── models/
│   ├── Settings.php                    # Backend settings page
│   └── FailedEvent.php                 # Dead-letter table
├── updates/
│   ├── version.yaml
│   ├── create_failed_events_table.php
│   └── add_meta_purchase_event_id_to_orders_table.php
├── components/
│   ├── PixelHead.php                   # Base snippet + per-page event
│   ├── ProductPagePixel.php            # ViewContent
│   ├── CategoryPagePixel.php           # ViewCategory
│   └── CheckoutPixel.php               # InitiateCheckout
├── assets/js/
│   └── meta-pixel.js                   # Larajax wrapper
└── lang/{en,lv,ru}/
```

---

## 7. Backend settings page (reusable across LoginGrupa client sites)

| Setting | Default | Purpose |
|---|---|---|
| Pixel ID | (empty) | Meta Pixel id |
| CAPI access token | (empty) | Long-lived Graph token |
| Test event code | (empty) | For Events Manager → Test Events |
| Default currency | `EUR` | |
| Phone country code | `371` | Used when normalising phones lacking a country code |
| Consent helper class | `\LoginGrupa\…\Helpers\Consent` | Per-site override |
| Send hashed PII | `true` | Off for sites without a DPA |
| Queue connection | `redis` | |
| Order status code = "Paid" | `paid` | Match Shopaholic's status code on this install |
| Re-fire Purchase if status flips back | `false` | Idempotency guard |
| Use server-side `_fbp` if missing | `true` | Closes the cookie-blocked gap |
| Strict consent mode (suppress all without opt-in) | `false` | Stricter GDPR |

---

## 8. Health & dedup verification

Plugin ships a backend "Health" page that:

1. Sends a known-good event with `test_event_code`.
2. Polls Events Manager API for the result.
3. Shows EMQ score and dedup % per event type.
4. Acceptance: dedup ≥ 80 %, EMQ ≥ 8 for Purchase, ≥ 6 for AddToCart.

Failed CAPI events land in `meta_pixel_failed_events` with full payload (PII redacted in log) for one-click manual replay within Meta's 7-day window.

---

## 9. Sprint plan (~3 weeks)

| Sprint | Days | Outcome |
|---|---|---|
| **S1 — Skeleton + cookie fix** | 3 | Plugin scaffold, Settings, base Pixel snippet via component, `EnsureFbpFbcCookies` middleware. **Alone fixes today's empty-cookies bug.** |
| **S2 — Purchase end-to-end** | 5 | CAPI client, queue, `OrderStatusWatcher`, idempotent Purchase event, dedup verified in Test Events. |
| **S3 — Funnel completion** | 5 | `ViewContent`, `AddToCart`, `InitiateCheckout`, `AddPaymentInfo`, `Search`, `Lead`, `CompleteRegistration` — all wired through Larajax with shared event_ids. |
| **S4 — Hardening + launch** | 3 | Health page, dead-letter UI, GDPR strict mode, lv/en/ru translations, README, marketplace listing under `LoginGrupa.MetaPixelShopaholic`. |

---

## 10. Expected outcomes (Meta's own forecasts for this account)

- **−14 %** cost per purchase (Pixel optimization → Purchase)
- **−13 %** cost per result + **+25.9 %** attributed conversions (CAPI added)
- **EMQ ≥ 8** for Purchase events
- Bank-transfer orders no longer invisible — every paid invoice now contributes to learning
- 5–8 % uplift in dynamic-ads relevance once `ViewContent` starts firing on PDP

---

## 11. Post-launch Ads Manager actions

After ~7 days of clean data with EMQ ≥ 8:

1. Switch the 6 e-commerce ad sets currently optimising on a lower funnel event over to **Conversion → Purchase** (PIXEL_OPTIMIZATION_HIE).
2. Apply SIGNALS_GROWTH_CAPI_V2 confirmation.
3. Merge / exclude the 2 audience-overlap ad sets Meta flagged.
4. Add 9:16 vertical video to ad sets missing Reels-friendly creative (forecast −12 % cost per result).
5. Refresh creative on **NAILS: Akcijas** (Frequency 5.25 — fatigue).

---

## 12. Open questions still needed before sprint 1

1. Which Shopaholic order-status code = "Paid" on this install? (likely `samaksats` or `paid`)
2. Existing cookie-banner / consent state location? (cookie name / column on user)
3. Multi-currency or EUR-only? (PRIMINENCE site appears EUR only)
4. Lead-form plugin: RainLab.User, RainLab.Builder, or custom?
5. Where to ship dead-letter alerts — Slack, email, Telegram?

Answer these five and sprint 1 can start.


---

## 13. Composer-installable plugin manifest

The plugin ships as a Composer package mirroring the `logingrupa/oc-retrypayment-plugin` template you provided. File: `plugins/logingrupa/metapixelshopaholic/composer.json`:

```json
{
    "name": "logingrupa/oc-metapixel-plugin",
    "type": "october-plugin",
    "description": "Meta Pixel + Conversions API for Lovata Shopaholic on OctoberCMS 4.x. Server-side event_id, deduplicated dual-channel events, OrderStatus=Paid Purchase trigger.",
    "license": "MIT",
    "authors": [
        { "name": "Logingrupa", "email": "info@logingrupa.lv" }
    ],
    "require": {
        "php": "^8.3",
        "october/system": "^4.0",
        "october/rain": "^4.0",
        "lovata/toolbox-plugin": "^2.2",
        "lovata/ordersshopaholic-plugin": "^1.33",
        "lovata/shopaholic-plugin": "^1.49",
        "guzzlehttp/guzzle": "^7.8",
        "ramsey/uuid": "^4.7"
    },
    "require-dev": {
        "pestphp/pest": "^3.0",
        "phpstan/phpstan": "^1.11",
        "phpmd/phpmd": "^2.15",
        "laravel/pint": "^1.18",
        "rector/rector": "^1.2",
        "mockery/mockery": "^1.6"
    },
    "autoload": {
        "psr-4": { "Logingrupa\\MetapixelShopaholic\\": "" }
    },
    "autoload-dev": {
        "psr-4": { "Logingrupa\\MetapixelShopaholic\\Tests\\": "tests/" }
    },
    "scripts": {
        "test":       "../../../vendor/bin/pest --configuration phpunit.xml",
        "test-cov":   "../../../vendor/bin/pest --coverage --min=90 --configuration phpunit.xml",
        "analyse":    "../../../vendor/bin/phpstan analyse --configuration=phpstan.neon",
        "baseline":   "../../../vendor/bin/phpstan analyse --configuration=phpstan.neon --generate-baseline=phpstan-baseline.neon",
        "phpmd":      "../../../vendor/bin/phpmd classes,components,controllers,models,Plugin.php text phpmd.xml",
        "pint":       "../../../vendor/bin/pint . --config=pint.json",
        "pint-test":  "../../../vendor/bin/pint . --config=pint.json --test",
        "rector-dry": "../../../vendor/bin/rector process --config=rector.php --dry-run",
        "rector":     "../../../vendor/bin/rector process --config=rector.php",
        "qa":         ["@pint-test", "@analyse", "@phpmd", "@test-cov"]
    },
    "extra": {
        "october": {
            "plugin":         "Logingrupa.MetapixelShopaholic",
            "installer-name": "metapixelshopaholic"
        }
    }
}
```

### Static-analysis configuration

`phpstan.neon` (level 10 — strictest):

```yaml
includes:
    - phpstan-baseline.neon
parameters:
    level: 10
    paths:
        - classes
        - components
        - controllers
        - models
        - Plugin.php
    bootstrapFiles:
        - ../../../bootstrap/app.php
    ignoreErrors: []
    treatPhpDocTypesAsCertain: true
    reportUnmatchedIgnoredErrors: true
    checkMissingIterableValueType: true
    checkGenericClassInNonGenericObjectType: true
    checkUninitializedProperties: true
    checkBenevolentUnionTypes: true
    checkExplicitMixedMissingReturn: true
    checkImplicitMixed: true
    checkTooWideReturnTypesInProtectedAndPublicMethods: true
```

`phpmd.xml` is copy-aligned with Lovata.Toolbox's ruleset (cleancode + codesize + design + naming + unusedcode), with one local override to allow the Hungarian-notation prefixes (`ob`, `s`, `i`, `b`, `a`, `f`).

`pint.json`:

```json
{
    "preset": "laravel",
    "rules": {
        "declare_strict_types": true,
        "strict_param": true,
        "ordered_imports": { "sort_algorithm": "alpha" },
        "no_unused_imports": true,
        "single_quote": true,
        "binary_operator_spaces": { "default": "single_space" }
    },
    "exclude": ["updates"]
}
```

`rector.php` includes `LevelSetList::UP_TO_PHP_83`, `SetList::CODE_QUALITY`, `SetList::DEAD_CODE`, `SetList::EARLY_RETURN`, `SetList::TYPE_DECLARATION`, `SetList::STRICT_BOOLEANS`.

---

## 14. Coding standards — Lovata.Toolbox Hungarian notation

The plugin follows Lovata.Toolbox conventions exactly so it feels native to any Shopaholic developer.

### Variable prefixes (Hungarian, mandatory)

| Prefix | Type | Example |
|---|---|---|
| `ob` | object instance | `$obOrder`, `$obPayloadBuilder` |
| `s` | string | `$sEventId`, `$sCurrency` |
| `i` | integer | `$iOrderId`, `$iEventTime` |
| `f` | float | `$fOrderTotal`, `$fItemPrice` |
| `b` | boolean | `$bConsentGranted`, `$bAlreadyFired` |
| `a` | array | `$aContents`, `$aUserData`, `$aCustomData` |
| `e` | enum case | `$eEventName` |
| `c` | collection (Laravel) | `$cFailedEvents` |

### Function naming — verb-first, self-explanatory, no abbreviations

Examples:

| Good | Bad |
|---|---|
| `buildPurchaseEventPayload(Order $obOrder, string $sEventId): array` | `buildPayload($o, $id)` |
| `hashCustomerEmailLowercased(string $sEmail): string` | `hashEm($e)` |
| `dispatchConversionsApiEventWithRetry(array $aPayload): void` | `send($p)` |
| `assertOrderIsTransitioningToPaidStatus(Order $obOrder): void` | `check($o)` |

### File header (every PHP file)

```php
<?php

declare(strict_types=1);

namespace Logingrupa\MetapixelShopaholic\Classes\Meta;

use Logingrupa\MetapixelShopaholic\Classes\Meta\Contracts\PayloadBuilderInterface;
// ... explicit imports, alphabetical, no wildcards
```

### DRY / SRP enforcement

- Every concrete class implements **one interface**, lives in **one file**, does **one job**. PHPMD rule `TooManyMethods=10` enforced.
- Cyclomatic complexity per method ≤ **6** (PHPMD `CyclomaticComplexity`).
- Method length ≤ **30 lines** (PHPMD `ExcessiveMethodLength`).
- Class length ≤ **250 lines** (PHPMD `ExcessiveClassLength`).
- No copy-paste between listeners — shared logic lives in `Classes/Meta/PayloadBuilder.php` and is injected via DI.

---

## 15. TigerStyle (TigerBeetle) — fail fast, fail hard, no fallbacks

Translated to PHP for this plugin, the rules are:

### 15.1 Assertions on every public method (preconditions and postconditions)

```php
public function buildPurchaseEventPayload(Order $obOrder, string $sEventId): array
{
    // Preconditions — fail loud, never silent.
    assert($obOrder->exists,                         'Order must be persisted before building Purchase payload');
    assert($obOrder->status?->code === self::STATUS_PAID,
                                                     'Purchase payload may only be built for orders in Paid status');
    assert($obOrder->meta_purchase_event_id !== null,
                                                     'meta_purchase_event_id must be set before building payload (idempotency contract)');
    assert(Uuid::isValid($sEventId),                 'event_id must be a valid UUID v4');
    assert($obOrder->total_price_value > 0,          'Order total must be strictly positive');
    assert(strlen((string) $obOrder->currency_code) === 3,
                                                     'Currency must be ISO-4217 (3 letters)');

    $aPayload = [/* ... */];

    // Postcondition — every Purchase payload MUST carry an order_id and a non-empty contents[].
    assert(isset($aPayload['custom_data']['order_id']),  'Built Purchase payload missing order_id');
    assert(!empty($aPayload['custom_data']['contents']), 'Built Purchase payload missing contents[]');

    return $aPayload;
}
```

`assert()` runs in dev (`zend.assertions=1`) and is compiled out in prod (`zend.assertions=-1`), so we get the safety in tests without paying in prod hot paths.

### 15.2 No error swallowing

PHPStan rule `disallowedFunctionCalls` blocks `@`, `error_reporting(0)`, and bare `catch (\Throwable)` without re-throw or log.

```php
// FORBIDDEN
try { $this->send($aPayload); } catch (\Throwable) {}

// REQUIRED
try {
    $this->dispatchConversionsApiEventWithRetry($aPayload);
} catch (MetaApiException $obException) {
    Log::error('meta-pixel.capi.send_failed', [
        'event_id'   => $aPayload['data'][0]['event_id'],
        'event_name' => $aPayload['data'][0]['event_name'],
        'http_code'  => $obException->getHttpStatusCode(),
        'graph_err'  => $obException->getGraphErrorMessage(),
    ]);
    FailedEvent::createFromPayloadAndException($aPayload, $obException);
    throw $obException;   // re-throw so the queue marks the job failed and retries
}
```

### 15.3 Log everything

Every public method on `MetaClient`, `OrderStatusWatcher`, `SendCapiEvent` job, and `PixelController` logs:

- Entry: `Log::debug('meta-pixel.{class}.{method}.enter', [/* args */])`
- Exit: `Log::debug('meta-pixel.{class}.{method}.exit',  [/* result summary */])`
- Error: `Log::error('meta-pixel.{class}.{method}.error', [/* full context */])`

State transitions (`Purchase fired`, `event queued`, `event dead-lettered`, `consent revoked`) log at `info`.

### 15.4 No fallbacks, no shortcuts

- If Settings model has no Pixel ID → throw `MissingPixelConfigurationException` on plugin boot. **Never silently skip events.**
- If hashing fails (e.g. mb_strtolower on invalid UTF-8) → throw, do not send a half-hashed payload.
- No "if CAPI fails, just rely on Pixel" fallback — both must succeed or the failure is recorded.
- No "fallback currency = EUR" if order has no currency — throw `OrderHasNoCurrencyException`.

### 15.5 Custom exception hierarchy (diagnostic context)

```
MetaPixelShopaholicException (abstract)
├── ConfigurationException
│   ├── MissingPixelConfigurationException
│   └── MissingCapiTokenException
├── PayloadException
│   ├── OrderHasNoCurrencyException
│   ├── OrderHasNoItemsException
│   └── InvalidEventIdException
└── ApiException
    ├── MetaApiTransientException        // retryable
    └── MetaApiPermanentException        // dead-letter
```

Every exception carries `getDiagnosticContext(): array` for structured logging.

---

## 16. Testing — Pest, one test per function, level-10 PHPStan-clean

### 16.1 Directory layout

```
tests/
├── Pest.php
├── TestCase.php                           # extends Orchestra Testbench (boots OctoberCMS app)
├── Unit/
│   ├── Meta/
│   │   ├── PayloadBuilderTest.php
│   │   ├── UserDataHasherTest.php
│   │   ├── ContentMapperTest.php
│   │   ├── EventIdGeneratorTest.php
│   │   └── MetaClientTest.php
│   ├── Listeners/
│   │   ├── CartListenerTest.php
│   │   ├── OrderListenerTest.php
│   │   ├── OrderStatusWatcherTest.php
│   │   ├── UserListenerTest.php
│   │   └── WishlistListenerTest.php
│   ├── Jobs/
│   │   └── SendCapiEventTest.php
│   ├── Middleware/
│   │   └── EnsureFbpFbcCookiesTest.php
│   └── Helpers/
│       ├── ConsentTest.php
│       └── ViewBagTest.php
├── Feature/
│   ├── PurchaseFlowOnCardPaymentTest.php
│   ├── PurchaseFlowOnBankTransferAdminMarksPaidTest.php
│   ├── PurchaseIdempotencyOnDoublePaidStatusTest.php
│   ├── AddToCartLarajaxRoundTripTest.php
│   ├── InitiateCheckoutOnCheckoutPageTest.php
│   ├── ViewContentOnProductPageTest.php
│   ├── EventDeduplicationByEventIdTest.php
│   ├── ConsentDeniedSuppressesEventsTest.php
│   ├── EnsureFbpFbcCookiesMiddlewareTest.php
│   └── DeadLetterReplayTest.php
└── Integration/
    ├── MetaTestEventsApiSmokeTest.php     # only runs with META_TEST_TOKEN env
    └── ShopaholicEventsAreSubscribedTest.php
```

### 16.2 Coverage policy

- **Every public method** in `classes/`, `controllers/`, `components/`, `models/` has at least one Unit test.
- **Every Shopaholic event hook** has a Feature test that asserts both Pixel data injection AND CAPI job dispatch (with the same `event_id`).
- **Every TigerStyle assertion** has a paired Pest test that triggers the assertion failure path (so we know our preconditions actually catch broken inputs).
- Minimum line coverage: **90 %** (enforced via `pest --coverage --min=90`, fails the QA pipeline below threshold).
- Mutation testing optional but recommended: `infection/infection` ≥ 80 % MSI before v1.0.0.

### 16.3 Sample Pest tests

`tests/Unit/Meta/PayloadBuilderTest.php`:

```php
<?php

declare(strict_types=1);

use Logingrupa\MetapixelShopaholic\Classes\Meta\PayloadBuilder;
use Logingrupa\MetapixelShopaholic\Exceptions\OrderHasNoCurrencyException;
use Lovata\OrdersShopaholic\Models\Order;

it('builds a Purchase payload with order_id, contents[], and ISO currency', function () {
    $obOrder = Order::factory()
        ->paid()
        ->withItems(2)
        ->create();
    $sEventId = '550e8400-e29b-41d4-a716-446655440000';

    $obBuilder = app(PayloadBuilder::class);
    $aPayload  = $obBuilder->buildPurchaseEventPayload($obOrder, $sEventId);

    expect($aPayload['data'][0]['event_name'])->toBe('Purchase');
    expect($aPayload['data'][0]['event_id'])->toBe($sEventId);
    expect($aPayload['data'][0]['custom_data']['order_id'])->toBe($obOrder->order_number);
    expect($aPayload['data'][0]['custom_data']['contents'])->toHaveCount(2);
    expect($aPayload['data'][0]['custom_data']['currency'])->toBe('EUR');
    expect($aPayload['data'][0]['custom_data']['value'])->toEqual($obOrder->total_price_value);
});

it('throws OrderHasNoCurrencyException when the order has no currency code', function () {
    $obOrder  = Order::factory()->paid()->withoutCurrency()->create();
    $obBuilder = app(PayloadBuilder::class);

    $obBuilder->buildPurchaseEventPayload($obOrder, '550e8400-e29b-41d4-a716-446655440000');
})->throws(OrderHasNoCurrencyException::class);

it('asserts the order is in Paid status (TigerStyle precondition)', function () {
    $obOrder  = Order::factory()->new()->create();   // status = new, not paid
    $obBuilder = app(PayloadBuilder::class);

    $obBuilder->buildPurchaseEventPayload($obOrder, '550e8400-e29b-41d4-a716-446655440000');
})->throws(AssertionError::class, 'Purchase payload may only be built for orders in Paid status');
```

`tests/Feature/PurchaseIdempotencyOnDoublePaidStatusTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Logingrupa\MetapixelShopaholic\Classes\Jobs\SendCapiEvent;
use Lovata\OrdersShopaholic\Models\Order;

it('dispatches Purchase exactly once even when status flips from paid → new → paid', function () {
    Bus::fake();

    $obOrder = Order::factory()->new()->create();

    // First transition to Paid
    $obOrder->status_id = paidStatusId();
    $obOrder->save();
    Bus::assertDispatchedTimes(SendCapiEvent::class, 1);

    // Flip back and forward
    $obOrder->status_id = newStatusId();
    $obOrder->save();
    $obOrder->status_id = paidStatusId();
    $obOrder->save();

    // Still exactly one dispatch — meta_purchase_event_id is set, watcher noops.
    Bus::assertDispatchedTimes(SendCapiEvent::class, 1);
});
```

`tests/Feature/EventDeduplicationByEventIdTest.php`:

```php
it('returns the same event_id in Larajax response and CAPI job payload', function () {
    Bus::fake();
    $sOfferId = OfferFactory::create()->id;

    $obResponse = $this->postJson('/larajax/cart/add', [
        'offer_id' => $sOfferId, 'quantity' => 1,
    ])->assertOk()->json();

    $sFrontendEventId = $obResponse['meta']['event_id'];

    Bus::assertDispatched(SendCapiEvent::class, function (SendCapiEvent $job) use ($sFrontendEventId) {
        return $job->aPayload['data'][0]['event_id'] === $sFrontendEventId
            && $job->aPayload['data'][0]['event_name'] === 'AddToCart';
    });
});
```

### 16.4 Mocked HTTP — never hit Meta in CI

`MetaClient` injects a `Guzzle\ClientInterface`. In tests we bind a `MockHandler` so every CAPI POST returns canned 200/4xx/5xx responses. The optional `tests/Integration/MetaTestEventsApiSmokeTest.php` only runs when `META_TEST_TOKEN` is set in CI secrets — uses `test_event_code` so nothing reaches production analytics.

---

## 17. QA pipeline — what `composer qa` runs (and what blocks merge)

```bash
composer qa
# → 1. pint --test           (style; fails if any file would change)
# → 2. phpstan analyse       (level 10; zero ignored errors outside baseline)
# → 3. phpmd ... text phpmd.xml   (cleancode/codesize/design/naming/unusedcode)
# → 4. pest --coverage --min=90    (100% of public methods covered, ≥ 90% lines)
```

Plus on demand:
- `composer rector-dry` — proposes modernisations; CI runs it on PR and posts the diff as a comment.
- `composer baseline` — only allowed at major version bumps; baseline file committed and reviewed.

CI workflow (GitHub Actions): two matrix legs — PHP 8.3 + 8.4 — both must be green. Coverage report uploaded to Codecov. Any of the four steps failing blocks merge.

---

## 18. Acceptance checklist before v1.0.0 tag

- [ ] `composer qa` is green on PHP 8.3 and 8.4.
- [ ] `pest --coverage --min=90` is green; every public method has a Unit test.
- [ ] PHPStan level 10, zero new errors above baseline.
- [ ] Hungarian notation used everywhere (`ob/s/i/f/b/a` prefixes), enforced by the PHPMD naming rule.
- [ ] All TigerStyle preconditions/postconditions present and tested.
- [ ] No `catch (\Throwable)` without log + re-throw or dead-letter persistence.
- [ ] Events Manager → Test Events shows dedup ≥ 80 %, EMQ ≥ 8 for Purchase using `test_event_code`.
- [ ] Plugin installs via `composer require logingrupa/oc-metapixel-plugin` on a clean OctoberCMS 4.3 + Shopaholic install.
- [ ] README documents all 5 open-question answers (currency, paid status code, consent helper, lead-form plugin, dead-letter sink).

---

## 19. Sprint plan — revised with QA built in

| Sprint | Days | Outcome |
|---|---|---|
| **S0 — Tooling** (1 day) | 1 | composer.json, phpstan.neon (level 10), phpmd.xml (Lovata.Toolbox-aligned), pint.json, rector.php, Pest scaffold, CI workflow, sample Hungarian-notation file. **No business logic yet — but `composer qa` is green on an empty plugin.** |
| **S1 — Skeleton + cookie fix** | 3 | Plugin scaffold, Settings, base Pixel snippet via component, `EnsureFbpFbcCookies` middleware. Every class has its Pest test. |
| **S2 — Purchase end-to-end** | 5 | CAPI client, queue, `OrderStatusWatcher`, idempotent Purchase, dedup verified in Test Events. Pest + PHPStan stay green. |
| **S3 — Funnel completion** | 5 | `ViewContent`, `AddToCart`, `InitiateCheckout`, `AddPaymentInfo`, `Search`, `Lead`, `CompleteRegistration` — all Larajax-wired with shared event_ids. Tests for each. |
| **S4 — Hardening + launch** | 3 | Health page, dead-letter UI, GDPR strict mode, lv/en/ru translations, README, marketplace listing. `composer qa` + acceptance checklist green. Tag v1.0.0. |
