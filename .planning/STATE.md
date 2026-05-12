---
gsd_state_version: 1.0
milestone: v1.0.0
milestone_name: milestone
status: in-progress
stopped_at: "Plan 03-02 complete (PAY-09 — Exception hierarchy: MetaPixelException abstract base + 7 concrete finals + lang stubs en/lv/ru + 11-test ExceptionHierarchyTest). Plan 03-01 forward-reference loop closed. Next: plan 03-03 (PAY-01 — MetaClient Guzzle wrapper)."
last_updated: "2026-05-12T21:43:41Z"
last_activity: 2026-05-12 -- Plan 03-02 shipped (composer qa green, 54 tests / 184 assertions / 0 skipped / 89.3 % coverage). Phase 3 2/6 plans done.
progress:
  total_phases: 5
  completed_phases: 2
  total_plans: 6
  completed_plans: 2
  percent: 33
---

# Project State

## Project Reference

See: `.planning/PROJECT.md` (updated 2026-04-22)

**Core value:** Meta Ads Manager sees every purchase (including bank-transfer and admin-marked-paid), dedup ≥ 80 %, EMQ ≥ 8 for Purchase
**Current focus:** Phase 02 — skeleton-cookie-fix

## Current Position

Phase: 03 (purchase-end-to-end) — in progress
Plan: 2 of 6 (03-01 + 03-02 shipped — PAY-04 + PAY-05 + PAY-09 done)
Status: Phase 03 wave 1 complete — plans 03-01 + 03-02 both done. Next: plan 03-03 (PAY-01, wave 2 — MetaClient Guzzle wrapper).
Last activity: 2026-05-12 -- Plan 03-02 shipped: 1 abstract base (MetaPixelException) + 7 final concretes (MissingPixelConfigException, MissingCapiTokenException, OrderHasNoCurrencyException, OrderHasNoItemsException, InvalidEventIdException, MetaApiTransientException, MetaApiPermanentException), PHP 8.4 constructor-promoted public readonly array $arContext, abstract isRetryable(): bool contract (only MetaApiTransientException returns true), JSON_UNESCAPED_SLASHES|UNICODE jsonContext() log-injection guard, 7 lang stub keys × 3 locales (en/lv/ru = 21 entries), 11-test ExceptionHierarchyTest locking abstract/final/extends/readonly/isRetryable/jsonContext/lang-key invariants. 5 task commits + summary commit. composer qa green (54 tests / 184 assertions / 0 skipped / 89.3% coverage, up from 76.1%). Plan 03-01 forward-reference loop CLOSED — 4 @phpstan-ignore class.notFound markers removed; 3 previously-skipped FailedEventModelTest cases auto-run + pass. All 8 exception files at 100% coverage. FailedEvent jumped 0% → 100%. 3 deviations: Rule 1 jsonContext([]) test expectation mismatch (analog returns '[]' for empty), Rule 3 readonly-aware anon double rewrite in FailedEventModelTest, Rule 3 dead is_array branch removed in FailedEvent::createFromPayloadAndException.

## Performance Metrics

**Velocity:**

- Total plans completed: 7 (Phase 1 + Plans 02-01..04 + Plans 03-01..02)
- Average duration: ~20 min (Plans 02-01 + 02-02 + 02-03 + 02-04 + 03-01 + 03-02: ~94+9+10+5 = ~118 min / 6 plans = ~20 min); Phase 1 not timed
- Total execution time: ~2.0 hours

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|---|---|---|---|
| 1. Tooling | 1 | — | — |
| 2. Skeleton+cookie | 4/4 | ~103 min | 26 min |
| 3. Purchase end-to-end | 2/6 | ~15 min | 8 min |

**Recent Trend:**

- Last 7 plans: 01-tooling/01-PLAN (passed), 02-skeleton/02-01..04 (all passed), 03-purchase/03-01-PLAN + 03-02-PLAN (passed).
- Trend: Plan 03-02 = 5 task commits + 1 summary commit, 3 deviations (Rule 1 jsonContext test expectation, Rule 3 readonly anon double, Rule 3 dead is_array branch). composer qa green / 54 tests / 184 assertions / 0 skipped / 89.3 % coverage (was 76.1 %; +13.2pp). All 8 new exception files at 100% coverage. FailedEvent 0% → 100%. Wave-1 forward-reference loop fully closed. **Phase 3 wave 1 done — PAY-04 + PAY-05 + PAY-09 shipped. 4 plans / 8 requirements (PAY-01..03, PAY-06..08, PAY-10..11) still pending.**

*Updated after each plan completion*

## Accumulated Context

### Decisions

Decisions are logged in PROJECT.md Key Decisions table.

Carried forward from v3 plan synthesis (2026-04-22):

- `event_id` direction is server → frontend only. Never reverse.
- `content_ids` format locked to `SKU-{product_id}[-{offer_id}]` to match Facebook Catalog feed exporter.
- Paid-status trigger default = `new-payment-received` (Status ID=5), configurable dropdown.
- Idempotency via DB column `meta_purchase_event_id VARCHAR(36) NULL INDEX` on `lovata_orders_shopaholic_orders`.
- Boot-time missing `pixel_id` = log + disabled flag (NOT throw).
- No `assert()` anywhere — enforced by `spaze/phpstan-disallowed-calls`.
- Lead event wiring hooks salon application-form `onSend` (only functional lead form on site).
- v1 dead-letter sink = log + backend `FailedEvents` list + `onReplay`. External alerting deferred to v1.1.
- Folder layout = Lovata singular (`classes/{event,queue,helper,meta,exception}/` + `middleware/` at plugin root).
- Settings extends `Lovata\Toolbox\Models\CommonSettings`, NOT plain `Model`.

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

- **MIG-01** Migration class files are snake_case (October Updates Manager convention) and are NOT PSR-4 discoverable from the plugin's `"Logingrupa\\Metapixelshopaholic\\": ""` autoload map. Tests that instantiate migration classes directly must `require_once __DIR__.'/../../updates/<filename>.php';`. Applied in `tests/Feature/MigrationsBootTest.php` + `tests/Feature/FailedEventModelTest.php`. Same pattern applies to plan 03-06 OrderStatusWatcherTest when it boots the orders schema.
- **MIG-02** SQLite-cannot-drop-indexed-columns — confirmed regression in `down()` migrations. Fix is `Schema::table(..., function (Blueprint $obTable) { $obTable->dropIndex($sIndexName); $obTable->dropColumn([...]); })` — drop the index FIRST. Applied in `updates/add_meta_purchase_event_id_to_orders_table.php`. Any future Phase 3+ migration that adds an indexed column must mirror the pattern in its `down()`.
- **MOD-01** phpmd CyclomaticComplexity threshold = 10 + NPathComplexity = 200. A static factory that branches on multiple `is_array/is_scalar/is_numeric/isset` guards quickly exceeds both. Solution: extract per-precondition private static helpers (e.g. `extractFirstEvent`, `extractStringField`, `encodePayload`, `extractHttpStatus`, `extractAttempts`). Pattern locked in for the rest of Phase 3 + 4 builders.
- **FE-01** _(CLOSED — Plan 03-02 Task 5.)_ MetaPixelException forward-reference suppressions in `models/FailedEvent.php` removed during plan 03-02 qa pass. Also removed the dead `is_array($obException->arContext)` ternary now that `arContext` is statically typed `array` via constructor promotion.
- **FE-02** _(CLOSED — Plan 03-02 Task 5.)_ The 3 createFromPayloadAndException skip-guarded tests now auto-run + pass. `makeMetaPixelExceptionDouble` was rewritten to forward $arContext through `parent::__construct($sMessage, $arContext)` (PHP 8.4 readonly cannot be reassigned post-construct) and implement abstract `isRetryable(): bool` returning false. Return type widened from `object` to MetaPixelException.

New from Plan 03-02 execution:

- **EH-01** PHP 8.4 `public readonly array $arContext` via constructor promotion is the canonical immutability lock for plugin exception context. Any future test double extending `MetaPixelException` MUST forward `$arContext` through `parent::__construct(...)` — direct `$this->arContext = ...` raises `\Error: Cannot modify readonly property`. Pattern locked for plans 03-03..03-06 (MetaClient/PayloadBuilder/SendCapiEvent/OrderStatusWatcher test doubles).
- **EH-02** The canonical $arContext convention for trusted Phase-3 code: `['order_id' => int, 'event_id' => string, 'http_status' => ?int, 'attempts' => int, 'graph_error' => ?string]`. Documented in 03-02-SUMMARY's "API Surface Now Available" section. `FailedEvent::createFromPayloadAndException` reads `http_status` + `attempts` from this convention; phpstan level 10 verifies the array key access.
- **EH-03** `composer qa` total coverage 76.1% → 89.3% (+13.2pp) — driven by FailedEvent jumping 0% → 100% (the 3 previously-skipped factory tests now run) + all 8 new exception classes at 100%. The "is FailedEvent really 0%?" doubt from plan 03-01's SUMMARY is resolved: it WAS only because the factory was untested, not because of pcov trait-attribution. The trait-attribution explanation was wrong; the static factory was simply unreached by Phase 2 baseline tests.
- **EH-04** `jsonContext([])` returns the JSON-array literal `'[]'`, NOT `'{}'` (the `'{}'` literal is the encode-failure fallback only — verified with stream resources in ExceptionHierarchyTest::test_jsonContext_returns_compact_json). The GoodsReceivedException analog has identical behavior. Forward-impact: any Phase-3 plan that wants `'{}'` for empty input must wrap with `$ar === [] ? '{}' : self::jsonContext($ar)`.

Carried forward from Plan 02-04 execution:

- **PH-01** Plan 02-01 retro-fit (HIGH priority for Phase 5 launch OR Phase 3 pre-PAY-01): add `regex:/^\d{6,20}$/` validator to the `pixel_id` field in `models/settings/fields.yaml` per T-04-01. Without it a compromised admin could set pixel_id to `'); alert(1)//` and break out of the inlined `<script>` string in `components/pixelhead/default.htm`. Backend Settings authenticated trust boundary mitigates partially, but stored XSS surface remains.
- **PH-02** Phase 4 FUN-01 prerequisite: when `custom_data` becomes non-empty (`content_ids`, `value`, `currency`), the `arMetaEvent.custom_data|json_encode|raw` Twig chain MUST be paired with an explicit allowlist in `PixelHead::onRun()`. T-04-02 + T-04-05 are mitigated by `[]` in Phase 2 but reopen the moment Phase 4 lands.
- **PH-03** Phase 5 README HARD-04 + HARD-05: document the theme partial migration step — once `{% component 'pixelHead' %}` is included in a layout, the theme owner removes the legacy `fbq('track', 'PageView')` line from `themes/logingrupa-naisstore/partials/facebook_pixel.htm`. Until that step is executed, both partials fire and Meta counts the theme partial's no-eventID call as a separate event (T-04-04).
- **PH-04** Test-harness reflection-priming pattern (PluginGuard state via ReflectionClass instead of Settings::set→get round-trip) is the canonical Singleton+memoized test-double for Phases 3-5. Reusable for MetaClient (capi_access_token), OrderStatusWatcher (paid_status_code), etc. Documented in `tests/Feature/PixelHeadTest.php::primePluginGuardEnabled` + class PHPDoc.
- **PH-05** PluginGuard.php has `@method static self instance()` class-level PHPDoc to surface the October Singleton trait's actual return contract for phpstan level 10. Same pattern must be applied to ANY future Singleton-trait consumer in this plugin that wants to chain instance methods under phpstan scan.

### Blockers/Concerns

None. All 5 open questions resolved via codebase evidence (see `.planning/answers/`).

### Quick Tasks Completed

| # | Description | Date | Commit | Status | Directory |
|---|---|---|---|---|---|
| 20260422 | Metapixel plan v2→v3 refactor — 7 parallel audits, all 5 open questions resolved via codebase evidence. Plan files committed to plugin repo. | 2026-04-22 | c14ee5c | Complete | (plugin root `.planning/`) |

## Session Continuity

Last activity: 2026-05-12 — Plan 03-02 (Exception hierarchy — PAY-09) shipped end-to-end. 5 task commits + 1 summary commit. composer qa green: 54 tests / 184 assertions / 0 skipped / **89.3 % coverage** (PixelHead 94.4 % / middleware 96.1 % / PluginGuard 93.5 % / Settings 92.9 % / Plugin 52.0 % / **FailedEvent 100% / all 8 exception classes 100%**). PAY-04 + PAY-05 + PAY-09 complete. **Phase 3: 2 / 6 plans done — wave 1 closed.**
Last session: 2026-05-12
Stopped at: Plan 03-02 complete. Wave-1 forward-reference loop CLOSED. Next: plan 03-03 (PAY-01 — MetaClient Guzzle wrapper, wave 2). The exception hierarchy now in place is the throw/catch surface for plans 03-03 (MetaClient), 03-04 (PayloadBuilder), 03-05 (SendCapiEvent), 03-06 (OrderStatusWatcher).
Resume file: `.planning/phases/03-purchase-end-to-end/03-03-PLAN.md`
