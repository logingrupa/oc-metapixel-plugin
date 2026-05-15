# Project Research Summary — Logingrupa.Metapixel v2.0.0

**Project:** Logingrupa.Metapixel v2.0 — generic-event-tracking marketplace plugin
**Domain:** OctoberCMS marketplace plugin / Meta Pixel + Conversions API tracking (adapter pattern)
**Researched:** 2026-05-15
**Confidence:** HIGH

---

## Executive Summary

v2.0 is a **refactor pivot, not a rewrite**. v1.1.1 Shopaholic-coupled plugin (177 tests, 82.8% coverage, production-validated on nailscosmetics.{no,lv,lt}) keeps its entire I/O backbone — `MetaClient`, `PayloadBuilder`, `UserDataHasher`, `EventLogWriter` (with the polymorphic `subject_type` + UNIQUE race-fence designed in Phase 3.1 for exactly this pivot), `SiteResolver`, `SendCapiEvent`, `FailedEvent`, `EnsureFbpFbcCookies`, `PluginGuard`. What changes is the **subject resolution layer**: every Order-typed signature is replaced with an `EventSubjectAdapter` + `ValueResolver` interface pair, resolved at runtime through an `AdapterRegistry` singleton bound in the service container. Third-party plugins (MelonCart, custom carts, theme actions) register their own adapter from `Plugin::boot()` and inherit dedup + multi-site + dead-letter for free.

The competitive landscape is **near-empty on OctoberCMS**: no first-party Meta Pixel + CAPI plugin exists in the marketplace. Three viable cart ecosystems define the adapter target surface (Shopaholic 11k+ installs, OFFLINE Mall 11,783 installs, Meloncart paid first-party). v2.0 ships **three adapters**: ShopaholicAdapter (non-regression for live nailscosmetics.*), ThemeActionAdapter (Twig + Larajax — operators without a supported cart still get value), and MallAdapter (largest non-Shopaholic install base, clean `mall.cart.*` + `mall.checkout.*` event surface). MeloncartAdapter deferred to v2.1 (paid plugin, smaller install base, untested in this codebase). Stack delta is narrow: add `jeremykendall/php-domain-parser ^6.4` to generalize v1.x hardcoded HOST_INDEX_MAP, broaden PHP constraint to `^8.3 || ^8.4`, pin Graph API to `v23.0` (v20 expires 2026-09-24), apply Multisite trait to per-site `pixel_id` + `capi_access_token`.

Dominant risk: **cross-context resolution drift** (P-01, anchored to Phase 3.1-07 production bug orders 29802/29803) — adding adapters and Multisite Settings multiplies the surface where writer (admin queue) and reader (frontend) can read divergent values. Prevention is mechanical and locked at contract level: `EventSubjectAdapter::getSiteId()` MUST read from subject, never request context; PHPStan disallowed-calls ban `SiteManager::*` outside `SiteResolver`; integration tests assert deterministic resolution. Other four CRITICAL pitfalls (P-02 boot-order race, P-03 hidden Lovata imports outside adapter dir, P-04 namespace-rename orphan rows on legacy installs, P-05 EventLog `subject_type` alias ambiguity) all have concrete v1.x anchors and named prevention. Confidence HIGH because every architectural decision lifts from a pattern already shipping in this codebase.

---

## Key Findings

### Recommended Stack

v1.x dependency graph carries forward verbatim. v2.0 delta is small and surgical.

- **`jeremykendall/php-domain-parser ^6.4`** — new `require`. Replaces v1.x hardcoded HOST_INDEX_MAP with operator-supplied TrustedHosts allowlist + PSL-aware subdomain index derivation.
- **PHP `^8.3 || ^8.4`** — broaden from v1.x `^8.4`. Enforced via `phpstan.neon: phpVersion: 80300` + Rector `UP_TO_PHP_83` + disallowed-calls ban on `array_find`/`array_any`/`array_all`/`#[\Deprecated]`/property hooks/asymmetric visibility.
- **Composer `suggest:` for Lovata** — `lovata/shopaholic-plugin` + `lovata/ordersshopaholic-plugin` + `lovata/buddies-plugin` move from `require:` to `suggest:`. Boot-time auto-detection via `class_exists($name, false)`.
- **Graph API `v23.0`** — pinned. v20 expires 2026-09-24. v23 (May 2025) is mid-LTS choice, no breaking CAPI changes vs v20.
- **`Multisite` trait on Settings** — `$propagatable = []` (empty default per P-10 lock). Per-site `pixel_id` + `capi_access_token` overrides.

Confidence HIGH. Full details: [`STACK.md`](STACK.md).

### Expected Features

15 table-stakes + 10 differentiators + 13 explicit anti-features. Near-empty marketplace lane means differentiators (Debug/Test-Events panel, dedup verification view, per-site multi-pixel routing, custom-adapter authoring guide) directly inform marketing positioning.

**Must have (table stakes):** AdapterRegistry singleton + interfaces; ShopaholicAdapter (non-regression); ThemeActionAdapter; Multisite per-site pixel/token; TrustedHosts allowlist; FailedEvents Replay UI; Composer suggest pattern; PluginGuard empty-config policy; README install <10 min; en/lv/ru translations; CI matrix on PHP 8.3+8.4 × with/without Shopaholic ≥90% coverage; namespace rename + marketplace listing.

**Should have (differentiators):** Built-in Debug/Test-Events panel; dedup verification view; custom-adapter authoring guide; MallAdapter; EventLog UNIQUE race-fence as marketing point; dual PHP support + event_id propagation + cookie middleware as carry-forward marketing points.

**Defer to v2.1+:** MeloncartAdapter (paid, smaller base, untested); MicroCartAdapter (Theme covers it); Slack/email/Telegram alerting; Campaign pricing tiers; GA4/GTM multi-vendor routing.

Confidence HIGH for adapter event surfaces; MEDIUM for marketplace-buyer expectations. Full details: [`FEATURES.md`](FEATURES.md).

### Architecture Approach

Two new interfaces (`EventSubjectAdapter`, `ValueResolver`), one new singleton (`AdapterRegistry`), one generalized component (`PurchasePixel` → `SubjectPixel`), eight `Event::fire` extension points. Plugin discovery: **self-registration from each adapter plugin's own `Plugin::boot()`** (Lovata sub-plugin precedent — Properties, Reviews, Labels).

Major components:
1. `AdapterRegistry` — service-container singleton, two-phase boot (register stores class names, resolve instantiates lazily)
2. `EventSubjectAdapter` — `getSubjectType()` (opaque alias string, NOT FQN), `getSubjectId()`, `getSiteId()` (MUST read from subject), `getSecretKey()`, `getValueResolver()`, `getUserData()`, `getSupportedEvents()`
3. `ValueResolver` — `resolveContentIds()`, `resolveValue()`, `resolveCurrency()`, `resolveContents()`, `resolveNumItems()`
4. ShopaholicOrderAdapter + ShopaholicOrderValueResolver — only directory allowed to import `Lovata\OrdersShopaholic\*`
5. ThemeActionAdapter + ThemeActionEvent + ThemeEventCollector + ThemeAjaxHandler — Twig + Larajax hybrid, CSRF + allowlist + rate-limit
6. MallAdapter + MallOrderValueResolver — events `mall.checkout.succeeded`, `mall.cart.product.added`, `mall.order.payment_state.changed`
7. Eight `Event::fire` extension points — adapter.resolve, value.resolve, user_data.resolve, event.before_dispatch, event.after_dispatch, event.dead_letter, pixel.before_render, settings.lookup

Confidence HIGH. Full details: [`ARCHITECTURE.md`](ARCHITECTURE.md).

### Critical Pitfalls

20 catalogued: 5 CRITICAL, 7 HIGH, 6 MEDIUM, 2 LOW. Every pitfall cites a v1.x mistake.

1. **P-01 Cross-context resolution drift (CRITICAL)** — Phase 3.1-07 anchor. Prevent: PHPStan disallowed-calls bans `SiteManager::*`/`request()`/`Request::*` in queue/event/adapter dirs; contract test asserts deterministic `getSiteId()`.
2. **P-02 Boot-order race (CRITICAL)** — Lazy resolve + order-agnostic register; `Plugin::boot()` MUST NOT call `AdapterRegistry::resolveFor()` during boot.
3. **P-03 Lovata imports outside adapter dir (CRITICAL)** — Composer suggest insufficient alone. Prevent: CI Run B (minimal install) must boot + tests green; `shipmonk/composer-dependency-analyser`; PHPStan disallowed-classes; pre-commit grep.
4. **P-04 Namespace rename orphans on legacy installs (CRITICAL)** — Dedicated BC migration phase (system_settings copy, system_plugin_versions identifier rewrite preserving `last_version='1.1.1'`, lang fallback shim, DB-copy smoke test).
5. **P-05 EventLog subject_type alias ambiguity (CRITICAL)** — Lock in Phase 2 as opaque alias string (`'shopaholic.order'`), NOT class FQN. Migrate legacy rows in BC phase.
6. **P-06 PHP 8.4-only syntax slips (HIGH, treat as CRITICAL)** — CI matrix `php: [8.3, 8.4]`; `phpstan: phpVersion: 80300`; Rector `UP_TO_PHP_83` with Php84 OFF; Pint nullable rule; pre-commit grep.
7. **P-15 TrustedHosts marketplace blocker (launch-gating)** — v1.x HOST_INDEX_MAP only knows nailscosmetics.*. Phase 4 must ship before public launch.

Other HIGH/CRITICAL: P-07 PDP IDNA2008 wrap, P-09 Larajax open-relay (CSRF + allowlist + rate-limit), P-10 Multisite `$propagatable` leak, P-11 plain-PHP autoload sanity, P-13 Event::fire envelope contract, P-14 paid_status_code split across adapters, P-16 system_plugin_versions ledger identifier rewrite, P-17 dual-install operator manual cleanup.

Confidence HIGH on identification; MEDIUM-HIGH on prevention. Full details: [`PITFALLS.md`](PITFALLS.md).

---

## Implications for Roadmap

**Suggested phase structure: 5 phases.**

### Phase 1: Tooling + composer.json + namespace rename
**Rationale:** CI matrix gates every subsequent PR; rename touches every file so first avoids double-renames.
**Delivers:** Renamed plugin dir + namespace, composer.json v2.0 shape, phpstan `phpVersion: 80300`, Rector `UP_TO_PHP_83`, three-tier test base (MetapixelTestCase + ShopaholicAdapterTestCase + ThemeActionAdapterTestCase), CI matrix Run A (full Lovata) + Run B (minimal), coverage threshold 90% on Run A.
**Avoids:** P-03, P-04, P-06, P-12, P-19, P-20.
**Scope flag:** May split 1a/1b if v1.x test-adapt cost bloats it.

### Phase 2: Adapter contract + AdapterRegistry + Event hooks
**Rationale:** Defines contracts every subsequent phase consumes. `subject_type` alias convention is highest-leverage decision.
**Delivers:** EventSubjectAdapter + ValueResolver interfaces, AdapterRegistry singleton (lazy-resolving, idempotent), 8 Event::fire hookpoints with immutable-envelope + mutable-extra contract, `SiteResolver::forSubject()`, signature refactors of `MetaClient::sendForPixel()`, `PayloadBuilder::buildEventPayload()`, `UserDataHasher::forSubject()`, `SendCapiEvent` constructor. 177 v1.x tests adapt via `FakeAdapter`.
**Avoids:** P-01, P-02, P-05, P-08, P-13.

### Phase 3: ShopaholicAdapter + MallAdapter + ThemeActionAdapter (parallel adapter wave)
**Rationale:** Three adapters share same shape; doing in one phase exercises contract three ways simultaneously.
**Delivers:** Three adapter trees, `Components\SubjectPixel`, `Plugin::registerMarkupTags()` for `this.metapixel.pushEvent` Twig helper, EVENT_NAME_ALLOWLIST + CSRF + rate-limit + JS-escape on Larajax endpoint, per-adapter Settings models.
**Avoids:** P-03, P-05, P-09, P-11, P-14.
**Scope flag:** If MallAdapter slips, Phase 5 absorbs. ShopaholicAdapter + ThemeActionAdapter non-negotiable here.

### Phase 4: Settings rework — Multisite + TrustedHosts + PDP + FailedEvents UI
**Rationale:** Multi-pixel closes multi-site loop. TrustedHosts is marketplace blocker. PDP is riskiest single piece (P-07) — needs own phase.
**Delivers:** Multisite trait on Settings with `$propagatable = []` lock, `Settings::lookupForSite($iSiteId)`, `trusted_hosts` Settings field, `HostIndexResolver` wrapping PDP, shipped `resources/data/public_suffix_list.dat` + `metapixel:refresh-psl` artisan, PSL cache in `storage/app/metapixel/psl/`, `Controllers\FailedEvents` backend list with Replay + dedup-status, en/lv/ru translations, multisite migration + per-site backfill.
**Avoids:** P-07, P-10, P-15, P-18.

### Phase 5: BC migration + README + custom-adapter guide + marketplace launch
**Rationale:** BC migration is production-blocker. README is launch gate. Custom-adapter guide unlocks v2.1 MeloncartAdapter ship.
**Delivers:** v1→v2 settings migration, paid_status_code→ShopaholicAdapter settings migration, lang namespace fallback shim, README install guide <10 min, `docs/CUSTOM-ADAPTERS.md` with working example, `docs/adapters/{shopaholic,mall,theme-action}.md`, marketplace assets, composer package rename, `v2.0.0` git tag.
**Avoids:** P-04, P-14, P-16, P-17.

### Research Flags

**Needs research at phase kickoff:**
- **Phase 2** — AdapterRegistry lazy-resolution + closure-binding spike; Event::fire reentrancy guard
- **Phase 4** — Multisite trait propagation on CommonSettings (no upstream test coverage); php-domain-parser IDNA2008 test matrix; per-field Multisite uncommon in Lovata

**Standard patterns (skip research):**
- Phase 1, Phase 3, Phase 5

---

## Confidence Assessment

| Area | Confidence | Notes |
|------|------------|-------|
| Stack | HIGH | Versions verified via Packagist + GitHub; PHP 8.4 features via php.net; Multisite trait via direct read of vendor source |
| Features | HIGH adapters; MEDIUM marketplace expectations | Mall + Meloncart + Shopaholic surfaces verified vs upstream. Buyer expectations extrapolated from PixelYourSite |
| Architecture | HIGH | Every decision lifts from existing codebase pattern. 2 MEDIUM areas flagged for spikes |
| Pitfalls | HIGH identification; MED-HIGH prevention | All 20 anchored to concrete v1.x evidence |

**Overall: HIGH.** v1.1.1 base production-validated; v2.0 is contract-shape refactor with surgical stack additions. Risk concentrates in Phase 4 (PDP + Multisite) and Phase 5 (BC migration).

### Gaps to address during planning

- `replaces:` plugin manifest support in October 4.x (verify Phase 1 kickoff)
- PSL data refresh cadence (ship file + artisan; defer auto-refresh to v2.x)
- Multisite trait `CommonSettings` propagation behavior (spike Phase 1 tooling, before Phase 4 commits)
- MallAdapter Phase 3 vs Phase 5 scope (roadmapper decides; recommend attempt-Phase-3 with mid-review)
- Meloncart adapter docs in v2.0 README (reference as v2.1 example; adapter itself slips)

---

## Sources

### Primary (HIGH confidence)
- v1.x archive: `.planning/archive/v1.1.1/phases/03.1-event-log-refactor/BRIEF.md`, `.../02-skeleton-cookie-fix/02-REVIEW.md` (CR-02..CR-05), `.../03.1-08-dead-code-cleanup/BRIEF.md`, `.planning/milestones/v1.1.1-ROADMAP.md`
- `.planning/PROJECT.md` (v2.0 locked decisions)
- `vendor/october/rain/src/Database/Traits/Multisite.php` (direct source read)
- `plugins/lovata/toolbox/models/CommonSettings.php`, `plugins/lovata/shopaholic/Plugin.php`, `plugins/lovata/ordersshopaholic/Plugin.php`, `plugins/logingrupa/storeextender/classes/event/cart/CartComponentHandler.php`
- [jeremykendall/php-domain-parser ^6.4](https://packagist.org/packages/jeremykendall/php-domain-parser)
- [PHP 8.4 Migration / Deprecations](https://www.php.net/manual/en/migration84.new-features.php)
- [Meta Graph API Versions changelog](https://developers.facebook.com/docs/graph-api/changelog/versions/)
- [OFFLINE Mall events](https://offline-gmbh.github.io/oc-mall-plugin/development/core/events.html), [Meloncart hooks](https://meloncart.com/developer/hooks/events)
- [OctoberCMS 4.x Multisite docs](https://docs.octobercms.com/4.x/cms/resources/multisite.html), [Composer suggest schema](https://getcomposer.org/doc/04-schema.md#suggest)

### Secondary (MEDIUM)
- v1.x source `classes/` + `components/` + `middleware/` + `models/`
- [OctoberCMS plugins/e-commerce](https://octobercms.com/plugins/e-commerce), [marketplace developer guide](https://octobercms.com/help/guidelines/developer)
- [Meta Pixel Reference](https://developers.facebook.com/docs/meta-pixel/reference/), [PixelYourSite WordPress plugin](https://wordpress.org/plugins/pixelyoursite/)

---
*Research completed: 2026-05-15*
*Ready for roadmap: yes*
