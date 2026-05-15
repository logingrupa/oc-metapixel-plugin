# ARCHITECTURE — Metapixel v2.0 (Generic Event-Tracking via Adapter Pattern)

**Researched:** 2026-05-15
**Confidence:** HIGH (grounded entirely in existing codebase + Lovata.Toolbox precedent already in `vendor/`)

---

## 1. Executive Summary

v2.0 keeps the v1.x I/O backbone intact — `MetaClient` (HTTP boundary), `PayloadBuilder` (envelope shape), `UserDataHasher` (CCache-tagged hashing), `EventLogWriter` (UNIQUE race-fence), `SiteResolver` (multi-site `site_id`), `SendCapiEvent` (queue + dead-letter), `FailedEvent` model + audit UI, `EnsureFbpFbcCookies` middleware, `PluginGuard` boot-disable guard. Polymorphism on `EventLog.subject_*` was designed in Phase 3.1 for exactly this pivot.

What changes for v2.0 is **subject resolution**: every place that today imports `Lovata\OrdersShopaholic\Models\Order` directly (PayloadBuilder, UserDataHasher, OrderStatusWatcher, SendCapiEvent, PurchasePixel) must route through an **`EventSubjectAdapter`** resolved from an **`AdapterRegistry`** lookup keyed on the concrete subject class. Third-party plugins (MelonCart, custom carts, Buddies users for Lead, raw theme actions for ViewContent) register their own adapter from `Plugin::boot()` and get full CAPI + Pixel dedup for free.

The shape is **lifted directly from Lovata.Toolbox patterns already in this codebase**: `ProductItem::extend()`, `Component::extend() + addDynamicMethod()`, `Event::subscribe(ModelHandler)`, `Settings::extend()`. We do not invent a new extensibility paradigm — operators already know this one.

---

## 2. Integration Map: v1.x Primitives → v2.0 Disposition

| v1.x Component | Disposition | What Changes |
|---|---|---|
| `Classes\Helper\EventLogWriter::record()` | **KEEP verbatim** | Signature already polymorphic (`object $obSubject`). Zero changes. |
| `Classes\Helper\SiteResolver::forOrder()` | **GENERALISE** | Becomes `forSubject(object $obSubject, EventSubjectAdapter $obAdapter)`. Adapter returns `?int` via `$obAdapter->getSiteId($obSubject)`. `getActiveSiteId()` kept for ThemeActionAdapter / Lead / non-persisted subjects. |
| `Classes\Helper\PluginGuard` | **KEEP** | Boot-disable flag pattern is sound; no adapter dependency. |
| `Models\EventLog` (polymorphic) | **KEEP verbatim** | Designed for this pivot. UNIQUE shape `(subject_type, subject_id, event_name, channel, site_id)` already supports arbitrary subject classes. |
| `Models\FailedEvent` | **KEEP** | Dead-letter is subject-agnostic (stores serialised payload + exception). |
| `Classes\Meta\MetaClient` | **PARAMETERISE** | Today reads `Settings::get('pixel_id')` + `Settings::get('capi_access_token')` from singleton. v2.0: per-call `MetaClient::sendForPixel(string $sPixelId, string $sToken, array $arPayload)`. Caller (SendCapiEvent) resolves pixel + token via Settings::lookupForSite(SiteResolver→adapter). |
| `Classes\Meta\PayloadBuilder` | **DECOMPOSE** | Today: `buildPurchaseEventPayload(Order $obOrder, ...)`. v2.0: generic `buildEventPayload(string $sEventName, EventSubjectAdapter $obAdapter, object $obSubject, ValueResolver $obResolver, string $sEventId, int $iEventTime, array $arEventExtras)`. All Order-specific logic moves into ShopaholicAdapter + ShopaholicValueResolver. |
| `Classes\Meta\UserDataHasher::forOrder(Order)` | **DECOMPOSE** | Becomes `forSubject(EventSubjectAdapter $obAdapter, object $obSubject)`. Adapter provides `getUserData(object): array` — raw fields (email, phone, fbp, fbc, external_id, ct/st/zp). Hasher does only sha256 + CCache. |
| `Classes\Queue\SendCapiEvent` | **REWRITE** | Constructor takes `object $obSubject` (polymorphic) + `string $sAdapterClass` (so queue rehydrate can re-resolve adapter — adapter is not `SerializesModels`-compatible, only the subject is). `handle()` resolves adapter via `AdapterRegistry::resolveByClass($sAdapterClass)` and routes through it. |
| `Classes\Event\OrderStatusWatcher` | **DEMOTE** | Becomes one of N watchers. Moves to `Classes\Event\Adapter\Shopaholic\OrderStatusWatcher` (lives inside the ShopaholicAdapter sub-namespace). Registered by ShopaholicAdapter, not Plugin core. |
| `Components\PurchasePixel` | **GENERALISE** | Becomes `Components\SubjectPixel` — accepts `subject_class` + `subject_slug_field` properties. Reads adapter from `AdapterRegistry::resolveByClass($sSubjectClass)`. `default.htm` partial unchanged. |
| `Components\PixelHead` | **EXTEND** | Add Twig API surface: a context array `arThemeEvents` consumed by the partial. Theme operators push events via component handlers OR a Twig filter. See §6. |
| `Middleware\EnsureFbpFbcCookies` | **KEEP** | Cookie capture is subject-agnostic. |
| `Plugin::boot()` | **EXTEND** | Adds: `AdapterRegistry::instance()`, auto-registration of ShopaholicAdapter when `lovata/ordersshopaholic` is installed (detected via `PluginManager::instance()->exists('Lovata.OrdersShopaholic')`), auto-registration of ThemeActionAdapter unconditionally. |

---

## 3. AdapterRegistry — Singleton via Service Container

**Decision: service-container singleton (`App::singleton(AdapterRegistry::class, ...)`), NOT static registry.**

Rationale:
- `PluginGuard::instance()` already uses Singleton trait via `App::singleton('metapixel.disabled', ...)` — same pattern.
- Service-container binding is **testable** (`$this->app->instance(AdapterRegistry::class, $obFake)` in feature tests).
- Static registry would race on parallel pestphp 4 test workers.
- Lovata's own `CartProcessor::instance()` is service-container — operators expect this shape.

**Class shape:**

```php
final class AdapterRegistry
{
    /** @var array<class-string, class-string<EventSubjectAdapter>> */
    private array $arAdapterMap = [];

    public static function instance(): self
    {
        return App::make(self::class);
    }

    public function register(string $sSubjectClass, string $sAdapterClass): void
    {
        if (! is_subclass_of($sAdapterClass, EventSubjectAdapter::class)) {
            throw new \InvalidArgumentException(
                "Adapter $sAdapterClass must implement EventSubjectAdapter"
            );
        }
        $this->arAdapterMap[$sSubjectClass] = $sAdapterClass;
    }

    public function resolveFor(object $obSubject): ?EventSubjectAdapter
    {
        $sClass = get_class($obSubject);
        if (isset($this->arAdapterMap[$sClass])) {
            return App::make($this->arAdapterMap[$sClass]);
        }
        foreach ($this->arAdapterMap as $sRegisteredClass => $sAdapterClass) {
            if (is_a($obSubject, $sRegisteredClass)) {
                return App::make($sAdapterClass);
            }
        }
        return null;
    }

    public function resolveByClass(string $sAdapterClass): EventSubjectAdapter
    {
        return App::make($sAdapterClass);
    }
}
```

**Boot wiring in `Plugin::boot()`:**

```php
public function boot(): void
{
    PluginGuard::instance();
    $this->app->singleton(AdapterRegistry::class);

    $obRegistry = AdapterRegistry::instance();
    $obRegistry->register(ThemeActionEvent::class, ThemeActionAdapter::class);

    if (PluginManager::instance()->exists('Lovata.OrdersShopaholic')) {
        $obRegistry->register(\Lovata\OrdersShopaholic\Models\Order::class, ShopaholicOrderAdapter::class);
        Event::subscribe(\Logingrupa\Metapixel\Classes\Event\Adapter\Shopaholic\OrderStatusWatcher::class);
    }

    if (App::runningInConsole()) {
        return;
    }
    $this->app->make(HttpKernel::class)->pushMiddleware(EnsureFbpFbcCookies::class);
}
```

---

## 4. Plugin Discovery — Decision Locked

**Decision: each adapter plugin self-registers from its own `Plugin::boot()`.**

Why not auto-walk plugins for `MetapixelAdapter` class:
- Lovata ecosystem has zero precedent for plugin-walking.
- Walking is brittle: requires hardcoded class-name convention, fails silently on typos, can't express "register for multiple subjects".
- Plugin load order matters: a discovered adapter must wait until its target subject's plugin is loaded.

Why not composer extra metadata:
- October PluginManager doesn't read `composer.json#extra` at boot.
- Operators authoring adapters in-repo can't be discovered.

**Third-party MelonCart adapter plugin pattern:**

```php
// plugins/operator/meloncart-metapixel/Plugin.php
class Plugin extends \System\Classes\PluginBase
{
    public $require = ['Logingrupa.Metapixel', 'Operator.Meloncart'];

    public function boot(): void
    {
        AdapterRegistry::instance()->register(
            \Operator\Meloncart\Models\Cart::class,
            MeloncartCartAdapter::class,
        );
        Event::subscribe(MeloncartPaidWatcher::class);
    }
}
```

Plugin load ordering guaranteed by `$require = ['Logingrupa.Metapixel']` — October's `PluginManager::sortDependencies` resolves graph before calling `boot()`.

---

## 5. Interface Contracts (Two Interfaces, No More)

SRP: adapters do NOT mix value-resolution + event-dispatch + payload-build.

### 5.1 `EventSubjectAdapter` — subject metadata + Meta dispatch routing

```php
namespace Logingrupa\Metapixel\Classes\Adapter;

interface EventSubjectAdapter
{
    public function getSubjectType(object $obSubject): string;
    public function getSubjectId(object $obSubject): int;
    public function getSiteId(object $obSubject): ?int;
    public function getSecretKey(object $obSubject): ?string;
    public function getValueResolver(object $obSubject): ValueResolver;

    /**
     * Raw (unhashed) user_data fields for Meta CAPI.
     * Required keys (any may be null): em, ph, fn, ln, ct, st, zp, country,
     * external_id, fbp, fbc, client_ip_address, client_user_agent.
     *
     * @return array<string, ?string>
     */
    public function getUserData(object $obSubject): array;

    /** @return array<string, list<string>> e.g. ['Purchase' => ['capi', 'pixel']] */
    public function getSupportedEvents(): array;
}
```

### 5.2 `ValueResolver` — payload value computation

Decoupled because: (a) one adapter may use multiple resolvers (b2c vs b2b), (b) value math is the most likely third-party override (campaign pricing, multi-currency, tier discounts), (c) testable in isolation.

```php
interface ValueResolver
{
    /** @return list<string> e.g. ['SKU-42', 'SKU-42-7'] */
    public function resolveContentIds(object $obSubject): array;

    public function resolveValue(object $obSubject): float;
    public function resolveCurrency(object $obSubject): string;

    /** @return list<array{id: string, quantity: int, item_price: float}> */
    public function resolveContents(object $obSubject): array;

    public function resolveNumItems(object $obSubject): int;
}
```

---

## 6. ThemeActionAdapter + Twig Wire-Up

**Decision: hybrid — Component handler for AJAX-fired events, Twig API for page-render events.**

Two use cases:
1. **Page-render events** (`PageView`, `ViewContent`) — client-side at page load, pure Pixel `fbq('track', ...)`. CAPI mirror optional.
2. **User-action events** (`AddToCart`, `Lead`, custom "ClickedHero") — user gesture; CAPI + Pixel pair with shared `event_id`.

### 6.1 `ThemeActionEvent` — anonymous polymorphic subject

```php
final class ThemeActionEvent
{
    public function __construct(
        public readonly string $sActionKey,    // 'product-view:42'
        public readonly int $iSyntheticId,     // hash of $sActionKey
        public readonly string $sEventName,
        public readonly array $arPayload,
    ) {}

    public function getKey(): int { return $this->iSyntheticId; }
}
```

### 6.2 Twig API — page-render events via `PixelHead`

```twig
{# themes/operator-theme/pages/product.htm #}
{% put scripts %}
    {% do this.metapixel.pushEvent({
        name: 'ViewContent',
        action_key: 'product-view:' ~ product.id,
        content_ids: ['SKU-' ~ product.id],
        value: product.offer.first.price_value,
        currency: 'EUR',
    }) %}
{% endput %}
```

`this.metapixel.pushEvent` bound by `Plugin::registerMarkupTags()`. PixelHead's `onRun()` reads accumulated array, emits `fbq('track', name, custom_data, {eventID})` per event. Synthetic `event_id` server-generated; `EventLogWriter::record(channel='pixel')` via AJAX `onMarkFired`.

Optional `also_dispatch_capi: true` triggers `SendCapiEvent::dispatch(...)` from `PixelHead::onRun()`.

### 6.3 Larajax API — user-action events

Lifts pattern from existing `StoreExtender\CartComponentHandler`:

```php
Event::listen('cms.ajax.beforeRunHandler', function ($obController, $sHandler) {
    if (! Str::contains($sHandler, 'Metapixel::onFireEvent')) return null;
    // Reads request payload, creates ThemeActionEvent, dispatches SendCapiEvent
    // + emits inline <script>fbq(...)</script> response fragment for client.
});
```

Theme button:
```html
<button onclick="jax.ajax('Metapixel::onFireEvent',
    { data: { name: 'AddToCart', content_ids: ['SKU-42'], value: 12.5, currency: 'EUR' }})">
    Add to cart
</button>
```

---

## 7. Event::fire Extension Points

Pattern lift: `shopaholic.sorting.offer.get.list` (used by `StoreExtender\ExtendOfferHandler`). Convention: `metapixel.{phase}.{verb}`.

| Event | Fire site | Purpose | Listener payload |
|---|---|---|---|
| `metapixel.adapter.resolve` | `AdapterRegistry::resolveFor()` before fallback walk | Third-party adapter override | `[$obSubject, &$obAdapter]` |
| `metapixel.value.resolve` | `PayloadBuilder` before reading `ValueResolver` | Override individual values | `[$obSubject, $sEventName, &$arValues]` |
| `metapixel.user_data.resolve` | `UserDataHasher::forSubject` after adapter returns raw fields, before sha256 | Inject CRM external_id, loyalty card | `[$obSubject, &$arRawUserData]` |
| `metapixel.event.before_dispatch` | `SendCapiEvent::handle` after race-fence won, BEFORE `MetaClient::send` | Reject (return false) or mutate payload | `[$sEventName, &$arPayload, $obSubject]` |
| `metapixel.event.after_dispatch` | `SendCapiEvent::handle` after successful send | Audit/analytics tap | `[$sEventName, $arPayload, $obSubject, $arResponse]` |
| `metapixel.event.dead_letter` | `SendCapiEvent::failed` after FailedEvent write | External alerting (Slack/Telegram) | `[$sEventName, $arPayload, $obSubject, $obException]` |
| `metapixel.pixel.before_render` | `SubjectPixel::onRun` before Twig partial | Suppress pixel render (consent banner) | `[$sEventName, &$bShouldRender, $obSubject]` |
| `metapixel.settings.lookup` | `Settings::lookupForSite` before reading per-site overrides | Multi-pixel routing override | `[$iSiteId, &$arResolved]` |

---

## 8. Component Extension Points

| Component | Extension pattern | Use case |
|---|---|---|
| `PixelHead` | `addDynamicMethod('pushCustomScript', fn() => ...)` | Operator injects vendor script (Tag Manager, Hotjar) |
| `SubjectPixel` (renamed from PurchasePixel) | `addDynamicMethod('onCustomMarkFired', ...)` | Non-Purchase mark-fired channels (e.g. `Subscribe`) |
| `FailedEvents` (backend list controller) | `Backend\Behaviors\ListController::extendListColumns` | Custom audit columns |

Component::extend in `Plugin::boot()`:

```php
PixelHead::extend(function ($obComponent) {
    $obComponent->addDynamicMethod('renderHotjar', function () {
        return '<script>...hotjar...</script>';
    });
});
```

`pixelhead/default.htm` then: `{{ __SELF__.renderHotjar()|raw }}` — runtime-resolved via October's Component `__call`.

---

## 9. Multi-Pixel Routing Flow

Per project lock: Multisite trait on pixel_id + capi_access_token.

```
[Subject change e.g. Order paid]
        │
        v
[Adapter::getSiteId($obSubject)]  ── returns ?int $iSiteId
        │
        v
[SendCapiEvent::handle]
        │
        ├── EventLogWriter::record(..., $iSiteId)   ← race-fence scoped per site
        │
        v
[Settings::lookupForSite($iSiteId)]   ← Multisite trait reads per-site row
        │
        ├── Event::fire('metapixel.settings.lookup', [$iSiteId, &$arResolved])
        │
        v
[MetaClient::sendForPixel($sPixelId, $sToken, $arPayload)]
        │
        v
[POST https://graph.facebook.com/v23.0/{sPixelId}/events?access_token={sToken}]
```

`Settings::lookupForSite($iSiteId): array{pixel_id: string, capi_access_token: string}`. Reads via October 4 Multisite trait if installed and `$iSiteId !== null`, falls back to global row.

---

## 10. New vs Modified Components

### NEW
- `Classes\Adapter\EventSubjectAdapter` interface
- `Classes\Adapter\ValueResolver` interface
- `Classes\Adapter\AdapterRegistry` (final class, service-container singleton)
- `Classes\Adapter\Shopaholic\ShopaholicOrderAdapter`
- `Classes\Adapter\Shopaholic\ShopaholicOrderValueResolver`
- `Classes\Adapter\Theme\ThemeActionAdapter`
- `Classes\Adapter\Theme\ThemeActionEvent` (synthetic subject)
- `Classes\Adapter\Theme\ThemeEventCollector` (request-scoped accumulator)
- `Classes\Adapter\Theme\ThemeAjaxHandler` (cms.ajax.beforeRunHandler listener)
- `Classes\Event\Adapter\Shopaholic\OrderStatusWatcher`
- `Components\SubjectPixel` (generalizes PurchasePixel)
- `models/Settings.php` additions: `trusted_hosts` field, Multisite trait on `pixel_id` + `capi_access_token`
- `updates/add_multisite_pixel_id_and_token.php` migration
- README.md install + adapter-authoring guide

### MODIFIED (signature changes)
- `Classes\Meta\PayloadBuilder` — methods take `EventSubjectAdapter` + `ValueResolver` instead of typed `Order`
- `Classes\Meta\UserDataHasher` — `forSubject(EventSubjectAdapter, object)` replaces `forOrder(Order)`
- `Classes\Meta\MetaClient` — `sendForPixel(string, string, array)` replaces singleton-reading `send(array)`
- `Classes\Queue\SendCapiEvent` — constructor adds `string $sAdapterClass`; `handle()` resolves adapter via registry
- `Classes\Helper\SiteResolver` — `forSubject(object, EventSubjectAdapter): ?int` replaces `forOrder(Order)`
- `Plugin.php` — namespace, plugin dir rename, boot logic per §3
- `composer.json` — `lovata/shopaholic-plugin` in `suggest:`

### KEEP VERBATIM
- `Classes\Helper\EventLogWriter`
- `Classes\Helper\PluginGuard`
- `Models\EventLog`, `Models\FailedEvent`
- `Middleware\EnsureFbpFbcCookies`
- `updates/create_metapixel_event_log_table.php`
- `Components\PixelHead` core (extends with Twig API)

---

## 11. Build Order Across v2.0 Phases

### Phase v2.0-01 — Generic core + interfaces
- Rename namespace `Logingrupa\Metapixelshopaholic` → `Logingrupa\Metapixel`; rename plugin dir.
- Move `lovata/shopaholic-plugin` out of `require` into `suggest`.
- Define `EventSubjectAdapter` + `ValueResolver` interfaces.
- Define `AdapterRegistry` (empty bindings).
- Refactor `MetaClient::sendForPixel`, `Settings::lookupForSite($iSiteId)`.
- Refactor `PayloadBuilder::buildEventPayload(...)`.
- Refactor `UserDataHasher::forSubject(...)`.
- Refactor `SendCapiEvent` constructor + handle().
- All existing tests adapted via `FakeAdapter` test double.

Acceptance: plugin boots on fresh October install WITHOUT Lovata.OrdersShopaholic and does not throw. No events dispatched (no adapters registered).

### Phase v2.0-02 — ShopaholicOrderAdapter + ShopaholicOrderValueResolver
- Implement adapter + value resolver lifting all Order-specific logic from v1.x.
- Move `OrderStatusWatcher` to `Classes\Event\Adapter\Shopaholic\OrderStatusWatcher`.
- `Plugin::boot` conditionally registers based on `PluginManager::exists('Lovata.OrdersShopaholic')`.
- All v1.x Phase 3.1 tests regreen.

Acceptance: identical Pixel + CAPI traffic to v1.1.1 on nailscosmetics fixture data.

### Phase v2.0-03 — ThemeActionAdapter + Twig/Larajax API + SubjectPixel
- Implement `ThemeActionAdapter`, `ThemeActionEvent`, `ThemeEventCollector`, `ThemeAjaxHandler`.
- `Plugin::registerMarkupTags()` registers `metapixel.pushEvent` Twig helper.
- Generalise `PurchasePixel` → `SubjectPixel` with `subject_class` + `subject_slug_field` properties.
- Wire all `Event::fire` extension points (§7).
- Component::extend surfaces (§8).
- Feature tests: ThemeActionAdapterTest, PixelHeadTwigApiTest, LarajaxThemeEventTest.

Acceptance: operator fires ViewContent + AddToCart from theme with zero Shopaholic Order → Meta Events Manager shows browser Pixel + server CAPI pair with matching event_id.

### Phase v2.0-04 — Multisite trait + Settings rework + trusted_hosts + FailedEvents UI + translations + README + marketplace launch
- Migration adds Multisite trait to `pixel_id` + `capi_access_token`.
- `Settings::lookupForSite($iSiteId)` reads per-site rows.
- `trusted_hosts` Settings field + `jeremykendall/php-domain-parser` integration replaces v1.x HOST_INDEX_MAP.
- FailedEvents backend list audit re-derive (HARD-01..03).
- en/lv/ru translations.
- README install guide < 10 min + custom-adapter authoring walkthrough.
- CI matrix: `composer qa` on (October 4 + Shopaholic) AND (October 4 only). ≥90% coverage gate.
- Marketplace dry-run: clean OctoberCMS, composer require, first CAPI event end-to-end < 10 min.

Acceptance: marketplace-installable. Tag `v2.0.0`.

---

## 12. Pitfalls & Flags for Roadmapper

- **Phase v2.0-01 size** — namespace rename + signature changes + adapting all 177 v1.x tests. Consider splitting into v2.0-01a (namespace rename + composer suggest) and v2.0-01b (interface + signature changes).
- **Queue rehydrate adapter resolution** — if third-party plugin uninstalled between dispatch and pickup, resolution fails. Mitigation: `handle()` boundary catch on `BindingResolutionException` → FailedEvent + log critical.
- **ThemeEventCollector lifetime** — request-scoped singleton; tests need explicit teardown.
- **Adapter resolution + class hierarchy** — `is_a($obSubject, $sRegisteredClass)` walks up. If two adapters register for sibling classes via same ancestor, foreach order = map-insertion order. Document; consider explicit priority field if conflicts surface.
- **Event::fire reentrancy** — `metapixel.value.resolve` listener that queries Settings could trigger `metapixel.settings.lookup` reentrance. Static guard needed.
- **README quality is a launch gate** — < 10 min install. Treat as deliverable with its own acceptance test (fresh-install dry-run).
- **Multi-pixel-per-site test matrix** — 2 sites × 2 adapters × 2 channels = 8 paths. Extend `MultiSiteEventLogTest` pattern.

---

## 13. Sample Code: Third-Party Adapter Plugin Boot

```php
// plugins/acme/customcart/Plugin.php
class Plugin extends PluginBase
{
    public $require = ['Logingrupa.Metapixel'];

    public function boot(): void
    {
        AdapterRegistry::instance()->register(AcmeCart::class, AcmeCartAdapter::class);

        AcmeCart::extend(function ($obCart) {
            $obCart->bindEvent('model.afterSave', function () use ($obCart) {
                if ($obCart->isDirty('status') && $obCart->status === 'paid') {
                    SendCapiEvent::dispatch('Purchase', $this->buildPayload($obCart), $obCart, AcmeCartAdapter::class);
                }
            });
        });
    }
}
```

```php
final class AcmeCartAdapter implements EventSubjectAdapter
{
    public function getSubjectType(object $obSubject): string { return get_class($obSubject); }
    public function getSubjectId(object $obSubject): int { return (int) $obSubject->getKey(); }
    public function getSiteId(object $obSubject): ?int { return $obSubject->site_id; }
    public function getSecretKey(object $obSubject): ?string { return $obSubject->token; }
    public function getValueResolver(object $obSubject): ValueResolver { return new AcmeCartValueResolver(); }
    public function getUserData(object $obSubject): array {
        return [
            'em' => $obSubject->customer_email,
            'ph' => $obSubject->customer_phone,
            'external_id' => 'acme-cart-' . $obSubject->id,
        ];
    }
    public function getSupportedEvents(): array {
        return ['Purchase' => ['capi', 'pixel'], 'AddToCart' => ['capi', 'pixel']];
    }
}
```

Two classes, ~50 LOC, no Metapixel core changes.

---

## 14. Confidence Assessment

| Area | Confidence | Source |
|---|---|---|
| AdapterRegistry singleton via service container | HIGH | `PluginGuard::instance()` precedent in v1.x |
| Two-interface split (EventSubjectAdapter + ValueResolver) | HIGH | SRP from project memory + Lovata Item/Collection precedent |
| Event::fire extension points | HIGH | `shopaholic.sorting.offer.get.list` precedent in `StoreExtender\ExtendOfferHandler` |
| Plugin discovery via self-registration | HIGH | Lovata sub-plugin pattern (Properties, Reviews, Labels) |
| ThemeActionAdapter hybrid (Twig + Larajax) | MEDIUM | Larajax half is `CartComponentHandler` precedent; Twig half is October-standard but never used in this codebase — small spike needed |
| Multi-pixel routing via `Settings::lookupForSite` + Multisite trait | MEDIUM | Multisite trait is October 4 standard; per-field Multisite usage uncommon in Lovata — spike recommended in Phase 4 |
| Build order (4 phases) | HIGH | Dependency graph mechanical |
| README < 10-min install acceptance | MEDIUM | Achievable; no precedent for this metric in v1.x |

---

## 15. Sources

- v1.x `.planning/PROJECT.md` (v2.0 locked decisions)
- v1.x `.planning/archive/v1.1.1/phases/03.1-event-log-refactor/BRIEF.md` (EventLog polymorphic schema)
- `classes/helper/EventLogWriter.php`, `SiteResolver.php`, `Queue/SendCapiEvent.php`
- `plugins/lovata/toolbox/classes/event/ModelHandler.php` (Event::subscribe ModelHandler)
- `plugins/lovata/shopaholic/classes/item/ProductItem.php` (Item::extend extension surface)
- `plugins/logingrupa/storeextender/classes/event/cart/CartComponentHandler.php` (cms.ajax.beforeRunHandler Larajax bridge)
