# Phase 4: Settings rework — Multisite + TrustedHosts + Cookie + FailedEvents + translations — Research

**Researched:** 2026-05-19
**Domain:** OctoberCMS v4 / Laravel 12 plugin — Multisite Settings, PSL-aware host parsing, HTTP cookie middleware, backend ListController + AJAX, i18n
**Confidence:** HIGH (settings/multisite, middleware, controllers patterns verified in-tree against `vendor/october/rain/src/Database/Traits/Multisite.php`, `modules/system/models/SettingModel.php`, sibling Lovata controllers, Laravel `HttpKernel`). MEDIUM (Meta Dataset Quality endpoint surface — exact JSON shape requires live-pixel verification; planner builds a thin Graph wrapper with response parsing that tolerates schema drift).

---

<user_constraints>

## User Constraints (from CONTEXT.md)

### Locked Decisions

**Multisite credential resolution + migration semantics (Area 1)**
- **D-01:** `Settings::lookupForSite(?int $iSiteId): array` falls back **silently** to the default-row value when the per-site row's `pixel_id` (or `capi_access_token`) is empty string OR NULL. Multisite trait reads the per-site row first; treat both `''` and `null` as "not configured for this site". Operator configures the default row once and every site inherits until it overrides. No PluginGuard "disabled per site" branch.
- **D-02:** `Settings::lookupForSite` becomes the ONLY credential-lookup contract in the codebase. PHPStan disallowed-calls config gains rules banning direct `Settings::get('pixel_id')` / `Settings::get('capi_access_token')` reads anywhere outside `Settings::lookupForSite` itself.
- **D-03:** `MULT-06` migration `updates/add_multisite_pixel_id_and_token.php` is **schema-additive only** — Multisite trait operates at the model-row layer (one row per site keyed by `site_id`), not via new columns on `system_settings`. Migration body: idempotent `Schema::hasTable` guard + no-op when already migrated. Marketplace fresh-install on single-site OctoberCMS sees zero behavior change.
- **D-04:** **MULT-05 test = Pest integration with hermetic SQLite + 2 fake Site rows.** Test setUp seeds 2 site rows (id=1 + id=2). FakeAdapter::getSiteId returns 1 for Site-A subject + 2 for Site-B subject. Inserts EventLog rows for both sites + asserts UNIQUE(subject_type, subject_id, event_name, channel, site_id) constraint allows both (NULL-distinct semantics). 8-path matrix: 2 sites × 2 adapters × 2 channels.

**FailedEvents UI + Replay/CheckDedup execution model (Area 2)**
- **D-05:** **Replay = synchronous MetaClient call.** Controller action `onReplay($iId)` resolves the FailedEvent row, hydrates the adapter via `AdapterRegistry::resolveByClass($obRow->adapter_type)`, calls `MetaClient::sendForPixel($sPixelId, $sToken, $arPayload)` inline, increments `attempts++`, flash-success on HTTP 200 OK, flash-error + write `graph_error` on failure. Replay button on each row + batch toolbar action.
- **D-06:** **CheckDedup writes inline columns.** Migration `updates/add_dedup_columns_to_failed_events.php` adds `dedup_pct DECIMAL(5,2) NULL`, `emq DECIMAL(4,2) NULL`, `dedup_checked_at DATETIME NULL` to `logingrupa_metapixel_failed_events`. Controller action `onCheckDedup($iId)` calls `MetaClient::fetchTestEventsStatus(...)`, parses JSON, writes the three columns, returns JSON for live list refresh.
- **D-07:** **Full batch toolbar** — checkbox-driven multi-select. Three batch actions: Replay (loops `onReplay`), CheckDedup (loops `onCheckDedup`), Delete. Bulk operations stay synchronous (one Graph API call per row in a loop) — acceptable because dead-letter table size stays small.
- **D-08:** **`controllers/FailedEvents.php`** extends `Backend\Classes\Controller` with `Backend.Behaviors.ListController` only — no FormController. Read-only audit UI. Action buttons live in `_list_toolbar.php` + per-row `recordOnClick`. `config_list.yaml` declares filters + columns. Backend menu registered in `Plugin::registerNavigation()` under "Settings" parent.

**PSL bundling + refresh model (Area 3)**
- **D-09:** **PSL ships bundled at composer install.** `resources/data/public_suffix_list.dat` committed to git. No auto-refresh cron — explicit `php artisan metapixel:refresh-psl`.
- **D-10:** **Stale PSL = log warning + continue.** `HostIndexResolver` constructor reads `filemtime(...)`; if age > 180 days, emits `Log::warning(...)` exactly once (request-scoped flag prevents log spam). Cookies still write.
- **D-11:** **`metapixel:refresh-psl` artisan command** — fetches `https://publicsuffix.org/list/public_suffix_list.dat`, validates non-empty + contains expected sentinel lines (`// ===BEGIN ICANN DOMAINS===`), atomic-rename to `resources/data/public_suffix_list.dat`, wipes `storage/app/metapixel/psl/` cache. Idempotent. Uses Guzzle (already a plugin dep). No composer post-install-cmd hook.
- **D-12:** **`HostIndexResolver`** is a stateless singleton bound in `Plugin::register()` via `App::singleton(HostIndexResolver::class)`. `resolve(string $sHost): ?int` returns the subdomain-index (1 for apex, 2 for `www.`, etc.) OR `null` for unresolvable host. Middleware treats `null` as "untrusted" → NO-OP.

**TrustedHosts UX + Settings tab structure (Area 4)**
- **D-13:** **`trusted_hosts` = simple textarea, one host per line.** Empty default. Mirrors `theme_custom_event_names` pattern from Phase 3.
- **D-14:** **STRICT validation in `Settings::beforeSave`.** Trim → lowercase → validate basic charset (`/^[a-z0-9.-]+$/`) → run through `HostIndexResolver::resolve()` → if PSL returns `null` (unknown TLD), reject the entire save with `Flash::error` listing rejected hosts. Operator who legitimately needs a brand-new ccTLD runs `php artisan metapixel:refresh-psl` first.
- **D-15:** **Settings tabs = 4-tab layout** — `tab.pixel_and_capi` / `tab.hosts_and_cookies` / `tab.theme_tracking` / `tab.advanced`. Drop v1.x tab name "Compliance".
- **D-16:** **`ensure_fbp_fbc_server_side` kill switch** = `switch` field, default `true`. COOK-01 lock — middleware short-circuits to no-op when toggled off.

**Translations + lang structure (Area 4 / LANG-01)**
- **D-17:** **RainLab.Translate-compatible nested structure** — `lang/en/lang.php` returns nested array. Twig + YAML access via `logingrupa.metapixel::lang.field.pixel_id`. Mirror in `lang/lv/lang.php`. NO RU file shipped.
- **D-18:** **Field labels fresh-written for marketplace audience.** LV translations: native (Latvian-fluent author), not machine-translated. Every UI string in this phase + Phase 3 ThemeTracking surface routes through lang files.
- **D-19:** **`LANG-01` coverage list** — Total ≈ 60 keys per language × 2 languages = 120 entries. Coverage gate: planner adds a Pest assertion that walks `lang/en/lang.php` array keys + checks `lang/lv/lang.php` exposes the same shape.

**Fresh code, NOT v1.x port (cross-cutting meta-decision)**
- **D-20:** **No legacy v1.x code reused.** Reuse v1.x DECISIONS (CR-02, CR-03, kill-switch semantics, 90-day TTL, `fb.{N}.{ts}.{rand}` format); re-derive code against new dependencies (HostIndexResolver injection, per-site `Settings::lookupForSite`, P-18 cache path).

### Claude's Discretion

- Migration filename conventions (`updates/2026_05_xx_add_*.php` snake_case per October pattern) — planner picks ordinal numbers based on existing `updates/version.yaml` sequence.
- Backend `_list_toolbar.php` button styling — October's `data-control="popup"` + `oc-icon-bolt` (Replay) / `oc-icon-shield` (CheckDedup) / `oc-icon-trash-o` (Delete).
- Exact PHPStan disallowed-calls rule wording for D-02 ban on direct `Settings::get('pixel_id'/'capi_access_token')` — planner writes the `phpstan.neon` patch.
- PSL parser `Rules` instance memoization shape (request-scoped vs. Laravel cache repository) — planner picks (request-scoped is sufficient).
- Lang key naming when collision — planner uses semantic group nesting per October convention.

### Deferred Ideas (OUT OF SCOPE)

- Per-row index override in `trusted_hosts` (Repeater) — v2.1.
- PSL auto-refresh weekly cron (`Plugin::registerSchedule` wire-up) — deferred per D-09.
- Sync execution timeout fallback to queued dispatch for Replay — deferred.
- RU translation file — operator self-services.
- FailedEvents dashboard widget (PSL age + dead-letter count) — Phase 5 polish.
- Settings export/import as YAML — Phase 5+.
- `metapixel:refresh-psl` as composer post-install-cmd hook — rejected.

</user_constraints>

<phase_requirements>

## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| MULT-01 | `models/Settings.php` adds `use Multisite;` trait. `protected $propagatable = []`. | §"Multisite mechanics on SettingModel" — trait is already in `Lovata\Toolbox\Models\CommonSettings` via `October\Rain\Database\Traits\Multisite`; Settings already declares `$propagatable = []` (Phase 2 lock). MULT-01 is a no-op confirmation task. |
| MULT-02 | `pixel_id` + `capi_access_token` marked per-site via Multisite trait (NOT in `$propagatable`). | §"Multisite mechanics on SettingModel" — keeping `$propagatable = []` is the active enforcement; per-site routing happens automatically by `getCacheKey()` `-{site_id}` suffix on `SettingModel`. |
| MULT-03 | `Settings::lookupForSite(?int): array{pixel_id, capi_access_token}` honors per-site row + falls back to default row on null/empty per-site values (D-01). | §"Lookup contract — null-safe site row resolution" — uses `Site::withContext($iSiteId, ...)` from October Site facade to scope `SettingModel::get()`. |
| MULT-04 | `SendCapiEvent::handle()` resolves credentials via `Settings::lookupForSite($iSiteId)`. | Already wired in Phase 2 (`classes/queue/SendCapiEvent.php:119`). No change. |
| MULT-05 | Multi-pixel routing integration test: 2 sites × 2 adapters × 2 channels = 8-path matrix. | §"Validation Architecture" — Pest spec uses fake site rows in hermetic SQLite. |
| MULT-06 | `updates/add_multisite_pixel_id_and_token.php` migration — schema-additive only (D-03). | §"Migration shape" — body is essentially a no-op (Multisite operates at row layer, not column layer). |
| HOST-01 | `trusted_hosts` textarea field. Empty default; validates on save. | §"TrustedHosts field UX + strict beforeSave validation". |
| HOST-02 | `HostIndexResolver` wraps `jeremykendall/php-domain-parser ^6.4`. | §"php-domain-parser API surface". |
| HOST-03 | PSL data shipped at `resources/data/public_suffix_list.dat`. `metapixel:refresh-psl` artisan command updates from upstream. Cache at `storage/app/metapixel/psl/`. | §"PSL distribution + refresh model" + §"PSL cache path safety". |
| HOST-04 | `EnsureFbpFbcCookies` middleware reads `trusted_hosts` + `HostIndexResolver`. Untrusted host → NO-OP. | §"Middleware shape — fresh derivation". |
| HOST-05 | Multi-TLD test matrix — apex `example.test`, `www.example.test`, `example.co.uk`, IDN `xn--bcher-kva.example`. | §"Validation Architecture — PSL fixture tests". |
| HOST-06 | Untrusted-host fail-safe test — middleware NO-OP, no exception. | §"Validation Architecture — middleware unit tests". |
| COOK-01 | `Settings::get('ensure_fbp_fbc_server_side', true)` kill switch. | §"Middleware shape — fresh derivation". |
| COOK-02 | CR-03 fbclid validation — `[A-Za-z0-9_-]` charset, ≤255 chars, invalid → skip `_fbc`. | §"Middleware shape — fresh derivation" + legacy reference for spec lock. |
| COOK-03 | `Cache-Control: private` documented as operator responsibility in README. Class-level PHPDoc references README. | §"Middleware shape — fresh derivation" (doc-only). |
| FAIL-01 | `Controllers\FailedEvents` extends `Backend\Classes\Controller` with `ListController` behavior. | §"Backend FailedEvents controller". |
| FAIL-02 | `onReplay($iId)` re-dispatches via `MetaClient` synchronously. | §"Replay + CheckDedup AJAX handlers". |
| FAIL-03 | `onCheckDedup($iId)` queries Meta Dataset Quality endpoint via `MetaClient::fetchTestEventsStatus(...)`. Writes inline columns. | §"Meta Dataset Quality endpoint". |
| LANG-01 | `lang/{en,lv}/lang.php` populated. RainLab.Translate-compatible structure. | §"Translation structure". |

</phase_requirements>

## Project Constraints (from CLAUDE.md)

Project (`/home/forge/nailscosmetics.lv/CLAUDE.md`):
- **Tech stack lock:** PHP 8.4, October CMS v4 (Laravel 12), Lovata Toolbox v2.2 backbone, RainLab.Translate v2.2.
- **Hungarian notation mandatory** for locals + methods. PHPMD `ShortVariable min=4`.
- **PSR-2** via `phpcs.xml`. Plugin uses Pint (Laravel preset) per `pint.json` — Pint runs only on plugin tree, not host tree.
- **Tiger-Style fail-fast:** throw at boundaries; catch only to log-and-rethrow OR dead-letter-persist; every `catch` documents reason. Bounded loops, bounded memory.
- **Composer:** Plugin is installable as `logingrupa/oc-metapixel-plugin` from a private GitHub repo.
- **No jQuery** in frontend code.
- **`/gsd:execute-phase` workflow enforcement** — all Edit/Write goes through GSD command.
- **Laravel Boost overlay:** `vendor/bin/pint --dirty --format agent` before finalizing PHP edits.
- **OctoberCMS-specific conventions** (from october/boost rules):
  - `php artisan create:controller Logingrupa.Metapixel FailedEvents` is the canonical scaffold.
  - Backend controllers use behaviors (FormController, ListController, RelationController) with YAML config.
  - `$jsonable` over `'array'` cast.
  - Model-based validation via `Validation` trait — internal log models (EventLog, FailedEvent) intentionally skip validation per plugin CLAUDE.md.
- **php-domain-parser cross-checks:** plugin CLAUDE.md lists `jeremykendall/php-domain-parser ^6.4` as the locked dependency. Version verified on packagist.

Plugin (`plugins/logingrupa/metapixel/CLAUDE.md`):
- **Namespace:** `Logingrupa\Metapixel`. Composer pkg `logingrupa/oc-metapixel-plugin`.
- **PHP support:** `^8.3 || ^8.4` dual — no 8.4-only syntax (no property hooks, no `array_find`/`array_any`/`array_all`, no `#[\Deprecated]`).
- **Adapter pattern carryforward:** generic core (`MetaClient`, `PayloadBuilder`, `UserDataHasher`, `EventLogWriter`) stays decoupled; Phase 4 only adds Settings/middleware/controller wiring.
- **Locked decisions** (Phase 4 honors verbatim): CR-02 TrustedHosts + PSL, CR-03 fbclid charset/length, PluginGuard warn-don't-throw, `$propagatable = []` empty whitelist, Graph API pinned `v23.0` (constant `META_GRAPH_API_VERSION`), Multisite trait on `pixel_id` + `capi_access_token` only.
- **Model property convention:** Laravel-standard names (`$jsonable`, `$casts`, `$rules`, `$fillable`, October relationship arrays) override Hungarian for those specific properties. Local vars + methods stay Hungarian.
- **Internal log/dead-letter models** (EventLog, FailedEvent) skip `Validation` trait + `$rules`. **The Phase 4 `Controllers\FailedEvents` is the first user-input boundary on FailedEvent (Replay/CheckDedup actions). Per CLAUDE.md: "Any future model with a user-input boundary MUST add the Validation trait + `$rules`." Planner must decide whether FailedEvent gains rules in Phase 4 OR whether the controller validates input itself (recommendation: controller-side validation; FailedEvent stays a write-only sink for `SendCapiEvent::writeFailedEvent` — Replay/CheckDedup only read by primary key, no user-supplied attribute write.) Document the decision in the plan.**
- **Build philosophy:** No over-engineering. No BC shims to v1.x. No dead code. Simple > clever. Five readable lines beat one clever line.
- **Tooling gate:** `composer qa` → `pint-test` → `phpstan analyse` (level 10, phpVersion 80300) → `phpmd` → `pest --coverage --min=90`. PHPStan disallowed-calls bans `assert()`, `@`, PHP 8.4-only fns, `request()` + `SiteManager::*` + `Site::*` in `classes/queue/*`, `classes/event/*`, `classes/adapter/*`.
- **NO `assert()` ANYWHERE.** Production `zend.assertions=0` silently no-ops. Use explicit `throw`.
- **NO `declare(strict_types=1)` enforcement.** Optional per file.
- **Lowercase folder convention** under `plugins/<vendor>/<plugin>/`. October Rain ClassLoader normalises namespaced PSR-style lookups by lowercasing every folder portion before the file basename.
- **Migration file naming:** **PascalCase basenames matching class FQN** (H-5 spike resolution from Phase 2). Plugin cannot run standalone `composer install`; tests/phpstan need FQN-loadable migration classes via October Rain ClassLoader's `loadUpperOrLower` upper-class branch. **Apply this to all Phase 4 migrations** (`updates/AddMultisitePixelIdAndToken.php`, `updates/AddDedupColumnsToFailedEvents.php`).
- **Migration `version.yaml` snake_case file references** — runtime migration path does not need autoload; October's `Updater::resolve` requires the file directly from the path. Plugin currently has `updates/CreateMetapixelEventLogTable.php`, `updates/CreateMetapixelFailedEventsTable.php`, `updates/AddPayloadToMetapixelEventLogTable.php` — all PascalCase. Phase 4 follows the same.
- **PHPStan `@phpstan-ignore` is banned project-wide.** When level 10 narrowing fails on `json_decode` mixed-return or similar, extract a private helper that walks the decoded shape with explicit type assertions. Pattern proven in Phase 2: `MetaClient::decodeBody`, `Settings::lookupForSite`'s runtime guard.

## Summary

Phase 4 closes the marketplace-launch blocker (P-15 TrustedHosts host-spoofing) by replacing the v1.x hardcoded `HOST_INDEX_MAP` constant with an operator-supplied `trusted_hosts` allowlist + a PSL-aware `HostIndexResolver` that derives the correct subdomain-index for any multi-TLD host (`.co.uk`, `.com.br`, IDN). It also unlocks per-site `pixel_id` + `capi_access_token` via the Multisite trait already present on `Lovata\Toolbox\Models\CommonSettings`, ships a fresh `EnsureFbpFbcCookies` middleware against the new dependencies, lands the backend `Controllers\FailedEvents` audit + Replay + CheckDedup admin UI, and populates the `lang/{en,lv}/lang.php` translation surface.

The technical stack is fully verified in-tree: `October\Rain\Database\Traits\Multisite` (`vendor/october/rain/src/Database/Traits/Multisite.php`) + `System\Models\SettingModel\HasMultisite` (`modules/system/models/settingmodel/HasMultisite.php`) handle per-site rows automatically once a model declares `$propagatable = []` (which `Settings` already does, Phase 2 lock). `jeremykendall/php-domain-parser` v6.4.0 is the locked PSL parser (verified on packagist, 13.4M installs, MIT license). October Rain's `Foundation\Http\Kernel` still exposes Laravel's `pushMiddleware` API for `Plugin::boot()`-time global middleware registration. The Lovata.OrdersShopaholic `PaymentMethods` backend controller is the canonical sibling example for the `Controllers\FailedEvents` ListController shape.

The Meta Conversions API dedup-status endpoint is on the **Dataset Quality API** surface — `GET /v23.0/{pixel_id}/?fields=...` — not on a v1.x `test_events_received` path. The exact response shape requires live-pixel verification; planner ships a tolerant JSON parser that pulls `event_match_quality` + dedup-rate fields by name with sensible null fallbacks.

**Primary recommendation:** Plan four execution waves: (1) `models/Settings.php` extends fields.yaml to 4-tab + `trusted_hosts` textarea + `ensure_fbp_fbc_server_side` switch + lookupForSite per-site implementation + strict beforeSave validation, (2) `classes/helper/HostIndexResolver.php` + `console/RefreshPsl.php` + bundled `resources/data/public_suffix_list.dat`, (3) `middleware/EnsureFbpFbcCookies.php` fresh implementation + `Plugin::boot` middleware registration, (4) `controllers/FailedEvents.php` + `controllers/failedevents/` views + dedup-column migration + Plugin::registerNavigation + lang/{en,lv}/lang.php populated.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Per-site `pixel_id` / `capi_access_token` storage | Database (system_settings rows + site_id column) | Backend (October Settings UI top-bar site picker) | `SettingModel` + `HasMultisite` trait owns row-per-site segregation; UI is a presentational shell. |
| `Settings::lookupForSite(?int): array` credential contract | API / Backend (PHP service layer, `models/Settings.php`) | — | Pure server-side helper — never called from browser. Phase 2 already wired callsite. |
| Multisite migration (`AddMultisitePixelIdAndToken`) | Database (no-op idempotent guard) | — | Schema-additive only per D-03. Trait operates at row layer. |
| `trusted_hosts` allowlist storage | Database (`system_settings` JSON field) | Backend (Settings textarea) | Textarea → newline string in expando JSON value. Mirrors `theme_custom_event_names` (Phase 3 lock). |
| `HostIndexResolver` PSL parsing | API / Backend (PHP service singleton, `classes/helper/`) | — | Pure logic, request-scoped memoization. No frontend exposure. |
| PSL data file distribution | CDN / Static (`resources/data/public_suffix_list.dat` shipped via Composer) | Backend (`metapixel:refresh-psl` Artisan command) | File ships in plugin git tree; refresh is operator-initiated CLI. |
| PSL cache (parsed `Rules` instance) | Database / Storage (`storage/app/metapixel/psl/`) | API / Backend (in-process singleton) | Forge-writable shared path (P-18 prevention). |
| `EnsureFbpFbcCookies` middleware (write `_fbp` / `_fbc` cookies) | API / Backend (Laravel HTTP middleware) | Browser (cookie consumed by `fbevents.js`) | Server writes; browser reads — server-side authoritative per kill-switch. |
| fbclid validation (CR-03) | API / Backend (middleware regex + length check) | — | Server-side gating at cookie-write boundary. |
| `Controllers\FailedEvents` (List + Filters) | Backend (October ListController) | Database (queries `logingrupa_metapixel_failed_events`) | Standard October backend behavior pattern. |
| Replay action (synchronous CAPI re-dispatch) | API / Backend (controller AJAX handler → MetaClient) | — | Inline Graph API call; no queue. |
| CheckDedup action (Graph API dataset quality query) | API / Backend (controller AJAX handler → MetaClient) | Database (writes 3 columns on FailedEvent row) | Inline call writes inline columns; no separate dedup_logs table. |
| `lang/{en,lv}/lang.php` translation files | API / Backend (file-based PHP arrays consumed by `Lang::get` + `|_` Twig filter) | — | RainLab.Translate-compatible structure. No DB layer. |
| Backend menu registration | Backend (`Plugin::registerNavigation` returning menu array) | — | Standard October hook. |

## Standard Stack

### Core

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `october/system` | `^4.0` | OctoberCMS v4 host. Provides `SettingModel`, `Backend\Classes\Controller`, ListController behavior, `PluginBase`, navigation registration, `Site` facade, `SiteManager`. | Required by plugin (existing dep). |
| `lovata/toolbox-plugin` | `^2.2` | Lovata.Toolbox `CommonSettings` base class (carries `Multisite` trait + `RainLab.Translate.Behaviors.TranslatableModel` implement + `$propagatable` property). | Existing plugin require. Settings extends CommonSettings. |
| `jeremykendall/php-domain-parser` | `^6.4` (latest 6.4.0, 2025-04-26) | PSL-aware host parser. `Pdp\Rules::fromPath()`, `Pdp\Domain::fromIDNA2008($sHost)`, `Rules::resolve($obDomain)` → `ResolvedDomainName` with `subDomain()`, `secondLevelDomain()`, `registrableDomain()`, `suffix()`. | Locked decision (plugin CLAUDE.md). v6.4.0 confirmed on packagist (13.4M installs, MIT, ext-intl required). |
| `guzzlehttp/guzzle` | `^7.8` (existing dep) | HTTP client for `metapixel:refresh-psl` artisan + `MetaClient::fetchTestEventsStatus` Graph API call. | Already pinned in plugin composer.json. |
| `illuminate/contracts` (Laravel 12) | `^12.0` (via october/all) | `Illuminate\Contracts\Http\Kernel` resolved from container for `pushMiddleware`; `Illuminate\Support\Facades\Lang` for i18n; `Illuminate\Support\Facades\App` for `Site::withContext`. | Laravel core. |

### Supporting

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `october/rain` | `^4.0` (existing via october/system) | `October\Rain\Database\Traits\Multisite` trait, `Site` facade (`October\Rain\Support\Facades\Site`), `MultisiteScope`. | Multisite per-site row routing. |
| `system/models/SettingModel/HasMultisite` (trait) | n/a — core module | Hooks `settingMultisiteBeforeSave` + `settingMultisiteInitSettingsData` lifecycle into Multisite-enabled SettingModel descendants. | Auto-applied by `SettingModel` base — verified at `modules/system/models/SettingModel.php:25-26`. |
| RainLab.Translate behavior | v2.2 (host) | `@RainLab.Translate.Behaviors.TranslatableModel` is auto-implemented by `CommonSettings::$implement`. Used by Settings `commentAbove` translation. | Already in effect. |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `jeremykendall/php-domain-parser` | `pdp/pdp` (v5) | v5 deprecated, no IDNA2008 support → fails on IDN hosts. Locked decision. |
| `jeremykendall/php-domain-parser` | hand-rolled `count(explode('.', $sHost)) - 1` | Wrong for `.co.uk` / `.com.br` (counts public suffix as subdomain) + exploitable via Host-header spoof. CR-02 lock rejects. |
| `Plugin::boot` + `pushMiddleware` | Add to `bootstrap/app.php` Middleware closure | `bootstrap/app.php` is application-wide; plugin must not edit host bootstrap (Forge zero-downtime breakage). `pushMiddleware` from plugin Plugin.php is canonical OctoberCMS pattern. |
| `ListController` only | `ListController + FormController` | No edit/create UI needed (FailedEvent rows written by `SendCapiEvent::writeFailedEvent` only). D-08 lock. |
| Replay via queue | Replay synchronously | D-05 lock. Admin tab blocks 1-3s; matches every other backend save UX. No queue dependency for rare admin action. |
| Separate `dedup_logs` table | Inline columns on `failed_events` | D-06 lock. Schema cost = 3 nullable columns on low-row-count table. |
| Cron PSL refresh | Operator-explicit artisan command | D-09 lock. Avoids cron-reliability concern. |

**Installation:**

```bash
# Add to plugin composer.json `require:`
composer require jeremykendall/php-domain-parser:^6.4
```

**Version verification (packagist):**

```bash
$ composer show -a jeremykendall/php-domain-parser
name     : jeremykendall/php-domain-parser
versions : ..., 6.4.0, 6.3.1, 6.3.0, ...
license  : MIT License
source   : https://github.com/jeremykendall/php-domain-parser
```

Last release: 2025-04-26 (≈ 13 months stable at research time).

## Package Legitimacy Audit

> slopcheck CLI was not available in the test environment. Per protocol, every recommended package is tagged `[ASSUMED]` and the planner MUST gate each install behind a `checkpoint:human-verify` task before running `composer require`. Disposition reflects manual cross-checks against authoritative sources.

| Package | Registry | Age | Downloads | Source Repo | slopcheck | Disposition |
|---------|----------|-----|-----------|-------------|-----------|-------------|
| `jeremykendall/php-domain-parser` | packagist | 14+ yrs (v6.4.0 released 2025-04-26; package since 2011) | 13.4M total | github.com/jeremykendall/php-domain-parser (98401b32, MIT) | not run — graceful degradation | Approved (pending checkpoint:human-verify) |
| `guzzlehttp/guzzle` | packagist | 14+ yrs | (Laravel ecosystem core) | github.com/guzzle/guzzle | not run | Already in plugin require (Phase 2). No change. |

**Packages removed due to slopcheck [SLOP] verdict:** none.
**Packages flagged as suspicious [SUS]:** none — graceful-degradation `[ASSUMED]` only.

**Planner action:** insert a `checkpoint:human-verify` task with prompt "Confirm `jeremykendall/php-domain-parser ^6.4` matches the package at `https://packagist.org/packages/jeremykendall/php-domain-parser` (verified MIT, ~13.4M installs, last release 2025-04-26)" before the `composer require` task.

## Architecture Patterns

### System Architecture Diagram

```
[Incoming HTTP request hits Laravel HTTP Kernel]
        │
        v
[Symfony Request → routing pipeline]
        │
        v
[Plugin::boot() at app boot earlier has called:
   $this->app[Illuminate\Contracts\Http\Kernel::class]
       ->pushMiddleware(EnsureFbpFbcCookies::class)]
        │
        │ AFTER session middleware (so response headers are mutable)
        v
[EnsureFbpFbcCookies::handle(Request, Closure $fnNext)]
        │
        │ $obResponse = $fnNext($obRequest)      # let inner pipeline run first
        │
        │ shouldSkip(): backend path? | App::bound('metapixel.disabled')?
        │                | Settings::get('ensure_fbp_fbc_server_side', true) == false?
        │                ├── yes → return $obResponse untouched (no cookies)
        │                └── no →
        │
        │ $arHosts = explode("\n", Settings::get('trusted_hosts'))
        │                       ├── empty → return (operator hasn't configured)
        │                       └── populated →
        │
        │ $sHost = strtolower($obRequest->getHost())
        │                       ├── $sHost NOT in $arHosts → return (untrusted)
        │                       └── trusted →
        │
        │ App::make(HostIndexResolver::class)->resolve($sHost)
        │                       ├── null (PSL doesn't know TLD) → return
        │                       └── int $iSubdomainIndex →
        │
        │ maybeSetFbp(...)  →  if request has no _fbp cookie:
        │                          response->headers->setCookie(
        │                              Cookie::create('_fbp',
        │                                  sprintf('fb.%d.%d.%s',
        │                                          $iSubdomainIndex,
        │                                          (int)(microtime(true)*1000),
        │                                          bin2hex(random_bytes(8))),
        │                                  expires=time()+90d, path='/',
        │                                  domain=null, secure=$obRequest->secure(),
        │                                  httpOnly=false, raw=false, sameSite='lax'))
        │
        │ maybeSetFbc(...)  →  read $sFbclid = $obRequest->query('fbclid', '')
        │                       ├── empty → skip
        │                       ├── >255 chars → skip (CR-03)
        │                       ├── preg_match('/^[A-Za-z0-9_-]+$/') fails → skip (CR-03)
        │                       ├── request already carries _fbc cookie → skip
        │                       └── write _fbc cookie with $iSubdomainIndex
        │
        v
[Response with _fbp + (optional) _fbc cookies returned to browser]


[ Browser fbevents.js reads _fbp + _fbc → forwards in fbq() calls ]
[ Adapter SendCapiEvent::handle forwards _fbp + _fbc in UserData payload ]
[ Meta CAPI receives identical _fbp/_fbc on both channels → match + dedup ]


────────────────────────────────────────────────────────────────────────────

[Backend admin browses to /backend/logingrupa/metapixel/failedevents]
        │
        v
[Controllers\FailedEvents extends Backend\Classes\Controller
   with Backend.Behaviors.ListController]
        │
        v
[config_list.yaml → columns + filters + checkboxes + recordUrl]
        │
        v
[List render → user clicks "Replay" on row → AJAX POST to onReplay($iId)]
        │
        │ $obRow = FailedEvent::findOrFail($iId)
        │ $obAdapter = AdapterRegistry::resolveByClass($obRow->adapter_type)
        │ $arCreds = Settings::lookupForSite( $obRow->{adapter-derived site_id} )
        │
        │ try { MetaClient::sendForPixel(pixel, token, payload) }
        │ catch (MetaApiPermanentException) { update graph_error, attempts++; flash }
        │ on 200 OK: attempts++, flash success (optionally delete row)
        │
        v
[Flash response + AJAX list refresh]


[ Or user clicks "CheckDedup" → AJAX POST to onCheckDedup($iId) ]
        │
        v
[MetaClient::fetchTestEventsStatus($sPixelId, $sToken, $sTestEventCode, $sEventId)]
        │ GET https://graph.facebook.com/v23.0/{pixel}/?fields=dataset_quality...
        │   (Dataset Quality endpoint; see "Meta Dataset Quality endpoint" section)
        v
[parse response → update FailedEvent row's dedup_pct, emq, dedup_checked_at columns]
        │
        v
[return JSON { dedup_pct, emq, checked_at } for ListController list refresh]
```

### Recommended Project Structure

```
plugins/logingrupa/metapixel/
├── Plugin.php                                  # boot: pushMiddleware + AdapterRegistry already-wired;
│                                                # registerSettings (existing), registerNavigation NEW
│                                                # (FailedEvents menu under Settings parent)
├── classes/
│   └── helper/
│       ├── HostIndexResolver.php               # NEW — PSL-wrapped subdomain index resolver
│       └── (existing: SiteResolver, EventLogWriter, PluginGuard)
├── classes/
│   └── meta/
│       └── MetaClient.php                      # MODIFY — add fetchTestEventsStatus() method
├── console/
│   ├── PurgeEventLog.php                       # existing
│   └── RefreshPsl.php                          # NEW — metapixel:refresh-psl artisan command
├── controllers/                                # NEW directory
│   ├── FailedEvents.php                        # ListController-only backend controller
│   └── failedevents/
│       ├── config_list.yaml                    # columns + filters + toolbar wiring
│       ├── _list_toolbar.htm                   # batch Replay / CheckDedup / Delete buttons
│       └── index.htm                           # standard list page partial
├── lang/
│   ├── en/lang.php                             # POPULATE — RainLab.Translate-compat nested keys
│   └── lv/lang.php                             # POPULATE — same structure
├── middleware/                                  # NEW directory
│   └── EnsureFbpFbcCookies.php                 # NEW — fresh derivation per D-20
├── models/
│   ├── Settings.php                            # MODIFY — extend lookupForSite, add trusted_hosts
│   │                                            #          beforeSave validation, ensure_fbp_fbc switch
│   └── settings/
│       ├── fields.yaml                         # MODIFY — re-tab to 4-tab layout + new fields
│       └── columns.yaml                        # (existing if any)
├── models/
│   └── failedevent/
│       └── columns.yaml                        # NEW — column metadata for ListController
├── resources/
│   └── data/
│       └── public_suffix_list.dat              # NEW — Mozilla PSL snapshot
├── updates/
│   ├── AddMultisitePixelIdAndToken.php         # NEW — schema-additive no-op (D-03)
│   ├── AddDedupColumnsToFailedEvents.php       # NEW — adds dedup_pct, emq, dedup_checked_at
│   └── version.yaml                            # APPEND — bump version + register migrations
```

### Pattern 1: Multisite mechanics on SettingModel

**What:** October's `SettingModel` automatically scopes settings per-site once the model gains the `MultisiteInterface` shape (via `Multisite` trait, already inherited from `CommonSettings`). The scoping happens transparently inside `SettingModel::getCacheKey()` which appends `-{site_id}` to the cache key and `SettingModel::newQuery()` which `where('item', settingsCode)` against the row, combined with the `MultisiteScope` global scope that injects `where('site_id', Site::getSiteIdFromContext())`.

**When to use:** Already in use. Phase 4 MULT-01..02 are no-op confirmations.

**Key files verified in-tree:**
- `vendor/october/rain/src/Database/Traits/Multisite.php` — declares trait + `$propagatable` property + lifecycle hooks (`multisiteBeforeSave`, `multisiteAfterCreate`, etc.).
- `modules/system/models/SettingModel.php` — uses `HasMultisite` trait (line 25), forces `getCacheKey()` to append `-{site_id}` (line 222-225) when model implements `MultisiteInterface`.
- `modules/system/models/settingmodel/HasMultisite.php` — `settingMultisiteBeforeSave` binds `site_root_id` to other-site model on save.
- `vendor/october/rain/src/Database/Scopes/MultisiteScope.php` — global scope that injects `where(site_id, Site::getSiteIdFromContext())`.
- `plugins/lovata/toolbox/models/CommonSettings.php` — Settings descends from this; `use Multisite;` is already present (line 13).

**Critical note:** `isClassInstanceOf` is structural duck-typing (`vendor/october/rain/src/Extension/ExtendableTrait.php:244` — checks methods exist). `Settings` already has `findOrCreateForSite()`, `isMultisiteEnabled()`, `isMultisiteSyncEnabled()` via the trait → already counts as implementing `MultisiteInterface`. MULT-01 is literally one line of config + a docblock update; no implementation work.

**Example (verbatim from `Lovata\Toolbox\Models\CommonSettings.php`):**

```php
namespace Lovata\Toolbox\Models;

use October\Rain\Database\Traits\Multisite;
use System\Models\SettingModel;

class CommonSettings extends SettingModel
{
    use Multisite;
    // …
    protected $propagatable = [];
}
```

Source: `plugins/lovata/toolbox/models/CommonSettings.php` lines 1-29.

### Pattern 2: Lookup contract — null-safe site row resolution

**What:** The Phase 2 `Settings::lookupForSite(?int $iSiteId): array` stub ignores `$iSiteId`. Phase 4 re-implements to: (a) read per-site row when `$iSiteId !== null`; (b) fall back to default-row value when per-site `pixel_id` or `capi_access_token` is empty/null; (c) preserve the public signature.

**When to use:** Every callsite that needs CAPI credentials at dispatch time. PHPStan disallowed-calls in D-02 bans direct `Settings::get('pixel_id')` outside `lookupForSite` itself.

**Implementation sketch:**

```php
// models/Settings.php
public static function lookupForSite(?int $iSiteId): array
{
    // Default-row read (no site scope context — global context).
    [$sDefaultPixel, $sDefaultToken] = self::readCredentialsInGlobalContext();

    if ($iSiteId === null) {
        return [
            'pixel_id' => $sDefaultPixel,
            'capi_access_token' => $sDefaultToken,
        ];
    }

    // Per-site row read — scope to site context.
    [$sSitePixel, $sSiteToken] = self::readCredentialsForSiteContext($iSiteId);

    return [
        // D-01: empty → fall back silently to default row.
        'pixel_id' => $sSitePixel !== '' ? $sSitePixel : $sDefaultPixel,
        'capi_access_token' => $sSiteToken !== '' ? $sSiteToken : $sDefaultToken,
    ];
}

private static function readCredentialsInGlobalContext(): array
{
    return Site::withGlobalContext(function (): array {
        Settings::clearInternalCache(); // bust SettingModel::$instances
        $mPixel = self::get('pixel_id', '');
        $mToken = self::get('capi_access_token', '');
        return [
            is_string($mPixel) ? $mPixel : '',
            is_string($mToken) ? $mToken : '',
        ];
    });
}

private static function readCredentialsForSiteContext(int $iSiteId): array
{
    return Site::withContext($iSiteId, function (): array {
        Settings::clearInternalCache();
        $mPixel = self::get('pixel_id', '');
        $mToken = self::get('capi_access_token', '');
        return [
            is_string($mPixel) ? $mPixel : '',
            is_string($mToken) ? $mToken : '',
        ];
    });
}
```

**Pitfall:** `SettingModel::$instances` is a static cache that persists across `Site::withContext` switches. Must call `Settings::clearInternalCache()` inside the closure to force a fresh DB read scoped to the new context. This is the same pattern locked in Phase 2 (`STATE.md` "Settings::clearInternalCache() test-isolation pattern").

**Source verification:**
- `Site::withGlobalContext($fn)` exists — used at `vendor/october/rain/src/Database/Traits/Multisite.php:102` and `:150`.
- `Site::withContext($iSiteId, $fn)` exists — used at `vendor/october/rain/src/Database/Traits/Multisite.php:344` and `:358`.
- `Settings::clearInternalCache()` exists on `SettingModel` (line 241-244 of `modules/system/models/SettingModel.php`).

### Pattern 3: Migration shape — Multisite is schema-additive only

**What:** D-03 lock — Multisite trait operates at the row layer (each site = one row, indexed by `site_id` column already present on `system_settings`). No new column on `system_settings` is needed.

**Migration body (idempotent no-op verifier):**

```php
namespace Logingrupa\Metapixel\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class AddMultisitePixelIdAndToken extends Migration
{
    /**
     * Multisite credential routing is a model-layer concern handled by the
     * Multisite trait on Settings (inherited from CommonSettings). The
     * system_settings table already carries site_id + site_root_id columns
     * (added by October core). This migration is a guard — it confirms the
     * table exists and runs no schema mutation. Schema-additive only per D-03.
     */
    public function up()
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }
        // No-op: row-layer routing requires no schema change here.
    }

    public function down()
    {
        // No-op.
    }
}
```

Listed in `updates/version.yaml`. The migration exists for traceability of MULT-06 in marketplace install logs (operators can `grep AddMultisitePixelIdAndToken` to confirm the upgrade ran).

**Source verification:** `system_settings` table from `modules/system/database/migrations/` already declares `site_id`, `site_root_id`, `site_group_id` (confirmed at runtime — phpunit.xml test fixture `tests/MetapixelTestCase.php:83-90` recreates the table with these three columns).

### Pattern 4: php-domain-parser API surface

**What:** `Pdp\Rules::fromPath('/path/to/public_suffix_list.dat')` builds a `Rules` instance once per request lifecycle. `$obRules->resolve(Pdp\Domain::fromIDNA2008($sHost))` returns `ResolvedDomainName` with `.subDomain()`, `.secondLevelDomain()`, `.registrableDomain()`, `.suffix()`.

**Subdomain index derivation:**

```php
use Pdp\Domain;
use Pdp\Rules;
use Pdp\UnableToResolveDomain;

class HostIndexResolver
{
    private ?Rules $obRules = null;

    /** @var array<string, ?int> request-scoped memo */
    private array $arMemo = [];

    public function __construct(private readonly string $sPslPath) {}

    public function resolve(string $sHost): ?int
    {
        $sHost = strtolower(trim($sHost));
        if ($sHost === '') {
            return null;
        }
        if (array_key_exists($sHost, $this->arMemo)) {
            return $this->arMemo[$sHost];
        }

        $obRules = $this->getRules();

        try {
            $obDomain = Domain::fromIDNA2008($sHost);
            $obResolved = $obRules->resolve($obDomain);
        } catch (UnableToResolveDomain | \Throwable $obException) {
            return $this->arMemo[$sHost] = null;
        }

        $obSuffix = $obResolved->suffix();
        if ($obSuffix->isPublicSuffix() === false || $obResolved->secondLevelDomain()->value() === null) {
            return $this->arMemo[$sHost] = null;
        }

        $iSubdomainLabels = count($obResolved->subDomain()->labels());
        // apex (`example.com`): subDomain labels = 0 → index 1
        // www.example.com:      subDomain labels = 1 → index 2
        // a.b.example.com:      subDomain labels = 2 → index 3
        return $this->arMemo[$sHost] = $iSubdomainLabels + 1;
    }

    private function getRules(): Rules
    {
        return $this->obRules ??= Rules::fromPath($this->sPslPath);
    }
}
```

**Edge cases handled:**
- IDN host (`xn--bcher-kva.example` — punycode for `bücher.example`): `Domain::fromIDNA2008` decodes via PHP's `ext-intl`. `Rules::resolve` returns a `ResolvedDomainName` whose `.suffix()` is the IDN-decoded version.
- Multi-TLD `.co.uk`, `.com.br`: PSL knows these are public suffixes. `secondLevelDomain()` returns `example`; `suffix()` returns `co.uk`. `subDomain` is correctly empty for apex `example.co.uk` → index 1. `www.example.co.uk` → subDomain `www` → index 2.
- Unknown TLD (`example.invalid` etc.): `Domain::fromIDNA2008` may succeed but `resolve()` returns a `ResolvedDomainName` whose `suffix().isPublicSuffix()` is `false` — guard rejects → `null`.
- IPs (`127.0.0.1`): `Domain::fromIDNA2008` throws `SyntaxError` — caught, returns `null`.

**Required PHP extension:** `ext-intl` (for IDN decoding). Already a host project requirement (verified — `intl` is in production PHP 8.4 stack).

**License:** MIT. Source: `https://github.com/jeremykendall/php-domain-parser`.

**Pdp library installation note:** `composer require jeremykendall/php-domain-parser:^6.4` lands `src/Rules.php`, `src/Domain.php`, `src/ResolvedDomainName.php`, `src/Storage/*` (PSR-16 storage integration — NOT used by us; D-09 ships bundled PSL).

### Pattern 5: PSL distribution + refresh model

**What:** `resources/data/public_suffix_list.dat` is committed to plugin git (D-09). The refresh artisan command pulls upstream when an operator runs it manually.

**Artisan command (`console/RefreshPsl.php`):**

```php
namespace Logingrupa\Metapixel\Console;

use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

class RefreshPsl extends Command
{
    /** @var string */
    protected $signature = 'metapixel:refresh-psl';

    /** @var string */
    protected $description = 'Refresh the bundled Public Suffix List from publicsuffix.org.';

    private const UPSTREAM_URL = 'https://publicsuffix.org/list/public_suffix_list.dat';
    private const SENTINEL = '// ===BEGIN ICANN DOMAINS===';

    public function handle(): int
    {
        $sBundlePath = base_path('plugins/logingrupa/metapixel/resources/data/public_suffix_list.dat');
        $sTmpPath = $sBundlePath . '.tmp';

        $obClient = new Client(['timeout' => 10]);

        try {
            $sBody = (string) $obClient->get(self::UPSTREAM_URL)->getBody();
        } catch (\Throwable $obException) {
            $this->error('metapixel: PSL fetch failed: ' . $obException->getMessage());
            return self::FAILURE;
        }

        if ($sBody === '' || ! str_contains($sBody, self::SENTINEL)) {
            $this->error('metapixel: downloaded PSL failed sentinel validation');
            return self::FAILURE;
        }

        if (file_put_contents($sTmpPath, $sBody) === false) {
            $this->error('metapixel: failed to write tmp PSL file');
            return self::FAILURE;
        }

        if (! rename($sTmpPath, $sBundlePath)) {
            @unlink($sTmpPath);
            $this->error('metapixel: atomic rename failed');
            return self::FAILURE;
        }

        // Wipe parsed Rules cache so next request reloads.
        $sCacheDir = storage_path('app/metapixel/psl');
        if (is_dir($sCacheDir)) {
            File::cleanDirectory($sCacheDir);
        }

        $this->info('metapixel: PSL refreshed (' . strlen($sBody) . ' bytes)');
        return self::SUCCESS;
    }
}
```

Registered in `Plugin::register()`:

```php
$this->registerConsoleCommand('metapixel:refresh-psl', RefreshPsl::class);
```

**PSL cache path safety (P-18):**
- ❌ `bootstrap/cache/` — read-only on Forge zero-downtime release.
- ❌ `<release>/plugins/.../cache/` — overwritten on each deploy.
- ✅ `storage/app/metapixel/psl/` — shared across releases (symlink target), Forge-writable.

`HostIndexResolver` can opt to memoize the parsed `Rules` in this directory (Laravel `Cache::store('file')->put('metapixel.psl.rules', serialize(...))`) — **D-12 says request-scoped memo is sufficient.** Don't over-engineer.

### Pattern 6: TrustedHosts field UX + strict beforeSave validation

**What:** Operator types one host per line in a Settings textarea. `Settings::beforeSave` partitions valid/invalid hosts, rejects the save with `Flash::error` listing invalids.

**fields.yaml addition (Hosts & Cookies tab):**

```yaml
tabs:
  fields:
    pixel_id:
      tab: logingrupa.metapixel::lang.tab.pixel_and_capi
      label: logingrupa.metapixel::lang.field.pixel_id_label
      # ...
    capi_access_token:
      tab: logingrupa.metapixel::lang.tab.pixel_and_capi
      # ...
    test_event_code:
      tab: logingrupa.metapixel::lang.tab.pixel_and_capi
      # ...

    trusted_hosts:
      tab: logingrupa.metapixel::lang.tab.hosts_and_cookies
      label: logingrupa.metapixel::lang.field.trusted_hosts_label
      commentAbove: logingrupa.metapixel::lang.field.trusted_hosts_comment
      type: textarea
      size: small
      span: full
    ensure_fbp_fbc_server_side:
      tab: logingrupa.metapixel::lang.tab.hosts_and_cookies
      label: logingrupa.metapixel::lang.field.ensure_fbp_fbc_label
      commentAbove: logingrupa.metapixel::lang.field.ensure_fbp_fbc_comment
      type: switch
      default: true

    theme_custom_event_names:
      tab: logingrupa.metapixel::lang.tab.theme_tracking
      # ... existing

    paid_status_code:
      tab: logingrupa.metapixel::lang.tab.pixel_and_capi
      # ... existing (or move to Advanced if planner prefers)
    default_currency_code:
      tab: logingrupa.metapixel::lang.tab.pixel_and_capi
      # ... existing
```

**beforeSave hook (extends existing pattern):**

```php
public function beforeSave(): void
{
    // existing theme_custom_event_names sanitization runs here

    $arLines = $this->splitHostInput($this->getAttribute('trusted_hosts'));
    if ($arLines === null) {
        return;
    }

    [$arClean, $arRejected] = $this->partitionHosts($arLines);

    if ($arRejected !== []) {
        Flash::error('metapixel: rejected hosts (unknown TLD or invalid charset): ' . implode(', ', $arRejected));
        // Halt save by throwing — October backend catches + flashes.
        throw new \October\Rain\Database\ModelException(...);
        // Alternative: setAttribute to the cleaned subset + flash warning.
        // Planner picks based on UX — strict halt is safer per D-14.
    }

    $this->setAttribute('trusted_hosts', implode("\n", $arClean));
}

private function partitionHosts(array $arLines): array
{
    $obResolver = App::make(HostIndexResolver::class);
    $arClean = [];
    $arRejected = [];
    foreach ($arLines as $mLine) {
        $sHost = is_string($mLine) ? strtolower(trim($mLine)) : '';
        if ($sHost === '') {
            continue;
        }
        if (preg_match('/^[a-z0-9.-]+$/', $sHost) !== 1) {
            $arRejected[] = $sHost;
            continue;
        }
        if ($obResolver->resolve($sHost) === null) {
            $arRejected[] = $sHost;
            continue;
        }
        $arClean[] = $sHost;
    }
    return [$arClean, $arRejected];
}
```

**Pattern parallel:** Mirrors `Settings::splitEventNameInput` + `Settings::partitionEventNames` already on disk (lines 79-113 of `models/Settings.php`). Keep the parallel — both helpers private + return same `[clean, rejected]` shape.

### Pattern 7: Middleware shape — fresh derivation (NOT v1.x port per D-20)

**Locked decisions reused from v1.x (CR-02, CR-03, kill switch, 90-day TTL, `fb.{N}.{ts}.{rand}` format):**
- 90-day TTL: `60 * 60 * 24 * 90`.
- Cookie format: `fb.{subdomain_index}.{creation_time_ms}.{random}` for `_fbp`; `fb.{subdomain_index}.{creation_time_ms}.{fbclid}` for `_fbc`.
- `creation_time_ms`: `(int) (microtime(true) * 1000)`.
- `_fbp` random segment: `bin2hex(random_bytes(8))` (16 hex chars / 64-bit CSPRNG).
- Cookie attributes: TTL 90d, path `/`, domain `null` (implicit current-host), secure mirrors `Request::secure()`, httpOnly false, SameSite Lax.
- fbclid validation: `[A-Za-z0-9_-]` charset, `<=` 255 chars.
- Kill switch: `Settings::get('ensure_fbp_fbc_server_side', true)`.
- Backend path short-circuit.
- App-bound `'metapixel.disabled'` defence-in-depth check.

**Fresh derivation against new deps:**

```php
namespace Logingrupa\Metapixel\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixel\Classes\Helper\HostIndexResolver;
use Logingrupa\Metapixel\Models\Settings;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * EnsureFbpFbcCookies — server-side _fbp / _fbc cookie writer.
 *
 * Operator MUST serve routes hitting this middleware with Cache-Control: private
 * to prevent shared-cache cookie leakage. See README "Cookie middleware" section.
 *
 * Operator-supplied trusted_hosts allowlist + PSL-derived subdomain-index
 * replaces v1.x hardcoded HOST_INDEX_MAP. fbclid validation (CR-03) skips _fbc
 * on invalid input. Kill switch via Settings::ensure_fbp_fbc_server_side.
 */
class EnsureFbpFbcCookies
{
    private const COOKIE_TTL_SECONDS = 60 * 60 * 24 * 90;
    private const COOKIE_FBP = '_fbp';
    private const COOKIE_FBC = '_fbc';
    private const FBCLID_MAX_LENGTH = 255;
    private const FBCLID_ALLOWED_PATTERN = '/^[A-Za-z0-9_-]+$/';

    public function __construct(private readonly HostIndexResolver $obResolver) {}

    public function handle(Request $obRequest, Closure $fnNext): Response
    {
        $obResponse = $fnNext($obRequest);

        if ($this->shouldSkip($obRequest)) {
            return $obResponse;
        }

        $sHost = strtolower($obRequest->getHost());
        $arTrustedHosts = $this->readTrustedHosts();
        if (! in_array($sHost, $arTrustedHosts, true)) {
            return $obResponse; // CR-02: untrusted host → no cookies
        }

        $iIndex = $this->obResolver->resolve($sHost);
        if ($iIndex === null) {
            return $obResponse; // PSL couldn't resolve → fail-safe NO-OP
        }

        $iCreationMs = (int) (microtime(true) * 1000);
        $bSecure = $obRequest->secure();
        $iExpire = time() + self::COOKIE_TTL_SECONDS;

        $this->maybeSetFbp($obRequest, $obResponse, $iIndex, $iCreationMs, $iExpire, $bSecure);
        $this->maybeSetFbc($obRequest, $obResponse, $iIndex, $iCreationMs, $iExpire, $bSecure);

        return $obResponse;
    }

    private function shouldSkip(Request $obRequest): bool
    {
        // Backend path
        $mBackendUri = config('cms.backendUri', 'backend');
        $sBackendUri = is_scalar($mBackendUri) ? (string) $mBackendUri : '';
        if ($sBackendUri !== '' && $obRequest->is(ltrim($sBackendUri, '/') . '*')) {
            return true;
        }
        // PluginGuard defence-in-depth
        if (App::bound('metapixel.disabled') && App::make('metapixel.disabled')) {
            return true;
        }
        // Operator kill switch
        try {
            $mToggle = Settings::get('ensure_fbp_fbc_server_side', true);
            return ! ($mToggle === true || $mToggle === 1 || $mToggle === '1');
        } catch (Throwable $obException) {
            Log::warning('metapixel: kill-switch lookup threw — middleware defaults to enabled', [
                'exception' => get_class($obException),
            ]);
            return false; // boundary fail-safe: middleware runs
        }
    }

    /** @return list<string> */
    private function readTrustedHosts(): array
    {
        $mRaw = Settings::get('trusted_hosts', '');
        $sRaw = is_string($mRaw) ? $mRaw : '';
        if ($sRaw === '') {
            return [];
        }
        $mLines = preg_split('/\R/', $sRaw);
        if ($mLines === false) {
            return [];
        }
        $arHosts = [];
        foreach ($mLines as $mLine) {
            $sHost = is_string($mLine) ? strtolower(trim($mLine)) : '';
            if ($sHost !== '') {
                $arHosts[] = $sHost;
            }
        }
        return $arHosts;
    }

    private function maybeSetFbp(Request $obRequest, Response $obResponse, int $iIndex,
                                  int $iMs, int $iExpire, bool $bSecure): void
    {
        if ($obRequest->cookie(self::COOKIE_FBP) !== null) {
            return;
        }
        $sFbp = sprintf('fb.%d.%d.%s', $iIndex, $iMs, bin2hex(random_bytes(8)));
        $obResponse->headers->setCookie(
            Cookie::create('_fbp', $sFbp, $iExpire, '/', null, $bSecure, false, false, 'lax')
        );
    }

    private function maybeSetFbc(Request $obRequest, Response $obResponse, int $iIndex,
                                  int $iMs, int $iExpire, bool $bSecure): void
    {
        $mFbclid = $obRequest->query('fbclid', '');
        $sFbclid = is_scalar($mFbclid) ? (string) $mFbclid : '';
        if ($sFbclid === '' || strlen($sFbclid) > self::FBCLID_MAX_LENGTH) {
            return;
        }
        if (preg_match(self::FBCLID_ALLOWED_PATTERN, $sFbclid) !== 1) {
            return;
        }
        if ($obRequest->cookie(self::COOKIE_FBC) !== null) {
            return;
        }
        $sFbc = sprintf('fb.%d.%d.%s', $iIndex, $iMs, $sFbclid);
        $obResponse->headers->setCookie(
            Cookie::create('_fbc', $sFbc, $iExpire, '/', null, $bSecure, false, false, 'lax')
        );
    }
}
```

**Registration in `Plugin::boot()` (existing method — APPEND):**

```php
public function boot(): void
{
    // … existing adapter registration

    $this->app[\Illuminate\Contracts\Http\Kernel::class]
        ->pushMiddleware(\Logingrupa\Metapixel\Middleware\EnsureFbpFbcCookies::class);
}
```

**Source verification:** `pushMiddleware` exists on `Illuminate\Foundation\Http\Kernel` (grep confirmed at `vendor/laravel/framework/src/Illuminate/Foundation/Http/Kernel.php`); October's `Foundation\Http\Kernel` extends it (verified at `vendor/october/rain/src/Foundation/Http/Kernel.php`).

### Pattern 8: Backend FailedEvents controller

**Canonical sibling reference:** `plugins/lovata/ordersshopaholic/controllers/PaymentMethods.php` + `paymentmethods/config_list.yaml` + `paymentmethods/_list_toolbar.htm`.

**Controller (`controllers/FailedEvents.php`):**

```php
namespace Logingrupa\Metapixel\Controllers;

use Backend\Classes\Controller;
use BackendMenu;
use Flash;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\App;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Exception\MetaPixelException;
use Logingrupa\Metapixel\Classes\Meta\MetaClient;
use Logingrupa\Metapixel\Models\FailedEvent;
use Logingrupa\Metapixel\Models\Settings;
use System\Classes\SettingsManager;
use Throwable;

class FailedEvents extends Controller
{
    /** @var list<string> */
    public $implement = [
        'Backend.Behaviors.ListController',
    ];

    /** @var string */
    public $listConfig = 'config_list.yaml';

    public function __construct()
    {
        parent::__construct();
        BackendMenu::setContext('Logingrupa.Metapixel', 'metapixel-main', 'metapixel-failed');
        SettingsManager::setContext('Logingrupa.Metapixel', 'metapixel-failed');
    }

    /**
     * Replay a single FailedEvent through MetaClient synchronously.
     * AJAX wired via per-row data-request="onReplay".
     */
    public function onReplay(): array
    {
        $iId = (int) post('record_id');
        // …resolve, dispatch, flash, return refresh payload
    }

    /**
     * Batch Replay for selected rows. Wires from _list_toolbar.htm
     * "checked: $('.control-list').listWidget('getChecked')".
     */
    public function onReplayBatch(): array
    {
        $arIds = (array) post('checked');
        foreach ($arIds as $mId) {
            // …loop onReplay logic, accumulate flash strings
        }
        return ['#failedEventList' => $this->listRefresh()];
    }

    public function onCheckDedup(): JsonResponse { /* … */ }
    public function onCheckDedupBatch(): array { /* … */ }
    public function onDeleteBatch(): array { /* … */ }
}
```

**`controllers/failedevents/config_list.yaml`:**

```yaml
title: 'logingrupa.metapixel::lang.failed_events.list_title'
modelClass: Logingrupa\Metapixel\Models\FailedEvent
list: $/logingrupa/metapixel/models/failedevent/columns.yaml
noRecordsMessage: 'logingrupa.metapixel::lang.failed_events.no_records'
showCheckboxes: true
showSorting: true
defaultSort:
    column: created_at
    direction: desc
recordsPerPage: 30
toolbar:
    buttons: list_toolbar
    search:
        prompt: 'logingrupa.metapixel::lang.failed_events.search_prompt'
filterConfig:
    scopes:
        event_name:
            label: 'logingrupa.metapixel::lang.failed_events.filter_event_name'
            type: text
        adapter_type:
            label: 'logingrupa.metapixel::lang.failed_events.filter_adapter_type'
            type: text
        created_at:
            label: 'logingrupa.metapixel::lang.failed_events.filter_date_range'
            type: daterange
            conditions: created_at >= :after AND created_at <= :before
```

**`models/failedevent/columns.yaml`:**

```yaml
columns:
    id:
        label: 'logingrupa.metapixel::lang.failed_events.column_id'
        searchable: false
        sortable: true
    event_id:
        label: 'logingrupa.metapixel::lang.failed_events.column_event_id'
        searchable: true
    event_name:
        label: 'logingrupa.metapixel::lang.failed_events.column_event_name'
        searchable: true
        sortable: true
    adapter_type:
        label: 'logingrupa.metapixel::lang.failed_events.column_adapter_type'
        searchable: true
    http_status:
        label: 'logingrupa.metapixel::lang.failed_events.column_http_status'
        sortable: true
    attempts:
        label: 'logingrupa.metapixel::lang.failed_events.column_attempts'
        sortable: true
    graph_error:
        label: 'logingrupa.metapixel::lang.failed_events.column_graph_error'
        type: partial
        path: $/logingrupa/metapixel/models/failedevent/_graph_error.htm
    dedup_pct:
        label: 'logingrupa.metapixel::lang.failed_events.column_dedup_pct'
        sortable: true
    emq:
        label: 'logingrupa.metapixel::lang.failed_events.column_emq'
        sortable: true
    dedup_checked_at:
        label: 'logingrupa.metapixel::lang.failed_events.column_dedup_checked_at'
        type: datetime
    created_at:
        label: 'logingrupa.metapixel::lang.failed_events.column_created_at'
        type: datetime
        sortable: true
```

**`controllers/failedevents/_list_toolbar.htm` (parallel to Lovata Toolbox pattern):**

```html
<div data-control="toolbar">
    <button class="btn btn-default oc-icon-bolt"
            disabled="disabled"
            data-request="onReplayBatch"
            onclick="$(this).data('request-data', { checked: $('.control-list').listWidget('getChecked') })"
            data-request-confirm="<?= e(trans('logingrupa.metapixel::lang.failed_events.confirm_replay')) ?>"
            data-trigger-action="enable"
            data-trigger=".control-list input[type=checkbox]"
            data-trigger-condition="checked"
            data-stripe-load-indicator>
        <?= e(trans('logingrupa.metapixel::lang.failed_events.button_replay')) ?>
    </button>

    <button class="btn btn-default oc-icon-shield"
            disabled="disabled"
            data-request="onCheckDedupBatch"
            onclick="$(this).data('request-data', { checked: $('.control-list').listWidget('getChecked') })"
            data-trigger-action="enable"
            data-trigger=".control-list input[type=checkbox]"
            data-trigger-condition="checked"
            data-stripe-load-indicator>
        <?= e(trans('logingrupa.metapixel::lang.failed_events.button_check_dedup')) ?>
    </button>

    <button class="btn btn-danger oc-icon-trash-o"
            disabled="disabled"
            data-request="onDeleteBatch"
            onclick="$(this).data('request-data', { checked: $('.control-list').listWidget('getChecked') })"
            data-request-confirm="<?= e(trans('logingrupa.metapixel::lang.failed_events.confirm_delete')) ?>"
            data-trigger-action="enable"
            data-trigger=".control-list input[type=checkbox]"
            data-trigger-condition="checked"
            data-stripe-load-indicator>
        <?= e(trans('logingrupa.metapixel::lang.failed_events.button_delete')) ?>
    </button>
</div>
```

**`Plugin::registerNavigation()` (NEW method):**

```php
public function registerNavigation(): array
{
    return [
        'metapixel-main' => [
            'label' => 'logingrupa.metapixel::lang.menu.label',
            'url' => \Backend::url('logingrupa/metapixel/failedevents'),
            'icon' => 'icon-bullseye',
            'permissions' => ['logingrupa.metapixel.access'],
            'order' => 500,
            'sideMenu' => [
                'metapixel-failed' => [
                    'label' => 'logingrupa.metapixel::lang.menu.failed_events',
                    'icon' => 'icon-bell',
                    'url' => \Backend::url('logingrupa/metapixel/failedevents'),
                    'permissions' => ['logingrupa.metapixel.access'],
                ],
            ],
        ],
    ];
}
```

(Alternative: nest under `October.System` `'settings'` parent rather than top-level. CONTEXT D-08 says "under Settings parent" — planner picks based on UX preference; both patterns are standard.)

### Pattern 9: Replay + CheckDedup AJAX handlers

**Replay:**

```php
public function onReplay(): array
{
    $iId = (int) post('record_id');
    $obRow = FailedEvent::findOrFail($iId);

    try {
        $obRegistry = App::make(AdapterRegistry::class);
        $obAdapter = $obRegistry->resolveByClass((string) $obRow->adapter_type);
    } catch (Throwable $obException) {
        Flash::error('metapixel: cannot replay — adapter ' . $obRow->adapter_type . ' not registered');
        return ['#failedEventList' => $this->listRefresh()];
    }

    // Re-resolve site_id from the persisted subject_type + subject_id? No —
    // subject is gone (queue serialization not persisted; we have the payload).
    // Use null site_id → Settings::lookupForSite falls back to default row.
    // D-01 semantics align: empty per-site value → default row.
    // For richer routing: persist site_id on FailedEvent in future iteration.
    $iSiteId = null;
    $arCreds = Settings::lookupForSite($iSiteId);

    try {
        $obClient = App::make(MetaClient::class);
        $arPayload = is_array($obRow->payload) ? $obRow->payload : [];
        $obClient->sendForPixel($arCreds['pixel_id'], $arCreds['capi_access_token'], $arPayload);
        $obRow->update([
            'attempts' => (int) $obRow->attempts + 1,
            'graph_error' => null,
            'http_status' => 200,
        ]);
        Flash::success('metapixel: replay succeeded — event_id ' . $obRow->event_id);
    } catch (MetaPixelException $obException) {
        $obRow->update([
            'attempts' => (int) $obRow->attempts + 1,
            'graph_error' => $obException->getMessage(),
        ]);
        Flash::error('metapixel: replay failed — ' . $obException->getMessage());
    } catch (Throwable $obException) {
        $obRow->update([
            'attempts' => (int) $obRow->attempts + 1,
            'graph_error' => $obException->getMessage(),
        ]);
        Flash::error('metapixel: replay errored — ' . $obException->getMessage());
    }

    return ['#failedEventList' => $this->listRefresh()];
}

private function listRefresh(): string
{
    return $this->makePartial('list');
}
```

**Open question (D-05 ambiguity):** the FailedEvent row does NOT persist `site_id`. Phase 4 plan must decide:
- **Option A (zero schema growth):** always fall back to null site_id → default-row credentials. Loses per-site routing on replay, but D-01 says "empty → default row" — semantically aligned.
- **Option B (add `site_id` column to failed_events):** schema growth. More accurate replay.
- **Recommendation:** Option A for v2.0 (no new column). Note in README troubleshooting that operators on multi-site setups should configure the default row as their primary site's credentials. Operator can also manually pick the correct site context before replay (use OctoberCMS top-bar site picker) — but that requires `Site::withContext` wrap in `onReplay`, which adds complexity. D-05 doesn't lock either way; surface as a planner discussion point.

### Pattern 10: Meta Dataset Quality endpoint (CheckDedup)

**Endpoint surface:** Meta documents the Dataset Quality API at `https://developers.facebook.com/docs/marketing-api/conversions-api/dataset-quality-api/`. Confirmed endpoint shape (from web research):

```
GET https://graph.facebook.com/v23.0/{pixel_id}/?fields=name,event_match_quality&access_token={token}
```

Or for events-level data:

```
GET https://graph.facebook.com/v23.0/{pixel_id}/stats?fields=...&access_token={token}
```

**Response shape (approximation — Meta does not publish a stable contract):**

```json
{
  "id": "{pixel_id}",
  "name": "...",
  "event_match_quality": {
    "Purchase": 8.4,
    "PageView": 7.1
  },
  "deduplication_rate": {
    "Purchase": 0.83
  }
}
```

**MetaClient extension (`fetchTestEventsStatus`):**

```php
public function fetchTestEventsStatus(string $sPixelId, string $sToken, string $sTestEventCode = '', string $sEventId = ''): array
{
    if ($sPixelId === '') {
        throw new MissingPixelConfigException('metapixel: pixel_id is empty');
    }
    if ($sToken === '') {
        throw new MissingCapiTokenException('metapixel: access_token is empty');
    }

    $sUrl = sprintf(
        '%s/%s/%s/?fields=name,event_match_quality,deduplication_rate&access_token=%s',
        self::META_GRAPH_API_BASE,
        self::META_GRAPH_API_VERSION,
        $sPixelId,
        rawurlencode($sToken),
    );

    $obClient = $this->obClient ?? new Client(['timeout' => self::DEFAULT_TIMEOUT_SECONDS]);

    try {
        $obResponse = $obClient->request('GET', $sUrl, ['http_errors' => false]);
    } catch (ConnectException $obException) {
        throw new MetaApiTransientException(
            'metapixel: dataset quality fetch connect failure',
            null, $obException, ['url' => $sUrl],
        );
    }

    $iStatus = $obResponse->getStatusCode();
    $arDecoded = $this->decodeBody((string) $obResponse->getBody());

    if ($iStatus < 200 || $iStatus >= 300) {
        throw new MetaApiPermanentException(
            'metapixel: dataset quality fetch ' . $iStatus,
            $iStatus, null, ['response' => $arDecoded],
        );
    }

    // Tolerant parser — returns null for missing fields, not throws.
    return [
        'event_match_quality' => $arDecoded['event_match_quality'] ?? null,
        'deduplication_rate' => $arDecoded['deduplication_rate'] ?? null,
        'raw' => $arDecoded,
    ];
}
```

**Note:** access_token in URL query is acceptable for GET requests against the dataset quality endpoint (Meta accepts both URL query and Bearer header for GET; for POST `/events`, we already POST it in the body for log-leak safety). The webserver-log leak is a smaller concern on backend-only AJAX traffic, but planner may opt to use Authorization Bearer header if Meta accepts it (research is inconclusive — confirm at implementation time).

**Open question for planner:** the exact JSON shape for `event_match_quality` + `deduplication_rate` requires live-pixel verification with the operator's actual test_event_code. The MetaClient method must tolerate schema drift — wrap field reads in `?? null` and let the controller surface "data not available" gracefully.

### Pattern 11: Translation structure

**Existing en/lang.php** (lines 1-27) uses flat-tree `settings.fields.{name}_label/_comment`. Phase 4 expands to:

```php
<?php

return [
    'plugin' => [
        'name' => 'Meta Pixel + Conversions API',
        'description' => 'Server-deduplicated Meta Pixel and Conversions API tracking via adapter pattern.',
    ],
    'settings' => [
        'label' => 'Meta Pixel + CAPI',
        'description' => 'Configure the Pixel ID, CAPI access token, and Test Events code for Meta tracking.',
        'category' => 'Marketing',
    ],
    'tab' => [
        'pixel_and_capi' => 'Pixel & CAPI',
        'hosts_and_cookies' => 'Hosts & Cookies',
        'theme_tracking' => 'Theme Tracking',
        'advanced' => 'Advanced',
    ],
    'field' => [
        'pixel_id_label' => 'Pixel ID',
        'pixel_id_comment' => '…',
        'capi_access_token_label' => 'CAPI Access Token',
        'capi_access_token_comment' => '…',
        'test_event_code_label' => 'Test Events Code',
        'test_event_code_comment' => '…',
        'trusted_hosts_label' => 'Trusted Hosts',
        'trusted_hosts_comment' => 'One host per line. The plugin sets _fbp / _fbc cookies only on these hosts. Sub-TLDs supported via the bundled Public Suffix List.',
        'ensure_fbp_fbc_label' => 'Set _fbp / _fbc cookies server-side',
        'ensure_fbp_fbc_comment' => 'Turn OFF if your theme already writes these cookies, or for GDPR consent banner integration where cookies must wait for opt-in.',
        'theme_custom_event_names_label' => 'Custom theme event names',
        'theme_custom_event_names_comment' => '…',
        'paid_status_code_label' => 'Paid status code',
        'paid_status_code_comment' => '…',
        'default_currency_code_label' => 'Default currency',
        'default_currency_code_comment' => '…',
        // Advanced tab fields if any
    ],
    'menu' => [
        'label' => 'Meta Pixel',
        'failed_events' => 'Failed events',
    ],
    'failed_events' => [
        'list_title' => 'Failed CAPI events',
        'no_records' => 'No failed events recorded.',
        'search_prompt' => 'Search by event_id, event_name…',
        'column_id' => 'ID',
        'column_event_id' => 'Event ID',
        'column_event_name' => 'Event name',
        'column_adapter_type' => 'Adapter',
        'column_http_status' => 'HTTP',
        'column_attempts' => 'Attempts',
        'column_graph_error' => 'Graph error',
        'column_dedup_pct' => 'Dedup %',
        'column_emq' => 'EMQ',
        'column_dedup_checked_at' => 'Checked',
        'column_created_at' => 'Failed at',
        'filter_event_name' => 'Event name',
        'filter_adapter_type' => 'Adapter',
        'filter_date_range' => 'Date range',
        'button_replay' => 'Replay',
        'button_check_dedup' => 'Check dedup',
        'button_delete' => 'Delete',
        'confirm_replay' => 'Re-dispatch the selected events through Meta CAPI?',
        'confirm_delete' => 'Delete the selected failed events permanently?',
        'flash_replay_success' => 'Replay succeeded — event_id :event_id',
        'flash_replay_error' => 'Replay failed — :error',
        'flash_dedup_success' => 'Dedup status updated for :count events',
    ],
    'exception' => [
        'missing_pixel_config' => 'Meta Pixel ID is not configured. Set it in Settings > Meta Pixel + CAPI > Pixel & CAPI tab.',
        'missing_capi_token' => 'Meta CAPI access token is not configured.',
        'order_has_no_currency' => 'Order has no currency — cannot build Purchase event payload.',
    ],
];
```

**LV mirror:** identical key shape, native Latvian strings.

**RainLab.Translate compatibility:** the `|_` Twig filter and `Lang::get('logingrupa.metapixel::lang.field.pixel_id_label')` resolution both work against this nested array shape. `CommonSettings` declares `@RainLab.Translate.Behaviors.TranslatableModel` in `$implement`, but `$translatable = []` — Settings field VALUES are not auto-translated; only the LABEL keys (which come through the `Lang::get` indirection from fields.yaml's `label:` / `commentAbove:`) get RainLab.Translate handling when an admin switches the backend locale.

**Pest coverage assertion (per D-19):**

```php
test('lang/lv exposes same key shape as lang/en', function () {
    $arEn = require __DIR__ . '/../../lang/en/lang.php';
    $arLv = require __DIR__ . '/../../lang/lv/lang.php';

    $fnFlatten = function (array $arArr, string $sPrefix = '') use (&$fnFlatten): array {
        $arOut = [];
        foreach ($arArr as $sKey => $mValue) {
            $sFullKey = $sPrefix === '' ? (string) $sKey : "$sPrefix.$sKey";
            if (is_array($mValue)) {
                $arOut = array_merge($arOut, $fnFlatten($mValue, $sFullKey));
            } else {
                $arOut[] = $sFullKey;
            }
        }
        return $arOut;
    };

    $arEnKeys = $fnFlatten($arEn);
    $arLvKeys = $fnFlatten($arLv);

    expect($arLvKeys)->toEqualCanonicalizing($arEnKeys);
});
```

### Anti-Patterns to Avoid

- **Naive `count(explode('.', $sHost))` for subdomain index** — wrong for `.co.uk`, `.com.br`. Use `HostIndexResolver` via PSL.
- **Mutating `bootstrap/app.php` from the plugin** — breaks Forge zero-downtime release symlinking; plugin code must live in plugin dir only. Use `pushMiddleware` from `Plugin::boot()`.
- **Caching parsed PSL `Rules` in `bootstrap/cache/`** — read-only on Forge release. Use `storage/app/metapixel/psl/`.
- **PSL composer post-install-cmd hook** — breaks `composer install` behind a firewall. Ship bundled PSL + operator-explicit refresh (D-11).
- **PHPStan `@phpstan-ignore` to silence level 10 narrowing** — banned project-wide. Extract a runtime-guarded helper (Phase 2 pattern: `Settings::lookupForSite`'s `is_string($mValue) ? $mValue : ''`).
- **`Settings::clearInternalCache()` omission inside `Site::withContext` closure** — silently returns stale credentials. Always clear inside the closure.
- **Adding columns to `system_settings`** for Multisite — Multisite is row-layer (D-03).
- **Hand-rolled fbclid charset filter** — use the locked regex `[A-Za-z0-9_-]` + length 255 (CR-03).
- **`Settings::get('pixel_id')` direct call outside `lookupForSite`** — D-02 ban. Adds friction at PR review but prevents cross-site credential leak.
- **Replay through queue dispatch** — D-05 explicitly says synchronous. Avoid premature complexity.
- **FailedEvent gaining `$rules` for backend create/update** — D-08 says ListController only, no FormController. Rows are write-only sink, not admin-editable.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Public-suffix-aware subdomain depth | `count(explode('.', host)) - 1` | `jeremykendall/php-domain-parser ^6.4` (`Pdp\Rules::resolve`) | Naive parser is wrong for `.co.uk`, `.com.br`, IDN. Library handles 14 years of edge cases. |
| Multisite Settings row scoping | Custom site-id column on Settings model | `October\Rain\Database\Traits\Multisite` + `System\Models\SettingModel\HasMultisite` | Trait already in `Lovata\Toolbox\Models\CommonSettings`. October stamps `site_id` + `site_root_id` automatically via lifecycle hooks. |
| Per-site Settings cache key | Hand-rolled `Cache::remember('pixel.' . $site)` | `SettingModel::getCacheKey()` (built-in) | Appends `-{site_id}` automatically when MultisiteInterface (line 222 of `modules/system/models/SettingModel.php`). |
| Backend ListController with filters + checkboxes + bulk actions | Custom backend page + manual list rendering | `Backend.Behaviors.ListController` + `config_list.yaml` | Standard October pattern. Lovata.OrdersShopaholic's `PaymentMethods` is the sibling exemplar. |
| HTTP middleware registration from a plugin | Edit `bootstrap/app.php` | `$this->app[Kernel::class]->pushMiddleware(...)` from `Plugin::boot()` | Plugin must not touch host bootstrap. `pushMiddleware` is the documented OctoberCMS plugin API. |
| Cookie creation with TTL + SameSite + secure flags | Raw `header('Set-Cookie: ...')` | `Symfony\Component\HttpFoundation\Cookie::create(...)` | Symfony handles encoding + escaping + flag emission. Already used by v1.x middleware. |
| fbclid charset validation | Custom regex matrix | Locked regex `[A-Za-z0-9_-]` ≤ 255 chars (CR-03) | Spec-locked; deviation creates Meta-incompatible cookies. |
| IDN host decoding | `idn_to_ascii` direct + manual fallback | `Pdp\Domain::fromIDNA2008($sHost)` | Library handles IDN2008 + IDN2003 + edge cases internally. Required `ext-intl` confirmed in prod stack. |
| Translation file loading | `include $sLangFile` + manual key resolution | `Lang::get('logingrupa.metapixel::lang.field.pixel_id_label')` (Laravel facade) | Auto-handles RainLab.Translate locale switching + fallback chain. |
| AJAX response refresh in ListController | Manual JSON response + frontend re-render | Return `['#failedEventList' => $this->makePartial('list')]` | OctoberCMS AJAX framework auto-updates the DOM selector. |

**Key insight:** Phase 4's surface area is large (5 sub-domains: Multisite, TrustedHosts, Cookie, FailedEvents, i18n) but the implementation lift is small because **every sub-domain has a canonical OctoberCMS/Lovata/Laravel pattern already in-tree.** The novel work is wiring `jeremykendall/php-domain-parser` into `HostIndexResolver` — everything else is configuration + idiomatic pattern application.

## Runtime State Inventory

> Phase 4 is mostly greenfield (new files: middleware, controllers, helper, command, migrations, lang). The only runtime-state concern is the PSL data file lifecycle.

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | **None** — `logingrupa_metapixel_event_log` + `logingrupa_metapixel_failed_events` already exist (Phase 2). Phase 4 only adds 3 nullable columns to `failed_events` (`dedup_pct`, `emq`, `dedup_checked_at`). Existing rows trivially get NULLs. | Migration `AddDedupColumnsToFailedEvents` — schema-additive, no data migration. |
| Live service config | **None** — no external service registrations carry plugin-specific identifiers. Meta Pixel ID stays in DB (Settings). The `trusted_hosts` Settings field is empty by default on fresh install; operators populate manually. | None. |
| OS-registered state | **None** — no Windows Task Scheduler / cron / systemd registrations. Plugin only registers a Laravel scheduled command via `Plugin::registerSchedule` (Phase 3, daily `metapixel:purge-event-log`); Phase 4 does NOT add cron entries (PSL refresh is explicit per D-09). | None. |
| Secrets/env vars | **None** — `capi_access_token` lives in DB (per-site Settings). No new env vars introduced. | None. |
| Build artifacts | **PSL data file at `resources/data/public_suffix_list.dat`** — bundled in git but updated by `metapixel:refresh-psl`. Composer install lays it down with the package; manual refresh overwrites. Parsed `Rules` cache at `storage/app/metapixel/psl/` MUST be cleared on refresh (handled by `RefreshPsl::handle()` — `File::cleanDirectory($sCacheDir)`). Operators on Forge zero-downtime see the same PSL file across release symlinks because plugin dir is on the active release. | Document in README: "Re-run `php artisan metapixel:refresh-psl` to update; the parsed-rules cache rebuilds on next request." |

**Nothing found in category:** Stored data migration, live service, OS-registered, secrets — verified above.

## Common Pitfalls

### Pitfall 1: SettingModel `$instances` static cache leak across site context switches

**What goes wrong:** `Settings::lookupForSite(1)` reads pixel_id_A. Then `Settings::lookupForSite(2)` returns pixel_id_A again because `SettingModel::$instances[$cacheKey]` was populated for the first call and `Site::withContext` does not auto-flush it.

**Why it happens:** `SettingModel::$instances` keys by `getCacheKey()`. The cache key DOES append `-{site_id}` for Multisite-aware models (line 222-225 of `modules/system/models/SettingModel.php`), BUT `getCacheKey()` reads `$this->site_id ?: Site::getSiteIdFromContext()` — so the call inside `Site::withContext($iSiteId, fn() => Settings::get(...))` does produce a different cache key. **The actual leak path is the Eloquent query cache `remember(1440, $cacheKey)` (line 187 of SettingModel.php) — Laravel's `remember()` caches by SQL+bindings, not by static map key.** Verify in test setUp.

**How to avoid:** call `Settings::clearInternalCache()` inside every `Site::withContext` closure that reads settings. Also clear Laravel's Cache facade entries with the matching key prefix before each test (`Cache::forget("system::setting.logingrupa_metapixel_settings-{$siteId}")`).

**Warning signs:** test MULT-05 8-path matrix passes for Site A but fails for Site B (same credentials returned).

### Pitfall 2: `MultisiteScope` ignores `Site::hasGlobalContext()`

**What goes wrong:** Querying `Settings::all()` in test setup returns 0 rows even after seeding both Site A + Site B rows.

**Why it happens:** `MultisiteScope::apply` (line 23-26 of `vendor/october/rain/src/Database/Scopes/MultisiteScope.php`) injects `where(site_id, Site::getSiteIdFromContext())` unless `Site::hasGlobalContext()` returns true. In test setUp, no site context is bound → `Site::getSiteIdFromContext()` returns the default (usually null or 1) → query returns only rows matching that id.

**How to avoid:** wrap multi-row reads in `Site::withGlobalContext(fn() => Settings::all())` for tests that need to inspect cross-site data.

**Warning signs:** test asserts 2 rows persisted (one per site); finds 1.

### Pitfall 3: `Plugin::boot` middleware registration runs AFTER session middleware (good) BUT after `october.cms` routing-binding middleware (bad if used for backend paths)

**What goes wrong:** Adding `EnsureFbpFbcCookies` via `pushMiddleware` lands it at the end of the global middleware stack — runs AFTER routing. Backend-path detection uses `$obRequest->is('backend*')`, which is correct, but if Plugin::boot() ordering puts the middleware before October.System's plugin loader has resolved the backend route, the `config('cms.backendUri')` read may return the default `'backend'` even when an operator has changed it via env.

**Why it happens:** `Plugin::boot` runs at plugin-load time. Plugin load order is alphabetical within priority groups. October.System loads first; plugin Settings model and `BACKEND_URI` env var are both available by the time `Logingrupa.Metapixel::boot()` runs. **This is actually fine.** The pitfall is theoretical for Phase 4.

**How to avoid:** verify in feature test — feature test sets `BACKEND_URI=/back`, expects middleware to short-circuit on `/back/something`.

### Pitfall 4: `Pdp\Rules::fromPath` is slow on a 600 KB file every request

**What goes wrong:** PSL parse cost on every request adds 20-50ms.

**Why it happens:** the PSL file is ~280 KB raw, parses to a large `Rules` object. Repeated parse on each `App::make(HostIndexResolver::class)` is wasteful.

**How to avoid:** `App::singleton(HostIndexResolver::class)` binds a single instance per request lifecycle (D-12). `HostIndexResolver` lazy-loads `Rules` in `getRules()` and memoizes on the instance. One parse per request lifecycle — acceptable.

**Warning signs:** profiler shows `Pdp\Rules::fromPath` in hot path. If observed, planner can opt to persist serialized `Rules` to `storage/app/metapixel/psl/rules.cache` and `unserialize` on subsequent boots — but D-12 says don't bother unless real evidence emerges.

### Pitfall 5: `Pdp\Domain::fromIDNA2008` throws `SyntaxError` on invalid hosts

**What goes wrong:** `HostIndexResolver::resolve('')` throws unhandled.

**Why it happens:** `Domain::fromIDNA2008('')` throws `Pdp\SyntaxError` (or `Pdp\UnableToResolveDomain`). Same for IP addresses, hostnames with underscores beyond label boundary, etc.

**How to avoid:** wrap `Domain::fromIDNA2008` + `Rules::resolve` in `try { ... } catch (\Throwable $obException) { return null; }` (see Pattern 4 sketch). Memoize the null result.

**Warning signs:** middleware throws 500 on an attacker-crafted Host header.

### Pitfall 6: `ListController` "Settings parent" navigation registration ambiguity

**What goes wrong:** D-08 says "Backend menu registered in `Plugin::registerNavigation()` under Settings parent". October has two parent options: (a) `October.System` `'settings'` top-level item, (b) the plugin's own `registerSettings()` page. Sibling `Lovata.OrdersShopaholic` registers under `October.System` `'settings'` via `BackendMenu::setContext('October.System', 'system', 'settings')` (no `registerNavigation` at all — uses `SettingsManager::setContext`).

**Why it happens:** D-08 wording is ambiguous between "Settings page child" and "Settings backend menu group child".

**How to avoid:** planner picks: (a) FailedEvents lives under `SettingsManager` (October.System Settings page) like Lovata controllers do, OR (b) FailedEvents gets its own top-level menu item via `registerNavigation`. Recommendation: **(a)** matches the Lovata pattern operators are used to, avoids menu sprawl.

**Implementation for (a):**

```php
public function registerSettings(): array
{
    return [
        'settings' => [
            // existing Settings model
            'label' => 'logingrupa.metapixel::lang.settings.label',
            'class' => Settings::class,
            'order' => 500,
        ],
        'failed_events' => [
            'label' => 'logingrupa.metapixel::lang.menu.failed_events',
            'description' => 'logingrupa.metapixel::lang.menu.failed_events_description',
            'category' => 'logingrupa.metapixel::lang.settings.category',
            'icon' => 'icon-bell',
            'url' => \Backend::url('logingrupa/metapixel/failedevents'),
            'order' => 510,
        ],
    ];
}
```

Then the controller's `BackendMenu::setContext('October.System', 'system', 'settings')` + `SettingsManager::setContext('Logingrupa.Metapixel', 'failed_events')` puts the page under the Settings parent in the breadcrumb.

### Pitfall 7: `composer-dependency-analyser` flags `jeremykendall/php-domain-parser` as DEV_DEPENDENCY_IN_PROD if added to `require-dev`

**What goes wrong:** PR with PSL parser only in `require-dev:` ships, then `composer install --no-dev` on production breaks `HostIndexResolver`.

**Why it happens:** plugin's `composer-dependency-analyser.php` (verified in-tree) enforces dev-only imports stay inside Shopaholic adapter dir. `Pdp\*` would be imported from `classes/helper/HostIndexResolver.php` (production path).

**How to avoid:** add `"jeremykendall/php-domain-parser": "^6.4"` to `require:`, NOT `require-dev:`. The plugin's `composer.json` currently declares no PSL package; planner adds it under `require:`.

### Pitfall 8: `Plugin::boot()` middleware registration runs before SettingModel cache table exists during plugin migration

**What goes wrong:** First-ever `php artisan october:up` boots plugins → `EnsureFbpFbcCookies::shouldSkip` reads `Settings::get('ensure_fbp_fbc_server_side')` → SettingModel queries `system_settings` table → table doesn't exist yet → exception → 500 on the migration request.

**Why it happens:** Plugin::boot runs before plugin migrations.

**How to avoid:** the middleware's `shouldSkip` already wraps the Settings read in `try { ... } catch (Throwable) { return true; }` (boundary fail-safe). Verified pattern from v1.x. New derivation must preserve.

**Warning signs:** "exception thrown during initial migration" in operator install log.

### Pitfall 9: October's `Site::withContext` callable signature is `function($siteId, $callable)` — getting argument order wrong

**What goes wrong:** `Site::withContext(fn() => ..., $iSiteId)` runs the closure but injects null site context.

**How to avoid:** confirm by reading `vendor/october/rain/src/Foundation/Console/Kernel.php` or `system/classes/sitemanager/HasSiteContext.php`. Argument order: `(int $iSiteId, callable $fnCallback)`.

**Verified:** `vendor/october/rain/src/Database/Traits/Multisite.php:344` shows `Site::withContext($siteId, function () use (...) { ... })` — `$siteId` first.

### Pitfall 10: Phase 4 controller-side validation rule for FailedEvent (CLAUDE.md user-input boundary requirement)

**What goes wrong:** Plugin CLAUDE.md requires future user-input-boundary models to add the Validation trait + `$rules`. FailedEvent gets new public actions in Phase 4 (`onReplay`, `onCheckDedup`).

**Why it happens:** the actions accept `record_id` from the request and use `FailedEvent::findOrFail($iId)` (primary key lookup only). No attribute writes from user input — only `attempts++`, `graph_error = $exceptionMessage`, `dedup_pct = $apiResponse[...]`. These are all server-derived values.

**How to avoid:** stay with controller-side validation. Cast `(int) post('record_id')`; reject if `<= 0`. Document in plan that FailedEvent does NOT gain `Validation` trait — `record_id` is the only user input, and `findOrFail` already validates by failing to find.

## Code Examples

### Example 1: Adding `use Multisite` to a SettingModel descendant (MULT-01)

```php
// Source: plugins/lovata/toolbox/models/CommonSettings.php (already done — verify)
namespace Logingrupa\Metapixel\Models;

use Lovata\Toolbox\Models\CommonSettings;

class Settings extends CommonSettings
{
    // Multisite trait inherited from CommonSettings.
    // $propagatable = [] inherited (empty whitelist).
    // No additional code required for MULT-01.

    // Override the explicit declaration for clarity + documentation
    // and to keep the locked decision visible at the descendant level:
    /** @var list<string> */
    protected $propagatable = [];

    public $settingsCode = 'logingrupa_metapixel_settings';
    public $settingsFields = 'fields.yaml';
}
```

### Example 2: Per-site row read with Site::withContext (MULT-03)

```php
// Source: vendor/october/rain/src/Database/Traits/Multisite.php:344
// (pattern verified in core trait usage)
use Site;

public static function lookupForSite(?int $iSiteId): array
{
    [$sDefaultPixel, $sDefaultToken] = self::readInGlobalContext();

    if ($iSiteId === null) {
        return ['pixel_id' => $sDefaultPixel, 'capi_access_token' => $sDefaultToken];
    }

    [$sSitePixel, $sSiteToken] = Site::withContext($iSiteId, function () {
        Settings::clearInternalCache();
        $mPixel = self::get('pixel_id', '');
        $mToken = self::get('capi_access_token', '');
        return [
            is_string($mPixel) ? $mPixel : '',
            is_string($mToken) ? $mToken : '',
        ];
    });

    return [
        'pixel_id' => $sSitePixel !== '' ? $sSitePixel : $sDefaultPixel,
        'capi_access_token' => $sSiteToken !== '' ? $sSiteToken : $sDefaultToken,
    ];
}
```

### Example 3: HostIndexResolver instantiation in Plugin::register

```php
// Source: plugin CLAUDE.md singleton pattern locked in D-12
use Illuminate\Support\Facades\App;
use Logingrupa\Metapixel\Classes\Helper\HostIndexResolver;

public function register(): void
{
    // existing AdapterRegistry singleton, ThemeEventCollector singleton

    $this->app->singleton(HostIndexResolver::class, function () {
        return new HostIndexResolver(
            base_path('plugins/logingrupa/metapixel/resources/data/public_suffix_list.dat')
        );
    });

    $this->registerConsoleCommand('metapixel:purge-event-log', PurgeEventLog::class);
    $this->registerConsoleCommand('metapixel:refresh-psl', \Logingrupa\Metapixel\Console\RefreshPsl::class);
}
```

### Example 4: Plugin::boot pushMiddleware (HOST-04 / COOK-01)

```php
// Source: octobercms.com/forum + verified at vendor/laravel/framework/.../HttpKernel.php
use Illuminate\Contracts\Http\Kernel;
use Logingrupa\Metapixel\Middleware\EnsureFbpFbcCookies;

public function boot(): void
{
    // existing adapter registration

    $this->app[Kernel::class]->pushMiddleware(EnsureFbpFbcCookies::class);
}
```

### Example 5: Sibling ListController controller (FAIL-01)

```php
// Source: plugins/lovata/ordersshopaholic/controllers/PaymentMethods.php
// (canonical exemplar)
namespace Logingrupa\Metapixel\Controllers;

use Backend\Classes\Controller;
use BackendMenu;
use System\Classes\SettingsManager;

class FailedEvents extends Controller
{
    public $implement = ['Backend.Behaviors.ListController'];
    public $listConfig = 'config_list.yaml';

    public function __construct()
    {
        parent::__construct();
        BackendMenu::setContext('October.System', 'system', 'settings');
        SettingsManager::setContext('Logingrupa.Metapixel', 'failed_events');
    }
}
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Hardcoded `HOST_INDEX_MAP` for known apex/www hosts | Operator-supplied `trusted_hosts` Settings + PSL parser | v2.0 (Phase 4) | Plugin works on any operator's domain without code change. Marketplace-ready. |
| v1.x `Logingrupa\Metapixelshopaholic\Middleware\EnsureFbpFbcCookies` (Shopaholic-coupled namespace) | Generic `Logingrupa\Metapixel\Middleware\EnsureFbpFbcCookies` with `HostIndexResolver` constructor injection | v2.0 (Phase 4) | Decoupled from cart-plugin; testable in isolation. |
| Single-pixel deploy (one `pixel_id` Setting) | Multisite-aware per-site `pixel_id` + `capi_access_token` | v2.0 (Phase 4 MULT-01..06) | Operator deploys one plugin across multiple sites; each gets its own pixel + token without code change. |
| v1.x `pdp/pdp` v5 (deprecated, no IDN2008) — never adopted | `jeremykendall/php-domain-parser ^6.4` v6 (current, IDN2008) | v2.0 (HOST-02) | Multi-TLD + IDN support. |
| Laravel HTTP Kernel direct edit via `app/Http/Kernel.php` | `bootstrap/app.php` Middleware closure + `pushMiddleware` from plugin Plugin.php | Laravel 11+ (host stack since 2024) | Plugin must NOT edit host bootstrap; `pushMiddleware` from `Plugin::boot()` works against both old + new structure. |
| Sync queue dispatch with no admin replay UI (v1.x partial) | Backend `Controllers\FailedEvents` with Replay + CheckDedup | v2.0 (FAIL-01..03) | Operator self-recovery without DB shell. |
| `array` Eloquent cast on JSON columns | `$jsonable = ['column']` October idiom | Plugin CLAUDE.md lock (2026-05-18 quick task 260518-999) | Round-trip identical; signals October idiom. Already applied to `FailedEvent::$jsonable = ['payload']`. |
| `lang/{en,lv,ru}/lang.php` (v1.x) | `lang/{en,lv}/lang.php` only (no RU at v2.0) | v2.0 scope decision | Operator self-services RU. |

**Deprecated/outdated:**
- `pdp/pdp` v5: superseded by `jeremykendall/php-domain-parser` v6 (same author, new naming).
- v1.x `HOST_INDEX_MAP` constant: dropped per D-20.
- v1.x "Compliance" Settings tab name: renamed to "Hosts & Cookies" per D-15.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Meta Dataset Quality endpoint accepts `GET /v23.0/{pixel_id}/?fields=event_match_quality,deduplication_rate&access_token={token}` shape | Pattern 10 (Meta Dataset Quality endpoint) | CheckDedup feature returns null/empty for all dedup data. Mitigated by tolerant JSON parser (no throw on missing fields). Operator can still use Replay; CheckDedup remains a "best-effort" diagnostic. |
| A2 | Meta accepts access_token in URL query for GET on Dataset Quality endpoint without 401 | Pattern 10 | If 401: switch to Authorization Bearer header. Two-line fix in MetaClient. |
| A3 | `Pdp\Domain::fromIDNA2008('xn--bcher-kva.example')` decodes correctly and `Rules::resolve` returns a valid `ResolvedDomainName` | Pattern 4 | If wrong: middleware NO-OPs on IDN hosts → cookies don't write. Mitigated by HOST-05 test matrix catching at CI before ship. |
| A4 | October's `Site::withContext($iSiteId, $fn)` signature is `(int, callable)` with site id first | Pattern 2 / Pitfall 9 | Verified in source — `vendor/october/rain/src/Database/Traits/Multisite.php:344` confirms. Low risk. |
| A5 | `SettingModel::$instances` static cache persists across `Site::withContext` switches in the same request | Pitfall 1 | If wrong (October auto-flushes on context switch): MULT-05 test passes without explicit `clearInternalCache`. No bug, just unused safety code. Low risk. |
| A6 | RainLab.Translate `|_` filter resolves nested-array lang keys (`logingrupa.metapixel::lang.field.pixel_id_label`) the same way `Lang::get` does | Pattern 11 | If wrong: backend labels render the literal key. Caught by manual smoke test on first ListController page render. Low risk; RainLab.Translate is the de facto OctoberCMS i18n standard. |
| A7 | `Backend.Behaviors.ListController` supports `recordOnClick` for per-row Replay/CheckDedup buttons inline with checkbox column | Pattern 8 | If wrong: per-row buttons need a custom partial column type — adds one config layer. Low risk; Lovata controllers ship the pattern. |
| A8 | `composer-dependency-analyser.php` config does not need a new entry for `jeremykendall/php-domain-parser` (it's a regular prod dep, not Lovata-special) | Pitfall 7 | Config flags every `Pdp\*` import. Mitigated by adding to `require:` (not `require-dev:`). Low risk. |
| A9 | The `trusted_hosts` Settings textarea is serializable through the SettingModel `value` expando JSON column without truncation at reasonable lengths (< 4 KB) | Pattern 6 | `system_settings.value` is `mediumtext` → 16 MB capacity. No risk. |
| A10 | Phase 4 needs no `composer.json` change beyond adding `jeremykendall/php-domain-parser ^6.4` to `require:` | Installation section | Guzzle already present; no other new dep. Verified against existing `composer.json`. Low risk. |

**Planner action:** treat A1 + A2 (Meta Dataset Quality endpoint) as needing live-pixel verification during Phase 4 execution; ship the tolerant parser as the primary path and surface "unable to fetch dedup status" gracefully in the UI. A3 (IDN) is straightforward to fixture-test in HOST-05. All other A* are LOW risk.

## Open Questions

1. **FailedEvent persists no `site_id` column — Replay loses per-site routing context.**
   - What we know: FailedEvent (Phase 2 migration) tracks `subject_type`, `subject_id`, `adapter_type`, `event_id`, `event_name`, `payload`, `http_status`, `graph_error`, `attempts`. No `site_id`.
   - What's unclear: should the Phase 4 dedup-columns migration also add `site_id` (nullable) so onReplay can use the correct site context, or accept the D-01 fall-back to default-row credentials?
   - Recommendation: planner discusses with user. Default recommendation = Option A (no site_id column) for v2.0 simplicity. Document in README troubleshooting.

2. **Per-row vs per-batch action wiring in ListController.**
   - What we know: D-07 says "Per-row buttons mirror the batch actions". Standard October pattern uses `recordOnClick` for row navigation OR a partial column with action buttons.
   - What's unclear: per-row buttons live in a custom column partial (`models/failedevent/_actions.htm`) OR in a popup modal launched by `recordOnClick`. Sibling Lovata controllers only ship batch toolbar.
   - Recommendation: planner picks based on UX. Simplest pattern = batch toolbar only (D-07 batch path) + per-row action via row click → popup modal listing actions. Cleanest October idiom.

3. **`SettingsManager::setContext` vs `BackendMenu::setContext` for FailedEvents navigation.**
   - What we know: Lovata.OrdersShopaholic `PaymentMethods` uses `BackendMenu::setContext('October.System', 'system', 'settings')` + `SettingsManager::setContext`. This puts the controller under the System Settings page parent in the breadcrumb.
   - What's unclear: D-08 says "menu registered in `Plugin::registerNavigation()` under Settings parent" — ambiguous between `registerNavigation` (own top-level menu) vs `registerSettings` (Settings child item).
   - Recommendation: planner picks. Default = `registerSettings` child entry per Pitfall 6 sketch. Avoids top-level menu sprawl.

4. **Should Plugin::boot run middleware registration conditionally if `trusted_hosts` is empty?**
   - What we know: with empty `trusted_hosts`, the middleware NO-OPs anyway (Pattern 7 readTrustedHosts → empty list → in_array fails → return).
   - What's unclear: skipping the `pushMiddleware` call when `trusted_hosts` is empty saves one method call per request. Trivial perf, but cleaner intent.
   - Recommendation: always push. Defending against an empty allowlist via early-return in the middleware itself keeps the behaviour observable; skipping registration would hide the "operator hasn't configured" state from `php artisan route:list`.

5. **D-15 fields.yaml tab mapping — which tab gets `paid_status_code` and `default_currency_code`?**
   - What we know: D-15 lists these in "Pixel & CAPI" tab. But they are Shopaholic-adapter-specific Settings. On a no-cart install, the dropdown options are empty.
   - What's unclear: is "Pixel & CAPI" the right home, or should they move to "Advanced" or stay only on the Shopaholic-conditional Settings surface?
   - Recommendation: keep in "Pixel & CAPI" per D-15. Empty dropdown is acceptable UX on no-cart installs (operator who has no cart doesn't trigger Purchase events anyway).

6. **`updates/AddMultisitePixelIdAndToken.php` body — truly no-op vs. confirmatory column-check.**
   - What we know: D-03 says schema-additive only — Multisite operates at row layer.
   - What's unclear: should the migration ALSO verify `site_id` + `site_root_id` columns exist on `system_settings` (added by October core)?
   - Recommendation: no — these are core columns; migration would always succeed. Keep the body a pure no-op with a `Schema::hasTable` guard. Existence purely for traceability + version.yaml linkage.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP 8.4 (host) / ^8.3 dual support (plugin) | All Phase 4 code | ✓ | 8.4.18 NTS + Zend OPcache 8.4.18 (verified at host) | — |
| `ext-intl` | `Pdp\Domain::fromIDNA2008` IDN decoding | ✓ (assumed, standard prod PHP) | — | None — required for IDN hosts. Verify in CI bootstrap. |
| `ext-filter` | `jeremykendall/php-domain-parser` core dep | ✓ (standard PHP) | — | — |
| `october/system ^4.0` (Laravel 12) | All Phase 4 code | ✓ | 4.x (existing) | — |
| `lovata/toolbox-plugin ^2.2` | `Settings extends CommonSettings` | ✓ | 2.2 | — |
| RainLab.Translate v2.2 | Translation locale switching | ✓ | 2.2 | — |
| `guzzlehttp/guzzle ^7.8` | `RefreshPsl` artisan + `MetaClient::fetchTestEventsStatus` | ✓ | 7.8 (Phase 2 lock) | — |
| `storage/app/` writable | PSL cache dir `storage/app/metapixel/psl/` | ✓ | writable (verified — `forge:forge` ownership) | — |
| `resources/data/public_suffix_list.dat` shipped | `HostIndexResolver::__construct(path)` | ✗ (not yet committed) | — | None — must ship in Phase 4 D-09. |
| `jeremykendall/php-domain-parser` v6.4 | `HostIndexResolver` | ✗ (not yet installed) | will be 6.4.0 | None — required by HOST-02. |
| `slopcheck` CLI | Package legitimacy audit | ✗ | — | Manual cross-check via packagist (done in audit table). |
| Live test Meta Pixel + access_token for CheckDedup endpoint shape verification | Pattern 10 / FAIL-03 | ✗ (operator-provided at runtime) | — | Tolerant JSON parser handles missing fields gracefully. |

**Missing dependencies with no fallback:**
- `resources/data/public_suffix_list.dat` — planner adds task to commit the bundled PSL file.
- `jeremykendall/php-domain-parser` ^6.4 — planner adds `composer require` task.

**Missing dependencies with fallback:**
- slopcheck CLI — graceful degradation via manual packagist verification.
- Live Meta pixel for endpoint shape verification — tolerant parser is fallback; document as "best-effort diagnostic" in README.

## Validation Architecture

> Required because `workflow.nyquist_validation` is not explicitly set to false in `.planning/config.json` (treat as enabled).

### Test Framework

| Property | Value |
|----------|-------|
| Framework | Pest 4.x + PHPUnit 12 (locked in Phase 1 / TOOL-08) |
| Config file | `plugins/logingrupa/metapixel/phpunit.xml` (verified — `bootstrap=../../../modules/system/tests/bootstrap.php`, in-memory SQLite) |
| Quick run command | `pest --filter=Phase04 -x --no-coverage` (or `pest tests/Feature/Settings -x`) |
| Full suite command | `composer test-cov` (runs `pest --coverage --min=90`) |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|--------------|
| MULT-01 | `Settings::$propagatable === []` lock + Multisite trait present | unit | `pest tests/Unit/Models/SettingsMultisiteTraitTest.php` | ❌ Wave 0 |
| MULT-02 | `pixel_id` + `capi_access_token` NOT in `$propagatable` | unit | (same file as MULT-01) | ❌ Wave 0 |
| MULT-03 | `Settings::lookupForSite($iSiteId)` returns per-site row OR default fallback | feature | `pest tests/Feature/Settings/LookupForSiteTest.php` | ❌ Wave 0 |
| MULT-04 | `SendCapiEvent::handle` calls `Settings::lookupForSite($iSiteId)` | (already covered Phase 2 BackboneIntegrationTest) | — | ✓ |
| MULT-05 | 8-path matrix: 2 sites × 2 adapters × 2 channels | feature | `pest tests/Feature/MultisiteEventLogRoutingTest.php` | ❌ Wave 0 |
| MULT-06 | `AddMultisitePixelIdAndToken` migration idempotent + no-op | feature | `pest tests/Feature/Migrations/AddMultisitePixelIdAndTokenTest.php` | ❌ Wave 0 |
| HOST-01 | `trusted_hosts` textarea + beforeSave strict validation | feature | `pest tests/Feature/Settings/TrustedHostsValidationTest.php` | ❌ Wave 0 |
| HOST-02 | `HostIndexResolver` returns correct subdomain index | unit (data-provider) | `pest tests/Unit/Helper/HostIndexResolverTest.php` | ❌ Wave 0 |
| HOST-03 | `metapixel:refresh-psl` validates sentinel + atomic-rename + cache wipe | feature | `pest tests/Feature/Console/RefreshPslTest.php` | ❌ Wave 0 |
| HOST-04 | Middleware reads `trusted_hosts` + delegates to `HostIndexResolver`; untrusted host NO-OP | feature | `pest tests/Feature/Middleware/EnsureFbpFbcCookiesTest.php` | ❌ Wave 0 |
| HOST-05 | Multi-TLD test matrix — apex, www., `.co.uk`, IDN, .com.br | unit (data-provider) | (same as HOST-02 with provider rows) | ❌ Wave 0 |
| HOST-06 | Untrusted host → middleware NO-OP, no cookie set, no exception | feature | (same as HOST-04 with untrusted host case) | ❌ Wave 0 |
| COOK-01 | Kill switch `ensure_fbp_fbc_server_side=false` short-circuits middleware | feature | (same as HOST-04 with kill-switch case) | ❌ Wave 0 |
| COOK-02 | Invalid fbclid → `_fbc` skipped; valid → `_fbc` written | feature | (same as HOST-04 with fbclid validation cases) | ❌ Wave 0 |
| COOK-03 | Docblock + README references — no automated test | manual-only | grep `Cache-Control: private` in README | (doc grep) |
| FAIL-01 | Controller list renders with columns + filters | feature | `pest tests/Feature/Controllers/FailedEventsListTest.php` | ❌ Wave 0 |
| FAIL-02 | `onReplay` resolves adapter, calls MetaClient, updates row, flashes | feature | `pest tests/Feature/Controllers/FailedEventsReplayTest.php` | ❌ Wave 0 |
| FAIL-03 | `onCheckDedup` calls MetaClient::fetchTestEventsStatus, writes 3 columns, returns JSON | feature | `pest tests/Feature/Controllers/FailedEventsCheckDedupTest.php` | ❌ Wave 0 |
| LANG-01 | EN + LV files exist; LV has same key shape as EN; nested array structure | unit | `pest tests/Unit/Lang/LangCoverageTest.php` | ❌ Wave 0 |

### Sampling Rate

- **Per task commit:** `pest tests/{related-folder} -x --no-coverage` (e.g. `pest tests/Feature/Settings -x` after MULT-03 task).
- **Per wave merge:** `composer test-cov` (full suite + coverage gate).
- **Phase gate:** Full suite green before `/gsd:verify-phase 04`. PHPStan level 10 zero errors. `composer qa` exits 0.

### Wave 0 Gaps

- [ ] `tests/Unit/Models/SettingsMultisiteTraitTest.php` — covers MULT-01 + MULT-02
- [ ] `tests/Feature/Settings/LookupForSiteTest.php` — covers MULT-03
- [ ] `tests/Feature/MultisiteEventLogRoutingTest.php` — covers MULT-05 (8-path matrix, hermetic 2-site SQLite seed)
- [ ] `tests/Feature/Migrations/AddMultisitePixelIdAndTokenTest.php` — covers MULT-06
- [ ] `tests/Feature/Settings/TrustedHostsValidationTest.php` — covers HOST-01
- [ ] `tests/Unit/Helper/HostIndexResolverTest.php` — covers HOST-02 + HOST-05 (data-provider for IDN, .co.uk, .com.br, apex, www., subdomain)
- [ ] `tests/Feature/Console/RefreshPslTest.php` — covers HOST-03 (Guzzle MockHandler for upstream PSL fetch)
- [ ] `tests/Feature/Middleware/EnsureFbpFbcCookiesTest.php` — covers HOST-04 + HOST-06 + COOK-01 + COOK-02 (multi-case feature spec)
- [ ] `tests/Feature/Controllers/FailedEventsListTest.php` — covers FAIL-01 (boots backend controller, asserts list partial renders)
- [ ] `tests/Feature/Controllers/FailedEventsReplayTest.php` — covers FAIL-02 (Guzzle MockHandler for MetaClient)
- [ ] `tests/Feature/Controllers/FailedEventsCheckDedupTest.php` — covers FAIL-03
- [ ] `tests/Unit/Lang/LangCoverageTest.php` — covers LANG-01 (flatten + key-shape compare)
- [ ] Shared fixture: `tests/fixtures/sites.php` — seeds 2 site rows for MULT-05 hermetic setup
- [ ] Shared fixture: `tests/fixtures/data/test_psl.dat` — small PSL subset (apex, www., .co.uk, .com.br, IDN sentinel) for HostIndexResolverTest without loading the full 280 KB file

**Framework install:** none — Pest already installed in Phase 1. Just add new test files.

## Security Domain

> Plugin is a marketplace product handling third-party Meta Pixel tracking; security is in-scope.

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | partial — backend admin uses October core auth | October.Backend `auth` middleware (existing) |
| V3 Session Management | partial — backend session via October core | October session middleware (existing) |
| V4 Access Control | yes — `Controllers\FailedEvents` requires `logingrupa.metapixel.access` permission | `Plugin::registerPermissions()` if not already; declared in registerNavigation `permissions: []` array |
| V5 Input Validation | yes — `trusted_hosts` strict beforeSave validation (D-14); fbclid regex validation (CR-03); `onReplay` int cast on `record_id` | beforeSave regex + PSL parse; `preg_match` for fbclid; `(int) post('record_id')` |
| V6 Cryptography | yes — `_fbp` random uses `random_bytes(8)` CSPRNG (lock from v1.x); session-equivalent values | PHP `random_bytes` (CSPRNG, libsodium-backed) — never hand-roll |
| V7 Error Handling | yes — Tiger-Style: throw at boundary, catch only to log-and-rethrow OR dead-letter-persist | Per CLAUDE.md lock |
| V13 API and Web Service | partial — Meta Graph API outbound calls only | MetaClient already wraps with timeout + transient/permanent classification |

### Known Threat Patterns for OctoberCMS plugin + cookie middleware

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Host-header spoofing → wrong subdomain index in cookie → CAPI mismatch | Tampering (CR-02 anchor) | `trusted_hosts` allowlist + PSL-derived index. Untrusted host → NO-OP. |
| fbclid query-string injection (multi-KB payload, XSS, SQL-shaped) | Tampering / Information Disclosure | CR-03 charset `[A-Za-z0-9_-]` + ≤ 255 char length. Invalid → skip `_fbc`. |
| Cross-site Settings token leak under Multisite | Information Disclosure (P-10 anchor) | `$propagatable = []` empty whitelist locks `pixel_id` + `capi_access_token` to per-site rows only. |
| Forge zero-downtime PSL cache directory writes fail (read-only release symlink) | Denial of Service (P-18 anchor) | Cache at `storage/app/metapixel/psl/` (shared writable path). |
| Cookie poisoning via shared-cache HTTP responses (Cache-Control: public) | Information Disclosure | Document operator responsibility in README — middleware does not auto-set Cache-Control. (COOK-03 doc-only) |
| FailedEvent admin Replay re-fires private payload to wrong pixel | Information Disclosure / Tampering | D-01 default-row fallback; operator who multi-sites uses default row as primary site. Audit log via `Log::info` on every Replay attempt. |
| FailedEvent record_id parameter tampering via crafted AJAX | Tampering | `findOrFail((int) post('record_id'))` rejects non-integer + missing rows. |
| Untrusted Meta Graph API response data injected into FailedEvent columns | Tampering / Information Disclosure | MetaClient `decodeBody` casts keys to string; controller writes `dedup_pct`, `emq`, `dedup_checked_at` only from `?? null` parsed numeric fields. No raw HTML/string passthrough. |
| PSL refresh artisan command fetches arbitrary URL | Server-Side Request Forgery | URL pinned to `https://publicsuffix.org/list/public_suffix_list.dat` constant — not operator-configurable. |
| `Settings::beforeSave` throws bypass (operator persists invalid trusted_hosts) | Tampering | `Flash::error` + halt save (throw `ModelException`) — operator forced to clean input before save persists. |

## Sources

### Primary (HIGH confidence — in-tree verification)

- `vendor/october/rain/src/Database/Traits/Multisite.php` — `Multisite` trait full source, including `$propagatable`, `bootMultisite`, `multisiteBeforeSave`, `findOrCreateForSite`, `Site::withContext` usage at lines 102, 150, 344, 358.
- `vendor/october/rain/src/Database/Scopes/MultisiteScope.php` — `MultisiteScope::apply` injects `where(site_id, Site::getSiteIdFromContext())`.
- `modules/system/models/SettingModel.php` — `SettingModel` base + `getCacheKey()` per-site suffix logic.
- `modules/system/models/settingmodel/HasMultisite.php` — `settingMultisiteBeforeSave` + `settingMultisiteInitSettingsData` lifecycle.
- `plugins/lovata/toolbox/models/CommonSettings.php` — Settings base class already uses `Multisite` trait + `$propagatable = []`.
- `vendor/october/rain/contracts/Database/MultisiteInterface.php` — structural contract for Multisite (3 methods).
- `vendor/october/rain/src/Extension/ExtendableTrait.php:244` — `isClassInstanceOf` duck-typing implementation.
- `plugins/lovata/ordersshopaholic/controllers/PaymentMethods.php` + `paymentmethods/config_list.yaml` + `paymentmethods/_list_toolbar.htm` — canonical sibling ListController example.
- `vendor/laravel/framework/src/Illuminate/Foundation/Http/Kernel.php` — `pushMiddleware` + `prependMiddleware` source.
- `vendor/october/rain/src/Foundation/Http/Kernel.php` — extends Laravel HttpKernel (carries pushMiddleware).
- `plugins/logingrupa/metapixel/models/Settings.php` — existing stub `lookupForSite`, `beforeSave` + `splitEventNameInput` + `partitionEventNames` pattern.
- `plugins/logingrupa/metapixel/models/FailedEvent.php` — existing model, fillable + jsonable + casts.
- `plugins/logingrupa/metapixel/classes/queue/SendCapiEvent.php` — existing Phase 2 wiring; `writeFailedEvent` populates `subject_type` + `subject_id`.
- `plugins/logingrupa/metapixel/classes/meta/MetaClient.php` — existing `sendForPixel` (Phase 4 adds `fetchTestEventsStatus`).
- `plugins/logingrupa/metapixel/phpstan.neon` — disallowed-calls deny-list pattern (Phase 4 extends with `Settings::get('pixel_id'|'capi_access_token')` outside `lookupForSite`).
- `plugins/logingrupa/metapixel/composer.json` — existing requires; Phase 4 adds PSL parser.
- `plugins/logingrupa/metapixel/updates/CreateMetapixelFailedEventsTable.php` — schema reference for column-add migration.

### Secondary (MEDIUM confidence — verified web sources)

- `https://packagist.org/packages/jeremykendall/php-domain-parser` — version 6.4.0, MIT, 13.4M installs, ext-filter required, last release 2025-04-26.
- `https://github.com/jeremykendall/php-domain-parser` (README) — `Rules::fromPath`, `Domain::fromIDNA2008`, `ResolvedDomainName::subDomain/secondLevelDomain/suffix` API. PSR-16 cache integration optional.
- `https://developers.facebook.com/docs/marketing-api/conversions-api/dataset-quality-api/` (Meta) — Dataset Quality endpoint surface (exact response shape requires live verification).
- `https://octobercms.com/forum/post/how-to-register-middlewares-in-octobercms-plugin` — `pushMiddleware` from `Plugin::boot()` canonical pattern.

### Tertiary (LOW confidence — needs validation during execution)

- Exact JSON response shape of `GET /v23.0/{pixel_id}/?fields=event_match_quality,deduplication_rate` — Meta does not publish a stable contract. Tolerant parser strategy in Pattern 10 mitigates.
- IDN host resolution edge cases beyond the tested matrix (Greek/Cyrillic/Han IDN domains). HOST-05 test matrix per CONTEXT covers the standard cases.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — every dep verified in-tree or on packagist with full version + license + source URL.
- Architecture (Multisite, middleware, controller): HIGH — sibling examples in-tree; trait source read line-by-line.
- Pitfalls: MEDIUM — most verified by code reading; #1 (`SettingModel::$instances` leak across `Site::withContext`) is a derived hypothesis from reading `getCacheKey()` line 222-225 + Laravel `remember()` semantics; MULT-05 test will confirm/refute at execution time.
- Meta Dataset Quality endpoint shape: MEDIUM — surface confirmed; response shape requires live verification. Tolerant parser strategy mitigates.

**Research date:** 2026-05-19
**Valid until:** 2026-06-19 (30 days — stable phase; jeremykendall/php-domain-parser is stable, OctoberCMS v4 + Laravel 12 stack is locked).
