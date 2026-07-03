---
phase: 05-documentation-marketplace-launch
plan: 06
plan_id: 05-06
subsystem: cutover-wave
tags: [eventpixel, purchase, dedup-contract, uat-gate-3, three-source-convergence]
requires:
  - phase: 03
    provides: EventPixel component (THEM-06) registered at Plugin.php:111, OrderStatusWatcher CAPI dispatch
  - phase: 05-04
    provides: PixelHead base PageView wire + UAT Gate 2 PASS
provides:
  - EventPixel server-confirmed Purchase pixel on order-complete + order-complete-proforma
  - Operator-confirmed Purchase event_id round-trip dedup verification
  - UAT Gate 3 PASS unblocks plan 05-08 (live smoke + screenshots) per D-03
affects: [05-08]
tech-stack:
  added: []
  patterns:
    - "EventPixel component on conversion pages — server-side EventLog lookup (channel=capi row) gates inline browser fbq emit with matching event_id"
key-files:
  created:
    - .planning/phases/05-documentation-marketplace-launch/05-06-UAT-GATE-3.md
  modified:
    - themes/logingrupa-naisstore/pages/order-complete.htm
    - themes/logingrupa-naisstore/pages/order-complete-proforma.htm
key-decisions:
  - "Task 1 shipped in theme commits 6d2367c (wire) + 866236e (props fix subject_type + event_name)."
  - "EventPixel reads EventLog server-side per D-09 (direct DB read, no service-layer detour)."
  - "UAT Gate 3 PASS 2026-05-27 — operator (Rolands Zeltins) signed three-source convergence on Purchase across real test order; AddToCart also cross-checked via cutover UAT."
patterns-established:
  - "Per-event pixel wiring on conversion pages = EventPixel component with subject_class + subject_slug_field props pinning to the Shopaholic Order"
  - "Dedup contract closure on Purchase = EXACTLY 2 EventLog rows (capi + pixel) with matching UUID v4 event_id"
requirements-completed: [DOCS-01]
duration: ~3 days calendar (operator gating + props fix)
completed: 2026-05-27
---

# Phase 5 Plan 06 — EventPixel Purchase wire + UAT Gate 3

**Order-complete pages now ship server-confirmed Purchase pixel; three-source dedup contract operator-verified.**

## Performance

- **Task 1 shipped:** 2026-05-24 (theme commits `6d2367c`, `866236e`)
- **Task 2 (UAT Gate 3):** 2026-05-27 (operator-signed)
- **Tasks:** 2/2
- **Files modified:** 2 order-complete pages + 1 UAT log

## Accomplishments

- Closed Purchase dedup loop — browser fbq emit + server CAPI dispatch reach Meta with matching UUID v4 event_id.
- `[eventPixel]` declared on order-complete.htm + order-complete-proforma.htm with `subject_class = Lovata\OrdersShopaholic\Models\Order` + `subject_slug_field = secret_key` + per-event metadata pinned via component props.
- Operator-verified three-source convergence on Purchase (`channel=capi` + `channel=pixel` rows in EventLog, both event_id-matched).
- AddToCart cross-check passed during same operator session (CartPositionWatcher dispatch path).
- Unblocked plan 05-08 (live smoke + 5 PNG screenshots).

## Task Commits

1. **Task 1: Wire EventPixel on order-complete pages** — theme `6d2367c` (`feat(05-06): wire eventPixel on order-complete + order-complete-proforma`) + `866236e` (`fix(05-06): eventPixel needs subject_type + event_name props`)
2. **Task 2: Operator UAT Gate 3** — `.planning/phases/05-documentation-marketplace-launch/05-06-UAT-GATE-3.md` (operator-signed 2026-05-27)

Related plugin-side fixes during operator UAT iteration:
- `ee6f608` `fix(capi): populate user_data so Meta accepts events (was 400 subcode 2804050)` — fixed user_data omission revealed by live test order.
- `5700c1f` `fix(watchers): inject request user_data into AddToCart + Purchase CAPI envelopes` — extended user_data fix to watcher dispatch path.
- `ebee0fd` `fix(pixel): emit test_event_code in browser fbq so Test Events pairs Browser+Server` — test_event_code wiring to browser side for Test Events pairing.
- `c79c8c4` `fix(capi): inject Settings.test_event_code into top-level CAPI payload` — test_event_code wiring to CAPI envelope.
- `fc1f1c1` `fix(updates): add v1.0.4 migration for missing FailedEvents subject columns` — FailedEvents schema gap for replay path.
- `241a731` `fix(failedevents): remove broken listRefresh override — _list partial does not exist` — FailedEvents controller list fix.
- `106e671` `fix(pixelhead): action_key per-request unique to bypass race-fence on PageView` — PageView race-fence bypass for per-request emission.
- `0658788` `feat(pixelhead): restore base-pixel emission lost in Phase 3 re-derive` — base pixel restore closing pixelhead-no-base-pageview debug session.

## Files Modified

- `themes/logingrupa-naisstore/pages/order-complete.htm` — `[eventPixel]` INI + `{% component 'eventPixel' %}` render
- `themes/logingrupa-naisstore/pages/order-complete-proforma.htm` — same
- `.planning/phases/05-documentation-marketplace-launch/05-06-UAT-GATE-3.md` — three-source PASS verdict

## UAT Gate 3 verdict (operator-signed)

| Source | Verdict |
|--------|---------|
| Meta Pixel Helper | 1 Purchase with eventID matching server — PASS |
| Meta Test Events live view | Browser + Server "Deduplicated" — PASS |
| `logingrupa_metapixel_event_log` | 2 rows (capi + pixel) per order, matching UUID v4 event_id — PASS |

Per `.planning/phases/05-documentation-marketplace-launch/05-06-UAT-GATE-3.md`.

## Cross-references

- **EventPixel component:** `components/EventPixel.php` (Phase 3 plan 03-08, registered Plugin.php:111).
- **OrderStatusWatcher:** `classes/event/adapter/shopaholic/OrderStatusWatcher.php` — Shopaholic Order status change CAPI dispatch.
- **CartPositionWatcher:** `classes/event/adapter/shopaholic/CartPositionWatcher.php` — CartPosition `eloquent.created` AddToCart dispatch.
- **Dedup contract:** D-04 (server → browser event_id direction, EXACTLY 2 EventLog rows per event).
- **Three-source rule:** D-05.

## Self-Check: PASSED

- [x] 2 order-complete page edits shipped (theme `6d2367c` + props fix `866236e`)
- [x] UAT Gate 3 PASS recorded (05-06-UAT-GATE-3.md)
- [x] Dedup contract verified — 2 EventLog rows per Purchase with matching event_id
- [x] AddToCart cross-check passed via CartPositionWatcher path
- [x] Plan 05-08 unblocked
