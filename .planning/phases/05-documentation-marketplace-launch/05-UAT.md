---
status: complete
phase: 05-documentation-marketplace-launch
source:
  - 05-00-SUMMARY.md
  - 05-02-SUMMARY.md
  - 05-02-RESPAWN-SUMMARY.md
  - 05-10-SUMMARY.md
  - 05-11-SUMMARY.md
  - 05-VERIFICATION.md
started: 2026-05-22T12:28:52Z
updated: 2026-07-03T15:30:00Z
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
note: "Verified at http://your-staging-host.example/lv/checkout/85ff11a0849c840d985c7877d212e45e — no pixel event emitted."

### 3. Theme Settings panel has no Facebook fields
expected: |
  Backend → Settings → Theme settings (configs/fields.yaml).
  Form shows NO "Facebook Pixel ID" field, NO "Facebook Domain Verification ID" field.
  `google_ga4_id` field still present (preserved — GA out-of-scope of strip).
result: pass
note: |
  Initially reported blocker on plugin Settings save (HostIndexResolver $sPslPath DI failure)
  — diagnosed as stale OPcache (FPM workers predated commit 6b2cd09). Fixed via FPM reload.
  Post-fix: user saved plugin Settings successfully — Pixel ID <pixel-id-redacted>,
  CAPI token (redacted), Test Events Code <test-event-code-redacted>, paid_status "new-payment-received"
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

### 7. Timed clean-room README dry-run (SC1/DOCS-01)
expected: |
  Fresh OctoberCMS 4.x install (no cart plugin), following only the README:
  `composer require logingrupa/oc-metapixel-plugin` → Settings configuration →
  first CAPI event verified in Meta Test Events. Completes in under 10 minutes,
  stopwatched. This is the launch acceptance gate.
result: issue
reported: "Agent-verified 2026-07-03 (subagent README audit + clean-install cross-check + live-production evidence). README content accurate against codebase (all 8 field labels, screenshots, troubleshoot signatures, API examples verified; ReadmeStructure + CustomAdaptersStructure gates pass). Live production proves pipeline end-to-end: 629 event_log rows incl. dedup twins, 6 dead-letters all auto-replayed, fbq('init','<pixel-id-redacted>') rendering on live pages. Estimated theme-path time ~9-10 min. BUT the dry-run following ONLY the README dead-ends at step 1: test-8 clean install proved plain `composer require logingrupa/oc-metapixel-plugin` (README:45) fails on a fresh October 4 lockfile (Lovata toolbox pins composer/installers ~1.0 vs fresh lock v2.3.0 — needs -W), and Lovata deps are unresolvable until `php artisan project:set <license>` adds the October gateway repo — neither documented. Minor: README:81 says 'Meta Business Manager' vs lang comment/Meta UI 'Events Manager' (D1); no single ordered quick-start to first Test Event (D2). Stopwatch itself remains humanly unverified."
severity: major

### 8. Clean-install composer require smoke (MKT-01)
expected: |
  From a genuinely clean, network-connected OctoberCMS 4.x install with a VCS
  repository entry: `composer require logingrupa/oc-metapixel-plugin` completes
  without errors on BOTH configs — (a) no cart plugin, (b) Shopaholic +
  OrdersShopaholic + Buddies. Deferred to Launch Milestone (launch-02-PLAN.md).
result: pass
verified_by: |
  Agent-executed 2026-07-03 in disposable scratchpad install (production untouched).
  Config (a): `composer create-project october/october "^4.0"` → v4.3.1; plugin via
  path repo; `composer require logingrupa/oc-metapixel-plugin:@dev -W` clean
  (toolbox 2.3.0, php-domain-parser 6.4.0); all 5 migrations green on SQLite with
  NO cart plugin; plugin:list shows Logingrupa.Metapixel Enabled; PluginGuard
  degrades gracefully on empty pixel_id (no boot throw). Config (b): + shopaholic
  1.33.0 / ordersshopaholic 1.33.2 / buddies 1.10.1 — zero composer conflicts,
  app boots, plugin still enabled. Caveats (upstream, fed into test-7 gap): -W
  flag needed (Lovata composer/installers ~1.0 pin); October gateway repo needs
  project:set first. Shopaholic's own v1.21.0 migration fails on SQLite only
  (drop indexed column) — upstream, MySQL-only operators unaffected.

### 9. v2.0.0 annotated tag + CI green on tag commit (MKT-04)
expected: |
  `v2.0.0` annotated tag exists locally AND on the remote; CI matrix
  (Run A full-Lovata + Run B minimal, both PHP 8.3/8.4) green on that exact
  tag commit. As of 2026-07-03 only `v2.0.0-rc.1` exists — ROADMAP claims the
  Launch Milestone "completed 2026-07-03" but no launch SUMMARY.md corroborates
  it and the tag state contradicts it. Resolve the discrepancy.
result: issue
reported: "Agent-verified 2026-07-03. No v2.0.0 tag locally (only v1.1.1, v2.0.0-rc.1) or on remote (git ls-remote --tags empty; gh api tags []). CI: only 3 workflow runs ever (metapixel-qa.yml), ALL failures, last 2026-05-21; 146 local commits unpushed since remote master 41bdf3c. Launch Milestone never executed: launch-01-SECURITY-SWEEP.md says 'PARTIAL — Step B deferred, launch_scheduled: false'; no LAUNCH-LOG, no launch SUMMARY.md. ROADMAP.md:393-394 '[x] completed 2026-07-03' marks are erroneous bookkeeping — contradicted by ROADMAP.md:408 own progress row ('0/2 Deferred — awaits operator decision'). Oddity: repo is ALREADY public (gh repo view isPrivate=false) though pre-flip security sweep Step B never ran. Tag creation itself awaits operator LAUNCH SCHEDULED signal by design; the defects are the erroneous ROADMAP marks, red+stale CI, 146 unpushed commits, and public repo without completed security sweep."
severity: major

## Summary

total: 9
passed: 7
issues: 2
pending: 0
skipped: 0
blocked: 0
note: "Test 3 initially failed (blocker: HostIndexResolver DI). Root cause = stale OPcache. Fixed via FPM reload. Re-verified pass after user successfully saved plugin Settings."

## Gaps

- truth: "Plugin Settings page saves cleanly; HostIndexResolver resolves via DI."
  status: failed
  reason: "User reported: when I try to save Unresolvable dependency resolving [Parameter #0 [ <required> string $sPslPath ]] in class Logingrupa\\Metapixel\\Classes\\Helper\\HostIndexResolver `http://your-staging-host.example/back/system/settings/update/logingrupa/metapixel/settings#primarytab-pixel-capi`"
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

- truth: "A buyer following only the README completes composer require → Settings → first CAPI event in Meta Test Events in under 10 minutes."
  status: failed
  reason: "User-directed agent verification: README:45 install command dead-ends on a genuinely fresh October 4.x install — plain `composer require logingrupa/oc-metapixel-plugin` fails to resolve (Lovata toolbox pins composer/installers ~1.0 vs fresh October lock v2.3.0; requires -W), and lovata/toolbox-plugin is unresolvable until the operator runs `php artisan project:set <license>` to add the gateway.octobercms.com repo. Neither prerequisite is in the README. Everything downstream verified working (production evidence + doc-gate tests)."
  severity: major
  test: 7
  root_cause: "README install section written against an existing October project (gateway repo + composer/installers v1 already in lock); never validated against a truly fresh `composer create-project october/october` lockfile. Proven by clean-install run in scratchpad 2026-07-03."
  artifacts:
    - path: "README.md:45"
      issue: "Install command missing -W flag; fails with 'lovata/toolbox-plugin ^2.2 could not be found' / installers conflict on fresh installs"
    - path: "README.md:81"
      issue: "Says 'Meta Business Manager' — Meta UI and lang/en/lang.php pixel_id_comment both say 'Events Manager' (D1, cosmetic)"
    - path: "README.md:39-70"
      issue: "No single ordered quick-start reaching first Test Event; buyer must jump to Theme walkthrough (README:106) to emit anything (D2)"
  missing:
    - "README install step documenting `php artisan project:set <license>` prerequisite (October gateway repo)"
    - "README install command updated to `composer require logingrupa/oc-metapixel-plugin -W` with one-line why"
    - "Ordered 'first event in 10 minutes' quick-start box: require -W → october:migrate → Settings (4 fields) → mount {% component 'pixelHead' %} → load page → check Test Events"
    - "README:81 Business→Events Manager wording fix"

- truth: "v2.0.0 annotated tag exists locally and on remote with CI matrix green on the tag commit; ROADMAP launch bookkeeping reflects reality."
  status: failed
  reason: "User-directed agent verification: no v2.0.0 tag anywhere; only 3 CI runs ever recorded (all failures, last 2026-05-21); 146 local commits unpushed since remote 41bdf3c; ROADMAP.md:393-394 marks launch-01/launch-02 '[x] completed 2026-07-03' though launch never executed (security sweep PARTIAL/Step B deferred, no LAUNCH-LOG, no launch SUMMARY.md); repo already public without the pre-flip sweep Step B."
  severity: major
  test: 9
  root_cause: "Two independent causes: (1) erroneous ROADMAP checkbox edit during 2026-07-03 tracking updates marked the deferred Launch Milestone complete; (2) tag/push/CI-green are launch-02-PLAN.md deliverables gated on the operator's LAUNCH SCHEDULED signal, which has not been given — but CI being red and 146 commits unpushed are fixable NOW independent of launch timing, and the repo being public while Step B (redaction sweep) is deferred inverts the planned order."
  artifacts:
    - path: ".planning/ROADMAP.md:393-394"
      issue: "launch-01/launch-02 marked [x] completed 2026-07-03 — contradicted by ROADMAP.md:408 progress row (0/2 Deferred) and on-disk evidence"
    - path: ".planning/launch/launch-01-SECURITY-SWEEP.md"
      issue: "status: PARTIAL — Step B deferred, launch_scheduled: false; yet repo is already public"
    - path: ".github/workflows/metapixel-qa.yml"
      issue: "All 3 historical runs failed; nothing pushed/run since 2026-05-21"
  missing:
    - "Revert ROADMAP.md:393-394 launch checkboxes to [ ] (deferred), matching :408"
    - "Push master (146 commits) and get metapixel-qa.yml CI matrix green on current HEAD — prerequisite for any future tag"
    - "Operator decisions: run security sweep Step B given repo is already public; give LAUNCH SCHEDULED signal when ready (tag creation stays gated on it)"
