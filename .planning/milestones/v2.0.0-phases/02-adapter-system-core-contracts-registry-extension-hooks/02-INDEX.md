---
phase: 02-adapter-system-core-contracts-registry-extension-hooks
plan_count: 8
granularity: standard
status: planned-r1
planning_inputs:
  - .planning/PROJECT.md
  - .planning/ROADMAP.md
  - .planning/REQUIREMENTS.md
  - .planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-CONTEXT.md
  - .planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-DISCUSSION-LOG.md
  - .planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-RESEARCH.md
  - .planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-PLAN-CHECK.md
  - .planning/research/ARCHITECTURE.md
  - .planning/research/PITFALLS.md
gate: composer qa (pint-test → phpstan level 10 → phpmd → pest --coverage --min=90)
---

# Phase 2 — Adapter System Core: Plan Index (Revision R1)

Phase 2 builds a generic event-dispatch backbone for Logingrupa.Metapixel v2.0. Every subject (Shopaholic Order, theme action, third-party cart) flows through one `MetaClient` + `PayloadBuilder` + `UserDataHasher` + `EventLogWriter` pipeline behind the `EventSubjectAdapter` + `ValueResolver` interface pair, resolved at runtime via `AdapterRegistry`. Three `Event::fire` hooks wire decision boundaries. No production adapter ships in this phase — the backbone is exercised by `FakeAdapter` + `FakeValueResolver` test doubles. ShopaholicOrderAdapter + ThemeActionAdapter land in Phase 3.

All code is **written fresh** from the locked spec (D-01..D-22 in `02-CONTEXT.md`, OQ-1/2/3 resolutions in `02-RESEARCH.md`). No port from `legacy/v1.1.1`. No BC shims. The acceptance gate every plan drives toward is `composer qa` (pint-test → phpstan level 10 → phpmd → pest --coverage --min=90).

**R1 revisions** (plan-checker REVISE verdict — see `02-PLAN-CHECK.md`):
- Plan 02-03 split into **02-03a (storage layer)** + **02-03b (settings + guard + exceptions)** running parallel in Wave 2 (M-2 — original plan was 6 tasks / 25+ files; now 5 tasks each).
- Shared test fixtures consolidated under `tests/Doubles/` (H-6 + M-9) — plan 02-01 ships 6 doubles, plan 02-05 adds SpyMetaClient (extends MetaClient which lands in Wave 3).
- H-1 / H-2 / H-3 / H-4 / H-8 / H-9 + M-3 / M-4 / M-5 / M-6 / M-7 / M-8 + L-4 / L-5 / L-6 / L-8 closed inline in each plan's revision history.

## Plans

| # | Slug | Wave | REQ-IDs | Pitfalls | Depends on |
|---|------|------|---------|----------|------------|
| 02-01 | `interfaces-registry-singleton-binding` | 1 | ADAP-01, ADAP-02, ADAP-03 | P-02 | — |
| 02-02 | `tooling-deltas-phpstan-phpunit` | 1 | (cross-cuts ADAP-06 PHPStan enforcement) | P-01 (enforcement), P-13 (convention) | — |
| 02-03a | `storage-models-migrations` | 2 | (storage layer — supports ADAP-08, ADAP-09, ADAP-10) | P-05 | 02-01 |
| 02-03b | `settings-pluginguard-exceptions` | 2 | (config layer — supports ADAP-09, ADAP-10) | (PluginGuard pattern) | 02-01 |
| 02-04 | `siteresolver-eventlogwriter-racefence` | 3 | ADAP-06 | P-01 (logic), P-05 (alias write) | 02-01, 02-03a |
| 02-05 | `metaclient-payloadbuilder-userdatahasher` | 3 | ADAP-07, ADAP-08, ADAP-09 | — | 02-01, 02-03a, 02-03b |
| 02-06 | `sendcapievent-queue-job-hooks` | 4 | ADAP-04, ADAP-05, ADAP-10 | P-08 | 02-01, 02-03a, 02-03b, 02-04, 02-05 |
| 02-07 | `fake-adapter-contract-test-base-smoke` | 5 | ADAP-11 | (closes contract loop — D-11..D-13) | 02-01, 02-05, 02-06 |

## Dependency graph

```
Wave 1 (parallel — no shared files):
  02-01  ──┐
  02-02  ──┘  (independent — no shared files)

Wave 2 (parallel — disjoint files_modified per M-2 split):
  02-03a ←── 02-01   (migrations + EventLog/FailedEvent models + classmap)
  02-03b ←── 02-01   (Settings + PluginGuard + exceptions + Plugin::registerSettings)

Wave 3 (parallel — disjoint files_modified):
  02-04  ←── 02-01, 02-03a            (SiteResolver + EventLogWriter need AdapterRegistry + EventLog model)
  02-05  ←── 02-01, 02-03a, 02-03b    (MetaClient + PayloadBuilder + UserDataHasher need interfaces + exception subclasses; ALSO ships SpyMetaClient deferred from plan 02-01)

Wave 4:
  02-06  ←── 02-01, 02-03a, 02-03b, 02-04, 02-05   (SendCapiEvent orchestrates everything; H-2 writeFailedEvent accepts adapter)

Wave 5:
  02-07  ←── 02-01, 02-05, 02-06          (FakeAdapter exercises the full pipeline; H-3 Orchestra Testbench; M-5 serialize smoke; M-7 ROADMAP flag)
```

## REQ-ID coverage map (11/11 ADAP-* requirements)

| REQ-ID | Plan | Closing artifact |
|--------|------|------------------|
| ADAP-01 | 02-01 | `classes/Adapter/EventSubjectAdapter.php` (interface, 7 methods) |
| ADAP-02 | 02-01 | `classes/Adapter/ValueResolver.php` (interface, 5 methods) |
| ADAP-03 | 02-01 | `classes/Adapter/AdapterRegistry.php` + `Plugin::register()` singleton bind |
| ADAP-04 | 02-06 | 3 `Event::fire` hooks inside `SendCapiEvent::handle()` (halt-able on `before_dispatch` per OQ-2) |
| ADAP-05 | 02-06 | Listener-isolation try/catch wrappers in `SendCapiEvent` |
| ADAP-06 | 02-04 | `classes/Helper/SiteResolver::forSubject()` + PHPStan disallowed-calls disallowIn deny-list (02-02 lands the rules per H-1) |
| ADAP-07 | 02-05 | `classes/Meta/PayloadBuilder::buildEventPayload()` (subject-agnostic; `$arEventExtras` per OQ-3 + H-9 combined grep gate) |
| ADAP-08 | 02-05 | `classes/Meta/UserDataHasher::forSubject()` (stateless per M-4 — no memo until Phase 3) |
| ADAP-09 | 02-05 | `classes/Meta/MetaClient::sendForPixel()` + `META_GRAPH_API_VERSION = 'v23.0'` |
| ADAP-10 | 02-06 | `SendCapiEvent` 4th constructor arg + `resolveByClass` + `BindingResolutionException` boundary; writeFailedEvent accepts `?EventSubjectAdapter` (H-2) |
| ADAP-11 | 02-07 | `FakeAdapter` (plan 02-01 ships) + `FakeValueResolver` (plan 02-01 ships) + `EventSubjectAdapterContractTestCase` (Orchestra Testbench per H-3) + smoke |

## Pitfall ownership (P-01, P-02, P-05, P-08, P-13)

| Pitfall | Severity | Owned by | Mechanism |
|---------|----------|----------|-----------|
| P-01 Cross-context resolution drift | CRITICAL | 02-01 (`getSiteId(object): ?int` interface) + 02-04 (`SiteResolver::forSubject` logic + static-source regex grep T6) + 02-02 (PHPStan disallowIn deny-list per H-1) + 02-07 (ContractTestCase invariants 03 + 04) | Interface + logic + static analysis + contract test stack |
| P-02 Boot-order race / registry unbound | CRITICAL | 02-01 (idempotent `register()` + singleton bind in `Plugin::register()` + order-agnostic test T4 + H-8 setUp pattern) | Bind-in-register + register-in-any-order proof |
| P-05 EventLog subject_type alias ambiguity | CRITICAL | 02-01 (`getSubjectType()` opaque alias contract) + 02-03a (migration column + `EventLog` model drops MorphTo) + 02-04 (`EventLogWriter` writes adapter alias not FQN) + 02-07 (ContractTestCase invariant 01) | Interface + storage + write site + contract test stack |
| P-08 Event::fire mutable payload | HIGH | 02-06 (hook PHPDoc forbidding `event_id`/`event_time` mutation + snapshot+restore in fireBeforeDispatchHalt + listener-isolation try/catch + tests T12) | Documented contract + snapshot+restore enforcement + exception isolation + test enforcement |
| P-13 Component::extend unbounded surface | MEDIUM | 02-02 (CLAUDE.md addendum: prefer `Event::fire` over `Component::extend`+`addDynamicMethod` for third-party hooks) | Convention doc only — Phase 2 ships no Component::extend code |

## Source coverage audit

| Source | Type | Coverage |
|--------|------|----------|
| GOAL — "generic event-dispatch backbone, any subject through MetaClient + PayloadBuilder + UserDataHasher + EventLogWriter pipeline behind EventSubjectAdapter + ValueResolver interface pair resolved via AdapterRegistry" | ROADMAP Phase 2 Goal | Plans 02-01 + 02-04 + 02-05 + 02-06 deliver pipeline; 02-07 proves it round-trips |
| SC1 — third party implements 2 interfaces + calls `AdapterRegistry::register`; `FakeAdapter` round-trips through `PayloadBuilder::buildEventPayload` producing the same envelope shape v1.x produced for Order | ROADMAP Phase 2 SC1 | 02-01 (interfaces + registry + FakeAdapter shipped) + 02-05 (PayloadBuilder) + 02-07 (ContractTestCase smoke + BackboneIntegrationTest) |
| SC2 — `getSiteId(object): ?int` is the only authoritative source; PHPStan disallowIn deny-list bans SiteManager/Request/request() in adapter/queue/event dirs; contract test asserts determinism across `Site::setSite($i)` | ROADMAP Phase 2 SC2 | 02-01 (interface) + 02-02 (PHPStan rules H-1) + 02-04 (SiteResolver logic + T6 static-source regex grep) + 02-07 (ContractTestCase invariants 03 + 04) |
| SC3 — 3 `Event::fire` hooks fire at documented boundaries; throwing listener caught + `Log::warning`'d + dispatch continues | ROADMAP Phase 2 SC3 | 02-06 (hooks + listener-isolation + tests T11–T14) |
| SC4 — `MetaClient::sendForPixel` per-call credentials; `PayloadBuilder::buildEventPayload` subject-agnostic; Graph API `v23.0` const; `SendCapiEvent` 4th `string $sAdapterClass` arg; `resolveByClass`; `BindingResolutionException` → FailedEvent | ROADMAP Phase 2 SC4 | 02-05 (MetaClient + PayloadBuilder + H-9 grep gate) + 02-06 (SendCapiEvent + H-2 writeFailedEvent) |
| SC5 — backbone tests regreen via FakeAdapter | ROADMAP Phase 2 SC5 | 02-07 (FakeAdapter contract base via Orchestra Testbench + smoke + BackboneIntegrationTest + M-5 serialize round-trip) — OQ-1 resolved fresh-rewrite of ~60-110 backbone-only Pest 4 tests across plans 02-01, 02-03a, 02-03b, 02-04, 02-05, 02-06, 02-07. **M-7 flag: ROADMAP.md SC5 wording still references 4 v1.x test files; orchestrator updates SC5 wording per OQ-1 before Phase 2 closure (plan 02-07 flags in 02-VERIFICATION-INPUTS.md, does NOT modify ROADMAP itself).** |
| ADAP-01..11 | REQUIREMENTS | All mapped (table above) |
| D-01..D-22 | CONTEXT decisions | All honored: fresh code (D-01..D-03), 2 tables (D-04..D-07), FakeAdapter shape (D-08..D-10) shipped plan 02-01 Wave 1, Contract test base (D-11..D-13) via Orchestra Testbench in plan 02-07, AdapterRegistry singleton (D-14), 3 hooks (D-15), listener exceptions caught (D-16), SiteResolver authoritative (D-17), Graph API v23.0 (D-18), `MetaClient::sendForPixel` per-call (D-19), `SendCapiEvent` signature (D-20), `PayloadBuilder::buildEventPayload` shape (D-21), `UserDataHasher::forSubject` shape (D-22) |
| OQ-1 — 177 tests: fresh-rewrite ~60-110 Pest 4 backbone tests | RESEARCH | Honored across all plans; 02-07 closes ADAP-11 contract loop |
| OQ-2 — `before_dispatch` halt-able via `Event::fire($name, $payload, $halt=true)`; observe-only on the other two; listener exceptions caught + `Log::warning` + continue | RESEARCH | 02-06 implements |
| OQ-3 — PayloadBuilder event-name-agnostic; adapter + ValueResolver + `$arEventExtras` carry per-event shape; NO `switch ($sEventName)` / `match` / `===` / `in_array` inside builder (H-9 combined grep gate) | RESEARCH | 02-05 implements |
| P-01, P-02, P-05, P-08, P-13 | RESEARCH PITFALLS | All owned (table above) |

No source item is unplanned. No `[ASSUMED]` from RESEARCH.md §9 / Assumptions Log A1..A5 is silently dropped — each is converted into an explicit Plan 02 spike step or test invariant (A1 → 02-02 task 1 spike; A2 → M-4 resolution drops memo entirely; A3 → 02-01 registry PHPDoc note + no production fixture; A4 → 02-07 test directory + Pest.php NOT modified per L-6; A5 → 02-04 race-fence test asserts SQLite UNIQUE NULL-distinct, M-3 rewords as SEQUENTIAL not concurrent).

## Acceptance gate (every plan)

`composer qa` from `plugins/logingrupa/metapixel/`:

```
composer qa  →  pint-test
             →  analyse                    (phpstan level 10, phpVersion 80300)
             →  phpmd                      (Lovata.Toolbox PHPMD_custom.xml)
             →  test-cov                   (pest --coverage --min=90; classes/Testing excluded per plan 02-07)
```

Coverage gate ≥ 90 % on the Run A (full-Lovata) CI matrix cell only. Minimal-install cell excludes the `Metapixel Adapter Tests` testsuite per Phase 1's `metapixel-qa.yml`. Phase 2 plans do not weaken either rule.

## Out of scope (deferred)

- ShopaholicOrderAdapter + ThemeActionAdapter (Phase 3)
- Multisite trait field-whitelist on `Settings::pixel_id` + `capi_access_token` (Phase 4 — MULT-01..06)
- FailedEvents admin UI + Replay + CheckDedup (Phase 4 — FAIL-01..03)
- `EnsureFbpFbcCookies` + `trusted_hosts` + `jeremykendall/php-domain-parser` (Phase 4 — HOST-01..06)
- Five additional `Event::fire` hooks — `adapter.resolve`, `value.resolve`, `user_data.resolve`, `pixel.before_render`, `settings.lookup` (v2.1 EXT-01..05)
- Strip ALL v1.x references from `.planning/` docs (separate post-Phase 2 commit per CONTEXT.md `Claude's Discretion`)
- 4 named v1.x test files (`OrderStatusWatcherEventLogTest`, `PurchasePixelEventLogGateTest`, `SendCapiEventEventLogTest`, `MultiSiteEventLogTest`) — Phase 3 alongside ShopaholicOrderAdapter (M-7 ROADMAP.md SC5 wording fix pending orchestrator action)

---

*Index emitted: 2026-05-17 (R1 revision)*
*Phase: 02-adapter-system-core-contracts-registry-extension-hooks*
*Plan-checker verdict: REVISE → addressed in R1 (see 02-PLAN-CHECK.md and each plan's Revision History)*
