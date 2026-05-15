---
gsd_state_version: 1.0
milestone: v1.1.1
milestone_name: shopaholic-coupled-metapixel-archived
status: milestone-closed
stopped_at: "Milestone v1.1.1 closed 2026-05-15. Architecture pivot to v2.0 generic-event-tracking plugin. v1.1.1 codebase frozen on legacy/v1.1.1 git branch (SHA 3f32ca6); annotated tag v1.1.1 local-only. Next: /gsd-new-milestone for v2.0 (namespace Logingrupa\\Metapixel, plugin dir logingrupa/metapixel, adapter pattern, PHP 8.3+8.4)."
last_updated: "2026-05-15T00:00:00.000Z"
last_activity: 2026-05-15
progress:
  total_phases: 8
  completed_phases: 5
  dropped_phases: 2
  superseded_phases: 1
  total_plans: 21
  completed_plans: 16
  percent: 76
---

# Project State

## Active Milestone

> _No active milestone. Run `/gsd-new-milestone` to start v2.0.0._

## Last Shipped Milestone: v1.1.1

**Status:** Closed 2026-05-15. Partial close — Phase 4 + 5 dropped on architecture pivot.

See [`milestones/v1.1.1-ROADMAP.md`](milestones/v1.1.1-ROADMAP.md) for the full archived snapshot:
- 5 phases complete (1, 2, 3.1, 3.1-07, 3.1-08)
- 1 phase superseded (3 — column-fence replaced by 3.1 UNIQUE race-fence)
- 2 phases DROPPED (4 Funnel + 5 Hardening — re-derived in v2.0)
- 28/50 v1 requirements validated; 22 dropped; 2 staging-deferred to operator
- 16/21 plans (76%)
- 207 commits, 11,027 PHP lines
- 177 tests / 0 failed (82.8% coverage)
- composer qa green end-to-end
- Tag `v1.1.1` annotated local-only at SHA `3f32ca6`
- Legacy branch `legacy/v1.1.1` preserves full v1.x codebase + tests + `.planning/`

## Pivot to v2.0

**Decision (2026-05-15):** Decouple plugin from Shopaholic. Marketplace-grade plugin for any OctoberCMS operator regardless of cart-plugin. Adapter pattern.

**Carry-forward locked decisions** (do not re-derive in v2.0):
- CR-02 TrustedHosts allowlist (operator-supplied via Settings + `jeremykendall/php-domain-parser`)
- CR-03 fbclid `[A-Za-z0-9_-]` ≤255 chars
- event_id server-direction dedup contract
- EventLog UNIQUE race-fence on `(subject_type, subject_id, event_name, channel, site_id)`
- PluginGuard empty-pixel-id → disabled + warn (never throw at boot)
- Tooling thresholds: phpstan lvl 10 + larastan + universalObjectCrates + phpmd Toolbox + Pest 4 + pint

**v2.0 changes:**
- Namespace rename: `Logingrupa\Metapixel`
- Plugin dir rename: `plugins/logingrupa/metapixel/`
- Composer package: drop "shopaholic" from name
- Architecture: `EventSubjectAdapter` + `ValueResolver` interfaces; per-cart adapters (Shopaholic port, MelonCart, Mall, ThemeAction)
- Composer `lovata/shopaholic-plugin` → `suggest:` not `require:`
- PHP support: `"php": "^8.3 || ^8.4"` (v1.x was 8.4-only)

## Next Action

Run `/gsd-new-milestone` to define v2.0 requirements + roadmap.
