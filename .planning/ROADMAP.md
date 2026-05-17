# Roadmap: Logingrupa.Metapixel

## Active Milestone: v2.0.0 — Generic-event-tracking marketplace plugin

**Phases:** 5 (Phase 1 → Phase 5)
**Granularity:** coarse
**Coverage:** 61/61 v2 requirements mapped (100%)
**Started:** 2026-05-15
**Numbering:** Fresh start at Phase 1 — v1.x phases archived under `.planning/archive/v1.1.1/phases/`. v2.0 is its own milestone; v1.x history not folded into v2.0 numbering.

**Build philosophy (locked):** Simple logic, fresh ideas, no over-engineering. No BC shims to v1.x — operators stay on `legacy/v1.1.1` branch indefinitely. No dead code, no unused functions, no premature abstractions. Class names describe purpose, not shape. v2.0 adapters = FRESH implementations using v2.0 contracts, NOT v1.x ports. All host fixtures generic (`example.test`, `example.co.uk` per RFC 2606) — no operator-specific names baked in.

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
- [ ] **Phase 2: Adapter system core — contracts + registry + extension hooks** — `EventSubjectAdapter` + `ValueResolver` + `AdapterRegistry` + 3 `Event::fire` hooks; v1.x I/O backbone refactored behind adapter signatures; 177 tests adapted via FakeAdapter.
- [ ] **Phase 3: ShopaholicAdapter + ThemeActionAdapter parallel wave** — Non-regression port of v1.x Order/Cart logic behind ShopaholicAdapter; generic theme-action tracking via Twig + Larajax for operators without a supported cart.
- [ ] **Phase 4: Settings rework — Multisite + TrustedHosts + Cookie + FailedEvents + translations** — Per-site `pixel_id`/`capi_access_token`; operator-supplied `trusted_hosts` + PSL-aware index derivation; FailedEvents backend UI; en/lv translations.
- [ ] **Phase 5: Documentation + marketplace launch** — README install guide (<10 min), custom-adapter authoring guide, marketplace assets, `v2.0.0` tag, `composer require` green on clean OctoberCMS 4.x.

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

**Plans:** TBD

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

**Plans:** TBD

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

**Plans:** TBD

### Phase 5: Documentation + marketplace launch

**Goal:** A buyer on a clean OctoberCMS 4.x install runs `composer require logingrupa/oc-metapixel-plugin` and reaches their first verified CAPI event in Meta Test Events within 10 minutes by following the README. A third-party developer authors a custom adapter against `docs/CUSTOM-ADAPTERS.md` with a working `AcmeCartAdapter` reference example. The plugin ships as a Composer package on the private GitHub repo with `v2.0.0` annotated tag, marketplace assets (icon + 5 screenshots + CHANGELOG.md), and `composer qa` exits 0 on both CI matrix branches.

**Depends on:** Phase 4 (TrustedHosts marketplace blocker **P-15** must be closed before any external operator install; Settings UI + translations must be production-shaped for README walkthroughs).

**Requirements:** DOCS-01, DOCS-02, DOCS-03, MKT-01, MKT-02, MKT-03, MKT-04, MKT-05

**Success Criteria** (what must be TRUE):
  1. A timed dry-run on a fresh OctoberCMS 4.x install (no cart plugin) following only the README — `composer require` → Settings configuration → first CAPI event verified in Meta Test Events — completes in under 10 minutes. This dry-run is the launch acceptance gate.
  2. `docs/CUSTOM-ADAPTERS.md` contains a working ~50-LOC `AcmeCartAdapter` + `AcmeCartValueResolver` example documenting the `AdapterRegistry::register()` pattern, `$require` plugin dependency declaration, and the three `Event::fire` hooks. A developer copying the example, swapping cart-model names, and registering from their own `Plugin::boot()` ships a working third-party adapter without touching plugin core.
  3. `composer require logingrupa/oc-metapixel-plugin` succeeds on (a) clean OctoberCMS 4.x with no cart plugin, (b) clean OctoberCMS 4.x with Shopaholic + OrdersShopaholic + Buddies. `composer qa` exits 0 on both. CI matrix Run A + Run B remain green on the `v2.0.0` tag commit.
  4. Plugin manifest (`plugin.yaml`) ships generic name "Meta Pixel + Conversions API", generic description, generic icon. Marketplace assets present: plugin icon (PNG), 5 screenshots (Settings, FailedEvents list, Replay flow, dedup verification, theme Twig API usage), CHANGELOG.md documenting v2.0.0 changes vs `legacy/v1.1.1` branch.
  5. Git tag `v2.0.0` annotated and pushed to remote; `legacy/v1.1.1` branch preserved on origin (operator may stay on legacy indefinitely — no BC shim, no upgrade migration in v2.0).

**Plans:** TBD

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

(Pitfalls P-04, P-14, P-16, P-17 — BC migration — DROPPED for v2.0: no upgrade path. Operators stay on `legacy/v1.1.1` branch.)

## Progress

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Tooling + composer + namespace rename + CI matrix | 3/3 | Executed — pending verification | 2026-05-16 |
| 2. Adapter system core | 0/8 | Planned (PASS-WITH-NOTES) | — |
| 3. ShopaholicAdapter + ThemeActionAdapter | 0/0 | Not started | — |
| 4. Settings rework + Multisite + TrustedHosts + FailedEvents | 0/0 | Not started | — |
| 5. Documentation + marketplace launch | 0/0 | Not started | — |

## Shipped Milestones

- ✅ **v1.1.1** — Shopaholic-coupled Meta Pixel + CAPI (2026-04-22 → 2026-05-14). Partial close: 28/50 v1 requirements validated, 22 dropped on architecture pivot. Phases 1, 2, 3.1, 3.1-07, 3.1-08 complete; Phase 3 task 9 superseded by 3.1; Phase 4 + 5 dropped. Codebase frozen on `legacy/v1.1.1` branch. See [`milestones/v1.1.1-ROADMAP.md`](milestones/v1.1.1-ROADMAP.md) and [`milestones/v1.1.1-REQUIREMENTS.md`](milestones/v1.1.1-REQUIREMENTS.md).

## Backlog

Deferred to v2.1+ (see `REQUIREMENTS.md` — Future Requirements):

- **v2.1 MallAdapter** (MALL-01) — `OFFLINE\Mall\Models\Order` adapter; reference example in `docs/CUSTOM-ADAPTERS.md`
- **v2.1 MeloncartAdapter** (MELON-01) — requires paid Meloncart plugin install
- **v2.1 Additional Event::fire hooks** (EXT-01..05) — adapter.resolve, value.resolve, user_data.resolve, pixel.before_render, settings.lookup
- **v2.1 Debug / Test-Events panel** (DBG-01) — backend last-100 EventLog rows with payload preview
- **v2.x Ops integrations** (OPS-01..02) — Slack/email/Telegram dead-letter alerting
- **v2.x Auto PSL refresh** (PSL-01) — operator-opt-in cron for automatic PSL refresh
