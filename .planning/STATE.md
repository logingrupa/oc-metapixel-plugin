---
gsd_state_version: 1.0
milestone: v1.1.0
milestone_name: milestone
status: executing
stopped_at: "Phase 3.1-07 — cross-context closure at contract level (12-task plan, MVP-TDD discipline, ALL RED → GREEN cycles preserved in git log); operator runs BACKFILL.sql per affected site + deploys v1.1.1 + runs STAGING-RUNBOOK Scenario 5 → on PASS, tag v1.1.1 + `/gsd-plan-phase 4`. Next focus: Phase 4 (funnel completion — AddToCart, ViewContent, Lead) reusing Phase 3.1 API surface (EventLog + EventLogWriter::record + SiteResolver — forOrder for Order-scoped, getActiveSiteId for non-Order subjects)."
last_updated: "2026-05-14T21:19:49.696Z"
last_activity: 2026-05-14
progress:
  total_phases: 8
  completed_phases: 2
  total_plans: 16
  completed_plans: 10
  percent: 25
---

# Project State

## Project Reference

See: `.planning/PROJECT.md` (updated 2026-04-22)

**Core value:** Meta Ads Manager sees every purchase (including bank-transfer and admin-marked-paid), dedup ≥ 80 %, EMQ ≥ 8 for Purchase
**Current focus:** Phase 3.1-07 — cross-context site_id symmetry CLOSED at contract level (2026-05-14, v1.1.1); operator runs BACKFILL.sql + STAGING Scenario 5; next focus = Phase 4 funnel completion

## Current Position

Phase: 3.1-07 (cross-context site_id symmetry) — CONTRACT-VERIFIED (v1.1.1); operator runs BACKFILL.sql + STAGING-RUNBOOK Scenario 5 for live closure
Plan: 1 of 1 (03.1-07 shipped 2026-05-14)
Status: Ready to execute

### Prior Phase 3.1 Cursor (preserved for history)

Phase: 3.1 (event-log refactor) — RUNTIME-VERIFIED (CI contracts) + STAGING CHECKPOINT PENDING (operator action via STAGING-RUNBOOK.md)
Plan: 6 of 6 (Plans 03.1-01..05 shipped 2026-05-13; Plan 03.1-06 staging-checkpoint automation shipped 2026-05-14)
Status: Phase 3.1 closed at the contract level — plugin v1.1.0 ledger entry in version.yaml. Schema bedrock + model + helpers + queue + watcher + component + multi-site test + cleanup all green. PurchaseEndToEndIntegrationTest (5 methods) codifies the 4 BRIEF acceptance scenarios + 1 multi-site sanity check in CI. STAGING-RUNBOOK.md spells out the deploy + 4-scenario live-environment procedures for the operator.

### Prior Phase Cursor (preserved for history)

Phase: 03 (purchase-end-to-end) — automated tasks complete, Task-9 manual staging verification SUPERSEDED by Phase 3.1 manual checkpoint (the column-based contract no longer exists; Phase 3.1's event_log contract is the canonical staging-verification surface).
Plan: 6 of 6 tasks 1-8 done (03-06 — PAY-03 + PAY-10/11 plumbing shipped). Task 9 superseded by Phase 3.1 closure.
Status: Phase 03 wave 4 partial — production code superseded by Phase 3.1 refactor; PAY-10 + PAY-11 acceptance criteria now mapped to Phase 3.1 BRIEF acceptance scenarios.
Last activity: 2026-05-14

## Performance Metrics

**Velocity:**

- Total plans completed: 15.5 (Phase 1 + Plans 02-01..04 + Plans 03-01..06 tasks 1-8 + Plans 03.1-01..05 = 1 + 4 + 5.5 + 5 = 15.5; 03-06 task 9 manual checkpoint superseded by Phase 3.1)
- Average duration: ~17 min (sum ~265 min / 15.5 plans); Phase 1 not timed
- Total execution time: ~4.4 hours

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|---|---|---|---|
| 1. Tooling | 1 | — | — |
| 2. Skeleton+cookie | 4/4 | ~103 min | 26 min |
| 3. Purchase end-to-end | 6/6 (task 9 superseded by 3.1) | ~95 min automated | 16 min |
| 3.1. Event-log refactor | 5/5 | ~71 min | 14 min |

**Recent Trend:**

- Last 10 plans: 02-skeleton/02-01..04 (all passed), 03-purchase/03-01..06 (all passed; task 9 superseded), 03.1/03.1-01..05 (all passed).
- Trend: Phase 3.1 ran 5 atomic plans across 4 waves; 4 commits on average per plan (multi-commit Tiger-Style SRP discipline). Sub-7-min plans on Waves 2 + 4 (helpers + cleanup); 25-35 min on Waves 1 + 3 (schema + rewrites). Verification surface: all 5 BRIEF acceptance criteria 1-3 + 5-9 codified by tests (MultiSiteEventLogTest + EventLogTest + SendCapiEventEventLogTest + PurchasePixelEventLogGateTest + OrderStatusWatcherEventLogTest); criterion 4 = operator manual staging checkpoint.

*Updated after each plan completion*

## Accumulated Context

### Decisions

Decisions are logged in PROJECT.md Key Decisions table.

Carried forward from v3 plan synthesis (2026-04-22):

- `event_id` direction is server → frontend only. Never reverse.
- `content_ids` format locked to `SKU-{product_id}[-{offer_id}]` to match Facebook Catalog feed exporter.
- Paid-status trigger default = `new-payment-received` (Status ID=5), configurable dropdown.
- ~~Idempotency via DB column `meta_purchase_event_id VARCHAR(36) NULL INDEX` on `lovata_orders_shopaholic_orders`~~ — **SUPERSEDED 2026-05-13 by Phase 3.1**. New: idempotency via plugin-owned `logingrupa_metapixel_event_log` table (polymorphic subject, multi-site `site_id` scoped, UNIQUE(subject_type, subject_id, event_name, channel, site_id) race-fence; second `channel='pixel'` row suppresses browser re-fires across devices/sessions/time).
- Boot-time missing `pixel_id` = log + disabled flag (NOT throw).
- No `assert()` anywhere — enforced by `spaze/phpstan-disallowed-calls`.
- Lead event wiring hooks salon application-form `onSend` (only functional lead form on site).
- v1 dead-letter sink = log + backend `FailedEvents` list + `onReplay`. External alerting deferred to v1.1.
- Folder layout = Lovata singular (`classes/{event,queue,helper,meta,exception}/` + `middleware/` at plugin root).
- Settings extends `Lovata\Toolbox\Models\CommonSettings`, NOT plain `Model`.

New from Phase 3.1 (event-log refactor, all 5 plans):

- **REFAC-CANONICAL** — Race-fence atomicity = `EventLog` UNIQUE constraint on `(subject_type, subject_id, event_name, channel, site_id)`. `EventLogWriter::record(string, string, string, object, ?string, int): bool` is the SINGLE write entrypoint (used by SendCapiEvent::handle for CAPI channel + PurchasePixel::onMarkFired for Pixel channel). Returns true on race-winner INSERT, false on UNIQUE collision OR DB failure (fail-safe — caller does NOT proceed to HTTP POST / fbq fire on false).
- **REFAC-MULTI-SITE** — Multi-site contract via `SiteResolver::getActiveSiteId(): ?int`. Reads `Config::get('system.active_site')` through October 4's `SiteManager::instance()->getActiveSiteId()` SDK with `Throwable` boundary catch returning null (Tiger-Style fail-safe). MySQL/SQLite UNIQUE treats NULL as distinct, so single-site (`site_id=null`) and multi-site (`site_id=int`) rows coexist on the same table without collision. All reads scope via `if ($iSiteId === null) whereNull('site_id') else where('site_id', $iSiteId)` branch to mirror the write semantic.
- **REFAC-SUPERSESSION** — Phase 3 column-fence on `lovata_orders_shopaholic_orders.meta_purchase_event_id` + `meta_purchase_event_time` was a stop-gap. Phase 3.1 plugin-owned table is the permanent contract. Phase 4 funnel events (AddToCart, ViewContent, Lead) reuse `EventLogWriter::record` with different `EventLog::CHANNEL_*` + `event_name` arguments — no new schema needed. The race-fence shape is forward-compatible.

### Pending Todos

Deferred from Phase 1 code review (decide before Phase 2 starts):

- **BR-01** CI auth — `.github/workflows/metapixel-qa.yml` runs `composer install` at repo root which needs auth for private logingrupa/* deps. Recommended: composer GH OAuth secret. Will fail on first push without it. _(Still pending — Phase 5 launch.)_
- **LR-01** Namespace casing — `Logingrupa\Metapixelshopaholic` (current, lowercase) vs `Logingrupa\MetaPixelShopaholic` (PascalCase, matches sibling plugins). _(CLOSED — keep current; Plan 02-01 confirms via CONTEXT Area 4 Q1.)_
- **MR-02** phpmd script path widen — currently only scans `Plugin.php`; reopens at every phase. _(CLOSED — Plan 02-01 Task 6 widened to `Plugin.php,classes,middleware,models,components,controllers,updates` + .gitkeep dir placeholders.)_

New from Plan 02-01 execution:

- **HR-02** Pre-existing test-harness leak: Laravel's dotenv loader overrides `phpunit.xml <env force=true>` directives, silently routing tests to production MySQL. Worked around in Plan 02-01 via `createApplication()` programmatic config override. A repo-level fix (root-level `.env.testing` file, or a `Tests\BootsTestEnvironment` trait shared across all Logingrupa plugins) should land in Phase 5. Plugin-side workaround is acceptable for v1.

New from Plan 02-02 execution:

- **PG-01** PluginGuard's Throwable-catch in `prime()` is structural, not a workaround: it materially strengthens SKEL-05 by extending the "boot never throws" guarantee from "empty pixel_id only" to "any Settings read failure" (covers DB outage, missing system_settings table on fresh install, dotenv-leak misroutes). The catch is reason-documented and logs a structured context array distinguishing settings_read_failed from the empty-pixel_id path. No further action — accepted as the canonical PluginGuard contract.
- **PG-02** Container-singleton bridge `App::make('metapixel.disabled')` is now the canonical handler short-circuit contract for Phases 3-5. Documented in PluginGuard class-level PHPDoc + the Plan 02-02 SUMMARY's "API Surface" section. Every Phase 3+ event handler MUST start with `if (App::make('metapixel.disabled')) { return; }`.

New from Plan 02-03 execution:

- **MW-01** Phase 5 README HARD-05 MUST document `Cache-Control: private` requirement on routes hitting `EnsureFbpFbcCookies` middleware. T-02-16: shared-cache cookie leakage on CDN/Varnish if header omitted. TODO surfaced in middleware class-level PHPDoc. No code change needed in Phase 2-4 — operator documentation only.
- **MW-02** Defense-in-depth via `App::bound('metapixel.disabled') && App::make(...)` is the canonical pattern for any future storefront-only Logingrupa.Metapixelshopaholic middleware. Bound-guard handles requests arriving before Plugin::boot() primes PluginGuard.

New from Plan 03-01 execution:

- **MIG-01** Migration class files are snake_case (October Updates Manager convention) and are NOT PSR-4 discoverable from the plugin's `"Logingrupa\\Metapixelshopaholic\\": ""` autoload map. Tests that instantiate migration classes directly must `require_once __DIR__.'/../../updates/<filename>.php';`. Applied in `tests/Feature/MigrationsBootTest.php` + `tests/Feature/FailedEventModelTest.php`. _(Still load-bearing — Phase 3.1 MigrationsBootTest rewrite preserves the pattern across all 4 surviving migrations.)_
- **MIG-02** SQLite-cannot-drop-indexed-columns — confirmed regression in `down()` migrations. Fix is `Schema::table(..., function (Blueprint $obTable) { $obTable->dropIndex($sIndexName); $obTable->dropColumn([...]); })` — drop the index FIRST. Applied in the legacy Phase-3 add migration AND carried forward to Phase 3.1 `drop_meta_purchase_columns_from_orders_table.php::up()`. Any future Phase 3+ migration that adds an indexed column must mirror the pattern in its `down()`.
- **MOD-01** phpmd CyclomaticComplexity threshold = 10 + NPathComplexity = 200. A static factory that branches on multiple `is_array/is_scalar/is_numeric/isset` guards quickly exceeds both. Solution: extract per-precondition private static helpers (e.g. `extractFirstEvent`, `extractStringField`, `encodePayload`, `extractHttpStatus`, `extractAttempts`). Pattern locked in for the rest of Phase 3 + 4 builders.
- **FE-01** _(CLOSED — Plan 03-02 Task 5.)_ MetaPixelException forward-reference suppressions in `models/FailedEvent.php` removed during plan 03-02 qa pass. Also removed the dead `is_array($obException->arContext)` ternary now that `arContext` is statically typed `array` via constructor promotion.
- **FE-02** _(CLOSED — Plan 03-02 Task 5.)_ The 3 createFromPayloadAndException skip-guarded tests now auto-run + pass. `makeMetaPixelExceptionDouble` was rewritten to forward $arContext through `parent::__construct($sMessage, $arContext)` (PHP 8.4 readonly cannot be reassigned post-construct) and implement abstract `isRetryable(): bool` returning false. Return type widened from `object` to MetaPixelException.

New from Plan 03-02 execution:

- **EH-01** PHP 8.4 `public readonly array $arContext` via constructor promotion is the canonical immutability lock for plugin exception context. Any future test double extending `MetaPixelException` MUST forward `$arContext` through `parent::__construct(...)` — direct `$this->arContext = ...` raises `\Error: Cannot modify readonly property`. Pattern locked for plans 03-03..03-06 + Phase 3.1 (test doubles).
- **EH-02** The canonical $arContext convention for trusted Phase-3 code: `['order_id' => int, 'event_id' => string, 'http_status' => ?int, 'attempts' => int, 'graph_error' => ?string]`. Documented in 03-02-SUMMARY's "API Surface Now Available" section. `FailedEvent::createFromPayloadAndException` reads `http_status` + `attempts` from this convention; phpstan level 10 verifies the array key access.
- **EH-03** `composer qa` total coverage 76.1% → 89.3% (+13.2pp) — driven by FailedEvent jumping 0% → 100% + all 8 new exception classes at 100%. Phase 3.1 expected to push this back to ≥ 89.6% (Phase 3 baseline) via new SiteResolver + EventLogWriter + EventLog test coverage offsetting any post-rewrite gaps.
- **EH-04** `jsonContext([])` returns the JSON-array literal `'[]'`, NOT `'{}'` (the `'{}'` literal is the encode-failure fallback only — verified with stream resources in ExceptionHierarchyTest::test_jsonContext_returns_compact_json). The GoodsReceivedException analog has identical behavior. Forward-impact: any Phase-3 plan that wants `'{}'` for empty input must wrap with `$ar === [] ? '{}' : self::jsonContext($ar)`.

Carried forward from Plan 03-05 execution (Phase 3 wave-3):

- **SCE-01** Laravel 12 ShouldQueue queue-job shape — first plugin use of the modern pattern (`PATTERNS.md` flagged "No analog found" — only legacy October-3 `fire($obJob, $arData)` precedents). Final shape: `final class SendCapiEvent implements ShouldQueue` + 4 traits + readonly constructor promotion + container-injected `handle(MetaClient $obClient): void` + `failed(Throwable): void` hook + private writeFailedEvent + buildLogContext helpers. Pattern locked for Phase 4 funnel jobs — they dispatch a NEW SendCapiEvent instance per handler, no subclassing (final class enforces).
- **SCE-02** Multi-catch routes `MetaApiPermanentException | MissingPixelConfigException | MissingCapiTokenException` to a single dead-letter branch. **Forward-impact:** any future MetaPixelException subclass that should dead-letter MUST be added to this multi-catch.
- ~~**SCE-03** Constructor signature `(string $sEventName, array $arPayload)` — flat positional args; $sEventName FIRST so the call reads left-to-right as a typed action.~~ — **SUPERSEDED by Phase 3.1 plan 03.1-03 REFAC-06.** New signature: `(string $sEventName, array $arPayload, Order $obSubject)`. 3rd arg `Order $obSubject` is required so EventLogWriter::record can take the subject through to the race-fence INSERT. v1.1.0 BREAKING CHANGE — caught by `test_constructor_requires_order_subject_parameter` (ArgumentCountError/TypeError dual-catch lock).
- **SCE-04** `failed()` hook else-branch wraps non-Meta exceptions as MetaApiPermanentException. Locked by `test_failed_hook_wraps_non_meta_exception_as_permanent`.
- **SCE-05** PHPUnit 12 risky-test pitfall: `Log::shouldHaveReceived(...)` and `Mockery::on(closure)` assertions are NOT counted by PHPUnit because they validate in `Mockery::close()`/`tearDown`, not via `$this->assert*()`. Fix: always assert state directly via `$this->assertSame(...)` — for Mockery use a captured-by-reference buffer (`$arCaptured`) inside the closure and assert against it post-dispatch. Pattern locked for any future Mockery test in this plugin. _Phase 3.1 plan 03.1-04 reuses this for PurchasePixelEventLogGateTest's forgery + log-injection dual-closure assertion._
- **SCE-06** Test infra: `bindMetaClientWithMockResponses(array $arResponses, array &$arHistory): MockHandler` binds a MockHandler-backed MetaClient into the container via `$this->app->instance(MetaClient::class, ...)`. _Phase 3.1 plan 03.1-03 adopts the by-reference history pattern (MC-04 fix carried forward)._
- **SCE-07** Tiger-Style silent catch in `writeFailedEvent` — DB-write failure during dead-letter logs critical only; rethrowing would cascade a DB outage. T-03-22 mitigation. _Phase 3.1 plan 03.1-02 mirrors this pattern in `EventLogWriter::record`'s Throwable catch (Log::critical + return false fail-safe)._
- **SCE-08** `public readonly array $arPayload` locks payload immutability across retries (T-03-23 mitigation). PHP 8.4 readonly enforcement means the same payload bytes go to Meta on every retry → idempotent at the Meta side via event_id. _Phase 3.1 plan 03.1-03 adds `public readonly Order $obSubject` alongside (SerializesModels stores Order id + class only on the wire — payload size unchanged)._
- ~~**SCE-09** No `ShouldBeUniqueUntilProcessing` dep. Idempotency lives at the dispatch site (plan 03-06 OrderStatusWatcher's `meta_purchase_event_id IS NULL` fence on `lovata_orders_shopaholic_orders`), not the job level. CONTEXT Area 1 Q3 lock.~~ — **SUPERSEDED by Phase 3.1 plan 03.1-03 REFAC-06.** Idempotency now lives at the row level — `EventLogWriter::record` returns false on UNIQUE collision, SendCapiEvent::handle short-circuits before MetaClient::send when false. Same semantics, cleaner schema (no DB-table mutation on Lovata's foreign table).

Carried forward from Plan 03-06 execution (Phase 3 wave-4 — partial, superseded by Phase 3.1):

- ~~**WR-12** Atomic CAS on `meta_purchase_event_id` column via `Order::where('id', $iOrderId)->whereNull('meta_purchase_event_id')->update(['meta_purchase_event_id' => $sUuid])` returning affected-row count.~~ — **DELETED by Phase 3.1 plan 03.1-03 REFAC-07.** OrderStatusWatcher::alreadyDispatched now queries `EventLog::where(subject_type=Order, subject_id, event_name=Purchase, channel=capi)->exists()` instead. The atomic CAS moved DOWN one layer to `EventLogWriter::record`'s `insertOrIgnore` race-fence.

Carried forward from Plan 03-04 execution:

- **PB-01..05** PayloadBuilder + Order fixture patches — unchanged by Phase 3.1, carried forward verbatim.
- **UDH-01..03** UserDataHasher patterns — unchanged by Phase 3.1, carried forward verbatim.
- **PHPSTAN-01** Universal-object-crates do NOT cover Lovata.OrdersShopaholic Order/OrderPosition or Lovata.Shopaholic Offer/Product. Phpstan level 10 + treatPhpDocTypesAsCertain raises `cast.int`, `cast.string`, `property.notFound`, `method.nonObject`, `instanceof.alwaysTrue`, `nullCoalesce.expr` against direct accessors. Mitigation pattern: `getAttribute(...)` + narrowing helpers (intOrZero/floatOrZero/stringOrEmpty/stringOrNull). _Phase 3.1 plan 03.1-02 EventLogWriter mirrors the pattern via `extractSubjectId` private helper._

Carried forward from Plan 03-03 execution:

- **MC-01..06** HTTP-boundary patterns — unchanged by Phase 3.1, carried forward verbatim.
- **MC-07** PH-01 (pixel_id regex validator) still pending. T-03-11 explicitly surfaced again in plan 03-03 SUMMARY's "Forward TODO" section. **HIGH priority** for Phase 5 launch (HARD-03). Without `regex:/^\d{6,20}$/` on the Settings field, a compromised admin can inject path-traversal sequences into the URL via pixel_id — Guzzle's URI-path encoding mitigates SQL/XSS at the HTTP layer but the stored XSS surface in `components/pixelhead/default.htm` remains. **Phase 3.1 does NOT address this — Phase 5 HARD-03 task.**

Carried forward from Plan 02-04 execution:

- **PH-01** Plan 02-01 retro-fit (HIGH priority for Phase 5 launch): add `regex:/^\d{6,20}$/` validator to the `pixel_id` field in `models/settings/fields.yaml` per T-04-01. **Phase 3.1 carries forward — not addressed.**
- **PH-02..05** Phase 4 + 5 prerequisites — unchanged by Phase 3.1, carried forward verbatim.

New from Phase 3.1 execution (all 5 plans):

- **REFAC-API** Phase 4 API surface for funnel events (AddToCart, ViewContent, Lead): `EventLog::CHANNEL_CAPI / CHANNEL_PIXEL` constants + `EventLogWriter::record(string $sEventId, string $sEventName, string $sChannel, object $obSubject, ?string $sSecretKey, int $iEventTime): bool` + `SiteResolver::getActiveSiteId(): ?int` + `EventLog::where(...)->exists()` reader. Phase 4 plans MUST consume this surface — no parallel race-fence implementations allowed (DRY locked by REFAC-MULTI-SITE decision above).
- **REFAC-VERSION** v1.1.0 ledger entry in `updates/version.yaml` lists both Phase 3.1 migrations under `1.1.0:` (drop_meta_purchase + create_metapixel_event_log). Operators upgrading from v1.0.3 → v1.1.0 run `php artisan october:up`; `system_plugin_versions` row for `Logingrupa.Metapixelshopaholic` should reflect v1.1.0 after migration (BRIEF acceptance criterion 5).
- **REFAC-BREAK** v1.1.0 BREAKING changes (Tiger-Style "no backward compatibility"):
    - SendCapiEvent constructor signature gained 3rd arg `Order $obSubject` (SCE-03 superseded).
    - WR-12 atomic-CAS on Order columns deleted (column-fence gone — WR-12 superseded above).
    - Reading from `meta_purchase_event_id` / `meta_purchase_event_time` columns is impossible — columns dropped. Any third-party code consuming these columns from Lovata's table breaks (no third-party consumers known; plugin was the sole writer).

### Phase 3.1-07 Forward Notes

**Cross-context site_id symmetry CLOSED 2026-05-14 (v1.1.1).** SiteResolver::forOrder is the canonical Order-scoped resolver; EventLogWriter::record signature gains 7th `?int $iSiteId` (DRY writer); Watcher + SendCapiEvent + PurchasePixel rewired. tests/Unit/SiteResolverTest + tests/Feature/MultiSiteCrossContextTest codify writer/reader symmetry. See [`STAGING-RUNBOOK.md` Scenario 5](phases/03.1-event-log-refactor/STAGING-RUNBOOK.md) for operator verification and [`BACKFILL.sql`](phases/03.1-07-multi-site-site-id-symmetry/BACKFILL.sql) for pre-deploy row repair on affected production sites.

### Phase 3.1 Forward Notes

**Operator handoff:** see [`STAGING-RUNBOOK.md`](phases/03.1-event-log-refactor/STAGING-RUNBOOK.md) for the deploy + 4-scenario live-environment procedures (Wave-5 Plan 03.1-06 deliverable). The 4 BRIEF scenarios are codified at the contract level in `tests/Feature/PurchaseEndToEndIntegrationTest.php` (5 methods) so CI proves them on every push/PR; the runbook closes the last 10 % of live-plumbing checks (real PayPal IPN, real Pixel script handshake, Meta Events Manager Test Events dashboard).

**Manual staging checkpoint handoff (operator action required):**

After deploying v1.1.0 to staging, the operator runs the 4 BRIEF acceptance scenarios (BRIEF.md lines 282-294). These cannot be automated in CI — they verify the live PayPal + IPN + admin-flow race-fence on real network paths.

1. **PayPal order fires CAPI + Pixel pair, same `event_id`.** Place a real PayPal test-mode order; on PayPal return + IPN delivery (both flow paths land paid-status), verify:
   - `system_plugin_versions` row shows `version='1.1.0'` for `Logingrupa.Metapixelshopaholic`.
   - `logingrupa_metapixel_event_log` has TWO rows for the test Order: `channel='capi'` (server) + `channel='pixel'` (browser). Same `event_id`. Same `event_time` (±0 since browser reads from CAPI row).
   - Meta Events Manager / Test Events sees 1 Purchase event (deduped by event_id within the 7-day window).
2. **Bank-transfer admin flip fires CAPI only.** Place a bank-transfer order; admin flips status to `new-payment-received` from the backend Orders list. Verify:
   - `event_log` has 1 row (`channel='capi'`). No Pixel row yet.
   - On customer visit to `/lv/checkout/{slug}`, Pixel row is inserted; total = 2 rows.
3. **Status flip-flop never re-fires Purchase.** From the backend, flip the test Order: paid → new → paid. Verify:
   - With `refire_purchase_on_status_flip = OFF` (default): no new event_log row; Queue::pushed count still 1.
   - With refire flag ON: a 2nd dispatch is queued, but `EventLogWriter::record` returns false (UNIQUE blocks), so no 2nd HTTP POST to Meta + no 2nd event_log row.
4. **Refresh + new-device incognito → no Purchase re-fire.** From the same browser tab, reload `/lv/checkout/{slug}` 3+ times; the `PixelHead` PageView fires each time but `PurchasePixel` renders NOTHING because the `channel='pixel'` row already exists in event_log. Open an incognito window on a DIFFERENT device → same result. Cross-device persistence is structural (server is single authority).

**API surface for Phase 4 (Funnel Completion):**

```php
// CHANNEL constants (model-locked).
EventLog::CHANNEL_CAPI === 'capi';
EventLog::CHANNEL_PIXEL === 'pixel';
EventLog::EVENT_PURCHASE === 'Purchase';   // Phase 4 will add EVENT_ADD_TO_CART, EVENT_VIEW_CONTENT, EVENT_LEAD, etc.

// SDK probe.
SiteResolver::getActiveSiteId(): ?int;

// Race-fence writer.
EventLogWriter::record(
    string $sEventId,
    string $sEventName,    // 'AddToCart', 'ViewContent', 'Lead', ...
    string $sChannel,      // CHANNEL_CAPI | CHANNEL_PIXEL
    object $obSubject,     // Phase 4 may bind Cart, User, LeadFormSubmission
    ?string $sSecretKey,   // optional slug-direct lookup index
    int $iEventTime,
): bool;                   // true = race winner, false = UNIQUE blocked OR DB failure (fail-safe)
```

**System plugin versions ledger check (operator command):**

```bash
php artisan tinker
>>> use System\Models\PluginVersion;
>>> PluginVersion::where('code', 'Logingrupa.Metapixelshopaholic')->first()->version;
"1.1.0"
```

OR direct SQL:

```sql
SELECT code, version FROM system_plugin_versions WHERE code = 'Logingrupa.Metapixelshopaholic';
-- expected: Logingrupa.Metapixelshopaholic | 1.1.0
```

**SDK resolution note (carry-forward from Task 1 execution):**

`MultiSiteEventLogTest::tearDown` resets `Config::set('system.active_site', null)` per T-3.1-25 mitigation. No SiteManager test-double binding was needed — the SDK reads from Config directly via `HasActiveSite::getActiveSiteId(): Config::get('system.active_site')` (verified at modules/system/classes/sitemanager/HasActiveSite.php line 84-87). Test 2 (single-site install) PASSES either via the `class_exists(SiteManager::class)` short-circuit OR via the `Config::get('system.active_site') === null` branch — both reach return null at the SiteResolver layer.

### Blockers/Concerns

- ~~**MANUAL-CHECKPOINT** Phase 3.1 BRIEF acceptance criterion 4 (refresh + incognito) is testable via PurchasePixelEventLogGateTest's `test_onrun_returns_null_when_pixel_row_exists` invariant + the OrderStatusWatcherEventLogTest existence-fence — but the LIVE network behavior on staging is operator-verified. Pending: operator deploys v1.1.0 to staging.~~ **CLOSED 2026-05-14 by Plan 03.1-06** — contract-level closure via `tests/Feature/PurchaseEndToEndIntegrationTest.php` (5 methods exercising the FULL Watcher → SendCapiEvent → EventLogWriter → MockHandler ↔ PurchasePixel onRun → onMarkFired chain); live-environment plumbing closure delegated to `phases/03.1-event-log-refactor/STAGING-RUNBOOK.md` operator playbook.

### Quick Tasks Completed

| # | Description | Date | Commit | Status | Directory |
|---|---|---|---|---|---|
| 20260422 | Metapixel plan v2→v3 refactor — 7 parallel audits, all 5 open questions resolved via codebase evidence. Plan files committed to plugin repo. | 2026-04-22 | c14ee5c | Complete | (plugin root `.planning/`) |
| 20260513 | Phase 3.1 BRIEF + PATTERNS + 5 plans + 5 SUMMARYs shipped end-to-end. v1.1.0 published. | 2026-05-13 | ec8e25a..165d834 | Complete | `.planning/phases/03.1-event-log-refactor/` |
| 20260514 | Phase 3.1 Wave-5 (Plan 03.1-06) — PurchaseEndToEndIntegrationTest (5 methods locking 4 BRIEF scenarios + multi-site sanity in CI) + STAGING-RUNBOOK.md operator playbook + STATE.md/ROADMAP.md runtime-verified closure. | 2026-05-14 | 632e722..22efd7b | Complete | `.planning/phases/03.1-event-log-refactor/` |
| 20260514b | Phase 3.1-07 (Plan 03.1-07) — Cross-context site_id symmetry hotfix. SiteResolver::forOrder + EventLogWriter signature DRY + Watcher/Queue/Component rewire + BACKFILL.sql + STAGING Scenario 5 + v1.1.1 bump. Closes 2026-05-14 prod bug. | 2026-05-14 | 736b3e3..(this) | Complete | `.planning/phases/03.1-07-multi-site-site-id-symmetry/` |

## Session Continuity

Last activity: 2026-05-14 — Phase 3.1-07 (cross-context site_id symmetry) CLOSED at contract level. Plugin v1.1.1 shipped. `SiteResolver::forOrder(Order): ?int` is the canonical Order-scoped resolver; `EventLogWriter::record(...)` 7th param `?int $iSiteId` (DRY writer); `OrderStatusWatcher::alreadyDispatched` + `PurchasePixel::findEventLogRow` rewired via `forOrder`. New tests: `tests/Unit/SiteResolverTest` (3 methods) + `tests/Feature/MultiSiteCrossContextTest` (3 methods admin-flip cross-context). Extensions on `SendCapiEventEventLogTest`, `OrderStatusWatcherEventLogTest`, `PurchasePixelEventLogGateTest` (one new RED-GREEN method each). `MultiSiteEventLogTest` adjusted to new contract. `PurchaseEndToEndIntegrationTest` Scenarios 4 + multi-site adjusted (Rule 3 — auto-fix blocking from reader rewire). `BACKFILL.sql` repairs stranded prod rows pre-deploy; `STAGING-RUNBOOK Scenario 5` operator playbook. Status advanced `phase-3.1-runtime-verified` → `phase-3.1-cross-context-verified`. Closes 2026-05-14 prod bug on new.nailscosmetics.lv (orders 29802 + 29803).

Last session: 2026-05-14
Stopped at: Phase 3.1-07 — cross-context closure at contract level (12-task plan, MVP-TDD discipline, ALL RED → GREEN cycles preserved in git log); operator runs BACKFILL.sql per affected site + deploys v1.1.1 + runs STAGING-RUNBOOK Scenario 5 → on PASS, tag v1.1.1 + `/gsd-plan-phase 4`. Next focus: Phase 4 (funnel completion — AddToCart, ViewContent, Lead) reusing Phase 3.1 API surface (EventLog + EventLogWriter::record + SiteResolver — forOrder for Order-scoped, getActiveSiteId for non-Order subjects).
Resume file: `.planning/ROADMAP.md`
