# Phase 3: Purchase end-to-end — Pattern Map

**Mapped:** 2026-05-12
**Files analyzed:** 23 (17 production + 6 tests/fixtures)
**Analogs found:** 21 / 23 (2 files — `SendCapiEvent` queue job and `MetaClient` Guzzle wrapper — have NO direct project precedent; planner must use Laravel 12 idioms + RESEARCH.md guidance)

---

## File Classification

| New / Modified File | Role | Data Flow | Closest Analog | Match Quality |
|---|---|---|---|---|
| `classes/meta/MetaClient.php` | service (HTTP client) | request-response | `plugins/logingrupa/postnordshippingshopaholic/classes/api/PostNordClient.php` (project HTTP-client pattern) + `plugins/logingrupa/vippsshopaholic/classes/api/VippsApiClient.php` (closest payment-HTTP sibling, env-based base URL + retry classification skeleton) | partial — neither uses Guzzle `ClientInterface` injection. PostNord uses `Illuminate\Support\Facades\Http`; Vipps uses raw cURL. No Guzzle precedent. Planner must apply Laravel 12 / Guzzle 7 idiom; see "No analog" below. |
| `classes/meta/PayloadBuilder.php` | service (transform) | transform | `plugins/logingrupa/storeextender/classes/event/cart/CartComponentHandler.php` (lines 39-149 — same SKU/contents/value array shape; lines 137-149 are the byte-for-byte content_ids contract) + `plugins/logingrupa/facebookcatalogshopaholic/classes/helper/ExportCatalogFacebookHelper.php` lines 350-374 (initOffer envelope shape) | role-match (transform) — exact analog for SKU format + cart-shaped payload contract. |
| `classes/meta/UserDataHasher.php` | service (transform + cache) | transform | `plugins/lovata/toolbox/classes/store/AbstractStore.php` lines 50-82 (CCache `get`/`forever`/`clear` tag-cache pattern, called via `Kharanenka\Helper\CCache`) | role-match — same CCache tag-cache primitive. No PII-hasher precedent in the codebase. |
| `classes/queue/SendCapiEvent.php` | queue job | event-driven (background dispatch) | _NO `ShouldQueue` precedent in plugins/._ Closest legacy queue handlers: `plugins/lovata/toolbox/classes/queue/ImportItemQueue.php` + `plugins/lovata/discountsshopaholic/classes/queue/RunProductPriceProcessor.php` — both use the old `fire($obJob, $arData)` October-3 queue pattern. Phase 3 must adopt Laravel 12 `ShouldQueue`. See "No analog" below. | no analog — new pattern. |
| `classes/event/OrderStatusWatcher.php` | event handler (Event::subscribe) | event-driven | `plugins/lovata/shopaholic/classes/event/product/ProductModelHandler.php` (lines 29-40 — `subscribe($obEvent)` signature; Model::extend inside subscribe; bindEvent on `model.afterUpdate`/`model.afterCreate` is in the parent `plugins/lovata/toolbox/classes/event/ModelHandler.php` lines 27-62) + `plugins/logingrupa/vippsshopaholic/classes/event/PaymentMethodModelHandler.php` (lines 14-39 — non-Lovata simple subscriber + `$obEvent->listen(...)` pattern, no inheritance) | exact — `PaymentMethodModelHandler` is the closest non-Toolbox subscriber match for a single-purpose listener; `ModelHandler::subscribe()` is the canonical Lovata `model.afterUpdate`/`model.afterCreate` precedent. |
| `classes/exception/MetaPixelException.php` | exception (abstract base) | n/a | `plugins/logingrupa/goodsreceivedshopaholic/classes/exception/GoodsReceivedException.php` (abstract base extending `\RuntimeException`, public readonly `$arContext`) | exact — same shape, same plugin family, same Tiger-Style precedent. |
| `classes/exception/MissingPixelConfigException.php` | exception (concrete) | n/a | `plugins/logingrupa/goodsreceivedshopaholic/classes/exception/InvalidEanException.php` (`final class … extends GoodsReceivedException {}`) | exact. |
| `classes/exception/MissingCapiTokenException.php` | exception (concrete) | n/a | same as above | exact. |
| `classes/exception/OrderHasNoCurrencyException.php` | exception (concrete) | n/a | same as above | exact. |
| `classes/exception/OrderHasNoItemsException.php` | exception (concrete) | n/a | same as above | exact. |
| `classes/exception/InvalidEventIdException.php` | exception (concrete) | n/a | same as above | exact. |
| `classes/exception/MetaApiTransientException.php` | exception (concrete, retryable) | n/a | same as above | exact. |
| `classes/exception/MetaApiPermanentException.php` | exception (concrete, dead-letter) | n/a | same as above | exact. |
| `models/FailedEvent.php` | model (plain Eloquent + Validation) | CRUD | `plugins/logingrupa/backinstockshopaholic/models/OfferSubscriber.php` (plain `Model` + `use Validation` + `$table`, `$rules`, `$fillable`, no Toolbox Item wrapper) + `plugins/lovata/ordersshopaholic/models/Order.php` (Lovata-style `use Validation`, `$fillable`, `$jsonable`, `$dates`) | exact — `OfferSubscriber` is the closest "plain model + Validation, no Item wrapper" precedent. |
| `updates/{N}_add_meta_purchase_event_id_to_orders_table.php` | migration (add column) | CRUD (schema) | `plugins/logingrupa/extendshopaholic/updates/table_update_shipping_type_add_external_id_field.php` (exact `Schema::table($sTable, function (Blueprint $obTable) {...})` add-column-with-`->after('id')` + reversible `down()` pattern) + `plugins/lovata/ordersshopaholic/updates/table_update_orders_add_currency_field.php` (same plugin family, target table) | exact. |
| `updates/{N+1}_create_table_failed_events.php` | migration (create table) | CRUD (schema) | `plugins/logingrupa/backinstockshopaholic/updates/create_table_offersubscribers.php` (`Schema::create(self::TABLE, function (Blueprint $obTable) {...})` with `InnoDB` engine, indexed columns, reversible `down()`) | exact. |
| `Plugin.php` (modify) | plugin entry (extend boot) | event-driven | `plugins/lovata/discountsshopaholic/Plugin.php` lines 65-99 (`addEventListener()` private method called from `boot()`, multiple `Event::subscribe(...)` lines) + the existing `plugins/logingrupa/metapixelshopaholic/Plugin.php` lines 91-107 (current Phase 2 boot — append point after `$obKernel->pushMiddleware(...)`) | exact. |
| `tests/Support/OrderFixtures.php` | test fixture (factory) | CRUD | `plugins/logingrupa/retrypaymentshopaholic/tests/fixtures/FakePaymentGateway.php` (namespace + class shape) + `plugins/logingrupa/retrypaymentshopaholic/tests/unit/RetryPaymentHelperTest.php` lines 32-50 (`Order::create([...])` + `Status::forceCreate` real-DB factory pattern) | role-match — closest test factory precedent in the same plugin family. |
| `tests/Feature/OrderStatusWatcherTest.php` | feature test | event-driven | `plugins/logingrupa/metapixelshopaholic/tests/Feature/BootsWithoutPixelIdTest.php` (Phase 2 — extends `MetapixelTestCase`, `Settings::set/clearInternalCache + Cache::flush + PluginGuard::flush` triple-reset, `Log::spy()` assertion style) + `plugins/logingrupa/retrypaymentshopaholic/tests/unit/RetryPaymentHelperTest.php` (real-DB Order/Status with `Order::create([...])`) | exact — same plugin, same MetapixelTestCase parent. |
| `tests/Feature/SendCapiEventTest.php` | feature test | event-driven | `plugins/logingrupa/postnordshippingshopaholic/tests/unit/PostNordClientTest.php` lines 11-94 (Laravel `Http::fake` retry/error mock pattern — closest project test for an HTTP-emitting service). CONTEXT mandates Guzzle `MockHandler` instead. | partial — analogous test structure (mock HTTP + assert behavior + assert log lines) but the mocking facade differs. |
| `tests/Unit/PayloadBuilderTest.php` | unit test | transform | `plugins/logingrupa/retrypaymentshopaholic/tests/unit/RetryPaymentHelperTest.php` lines 75-83 (Pest `->throws(RuntimeException::class)` precondition assertion pattern, multiple precondition cases) | role-match. |
| `tests/Unit/UserDataHasherTest.php` | unit test | transform | same as above (Pest precondition style) | role-match. |
| `tests/Unit/MetaClientTest.php` | unit test | request-response | same as `SendCapiEventTest.php` analog | partial. |

---

## Pattern Assignments

### `classes/meta/MetaClient.php` (service, request-response)

**Analog:** `plugins/logingrupa/postnordshippingshopaholic/classes/api/PostNordClient.php` + `plugins/logingrupa/vippsshopaholic/classes/api/VippsApiClient.php`

**Why these analogs:** PostNord is the most-recent Logingrupa HTTP-client class with `declare(strict_types=1)`, explicit constructor injection, `private readonly` properties, and try/catch-on-HTTP-error idiom. Vipps owns the closest semantic match (payment-channel HTTP) — its env-based `getBaseUrl()` + `const BASE_URL_*` shape is the precedent for `MetaClient::GRAPH_VERSION = 'v20.0'` + `https://graph.facebook.com/v20.0/{pixel_id}/events`. Both are Logingrupa-namespaced and reflect this team's HTTP-client conventions.

**Imports pattern** (PostNord lines 1-9):
```php
<?php

declare(strict_types=1);

namespace Logingrupa\PostNordShippingShopaholic\Classes\Api;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Lovata\OrdersShopaholic\Classes\Item\ShippingTypeItem;
```

For MetaClient swap to:
```php
<?php

declare(strict_types=1);

namespace Logingrupa\Metapixelshopaholic\Classes\Meta;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixelshopaholic\Classes\Exception\MetaApiPermanentException;
use Logingrupa\Metapixelshopaholic\Classes\Exception\MetaApiTransientException;
use Logingrupa\Metapixelshopaholic\Models\Settings;
```

**Constructor pattern** (PostNord lines 24-32) — constructor-injectable client with default:
```php
public function __construct(
    private readonly string $sApiKey,
    private readonly string $sCountryCode = 'NO',
) {
}
```

Adapt to (CONTEXT Area 1 Q1 lock — ClientInterface injection):
```php
public function __construct(
    ?ClientInterface $obClient = null,
) {
    $this->obClient = $obClient ?? new Client([
        'base_uri' => 'https://graph.facebook.com/' . self::GRAPH_VERSION . '/',
        'timeout' => 5,
    ]);
}
```

**Env-based base URL constants** (Vipps lines 22-26):
```php
/** @var string Base URL for the Vipps Test environment */
const BASE_URL_TEST = 'https://apitest.vipps.no';

/** @var string Base URL for the Vipps Live (production) environment */
const BASE_URL_LIVE = 'https://api.vipps.no';
```

For MetaClient → single `const GRAPH_VERSION = 'v20.0'` (PROJECT.md "Custom Graph API endpoint version other than v20" out-of-scope locks v20).

**Error classification + log + throw pattern** (PostNord lines 110-117 + Vipps lines 422-440):
```php
} catch (\Exception $obException) {
    Log::warning('PostNord API test connection failed', [
        'message' => $obException->getMessage(),
    ]);

    return ['success' => false, 'message' => 'Connection error: ' . $obException->getMessage()];
}
```

For MetaClient — replace the soft `return ['success' => false]` with fail-fast `throw new MetaApiTransientException(...)` or `throw new MetaApiPermanentException(...)` per CONTEXT Area 1 Q4 transient list (408 / 429 / 500 / 502 / 503 / 504 + `ConnectException` → transient; anything else → permanent). Always `Log::warning(...)` first with `['meta_pixel.event_id' => ..., 'meta_pixel.http_status' => ..., 'meta_pixel.attempt' => ...]` context.

**Settings read pattern** (existing `plugins/logingrupa/metapixelshopaholic/classes/helper/PluginGuard.php` lines 134-136):
```php
$mPixelId = Settings::get('pixel_id', '');
$sPixelId = is_scalar($mPixelId) ? (string) $mPixelId : '';
```

For MetaClient — read `pixel_id`, `capi_access_token`, `test_event_code` lazily inside `send()` (NOT in constructor) so missing config throws `MissingPixelConfigException` / `MissingCapiTokenException` at event-time per CONTEXT Area 3 Q4.

---

### `classes/meta/PayloadBuilder.php` (service, transform)

**Analog:** `plugins/logingrupa/storeextender/classes/event/cart/CartComponentHandler.php`

**Why:** Method `getPixelPurchaseData()` is the byte-for-byte precedent — builds an array of `contents`, `content_ids`, `value`, `currency`, `num_items`, `tax`, `shipping` from a `CartPositionCollection`. PayloadBuilder for Purchase iterates `OrderPosition` instead of `CartPosition` but the output shape is identical. Same SKU helper (`buildSkuId`) lives at lines 137-149 — this is the contract MUST be byte-for-byte equal for Meta product-match.

**Imports pattern** (CartComponentHandler lines 1-9):
```php
<?php namespace Logingrupa\StoreExtender\Classes\Event\Cart;

use Input;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Product;
use Lovata\Shopaholic\Classes\Helper\CurrencyHelper;
use Lovata\Toolbox\Classes\Helper\PriceHelper;
use Lovata\OrdersShopaholic\Components\Cart;
use Lovata\OrdersShopaholic\Classes\Processor\CartProcessor;
```

For PayloadBuilder use `<?php` + `declare(strict_types=1);` + `namespace Logingrupa\Metapixelshopaholic\Classes\Meta;`. Imports: `Lovata\OrdersShopaholic\Models\Order`, `Lovata\OrdersShopaholic\Models\OrderPosition`, `Lovata\Shopaholic\Models\Offer`, `Lovata\Shopaholic\Models\Product`, `Ramsey\Uuid\Uuid`, the exception classes.

**SKU format pattern (LOAD-BEARING — byte-for-byte contract)** (CartComponentHandler lines 137-149):
```php
protected function buildSkuId($obOfferItem)
{
    $iProductId = $obOfferItem->product_id;
    $iOfferId = $obOfferItem->id;

    $iOfferCount = Offer::where('product_id', $iProductId)->count();

    if ($iOfferCount <= 1) {
        return 'SKU-' . $iProductId;
    }

    return 'SKU-' . $iProductId . '-' . $iOfferId;
}
```

PayloadBuilder MUST reproduce this exactly. Single-offer products: `SKU-{product_id}`. Multi-offer: `SKU-{product_id}-{offer_id}`. Lock: FUN-13 + PROJECT.md content_ids decision. Cross-checked against `plugins/logingrupa/facebookcatalogshopaholic/classes/helper/ExportCatalogFacebookHelper.php` lines 356-357.

**Contents-list pattern** (CartComponentHandler lines 75-95):
```php
foreach ($obCartPositionList as $obCartPositionItem) {
    $obOfferItem = $obCartPositionItem->item;
    if (empty($obOfferItem) || $obOfferItem->isEmpty()) {
        continue;
    }

    $obProductItem = $obOfferItem->product;
    $iQuantity = (int) $obCartPositionItem->quantity;
    $fPriceValue = (float) $obCartPositionItem->price_value;
    $fOfferValueTotal += $fPriceValue;

    $sSkuId = $this->buildSkuId($obOfferItem);

    // Facebook Pixel content item
    $arResult['contents'][] = [
        'id'       => $sSkuId,
        'quantity' => $iQuantity,
        'price'    => $fPriceValue,
    ];

    $arResult['content_ids'][] = $sSkuId;
```

For PayloadBuilder replace `$obCartPositionList` with `$obOrder->order_position` (HasMany on Order — see `plugins/lovata/ordersshopaholic/models/Order.php` lines 163-172). Replace `$obCartPositionItem->item` (which returns OfferItem) with looking up `Offer::find($obOrderPosition->offer_id)` or reading `$obOrderPosition->item_id` + `$obOrderPosition->item_type === 'Lovata\\Shopaholic\\Models\\Offer'`.

**Precondition throw pattern** (Plugin's own `plugins/logingrupa/goodsreceivedshopaholic/classes/orchestrator/ApplyOrchestrator.php` lines 200-221):
```php
private function assertNotApplied(Invoice $obInvoice): void
{
    if ((string) $obInvoice->status !== Invoice::STATUS_APPLIED) {
        return;
    }

    throw new ApplyAlreadyDoneException(
        (string) \Lang::get('logingrupa.goodsreceivedshopaholic::lang.exception.apply_already_done'),
        [
            'invoice_id' => (int) $obInvoice->id,
            // ... rich context
        ],
    );
}
```

Apply same shape for each PAY-09 precondition: `OrderHasNoCurrencyException`, `OrderHasNoItemsException`, `InvalidEventIdException`. Throw with `$arContext` array (`['order_id' => $obOrder->id, 'event_id' => $sEventId, ...]`) so `Log::error($sMsg, $obException->arContext)` is one-liner downstream.

---

### `classes/meta/UserDataHasher.php` (service, transform + cache)

**Analog:** `plugins/lovata/toolbox/classes/store/AbstractStore.php` (CCache primitive)

**Why:** CCache is the canonical Lovata cache primitive (Kharanenka helper); tag-based invalidation; used by every Lovata Store. CONTEXT Area 3 Q2 mandates `Lovata\Toolbox\Classes\CCache::storeReturnedValue($arTags, $callback)`. The real Kharanenka helper is `Kharanenka\Helper\CCache` per `AbstractStore.php` line 5 import — the planner must import from there (not from `Lovata\Toolbox\Classes\CCache` which does NOT exist in this version).

**Import pattern** (AbstractStore.php line 5):
```php
use Kharanenka\Helper\CCache;
```

**CCache get-or-compute pattern** (AbstractStore.php lines 32-58):
```php
protected function getIDList() : array
{
    //Get element ID list from cache
    $arElementIDList = $this->getIDListFromCache();
    if (!empty($arElementIDList) && is_array($arElementIDList)) {
        return $arElementIDList;
    }

    $arElementIDList = $this->getIDListFromDB();
    $this->saveIDList($arElementIDList);

    return $arElementIDList;
}

protected function getIDListFromCache() : array
{
    $arCacheTags = $this->getCacheTagList();
    $sCacheKey = $this->getCacheKey();

    $arElementIDList  = (array) CCache::get($arCacheTags, $sCacheKey);

    return $arElementIDList;
}
```

For UserDataHasher: tag = `['meta-pixel-user-hash']`; key per CONTEXT Area 3 Q2:
- `meta-pixel-user-hash:order:{$iOrderId}` (Phase 3 Purchase)
- `meta-pixel-user-hash:lead:{$sRequestId}` (Phase 4 Lead)
- `meta-pixel-user-hash:request:{$sRequestId}` (Phase 4 PageView / ViewContent / etc.)

Use `CCache::get($arTags, $sKey)` to read; `CCache::forever($arTags, $sKey, $arHashed)` to write (matches AbstractStore line 70). TTL: per CONTEXT 60s — replace `forever` with `Cache::tags($arTags)->put($sKey, $arHashed, 60)` if a TTL-aware CCache method doesn't exist, OR write a tiny custom `storeReturnedValueShort()` helper. Planner to confirm method availability against `vendor/kharanenka/helper-cache/src/CCache.php` before writing.

**Hash pattern (CONTEXT Specifics line 156):**
```php
hash('sha256', mb_strtolower(trim((string) $sValue)))
```

Apply to em, ph, fn, ln, external_id. Skip client_ip_address / client_user_agent / fbp / fbc (these are NOT hashed per Meta's CAPI spec — they are passed plaintext).

---

### `classes/queue/SendCapiEvent.php` (queue job, event-driven)

**Analog:** No `ShouldQueue` precedent in the codebase. Closest legacy queue handlers:
- `plugins/lovata/toolbox/classes/queue/ImportItemQueue.php` (October-3 `fire($obJob, $arQueueData)` pattern, calls `$obJob->delete()` manually)
- `plugins/lovata/discountsshopaholic/classes/queue/RunProductPriceProcessor.php` (same `fire($obJob, $iProductID)` shape)

**Why no exact analog:** Both legacy handlers are dispatched via October's deprecated `Queue::push('ClassName', $arData)` API; neither implements `ShouldQueue` or sets `$tries` / `$backoff`. Phase 3 introduces the modern Laravel 12 `ShouldQueue` pattern to this plugin codebase. Planner must follow Laravel 12 idiom directly (per CONTEXT Area 1 Q3).

**Laravel 12 ShouldQueue shape (from RESEARCH.md / Laravel docs — NO project precedent):**
```php
<?php

declare(strict_types=1);

namespace Logingrupa\Metapixelshopaholic\Classes\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixelshopaholic\Classes\Exception\MetaApiPermanentException;
use Logingrupa\Metapixelshopaholic\Classes\Exception\MetaApiTransientException;
use Logingrupa\Metapixelshopaholic\Classes\Meta\MetaClient;
use Logingrupa\Metapixelshopaholic\Models\FailedEvent;

final class SendCapiEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @var int Max attempts before dead-letter */
    public int $tries = 3;

    /** @var array<int, int> Exponential backoff in seconds */
    public array $backoff = [1, 4, 16];

    public function __construct(
        private readonly array $arPayload,
        private readonly string $sEventName,
    ) {
    }

    public function handle(MetaClient $obClient): void
    {
        try {
            $obClient->send($this->arPayload);
        } catch (MetaApiTransientException $obException) {
            // Re-throw so Laravel retries per $tries + $backoff.
            throw $obException;
        } catch (MetaApiPermanentException $obException) {
            // Dead-letter: persist + no rethrow. CONTEXT Area 1 Q2.
            FailedEvent::createFromPayloadAndException($this->arPayload, $obException);
            Log::error('Metapixel CAPI permanent failure (dead-lettered)', [
                'meta_pixel.event_id' => $this->arPayload['data'][0]['event_id'] ?? null,
                'meta_pixel.event_name' => $this->sEventName,
                'meta_pixel.http_status' => $obException->arContext['http_status'] ?? null,
            ]);
        }
    }
}
```

**Logger context-key precedent:** `plugins/logingrupa/metapixelshopaholic/classes/helper/PluginGuard.php` lines 148-150 (`['reason' => 'settings_read_failed', 'exception' => $obException->getMessage()]`). Phase 3 extends this convention with `meta_pixel.*` namespaced keys per CONTEXT Discretion #9.

---

### `classes/event/OrderStatusWatcher.php` (event handler, event-driven)

**Analog:** `plugins/lovata/shopaholic/classes/event/product/ProductModelHandler.php` lines 29-40 + `plugins/lovata/toolbox/classes/event/ModelHandler.php` lines 27-62 (parent — actual `bindEvent('model.afterUpdate', ...)` lives here)

**Why:** `ProductModelHandler::subscribe($obEvent)` is the canonical Lovata pattern referenced explicitly in CONTEXT Area 2 Q1. Toolbox `ModelHandler::subscribe()` is the parent that wires `model.afterCreate`, `model.afterSave`, `model.afterDelete` via `$sModelClass::extend(function ($obElement) { $obElement->bindEvent(...) })`. The simpler non-Toolbox alternative is `plugins/logingrupa/vippsshopaholic/classes/event/PaymentMethodModelHandler.php` lines 14-39 — single-purpose `subscribe($obEvent)` + `$obEvent->listen(...)` without inheriting from `ModelHandler`.

**Subscribe pattern A — Toolbox ModelHandler subclass** (ProductModelHandler.php lines 19-40 + parent ModelHandler.php lines 27-62):
```php
class ProductModelHandler extends ModelHandler
{
    /** @var  Product */
    protected $obElement;

    /**
     * Add listeners
     * @param \Illuminate\Events\Dispatcher $obEvent
     */
    public function subscribe($obEvent)
    {
        parent::subscribe($obEvent);

        Product::extend(function ($obModel) {
            // additional extension wiring
        });
    }

    protected function getModelClass()
    {
        return Product::class;
    }

    protected function getItemClass()
    {
        return ProductItem::class;
    }
}
```

The parent (ModelHandler.php lines 30-51) provides the actual `bindEvent`:
```php
$sModelClass::extend(function ($obElement) {

    /** @var \Model $obElement */
    $obElement->bindEvent('model.afterCreate', function () use ($obElement) {
        $this->obElement = $obElement;
        $this->init();
        $this->afterCreate();
    }, $this->iPriority);

    /** @var \Model $obElement */
    $obElement->bindEvent('model.afterSave', function () use ($obElement) {
        // ...
    }, $this->iPriority);
});
```

**Subscribe pattern B — direct `$obEvent->listen(...)`** (PaymentMethodModelHandler.php lines 21-31):
```php
public function subscribe($obEvent)
{
    // Add Vipps to the list of available payment gateways in the backend dropdown
    $obEvent->listen(
        PaymentMethod::EVENT_GET_GATEWAY_LIST,
        function () {
            return [
                'vipps' => 'Vipps MobilePay',
            ];
        }
    );
}
```

**Recommended for OrderStatusWatcher:** Pattern B (direct `listen()` via `eloquent.created` / `eloquent.updated`) per CONTEXT Area 2 Q1 exact wording — `$obEvents->listen('eloquent.updated: Lovata\OrdersShopaholic\Models\Order', [$this, 'handleUpdated']);`. Do NOT extend `ModelHandler` (that pattern is for cache-clearing Lovata Stores; this is a domain handler with idempotency logic that doesn't fit the `afterCreate/afterSave/afterDelete` template-method).

**Concrete shape:**
```php
<?php

declare(strict_types=1);

namespace Logingrupa\Metapixelshopaholic\Classes\Event;

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixelshopaholic\Classes\Queue\SendCapiEvent;
use Logingrupa\Metapixelshopaholic\Classes\Meta\PayloadBuilder;
use Logingrupa\Metapixelshopaholic\Models\Settings;
use Lovata\OrdersShopaholic\Models\Order;
use Ramsey\Uuid\Uuid;

final class OrderStatusWatcher
{
    public function subscribe(Dispatcher $obEvents): void
    {
        $obEvents->listen(
            'eloquent.updated: ' . Order::class,
            [$this, 'handleUpdated'],
        );
        $obEvents->listen(
            'eloquent.created: ' . Order::class,
            [$this, 'handleCreated'],
        );
    }

    public function handleUpdated(Order $obOrder): void
    {
        if (App::make('metapixel.disabled')) {
            return;
        }
        // status-flip guard + meta_purchase_event_id IS NULL guard + dispatch
    }

    public function handleCreated(Order $obOrder): void
    {
        // same precondition checks (admin-created at paid status — CONTEXT Area 2 Q2)
    }
}
```

**PluginGuard short-circuit pattern (entry-test)** — every Phase 3 handler MUST start with this. Source: `plugins/logingrupa/metapixelshopaholic/classes/helper/PluginGuard.php` lines 16-23 (documented contract) + `tests/Feature/EnsureFbpFbcCookiesTest.php` lines 65-69:
```php
if (App::make('metapixel.disabled')) {
    return;
}
```

**UUID generation pattern** — `plugins/logingrupa/metapixelshopaholic/components/PixelHead.php` line 93:
```php
'event_id' => Uuid::uuid4()->toString(),
```

OrderStatusWatcher MUST reuse this exact call (NOT `Str::uuid()` — `ramsey/uuid` is the locked dep per `composer.json` line 20 + TOOL-01).

**saveQuietly pattern** — `plugins/logingrupa/goodsreceivedshopaholic/classes/orchestrator/ApplyOrchestrator.php` lines 252-268:
```php
private function markInvoiceApplied(
    Invoice $obInvoice,
    StockApplyOutcome $obOutcome,
    int $iAppliedByUserId,
): void {
    $obInvoice->status = Invoice::STATUS_APPLIED;
    $obInvoice->applied_at = Carbon::now();
    $obInvoice->applied_by_user_id = $iAppliedByUserId;
    $obInvoice->stock_added_units = $obOutcome->result->units_added;
    $obInvoice->saveQuietly();
}
```

For OrderStatusWatcher — set `$obOrder->meta_purchase_event_id = $sUuid; $obOrder->saveQuietly();` inside `handleUpdated`. Critical (CONTEXT Area 2 Q4): `saveQuietly` suppresses model observers, preventing infinite recursion since we are currently inside `eloquent.updated`. NEVER use `DB::table('lovata_orders_shopaholic_orders')->update(...)` (bypasses Eloquent casts + Multisite trait + Encryptable).

---

### `classes/exception/MetaPixelException.php` (abstract base)

**Analog:** `plugins/logingrupa/goodsreceivedshopaholic/classes/exception/GoodsReceivedException.php` (full file, lines 1-59)

**Why:** Same plugin family (Logingrupa), same Tiger-Style typed-exception pattern, same `RuntimeException` base, same `public readonly array $arContext` PHP 8.4 immutability lock, same `jsonContext()` log-injection guard.

**Full pattern excerpt** (GoodsReceivedException.php lines 30-58):
```php
abstract class GoodsReceivedException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $arContext
     */
    public function __construct(
        string $sMessage,
        public readonly array $arContext = [],
        ?Throwable $obPrevious = null,
    ) {
        parent::__construct($sMessage, 0, $obPrevious);
    }

    /**
     * Encode a context array as a single-line JSON string safe for
     * `Log::error()` sinks. ... Returns `'{}'` if `json_encode` fails.
     *
     * @param  array<string, mixed>  $arContext
     */
    protected static function jsonContext(array $arContext): string
    {
        $sJson = json_encode($arContext, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $sJson !== false ? $sJson : '{}';
    }
}
```

Adapt: rename to `Logingrupa\Metapixelshopaholic\Classes\Exception\MetaPixelException`. Keep everything else verbatim.

---

### `classes/exception/{Missing*, OrderHas*, InvalidEventId, MetaApi*}Exception.php` (concrete)

**Analog:** `plugins/logingrupa/goodsreceivedshopaholic/classes/exception/InvalidEanException.php` (full file, lines 1-16)

**Full pattern excerpt:**
```php
<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Classes\Exception;

/**
 * Thrown by `EanMatcherService` (Phase 2 plan 02-06) as defense-in-depth at
 * the matcher boundary (security threat model). The HTM parser itself does
 * NOT throw this — D-16 dictates lenient parser behavior (log + skip).
 * Lang key `exception.invalid_ean`.
 */
final class InvalidEanException extends GoodsReceivedException
{
}
```

Apply seven times for each Phase 3 concrete exception. The docblock describes WHERE the exception is thrown + the lang key. PHP 8.4 `final` is mandatory (matches phpstan level 10 + this team's precedent).

---

### `models/FailedEvent.php` (plain model + Validation, no Item wrapper)

**Analog:** `plugins/logingrupa/backinstockshopaholic/models/OfferSubscriber.php` (full file, lines 1-97)

**Why:** OfferSubscriber is the closest "plain `October\Rain\Database\Model` + `use Validation`" model in the same plugin family — no Toolbox Item wrapper (PROJECT.md key decision: "FailedEvent = plain Model, no Toolbox Item wrapper"). Same conventions: `$table`, `$rules`, `$fillable`, `$attributeNames`, `$jsonable`, `$dates`, `$casts`.

**Imports + class skeleton** (OfferSubscriber.php lines 1-30):
```php
<?php namespace Logingrupa\BackInStockShopaholic\Models;

use Model;
use October\Rain\Database\Traits\Sluggable;
use October\Rain\Database\Traits\Validation;
use Kharanenka\Scope\NameField;
use Kharanenka\Scope\SlugField;
use Lovata\Toolbox\Traits\Helpers\TraitCached;

class OfferSubscriber extends Model
{
    use Sluggable;
    use Validation;
    use NameField;
    use SlugField;
    use TraitCached;

    /** @var string */
    public $table = 'logingrupa_backinstock_offersubscribers';
```

For FailedEvent — drop Sluggable / NameField / SlugField / TraitCached (admin-only audit log, no slug, never cached). Keep only `use Validation`. Adapt:
```php
<?php

declare(strict_types=1);

namespace Logingrupa\Metapixelshopaholic\Models;

use Model;
use October\Rain\Database\Traits\Validation;

class FailedEvent extends Model
{
    use Validation;

    /** @var string */
    public $table = 'logingrupa_metapixel_failed_events';

    public $rules = [
        'event_id' => 'required|string|max:36',
        'event_name' => 'required|string|max:64',
        'payload' => 'required|string',
        'http_status' => 'nullable|integer',
        'attempts' => 'required|integer',
    ];

    public $fillable = [
        'event_id',
        'event_name',
        'payload',
        'graph_error',
        'http_status',
        'attempts',
    ];

    public $jsonable = [];  // payload stored as longtext per CONTEXT Area 4 Q1
    public $dates = ['created_at', 'updated_at'];
    public $casts = [];
}
```

Add `createFromPayloadAndException(array $arPayload, MetaPixelException $obException): self` static factory per PAY-02 (CONTEXT Discretion #6).

---

### `updates/{N}_add_meta_purchase_event_id_to_orders_table.php` (migration, add column)

**Analog:** `plugins/logingrupa/extendshopaholic/updates/table_update_shipping_type_add_external_id_field.php` (full file, lines 1-43)

**Why:** Exact precedent for adding a column to an existing Lovata table with reversible down(). `Schema::table($sTable, function (Blueprint $obTable) {...})` + `->after('id')` positional placement + double-guarded up()/down() (`Schema::hasTable` AND `Schema::hasColumn`). CONTEXT specifies positioning after `secret_key` — the analog's `->after('id')` pattern adapts directly.

**Full pattern excerpt** (table_update_shipping_type_add_external_id_field.php lines 1-43):
```php
<?php namespace LoginGrupa\ExtendShopaholic\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

class TableUpdateShippingTypeAddExternalIdField extends Migration
{
    const TABLE_NAME = 'lovata_orders_shopaholic_shipping_types';

    /**
     * Apply migration
     */
    public function up()
    {
        if (!Schema::hasTable(self::TABLE_NAME) || Schema::hasColumn(self::TABLE_NAME, 'color')) {
            return;
        }

        Schema::table(self::TABLE_NAME, function (Blueprint $obTable) {
            $obTable->string('external_id')->after('id');
        });
    }

    /**
     * Rollback migration
     */
    public function down()
    {
        if (!Schema::hasTable(self::TABLE_NAME) || !Schema::hasColumn(self::TABLE_NAME, 'external_id')) {
            return;
        }

        Schema::table(self::TABLE_NAME, function (Blueprint $obTable) {
            $obTable->dropColumn(['external_id']);
        });
    }
}
```

Adapt for Phase 3 (PAY-04):
```php
<?php namespace Logingrupa\Metapixelshopaholic\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

class AddMetaPurchaseEventIdToOrdersTable extends Migration
{
    const TABLE_NAME = 'lovata_orders_shopaholic_orders';
    const COLUMN_NAME = 'meta_purchase_event_id';

    public function up()
    {
        if (!Schema::hasTable(self::TABLE_NAME) || Schema::hasColumn(self::TABLE_NAME, self::COLUMN_NAME)) {
            return;
        }

        Schema::table(self::TABLE_NAME, function (Blueprint $obTable) {
            $obTable->string(self::COLUMN_NAME, 36)->nullable()->after('secret_key')->index();
        });
    }

    public function down()
    {
        if (!Schema::hasTable(self::TABLE_NAME) || !Schema::hasColumn(self::TABLE_NAME, self::COLUMN_NAME)) {
            return;
        }

        Schema::table(self::TABLE_NAME, function (Blueprint $obTable) {
            $obTable->dropColumn([self::COLUMN_NAME]);
        });
    }
}
```

**Bug-watch:** SQLite cannot drop indexed columns (documented in `tests/MetapixelTestCase.php` lines 27-32 comment). The MetapixelTestCase `autoMigrate = false` + hermetic-tables pattern bypasses this. Phase 3 tests boot the migration on the hermetic `lovata_orders_shopaholic_orders` table — confirm SQLite-in-memory can apply `->string(...)->nullable()->after('secret_key')->index()` cleanly (`->after()` is a MySQL-only Blueprint hint and is silently ignored on SQLite).

**Plugin namespace casing:** Use `Logingrupa\Metapixelshopaholic\Updates` (capital L lowercase rest). DO NOT copy ExtendShopaholic's legacy `LoginGrupa` (camelCase L+G) — CLAUDE.md Conventions: "Plugin namespace: `Logingrupa\PluginName` (use `Logingrupa`, not `LoginGrupa`, in new code)".

---

### `updates/{N+1}_create_table_failed_events.php` (migration, create table)

**Analog:** `plugins/logingrupa/backinstockshopaholic/updates/create_table_offersubscribers.php` (full file, lines 1-58)

**Why:** Exact precedent in the same plugin family for creating a new logingrupa table — `engine = 'InnoDB'`, `increments('id')->unsigned()`, indexed columns, foreign keys (not applicable for FailedEvent), reversible `down()` with `Schema::dropIfExists`.

**Full pattern excerpt** (create_table_offersubscribers.php lines 1-58):
```php
<?php namespace Logingrupa\BackInStockShopaholic\Updates;

use Schema;
use Illuminate\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

class CreateTableOfferSubscribers extends Migration
{
    const TABLE = 'logingrupa_backinstock_offersubscribers';

    public function up()
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $obTable)
        {
            $obTable->engine = 'InnoDB';
            $obTable->increments('id')->unsigned();
            $obTable->unsignedInteger('subscriber_id');
            $obTable->unsignedInteger('offer_id');
            $obTable->enum('status', ['pending', 'sent', 'unsubscribed'])->default('pending')->index();
            $obTable->timestamp('sent_at')->nullable()->index();
            $obTable->timestamp('opened_at')->nullable()->index();
            $obTable->timestamps();

            $obTable->foreign('subscriber_id')->references('id')->on(...)->onDelete('cascade');
            // ...
            $obTable->unique(['subscriber_id', 'offer_id'], 'lg_bis_unique_subscriber_offer');
        });
    }

    public function down()
    {
        Schema::dropIfExists(self::TABLE);
    }
}
```

Adapt for FailedEvent per CONTEXT Area 4 Q1 + Q2:
```php
class CreateTableFailedEvents extends Migration
{
    const TABLE = 'logingrupa_metapixel_failed_events';

    public function up()
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $obTable) {
            $obTable->engine = 'InnoDB';
            $obTable->increments('id')->unsigned();
            $obTable->string('event_id', 36)->index();
            $obTable->string('event_name', 64)->index();
            $obTable->longText('payload');
            $obTable->text('graph_error')->nullable();
            $obTable->smallInteger('http_status')->unsigned()->nullable()->index();
            $obTable->unsignedInteger('attempts')->default(0);
            $obTable->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists(self::TABLE);
    }
}
```

**Important:** Use `October\Rain\Database\Schema\Blueprint` (matches ExtendShopaholic) OR `Illuminate\Database\Schema\Blueprint` (matches BackInStockShopaholic) — both work. Phase 3 should pick October-namespaced for consistency with other Lovata migrations.

---

### `Plugin.php` (modify — append Event::subscribe)

**Analog A — multiple subscribe lines:** `plugins/lovata/discountsshopaholic/Plugin.php` lines 62-99:
```php
public function boot()
{
    $this->addEventListener();
}

protected function addEventListener()
{
    Event::subscribe(ExtendBackendMenuHandler::class);
    Event::subscribe(ExtendFieldHandler::class);
    Event::subscribe(BrandModelHandler::class);
    Event::subscribe(BrandRelationHandler::class);
    // ... many more
}
```

**Analog B — current Phase 2 metapixel boot:** `plugins/logingrupa/metapixelshopaholic/Plugin.php` lines 91-107 (the file you will modify):
```php
public function boot(): void
{
    // 1) Prime PluginGuard in every context (CONTEXT Area 1 Q2-Q3 + SKEL-05).
    PluginGuard::instance();

    // 2) CLI-only gate (WR-01): no HTTP response in CLI = nothing to push to.
    if (App::runningInConsole()) {
        return;
    }

    // 3) Push EnsureFbpFbcCookies onto the global HTTP middleware stack.
    /** @var HttpKernel $obKernel */
    $obKernel = $this->app->make(HttpKernel::class);
    $obKernel->pushMiddleware(EnsureFbpFbcCookies::class);
}
```

**Where to insert OrderStatusWatcher subscription:** BEFORE the CLI-only gate (per CONTEXT Integration Points: "globally registered — storefront + backend + queue worker — backend admin status-flip is the bank-transfer path"). Order matters:

1. Prime PluginGuard (unchanged).
2. **NEW:** `Event::subscribe(OrderStatusWatcher::class);` — global, must fire in CLI too (artisan queue:work runs Order updates through bank-transfer admin flips initiated by web admin but the model events still need to fire on the worker if a saved-elsewhere Order is later loaded).
3. CLI gate — return early before middleware push (unchanged).
4. Middleware push (unchanged).

**Concrete diff (additive):**
```php
use Illuminate\Support\Facades\Event;
use Logingrupa\Metapixelshopaholic\Classes\Event\OrderStatusWatcher;

// ...

public function boot(): void
{
    PluginGuard::instance();

    // NEW Phase 3: subscribe to Order eloquent.updated/eloquent.created globally
    // (storefront, backend, queue worker — backend admin status-flip is the
    // bank-transfer path per CONTEXT Specifics line 149).
    Event::subscribe(OrderStatusWatcher::class);

    if (App::runningInConsole()) {
        return;
    }

    /** @var HttpKernel $obKernel */
    $obKernel = $this->app->make(HttpKernel::class);
    $obKernel->pushMiddleware(EnsureFbpFbcCookies::class);
}
```

---

### `tests/Support/OrderFixtures.php` (test fixture / factory)

**Analog:** `plugins/logingrupa/retrypaymentshopaholic/tests/fixtures/FakePaymentGateway.php` (namespace + placement) + `plugins/logingrupa/retrypaymentshopaholic/tests/unit/RetryPaymentHelperTest.php` lines 32-50 (`Order::create([...])` real-DB factory pattern) + `plugins/logingrupa/retrypaymentshopaholic/tests/RetryPaymentTestCase.php` lines 77-138 (hermetic `lovata_orders_shopaholic_orders` Schema::create pattern)

**Why:** RetryPayment is the closest project precedent for tests that need a real `Lovata.OrdersShopaholic.Order` row on SQLite-in-memory with hermetic-table provisioning. Phase 3 must extend `MetapixelTestCase::bootOrdersStatuses()` (already exists, lines 195-218) with a hermetic `lovata_orders_shopaholic_orders` + `lovata_orders_shopaholic_order_positions` set.

**Fixture placement pattern** (FakePaymentGateway.php lines 1-9):
```php
<?php namespace Logingrupa\RetrypaymentShopaholic\Tests\Fixtures;

use Lovata\OrdersShopaholic\Interfaces\PaymentGatewayInterface;

class FakePaymentGateway implements PaymentGatewayInterface
{
    // ...
}
```

For Phase 3 → `tests/Support/OrderFixtures.php` per CONTEXT Area 4 Q3 (note: planner may also choose `tests/Fixtures/OrderFixtures.php` — CONTEXT says either is fine; `Support` is more Laravel-idiomatic).

**Real-DB factory pattern** (RetryPaymentHelperTest.php lines 32-50):
```php
$obOrder = Order::create([
    'status_id' => 6,
    'transaction_id' => null,
    'secret_key' => 'test-secret-001',
]);
```

**Hermetic table provisioning pattern** (RetryPaymentTestCase.php lines 93-115):
```php
if (!Schema::hasTable('lovata_orders_shopaholic_orders')) {
    Schema::create('lovata_orders_shopaholic_orders', function (Blueprint $obTable) {
        $obTable->increments('id');
        $obTable->integer('user_id')->nullable();
        $obTable->integer('status_id')->nullable();
        $obTable->string('order_number')->nullable();
        $obTable->string('secret_key')->nullable();
        $obTable->decimal('total_price', 15, 2)->nullable();
        // ... etc.
        $obTable->timestamps();
    });
}
```

Phase 3 must add `meta_purchase_event_id` to the hermetic table (or run Phase 3 migration on the hermetic base). Cleanest path per MetapixelTestCase precedent (lines 195-218): add a `bootOrdersTable()` helper alongside the existing `bootOrdersStatuses()` that creates the orders table + applies the Phase 3 migration. Drop in `dropHermeticSchemas()` (lines 224-228).

---

### `tests/Feature/OrderStatusWatcherTest.php` (feature test, event-driven)

**Analog:** `plugins/logingrupa/metapixelshopaholic/tests/Feature/BootsWithoutPixelIdTest.php` (same plugin, same TestCase parent — Phase 2 precedent)

**Imports + class skeleton** (BootsWithoutPixelIdTest.php lines 1-43):
```php
<?php

namespace Logingrupa\Metapixelshopaholic\Tests\Feature;

require_once __DIR__.'/../MetapixelTestCase.php';

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixelshopaholic\Classes\Helper\PluginGuard;
use Logingrupa\Metapixelshopaholic\Models\Settings;
use Logingrupa\Metapixelshopaholic\Plugin;
use Logingrupa\Metapixelshopaholic\Tests\MetapixelTestCase;

final class BootsWithoutPixelIdTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Settings::clearInternalCache();
        PluginGuard::flush();
        Cache::flush();
    }
```

**Triple-reset pattern** (lines 39-42 + lines 47-49):
```php
Settings::set('pixel_id', '');  // or whatever Settings the test cares about
Settings::clearInternalCache();
PluginGuard::flush();
Cache::flush();
```

Phase 3 OrderStatusWatcherTest MUST replicate this for clean test isolation. Add `Queue::fake()` BEFORE dispatch assertions so `SendCapiEvent::dispatch(...)` can be asserted via `Queue::assertPushed(SendCapiEvent::class, …)`.

**Test assertions to write** (CONTEXT Specifics line 162 — five scenarios):
1. Fresh paid order fires Job → `Queue::assertPushed(SendCapiEvent::class, fn ($job) => …)`
2. Same order updated again at same paid status → `Queue::assertNotPushed(SendCapiEvent::class)` (after first assertion).
3. `refire_purchase_on_status_flip = true` + status-away + back → `Queue::assertPushedTimes(SendCapiEvent::class, 2)`.
4. `refire_purchase_on_status_flip = false` + status-away + back → `Queue::assertPushedTimes(SendCapiEvent::class, 1)`.
5. Plugin disabled (no pixel_id) → `Queue::assertNotPushed(SendCapiEvent::class)`.

**Log::spy pattern** (BootsWithoutPixelIdTest.php line 51 + lines 59-62):
```php
Log::spy();
// trigger code
Log::shouldHaveReceived('warning')
    ->atLeast()
    ->once()
    ->withArgs(fn ($sMsg) => is_string($sMsg) && str_contains($sMsg, 'pixel_id not configured'));
```

Reuse for OrderStatusWatcher dispatch-logged-context assertions.

---

### `tests/Feature/SendCapiEventTest.php` (feature test)

**Analog:** `plugins/logingrupa/postnordshippingshopaholic/tests/unit/PostNordClientTest.php` (HTTP mock + assert structure — but Laravel `Http::fake` not Guzzle MockHandler)

**Test structure analog** (PostNordClientTest.php lines 11-94):
```php
it('parses service points from API response', function (): void {
    Http::fake([
        'api2.postnord.com/*' => Http::response([...], 200),
    ]);

    $obClient = new PostNordClient('test-api-key', 'NO');
    $arResult = $obClient->findNearestByAddress('1528');

    expect($arResult)->toHaveCount(2);
    expect($arResult[0]['service_point_id'])->toBe('123456');
});

it('returns empty array on API error', function (): void {
    Http::fake([
        'api2.postnord.com/*' => Http::response('Server Error', 500),
    ]);
    // ...
});
```

**For Phase 3 — Guzzle MockHandler instead** (CONTEXT Area 1 Q1 + Specifics line 163 explicit mandate). No project analog; planner must apply Guzzle 7 idiom:
```php
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

it('retries 3 times on 503 then succeeds', function (): void {
    $obMock = new MockHandler([
        new Response(503),
        new Response(503),
        new Response(503),
        new Response(200, [], '{"events_received": 1}'),
    ]);
    $obStack = HandlerStack::create($obMock);
    $obGuzzle = new Client(['handler' => $obStack]);

    $obClient = new MetaClient($obGuzzle);
    // dispatch SendCapiEvent + assert behaviour
});

it('dead-letters after 4 503 responses', function (): void {
    $obMock = new MockHandler([
        new Response(503), new Response(503),
        new Response(503), new Response(503),
    ]);
    // ...
    expect(FailedEvent::count())->toBe(1);
});
```

**Test isolation pattern** — same triple-reset (Settings + Cache + PluginGuard) from BootsWithoutPixelIdTest. Plus `Queue::fake()` or run the job synchronously via `SendCapiEvent::dispatchSync(...)` so the Guzzle mock is exercised.

---

### `tests/Unit/PayloadBuilderTest.php` (unit test, transform)

**Analog:** `plugins/logingrupa/retrypaymentshopaholic/tests/unit/RetryPaymentHelperTest.php` lines 75-83 (Pest `->throws(...)` precondition test pattern)

**Precondition-throws pattern** (lines 75-83):
```php
test('it throws when retrying non-retryable order', function () {
    $obOrder = Order::create([
        'status_id' => 5,
        'transaction_id' => null,
        'secret_key' => 'test-secret-005',
    ]);

    RetryPaymentHelper::retry($obOrder, 1);
})->throws(RuntimeException::class);
```

For PayloadBuilderTest — one test per PAY-09 precondition:
```php
test('throws OrderHasNoCurrencyException when currency is null', function () {
    $obOrder = OrderFixtures::makePaidOrder();
    $obOrder->currency_id = null;
    $obOrder->saveQuietly();

    (new PayloadBuilder())->buildPurchaseEventPayload($obOrder, Uuid::uuid4()->toString(), time());
})->throws(OrderHasNoCurrencyException::class);

test('throws InvalidEventIdException when event_id is not UUID', function () {
    $obOrder = OrderFixtures::makePaidOrder();
    (new PayloadBuilder())->buildPurchaseEventPayload($obOrder, 'not-a-uuid', time());
})->throws(InvalidEventIdException::class);
```

Pest dataset alternative per CONTEXT Discretion #8 — one row per exception type if planner prefers data providers.

---

### `tests/Unit/UserDataHasherTest.php` (unit test, transform)

**Analog:** Same as PayloadBuilderTest. Plus determinism precondition:
```php
test('hash is deterministic across calls for the same input', function () {
    $obHasher = new UserDataHasher();
    $arA = $obHasher->forOrder(OrderFixtures::makePaidOrder());
    $arB = $obHasher->forOrder(OrderFixtures::makePaidOrder());
    expect($arA['em'])->toBe($arB['em']);
});
```

CCache flush in `setUp()` (Cache::flush + a `CCache::clear(['meta-pixel-user-hash'], $sKey)` call if needed) to prevent cross-test bleed.

---

### `tests/Unit/MetaClientTest.php` (unit test, request-response)

**Analog:** Same as `SendCapiEventTest.php` (Guzzle MockHandler) but at the smaller `MetaClient::send()` unit level.

Three required test cases per CONTEXT Area 1 Q4:
1. 503 → `MetaApiTransientException`.
2. 400 (bad payload, e.g. malformed event_id) → `MetaApiPermanentException`.
3. `ConnectException` (network failure) → `MetaApiTransientException`.

Backoff schedule `[1, 4, 16]` is asserted at the SendCapiEvent level (the job carries `public array $backoff`), NOT at MetaClient — MetaClient is stateless single-shot.

---

## Shared Patterns

### Hungarian Notation (mandatory, every new file)

**Source:** `plugins/lovata/toolbox/...` + project CLAUDE.md "Conventions" + every existing `plugins/logingrupa/metapixelshopaholic/**.php`.

**Apply to:** ALL files. PHPMD `ShortVariable min=4` permits `$ob`, `$ar`, `$iN` prefixes (TOOL-03).

| Prefix | Use |
|---|---|
| `$ob` | `$obOrder`, `$obClient`, `$obException`, `$obPayload` (Model / Item / object / exception / DTO) |
| `$ar` | `$arPayload`, `$arHashed`, `$arContext`, `$arUserData` |
| `$i` | `$iEventTime`, `$iOrderId`, `$iAttempts`, `$iHttpStatus` |
| `$s` | `$sEventId`, `$sUuid`, `$sCurrencyCode`, `$sMessage` |
| `$b` | `$bIsTransient`, `$bIsRetryable`, `$bDeadLettered`, `$bShouldRefire` |
| `$f` | `$fTotalValue`, `$fOfferValue` |

---

### PluginGuard short-circuit (every Phase 3 handler entry)

**Source:** `plugins/logingrupa/metapixelshopaholic/classes/helper/PluginGuard.php` lines 16-23 (documented contract); enforced in `tests/Feature/EnsureFbpFbcCookiesTest.php` lines 62-69 (the existing Phase 2 short-circuit test pattern).

**Apply to:** `OrderStatusWatcher::handleUpdated`, `OrderStatusWatcher::handleCreated`, every Phase 4 handler. NOT applied inside `SendCapiEvent::handle()` (the job is already queued; dispatch-time PluginGuard short-circuit at the watcher level is the only gate).

```php
if (App::make('metapixel.disabled')) {
    return;
}
```

---

### Settings read pattern

**Source:** `plugins/logingrupa/metapixelshopaholic/classes/helper/PluginGuard.php` lines 134-136 + `tests/Feature/BootsWithoutPixelIdTest.php` lines 46-48 (write side `Settings::set` + `Settings::clearInternalCache`).

**Apply to:** `MetaClient` (reads `pixel_id`, `capi_access_token`, `test_event_code`), `OrderStatusWatcher` (reads `paid_status_code`, `refire_purchase_on_status_flip`), `PayloadBuilder` (reads `currency_code` fallback), `UserDataHasher` (reads `phone_country_code`).

Read side:
```php
$mValue = Settings::get('pixel_id', '');
$sValue = is_scalar($mValue) ? (string) $mValue : '';
```

NEVER use `Settings::getValue('key')` (legacy Lovata API) in new Phase 3 code — `Settings::get()` is the Phase 2 lock per Settings model design.

Write side (tests):
```php
Settings::set('refire_purchase_on_status_flip', true);
Settings::clearInternalCache();
Cache::flush();
```

---

### Logger context-key namespace

**Source:** `plugins/logingrupa/metapixelshopaholic/classes/helper/PluginGuard.php` lines 148-150 (`['reason' => 'settings_read_failed', 'exception' => $obException->getMessage()]`) + CONTEXT Discretion #9.

**Apply to:** All Phase 3 `Log::warning(...)` / `Log::error(...)` calls.

Suggested keys (planner has discretion):
- `meta_pixel.event_id` — server-generated UUIDv4.
- `meta_pixel.event_name` — 'Purchase', 'ViewContent', etc.
- `meta_pixel.order_id` — `$obOrder->id`.
- `meta_pixel.order_number` — `$obOrder->order_number` (e.g. '260422-0002').
- `meta_pixel.attempt` — current retry count (1..3).
- `meta_pixel.http_status` — Graph response status.
- `meta_pixel.graph_error` — Graph API error message.

---

### Fail-fast Tiger-Style

**Source:** PROJECT.md "Constraints" + CLAUDE.md "Tiger-Style Rules" + `plugins/logingrupa/goodsreceivedshopaholic/classes/orchestrator/ApplyOrchestrator.php` lines 162-163, 200-221, 256 ("every `catch` either rethrows after logging OR has explicit reason comment").

**Apply to:** All Phase 3 production code. Every `catch (...)` body MUST be one of:
1. `Log::error(...)` then `throw $obException;` (re-throw).
2. `Log::error(...)` then `throw new ClassifiedException($sMsg, [...], $obException);` (re-classify).
3. Explicit `// silent: <reason>` comment + no rethrow (boundary-layer only).

PluginGuard.php lines 134-157 are the boundary-catch precedent (Settings read at boot must NOT cascade). NO other Phase 3 catch should be silent.

---

### CCache tag-cache primitive

**Source:** `plugins/lovata/toolbox/classes/store/AbstractStore.php` lines 5, 50-82 (`Kharanenka\Helper\CCache::get`/`forever`/`clear`).

**Apply to:** `UserDataHasher`. NOT applied to `MetaClient` (HTTP responses are not cached — per-event-id semantics) or `PayloadBuilder` (per-order recompute is cheap; cache would create stale-data hazards).

---

### saveQuietly inside-handler write

**Source:** `plugins/logingrupa/goodsreceivedshopaholic/classes/orchestrator/ApplyOrchestrator.php` lines 252-268 (`Invoice::saveQuietly()`).

**Apply to:** `OrderStatusWatcher::handleUpdated` when writing `meta_purchase_event_id`. NEVER use full `save()` (infinite recursion via the same `eloquent.updated` handler) or `DB::table()->update()` (bypasses casts + Multisite trait + Encryptable per CONTEXT Area 2 Q4).

---

### Pest test parent + triple-reset setUp

**Source:** `plugins/logingrupa/metapixelshopaholic/tests/MetapixelTestCase.php` (the parent) + `tests/Feature/BootsWithoutPixelIdTest.php` lines 32-43 (the triple-reset).

**Apply to:** All Phase 3 tests (`OrderStatusWatcherTest`, `SendCapiEventTest`, `PayloadBuilderTest`, `UserDataHasherTest`, `MetaClientTest`).

```php
final class XxxTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Settings::clearInternalCache();
        PluginGuard::flush();
        Cache::flush();
        Queue::fake();           // Phase 3 NEW: queue dispatch asserts
        $this->bootSystemSettings();
        $this->bootOrdersStatuses();
        // Phase 3 NEW: $this->bootOrdersTable() + migration
    }
}
```

---

### Exception hierarchy + `arContext` for log injection

**Source:** `plugins/logingrupa/goodsreceivedshopaholic/classes/exception/GoodsReceivedException.php` (full file).

**Apply to:** All 8 Phase 3 exception files. Each concrete class is `final class XxxException extends MetaPixelException {}` with a class-level docblock describing where it's thrown + the lang key. PHPDoc shape:

```php
/**
 * Thrown by `PayloadBuilder::buildPurchaseEventPayload` when `$obOrder->currency`
 * relation is null (orphan / corrupted Order row). Should never happen on a
 * persisted order — Lovata.OrdersShopaholic seeds `currency_id` on `OrderProcessor`
 * create-path. Lang key `exception.order_has_no_currency`.
 */
final class OrderHasNoCurrencyException extends MetaPixelException
{
}
```

---

## No Analog Found

| File | Role | Data Flow | Reason | Planner Mitigation |
|---|---|---|---|---|
| `classes/queue/SendCapiEvent.php` | queue job | event-driven | NO `ShouldQueue` precedent in any plugin under `/home/forge/nailscosmetics.lv/plugins/`. The only existing queue handlers (`ImportItemQueue`, `RunProductPriceProcessor`) use October-3 `fire($obJob, $arData)` API + manual `$obJob->delete()`. Phase 3 introduces the Laravel 12 `ShouldQueue` pattern to this codebase. | Follow Laravel 12 idiom directly per RESEARCH.md (`implements ShouldQueue`, `use Queueable, InteractsWithQueue, SerializesModels, Dispatchable`, `public int $tries = 3`, `public array $backoff = [1, 4, 16]`, `handle(MetaClient $obClient): void`). |
| `classes/meta/MetaClient.php` (Guzzle injection part) | service (HTTP) | request-response | NO production code in the project imports `GuzzleHttp\Client` or `GuzzleHttp\ClientInterface`. PostNord uses `Http::` facade; Vipps uses raw cURL. Phase 1 added `guzzlehttp/guzzle ^7.8` to composer.json explicitly for this file. | Follow Guzzle 7 idiom per RESEARCH.md (`new Client(['base_uri' => ..., 'timeout' => 5])`, constructor-injectable `?ClientInterface $obClient = null`, switch-on-exception-code transient classification). PostNord serves as the structural analog (class layout, error-log shape); the Guzzle wiring itself is new. |

---

## Metadata

**Analog search scope:**
- `/home/forge/nailscosmetics.lv/plugins/logingrupa/` (all sibling Logingrupa plugins).
- `/home/forge/nailscosmetics.lv/plugins/lovata/` (ecosystem precedents — Shopaholic, OrdersShopaholic, Toolbox, Buddies, DiscountsShopaholic, FilterShopaholic).
- `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixelshopaholic/` (existing Phase 1 + Phase 2 files — primary in-plugin precedents).

**Files scanned (Read):** 18 production / test files.
**Files searched (Grep / Glob / find):** ~40 across the two plugin namespaces.
**Pattern extraction date:** 2026-05-12.

**Precedent-strength ranking (for planner):**

1. **EXACT match** (12 files) — exception hierarchy (8) + 2 migrations + FailedEvent model + Plugin.php boot append.
2. **Role-match** (7 files) — PayloadBuilder (cart-shaped payload precedent), UserDataHasher (CCache primitive), OrderStatusWatcher (Lovata subscribe + saveQuietly), OrderFixtures + 3 unit/feature tests.
3. **Partial / no analog** (4 files) — MetaClient (no Guzzle precedent), SendCapiEvent (no `ShouldQueue` precedent), SendCapiEventTest + MetaClientTest (no Guzzle MockHandler precedent).

**Cross-plugin consistency check:** All extracted patterns are PHP 8.4-compatible, phpstan-level-10-safe (the `declare(strict_types=1)` + typed-property pattern from GoodsReceivedShopaholic exception base is the gold standard), PHPMD-compatible (Hungarian notation passes `ShortVariable min=4`), and Tiger-Style-aligned (every `catch` example either rethrows or documents its silence).
