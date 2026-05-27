---
gate: UAT Gate 3
plan: 05-06
phase: 05-documentation-marketplace-launch
status: PASS
verdict: passed
date: 2026-05-27
env: https://new.nailscosmetics.lv
operator: Rolands Zeltins
gates_plan: 05-08
---

# UAT Gate 3 — Purchase event_id round-trips browser + server (dedup contract)

Per D-03 cutover contract + D-05 three-source convergence rule. Plan 05-08 (live smoke + screenshots) was blocked on this gate.

## Operator action

Real test order placed on `new.nailscosmetics.lv` with `test_event_code=TEST58466` set in Settings. Browser fbq('track','Purchase',...,{eventID:<uuid>}) inline emit from EventPixel + server-side CAPI dispatch via OrderStatusWatcher → SendCapiEvent.

## Three-source verdict — Purchase

| Source | Expected | Observed | Verdict |
|--------|----------|----------|---------|
| Meta Pixel Helper | 1 Purchase with eventID matching server | matched | PASS |
| Meta Test Events live view | Browser + Server pair for Purchase, "Deduplicated" label | matched | PASS |
| `logingrupa_metapixel_event_log` | 2 rows for order: `channel='capi'` + `channel='pixel'`, SAME event_id (UUID v4) | 2 rows, event_id matches | PASS |

`event_id` identical across browser fbq emit + Meta server-side row + plugin DB rows — dedup contract anchor holds.

## AddToCart cross-check (bonus, post-cutover UAT item 1)

AddToCart browser + server dedup also verified during cutover UAT 2026-05-27. CartPositionWatcher dispatches CAPI on `eloquent.created` of CartPosition with matching event_id from the EventLog row. EventLog returns 2 rows per add-to-cart: `channel='capi'` + `channel='pixel'`, same event_id.

## Conclusion

Gate 3 PASS. Plan 05-08 (live smoke + 5 PNG screenshots) is unblocked.

_Operator-signed 2026-05-27._
