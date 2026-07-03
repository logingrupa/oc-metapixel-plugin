# Phase 6: ViewContent funnel — Shopaholic PDP + offer-switch — Pattern Map

**Mapped:** 2026-05-28
**Files analyzed:** 13 (4 Modify + 9 Add)
**Analogs found:** 13 / 13 (100% — all in-plugin)

> Read this in 30 seconds. Planner: assigns analog excerpts to plan task action sections. Executor: each task includes the relevant excerpt as `<read_first>` anchor.

## File Classification

### Modify

| File | Role | Data flow | Closest analog | Match |
|------|------|-----------|----------------|-------|
| `components/PixelHead.php` | OctoberCMS component | event-driven (Twig vars + collector flush) | self (refactor) — collector pattern at L185-216 | exact |
| `classes/adapter/AdapterRegistry.php` | service-container registry | static lookup | self (extend with alias index) | exact |
| `classes/adapter/theme/ThemeAjaxHandler.php` | event-watcher / AJAX router | request-response (Larajax → JsonResponse) | self (extend `onBeforeRun` L63-117) | exact |
| `classes/adapter/EventSubjectAdapter.php` | interface contract | n/a — type contract | self (add subinterface `SupportsHybridAjax`) | exact |

### Add

| File | Role | Data flow | Closest analog | Match |
|------|------|-----------|----------------|-------|
| `classes/adapter/shopaholic/ShopaholicProductAdapter.php` | EventSubjectAdapter | event-driven | `ShopaholicCartPositionAdapter.php` | exact (D-15 site fallback shared) |
| `classes/adapter/shopaholic/ShopaholicProductValueResolver.php` | ValueResolver | transform (subject → SKU/value/currency) | `ShopaholicCartPositionValueResolver.php` | exact |
| `classes/event/adapter/shopaholic/ProductPageWatcher.php` | event-watcher (subscriber) | event-driven (Event::fire → CAPI dispatch) | `CartPositionWatcher.php` + `OrderStatusWatcher.php` | exact (event-listen shape) |
| `components/ProductPixel.php` | OctoberCMS component | request-response (Twig var injection) | `EventPixel.php` (sibling) + `PixelHead.php` (collector consumer) | exact (vendor-neutral name pattern) |
| `components/productpixel/default.htm` | Twig partial | render-only | `components/pixelhead/default.htm` | exact |
| `classes/exception/UnknownSubjectTypeException.php` | exception | n/a | `MissingCapiTokenException.php` / `OrderHasNoCurrencyException.php` | exact |
| `tests/Feature/Adapter/Shopaholic/ProductPageWatcherTest.php` | feature test | event-driven | `PixelHeadBasePixelTest.php` (Bus::fake) + `CartPositionWatcher` watcher tests pattern | exact |
| `tests/Feature/Adapter/Shopaholic/ShopaholicProductValueResolverTest.php` | unit test | transform | (no in-repo resolver test — use `MetapixelTestCase` + `#[Group('adapter')]`) | role-match |
| `tests/Feature/Components/PixelHeadDeferredFlushTest.php` | feature test | event-driven (lifecycle) | `PixelHeadBasePixelTest.php` | exact |
| `tests/Feature/Components/ProductPixelTest.php` | feature test | request-response (component reflection) | `PixelHeadBasePixelTest.php::runComponent` reflection helper | exact |
| `tests/Feature/Adapter/Theme/ThemeAjaxHandlerSubjectTypeTest.php` | feature test | request-response | `ThemeAjaxHandlerAllowlistTest.php` | exact |

---

## Pattern Assignments — Modify

### `components/PixelHead.php` (component, event-driven)

**Analog:** self — current shape at `components/PixelHead.php:57-61` + `:185-216`.

**BEFORE — `onRun()` flushes immediately (L57-61):**
```php
public function onRun(): void
{
    $this->emitBasePixel();
    $this->emitCollectedEvents();   // ← move out
}
```

**WHAT CHANGES:**
- `onRun()` keeps `emitBasePixel()` (base PageView per-pageload, deterministic).
- `emitCollectedEvents()` invocation MOVES to a NEW `cms.page.beforeRenderPage` listener registered in `Plugin::boot()` (Section 2 option b).
- `emitCollectedEvents()` extended to emit `{eventID: <event_id>}` (the 4th fbq arg) when pushed event carries `event_id` key. Current code at L200-202 does NOT include `eventID` — Phase 6 MUST extend.
- PHPDoc gains class-level lifecycle-contract docblock (RESEARCH Section 12 sketch).

**Collector-consumption pattern to preserve (L185-216) — emit shape:**
```php
protected function emitCollectedEvents(): void
{
    /** @var ThemeEventCollector $obCollector */
    $obCollector = App::make(ThemeEventCollector::class);
    $arEvents = $obCollector->flush();
    $arScriptBlocks = [];
    foreach ($arEvents as $arEvent) {
        $mNameRaw = $arEvent['name'] ?? null;
        if (! is_string($mNameRaw) || $mNameRaw === '') {
            continue;
        }
        $sName = $mNameRaw;
        $arCustomData = isset($arEvent['custom_data']) && is_array($arEvent['custom_data'])
            ? $arEvent['custom_data']
            : array_diff_key($arEvent, ['name' => true, 'action_key' => true, 'also_dispatch_capi' => true, 'site_id' => true, 'event_id' => true, 'product_id' => true]);
        $sNameJson = (string) json_encode($sName, self::JS);
        $sDataJson = (string) json_encode($arCustomData, self::JS);
        // PHASE 6 CHANGE: include {eventID: ...} (4th fbq arg) when event_id present
        $arScriptBlocks[] = sprintf('<script>fbq("track", %s, %s);</script>', $sNameJson, $sDataJson);
        // ... CAPI mirror branch unchanged
    }
    $this->page['pixelHeadBlocks'] = $arScriptBlocks;
}
```

**Pitfall to mitigate (RESEARCH Pitfall 1):** `$this->page['pixelHeadBlocks']` mutation in the deferred listener MUST land in the Twig render context. Two viable shapes:
- (a) Move flush logic into a static `PixelHead::flushDeferredFromController(CmsController $obController)` that writes into `$obController->vars['pixelHeadBlocks']` directly. Bypasses component-instance scoping.
- (b) Singleton buffer `PixelHeadDeferredFlushBuffer`; `default.htm` reads via a markup helper. RESEARCH recommendation = (a) when the listener has the `CmsController` argument.

---

### `classes/adapter/AdapterRegistry.php` (registry, static lookup)

**Analog:** self — current `register()` at L41-49.

**BEFORE — `register()` only fills `$arAdapterMap`:**
```php
public function register(string $sSubjectClass, string $sAdapterClass): void
{
    if (! is_subclass_of($sAdapterClass, EventSubjectAdapter::class)) {
        throw new InvalidArgumentException(
            "Adapter {$sAdapterClass} must implement ".EventSubjectAdapter::class,
        );
    }
    $this->arAdapterMap[$sSubjectClass] = $sAdapterClass;
}
```

**WHAT CHANGES (RESEARCH Section 5):**
- Add `private array $arAliasMap = []` field.
- Extend `register()` to instantiate the adapter once via `App::make($sAdapterClass)`, call `getSubjectType(new \stdClass)` (verified safe — all 3 shipping adapters return constant string and ignore argument; A3 assumption), insert into `$arAliasMap[$sAlias] = $sAdapterClass`.
- Add `resolveByAlias(string $sAlias): string` returning class FQN or throwing `UnknownSubjectTypeException`.

**New method sketch (RESEARCH L347-389):**
```php
/**
 * Resolve adapter class FQN by opaque subject_type alias.
 *
 * @throws UnknownSubjectTypeException
 * @return class-string<EventSubjectAdapter>
 */
public function resolveByAlias(string $sAlias): string
{
    if (! isset($this->arAliasMap[$sAlias])) {
        throw new UnknownSubjectTypeException(
            "No adapter registered for subject_type alias '{$sAlias}'",
        );
    }
    return $this->arAliasMap[$sAlias];
}
```

---

### `classes/adapter/theme/ThemeAjaxHandler.php` (event-watcher / AJAX router)

**Analog:** self — current `onBeforeRun()` at L63-117.

**BEFORE — `onBeforeRun` dispatches via ThemeActionAdapter only (L80-99):**
```php
try {
    $obEvent = ThemeActionEvent::fromArray($arData);
} catch (InvalidArgumentException $obException) {
    return new JsonResponse(['error' => 'invalid event payload: '.$obException->getMessage()], 422);
}

$sEventId = Uuid::uuid4()->toString();
$arPayload = (new PayloadBuilder(new UserDataHasher))->buildEventPayload(
    $obEvent->sEventName,
    App::make(ThemeActionAdapter::class),
    $obEvent,
    new ThemeActionValueResolver,
    $sEventId,
    time(),
    [],
);
SendCapiEvent::dispatch($obEvent->sEventName, $arPayload, $obEvent, ThemeActionAdapter::class);
```

**WHAT CHANGES (RESEARCH Section 9):**
- Detect optional `subject_type` field BEFORE existing ThemeActionEvent path.
- When present: extract → `AdapterRegistry::resolveByAlias($sAlias)` (catches `UnknownSubjectTypeException` → 422) → `$obAdapter instanceof SupportsHybridAjax` check (else 422) → `$obAdapter->loadSubject($iSubjectId, $arContext)` → null returns 404 → build payload via adapter + its ValueResolver → dispatch.
- When absent: existing ThemeActionEvent path unchanged.

**Allowlist + rate-limit + outer try/catch wrapper at L63-117 remains as boundary catch.** New `dispatchViaAdapter()` private method receives the already-validated data and returns a `JsonResponse`.

**Status code matrix (new):**
| Condition | Code |
|---|---|
| `subject_type` not in alias index | 422 `unknown subject_type` |
| Adapter not `SupportsHybridAjax` | 422 `subject_type does not support hybrid AJAX` |
| `subject_id` ≤ 0 or non-numeric | 422 `invalid subject_id` |
| `loadSubject()` returns null | 404 `subject not found` |
| Success | 200 `{event_id, script}` (existing shape) |

---

### `classes/adapter/EventSubjectAdapter.php` (interface contract)

**Analog:** self — current full interface (8 methods).

**WHAT CHANGES:**
- Do NOT add a method to `EventSubjectAdapter` (BC break, voids Phase 2 contract test 10 invariants — RESEARCH Section 9 + Pitfall 9).
- Instead, ADD a sibling sub-interface:

```php
namespace Logingrupa\Metapixel\Classes\Adapter;

/**
 * Marker subinterface for adapters that support the hybrid AJAX path
 * (ThemeAjaxHandler::onBeforeRun routing subject_type → adapter). Adapters
 * declaring this contract MUST re-enforce subject's domain guards
 * (active(), site-relation match) inside loadSubject — otherwise an attacker
 * MITMing the AJAX POST could fire CAPI for a cross-site or inactive subject.
 */
interface SupportsHybridAjax extends EventSubjectAdapter
{
    /**
     * Hydrate the subject from PK + arbitrary context (e.g. offer_id).
     * Return null when subject is missing, inactive, or fails site-match.
     *
     * @param  array<string, mixed>  $arContext
     */
    public function loadSubject(int $iSubjectId, array $arContext): ?object;
}
```

`ShopaholicProductAdapter` implements `SupportsHybridAjax` (not the base interface alone). `ShopaholicOrderAdapter` / `ShopaholicCartPositionAdapter` / `ThemeActionAdapter` are unchanged (no `loadSubject` needed yet).

---

## Pattern Assignments — Add

### `classes/adapter/shopaholic/ShopaholicProductAdapter.php` (EventSubjectAdapter, event-driven)

**Analog:** `classes/adapter/shopaholic/ShopaholicCartPositionAdapter.php`

**Class-level docblock + constants pattern (L9-30):**
```php
/**
 * EventSubjectAdapter for Lovata\OrdersShopaholic\Models\CartPosition. Alias
 * 'shopaholic.cart_position'. Supports AddToCart on capi+pixel channels.
 *
 * site_id source: prefers $obPosition->cart->site_id (1-hop relation) when
 * non-null; falls back to October's Site::getSiteIdFromContext() as the SECOND
 * documented P-01 exception (alongside ThemeActionAdapter; CONTEXT.md D-15).
 * ...
 */
final class ShopaholicCartPositionAdapter implements EventSubjectAdapter
{
    private const SUBJECT_TYPE = 'shopaholic.cart_position';
    /** @var array<string, list<string>> */
    private const SUPPORTED_EVENTS = ['AddToCart' => ['capi', 'pixel']];
```

**`getSiteId` D-15 fallback pattern (L44-55):**
```php
public function getSiteId(object $obSubject): ?int
{
    $mCart = $this->positionOf($obSubject)?->getRelationValue('cart');
    $mSiteId = is_object($mCart) ? ($mCart->site_id ?? null) : null;
    if (is_numeric($mSiteId)) {
        return (int) $mSiteId;
    }

    $mContextSiteId = Site::getSiteIdFromContext();

    return is_int($mContextSiteId) && $mContextSiteId > 0 ? $mContextSiteId : null;
}
```

**DELTA for ProductAdapter (RESEARCH Section 10 — critical finding):**
- `SUBJECT_TYPE = 'shopaholic.product'`
- `SUPPORTED_EVENTS = ['ViewContent' => ['capi', 'pixel']]`
- `getSiteId` reads `$obProduct->site_list` (verified via `MultisiteHelperTrait`) — single-element list returns it; multi-site falls back to `Site::getSiteIdFromContext()`. Product has NO `site_id` column.
- `getSubjectId` reads `$obProduct->id` via `getAttribute('id')`.
- `getSecretKey` returns null (no per-product secret).
- `getUserData` returns all-null array (matches CartPositionAdapter L75-83 — anonymous subject, theme cookies populate via cookie-middleware path).
- `getValueResolver` returns `new ShopaholicProductValueResolver`.
- `implements SupportsHybridAjax` (NOT bare `EventSubjectAdapter`) and adds `loadSubject(int, array): ?object` method per RESEARCH Section 9 Pitfall 3:
  ```php
  public function loadSubject(int $iSubjectId, array $arContext): ?object
  {
      // Re-enforce guards equivalent to ProductPage::getElementObject
      $obProduct = Product::active()->find($iSubjectId);
      if ($obProduct === null) { return null; }
      // hasRelationWithSite-equivalent: site_list must include current site context
      $arSiteList = $obProduct->site_list ?? [];
      $iCurrentSite = Site::getSiteIdFromContext();
      if (is_int($iCurrentSite) && is_array($arSiteList) && ! in_array($iCurrentSite, $arSiteList, true)) {
          return null;
      }
      return $obProduct;
  }
  ```

**PHPStan deny-list extension (RESEARCH Section 10 + Pitfall 5):** add `classes/adapter/shopaholic/ShopaholicProductAdapter.php` to `allowIn` for `Site::*`, `SiteManager::*`, `Request::*`, `request()` rules in `phpstan.neon`. Pattern: copy-paste from existing `ShopaholicCartPositionAdapter.php` allowlist line.

**Dependency boundary:** THIS file is the ONLY new file allowed to import `Lovata\Shopaholic\Models\Product`. Enforced by `composer-dependency-analyser.php`.

---

### `classes/adapter/shopaholic/ShopaholicProductValueResolver.php` (ValueResolver, transform)

**Analog:** `classes/adapter/shopaholic/ShopaholicCartPositionValueResolver.php`

**Currency fallback pattern (L41-50) — REUSE VERBATIM, swap exception type:**
```php
public function resolveCurrency(object $obSubject): string
{
    $mDefault = Settings::get('default_currency_code', '');
    if (is_string($mDefault) && $mDefault !== '') {
        return $mDefault;
    }
    throw new OrderHasNoCurrencyException(
        'CartPosition has no currency context; configure Settings.default_currency_code',
    );
}
```

**DELTA for ProductValueResolver (RESEARCH Section 4 + Example 1):**
- `resolveCurrency` first tries `CurrencyHelper::instance()->getActiveCurrencyCode()` (RESEARCH L296-336), THEN falls back to `Settings::get('default_currency_code', '')`, THEN throws (reuse `OrderHasNoCurrencyException` OR file a new `ProductHasNoCurrencyException` — planner picks).
- `resolveContentIds` uses default-offer logic (D-10): `$obProduct->offer->where('active', true)->sortBy('sort_order')->first()`. Empty collection → `['SKU-{pid}']`. Multi-offer → `['SKU-{pid}-{oid}']`. Single-offer → `['SKU-{pid}']`.
- `resolveValue` reads raw `Offer::price_value` (RESEARCH Section 4 planner decision — no PriceTypeHelper for v2.0, matches CartPosition resolver).
- `resolveContents` returns one item with `{id, quantity:1, item_price}`.
- `resolveNumItems` returns `1` (constant — single-product view).

**SKU helper analog (CartPosition L97-108):**
```php
private function buildContentId(Offer $obOffer): string
{
    $obProduct = $this->productOf($obOffer);
    if ($obProduct === null) {
        return sprintf('SKU-%d', 0);
    }
    $iProductId = $this->intAttr($obProduct, 'id');

    return $obProduct->offer->count() > 1
        ? sprintf('SKU-%d-%d', $iProductId, $this->intAttr($obOffer, 'id'))
        : sprintf('SKU-%d', $iProductId);
}
```

**DELTA:** ProductValueResolver receives `Product` (not `CartPosition`), so flow is inverted — start with `$obProduct`, drill into default offer. Use `RESEARCH Example 1` (L863-942) as drop-in template.

**Private helper attrs (L122-134 — REUSE VERBATIM):**
```php
private function intAttr(Model $obModel, string $sAttr): int
{
    $mValue = $obModel->getAttribute($sAttr);
    return is_numeric($mValue) ? (int) $mValue : 0;
}

private function floatAttr(Model $obModel, string $sAttr): float
{
    $mValue = $obModel->getAttribute($sAttr);
    return is_numeric($mValue) ? (float) $mValue : 0.0;
}
```

---

### `classes/event/adapter/shopaholic/ProductPageWatcher.php` (event-watcher, event-driven)

**Analog:** `classes/event/adapter/shopaholic/CartPositionWatcher.php` (subscribe shape + dispatch loop) + `OrderStatusWatcher.php` (Tiger-Style log+return boundary).

**Subscribe pattern (CartPositionWatcher L28-32):**
```php
public function subscribe(Dispatcher $obDispatcher): void
{
    $obDispatcher->listen('eloquent.created: '.CartPosition::class, [$this, 'handleCreated']);
    $obDispatcher->listen('eloquent.updated: '.CartPosition::class, [$this, 'handleUpdated']);
}
```

**DELTA for ProductPageWatcher:** subscribe to a SINGLE event with a SINGLE handler:
```php
public function subscribe(Dispatcher $obDispatcher): void
{
    $obDispatcher->listen('shopaholic.product.open', [$this, 'handle']);
}
```

**Dispatch pattern (CartPositionWatcher L62-88) — payload build + user_data inject + queue dispatch:**
```php
private function dispatchAddToCart(CartPosition $obCartPosition): void
{
    // ... guard ...
    $obAdapter = new ShopaholicCartPositionAdapter;
    $obResolver = new ShopaholicCartPositionValueResolver;
    $obBuilder = new PayloadBuilder(new UserDataHasher);

    $arPayload = $obBuilder->buildEventPayload(
        'AddToCart',
        $obAdapter,
        $obCartPosition,
        $obResolver,
        Uuid::uuid4()->toString(),
        time(),
        [],
    );
    $arPayload = $this->injectRequestUserData($arPayload);

    SendCapiEvent::dispatch('AddToCart', $arPayload, $obCartPosition, ShopaholicCartPositionAdapter::class);
}
```

**Tiger-Style log + return boundary (OrderStatusWatcher L63-72):**
```php
} catch (Throwable $obException) {
    // Tiger-Style: log + return. Do NOT rethrow — would cascade-break
    // Order::save() through Lovata OrderProcessor / Campaign / PromoMechanism.
    Log::warning('metapixel: OrderStatusWatcher payload-build failed', [
        'meta_pixel.order_id' => $obOrder->id,
        'meta_pixel.exception' => get_class($obException),
        'meta_pixel.message' => $obException->getMessage(),
    ]);
}
```

**DELTA for ProductPageWatcher (RESEARCH Section 6 + Example 3 — full implementation at L979-1045):**
- Uses `CapturesRequestUserData` trait (same as both analogs).
- After building payload + injecting user_data, ALSO pushes to `ThemeEventCollector` BEFORE dispatching CAPI:
  ```php
  App::make(ThemeEventCollector::class)->push([
      'name' => 'ViewContent',
      'action_key' => 'viewcontent:'.$obProduct->id.':'.$sEventId,
      'event_id' => $sEventId,
      'content_ids' => $obResolver->resolveContentIds($obProduct),
      'content_name' => is_string($obProduct->name) ? $obProduct->name : '',
      'content_type' => 'product',
      'value' => $obResolver->resolveValue($obProduct),
      'currency' => $obResolver->resolveCurrency($obProduct),
      'product_id' => $obProduct->id,
  ]);
  ```
- Action key = `viewcontent:{product_id}:{event_id}` (CONTEXT discretion shape — per-request unique via UUIDv4 suffix).
- Guard: `if (PluginGuard::isDisabled()) return;` at top.
- No `wasChanged` / `exists` guard (vs. OrderStatusWatcher) — `shopaholic.product.open` is fired with valid `$obElement` only per RESEARCH Section 3 invariants 1-5.

---

### `components/ProductPixel.php` (component, request-response)

**Analog:** `components/EventPixel.php` (vendor-neutral naming + `componentDetails` shape) + `components/PixelHead.php` (PluginGuard gate + Twig-var page injection).

**Component header pattern (EventPixel L20-42):**
```php
final class EventPixel extends ComponentBase
{
    private const TABLE = 'logingrupa_metapixel_event_log';
    private const JS = JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS;

    /** @return array{name: string, description: string} */
    public function componentDetails(): array
    {
        return ['name' => 'EventPixel', 'description' => 'Emits server-confirmed fbq() pixel for a subject (Order, theme action, custom adapter).'];
    }

    /** @return array<string, array<string, mixed>> */
    public function defineProperties(): array
    {
        return [
            'subject_class' => ['title' => 'Subject FQN', 'type' => 'string', 'required' => true],
            // ...
        ];
    }
```

**PluginGuard gate pattern (PixelHead L74-76):**
```php
if (PluginGuard::isDisabled()) {
    return;
}
```

**DELTA for ProductPixel (RESEARCH Section 7 + Section 8):**
- `componentDetails`: name `'ProductPixel'`, description `'PDP-level Meta Pixel ViewContent + offer-switch trigger. Place inside the layout/page hosting Shopaholic [ProductPage].'`.
- `defineProperties`: returns `[]` (zero properties — operator-zero-config).
- `onRun`: writes `$this->page['productPixelOfferSwitchJs']` with the inline JS string (when not disabled).
- `onRun`: writes `$this->page['productPixelProductGlobalJs']` with `<script>window.__metapixelProduct={id:{$iProductId}};</script>` when ThemeEventCollector contains a `product_id` key (RESEARCH Section 7 option c + Pitfall 8 soft-gate).
- The initial `fbq('track','ViewContent',...,{eventID})` browser block is rendered by PixelHead's deferred flush from the collector push the watcher made — NOT by this component (RESEARCH Section 7 comment block at L488-491).
- Inline JS body = RESEARCH Section 7 (L501-528) or Example 2 (L946-977). Use Example 2 (idempotency-guarded + Pitfall 8 soft-gate + `window.__metapixelProduct.id` source).

**Composer dependency boundary:** ProductPixel MUST NOT import `Lovata\Shopaholic\*`. Reads `product_id` from collector (already pushed by adapter-side watcher), NOT from the model directly.

---

### `components/productpixel/default.htm` (Twig partial, render-only)

**Analog:** `components/pixelhead/default.htm` (full file, 14 lines).

**Conditional render pattern (pixelhead/default.htm L1-13):**
```twig
{% if pixelHeadBase %}
<!-- Metapixel base pixel — server-injected eventID for CAPI dedup -->
<script>
!function(f,b,e,v,n,t,s){...}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
fbq('init', {{ pixelHeadBase.pixel_id_js|raw }});
fbq('track', {{ pixelHeadBase.event_name_js|raw }}, {event_time: {{ pixelHeadBase.event_time_js|raw }}}, {eventID: {{ pixelHeadBase.event_id_js|raw }}{% if pixelHeadBase.test_event_code_js %}, test_event_code: {{ pixelHeadBase.test_event_code_js|raw }}{% endif %}});
</script>
<noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id={{ pixelHeadBase.noscript_pixel_id }}&ev={{ pixelHeadBase.noscript_event_name }}&noscript=1"/></noscript>
{% endif %}
{% if pixelHeadBlocks %}
{% for block in pixelHeadBlocks %}{{ block|raw }}
{% endfor %}
{% endif %}
```

**DELTA for productpixel/default.htm:**
```twig
{% if productPixelProductGlobalJs %}
{{ productPixelProductGlobalJs|raw }}
{% endif %}
{% if productPixelOfferSwitchJs %}
{{ productPixelOfferSwitchJs|raw }}
{% endif %}
```

Two guard blocks (one per Twig page var). Both render `|raw` because PHP already JSON-escaped via `JSON_HEX_*` mask.

---

### `classes/exception/UnknownSubjectTypeException.php` (exception)

**Analog:** `classes/exception/MissingCapiTokenException.php` (3 lines + docblock) and `OrderHasNoCurrencyException.php` (same shape).

**Pattern (full file — copy verbatim):**
```php
<?php

namespace Logingrupa\Metapixel\Classes\Exception;

/**
 * Thrown at event-fire time when Settings::lookupForSite returns an empty
 * capi_access_token. The CAPI dispatch step cannot proceed without it; pixel
 * dispatch (browser channel) is unaffected.
 */
final class MissingCapiTokenException extends MetaPixelException {}
```

**DELTA for UnknownSubjectTypeException:**
```php
<?php

namespace Logingrupa\Metapixel\Classes\Exception;

/**
 * Thrown by AdapterRegistry::resolveByAlias when the supplied subject_type
 * string does not match any registered adapter's alias. Caught at
 * ThemeAjaxHandler::onBeforeRun → returns JsonResponse 422.
 */
final class UnknownSubjectTypeException extends MetaPixelException {}
```

Inherits constructor `(string $sMessage, int $iCode, ?Throwable $obPrevious, array $arContext)` from `MetaPixelException` (`classes/exception/MetaPixelException.php:20`).

---

### `tests/Feature/Components/PixelHeadDeferredFlushTest.php` (feature test, lifecycle)

**Analog:** `tests/Feature/Components/PixelHeadBasePixelTest.php` (full file — 207 lines).

**Class-level group attribute + setUp + tearDown (L28-45):**
```php
#[Group('adapter')]
final class PixelHeadBasePixelTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new CreateMetapixelEventLogTable)->up();
        App::singleton(ThemeEventCollector::class);
        PluginGuard::reset();
    }

    protected function tearDown(): void
    {
        App::forgetInstance(ThemeEventCollector::class);
        PluginGuard::reset();
        (new CreateMetapixelEventLogTable)->down();
        parent::tearDown();
    }
```

**`runComponent` reflection helper (L163-206) — REUSE VERBATIM (copy into new test, or extract to a shared trait):**
```php
private function runComponent(PixelHead $obComponent): array
{
    $obFakePage = new class implements ArrayAccess {
        public array $vars = [];
        public function offsetExists($offset): bool { return isset($this->vars[$offset]); }
        public function offsetGet($offset): mixed { return $this->vars[$offset] ?? null; }
        public function offsetSet($offset, $value): void { /* ... */ }
        public function offsetUnset($offset): void { unset($this->vars[$offset]); }
    };
    $obReflection = new ReflectionProperty(PixelHead::class, 'page');
    $obReflection->setAccessible(true);
    $obReflection->setValue($obComponent, $obFakePage);
    $obComponent->onRun();
    // ...
}
```

**Bus::fake assertion pattern (L75-98):**
```php
public function test_dispatches_capi_pageview_with_same_event_id_as_browser(): void
{
    Bus::fake();
    Settings::clearInternalCache();
    Settings::set(['pixel_id' => '1234567890', 'capi_access_token' => 'TOKEN-X']);
    // ...
    $arPage = $this->runComponent(new PixelHead);
    $sBrowserEventId = trim($arPage['pixelHeadBase']['event_id_js'], '"');

    Bus::assertDispatched(SendCapiEvent::class, static function (SendCapiEvent $obJob) use ($sBrowserEventId): bool {
        // ...
    });
}
```

**DELTA for PixelHeadDeferredFlushTest (brief matrix items 1-4):**
1. Test: base PageView emits via `onRun()` AND the deferred listener fires `emitCollectedEvents()` separately. Assert order — fire `cms.page.beforeRenderPage` AFTER component `onRun`, push to collector BETWEEN them, assert pushed event appears in `$arPage['pixelHeadBlocks']`.
2. Test: collector push between `onRun` and `beforeRenderPage` is flushed (RESEARCH Pitfall 1 invariant).
3. Test: action_key shape unchanged (`base:pageview:{site_id}:{event_id}` regex).
4. Test: `test_event_code` still flows to fbq script when Settings carries it.

Use `Event::fake([])` then `Event::dispatch('cms.page.beforeRenderPage', [$obController])` to simulate the lifecycle anchor in the test. Or call the static `PixelHead::flushDeferredFromController($obController)` directly.

---

### `tests/Feature/Adapter/Shopaholic/ProductPageWatcherTest.php` (feature test, event-driven)

**Analog:** `PixelHeadBasePixelTest.php` (Bus::fake + setUp/tearDown + Group attribute) + CartPositionWatcher dispatch test pattern.

**Test scaffold copy-pattern:**
```php
#[Group('adapter')]
final class ProductPageWatcherTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new CreateMetapixelEventLogTable)->up();
        App::singleton(ThemeEventCollector::class);
        PluginGuard::reset();
    }

    protected function tearDown(): void
    {
        App::forgetInstance(ThemeEventCollector::class);
        PluginGuard::reset();
        (new CreateMetapixelEventLogTable)->down();
        parent::tearDown();
    }
```

**DELTA — 11-row brief matrix:**
1. `Event::dispatch('shopaholic.product.open', [$obProduct])` → Bus::assertDispatched SendCapiEvent for ViewContent + ThemeEventCollector::count() == 1.
2. PluginGuard disabled → Bus::assertNotDispatched + collector::count() == 0.
3. Plugin.php boot sanity: extend `PluginSanityTest` (existing — RESEARCH Table) to assert ProductPageWatcher subscribed when `Lovata.Shopaholic` exists.
4. Zero-offer product → SKU = `['SKU-{pid}']`, no throw.
5. Multi-offer product → SKU = `['SKU-{pid}-{oid}']` (first-active-by-sort_order).
6. Single-offer product → SKU = `['SKU-{pid}']`.
7. CAPI payload event_id matches collector-pushed event_id.
8. user_data populated from `$_SERVER['HTTP_USER_AGENT']`, `$_SERVER['REMOTE_ADDR']`, `$_COOKIE['_fbp']`, `$_COOKIE['_fbc']` (use `$_SERVER[...] = 'X'` direct assignment in test — RESEARCH `CapturesRequestUserData` reads superglobals directly).
9. `test_event_code` settings → CAPI payload top-level + browser fbq both include it (cross-reference `PixelHeadBasePixelTest` for browser-side assertion).
10. EventLog UNIQUE race-fence does NOT block per-pageload duplicates (action_key per-request unique via event_id suffix).
11. (covered by ThemeAjaxHandlerSubjectTypeTest — offer-switch AJAX integration).

---

### `tests/Feature/Adapter/Shopaholic/ShopaholicProductValueResolverTest.php` (unit test, transform)

**Analog:** no in-repo resolver test exists yet — use `MetapixelTestCase` base + `#[Group('adapter')]` + `#[DataProvider(...)]` pattern proven in `MetaClientTest` (Phase 2 lock — RESEARCH L747).

**Pattern skeleton:**
```php
#[Group('adapter')]
final class ShopaholicProductValueResolverTest extends MetapixelTestCase
{
    #[DataProvider('provideOfferShapes')]
    public function test_resolveContentIds_matches_sku_format(array $arOfferSetup, array $arExpectedIds): void
    {
        $obProduct = $this->makeProductWithOffers($arOfferSetup);
        $obResolver = new ShopaholicProductValueResolver;

        $this->assertSame($arExpectedIds, $obResolver->resolveContentIds($obProduct));
    }

    public static function provideOfferShapes(): array
    {
        return [
            'zero offers' => [[], ['SKU-{pid}']],
            'single offer' => [[['active' => true, 'sort_order' => 0]], ['SKU-{pid}']],
            'multi-offer first-active' => [[
                ['active' => true, 'sort_order' => 0, 'id' => 100],
                ['active' => true, 'sort_order' => 1, 'id' => 101],
            ], ['SKU-{pid}-100']],
            // ...
        ];
    }
}
```

**DELTA:** test `resolveValue` (raw `price_value` reads), `resolveCurrency` (CurrencyHelper → Settings fallback → throw chain), `resolveContents` shape, `resolveNumItems` constant 1.

---

### `tests/Feature/Components/ProductPixelTest.php` (feature test, request-response)

**Analog:** `PixelHeadBasePixelTest.php` (full file — `runComponent` reflection + PluginGuard reset + Settings setup).

**DELTA:**
- Test: `$this->page['productPixelOfferSwitchJs']` populated (substring assertions: `'window.__metapixelProductPixelInit'`, `'jax.ajax(\'Metapixel::onFireEvent\''`, `'subject_type: \'shopaholic.product\''`, `'document.addEventListener(\'change\''`).
- Test: PluginGuard disabled → `productPixelOfferSwitchJs` is null/empty.
- Test: `productPixelProductGlobalJs` populated when ThemeEventCollector has a `product_id` push (or via test-injection path — planner picks).
- Test: `productPixelProductGlobalJs` is null when collector empty.

No browser E2E — substring assertions on the rendered JS string suffice (RESEARCH Section 11).

---

### `tests/Feature/Adapter/Theme/ThemeAjaxHandlerSubjectTypeTest.php` (feature test, request-response)

**Analog:** `tests/Feature/Adapter/Theme/ThemeAjaxHandlerAllowlistTest.php` (full file — 131 lines).

**setUp pattern (L29-46):**
```php
protected function setUp(): void
{
    parent::setUp();
    $this->app->singleton(AdapterRegistry::class);
    App::make(AdapterRegistry::class)->register(
        ThemeActionEvent::class,
        ThemeActionAdapter::class,
    );
    Settings::clearInternalCache();
    Settings::set([
        'pixel_id' => 'PIXEL-1',
        'capi_access_token' => 'TOKEN-1',
    ]);
    $this->app->forgetInstance(RateLimiter::class);
    Session::shouldReceive('getId')->andReturn('test-session-allowlist');
    Request::shouldReceive('ip')->andReturn('127.0.0.1');
}
```

**Mockery + Request::shouldReceive pattern (L65-80):**
```php
public function test_returns_422_when_event_name_not_in_allowlist(): void
{
    Request::shouldReceive('input')->with('data', [])->andReturn([
        'name' => 'NotAnEvent',
        'action_key' => 'whatever',
    ]);

    $obController = Mockery::mock(Controller::class);
    $obHandler = new ThemeAjaxHandler;
    $mResponse = $obHandler->onBeforeRun($obController, 'Metapixel::onFireEvent');

    $this->assertInstanceOf(JsonResponse::class, $mResponse);
    $this->assertSame(422, $mResponse->getStatusCode());
    $arData = json_decode((string) $mResponse->getContent(), true);
    $this->assertSame(['error' => 'event_name not allowed'], $arData);
}
```

**DELTA — 5 test cases (RESEARCH Section 11):**
1. Unknown alias `subject_type:'mall.product'` (not registered) → 422 `{error: 'unknown subject_type'}`.
2. Valid alias `subject_type:'shopaholic.product'` + valid `subject_id` → dispatches `SendCapiEvent` with `ShopaholicProductAdapter::class`.
3. Adapter does not implement `SupportsHybridAjax` → 422 `{error: 'subject_type does not support hybrid AJAX'}` (register a stub adapter that only implements base interface).
4. `subject_id ≤ 0` → 422 `{error: 'invalid subject_id'}`.
5. `loadSubject()` returns null (inactive or cross-site product) → 404 `{error: 'subject not found'}`.

Setup must `AdapterRegistry::register(Product::class, ShopaholicProductAdapter::class)` in addition to the existing ThemeActionAdapter registration.

---

## Shared Patterns (cross-cutting — apply to all relevant new files)

### Hungarian notation + October-property carve-out
**Source:** `plugins/logingrupa/metapixel/CLAUDE.md` § Code style + Model property convention
**Apply to:** every new PHP file.
- Local variables + methods: `$obProduct`, `$arPayload`, `$sEventId`, `$iProductId`, `$bIsActive`, `$fPrice`.
- October model properties stay Laravel-standard (`$table`, `$fillable`, `$jsonable`, `$casts`, `$rules`, `$hasOne/$hasMany/$belongsTo`, etc.) — NOT applicable here (no new models added in Phase 6).

### PluginGuard gate (first line of every emission path)
**Source:** `components/PixelHead.php:74-76`, `components/EventPixel.php` (implicit — guarded by EventLog row existence), watchers (TBD in ProductPageWatcher).
```php
if (PluginGuard::isDisabled()) {
    return;
}
```
**Apply to:** `ProductPageWatcher::handle`, `ProductPixel::onRun`, `dispatchViaAdapter` (planner picks — RESEARCH does not specify whether ThemeAjaxHandler hybrid path needs this; existing path does NOT gate via PluginGuard, only via Settings allowlist + rate-limit).

### CapturesRequestUserData trait (request-context user_data merge)
**Source:** `classes/event/CapturesRequestUserData.php` (full file — 108 lines)
**Apply to:** `ProductPageWatcher` (mix `use CapturesRequestUserData;` in class body, call `$arPayload = $this->injectRequestUserData($arPayload);` after `buildEventPayload`).

### Tiger-Style boundary catch (log + return, never rethrow)
**Source:** `OrderStatusWatcher.php:63-72` + `PixelHead.php:121-129`.
```php
} catch (Throwable $obException) {
    Log::warning('metapixel: <Class> <verb> failed', [
        'meta_pixel.<id_key>' => $obSubject->id ?? null,
        'meta_pixel.exception' => get_class($obException),
        'meta_pixel.message' => $obException->getMessage(),
    ]);
}
```
**Apply to:** `ProductPageWatcher::handle`, `ProductPixel::onRun` (if any throw possible — currently none beyond PluginGuard), `ThemeAjaxHandler::dispatchViaAdapter` outer catch.

### JSON encoding for inline `<script>` (XSS-safe mask)
**Source:** `components/PixelHead.php:41` (`const JS = JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS`) + `ThemeAjaxHandler.php:101`.
```php
private const JS = JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS;
// ...
(string) json_encode($mValue, self::JS)
```
**Apply to:** `ProductPixel.php` (inline JS string contains JSON-encoded values), `ThemeAjaxHandler::dispatchViaAdapter` script-block builder.

### PHPStan disallowed-calls allowlist extension
**Source:** `phpstan.neon` `allowIn` entries on `Site::*`, `SiteManager::*`, `Request::*`, `request()` rules — list contains `classes/adapter/shopaholic/ShopaholicCartPositionAdapter.php`.
**Apply to:** add `classes/adapter/shopaholic/ShopaholicProductAdapter.php` to all 4 rules. Pattern: same line shape as the existing CartPositionAdapter entry (RESEARCH Section 10 + Pitfall 5).

### Test class attributes (PHPUnit 12 native)
**Source:** every existing test file in `tests/Feature/Adapter/*` carries `#[Group('adapter')]` class-level (verified — `PixelHeadBasePixelTest.php:28`, `ThemeAjaxHandlerAllowlistTest.php:26`).
```php
use PHPUnit\Framework\Attributes\Group;

#[Group('adapter')]
final class ProductPageWatcherTest extends MetapixelTestCase
```
**Apply to:** all 5 new test files. Minimal-install CI cell excludes via `pest --exclude-group=adapter`.

### Composer dependency boundary (Lovata import allowlist)
**Source:** `composer-dependency-analyser.php`
**Apply to:** ONLY `ShopaholicProductAdapter.php` may `use Lovata\Shopaholic\Models\Product;` (and transitively `Offer`). All other new files (`ShopaholicProductValueResolver`, `ProductPageWatcher`, `ProductPixel`) may also import Lovata classes per existing allowlist directory rules — `classes/adapter/shopaholic/*` + `classes/event/adapter/shopaholic/*` are allowed. `components/ProductPixel.php` is in `components/` — NOT allowed to import Lovata\Shopaholic\*; reads `product_id` from collector only.

---

## Plugin.php Boot Wiring (extension, not file-add)

**Source:** existing `Plugin.php:73-100`.

**WHAT CHANGES (RESEARCH Section 10):**
- L75 `if ($this->isShopaholicEnabled())` block adds:
  ```php
  $obRegistry->register(Product::class, ShopaholicProductAdapter::class);
  Event::subscribe(ProductPageWatcher::class);
  ```
- After existing L83-89 `cms.page.beforeRenderPage` listener (ThisVariable.config.metapixel injection), append a SECOND listener for PixelHead deferred flush:
  ```php
  Event::listen('cms.page.beforeRenderPage', function (CmsController $obController): void {
      PixelHead::flushDeferredFromController($obController);
  });
  ```
- L108-114 `registerComponents()` returns: add `ProductPixel::class => 'productPixel'`.
- Imports: add `Lovata\Shopaholic\Models\Product;`, `Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicProductAdapter;`, `Logingrupa\Metapixel\Classes\Event\Adapter\Shopaholic\ProductPageWatcher;`, `Logingrupa\Metapixel\Components\ProductPixel;`.

**Note (RESEARCH Section 10):** `isShopaholicEnabled()` checks `Lovata.OrdersShopaholic` — recommendation (option a) is to keep one guard since OrdersShopaholic requires Shopaholic transitively. Phase 6 ships with the existing one-guard pattern unless planner picks option (b) split.

---

## No Analog Found

None. All 13 files have in-plugin analogs. Pattern confidence: HIGH.

---

## Metadata

- **Analog search scope:** `plugins/logingrupa/metapixel/classes/` + `plugins/logingrupa/metapixel/components/` + `plugins/logingrupa/metapixel/tests/`
- **Files scanned:** ~25 (4 adapter files, 2 watchers, 4 component files, 6 test files, 3 exception files, 1 registry, 1 trait, 1 collector, Plugin.php, EventSubjectAdapter interface)
- **Pattern extraction date:** 2026-05-28
- **Confidence:** HIGH — all analogs are first-party, recently modified (May 2026), and reflect locked Phase 2/3/4/5 decisions

## PATTERN MAPPING COMPLETE
