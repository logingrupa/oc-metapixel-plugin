# 05-SMOKE-LOG — Live smoke on your-staging-host.example

---
date: 2026-07-03
env: your-staging-host.example (Forge staging, OctoberCMS v4 / Laravel 12, QUEUE_CONNECTION=sync)
operator: Claude Code agent-driven smoke (5 sequential subagents driving Playwright headless Chromium 1440x900 + `php artisan tinker`), operator-delegated 2026-07-03
settings_mechanism: OctoberCMS Backend UI (PRIMARY path per W-15) for every dummy/real transition — SQL fallback never needed
test_event_code: <test-event-code-redacted> (active for the whole window)
smoke_window_utc: 2026-07-03 09:27 – 10:06
status: PASS (Step K pass-with-limitation — see below)
---

## Step sequence (validated — README walkthrough source, D-08)

| Step | Action | Timestamp (UTC) | Result |
|------|--------|-----------------|--------|
| PRE | Backend → Settings → Marketing → Meta Pixel + CAPI (`/back/system/settings/update/logingrupa/metapixel/settings`): Pixel ID → `000000000000000`, CAPI Access Token → `REDACTED_FOR_DEMO_DO_NOT_USE`, Save (flash "Meta Pixel + CAPI settings updated"), reload → persistence confirmed; DB readback confirmed | 09:27:16–09:27:22 | PASS |
| A | Screenshot `01-settings.png` — settings form with dummy pixel, masked token, Test Events Code, Paid status code, Default currency | 09:27:22 | PASS |
| B-restore | Restore real values via same Backend UI form; tinker compare vs backup: pixel MATCH, token MATCH (len 200) | 09:30:00 | PASS |
| B | Guest checkout: product SKU-181-363 (4.20 EUR) → cart → shipping `localpickup` → payment "Ar bankas pārskaitījumu" (bank transfer, no gateway) → order-complete `/lv/checkout/{secret_key}` | 09:36:43 | PASS |
| B-status | Purchase fires on status transition to `new-payment-received` (paid_status_code), NOT at creation. Backend order update page → status id 5 → Save (flash "Update order was successful") → CAPI Purchase dispatched inline (sync queue) | 09:42:01 | PASS |
| B-pixel | Revisit order-complete page → EventPixel renders browser fbq Purchase with the SERVER event_id; `onMarkFired` writes the `channel=pixel` twin row | 09:42:40 | PASS |
| C | PDP `/lv/p/dezinficesanas-lidzeklis-soft-silk-forasept-strong` → ViewContent (browser fbq + capi row, same event_id) | 09:40:56 | PASS |
| D | Load `/lv` → exactly **1** `facebook.com/tr?ev=PageView` request per page load | 09:40:18 | PASS |
| E | Screenshot `05-twig-api.png` — rendered-snippet variant (view-source variant rejected: real pixel id visible in `fbq('init')` block on same screen region) | 09:47:56 | PASS |
| F+G | Dummy pixel + bad token `EAA-DUMMY-FAIL` in ONE Backend save (plan assumed two saves — consolidated); guest order 260703-0002 (4.90 EUR) → status flip → CAPI Purchase fails Graph 400 "Invalid OAuth access token" → FailedEvent row id 20 | 09:50:40–09:52:16 | PASS |
| H | Screenshot `02-failed-events-list.png` — `/back/logingrupa/metapixel/failedevents`, 6 rows, columns ID / Event ID / Event name / Adapter / HTTP / Attempts / Graph error / Dedup % / EMQ / Checked / Failed at | 09:53:40 | PASS |
| I | Restore real values via Backend UI; tinker MATCH | 09:57:03 | PASS |
| J | Replay button on row 20 → `replayOne` re-dispatch OK → attempts 1→2, http_status + graph_error cleared (row kept as audit record, not deleted). Screenshot `03-replay-flow.png` with green "Replay succeeded — event_id 7b8e82c4-…" flash | 10:00:11–10:03:23 | PASS |
| K | Check dedup button → `MetaClient::fetchTestEventsStatus` GET `/v23.0/{pixel}/?fields=name,event_match_quality,deduplication_rate` → Graph 400 `(#100) Missing Permission` — token lacks `ads_read`/`ads_management` on the ad account. Plugin fail-safe correct: red flash, dedup_pct/EMQ/checked_at untouched. Screenshot `04-check-dedup.png` captures the fail-safe state | 10:05:20 | PASS-WITH-LIMITATION |
| cleanup | Replayed remaining dead-letter rows 15–18 (all succeeded, attempts=2, errors cleared); 6 audit rows remain by design (Delete is a deliberate operator action) | 10:06:09 | PASS |
| L | Visual review all 5 PNGs (orchestrator, image read): zero real pixel digits (`2291…` prefix absent), zero real `EAA…` tokens, all legible | 10:15 | PASS |
| M | Real Settings verified restored: pixel MATCH / token MATCH / test_event_code MATCH. Temp backend user `smokebot` deleted; secrets purged from scratchpad | 10:20 | PASS |

## EventLog row counts (smoke window, `logingrupa_metapixel_event_log`)

`SELECT count(*), event_name, channel FROM logingrupa_metapixel_event_log WHERE created_at >= '2026-07-03 09:30:10' GROUP BY event_name, channel`

| count | event_name | channel |
|-------|------------|---------|
| 5 | ViewContent | capi |
| 15 | PageView | capi |
| 4 | AddToCart | capi |
| 4 | AddToCart | pixel |
| 1 | Purchase | capi |
| 1 | Purchase | pixel |

≥6 rows across 3+ event classes × 2 channels — D-07 must-have satisfied. (Extra rows: 3 aborted scripted checkout attempts before order 260703-0001; cart positions 50–52 abandoned.)

## Event ID samples (browser fbq eventID === server EventLog event_id — dedup contract)

| Event | event_id | Browser↔Server |
|-------|----------|----------------|
| Purchase (order 29813 / 260703-0001, 4.20 EUR, content_ids `["SKU-181-363"]`) | `8b8b2d97-5f55-4fbf-85ba-6ff69853ce1a` | MATCH (capi + pixel rows) |
| ViewContent (content_ids `["SKU-592-6001"]`, 4.90 EUR) | `954db3b4-ba82-4f67-8f2a-73b6debf118c` | MATCH (capi row; browser fires with server eid) |
| PageView (`/lv`) | `fd87e338-2a53-47ca-badb-3006a1c1b70b` | MATCH (capi row) |
| Purchase forced-fail (order 29814 / 260703-0002) → FailedEvent id 20 | `3b80d5e3-7e41-4e9e-9c15-bc4af7512a1f` | dead-lettered then replayed OK |

content_ids format `SKU-{product_id}[-{offer_id}]` confirmed (matches Facebook Catalog feed exporter contract).

## Cookies (redacted first6…last4)

- `_fbp`: fresh per Playwright context — B `fb.2.1…ef12`, C `fb.2.1…97f4`, D `fb.2.1…b304`, purchase-revisit `fb.2.1…586f`
- `_fbc`: absent — expected (no `fbclid` present on any visit)

## Screenshots (MKT-03, all dummy-values-only, leak-checked)

| # | File | Bytes | Subject |
|---|------|-------|---------|
| 1 | `docs/screenshots/01-settings.png` | 124,587 | Settings form, dummy pixel + masked token |
| 2 | `docs/screenshots/02-failed-events-list.png` | 202,002 | FailedEvents list, 6 dead-letter rows, Graph 400 snippets |
| 3 | `docs/screenshots/03-replay-flow.png` | 198,070 | Replay success flash + rows attempts=2, errors cleared |
| 4 | `docs/screenshots/04-check-dedup.png` | 199,181 | CheckDedup fail-safe (Graph `(#100) Missing Permission`) — re-capture after `ads_read` grant if the happy path is wanted |
| 5 | `docs/screenshots/05-twig-api.png` | 135,367 | Twig `pushEvent` snippet + live pixel-id-free fbq ViewContent emission |

## Findings & deviations

1. **Purchase timing (by design):** `OrderStatusWatcher` dispatches Purchase on transition to `paid_status_code` (`new-payment-received`), not at order creation. README walkthrough must include the status-flip step.
2. **Headless UA suppression (operational):** Meta `fbevents.js` silently drops all `/tr` browser sends under the default `HeadlessChrome` user agent (script loads, queue drains, nothing sent). Real browsers unaffected. Any automated pixel verification must spoof a plain Chrome UA + mask `navigator.webdriver`.
3. **Theme wiring (docs-relevant):** the theme does NOT call `pushEvent` literally — it uses `[pixelHead]` (4 layouts) + `[productPixel]` (pages/product.htm). The generic Twig API (`this.metapixel.pushEvent`) is mounted in `Plugin.php` via `cms.page.beforeRenderPage` → `ThemeEventCollector` and covered by `ThemeMarkupTagsTwigTest`. README must show both.
4. **CheckDedup permission (operator follow-up):** grant the CAPI system-user token `ads_read` on the pixel's ad account to enable dedup %/EMQ fetch; then optionally re-capture `04-check-dedup.png` happy path.
5. **Cosmetic UI quirks (backlog candidates, server behavior correct):** (a) FailedEvents toolbar buttons lack `data-request-flash`, so success/error flashes never display without it; (b) AJAX handlers return partial keyed `#failedEventList` but `index.htm` has no such container — list refreshes only on reload.
6. **F+G consolidation:** dummy pixel + bad token set in one save instead of the plan's two — identical end state, fewer transitions.
7. **Staging side-effects:** test orders 29813 (status 5) + 29814 remain in staging DB; 6 replayed FailedEvent audit rows remain; 2 unrelated SMTP auth errors in `system.log` (staging mailer misconfig, `SafeMailer` order-confirmation mail) — pre-existing, not metapixel.
8. **Log file:** runtime log is `storage/logs/system.log` (October), not `laravel.log`. Metapixel/CAPI error lines in window: 0 (besides the two intentionally forced Graph 400s).

READY FOR README (plan 05-09)
