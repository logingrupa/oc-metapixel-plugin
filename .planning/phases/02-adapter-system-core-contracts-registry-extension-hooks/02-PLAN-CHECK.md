# Phase 2 Plan Check

**Verdict:** REVISE

Goal-backward verification of the 7-plan Phase 2 plan set. The plans deliver substantial coverage of ADAP-01..11 and the 5 named pitfalls. Most importantly, the seven plans **DO** thread the FakeAdapter → PayloadBuilder → SendCapiEvent → MetaClient → EventLogWriter pipeline end-to-end and assert it in `BackboneIntegrationTest`. The dependency graph is acyclic and Wave assignments are coherent.

But three categories of blocker remain that will either (a) cause execution to fail on the first `composer qa` run inside `composer-dependency-analyser`, (b) silently misfire the `tools/Adapter` PHPStan scope (P-01 enforcement degrades to no-op), or (c) violate the locked build philosophy ("no v1.x port", "fresh code") by silently regressing the FailedEvent dead-letter row to be incomplete (subject_type/subject_id hardcoded to `null` defeats Phase 4 admin UI). None of these block goal achievability in principle, but each will either bounce in CI or produce a misleading green that hides P-01 / P-05 regression risk before Phase 3 lands.

---

## HIGH issues

### H-1 (BLOCKER) — Plan 02-02 disallowedMethodCalls `allowIn` includes `classes/Helper/*` AND `classes/Meta/*`, weakening P-01 enforcement to a no-op for those dirs

**Plan:** 02-02, Task 2
**Source:** `02-02-INDEX.md:256-277`

The plan writes:

```yaml
disallowedMethodCalls:
    - method: '<VERIFIED_SITEMANAGER_FQN>::*'
      allowIn:
          - Plugin.php
          - classes/Helper/*
          - classes/Meta/*
          - middleware/*
          - controllers/*
          - models/*
```

CONTEXT.md D-17 + REQUIREMENTS.md ADAP-06 + RESEARCH.md §5.1 ALL say the ban is scoped to `classes/Queue/`, `classes/Event/`, `classes/Adapter/`. The plan's `allowIn` fail-closed flip ALSO whitelists `classes/Helper/` — which is where `SiteResolver` and `EventLogWriter` live. That means a future `classes/Helper/SiteResolver.php` author can call `SiteManager::getCurrent()` and PHPStan stays green. The whole point of D-17 ("SiteResolver is the only authoritative source of site_id") is enforced by SiteResolver delegating to the adapter, and PHPStan was the static safety net.

Worse: `classes/Meta/` (where MetaClient lives) is also whitelisted. MetaClient calling `request()` for "convenience" would pass static analysis under this config.

Also note plan 02-04 Task 3 adds a defensive `test_site_resolver_makes_no_request_or_site_manager_calls` regex check that catches the SiteResolver-specific case at test time, but `EventLogWriter` is unguarded.

**Fix:** Replace `allowIn` with `disallowIn` (per RESEARCH.md §5.1 verbatim). Use deny-list scoped to the three dirs, accept that `classes/Helper/` is denied by default — which is what RESEARCH actually prescribes:

```yaml
disallowedMethodCalls:
    - method: 'System\Classes\SiteManager::*'
      disallowIn:
          - classes/Queue/*
          - classes/Event/*
          - classes/Adapter/*
```

If the planner wants the fail-closed `allowIn` semantics (which is genuinely safer in spirit), the allowlist must be narrowed: `Plugin.php`, `middleware/*`, `controllers/*`, `components/*` only. `classes/Helper/*` and `classes/Meta/*` MUST come out of the allowlist.

### H-2 (BLOCKER) — Plan 02-03 stamps `subject_type = null, subject_id = null` into FailedEvent rows, defeating Phase 4 admin UI re-resolution path

**Plan:** 02-06, Task 1 + carried via 02-03 schema
**Source:** `02-06-PLAN.md:283-305` (`writeFailedEvent`)

```php
private function writeFailedEvent(Throwable $obException, ?int $iHttpStatus): void
{
    FailedEvent::create([
        ...
        'subject_type' => null,
        'subject_id' => null,
        ...
    ]);
}
```

But RESEARCH.md §4.14 explicitly says the FailedEvent table adds `adapter_type`, `subject_type`, `subject_id` columns "per ADAP-10 — admin UI Phase 4 filters on `adapter_type`; replay path needs `subject_type`/`subject_id` to re-resolve via AdapterRegistry."

If those two columns are hardcoded `null`, Phase 4 FailedEvents::onReplay() cannot rehydrate the subject. The dead-letter row carries the full payload (good) but loses the (subject_type, subject_id) cross-reference that lets Phase 4 admin UI link the failed event back to the source order/cart/whatever.

The fix is trivial: in `writeFailedEvent`, look up adapter alias when the adapter was resolvable. The order of operations in `handle()` is:

```
$obAdapter = $obRegistry->resolveByClass($this->sAdapterClass);  // try/catch BindingResolutionException
```

When BindingResolutionException fires, the adapter doesn't exist — `subject_type` legitimately is null then. But in every other writeFailedEvent call path (Permanent / MissingPixel / MissingCapiToken catches, AND in `failed()` retry-exhaustion), `$obAdapter` IS in scope and has a working `getSubjectType($obSubject)` + `getSubjectId($obSubject)`.

**Fix:** Pass `$obAdapter` (or just the resolved alias + id) into `writeFailedEvent`. Have writeFailedEvent accept `?EventSubjectAdapter $obAdapter = null` and populate subject_type/subject_id when non-null. The BindingResolutionException early-return path correctly passes null. Every other call site has the adapter.

### H-3 (BLOCKER) — Plan 02-07 Task 2 reverses Task 2's own claim about classes/Testing extending MetapixelTestCase, then says "stick with MetapixelTestCase + exclude in dependency-analyser" — final state is ambiguous + violates the locked Lovata-import-boundary intent of TOOL-11

**Plan:** 02-07, Task 2
**Source:** `02-07-PLAN.md:441-503`

The plan oscillates: first proposes extending Illuminate TestCase (option A), then says "actually, simpler still: keep extends MetapixelTestCase for FIRST-PARTY tests AND ship a parallel base for third parties", then settles on "For Phase 2 simplicity: keep extends MetapixelTestCase. Composer-dependency-analyser CAN exempt this specific cross-namespace import via addPathToExclude".

The FINAL position has two architectural problems:

1. **It defeats the marketplace promise of D-11:** "Third parties extend this base for their own adapter tests" (CONTEXT.md D-13). If `EventSubjectAdapterContractTestCase extends Logingrupa\Metapixel\Tests\MetapixelTestCase`, then a third party requiring `logingrupa/oc-metapixel-plugin` AS A LIBRARY (not as a checked-out test harness) cannot extend the base because `MetapixelTestCase` lives under `Logingrupa\Metapixel\Tests\` (autoload-dev). The third party's `composer require logingrupa/oc-metapixel-plugin --dev` will NOT pull MetapixelTestCase into autoload — the dev tree of the plugin's own dependencies is not their dev tree.

2. **TOOL-11 / composer-dependency-analyser was specifically chosen to enforce import boundaries.** Adding a `addPathToExclude('/classes/Testing')` to suppress its only finding in this layer means we're whitelisting the exact thing the tool exists to flag. The Phase 1 success criterion SC4 says the analyser "would flag a hidden `use Lovata\OrdersShopaholic\Models\Order` inserted anywhere outside `Classes\Adapter\Shopaholic\` namespace" — same machinery now suppresses an actual layer violation in classes/Testing.

**Fix:** Pick the original option A. `EventSubjectAdapterContractTestCase extends Illuminate\Foundation\Testing\TestCase` (or even better, `Orchestra\Testbench\TestCase` if you want a more portable test harness — many marketplace OctoberCMS plugins use it). Concrete subclasses (FakeAdapterContractTest in this plugin's tests; AcmeCartAdapterContractTest in a third party's tests) supply `createApplication()` and any boot trait they need. FakeAdapterContractTest can also `use \Logingrupa\Metapixel\Tests\BootstrapsOctoberFromMetapixelTestCase;` (a trait extracted from MetapixelTestCase) — but the contract base does NOT depend on tests/.

If that's too much for Phase 2, the next-best fix is a SMALLER concession: don't ship the contract base in production at all this phase. Move it to `tests/Contract/Base/EventSubjectAdapterContractTestCase.php` (autoload-dev), bind third-party docs/CUSTOM-ADAPTERS.md to "copy this 100-LOC file into your own tests/" pattern. That keeps composer-dependency-analyser strict.

### H-4 (BLOCKER) — Plan 02-05 declares Guzzle dep is "production runtime" but `composer.json` autoload section needs no edit. The plan's verify step then asserts `ls vendor/guzzlehttp/guzzle/src/Client.php` from PLUGIN dir using `2>/dev/null || ls ../../../vendor/...` — broken shell logic

**Plan:** 02-05, Task 1
**Source:** `02-05-PLAN.md:366-368`

```bash
ls vendor/guzzlehttp/guzzle/src/Client.php 2>/dev/null || ls ../../../vendor/guzzlehttp/guzzle/src/Client.php 2>/dev/null
```

The `||` short-circuits only on non-zero exit. If neither path exists, the SECOND `ls` returns 1 → the whole expression returns 1 → verify fails. But the issue is more subtle: the plan didn't run `composer install` after adding the dep — it ran `composer update guzzlehttp/guzzle --with-dependencies --no-interaction` from the plugin dir, where there IS no vendor/ (project root vendor is shared). The actual vendor path under Forge / your dev env is `/home/forge/nailscosmetics.lv/vendor/guzzlehttp/...`, not under the plugin dir. The fallback `../../../vendor/...` would resolve to `/home/forge/nailscosmetics.lv/plugins/logingrupa/vendor/` which is wrong (off by one).

Worse: running `composer update` inside the plugin dir against the plugin's local `composer.json` will fail entirely unless the plugin has its OWN composer.lock — which October-plugin-style packages typically don't. Lovata's plugins don't carry composer.lock; they're installed by the root project's composer.

**Fix:** Reframe Task 1. Add Guzzle to the plugin's `composer.json` `require:` (documentation + marketplace install constraint), but DO NOT run `composer update` from the plugin dir — instead document "after merge, the operator runs `composer update logingrupa/oc-metapixel-plugin` from project root to refresh lockfile." For the verify step, simply check that `composer validate` passes from the plugin dir, and that Guzzle is available via PHP autoload check (run `php -r 'require "../../../vendor/autoload.php"; class_exists("GuzzleHttp\\Client") || exit(1);'`).

### H-5 (BLOCKER) — Plan 02-03 Task 5 renames migration filenames to PSR-4 PascalCase "AlternatIvely (preferred)" via composer.json classmap, but composer.json autoload section is `"Logingrupa\\Metapixel\\": ""` which already PSR-4-maps `updates/` as `Logingrupa\Metapixel\Updates\*` — adding a classmap is double-loading + the snake_case filename pattern WILL break PSR-4

**Plan:** 02-03, Task 5
**Source:** `pre-split 02-PLAN-3 (R1) — addressed in R2 by splitting into 02-03a-PLAN.md (storage) + 02-03b-PLAN.md (settings/guard/exceptions)`

Composer's PSR-4 spec is strict: class `Logingrupa\Metapixel\Updates\CreateMetapixelEventLogTable` MUST live at `updates/CreateMetapixelEventLogTable.php` (PascalCase, matching class name). The plan keeps the file at `updates/create_metapixel_event_log_table.php` (snake_case) and tries to fix this by adding `"classmap": ["updates/"]` to autoload-dev.

Problem: composer's classmap is generated at `composer dump-autoload` time and is a fallback after PSR-4. With both:
- PSR-4 maps `Logingrupa\Metapixel\Updates\CreateMetapixelEventLogTable` → `updates/CreateMetapixelEventLogTable.php` (file doesn't exist)
- Classmap maps the same FQN to `updates/create_metapixel_event_log_table.php` (file exists)

Composer's docs say classmap wins when PSR-4 misses, so this should work — BUT it puts classmap (a dev-only autoload mechanism) in `autoload-dev` while the migrations are also needed at production install time when OctoberCMS runs `october:up`. October's plugin manager `require_once`s migration files by path read from `version.yaml`, not by class autoload. So at production-install time the classmap is irrelevant — the file is `require`d directly. Phase 2 tests are the ONLY consumer that needs the classmap.

But the deeper issue: the plan also puts the classmap in `autoload-dev`, then the test file does `use Logingrupa\Metapixel\Updates\CreateMetapixelEventLogTable;` — this works in test env but ALSO works at production-install time because the classmap is in `autoload-dev` (loaded only when `--dev`). Production: October's `require` path; tests: classmap. Both happy.

The actual blocker is different: `autoload-dev.classmap` is non-standard for an October plugin. None of the Lovata plugins do this — they all use the same pattern (snake_case files, October's plugin manager requires by path from version.yaml, tests don't import migrations as classes). What Lovata does instead in their test suite is `(new \Lovata\OrdersShopaholic\Updates\CreateOrdersTable)->up()` style — but the file is at `updates/v1_0_1_create_orders_table.php` and they have `"autoload-dev": { "classmap": ["updates/"] }`. So the classmap pattern IS established — but the plan should verify this works under composer 2.6+ (latest spec sometimes warns on classmap-only).

Lower blocker: keep, but add a Task 5.5 spike "verify `composer dump-autoload --no-interaction --classmap-authoritative` resolves CreateMetapixelEventLogTable::class with snake_case file". If it fails, rename files PascalCase.

**Fix:** Either (a) rename migration files to PascalCase (matches PSR-4 cleanly, no classmap), or (b) keep classmap + ADD spike step in Task 5 to verify resolution. Option (a) is the cleaner Lovata-deviation per "no over-engineering". Note: Phase 1 SUMMARY.md may have left existing migration file conventions — verify before flipping.

### H-6 (BLOCKER) — Plan 02-06 Task 2/3 inline-declares class-fixtures (TestSubject, TestSubjectAdapter, FakeStubAdapter, SpyMetaClient) at file global scope ACROSS MULTIPLE TEST FILES — composer-dependency-analyser + PHP autoload will collide on cross-file class names

**Plan:** 02-06, Tasks 2 + 3
**Source:** `02-06-PLAN.md:552-583` (T11 declares `FakeStubAdapter`, `SpyMetaClient`); `02-PLAN-6:586-665` (T12 reuses); plan 02-04 Task 4 ALSO declares `TestSubject`, `TestSubjectAdapter`, `ZeroIdSubjectAdapter` (lines 423-451); plan 02-06 Task 3 then reuses those same `TestSubject` / `TestSubjectAdapter` names in `tests/Feature/Queue/*` test files.

The plan acknowledges this risk inline ("If autoload starts complaining..." in 02-04 Task 4 lines 537-555) and suggests namespacing them. But then 02-06 Task 2's T11 example code shows `final class FakeStubAdapter implements EventSubjectAdapter` at GLOBAL scope (no namespace shown above the class declaration). The plan IS ambiguous about whether the test files are namespaced or not.

Beyond namespace collision: redeclaring `class TestSubject` in `tests/Feature/Adapter/EventLogWriterRaceFenceTest.php` AND `tests/Feature/Queue/SendCapiEventHaltTest.php` AND `tests/Feature/Queue/SendCapiEventHappyPathTest.php` etc. — Pest/PHPUnit loads each test file once per run; if both define `class TestSubject` at top level, the second file load raises a Fatal Error.

The fix the plan suggests ("namespace each test file") works for collision, but it makes the test files less readable AND requires every test to re-namespace the helpers.

**Fix:** Extract `TestSubject`, `TestSubjectAdapter`, `ZeroIdSubjectAdapter`, `FakeStubAdapter`, `SpyMetaClient` into a single shared fixture file `tests/Doubles/Fixtures/QueueTestFixtures.php` (or split: `tests/Doubles/TestSubject.php` + `tests/Doubles/SpyMetaClient.php`). Each test file imports the FQNs. This is a Plan 02-04 + 02-06 shared concern — Plan 02-04 ships first, so this fixture file lands in 02-04 and 02-06 reuses.

### H-7 (BLOCKER) — Plan 02-07's BackboneIntegrationTest calls `MetaClient::sendForPixel('PIXEL-1', 'TOKEN-1', $arPayload)` but Plan 02-06's `SendCapiEvent::handle` already wraps this. The integration test bypasses SendCapiEvent's race-fence AND its event_id snapshot — proves a different code path than Phase 2 ships

**Plan:** 02-07, Task 4
**Source:** `02-07-PLAN.md:706-734` (BackboneIntegrationTest)

Reading carefully: the test DOES call `$obJob->handle(...)`. False alarm on the bypass concern — handle() does invoke EventLogWriter then sendForPixel. The end-to-end IS exercised.

But the second test `test_dedup_second_dispatch_for_same_subject_short_circuits_no_http_call` claims to verify dedup, and the assertion is `$this->assertCount(1, $obMock, 'MetaClient was called only once')`. `count($obMock)` returns the number of QUEUED responses remaining, not the number consumed. A MockHandler with 2 queued and 1 consumed has `count() === 1`. The assertion is correct by coincidence — but the assertion message is misleading. A reviewer reading the test will misunderstand. The actual call count should be tracked via `Middleware::history` (see plan 02-05 Task 5 T10 for the pattern).

Downgrade to WARNING: the test asserts the right thing but for the wrong reason. The MockHandler ALSO consumes responses on bytes-read so might still drain queue even if SendCapiEvent's race-fence short-circuited. Risk: race-fence regresses, MockHandler still drains, test stays green.

**Fix:** Use `Middleware::history($arHistory)` and assert `count($arHistory) === 1` after both dispatches. Or use SpyMetaClient pattern from plan 02-06 (`iCallCount` property) — same shared-fixture file from H-6.

(Reclassified to WARNING since the assertion is semantically correct, just fragile.)

### H-8 (BLOCKER) — Plan 02-04 Task 4 + Plan 02-06 + Plan 02-07 — Multiple plans call `app(AdapterRegistry::class)` after `(new Plugin)->register()` but tests run before October's plugin lifecycle binds the singleton. Risk: tests pass because `app()` lazy-resolves the class via PHP autoload (not the bound singleton), but the resulting registry is NOT the same instance MetapixelTestCase's tearDown clears

**Plan:** 02-04 Task 4 (lines 458-461), 02-06 Task 3 (lines 822-827), 02-07 Task 2+4

```php
(new \Logingrupa\Metapixel\Plugin)->register();
app(AdapterRegistry::class)->register(TestSubject::class, TestSubjectAdapter::class);
```

October's `PluginBase::register()` typically uses `$this->app->singleton(...)` (`$this->app` from the trait). Calling `new Plugin` instantiates a Plugin that may not have `$this->app` set (PluginBase constructor takes the container). The plan's Plugin.php (02-01 Task 3) writes:

```php
public function register(): void
{
    $this->app->singleton(AdapterRegistry::class);
}
```

`$this->app` is inherited from PluginBase. If `(new Plugin)` is constructed without the container passed (PluginBase signature: `__construct(Application $app)`), `$this->app` is null and `register()` throws a TypeError.

This pattern is repeated across plans 02-04, 02-06, 02-07. None of them check whether `new \Logingrupa\Metapixel\Plugin` actually works without container injection.

**Fix:** Two options:
1. Test setUp does `$this->app->singleton(AdapterRegistry::class)` directly (bypasses Plugin::register but achieves the same bind).
2. Use `(new \Logingrupa\Metapixel\Plugin($this->app))->register()` — pass the container.

Either fixes the latent TypeError. Plan 02-01's tests T1-T5 use the same pattern, so this needs the fix landed in Plan 02-01 first and propagated.

### H-9 (BLOCKER) — Plan 02-05 PayloadBuilder OQ-3 enforcement uses `! grep -E 'if\s*\(\s*\$sEventName\s*===` to prevent event-name switch — but RESEARCH says no `switch($sEventName)` AT ALL. The grep guards against `===` but NOT against `!==`, `in_array($sEventName, [...])`, `match ($sEventName) { ... }`, or `match(true)` patterns

**Plan:** 02-05, Task 3 verify
**Source:** `02-05-PLAN.md:427`

```bash
! grep -E 'switch\s*\(\s*\$sEventName\s*\)' plugins/.../PayloadBuilder.php
! grep -E 'if\s*\(\s*\$sEventName\s*===' plugins/.../PayloadBuilder.php
```

A planner-author or future maintainer could write:

```php
$arEnvelope = match ($sEventName) {
    'Purchase' => $this->purchaseExtras($obSubject),
    'Lead' => $this->leadExtras($obSubject),
    default => [],
};
```

…and it passes both grep guards. OQ-3 explicitly rules out event-name-specific dispatch INSIDE PayloadBuilder. The grep is brittle.

**Fix:** Strengthen the grep guard — single combined check: `! grep -E '\$sEventName\s*(===|!==|==)|switch\s*\(\s*\$sEventName|match\s*\(\s*\$sEventName' file.php`. Or, better, write a phpstan custom rule for "no string comparison on $sEventName in PayloadBuilder" (overkill for Phase 2; do the better grep).

Lower priority: keep as warning. PayloadBuilder is small (~50 LOC), a code review catches this. But explicit grep is cheap insurance.

### H-10 (BLOCKER → BLOCKER) — Plan 02-03 Task 5 T27 invokes `(new CreateMetapixelEventLogTable)->up()` from a test that lives in `tests/Feature/Migrations/`. MetapixelTestCase already calls `tearDown` which drops `system_settings` and other tables — but does NOT drop `logingrupa_metapixel_event_log`. After T27 runs `up()`, table persists. Then T17 (plan 02-04 Task 4) ALSO calls `(new CreateMetapixelEventLogTable)->up()` in its setUp → SQLite throws "table already exists" because the migration's `if (Schema::hasTable(self::TABLE)) return;` guard does prevent re-create, but the `down()` in the previous tearDown might not have run if pest aborts mid-test

Carefully re-reading plan 02-03 T27: `(new CreateMetapixelEventLogTable)->down();` is called INSIDE the test body at the end. Plan 02-04 T17 setUp calls `up()` and tearDown calls `down()`. So if tests pass, no overlap.

But pest 4 isolates tests via a fresh app instance per test method (depending on config). In-memory SQLite is shared across tests of the same instance — and `up()`'s `if (Schema::hasTable...)` guard handles idempotency. So this is actually safe.

Downgrade to INFO: cleanup pattern is correct.

---

## MEDIUM issues

### M-1 — Plan 02-02 task 1 spike's exit criterion is "`/tmp/site-fqn-spike.txt` contains output with at least one match for SiteManager or a Cms/Site namespace" — passes even when grep returns NOTHING + comment is hand-edited later

**Plan:** 02-02 Task 1
The spike's verify command is `test -f /tmp/site-fqn-spike.txt && grep -qE 'class\s+SiteManager|namespace.*Cms|namespace.*Site' /tmp/site-fqn-spike.txt`. If the grep commands collectively produce 0 matches, the file is empty, the second grep fails, AND the spike "passes done state" by claiming "Spike conclusion documented for Task 2". The plan doesn't enforce that Task 2 ACTUALLY reads `/tmp/site-fqn-spike.txt` and uses its FQNs.

**Fix:** Task 2 should embed a literal `cat /tmp/site-fqn-spike.txt | grep SiteManager` output snippet in its commit message OR fail if the spike file content doesn't include `System\Classes\SiteManager` (the expected FQN). Better: Task 2's edit script reads `/tmp/site-fqn-spike.txt` and substitutes into the .neon template.

### M-2 — Plan 02-03 has 6 tasks AND 25+ files modified — exceeds gsd plan-checker scope guidance (2-3 tasks, 5-8 files)

Source: `02-PLAN-3 files_modified` lists 25 paths. Tasks count is 6. The plan-checker scope rubric flags 5+ tasks as blocker, 10+ files as warning. This plan is at 6 tasks / 25 files. Quality risk: execute-phase context degrades by file count; review fatigue is high.

**Fix:** Split into 02-03a (migrations + version.yaml + 2 models + tests T25-T28 — DB layer) and 02-03b (Settings + fields.yaml + PluginGuard + 5 exceptions + lang files + Plugin::registerSettings — config + lifecycle layer). The dependency graph stays the same (both unblock Wave 3 plans 02-04 + 02-05 once both ship). Wave 2 becomes 02-03a sequential then 02-03b parallel-with-nothing.

### M-3 — Plan 02-04 declares `must_haves.truths` includes "Race-fence Feature test asserts two CONCURRENT insertOrIgnore calls" — but tests are sequential (SQLite-in-memory has no actual concurrency)

The truth says "concurrent" but Task 4's T17 test is two sequential calls. Misleading wording — and an operator reading must_haves later may believe concurrency is exercised. SQLite UNIQUE blocks the second insert regardless, but the test does NOT exercise concurrent contention.

**Fix:** Reword must_have truth: "two SEQUENTIAL insertOrIgnore calls with identical key → first returns true, second returns false (UNIQUE collision). Concurrent contention is not exercised in test env." Acceptance criterion is the same; documentation is honest.

### M-4 — Plan 02-05's UserDataHasher memo key is `$obAdapter->getSubjectType($obSubject).':'.$obAdapter->getSubjectId($obSubject)` — but for a fixed adapter+subject, the user_data is also fixed at construction; the memo is only useful for SUB-REQUEST repeated calls. Plan 02-06 SendCapiEvent calls hasher exactly ONCE per dispatch via PayloadBuilder. The memo is dead-weight.

CONTEXT.md D-22: "adapter provides raw fields; Hasher does only sha256 + per-request CCache". The memo IS spec'd. But CCache was deferred (A2). The static array memo serves zero purpose in Phase 2 — there's no caller that hashes the same subject twice in one request. Phase 3 ThemeEventCollector flushes multiple events per request — but those are DIFFERENT subjects (each ThemeActionEvent has its own synthetic_id).

This is over-engineering against the "no over-engineering" lock. Memo + reset() + memo-clears-test (T9) are all premature.

**Fix:** Drop the memo. UserDataHasher is a stateless function. Each `forSubject` call computes sha256s. The 4-branch memo logic in tests becomes a 0-branch. Saves ~15 LOC + 1 test method. If Phase 3 ThemeEventCollector reveals a real cross-event repeat, add the memo then. CLAUDE.md "Build only for current need."

### M-5 — Plan 02-06 SendCapiEvent uses `public readonly string $sEventName` + `public readonly object $obSubject` + `public readonly string $sAdapterClass` — but constructor-promoted readonly arguments + `SerializesModels` trait + Laravel queue may not round-trip cleanly for `object $obSubject` when subject is a plain `stdClass`

Laravel's `SerializesModels` handles Eloquent specifically. For plain objects, it relies on PHP `serialize()`. stdClass serializes fine, but the queue worker re-instantiates by calling `unserialize($sBlob)` on the job's serialized form. Constructor-promoted readonly props are fine on PHP 8.3+ for serialization. This should work.

But Phase 2 tests run jobs SYNCHRONOUSLY (Queue::fake or direct ->handle()) — they don't exercise serialize/unserialize. Production WILL serialize. If `$obSubject` is a Lovata Eloquent Order, `SerializesModels` does the right thing. If a third party's adapter dispatches `SendCapiEvent` with a plain DTO, it should still serialize but Phase 2 has no test asserting this.

**Fix:** Add a smoke test in Plan 02-07 BackboneIntegrationTest: `serialize($obJob)` and `unserialize(serialize($obJob))` round-trip; assert `handle()` works on the unserialized form. ~5 LOC, covers a real production failure mode.

### M-6 — Plan 02-07 Task 3 supplies `makeSubject(): object { return new \stdClass; }` for FakeAdapterContractTest. Invariant 09 (registry round-trip) calls `$obRegistry->register(get_class($obSubject), $sAdapterClass)` — registers `stdClass` → FakeAdapter. Future Phase 3 tests will also try to register stdClass → ShopaholicOrderAdapter. Registry state may collide across test runs

The contract base's invariant 09 mutates the AdapterRegistry singleton. Without explicit forgetInstance in tearDown, the registration persists. Plan 02-07 does NOT show a tearDown override on EventSubjectAdapterContractTestCase.

**Fix:** Add to the abstract base:

```php
protected function tearDown(): void
{
    app()->forgetInstance(AdapterRegistry::class);
    parent::tearDown();
}
```

### M-7 — Plan 02-07's "ADAP-11 closes" claim sidesteps "regreen 177 v1.x tests" by reframing via OQ-1 — but ROADMAP SC5 STILL says "All 177 v1.x tests regreen". The reframing is in CONTEXT/RESEARCH; not in REQUIREMENTS.md ADAP-11 itself

REQUIREMENTS.md ADAP-11 verbatim: "All 177 v1.x tests adapt via `FakeAdapter` test double. `OrderStatusWatcherEventLogTest`, `PurchasePixelEventLogGateTest`, `SendCapiEventEventLogTest`, `MultiSiteEventLogTest` regreen."

ROADMAP.md Phase 2 SC5: "All 177 v1.x tests regreen via a `FakeAdapter` test double standing in for ShopaholicOrderAdapter. `OrderStatusWatcherEventLogTest`, `PurchasePixelEventLogGateTest`, `SendCapiEventEventLogTest`, `MultiSiteEventLogTest` pass without touching real Lovata Order code."

OQ-1 (CONTEXT.md) does not REPLACE these — it's a researcher's proposed resolution. The plan-set adopts the proposal as if locked. But the named tests (OrderStatusWatcherEventLogTest, PurchasePixelEventLogGateTest, SendCapiEventEventLogTest, MultiSiteEventLogTest) are SPECIFIC ASSERTIONS that the named tests pass. None of the Phase 2 plans create these 4 test files OR equivalents. Phase 2 ships BackboneIntegrationTest + ContractTestCaseSmokeTest + Hook tests — different file names, different coverage shape.

`OrderStatusWatcher` is Phase 3 (SHOP-03). Without it, `OrderStatusWatcherEventLogTest` literally can't exist. The ROADMAP success criterion is misaligned with the phase decomposition. The plan-checker can flag this, but the FIX requires updating ROADMAP.md SC5 wording (which CONTEXT.md OQ-1 was implicitly doing).

**Fix:** Update ROADMAP.md SC5 to match OQ-1 reframe: "Backbone test suite (~60-110 Pest 4 tests across `tests/Unit/`, `tests/Feature/`, `tests/Contract/`) regreens through a `FakeAdapter` test double. The four named v1.x tests (`OrderStatusWatcherEventLogTest` etc.) move to Phase 3 alongside `ShopaholicOrderAdapter`." This is a planning-doc cleanup, NOT a plan revision — but plan 02-07 should flag it and the parent gsd command should land the ROADMAP edit before Phase 2 closure.

### M-8 — Plan 02-02 Task 5 verify step `git diff-tree ... | wc -l | xargs test 3 -eq` asserts EXACTLY 3 files changed. If plan 02-02 also creates `tests/Contract/Adapter/.gitkeep` (mentioned in Task 5 fallback: "If pest fails: create `tests/Contract/Adapter/.gitkeep`"), the file count is 4 and the check fails

Plan 02-02 task 5 lines 416-426: "If pest test fails on plan 02-01 tests due to missing Contract/Adapter directory: create `tests/Contract/Adapter/.gitkeep` so the directory exists pre-plan-02-07." Then the verify asserts `test 3 -eq` on file count. Inconsistent.

**Fix:** Change to `test 4 -ge` (at least 3) or list expected files explicitly.

### M-9 — Plans 02-04 / 02-06 / 02-07 use anonymous classes inside test methods AND named classes (TestSubject, etc) AND helper methods returning anonymous adapters — three patterns for the same purpose. Future maintainer confused

The plans show:
- Plan 02-01 T4: helper method `makeNoopAdapter()` returning anonymous class
- Plan 02-04 T3: helper method `makeFixedSiteAdapter()` returning anonymous class
- Plan 02-04 T4: top-level named `class TestSubjectAdapter` + `ZeroIdSubjectAdapter`
- Plan 02-06 T2: top-level named `class FakeStubAdapter`, `SpyMetaClient`
- Plan 02-07: tests use `FakeAdapter` from `tests/Doubles/`

**Fix:** Pick ONE pattern across Phase 2. Recommend extracting all to `tests/Doubles/` (FakeAdapter with fluent setters covers most cases). Plans 02-04 and 02-06 then USE the FakeAdapter from 02-07 — but 02-07 is Wave 5, depending on 02-06. Reorder: ship FakeAdapter + FakeValueResolver in Wave 1 alongside 02-01 (no functional dependencies — they only need the interfaces from 02-01 Task 1). Then 02-04 / 02-06 use them. Cleanly reduces 100+ LOC of inline fixtures.

---

## LOW issues

### L-1 — Plan 02-03 Lv translation: "marķieris" for "token" — verify with native speaker; "atslēga" or "talons" may be more idiomatic depending on banking/security context
### L-2 — Plan 02-05 MetaClient default timeout 5s — may be tight for Graph API on slow links. CONTEXT lacks decision. Document or make configurable.
### L-3 — Plan 02-04's SiteResolver final class has zero state, one static method. Could just as well be a global function or trait — but `final class` is idiomatic for Lovata.Toolbox patterns. No change needed.
### L-4 — Plans use both `Illuminate\Support\Facades\Log` and `Log` (October alias). Inconsistent. Pick one (Illuminate FQN preferred per H-3 rationale — testable + grep-able).
### L-5 — Plan 02-06 SendCapiEvent::failed() doesn't restore `$arPayload` from the snapshot — if before_dispatch listener mutated event_id and dispatch threw before snapshot-restore, failed() writes the MUTATED event_id to FailedEvent. Minor edge case but inconsistent with P-08 lockdown.
### L-6 — Plan 02-07 Pest.php comment block doesn't add value (the absence of a uses() binding is self-documenting). Remove for code-noise reduction per CLAUDE.md "no comment pollution" spirit.
### L-7 — Plan 02-03 Task 4 commits composer.json + composer.lock in one commit. Composer.lock for plugin packages is typically NOT committed (the root project's lock is authoritative). Verify project convention before commit.
### L-8 — Multiple plans mix Pest classic style (`final class FooTest extends MetapixelTestCase`) with Pest 4 `it()/test()` functional style. Phase 1 chose classic. Phase 2 should consistently follow. None of the plans use functional style — but a few `pest()->extend(...)` patterns appear in plan 02-07 Task 3 prose discussion (then dropped). Confirm: classic across the board.

---

## REQ coverage matrix

All 11 ADAP-* requirements have at least one plan + task. The mapping is correct.

| REQ-ID | Plan | Task | Notes |
|--------|------|------|-------|
| ADAP-01 | 02-01 | Task 1 | EventSubjectAdapter interface, 7 methods. Frontmatter `requirements:` declares. |
| ADAP-02 | 02-01 | Task 1 | ValueResolver interface, 5 methods. |
| ADAP-03 | 02-01 | Tasks 2, 3 | AdapterRegistry final + Plugin::register singleton. |
| ADAP-04 | 02-06 | Task 1 | 3 Event::fire hooks. OQ-2 halt-able on before_dispatch. |
| ADAP-05 | 02-06 | Task 1 | Listener exception catch + Log::warning. |
| ADAP-06 | 02-04 (primary) + 02-02 (enforcement) | T1 (logic) + T2 (PHPStan) | SiteResolver delegation + disallowed-calls. **H-1 weakens enforcement** |
| ADAP-07 | 02-05 | Task 3 | PayloadBuilder subject-agnostic. **H-9 verify guard incomplete** |
| ADAP-08 | 02-05 | Task 2 | UserDataHasher::forSubject. **M-4 memo over-engineered** |
| ADAP-09 | 02-05 | Task 4 | MetaClient::sendForPixel per-call + v23.0. **H-4 install verify broken** |
| ADAP-10 | 02-06 | Task 1 | SendCapiEvent 4th arg + resolveByClass + BindingResolutionException. **H-2 subject_type/id stamped null** |
| ADAP-11 | 02-07 | Tasks 1, 3, 4 | FakeAdapter + ContractTestCase + smoke. **M-7 ROADMAP SC5 mismatch** |

PROJECT.md target features cross-check: all "Generic core" features map to Phase 2 plans. "Adapter contracts" → 02-01. "Lovata-style extensibility" Event::fire → 02-06. "Settings rework" → 02-03 (stub) + Phase 4 (full). No PROJECT.md item for Phase 2 is unmapped.

---

## Pitfall coverage matrix

| Pitfall | Plan | Mechanism | Notes |
|---------|------|-----------|-------|
| P-01 Cross-context resolution drift | 02-02 (phpstan), 02-04 (SiteResolver), 02-07 (ContractTestCase invariant 03/04) | Interface + logic + static analysis + contract test stack | **H-1 weakens the static analysis layer significantly** |
| P-02 Boot-order race | 02-01 (idempotent register + bind-in-register + T4 boot-order test) | Idempotent map + Plugin::register binding | Closed by Plan 02-01. **H-8 risk on Plugin instantiation without container** |
| P-05 EventLog subject_type alias | 02-01 (interface contract) + 02-03 (no MorphTo + alias-comment migration) + 02-04 (EventLogWriter uses registry, not get_class) + 02-07 (ContractTestCase invariant 01) | Interface + storage + write site + contract | All four layers present. |
| P-08 Mutable hook payload | 02-06 (snapshot+restore on event_id/event_time + listener-isolation try/catch + T12 enforcement) | Documented contract + snapshot+restore + listener isolation | Snapshot pattern is correct. **L-5 failed() doesn't snapshot — minor edge case** |
| P-13 Component::extend unbounded | 02-02 (CLAUDE.md addendum: prefer Event::fire over Component::extend) | Convention doc only — no code | Closed by Plan 02-02 CLAUDE.md edit. No Component::extend in Phase 2 scope. |

All 5 named pitfalls have at least one closing mechanism. H-1 partially erodes P-01.

---

## End-to-end trace (Purchase via FakeAdapter)

Walking one Purchase event from dispatch to confirmed Meta CAPI POST through the 7 plans:

1. **Test calls** `SendCapiEvent::dispatch('Purchase', $arPayload, $obSubject, FakeAdapter::class)` — `tests/Feature/Adapter/BackboneIntegrationTest.php` (plan 02-07 Task 4).
2. **`$arPayload`** was assembled by `PayloadBuilder::buildEventPayload(...)` from plan 02-05 Task 3. Subject-agnostic envelope: `['data' => [[event_id, event_time, event_name=Purchase, action_source=website, user_data (sha256 via plan 02-05 Task 2 UserDataHasher), custom_data (currency/value/contents/content_ids from FakeValueResolver via plan 02-07 Task 1, content_type=product)]]]`.
3. **Job enters handle()** — plan 02-06 Task 1 SendCapiEvent::handle:
   a. `AdapterRegistry::resolveByClass(FakeAdapter::class)` — plan 02-01 Task 2 AdapterRegistry returns App::make(FakeAdapter::class) instance. **H-8 risk: BackboneIntegrationTest setUp does `(new Plugin)->register()` which may fail without container.**
   b. `fireBeforeDispatchHalt($obAdapter)` — fires `metapixel.event.before_dispatch` hook. Listener (registered observe-only via Event::listen in BackboneIntegrationTest) returns null → no halt. Snapshot+restore preserves event_id.
   c. `SiteResolver::forSubject($obSubject, $obAdapter)` — plan 02-04 Task 1 delegates to `$obAdapter->getSiteId($obSubject)` → returns null (FakeAdapter default). **H-1: phpstan rule should ban SiteManager calls in SiteResolver but `allowIn` whitelist includes classes/Helper/, so the rule wouldn't fire even if SiteResolver imported SiteManager.**
   d. `EventLogWriter::record(...)` — plan 02-04 Task 2 calls `AdapterRegistry::resolveFor($obSubject)->getSubjectType($obSubject)` → 'fake.subject' opaque alias (plan 02-07 Task 1 FakeAdapter default). Writes row via `insertOrIgnore` to `logingrupa_metapixel_event_log` (plan 02-03 Task 1 migration). Returns true (race won).
   e. `Settings::lookupForSite(null)` — plan 02-03 Task 3 Settings stub returns default-row pixel_id + token from `Settings::set()` in test setUp.
   f. `MetaClient::sendForPixel($sPixelId, $sToken, $arPayload)` — plan 02-05 Task 4. Test injects Guzzle MockHandler returning 200. URL = `https://graph.facebook.com/v23.0/PIXEL-1/events`. Returns `['events_received' => 1, 'fbtrace_id' => 'trace-1']`.
   g. `fireAfterDispatch($arResponse)` — fires `metapixel.event.after_dispatch`. Test listener captures response.
4. **Assertions**:
   - EventLog has 1 row with channel='capi', event_name='Purchase', subject_type='fake.subject', event_id='uuid-backbone-1' ✓
   - FailedEvent table empty ✓
   - after_dispatch listener received `['events_received' => 1, 'fbtrace_id' => 'trace-1']` ✓

**Gaps in trace:**
- **G-1 (H-8):** Plugin::register pattern may TypeError before reaching step 3a. Must fix container injection.
- **G-2 (H-2):** If MetaClient mock returns 400 instead → MetaApiPermanentException → writeFailedEvent — but the FailedEvent row will have `subject_type=null, subject_id=null`, breaking Phase 4 admin UI re-resolution.
- **G-3 (M-5):** Production-path test missing — `serialize(SendCapiEvent)` round-trip not exercised in Phase 2. If subject is non-serializable, production fails silently.

End-to-end trace passes the SC1 round-trip test in spirit but needs H-8 fixed before execute-phase will land green.

---

## Summary

The 7-plan Phase 2 plan set has the right shape: every ADAP-* requirement maps, every named pitfall has at least one closing mechanism, the dependency graph is acyclic, and the end-to-end FakeAdapter pipeline IS exercised in BackboneIntegrationTest. OQ-1 (test scope), OQ-2 (halt-able + listener isolation + snapshot+restore for P-08), and OQ-3 (subject-agnostic PayloadBuilder) are honored. Build philosophy ("fresh code, no port", "no over-engineering") is respected in spirit, though UserDataHasher's memo (M-4) is a minor lapse.

The blockers cluster in three areas: **(1) tooling correctness** — H-1 (allowIn whitelist too broad) silently degrades P-01 enforcement, H-4 (broken Guzzle install verify), H-8 (Plugin instantiation without container will TypeError in tests across plans 02-01/04/06/07); **(2) marketplace contract** — H-3 (contract base extends test-namespace MetapixelTestCase, breaking third-party use AND requiring composer-dependency-analyser suppression of the exact thing it was added for); **(3) Phase 4 enablement** — H-2 (FailedEvent rows hardcode subject_type/subject_id null, defeating admin UI re-resolution).

Each blocker has a small fix. Recommend: revise plans 02-01 + 02-02 + 02-03 + 02-06 + 02-07 to address H-1, H-2, H-3, H-4, H-6, H-8 — then ship. Plans 02-04 and 02-05 are largely sound; minor tweaks for H-9 / M-4 / shared fixtures (M-9).

After revisions, re-run plan-checker. Estimated revision scope: ~30-60 LOC across 5 plan files, ~2-hour planner effort.
