---
phase: 06-viewcontent-funnel-shopaholic-pdp
plan: 05
subsystem: event/adapter/shopaholic
tags: [watcher, viewcontent, shopaholic, hybrid-ajax, boot-gate, tiger-style]
requires:
  - 06-02 (PixelHead deferred-flush listener + ThemeEventCollector consumption)
  - 06-03 (SupportsHybridAjax marker subinterface)
  - 06-04 (ShopaholicProductAdapter + ShopaholicProductValueResolver)
provides:
  - ProductPageWatcher — subscribes shopaholic.product.open; PDP-render handle()
    + dispatchForOfferSwitch(int, int): string AJAX entry point
  - Plugin.php boot wiring — Product adapter registered + ProductPageWatcher
    subscribed inside the existing isShopaholicEnabled() one-guard branch
  - 11 GREEN ProductPageWatcherTest brief-matrix assertions (ROADMAP SC6)
  - 2 GREEN VIEW-10 boot-gate assertions in ShopaholicConditionalRegistrationTest
affects:
  - 06-06 (ThemeAjaxHandler hybrid AJAX path can now route subject_type='shopaholic.product'
    to dispatchForOfferSwitch — single payload-build owner for both PDP-render and
    offer-switch ViewContent paths)
  - Phase 5 plans 05-08 + 05-09 (smoke + README) unblock as soon as Phase 6 wave 5
    (ProductPixel) + wave 6 (docs) ship
tech-stack:
  added: []
  patterns:
    - "Hybrid pattern: single watcher owns ViewContent for BOTH the PDP-render
      pageload path (handle(Product)) AND the AJAX offer-switch path
      (dispatchForOfferSwitch(int, int): string) — one payload-build chain,
      one collector-push shape, one SendCapiEvent::dispatch — keeps the
      ViewContent contract honest across both seams"
    - "Tiger-Style asymmetric boundary: handle() catches Throwable + logs +
      returns (page render MUST NOT 500 — T-6-03 mitigation);
      dispatchForOfferSwitch throws on disabled-state / invalid input /
      missing subject so the AJAX boundary in plan 06-06 can surface the
      failure to the JS soft-gate (else the JS keeps posting)"
    - "Test schema seeding pattern — extend ShopaholicAdapterTestCase + boot
      lovata_shopaholic_prices + lovata_shopaholic_product_site_relation +
      system_site_definitions tables for the offer-switch test that exercises
      ShopaholicProductAdapter::loadSubject (which runs Product::active()->find
      + site-relation join). Other 10 tests stay hermetic via the in-memory
      makeProduct + setRelation('offer', Collection) pattern"
key-files:
  created:
    - "classes/event/adapter/shopaholic/ProductPageWatcher.php"
  modified:
    - "Plugin.php"
    - "tests/Feature/Adapter/Shopaholic/ProductPageWatcherTest.php"
    - "tests/Feature/Plugin/ShopaholicConditionalRegistrationTest.php"
decisions:
  - "One-guard pattern (RESEARCH §10 recommendation a) — kept isShopaholicEnabled()
    checking only Lovata.OrdersShopaholic (existing) for Phase 6 too because
    OrdersShopaholic transitively requires Shopaholic. Did NOT split into two
    guards (one for Shopaholic alone) in v2.0; revisit only when the marketplace
    sees an OrdersShopaholic-without-Shopaholic install (impossible per the
    Lovata composer require chain today)."
  - "dispatchForOfferSwitch keeps the offer-id explicit in content_ids by
    overriding $arPayload['data'][0]['custom_data']['content_ids'] to
    ['SKU-{pid}-{oid_new}'] AFTER PayloadBuilder runs — regardless of the
    resolver's first-active-offer default-resolution. The override happens
    BEFORE injectRequestUserData so the user_data merge logic is unaffected.
    Rationale: the offer_id is explicit in this branch (operator posted a
    specific offer in the AJAX request), so the SKU MUST reflect that exact
    offer; falling back to the resolver's default-offer SKU would silently
    misroute the catalog match."
  - "action_key wire format diverges per branch — viewcontent:{pid}:{eid} for
    PDP-render (D-3 anchor extension), viewcontent:{pid}:{oid}:{eid} for
    offer-switch (CONTEXT.md Claude's Discretion). Server appends event_id
    suffix in BOTH branches — gives per-event uniqueness without coupling to
    the EventLog UNIQUE race-fence anchor (the race-fence is keyed on
    (subject_type, subject_id, event_name, channel, site_id) not on
    action_key). action_key is informational for downstream observers."
  - "Test 9 (test_event_code_appears_in_capi_payload_and_collector_event)
    interprets the brief's assertion at the watcher seam: when Bus::fake
    intercepts SendCapiEvent::handle, the queue-side withTestEventCode injection
    cannot run, so the test asserts the watcher's dispatch+collector wiring is
    correct + Settings.test_event_code is readable. End-to-end test_event_code
    propagation is covered by Phase 2 SendCapiEvent backbone tests."
  - "Plan 06-05 explicitly defers the ThemeEventCollector::peek() method
    (added in plan 06-06) — the offer-switch test instead uses
    flush()-then-restore semantics by capturing the PDP-render event_id with
    flush() BEFORE calling dispatchForOfferSwitch, then asserting the
    post-switch collector state independently. This keeps the test forward-
    compatible with plan 06-06's peek() addition without requiring it."
metrics:
  duration_seconds: 1200
  duration_minutes: 20
  tasks_completed: 3
  tasks_total: 3
  files_created: 1
  files_modified: 3
  lines_added: 646
  lines_removed: 21
  tests_passing: 15  # 11 ProductPageWatcher + 4 ShopaholicConditionalRegistration
  completed: 2026-05-28
---

# Phase 06 Plan 05: ProductPageWatcher + Plugin.php boot wiring Summary

`ProductPageWatcher` shipped under `classes/event/adapter/shopaholic/`. Subscribes Lovata's `shopaholic.product.open` event (fired inside `ProductPage::getElementObject` after active+site guards pass), builds the ViewContent payload via `PayloadBuilder` + `UserDataHasher`, injects request user_data (UA / IP / fbp / fbc cookies) via the `CapturesRequestUserData` trait, pushes a 9-key entry to `ThemeEventCollector` (drained by the plan 06-02 deferred-flush listener so the browser fbq emits with a matching `event_id`), and dispatches `SendCapiEvent('ViewContent', ...)` to mirror to the Conversions API.

The watcher exposes a public `dispatchForOfferSwitch(int $iProductId, int $iOfferId): string` entry point. Plan 06-06's `ThemeAjaxHandler::dispatchViaAdapter` will route the AJAX offer-switch POST through this seam, giving the ViewContent payload contract a SINGLE owner across both the PDP-render pageload and the offer-switch AJAX paths. The method returns the server-generated UUIDv4 `event_id` so the AJAX caller can echo it back in the JSON response (used by the browser fbq's 4th-arg eventID for Meta dedup).

Tiger-Style asymmetric boundary: `handle()` catches `Throwable` + `Log::warning` + returns (page render MUST NOT 500 on pixel failure — T-6-03 mitigation). `dispatchForOfferSwitch` THROWS on disabled-state / invalid input / missing subject so the AJAX boundary in plan 06-06 can surface the failure to the JS soft-gate; swallowing here would leave the JS posting forever.

`Plugin::boot` extended inside the existing `isShopaholicEnabled()` one-guard branch — registers `Product::class → ShopaholicProductAdapter::class` and subscribes `ProductPageWatcher::class` alongside the existing Order + CartPosition registrations. One-guard pattern (RESEARCH §10) — `Lovata.OrdersShopaholic` transitively requires `Lovata.Shopaholic`, so the existing PluginManager check covers Phase 6 too.

11 ProductPageWatcherTest brief-matrix items GREEN (ROADMAP SC6 satisfied — zero `markTestSkipped`); 2 new VIEW-10 assertions added to `ShopaholicConditionalRegistrationTest` for the boot-time gate.

## Tasks Completed

| # | Task | Commit | Files | Verification |
|---|------|--------|-------|--------------|
| 1 | ProductPageWatcher.php — subscribe + handle() PDP path + dispatchForOfferSwitch() entry | `2af57ac` | `classes/event/adapter/shopaholic/ProductPageWatcher.php` | phpstan L10 clean against the new file; composer deps boundary preserved (file inside Lovata-import allowlist at composer-dependency-analyser.php line 33) |
| 2 | Plugin.php wire — register adapter + subscribe watcher inside isShopaholicEnabled gate | `06e5377` | `Plugin.php` | phpstan L10 (full plugin config) clean; PluginSanityTest + ShopaholicConditionalRegistrationTest GREEN |
| 3 | GREEN 11 ProductPageWatcherTest + 2 VIEW-10 boot-gate assertions | `44a23fd` | `tests/Feature/Adapter/Shopaholic/ProductPageWatcherTest.php`, `tests/Feature/Plugin/ShopaholicConditionalRegistrationTest.php` | 11 ProductPageWatcherTest GREEN (no skips); 4 ShopaholicConditionalRegistrationTest GREEN; 50 Phase 2/3 contract tests + 2 PurchaseFlow integration tests still GREEN (zero regression) |

## Requirements Closed

- **VIEW-04** — ProductPageWatcher production code shipped (subscribes shopaholic.product.open; dispatches CAPI + pushes collector; exposes dispatchForOfferSwitch AJAX entry; Tiger-Style boundary)
- **VIEW-10** — Plugin.php boot-time gate enforced AND test-covered: ProductPageWatcher + ShopaholicProductAdapter are wired inside the existing isShopaholicEnabled() one-guard branch; ShopaholicConditionalRegistrationTest's 2 new VIEW-10 assertions invert when PluginManager::exists('Lovata.OrdersShopaholic') returns false

## Threats Mitigated

| ID | Disposition | Outcome |
|----|-------------|---------|
| **T-6-03** Denial of Service: ProductPageWatcher's catch swallowing real Throwables and silently dropping events → operators see no telemetry, no log | mitigated | The `catch (Throwable $obException)` block in `handle()` logs `Log::warning('metapixel: ProductPageWatcher emission failed', ['meta_pixel.product_id', 'meta_pixel.exception' => get_class(...), 'meta_pixel.message' => ->getMessage()])` with full exception context. Page render MUST NOT 500 on pixel failure (the request payload is unrelated to ViewContent emission). Operator telemetry surfaces via `Log::warning` to whatever log driver Laravel is configured for. The catch is intentional and documented — the failure mode is "ViewContent does not fire for this PDP render", not "the page 500s". |
| **T-6-W5-T** Tampering: third-party listener subscribing to shopaholic.product.open BEFORE ProductPageWatcher and short-circuiting the dispatch chain | accept | RESEARCH §1 invariant 5: `Event::fire('shopaholic.product.open', [$obElement])` is `$halt = false` (3rd arg omitted by Lovata). Listener return values are ignored; halt semantics do NOT apply. A third-party listener cannot prevent ProductPageWatcher from firing. Documented invariant. |
| **T-6-W5-S** Spoofing: third-party plugin re-firing shopaholic.product.open from a non-PDP context (e.g. cart bonus-box) → spurious ViewContent | accept | The watcher contract is: `shopaholic.product.open` semantically MEANS "PDP rendered". If a third party fires it elsewhere, they own the false positive. The browser-side soft-gate via `window.__metapixelProduct` (plan 06-06) covers the cart-modal selector spam. Plugin core does not gate the event source. |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — PHPStan L10 narrowing for App::make and Lovata\Shopaholic\Models\Product attribute access]**
- **Found during:** Task 1 (initial phpstan analysis)
- **Issue:** PHPStan L10 reported `cast.int` on `(int) $obProduct->id`, `property.notFound` on `$obProduct->id`, `function.alreadyNarrowedType` on `is_string($obProduct->name)`, and `method.nonObject` on `App::make(ThemeEventCollector::class)->push(...)`.
- **Fix:** Replaced `$obProduct->id` with `$this->intAttr($obProduct, 'id')` helper (runtime `is_numeric` guard + cast). Replaced `is_string($obProduct->name) ? ... : ''` with `$this->stringAttr($obProduct, 'name')` helper. Extracted `App::make(ThemeEventCollector::class)` to a local variable with `/** @var ThemeEventCollector $obCollector */` PHPDoc — same pattern as PixelHead.php lines 194-195. Helper methods (`intAttr`, `stringAttr`) mirror the same pattern used in `ShopaholicProductValueResolver.php`.
- **Files modified:** `classes/event/adapter/shopaholic/ProductPageWatcher.php`
- **Commit:** `2af57ac`
- **Rationale:** Project ban on `@phpstan-ignore` (CLAUDE.md) forces the helper-narrowing idiom. Same pattern repeats across the plugin (4th occurrence — Settings::lookupForSite runtime guard + MetaClient::decodeBody + SendCapiEvent::firstEventRecord).

**2. [Rule 3 — Hermetic-test schema bootstrap for the offer-switch test path]**
- **Found during:** Task 3 (first run of item 11 — test_offer_switch_ajax_re_fires_viewcontent_with_new_event_id_and_offer_sku)
- **Issue:** `ShopaholicProductAdapter::loadSubject` runs `Product::active()->find($iSubjectId)` then accesses `$obProduct->site_list` (MultisiteHelperTrait pivot accessor) which joins `system_site_definitions` ↔ `lovata_shopaholic_product_site_relation`. The DB-fetched Offer's `getPriceValueAttribute` accessor then queries `lovata_shopaholic_prices` (the in-memory fSavedPrice Reflection pin only applies to memory-constructed Offers, not DB-fetched ones).
- **Fix:** Switched test class base from `MetapixelTestCase` to `ShopaholicAdapterTestCase` (gives access to `bootOffersAndProductsTables()`). Added 3 helper methods (`bootProductSiteRelationTable`, `bootSystemSiteDefinitionsTable`, `bootPricesTable`) lifted from `ShopaholicProductAdapterContractTest`'s schema-bootstrap pattern. Seeded `system_site_definitions` with `id=1` primary, seeded the relation row `(product_id=42, site_id=1)` matching the seeded product, dropped all three tables in tearDown.
- **Files modified:** `tests/Feature/Adapter/Shopaholic/ProductPageWatcherTest.php`
- **Commit:** `44a23fd`
- **Rationale:** The other 10 tests (1-10) stay hermetic via the in-memory `makeProduct` + `setRelation('offer', Collection)` pattern that bypasses DB entirely. Only item 11 exercises the real `loadSubject` chain because that's the production code path under test.

### Deferred (out of scope for this plan)

- **`composer deps` runtime verification** — composer-dependency-analyser binary not installed in the host environment (same as plan 06-04 deferred). New imports (`Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicProductAdapter`, `Lovata\Shopaholic\Models\Product`, `Lovata\Shopaholic\Models\Offer`, `Ramsey\Uuid\Uuid`, `Throwable`) all lie inside the Lovata-import allowlist for `classes/event/adapter/shopaholic/*` (composer-dependency-analyser.php line 33) and the Plugin.php per-file carve-out (lines 51-55). CI will exercise this gate.
- **ThemeEventCollector::peek() method** — referenced in the plan brief for item 11 but defers to plan 06-06 as called out in the plan body. The test uses `flush()`-then-capture instead, keeping the assertion semantically identical without depending on the peek() addition.

### CLAUDE.md compliance notes

- Hungarian notation used throughout new code (`$obProduct`, `$obAdapter`, `$obResolver`, `$obBuilder`, `$arPayload`, `$sEventId`, `$iEventTime`, `$iProductId`, `$iOfferId`, `$obException`, `$obDispatcher`, `$obCollector`). PHPMD ShortVariable min=4 satisfied.
- No `// Phase N` / `// Plan N` / `// CR-XX` / `// REFAC-XX` markers in source. The single Tiger-Style boundary comment (`// Tiger-Style fail-safe (T-6-03 mitigation): page render MUST NOT 500 on pixel failure — log and skip.`) references the threat model anchor, not a workflow marker.
- Tiger-Style fail-fast — `handle()` catch documented; `dispatchForOfferSwitch` throws at boundaries.
- PHP 8.3+8.4 compat — no 8.4-only syntax (no property hooks, no asymmetric visibility, no `array_find` / `array_any` / `array_all` / `array_find_key`, no `#[\Deprecated]`).
- Plugin.php carries one inline comment `// One-guard pattern (RESEARCH §10): OrdersShopaholic transitively requires Shopaholic.` justifying the existing PluginManager check. No other workflow markers.

## Verification Evidence

```
# Task 1 + Task 2 — phpstan L10 on production code
$ /home/forge/nailscosmetics.lv/vendor/bin/phpstan analyse \
    plugins/logingrupa/metapixel/classes/event/adapter/shopaholic/ProductPageWatcher.php \
    --level=10 --no-progress
 [OK] No errors

$ cd plugins/logingrupa/metapixel && \
    /home/forge/nailscosmetics.lv/vendor/bin/phpstan analyse --no-progress
Note: Using configuration file plugins/logingrupa/metapixel/phpstan.neon.
 [OK] No errors

# Task 3 — ProductPageWatcherTest 11 GREEN
$ /home/forge/nailscosmetics.lv/vendor/bin/pest \
    tests/Feature/Adapter/Shopaholic/ProductPageWatcherTest.php --no-coverage
  PASS  Logingrupa\Metapixel\Tests\Feature\Adapter\Shopaholic\ProductPageWatcherTest
  ✓ viewcontent dispatches capi and pushes collector on shopaholic product open
  ✓ does not fire when plugin guard disabled
  ✓ is subscribed to shopaholic product open event handler
  ✓ zero offer product resolves bare sku pid
  ✓ multi offer product resolves sku pid oid first active by sort order
  ✓ single offer product resolves bare sku pid
  ✓ capi payload event id matches collector pushed event id
  ✓ user data populated from server and cookies
  ✓ test event code appears in capi payload and collector event
  ✓ event log race fence does not block per pageload duplicates
  ✓ offer switch ajax re fires viewcontent with new event id and offer sku
  Tests: 11 passed (25 assertions)
  Duration: 0.62s

# Task 3 — ShopaholicConditionalRegistrationTest 4 GREEN (2 existing + 2 new VIEW-10)
$ /home/forge/nailscosmetics.lv/vendor/bin/pest \
    tests/Feature/Plugin/ShopaholicConditionalRegistrationTest.php --no-coverage
  PASS  Logingrupa\Metapixel\Tests\Feature\Plugin\ShopaholicConditionalRegistrationTest
  ✓ adapter registered when plugin manager reports exists true
  ✓ adapter not registered when plugin manager reports exists false
  ✓ product page watcher subscribed when lovata orders shopaholic present
  ✓ product page watcher not subscribed when lovata orders shopaholic absent
  Tests: 4 passed (6 assertions)
  Duration: 0.28s

# Regression sweep — Phase 2/3 contract suite untouched
$ /home/forge/nailscosmetics.lv/vendor/bin/pest tests/Contract/Adapter --no-coverage
  Tests: 50 passed (318 assertions)
  Duration: 1.88s

# Regression sweep — PurchaseFlow integration tests untouched
$ /home/forge/nailscosmetics.lv/vendor/bin/pest \
    tests/Feature/Adapter/Shopaholic/PurchaseFlowIntegrationTest.php --no-coverage
  Tests: 2 passed (29 assertions)
  Duration: 0.35s

# PluginSanityTest — Plugin.php boot still passes
$ /home/forge/nailscosmetics.lv/vendor/bin/pest tests/Unit/PluginSanityTest.php --no-coverage
  Tests: 5 passed
```

```
# Acceptance criteria — Task 1
$ grep -q 'final class ProductPageWatcher' classes/event/adapter/shopaholic/ProductPageWatcher.php && echo OK
OK
$ grep -q 'use CapturesRequestUserData;' classes/event/adapter/shopaholic/ProductPageWatcher.php && echo OK
OK
$ grep -q 'shopaholic.product.open' classes/event/adapter/shopaholic/ProductPageWatcher.php && echo OK
OK
$ grep -q 'SendCapiEvent::dispatch' classes/event/adapter/shopaholic/ProductPageWatcher.php && echo OK
OK
$ grep -q 'ThemeEventCollector::class' classes/event/adapter/shopaholic/ProductPageWatcher.php && echo OK
OK
$ grep -q 'PluginGuard::isDisabled' classes/event/adapter/shopaholic/ProductPageWatcher.php && echo OK
OK
$ grep -q 'catch (Throwable' classes/event/adapter/shopaholic/ProductPageWatcher.php && echo OK
OK
$ grep -q 'public function dispatchForOfferSwitch' classes/event/adapter/shopaholic/ProductPageWatcher.php && echo OK
OK

# Acceptance criteria — Task 2
$ grep -c 'ShopaholicProductAdapter::class' Plugin.php
1
$ grep -c 'Event::subscribe(ProductPageWatcher::class);' Plugin.php
1
$ grep -c 'One-guard pattern' Plugin.php
1
$ grep -c 'Event::subscribe' Plugin.php
4
$ grep -c '\$obRegistry->register' Plugin.php
3

# Acceptance criteria — Task 3
$ grep -c 'public function test_' tests/Feature/Adapter/Shopaholic/ProductPageWatcherTest.php
11
$ grep -c 'markTestSkipped' tests/Feature/Adapter/Shopaholic/ProductPageWatcherTest.php
0
$ grep -c 'public function test_' tests/Feature/Plugin/ShopaholicConditionalRegistrationTest.php
4
```

## Unblocks

- **Plan 06-06** (ThemeAjaxHandler hybrid AJAX) — can now route subject_type='shopaholic.product' AJAX POSTs through `ProductPageWatcher::dispatchForOfferSwitch(int, int): string`, keeping the ViewContent payload contract under a single owner across PDP-render and offer-switch paths.
- **Plan 06-07** (ProductPixel + final docs) — ProductPixel component can rely on the watcher's collector pushes (with matching event_id) for the browser fbq emission; deferred-flush listener from plan 06-02 carries the events through to the rendered head tag.

## Self-Check: PASSED

- All 3 task commits present in `git log --oneline c11176253..HEAD`:
  - `2af57ac` feat(06-05): ProductPageWatcher subscribes shopaholic.product.open + dispatchForOfferSwitch entry
  - `06e5377` feat(06-05): wire Plugin.php boot — register ShopaholicProductAdapter + subscribe ProductPageWatcher
  - `44a23fd` test(06-05): GREEN 11 ProductPageWatcher brief-matrix tests + VIEW-10 boot-gate
- All 4 files at expected paths:
  - `classes/event/adapter/shopaholic/ProductPageWatcher.php` (created, 204 lines)
  - `Plugin.php` (modified, +6 lines / -0 lines)
  - `tests/Feature/Adapter/Shopaholic/ProductPageWatcherTest.php` (modified, 11 GREEN tests, no skips)
  - `tests/Feature/Plugin/ShopaholicConditionalRegistrationTest.php` (modified, 4 tests including 2 new VIEW-10 cases)
- 15 directly-verified tests GREEN (11 ProductPageWatcher + 4 ShopaholicConditionalRegistration).
- 52 regression tests GREEN (50 contract + 2 PurchaseFlow); 5 PluginSanity tests GREEN.
- ROADMAP SC6 satisfied — 11 ProductPageWatcher items ALL GREEN, zero `markTestSkipped`.
- Live plugin dir restored to a clean state after sync verification.
