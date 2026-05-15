---
phase: 3.1
title: Event-log refactor
status: inserted
inserted_on: 2026-05-13
supersedes_decision: "Idempotency via meta_purchase_event_id column on lovata_orders_shopaholic_orders"
target_plugin_version: 1.1.0
depends_on: [3]
---

# Phase 3.1: Event-Log Refactor — BRIEF

> Source of truth for `/gsd-plan-phase 3.1`. Captures the v2 refactor spec verbatim. CLEAN refactor — NO backward compatibility, NO legacy code, NO dead code, NO duplicate code.

## Problem

Current Phase 3 implementation uses two columns on `lovata_orders_shopaholic_orders` (`meta_purchase_event_id`, `meta_purchase_event_time`) as the race-fence idempotency lock AND as the source for browser Pixel rendering. Two issues:

1. Plugin shouldn't write to Shopaholic's table — violates SRP, hostile to third-party operators who can't audit foreign schema mutations on their core e-commerce tables.
2. Existing two columns can't suppress browser Pixel refires across devices (phone → PC after 10 days). Meta's 7-day eventID dedup window expires → re-fire counts as new conversion → ad-spend optimization wrecked.

## Solution — plugin-owned multi-site event log

NEW table `logingrupa_metapixel_event_log` is the SINGLE source of truth for "has this Meta event fired for this subject":

```sql
CREATE TABLE logingrupa_metapixel_event_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id VARCHAR(36) NOT NULL,
    event_name VARCHAR(64) NOT NULL,        -- Purchase / AddToCart / ViewContent / Lead / ...
    channel VARCHAR(16) NOT NULL,           -- 'capi' or 'pixel'
    subject_type VARCHAR(255) NOT NULL,     -- polymorphic FK type
    subject_id INT UNSIGNED NOT NULL,       -- polymorphic FK id
    secret_key VARCHAR(64) NULL,            -- direct slug index for /checkout/{slug}
    site_id INT UNSIGNED NULL,              -- October 4 multi-site scope
    event_time INT UNSIGNED NOT NULL,       -- Meta-spec Unix timestamp (paired browser+server)
    fired_at TIMESTAMP NOT NULL,            -- when this row was inserted
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY uniq_subject_event_channel (subject_type, subject_id, event_name, channel, site_id),
    INDEX idx_event_id (event_id),
    INDEX idx_secret_key (secret_key, event_name, channel, site_id),
    INDEX idx_subject (subject_type, subject_id, site_id)
);
```

UNIQUE KEY shape protects against:

- Concurrent CAPI dispatches for same Order (PayPal return + IPN race) — one INSERT wins, others fail with duplicate key
- Browser Pixel re-fires across devices/sessions/time — second AJAX INSERT no-ops via ON DUPLICATE KEY
- Cross-site collision when multi-site October installation runs same Order id on two sites — site_id scope keeps them separate

## Multi-site contract (October 4)

October 4 ships with multi-site behavior. Same DB, multiple sites distinguished by `site_id` in `system_settings`, `cms_theme_data`, etc. Settings model resolves per-site via `Site::getActiveSiteId()`.

This plugin MUST be multi-site safe because:

- Plugin shipped to operators we don't control — may run multi-site installations
- Same Order id can theoretically exist on multiple sites (rare but valid)
- Settings (`pixel_id`, `capi_access_token`) ARE per-site — different Pixels per locale
- `event_log` rows are per-site to keep audit trail aligned with the Pixel that received them

Implementation:

- All INSERTs into `event_log` MUST include `site_id` from `Site::getSiteIdFromContext()` or equivalent (October 4 multi-site SDK)
- All reads MUST scope by `site_id = current_active_site_id`
- UNIQUE key includes `site_id` so two sites can independently dispatch Purchase for theoretically-same Order id

Provide a thin helper class `Logingrupa\Metapixelshopaholic\Classes\Helper\SiteResolver`:

- Single method `getActiveSiteId(): ?int` — reads October 4 SDK, returns null when SDK absent/disabled (single-site install)
- Used everywhere `site_id` is needed — DRY

## Hard requirements

- **NO backward compatibility** — delete every reference to `meta_purchase_event_id` + `meta_purchase_event_time` columns
- **NO legacy code** — drop the migrations that added those columns, delete the columns from any test fixtures, remove from PHPDoc/properties everywhere
- **NO dead code** — anything not on the new `event_log` path = deleted
- **NO duplicate code** — single helper per concern (event_log INSERT, event_log query, site_id resolution)
- **Tiger-Style** — fail-fast at boundaries, short functions (<70 lines), explicit return types, every catch logs + rethrows OR has explicit reason comment
- **Hungarian notation** — `$obItem`, `$arList`, `$iCount`, `$sSlug`, `$bIsActive`, `$fPrice`
- **phpstan level 10 + larastan + universal-object-crates** — must pass
- **PSR-2** — phpcs.xml conventions, camelCase methods OK
- **Tests** — integration > unit, hit real SQLite in-memory via `MetapixelTestCase`. UNIQUE constraint race must be tested explicitly (two concurrent INSERT attempts via separate model instances → one wins, one fails gracefully).

## Atomic commits

### 1. Migration: drop legacy columns from Shopaholic Orders (REFAC-01)

File: `plugins/logingrupa/metapixelshopaholic/updates/drop_meta_purchase_columns_from_orders_table.php`

```php
public function up(): void
{
    Schema::table('lovata_orders_shopaholic_orders', function (Blueprint $obTable): void {
        $obTable->dropIndex('lovata_orders_shopaholic_orders_meta_purchase_event_id_index');
        $obTable->dropColumn(['meta_purchase_event_id', 'meta_purchase_event_time']);
    });
}

public function down(): void
{
    Schema::table('lovata_orders_shopaholic_orders', function (Blueprint $obTable): void {
        $obTable->uuid('meta_purchase_event_id')->nullable()->index();
        $obTable->unsignedBigInteger('meta_purchase_event_time')->nullable();
    });
}
```

Apply MIG-02 lock pattern (drop index BEFORE column on SQLite).

Delete the source migrations `add_meta_purchase_event_id_to_orders_table.php` and `add_meta_purchase_event_time_to_orders_table.php` from `updates/`. Update `version.yaml` to remove their entries (or replace with the drop migration as part of v1.1.0).

### 2. Migration: create event_log table (REFAC-02)

File: `plugins/logingrupa/metapixelshopaholic/updates/create_metapixel_event_log_table.php`

Schema per the SQL block above. Use Laravel migration DSL. Add the UNIQUE + indices via `$obTable->unique()` and `$obTable->index()` calls. SQLite-compatible.

### 3. Eloquent model (REFAC-03)

File: `plugins/logingrupa/metapixelshopaholic/models/EventLog.php`

- Namespace `Logingrupa\Metapixelshopaholic\Models`
- Extends October's `Model` (NOT plain Eloquent)
- `$table = 'logingrupa_metapixel_event_log'`
- `$fillable` for all writable columns
- Public class constants:
  - `CHANNEL_CAPI = 'capi'`
  - `CHANNEL_PIXEL = 'pixel'`
  - `EVENT_PURCHASE = 'Purchase'`
- Polymorphic relation helper method `subject()` returning `MorphTo` — for future ops UIs
- Use Hungarian-notation property names throughout the class

### 4. SiteResolver helper (REFAC-04)

File: `plugins/logingrupa/metapixelshopaholic/classes/helper/SiteResolver.php`

- Final class, single static method `getActiveSiteId(): ?int`
- Reads October 4's multi-site SDK (e.g. `\System\Classes\SiteManager::instance()->getActiveSite()?->getKey()` — confirm against installed October version)
- Returns null when SDK absent / no active site bound (single-site or queue/CLI context)
- One unit test asserts null in CLI context

### 5. EventLog write helper (REFAC-05)

File: `plugins/logingrupa/metapixelshopaholic/classes/helper/EventLogWriter.php`

- Final class, single public method `record(...): bool`
- Signature: `record(string $sEventId, string $sEventName, string $sChannel, object $obSubject, ?string $sSecretKey, int $iEventTime): bool`
- Wraps an INSERT IGNORE / ON DUPLICATE KEY UPDATE id=id to leverage the UNIQUE constraint atomically
- Returns true when the row was actually inserted (race winner), false when UNIQUE caught it (race loser or retry)
- Reads `site_id` via `SiteResolver`
- Single function, single responsibility — used by both CAPI dispatch path and Pixel AJAX handler

### 6. SendCapiEvent integration (REFAC-06)

File: `plugins/logingrupa/metapixelshopaholic/classes/queue/SendCapiEvent.php`

- Replace the existing `meta_purchase_event_id IS NULL` race-fence dependency (column gone)
- Race-fence is now the UNIQUE constraint on `event_log` via `EventLogWriter::record` BEFORE HTTP dispatch
- Sequence:
  1. Call `EventLogWriter::record($sEventId, 'Purchase', 'capi', $obOrder, $obOrder->secret_key, $iEventTime)`
  2. If returns false (lost race) → log INFO and return — peer already dispatched, no HTTP POST
  3. If returns true → proceed to `MetaClient::send()`
- This moves the atomic check from DB-table-column to DB-table-row level. Same semantics, cleaner schema.

### 7. OrderStatusWatcher rewrite (REFAC-07)

File: `plugins/logingrupa/metapixelshopaholic/classes/event/OrderStatusWatcher.php`

- Delete the entire WR-12 / WR-13 atomic-CAS-on-orders code (columns gone)
- `handleUpdated` / `handleCreated` still gate on status code match
- Idempotency check is now: `EventLog::where(subject_type=Order, subject_id, event_name='Purchase', channel='capi')->exists()`
- If exists → return (already dispatched)
- Else → generate UUID + `event_time`, call `SendCapiEvent::dispatch` (`afterCommit` wrapper preserved)
- The actual race-fence atomicity lives inside `SendCapiEvent` → `EventLogWriter::record`. Watcher just decides "should we try."
- Refire-flip logic: delete the existing column-clearing branch. Refire-on flag semantics:
  - **ON** → watcher always proceeds to `SendCapiEvent` (which has its own UNIQUE-constraint dedup)
  - **OFF** (default) → watcher pre-checks `event_log` to skip
- Re-read this section against Tiger-Style — refactor to <70 lines per method. Extract small helpers as needed.

### 8. PurchasePixel component rewrite (REFAC-08)

File: `plugins/logingrupa/metapixelshopaholic/components/PurchasePixel.php`

`onRun()` flow:

1. `PluginGuard` disabled → return
2. Resolve Order by `secret_key` slug → null → return
3. Status check → not paid → return
4. Query `event_log` for CAPI row (`event_name='Purchase'`, `channel='capi'`, site-scoped):
   - Absent → return (server hasn't fired yet — don't pair half a contract)
5. Query `event_log` for Pixel row (`channel='pixel'`):
   - Present → return (browser already fired across this or any device)
6. Read `event_id` + `event_time` from the CAPI row (single source of truth)
7. Build `PayloadBuilder` envelope, populate `$arMetaEvent` + `$sCustomDataJson`
8. Render via `default.htm`

New AJAX handler `onMarkFired(): array`:

- Validates input `event_id` matches the order's CAPI row `event_id` (security — prevent forged Pixel-fire claims)
- Calls `EventLogWriter::record` with `channel='pixel'`
- Returns `['ok' => true|false]`

`isAtPaidStatus`, `resolveOrder`, etc. helpers stay; rewrite minimally to use `event_log` instead of order columns.

### 9. Twig partial (REFAC-09)

File: `plugins/logingrupa/metapixelshopaholic/components/purchasepixel/default.htm`

```twig
{% if __SELF__.arMetaEvent is not null %}
<script>
    (function () {
        if (typeof fbq !== 'function') {
            return;
        }
        var sEventId = '{{ __SELF__.arMetaEvent.event_id|e('js') }}';
        fbq('track', 'Purchase', Object.assign({event_time: {{ __SELF__.arMetaEvent.event_time }} }, {{ __SELF__.sCustomDataJson|raw }}), { eventID: sEventId });
        if (typeof jax === 'object' && typeof jax.ajax === 'function') {
            jax.ajax('purchasePixel::onMarkFired', { data: { event_id: sEventId } });
        }
    })();
</script>
{% endif %}
```

No client-side state, no cookie, no `sessionStorage`. Server is single authority.

PageView event remains untouched — `PixelHead` component (separate, owns `fbq('init', ...)` and PageView base call) fires on every page load by design. This refactor ONLY affects Purchase.

### 10. Delete obsolete code (REFAC-10)

- Remove `meta_purchase_event_id` references from `tests/MetapixelTestCase.php::bootOrdersTable` schema patcher
- Remove from any model PHPDoc on Order if added
- Remove from any helper / docblock anywhere in the plugin
- Update `.planning/STATE.md` Pending Todos — close any items tied to the removed columns

### 11. Tests (REFAC-11)

`tests/Feature/EventLogTest.php`:

- UNIQUE constraint blocks duplicate `(subject_type, subject_id, event_name, channel, site_id)` row
- Polymorphic `subject` query works
- `secret_key` index returns matching rows
- `site_id NULL` and `site_id=N` rows coexist correctly under the UNIQUE key (NULL is treated as distinct value by MySQL UNIQUE)

`tests/Feature/SendCapiEventEventLogTest.php`:

- First dispatch records CAPI row + POSTs to Meta
- Second concurrent dispatch: `EventLogWriter::record` returns false, no HTTP POST
- DB-write failure during `record()` does NOT cascade (Tiger-Style boundary)

`tests/Feature/PurchasePixelEventLogGateTest.php`:

- `onRun()` returns null when `channel='pixel'` row exists
- `onRun()` returns null when `channel='capi'` row absent
- `onRun()` renders script when CAPI exists AND Pixel absent
- `onMarkFired()` inserts row
- `onMarkFired()` second call returns `ok=true`, no duplicate row
- `onMarkFired()` rejects `event_id` mismatch (security)

`tests/Feature/OrderStatusWatcherEventLogTest.php`:

- Update existing 10 tests to assert via `event_log` instead of `orders` columns
- Refire-on / refire-off paths covered
- Status flip-flop no-refire via `event_log` existence check

`tests/Feature/MultiSiteEventLogTest.php`:

- Two sites bind same Order id → independent INSERTs succeed
- Single-site install (`SiteResolver` returns null) → `site_id NULL` on all rows
- Read scoped to active site only

## Configuration

No new Settings fields needed. The refire flag `refire_purchase_on_status_flip` stays as-is — semantics shift to "watcher skips event_log existence check when ON" instead of "watcher clears event_id column when ON".

## Acceptance criteria

1. `composer qa` green
2. All Phase 3 scenarios still pass on staging:
   - PayPal order fires CAPI + Pixel pair, same `event_id`
   - Bank-transfer admin flip fires CAPI only, then Pixel on customer visit
   - Status flip-flop never re-fires Purchase
3. Refresh of `/lv/checkout/{slug}` → PageView fires (untouched), Purchase does NOT re-fire
4. New incognito on different device → still no Purchase re-fire (server `event_log` persists)
5. `system_plugin_versions` row for `Logingrupa.Metapixelshopaholic` shows v1.1.0 (semver minor bump)
6. `lovata_orders_shopaholic_orders.meta_purchase_event_id` column does NOT exist
7. `logingrupa_metapixel_event_log` table exists with all indices
8. Concurrent test: two PHP processes calling `SendCapiEvent::dispatch` on same Order → exactly one HTTP POST + exactly one event_log row
9. Multi-site test: same Order id on two `site_id` values → two independent CAPI fires

## Out of scope

- **Phase 4 funnel events** (AddToCart, ViewContent, Lead, etc.) — event_log designed for them but no implementation here
- **Address fields** (ct/st/zp/country) in `UserDataHasher` — separate EMQ uplift
- **Stable external_id for logged-in customers** — separate task
- **AEM / Verified Domain** — operator action

## Execution discipline

Execute atomically, one concern per commit, `composer qa` green at every step. Use existing `OrderStatusWatcher` docblock style for WR-* lock annotations. Bump plugin version to v1.1.0 in `version.yaml`.
