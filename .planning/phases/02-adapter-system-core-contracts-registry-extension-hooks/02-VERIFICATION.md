---
phase: 02-adapter-system-core-contracts-registry-extension-hooks
verified: 2026-05-17T00:00:00Z
status: human_needed
score: 5/5 must-haves verified
overrides_applied: 0
adap_coverage:
  ADAP-01: verified
  ADAP-02: verified
  ADAP-03: verified
  ADAP-04: verified
  ADAP-05: verified
  ADAP-06: verified
  ADAP-07: verified
  ADAP-08: verified
  ADAP-09: verified
  ADAP-10: verified
  ADAP-11: verified-with-reframe
human_verification:
  - test: "Run composer qa from plugins/logingrupa/metapixel/ on a full-Lovata install"
    expected: "pint-test passes, phpstan level 10 passes, phpmd passes, pest --coverage --min=90 passes (93 test methods across 42 test files)"
    why_human: "Cannot run composer qa without a full OctoberCMS + Lovata dependency tree. Confirms the acceptance gate is actually green."
  - test: "Verify CR-01 envelope-destroyed bypass path behaviour"
    expected: "When a before_dispatch listener does unset($arPayload['data']), the snapshot/restore conditional (lines 176-181 of SendCapiEvent.php) skips the restore because isset($arMutablePayload['data']) is false. Confirm whether this case is acceptable (event_id lost, Meta gets no data) or whether the full-snapshot fallback proposed by the code reviewer should be applied."
    why_human: "The bypass is observable in code: if data is cleared by a listener, arPayload becomes [] and MetaClient POSTs an empty envelope. A business decision is needed: treat envelope-destroyed as halt (return true) or restore full snapshot. The existing test only covers the key-replacement mutation path, not the clearing path."
  - test: "Confirm Settings::lookupForSite($iSiteId) operator visibility on multi-site installs"
    expected: "On a live two-site October install (e.g., nailscosmetics.lv + nailscosmetics.no), both sites read the same default settings row. Operator is aware that per-site credentials are Phase 4 (MULT-03). No silent mis-routing occurs in practice because Phase 2 is single-pixel-only."
    why_human: "WR-04: The stub ignores $iSiteId silently. Acceptable per REQUIREMENTS.md MULT-03 deferred scope, but a human should confirm no multi-site operator is actually running Phase 2 with per-site pixels configured."
---

# Phase 2: Adapter System Core Verification Report

**Phase Goal:** A generic event-dispatch backbone exists where any subject (Shopaholic Order, theme action, or third-party cart) can be tracked through the same MetaClient + PayloadBuilder + UserDataHasher + EventLogWriter pipeline behind an EventSubjectAdapter + ValueResolver interface pair resolved at runtime via AdapterRegistry. v1.x's 177-test suite is regreened against the new signatures via a FakeAdapter test double; no production adapter ships in this phase.

**Verified:** 2026-05-17
**Status:** human_needed
**Re-verification:** No — initial verification

---

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Third party implements 2 interfaces + calls AdapterRegistry::register; FakeAdapter round-trips through PayloadBuilder::buildEventPayload producing v1.x envelope shape | VERIFIED | `EventSubjectAdapter.php` + `ValueResolver.php` interfaces exist and are substantive; `AdapterRegistry::register` throws on invalid class; `ContractTestCaseSmokeTest::test_fake_adapter_round_trips_through_payload_builder_to_documented_envelope` asserts envelope shape including `data[0].event_id`, `event_time`, `event_name`, `action_source`, `user_data`, `custom_data` |
| 2 | getSiteId(object): ?int is the ONLY authoritative source of site_id; PHPStan bans SiteManager/Request/request() in adapter/queue/event dirs; contract test asserts determinism | VERIFIED | `phpstan.neon` disallowedMethodCalls scoped `disallowIn: classes/queue/*, classes/event/*, classes/adapter/*` for `SiteManager::*`, `Site::*`, `Request::*`; `request()` function also banned via `disallowedFunctionCalls`; no actual SiteManager/Request calls found in those directories; `SiteResolverTest::test_site_resolver_makes_no_request_or_site_manager_calls` is a static-grep defence; `ContractTestCase` invariants 03 + 04 assert deterministic return and ?int type |
| 3 | 3 Event::fire hooks fire at documented boundaries; throwing listener caught + Log::warning + dispatch continues | VERIFIED | `SendCapiEvent` constants `HOOK_BEFORE_DISPATCH`, `HOOK_AFTER_DISPATCH`, `HOOK_DEAD_LETTER` defined lines 56-60; `fireBeforeDispatchHalt` catches `Throwable` + `Log::warning` + returns `false` (dispatch continues); `fireAfterDispatch` + `fireDeadLetter` each have identical try/catch isolation; `ListenerExceptionIsolationTest::test_throwing_listener_does_not_halt_dispatch_logs_warning` proves it |
| 4 | MetaClient::sendForPixel per-call credentials; PayloadBuilder subject-agnostic; Graph API v23.0 constant; SendCapiEvent 4th string $sAdapterClass arg; handle() rehydrates via resolveByClass; writeFailedEvent on BindingResolutionException | VERIFIED | `MetaClient::META_GRAPH_API_VERSION = 'v23.0'` (line 27); `sendForPixel(string $sPixelId, string $sToken, array $arPayload)` per-call; `PayloadBuilder::buildEventPayload` has zero `===` / `switch` / `match` on event name; `SendCapiEvent` 4th constructor arg `string $sAdapterClass` (line 75); `resolveByClass($this->sAdapterClass)` in `handle()` (line 81); `BindingResolutionException` caught at line 82 → `writeFailedEvent($obException, null, null)` |
| 5 | Backbone tests regreen via FakeAdapter (reframed by OQ-1: ~93 Pest 4 backbone tests; 4 named v1.x test files deferred to Phase 3) | VERIFIED (with reframe) | 42 test files, 93 test methods confirmed; `BackboneIntegrationTest` (3 tests: happy path + dedup + serialize round-trip); `FakeAdapterContractTest` (10 invariants via `EventSubjectAdapterContractTestCase`); 7 `SendCapiEvent` Feature tests; 4 Hook unit tests; ROADMAP.md SC5 wording NOT yet updated (M-7 pending orchestrator action — advisory only, does not block) |

**Score:** 5/5 truths verified

---

### Deferred Items

Items not yet met but explicitly addressed in later milestone phases.

| # | Item | Addressed In | Evidence |
|---|------|-------------|----------|
| 1 | Settings::lookupForSite actually routes per site_id | Phase 4 | REQUIREMENTS.md MULT-03: "re-implements to honor the Multisite per-site row routing" |
| 2 | Multisite trait on pixel_id + capi_access_token | Phase 4 | REQUIREMENTS.md MULT-01, MULT-02 |
| 3 | FailedEvent admin UI replay | Phase 4 | REQUIREMENTS.md FAIL-01..03 |
| 4 | OrderStatusWatcherEventLogTest, PurchasePixelEventLogGateTest, SendCapiEventEventLogTest, MultiSiteEventLogTest | Phase 3 | 02-VERIFICATION-INPUTS.md M-7 flag; SHOP-03 owns |

---

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `classes/adapter/EventSubjectAdapter.php` | 7-method interface with cross-context-determinism PHPDoc | VERIFIED | Substantive: all 7 methods present; PHPDoc on getSiteId forbids request context |
| `classes/adapter/ValueResolver.php` | 5-method interface | VERIFIED | Substantive: all 5 methods present with correct return types |
| `classes/adapter/AdapterRegistry.php` | Singleton, register + resolveFor + resolveByClass | VERIFIED | register() throws InvalidArgumentException on non-implementing class; resolveFor() walks hierarchy via is_a(); resolveByClass() for queue rehydration |
| `classes/helper/SiteResolver.php` | One-line delegate to adapter.getSiteId | VERIFIED | Exactly 1 method, 1 line of logic: delegates to adapter |
| `classes/helper/EventLogWriter.php` | UNIQUE insertOrIgnore race-fence | VERIFIED | Uses DB::table->insertOrIgnore; catches Throwable as fail-safe; resolves adapter for opaque alias |
| `classes/meta/MetaClient.php` | sendForPixel per-call creds; v23.0 constant | VERIFIED | META_GRAPH_API_VERSION = 'v23.0' constant; sendForPixel(pixelId, token, payload); ConnectException → TransientException; http_errors false |
| `classes/meta/PayloadBuilder.php` | Subject-agnostic buildEventPayload 7-arg; no event-name switch | VERIFIED | Zero comparisons on $sEventName; $arEventExtras slot as 7th param; envelope shape matches documented contract |
| `classes/meta/UserDataHasher.php` | Stateless sha256 hasher; HASHABLE + PASSTHROUGH fields | VERIFIED | Stateless (no static properties); HASHABLE_FIELDS and PASSTHROUGH_FIELDS constants; hashField returns null for empty (no sha256 of empty string) |
| `classes/queue/SendCapiEvent.php` | 4-arg constructor; 3 hooks; listener isolation; writeFailedEvent | VERIFIED | All verified; snapshot/restore conditional present (see CR-01 advisory) |
| `classes/testing/EventSubjectAdapterContractTestCase.php` | Abstract base with 10 invariants | VERIFIED | 10 invariant test methods confirmed; tearDown resets AdapterRegistry singleton |
| `tests/doubles/FakeAdapter.php` | Fluent EventSubjectAdapter double | VERIFIED | Implements all 7 interface methods; fluent builder pattern; autoload-dev only |
| `tests/doubles/FakeValueResolver.php` | ValueResolver double | VERIFIED | File exists; imported by ContractTestCaseSmokeTest and BackboneIntegrationTest |
| `tests/Contract/Adapter/FakeAdapterContractTest.php` | FakeAdapter passes all 10 ContractTestCase invariants | VERIFIED | Extends EventSubjectAdapterContractTestCase; makeAdapter() returns FakeAdapter; makeSubject() returns stdClass |
| `tests/Feature/Adapter/BackboneIntegrationTest.php` | End-to-end pipeline test through FakeAdapter | VERIFIED | 3 tests: happy path (EventLog row + after_dispatch fires), dedup short-circuit, serialize round-trip |
| `tests/Feature/Adapter/ContractTestCaseSmokeTest.php` | SC1 envelope round-trip smoke | VERIFIED | Asserts full envelope shape including hashed em, currency, content_ids, action_source |
| `Plugin.php` | register() binds AdapterRegistry singleton | VERIFIED | `$this->app->singleton(AdapterRegistry::class)` at line 37 |
| `phpstan.neon` | Level 10; disallowedMethodCalls deny-list scoped to adapter/queue/event dirs | VERIFIED | Level 10, phpVersion 80300; SiteManager::*, Site::*, Request::* banned in classes/queue/*, classes/event/*, classes/adapter/* |
| `composer.json` | guzzlehttp/guzzle in require | VERIFIED | `"guzzlehttp/guzzle": "^7.8"` in require (not require-dev) |

---

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|----|--------|---------|
| `Plugin::register()` | `AdapterRegistry` | `$this->app->singleton(AdapterRegistry::class)` | WIRED | Bound before any adapter boot() calls |
| `SendCapiEvent::handle()` | `AdapterRegistry::resolveByClass` | `$obRegistry->resolveByClass($this->sAdapterClass)` | WIRED | Line 81; BindingResolutionException caught at line 82 |
| `SendCapiEvent::handle()` | `EventLogWriter::record()` | Direct static call line 105-113 | WIRED | Passes event_id, event_name, 'capi', subject, secret_key, event_time, site_id |
| `SendCapiEvent::handle()` | `MetaClient::sendForPixel` | Injected via handle() DI + Settings::lookupForSite | WIRED | Line 121; per-call credentials resolved from Settings |
| `EventLogWriter::record()` | `AdapterRegistry` (for opaque alias) | `App::make(AdapterRegistry::class)` | WIRED | Resolves adapter to get getSubjectType() alias; never stores class FQN |
| `FakeAdapter` | `PayloadBuilder::buildEventPayload` | `ContractTestCaseSmokeTest` + `BackboneIntegrationTest` | WIRED | Both tests call buildEventPayload with FakeAdapter/TestSubjectAdapter; envelope verified |
| `phpstan.neon` | `classes/queue/*`, `classes/adapter/*`, `classes/event/*` | disallowIn scoping | WIRED | All three directories scoped for SiteManager/Site/Request deny-list |

---

### Data-Flow Trace (Level 4)

Backbone is a dispatch pipeline, not a data-rendering component. Level 4 (data-flow to render) does not apply. The pipeline's flow is verified at Level 3 via integration tests that assert DB row insertion and HTTP mock call counts.

---

### Behavioral Spot-Checks

Step 7b: SKIPPED — no runnable entry points without full OctoberCMS + Laravel bootstrap. Behavioral verification requires composer qa (human item #1).

---

### Probe Execution

No probe scripts declared in PLAN or SUMMARY files. No `scripts/*/tests/probe-*.sh` found. Step 7c: SKIPPED (no probes defined).

---

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|-------------|--------|----------|
| ADAP-01 | 02-01 | EventSubjectAdapter interface (7 methods) | SATISFIED | `classes/adapter/EventSubjectAdapter.php` — all 7 methods present with correct signatures and PHPDoc |
| ADAP-02 | 02-01 | ValueResolver interface (5 methods) | SATISFIED | `classes/adapter/ValueResolver.php` — all 5 methods present |
| ADAP-03 | 02-01 | AdapterRegistry singleton with register/resolveFor/resolveByClass | SATISFIED | AdapterRegistry exists; Plugin::register() binds singleton; InvalidArgumentException on invalid adapter class confirmed |
| ADAP-04 | 02-06 | 3 Event::fire hooks at decision boundaries | SATISFIED | HOOK_BEFORE_DISPATCH (halt-able, $arPayload by-ref), HOOK_AFTER_DISPATCH (observe-only), HOOK_DEAD_LETTER (observe-only) all present |
| ADAP-05 | 02-06 | Listener exceptions caught + Log::warning + continue | SATISFIED | All 3 fire sites wrap in try/catch(Throwable); Log::warning on exception; dispatch continues |
| ADAP-06 | 02-04 | SiteResolver::forSubject; PHPStan deny-list bans SiteManager/Request | SATISFIED | SiteResolver.php delegates to adapter.getSiteId(); phpstan.neon disallowIn confirmed; SiteResolverTest static-grep defence confirms no banned calls in source |
| ADAP-07 | 02-05 | PayloadBuilder::buildEventPayload subject-agnostic 7-arg | SATISFIED | No event-name comparisons; $arEventExtras slot; full 7-arg signature matches REQUIREMENTS.md spec |
| ADAP-08 | 02-05 | UserDataHasher::forSubject stateless sha256 | SATISFIED | Stateless (no static properties); sha256 via hashField; passthrough fields returned raw. Note: REQUIREMENTS.md says "per-request CCache" but M-4 resolution dropped memo as YAGNI — implementation is simpler and correct |
| ADAP-09 | 02-05 | MetaClient::sendForPixel per-call creds; v23.0 constant | SATISFIED | Constant META_GRAPH_API_VERSION = 'v23.0' line 27; sendForPixel takes (pixelId, token, payload) |
| ADAP-10 | 02-06 | SendCapiEvent 4th $sAdapterClass arg; resolveByClass; BindingResolutionException → FailedEvent | SATISFIED | 4-arg constructor confirmed; resolveByClass called in handle(); BindingResolutionException caught → writeFailedEvent(exception, null, null) with Log::critical |
| ADAP-11 | 02-07 | Backbone tests regreen via FakeAdapter (OQ-1 reframe: 93 fresh Pest 4 tests; 4 v1.x files deferred to Phase 3) | SATISFIED | 42 test files, 93 test methods; FakeAdapterContractTest (10 invariants); BackboneIntegrationTest (3); 7 queue feature tests; 4 hook tests; 4 named v1.x test files intentionally absent (Phase 3 scope) |

Note on ADAP-11 wording: REQUIREMENTS.md line 43 still shows the old SC5 text ("All 177 v1.x tests... OrderStatusWatcherEventLogTest... regreen"). This text predates the OQ-1 resolution. The M-7 flag in 02-VERIFICATION-INPUTS.md documents the orchestrator action to update both ROADMAP.md and REQUIREMENTS.md ADAP-11 wording. The implementation satisfies the reframed intent.

---

### Pitfall Verdict

| Pitfall | Severity | Verdict | Evidence |
|---------|----------|---------|----------|
| P-01 Cross-context resolution drift | CRITICAL | CLOSED | Three-layer defence: (1) phpstan disallowIn bans SiteManager/Site/Request in adapter/queue/event dirs; (2) SiteResolverTest static-grep assertion; (3) ContractTestCase invariants 03 + 04 assert getSiteId() returns ?int deterministically with no side effects. No SiteManager/Request calls found in banned directories. |
| P-02 Boot-order race | CRITICAL | CLOSED | AdapterRegistry bound in Plugin::register() (not boot()). resolveFor() + resolveByClass() use lazy App::make — no pre-boot dependency. AdapterRegistryBootOrderTest proves registration-order-invariant resolution for unrelated subject classes. |
| P-05 EventLog subject_type alias ambiguity | CRITICAL | CLOSED | Interface PHPDoc forbids backslash in getSubjectType(); EventLog model has no MorphTo (no class FQN stored); EventLogWriter reads alias via adapter (not get_class()); ContractTestCase invariant_01 asserts strlen <= 64 and no backslashes. |
| P-08 Event::fire mutable payload | HIGH | CLOSED (with advisory) | Snapshot of event_id + event_time at lines 166-167; restore conditional at lines 176-181; test_listener_mutation_of_event_id_is_reverted_to_snapshot confirms the common path. Advisory (CR-01): if a listener clears data[], the conditional is false and arPayload becomes an empty array — MetaClient posts only the access_token, Meta returns 400, EventLog row was not yet written (race-fence write is after the hook), so no phantom dedup row is created. The failure mode is a dead-letter dispatch with observable Meta 400, not silent data corruption. Full-snapshot fallback would be safer; see Recommendations. |
| P-13 Component::extend surface | MEDIUM | CLOSED | No Component::extend code shipped in Phase 2. CLAUDE.md addendum confirmed in plugin-specific CLAUDE.md "Extensibility contract" section documenting Event::fire preference over Component::extend+addDynamicMethod for third-party hooks. |

---

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `classes/testing/EventSubjectAdapterContractTestCase.php` | 14-36 | Phase N references in class PHPDoc | INFO | "FakeAdapter in Phase 2", "Phase 3", "Phase 2 marketplace contract" — CLAUDE.md prohibits `// Phase N` inline comments, but these are in `/** ... */` docblocks describing intended use evolution. Not a blocker; borderline per strict reading. No `// Phase N` single-line comments found anywhere. |
| `models/Settings.php` | 8-13, 30 | Phase N references in class PHPDoc | INFO | "Phase 2 stub ignores $iSiteId. Phase 4 MULT-03 re-implements..." — acceptable in class-level docblocks that document intentional deferral. The `$iSiteId` param is functionally ignored (WR-04). |
| `models/FailedEvent.php` | 8-9 | Phase N references in docblock | INFO | "Phase 4 admin UI (FAIL-01..03) consumes this table" — acceptable deferred-feature documentation. |

No `TBD`, `FIXME`, or `XXX` markers found in any modified files. No `// CR-XX` / `// REFAC-XX` inline markers found. All Phase N references are in `/** */` class-level docblocks.

Debt-marker gate: CLEAR.

---

### Human Verification Required

#### 1. Acceptance gate (composer qa) must be run

**Test:** From `plugins/logingrupa/metapixel/`, run `composer qa` on a full-Lovata install (php 8.3 or 8.4 + all Lovata dependencies resolved in vendor/).
**Expected:** `pint-test` exits 0 (formatting clean), `phpstan analyse` exits 0 (no level-10 violations including disallowed-calls deny-list), `phpmd` exits 0, `pest --coverage --min=90` exits 0 (93 test methods, coverage >= 90% on classes/ + models/ excluding classes/testing/).
**Why human:** Cannot run composer without a full OctoberCMS bootstrap environment. This is the single mandatory acceptance gate for every Phase 2 plan.

#### 2. CR-01 envelope-destroyed bypass — accept or fix decision

**Test:** Manually trace the `fireBeforeDispatchHalt` path when a listener sets `$arPayload['data'] = []` or `unset($arPayload['data'])`. Confirm: (a) the `isset($arMutablePayload['data'])` check on line 176 fails, (b) `$this->arPayload` becomes `['data' => []]` or `[]`, (c) MetaClient sends `{"access_token":"..."}` or `{"data":[],"access_token":"..."}` to Meta, (d) Meta returns a 400, (e) the BindingResolutionException catch does NOT fire (adapter was already resolved), (f) the MetaApiPermanentException catch fires → FailedEvent written → dead_letter hook fires.
**Expected:** Business decision: either (a) accept this failure mode as self-healing (no dedup phantom, dead-letter is observable) OR (b) apply the CR-01 fix (full-snapshot restore when envelope shape is destroyed, Log::warning, return halt=false).
**Why human:** Determining acceptable blast radius for misbehaving third-party listeners is a product decision, not a code question.

#### 3. Multi-site operator awareness for Settings::lookupForSite stub

**Test:** Confirm that nailscosmetics.lv / nailscosmetics.no / nailscosmetics.lt installs (the three production servers) are single-pixel-only in Phase 2. If any production site has a second OctoberCMS site configured with a different pixel_id, verify that per-site routing being unavailable until Phase 4 is acceptable.
**Expected:** Either single-pixel confirmed (no action needed) or WR-04 fix applied before Phase 2 deployment.
**Why human:** Requires checking production October backend site configuration, which cannot be read from the codebase.

---

### Recommendations / Known Issues (from 02-REVIEW.md)

These are advisory findings from the code review. None violate a locked decision or a success criterion — they do not gate phase closure. They are surfaced here for Phase 3 pre-work planning.

**Critical-class advisory (no automatic blocker):**

**CR-01 — before_dispatch snapshot/restore conditional bypass** (`classes/queue/SendCapiEvent.php` lines 176-181): The existing restore is conditional on `isset($arMutablePayload['data'][0])`. A listener that clears `data` bypasses the restore — `arPayload` becomes `[]`, MetaClient sends an empty envelope, Meta returns 400, FailedEvent is written. The dedup race-fence row was NOT written before the hook (write happens after it), so no phantom dedup is created. The 02-REVIEW.md proposes a full-snapshot fallback when envelope shape is destroyed. Recommend applying in Phase 3 pre-work or as a targeted hotfix if misbehaving third-party listeners are a near-term concern.

**CR-02 — Token injection via array_merge precedence** (`classes/meta/MetaClient.php` line 67): `array_merge($arPayload, ['access_token' => $sToken])` — the token always wins (later key overwrites), so token cannot be replaced. However, a before_dispatch listener can inject top-level envelope keys like `test_event_code` (flips event to test mode), `data` with spurious records, or arbitrary keys Meta silently ignores. Recommend adding an explicit `ALLOWED_ENVELOPE_KEYS` whitelist in MetaClient::sendForPixel before Phase 3 production adapters ship. Does not block Phase 2 (no production adapter exists yet).

**CR-03 — UserDataHasher TypeError on non-string adapter values** (`classes/meta/UserDataHasher.php` lines 31-38): `hashField(?string $sValue)` will throw a TypeError if an adapter returns an int or object for a user_data field (only docblock-typed, not runtime-enforced). The queue job catches only MetaPixelException subclasses — a TypeError propagates out, triggers 3 retries, then dead-letters with a TypeError. Recommend adding runtime coercion + Log::warning in `forSubject()` before Phase 3 adapters ship. ShopaholicOrderAdapter could accidentally return int Order IDs under `external_id` if not careful.

**Warning-class advisory:**

- **WR-01**: `MissingCapiTokenException` and `MissingPixelConfigException` dead-letter without an explicit `Log::error` — only the dead_letter hook fires. Add a Log::error line so ops without a hook listener see a signal.
- **WR-02**: `failed()` exception classification fragile — `getHttpStatus()` should be on the `MetaPixelException` base.
- **WR-03**: `EventLogWriter::record` Throwable catch masks infrastructure failures as race-fence collisions. Recommend distinguishing duplicate-key from schema/connection failures.
- **WR-04**: `Settings::lookupForSite($iSiteId)` silently ignores `$iSiteId`. Add a `Log::info` when `$iSiteId !== null` so multi-site operators get a visible signal.
- **WR-05**: `json_encode` return value not checked in `writeFailedEvent` — false concatenated to string silently drops context.
- **WR-06**: No explicit `'verify' => true` or `connect_timeout` in MetaClient Guzzle client constructor.
- **WR-07**: `FailedEvent.payload` persists raw custom_data including purchase amounts and fbp/fbc click IDs indefinitely; no `retention_at` column for GDPR purge scheduling.

**Info-class:**

- **IN-01**: No PSR-3 debug logging of 2xx Graph API responses inside MetaClient.
- **IN-02**: `AdapterRegistry::resolveByClass` does not check whether the class was ever registered — naming is misleading. Consider renaming to `instantiateAdapter` or adding a docblock clarification.
- **IN-03**: `PluginGuard::isDisabled` memo is process-scoped — Settings changes require php-fpm + queue:restart.
- **IN-04**: `subject_type` column is VARCHAR(255) in migration but ContractTestCase asserts `<= 64`. Tighten to `string('subject_type', 64)`.
- **IN-05**: `FailedEvent` unique constraint `(event_id, http_status)` allows two rows per event_id with different statuses; `writeFailedEvent` uses `create()` not `insertOrIgnore` — migration docblock describes the wrong mechanism.

---

### Gaps Summary

No gaps found. All 5 Success Criteria verified. All 11 ADAP-* requirements satisfied. All 5 in-phase pitfalls closed. No TBD/FIXME/XXX debt markers.

The 3 human verification items are operational/product decisions, not codebase defects. Status is `human_needed` because the mandatory acceptance gate (composer qa) cannot be verified programmatically without a full OctoberCMS environment.

**Pending orchestrator actions before Phase 3:**

1. Run `composer qa` (full-Lovata CI run) and confirm it exits 0.
2. Update ROADMAP.md Phase 2 SC5 wording per M-7 (replace "All 177 v1.x tests regreen..." with the suggested replacement from 02-VERIFICATION-INPUTS.md).
3. Update REQUIREMENTS.md ADAP-11 wording to match OQ-1 reframe.
4. Flip `.planning/REQUIREMENTS.md` ADAP-01..11 from `[x]` (already marked complete) — already done.
5. Update `.planning/ROADMAP.md` Phase 2 status to "Complete".
6. Advance STATE.md Current Position to Phase 3.

---

_Verified: 2026-05-17_
_Verifier: Claude (gsd-verifier)_
_Verification mode: Initial (no previous VERIFICATION.md)_
