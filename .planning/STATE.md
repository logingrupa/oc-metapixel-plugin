---
gsd_state_version: 1.0
milestone: v1.0.0
milestone_name: milestone
status: in-progress
stopped_at: "Plan 03-03 complete (PAY-01 — MetaClient Guzzle 7 wrapper: 198-LOC HTTP boundary class + 14-test MetaClientTest with MockHandler-backed Guzzle + 100% MetaClient.php coverage). Next: plan 03-04 (PAY-06 — PayloadBuilder)."
last_updated: "2026-05-12T22:03:00Z"
last_activity: 2026-05-12 -- Plan 03-03 shipped (composer qa green, 69 tests / 230 assertions / 0 skipped / 92.7 % coverage / MetaClient.php 100 %). Phase 3 3/6 plans done.
progress:
  total_phases: 5
  completed_phases: 2
  total_plans: 6
  completed_plans: 3
  percent: 50
---

# Project State

## Project Reference

See: `.planning/PROJECT.md` (updated 2026-04-22)

**Core value:** Meta Ads Manager sees every purchase (including bank-transfer and admin-marked-paid), dedup ≥ 80 %, EMQ ≥ 8 for Purchase
**Current focus:** Phase 02 — skeleton-cookie-fix

## Current Position

Phase: 03 (purchase-end-to-end) — in progress
Plan: 3 of 6 (03-01 + 03-02 + 03-03 shipped — PAY-04 + PAY-05 + PAY-09 + PAY-01 done)
Status: Phase 03 wave 2 in progress — plans 03-01 + 03-02 + 03-03 done. Next: plan 03-04 (PAY-06, wave 2 — PayloadBuilder).
Last activity: 2026-05-12 -- Plan 03-03 shipped: classes/meta/MetaClient.php (198 LOC) — single HTTP boundary to Meta Graph API v20.0 /events with constructor-injectable `?ClientInterface $obClient`, public const string GRAPH_VERSION = 'v20.0', private const array TRANSIENT_STATUS_CODES = [408, 429, 500, 502, 503, 504], 'http_errors' => false on default Client (single getStatusCode switch as decision point), 5-second timeout (T-03-15 worker-block cap), lazy Settings reads in send() (pixel_id/capi_access_token/test_event_code), four typed-exception throw sites (MissingPixelConfigException, MissingCapiTokenException, MetaApiTransientException, MetaApiPermanentException), Log::warning/error breadcrumbs without access token (T-03-12). tests/Unit/MetaClientTest.php — 14 test methods locking the 7 send-time invariants + transient-status sweep (408/429/500/502/503/504) + permanent-status sweep (400/401/403/404/422) + ConnectException → transient + RequestException catch coverage (defense-in-depth) + decodeResponseBody non-array guard + GRAPH_VERSION constant. 3 task commits + summary commit. composer qa green (69 tests / 230 assertions / 0 skipped / 92.7% coverage / MetaClient.php 100% / total 86.3% → 92.7% +6.4pp). 5 deviations: Rule 1 HR-02 multi-Settings::set flap (switched to reflection-priming via Settings::instance()->setAttribute — plan explicitly anticipated this), Rule 3 Pest 4 @dataProvider on class-style tests (replaced with inline foreach), Rule 1 array-destructure breaks by-ref (switched to explicit by-ref parameter), Rule 1 phpstan level 10 json_decode→array<mixed> narrowing (extracted decodeResponseBody helper with explicit key-iteration), Rule 3 phpmd CyclomaticComplexity = 10 hit (extracted classifyResponse helper).

## Performance Metrics

**Velocity:**

- Total plans completed: 8 (Phase 1 + Plans 02-01..04 + Plans 03-01..03)
- Average duration: ~19 min (Plans 02-01 + 02-02 + 02-03 + 02-04 + 03-01 + 03-02 + 03-03: ~94+9+10+5+14 ≈ ~132 min / 7 plans = ~19 min); Phase 1 not timed
- Total execution time: ~2.2 hours

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|---|---|---|---|
| 1. Tooling | 1 | — | — |
| 2. Skeleton+cookie | 4/4 | ~103 min | 26 min |
| 3. Purchase end-to-end | 3/6 | ~29 min | 10 min |

**Recent Trend:**

- Last 8 plans: 01-tooling/01-PLAN (passed), 02-skeleton/02-01..04 (all passed), 03-purchase/03-01-PLAN + 03-02-PLAN + 03-03-PLAN (passed).
- Trend: Plan 03-03 = 3 task commits + 1 summary commit, 5 deviations (Rule 1 HR-02 multi-Settings::set flap → reflection priming, Rule 3 Pest 4 dataProvider → inline foreach, Rule 1 array-destructure by-ref → explicit by-ref param, Rule 1 phpstan json_decode narrowing → decodeResponseBody extraction, Rule 3 phpmd CyclomaticComplexity = 10 → classifyResponse extraction). composer qa green / 69 tests / 230 assertions / 0 skipped / 92.7 % coverage (was 89.3 %; +3.4pp) / **MetaClient.php at 100 %**. **Phase 3 wave 2 in progress — PAY-01 shipped on top of wave 1 (PAY-04 + PAY-05 + PAY-09). 3 plans / 7 requirements (PAY-02 + PAY-03 + PAY-06..08 + PAY-10..11) still pending.**

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

New from Plan 03-03 execution:

- **MC-01** HTTP-boundary pattern: constructor-injectable `?ClientInterface $obClient = null` is the canonical testable HTTP-client shape for this plugin. Default Guzzle Client built with `'http_errors' => false` so status-code-based classification flows through a SINGLE switch (no parallel try/catch shapes). Reusable for any future Logingrupa.Metapixelshopaholic third-party-HTTP class (e.g. Phase 5 HARD-03's optional Slack/Telegram dead-letter alerter).
- **MC-02** HR-02 confirmed reproducible under multi-Settings::set load. The `Settings::set + clearInternalCache + Cache::flush` round-trip flaps when ≥ 2 fields are primed per test (every MetaClient test sets pixel_id + capi_access_token, some also test_event_code). The reliable workaround is the reflection-priming pattern (`Settings::instance()->setAttribute(...)`) — identical shape to `PixelHeadTest::primePluginGuardEnabled`. Pattern locked for plans 03-04..03-06.
- **MC-03** Pest 4 does NOT enumerate PHPUnit `@dataProvider` decorators on class-style test methods. The data-driven pattern in Pest 4 is functional (`it('...', $closure)->with([dataset])`). For class-style tests use an inline `foreach` loop. Documented in MetaClientTest::test_send_throws_transient_on_each_transient_status_code's class-level comment.
- **MC-04** Array-destructuring of a returned tuple `[$a, $b] = $fn()` silently breaks by-reference semantics — `[$obClient, $arHistory] = $this->make(...)` with `return [..., &$arHistory]` copies the dereferenced array. Fix: explicit by-reference parameter on the helper. Pattern locked for any future Middleware::history-based test in this plugin.
- **MC-05** phpstan level 10 + `json_decode(..., true)` returns `array<mixed>` (NOT `array<string, mixed>`). The narrowing path that does NOT require `@phpstan-ignore` / `assert` / `@var` is: `foreach ($mDecoded as $mKey => $mValue) { if (is_string($mKey)) { ... } }` — phpstan infers `array<string, mixed>` from the explicit key check. Pattern locked for any future JSON-decode-to-typed-array in this plugin.
- **MC-06** phpmd `CyclomaticComplexity = 10` fires at exactly 10 (reportLevel inclusive). HTTP-client send() methods with 3 Settings guards + try/catch (ConnectException, RequestException) + status-code classification hit this trivially. Extract the response-classification block into a private `classifyResponse(int, string): array` helper to drop complexity from 10 → 6. Pattern locked for plans 03-04..03-06.
- **MC-07** PH-01 (pixel_id regex validator) still pending. T-03-11 explicitly surfaced again in plan 03-03 SUMMARY's "Forward TODO" section. **HIGH priority** for plan 03-06 OrderStatusWatcher OR Phase 5 HARD-03. Without `regex:/^\d{6,20}$/` on the Settings field, a compromised admin can inject path-traversal sequences into the URL via pixel_id — Guzzle's URI-path encoding mitigates SQL/XSS at the HTTP layer but the stored XSS surface in `components/pixelhead/default.htm` remains.

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

Last activity: 2026-05-12 — Plan 03-03 (MetaClient Guzzle 7 wrapper — PAY-01) shipped end-to-end. 3 task commits + 1 summary commit. composer qa green: 69 tests / 230 assertions / 0 skipped / **92.7 % coverage** (PixelHead 94.4 % / middleware 96.1 % / PluginGuard 93.5 % / Settings 92.9 % / Plugin 52.0 % / FailedEvent 100% / all 8 exception classes 100% / **MetaClient 100 %**). PAY-01 + PAY-04 + PAY-05 + PAY-09 complete. **Phase 3: 3 / 6 plans done — wave 2 in progress (PAY-01 done, PAY-06..08 + PAY-02..03 + PAY-10..11 pending).**
Last session: 2026-05-12
Stopped at: Plan 03-03 complete. MetaClient HTTP boundary in place. Next: plan 03-04 (PAY-06 — PayloadBuilder, wave 2). The MetaClient::send(array): array contract is now the consumer surface for plan 03-05 SendCapiEvent (PAY-02) — `handle(MetaClient $obClient): void` will resolve via Laravel container.
Resume file: `.planning/phases/03-purchase-end-to-end/03-04-PLAN.md`
