---
phase: 05-documentation-marketplace-launch
plan: 04
plan_id: 05-04
subsystem: cutover-wave
tags: [pixelhead, base-pageview, theme-wire, uat-gate-2, three-source-convergence]
requires:
  - phase: 03
    provides: PixelHead component (THEM-07) registered at Plugin.php:112
  - phase: 05-03
    provides: Zero-events baseline UAT Gate 1 PASS — clean slate for re-wire
provides:
  - PixelHead base PageView coverage restored across 4 theme layouts
  - Operator-confirmed three-source PageView dedup contract verification
  - UAT Gate 2 PASS unblocks plan 05-06 (EventPixel per-event wire) per D-03 cutover rule
affects: [05-06, 05-08]
tech-stack:
  added: []
  patterns:
    - "Theme layout INI [pixelHead] declaration + {% component 'pixelHead' %} render inside <head> — single component alias drives both browser fbq init/PageView and server CAPI dispatch with shared event_id"
key-files:
  created:
    - .planning/phases/05-documentation-marketplace-launch/05-04-UAT-GATE-2.md
  modified:
    - themes/logingrupa-naisstore/layouts/main.htm
    - themes/logingrupa-naisstore/layouts/content.htm
    - themes/logingrupa-naisstore/layouts/light.htm
    - themes/logingrupa-naisstore/layouts/catalog_default.htm
key-decisions:
  - "Task 1 shipped in single theme commit 524189f covering all 4 layouts — atomic re-wire."
  - "Component placed before {% partial 'google_analythics' %} inside <head> to mirror legacy facebook_pixel.htm placement (page-load-blocking acceptable; PixelHead is async fbevents.js)."
  - "UAT Gate 2 closure 2026-05-27 — operator (Rolands Zeltins) signed three-source convergence on PageView across all 5 critical page URLs."
patterns-established:
  - "PixelHead component as singleton head-tag base pixel — wires automatically via theme layout, no per-page boilerplate"
  - "Three-source convergence as the standard cutover-wave acceptance gate (Pixel Helper + Test Events Browser+Server dedup + EventLog tail)"
requirements-completed: [DOCS-01]
duration: ~2 weeks calendar (operator gating)
completed: 2026-05-27
---

# Phase 5 Plan 04 — PixelHead base PageView wire

**Theme layouts now ship base PageView via plugin component; UAT Gate 2 operator-signed PASS.**

## Performance

- **Task 1 shipped:** 2026-05-22 (theme commit `524189f`)
- **Task 2 (UAT Gate 2):** 2026-05-27 (operator-signed)
- **Tasks:** 2/2
- **Files modified:** 4 theme layouts + 1 UAT log

## Accomplishments

- Restored base PageView coverage lost in 05-02 legacy strip via plugin component (D-04 lock — no static fbq in theme).
- `[pixelHead]` declared in INI section + `{% component 'pixelHead' %}` rendered inside `<head>` of `main.htm`, `content.htm`, `light.htm`, `catalog_default.htm`.
- Operator-confirmed three-source dedup contract across `/`, `/catalog`, `/product/<slug>`, `/checkout/<slug>`, `/order-complete/<order-slug>`.
- Unblocked plan 05-06 (EventPixel per-event wire) per D-03 cutover rule.

## Task Commits

1. **Task 1: Wire PixelHead into 4 layouts** — theme `524189f` (`feat(05-04): wire PixelHead component in 4 theme layouts (head-tag base pixel)`)
2. **Task 2: Operator UAT Gate 2** — `.planning/phases/05-documentation-marketplace-launch/05-04-UAT-GATE-2.md` (operator-signed 2026-05-27)

## Files Modified

- `themes/logingrupa-naisstore/layouts/main.htm` — `[pixelHead]` INI + `{% component 'pixelHead' %}` in `<head>`
- `themes/logingrupa-naisstore/layouts/content.htm` — same
- `themes/logingrupa-naisstore/layouts/light.htm` — same
- `themes/logingrupa-naisstore/layouts/catalog_default.htm` — same
- `.planning/phases/05-documentation-marketplace-launch/05-04-UAT-GATE-2.md` — three-source PASS verdict

## UAT Gate 2 verdict (operator-signed)

| Source | Verdict |
|--------|---------|
| Meta Pixel Helper | 1 PageView per page-load across 5 URLs — PASS |
| Meta Test Events live view | Browser + Server pair, matching event_id, "Deduplicated" label — PASS |
| `logingrupa_metapixel_event_log` tail | 1 row `channel=capi` per page-load, event_id matches — PASS |

Per `.planning/phases/05-documentation-marketplace-launch/05-04-UAT-GATE-2.md`.

## Cross-references

- **PixelHead component:** `components/PixelHead.php` (Phase 3 plan 03-08, registered Plugin.php:112).
- **Cutover gate rule:** D-03 (operator-confirmed checkpoint before next wave).
- **Three-source rule:** D-05 (independent verification across browser helper + Meta server + plugin DB).

## Self-Check: PASSED

- [x] 4 layout edits shipped (theme commit 524189f)
- [x] UAT Gate 2 PASS recorded (05-04-UAT-GATE-2.md)
- [x] PixelHead component pre-existed and is registered (Plugin.php:112)
- [x] Three-source convergence verified by operator
- [x] Plan 05-06 unblocked
