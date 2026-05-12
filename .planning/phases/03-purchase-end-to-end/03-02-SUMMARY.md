---
phase: 03-purchase-end-to-end
plan: 02
subsystem: exception-hierarchy
tags: [exceptions, typed-errors, immutability, retry-contract, capi, php8.4]
requires: []
provides:
  - Logingrupa\Metapixelshopaholic\Classes\Exception\MetaPixelException (abstract base)
  - Logingrupa\Metapixelshopaholic\Classes\Exception\MissingPixelConfigException
  - Logingrupa\Metapixelshopaholic\Classes\Exception\MissingCapiTokenException
  - Logingrupa\Metapixelshopaholic\Classes\Exception\OrderHasNoCurrencyException
  - Logingrupa\Metapixelshopaholic\Classes\Exception\OrderHasNoItemsException
  - Logingrupa\Metapixelshopaholic\Classes\Exception\InvalidEventIdException
  - Logingrupa\Metapixelshopaholic\Classes\Exception\MetaApiTransientException (isRetryable() === true)
  - Logingrupa\Metapixelshopaholic\Classes\Exception\MetaApiPermanentException (isRetryable() === false)
  - lang.exception.* keys (7 keys × 3 locales = 21 entries) under `logingrupa.metapixelshopaholic::lang.exception.*`
affects:
  - models/FailedEvent.php (3 `@phpstan-ignore class.notFound` markers removed; redundant `is_array(arContext)` guard dropped now that the property is typed `array`)
  - tests/Feature/FailedEventModelTest.php (anonymous double now forwards $arContext through parent::__construct + implements abstract isRetryable(); return type widened to MetaPixelException; the 3 skip-guarded factory tests auto-run + pass)
tech_stack:
  added:
    - PHP 8.4 constructor-promoted `public readonly array` (already in vendor)
    - JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE flags (PHP core — log-injection guard)
  patterns:
    - "Abstract base + N final-concrete subclasses (Tiger-Style typed-exception hierarchy)"
    - "Constructor-promoted readonly property for immutable log context"
    - "isRetryable(): bool encoded in the type — no separate retryable-classification switch"
    - "Grep-able concrete class names — log triage hits the precondition without parsing message strings"
    - "Lang stubs in en/lv/ru with real English values + mirrored lv/ru per SKEL-06 (full translations land in HARD-04)"
key_files:
  created:
    - classes/exception/MetaPixelException.php
    - classes/exception/MissingPixelConfigException.php
    - classes/exception/MissingCapiTokenException.php
    - classes/exception/OrderHasNoCurrencyException.php
    - classes/exception/OrderHasNoItemsException.php
    - classes/exception/InvalidEventIdException.php
    - classes/exception/MetaApiTransientException.php
    - classes/exception/MetaApiPermanentException.php
    - tests/Unit/ExceptionHierarchyTest.php
  modified:
    - lang/en/lang.php
    - lang/lv/lang.php
    - lang/ru/lang.php
    - models/FailedEvent.php
    - tests/Feature/FailedEventModelTest.php
decisions:
  - "Byte-for-byte adoption of the GoodsReceivedException analog for the constructor signature + jsonContext() helper. Only addition is `abstract public function isRetryable(): bool;` per CONTEXT Area 1 Q4. Zero invention beyond what the analog already proves in production."
  - "`isRetryable()` lives on the abstract base as an abstract method. Every concrete is forced to implement at compile time (PHP refuses to instantiate). This is stronger than a bool $bRetryable property — adding a new concrete cannot accidentally inherit a default retryability."
  - "Test 10 (jsonContext) split into TWO assertions: round-trip on non-empty input (proves encode succeeds), and `'{}'` fallback on encode-failure input (a stream resource, since `json_encode` cannot encode resources). The `'[]'` literal for empty arrays is documented in the test as the analog's intentional behavior — `'{}'` is the failure fallback, not the empty-input output."
  - "Plan 03-01's wave-1 forward-reference suppressions removed in this plan (per STATE.md FE-01). The 3 createFromPayloadAndException tests auto-run + pass. Total test count: 40 passing + 3 skipped → 54 passing + 0 skipped (+14 tests)."
  - "The anonymous-class double in FailedEventModelTest rewritten to satisfy readonly: it forwards $arContext through `parent::__construct($sMessage, $arContext)` rather than reassigning post-construct. Also implements abstract isRetryable() returning false. Return type widened from `object` to MetaPixelException."
  - "Lang values in lv/ru mirror en stubs per the SKEL-06 convention (Plan 02-04 precedent). Full lv/ru translations land in Phase 5 HARD-04."
metrics:
  duration_minutes: 5
  tasks_completed: 6
  files_created: 9
  files_modified: 5
  tests_added: 11
  tests_unskipped: 3
  tests_passing: 54
  tests_skipped: 0
  total_assertions: 184
  composer_qa: "exit 0"
  coverage_total: "89.3%"
  exception_classes_coverage: "100.0% (all 8 files)"
  completed: "2026-05-12T21:43:41Z"
---

# Phase 3 Plan 2: Exception Hierarchy (PAY-09) Summary

Wave-1 leaf — ships the 8-class custom exception hierarchy that every subsequent Phase-3 production class (MetaClient, PayloadBuilder, SendCapiEvent, OrderStatusWatcher) throws or catches. One abstract base + seven final concretes + 7 lang stubs × 3 locales + 11 unit tests locking the contract. `composer qa` green; coverage 76.1% → 89.3%.

## What Shipped

### Abstract Base

**`classes/exception/MetaPixelException.php`** (PAY-09)
- `abstract class MetaPixelException extends \RuntimeException` in namespace `Logingrupa\Metapixelshopaholic\Classes\Exception`.
- Constructor signature: `__construct(string $sMessage, public readonly array $arContext = [], ?Throwable $obPrevious = null)`. PHP 8.4 constructor-promoted `readonly` modifier makes `$arContext` immutable post-construct (T-03-06).
- `abstract public function isRetryable(): bool;` — Phase-3 addition over the GoodsReceivedException analog. Subclasses MUST implement; compile-time enforced via `new $sClass(...)` refusing to instantiate.
- `protected static function jsonContext(array $arContext): string` — uses `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`; falls back to `'{}'` on encode failure (resources, recursive refs).

### Seven Concrete Final Subclasses

All five non-API and two API concretes are declared `final` (T-03-07 — a plugin consumer cannot subclass and flip retryability):

| Class | isRetryable() | Throw Site (forward) | Lang Key |
|---|---|---|---|
| `MissingPixelConfigException` | false | `MetaClient::send()` plan 03-03 — event-time pixel_id missing | `exception.missing_pixel_config` |
| `MissingCapiTokenException` | false | `MetaClient::send()` plan 03-03 — event-time CAPI token missing | `exception.missing_capi_token` |
| `OrderHasNoCurrencyException` | false | `PayloadBuilder::buildPurchaseEventPayload` plan 03-04 — order has no currency_code AND no currency relation | `exception.order_has_no_currency` |
| `OrderHasNoItemsException` | false | `PayloadBuilder::buildPurchaseEventPayload` plan 03-04 — empty order_position collection | `exception.order_has_no_items` |
| `InvalidEventIdException` | false | `PayloadBuilder::buildPurchaseEventPayload` plan 03-04 — event_id fails `Ramsey\Uuid\Uuid::isValid()` | `exception.invalid_event_id` |
| `MetaApiTransientException` | **true** | `MetaClient::send()` plan 03-03 — HTTP 408/429/500/502/503/504 + `GuzzleHttp\Exception\ConnectException` | `exception.meta_api_transient` |
| `MetaApiPermanentException` | false | `MetaClient::send()` plan 03-03 — Graph API 4xx (except 408/429), malformed payload, revoked token | `exception.meta_api_permanent` |

### Lang Stubs (en/lv/ru × 7 = 21 entries)

Appended `'exception' => [...]` sub-array to each `lang/{en,lv,ru}/lang.php` (between `component` and `tab`). EN values are real English log-friendly sentences. LV and RU mirror EN per the SKEL-06 convention (full translations land in Phase 5 HARD-04). Existing Phase-2 keys untouched.

### Unit Test Locking the Contract

**`tests/Unit/ExceptionHierarchyTest.php`** — 11 test methods, 18 assertion sites:
1. `test_meta_pixel_exception_is_abstract` — `ReflectionClass::isAbstract()`.
2. `test_meta_pixel_exception_extends_runtime_exception` — `is_subclass_of` against `\RuntimeException`.
3. `test_every_concrete_exception_extends_meta_pixel_exception` — iterates 7 concretes.
4. `test_every_concrete_exception_is_final` — iterates 7 concretes via `ReflectionClass::isFinal()`.
5. `test_concrete_exception_constructor_signature` — `new MissingPixelConfigException('msg', ['order_id' => 42], $obPrevious)` and asserts message + arContext + previous chain.
6. `test_arContext_is_readonly` — try/catch around an assignment; asserts the caught `\Error` message contains "readonly".
7. `test_meta_api_transient_exception_is_retryable` — `=== true`.
8. `test_meta_api_permanent_exception_is_not_retryable` — `=== false`.
9. `test_non_api_exceptions_are_not_retryable` — iterates the 5 non-API concretes.
10. `test_jsonContext_returns_compact_json` — anonymous-class exposer + round-trip via `json_decode` + the `'{}'` encode-failure path via stream resource.
11. `test_every_lang_key_resolves_to_a_string` — `App::setLocale('en')` + `Lang::get(...)` for all 7 keys; asserts non-empty + does not contain `::lang.` (which would indicate the key did not resolve).

### Forward-Reference Loop Closed

Per **STATE.md FE-01** + **FE-02**, plan 03-01's `models/FailedEvent.php` had 3 `@phpstan-ignore-next-line class.notFound` markers for the wave-1 forward reference to `MetaPixelException`. This plan removes them. `tests/Feature/FailedEventModelTest.php`'s `makeMetaPixelExceptionDouble` rewritten:
- Forwards `$arContext` through `parent::__construct($sMessage, $arContext)` (readonly cannot be reassigned post-construct).
- Implements abstract `isRetryable(): bool` returning false.
- Return type widened from `object` to `MetaPixelException` for static-analysis precision.
- The 3 previously-skipped factory tests now auto-run + pass.

## Test Count Delta

| Metric | Baseline (after 03-01) | After 03-02 | Delta |
|---|---|---|---|
| Passing | 40 | 54 | **+14** |
| Skipped | 3 | 0 | **−3** |
| Total assertions | 124 | 184 | **+60** |
| Coverage | 76.1% | **89.3%** | +13.2pp |
| `composer qa` | exit 0 | exit 0 | unchanged |

`+14 tests` = 11 new ExceptionHierarchyTest + 3 unskipped FailedEventModelTest cases (plan's success criterion: total ≥ +10 — met).

### Coverage of New Files

All 8 exception classes report **100.0%** coverage (every method exercised by ExceptionHierarchyTest + FailedEventModelTest). `models/FailedEvent.php` jumped from 0.0% → **100.0%** because the 3 previously-skipped factory tests now auto-run and exercise all 5 private helpers + the orchestrator.

## API Surface Now Available (forward contract for 03-03..03-06)

### Canonical Throw Patterns

```php
// PayloadBuilder precondition (plan 03-04)
if ($obOrder->currency === null && $obOrder->currency_code === null) {
    throw new OrderHasNoCurrencyException(
        'Order #' . $obOrder->order_number . ' has no currency.',
        ['order_id' => $obOrder->id, 'order_number' => $obOrder->order_number],
    );
}

// MetaClient::send() transient classification (plan 03-03)
if (in_array($iStatus, [408, 429, 500, 502, 503, 504], true)) {
    throw new MetaApiTransientException(
        $sGraphError,
        ['order_id' => $iOrderId, 'event_id' => $sEventId, 'http_status' => $iStatus, 'attempts' => $iAttempts, 'graph_error' => $sGraphError],
    );
}
```

### Canonical Catch Pattern (Plan 03-05 SendCapiEvent::handle())

```php
try {
    $this->obMetaClient->send($arPayload);
} catch (MetaApiTransientException $obException) {
    // Rethrow → Laravel queue worker honours $tries + $backoff.
    throw $obException;
} catch (MetaApiPermanentException $obException) {
    // Dead-letter: persist FailedEvent row, do NOT rethrow.
    FailedEvent::createFromPayloadAndException($arPayload, $obException);
}
```

The `instanceof` distinction on the type itself drives the dispatch contract. The `isRetryable()` method is exposed as the dynamic-dispatch fallback for any future caller that needs a boolean check (e.g. a generic retry middleware).

### Canonical $arContext Convention

Trusted code passes the following context keys (forward contract for plans 03-03..03-06):

```php
[
    'order_id'    => int,          // Always present.
    'event_id'    => string,       // UUIDv4 — always present.
    'http_status' => ?int,         // Set by MetaClient::send() on HTTP failures.
    'attempts'    => int,          // Set by SendCapiEvent::handle() before rethrow.
    'graph_error' => ?string,      // Meta Graph API error message, if any.
]
```

`FailedEvent::createFromPayloadAndException` reads `http_status` + `attempts` from this convention (per plan 03-01 contract). The `readonly` modifier on `$arContext` prevents any downstream consumer from mutating these keys.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `test_jsonContext_returns_compact_json` initially asserted `jsonContext([]) === '{}'`**
- **Found during:** Task 5 (initial test run reported `'-{}' / '+[]'`).
- **Issue:** PHP's `json_encode([])` returns the JSON array literal `'[]'`, not the JSON object literal `'{}'`. The plan's `<behavior>` Task 1 specification said "`jsonContext([])` returns `'{}'`", but this contradicts the analog (`GoodsReceivedException::jsonContext`) which does NOT special-case empty arrays. The `'{}'` literal in the analog is the encode-failure fallback only.
- **Fix:** Test rewritten with TWO assertions — round-trip on non-empty input (proves encode succeeds) and `'{}'` fallback via a stream resource (proves `json_encode` failure path returns the literal `'{}'`). The implementation matches the analog byte-for-byte; only the test expectation was adjusted.
- **Files modified:** `tests/Unit/ExceptionHierarchyTest.php`
- **Commit:** `aa53348` (rolled into Task 5 commit)

**2. [Rule 3 - Blocking] `makeMetaPixelExceptionDouble` anonymous class violated readonly + missing abstract impl**
- **Found during:** Task 5 (the 3 unskipped FailedEventModelTest cases needed a working `MetaPixelException` double).
- **Issue:** The Phase-3 contract is stricter than what the 03-01 placeholder anticipated: (a) `$arContext` is `public readonly` (PHP 8.4) — the original double's `$this->arContext = $arContext` after `parent::__construct($sMessage)` raises `\Error: Cannot modify readonly property`. (b) `MetaPixelException::isRetryable()` is abstract — the original double didn't implement it, so PHP refuses to instantiate.
- **Fix:** Rewrote the anonymous double to forward `$arContext` through `parent::__construct($sMessage, $arContext)` (lets the readonly constructor-promotion path set the property exactly once) and added a stub `isRetryable(): bool { return false; }` (matches the "permanent" dead-letter contract under test). Return type widened from `object` to `MetaPixelException` for static-analysis precision.
- **Files modified:** `tests/Feature/FailedEventModelTest.php`
- **Commit:** `aa53348` (rolled into Task 5 commit)

**3. [Rule 3 - Blocking] FailedEvent::createFromPayloadAndException had a dead `is_array(arContext)` guard**
- **Found during:** Task 5 (removing the `@phpstan-ignore` markers per FE-01 surfaced the dead branch).
- **Issue:** Now that `arContext` is declared `public readonly array` (typed as `array` via constructor promotion), the `is_array($obException->arContext) ? $obException->arContext : []` ternary is unreachable — PHP guarantees the property is `array`. phpstan level 10 would flag the dead branch as `instanceof.alwaysTrue`.
- **Fix:** Replaced the ternary with a direct assignment `$arContext = $obException->arContext;`. Same behavior, no dead branch, one less line.
- **Files modified:** `models/FailedEvent.php`
- **Commit:** `aa53348` (rolled into Task 5 commit)

### MetaPixelException Forward-Reference Suppressions Removed (FE-01 closed)

Per STATE.md FE-01, the 3 `@phpstan-ignore-next-line class.notFound` markers in `models/FailedEvent.php` (plus 1 marker in `tests/Feature/FailedEventModelTest.php`) were removed in this plan. `composer analyse` now passes without any suppressions on the FailedEvent reference sites. The FE-01 tracking item is resolved.

## Threat Model Realization (T-03-06..T-03-10)

| Threat ID | Status | Realized via |
|---|---|---|
| T-03-06 (Info disclosure via log injection) | **mitigated** | `jsonContext()` uses `JSON_UNESCAPED_SLASHES \| JSON_UNESCAPED_UNICODE` — control chars (`\n`, `\r`, `\t`) escape to literal sequences, blocking log-injection. Test 10 round-trips a sample context array. |
| T-03-07 (Subclass overriding isRetryable() to flip semantics) | **mitigated** | All 7 concretes declared `final`. Test 4 (`test_every_concrete_exception_is_final`) iterates and asserts via `ReflectionClass::isFinal()`. |
| T-03-08 (Massive `$arContext` DoS) | accepted | Context built by trusted code; payload size bounded by Graph API's ~16 KB cap. LONGTEXT column on `failed_events.payload` handles it. |
| T-03-09 (Exception without attribution) | accepted | `arContext` always carries `order_id` + `event_id` per the canonical convention. Sufficient audit trail. |
| T-03-10 (Lang-key injection) | **mitigated** | The 7 lang keys are hard-coded constants in the throwing classes — never built from runtime input. Exhaustive whitelist. |

## Known Stubs

None. Every exception class has a defined throw site in Phase 3 plans 03-03..03-06. Every lang key has a defined call site (the exception's `Log::error($obException->getMessage(), $obException->arContext)` sink + the eventual backend FailedEvents admin list in Phase 5 HARD-01). The lv/ru lang values mirror en per the SKEL-06 convention — this is documented intentional behavior (translated in Phase 5 HARD-04), NOT a stub.

## Forward-Pointing Surface

- **Plan 03-03 (MetaClient — PAY-01):** Throws `MissingPixelConfigException`, `MissingCapiTokenException`, `MetaApiTransientException`, `MetaApiPermanentException`. The HTTP-status switch inside `MetaClient::send()` is the sole decision point for transient-vs-permanent classification.
- **Plan 03-04 (PayloadBuilder — PAY-06):** Throws `OrderHasNoCurrencyException`, `OrderHasNoItemsException`, `InvalidEventIdException` as fail-fast preconditions at the function boundary.
- **Plan 03-05 (SendCapiEvent — PAY-02):** Catches `MetaApiTransientException` → rethrows for Laravel queue retry; catches `MetaApiPermanentException` → persists FailedEvent via `FailedEvent::createFromPayloadAndException($arPayload, $obException)`. The factory contract is locked by plan 03-01 + this plan's $arContext convention.
- **Plan 03-06 (OrderStatusWatcher — PAY-03):** Does NOT throw any of these directly — it dispatches the queue job; the exceptions surface inside the queue worker. The `meta_purchase_event_id IS NULL` fence in the watcher (PAY-04) ensures no exception is needed at the dispatch site.

## Phase 2 + Phase 3 Plan 01 Invariants Intact

- `Plugin.php::boot()` NOT modified by this plan (no `Event::subscribe(OrderStatusWatcher::class)` yet — that's plan 03-06). PluginGuard + `App::make('metapixel.disabled')` container singleton remain the canonical handler short-circuit.
- `MetapixelTestCase::flushPluginSingletons()` unchanged — exceptions are stateless, no singleton to flush.
- Settings field count unchanged (10 fields). No new field registrations.
- No theme partial / component changes — PixelHead, EnsureFbpFbcCookies, Settings tests all still green.
- Plan 03-01 migrations + FailedEvent model unchanged at the schema level — only the `@phpstan-ignore` markers were stripped.

## Self-Check: PASSED

**Files created (9):**
- classes/exception/MetaPixelException.php — FOUND
- classes/exception/MissingPixelConfigException.php — FOUND
- classes/exception/MissingCapiTokenException.php — FOUND
- classes/exception/OrderHasNoCurrencyException.php — FOUND
- classes/exception/OrderHasNoItemsException.php — FOUND
- classes/exception/InvalidEventIdException.php — FOUND
- classes/exception/MetaApiTransientException.php — FOUND
- classes/exception/MetaApiPermanentException.php — FOUND
- tests/Unit/ExceptionHierarchyTest.php — FOUND

**Files modified (5):**
- lang/en/lang.php — FOUND (exception sub-array appended, Phase-2 keys untouched)
- lang/lv/lang.php — FOUND
- lang/ru/lang.php — FOUND
- models/FailedEvent.php — FOUND (3 phpstan-ignore markers removed, dead is_array branch removed)
- tests/Feature/FailedEventModelTest.php — FOUND (anonymous double rewritten, return type widened)

**Commits:**
- 0579170 — feat(03-02): task 1 — MetaPixelException abstract base — FOUND
- f1d51c2 — feat(03-02): task 2 — 5 non-API concrete exceptions — FOUND
- 15266d3 — feat(03-02): task 3 — MetaApi transient/permanent exceptions — FOUND
- b6cf487 — feat(03-02): task 4 — exception lang stub keys en/lv/ru — FOUND
- aa53348 — test(03-02): task 5 — ExceptionHierarchyTest + FailedEvent fixups — FOUND

**Quality gates:**
- `composer qa` — exit 0 — VERIFIED
- 54 tests passed (was 40 + 3 skipped) — VERIFIED
- 89.3% total coverage (was 76.1%) — VERIFIED
- All 8 exception files at 100.0% coverage — VERIFIED
- All 7 lang keys resolve in en/lv/ru — VERIFIED
