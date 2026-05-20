---
phase: 04
slug: settings-rework-multisite-trustedhosts-cookie-failedevents-t
status: verified
threats_open: 0
asvs_level: 2
created: 2026-05-20
---

# Phase 4 — Security

> Per-phase security contract: threat register, accepted risks, and audit trail.
> Register authored at plan time (register_authored_at_plan_time: TRUE) — this audit verifies each declared mitigation against the implementation; it does not scan for new threats.

---

## Trust Boundaries

| Boundary | Description | Data Crossing |
|----------|-------------|---------------|
| Operator backend → Settings model | Admin saves per-site pixel_id / capi_access_token + trusted_hosts + kill switch via October Multisite top-bar site picker. Subject to October's backend auth + CSRF. | pixel_id, capi_access_token (credentials), trusted_hosts list, kill-switch flag |
| `SendCapiEvent::handle` → Meta CAPI | Job rehydrates Settings via `lookupForSite($iSiteId)` and POSTs to `graph.facebook.com/v23.0/{pixel}/events`. | Per-site CAPI credentials + event payload |
| Browser HTTP request → `EnsureFbpFbcCookies` middleware → response cookies | Untrusted Host header + fbclid query + existing cookies. Middleware writes `_fbp` / `_fbc` Symfony cookies. | Host header, fbclid query, request cookies, Set-Cookie response headers |
| `metapixel:refresh-psl` → `https://publicsuffix.org` | One-way HTTPS GET to a pinned constant URL (no operator override). Atomic rename, sentinel validation, cache wipe. | Public Suffix List bytes |
| Backend admin browser → AJAX POST `/backend/logingrupa/metapixel/failedevents/onReplay\|onCheckDedup\|onDeleteBatch` | October backend AJAX framework enforces CSRF token + admin session. | record_id, checked[] from operator → dispatched payload to Meta |
| Meta Graph API response → `dedup_pct` / `emq` / `dedup_checked_at` columns | Tolerant `?? null` JSON parser; numeric casts on persistence; HTML escape in `_graph_error.htm` partial. | Untrusted upstream JSON |
| `Lang::get` ← any YAML config / Twig / controller flash | Read-only lang file load; arrays are `require`'d PHP literals — no user-input boundary. | EN/LV translation strings |

---

## Threat Register

26 threats: 23 mitigated, 3 accepted (documented). All threats CLOSED.

### Plan 04-01 (Settings Multisite)

| Threat ID | Category | Component | Disposition | Mitigation | Status |
|-----------|----------|-----------|-------------|------------|--------|
| T-04-MULT-01 | Information Disclosure | `Settings::lookupForSite` | mitigate | D-01 silent default-row fallback at `models/Settings.php:62-79` (`lookupForSite(?int)` returns per-site row, falls back when empty); D-02 PHPStan `disallowedStaticCalls` rule at `phpstan.neon:104-133` bans direct `Settings::get('pixel_id'\|'capi_access_token')` outside `models/Settings.php` + `classes/helper/PluginGuard.php`. Verified by `tests/Feature/Settings/SettingsLookupForSiteTest.php::test_lookup_for_site_with_id_returns_per_site_row`. | closed |
| T-04-MULT-02 | Information Disclosure (P-10) | `$propagatable` whitelist drift | mitigate | Explicit `protected $propagatable = []` declared at descendant level at `models/Settings.php:38`. Verified by `tests/Unit/Models/SettingsMultisiteTraitTest.php::test_pixel_id_is_not_in_propagatable` + `test_capi_access_token_is_not_in_propagatable`. | closed |
| T-04-MULT-03 | Tampering | `SettingModel::$instances` cache leak | mitigate | Sidestepped by Rule-1 deviation in 04-01-SUMMARY: `readCredentialsInGlobalContext` + `readCredentialsForSiteContext` use direct `DB::table('system_settings')` queries (`models/Settings.php:94-148`) bypassing the SettingModel cache entirely. 8-path matrix verified by `tests/Feature/MultisiteEventLogRoutingTest.php` (10 cases). | closed |
| T-04-MULT-04 | Denial of Service | Migration runs before `system_settings` exists on fresh install | mitigate | `Schema::hasTable('system_settings')` guard at `updates/AddMultisitePixelIdAndToken.php:21-23`. Verified by `tests/Feature/Migrations/AddMultisitePixelIdAndTokenTest.php::test_up_is_idempotent_when_system_settings_table_absent`. | closed |

### Plan 04-02 (TrustedHosts + PSL)

| Threat ID | Category | Component | Disposition | Mitigation | Status |
|-----------|----------|-----------|-------------|------------|--------|
| T-04-HOST-01 (CR-02) | Tampering | `HostIndexResolver::resolve` naive parser exploit | mitigate | `jeremykendall/php-domain-parser ^6.4` in `composer.json:14` (under `require:`, not `require-dev:`); `classes/helper/HostIndexResolver.php:6-7,56-59` uses `Pdp\Domain::fromIDNA2008` + `Pdp\Rules::resolve`. HOST-05 multi-TLD matrix in `tests/Unit/Helper/HostIndexResolverTest.php`. | closed |
| T-04-HOST-02 (P-18) | Denial of Service | PSL cache write to read-only Forge release dir | mitigate | Cache path locked to `storage_path('app/metapixel/psl')` at `console/RefreshPsl.php:78`. | closed |
| T-04-HOST-03 | Tampering / Spoofing | Untrusted host bypass via Host header spoof | mitigate | `Settings::partitionHosts` at `models/Settings.php:237-261` rejects unknown-TLD lines via `HostIndexResolver::resolve` null return; `HostIndexResolver::resolve` at `classes/helper/HostIndexResolver.php:44-75` returns null on unknown suffix. Downstream middleware (T-04-COOK-03) treats null as NO-OP. | closed |
| T-04-HOST-04 (D-10) | Repudiation | Operator runs stale PSL for months — silent drift | mitigate | `HostIndexResolver::checkPslAge` at `classes/helper/HostIndexResolver.php:84-107` emits one `Log::warning('PSL snapshot is N days old — run php artisan metapixel:refresh-psl')` per request when `filemtime > 180 days` (15_552_000 sec constant). One-shot latch via `$bStaleWarningEmitted`. | closed |
| T-04-PSL-01 | Tampering / SSRF | `metapixel:refresh-psl` fetches arbitrary URL | mitigate | URL pinned to `UPSTREAM_URL = 'https://publicsuffix.org/list/public_suffix_list.dat'` constant at `console/RefreshPsl.php:24`; not operator-configurable. `SENTINEL` validation at `console/RefreshPsl.php:26,57` + atomic `rename()` + tmp cleanup at `console/RefreshPsl.php:63-76`. | closed |
| T-04-PSL-02 | Repudiation | Operator save persists invalid trusted_hosts → silent runtime breakage | mitigate | D-14 strict halt via `throw new ModelException` at `models/Settings.php:187` after `Flash::error` listing rejected hosts (`models/Settings.php:177-185`). Verified by `tests/Feature/Settings/TrustedHostsValidationTest.php`. | closed |
| T-04-SC (carry) | Tampering | Composer install legitimacy for `jeremykendall/php-domain-parser` | mitigate | `Task 0 checkpoint:human-verify` gate in PLAN 04-02 cleared by operator (per 04-02-SUMMARY: packagist metadata verified — MIT, 13.4M installs, last release 2025-04-26, ext-intl present). | closed |

### Plan 04-03 (Cookie middleware)

| Threat ID | Category | Component | Disposition | Mitigation | Status |
|-----------|----------|-----------|-------------|------------|--------|
| T-04-COOK-01 (CR-03) | Tampering / XSS | fbclid query injection into `_fbc` cookie value | mitigate | `preg_match('/^[A-Za-z0-9_-]+$/', $sFbclid) !== 1 → return;` at `middleware/EnsureFbpFbcCookies.php:230-232`; `strlen > 255` short-circuit at `middleware/EnsureFbpFbcCookies.php:227-229`. Verified by 3 named tests in `tests/Feature/Middleware/EnsureFbpFbcCookiesTest.php` (`test_invalid_fbclid_charset_skips_fbc`, `test_oversize_fbclid_skips_fbc`, `test_exactly_255_char_fbclid_is_accepted`). | closed |
| T-04-COOK-02 | Information Disclosure | Cookie domain attribute misderived → wrong-scope cookie | mitigate | `Cookie::create` invocations at `middleware/EnsureFbpFbcCookies.php:197-207` (fbp) and `middleware/EnsureFbpFbcCookies.php:238-248` (fbc) both pass domain `null` as the 5th positional argument. PSL-derived `$iIndex` encodes the cookie VALUE only. | closed |
| T-04-COOK-03 (P-15) | Tampering / Spoofing | Host-header spoofing → cookies set on attacker host | mitigate | Untrusted-host short-circuit at `middleware/EnsureFbpFbcCookies.php:47-50` (`! in_array($sHost, $arTrustedHosts, true) → return $obResponse;`); PSL-unresolvable host short-circuit at `middleware/EnsureFbpFbcCookies.php:52-55`. Verified by `test_untrusted_host_writes_no_cookies` + `test_psl_unresolvable_host_writes_no_cookies`. | closed |
| T-04-COOK-04 (Pitfall 8) | Denial of Service | `Settings::get` throws on fresh install → middleware 500s migration HTTP request | mitigate | Two try/catch wrappers in `middleware/EnsureFbpFbcCookies.php:113-137` (shouldSkip) and `middleware/EnsureFbpFbcCookies.php:148-158` (readTrustedHosts), both emitting `Log::warning` + returning safe defaults. Verified by `test_settings_get_throwing_does_not_500`. | closed |
| T-04-COOK-05 (CR-03 length) | Resource exhaustion | Multi-KB fbclid payload | mitigate | 255-char hard cap via `strlen($sFbclid) > self::FBCLID_MAX_LENGTH` check at `middleware/EnsureFbpFbcCookies.php:227-229` runs BEFORE `preg_match` (O(1) length test first). | closed |
| T-04-COOK-06 | Tampering | Cookie poisoning via shared-cache `Cache-Control: public` response | accept | Operator-responsibility risk — accepted with documentation. See Accepted Risks Log entry R-04-01. Class-level docblock at `middleware/EnsureFbpFbcCookies.php:21-22` documents the operator obligation: "Operator MUST serve routes hitting this middleware with Cache-Control: private to prevent shared-cache cookie leakage. See README 'Cookie middleware' section." | closed |

### Plan 04-04 (FailedEvents controller)

| Threat ID | Category | Component | Disposition | Mitigation | Status |
|-----------|----------|-----------|-------------|------------|--------|
| T-04-FAIL-01 | CSRF | `onReplay` / `onCheckDedup` / `onDeleteBatch` AJAX handlers | mitigate | `controllers/FailedEvents.php:39` extends `Backend\Classes\Controller`; constructor calls `parent::__construct()` at line 51 wiring October's backend middleware stack (auto-includes VerifyCsrfToken — X-XSRF-TOKEN header). `tests/Feature/Controllers/FailedEventsReplayTest.php::test_on_replay_record_id_zero_or_missing_rejects` exercises the controller-side reject path. | closed |
| T-04-FAIL-02 | Tampering | `record_id` parameter tampering via crafted AJAX | mitigate | `postRecordId()` helper at `controllers/FailedEvents.php:339-350` narrows `post('record_id')` to int via `is_int` + `is_string && ctype_digit` guards (returns 0 on non-numeric); `findRowOrFail(int)` at `controllers/FailedEvents.php:389-403` rejects id ≤ 0 with `Flash::error` + `RuntimeException` AND rejects missing rows. Verified by `test_on_replay_record_id_zero_or_missing_rejects`. | closed |
| T-04-FAIL-03 | Information Disclosure | Replay sends payload to wrong pixel under multisite | accept | D-01 + Open Question 1 Option A: FailedEvent has no site_id column in v2.0; Replay calls `Settings::lookupForSite(null)` at `controllers/FailedEvents.php:179-180` (default-row credentials). See Accepted Risks Log entry R-04-02. Class-level WARNING docblock at `controllers/FailedEvents.php:28-38` documents the operator obligation; v2.1 deferred for the contract expansion. | closed |
| T-04-FAIL-04 | Tampering | Untrusted Meta Graph API response injects into FailedEvent columns | mitigate | `$casts = ['dedup_pct' => 'float', 'emq' => 'float', 'dedup_checked_at' => 'datetime', ...]` at `models/FailedEvent.php:56-62`; tolerant parser `extractMetricForEventName` at `controllers/FailedEvents.php:306-320` uses `is_array + array_key_exists + is_numeric` guard chain returning `?float` (never throws); MetaClient response read via `?? null` on every field at `classes/meta/MetaClient.php:157-158`; `_graph_error.htm` partial uses `e()` escape at `models/failedevent/_graph_error.htm:5`. | closed |
| T-04-FAIL-05 | Authorisation | Non-admin user accesses FailedEvents controller | mitigate | October's `auth` middleware enforces backend session on every controller extending `Backend\Classes\Controller`. `Plugin::registerSettings` registers the entry under SettingsManager parent at `Plugin.php:179-185` so the menu requires admin access. Permissions array deferred to Phase 5 polish. | closed |
| T-04-FAIL-SC | Tampering | Supply-chain: composer install of new packages this plan | mitigate | Zero new composer dependencies in plan 04-04 — verified by `composer.json` diff (PSL parser already landed plan 04-02; no new `require` entries). SC threat dormant. | closed |

### Plan 04-05 (Translations)

| Threat ID | Category | Component | Disposition | Mitigation | Status |
|-----------|----------|-----------|-------------|------------|--------|
| T-04-LANG-01 | Information Disclosure | Raw English string leak in UI when LV locale active | mitigate | `tests/Feature/Lang/LangKeyCoverageTest.php::test_lv_key_shape_matches_en` enforces canonical key-shape parity via Pest `toEqualCanonicalizing` matcher (line 128); 8 test cases including no-RU lock + no-blank-leaf + no-machine-translation-stub assertions. | closed |
| T-04-LANG-02 | Tampering / XSS | Lang strings injected with HTML markup | accept | All Phase 4 lang strings authored as plain prose; consumers escape via `<?= e(trans(...)) ?>` in toolbar partials and Twig auto-escape in fields.yaml. See Accepted Risks Log entry R-04-03. | closed |
| T-04-LANG-03 | Repudiation | `:rejected` placeholder unsubstituted in operator Flash | mitigate | `Settings::beforeSaveTrustedHosts` at `models/Settings.php:177-185` inlines the value at runtime via `Flash::error('metapixel: rejected trusted_hosts ... ' . implode(', ', $arRejected))` — placeholder-free runtime path. The `exception.invalid_trusted_hosts` lang key (with `:rejected` placeholder) is reserved for future README/documentation use; operator runtime never sees it unsubstituted. | closed |

---

## Accepted Risks Log

| Risk ID | Threat Ref | Rationale | Accepted By | Date |
|---------|------------|-----------|-------------|------|
| R-04-01 | T-04-COOK-06 | Cookie poisoning via shared-cache `Cache-Control: public` is an operator-platform responsibility outside the middleware's control. The middleware does not auto-set Cache-Control headers (would conflict with operator's CMS-side response shaping). Class-level docblock at `middleware/EnsureFbpFbcCookies.php:21-22` documents the operator obligation; README "Cookie middleware" section (DOCS-01 — Phase 5) will carry the full operator guidance. Severity: medium (requires misconfigured cache layer + active attacker on the same edge cache to weaponise). | Plan 04-03 author | 2026-05-20 |
| R-04-02 | T-04-FAIL-03 | FailedEvent has no `site_id` column in v2.0 (Open Question 1 Option A). Replay uses `Settings::lookupForSite(null)` → default-row credentials per D-01 semantics. Multi-site operators (.no/.lv/.lt) MUST configure default-row credentials as their primary site's pixel — Replay through a non-primary-site row will dispatch under the wrong pixel ID. Class-level WARNING docblock at `controllers/FailedEvents.php:28-38` documents the runtime contract; README troubleshooting (DOCS-01 — Phase 5) will document. Contract expansion (adapter `getSiteIdForReplay()` + site_id schema column) deferred to v2.1. Severity: low (replay is operator-initiated, infrequent, dead-letter rescue path; wrong-pixel dispatch produces Graph API permanent error rather than data leak). | Plan 04-04 author | 2026-05-20 |
| R-04-03 | T-04-LANG-02 | All Phase 4 lang strings are plain prose (no embedded HTML). Consumers escape via `<?= e(trans(...)) ?>` in toolbar partials and Twig auto-escape in fields.yaml. Any future operator-supplied translation override would require an explicit `October.System.Translate` admin role; the trust boundary is the same as direct theme editing. Severity: low (operator-trusted authoring surface; no untrusted-user injection path). | Plan 04-05 author | 2026-05-20 |

---

## Security Audit Trail

| Audit Date | Threats Total | Closed | Open | Run By |
|------------|---------------|--------|------|--------|
| 2026-05-20 | 26 | 26 | 0 | gsd-security-auditor (verifier mode, register_authored_at_plan_time) |

---

## Unregistered Flags

None. All 5 plan SUMMARY files explicitly report `Threat Flags: None`. The implementation surface declared in PLAN.md was the same surface implemented (verified by file-by-file grep). No new attack surface emerged during implementation.

---

## Sign-Off

- [x] All threats have a disposition (mitigate / accept / transfer) — 23 mitigate, 3 accept, 0 transfer
- [x] Accepted risks documented in Accepted Risks Log — R-04-01 (T-04-COOK-06), R-04-02 (T-04-FAIL-03), R-04-03 (T-04-LANG-02)
- [x] `threats_open: 0` confirmed
- [x] `status: verified` set in frontmatter

**Approval:** verified 2026-05-20
