# Roadmap: Logingrupa.Metapixel

## Active Milestone: v2.0.0 — Generic-event-tracking marketplace plugin

**Phases:** 5 (Phase 1 → Phase 5)
**Granularity:** coarse
**Coverage:** 61/61 v2 requirements mapped (100%)
**Started:** 2026-05-15
**Numbering:** Fresh start at Phase 1 — prior-milestone phases archived under `.planning/archive/`. v2.0 is its own milestone; prior history not folded into v2.0 numbering.

**Build philosophy (locked):** Simple logic, fresh ideas, no over-engineering. No BC shims; fresh installs only. No dead code, no unused functions, no premature abstractions. Class names describe purpose, not shape. v2.0 adapters = FRESH implementations using v2.0 contracts. All host fixtures generic (`example.test`, `example.co.uk` per RFC 2606) — no operator-specific names baked in.

## Architecture at a glance

### Directory tree (after Phase 1 rename + Phase 5 launch)

```
plugins/logingrupa/metapixel/
├── Plugin.php                              # boot: PluginGuard + AdapterRegistry singleton + conditional adapter registration
├── plugin.yaml                             # generic name, generic description, generic icon
├── composer.json                           # logingrupa/oc-metapixel-plugin; PHP ^8.3 || ^8.4; lovata/* in suggest:
├── README.md                               # < 10 min install guide + Settings + Pixel acquisition + Twig API
├── classes/
│   ├── adapter/                            # contracts + registry
│   │   ├── EventSubjectAdapter.php         # interface (subject → adapter API)
│   │   ├── ValueResolver.php               # interface (value/contents/currency)
│   │   ├── AdapterRegistry.php             # service-container singleton
│   │   ├── shopaholic/                     # ShopaholicAdapter + ValueResolver (only dir allowed to import Lovata\OrdersShopaholic\*)
│   │   └── theme/                          # ThemeActionAdapter + ThemeActionEvent + ThemeEventCollector + ThemeAjaxHandler
│   ├── event/
│   │   └── adapter/shopaholic/             # OrderStatusWatcher (eloquent.updated subscriber)
│   ├── exception/                          # MetaPixel exceptions (carry-forward shape, simpler)
│   ├── helper/                             # SiteResolver, HostIndexResolver, PluginGuard, EventLogWriter
│   ├── meta/                               # MetaClient (Guzzle, Graph API v23), PayloadBuilder, UserDataHasher
│   └── queue/                              # SendCapiEvent (ShouldQueue + retry + dead-letter)
├── components/                             # PixelHead (head-area init + accumulator emit), EventPixel (server-confirmed render)
├── controllers/                            # FailedEvents (backend list + Replay + CheckDedup)
├── docs/                                   # CUSTOM-ADAPTERS.md (third-party authoring guide)
├── lang/{en,lv}/lang.php                   # en + lv translations (no ru at v2.0)
├── middleware/                             # EnsureFbpFbcCookies (kill switch + CR-03 + HostIndexResolver)
├── models/                                 # Settings (Multisite trait), EventLog, FailedEvent
├── resources/data/public_suffix_list.dat   # PSL shipped with plugin
├── tests/                                  # MetapixelTestCase + ShopaholicAdapterTestCase + Pest specs
└── updates/                                # migrations: create_metapixel_event_log + add_multisite_pixel_id_and_token
```

### Data flow — Purchase event end-to-end

```
[Order saved, status = new-payment-received]
        │ eloquent.updated
        v
[shopaholic\OrderStatusWatcher]
        │ AdapterRegistry::resolveFor($obOrder) → ShopaholicOrderAdapter
        │ EventLog row absent for (order_id, Purchase, capi)?
        v
[PayloadBuilder::buildEventPayload('Purchase', $obAdapter, $obOrder, $obResolver, $sEventId, $iEventTime, [])]
        │ Event::fire('metapixel.event.before_dispatch', [name, &payload, subject])  ← halts on false
        v
[SendCapiEvent::dispatch('Purchase', $arPayload, $obOrder, ShopaholicOrderAdapter::class)]
        │ queue worker rehydrates
        v
[SendCapiEvent::handle]
        │ EventLogWriter::record(...) → race-fence UNIQUE INSERT
        │ Settings::lookupForSite($obAdapter->getSiteId($obOrder)) → ($sPixelId, $sToken)
        │ MetaClient::sendForPixel($sPixelId, $sToken, $arPayload)
        v
[POST https://graph.facebook.com/v23.0/{pixel_id}/events]
        │ on permanent fail: FailedEvent::create + Event::fire('metapixel.event.dead_letter', ...)
        v
[Customer hits /lv/checkout/{secret_key}]
        │ EventPixel component
        │ EventLog has channel=capi row + channel=pixel row absent?
        v
[emit inline <script>fbq('track', 'Purchase', custom_data, {eventID: $sEventId})</script>]
        │ Larajax onMarkFired → EventLog INSERT (channel=pixel)
```

### Twig API — operator-supplied theme events

```twig
{# operator-theme/pages/product.htm — fires ViewContent on PDP render #}
{% do this.metapixel.pushEvent({
    name: 'ViewContent',
    action_key: 'product-view:' ~ product.id,
    content_ids: ['SKU-' ~ product.id],
    value: product.offer.first.price_value,
    currency: 'EUR',
    also_dispatch_capi: true
}) %}
```

```html
<!-- operator-theme/partials/cart.htm — fires AddToCart on click via Larajax -->
<button onclick="jax.ajax('Metapixel::onFireEvent', { data: {
    name: 'AddToCart',
    content_ids: ['SKU-42'],
    value: 12.50,
    currency: 'EUR'
}})">Add to cart</button>
```

### Extension example — third-party overrides payload before dispatch

```php
// Third-party Plugin::boot() — wraps any event before CAPI POST
Event::listen('metapixel.event.before_dispatch',
    function (string $sEventName, array &$arPayload, object $obSubject): ?bool {
        if ($sEventName === 'Purchase') {
            $arPayload['custom_data']['campaign_tier'] = $this->resolveTier($obSubject);
        }
        return null; // null/true = continue; false = halt dispatch
    }
);
```

### Custom adapter example — third-party cart plugin

```php
// plugins/acme/customcart/Plugin.php
class Plugin extends PluginBase {
    public $require = ['Logingrupa.Metapixel'];

    public function boot(): void {
        AdapterRegistry::instance()->register(AcmeCart::class, AcmeCartAdapter::class);

        AcmeCart::extend(function ($obCart) {
            $obCart->bindEvent('model.afterSave', function () use ($obCart) {
                if ($obCart->isDirty('status') && $obCart->status === 'paid') {
                    SendCapiEvent::dispatch('Purchase', $this->buildPayload($obCart), $obCart, AcmeCartAdapter::class);
                }
            });
        });
    }
}
```

### Settings UX — Multisite-native

- October's built-in **top-bar site picker** scopes Settings reads/writes (NOT a custom repeater field). Operator picks site, edits `pixel_id` + `capi_access_token`, saves — row stored as separate `system_settings` entry by October.
- Single-site installs see no UX change (default row primary).
- Per-adapter Settings fields (paid_status_code, currency_code) ship dynamic dropdowns sourced from the cart-plugin (e.g. `Status::lists()` for Shopaholic). Operator picks from current state — no hardcoded option list.

## Phases

- [ ] **Phase 1: Tooling + composer + namespace rename + CI matrix** — Quality bar + PHP 8.3/8.4 dual-CI green before any business code; namespace `Logingrupa\Metapixel` lands.
- [x] **Phase 2: Adapter system core — contracts + registry + extension hooks** — `EventSubjectAdapter` + `ValueResolver` + `AdapterRegistry` + 3 `Event::fire` hooks; v1.x I/O backbone refactored behind adapter signatures; 177 tests adapted via FakeAdapter. (completed 2026-05-17)
- [x] **Phase 3: ShopaholicAdapter + ThemeActionAdapter parallel wave** — Non-regression port of v1.x Order/Cart logic behind ShopaholicAdapter; generic theme-action tracking via Twig + Larajax for operators without a supported cart. (completed 2026-05-18)
- [x] **Phase 4: Settings rework — Multisite + TrustedHosts + Cookie + FailedEvents + translations** — Per-site `pixel_id`/`capi_access_token`; operator-supplied `trusted_hosts` + PSL-aware index derivation; FailedEvents backend UI; en/lv translations. (completed 2026-05-20)
- [x] **Phase 5: Documentation + marketplace launch (partial)** — Cutover wave + CHANGELOG shipped 2026-05-27. README + smoke + screenshots deferred until Phase 6 ViewContent funnel ships. 05-13 + 05-14 split out to Launch Milestone. (completed 2026-05-28)
- [x] **Phase 6: ViewContent funnel — Shopaholic PDP + offer-switch** — Close conversion funnel at offer-level grain. ShopaholicProductAdapter + ProductPageWatcher + offer-switch JS. Refactor PixelHead to flush at `cms.page.beforeRenderPage` (breaking timing change — no callout, plugin is fresh, no operators on legacy timing yet). See brief `.planning/briefs/2026-05-27-viewcontent-funnel-shopaholic.md`. (completed 2026-05-28)

**Launch Milestone (deferred, separate from numbered phases)** — Pre-flip security sweep Step B + public repo flip + `v2.0.0` annotated tag. Triggered when operator decides to launch; not gated by phase progress.

## Phase Details

### Phase 1: Tooling + composer + namespace rename + CI matrix

**Goal:** A fresh clone of the renamed plugin (`plugins/logingrupa/metapixel/`, namespace `Logingrupa\Metapixel`) passes `composer qa` on both PHP 8.3 and PHP 8.4, in both full-Lovata and minimal install matrices, with the quality toolchain wired correctly before any business-logic refactor begins.

**Depends on:** Nothing (first phase of v2.0).

**Requirements:** TOOL-01, TOOL-02, TOOL-03, TOOL-04, TOOL-05, TOOL-06, TOOL-07, TOOL-08, TOOL-09, TOOL-10, TOOL-11

**Success Criteria** (what must be TRUE):

  1. `composer install` on a fresh clone of the renamed `plugins/logingrupa/metapixel/` directory succeeds; `composer qa` exits 0 on the empty post-rename scaffold (pint-test → analyse → phpmd → test-cov chain).
  2. CI matrix on GitHub Actions runs `php: [8.3, 8.4]` × `install: [full-lovata, minimal]`; all four cells green on the rename PR. Full-Lovata enforces coverage gate ≥90%; minimal runs MetapixelTestCase subsets with no coverage gate.
  3. PHPStan (level 10, `phpVersion: 80300`) + Rector (`LevelSetList::UP_TO_PHP_83`) + Pint (`nullable_type_declaration_for_default_null_value`) collectively reject PHP 8.4-only syntax — operator-authored snippet using property hooks / asymmetric visibility / `array_find` / `#[\Deprecated]` fails CI with a clear error. (Prevents **P-06**.)
  4. `shipmonk/composer-dependency-analyser` reports zero violations and would flag a hidden `use Lovata\OrdersShopaholic\Models\Order` inserted anywhere outside `Classes\Adapter\Shopaholic\` namespace. (Prevents **P-03**.)
  5. Three-tier Pest 4 test bases instantiated: `MetapixelTestCase` (no cart-plugin deps), `ShopaholicAdapterTestCase extends MetapixelTestCase` (boots Lovata Orders table for Run A). Each tier runs in isolation without the other tier's migrations.

**Plans:** 3 plans

- [x] `01-01-PLAN.md` — Directory rename + namespace rewrite + composer.json TOOL-01 shape (TOOL-01, TOOL-02, TOOL-03) — SHIPPED 2026-05-16
- [x] `01-02-PLAN.md` — Tooling configs: phpstan, rector, pint, phpmd, composer-dependency-analyser, qa script chain (TOOL-04, TOOL-05, TOOL-06, TOOL-07, TOOL-10, TOOL-11) — SHIPPED 2026-05-16
- [x] `01-03-PLAN.md` — Pest 4 test scaffold (MetapixelTestCase + ShopaholicAdapterTestCase) + GitHub Actions 2x2 CI matrix (TOOL-08, TOOL-09) — SHIPPED 2026-05-16

### Phase 2: Adapter system core — contracts + registry + extension hooks

**Goal:** A generic event-dispatch backbone exists where any subject (Shopaholic Order, theme action, or third-party cart) can be tracked through the same `MetaClient` + `PayloadBuilder` + `UserDataHasher` + `EventLogWriter` pipeline behind an `EventSubjectAdapter` + `ValueResolver` interface pair resolved at runtime via `AdapterRegistry`. v1.x's 177-test suite is regreened against the new signatures via a `FakeAdapter` test double; no production adapter ships in this phase.

**Depends on:** Phase 1 (CI matrix + namespace rename + composer-dependency-analyser must be in place to gate adapter-directory isolation).

**Requirements:** ADAP-01, ADAP-02, ADAP-03, ADAP-04, ADAP-05, ADAP-06, ADAP-07, ADAP-08, ADAP-09, ADAP-10, ADAP-11

**Success Criteria** (what must be TRUE):

  1. A developer writing a new adapter implements two interfaces (`EventSubjectAdapter` + `ValueResolver`) and calls `AdapterRegistry::register($sSubjectClass, $sAdapterClass)` from their plugin's `boot()`; no plugin core change required. The contract test `FakeAdapter` round-trips through `PayloadBuilder::buildEventPayload()` and produces the same envelope shape v1.x produced for Order.
  2. `EventSubjectAdapter::getSiteId(object $obSubject): ?int` is enforced as the only authoritative source of `site_id` — PHPStan disallowed-calls rules in `Classes\Queue\`, `Classes\Event\`, `Classes\Adapter\` ban `SiteManager::*`, `request()`, `Request::*`, and an integration contract test asserts `getSiteId()` returns the same value regardless of `Site::setSite($i)` active context. (Prevents **P-01**, anchored in the Phase 3.1-07 production bug on orders 29802/29803.)
  3. Three `Event::fire` extension points (`metapixel.event.before_dispatch`, `metapixel.event.after_dispatch`, `metapixel.event.dead_letter`) fire at documented decision boundaries; a throwing third-party listener is caught + `Log::warning`'d + dispatch continues (test: simulate throwing listener, assert core dispatch succeeds and dead-letter records only the listener exception).
  4. `MetaClient::sendForPixel(string $sPixelId, string $sToken, array $arPayload)` accepts per-call credentials (no more singleton Settings read); `PayloadBuilder::buildEventPayload(string $sEventName, EventSubjectAdapter, object $obSubject, ValueResolver, string $sEventId, int $iEventTime, array $arEventExtras)` is subject-agnostic; Graph API pinned to `v23.0` constant. `SendCapiEvent` constructor accepts a 4th `string $sAdapterClass` arg; `handle()` rehydrates the adapter via `AdapterRegistry::resolveByClass()` and writes FailedEvent on `BindingResolutionException`.
  5. All 177 v1.x tests regreen via a `FakeAdapter` test double standing in for ShopaholicOrderAdapter. `OrderStatusWatcherEventLogTest`, `PurchasePixelEventLogGateTest`, `SendCapiEventEventLogTest`, `MultiSiteEventLogTest` pass without touching real Lovata Order code.

**Plans:** 9/9 plans complete

### Phase 3: ShopaholicAdapter + ThemeActionAdapter parallel wave

**Goal:** Two adapters ship behind Phase 2 contracts as FRESH implementations using modern October 4 + Laravel 12 + Lovata.Toolbox idioms. NOT a v1.x port. `ShopaholicAdapter` tracks `Lovata\OrdersShopaholic\Models\Order` (reuses v1.x DECISIONS — event_id contract, EventLog UNIQUE race-fence, `SKU-{product_id}[-{offer_id}]` content_ids — but writes new simple code). `ThemeActionAdapter` lets an operator on any OctoberCMS install track events from theme partials via Twig API + Larajax handler without writing PHP. The adapter contract proves out in two shapes simultaneously.

**Depends on:** Phase 2 (interfaces + AdapterRegistry + Event hooks + refactored signatures must be locked before adapters can be authored).

**Requirements:** SHOP-01, SHOP-02, SHOP-03, SHOP-04, SHOP-05, THEM-01, THEM-02, THEM-03, THEM-04, THEM-05, THEM-06, THEM-07

**Success Criteria** (what must be TRUE):

  1. Pest integration test on a generic Order fixture (host `example.test`, hermetic SQLite): status flip to `new-payment-received` → ShopaholicAdapter dispatch → `EventLogWriter::record` race-fence (channel=capi) → Guzzle MockHandler asserts Meta payload shape + event_id presence. Same event_id round-trips to browser PixelHead/EventPixel render. EventLog row uses `subject_type = 'shopaholic.order'` alias — NOT FQN (**P-05** prevention). Second admin-flip on same Order does NOT re-fire (EventLog row blocks).
  2. `Plugin::boot()` conditionally registers ShopaholicOrderAdapter + subscribes OrderStatusWatcher only when `PluginManager::instance()->exists('Lovata.OrdersShopaholic')` is true. CI Run B (minimal install, no Lovata) boots the plugin without errors; ShopaholicAdapter is absent from the registry; no `Lovata\*` import is loaded outside `Classes\Adapter\Shopaholic\`.
  3. An operator on a Lovata-free OctoberCMS theme wires a `ViewContent` event from a Twig product page by calling `{% do this.metapixel.pushEvent({name: 'ViewContent', action_key: 'product-view:' ~ product.id, content_ids: [...], value: 12.50, currency: 'EUR'}) %}`; `PixelHead` emits the corresponding `fbq('track', ...)` script block on the next render, optionally mirroring to CAPI when `also_dispatch_capi: true`.
  4. The Larajax handler `Metapixel::onFireEvent` validates incoming events against an `EVENT_NAME_ALLOWLIST` of Meta-standard event names, enforces OctoberCMS CSRF token, rate-limits per IP+session, and JS-escapes returned payload fragments. Pest fuzzing tests with XSS / SQLi-shaped / oversize / mixed-encoding inputs all return 422 with no row written to EventLog. (Prevents **P-09**.)
  5. `Components\EventPixel` accepts `subject_class` + `subject_slug_field` properties and resolves the adapter via `AdapterRegistry::resolveByClass()`. `onMarkFired` AJAX writes `channel='pixel'` row to EventLog with server-supplied `event_id` validation; `ThemeEventCollector` accumulator is request-scoped and flushed between requests.

**Plans:** 9/10 plans executed

- [ ] `03-01-PLAN.md` — EventLog payload column migration + EventLogWriter::record `array $arPayload` trailing arg + `PurgeEventLog` console command + `Plugin::registerSchedule` daily wire-up (foundation; D-06..D-08)
- [ ] `03-02-PLAN.md` — `ShopaholicOrderAdapter` + `ShopaholicOrderValueResolver` + `OrderStatusWatcher` + Plugin::boot conditional registration via `PluginManager::exists` gate (SHOP-01, SHOP-02, SHOP-03, SHOP-04)
- [ ] `03-03-PLAN.md` — `ShopaholicCartPositionAdapter` + `ShopaholicCartPositionValueResolver` + `CartPositionWatcher` (MorphTo-aware Offer access + dedup on update) — carries SHOP-01..04 to CartPosition
- [ ] `03-04-PLAN.md` — SHOP-05 end-to-end Pest integration test: status flip → dispatch → race-fence → Guzzle MockHandler payload assertion + second-flip dedup proof (SHOP-05)
- [ ] `03-05-PLAN.md` — `ThemeActionEvent` value object + `ThemeActionAdapter` with D-15 site_id fallback + phpstan.neon D-16 deny-list narrowing (THEM-01, THEM-02)
- [ ] `03-06-PLAN.md` — `ThemeEventCollector` request-scoped singleton + `Plugin::registerMarkupTags` Twig `metapixel_push_event` bare function + `this.metapixel.pushEvent` dot-notation mount (THEM-03, THEM-04)
- [ ] `03-07-PLAN.md` — `ThemeAjaxHandler` P-09 defence (META_STANDARD allowlist + Settings textarea + RateLimiter + JS-escape + 14-input fuzzing matrix) (THEM-05)
- [ ] `03-08-PLAN.md` — `Components\EventPixel` (D-09 direct-DB read + un-injectable event_id onMarkFired) + `Components\PixelHead` (ThemeEventCollector consumer + optional CAPI mirror) (THEM-06, THEM-07)
- [ ] `03-09-PLAN.md` — Gap closure: null-guard `ShopaholicOrderValueResolver::buildContentId` orphan TypeError + `ShopaholicCartPositionAdapter::getSiteId` Site::getCurrent fallback (VERIFICATION Gap 1 + Gap 2; SHOP-02, SHOP-03)
- [ ] `03-10-PLAN.md` — Gap closure: persist `theme_custom_event_names` as newline-string for textarea round-trip + six missing `settings.fields` lang keys (VERIFICATION Gap 3 + Gap 4; THEM-05, THEM-07)

### Phase 4: Settings rework — Multisite + TrustedHosts + Cookie + FailedEvents + translations

**Goal:** Settings becomes marketplace-ready: per-site `pixel_id` + `capi_access_token` via Multisite trait; operator-supplied `trusted_hosts` allowlist plus `jeremykendall/php-domain-parser` derives subdomain cookie index for any multi-TLD host (replacing v1.x hardcoded `HOST_INDEX_MAP`); `EnsureFbpFbcCookies` middleware honors both the kill switch and CR-03 fbclid validation; backend `Controllers\FailedEvents` ships Replay + dedup-status verification; en/lv translations cover every UI surface. This phase closes the marketplace launch blocker on host-spoofing safety (**P-15**).

**Depends on:** Phase 3 (ShopaholicAdapter Settings interactions — per-adapter `paid_status_code` semantics, EventLog row writes with per-site `pixel_id` — must be wired before Multisite trait re-routes credential lookups).

**Requirements:** MULT-01, MULT-02, MULT-03, MULT-04, MULT-05, MULT-06, HOST-01, HOST-02, HOST-03, HOST-04, HOST-05, HOST-06, COOK-01, COOK-02, COOK-03, FAIL-01, FAIL-02, FAIL-03, LANG-01

**Success Criteria** (what must be TRUE):

  1. An operator running two OctoberCMS sites (Site A + Site B) configures `pixel_id_A` + `token_A` on Site A and `pixel_id_B` + `token_B` on Site B; a Site-A Order fires CAPI + Pixel to `pixel_id_A` and a Site-B Order fires to `pixel_id_B`; cross-site EventLog rows independent under UNIQUE NULL-distinct semantics (8-path matrix test: 2 sites × 2 adapters × 2 channels). `$propagatable = []` lock prevents cross-site token leak. (Prevents **P-10**.)
  2. An operator on `shop.example.co.uk`, `acme.com.br`, or an IDN host (`xn--bcher-kva.example`) saves their domain to `trusted_hosts` Settings; the middleware derives the correct subdomain index via PSL (e.g. 1 for apex, 2 for `www.` subdomain); `_fbp` / `_fbc` cookies write to the correct scope. A host not in `trusted_hosts` causes the middleware to NO-OP — no exception, no cookies set (fail-safe). (Prevents **P-15**.)
  3. `EnsureFbpFbcCookies` honors `Settings::get('ensure_fbp_fbc_server_side', true)` as a kill switch; CR-03 fbclid validation (`[A-Za-z0-9_-]` charset, ≤255 chars) skips `_fbc` on invalid input. PSL data ships at `resources/data/public_suffix_list.dat`; `metapixel:refresh-psl` artisan command refreshes from upstream; parsed `Rules` cache lives at `storage/app/metapixel/psl/` (Forge-writable, not in read-only release dir — prevents **P-18**).
  4. A backend admin viewing `Controllers\FailedEvents` sees columns event_id / event_name / adapter_type / http_status / attempts / created_at / graph_error snippet with filters by event_name + adapter_type + date range. Clicking "Replay" on a row re-dispatches the event through `MetaClient`, increments attempts, flash-succeeds on HTTP 200, surfaces graph_error on failure. Clicking "CheckDedup" calls `MetaClient::fetchTestEventsStatus()` and returns JSON with dedup % + EMQ per event for the current `test_event_code`.
  5. All Settings field labels, Settings commentAbove text, FailedEvents column labels, FailedEvents action buttons (Replay, CheckDedup), backend menu label, and error messages render through `lang/en/lang.php` and `lang/lv/lang.php` (RainLab.Translate-compatible structure). No raw lang keys leak to UI; en + lv only (RU dropped per scope decision — operator adds own `lang/ru/lang.php` if needed).

**Plans:** 5/5 plans complete

- [ ] `04-01-PLAN.md` — Settings Multisite trait + lookupForSite per-site body + AddMultisitePixelIdAndToken no-op migration + phpstan disallowed-calls D-02 (MULT-01, MULT-02, MULT-03, MULT-04, MULT-05, MULT-06)
- [ ] `04-02-PLAN.md` — HostIndexResolver (jeremykendall/php-domain-parser ^6.4) + bundled PSL data + RefreshPsl artisan command + trusted_hosts beforeSave strict validation + 4-tab fields.yaml restructure (HOST-01, HOST-02, HOST-03, HOST-04, HOST-05, HOST-06)
- [ ] `04-03-PLAN.md` — EnsureFbpFbcCookies middleware (fresh derivation per D-20) + Plugin::boot pushMiddleware registration + kill switch + CR-03 fbclid validation (COOK-01, COOK-02, COOK-03)
- [ ] `04-04-PLAN.md` — Controllers\FailedEvents ListController + onReplay/onCheckDedup AJAX handlers + AddDedupColumnsToFailedEvents migration + MetaClient::fetchTestEventsStatus extension + Plugin::registerSettings 'failed_events' entry (FAIL-01, FAIL-02, FAIL-03)
- [ ] `04-05-PLAN.md` — lang/{en,lv}/lang.php expansion to ~60 keys + fields.yaml label paths migration to field.* + LangKeyCoverageTest Pest gate (LANG-01)

### Phase 5: Documentation + marketplace launch

**Goal:** A buyer on a clean OctoberCMS 4.x install runs `composer require logingrupa/oc-metapixel-plugin` and reaches their first verified CAPI event in Meta Test Events within 10 minutes by following the README. A third-party developer authors a custom adapter against `docs/CUSTOM-ADAPTERS.md` with a working `AcmeCartAdapter` reference example. The plugin ships as a Composer package on the private GitHub repo with `v2.0.0` annotated tag, marketplace assets (icon + 5 screenshots + CHANGELOG.md), and `composer qa` exits 0 on both CI matrix branches.

**Depends on:** Phase 4 (TrustedHosts marketplace blocker **P-15** must be closed before any external operator install; Settings UI + translations must be production-shaped for README walkthroughs).

**Requirements:** DOCS-01, DOCS-02, DOCS-03, MKT-01, MKT-02, MKT-03, MKT-04, MKT-05

**Success Criteria** (what must be TRUE):

  1. A timed dry-run on a fresh OctoberCMS 4.x install (no cart plugin) following only the README — `composer require` → Settings configuration → first CAPI event verified in Meta Test Events — completes in under 10 minutes. This dry-run is the launch acceptance gate.
  2. `docs/CUSTOM-ADAPTERS.md` contains a working ~50-LOC `AcmeCartAdapter` + `AcmeCartValueResolver` example documenting the `AdapterRegistry::register()` pattern, `$require` plugin dependency declaration, and the three `Event::fire` hooks. A developer copying the example, swapping cart-model names, and registering from their own `Plugin::boot()` ships a working third-party adapter without touching plugin core.
  3. `composer require logingrupa/oc-metapixel-plugin` succeeds on (a) clean OctoberCMS 4.x with no cart plugin, (b) clean OctoberCMS 4.x with Shopaholic + OrdersShopaholic + Buddies. `composer qa` exits 0 on both. CI matrix Run A + Run B remain green on the `v2.0.0` tag commit.
  4. Plugin manifest (`plugin.yaml`) ships generic name "Meta Pixel + Conversions API", generic description, generic icon. Marketplace assets present: plugin icon (PNG), 5 screenshots (Settings, FailedEvents list, Replay flow, dedup verification, theme Twig API usage), CHANGELOG.md documenting the v2.0.0 initial public release.
  5. Git tag `v2.0.0` annotated and pushed to remote. No BC shim; no upgrade migration in v2.0.

**Plans:** 17/17 plans complete

- [x] 05-18-PLAN.md

**Wave 1**

- [x] 05-00-PLAN.md
- [x] `05-00-PLAN.md` — Wave 0 test scaffolding (ReadmeStructureTest + CustomAdaptersStructureTest + AssetsExistTest + PluginYamlSanityTest) (DOCS-01, DOCS-02, DOCS-03, MKT-02, MKT-03)
- [x] `05-02-PLAN.md` — Legacy JS pixel inventory + strip: Task 0 inventory grep, Tasks 1-3 four deletes + eleven edits + bundle rebuild + dead-v1.x `purchasePixel` block strip (DOCS-01 cutover)
- [x] `05-03-PLAN.md` — UAT Gate 1: zero-events verification on 5 pages via Pixel Helper + Test Events + EventLog DB (D-03 + D-05) — closed 2026-05-22 5/5 PASS (commit `933f194`)
- [x] `05-04-PLAN.md` — PixelHead layout wire + UAT Gate 2 — closed 2026-05-27 PASS (theme commit `524189f`)
- [x] `05-06-PLAN.md` — EventPixel per-event wire + UAT Gate 3 — closed 2026-05-27 PASS (theme commits `6d2367c` + `866236e`)
- [ ] `05-08-PLAN.md` — Live smoke on new.nailscosmetics.lv → 05-SMOKE-LOG.md + 5 screenshots at plugin-relative `docs/screenshots/` (DOCS-01, MKT-03) — **blocks on Phase 6 ViewContent shipping**
- [ ] `05-09-PLAN.md` — README.md single-page walkthrough (DOCS-01, DOCS-02) — **blocks on Phase 6 ViewContent + PixelHead deferred-flush shipping (README must document ViewContent scope + breaking lifecycle change)**
- [x] `05-10-PLAN.md` — docs/CUSTOM-ADAPTERS.md with AcmeCart minimal register snippet + OFFLINE Mall full inline example + 3 hook patterns + Testing section (DOCS-03)
- [x] `05-11-PLAN.md` — v1.x reference strip (13 docblock decorators + ROADMAP/REQUIREMENTS MKT-* wording) + NoV1xReferencesTest gate (release hygiene)
- [x] `05-12-PLAN.md` — CHANGELOG.md fresh v2.0.0 + composer.json keywords + plugin.yaml verify (MKT-02, MKT-03) — closed 2026-05-27 4/5 AssetsExistTest GREEN (screenshots assertion owned by 05-08)
- [ ] `05-15-PLAN.md` — Gap closure (UAT test 9 / D-07): browser AddToCart fbq reuses server CAPI event_id + full custom_data; pixel-only wire (CartPositionWatcher::resolveBrowserPixel + Metapixel::onMarkAddToCart + theme $.request) with no second CAPI dispatch
- [ ] `05-16-PLAN.md` — Gap closure UAT re-test (D-07): rebuild theme assets + operator verifies event_id dedup + full custom_data + stray no-event_id AddToCart gone
- [ ] `05-19-PLAN.md` — Gap closure (UAT test 7 / SC1 / DOCS-01): README install dead-end fix — document `php artisan project:set <license>` gateway prerequisite + `-W` flag + Business→Events Manager wording + ordered quick-start box; extend ReadmeStructureTest
- [ ] `05-20-PLAN.md` — Gap closure (UAT test 9 / MKT-04): revert erroneous ROADMAP launch-milestone `[x] completed` checkboxes to `[ ]` deferred, matching the `0/2 Deferred` progress row
- [ ] `05-21-PLAN.md` — Gap closure (UAT test 9 / MKT-04): rewrite metapixel-qa.yml for the standalone-plugin-at-root public repo + gateway-auth secret; operator-approved push of master + watch CI matrix to green (autonomous: false)

**Wave 2** *(blocked on Wave 1 completion)*

- [x] 05-02-PLAN.md
- [x] 05-11-PLAN.md

**Wave 3** *(blocked on Wave 2 completion)*

- [x] 05-03-PLAN.md

**Wave 4** *(blocked on Wave 3 completion)*

- [x] 05-04-PLAN.md

**Wave 5** *(blocked on Wave 4 completion)*

- [x] 05-06-PLAN.md

**Wave 6** *(blocked on Wave 5 completion)*

- [x] 05-08-PLAN.md

**Wave 7** *(blocked on Wave 6 completion)*

- [x] 05-09-PLAN.md
- [x] 05-10-PLAN.md

**Wave 8** *(blocked on Wave 7 completion)*

- [x] 05-12-PLAN.md

**Wave 11** *(blocked on Wave 8 completion)*

- [x] 05-15-PLAN.md

**Wave 12** *(blocked on Wave 11 completion)*

- [x] 05-16-PLAN.md

**Wave 13** *(blocked on Wave 12 completion)*

- [x] 05-17-PLAN.md

**Gap-closure wave (2026-07-03 — UAT tests 7 & 9)**

- [x] 05-19-PLAN.md — README install dead-end fix (autonomous)
- [x] 05-20-PLAN.md — ROADMAP launch-checkbox revert (autonomous)
- [ ] 05-21-PLAN.md — CI workflow repair + operator-gated push to green (autonomous: false; depends on 05-19, 05-20)

**Cross-cutting constraints:**

- Cutover gate is operator-confirmed per D-03 — autonomous: false, resume signal required.
- UAT verification combines D-05 three sources: Meta Pixel Helper, Meta Test Events live view, `logingrupa_metapixel_event_log` DB tail.
- Screenshot paths follow D-19: `plugins/logingrupa/metapixel/docs/screenshots/{01-settings,02-failed-events,03-replay,04-check-dedup,05-twig-api}.png`. README references via plugin-relative `docs/screenshots/`.

### Phase 6: ViewContent funnel — Shopaholic PDP + offer-switch

**Goal:** Close the Meta Pixel conversion funnel for Shopaholic operators at offer-level grain — `ViewContent (SKU) → AddToCart (SKU) → Purchase (SKU per line item)`. Zero theme code required. Plugin auto-fires on Shopaholic PDP render AND on offer-selector change. Browser fbq + Server CAPI share `event_id` for Meta dedup.

**Depends on:** Phase 5 cutover wave (PixelHead + EventPixel shipped + UAT Gates 2 + 3 PASS). MUST ship before Phase 5 plans 05-08 (smoke needs ViewContent firing) and 05-09 (README must document ViewContent + breaking PixelHead lifecycle change).

**Source brief:** `.planning/briefs/2026-05-27-viewcontent-funnel-shopaholic.md` — D-1 through D-6 locked 2026-05-27.

**Requirements:** VIEW-01, VIEW-02, VIEW-03, VIEW-04, VIEW-05, VIEW-06, VIEW-07, VIEW-08, VIEW-09, VIEW-10, VIEW-11

**Success Criteria** (what must be TRUE):

  1. `ViewContent` fires on every Shopaholic PDP render via `shopaholic.product.open` event subscriber. content_ids = `['SKU-{pid}-{oid}']` for multi-offer products; `['SKU-{pid}']` for single-offer. Browser fbq + Server CAPI share event_id.
  2. `ViewContent` re-fires with new event_id + new offer SKU on every `[name="offer_id"]` DOM change (select / radio / hidden input) via plugin-injected vanilla JS + ThemeAjaxHandler endpoint.
  3. `PixelHead` flushes at `cms.page.beforeRenderPage` (NOT `onRun()`) so page-tier components can push events to ThemeEventCollector before flush. Base PageView still emits; action_key shape `base:pageview:{site_id}:{event_id}` unchanged.
  4. `ShopaholicProductAdapter` + `ShopaholicProductValueResolver` ship with PHPStan level 10 + PHPMD + Pint clean. PHPStan disallowed-calls deny-list still bans `SiteManager::*`, `Request::*`, `request()` inside adapter dir — `getSiteId()` reads from `$obProduct->site_id`.
  5. `composer deps` boundary check confirms `ShopaholicProductAdapter` is the only new file importing `Lovata\Shopaholic\*`.
  6. Test matrix (11 ProductPageWatcher assertions + 4 PixelHeadDeferredFlush assertions) all GREEN; coverage stays ≥90 % on full-Lovata CI cell.
  7. CHANGELOG.md gets new entries under `### Added` (under `## [2.0.0] - YYYY-MM-DD`) documenting the ViewContent funnel artifacts. NO breaking-changes callout per CONTEXT.md D-discretion + Phase 5 D-22 fresh-v2.0.0 stance. PixelHead PHPDoc carries the lifecycle-contract docblock for future operators. README documents ViewContent + offer-switch behaviour.

**Plans:** 7/7 plans complete

- [ ] `06-01-PLAN.md` — Wave 1: REQUIREMENTS.md VIEW-XX rows + VALIDATION.md per-task map + 5 RED test stubs (autonomous)
- [ ] `06-02-PLAN.md` — Wave 2: PixelHead deferred-flush refactor + PixelHeadDeferredFlushBuffer singleton + Plugin.boot listener (VIEW-01)
- [ ] `06-03-PLAN.md` — Wave 3: AdapterRegistry::resolveByAlias + SupportsHybridAjax subinterface + UnknownSubjectTypeException (VIEW-07, VIEW-08)
- [ ] `06-04-PLAN.md` — Wave 3: ShopaholicProductAdapter + ShopaholicProductValueResolver + phpstan.neon allowlist (VIEW-02, VIEW-03, VIEW-08)
- [ ] `06-05-PLAN.md` — Wave 4: ProductPageWatcher + Plugin.boot wiring + 11-item brief matrix tests (VIEW-04, VIEW-10)
- [ ] `06-06-PLAN.md` — Wave 5: ProductPixel component + offer-switch JS + ThemeAjaxHandler hybrid subject_type branch (VIEW-05, VIEW-06, VIEW-09)
- [ ] `06-07-PLAN.md` — Wave 6: CHANGELOG.md + README.md ViewContent walkthrough + PixelHead PHPDoc verify (VIEW-11)

## Pitfall Coverage Map

(From `research/PITFALLS.md` — every CRITICAL/HIGH pitfall has a phase that prevents it.)

| Pitfall | Severity | Phase preventing |
|---|---|---|
| P-01 Cross-context resolution drift | CRITICAL | Phase 2 (ADAP-06 SiteResolver::forSubject + PHPStan disallowed-calls + contract test) |
| P-02 Boot-order race | CRITICAL | Phase 2 (ADAP-03 lazy `App::make` + idempotent register + order-agnostic test) |
| P-03 Hidden Lovata imports outside adapter dir | CRITICAL | Phase 1 (TOOL-11 composer-dependency-analyser) + Phase 3 (SHOP-04 isolation) |
| P-05 EventLog `subject_type` alias ambiguity | CRITICAL | Phase 2 (ADAP-01 alias contract) + Phase 3 (SHOP-01 returns `'shopaholic.order'`) |
| P-06 PHP 8.4-only syntax slips | HIGH | Phase 1 (TOOL-04 phpstan `phpVersion: 80300` + TOOL-05 Rector UP_TO_PHP_83 + TOOL-06 Pint nullable rule) |
| P-07 PDP IDNA2008 wrap | HIGH | Phase 4 (HOST-02 wrap + fallback chain) |
| P-08 Event::fire mutable payload | HIGH | Phase 2 (ADAP-04 documented contract + ADAP-05 listener isolation) |
| P-09 Larajax open relay | HIGH | Phase 3 (THEM-05 allowlist + CSRF + rate-limit + JS-escape + fuzzing) |
| P-10 Multisite `$propagatable` leak | HIGH | Phase 4 (MULT-01 `$propagatable = []` + MULT-05 cross-site test) |
| P-11 class_exists autoloader race | HIGH | Phase 3 (SHOP-04 PluginManager::exists gate) |
| P-12 Generic core tests boot Shopaholic | HIGH | Phase 1 (TOOL-08 three-tier test base) |
| P-15 TrustedHosts marketplace blocker | LAUNCH-GATE | Phase 4 (HOST-01..06) — MUST close before Phase 5 |
| P-18 PSL cache write fails on Forge | MEDIUM | Phase 4 (HOST-03 cache path = storage/app/metapixel/psl/) |
| P-20 Coverage on partial code paths | LOW | Phase 1 (TOOL-09 coverage gate on Run A only) |

(Pitfalls P-04, P-14, P-16, P-17 — BC migration — DROPPED for v2.0: no upgrade path. Fresh installs only.)

### Launch Milestone (deferred, separate from numbered phases)

**Goal:** Make the plugin publicly installable. Triggered when operator decides to launch — gated by Phase 5 close + Phase 6 ship + operator readiness.

**Plans:** 0/2

- [x] `launch-01-PLAN.md` — Pre-flip security sweep Step B: `.planning/` operator-infra redaction (replaces `new.nailscosmetics.lv` → `your-staging-host.example` in STATE.md + 05-CONTEXT.md + 05-DISCUSSION-LOG.md + research/PITFALLS.md). Worklist captured in `.planning/phases/05-documentation-marketplace-launch/05-13-SECURITY-SWEEP.md`. _(was Phase 5 plan 05-13)_ (completed 2026-07-03)
- [ ] `launch-02-PLAN.md` — Repo flip public + `v2.0.0` annotated tag + composer VCS install smoke from /tmp + CI matrix verify (MKT-01, MKT-04, MKT-05). _(was Phase 5 plan 05-14)_

Resume signal: `LAUNCH SCHEDULED` after operator decision.

## Progress

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Tooling + composer + namespace rename + CI matrix | 3/3 | Executed — pending verification | 2026-05-16 |
| 2. Adapter system core | 9/9 | Complete   | 2026-05-20 |
| 3. ShopaholicAdapter + ThemeActionAdapter | 9/10 | In Progress|  |
| 4. Settings rework + Multisite + TrustedHosts + FailedEvents | 5/5 | Complete    | 2026-05-20 |
| 5. Documentation + marketplace launch | 17/17 | Complete   | 2026-07-03 |
| 6. ViewContent funnel — Shopaholic PDP + offer-switch | 7/7 | Complete    | 2026-05-28 |
| Launch Milestone | 0/2 | Deferred — awaits operator decision |  |

## Shipped Milestones

- ✅ **Prior milestone** — Shopaholic-coupled Meta Pixel + CAPI (2026-04-22 → 2026-05-14). Partial close: 28/50 requirements validated, 22 dropped on architecture pivot. Phases 1, 2, 3.1, 3.1-07, 3.1-08 complete; Phase 3 task 9 superseded by 3.1; Phase 4 + 5 dropped. Archived under `.planning/archive/` and `.planning/milestones/`.

## Backlog

Deferred to v2.1+ (see `REQUIREMENTS.md` — Future Requirements):

- **v2.1 MallAdapter** (MALL-01) — `OFFLINE\Mall\Models\Order` adapter; reference example in `docs/CUSTOM-ADAPTERS.md`
- **v2.1 MeloncartAdapter** (MELON-01) — requires paid Meloncart plugin install
- **v2.1 Additional Event::fire hooks** (EXT-01..05) — adapter.resolve, value.resolve, user_data.resolve, pixel.before_render, settings.lookup
- **v2.1 Debug / Test-Events panel** (DBG-01) — backend last-100 EventLog rows with payload preview
- **v2.x Ops integrations** (OPS-01..02) — Slack/email/Telegram dead-letter alerting
- **v2.x Auto PSL refresh** (PSL-01) — operator-opt-in cron for automatic PSL refresh
