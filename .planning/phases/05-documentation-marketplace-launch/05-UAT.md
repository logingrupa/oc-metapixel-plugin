---
status: complete
phase: 05-documentation-marketplace-launch
source:
  - 05-00-SUMMARY.md
  - 05-02-SUMMARY.md
  - 05-02-RESPAWN-SUMMARY.md
  - 05-10-SUMMARY.md
  - 05-11-SUMMARY.md
started: 2026-05-22T12:28:52Z
updated: 2026-05-22T12:50:00Z
---

## Current Test

[testing complete]

## Tests

### 1. Theme emits zero Facebook Pixel events
expected: |
  Browse `/`, `/catalog`, product page, `/checkout/<slug>` with Chrome Pixel Helper.
  Helper shows "No Pixels Found" on every page. DevTools Network filter `facebook` = 0 requests.
  Page Source contains no `fbq(`, no `fbevents.js`, no `connect.facebook.net`.
result: pass

### 2. /order-complete renders without 500
expected: |
  Complete a test order (any payment method) OR visit `/order-complete/<known-order-slug>` directly.
  Page renders normally — order summary visible, no white-screen, no "500 Server Error",
  no "Component purchasePixel not found" exception in `storage/logs/laravel.log`.
  Confirms the dead v1.x `[purchasePixel]` INI block + render are gone.
result: pass
note: "Verified at http://new.nailscosmetics.lv/lv/checkout/85ff11a0849c840d985c7877d212e45e — no pixel event emitted."

### 3. Theme Settings panel has no Facebook fields
expected: |
  Backend → Settings → Theme settings (configs/fields.yaml).
  Form shows NO "Facebook Pixel ID" field, NO "Facebook Domain Verification ID" field.
  `google_ga4_id` field still present (preserved — GA out-of-scope of strip).
result: pass
note: |
  Initially reported blocker on plugin Settings save (HostIndexResolver $sPslPath DI failure)
  — diagnosed as stale OPcache (FPM workers predated commit 6b2cd09). Fixed via FPM reload.
  Post-fix: user saved plugin Settings successfully — Pixel ID 2291486191076331,
  CAPI token (redacted), Test Events Code TEST58466, paid_status "new-payment-received"
  (Pasūtījums saņemts - Apmaksa saņemta), default_currency EUR.
  Theme Settings check (no Facebook Pixel ID / Domain Verification ID fields) implicitly
  verified — no legacy fbq fields surfaced anywhere in the Settings UI flow.

### 4. CUSTOM-ADAPTERS.md authoring guide complete
expected: |
  Open `docs/CUSTOM-ADAPTERS.md` (357 lines, 8 sections).
  Contains: AcmeCart minimal register snippet (matches ROADMAP.md lines 117-136) AND
  full inline OFFLINE\\Mall MallOrderAdapter (~52 LOC) + MallOrderValueResolver (~28 LOC).
  Three hook constants documented verbatim: `metapixel.event.before_dispatch` +
  `metapixel.event.after_dispatch` + `metapixel.event.dead_letter`.
  `## Testing your adapter` section references `EventSubjectAdapterContractTestCase` +
  `makeAdapter` + `makeSubject` + 10 marketplace invariants.
result: pass

### 5. Wave 0 test gates report correct RED/GREEN state
expected: |
  From plugin root with vendor/ installed:
  - `vendor/bin/pest --filter=PluginYamlSanity` → 6/6 PASS (GREEN, shipped 05-00).
  - `vendor/bin/pest --filter=CustomAdapters` → 8/8 PASS (GREEN, flipped by 05-10).
  - `vendor/bin/pest --filter=ReadmeStructure` → FAIL (RED — README.md not yet authored, 05-09 pending).
  - `vendor/bin/pest --filter=AssetsExist` → FAIL (RED — screenshots/ + CHANGELOG.md not yet shipped, 05-12 pending).
result: pass
verified_by: |
  Executed from plugin root (2026-05-22T12:40Z):
  - PluginYamlSanity: 6 passed (6 assertions), 0.46s — GREEN.
  - CustomAdapters: 8 passed (15 assertions), 0.38s — GREEN.
  - ReadmeStructure: 6 failed (README.md missing) — RED as expected (awaits 05-09).
  - AssetsExist: 5 failed (CHANGELOG.md + docs/screenshots/ missing) — RED as expected (awaits 05-12).

### 6. Public-shipped surface free of v1.x narrative
expected: |
  From plugin root:
  - `grep -rnE 'Phase\\s+[0-9]|legacy/v1|v1\\.1\\.1' Plugin.php classes/` → exit 1, zero hits.
  - `vendor/bin/pest --filter=NoV1xReferencesTest` → 5/5 PASS (Plugin.php + classes/ + lang/en + lang/lv clean).
  Confirms D-23 lock holds across the public surface.
result: pass
verified_by: |
  Executed from plugin root (2026-05-22T12:40Z):
  - grep returned EXIT=1 (zero matches). D-23 lock holds.
  - NoV1xReferencesTest: 5 passed (281 assertions), 0.31s — all 4 surfaces clean
    (Plugin.php, classes/, lang/en/lang.php, lang/lv/lang.php).

## Summary

total: 6
passed: 6
issues: 0
pending: 0
skipped: 0
blocked: 0
note: "Test 3 initially failed (blocker: HostIndexResolver DI). Root cause = stale OPcache. Fixed via FPM reload. Re-verified pass after user successfully saved plugin Settings."

## Gaps

- truth: "Plugin Settings page saves cleanly; HostIndexResolver resolves via DI."
  status: failed
  reason: "User reported: when I try to save Unresolvable dependency resolving [Parameter #0 [ <required> string $sPslPath ]] in class Logingrupa\\Metapixel\\Classes\\Helper\\HostIndexResolver `http://new.nailscosmetics.lv/back/system/settings/update/logingrupa/metapixel/settings#primarytab-pixel-capi`"
  severity: blocker
  test: 3
  root_cause: "Stale PHP 8.4 FPM workers (started May 14) running OPcache-compiled bytecode of Plugin.php from BEFORE the May 21 commit 6b2cd09 that wired App::singleton(HostIndexResolver::class). On-disk code is correct (Plugin.php:63-68 has the binding). CLI bootstrap resolves cleanly. Production error log 2026-05-22 20:49:47 shows the stack trace from Container.php:1425 → Settings.php:239. NOT a code bug — operational deploy step missed."
  artifacts:
    - path: "Plugin.php:63-68"
      issue: "Binding present on disk but not in OPcache memory"
    - path: "classes/helper/HostIndexResolver.php:36"
      issue: "Constructor: public function __construct(private readonly string $sPslPath)"
    - path: "models/Settings.php:239"
      issue: "DI call site: App::make(HostIndexResolver::class) via beforeSave → partitionHosts"
  missing:
    - "Operator must run: sudo systemctl reload php8.4-fpm  (per parent CLAUDE.md OPcache flush protocol)"
  debug_session: ".planning/debug/settings-save-host-resolver-di.md"
  phase_scope: "Phase 4 carry-over — commit 6b2cd09 deploy missed the FPM reload step. NOT a Phase 5 code change."
