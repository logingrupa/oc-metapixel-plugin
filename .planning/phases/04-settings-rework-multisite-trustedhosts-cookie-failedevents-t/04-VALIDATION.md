---
phase: 4
slug: settings-rework-multisite-trustedhosts-cookie-failedevents-t
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-05-19
revised: 2026-05-19  # iteration 1 — added D-10 stale-PSL test row + 04-04 scope rationale link
---

# Phase 4 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 4 (PHPUnit 12 transport) + PHPStan 10 + Pint + PHPMD |
| **Config file** | `plugins/logingrupa/metapixel/phpunit.xml` + `plugins/logingrupa/metapixel/tests/Pest.php` |
| **Quick run command** | `cd plugins/logingrupa/metapixel && composer test -- --compact --filter={TestName}` |
| **Full suite command** | `cd plugins/logingrupa/metapixel && composer qa` |
| **Estimated runtime** | ~45 seconds (qa full); ~5 seconds (focused filter) |

---

## Sampling Rate

- **After every task commit:** Run focused Pest filter for the task's test file (`composer test -- --compact --filter=...`)
- **After every plan wave:** Run `composer qa` (pint-test → phpstan analyse → phpmd → pest --coverage --min=90)
- **Before `/gsd:verify-work`:** `composer qa` must exit 0, coverage ≥ 90 %
- **Max feedback latency:** 45 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 04-01-01 | 01 | 1 | MULT-01, MULT-02 | — | `Settings` model declares `$propagatable = []` whitelist lock (cross-site token leak guard) | unit | `composer test -- --filter=SettingsMultisitePropagatableTest` | ❌ W0 | ⬜ pending |
| 04-01-02 | 01 | 1 | MULT-03 | T-04-MULT-01 | `Settings::lookupForSite($iSiteID)` returns per-site `pixel_id` / `capi_access_token` with no fallback bleed across sites | unit | `composer test -- --filter=SettingsLookupForSiteTest` | ❌ W0 | ⬜ pending |
| 04-01-03 | 01 | 1 | MULT-04 | — | Admin Settings form renders per-site tab + scopes the two whitelisted fields | unit | `composer test -- --filter=SettingsFormMultisiteScopeTest` | ❌ W0 | ⬜ pending |
| 04-01-04 | 01 | 1 | MULT-05 | T-04-MULT-02 | 8-path matrix (2 sites × 2 adapters × 2 channels): EventLog rows independent under UNIQUE NULL-distinct semantics | feature | `composer test -- --filter=MultisiteEightPathMatrixTest` | ❌ W0 | ⬜ pending |
| 04-01-05 | 01 | 1 | MULT-06 | — | `AddSiteIdToSettings` migration is idempotent + no-op-safe on October-managed `system_settings` | unit | `composer test -- --filter=MigrationSiteIdAdditiveTest` | ❌ W0 | ⬜ pending |
| 04-02-01 | 02 | 1 | HOST-01 | — | `composer.json` declares `jeremykendall/php-domain-parser ^6.4` in `require:` (not require-dev) | unit | `composer test -- --filter=PslDependencyDeclaredTest` | ❌ W0 | ⬜ pending |
| 04-02-02 | 02 | 1 | HOST-02 | — | `resources/data/public_suffix_list.dat` bundled with plugin; `metapixel:refresh-psl` artisan command refreshes to `storage/app/metapixel/psl/` (Forge-writable, NOT in release dir — P-18 fence) | unit | `composer test -- --filter=PslRefreshCommandTest` | ❌ W0 | ⬜ pending |
| 04-02-03 | 02 | 2 | HOST-03 | — | `Settings::get('trusted_hosts')` Settings textarea: one host per line; normalized + de-duped + lowercased on save | unit | `composer test -- --filter=TrustedHostsSettingsValidationTest` | ❌ W0 | ⬜ pending |
| 04-02-04a | 02 | 2 | HOST-04 | T-04-HOST-01 | `HostIndexResolver::resolve(string $sHost): ?int` returns 1 for apex, 2 for `www.`, 2 for `shop.acme.co.uk`, correct subdomain depth for IDN `xn--bcher-kva.example`. Naive `explode('.')` is banned (PHPStan disallowed-calls). | unit | `composer test -- --filter=HostIndexResolverTest` | ❌ W0 | ⬜ pending |
| 04-02-04b | 02 | 2 | D-10 | T-04-HOST-04 | `HostIndexResolver` constructor latches `$bStaleWarningEmitted`; on first `resolve()` call, if `time() - filemtime(sPslPath) > 15552000` seconds (180 days), emits exactly one `Log::warning('PSL snapshot is N days old — run php artisan metapixel:refresh-psl')`. Second `resolve()` call in same request emits zero additional warnings. Fresh PSL (mtime within 180 days) emits zero warnings. Cookies continue to resolve correctly throughout (D-10: stale snapshot is not a failure mode). | unit | `composer test -- --filter=HostIndexResolverTest::test_stale_psl_emits_log_warning_once_when_filemtime_older_than_180_days` | ❌ W0 | ⬜ pending |
| 04-02-05 | 02 | 2 | HOST-05 | T-04-HOST-02 | Multi-TLD fixture matrix: apex `example.test`, `www.example.test`, `example.co.uk`, IDN `xn--bcher-kva.example`, exotic `shop.example.com.br` — all derive correct subdomain index. | feature | `composer test -- --filter=HostIndexMultiTldMatrixTest` | ❌ W0 | ⬜ pending |
| 04-02-06 | 02 | 2 | HOST-06 | T-04-HOST-03 | Untrusted host (not in `trusted_hosts`) → `HostIndexResolver::resolve()` returns null → middleware NO-OPs, no exception, no cookies set (fail-safe). | feature | `composer test -- --filter=HostIndexUntrustedFailsafeTest` | ❌ W0 | ⬜ pending |
| 04-03-01 | 03 | 3 | COOK-01 | — | `EnsureFbpFbcCookies::handle()` honors `Settings::get('ensure_fbp_fbc_server_side', true)` kill switch — early-return when false. | unit | `composer test -- --filter=EnsureFbpFbcCookiesKillSwitchTest` | ❌ W0 | ⬜ pending |
| 04-03-02 | 03 | 3 | COOK-02 | T-04-COOK-01 | `_fbp` cookie format `fb.{index}.{ts_ms}.{rand}` written when missing on trusted host; cookie not overwritten when present. | unit | `composer test -- --filter=FbpCookieFormatTest` | ❌ W0 | ⬜ pending |
| 04-03-03 | 03 | 3 | COOK-03 | T-04-COOK-02 | `_fbc` cookie: fbclid validated `[A-Za-z0-9_-]` charset + ≤255 chars; invalid → skip `_fbc` (NOT throw). | unit | `composer test -- --filter=FbcCookieFbclidValidationTest` | ❌ W0 | ⬜ pending |
| 04-04-01 | 04 | 4 | FAIL-01 | — | `Backend\Controllers\FailedEvents` ListController shows columns event_id, event_name, adapter_type, http_status, attempts, created_at, graph_error snippet with filter widgets event_name + adapter_type + date range. Touches 15 files at the boundary — see Plan 04-04 §Scope Rationale (risk accepted: tightly-coupled MVC bundle, splitting would introduce churn). | feature | `composer test -- --filter=FailedEventsListControllerTest` | ❌ W0 | ⬜ pending |
| 04-04-02 | 04 | 4 | FAIL-02 | T-04-FAIL-01 | `onReplay($iRecordID)` AJAX handler re-dispatches event through `MetaClient`, increments `attempts`, flashes success on HTTP 200, surfaces `graph_error` on failure. CSRF-guarded. | feature | `composer test -- --filter=FailedEventsReplayHandlerTest` | ❌ W0 | ⬜ pending |
| 04-04-03 | 04 | 4 | FAIL-03 | — | `onCheckDedup` AJAX handler calls `MetaClient::fetchTestEventsStatus()` and returns JSON `{ dedup_rate, emq, per_event[] }` for current `test_event_code`. Tolerant parser handles missing Meta fields. | feature | `composer test -- --filter=FailedEventsCheckDedupHandlerTest` | ❌ W0 | ⬜ pending |
| 04-05-01 | 05 | 4 | LANG-01 | — | Every Settings field label, `commentAbove`, FailedEvents column label, action button (Replay, CheckDedup), backend menu label, and error message resolves to non-empty string via `Lang::get('logingrupa.metapixel::lang.*')` in en + lv. No raw lang keys leak to UI. | feature | `composer test -- --filter=LangKeyCoverageTest` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Unit/Settings/SettingsMultisitePropagatableTest.php` — stubs for MULT-01, MULT-02
- [ ] `tests/Unit/Settings/SettingsLookupForSiteTest.php` — stubs for MULT-03
- [ ] `tests/Unit/Settings/SettingsFormMultisiteScopeTest.php` — stubs for MULT-04
- [ ] `tests/Feature/Settings/MultisiteEightPathMatrixTest.php` — stubs for MULT-05 (depends on Phase 3 ShopaholicAdapter + ThemeActionAdapter test doubles)
- [ ] `tests/Unit/Updates/MigrationSiteIdAdditiveTest.php` — stubs for MULT-06
- [ ] `tests/Unit/Host/PslDependencyDeclaredTest.php` — stubs for HOST-01
- [ ] `tests/Unit/Host/PslRefreshCommandTest.php` — stubs for HOST-02
- [ ] `tests/Unit/Host/TrustedHostsSettingsValidationTest.php` — stubs for HOST-03
- [ ] `tests/Unit/Host/HostIndexResolverTest.php` — stubs for HOST-04, HOST-05, HOST-06 AND **D-10 stale-PSL operator nudge**. Required test cases include:
  - `test_resolve_returns_correct_index_for_apex_www_and_multi_tld_hosts` (HOST-04/05 — DataProvider)
  - `test_resolve_returns_null_for_unresolvable_hosts` (HOST-06 — DataProvider)
  - `test_resolve_memoizes_repeated_lookups` (memoization smoke)
  - **`test_stale_psl_emits_log_warning_once_when_filemtime_older_than_180_days`** — NEW per revision iteration 1: setUp copies fixture to tmp path via `tempnam()`, calls `touch($sTmpPath, Carbon::now()->subDays(200)->getTimestamp())` to set mtime 200 days in past; mocks `Log::shouldReceive('warning')->once()->with(Mockery::pattern('/^PSL snapshot is \d+ days old/'))`; instantiates `new HostIndexResolver($sTmpPath)`; calls `$obResolver->resolve('example.co.uk')` twice; Mockery verifies exactly one warning emitted (request-scoped one-shot latch). Teardown unlinks tmp.
  - **`test_fresh_psl_emits_no_warning_when_filemtime_within_180_days`** — companion: same setup but `Carbon::now()->subDays(30)`; asserts `Log::shouldNotReceive('warning')`.
- [ ] `tests/Feature/Host/HostIndexMultiTldMatrixTest.php` — stubs for HOST-05
- [ ] `tests/Feature/Host/HostIndexUntrustedFailsafeTest.php` — stubs for HOST-06
- [ ] `tests/Unit/Middleware/EnsureFbpFbcCookiesKillSwitchTest.php` — stubs for COOK-01
- [ ] `tests/Unit/Middleware/FbpCookieFormatTest.php` — stubs for COOK-02
- [ ] `tests/Unit/Middleware/FbcCookieFbclidValidationTest.php` — stubs for COOK-03
- [ ] `tests/Feature/Backend/FailedEventsListControllerTest.php` — stubs for FAIL-01
- [ ] `tests/Feature/Backend/FailedEventsReplayHandlerTest.php` — stubs for FAIL-02
- [ ] `tests/Feature/Backend/FailedEventsCheckDedupHandlerTest.php` — stubs for FAIL-03
- [ ] `tests/Feature/Lang/LangKeyCoverageTest.php` — stubs for LANG-01
- [ ] `tests/Fixtures/psl/public_suffix_list.test.dat` — hermetic PSL fixture for HostIndexResolver tests (avoid touching shipped PSL during tests)
- [ ] `tests/Doubles/FakeMetaClient.php` — MetaClient::fetchTestEventsStatus + dispatchEvent doubles for Replay + CheckDedup tests

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Two-site live operator UX — operator configures pixel_id + token on Site A + Site B and confirms admin Settings tabs show per-site values | MULT-05 (cross-check) | Requires live OctoberCMS multisite deployment with RainLab.Sites + two domains; cannot recreate Forge symlink layout inside CI | (1) Deploy plugin to staging Forge with two sites configured. (2) Backend > Settings > Logingrupa Metapixel — confirm site tabs render. (3) Save distinct credentials on each site. (4) Fire a sandbox Order on each site; tail EventLog rows — confirm `site_id` and `pixel_id` match each respective site. |
| Backend FailedEvents Replay button — visual confirmation of flash + table refresh after replay | FAIL-02 | AJAX flash + October backend AjaxResponse render is Twig/JS; automated test covers handler but not the rendered toast | Backend > System > FailedEvents > pick a failed row > click Replay > confirm flash banner appears + `attempts` column increments on table refresh. |
| Meta Dataset Quality endpoint live shape — tolerant parser handles whatever Meta returns | FAIL-03 | Meta does not publish a stable JSON contract for `/v23.0/{pixel_id}/?fields=event_match_quality,deduplication_rate`; only live calls confirm shape | (1) Set a real `test_event_code` in Settings. (2) Click CheckDedup. (3) Confirm UI shows dedup % + EMQ or graceful "data not available". (4) Tail Laravel log for `MetaClient` debug entries. |
| D-10 stale-PSL operator nudge — live log line surfaces when bundled PSL > 180 days old | D-10 | Production multi-month observation window; CI cannot fast-forward filemtime safely across full Laravel boot | (1) On a staging deployment, manually `touch -t 202410010000 plugins/logingrupa/metapixel/resources/data/public_suffix_list.dat` to set mtime ~7 months back. (2) Hit any page that triggers `HostIndexResolver::resolve` (e.g. a tracked order). (3) Tail `storage/logs/laravel.log` — confirm single `[warning] PSL snapshot is 218 days old — run php artisan metapixel:refresh-psl` line. (4) Hit the page a second time — confirm no duplicate warning in the same request (request-scoped one-shot). |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references (including D-10 stale-PSL test cases)
- [ ] No watch-mode flags
- [ ] Feedback latency < 45s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
