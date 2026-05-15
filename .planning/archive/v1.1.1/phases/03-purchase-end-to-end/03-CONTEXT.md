# Phase 3: Purchase end-to-end - Context

**Gathered:** 2026-05-12
**Status:** Ready for planning

<domain>
## Phase Boundary

A paid order — including bank-transfer and admin-marked-paid orders previously invisible to Meta — fires exactly one deduplicated `Purchase` event via CAPI (Conversions API). Idempotency is enforced at the database level via a new `meta_purchase_event_id VARCHAR(36) NULL INDEX` column on `lovata_orders_shopaholic_orders`. Status flip-flops (paid → other → paid) never re-fire while the v1 default `refire_purchase_on_status_flip = off`.

Scope = PAY-01..PAY-11 = the eleven concrete classes/files:
`classes/meta/MetaClient.php`, `classes/meta/PayloadBuilder.php`, `classes/meta/UserDataHasher.php`,
`classes/queue/SendCapiEvent.php`,
`classes/event/OrderStatusWatcher.php`,
`classes/exception/{MetaPixelException, MissingPixelConfigException, MissingCapiTokenException, OrderHasNoCurrencyException, OrderHasNoItemsException, InvalidEventIdException, MetaApiTransientException, MetaApiPermanentException}.php`,
`updates/{N}_add_meta_purchase_event_id_to_orders_table.php`,
`updates/{N+1}_create_table_failed_events.php`,
`models/FailedEvent.php`,
plus Pest test coverage (PayloadBuilder preconditions, SendCapiEvent retry+dead-letter, OrderStatusWatcher idempotency double-fire guard) with Guzzle mocked via `MockHandler`.

Out of scope: All funnel components (`PageView`, `ViewContent`, `ViewCategory`, `Search`, `AddToCart`, `AddToWishlist`, `InitiateCheckout`, `AddPaymentInfo`, `Lead`, `CompleteRegistration`, `Contact`) → Phase 4 (FUN-01..FUN-14). Backend `FailedEvents` admin controller + `onReplay` + `onCheckDedup` → Phase 5 (HARD-01..HARD-03). Full translations → Phase 5 (HARD-04). README runbook + Composer marketplace listing → Phase 5 (HARD-05, HARD-07). `MetaTestEventsApiSmokeTest` env-gated integration → Phase 5 (HARD-08). Coverage gate `≥ 90 %` → Phase 5 (HARD-06).

</domain>

<decisions>
## Implementation Decisions

### Locked by REQUIREMENTS.md (v1.0.0 PAY-01..11)

- PAY-01: `classes/meta/MetaClient.php` wraps Guzzle (`guzzlehttp/guzzle ^7.8`), targets Graph API v20 `/events`, reads `pixel_id` / `capi_access_token` / `test_event_code` from Settings, retries 3× on transient (HTTP 429/5xx).
- PAY-02: `classes/queue/SendCapiEvent.php` queue job, retries 3× on `MetaApiTransientException`, dead-letters on `MetaApiPermanentException`.
- PAY-03: `classes/event/OrderStatusWatcher.php` fires on `Order::model.afterUpdate` (and `model.afterCreate` — see Area 2 Q2), generates UUIDv4, `saveQuietly` to `meta_purchase_event_id`, dispatches `SendCapiEvent`.
- PAY-04: `updates/add_meta_purchase_event_id_to_orders_table.php` adds `meta_purchase_event_id VARCHAR(36) NULL INDEX` to `lovata_orders_shopaholic_orders`. Reversible `down()`.
- PAY-05: `updates/create_table_failed_events.php` + `models/FailedEvent.php` (plain `October\Rain\Database\Model` + `Validation` trait).
- PAY-06: `classes/meta/PayloadBuilder::buildPurchaseEventPayload(Order, event_id): array` returns Graph API envelope.
- PAY-07: `classes/meta/UserDataHasher.php` builds hashed user_data, normalises phone via `phone_country_code` Setting.
- PAY-08: Guest `external_id` = `hash('sha256', $obOrder->secret_key)`.
- PAY-09: Exception hierarchy in `classes/exception/`.
- PAY-10: Meta Test Events shows dedup ≥ 80 % + EMQ ≥ 8 (manual staging verification).
- PAY-11: Bank-transfer / admin-marked-paid orders fire CAPI-only Purchase (no Pixel twin, accepted by Meta).

### Area 1 — MetaClient + Queue transport (PAY-01, PAY-02)

- Q1: HTTP client wiring — Constructor-injectable `ClientInterface $obClient = null` parameter on `MetaClient`. Default constructed via `new GuzzleHttp\Client(['base_uri' => 'https://graph.facebook.com/v20.0/', 'timeout' => 5])`. Tests bind `MockHandler` via `new Client(['handler' => HandlerStack::create($obMock)])` and pass into constructor. Allows full request/response control in Pest without `Http::fake()` global facade pollution.
- Q2: Retry backoff schedule — `SendCapiEvent::$backoff = [1, 4, 16]` (seconds, exponential). After 3 attempts on `MetaApiTransientException` → `FailedEvent::createFromPayloadAndException` and no rethrow (job marked succeeded so worker doesn't park).
- Q3: Queue job uniqueness — `SendCapiEvent` implements `ShouldQueue` only. The DB column `meta_purchase_event_id` is the canonical idempotency guard at the dispatch site (OrderStatusWatcher), not at the job level. No `ShouldBeUniqueUntilProcessing` dep, no extra Lovata.Toolbox import. Two equal payloads CANNOT be dispatched because the UUID generation in OrderStatusWatcher is fenced by `meta_purchase_event_id IS NULL`.
- Q4: Transient classification — Transient = HTTP 408 / 429 / 500 / 502 / 503 / 504 OR `GuzzleHttp\Exception\ConnectException` (network failure). Anything else (4xx except above, `RequestException` with non-network cause) → permanent → dead-letter. Encoded in `MetaClient::send()` switch on `$obException->getCode()`.

### Area 2 — OrderStatusWatcher + idempotency (PAY-03, PAY-04)

- Q1: Event subscription mechanism — `Event::subscribe(OrderStatusWatcher::class)` from `Plugin::boot()` (after PluginGuard prime, after CLI gate). Inside `OrderStatusWatcher::subscribe(Dispatcher $obEvents)` bind:
  - `$obEvents->listen('eloquent.updated: Lovata\OrdersShopaholic\Models\Order', [$this, 'handleUpdated']);`
  - `$obEvents->listen('eloquent.created: Lovata\OrdersShopaholic\Models\Order', [$this, 'handleCreated']);`
  This mirrors Lovata `ProductModelHandler`'s pattern (`plugins/lovata/shopaholic/classes/event/ProductModelHandler.php`). Typed `$obOrder: Order` argument; phpstan-friendly.
- Q2: Fire on `eloquent.created` too — Yes. Backend admin manually creating an order already at `new-payment-received` (imports, seeds, manual entry) must not be missed. Created-path is rare but symmetric; checkout-created orders default to a non-paid status so the typical Purchase path is still updated-only.
- Q3: `refire_purchase_on_status_flip` behaviour — Switch defaults OFF (per SKEL-02). Watcher consults `Settings::get('refire_purchase_on_status_flip', false)`:
  - OFF (v1 default): if `meta_purchase_event_id IS NULL` AND status now equals `paid_status_code` → fire. Column never clears. Status flip-flops never re-fire.
  - ON: on status-AWAY transition (`$obOrder->isDirty('status_id') && $obOrder->getOriginal('status_id')` was paid status, new is not) → clear column via `saveQuietly`. Next paid flip re-fires.
- Q4: UUID persistence write path — `$obOrder->meta_purchase_event_id = $sUuid; $obOrder->saveQuietly();`. October's `saveQuietly` suppresses model observers — critical because we are currently inside an `eloquent.updated` handler and a full `save()` would recurse infinitely. NOT `DB::table()->update(...)` (bypasses Eloquent casts / Multisite trait).

### Area 3 — PayloadBuilder + UserDataHasher + Exceptions (PAY-06, PAY-07, PAY-08, PAY-09)

- Q1: PayloadBuilder shape — Single `Logingrupa\Metapixelshopaholic\Classes\Meta\PayloadBuilder` class. Public methods: `buildPurchaseEventPayload(Order $obOrder, string $sEventId, int $iEventTime): array`. Phase 4 will add `buildViewContentPayload`, `buildAddToCartPayload`, etc. — same class. Envelope shape (`data[0] = {…}` wrapper) lives in private `wrapEnvelope(array $arEventBody): array` helper. UserDataHasher is constructor-injected (lazy default = `new UserDataHasher()`); allows tests to swap hash fakes.
- Q2: UserDataHasher cache key strategy — Hierarchy by source:
  - `$obOrder` present → `meta-pixel-user-hash:order:{$iOrderId}` (covers Phase 3 Purchase, Phase 4 InitiateCheckout / AddPaymentInfo).
  - Form submission with explicit form_data (Phase 4 Lead) → `meta-pixel-user-hash:lead:{$sRequestId}`.
  - Bare request (Phase 4 PageView / ViewContent / ViewCategory / Search) → `meta-pixel-user-hash:request:{$sRequestId}`.
  Cache via `Lovata\Toolbox\Classes\CCache::forget` / `::storeReturnedValue($arCacheTags, function() { … })` with tag `meta-pixel-user-hash`. TTL = 60 seconds (per-request lifetime fence; tag-purged in tearDown). Implementation: `CCache::get($arTags, $sKey, function() use (…) { return $this->compute(…); })`.
- Q3: Guest `external_id` source — `hash('sha256', mb_strtolower(trim((string)$obOrder->secret_key)))`. `secret_key` is the guest's existing guest-purchase-URL token, present on every Order row in `lovata_orders_shopaholic_orders` (column verified in `OrdersShopaholic` migrations). Stable per order. NEVER fall back to email-only (email may be missing on guest orders).
- Q4: Exception hierarchy file layout — One file per class in `classes/exception/`. Eight files:
  1. `MetaPixelException.php` — abstract base extends `\RuntimeException`. Stores `protected array $arContext = []` for `Log::error($sMsg, $obException->getContext())`.
  2. `MissingPixelConfigException` extends MetaPixelException — thrown event-time when `pixel_id` unset.
  3. `MissingCapiTokenException` extends MetaPixelException — thrown event-time when `capi_access_token` unset.
  4. `OrderHasNoCurrencyException` extends MetaPixelException — thrown by `PayloadBuilder` when `$obOrder->currency_code` null.
  5. `OrderHasNoItemsException` extends MetaPixelException — thrown by `PayloadBuilder` when `$obOrder->order_position->count() === 0`.
  6. `InvalidEventIdException` extends MetaPixelException — thrown by `PayloadBuilder` when `event_id` fails `Uuid::isValid()`.
  7. `MetaApiTransientException` extends MetaPixelException — retryable in queue.
  8. `MetaApiPermanentException` extends MetaPixelException — dead-letter path.
  phpstan namespace clean; PHPMD class-count happy. Concrete exception name encodes the precondition for grep-ability.

### Area 4 — FailedEvent model + tests (PAY-05, success criteria)

- Q1: `payload` column type — `LONGTEXT` via `$table->longText('payload')`. Admin reviews payload as raw JSON-encoded text. No v1 query-payload need (filter/search work on `event_id`, `event_name`, `http_status`).
- Q2: Index strategy — `event_id VARCHAR(36) INDEX`, `event_name VARCHAR(64) INDEX`, `http_status SMALLINT NULL INDEX`. Admin replay flow queries on `event_id` (single lookup), bulk views filter on `event_name` + `http_status`.
- Q3: Test fixtures — `tests/Support/OrderFixtures.php` (or `tests/Fixtures/OrderFixtures.php` per Phase 1 layout precedent): `OrderFixtures::makePaidOrder(): Order` creates Status `new-payment-received` if missing, builds one Offer + Product + OrderPosition, sets `secret_key`, returns persisted `Order`. Real DB via PHPUnit SQLite-in-memory + `runOctoberUpCommand()` migrations — Phase 1 pattern, already wired in `tests/MetapixelTestCase.php`. NO Mockery for Order/OrderPosition (would mask migration issues against `lovata_orders_shopaholic_orders`).
- Q4: Real-Meta-API smoke test scope — Phase 3 ships PAY-10 + PAY-11 acceptance via **manual staging verification** documented in 03-SUMMARY.md (real PayPal order on staging with `test_event_code` set; bank-transfer admin-flip verification). The automated `tests/Integration/MetaTestEventsApiSmokeTest.php` (env-gated `META_TEST_TOKEN`) lands in Phase 5 HARD-08. Phase 3 mocks Guzzle via `GuzzleHttp\Handler\MockHandler` for all unit/feature tests.

### Claude's Discretion

- Migration filenames — exact numeric prefix ordering relative to existing Lovata.OrdersShopaholic migrations.
- `MetaClient` exposed public method names beyond `send(array $arPayload): array` (e.g. `sendPurchase(array $arPayload)` shortcut vs. only the generic `send`).
- `SendCapiEvent` constructor signature shape — DTO object vs flat positional args (`event_id`, `event_time`, `event_name`, `custom_data`, `user_data`, `action_source`, `event_source_url`).
- `OrderStatusWatcher` extraction of `paid_status_code` lookup — inline `Settings::get(...)` vs cached static.
- Internal helper method names (`buildEventBody`, `wrapEnvelope`, `getHashedUserData`).
- `FailedEvent::createFromPayloadAndException(array, MetaPixelException): self` exact signature.
- Whether `MetaClient::send` returns the full Graph response array or a typed DTO.
- Pest dataset structure for `PayloadBuilder` precondition tests (one dataset row per exception type vs separate test methods).
- Logger context key names (`meta_pixel.order_id`, `meta_pixel.event_id`, …).
- Phone normalisation library choice — raw regex vs `libphonenumber/libphonenumber`. (Lean: raw regex strip + prepend `phone_country_code` Setting if missing leading country code.)

</decisions>

<code_context>
## Existing Code Insights

### Reusable Assets

- **`Logingrupa\Metapixelshopaholic\Classes\Helper\PluginGuard`** (Phase 2) — `PluginGuard::instance()->isDisabled(): bool` + container singleton `App::make('metapixel.disabled')`. Every Phase 3 event handler MUST start with `if (App::make('metapixel.disabled')) { return; }`.
- **`Logingrupa\Metapixelshopaholic\Models\Settings`** (Phase 2) — reads `pixel_id`, `capi_access_token`, `test_event_code`, `currency_code`, `phone_country_code`, `paid_status_code`, `refire_purchase_on_status_flip`, `queue_connection` via `Settings::get('key', $default)`.
- **`Lovata\Toolbox\Classes\CCache`** — tag-based cache primitive (`CCache::storeReturnedValue($arTags, $callback)`); used by every Lovata Store. Reusable in `UserDataHasher` for per-request hash cache.
- **`Lovata\OrdersShopaholic\Models\Order`** — has `secret_key`, `email`, `phone`, `name`, `last_name`, `order_number`, `status_id`, `total_price_value`, `currency`, `order_position` HasMany. The `meta_purchase_event_id` column will be added by `updates/add_meta_purchase_event_id_to_orders_table.php` (PAY-04).
- **`Lovata\OrdersShopaholic\Models\Status`** — `lists('name', 'code')` already wired in Settings dropdown via Phase 2 SKEL-02. `code = 'new-payment-received'` is the paid-status default.
- **`Lovata\OrdersShopaholic\Models\OrderPosition`** — `offer_id`, `product_id`, `quantity`, `price_value`, `currency`. PayloadBuilder iterates to build `custom_data.contents[]`.
- **`Lovata\Shopaholic\Models\Offer`** + **`Product`** — `product_id` resolution for `content_ids = 'SKU-{product_id}[-{offer_id}]'` format. Single-offer products → `SKU-{product_id}`; multi-offer → `SKU-{product_id}-{offer_id}`. Format mirrors `plugins/logingrupa/facebookcatalogshopaholic/classes/helper/ExportCatalogFacebookHelper.php:356`.
- **`Lovata\Toolbox\Classes\Helper\UserHelper`** — abstracts `Lovata.Buddies` vs `RainLab.User` for resolving the current user during checkout (Phase 4 cases — Phase 3 only cares about Order's denormalised email/phone fields).
- **`Ramsey\Uuid\Uuid::uuid4()->toString()`** — UUIDv4 generator declared in `composer.json` (`ramsey/uuid ^4.7`).
- **`GuzzleHttp\Client` + `GuzzleHttp\Handler\MockHandler`** — Guzzle declared in `composer.json` (`guzzlehttp/guzzle ^7.8`). Mock pattern reusable in Pest tests.
- **`tests/MetapixelTestCase.php`** (Phase 1) — extends `System\Tests\Bootstrap\TestCase`, runs OctoberCMS migrations via `runOctoberUpCommand()`. Phase 3 tests extend this — SQLite-in-memory boots a real `lovata_orders_shopaholic_orders` table.

### Established Patterns

- **Hungarian notation** mandatory — `$obOrder`, `$obClient`, `$sEventId`, `$iEventTime`, `$arPayload`, `$bIsTransient`, `$fTotalValue`.
- **Event handler subscribe pattern** — Lovata `ProductModelHandler` precedent (`plugins/lovata/shopaholic/classes/event/ProductModelHandler.php`). Methods `getModelClass(): string`, `getItemClass(): string`, `subscribe(Dispatcher $obEvents): void`. Inside subscribe: `$obEvents->listen('eloquent.created: ' . self::getModelClass(), [$this, 'handleCreated']);`.
- **`saveQuietly`** — October's no-event save for inside-handler mutations. Prevents infinite recursion.
- **Custom exception inheritance** — Lovata exceptions extend `\RuntimeException`. Add `getContext(): array` for `Log::error($sMsg, $obException->getContext())`.
- **Queue job pattern** — Laravel 12 standard. `implements ShouldQueue`, `use Queueable, InteractsWithQueue, SerializesModels`. `public int $tries = 3`. `public array $backoff = [1, 4, 16]`. `handle(MetaClient $obClient): void`. Container resolves `MetaClient` via type-hint.
- **PluginGuard short-circuit** — `if (App::make('metapixel.disabled')) { return; }` at handler entry. Documented contract from Phase 2.
- **Migration file naming** — Lovata snake_case: `add_meta_purchase_event_id_to_orders_table.php`, `create_table_failed_events.php`. Class name PascalCase mirror: `AddMetaPurchaseEventIdToOrdersTable`, `CreateTableFailedEvents`.
- **`composer qa`** chain: `pint-test` → `analyse` (phpstan level 10) → `phpmd` → `test-cov` (Pest with coverage). Must stay green.
- **phpstan level 10** — every method declared return type; every parameter typed; no `mixed` leakage; `disallowedFunctionCalls` bans `assert()` (spaze extension).
- **Fail-fast Tiger-Style** — throw at function boundaries; every `catch` either rethrows after logging OR has explicit reason comment; preconditions throw early.

### Integration Points

- **`Plugin::boot()` (Plugin.php)** — append `Event::subscribe(OrderStatusWatcher::class);` after PluginGuard prime + CLI gate + middleware push. The handler is registered globally (storefront + backend + queue worker — backend admin status-flip is the bank-transfer path).
- **`Plugin.php::registerComponents`** — no Phase 3 changes (no new components; PixelHead extended in Phase 4).
- **`composer.json`** — already lists `guzzlehttp/guzzle ^7.8` + `ramsey/uuid ^4.7` (Phase 1 TOOL-01). No further require additions in Phase 3.
- **Database migration ordering** — `updates/add_meta_purchase_event_id_to_orders_table.php` runs AFTER `lovata/ordersshopaholic`'s own migrations because plugin load order respects `$require`. Verified safe.
- **Settings UI** — no field additions in Phase 3. All 10 fields already shipped by Phase 2 SKEL-02. Watcher reads `paid_status_code` and `refire_purchase_on_status_flip` from existing Settings.
- **Backend FailedEvents list** — Phase 3 SHIPS only the model + table + dead-letter writes. The admin controller (`controllers/FailedEvents.php` + `controllers/failedevents/config_list.yaml`) is Phase 5 HARD-01.

</code_context>

<specifics>
## Specific Ideas

- **Graph API endpoint pin** — `https://graph.facebook.com/v20.0/{pixel_id}/events`. Version `v20.0` is OUT-OF-SCOPE-listed in PROJECT.md ("Custom Graph API endpoint version other than v20"). Codified as `MetaClient::GRAPH_VERSION = 'v20.0'` constant.
- **`event_time` contract (FUN-12 prerequisite)** — Even though FUN-12 lands in Phase 4, OrderStatusWatcher already needs an integer Unix seconds timestamp. `$iEventTime = time();` — single source generated at dispatch site, passed into both `SendCapiEvent` AND eventually into the Twig-rendered Pixel call. Server-only direction; never recomputed client-side.
- **Bank-transfer / admin-marked-paid edge case (PAY-11 + Success Criterion 2)** — When admin flips an order's status from "bank-transfer-pending" to `new-payment-received` in the backend, no browser session exists. `OrderStatusWatcher::handleUpdated` runs in the backend request lifecycle, dispatches CAPI ONLY (no Pixel browser twin). Meta accepts the single-channel event because `event_id` is unique. Dedup is NOT broken — there is nothing to dedup against.
- **Status flip-flop guard (Success Criterion 3)** — DB-level: `meta_purchase_event_id IS NOT NULL` is the fence. Watcher entry-test:
  ```php
  if (App::make('metapixel.disabled')) return;
  if ($obOrder->status->code !== Settings::get('paid_status_code', 'new-payment-received')) return;
  if ($obOrder->meta_purchase_event_id !== null) return; // already fired
  ```
- **Anonymous external_id derivation (PAY-08)** — `hash('sha256', mb_strtolower(trim((string)$obOrder->secret_key)))`. `secret_key` is generated by Lovata.OrdersShopaholic on every Order create (`/lovata/ordersshopaholic/classes/processor/OrderProcessor.php`); never null on a persisted order.
- **Phone normalisation** — `UserDataHasher::normalisePhone(string $sPhone): string` — strip non-digits, prepend `phone_country_code` Setting (default `371` for Latvia) if missing leading country code, return hashed. No `libphonenumber` dep; regex sufficient for v1 (multi-site: `.no` operator must set `phone_country_code = '47'`, `.lt` = `'370'`).
- **Currency handling** — `PayloadBuilder` reads `$obOrder->currency->code` (NOT `$obOrder->currency_id`). Multi-site: `.no` = `NOK`, `.lv` / `.lt` = `EUR`. Currency_code Setting default `EUR` is a global fallback ONLY if Order.currency relation is null (should never happen on a real order).
- **Order_id contract (FUN-14 prerequisite)** — Meta's `order_id` custom_data field = `$obOrder->order_number` (NOT `$obOrder->id`). Live format e.g. `260422-0002`. Codified in Phase 3 PayloadBuilder.
- **Content_ids precision** — `'SKU-' . $obOffer->product_id . ($iOfferCount > 1 ? '-' . $obOffer->id : '')`. Single-offer products skip the offer suffix. Format MUST match `plugins/logingrupa/facebookcatalogshopaholic/classes/helper/ExportCatalogFacebookHelper.php:356` byte-for-byte; mismatch = Meta cannot reconcile product feed ids.
- **Manual staging verification protocol (PAY-10, PAY-11)** — Documented in 03-SUMMARY.md after execution: (1) Set `test_event_code` in Settings on staging. (2) Place a real PayPal order on staging through to `new-payment-received`. (3) Open Meta Events Manager → Test Events; verify Pixel + CAPI dedup pair appears; record dedup % and EMQ in SUMMARY. (4) Manually flip a bank-transfer order to `new-payment-received` via backend; verify single CAPI event lands. (5) Flip same order away + back; verify NO re-fire (`meta_purchase_event_id` populated).
- **`OrderStatusWatcher` test isolation** — Pest test `tests/Feature/OrderStatusWatcherTest.php` boots SQLite, runs `lovata_orders_shopaholic_orders` migration + Phase 3 migration. Asserts: (a) fresh paid order fires Job → dispatched once; (b) updating same order again with same paid status fires zero jobs; (c) `refire_purchase_on_status_flip = true` + status-away + back fires twice; (d) `refire_purchase_on_status_flip = false` + status-away + back fires once; (e) plugin disabled (no pixel_id) fires zero jobs.
- **`SendCapiEvent` retry tests** — Mock Guzzle returns 503 three times then 200 → job succeeds with 4 attempts visible in `$obJob->attempts()`. Mock returns 503 four times → `FailedEvent` row written, job marked done (no infinite retry).

</specifics>

<deferred>
## Deferred Ideas

- **PageView CAPI twin (FUN-01)** → Phase 4. Phase 2 PixelHead component fires client-side only.
- **Funnel event components** (`ViewContent`, `ViewCategory`, `Search`, `AddToCart`, `AddToWishlist`, `InitiateCheckout`, `AddPaymentInfo`, `Lead`, `CompleteRegistration`, `Contact`) → Phase 4 FUN-02..FUN-11.
- **`FailedEvents` backend admin list + onReplay + onCheckDedup** → Phase 5 HARD-01..HARD-03.
- **Full translations** (en/lv/ru content beyond Phase 2 stubs) → Phase 5 HARD-04.
- **README runbook + Composer marketplace listing** → Phase 5 HARD-05, HARD-07.
- **Automated live-Meta-API smoke test** (`MetaTestEventsApiSmokeTest`, env-gated `META_TEST_TOKEN`) → Phase 5 HARD-08.
- **Coverage gate ≥ 90 %** → Phase 5 HARD-06. Phase 3 targets ≥ 80 % on PAY-* classes (Phase 2 precedent of 88.1 % overall holds the line).
- **External dead-letter alerting** (Slack/email/Telegram fan-out from `MetaApiPermanentException`) → v1.1 OPS-01..03.
- **`libphonenumber` upgrade** for phone normalisation → post-v1.
- **Cross-channel dedup audit job** (Facebook Catalog feed ids vs observed Pixel content_ids) → v1.2 CAT-02.
- **Pricing-tier integration** (`Logingrupa.CampaignpricingShopaholic` in `content_price`) → v1.2 CAT-01.

</deferred>
