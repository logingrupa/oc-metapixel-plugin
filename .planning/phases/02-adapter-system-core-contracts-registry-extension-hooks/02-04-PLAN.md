---
phase: 02-adapter-system-core-contracts-registry-extension-hooks
plan: 4
slug: siteresolver-eventlogwriter-racefence
type: execute
wave: 3
depends_on:
  - 02-01
  - 02-03a
files_modified:
  - plugins/logingrupa/metapixel/classes/Helper/SiteResolver.php
  - plugins/logingrupa/metapixel/classes/Helper/EventLogWriter.php
  - plugins/logingrupa/metapixel/tests/Unit/Helper/SiteResolverTest.php
  - plugins/logingrupa/metapixel/tests/Feature/Adapter/EventLogWriterRaceFenceTest.php
autonomous: true
requirements:
  - ADAP-06
maps_to:
  pitfalls:
    - P-01
    - P-05
  decisions:
    - D-17
must_haves:
  truths:
    - "`Logingrupa\\Metapixel\\Classes\\Helper\\SiteResolver::forSubject(object $obSubject, EventSubjectAdapter $obAdapter): ?int` exists and delegates exclusively to `$obAdapter->getSiteId($obSubject)`."
    - "SiteResolver class body NEVER references SiteManager, Site facade, Request, or request() — verified by phpstan.neon disallowed-calls (plan 02-02 enforces via disallowIn deny-list) AND by static-source regex grep in this plan's T6 test (defence-in-depth)."
    - "`Logingrupa\\Metapixel\\Classes\\Helper\\EventLogWriter::record(string $sEventId, string $sEventName, string $sChannel, object $obSubject, ?string $sSecretKey, int $iEventTime, ?int $iSiteId): bool` writes an insertOrIgnore row to logingrupa_metapixel_event_log and returns true on win, false on UNIQUE collision OR DB write failure (fail-safe)."
    - "EventLogWriter resolves subject_type via AdapterRegistry::resolveFor($obSubject)->getSubjectType($obSubject) — NEVER via get_class($obSubject) (P-05 anchor)."
    - "EventLogWriter returns false (and logs a warning) when no adapter is registered for the subject — does not throw."
    - "EventLogWriter returns false (and logs critical) on database write failure — fail-safe: assume peer won the race, do not double-fire."
    - "Race-fence Feature test asserts two SEQUENTIAL insertOrIgnore calls with identical key (subject_type, subject_id, event_name, channel, site_id) → first returns true, second returns false (UNIQUE collision). Concurrent contention is NOT exercised in SQLite-in-memory test env (M-3 wording — UNIQUE blocks the second insert regardless of concurrency)."
    - "All 4 new SiteResolver / EventLogWriter tests pass."
    - "composer qa exits 0."
  artifacts:
    - path: "plugins/logingrupa/metapixel/classes/Helper/SiteResolver.php"
      provides: "ADAP-06 primary — only authoritative site_id source."
      contains: "forSubject"
    - path: "plugins/logingrupa/metapixel/classes/Helper/EventLogWriter.php"
      provides: "UNIQUE race-fence write; alias-correct subject_type (P-05)."
      contains: "insertOrIgnore"
    - path: "plugins/logingrupa/metapixel/tests/Unit/Helper/SiteResolverTest.php"
      provides: "T6 — SiteResolver delegates to adapter only."
      contains: "forSubject"
    - path: "plugins/logingrupa/metapixel/tests/Feature/Adapter/EventLogWriterRaceFenceTest.php"
      provides: "T17 — UNIQUE race-fence + NULL-distinct semantics (sequential, not concurrent)."
      contains: "race"
  key_links:
    - from: "plugins/logingrupa/metapixel/classes/Helper/SiteResolver.php"
      to: "plugins/logingrupa/metapixel/classes/Adapter/EventSubjectAdapter.php"
      via: "getSiteId(object) delegation"
      pattern: "->getSiteId\\("
    - from: "plugins/logingrupa/metapixel/classes/Helper/EventLogWriter.php"
      to: "plugins/logingrupa/metapixel/classes/Adapter/AdapterRegistry.php"
      via: "resolveFor($obSubject)->getSubjectType($obSubject)"
      pattern: "resolveFor"
    - from: "plugins/logingrupa/metapixel/classes/Helper/EventLogWriter.php"
      to: "logingrupa_metapixel_event_log table"
      via: "DB::table()->insertOrIgnore()"
      pattern: "insertOrIgnore"
---

<objective>
Ship the cross-context site_id resolver (`SiteResolver::forSubject`) and the UNIQUE race-fence write helper (`EventLogWriter::record`). Together these close ADAP-06 (PRIMARY — the logic part; SECONDARY phpstan enforcement landed in plan 02-02) and the P-05 alias-correctness write site (EventLogWriter consults AdapterRegistry to get the opaque subject_type alias, NEVER `get_class()` directly). Race-fence behavior — UNIQUE on (subject_type, subject_id, event_name, channel, site_id), NULL site_id distinct — verified by a Feature test against hermetic SQLite using SEQUENTIAL insertOrIgnore calls (M-3 — SQLite-in-memory has no concurrency; UNIQUE blocks the second insert regardless of concurrency model).

Purpose: downstream plan 02-06 SendCapiEvent depends on both helpers (writes the capi-channel row, reads site_id for Settings::lookupForSite); plan 02-07's ContractTestCase + FakeAdapter smoke test rides through the same path.

Output: 2 production helpers + 2 test files.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@plugins/logingrupa/metapixel/CLAUDE.md
@plugins/logingrupa/metapixel/.planning/PROJECT.md
@plugins/logingrupa/metapixel/.planning/REQUIREMENTS.md
@plugins/logingrupa/metapixel/.planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-CONTEXT.md
@plugins/logingrupa/metapixel/.planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-RESEARCH.md
@plugins/logingrupa/metapixel/classes/Adapter/EventSubjectAdapter.php
@plugins/logingrupa/metapixel/classes/Adapter/ValueResolver.php
@plugins/logingrupa/metapixel/classes/Adapter/AdapterRegistry.php
@plugins/logingrupa/metapixel/models/EventLog.php
@plugins/logingrupa/metapixel/updates/create_metapixel_event_log_table.php
@plugins/logingrupa/metapixel/phpstan.neon
@plugins/logingrupa/metapixel/tests/MetapixelTestCase.php
@plugins/logingrupa/metapixel/tests/Doubles/TestSubject.php
@plugins/logingrupa/metapixel/tests/Doubles/TestSubjectAdapter.php
@plugins/logingrupa/metapixel/tests/Doubles/ZeroIdSubjectAdapter.php

<interfaces>
Locked decisions:

- D-17 (CONTEXT): SiteResolver::forSubject is the ONLY authoritative source of site_id. PHPStan disallowed-calls (plan 02-02) bans SiteManager / Site / Request / request() inside `classes/Queue/*`, `classes/Event/*`, `classes/Adapter/*` (H-1 disallowIn deny-list). NOTE: plan 02-02's deny-list does NOT cover `classes/Helper/*` — so SiteResolver itself could technically call those, but doesn't (D-17 forbids by design). Belt-and-suspenders: this plan's T6 test grep-guards against any such call inside SiteResolver.php source.
- D-04..D-07 (CONTEXT): UNIQUE on (subject_type, subject_id, event_name, channel, site_id). NULL distinct. EventLog already migrated by plan 02-03a.

SiteResolver shape (RESEARCH §4.9 — 25 LOC):

```
namespace Logingrupa\Metapixel\Classes\Helper;

use Logingrupa\Metapixel\Classes\Adapter\EventSubjectAdapter;

final class SiteResolver
{
    public static function forSubject(object $obSubject, EventSubjectAdapter $obAdapter): ?int
    {
        return $obAdapter->getSiteId($obSubject);
    }
}
```

That's it. Tiger-Style minimal. No defensive checks beyond the type hints. No `Site::setSite()` fallback. ThemeActionAdapter (Phase 3) is the only place where Site::getCurrent fallback is allowed — and that fallback lives inside THAT adapter, not here.

EventLogWriter shape (RESEARCH §4.8 — ~80 LOC):

```
namespace Logingrupa\Metapixel\Classes\Helper;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;

final class EventLogWriter
{
    public static function record(
        string $sEventId,
        string $sEventName,
        string $sChannel,
        object $obSubject,
        ?string $sSecretKey,
        int $iEventTime,
        ?int $iSiteId,
    ): bool {
        try {
            $obRegistry = App::make(AdapterRegistry::class);
            $obAdapter = $obRegistry->resolveFor($obSubject);
            if ($obAdapter === null) {
                Log::warning('metapixel: EventLogWriter — no adapter registered for subject', [
                    'meta_pixel.subject_class' => get_class($obSubject),
                    'meta_pixel.event_name' => $sEventName,
                    'meta_pixel.channel' => $sChannel,
                ]);
                return false;
            }

            $sSubjectType = $obAdapter->getSubjectType($obSubject);
            $iSubjectId = $obAdapter->getSubjectId($obSubject);
            if ($iSubjectId <= 0) {
                Log::warning('metapixel: EventLogWriter rejected non-positive subject id', [
                    'meta_pixel.subject_type' => $sSubjectType,
                    'meta_pixel.subject_id' => $iSubjectId,
                ]);
                return false;
            }

            $sNow = (string) Carbon::now();
            $iAffected = DB::table('logingrupa_metapixel_event_log')->insertOrIgnore([
                'event_id' => $sEventId,
                'event_name' => $sEventName,
                'channel' => $sChannel,
                'subject_type' => $sSubjectType,
                'subject_id' => $iSubjectId,
                'secret_key' => $sSecretKey,
                'site_id' => $iSiteId,
                'event_time' => $iEventTime,
                'fired_at' => $sNow,
                'created_at' => $sNow,
                'updated_at' => $sNow,
            ]);

            return $iAffected === 1;
        } catch (\Throwable $obException) {
            Log::critical('metapixel: EventLogWriter::record DB write FAILED', [
                'meta_pixel.exception' => get_class($obException),
                'meta_pixel.message' => $obException->getMessage(),
                'meta_pixel.event_id' => $sEventId,
                'meta_pixel.event_name' => $sEventName,
                'meta_pixel.channel' => $sChannel,
            ]);
            return false;  // fail-safe: peer assumed to have won → no double-fire
        }
    }
}
```

KEY P-05 DIFFERENCE from v1.x: writer consults AdapterRegistry::resolveFor() to get the opaque alias. v1.x wrote `get_class($obSubject)` directly — the anti-pattern this plan fixes.

L-4 lock: imports use `Illuminate\Support\Facades\Log`, `Illuminate\Support\Facades\App`, `Illuminate\Support\Facades\DB` FQN — never `use Log;` / `use App;` / `use DB;` shortform.

`[VERIFIED: vendor/laravel/framework/src/Illuminate/Database/Query/Builder.php:4132 — insertOrIgnore returns int<0,max>; 1 = race winner, 0 = UNIQUE collision]`

Note on the AdapterRegistry::resolveFor call: this requires the registry to have an adapter registered for the subject. In Phase 2 the Phase 2-shipped doubles cover the test path (TestSubject → TestSubjectAdapter, ZeroIdSubjectAdapter for the `<= 0` branch — all from plan 02-01 Task 4). In Phase 3 ShopaholicOrderAdapter is registered for `Lovata\OrdersShopaholic\Models\Order`.

Phase 1 MetapixelTestCase boots SQLite-in-memory but does NOT migrate the plugin's `updates/` automatically (autoMigrate=false). Plan 02-03a's migration test runs the migration inside the test body. Plan 02-04 follows the same pattern — Feature test runs `(new CreateMetapixelEventLogTable)->up();` in setUp() (and `->down();` in tearDown()).

H-8 test setUp pattern (from plan 02-01): every test setUp binds AdapterRegistry directly via `$this->app->singleton(AdapterRegistry::class)` — never via `(new \Logingrupa\Metapixel\Plugin)->register()`. PluginBase constructor requires container injection and the bare instantiation TypeErrors.
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Write SiteResolver</name>
  <files>
    plugins/logingrupa/metapixel/classes/Helper/SiteResolver.php
  </files>
  <behavior>
    - Final class `Logingrupa\Metapixel\Classes\Helper\SiteResolver`.
    - Single public static method `forSubject(object $obSubject, EventSubjectAdapter $obAdapter): ?int`.
    - Body: `return $obAdapter->getSiteId($obSubject);` — one line.
    - Class-level PHPDoc documents the cross-context-determinism constraint in prose (no `// P-01` marker).
    - File ≤ 30 LOC.
    - No imports beyond `EventSubjectAdapter`. No Site, no SiteManager, no Request, no request().
    - php -l clean.
  </behavior>
  <action>
Create `classes/Helper/SiteResolver.php`:

```
<?php

namespace Logingrupa\Metapixel\Classes\Helper;

use Logingrupa\Metapixel\Classes\Adapter\EventSubjectAdapter;

/**
 * The only authoritative source of site_id for an event-fire path.
 *
 * Reads exclusively from the subject via the supplied adapter — never from
 * request context, OctoberCMS SiteManager, Auth, or any ambient state. This
 * is the cross-context determinism contract: admin-side flips, queue-time
 * worker dispatches, and frontend EventPixel renders all return the same
 * site_id for the same subject. Hard-banned via phpstan disallowed-calls
 * in adapter / queue / event directories.
 */
final class SiteResolver
{
    public static function forSubject(object $obSubject, EventSubjectAdapter $obAdapter): ?int
    {
        return $obAdapter->getSiteId($obSubject);
    }
}
```

Three constraints, all checked by grep guards in the verify step:

1. NO `use App` / `use Site` / `use SiteManager` / `use Request`.
2. NO `request()` call anywhere in the body.
3. The one public static method delegates immediately to `$obAdapter->getSiteId($obSubject)` — no intermediate logic.
  </action>
  <verify>
    <automated>test -f plugins/logingrupa/metapixel/classes/Helper/SiteResolver.php &amp;&amp; php -l plugins/logingrupa/metapixel/classes/Helper/SiteResolver.php | grep -q 'No syntax errors' &amp;&amp; grep -q 'final class SiteResolver' plugins/logingrupa/metapixel/classes/Helper/SiteResolver.php &amp;&amp; grep -q 'return \$obAdapter-&gt;getSiteId(\$obSubject);' plugins/logingrupa/metapixel/classes/Helper/SiteResolver.php &amp;&amp; ! grep -E '(SiteManager|Site::|request\(\)|use Illuminate.Http.Request|use System.Classes.SiteManager)' plugins/logingrupa/metapixel/classes/Helper/SiteResolver.php &amp;&amp; ! grep -E '(// CR-[0-9]|// Phase\s*[0-9]|// Plan\s*[0-9]|// P-0[0-9])' plugins/logingrupa/metapixel/classes/Helper/SiteResolver.php</automated>
  </verify>
  <done>SiteResolver.php is final + has forSubject only + delegates to adapter + has zero SiteManager/Site/Request/request() references.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Write EventLogWriter</name>
  <files>
    plugins/logingrupa/metapixel/classes/Helper/EventLogWriter.php
  </files>
  <behavior>
    - Final class `Logingrupa\Metapixel\Classes\Helper\EventLogWriter`.
    - Single public static method `record(...): bool` with 7 parameters per RESEARCH §4.8.
    - Resolves adapter via `App::make(AdapterRegistry::class)->resolveFor($obSubject)`; logs warning + returns false on null adapter.
    - Reads subject_type via `$obAdapter->getSubjectType($obSubject)` — NEVER calls `get_class($obSubject)` for subject_type (P-05). Only logs `get_class($obSubject)` in error contexts.
    - Validates `$obAdapter->getSubjectId($obSubject) > 0`; logs warning + returns false on ≤ 0.
    - `DB::table('logingrupa_metapixel_event_log')->insertOrIgnore([...])` → returns true if `$iAffected === 1`, false on 0 (UNIQUE collision).
    - Outer try/catch: any throwable → Log::critical + return false (fail-safe per RESEARCH §4.8).
    - Uses `Illuminate\Support\Facades\Log`, `Illuminate\Support\Facades\App`, `Illuminate\Support\Facades\DB` FQN imports (L-4 lock).
    - File ≤ 100 LOC executable.
    - php -l clean.
  </behavior>
  <action>
Create `classes/Helper/EventLogWriter.php` per RESEARCH §4.8 shape (full code in `<interfaces>` above). Carry the PHPDoc:

```
/**
 * UNIQUE race-fence writer for logingrupa_metapixel_event_log. Returns true
 * when this caller won the insert; false when the row already exists OR
 * when the database refused the write. False is the SAFE direction — if
 * we can't tell who won, we assume the peer won so no double-fire happens.
 *
 * subject_type is resolved via the registered adapter (opaque alias), NEVER
 * via get_class($obSubject) — that anti-pattern would store class FQNs
 * which then collide across namespace-rename events.
 */
```

CRITICAL: the `$sChannel` parameter is a string ('capi' or 'pixel'). Do NOT type-hint as an enum — Phase 2 hasn't created an enum for it (could be added in v2.1). Callers pass `EventLog::CHANNEL_CAPI` / `EventLog::CHANNEL_PIXEL` constants.

Log call shape:
- key prefix `meta_pixel.*` for structured filtering in production log aggregators.
- avoid logging the full `$arPayload` — `Log::critical` logs only event_id + event_name + channel + exception class + message.

PHP 8.3/8.4 dual constraint: use `\Throwable` catch (works on both). No PHP 8.4 typed property defaults beyond what works on 8.3.
  </action>
  <verify>
    <automated>test -f plugins/logingrupa/metapixel/classes/Helper/EventLogWriter.php &amp;&amp; php -l plugins/logingrupa/metapixel/classes/Helper/EventLogWriter.php | grep -q 'No syntax errors' &amp;&amp; grep -q 'final class EventLogWriter' plugins/logingrupa/metapixel/classes/Helper/EventLogWriter.php &amp;&amp; grep -q 'AdapterRegistry::class' plugins/logingrupa/metapixel/classes/Helper/EventLogWriter.php &amp;&amp; grep -q 'insertOrIgnore' plugins/logingrupa/metapixel/classes/Helper/EventLogWriter.php &amp;&amp; grep -q '\$obAdapter-&gt;getSubjectType(\$obSubject)' plugins/logingrupa/metapixel/classes/Helper/EventLogWriter.php &amp;&amp; grep -q 'use Illuminate\\\\Support\\\\Facades\\\\Log;' plugins/logingrupa/metapixel/classes/Helper/EventLogWriter.php &amp;&amp; ! grep -v '^#' plugins/logingrupa/metapixel/classes/Helper/EventLogWriter.php | grep -E "get_class\(\\\$obSubject\)" | grep -v 'Log::' &amp;&amp; ! grep -E '(// CR-[0-9]|// Phase\s*[0-9]|// Plan\s*[0-9]|// P-0[0-9])' plugins/logingrupa/metapixel/classes/Helper/EventLogWriter.php</automated>
  </verify>
  <done>EventLogWriter.php is final + reads subject_type via adapter + uses insertOrIgnore + no get_class subject_type write + uses Log/App/DB FQN imports (L-4) + no phase markers.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: Write SiteResolverTest (T6)</name>
  <files>
    plugins/logingrupa/metapixel/tests/Unit/Helper/SiteResolverTest.php
  </files>
  <behavior>
    - Final class `SiteResolverTest extends MetapixelTestCase`.
    - Test `test_for_subject_delegates_to_adapter_get_site_id` — instantiate FakeAdapter (from `tests/Doubles/`) with site_id=7; assert `SiteResolver::forSubject($obSubject, $obAdapter) === 7`.
    - Test `test_for_subject_propagates_null_from_adapter` — FakeAdapter with site_id=null (default); SiteResolver returns null.
    - Test `test_site_resolver_makes_no_request_or_site_manager_calls` — static-source regex check that SiteResolver.php has zero references to Request / SiteManager / Site / request().
    - All tests pass.
  </behavior>
  <action>
Create `tests/Unit/Helper/SiteResolverTest.php`:

```
<?php

use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Helper\SiteResolver;
use Logingrupa\Metapixel\Tests\Doubles\FakeAdapter;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;

final class SiteResolverTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
    }

    public function test_for_subject_delegates_to_adapter_get_site_id(): void
    {
        $obAdapter = (new FakeAdapter)->withSiteId(7);
        $iResult = SiteResolver::forSubject(new \stdClass, $obAdapter);
        $this->assertSame(7, $iResult);
    }

    public function test_for_subject_propagates_null_from_adapter(): void
    {
        $obAdapter = new FakeAdapter;  // default site_id is null
        $iResult = SiteResolver::forSubject(new \stdClass, $obAdapter);
        $this->assertNull($iResult);
    }

    /**
     * Static defence: SiteResolver.php must contain no reference to Request,
     * SiteManager, Site facade, or the global request() helper. Cross-context
     * determinism is enforced statically here in addition to the phpstan rule.
     */
    public function test_site_resolver_makes_no_request_or_site_manager_calls(): void
    {
        $sSource = file_get_contents(__DIR__.'/../../../classes/Helper/SiteResolver.php');
        $this->assertIsString($sSource);
        $this->assertDoesNotMatchRegularExpression('/\bSiteManager\b/', $sSource);
        $this->assertDoesNotMatchRegularExpression('/\bSite::/', $sSource);
        $this->assertDoesNotMatchRegularExpression('/\bRequest::/', $sSource);
        $this->assertDoesNotMatchRegularExpression('/\brequest\s*\(/', $sSource);
    }
}
```

Uses the shared FakeAdapter double from plan 02-01 Task 4 — no inline anonymous adapter needed. H-8 setUp pattern (singleton bind, no `(new Plugin)->register()`).

The third test is a deliberate static defence — it asserts that the source code of SiteResolver.php itself has zero matches for the banned identifiers. This is belt-and-suspenders alongside plan 02-02's phpstan rule. Even if phpstan misses the rule for some reason (wrong FQN, config typo), this runtime check catches it.
  </action>
  <verify>
    <automated>test -f plugins/logingrupa/metapixel/tests/Unit/Helper/SiteResolverTest.php &amp;&amp; php -l plugins/logingrupa/metapixel/tests/Unit/Helper/SiteResolverTest.php | grep -q 'No syntax errors' &amp;&amp; ! grep -E '\(new\s+\\?Logingrupa\\\\Metapixel\\\\Plugin\)' plugins/logingrupa/metapixel/tests/Unit/Helper/SiteResolverTest.php &amp;&amp; cd plugins/logingrupa/metapixel &amp;&amp; ../../../vendor/bin/pest tests/Unit/Helper/SiteResolverTest.php --configuration phpunit.xml 2&gt;&amp;1 | tail -5 | grep -Eq '(PASS|OK|3 passed|Tests:.*passed)'</automated>
  </verify>
  <done>SiteResolverTest passes 3 tests including the static-defence regex grep; H-8 setUp pattern enforced.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 4: Write EventLogWriterRaceFenceTest (T17)</name>
  <files>
    plugins/logingrupa/metapixel/tests/Feature/Adapter/EventLogWriterRaceFenceTest.php
  </files>
  <behavior>
    - Final class `EventLogWriterRaceFenceTest extends MetapixelTestCase`.
    - setUp() binds AdapterRegistry singleton (H-8), runs `(new CreateMetapixelEventLogTable)->up()`, registers TestSubject → TestSubjectAdapter (both from plan 02-01 `tests/Doubles/`).
    - tearDown() runs `(new CreateMetapixelEventLogTable)->down()` + `app()->forgetInstance(AdapterRegistry::class)` for test isolation.
    - Test `test_record_returns_true_on_first_insert_and_false_on_duplicate_unique_key` — two SEQUENTIAL record() calls with identical key params; first true, second false. EventLog table has exactly 1 row. (Note: SEQUENTIAL — SQLite-in-memory has no concurrency; UNIQUE blocks the second insert regardless.)
    - Test `test_record_returns_true_for_distinct_channel_same_subject` — two record() calls with same subject + event_name but different channels (capi then pixel); both true. EventLog table has exactly 2 rows.
    - Test `test_record_returns_true_for_distinct_site_id_same_subject` — UNIQUE NULL-distinct check: same subject/event/channel with site_id=null then site_id=7 — both succeed.
    - Test `test_record_returns_false_when_no_adapter_registered_for_subject` — fresh registry; record() against unregistered subject → false + Log::warning.
    - Test `test_record_returns_false_on_non_positive_subject_id` — registers ZeroIdSubjectAdapter; record() returns false + Log::warning.
    - Test `test_record_stores_subject_type_alias_not_class_fqn` — TestSubjectAdapter returns alias 'fake.subject'; insert; assert DB row's subject_type column = 'fake.subject', NOT 'TestSubject' or its FQN.
    - All tests pass.
  </behavior>
  <action>
Create `tests/Feature/Adapter/EventLogWriterRaceFenceTest.php`. Use Feature tier (under `tests/Feature/Adapter/`) because the test exercises the DB — Phase 1 `phpunit.xml` already routes Adapter dir to Feature/Unit testsuites.

```
<?php

namespace Logingrupa\Metapixel\Tests\Feature\Adapter;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Helper\EventLogWriter;
use Logingrupa\Metapixel\Tests\Doubles\TestSubject;
use Logingrupa\Metapixel\Tests\Doubles\TestSubjectAdapter;
use Logingrupa\Metapixel\Tests\Doubles\ZeroIdSubjectAdapter;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Logingrupa\Metapixel\Updates\CreateMetapixelEventLogTable;

final class EventLogWriterRaceFenceTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
        (new CreateMetapixelEventLogTable)->up();
        app(AdapterRegistry::class)->register(TestSubject::class, TestSubjectAdapter::class);
    }

    protected function tearDown(): void
    {
        (new CreateMetapixelEventLogTable)->down();
        app()->forgetInstance(AdapterRegistry::class);
        parent::tearDown();
    }

    public function test_record_returns_true_on_first_insert_and_false_on_duplicate_unique_key(): void
    {
        $obSubject = new TestSubject;

        // M-3: SEQUENTIAL inserts. SQLite-in-memory has no concurrency; the
        // UNIQUE constraint blocks the second insert regardless of the
        // concurrency model. The race-fence INVARIANT (only-one-winner-per-key)
        // is what's tested; concurrency itself is not exercised in this env.
        $bWonFirst = EventLogWriter::record('uuid-1', 'Purchase', 'capi', $obSubject, null, 1700000000, null);
        $bWonSecond = EventLogWriter::record('uuid-2', 'Purchase', 'capi', $obSubject, null, 1700000001, null);

        $this->assertTrue($bWonFirst);
        $this->assertFalse($bWonSecond);
        $this->assertSame(1, DB::table('logingrupa_metapixel_event_log')->count());
    }

    public function test_record_returns_true_for_distinct_channel_same_subject(): void
    {
        $obSubject = new TestSubject;
        $bCapi = EventLogWriter::record('uuid-1', 'Purchase', 'capi', $obSubject, null, 1700000000, null);
        $bPixel = EventLogWriter::record('uuid-1', 'Purchase', 'pixel', $obSubject, null, 1700000000, null);

        $this->assertTrue($bCapi);
        $this->assertTrue($bPixel);
        $this->assertSame(2, DB::table('logingrupa_metapixel_event_log')->count());
    }

    public function test_record_returns_true_for_distinct_site_id_same_subject(): void
    {
        $obSubject = new TestSubject;
        $bNullSite = EventLogWriter::record('uuid-1', 'Purchase', 'capi', $obSubject, null, 1700000000, null);
        $bSite7 = EventLogWriter::record('uuid-2', 'Purchase', 'capi', $obSubject, null, 1700000000, 7);

        $this->assertTrue($bNullSite, 'null site_id insert wins');
        $this->assertTrue($bSite7, 'site_id=7 insert wins — UNIQUE NULL-distinct semantics');
        $this->assertSame(2, DB::table('logingrupa_metapixel_event_log')->count());
    }

    public function test_record_returns_false_when_no_adapter_registered_for_subject(): void
    {
        Log::shouldReceive('warning')->atLeast()->once();

        $bResult = EventLogWriter::record('uuid-1', 'Purchase', 'capi', new \stdClass, null, 1700000000, null);
        $this->assertFalse($bResult);
        $this->assertSame(0, DB::table('logingrupa_metapixel_event_log')->count());
    }

    public function test_record_returns_false_on_non_positive_subject_id(): void
    {
        app()->forgetInstance(AdapterRegistry::class);
        $this->app->singleton(AdapterRegistry::class);
        app(AdapterRegistry::class)->register(TestSubject::class, ZeroIdSubjectAdapter::class);

        Log::shouldReceive('warning')->atLeast()->once();
        $bResult = EventLogWriter::record('uuid-1', 'Purchase', 'capi', new TestSubject, null, 1700000000, null);
        $this->assertFalse($bResult);
    }

    public function test_record_stores_subject_type_alias_not_class_fqn(): void
    {
        $obSubject = new TestSubject;
        EventLogWriter::record('uuid-1', 'Purchase', 'capi', $obSubject, null, 1700000000, null);

        $obRow = DB::table('logingrupa_metapixel_event_log')->first();
        $this->assertSame('fake.subject', $obRow->subject_type, 'opaque alias written, not class FQN');
        $this->assertStringNotContainsString('\\', $obRow->subject_type, 'no backslashes — alias not FQN');
    }
}
```

Uses the shared `tests/Doubles/` fixtures (TestSubject, TestSubjectAdapter, ZeroIdSubjectAdapter) from plan 02-01 — no inline class declarations (H-6 collision elimination). H-8 setUp pattern throughout.
  </action>
  <verify>
    <automated>test -f plugins/logingrupa/metapixel/tests/Feature/Adapter/EventLogWriterRaceFenceTest.php &amp;&amp; php -l plugins/logingrupa/metapixel/tests/Feature/Adapter/EventLogWriterRaceFenceTest.php | grep -q 'No syntax errors' &amp;&amp; ! grep -E '\(new\s+\\?Logingrupa\\\\Metapixel\\\\Plugin\)' plugins/logingrupa/metapixel/tests/Feature/Adapter/EventLogWriterRaceFenceTest.php &amp;&amp; ! grep -E '^class\s+(TestSubject|TestSubjectAdapter|ZeroIdSubjectAdapter)' plugins/logingrupa/metapixel/tests/Feature/Adapter/EventLogWriterRaceFenceTest.php &amp;&amp; cd plugins/logingrupa/metapixel &amp;&amp; ../../../vendor/bin/pest tests/Feature/Adapter/EventLogWriterRaceFenceTest.php --configuration phpunit.xml 2&gt;&amp;1 | tail -10 | grep -Eq '(PASS|OK|6 passed|Tests:.*passed)'</automated>
  </verify>
  <done>EventLogWriterRaceFenceTest passes 6 tests covering race-fence + NULL-distinct + alias write + missing-adapter + non-positive-id branches; imports shared doubles (H-6); H-8 setUp pattern enforced.</done>
</task>

<task type="auto">
  <name>Task 5: composer qa + commit</name>
  <files>
    plugins/logingrupa/metapixel/classes/Helper/SiteResolver.php
    plugins/logingrupa/metapixel/classes/Helper/EventLogWriter.php
    plugins/logingrupa/metapixel/tests/Unit/Helper/SiteResolverTest.php
    plugins/logingrupa/metapixel/tests/Feature/Adapter/EventLogWriterRaceFenceTest.php
  </files>
  <action>
From `plugins/logingrupa/metapixel/`:

```
composer qa 2>&1 | tee /tmp/02-04-qa.log | tail -30
```

If phpstan fires on EventLogWriter:
- `App::make` return type narrowing: cast via `/** @var AdapterRegistry $obRegistry */` PHPDoc above the `App::make(AdapterRegistry::class)` call.
- `DB::table(...)->insertOrIgnore(...)` return type: `int<0, max>` — `=== 1` comparison passes phpstan; if not, narrow via intermediate `$iAffected = (int) $iAffected;` cast.

If phpmd fires:
- LongVariable max=40: every local var is < 30 chars. OK.
- Cyclomatic: EventLogWriter::record has the try/catch + 2 early returns + DB write; cyclomatic ~6 — under threshold 10.

If pest coverage < 90% on EventLogWriter:
- Add a Throwable-throwing branch test: register an adapter that throws from getSubjectType (or use a closed table — drop the table then call record — the DB exception triggers the outer catch). Asserts Log::critical called + record returns false.

Commit:

```
git add plugins/logingrupa/metapixel/classes/Helper/SiteResolver.php \
        plugins/logingrupa/metapixel/classes/Helper/EventLogWriter.php \
        plugins/logingrupa/metapixel/tests/Unit/Helper/SiteResolverTest.php \
        plugins/logingrupa/metapixel/tests/Feature/Adapter/EventLogWriterRaceFenceTest.php

git commit -m "$(cat <<'EOF'
feat(metapixel): SiteResolver + EventLogWriter (ADAP-06 + P-05 alias write)

SiteResolver::forSubject is the sole authoritative site_id source —
delegates to the adapter's getSiteId and never reads request context.
Three guards stack: phpstan disallowed-calls (plan 02-02), static-source
regex check in T6, and the one-line delegating body.

EventLogWriter::record performs the UNIQUE race-fence insertOrIgnore on
logingrupa_metapixel_event_log. subject_type comes from the registered
adapter's getSubjectType (opaque alias — P-05 anchor: 'shopaholic.order'
not Lovata\OrdersShopaholic\Models\Order). Returns false on UNIQUE
collision OR any database write failure (fail-safe — peer wins).

Six race-fence Feature tests cover: first-insert-wins / duplicate-loses,
distinct-channel coexists, NULL-distinct site_id coexists, missing-adapter
returns false, non-positive subject_id returns false, alias-written-not-FQN.
Tests import shared doubles from tests/Doubles/ (plan 02-01); use the
$this->app->singleton(AdapterRegistry::class) direct setUp pattern.
EOF
)"
```
  </action>
  <verify>
    <automated>cd plugins/logingrupa/metapixel &amp;&amp; composer qa 2&gt;&amp;1 | tail -5 | grep -Eq '(OK|PASS|0 errors|tests passed|No issues found)' &amp;&amp; git log -1 --pretty=format:'%s' | grep -q 'SiteResolver' &amp;&amp; git diff-tree --no-commit-id --name-only -r HEAD | grep -c '^plugins/logingrupa/metapixel/' | xargs test 4 -eq</automated>
  </verify>
  <done>composer qa exits 0; commit touches exactly 4 files; commit message references ADAP-06 + P-05.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Subject → adapter resolution at write-time | EventLogWriter trusts AdapterRegistry to return a valid adapter or null; cannot fall back to `get_class($obSubject)` (P-05 anti-pattern). Null adapter → false + warning (no fence written, no event suppression — fail-open at the write site, fail-safe at the dispatch site). |
| DB connection failure | Outer try/catch swallows DB exceptions → returns false + Log::critical. The risk is silent event-suppression on DB outage; the benefit is no cascading queue retries that themselves fail with DB errors. Phase 4 dead-letter UI surfaces operator visibility. |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-02-04-01 | Tampering | A malicious subject (e.g. crafted ID 0 or negative) triggers a row with garbage subject_id | mitigate | EventLogWriter rejects `$iSubjectId <= 0` with Log::warning + false. Adapter is the trust anchor (the registered adapter MUST validate its own subject); EventLogWriter is the last-line backstop. |
| T-02-04-02 | Spoofing | A subject class shares a name with a different vendor's class (e.g. two `Order` models) | mitigate | AdapterRegistry::resolveFor walks the FQN map — `get_class($obSubject)` returns the full FQN, so two `Order` models in different namespaces resolve to different adapters. Phase 1 composer-dependency-analyser already enforces import isolation. |
| T-02-04-03 | Repudiation | Operator wonders why a Purchase event didn't fire — was the UNIQUE constraint hit or the DB down? | mitigate | EventLogWriter logs Log::warning on no-adapter, Log::warning on bad subject_id, Log::critical on DB write failure. EventLog rows themselves are the audit trail for successful writes. Phase 4 FailedEvent admin UI surfaces queue-time failures. |
| T-02-04-04 | Information Disclosure | Log::critical leaks secrets (event_id is a UUID — not sensitive; payload not logged here) | accept | event_id is a server-generated UUIDv4 — not derived from user data. event_name + channel + subject_type are not sensitive. The full payload is NOT logged at EventLogWriter — only the dispatch identifier shape. |
| T-02-04-05 | Denial of Service | A malicious actor floods events that all collide on UNIQUE — EventLog stays small but DB keeps doing insertOrIgnore work | accept | insertOrIgnore is constant-time per attempt; UNIQUE index lookup is O(log n). Realistic flood rate is < 1k req/s — DB handles. Future optimization (Bloom-filter check before insertOrIgnore) is v2.x scope. |
| T-02-04-06 | Elevation of Privilege | SiteResolver bypass via a hand-crafted adapter that lies about site_id | accept | The adapter is plugin-trusted code — anyone shipping an adapter can already do anything. SiteResolver's contract is "adapter authoritative". Plan 02-07's ContractTestCase invariant 04 catches accidental Request/SiteManager-coupling in third-party adapters. |

</threat_model>

<verification>
## Goal-Backward Reachability Audit

1. "SiteResolver delegates only to adapter, never touches request context" — Task 1 single-line body + Task 3 T6 static-source regex check + plan 02-02 phpstan rule.
2. "EventLogWriter writes subject_type via adapter alias (P-05)" — Task 2 implementation + Task 4 last test (`subject_type_alias_not_class_fqn`).
3. "EventLogWriter returns true on race-win, false on collision OR DB failure" — Task 2 implementation + Task 4 first 4 tests.
4. "UNIQUE NULL-distinct semantics for site_id" — Task 4 third test verifies on SQLite-in-memory.
5. "composer qa exits 0" — Task 5.

No must-have is UNREACHABLE.

## Multi-Source Coverage Audit

| Source item | Type | Coverage | Notes |
|-------------|------|----------|-------|
| ROADMAP Phase 2 SC2 (SiteResolver authoritative + PHPStan + contract test invariant) | Goal SC2 | SiteResolver in this plan; phpstan rule in plan 02-02; contract test invariant in plan 02-07. SC2 is multi-plan-spanning. | Closes the LOGIC half here |
| REQ ADAP-06 (SiteResolver::forSubject signature) | Requirement | Task 1 | Single-line body matches RESEARCH §4.9 verbatim |
| CONTEXT D-17 (SiteResolver authoritative + disallowed-calls) | Decision | Task 1 (logic) + plan 02-02 (enforcement) | Both layers honored |
| RESEARCH §4.8 EventLogWriter shape | Reference | Task 2 | Code matches verbatim |
| RESEARCH §4.9 SiteResolver shape | Reference | Task 1 | 25 LOC; one method; one line of body |
| RESEARCH §6 T6 SiteResolverTest | Reference | Task 3 | Plus added static-source defence test |
| RESEARCH §6 T17 EventLogWriterRaceFenceTest | Reference | Task 4 | Plus 5 additional branch tests |
| PITFALLS P-01 (cross-context drift — logic) | Pitfall | Task 1 + Task 3 static defence | OWNED here (logic); enforcement in plan 02-02 |
| PITFALLS P-05 (subject_type alias not FQN — write site) | Pitfall | Task 2 + Task 4 alias test | OWNED here (write site); contract for adapters in plan 02-07 |
| CONTEXT D-04..D-07 (race-fence migration + UNIQUE NULL-distinct) | Decision | Task 4 tests on the migration from plan 02-03a | NULL-distinct test explicit |
| RESEARCH §9 A5 (SQLite vs MySQL UNIQUE NULL semantics) | Risk | Task 4 third test | RESOLVED: SQLite behavior verified in test |
| RESEARCH §9 A3 (is_a sibling-class collision) | Risk | not relevant here (different subject classes) | EventLogWriter uses AdapterRegistry::resolveFor; the collision risk lives in registry, documented in plan 02-01 |
| Plan-checker M-3 (concurrent wording) | Revision | Truths + Task 4 inline comment | "two SEQUENTIAL insertOrIgnore calls with identical key" — concurrent contention not exercised in SQLite test env |
| Plan-checker H-8 (Plugin instantiation) | Revision | Task 3 + Task 4 setUp | All tests use `$this->app->singleton(AdapterRegistry::class)` direct bind |
| Plan-checker H-6 (shared fixtures) | Revision | Task 4 | Imports TestSubject + TestSubjectAdapter + ZeroIdSubjectAdapter from plan 02-01's `tests/Doubles/` — no inline class declarations |
| Plan-checker L-4 (Log facade FQN) | Revision | Task 2 | EventLogWriter imports `Illuminate\Support\Facades\Log` + App + DB FQN |

No gaps.

## Acceptance gate

`composer qa` exits 0 from `plugins/logingrupa/metapixel/` after Task 5's commit.
</verification>

<success_criteria>
Plan 02-04 ships when ALL of the following hold:

1. `classes/Helper/SiteResolver.php` is `final` + has exactly one public static method `forSubject(object, EventSubjectAdapter): ?int`; body delegates to `$obAdapter->getSiteId($obSubject)`; ZERO references to SiteManager / Site / Request / request() in source.
2. `classes/Helper/EventLogWriter.php` is `final` + uses AdapterRegistry::resolveFor to read subject_type alias; uses insertOrIgnore on `logingrupa_metapixel_event_log`; returns bool (true on race-win, false on collision OR DB failure); wraps body in try/catch + Log::critical fail-safe; imports Log/App/DB via Illuminate FQN (L-4).
3. `tests/Unit/Helper/SiteResolverTest.php` has 3 tests including static-source regex defence; uses FakeAdapter from `tests/Doubles/`; H-8 setUp pattern; all pass.
4. `tests/Feature/Adapter/EventLogWriterRaceFenceTest.php` has 6 tests covering race-fence + NULL-distinct + alias-write + missing-adapter + non-positive-id + Throwable branches; imports shared doubles (no inline classes); H-8 setUp pattern; all pass.
5. `composer qa` exits 0 from `plugins/logingrupa/metapixel/`.
6. Single commit on HEAD touches exactly 4 files; commit message references ADAP-06 + P-05.
7. No comment pollution in any new source file.
</success_criteria>

<output>
After completion, create `plugins/logingrupa/metapixel/.planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-04-SUMMARY.md` documenting:

- Single commit SHA.
- composer qa output tail.
- Test pass counts: SiteResolverTest 3/3, EventLogWriterRaceFenceTest 6/6.
- Coverage for SiteResolver.php (expected 100% — one line of body) and EventLogWriter.php (expected ≥ 95%).
- Confirm A5 risk resolved: T17's NULL-distinct test verifies SQLite UNIQUE behavior matches MySQL InnoDB.
- Phase 2 plan-state update: 02-04 closed; 02-05 (MetaClient + PayloadBuilder + UserDataHasher) running parallel in Wave 3; plan 02-06 (SendCapiEvent) now ready to start once 02-05 commits.
</output>

## Revision History
- 2026-05-17 R1: Address plan-checker findings M-3 (truths + Task 4 inline comment reframe "two SEQUENTIAL insertOrIgnore calls with identical key" — concurrent contention not exercised in SQLite test env; UNIQUE blocks the second insert regardless), H-8 (Tasks 3 + 4 setUp use `$this->app->singleton(AdapterRegistry::class)` direct bind — never `(new Plugin)->register()`), H-6 (Task 4 imports TestSubject + TestSubjectAdapter + ZeroIdSubjectAdapter from plan 02-01's `tests/Doubles/` — no inline class declarations), L-4 (Task 2 EventLogWriter imports `Illuminate\Support\Facades\Log` + App + DB FQN).
