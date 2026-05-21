---
phase: 02
slug: adapter-system-core-contracts-registry-extension-hooks
status: approved
nyquist_compliant: true
wave_0_complete: true
created: 2026-05-21
---

# Phase 02 — Validation Strategy

Retroactive Nyquist validation for the adapter-system-core backbone (8 plans, ADAP-01..11). Reconstructed from `02-INDEX.md` + 8 SUMMARY files + `02-VERIFICATION.md` + `02-HUMAN-UAT.md`.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 4 on PHPUnit 12 (PHP 8.3 + 8.4 dual) |
| **Config file** | `phpunit.xml` (bootstrap: `../../../modules/system/tests/bootstrap.php`); `phpstan.neon` (level 10, phpVersion 80300, disallowed-calls deny-list); `phpmd.xml` (Lovata.Toolbox PHPMD_custom.xml) |
| **Quick run command** | `vendor/bin/pest --testsuite='Metapixel Unit Tests'` |
| **Full suite command** | `composer qa` (pint-test → phpstan analyse → phpmd → pest --coverage --min=90) |
| **Estimated runtime** | ~6 s (quick) / ~25 s (full suite Run A) |
| **Source coverage scope** | `Plugin.php`, `classes/`, `models/`, `console/`, `components/`, `middleware/`, `controllers/` (excluding `classes/testing/`) |
| **Test suites** | Unit (`tests/Unit`), Feature (`tests/Feature`), Contract (`tests/Contract`) |
| **CI matrix** | Run A (full-Lovata) ≥ 90 % coverage gate; Run B (minimal-install) excludes `--group=adapter` tests |

---

## Sampling Rate

- **After every task commit:** `vendor/bin/pest --testsuite='Metapixel Unit Tests'` (quick — ~6 s)
- **After every plan wave:** `composer qa` from `plugins/logingrupa/metapixel/`
- **Before `/gsd:verify-work`:** Full suite green on Run A + Run B CI matrix cells
- **Max feedback latency:** ~25 s

---

## Per-Task Verification Map

Each plan maps to a closing test artifact under `tests/`. All ADAP-* requirements have automated verification. PHPStan disallowed-calls rules and `composer qa` gate enforce static invariants (P-01 + extensibility-contract).

| Task ID | Plan | Wave | Requirement | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|-----------|-------------------|-------------|--------|
| 02-01-T1 | 02-01 | 1 | ADAP-01, ADAP-02, ADAP-03 | unit | `vendor/bin/pest tests/Unit/Adapter/AdapterRegistryTest.php` | ✅ | ✅ green |
| 02-01-T2 | 02-01 | 1 | ADAP-03 (singleton) | unit | `vendor/bin/pest tests/Unit/Adapter/AdapterRegistrySingletonBindingTest.php` | ✅ | ✅ green |
| 02-01-T3 | 02-01 | 1 | ADAP-03 (fail-fast register) | unit | `vendor/bin/pest tests/Unit/Adapter/AdapterRegistryInvalidAdapterTest.php` | ✅ | ✅ green |
| 02-01-T4 | 02-01 | 1 | ADAP-03 + P-02 boot-order | unit | `vendor/bin/pest tests/Unit/Adapter/AdapterRegistryBootOrderTest.php` | ✅ | ✅ green |
| 02-01-T5 | 02-01 | 1 | ADAP-03 (forgetInstance) | unit | `vendor/bin/pest tests/Unit/Adapter/AdapterRegistryFlushTest.php` | ✅ | ✅ green |
| 02-02-T2 | 02-02 | 1 | ADAP-06 (PHPStan deny-list) | static | `vendor/bin/phpstan analyse -c phpstan.neon` | ✅ (`phpstan.neon`) | ✅ green |
| 02-03a-T1 | 02-03a | 2 | P-05 storage + race-fence schema | feature | `vendor/bin/pest tests/Feature/Migrations/EventLogMigrationTest.php` | ✅ | ✅ green |
| 02-03a-T2 | 02-03a | 2 | H-2 FailedEvent schema | feature | `vendor/bin/pest tests/Feature/Migrations/FailedEventsMigrationTest.php` | ✅ | ✅ green |
| 02-03a-T3 | 02-03a | 2 | P-05 EventLog model (no MorphTo) | feature | `vendor/bin/pest tests/Feature/Models/EventLogModelTest.php` | ✅ | ✅ green |
| 02-03a-T4 | 02-03a | 2 | FailedEvent model | feature | `vendor/bin/pest tests/Feature/Models/FailedEventModelTest.php` | ✅ | ✅ green |
| 02-03b-T1 | 02-03b | 2 | PluginGuard cascade-safe | unit | `vendor/bin/pest tests/Unit/Helper/PluginGuardTest.php` | ✅ | ✅ green |
| 02-03b-T2 | 02-03b | 2 | Exception hierarchy + context | unit | `vendor/bin/pest tests/Unit/ExceptionHierarchyTest.php` | ✅ | ✅ green |
| 02-03b-T3 | 02-03b | 2 | Settings::lookupForSite stub | feature | `vendor/bin/pest tests/Feature/Settings/SettingsLookupForSiteTest.php` | ✅ | ✅ green |
| 02-03b-T4 | 02-03b | 2 | CommonSettings parent contract | feature | `vendor/bin/pest tests/Feature/Settings/SettingsCommonSettingsParentTest.php` | ✅ | ✅ green |
| 02-04-T1 | 02-04 | 3 | ADAP-06 (SiteResolver) | unit | `vendor/bin/pest tests/Unit/Helper/SiteResolverTest.php` | ✅ | ✅ green |
| 02-04-T2 | 02-04 | 3 | P-05 alias write + UNIQUE race-fence | feature | `vendor/bin/pest tests/Feature/Adapter/EventLogWriterRaceFenceTest.php` | ✅ | ✅ green |
| 02-05-T1 | 02-05 | 3 | ADAP-09 (MetaClient v23.0 + per-call creds) | unit | `vendor/bin/pest tests/Unit/Meta/MetaClientTest.php` | ✅ | ✅ green |
| 02-05-T2 | 02-05 | 3 | ADAP-07 (PayloadBuilder subject-agnostic + H-9 grep gate) | unit | `vendor/bin/pest tests/Unit/Meta/PayloadBuilderTest.php` | ✅ | ✅ green |
| 02-05-T3 | 02-05 | 3 | ADAP-08 (UserDataHasher stateless) | unit | `vendor/bin/pest tests/Unit/Meta/UserDataHasherTest.php` | ✅ | ✅ green |
| 02-06-T1 | 02-06 | 4 | ADAP-04 + P-08 halt | unit | `vendor/bin/pest tests/Unit/Hook/BeforeDispatchHaltTest.php` | ✅ | ✅ green |
| 02-06-T2 | 02-06 | 4 | ADAP-04 + P-08 event_id/event_time snapshot | unit | `vendor/bin/pest tests/Unit/Hook/BeforeDispatchPayloadMutationTest.php` | ✅ | ✅ green |
| 02-06-T3 | 02-06 | 4 | ADAP-05 listener isolation | unit | `vendor/bin/pest tests/Unit/Hook/ListenerExceptionIsolationTest.php` | ✅ | ✅ green |
| 02-06-T4 | 02-06 | 4 | ADAP-04 dead_letter observe-only | unit | `vendor/bin/pest tests/Unit/Hook/DeadLetterHookTest.php` | ✅ | ✅ green |
| 02-06-T5 | 02-06 | 4 | ADAP-10 happy path | feature | `vendor/bin/pest tests/Feature/Queue/SendCapiEventHappyPathTest.php` | ✅ | ✅ green |
| 02-06-T6 | 02-06 | 4 | ADAP-10 resolveByClass + BindingResolutionException | feature | `vendor/bin/pest tests/Feature/Queue/SendCapiEventBindingResolutionTest.php` | ✅ | ✅ green |
| 02-06-T7 | 02-06 | 4 | ADAP-10 + ADAP-04 halt path | feature | `vendor/bin/pest tests/Feature/Queue/SendCapiEventHaltTest.php` | ✅ | ✅ green |
| 02-06-T8 | 02-06 | 4 | ADAP-10 dead-letter | feature | `vendor/bin/pest tests/Feature/Queue/SendCapiEventDeadLetterTest.php` | ✅ | ✅ green |
| 02-06-T9 | 02-06 | 4 | ADAP-10 transient retry | feature | `vendor/bin/pest tests/Feature/Queue/SendCapiEventTransientRetryTest.php` | ✅ | ✅ green |
| 02-06-T10 | 02-06 | 4 | ADAP-10 L-5 failed() resolve | feature | `vendor/bin/pest tests/Feature/Queue/SendCapiEventFailedHandlerTest.php` | ✅ | ✅ green |
| 02-06-T11 | 02-06 | 4 | ADAP-10 branch coverage | feature | `vendor/bin/pest tests/Feature/Queue/SendCapiEventBranchCoverageTest.php` | ✅ | ✅ green |
| 02-07-T1 | 02-07 | 5 | ADAP-11 marketplace contract (10 invariants) | contract | `vendor/bin/pest tests/Contract/Adapter/FakeAdapterContractTest.php` | ✅ | ✅ green |
| 02-07-T2 | 02-07 | 5 | SC1 envelope shape round-trip | feature | `vendor/bin/pest tests/Feature/Adapter/ContractTestCaseSmokeTest.php` | ✅ | ✅ green |
| 02-07-T3 | 02-07 | 5 | SC1 + SC5 + M-5 serialize round-trip | feature | `vendor/bin/pest tests/Feature/Adapter/BackboneIntegrationTest.php` | ✅ | ✅ green |
| 02-08-T1 | 02-08 | post | CR-01 full-snapshot restore on shape destruction | unit | `vendor/bin/pest tests/Unit/Hook/BeforeDispatchPayloadMutationTest.php` (+3 cases) | ✅ | ✅ green |

Status legend: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky

### Static enforcement gates

| Gate | Mechanism | Command |
|------|-----------|---------|
| P-01 cross-context resolution drift | PHPStan disallowed-calls deny-list (`SiteManager::*`, `Site::*`, `Request::*`, `request()` banned in `classes/queue/*`, `classes/event/*`, `classes/adapter/*`) | `vendor/bin/phpstan analyse -c phpstan.neon` |
| P-01 defence-in-depth | Static-source regex grep test on `SiteResolver.php` | `vendor/bin/pest tests/Unit/Helper/SiteResolverTest.php` |
| H-9 PayloadBuilder event-name-agnostic | Combined grep gate (`===`, `!==`, `==`, `switch`, `match`, `in_array`) | `! grep -E '\$sEventName\s*(===\|!==\|==)\|switch\s*\(\s*\$sEventName\|match\s*\(\s*\$sEventName\|in_array\s*\(\s*\$sEventName' classes/meta/PayloadBuilder.php` |
| Coverage ≥ 90 % | Pest coverage gate (Run A CI cell) | `vendor/bin/pest --coverage --min=90` |
| PSR-12 + Laravel preset | Pint | `vendor/bin/pint --test` |
| Cyclomatic + dead-code | PHPMD | `vendor/bin/phpmd Plugin.php,classes,models,console,components,middleware,controllers text phpmd.xml` |

---

## Wave 0 Requirements

Existing infrastructure covers all phase requirements. No Wave 0 stubs needed — Phase 1 (`01-tooling-composer-namespace-rename-ci-matrix`) already shipped the Pest 4 + PHPStan + PHPMD + Pint chain plus `MetapixelTestCase`. Plan 02-02 extended `phpunit.xml` source-include to `./classes` + `./models` and added the `Metapixel Contract Tests` testsuite.

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| `composer qa` exit 0 on the full-Lovata CI matrix cell | ADAP-01..11 acceptance gate | Plugin cannot run standalone `composer install` — private October packages not on a public registry. Verification requires a host install with the full Lovata dependency tree resolved in `vendor/`. | From `plugins/logingrupa/metapixel/`, run `composer qa`. Expect: pint passed, phpstan level 10 `[OK] No errors`, phpmd clean, pest 430 passed / 1532 assertions / coverage ≥ 90 % (Run A). **Verified 2026-05-20** — see `02-HUMAN-UAT.md` test #1. |
| Multi-site operator awareness of Settings per-site routing | MULT-03 (deferred Phase 4 — closed in this phase via per-site Multisite-trait routing) | Requires live two-site October install + backend operator workflow check. Cannot read backend site configuration from the codebase. | Confirm on `nailscosmetics.lv` + `nailscosmetics.no` that `Settings::lookupForSite($iSiteId)` reads the per-site Multisite row when set and silently falls back to the default row. **Verified 2026-05-20** — see `02-HUMAN-UAT.md` test #3. |

---

## Validation Sign-Off

- [x] All tasks have automated verify or static-gate enforcement
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all MISSING references (none required)
- [x] No watch-mode flags
- [x] Feedback latency < 30 s
- [x] `nyquist_compliant: true` set in frontmatter

**Coverage snapshot (post-merge, verified 2026-05-20):** 430 passed / 1532 assertions / total coverage 90.2 % (Run A) · 244 passed / 834 assertions (Run B `--exclude-group=adapter`).

**Approval:** approved 2026-05-21 (retroactive reconstruction; phase already merged + executor-verified 2026-05-17, CR-01 gap closed via plan 02-08 on 2026-05-20).
