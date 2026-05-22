---
status: partial
phase: 05-documentation-marketplace-launch
source:
  - 05-00-SUMMARY.md
  - 05-02-SUMMARY.md
  - 05-02-RESPAWN-SUMMARY.md
  - 05-10-SUMMARY.md
  - 05-11-SUMMARY.md
started: 2026-05-22T12:28:52Z
updated: 2026-05-22T12:40:00Z
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
result: issue
reported: "when I try to save Unresolvable dependency resolving [Parameter #0 [ <required> string $sPslPath ]] in class Logingrupa\\Metapixel\\Classes\\Helper\\HostIndexResolver `http://new.nailscosmetics.lv/back/system/settings/update/logingrupa/metapixel/settings#primarytab-pixel-capi`"
severity: blocker
note: |
  User navigated to plugin Settings (Logingrupa.Metapixel) rather than theme Settings.
  Saving plugin Settings throws "Unresolvable dependency resolving [Parameter #0 [ <required> string $sPslPath ]]"
  on `HostIndexResolver`. DI container has no binding for the constructor's $sPslPath argument.
  Likely missing `App::singleton` / `App::bind` in `Plugin::register()` or `Plugin::boot()` that
  injects the `resources/data/public_suffix_list.dat` path. Blocks all Settings save operations.
  Theme Settings (configs/fields.yaml) check NOT performed — re-run after fix.

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
passed: 5
issues: 1
pending: 0
skipped: 0
blocked: 0

## Gaps

- truth: "Plugin Settings page saves cleanly; HostIndexResolver resolves via DI."
  status: failed
  reason: "User reported: when I try to save Unresolvable dependency resolving [Parameter #0 [ <required> string $sPslPath ]] in class Logingrupa\\Metapixel\\Classes\\Helper\\HostIndexResolver `http://new.nailscosmetics.lv/back/system/settings/update/logingrupa/metapixel/settings#primarytab-pixel-capi`"
  severity: blocker
  test: 3
  artifacts: []
  missing: []
  debug_session: ""
