# Phase 2: Adapter system core — contracts + registry + extension hooks — Context

**Gathered:** 2026-05-17
**Status:** Ready for planning

<domain>
## Phase Boundary

Build a generic event-dispatch backbone for Logingrupa.Metapixel v2.0. Any subject (Shopaholic Order, theme action, third-party cart) flows through the same `MetaClient` + `PayloadBuilder` + `UserDataHasher` + `EventLogWriter` pipeline behind an `EventSubjectAdapter` + `ValueResolver` interface pair resolved at runtime via `AdapterRegistry`. Wire three `Event::fire` extension points at documented decision boundaries. **No production adapter ships in this phase** — backbone is exercised by a `FakeAdapter` test double living in `tests/Doubles/`. ShopaholicOrderAdapter + ThemeActionAdapter land in Phase 3.

Phase 2 owns ADAP-01..11 (11 requirements) and prevents pitfalls P-01 (cross-context resolution drift), P-02 (boot-order race), P-05 (subject_type alias ambiguity), P-08 (mutable hook payload), P-13 (Component::extend unbounded surface).

</domain>

<decisions>
## Implementation Decisions

### Code source: no port, all fresh

- **D-01:** Every backbone class is **written fresh** from the current spec (REQUIREMENTS.md ADAP-01..11 + research/ARCHITECTURE.md §2-§10). No cherry-pick from `legacy/v1.1.1`. No re-namespace + adapt. Reason: prior implementation was over-engineered and ugly; v2.0 starts clean with simple logic, modern October 4 + Laravel 12 + Lovata.Toolbox idioms.
- **D-02:** Applies to: `EventLogWriter`, `EventLog` model, `FailedEvent` model, `PluginGuard`, exception classes, `MetaClient`, `PayloadBuilder`, `UserDataHasher`, `Settings` (Phase 2 shape — Multisite trait lands in Phase 4), `SendCapiEvent`, `SiteResolver`, and the EventLog + FailedEvent migrations.
- **D-03:** v1.x file paths in research/ARCHITECTURE.md ("KEEP verbatim", "MODIFIED from v1.x") are reframed as forward-spec only: they document the SHAPE Phase 2 must produce, not files to copy.

### Database schema: 2 tables, fresh migrations

- **D-04:** Two separate tables, written from scratch in October 4 migration syntax. No `legacy/v1.1.1` migration files reused.
- **D-05:** `logingrupa_metapixel_event_log` — success log + race-fence. Columns: `id`, `subject_type` (string, opaque alias e.g. `'shopaholic.order'` — NOT class FQN per P-05), `subject_id` (bigint), `event_name` (string), `channel` (enum: `capi`, `pixel`), `site_id` (int, nullable), `event_id` (UUID, indexed), `event_time` (int Unix seconds), `created_at`. UNIQUE constraint on `(subject_type, subject_id, event_name, channel, site_id)`.
- **D-06:** `logingrupa_metapixel_failed_events` — dead-letter queue. Columns: `id`, `event_id`, `event_name`, `adapter_type` (string), `subject_type`, `subject_id`, `payload` (json), `http_status` (int, nullable), `graph_error` (text, nullable), `attempts` (int), `created_at`. Phase 2 ships the table + minimal model only; admin UI + Replay/CheckDedup actions land in Phase 4 (FAIL-01..03).
- **D-07:** Rationale for two tables: different access patterns. EventLog grows linearly with successful events (millions of rows over time) and needs the UNIQUE race-fence at write time. FailedEvent is rare (handful per month under healthy operation) and is queried by admins via filters. Mixing them would bloat indexes and complicate admin queries.

### FakeAdapter — test double shape

- **D-08:** `tests/Doubles/FakeAdapter.php` — single `final class FakeAdapter implements EventSubjectAdapter` with fluent setters: `withSubjectType()`, `withSubjectId()`, `withSiteId()`, `withSecretKey()`, `withUserData()`, `withValueResolver()`, `withSupportedEvents()`. Tests instantiate fresh per test (no shared mutable state).
- **D-09:** FakeAdapter never autoloads in production — `tests/Doubles/` is outside the plugin's PSR-4 root, only loaded by the test bootstrap.
- **D-10:** Companion `FakeValueResolver` in same directory — returns operator-provided `contentIds`, `value`, `currency`, `contents`, `numItems` via constructor or setters.

### Contract test base for third-party adapters

- **D-11:** Ship `Logingrupa\Metapixel\Classes\Testing\EventSubjectAdapterContractTestCase` (under `classes/Testing/` namespace, gated behind `require-dev` Pest 4). Abstract test base with ~10 invariants every adapter must satisfy.
- **D-12:** Invariants enforced by the contract base: `getSubjectType()` returns an alias string without backslashes (P-05); `getSiteId()` returns the same value regardless of `Site::setSite()` active context (P-01 prevention by construction); `getUserData()` returns the documented Meta CAPI key set (`em, ph, fn, ln, ct, st, zp, country, external_id, fbp, fbc, client_ip_address, client_user_agent`) with non-string values explicitly null; `getSupportedEvents()` returns `array<string, list<string>>` shape; the adapter is `is_a()`-compatible with the subject class it registers against.
- **D-13:** Third parties extend this base for their custom adapter tests (see Pattern A in DISCUSSION-LOG.md). Same base used internally by Phase 3 (ShopaholicOrderAdapter, ThemeActionAdapter) so first-party and third-party adapters meet the same quality bar.

### Carried forward from project decisions (locked, do not re-derive)

These are not re-decided in Phase 2 — they are constraints on how the decisions above are realized:

- **D-14:** AdapterRegistry is a service-container singleton (`App::singleton(AdapterRegistry::class)`), lazy `App::make` resolution, walks class hierarchy via `is_a()`, returns null on miss (ADAP-03 + research/ARCHITECTURE.md §3).
- **D-15:** Three `Event::fire` hooks: `metapixel.event.before_dispatch`, `metapixel.event.after_dispatch`, `metapixel.event.dead_letter` (ADAP-04). Five additional hooks (adapter.resolve, value.resolve, user_data.resolve, pixel.before_render, settings.lookup) DEFERRED to v2.1.
- **D-16:** Listener exceptions caught + `Log::warning` + continue. Never propagate to core dispatch (ADAP-05).
- **D-17:** `SiteResolver::forSubject(object, EventSubjectAdapter): ?int` is the only authoritative source of `site_id`. PHPStan disallowed-calls bans `SiteManager::*`, `request()`, `Request::*` in `classes/Queue/`, `classes/Event/`, `classes/Adapter/` (ADAP-06, P-01 prevention).
- **D-18:** Graph API pinned to `v23.0` via `MetaClient::META_GRAPH_API_VERSION` constant (ADAP-09). v20 expires 2026-09-24 — no operator override.
- **D-19:** `MetaClient::sendForPixel(string $sPixelId, string $sToken, array $arPayload): array` — per-call credentials, no singleton Settings read inside MetaClient (ADAP-09).
- **D-20:** `SendCapiEvent` constructor signature: `(string $sEventName, array $arPayload, object $obSubject, string $sAdapterClass)`. `handle()` resolves adapter via `AdapterRegistry::resolveByClass()`. `BindingResolutionException` boundary catch writes FailedEvent + `Log::critical` (ADAP-10).
- **D-21:** `PayloadBuilder::buildEventPayload(string $sEventName, EventSubjectAdapter $obAdapter, object $obSubject, ValueResolver $obResolver, string $sEventId, int $iEventTime, array $arEventExtras): array` — subject-agnostic, no Order-typed signatures (ADAP-07). Event-specific assembly decision deferred (see Open Questions).
- **D-22:** `UserDataHasher::forSubject(EventSubjectAdapter $obAdapter, object $obSubject): array` — adapter provides raw fields, hasher does sha256 + per-request CCache (ADAP-08).

### Open Questions (for researcher / planner)

The user deferred deep-dive on 3 areas. Researcher should surface tradeoffs with code evidence; planner re-presents with research backing:

- **OQ-1: 177-test suite migration.** ADAP-11 says "regreen 177 v1.x tests via FakeAdapter". With the all-fresh decision (D-01), are the 177 v1.x tests cherry-picked + adapted, OR rewritten fresh from spec? Fresh-rewrite likely ends at fewer than 177 tests, scoped to Phase 2 backbone only (Phase 3 adds adapter-specific tests on top). Recommend: fresh-rewrite, target ~60-80 backbone-only tests covering ADAP-01..11 success criteria + P-01/P-02/P-05/P-08 invariants. Coverage gate (≥90% on Run A) applies to the fresh test set.
- **OQ-2: Hook contract — halt-able vs not.** ADAP-04 spec says `before_dispatch` listener "return false halts dispatch". P-08 prevention says hooks are NOT cancelable. Direct contradiction. Researcher: check Lovata precedent (`shopaholic.sorting.offer.get.list` — halt-able? observe only?). Recommend resolution: keep halt-able semantics on `before_dispatch` ONLY (matches Lovata pattern, lets third-party suppress events for consent/compliance), document loudly in the hook PHPDoc, exception isolation per D-16 still applies. The other two hooks (`after_dispatch`, `dead_letter`) are observe-only.
- **OQ-3: PayloadBuilder event-specific logic.** Where does Purchase vs ViewContent vs Lead assembly live? Option A — PayloadBuilder switches internally on `$sEventName` (centralized, every adapter gets identical envelope). Option B — adapter exposes `getEventExtras($obSubject, $sEventName): array` and PayloadBuilder merges (decentralized, each adapter owns its event shapes — easier for third-party customization). Researcher: weigh against SRP + the v2.0 "no over-engineering" rule.

### Claude's Discretion

The user did not explicitly direct on these — Claude/planner proceeds with the stated default unless conflict surfaces:

- Exception hierarchy: one base `MetaPixelException` + per-error-class subclasses. Fresh names, no v1.x copy. `final` where the class isn't designed for extension.
- Hungarian notation (`$ob`, `$ar`, `$i`, `$s`, `$f`, `$b`) per project lock — applies to every new file.
- Short Laravel docblocks (one-line summary + `@param` + `@return`) — no multi-paragraph narrative.
- Test directory layout: `tests/Unit/` (pure logic, no DB), `tests/Feature/` (DB + framework boot), `tests/Doubles/` (FakeAdapter, FakeValueResolver, fixture builders), `tests/Contract/` (extends `EventSubjectAdapterContractTestCase` for first-party adapter contract checks once Phase 3 adapters exist).
- No `assert()` — per Phase 1 PHPStan disallowed-calls. No `declare(strict_types=1)` — optional per-file, project-wide off.
- Plugin-doc-cleanup task (`.planning/` v1.x reference strip) executes as a separate commit AFTER this CONTEXT.md commits — not part of the Phase 2 plan execution scope.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase scope + requirements

- `.planning/ROADMAP.md` §"Phase 2: Adapter system core" — success criteria (SC1-SC5), depends-on, requirement mapping.
- `.planning/REQUIREMENTS.md` §"Adapter system core (Phase 2)" — ADAP-01..11 verbatim specs.
- `.planning/PROJECT.md` §"Current Milestone: v2.0.0" — target features, locked v2.0 decisions (event_id direction, EventLog UNIQUE race-fence, PluginGuard pattern, no assert(), Lovata.Toolbox idioms).

### Architecture + design

- `.planning/research/ARCHITECTURE.md` §2 Integration Map — disposition of each backbone class (read SHAPE, ignore "KEEP from v1.x" framing per D-03).
- `.planning/research/ARCHITECTURE.md` §3 AdapterRegistry class shape — locks the singleton + registration + resolution pattern.
- `.planning/research/ARCHITECTURE.md` §5 Interface contracts — full method signatures for `EventSubjectAdapter` + `ValueResolver`.
- `.planning/research/ARCHITECTURE.md` §7 Event::fire extension points — the three Phase 2 hooks + 5 deferred to v2.1.
- `.planning/research/ARCHITECTURE.md` §9 Multi-pixel routing flow — `Settings::lookupForSite($iSiteId)` contract Phase 2 must support (even though Multisite trait lands Phase 4).
- `.planning/research/ARCHITECTURE.md` §13 Third-party adapter sample — reference shape for the contract test base (D-11).

### Pitfall ownership

- `.planning/research/PITFALLS.md` §P-01 Cross-context resolution drift — disallowed-calls config + contract test invariant.
- `.planning/research/PITFALLS.md` §P-02 Boot-order race — lazy resolve + idempotent register + boot-order invariant test.
- `.planning/research/PITFALLS.md` §P-05 EventLog subject_type alias — opaque string convention.
- `.planning/research/PITFALLS.md` §P-08 Mutable hook payload — listener isolation + payload contract.
- `.planning/research/PITFALLS.md` §P-13 Component::extend unbounded — Phase 2 documents convention, prefer `Event::fire` over `addDynamicMethod`.

### Tooling (Phase 1 carries into Phase 2)

- `phpstan.neon` — phpVersion 80300, disallowed-calls config to extend with `SiteManager::*`, `request()`, `Request::*` bans in adapter/queue/event dirs.
- `composer-dependency-analyser.php` — extend so any `Lovata\*` import is restricted to `classes/Adapter/Shopaholic/` (Phase 3 enforcement, Phase 2 just sets up the rule shape).
- `phpunit.xml` — Phase 2 adds `<testsuite name="Metapixel Adapter Tests">` block pointing at `tests/Contract/` (currently a forward-reference, becomes meaningful when Phase 3 ships adapters).

### Forward-reference (read for context, do not implement in Phase 2)

- `.planning/REQUIREMENTS.md` §"Multisite + Settings rework (Phase 4)" — `Settings::lookupForSite()` shape Phase 2 stubs with no Multisite trait yet.
- `.planning/REQUIREMENTS.md` §"FailedEvents backend audit (Phase 4)" — admin UI ships Phase 4; Phase 2 ships only the table + minimal model.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets

- `Lovata\Toolbox\Models\CommonSettings` — `Settings` extends this (ecosystem norm; auto-caches; will host Multisite trait in Phase 4). Phase 2 `Settings` is a thin wrapper with `pixel_id`, `capi_access_token`, `test_event_code` fields. NO Multisite trait yet.
- `Lovata\Toolbox\Classes\Event\ModelHandler` — base for any model-event subscriber. Phase 3 OrderStatusWatcher extends this; Phase 2 does NOT add a watcher (no subject model to watch). FailedEvent model can extend plain `October\Rain\Database\Model` since it's admin-only audit log.
- October's service container — `App::singleton(AdapterRegistry::class, fn() => new AdapterRegistry())` is the precise binding shape used by `PluginGuard::instance()` in v1.x project memory; lift the pattern unchanged.
- Larastan + spaze/phpstan-disallowed-calls (landed Phase 1) — extend the disallowed-calls config to ban `SiteManager::*`, `request()`, `Request::*` inside `classes/Queue/`, `classes/Event/`, `classes/Adapter/`.
- Pest 4 + Pest plugin-drift (landed Phase 1) — drive the new backbone tests; `MetapixelTestCase` is the base (no Lovata).

### Established Patterns

- **Hungarian notation everywhere** — `$obSubject`, `$arPayload`, `$sEventName`, `$iSiteId`, `$bIsActive`. Enforced by phpmd `ShortVariable min=4`.
- **Final classes by default** — adapters, value resolvers, registry, helper, queue jobs all `final` unless contract test base or designed-for-extension.
- **One responsibility per class, ≤70 LOC per method** — split helpers when bigger.
- **`extends PluginBase` + `boot()` + `register()`** — October's plugin lifecycle. `register()` for bindings, `boot()` for event subscriptions + registry registrations.
- **Service-container bindings tested via `$this->app->instance(...)`** — `Plugin::register()` binds `AdapterRegistry::class` as singleton; tests swap in fresh instances per test.

### Integration Points

- `Plugin::register()` — `$this->app->singleton(AdapterRegistry::class)`. Phase 2 only — no adapter registration yet (Phase 3 adds conditional `ShopaholicOrderAdapter` registration in `boot()`).
- `Plugin::boot()` — wires `EnsureFbpFbcCookies` middleware (carry the shape from research; Phase 4 generalizes for `trusted_hosts`). Phase 2 boot is intentionally minimal — just AdapterRegistry binding + (eventually) middleware push.
- Tests bootstrap (`tests/Pest.php`) — `uses(MetapixelTestCase::class)->in('Unit', 'Feature')` already wired Phase 1. Phase 2 adds `uses(ShopaholicAdapterTestCase::class)->in('Contract/Adapter/Shopaholic')` forward-reference (currently empty dir).

</code_context>

<specifics>
## Specific Ideas

- The user rejected v1.x as "bad code, over-engineered, ugly". Fresh code must be **simpler** than the v1.x equivalent — fewer files, shorter methods, clearer names. Reviewers should challenge any new file that doesn't pass a "would this fit in <50 LOC?" smell test for the helper/exception/migration classes.
- Contract test base (D-11) is the user's investment in third-party quality: a marketplace operator authoring `AcmeCartAdapter` extends `EventSubjectAdapterContractTestCase`, runs `pest`, gets fail-fast feedback if their adapter drifts from P-01 invariants.
- "All v1.x references stripped from `.planning/`" is the user's directive — execute as a follow-up commit AFTER 02-CONTEXT.md commits. Out of Phase 2 plan scope, but in the next-action queue.

</specifics>

<deferred>
## Deferred Ideas

- **Strip ALL v1.x references from `.planning/` docs** — separate task tracked outside Phase 2's plan. Touches REQUIREMENTS.md, ROADMAP.md, PROJECT.md, STATE.md, research/ARCHITECTURE.md, research/PITFALLS.md, research/SUMMARY.md, research/FEATURES.md, research/STACK.md. Pitfall anchors lose `v1.x anchor: REVIEW CR-XX` framing — become "observed in prior work". User intent: planning docs read as a fresh forward-spec, no historical port-framing.
- **Hook contract halt-able semantics (OQ-2)** — researcher resolves; planner re-presents with Lovata precedent + recommendation.
- **PayloadBuilder event-specific assembly (OQ-3)** — researcher weighs centralized switch vs adapter-supplied extras.
- **Five additional Event::fire hooks** (`adapter.resolve`, `value.resolve`, `user_data.resolve`, `pixel.before_render`, `settings.lookup`) — DEFERRED to v2.1 until a real third-party use case surfaces. Not Phase 2 scope.
- **Multisite trait on `Settings::pixel_id` + `capi_access_token`** — Phase 4 (MULT-01..06). Phase 2 ships single-row Settings only; `Settings::lookupForSite($iSiteId)` stub returns the default row regardless of `$iSiteId`.
- **FailedEvents admin UI + Replay + CheckDedup** — Phase 4 (FAIL-01..03). Phase 2 ships only the `logingrupa_metapixel_failed_events` table + `FailedEvent` model.
- **ShopaholicOrderAdapter + OrderStatusWatcher** — Phase 3 (SHOP-01..05). Phase 2 contract test base + FakeAdapter must shape the interface so Phase 3 ports cleanly.
- **ThemeActionAdapter + Twig API + Larajax handler** — Phase 3 (THEM-01..07).
- **EnsureFbpFbcCookies generalization + `trusted_hosts` + `jeremykendall/php-domain-parser`** — Phase 4 (HOST-01..06). Phase 2 ships the middleware skeleton if needed for the Plugin::boot wire-up, but the host-allowlist logic is Phase 4.

</deferred>

---

*Phase: 2-adapter-system-core-contracts-registry-extension-hooks*
*Context gathered: 2026-05-17*
