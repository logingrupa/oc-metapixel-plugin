---
phase: 03-purchase-end-to-end
reviewed: 2026-05-12T00:00:00Z
depth: standard
files_reviewed: 35
files_reviewed_list:
  - plugins/logingrupa/metapixelshopaholic/Plugin.php
  - plugins/logingrupa/metapixelshopaholic/updates/add_meta_purchase_event_id_to_orders_table.php
  - plugins/logingrupa/metapixelshopaholic/updates/create_table_failed_events.php
  - plugins/logingrupa/metapixelshopaholic/updates/version.yaml
  - plugins/logingrupa/metapixelshopaholic/models/FailedEvent.php
  - plugins/logingrupa/metapixelshopaholic/models/Settings.php
  - plugins/logingrupa/metapixelshopaholic/models/settings/fields.yaml
  - plugins/logingrupa/metapixelshopaholic/classes/exception/MetaPixelException.php
  - plugins/logingrupa/metapixelshopaholic/classes/exception/MissingPixelConfigException.php
  - plugins/logingrupa/metapixelshopaholic/classes/exception/MissingCapiTokenException.php
  - plugins/logingrupa/metapixelshopaholic/classes/exception/OrderHasNoCurrencyException.php
  - plugins/logingrupa/metapixelshopaholic/classes/exception/OrderHasNoItemsException.php
  - plugins/logingrupa/metapixelshopaholic/classes/exception/InvalidEventIdException.php
  - plugins/logingrupa/metapixelshopaholic/classes/exception/MetaApiTransientException.php
  - plugins/logingrupa/metapixelshopaholic/classes/exception/MetaApiPermanentException.php
  - plugins/logingrupa/metapixelshopaholic/classes/meta/MetaClient.php
  - plugins/logingrupa/metapixelshopaholic/classes/meta/PayloadBuilder.php
  - plugins/logingrupa/metapixelshopaholic/classes/meta/UserDataHasher.php
  - plugins/logingrupa/metapixelshopaholic/classes/queue/SendCapiEvent.php
  - plugins/logingrupa/metapixelshopaholic/classes/event/OrderStatusWatcher.php
  - plugins/logingrupa/metapixelshopaholic/components/PurchasePixel.php
  - plugins/logingrupa/metapixelshopaholic/components/purchasepixel/default.htm
  - plugins/logingrupa/metapixelshopaholic/lang/en/lang.php
  - plugins/logingrupa/metapixelshopaholic/lang/lv/lang.php
  - plugins/logingrupa/metapixelshopaholic/lang/ru/lang.php
  - plugins/logingrupa/metapixelshopaholic/tests/MetapixelTestCase.php
  - plugins/logingrupa/metapixelshopaholic/tests/Support/OrderFixtures.php
  - plugins/logingrupa/metapixelshopaholic/tests/Feature/MigrationsBootTest.php
  - plugins/logingrupa/metapixelshopaholic/tests/Feature/FailedEventModelTest.php
  - plugins/logingrupa/metapixelshopaholic/tests/Feature/SendCapiEventTest.php
  - plugins/logingrupa/metapixelshopaholic/tests/Feature/OrderStatusWatcherTest.php
  - plugins/logingrupa/metapixelshopaholic/tests/Feature/PurchasePixelTest.php
  - plugins/logingrupa/metapixelshopaholic/tests/Unit/ExceptionHierarchyTest.php
  - plugins/logingrupa/metapixelshopaholic/tests/Unit/MetaClientTest.php
  - plugins/logingrupa/metapixelshopaholic/tests/Unit/PayloadBuilderTest.php
  - plugins/logingrupa/metapixelshopaholic/tests/Unit/UserDataHasherTest.php
findings:
  critical: 5
  warning: 9
  info: 6
  total: 20
status: issues_found
---

# Phase 03: Purchase end-to-end — Code Review Report

**Reviewed:** 2026-05-12
**Depth:** standard
**Files Reviewed:** 35
**Status:** issues_found

## Summary

Phase 03 delivers the Purchase CAPI dispatch chain: two migrations (event_id/event_time columns on orders, failed_events table), the FailedEvent audit model, an 8-class exception hierarchy, MetaClient HTTP boundary, PayloadBuilder envelope builder, UserDataHasher PII hashing, SendCapiEvent queue job, OrderStatusWatcher model subscriber, PurchasePixel browser-twin component, and 10 test files. Overall architecture is sound — readonly properties on exceptions and the job, atomic saveQuietly persistence of the (event_id, event_time) pair, MockHandler-backed tests, real-DB OrderFixtures.

Adversarial review surfaces five Critical defects (all centered on the Pixel browser-side XSS surface and validation gaps that could become exploitable on small refactors), nine Warnings (Tiger-Style + Hungarian + documented-design drift), and six Info-level items. The BLOCKER-1 (currency 4-step fallback) test coverage is adequate; the BLOCKER-2 PurchasePixel dedup byte-for-byte contract is locked by `test_custom_data_matches_capi_envelope_byte_for_byte` — both BLOCKER assertions pass.

The most consequential Critical is **CR-01** — the Twig partial's `custom_data|json_encode|raw` relies on a non-flagged default `json_encode` that is one mistaken `JSON_UNESCAPED_SLASHES` flag away from a `</script>` break-out, with no defense-in-depth (no `JSON_HEX_TAG`, no Content-Security-Policy assumed). Today's input chain is server-controlled (order_number, server-generated SKUs), but the safety margin is too thin for a Tiger-Style file.

The second-most consequential is **CR-02** — `Uuid::isValid()` is being used to validate UUIDv4 in `PayloadBuilder::assertValidEventId`, but Ramsey accepts v1/v3/v5 too. The dispatch site only ever generates v4 via `Uuid::uuid4()`, so this is not exploitable today, but it violates the documented "UUIDv4 only" contract (PAY-03) and would silently accept a forged v1 if the column ever sourced from elsewhere.

Critical issues block ship; Warnings should be fixed in the same Phase 3 fix pass.

## Critical Issues

### CR-01: PurchasePixel Twig partial — `json_encode|raw` lacks `JSON_HEX_TAG`, one flag-flip from `</script>` break-out

**File:** `plugins/logingrupa/metapixelshopaholic/components/purchasepixel/default.htm:27`
**Issue:** The custom_data interpolation
```twig
Object.assign({event_time: {{ __SELF__.arMetaEvent.event_time }} }, {{ __SELF__.arMetaEvent.custom_data|json_encode|raw }})
```
uses Twig's `json_encode` filter with no flags. Twig defers to PHP's `json_encode`, which by default escapes `/` to `\/` — that's the only thing protecting against `</script>` break-out from a value injected into custom_data. The file header comment explicitly claims this rendering is safe ("json_encode produces a syntactically-safe JS object literal"), but it is conditionally safe — the moment any maintainer adds `JSON_UNESCAPED_SLASHES` (a common readability tweak, already used in three other places in this plugin: `MetaPixelException::jsonContext`, `FailedEvent::encodePayload`, `MetaClient::decodeResponseBody`'s sibling encoders), the partial becomes vulnerable to stored-XSS.

The Tiger-Style "defense in depth" / fail-safe doctrine requires `JSON_HEX_TAG` (escapes `<` and `>` to `<` / `>`) so the partial is safe regardless of slash-escaping. `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT` is the canonical "safe-for-script-context" set.

Today's `custom_data` is server-built (order_number, server-generated UUIDs, SKU-XXX strings, server-rounded numerics), so the active attack surface is small — but it is a single regression away from being exploitable. The plan's own threat model T-04-02 / T-03-33 documents this as a Pixel XSS target.

**Fix:**
Move the encoding off the Twig filter and into the component so the flags are explicit and PHP-tested. In `PurchasePixel.php`:
```php
private function encodeCustomDataForScript(array $arCustomData): string
{
    $sJson = json_encode(
        $arCustomData,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    );
    return $sJson;
}
```
Expose a public `sCustomDataJson` property and consume it in Twig:
```twig
fbq('track', 'Purchase',
    Object.assign({event_time: {{ __SELF__.arMetaEvent.event_time }} }, {{ __SELF__.sCustomDataJson|raw }}),
    { eventID: '{{ __SELF__.arMetaEvent.event_id|e('js') }}' });
```
Add an XSS regression test: feed a paid Order whose `order_number` contains `</script><script>alert(1)</script>` (force-filled in a hermetic fixture), call `onRun`, then assert the rendered `sCustomDataJson` contains neither `</script>` nor `<script>` substrings.

---

### CR-02: `PayloadBuilder::assertValidEventId` uses `Uuid::isValid()` — accepts UUIDv1/v3/v5 despite documented "UUIDv4 only" contract

**File:** `plugins/logingrupa/metapixelshopaholic/classes/meta/PayloadBuilder.php:106-114`
**Issue:** The contract docblock on the class (lines 27, 41) and on `InvalidEventIdException` says "server-generated UUIDv4 per PAY-03 (event_id direction is server → frontend only)". The validator uses `Uuid::isValid($sEventId)`, which returns true for any well-formed UUID — v1, v2, v3, v4, v5, and Nil. Confirmed via repl: `Uuid::isValid("00000000-0000-1000-8000-000000000000")` → `bool(true)` (a UUIDv1 with a time-low of zero).

Today's dispatch site is `OrderStatusWatcher::fireForwardDispatch` which uses `Uuid::uuid4()->toString()`, so in practice only v4 reaches the validator. But:
1. `PurchasePixel::onRun` also feeds the column value back into `PayloadBuilder::buildPurchaseEventPayload` (PurchasePixel.php:124-128). If a future migration backfills `meta_purchase_event_id` from a different source (a legacy v1 UUID from another plugin, a deterministic UUIDv5 derived from order_number), the validator silently accepts it and the contract is broken without alarm.
2. The exception itself is named after the contract ("InvalidEventIdException — event_id is not a valid UUID v4") so the validator should match the docstring.

**Fix:** Tighten the validator to require version 4 specifically:
```php
private function assertValidEventId(string $sEventId, int $iOrderId): void
{
    $bValidV4 = $sEventId !== ''
        && Uuid::isValid($sEventId)
        && Uuid::fromString($sEventId)->getFields()->getVersion() === 4;
    if (!$bValidV4) {
        throw new InvalidEventIdException(
            'event_id is not a valid UUIDv4',
            ['event_id' => $sEventId, 'order_id' => $iOrderId],
        );
    }
}
```
Add a test in `PayloadBuilderTest`: feed a valid v1 UUID, assert `InvalidEventIdException` thrown.

---

### CR-03: `PurchasePixel::resolveOrder()` ignores the `validationPattern` declared on `orderSlug` — slug from `{{ :slug }}` flows untyped into a DB query

**File:** `plugins/logingrupa/metapixelshopaholic/components/PurchasePixel.php:168-179` (combined with `defineProperties` at lines 73-84)
**Issue:** `defineProperties` declares `'validationPattern' => '^[a-zA-Z0-9-]+$'` for `orderSlug`, but October's component property `validationPattern` is enforced only at backend-form-edit time when the property is set manually via the page builder. At runtime — when the property is bound via `{{ :slug }}` (the recommended default in this very file's docblock) — there is no validation at all. `$this->property('orderSlug')` returns whatever the URL route produced.

The actual database query is parameterized: `Order::where('secret_key', $sSlug)->first()` — so this is **not** SQL injection. But two concerns remain:
1. The pattern lacks `\A` / `\z` (or PCRE `^…$` with `D` modifier). October stores it as a JS pattern (frontend) and as a PHP regex (backend `preg_match`). PHP `preg_match('/^[a-zA-Z0-9-]+$/', $s)` allows trailing newline by default — `secret_key\n` would match. Today's query is parameterized so the trailing newline lookup just misses (returns null) — but the validation expectation is broken and a future maintainer who copies this pattern into a non-parameterized path inherits the unanchored regex.
2. The validator is documented as a security guard in the docblock ("the escaper is a no-op today but is the T-04-02 / T-03-33 mitigation against a future regression"), yet it never runs on the actual hot path. That is the worst kind of safety theater — a developer reads the validator and assumes it executes.

**Fix:**
1. Remove the misleading `validationPattern` from `defineProperties` (or document explicitly it is backend-edit only).
2. Add a runtime guard in `resolveOrder`:
```php
private function resolveOrder(): ?Order
{
    $sSlug = $this->stringOrEmpty($this->property('orderSlug'));
    if ($sSlug === '' || preg_match('/\A[A-Za-z0-9-]{1,64}\z/', $sSlug) !== 1) {
        return null;
    }
    $obResult = Order::where('secret_key', $sSlug)->first();
    return $obResult instanceof Order ? $obResult : null;
}
```
The `{1,64}` cap is a bounded-loop discipline (Tiger-Style); `secret_key` in Lovata is 40 chars or fewer.

---

### CR-04: Production classes shipped without `declare(strict_types=1);` — Phase 3 deliverables FailedEvent model + both migrations + Plugin.php

**File:** `plugins/logingrupa/metapixelshopaholic/models/FailedEvent.php:1-2`, `plugins/logingrupa/metapixelshopaholic/updates/add_meta_purchase_event_id_to_orders_table.php:1-2`, `plugins/logingrupa/metapixelshopaholic/updates/create_table_failed_events.php:1-2`, `plugins/logingrupa/metapixelshopaholic/Plugin.php:1-2`, `plugins/logingrupa/metapixelshopaholic/models/Settings.php:1-2`
**Issue:** Per project conventions (CLAUDE.md Tiger-Style section: "Explicit types, explicit returns. No hidden magic. Declare `: void`, `: array`, `: ProductItem`, etc"), all production classes should opt into `declare(strict_types=1);`. Every other Phase 3 production class **does** opt in — MetaClient.php, PayloadBuilder.php, UserDataHasher.php, OrderStatusWatcher.php, SendCapiEvent.php, every exception class, PurchasePixel.php. The Phase 3 fresh-shipped files that do **not** are:

- `models/FailedEvent.php` (PAY-05 — net-new in this phase)
- `updates/add_meta_purchase_event_id_to_orders_table.php` (PAY-04 — net-new)
- `updates/create_table_failed_events.php` (PAY-05 — net-new)
- `Plugin.php` (modified in this phase — Event::subscribe + registerComponents added)
- `models/Settings.php` (rules added in this phase — pixel_id regex)

This matters because `FailedEvent::createFromPayloadAndException` has helper methods like `extractStringField` that coerce mixed → string. Without strict types, an integer event_id (e.g. `'event_id' => 123` in a future caller) would silently `(string) 123` instead of raising. Strict types adds the missing fail-fast layer at the function-arg boundary.

**Fix:** Add `declare(strict_types=1);` immediately after the `<?php` opener of every file listed above. Verify by grep:
```bash
grep -L "declare(strict_types=1)" plugins/logingrupa/metapixelshopaholic/{Plugin,models/FailedEvent,models/Settings,updates/*}.php
```
Each file must come back clean.

---

### CR-05: `OrderStatusWatcher::handleUpdated` — refire-flip away-clear path is never tested for the `disabled` short-circuit ordering

**File:** `plugins/logingrupa/metapixelshopaholic/classes/event/OrderStatusWatcher.php:73-99`
**Issue:** Sequence:
1. `isPluginDisabled()` returns false → continue.
2. Refire branch executes: `setAttribute(meta_purchase_event_id, null)` + `setAttribute(meta_purchase_event_time, null)` + `saveQuietly()`.
3. Status fence check.

If the plugin is disabled **between** step 2 and the next call to `handleUpdated`, the columns have been NULLed but no dispatch will happen on re-paid. Worse: if `isPluginDisabled` returns true on the next save (admin toggled it off), the away-cleared columns remain null forever — manual replay is impossible because no `meta_purchase_event_id` exists for the audit trail.

A second, more immediate concern is that the away-clear runs **even when the dispatcher would later determine it's the only save the order will see** (e.g. order's status flipped to `cancelled` permanently). That's the documented intent — but it means the audit trail loses the original UUID without any replacement.

Even more critical: the test `test_status_flip_away_then_back_with_refire_on_fires_twice` (`OrderStatusWatcherTest.php:126-150`) covers refire=ON with a back-to-paid flip. But there is **no test** verifying that with refire=ON, plugin-disabled mid-flight does not clear the columns. The disabled short-circuit is at line 75 — if a future refactor moves the away-clear above the disabled check (e.g., "defensive — always clear on away even if plugin off"), audit data is destroyed silently. No test would catch it.

**Fix:** Add a regression test:
```php
public function test_refire_on_with_plugin_disabled_does_not_clear_columns(): void
{
    Settings::set('refire_purchase_on_status_flip', true);
    Cache::flush();
    Settings::clearInternalCache();
    $obOrder = $this->makeOrderAtPendingStatus();
    $obOrder->status_id = 5;
    $obOrder->save();
    $sUuid = $obOrder->fresh()->meta_purchase_event_id;
    $this->assertNotNull($sUuid);

    // Disable the plugin AFTER the paid flip; subsequent away-transition
    // MUST NOT clear the UUID column (audit-trail preservation).
    $this->primePluginGuardDisabled();
    $obOrder = $obOrder->fresh();
    $obOrder->status_id = 4;
    $obOrder->save();

    $this->assertSame($sUuid, $obOrder->fresh()->meta_purchase_event_id,
        'disabled plugin must NOT clear columns on away-transition (audit-trail).');
}
```
This is also a code-fix opportunity: consider documenting the audit-data-loss semantics of refire=ON in CONTEXT.md so operators understand the trade-off.

## Warnings

### WR-01: `OrderStatusWatcher` — single `Order::save()` cascade can fire `eloquent.updated` twice when refire ON triggers an away-clear save then the user's save completes

**File:** `plugins/logingrupa/metapixelshopaholic/classes/event/OrderStatusWatcher.php:86-89, 161`
**Issue:** When refire=ON and an away-transition save fires, line 89 calls `$obOrder->saveQuietly()` from inside the `eloquent.updated` handler chain that is currently processing the user's original `Order::save()` call. While `saveQuietly` suppresses subsequent event firings, it **does** issue a DB UPDATE during another DB UPDATE's event chain. SQLite-in-memory accepts this; MySQL InnoDB also accepts this; but reading the same row twice in one save chain can corrupt the `$obOrder` instance's `getOriginal()` / `isDirty()` snapshots used in the SAME handler later (the `isAtPaidStatus(...)` call on line 103, which queries Status by status_id, is read-only — but `isAwayFromPaid` reads `getOriginal('status_id')` and that snapshot can shift after the inner saveQuietly).

In practice the `isAwayFromPaid` is only called once (above the saveQuietly) so the bug is dormant. But this is exactly the kind of two-write-in-one-event-chain pattern that breaks subtly under load (queue worker re-firing, OctoberCMS observer plugin re-firing model events on saveQuietly via a configuration option).

**Fix:** Use the dirty-payload write path that does NOT trigger a save call inside the event handler:
```php
\DB::table('lovata_orders_shopaholic_orders')
    ->where('id', $obOrder->getKey())
    ->update([
        'meta_purchase_event_id' => null,
        'meta_purchase_event_time' => null,
    ]);
$obOrder->setRawAttributes(array_merge($obOrder->getAttributes(), [
    'meta_purchase_event_id' => null,
    'meta_purchase_event_time' => null,
]), true); // sync in-memory snapshot
```
This skips the model save chain entirely. Tiger-Style: fail-fast clarity over Eloquent convenience.

---

### WR-02: `UserDataHasher::compute()` — `email`/`name`/`last_name` hashed via `mb_strtolower(trim(…))`, but `external_id` from `secret_key` is also passed through `hashLower` — `secret_key` is opaque and shouldn't be lowercased

**File:** `plugins/logingrupa/metapixelshopaholic/classes/meta/UserDataHasher.php:111`
**Issue:** Meta CAPI spec for PII fields (em / ph / fn / ln) requires `sha256(mb_strtolower(trim(value)))`. For `external_id`, Meta's spec says "any unique identifier from the advertiser" and explicitly notes hashing is **optional** — the CAPI guide recommends sha256 but says lowercasing is NOT a normalization step for external_id (Meta CAPI Docs: "Best practice — hash with SHA-256; lowercase/trim only the PII normalization fields").

Today's `secret_key` happens to be lowercase ASCII (generated by Lovata `OrderProcessor::generateSecretKey` using `Str::random`), so the lowercase is a no-op. But a future change to use a UUID v4 `secret_key` (hex with both cases possible) or a base64 token (mixed case) would silently break the external_id contract — the same user across two events would hash to different `external_id` values if one was uppercased at source. This breaks Meta's user-resolution for the EMQ score.

**Fix:** Hash `secret_key` with sha256 only — no lowercase / trim:
```php
'external_id' => $sSecretKey !== '' ? hash('sha256', $sSecretKey) : null,
```
The `null` guard matches the other PII fields' shape. Add a regression test in `UserDataHasherTest` feeding a `secret_key` of `'MixedCaseSECRET'` and asserting `external_id` equals `hash('sha256', 'MixedCaseSECRET')`, NOT `hash('sha256', 'mixedcasesecret')`.

---

### WR-03: `PayloadBuilder::resolveCurrency` docblock says "4-step fallback" but implementation has 3 steps + throw

**File:** `plugins/logingrupa/metapixelshopaholic/classes/meta/PayloadBuilder.php:36, 117-154`
**Issue:** Two contradictory documentation paths:
- Class docblock line 36: "Currency: 4-step fallback per CONTEXT.md Specifics line 158 — relation → direct property → Settings::get('currency_code', 'EUR') → throw."
- Method docblock line 117: "4-step currency fallback chain per CONTEXT.md Specifics line 158: 1. obOrder->currency relation … 2. obOrder->currency_code accessor … 3. Settings::get('currency_code', 'EUR') … 4. Throw OrderHasNoCurrencyException."

These count "throw" as a step. The implementation has 3 source attempts + a final throw. That's a documentation-vs-test mismatch waiting to bite — a future engineer reading "4-step" will look for a missing source. CONTEXT.md is the source of truth; either align the doc to "3 sources + fail-fast" OR add a 4th source (e.g., site default currency from `Lovata\Shopaholic\Models\Currency::getDefault()`).

**Fix:** Decide. Either:
- Re-word docblock to "3-source fallback chain + fail-fast" (no behavior change), OR
- Add the documented missing 4th source as the last fallback before the throw.

Either way, the `test_throws_order_has_no_currency_when_all_three_sources_empty` test name should be made consistent with the chosen wording.

---

### WR-04: `FailedEvent::extractStringField` returns empty string on missing/non-scalar `event_id`/`event_name` — but `rules` declares both `required` → `create()` will throw `ModelException` and the dead-letter row is lost

**File:** `plugins/logingrupa/metapixelshopaholic/models/FailedEvent.php:42-49, 89-91, 119-122`
**Issue:** `createFromPayloadAndException` is the dead-letter terminal — by the time it is called, the payload has already failed CAPI dispatch AND `MetaApiPermanentException` has been raised. If the payload happens to be malformed in a way that `$arPayload['data'][0]['event_id']` is missing or non-scalar (e.g., the rare case where PayloadBuilder threw an exception BEFORE assembling data[0], or a future Phase 4 caller dispatches an envelope with a different shape), `extractStringField` returns `''`. The model `rules` declares `event_id => 'required|string|max:36'` — empty string fails `required`. `FailedEvent::create([…])` raises `ModelException` inside `SendCapiEvent::writeFailedEvent`.

That silent catch at SendCapiEvent.php:156 then swallows the ModelException — silently. The dead-letter row is never persisted. The administrator has no visibility on the permanent failure. T-03-22 mitigation (don't cascade DB failures) is correct, but the silent loss of failure intelligence is a Tiger-Style discipline gap.

**Fix:** In `FailedEvent::createFromPayloadAndException`, replace empty-string with a synthetic placeholder that satisfies `required`:
```php
'event_id' => self::extractStringField($arFirstEvent, 'event_id') ?: '__missing__',
'event_name' => self::extractStringField($arFirstEvent, 'event_name') ?: '__unknown__',
```
This preserves visibility — the operator sees `__missing__` in the dead-letter list and knows the payload was malformed, rather than silently losing the row. Add a test in `FailedEventModelTest`:
```php
public function test_create_from_payload_handles_missing_event_id_with_placeholder(): void
{
    $obException = $this->makeMetaPixelExceptionDouble('boom', []);
    $obFailed = FailedEvent::createFromPayloadAndException(
        ['data' => [['event_name' => 'Purchase']]], // no event_id key
        $obException,
    );
    $this->assertSame('__missing__', $obFailed->event_id);
}
```

---

### WR-05: `MetaClient` constants use PHP 8.3+ typed const syntax — `public const string GRAPH_VERSION`, `private const int DEFAULT_TIMEOUT` — fine on PHP 8.4 prod, but composer.json minimum should match

**File:** `plugins/logingrupa/metapixelshopaholic/classes/meta/MetaClient.php:33, 35, 38` + sibling files (PayloadBuilder.php:41-47, UserDataHasher.php:36-40)
**Issue:** Multiple constants use the PHP 8.3+ feature "typed class constants" (`public const string GRAPH_VERSION = 'v20.0'`). This is fine for production (PHP 8.4) but production-prod constraints don't tell the whole story — if `composer.json` declares a lower minimum (e.g., `"php": "^8.1"`), CI on older PHP runners or downstream installs on PHP 8.2 will fatally parse-error. The project's documented minimum is "PHP 8.3+ (prod 8.4)" — typed const requires 8.3 exactly, so this is at the minimum boundary. Worth verifying composer.json reflects this.

**Fix:** Verify `plugins/logingrupa/metapixelshopaholic/composer.json` has `"php": "^8.3"` (or higher). If not, either bump the constraint OR drop the type modifier:
```php
public const GRAPH_VERSION = 'v20.0'; // untyped — PHP 7+
```
Document the choice in PATTERNS.md so future plans don't accidentally regress.

---

### WR-06: `PayloadBuilder::resolveEventSourceUrl()` — silent catch on `app(Request::class)` that cannot throw in practice + leaks `Throwable` capture

**File:** `plugins/logingrupa/metapixelshopaholic/classes/meta/PayloadBuilder.php:257-267`
**Issue:** `app(Request::class)` either returns the bound Request OR creates a default one via the container's auto-resolution — it does not throw under normal circumstances. The `try/catch (Throwable)` block is dead code: the only way to reach the catch is a container outright failure (BindingResolutionException is a Throwable), which would indicate a far worse problem than missing a Request.

More important: `Throwable` is too broad. If the container resolution succeeds but `$obRequest->fullUrl()` somehow throws (it can't today — fullUrl is pure string concat — but a future Symfony upgrade could introduce a route-resolution side effect), that throw escapes back through `buildPurchaseEventPayload` and bubbles up to the queue job. The silent catch's docblock comment says "no request in queue worker / CLI context" but `fullUrl()` is outside the try block.

**Fix:** Narrow the catch + move the URL extraction inside:
```php
private function resolveEventSourceUrl(): ?string
{
    try {
        $obRequest = app(Request::class);
        return $obRequest->fullUrl();
    } catch (\Illuminate\Contracts\Container\BindingResolutionException) {
        // silent: no request in queue worker / CLI context.
        return null;
    }
}
```
Same fix applies verbatim to `UserDataHasher::readRequest` (UserDataHasher.php:159-169).

---

### WR-07: `SendCapiEvent::failed()` may double-write FailedEvent rows — once from `handle()` catch + once from `failed()` hook after retry exhaustion

**File:** `plugins/logingrupa/metapixelshopaholic/classes/queue/SendCapiEvent.php:95-118, 125-144`
**Issue:** Flow for a transient-then-eventually-permanent path:
1. First attempt: handle() catches MetaApiTransientException → rethrow → Laravel retries.
2. Second attempt: handle() catches MetaApiTransientException → rethrow → Laravel retries.
3. Third attempt: handle() catches MetaApiTransientException → rethrow.
4. `$tries` exhausted → Laravel calls `failed()` → writes FailedEvent.

OK so far. But: if attempt 2 is a permanent failure (HTTP 400 mid-retry):
1. Attempt 1: transient 503 → rethrow.
2. Attempt 2: permanent 400 → catch + writeFailedEvent + no rethrow → job marked succeeded.

`failed()` is NOT called because the job didn't throw on attempt 2. Single FailedEvent row. Good.

But what if `handle()`'s `MetaApiPermanentException` catch branch raises an unexpected exception (e.g., the dead-letter DB write fails AND the silent catch's `Log::critical` itself throws due to log driver misconfiguration)? Then the `handle()` method effectively throws — Laravel sees an exception, retries... and on the next attempt the same permanent failure happens. Eventually `$tries` exhausted, `failed()` fires with the LATEST exception. `failed()` then calls `writeFailedEvent` AGAIN — but with the new exception's payload (which is the same arPayload, but the exception context differs). Two FailedEvent rows for one logical permanent failure.

This is unlikely (logging is generally infallible) but the contract is fragile. The fundamental issue is that `handle()` and `failed()` both call `writeFailedEvent` and there is no idempotency on the FailedEvent side.

**Fix:** Add a uniqueness constraint to the dead-letter table on `(event_id, http_status)` so duplicate writes silently no-op:
```php
// In create_table_failed_events.php
$obTable->unique(['event_id', 'http_status'], 'metapixel_failed_events_event_status_unique');
```
This is a defense-in-depth fix; the more important review note is to **explicitly test the double-fire path** — add a test in `SendCapiEventTest`:
```php
public function test_handle_then_failed_does_not_double_write_failed_event(): void
{
    $this->primeSettings();
    $arPayload = $this->makePayload('double-fire-uuid');
    $obJob = new SendCapiEvent('Purchase', $arPayload);
    // Simulate handle's permanent catch firing first…
    $obJob->failed(new MetaApiPermanentException('first', ['http_status' => 400]));
    // …then Laravel calling failed() on top
    $obJob->failed(new MetaApiPermanentException('first', ['http_status' => 400]));
    $this->assertSame(1, FailedEvent::count(), 'must not double-write');
}
```

---

### WR-08: `OrderStatusWatcher` performs `Status::where(...)` lookup on EVERY `eloquent.updated` of EVERY Order — N+1 footprint on bulk admin saves

**File:** `plugins/logingrupa/metapixelshopaholic/classes/event/OrderStatusWatcher.php:230-273`
**Issue:** Each `handleUpdated` call may fire up to 2 `Status::where('id', $id)->value('code')` queries (one for current, one for original via `isAwayFromPaid`) plus the disabled flag container lookup. For a bulk-update admin action (mass status change on 1000 orders), this is up to 2000 status table queries.

Performance is out of v1 review scope per the project context — flagging this as a Warning because the design intent is documented elsewhere (eager-load chain) but the code makes no attempt to use it. The fallback path is correct; it is just suboptimal.

**Fix (deferred to Phase 5 PER-01 if it exists):** Cache the resolved status `code` map at the OrderStatusWatcher level via CCache with a short TTL OR an in-process static map:
```php
private static array $arStatusCodeCache = [];

private function lookupStatusCode(int $iStatusId): string
{
    if (!isset(self::$arStatusCodeCache[$iStatusId])) {
        $mCode = Status::where('id', $iStatusId)->value('code');
        self::$arStatusCodeCache[$iStatusId] = is_scalar($mCode) ? (string) $mCode : '';
    }
    return self::$arStatusCodeCache[$iStatusId];
}
```
Add a `flush` static to clear it (test hooks).

---

### WR-09: `PurchasePixel::extractCustomData` re-keys an already-string-keyed array via `(string) $mKey` cast — discards original integer keys silently

**File:** `plugins/logingrupa/metapixelshopaholic/components/PurchasePixel.php:233-238`
**Issue:** The comment claims "Re-key to satisfy phpstan level 10's array<string, mixed> contract — `$mCustom` is array<mixed> until proven string-keyed." This is correct, but `(string) $mKey` silently converts integer keys to string. If `custom_data` ever contains an integer-keyed sub-array (today it does not, but Meta CAPI v21+ envelope could change), the cast collides — PHP arrays with `'0'` and `0` as keys silently coalesce. There is no test that the re-keying preserves all entries — only that the contract is met.

**Fix:** Filter explicitly to string-keyed entries, drop integer keys:
```php
foreach ($mCustom as $mKey => $mValue) {
    if (!is_string($mKey)) {
        continue; // integer-keyed entry — not a CAPI custom_data field.
    }
    $arResult[$mKey] = $mValue;
}
```
Add a unit test feeding a mock `$arPayload` whose `custom_data` contains `[0 => 'unexpected', 'value' => 49.95]`, assert the result has `'value'` and not `0`.

## Info

### IN-01: Three lang files (en, lv, ru) are byte-identical — RainLab.Translate workflow not yet leveraged

**File:** `plugins/logingrupa/metapixelshopaholic/lang/en/lang.php`, `plugins/logingrupa/metapixelshopaholic/lang/lv/lang.php`, `plugins/logingrupa/metapixelshopaholic/lang/ru/lang.php`
**Issue:** All three files are identical English text. The plugin claims multi-language support (`.lv` and `.lt` deployments) but only `pixel_id` is RainLab-translatable per Settings.php:41. Backend operators on .lv/.lt see English strings for tab labels, field labels, error messages.
**Fix:** Ship actual Latvian + Russian translations OR document the en-only-for-now decision explicitly in CLAUDE.md so a future maintainer doesn't think the lv/ru files are placeholders for missing content.

### IN-02: `OrderStatusWatcher` boundary catch swallows exception details — `$obException->arContext` is logged structured but `getPrevious()` chain is not

**File:** `plugins/logingrupa/metapixelshopaholic/classes/event/OrderStatusWatcher.php:169-183`
**Issue:** The boundary catch at line 169 logs class + message but not `getPrevious()` chain or the structured `arContext`. If PayloadBuilder throws `OrderHasNoCurrencyException` with `arContext['order_id'] => 123`, the order_id is duplicated in the log via `$this->intOrZero($obOrder->getAttribute('id'))` but the exception's own context array is dropped.
**Fix:** Merge the exception's arContext into the log call:
```php
Log::warning('Metapixel: PayloadBuilder precondition failed — Purchase NOT dispatched', array_merge([
    'meta_pixel.order_id' => $this->intOrZero($obOrder->getAttribute('id')),
    // …existing keys
], $obException->arContext));
```

### IN-03: `MetaPixelException::jsonContext` is `protected static` but only invoked in tests via an anonymous-class accessor — no production caller

**File:** `plugins/logingrupa/metapixelshopaholic/classes/exception/MetaPixelException.php:67-82`
**Issue:** The helper has a thoughtful safety contract (log-injection guard, T-03-06) and the test (`test_jsonContext_returns_compact_json`) locks the behavior, but no production code calls it. The intended call site is `Log::error($obException->getMessage(), $obException->arContext)` per the class docblock, which passes the array directly — Monolog handles the encoding. `jsonContext` is reachable only by future subclasses.
**Fix:** Either delete `jsonContext` (and the test) as dead code, OR use it from `OrderStatusWatcher`'s boundary catch (see IN-02). Document the choice.

### IN-04: `MigrationsBootTest` `require_once` chain — migrations are discovered by manual `require_once` because of snake_case filenames

**File:** `plugins/logingrupa/metapixelshopaholic/tests/Feature/MigrationsBootTest.php:7-9`
**Issue:** The manual `require_once` is correct but fragile — adding a 3rd migration in Phase 4 requires updating this list manually. Consider a glob-based loader in `MetapixelTestCase` that loads everything in `updates/*.php`.
**Fix:**
```php
protected function loadMigrations(): void
{
    foreach (glob(__DIR__.'/../../updates/*.php') as $sFile) {
        require_once $sFile;
    }
}
```

### IN-05: `FailedEvent::createFromPayloadAndException` lacks a `declare(strict_types=1)` companion — but `MetaPixelException` (its only typed parameter) does

**File:** `plugins/logingrupa/metapixelshopaholic/models/FailedEvent.php:83`
**Issue:** Cross-package callers mixing strict and weak modes can pass non-array `$arPayload` (e.g., an object that satisfies typehint `array` via cast). Without strict_types, the function-arg coercion is silent. Already covered as CR-04 but flagging the specific factory entry point for clarity.

### IN-06: Twig partial template comment block mentions `e('js')` mitigation for "future regression that lets attacker-controlled strings reach the column" — but the comment is INSIDE the partial, not in PROJECT.md

**File:** `plugins/logingrupa/metapixelshopaholic/components/purchasepixel/default.htm:8-12`
**Issue:** Security mitigations should live in the threat model (PROJECT.md / PATTERNS.md), not inside Twig comments where they vanish from grep targets like "T-03-33 mitigation". A maintainer doing a security audit by grepping the plugin root for "T-03-33" misses the partial.
**Fix:** Move the T-03-33 mitigation note to PATTERNS.md alongside the other threat-model entries; leave a one-liner pointer in the Twig comment ("T-03-33 mitigation — see PATTERNS.md").

---

_Reviewed: 2026-05-12_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
