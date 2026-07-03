# Phase 02 — Verification Inputs (for `/gsd:verify-phase`)

Phase: 02-adapter-system-core-contracts-registry-extension-hooks
Authored by: plan 02-07 closure (M-7 flag included)
Status: ready for gsd-verifier handoff
Last updated: 2026-05-17

This document is the structured evidence trail the gsd-verifier consumes when
running `/gsd:verify-phase 02-adapter-system-core-contracts-registry-extension-hooks`.
The verifier produces `02-VERIFICATION.md` keyed against these checklists.

---

## ROADMAP.md SC5 mismatch (M-7 — orchestrator action)

ROADMAP.md Phase 2 SC5 currently reads:

> "All 177 v1.x tests regreen via a FakeAdapter test double standing in for ShopaholicOrderAdapter. `OrderStatusWatcherEventLogTest`, `PurchasePixelEventLogGateTest`, `SendCapiEventEventLogTest`, `MultiSiteEventLogTest` pass without touching real Lovata Order code."

**OQ-1 RESOLUTION reframes this:**

- The "177" target is wrong. Phase 2 deliberately did NOT port v1.x tests (D-01 all-fresh decision).
- Phase 2 landed ~100+ backbone-only Pest 4 tests across plans 02-01..02-07 (final count emitted to plan 02-07 SUMMARY).
- The 4 named test files (`OrderStatusWatcherEventLogTest`, `PurchasePixelEventLogGateTest`, `SendCapiEventEventLogTest`, `MultiSiteEventLogTest`) are Phase 3 work — they require ShopaholicOrderAdapter (SHOP-03 owns).

**Orchestrator action:** Update ROADMAP.md SC5 wording to reflect the OQ-1 reframe before Phase 2 closure. Plan 02-07 does NOT modify ROADMAP.md itself (M-7 — flag only).

Suggested replacement wording:

> "Backbone tests adapt via the `FakeAdapter` test double standing in for any concrete adapter. `BackboneIntegrationTest`, `FakeAdapterContractTest`, `ContractTestCaseSmokeTest` plus the queue + hook + helper + meta suites all pass without touching real Lovata Order code. The 4 v1.x-named test files (`OrderStatusWatcherEventLogTest`, `PurchasePixelEventLogGateTest`, `SendCapiEventEventLogTest`, `MultiSiteEventLogTest`) move to Phase 3 alongside ShopaholicOrderAdapter (SHOP-03)."

---

## Success Criteria evidence

### SC1 — `EventSubjectAdapter` + `ValueResolver` round-trip envelope

> A developer writing a new adapter implements two interfaces (`EventSubjectAdapter` + `ValueResolver`) and calls `AdapterRegistry::register($sSubjectClass, $sAdapterClass)` from their plugin's `boot()`; no plugin core change required. The contract test `FakeAdapter` round-trips through `PayloadBuilder::buildEventPayload()` and produces the same envelope shape v1.x produced for Order.

| Evidence | Location | Status |
|----------|----------|--------|
| `EventSubjectAdapter` interface | `classes/adapter/EventSubjectAdapter.php` | Closed (plan 02-01) |
| `ValueResolver` interface | `classes/adapter/ValueResolver.php` | Closed (plan 02-01) |
| `AdapterRegistry::register` + idempotency | `classes/adapter/AdapterRegistry.php` | Closed (plan 02-01) |
| FakeAdapter round-trip through PayloadBuilder | `tests/Feature/Adapter/ContractTestCaseSmokeTest.php::test_fake_adapter_round_trips_through_payload_builder_to_documented_envelope` | Closed (plan 02-07) |
| Envelope shape matches documented contract | `classes/meta/PayloadBuilder.php` returns `['data' => [{event_id, event_time, event_name, action_source, user_data, custom_data}]]` | Closed (plan 02-05) |
| `Plugin::register()` binds AdapterRegistry singleton | `Plugin.php::register()` | Closed (plan 02-01) |

### SC2 — `getSiteId()` is the only authoritative `site_id` source (P-01)

> `EventSubjectAdapter::getSiteId(object $obSubject): ?int` is enforced as the only authoritative source of `site_id` — PHPStan disallowed-calls rules in `Classes\Queue\`, `Classes\Event\`, `Classes\Adapter\` ban `SiteManager::*`, `request()`, `Request::*`, and an integration contract test asserts `getSiteId()` returns the same value regardless of `Site::setSite($i)` active context.

| Evidence | Location | Status |
|----------|----------|--------|
| PHPStan disallowed-calls deny-list scoped to `classes/queue/*`, `classes/event/*`, `classes/adapter/*` | `phpstan.neon` (Site / SiteManager / Request) | Closed (plan 02-02) |
| `SiteResolver::forSubject` is the one-line delegate | `classes/helper/SiteResolver.php` | Closed (plan 02-04) |
| `SiteResolver` static-source regex defence test | `tests/Unit/Helper/SiteResolverTest.php::test_source_does_not_reference_site_manager_or_request` | Closed (plan 02-04) |
| Adapter contract enforces `getSiteId(): ?int` shape | `classes/adapter/EventSubjectAdapter.php` line 35 | Closed (plan 02-01) |
| Contract test invariant 03 — `getSiteId` deterministic across successive calls | `classes/testing/EventSubjectAdapterContractTestCase.php::test_invariant_03_site_id_deterministic_across_set_site_context` | Closed (plan 02-07) |
| Contract test invariant 04 — `getSiteId` returns `?int` without Request side effects | `classes/testing/EventSubjectAdapterContractTestCase.php::test_invariant_04_get_site_id_reads_no_request_or_site_manager` | Closed (plan 02-07) |
| `Site::setSite($i)` cross-context determinism | static-enforcement-only at Phase 2 (RESEARCH §5.1 — no Site facade present in this October build for the test cycle); contract test asserts the deterministic delegation shape | Closed via belt-and-braces (phpstan + T6 grep + runtime invariant) |

### SC3 — Three `Event::fire` hooks at decision boundaries (P-08)

> Three `Event::fire` extension points (`metapixel.event.before_dispatch`, `metapixel.event.after_dispatch`, `metapixel.event.dead_letter`) fire at documented decision boundaries; a throwing third-party listener is caught + `Log::warning`'d + dispatch continues.

| Evidence | Location | Status |
|----------|----------|--------|
| 3 hook constants on `SendCapiEvent` | `classes/queue/SendCapiEvent.php` lines 56-60 | Closed (plan 02-06) |
| `before_dispatch` halt-on-false via `Event::fire(..., true)` | `SendCapiEvent::fireBeforeDispatchHalt` | Closed (plan 02-06) |
| `event_id` + `event_time` snapshot-restore (P-08) | `SendCapiEvent::fireBeforeDispatchHalt` | Closed (plan 02-06) |
| Listener-isolation try/catch on every fire site (D-16) | `SendCapiEvent::fireBeforeDispatchHalt` / `fireAfterDispatch` / `fireDeadLetter` | Closed (plan 02-06) |
| Halt-on-false test | `tests/Unit/Hook/BeforeDispatchHaltTest.php` | Closed (plan 02-06) |
| Payload-mutation guard test | `tests/Unit/Hook/BeforeDispatchPayloadMutationTest.php` | Closed (plan 02-06) |
| Listener-exception isolation test | `tests/Unit/Hook/ListenerExceptionIsolationTest.php` | Closed (plan 02-06) |
| `dead_letter` hook smoke | `tests/Unit/Hook/DeadLetterHookTest.php` | Closed (plan 02-06) |
| `after_dispatch` listener receives Graph response | `tests/Feature/Adapter/BackboneIntegrationTest.php::test_happy_path_fake_adapter_through_full_backbone_returns_capi_row_and_fires_after_dispatch` | Closed (plan 02-07) |

### SC4 — Backbone shape: per-call credentials + subject-agnostic builder + 4-arg queue job

> `MetaClient::sendForPixel(string $sPixelId, string $sToken, array $arPayload)` accepts per-call credentials (no more singleton Settings read); `PayloadBuilder::buildEventPayload(string $sEventName, EventSubjectAdapter, object $obSubject, ValueResolver, string $sEventId, int $iEventTime, array $arEventExtras)` is subject-agnostic; Graph API pinned to `v23.0` constant. `SendCapiEvent` constructor accepts a 4th `string $sAdapterClass` arg; `handle()` rehydrates the adapter via `AdapterRegistry::resolveByClass()` and writes FailedEvent on `BindingResolutionException`.

| Evidence | Location | Status |
|----------|----------|--------|
| `MetaClient::sendForPixel(pixelId, token, payload)` per-call credentials | `classes/meta/MetaClient.php::sendForPixel` | Closed (plan 02-05) |
| Graph API `v23.0` constant | `MetaClient::META_GRAPH_API_VERSION` | Closed (plan 02-05) |
| PayloadBuilder subject-agnostic (no event-name switch) | `classes/meta/PayloadBuilder.php` — zero `$sEventName ===` / `switch` / `match` comparisons | Closed (plan 02-05) |
| Per-event extras via `array $arEventExtras` slot | `PayloadBuilder::buildEventPayload` 7th param | Closed (plan 02-05) |
| SendCapiEvent 4-arg constructor | `SendCapiEvent::__construct(eventName, payload, subject, adapterClass)` | Closed (plan 02-06) |
| `AdapterRegistry::resolveByClass` rehydrate | `AdapterRegistry::resolveByClass` | Closed (plan 02-01) |
| `BindingResolutionException` → `writeFailedEvent(null adapter)` | `SendCapiEvent::handle` try/catch | Closed (plan 02-06) |
| `writeFailedEvent(?EventSubjectAdapter)` populates subject_type/id (H-2) | `SendCapiEvent::writeFailedEvent` | Closed (plan 02-06) |
| `failed()` retry-exhaustion handler (L-5) | `SendCapiEvent::failed` | Closed (plan 02-06) |
| Serialize round-trip survives the queue worker cycle (M-5) | `tests/Feature/Adapter/BackboneIntegrationTest.php::test_serialize_round_trip_job_unserializes_and_runs_handle` | Closed (plan 02-07) |

### SC5 — Backbone tests regreen via FakeAdapter

**See M-7 flag at the top of this document — current ROADMAP.md wording is misaligned with OQ-1.**

OQ-1 reframed SC5 to: backbone tests regreen via FakeAdapter test double; the 4 named v1.x test files move to Phase 3 alongside ShopaholicOrderAdapter.

| Evidence | Location | Status |
|----------|----------|--------|
| `FakeAdapter` test double | `tests/doubles/FakeAdapter.php` (autoload-dev) | Closed (plan 02-01) |
| `FakeValueResolver` test double | `tests/doubles/FakeValueResolver.php` | Closed (plan 02-01) |
| Backbone integration through FakeAdapter / TestSubjectAdapter end-to-end | `tests/Feature/Adapter/BackboneIntegrationTest.php` (3 tests including M-5) | Closed (plan 02-07) |
| Contract test passes against FakeAdapter (ADAP-11 smoke) | `tests/Contract/Adapter/FakeAdapterContractTest.php` (10 invariants) | Closed (plan 02-07) |
| Race-fence + dedup integration | `tests/Feature/Adapter/EventLogWriterRaceFenceTest.php` | Closed (plan 02-04) |
| Queue + handle flow tests | `tests/Feature/Queue/*` (7 files) | Closed (plan 02-06) |
| Hook + listener-isolation tests | `tests/Unit/Hook/*` (4 files) | Closed (plan 02-06) |
| Helper + Meta + Adapter unit suites | `tests/Unit/{Adapter,Helper,Meta}/*` | Closed (plans 02-01..02-06) |
| 4 v1.x-named test files (`OrderStatusWatcherEventLogTest` etc.) — NOT shipped here | Phase 3 SHOP-03 | Deferred |

---

## Requirement closure table

| REQ ID | Owner plan | Status |
|--------|------------|--------|
| ADAP-01 | 02-01 (interfaces) | Closed |
| ADAP-02 | 02-01 (ValueResolver interface) | Closed |
| ADAP-03 | 02-01 (AdapterRegistry) | Closed |
| ADAP-04 | 02-06 (3 Event::fire hooks) | Closed |
| ADAP-05 | 02-06 (listener-isolation try/catch envelopes) | Closed |
| ADAP-06 | 02-04 (SiteResolver::forSubject) | Closed |
| ADAP-07 | 02-03a (EventLog UNIQUE race-fence migration) + 02-04 (EventLogWriter::record) | Closed |
| ADAP-08 | 02-03b (Settings::lookupForSite stub) + 02-04 (anonymous external_id derivation surface) | Closed |
| ADAP-09 | 02-05 (Graph API v23.0 constant pinned) | Closed |
| ADAP-10 | 02-06 (4-arg SendCapiEvent constructor + resolveByClass) | Closed |
| ADAP-11 | 02-07 (FakeAdapter + ContractTestCase + smoke + integration) | Closed |

11 / 11 ADAP-* requirements closed.

---

## Pitfall closure table

| Pitfall | Severity | Owner plan | Mitigation | Status |
|---------|----------|------------|------------|--------|
| P-01 Cross-context resolution drift | CRITICAL | 02-02 (phpstan deny-list) + 02-04 (T6 grep + SiteResolver one-line delegate) + 02-07 (invariant 03 + 04 in contract base) | Belt-and-braces: static phpstan deny-list, static-source regex defence, runtime contract test invariants | Closed |
| P-02 Boot-order race | CRITICAL | 02-01 (lazy App::make in resolveFor / resolveByClass + idempotent register) | Registry uses lazy App::make; idempotent register tested via `AdapterRegistryBootOrderTest` | Closed |
| P-05 EventLog subject_type alias ambiguity | CRITICAL | 02-01 (alias contract) + 02-03a (no MorphTo on EventLog) + 02-04 (write-site reads adapter, not get_class) + 02-07 (invariant 01 forbids backslash in subject_type) | Closed |
| P-08 Event::fire mutable payload | HIGH | 02-06 (snapshot+restore event_id/event_time + listener-isolation try/catch envelope) | Closed |
| P-13 (Plugin CLAUDE.md "Extensibility contract" preference ranking) | DOC LOCK | 02-02 (plugin CLAUDE.md edit) | Closed |

5 / 5 in-Phase-2 pitfalls closed.

Phase 1 pitfalls (P-03 P-06 P-12 P-20) — separate verification scope.

Out-of-Phase-2 pitfalls (P-07 P-09 P-10 P-11 P-15 P-18) — Phase 3 / 4 ownership.

---

## Out-of-scope items in this phase

- **Phase 3 work (NOT this phase):** ShopaholicOrderAdapter, OrderStatusWatcher, ThemeActionAdapter, PixelHead component, EventPixel component, Larajax handler, fbq() script emission, the 4 named SC5 test files (`OrderStatusWatcherEventLogTest` etc.).
- **Phase 4 work (NOT this phase):** Multisite trait whitelist on Settings, TrustedHosts allowlist, EnsureFbpFbcCookies middleware, FailedEvents admin UI + Replay + CheckDedup, en/lv translation files.
- **Phase 5 work (NOT this phase):** README install guide, CUSTOM-ADAPTERS.md, marketplace assets, v2.0.0 tag, composer require smoke on clean OctoberCMS.
- **Orchestra Testbench:** Dropped per R2 YAGNI override (db89398). Revisit at v2.1 when first real third-party adapter ships outside this repo.
- **Test isolation for `Site::setSite($i)`:** Active site context exercise deferred — Phase 2 enforces deterministic delegation statically (phpstan deny-list + SiteResolver source regex) + at adapter contract (invariants 03 + 04). Phase 3 will exercise active-site context in `ShopaholicOrderAdapterContractTest` since real Order objects carry the site_id column.

---

## Filesystem path-case notes

Phase 2 deviation 1 (plan 02-01) locked the lowercase folder convention under `plugins/<vendor>/<plugin>/`:

- Disk paths under `classes/` are lowercase: `classes/adapter/`, `classes/helper/`, `classes/meta/`, `classes/queue/`, `classes/exception/`, `classes/testing/`.
- Namespaces stay PascalCase: `Logingrupa\Metapixel\Classes\Adapter\…`, `Logingrupa\Metapixel\Classes\Testing\…`.
- October Rain `ClassLoader::load` normalises folder portions to lowercase before basename resolution — PHP namespace resolution is case-insensitive.
- Plan 02-07's frontmatter `files_modified` references `classes/Testing/` (PascalCase) — treated as a folder-name typo; on-disk artifact ships under `classes/testing/`.
- `phpunit.xml` `<source><exclude>` uses lowercase `./classes/testing` to match the disk path.

---

## Next action

Run `/gsd:verify-phase 02-adapter-system-core-contracts-registry-extension-hooks` to produce `02-VERIFICATION.md` against this checklist.

Post-verification orchestrator todo list:

1. Apply M-7 ROADMAP.md SC5 wording fix.
2. Flip `.planning/REQUIREMENTS.md` ADAP-01..11 from `[ ]` to `[x]`.
3. Update `.planning/ROADMAP.md` Phase 2 status to "Complete".
4. Advance STATE.md `Current Position` to Phase 3.
