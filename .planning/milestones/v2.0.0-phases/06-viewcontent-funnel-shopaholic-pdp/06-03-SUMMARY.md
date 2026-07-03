---
phase: 06-viewcontent-funnel-shopaholic-pdp
plan: 03
subsystem: adapter-registry
tags: [adapter-registry, alias-resolution, hybrid-ajax, security, threat-model, php-83]

# Dependency graph
requires:
  - phase: 06-viewcontent-funnel-shopaholic-pdp
    provides: 06-01 RED stubs + Phase 2 AdapterRegistry baseline (resolveFor + resolveByClass + register)
provides:
  - "AdapterRegistry::resolveByAlias(string): class-string<EventSubjectAdapter> with O(1) alias lookup against a register-time-built index"
  - "SupportsHybridAjax marker subinterface declaring loadSubject(int, array): ?object — opt-in hybrid-AJAX hydration contract"
  - "UnknownSubjectTypeException under classes/exception/ — thrown by resolveByAlias on miss, caught at ThemeAjaxHandler boundary (plan 06-06)"
  - "Class-level 'adapters MUST have parameterless constructors' convention note on AdapterRegistry"
affects:
  - 06-04 (ShopaholicProductAdapter implements SupportsHybridAjax for PDP offer-switch path)
  - 06-05 (ProductPageWatcher dispatches via Plugin.boot adapter registration → alias index auto-builds)
  - 06-06 (ThemeAjaxHandler hybrid path consumes resolveByAlias + catches UnknownSubjectTypeException → JsonResponse 422)

# Tech tracking
tech-stack:
  added: []  # No new framework installs — extends existing Phase 2 AdapterRegistry surface
  patterns:
    - "Register-time alias index build via App::make($sAdapterClass)->getSubjectType(new \\stdClass) — alias is byte-for-byte the registered set"
    - "Marker subinterface (SupportsHybridAjax) preserves Phase 2 EventSubjectAdapterContractTestCase 10 invariants on the base EventSubjectAdapter interface"
    - "Sub-namespace test doubles under Logingrupa\\Metapixel\\Tests\\Unit\\Adapter\\Doubles using PHP bracketed multi-namespace syntax — autoload-dev scope, never production"
    - "Class-level #[PHPUnit\\Framework\\Attributes\\Group('adapter')] attribute on the test class for minimal-install CI cell isolation"

key-files:
  created:
    - classes/adapter/SupportsHybridAjax.php
    - classes/exception/UnknownSubjectTypeException.php
    - tests/Unit/Adapter/AdapterRegistryResolveByAliasTest.php
  modified:
    - classes/adapter/AdapterRegistry.php

key-decisions:
  - "loadSubject placed on a NEW SupportsHybridAjax subinterface, NOT the base EventSubjectAdapter — Phase 2 contract test 10 invariants stay intact (CONTEXT.md code_context is reference material, not a locked decision per RESEARCH §11 Q2)"
  - "Alias index built at register-time, not lazily on first resolveByAlias call — allowlist becomes the registered set; no FQN-injection path through the registry"
  - "register-time App::make($sAdapterClass) is safe because all shipping adapters (Order, CartPosition, ThemeActionEvent) have parameterless constructors AND return constant alias from getSubjectType (A3 verified — RESEARCH §5)"
  - "AdapterRegistry class PHPDoc gains 'parameterless constructors' convention line — surfaces the implicit contract Phase 6+ third-party adapters must honor"
  - "Test-local fake adapters (FakeFooSubjectAdapter, FakeBarHybridAdapter) live in Logingrupa\\Metapixel\\Tests\\Unit\\Adapter\\Doubles sub-namespace — NOT in shared tests/doubles/ — they are unit-test fixtures local to this single test file"

# Threat model status
threat_mitigations:
  - id: T-6-04
    category: "Tampering / Elevation"
    component: "ThemeAjaxHandler hybrid path"
    status: "mitigated (allowlist landed at registry)"
    notes: "resolveByAlias lookup against $arAliasMap (operator-registered set). Throws UnknownSubjectTypeException on miss. NO 'new $sFqn(...)' instantiation path in registry. Consumer (plan 06-06) catches at boundary."
  - id: T-6-W3-T
    category: "Tampering"
    component: "Third-party adapter alias collision"
    status: "mitigated by idempotent overwrite + defense-in-depth at ThemeAjaxHandler"
    notes: "Last register() call wins (documented PHPDoc contract). Operator-side concern; allowlist principle. ThemeAjaxHandler hybrid path adds instanceof SupportsHybridAjax check + loadSubject re-enforces domain guards (planned plan 06-06)."
  - id: T-6-W3-R
    category: "Repudiation"
    component: "Boot-time App::make adapter constructor failure"
    status: "accepted"
    notes: "RESEARCH Pitfall 11 — operator's plugin order issue. Documented in AdapterRegistry class PHPDoc 'Adapters MUST have parameterless constructors'."

# Metrics
duration: 4min 52sec
completed: 2026-05-28
---

# Phase 06 Plan 03: AdapterRegistry alias resolution + SupportsHybridAjax + UnknownSubjectTypeException Summary

**Locked surface for the hybrid AJAX path: O(1) alias → adapter-class lookup with allowlist semantics, opt-in subinterface for PK hydration, typed exception for the consumer boundary.**

## Objective met

Plan 06-03 ships the three artifacts that plans 06-04 / 06-05 / 06-06 consume:

1. **`AdapterRegistry::resolveByAlias(string $sAlias): string`** — translates an untrusted JS-supplied `subject_type` alias to a registered adapter class FQN, or throws `UnknownSubjectTypeException`. The alias index is built at `register()` time by calling `App::make($sAdapterClass)->getSubjectType(new \stdClass)` immediately after the existing `is_subclass_of` guard. This makes the allowlist byte-for-byte the operator-registered set — no FQN-injection path exists because the registry never instantiates a class from an untrusted string.

2. **`SupportsHybridAjax` marker subinterface** — extends `EventSubjectAdapter` and declares `loadSubject(int $iSubjectId, array $arContext): ?object`. Adapters that need PK-based hydration opt in. The base `EventSubjectAdapter` interface stays unchanged, so Phase 2's `EventSubjectAdapterContractTestCase` 10 invariants remain intact (no BC break, no contract churn).

3. **`UnknownSubjectTypeException`** — extends `MetaPixelException` with empty body, inherits the 4-arg constructor + `getContext()`. Same minimal shape as `MissingCapiTokenException` and `OrderHasNoCurrencyException`. Plan 06-06's `ThemeAjaxHandler::onBeforeRun` will catch and translate to `JsonResponse 422`.

## What changed

### `classes/adapter/AdapterRegistry.php` (modified)

- Added `use Logingrupa\Metapixel\Classes\Exception\UnknownSubjectTypeException;` import.
- Added `private array $arAliasMap = []` field with `array<string, class-string<EventSubjectAdapter>>` PHPDoc.
- Inside `register()` after `$this->arAdapterMap[$sSubjectClass] = $sAdapterClass;` — added register-time alias-index population via `App::make($sAdapterClass)->getSubjectType(new \stdClass)`. Inline comment cites RESEARCH §5 / A3 and the alias-opacity contract documented on `EventSubjectAdapter::getSubjectType`.
- Added new `public function resolveByAlias(string $sAlias): string` with `@throws UnknownSubjectTypeException` + `@return class-string<EventSubjectAdapter>` PHPDoc. Body: `isset` check on `$arAliasMap`, throw with message `"No adapter registered for subject_type alias '{$sAlias}'"` on miss, return the FQN on hit.
- Class-level PHPDoc gained one line: `Adapters MUST have parameterless constructors so the register-time alias-index population (App::make($sAdapterClass)) cannot fail.`

### `classes/adapter/SupportsHybridAjax.php` (new)

`interface SupportsHybridAjax extends EventSubjectAdapter` with one method:

```
public function loadSubject(int $iSubjectId, array $arContext): ?object;
```

PHPDoc documents the bypass-via-AJAX hazard — adapters MUST re-enforce active scope, soft-delete scope, and site-match inside `loadSubject`. Return null on missing/inactive/soft-deleted/site-mismatch.

### `classes/exception/UnknownSubjectTypeException.php` (new)

```
final class UnknownSubjectTypeException extends MetaPixelException {}
```

One-line PHPDoc summary identifies the throw site (AdapterRegistry::resolveByAlias) and catch site (ThemeAjaxHandler::onBeforeRun → JsonResponse 422).

### `tests/Unit/Adapter/AdapterRegistryResolveByAliasTest.php` (new)

`final class AdapterRegistryResolveByAliasTest extends MetapixelTestCase` with class-level `#[Group('adapter')]`. 6 `test_*` methods cover:

1. Known alias → registered FQN.
2. Unknown alias → `UnknownSubjectTypeException` with the exact message.
3. Alias index persists across multiple `register()` calls (round-trips two aliases).
4. Idempotent re-register keeps the alias mapped to the same FQN.
5. `SupportsHybridAjax` adapter resolves through the alias index; resolved instance is subinterface-compatible.
6. `register()` guard still throws `InvalidArgumentException` when the adapter does not implement `EventSubjectAdapter`.

Test-local doubles `FakeFooSubjectAdapter` (alias `'fake.foo'`) and `FakeBarHybridAdapter` (alias `'fake.bar'`, implements `SupportsHybridAjax`) live in a `Logingrupa\Metapixel\Tests\Unit\Adapter\Doubles` sub-namespace declared with PHP bracketed multi-namespace syntax — keeps them local to the test file and out of the shared `tests/doubles/` autoload-dev classmap.

`setUp()` swaps a fresh `AdapterRegistry` singleton per test (`fn () => new AdapterRegistry`) so the alias index does not leak between tests.

## Verification

| Check | Result |
|-------|--------|
| `phpstan analyse classes/adapter/AdapterRegistry.php classes/adapter/SupportsHybridAjax.php classes/exception/UnknownSubjectTypeException.php --level=10 --no-progress` | **PASS** — No errors |
| `pest tests/Unit/Adapter/AdapterRegistryResolveByAliasTest.php --no-coverage` | **PASS** — 6 / 6 tests passing (9 assertions) |
| `pest tests/Unit/PluginSanityTest.php --no-coverage` | **PASS** — 5 / 5 (boot-time adapter registrations still succeed with new alias-index step) |
| `pest tests/Unit/Adapter/ --no-coverage --compact` | **PASS** — 64 / 64 (no regression on existing AdapterRegistry test surface — resolveFor, resolveByClass, all, register guard, hierarchy walk, singleton binding, flush, boot order) |
| `grep -q 'interface SupportsHybridAjax extends EventSubjectAdapter' classes/adapter/SupportsHybridAjax.php` | **PASS** |
| `grep -q 'final class UnknownSubjectTypeException extends MetaPixelException' classes/exception/UnknownSubjectTypeException.php` | **PASS** |
| `grep -q 'public function resolveByAlias' classes/adapter/AdapterRegistry.php` | **PASS** |
| `grep -q 'private array \$arAliasMap' classes/adapter/AdapterRegistry.php` | **PASS** |
| `grep -q 'new \\stdClass' classes/adapter/AdapterRegistry.php` | **PASS** (matches `new \stdClass` literal; plan's `'new \\\\stdClass'` over-escaped grep would only match a literal double-backslash that PHP source never uses) |
| `pest tests/Unit/Adapter/AdapterRegistryResolveByAliasTest.php --exclude-group=adapter` | **PASS** — "No tests found" (file correctly tagged for minimal-install CI cell isolation) |
| `pest tests/Unit/Adapter/AdapterRegistryResolveByAliasTest.php --group=adapter --filter=AdapterRegistry` | **PASS** — 6 included |

## Phase 2 invariant preservation

`EventSubjectAdapter` base interface is UNCHANGED — the 7 method signatures every Phase 2 contract test relies on (`getSubjectType`, `getSubjectId`, `getSiteId`, `getSecretKey`, `getValueResolver`, `getUserData`, `getSupportedEvents`) stay byte-for-byte identical. `SupportsHybridAjax` is a separate subinterface; adapters opt in by implementing it. No Phase 2 contract test required modification.

PluginSanityTest's `test_register_and_boot_are_callable_without_error` is the canonical regression for boot-time adapter registration with the new register-time `App::make` step. It still passes — verifies the Phase 2-shipped Order + CartPosition + ThemeActionEvent adapters all have parameterless constructors.

## Threat surface — T-6-04 mitigation status

| Threat ID | Status | Notes |
|-----------|--------|-------|
| T-6-04 (Tampering: FQN injection via subject_type) | **mitigated** at registry | `resolveByAlias` lookups against `$arAliasMap` only. No `new $sFqn(...)` path in registry. Test 2 above is the unit-level gate. |
| T-6-W3-T (Tampering: Third-party alias collision) | **mitigated** by idempotent overwrite contract + defense-in-depth | Last `register()` call wins (documented PHPDoc). Allowlist principle: only operator-installed plugins can register. ThemeAjaxHandler will add `instanceof SupportsHybridAjax` + `loadSubject` re-enforcement (plan 06-06). |
| T-6-W3-R (Repudiation: boot-time constructor failure) | **accepted** | RESEARCH Pitfall 11. Documented in AdapterRegistry class PHPDoc convention. |

## Unblock list

This plan ships the registry surface the next three plans depend on:

- **06-04 (ShopaholicProductAdapter)** — implements `SupportsHybridAjax`; supplies `'shopaholic.product'` alias from `getSubjectType` and PK hydration from `loadSubject` (Product model + active/visible/published scope re-enforcement + site-match).
- **06-05 (ProductPageWatcher)** — dispatches `ViewContent` via the adapter chain; Plugin.boot adapter registration auto-builds the alias index, no watcher-side change.
- **06-06 (ThemeAjaxHandler hybrid path)** — calls `AdapterRegistry::resolveByAlias($sSubjectTypeFromJs)` inside `onBeforeRun`; catches `UnknownSubjectTypeException` → returns `JsonResponse(['error' => 'unknown_subject_type'], 422)`. Confirms `instanceof SupportsHybridAjax` before calling `loadSubject`.

## Deviations from Plan

None — plan executed exactly as written.

## Commits

| Task | Commit | Description |
|------|--------|-------------|
| 1 | `54c056c` | feat(06-03): add resolveByAlias + SupportsHybridAjax + UnknownSubjectTypeException |
| 2 | `a8a8ab7` | test(06-03): cover AdapterRegistry::resolveByAlias with 6 unit tests |

## Self-Check: PASSED

- `classes/adapter/SupportsHybridAjax.php` FOUND
- `classes/exception/UnknownSubjectTypeException.php` FOUND
- `classes/adapter/AdapterRegistry.php` (modified) FOUND with `resolveByAlias` + `$arAliasMap`
- `tests/Unit/Adapter/AdapterRegistryResolveByAliasTest.php` FOUND with 6 `test_*` methods + class-level `#[Group('adapter')]`
- Commit `54c056c` FOUND in `git log` (Task 1)
- Commit `a8a8ab7` FOUND in `git log` (Task 2)
