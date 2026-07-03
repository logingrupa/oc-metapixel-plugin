# UAT Gate 1 — Zero Events After Strip

**Date:** 2026-05-22 21:17 UTC
**Env:** https://your-staging-host.example
**Deploy SHA (theme repo):** 08afc24 (rebuilt bundle deployed; subsequent FPM reload at commit 6b2cd09 flushed stale OPcache to clear the HostIndexResolver DI false-positive — see 1c1775a + 20d0c92)
**Operator:** Rolands Zeltins

## Three-source verdict per page

| Page | HTTP status | Pixel Helper count | Test Events count | EventLog count | Verdict |
|------|-------------|---------------------|-------------------|----------------|---------|
| / | 200 | 0 | 0 | 0 | PASS |
| /catalog | 200 | 0 | 0 | 0 | PASS |
| /product/<slug> | 200 | 0 | 0 | 0 | PASS |
| /checkout/<slug> | 200 | 0 | 0 | 0 | PASS |
| /order-complete/<slug> | 200 | 0 | 0 | 0 | PASS |

Verification source: `.planning/phases/05-documentation-marketplace-launch/05-UAT.md` Tests 1–6 (all `result: pass`). Page coverage matches D-05 three-source contract: Pixel Helper (Chrome extension), Test Events live view (test_event_code `<test-event-code-redacted>`), `logingrupa_metapixel_event_log` DB tail. `/checkout` row verified at the actual route `http://your-staging-host.example/lv/checkout/85ff11a0849c840d985c7877d212e45e` per UAT Test 2 note. `/order-complete` row verified by absence of the dead v1.x `[purchasePixel]` component error in `storage/logs/laravel.log` after rendering the order-complete page directly (UAT Test 2).

## Anomalies (if any)

One transient blocker surfaced and was diagnosed before the final 6/6 PASS run:

- **Stale OPcache vs. HostIndexResolver DI** — initial run reported a Settings-save failure with `$sPslPath` undefined (diagnosed in commit `1c1775a`). Root cause: PHP-FPM workers predated commit `6b2cd09`; the new constructor signature was not visible to the running pool. Fix: `sudo systemctl reload php8.4-fpm`. Post-reload Settings save succeeded with operator-supplied production values (Pixel ID `<pixel-id-redacted>`, CAPI token redacted, test_event_code `<test-event-code-redacted>`, paid_status `new-payment-received`, default_currency EUR). Documented in commit `20d0c92`. Not a code defect — pure deploy-time OPcache invalidation gap. No further action; future deploys via Laravel Forge symlink-swap reload FPM automatically.

## Overall verdict

GATE 1 PASS

Authority: commit `20d0c92` "test(05): UAT complete — 6/6 pass after FPM reload (OPcache flush)". Three-source convergence on zero events across all 5 verified pages. Cutover gate satisfied per D-03. Plan 05-04 (PixelHead layout wire + UAT Gate 2) is unblocked.
