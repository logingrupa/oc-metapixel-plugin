---
status: complete
phase: 05-documentation-marketplace-launch
scope: post-cutover acceptance — covers conversion events, FailedEvents UI, multisite, cookie kill switch, TrustedHosts, translations
date: 2026-05-27
env: https://your-staging-host.example
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

total: 8
passed: 8
issues: 0
pending: 0
skipped: 0
blocked: 0

## Gaps

(none open — the test-9 gap below was resolved 2026-07-03 by plans 05-15/05-16/05-17 + live-UAT hotfixes; kept for history)

- truth: "AddToCart browser pixel fires with server event_id + full custom_data (event_id dedup, not fbp fallback)"
  status: resolved
  reason: "User reported 2026-07-02: browser AddToCart had no event_id, only value/currency/cs_est; server capi c6c2517d-1aa3-4d3b-be9e-8b368c22da83 carried full custom_data. Diagnosis: plugin emits NO browser AddToCart by design (D-07 deferred to post-launch); EventLog has zero channel=pixel AddToCart rows ever; observed browser event is Meta-generated (cs_est = client-side estimate) even though auto-events toggle is OFF; dedup currently relies on fragile fbp fallback. Operator pulled D-07 into scope: build browser AddToCart wire with server event_id for true event_id dedup (better ad pricing)."
  severity: major
  test: 9
  artifacts:
    - classes/event/adapter/shopaholic/CartPositionWatcher.php
    - classes/adapter/theme/ThemeAjaxHandler.php
    - classes/adapter/shopaholic/ShopaholicCartPositionAdapter.php
    - components/ProductPixel.php
    - components/EventPixel.php
    - themes/logingrupa-naisstore/partials/shared/controls/add-to-cart-control.js
  missing:
    - "Browser fbq AddToCart emitter reusing the CartPositionWatcher-generated event_id (offer-switch $.request + executable-script-injection pattern per ProductPixel::buildOfferSwitchJs)"
    - "channel='pixel' EventLog twin row for cart_position AddToCart (race-fence pattern per EventPixel::onMarkFired)"
    - "Guard against double CAPI dispatch — CartPositionWatcher already fires capi on eloquent.created; the browser wire must NOT trigger a second SendCapiEvent"
    - "Post-fix re-test: confirm stray no-event_id browser AddToCart no longer appears; if it does, identify its emitter (Meta auto-events reported OFF)"

## Items tracked separately

### 9. AddToCart browser pixel carries server event_id + full custom_data (D-07 wire)
expected: |
  Adding a product to cart fires a browser fbq AddToCart with eventID equal to the
  server CAPI event_id and full custom_data (content_ids, contents, num_items,
  value, currency). Test Events shows Browser + Server pair deduplicated BY EVENT ID,
  not by fbp fallback.
result: pass
reported: |
  2026-07-03 operator approved after live re-test. Browser + Server AddToCart pair
  deduplicated BY EVENT ID (observed d87dc505-2e56-4bd0-a6a4-aff7c493a9a9 on both
  channels) with full custom_data (content_ids, contents, num_items, value,
  currency) byte-identical both sides; exactly one channel=capi + one channel=pixel
  EventLog row per cart_position, idempotent on re-add. Purchase regression pass
  (680225a6 pair, full hashed match keys server-side). PDP funnel re-verified:
  one PageView pair + one ViewContent pair per render, no unpaired events;
  offer-switch fires one ViewContent pair per variant change with offer-level
  content_name/value. Stray no-event_id browser AddToCart: emitter identified as
  Meta client-side estimation inside fbevents.js (es=automatic,
  est_source=602085151677479) — not plugin code, not autoConfig (disabled via
  fbq('set','autoConfig',false) in 36b7244, which killed SubscribedButtonClick);
  no client-side kill switch exists, Meta support ticket if operator wants it gone.
  Live UAT surfaced + fixed en route: $.request transport shape for
  onMarkAddToCart (867bb3c) and onFireEvent (e72ed42); AJAX-postback ViewContent/
  PageView leaks — view predicate RequestKind::isPageRender (16672d8, eda6d9c,
  f2f8a20); duplicate product.open emission guard (1891a5b); empty-site_list
  loadSubject rejection (fa4679a); offer-switch content_name/value from switched
  offer (27a460c); theme: native change re-dispatch on swatch radios (theme repo
  7b35352), browser AddToCart follow-up wire rebuild (ec0bd99).
severity: major (resolved)

### 7. Open debug sessions — need fixes (not blocking UAT closure)
result: pass
reason: |
  Both sessions in `.planning/debug/` carry status: resolved (2026-05-27):
  - `pixelhead-no-base-pageview.md` — resolved by commit 0658788, verified by Gate 2 PASS.
  - `settings-save-host-resolver-di.md` — stale OPcache root cause, FPM reload applied,
    verified by cutover items 4+5 PASS.
  Status flips confirmed on disk 2026-07-02 — no open debug sessions remain.

**Item 8 — Queue toggle for CAPI server events (not a UAT test; backlog todo).**
Pending todo `.planning/todos/pending/2026-05-27-enable-optional-queue-for-capi-server-events.md`
deferred to next release (post-v2.0.0) by operator decision. Not gating v2.0.0 marketplace launch.

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
