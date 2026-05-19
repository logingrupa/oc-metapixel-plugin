# Phase 4: Settings rework — Multisite + TrustedHosts + Cookie + FailedEvents + translations — Context

**Gathered:** 2026-05-19
**Status:** Ready for planning

<domain>
## Phase Boundary

Marketplace-ready Settings layer plus the cookie middleware + backend recovery UI + i18n surface that turn the v2.0 plugin into a clean Composer install. Concretely:

1. **Multisite credentials** — `models/Settings.php` adds `use Multisite;`. `pixel_id` + `capi_access_token` route per-site via the trait; `$propagatable = []` empty whitelist prevents cross-site token leak (P-10). `Settings::lookupForSite(?int $iSiteId): array{pixel_id,capi_access_token}` becomes the only credential-lookup contract (already wired in Phase 2 as a stub; Phase 4 re-implements the body).
2. **TrustedHosts allowlist** — operator-supplied `trusted_hosts` textarea (one host per line). New `Classes\Helper\HostIndexResolver` wraps `jeremykendall/php-domain-parser ^6.4` against a bundled `resources/data/public_suffix_list.dat` snapshot to compute the Meta `_fbp` subdomain-index correctly for multi-TLD hosts (`.co.uk`, `.com.br`, IDN). New artisan command `metapixel:refresh-psl` updates the snapshot on demand. PSL cache lives at `storage/app/metapixel/psl/` (Forge-writable, prevents P-18).
3. **EnsureFbpFbcCookies middleware** — fresh implementation (NOT a v1.x port). Honors `Settings::get('ensure_fbp_fbc_server_side', true)` kill switch. CR-03 fbclid validation (`[A-Za-z0-9_-]` charset, ≤255 chars) skips `_fbc` on invalid input. Reads `trusted_hosts` + delegates to `HostIndexResolver`. Untrusted host → middleware NO-OPs (fail-safe per CR-02 — closes P-15 marketplace launch blocker).
4. **FailedEvents backend UI** — `controllers/FailedEvents.php` + `controllers/failedevents/` views directory. ListController with columns event_id / event_name / adapter_type / http_status / attempts / created_at / graph_error / dedup_pct / emq / dedup_checked_at. Filters by event_name + adapter_type + date range. Per-row + batch toolbar actions: Replay (synchronous MetaClient call) + CheckDedup (synchronous Graph API call, writes inline dedup_pct/emq/checked_at columns) + Delete. Migration adds the three new dedup columns.
5. **Translations** — `lang/en/lang.php` + `lang/lv/lang.php` populated for every Settings field label + commentAbove, FailedEvents column labels + buttons, backend menu label, error messages. RainLab.Translate-compatible nested structure. EN + LV only — RU dropped per v2.0 scope (operator self-services `lang/ru/lang.php`).

Phase 4 owns 19 requirements (MULT-01..06, HOST-01..06, COOK-01..03, FAIL-01..03, LANG-01) and closes pitfalls **P-10** (Multisite token leak via empty `$propagatable` lock) and **P-15** (TrustedHosts host-spoofing marketplace-launch blocker). P-18 (PSL cache write fails on Forge) is structurally prevented by the `storage/app/metapixel/psl/` cache path lock.

</domain>

<decisions>
## Implementation Decisions

### Multisite credential resolution + migration semantics (Area 1)

- **D-01:** `Settings::lookupForSite(?int $iSiteId): array` falls back **silently** to the default-row value when the per-site row's `pixel_id` (or `capi_access_token`) is empty string OR NULL. Multisite trait reads the per-site row first; treat both `''` and `null` as "not configured for this site". Operator configures the default row once and every site inherits until it overrides. No PluginGuard "disabled per site" branch — that adds friction to the most common single-default + per-site-override pattern with no real safety gain (per-site config is opt-in, not opt-out).
- **D-02:** `Settings::lookupForSite` becomes the ONLY credential-lookup contract in the codebase. PHPStan disallowed-calls config gains rules banning direct `Settings::get('pixel_id')` / `Settings::get('capi_access_token')` reads anywhere outside `Settings::lookupForSite` itself. Forces callers (SendCapiEvent, PluginGuard, Components\PixelHead, Components\EventPixel) through the multisite-aware path. Prevents accidental cross-site credential read from `request()`-derived contexts.
- **D-03:** `MULT-06` migration `updates/add_multisite_pixel_id_and_token.php` is **schema-additive only** — Multisite trait operates at the model-row layer (one row per site keyed by site_id), not via new columns on `system_settings`. Migration body: idempotent `Schema::hasTable` guard + no-op when already migrated. Marketplace fresh-install on single-site OctoberCMS sees zero behavior change (default row remains primary; the trait reads it for site_id=null).
- **D-04:** **MULT-05 test = Pest integration with hermetic SQLite + 2 fake Site rows.** Test setUp seeds 2 `cms_themes`-equivalent rows (id=1 + id=2). FakeAdapter::getSiteId returns 1 for Site-A subject + 2 for Site-B subject. Inserts EventLog rows for both sites + asserts UNIQUE(`subject_type, subject_id, event_name, channel, site_id`) constraint allows both (NULL-distinct semantics — same subject_id, different site_id is two rows). MySQL/SQLite handle this identically post-MySQL-8.0.13 / SQLite-3.35; call out parity in test docblock. 8-path matrix: 2 sites × 2 adapters (ShopaholicOrderAdapter + ShopaholicCartPositionAdapter via FakeAdapter aliases) × 2 channels (capi + pixel).

### FailedEvents UI + Replay/CheckDedup execution model (Area 2)

- **D-05:** **Replay = synchronous MetaClient call.** Controller action `onReplay($iId)` resolves the FailedEvent row, hydrates the adapter via `AdapterRegistry::resolveByClass($obRow->adapter_type)`, calls `MetaClient::sendForPixel($sPixelId, $sToken, $arPayload)` inline, increments `attempts++`, flash-success on HTTP 200 OK, flash-error + write `graph_error` on failure. Admin tab blocks 1–3 seconds typical Meta latency. Matches every other backend save-action UX. No queue dependency for this rare admin action. Replay button on each row + batch toolbar action.
- **D-06:** **CheckDedup writes inline columns.** Migration `updates/add_dedup_columns_to_failed_events.php` adds `dedup_pct DECIMAL(5,2) NULL`, `emq DECIMAL(4,2) NULL`, `dedup_checked_at DATETIME NULL` to `logingrupa_metapixel_failed_events`. Controller action `onCheckDedup($iId)` calls `MetaClient::fetchTestEventsStatus($sPixelId, $sTestEventCode, $sEventId)` via Graph API, parses JSON response, writes the three columns onto the row, returns JSON for live list refresh. ListController shows dedup_pct + emq + checked_at columns. Empty cell when never checked.
- **D-07:** **Full batch toolbar** — checkbox-driven multi-select. Three batch actions: Replay (loops `onReplay`), CheckDedup (loops `onCheckDedup`), Delete (truncates selected rows). Per-row buttons mirror the batch actions for single-row workflow. List shows the standard October checkbox column. Bulk operations stay synchronous (one Graph API call per row in a loop) — acceptable because dead-letter table size stays small (10s of rows, not thousands).
- **D-08:** **`controllers/FailedEvents.php`** extends `Backend\Classes\Controller` with `Backend.Behaviors.ListController` only — no FormController. Read-only audit UI; rows are written by `SendCapiEvent::writeFailedEvent` after retries exhaust, never created by admin. Action buttons live in `_list_toolbar.php` + per-row `recordOnClick`. `config_list.yaml` declares filters + columns. Backend menu registered in `Plugin::registerNavigation()` under "Settings" parent.

### PSL bundling + refresh model (Area 3)

- **D-09:** **PSL ships bundled at composer install.** `resources/data/public_suffix_list.dat` committed to git. `composer require logingrupa/oc-metapixel-plugin` lands the file ready-to-use — zero network call at install or first-request time. Snapshot frozen at plugin tag time. README documents operator runs `php artisan metapixel:refresh-psl` to update when adding a new ccTLD or annually for hygiene. No auto-refresh cron — Plugin::registerSchedule is NOT used for PSL refresh (avoids cron-reliability concern + makes refresh explicitly operator-initiated).
- **D-10:** **Stale PSL = log warning + continue.** `HostIndexResolver` constructor reads `filemtime(resources/data/public_suffix_list.dat)` once per request lifecycle; if age > 180 days, emits `Log::warning('PSL snapshot is N days old — run php artisan metapixel:refresh-psl')` exactly once (request-scoped flag prevents log spam). Cookies still write — PSL almost never removes entries, only adds new ones, so 12-month-old PSL still correctly resolves every pre-existing host. No "refuse cookies on stale PSL" failure mode — silently breaks tracking for inattentive operators.
- **D-11:** **`metapixel:refresh-psl` artisan command** — fetches `https://publicsuffix.org/list/public_suffix_list.dat` (canonical URL) into a tmp file, validates non-empty + contains expected sentinel lines (`// ===BEGIN ICANN DOMAINS===`), atomic-rename to `resources/data/public_suffix_list.dat`, wipes `storage/app/metapixel/psl/` so the PDP `Rules` cache rebuilds on next request. Idempotent. On HTTP error or validation failure: keep existing file, exit non-zero with stderr message. Uses Guzzle (already a plugin dep). No composer post-install-cmd hook (would break composer install on firewalled hosts).
- **D-12:** **`HostIndexResolver`** is a stateless singleton bound in `Plugin::register()` via `App::singleton(HostIndexResolver::class)`. Constructor loads `Pdp\Rules::fromPath('resources/data/public_suffix_list.dat')` once per request; parsed `Rules` instance cached on the singleton. `resolve(string $sHost): ?int` returns the subdomain-index (1 for apex, 2 for `www.`, etc.) OR `null` for unresolvable host (unknown TLD). Middleware treats `null` as "untrusted" → NO-OP.

### TrustedHosts UX + Settings tab structure (Area 4)

- **D-13:** **`trusted_hosts` = simple textarea, one host per line.** No Repeater, no per-row index override. PSL computes the subdomain-index automatically at request time; operator never needs to know the integer. Empty default (operator MUST populate before the middleware writes any cookies). Mirrors the `theme_custom_event_names` textarea pattern from Phase 3 — operator-friendly + minimal UI surface.
- **D-14:** **STRICT validation in `Settings::beforeSave`.** For each non-empty line: trim → lowercase → validate basic charset (`/^[a-z0-9.-]+$/`) → run through `HostIndexResolver::resolve()` → if PSL returns `null` (unknown TLD), reject the entire save with a Flash::error listing rejected hosts. Idempotent on already-clean input. Operator gets immediate feedback on typos and unknown TLDs; tracking never silently breaks at request time because of a malformed trusted_hosts entry. Operator who legitimately needs a brand-new ccTLD runs `php artisan metapixel:refresh-psl` first.
- **D-15:** **Settings tabs = 4-tab layout** — `tab.pixel_and_capi` / `tab.hosts_and_cookies` / `tab.theme_tracking` / `tab.advanced`. Mapping:
  - **Pixel & CAPI:** `pixel_id`, `capi_access_token`, `test_event_code`, `currency_code`, `paid_status_code`
  - **Hosts & Cookies:** `trusted_hosts`, `ensure_fbp_fbc_server_side` kill switch
  - **Theme Tracking:** `theme_custom_event_names` (Phase 3 field)
  - **Advanced:** `phone_country_code` + reserved space for future ops fields
  Each tab maps cleanly to a README section. Drop v1.x tab name "Compliance" — vague for marketplace audience.
- **D-16:** **`ensure_fbp_fbc_server_side` kill switch** = `switch` field type, default `true`, label "Set _fbp/_fbc cookies server-side", commentAbove explains "Disable if your theme already writes these cookies, or for GDPR consent-banner integration where cookies must wait for opt-in". COOK-01 lock — middleware short-circuits to no-op when toggled off.

### Translations + lang structure (Area 4 / LANG-01)

- **D-17:** **RainLab.Translate-compatible nested structure** — `lang/en/lang.php` returns nested array `['plugin' => ['name','description'], 'settings' => ['label','description'], 'tab' => ['pixel_and_capi',...], 'field' => ['pixel_id','pixel_id_comment',...], 'failed_events' => ['title','columns'=>[...], 'buttons'=>[...]], 'exception' => [...]]`. Twig + YAML access via `logingrupa.metapixel::lang.field.pixel_id`. Mirror in `lang/lv/lang.php`. NO RU file shipped — operator who wants Russian translates from EN themselves.
- **D-18:** **Field labels fresh-written for marketplace audience.** Not a verbatim v1.x EN copy. Wording aimed at marketplace buyer reading Settings page cold: short label + 1-sentence commentAbove explaining what to put in the field and why. LV translations: native (Latvian-fluent author), not machine-translated. Lang key naming follows October convention (snake_case nested under semantic group). Every UI string in this phase + Phase 3 ThemeTracking surface routes through lang files (zero raw English in YAML/Twig).
- **D-19:** **`LANG-01` coverage list** — Settings: tab labels (4), field labels + commentAbove (12 fields × 2 = 24), validation error messages (~6). FailedEvents: page title, list column labels (10), filter labels (3), button labels per-row + batch (8), confirmation modal strings (3). Backend menu: parent ("Settings") + item ("Failed Events"). Total ≈ 60 keys per language × 2 languages = 120 entries. Coverage gate: planner adds a Pest assertion that walks `lang/en/lang.php` array keys + checks `lang/lv/lang.php` exposes the same shape (no missing translations).

### Fresh code, NOT v1.x port (cross-cutting meta-decision)

- **D-20:** **No legacy v1.x code reused.** Every artifact in this phase is a fresh implementation per modern Laravel 12 + October 4 + Lovata.Toolbox idioms. The v1.x `HOST_INDEX_MAP` constant is DELETED — replaced by `HostIndexResolver` + PSL. The v1.x `EnsureFbpFbcCookies` middleware is REWRITTEN — same external contract (sets `_fbp` / `_fbc` cookies with correct subdomain-index, respects kill switch, validates fbclid) but the body re-derives the logic against new dependencies (HostIndexResolver injection, per-site Settings::lookupForSite, P-18 cache path). v1.x decisions (CR-02, CR-03, kill-switch semantics, 90-day TTL, fb.{N}.{ts}.{rand} format) are reused; v1.x code is not. CLAUDE.md "Build philosophy" lock reaffirmed.

### Claude's Discretion

- Migration filename conventions (`updates/2026_05_xx_add_*.php` snake_case per October pattern) — planner picks ordinal numbers based on existing `updates/version.yaml` sequence.
- Backend `_list_toolbar.php` button styling — October's `data-control="popup"` + `oc-icon-bolt` (Replay) / `oc-icon-shield` (CheckDedup) / `oc-icon-trash-o` (Delete) per October ListController convention.
- Exact PHPStan disallowed-calls rule wording for D-02 ban on direct `Settings::get('pixel_id'/'capi_access_token')` — planner writes the `phpstan.neon` patch.
- PSL parser `Rules` instance memoization shape (request-scoped vs. Laravel cache repository) — planner picks based on PDP library docs (request-scoped is sufficient).
- Lang key naming when collision (e.g. multiple `label` keys under different groups) — planner uses semantic group nesting per October convention.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project + plugin standards

- `/home/forge/nailscosmetics.lv/CLAUDE.md` — Project-level: PHP 8.4 stack, Hungarian notation, Tiger-Style fail-fast, October v4 + Laravel 12 idioms, October-specific differences from Laravel.
- `plugins/logingrupa/metapixel/CLAUDE.md` — Plugin-level: namespace `Logingrupa\Metapixel`, PHP 8.3+8.4 dual support (no 8.4-only syntax), locked decisions from v1.1.1, "fresh code NOT v1.x port" build philosophy, Hungarian + October model property conventions, no `assert()`, no `// CR-XX` markers in code.
- `.planning/PROJECT.md` — v2.0 milestone goal + locked decisions table (CR-02 TrustedHosts, CR-03 fbclid, event_id direction, EventLog UNIQUE race-fence, PluginGuard pattern, `$propagatable = []`).

### Roadmap + requirements

- `.planning/ROADMAP.md` §"Phase 4: Settings rework" — phase boundary, 5 success criteria, requirements (MULT-01..06, HOST-01..06, COOK-01..03, FAIL-01..03, LANG-01), pitfall mapping (P-10, P-15, P-18).
- `.planning/REQUIREMENTS.md` lines 69-99 — 19 phase-4 requirement IDs with detailed acceptance criteria.
- `.planning/ROADMAP.md` §"Pitfall Coverage Map" — P-10 (Multisite $propagatable leak), P-15 (TrustedHosts marketplace blocker), P-18 (PSL cache path).

### Phase 2 + 3 context (carry-forward decisions)

- `.planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-CONTEXT.md` — Phase 2 locked: `Settings::lookupForSite` contract (stub), `MetaClient::sendForPixel($sPixelId, $sToken, $arPayload)` per-call credentials, `SendCapiEvent` 4th arg adapter class, `AdapterRegistry::resolveByClass`.
- `.planning/phases/03-shopaholicadapter-themeactionadapter-parallel-wave/03-CONTEXT.md` — Phase 3 locked: ShopaholicAdapter aliases (`shopaholic.order`, `shopaholic.cart_position`), `theme_custom_event_names` Settings textarea + beforeSave sanitization pattern (referenced for D-14 strict validation parallel), EventLog payload column.

### External libs + specs

- `jeremykendall/php-domain-parser ^6.4` README + `Pdp\Rules::fromPath()` docs — PSL parser API surface.
- `https://publicsuffix.org/list/public_suffix_list.dat` — canonical PSL source for `metapixel:refresh-psl` (D-11).
- Meta CAPI Graph API v23.0 — pixel/conversions endpoint, test_events_status payload shape for CheckDedup (FAIL-03 / D-06).
- Meta `_fbp` cookie spec — `fb.{subdomain-index}.{creation-time-ms}.{random}` format.

### Existing v2.0 plugin code (read for current state, NOT port targets)

- `plugins/logingrupa/metapixel/models/Settings.php` — current Phase 2 stub: `lookupForSite` stub body, `theme_custom_event_names` beforeSave sanitization (template for D-14 strict validation), `$propagatable = []` already set.
- `plugins/logingrupa/metapixel/models/FailedEvent.php` — current model: fillable + jsonable + casts. Phase 4 migration adds `dedup_pct` / `emq` / `dedup_checked_at` columns and corresponding fillable + casts.
- `plugins/logingrupa/metapixel/models/settings/fields.yaml` — current Settings field YAML; Phase 4 adds `trusted_hosts` + `ensure_fbp_fbc_server_side` fields and re-groups into 4-tab layout.
- `plugins/logingrupa/metapixel/lang/en/` + `lv/` — empty stubs ready for Phase 4 population.

### v1.x legacy (reference for DECISIONS only — DO NOT port code)

- Git branch `legacy/v1.1.1` paths `middleware/EnsureFbpFbcCookies.php` + `controllers/FailedEvents.php` — historical reference for CR-02/CR-03 wording + fbq cookie format. Re-derive logic per D-20; do not reuse code.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets

- **`Settings::lookupForSite(?int $iSiteId): array`** — Phase 2 stub at `models/Settings.php:35-44` already returns the contract shape. Phase 4 re-implements the body to honor Multisite per-site row routing + default-row fallback (D-01).
- **`Settings::beforeSave()` partition+flash pattern** — `models/Settings.php:55-73` (theme_custom_event_names sanitization) is the template for `trusted_hosts` strict validation (D-14). Reuse `splitEventNameInput` + `partition*` helper shape; parallel pattern keeps the model file consistent.
- **`MetaClient::sendForPixel($sPixelId, $sToken, $arPayload)`** — Phase 2 lock. Used inline by `onReplay` synchronous Replay (D-05).
- **`AdapterRegistry::resolveByClass($sAdapterClass)`** — Phase 2 lock. Used by `onReplay` to rehydrate adapter from `FailedEvent::adapter_type` column.
- **`Logingrupa\Metapixel\Models\FailedEvent`** — exists; migration adds 3 dedup columns + 3 fillable entries + 2 casts.

### Established Patterns

- **PSL cache path lock** — `storage/app/metapixel/psl/` (Forge-writable, NOT `bootstrap/cache/` or any release-relative path). Prevents P-18.
- **Lovata.Toolbox CommonSettings + Multisite trait** — standard October ecosystem combo. `$propagatable = []` empty whitelist already set in `models/Settings.php:30`.
- **PHPStan disallowed-calls is the enforcement mechanism for credential-lookup discipline** — pattern proven Phase 2 for `SiteManager`/`request()` bans in `classes/queue/`, `classes/event/`, `classes/adapter/`. D-02 extends the pattern with a new rule banning direct `Settings::get('pixel_id'|'capi_access_token')` outside `Settings::lookupForSite`.
- **`Plugin::boot()` conditional registration via `PluginManager::instance()->exists(...)`** — Phase 3 lock for ShopaholicAdapter. Not needed in Phase 4 (no cart-plugin dependency in any Phase 4 artifact).
- **Hungarian notation for locals + methods, Laravel-standard names for October model properties** — plugin CLAUDE.md `$fillable` / `$jsonable` / `$casts` stay Laravel-standard; locals stay `$obSettings`, `$arHosts`, `$iSiteId`, `$sHost`.

### Integration Points

- **`SendCapiEvent::handle`** (Phase 2) — already calls `Settings::lookupForSite($iSiteId)`; Phase 4 only changes the body of `lookupForSite`, not the callsite.
- **`EnsureFbpFbcCookies` middleware** is NEW in Phase 4. Registered in `Plugin::boot()` via `Kernel::pushMiddleware(EnsureFbpFbcCookies::class)` or Laravel 12's `bootstrap/app.php` middleware config — planner picks per October 4 standard. Must run AFTER session middleware (cookie response APIs) and BEFORE rendering (so headers are mutable).
- **`Controllers\FailedEvents`** wires to existing `models/FailedEvent.php`. No model changes beyond the dedup column migration.
- **Backend menu registration** — `Plugin::registerNavigation()` adds the FailedEvents menu item; planner picks parent (top-level vs. Settings child).

</code_context>

<specifics>
## Specific Ideas

- **`Settings::lookupForSite` becomes the credential-lookup chokepoint.** Every caller (SendCapiEvent, PluginGuard, PixelHead, EventPixel) goes through it. PHPStan disallowed-calls enforces. This is the architectural anchor for P-10 prevention.
- **Bundled PSL frozen at plugin tag time** — operator install never blocks on Mozilla's CDN. Snapshot age surfaced via `Log::warning` at 180 days. Refresh is operator-explicit (`metapixel:refresh-psl`), not auto-cron.
- **CheckDedup writes inline columns** — `dedup_pct`, `emq`, `dedup_checked_at` on `failed_events`. Lets the ListController show "last known dedup state per dead-letter row" without a separate dedup_logs table. Schema cost = 3 nullable columns on a low-row-count table.
- **4-tab Settings layout** — `Pixel & CAPI / Hosts & Cookies / Theme Tracking / Advanced`. Maps to README sections. Drops v1.x "Compliance" tab name.
- **Strict trusted_hosts validation on save** — PSL-parse each host at save time; reject unresolvable. Forces operator hygiene at the boundary where they have the most context (just typed the host).

</specifics>

<deferred>
## Deferred Ideas

- **Per-row index override in trusted_hosts** (Repeater with manual subdomain-index column) — power-user feature unnecessary if PSL works. Add in v2.1 if operator surfaces a "PSL got my host wrong" support ticket.
- **PSL auto-refresh weekly cron** — Plugin::registerSchedule weekly wire-up. Deferred per D-09 (operator-explicit only). Revisit if marketplace surveys show operators don't refresh and hit stale-PSL bugs.
- **Sync execution timeout fallback to queued dispatch** for Replay — adds complexity (two code paths). Deferred unless real operator surface shows Meta latency > 5s consistently.
- **RU translation file** — dropped per v2.0 scope. Operator self-services `lang/ru/lang.php`. Revisit if marketplace data shows >20% RU operator base.
- **FailedEvents dashboard widget** (backend report widget showing PSL age + dead-letter count + last-refresh date) — Phase 5 polish.
- **Settings export/import as YAML** for cross-site config migration — Phase 5+.
- **`metapixel:refresh-psl` as composer post-install-cmd hook** — rejected per D-11 (breaks composer install on firewalled hosts).

</deferred>

---

*Phase: 04-settings-rework-multisite-trustedhosts-cookie-failedevents-translations*
*Context gathered: 2026-05-19*
