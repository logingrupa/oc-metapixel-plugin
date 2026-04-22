# Logingrupa.MetapixelShopaholic — Planning Index

Meta Pixel + Conversions API for Lovata Shopaholic on OctoberCMS 4.x.

Milestone v1.0.0 bootstrapped 2026-04-22 via `/gsd-new-milestone` — GSD workspace lives inside this plugin repo.

## Contract

**De-duplication happens on Meta's side.** Our only job: same `event_id` on both channels (browser `fbq()` + server CAPI). Server generates UUID, frontend consumes. See "THE ONE RULE" banner in `PLAN.md`.

## GSD artifacts (active)

| File | Purpose |
|---|---|
| `PROJECT.md` | What-is, core value, active requirements, out-of-scope, constraints, key decisions. |
| `REQUIREMENTS.md` | 45 REQ-IDs across TOOL/SKEL/PAY/FUN/HARD, traceability to phases. |
| `ROADMAP.md` | 5 phases (Tooling → Skeleton → Purchase → Funnel → Hardening) with success criteria. |
| `STATE.md` | Current milestone + phase position, accumulated decisions, session continuity. |
| `config.json` | GSD workflow toggles (research off, plan_check on, verifier on, nyquist on, ui_phase off). |
| `phases/` | Per-phase plans (populated by `/gsd-plan-phase N`). |

## Reference docs (kept for audit trail)

| File | Purpose |
|---|---|
| `PLAN.md` | **v3 codebase-aligned plan** — all 5 open questions resolved. Primary source for REQUIREMENTS.md. |
| `PLAN-v2-original.md` | Original plan from prior AI agent. Superseded by v3. |
| `SUMMARY.md` | What changed v2→v3, resolved answers. |
| `QUICK-TASK-PLAN.md` | Meta-plan: how the v2→v3 refactor was carried out. |
| `audits/01-event-flow.md` | §1-3 event_id flow + catalogue + user_data. |
| `audits/02-event-hooks.md` | §4 Shopaholic event hooks — which exist, which don't. |
| `audits/03-larajax.md` | §5 Larajax real/installed; routing via component handlers. |
| `audits/04-architecture.md` | §6 plugin folder layout vs Lovata.Toolbox conventions. |
| `audits/05-settings-health.md` | §7-8 Settings model + FailedEvents backend page patterns. |
| `audits/06-composer-naming.md` | §13-14 composer, PHPStan, PHPMD, Pest, Hungarian notation. |
| `audits/07-tiger-tests-qa.md` | §15-17 TigerStyle, Pest+October test base, CI pipeline. |
| `answers/paid-status.md` | DB-verified: Paid status = `new-payment-received` (ID=5). |
| `answers/content-id-and-forms.md` | Verified: `content_ids` = `SKU-{product_id}[-{offer_id}]`; Lead form = `/saloniem/pieteikt-salonu`; no consent banner. |

## Phases (from ROADMAP.md)

| Phase | Days | Outcome |
|---|---|---|
| **1. Tooling** | 1 | composer.json, phpstan.neon, phpmd.xml, pint.json, rector.php, Pest scaffold, CI. `composer qa` green on empty plugin. |
| **2. Skeleton + cookie fix** | 3 | Plugin.php, Settings(CommonSettings), `EnsureFbpFbcCookies` middleware. Fixes empty `_fbp`/`_fbc` live bug. |
| **3. Purchase end-to-end** | 5 | MetaClient, SendCapiEvent queue job, OrderStatusWatcher, idempotent `meta_purchase_event_id`. |
| **4. Funnel completion** | 5 | ViewContent, AddToCart, InitiateCheckout, AddPaymentInfo, ViewCategory, Search, Lead (salon form), CompleteRegistration. |
| **5. Hardening + launch** | 3 | FailedEvents list + onReplay, lang/{en,lv,ru}, README, marketplace listing. |

## Next GSD command

```
/gsd-plan-phase 1
```

Generates `phases/01-tooling/01-01-PLAN.md` with task breakdown. Then:

```
/gsd-execute-phase 1
```
