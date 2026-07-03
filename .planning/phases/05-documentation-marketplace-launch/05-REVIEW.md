---
phase: 05-documentation-marketplace-launch
reviewed: 2026-07-03T10:26:22Z
depth: standard
files_reviewed: 22
files_reviewed_list:
  - classes/adapter/shopaholic/ShopaholicCartPositionAdapter.php
  - classes/adapter/shopaholic/ShopaholicSettingsOptions.php
  - classes/adapter/theme/ThemeAjaxHandler.php
  - classes/event/adapter/shopaholic/CartPositionWatcher.php
  - classes/event/adapter/shopaholic/ProductPageWatcher.php
  - classes/meta/AddToCartPixelResult.php
  - classes/meta/PayloadBuilder.php
  - classes/queue/SendCapiEvent.php
  - classes/testing/EventSubjectAdapterContractTestCase.php
  - docs/CUSTOM-ADAPTERS.md
  - README.md
  - tests/Feature/Adapter/Shopaholic/CartPositionWatcherBrowserPixelTest.php
  - tests/Feature/Adapter/Shopaholic/ProductPageWatcherTest.php
  - tests/Feature/Adapter/Theme/ThemeAjaxHandlerMarkAddToCartTest.php
  - tests/Feature/Docs/AssetsExistTest.php
  - tests/Feature/Docs/CustomAdaptersStructureTest.php
  - tests/Feature/Docs/NoV1xReferencesTest.php
  - tests/Feature/Docs/ReadmeStructureTest.php
  - tests/Feature/Plugin/PluginYamlSanityTest.php
  - tests/Unit/Meta/PayloadBuilderTest.php
  - ../../../themes/logingrupa-naisstore/partials/shared/controls/add-to-cart-control.js
findings:
  critical: 1
  warning: 9
  info: 8
  total: 18
status: issues_found
---

# Phase 05: Code Review Report

**Reviewed:** 2026-07-03T10:26:22Z
**Depth:** standard
**Files Reviewed:** 22
**Status:** issues_found

## Summary

Standard-depth review of the Phase 05 documentation/marketplace-launch surface: two marketplace-facing docs, the theme AJAX handler + Shopaholic watchers touched by the D-07 AddToCart dedup work, the queue job, the contract-test harness, docs gate tests, and the theme add-to-cart JS.

Docs factual claims were cross-checked against the codebase: all eight README troubleshoot log signatures verified verbatim, the `pixelHead` component alias, `this.metapixel.pushEvent` Twig API, `new-payment-received` default, "Settings → Marketing → Meta Pixel + CAPI" path, and all `field.*_label` strings are accurate, and the five screenshots exist and are git-tracked. The 04-check-dedup.png fail-safe caption is accurate per instructions.

The headline defect: **both marketplace docs teach a third-party adapter registration API that does not exist** — the copy-paste snippet fatals inside the third party's `Plugin::boot()`, the exact host-site cascade failure the PluginGuard pattern was designed to prevent — and the docs gate test enshrines the broken forms. Additional warnings cover an event-name mismatch on the hybrid AJAX product path, unvalidated offer IDs poisoning `content_ids`, missing PluginGuard on the theme fire paths, a `$tries`/backoff mismatch, queued-job model-deletion loss, and two theme-JS tracking defects.

## Critical Issues

### CR-01: Both marketplace docs teach an `AdapterRegistry` registration API that does not exist — copy-paste fatals in third-party `Plugin::boot()`

**File:** `docs/CUSTOM-ADAPTERS.md:106` and `README.md:177`
**Issue:** `AdapterRegistry` (classes/adapter/AdapterRegistry.php) is a `final` class with only an *instance* method `register(string, string)`; it has no `instance()` static (no October `Singleton` trait) and no static `register()`. Yet:
- `docs/CUSTOM-ADAPTERS.md:106` instructs: `AdapterRegistry::instance()->register(AcmeCart::class, AcmeCartAdapter::class);` → `Error: Call to undefined method ...AdapterRegistry::instance()`.
- `README.md:177` instructs: `AdapterRegistry::register(\Vendor\Plugin\Models\Booking::class, ...);` → `Error: Non-static method ...register() cannot be called statically` on PHP 8.

Either snippet, pasted into a third-party `Plugin::boot()` as documented, throws at boot and cascade-breaks the entire host site — the precise failure mode the plugin's own PluginGuard lock exists to prevent. This is the primary deliverable of the DOCS-03/extensibility documentation and it is executable-wrong on both public surfaces. (The plugin's own `Plugin.php:83` and its class docblock show the correct container form.)
**Fix:** Replace both snippets with the container-singleton form the plugin itself uses:
```php
use Illuminate\Support\Facades\App;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;

App::make(AdapterRegistry::class)->register(AcmeCart::class, AcmeCartAdapter::class);
```
Also update the prose at `docs/CUSTOM-ADAPTERS.md:124` ("`AdapterRegistry::instance()->register(...)` maps your subject class...") to match. Must be fixed together with WR-01, whose gate currently locks in the broken forms.

## Warnings

### WR-01: Docs gate test enshrines the two broken registration call shapes

**File:** `tests/Feature/Docs/CustomAdaptersStructureTest.php:100-109`
**Issue:** `test_doc_shows_register_pattern` passes when the doc contains **either** `AdapterRegistry::instance()->register` **or** `AdapterRegistry::register` — both of which are fatal call shapes (see CR-01). The gate validates the wrong contract: fixing the doc to the correct `App::make(AdapterRegistry::class)->register(...)` form would still pass (substring `AdapterRegistry::register` is absent, `::instance()` absent → both false → test FAILS), so the fix for CR-01 is actively blocked by this test.
**Fix:** Assert the correct pattern:
```php
$this->assertStringContainsString(
    'App::make(AdapterRegistry::class)->register',
    $sDoc,
    'docs/CUSTOM-ADAPTERS.md must show the container-resolved AdapterRegistry registration snippet.',
);
```

### WR-02: Hybrid AJAX product path renders the caller-supplied event name in the browser script while the server always dispatches ViewContent

**File:** `classes/adapter/theme/ThemeAjaxHandler.php:228-239`
**Issue:** In `dispatchViaAdapter`, the `ShopaholicProductAdapter` branch delegates to `ProductPageWatcher::dispatchForOfferSwitch`, which hardcodes `'ViewContent'` for the CAPI dispatch — but the returned fbq script is built with `FbqScriptBuilder::build($mName, ...)` where `$mName` is any allowlisted name from the request (`AddToCart`, `Purchase`, `Lead`, ...). A request with `subject_type=shopaholic.product&name=Purchase` yields a browser `Purchase` event carrying an `event_id` whose server twin is a `ViewContent` — the pair never deduplicates by name, so the browser event counts as an extra standalone conversion in Meta. Client-controllable event-count inflation on a conversion pixel.
**Fix:** Reject non-ViewContent names on this branch:
```php
if ($sAdapterClass === ShopaholicProductAdapter::class) {
    if ($mName !== 'ViewContent') {
        return new JsonResponse(['error' => 'shopaholic.product supports ViewContent only'], 422);
    }
    ...
}
```

### WR-03: `dispatchForOfferSwitch` accepts any offer_id and stamps it into `content_ids` — bogus SKUs sent to Meta

**File:** `classes/event/adapter/shopaholic/ProductPageWatcher.php:198-224`
**Issue:** `$arForcedContentIds = ['SKU-'.$iProductId.'-'.$iOfferId]` is built from the raw browser-supplied offer_id **before** `findOffer()` checks whether the offer exists on the product. The fail-safe only reverts `content_name`/`value`; `content_ids` and `contents` keep the fabricated SKU. A request with `offer_id=9999999` sends `SKU-42-9999999` to CAPI (and into the browser script and the EventLog payload), violating the locked `SKU-{product_id}[-{offer_id}]` ↔ Facebook Catalog feed contract and degrading event match quality with attacker/typo-controllable junk IDs.
**Fix:** Resolve the offer first and fail fast when it is not one of the product's offers:
```php
$obOffer = $this->findOffer($obProduct, $iOfferId);
if ($obOffer === null) {
    throw new \RuntimeException('dispatchForOfferSwitch: offer does not belong to product');
}
```
(ThemeAjaxHandler's boundary catch converts this to an error response; alternatively return 422 there.)

### WR-04: `onFireEvent` and generic hybrid paths skip PluginGuard — disabled plugin accumulates FailedEvent rows instead of suppressing

**File:** `classes/adapter/theme/ThemeAjaxHandler.php:82-122, 240-263`
**Issue:** The plain `onFireEvent` path and the generic hybrid-alias path never call `PluginGuard::isDisabled()`. With an empty `pixel_id` (documented disabled mode, "events suppressed"), every theme `pushEvent` AJAX still queues a `SendCapiEvent`; each job then wins the EventLog fence, throws `MissingPixelConfigException` in `Settings::lookupForSite`/`MetaClient`, writes a `FailedEvent` dead-letter row, and fires the `metapixel.event.dead_letter` hook. A disabled plugin therefore pollutes the FailedEvents admin list and triggers third-party permanent-failure alerting on every theme event — contradicting the PluginGuard lock and the README troubleshoot row "No events fire at all ... An empty Pixel ID disables the plugin by design." The sibling paths (`markAddToCartPixel` via `resolveBrowserPixel`, `dispatchForOfferSwitch`) both check the guard.
**Fix:** Early-return before dispatch on both paths:
```php
if (PluginGuard::isDisabled()) {
    return new JsonResponse(['event_id' => null, 'script' => '']);
}
```

### WR-05: `SendCapiEvent::$tries = 3` contradicts its own "4 total tries" contract; third backoff entry is unreachable

**File:** `classes/queue/SendCapiEvent.php:62-66`
**Issue:** The docblock states "1 initial + 3 backoffs = 4 total tries" and `$backoff = [1, 4, 16]` provides three retry delays — but Laravel's `$tries` is the **total attempt count**, so `$tries = 3` yields 3 attempts with only the `1` and `4` second delays; the `16` is never used. Behavior deviates from the documented retry design (transient Meta outages get one fewer retry and no long backoff), and the README's "retries per the job's `$tries` and `$backoff` schedule" inherits the ambiguity.
**Fix:** `public int $tries = 4;` (or, if 3 attempts is intended, correct the comment and trim `$backoff` to `[1, 4]`).

### WR-06: Queued job serializes the live subject model — subject deleted before the worker runs loses the event outside the dead-letter system

**File:** `classes/queue/SendCapiEvent.php:49-76`
**Issue:** The job uses `SerializesModels` with `public readonly object $obSubject`, which for Eloquent subjects stores a `ModelIdentifier` and re-fetches on the worker. `CartPosition` rows are routinely deleted (checkout converts the cart, user clears it). If that happens before the queue drains, unserialization throws `ModelNotFoundException` **before** `handle()` — the job instance never exists, so `failed()`/`writeFailedEvent()`/`metapixel.event.dead_letter` never run and the AddToCart event vanishes into the framework `failed_jobs` table, invisible to the plugin's FailedEvents replay UI. The payload is already fully pre-built at dispatch time; the live model is only needed for fence keys and hook context.
**Fix:** Either set `public $deleteWhenMissingModels = true` with an explicit decision that pre-conversion AddToCart loss is acceptable, or (better) stop serializing the live model: capture `subject_type`/`subject_id`/`site_id`/`secret_key` at dispatch time (the adapter is already resolvable by class) and pass a lightweight DTO, keeping the dead-letter path reachable.

### WR-07: `fireMetaAddToCartPixel` has no error handler — October's default AJAX error alert surfaces pixel failures to the shopper

**File:** `../../../themes/logingrupa-naisstore/partials/shared/controls/add-to-cart-control.js:101-110`
**Issue:** `$.request('Metapixel::onMarkAddToCart', { data, success })` supplies only a `success` callback. The October AJAX framework's default error handler shows an `alert()` with the response body on any non-2xx. The endpoint deliberately returns 429 (shared 30/min per IP+session limiter with `onFireEvent`) and 500 on internal failure — so a rate-limited or failing *tracking* call pops a raw error dialog in the middle of add-to-cart. Observability must never degrade shopper UX.
**Fix:**
```js
$.request('Metapixel::onMarkAddToCart', {
  data: { offer_id: offerId },
  success: function (resp) { /* unchanged */ },
  error: function () { /* silent: tracking failure must not interrupt checkout */ },
});
```

### WR-08: Google AddToCart fires on click, before the cart add succeeds

**File:** `../../../themes/logingrupa-naisstore/partials/shared/controls/add-to-cart-control.js:44-46`
**Issue:** `onAddToCartClick` calls `trackGoogleAddToCart(selectedOption)` immediately after kicking off the (unawaited) `addOfferToCart(...)` promise. The Google event is therefore recorded even when `Cart::onAdd` returns `status: false` or the request throws, while the Meta pixel (`fireMetaAddToCartPixel`) correctly fires only inside the success branch. Result: systematic Google-vs-Meta AddToCart count skew and Google over-counting on failed adds.
**Fix:** Move the call into the success branch of `addOfferToCart`, next to `fireMetaAddToCartPixel`:
```js
if (response && response.status) {
  showButtonPopover(button, 'Item added to cart');
  refreshCartHeader();
  trackGoogleAddToCart(selectedOption);
  fireMetaAddToCartPixel(selectedOption.value);
}
```

### WR-09: Shipped contract-test base extends a dev-only class — the documented third-party testing flow fatals on a Composer install

**File:** `classes/testing/EventSubjectAdapterContractTestCase.php:10,41` and `docs/CUSTOM-ADAPTERS.md:304-326`
**Issue:** `EventSubjectAdapterContractTestCase` lives in the shipped `classes/` tree (main `autoload` PSR-4) but extends `Logingrupa\Metapixel\Tests\MetapixelTestCase`, which is registered only under `autoload-dev` in composer.json. For any third party who installed the plugin as a dependency — the exact audience of CUSTOM-ADAPTERS.md's "Testing your adapter" section ("`pest tests/MallOrderAdapterContractTest.php` exits 0 → your adapter satisfies the marketplace contract") — the parent class is not autoloadable and the documented test fatals with "Class ... MetapixelTestCase not found". The class docblock admits this is deferred ("Revisit when first third-party adapter ships"), but the marketplace doc presents it as working today with a worked Mall example.
**Fix:** Either (a) make the harness genuinely consumable — move/duplicate the minimal `MetapixelTestCase` bootstrap into the shipped autoload namespace, or (b) add an explicit caveat in `docs/CUSTOM-ADAPTERS.md` §Testing that the contract harness currently requires a dev checkout of the plugin (composer `--prefer-source` + dev autoload), so the doc's success claim matches reality.

## Info

### IN-01: Orphaned docblock in SendCapiEvent — `writeFailedEvent` documentation is attached to `withTestEventCode`

**File:** `classes/queue/SendCapiEvent.php:249-263`
**Issue:** Two consecutive docblocks precede `withTestEventCode()`: the first (lines 249-254) documents `writeFailedEvent`, the second documents `withTestEventCode`. `writeFailedEvent` (line 278) itself has no docblock.
**Fix:** Move the first docblock down to sit directly above `writeFailedEvent`.

### IN-02: Dead re-validation of the event name inside `dispatchViaAdapter`

**File:** `classes/adapter/theme/ThemeAjaxHandler.php:222-225`
**Issue:** `onBeforeRun` already returns 422 for disallowed names (line 87) before delegating, so the second `isAllowedEventName` check is unreachable dead code (and `loadSubject` DB work at line 217 happens before it, inverting the cheap-check-first order it implies).
**Fix:** Remove the duplicate check, or hoist it above `loadSubject` with a comment if defense-in-depth is intended.

### IN-03: `dispatchForOfferSwitch` docblock overstates the boundary's error translation; subject loaded twice

**File:** `classes/event/adapter/shopaholic/ProductPageWatcher.php:155-158` (and `ThemeAjaxHandler.php:217,235`)
**Issue:** The docblock claims "The AJAX boundary translates each throw into a 422/404/500 JsonResponse" — in reality every watcher throw reaches `onBeforeRun`'s generic `Throwable` catch and becomes a bare 500. Also `dispatchViaAdapter` calls `loadSubject` (line 217) and then `dispatchForOfferSwitch` re-loads the same product (watcher line 178) — a redundant DB round trip per switch.
**Fix:** Correct the docblock (all throws → 500 today), or catch the watcher's `InvalidArgumentException`/`RuntimeException` in `dispatchViaAdapter` and map them to 422/404. Consider passing the already-loaded subject into the watcher.

### IN-04: Offer-switch pushes to ThemeEventCollector in an AJAX request that never flushes it

**File:** `classes/event/adapter/shopaholic/ProductPageWatcher.php:238-246`
**Issue:** `dispatchForOfferSwitch` runs only from the AJAX boundary, whose `JsonResponse` short-circuits the CMS lifecycle — `cms.page.beforeRenderPage` (the deferred-flush drain) never fires, so the pushed collector entry is dead state discarded with the request. The browser event is delivered via the returned script instead. If a future change ever flushes the collector during AJAX partial updates, this entry would double-fire the browser ViewContent.
**Fix:** Drop the collector push from the offer-switch path (the JsonResponse script is the delivery mechanism), or document why the push is intentionally kept.

### IN-05: Contract invariants 03/04 are weaker than their names claim

**File:** `classes/testing/EventSubjectAdapterContractTestCase.php:81-102`
**Issue:** `test_invariant_03_site_id_deterministic_across_set_site_context` never changes site context — it calls `getSiteId` twice under identical conditions, so a request/site-context-dependent adapter passes. `test_invariant_04` asserts only the `?int` return type; it cannot detect a `Request::` read (acknowledged in the message, but the test name promises more).
**Fix:** In invariant 03, mutate the active site context (e.g. `Site::withContext(...)` / swap the SiteManager fake) between the two calls; rename invariant 04 to reflect that the real enforcement is the static PHPStan disallowed-calls rule.

### IN-06: Doc says user-data keys "MUST be null (do not omit)" but the contract test permits omission

**File:** `docs/CUSTOM-ADAPTERS.md:59` vs `classes/testing/EventSubjectAdapterContractTestCase.php:125-145`
**Issue:** Invariant 07 only asserts returned keys are a subset of the 13-key set with `string|null` values — an adapter omitting keys passes the harness while violating the documented MUST. Doc and gate disagree on the contract.
**Fix:** Either assert all 13 keys are present in invariant 07, or soften the doc to "missing keys may be omitted or null".

### IN-07: Minimal AcmeCart example calls undefined `$this->buildPayload()` inside `Plugin::boot()` closures

**File:** `docs/CUSTOM-ADAPTERS.md:108-115`
**Issue:** In the registration snippet, `$this` inside the `bindEvent` closure is the `Plugin` instance, and no `buildPayload` method is shown or defined anywhere — verbatim copy-paste throws `Error: Call to undefined method` on the first paid-status save. The reader has no pointer to what a payload builder for their cart looks like (PayloadBuilder usage appears nowhere in this doc).
**Fix:** Show a one-line `PayloadBuilder` usage (mirroring `SendCapiEvent` callers) or annotate the call: `// buildPayload(): your wrapper around Logingrupa\Metapixel\Classes\Meta\PayloadBuilder::buildEventPayload(...)`.

### IN-08: Shipped source docblocks reference planning-only artifacts that won't exist in the marketplace package

**File:** `classes/adapter/shopaholic/ShopaholicCartPositionAdapter.php:16-23`, `classes/adapter/theme/ThemeAjaxHandler.php:190-193`, `Plugin.php:82`
**Issue:** Public-shipped docblocks cite "CONTEXT.md D-15/D-16", "CONTEXT.md Claude's Discretion", and "RESEARCH §10" — files that live under `.planning/` and are not distributed. For marketplace consumers these are dangling references; the plugin CLAUDE.md's own rule is that workflow refs belong in commits/PRs, not source. (The `NoV1xReferencesTest` gate only bans "Phase N" markers, so these slip through.)
**Fix:** Replace with self-contained rationale (the D-15/D-16 exception text is already inlined; drop the file citations) or widen the D-23 gate regex to catch `CONTEXT.md`/`RESEARCH §` references.

---

_Reviewed: 2026-07-03T10:26:22Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
