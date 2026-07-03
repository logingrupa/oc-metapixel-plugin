---
phase: 05-documentation-marketplace-launch
asvs_level: 1
block_on: high
threats_total: 40
threats_closed: 40
threats_open: 0
audited: 2026-07-03
auditor: gsd-security-auditor
verdict: SECURED
---

# Phase 05 — Security Audit (Threat Verification)

Retroactive verification of every declared threat mitigation across the 16
plan-time `<threat_model>` registers in phase 05. ASVS L1: each mitigation
verified PRESENT in the cited file/surface. Implementation files were read-only;
nothing was patched. Register total is 40 unique threats (T-05-03 is declared in
both 05-08 and 05-12 — deduped once).

## Result

All 40 threats CLOSED. 0 open, 0 blocking. No unregistered attack surface.
`## Threat Flags` in 05-11-SUMMARY declares "None" — no unmapped flags.

## Threat Verification (mitigate — code/doc surface grep-verified)

| Threat ID | Category | Severity | Disposition | Evidence |
|-----------|----------|----------|-------------|----------|
| T-05-02-02 | Denial of Service | unrated→critical(N/A closed) | mitigate | No `purchasePixel` refs in themes/ or Plugin.php/components/ — dead alias fully removed |
| T-05-03-01 | Spoofing | unrated | mitigate | Three-source UAT convergence — 05-03-UAT-GATE-1.md + 05-UAT.md |
| T-05-04-03 | Spoofing | unrated | mitigate | Three-source UAT convergence — 05-04-UAT-GATE-2.md |
| T-05-04-04 | Tampering | unrated | mitigate | EventLog DB tail check documented in 05-04-UAT-GATE-2.md |
| T-05-06-03 | Tampering | unrated | mitigate | event_id cross-channel dedup check — 05-06-UAT-GATE-3.md |
| T-05-03 | Information Disclosure | unrated | mitigate | docs/screenshots/01-settings.png: Pixel ID = `000000000000000` (dummy), CAPI token masked (password dots). Visually verified — no real Pixel ID or token leaks. See Observation O-1. |
| T-05-08-01 | Information Disclosure | unrated | mitigate | launch-01-SECURITY-SWEEP.md Step B executed 2026-07-03 (staging host→`your-staging-host.example`, 0 residual hits); screenshots show no hostname (URL bar cropped out) |
| T-05-09-01 | Information Disclosure | unrated | mitigate | README.md:85,115 — "never place a Pixel ID or access token in `.env`", use backend Settings |
| T-05-09-02 | Tampering | unrated | mitigate | README.md:37,67 — package `logingrupa/oc-metapixel-plugin` + VCS URL pinned; "Do not install a similarly named package" |
| T-05-10-01 | Tampering | unrated | mitigate | docs/CUSTOM-ADAPTERS.md:310 — "MUST NOT mutate `event_id` or `event_time` — Meta dedup contract anchor" |
| T-05-11-01 | Information Disclosure | unrated | mitigate | tests/Feature/Docs/NoV1xReferencesTest.php — D-23 gate bans Phase N / v1.x / legacy markers in Plugin.php, classes/, lang/ |
| T-05-11-02 | Tampering | unrated | mitigate | composer qa full suite GREEN post-strip (587 pest passing, phase context) |
| T-05G-01 | Information Disclosure | medium | mitigate | CartPositionWatcher.php:179-192 `resolvePositionId` uses `CartProcessor`→`CartPosition::getByCart($iCartId)` — offer_id selects only within caller's own session cart |
| T-05G-02 | Cross-Site Scripting | high | mitigate | FbqScriptBuilder.php:13,22,26,45,48 — `JSON_HEX_TAG\|QUOT\|AMP\|APOS` on every interpolated value; no raw string concat of untrusted input |
| T-05G-03 | Spoofing | high | mitigate | CartPositionWatcher.php:153 event_id read from `$obPixelRow->event_id` (EventLog row); `resolveBrowserPixel(int $iOfferId)` takes no browser event_id |
| T-05G-04 | Denial of Service | low | mitigate | ThemeAjaxHandler.php:174→363 `isRateLimited()` per-IP+session 30/60s applied on markAddToCartPixel |
| T-05G-05 | Repudiation | high | mitigate | `resolveBrowserPixel` contains NO `SendCapiEvent::dispatch`; sole emitter is `dispatchAddToCart` (CartPositionWatcher.php:113) on eloquent.created |
| T-05-01 (05-18) | Tampering | medium | mitigate | ThemeAjaxHandler.php retains every guard: `isAllowedEventName`:351, `isRateLimited`:363, `resolveByAlias`:223, subject_id/offer_id positive-int guards:171,234, FbqScriptBuilder JS-escape — behaviour-preserving refactor, suite GREEN |
| T-05-19-01 | Information Disclosure | low | mitigate | README.md:45,74 — `php artisan project:set <license>` placeholder token; no real license key |
| T-05-19-02 | Tampering | low | mitigate | tests/Feature/Docs/ReadmeStructureTest.php — install-fidelity gate fails build on prose regression |
| T-05-20-01 | Repudiation | medium | mitigate | 05-20-SUMMARY.md — launch-01/launch-02 ROADMAP bullets reverted `[x]`→`[ ]`, dropped false `(completed)` suffix |
| T-05-21-01 | Information Disclosure | high | mitigate | launch-01-SECURITY-SWEEP.md Step B (2026-07-03, plan 05-21 REDACT-FIRST) — 33 non-archive `.planning/` files redacted, 0 residual hits pre-push |
| T-05-21-02 | Information Disclosure | high | mitigate | .github/workflows/metapixel-qa.yml:44 `COMPOSER_AUTH: ${{ secrets.OCTOBER_AUTH_JSON }}`; :66-71 fail-fast + JSON validation, value never echoed; :21 `permissions: contents: read` |
| T-05-21-03 | Elevation of Privilege | medium | mitigate | metapixel-qa.yml:21-22 (workflow) + :28-29 (job) `permissions: contents: read` — least privilege, cannot push refs/releases/tags |
| T-05-21-04 | Tampering | medium | mitigate | metapixel-qa.yml present + valid; 05-21-SUMMARY records watch-to-green CI iteration |
| T-05-22-01 | Tampering | low | mitigate | README.md:67 exact package name + VCS pin, "do not install a similarly named package" warning in force |
| T-05-22-02 | Denial of Service | medium | mitigate | README.md:60,75 — `:dev-master -W` pre-release fallback keeps fresh install unblocked until v2.0.0 tag |

## Accepted Risks Log (accept disposition — plan-time rationale still holds)

| Threat ID | Category | Rationale (verified current) |
|-----------|----------|------------------------------|
| T-05-00-01 | Tampering | Wave-0 tests are read-only filesystem assertions; no input surface |
| T-05-02-01 | Repudiation | Strip is mechanical; commit message + 05-02-LEGACY-INVENTORY.md provide audit trail |
| T-05-03-02 | Information Disclosure | Operator instructed to NOT commit UAT-gate screenshots; only 05-08 produces formal screenshots |
| T-05-04-01 | Information Disclosure | PixelHead reads pixel_id from per-site Settings; empty→PluginGuard disabled, no render (cascade-safe) |
| T-05-04-02 | Cross-Site Scripting | pixel_id passes Phase-4 Settings validation; PixelHead output escaped per Phase-3 THEM-05 |
| T-05-06-01 | Tampering | secret_key/event_id validated server-side per Phase-3 THEM-06 (D-09 lock) |
| T-05-06-02 | Information Disclosure | event_id is UUIDv4 random — no PII; Meta dedup anchor only (confirmed in failed-events screenshot) |
| T-05-06-04 | Repudiation | Operator records order ID, secret_key, place/cancel timestamps in UAT log |
| T-05-08-02 | Tampering | Operator restores real Settings values post-capture per Step M; UAT closure verifies |
| T-05-10-02 | Information Disclosure | Doc hook examples use placeholder tokens/channels — no real EAA-prefix token |
| T-05-12-01 | Tampering | composer.json keywords are descriptive nouns; no install-name shadowing |
| T-05-SC (05-18) | Tampering | No package installs in the refactor plan — nothing to verify |
| T-05-22-03 | Tampering | Composer rejects `http://` VCS by default; URL pinned to GitHub HTTPS |

## Unregistered Flags

None. 05-11-SUMMARY `## Threat Flags` = "None". No new attack surface appeared
during implementation without a threat mapping.

## Observations (non-blocking, not threat gaps)

- **O-1 — Test Events Code visible in 01-settings.png.** The settings screenshot
  shows Test Events Code `TEST50287` in cleartext. This deviates from the plan
  05-12 visual-review checklist, which expected only `000000000000000` and a
  redacted token to be visible. It is NOT a T-05-03 gap: the two named leak
  vectors (real Pixel ID, CAPI token) are properly handled — Pixel ID is the
  dummy `000000000000000` and the CAPI token field is masked with password dots.
  The Test Events Code is classified by launch-01-SECURITY-SWEEP.md Step A as a
  throwaway, non-auth identifier (useless without the pixel access token, which
  is not exposed). Recorded for operator awareness; if a cleaner public asset is
  desired, re-capture the settings screenshot with the Test Events Code field
  blanked. Non-blocking.

## Verdict

**SECURED.** 40/40 threats CLOSED (27 mitigate verified in code/docs/CI/sweep,
13 accepted-risk with standing rationale). 0 open, 0 blocking under
`block_on: high`. All 5 high-severity mitigations (T-05G-02, T-05G-03, T-05G-05,
T-05-21-01, T-05-21-02) verified present at the correct boundary.
