# Plan 05-16 Summary — D-07 browser AddToCart wire: deploy + operator UAT re-test

**Status:** Complete — operator approved 2026-07-03 ("approved").
**UAT:** 05-UAT-CUTOVER.md test 9 flipped `issue` → `pass`; Summary 8/8 passed, 0 issues.

## What was done

- **Task 1 (deploy):** theme bundle rebuilt (`pnpm run prod`), compiled `assets/js/common.js` carries the `Metapixel::onMarkAddToCart` follow-up + `createContextualFragment` injection. Theme repo commit `ec0bd99`. OPcache flushes performed by operator (`sudo systemctl reload php8.4-fpm`) — executor is sudo-blocked on this host.
- **Checkpoint (human-verify):** extended live-UAT session 2026-07-02→03. Operator confirmed in Meta Test Events:
  - AddToCart Browser + Server pair **deduplicated by event ID** (`d87dc505…` both channels), full custom_data byte-identical.
  - Exactly one `channel='capi'` + one `channel='pixel'` EventLog row per cart_position; idempotent on re-add (late pixel-twin completion reuses stored capi event_id).
  - Purchase regression pass (`680225a6…` pair; server side carries hashed Email/name/phone/external_id — browser-side PII absent by design, Meta-recommended CAPI architecture).
  - PDP funnel: one PageView pair + one ViewContent pair per render, nothing unpaired.
  - Offer switch: one ViewContent pair per variant change, offer-level `content_name`/`value`.
- **Task 2 (record):** UAT test 9 pass recorded with full findings.

## Defects found by live UAT and fixed en route (plugin repo)

| Commit | Fix |
|---|---|
| `36b7244` | `fbq('set','autoConfig',false)` — kills inferredEvents (SubscribedButtonClick + scraped-jsonLD AddToCart) |
| `867bb3c` | onMarkAddToCart accepts October `$.request` top-level transport shape (was 422 'invalid offer_id' on every call) |
| 05-17 plan | ViewContent per-view fence + PayloadBuilder zero-junk (separate gap plan, own SUMMARY) |
| `1891a5b` | request-scoped guard: duplicate `shopaholic.product.open` emissions (Lovata ProductPage + CustomProductPage both fire) |
| `16672d8` | ViewContent skips AJAX postbacks (Larajax carries no October header — debug-log capture pinned it) |
| `eda6d9c` | PageView (PixelHead) skips AJAX postbacks — same leak class |
| `f2f8a20` | refactor: `RequestKind::isPageRender()` — single owner of the view predicate ("a view = plain GET page render"), unit-tested |
| `e72ed42` | onFireEvent reads both transport shapes via shared `readEventData()` (offer-switch `$.request` wire was dead: 422 'event_name not allowed') |
| `fa4679a` | `loadSubject`: empty `site_list` = unrestricted, not zero-site membership (was 404 'subject not found' on installs with unused site pivot) |
| `27a460c` | offer-switch ViewContent carries the switched offer's name + price (was product-level/first-offer) |

Theme repo: `471b980` (05-15 wire), `ec0bd99` (bundle rebuild), `7b35352` (re-dispatch native `change` after programmatic offer-radio check — swatch flips were invisible to all delegated listeners).

## Known remaining (documented, non-blocking)

- **cs_est ghost AddToCart** — Meta client-side estimation inside official fbevents.js (`es=automatic`, `est_source=602085151677479`). Not plugin code; no public kill switch; flagged `cs_est=true` so Meta treats it as estimated. Removal = Meta support ticket. Meta typically auto-suppresses once real events flow.
- **Browser/server ViewContent param asymmetry** — browser carries `content_name`, server carries `num_items`/`contents`. Dedup unaffected (event_id keyed). Polish candidate: align param sets.
- **Position-recycle no-refire** — re-adding a previously-carted offer to the same persistent cart updates the recycled position row (no `eloquent.created`) → no new AddToCart CAPI; one-shot fence per position id. Rare path (logged-in persistent carts), acceptable undercount; fast-follow candidate.
- **Upstream Lovata:** `shopaholic.product.open` fires on AJAX postbacks → inflates Popularity view counts on all Shopaholic stores. Issue-report candidate. Optional own-fork fix: same `RequestKind`-style guard before `Event::fire` in storeextender `CustomProductPage`.
- Pre-existing pint failure in `tests/Feature/Adapter/Theme/ThemeMarkupTagsTwigTest.php` at HEAD (owned by earlier plan, untouched).

## Self-Check: PASSED

- UAT test 9 pass recorded by operator verdict (not self-approved).
- All fix commits carry tests; suites green at each commit (pint + phpstan L10 + pest).
- Live verification: GET → exactly 1 ViewContent + 1 PageView row; AJAX postback → 0 rows; dedup pairs observed in Meta Test Events.
