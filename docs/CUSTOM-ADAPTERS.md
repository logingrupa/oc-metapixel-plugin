# Authoring a custom adapter

A custom adapter lets you ship Meta Pixel + Conversions API tracking for any
OctoberCMS cart, theme action, or arbitrary subject — Lovata Shopaholic, OFFLINE
Mall, Meloncart, a hand-rolled custom cart, or a non-commerce event — in
roughly fifty lines of code. The plugin core (`MetaClient`, `PayloadBuilder`,
`UserDataHasher`, `EventLogWriter`) is subject-agnostic. All per-subject
behavior lives behind two interfaces — `EventSubjectAdapter` and
`ValueResolver` — and a single registry call.

This guide walks the contract, the minimum register snippet, a fully-worked
inline example, the three `Event::fire` hook constants, and the contract-test
harness you should run against your adapter before publishing.

## The contract: EventSubjectAdapter + ValueResolver

`EventSubjectAdapter` (7 methods) routes a subject (an order, a theme action,
a custom cart) to its opaque alias, id, site, secret-key, value resolver,
raw user-data fields, and supported event channels:

```php
namespace Logingrupa\Metapixel\Classes\Adapter;

interface EventSubjectAdapter
{
    public function getSubjectType(object $obSubject): string;   // opaque alias
    public function getSubjectId(object $obSubject): int;        // positive
    public function getSiteId(object $obSubject): ?int;          // from subject ONLY
    public function getSecretKey(object $obSubject): ?string;    // nullable token
    public function getValueResolver(object $obSubject): ValueResolver;
    public function getUserData(object $obSubject): array;       // 13-key Meta CAPI set
    public function getSupportedEvents(): array;                 // Map<event, list<channel>>
}
```

- **`getSubjectType`** returns the opaque alias identifying your vendor +
  entity kind (e.g. `'shopaholic.order'`, `'mall.order'`, `'theme.action'`).
  MUST NOT contain backslashes; MUST NOT be a class FQN; MUST be ≤ 64
  characters. Aliases keep the EventLog stable across class renames and
  multi-vendor installs.
- **`getSubjectId`** returns the numeric subject identifier (order id,
  synthetic theme-action id). MUST be a positive `int` —
  `EventLogWriter::record()` rejects values ≤ 0.
- **`getSiteId`** MUST read from the subject itself (an `Order.site_id`
  column, a pushed theme-action payload). MUST NOT be derived from request
  context, the active SiteManager site, or Auth state — cross-context
  determinism is the invariant. PHPStan disallowed-calls bans
  `SiteManager::*`, `request()`, and `Request::*` inside the adapter
  directories to enforce this statically.
- **`getSecretKey`** returns a per-subject secret token (`Order.secret_key`,
  session token, etc.) used to derive an anonymous `external_id` when the
  subject has no logged-in user. Return `null` when no token is available.
- **`getValueResolver`** returns the per-subject `ValueResolver` — each
  adapter chooses how `content_ids`, `value`, `currency`, `contents`, and
  `num_items` are computed.
- **`getUserData`** returns raw (unhashed) user-data fields per the Meta CAPI
  spec. Allowed keys: `em`, `ph`, `fn`, `ln`, `ct`, `st`, `zp`, `country`,
  `external_id`, `fbp`, `fbc`, `client_ip_address`, `client_user_agent`.
  Missing keys MUST be `null` (do not omit).
- **`getSupportedEvents`** returns a declarative event-channel matrix —
  shape `array<string, list<string>>` where the outer key is the Meta event
  name (`Purchase`, `ViewContent`, …) and the inner list values are channel
  names — a subset of `{'capi', 'pixel'}`.

`ValueResolver` (5 methods) is the per-event value-computation surface:

```php
namespace Logingrupa\Metapixel\Classes\Adapter;

interface ValueResolver
{
    /** @return list<string> */
    public function resolveContentIds(object $obSubject): array;
    public function resolveValue(object $obSubject): float;
    public function resolveCurrency(object $obSubject): string;       // ISO-4217
    /** @return list<array{id: string, quantity: int, item_price: float}> */
    public function resolveContents(object $obSubject): array;
    public function resolveNumItems(object $obSubject): int;
}
```

- **`resolveContentIds`** returns the SKU/content-id list for the event.
- **`resolveValue`** returns the monetary value in `resolveCurrency()`'s
  currency.
- **`resolveCurrency`** returns an ISO-4217 currency code (`EUR`, `USD`,
  `NOK`, …).
- **`resolveContents`** returns line-item details per Meta CAPI spec.
- **`resolveNumItems`** returns the total number of items in the event.

Two contract reminders worth restating: `subject_type` is an opaque alias —
not a class FQN, no backslashes, ≤ 64 chars (invariant 01). `getSiteId`
reads from the subject only — never from request context (P-01 anchor).

## Minimal example: register your adapter (AcmeCart)

This minimal snippet shows the registration shape. Implementations of
`AcmeCartAdapter` itself follow the contract above — see the next section
for a fully-worked example.

```php
// plugins/acme/customcart/Plugin.php
class Plugin extends PluginBase {
    public $require = ['Logingrupa.Metapixel'];

    public function boot(): void {
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

Three things matter here:

- `$require = ['Logingrupa.Metapixel']` tells October that your plugin loads
  after the Metapixel plugin — without it, `AdapterRegistry` is not yet
  bound when your `boot()` runs.
- `AdapterRegistry::instance()->register(...)` maps your subject class to
  your adapter class. The plugin core's queue worker calls
  `AdapterRegistry::resolveByClass(AcmeCartAdapter::class)` on rehydrate.
- `SendCapiEvent::dispatch(...)` is the only public entry point — see
  *Trigger dispatch* below.

## Full inline example: OFFLINE Mall

A complete adapter + value resolver targeting `OFFLINE\Mall\Models\Order`:

```php
namespace Offline\Mall\Adapters;

use Logingrupa\Metapixel\Classes\Adapter\EventSubjectAdapter;
use Logingrupa\Metapixel\Classes\Adapter\ValueResolver;
use OFFLINE\Mall\Models\Order;

final class MallOrderAdapter implements EventSubjectAdapter {
    public function getSubjectType(object $obSubject): string {
        return 'mall.order';  // opaque alias — NOT class FQN
    }
    public function getSubjectId(object $obSubject): int {
        return (int) $obSubject->getKey();
    }
    public function getSiteId(object $obSubject): ?int {
        // Read from subject — never from request context.
        return (int) ($obSubject->site_id ?? null) ?: null;
    }
    public function getSecretKey(object $obSubject): ?string {
        return (string) ($obSubject->getAttribute('hash') ?? '') ?: null;
    }
    public function getValueResolver(object $obSubject): ValueResolver {
        return new MallOrderValueResolver;
    }
    public function getUserData(object $obSubject): array {
        return [
            'em' => $obSubject->customer?->email,
            'fn' => $obSubject->customer?->firstname,
            'ln' => $obSubject->customer?->lastname,
            'ph' => $obSubject->customer?->phone,
            'ct' => $obSubject->shipping_address?->city,
            'st' => $obSubject->shipping_address?->state?->code,
            'zp' => $obSubject->shipping_address?->zip,
            'country' => $obSubject->shipping_address?->country?->code,
            'external_id' => $obSubject->customer?->id ? (string) $obSubject->customer->id : null,
            'fbp' => null,
            'fbc' => null,
            'client_ip_address' => null,
            'client_user_agent' => null,
        ];
    }
    public function getSupportedEvents(): array {
        return ['Purchase' => ['capi', 'pixel']];
    }
}
```

And the matching value resolver:

```php
namespace Offline\Mall\Adapters;

use Logingrupa\Metapixel\Classes\Adapter\ValueResolver;

final class MallOrderValueResolver implements ValueResolver {
    public function resolveContentIds(object $obSubject): array {
        return $obSubject->products
            ->map(fn ($obItem) => 'SKU-' . $obItem->product_id)
            ->values()
            ->all();
    }
    public function resolveValue(object $obSubject): float {
        return (float) ($obSubject->total_post_taxes / 100);
    }
    public function resolveCurrency(object $obSubject): string {
        return (string) ($obSubject->currency?->code ?? 'EUR');
    }
    public function resolveContents(object $obSubject): array {
        return $obSubject->products->map(fn ($obItem) => [
            'id' => 'SKU-' . $obItem->product_id,
            'quantity' => (int) $obItem->quantity,
            'item_price' => (float) ($obItem->price_post_taxes / 100),
        ])->values()->all();
    }
    public function resolveNumItems(object $obSubject): int {
        return (int) $obSubject->products->sum('quantity');
    }
}
```

This code lives ONLY in this documentation. The plugin itself does NOT ship a
`classes/adapter/mall/` directory. Copy these blocks into your own plugin and
adapt the subject class, field names, and currency conversions to your
domain.

## Trigger dispatch

`SendCapiEvent::dispatch` is the only public entry point. It takes the Meta
event name, the pre-built payload array, the subject object, and the adapter
class FQN, queues the job, and returns immediately.

```php
SendCapiEvent::dispatch(
    'Purchase',
    $arPayload,
    $obSubject,
    MallOrderAdapter::class,
);
```

Three runtime contracts to know:

- The queue worker rehydrates the adapter via
  `AdapterRegistry::resolveByClass(MallOrderAdapter::class)` on `handle()` —
  the worker process must have the same adapter binding available.
- On transient HTTP failure, Laravel retries per the job's `$tries` and
  `$backoff` schedule.
- On permanent failure (or rehydrate failure), `SendCapiEvent::writeFailedEvent()`
  persists a `FailedEvent` row AND fires `metapixel.event.dead_letter` —
  see the *dead_letter* hook below.

## Hook patterns

Three `Event::fire` hooks are exposed for marketplace extensibility. The hook
constant strings are stable contract surface and match
`SendCapiEvent::HOOK_BEFORE_DISPATCH`, `HOOK_AFTER_DISPATCH`, and
`HOOK_DEAD_LETTER` verbatim.

### `before_dispatch` — inject test_event_code for staging

```php
Event::listen('metapixel.event.before_dispatch',
    function (string $sEventName, array &$arPayload, object $obSubject): ?bool {
        if (app()->environment('staging')) {
            $arPayload['test_event_code'] = config('mall.metapixel.test_event_code');
        }
        return null;
    }
);
```

MUST NOT mutate `event_id` or `event_time` — Meta dedup contract anchor. The
job snapshots and restores both fields after the hook runs to enforce this.
Returning `false` vetoes the dispatch.

### `after_dispatch` — mirror to analytics dashboard

```php
Event::listen('metapixel.event.after_dispatch',
    function (string $sEventName, array $arPayload, object $obSubject, array $arResponse): void {
        AnalyticsClient::record('meta_capi_success', [
            'event_name' => $sEventName,
            'event_id' => $arPayload['data'][0]['event_id'] ?? null,
            'fbtrace_id' => $arResponse['fbtrace_id'] ?? null,
        ]);
    }
);
```

Observe-only. Listener exceptions are caught, logged via `Log::warning`, and
swallowed — they never propagate back into the dispatch pipeline.

### `dead_letter` — Slack alert on permanent failure

```php
Event::listen('metapixel.event.dead_letter',
    function (string $sEventName, array $arPayload, object $obSubject, \Throwable $obException): void {
        SlackWebhook::send([
            'channel' => '#alerts-metapixel',
            'text' => "Meta CAPI permanent failure: {$sEventName} on " . get_class($obSubject)
                    . " — {$obException->getMessage()}",
        ]);
    }
);
```

Observe-only. Fires AFTER `SendCapiEvent::writeFailedEvent()` persists the
`FailedEvent` row. Use this for operator alerts; use the FailedEvents backend
UI for replay.

## Testing your adapter

Extend the contract base + supply factory methods:

```php
use Logingrupa\Metapixel\Classes\Testing\EventSubjectAdapterContractTestCase;

final class MallOrderAdapterContractTest extends EventSubjectAdapterContractTestCase
{
    protected function makeAdapter(): EventSubjectAdapter
    {
        return new MallOrderAdapter;
    }

    protected function makeSubject(): object
    {
        return Order::factory()->create(['site_id' => 1, 'status' => 'paid']);
    }
}
```

`pest tests/MallOrderAdapterContractTest.php` exits 0 → your adapter
satisfies the marketplace contract. The 10 invariants enforce:

1. `subject_type` is opaque alias (no backslashes, ≤ 64 chars)
2. `subject_id` is a positive `int`
3. `getSiteId` deterministic across successive calls (same subject, same result)
4. `getSiteId` returns `?int` — no `Request` side effect (PHPStan
   disallowed-calls anchored)
5. `getSecretKey` returns `?string`, never throws
6. `getValueResolver` returns a `ValueResolver` instance
7. `getUserData` keys ⊆ the 13-key Meta CAPI allowed set; values are
   `string|null`
8. `getSupportedEvents` shape is `Map<string, list<'capi'|'pixel'>>` with at
   least one supported event declared
9. Registry round-trip via `AdapterRegistry::register` + `resolveFor`
   returns the same adapter
10. `PayloadBuilder` produces a valid envelope (`data[0]` keys: `event_id`,
    `event_time`, `event_name`, `action_source`, `user_data`, `custom_data`)

(Source: `classes/testing/EventSubjectAdapterContractTestCase.php`)

## Anti-patterns

Prefer `Event::fire` hooks (`before_dispatch`, `after_dispatch`,
`dead_letter`) for cross-cutting concerns. They are stable contract surface,
documented signatures, and exception-safe — a misbehaving listener cannot
break dispatch.

`Component::extend(PixelHead::class, ...)` + `addDynamicMethod(...)` is
**LAST RESORT**. Use ONLY when an `Event::fire` hook does not exist for your
use case. The surface is unbounded — every method can be replaced — and
third parties must scope dynamic methods with an `onMetapixel*` prefix to
avoid collisions with future plugin upgrades.
