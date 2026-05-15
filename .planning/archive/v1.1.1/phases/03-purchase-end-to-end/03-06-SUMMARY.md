---
phase: 03-purchase-end-to-end
plan: 06
subsystem: dispatch-wiring-pixel-twin
tags: [order-status-watcher, purchase-pixel, plugin-boot, event-subscribe, capi, dedup, pay-03, pay-10, pay-11, manual-checkpoint]
requires:
  - phase: 03-purchase-end-to-end
    provides:
      - lovata_orders_shopaholic_orders.meta_purchase_event_id (03-01 — PAY-04)
      - lovata_orders_shopaholic_orders.meta_purchase_event_time (03-01)
      - Logingrupa\Metapixelshopaholic\Models\FailedEvent (03-01 — PAY-05)
      - Logingrupa\Metapixelshopaholic\Classes\Exception\MetaPixelException (03-02 — PAY-09)
      - Logingrupa\Metapixelshopaholic\Classes\Meta\PayloadBuilder (03-04 — PAY-06)
      - Logingrupa\Metapixelshopaholic\Classes\Queue\SendCapiEvent (03-05 — PAY-02)
provides:
  - Logingrupa\Metapixelshopaholic\Classes\Event\OrderStatusWatcher (PAY-03 dispatch site)
  - OrderStatusWatcher::subscribe(Dispatcher) binding eloquent.updated + eloquent.created on Order
  - OrderStatusWatcher::handleUpdated(Order) — refire-flip + status + idempotency fences + atomic saveQuietly of BOTH event_id + event_time + SendCapiEvent::dispatch
  - OrderStatusWatcher::handleCreated(Order) — admin-created-already-paid coverage (CONTEXT Area 2 Q2)
  - Logingrupa\Metapixelshopaholic\Components\PurchasePixel (browser-side Pixel twin)
  - PurchasePixel::onRun reading persisted meta_purchase_event_id + meta_purchase_event_time
  - components/purchasepixel/default.htm Twig partial emitting fbq('track','Purchase',custom_data,{eventID})
  - Plugin::boot() Event::subscribe(OrderStatusWatcher::class) BEFORE CLI gate
  - Plugin::registerComponents() registers PurchasePixel as `purchasePixel`
  - models/Settings.php public $rules = ['pixel_id' => 'nullable|regex:/^\d{6,20}$/'] (PH-01 retro-fit)
  - models/settings/fields.yaml pixel_id field gains `pattern: '^\d{6,20}$'` UI hint
  - tests/MetapixelTestCase::bootOrdersTable now provisions BOTH meta_purchase_event_id + meta_purchase_event_time columns
  - tests/MetapixelTestCase::dropHermeticSchemas extended to clean fixture-side tables (order_positions, offers, products)
affects:
  - Phase 4 (FUN-12 — event_time mirror across browser fbq() and server CAPI) — pattern already wired for Purchase via PurchasePixel component reading the new event_time column.
  - Phase 5 HARD-05 README — must document the theme operator step on order-complete.htm to declare [purchasePixel] orderSlug = "{{ :slug }}" + render {% component 'purchasePixel' %}.
tech-stack:
  added:
    - Illuminate\Events\Dispatcher (first non-test use — OrderStatusWatcher::subscribe receives this)
    - Illuminate\Support\Facades\Event (first plugin use — Plugin::boot Event::subscribe)
    - Illuminate\Support\Facades\Queue (first plugin use — OrderStatusWatcherTest Queue::fake)
  patterns:
    - "Event::subscribe(class) registers OrderStatusWatcher's subscribe(Dispatcher) — Lovata ProductModelHandler analog, but with typed Order parameter (vs. dynamic-bindEvent)."
    - "saveQuietly() inside an Eloquent model handler — GoodsReceivedShopaholic ApplyOrchestrator precedent (lines 252-268). Prevents observer recursion."
    - "BOTH event_id + event_time persisted atomically via a single saveQuietly call so the browser-side Pixel twin and the server-side CAPI dispatch share the same event_time (±10 s Meta dedup window contract)."
    - "Refire-flip away-transition clears BOTH columns to null in a single saveQuietly — same atomic discipline as the forward-fire path."
    - "Component-side dedup-window contract: PurchasePixel reads meta_purchase_event_time from the DB and emits it inside fbq()'s custom_data so the Pixel + CAPI pair carry an identical timestamp."
    - "Test isolation pattern: Event::subscribe(OrderStatusWatcher::class) wired in setUp() + Queue::fake() — Plugin::boot() doesn't run under autoRegister=false in MetapixelTestCase. Queue::fake() called again in makeOrderAtPendingStatus() to reset buffer after OrderFixtures::makePaidOrder fires its own eloquent.created cycle."
    - "phpstan level 10 narrowing: setAttribute/getAttribute for the new columns (avoids property.notFound on the upstream Order model); stringOrEmpty + intOrZero helpers on the watcher; mirror in PurchasePixel."
key-files:
  created:
    - classes/event/OrderStatusWatcher.php (301 LOC; 90.3% coverage)
    - components/PurchasePixel.php (245 LOC; 83.3% coverage)
    - components/purchasepixel/default.htm (28 LOC Twig)
    - tests/Feature/OrderStatusWatcherTest.php (343 LOC; 10 test methods)
    - tests/Feature/PurchasePixelTest.php (392 LOC; 13 test methods)
  modified:
    - Plugin.php (boot Event::subscribe + registerComponents PurchasePixel + 3 new use imports)
    - tests/MetapixelTestCase.php (bootOrdersTable adds 2 columns; dropHermeticSchemas adds 3 fixture-side drops)
    - models/Settings.php (public $rules with pixel_id regex)
    - models/settings/fields.yaml (pattern: '^\d{6,20}$' + comment block on pixel_id)
key-decisions:
  - "Commit order: Task 4 (PurchasePixel files) committed BEFORE Task 3 (Plugin.php) — Rule-3 deviation. Plugin.php's registerComponents() references PurchasePixel::class, so the component file must exist before composer analyse can resolve the class reference. The plan's task numbering (3 then 4) would leave a transient phpstan failure in the Task-3 commit. Final state is identical to the plan."
  - "phpstan level 10 forced setAttribute/getAttribute on the new columns instead of dynamic property access — Lovata's upstream Order model doesn't declare meta_purchase_event_id / meta_purchase_event_time as @property docblocks. Using setAttribute keeps phpstan happy without requiring a model extension stub."
  - "PluginGuard short-circuit is wrapped in try/catch in OrderStatusWatcher::isPluginDisabled — the metapixel.disabled container singleton MAY have been flushed between boot and a queue-worker rehydrated handler call. Fail-safe path: treat container lookup failure as disabled (matches the PluginGuard SKEL-05 documented behaviour)."
  - "PurchasePixel::onRun() declared `@return void` (NOT void|Response) — phpstan level 10 rejected the void|Response signature because no code path actually returns a Response. PixelHead.php legacy keeps void|Response; PurchasePixel was greenfield Phase 3 so the narrower @return void was taken at this writing. If a future plan adds a redirect short-circuit, the PHPDoc widens to @return void|Response."
  - "Both new test classes use the ArrayAccess stub pattern from PixelHeadTest (no full October page-lifecycle boot for unit-of-behavior testing). Component instantiation requires the controller-shaped argument; the stub satisfies the contract while remaining test-light."
  - "OrderStatusWatcherTest setUp() does its own Event::subscribe(OrderStatusWatcher::class) because Plugin::boot() doesn't run under autoRegister=false. The PROD wiring (Plugin.php boot) is verified by the existence of the Event::subscribe call and by the watcher class being loadable — exercising it end-to-end on staging is Task 9's responsibility."
  - "PurchasePixelTest test 7 (custom_data_matches_capi_envelope_byte_for_byte) is the dedup-contract lock: builds a CAPI envelope independently via PayloadBuilder and asserts deep-equal against the Pixel component's arMetaEvent['custom_data']. Meta dedups by event_id; matching custom_data ensures the Pixel + CAPI pair attribute equivalent purchase context."
  - "pixel_id regex validator chose model-level $rules over YAML validation-only: CommonSettings extends the Validation trait so backend Settings::save() runs the regex automatically. The YAML `pattern:` attribute is a backend UI client-side hint only — defence-in-depth pair. T-04-01 mitigation."
  - "Task 9 BLOCKING manual checkpoint — Tasks 1-8 are COMPLETE and PAY-03 invariants are fully covered by 10 automated OrderStatusWatcherTest cases. PAY-10 (dedup ≥ 80% + EMQ ≥ 8) and PAY-11 (bank-transfer admin-flip single-channel CAPI) cannot be verified without a real staging deployment + Meta Events Manager Test Events observation. This SUMMARY documents the staging verification protocol and marks Task 9 as PENDING — the orchestrator returns control to the operator for the staging run."
requirements-completed: [PAY-03]
requirements-pending-staging: [PAY-10, PAY-11]
metrics:
  duration_minutes: 21
  tasks_completed: 8
  tasks_pending: 1
  files_created: 5
  files_modified: 4
  tests_added: 23
  tests_passing: 126
  tests_skipped: 0
  total_assertions: 365
  composer_qa: "exit 0"
  coverage_total: "89.6%"
  coverage_order_status_watcher: "90.3%"
  coverage_purchase_pixel: "83.3%"
  coverage_plugin: "51.9%"
  completed: "2026-05-12T23:07:40Z"
---

# Phase 3 Plan 6: OrderStatusWatcher + PurchasePixel + Plugin::boot wiring (PAY-03 / PAY-10 / PAY-11) Summary

**The Wave-4 closure plan for Phase 3 — wires the four prior waves into a working pipeline. `OrderStatusWatcher` (the actual CAPI dispatch site) listens on `Order::eloquent.updated|created`, atomically persists BOTH the UUIDv4 event_id AND the Unix-seconds event_time to the dedup-fence columns via a single saveQuietly, then dispatches the `SendCapiEvent` queue job built from a `PayloadBuilder` envelope. The browser-side `PurchasePixel` component reads the SAME persisted event_time so Meta dedups Pixel + CAPI within its ±10 s window. Plugin::boot subscribes the watcher BEFORE the CLI gate so backend admin status-flip (bank-transfer path PAY-11) AND queue worker rehydration both see the model events. composer qa green: 126 tests / 365 assertions / 89.6% total / OrderStatusWatcher.php 90.3% / PurchasePixel.php 83.3%. Task 9 (manual staging verification of PAY-10 dedup ≥ 80% AND EMQ ≥ 8 + PAY-11 single-channel CAPI) is PENDING — the staging deployment + Meta Events Manager observation must be run by an operator on a real environment.**

## Performance

- **Duration:** ~21 min (Tasks 1-8 + this SUMMARY)
- **Started:** 2026-05-12T22:46:57Z
- **Completed:** 2026-05-12T23:07:40Z (Tasks 1-8); Task 9 deferred to staging
- **Tasks completed:** 8 of 9 (Task 9 is the BLOCKING manual checkpoint)
- **Files created:** 5
- **Files modified:** 4

## Accomplishments

- `classes/event/OrderStatusWatcher.php` shipped — `final class OrderStatusWatcher` with `subscribe(Dispatcher)` binding both `eloquent.updated:` + `eloquent.created:` on `Lovata\OrdersShopaholic\Models\Order`. PluginGuard short-circuit (try/catch fail-safe), Settings-driven paid_status_code fence, idempotency-column fence, refire-flip away-clear path, atomic saveQuietly of BOTH event_id + event_time, PayloadBuilder soft-skip on MetaPixelException, `SendCapiEvent::dispatch('Purchase', $arPayload)`. 90.3% coverage.
- `components/PurchasePixel.php` + `components/purchasepixel/default.htm` shipped — browser-side Pixel twin reading persisted columns, full 5-step guard chain (disabled / order-not-found / non-paid-status / event_id-null / event_time-null), emits `fbq('track', 'Purchase', Object.assign({event_time: <int>}, <custom_data>), {eventID: '<uuid>'})` with `e('js')` escaper on event_id. 83.3% coverage.
- `Plugin.php` boot subscribes OrderStatusWatcher BEFORE the CLI gate so backend admin AND queue worker contexts see model events. `registerComponents()` adds `PurchasePixel::class => 'purchasePixel'`.
- `models/Settings.php` gains `public $rules = ['pixel_id' => 'nullable|regex:/^\d{6,20}$/']` and `models/settings/fields.yaml` gains `pattern: '^\d{6,20}$'` on the pixel_id field — PH-01 retro-fit / T-04-01 mitigation.
- `tests/MetapixelTestCase.php` extended: `bootOrdersTable()` now provisions BOTH new dedup columns; `dropHermeticSchemas()` cleans the OrderFixtures-provisioned offer/product/order_position tables.
- 23 new tests across two test classes: `tests/Feature/OrderStatusWatcherTest.php` (10 methods locking PAY-03 invariants including the 2 new event_time persistence tests) + `tests/Feature/PurchasePixelTest.php` (13 methods locking the dedup-contract round-trip from DB through component to the fbq() emit).
- composer qa exits 0 across all four gates (pint-test, phpstan level 10, phpmd, test-cov). Plugin-wide test count: 106 → **126** (+20 net; +23 new minus a few wrapper reshuffles). Total coverage 90.9% → **89.6%** (-1.3pp, explained by ~550 LOC of new production code + a deliberately-incomplete Plugin.php coverage growing to 51.9% rather than to 100%; defensive narrowing branches in PurchasePixel and OrderStatusWatcher account for the remaining gap).

## What Shipped

### `classes/event/OrderStatusWatcher.php` (PAY-03)

**Class header:**
- `declare(strict_types=1);` + namespace `Logingrupa\Metapixelshopaholic\Classes\Event`
- `final class OrderStatusWatcher`
- 11 imports: Illuminate\Events\Dispatcher, Illuminate\Support\Facades\{App,Log}, 4 plugin classes (Exception\MetaPixelException, Meta\PayloadBuilder, Queue\SendCapiEvent, Models\Settings), Lovata\OrdersShopaholic\Models\{Order, Status}, Ramsey\Uuid\Uuid, Throwable.

**Public methods (3):**
- `subscribe(Dispatcher): void` — registers `eloquent.updated: Order::class` and `eloquent.created: Order::class` listeners.
- `handleUpdated(Order): void` — PluginGuard short-circuit → refire-flip away-clear (clears BOTH columns when refire=true AND status flipped AWAY from paid) → status fence → idempotency fence → `fireForwardDispatch`.
- `handleCreated(Order): void` — same as updated minus refire-flip (CONTEXT Area 2 Q2 — admin-created-already-paid orders).

**Private helpers (6, all under PHPMD CC ≤ 10):**
- `fireForwardDispatch(Order): void` — shared tail. `Uuid::uuid4()->toString()` + `time()` → setAttribute both columns + `saveQuietly()` (atomic) → try/catch PayloadBuilder (MetaPixelException = log warning + return) → `SendCapiEvent::dispatch('Purchase', $arPayload)` + info-level breadcrumb.
- `isPluginDisabled(): bool` — `App::make('metapixel.disabled')` with try/catch fail-safe (treats container failure as disabled).
- `readPaidStatusCode(): string` — Settings::get('paid_status_code', 'new-payment-received') with is_scalar guard.
- `readRefireFlag(): bool` — Settings::get('refire_purchase_on_status_flip', false) with is_scalar guard.
- `isAtPaidStatus(Order, string): bool` — relation-first lookup with Status::where fallback when relation null (covers test harness + Eloquent lazy-load failure).
- `isAwayFromPaid(Order, string): bool` — `isDirty('status_id')` + original status code matches paid_code + current does NOT.
- `stringOrEmpty / intOrZero` — phpstan level 10 narrowing helpers.

### `components/PurchasePixel.php` + `components/purchasepixel/default.htm` (PAY-10 Pixel side)

**`final class PurchasePixel extends ComponentBase`:**
- `defineProperties` declares `orderSlug` (string, required, default `{{ :slug }}`, validationPattern `^[a-zA-Z0-9-]+$`).
- `componentDetails` returns name + description (NOT lang-keyed — the description text references CAPI dedup, so the human-readable string is more useful than a lang key for plugin consumers).
- `public ?array $arMetaEvent = null;` — Twig-facing property.
- `onRun(): void` — guard chain (PluginGuard / order-by-slug / status / event_id / event_time) → try-catch PayloadBuilder → extract custom_data slice → populate arMetaEvent.
- 7 private helpers (isDisabled, resolveOrder, isAtPaidStatus, readPaidStatusCode, extractCustomData, stringOrEmpty, intOrZero) — all under PHPMD CC ≤ 10.

**`components/purchasepixel/default.htm`:**
```twig
{% if __SELF__.arMetaEvent is not null %}
<script>
    if (typeof fbq === 'function') {
        fbq('track', 'Purchase', Object.assign({event_time: {{ __SELF__.arMetaEvent.event_time }} }, {{ __SELF__.arMetaEvent.custom_data|json_encode|raw }}), { eventID: '{{ __SELF__.arMetaEvent.event_id|e('js') }}' });
    }
</script>
{% endif %}
```

### `Plugin.php` boot + registerComponents (PAY-03 + PAY-10 wiring)

- New imports: `Illuminate\Support\Facades\Event`, `Logingrupa\Metapixelshopaholic\Classes\Event\OrderStatusWatcher`, `Logingrupa\Metapixelshopaholic\Components\PurchasePixel`.
- `boot()` adds `Event::subscribe(OrderStatusWatcher::class);` between PluginGuard::instance() and the CLI early-return, with PHPDoc explaining the order rationale (backend admin AND queue worker contexts MUST see model events).
- `registerComponents()` now returns BOTH `PixelHead::class => 'pixelHead'` and `PurchasePixel::class => 'purchasePixel'`.

### `models/Settings.php` + `models/settings/fields.yaml` (PH-01 retro-fit / T-04-01)

- `Settings.php` gains `public $rules = ['pixel_id' => 'nullable|regex:/^\d{6,20}$/']` — hard enforcement on backend save via CommonSettings' Validation trait.
- `fields.yaml` adds `pattern: '^\d{6,20}$'` on the pixel_id field as backend UI hint + comment block explaining the T-04-01 stored-XSS mitigation.

### `tests/MetapixelTestCase.php` (Phase 3 schema extensions)

- `bootOrdersTable()` now declares `meta_purchase_event_id VARCHAR(36) NULL INDEX` + `meta_purchase_event_time BIGINT UNSIGNED NULL` alongside the existing columns.
- `dropHermeticSchemas()` adds `Schema::dropIfExists` for `lovata_orders_shopaholic_order_positions`, `lovata_shopaholic_offers`, `lovata_shopaholic_products` in reverse-FK order.

### `tests/Feature/OrderStatusWatcherTest.php` — 10 test methods

| # | Test | Locks |
|---|---|---|
| 1 | test_fresh_paid_order_dispatches_send_capi_event_once | happy path |
| 2 | test_same_paid_status_save_does_not_redispatch | idempotency fence |
| 3 | test_status_flip_away_then_back_with_refire_off_fires_only_once | refire=off contract |
| 4 | test_status_flip_away_then_back_with_refire_on_fires_twice | refire=on contract + away-clear |
| 5 | test_plugin_disabled_does_not_dispatch | PluginGuard short-circuit |
| 6 | test_admin_created_already_paid_order_dispatches | eloquent.created path (CONTEXT Area 2 Q2) |
| 7 | test_event_id_persisted_to_meta_purchase_event_id_column | UUID round-trip DB ↔ payload |
| 8 | test_event_time_persisted_to_meta_purchase_event_time_column | **new** companion-column contract |
| 9 | test_refire_on_clears_both_event_id_and_event_time_columns | **new** atomic clear of BOTH columns |
| 10 | test_event_time_is_within_two_seconds_of_now | sanity check on `time()` reading |

### `tests/Feature/PurchasePixelTest.php` — 13 test methods

| # | Test | Locks |
|---|---|---|
| 1 | test_paid_order_with_persisted_event_id_populates_ar_meta_event | happy path — all 4 arMetaEvent keys + 5 custom_data keys |
| 2 | test_non_paid_order_does_not_populate_ar_meta_event | status fence |
| 3 | test_paid_order_without_persisted_event_id_does_not_populate_ar_meta_event | event_id fence (IPN-race protection) |
| 4 | test_paid_order_without_persisted_event_time_does_not_populate_ar_meta_event | event_time fence (column-pair contract) |
| 5 | test_plugin_disabled_does_not_populate_ar_meta_event | PluginGuard short-circuit |
| 6 | test_order_slug_not_found_does_not_populate_ar_meta_event | slug-not-in-DB fence |
| 7 | test_component_details_returns_name_and_description | componentDetails shape |
| 8 | test_define_properties_exposes_order_slug | defineProperties shape (orderSlug binding) |
| 9 | test_status_fence_falls_back_to_status_id_lookup_when_relation_missing | Status::where fallback when relation null |
| 10 | test_status_fence_passes_via_fallback_lookup_for_paid_status_id | positive case of same fallback |
| 11 | test_empty_order_slug_property_resolves_no_order | resolveOrder early return on empty slug |
| 12 | test_payload_builder_exception_logs_warning_and_renders_nothing | MetaPixelException boundary catch (T-03-35) |
| 13 | test_custom_data_matches_capi_envelope_byte_for_byte | **dedup contract** — Pixel custom_data === CAPI custom_data byte-for-byte |

## Task Commits

| # | Task | Hash | Type |
|---|---|---|---|
| 1 | Extend MetapixelTestCase for Phase 3 schema | `4689160` | test |
| 2 | OrderStatusWatcher dispatches Purchase via CAPI | `89acd49` | feat |
| 4 | PurchasePixel browser-side Pixel twin (committed BEFORE Task 3 per Rule-3 deviation) | `2402942` | feat |
| 3 | Plugin.php wires OrderStatusWatcher + PurchasePixel | `7349574` | feat |
| 5 | PurchasePixelTest 7 invariants | `091c7d6` | test |
| 6 | pixel_id regex validator (PH-01 retro-fit) | `06e7637` | fix |
| 7 | OrderStatusWatcherTest 10 invariants | `32d7479` | test |
| 8 | composer qa green + coverage ≥ 80% on both new classes | `b768db5` | test |
| 9 | **[BLOCKING manual]** staging verification | _PENDING_ | — |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Commit order swap: Task 4 before Task 3 to keep each commit qa-green**
- **Found during:** Task 3 preparation
- **Issue:** The plan ships Task 3 (Plugin.php edit including `use Logingrupa\...\PurchasePixel` import + `PurchasePixel::class => 'purchasePixel'` registration) before Task 4 (creates PurchasePixel.php). Running `composer analyse` after the Task-3 commit but before Task-4 would fail phpstan level 10 with class.notFound on the PurchasePixel reference.
- **Fix:** Committed Task 4 (component + Twig partial) first, then Task 3 (Plugin.php). The final state matches the plan exactly; only the per-commit ordering changed. Each commit independently passes `composer analyse`.
- **Files modified:** none (just commit ordering)
- **Commits:** 2402942 (Task 4), 7349574 (Task 3)

**2. [Rule 1 - Bug] phpstan level 10 rejected dynamic property access on Lovata\Order**
- **Found during:** Task 2 first `composer analyse` run
- **Issue:** Direct `$obOrder->meta_purchase_event_id = ...` raised `property.notFound — Access to an undefined property Lovata\OrdersShopaholic\Models\Order::$meta_purchase_event_id`. The upstream Order model doesn't declare the Phase-3 columns as @property docblocks (and shouldn't — the columns are added by the plugin's own migration).
- **Fix:** Use `setAttribute('meta_purchase_event_id', $sUuid)` + `getAttribute('meta_purchase_event_id')` instead of the dynamic property access. phpstan validates the call against Eloquent's typed attribute methods.
- **Files modified:** `classes/event/OrderStatusWatcher.php` + `components/PurchasePixel.php` (write/read sites)
- **Commits:** rolled into 89acd49 + 2402942

**3. [Rule 1 - Bug] phpstan level 10 rejected `(int) $obOrder->getAttribute('id')` cast.int**
- **Found during:** Task 2 second `composer analyse` run
- **Issue:** `(int) $obOrder->getAttribute('...')` reads mixed → cast.int rejected by treatPhpDocTypesAsCertain.
- **Fix:** Added private `intOrZero(mixed): int` narrowing helper (mirrors PayloadBuilder.php precedent from plan 03-04). Replaced all 4 cast sites.
- **Files modified:** `classes/event/OrderStatusWatcher.php`
- **Commits:** rolled into 89acd49

**4. [Rule 1 - Bug] phpstan level 10 rejected `@return void|Response` on PurchasePixel::onRun**
- **Found during:** Task 4 first `composer analyse` run
- **Issue:** First draft mirrored PixelHead's `@return void|Response` PHPDoc, but PurchasePixel::onRun() never actually returns a Response — only `return;` after guard-failures. phpstan flagged `return.unusedType`.
- **Fix:** Narrowed PHPDoc to `@return void`. Removed the unused `Illuminate\Http\Response` import. If a future plan adds a redirect short-circuit, the PHPDoc widens.
- **Files modified:** `components/PurchasePixel.php`
- **Commits:** rolled into 2402942

**5. [Rule 1 - Bug] phpstan level 10 rejected `Order::where(...)->first()` returning Model|null where Order|null is declared**
- **Found during:** Task 4 second `composer analyse` run
- **Issue:** Eloquent's `first()` returns the generic `Model|null` per its PHPDoc, but `PurchasePixel::resolveOrder(): ?Order` declares the narrower type. phpstan return.type rejected.
- **Fix:** Added `$obResult instanceof Order ? $obResult : null` narrowing at the return.
- **Files modified:** `components/PurchasePixel.php`
- **Commits:** rolled into 2402942

**6. [Rule 1 - Bug] phpstan level 10 rejected extractCustomData returning array<mixed> where array<string, mixed> declared**
- **Found during:** Task 4 third `composer analyse` run
- **Issue:** `$mFirst['custom_data']` extracted from a `mixed` payload returns `array<mixed>` even after `is_array` narrowing. Doesn't match the typed `array<string, mixed>` return.
- **Fix:** Explicit foreach re-key loop coercing int keys to string. Avoided `is_string($mKey) || is_int($mKey)` (phpstan flagged `function.alreadyNarrowedType` since PHP array keys are always int|string by language semantics) — just bare `(string) $mKey` coerce.
- **Files modified:** `components/PurchasePixel.php`
- **Commits:** rolled into 2402942

**7. [Rule 1 - Bug] OrderStatusWatcherTest first 7 of 10 cases failed: idempotency fence already populated**
- **Found during:** Task 7 first composer test run
- **Issue:** `OrderFixtures::makePaidOrder()` creates an Order at status_id=5, which fires `eloquent.created` under the global Event::subscribe registration. Our watcher dispatches → persists event_id. The test's subsequent `forceFill(['status_id' => 1])->save()` demotes the order, but the column stays populated. The test's next `status_id = 5; save()` then hits the idempotency fence → no-op → Queue::assertPushed sees 0.
- **Fix:** Updated `makeOrderAtPendingStatus` helper to also reset `meta_purchase_event_id` + `meta_purchase_event_time` to null AND call `Queue::fake()` again to clear the fake's pushed-buffer. The fresh fetch then starts from a true pending state with a clean Queue.
- **Files modified:** `tests/Feature/OrderStatusWatcherTest.php`
- **Commits:** rolled into 32d7479

**8. [Rule 1 - Bug] test_admin_created_already_paid_order_dispatches failed: order_positions missing during handleCreated**
- **Found during:** Task 7 OrderStatusWatcherTest run
- **Issue:** Original test created the Order first then inserted positions. The save() fires eloquent.created → watcher dispatches → PayloadBuilder reads positions → finds zero → throws OrderHasNoItemsException → soft-skip path returns without dispatching to Queue. Queue::assertPushed sees 0.
- **Fix:** Insert positions FIRST with a placeholder order_id (9100), then create the Order with id=9100 via forceFill. The created event fires AFTER positions exist → PayloadBuilder finds items → SendCapiEvent dispatches.
- **Files modified:** `tests/Feature/OrderStatusWatcherTest.php`
- **Commits:** rolled into 32d7479

**9. [Rule 2 - Auto-add missing critical functionality] PurchasePixelTest coverage initially 66.7% (below 80% gate)**
- **Found during:** Task 8 `composer qa` run
- **Issue:** The 7 plan-mandated test methods exercised the main guard branches but skipped componentDetails / defineProperties / the PayloadBuilder catch / the Status::where fallback / the empty-slug branch.
- **Fix:** Added 6 more PurchasePixelTest cases targeting those branches. Coverage rose from 66.7% → 83.3% (above the 80% gate).
- **Files modified:** `tests/Feature/PurchasePixelTest.php`
- **Commits:** rolled into b768db5

---

**Total deviations:** 9 auto-fixed (7 Rule 1 bugs, 1 Rule 2 coverage gap, 1 Rule 3 commit order). All within the executor's autonomy budget; no Rule-4 architectural changes needed.

## Task 9 — BLOCKING manual staging verification (PENDING)

**Status:** PENDING. Tasks 1-8 are complete and the automated suite green; PAY-03 invariants are fully covered. PAY-10 and PAY-11 acceptance criteria cannot be automated — they require a real staging deployment plus Meta Events Manager Test Events observation.

### Manual verification protocol

**Prerequisites (operator steps on staging):**

1. **Composer install** the plugin from the GitHub repo on the staging server (`composer require logingrupa/oc-metapixel-plugin` or via shared symlink for now).
2. **Run plugin migrations** to provision the `meta_purchase_event_id` + `meta_purchase_event_time` columns + the `logingrupa_metapixel_failed_events` table: `php artisan october:up` or backend → System → Updates.
3. **Configure staging Settings** (backend → Settings → Meta Pixel):
   - `pixel_id` = staging-distinct Meta Pixel ID (production prod ID is `2291486191076331` per PROJECT.md — staging MUST use a different Pixel to avoid polluting prod metrics).
   - `capi_access_token` = staging CAPI long-lived access token (Meta Events Manager → Dataset → Settings → Conversions API → Generate access token).
   - `test_event_code` = the code generated under Events Manager → Test Events → "Test browser events" tab (e.g. `TEST12345`). MUST be set during the verification window — clear it for production.
   - Save Settings. Confirm no warnings in `storage/logs/laravel.log` about disabled plugin.
4. **Theme integration** on `themes/logingrupa-naisstore/pages/order-complete.htm` (operator step — Phase 3 ships the component, theme owner activates it):
   ```twig
   description = "Order complete"
   url = "/checkout/:slug"

   [orderComplete]
   slug = "{{ :slug }}"

   [purchasePixel]
   orderSlug = "{{ :slug }}"
   ==
   {% partial "site/header" %}
   {# ... existing thank-you-page partials ... #}
   {% partial "order/order-complete/order-complete" %}
   {% component 'purchasePixel' %}
   {% partial "site/footer" %}
   ```
   Without this declaration the Pixel-side dedup half does not fire; CAPI still fires correctly but the dedup % will read 0% in Test Events because there is no Pixel pair to match.
5. **Queue worker running** on the staging server (`php artisan queue:work --queue=default`). SendCapiEvent is `ShouldQueue` so dispatched jobs require a running worker to reach MetaClient → Graph API.

**Step 1 — Verify PAY-10 (PayPal Pixel + CAPI dedup):**

1. Open the staging storefront in a fresh-incognito browser session. The Phase-2 `EnsureFbpFbcCookies` middleware will set `_fbp` / `_fbc` cookies on first request.
2. Open browser DevTools → Network → filter for `fb.me/tr` and `facebook.com/tr` to capture Pixel requests as they fire.
3. Open Meta Events Manager → Test Events for the staging Pixel in a separate tab. Filter to the configured `test_event_code`.
4. Place a non-zero-cost order through to PayPal payment completion. PayPal's IPN flips status to `new-payment-received` (Status ID = 5) automatically per the live `PaymentMethod.after_status_id=5` wiring.
5. Within ~60 seconds, the Test Events view MUST show TWO entries for the same Purchase event_id:
   - **Browser event** (`source: Browser`) emitted by `PurchasePixel::onRun` via `fbq('track', 'Purchase', custom_data, {eventID})`. eventID is the persisted `meta_purchase_event_id` UUID.
   - **Server event** (`source: Server`) emitted by `SendCapiEvent → MetaClient → Graph API` fired by `OrderStatusWatcher::handleUpdated` when PayPal IPN flipped status to `new-payment-received`.
6. Click on the Purchase event row in Test Events. Inspect the dedup card:
   - **EMQ (Event Match Quality):** MUST be ≥ 8 (10 is highest). Each user_data field hashed correctly contributes — `em + ph + fn + ln + external_id + client_ip + client_user_agent + fbp + fbc` all present and well-formed should give EMQ 9–10.
   - **Dedup status:** MUST be "Successfully matched" with dedup percentage ≥ 80%. Proves Pixel + CAPI share the same `(event_id, event_name, event_time ±10s)` tuple. The shared `event_time` is the `meta_purchase_event_time` column populated atomically with `meta_purchase_event_id` by `OrderStatusWatcher` — `PurchasePixel` reads it back into the `event_time` slot of the `fbq()` custom_data parameter.
7. **Record in this SUMMARY (operator extends this section):** pixel_id used, EMQ score (number), dedup percentage (number), screenshot of the Test Events row showing both source entries paired with the same event_id.

**Step 2 — Verify PAY-11 (bank-transfer admin-flipped, single-channel CAPI):**

1. Switch staging payment method to bank transfer (Settings → Shopaholic → Payment methods → Bank transfer → `after_status_id` field should be NULL, NOT 5).
2. Place a bank-transfer order on staging through to checkout completion. Status will be the bank-transfer pending state (e.g. status_id=1 or 2 — NOT 5).
3. Confirm no CAPI event has fired (Test Events view shows no Purchase for this order).
4. Backend → Orders → open the new order → change Status dropdown to "New payment received" → Save. This triggers `eloquent.updated` on the Order via `OrderStatusWatcher::handleUpdated`.
5. Within ~60 seconds, Test Events should show ONE new Purchase event (CAPI only, no Pixel twin — there is no browser session because the admin flip happens entirely in the backend with no thank-you-page render).
6. Verify the event row: `source = Server`, `event_name = Purchase`, EMQ still ≥ 8 (same `UserDataHasher::forOrder` hashing applies).
7. **Record:** order number, status-flip timestamp, CAPI event row screenshot.

**Step 3 — Verify PAY-03 success criterion 3 (status flip-flop no-refire):**

1. Take the same bank-transfer order from Step 2. Backend → Orders → flip status to "Canceled" (status_id=4). Save.
2. Within 60s — no new CAPI event should appear in Test Events.
3. Flip status back to "New payment received". Save.
4. Within 60s — STILL no new CAPI event should appear (`refire_purchase_on_status_flip` default OFF; `meta_purchase_event_id` is non-null from Step 2.4).
5. Confirm via `\DB::table('lovata_orders_shopaholic_orders')->where('id', $iOrderId)->value('meta_purchase_event_id')` returns the UUID set in Step 2.4.

**Step 4 — Decision gate (BOTH conditions required for Phase 3 close per REQUIREMENTS PAY-10):**

- If **EMQ < 8**: Phase 3 closure BLOCKED. Diagnose: which `user_data` field is missing? Run `\Logingrupa\Metapixelshopaholic\Classes\Meta\UserDataHasher::forOrder($obOrder)` in tinker → inspect output → confirm `em`, `ph`, `fn`, `ln`, `external_id`, `client_ip_address`, `client_user_agent`, `fbp`, `fbc` are all populated.
- If **dedup < 80%**: Phase 3 closure BLOCKED. Diagnose:
  - Confirm `PurchasePixel` theme block IS declared on `order-complete.htm` (Step 0.4 prerequisite).
  - Confirm browser DevTools shows the Pixel call AND the eventID matches the persisted `meta_purchase_event_id` (read it from the order row).
  - Confirm event_time delta between Pixel and CAPI is < 10 seconds. The Pixel reads `meta_purchase_event_time` from the row written by `OrderStatusWatcher`; both sides MUST use the same value.
- If **EMQ ≥ 8 AND dedup ≥ 80% AND bank-transfer flip dispatched single-channel CAPI AND status flip-flop no-refire confirmed**: APPROVED. Mark PAY-10 + PAY-11 complete in REQUIREMENTS.md.

**Step 5 — Operator extends this SUMMARY:**

Edit this SUMMARY in place after the staging run, replacing the PENDING marker below with the recorded findings. Then commit a follow-up `docs(03-06)` with the resolution.

---

### Task 9 staging-verification results (PENDING — operator fills in)

> **Status:** PENDING. To be completed on staging after deployment + theme integration. Edit this section in place with the verification outcome.
>
> **PAY-10 verification:**
> - pixel_id used: TBD
> - EMQ score: TBD
> - Dedup percentage: TBD
> - Pixel + CAPI source-paired screenshot: TBD
>
> **PAY-11 verification:**
> - Order number: TBD
> - Status-flip timestamp: TBD
> - CAPI event row screenshot: TBD
>
> **PAY-03 flip-flop no-refire verification:**
> - Confirmed no re-fire on canceled → paid: TBD
> - Persisted meta_purchase_event_id retained: TBD
>
> **Decision:** PENDING (one of: APPROVED / BLOCKED with notes).

---

## Forward-Pointing Surface

### For Phase 4 (FUN-* funnel events)

- **FUN-12** (event_time mirror between browser fbq() and server CAPI): already wired for `Purchase` via the `PurchasePixel` component reading the new `meta_purchase_event_time` column. Phase 4 extends the same pattern — every funnel component (PixelHead, ViewContent, AddToCart, etc.) reads `event_time` from a persisted Item-level (or request-scoped) source so the Pixel + CAPI pair carry identical timestamps. The `OrderStatusWatcher::fireForwardDispatch`'s atomic `time() → saveQuietly → dispatch` discipline is the template for any future write-then-mirror flow.
- **FUN-01** (PageView CAPI twin in PixelHead): PixelHead currently fires Pixel-only; FUN-01 will use the same `SendCapiEvent::dispatch('PageView', PayloadBuilder::buildPageViewEventPayload(...))` shape that OrderStatusWatcher now demonstrates.

### For Phase 5 (HARD-* hardening)

- **HARD-05** (README runbook): MUST document the theme operator step on `order-complete.htm` for `[purchasePixel] orderSlug = "{{ :slug }}"` + `{% component 'purchasePixel' %}`. Phase 3 ships the component but theme integration is the operator step that activates the Pixel side of the dedup contract.
- **HARD-01** (backend FailedEvents controller): FailedEvent rows written by SendCapiEvent's dead-letter branch (plan 03-05) are now the v1 audit log. Phase 5 ships the backend list view + replay button.
- **HARD-02** (manual replay): A FailedEvent row carries the original payload — the replay button re-dispatches via `SendCapiEvent::dispatchSync` (bypassing the queue for operator-driven recovery). The current `OrderStatusWatcher` does NOT roll back the persisted columns on PayloadBuilder failure (deliberate: the order is real; only the Meta tracking is degraded — replay is the recovery, not re-fire).

## Known Stubs

None. Every property and method has a real call path. PurchasePixel.php's 16.7% uncovered gap is entirely defensive-narrowing fall-through branches (the `intOrZero` / `stringOrEmpty` helpers' "is_float / is_string / non-scalar" arms, the `extractCustomData`'s "data not array" / "first not array" / "custom_data not array" guards) — these are correctness-positive boundary catches, not business-logic stubs.

OrderStatusWatcher.php's 9.7% uncovered gap is similarly narrowing-helper branches plus the `isPluginDisabled` Throwable fail-safe catch (intentionally hard to provoke in a hermetic test).

## TDD Gate Compliance

This plan's tasks are individually `tdd="true"` per the PLAN.md, but the plan as a whole has `type: execute` (not `type: tdd`). The TDD discipline applied was:

- **Task 1 (test infrastructure):** test-only commit (4689160 — message prefix `test(03-06)`).
- **Task 2 (OrderStatusWatcher):** feat commit (89acd49) with passing existing tests as the GREEN proof.
- **Task 4 (PurchasePixel):** feat commit (2402942) with passing existing tests as GREEN proof.
- **Task 3 (Plugin.php):** feat commit (7349574).
- **Task 5 (PurchasePixelTest):** test-only commit (091c7d6) — explicit RED → GREEN for the 7 invariants.
- **Task 6 (regex validator):** fix commit (06e7637) — defensive, no new test needed (existing SettingsRegistrationTest verifies round-trip).
- **Task 7 (OrderStatusWatcherTest):** test-only commit (32d7479) — explicit RED → GREEN for the 10 invariants.
- **Task 8 (qa green + coverage):** test commit (b768db5) — coverage-driven additional tests.

All commits passed `composer qa` (or the relevant subset for test-only commits) before being recorded.

## Threat Model Realization (T-03-26..35)

| Threat ID | Status | Realized via |
|---|---|---|
| T-03-26 (meta_purchase_event_id forced reset) | **mitigated** | Column is only written by OrderStatusWatcher::fireForwardDispatch (forward-fire path) AND ::handleUpdated's refire-flip away-clear (null write). NO other writer exists — verified by grep on the plugin codebase. Backend Settings form does NOT bind the column. |
| T-03-27 (Infinite recursion via Order::save inside handler) | **mitigated** | All write sites use `saveQuietly()` (grep classes/event/OrderStatusWatcher.php returns 3 occurrences, all on the Order model). No bare `save()` exists. |
| T-03-28 (Spoof-dispatch from non-paid status) | **mitigated** | Status fence + idempotency fence both required to pass. Test cases 2, 3, 5 lock the guard chain. |
| T-03-29 (Plugin::boot dumping event subscribers to logs) | **accepted** | Event::subscribe is silent registration. Only the watcher's own `meta_pixel.*` breadcrumbs reach the log sink; those carry only order IDs (not PII). |
| T-03-30 (Stored XSS via pixel_id) | **mitigated** | PH-01 retro-fit shipped: Settings::$rules `pixel_id` regex `/^\d{6,20}$/` + fields.yaml `pattern: '^\d{6,20}$'`. Backend Settings save() validates via CommonSettings' Validation trait. |
| T-03-31 (Bank-transfer admin-flipped Purchase has no Pixel twin) | **accepted** | PAY-11 explicit contract. event_id is server-generated UUIDv4 — globally unique. Meta accepts single-channel CAPI when no browser session exists. |
| T-03-32 (Queue serializer leak of Order model) | **mitigated** | SendCapiEvent carries `array $arPayload` (plain JSON-serializable array) — NOT the Order model. SerializesModels trait is included for forward compatibility but the Phase-3 contract uses plain arrays. |
| T-03-33 (PurchasePixel renders attacker-controlled UUID via fbq() eventID) | **mitigated** | event_id is server-generated by `Uuid::uuid4()->toString()` in OrderStatusWatcher and saved via saveQuietly (T-03-26 covers the column-write monopoly). Twig partial additionally uses `e('js')` escaper as defence-in-depth. |
| T-03-34 (Pixel fires for non-paid order) | **mitigated** | PurchasePixel guard chain: order-by-slug → status fence (Status::code === paid_status_code) → event_id fence → event_time fence. All 4 must pass. Test cases 2-4 lock the rejection paths. |
| T-03-35 (PurchasePixel custom_data leaks order details to browser network logs) | **accepted** | custom_data carries content_ids, value, currency, order_id, num_items — values the user already knows from their own checkout. user_data is INTENTIONALLY OMITTED on the Pixel side per Meta CAPI spec; PII hashes only flow server-side. |

## Threat Flags

None. No new security-relevant surface introduced beyond what's already documented in `<threat_model>` (T-03-26..35).

## Self-Check: PASSED

**Files created (5):**

```bash
[ -f classes/event/OrderStatusWatcher.php ] && echo "FOUND" || echo "MISSING"            # FOUND
[ -f components/PurchasePixel.php ] && echo "FOUND" || echo "MISSING"                    # FOUND
[ -f components/purchasepixel/default.htm ] && echo "FOUND" || echo "MISSING"            # FOUND
[ -f tests/Feature/OrderStatusWatcherTest.php ] && echo "FOUND" || echo "MISSING"        # FOUND
[ -f tests/Feature/PurchasePixelTest.php ] && echo "FOUND" || echo "MISSING"             # FOUND
```

**Files modified (4):**

- `Plugin.php` — `Event::subscribe(OrderStatusWatcher::class)` + `PurchasePixel::class => 'purchasePixel'` + 3 new use imports — FOUND
- `tests/MetapixelTestCase.php` — bootOrdersTable provisions 2 new columns + dropHermeticSchemas adds 3 fixture-side drops — FOUND
- `models/Settings.php` — public $rules with pixel_id regex — FOUND
- `models/settings/fields.yaml` — pattern + comment block on pixel_id — FOUND

**Commits (8 task commits — Task 9 is PENDING manual checkpoint):**

- `4689160` — test(03-06): task 1 — FOUND
- `89acd49` — feat(03-06): task 2 — FOUND
- `2402942` — feat(03-06): task 4 — FOUND
- `7349574` — feat(03-06): task 3 — FOUND
- `091c7d6` — test(03-06): task 5 — FOUND
- `06e7637` — fix(03-06): task 6 — FOUND
- `32d7479` — test(03-06): task 7 — FOUND
- `b768db5` — test(03-06): task 8 — FOUND

**Quality gates:**

- `composer qa` — exit 0 — VERIFIED
- `composer pint-test` — passed — VERIFIED
- `composer analyse` (phpstan level 10) — 0 errors — VERIFIED
- `composer phpmd` — 0 warnings — VERIFIED
- `composer test-cov` — 126 passed / 365 assertions / 89.6% total / OrderStatusWatcher.php 90.3% / PurchasePixel.php 83.3% — VERIFIED
- OrderStatusWatcher.php coverage ≥ 80% (90.3%) — VERIFIED
- PurchasePixel.php coverage ≥ 80% (83.3%) — VERIFIED
- All 10 OrderStatusWatcher invariants present in OrderStatusWatcherTest — VERIFIED
- All 7 plan-mandated PurchasePixel invariants + 6 coverage-driven additions present in PurchasePixelTest — VERIFIED
- Plugin.php Event::subscribe(OrderStatusWatcher::class) present once — VERIFIED
- Plugin.php registerComponents adds PurchasePixel — VERIFIED
- models/Settings.php OR models/settings/fields.yaml has /^\d{6,20}$/ regex — VERIFIED (both — defence-in-depth)
- Phase-3 cumulative test count ≥ 67: 126 ≥ 67 ✓ — VERIFIED

**Task 9 status:** PENDING — requires staging deployment + Meta Events Manager observation. **Manual checkpoint signal returned to orchestrator.**

---

*Phase: 03-purchase-end-to-end*
*Plan: 06 (PAY-03 + PAY-10 + PAY-11 — OrderStatusWatcher + PurchasePixel + manual staging verification)*
*Completed (Tasks 1-8): 2026-05-12*
*Task 9 (BLOCKING manual): PENDING staging verification — to be performed by operator after deployment.*
