---
gate: UAT Gate 2
plan: 05-04
phase: 05-documentation-marketplace-launch
status: PASS
verdict: passed
date: 2026-05-27
env: https://new.nailscosmetics.lv
operator: Rolands Zeltins
gates_plan: 05-06
---

# UAT Gate 2 — PageView fires browser + server with same event_id (deduplicated)

Per D-03 cutover contract + D-05 three-source convergence rule. Plan 05-06 (EventPixel wire) was blocked on this gate.

## Three-source verdict — PageView

| Source | Expected | Observed | Verdict |
|--------|----------|----------|---------|
| Meta Pixel Helper (Chrome) | 1 PageView per page load | 1 PageView | PASS |
| Meta Test Events live view | Browser + Server pair with matching `event_id`, labelled "Deduplicated" | Browser + Server matched, dedup label visible | PASS |
| `logingrupa_metapixel_event_log` tail | 1 row `channel=capi` per page load | 1 row per page load | PASS |

`event_id` identical across all three sources (server-direction contract — server-generated UUIDv4, browser fbq reads server-emitted value).

## Pages covered

Same 5 page URLs verified zero in Gate 1, now verified `1 PageView browser + 1 PageView server, deduped`:

- /
- /catalog
- /product/<slug>
- /checkout/<slug>
- /order-complete/<order-slug>

## Conclusion

Gate 2 PASS. Plan 05-06 (EventPixel wire for conversion events: AddToCart, Purchase) is unblocked and may execute.

_Operator-signed 2026-05-27._
