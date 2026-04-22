# Logingrupa.MetapixelShopaholic — Planning Index

Meta Pixel + Conversions API for Lovata Shopaholic on OctoberCMS 4.x.

Plugin code not yet generated — S0 (Tooling) is the first sprint in [`PLAN.md`](./PLAN.md).

## Contract

**De-duplication happens on Meta's side.** Our only job: same `event_id` on both channels (browser `fbq()` + server CAPI). Server generates UUID, frontend consumes. See "THE ONE RULE" banner in `PLAN.md`.

## Files

| File | Purpose |
|---|---|
| `PLAN.md` | **Active plan (v3)** — codebase-aligned, all 5 open questions resolved. Start here. |
| `PLAN-v2-original.md` | Original plan from prior AI agent. Kept for audit trail — superseded by v3. |
| `SUMMARY.md` | Quick-task summary: what changed v2→v3, resolved answers, next step. |
| `QUICK-TASK-PLAN.md` | Meta-plan: how the v2→v3 refactor was carried out. |
| `audits/01-event-flow.md`      | §1-3 event_id flow + catalogue + user_data — audit vs theme + Shopaholic. |
| `audits/02-event-hooks.md`     | §4 Shopaholic event hook names — which exist, which don't. |
| `audits/03-larajax.md`         | §5 Larajax real/installed; routing via component handlers. |
| `audits/04-architecture.md`    | §6 plugin folder layout vs Lovata.Toolbox conventions. |
| `audits/05-settings-health.md` | §7-8 Settings model + FailedEvents backend page patterns. |
| `audits/06-composer-naming.md` | §13-14 composer, PHPStan, PHPMD, Pest, Hungarian notation. |
| `audits/07-tiger-tests-qa.md`  | §15-17 TigerStyle, Pest+October test base, CI pipeline. |
| `answers/paid-status.md`       | DB-verified: Paid status = `new-payment-received` (ID=5). |
| `answers/content-id-and-forms.md` | Verified: `content_ids` = `SKU-{product_id}[-{offer_id}]`; Lead form = `/saloniem/pieteikt-salonu`; no consent banner. |

## Sprints (from PLAN.md §9)

| Sprint | Days | Outcome |
|---|---|---|
| **S0 — Tooling** | 1 | composer.json, phpstan.neon, phpmd.xml, pint.json, rector.php, Pest scaffold, CI. `composer qa` green on empty plugin. |
| **S1 — Skeleton + cookie fix** | 3 | Plugin.php, Settings(CommonSettings), `EnsureFbpFbcCookies` middleware. Fixes empty `_fbp`/`_fbc` live bug. |
| **S2 — Purchase end-to-end** | 5 | MetaClient, SendCapiEvent queue job, OrderStatusWatcher, idempotent `meta_purchase_event_id`. |
| **S3 — Funnel completion** | 5 | ViewContent, AddToCart, InitiateCheckout, AddPaymentInfo, ViewCategory, Search, Lead (salon form), CompleteRegistration. |
| **S4 — Hardening + launch** | 3 | FailedEvents list + onReplay, lang/{en,lv,ru}, README, marketplace listing. |

## Next GSD command

```
/gsd-new-milestone @plugins/logingrupa/metapixelshopaholic/.planning/PLAN.md
```

Seeds a fresh milestone in the main `.planning/` (or scoped GSD workspace) from v3 plan. Then per-sprint:

```
/gsd-plan-phase
/gsd-execute-phase
```
