---
slug: metapixel-plan-refactor
date: 2026-04-22
status: complete
---

# Summary — Metapixel plan refactor v2 → v3

## What done
- Spawned 7 parallel Explore audits covering all 19 `##` sections of `.planning/new-plugin+refactor.md`
- Each audit written to `audit-0{1..7}.md` with file:line citations
- Synthesized into `.planning/new-plugin+refactor.v3.md`

## Key corrections from v2 → v3
1. **Event hooks wrong** — Only `shopaholic.cart.add` fires natively. Update/remove/favorite/register events DO NOT EXIST. v3 uses `model.afterUpdate` + `eloquent.created` fallbacks.
2. **Larajax routing wrong** — Larajax IS installed but no facade-routes pattern used. v3 uses component handler extension (`Cart::extend` + `addDynamicMethod('onMetaTrack*')`).
3. **Folder names wrong** — Lovata uses `classes/event/`, `classes/queue/`, `classes/helper/` (singular), `middleware/` at plugin root (not under `classes/`).
4. **Settings model wrong** — Must extend `Lovata\Toolbox\Models\CommonSettings`, fields at `models/settings/fields.yaml`.
5. **Paid status hardcoded `paid`** — Lovata base = `complete`. v3 adds dropdown populated from `Status::all()->lists('name','code')`.
6. **assert() unsafe** — prod `zend.assertions=0` = silent no-op. v3 uses explicit `throw` w/ `spaze/phpstan-disallowed-calls` guard.
7. **Pest ^3.0 outdated** — root uses ^4.1. v3 bumps.
8. **PHPStan level 10 needs crates** — v3 adds `universalObjectCratesClasses` for Lovata Item/Collection + `larastan` extension.
9. **PHPMD class-length 250 unrealistic** — Toolbox norm is 1000. v3 copies Toolbox `PHPMD_custom.xml` w/ LongVariable max bumped 25→40.
10. **Orchestra Testbench wrong for OC CMS** — v3 reuses `CampaignPricingTestCase` pattern.
11. **Boot-time fail-hard breaks site** — v3 splits: warn-on-boot + throw-on-first-event + graceful CAPI fallback.
12. **content_ids format resolved** — use `SKU-{product_id}[-{offer_id}]` to match Facebook Catalog feed at `ExportCatalogFacebookHelper.php:356`. No dropdown needed.
13. **event_time missing in frontend fbq calls** — v3 mandates `event_time: Math.floor(Date.now()/1000)` for dedup window.
14. **No anon external_id fallback** — v3: `sha256($obOrder->secret_key)` for guest checkout.
15. **CCache for user_data hashes** — v3 adds request-scoped tagged cache to avoid duplicate sha256.
16. **No project CI** — v3 ships `.github/workflows/metapixel-qa.yml` in S0.
17. **`declare(strict_types=1)`** — zero ecosystem usage. v3 drops enforcement (optional per-file).

## Files produced
- `.planning/new-plugin+refactor.v3.md` (corrected plan)
- `.planning/quick/20260422-metapixel-plan-refactor/audit-0{1..7}.md`

## Open questions — ALL RESOLVED via codebase (see `answer-*.md`)
1. Paid status code = **`new-payment-received`** (ID=5, custom). PayPal/Vipps gateways auto-set; bank transfer needs admin mark-paid. Proof: live DB query + `PaymentMethod.after_status_id`.
2. content_ids = **`SKU-{product_id}[-{offer_id}]`** to match Facebook Catalog feed. Proof: `ExportCatalogFacebookHelper.php:356` + `CartComponentHandler.php:137-149`.
3. Lead form = **salon application at `themes/.../pages/salon/application-form.htm`** (native `onSend`, not FormBuilder). Wire Meta Lead there.
4. No consent banner — fire events unconditionally. Re-visit if stakeholder ships GDPR gate later.
5. Dead-letter sink v1 = log-only + backend `FailedEvents` list with `onReplay`. Defer external alerts (Slack/email) to v1.1.

## Core contract (user-reinforced)
**De-duplication happens on Meta's side, not ours.** Our job: identical `event_id` on Pixel + CAPI channels. Server always generates, frontend always consumes. Documented at top of v3 plan as "THE ONE RULE".

## Next
S0 can start immediately. Run `/gsd-plan-phase` to break v3 into phase work items, or `/gsd-execute-phase` to start tooling directly.
