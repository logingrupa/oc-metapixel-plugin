---
phase: 02-adapter-system-core-contracts-registry-extension-hooks
plan: 7
slug: fake-adapter-contract-test-base-smoke
type: execute
wave: 5
depends_on:
  - 02-01
  - 02-02
  - 02-05
  - 02-06
files_modified:
  - plugins/logingrupa/metapixel/classes/Testing/EventSubjectAdapterContractTestCase.php
  - plugins/logingrupa/metapixel/tests/Contract/Adapter/FakeAdapterContractTest.php
  - plugins/logingrupa/metapixel/tests/Feature/Adapter/ContractTestCaseSmokeTest.php
  - plugins/logingrupa/metapixel/tests/Feature/Adapter/BackboneIntegrationTest.php
  - plugins/logingrupa/metapixel/composer.json
  - plugins/logingrupa/metapixel/phpunit.xml
  - plugins/logingrupa/metapixel/.planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-VERIFICATION-INPUTS.md
autonomous: true
requirements:
  - ADAP-11
maps_to:
  pitfalls: []
  decisions:
    - D-08
    - D-09
    - D-10
    - D-11
    - D-12
    - D-13
must_haves:
  truths:
    - "`Logingrupa\\Metapixel\\Classes\\Testing\\EventSubjectAdapterContractTestCase` abstract class extends `Orchestra\\Testbench\\TestCase` (H-3 — Orchestra Testbench is the marketplace-idiomatic Laravel test harness; third parties `composer require logingrupa/oc-metapixel-plugin` and extend the base from their own tests/ without needing the plugin's MetapixelTestCase). 10 invariant test methods + abstract makeAdapter() + makeSubject() hooks."
    - "Abstract base ships under `classes/Testing/` — production PSR-4 namespace so third parties extend without depending on the plugin's autoload-dev test directory (D-11). Orchestra Testbench is a dev dependency for the plugin BUT also required by any consumer extending the base — third parties add it to their own require-dev."
    - "FakeAdapterContractTest extends the contract base + supplies makeAdapter() returning FakeAdapter + makeSubject() returning stdClass. All 10 invariants pass."
    - "ContractTestCaseSmokeTest registers FakeAdapter via AdapterRegistry + builds a payload via PayloadBuilder + verifies envelope shape matches the documented contract (SC1 round-trip)."
    - "BackboneIntegrationTest dispatches SendCapiEvent end-to-end through FakeAdapter + Guzzle MockHandler (200 response) + asserts EventLog row exists + after_dispatch listener received the response. Closes SC1 + SC5. Includes M-5 serialize round-trip smoke (`serialize($obJob); unserialize($sBlob)->handle(...)` works) — catches production-path SerializesModels failures the synchronous tests miss."
    - "Abstract base tearDown does `app()->forgetInstance(AdapterRegistry::class)` (M-6) to prevent invariant 09's registry registration from leaking across tests."
    - "All 9 tests in Contract/Adapter + Feature/Adapter pass (FakeAdapterContractTest 10 invariants + ContractTestCaseSmokeTest 2 + BackboneIntegrationTest 3 including M-5 serialize smoke)."
    - "Phase 2 plan acceptance flags ROADMAP.md SC5 mismatch (M-7) — wording references 4 v1.x test files that OQ-1 reframes as Phase 3 work; this plan does NOT update ROADMAP.md, just flags for orchestrator surface."
    - "`composer qa` exits 0; coverage ≥ 90% holds on the full backbone (Phase 2 production code excluding classes/Testing per phpunit.xml exclude)."
    - "Phase 2 close: 02-VERIFICATION-INPUTS.md scaffolded with the SC1–SC5 evidence checklist for the next-step gsd-verifier run."
  artifacts:
    - path: "plugins/logingrupa/metapixel/classes/Testing/EventSubjectAdapterContractTestCase.php"
      provides: "D-11 — abstract contract base shipped in production namespace; extends Orchestra\\Testbench\\TestCase (H-3) so third parties extend without depending on plugin's autoload-dev MetapixelTestCase."
      contains: "abstract class EventSubjectAdapterContractTestCase"
    - path: "plugins/logingrupa/metapixel/tests/Contract/Adapter/FakeAdapterContractTest.php"
      provides: "Smoke that the contract base passes against FakeAdapter."
      contains: "extends EventSubjectAdapterContractTestCase"
    - path: "plugins/logingrupa/metapixel/tests/Feature/Adapter/BackboneIntegrationTest.php"
      provides: "SC1 + SC5 end-to-end integration — FakeAdapter through the full Phase 2 pipeline + M-5 serialize round-trip smoke."
      contains: "SendCapiEvent"
    - path: "plugins/logingrupa/metapixel/.planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-VERIFICATION-INPUTS.md"
      provides: "SC1–SC5 evidence checklist for gsd-verifier handoff; flags M-7 ROADMAP.md SC5 mismatch for orchestrator."
      contains: "SC1"
  key_links:
    - from: "plugins/logingrupa/metapixel/tests/Contract/Adapter/FakeAdapterContractTest.php"
      to: "plugins/logingrupa/metapixel/classes/Testing/EventSubjectAdapterContractTestCase.php"
      via: "extends abstract base"
      pattern: "extends EventSubjectAdapterContractTestCase"
    - from: "plugins/logingrupa/metapixel/classes/Testing/EventSubjectAdapterContractTestCase.php"
      to: "Orchestra\\Testbench\\TestCase"
      via: "extends Orchestra Testbench (H-3)"
      pattern: "Orchestra.Testbench.TestCase"
    - from: "plugins/logingrupa/metapixel/tests/Feature/Adapter/BackboneIntegrationTest.php"
      to: "plugins/logingrupa/metapixel/classes/Queue/SendCapiEvent.php"
      via: "dispatchSync"
      pattern: "SendCapiEvent"
---

<r2_override>
**R2 OVERRIDE (2026-05-17, post plan-check):** `orchestra/testbench` dependency DROPPED. Reason: Phase 2 has exactly ONE consumer of the contract base (first-party `FakeAdapterContractTest`). Phase 3 first-party adapters use plain `MetapixelTestCase`. Third-party adapters land v2.1 earliest. Per CLAUDE.md "Build only for current need" — Testbench is over-engineering for Phase 2.

**Effective shape:**
- `EventSubjectAdapterContractTestCase` extends `Logingrupa\Metapixel\Tests\MetapixelTestCase` (Phase 1 base, no Lovata).
- Lives in `classes/Testing/` production namespace per D-11 BUT acceptable cross-namespace import for Phase 2 — first-party only.
- Task 1 (composer require orchestra/testbench) REMOVED entirely.
- At v2.1 when first real third-party adapter appears: swap base to `Orchestra\Testbench\TestCase` THEN — or instruct third parties to copy contract base file into their own tests/ via `docs/CUSTOM-ADAPTERS.md`.
- `composer-dependency-analyser` may flag the `Tests\MetapixelTestCase` import from `classes/Testing/`. Acceptable: this IS first-party. Either tolerate the flag (low signal) or add `Logingrupa\Metapixel\Tests\MetapixelTestCase` to the analyser's ignoreOnlyInPath list scoped to `classes/Testing/EventSubjectAdapterContractTestCase.php`. Decide at execute time.
- All other text below describing Testbench is HISTORICAL — supersede with the above.
</r2_override>

<objective>
Close Phase 2 by shipping the public contract test base (`EventSubjectAdapterContractTestCase` extending `Logingrupa\Metapixel\Tests\MetapixelTestCase` per R2 override above; original plan said Orchestra\Testbench\TestCase — DROPPED), the contract smoke test (`FakeAdapterContractTest` proves the base passes against FakeAdapter), the SC1 round-trip smoke (`ContractTestCaseSmokeTest`), and the SC1 + SC5 end-to-end integration test (`BackboneIntegrationTest` exercises SendCapiEvent → race-fence → MetaClient mock → after_dispatch — full Phase 2 pipeline; includes M-5 serialize round-trip smoke). Also scaffold `02-VERIFICATION-INPUTS.md` for the next-step gsd-verifier handoff.

H-3 RESOLUTION: The original plan oscillated between extending MetapixelTestCase (which lives in autoload-dev `Logingrupa\Metapixel\Tests\` namespace — third parties cannot inherit because their `composer require` of the plugin does NOT pull the plugin's own dev tree into their autoload) and suppressing the cross-namespace violation via composer-dependency-analyser's `addPathToExclude`. The plan-checker's R1 verdict: pick option (a) — extend `Orchestra\Testbench\TestCase`. Orchestra Testbench is the Lovata-marketplace-standard for OctoberCMS plugins that need to ship test scaffolds for third-party extension authors. Add `orchestra/testbench` to plugin require-dev. Third parties `composer require --dev orchestra/testbench` in their own setup. The contract base no longer depends on MetapixelTestCase or any other autoload-dev path.

Doubles (FakeAdapter + FakeValueResolver + TestSubject + TestSubjectAdapter + ZeroIdSubjectAdapter + FakeStubAdapter + SpyMetaClient) are already shipped under `tests/Doubles/` by plans 02-01 + 02-05 — this plan does NOT re-ship them. The contract test + smoke + BackboneIntegrationTest import the shared doubles.

ADAP-11 is closed: backbone tests regreen via FakeAdapter. M-7: ROADMAP.md SC5 wording references 4 v1.x test files (`OrderStatusWatcherEventLogTest`, `PurchasePixelEventLogGateTest`, `SendCapiEventEventLogTest`, `MultiSiteEventLogTest`) that OQ-1 reframes as Phase 3 work alongside ShopaholicOrderAdapter. This plan FLAGS the mismatch (in 02-VERIFICATION-INPUTS.md + acceptance criterion) — does NOT update ROADMAP.md itself (orchestrator handles surfacing).

Output: 1 contract test base (Orchestra Testbench) + 1 composer.json edit (orchestra/testbench require-dev) + 3 test files + 1 phpunit.xml edit (exclude classes/Testing from coverage) + 1 verification-inputs scaffold.
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
@plugins/logingrupa/metapixel/classes/Adapter/EventSubjectAdapter.php
@plugins/logingrupa/metapixel/classes/Adapter/ValueResolver.php
@plugins/logingrupa/metapixel/classes/Adapter/AdapterRegistry.php
@plugins/logingrupa/metapixel/classes/Helper/EventLogWriter.php
@plugins/logingrupa/metapixel/classes/Meta/MetaClient.php
@plugins/logingrupa/metapixel/classes/Meta/PayloadBuilder.php
@plugins/logingrupa/metapixel/classes/Meta/UserDataHasher.php
@plugins/logingrupa/metapixel/classes/Queue/SendCapiEvent.php
@plugins/logingrupa/metapixel/tests/Doubles/FakeAdapter.php
@plugins/logingrupa/metapixel/tests/Doubles/FakeValueResolver.php
@plugins/logingrupa/metapixel/tests/Doubles/SpyMetaClient.php
@plugins/logingrupa/metapixel/composer.json
@plugins/logingrupa/metapixel/phpunit.xml

<interfaces>
Locked decisions:

- D-08..D-10: FakeAdapter + FakeValueResolver (shipped by plan 02-01 Task 4).
- D-11: Ship `Logingrupa\Metapixel\Classes\Testing\EventSubjectAdapterContractTestCase` (production namespace, under `classes/Testing/`). H-3 lock — extends `Orchestra\Testbench\TestCase`, NOT MetapixelTestCase.
- D-12: 10 invariants enforced — see RESEARCH §6 list verbatim.
- D-13: Third parties extend this base for their custom adapter tests. Same base used internally by Phase 3 adapters.

H-3 RESOLUTION (Orchestra Testbench):

The original plan tried two failed approaches:
1. Extending `MetapixelTestCase` directly — broken because MetapixelTestCase is under autoload-dev `Logingrupa\Metapixel\Tests\` namespace which third-party consumers cannot reach via standard `composer require`.
2. Suppressing the cross-namespace import violation via composer-dependency-analyser `addPathToExclude` — defeats TOOL-11's purpose (the tool exists to enforce import boundaries; excluding the violating dir whitelists exactly what the tool was added to flag).

Plan-checker R1 verdict: extend `Orchestra\Testbench\TestCase`. Orchestra Testbench is the marketplace-standard Laravel test harness — many Lovata-ecosystem OctoberCMS plugins use it (`vendor/laravel/framework`'s own test suite, Lovata's `vendor/lovata/oc-shopaholic-plugin` uses Testbench in its docs for third-party test patterns). Orchestra Testbench provides:
- `createApplication()` default implementation (a Laravel app instance for tests).
- `setUp()` / `tearDown()` defaults compatible with PHPUnit 12.
- No dependency on the plugin's autoload-dev MetapixelTestCase.

Composer delta (this plan's Task 1):
- Plugin `composer.json` `require-dev` adds `"orchestra/testbench": "^9.0"` (matches Laravel 12 compatibility).
- Third-party consumers also add `orchestra/testbench` to THEIR `require-dev` (documented in Phase 5 docs/CUSTOM-ADAPTERS.md).
- composer-dependency-analyser remains strict — no addPathToExclude needed because the contract base only imports `Orchestra\Testbench\TestCase` + the plugin's production-namespace types (EventSubjectAdapter, AdapterRegistry, PayloadBuilder, UserDataHasher), all of which are legitimate cross-namespace imports.

EventSubjectAdapterContractTestCase shape (H-3 + M-6 + RESEARCH §4.16):

```
namespace Logingrupa\Metapixel\Classes\Testing;

use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Adapter\EventSubjectAdapter;
use Logingrupa\Metapixel\Classes\Adapter\ValueResolver;
use Logingrupa\Metapixel\Classes\Meta\PayloadBuilder;
use Logingrupa\Metapixel\Classes\Meta\UserDataHasher;
use Orchestra\Testbench\TestCase;

/**
 * Contract test base for any EventSubjectAdapter implementation. Extends
 * Orchestra\Testbench\TestCase so third-party adapter authors can extend
 * without depending on the plugin's autoload-dev MetapixelTestCase.
 *
 * Third-party usage:
 *
 *   final class AcmeCartAdapterContractTest extends EventSubjectAdapterContractTestCase
 *   {
 *       protected function makeAdapter(): EventSubjectAdapter
 *       {
 *           return new AcmeCartAdapter;
 *       }
 *       protected function makeSubject(): object
 *       {
 *           return AcmeCartFactory::create(['site_id' => 1]);
 *       }
 *   }
 *
 * Consumers must add `orchestra/testbench` to their composer require-dev:
 *
 *   composer require --dev orchestra/testbench
 *
 * `pest tests/AcmeCartAdapterContractTest.php` exits 0 → the adapter
 * satisfies the Phase 2 marketplace contract.
 */
abstract class EventSubjectAdapterContractTestCase extends TestCase
{
    abstract protected function makeAdapter(): EventSubjectAdapter;
    abstract protected function makeSubject(): object;

    /**
     * M-6: invariant 09 (registry round-trip) registers an adapter into the
     * container singleton. Without explicit forget, the registration persists
     * across tests. Reset between tests so subclasses + concrete contract
     * tests are isolated.
     */
    protected function tearDown(): void
    {
        app()->forgetInstance(AdapterRegistry::class);
        parent::tearDown();
    }

    public function test_invariant_01_subject_type_is_opaque_alias_format(): void { ... }
    public function test_invariant_02_subject_id_is_positive_int(): void { ... }
    public function test_invariant_03_site_id_deterministic_across_set_site_context(): void { ... }
    public function test_invariant_04_get_site_id_reads_no_request_or_site_manager(): void { ... }
    public function test_invariant_05_get_secret_key_returns_string_or_null_never_throws(): void { ... }
    public function test_invariant_06_get_value_resolver_returns_value_resolver_instance(): void { ... }
    public function test_invariant_07_get_user_data_returns_documented_meta_capi_keys(): void { ... }
    public function test_invariant_08_get_supported_events_returns_correct_shape(): void { ... }
    public function test_invariant_09_registry_round_trip_returns_same_adapter(): void { ... }
    public function test_invariant_10_payload_builder_produces_valid_envelope_shape(): void { ... }
}
```

The 10 invariants are unchanged from RESEARCH §6 / the original plan's body. M-6 adds the tearDown forgetInstance.

For invariant 09 (registry round-trip), the test uses Orchestra Testbench's default app instance — `app(AdapterRegistry::class)` resolves a fresh registry per test (because tearDown's forgetInstance clears the singleton + Orchestra Testbench's setUp recreates the app). Tests register adapter → resolveFor → assertInstanceOf.

For invariant 03 (site_id determinism), the test calls `$obAdapter->getSiteId($obSubject)` twice + asserts same result. No Site::setSite() context exercised (Phase 2 cannot, per RESEARCH); the deterministic delegation is enforced statically (plan 02-02 phpstan rule + plan 02-04 T6 static-source grep).

ContractTestCaseSmokeTest shape:

```
namespace Logingrupa\Metapixel\Tests\Feature\Adapter;

// extends MetapixelTestCase (not the contract base) — this test exercises
// the round-trip through PayloadBuilder, not the 10 invariants

use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Meta\PayloadBuilder;
use Logingrupa\Metapixel\Classes\Meta\UserDataHasher;
use Logingrupa\Metapixel\Tests\Doubles\FakeAdapter;
use Logingrupa\Metapixel\Tests\Doubles\FakeValueResolver;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;

final class ContractTestCaseSmokeTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);  // H-8
    }

    public function test_fake_adapter_round_trips_through_payload_builder_to_documented_envelope(): void { ... }
    public function test_fake_adapter_registry_round_trip(): void { ... }
}
```

BackboneIntegrationTest shape — adds M-5 serialize round-trip smoke:

```
final class BackboneIntegrationTest extends MetapixelTestCase
{
    protected function setUp(): void { ... }  // H-8 + migrations + FakeAdapter register + Settings::set
    protected function tearDown(): void { ... }  // drop migrations + Event::forget + forgetInstance

    public function test_happy_path_fake_adapter_through_full_backbone_returns_capi_row_and_fires_after_dispatch(): void { ... }
    public function test_dedup_second_dispatch_for_same_subject_short_circuits_no_http_call(): void { ... }

    /**
     * M-5: production-path serialize round-trip. Synchronous tests (handle()
     * direct invocation) skip the serialize/unserialize cycle that production
     * Laravel queue workers execute. This test confirms the SendCapiEvent job
     * survives serialize → unserialize → handle() — catches the SerializesModels-
     * for-stdClass-subject failure mode the sync tests miss.
     */
    public function test_serialize_round_trip_job_unserializes_and_runs_handle(): void
    {
        Settings::set(['pixel_id' => 'PIXEL-1', 'capi_access_token' => 'TOKEN-1']);

        $obMock = new MockHandler([new Response(200, [], json_encode(['events_received' => 1]))]);
        $obStack = HandlerStack::create($obMock);
        $obClient = new MetaClient(new Client(['handler' => $obStack]));

        $arPayload = $this->buildPayload('uuid-serialize-1', 1700000003);
        $obOriginalJob = new SendCapiEvent('Purchase', $arPayload, new \stdClass, FakeAdapter::class);

        $sBlob = serialize($obOriginalJob);
        $obRehydrated = unserialize($sBlob);

        $this->assertInstanceOf(SendCapiEvent::class, $obRehydrated);
        $obRehydrated->handle(app(AdapterRegistry::class), $obClient);

        $this->assertSame(1, DB::table('logingrupa_metapixel_event_log')->count(), 'EventLog written after serialize round-trip');
    }
}
```

Pest.php is NOT touched in this plan (L-6 — drop the comment block that would have documented "why Contract dir doesn't get uses() binding"; the absence of a uses() binding is self-documenting; CLAUDE.md "no comment pollution" applies).

phpunit.xml `<source><exclude>` exempts `classes/Testing/` from coverage (test-helper code, not production behaviour). Plan 02-02 added the `<source><include>` block; this plan adds the corresponding `<exclude>`.

`composer-dependency-analyser.php` is NOT touched in this plan. H-3 resolution eliminates the cross-namespace MetapixelTestCase import that the original plan tried to suppress via addPathToExclude. The contract base now only imports legitimate production-namespace types + Orchestra Testbench (which IS in require-dev as added by this plan's Task 1).

M-7 flag (ROADMAP.md SC5 mismatch): ROADMAP.md SC5 names 4 v1.x test files (`OrderStatusWatcherEventLogTest`, `PurchasePixelEventLogGateTest`, `SendCapiEventEventLogTest`, `MultiSiteEventLogTest`) that OQ-1 reframes as Phase 3 work. This plan does NOT update ROADMAP.md; the orchestrator surfaces the mismatch via the verification-inputs scaffold + plan acceptance note.
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: REMOVED — orchestra/testbench dependency dropped per R2 override</name>
  <files></files>
  <action>
Per R2 override block at top of this plan: `orchestra/testbench` is NOT added. Phase 2 contract base extends `Logingrupa\Metapixel\Tests\MetapixelTestCase` (Phase 1, no Lovata). YAGNI: zero v2.0 third-party adapter consumers. Revisit at v2.1 when first real third party authors an adapter — either swap to Testbench then OR ship contract base as a copy-this-file pattern via `docs/CUSTOM-ADAPTERS.md`.

Skip this task. Renumber subsequent tasks mentally (Task 2 → Task 1 effective, etc.) — executor MAY keep the labels for traceability with R1 plan-check report.
  </action>
  <verify>
    <automated>! grep -q 'orchestra/testbench' plugins/logingrupa/metapixel/composer.json</automated>
  </verify>
  <done>plugin composer.json contains NO orchestra/testbench entry; contract base in Task 2 extends MetapixelTestCase per R2 override.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Write EventSubjectAdapterContractTestCase abstract base (R2 — extends MetapixelTestCase, NOT Testbench + M-6 tearDown)</name>
  <files>
    plugins/logingrupa/metapixel/classes/Testing/EventSubjectAdapterContractTestCase.php
  </files>
  <behavior>
    - Namespace `Logingrupa\Metapixel\Classes\Testing` (PRODUCTION namespace under classes/Testing — D-11 ships in production PSR-4 root).
    - **H-3 lock: extends `Orchestra\Testbench\TestCase`** (NOT MetapixelTestCase). Orchestra Testbench provides createApplication() + Laravel test harness; third parties extend the contract base from their own tests/ without depending on the plugin's autoload-dev MetapixelTestCase.
    - 2 abstract protected methods: `makeAdapter(): EventSubjectAdapter` + `makeSubject(): object`.
    - **M-6 tearDown** override: `protected function tearDown(): void { app()->forgetInstance(AdapterRegistry::class); parent::tearDown(); }` — prevents invariant 09's registry registration from leaking across tests.
    - 10 public `test_invariant_NN_*` methods exactly as listed in RESEARCH §6 (each enforces one invariant from D-12).
    - Class-level PHPDoc documents the third-party usage pattern with a working code example + the `composer require --dev orchestra/testbench` instruction.
    - File ≤ 170 LOC (10 invariants ≤ 15 LOC each + class boilerplate).
    - php -l clean.
  </behavior>
  <action>
Create `classes/Testing/EventSubjectAdapterContractTestCase.php` per the shape in `<interfaces>`. This is a PRODUCTION class file (under `classes/Testing/`, in the `Logingrupa\Metapixel\Classes\Testing\` namespace) — it ships with the plugin so marketplace operators can `composer require logingrupa/oc-metapixel-plugin` (or `--dev`) and write their adapter tests by extending the base.

The 10 invariants from RESEARCH §6 are unchanged. The two key R1 deltas:

1. **H-3**: `extends Orchestra\Testbench\TestCase` — NOT `extends MetapixelTestCase`. The class-level PHPDoc documents that consumers must add `orchestra/testbench` to their `require-dev`.
2. **M-6**: tearDown override calls `app()->forgetInstance(AdapterRegistry::class); parent::tearDown();` — explicit per-test forget after invariant 09's registry registration.

Composer-dependency-analyser remains strict — no addPathToExclude needed. The contract base imports:
- `Orchestra\Testbench\TestCase` — orchestra/testbench is in require-dev (Task 1).
- `Logingrupa\Metapixel\Classes\Adapter\*` — same-plugin production namespace.
- `Logingrupa\Metapixel\Classes\Meta\*` — same-plugin production namespace.

All imports are legitimate cross-package cross-namespace imports the analyser tracks normally.

Invariant test bodies — keep verbatim from RESEARCH §6:

- 01: getSubjectType returns non-empty string, no backslashes, ≤ 64 chars.
- 02: getSubjectId > 0.
- 03: getSiteId returns same value on two successive calls (determinism).
- 04: getSiteId returns null or int (no exceptions, no Request side effects — phpstan-enforced in adapter dirs, runtime-asserted here).
- 05: getSecretKey returns string or null, never throws.
- 06: getValueResolver returns a ValueResolver instance.
- 07: getUserData keys are subset of 13-key Meta CAPI allowed set; values are string|null.
- 08: getSupportedEvents shape — keys are strings (event names), values are arrays of {capi, pixel} channels.
- 09: register adapter via AdapterRegistry → resolveFor(subject) returns same FQN. tearDown forgets the registry (M-6).
- 10: PayloadBuilder + UserDataHasher produce envelope with 6 keys + correct event_name/event_id/event_time/action_source.

NO comment markers (`// H-3`, `// M-6`, `// CR-XX`, `// P-XX`). The class PHPDoc explains the Orchestra Testbench choice in prose.
  </action>
  <verify>
    <automated>test -f plugins/logingrupa/metapixel/classes/Testing/EventSubjectAdapterContractTestCase.php &amp;&amp; php -l plugins/logingrupa/metapixel/classes/Testing/EventSubjectAdapterContractTestCase.php | grep -q 'No syntax errors' &amp;&amp; grep -q 'abstract class EventSubjectAdapterContractTestCase' plugins/logingrupa/metapixel/classes/Testing/EventSubjectAdapterContractTestCase.php &amp;&amp; grep -q 'extends MetapixelTestCase' plugins/logingrupa/metapixel/classes/Testing/EventSubjectAdapterContractTestCase.php &amp;&amp; ! grep -q 'Orchestra' plugins/logingrupa/metapixel/classes/Testing/EventSubjectAdapterContractTestCase.php &amp;&amp; grep -q 'abstract protected function makeAdapter' plugins/logingrupa/metapixel/classes/Testing/EventSubjectAdapterContractTestCase.php &amp;&amp; grep -q 'abstract protected function makeSubject' plugins/logingrupa/metapixel/classes/Testing/EventSubjectAdapterContractTestCase.php &amp;&amp; grep -q 'forgetInstance(AdapterRegistry::class)' plugins/logingrupa/metapixel/classes/Testing/EventSubjectAdapterContractTestCase.php &amp;&amp; grep -c 'public function test_invariant_' plugins/logingrupa/metapixel/classes/Testing/EventSubjectAdapterContractTestCase.php | grep -Eq '^10$'</automated>
  </verify>
  <done>EventSubjectAdapterContractTestCase abstract class with 10 invariants + 2 abstract methods + M-6 tearDown + extends Logingrupa\Metapixel\Tests\MetapixelTestCase (R2 override — Testbench DROPPED).</done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: Write FakeAdapterContractTest + ContractTestCaseSmokeTest (L-6 drop Pest.php comment)</name>
  <files>
    plugins/logingrupa/metapixel/tests/Contract/Adapter/FakeAdapterContractTest.php
    plugins/logingrupa/metapixel/tests/Feature/Adapter/ContractTestCaseSmokeTest.php
  </files>
  <behavior>
    - FakeAdapterContractTest extends EventSubjectAdapterContractTestCase + supplies makeAdapter() returning FakeAdapter + makeSubject() returning stdClass. Inherits all 10 invariant tests — none fail.
    - ContractTestCaseSmokeTest extends MetapixelTestCase (NOT the contract base — this test exercises PayloadBuilder round-trip, not the 10 invariants). 2 tests assert SC1 envelope shape + registry round-trip. Uses H-8 setUp pattern.
    - **L-6: Pest.php is NOT touched in this plan** (CLAUDE.md "no comment pollution" — the absence of a uses() binding for the Contract directory is self-documenting; no comment-block needed).
    - All 12 tests (10 invariants + 2 smoke) pass.
  </behavior>
  <action>
Create `tests/Contract/Adapter/FakeAdapterContractTest.php`:

```
<?php

namespace Logingrupa\Metapixel\Tests\Contract\Adapter;

use Logingrupa\Metapixel\Classes\Adapter\EventSubjectAdapter;
use Logingrupa\Metapixel\Classes\Testing\EventSubjectAdapterContractTestCase;
use Logingrupa\Metapixel\Tests\Doubles\FakeAdapter;

final class FakeAdapterContractTest extends EventSubjectAdapterContractTestCase
{
    protected function makeAdapter(): EventSubjectAdapter
    {
        return new FakeAdapter;
    }

    protected function makeSubject(): object
    {
        return new \stdClass;
    }
}
```

That's the entire file. FakeAdapter (from `tests/Doubles/`) returns 'fake.subject' (opaque alias, no backslash, < 64 chars → invariant 01 passes), subject_id=1 (positive → 02), getSiteId returns null deterministically (03 + 04), getSecretKey returns null (05), getValueResolver returns FakeValueResolver (06), getUserData returns 13-key array of nulls (07), getSupportedEvents returns `['Purchase' => ['capi', 'pixel']]` (08). Invariant 09 registers FakeAdapter via AdapterRegistry; invariant 10 runs PayloadBuilder against FakeAdapter.

Orchestra Testbench provides createApplication() — concrete test does NOT override. M-6 tearDown in the base handles registry cleanup automatically.

Create `tests/Feature/Adapter/ContractTestCaseSmokeTest.php`:

```
<?php

namespace Logingrupa\Metapixel\Tests\Feature\Adapter;

use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Meta\PayloadBuilder;
use Logingrupa\Metapixel\Classes\Meta\UserDataHasher;
use Logingrupa\Metapixel\Tests\Doubles\FakeAdapter;
use Logingrupa\Metapixel\Tests\Doubles\FakeValueResolver;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;

final class ContractTestCaseSmokeTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);  // H-8
    }

    public function test_fake_adapter_round_trips_through_payload_builder_to_documented_envelope(): void
    {
        $obAdapter = (new FakeAdapter)
            ->withSubjectType('shopaholic.order')
            ->withSubjectId(42)
            ->withUserData(['em' => 'test@example.test'])
            ->withSiteId(1);

        $obResolver = new FakeValueResolver(
            arContentIds: ['SKU-42'],
            fValue: 99.99,
            sCurrency: 'EUR',
            arContents: [['id' => 'SKU-42', 'quantity' => 1, 'item_price' => 99.99]],
            iNumItems: 1,
        );

        $obBuilder = new PayloadBuilder(new UserDataHasher);
        $arEnvelope = $obBuilder->buildEventPayload('Purchase', $obAdapter, new \stdClass, $obResolver, 'uuid-1', 1700000000, []);

        $this->assertSame('uuid-1', $arEnvelope['data'][0]['event_id']);
        $this->assertSame(1700000000, $arEnvelope['data'][0]['event_time']);
        $this->assertSame('Purchase', $arEnvelope['data'][0]['event_name']);
        $this->assertSame('website', $arEnvelope['data'][0]['action_source']);
        $this->assertSame(hash('sha256', 'test@example.test'), $arEnvelope['data'][0]['user_data']['em']);
        $this->assertSame('EUR', $arEnvelope['data'][0]['custom_data']['currency']);
        $this->assertSame(99.99, $arEnvelope['data'][0]['custom_data']['value']);
        $this->assertSame(['SKU-42'], $arEnvelope['data'][0]['custom_data']['content_ids']);
        $this->assertSame('product', $arEnvelope['data'][0]['custom_data']['content_type']);
    }

    public function test_fake_adapter_registry_round_trip(): void
    {
        $obRegistry = app(AdapterRegistry::class);
        $obRegistry->register(\stdClass::class, FakeAdapter::class);

        $obResolved = $obRegistry->resolveFor(new \stdClass);
        $this->assertInstanceOf(FakeAdapter::class, $obResolved);
    }
}
```

Uses FakeAdapter + FakeValueResolver from `tests/Doubles/` (plan 02-01). H-8 setUp pattern (singleton bind direct). No inline class declarations.

L-6: NO Pest.php edit in this plan. The original plan included a comment block "tests/Contract/Adapter/ files extend EventSubjectAdapterContractTestCase directly..." — drop per CLAUDE.md "no comment pollution". The absence of a uses() binding for Contract/Adapter is self-documenting (phpunit.xml routes the dir + subclasses are explicit `extends`).
  </action>
  <verify>
    <automated>test -f plugins/logingrupa/metapixel/tests/Contract/Adapter/FakeAdapterContractTest.php &amp;&amp; test -f plugins/logingrupa/metapixel/tests/Feature/Adapter/ContractTestCaseSmokeTest.php &amp;&amp; php -l plugins/logingrupa/metapixel/tests/Contract/Adapter/FakeAdapterContractTest.php | grep -q 'No syntax errors' &amp;&amp; php -l plugins/logingrupa/metapixel/tests/Feature/Adapter/ContractTestCaseSmokeTest.php | grep -q 'No syntax errors' &amp;&amp; grep -q 'extends EventSubjectAdapterContractTestCase' plugins/logingrupa/metapixel/tests/Contract/Adapter/FakeAdapterContractTest.php &amp;&amp; ! grep -E '\(new\s+\\?Logingrupa\\\\Metapixel\\\\Plugin\)' plugins/logingrupa/metapixel/tests/Feature/Adapter/ContractTestCaseSmokeTest.php &amp;&amp; cd plugins/logingrupa/metapixel &amp;&amp; ../../../vendor/bin/pest tests/Contract/Adapter tests/Feature/Adapter/ContractTestCaseSmokeTest.php --configuration phpunit.xml 2&gt;&amp;1 | tail -10 | grep -Eq '(PASS|OK|Tests:.*passed)'</automated>
  </verify>
  <done>FakeAdapterContractTest passes 10 invariants (via inherited base + Orchestra Testbench harness); ContractTestCaseSmokeTest passes 2 smoke + uses H-8 setUp; Pest.php NOT modified (L-6).</done>
</task>

<task type="auto" tdd="true">
  <name>Task 4: Write BackboneIntegrationTest — SC1 + SC5 end-to-end + M-5 serialize round-trip</name>
  <files>
    plugins/logingrupa/metapixel/tests/Feature/Adapter/BackboneIntegrationTest.php
  </files>
  <behavior>
    - Final class `BackboneIntegrationTest extends MetapixelTestCase`.
    - setUp: H-8 singleton bind + run both migrations (event_log + failed_events) + register FakeAdapter for stdClass + Settings::set with non-empty creds.
    - tearDown: drop migrations + Event::forget hooks + forgetInstance.
    - Test 1 happy-path: MetaClient mock returns 200 → assert EventLog row exists (channel=capi, event_id matches) + FailedEvent table empty + after_dispatch listener received ['events_received' => 1, 'fbtrace_id' => 'trace-1'].
    - Test 2 dedup: dispatch the SAME event twice → first writes EventLog row, second is no-op (race-fence collision). Use Middleware::history to track actual MetaClient call count (H-7 downgraded — assert via history array count = 1, NOT MockHandler count).
    - **Test 3 M-5 serialize round-trip**: `serialize($obJob)` + `unserialize($sBlob)` produces a working SendCapiEvent instance; `->handle()` on the unserialized form writes EventLog (proves production-path serialize/unserialize cycle works for stdClass subject).
    - All 3 tests pass.
  </behavior>
  <action>
Create `tests/Feature/Adapter/BackboneIntegrationTest.php`:

```
<?php

namespace Logingrupa\Metapixel\Tests\Feature\Adapter;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Meta\MetaClient;
use Logingrupa\Metapixel\Classes\Meta\PayloadBuilder;
use Logingrupa\Metapixel\Classes\Meta\UserDataHasher;
use Logingrupa\Metapixel\Classes\Queue\SendCapiEvent;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\Doubles\FakeAdapter;
use Logingrupa\Metapixel\Tests\Doubles\FakeValueResolver;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Logingrupa\Metapixel\Updates\CreateMetapixelEventLogTable;
use Logingrupa\Metapixel\Updates\CreateMetapixelFailedEventsTable;

final class BackboneIntegrationTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);  // H-8
        (new CreateMetapixelEventLogTable)->up();
        (new CreateMetapixelFailedEventsTable)->up();
        app(AdapterRegistry::class)->register(\stdClass::class, FakeAdapter::class);
        Settings::set(['pixel_id' => 'PIXEL-1', 'capi_access_token' => 'TOKEN-1']);
    }

    protected function tearDown(): void
    {
        (new CreateMetapixelEventLogTable)->down();
        (new CreateMetapixelFailedEventsTable)->down();
        Event::forget(SendCapiEvent::HOOK_BEFORE_DISPATCH);
        Event::forget(SendCapiEvent::HOOK_AFTER_DISPATCH);
        Event::forget(SendCapiEvent::HOOK_DEAD_LETTER);
        app()->forgetInstance(AdapterRegistry::class);
        parent::tearDown();
    }

    public function test_happy_path_fake_adapter_through_full_backbone_returns_capi_row_and_fires_after_dispatch(): void
    {
        $arResponses = [];
        Event::listen(SendCapiEvent::HOOK_AFTER_DISPATCH,
            function (string $sName, array $arPayload, object $obSubject, array $arResponse) use (&$arResponses): void {
                $arResponses[] = $arResponse;
            });

        $obMock = new MockHandler([new Response(200, [], json_encode([
            'events_received' => 1,
            'fbtrace_id' => 'trace-1',
        ]))]);
        $obStack = HandlerStack::create($obMock);
        $obClient = new MetaClient(new Client(['handler' => $obStack]));

        $arPayload = $this->buildPayload('uuid-backbone-1', 1700000001);
        $obJob = new SendCapiEvent('Purchase', $arPayload, new \stdClass, FakeAdapter::class);
        $obJob->handle(app(AdapterRegistry::class), $obClient);

        $this->assertSame(1, DB::table('logingrupa_metapixel_event_log')->count(), 'EventLog has 1 row');
        $obRow = DB::table('logingrupa_metapixel_event_log')->first();
        $this->assertSame('capi', $obRow->channel);
        $this->assertSame('Purchase', $obRow->event_name);
        $this->assertSame('fake.subject', $obRow->subject_type);
        $this->assertSame('uuid-backbone-1', $obRow->event_id);
        $this->assertSame(0, DB::table('logingrupa_metapixel_failed_events')->count(), 'no FailedEvent');
        $this->assertCount(1, $arResponses, 'after_dispatch listener fired once');
        $this->assertSame(1, $arResponses[0]['events_received']);
        $this->assertSame('trace-1', $arResponses[0]['fbtrace_id']);
    }

    public function test_dedup_second_dispatch_for_same_subject_short_circuits_no_http_call(): void
    {
        $arHistory = [];
        $obMock = new MockHandler([new Response(200, [], '{}'), new Response(200, [], '{}')]);
        $obStack = HandlerStack::create($obMock);
        $obStack->push(Middleware::history($arHistory));
        $obClient = new MetaClient(new Client(['handler' => $obStack]));

        $arPayload = $this->buildPayload('uuid-dedup-1', 1700000002);

        $obFirstJob = new SendCapiEvent('Purchase', $arPayload, new \stdClass, FakeAdapter::class);
        $obFirstJob->handle(app(AdapterRegistry::class), $obClient);

        $obSecondJob = new SendCapiEvent('Purchase', $arPayload, new \stdClass, FakeAdapter::class);
        $obSecondJob->handle(app(AdapterRegistry::class), $obClient);

        $this->assertSame(1, DB::table('logingrupa_metapixel_event_log')->count(), 'EventLog has 1 row (second dispatch deduped)');
        $this->assertCount(1, $arHistory, 'MetaClient called exactly once — race-fence short-circuit (history middleware accurate count, NOT MockHandler internal queue)');
    }

    /**
     * M-5: Production Laravel queue workers serialize jobs to the queue
     * backend (Redis/DB) and unserialize them on the worker side. Synchronous
     * tests skip this cycle. This smoke confirms SerializesModels handles
     * the stdClass subject + readonly properties correctly + handle() works
     * on the unserialized form.
     */
    public function test_serialize_round_trip_job_unserializes_and_runs_handle(): void
    {
        $obMock = new MockHandler([new Response(200, [], json_encode(['events_received' => 1]))]);
        $obStack = HandlerStack::create($obMock);
        $obClient = new MetaClient(new Client(['handler' => $obStack]));

        $arPayload = $this->buildPayload('uuid-serialize-1', 1700000003);
        $obOriginalJob = new SendCapiEvent('Purchase', $arPayload, new \stdClass, FakeAdapter::class);

        $sBlob = serialize($obOriginalJob);
        $obRehydrated = unserialize($sBlob);

        $this->assertInstanceOf(SendCapiEvent::class, $obRehydrated);
        $obRehydrated->handle(app(AdapterRegistry::class), $obClient);

        $this->assertSame(1, DB::table('logingrupa_metapixel_event_log')->count(),
            'EventLog row written after serialize/unserialize round-trip');
    }

    /** @return array<string, mixed> */
    private function buildPayload(string $sEventId, int $iEventTime): array
    {
        $obBuilder = new PayloadBuilder(new UserDataHasher);
        return $obBuilder->buildEventPayload('Purchase', new FakeAdapter, new \stdClass, new FakeValueResolver, $sEventId, $iEventTime, []);
    }
}
```

This single test file closes:
- SC1: round-trip through FakeAdapter produces v1.x-shape envelope (verified via the row + the after_dispatch payload assertion).
- SC5: backbone tests regreen via FakeAdapter (re-framed per OQ-1 — M-7 mismatch flagged in 02-VERIFICATION-INPUTS.md).
- ADAP-11: backbone tests adapt via FakeAdapter test double.
- M-5: production-path serialize round-trip smoke catches SerializesModels-for-stdClass-subject failure mode.
- H-7 downgrade: dedup test uses `Middleware::history($arHistory)` for accurate call-count, NOT MockHandler internal queue count.

H-6 imports: FakeAdapter + FakeValueResolver from `tests/Doubles/`. No inline classes. H-8 setUp pattern. L-8 classic Pest style.
  </action>
  <verify>
    <automated>test -f plugins/logingrupa/metapixel/tests/Feature/Adapter/BackboneIntegrationTest.php &amp;&amp; php -l plugins/logingrupa/metapixel/tests/Feature/Adapter/BackboneIntegrationTest.php | grep -q 'No syntax errors' &amp;&amp; grep -q 'test_serialize_round_trip_job_unserializes_and_runs_handle' plugins/logingrupa/metapixel/tests/Feature/Adapter/BackboneIntegrationTest.php &amp;&amp; grep -q 'Middleware::history' plugins/logingrupa/metapixel/tests/Feature/Adapter/BackboneIntegrationTest.php &amp;&amp; ! grep -E '\(new\s+\\?Logingrupa\\\\Metapixel\\\\Plugin\)' plugins/logingrupa/metapixel/tests/Feature/Adapter/BackboneIntegrationTest.php &amp;&amp; cd plugins/logingrupa/metapixel &amp;&amp; ../../../vendor/bin/pest tests/Feature/Adapter/BackboneIntegrationTest.php --configuration phpunit.xml 2&gt;&amp;1 | tail -10 | grep -Eq '(PASS|OK|3 passed|Tests:.*passed)'</automated>
  </verify>
  <done>BackboneIntegrationTest passes 3 tests (happy-path + dedup using Middleware::history + M-5 serialize round-trip); H-6 + H-8 + H-7 downgrade patterns applied.</done>
</task>

<task type="auto">
  <name>Task 5: phpunit.xml exclude classes/Testing + scaffold 02-VERIFICATION-INPUTS.md (M-7 flag) + composer qa + commit</name>
  <files>
    plugins/logingrupa/metapixel/phpunit.xml
    plugins/logingrupa/metapixel/.planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-VERIFICATION-INPUTS.md
    plugins/logingrupa/metapixel/composer.json
    plugins/logingrupa/metapixel/classes/Testing/
    plugins/logingrupa/metapixel/tests/Contract/
    plugins/logingrupa/metapixel/tests/Feature/Adapter/
  </files>
  <action>
Edit `phpunit.xml` — find the `<source><include>` block (added by plan 02-02 Task 3). Add a sibling `<exclude>` block:

```xml
<source>
    <include>
        <file>./Plugin.php</file>
        <directory>./classes</directory>
        <directory>./models</directory>
    </include>
    <exclude>
        <directory>./classes/Testing</directory>
    </exclude>
</source>
```

Rationale: `classes/Testing/` ships test-helper code (EventSubjectAdapterContractTestCase). Its 10 invariant methods ARE executed via FakeAdapterContractTest inheritance, but their coverage shape is "test code, not production behaviour" — excluding from coverage avoids diluting the gate. Standard pattern for shipped test-helper code.

Scaffold `02-VERIFICATION-INPUTS.md` per the shape in the original plan, with two additions:

1. **M-7 flag section** at the top:

```markdown
## ROADMAP.md SC5 mismatch (M-7 — orchestrator action)

ROADMAP.md Phase 2 SC5 currently reads:

> "All 177 v1.x tests regreen via a FakeAdapter test double standing in for ShopaholicOrderAdapter. OrderStatusWatcherEventLogTest, PurchasePixelEventLogGateTest, SendCapiEventEventLogTest, MultiSiteEventLogTest pass without touching real Lovata Order code."

OQ-1 RESOLUTION reframes this: 177 is the wrong target; fresh-rewrite landed ~110 backbone-only Pest 4 tests across Phase 2 plans 02-01..02-07. The 4 named test files (OrderStatusWatcherEventLogTest etc.) move to Phase 3 alongside ShopaholicOrderAdapter (SHOP-03).

**Orchestrator action**: Update ROADMAP.md SC5 wording to reflect OQ-1 before Phase 2 closure. This plan does NOT update ROADMAP.md (per M-7 — flag only).
```

2. The standard SC1–SC5 evidence checklist (verbatim from the original plan's `<output>` scaffold).

Then commit (from plugin dir):

```
composer qa 2>&1 | tee /tmp/02-07-qa.log | tail -30
```

Likely phpstan issues:
- `Orchestra\Testbench\TestCase` resolution — orchestra/testbench is in require-dev (Task 1); larastan should resolve.
- Pest 4 abstract test class — phpstan may flag uninvoked abstract methods. Should resolve via the concrete FakeAdapterContractTest subclass exercising all 10 invariant methods.

Coverage: classes/Testing/* excluded from `<source>` (Task 5 phpunit.xml edit) → no dilution.

Commit:

```
git add plugins/logingrupa/metapixel/classes/Testing/ \
        plugins/logingrupa/metapixel/tests/Contract/ \
        plugins/logingrupa/metapixel/tests/Feature/Adapter/ \
        plugins/logingrupa/metapixel/composer.json \
        plugins/logingrupa/metapixel/composer.lock \
        plugins/logingrupa/metapixel/phpunit.xml \
        plugins/logingrupa/metapixel/.planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-VERIFICATION-INPUTS.md

git commit -m "$(cat <<'EOF'
feat(metapixel): contract test base + FakeAdapter smoke + backbone integration (ADAP-11) — Phase 2 close

EventSubjectAdapterContractTestCase abstract base under classes/Testing/
(production namespace) extends Orchestra\Testbench\TestCase (H-3 — Lovata-
marketplace-idiomatic Laravel test harness; third parties consume the
plugin AS A LIBRARY without needing the plugin's autoload-dev
MetapixelTestCase). orchestra/testbench ^9.0 added to require-dev.

10 invariants enforce P-01 alias + cross-context determinism +
getUserData allowed keys + getSupportedEvents shape + registry round-trip
+ PayloadBuilder envelope shape. tearDown forgets the AdapterRegistry
singleton between tests (M-6 — prevents invariant 09's registration
from leaking).

FakeAdapterContractTest extends the base and proves all 10 invariants pass
against FakeAdapter (D-11..D-13 marketplace contract). ContractTestCaseSmoke
Test runs the SC1 round-trip: PayloadBuilder + FakeAdapter produce the
documented envelope. BackboneIntegrationTest runs the end-to-end:
SendCapiEvent::handle through FakeAdapter → AdapterRegistry::resolveByClass
→ EventLogWriter race-fence → MetaClient mock 200 → after_dispatch
listener received the response. Dedup test uses Middleware::history
for accurate call-count (H-7 downgrade). Third test runs M-5 serialize
round-trip smoke — proves SerializesModels handles stdClass subject
correctly in the production queue worker path.

phpunit.xml <source><exclude> exempts classes/Testing from coverage
(test-helper code, not production behaviour). Pest.php NOT modified
(L-6 — no comment-block clutter).

02-VERIFICATION-INPUTS.md scaffolded for the next-step
/gsd:verify-phase run — SC1..SC5 evidence checklist + M-7 ROADMAP.md
SC5 mismatch flagged for orchestrator + pitfall closure table +
out-of-scope notes.

Phase 2 close: 8 plans shipped (02-01 + 02-02 + 02-03a + 02-03b +
02-04 + 02-05 + 02-06 + 02-07). 11 ADAP-* + P-01 P-02 P-05 P-08 P-13
covered. Plan 02-07 final commit.
EOF
)"
```

If the qa run fails after the commit, amend (do NOT create a new commit — keep Phase 2 close clean).
  </action>
  <verify>
    <automated>test -f plugins/logingrupa/metapixel/.planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-VERIFICATION-INPUTS.md &amp;&amp; grep -q 'SC1' plugins/logingrupa/metapixel/.planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-VERIFICATION-INPUTS.md &amp;&amp; grep -q 'SC5' plugins/logingrupa/metapixel/.planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-VERIFICATION-INPUTS.md &amp;&amp; grep -q 'M-7' plugins/logingrupa/metapixel/.planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-VERIFICATION-INPUTS.md &amp;&amp; grep -q '<exclude>' plugins/logingrupa/metapixel/phpunit.xml &amp;&amp; grep -q '<directory>./classes/Testing</directory>' plugins/logingrupa/metapixel/phpunit.xml &amp;&amp; cd plugins/logingrupa/metapixel &amp;&amp; composer qa 2&gt;&amp;1 | tail -5 | grep -Eq '(OK|PASS|0 errors|tests passed|No issues found)' &amp;&amp; git log -1 --pretty=format:'%s' | grep -q 'ADAP-11' &amp;&amp; git diff-tree --no-commit-id --name-only -r HEAD | grep -c '^plugins/logingrupa/metapixel/' | xargs test 8 -le</automated>
  </verify>
  <done>02-VERIFICATION-INPUTS.md scaffolded with SC1..SC5 + M-7 ROADMAP flag; phpunit.xml excludes classes/Testing from coverage; composer qa exits 0; commit on HEAD includes the verification scaffold + all Plan 02-07 artifacts; commit message references ADAP-11 + Phase 2 close.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Production classes/Testing/ namespace → third-party adapter test code | The EventSubjectAdapterContractTestCase ships in production PSR-4. Third parties consuming it import from a `composer require` of the plugin + add `orchestra/testbench` to their own require-dev (H-3). The contract is stable API — a Phase 2.1+ breaking change to invariants requires major-version bump. |
| Doubles under tests/Doubles/ → autoload-dev | All 7 doubles (shipped by plans 02-01 + 02-05) live under tests/Doubles/ — autoload-dev only. Production `composer install --no-dev` never loads them. The contract base does NOT import from tests/Doubles/ — it relies on subclasses' makeAdapter() + makeSubject() supplying instances. |
| Serialize/unserialize round-trip | M-5 smoke confirms SerializesModels handles stdClass subjects. Production Lovata Order objects are Eloquent — SerializesModels handles them via the framework's well-tested path. Plain DTO subjects (theme actions, third-party carts) go through PHP serialize natively. |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-02-07-01 | Tampering | Third party ships an adapter that breaks the EventSubjectAdapter contract | mitigate | EventSubjectAdapterContractTestCase's 10 invariants run in the third party's CI — fail-fast feedback before they merge their adapter. Orchestra Testbench provides the test harness. |
| T-02-07-02 | Spoofing | A malicious test double pretends to be a production adapter | accept | tests/Doubles/* lives under autoload-dev — production composer install --no-dev cannot load them. The contract base in classes/Testing/ is abstract — cannot be instantiated. |
| T-02-07-03 | Repudiation | Operator wonders why Phase 2 closure was approved despite test gap | mitigate | 02-VERIFICATION-INPUTS.md is the structured evidence trail. /gsd:verify-phase runs against it; gsd-verifier produces 02-VERIFICATION.md with each SC checkbox status. M-7 ROADMAP.md SC5 mismatch flagged for orchestrator action. |
| T-02-07-04 | Information Disclosure | EventSubjectAdapterContractTestCase exposes internal plugin types (PayloadBuilder, UserDataHasher) | accept | These ARE the marketplace-stable types. Phase 5 README + docs/CUSTOM-ADAPTERS.md document the third-party hookpoints. The contract base is the canonical extension surface. |
| T-02-07-05 | Denial of Service | A third-party adapter's makeSubject() in their ContractTest takes 10 seconds to construct | accept | Test slowness is the third party's CI problem. Plugin's CI matrix runs 10 invariants × 1-second test budget = 10 seconds per adapter. Acceptable. |
| T-02-07-06 | Elevation of Privilege | A third party invokes the contract base from production code (not test) to bypass plugin restrictions | accept | The class is abstract; cannot be instantiated. Calling its test methods from production would require `extends` + Orchestra Testbench harness — a substantial intent-to-misuse signal. |

</threat_model>

<verification>
## Goal-Backward Reachability Audit

1. "Contract base extends Orchestra\Testbench\TestCase (H-3)" — Tasks 1 + 2.
2. "Contract base has 10 invariants + M-6 tearDown" — Task 2.
3. "FakeAdapterContractTest passes all 10 invariants" — Task 3.
4. "ContractTestCaseSmokeTest closes SC1 round-trip" — Task 3.
5. "BackboneIntegrationTest closes SC1 + SC5 end-to-end + M-5 serialize round-trip" — Task 4.
6. "phpunit.xml excludes classes/Testing from coverage" — Task 5.
7. "02-VERIFICATION-INPUTS.md scaffolded with M-7 flag" — Task 5.
8. "ADAP-11 closed" — all of the above.
9. "Phase 2 closure handoff complete" — Task 5.
10. "composer qa exits 0 on the final commit" — Task 5.

No must-have is UNREACHABLE.

## Multi-Source Coverage Audit

| Source item | Type | Coverage | Notes |
|-------------|------|----------|-------|
| ROADMAP Phase 2 SC1 (FakeAdapter round-trip through PayloadBuilder produces v1.x envelope shape) | Goal SC1 | Tasks 3, 4 | ContractTestCaseSmokeTest + BackboneIntegrationTest happy-path |
| ROADMAP Phase 2 SC5 (177 v1.x tests regreen via FakeAdapter) | Goal SC5 | Task 4 | OQ-1 reframed: fresh ~110 tests + FakeAdapter at the integration layer; M-7 flag in 02-VERIFICATION-INPUTS.md for orchestrator to update ROADMAP wording |
| REQ ADAP-11 (backbone tests adapt via FakeAdapter) | Requirement | Tasks 3, 4 | FakeAdapter (from plan 02-01) + FakeAdapterContractTest passes + BackboneIntegrationTest end-to-end |
| CONTEXT D-08 (FakeAdapter fluent setters) | Decision | Plan 02-01 Task 4 | Imported here by FQN |
| CONTEXT D-09 (FakeAdapter outside production PSR-4 root) | Decision | Plan 02-01 Task 4 | tests/Doubles/ autoload-dev only |
| CONTEXT D-10 (FakeValueResolver constructor-injected) | Decision | Plan 02-01 Task 4 | Imported here by FQN |
| CONTEXT D-11 (ContractTestCase in classes/Testing/ production namespace) | Decision | Task 2 | classes/Testing/EventSubjectAdapterContractTestCase.php, extends Orchestra\Testbench\TestCase per H-3 |
| CONTEXT D-12 (10 invariants) | Decision | Task 2 | All 10 invariant methods present |
| CONTEXT D-13 (third parties + first parties use same base) | Decision | Task 2 PHPDoc + Task 3 FakeAdapterContractTest demonstrates | First-party (FakeAdapter) extends the same base Phase 3 ShopaholicOrderAdapterContractTest will extend |
| RESEARCH §4.16 ContractTestCase shape | Reference | Task 2 | Adapted for H-3 — extends Orchestra\Testbench\TestCase instead of MetapixelTestCase |
| RESEARCH §6 FakeAdapter + FakeValueResolver shape | Reference | Plan 02-01 Task 4 | Imported here |
| RESEARCH §6 ContractTestCase 10 invariants | Reference | Task 2 | All 10 present + M-6 tearDown |
| RESEARCH §9 A2 (CCache memo) | Risk | not relevant — plan 02-05 owned + M-4 deferred memo | UserDataHasher stateless |
| RESEARCH §9 A4 (Pest 4 `pest()->extend()` vs `uses()` for ContractTestCase) | Risk | Task 3 — explicit `extends` (no Pest.php uses() binding; L-6 no comment-block) | RESOLVED |
| Plan-checker H-3 (Orchestra Testbench) | Revision | Tasks 1 + 2 | Adopts orchestra/testbench require-dev + EventSubjectAdapterContractTestCase extends Orchestra\Testbench\TestCase — eliminates the MetapixelTestCase cross-namespace import + no addPathToExclude in composer-dependency-analyser |
| Plan-checker M-5 (serialize round-trip smoke) | Revision | Task 4 third test | Production-path serialize → unserialize → handle() smoke catches SerializesModels-for-stdClass failure mode |
| Plan-checker M-6 (ContractTestCase tearDown) | Revision | Task 2 | tearDown forgets AdapterRegistry singleton after invariant 09's registration |
| Plan-checker M-7 (ROADMAP.md SC5 mismatch flag) | Revision | Task 5 02-VERIFICATION-INPUTS.md | Flagged for orchestrator surface; ROADMAP.md NOT updated by this plan |
| Plan-checker H-7 (BackboneIntegrationTest MockHandler count) | Revision | Task 4 dedup test | Uses Middleware::history($arHistory) for accurate call-count; NOT MockHandler internal queue count |
| Plan-checker H-8 (Plugin instantiation in tests) | Revision | Tasks 3 + 4 setUp | ContractTestCaseSmokeTest + BackboneIntegrationTest use `$this->app->singleton(AdapterRegistry::class)` direct bind |
| Plan-checker H-6 (shared fixtures) | Revision | Tasks 3 + 4 | Import FakeAdapter + FakeValueResolver from plan 02-01's `tests/Doubles/` |
| Plan-checker L-6 (Pest.php comment block dropped) | Revision | Task 3 | Pest.php NOT modified — CLAUDE.md "no comment pollution" |
| Plan-checker L-8 (classic Pest style) | Revision | Tasks 3 + 4 | All test files use `final class FooTest extends MetapixelTestCase` (smoke + integration) or `final class FakeAdapterContractTest extends EventSubjectAdapterContractTestCase` (contract) |

No gaps.

## Acceptance gate

`composer qa` exits 0 from `plugins/logingrupa/metapixel/` after Task 5's commit. Plan 02-07 closes Phase 2.
</verification>

<success_criteria>
Plan 02-07 ships when ALL of the following hold:

1. `composer.json` require-dev adds `orchestra/testbench ^9.0` (H-3); composer validate passes; Orchestra\Testbench\TestCase autoloads.
2. `classes/Testing/EventSubjectAdapterContractTestCase.php` is abstract + extends `Orchestra\Testbench\TestCase` (NOT MetapixelTestCase per H-3) + 2 abstract methods (makeAdapter + makeSubject) + 10 public test_invariant_NN_* methods + M-6 tearDown forgets AdapterRegistry.
3. `phpunit.xml` `<source><exclude>` exempts `./classes/Testing` from coverage.
4. `tests/Contract/Adapter/FakeAdapterContractTest.php` extends the base; all 10 invariants pass.
5. `tests/Feature/Adapter/ContractTestCaseSmokeTest.php` proves SC1 round-trip; 2 tests pass; uses H-8 setUp pattern.
6. `tests/Feature/Adapter/BackboneIntegrationTest.php` proves SC1 + SC5 end-to-end; 3 tests pass (happy-path + dedup with Middleware::history per H-7 + M-5 serialize round-trip); uses H-6 shared doubles + H-8 setUp.
7. `tests/Pest.php` NOT modified (L-6).
8. `.planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-VERIFICATION-INPUTS.md` scaffolded with SC1..SC5 checklists + pitfall closure table + M-7 ROADMAP.md SC5 mismatch flag.
9. `composer qa` exits 0 from `plugins/logingrupa/metapixel/`.
10. Single commit on HEAD touches ≥ 8 files; commit message references ADAP-11 + Phase 2 close + H-3 + M-5 + M-6 + M-7.
11. No comment pollution in source.
12. Phase 2 closure: all 8 plans (02-01 + 02-02 + 02-03a + 02-03b + 02-04 + 02-05 + 02-06 + 02-07) committed; the next action is `/gsd:verify-phase 02-adapter-system-core-contracts-registry-extension-hooks`.
</success_criteria>

<output>
After completion, create `plugins/logingrupa/metapixel/.planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-07-SUMMARY.md` documenting:

- Single commit SHA.
- composer qa output tail proving green on the FULL Phase 2 suite.
- Test counts: 10 invariants (FakeAdapterContractTest via Orchestra Testbench harness) + 2 smoke (ContractTestCaseSmokeTest) + 3 integration (BackboneIntegrationTest including M-5 serialize round-trip).
- Aggregate Phase 2 test count across all 8 plans (running `pest --list-tests` or counting via grep should land ≥ 60).
- Final coverage report on Phase 2 production code (Plugin.php + classes/* minus classes/Testing + models/*) — expected ≥ 90%.
- Phase 2 closure status: 11/11 ADAP-* requirements complete; P-01 / P-02 / P-05 / P-08 / P-13 all closed.
- H-3 resolution outcome: Orchestra Testbench harness chosen + documented in CUSTOM-ADAPTERS.md hookpoint for Phase 5.
- M-7 status: flagged in 02-VERIFICATION-INPUTS.md; orchestrator action pending to update ROADMAP.md SC5 wording.
- Next action: `/gsd:verify-phase 02-adapter-system-core-contracts-registry-extension-hooks` to run gsd-verifier against 02-VERIFICATION-INPUTS.md and produce 02-VERIFICATION.md.
- Recommend: post-verification, update `.planning/REQUIREMENTS.md` to flip ADAP-01..11 from `[ ]` to `[x]` + update `.planning/ROADMAP.md` Phase 2 status to "Complete" + apply M-7 SC5 wording fix.
</output>

## Revision History
- 2026-05-17 R2: H-3 RESOLUTION FLIPPED. orchestra/testbench dropped per user YAGNI challenge (CLAUDE.md "Build only for current need"). Phase 2 has ONE consumer of the contract base (first-party FakeAdapterContractTest); Phase 3 first-party adapters use plain MetapixelTestCase; third-party adapters land v2.1 earliest. R2 override: contract base extends `Logingrupa\Metapixel\Tests\MetapixelTestCase`. Task 1 (composer require) REMOVED. Plugin composer.json edited to remove orchestra/testbench require-dev entry in same commit. At v2.1 when first real third party authors an adapter — either swap base to Testbench then OR ship contract base as copy-this-file pattern via docs/CUSTOM-ADAPTERS.md.
- 2026-05-17 R1: Address plan-checker findings H-3 (Task 1 adds orchestra/testbench ^9.0 to require-dev; Task 2 EventSubjectAdapterContractTestCase extends `Orchestra\Testbench\TestCase` NOT MetapixelTestCase — Lovata-marketplace-idiomatic harness; eliminates the cross-namespace import + addPathToExclude suppression that defeated TOOL-11's purpose), M-5 (Task 4 BackboneIntegrationTest adds `test_serialize_round_trip_job_unserializes_and_runs_handle` — `serialize($obJob); unserialize($sBlob)->handle()` smoke catches SerializesModels-for-stdClass failure mode synchronous tests miss), M-6 (Task 2 EventSubjectAdapterContractTestCase adds tearDown override that calls `app()->forgetInstance(AdapterRegistry::class); parent::tearDown();` — prevents invariant 09's registry registration from polluting subsequent tests), M-7 (Task 5 02-VERIFICATION-INPUTS.md scaffolds with explicit M-7 flag section calling for orchestrator action to update ROADMAP.md SC5 wording per OQ-1; this plan does NOT modify ROADMAP.md), H-7 downgrade (Task 4 dedup test uses Middleware::history($arHistory) for accurate call-count; NOT MockHandler internal queue count), H-6 (Tasks 3 + 4 import FakeAdapter + FakeValueResolver from plan 02-01's tests/Doubles/), H-8 (Tasks 3 + 4 setUp use `$this->app->singleton(AdapterRegistry::class)`), L-6 (Pest.php NOT modified — CLAUDE.md "no comment pollution"), L-8 (Tasks 3 + 4 confirm classic Pest style).
