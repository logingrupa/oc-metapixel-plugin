# Phase 2: Adapter system core — contracts + registry + extension hooks — Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-17
**Phase:** 02-adapter-system-core-contracts-registry-extension-hooks
**Areas discussed:** Backbone source: port vs fresh (covered code source, planning-doc cleanup scope, EventLog + FailedEvent schema, FakeAdapter shape, contract test base)

---

## Backbone source: port vs fresh

### Q1 — Default source for v1.x backbone files

| Option | Description | Selected |
|--------|-------------|----------|
| Cherry-pick from legacy + renamespace | git checkout legacy/v1.1.1 -- classes/helper/EventLogWriter.php (etc), bulk sed rename, drop declare(strict_types=1), then audit. Preserves 82.8% coverage baseline. Carries v1.x design quirks. | |
| Fresh write against spec | Open ARCHITECTURE.md + REQUIREMENTS.md + v1.x as reference, write each class from scratch with current style. Matches 'no port' rule. ~2-3× slower, may miss subtle v1.x fixes. | |
| Hybrid — port models/migrations/exceptions, rewrite logic classes | Cherry-pick schemas + exceptions, rewrite logic-heavy classes fresh. Pragmatic middle. | |

**User's choice:** Free-text — "Faak, we do all fresh! no old 1.x logic!!! it was bed code, ower engeneered and ugly code, please also update all files in .planning, all refrences of v1.x".

**Notes:** Strong rejection of v1.x as bad/over-engineered/ugly. All-fresh applies to every backbone class — no cherry-pick of any kind, including models/migrations/exceptions. User also requested cleanup of v1.x references in `.planning/` docs — tracked as a deferred follow-up task.

---

### Q2 — Scope of `.planning/` doc cleanup re v1.x references

| Option | Description | Selected |
|--------|-------------|----------|
| Strip all v1.x references | Remove every mention of v1.x / v1.1.1 / legacy/ branch / KEEP verbatim / v1.x anchor. Pitfall anchors lose 'v1.x anchor: REVIEW CR-XX' framing. | ✓ |
| Strip 'KEEP/port from v1.x' framing only, preserve historical anchors | Rewrite directives to fresh-build framing. Keep pitfall anchors as historical evidence. | |
| Scope to ROADMAP + REQUIREMENTS only, leave research/ alone | Active operating docs cleaned, research/ stays as frozen artifacts. | |
| Defer the cleanup — just lock the directive in CONTEXT.md | Note the fresh-code directive only. Skip the doc rewrite. | |

**User's choice:** Strip all v1.x references.

**Notes:** Net of this directive: planning docs read as a forward-spec, no historical port-framing. Pitfall anchors lose `v1.x anchor: REVIEW CR-XX` references — become "observed in prior work" generic language. Executed as a separate task AFTER 02-CONTEXT.md commits.

---

### Q3 — EventLog + FailedEvent table separation

| Option | Description | Selected |
|--------|-------------|----------|
| Yes, two tables, same shape, fresh migrations | EventLog UNIQUE(subject_type, subject_id, event_name, channel, site_id) for race-fence + CAPI↔Pixel pairing. FailedEvent separate for dead-letter audit. Migrations written from scratch (October 4 syntax). | ✓ |
| Two tables, drop site_id from UNIQUE | Skip multi-site in Phase 2 schema. Phase 4 (Multisite) re-adds via second migration. Smaller Phase 2, more churn later. | |
| Collapse — one table with success/failed flag | Single 'events' table, column 'status' = success/failed. Simpler. Costs: bloated table, separate indexes for race-fence vs failed-query, admin UI harder. | |

**User's choice:** Yes, two tables, same shape, fresh migrations — locked after Claude explained: EventLog = success log + race-fence (millions of rows under load, UNIQUE prevents double-fire, CAPI↔Pixel pairing). FailedEvent = dead-letter queue (handful per month, admin Replay/CheckDedup UI in Phase 4). Different access patterns → two tables.

**Notes:** Initial response was "Question is simple why we need 2 tables, why need why dont need, answer me please so I can decide pros and conds and practical purpouse!" — user pushed back on abstract framing, wanted plain practical justification before deciding.

---

### Q4 — FakeAdapter test-double shape

| Option | Description | Selected |
|--------|-------------|----------|
| Single class with fluent setters | tests/Doubles/FakeAdapter.php. One file. Tests chain (new FakeAdapter)->withSiteId(2)->withSubjectType('test.subject'). Readable test code, easy to extend when interface grows. ~80 LOC one-time write. | ✓ |
| Inline anonymous class per test | Each test inlines new class implements EventSubjectAdapter. Zero shared state. ~25 LOC of boilerplate per test × 50-100 tests = boilerplate explosion. | |
| Factory fn + Pest dataset | makeFakeAdapter(['site_id' => 2]) factory. Pest datasets for matrix tests. Concise for repeated tests, indirect for one-off tests. | |

**User's choice:** Single class with fluent setters.

**Notes:** Initial pushback — "I need some practical examples what each of 3 options practically mean because i do not understand what you are asking!". Claude re-presented with concrete code examples for each option + explained WHY FakeAdapter at all (Phase 2 ships zero production adapters by design — backbone needs a test stand-in without dragging in Lovata). User then asked "Why not Real adapters tested? Also so other can write tests for custom adapters?" — answered via D-08 (FakeAdapter purpose) + D-11 (contract test base for third-party adapter authors).

---

### Q5 — Ship contract test base for third-party adapters?

| Option | Description | Selected |
|--------|-------------|----------|
| Yes — ship EventSubjectAdapterContractTestCase in src/ | Abstract test base with ~10 invariants every adapter must satisfy. Third parties extend for their adapter. P-01 prevention by construction, public quality bar. src/ depends on a test framework abstraction, mitigated by putting it under classes/Testing/ namespace, gated behind require-dev. | ✓ |
| No — document FakeAdapter pattern in docs/CUSTOM-ADAPTERS.md only | No shared contract base. Third parties read docs and write own tests. Zero coupling between src/ and test framework, but every adapter rolls their own invariants. | |
| Defer to Phase 5 docs work | Phase 2 ships FakeAdapter only. Phase 5 decides whether contract base or doc-only. Risk: Phase 3 ShopaholicAdapter tests can't use the contract base if it's deferred. | |

**User's choice:** Yes — ship EventSubjectAdapterContractTestCase in src/.

**Notes:** Investment in third-party quality. First-party adapters (ShopaholicOrderAdapter, ThemeActionAdapter in Phase 3) extend the same base — uniform invariants across first-party and third-party adapters.

---

## Claude's Discretion

- Exception hierarchy shape (one base + per-error subclasses, final by default)
- Hungarian notation enforcement (already locked project-wide, applies to every new file)
- Short Laravel docblock style (one-line summary + @param + @return)
- Test directory layout (`tests/Unit/`, `tests/Feature/`, `tests/Doubles/`, `tests/Contract/`)
- No `assert()`, no project-wide `declare(strict_types=1)` (already locked Phase 1)
- Plugin-doc-cleanup task executes as a separate commit AFTER this CONTEXT.md commits (not part of Phase 2 plan)

## Deferred Ideas

- **Strip ALL v1.x references from `.planning/` docs** — tracked outside Phase 2 plan scope. Touches REQUIREMENTS.md, ROADMAP.md, PROJECT.md, STATE.md, research/*.md.
- **Hook contract halt-able semantics (OQ-2)** — researcher resolves; planner re-presents with Lovata precedent.
- **PayloadBuilder event-specific assembly (OQ-3)** — researcher weighs centralized switch vs adapter-supplied extras.
- **Five additional Event::fire hooks** (`adapter.resolve`, `value.resolve`, `user_data.resolve`, `pixel.before_render`, `settings.lookup`) — DEFERRED to v2.1 until a real third-party use case surfaces.
- **Multisite trait on Settings** — Phase 4.
- **FailedEvents admin UI + Replay + CheckDedup** — Phase 4.
- **ShopaholicOrderAdapter + OrderStatusWatcher** — Phase 3.
- **ThemeActionAdapter + Twig API + Larajax handler** — Phase 3.
- **EnsureFbpFbcCookies generalization + `trusted_hosts` + `jeremykendall/php-domain-parser`** — Phase 4.

---

## Areas not discussed (initial gray-area list, deferred to researcher / planner)

- **177-test suite migration** — see CONTEXT.md OQ-1.
- **Hook contract: halt-able vs not** — see CONTEXT.md OQ-2.
- **PayloadBuilder event-specific logic** — see CONTEXT.md OQ-3.

User chose to lock CONTEXT.md after the Backbone area completed rather than continue. These three areas surface again in the planning loop with researcher backing.
