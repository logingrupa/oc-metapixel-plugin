---
phase: 02-adapter-system-core-contracts-registry-extension-hooks
plan: 6
slug: sendcapievent-queue-job-hooks
type: execute
wave: 4
depends_on:
  - 02-01
  - 02-03a
  - 02-03b
  - 02-04
  - 02-05
files_modified:
  - plugins/logingrupa/metapixel/classes/Queue/SendCapiEvent.php
  - plugins/logingrupa/metapixel/tests/Unit/Hook/BeforeDispatchHaltTest.php
  - plugins/logingrupa/metapixel/tests/Unit/Hook/BeforeDispatchPayloadMutationTest.php
  - plugins/logingrupa/metapixel/tests/Unit/Hook/ListenerExceptionIsolationTest.php
  - plugins/logingrupa/metapixel/tests/Unit/Hook/DeadLetterHookTest.php
  - plugins/logingrupa/metapixel/tests/Feature/Queue/SendCapiEventBindingResolutionTest.php
  - plugins/logingrupa/metapixel/tests/Feature/Queue/SendCapiEventHaltTest.php
  - plugins/logingrupa/metapixel/tests/Feature/Queue/SendCapiEventHappyPathTest.php
  - plugins/logingrupa/metapixel/tests/Feature/Queue/SendCapiEventDeadLetterTest.php
  - plugins/logingrupa/metapixel/tests/Feature/Queue/SendCapiEventTransientRetryTest.php
autonomous: true
requirements:
  - ADAP-04
  - ADAP-05
  - ADAP-10
maps_to:
  pitfalls:
    - P-08
  decisions:
    - D-15
    - D-16
    - D-20
must_haves:
  truths:
    - "`Logingrupa\\Metapixel\\Classes\\Queue\\SendCapiEvent` implements ShouldQueue with Dispatchable + InteractsWithQueue + Queueable + SerializesModels traits."
    - "Constructor signature: `(string $sEventName, array $arPayload, object $obSubject, string $sAdapterClass)` (D-20 — 4th arg `string $sAdapterClass`)."
    - "`handle(AdapterRegistry $obRegistry, MetaClient $obClient)` resolves adapter via `$obRegistry->resolveByClass($this->sAdapterClass)`; `BindingResolutionException` caught → writes FailedEvent + Log::critical → returns without throwing (job marked done — no retry)."
    - "`writeFailedEvent(Throwable $obException, ?int $iHttpStatus, ?EventSubjectAdapter $obAdapter = null)` accepts the resolved adapter (H-2) and populates FailedEvent.subject_type + subject_id from the adapter when non-null. Only the BindingResolutionException early-return path passes null (legitimate — adapter does not exist). Every other call site has the adapter."
    - "3 `Event::fire` hooks fire at the documented decision boundaries: before_dispatch (halt-able), after_dispatch (observe), dead_letter (observe)."
    - "before_dispatch fires via `Event::fire('metapixel.event.before_dispatch', [$sEventName, &$arPayload, $obSubject], true)`; listener returning literal `false` halts dispatch (no race-fence write, no MetaClient call, no after_dispatch)."
    - "All 3 hook fires wrap their `Event::fire` call in try/catch — Throwable → Log::warning + treat as abstain (dispatch continues). Listener exceptions never propagate to handle()."
    - "Transient MetaApi failure rethrows for Laravel queue retry; permanent failure writes FailedEvent + fires dead_letter."
    - "`failed(\\Throwable $obException)` retry-exhaustion handler writes FailedEvent + fires dead_letter hook (catches the queue worker's 4th attempt). L-5: same snapshot pattern as handle() for event_id/event_time consistency."
    - "All test files use H-8 setUp pattern (`$this->app->singleton(AdapterRegistry::class)` direct bind) and import TestSubject + TestSubjectAdapter from plan 02-01's `tests/Doubles/` (no inline class declarations)."
    - "All 9 test files pass (T11–T14 Unit/Hook + T18–T22 Feature/Queue)."
    - "composer qa exits 0."
  artifacts:
    - path: "plugins/logingrupa/metapixel/classes/Queue/SendCapiEvent.php"
      provides: "ADAP-04 + ADAP-05 + ADAP-10 — queue orchestrator + 3 hooks + listener isolation + adapter rehydrate + FailedEvent boundary catch (H-2 populated subject_type/id)."
      contains: "metapixel.event.before_dispatch"
  key_links:
    - from: "plugins/logingrupa/metapixel/classes/Queue/SendCapiEvent.php"
      to: "plugins/logingrupa/metapixel/classes/Adapter/AdapterRegistry.php"
      via: "resolveByClass(sAdapterClass)"
      pattern: "resolveByClass"
    - from: "plugins/logingrupa/metapixel/classes/Queue/SendCapiEvent.php"
      to: "plugins/logingrupa/metapixel/classes/Helper/EventLogWriter.php"
      via: "EventLogWriter::record race-fence"
      pattern: "EventLogWriter::record"
    - from: "plugins/logingrupa/metapixel/classes/Queue/SendCapiEvent.php"
      to: "plugins/logingrupa/metapixel/classes/Meta/MetaClient.php"
      via: "sendForPixel call"
      pattern: "sendForPixel"
    - from: "plugins/logingrupa/metapixel/classes/Queue/SendCapiEvent.php"
      to: "plugins/logingrupa/metapixel/models/FailedEvent.php"
      via: "FailedEvent::create on permanent fail with subject_type/subject_id populated (H-2)"
      pattern: "FailedEvent::create"
---

<objective>
Ship the queue job orchestrator (`SendCapiEvent`) that bridges everything Plan 02-01 through 02-05 produced. Wires the 3 `Event::fire` hooks at the documented decision boundaries with listener-isolation wrappers (D-15 + D-16). Resolves adapter via `AdapterRegistry::resolveByClass` after queue serialization rehydrates the job (ADAP-10). Catches `BindingResolutionException` at the boundary, writes FailedEvent + Log::critical, never throws past the boundary.

H-2 RESOLUTION: `writeFailedEvent` accepts `?EventSubjectAdapter $obAdapter = null` and populates `subject_type` + `subject_id` from the adapter when non-null. The BindingResolutionException early-return path passes null (legitimate — adapter does not exist). Every other call site (Permanent / MissingPixel / MissingCapiToken catches, `failed()` retry-exhaustion) has the adapter resolved and passes it. Phase 4 FailedEvents::onReplay() depends on these columns to re-resolve via AdapterRegistry.

OQ-2 RESOLUTION applies: `metapixel.event.before_dispatch` is halt-able via Laravel's `Event::fire($name, $payload, $halt=true)` semantics — listener returning literal `false` vetoes dispatch. The other two hooks (`after_dispatch`, `dead_letter`) are observe-only.

P-08 PREVENTION: `&$arPayload` is by-reference ONLY on `before_dispatch`; the other two hooks pass payload by-value. PHPDoc on the hook fire site documents that listeners MUST NOT mutate `event_id` or `event_time` (the dedup contract anchor). Snapshot+restore in fireBeforeDispatchHalt enforces. Test T12 verifies.

L-5 RESOLUTION: `failed()` retry-exhaustion handler uses the same snapshot-and-pass-adapter pattern as `handle()` to keep failed_events row state consistent.

H-8 RESOLUTION: All 9 test setUps use `$this->app->singleton(AdapterRegistry::class)` direct bind. Never `(new \Logingrupa\Metapixel\Plugin)->register()` — PluginBase requires container injection.

H-6 RESOLUTION: All 9 test files import TestSubject, TestSubjectAdapter, FakeStubAdapter, SpyMetaClient from `tests/Doubles/` (plans 02-01 + 02-05) — no inline class declarations.

Output: 1 production class (`classes/Queue/SendCapiEvent.php`) + 9 test files (4 hook unit + 5 queue feature).
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@plugins/logingrupa/metapixel/CLAUDE.md
@plugins/logingrupa/metapixel/.planning/REQUIREMENTS.md
@plugins/logingrupa/metapixel/.planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-CONTEXT.md
@plugins/logingrupa/metapixel/.planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-RESEARCH.md
@plugins/logingrupa/metapixel/classes/Adapter/AdapterRegistry.php
@plugins/logingrupa/metapixel/classes/Adapter/EventSubjectAdapter.php
@plugins/logingrupa/metapixel/classes/Helper/EventLogWriter.php
@plugins/logingrupa/metapixel/classes/Helper/SiteResolver.php
@plugins/logingrupa/metapixel/classes/Meta/MetaClient.php
@plugins/logingrupa/metapixel/classes/Exception/MetaApiTransientException.php
@plugins/logingrupa/metapixel/classes/Exception/MetaApiPermanentException.php
@plugins/logingrupa/metapixel/classes/Exception/MissingPixelConfigException.php
@plugins/logingrupa/metapixel/classes/Exception/MissingCapiTokenException.php
@plugins/logingrupa/metapixel/models/FailedEvent.php
@plugins/logingrupa/metapixel/models/Settings.php
@plugins/logingrupa/metapixel/tests/Doubles/TestSubject.php
@plugins/logingrupa/metapixel/tests/Doubles/TestSubjectAdapter.php
@plugins/logingrupa/metapixel/tests/Doubles/FakeStubAdapter.php
@plugins/logingrupa/metapixel/tests/Doubles/SpyMetaClient.php

<interfaces>
Locked decisions:

- D-15: 3 Event::fire hooks `metapixel.event.before_dispatch`, `metapixel.event.after_dispatch`, `metapixel.event.dead_letter`. Five additional hooks deferred to v2.1.
- D-16: Listener exceptions caught + Log::warning + continue. Never propagate to dispatch.
- D-20: `SendCapiEvent` constructor signature `(string $sEventName, array $arPayload, object $obSubject, string $sAdapterClass)`. handle() resolves via AdapterRegistry::resolveByClass. BindingResolutionException → FailedEvent + Log::critical.
- OQ-2 (RESEARCH §2): before_dispatch halt-able via `Event::fire($name, $payload, true)`; listener returning literal `false` vetoes. Payload by-reference ONLY on before_dispatch.
- P-08 mitigation: snapshot+restore event_id/event_time before/after firing the hook.
- H-2 lock: writeFailedEvent accepts the resolved adapter + populates FailedEvent.subject_type/subject_id from it.
- L-4 lock: imports `Illuminate\Support\Facades\Event`, `Illuminate\Support\Facades\Log` FQN — never `use Event;` or `use Log;` shortform.

SendCapiEvent shape (RESEARCH §4.7 + §2 + H-2 + L-5 — ~160 LOC; helpers split out so each method ≤ 40 LOC):

```
namespace Logingrupa\Metapixel\Classes\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Adapter\EventSubjectAdapter;
use Logingrupa\Metapixel\Classes\Exception\MetaApiPermanentException;
use Logingrupa\Metapixel\Classes\Exception\MetaApiTransientException;
use Logingrupa\Metapixel\Classes\Exception\MetaPixelException;
use Logingrupa\Metapixel\Classes\Exception\MissingCapiTokenException;
use Logingrupa\Metapixel\Classes\Exception\MissingPixelConfigException;
use Logingrupa\Metapixel\Classes\Helper\EventLogWriter;
use Logingrupa\Metapixel\Classes\Helper\SiteResolver;
use Logingrupa\Metapixel\Classes\Meta\MetaClient;
use Logingrupa\Metapixel\Models\EventLog;
use Logingrupa\Metapixel\Models\FailedEvent;
use Logingrupa\Metapixel\Models\Settings;
use Throwable;

final class SendCapiEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const HOOK_BEFORE_DISPATCH = 'metapixel.event.before_dispatch';

    public const HOOK_AFTER_DISPATCH = 'metapixel.event.after_dispatch';

    public const HOOK_DEAD_LETTER = 'metapixel.event.dead_letter';

    /** @var int Laravel queue retry attempts (1 initial + 3 backoffs = 4 total tries) */
    public int $tries = 3;

    /** @var list<int> backoff seconds */
    public array $backoff = [1, 4, 16];

    /**
     * @param  array<string, mixed>  $arPayload
     */
    public function __construct(
        public readonly string $sEventName,
        public array $arPayload,
        public readonly object $obSubject,
        public readonly string $sAdapterClass,
    ) {}

    public function handle(AdapterRegistry $obRegistry, MetaClient $obClient): void
    {
        try {
            $obAdapter = $obRegistry->resolveByClass($this->sAdapterClass);
        } catch (BindingResolutionException $obException) {
            Log::critical('metapixel: adapter rehydrate failed — dead-lettered', [
                'meta_pixel.adapter_class' => $this->sAdapterClass,
                'meta_pixel.event_id' => $this->arPayload['data'][0]['event_id'] ?? null,
                'meta_pixel.event_name' => $this->sEventName,
                'meta_pixel.exception' => get_class($obException),
            ]);
            $this->writeFailedEvent($obException, null, null);  // H-2: null adapter — legitimate
            return;
        }

        if ($this->fireBeforeDispatchHalt($obAdapter)) {
            Log::info('metapixel: dispatch halted by before_dispatch listener', [
                'meta_pixel.event_id' => $this->arPayload['data'][0]['event_id'] ?? null,
                'meta_pixel.event_name' => $this->sEventName,
            ]);
            return;
        }

        $iSiteId = SiteResolver::forSubject($this->obSubject, $obAdapter);

        $bWonRaceFence = EventLogWriter::record(
            (string) ($this->arPayload['data'][0]['event_id'] ?? ''),
            $this->sEventName,
            EventLog::CHANNEL_CAPI,
            $this->obSubject,
            $obAdapter->getSecretKey($this->obSubject),
            (int) ($this->arPayload['data'][0]['event_time'] ?? 0),
            $iSiteId,
        );
        if (! $bWonRaceFence) {
            return;
        }

        $arCreds = Settings::lookupForSite($iSiteId);

        try {
            $arResponse = $obClient->sendForPixel($arCreds['pixel_id'], $arCreds['capi_access_token'], $this->arPayload);
        } catch (MetaApiTransientException $obException) {
            throw $obException;  // Laravel queue retry
        } catch (MetaApiPermanentException|MissingPixelConfigException|MissingCapiTokenException $obException) {
            $iStatus = $obException instanceof MetaApiPermanentException ? $obException->getHttpStatus() : null;
            $this->writeFailedEvent($obException, $iStatus, $obAdapter);  // H-2: adapter resolved
            $this->fireDeadLetter($obException);
            return;
        }

        $this->fireAfterDispatch($arResponse);
    }

    public function failed(Throwable $obException): void
    {
        // L-5: try to resolve adapter for failed_events.subject_type/id population.
        // If resolution fails (e.g. adapter class unbound after worker reload), fall
        // back to null adapter — same fail-safe as handle()'s BindingResolutionException.
        $obAdapter = null;
        try {
            $obAdapter = app(AdapterRegistry::class)->resolveByClass($this->sAdapterClass);
        } catch (Throwable $obResolveException) {
            // Silent: failed() runs on retry-exhaustion path; cannot escalate.
        }

        $iStatus = $obException instanceof MetaApiTransientException ? $obException->getHttpStatus() : null;
        $this->writeFailedEvent($obException, $iStatus, $obAdapter);  // H-2 + L-5
        $this->fireDeadLetter($obException);
    }

    private function fireBeforeDispatchHalt(EventSubjectAdapter $obAdapter): bool
    {
        try {
            // P-08 enforcement: snapshot event_id + event_time BEFORE firing the hook.
            // Restore from snapshot so a misbehaving listener cannot break dedup.
            $sEventId = (string) ($this->arPayload['data'][0]['event_id'] ?? '');
            $iEventTime = (int) ($this->arPayload['data'][0]['event_time'] ?? 0);

            $arMutablePayload = $this->arPayload;
            $mResult = Event::fire(
                self::HOOK_BEFORE_DISPATCH,
                [$this->sEventName, &$arMutablePayload, $this->obSubject],
                true,
            );

            $arMutablePayload['data'][0]['event_id'] = $sEventId;
            $arMutablePayload['data'][0]['event_time'] = $iEventTime;
            $this->arPayload = $arMutablePayload;

            return $mResult === false;
        } catch (Throwable $obException) {
            Log::warning('metapixel: before_dispatch listener threw — treating as abstain', [
                'meta_pixel.exception' => get_class($obException),
                'meta_pixel.message' => $obException->getMessage(),
                'meta_pixel.event_id' => $this->arPayload['data'][0]['event_id'] ?? null,
            ]);
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $arResponse
     */
    private function fireAfterDispatch(array $arResponse): void
    {
        try {
            Event::fire(self::HOOK_AFTER_DISPATCH, [
                $this->sEventName,
                $this->arPayload,
                $this->obSubject,
                $arResponse,
            ]);
        } catch (Throwable $obException) {
            Log::warning('metapixel: after_dispatch listener threw — observed', [
                'meta_pixel.exception' => get_class($obException),
                'meta_pixel.event_id' => $this->arPayload['data'][0]['event_id'] ?? null,
            ]);
        }
    }

    private function fireDeadLetter(Throwable $obException): void
    {
        try {
            Event::fire(self::HOOK_DEAD_LETTER, [
                $this->sEventName,
                $this->arPayload,
                $this->obSubject,
                $obException,
            ]);
        } catch (Throwable $obListenerException) {
            Log::warning('metapixel: dead_letter listener threw — observed', [
                'meta_pixel.listener_exception' => get_class($obListenerException),
            ]);
        }
    }

    /**
     * H-2: writeFailedEvent accepts the resolved adapter and populates
     * FailedEvent.subject_type + subject_id from it when non-null. The
     * BindingResolutionException path passes null (adapter does not exist —
     * Phase 4 admin UI cannot re-resolve and that's the right answer).
     */
    private function writeFailedEvent(Throwable $obException, ?int $iHttpStatus, ?EventSubjectAdapter $obAdapter): void
    {
        try {
            $arContext = $obException instanceof MetaPixelException ? $obException->getContext() : [];
            $sSubjectType = $obAdapter !== null ? $obAdapter->getSubjectType($this->obSubject) : null;
            $iSubjectId = $obAdapter !== null ? $obAdapter->getSubjectId($this->obSubject) : null;

            FailedEvent::create([
                'event_id' => (string) ($this->arPayload['data'][0]['event_id'] ?? ''),
                'event_name' => $this->sEventName,
                'adapter_type' => $this->sAdapterClass,
                'subject_type' => $sSubjectType,
                'subject_id' => $iSubjectId,
                'payload' => $this->arPayload,
                'graph_error' => $obException->getMessage()."\n".json_encode($arContext),
                'http_status' => $iHttpStatus,
                'attempts' => $this->attempts() ?: 1,
            ]);
        } catch (Throwable $obDbException) {
            // Silent: DB write failure on a dead-letter path cannot itself escalate.
            // The exception was already logged via Log::critical or Log::warning upstream.
            Log::warning('metapixel: writeFailedEvent — DB insert failed', [
                'meta_pixel.exception' => get_class($obDbException),
            ]);
        }
    }
}
```

Critical Laravel queue idioms:
- `ShouldQueue` interface + `Dispatchable + InteractsWithQueue + Queueable + SerializesModels` traits → standard Laravel-job stack.
- Test environment can dispatch SYNCHRONOUSLY via `Queue::fake()` or by setting `QUEUE_CONNECTION=sync` (which Phase 1's MetapixelTestCase already does). For Feature tests, dispatch synchronously and assert by-side-effect: `SendCapiEvent::dispatchSync(...)`.
- `$obSubject` is serialized between push and pop via `SerializesModels` — Eloquent models get a serialize stub; plain `object` (like stdClass test fixtures) gets standard PHP serialize. Production: Lovata Order objects (Eloquent) serialize cleanly. FakeAdapter test subjects are plain stdClass — also serializable. M-5 (plan 02-07) adds a serialize round-trip smoke test in BackboneIntegrationTest.
- `attempts()` returns the current attempt count (1 on first try, up to `$tries + 1` = 4 on the last retry).
- `failed()` is called by Laravel when the job throws past `$tries`. Receives the throwable. We resolve the adapter (L-5 — same as handle()), write FailedEvent + fire dead_letter.

`Event::fire()` in October is an alias to `Illuminate\Support\Facades\Event::fire`. `[VERIFIED: vendor/october/rain/src/Support/Facades/Event.php]`. The third `true` arg passes through to `Illuminate\Events\Dispatcher::dispatch($event, $payload, $halt=true)`. `[VERIFIED: vendor/laravel/framework/src/Illuminate/Events/Dispatcher.php:270-339]`.

PHPDoc on the 3 hook firing sites (canonical contract from RESEARCH §2 PHPDoc draft) — embedded in the SendCapiEvent class-level PHPDoc to document all three signatures + listener-isolation contract + P-08 mutation rule.

H-6 shared fixture imports (no inline declarations):

| Test file | Imports |
|-----------|---------|
| BeforeDispatchHaltTest, BeforeDispatchPayloadMutationTest, ListenerExceptionIsolationTest, DeadLetterHookTest | `FakeStubAdapter` + `SpyMetaClient` from `Logingrupa\Metapixel\Tests\Doubles\` |
| SendCapiEventHaltTest, SendCapiEventHappyPathTest, SendCapiEventDeadLetterTest, SendCapiEventTransientRetryTest | `TestSubject` + `TestSubjectAdapter` from `tests/Doubles/` (registered via AdapterRegistry); `SpyMetaClient` where needed |
| SendCapiEventBindingResolutionTest | No fixture imports needed — passes bogus adapter class string |

H-8 setUp pattern (every test): `$this->app->singleton(AdapterRegistry::class)` direct bind — never `(new Plugin)->register()`.

L-4 (Log facade): all 9 test files import `Illuminate\Support\Facades\Log` FQN when they use `Log::shouldReceive`.

L-8 (classic Pest style): all 9 test files use `final class FooTest extends MetapixelTestCase`.

Test approach for the 9 tests:

T11 BeforeDispatchHaltTest — register an `Event::listen('metapixel.event.before_dispatch', fn() => false)` listener; dispatch SendCapiEvent synchronously; assert no EventLog row + no MetaClient call (SpyMetaClient.iCallCount === 0).

T12 BeforeDispatchPayloadMutationTest — register a listener that mutates `$arPayload['data'][0]['custom_data']['campaign_tier'] = 'gold'`; dispatch; assert the MetaClient mock received the mutated payload AND that event_id is unchanged. Then add a second listener that tries to mutate event_id; dispatch; assert event_id IN THE OUTGOING PAYLOAD is the ORIGINAL (snapshot+restore — P-08 enforcement).

T13 ListenerExceptionIsolationTest — register a listener that throws RuntimeException; dispatch; assert Log::warning called + dispatch continued (SpyMetaClient.iCallCount === 1, race-fence row written).

T14 DeadLetterHookTest — inline anonymous subclass of MetaClient that throws MetaApiPermanentException(400); assert dead_letter listener invoked with the exception. Uses FakeStubAdapter from `tests/Doubles/`. Migrations: setUp runs both CreateMetapixelEventLogTable + CreateMetapixelFailedEventsTable.

T18 SendCapiEventBindingResolutionTest — pass bogus adapter class string ('NonExistent\Foo\BarAdapter'); assert FailedEvent row created with `subject_type=null, subject_id=null` (H-2 — legitimate null because adapter does not exist); Log::critical called.

T19 SendCapiEventHaltTest — register halting listener; dispatch; assert no EventLog row + no HTTP call.

T20 SendCapiEventHappyPathTest — full path: dispatch → race-fence wins → MetaClient mock returns 200 → after_dispatch listener invoked with response array.

T21 SendCapiEventDeadLetterTest — MetaClient mock returns 400 → MetaApiPermanentException → FailedEvent row + dead_letter listener invoked. H-2 ASSERT: FailedEvent row has `subject_type='fake.subject', subject_id=42` (populated from TestSubjectAdapter — H-2).

T22 SendCapiEventTransientRetryTest — MetaClient mock returns 503 → MetaApiTransientException rethrown (sync mode rethrows; assert via expectException).

For tests T18-T22 sync dispatch:
- Use `SendCapiEvent::dispatchSync($sName, $arPayload, $obSubject, $sAdapterClass)` OR direct `$obJob->handle(app(AdapterRegistry::class), $obClient)`.

For tests T11-T14 hook isolation:
- Unit tier: instantiate SendCapiEvent + invoke `handle($obRegistry, $obMetaClient)` directly with hand-built dependencies. Skip the Laravel queue runtime entirely.
- Mock MetaClient with SpyMetaClient (from tests/Doubles/) — simpler than Guzzle MockHandler for hook-isolation tests.
- Use `Event::listen` + `Event::forget` per test to register/unregister hook listeners.

Cleaner per-test pattern using `Event::listen` + `Event::forget` in tearDown to prevent listener leak.
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Write SendCapiEvent queue job (H-2 writeFailedEvent + L-5 failed())</name>
  <files>
    plugins/logingrupa/metapixel/classes/Queue/SendCapiEvent.php
  </files>
  <behavior>
    - Final class `Logingrupa\Metapixel\Classes\Queue\SendCapiEvent implements ShouldQueue`.
    - Uses Dispatchable + InteractsWithQueue + Queueable + SerializesModels traits.
    - Constants HOOK_BEFORE_DISPATCH, HOOK_AFTER_DISPATCH, HOOK_DEAD_LETTER.
    - Public properties `$tries = 3`, `$backoff = [1, 4, 16]`.
    - Constructor: `(string $sEventName, array $arPayload, object $obSubject, string $sAdapterClass)`. `$arPayload` is mutable (not readonly) so hook listener mutations can survive across handle() execution.
    - `handle(AdapterRegistry, MetaClient): void` orchestrates: adapter rehydrate (catch BindingResolutionException → FailedEvent with null adapter [H-2] + Log::critical + return) → before_dispatch halt check → site_id via SiteResolver → EventLogWriter race-fence → Settings::lookupForSite → MetaClient::sendForPixel (catch Transient = rethrow; catch Permanent/MissingPixel/MissingToken = writeFailedEvent with adapter [H-2] + dead_letter + return) → after_dispatch.
    - `failed(Throwable): void` — retry-exhaustion hook; tries to resolve adapter (L-5), writes FailedEvent (with adapter if resolvable) + fires dead_letter.
    - Private helpers fireBeforeDispatchHalt, fireAfterDispatch, fireDeadLetter, writeFailedEvent — each ≤ 40 LOC.
    - `writeFailedEvent(Throwable, ?int $iHttpStatus, ?EventSubjectAdapter $obAdapter)` — H-2 signature; populates `subject_type` + `subject_id` from adapter when non-null.
    - fireBeforeDispatchHalt SNAPSHOTS event_id + event_time before firing the hook and RESTORES them after — P-08 enforcement.
    - All three hook fire sites wrap Event::fire in try/catch (Throwable → Log::warning + treat as abstain/observed).
    - Imports use `Illuminate\Support\Facades\Log` + `Illuminate\Support\Facades\Event` FQN (L-4).
    - File ≤ 220 LOC.
  </behavior>
  <action>
Create `classes/Queue/SendCapiEvent.php` per the shape in `<interfaces>` — including the P-08 snapshot+restore in fireBeforeDispatchHalt AND the H-2 `?EventSubjectAdapter $obAdapter` parameter on writeFailedEvent AND the L-5 adapter-resolve in failed().

CRITICAL — Phase 1 PHPStan disallowed-calls rule (plan 02-02) bans `Request`, `SiteManager`, `Site`, `request()` inside `classes/Queue/*` (H-1 disallowIn deny-list). SendCapiEvent must NOT touch any of these. site_id comes from SiteResolver::forSubject which comes from the adapter.

The class-level PHPDoc carries the 3 hook contracts (verbatim from RESEARCH §2):

```
/**
 * Queue job that bridges adapter → EventLog race-fence → MetaClient send.
 *
 * Hook: metapixel.event.before_dispatch — halt-able via Event::fire(..., true).
 *   Signature: function(string, array &$arPayload, object): mixed
 *   Return false to veto. Mutating event_id/event_time is forbidden (the
 *   server↔browser dedup contract). Snapshot+restore guarantees enforcement.
 *
 * Hook: metapixel.event.after_dispatch — observe-only successful-dispatch tap.
 *   Signature: function(string, array, object, array $arGraphResponse): mixed
 *
 * Hook: metapixel.event.dead_letter — observe-only permanent-failure alert.
 *   Signature: function(string, array, object, \Throwable): mixed
 *
 * Listener exceptions on any hook are caught + Log::warning + continue —
 * never propagate to the dispatch pipeline.
 *
 * writeFailedEvent populates FailedEvent.subject_type + subject_id from the
 * resolved adapter when available (enables Phase 4 admin UI re-resolution).
 * BindingResolutionException early-return passes null — adapter does not
 * exist, re-resolution is impossible.
 */
```

DO NOT use comment markers like `// P-08`, `// OQ-2`, `// ADAP-XX`, `// H-2`, `// L-5` — keep workflow refs out of source. The behavior is documented in prose in the class PHPDoc + method PHPDocs.

NULL-safe payload access: `$this->arPayload['data'][0]['event_id'] ?? ''` everywhere. Defensive defaults protect against listener mutations + serialization corner cases.

Imports (L-4 lock): use `Illuminate\Support\Facades\Event` + `Illuminate\Support\Facades\Log` FQN — never the October aliases.
  </action>
  <verify>
    <automated>test -f plugins/logingrupa/metapixel/classes/Queue/SendCapiEvent.php &amp;&amp; php -l plugins/logingrupa/metapixel/classes/Queue/SendCapiEvent.php | grep -q 'No syntax errors' &amp;&amp; grep -q 'final class SendCapiEvent' plugins/logingrupa/metapixel/classes/Queue/SendCapiEvent.php &amp;&amp; grep -q 'implements ShouldQueue' plugins/logingrupa/metapixel/classes/Queue/SendCapiEvent.php &amp;&amp; grep -q "metapixel.event.before_dispatch" plugins/logingrupa/metapixel/classes/Queue/SendCapiEvent.php &amp;&amp; grep -q "metapixel.event.after_dispatch" plugins/logingrupa/metapixel/classes/Queue/SendCapiEvent.php &amp;&amp; grep -q "metapixel.event.dead_letter" plugins/logingrupa/metapixel/classes/Queue/SendCapiEvent.php &amp;&amp; grep -q 'resolveByClass' plugins/logingrupa/metapixel/classes/Queue/SendCapiEvent.php &amp;&amp; grep -q 'EventLogWriter::record' plugins/logingrupa/metapixel/classes/Queue/SendCapiEvent.php &amp;&amp; grep -q 'sendForPixel' plugins/logingrupa/metapixel/classes/Queue/SendCapiEvent.php &amp;&amp; grep -q 'FailedEvent::create' plugins/logingrupa/metapixel/classes/Queue/SendCapiEvent.php &amp;&amp; grep -q '?EventSubjectAdapter \$obAdapter' plugins/logingrupa/metapixel/classes/Queue/SendCapiEvent.php &amp;&amp; grep -q "'subject_type' =&gt; \$sSubjectType" plugins/logingrupa/metapixel/classes/Queue/SendCapiEvent.php &amp;&amp; grep -q 'use Illuminate\\\\Support\\\\Facades\\\\Log;' plugins/logingrupa/metapixel/classes/Queue/SendCapiEvent.php &amp;&amp; grep -q 'use Illuminate\\\\Support\\\\Facades\\\\Event;' plugins/logingrupa/metapixel/classes/Queue/SendCapiEvent.php &amp;&amp; ! grep -E '(SiteManager|Site::setSite|request\(\))' plugins/logingrupa/metapixel/classes/Queue/SendCapiEvent.php &amp;&amp; ! grep -E '(// CR-[0-9]|// Phase\s*[0-9]|// Plan\s*[0-9]|// P-0[0-9]|// OQ-[0-9]|// H-[0-9]|// L-[0-9])' plugins/logingrupa/metapixel/classes/Queue/SendCapiEvent.php</automated>
  </verify>
  <done>SendCapiEvent.php is final ShouldQueue + 3 hook constants + resolveByClass + EventLogWriter::record + sendForPixel + FailedEvent::create with subject_type populated from adapter (H-2) + writeFailedEvent accepts `?EventSubjectAdapter` parameter + Log/Event FQN imports (L-4) + no banned Site/Request/request() + no phase/H/L markers in source.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Write hook unit tests (T11–T14) using shared doubles</name>
  <files>
    plugins/logingrupa/metapixel/tests/Unit/Hook/BeforeDispatchHaltTest.php
    plugins/logingrupa/metapixel/tests/Unit/Hook/BeforeDispatchPayloadMutationTest.php
    plugins/logingrupa/metapixel/tests/Unit/Hook/ListenerExceptionIsolationTest.php
    plugins/logingrupa/metapixel/tests/Unit/Hook/DeadLetterHookTest.php
  </files>
  <behavior>
    - All 4 tests under `tests/Unit/Hook/` extend MetapixelTestCase.
    - Each test isolates hooks via tearDown's `Event::forget` for the 3 hook constants.
    - H-8 setUp: `$this->app->singleton(AdapterRegistry::class)` direct bind — never `(new Plugin)->register()`.
    - H-6 imports: FakeStubAdapter + SpyMetaClient from `Logingrupa\Metapixel\Tests\Doubles\` (NO inline class declarations).
    - L-4 imports: `Illuminate\Support\Facades\Event` + `Illuminate\Support\Facades\Log` FQN.
    - L-8: classic Pest style — `final class FooTest extends MetapixelTestCase`.
    - T11 BeforeDispatchHaltTest: listener returns false → fireBeforeDispatchHalt returns true → handle() returns early without race-fence or HTTP call (SpyMetaClient.iCallCount === 0).
    - T12 BeforeDispatchPayloadMutationTest: listener mutates custom_data['campaign_tier']='gold' → outgoing payload contains the mutation. Second test: listener mutates event_id → outgoing payload's event_id is the ORIGINAL (snapshot restored — P-08 enforcement).
    - T13 ListenerExceptionIsolationTest: listener throws RuntimeException → Log::warning called + handle() proceeds (SpyMetaClient.iCallCount === 1).
    - T14 DeadLetterHookTest: inline anonymous MetaClient subclass throws MetaApiPermanentException; asserts dead_letter listener invoked with [eventName, payload, subject, exception]. Runs both migrations in setUp (event_log + failed_events).
    - All 4 tests pass.
  </behavior>
  <action>
For T11 `BeforeDispatchHaltTest.php`:

```
<?php

namespace Logingrupa\Metapixel\Tests\Unit\Hook;

use Illuminate\Support\Facades\Event;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Queue\SendCapiEvent;
use Logingrupa\Metapixel\Tests\Doubles\FakeStubAdapter;
use Logingrupa\Metapixel\Tests\Doubles\SpyMetaClient;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;

final class BeforeDispatchHaltTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
        app(AdapterRegistry::class)->register(\stdClass::class, FakeStubAdapter::class);
    }

    protected function tearDown(): void
    {
        Event::forget(SendCapiEvent::HOOK_BEFORE_DISPATCH);
        Event::forget(SendCapiEvent::HOOK_AFTER_DISPATCH);
        Event::forget(SendCapiEvent::HOOK_DEAD_LETTER);
        parent::tearDown();
    }

    public function test_listener_returning_false_halts_dispatch_no_http_call(): void
    {
        Event::listen(SendCapiEvent::HOOK_BEFORE_DISPATCH, fn() => false);

        $obSpyClient = new SpyMetaClient;
        $obJob = new SendCapiEvent('Purchase', $this->makePayload(), new \stdClass, FakeStubAdapter::class);
        $obJob->handle(app(AdapterRegistry::class), $obSpyClient);

        $this->assertSame(0, $obSpyClient->iCallCount, 'no HTTP call when halted');
    }

    /** @return array<string, mixed> */
    private function makePayload(): array
    {
        return ['data' => [[
            'event_id' => 'uuid-1',
            'event_time' => 1700000000,
            'event_name' => 'Purchase',
            'action_source' => 'website',
            'user_data' => [],
            'custom_data' => [],
        ]]];
    }
}
```

T12 `BeforeDispatchPayloadMutationTest.php`: same setUp/tearDown pattern; 2 tests:
- `test_listener_mutation_of_custom_data_propagates_to_outgoing_payload` — register a by-reference listener that adds `$arPayload['data'][0]['custom_data']['campaign_tier'] = 'gold'`; assert `$obSpyClient->arLastPayload['data'][0]['custom_data']['campaign_tier'] === 'gold'`.
- `test_listener_mutation_of_event_id_is_reverted_p08` — register a by-reference listener that sets `$arPayload['data'][0]['event_id'] = 'malicious-replacement'` and `$arPayload['data'][0]['event_time'] = 9999999999`; assert `$obSpyClient->arLastPayload['data'][0]['event_id'] === 'uuid-1'` (snapshot restored) and `event_time === 1700000000`.

T13 `ListenerExceptionIsolationTest.php`: register a listener that throws RuntimeException; `Log::shouldReceive('warning')->atLeast()->once()`; dispatch; assert `$obSpyClient->iCallCount === 1` (dispatch continued).

T14 `DeadLetterHookTest.php`: setUp ALSO runs both migrations (CreateMetapixelEventLogTable + CreateMetapixelFailedEventsTable). Register dead_letter listener that records invocations into an array; inline anonymous `class extends MetaClient { sendForPixel: throws MetaApiPermanentException('400', 400); }`; dispatch with FakeStubAdapter; assert dead_letter listener was invoked once with exception class `MetaApiPermanentException`. H-2 ASSERT: FailedEvent row has `subject_type='fake.subject', subject_id=1` populated from FakeStubAdapter (proves the H-2 fix lands correctly).

For T14, the inline MetaClient subclass IS the test's specific concern (one-off throwing variant) — not a shared fixture. Inline declaration acceptable because it's a single-file pattern, not cross-file.
  </action>
  <verify>
    <automated>for f in plugins/logingrupa/metapixel/tests/Unit/Hook/BeforeDispatchHaltTest.php plugins/logingrupa/metapixel/tests/Unit/Hook/BeforeDispatchPayloadMutationTest.php plugins/logingrupa/metapixel/tests/Unit/Hook/ListenerExceptionIsolationTest.php plugins/logingrupa/metapixel/tests/Unit/Hook/DeadLetterHookTest.php; do test -f "$f" || { echo "missing $f"; exit 1; }; php -l "$f" | grep -q 'No syntax errors' || exit 1; done &amp;&amp; ! grep -rE '\(new\s+\\?Logingrupa\\\\Metapixel\\\\Plugin\)' plugins/logingrupa/metapixel/tests/Unit/Hook/ &amp;&amp; ! grep -rE '^(final\s+)?class\s+(TestSubject|FakeStubAdapter|SpyMetaClient)\s' plugins/logingrupa/metapixel/tests/Unit/Hook/ &amp;&amp; cd plugins/logingrupa/metapixel &amp;&amp; ../../../vendor/bin/pest tests/Unit/Hook --configuration phpunit.xml 2&gt;&amp;1 | tail -10 | grep -Eq '(PASS|OK|Tests:.*passed)'</automated>
  </verify>
  <done>4 hook test files exist + php -l clean; H-8 setUp pattern (no Plugin instantiation); H-6 imports (no inline declarations of TestSubject/FakeStubAdapter/SpyMetaClient); pest tests/Unit/Hook exits 0 with ≥ 5 test methods passing.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: Write Queue feature tests (T18–T22) using shared doubles</name>
  <files>
    plugins/logingrupa/metapixel/tests/Feature/Queue/SendCapiEventBindingResolutionTest.php
    plugins/logingrupa/metapixel/tests/Feature/Queue/SendCapiEventHaltTest.php
    plugins/logingrupa/metapixel/tests/Feature/Queue/SendCapiEventHappyPathTest.php
    plugins/logingrupa/metapixel/tests/Feature/Queue/SendCapiEventDeadLetterTest.php
    plugins/logingrupa/metapixel/tests/Feature/Queue/SendCapiEventTransientRetryTest.php
  </files>
  <behavior>
    - All 5 tests under `tests/Feature/Queue/` extend MetapixelTestCase.
    - setUp: H-8 singleton bind + run both migrations (event_log + failed_events) + register TestSubject → TestSubjectAdapter via AdapterRegistry.
    - tearDown: drop migrations + Event::forget hooks + forgetInstance.
    - H-6 imports: TestSubject + TestSubjectAdapter from `tests/Doubles/`; SpyMetaClient or Guzzle MockHandler where needed. NO inline class declarations.
    - T18 BindingResolutionTest: pass bogus adapter class string; assert FailedEvent row created with `subject_type=null, subject_id=null` (H-2 — legitimate null) + Log::critical called.
    - T19 HaltTest: register halting listener; assert no EventLog row + no HTTP call.
    - T20 HappyPathTest: full pipeline — race-fence wins + MetaClient mock returns 200 + after_dispatch listener invoked with response array; EventLog row exists with channel=capi.
    - T21 DeadLetterTest: MetaClient mock returns 400 → MetaApiPermanentException → FailedEvent row + dead_letter listener invoked. H-2 ASSERT: FailedEvent row has `subject_type='fake.subject', subject_id=42` (TestSubject default iId=42) populated from TestSubjectAdapter.
    - T22 TransientRetryTest: MetaClient mock returns 503 → MetaApiTransientException rethrown (sync mode rethrows; test asserts via expectException).
    - All 5 tests pass.
  </behavior>
  <action>
Common setUp/tearDown extracted into each test file (acceptable Phase 2 cost per the DRY violation note — plan 02-07 may consolidate when contract base lands):

```
protected function setUp(): void
{
    parent::setUp();
    $this->app->singleton(AdapterRegistry::class);
    (new CreateMetapixelEventLogTable)->up();
    (new CreateMetapixelFailedEventsTable)->up();
    app(AdapterRegistry::class)->register(TestSubject::class, TestSubjectAdapter::class);
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
```

makePayload() helper in each test file returns the standard envelope with the H-8-pattern bound registry already in place.

T18 `SendCapiEventBindingResolutionTest.php`:
- `test_bogus_adapter_class_triggers_failed_event_and_log_critical_with_null_subject_type` — `Log::shouldReceive('critical')->atLeast()->once()`; instantiate SendCapiEvent with adapter class 'NonExistent\Foo\BarAdapter'; call handle(); assert `DB::table('logingrupa_metapixel_failed_events')->count() === 1`; assert row's `adapter_type === 'NonExistent\Foo\BarAdapter'` AND `subject_type === null` AND `subject_id === null` (H-2 — legitimate null because adapter never resolved).

T19 `SendCapiEventHaltTest.php`:
- `test_halt_listener_skips_race_fence_and_http_call` — Event::listen halt; dispatch with SpyMetaClient; assert 0 EventLog rows + 0 SpyMetaClient.iCallCount.

T20 `SendCapiEventHappyPathTest.php`:
- `test_happy_path_writes_event_log_calls_meta_fires_after_dispatch` — Settings::set with non-empty creds; Event::listen after_dispatch captures responses array; Guzzle MockHandler returns 200 + `['events_received' => 1, 'fbtrace_id' => 'trace-1']`; dispatch; assert 1 EventLog row (channel=capi, event_name='Purchase'); assert after_dispatch received 1 response with `events_received === 1`.

T21 `SendCapiEventDeadLetterTest.php`:
- `test_permanent_failure_writes_failed_event_and_fires_dead_letter_with_h2_subject_type` — Settings::set with non-empty creds; Event::listen dead_letter captures invocations; Guzzle MockHandler returns 400; dispatch; assert 1 FailedEvent row with `http_status === 400`. H-2 ASSERT: `subject_type === 'fake.subject'` AND `subject_id === 42` (populated from TestSubjectAdapter — TestSubject default iId=42). Assert dead_letter listener invoked once.

T22 `SendCapiEventTransientRetryTest.php`:
- `test_transient_failure_rethrows_for_laravel_queue_retry` — Settings::set with creds; Guzzle MockHandler returns 503; `$this->expectException(MetaApiTransientException::class)`; dispatch (sync mode rethrows).

All 5 test files use H-8 setUp + H-6 imports + L-8 classic style. NO inline `class TestSubject {}` or `class TestSubjectAdapter {}` declarations — these come from `tests/Doubles/`.
  </action>
  <verify>
    <automated>for f in plugins/logingrupa/metapixel/tests/Feature/Queue/SendCapiEventBindingResolutionTest.php plugins/logingrupa/metapixel/tests/Feature/Queue/SendCapiEventHaltTest.php plugins/logingrupa/metapixel/tests/Feature/Queue/SendCapiEventHappyPathTest.php plugins/logingrupa/metapixel/tests/Feature/Queue/SendCapiEventDeadLetterTest.php plugins/logingrupa/metapixel/tests/Feature/Queue/SendCapiEventTransientRetryTest.php; do test -f "$f" || { echo "missing $f"; exit 1; }; php -l "$f" | grep -q 'No syntax errors' || exit 1; done &amp;&amp; ! grep -rE '\(new\s+\\?Logingrupa\\\\Metapixel\\\\Plugin\)' plugins/logingrupa/metapixel/tests/Feature/Queue/ &amp;&amp; ! grep -rE '^(final\s+)?class\s+(TestSubject|TestSubjectAdapter|SpyMetaClient)\s' plugins/logingrupa/metapixel/tests/Feature/Queue/ &amp;&amp; cd plugins/logingrupa/metapixel &amp;&amp; ../../../vendor/bin/pest tests/Feature/Queue --configuration phpunit.xml 2&gt;&amp;1 | tail -10 | grep -Eq '(PASS|OK|Tests:.*passed)'</automated>
  </verify>
  <done>5 feature test files exist + H-8 setUp + H-6 imports + L-8 classic style; pest tests/Feature/Queue exits 0; T21 verifies H-2 subject_type='fake.subject' populated from adapter.</done>
</task>

<task type="auto">
  <name>Task 4: composer qa + commit</name>
  <files>
    plugins/logingrupa/metapixel/classes/Queue/SendCapiEvent.php
    plugins/logingrupa/metapixel/tests/Unit/Hook/
    plugins/logingrupa/metapixel/tests/Feature/Queue/
  </files>
  <action>
From `plugins/logingrupa/metapixel/`:

```
composer qa 2>&1 | tee /tmp/02-06-qa.log | tail -30
```

Likely phpstan issues:
- `$this->arPayload['data'][0]['event_id'] ?? ''` chained array access on `array<string, mixed>` — narrow via `/** @var array{data: list<array<string, mixed>>} $arPayload */` PHPDoc on the constructor property.
- `Event::fire` return type `mixed` — already handled via `=== false` strict compare in fireBeforeDispatchHalt.
- `attempts()` from `InteractsWithQueue` trait may need PHPDoc on the property or method-call assertion.
- larastan may flag `FailedEvent::create(...)` as undefined static — add a class-level `@method static FailedEvent create(array $arAttributes)` PHPDoc on FailedEvent.php (plan 02-03a didn't ship it; Plan 02-06 may amend during this task).

Likely test issues:
- `$obSpyClient` extending MetaClient via shared `tests/Doubles/SpyMetaClient.php` (plan 02-05 Task 6); no inline declarations.
- Static `dataProvider` requirement for PHPUnit 12 — none of these tests use dataProviders.

Coverage:
- SendCapiEvent has ~13 branches (rehydrate hit/miss × halt/proceed × race-fence won/lost × HTTP success/transient/permanent × failed() retry-exhaustion × adapter resolved/null in writeFailedEvent). T18-T22 cover all primary paths; T11-T14 cover hook-specific branches.
- H-2 specifically covered by T18 (null subject_type/id) AND T21 (populated subject_type/id from TestSubjectAdapter).
- L-5 covered by failed() being called via Laravel's retry-exhaustion path — TODO: add a smoke test in plan 02-07's BackboneIntegrationTest for the serialize round-trip (M-5).
- Expected ≥ 95% on SendCapiEvent.

Commit:

```
git add plugins/logingrupa/metapixel/classes/Queue/ \
        plugins/logingrupa/metapixel/tests/Unit/Hook/ \
        plugins/logingrupa/metapixel/tests/Feature/Queue/

git commit -m "$(cat <<'EOF'
feat(metapixel): SendCapiEvent queue job + 3 Event::fire hooks (ADAP-04/05/10)

SendCapiEvent orchestrates the full Phase 2 backbone: AdapterRegistry::
resolveByClass rehydrates the adapter (BindingResolutionException →
FailedEvent with null subject_type/id [H-2 — adapter does not exist] +
Log::critical, no rethrow), SiteResolver::forSubject reads site_id from
the subject, EventLogWriter::record runs the UNIQUE race-fence,
Settings::lookupForSite resolves credentials, MetaClient::sendForPixel
POSTs to Graph v23.0. Transient failures rethrow for Laravel queue retry
(3 tries, [1,4,16]s backoff); permanent failures write FailedEvent +
fire dead_letter; happy path fires after_dispatch.

writeFailedEvent accepts ?EventSubjectAdapter \$obAdapter and populates
FailedEvent.subject_type + subject_id from it when non-null. Only the
BindingResolutionException path passes null. Phase 4 admin UI re-resolution
depends on these columns being populated (H-2 — was hardcoded null
originally).

failed() retry-exhaustion hook resolves the adapter via AdapterRegistry
the same way handle() does, then writes FailedEvent + fires dead_letter
(L-5 — keeps failed_events row state consistent across handle/failed paths).

Three Event::fire hooks: before_dispatch (halt-able via \$halt=true
Lovata idiom — listener returns false to veto), after_dispatch and
dead_letter (observe-only). Every fire site wraps Event::fire in
try/catch — throwable → Log::warning + treat as abstain/observed.

P-08 enforcement: fireBeforeDispatchHalt snapshots event_id +
event_time BEFORE firing the hook and RESTORES them after — a misbehaving
listener that mutates either field cannot silently break Meta dedup.
T12 asserts the snapshot+restore behaviour.

Four hook unit tests + five queue feature tests use shared doubles from
tests/Doubles/ (no inline classes) and the \$this->app->singleton
direct setUp pattern (no PluginBase instantiation TypeError).
EOF
)"
```
  </action>
  <verify>
    <automated>cd plugins/logingrupa/metapixel &amp;&amp; composer qa 2&gt;&amp;1 | tail -5 | grep -Eq '(OK|PASS|0 errors|tests passed|No issues found)' &amp;&amp; git log -1 --pretty=format:'%s' | grep -q 'SendCapiEvent' &amp;&amp; git diff-tree --no-commit-id --name-only -r HEAD | grep -c '^plugins/logingrupa/metapixel/' | xargs test 9 -le</automated>
  </verify>
  <done>composer qa exits 0; commit touches ≥ 9 files; commit message references ADAP-04/05/10 + P-08 + H-2 + L-5.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Queue serialize/deserialize → handle() | Job arguments cross the queue boundary. `SerializesModels` trait handles Eloquent models; plain objects use PHP serialize. Untrusted serialized data is a deserialize-RCE risk — but the queue source IS plugin-trusted (only SendCapiEvent::dispatch from plugin code pushes jobs). M-5 smoke test in plan 02-07 covers the production-path serialize round-trip. |
| Third-party Event::listen → SendCapiEvent::handle | Third-party listener exceptions caught + Log::warning + dispatch continues. Listener mutation of event_id is reverted by snapshot+restore. |
| MetaClient → graph.facebook.com | Plan 02-05 owns this boundary. SendCapiEvent calls sendForPixel and classifies the response (transient retry vs permanent dead-letter). |
| failed() retry-exhaustion → operator | Laravel calls failed() after $tries exhaustion. We resolve adapter (L-5) + write FailedEvent + fire dead_letter. Operator surface: Phase 4 admin UI (FAIL-01..03) + dead_letter listener (Phase 5 v2.x OPS-01 Slack/email fan-out). |
| writeFailedEvent → Phase 4 admin UI replay | H-2 — subject_type + subject_id columns populated from adapter enable Phase 4 to re-resolve the subject for replay. Null only on legitimate BindingResolutionException path. |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-02-06-01 | Tampering | A third-party before_dispatch listener mutates event_id to break Meta dedup | mitigate | Snapshot+restore in fireBeforeDispatchHalt reverts any event_id / event_time mutation. T12 enforces. PHPDoc on the hook explicitly documents the forbidden mutation. |
| T-02-06-02 | Spoofing | A queued job with a forged adapter class FQN triggers RCE via App::make | accept | App::make for an arbitrary class FQN that doesn't exist throws BindingResolutionException — we catch + dead-letter with null subject_type/id (H-2). If the FQN exists but isn't an adapter, AdapterRegistry::register has rejected it at registration time (plan 02-01 InvalidArgumentException). The only attack surface is "registered adapter A could be spoofed by adapter A's registration"-class — same risk as any plugin extensibility. |
| T-02-06-03 | Repudiation | Operator wonders why event for order X never reached Meta | mitigate | FailedEvent table is the audit trail with subject_type/subject_id populated (H-2) — Phase 4 admin UI surfaces and links to the original subject. dead_letter listener is the alert channel (Phase 5 OPS-01). EventLog table is the success audit. |
| T-02-06-04 | Information Disclosure | FailedEvent.payload may contain hashed PII | accept | sha256 of email/phone is not reversible — operator can see hashes but not raw values. Phase 4 admin UI displays "payload preview" not the full JSON (FAIL-01 column-spec). Phase 5 README documents PII handling. |
| T-02-06-05 | Denial of Service | Pathological retry storm — MetaApiTransient throws on every attempt, exhausting queue worker capacity | mitigate | $tries = 3 caps the retry; backoff [1, 4, 16]s caps the rate. failed() writes FailedEvent so retry state doesn't accumulate. |
| T-02-06-06 | Elevation of Privilege | A malicious adapter's getSecretKey returns a privileged value used for spoofing | accept | secret_key is opaque to the plugin — Phase 3 Shopaholic uses Order.secret_key (the order's URL slug). Different adapters' secret keys live in different rows; UNIQUE constraint doesn't index secret_key for uniqueness across adapters. No cross-adapter spoofing risk. |

</threat_model>

<verification>
## Goal-Backward Reachability Audit

1. "SendCapiEvent constructor takes (string, array, object, string) per D-20" — Task 1 implements.
2. "handle() resolves adapter via resolveByClass + catches BindingResolutionException → FailedEvent with null subject_type/id (H-2)" — Task 1 + T18.
3. "Other dispatch failure paths write FailedEvent with subject_type/id populated from adapter (H-2)" — Task 1 writeFailedEvent + T21 H-2 ASSERT.
4. "3 Event::fire hooks at decision boundaries" — Task 1 + T11–T14 unit tests + T19–T21 feature tests.
5. "before_dispatch halt-able; other 2 observe-only" — Task 1 (Event::fire third arg true ONLY on before_dispatch) + T11 + T19.
6. "Listener exceptions caught + Log::warning + dispatch continues (D-16, ADAP-05)" — Task 1 try/catch on every fire + T13.
7. "P-08: snapshot+restore event_id/event_time" — Task 1 + T12.
8. "Transient rethrow for retry, permanent → FailedEvent + dead_letter" — Task 1 + T21 + T22.
9. "failed() retry-exhaustion → adapter resolve (L-5) + FailedEvent + dead_letter" — Task 1's failed().
10. "composer qa exits 0" — Task 4.

No must-have is UNREACHABLE.

## Multi-Source Coverage Audit

| Source item | Type | Coverage | Notes |
|-------------|------|----------|-------|
| REQ ADAP-04 (3 hooks at decision boundaries) | Requirement | Task 1 (fire sites) + Tasks 2–3 (tests) | Owned |
| REQ ADAP-05 (listener exceptions caught + Log::warning + continue) | Requirement | Task 1 (try/catch wrappers) + T13 | Owned |
| REQ ADAP-10 (4th constructor arg + resolveByClass + BindingResolutionException → FailedEvent + Log::critical) | Requirement | Task 1 + T18 | Owned |
| CONTEXT D-15 (3 hooks; 5 deferred) | Decision | Task 1 fires 3, no 4th | Honored |
| CONTEXT D-16 (listener exceptions caught) | Decision | Task 1 try/catch | Honored |
| CONTEXT D-20 (SendCapiEvent signature + resolveByClass + BindingResolutionException) | Decision | Task 1 + T18 | Honored |
| RESEARCH §2 OQ-2 resolution | Decision | Task 1 Event::fire(name, payload, true) ONLY on before_dispatch | Honored verbatim |
| RESEARCH §4.7 SendCapiEvent shape | Reference | Task 1 | Code matches, with P-08 snapshot+restore extension + H-2 writeFailedEvent extension + L-5 failed() adapter-resolve |
| RESEARCH §6 T11–T14 hook unit tests | Reference | Task 2 | All 4 land; T14 verifies H-2 subject_type='fake.subject' populated |
| RESEARCH §6 T18–T22 queue feature tests | Reference | Task 3 | All 5 land; T18 verifies H-2 null path; T21 verifies H-2 populated path |
| PITFALLS P-08 (mutable hook payload) | Pitfall | Task 1 snapshot+restore + T12 | OWNED (enforced + tested) |
| Plan 02-03a FailedEvent + migration | Dependency | Task 1 imports + Tasks 2–3 use | Available (Wave 2 < Wave 4) |
| Plan 02-03b Settings + exceptions | Dependency | Task 1 imports | Available (Wave 2 < Wave 4) |
| Plan 02-04 EventLogWriter + SiteResolver | Dependency | Task 1 imports | Available (Wave 3 < Wave 4) |
| Plan 02-05 MetaClient + SpyMetaClient double | Dependency | Task 1 imports MetaClient; Tasks 2-3 import SpyMetaClient | Available (Wave 3 < Wave 4) |
| Plan-checker H-2 (FailedEvent subject_type/id populated) | Revision | Task 1 writeFailedEvent + T18 (null path) + T21 (populated path) | Resolved — writeFailedEvent accepts ?EventSubjectAdapter; populates from adapter when non-null; BindingResolutionException passes null |
| Plan-checker H-8 (Plugin instantiation in tests) | Revision | Tasks 2 + 3 setUp | All 9 tests use `$this->app->singleton(AdapterRegistry::class)` direct bind |
| Plan-checker H-6 (shared fixtures) | Revision | Tasks 2 + 3 imports | All 9 tests import TestSubject + TestSubjectAdapter + FakeStubAdapter + SpyMetaClient from `tests/Doubles/` — no inline classes |
| Plan-checker L-4 (Log facade FQN) | Revision | Task 1 + Tasks 2 + 3 | SendCapiEvent + all tests use `Illuminate\Support\Facades\Log` + `Illuminate\Support\Facades\Event` FQN |
| Plan-checker L-5 (failed() snapshot consistency) | Revision | Task 1 failed() | Resolves adapter via AdapterRegistry::resolveByClass same as handle(); passes to writeFailedEvent for H-2 subject_type/id population |
| Plan-checker L-8 (classic Pest style) | Revision | Tasks 2 + 3 | All 9 test files use `final class FooTest extends MetapixelTestCase` |

No gaps.

## Acceptance gate

`composer qa` exits 0 from `plugins/logingrupa/metapixel/` after Task 4's commit.
</verification>

<success_criteria>
Plan 02-06 ships when ALL of the following hold:

1. `classes/Queue/SendCapiEvent.php` is final + implements ShouldQueue + uses standard Laravel-job traits + has 4-arg constructor + 3 hook constants + handle() + failed() + fireBeforeDispatchHalt (with snapshot+restore) + fireAfterDispatch + fireDeadLetter + writeFailedEvent(`?EventSubjectAdapter $obAdapter`) helpers.
2. writeFailedEvent populates `subject_type` + `subject_id` from adapter when non-null (H-2); null only on legitimate BindingResolutionException path.
3. failed() resolves adapter via AdapterRegistry::resolveByClass (L-5) + passes to writeFailedEvent for consistency.
4. SendCapiEvent contains no banned Site/SiteManager/Request/request() calls (PHPStan-enforced).
5. SendCapiEvent imports `Illuminate\Support\Facades\Log` + `Illuminate\Support\Facades\Event` FQN (L-4).
6. All 4 hook unit tests pass (T11 halt + T12 mutation×2 + T13 isolation + T14 dead-letter); use H-8 setUp + H-6 shared doubles; T14 asserts H-2 subject_type populated.
7. All 5 queue feature tests pass (T18 binding + T19 halt + T20 happy + T21 dead-letter + T22 transient); use H-8 setUp + H-6 shared doubles; T18 asserts H-2 null subject_type; T21 asserts H-2 populated subject_type='fake.subject'.
8. composer qa exits 0; SendCapiEvent.php coverage ≥ 95%.
9. Single commit on HEAD references ADAP-04/05/10 + P-08 + H-2 + L-5.
10. No comment pollution in source.
</success_criteria>

<output>
After completion, create `plugins/logingrupa/metapixel/.planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-06-SUMMARY.md` documenting:

- Single commit SHA.
- composer qa output tail.
- Test counts: 4 hook unit tests (≥ 5 methods), 5 queue feature tests (≥ 5 methods).
- Coverage on SendCapiEvent.php (expected ≥ 95%).
- Confirm P-08 enforcement: T12 outgoing event_id matches original after listener mutation attempt.
- Confirm H-2 path: T18 FailedEvent row has null subject_type (BindingResolutionException path); T21 FailedEvent row has subject_type='fake.subject' (resolved-adapter path).
- Confirm L-5 path: failed() resolves adapter via AdapterRegistry::resolveByClass and passes to writeFailedEvent.
- Confirm OQ-2 resolution: T11 halt prevents EventLog write + HTTP call; T13 throwing listener does not halt dispatch.
- Phase 2 plan-state update: 02-06 closed; plan 02-07 (FakeAdapter + ContractTestCase + smoke) Wave 5 now ready — final plan of Phase 2.
</output>

## Revision History
- 2026-05-17 R1: Address plan-checker findings H-2 (Task 1 writeFailedEvent accepts `?EventSubjectAdapter $obAdapter = null` parameter — populates `subject_type` + `subject_id` from adapter when non-null; BindingResolutionException early-return path passes null per H-2 spec; T18 verifies null path, T21 + T14 verify populated path for Phase 4 admin UI re-resolution), H-8 (all 9 test setUps use `$this->app->singleton(AdapterRegistry::class)` direct bind — never `(new Plugin)->register()`), H-6 (all 9 test files import TestSubject + TestSubjectAdapter + FakeStubAdapter + SpyMetaClient from `tests/Doubles/` — no inline class declarations), L-4 (SendCapiEvent + all tests use `Illuminate\Support\Facades\Log` + `Illuminate\Support\Facades\Event` FQN), L-5 (Task 1 failed() resolves adapter via AdapterRegistry::resolveByClass + passes to writeFailedEvent — same pattern as handle() for failed_events row consistency), L-8 (Tasks 2 + 3 confirm classic Pest style).
