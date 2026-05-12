---
gsd_state_version: 1.0
milestone: v1.0.0
milestone_name: milestone
status: in-progress
stopped_at: "Plan 03-04 complete (PAY-06 + PAY-07 + PAY-08 — PayloadBuilder Purchase envelope + UserDataHasher CCache memoization + OrderFixtures real-DB factory: 5 files / 94 tests / 289 assertions / 90.0% total / PayloadBuilder.php 84.1% / UserDataHasher.php 90.3%). Next: plan 03-05 (PAY-02 — SendCapiEvent queue job)."
last_updated: "2026-05-12T22:26:07Z"
last_activity: 2026-05-12 -- Plan 03-04 shipped (composer qa green, 94 tests / 289 assertions / 0 skipped / 90.0 % coverage / PayloadBuilder.php 84.1 % / UserDataHasher.php 90.3 %). Phase 3 4/6 plans done.
progress:
  total_phases: 5
  completed_phases: 2
  total_plans: 6
  completed_plans: 4
  percent: 66
---

# Project State

## Project Reference

See: `.planning/PROJECT.md` (updated 2026-04-22)

**Core value:** Meta Ads Manager sees every purchase (including bank-transfer and admin-marked-paid), dedup ≥ 80 %, EMQ ≥ 8 for Purchase
**Current focus:** Phase 02 — skeleton-cookie-fix

## Current Position

Phase: 03 (purchase-end-to-end) — in progress
Plan: 4 of 6 (03-01 + 03-02 + 03-03 + 03-04 shipped — PAY-01 + PAY-04 + PAY-05 + PAY-06 + PAY-07 + PAY-08 + PAY-09 done)
Status: Phase 03 wave 2 complete — plans 03-01 + 03-02 + 03-03 + 03-04 done. Next: plan 03-05 (PAY-02, wave 3 — SendCapiEvent queue job).
Last activity: 2026-05-12 -- Plan 03-04 shipped: classes/meta/PayloadBuilder.php (303 LOC) — Graph API v20 Purchase envelope with 4-step currency fallback (relation → field → Settings → throw per CONTEXT.md Specifics line 158), byte-for-byte content_ids contract (StoreExtender::CartComponentHandler::buildSkuId), all 3 PAY-09 precondition throws (InvalidEventIdException, OrderHasNoCurrencyException, OrderHasNoItemsException), constructor-injected UserDataHasher with lazy default, custom_data.order_id = order_number (NOT id) per FUN-14, resolveProductIdForOffer helper (Offer::where('id', $iOfferId)->value('product_id')) — handles OrderPosition polymorphic schema (item_id + item_type), getRawOriginal('price') bypasses PriceHelperTrait formatter. classes/meta/UserDataHasher.php (195 LOC) — sha256(mb_strtolower(trim)) for em/ph/fn/ln/external_id, plaintext request metadata (client_ip/UA/fbp/fbc), phone normalisation honouring Settings phone_country_code (default 371 LV; multi-site .no=47), guest external_id = sha256(secret_key) per PAY-08, CCache memoization (tag 'meta-pixel-user-hash', key 'meta-pixel-user-hash:order:{id}'). tests/Support/OrderFixtures.php — 3 named factory methods (makePaidOrder / makeMultiOfferOrder / makeGuestOrderWithoutEmail) + 6 typed constants (EXPECTED_SINGLE_SKU = 'SKU-10', EXPECTED_MULTI_SKU = 'SKU-11-102', etc.) + hermetic offer/product/order_position table provisioning + one_c_status_id column patch (Lovata.BaseCode dependency). tests/Unit/PayloadBuilderTest.php — 14 test methods locking envelope shape + content_ids + custom_data + 3 PAY-09 preconditions + REVISED 4-step currency fallback (2 tests: settings-fallback NO-throw path + all-3-sources-empty THROW path per BLOCKER 1 resolution). tests/Unit/UserDataHasherTest.php — 11 test methods locking PII hashing + phone normalisation 3 paths + cache memoization + determinism. 5 task commits + 1 phpstan auto-fix commit + summary commit. composer qa green: 94 tests / 289 assertions / 0 skipped / **90.0% coverage** (PayloadBuilder 84.1% / UserDataHasher 90.3% / MetaClient 100% / Exceptions 100% / FailedEvent 100%). 7 deviations: Rule 1 phpstan level 10 11-error narrowing (added stringOrEmpty/intOrZero/floatOrZero/stringOrNull/narrowCachedArray helpers, replaced instanceof Request with try/return-on-throw, replaced relation access with getRelationValue + is_object + method_exists guard); Rule 1 OrderPosition polymorphic (resolveProductIdForOffer + getRawOriginal('item_id')); Rule 1 price column not price_value (getRawOriginal('price') bypasses PriceHelper::format); Rule 3 one_c_status_id Lovata.BaseCode dependency (OrderFixtures patches column into bootOrdersTable schema); Rule 3 currency_id = null in fixtures (no hermetic currency table); Rule 3 Offer SoftDelete + orderBy('sort_order') (added columns in OrderFixtures); Rule 1 pint cosmetic (4 fixers applied).

Plan-checker BLOCKER 1 resolved: resolveCurrency 4-step fallback chain (relation → field → Settings → throw) per CONTEXT.md Specifics line 158, locked by 2 unit tests (NO-throw fallback path + THROW last-line-of-defence path).

## Performance Metrics

**Velocity:**

- Total plans completed: 9 (Phase 1 + Plans 02-01..04 + Plans 03-01..04)
- Average duration: ~21 min (Plans 02-01 + 02-02 + 02-03 + 02-04 + 03-01 + 03-02 + 03-03 + 03-04: ~94+9+10+5+14+39 ≈ ~171 min / 8 plans = ~21 min); Phase 1 not timed
- Total execution time: ~2.85 hours

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|---|---|---|---|
| 1. Tooling | 1 | — | — |
| 2. Skeleton+cookie | 4/4 | ~103 min | 26 min |
| 3. Purchase end-to-end | 4/6 | ~68 min | 17 min |

**Recent Trend:**

- Last 9 plans: 01-tooling/01-PLAN (passed), 02-skeleton/02-01..04 (all passed), 03-purchase/03-01-PLAN + 03-02-PLAN + 03-03-PLAN + 03-04-PLAN (passed).
- Trend: Plan 03-04 = 5 task commits + 1 phpstan auto-fix follow-up + 1 summary commit, 7 deviations (Rule 1 phpstan level 10 11-error narrowing, Rule 1 OrderPosition polymorphic schema discovery, Rule 1 price column not price_value, Rule 3 one_c_status_id Lovata.BaseCode, Rule 3 hermetic Currency table absent, Rule 3 Offer SoftDelete + orderBy, Rule 1 pint cosmetic). composer qa green / 94 tests / 289 assertions / 0 skipped / **90.0 % coverage** (was 92.7 %; -2.7pp, explained by +700 LOC new production code) / **PayloadBuilder.php 84.1 %** / **UserDataHasher.php 90.3 %**. **Phase 3 wave 2 COMPLETE — PAY-06 + PAY-07 + PAY-08 shipped on top of wave 1 (PAY-01 + PAY-04 + PAY-05 + PAY-09). 4 plans / 4 requirements (PAY-02 + PAY-03 + PAY-10..11) still pending.**

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

Last activity: 2026-05-12 — Plan 03-04 (PayloadBuilder + UserDataHasher + OrderFixtures — PAY-06 + PAY-07 + PAY-08) shipped end-to-end. 5 task commits + 1 phpstan auto-fix commit + 1 summary commit. composer qa green: 94 tests / 289 assertions / 0 skipped / **90.0 % coverage** (PixelHead 94.4 % / middleware 96.1 % / PluginGuard 93.5 % / Settings 92.9 % / Plugin 52.0 % / FailedEvent 100% / all 8 exception classes 100% / MetaClient 100 % / **PayloadBuilder 84.1 %** / **UserDataHasher 90.3 %**). PAY-01 + PAY-04 + PAY-05 + PAY-06 + PAY-07 + PAY-08 + PAY-09 complete. **Phase 3: 4 / 6 plans done — wave 2 COMPLETE (PAY-06 + PAY-07 + PAY-08 shipped on top of PAY-01 + PAY-04 + PAY-05 + PAY-09). 2 plans / 4 requirements (PAY-02 + PAY-03 + PAY-10..11) pending.**
Last session: 2026-05-12
Stopped at: Plan 03-04 complete. PayloadBuilder + UserDataHasher transform layer in place. Next: plan 03-05 (PAY-02 — SendCapiEvent queue job). The PayloadBuilder.buildPurchaseEventPayload + UserDataHasher.forOrder contracts are the consumer surfaces for SendCapiEvent (which calls MetaClient::send with the built envelope) and OrderStatusWatcher (which dispatches SendCapiEvent with the order_id + event_id + event_time tuple).
Resume file: `.planning/phases/03-purchase-end-to-end/03-05-PLAN.md`
