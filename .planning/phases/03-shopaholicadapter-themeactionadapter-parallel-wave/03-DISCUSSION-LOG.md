# Phase 3: ShopaholicAdapter + ThemeActionAdapter parallel wave - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in 03-CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-18
**Phase:** 03-shopaholicadapter-themeactionadapter-parallel-wave
**Areas discussed:** ShopaholicAdapter event scope, EventPixel custom_data source, ThemeAjaxHandler allowlist source, ThemeAdapter site_id source + Plan ordering

---

## ShopaholicAdapter event scope

| Option | Description | Selected |
|--------|-------------|----------|
| Purchase only (Recommended) | Match v1.x baseline + SHOP-05 single-flow. Smallest blast radius; SRP-clean. | |
| Purchase + AddToCart | Add CartPosition::created subscriber; two events per adapter. | ✓ |
| Purchase + AddToCart + ViewContent + Lead | Full Meta funnel server-side; four watchers; DRY-broken with ThemeAdapter. | |

**User's choice:** Purchase + AddToCart — but challenged the cons as possibly structural ("maybe wrong structure is chosen, that's why so many cons?"). Required clarification on Shopaholic Popularity DB + theme-level PageView.

**Notes:** User noted (a) Shopaholic has internal ViewContent-equivalent in `lovata_popularity_shopaholic_products` (October Popularity plugin) — unrelated to Meta event log; (b) PageView is theme-layout responsibility, not adapter responsibility. Follow-up question split Purchase + AddToCart across TWO adapter classes (SRP at file level).

---

## ShopaholicAdapter split shape (follow-up)

| Option | Description | Selected |
|--------|-------------|----------|
| Two adapters in shopaholic/ dir + ThemeAdapter owns PageView/ViewContent (Recommended) | SRP per-subject; <70 LOC each; polymorphic EventLog dedup. | ✓ |
| One mega-adapter + per-event branching | Anti-pattern; type-switching inside getSubjectType/getValueResolver. | |

**User's choice:** Two adapters in shopaholic/ dir (Recommended).

**Notes:** Locks D-02..D-05 in CONTEXT.md.

---

## EventPixel custom_data source

| Option | Description | Selected |
|--------|-------------|----------|
| Adapter re-resolves at render time (Recommended) | EventLog schema unchanged; ValueResolver re-runs at thank-you page. | |
| Add payload column to EventLog | Frozen audit; pure DB read; needs migration + TTL purge. | ✓ |
| Minimal scalar columns (value + currency only) | Hybrid storage; schema balloons per event type. | |

**User's choice:** Add payload column (option 2) with 7-day TTL purge. Asked for deeper insight before locking.

**Notes:** Provided deeper analysis — third-party-author burden (re-resolve forces ValueResolver to be idempotent + cheap + mutation-robust), audit-trail benefit re-framed, EventLog as "dispatched-event ledger" mental model resolves SRP concern. Locked via follow-up question.

---

## EventLog payload column + 7-day TTL purge confirmation (follow-up)

| Option | Description | Selected |
|--------|-------------|----------|
| Lock option 2: payload longText + Schedule daily purge >7d (Recommended) | New migration + EventLogWriter sig change + `metapixel:purge-event-log` console command. | ✓ |
| Lock option 1: stay current schema + adapter re-resolve | No schema change; render-time re-resolve. | |

**User's choice:** Lock option 2 (Recommended).

**Notes:** Locks D-06..D-10 in CONTEXT.md.

---

## ThemeAjaxHandler allowlist source

| Option | Description | Selected |
|--------|-------------|----------|
| Hardcoded Meta-standard + operator-extendable via Settings, regex-validated | Hybrid; operator-visible regex for custom names. | |
| Hardcoded Meta-standard only, no extension | 18-name const; restrictive; anti-marketplace. | |
| Operator-supplied allowlist only | No defaults; anti-DX on fresh install. | |

**User's choice:** None of these. Rejected regex framing. Wanted: Meta defaults shipped + two extension paths (non-dev operator via backend Settings; savvy dev via plugin extension, no fork).

**Notes:** Reframed — sanitization is invisible to operator (save-time char-check on textarea input), not regex-typed-by-operator. Follow-up question locked the corrected shape.

---

## ThemeAjaxHandler allowlist shape (follow-up)

| Option | Description | Selected |
|--------|-------------|----------|
| Meta defaults + backend textarea + dev-savvy via own adapter (Recommended) | Two paths, no fork. META_STANDARD const + Settings textarea sanitized at save; savvy dev bypasses via AdapterRegistry::register. | ✓ |
| Differ — different shape | Redirect option. | |

**User's choice:** Lock this shape (Recommended).

**Notes:** Locks D-11..D-14 in CONTEXT.md.

---

## ThemeAdapter site_id source

| Option | Description | Selected |
|--------|-------------|----------|
| Fallback to Site::getCurrent()->getId() when payload[site_id] missing (Recommended, documented P-01 exception) | Twig DX-clean; ThemeAdapter is the ONE documented exception to P-01 ban. | ✓ |
| Strict P-01: operator MUST pass site_id in payload | Twig boilerplate at every call site; anti-DX. | |

**User's choice:** Fallback to Site::getCurrent()->getId() (Recommended).

**Notes:** Locks D-15..D-16 in CONTEXT.md. PHPStan deny-list config splits by sub-directory: classes/adapter/theme/ EXCLUDED; classes/adapter/shopaholic/ retains the ban.

---

## Plan-execution ordering

| Option | Description | Selected |
|--------|-------------|----------|
| Sequential: Shopaholic first, Theme second (Recommended) | 8 plans 03-01..03-08 linear; dogfood Shopaholic on nailscosmetics.* before Theme work. | ✓ |
| Parallel wave: Shopaholic + Theme interleaved | Cross-cutting churn on EventLog.payload migration touching both halves. | |

**User's choice:** Sequential (Recommended).

**Notes:** Locks D-17..D-18 in CONTEXT.md. 8-plan outline scaffolded; planner refines exact task counts.

---

## Claude's Discretion

Captured in CONTEXT.md `<decisions> ### Claude's Discretion` section. Summary:

- **OrderStatusWatcher trigger code:** `paid_status_code` sourced from `Status::lists()` dropdown; single-value; default `new-payment-received`.
- **CartPositionWatcher trigger semantics:** dispatch on `eloquent.created`; on `eloquent.updated`, dedup against EventLog row.
- **ThemeEventCollector flush boundary:** explicit `flush()` called by PixelHead emit + tests' tearDown — no magic terminating-event flush.
- **PixelHead-EventPixel coexistence:** PixelHead emits collector events on render; EventPixel handles server-confirmed-elsewhere path (CAPI fired from queue worker → customer hits thank-you page later). Separate components, no overlap.
- **Test directory layout:** `tests/Feature/Adapter/Shopaholic/` + `tests/Feature/Adapter/Theme/` + `tests/Contract/Adapter/Shopaholic/` + `tests/Contract/Adapter/Theme/`.
- **`Plugin::registerComponents()`:** registers `eventPixel` + `pixelHead` components.

## Deferred Ideas

Captured in CONTEXT.md `<deferred>` section. Highlights:

- FailedEvents admin UI + Replay + CheckDedup — Phase 4 (FAIL-01..03).
- Multisite trait on Settings::pixel_id + capi_access_token — Phase 4 (MULT-01..06).
- TrustedHosts + php-domain-parser — Phase 4 (HOST-01..06).
- Translations — Phase 4 (LANG-01).
- Server-side Lead adapter — v2.1.
- MallAdapter + MeloncartAdapter — v2.1.
- 5 additional Event::fire hooks — v2.1.
- Debug/Test-Events backend panel — v2.1.
- ThemeAjaxHandler rate-limit configurability — out of Phase 3; revisit Phase 4 if demand surfaces.

## User Feedback Saved to Memory

- `feedback_gray_area_option_format.md` — every gray-area option must include practical example + pros/cons + DRY+SRP industry-standard pick. Drove the format of all 4 area presentations after Area 1 selection.
