---
status: complete
phase: 05-documentation-marketplace-launch
scope: post-cutover acceptance — covers conversion events, FailedEvents UI, multisite, cookie kill switch, TrustedHosts, translations
date: 2026-05-27
env: https://new.nailscosmetics.lv
operator: Rolands Zeltins
related:
  - 05-04-UAT-GATE-2.md
  - 05-UAT.md
gates_plans:
  - 05-06
  - 05-08
  - 05-12
  - 05-13
  - 05-14
---

# Phase 5 — Post-Cutover UAT (operator-confirmed)

Closes acceptance items 1–6 from the /gsd-progress recommended UAT list. Items 7 (debug sessions) and 8 (queue toggle todo) tracked separately.

## Tests

### 1. AddToCart fires browser + server with same event_id (deduplicated)
expected: |
  Add a product to cart. Watch Meta Pixel Helper, Meta Test Events live view, and
  `logingrupa_metapixel_event_log` tail. Browser + Server entries for AddToCart with
  matching event_id, labelled "Deduplicated" in Test Events. EventLog has 1 row
  channel=capi per add-to-cart.
result: pass

### 2. Purchase fires browser + server with same event_id (deduplicated)
expected: |
  Complete a test checkout. Watch the same three sources. Browser + Server entries
  for Purchase with matching event_id, labelled "Deduplicated". EventLog has 1 row
  channel=capi per order paid. Currency + value match the order total.
result: pass

### 3. FailedEvents admin UI — list + Replay
expected: |
  Backend → Logingrupa → Metapixel → Failed Events. Force a failure (invalid token
  or unreachable Meta endpoint). Row appears in list with subject + event_id +
  http_status + last_error. Click Replay. Row clears or re-attempts. Phase 4
  FAIL-01..03 contract holds.
result: pass

### 4. Multisite routing — per-site pixel_id
expected: |
  Settings tab on each October site (e.g. nailscosmetics.lv vs nailscosmetics.no
  if both configured) shows its own pixel_id + capi_access_token via Multisite
  trait. Saving site A does not overwrite site B. Outbound CAPI requests carry
  the per-site pixel_id. Phase 4 MULT-01..03 contract holds.
result: pass

### 5. Cookie kill switch + TrustedHosts allowlist
expected: |
  Settings kill switch off → no `_fbp` / `_fbc` cookies set by middleware on
  any page load. Toggle back on → cookies set. Spoof Host header to a value not
  in trusted_hosts allowlist → cookies skipped (fail-safe). Phase 4 HOST-01..06 +
  COOK-01..03 + CR-02 + CR-03 contracts hold.
result: pass

### 6. Translations en/lv coverage
expected: |
  Admin language switch → en and lv. Settings tab field labels, help text, button
  labels translate. FailedEvents controller list columns translate. No raw
  translation keys visible. Phase 4 LANG-01 contract holds.
result: pass

## Summary

total: 6
passed: 6
issues: 0
pending: 0
skipped: 0
blocked: 0

## Items tracked separately

### 7. Open debug sessions — need fixes (not blocking UAT closure)

Two sessions in `.planning/debug/`:

- `pixelhead-no-base-pageview.md` — likely already resolved by commit `0658788`
  ("feat(pixelhead): restore base-pixel emission lost in Phase 3 re-derive"). Needs
  status flip + close. PageView fires per Gate 2 + this UAT — symptom resolved.
- `settings-save-host-resolver-di.md` — status: diagnosed. Root cause = stale
  OPcache (per 05-UAT.md gap note). Operational fix already applied (FPM reload).
  Needs status flip + close.

Both sessions can close on next pass — no code change required, just status update.

### 8. Queue toggle for CAPI server events — next release

Pending todo: `.planning/todos/pending/2026-05-27-enable-optional-queue-for-capi-server-events.md`.
Deferred to next release (post-v2.0.0). Not gating v2.0.0 marketplace launch.

## Remaining Phase 5 work

UAT acceptance is complete. Remaining Phase 5 plans are artifact-shipping (no further
operator click-through needed except for screenshots):

| Plan | Wave | Owner | Artifact |
|------|------|-------|----------|
| 05-06 | 5 | autonomous | EventPixel wire — covered by Gate 2 + tests 1+2 above (browser + server event pair) |
| 05-08 | 6 | operator | 5 screenshots in `docs/screenshots/` (MKT-01) — operator-shot, only manual remaining item |
| 05-09 | 7 | autonomous | README.md — explicitly skipped by operator (defer to post-v2.0.0 churn) |
| 05-10 | 7 | autonomous | DONE (CUSTOM-ADAPTERS.md shipped) |
| 05-11 | 2 | autonomous | DONE (release-hygiene docblock strip) |
| 05-12 | 8 | autonomous | CHANGELOG.md + plugin.yaml + composer.json version bump |
| 05-13 | 9 | autonomous | git tag v2.0.0 |
| 05-14 | 10 | autonomous | marketplace launch wrap |

_Operator-signed 2026-05-27 — closes /gsd-verify-work for items 1–6._
