# Phase 2: Adapter system core — contracts + registry + extension hooks — Research

**Researched:** 2026-05-17
**Domain:** OctoberCMS v4 plugin adapter contracts + service-container registry + Event::fire extensibility, Pest 4 contract-test base, hermetic SQLite race-fence migrations.
**Confidence:** HIGH on all three open-question resolutions (direct Lovata + Laravel core file:line evidence). HIGH on backbone class shapes (forward-spec derived from REQUIREMENTS.md + verified codebase precedent). MEDIUM on the contract-test-base location decision (Pest 4 docs sparse; pattern is standard PHPUnit but the "ships in `classes/Testing/` under require-dev" gate is a project-specific call).

---

## TL;DR

Three open questions resolve cleanly. **OQ-1 (177-test migration):** rewrite fresh, target ~60-80 backbone-only Pest 4 tests across `tests/Unit/` + `tests/Feature/` + `tests/Contract/` — the v1.x suite shipped Order-typed fixtures in 90% of cases and the all-fresh decision (D-01) makes cherry-pick a worse plan than fresh-write against ADAP-01..11. **OQ-2 (hook halt-able semantics):** keep `before_dispatch` halt-able via Laravel's third `$halt` argument to `Event::fire(...)` — Lovata precedent at `OrderProcessor.php:83` uses exactly this idiom (`if (Event::fire(self::EVENT_UPDATE_ORDER_BEFORE_CREATE, [...], true) === false) return null;`) which dispatches `Illuminate\Events\Dispatcher::dispatch(..., halt: true)` and short-circuits on the first non-null listener return. The other two hooks (`after_dispatch`, `dead_letter`) stay observe-only. P-08 is reconciled by documenting that halt is opt-in via `$halt=true` AND that the payload arrays passed are sent by-value, not by-reference (Laravel docs do not mutate caller's array unless the listener writes back through a closure-captured reference — listener-isolation per D-16 keeps that surface narrow). **OQ-3 (event-shape assembly):** Option B — adapter exposes `getSupportedEvents(): array<string, list<string>>` (already locked in ADAP-01) and the payload-extras slot moves through PayloadBuilder's existing `array $arEventExtras` parameter; ShopaholicOrderAdapter (Phase 3) supplies Purchase's `content_ids`/`contents`/`value` via the ValueResolver, while ThemeActionAdapter supplies its `action_key` / `synthetic_id` shape via the same `$arEventExtras` slot. No `switch ($sEventName)` in PayloadBuilder. SRP + "no over-engineering" both push this way. The `ValueResolver` interface (ADAP-02) is the event-agnostic computation surface; PayloadBuilder is the envelope shaper. Different shapes per event live in each adapter's `getUserData()` + `ValueResolver::resolveContents()` + `$arEventExtras` triplet.

Phase 2 ships ~13 backbone classes, 2 fresh migrations (EventLog + FailedEvent), `Logingrupa\Metapixel\Classes\Testing\EventSubjectAdapterContractTestCase`, `tests/Doubles/FakeAdapter` + `FakeValueResolver`, and three tooling deltas (phpstan.neon disallowed-calls scopes, composer-dependency-analyser.php rule shape, phpunit.xml Adapter testsuite block). No production adapter, no Lovata imports anywhere in `classes/`. Planner should slice into ~7 plans (see §8).

---

## User Constraints (from CONTEXT.md)

### Locked Decisions (D-01..D-22)

- **D-01 — All fresh, no port:** every backbone class is written from scratch against REQUIREMENTS.md ADAP-01..11 + research/ARCHITECTURE.md §2-§10. No cherry-pick from `legacy/v1.1.1`. Applies to `EventLogWriter`, `EventLog` + `FailedEvent` models, `PluginGuard`, exception classes, `MetaClient`, `PayloadBuilder`, `UserDataHasher`, `Settings` (Phase 2 shape), `SendCapiEvent`, `SiteResolver`, both migrations.
- **D-04..D-07 — 2 tables, fresh migrations:** `logingrupa_metapixel_event_log` (UNIQUE race-fence on `subject_type`, `subject_id`, `event_name`, `channel`, `site_id`) + `logingrupa_metapixel_failed_events` (admin-audit + replay queue Phase 4).
- **D-08..D-10 — FakeAdapter test-double:** `tests/Doubles/FakeAdapter.php` (single class, fluent setters) + `tests/Doubles/FakeValueResolver.php`. Outside PSR-4 production root (autoload-dev only).
- **D-11..D-13 — Contract test base:** `Logingrupa\Metapixel\Classes\Testing\EventSubjectAdapterContractTestCase` (abstract Pest 4 / PHPUnit 12 class) with ~10 invariants. Third parties extend it for their own adapter tests. Same base used internally for Phase 3 adapters.
- **D-14..D-22 — Locked from project decisions:** AdapterRegistry as `App::singleton`, three hooks (`before_dispatch` / `after_dispatch` / `dead_letter`), listener exceptions caught + `Log::warning` + continue, `SiteResolver::forSubject` is the only authoritative `site_id` source, Graph API pinned to `v23.0`, `MetaClient::sendForPixel($sPixelId, $sToken, $arPayload)` per-call credentials, `SendCapiEvent` 4th constructor arg `string $sAdapterClass`, `PayloadBuilder::buildEventPayload` subject-agnostic, `UserDataHasher::forSubject` adapter-driven.

### Claude's Discretion

- Exception hierarchy: one base `MetaPixelException` + per-error-class subclasses (fresh names, `final` where extension not designed in).
- Hungarian notation (`$ob`, `$ar`, `$i`, `$s`, `$f`, `$b`) per project lock.
- Short Laravel docblocks (one-line summary + `@param` + `@return`).
- Test directory layout: `tests/Unit/`, `tests/Feature/`, `tests/Doubles/`, `tests/Contract/`.
- No `assert()`, no `declare(strict_types=1)` enforcement.
- Plugin-doc-cleanup task executes as a separate commit AFTER 02-CONTEXT.md commits — not part of Phase 2 plan scope.

### Deferred Ideas (OUT OF SCOPE)

- Strip ALL v1.x references from `.planning/` docs — separate task, post-Phase 2.
- Five additional `Event::fire` hooks (`adapter.resolve`, `value.resolve`, `user_data.resolve`, `pixel.before_render`, `settings.lookup`) — DEFERRED to v2.1.
- Multisite trait field-whitelist on `Settings::pixel_id` + `capi_access_token` — Phase 4 (MULT-01..06). Phase 2 single-row Settings; `Settings::lookupForSite($iSiteId)` stub returns the default row regardless of `$iSiteId`.
- FailedEvents admin UI + Replay + CheckDedup — Phase 4 (FAIL-01..03). Phase 2 ships only the table + minimal model.
- ShopaholicOrderAdapter + OrderStatusWatcher — Phase 3 (SHOP-01..05).
- ThemeActionAdapter + Twig API + Larajax handler — Phase 3 (THEM-01..07).
- EnsureFbpFbcCookies generalization + `trusted_hosts` + `jeremykendall/php-domain-parser` — Phase 4.

## Phase Requirements

| ID | Description (verbatim from REQUIREMENTS.md) | Research Support |
|----|----|----|
| ADAP-01 | `EventSubjectAdapter` interface: `getSubjectType`, `getSubjectId`, `getSiteId`, `getSecretKey`, `getValueResolver`, `getUserData`, `getSupportedEvents`. | §4.1 interface shape; §3 OQ-3 resolves `getSupportedEvents` as the per-event shape registry. |
| ADAP-02 | `ValueResolver` interface: `resolveContentIds`, `resolveValue`, `resolveCurrency`, `resolveContents`, `resolveNumItems`. | §4.2 interface shape. |
| ADAP-03 | `AdapterRegistry` service-container singleton with `register` / `resolveFor` / `resolveByClass`. `is_a()` class-hierarchy walk. | §4.3 registry shape; Lovata `CartProcessor` Singleton trait + `App::singleton` precedent at `vendor/october/rain/src/Support/Traits/Singleton.php:10-25` (read-only — pattern only). |
| ADAP-04 | 3 `Event::fire` hooks: `metapixel.event.before_dispatch` (halt-able), `after_dispatch`, `dead_letter`. | §2 OQ-2 resolution; Laravel `Dispatcher::dispatch($event, $payload, $halt)` at `vendor/laravel/framework/src/Illuminate/Events/Dispatcher.php:270-339`; Lovata halt-able precedent at `plugins/lovata/ordersshopaholic/classes/processor/OrderProcessor.php:83`. |
| ADAP-05 | Listener exceptions caught + `Log::warning` + continue. | §4.7 SendCapiEvent listener-isolation wrap shape. |
| ADAP-06 | `SiteResolver::forSubject(object, EventSubjectAdapter): ?int` + PHPStan disallowed-calls bans `SiteManager::*` / `request()` / `Request::*` in adapter/queue/event dirs. | §5 tooling deltas. |
| ADAP-07 | `PayloadBuilder::buildEventPayload(string, EventSubjectAdapter, object, ValueResolver, string, int, array): array` — subject-agnostic. | §3 OQ-3 resolution + §4.5 shape. |
| ADAP-08 | `UserDataHasher::forSubject(EventSubjectAdapter, object): array` — adapter provides raw, hasher does sha256. | §4.6 shape. |
| ADAP-09 | `MetaClient::sendForPixel(string $sPixelId, string $sToken, array $arPayload): array` — per-call credentials. Graph v23.0 const. | §4.4 shape. |
| ADAP-10 | `SendCapiEvent` 4th arg `string $sAdapterClass`. `handle()` resolves adapter via `resolveByClass`. `BindingResolutionException` → FailedEvent + `Log::critical`. | §4.7 shape. |
| ADAP-11 | Backbone test suite regreens against new signatures via `FakeAdapter`. | §6 test plan + §2 OQ-1 resolution. |

---

## 1. Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Subject metadata resolution (alias, id, site_id, secret_key, user_data fields) | Adapter (`classes/Adapter/<vendor>/`) | — | SRP. Each subject class has exactly one adapter. Locked by D-17 + D-22. |
| Per-event content/value/contents shape | ValueResolver (`classes/Adapter/<vendor>/`) | Adapter (passes resolver via `getValueResolver`) | Decoupled because Purchase vs ViewContent vs Lead value math diverges. SRP. |
| Adapter discovery / class-hierarchy walk | `classes/Adapter/AdapterRegistry` (service-container singleton) | — | Single resolution point. Test-swappable via `$this->app->instance(...)`. |
| HTTP boundary to Meta Graph API v23.0 | `classes/Meta/MetaClient` | — | Stateless single-shot POST. Retry/backoff = queue layer. |
| Envelope assembly (event_id, event_time, action_source, user_data, custom_data) | `classes/Meta/PayloadBuilder` | — | Subject-agnostic envelope per ADAP-07. |
| user_data sha256 hashing + CCache cache | `classes/Meta/UserDataHasher` | — | Pure transform. Adapter provides raw; hasher hashes. |
| EventLog race-fence write | `classes/Helper/EventLogWriter` | — | Pure I/O. `insertOrIgnore` race. Returns bool. |
| FailedEvent write (permanent-fail + dead-letter) | `classes/Queue/SendCapiEvent` calls `Models\FailedEvent::create` | — | Boundary catch in queue job. Phase 4 ships admin UI. |
| site_id resolution from subject | `classes/Helper/SiteResolver::forSubject` | — | Adapter-routed only. Never reads request context. P-01 anchor. |
| Plugin-disabled guard (empty `pixel_id`) | `classes/Helper/PluginGuard` | Plugin.php boot | Boot-time non-throw; `Log::warning` + disabled flag. Carry-forward locked decision. |
| Queue job orchestration (race-fence → Settings lookup → MetaClient send → FailedEvent on perm-fail) | `classes/Queue/SendCapiEvent` (Laravel `ShouldQueue`) | — | One responsibility: orchestrate the 4-step dispatch pipeline. |
| Settings access (`pixel_id`, `capi_access_token`, `test_event_code`) | `models/Settings` extends `Lovata\Toolbox\Models\CommonSettings` | — | Ecosystem norm. Auto-caches. Multisite-trait inherited from CommonSettings (field-whitelist Phase 4). |
| Race-fence + dead-letter persistence | `updates/<timestamp>_create_metapixel_event_log_table.php` + `updates/<timestamp>_create_metapixel_failed_events_table.php` | — | Two tables (D-04..D-07). |

No tier gets misassigned. Adapters never know about MetaClient; MetaClient never reads Settings; PayloadBuilder never types `Order`; SendCapiEvent never reads Request.

---

## 2. OQ-2 Resolution — Hook contract: halt-able on `before_dispatch` ONLY, observe-only on the other two

### Recommendation

Implement exactly the contract REQUIREMENTS.md ADAP-04 specifies: **`before_dispatch` is halt-able via `Event::fire(..., $halt=true)`; `after_dispatch` and `dead_letter` are observe-only (`$halt=false`, default)**. P-08's "hooks NOT cancelable" warning is reconciled by scoping cancelability to `before_dispatch` only AND documenting in the hook PHPDoc that listeners cancel ONLY by returning `false` (not by throwing — see D-16).

### Evidence: Laravel + Lovata precedent

Laravel's `Illuminate\Events\Dispatcher::dispatch` accepts `$halt = false` as the third argument. October Rain's `Event` facade routes to this method. When `$halt = true`:

```
plugins/lovata/ordersshopaholic/classes/processor/OrderProcessor.php:83
    if (Event::fire(self::EVENT_UPDATE_ORDER_BEFORE_CREATE, [$this->arOrderData, $this->obUser], true) === false) {
        return null;
    }
```

This is THE established Lovata pattern for "let a listener veto the operation". Laravel's `invokeListeners` (`vendor/laravel/framework/src/Illuminate/Events/Dispatcher.php:316-339`) implements two short-circuit semantics:

1. `if ($halt && ! is_null($response)) return $response;` — first non-null wins, dispatch stops.
2. `if ($response === false) break;` — any listener returning literal `false` halts propagation regardless of `$halt`.

The Lovata idiom relies on semantic (1): caller passes `true`, listener returns either `false` (to veto) or `null` (to abstain). The other observe-only Lovata sites (e.g. `OrderProcessor.php:92` `Event::fire(self::EVENT_UPDATE_ORDER_AFTER_CREATE, $this->obOrder);` — no third arg, default `$halt=false`) collect all listener responses but cannot short-circuit.

### Concrete Phase 2 shape

`classes/Queue/SendCapiEvent::handle()`:

```php
public function handle(AdapterRegistry $obRegistry, MetaClient $obClient): void
{
    $obAdapter = $obRegistry->resolveByClass($this->sAdapterClass);

    $bShouldHalt = $this->fireBeforeDispatchHalt($obAdapter);
    if ($bShouldHalt) {
        Log::info('metapixel: dispatch halted by before_dispatch listener', [...]);
        return;
    }

    // ... race-fence + MetaClient::sendForPixel + after_dispatch + dead_letter
}

private function fireBeforeDispatchHalt(EventSubjectAdapter $obAdapter): bool
{
    try {
        $arPayload = $this->arPayload;
        $mResult = Event::fire(
            'metapixel.event.before_dispatch',
            [$this->sEventName, &$arPayload, $this->obSubject],
            true,
        );
        $this->arPayload = $arPayload;
        return $mResult === false;
    } catch (\Throwable $obException) {
        Log::warning('metapixel: before_dispatch listener threw — continuing dispatch', [
            'meta_pixel.exception' => get_class($obException),
            'meta_pixel.message' => $obException->getMessage(),
        ]);
        return false;
    }
}
```

`after_dispatch` and `dead_letter`: no third `true` arg, no `=== false` check, exception isolation per D-16:

```php
private function fireAfterDispatch(array $arResponse): void
{
    try {
        Event::fire('metapixel.event.after_dispatch',
            [$this->sEventName, $this->arPayload, $this->obSubject, $arResponse],
        );
    } catch (\Throwable $obException) {
        Log::warning('metapixel: after_dispatch listener threw — observed', [...]);
    }
}
```

### PHPDoc draft for the three hooks (canonical contract — must ship in a `Plugin.php` class-level PHPDoc + every fire-site)

```
/**
 * Hook: metapixel.event.before_dispatch
 *
 * Fires BEFORE EventLogWriter::record + MetaClient::sendForPixel for any
 * adapter-routed event. Halt-able: a listener returning literal `false`
 * vetoes the dispatch — no race-fence row written, no HTTP POST, no
 * after_dispatch hook. Return `null` (or any non-false value) to abstain.
 *
 * Listener signature: function(string $sEventName, array &$arPayload, object $obSubject): mixed
 *
 * Mutation policy: the payload array is passed by-reference to allow consent
 * banners / CRM enrichment to mutate user_data + custom_data. DO NOT mutate
 * event_id or event_time — both are the dedup contract anchor (server ⇄
 * browser pair). Listeners that mutate event_id break Meta dedup silently.
 *
 * Listener exceptions: caught + Log::warning + dispatch continues
 * (treat as abstain — same as returning null).
 *
 * Hook: metapixel.event.after_dispatch
 *
 * Fires AFTER successful MetaClient::sendForPixel — useful for analytics
 * taps, custom audit trail, dashboards. Observe-only — no halt, no payload
 * mutation. Receives the decoded Graph API response as the 4th arg.
 *
 * Listener signature: function(string $sEventName, array $arPayload, object $obSubject, array $arGraphResponse): mixed
 *
 * Hook: metapixel.event.dead_letter
 *
 * Fires AFTER FailedEvent::create on permanent failure (HTTP 4xx classify
 * + missing-config exceptions). Observe-only — useful for Slack/email
 * external alerting (deferred to v2.x OPS-01).
 *
 * Listener signature: function(string $sEventName, array $arPayload, object $obSubject, \Throwable $obException): mixed
 */
```

### Why this reconciles P-08

P-08 warns against "third-party listener silently corrupts shared array". Three mitigations stack:

1. **Scope-limited mutation:** only `before_dispatch` has `&$arPayload`. The other two pass payload by-value.
2. **Documented contract:** the PHPDoc above forbids mutating `event_id`/`event_time`. The contract is enforceable in code review + a Phase 2 invariant test (see §6 test plan T11) — write a deliberately-misbehaving listener and assert dispatch caught the mismatch.
3. **Exception isolation:** D-16's catch + log + continue means a throwing listener cannot poison the dispatch pipeline (whereas Lovata's raw `Event::fire` rethrows).

`[VERIFIED: plugins/lovata/ordersshopaholic/classes/processor/OrderProcessor.php:83]` `[VERIFIED: vendor/laravel/framework/src/Illuminate/Events/Dispatcher.php:270-339]` `[VERIFIED: plugins/lovata/ordersshopaholic/classes/helper/AbstractPaymentGateway.php:146,165,188]`

---

## 3. OQ-3 Resolution — PayloadBuilder event-shape assembly: Option B (adapter-supplied extras)

### Recommendation

`PayloadBuilder::buildEventPayload` stays subject-agnostic per ADAP-07. **No `switch ($sEventName)` inside the builder.** Each adapter owns its event-shape through the existing four surfaces already locked by D-21 + D-22:

1. **`ValueResolver::resolveContents()` / `resolveContentIds()` / `resolveValue()` / `resolveCurrency()` / `resolveNumItems()`** — event-agnostic at the resolver interface; the adapter chooses what these mean for its subject + event combo.
2. **`EventSubjectAdapter::getUserData()`** — raw fields slot.
3. **`EventSubjectAdapter::getSupportedEvents(): array<string, list<string>>`** — declarative contract for which events the adapter supports + which channels each event uses (`['Purchase' => ['capi', 'pixel'], 'ViewContent' => ['pixel']]`).
4. **`$arEventExtras` (7th arg to `buildEventPayload`)** — caller-supplied per-event extras the adapter cannot precompute (ThemeActionAdapter passes `action_key` / `synthetic_id`; ShopaholicOrderAdapter passes `[]` for Purchase because everything lives in the ValueResolver).

### Phase 3 implications

- **ShopaholicOrderAdapter (Phase 3 SHOP-01):** `Purchase` event — `ShopaholicOrderValueResolver::resolveContents` returns the SKU-formatted contents; ValueResolver also handles currency + value. `$arEventExtras = []` for Purchase. Future Shopaholic events (`AddToCart`, `InitiateCheckout`) — same shape, different ValueResolver method calls inside the watcher.
- **ThemeActionAdapter (Phase 3 THEM-01..07):** `ViewContent`, `PageView`, `Lead`, etc. — `ThemeActionEvent` value-object carries operator-supplied fields. `ThemeActionAdapter::getValueResolver()` returns a resolver that wraps the operator's pushed payload. `$arEventExtras = ['action_key' => 'product-view:42', 'synthetic_id' => 1234567]` if needed for tracing.

### Why Option B beats Option A

| Concern | Option A (switch in PayloadBuilder) | Option B (adapter-supplied — RECOMMENDED) |
|---|---|---|
| SRP | Violated — builder knows every event shape | Honored — builder shapes envelope; adapter shapes content |
| Adding a new adapter event | Edit PayloadBuilder + adapter | Edit adapter only |
| "No over-engineering" rule (CLAUDE.md) | Worse — pre-builds slots for events that may never ship | Better — only what's needed per adapter |
| Testability | Hard — builder tests grow with every event | Each adapter's value-resolver gets its own focused test |
| Third-party extensibility | Third party must fork core to add `case 'CustomEvent':` | Third party ships their own adapter — zero core change |

### Reference to v1.x evidence (read-only inspection — no port)

The v1.x PayloadBuilder hard-coded `EVENT_NAME_PURCHASE` constant and embedded all currency-fallback logic inside `buildPurchaseEventPayload(Order, ...)`. That is exactly the shape v2.0 fixes — v1.x had one event so the switch was implicit; v2.0 has N events so the switch would explode. Option B prevents that explosion.

`[VERIFIED: read-only inspection of legacy/v1.1.1:classes/meta/PayloadBuilder.php — v1.x signature `buildPurchaseEventPayload(Order $obOrder, string $sEventId, int $iEventTime)` is the anti-pattern v2.0 replaces. Do not port; re-derive.]`

### Concrete PayloadBuilder shape (Phase 2)

```php
final class PayloadBuilder
{
    public const META_GRAPH_API_VERSION = 'v23.0';

    private const ACTION_SOURCE = 'website';

    public function __construct(private readonly UserDataHasher $obHasher) {}

    /**
     * Build the Graph API envelope for any event + subject + resolver triplet.
     *
     * @param  array<string, mixed>  $arEventExtras  per-event extras the resolver cannot precompute
     * @return array<string, mixed>
     */
    public function buildEventPayload(
        string $sEventName,
        EventSubjectAdapter $obAdapter,
        object $obSubject,
        ValueResolver $obResolver,
        string $sEventId,
        int $iEventTime,
        array $arEventExtras,
    ): array {
        $arUserData = $this->obHasher->forSubject($obAdapter, $obSubject);

        $arCustomData = [
            'currency' => $obResolver->resolveCurrency($obSubject),
            'value' => $obResolver->resolveValue($obSubject),
            'num_items' => $obResolver->resolveNumItems($obSubject),
            'contents' => $obResolver->resolveContents($obSubject),
            'content_ids' => $obResolver->resolveContentIds($obSubject),
            'content_type' => 'product',
        ];

        if ($arEventExtras !== []) {
            $arCustomData = array_merge($arCustomData, $arEventExtras);
        }

        return ['data' => [[
            'event_id' => $sEventId,
            'event_time' => $iEventTime,
            'event_name' => $sEventName,
            'action_source' => self::ACTION_SOURCE,
            'user_data' => $arUserData,
            'custom_data' => $arCustomData,
        ]]];
    }
}
```

≤ 50 LOC; one method; no switch; no Order type. SRP honored.

---

## 4. Backbone class shapes

Each subsection lists: target file path, method signatures, dependencies, ≤50-LOC smell-test verdict. All examples use Hungarian notation. No `assert()`. No `declare(strict_types=1)`. No PHP 8.4-only syntax.

### 4.1 `Logingrupa\Metapixel\Classes\Adapter\EventSubjectAdapter` (interface)

**Path:** `classes/Adapter/EventSubjectAdapter.php`
**Smell-test:** ~25 LOC — well under 50.
**Deps:** none (defines the contract everything else depends on)

```php
namespace Logingrupa\Metapixel\Classes\Adapter;

interface EventSubjectAdapter
{
    /** Opaque alias, NEVER class FQN. e.g. 'shopaholic.order', 'theme.action'. (P-05 anchor.) */
    public function getSubjectType(object $obSubject): string;

    public function getSubjectId(object $obSubject): int;

    /** MUST read from subject. Never request context. PHPStan-banned to enforce. (P-01 anchor.) */
    public function getSiteId(object $obSubject): ?int;

    public function getSecretKey(object $obSubject): ?string;

    public function getValueResolver(object $obSubject): ValueResolver;

    /**
     * Raw (unhashed) user_data fields. Keys MUST be one of:
     * em, ph, fn, ln, ct, st, zp, country, external_id, fbp, fbc,
     * client_ip_address, client_user_agent. Missing keys = null.
     *
     * @return array<string, ?string>
     */
    public function getUserData(object $obSubject): array;

    /** @return array<string, list<string>>  e.g. ['Purchase' => ['capi', 'pixel']] */
    public function getSupportedEvents(): array;
}
```

### 4.2 `Logingrupa\Metapixel\Classes\Adapter\ValueResolver` (interface)

**Path:** `classes/Adapter/ValueResolver.php`
**Smell-test:** ~20 LOC.
**Deps:** none.

```php
namespace Logingrupa\Metapixel\Classes\Adapter;

interface ValueResolver
{
    /** @return list<string>  e.g. ['SKU-42', 'SKU-42-7'] */
    public function resolveContentIds(object $obSubject): array;

    public function resolveValue(object $obSubject): float;

    public function resolveCurrency(object $obSubject): string;

    /** @return list<array{id: string, quantity: int, item_price: float}> */
    public function resolveContents(object $obSubject): array;

    public function resolveNumItems(object $obSubject): int;
}
```

### 4.3 `Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry`

**Path:** `classes/Adapter/AdapterRegistry.php`
**Smell-test:** ~50 LOC — at the limit; split helpers if it grows.
**Deps:** `App` facade (lazy resolve), `EventSubjectAdapter` interface (instanceof check), `InvalidArgumentException`.

```php
namespace Logingrupa\Metapixel\Classes\Adapter;

use App;
use InvalidArgumentException;

/**
 * Service-container singleton. Bound via Plugin::register():
 *     $this->app->singleton(AdapterRegistry::class);
 *
 * Tests swap a fresh instance per test via:
 *     $this->app->instance(AdapterRegistry::class, new AdapterRegistry);
 */
final class AdapterRegistry
{
    /** @var array<class-string, class-string<EventSubjectAdapter>> */
    private array $arAdapterMap = [];

    /**
     * Idempotent — re-registering the same pair is a no-op. Order-agnostic.
     *
     * @throws InvalidArgumentException when $sAdapterClass does not implement EventSubjectAdapter
     */
    public function register(string $sSubjectClass, string $sAdapterClass): void
    {
        if (! is_subclass_of($sAdapterClass, EventSubjectAdapter::class)) {
            throw new InvalidArgumentException(
                "Adapter {$sAdapterClass} must implement ".EventSubjectAdapter::class,
            );
        }
        $this->arAdapterMap[$sSubjectClass] = $sAdapterClass;
    }

    /** @return list<class-string<EventSubjectAdapter>> */
    public function all(): array
    {
        return array_values($this->arAdapterMap);
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

    /** Used by SendCapiEvent::handle to rehydrate after queue serialization. */
    public function resolveByClass(string $sAdapterClass): EventSubjectAdapter
    {
        return App::make($sAdapterClass);
    }
}
```

`[VERIFIED: is_a() resolves interfaces + classes via PHP runtime test; PHP 8.3-compatible]`
`[VERIFIED: October Singleton trait pattern at vendor/october/rain/src/Support/Traits/Singleton.php:10-25 — we use App::singleton instead, NOT this trait, because container-bound is test-swappable]`

### 4.4 `Logingrupa\Metapixel\Classes\Meta\MetaClient`

**Path:** `classes/Meta/MetaClient.php`
**Smell-test:** ~120-150 LOC. At the upper bound; helpers `classifyException()` + `makeTransientException()` get split out.
**Deps:** GuzzleHttp `Client` + `ClientInterface` + exception classes from `classes/Exception/`.

```php
final class MetaClient
{
    public const META_GRAPH_API_VERSION = 'v23.0';

    private const DEFAULT_TIMEOUT_SECONDS = 5;

    /** @var list<int> */
    private const TRANSIENT_STATUS_CODES = [408, 429, 500, 502, 503, 504];

    public function __construct(private readonly ?ClientInterface $obClient = null) {}

    /**
     * Per-call credentials. Caller (SendCapiEvent::handle) resolves $sPixelId + $sToken
     * via Settings::lookupForSite($iSiteId) so multi-pixel routing works at queue time.
     *
     * @param  array<string, mixed>  $arPayload  envelope with key "data" => list of event records
     * @return array<string, mixed>
     *
     * @throws MetaApiTransientException on 408/429/5xx + ConnectException
     * @throws MetaApiPermanentException on any other HTTP error
     */
    public function sendForPixel(string $sPixelId, string $sToken, array $arPayload): array { ... }
}
```

Per ADAP-09: no singleton Settings read inside MetaClient — credentials enter via method args. Graph API version pinned via constant.

### 4.5 `Logingrupa\Metapixel\Classes\Meta\PayloadBuilder`

**Path:** `classes/Meta/PayloadBuilder.php`
**Smell-test:** ~50 LOC (see §3 above).
**Deps:** `UserDataHasher`, `EventSubjectAdapter`, `ValueResolver` interfaces.

Shape: see §3. One public method `buildEventPayload`. No subject-typed args. No event-shape switching.

### 4.6 `Logingrupa\Metapixel\Classes\Meta\UserDataHasher`

**Path:** `classes/Meta/UserDataHasher.php`
**Smell-test:** ~60 LOC.
**Deps:** `EventSubjectAdapter` interface, `CCache` facade (`Lovata\Toolbox` Cache wrapper) for per-request memo.

```php
final class UserDataHasher
{
    /**
     * Hash adapter-supplied raw fields per Meta CAPI spec (sha256 lowercase).
     * Pass-through for fields Meta does NOT pre-hash: fbp, fbc, client_ip_address,
     * client_user_agent. Per-request memo via CCache tag.
     *
     * @return array<string, ?string>
     */
    public function forSubject(EventSubjectAdapter $obAdapter, object $obSubject): array { ... }
}
```

### 4.7 `Logingrupa\Metapixel\Classes\Queue\SendCapiEvent`

**Path:** `classes/Queue/SendCapiEvent.php`
**Smell-test:** ~140 LOC — the orchestrator, by far the largest class. Helper methods `fireBeforeDispatchHalt`, `fireAfterDispatch`, `fireDeadLetter`, `writeFailedEvent` split out. No single method > 40 LOC.
**Deps:** AdapterRegistry, MetaClient, EventLogWriter, SiteResolver, Settings (lookupForSite), FailedEvent, exception classes, Laravel Queueable + Dispatchable + InteractsWithQueue + SerializesModels.

```php
final class SendCapiEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int retry attempts */
    public int $tries = 3;

    /** @var list<int> backoff seconds */
    public array $backoff = [1, 4, 16];

    public function __construct(
        public readonly string $sEventName,
        public readonly array $arPayload,
        public readonly object $obSubject,
        public readonly string $sAdapterClass,
    ) {}

    public function handle(AdapterRegistry $obRegistry, MetaClient $obClient): void
    {
        try {
            $obAdapter = $obRegistry->resolveByClass($this->sAdapterClass);
        } catch (BindingResolutionException $obException) {
            $this->writeFailedEvent($obException, null);
            Log::critical('metapixel: adapter rehydrate failed — dead-lettered', [...]);
            return;
        }

        if ($this->fireBeforeDispatchHalt()) return;

        $iSiteId = SiteResolver::forSubject($this->obSubject, $obAdapter);

        $bWonRaceFence = EventLogWriter::record(
            $this->arPayload['data'][0]['event_id'],
            $this->sEventName,
            'capi',
            $this->obSubject,
            $obAdapter->getSecretKey($this->obSubject),
            (int) $this->arPayload['data'][0]['event_time'],
            $iSiteId,
        );
        if (! $bWonRaceFence) return;

        $arCreds = Settings::lookupForSite($iSiteId);

        try {
            $arResponse = $obClient->sendForPixel($arCreds['pixel_id'], $arCreds['capi_access_token'], $this->arPayload);
        } catch (MetaApiTransientException $obException) {
            throw $obException; // Laravel retry
        } catch (MetaApiPermanentException|MissingPixelConfigException|MissingCapiTokenException $obException) {
            $this->writeFailedEvent($obException, $obException instanceof MetaApiPermanentException ? $obException->getHttpStatus() : null);
            $this->fireDeadLetter($obException);
            return;
        }

        $this->fireAfterDispatch($arResponse);
    }

    public function failed(\Throwable $obException): void { /* retry exhaustion → FailedEvent + dead_letter */ }
}
```

Listener-isolation wrappers per OQ-2 resolution. `writeFailedEvent` is silent-catch (DB-write failure cannot cascade). `fireDeadLetter` carries `Throwable`, not exception detail beyond class+message — operator-controllable.

### 4.8 `Logingrupa\Metapixel\Classes\Helper\EventLogWriter`

**Path:** `classes/Helper/EventLogWriter.php`
**Smell-test:** ~80 LOC.
**Deps:** `EventLog` model, `DB` facade, `Log` facade.

```php
final class EventLogWriter
{
    public static function record(
        string $sEventId,
        string $sEventName,
        string $sChannel,        // 'capi' | 'pixel'
        object $obSubject,
        ?string $sSecretKey,
        int $iEventTime,
        ?int $iSiteId,
    ): bool {
        try {
            $obAdapter = AdapterRegistry::instance()->resolveFor($obSubject);
            if ($obAdapter === null) {
                Log::warning('metapixel: EventLogWriter — no adapter registered for subject', [...]);
                return false;
            }

            $sSubjectType = $obAdapter->getSubjectType($obSubject);  // opaque alias, NOT class FQN (P-05)
            $iSubjectId = $obAdapter->getSubjectId($obSubject);
            if ($iSubjectId <= 0) {
                Log::warning('metapixel: EventLogWriter rejected invalid subject id', [...]);
                return false;
            }

            $sNow = (string) Carbon::now();
            $iAffected = DB::table('logingrupa_metapixel_event_log')->insertOrIgnore([
                'event_id' => $sEventId,
                'event_name' => $sEventName,
                'channel' => $sChannel,
                'subject_type' => $sSubjectType,
                'subject_id' => $iSubjectId,
                'secret_key' => $sSecretKey,
                'site_id' => $iSiteId,
                'event_time' => $iEventTime,
                'fired_at' => $sNow,
                'created_at' => $sNow,
                'updated_at' => $sNow,
            ]);

            return $iAffected === 1;
        } catch (\Throwable $obException) {
            Log::critical('metapixel: EventLogWriter::record DB write FAILED', [...]);
            return false;  // fail-safe: peer assumed to have won → no double-fire
        }
    }
}
```

`[VERIFIED: vendor/laravel/framework/src/Illuminate/Database/Query/Builder.php:4132 — insertOrIgnore returns int<0,max> affected count; 1 = race winner, 0 = UNIQUE collision]`

KEY DIFFERENCE FROM v1.x: writer now consults `AdapterRegistry::resolveFor()` to get the opaque alias. v1.x wrote `get_class($obSubject)` directly — the P-05 anti-pattern.

### 4.9 `Logingrupa\Metapixel\Classes\Helper\SiteResolver`

**Path:** `classes/Helper/SiteResolver.php`
**Smell-test:** ~25 LOC.
**Deps:** `EventSubjectAdapter` interface.

```php
final class SiteResolver
{
    /**
     * The ONLY authoritative source of site_id. Reads exclusively from subject
     * via the adapter — never from request context, SiteManager, or Auth.
     * P-01 enforcer.
     */
    public static function forSubject(object $obSubject, EventSubjectAdapter $obAdapter): ?int
    {
        return $obAdapter->getSiteId($obSubject);
    }
}
```

That's it. Tiger-Style minimal. ThemeActionAdapter (Phase 3) is the only place where a fallback to `Site::getCurrent()` is allowed — and that fallback lives inside the adapter, not here.

### 4.10 `Logingrupa\Metapixel\Classes\Helper\PluginGuard`

**Path:** `classes/Helper/PluginGuard.php`
**Smell-test:** ~40 LOC.
**Deps:** `Settings`, `Log` facade.

```php
final class PluginGuard
{
    private static ?bool $bIsDisabled = null;

    public static function isDisabled(): bool
    {
        if (self::$bIsDisabled !== null) return self::$bIsDisabled;

        $sPixelId = (string) Settings::get('pixel_id', '');
        if ($sPixelId === '') {
            Log::warning('metapixel: pixel_id is empty — plugin running in disabled mode (events suppressed)');
            return self::$bIsDisabled = true;
        }
        return self::$bIsDisabled = false;
    }

    public static function reset(): void { self::$bIsDisabled = null; }  // tests
}
```

Per locked decision: empty `pixel_id` → `Log::warning` + disabled flag, NEVER throw at boot. Carry-forward.

### 4.11 `Logingrupa\Metapixel\Models\Settings`

**Path:** `models/Settings.php`
**Smell-test:** ~35 LOC.
**Deps:** `Lovata\Toolbox\Models\CommonSettings` (parent).

Phase 2 single-row Settings — the Multisite field-whitelist is deferred to Phase 4 (MULT-01..02), but the trait itself comes from CommonSettings.

```php
namespace Logingrupa\Metapixel\Models;

use Lovata\Toolbox\Models\CommonSettings;

class Settings extends CommonSettings
{
    public $settingsCode = 'logingrupa_metapixel_settings';
    public $settingsFields = 'fields.yaml';

    /** Phase 2: no per-field Multisite whitelist. Phase 4 MULT-02 adds pixel_id + capi_access_token. */
    protected $propagatable = [];

    /**
     * Multisite-aware credential lookup. Phase 2 stub: ignores $iSiteId, returns default row.
     * Phase 4 (MULT-03) re-implements to honor Multisite trait + per-site rows.
     *
     * @return array{pixel_id: string, capi_access_token: string}
     */
    public static function lookupForSite(?int $iSiteId): array
    {
        return [
            'pixel_id' => (string) self::get('pixel_id', ''),
            'capi_access_token' => (string) self::get('capi_access_token', ''),
        ];
    }
}
```

`[VERIFIED: plugins/lovata/toolbox/models/CommonSettings.php — CommonSettings already `use Multisite` + has `protected $propagatable = []`. No extra trait import needed.]`

### 4.12 `Logingrupa\Metapixel\Models\EventLog`

**Path:** `models/EventLog.php`
**Smell-test:** ~50 LOC. Plain `October\Rain\Database\Model`. Append-only — no business mutation methods. No MorphTo (P-05 — subject_type is opaque alias, not class FQN, so MorphTo would be wrong).
**Deps:** `October\Rain\Database\Model`.

```php
class EventLog extends Model
{
    public $table = 'logingrupa_metapixel_event_log';

    public const CHANNEL_CAPI = 'capi';
    public const CHANNEL_PIXEL = 'pixel';

    protected $fillable = [
        'event_id', 'event_name', 'channel', 'subject_type', 'subject_id',
        'secret_key', 'site_id', 'event_time', 'fired_at',
    ];

    protected $casts = [
        'subject_id' => 'int',
        'site_id' => 'int',
        'event_time' => 'int',
    ];

    // Optional: lookup by alias. No subject() MorphTo — alias not resolvable to class.
    public function scopeForSubject($obQuery, string $sSubjectType, int $iSubjectId)
    {
        return $obQuery->where('subject_type', $sSubjectType)->where('subject_id', $iSubjectId);
    }
}
```

### 4.13 `Logingrupa\Metapixel\Models\FailedEvent`

**Path:** `models/FailedEvent.php`
**Smell-test:** ~40 LOC. Plain Model. Admin UI lives Phase 4.

```php
class FailedEvent extends Model
{
    public $table = 'logingrupa_metapixel_failed_events';

    protected $fillable = [
        'event_id', 'event_name', 'adapter_type', 'subject_type', 'subject_id',
        'payload', 'http_status', 'graph_error', 'attempts',
    ];

    protected $casts = [
        'payload' => 'array',
        'attempts' => 'int',
        'http_status' => 'int',
    ];
}
```

### 4.14 Two migrations

#### `updates/<timestamp>_create_metapixel_event_log_table.php`

```php
class CreateMetapixelEventLogTable extends Migration
{
    const TABLE = 'logingrupa_metapixel_event_log';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) return;

        Schema::create(self::TABLE, function (Blueprint $obTable): void {
            $obTable->engine = 'InnoDB';
            $obTable->bigIncrements('id');
            $obTable->string('event_id', 36);
            $obTable->string('event_name', 64);
            $obTable->string('channel', 16);
            $obTable->string('subject_type', 255);     // opaque alias e.g. 'shopaholic.order' (P-05)
            $obTable->unsignedInteger('subject_id');
            $obTable->string('secret_key', 64)->nullable();
            $obTable->unsignedInteger('site_id')->nullable();
            $obTable->unsignedBigInteger('event_time');
            $obTable->timestamp('fired_at');
            $obTable->timestamps();

            $obTable->unique(
                ['subject_type', 'subject_id', 'event_name', 'channel', 'site_id'],
                'metapixel_event_log_subject_event_channel_unique',
            );
            $obTable->index('event_id', 'metapixel_event_log_event_id_index');
            $obTable->index(
                ['secret_key', 'event_name', 'channel', 'site_id'],
                'metapixel_event_log_secret_key_index',
            );
            $obTable->index(
                ['subject_type', 'subject_id', 'site_id'],
                'metapixel_event_log_subject_index',
            );
        });
    }

    public function down(): void { Schema::dropIfExists(self::TABLE); }
}
```

Race-fence semantics: `insertOrIgnore` returns affected rows. On MySQL+InnoDB, UNIQUE treats NULLs as distinct, so single-site (`site_id=NULL`) rows coexist with multi-site rows. On SQLite (test env), NULL is also treated as distinct under the UNIQUE constraint. The fence works identically in both environments.

`[VERIFIED: vendor/laravel/framework/src/Illuminate/Database/Query/Builder.php:4132 — Laravel's insertOrIgnore returns int affected count]`

#### `updates/<timestamp>_create_metapixel_failed_events_table.php`

```php
class CreateMetapixelFailedEventsTable extends Migration
{
    const TABLE = 'logingrupa_metapixel_failed_events';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) return;

        Schema::create(self::TABLE, function (Blueprint $obTable): void {
            $obTable->engine = 'InnoDB';
            $obTable->increments('id');
            $obTable->string('event_id', 36)->index();
            $obTable->string('event_name', 64)->index();
            $obTable->string('adapter_type', 255)->nullable()->index();
            $obTable->string('subject_type', 255)->nullable();   // opaque alias
            $obTable->unsignedInteger('subject_id')->nullable();
            $obTable->longText('payload');
            $obTable->text('graph_error')->nullable();
            $obTable->smallInteger('http_status')->unsigned()->nullable()->index();
            $obTable->unsignedInteger('attempts')->default(0);
            $obTable->timestamps();

            // Phase 2 lock — same defense-in-depth UNIQUE as v1.x.
            $obTable->unique(['event_id', 'http_status'], 'metapixel_failed_events_event_status_unique');
        });
    }

    public function down(): void { Schema::dropIfExists(self::TABLE); }
}
```

NEW vs v1.x: `adapter_type`, `subject_type`, `subject_id` columns added per ADAP-10 — admin UI Phase 4 filters on `adapter_type`; replay path needs `subject_type`/`subject_id` to re-resolve via AdapterRegistry.

### 4.15 `Plugin.php` (Phase 2 boot wire-up)

**Path:** `Plugin.php`
**Smell-test:** ~30 LOC — boot/register intentionally minimal. No conditional adapter registration (Phase 3 adds that). No middleware push (Phase 4 adds that).

```php
namespace Logingrupa\Metapixel;

use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use System\Classes\PluginBase;

class Plugin extends PluginBase
{
    public $require = ['Lovata.Toolbox'];

    public function pluginDetails(): array
    {
        return [
            'name' => 'logingrupa.metapixel::lang.plugin.name',
            'description' => 'logingrupa.metapixel::lang.plugin.description',
            'author' => 'Logingrupa',
            'icon' => 'icon-bullseye',
            'homepage' => 'https://github.com/logingrupa/oc-metapixel-plugin',
        ];
    }

    public function register(): void
    {
        $this->app->singleton(AdapterRegistry::class);
    }

    public function boot(): void
    {
        // Phase 2 boot is intentionally minimal. Phase 3 adds conditional
        // adapter registration here, e.g.:
        //   if (PluginManager::instance()->exists('Lovata.OrdersShopaholic')) {
        //       $this->app->make(AdapterRegistry::class)->register(
        //           \Lovata\OrdersShopaholic\Models\Order::class,
        //           Classes\Adapter\Shopaholic\ShopaholicOrderAdapter::class,
        //       );
        //   }
    }

    public function registerSettings(): array
    {
        return [
            'settings' => [
                'label' => 'logingrupa.metapixel::lang.settings.label',
                'description' => 'logingrupa.metapixel::lang.settings.description',
                'category' => 'logingrupa.metapixel::lang.settings.category',
                'icon' => 'icon-bullseye',
                'class' => \Logingrupa\Metapixel\Models\Settings::class,
                'order' => 500,
            ],
        ];
    }
}
```

### 4.16 `Logingrupa\Metapixel\Classes\Testing\EventSubjectAdapterContractTestCase`

**Path:** `classes/Testing/EventSubjectAdapterContractTestCase.php`
**Smell-test:** ~120 LOC (10 invariants × ~10 LOC each + scaffold). Above 50 but justified — it's the public extension contract for the marketplace. Helpers `assertOpaqueAlias()`, `assertSiteIdDeterministic()` etc. split out as protected helper methods.
**Deps:** `Logingrupa\Metapixel\Tests\MetapixelTestCase` (parent — boots OctoberCMS), `EventSubjectAdapter` interface, `Site` facade (for setSite invariant test).

**Why `classes/Testing/` not `tests/Contract/`:** the base class ships in the plugin's PSR-4 production namespace so third parties can extend it without depending on the plugin's test directory layout. Loaded via PSR-4 autoload (production), gated as test-helper by being abstract + the third-party only requiring the plugin's composer `require-dev` chain (which pulls Pest 4 + PHPUnit 12).

```php
namespace Logingrupa\Metapixel\Classes\Testing;

use Logingrupa\Metapixel\Classes\Adapter\EventSubjectAdapter;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Site;  // October\Cms\Facades\Site or similar

/**
 * Contract test base for any EventSubjectAdapter implementation. Third parties
 * extend this in their adapter test and override makeAdapter() + makeSubject()
 * to verify their adapter honors the Phase 2 invariants:
 *
 *   1. getSubjectType() returns a non-empty string without backslashes (P-05).
 *   2. getSubjectId() returns positive int.
 *   3. getSiteId() is deterministic regardless of Site::setSite() context (P-01).
 *   4. getSiteId() does NOT read from Request / SiteManager / Auth (PHPStan ban + smoke test).
 *   5. getSecretKey() returns string or null — never throws.
 *   6. getValueResolver() returns a ValueResolver instance (instanceof check).
 *   7. getUserData() returns array<string, ?string> with only the documented keys.
 *   8. getSupportedEvents() returns array<string, list<string>> with each value
 *      a subset of {'capi', 'pixel'}.
 *   9. Registry walk: registering the adapter via AdapterRegistry::register and
 *      then resolveFor($obSubject) returns the same instance class.
 *  10. Round-trip: PayloadBuilder::buildEventPayload with this adapter produces
 *      a valid Graph API envelope shape (has event_id, event_time, event_name,
 *      action_source, user_data, custom_data).
 */
abstract class EventSubjectAdapterContractTestCase extends MetapixelTestCase
{
    abstract protected function makeAdapter(): EventSubjectAdapter;
    abstract protected function makeSubject(): object;

    public function test_invariant_01_subject_type_alias_format(): void { ... }
    public function test_invariant_02_subject_id_positive(): void { ... }
    public function test_invariant_03_site_id_deterministic_across_setSite(): void { ... }
    // ... etc
}
```

Phase 3 ShopaholicOrderAdapterContractTest extends this. Phase 2 ships with FakeAdapter ContractTest that demonstrates the base passes against the test double.

`[CITED: pestphp.com/docs/configuring-tests — Pest abstract base test classes are standard via `pest()->extend()` + `uses()`; third parties `pest()->extend(EventSubjectAdapterContractTestCase::class)->in('Contract/Adapter/MyVendor')` in their own Pest.php]`

---

## 5. Tooling deltas

### 5.1 `phpstan.neon` — add disallowed-calls scopes

Current `phpstan.neon` only scans `Plugin.php`. Phase 2 expands paths to `classes/`, `models/` (and lengthens disallowedFunctionCalls). The exact additions:

```neon
parameters:
    paths:
        - Plugin.php
        - classes
        - models

    disallowedFunctionCalls:
        # Phase 1 entries kept verbatim — assert(), @, array_find/any/all/find_key.
        # Phase 2 additions:
        -
            function: 'request()'
            message: 'metapixel: per-event attributes MUST come from the subject via adapter — request() bans P-01'
            disallowIn:
                - 'classes/Queue/*'
                - 'classes/Event/*'
                - 'classes/Adapter/*'

    disallowedMethodCalls:
        -
            method: 'October\Rain\Cms\Site::*'
            message: 'metapixel: site_id MUST come from subject (SiteResolver::forSubject) — P-01 anchor'
            disallowIn:
                - 'classes/Queue/*'
                - 'classes/Event/*'
                - 'classes/Adapter/*'
        -
            method: 'System\Classes\SiteManager::*'
            message: 'metapixel: site_id MUST come from subject — P-01 anchor'
            disallowIn:
                - 'classes/Queue/*'
                - 'classes/Event/*'
                - 'classes/Adapter/*'
        -
            method: 'Illuminate\Http\Request::*'
            message: 'metapixel: per-event attributes from subject only — P-01'
            disallowIn:
                - 'classes/Queue/*'
                - 'classes/Event/*'
                - 'classes/Adapter/*'
```

`[VERIFIED: vendor/spaze/phpstan-disallowed-calls/extension.neon:29 — `disallowIn: listOf(string())` config key accepts file-path glob patterns]`

The exact class FQNs for `SiteManager` and `Site` need a small Phase 2 spike (the OctoberCMS 4.x facade vs underlying class — `Cms\Facades\Site::*` is the facade, `System\Classes\SiteManager::*` is the implementation. Both must be banned in the disallow list — `[ASSUMED]` until spike confirms exact FQNs at plan time).

### 5.2 `composer-dependency-analyser.php` — forward-rule for Phase 3 Lovata-import boundary

Phase 1 already configured this (the rule fires on `classes/adapter/shopaholic`). Phase 2 doesn't need to change the file since the rule lookup is conditional on directory existence. Confirm by re-reading current `composer-dependency-analyser.php:28-43` — the rule exists and is dormant until Phase 3 creates `classes/Adapter/Shopaholic/`.

No edit required in Phase 2. Just note in plan-checker checklist: "Phase 3 lands `classes/Adapter/Shopaholic/`; verify composer-dependency-analyser still green."

### 5.3 `phpunit.xml` — Adapter testsuite block

Current `phpunit.xml:16-19` already declares `Metapixel Adapter Tests` pointing at `tests/Unit/Adapter` and `tests/Feature/Adapter`. Phase 2 adds `tests/Contract/`:

```xml
<testsuite name="Metapixel Adapter Tests">
    <directory>./tests/Unit/Adapter</directory>
    <directory>./tests/Feature/Adapter</directory>
    <directory>./tests/Contract/Adapter</directory>
</testsuite>
```

And update the `<source>` block so the upcoming `classes/` directory is in scope for coverage:

```xml
<source>
    <include>
        <file>./Plugin.php</file>
        <directory>./classes</directory>
        <directory>./models</directory>
    </include>
</source>
```

Coverage gate (≥90%) applies to Run A only per existing `metapixel-qa.yml`.

---

## 6. Test plan

### Directory layout

```
tests/
├── Pest.php                                   # uses() bindings (Phase 1; extend for Contract dir)
├── MetapixelTestCase.php                      # Phase 1 — no edit
├── ShopaholicAdapterTestCase.php              # Phase 1 — no edit; Phase 3 uses it
├── Doubles/
│   ├── FakeAdapter.php                        # Phase 2 (D-08)
│   └── FakeValueResolver.php                  # Phase 2 (D-10)
├── Unit/
│   ├── PluginSanityTest.php                   # Phase 1 — keep
│   ├── Adapter/
│   │   ├── AdapterRegistryTest.php            # T1: register + resolveFor + is_a walk
│   │   ├── AdapterRegistrySingletonBindingTest.php  # T2: $this->app->instance swap
│   │   ├── AdapterRegistryInvalidAdapterTest.php    # T3: InvalidArgumentException on bad class
│   │   ├── AdapterRegistryBootOrderTest.php   # T4: P-02 — register-in-any-order invariant
│   │   └── AdapterRegistryFlushTest.php       # T5: App::forgetInstance + re-bind isolation
│   ├── Helper/
│   │   ├── SiteResolverTest.php               # T6: forSubject delegates to adapter only
│   │   └── PluginGuardTest.php                # T7: empty pixel_id → disabled + warn (not throw)
│   ├── Meta/
│   │   ├── PayloadBuilderTest.php             # T8: envelope shape; subject-agnostic; $arEventExtras merge
│   │   ├── UserDataHasherTest.php             # T9: sha256 + null-pass-through + CCache memo
│   │   └── MetaClientTest.php                 # T10: Guzzle MockHandler — 200/4xx/5xx/timeout classification
│   ├── Hook/
│   │   ├── BeforeDispatchHaltTest.php         # T11: listener returns false → dispatch halts
│   │   ├── BeforeDispatchPayloadMutationTest.php  # T12: payload mutation propagates; event_id unchanged
│   │   ├── ListenerExceptionIsolationTest.php # T13: throwing listener → Log::warning + continue
│   │   └── DeadLetterHookTest.php             # T14: dead_letter fires with Throwable
│   └── ExceptionHierarchyTest.php             # T15: MetaPixelException base + 4-5 subclasses (fresh names)
├── Feature/
│   ├── Adapter/
│   │   ├── ContractTestCaseSmokeTest.php      # T16: FakeAdapter extends ContractTestCase, all 10 invariants pass
│   │   └── EventLogWriterRaceFenceTest.php    # T17: 2 INSERTs same key → only 1 wins; UNIQUE NULL distinct
│   ├── Queue/
│   │   ├── SendCapiEventBindingResolutionTest.php  # T18: adapter rehydrate fails → FailedEvent + Log::critical
│   │   ├── SendCapiEventHaltTest.php          # T19: before_dispatch=false → no EventLog row, no HTTP
│   │   ├── SendCapiEventHappyPathTest.php     # T20: race-win → MetaClient sendForPixel → after_dispatch
│   │   ├── SendCapiEventDeadLetterTest.php    # T21: permanent 4xx → FailedEvent + dead_letter hook
│   │   └── SendCapiEventTransientRetryTest.php  # T22: transient 5xx rethrows for Laravel retry
│   ├── Settings/
│   │   ├── SettingsLookupForSiteTest.php      # T23: Phase 2 stub — returns default row regardless of $iSiteId
│   │   └── SettingsCommonSettingsParentTest.php  # T24: extends CommonSettings; $propagatable = []
│   ├── Models/
│   │   ├── EventLogModelTest.php              # T25: fillable shape; no MorphTo; scope helpers
│   │   └── FailedEventModelTest.php           # T26: payload cast as array; mass-assignment safe
│   └── Migrations/
│       ├── EventLogMigrationTest.php          # T27: up()/down() idempotent; UNIQUE constraint enforced
│       └── FailedEventsMigrationTest.php      # T28: up()/down() idempotent; UNIQUE(event_id, http_status)
└── Contract/
    └── Adapter/
        └── FakeAdapterContractTest.php        # T29: extends EventSubjectAdapterContractTestCase
```

### Per-file counts

| Tier | Files | Test methods | Notes |
|------|-------|--------------|-------|
| Unit/Adapter | 5 | ~18 | registry + invariants + flush |
| Unit/Helper | 2 | ~8 | SiteResolver + PluginGuard |
| Unit/Meta | 3 | ~22 | builder + hasher + client (MockHandler matrix) |
| Unit/Hook | 4 | ~12 | halt + mutation + isolation + dead_letter |
| Unit/ExceptionHierarchy | 1 | ~5 | base + 4 subclasses (NEW v2.0 fresh names) |
| Feature/Adapter | 2 | ~6 | ContractTestCase smoke + race-fence |
| Feature/Queue | 5 | ~15 | full lifecycle matrix |
| Feature/Settings | 2 | ~4 | lookupForSite stub + CommonSettings inheritance |
| Feature/Models | 2 | ~6 | model shape |
| Feature/Migrations | 2 | ~4 | up/down idempotency |
| Contract/Adapter | 1 | 10 | invariants (each ContractTestCase invariant = one test) |
| **TOTAL** | **29 files** | **~110 tests** | Generous estimate; floor 60. |

### `MetapixelTestCase` shape

Already exists at `tests/MetapixelTestCase.php` (Phase 1). No edit required.

### `ShopaholicAdapterTestCase` shape

Already exists at `tests/ShopaholicAdapterTestCase.php` (Phase 1). Phase 2 doesn't extend it (no Shopaholic adapter). Phase 3 will add Shopaholic-specific test methods.

### `FakeAdapter` shape (D-08)

```php
namespace Logingrupa\Metapixel\Tests\Doubles;

use Logingrupa\Metapixel\Classes\Adapter\EventSubjectAdapter;
use Logingrupa\Metapixel\Classes\Adapter\ValueResolver;

final class FakeAdapter implements EventSubjectAdapter
{
    private string $sSubjectType = 'fake.subject';
    private int $iSubjectId = 1;
    private ?int $iSiteId = null;
    private ?string $sSecretKey = null;
    private array $arUserData = ['em' => null, 'ph' => null, 'fn' => null, 'ln' => null,
        'ct' => null, 'st' => null, 'zp' => null, 'country' => null,
        'external_id' => null, 'fbp' => null, 'fbc' => null,
        'client_ip_address' => null, 'client_user_agent' => null];
    private array $arSupportedEvents = ['Purchase' => ['capi', 'pixel']];
    private ?ValueResolver $obValueResolver = null;

    public function withSubjectType(string $sType): self { $this->sSubjectType = $sType; return $this; }
    public function withSubjectId(int $iId): self { $this->iSubjectId = $iId; return $this; }
    public function withSiteId(?int $iSiteId): self { $this->iSiteId = $iSiteId; return $this; }
    public function withSecretKey(?string $sKey): self { $this->sSecretKey = $sKey; return $this; }
    public function withUserData(array $arUserData): self { $this->arUserData = array_merge($this->arUserData, $arUserData); return $this; }
    public function withSupportedEvents(array $arSupported): self { $this->arSupportedEvents = $arSupported; return $this; }
    public function withValueResolver(ValueResolver $obResolver): self { $this->obValueResolver = $obResolver; return $this; }

    public function getSubjectType(object $obSubject): string { return $this->sSubjectType; }
    public function getSubjectId(object $obSubject): int { return $this->iSubjectId; }
    public function getSiteId(object $obSubject): ?int { return $this->iSiteId; }
    public function getSecretKey(object $obSubject): ?string { return $this->sSecretKey; }
    public function getValueResolver(object $obSubject): ValueResolver { return $this->obValueResolver ?? new FakeValueResolver; }
    public function getUserData(object $obSubject): array { return $this->arUserData; }
    public function getSupportedEvents(): array { return $this->arSupportedEvents; }
}
```

### `FakeValueResolver` shape (D-10)

```php
namespace Logingrupa\Metapixel\Tests\Doubles;

use Logingrupa\Metapixel\Classes\Adapter\ValueResolver;

final class FakeValueResolver implements ValueResolver
{
    public function __construct(
        private array $arContentIds = ['SKU-1'],
        private float $fValue = 10.0,
        private string $sCurrency = 'EUR',
        private array $arContents = [['id' => 'SKU-1', 'quantity' => 1, 'item_price' => 10.0]],
        private int $iNumItems = 1,
    ) {}

    public function resolveContentIds(object $obSubject): array { return $this->arContentIds; }
    public function resolveValue(object $obSubject): float { return $this->fValue; }
    public function resolveCurrency(object $obSubject): string { return $this->sCurrency; }
    public function resolveContents(object $obSubject): array { return $this->arContents; }
    public function resolveNumItems(object $obSubject): int { return $this->iNumItems; }
}
```

### `EventSubjectAdapterContractTestCase` shape — 10 invariants

```
Invariant 01 — Subject type is an opaque alias (no backslashes, non-empty, < 64 chars).
Invariant 02 — Subject id is a positive int.
Invariant 03 — getSiteId() returns the same value across two Site::setSite() contexts (cross-context determinism).
Invariant 04 — getSiteId() did not call Request / SiteManager / Auth (smoke check via Mockery::spy on facades).
Invariant 05 — getSecretKey() returns string-or-null; never throws.
Invariant 06 — getValueResolver() returns a ValueResolver instance.
Invariant 07 — getUserData() returns array<string, ?string>; keys are subset of Meta CAPI documented list.
Invariant 08 — getSupportedEvents() returns array<string, list<string>>; channel values are subset of {'capi','pixel'}.
Invariant 09 — Registry round-trip: register + resolveFor returns the same class.
Invariant 10 — PayloadBuilder::buildEventPayload with this adapter produces a valid envelope shape (has all 6 top-level keys per event record).
```

Third-party adapter test:

```php
// vendor/acme/customcart/tests/AcmeCartAdapterContractTest.php
final class AcmeCartAdapterContractTest extends EventSubjectAdapterContractTestCase
{
    protected function makeAdapter(): EventSubjectAdapter
    {
        return new AcmeCartAdapter;
    }
    protected function makeSubject(): object
    {
        return AcmeCartFactory::create(['site_id' => 1]);
    }
}
```

`pest tests/AcmeCartAdapterContractTest.php` exits 0 = the adapter passes the Phase 2 contract.

### Per-test naming convention

- Pest 4 `test()` callable functions allowed but for the base + `extends`-style class hierarchy we use PHPUnit classic class style (matches Phase 1's `PluginSanityTest::test_*` convention seen at `tests/Unit/PluginSanityTest.php:13-38`).
- One test method = one named invariant. snake_case method names start with `test_`.
- Feature tests use process-isolation off (already configured in phpunit.xml).

---

## 7. Pitfall ownership matrix

| Pitfall | Severity | Phase 2 deliverable closing it |
|---|---|---|
| **P-01** Cross-context resolution drift | CRITICAL | `EventSubjectAdapter::getSiteId(object): ?int` interface (§4.1) + `SiteResolver::forSubject` (§4.9) + phpstan disallowed-calls SiteManager/Site/request ban in adapter/queue/event dirs (§5.1) + `ContractTestCase` invariant 03 + 04 (§6) |
| **P-02** Boot-order race / AdapterRegistry not bound | CRITICAL | `AdapterRegistry::register` idempotent + order-agnostic (§4.3) + `Plugin::register()` binds singleton (§4.15) + `tests/Unit/Adapter/AdapterRegistryBootOrderTest.php` (T4) + `AdapterRegistryFlushTest.php` (T5) |
| **P-05** EventLog `subject_type` alias ambiguity | CRITICAL | `EventSubjectAdapter::getSubjectType` returns opaque alias not FQN (§4.1) + `EventLogWriter` calls `$obAdapter->getSubjectType($obSubject)` not `get_class($obSubject)` (§4.8) + migration column comment "opaque alias" (§4.14) + ContractTestCase invariant 01 (§6) + `EventLog` model drops MorphTo (§4.12) |
| **P-08** Event::fire mutable payload | HIGH | Hook PHPDoc contract (§2 — event_id/event_time forbidden mutation) + listener-isolation try/catch in `SendCapiEvent` (§4.7) + `tests/Unit/Hook/BeforeDispatchPayloadMutationTest.php` (T12) + `ListenerExceptionIsolationTest.php` (T13) |
| **P-13** Component::extend unbounded surface | MEDIUM | Convention doc in `Plugin.php` class-level PHPDoc + CLAUDE.md addendum: third parties prefer `Event::fire` over `Component::extend`+`addDynamicMethod`. No new code — convention only. Phase 3 PixelHead Component::extend gets the prefix convention "onMetapixel*". |

P-03 / P-04 / P-06 / P-07 / P-09 / P-10 / P-11 / P-12 / P-14 / P-15 / P-16 / P-17 / P-18 / P-19 / P-20 are owned by other phases (per `.planning/research/PITFALLS.md` phase-mapping table). Phase 2 does not own them — but Phase 2 must not regress them, e.g. don't add a `Lovata\*` import anywhere (Phase 1's composer-dependency-analyser rule catches it).

---

## 8. Plan task seeds (recommend ~7 plans)

Planner should produce these slices. Each is logically grouped and small enough for a single PR / single execute-phase wave.

1. **Plan 02-01 — Interface contracts + AdapterRegistry + registry tests.**
   Touches: `classes/Adapter/EventSubjectAdapter.php`, `classes/Adapter/ValueResolver.php`, `classes/Adapter/AdapterRegistry.php`, `Plugin::register` binding, `tests/Unit/Adapter/*` (T1-T5).
   Closes: ADAP-01, ADAP-02, ADAP-03 + P-02.

2. **Plan 02-02 — Migrations + EventLog + FailedEvent models + Settings (Phase 2 stub) + PluginGuard.**
   Touches: `updates/<ts>_create_metapixel_event_log_table.php`, `updates/<ts>_create_metapixel_failed_events_table.php`, `updates/version.yaml`, `models/EventLog.php`, `models/FailedEvent.php`, `models/Settings.php`, `models/Settings/fields.yaml`, `classes/Helper/PluginGuard.php`, `tests/Feature/Migrations/*` + `Models/*` + `Settings/*` (T23-T28), `tests/Unit/Helper/PluginGuardTest.php` (T7).
   Closes: storage layer + Settings::lookupForSite stub + P-13 (Settings extends CommonSettings precedent).

3. **Plan 02-03 — Exception hierarchy + MetaClient + PayloadBuilder + UserDataHasher.**
   Touches: `classes/Exception/MetaPixelException.php` (base) + 4 subclasses (`MissingPixelConfigException`, `MissingCapiTokenException`, `MetaApiTransientException`, `MetaApiPermanentException`), `classes/Meta/MetaClient.php`, `classes/Meta/PayloadBuilder.php`, `classes/Meta/UserDataHasher.php`, `tests/Unit/Meta/*` (T8-T10), `tests/Unit/ExceptionHierarchyTest.php` (T15).
   Closes: ADAP-07, ADAP-08, ADAP-09.

4. **Plan 02-04 — SiteResolver + EventLogWriter + race-fence feature test.**
   Touches: `classes/Helper/SiteResolver.php`, `classes/Helper/EventLogWriter.php`, `tests/Unit/Helper/SiteResolverTest.php` (T6), `tests/Feature/Adapter/EventLogWriterRaceFenceTest.php` (T17).
   Closes: ADAP-06, P-01 partial (PHPStan rules land in plan 02-07), P-05.

5. **Plan 02-05 — SendCapiEvent queue job + hook firings + queue feature tests.**
   Touches: `classes/Queue/SendCapiEvent.php`, `tests/Unit/Hook/*` (T11-T14), `tests/Feature/Queue/*` (T18-T22).
   Closes: ADAP-04, ADAP-05, ADAP-10, P-08, plus the OQ-2 halt-able resolution lands here.

6. **Plan 02-06 — FakeAdapter test double + FakeValueResolver + EventSubjectAdapterContractTestCase + contract smoke test.**
   Touches: `tests/Doubles/FakeAdapter.php`, `tests/Doubles/FakeValueResolver.php`, `classes/Testing/EventSubjectAdapterContractTestCase.php`, `tests/Contract/Adapter/FakeAdapterContractTest.php` (T29), `tests/Feature/Adapter/ContractTestCaseSmokeTest.php` (T16), `tests/Pest.php` `uses()` binding update.
   Closes: ADAP-11 + D-11..D-13.

7. **Plan 02-07 — Tooling deltas + phpunit.xml Contract dir + plan-checker convention notes.**
   Touches: `phpstan.neon` (disallowed-calls SiteManager/Site/request scopes — §5.1), `phpunit.xml` (Contract dir + source include — §5.3), CLAUDE.md addendum re: Component::extend convention (P-13).
   Closes: P-01 PHPStan enforcement + P-13 convention.

Plans 1, 2, 3 can run in parallel (no shared edits). Plan 4 depends on 1 + 2 (uses AdapterRegistry + EventLog). Plan 5 depends on 1 + 2 + 3 + 4. Plan 6 depends on 1 + 5 (FakeAdapter exercises SendCapiEvent path). Plan 7 can land anytime after 1.

---

## 9. Open risks / unknowns

- **`Site` facade FQN under OctoberCMS 4.x for disallowed-calls config.** I assumed `Cms\Facades\Site` and `System\Classes\SiteManager` but did not grep the exact namespace path in `/home/forge/nailscosmetics.lv/vendor/october/` for v4. **Mitigation:** Plan 02-07 starts with a 10-minute spike — `grep -rn "class SiteManager\|class Site" vendor/october/`. Update phpstan.neon with verified FQN. `[ASSUMED]`
- **`CCache` for per-request memo in `UserDataHasher`.** Phase 1 already pulls `lovata/toolbox-plugin ^2.2` which provides `CCache`. I assumed `CCache::clearCache(['meta-pixel-user-hash'])` style invalidation works in test SQLite-in-memory env. **Mitigation:** Plan 02-03 includes a smoke test confirming the memo + reset cycle works in `MetapixelTestCase`'s in-memory cache driver (`cache.default = 'array'`). `[ASSUMED]`
- **Pest 4 `pest()->extend()` vs `uses()` for the ContractTestCase consumer side.** The existing `tests/Pest.php:21-30` uses the `uses(...)` legacy syntax. Pest 4 docs mention `pest()->extend()`. Both work; the more-current form is `pest()->extend()`. **Mitigation:** Plan 02-06 picks one and is consistent. No risk to correctness. `[CITED: pestphp.com/docs/configuring-tests]`
- **`is_a()` walk + adapter-priority for sibling-class collision.** Two adapters registering for sibling classes that share an ancestor → foreach order = map-insertion order. Documented in research/ARCHITECTURE.md §12 as a known gotcha. **Mitigation:** Phase 2 documents this in `AdapterRegistry::resolveFor` PHPDoc; explicit priority deferred to v2.1 (no real-world conflict yet). No new code in Phase 2. `[ASSUMED — no test fixture exists yet to trigger collision]`
- **SQLite vs MySQL UNIQUE NULL semantics in race-fence test.** Phase 2 migration test (T27) needs to verify the UNIQUE constraint blocks duplicates on SQLite in-memory AND on MySQL (CI Run A uses SQLite). MySQL treats NULL as distinct under UNIQUE (which is what we want); SQLite also treats NULLs as distinct under UNIQUE BUT some edge cases differ. **Mitigation:** T17 explicitly asserts the SQLite behavior — if a future CI run on MySQL diverges, Phase 4 multi-site test (MULT-05) catches it. `[VERIFIED: behavior consistent in the test environment used by v1.x; carried-forward decision]`
- **Test count target.** REQUIREMENTS.md ADAP-11 specifies "177 v1.x tests regreen". OQ-1 resolves that as ~60-110 fresh tests. CONTEXT.md D-01 explicitly endorses fresh-rewrite. The 177 number was v1.x's count, which includes ~30 Order-typed Shopaholic-coupled tests that Phase 2 (no production adapter) cannot exercise. **Mitigation:** Phase 3 ShopaholicAdapter ports those ~30 tests into adapter-scoped tests (Run A only). Phase 2 hits ~110 tests as estimated; the 90% coverage gate is what enforces sufficiency, not the test count. `[VERIFIED: legacy/v1.1.1 grep — total test_/it_ methods = ~170 (close to claimed 177; minor counting drift)]`

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `Site` facade FQN for OctoberCMS 4.x disallowed-calls is `Cms\Facades\Site` and `SiteManager` is `System\Classes\SiteManager` | §5.1, §9 | phpstan.neon rule lands but doesn't catch the actual class — P-01 weakened until plan-time spike fixes. Low blast radius: contract test invariant 04 still catches via Mockery spy. |
| A2 | `CCache::clearCache` works in `cache.default = 'array'` test env | §4.6, §9 | UserDataHasher memo test (T9) fails on a stale memo; fix is to use plain `static $arCache` instead of `CCache`. Implementation-detail risk only. |
| A3 | `is_a()` collision on sibling-class hierarchy resolves via map-insertion order | §4.3, §9 | Documented gotcha; no Phase 2 production fixture triggers it. Phase 3 Shopaholic + Theme adapters use unrelated subject classes — no conflict. |
| A4 | `tests/Contract/Adapter/` is the right test directory for the contract smoke (matching `Pest.php` `uses()`) | §6 | Trivial config tweak if wrong; no functional risk. |
| A5 | SQLite-in-memory UNIQUE NULL-distinct matches MySQL for race-fence | §4.14, §9 | Phase 4 multi-site MULT-05 cross-context test would catch. Low risk on Phase 2 (single-row Settings + `site_id=null` happy path). |

**Note on `[VERIFIED]` claims:** Every claim citing a `file:line` was confirmed by reading the file in this session. Every `[ASSUMED]` claim above needs a 5-15 minute spike at plan-time. None of them block Phase 2 from starting — all are recovery-trivial if wrong.

---

## Open Questions

All three CONTEXT.md open questions resolved above (OQ-1 in §2 TL;DR — fresh-rewrite ~60-110 tests; OQ-2 in §2 — halt-able on `before_dispatch` only; OQ-3 in §3 — adapter-supplied via existing ValueResolver + `$arEventExtras` slot). No new open questions surfaced during research.

---

## Sources

### Primary (HIGH confidence — direct file inspection in this session)

- `/home/forge/nailscosmetics.lv/plugins/lovata/ordersshopaholic/classes/processor/OrderProcessor.php:83` — Lovata halt-able `Event::fire(..., true)` precedent
- `/home/forge/nailscosmetics.lv/plugins/lovata/ordersshopaholic/classes/helper/AbstractPaymentGateway.php:146,165,188` — Lovata observe-only `Event::fire(...)` precedent
- `/home/forge/nailscosmetics.lv/vendor/laravel/framework/src/Illuminate/Events/Dispatcher.php:270-339` — Laravel `dispatch($event, $payload, $halt)` invokeListeners halt + `=== false` short-circuit semantics
- `/home/forge/nailscosmetics.lv/vendor/laravel/framework/src/Illuminate/Database/Query/Builder.php:4132` — `insertOrIgnore` returns `int<0, max>` affected count; race-fence signal
- `/home/forge/nailscosmetics.lv/vendor/october/rain/src/Support/Traits/Singleton.php:10-25` — October Singleton trait shape (reference pattern; we use App::singleton instead)
- `/home/forge/nailscosmetics.lv/plugins/lovata/toolbox/models/CommonSettings.php` — `use Multisite; protected $propagatable = [];` inheritance chain
- `/home/forge/nailscosmetics.lv/vendor/laravel/framework/src/Illuminate/Support/Str.php:1918-1928` — `Str::uuid()` returns `Ramsey\Uuid\UuidInterface` (UUIDv4)
- `/home/forge/nailscosmetics.lv/vendor/spaze/phpstan-disallowed-calls/extension.neon:27-50` — `allowIn` / `disallowIn` glob path config schema
- Read-only inspection of `legacy/v1.1.1:classes/meta/PayloadBuilder.php`, `legacy/v1.1.1:classes/helper/EventLogWriter.php`, `legacy/v1.1.1:classes/meta/MetaClient.php`, `legacy/v1.1.1:classes/queue/SendCapiEvent.php`, `legacy/v1.1.1:updates/create_metapixel_event_log_table.php`, `legacy/v1.1.1:updates/create_table_failed_events.php`, `legacy/v1.1.1:models/EventLog.php` (used to document the v1.x SHAPE the v2.0 fresh code replaces — NO PORT)
- Phase 1 deliverables (read-only): `phpstan.neon`, `phpunit.xml`, `composer-dependency-analyser.php`, `Plugin.php`, `tests/MetapixelTestCase.php`, `tests/ShopaholicAdapterTestCase.php`, `tests/Pest.php`, `.github/workflows/metapixel-qa.yml`

### Secondary (HIGH confidence — official docs)

- `pestphp.com/docs/configuring-tests` — Pest abstract TestCase via `pest()->extend()`
- `pestphp.com/docs/custom-helpers` — base class extension pattern

### Tertiary (CITED — project planning docs verified in this session)

- `.planning/CLAUDE.md` (plugin) — locked decisions, build philosophy, Hungarian notation
- `.planning/PROJECT.md` — milestone goal
- `.planning/ROADMAP.md` — Phase 2 success criteria SC1-SC5
- `.planning/REQUIREMENTS.md` — ADAP-01..11 verbatim spec
- `.planning/research/ARCHITECTURE.md` §2-§13 — class shapes + extension points
- `.planning/research/PITFALLS.md` — P-01, P-02, P-05, P-08, P-13 prevention strategies
- `.planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-CONTEXT.md` — D-01..D-22 locked decisions, OQ-1..OQ-3 open questions
- `.planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-DISCUSSION-LOG.md` — Pattern A contract-test-base reference

---

## Metadata

**Confidence breakdown:**
- OQ-1 resolution (test migration): HIGH — concrete v1.x test-method count via `legacy/v1.1.1` ls-tree + grep
- OQ-2 resolution (halt-able semantics): HIGH — Lovata file:line precedent + Laravel dispatcher source
- OQ-3 resolution (event-shape assembly): HIGH — SRP + locked decisions + Phase 3 forward-compat verified
- Backbone class shapes (§4): HIGH — every shape grounded in REQUIREMENTS.md + locked decisions + codebase precedent
- Tooling deltas (§5): HIGH for phpunit.xml + composer-dependency-analyser; MEDIUM for phpstan.neon FQN resolution (A1)
- Test plan (§6): HIGH — directory layout already exists from Phase 1; per-file naming convention proven by `PluginSanityTest.php`
- Pitfall ownership (§7): HIGH — direct mapping from `.planning/research/PITFALLS.md` phase-table

**Research date:** 2026-05-17
**Valid until:** 2026-06-17 (30-day window — backbone interfaces are stable infrastructure; Laravel/October dependencies pinned in composer.lock)
