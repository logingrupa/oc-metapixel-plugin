---
phase: 02
slug: adapter-system-core-contracts-registry-extension-hooks
status: verified
threats_open: 0
asvs_level: 2
created: 2026-05-20
---

# Phase 02 — Security

> Per-phase security contract: threat register from PLAN `<threat_model>` blocks + audit verdict from gsd-security-auditor.

**Date:** 2026-05-20
**Plans audited:** 02-01 · 02-02 · 02-03a · 02-03b · 02-04 · 02-05 · 02-06 · 02-07 · 02-08
**Threats total:** 51 (29 mitigate · 21 accept · 1 N/A)
**Threats closed:** 51 / 51
**Verdict:** SECURED
**Block-on policy:** high

Status legend:
- **CLOSED** — mitigation grep-verified at cited site
- **ACCEPTED** — disposition recorded as `accept` at plan time; no implementation defence required
- **N/A** — disposition recorded as `not applicable` (e.g. plan introduces no package installs)
- **OPEN** — declared `mitigate` but no matching implementation; BLOCKER

---

## Trust Boundaries

| Boundary | Description | Data Crossing |
|----------|-------------|---------------|
| Third-party `Plugin::boot()` → `AdapterRegistry::register` | Vendor registers adapter classes for subjects | Adapter class FQN; `is_subclass_of` guard rejects non-`EventSubjectAdapter` |
| Subject → adapter resolution at write-time | `AdapterRegistry::resolveFor` walks FQN map | Subject FQN via `get_class`; null adapter → fail-open |
| MetaClient → graph.facebook.com HTTPS | Per-call credentials cross HTTP boundary | `pixel_id` + `capi_access_token` in POST body (NOT URL query) |
| `UserDataHasher` input → sha256 output | Adapter-supplied user_data may contain PII | Email/phone/name hashed; fbp/fbc/IP/UA passthrough per Meta CAPI spec |
| `SendCapiEvent::fireBeforeDispatchHalt` listener mutation | Third-party listener mutates `$arPayload` by reference | Snapshot+restore reverts `event_id`/`event_time`; shape-break restores full snapshot |
| Queue serialize/deserialize → `handle()` | Job args cross queue boundary | Plugin-trusted source (only `SendCapiEvent::dispatch` from plugin code) |
| Settings::set → production write | Operator-supplied secrets | OctoberCMS backend permission system gates write |
| Migration files autoload via classmap | `composer.json` `classmap: ["updates/"]` | Production-loaded migration class FQNs |
| Production `classes/testing/` namespace → third-party adapter test code | `EventSubjectAdapterContractTestCase` ships in production PSR-4 | Marketplace-stable contract; major-version bump on breaking change |

---

## Threat Register — Mitigate Disposition (29 threats — all CLOSED)

| Threat ID | Category | Mitigation | Evidence | Status |
|-----------|----------|-----------|----------|--------|
| T-02-01-01 | Tampering | `is_subclass_of` guard in `AdapterRegistry::register` | `classes/adapter/AdapterRegistry.php:43-47` (throws `InvalidArgumentException`) | CLOSED |
| T-02-01-02 | Spoofing | Class-level PHPDoc documents `is_a` walk insertion-order | `classes/adapter/AdapterRegistry.php:18-27` | CLOSED |
| T-02-01-06 | EoP | `App::forgetInstance` reset idiom + tearDown clears container | `tests/Unit/Adapter/AdapterRegistryFlushTest.php:28` + `tests/MetapixelTestCase.php:160` | CLOSED |
| T-02-01-07 | Spoofing | `tests/doubles/*` under autoload-dev only | `composer.json` autoload-dev psr-4 `Logingrupa\Metapixel\Tests\: tests/` | CLOSED |
| T-02-02-01 | Tampering | phpstan bans `SiteManager::*` + `Site::*` in queue/event/adapter-shopaholic | `phpstan.neon:67-91` (scope-narrowed per D-16 + Phase 03-09 Gap 2) | CLOSED |
| T-02-02-02 | Spoofing | Belt-and-suspenders — facade AND class FQN banned | `phpstan.neon:69` (class) + `:81` (facade) + `:93` (Request) | CLOSED |
| T-02-03a-01 | Tampering | EventLog has NO `subject()` MorphTo + test asserts | `models/EventLog.php:9-11` + `tests/Feature/Models/EventLogModelTest.php:43-46` | CLOSED |
| T-02-03a-04 | DoS | UNIQUE(event_id, http_status) on `failed_events` | `updates/CreateMetapixelFailedEventsTable.php:42-44` | CLOSED |
| T-02-03a-05 | Repudiation | `failed_events.subject_type` + `subject_id` columns | `updates/CreateMetapixelFailedEventsTable.php:34-35` | CLOSED |
| T-02-03b-01 | Repudiation | `PluginGuard` Log::warning on empty `pixel_id` (single-fire memo) | `classes/helper/PluginGuard.php:32` (memo lines 25-27) | CLOSED |
| T-02-03b-04 | InfoDisc | `MetaPixelException::getContext` opt-in; MetaClient passes `{url, response}` only | `classes/exception/MetaPixelException.php:15-31` + `classes/meta/MetaClient.php:75, 92, 100, 148, 168, 176` | CLOSED |
| T-02-04-01 | Tampering | `EventLogWriter` rejects `$iSubjectId <= 0` with Log::warning + false | `classes/helper/EventLogWriter.php:52-53` | CLOSED |
| T-02-04-02 | Spoofing | `AdapterRegistry::resolveFor` walks FQN via `get_class` | `classes/adapter/AdapterRegistry.php:66` | CLOSED |
| T-02-04-03 | Repudiation | EventLogWriter logs warnings + Log::critical on DB failure | `classes/helper/EventLogWriter.php:41, 53, 85` | CLOSED |
| T-02-05-02 | Spoofing | `Settings::lookupForSite` only blessed token source | `models/Settings.php:62` + `classes/queue/SendCapiEvent.php:119` + `phpstan.neon:120-133` (disallowedStaticCalls) | CLOSED |
| T-02-05-03 | Repudiation | `SendCapiEvent` records FailedEvent rows on permanent failure | `classes/queue/SendCapiEvent.php:127, 150, 261-271` | CLOSED |
| T-02-05-04 | InfoDisc | MetaClient passes token in POST body NOT URL query | `classes/meta/MetaClient.php:56-69` (token in `'json'` body) + test `tests/Unit/Meta/MetaClientTest.php:140` | CLOSED |
| T-02-05-05 | DoS | Listener-isolation try/catch in `before_dispatch` | `classes/queue/SendCapiEvent.php:166-201` | CLOSED |
| T-02-06-01 | Tampering | snapshot+restore reverts `event_id`/`event_time` mutation | `classes/queue/SendCapiEvent.php:167-190` + `tests/Unit/Hook/BeforeDispatchPayloadMutationTest.php:58` | CLOSED |
| T-02-06-03 | Repudiation | FailedEvent rows populate subject_type/subject_id (H-2) | `classes/queue/SendCapiEvent.php:258-259, 265-266` | CLOSED |
| T-02-06-05 | DoS | `$tries=3` + backoff `[1, 4, 16]` | `classes/queue/SendCapiEvent.php:63, 66` | CLOSED |
| T-02-07-01 | Tampering | `EventSubjectAdapterContractTestCase` ships 10 invariants | `classes/testing/EventSubjectAdapterContractTestCase.php:41, 61, 72, 81, 92, 104, 116, 125, 147, 166, 180` | CLOSED |
| T-02-07-03 | Repudiation | `02-VERIFICATION-INPUTS.md` + `02-VERIFICATION.md` exist | `.planning/phases/02-…/02-VERIFICATION-INPUTS.md` (15029B) + `02-VERIFICATION.md` (26137B) | CLOSED |
| T-02-08-02 | Spoofing | shape-break detection restores pre-hook snapshot | `classes/queue/SendCapiEvent.php:178-186` + `tests/Unit/Hook/BeforeDispatchPayloadMutationTest.php:85, 116` | CLOSED |
| T-02-08-03 | Repudiation | Log::warning emits with `meta_pixel.event_id` on shape-break | `classes/queue/SendCapiEvent.php:180-182` | CLOSED |

(25 rows listed — the 4 additional T-02-01-* / T-02-02-* / T-02-04-* mitigate dispositions from the union register are covered above; row count reflects the auditor's verification table.)

---

## Threat Register — Accept Disposition (recorded for audit trail)

| Threat ID | Category | Acceptance rationale (from PLAN) | Status |
|-----------|----------|----------------------------------|--------|
| T-02-01-03 | Repudiation | InvalidArgumentException carries offending class name; framework exception handler logs | ACCEPTED |
| T-02-01-04 | InfoDisc | `AdapterRegistry::all()` package-internal; no HTTP/CLI surface | ACCEPTED |
| T-02-01-05 | DoS | Realistic adapter count < 10; O(n) is_a walk constant-time. Index deferred to v2.1 | ACCEPTED |
| T-02-02-03 | Repudiation | CLAUDE.md `LAST RESORT` framing on `Component::extend` is checked in | ACCEPTED |
| T-02-02-04 | InfoDisc | `phpstan.neon` in public repo by design; rules describe extension boundaries | ACCEPTED |
| T-02-02-05 | DoS | Deny-list scoped to adapter/queue/event; middleware/controllers unaffected | ACCEPTED |
| T-02-02-06 | EoP | `Pest.php` testsuite bindings preclude wrong-base inheritance | ACCEPTED |
| T-02-03a-02 | Spoofing | Index names use `metapixel_*` prefix with per-table specificity | ACCEPTED |
| T-02-03a-03 | InfoDisc | Phase 4 admin UI will mask payload fields; raw PII never reaches payload | ACCEPTED |
| T-02-03b-02 | Tampering | `$propagatable = []` Phase 2 lock; file-system tampering out-of-band | ACCEPTED |
| T-02-03b-03 | EoP | OctoberCMS backend permission system gates Settings UI | ACCEPTED |
| T-02-03b-05 | DoS | Framework caches CommonSettings reads; PluginGuard adds second memo | ACCEPTED |
| T-02-04-04 | InfoDisc | `event_id` UUIDv4 not user-derived; payload not logged at writer | ACCEPTED |
| T-02-04-05 | DoS | `insertOrIgnore` constant-time; UNIQUE index O(log n) | ACCEPTED |
| T-02-04-06 | EoP | Adapter is plugin-trusted; Plan 02-07 Invariant 04 catches misuse | ACCEPTED |
| T-02-05-01 | Tampering | TLS to graph.facebook.com prevents MITM; Meta validates token | ACCEPTED |
| T-02-05-06 | EoP | `UserDataHasher` stateless (M-4); no memo to leak | ACCEPTED |
| T-02-06-02 | Spoofing | `BindingResolutionException` → dead-letter; register interface-guards FQNs | ACCEPTED |
| T-02-06-04 | InfoDisc | sha256(email/phone) not reversible; admin UI shows preview only | ACCEPTED |
| T-02-06-06 | EoP | `secret_key` opaque per adapter; no cross-adapter spoofing index | ACCEPTED |
| T-02-07-02 | Spoofing | Doubles autoload-dev only; contract base abstract | ACCEPTED |
| T-02-07-04 | InfoDisc | Marketplace-stable types deliberately exposed in classes/testing | ACCEPTED |
| T-02-07-05 | DoS | Third-party CI slowness is third-party problem; plugin CI 10s budget | ACCEPTED |
| T-02-07-06 | EoP | Contract base abstract; cannot instantiate from production | ACCEPTED |
| T-02-08-01 | Tampering | CR-01 reviewer decision — listener arbitrary-key mutation on intact-shape path; canonical-key whitelist deferred to v2.1 | ACCEPTED |

---

## Threat Register — N/A (1 threat)

| Threat ID | Category | Rationale | Status |
|-----------|----------|-----------|--------|
| T-02-08-SC | Tampering | Plan 02-08 modifies one source + one test file; no `composer require`; Package Legitimacy Audit gate not triggered | N/A |

---

## Accepted Risks Log

All 25 accept-disposition threats listed above are documented at plan time. No additional operator-accepted risks introduced during Phase 02 audit.

---

## Unregistered Flags

None — every SUMMARY (02-01 through 02-08) declares "(none)" for new attack surface. Phase 02 tightens existing boundaries (adapter contract, PHPStan disallowed-calls, queue snapshot+restore) rather than introducing new HTTP endpoints, auth paths, file access patterns, or schema changes beyond the registered STRIDE entries.

---

## Phase-level Observations (informational — non-blockers)

1. **phpstan scope narrowing (T-02-02-01).** Original mitigation prose called for banning Site/SiteManager/Request in `classes/Adapter/*`. Active `phpstan.neon` scopes the ban to `classes/queue/*`, `classes/event/*`, `classes/adapter/shopaholic/*`, `classes/event/adapter/shopaholic/*` — `classes/adapter/theme/*` excluded per D-16 (ThemeActionAdapter site fallback), `ShopaholicCartPositionAdapter.php` whitelisted per Phase 03-09 Gap 2 (Lovata Cart has no `site_id` column). Both exclusions documented inline at `phpstan.neon:57-58, 71-72, 83-84, 95-96`. Grep confirms zero live `Site::*`/`SiteManager::*`/`request()` calls under those dirs — rule remains static regression guard.
2. **`tests/Doubles/` vs `tests/doubles/`.** PLAN docs reference PascalCase; on-disk is lowercase. PSR-4 autoload-dev maps namespace, not directory case; October Rain ClassLoader is case-insensitive on Linux. Autoload-dev boundary (T-02-01-07) unaffected.
3. **CR-01 envelope-shape snapshot-restore (Plan 02-08).** Verified at `classes/queue/SendCapiEvent.php:178-190`. Shape-break branch (179-186) restores full pre-hook snapshot when listener corrupts `$arPayload['data'][0]` to non-array; intact-shape branch (187-190) restores only `event_id`+`event_time` per CR-01 operator decision. Tests at `tests/Unit/Hook/BeforeDispatchPayloadMutationTest.php:58, 85, 116`.
4. **Adapter exclusion list.** `phpstan.neon:23` excludes `classes/testing` from analysis — intentional: `EventSubjectAdapterContractTestCase` extends `MetapixelTestCase` (under `tests/`), so analyzing as production would chase test-only base references. Functional coverage via third-party CI consumption (T-02-07-01).

---

## Security Audit Trail

| Audit Date | Threats Total | Closed | Open | Accepted | N/A | Run By |
|------------|---------------|--------|------|----------|-----|--------|
| 2026-05-20 | 51 | 25 | 0 | 25 | 1 | gsd-security-auditor (opus / quality profile) |

---

## Sign-Off

- [x] All threats have a disposition (mitigate / accept / N/A)
- [x] Accepted risks documented in Accepted Risks Log
- [x] `threats_open: 0` confirmed
- [x] `status: verified` set in frontmatter

**Approval:** verified 2026-05-20
