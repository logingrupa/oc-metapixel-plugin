---
phase: 02-adapter-system-core-contracts-registry-extension-hooks
plan: 1
slug: interfaces-registry-singleton-binding
type: execute
wave: 1
depends_on: []
files_modified:
  - plugins/logingrupa/metapixel/classes/Adapter/EventSubjectAdapter.php
  - plugins/logingrupa/metapixel/classes/Adapter/ValueResolver.php
  - plugins/logingrupa/metapixel/classes/Adapter/AdapterRegistry.php
  - plugins/logingrupa/metapixel/Plugin.php
  - plugins/logingrupa/metapixel/phpstan.neon
  - plugins/logingrupa/metapixel/tests/Doubles/FakeAdapter.php
  - plugins/logingrupa/metapixel/tests/Doubles/FakeValueResolver.php
  - plugins/logingrupa/metapixel/tests/Doubles/TestSubject.php
  - plugins/logingrupa/metapixel/tests/Doubles/TestSubjectAdapter.php
  - plugins/logingrupa/metapixel/tests/Doubles/ZeroIdSubjectAdapter.php
  - plugins/logingrupa/metapixel/tests/Doubles/FakeStubAdapter.php
  - plugins/logingrupa/metapixel/tests/Unit/Adapter/AdapterRegistryTest.php
  - plugins/logingrupa/metapixel/tests/Unit/Adapter/AdapterRegistrySingletonBindingTest.php
  - plugins/logingrupa/metapixel/tests/Unit/Adapter/AdapterRegistryInvalidAdapterTest.php
  - plugins/logingrupa/metapixel/tests/Unit/Adapter/AdapterRegistryBootOrderTest.php
  - plugins/logingrupa/metapixel/tests/Unit/Adapter/AdapterRegistryFlushTest.php
autonomous: true
requirements:
  - ADAP-01
  - ADAP-02
  - ADAP-03
maps_to:
  pitfalls:
    - P-02
  decisions:
    - D-01
    - D-08
    - D-09
    - D-10
    - D-14
must_haves:
  truths:
    - "`Logingrupa\\Metapixel\\Classes\\Adapter\\EventSubjectAdapter` interface exists with exactly seven methods: getSubjectType, getSubjectId, getSiteId, getSecretKey, getValueResolver, getUserData, getSupportedEvents."
    - "`Logingrupa\\Metapixel\\Classes\\Adapter\\ValueResolver` interface exists with exactly five methods: resolveContentIds, resolveValue, resolveCurrency, resolveContents, resolveNumItems."
    - "`Logingrupa\\Metapixel\\Classes\\Adapter\\AdapterRegistry` is a final class with methods register, all, resolveFor, resolveByClass."
    - "`AdapterRegistry::register()` throws InvalidArgumentException when the adapter class does not implement EventSubjectAdapter."
    - "`AdapterRegistry::register()` is idempotent — re-registering the same pair is a no-op, and order of registration does not change resolution outcome (P-02 invariant)."
    - "`AdapterRegistry::resolveFor()` walks the class hierarchy via is_a() and returns null on miss (lazy App::make on hit)."
    - "`AdapterRegistry::resolveByClass()` returns an EventSubjectAdapter instance for queue-rehydrate use."
    - "`Plugin::register()` binds AdapterRegistry as a singleton in October's service container."
    - "`tests/Doubles/` ships 6 shared fixtures (FakeAdapter + FakeValueResolver + TestSubject + TestSubjectAdapter + ZeroIdSubjectAdapter + FakeStubAdapter) under `Logingrupa\\Metapixel\\Tests\\Doubles\\` autoload-dev — every downstream plan imports by FQN (H-6 collision-elimination). SpyMetaClient ships in plan 02-05 Task 6 alongside MetaClient."
    - "Test setUp pattern across Phase 2 binds AdapterRegistry singleton directly via `$this->app->singleton(AdapterRegistry::class)` — never via `(new Plugin)->register()` (H-8 — PluginBase constructor requires container injection)."
    - "phpstan.neon scans classes/ and tests/Unit/Adapter/ folders without errors at level 10 against phpVersion 80300."
    - "`composer qa` exits 0 from plugins/logingrupa/metapixel/ after this plan ships."
  artifacts:
    - path: "plugins/logingrupa/metapixel/classes/Adapter/EventSubjectAdapter.php"
      provides: "ADAP-01 — adapter interface contract."
      contains: "interface EventSubjectAdapter"
    - path: "plugins/logingrupa/metapixel/classes/Adapter/ValueResolver.php"
      provides: "ADAP-02 — value resolver interface contract."
      contains: "interface ValueResolver"
    - path: "plugins/logingrupa/metapixel/classes/Adapter/AdapterRegistry.php"
      provides: "ADAP-03 — service-container singleton registry."
      contains: "final class AdapterRegistry"
    - path: "plugins/logingrupa/metapixel/Plugin.php"
      provides: "AdapterRegistry singleton binding inside register()."
      contains: "$this->app->singleton(AdapterRegistry::class)"
    - path: "plugins/logingrupa/metapixel/tests/Doubles/FakeAdapter.php"
      provides: "D-08 shared fluent adapter double — Wave 1 lands so all Phase 2 tests reuse (H-6 / M-9)."
      contains: "final class FakeAdapter"
    - path: "plugins/logingrupa/metapixel/tests/Doubles/FakeValueResolver.php"
      provides: "D-10 shared resolver double."
      contains: "final class FakeValueResolver"
    - path: "plugins/logingrupa/metapixel/tests/Unit/Adapter/AdapterRegistryTest.php"
      provides: "Register + resolveFor + is_a hierarchy walk coverage."
      contains: "register"
    - path: "plugins/logingrupa/metapixel/tests/Unit/Adapter/AdapterRegistryBootOrderTest.php"
      provides: "P-02 anchor — register-in-any-order invariant."
      contains: "BootOrder"
  key_links:
    - from: "plugins/logingrupa/metapixel/Plugin.php"
      to: "plugins/logingrupa/metapixel/classes/Adapter/AdapterRegistry.php"
      via: "App::singleton binding in register()"
      pattern: "singleton\\(AdapterRegistry::class"
    - from: "plugins/logingrupa/metapixel/classes/Adapter/AdapterRegistry.php"
      to: "plugins/logingrupa/metapixel/classes/Adapter/EventSubjectAdapter.php"
      via: "is_subclass_of guard in register()"
      pattern: "is_subclass_of\\(\\$sAdapterClass, EventSubjectAdapter::class\\)"
    - from: "plugins/logingrupa/metapixel/tests/Doubles/"
      to: "plugins/logingrupa/metapixel/classes/Adapter/EventSubjectAdapter.php"
      via: "implements EventSubjectAdapter on each adapter double"
      pattern: "implements EventSubjectAdapter"
---

<objective>
Ship the two Phase 2 contract interfaces (`EventSubjectAdapter`, `ValueResolver`), the service-container singleton registry (`AdapterRegistry`), and the shared `tests/Doubles/` fixture set every downstream Phase 2 plan imports. Bind the registry in `Plugin::register()`. Cover the registry with five unit tests proving idempotent registration, `is_a()` hierarchy walk, invalid-adapter rejection, boot-order invariance (P-02), and singleton-binding test-swap.

Purpose: closes ADAP-01, ADAP-02, ADAP-03 + P-02. All downstream Phase 2 plans depend on these three production classes AND on the shared doubles (H-6 / M-9 resolution — eliminates redeclaration collisions across test files in 02-04 / 02-06 / 02-07).

Output: 3 production classes + 1 Plugin.php edit + 1 phpstan.neon path expansion + 7 shared test doubles (`tests/Doubles/*`) + 5 unit test files.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@plugins/logingrupa/metapixel/CLAUDE.md
@plugins/logingrupa/metapixel/.planning/PROJECT.md
@plugins/logingrupa/metapixel/.planning/ROADMAP.md
@plugins/logingrupa/metapixel/.planning/REQUIREMENTS.md
@plugins/logingrupa/metapixel/.planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-CONTEXT.md
@plugins/logingrupa/metapixel/.planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-RESEARCH.md
@plugins/logingrupa/metapixel/Plugin.php
@plugins/logingrupa/metapixel/phpstan.neon
@plugins/logingrupa/metapixel/composer.json
@plugins/logingrupa/metapixel/tests/MetapixelTestCase.php
@plugins/logingrupa/metapixel/tests/Pest.php

<interfaces>
Locked decisions from 02-CONTEXT.md (D-01, D-08..D-10, D-14) + 02-RESEARCH.md §4.1–§4.3 + §4.15 + §6:

- All code is FRESH — no port from legacy/v1.1.1.
- AdapterRegistry is a `final` class bound via `App::singleton(AdapterRegistry::class)` in Plugin::register(). Tests swap a fresh instance per test via `$this->app->instance(AdapterRegistry::class, new AdapterRegistry)`.
- `is_a()` walk semantics: foreach order = map-insertion order. RESEARCH.md A3 documents the sibling-class-collision edge case as a known gotcha — explicit priority deferred to v2.1.
- Hungarian notation everywhere: `$obSubject`, `$arAdapterMap`, `$sSubjectClass`, `$sAdapterClass`, `$iCount`, `$bIsActive`.
- No `assert()`. No `declare(strict_types=1)`. No comment pollution. Short Laravel docblocks (one-line summary + `@param` + `@return`).
- October 4 + Laravel 12: prefer `Illuminate\Support\Facades\App` (consistent + grep-able per L-4) — same alias OctoberCMS registers.
- **L-4 lock:** every new file across Phase 2 imports `Illuminate\Support\Facades\Log` (FQN form) for the Log facade. Never `use Log;`. Same for `Illuminate\Support\Facades\App`, `Illuminate\Support\Facades\Event`, `Illuminate\Support\Facades\DB`.

EventSubjectAdapter interface contract (verbatim from ADAP-01 + §4.1):
- `getSubjectType(object $obSubject): string` — opaque alias, NEVER class FQN (P-05 anchor). e.g. 'shopaholic.order', 'theme.action'.
- `getSubjectId(object $obSubject): int`
- `getSiteId(object $obSubject): ?int` — MUST read from subject, never request context (P-01 anchor). PHPStan-banned in adapter/queue/event dirs by plan 02-02.
- `getSecretKey(object $obSubject): ?string`
- `getValueResolver(object $obSubject): ValueResolver`
- `getUserData(object $obSubject): array<string, ?string>` — keys MUST be one of: em, ph, fn, ln, ct, st, zp, country, external_id, fbp, fbc, client_ip_address, client_user_agent. Missing keys = null.
- `getSupportedEvents(): array<string, list<string>>` — e.g. ['Purchase' => ['capi', 'pixel']].

ValueResolver interface contract (verbatim from ADAP-02 + §4.2):
- `resolveContentIds(object $obSubject): array` (list<string>)
- `resolveValue(object $obSubject): float`
- `resolveCurrency(object $obSubject): string`
- `resolveContents(object $obSubject): array` (list<array{id: string, quantity: int, item_price: float}>)
- `resolveNumItems(object $obSubject): int`

AdapterRegistry contract (verbatim from ADAP-03 + §4.3):
- `register(string $sSubjectClass, string $sAdapterClass): void` — throws InvalidArgumentException when adapter does not implement EventSubjectAdapter. Idempotent: re-registering same pair is a no-op. Order-agnostic.
- `all(): array` (list of registered adapter class names — used by Phase 3 conditional-registration smoke).
- `resolveFor(object $obSubject): ?EventSubjectAdapter` — lazy `App::make`, walks class hierarchy via `is_a()`, returns null on miss.
- `resolveByClass(string $sAdapterClass): EventSubjectAdapter` — for queue-rehydrate.

Shared `tests/Doubles/` fixture suite (H-6 / M-9 resolution — Wave 1 lands so plans 02-04 / 02-06 / 02-07 import by FQN, no redeclaration collisions):

| File | FQN | Role |
|------|-----|------|
| FakeAdapter.php | `Logingrupa\Metapixel\Tests\Doubles\FakeAdapter` | Fluent adapter double (D-08) — defaults: subject_type='fake.subject', subject_id=1, site_id=null, supportedEvents=['Purchase'=>['capi','pixel']]. 7 with* setters. |
| FakeValueResolver.php | `Logingrupa\Metapixel\Tests\Doubles\FakeValueResolver` | Constructor-defaults resolver double (D-10) — defaults: contentIds=['SKU-1'], value=10.0, currency='EUR', contents=[…], numItems=1. |
| TestSubject.php | `Logingrupa\Metapixel\Tests\Doubles\TestSubject` | Plain DTO class with `public int $iId = 42`. Used by plans 02-04 / 02-06 / 02-07 as the subject the registry maps to TestSubjectAdapter. |
| TestSubjectAdapter.php | `Logingrupa\Metapixel\Tests\Doubles\TestSubjectAdapter` | Adapter whose getSubjectId returns `$obSubject->iId` + getSubjectType returns 'fake.subject' + getSiteId returns null. Used by EventLogWriterRaceFenceTest + queue feature tests. |
| ZeroIdSubjectAdapter.php | `Logingrupa\Metapixel\Tests\Doubles\ZeroIdSubjectAdapter` | Extends TestSubjectAdapter; getSubjectId returns 0 (used to test EventLogWriter's `<= 0` reject branch). |
| FakeStubAdapter.php | `Logingrupa\Metapixel\Tests\Doubles\FakeStubAdapter` | Minimal `final` EventSubjectAdapter for hook-isolation unit tests (BeforeDispatchHaltTest etc). Defaults same as FakeAdapter but immutable. |
| SpyMetaClient.php | `Logingrupa\Metapixel\Tests\Doubles\SpyMetaClient` | `class SpyMetaClient extends MetaClient` with `public int $iCallCount = 0` + `public array $arLastPayload = []` + sendForPixel override returning `['events_received' => 1]`. Constructor calls `parent::__construct(null)`. NOT `final` (test subclasses may want a throwing variant). |

D-09 reminder: all 7 doubles live under `tests/Doubles/` — autoload-dev only. Production `composer install --no-dev` never autoloads them. Plan 02-07's contract base (under `classes/Testing/`) is a separate production-namespace artifact (H-3 — switches to Orchestra Testbench).

**H-8 test setUp pattern (apply across every Phase 2 test file):**

Every Phase 2 test that needs the AdapterRegistry singleton bound MUST bind it directly in setUp() instead of instantiating Plugin. `PluginBase::__construct(Application $app)` requires container injection — `new \Logingrupa\Metapixel\Plugin` (no args) raises TypeError. Pattern:

```php
protected function setUp(): void
{
    parent::setUp();
    $this->app->singleton(AdapterRegistry::class);
    // optional per-test: register adapters via app(AdapterRegistry::class)->register(...)
}
```

This is the option-B fix from the plan-checker H-8 finding. It bypasses Plugin::register() entirely + achieves the same bind. Every Phase 2 plan uses it.

Phase 1 wired:
- `Logingrupa\Metapixel\Plugin` boots with empty register() / boot() (verify by re-reading Plugin.php).
- `tests/MetapixelTestCase` boots OctoberCMS in SQLite-in-memory without cart-plugin migrations.
- `tests/Pest.php` binds MetapixelTestCase to tests/Unit and tests/Feature.
- phpstan.neon currently scans Plugin.php only — Phase 2 expands to `classes`, `models`.
- composer-dependency-analyser.php scans /classes and /models when they exist (file_exists guard).
- composer scripts.qa chain: pint-test → analyse → phpmd → test-cov (see composer.json:46-55). Note: `phpmd` script currently targets `Plugin.php` only — Phase 2 extends scope as classes/ appears (this plan's first classes/ files).
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Create EventSubjectAdapter + ValueResolver interfaces</name>
  <files>
    plugins/logingrupa/metapixel/classes/Adapter/EventSubjectAdapter.php
    plugins/logingrupa/metapixel/classes/Adapter/ValueResolver.php
  </files>
  <behavior>
    - `EventSubjectAdapter` is an interface (PHP `interface` keyword, not class) under namespace `Logingrupa\Metapixel\Classes\Adapter`.
    - Declares exactly 7 methods with the signatures listed in `<interfaces>` above. Each method has a one-line PHPDoc summary plus `@param` + `@return` only.
    - `getSubjectType` PHPDoc references "opaque alias" + "P-05 anchor" framing (without literally writing `P-05` in source — describe the constraint in prose: "MUST be an opaque alias such as 'shopaholic.order'; MUST NOT contain backslashes; MUST NOT be a class FQN").
    - `getSiteId` PHPDoc references "MUST read from subject" + cross-context determinism constraint in prose (without writing `P-01`).
    - `getUserData` PHPDoc enumerates the allowed key set: em, ph, fn, ln, ct, st, zp, country, external_id, fbp, fbc, client_ip_address, client_user_agent.
    - `getSupportedEvents` PHPDoc documents `array<string, list<string>>` shape with channel subset `{'capi', 'pixel'}`.
    - `ValueResolver` is an interface with the 5 methods listed in `<interfaces>`. Same PHPDoc style.
    - php -l clean on both files.
  </behavior>
  <action>
Create `classes/Adapter/EventSubjectAdapter.php` exactly to the §4.1 shape from 02-RESEARCH.md. Mirror the PHPDoc verbatim from research §4.1 (per D-01 fresh-write — research IS the spec). NO comment pollution: zero `// P-05`, `// CR-XX`, `// Phase N`, `// Plan N` markers. Use Hungarian-notation parameters (`object $obSubject`).

Then create `classes/Adapter/ValueResolver.php` to §4.2 shape. Five methods, plain `@return` PHPDoc with `array` arrays generic-typed via `@return list<string>` / `@return list<array{id: string, quantity: int, item_price: float}>` style.

Both interfaces declare `namespace Logingrupa\Metapixel\Classes\Adapter;` and reside in `classes/Adapter/` directory under the plugin root. PSR-4 autoload prefix `Logingrupa\\Metapixel\\` maps the plugin root to namespace root (composer.json:38), so `classes/Adapter/EventSubjectAdapter.php` resolves to `Logingrupa\Metapixel\Classes\Adapter\EventSubjectAdapter`.

NO `assert()`. NO `declare(strict_types=1)` line. The file opens with `<?php` followed by a blank line then `namespace ...;` (Pint preset rule).
  </action>
  <verify>
    <automated>test -f plugins/logingrupa/metapixel/classes/Adapter/EventSubjectAdapter.php &amp;&amp; test -f plugins/logingrupa/metapixel/classes/Adapter/ValueResolver.php &amp;&amp; php -l plugins/logingrupa/metapixel/classes/Adapter/EventSubjectAdapter.php | grep -q 'No syntax errors' &amp;&amp; php -l plugins/logingrupa/metapixel/classes/Adapter/ValueResolver.php | grep -q 'No syntax errors' &amp;&amp; grep -q 'interface EventSubjectAdapter' plugins/logingrupa/metapixel/classes/Adapter/EventSubjectAdapter.php &amp;&amp; grep -q 'interface ValueResolver' plugins/logingrupa/metapixel/classes/Adapter/ValueResolver.php &amp;&amp; grep -c 'public function' plugins/logingrupa/metapixel/classes/Adapter/EventSubjectAdapter.php | grep -Eq '^7$' &amp;&amp; grep -c 'public function' plugins/logingrupa/metapixel/classes/Adapter/ValueResolver.php | grep -Eq '^5$' &amp;&amp; ! grep -E '(// CR-[0-9]|// Phase\s*[0-9]|// Plan\s*[0-9]|// P-0[0-9]|declare\(strict_types)' plugins/logingrupa/metapixel/classes/Adapter/EventSubjectAdapter.php &amp;&amp; ! grep -E '(// CR-[0-9]|// Phase\s*[0-9]|// Plan\s*[0-9]|// P-0[0-9]|declare\(strict_types)' plugins/logingrupa/metapixel/classes/Adapter/ValueResolver.php</automated>
  </verify>
  <done>Both interface files exist; php -l clean; 7 methods on EventSubjectAdapter; 5 methods on ValueResolver; no phase/CR/P-XX markers; no declare(strict_types).</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Create AdapterRegistry final class</name>
  <files>
    plugins/logingrupa/metapixel/classes/Adapter/AdapterRegistry.php
  </files>
  <behavior>
    - Final class `Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry`.
    - Private property `$arAdapterMap` typed `array<class-string, class-string<EventSubjectAdapter>>` via PHPDoc.
    - `register(string $sSubjectClass, string $sAdapterClass): void` — throws `\InvalidArgumentException` when `is_subclass_of($sAdapterClass, EventSubjectAdapter::class)` is false; otherwise sets `$this->arAdapterMap[$sSubjectClass] = $sAdapterClass`. Re-registering the same pair leaves the map unchanged (idempotent).
    - `all(): array` returns `array_values($this->arAdapterMap)` (list of adapter class FQNs).
    - `resolveFor(object $obSubject): ?EventSubjectAdapter` — direct `get_class()` hit goes to `App::make($this->arAdapterMap[$sClass])`; otherwise foreach the map and return `App::make` for the first `is_a($obSubject, $sRegisteredClass, true)` hit; return null on miss.
    - `resolveByClass(string $sAdapterClass): EventSubjectAdapter` returns `App::make($sAdapterClass)` unconditionally (caller wraps `BindingResolutionException` per ADAP-10 — that's plan 02-06's concern).
    - Class-level PHPDoc documents singleton binding pattern + test-swap idiom.
    - File ≤ 70 LOC executable (PHPDoc + braces excluded), every method ≤ 20 LOC.
  </behavior>
  <action>
Write `classes/Adapter/AdapterRegistry.php` to the §4.3 shape from 02-RESEARCH.md. Import via `use Illuminate\Support\Facades\App;` (L-4 lock — FQN preferred over the October alias for grep-ability + test-time replacement).

Class-level PHPDoc (short, Laravel-style) documents the singleton binding pattern (`$this->app->singleton(AdapterRegistry::class)` in Plugin::register), the test-swap idiom (`$this->app->instance(AdapterRegistry::class, new AdapterRegistry)`), and the is_a walk semantics (foreach order = map-insertion order; sibling-class collision is undefined and resolved by registration order; no explicit priority API in v2.0).

Method PHPDocs are one-liners per the Laravel short-docblock rule:
- `register(string $sSubjectClass, string $sAdapterClass): void` — one-line summary + `@throws \InvalidArgumentException`.
- `all(): array` — one-line summary + `@return list<class-string<EventSubjectAdapter>>`.
- `resolveFor(object $obSubject): ?EventSubjectAdapter` — one-line summary.
- `resolveByClass(string $sAdapterClass): EventSubjectAdapter` — one-line summary noting use by SendCapiEvent::handle for queue rehydrate.

Idempotency: `$this->arAdapterMap[$sSubjectClass] = $sAdapterClass;` is naturally idempotent for the same-pair case. If `$sSubjectClass` is re-registered with a DIFFERENT adapter, the new value silently wins (no exception, no log warning — documented in research as "registration order wins"). Do NOT add throw-on-conflict logic — keeps the registry simple.

Use `is_subclass_of($sAdapterClass, EventSubjectAdapter::class)` (works with interface FQNs) not `is_a` (which expects an object or requires `$allow_string=true` flag).
  </action>
  <verify>
    <automated>test -f plugins/logingrupa/metapixel/classes/Adapter/AdapterRegistry.php &amp;&amp; php -l plugins/logingrupa/metapixel/classes/Adapter/AdapterRegistry.php | grep -q 'No syntax errors' &amp;&amp; grep -q 'final class AdapterRegistry' plugins/logingrupa/metapixel/classes/Adapter/AdapterRegistry.php &amp;&amp; grep -q 'is_subclass_of' plugins/logingrupa/metapixel/classes/Adapter/AdapterRegistry.php &amp;&amp; grep -q 'InvalidArgumentException' plugins/logingrupa/metapixel/classes/Adapter/AdapterRegistry.php &amp;&amp; grep -Eq 'function register|function all|function resolveFor|function resolveByClass' plugins/logingrupa/metapixel/classes/Adapter/AdapterRegistry.php &amp;&amp; grep -q 'use Illuminate\\\\Support\\\\Facades\\\\App;' plugins/logingrupa/metapixel/classes/Adapter/AdapterRegistry.php &amp;&amp; ! grep -E '(// CR-[0-9]|// Phase\s*[0-9]|// Plan\s*[0-9]|// P-0[0-9])' plugins/logingrupa/metapixel/classes/Adapter/AdapterRegistry.php</automated>
  </verify>
  <done>AdapterRegistry.php exists; php -l clean; final class; uses is_subclass_of guard with InvalidArgumentException; 4 public methods; uses Illuminate\Support\Facades\App FQN import; no phase markers.</done>
</task>

<task type="auto">
  <name>Task 3: Wire Plugin::register() singleton binding + expand phpstan paths</name>
  <files>
    plugins/logingrupa/metapixel/Plugin.php
    plugins/logingrupa/metapixel/phpstan.neon
  </files>
  <action>
Edit `Plugin.php`:

Current state (verified at session start):

```
public function register(): void {}
public function boot(): void {}
```

Add a `use` statement at the top (alphabetical, per pint `ordered_imports: alpha`):

```
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
```

Replace `register()` body with a single `$this->app->singleton(AdapterRegistry::class)` call. `boot()` stays empty in this plan — plan 02-03b adds `registerSettings()` (a separate method, not inside boot()). No Phase 3 conditional adapter registration in this phase.

Refresh the class-level PHPDoc paragraph to drop any "Phase 1 ships an empty boot/register" wording (per project lock no phase markers in code). Document: register() binds the AdapterRegistry singleton; third parties register their adapters from their own Plugin::boot() by calling `AdapterRegistry::register($sSubjectClass, $sAdapterClass)`.

Edit `phpstan.neon`:

Current `paths:` is `[Plugin.php]`. Expand to a multi-line block including `Plugin.php` + `classes`. Do NOT add `models` yet — plan 02-03a lands models/ and updates phpstan paths then. Each phase reopens this list as files appear.

Keep all Phase 1 disallowed* sections verbatim. Plan 02-02 adds the SiteManager / request / Request bans + scoped `disallowIn` paths (H-1 — deny-list per RESEARCH §5.1 verbatim, NOT allow-list).

Also update the composer.json `phpmd` script to scan `classes/` in addition to `Plugin.php`:

Current: `"phpmd": "phpmd Plugin.php text phpmd.xml"`
New:     `"phpmd": "phpmd Plugin.php,classes text phpmd.xml"`

(Comma-separated path list is phpmd-native. No quoting issues since the path has no spaces.)

Also update `composer-dependency-analyser.php` — verify the production-code scan paths (currently `['/Plugin.php', '/classes', ...]`). `/classes` is already in the list with a `file_exists` guard — no edit needed; the registry directory creation in tasks 1 + 2 trips the guard automatically.
  </action>
  <verify>
    <automated>grep -q 'use Logingrupa\\\\Metapixel\\\\Classes\\\\Adapter\\\\AdapterRegistry;' plugins/logingrupa/metapixel/Plugin.php &amp;&amp; grep -q 'singleton(AdapterRegistry::class)' plugins/logingrupa/metapixel/Plugin.php &amp;&amp; php -l plugins/logingrupa/metapixel/Plugin.php | grep -q 'No syntax errors' &amp;&amp; grep -E '^\s+- classes\s*$' plugins/logingrupa/metapixel/phpstan.neon &amp;&amp; grep -q 'phpmd Plugin.php,classes' plugins/logingrupa/metapixel/composer.json</automated>
  </verify>
  <done>Plugin.php imports + binds AdapterRegistry; phpstan.neon scans classes; phpmd script extended; php -l clean.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 4: Ship 7 shared test doubles under tests/Doubles/ (H-6 + M-9 resolution)</name>
  <files>
    plugins/logingrupa/metapixel/tests/Doubles/FakeAdapter.php
    plugins/logingrupa/metapixel/tests/Doubles/FakeValueResolver.php
    plugins/logingrupa/metapixel/tests/Doubles/TestSubject.php
    plugins/logingrupa/metapixel/tests/Doubles/TestSubjectAdapter.php
    plugins/logingrupa/metapixel/tests/Doubles/ZeroIdSubjectAdapter.php
    plugins/logingrupa/metapixel/tests/Doubles/FakeStubAdapter.php
    plugins/logingrupa/metapixel/tests/Doubles/SpyMetaClient.php
  </files>
  <behavior>
    - All 7 files under namespace `Logingrupa\Metapixel\Tests\Doubles` (autoload-dev PSR-4 maps tests/ → that namespace per Phase 1 composer.json).
    - One class per file (PSR-4 strict). File basename matches class name PascalCase.
    - FakeAdapter: final + 7 fluent setters + 7 EventSubjectAdapter methods (defaults per RESEARCH §6).
    - FakeValueResolver: final + constructor-injected 5 defaults + 5 ValueResolver methods.
    - TestSubject: plain DTO, `public int $iId = 42`. No interface.
    - TestSubjectAdapter: final class implements EventSubjectAdapter; getSubjectType='fake.subject'; getSubjectId returns `$obSubject->iId` (typed against TestSubject but accepts any object — cast via property_exists guard). getSiteId=null default but constructor accepts `?int $iSiteId = null` to override.
    - ZeroIdSubjectAdapter: final class extends TestSubjectAdapter; getSubjectId returns 0 (used to exercise EventLogWriter's `<= 0` reject branch).
    - FakeStubAdapter: final class implements EventSubjectAdapter; immutable; minimal hook-test stub (no fluent setters). Defaults same shape as FakeAdapter but with no `with*` API.
    - SpyMetaClient: `class SpyMetaClient extends MetaClient` (NOT final — test files may subclass to throw). Constructor calls `parent::__construct(null)`. `public int $iCallCount = 0`. `public array $arLastPayload = []`. Override `sendForPixel(string $sPixelId, string $sToken, array $arPayload): array` → increments counter, captures payload, returns `['events_received' => 1]`.
    - All 7 files php -l clean.
    - NO comment pollution; short Laravel docblocks only.
  </behavior>
  <action>
Create `tests/Doubles/` directory. Each class file follows the shape verbatim from RESEARCH §6 (FakeAdapter + FakeValueResolver). The new fixtures (TestSubject + TestSubjectAdapter + ZeroIdSubjectAdapter + FakeStubAdapter + SpyMetaClient) are Phase 2-specific — designed once here, reused by 02-04, 02-06, 02-07.

PSR-4 file naming: `FakeAdapter.php` contains `class FakeAdapter` etc. Plan 02-01 ships AdapterRegistry + interfaces BEFORE the doubles in this same wave — order tasks: Task 1 → Task 2 → Task 3 → Task 4. SpyMetaClient extends `Logingrupa\Metapixel\Classes\Meta\MetaClient` which lands in plan 02-05 (Wave 3). So Task 4's SpyMetaClient WILL php -l clean (PHP only validates syntax, not autoload) but a `composer dump-autoload` run before plan 02-05 ships will not be able to instantiate SpyMetaClient. Verification: defer pest invocation on SpyMetaClient to plan 02-05+ — the file exists in Wave 1 for future plans to import, but it's not exercised in this plan's tests.

To avoid phpstan errors on SpyMetaClient (extends a class that does not yet exist), either:
- Add `classes/Meta/MetaClient.php` STUB to this plan (minimal `class MetaClient { public function sendForPixel(string, string, array): array { return []; } }`) — landed in Wave 1, replaced fully by plan 02-05 Wave 3. Plan 02-05 turns it into the full Guzzle-backed implementation.
- OR: defer SpyMetaClient to a SEPARATE shared-fixtures task in plan 02-05 (the wave that ships MetaClient).

Pick the SEPARATE-task-in-02-05 approach: SpyMetaClient lands in plan 02-05 task 6 alongside MetaClient (NOT in Task 4 of plan 02-01). This keeps Wave 1 self-consistent.

So Task 4 in plan 02-01 SHIPS 6 fixtures (FakeAdapter, FakeValueResolver, TestSubject, TestSubjectAdapter, ZeroIdSubjectAdapter, FakeStubAdapter) — NOT SpyMetaClient. Plan 02-05 task 6 ships SpyMetaClient (1 file) alongside MetaClient (Wave 3).

Update files_modified list above to reflect: SpyMetaClient.php is removed from this plan's frontmatter (it ships in plan 02-05 Task 6 — see plan 02-05 frontmatter for the file_modified entry).

Each adapter double's getValueResolver(): if it returns a ValueResolver instance, default to `new FakeValueResolver` (cross-double import within the same namespace).

Class-level PHPDoc on each double (one-line summary):

- FakeAdapter: "Fluent EventSubjectAdapter double. Instantiate fresh per test (no shared mutable state). Autoload-dev only — never autoloads in production (D-09)."
- FakeValueResolver: "Constructor-defaults ValueResolver double. Pair with FakeAdapter for round-trip tests."
- TestSubject: "Plain DTO subject fixture. Used as the subject class registered against TestSubjectAdapter in EventLogWriter + queue tests."
- TestSubjectAdapter: "EventSubjectAdapter double whose getSubjectId reads `$obSubject->iId`. getSiteId returns the constructor-supplied `$iSiteId` (null default)."
- ZeroIdSubjectAdapter: "TestSubjectAdapter variant whose getSubjectId returns 0 — used to exercise EventLogWriter's `<= 0` reject branch (T17)."
- FakeStubAdapter: "Immutable minimal EventSubjectAdapter for hook-isolation unit tests. Same shape as FakeAdapter; no fluent setters."

NO comment pollution; no `// shared fixture for H-6` markers; no `// used by 02-04` references.
  </action>
  <verify>
    <automated>for f in plugins/logingrupa/metapixel/tests/Doubles/FakeAdapter.php plugins/logingrupa/metapixel/tests/Doubles/FakeValueResolver.php plugins/logingrupa/metapixel/tests/Doubles/TestSubject.php plugins/logingrupa/metapixel/tests/Doubles/TestSubjectAdapter.php plugins/logingrupa/metapixel/tests/Doubles/ZeroIdSubjectAdapter.php plugins/logingrupa/metapixel/tests/Doubles/FakeStubAdapter.php; do test -f "$f" || { echo "missing $f"; exit 1; }; php -l "$f" | grep -q 'No syntax errors' || exit 1; done &amp;&amp; grep -q 'final class FakeAdapter' plugins/logingrupa/metapixel/tests/Doubles/FakeAdapter.php &amp;&amp; grep -q 'final class FakeValueResolver' plugins/logingrupa/metapixel/tests/Doubles/FakeValueResolver.php &amp;&amp; grep -q 'class TestSubject' plugins/logingrupa/metapixel/tests/Doubles/TestSubject.php &amp;&amp; grep -q 'class TestSubjectAdapter' plugins/logingrupa/metapixel/tests/Doubles/TestSubjectAdapter.php &amp;&amp; grep -q 'class ZeroIdSubjectAdapter extends TestSubjectAdapter' plugins/logingrupa/metapixel/tests/Doubles/ZeroIdSubjectAdapter.php &amp;&amp; grep -q 'class FakeStubAdapter' plugins/logingrupa/metapixel/tests/Doubles/FakeStubAdapter.php &amp;&amp; ! grep -rE '(// CR-[0-9]|// Phase\s*[0-9]|// Plan\s*[0-9]|// H-[0-9]|// M-[0-9])' plugins/logingrupa/metapixel/tests/Doubles/</automated>
  </verify>
  <done>6 fixture files exist (SpyMetaClient deferred to plan 02-05 Task 6); php -l clean on each; classes match expected names + inheritance; no comment pollution.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 5: Write AdapterRegistry unit tests (5 files, T1–T5)</name>
  <files>
    plugins/logingrupa/metapixel/tests/Unit/Adapter/AdapterRegistryTest.php
    plugins/logingrupa/metapixel/tests/Unit/Adapter/AdapterRegistrySingletonBindingTest.php
    plugins/logingrupa/metapixel/tests/Unit/Adapter/AdapterRegistryInvalidAdapterTest.php
    plugins/logingrupa/metapixel/tests/Unit/Adapter/AdapterRegistryBootOrderTest.php
    plugins/logingrupa/metapixel/tests/Unit/Adapter/AdapterRegistryFlushTest.php
  </files>
  <behavior>
    - All 5 files use PHPUnit classic style (`final class FooTest extends MetapixelTestCase` with `test_*` snake_case methods) — matches Phase 1's `PluginSanityTest.php` precedent (L-8 confirmation).
    - T1 `AdapterRegistryTest::test_register_and_resolve_for_returns_adapter_instance` + `test_resolve_for_walks_class_hierarchy_via_is_a` + `test_resolve_for_returns_null_when_subject_not_registered` + `test_all_returns_list_of_registered_adapter_class_names`.
    - T2 `AdapterRegistrySingletonBindingTest::test_singleton_binding_returns_same_instance` + `test_app_instance_swaps_fresh_registry_for_test_isolation` — exercises `App::make(AdapterRegistry::class) === App::make(AdapterRegistry::class)` and `$this->app->instance(AdapterRegistry::class, new AdapterRegistry); App::make(AdapterRegistry::class) === $obFreshInstance`.
    - T3 `AdapterRegistryInvalidAdapterTest::test_register_throws_when_adapter_class_does_not_implement_event_subject_adapter` — uses `stdClass` as bad adapter; expects `InvalidArgumentException`.
    - T4 `AdapterRegistryBootOrderTest::test_resolution_outcome_is_invariant_across_registration_order` — registers two adapters for two unrelated subject classes in order A,B then in order B,A; asserts both registries resolve identically. P-02 anchor.
    - T5 `AdapterRegistryFlushTest::test_app_forget_instance_re_binds_fresh_singleton` — `App::forgetInstance(AdapterRegistry::class)` then `App::make(AdapterRegistry::class)` returns a new empty registry (no leaked state from prior test).
    - Every test uses Task 4's shared doubles (FakeAdapter / FakeStubAdapter) by FQN import. NO inline anonymous-class declarations.
    - All tests use the H-8 setUp pattern: `$this->app->singleton(AdapterRegistry::class)` direct bind — never `(new Plugin)->register()`.
    - All tests pass: `vendor/bin/pest tests/Unit/Adapter --configuration phpunit.xml` exits 0.
  </behavior>
  <action>
Create directory `plugins/logingrupa/metapixel/tests/Unit/Adapter/` first (via the file write — `mkdir -p`-equivalent via Write).

For each test file, follow Phase 1 PluginSanityTest's PHPUnit classic style:

```
<?php

use Illuminate\Support\Facades\App;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Adapter\EventSubjectAdapter;
use Logingrupa\Metapixel\Tests\Doubles\FakeAdapter;
use Logingrupa\Metapixel\Tests\Doubles\FakeStubAdapter;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;

final class AdapterRegistryTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
    }

    public function test_register_and_resolve_for_returns_adapter_instance(): void { ... }
    // etc.
}
```

H-8 anchor: every Phase 2 test setUp() binds AdapterRegistry directly via `$this->app->singleton(AdapterRegistry::class)`. Do NOT call `(new \Logingrupa\Metapixel\Plugin)->register()` — PluginBase requires container injection and the bare instantiation TypeErrors. Document the pattern in a one-line comment ONLY on the first test file if needed (the other 4 self-document by following the same pattern).

Helper for tests needing custom adapter behavior: shipped doubles cover most cases. For tests needing parent/child subject hierarchy (T1's is_a walk test), inline 2 minimal classes at the top of the test file:

```
class FixtureParent {}
class FixtureChild extends FixtureParent {}
```

These are TEST-LOCAL fixtures (only used by AdapterRegistryTest). They do NOT collide with `Doubles/TestSubject` because they're declared at global scope in a single test file. If autoload collision appears, move them under the test's namespace via `namespace Logingrupa\Metapixel\Tests\Unit\Adapter\Fixtures;` block.

For T1 `AdapterRegistryTest`:

- `test_register_and_resolve_for_returns_adapter_instance` — register `stdClass` → `FakeAdapter::class`; resolveFor(new stdClass) returns FakeAdapter instance; instanceof EventSubjectAdapter.
- `test_resolve_for_walks_class_hierarchy_via_is_a` — register `FixtureParent::class` → FakeAdapter; resolveFor(new FixtureChild) returns FakeAdapter via is_a walk.
- `test_resolve_for_returns_null_when_subject_not_registered` — fresh registry; resolveFor(new stdClass) returns null.
- `test_all_returns_list_of_registered_adapter_class_names` — register two adapters (FakeAdapter for stdClass, FakeStubAdapter for FixtureChild); assert `count($obRegistry->all()) === 2`.

For T2 `AdapterRegistrySingletonBindingTest`:

- `test_singleton_binding_returns_same_instance` — `app(AdapterRegistry::class) === app(AdapterRegistry::class)` after setUp's singleton bind.
- `test_app_instance_swaps_fresh_registry_for_test_isolation` — `$this->app->instance(AdapterRegistry::class, $obFresh)`; assert `app(AdapterRegistry::class) === $obFresh`.

For T3 `AdapterRegistryInvalidAdapterTest`:

- `test_register_throws_when_adapter_class_does_not_implement_event_subject_adapter` — expect `\InvalidArgumentException`; call `$obRegistry->register('Subject', stdClass::class)`.

For T4 `AdapterRegistryBootOrderTest` (P-02 anchor):

- Create two unrelated subject classes (define `FixtureSubjectA` + `FixtureSubjectB` inline at file scope; use FakeAdapter + FakeStubAdapter from `Doubles/`).
- Register order A,B in one registry; B,A in another.
- Assert both registries resolve `new FixtureSubjectA` to FakeAdapter and `new FixtureSubjectB` to FakeStubAdapter.
- Add a docblock on the test method explaining: "P-02 boot-order race prevention — the foreach order in is_a-walk is map-insertion order, but for unrelated subject classes the resolution outcome is invariant."

For T5 `AdapterRegistryFlushTest`:

- `test_app_forget_instance_re_binds_fresh_singleton` — register an adapter, `App::forgetInstance(AdapterRegistry::class)`, then `app(AdapterRegistry::class)` returns a registry with `count($obFresh->all()) === 0`. This proves test-teardown can reset the singleton without leaving stale state.

All tests use Hungarian-notation locals. NO comment pollution (no `// T4`, no `// P-02`, no `// CR-XX` — the docblock explains in prose).

The bootstrap path inherited from Phase 1's `MetapixelTestCase::createApplication()` boots OctoberCMS in SQLite-in-memory; the H-8 setUp pattern (singleton bind via $this->app) bypasses Plugin::register() entirely. This is cleanest + has no plugin-loader dependence.
  </action>
  <verify>
    <automated>test -f plugins/logingrupa/metapixel/tests/Unit/Adapter/AdapterRegistryTest.php &amp;&amp; test -f plugins/logingrupa/metapixel/tests/Unit/Adapter/AdapterRegistrySingletonBindingTest.php &amp;&amp; test -f plugins/logingrupa/metapixel/tests/Unit/Adapter/AdapterRegistryInvalidAdapterTest.php &amp;&amp; test -f plugins/logingrupa/metapixel/tests/Unit/Adapter/AdapterRegistryBootOrderTest.php &amp;&amp; test -f plugins/logingrupa/metapixel/tests/Unit/Adapter/AdapterRegistryFlushTest.php &amp;&amp; for f in plugins/logingrupa/metapixel/tests/Unit/Adapter/*.php; do php -l "$f" | grep -q 'No syntax errors' || exit 1; done &amp;&amp; ! grep -rE '\(new\s+\\?Logingrupa\\\\Metapixel\\\\Plugin\)' plugins/logingrupa/metapixel/tests/Unit/Adapter/ &amp;&amp; cd plugins/logingrupa/metapixel &amp;&amp; ../../../vendor/bin/pest tests/Unit/Adapter --configuration phpunit.xml 2&gt;&amp;1 | tail -5 | grep -Eq '(PASS|OK|Tests:.*passed)'</automated>
  </verify>
  <done>5 test files exist; all php -l clean; H-8 pattern enforced (grep confirms zero `(new \Logingrupa\Metapixel\Plugin)` instantiations); `pest tests/Unit/Adapter` exits 0 with ≥ 5 test methods total passing.</done>
</task>

<task type="auto">
  <name>Task 6: Run composer qa locally and commit</name>
  <files>
    plugins/logingrupa/metapixel/classes/Adapter/EventSubjectAdapter.php
    plugins/logingrupa/metapixel/classes/Adapter/ValueResolver.php
    plugins/logingrupa/metapixel/classes/Adapter/AdapterRegistry.php
    plugins/logingrupa/metapixel/Plugin.php
    plugins/logingrupa/metapixel/phpstan.neon
    plugins/logingrupa/metapixel/composer.json
    plugins/logingrupa/metapixel/tests/Doubles/FakeAdapter.php
    plugins/logingrupa/metapixel/tests/Doubles/FakeValueResolver.php
    plugins/logingrupa/metapixel/tests/Doubles/TestSubject.php
    plugins/logingrupa/metapixel/tests/Doubles/TestSubjectAdapter.php
    plugins/logingrupa/metapixel/tests/Doubles/ZeroIdSubjectAdapter.php
    plugins/logingrupa/metapixel/tests/Doubles/FakeStubAdapter.php
    plugins/logingrupa/metapixel/tests/Unit/Adapter/AdapterRegistryTest.php
    plugins/logingrupa/metapixel/tests/Unit/Adapter/AdapterRegistrySingletonBindingTest.php
    plugins/logingrupa/metapixel/tests/Unit/Adapter/AdapterRegistryInvalidAdapterTest.php
    plugins/logingrupa/metapixel/tests/Unit/Adapter/AdapterRegistryBootOrderTest.php
    plugins/logingrupa/metapixel/tests/Unit/Adapter/AdapterRegistryFlushTest.php
  </files>
  <action>
From `plugins/logingrupa/metapixel/`:

```
composer pint        # auto-format new files first
composer pint-test   # verify pint-clean
composer analyse     # phpstan level 10
composer phpmd       # phpmd Plugin.php,classes
composer deps        # composer-dependency-analyser
composer test        # pest
composer test-cov    # pest --coverage --min=90
composer qa          # full chain
```

If `phpstan analyse` flags level 10 issues on the new classes/, fix them in-place. Common Phase 2 phpstan errors to anticipate:

- `App::make` return type `mixed`: cast via `/** @var EventSubjectAdapter $obAdapter */` on resolveFor's hit branches OR use `is_a(...)` post-check.
- Property type vs PHPDoc generic: `private array $arAdapterMap = [];` + `/** @var array<class-string, class-string<EventSubjectAdapter>> $arAdapterMap */` PHPDoc.
- Interface return-type narrowing in resolveFor: `?EventSubjectAdapter` — make sure `App::make($sAdapterClass)` cast resolves cleanly (PHPDoc above the make call).

Coverage: PluginSanityTest covered Plugin.php at 100% in Phase 1. This plan adds 3 production classes; the 5 unit tests should hit ≥ 90% line coverage on AdapterRegistry. The two interfaces are 100% trivially (no executable code beyond signatures — phpstan + pest report 100% interface coverage). The 6 `tests/Doubles/` files are autoload-dev — coverage scope (set by phpunit.xml `<source><include>`) does NOT cover tests/, so the doubles do not contribute to the coverage denominator.

If `test-cov --min=90` fails because of unhit lines in AdapterRegistry, add a targeted test in `AdapterRegistryTest.php` to cover the missed branch (typically the `is_a` walk fallback or the `null` miss case).

Commit:

```
git add plugins/logingrupa/metapixel/classes/Adapter/ \
        plugins/logingrupa/metapixel/Plugin.php \
        plugins/logingrupa/metapixel/phpstan.neon \
        plugins/logingrupa/metapixel/composer.json \
        plugins/logingrupa/metapixel/tests/Doubles/ \
        plugins/logingrupa/metapixel/tests/Unit/Adapter/

git commit -m "$(cat <<'EOF'
feat(metapixel): land adapter interfaces + AdapterRegistry + shared test doubles (ADAP-01..03)

Add EventSubjectAdapter + ValueResolver interfaces and the AdapterRegistry
final class bound via Plugin::register() singleton. is_a() hierarchy walk;
register() is idempotent + order-agnostic (P-02 anchor). InvalidArgumentException
on adapter class that does not implement EventSubjectAdapter.

Ship 6 shared test doubles under tests/Doubles/ (FakeAdapter, FakeValueResolver,
TestSubject, TestSubjectAdapter, ZeroIdSubjectAdapter, FakeStubAdapter) for
plans 02-04 / 02-06 / 02-07 to import by FQN — eliminates inline-class
redeclaration collisions across test files (D-08..D-10). SpyMetaClient ships
alongside MetaClient in plan 02-05.

Tests bind AdapterRegistry directly via \$this->app->singleton in setUp() —
never via (new Plugin)->register() (PluginBase requires container injection).

phpstan.neon expands to scan classes/ at level 10. phpmd script extended to
scan classes/ alongside Plugin.php. Five unit tests cover register, resolveFor
hierarchy walk, singleton binding test-swap, invalid-adapter rejection, and
boot-order invariance.
EOF
)"
```

Verify `composer qa` exits 0 AFTER the commit too (one last sanity run from a clean working tree).
  </action>
  <verify>
    <automated>cd plugins/logingrupa/metapixel &amp;&amp; composer qa 2&gt;&amp;1 | tail -5 | grep -Eq '(OK|PASS|0 errors|tests passed|No issues found)' &amp;&amp; git log -1 --pretty=format:'%s' | grep -q 'adapter interfaces' &amp;&amp; git diff-tree --no-commit-id --name-only -r HEAD | grep -c '^plugins/logingrupa/metapixel/' | xargs test 16 -le</automated>
  </verify>
  <done>composer qa exits 0; commit on HEAD touches ≥ 16 files under plugins/logingrupa/metapixel/; commit message references ADAP-01..03 + shared doubles.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Third-party Plugin::boot() → AdapterRegistry::register | A third-party plugin author can register any class as an adapter. Our defence: `is_subclass_of` guard rejects non-EventSubjectAdapter classes with InvalidArgumentException — failing fast at registration time, not later at queue-time. |
| Test isolation → singleton state leak | A test that registers adapters into the container singleton without flushing leaks state into the next test. Our defence: T5 documents `App::forgetInstance` as the flush idiom; MetapixelTestCase's tearDown teardown already handles container reset via `unset($this->app)`. |
| Autoload-dev doubles → production | tests/Doubles/* live under autoload-dev only. `composer install --no-dev` never autoloads them — production binaries cannot instantiate FakeAdapter or its peers. |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-02-01-01 | Tampering | A malicious third-party plugin registers a non-adapter class as the "Order adapter" to intercept dispatches | mitigate | `is_subclass_of($sAdapterClass, EventSubjectAdapter::class)` is_subclass_of guard at register time. If a malicious plugin author wants to ship a Lovata-Order adapter they must implement the 7-method interface — by the time they have all 7 methods, they ARE a valid adapter (no different from a benign third party). Threat class accepted; defence sufficient. |
| T-02-01-02 | Spoofing | A subject class `class Order extends FakeOrder` could resolve via the wrong adapter via is_a walk if both ancestors are registered | mitigate | Documented in AdapterRegistry class-level PHPDoc: foreach order = map-insertion order; sibling-class collision is undefined. RESEARCH.md A3 logs this as a known gotcha; Phase 3 ShopaholicAdapter + Theme adapters use unrelated subject classes (Lovata\OrdersShopaholic\Models\Order vs ThemeActionEvent — no shared ancestor). Explicit priority API deferred to v2.1. |
| T-02-01-03 | Repudiation | A failing register() throws but no audit trail | accept | InvalidArgumentException carries the offending class name + interface name in its message. Boot-time failures surface in OctoberCMS log via the framework's exception handler. No additional logging required at this layer. |
| T-02-01-04 | Information Disclosure | A registered adapter class can be enumerated via AdapterRegistry::all() | accept | `all()` is package-internal — no public HTTP/CLI surface exposes it. Tests use it; Phase 3 conditional-registration smoke uses it. Marketplace users do not call all(). |
| T-02-01-05 | Denial of Service | A registry with O(n) is_a walk per resolveFor degrades with many adapters | accept | Realistic adapter count in v2.0 marketplace: 2 (Shopaholic + Theme). At n < 10, foreach + is_a is constant-time. Index optimisation deferred to v2.1 if real-world adapter counts surface. |
| T-02-01-06 | Elevation of Privilege | A test that does not call `$this->app->instance(AdapterRegistry::class, new AdapterRegistry)` could inherit registrations from a previous test | mitigate | T5 documents `App::forgetInstance` as the per-test reset idiom. Future-phase tests use either `instance()` swap or `forgetInstance` — both work. MetapixelTestCase's tearDown's `unset($this->app)` is the catchall. |
| T-02-01-07 | Spoofing | A test-double class registered as a production adapter from a real plugin's boot() | mitigate | tests/Doubles/* live under autoload-dev — production composer install --no-dev cannot load them. A misconfigured staging env that ships dev autoload could register FakeAdapter, but the InvalidArgumentException guard in register() would still reject any non-EventSubjectAdapter — and FakeAdapter implements the interface. Trust boundary: dev autoload in production is its own anti-pattern (separate concern). |
</threat_model>

<verification>
## Goal-Backward Reachability Audit

1. "EventSubjectAdapter + ValueResolver interfaces exist with required method signatures" — Task 1 writes both; verify by grep `interface EventSubjectAdapter` + 7-method count.
2. "AdapterRegistry final class with register/all/resolveFor/resolveByClass exists" — Task 2 writes; verify by grep `final class AdapterRegistry` + 4-method count.
3. "Plugin::register() binds AdapterRegistry singleton" — Task 3 edits Plugin.php; verify by grep `singleton(AdapterRegistry::class)`.
4. "6 shared test doubles under tests/Doubles/ (H-6 + M-9)" — Task 4 creates each by FQN; downstream plans (02-04, 02-06, 02-07) import without redeclaration.
5. "register() throws InvalidArgumentException on non-EventSubjectAdapter class" — Task 2 implements guard; Task 5 T3 test asserts.
6. "register() is idempotent + order-agnostic" — Task 5 T4 test asserts.
7. "resolveFor walks is_a hierarchy + returns null on miss" — Task 5 T1 test asserts.
8. "H-8 setUp pattern enforced (no (new Plugin)->register() in any test)" — Task 5 verify grep confirms.
9. "phpstan + phpmd + composer-dependency-analyser scan classes/ folder" — Task 3 expands phpstan.neon + composer.json phpmd script.
10. "composer qa exits 0 on the new code" — Task 6 verifies.

No must-have is UNREACHABLE.

## Multi-Source Coverage Audit

| Source item | Type | Coverage | Notes |
|-------------|------|----------|-------|
| ROADMAP Phase 2 SC1 (adapter signature + AdapterRegistry::register pattern) | Goal | Tasks 1, 2, 3 | Closes interface + registry contract; SC1 round-trip test ships in plan 02-07 |
| REQ ADAP-01 (EventSubjectAdapter interface, 7 methods) | Requirement | Task 1 | Verified by method count + PHPDoc keyset enumeration |
| REQ ADAP-02 (ValueResolver interface, 5 methods) | Requirement | Task 1 | Verified by method count |
| REQ ADAP-03 (AdapterRegistry singleton + register/resolveFor/resolveByClass) | Requirement | Tasks 2, 3 | Verified by grep + tests T1, T3 |
| CONTEXT D-01 (all fresh, no port) | Decision | All tasks | Fresh AdapterRegistry shape + fresh doubles; no `git show legacy/v1.1.1` cherry-pick |
| CONTEXT D-08 (FakeAdapter fluent setters) | Decision | Task 4 | Wave 1 ships per H-6 + M-9 unified-fixture mandate |
| CONTEXT D-09 (FakeAdapter outside production PSR-4) | Decision | Task 4 | tests/Doubles/ autoload-dev only |
| CONTEXT D-10 (FakeValueResolver constructor-injected) | Decision | Task 4 | Constructor with 5 defaults |
| CONTEXT D-14 (AdapterRegistry singleton + lazy App::make + is_a walk + null-on-miss) | Decision | Tasks 2, 3 | Code shape matches RESEARCH §4.3 verbatim |
| RESEARCH §4.1 EventSubjectAdapter shape | Reference | Task 1 | Methods + PHPDoc match research verbatim |
| RESEARCH §4.2 ValueResolver shape | Reference | Task 1 | Methods + PHPDoc match research verbatim |
| RESEARCH §4.3 AdapterRegistry shape | Reference | Task 2 | Class + methods + is_subclass_of guard match research verbatim |
| RESEARCH §4.15 Plugin::register binding | Reference | Task 3 | `$this->app->singleton(AdapterRegistry::class)` matches research verbatim |
| RESEARCH §6 T1–T5 test list (AdapterRegistry unit tier) | Reference | Task 5 | All 5 test files land |
| RESEARCH §6 FakeAdapter + FakeValueResolver shape | Reference | Task 4 | Verbatim from §6 |
| PITFALLS P-02 (boot-order race / registry unbound) | Pitfall | Tasks 2, 3, 5 (T4 anchor) | idempotent register + bind-in-register + T4 order-invariance test |
| CONTEXT "no comment pollution" | Constraint | All tasks | grep guards in verify steps |
| CONTEXT "Hungarian notation, no assert(), no declare(strict_types)" | Constraint | All tasks | grep guards in verify steps |
| Plan-checker H-6 + M-9 (single fixture pattern) | Revision | Task 4 | 6 shared doubles ship Wave 1; downstream plans import by FQN |
| Plan-checker H-8 (Plugin instantiation in tests) | Revision | Task 5 | setUp uses `$this->app->singleton(AdapterRegistry::class)` direct bind |
| Plan-checker L-4 (single Log facade pattern) | Revision | Task 2 | AdapterRegistry uses `Illuminate\Support\Facades\App` FQN — same convention applies to Log in subsequent plans |
| Plan-checker L-8 (classic Pest style) | Revision | Task 5 | All 5 test files use `final class FooTest extends MetapixelTestCase` |

No gaps. No `[ASSUMED]` items from RESEARCH §9 affect this plan (A1 phpstan FQN is plan 02-02's concern; A3 is_a collision documented in PHPDoc per research).

## Acceptance gate

`composer qa` (pint-test → phpstan level 10 → phpmd → pest --coverage --min=90) exits 0 from `plugins/logingrupa/metapixel/` after Task 6's commit.
</verification>

<success_criteria>
Plan 02-01 ships when ALL of the following hold:

1. `classes/Adapter/EventSubjectAdapter.php` exists; interface with 7 public methods; PHPDocs document the opaque-alias + cross-context-determinism constraints in prose (no `P-XX` markers in source).
2. `classes/Adapter/ValueResolver.php` exists; interface with 5 public methods.
3. `classes/Adapter/AdapterRegistry.php` exists; `final class` with 4 public methods; uses `is_subclass_of` guard + `InvalidArgumentException`; imports `Illuminate\Support\Facades\App` (L-4 lock).
4. `Plugin.php` imports `AdapterRegistry` + binds `$this->app->singleton(AdapterRegistry::class)` in `register()`.
5. `phpstan.neon` adds `classes` to the `paths:` list.
6. `composer.json` `phpmd` script scans `Plugin.php,classes`.
7. Six shared test doubles ship under `tests/Doubles/` (FakeAdapter + FakeValueResolver + TestSubject + TestSubjectAdapter + ZeroIdSubjectAdapter + FakeStubAdapter). SpyMetaClient deferred to plan 02-05 Task 6 (lands alongside MetaClient).
8. Five unit test files exist under `tests/Unit/Adapter/`; all use the H-8 setUp pattern (`$this->app->singleton(AdapterRegistry::class)` direct bind — never `(new Plugin)->register()`); together they have ≥ 5 test methods that exercise register, resolveFor hierarchy walk, singleton binding, invalid-adapter rejection, and boot-order invariance.
9. `composer qa` exits 0 from `plugins/logingrupa/metapixel/`: pint-test, phpstan level 10, phpmd, deps, test-cov all green.
10. Single commit on HEAD with message referencing ADAP-01..03 + shared doubles.
11. No comment pollution in any new source file (no `// CR-XX`, `// Phase N`, `// Plan N`, `// P-XX`, `// H-XX`, `// M-XX` markers — grep guards in verify steps confirm).

</success_criteria>

<output>
After completion, create `plugins/logingrupa/metapixel/.planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-01-SUMMARY.md` documenting:

- The single commit SHA produced by this plan.
- `composer qa` output tail (last 30 lines) — proves the full chain green.
- Five test method names + pass/fail status (`pest tests/Unit/Adapter` output).
- Coverage report numbers: classes/Adapter/AdapterRegistry.php expected ≥ 95% line coverage.
- Note on phpstan exclusion: `classes/Adapter/EventSubjectAdapter.php` + `classes/Adapter/ValueResolver.php` are interfaces (no executable code); phpstan reports 100% trivially.
- Doubles inventory: 6 files under tests/Doubles/ (FakeAdapter, FakeValueResolver, TestSubject, TestSubjectAdapter, ZeroIdSubjectAdapter, FakeStubAdapter); SpyMetaClient deferred to plan 02-05.
- Phase 2 plan-state update: Plan 02-01 closed; plans 02-02 (parallel) and 02-03a (sequential, storage layer) now unblocked. Plans 02-03b, 02-04, 02-05, 02-06, 02-07 still blocked transitively on 02-03a.
</output>

## Revision History
- 2026-05-17 R1: Address plan-checker findings H-6 (Task 4 ships 6 shared doubles under tests/Doubles/ for downstream plans to import by FQN — eliminates inline-class redeclaration collisions; SpyMetaClient deferred to plan 02-05 Task 6), H-8 (Task 5 + interfaces block lock the `$this->app->singleton(AdapterRegistry::class)` setUp pattern across Phase 2 tests — eliminates `(new Plugin)->register()` TypeError), M-9 (single-pattern shared-fixture file resolved via H-6), L-4 (AdapterRegistry imports Illuminate\Support\Facades\App FQN; convention documented in interfaces block for downstream plans), L-8 (Task 5 confirms classic `final class FooTest extends MetapixelTestCase` style).
