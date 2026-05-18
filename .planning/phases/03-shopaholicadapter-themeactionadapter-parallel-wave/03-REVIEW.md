---
phase: 03-shopaholicadapter-themeactionadapter-parallel-wave
reviewed: 2026-05-18T19:30:00Z
depth: standard
files_reviewed: 25
files_reviewed_list:
  - classes/adapter/shopaholic/ShopaholicCartPositionAdapter.php
  - classes/adapter/shopaholic/ShopaholicCartPositionValueResolver.php
  - classes/adapter/shopaholic/ShopaholicOrderAdapter.php
  - classes/adapter/shopaholic/ShopaholicOrderValueResolver.php
  - classes/adapter/shopaholic/ShopaholicSettingsOptions.php
  - classes/adapter/theme/ThemeActionAdapter.php
  - classes/adapter/theme/ThemeActionEvent.php
  - classes/adapter/theme/ThemeActionValueResolver.php
  - classes/adapter/theme/ThemeAjaxHandler.php
  - classes/adapter/theme/ThemeEventCollector.php
  - classes/event/adapter/shopaholic/CartPositionWatcher.php
  - classes/event/adapter/shopaholic/OrderStatusWatcher.php
  - classes/exception/OrderHasNoCurrencyException.php
  - classes/helper/EventLogWriter.php
  - classes/queue/SendCapiEvent.php
  - components/EventPixel.php
  - components/PixelHead.php
  - components/eventpixel/default.htm
  - components/pixelhead/default.htm
  - console/PurgeEventLog.php
  - models/EventLog.php
  - models/Settings.php
  - models/settings/fields.yaml
  - updates/AddPayloadToMetapixelEventLogTable.php
  - Plugin.php
findings:
  critical: 4
  warning: 9
  info: 6
  total: 19
status: issues_found
---

# Phase 3: Code Review Report

**Reviewed:** 2026-05-18T19:30:00Z
**Depth:** standard
**Files Reviewed:** 25
**Status:** issues_found

## Summary

Phase 3 ships two production adapters (Shopaholic Purchase/AddToCart, Theme generic) against the Phase 2 backbone. The CONTEXT.md locks (D-06..D-28) are largely honored — JSON-escape flags on inline `<script>` emission are correct, the AdapterRegistry boundary integrity holds (no Lovata imports outside whitelisted dirs), the EventLogWriter race-fence shape is preserved, no `assert()` / `@phpstan-ignore` markers leak into source, and Hungarian notation is consistent.

However, the review surfaced four correctness BLOCKERS:

1. `ShopaholicOrderValueResolver::buildContentId` dereferences `$obOffer->product` without a null guard — a deleted/missing Product relation crashes the Purchase payload build (and the surrounding watcher swallows the exception, silently dropping the Meta event).
2. `ShopaholicCartPositionAdapter::getSiteId` traverses `Cart::site_id` but Lovata `Cart` has no `site_id` column — every AddToCart EventLog row writes `site_id = NULL`, which breaks the UNIQUE race-fence dedup (MySQL treats NULL as distinct in UNIQUE indexes).
3. `Settings::beforeSave` stores the sanitized `theme_custom_event_names` as a PHP array, but the field renders as `type: textarea`. On re-edit the textarea cannot stringify an array — the operator sees a broken form and loses the list on the next save.
4. `models/settings/fields.yaml` references five new translation keys (`paid_status_code_label`/`_comment`, `default_currency_code_label`/`_comment`, `theme_custom_event_names_label`/`_comment`) that do not exist in `lang/en/lang.php` or `lang/lv/lang.php` — the Settings UI renders raw `logingrupa.metapixel::lang.settings.fields.X` strings.

Multiple WARNINGS surround the same structural concern: the EventLog UNIQUE race-fence is silently weakened on any path that produces `site_id = NULL` (Cart adapter always, Theme adapter when context is null, Order adapter on single-site installs). Several smaller defects (timing-fragile dedup logic in OrderStatusWatcher, crc32 collision risk on synthetic theme IDs, inconsistent null-guard discipline between the two Shopaholic value resolvers, dead `$obOrder->exists &&` predicate, inaccurate `Settings::set` docblock) are flagged below.

## Critical Issues

### CR-01: Null dereference in `ShopaholicOrderValueResolver::buildContentId`

**File:** `classes/adapter/shopaholic/ShopaholicOrderValueResolver.php:124-132`
**Issue:** `$obOffer->product` is accessed without a null guard. When `product_id` is unset on the Offer (deleted Product, soft-deleted, or row missing), `$obOffer->product` returns `null`. The subsequent `$this->intAttr($obProduct, 'id')` declares `Model $obModel` (non-nullable typehint) and will throw `TypeError`. The next line `$obProduct->offer->count()` would also fatal on null. The `OrderStatusWatcher::handle()` outer try/catch swallows the error and the Purchase event silently disappears. This is inconsistent with `ShopaholicCartPositionValueResolver::buildContentId` (lines 97-108) which correctly uses `productOf()` to null-guard via `getRelationValue('product')`.
**Fix:**
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

private function productOf(Offer $obOffer): ?Product
{
    $mProduct = $obOffer->getRelationValue('product');

    return $mProduct instanceof Product ? $mProduct : null;
}
```
(mirrors the CartPosition resolver). Add `use Lovata\Shopaholic\Models\Product;` at the top.

### CR-02: `ShopaholicCartPositionAdapter::getSiteId` always returns null — race-fence broken

**File:** `classes/adapter/shopaholic/ShopaholicCartPositionAdapter.php:33-39`
**Issue:** The adapter reads `site_id` via `$obPosition->cart->site_id`, but Lovata `Cart` (`plugins/lovata/ordersshopaholic/models/Cart.php`) has no `site_id` column or relation — the model's `$fillable` is `[user_id, email, user_data, property, billing_address, shipping_address]` only. `$mCart->site_id ?? null` always evaluates to `null`. Every AddToCart EventLog row is then written with `site_id = NULL`. MySQL's UNIQUE constraint treats NULL as a distinct value (NULL ≠ NULL in UNIQUE indexes), so the EventLog race-fence `(subject_type, subject_id, event_name, channel, site_id)` no longer dedupes concurrent inserts. Combined with the watcher's TOCTOU pre-check window, two concurrent CartPosition updates can both win the race and double-fire AddToCart to Meta. This defeats the entire D-09 dedup story for the cart path.
**Fix:** Two options, both acceptable:
1. Read site_id from a request-bound source documented as a Cart-specific exception (mirrors D-15 for Theme), OR
2. Add a `site_id` migration + extension on Cart in this plugin's `Plugin::boot()` and populate via the same code path Lovata uses for Order (`Site::getSiteIdFromContext()` at cart-creation time). Document the chosen path in CLAUDE.md "Locked decisions".

Until fixed, the dedup invariant claimed in CONTEXT.md §D-03 ("UNIQUE race-fence on (..., site_id) is the dedup anchor — qty-bump updates do NOT re-fire") is false for any environment where Cart.site_id is unpopulated (which is every supported environment given upstream Lovata schema).

### CR-03: `Settings::beforeSave` corrupts the textarea field on re-save

**File:** `models/Settings.php:52-70`
**Issue:** `beforeSave()` runs `partitionEventNames()` and writes back the result as a PHP array via `setAttribute('theme_custom_event_names', $arClean)`. The form field `theme_custom_event_names` is declared `type: textarea` in `fields.yaml`. On the next render, October's textarea widget attempts to coerce the stored array to a string for the `<textarea>` body. PHP's array-to-string conversion produces the literal string `"Array"` (with a notice), or worse, breaks the form on stricter PHP error levels. Either way: the operator opens settings → sees `Array` or empty → saves again → all custom event names are lost. The data is destructive: the array is JSON-serialized to the `value` column (because SettingModel uses ExpandoModel), so the next read returns the array as written, but the form widget can't display it.
**Fix:** Store the sanitized value back as a newline-joined string (round-trip stability with the textarea widget):
```php
public function beforeSave(): void
{
    $arLines = $this->splitEventNameInput($this->getAttribute('theme_custom_event_names'));
    if ($arLines === null) {
        return;
    }
    [$arClean, $arDropped] = $this->partitionEventNames($arLines);
    $this->setAttribute('theme_custom_event_names', implode("\n", $arClean));

    if ($arDropped !== []) {
        Flash::warning('metapixel: dropped invalid event names: '.implode(', ', $arDropped));
    }
}
```
Then update `getThemeCustomEventNames()` to split-by-newline on read:
```php
public static function getThemeCustomEventNames(): array
{
    $mRaw = self::get('theme_custom_event_names', '');
    if (is_array($mRaw)) {
        $mRaw = implode("\n", $mRaw);
    }
    if (! is_string($mRaw) || $mRaw === '') {
        return [];
    }
    $arLines = preg_split('/\R/', $mRaw) ?: [];
    $arResult = [];
    foreach ($arLines as $sLine) {
        $sLine = trim((string) $sLine);
        if ($sLine !== '' && preg_match('/^[A-Za-z0-9_]{1,50}$/', $sLine) === 1) {
            $arResult[] = $sLine;
        }
    }
    return $arResult;
}
```
The array-on-read passthrough handles existing rows already corrupted.

### CR-04: Settings field labels reference non-existent translation keys

**File:** `models/settings/fields.yaml:17-37`, `lang/en/lang.php`
**Issue:** Five new field labels/comments in `fields.yaml` reference translation keys that do not exist in `lang/en/lang.php`:
- `logingrupa.metapixel::lang.settings.fields.paid_status_code_label`
- `logingrupa.metapixel::lang.settings.fields.paid_status_code_comment`
- `logingrupa.metapixel::lang.settings.fields.default_currency_code_label`
- `logingrupa.metapixel::lang.settings.fields.default_currency_code_comment`
- `logingrupa.metapixel::lang.settings.fields.theme_custom_event_names_label`
- `logingrupa.metapixel::lang.settings.fields.theme_custom_event_names_comment`

The lang file only defines `pixel_id_*`, `capi_access_token_*`, `test_event_code_*`. When October cannot resolve a `::lang.foo.bar` key, it renders the raw key string in the UI. Operators see `logingrupa.metapixel::lang.settings.fields.paid_status_code_label` as the field label — a debug-quality UX leak. CONTEXT.md §D-12 reserves these keys for Phase 4 LANG-01, but the YAML references them today and that ships broken admin UI now.
**Fix:** Either (a) add English placeholder strings for all six keys to `lang/en/lang.php` (and `lang/lv/lang.php`) immediately, or (b) inline English literals in `fields.yaml` for the three new fields and defer the lang keys to Phase 4. Option (a) is safer because the v2.0 install fresh-only constraint means these strings ship to operators right now.

## Warnings

### WR-01: EventLog UNIQUE race-fence broken whenever `site_id` is NULL

**File:** `updates/CreateMetapixelEventLogTable.php:40-43`, `classes/helper/EventLogWriter.php:68-83`
**Issue:** The UNIQUE constraint `(subject_type, subject_id, event_name, channel, site_id)` includes a nullable `site_id` column. MySQL/InnoDB treats NULL as a distinct value in UNIQUE indexes, so `(X, Y, name, capi, NULL)` and `(X, Y, name, capi, NULL)` are both insertable. The race-fence (Phase 2 invariant for D-09) silently breaks whenever site_id is null — which is **every AddToCart event (CR-02)**, every Theme event on single-site installs, and every Order event where Order.site_id is unpopulated. CartPositionWatcher's pre-check helper masks this in sequential code paths but not under concurrency.
**Fix:** Either coerce site_id to a non-null sentinel (e.g., 0 for "no site context") at write time AND in the UNIQUE constraint, or add a generated column expression like `COALESCE(site_id, 0) AS site_id_dedup` and include that in the UNIQUE. MySQL 8.0+ functional indexes are an alternative. Document the choice in CLAUDE.md.

### WR-02: `OrderStatusWatcher::handle` early-return predicate is over-defensive / partially dead

**File:** `classes/event/adapter/shopaholic/OrderStatusWatcher.php:40-42`
**Issue:** The check `if ($obOrder->exists && ! $obOrder->wasChanged('status_id'))` always sees `$obOrder->exists === true` because the watcher is registered against `eloquent.created` and `eloquent.updated`, both of which fire post-save. The `$obOrder->exists &&` clause is dead-weight and obscures the actual semantics. More importantly, on `eloquent.created` for an Order that is born already in the paid status, `wasChanged('status_id')` may be true (Eloquent's `getChanges()` populates for new inserts) — but this is exactly when you DO want to dispatch. The current branch falls through correctly only by accident of Eloquent semantics. The code reads as if `exists=true` ever means "we should suppress dispatch", which is false.
**Fix:** Replace with the actual intent:
```php
// On bare updates that did not touch status_id, skip — only dispatch on a real transition.
if ($obOrder->wasRecentlyCreated === false && ! $obOrder->wasChanged('status_id')) {
    return;
}
```
`wasRecentlyCreated` is the explicit Laravel API for "this is the row from eloquent.created"; on every subsequent `eloquent.updated` it's false. Add an EventLog pre-check (mirror CartPositionWatcher) for symmetry — concurrent admin re-saves of an already-paid Order otherwise spam the queue with jobs that race-fence eventually rejects (extra queue work + extra MetaClient round trips dropped by the race-fence on the worker side, but only after the job runs).

### WR-03: `ThemeActionEvent::iSyntheticId` is a 32-bit crc32 — collision risk on production volume

**File:** `classes/adapter/theme/ThemeActionEvent.php:42`
**Issue:** `(int) sprintf('%u', crc32($mActionKey))` produces a 32-bit unsigned ID. With a 7-day TTL and high-traffic theme events (PageView, ViewContent), the EventLog can accumulate millions of `theme.action` rows. The birthday-bound collision probability for 32 bits is non-trivial above ~65k rows. Two unrelated `action_key` strings can collide → second event gets deduped as a duplicate of the first → silently dropped. There is no log trace; the operator simply never sees the second Meta event.
**Fix:** Use a 64-bit hash:
```php
$iSyntheticId = (int) sprintf('%u', crc32($mActionKey));   // BEFORE — 32 bits
$iSyntheticId = abs(unpack('q', substr(hash('xxh3', $mActionKey, true), 0, 8))[1] ?? 0);  // AFTER — 64 bits
```
Or use `hexdec(substr(hash('xxh128', $key), 0, 15))` — the EventLog `subject_id` column is `unsignedInteger` (32-bit). To use a 64-bit hash, also change the column to `unsignedBigInteger` in a follow-up migration.

### WR-04: `OrderStatusWatcher` catch silently swallows currency exceptions (and everything else)

**File:** `classes/event/adapter/shopaholic/OrderStatusWatcher.php:59-67`
**Issue:** The outer `catch (Throwable)` is documented as Tiger-Style "log + return — do NOT rethrow", which is correct for not cascade-breaking `Order::save()`. However, the catch is too broad: a thrown `OrderHasNoCurrencyException` becomes a `Log::warning` and the Purchase event is silently lost. The operator has no admin-visible signal that Purchase tracking failed for Order #X — they just see a missing Meta event days later. The FailedEvent table is the documented dead-letter sink, but the watcher never writes to it.
**Fix:** Persist a `FailedEvent` row for `OrderHasNoCurrencyException` (and similar build-time failures) before returning:
```php
} catch (Throwable $obException) {
    Log::warning('metapixel: OrderStatusWatcher payload-build failed', [...]);
    FailedEvent::create([
        'event_name' => 'Purchase',
        'adapter_type' => ShopaholicOrderAdapter::class,
        'subject_type' => 'shopaholic.order',
        'subject_id' => $obOrder->id,
        'payload' => [],
        'graph_error' => 'pre-dispatch: '.$obException->getMessage(),
        'http_status' => null,
        'attempts' => 0,
    ]);
}
```
This gives the Phase 4 admin UI a single dead-letter surface for both pre-dispatch and post-dispatch failures.

### WR-05: `ThemeAjaxHandler::onBeforeRun` return-type compatibility with October's AJAX framework is unverified

**File:** `classes/adapter/theme/ThemeAjaxHandler.php:63-117`
**Issue:** The handler returns `Illuminate\Http\JsonResponse` to short-circuit the `cms.ajax.beforeRunHandler` event. October's `runAjaxHandler` returns the event value to `execAjaxHandlers`, which then runs `ajax()::wrap($result)` on it (`modules/cms/classes/controller/HasAjaxRequests.php:149`). `ajax()::wrap` is designed for arbitrary handler returns (strings, arrays, true), and its behaviour when passed a fully-built JsonResponse is October-version-dependent — at minimum, the wrap layer may double-encode headers, double-wrap the body, or strip the status code. The expected client-side `jax.ajax` response shape for an `error` is also not what JsonResponse 400/422/429/500 naturally produces; jax expects October's framework error envelope.
**Fix:** Verify integration behaviour with an explicit Pest feature test that posts to `Metapixel::onFireEvent` with (a) a disallowed event name, (b) a rate-limited request, and (c) an invalid payload, and asserts the client-side jax response shape, status code, and that no JSON corruption occurs. If `ajax()::wrap` mangles JsonResponse, switch to `throw new ApplicationException(...)` or `Response::make(json_encode([...]), 422, ['Content-Type' => 'application/json'])` per October convention.

### WR-06: Inconsistent null-guard discipline between Shopaholic resolvers (DRY)

**File:** `classes/adapter/shopaholic/ShopaholicOrderValueResolver.php:119-132` vs `classes/adapter/shopaholic/ShopaholicCartPositionValueResolver.php:97-120`
**Issue:** `ShopaholicCartPositionValueResolver` uses `productOf()` + `getRelationValue('product')` to safely null-guard the Offer→Product traversal (Pitfall 1 documented in its docblock). `ShopaholicOrderValueResolver::buildContentId` does direct `$obOffer->product` access, bypassing the same pattern. Same applies to `ShopaholicOrderValueResolver::offerOf` (line 119-122) which does direct `$obPos->item` access where the CartPosition resolver wraps via `getRelationValue('item')`. The two adapters share a content_ids format requirement (D-20: SKU-{product_id}[-{offer_id}]) — they should share the safe-traversal pattern via a private trait, per CONTEXT.md "D-04: Shared SKU-formatting helper extracted to a private trait if logic duplicates".
**Fix:** Extract a `BuildsShopaholicContentId` trait into `classes/adapter/shopaholic/` with `private function buildContentId(?Offer $obOffer): string` and `private function productOf(?Offer $obOffer): ?Product`. Both resolvers use the trait. CR-01 is then fixed simultaneously and the two resolvers stay byte-identical for the SKU format requirement.

### WR-07: `Plugin.php` imports Lovata classes unconditionally at file-parse time

**File:** `Plugin.php:23-24`
**Issue:** `use Lovata\OrdersShopaholic\Models\CartPosition;` and `use Lovata\OrdersShopaholic\Models\Order;` are at the top of `Plugin.php`. PHP `use` statements are aliases and do not trigger autoload, so this works in practice — but it creates a cognitive trap: any future code in `Plugin.php` that does `$x instanceof Order` outside the `isShopaholicEnabled()` gate will autoload-fail on minimal-install runs. The current pattern only avoids the issue by accident (references are inside the `if` block). `composer-dependency-analyser.php` only permits Lovata imports in `classes/adapter/shopaholic/` and `classes/event/adapter/shopaholic/` — `Plugin.php` is not whitelisted, so this is also a boundary-rule violation that the analyser may flag (depends on whether `Plugin.php` is in the scan path; line 10-14 shows it is).
**Fix:** Pass the class names as strings — `AdapterRegistry::register('Lovata\\OrdersShopaholic\\Models\\Order', ShopaholicOrderAdapter::class)` — or fetch them via the adapter's own `getSubjectClass()` static accessor. The `use` statements then drop from `Plugin.php` and the boundary stays clean.

### WR-08: `Settings::set` / `Settings::get` docblock signatures are wrong

**File:** `models/Settings.php:16-19`
**Issue:** The class docblock declares:
```
@method static void set(array<string, mixed> $arValues)
```
The actual `System\Models\SettingModel::set($key, $value = null)` returns `bool` (the result of `save()`) and accepts either a string key + value OR a single array. The `@method` line claims `void` and array-only — both wrong. PHPStan with level 10 + `treatPhpDocTypesAsCertain: true` (set in `phpstan.neon`) will trust this lie and may miss real type errors at call sites.
**Fix:** Match the actual base signature:
```
@method static bool set(string|array $sKey, mixed $mValue = null)
@method static mixed get(string $sKey, mixed $mDefault = null)
```

### WR-09: `EventPixel::onMarkFired` write path is not in a transaction with the lookup

**File:** `components/EventPixel.php:80-98, 193-219`
**Issue:** The handler does `findCapiRow()` → validate event_id → `insertPixelRow()`. Between the lookup and the insert there is no transactional fence. Two concurrent requests for the same subject (e.g., user refreshes the thank-you page rapidly, or the AJAX handler is retried by the browser) can both pass the validation and race to insert. The INSERT IGNORE saves the day on the row level, but the inner-method ignores the affected-row count — `insertPixelRow` returns `['ok' => true]` regardless of whether the row was actually inserted. The caller cannot distinguish "first insert succeeded" from "row already existed". For diagnostic / replay tracing this is a lost signal.
**Fix:** Capture `$iAffected = DB::table(...)->insertOrIgnore(...)` and return `['ok' => true, 'inserted' => $iAffected === 1]`. The frontend can choose to act on or ignore the flag.

## Info

### IN-01: `ThemeActionAdapter` references `Site::getSiteIdFromContext()` while CONTEXT.md D-15 names `Site::getCurrent()?->getId()`

**File:** `classes/adapter/theme/ThemeActionAdapter.php:76`
**Issue:** CONTEXT.md §D-15 documents the fallback as `Site::getCurrent()?->getId()`. The implementation uses `Site::getSiteIdFromContext()`. Both exist (verified in `vendor/october/rain/src/Support/Facades/Site.php` — `getSiteIdFromContext` is on the facade, `getCurrent` is not). The implementation choice is correct (the facade exposes the method, PHPStan won't complain), but the CONTEXT.md text is now out-of-sync with code. Either update the doc or rename to track.

### IN-02: `pixelhead/default.htm` and `eventpixel/default.htm` rely on `|raw` filter — no second escape layer

**File:** `components/pixelhead/default.htm:2`, `components/eventpixel/default.htm:3-9`
**Issue:** Both templates emit pre-built script fragments with `{{ block|raw }}`. The PHP layer is responsible for JSON-escape (`JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS`) and produces safe `<script>` content. Today this is correct. If a future maintainer adds operator-controlled data into the `pixelHeadBlocks` array path WITHOUT routing it through the same json_encode + flags pipeline, the `|raw` filter will obediently emit whatever is in the array as HTML. There is no Twig safety net.
**Fix:** Add a `// SAFETY: $arScriptBlocks entries MUST be JSON-flag-escaped script tags only — do not append raw operator strings here` comment above the `$this->page['pixelHeadBlocks'] = $arScriptBlocks;` assignment in `PixelHead.php` and the equivalent line in `EventPixel.php`. The CLAUDE.md "No comment pollution" rule still permits safety-critical inline comments; this is one of those cases.

### IN-03: `OrderStatusWatcher` doesn't run an EventLog pre-check (inconsistent with CartPositionWatcher)

**File:** `classes/event/adapter/shopaholic/OrderStatusWatcher.php:29-58`
**Issue:** `CartPositionWatcher::handleUpdated` queries EventLog before dispatching to avoid extra queue churn (line 44-51). `OrderStatusWatcher::handle` relies on the EventLogWriter race-fence alone. Inconsistent. With Orders that hit `eloquent.updated` multiple times after a paid-status flip (e.g., admin edits a note), the watcher dispatches a queue job each time; the race-fence rejects all but the first. Wasted queue work + log noise.
**Fix:** Add the same pre-check shape used in CartPositionWatcher (parallel structure), or extract a `previouslyDispatched(adapter, subject, event, channel)` helper into a shared trait.

### IN-04: `EventLogWriter::record` uses `\Throwable` catch instead of imported `Throwable`

**File:** `classes/helper/EventLogWriter.php:84`
**Issue:** Minor style inconsistency — the file does not `use Throwable;` at the top, so the catch is `catch (\Throwable ...)` with the leading backslash. Most other files in the plugin (SendCapiEvent, OrderStatusWatcher, CartPositionWatcher, EventPixel, PixelHead) `use Throwable;` and write `catch (Throwable ...)`. Not a bug.
**Fix:** Add `use Throwable;` at top of `EventLogWriter.php` and drop the leading backslash.

### IN-05: `EventLog::scopeForSubject` is only exercised in tests

**File:** `models/EventLog.php:54-59`
**Issue:** The `scopeForSubject` query scope is tested in `tests/Feature/Models/EventLogModelTest.php` but has no production caller. CONTEXT.md "build philosophy" reads "No dead code, no unused functions. Interface methods land only when first concrete caller lands." A scope tested only in its own unit test isn't strictly dead, but it's pre-emptive. Either land a real caller (e.g., refactor `EventPixel::findCapiRow` to use Eloquent + this scope) or remove until needed.
**Fix:** Reader's choice — accept the scope as a small, type-safe public API surface (low cost), or drop it.

### IN-06: `console/PurgeEventLog::handle` doesn't lock the cutoff timestamp

**File:** `console/PurgeEventLog.php:24-27`
**Issue:** The cutoff is computed as `(string) Carbon::now()->subDays(7)` and passed to a single `DELETE` query — this is fine for correctness. But for an audit log, deleting a slice older than 7 days is final. Consider chunked deletes for very large tables, and consider exposing `--dry-run` for operators who want to inspect what would be purged. Both are operator-experience nits; neither affects correctness.
**Fix:** Optional — add `->chunk(...)` based deletion and a `--dry-run` option in a follow-up. Not required for Phase 3 sign-off.

---

_Reviewed: 2026-05-18T19:30:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
