# Logingrupa.MetapixelShopaholic — Plan v3 (codebase-aligned)

> **v3 vs v2**: Previous AI agent wrote v2 without access to `themes/logingrupa-naisstore/`, `plugins/lovata/toolbox/`, `plugins/lovata/shopaholic/`, `plugins/lovata/ordersshopaholic/`, `plugins/lovata/buddies/`, `plugins/lovata/wishlistshopaholic/`, or existing `plugins/logingrupa/*`. This v3 reconciles v2 against live code.
>
> Audit files backing every fix: `.planning/quick/20260422-metapixel-plan-refactor/audit-01..07.md`.

Plugin author: **Logingrupa** · Target stack: **OctoberCMS 4.x + Laravel 12 + Lovata Shopaholic** · Pixel ID on nailscosmetics.lv: `2291486191076331`.

---

## ⚠ THE ONE RULE — event_id contract

**De-duplication happens on Meta's side, not ours.** Our only job: make sure the **same `event_id`** arrives on both channels (browser Pixel `fbq()` call + server-side CAPI `POST /events`) for the same user action.

- Server generates `event_id` = UUID v4 once per event.
- Frontend `fbq('track', name, data, { eventID })` uses it.
- Backend `SendCapiEvent` job payload `data[0].event_id` uses it.
- If the request has no browser (bank-transfer admin-mark-paid), only CAPI fires — Meta still dedupes correctly because there was never a Pixel twin.

**If this contract is broken, Meta double-counts every event and EMQ/attribution breaks.** Every pattern below exists to enforce this rule. All code paths (Patterns A/B/C) flow `event_id` server → frontend, never frontend → server.

---

## 0. What this v3 changes vs v2

| # | v2 said | v3 says | Why |
|---|---------|---------|-----|
| 0.1 | `shopaholic.cart.element.after.{add,update,remove}` hooks | Only `shopaholic.cart.add` exists; update/remove fire NOTHING → listen on `CartPosition` model `updated`/`deleted` events | Grep of `ordersshopaholic/classes/processor/*.php` — only `OfferCartPositionProcessor.php:26` fires an event (`shopaholic.cart.add`). CartProcessor update/remove are silent. |
| 0.2 | `shopaholic.favorite.element.after.add` hook | Use `eloquent.created: Lovata\WishListShopaholic\Models\*` OR `addDynamicMethod('onAddToWishList')` on component | WishListShopaholic has **zero** `Event::fire()` calls — wishlist is UI-only component extension. |
| 0.3 | `lovata.buddies.user.after.register` hook | Use `eloquent.created: Lovata\Buddies\Models\User` | Buddies defines only `EVENT_BEFORE_LOGIN`, `EVENT_AFTER_LOGIN`, `EVENT_LOGOUT`. Register fires no event. |
| 0.4 | `Larajax::get/post(...)` route facade | Component handler methods (`onXxx`) called via `jax.ajax('Component::onXxx', ...)` | Larajax IS installed (`vendor/larajax/larajax`), but theme uses `jax.ajax('Cart::onAdd', {...})` targeting October CMS component handlers. No `routes/larajax.php` exists. |
| 0.5 | `classes/{listeners,jobs,middleware,helpers}/` | `classes/{event,queue,helper}/` + root `middleware/` | Lovata.Toolbox convention — lowercase singular. `middleware/` lives at plugin root, registered via `Plugin::boot() → registerMiddleware()`. |
| 0.6 | Settings model: plain `Model` + `models/Settings.php` | Extends `Lovata\Toolbox\Models\CommonSettings` · fields at `models/settings/fields.yaml` · read via `Settings::get('key', $default)` | Toolbox/Shopaholic pattern — `CommonSettings` auto-caches, supports Multisite trait. |
| 0.7 | "Order status = paid" → hardcoded `'paid'` | **Default `new-payment-received` (Status ID=5, custom)**. Dropdown still exposed in Settings for overrides. | DB query confirms live site has 8 statuses; PayPal + Vipps payment methods both have `after_status_id=5` → `new-payment-received`. Base Lovata `complete` is "shipped/done", NOT paid. Bank transfer + COD have null `after_status_id` — admin manually flips to ID=5. |
| 0.8 | `content_ids: ["SKU-648-6415"]` | `content_ids: ["SKU-{product_id}"]` (single-offer product) OR `["SKU-{product_id}-{offer_id}"]` (multi-offer) | **Matches the Facebook Catalog feed** at `plugins/logingrupa/facebookcatalogshopaholic/classes/helper/ExportCatalogFacebookHelper.php:356` — catalog emits `'offer_id' => 'SKU-' . $obOffer->product->id . '-' . $obOffer->id`. Existing tracking at `plugins/logingrupa/storeextender/classes/event/cart/CartComponentHandler.php:137-149` + `themes/logingrupa-naisstore/pages/checkout.htm:93-94` already build this format. Aligned Pixel + Catalog = Meta matches products correctly. |
| 0.9 | `assert()` TigerStyle preconditions | Explicit `throw new XxxException` — **never** `assert()` | Zero `assert(` usage across lovata/ + logingrupa/. `zend.assertions=0` in prod = silent no-op. Fail-fast = throw. |
| 0.10 | `catch (\Throwable)` forbidden via core PHPStan `disallowedFunctionCalls` | Install `spaze/phpstan-disallowed-calls ^4.0` explicitly | Rule doesn't exist in core PHPStan/larastan. |
| 0.11 | Pest `^3.0` | Pest `^4.1` (+ `pest-plugin-drift ^4.0`) | Root composer.json already pinned `pestphp/pest: ^4.1`. |
| 0.12 | PHPStan level 10 on greenfield plugin | Level 10 **plus** `universalObjectCratesClasses: [Lovata\Toolbox\Classes\Item\ElementItem, Lovata\Toolbox\Classes\Collection\ElementCollection]` | Required to silence `__get()` magic on Lovata Items/Collections (proven in `campaignpricingshopaholic/phpstan.neon:19`). |
| 0.13 | `PHPMD ExcessiveClassLength ≤ 250` · `CyclomaticComplexity ≤ 6` | Copy `lovata/toolbox/PHPMD_custom.xml` verbatim: Class ≤ 1000 · CC report ≥ 10 · `LongVariable max=40` (was 25) | Ecosystem norm — Toolbox `ElementCollection.php` is 849 lines. Plan v2's 250-line cap contradicts Toolbox baseline. |
| 0.14 | Orchestra Testbench base | `Logingrupa\Metapixelshopaholic\Tests\MetapixelTestCase` copy-pattern of `campaignpricingshopaholic/tests/CampaignPricingTestCase.php` | October CMS v4 + Pest 4 + PHPUnit 12 need wrapper that overrides `setUp(): void`. Testbench is for standalone packages. |
| 0.15 | Fail hard on boot if Pixel ID missing | Warn on boot; throw `MissingPixelConfigException` on first event attempt | Boot-time throw cascades through Campaigns/PromoMechanism plugin chain — breaks site. Event-time throw localises failure to the Meta plugin only. |
| 0.16 | v2 event_catalogue omits `event_time` on frontend `fbq()` calls | Every `fbq('track', ...)` MUST pass `event_time: Math.floor(Date.now()/1000)` in custom_data **AND** the CAPI job must use same timestamp | Meta dedup window is ±10 s. Theme's current `facebook-purchase-tracking.js` omits `event_time` → dedup fragile. |
| 0.17 | `EnsureFbpFbcCookies` middleware | Keep — middleware still mandatory, but lives at `middleware/EnsureFbpFbcCookies.php` (root), not `classes/middleware/` | Live site has empty `_fbp`/`_fbc` cookies (observed 2026-04-22). |
| 0.18 | `Purchase` idempotency column | New column `meta_purchase_event_id VARCHAR(36) NULL INDEX` after Order `secret_key` column | Verified via `table_create_order.php:38`. |
| 0.19 | Misspelled events (`InitiatedCheckout`, `ViewdOrderCompleatedStatusPage`) | Rename → `InitiateCheckout` (Meta standard) + `ViewedOrderConfirmation` (custom, retargeting only) | Unchanged from v2. |
| 0.20 | — | Anon `external_id` fallback for guest checkout: `hash('sha256', $obOrder->secret_key)` | Orders already carry `secret_key` (guest purchase URL token) — stable per-order, safe to hash. |

---

## 1. `event_id` direction — unchanged from v2

Server generates UUID v4 → frontend consumes via Twig global OR AJAX `meta` envelope. Three patterns:

### Pattern A — Server-rendered page events (PageView, ViewContent, ViewCategory, InitiateCheckout)

Component adds to the view via `$this->page['arMetaEvent']`:
```php
// components/ProductPagePixel.php :: onRun()
$sEventId  = (string) Str::uuid();
$iEventTime = time();
$aCustom    = PayloadBuilder::viewContent($this->obElement);

$this->page['arMetaEvent'] = [
    'event_id'   => $sEventId,
    'event_time' => $iEventTime,
    'event_name' => 'ViewContent',
    'custom_data'=> $aCustom,
];

SendCapiEvent::dispatch($sEventId, $iEventTime, 'ViewContent', $aCustom, UserDataHasher::forCurrentRequest(), 'website', Request::fullUrl());
```

Twig partial `facebook_pixel.htm` (extend current file, don't replace):
```twig
{% if arMetaEvent %}
<script>
  window.__metaEvt = {
    event_id:   '{{ arMetaEvent.event_id }}',
    event_time: {{ arMetaEvent.event_time }},
    name:       '{{ arMetaEvent.event_name }}',
    data:       {{ arMetaEvent.custom_data | json_encode | raw }}
  };
  fbq('track', window.__metaEvt.name,
      Object.assign({ event_time: window.__metaEvt.event_time }, window.__metaEvt.data),
      { eventID: window.__metaEvt.event_id });
</script>
{% endif %}
```

### Pattern B — AJAX events (AddToCart, AddToWishlist, Search)

No Larajax facade routes — **extend existing Lovata components** via `Cart::extend(function ($c) { $c->addDynamicMethod('onXxx', ...); })` (exact pattern used today in `logingrupa/storeextender/classes/event/cart/CartComponentHandler.php`).

Handler returns a `meta` envelope in addition to the component's standard response:
```php
// StoreExtender pattern — extend Cart component
\Lovata\OrdersShopaholic\Components\Cart::extend(function ($obComponent) {
    $obComponent->addDynamicMethod('onMetaTrackAddToCart', function () use ($obComponent) {
        // Called AFTER Cart::onAdd succeeded; reads last-added position from CartPosition store
        $obPosition = CartProcessor::instance()->getLastAddedPosition();
        assert_or_throw($obPosition !== null, OrderHasNoItemsException::class);

        $sEventId  = (string) Str::uuid();
        $iEventTime = time();
        $aCustom    = PayloadBuilder::addToCart($obPosition);

        SendCapiEvent::dispatch($sEventId, $iEventTime, 'AddToCart', $aCustom,
            UserDataHasher::forCurrentRequest(), 'website', Request::fullUrl());

        return [
            'meta' => [
                'event_id'    => $sEventId,
                'event_name'  => 'AddToCart',
                'event_time'  => $iEventTime,
                'custom_data' => $aCustom,
            ],
        ];
    });
});
```

Frontend:
```js
// After the existing await jax.ajax('Cart::onAdd', ...)
const meta = await jax.ajax('Cart::onMetaTrackAddToCart');
fbq('track', meta.meta.event_name,
    Object.assign({ event_time: meta.meta.event_time }, meta.meta.custom_data),
    { eventID: meta.meta.event_id });
```

### Pattern C — Backend-only Purchase (bank-transfer admin-mark-paid)

`meta_purchase_event_id` column on `lovata_orders_shopaholic_orders` → if non-null when watcher fires, noop. If null, generate UUID, persist, dispatch CAPI job, never re-fire.

---

## 2. Event catalogue — user_data inherited on every event

Rules:
- `content_ids` = `SKU-{product_id}` (single-offer product) OR `SKU-{product_id}-{offer_id}` (multi-offer). **Exact format the Facebook catalog feed emits** (`ExportCatalogFacebookHelper.php:356`). Reuse `CartComponentHandler` helpers — do NOT invent a new format.
- `content_name` = `$obOffer->product->name` (trimmed to 100 chars).
- `value`, `currency`, `contents[]` use `$obOffer->price_value` and `Settings::get('currency_code', 'EUR')`.
- `order_id` = `$obOrder->order_number` (verified format `260422-0002`).

Events:

- **§2.1 PageView** — `{}` custom_data.
- **§2.2 ViewContent** — PDP, via `ProductPagePixel` component (Pattern A).
- **§2.3 ViewCategory** — `trackCustom`, first 10 offer ids.
- **§2.4 Search** — `search_string`, first 10 result ids.
- **§2.5 AddToCart** — Pattern B via `Cart::onMetaTrackAddToCart` dynamic method.
- **§2.6 AddToWishlist** — Pattern B via `ExtendProductComponent::onMetaTrackAddToWishList`.
- **§2.7 InitiateCheckout** — Replace today's misspelt `InitiatedCheckout`. Component on `/lv/checkout`.
- **§2.8 AddPaymentInfo** — fires when user toggles payment-method radio (`jax.ajax('MakeOrder::onMetaTrackPaymentInfo', ...)`).
- **§2.9 Purchase** — `OrderStatus → new-payment-received` (Status ID=5). Watcher at §4.
- **§2.10 Lead** — Wire into salon application form at `themes/logingrupa-naisstore/pages/salon/application-form.htm:13-74`. Extend its `onSend()` handler: before the mail sends, generate event_id + dispatch `SendCapiEvent::dispatch($sEventId, time(), 'Lead', ['content_name' => 'Salon application', 'content_category' => 'salon_inquiry'], UserDataHasher::forFormSubmission($aFormData), 'website', Request::fullUrl())`, return event_id in JSON response, browser fires `fbq('track', 'Lead', ..., { eventID })`.
- **§2.11 CompleteRegistration** — listen on `eloquent.created: Lovata\Buddies\Models\User`.
- **§2.12 Contact** — `trackCustom`, click-to-call / WhatsApp / mailto.

---

## 3. `user_data` block

Toolbox-style hasher, cached per-request via `CCache`:

```php
// classes/meta/UserDataHasher.php
namespace Logingrupa\Metapixelshopaholic\Classes\Meta;

use Illuminate\Support\Facades\Request;
use Lovata\Buddies\Models\User;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\Toolbox\Classes\Helper\CacheHelper;

class UserDataHasher
{
    /** @var string[] */
    private const TAG = ['meta-pixel-user-hash'];

    public static function forCurrentRequest(): array
    {
        $sCacheKey = 'req:'.(Request::header('X-Request-Id') ?: spl_object_id(Request::instance()));
        $aCached = \CCache::get(self::TAG, $sCacheKey);
        if ($aCached !== null) {
            return $aCached;
        }

        $obUser = User::getAuthHelper()->getUser();
        $aOut = self::buildFromUser($obUser);
        \CCache::forever(self::TAG, $sCacheKey, $aOut);
        return $aOut;
    }

    public static function forOrder(Order $obOrder): array
    {
        $sCacheKey = 'order:'.$obOrder->id;
        $aCached = \CCache::get(self::TAG, $sCacheKey);
        if ($aCached !== null) {
            return $aCached;
        }

        $aOut = self::buildFromOrder($obOrder);
        \CCache::forever(self::TAG, $sCacheKey, $aOut);
        return $aOut;
    }

    private static function sha(string $sValue): string
    {
        return hash('sha256', mb_strtolower(trim($sValue)));
    }

    private static function normPhone(string $sPhone): string
    {
        $sDigits = preg_replace('/\D+/', '', $sPhone);
        if (strlen($sDigits) < 10) {
            return $sDigits;
        }
        $sCountry = (string) \LogingrupaMetapixelSettings::get('phone_country_code', '371');
        return str_starts_with($sDigits, $sCountry) ? $sDigits : $sCountry.$sDigits;
    }

    private static function buildFromUser(?User $obUser): array
    {
        $aOut = [
            'client_ip_address' => Request::ip(),
            'client_user_agent' => (string) Request::userAgent(),
            'fbp'               => Cookie::get('_fbp'),
            'fbc'               => Cookie::get('_fbc'),
        ];
        if ($obUser === null) {
            return $aOut;
        }
        $aOut['em'] = self::sha($obUser->email);
        if ($obUser->phone)      { $aOut['ph'] = self::sha(self::normPhone($obUser->phone)); }
        if ($obUser->name)       { $aOut['fn'] = self::sha($obUser->name); }
        if ($obUser->last_name)  { $aOut['ln'] = self::sha($obUser->last_name); }
        $aOut['external_id'] = self::sha((string) $obUser->id);
        return $aOut;
    }

    private static function buildFromOrder(Order $obOrder): array
    {
        // ... same fields as buildFromUser but pulled off $obOrder->user + shipping_address
        // external_id: user_id hash if user, else sha256(secret_key) for guests.
        // Implementation omitted for brevity — one method per field, all ≤ 10 LOC.
    }
}
```

**Anon external_id fallback**: for guest orders, `external_id = sha256($obOrder->secret_key)` — stable per-order.

**`EnsureFbpFbcCookies` middleware** — unchanged from v2, at `middleware/EnsureFbpFbcCookies.php`. Registered via:
```php
// Plugin.php :: boot()
$this->registerMiddleware([
    \Logingrupa\Metapixelshopaholic\Middleware\EnsureFbpFbcCookies::class,
]);
```

---

## 4. Event hooks — audited and corrected

```php
// Plugin.php :: boot()

// Cart — ONLY 'shopaholic.cart.add' fires natively.
Event::listen('shopaholic.cart.add', CartListener::class.'@added');

// Cart update + remove — no native events. Listen on the Eloquent model.
\Lovata\OrdersShopaholic\Models\CartPosition::extend(function ($obModel) {
    $obModel->bindEvent('model.afterUpdate', fn() => app(CartListener::class)->updated($obModel));
    $obModel->bindEvent('model.afterDelete', fn() => app(CartListener::class)->removed($obModel));
});

// Wishlist — no native events. Extend the component.
\Lovata\WishListShopaholic\Components\ExtendProductComponent::extend(function ($obComponent) {
    $obComponent->addDynamicMethod('onMetaTrackAddToWishList', function () use ($obComponent) {
        /* dual-fire */
    });
});

// Order created (pre-payment)
Event::listen('shopaholic.order.created', OrderListener::class.'@created');

// Order paid — listen on Order model afterUpdate; idempotent via meta_purchase_event_id column.
\Lovata\OrdersShopaholic\Models\Order::extend(function ($obModel) {
    $obModel->bindEvent('model.afterUpdate', function () use ($obModel) {
        app(OrderStatusWatcher::class)->handle($obModel);
    });
});

// User registration — no Lovata event. Use Eloquent native.
Event::listen('eloquent.created: Lovata\\Buddies\\Models\\User', function ($obUser) {
    app(UserListener::class)->registered($obUser);
});
```

**`OrderStatusWatcher::handle()`** (unchanged logic, corrected status resolution):
```php
public function handle(Order $obOrder): void
{
    $sPaidCode = Settings::get('paid_status_code', 'complete');  // default 'complete' (Lovata base), override in backend
    if ($obOrder->status?->code !== $sPaidCode) {
        return;
    }
    if ($obOrder->meta_purchase_event_id !== null) {
        return;  // idempotent
    }

    $sEventId = (string) Str::uuid();
    $obOrder->meta_purchase_event_id = $sEventId;
    $obOrder->saveQuietly();

    SendCapiEvent::dispatch(
        $sEventId,
        time(),
        'Purchase',
        PayloadBuilder::purchase($obOrder),
        UserDataHasher::forOrder($obOrder),
        'website',
        url('/')
    );
}
```

---

## 5. AJAX transport — use Larajax that's already installed

Larajax present at `vendor/larajax/larajax/`. Theme already uses `jax.ajax('Component::onHandler', ...)`. No `Larajax::get(...)` routes — always go through component handlers.

CSRF: handled transparently by `vendor/larajax/larajax/resources/src/request/options.js` (reads `<meta name="csrf-token">` + `XSRF-TOKEN` cookie).

Plugin does NOT register routes. It only:
1. Extends existing Lovata components (`Cart`, `ExtendProductComponent`, `MakeOrder`, `ProductPage`, `CategoryPage`) with `onMetaTrack*` dynamic methods.
2. Ships `components/{Pixel,ProductPage,CategoryPage,Checkout}Pixel.php` to render per-page `fbq('track', ...)` snippets + queue CAPI.

---

## 6. Architecture — Lovata-aligned folders

```
plugins/logingrupa/metapixelshopaholic/
├── Plugin.php
├── plugin.yaml
├── composer.json
├── middleware/                              # Plugin root, NOT under classes/
│   └── EnsureFbpFbcCookies.php
├── classes/
│   ├── meta/
│   │   ├── MetaClient.php                   # Guzzle wrapper, Graph API v20
│   │   ├── PayloadBuilder.php
│   │   ├── UserDataHasher.php
│   │   ├── ContentMapper.php                # Offer → Meta contents[]
│   │   └── EventIdGenerator.php
│   ├── event/                               # Lovata convention (was: listeners/)
│   │   ├── CartListener.php
│   │   ├── OrderListener.php
│   │   ├── OrderStatusWatcher.php
│   │   ├── UserListener.php
│   │   └── WishlistListener.php
│   ├── queue/                               # Lovata singular (was: jobs/)
│   │   └── SendCapiEvent.php
│   ├── helper/                              # Lovata singular (was: helpers/)
│   │   ├── Consent.php
│   │   └── ViewBag.php
│   └── exception/
│       ├── MetaPixelException.php           # abstract base
│       ├── MissingPixelConfigException.php
│       ├── MissingCapiTokenException.php
│       ├── OrderHasNoCurrencyException.php
│       ├── OrderHasNoItemsException.php
│       ├── InvalidEventIdException.php
│       ├── MetaApiTransientException.php    # retryable
│       └── MetaApiPermanentException.php    # dead-letter
├── controllers/
│   ├── FailedEvents.php                     # Backend\Classes\Controller + ListController behavior
│   └── failedevents/
│       ├── config_list.yaml
│       └── _list_toolbar.htm
├── components/
│   ├── PixelHead.php
│   ├── ProductPagePixel.php
│   ├── CategoryPagePixel.php
│   └── CheckoutPixel.php
├── models/
│   ├── Settings.php                         # extends Lovata\Toolbox\Models\CommonSettings
│   ├── settings/
│   │   └── fields.yaml
│   ├── FailedEvent.php                      # plain Eloquent + Validation trait
│   └── failedevent/
│       └── columns.yaml
├── updates/
│   ├── version.yaml
│   ├── create_table_failed_events.php
│   └── add_meta_purchase_event_id_to_orders_table.php
└── lang/{en,lv,ru}/                         # RainLab.Translate compatible
```

**FailedEvent** stays plain `October\Rain\Database\Model` — admin-only audit log, no frontend exposure → no Toolbox Item wrapper needed.

---

## 7. Settings — Lovata `CommonSettings` pattern

```php
// models/Settings.php
namespace Logingrupa\Metapixelshopaholic\Models;

use Lovata\Toolbox\Models\CommonSettings;

class Settings extends CommonSettings
{
    public $implement = ['@RainLab.Translate.Behaviors.TranslatableModel'];
    public $translatable = ['pixel_id'];

    const SETTINGS_CODE = 'logingrupa_metapixelshopaholic_settings';
    public $settingsCode = 'logingrupa_metapixelshopaholic_settings';
    public $settingsFields = 'fields.yaml';

    public function getPaidStatusCodeOptions(): array
    {
        // Lists every Status in the system as { code => name }
        // Default on nailscosmetics.lv → 'new-payment-received' (ID=5)
        return \Lovata\OrdersShopaholic\Models\Status::lists('name', 'code');
    }
}
```

Access from code: `Settings::get('pixel_id')`, `Settings::get('paid_status_code', 'new-payment-received')`.

| Setting | Type | Default | Purpose |
|---|---|---|---|
| `pixel_id` | text | `2291486191076331` | Meta Pixel id |
| `capi_access_token` | password | — | Long-lived Graph token |
| `test_event_code` | text | — | Events Manager → Test Events |
| `currency_code` | text | `EUR` | Default for events w/o currency |
| `phone_country_code` | text | `371` | Normalise phones missing country code |
| `send_hashed_pii` | switch | `true` | Off for sites without DPA |
| `queue_connection` | dropdown (`redis`/`database`/`sync`) | `database` | Match `config/queue.php` |
| `paid_status_code` | dropdown from `Status` model | `new-payment-received` | Live site uses custom status ID=5, set by PayPal/Vipps gateways + admin-mark-paid for bank transfer |
| `refire_purchase_on_status_flip` | switch | `false` | Idempotency guard |
| `ensure_fbp_fbc_server_side` | switch | `true` | Closes cookie-blocked gap |

> `content_id_source` setting **dropped** — Facebook Catalog exporter forces `SKU-{product_id}[-{offer_id}]` format, no toggle needed.
> `strict_consent` + `consent_helper_class` settings **dropped for v1** — no consent banner on nailscosmetics.lv. Re-add if stakeholder later ships one.

---

## 8. Health page — Backend ListController

Controller at `controllers/FailedEvents.php`:
```php
namespace Logingrupa\Metapixelshopaholic\Controllers;

use Backend\Classes\Controller;
use BackendMenu;
use Logingrupa\Metapixelshopaholic\Models\FailedEvent;

class FailedEvents extends Controller
{
    public $implement = ['Backend.Behaviors.ListController'];
    public $listConfig = 'config_list.yaml';

    public function __construct()
    {
        parent::__construct();
        BackendMenu::setContext('Lovata.Shopaholic', 'shopaholic-menu', 'metapixel');
    }

    public function onReplay(): array
    {
        $iId = (int) post('id');
        $obFailed = FailedEvent::findOrFail($iId);
        app(\Logingrupa\Metapixelshopaholic\Classes\Meta\MetaClient::class)->replay($obFailed);
        \Flash::success('Replayed');
        return $this->listRefresh();
    }

    public function onCheckDedup(): \Illuminate\Http\JsonResponse
    {
        return response()->json(
            app(\Logingrupa\Metapixelshopaholic\Classes\Meta\MetaClient::class)->fetchTestEventsStatus()
        );
    }
}
```

Acceptance unchanged: dedup ≥ 80%, EMQ ≥ 8 for Purchase (Events Manager test-events endpoint).

---

## 9. Sprint plan

| Sprint | Days | Outcome |
|---|---|---|
| **S0 — Tooling** | 1 | `composer.json`, `phpstan.neon` (level 10 + larastan + universalObjectCrates for Lovata Item/Collection), `phpmd.xml` (copy `lovata/toolbox/PHPMD_custom.xml`, bump LongVariable to 40), `pint.json`, `rector.php`, `.github/workflows/test.yml`, Pest scaffold, `MetapixelTestCase` (copy of `CampaignPricingTestCase`). `composer qa` green on empty plugin. |
| **S1 — Skeleton + cookie fix** | 3 | Plugin scaffold, `Settings` extending `CommonSettings`, base Pixel via extended `facebook_pixel.htm` partial, `EnsureFbpFbcCookies` middleware registered via `Plugin::boot()`. Unit tests per class. **Alone fixes today's empty-cookies bug.** |
| **S2 — Purchase end-to-end** | 5 | `MetaClient`, `SendCapiEvent` queue job, `OrderStatusWatcher`, idempotent Purchase via `meta_purchase_event_id` column, dedup verified in Test Events. |
| **S3 — Funnel completion** | 5 | ViewContent, AddToCart (via `Cart` component extension), InitiateCheckout, AddPaymentInfo, ViewCategory, Search, Lead (hook salon form `onSend`), CompleteRegistration (`eloquent.created` on Buddies User). All share event_id + event_time. |
| **S4 — Hardening + launch** | 3 | `FailedEvents` backend list + `onReplay` button, `lang/{en,lv,ru}` translations, README, marketplace listing `Logingrupa.MetapixelShopaholic`. |

---

## 10. Open questions — RESOLVED via codebase investigation

| # | Question | Answer | Evidence |
|---|---|---|---|
| 1 | Which `Status::code` = "Paid"? | **`new-payment-received`** (Status ID=5) | DB query on nailscosmetics.lv: 8 statuses live; PayPal `PaymentMethod.after_status_id=5` + Vipps `after_status_id=5`. Bank transfer + COD have null → admin manually flips to ID=5. See `answer-paid-status.md`. |
| 2 | `content_id` source? | **`SKU-{product_id}`** (single-offer) / **`SKU-{product_id}-{offer_id}`** (multi-offer) | `ExportCatalogFacebookHelper.php:356` (Facebook feed) + `CartComponentHandler.php:137-149` (existing purchase tracking) both emit this format. Pixel must mirror the feed. |
| 3 | Lead-form plugin? | **Salon application form** at `themes/logingrupa-naisstore/pages/salon/application-form.htm:13-74` (native `onSend` handler, NOT Renatio.FormBuilder) | Submits name/email/phone/salon details, mails to naisofiss@gmail.com. Wire Lead event into `onSend()` — see §2.10. |
| 4 | Cookie/consent banner? | **None exists.** Fire all events unconditionally. | No cookie banner in theme/partials/layouts/plugins. Pixel already fires unconditionally at `facebook_pixel.htm:1-5`. Re-add gating if stakeholder later adds a GDPR banner. |
| 5 | Dead-letter alert sink? | **Log only + backend `FailedEvents` list with `onReplay`** (v1). External alerts (Slack/email/Telegram) deferred to v1.1 behind Settings dropdown. | No current ops channel; `Log::error('Metapixel.*', [...])` routes to `storage/logs/system.log` per `config/logging.php`. Admin watches backend list. |

**Status:** All five resolved. S0 can start immediately.

---

## 11. Composer manifest (v3)

`plugins/logingrupa/metapixelshopaholic/composer.json`:
```json
{
    "name": "logingrupa/oc-metapixel-plugin",
    "type": "october-plugin",
    "description": "Meta Pixel + Conversions API for Lovata Shopaholic on OctoberCMS 4.x. Server-side event_id, deduplicated dual-channel events, OrderStatus-driven Purchase trigger.",
    "license": "MIT",
    "authors": [ { "name": "Logingrupa", "email": "info@logingrupa.lv" } ],
    "require": {
        "php": "^8.4",
        "october/rain": "^4.0",
        "october/all": "^4.0",
        "lovata/toolbox-plugin": "^2.2",
        "lovata/ordersshopaholic-plugin": "^1.33",
        "lovata/shopaholic-plugin": "^1.32",
        "lovata/buddies-plugin": "^1.10",
        "guzzlehttp/guzzle": "^7.8",
        "ramsey/uuid": "^4.7"
    },
    "require-dev": {
        "pestphp/pest": "^4.1",
        "pestphp/pest-plugin-drift": "^4.0",
        "phpunit/phpunit": "^12.0",
        "larastan/larastan": "^3.0",
        "spaze/phpstan-disallowed-calls": "^4.0",
        "phpmd/phpmd": "^2.15",
        "laravel/pint": "^1.26",
        "rector/rector": "^2.0",
        "mockery/mockery": "^1.6"
    },
    "autoload":     { "psr-4": { "Logingrupa\\Metapixelshopaholic\\": "" } },
    "autoload-dev": { "psr-4": { "Logingrupa\\Metapixelshopaholic\\Tests\\": "tests/" } },
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
            "plugin":         "Logingrupa.Metapixelshopaholic",
            "installer-name": "metapixelshopaholic"
        }
    }
}
```

`phpstan.neon`:
```yaml
includes:
    - ../../../vendor/larastan/larastan/extension.neon
    - ../../../vendor/spaze/phpstan-disallowed-calls/extension.neon
    - phpstan-baseline.neon
parameters:
    level: 10
    paths:
        - classes
        - components
        - controllers
        - middleware
        - models
        - Plugin.php
    bootstrapFiles:
        - ../../../bootstrap/app.php
    universalObjectCratesClasses:
        - Lovata\Toolbox\Classes\Item\ElementItem
        - Lovata\Toolbox\Classes\Collection\ElementCollection
    disallowedFunctionCalls:
        - function: 'assert()'
          message: 'Use explicit throw — assert() is a no-op in production (zend.assertions=0).'
    reportUnmatchedIgnoredErrors: true
    treatPhpDocTypesAsCertain: true
    checkUninitializedProperties: true
```

`phpmd.xml` — copy of `plugins/lovata/toolbox/PHPMD_custom.xml` verbatim with:
- `LongVariable max=40` (was 25)
- Everything else identical (Class ≤ 1000 LOC · CC report ≥ 10 · ShortVariable min=4 → allows `$ob`, `$ar`, `$iN`, `$sX`).

`pint.json`:
```json
{
    "preset": "laravel",
    "rules": {
        "ordered_imports":        { "sort_algorithm": "alpha" },
        "no_unused_imports":      true,
        "single_quote":           true,
        "binary_operator_spaces": { "default": "single_space" }
    },
    "exclude": ["updates"]
}
```
> `declare(strict_types=1)` **not** enforced — zero occurrences in existing Lovata/Logingrupa files. Document as optional per-file; do not break ecosystem norm.

`rector.php` — `LevelSetList::UP_TO_PHP_84`, `SetList::CODE_QUALITY`, `SetList::DEAD_CODE`, `SetList::EARLY_RETURN`, `SetList::TYPE_DECLARATION`.

---

## 12. Coding standards — Lovata Hungarian (unchanged from v2 §14 except limits)

| Prefix | Type | Example |
|---|---|---|
| `ob` | object / model / item / collection | `$obOrder`, `$obPayloadBuilder`, `$obOffer` |
| `s` | string | `$sEventId`, `$sCurrencyCode` |
| `i` | integer | `$iOrderId`, `$iEventTime` |
| `f` | float | `$fOrderTotal` |
| `b` | boolean | `$bConsentGranted`, `$bAlreadyFired` |
| `a` | array | `$aContents`, `$aUserData` |

Function naming — verb-first, self-explanatory, no abbreviations (as in v2 §14).

**PHPMD limits (aligned with Toolbox):**
- Cyclomatic complexity ≥ 10 → warn (was v2's ≥ 6).
- Method length ≥ 100 LOC → warn (was v2's ≥ 30).
- Class length ≥ 1000 LOC → warn (was v2's ≥ 250).
- Public methods per class ≥ 10 → warn (was v2's ≥ 10 — unchanged).

---

## 13. TigerStyle — fail fast via **throw**, not assert

### 13.1 Preconditions via explicit throw

```php
public function buildPurchaseEventPayload(Order $obOrder, string $sEventId): array
{
    if (!$obOrder->exists) {
        throw new \InvalidArgumentException('Order must be persisted before building Purchase payload');
    }
    $sPaidCode = Settings::get('paid_status_code', 'complete');
    if ($obOrder->status?->code !== $sPaidCode) {
        throw new \LogicException("Purchase payload may only be built for orders in '{$sPaidCode}' status");
    }
    if ($obOrder->meta_purchase_event_id === null) {
        throw new InvalidEventIdException('meta_purchase_event_id must be set (idempotency contract)');
    }
    if (!Uuid::isValid($sEventId)) {
        throw new InvalidEventIdException('event_id must be a valid UUID v4');
    }
    if ((float) $obOrder->total_price_value <= 0) {
        throw new OrderHasNoItemsException('Order total must be strictly positive');
    }
    if (!$obOrder->currency?->code) {
        throw new OrderHasNoCurrencyException('Order has no currency');
    }

    $aPayload = [/* ... */];

    if (!isset($aPayload['custom_data']['order_id'])) {
        throw new \LogicException('Built Purchase payload missing order_id');
    }
    if (empty($aPayload['custom_data']['contents'])) {
        throw new \LogicException('Built Purchase payload missing contents[]');
    }

    return $aPayload;
}
```

> Why not `assert()`: `zend.assertions=0` is standard PHP-FPM production setting → assertions become no-ops → silent bugs. Throwing gives identical dev behaviour plus prod safety.

### 13.2 No error swallowing

Enforced via `spaze/phpstan-disallowed-calls`:
```yaml
# phpstan.neon (partial)
parameters:
    disallowedFunctionCalls:
        - function: 'assert()'
          message: 'Use explicit throw — assert() is a no-op in production.'
        - function: '@'
          message: 'Error suppression forbidden.'
    disallowedMethodCalls:
        - method: '*->__construct()'
          allowIn:
            - 'classes/exception/*'
          message: 'Only custom exception classes may suppress — log + re-throw elsewhere.'
```

```php
// FORBIDDEN
try { $this->send($aPayload); } catch (\Throwable) {}

// REQUIRED
try {
    $this->dispatchConversionsApiEventWithRetry($aPayload);
} catch (MetaApiTransientException $obException) {
    Log::warning('Metapixel.CAPI: transient failure', [
        'event_id'   => $aPayload['data'][0]['event_id'],
        'event_name' => $aPayload['data'][0]['event_name'],
        'http_code'  => $obException->getHttpStatusCode(),
        'graph_err'  => $obException->getGraphErrorMessage(),
    ]);
    throw $obException;                       // queue retries
} catch (MetaApiPermanentException $obException) {
    Log::error('Metapixel.CAPI: permanent failure', [...]);
    FailedEvent::createFromPayloadAndException($aPayload, $obException);
    // do NOT re-throw — queue shouldn't retry; dead-lettered.
}
```

### 13.3 Log convention

Match Logingrupa existing pattern: `ClassName: action description` + context array.
```php
Log::info('Metapixel.OrderStatusWatcher: Purchase queued', ['order_id' => $obOrder->id, 'event_id' => $sEventId]);
Log::warning('Metapixel.MetaClient: retrying after 429', ['event_id' => $sEventId, 'attempt' => 2]);
Log::error('Metapixel.SendCapiEvent: dead-lettered', ['event_id' => $sEventId, 'graph_err' => $sErr]);
```

### 13.4 No fallbacks — with one critical nuance

**Boot time:** if `Settings::get('pixel_id')` is empty, log warning and set a plugin-wide flag `$bPluginDisabled = true`. **Do not throw.** Throwing on boot would cascade-break Campaigns/PromoMechanism/Order flow and nuke the whole site. Event handlers short-circuit while the flag is true.

**Event time:** if flag is clear but `pixel_id` becomes empty mid-request (unlikely), throw `MissingPixelConfigException`. Pipeline dead-letters the event.

**CAPI job failure:** retry 3× w/ exponential backoff, then dead-letter. **Pixel still fires client-side** — that's graceful degradation of the *backup* channel, not a silent swallow.

---

## 14. Testing — Pest 4 + October CMS wrapper

### 14.1 Base test case

```php
// tests/MetapixelTestCase.php — copy of campaignpricingshopaholic/tests/CampaignPricingTestCase.php

namespace Logingrupa\Metapixelshopaholic\Tests;

use System\Tests\Bootstrap\TestCase;

abstract class MetapixelTestCase extends TestCase
{
    use \October\Tests\Concerns\InteractsWithAuthentication;
    use \October\Tests\Concerns\PerformsMigrations;
    use \October\Tests\Concerns\PerformsRegistrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runOctoberUpCommand();
    }
}
```

### 14.2 Layout (unchanged from v2 §16)

```
tests/
├── Pest.php
├── MetapixelTestCase.php
├── Factories/
│   ├── OrderFactory.php           # must be created — no Lovata factories exist
│   ├── OfferFactory.php
│   └── UserFactory.php
├── Unit/{Meta,Event,Queue,Middleware,Helper}/
└── Feature/
    ├── PurchaseFlowOnCardPaymentTest.php
    ├── PurchaseFlowOnBankTransferAdminMarksPaidTest.php
    ├── PurchaseIdempotencyOnDoublePaidStatusTest.php
    ├── AddToCartAjaxRoundTripTest.php
    └── EnsureFbpFbcCookiesMiddlewareTest.php
```

### 14.3 Sample

```php
// tests/Unit/Meta/PayloadBuilderTest.php
use Logingrupa\Metapixelshopaholic\Classes\Meta\PayloadBuilder;
use Logingrupa\Metapixelshopaholic\Classes\Exception\OrderHasNoCurrencyException;
use Logingrupa\Metapixelshopaholic\Tests\Factories\OrderFactory;

it('builds Purchase payload with order_id, contents[], currency', function () {
    $obOrder = OrderFactory::paid()->withItems(2)->create();
    $obOrder->update(['meta_purchase_event_id' => $sEventId = '550e8400-e29b-41d4-a716-446655440000']);

    $aPayload = app(PayloadBuilder::class)->buildPurchaseEventPayload($obOrder, $sEventId);

    expect($aPayload['data'][0]['event_name'])->toBe('Purchase');
    expect($aPayload['data'][0]['event_id'])->toBe($sEventId);
    expect($aPayload['data'][0]['custom_data']['order_id'])->toBe($obOrder->order_number);
    expect($aPayload['data'][0]['custom_data']['contents'])->toHaveCount(2);
});

it('throws OrderHasNoCurrencyException if order.currency is null', function () {
    $obOrder = OrderFactory::paid()->withoutCurrency()->create();
    app(PayloadBuilder::class)->buildPurchaseEventPayload($obOrder, '550e8400-e29b-41d4-a716-446655440000');
})->throws(OrderHasNoCurrencyException::class);

it('throws on event_id not UUID', function () {
    $obOrder = OrderFactory::paid()->withItems(1)->create();
    app(PayloadBuilder::class)->buildPurchaseEventPayload($obOrder, 'not-a-uuid');
})->throws(\Logingrupa\Metapixelshopaholic\Classes\Exception\InvalidEventIdException::class);
```

### 14.4 Mocked HTTP

`MetaClient` takes `GuzzleHttp\ClientInterface` via constructor. In tests: bind `MockHandler` via service container. Never hit Meta in CI.

Integration smoke (`tests/Integration/MetaTestEventsApiSmokeTest.php`) runs ONLY if `META_TEST_TOKEN` env var is set + uses `test_event_code`.

### 14.5 Coverage policy

- 90 % line coverage enforced via `pest --coverage --min=90`.
- Every precondition throw has a paired failure-path test.
- Mutation testing (Infection) optional post-v1.

---

## 15. CI — GitHub Actions

**Project currently has no `.github/workflows/`**. Add one as part of S0:

```yaml
# .github/workflows/metapixel-qa.yml
name: Metapixel QA
on:
  pull_request:
    paths: ['plugins/logingrupa/metapixelshopaholic/**']
  push:
    branches: [master]
    paths: ['plugins/logingrupa/metapixelshopaholic/**']
jobs:
  qa:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php: ['8.4']
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          coverage: pcov
      - run: composer install --no-interaction
      - working-directory: plugins/logingrupa/metapixelshopaholic
        run: composer install --no-interaction
      - working-directory: plugins/logingrupa/metapixelshopaholic
        run: composer qa
```

PHP 8.4 only (per CLAUDE.md).

---

## 16. Acceptance checklist before v1.0.0

- [ ] `composer qa` green on PHP 8.4.
- [ ] `pest --coverage --min=90` green.
- [ ] PHPStan level 10 + larastan + `universalObjectCrates` for Lovata Item/Collection → zero new errors above baseline.
- [ ] Hungarian notation enforced by PHPMD `ShortVariable min=4`.
- [ ] No `assert()` call anywhere — enforced by `spaze/phpstan-disallowed-calls`.
- [ ] No bare `catch (\Throwable)` or `catch (\Exception) {}` without log + re-throw or dead-letter persistence.
- [ ] Events Manager → Test Events shows dedup ≥ 80 %, EMQ ≥ 8 for Purchase using `test_event_code`.
- [ ] `EnsureFbpFbcCookies` middleware registered + tested — verifies `_fbp`/`_fbc` set server-side.
- [ ] `meta_purchase_event_id` column exists on `lovata_orders_shopaholic_orders`, idempotency test passes on status flip-flop.
- [ ] Plugin boots cleanly on empty Settings (warns, doesn't throw) — verified by feature test.
- [ ] `composer require logingrupa/oc-metapixel-plugin` works on clean OctoberCMS 4.x + Shopaholic install.
- [ ] README documents all 5 open-question answers (paid_status_code, content_id_source, lead form, consent, dead-letter alerting).

---

## 17. Expected outcomes (unchanged — Meta's own forecasts for nailscosmetics.lv)

- **−14 %** cost per purchase (Pixel → Purchase optimisation)
- **−13 %** cost per result · **+25.9 %** attributed conversions (CAPI)
- **EMQ ≥ 8** for Purchase
- Bank-transfer orders become visible to Meta for the first time
- 5–8 % uplift in dynamic-ads relevance once ViewContent fires on PDPs

---

## 18. Post-launch Ads Manager actions

Unchanged from v2 §11.

---

## 19. Post-v1 follow-up work (out of scope for this plan)

- Add model factories to `lovata/shopaholic` + `lovata/ordersshopaholic` (upstream PR) to remove test-side factory duplication.
- Propose a native `shopaholic.cart.update` / `shopaholic.cart.remove` event upstream.
- Propose `lovata.buddies.user.after.register` event upstream.
- Consider Campaign-plugin pricing tiers in ViewContent `content_price` field (depends on Logingrupa.CampaignpricingShopaholic maturity).
