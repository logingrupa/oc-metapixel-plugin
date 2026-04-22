---
slug: metapixel-plan-refactor
date: 2026-04-22
mode: quick --full --research
---

# Refactor new-plugin+refactor.md → v3 aligned with codebase

Audit plan at `.planning/new-plugin+refactor.md` (written by AI without access to theme + Lovata plugins) and produce v3 corrected against:
- `themes/logingrupa-naisstore/` conventions (Larajax `jax.ajax('Comp::onX')`, fbq already live)
- `plugins/lovata/toolbox/` (Hungarian, CCache, PHPMD, Item/Collection/Store)
- `plugins/lovata/shopaholic/`, `ordersshopaholic/`, `buddies/`, `wishlistshopaholic/` (actual event names)
- `plugins/logingrupa/storeextender/` + `campaignpricingshopaholic/` (existing conventions)

## Approach
- 7 parallel Explore agents, one audit doc per scope
- Synthesize into v3 plan file at `.planning/new-plugin+refactor.v3.md`
- Keep Lovata Hungarian notation (`ob/s/i/f/b/a`), tiger-style fail-hard via throw (not assert)
- Output: revised plan document only — no code written yet

## Deliverables
- 7 audit files under `.planning/quick/20260422-metapixel-plan-refactor/audit-0{1..7}.md`
- `.planning/new-plugin+refactor.v3.md` — corrected plan, ready for S0 implementation
