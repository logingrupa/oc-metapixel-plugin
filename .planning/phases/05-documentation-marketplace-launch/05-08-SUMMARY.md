---
phase: 5
plan: 08
plan_id: 05-08
status: complete
completed: 2026-07-03
requirements: [DOCS-01, MKT-03]
---

# 05-08 SUMMARY — Live smoke + 5 marketplace screenshots

**One-liner:** Full live smoke on your-staging-host.example executed agent-driven (operator-delegated): Purchase/ViewContent/PageView event_id round-trip confirmed browser↔CAPI, FailedEvent→Replay→CheckDedup flow exercised, 5 MKT-03 screenshots captured with dummy-values-only, smoke log authored for the 05-09 README walkthrough.

## Smoke window

2026-07-03 09:27–10:20 UTC. Mechanism: 5 sequential subagents driving Playwright headless Chromium + `php artisan tinker`; Backend UI PRIMARY path (W-15) for every Settings transition; SQL fallback never used.

## Delivered artifacts

- `.planning/phases/05-documentation-marketplace-launch/05-SMOKE-LOG.md` — full audit trail (D-08), ends with "READY FOR README (plan 05-09)"
- `docs/screenshots/01-settings.png` (124,587 B) — Settings form, dummy row (D-18)
- `docs/screenshots/02-failed-events-list.png` (202,002 B) — FailedEvents list, 6 dead-letter rows
- `docs/screenshots/03-replay-flow.png` (198,070 B) — Replay success flash, attempts=2, errors cleared
- `docs/screenshots/04-check-dedup.png` (199,181 B) — CheckDedup fail-safe (Graph `(#100) Missing Permission`; see limitations)
- `docs/screenshots/05-twig-api.png` (135,367 B) — Twig `pushEvent` snippet + live fbq ViewContent emission

## Key evidence

- Purchase event_id `8b8b2d97-5f55-4fbf-85ba-6ff69853ce1a` identical across browser fbq eid, `channel=capi` row, `channel=pixel` row — dedup contract confirmed end-to-end on a real order (29813 / 260703-0001, 4.20 EUR, `["SKU-181-363"]`).
- ViewContent + PageView browser↔server event_id MATCH; exactly 1 PageView `/tr` per page load.
- EventLog window aggregate: 30 rows, 4 event classes, both channels — exceeds the ≥6-row (3×2) must-have.
- Forced dead-letter (bad token → Graph 400) → Replay succeeded (attempts 1→2, errors cleared, row kept as audit record per `FailedEvents::replayOne`).
- Screenshot visual review (orchestrator image read): zero real Pixel ID digits, zero real EAA tokens (T-05-03 mitigated). Settings restored to real values and verified (pixel/token/test_event_code MATCH); temp backend user deleted, secrets purged.

## Deviations

1. Smoke executed by agents instead of the human operator (operator explicitly delegated 2026-07-03). Checkpoint evidence returned as structured per-step reports; all human-verify substance preserved in the smoke log.
2. Plan Steps F+G consolidated to one Settings save (identical end state).
3. Step K limitation: dedup %/EMQ fetch blocked by Meta token scope (`(#100) Missing Permission` — needs `ads_read` on the ad account). Plugin fail-safe behaved correctly; `04-check-dedup.png` shows the fail-safe state. Operator follow-up: grant permission, optionally re-capture happy path.

## Findings for downstream plans (05-09 README)

- Purchase fires on order-status transition to `paid_status_code` (`new-payment-received`), not at order creation — walkthrough must include the status flip.
- Theme wiring is component-based (`[pixelHead]` in 4 layouts, `[productPixel]` on product page); generic `this.metapixel.pushEvent` Twig API mounted via `cms.page.beforeRenderPage` — README documents both.
- Meta fbevents.js suppresses browser sends under HeadlessChrome UA — note for anyone automating pixel verification.
- Cosmetic backlog: FailedEvents toolbar lacks `data-request-flash`; AJAX partial targets missing `#failedEventList` container (list needs manual reload).

## Self-Check: PASSED

- 5 PNGs exist at plugin-relative `docs/screenshots/0[1-5]-*.png` (AssetsExistTest path contract; pest vendor absent on server — CI runs the suite)
- 05-SMOKE-LOG.md contains timestamps, EventLog counts, event_ids, pass/fail per step, Backend-UI-vs-SQL choice, READY FOR README handoff
- ≥6 EventLog rows / 3 event classes / 2 channels in window
- Dummy-values-only screenshots (visual review PASS)
- Real Settings restored + verified
