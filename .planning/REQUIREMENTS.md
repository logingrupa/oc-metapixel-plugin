# Requirements: Logingrupa.MetapixelShopaholic

**Defined:** 2026-04-22
**Core Value:** Meta Ads Manager sees every purchase (including bank-transfer and admin-marked-paid), dedup ≥ 80 %, EMQ ≥ 8 for Purchase — unlocking CAPI-driven optimisation.
**Contract:** Meta dedupes on its side; we ship the same `event_id` (server-generated UUID v4) on both browser `fbq()` and server `POST /events`. Server → frontend direction only. Never reverse.

## v1 Requirements

### Tooling (Phase 1)

- [ ] **TOOL-01**: `composer.json` declares `php ^8.4`, October Rain ^4.0, `lovata/toolbox-plugin ^2.2`, `lovata/ordersshopaholic-plugin ^1.33`, `lovata/shopaholic-plugin ^1.32`, `lovata/buddies-plugin ^1.10`, `guzzlehttp/guzzle ^7.8`, `ramsey/uuid ^4.7`, plus dev deps (Pest ^4.1 + pest-plugin-drift ^4.0, PHPUnit ^12, larastan ^3.0, spaze/phpstan-disallowed-calls ^4.0, phpmd ^2.15, pint ^1.26, rector ^2.0, mockery ^1.6). `composer` script `qa` chains `pint-test` → `analyse` → `phpmd` → `test-cov`.
- [ ] **TOOL-02**: `phpstan.neon` at level 10 with larastan extension + spaze/phpstan-disallowed-calls extension + `universalObjectCratesClasses` for `Lovata\Toolbox\Classes\Item\ElementItem` and `Lovata\Toolbox\Classes\Collection\ElementCollection`. `disallowedFunctionCalls` bans `assert()` and `@` suppression. `reportUnmatchedIgnoredErrors: true`, `treatPhpDocTypesAsCertain: true`, `checkUninitializedProperties: true`.
- [ ] **TOOL-03**: `phpmd.xml` copied verbatim from `plugins/lovata/toolbox/PHPMD_custom.xml` with `LongVariable max=40` (was 25). Class ≤ 1000 LOC, CC report ≥ 10, `ShortVariable min=4` (allows `$ob`, `$ar`, `$iN`).
- [ ] **TOOL-04**: `pint.json` Laravel preset + `ordered_imports: alpha`, `no_unused_imports`, `single_quote`, `binary_operator_spaces: single_space`, `exclude: [updates]`.
- [ ] **TOOL-05**: `rector.php` with `LevelSetList::UP_TO_PHP_84`, `SetList::CODE_QUALITY`, `SetList::DEAD_CODE`, `SetList::EARLY_RETURN`, `SetList::TYPE_DECLARATION`.
- [ ] **TOOL-06**: Pest 4 scaffold — `tests/Pest.php`, `tests/MetapixelTestCase.php` copied from `plugins/logingrupa/campaignpricingshopaholic/tests/CampaignPricingTestCase.php` pattern (extends `System\Tests\Bootstrap\TestCase`, uses `InteractsWithAuthentication`/`PerformsMigrations`/`PerformsRegistrations`, overrides `setUp(): void` with `$this->runOctoberUpCommand()`).
- [ ] **TOOL-07**: `.github/workflows/metapixel-qa.yml` runs `composer install` + `composer qa` on PHP 8.4 for push to master and PRs touching `plugins/logingrupa/metapixelshopaholic/**`.
- [ ] **TOOL-08**: `composer qa` passes green on the empty plugin scaffold (pint-test clean, phpstan 0 errors, phpmd 0 warnings, pest 0 tests/0 failures).

### Skeleton + cookie fix (Phase 2)

- [x] **SKEL-01**: `Plugin.php` registers all event listeners in `boot()` (Cart `shopaholic.cart.add`, CartPosition `model.afterUpdate`/`model.afterDelete`, Wishlist `ExtendProductComponent::extend` + `addDynamicMethod('onMetaTrackAddToWishList')`, Order `shopaholic.order.created` + `model.afterUpdate`, User `eloquent.created: Lovata\Buddies\Models\User`). `plugin.yaml` declares plugin metadata + requirements. _(Plan 02-01 ships the SKEL-01 metadata-layer subset — pluginDetails + registerSettings + plugin.yaml + narrowed $require. Event subscribers ship in Phases 3-4.)_
- [x] **SKEL-02**: `models/Settings.php` extends `Lovata\Toolbox\Models\CommonSettings` with `settingsCode = 'logingrupa_metapixelshopaholic_settings'` and `models/settings/fields.yaml` defining: `pixel_id` (text, translatable), `capi_access_token` (password), `test_event_code` (text), `currency_code` (text, default `EUR`), `phone_country_code` (text, default `371`), `send_hashed_pii` (switch, default on), `queue_connection` (dropdown: redis/database/sync, default `database`), `paid_status_code` (dropdown from `Status::lists('name','code')`, default `new-payment-received`), `refire_purchase_on_status_flip` (switch, default off), `ensure_fbp_fbc_server_side` (switch, default on). `getPaidStatusCodeOptions()` lists all Shopaholic statuses.
- [x] **SKEL-03**: `middleware/EnsureFbpFbcCookies.php` at plugin root sets `_fbp` / `_fbc` cookies server-side when missing, using Meta-spec format (`fb.{subdomain-index}.{creation-timestamp}.{random}`). Registered via `Plugin::boot()` → `app(HttpKernel::class)->pushMiddleware(...)` (Laravel-native; October's PluginBase has no `registerMiddleware()` method).
- [x] **SKEL-04**: Plugin extends the theme's existing `facebook_pixel.htm` partial (via component `PixelHead` rendered on layout) without replacing it. Twig consumes `arMetaEvent` (event_id, event_time, event_name, custom_data) to emit `fbq('track', name, Object.assign({event_time}, data), {eventID})`.
- [x] **SKEL-05**: Boot-time missing `pixel_id` triggers `Log::warning('Metapixel: pixel_id not configured — plugin disabled')` and sets a plugin-wide disabled flag. Event handlers short-circuit while the flag is true. Does NOT throw at boot (would cascade break Campaigns/PromoMechanism). Verified by feature test booting with empty Settings.
- [x] **SKEL-06**: `lang/{en,lv,ru}/lang.php` RainLab.Translate-compatible scaffolding for Settings labels (content left empty or stubbed; full translations in Phase 5).

### Purchase end-to-end (Phase 3)

- [x] **PAY-01**: `classes/meta/MetaClient.php` wraps Guzzle (`guzzlehttp/guzzle ^7.8`) targeting Graph API v20 `/events` endpoint. Constructor-injectable `ClientInterface` for test mocking. Reads `pixel_id`, `capi_access_token`, `test_event_code` from Settings. Exponential-backoff retry 3× on transient errors (HTTP 429/5xx), throws `MetaApiTransientException`. Permanent errors (4xx except 429) throw `MetaApiPermanentException`. ✓ Plan 03-03 (2026-05-12 — MetaClient itself is stateless single-shot; the 3× exponential retry lives at the queue-job layer in plan 03-05 SendCapiEvent::$backoff = [1, 4, 16] per CONTEXT Area 1 Q1+Q2 lock; transient list = [408, 429, 500, 502, 503, 504] + ConnectException; MetaClient.php at 100% coverage)
- [x] **PAY-02**: `classes/queue/SendCapiEvent.php` queue job accepting `(event_id, event_time, event_name, custom_data, user_data, action_source, event_source_url)`. Retries 3× on `MetaApiTransientException`. On `MetaApiPermanentException` → `FailedEvent::createFromPayloadAndException` + no rethrow (dead-lettered). Logs at each stage with context array. ✓ Plan 03-05 (2026-05-12 — final class SendCapiEvent implements ShouldQueue; constructor `(string $sEventName, array $arPayload)` with public readonly promoted properties; $tries = 3 + $backoff = [1, 4, 16]; handle(MetaClient) container-injected; multi-catch routes MetaApiTransientException → rethrow vs MetaApiPermanentException | MissingPixelConfigException | MissingCapiTokenException → FailedEvent + no rethrow; failed(Throwable) hook wraps non-Meta exceptions as MetaApiPermanentException; meta_pixel.* log namespace; 181 LOC at 100.0% coverage; 12 dispatchSync-driven Pest tests; total plugin coverage 90.9%)
- [x] **PAY-03**: `classes/event/OrderStatusWatcher.php` listens on `Order::eloquent.updated` + `eloquent.created`. When `$obOrder->status->code === Settings::get('paid_status_code', 'new-payment-received')` AND `meta_purchase_event_id` is NULL, generates UUIDv4, `saveQuietly` to the column (AND meta_purchase_event_time companion column atomically — Pixel-twin dedup contract), then dispatches `SendCapiEvent::dispatch('Purchase', $arPayload)`. Idempotent by DB-level guard. Refire-flip away-clear path supported when `refire_purchase_on_status_flip = true`. ✓ Plan 03-06 (2026-05-12 — 90.3% coverage; 10 OrderStatusWatcherTest invariants locked: fresh-paid, same-status-noop, refire=off flip-flop, refire=on flip-flop with both-columns-cleared, plugin-disabled, admin-created path, event_id + event_time persistence, event_time-within-2-seconds. PurchasePixel browser-twin component also shipped reading both persisted columns; 13 PurchasePixelTest invariants lock the dedup-contract round-trip from DB through component to fbq().)
- [x] **PAY-04**: `updates/add_meta_purchase_event_id_to_orders_table.php` adds `meta_purchase_event_id VARCHAR(36) NULL` + index, positioned after the existing `secret_key` column on `lovata_orders_shopaholic_orders`. Reversible `down()`. ✓ Plan 03-01 (also adds `meta_purchase_event_time BIGINT UNSIGNED NULL` for Pixel + CAPI event_time dedup matching)
- [x] **PAY-05**: `updates/create_table_failed_events.php` creates `logingrupa_metapixel_failed_events` table (id, event_id UUID, event_name, payload JSON, graph_error TEXT, http_status INT, attempts INT, created_at, updated_at). `models/FailedEvent.php` = plain `October\Rain\Database\Model` + `Validation` trait. ✓ Plan 03-01 (payload column is LONGTEXT per CONTEXT Area 4 Q1; factory `createFromPayloadAndException(array, MetaPixelException): self` shipped)
- [x] **PAY-06**: `classes/meta/PayloadBuilder.php` `buildPurchaseEventPayload(Order, event_id): array` returns Graph API envelope with `data[0] = {event_id, event_time, event_name: 'Purchase', action_source: 'website', event_source_url, user_data, custom_data: {order_id, contents[], value, currency}}`. Preconditions throw (see PAY-09). Contents use `SKU-{product_id}[-{offer_id}]` format (see PAY-13). ✓ Plan 03-04 (2026-05-12 — 84.1% coverage; 14 PayloadBuilderTest invariants; 4-step currency fallback per CONTEXT line 158: relation → field → Settings → throw)
- [x] **PAY-07**: `classes/meta/UserDataHasher.php` builds hashed user_data (em, ph, fn, ln, external_id, client_ip_address, client_user_agent, fbp, fbc). Phone normalised with `phone_country_code` Settings default (`371`). All PII passes `hash('sha256', mb_strtolower(trim($sValue)))`. Cached per-request via `CCache` tag `meta-pixel-user-hash`, keyed by request id (guest) or `order:{id}` (checkout). ✓ Plan 03-04 (2026-05-12 — 90.3% coverage; 11 UserDataHasherTest invariants)
- [x] **PAY-08**: Anonymous `external_id` fallback for guest orders = `hash('sha256', $obOrder->secret_key)`. Stable per-order, no User row required. ✓ Plan 03-04 (2026-05-12 — `external_id = hash('sha256', mb_strtolower(trim($obOrder->secret_key)))`; locked by test_external_id_is_sha256_of_lowercase_trimmed_secret_key)
- [x] **PAY-09**: Custom exception hierarchy in `classes/exception/`: `MetaPixelException` (abstract) → `MissingPixelConfigException`, `MissingCapiTokenException`, `OrderHasNoCurrencyException`, `OrderHasNoItemsException`, `InvalidEventIdException`, `MetaApiTransientException` (retryable), `MetaApiPermanentException` (dead-letter). `PayloadBuilder::buildPurchaseEventPayload` throws appropriately on invariant violations (non-persisted order, wrong status, non-UUID event_id, zero total, null currency). ✓ Plan 03-02 (2026-05-12)
- [ ] **PAY-10**: Events Manager → Test Events shows Pixel + CAPI dedup ≥ 80 % and EMQ ≥ 8 for `Purchase`, verified end-to-end using `test_event_code` and a real paid order on staging. _**Plan 03-06 Tasks 1-8 Complete — PENDING staging verification.** The OrderStatusWatcher dispatch site + PurchasePixel browser twin + atomic event_id/event_time persistence are all shipped and automated-tested. Staging operator must deploy + configure test_event_code + integrate `[purchasePixel] orderSlug = "{{ :slug }}"` block on order-complete.htm + place a real PayPal order + observe Meta Events Manager → Test Events to verify dedup ≥ 80% AND EMQ ≥ 8. Recorded findings to be added in 03-06-SUMMARY.md "Task 9 staging-verification results" section. See plan 03-06-PLAN.md task 9 `<how-to-verify>` block for the protocol._
- [ ] **PAY-11**: Bank-transfer and admin-marked-paid orders (previously invisible to Meta because no browser session) fire Purchase via CAPI only. Dedup is not broken (no Pixel twin exists — Meta accepts the single event). _**Plan 03-06 Tasks 1-8 Complete — PENDING staging verification.** Plugin::boot subscribes OrderStatusWatcher BEFORE the CLI gate so backend admin status-flip is observed. The handler dispatches a single CAPI event (no Pixel twin fires because there is no browser session). Test admin-created-already-paid case automated (test_admin_created_already_paid_order_dispatches in OrderStatusWatcherTest); test backend-flip-from-bank-transfer-pending requires real staging UI + Meta Events Manager observation. See 03-06-PLAN.md task 9 Step 2._

### Funnel events (Phase 4)

- [ ] **FUN-01**: `components/PixelHead.php` on theme layout fires `PageView` with `{}` custom_data + CAPI dispatch; emits `<script>fbq('init', ...); fbq('track', 'PageView', {event_time}, {eventID})</script>` once per page load.
- [ ] **FUN-02**: `components/ProductPagePixel.php` component fires `ViewContent` on `onRun()`, builds custom_data via `PayloadBuilder::viewContent($obElement)` (content_ids, content_name trimmed to 100 chars, currency, value = `$obOffer->price_value`), and dispatches CAPI with the same `event_id` + `event_time`.
- [ ] **FUN-03**: `components/CategoryPagePixel.php` fires `ViewCategory` (custom event via `fbq('trackCustom', ...)`) with first 10 offer ids as content_ids.
- [ ] **FUN-04**: Search component extension fires `Search` with `search_string` + first 10 result content_ids.
- [ ] **FUN-05**: AddToCart — `Lovata\OrdersShopaholic\Components\Cart::extend` + `addDynamicMethod('onMetaTrackAddToCart')`. Frontend calls `jax.ajax('Cart::onAdd', ...)` → then `jax.ajax('Cart::onMetaTrackAddToCart')`. Handler reads last-added `CartPosition` via `CartProcessor::instance()->getLastAddedPosition()`, generates event_id, dispatches CAPI, returns `meta` envelope `{event_id, event_name, event_time, custom_data}` for browser to mirror with `fbq('track', 'AddToCart', ..., {eventID})`.
- [ ] **FUN-06**: AddToWishlist — `Lovata\WishListShopaholic\Components\ExtendProductComponent::extend` + `addDynamicMethod('onMetaTrackAddToWishList')` mirrors the AddToCart pattern. Replaces the v2-planned `shopaholic.favorite.*` hook (verified non-existent in WishListShopaholic via grep).
- [ ] **FUN-07**: InitiateCheckout — `components/CheckoutPixel.php` on `/lv/checkout` fires `InitiateCheckout` on page load with cart contents + total value. Replaces the legacy misspelt `InitiatedCheckout` event. Event name is the Meta-standard `InitiateCheckout`.
- [ ] **FUN-08**: AddPaymentInfo — `Lovata\OrdersShopaholic\Components\MakeOrder::extend` + `addDynamicMethod('onMetaTrackPaymentInfo')`. Frontend fires on payment-method radio toggle.
- [ ] **FUN-09**: Lead — extend salon application-form `onSend` handler at `themes/logingrupa-naisstore/pages/salon/application-form.htm:13-74`. Before the mail sends, generate event_id + dispatch `SendCapiEvent::dispatch(..., 'Lead', {content_name: 'Salon application', content_category: 'salon_inquiry'}, UserDataHasher::forFormSubmission(form_data), 'website', Request::fullUrl())`. Return event_id in JSON response so browser fires `fbq('track', 'Lead', ..., {eventID})`.
- [ ] **FUN-10**: CompleteRegistration — listener on `eloquent.created: Lovata\Buddies\Models\User` generates event_id + dispatches CAPI + queues a browser-side token that the next page render consumes for `fbq('track', 'CompleteRegistration', ..., {eventID})`. Replaces the v2-planned `lovata.buddies.user.after.register` hook (verified non-existent in Buddies via grep).
- [ ] **FUN-11**: Contact — click-to-call / WhatsApp / `mailto:` tracking via `fbq('trackCustom', 'Contact', ...)`. Client-side only (no CAPI twin — no server event, Meta accepts single-channel).
- [ ] **FUN-12**: Every `fbq('track', ...)` passes `event_time: Math.floor(Date.now()/1000)` in custom_data; the paired CAPI job uses the exact same timestamp. Meta dedup window is ±10 s — timestamp divergence breaks matching.
- [ ] **FUN-13**: `content_ids` format is always `SKU-{product_id}` (single-offer products) or `SKU-{product_id}-{offer_id}` (multi-offer). Helpers live in `classes/meta/ContentMapper.php` and reuse the format from `plugins/logingrupa/facebookcatalogshopaholic/classes/helper/ExportCatalogFacebookHelper.php:356` exactly. No alternative format ever emitted.
- [ ] **FUN-14**: `order_id` in custom_data = `$obOrder->order_number` (verified live format e.g. `260422-0002`).

### Maintenance / Cleanup (Phase 3.1-08, INSERTED 2026-05-14)

- [ ] **CLEAN-01**: Production-code dead/stale removal. `models/EventLog.php` docblock reflects REFAC-13 caller-supplied `?int $iSiteId` contract; `classes/helper/EventLogWriter::record` 7th param hardened to required (or `@deprecated` if audit surfaces >3 implicit-null callers); `classes/helper/SiteResolver.php` class-level PHPDoc caveman-compressed (~33 lines → ~8 lines, `@see` REFAC-04/REFAC-12 preserved). Tracks T1.1–T1.3 in `phases/03.1-08-dead-code-cleanup/BRIEF.md`.
- [ ] **CLEAN-02**: Test-suite DRY + lint cleanup. `tests/Feature/MultiSiteCrossContextTest.php` uses `use Ramsey\Uuid\Uuid;` (no inline FQN); hex literal `event_id` values in `SendCapiEventEventLogTest.php:237` + `PurchaseEndToEndIntegrationTest.php:340` replaced with `Uuid::uuid4()->toString()`; stale `$casts int` rationale comment in `PurchaseEndToEndIntegrationTest.php:482-487` removed; `tests/Feature/OrderStatusWatcherEventLogTest.php` has `declare(strict_types=1);`; `tests/Feature/SendCapiEventTest.php::setUp` docstring synced post-migration-merge (commit 08a0d12). Tracks T2.1–T2.5.
- [ ] **CLEAN-03**: Six pre-existing test failures diagnosed and fixed (NOT baselined unless scope-blocked): `EventLogTest::test_event_id_validation_rejects_longer_than_36`, `ExceptionHierarchyTest` translation namespace, `BootsWithoutPixelIdTest::test_isdisabled_returns_false_when_pixel_id_populated`, `EnsureFbpFbcCookiesTest` toggle-OFF guard, `PurchasePixelEventLogGateTest::test_onmarkfired_second_call_returns_ok_true_no_duplicate`, `SendCapiEventEventLogTest::test_second_concurrent_dispatch_returns_false_no_http_post`. After fixes: `composer test` exits 0. Scope-blocked items go to `tests/SKIP-BASELINE.md` + `->skip('baselined: <reason>')`. Tracks T3.1–T3.6.
- [ ] **CLEAN-04**: Planning-doc cleanup. `.planning/PLAN.md` + `.planning/PLAN-v2-original.md` annotated with `> **SUPERSEDED 2026-05-13**` block pointing to `.planning/phases/03.1-event-log-refactor/BRIEF.md`; `updates/.gitkeep` removed (directory non-empty); `composer.json` non-standard `_comments` key removed (verified by `composer validate --strict`). Tracks T4.1–T4.3.
- [ ] **CLEAN-05**: Milestone close. `composer qa` exits 0 (pint-test + analyse + phpmd + test all green; phpstan baseline regenerated if 2 pre-existing errors can't be narrow-fixed); plugin git tag `v1.1.1` created (NOT pushed without user confirm); `.planning/STATE.md` advances to `status: phase-3.1-milestone-ready`. Tracks T5.1–T5.3.

### Hardening / Launch (Phase 5)

- [ ] **HARD-01**: `controllers/FailedEvents.php` extends `Backend\Classes\Controller` with `Backend.Behaviors.ListController`. `controllers/failedevents/config_list.yaml` + `_list_toolbar.htm` render columns (event_id, event_name, http_status, attempts, created_at, graph_error snippet) with filters + search. Registered under Shopaholic backend menu.
- [ ] **HARD-02**: `FailedEvents::onReplay(): array` re-dispatches a selected FailedEvent through `MetaClient::replay($obFailed)`. Updates attempts counter. Flash-success on 200 OK; surfaces graph error on failure.
- [ ] **HARD-03**: `FailedEvents::onCheckDedup(): JsonResponse` queries Meta Test Events endpoint via `MetaClient::fetchTestEventsStatus()` and returns JSON with dedup % and EMQ per event for the current `test_event_code`.
- [ ] **HARD-04**: `lang/{en,lv,ru}/lang.php` populated with translations for Settings labels, FailedEvents columns, and any user-facing strings. RainLab.Translate compatible.
- [ ] **HARD-05**: `README.md` documents installation, Settings configuration, the dedup contract, all 5 resolved open questions (paid_status_code, content_ids format, lead form wiring, consent stance, dead-letter alerting v1 scope), plus a troubleshooting runbook keyed to `Log::*` context arrays.
- [ ] **HARD-06**: `pest --coverage --min=90` passes. Coverage includes every exception precondition in `PayloadBuilder`, the `OrderStatusWatcher` idempotency double-fire guard, `EnsureFbpFbcCookies` middleware cookie-setting behaviour, and `SendCapiEvent` retry + dead-letter branches. Mock Guzzle via `MockHandler`.
- [ ] **HARD-07**: `composer require logingrupa/oc-metapixel-plugin` works on a clean OctoberCMS 4.x + Shopaholic install (no hidden dependency on the nailscosmetics.lv repo).
- [ ] **HARD-08**: `tests/Integration/MetaTestEventsApiSmokeTest.php` runs only when `META_TEST_TOKEN` env var is present, using `test_event_code`. Documented in README as CI-optional.

## v2 Requirements (deferred)

### v1.1 ops integrations

- **OPS-01**: Settings dropdown for dead-letter alert sink (log-only / Slack webhook / email / Telegram).
- **OPS-02**: `MetaApiPermanentException` fan-out to the selected alert channel.
- **OPS-03**: Daily digest backend widget summarising dead-lettered events by event name + graph_error bucket.

### v1.2 catalogue alignment

- **CAT-01**: Integrate `Logingrupa.CampaignpricingShopaholic` pricing tiers into `ViewContent.content_price`.
- **CAT-02**: Cross-channel dedup audit job comparing Facebook Catalog feed ids vs observed Pixel content_ids.

### Upstream contributions

- **UPS-01**: Propose `shopaholic.cart.update` / `shopaholic.cart.remove` events to lovata/ordersshopaholic.
- **UPS-02**: Propose `lovata.buddies.user.after.register` event to lovata/buddies.
- **UPS-03**: Propose `shopaholic.favorite.element.after.add` event to lovata/wishlistshopaholic.
- **UPS-04**: Upstream model factories to lovata/shopaholic + lovata/ordersshopaholic to remove test-side duplication.

## Out of Scope (v1)

| Feature | Reason |
|---|---|
| GDPR / cookie-consent banner integration | Live site has no banner. Pixel fires unconditionally today; re-gate if stakeholder ships one. |
| External dead-letter alerting (Slack/email/Telegram) | No current ops channel. Deferred to v1.1 behind Settings dropdown. |
| `content_id_source` Settings dropdown | Forced to `SKU-{product_id}[-{offer_id}]` to match Facebook Catalog feed exporter. Any toggle = guaranteed product-match failures. |
| `strict_consent` / `consent_helper_class` Settings | No banner exists. Re-add if later gated. |
| Mutation testing (Infection) | Post-v1 quality bar. |
| Browser-side `event_id` generation | Violates contract. Server-only direction. |
| Custom Graph API endpoint version other than v20 | Pin v20; upgrade in a later milestone. |
| `declare(strict_types=1)` enforcement | Zero ecosystem usage; optional per-file. |
| Pricing-tier enrichment in `content_price` | Depends on CampaignpricingShopaholic maturity; v1.2. |

## Traceability

| Requirement | Phase | Status |
|---|---|---|
| TOOL-01 | Phase 1 | Pending |
| TOOL-02 | Phase 1 | Pending |
| TOOL-03 | Phase 1 | Pending |
| TOOL-04 | Phase 1 | Pending |
| TOOL-05 | Phase 1 | Pending |
| TOOL-06 | Phase 1 | Pending |
| TOOL-07 | Phase 1 | Pending |
| TOOL-08 | Phase 1 | Pending |
| SKEL-01 | Phase 2 | Plan 02-01 Complete (metadata-layer subset) |
| SKEL-02 | Phase 2 | Plan 02-01 Complete |
| SKEL-03 | Phase 2 | Plan 02-03 Complete |
| SKEL-04 | Phase 2 | Plan 02-04 Complete |
| SKEL-05 | Phase 2 | Plan 02-02 Complete |
| SKEL-06 | Phase 2 | Plan 02-01 Complete |
| PAY-01 | Phase 3 | Plan 03-03 Complete |
| PAY-02 | Phase 3 | Plan 03-05 Complete |
| PAY-03 | Phase 3 | Pending |
| PAY-04 | Phase 3 | Plan 03-01 Complete |
| PAY-05 | Phase 3 | Plan 03-01 Complete |
| PAY-06 | Phase 3 | Plan 03-04 Complete |
| PAY-07 | Phase 3 | Plan 03-04 Complete |
| PAY-08 | Phase 3 | Plan 03-04 Complete |
| PAY-09 | Phase 3 | Plan 03-02 Complete |
| PAY-10 | Phase 3 | Pending |
| PAY-11 | Phase 3 | Pending |
| CLEAN-01 | Phase 3.1-08 | Pending |
| CLEAN-02 | Phase 3.1-08 | Pending |
| CLEAN-03 | Phase 3.1-08 | Pending |
| CLEAN-04 | Phase 3.1-08 | Pending |
| CLEAN-05 | Phase 3.1-08 | Pending |
| FUN-01 | Phase 4 | Pending |
| FUN-02 | Phase 4 | Pending |
| FUN-03 | Phase 4 | Pending |
| FUN-04 | Phase 4 | Pending |
| FUN-05 | Phase 4 | Pending |
| FUN-06 | Phase 4 | Pending |
| FUN-07 | Phase 4 | Pending |
| FUN-08 | Phase 4 | Pending |
| FUN-09 | Phase 4 | Pending |
| FUN-10 | Phase 4 | Pending |
| FUN-11 | Phase 4 | Pending |
| FUN-12 | Phase 4 | Pending |
| FUN-13 | Phase 4 | Pending |
| FUN-14 | Phase 4 | Pending |
| HARD-01 | Phase 5 | Pending |
| HARD-02 | Phase 5 | Pending |
| HARD-03 | Phase 5 | Pending |
| HARD-04 | Phase 5 | Pending |
| HARD-05 | Phase 5 | Pending |
| HARD-06 | Phase 5 | Pending |
| HARD-07 | Phase 5 | Pending |
| HARD-08 | Phase 5 | Pending |

**Coverage:**
- v1 requirements: 50 total (45 + 5 CLEAN-* Phase 3.1-08 cleanup)
- Mapped to phases: 50
- Unmapped: 0 ✓

---
*Requirements defined: 2026-04-22*
*Last updated: 2026-04-22 after initial definition*
