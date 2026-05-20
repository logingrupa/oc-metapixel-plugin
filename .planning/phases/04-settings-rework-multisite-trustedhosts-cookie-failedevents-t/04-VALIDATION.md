---
phase: 4
slug: settings-rework-multisite-trustedhosts-cookie-failedevents-t
status: approved
nyquist_compliant: true
wave_0_complete: true
created: 2026-05-19
revised: 2026-05-20  # iteration 2 — post-execution audit; mapped per-task tests to actual filenames (refactored during execution), confirmed 388 tests green
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
| 04-01-01 | 01 | 1 | MULT-01, MULT-02 | — | `Settings` model declares `$propagatable = []` whitelist lock (cross-site token leak guard) | unit | `composer test -- --filter=SettingsMultisiteTraitTest` | ✅ | ✅ green |
| 04-01-02 | 01 | 1 | MULT-03 | T-04-MULT-01 | `Settings::lookupForSite($iSiteID)` returns per-site `pixel_id` / `capi_access_token` with no fallback bleed across sites | unit | `composer test -- --filter=SettingsLookupForSiteTest` | ✅ | ✅ green |
| 04-01-03 | 01 | 1 | MULT-04 | — | Settings extends `Lovata\Toolbox\Models\Settings` (CommonSettings) — parent provides multisite tab rendering; `$propagatable = []` scopes whitelisted fields | unit | `composer test -- --filter=SettingsCommonSettingsParentTest` | ✅ | ✅ green |
| 04-01-04 | 01 | 1 | MULT-05 | T-04-MULT-02 | 8-path matrix (2 sites × 2 adapters × 2 channels): EventLog rows independent under UNIQUE NULL-distinct semantics | feature | `composer test -- --filter=MultisiteEventLogRoutingTest` | ✅ | ✅ green |
| 04-01-05 | 01 | 1 | MULT-06 | — | `AddMultisitePixelIdAndToken` migration is idempotent + no-op-safe on October-managed `system_settings` | unit | `composer test -- --filter=AddMultisitePixelIdAndTokenTest` | ✅ | ✅ green |
| 04-02-01 | 02 | 1 | HOST-01 | — | `composer.json` declares `jeremykendall/php-domain-parser ^6.4` in `require:` (not require-dev) — verified at composer.json:14; transitively exercised by RefreshPslTest + HostIndexResolverTest running green | unit | `composer test -- --filter=RefreshPslTest` (transitive) | ✅ | ✅ green |
| 04-02-02 | 02 | 1 | HOST-02 | — | `resources/data/public_suffix_list.dat` bundled with plugin; `metapixel:refresh-psl` artisan command refreshes to `storage/app/metapixel/psl/` (Forge-writable, NOT in release dir — P-18 fence) | unit | `composer test -- --filter=RefreshPslTest` | ✅ | ✅ green |
| 04-02-03 | 02 | 2 | HOST-03 | — | `Settings::get('trusted_hosts')` Settings textarea: one host per line; normalized + de-duped + lowercased on save | unit | `composer test -- --filter=TrustedHostsValidationTest` | ✅ | ✅ green |
| 04-02-04a | 02 | 2 | HOST-04 | T-04-HOST-01 | `HostIndexResolver::resolve(string $sHost): ?int` returns correct subdomain depth across apex/www/multi-TLD/IDN. Naive `explode('.')` banned (PHPStan disallowed-calls). | unit | `composer test -- --filter=HostIndexResolverTest::test_resolve_returns_expected_subdomain_index` | ✅ | ✅ green |
| 04-02-04b | 02 | 2 | D-10 | T-04-HOST-04 | `HostIndexResolver` constructor latches `$bStaleWarningEmitted`; on first `resolve()` call, if `time() - filemtime > 180 days`, emits exactly one `Log::warning`. Second call in same request emits zero additional warnings. | unit | `composer test -- --filter=HostIndexResolverTest::test_stale_psl_emits_log_warning_once_when_filemtime_older_than_180_days` | ✅ | ✅ green |
| 04-02-05 | 02 | 2 | HOST-05 | T-04-HOST-02 | Multi-TLD fixture matrix: apex + www + `co.uk` + IDN `xn--bcher-kva.example` + exotic `shop.example.com.br` — all derive correct subdomain index. | feature | `composer test -- --filter=HostIndexResolverTest::test_resolve_returns_expected_subdomain_index` (DataProvider) | ✅ | ✅ green |
| 04-02-06 | 02 | 2 | HOST-06 | T-04-HOST-03 | Untrusted host → `HostIndexResolver::resolve()` returns null → middleware NO-OPs, no exception, no cookies set (fail-safe). | feature | `composer test -- --filter=HostIndexResolverTest::test_resolve_returns_null_for_unresolvable_hosts` + `EnsureFbpFbcCookiesTest::test_untrusted_host_writes_no_cookies` | ✅ | ✅ green |
| 04-03-01 | 03 | 3 | COOK-01 | — | `EnsureFbpFbcCookies::handle()` honors `Settings::get('ensure_fbp_fbc_server_side', true)` kill switch — early-return when false. | unit | `composer test -- --filter=EnsureFbpFbcCookiesTest::test_kill_switch_off_skips_cookie_write` | ✅ | ✅ green |
| 04-03-02 | 03 | 3 | COOK-02 | T-04-COOK-01 | `_fbp` cookie format `fb.{index}.{ts_ms}.{rand}` written when missing on trusted host; cookie not overwritten when present. | unit | `composer test -- --filter=EnsureFbpFbcCookiesTest::test_fbp_cookie_format_matches_fb_index_ms_random` | ✅ | ✅ green |
| 04-03-03 | 03 | 3 | COOK-03 | T-04-COOK-02 | `_fbc` cookie: fbclid validated `[A-Za-z0-9_-]` charset + ≤255 chars; invalid → skip `_fbc` (NOT throw). | unit | `composer test -- --filter=EnsureFbpFbcCookiesTest::test_invalid_fbclid_charset_skips_fbc` + `test_oversize_fbclid_skips_fbc` + `test_exactly_255_char_fbclid_is_accepted` | ✅ | ✅ green |
| 04-04-01 | 04 | 4 | FAIL-01 | — | `Backend\Controllers\FailedEvents` ListController declares yaml/columns/filters per Plan 04-04 §Scope Rationale (bundled MVC, 15-file boundary accepted). | feature | `composer test -- --filter=FailedEventsListTest` | ✅ | ✅ green |
| 04-04-02 | 04 | 4 | FAIL-02 | T-04-FAIL-01 | `onReplay($iRecordID)` AJAX handler re-dispatches event through `MetaClient`, increments `attempts`, flashes success on HTTP 200, surfaces `graph_error` on failure. CSRF-guarded by October Backend Controller. | feature | `composer test -- --filter=FailedEventsReplayTest` | ✅ | ✅ green |
| 04-04-03 | 04 | 4 | FAIL-03 | — | `onCheckDedup` AJAX handler calls Meta Graph API and writes `dedup_pct` / `emq` / `dedup_checked_at` columns. Tolerant parser handles missing Meta fields. | feature | `composer test -- --filter=FailedEventsCheckDedupTest` | ✅ | ✅ green |
| 04-05-01 | 05 | 4 | LANG-01 | — | Every Settings field label, `commentAbove`, FailedEvents column label, action button (Replay, CheckDedup), backend menu label, and error message resolves to non-empty string in en + lv. `toEqualCanonicalizing` parity enforced. | feature | `composer test -- --filter=LangKeyCoverageTest` | ✅ | ✅ green |

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

- [x] All tasks have `<automated>` verify or Wave 0 dependencies
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all MISSING references (including D-10 stale-PSL test cases)
- [x] No watch-mode flags
- [x] Feedback latency < 45s — full suite 18.07s (388 tests, 1455 assertions)
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** approved 2026-05-20

## Validation Audit 2026-05-20

| Metric | Count |
|--------|-------|
| Gaps found | 0 |
| Resolved | 0 |
| Escalated | 0 |
| Per-task rows mapped to actual test files | 19 / 19 |
| Pest suite | 388 passed (1455 assertions) in 18.07s |

Test filenames in the original Per-Task Map diverged from on-disk files due to consolidation during execution (e.g. `EnsureFbpFbcCookiesKillSwitchTest` + `FbpCookieFormatTest` + `FbcCookieFbclidValidationTest` merged into `EnsureFbpFbcCookiesTest`; `HostIndexResolverTest` carries HOST-04/05/06 via DataProviders + the D-10 stale-PSL one-shot latch tests). Coverage substance is intact — table above re-maps each Task ID to the actual test file + named test method.
