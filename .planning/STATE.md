---
gsd_state_version: 1.0
milestone: v2.0.0
milestone_name: Generic-event-tracking marketplace plugin
status: executing
last_updated: "2026-05-28T12:48:07.693Z"
progress:
  total_phases: 6
  completed_phases: 3
  total_plans: 46
  completed_plans: 36
  percent: 50
---

# Project State

## Active Milestone: v2.0.0 — Generic-event-tracking marketplace plugin

**Goal:** Decouple plugin from Shopaholic via Lovata-style extensible adapter pattern. Marketplace-grade Meta Pixel + CAPI plugin sellable to any OctoberCMS operator regardless of cart-plugin. Third parties can register custom adapters without modifying plugin core. PHP 8.3 + 8.4 dual support.

See `.planning/PROJECT.md` "Current Milestone" section for full feature list + locked decisions.
See `.planning/ROADMAP.md` for 5-phase v2.0.0 roadmap with success criteria.
See `.planning/REQUIREMENTS.md` for 61 v2 requirements + traceability table.

## Current Position

Phase: 06 (viewcontent-funnel-shopaholic-pdp) — EXECUTING
Plan: 1 of 7
Phase 5: PARTIAL (8/10 closed). 05-08 + 05-09 block on Phase 6 ViewContent shipping. 05-13 + 05-14 split out to **Launch Milestone**.
Resume file: `.planning/phases/06-viewcontent-funnel-shopaholic-pdp/06-CONTEXT.md`
Status: Executing Phase 06

**UAT closure 2026-05-27:**

- `05-04-UAT-GATE-2.md` PASS — PageView browser+server dedup confirmed across 5 pages.
- `05-UAT-CUTOVER.md` PASS — AddToCart + Purchase browser+server dedup, FailedEvents admin UI Replay, Multisite per-site pixel_id, Cookie kill switch + TrustedHosts allowlist, Translations en/lv.

**Outside UAT scope:**

- Debug sessions: `pixelhead-no-base-pageview` (resolved by commit 0658788 — close on next pass), `settings-save-host-resolver-di` (OPcache root cause, FPM reload fix applied — close on next pass).
- Pending todo `2026-05-27-enable-optional-queue-for-capi-server-events` deferred to next release (post-v2.0.0).

**Phase 2 re-verification 2026-05-27:** `02-VERIFICATION.md` flipped human_needed → verified. composer qa green (pint ✓ phpstan L10 ✓ phpmd ✓ pest 455/466). 11 pest failures are Phase 5 scope (README + screenshots + CHANGELOG, owned by plans 05-09/05-08/05-12 — TDD tests written ahead of artifact).

**Next action:**

1. `/gsd-plan-phase 6` — author PLAN.md for ViewContent funnel from `.planning/briefs/2026-05-27-viewcontent-funnel-shopaholic.md`. D-1..D-6 locked.
2. Execute Phase 6 waves (PixelHead deferred-flush → ShopaholicProductAdapter+Watcher → JS offer-switch+AJAX → docs).
3. Return to Phase 5: ship 05-08 (smoke + screenshots) + 05-09 (README) with ViewContent in scope.
4. When ready to launch: `LAUNCH SCHEDULED` resume signal → execute Launch Milestone (launch-01 redact + launch-02 public flip + v2.0.0 tag).

## Roadmap Snapshot

| Phase | Name | Requirements | Status |
|-------|------|--------------|--------|
| 1 | Tooling + composer + namespace rename + CI matrix | TOOL-01..11 (11) | Executed (3/3 plans) — pending verification |
| 2 | Adapter system core | ADAP-01..11 (11) | Executed — pending verification (8/8 plans; 11/11 ADAP-*; P-01 P-02 P-05 P-08 P-13 closed; 02-07 contract base + FakeAdapter smoke + backbone integration shipped 2026-05-17) |
| 3 | ShopaholicAdapter + ThemeActionAdapter | SHOP-01..05 + THEM-01..07 (12) | Not started |
| 4 | Settings rework — Multisite + TrustedHosts + Cookie + FailedEvents + translations | MULT-01..06 + HOST-01..06 + COOK-01..03 + FAIL-01..03 + LANG-01 (19) | Not started |
| 5 | Documentation + marketplace launch | DOCS-01..03 + MKT-01..05 (8) | Not started |

**Coverage:** 61/61 v2 requirements mapped (100%). 0 orphaned.

## Previously Shipped: v1.1.1

Closed 2026-05-15. Partial close — Phase 4 + 5 dropped on architecture pivot. See [`milestones/v1.1.1-ROADMAP.md`](milestones/v1.1.1-ROADMAP.md) for full archive.

- 5 phases complete (1, 2, 3.1, 3.1-07, 3.1-08)
- 28/50 v1 requirements validated; 22 dropped; 2 staging-deferred to operator
- 16/21 plans (76%)
- 207 commits, 11,027 PHP lines
- 177 tests / 0 failed (82.8% coverage)
- composer qa green end-to-end
- Tag `v1.1.1` annotated local-only at SHA `3f32ca6`
- Legacy branch `legacy/v1.1.1` preserves full v1.x codebase + tests + `.planning/`

## Accumulated Context

### Decisions carried forward from v1.x (locked, do NOT re-derive)

- **event_id direction = server → frontend.** Meta dedupes on event_id match within ±10s. Never reverse.
- **CR-02 TrustedHosts allowlist.** v1.x hardcoded HOST_INDEX_MAP; v2.0 operator-supplies via Settings + `jeremykendall/php-domain-parser` for multi-TLD index derivation. Untrusted host → skip cookies (fail-safe). Owned by Phase 4 (HOST-01..06).
- **CR-03 fbclid validation.** `[A-Za-z0-9_-]` charset, ≤255 chars. Invalid → skip `_fbc`. Carried forward in Phase 4 COOK-02.
- **Idempotency via EventLog UNIQUE race-fence.** `(subject_type, subject_id, event_name, channel, site_id)`. `EventLogWriter::record` returns false on collision or DB failure (fail-safe). Kept verbatim per ARCHITECTURE.md §2.
- **Multi-site site_id from Subject model attribute.** v1.x reads `Order.site_id` (Lovata column); v2.0 generalizes to `EventSubjectAdapter::getSiteId(object $obSubject): ?int`. Owned by Phase 2 ADAP-01/ADAP-06.
- **PluginGuard pattern.** Empty `pixel_id` → `Log::warning` + disabled flag, never throw at boot. Carried forward verbatim per ARCHITECTURE.md §2.
- **No `assert()`** — prod `zend.assertions=0` silently no-ops. Enforced by `spaze/phpstan-disallowed-calls`. Locked in Phase 1 TOOL-04.
- **content_ids format = `SKU-{product_id}[-{offer_id}]`** for Shopaholic adapter (matches Facebook Catalog feed). Other adapters define own format via `ValueResolver::resolveContentIds()`. Owned by Phase 3 SHOP-02.
- **Anonymous external_id** = sha256 of subject's unique token (Order.secret_key, Session id, etc.) per adapter. Owned by Phase 2 ADAP-08.

### v2.0 architectural decisions (new — locked at milestone start)

- **Namespace:** `Logingrupa\Metapixel` (drop "Shopaholic"). Owned by Phase 1 TOOL-03.
- **Plugin dir:** `plugins/logingrupa/metapixel/`. Owned by Phase 1 TOOL-02.
- **PHP support:** `"php": "^8.3 || ^8.4"` — avoid 8.4-only syntax (no property hooks, asymmetric visibility, `array_find`/`array_any`/`array_all`/`array_find_key`, `#[\Deprecated]`). Enforced by Phase 1 TOOL-04..06.
- **Composer suggest pattern** — `lovata/shopaholic-plugin` becomes `suggest:`. Plugin works without Shopaholic. Owned by Phase 1 TOOL-01.
- **Graph API pinned to `v23.0`** — v20 expires 2026-09-24. Owned by Phase 2 ADAP-09.
- **Lovata-style extensibility:**
  - `AdapterRegistry::register(string $sSubjectClass, string $sAdapterClass)` — third parties register custom adapters from their `Plugin::boot()`. Owned by Phase 2 ADAP-03.
  - Three `Event::fire` decision-point hooks (`metapixel.event.before_dispatch`, `after_dispatch`, `dead_letter`) with documented payload mutability contracts. Owned by Phase 2 ADAP-04.
  - Five additional hooks (adapter.resolve, value.resolve, user_data.resolve, pixel.before_render, settings.lookup) DEFERRED to v2.1 until real third-party use case surfaces.
  - `Component::extend(...)` + `addDynamicMethod(...)` on PixelHead and FailedEvents controller (operator-prefix convention for namespacing).
- **Multisite trait** on `pixel_id` + `capi_access_token` Settings fields (per-site overrides). `$propagatable = []` lock prevents cross-site token leak. Owned by Phase 4 MULT-01..06.
- **Build philosophy (from `feedback-no-overengineering-fresh-simple` memory):** Simple logic, fresh ideas, no over-engineering. No BC shims (operators stay on `legacy/v1.1.1` branch). No dead code, no unused functions, no premature abstractions. Build for current need only.
- **Code style additions (from `feedback-lovata-extensibility-pattern` memory):** DRY, SRP, self-explanatory variable names (no `$mId`, `$tmp`), Laravel short docblocks (one-line summary + `@param` + `@return`; no multi-paragraph narrative), no phase/CR/incident markers in code.

### v2.0 Phase 2 decisions (added during execution)

- **Lowercase folder convention under `plugins/<vendor>/<plugin>/`** — October Rain `ClassLoader::load` normalises namespaced PSR-style lookups by lowercasing every folder portion before the file basename. PascalCase folders (e.g., `classes/Adapter/`, `tests/Doubles/`) cause autoload misses on Linux because the host bootstrap registers October Rain's ClassLoader (the plugin's own `vendor/autoload.php` is NOT loaded by host bootstrap). All v2.0 plan paths therefore ship lowercase: `classes/adapter/`, `tests/doubles/`, etc. Namespaces stay PascalCase (`Logingrupa\Metapixel\Classes\Adapter\…`) — PHP namespace resolution is case-insensitive. Owned by Phase 2 plan 02-01.
- **H-8 test setUp pattern locked across Phase 2:** every Phase 2 test that needs `AdapterRegistry` MUST bind it directly via `$this->app->singleton(AdapterRegistry::class)` in setUp(). NEVER `(new \Logingrupa\Metapixel\Plugin)->register()` — `PluginBase::__construct(Application $app)` requires container injection and the bare instantiation TypeErrors. Plan 02-01 anchors; plans 02-02..02-07 enforce in their setUp() pattern.
- **AdapterRegistry::$arAdapterMap PHPDoc key type is `array<string, …>`, NOT `array<class-string, …>`.** `register()` accepts a plain string subject FQN; PHPStan level 10 cannot narrow `string` to `class-string` without an extra runtime check the registry deliberately does not add (no benefit — `is_subclass_of` on the adapter side already enforces the value-type contract). Owned by Phase 2 plan 02-01.
- **Site facade FQN verified as `October\Rain\Support\Facades\Site`** (NOT `October\Rain\Cms\Site` as RESEARCH §5.1 assumed — that namespace does not exist in this October build; `vendor/october/rain/src/` contains no `Cms/` subdir). **SiteManager FQN verified as `System\Classes\SiteManager`** (at `modules/system/classes/SiteManager.php` line 18). `phpstan.neon` bans both FQNs (belt-and-suspenders) + `Illuminate\Http\Request::*` + global `request()` helper, all four via H-1 `disallowIn` deny-list scoped to lowercase `classes/queue/*`, `classes/event/*`, `classes/adapter/*`. P-01 cross-context-resolution-drift is now statically enforced. Owned by Phase 2 plan 02-02.
- **PHPStan disallowed-calls uses H-1 `disallowIn` deny-list (NOT `allowIn` allow-list).** Outside the three adapter/queue/event dirs the banned calls are PERMITTED — middleware/, controllers/, components/ legitimately read Request; `classes/helper/` + `classes/meta/` MAY call SiteManager but `SiteResolver` itself MUST NOT (enforced by Plan 02-04 Task 3's static-source regex grep test on `SiteResolver.php`, not by phpstan rule — defence-in-depth). Owned by Phase 2 plan 02-02.
- **Plugin `CLAUDE.md` "Extensibility contract" ranks third-party hooks 1–6 in order of preference** (P-13 convention lock). Event::fire hooks rank 2–4; `Component::extend` + `addDynamicMethod` rank 6 as LAST RESORT with mandatory `onMetapixel*` dynamic-method prefix to avoid third-party collisions. `metapixel.event.before_dispatch` listeners MUST NOT mutate `event_id` or `event_time` (dedup contract anchor — Meta dedupes server-pixel on `event_id` match within ±10s of `event_time`). Owned by Phase 2 plan 02-02.
- **Migration file naming convention: PascalCase basenames matching class FQN** (H-5 spike resolution). Plugin cannot run standalone `composer install` (private October packages not on a public registry) → autoload-dev classmap declared in `composer.json` never registers. October Rain ClassLoader's `loadUpperOrLower` resolves `Logingrupa\Metapixel\Updates\CreateMetapixelEventLogTable` via the `upperClass` branch (lowercase folder + PascalCase basename). October's `Updater::resolve` `require`s files by path from `version.yaml` — runtime migration path does not need autoload. Lovata snake_case migration convention is reserved for files that do not need FQN resolution from tests/phpstan. Owned by Phase 2 plan 02-03a. **Going forward, all plugin code that must be FQN-loadable from tests uses PascalCase basenames matching the class name.**
- **Storage backbone shape locked**: `logingrupa_metapixel_event_log` (UNIQUE on subject_type/subject_id/event_name/channel/site_id — race-fence anchor) + `logingrupa_metapixel_failed_events` (UNIQUE on event_id/http_status; nullable subject_type+subject_id columns enable H-2 admin UI re-resolution). EventLog model has NO `subject()` MorphTo — subject_type is opaque alias (P-05 anchor enforced by `assertFalse(method_exists(EventLog::class, 'subject'))` in T25). FailedEvent.payload cast to array. Owned by Phase 2 plan 02-03a.
- **Settings model extends Lovata.Toolbox CommonSettings** (NOT October's `SettingModel` directly) — inherits Multisite trait + RainLab.Translate behavior + `$settingsFields` convention. Phase 2 stub: `$propagatable = []` lock + static `lookupForSite(?int $iSiteId): array{pixel_id, capi_access_token}` that ignores $iSiteId. Phase 4 MULT-01..02 introduces the per-field whitelist (pixel_id + capi_access_token enter $propagatable); MULT-03 re-implements lookupForSite to honor per-site rows without changing the public signature. Owned by Phase 2 plan 02-03b.
- **PluginGuard cascade-safety pattern locked.** `final class PluginGuard` exposes `isDisabled(): bool` (memoised via static `?bool $bIsDisabled`) + `reset(): void`. Empty `Settings::get('pixel_id', '')` → `Log::warning` + cached true; non-empty → false. NEVER throws at boot — throwing would cascade through October's plugin chain and break unrelated plugins (Campaigns, PromoMechanism). Production memo lifecycle: one Log::warning per request. Test isolation: explicit reset() in setUp + tearDown. Owned by Phase 2 plan 02-03b.
- **Exception hierarchy locked.** `MetaPixelException` (abstract base extends `\RuntimeException`; constructor `(string, int, ?Throwable, array $arContext)` + `getContext(): array`) → 4 finals: `MissingPixelConfigException` + `MissingCapiTokenException` (event-fire-time empty credentials) + `MetaApiTransientException` (HTTP 408/429/5xx + ConnectException → caller retries) + `MetaApiPermanentException` (HTTP 4xx non-408/429 → caller persists FailedEvent + fires dead_letter). The 2 HTTP exceptions carry `?int $iHttpStatus` + `getHttpStatus(): ?int`; iHttpStatus also surfaces via RuntimeException's int $code (via `?? 0`). Constructor shape `(string, ?int, ?Throwable, array)` consistent across both HTTP exceptions so SendCapiEvent::failed can stamp http_status the same way writeFailedEvent does (plan 02-06 L-5 cross-check). Owned by Phase 2 plan 02-03b.
- **PHPStan level 10 mixed-cast pattern locked.** `Settings::get` returns `mixed` (inherited from October's `SettingModel`); naive `(string) Settings::get(...)` fires `cast.string` identifier. CLAUDE.md project lock forbids `@phpstan-ignore` comments. Replaced with `$mValue = Settings::get(...); is_string($mValue) ? $mValue : ''` runtime guard. Applied in PluginGuard::isDisabled + Settings::lookupForSite. Apply same pattern to any future Settings::get callsite at phpstan level 10. Owned by Phase 2 plan 02-03b.
- **`@method` docblock pattern for Lovata.Toolbox CommonSettings descendants.** Declare `@method static mixed get(string $sCode, mixed $mDefault = null)` + `@method static void set(array<string, mixed> $arValues)` on the subclass to satisfy larastan inheritance resolution at phpstan level 10. Lovata's own CommonSettings does not declare them; descendants need it because larastan does not walk through October's SettingModel→Model dynamic-method chain at full strictness. Owned by Phase 2 plan 02-03b.
- **`Settings::clearInternalCache()` test-isolation pattern.** SettingModel keeps a static `$instances` cache that survives across tests (DB resets per test via in-memory SQLite, instance cache does not). Tests asserting fresh Setting state MUST call `Settings::clearInternalCache()` in setUp(). If plan 02-04+ tests need it broadly, consider moving to MetapixelTestCase::setUp() — deferred until a second consumer surfaces. Owned by Phase 2 plan 02-03b.
- **Coverage gate carrier pattern: any plan that adds methods to Plugin.php MUST also extend PluginSanityTest** to cover them — otherwise the Plugin.php denominator grows + coverage % drops below the 90% gate even when the new files themselves are at 100%. Plan 02-03b extended PluginSanityTest with `test_register_settings_returns_descriptor_for_settings_model` to close the gate on `registerSettings()`. Owned by Phase 2 plan 02-03b.
- **SiteResolver shape locked.** `final class SiteResolver` under `classes/helper/` exposes one public static method `forSubject(object $obSubject, EventSubjectAdapter $obAdapter): ?int` with body `return $obAdapter->getSiteId($obSubject);` — one line, no defensive guards beyond type hints. PHPDoc documents cross-context determinism in prose with lowercased "site manager" / "request" wording so the T6 static-source regex defence test (`assertDoesNotMatchRegularExpression('/\bSiteManager\b/', $sSource)`) finds zero matches. Three layers of guard: (1) phpstan disallowed-calls on classes/{queue,event,adapter}/ (plan 02-02); (2) T6 static-source regex on SiteResolver.php source (plan 02-04); (3) the one-line delegating body itself. SiteResolver lives OUTSIDE the phpstan deny-list scope by design — defence-in-depth via the test, not via phpstan. Owned by Phase 2 plan 02-04.
- **EventLogWriter shape locked.** `final class EventLogWriter` under `classes/helper/` exposes one public static method `record(string $sEventId, string $sEventName, string $sChannel, object $obSubject, ?string $sSecretKey, int $iEventTime, ?int $iSiteId): bool`. Resolves adapter via `App::make(AdapterRegistry::class)->resolveFor($obSubject)` — null adapter → Log::warning + false; reads opaque `subject_type` via `$obAdapter->getSubjectType($obSubject)` (P-05 anchor); rejects `getSubjectId() <= 0` → Log::warning + false; calls `DB::table('logingrupa_metapixel_event_log')->insertOrIgnore([...])` → returns `$iAffected === 1`; outer try/catch swallows any Throwable to Log::critical + false (fail-safe — peer-wins assumption). Two `get_class($obSubject)` calls live in Log diagnostic arrays only (never as subject_type writes). L-4 lock: Log/App/DB imported via `Illuminate\Support\Facades\` FQN. Owned by Phase 2 plan 02-04.
- **NULL-distinct UNIQUE semantics on race-fence — write-site implication.** SQLite-in-memory + MySQL InnoDB both treat multiple NULL values in a UNIQUE column as DISTINCT. The race-fence anchor test in EventLogWriterRaceFenceTest MUST use non-null site_id to actually exercise the constraint; a dedicated NULL-distinct test (case 3) verifies the null-twin path. Plan 02-06 (SendCapiEvent) MUST resolve site_id via SiteResolver::forSubject BEFORE calling EventLogWriter::record — passing site_id=null when a real site exists silently disables the race-fence for sibling NULL inserts. Owned by Phase 2 plan 02-04; consumed by plan 02-06.
- **MetaClient shape locked.** `final class MetaClient` under `classes/meta/` exposes one public method `sendForPixel(string $sPixelId, string $sToken, array $arPayload): array`. Public const `META_GRAPH_API_VERSION = 'v23.0'` (D-18 — v20 expires 2026-09-24, no operator override). Private const `TRANSIENT_STATUS_CODES = [408, 429, 500, 502, 503, 504]`. Constructor accepts optional `?ClientInterface` for test injection. Empty pixel_id → `MissingPixelConfigException`; empty token → `MissingCapiTokenException`; ConnectException → `MetaApiTransientException` with original as previous + null http_status; 2xx → decoded body; 408/429/5xx → `MetaApiTransientException` with status; 4xx (other) → `MetaApiPermanentException` with status. URL `{base}/v23.0/{pixel}/events`; `access_token` merged INTO POST body JSON (NEVER URL — T-02-05-04 webserver-log leak mitigation). `http_errors => false` so we classify ourselves. Private `decodeBody(string): array<string, mixed>` helper resolves phpstan level 10 return.type narrowing on json_decode's mixed return without `@phpstan-ignore`. Owned by Phase 2 plan 02-05.
- **PayloadBuilder shape locked.** `final class PayloadBuilder` under `classes/meta/` exposes one public method `buildEventPayload(string $sEventName, EventSubjectAdapter, object, ValueResolver, string $sEventId, int $iEventTime, array $arEventExtras): array`. Constructor-injects `UserDataHasher` via readonly promoted property. Private const `ACTION_SOURCE = 'website'`. Body has zero event-name comparisons of any form — H-9 grep gate `! grep -E '\$sEventName\s*(===|!==|==)|switch\s*\(\s*\$sEventName|match\s*\(\s*\$sEventName|in_array\s*\(\s*\$sEventName' classes/meta/PayloadBuilder.php` exits 0. Merge order: ValueResolver-derived custom_data first, then `$arEventExtras` overlay via array_merge — adapter can override `content_type` / `currency` for non-ecommerce events (ViewContent on CMS article → `content_type='article'`). Future events (AddToCart, Lead, ViewContent) ship by authoring a new adapter, NEVER by editing the builder. Owned by Phase 2 plan 02-05.
- **UserDataHasher shape locked (M-4: stateless).** `final class UserDataHasher` under `classes/meta/` exposes one public method `forSubject(EventSubjectAdapter, object): array<string, ?string>`. Private const `HASHABLE_FIELDS = ['em', 'ph', 'fn', 'ln', 'ct', 'st', 'zp', 'country', 'external_id']` (9) + `PASSTHROUGH_FIELDS = ['fbp', 'fbc', 'client_ip_address', 'client_user_agent']` (4) = 13 returned keys. Hashable: `hash('sha256', strtolower(trim($sValue)))`; passthrough: as-is. Null/empty → null (NEVER `hash('sha256', '')` which would collide across unrelated senders). **M-4 lock: stateless — no `$arMemo` property, no `reset()` method, no memo tests.** Phase 3 ThemeEventCollector adds memo when a real cross-event repeat surfaces. ~45 LOC after memo removal. Owned by Phase 2 plan 02-05.
- **PHPUnit 12 #[DataProvider(...)] attribute replaces @dataProvider annotation.** PHPUnit 12 dropped annotation discovery; legacy `@dataProvider` annotations on test methods now fail with `ArgumentCountError: Too few arguments to function …, 0 passed`. Pattern: declare `public static function provideXxx(): array` + decorate test method with `#[DataProvider('provideXxx')]` attribute + `use PHPUnit\Framework\Attributes\DataProvider;`. Applied in MetaClientTest's 2 dataProvider methods (transient + permanent status codes). Pattern carries forward for any future test file using dataProviders. Owned by Phase 2 plan 02-05.
- **`@phpstan-ignore` is banned project-wide (CLAUDE.md).** When phpstan level 10 narrowing fails on `json_decode` mixed-return or similar, extract a private helper that walks the decoded shape with explicit type assertions. Example: `MetaClient::decodeBody(string): array<string, mixed>` uses `foreach + (string) $mKey` cast to satisfy the return.type identifier. Same pattern as `Settings::lookupForSite`'s `$mValue = Settings::get(...); is_string($mValue) ? $mValue : ''` runtime guard. Owned by Phase 2 plans 02-03b + 02-05.
- **guzzlehttp/guzzle in plugin composer require (H-4 marketplace contract).** Plugin `composer.json` `require:` declares `"guzzlehttp/guzzle": "^7.8"`. H-4 lock: do NOT run `composer update` from plugin dir — plugin packages don't carry composer.lock. Operator runs `composer update logingrupa/oc-metapixel-plugin --with-dependencies --no-interaction` from project root to refresh the project lockfile. In this repo Guzzle is already a transitive Laravel/October dep; the explicit require pins it for the marketplace standalone-install case. Owned by Phase 2 plan 02-05.
- **SendCapiEvent shape locked.** `final class SendCapiEvent implements ShouldQueue` under `classes/queue/` uses Dispatchable + InteractsWithQueue + Queueable + SerializesModels traits. 4-arg constructor `(string $sEventName, array $arPayload, object $obSubject, string $sAdapterClass)` per D-20. 3 hook constants `HOOK_BEFORE_DISPATCH`/`AFTER_DISPATCH`/`DEAD_LETTER`. `$tries=3`, `$backoff=[1,4,16]`. handle() orchestrates: AdapterRegistry::resolveByClass rehydrate (BindingResolutionException → writeFailedEvent(null adapter) + Log::critical + return) → fireBeforeDispatchHalt (halt-able with P-08 snapshot+restore) → SiteResolver::forSubject → EventLogWriter::record race-fence → Settings::lookupForSite → MetaClient::sendForPixel (transient rethrow / permanent dead-letter / happy after_dispatch). failed(Throwable) handler resolves adapter via AdapterRegistry::resolveByClass (L-5) + writes FailedEvent + fires dead_letter. writeFailedEvent accepts `?EventSubjectAdapter $obAdapter` and populates FailedEvent.subject_type + subject_id from it when non-null (H-2 — Phase 4 admin UI re-resolution). All 3 Event::fire fire sites wrapped in try/catch (Throwable → Log::warning + abstain/observed). L-4 FQN imports. PHPStan deny-list bans Site/SiteManager/Request inside classes/queue/* — SendCapiEvent has zero references. Private `firstEventRecord(): array<string, mixed>` narrows mixed[][0] access for level 10 — 3rd repo use of the helper-narrowing idiom (after Settings::lookupForSite runtime guard + MetaClient::decodeBody). Owned by Phase 2 plan 02-06.
- **MetaClient `final` keyword dropped (Rule 1 carry-over fix from plan 02-05).** SpyMetaClient (shipped in 02-05 as deferred fixture) declared `class SpyMetaClient extends MetaClient` but on-disk MetaClient was `final` → cannot instantiate. The 02-05 tests never instantiated SpyMetaClient; the contradiction surfaced on the first plan 02-06 hook unit test run. Plan 02-06 also requires inline anonymous-class subclass of MetaClient in DeadLetterHookTest for the dead-letter branch. Dropped final. Production behavior unchanged; only extension surface opens. Owned by Phase 2 plan 02-06.
- **Event::fire halt-only on before_dispatch (OQ-2 resolution).** `Event::fire($name, $payload, $halt=true)` third arg activates Laravel Dispatcher's halt-on-non-null-response semantics. Listener returning literal `false` vetoes; the dispatcher returns `false` (non-null), `=== false` strict-compare halts. Payload is by-reference (`&$arPayload`) ONLY on before_dispatch — the other 2 hooks pass by value. T11 + T19 verify the halt; T13 verifies that throwing listeners do NOT halt (separate code path from the halt-on-false semantics). PHPDoc on the class-level hook contracts block documents the asymmetry. Owned by Phase 2 plan 02-06.
- **P-08 snapshot+restore for event_id / event_time.** Inside `fireBeforeDispatchHalt`, snapshot `$arPayload['data'][0]['event_id']` + `event_time` into local vars BEFORE firing the hook; restore from the snapshot AFTER the hook returns. A misbehaving listener that mutates either field cannot break the Meta dedup contract (Meta dedupes server-pixel on `event_id` match within ±10s of `event_time`). T12 BeforeDispatchPayloadMutationTest enforces both halves — custom_data mutation propagates AS INTENDED; event_id mutation REVERTS to the snapshot. Owned by Phase 2 plan 02-06.
- **writeFailedEvent ?EventSubjectAdapter contract (H-2 resolution).** Helper signature `writeFailedEvent(Throwable $obException, ?int $iHttpStatus, ?EventSubjectAdapter $obAdapter): void`. When `$obAdapter` is non-null, populates `subject_type` + `subject_id` from `getSubjectType` + `getSubjectId`. Only the BindingResolutionException early-return path passes null (legitimate — adapter does not exist; re-resolution is impossible). Phase 4 admin UI (FAIL-01..03) re-resolution depends on subject_type + subject_id being populated on every other failure path. T18 verifies the null path (legitimate); T21 + T14 verify the populated path. Owned by Phase 2 plan 02-06.
- **L-5 failed() retry-exhaustion adapter-resolve.** `failed(Throwable)` resolves adapter via `app(AdapterRegistry::class)->resolveByClass($this->sAdapterClass)` the same way handle() does, then writes FailedEvent + fires dead_letter. Inner try/catch swallows resolution failure (worker reload scenario) so the FailedEvent can still be written with null subject_type/id (same fail-safe as handle()'s BindingResolutionException). Keeps failed_events row state consistent across handle / failed paths. SendCapiEventFailedHandlerTest 2 cases (adapter-resolve + unresolvable-fallback) enforce. Owned by Phase 2 plan 02-06.
- **Listener-isolation try/catch envelope around every Event::fire (D-16 + ADAP-05).** Every Event::fire site is wrapped in try/catch — Throwable → Log::warning + continue (treat as abstain on before_dispatch; observed on after_dispatch + dead_letter). Listener exceptions never propagate to dispatch. T13 + SendCapiEventBranchCoverageTest::test_after_dispatch_listener_exception_is_swallowed + test_dead_letter_listener_exception_is_swallowed enforce on each of the 3 hooks. Owned by Phase 2 plan 02-06.
- **EventSubjectAdapterContractTestCase abstract base locked.** `abstract class Logingrupa\Metapixel\Classes\Testing\EventSubjectAdapterContractTestCase extends Logingrupa\Metapixel\Tests\MetapixelTestCase` (R2 YAGNI override per commit db89398 — orchestra/testbench DROPPED for Phase 2). Two abstract factory methods: `makeAdapter(): EventSubjectAdapter` + `makeSubject(): object`. 10 public `test_invariant_NN_*` methods enforce the EventSubjectAdapter marketplace contract: 01 subject_type opaque alias (no backslash, ≤ 64 chars), 02 subject_id positive int, 03 getSiteId deterministic across successive calls, 04 getSiteId returns ?int (no Request side effect), 05 getSecretKey ?string never throws, 06 getValueResolver returns ValueResolver instance, 07 getUserData allowed-key set (13-key Meta CAPI subset, string|null values), 08 getSupportedEvents shape (string event name → list of {capi,pixel} channels), 09 registry round-trip via AdapterRegistry::register + resolveFor, 10 PayloadBuilder envelope shape with 6 inner keys. M-6 tearDown forgets AdapterRegistry singleton after invariant 09's registration so subclasses + concrete tests are isolated. Phase 3 first-party adapters (ShopaholicOrderAdapterContractTest + ThemeActionAdapterContractTest) extend this base + supply makeAdapter() / makeSubject(); third-party marketplace adapters revisit at v2.1 (Testbench swap OR copy-this-file pattern via docs/CUSTOM-ADAPTERS.md). Owned by Phase 2 plan 02-07.
- **classes/testing/ symmetric exclusion from phpstan + phpunit coverage.** The contract base imports `Logingrupa\Metapixel\Tests\MetapixelTestCase` (autoload-dev) from a production PSR-4 directory. phpstan's production-scan path includes `classes/`, so it tries to load MetapixelTestCase + its October test traits (`InteractsWithAuthentication`, `PerformsMigrations`, `PerformsRegistrations`) which are not present outside the test bootstrap → phpstan fatals with `Trait not found`. Fix: add `classes/testing (?)` to `phpstan.neon` excludePaths + `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/classes/testing (?)` to `/tmp/metapixel-phpstan-smoke.neon`. Symmetric with phpunit.xml's `<source><exclude><directory>./classes/testing</directory></exclude>` (test-helper code, not production runtime behaviour). Belt-and-braces: phpstan exclusion prevents static-analysis trip; phpunit exclusion prevents coverage dilution from the 10 invariant test bodies (which ARE executed via concrete subclasses + Pest, but their coverage shape is test-helper, not production). Owned by Phase 2 plan 02-07.
- **M-5 serialize round-trip smoke pattern.** Synchronous tests (handle() direct invocation) skip the serialize/unserialize cycle production Laravel queue workers execute. BackboneIntegrationTest::test_serialize_round_trip_job_unserializes_and_runs_handle confirms `unserialize(serialize($obJob))->handle()` writes EventLog — catches SerializesModels-for-subject failure mode that pure-sync tests miss. Pattern applies to any future v2.x ShouldQueue job. Phase 3 SendShopaholicCapiEvent + SendThemeActionCapiEvent (if separate jobs land) MUST add the same smoke. Owned by Phase 2 plan 02-07.
- **H-7 Middleware::history > MockHandler queue count pattern.** MockHandler internal queue count is unreliable for HTTP-call-count assertions — pending mocks stay queued; assertCount on the handler does not match request count. Push `Middleware::history($arHistory)` onto the HandlerStack BEFORE the MockHandler; assert `count($arHistory)` for accurate call count. BackboneIntegrationTest::test_dedup_second_dispatch_for_same_subject_short_circuits_no_http_call uses this pattern. Carry forward to Phase 3 + Phase 4 integration tests. Owned by Phase 2 plan 02-07.
- **M-7 ROADMAP.md SC5 mismatch flag (orchestrator action pending).** ROADMAP.md Phase 2 SC5 wording references 4 v1.x test files (`OrderStatusWatcherEventLogTest`, `PurchasePixelEventLogGateTest`, `SendCapiEventEventLogTest`, `MultiSiteEventLogTest`) that OQ-1 reframes as Phase 3 work alongside ShopaholicOrderAdapter (SHOP-03). Plan 02-07 does NOT update ROADMAP.md (per plan must_haves lock); the mismatch is flagged in 02-VERIFICATION-INPUTS.md with suggested replacement wording. Orchestrator (NOT executor) applies the ROADMAP.md edit post-`/gsd:verify-phase`. Owned by Phase 2 plan 02-07 (flag only) + orchestrator (apply fix).

### Pitfall ownership (each CRITICAL/HIGH pitfall mapped to a phase)

See ROADMAP.md "Pitfall Coverage Map" section.

Anchored CRITICALs:

- **P-01 Cross-context resolution drift** (Phase 3.1-07 production bug anchor): Phase 2 ADAP-06 prevents via SiteResolver::forSubject (plan 02-04 logic) + PHPStan disallowed-calls (plan 02-02 enforcement) + contract test invariant (plan 02-07). **Logic + static enforcement layers closed**; contract test invariant pending plan 02-07.
- **P-03 Hidden Lovata imports outside adapter dir**: Phase 1 TOOL-11 prevents via composer-dependency-analyser; Phase 3 SHOP-04 enforces at adapter boundary.
- **P-05 EventLog subject_type alias ambiguity**: Phase 2 ADAP-01 locks alias-string convention (plan 02-01); EventLog model has no MorphTo (plan 02-03a); EventLogWriter reads subject_type via `$obAdapter->getSubjectType($obSubject)` not get_class (plan 02-04 write-site anchor); Phase 3 SHOP-01 returns `'shopaholic.order'`. **Write-site anchor closed**; persistence-side already closed in plan 02-03a (no MorphTo).
- **P-06 PHP 8.4-only syntax slips**: Phase 1 TOOL-04 phpstan `phpVersion: 80300` + TOOL-05 Rector UP_TO_PHP_83 + TOOL-06 Pint nullable rule.
- **P-15 TrustedHosts marketplace blocker**: Phase 4 HOST-01..06 MUST close before Phase 5 marketplace launch.

### Pending Todos

- `/gsd-verify-phase 01` to verify Phase 1 execution outcomes (3 plans / 11 TOOL-* requirements).
- Phase 2 PHPStan `paths` reopen: when components/ lands, append to phpstan.neon paths list (Plan 02-01 added `classes`, Plan 02-03a added `models`; `classes/meta/` already in scope via the `classes` directory entry).
- Phase 2+ phpunit.xml `<source><include>` reopen: when components/, middleware/, controllers/, console/ land, add each as `<directory>` entry alongside existing `Plugin.php` + `./classes` + `./models` (Plan 02-01 added `./classes`; Plan 02-02 added `./models`; `./classes/meta` covered by `./classes` recursive scan).
- Phase 3 SHOP-* adds `<testsuite name="Metapixel Adapter Tests">` block to phpunit.xml when tests/Unit/Adapter/Shopaholic + tests/Feature/Adapter/Shopaholic land (Run B's --exclude-testsuite='Metapixel Adapter Tests' becomes a real exclude then; currently a no-op).
- Phase 2 ADAP-03 wires AdapterRegistry::flush() call into MetapixelTestCase::flushModelEventListeners() (currently absent — Phase 1 plan 01-03 intentionally did not add a placeholder comment).
- Phase 2 plans 02-02..02-07 MUST use lowercase folder paths (`classes/{adapter,helper,meta,queue,exception,testing}/`, `tests/{doubles,unit,feature,contract}/…`) for October Rain ClassLoader autoload — locked by 02-01 deviation 1. Namespaces stay PascalCase. Plan markdown files that show `classes/Adapter/`, `tests/Doubles/`, etc., should be treated as folder-name typos and shipped lowercase.
- `.planning/todos/pending/2026-05-27-enable-optional-queue-for-capi-server-events.md` — post-v2.0.0 Settings toggle (`use_queue` + `queue_name`) so CAPI dispatch defers to Laravel queue instead of running sync inside page-load. Reference: Lovata.Shopaholic XML import `import_queue_on` / `import_queue_name` pattern. Reason: sync Guzzle POST adds 50-200ms (worst case 5s timeout) to every page-load and the four trigger points (PixelHead, EventPixel, CartPositionWatcher, OrderStatusWatcher) cascade into measurable conversion-rate regression.

### Blockers/Concerns

(none — Plan 01-03 shipped cleanly; standalone-repo composer install limitation persists from 01-01/01-02 — smoke tests executed via host vendor binaries, documented in 01-03-SUMMARY.md "Smoke-Test Path Deviations". Full qa chain integration smoke (including composer-dependency-analyser) deferred to CI matrix.)

## Session Continuity

Last session: 2026-05-21T07:58:39.820Z

Stopped at: Phase 5 context gathered

Resume file: .planning/phases/05-documentation-marketplace-launch/05-CONTEXT.md

## Quick Tasks Completed

| Quick Task ID | Date | Title | Commit | Files | Notes |
|---------------|------|-------|--------|-------|-------|
| 260518-999 | 2026-05-18 | Fix $jsonable on FailedEvent.payload + document model property convention | `93dd90b` | 3 modified (models/FailedEvent.php, tests/Feature/Models/FailedEventModelTest.php, CLAUDE.md) | october/boost compliance — swap Eloquent 'array' cast for October $jsonable on longText column; CLAUDE.md "Code style" gains "### Model property convention" subsection documenting Laravel-standard October property names override of Hungarian, $jsonable vs 'array' cast preference, and Validation-trait omission rationale for internal log models. composer qa green (host-vendor smoke chain — 111 tests / 333 assertions / 99.3% coverage). One atomic commit on master per plan Task 3. |
| 260520 | 2026-05-20 | Fix CI YAML --exclude-group=adapter + allowlist Plugin.php in composer-dependency-analyser | `1b82f4d` + `db676b4` | 2 modified (.github/workflows/metapixel-qa.yml, composer-dependency-analyser.php) | CI/QA regression fix split per SRP. `1b82f4d` — Run B now invokes `--exclude-group=adapter` (Phase 3 #[Group('adapter')] migration) instead of the silent no-op `--exclude-testsuite='Metapixel Adapter Tests'`. `db676b4` — composer-dependency-analyser.php gains explicit per-package `ignoreErrorsOnPackageAndPath` call covering `Plugin.php` for the AdapterRegistry's Lovata\OrdersShopaholic\Models\{CartPosition,Order} imports. Validation: CiWorkflowMatrixTest 5/5 + ComposerDependencyAnalyserScopeTest 8/8 green. Functional smoke: --exclude-group=adapter drops 186/427 tests. Two atomic commits on master. |

## Performance Metrics

| Phase | Plan | Duration | Tasks | Files | Date |
|-------|------|----------|-------|-------|------|
| 1 | 01-01 | ~12 min | 6 (4 active, 2 deferred) | 5 created, 71 deleted | 2026-05-16 |
| 1 | 01-02 | ~14 min | 9 (7 active, 1 skipped, 1 smoke-only) | 5 created, 2 modified | 2026-05-16 |
| 1 | 01-03 | ~18 min | 8 (7 active, 1 smoke-only) | 6 created, 0 modified | 2026-05-16 |
| 2 | 02-01 | ~12 min | 6 tasks (all active) | 14 created, 4 modified | 2026-05-17 |
| 2 | 02-02 | ~4 min | 5 tasks (all active; T1 spike + T5 QA-gate non-committing) | 1 created, 3 modified | 2026-05-17 |
| 2 | 02-03a | ~7 min | 5 tasks (4 active + 1 H-5 rename fix; T5 QA-gate non-committing) | 9 created, 2 modified | 2026-05-17 |
| 2 | 02-03b | ~9 min | 5 tasks (4 feat/test + 1 QA-gate fix) | 12 created, 4 modified | 2026-05-17 |
| 2 | 02-04 | ~6 min | 5 tasks (2 feat + 2 test + 1 QA-gate fix) | 4 created, 0 modified | 2026-05-17 |
| 2 | 02-05 | ~11 min | 7 tasks (1 composer + 3 RED + 3 GREEN + 1 SpyMetaClient + 1 QA-gate fix; 9 commits total) | 7 created, 1 modified | 2026-05-17 |
| 2 | 02-06 | ~11 min | 4 tasks (1 feat + 1 Rule-1 MetaClient final-drop fix + 2 test + 1 QA-gate fix; 5 commits total) | 12 created, 1 modified | 2026-05-17 |
| 2 | 02-07 | ~8 min | 5 tasks (Task 1 dropped per R2 YAGNI; 4 active across 1 contract base + 3 test files + 1 verification scaffold + phpstan/phpunit exclude; 1 atomic commit for Phase 2 close) | 5 created, 2 modified | 2026-05-17 |
| Phase 03 P10 | 5min | 4 tasks | 3 files |
