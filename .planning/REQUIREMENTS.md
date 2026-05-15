# Requirements: Logingrupa.Metapixel v2.0.0

**Defined:** 2026-05-15
**Milestone:** v2.0.0 — Generic-event-tracking marketplace plugin
**Core Value:** Any OctoberCMS operator can install Logingrupa.Metapixel from Composer, configure pixel_id + capi_access_token + trusted_hosts via backend Settings, and ship Meta Pixel + Conversions API tracking for their cart-plugin (Shopaholic, Mall, Theme, or custom) in under 10 minutes. Server-deduplicated event_id contract preserved; multi-site per-pixel routing supported; third parties extend with custom adapters without forking.

## v2 Requirements

### Tooling (Phase 1 — composer.json + namespace rename + CI)

- [ ] **TOOL-01**: `composer.json` declares `"name": "logingrupa/oc-metapixel-plugin"`, `"php": "^8.3 || ^8.4"`, generic description (no "shopaholic" or vendor-specific terms). `lovata/shopaholic-plugin` + `lovata/ordersshopaholic-plugin` + `lovata/buddies-plugin` move from `require:` to `suggest:`. Stay in `require-dev:` so test suite exercises ShopaholicAdapter. PSR-4 autoload uses `Logingrupa\\Metapixel\\` namespace.
- [ ] **TOOL-02**: Plugin directory renamed from `plugins/logingrupa/metapixelshopaholic/` to `plugins/logingrupa/metapixel/`. PSR-4 + October PluginManager identifier `Logingrupa.Metapixel`. v1.x source on `legacy/v1.1.1` branch; master tree contains only v2.0 source.
- [ ] **TOOL-03**: Namespace rename `Logingrupa\Metapixelshopaholic` → `Logingrupa\Metapixel` across all source. Lang keys `logingrupa.metapixelshopaholic::lang.*` → `logingrupa.metapixel::lang.*`.
- [ ] **TOOL-04**: `phpstan.neon` config: `phpVersion: 80300` (PHP 8.3 baseline), level 10, larastan, spaze/phpstan-disallowed-calls bans `assert()`, `@` suppression, `array_find()`, `array_any()`, `array_all()`, `array_find_key()`, property hooks, asymmetric visibility, `#[\Deprecated]`. `universalObjectCratesClasses` covers `Lovata\Toolbox\Classes\Item\ElementItem` + `ElementCollection`. `reportUnmatchedIgnoredErrors: true`, `treatPhpDocTypesAsCertain: true`, `checkUninitializedProperties: true`.
- [ ] **TOOL-05**: `rector.php` config: `LevelSetList::UP_TO_PHP_83` (NOT 84 — caps upgrade rewrites at 8.3-safe), `SetList::CODE_QUALITY`, `SetList::DEAD_CODE`, `SetList::EARLY_RETURN`, `SetList::TYPE_DECLARATION`.
- [ ] **TOOL-06**: `pint.json` Laravel preset + `nullable_type_declaration_for_default_null_value` (implicit nullable deprecation since PHP 8.4), `ordered_imports: alpha`, `no_unused_imports`, `single_quote`, `binary_operator_spaces: single_space`, `exclude: [updates]`.
- [ ] **TOOL-07**: `phpmd.xml` copied from Lovata.Toolbox PHPMD_custom.xml. `LongVariable max=40`, `ShortVariable min=4` (allows `$ob`, `$ar`, `$iN`), CyclomaticComplexity reportLevel=10, ExcessiveClassLength minimum=1000.
- [ ] **TOOL-08**: Pest 4 scaffold — three-tier test base:
  - `tests/MetapixelTestCase.php` — base, no cart-plugin dependencies, runs Run B (minimal install) scenarios
  - `tests/ShopaholicAdapterTestCase.php` — extends MetapixelTestCase, boots Lovata Orders table (Run A)
  - `tests/ThemeActionAdapterTestCase.php` — extends MetapixelTestCase, no Order dep
- [ ] **TOOL-09**: `.github/workflows/metapixel-qa.yml` runs CI matrix: `php: [8.3, 8.4]` × `install: [full-lovata, minimal]`. Full-Lovata Run A: install Lovata.Toolbox + Lovata.Shopaholic + Lovata.OrdersShopaholic, run all tests, coverage ≥90% gate. Minimal Run B: install only Lovata.Toolbox, run `MetapixelTestCase` + `ThemeActionAdapterTestCase` subsets, no coverage gate (separate baseline).
- [ ] **TOOL-10**: `composer qa` script chains `pint-test` → `analyse` → `phpmd` → `test-cov`. Exits 0 on fresh clone (both Run A and Run B branches).
- [ ] **TOOL-11**: `shipmonk/composer-dependency-analyser` dev dependency + `composer-dependency-analyser.php` config enforces no Lovata.OrdersShopaholic / Lovata.Shopaholic imports outside `Classes\Adapter\Shopaholic\` directory.

### Adapter system core (Phase 2 — contracts + registry + extension hooks)

- [ ] **ADAP-01**: `Classes\Adapter\EventSubjectAdapter` interface defines: `getSubjectType(object $obSubject): string` (opaque alias, NOT class FQN — e.g. `'shopaholic.order'`), `getSubjectId(object $obSubject): int`, `getSiteId(object $obSubject): ?int` (MUST read from subject, never request context), `getSecretKey(object $obSubject): ?string`, `getValueResolver(object $obSubject): ValueResolver`, `getUserData(object $obSubject): array<string, ?string>`, `getSupportedEvents(): array<string, list<string>>`. Class-level PHPDoc documents the cross-context-determinism contract.
- [ ] **ADAP-02**: `Classes\Adapter\ValueResolver` interface defines: `resolveContentIds(object $obSubject): list<string>`, `resolveValue(object $obSubject): float`, `resolveCurrency(object $obSubject): string`, `resolveContents(object $obSubject): list<array{id: string, quantity: int, item_price: float}>`, `resolveNumItems(object $obSubject): int`.
- [ ] **ADAP-03**: `Classes\Adapter\AdapterRegistry` service-container singleton (`App::singleton(AdapterRegistry::class, ...)`). Methods: `register(string $sSubjectClass, string $sAdapterClass): void` (throws `InvalidArgumentException` if adapter class doesn't implement `EventSubjectAdapter`), `resolveFor(object $obSubject): ?EventSubjectAdapter` (lazy `App::make`, walks class hierarchy via `is_a()`, returns null on miss), `resolveByClass(string $sAdapterClass): EventSubjectAdapter` (for queue rehydrate).
- [ ] **ADAP-04**: 8 `Event::fire` extension points wired at decision boundaries with documented payload contracts:
  - `metapixel.adapter.resolve` — `[$obSubject, &$obAdapter]`
  - `metapixel.value.resolve` — `[$obSubject, $sEventName, &$arValues]`
  - `metapixel.user_data.resolve` — `[$obSubject, &$arRawUserData]`
  - `metapixel.event.before_dispatch` — `[$sEventName, &$arPayload, $obSubject]` (return false halts)
  - `metapixel.event.after_dispatch` — `[$sEventName, $arPayload, $obSubject, $arResponse]`
  - `metapixel.event.dead_letter` — `[$sEventName, $arPayload, $obSubject, $obException]`
  - `metapixel.pixel.before_render` — `[$sEventName, &$bShouldRender, $obSubject]`
  - `metapixel.settings.lookup` — `[$iSiteId, &$arResolved]`
- [ ] **ADAP-05**: Static reentrancy guard prevents `metapixel.value.resolve` → `metapixel.settings.lookup` infinite recursion. Listener exceptions caught + `Log::warning` + continue (never propagate to dispatch).
- [ ] **ADAP-06**: `Classes\Helper\SiteResolver::forSubject(object $obSubject, EventSubjectAdapter $obAdapter): ?int` replaces `forOrder(Order)`. PHPStan disallowed-calls bans `SiteManager::*` / `request()` / `Request::*` inside `Classes\Queue\`, `Classes\Event\`, `Classes\Adapter\` directories — enforces cross-context determinism (P-01 prevention).
- [ ] **ADAP-07**: `Classes\Meta\PayloadBuilder::buildEventPayload(string $sEventName, EventSubjectAdapter $obAdapter, object $obSubject, ValueResolver $obResolver, string $sEventId, int $iEventTime, array $arEventExtras): array` replaces `buildPurchaseEventPayload(Order, ...)`. All Order-specific logic moves to ShopaholicAdapter.
- [ ] **ADAP-08**: `Classes\Meta\UserDataHasher::forSubject(EventSubjectAdapter $obAdapter, object $obSubject): array` replaces `forOrder(Order)`. Adapter provides raw fields; Hasher does only sha256 + per-request CCache.
- [ ] **ADAP-09**: `Classes\Meta\MetaClient::sendForPixel(string $sPixelId, string $sToken, array $arPayload): array` replaces singleton-reading `send(array)`. Graph API version pinned to `v23.0` (constant `META_GRAPH_API_VERSION = 'v23.0'`, no operator override — v20 expires 2026-09-24).
- [ ] **ADAP-10**: `Classes\Queue\SendCapiEvent` constructor adds `string $sAdapterClass` 4th arg. `handle()` resolves adapter via `AdapterRegistry::resolveByClass($sAdapterClass)`. `BindingResolutionException` boundary catch writes FailedEvent + log critical (adapter plugin uninstalled between dispatch + worker pickup).
- [ ] **ADAP-11**: All 177 v1.x tests adapt via `FakeAdapter` test double. `OrderStatusWatcherEventLogTest`, `PurchasePixelEventLogGateTest`, `SendCapiEventEventLogTest`, `MultiSiteEventLogTest` regreen.

### ShopaholicAdapter (Phase 3 — non-regression port)

- [ ] **SHOP-01**: `Classes\Adapter\Shopaholic\ShopaholicOrderAdapter` implements `EventSubjectAdapter`. `getSubjectType()` returns `'shopaholic.order'` (alias, NOT `\Lovata\OrdersShopaholic\Models\Order::class`). `getSiteId()` reads `$obOrder->getAttribute('site_id')` (Lovata column).
- [ ] **SHOP-02**: `Classes\Adapter\Shopaholic\ShopaholicOrderValueResolver` implements `ValueResolver`. `resolveContentIds()` matches Facebook Catalog feed exporter format `SKU-{product_id}[-{offer_id}]`. 4-step currency fallback (relation → field → Settings → throw).
- [ ] **SHOP-03**: `Classes\Event\Adapter\Shopaholic\OrderStatusWatcher` moved from `Classes\Event\OrderStatusWatcher`. Watches `eloquent.updated` + `eloquent.created` on Order. On `paid_status_code` match + EventLog row absent, dispatches `SendCapiEvent::dispatch('Purchase', $arPayload, $obOrder, ShopaholicOrderAdapter::class)`.
- [ ] **SHOP-04**: `Plugin::boot()` conditionally registers ShopaholicOrderAdapter + subscribes OrderStatusWatcher only when `PluginManager::instance()->exists('Lovata.OrdersShopaholic')` is true. Composer-dependency-analyser enforces no Lovata imports outside `Classes\Adapter\Shopaholic\` dir.
- [ ] **SHOP-05**: ShopaholicAdapter non-regression test suite — identical Pixel + CAPI traffic to v1.1.1 on nailscosmetics.* fixture data. Same event_id round-trip, same EventLog rows, same Meta Test Events events.

### ThemeActionAdapter (Phase 3 — generic theme tracking)

- [ ] **THEM-01**: `Classes\Adapter\Theme\ThemeActionEvent` value object: `sActionKey` (e.g. `'product-view:42'`), `iSyntheticId` (hash of action_key for event_log subject_id), `sEventName`, `arPayload`.
- [ ] **THEM-02**: `Classes\Adapter\Theme\ThemeActionAdapter` implements `EventSubjectAdapter`. `getSiteId()` reads from `ThemeActionEvent->arPayload['site_id']` (operator-supplied) OR falls back to `Site::getCurrent()?->getId()` (only place where request-context site fallback is allowed — documented in PHPDoc).
- [ ] **THEM-03**: `Classes\Adapter\Theme\ThemeEventCollector` request-scoped singleton. Accumulates events pushed via Twig API. Reset between requests (PHP-FPM static reset; test teardown calls explicit `flush()`).
- [ ] **THEM-04**: `Plugin::registerMarkupTags()` registers `this.metapixel.pushEvent(arEvent)` Twig helper. Theme layouts call `{% do this.metapixel.pushEvent({name: 'ViewContent', action_key: 'product-view:' ~ product.id, content_ids: [...], value: 12.50, currency: 'EUR'}) %}` before PixelHead renders.
- [ ] **THEM-05**: `Classes\Adapter\Theme\ThemeAjaxHandler` listens on `cms.ajax.beforeRunHandler` for `Metapixel::onFireEvent`. Validates against `EVENT_NAME_ALLOWLIST` (Meta-standard event names + operator-configured custom events), enforces CSRF token, rate-limits per IP+session, JS-escapes returned payload. Dispatches `SendCapiEvent` + emits `<script>fbq(...)</script>` response fragment.
- [ ] **THEM-06**: `Components\SubjectPixel` (generalizes v1.x `PurchasePixel`). Properties: `subject_class`, `subject_slug_field`. Resolves adapter via `AdapterRegistry::resolveByClass($sSubjectClass)`. `onMarkFired` AJAX handler writes `channel='pixel'` row to EventLog with event_id validation.
- [ ] **THEM-07**: `Components\PixelHead` extended with Twig API surface — reads `ThemeEventCollector` accumulator, emits `fbq('track', ...)` script blocks per pushed event. Optional `also_dispatch_capi: true` triggers CAPI mirror.

### Multisite + Settings rework (Phase 4)

- [ ] **MULT-01**: `models/Settings.php` adds `use Multisite;` trait. `protected $propagatable = []` (empty whitelist — explicit per-field opt-in, P-10 prevention). Lint check ensures `$propagatable` never sprouts inadvertently.
- [ ] **MULT-02**: `pixel_id` + `capi_access_token` fields marked per-site via Multisite trait (not in `$propagatable`). Each OctoberCMS site row stores independent value.
- [ ] **MULT-03**: `Settings::lookupForSite(?int $iSiteId): array{pixel_id: string, capi_access_token: string}` helper. Reads per-site row via Multisite trait when `$iSiteId !== null`; falls back to default row on null. Fires `metapixel.settings.lookup` event for last-mile override.
- [ ] **MULT-04**: `SendCapiEvent::handle()` resolves pixel+token via `Settings::lookupForSite($iSiteId)` where `$iSiteId = $obAdapter->getSiteId($obSubject)`. `MetaClient::sendForPixel($sPixelId, $sToken, $arPayload)` carries per-site credentials.
- [ ] **MULT-05**: Multi-pixel routing integration test: 2 OctoberCMS sites × 2 adapters × 2 channels = 8-path matrix. Site A Order fires to pixel_A; Site B Order fires to pixel_B; cross-site EventLog rows are independent (UNIQUE NULL-distinct semantics).
- [ ] **MULT-06**: `updates/add_multisite_pixel_id_and_token.php` migration. Idempotent. Single-site installs see no behavior change (default row remains primary).

### TrustedHosts + php-domain-parser (Phase 4)

- [ ] **HOST-01**: `trusted_hosts` Settings field added (textarea, one host per line). Empty default; operator populates with own production domains. Validates host syntax on save.
- [ ] **HOST-02**: `Classes\Helper\HostIndexResolver` wraps `jeremykendall/php-domain-parser ^6.4`. Derives subdomain index from `Request::getHost()` using PSL (Public Suffix List). Handles multi-part TLDs correctly (`.co.uk`, `.com.au`, `.com.br`). Returns 1 for apex, 2 for `www.` subdomain, etc.
- [ ] **HOST-03**: PSL data shipped at `resources/data/public_suffix_list.dat`. `metapixel:refresh-psl` artisan command updates PSL from upstream. Cache parsed `Rules` in `storage/app/metapixel/psl/` via PSR-16.
- [ ] **HOST-04**: `EnsureFbpFbcCookies` middleware retired HOST_INDEX_MAP. Reads `trusted_hosts` Setting + `HostIndexResolver`. Untrusted host → middleware skips cookie-set (fail-safe). CR-02 host-spoofing threat preserved.
- [ ] **HOST-05**: Multi-TLD test matrix — fixtures for apex `example.test`, `www.example.test`, `example.co.uk`, `www.example.co.uk`, `subdomain.example.com.br`, IDN host `xn--bcher-kva.example`. All resolve correctly via PSL.
- [ ] **HOST-06**: Untrusted host fail-safe test — host not in `trusted_hosts` → middleware NO-OP, returns next response unchanged, no cookies set, no exception.

### Cookie middleware carry-forward (Phase 4 — preserves v1.x CR-02/CR-03 contracts)

- [ ] **COOK-01**: `EnsureFbpFbcCookies` keeps `Settings::get('ensure_fbp_fbc_server_side', true)` kill switch — operator toggle disables middleware entirely.
- [ ] **COOK-02**: CR-03 fbclid validation preserved — `[A-Za-z0-9_-]` charset, ≤255 chars, invalid → skip `_fbc` (fail-safe).
- [ ] **COOK-03**: `Cache-Control: private` header documented as operator responsibility in README — middleware does not auto-set (would mutate non-storefront routes). Class-level PHPDoc references README section.

### FailedEvents backend audit (Phase 4)

- [ ] **FAIL-01**: `Controllers\FailedEvents` extends `Backend\Classes\Controller` with `Backend.Behaviors.ListController`. `controllers/failedevents/config_list.yaml` renders columns: event_id, event_name, adapter_type, http_status, attempts, created_at, graph_error snippet. Filters by event_name + adapter_type + date range.
- [ ] **FAIL-02**: `FailedEvents::onReplay(): array` re-dispatches selected FailedEvent through `MetaClient`. Updates attempts counter. Flash-success on 200 OK; surfaces graph_error on failure.
- [ ] **FAIL-03**: `FailedEvents::onCheckDedup(): JsonResponse` queries Meta Test Events endpoint via `MetaClient::fetchTestEventsStatus()`. Returns JSON with dedup % + EMQ per event for current `test_event_code`.

### Translations (Phase 4)

- [ ] **LANG-01**: `lang/{en,lv}/lang.php` files populated. Cover: Settings field labels + commentAbove, FailedEvents column labels + buttons (Replay, CheckDedup), backend menu label, error messages. RainLab.Translate-compatible structure.
- [ ] **LANG-02**: Fallback shim — `logingrupa.metapixelshopaholic::lang.*` keys resolve to `logingrupa.metapixel::lang.*` equivalents during v1→v2 upgrade window. Prevents raw lang-key rendering for operators mid-migration.

### Documentation (Phase 5)

- [ ] **DOCS-01**: `README.md` install guide walks buyer from `composer require logingrupa/oc-metapixel-plugin` → Settings configuration → first CAPI event verified in Meta Test Events in under 10 minutes. Timed dry-run as launch acceptance gate.
- [ ] **DOCS-02**: `README.md` includes: Settings field walkthrough, per-adapter setup (Shopaholic + Mall + Theme), Pixel + CAPI token acquisition steps (with Meta UI screenshots), `.env` variable reference, troubleshooting runbook keyed to `Log::*` context arrays, multi-site routing setup, custom-adapter authoring quick-start.
- [ ] **DOCS-03**: `docs/CUSTOM-ADAPTERS.md` — full custom-adapter authoring guide with working `AcmeCartAdapter` + `AcmeCartValueResolver` example (~50 LOC). Documents AdapterRegistry::register pattern, $require dependency declaration, Event::fire hooks for value overrides.
- [ ] **DOCS-04**: Per-adapter docs: `docs/adapters/shopaholic.md`, `docs/adapters/mall.md`, `docs/adapters/theme-action.md`. Each documents the cart-plugin event surface mapped to Meta events.
- [ ] **DOCS-05**: `UPGRADE.md` documents v1.1.1 → v2.0.0 upgrade path for nailscosmetics.* operators (BC migration steps, Settings preservation, namespace shim window).

### Marketplace launch (Phase 5)

- [ ] **MKT-01**: Composer package published as `logingrupa/oc-metapixel-plugin` on private GitHub repo with public composer-installable manifest. Composer install on clean OctoberCMS 4.x + no cart-plugin completes without errors.
- [ ] **MKT-02**: Plugin manifest (`plugin.yaml`): generic name "Meta Pixel + Conversions API", generic description, generic icon. Author `Logingrupa`. Homepage points to GitHub repo.
- [ ] **MKT-03**: Marketplace assets: plugin icon (PNG), 5 screenshots (Settings, FailedEvents list, Replay flow, dedup verification, debug panel), CHANGELOG.md documenting v2.0.0 changes vs v1.x legacy branch.
- [ ] **MKT-04**: Plugin git tag `v2.0.0` annotated. Pushed to remote. v1.1.1 + legacy/v1.1.1 branch preserved (operator may stay on legacy or upgrade per UPGRADE.md).
- [ ] **MKT-05**: `composer qa` exits 0 on clean OctoberCMS + Shopaholic install AND on clean OctoberCMS + no-cart install. Both CI matrix runs green.

## Future Requirements (v2.1+, deferred)

### v2.1 — MeloncartAdapter

- **MELON-01**: `Classes\Adapter\Meloncart\MeloncartCartAdapter` against `Operator\Meloncart\Models\Cart`. Untested in v2.0 codebase; requires paid Meloncart plugin install for full validation. Documented as v2.1 deliverable + reference example in `docs/CUSTOM-ADAPTERS.md`.

### v2.1 — Debug / Test-Events panel

- **DBG-01**: Backend panel listing last-100 EventLog rows with payload preview, per-site filter, dedup status badges. PixelYourSite-equivalent visualization. Differentiator feature, not table-stake.

### v2.x — Ops integrations

- **OPS-01**: Settings dropdown for dead-letter alert sink (log-only / Slack webhook / email / Telegram).
- **OPS-02**: External alert fan-out on `metapixel.event.dead_letter` event.
- **OPS-03**: Daily digest backend widget summarizing dead-lettered events by event_name + graph_error bucket.

### v2.x — Auto PSL refresh

- **PSL-01**: Operator-opt-in cron for automatic PSL data refresh (currently manual via artisan command in v2.0).

## Out of Scope (v2.0)

| Feature | Reason |
|---|---|
| GDPR / cookie-consent banner integration | Live theme has no banner. Re-gate if stakeholder ships one. |
| Multi-vendor pixel routing (GA4, GTM, Pinterest, TikTok) | Anti-feature — separate plugin in v2.x. PixelYourSite-style bloat avoided. |
| Browser-side `event_id` generation | Violates server-direction contract. Server-only. |
| Custom Graph API endpoint version | Pinned to v23.0. Operator-override explicitly rejected (breaking changes require code adaptation). |
| `declare(strict_types=1)` enforcement | Zero ecosystem usage in Lovata files. Optional per-file. |
| Charts / dashboards beyond Debug panel (v2.1) | Out-of-scope for marketplace baseline. |
| `content_id_source` Settings dropdown | Per-adapter ValueResolver controls format. No global toggle. |
| Mutation testing (Infection) | Post-v2 quality bar. |

## Coverage Summary

| Phase | Category | Requirements |
|---|---|---|
| 1 | Tooling (TOOL-01..11) | 11 |
| 2 | Adapter system (ADAP-01..11) | 11 |
| 3 | ShopaholicAdapter (SHOP-01..05) | 5 |
| 3 | ThemeActionAdapter (THEM-01..07) | 7 |
| 3 | MallAdapter (MALL-01..05) | 5 |
| 4 | Multisite (MULT-01..06) | 6 |
| 4 | TrustedHosts (HOST-01..06) | 6 |
| 4 | Cookie middleware (COOK-01..03) | 3 |
| 4 | FailedEvents (FAIL-01..03) | 3 |
| 4 | Translations (LANG-01..02) | 2 |
| 5 | Documentation (DOCS-01..05) | 5 |
| 5 | BC migration (BC-01..04) | 4 |
| 5 | Marketplace (MKT-01..05) | 5 |
| **Total v2** | **73 requirements** | |

## Traceability

(Filled by roadmapper agent.)

---
*Requirements defined: 2026-05-15*
*Milestone: v2.0.0 — Generic-event-tracking marketplace plugin*
