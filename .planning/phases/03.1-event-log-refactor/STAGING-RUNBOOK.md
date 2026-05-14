---
phase: 03.1-event-log-refactor
plugin_version: 1.1.0
audience: operator (deployer of nailscosmetics.no / .lv / .lt)
purpose: Close BRIEF acceptance criteria 1, 4, and 5 — runtime-verification gap deferred in VERIFICATION.md
requires: merged master branch + Laravel Forge access + Meta Events Manager Test Events tab
estimated_duration: 30 min (sequential 4 scenarios + composer qa + SQL check)
---

# Phase 3.1 Staging Runbook — Event-Log Refactor v1.1.0

This runbook is the operator playbook for the LAST 10 % of Phase 3.1
verification that genuinely cannot be automated in CI. The
`PurchaseEndToEndIntegrationTest.php` integration test (Wave-5 Task 1)
codifies the 4 BRIEF acceptance scenarios at the contract level inside
SQLite-in-memory + MockHandler so every push/PR proves them. The
remaining proofs require live network paths (real PayPal IPN, real
Pixel browser script handshake, Meta Events Manager Test Events
dashboard) — those live here as numbered procedures.

Run sequentially against the lowest-traffic site first (recommend
`.lt`, then `.lv`, finally `.no`). On each site, record pass/fail in
Step 8's checkpoint template and append to `STATE.md`.

---

## Preconditions

- Branch `master` merged with all Phase 3.1 commits (03.1-01..06). Confirm via
  `git log --oneline | head -10` on the staging server — most recent commits
  should reference `03.1-06`.
- Laravel Forge SSH access to the staging server. Operator can `sudo
  systemctl reload php8.4-fpm` to flush OPcache after deploy.
- Meta Business Suite → Events Manager → Test Events tab open in a separate
  browser window with `test_event_code` configured against the staging
  Pixel id (NOT the production pixel — use the dedicated staging Pixel).
- Plugin Settings → `pixel_id` set to the staging Pixel id, `capi_access_token`
  to the staging CAPI token, `paid_status_code` to `new-payment-received`,
  `refire_purchase_on_status_flip` to OFF.

---

## Step 1 — Deploy v1.1.0

Numbered shell commands executed inside the Forge SSH session:

```bash
# SSH into staging.
ssh forge@staging.nailscosmetics.lv

# Pull latest master.
cd /home/forge/staging.nailscosmetics.lv
git fetch origin master
git checkout master
git pull origin master

# Vendor + migrations.
composer install --no-dev --optimize-autoloader --no-interaction
php artisan october:up

# Flush OPcache so the new code is actually live.
sudo systemctl reload php8.4-fpm

# Clear October caches.
php artisan cache:clear
php artisan october:util clear cache
```

Expected output: `october:up` reports
`Logingrupa.Metapixelshopaholic: Database is up to date.` AFTER the
migration list shows `drop_meta_purchase_columns_from_orders_table`
+ `create_metapixel_event_log_table` ran exactly once.

---

## Step 2 — Confirm system_plugin_versions row reads v1.1.0

(BRIEF acceptance criterion 5.)

SQL check (run via `php artisan tinker` OR the Forge MySQL console):

```sql
SELECT version FROM system_plugin_versions
WHERE code = 'Logingrupa.Metapixelshopaholic';
```

Expected: single row, `version = '1.1.0'`. If the row reads `1.0.3` or
earlier → `php artisan october:up` did not run OR the migration list
was not picked up. Re-check `updates/version.yaml` matches
`git log -1 --name-only` for the deployed HEAD.

Equivalent `php artisan tinker` snippet:

```bash
php artisan tinker
>>> use System\Models\PluginVersion;
>>> PluginVersion::where('code', 'Logingrupa.Metapixelshopaholic')->first()->version;
"1.1.0"
```

---

## Step 3 — Run composer qa

(BRIEF acceptance criterion 1.)

```bash
cd /home/forge/staging.nailscosmetics.lv/plugins/logingrupa/metapixelshopaholic
composer install --dev --no-interaction
composer qa
```

Expected: `pint-test`, `analyse`, `phpmd`, `pest` all exit 0. Coverage
report shows ≥ 90 % plugin-wide (Phase 3 baseline 89.6 %; Phase 3.1
Wave-5 `PurchaseEndToEndIntegrationTest` lifts coverage above the
threshold).

Note: CI workflow `.github/workflows/metapixel-qa.yml` already runs
this on every push and PR touching the plugin tree. The staging-side
check is the operator's belt-and-suspenders confirmation before flipping
production traffic.

---

## Step 4 — Scenario 1: PayPal end-to-end CAPI + Pixel pair

(BRIEF acceptance criterion 2 + 3, lines 283-285.)

Procedure:

1. Create a test order at `/lv/cart` via the live UI. Choose PayPal as the
   payment method.
2. Complete PayPal sandbox checkout (or live with a small-value real
   order — operator's choice).
3. PayPal IPN flips the order to `new-payment-received`. Watch the Order
   detail in Backend Admin → Orders → confirm `status.code` reads
   `new-payment-received`.
4. Open Meta Events Manager → Test Events. Within ≤ 60 seconds, expect
   exactly TWO event entries for the SAME `event_id`: one labelled
   `Browser (Pixel)`, one labelled `Server (CAPI)`. Meta UI shows them
   as deduplicated (the `Deduplicated` badge is visible).
5. Customer-side: navigate to `/lv/checkout/{secret_key}` for the order.
   Open browser DevTools → Network → filter by `facebook.com`. Confirm
   `fbevents.js` fires `track Purchase` exactly ONCE per page load.
   Refresh the page. Confirm `track Purchase` does NOT fire on the refresh
   (server-side `event_log` row with `channel='pixel'` blocks `onRun`).

Expected dashboard metric: dedup ≥ 80 % on Purchase, EMQ ≥ 8.

Pass/fail recorded in the operator log + STATE.md (Step 8 template).

---

## Step 5 — Scenario 2: Bank-transfer admin flip

(BRIEF acceptance criterion 2, line 286.)

Procedure:

1. Place a bank-transfer order via `/lv/checkout` (payment method:
   bank-transfer). Order lands in status `new` (id=1).
2. Wait 30 seconds — confirm NO Purchase event appears in Test Events for
   this order (the order is unpaid; nothing should fire).
3. In Backend Admin → Orders → flip the order's status to
   `new-payment-received`. Save.
4. Within ≤ 60 seconds: Test Events shows exactly ONE event for the order,
   labelled `Server (CAPI)` only. No Browser/Pixel twin yet — the
   customer is not on `/lv/checkout/{secret_key}` at this moment.
5. Customer visits `/lv/checkout/{secret_key}` later (different browser
   session, simulating "next time customer opens the link"). Confirm Test
   Events now shows the matching `Browser (Pixel)` entry with the SAME
   `event_id` as step 4.

Pass/fail recorded.

---

## Step 6 — Scenario 3: Status flip-flop never re-fires

(BRIEF acceptance criterion 2, line 287.)

Procedure:

1. Take the order from Scenario 1 OR Scenario 2 (already paid, has
   CAPI+Pixel rows).
2. In Backend Admin → flip status to `canceled` (id=4). Save.
3. Wait 30 seconds — confirm NO new event appears in Test Events (the
   away-flip is a no-op).
4. Flip status BACK to `new-payment-received`. Save.
5. Wait 60 seconds — confirm Test Events shows NO NEW event for this
   order. The existing CAPI+Pixel pair is unchanged.
6. Verify in DB:

   ```sql
   SELECT COUNT(*) FROM logingrupa_metapixel_event_log
   WHERE subject_id = {iOrderId}
     AND event_name = 'Purchase'
     AND channel = 'capi';
   ```

   Returns exactly 1.

Pass/fail recorded.

---

## Step 7 — Scenario 4: Refresh + incognito on different device never re-fires

(BRIEF acceptance criteria 3 + 4, lines 287-288.)

Procedure:

1. Take the order from Scenario 1 (has CAPI+Pixel rows).
2. Hard-refresh `/lv/checkout/{secret_key}` (Ctrl+Shift+R). Confirm
   DevTools → Network → `fbevents.js` → NO `track Purchase` request
   fires. PageView still fires (PixelHead is untouched).
3. Open the same URL in a new incognito window on a DIFFERENT device
   (e.g. phone). Confirm NO `track Purchase` fires.
4. Wait 10 days (or set a calendar reminder; this is the cross-Meta-
   7-day-dedup-window test). On day 11, repeat step 3. Still no
   `track Purchase` — server-side `event_log` is the only source of
   truth, independent of Meta's eventID dedup window expiry.

Pass/fail recorded (the 10-day check can be deferred; capture the
immediate refresh + incognito results as the criterion-4 pass marker).

---

## Scenario 5 — Cross-context admin flip + customer frontend revisit

Mirrors prod bug closed by Phase 3.1-07 (REFAC-12..14).

**Preconditions:** v1.1.1 deployed (`composer install && php artisan october:up && sudo systemctl reload php8.4-fpm`). Fresh install — no backfill needed (BACKFILL.sql retired post-cleanup; plugin works out of box).

1. Pick a bank-transfer order on `/back` (`status_id=1`, `new`). Note `order.id`, `order.secret_key`, `order.site_id` (verify `site_id IS NOT NULL` via SQL probe below — if NULL on multi-site install, abort; order predates Lovata v1.33 multi-site migration).
2. Flip status `1 → 5` (`new-payment-received`) via admin. Save.
3. Meta Events Manager → Test Events: observe ONE Purchase server HTTP 2xx with `test_event_code=TEST6581` (or your configured code).
4. SQL probe:

   ```sql
   SELECT id, channel, site_id, event_id, fired_at
     FROM logingrupa_metapixel_event_log
    WHERE subject_id = <ORDER_ID>
      AND subject_type = 'Lovata\\OrdersShopaholic\\Models\\Order';
   ```

   Expected: ONE row, `channel='capi'`, `site_id=<this site's id>` (NOT NULL).
5. Incognito window. Visit `/lv/checkout/{order.secret_key}`. DevTools Network — confirm `fbq` POST to `connect.facebook.net` AND `jax.ajax('purchasePixel::onMarkFired')` XHR status 200.
6. SQL probe again — expected TWO rows: `channel='capi'` + `channel='pixel'`, SAME `site_id`, SAME `event_id`, SAME `event_time`.
7. Meta Events Manager → Test Events: observe ONE Purchase Browser event paired with Server from step 3 (same `event_id` → Meta dedups).
8. STATE.md operator-append:

   ```
   - 2026-MM-DD — Scenario 5 PASSED on <site> (order #<id>): cross-context site_id symmetry verified. v1.1.1 RUNTIME-VERIFIED.
   ```

Failure mode (Phase 3.1-07 regression detector): step 5 shows NO `fbq` POST AND step 6 SQL probe shows ONE row only (`channel='capi'`, `site_id` matches but no Pixel row). Means `PurchasePixel::findEventLogRow` did NOT resolve via `forOrder` — file regression bug; BRIEF + RESEARCH for Phase 3.1-07 hold locked fix shape.

---

## Step 8 — Record checkpoint pass in STATE.md

Once Steps 1-7 are all PASS, append to `.planning/STATE.md` Phase 3.1
Forward Notes block:

```markdown
### Phase 3.1 Manual Staging Checkpoint — PASSED YYYY-MM-DD
- Site: {staging.nailscosmetics.{lt,lv,no}}
- composer qa: green
- system_plugin_versions: 1.1.0
- Scenario 1 (PayPal): PASS — dedup XX%, EMQ X
- Scenario 2 (bank-transfer admin flip): PASS
- Scenario 3 (status flip-flop): PASS
- Scenario 4 (refresh + incognito): PASS
- Operator: {your name / email}
```

Commit message: `docs(03.1-06): staging checkpoint PASSED on {site}`.
Once all three sites pass, tag the plugin repo `v1.1.0` (annotated tag
recommended for release-traceability).

---

## Failure recovery

- **Migration list incomplete** → roll back via `php artisan october:down
  --rollback={count}` then re-pull master + re-run `october:up`. Verify
  `system_plugin_versions` returns to the previous version BEFORE the
  next deploy attempt.
- **composer qa fails** → re-read latest plugin SUMMARYs in
  `.planning/phases/*/`. Identify which test broke; fix on a feature
  branch off `master`; re-merge through the CI gate. Do NOT bypass
  `metapixel-qa.yml`.
- **Test Events shows duplicate (non-deduped) Purchase events** →
  `event_id` mismatch between server and browser. Check that
  `PurchasePixel::onRun()` reads from the EventLog CAPI row, not a
  fresh UUID. Confirm `arMetaEvent.event_id` in DevTools Network
  payload matches `event_log.event_id` for that order.
- **Test Events shows zero events on PayPal flow** → check PayPal IPN
  reaches the site (Forge logs). Check `OrderStatusWatcher::handleUpdated`
  fires (temporary `Log::info` in the watcher, redeploy, retry; revert
  on success). Check `composer qa` ran the latest test suite (the
  `OrderStatusWatcherEventLogTest` + `PurchaseEndToEndIntegrationTest`
  cover this contract).

---

## Cross-references

- BRIEF acceptance criteria: `.planning/phases/03.1-event-log-refactor/BRIEF.md`
  lines 280-294.
- VERIFICATION report (deferred items): `.planning/phases/03.1-event-log-refactor/VERIFICATION.md`
  lines 162-178.
- Integration test class (CI-side proofs):
  `tests/Feature/PurchaseEndToEndIntegrationTest.php`.
- CI workflow: `.github/workflows/metapixel-qa.yml` — `composer qa` on
  every push/PR touching the plugin tree.
- Sibling Phase 3.1 SUMMARYs: `.planning/phases/03.1-event-log-refactor/03.1-0{1..5}-SUMMARY.md`.
