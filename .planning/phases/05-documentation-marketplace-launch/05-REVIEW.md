---
phase: 05-documentation-marketplace-launch
reviewed: 2026-07-03T12:20:25Z
depth: standard
files_reviewed: 25
files_reviewed_list:
  - classes/adapter/shopaholic/ShopaholicCartPositionAdapter.php
  - classes/adapter/shopaholic/ShopaholicSettingsOptions.php
  - classes/adapter/theme/ThemeAjaxHandler.php
  - classes/adapter/theme/ThemeAjaxRequestReader.php
  - classes/event/adapter/shopaholic/CartPositionWatcher.php
  - classes/event/adapter/shopaholic/ProductPageWatcher.php
  - classes/meta/AddToCartPixelResult.php
  - classes/meta/PayloadBuilder.php
  - classes/queue/SendCapiEvent.php
  - classes/testing/EventSubjectAdapterContractTestCase.php
  - components/PixelHead.php
  - docs/CUSTOM-ADAPTERS.md
  - tests/Feature/Adapter/Shopaholic/CartPositionWatcherBrowserPixelTest.php
  - tests/Feature/Adapter/Shopaholic/ProductPageWatcherTest.php
  - tests/Feature/Adapter/Theme/ThemeAjaxHandlerMarkAddToCartTest.php
  - tests/Feature/Adapter/Theme/ThemeAjaxHandlerSubjectTypeTest.php
  - tests/Feature/Adapter/Theme/ThemeAjaxRequestReaderTest.php
  - tests/Feature/Components/PixelHeadDeferredFlushTest.php
  - tests/Feature/Docs/AssetsExistTest.php
  - tests/Feature/Docs/CustomAdaptersStructureTest.php
  - tests/Feature/Docs/NoV1xReferencesTest.php
  - tests/Feature/Docs/ReadmeStructureTest.php
  - tests/Feature/Plugin/PluginYamlSanityTest.php
  - tests/Unit/Meta/PayloadBuilderTest.php
  - ../../../themes/logingrupa-naisstore/partials/shared/controls/add-to-cart-control.js
findings:
  critical: 2
  warning: 9
  info: 8
  total: 19
status: issues_found
---

# Phase 05: Code Review Report

**Reviewed:** 2026-07-03T12:20:25Z
**Depth:** standard
**Files Reviewed:** 25
**Status:** issues_found

## Summary

Re-review after gap-closure plan 05-18 (ThemeAjaxHandler refactor with new
ThemeAjaxRequestReader collaborator, ProductPageWatcher::dispatchForOfferSwitch
and PixelHead::flushDeferredFromController extraction, plus focused coverage
tests). The refactor itself is clean — the extracted ThemeAjaxRequestReader is
correct, stateless, and well-tested; PixelHead's deferred-flush split matches
its documented lifecycle contract; PayloadBuilder and the contract test base
are sound. Doc-gate tests (AssetsExist, ReadmeStructure, NoV1xReferences,
PluginYamlSanity, CustomAdaptersStructure) are hermetic and correct.

However, tracing the reviewed AJAX/queue paths end-to-end surfaced two
Critical defects: (1) the SendCapiEvent retry contract is defeated by the
EventLog race-fence — any transient Meta/API failure permanently and silently
drops the event with no dead-letter; (2) the `Metapixel::onFireEvent` theme
path passes the raw client payload into ThemeActionEvent, letting any
unauthenticated visitor inject arbitrary CAPI identity fields (`em`, `ph`,
`external_id`, …), a `secret_key`, and a `site_id` into server-signed CAPI
events — and conversely never captures server-side IP/UA/cookie user_data, so
legitimate theme events ship with empty user_data that Meta rejects.

Nine warnings cover multisite site-resolution drift in the cart adapter,
Graph-payload contamination, event-name mismatch on the hybrid path,
fabricated content_ids, an orphaned collector push, a queue-latency race that
drops the browser AddToCart pixel, a missing PluginGuard on the fire-event
path, premature Google tracking in the theme JS, and a copy-paste-fatal doc
example.

## Critical Issues

### CR-01: Transient CAPI failures permanently and silently drop events — retry defeated by race fence

**File:** `classes/queue/SendCapiEvent.php:105-132` (with `classes/helper/EventLogWriter.php:68-83`)
**Issue:** `handle()` writes the EventLog race-fence row (`EventLogWriter::record`, an `insertOrIgnore`) BEFORE calling `MetaClient::sendForPixel`. On a `MetaApiTransientException` (Meta 5xx, network timeout, rate limit) the exception is rethrown so Laravel schedules a retry — but on attempt 2, `record()` collides with the row written on attempt 1, returns `false`, and `handle()` returns cleanly ("peer won"). The retry is a no-op that Laravel counts as success:
- The event is never delivered to Meta.
- `failed()` never runs (the job did not exhaust `$tries` with exceptions), so no `FailedEvent` row and no `metapixel.event.dead_letter` hook fires.
- `$tries = 3` / `$backoff = [1, 4, 16]` are effectively dead configuration for the transient path.

This directly contradicts the documented contract in `docs/CUSTOM-ADAPTERS.md` ("On transient HTTP failure, Laravel retries per the job's `$tries` and `$backoff` schedule") and is silent data loss for every event that hits a transient failure — the exact class of failure retries exist for.
**Fix:** Make the fence retry-aware: treat a fence collision as "won" when the existing row carries the SAME `event_id` (a retry of self, not a duplicate peer). E.g. in `SendCapiEvent::handle()`:
```php
if (! $bWonRaceFence && ! EventLogWriter::ownsRow($this->readEventId(), $this->sEventName, 'capi', $this->obSubject, $iSiteId)) {
    return; // genuine duplicate peer
}
// same event_id row exists → this is our own retry, proceed to send
```
with `ownsRow()` doing a `SELECT event_id` on the fence key and comparing. Alternatively, track delivery separately (e.g. a `delivered_at` column set after a 2xx) and only skip the send when the row is marked delivered.

### CR-02: onFireEvent theme path accepts client-controlled CAPI identity, secret_key, and site_id; never captures server-side user_data

**File:** `classes/adapter/theme/ThemeAjaxHandler.php:107-126` (with `classes/adapter/theme/ThemeActionEvent.php:44`, `classes/adapter/theme/ThemeActionAdapter.php:63-79,102-112`)
**Issue:** `handleFireEvent()` builds `ThemeActionEvent::fromArray($arData)` from the RAW client AJAX payload. `fromArray` stores the entire array as `arPayload`, and `ThemeActionAdapter` then reads from it:
- `getUserData()` reads all 13 Meta CAPI identity keys (`em`, `ph`, `fn`, `ln`, `external_id`, `fbp`, `fbc`, `client_ip_address`, `client_user_agent`, …) straight from `arPayload`. Any unauthenticated visitor can POST `data[em]=victim@example.com` and have the plugin hash it (UserDataHasher) and submit it in a server-signed CAPI event — arbitrary identity injection / conversion-attribution pollution of the operator's Meta dataset, throttled only by the 30/min rate limit.
- `getSiteId()` honors client-supplied `data[site_id]`, so on a multisite install the attacker selects which site's `pixel_id`/`capi_access_token` (`Settings::lookupForSite`) the event fires under, and which site's fence partition the EventLog row lands in.
- `getSecretKey()` honors client-supplied `data[secret_key]`, persisting attacker data into the EventLog `secret_key` column.

The inverse defect compounds it: unlike `PixelHead::emitBasePixel()` (which calls `collectRequestUserData()` server-side) and the watchers (which use `injectRequestUserData`), `handleFireEvent()` injects NO server-derived `client_ip_address`/`client_user_agent`/`_fbp`/`_fbc`. A legitimate theme JS call that sends only `name` + `action_key` produces a CAPI event with all-null user_data — which Meta rejects (HTTP 400 subcode 2804050 per PixelHead's own docblock), permanently dead-lettering every honest theme-path event.
**Fix:** In `handleFireEvent()`, before `fromArray`: (1) strip the identity/config keys from the client payload — allow only `name`, `action_key`, and (for hybrid) `subject_type`/`subject_id`/`offer_id`/`context`; (2) merge server-captured user_data, mirroring PixelHead:
```php
$arSafe = array_intersect_key($arData, array_flip(['name', 'action_key']));
$arSafe = array_merge($arSafe, [
    'client_ip_address' => Request::ip(),
    'client_user_agent' => Request::userAgent(),
    'fbp' => Cookie::get('_fbp'),
    'fbc' => Cookie::get('_fbc'),
    'site_id' => Site::getSiteIdFromContext(),
]);
$obEvent = ThemeActionEvent::fromArray($arSafe);
```
(ThemeAjaxHandler lives in `classes/adapter/theme/`, which is already the documented D-16 exclusion zone for the Request/Site ban, so this is permitted there — or delegate capture to a `components/`-layer helper.)

## Warnings

### WR-01: Cart adapter's request-context site fallback actually executes in the queue worker — contradicting its own safety rationale

**File:** `classes/adapter/shopaholic/ShopaholicCartPositionAdapter.php:44-55` (with `classes/queue/SendCapiEvent.php:103`, `classes/event/adapter/shopaholic/CartPositionWatcher.php:50-57`)
**Issue:** The docblock justifies the `Site::getSiteIdFromContext()` fallback with "Cart events fire in-request by definition … never from queue worker rehydration". That is false for the CAPI path: `SendCapiEvent::handle()` calls `SiteResolver::forSubject($this->obSubject, $obAdapter)` → `getSiteId()` inside the worker (CLI context). Since the docblock itself states Lovata `Cart` has no native `site_id` column, the fallback is the NORMAL path, and in the worker it resolves to the CLI default/primary site. Consequences on any multisite single-DB install: (a) `Settings::lookupForSite()` resolves the wrong (primary) site's pixel credentials for events originating on non-primary sites; (b) the EventLog fence row gets the worker-context site_id while `handleUpdated()`'s dedup query (run in-request, resolving the request-context site) filters on a DIFFERENT site_id — the "already logged" check never matches, so every qty-bump update re-dispatches a redundant AddToCart job (absorbed only by the worker-side fence).
**Fix:** Bake the site_id into the dispatched subject/payload at request time (mirroring ProductPageWatcher's `makeDispatchEvent` "site_id is baked from the subject" pattern) — e.g. have `CartPositionWatcher::dispatchAddToCart` resolve site_id in-request and route through a subject that carries it, and make `handleUpdated`'s dedup query use the same baked value. At minimum, correct the docblock — the current text documents an invariant the code does not have.

### WR-02: Generic hybrid dispatch injects top-level `action_key` into the outgoing Graph API payload

**File:** `classes/adapter/theme/ThemeAjaxHandler.php:285-290` (with `classes/queue/SendCapiEvent.php:119-123`)
**Issue:** `dispatchGenericAdapter()` sets `$arPayload['action_key'] = $sWireActionKey.':'.$sEventId` at the TOP level of the CAPI envelope. `SendCapiEvent::handle()` sends `$this->arPayload` (plus `test_event_code`) verbatim to `MetaClient::sendForPixel`, so the internal routing key is transmitted to Meta as an unknown top-level Graph parameter. Graph API commonly rejects unknown POST parameters with `(#100)` — a permanent error that would dead-letter every generic-alias hybrid event; even if tolerated, it leaks internal routing data to a third party. The comment calls the append "observability-only", but nothing strips it before send. Everywhere else `action_key` lives in `custom_data` (see `PayloadBuilderTest::test_event_extras_merge_into_custom_data`) or on the subject, never top-level.
**Fix:** Pass it through the builder's extras so it lands in `custom_data`:
```php
$arPayload = (new PayloadBuilder(new UserDataHasher))->buildEventPayload(
    $sName, $obAdapter, $obSubject, $obAdapter->getValueResolver($obSubject),
    $sEventId, $iEventTime,
    $sWireActionKey !== '' ? ['action_key' => $sWireActionKey.':'.$sEventId] : [],
);
```
or strip non-Graph top-level keys inside `SendCapiEvent::withTestEventCode`/`MetaClient` before send.

### WR-03: Hybrid AJAX path ignores `getSupportedEvents()`; shopaholic branch echoes client-chosen event name over a hard-coded ViewContent CAPI dispatch

**File:** `classes/adapter/theme/ThemeAjaxHandler.php:228-259`
**Issue:** `dispatchViaAdapter()` validates the event name only against the global allowlist (META_STANDARD ∪ custom names) — the adapter's declared event-channel matrix (`getSupportedEvents()`, documented in CUSTOM-ADAPTERS.md as contract surface) is never consulted. Worse, in `dispatchShopaholicOfferSwitch()` the delegate `ProductPageWatcher::dispatchForOfferSwitch()` always dispatches CAPI `'ViewContent'`, while the returned browser script is built with the CLIENT-SUPPLIED `$sName`. A request with `name=Purchase&subject_type=shopaholic.product` yields a browser `fbq('track', 'Purchase', …, {eventID: X})` paired with a server CAPI `ViewContent` carrying the same event_id — Meta dedup only pairs identical event names, so the visitor mints an unmatched, server-blessed Purchase browser event with real catalog custom_data.
**Fix:** In `dispatchShopaholicOfferSwitch()`, reject `$sName !== 'ViewContent'` with 422 (or pass `$sName` down and have the watcher dispatch it). In `dispatchViaAdapter()`, add:
```php
if (! array_key_exists($sName, $obAdapter->getSupportedEvents())) {
    return new JsonResponse(['error' => 'event_name not supported by subject_type'], 422);
}
```

### WR-04: dispatchForOfferSwitch fabricates content_ids for offer ids that do not belong to the product

**File:** `classes/event/adapter/shopaholic/ProductPageWatcher.php:252-274`
**Issue:** `resolveOfferContentData()` unconditionally forces `content_ids = ['SKU-'.$iProductId.'-'.$iOfferId]` from the raw client-supplied `offer_id` and only falls back to product-level name/value when `findOffer()` misses. A nonexistent or cross-product offer id therefore produces a CAPI + fbq event advertising a `SKU-{pid}-{oid}` that does not exist in the Facebook Catalog feed (the plugin's own locked decision requires content_ids to match the feed exporter), degrading catalog-match quality with attacker- or bug-supplied ids.
**Fix:** Treat a `findOffer()` miss as a hard failure (Tiger-Style — this method already throws for invalid input):
```php
$obOffer = $this->findOffer($obProduct, $iOfferId);
if ($obOffer === null) {
    throw new \RuntimeException('offer does not belong to product');
}
```
letting the AJAX boundary return its error response, or fall back to the product-level `resolveContentIds()` output instead of the fabricated SKU.

### WR-05: Offer-switch collector push is orphaned — dead write with latent double-emission risk

**File:** `classes/event/adapter/shopaholic/ProductPageWatcher.php:208-216`
**Issue:** `dispatchForOfferSwitch()` is only reachable from the AJAX path (`ThemeAjaxHandler::dispatchShopaholicOfferSwitch`), which returns a `JsonResponse` from `cms.ajax.beforeRunHandler` — October short-circuits the request, so `cms.page.beforeRenderPage` (the only consumer, `Plugin.php:104` → `flushDeferredFromController`) never fires. The `ThemeEventCollector::push()` here is therefore never flushed in production: the browser script is already delivered via the JSON response. It is dead weight today, and a double-emission bug tomorrow — if the flush listener ever runs in the same request (or a future caller invokes this method during page render), the same event_id renders twice. Test coverage even asserts the orphan exists ("offer-switch leaves exactly 1 new entry in the collector"), cementing the dead behavior.
**Fix:** Remove the collector push from `dispatchForOfferSwitch()` (the OfferSwitchResult already carries everything the AJAX boundary needs) and drop the corresponding assertion in `ProductPageWatcherTest::test_offer_switch_ajax_re_fires_viewcontent_with_new_event_id_and_offer_sku`; or guard it explicitly with `if (RequestKind::isPageRender())`.

### WR-06: Browser AddToCart pixel races the queue worker — silently dropped on async queue drivers

**File:** `classes/event/adapter/shopaholic/CartPositionWatcher.php:102-133` (with `classes/adapter/theme/ThemeAjaxHandler.php:161-163`)
**Issue:** `resolveBrowserPixel()` reads the `channel='capi'` EventLog row, which is written only when the queue worker executes `SendCapiEvent::handle()` (→ `EventLogWriter::record`). The theme JS fires `Metapixel::onMarkAddToCart` immediately after `Cart::onAdd` succeeds — typically milliseconds later. On any async queue driver (database/redis), the capi row will usually not exist yet, `findCapiAddToCartRow()` returns null, and the handler returns `{event_id: null, script: ''}`: the browser AddToCart pixel is silently dropped for most adds, with no log and no retry. Only a `sync` queue makes the happy path hold in production.
**Fix:** Decouple the browser pixel from worker completion — have `CartPositionWatcher::dispatchAddToCart` persist the generated event_id + custom_data at request time (e.g. write the `channel='pixel'` EventLog row up-front in-request, or cache event_id/custom_data keyed by cart position for a short TTL), and have `resolveBrowserPixel` read that instead of the worker-written capi row. At minimum document the sync-queue requirement and add a `Log::info` on the miss so operators can see the drop rate.

### WR-07: handleFireEvent has no PluginGuard — disabled plugin still queues CAPI jobs and returns fbq scripts

**File:** `classes/adapter/theme/ThemeAjaxHandler.php:88-139` (with `Plugin.php:114`)
**Issue:** `Event::subscribe(ThemeAjaxHandler::class)` is unconditional at boot, and unlike `markAddToCartPixel` (guarded inside `resolveBrowserPixel`) and `dispatchForOfferSwitch` (throws when disabled), the plain theme-action branch of `handleFireEvent()` never checks `PluginGuard::isDisabled()`. With an empty `pixel_id`, every `Metapixel::onFireEvent` call still: dispatches a `SendCapiEvent` job that is guaranteed to dead-letter (`MissingPixelConfigException` → a FailedEvent row per call), and returns an executable `fbq(...)` script to a page whose base pixel was never rendered — `fbq` is undefined there, so the injected script throws a ReferenceError in the visitor's console. Unbounded FailedEvent growth from a misconfigured install violates the PluginGuard pattern's purpose.
**Fix:** Add at the top of `handleFireEvent()` (dispatchViaAdapter inherits it):
```php
if (PluginGuard::isDisabled()) {
    return new JsonResponse(['event_id' => null, 'script' => ''], 200);
}
```

### WR-08: Google AddToCart tracked before the cart add succeeds

**File:** `../../../themes/logingrupa-naisstore/partials/shared/controls/add-to-cart-control.js:44-46`
**Issue:** `onAddToCartClick()` calls `trackGoogleAddToCart(selectedOption)` synchronously right after kicking off the (unawaited) `addOfferToCart(...)` promise. The Google AddToCart event therefore fires on every click — including failed adds (out of stock, validation error, network failure) — while the Meta pixel (`fireMetaAddToCartPixel`) correctly fires only inside the `response.status` success branch. Google and Meta AddToCart counts will diverge, and Google conversions include adds that never happened.
**Fix:** Move the call into the success branch of `addOfferToCart`:
```js
if (response && response.status) {
  showButtonPopover(button, 'Item added to cart');
  refreshCartHeader();
  fireMetaAddToCartPixel(selectedOption.value);
  trackGoogleAddToCart(selectedOption);
}
```

### WR-09: CUSTOM-ADAPTERS.md minimal example calls an undefined `$this->buildPayload()` — copy-paste fatal

**File:** `docs/CUSTOM-ADAPTERS.md:100-117`
**Issue:** The "Minimal example" Plugin.php snippet dispatches `SendCapiEvent::dispatch('Purchase', $this->buildPayload($obCart), $obCart, AcmeCartAdapter::class)`. `buildPayload()` is not defined anywhere in the document, does not exist on `PluginBase`, and inside the `bindEvent` closure `$this` is not even reliably the Plugin instance (October rebinds model-event closures). A third-party developer following the guide — its entire purpose — copies a snippet that fatals with "Call to undefined method". The guide also never shows how to construct `$arPayload` at all: `PayloadBuilder`, the required input of the "only public entry point" (*Trigger dispatch* section), appears nowhere in the doc.
**Fix:** Replace the phantom helper with a runnable payload build:
```php
$obAdapter = new AcmeCartAdapter;
$arPayload = (new PayloadBuilder(new UserDataHasher))->buildEventPayload(
    'Purchase', $obAdapter, $obCart, $obAdapter->getValueResolver($obCart),
    Uuid::uuid4()->toString(), time(), [],
);
SendCapiEvent::dispatch('Purchase', $arPayload, $obCart, AcmeCartAdapter::class);
```
and add a short "Build the payload" subsection introducing `PayloadBuilder` + `UserDataHasher`.

## Info

### IN-01: `$tries` docblock contradicts the code — third backoff entry unreachable

**File:** `classes/queue/SendCapiEvent.php:62-66`
**Issue:** The comment says "1 initial + 3 backoffs = 4 total tries", but `$tries = 3` means 3 total attempts in Laravel; `backoff[2] = 16` is never used (only two delays occur before `failed()` runs).
**Fix:** Either set `public int $tries = 4;` to match the comment, or fix the comment to "3 total attempts" and trim `$backoff` to `[1, 4]`.

### IN-02: Orphaned docblock stacked above withTestEventCode; writeFailedEvent left undocumented

**File:** `classes/queue/SendCapiEvent.php:249-263,278`
**Issue:** The "Persist a FailedEvent row…" docblock (lines 249-254) is immediately followed by a second docblock and then `withTestEventCode()` — the first block is dangling and belongs to `writeFailedEvent()` (line 278), which now has no docblock at all.
**Fix:** Move the FailedEvent docblock down to directly precede `writeFailedEvent()`.

### IN-03: resolveBrowserPixel docblock claims "never throws" but the method has no try/catch

**File:** `classes/event/adapter/shopaholic/CartPositionWatcher.php:100-104`
**Issue:** "Fail-safe: returns null (never throws) on every miss" — but `CartProcessor::instance()`, the CartPosition query, and the DB reads can all throw; the method relies on the AJAX boundary's catch (which returns 500, not the documented null).
**Fix:** Reword to "returns null on every resolution miss; infrastructure failures propagate to the AJAX boundary", or wrap in try/catch returning null if that is the intended contract.

### IN-04: PixelHead::extractCustomData strip list omits `offer_id`

**File:** `components/PixelHead.php:292-299`
**Issue:** The fallthrough strip list removes `name`, `action_key`, `also_dispatch_capi`, `site_id`, `event_id`, `product_id` — but not `offer_id`, which the offer-switch collector push includes (`ProductPageWatcher.php:215`). If such an event is ever flushed (see WR-05), `offer_id` leaks into the browser fbq custom_data as a non-standard field.
**Fix:** Add `'offer_id' => true` to the `array_diff_key` filter.

### IN-05: Planning-artifact references in shipped docblocks

**File:** `classes/adapter/theme/ThemeAjaxHandler.php:196-199,264-265`; `classes/adapter/shopaholic/ShopaholicCartPositionAdapter.php:16,20`
**Issue:** Docblocks on public marketplace surface reference internal planning artifacts ("CONTEXT.md Claude's Discretion", "CONTEXT.md D-15"). The plugin's no-comment-pollution rule bans workflow markers in source; `.planning/` will not ship with the marketplace package, making these references dangling for third parties.
**Fix:** Restate the decision content inline and drop the CONTEXT.md pointers.

### IN-06: Dead ReflectionProperty setup in offer-switch test

**File:** `tests/Feature/Adapter/Shopaholic/ProductPageWatcherTest.php:392-395`
**Issue:** `$obReflect = new ReflectionProperty(Offer::class, 'fSavedPrice'); $obReflect->setAccessible(true);` is never used — the actual pre-seeding happens inside `makeOffer()`. Dead code with a misleading comment above it.
**Fix:** Delete lines 392-395 (comment + reflection setup).

### IN-07: Contract-test invariants 03/04 assert less than their names claim

**File:** `classes/testing/EventSubjectAdapterContractTestCase.php:81-102`
**Issue:** `test_invariant_03_site_id_deterministic_across_set_site_context` never varies any site context — it just calls `getSiteId` twice back-to-back. `test_invariant_04_get_site_id_reads_no_request_or_site_manager` asserts only the return type; it cannot detect Request/SiteManager reads (the docblock admits phpstan anchors that statically — but third-party adapters, the harness's marketplace audience, are NOT covered by this repo's phpstan config, so for them the invariant is unenforced).
**Fix:** In invariant 03, flip the active site between the two calls before asserting equality; rename invariant 04 to reflect what it asserts, or strengthen it (swap the bound request instance and assert the result is unchanged).

### IN-08: Status dropdown pluck bypasses translation accessors

**File:** `classes/adapter/shopaholic/ShopaholicSettingsOptions.php:30`
**Issue:** `Status::orderBy('sort_order')->pluck('name', 'code')` reads the raw column via the query builder, bypassing Lovata's multilingual (RainLab.Translate) accessor on `name` — backend operators on non-default locales see untranslated status names; duplicate `code` values silently collapse to the last row.
**Fix:** Hydrate models so pluck goes through the accessor: `Status::orderBy('sort_order')->get()->pluck('name', 'code')->all();`

---

_Reviewed: 2026-07-03T12:20:25Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
