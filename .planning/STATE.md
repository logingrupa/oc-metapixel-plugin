---
gsd_state_version: 1.0
milestone: v1.0.0
milestone_name: milestone
status: phase-3.1-inserted-awaiting-plan
stopped_at: "Phase 3.1 (event-log refactor) INSERTED 2026-05-13. Supersedes Phase 3 idempotency-column mechanism. Phase 3 Task 9 manual staging checkpoint DEFERRED — column mechanism being torn out, staging verification will roll forward to Phase 3.1 completion. BRIEF.md committed at .planning/phases/03.1-event-log-refactor/BRIEF.md. Next action: operator runs `/gsd-plan-phase 3.1` to produce PLAN.md from BRIEF.md."
last_updated: "2026-05-13T00:00:00Z"
last_activity: 2026-05-13 -- Phase 3.1 INSERTED. ROADMAP.md + PROJECT.md Key Decisions + STATE.md cursor updated. BRIEF.md captures the v2 refactor spec verbatim (11 atomic commits REFAC-01..REFAC-11). Plugin-owned multi-site logingrupa_metapixel_event_log table will replace the foreign-schema lovata_orders_shopaholic_orders columns. Plugin version bumps to v1.1.0 on Phase 3.1 completion.
progress:
  total_phases: 6
  completed_phases: 2
  total_plans: 6
  completed_plans: 5.5
  percent: 76
---

# Project State

## Project Reference

See: `.planning/PROJECT.md` (updated 2026-04-22)

**Core value:** Meta Ads Manager sees every purchase (including bank-transfer and admin-marked-paid), dedup ≥ 80 %, EMQ ≥ 8 for Purchase
**Current focus:** Phase 3.1 — event-log refactor (INSERTED 2026-05-13)

## Current Position

Phase: 3.1 (event-log refactor — INSERTED) — BRIEF.md committed, awaiting `/gsd-plan-phase 3.1`
Plan: 0 of N — to be produced from `.planning/phases/03.1-event-log-refactor/BRIEF.md`
Status: Phase 3 wave 4 superseded — column-based idempotency mechanism being replaced by plugin-owned `logingrupa_metapixel_event_log` table. Phase 3 Task 9 manual staging checkpoint DEFERRED — verification rolls forward to Phase 3.1 completion (acceptance criteria 2 in BRIEF.md preserves all original Phase 3 staging scenarios). Plugin will bump to v1.1.0 on Phase 3.1 completion.

### Prior Phase Cursor (preserved for history)

Phase: 03 (purchase-end-to-end) — automated tasks complete, awaiting Task-9 manual staging verification
Plan: 6 of 6 tasks 1-8 done (03-06 — PAY-03 + PAY-10/11 plumbing shipped). Task 9 (BLOCKING manual checkpoint) PENDING — superseded by Phase 3.1.
Status: Phase 03 wave 4 partial — production code + automated tests green; staging verification of PAY-10 + PAY-11 acceptance criteria deferred to Phase 3.1 completion.
Last activity: 2026-05-12 -- Plan 03-06 tasks 1-8 shipped: classes/event/OrderStatusWatcher.php (301 LOC) — final class dispatching Purchase via CAPI on Order eloquent.updated/created with refire-flip + status + idempotency fences and atomic saveQuietly of BOTH meta_purchase_event_id AND meta_purchase_event_time (Pixel-twin contract); components/PurchasePixel.php (245 LOC) — browser-side Pixel twin reading both persisted columns and emitting fbq('track','Purchase',custom_data,{eventID}); components/purchasepixel/default.htm Twig partial with e('js') defence-in-depth; Plugin.php now Event::subscribe(OrderStatusWatcher::class) BEFORE the CLI gate so backend admin (PAY-11) AND queue worker contexts see model events, and registerComponents adds PurchasePixel as 'purchasePixel'; tests/MetapixelTestCase.php bootOrdersTable provisions BOTH new columns + dropHermeticSchemas cleans fixture tables; models/Settings.php public $rules + fields.yaml pattern shipping the PH-01 retro-fit regex /^\d{6,20}$/ for pixel_id (T-04-01 mitigation); tests/Feature/OrderStatusWatcherTest.php (10 methods locking PAY-03 invariants — fresh-paid, same-status-noop, refire=off flip-flop, refire=on flip-flop, plugin-disabled, admin-created path, event_id + event_time persistence, refire=on clears BOTH columns, event_time-within-2-seconds); tests/Feature/PurchasePixelTest.php (13 methods locking the dedup-contract round-trip from DB through component to fbq(), including the byte-for-byte custom_data === CAPI custom_data dedup-contract test). 8 task commits. composer qa green: 126 tests / 365 assertions / 0 skipped / **89.6% coverage** (OrderStatusWatcher.php 90.3% / PurchasePixel.php 83.3% / SendCapiEvent.php 100% / MetaClient.php 100% / PayloadBuilder 84.1% / UserDataHasher 90.3% / Exceptions 100% / FailedEvent 100%). 9 deviations: 7 Rule-1 phpstan-level-10 narrowing fixes (setAttribute/getAttribute for the new columns; intOrZero helper for mixed→int casts; void@return instead of void|Response; Order|null instanceof narrowing; array<string,mixed> re-key on extractCustomData; test fixture idempotency-column clear so eloquent.updated fires fresh; admin-created-path positions-before-order ordering); 1 Rule-2 coverage gap (PurchasePixelTest 66.7% → 83.3% via 6 added tests); 1 Rule-3 commit order swap (Task 4 PurchasePixel files committed BEFORE Task 3 Plugin.php so each commit independently passes composer analyse). **Task 9 (BLOCKING manual): operator must deploy to staging, configure pixel_id + test_event_code, integrate `[purchasePixel] orderSlug = "{{ :slug }}"` block on order-complete.htm, place a real PayPal order, observe Meta Events Manager Test Events to verify dedup ≥ 80% AND EMQ ≥ 8 (PAY-10), then flip a bank-transfer order to paid in backend admin to verify single-channel CAPI (PAY-11), then flip the same order canceled→paid to verify status flip-flop no-refire (PAY-03 success criterion 3). Edit 03-06-SUMMARY.md's "Task 9 staging-verification results" section in place with recorded findings.**

## Performance Metrics

**Velocity:**

- Total plans completed: 10.5 (Phase 1 + Plans 02-01..04 + Plans 03-01..06 tasks 1-8; Task 9 manual checkpoint PENDING)
- Average duration: ~20 min (Plans 02-01 + 02-02 + 02-03 + 02-04 + 03-01 + 03-02 + 03-03 + 03-04 + 03-05 + 03-06 tasks 1-8: ~94+9+10+5+14+39+6+21 ≈ ~198 min / 10 plans = ~20 min); Phase 1 not timed
- Total execution time: ~3.3 hours

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|---|---|---|---|
| 1. Tooling | 1 | — | — |
| 2. Skeleton+cookie | 4/4 | ~103 min | 26 min |
| 3. Purchase end-to-end | 6/6 (Task 9 manual deferred to 3.1) | ~95 min automated | 16 min |
| 3.1. Event-log refactor (INSERTED) | 0/N — awaiting plan | — | — |

**Recent Trend:**

- Last 10 plans: 01-tooling/01-PLAN (passed), 02-skeleton/02-01..04 (all passed), 03-purchase/03-01-PLAN + 03-02-PLAN + 03-03-PLAN + 03-04-PLAN + 03-05-PLAN (passed), 03-06-PLAN tasks 1-8 (passed; task 9 PENDING manual).
- Trend: Plan 03-06 tasks 1-8 = 8 task commits, 9 deviations (7 Rule-1 phpstan narrowing, 1 Rule-2 coverage gap, 1 Rule-3 commit order swap). composer qa green / 126 tests / 365 assertions / 0 skipped / **89.6 % coverage** (was 90.9 %; -1.3pp explained by +550 LOC of new production code) / OrderStatusWatcher.php 90.3 % / PurchasePixel.php 83.3 %. Plan duration ~21 min (8 tasks; on par with the per-task ~3 min average — biggest time sinks were the 2 test files at 5 min each). **Phase 3 wave 4 PARTIAL — PAY-03 shipped automated; PAY-10 + PAY-11 PENDING manual staging verification.**

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

New from Plan 03-05 execution:

- **SCE-01** Laravel 12 ShouldQueue queue-job shape — first plugin use of the modern pattern (`PATTERNS.md` flagged "No analog found" — only legacy October-3 `fire($obJob, $arData)` precedents). Final shape: `final class SendCapiEvent implements ShouldQueue` + 4 traits (Dispatchable, InteractsWithQueue, Queueable, SerializesModels) + readonly constructor promotion + container-injected `handle(MetaClient $obClient): void` + `failed(Throwable): void` hook + private writeFailedEvent + buildLogContext helpers. Pattern locked for Phase 4 funnel jobs — they dispatch a NEW SendCapiEvent instance per handler, no subclassing (final class enforces).
- **SCE-02** Multi-catch routes `MetaApiPermanentException | MissingPixelConfigException | MissingCapiTokenException` to a single dead-letter branch. All three return `isRetryable() === false` from the 03-02 exception hierarchy; separating into three catch branches would double catch-block surface for negligible gain. **Forward-impact:** any future MetaPixelException subclass that should dead-letter MUST be added to this multi-catch.
- **SCE-03** Constructor signature `(string $sEventName, array $arPayload)` — flat positional args; $sEventName FIRST so the call reads left-to-right as a typed action: "send EVENT_NAME with PAYLOAD". Locked for plans 03-06 + Phase 4 dispatch sites.
- **SCE-04** `failed()` hook else-branch wraps non-Meta exceptions as MetaApiPermanentException. Laravel may call failed() with any Throwable (DB outage, container resolution failure, SerializesModels rehydration error). The wrap preserves `FailedEvent::createFromPayloadAndException(MetaPixelException)` type contract. Test `test_failed_hook_wraps_non_meta_exception_as_permanent` locks the contract.
- **SCE-05** PHPUnit 12 risky-test pitfall: `Log::shouldHaveReceived(...)` and `Mockery::on(closure)` assertions are NOT counted by PHPUnit because they validate in `Mockery::close()`/`tearDown`, not via `$this->assert*()`. Fix: always assert state directly via `$this->assertSame(...)` — for Mockery use a captured-by-reference buffer (`$arCaptured`) inside the closure and assert against it post-dispatch. Pattern locked for any future Mockery test in this plugin.
- **SCE-06** Test infra: `bindMetaClientWithMockResponses(array $arResponses): void` binds a MockHandler-backed MetaClient into the container via `$this->app->instance(MetaClient::class, ...)`. SendCapiEvent::dispatchSync resolves MetaClient from the container in `handle(MetaClient)` — auto-resolution picks up the bound mock. No Queue::fake required.
- **SCE-07** Tiger-Style silent catch in `writeFailedEvent` — DB-write failure during dead-letter logs critical only; rethrowing would cause Laravel to retry an already-permanent failure or cascade a DB outage. T-03-22 mitigation. Locked by `test_db_write_failure_during_dead_letter_does_not_cascade` (drops failed_events table → dispatchSync does NOT throw).
- **SCE-08** `public readonly array $arPayload` locks payload immutability across retries (T-03-23 mitigation). PHP 8.4 readonly enforcement means the same payload bytes go to Meta on every retry → idempotent at the Meta side via event_id.
- **SCE-09** No `ShouldBeUniqueUntilProcessing` dep. Idempotency lives at the dispatch site (plan 03-06 OrderStatusWatcher's `meta_purchase_event_id IS NULL` fence on `lovata_orders_shopaholic_orders`), not the job level. CONTEXT Area 1 Q3 lock.

New from Plan 03-04 execution:

- **PB-01** PayloadBuilder pattern: stateless single-shot transform with constructor-injected `?UserDataHasher = null` (lazy default). Test mock-surface = pass `new PayloadBuilder($obFakeHasher)`. Forward-impact: Phase 4 funnel-event specialisations (`buildViewContentPayload`, `buildAddToCartPayload`, etc.) add public methods to the same class — `wrapEnvelope(array): array` extraction lands when the second public method ships.
- **PB-02** 4-step currency fallback per CONTEXT.md Specifics line 158: relation → currency_code → Settings::get('currency_code', 'EUR') → throw OrderHasNoCurrencyException. Last-line-of-defence throw — only triggers when ALL THREE sources empty. Two tests lock the NO-throw fallback path AND the THROW exhaustion path. Pattern reusable for any multi-source field resolution (Phase 4 lead form_data, etc.).
- **PB-03** OrderPosition is POLYMORPHIC (item_id + item_type → MorphTo). `offer_id` is a dynamic getter that returns item_id when item_type = Offer::class. `product_id` is NOT a column. PayloadBuilder reads getRawOriginal('item_id') and resolves product_id via Offer::where('id', $iOfferId)->value('product_id'). Documentation drift in plan's <interfaces> block — corrected during execution.
- **PB-04** OrderPosition `price` (decimal column) ≠ `price_value` (PriceHelperTrait dynamic accessor returning PriceHelper::format result, rounds per Settings.decimals). Use getRawOriginal('price') to preserve cents. Pattern locked for any future OrderPosition price/currency read.
- **PB-05** Hermetic SQLite fixture patches: lovata_orders_shopaholic_orders needs one_c_status_id column (Lovata.BaseCode ExtendOrderFieldsHandler dependency); lovata_shopaholic_offers needs sort_order + softDeletes (default orderBy + SoftDelete trait); lovata_orders_shopaholic_order_positions needs item_id + item_type + price (polymorphic + decimal price). All patches live in OrderFixtures (not MetapixelTestCase — files_modified discipline).
- **UDH-01** UserDataHasher CCache key contract: `meta-pixel-user-hash:order:{$iOrderId}` (Phase 3 Purchase). Phase 4 will add `:lead:{$sRequestId}` (form submission) and `:request:{$sRequestId}` (bare request). Cache tag `meta-pixel-user-hash` is plugin-scoped and tag-purgeable in test tearDown.
- **UDH-02** Guest external_id derivation per PAY-08: `hash('sha256', mb_strtolower(trim((string) $obOrder->secret_key)))`. Lovata.OrdersShopaholic guarantees secret_key on every persisted order — never null. Pattern reusable for Phase 4 Lead `external_id` from form_data.email.
- **UDH-03** Phone normalisation: preg_replace('/\D+/', '') strips non-digits; prepend Settings::get('phone_country_code', '371') if not already prefixed. Multi-site operator override: .no=47 / .lt=370. The `str_starts_with($sDigits, $sCountryCode)` guard dedupes — already-prefixed phones stay unchanged.
- **PHPSTAN-01** Universal-object-crates do NOT cover Lovata.OrdersShopaholic Order/OrderPosition or Lovata.Shopaholic Offer/Product. Phpstan level 10 + treatPhpDocTypesAsCertain raises `cast.int`, `cast.string`, `property.notFound`, `method.nonObject`, `instanceof.alwaysTrue`, `nullCoalesce.expr` against direct accessors. Mitigation pattern: `getAttribute(...)` + narrowing helpers (intOrZero/floatOrZero/stringOrEmpty/stringOrNull). instanceof Request check → try/return-on-throw pattern. Relation access → `getRelationValue($name)` + `is_object` + `method_exists`. Pattern locked for plans 03-05 + 03-06.

Carried forward from Plan 03-03 execution:

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

Last activity: 2026-05-12 — Plan 03-05 (SendCapiEvent Laravel 12 ShouldQueue queue job — PAY-02) shipped end-to-end. 3 task commits + 1 summary commit. composer qa green: 106 tests / 318 assertions / 0 skipped / **90.9 % coverage** (PixelHead 94.4 % / middleware 96.1 % / PluginGuard 93.5 % / Settings 92.9 % / Plugin 52.0 % / FailedEvent 100% / all 8 exception classes 100% / MetaClient 100 % / PayloadBuilder 84.1 % / UserDataHasher 90.3 % / **SendCapiEvent 100.0 %**). PAY-01 + PAY-02 + PAY-04 + PAY-05 + PAY-06 + PAY-07 + PAY-08 + PAY-09 complete. **Phase 3: 5 / 6 plans done — wave 3 PARTIAL (PAY-02 shipped). 1 plan / 3 requirements (PAY-03 + PAY-10..11) pending.**
Last session: 2026-05-12
Stopped at: Plan 03-05 complete. SendCapiEvent queue boundary in place. Next: plan 03-06 (PAY-03 — OrderStatusWatcher dispatch site + PAY-10..11 manual staging verification). The SendCapiEvent::dispatch('Purchase', $arPayload) contract is the consumer surface for OrderStatusWatcher — argument order is `(string $sEventName, array $arPayload)` per SCE-03 lock. OrderStatusWatcher must generate UUIDv4 + saveQuietly to meta_purchase_event_id + dispatch SendCapiEvent, all fenced by `meta_purchase_event_id IS NULL` per CONTEXT Area 2 Q4.
Resume file: `.planning/phases/03-purchase-end-to-end/03-06-PLAN.md`
