---
slug: pixelhead-no-base-pageview
status: resolved
resolved_on: 2026-05-27
resolved_by: commit 0658788 "feat(pixelhead): restore base-pixel emission lost in Phase 3 re-derive"
verified_by: 2026-05-27 operator cutover UAT — PageView fires browser+server with matching event_id; Pixel Helper shows 1 PageView per page-load across all 5 critical pages (05-04-UAT-GATE-2.md PASS).
trigger: PixelHead v2.0 component did not emit fbevents.js loader, fbq('init', pixel_id), or base fbq('track', 'PageView', {}, {eventID}) on page-load. Pixel Helper reported "No Pixels found on this page" on staging http://your-staging-host.example/lv/p/virsejais-parklajums-builder-top-coat-uvled-15ml even after plan 05-04 wired [pixelHead] component into all 4 theme layouts (theme commit 524189f). Blocked Phase 5 UAT Gate 2.
created: 2026-05-25T00:00:00Z
updated: 2026-05-27T00:00:00Z
---

# Debug session — pixelhead-no-base-pageview

## Symptoms

- **Expected:** PDP at `/lv/p/<slug>` (uses `catalog_default` layout) renders fbevents.js loader script + `fbq('init', '<pixel-id-redacted>')` + `fbq('track', 'PageView', {}, {eventID: <uuid>})` inside `<head>`. Pixel Helper shows 1 PageView. Test Events shows Browser+Server with same event_id. EventLog has 1 channel=capi row per page-load with matching event_id. This is the explicit Phase 5 UAT Gate 2 acceptance criterion (plan 05-04-PLAN.md frontmatter `must_haves.truths`).

- **Actual:** PDP HTML contains ZERO `fbq(`, ZERO `fbevents.js`, ZERO `connect.facebook.net`, ZERO `<noscript>` Pixel tag. Pixel Helper extension reports "No Pixels found on this page". Page renders cleanly (HTTP 200, ~63 KB). Layout file (`catalog_default.htm`) DOES contain `{% component 'pixelHead' %}` per theme commit 524189f — verified post-deploy.

- **Error messages:** No PHP/Twig errors in storage/logs/system.log around request time. No console errors related to pixel (only unrelated `ERR_INTERNET_DISCONNECTED` on the operator's browser session and Google Analytics POST — operator's own connectivity glitch).

- **Timeline:** First noticed during plan 05-04 UAT Gate 2 verification on 2026-05-25 after theme commit 524189f deploy. Plan 05-03 UAT Gate 1 (zero-events verification) passed cleanly on 2026-05-22 — confirms strip worked, no v1.x residue. v2.0 PixelHead has NEVER fired base PageView in production. v1.x PixelHead DID emit base PageView (archive `.planning/archive/v1.1.1/phases/02-skeleton-cookie-fix/02-04-PLAN.md` lines 188-244 documents this).

- **Reproduction:**
  1. Visit https://your-staging-host.example/lv/p/virsejais-parklajums-builder-top-coat-uvled-15ml (or any product page using `catalog_default` layout)
  2. Open Pixel Helper extension → expect 1 PageView → observe "No Pixels found"
  3. View source / DevTools → expect `fbevents.js` script tag → observe none
  4. `curl -s http://your-staging-host.example/lv/p/<slug> | grep -cE "fbq\(|fbevents|connect\.facebook"` returns 0

## Code evidence (initial)

- `plugins/logingrupa/metapixel/components/PixelHead.php:40-71` — `onRun()` only iterates `App::make(ThemeEventCollector::class)->flush()`. No base-pixel emission. No Settings lookup for pixel_id. No fbevents.js loader. No fbq('init'). No fbq('track', 'PageView'). No CAPI dispatch unless an event was theme-pushed via Twig API.

- `plugins/logingrupa/metapixel/components/pixelhead/default.htm` — Template emits NOTHING unless `pixelHeadBlocks` non-empty. On a typical PDP with no theme-side `pushEvent()` calls, output is empty string (just trailing newline).

- `plugins/logingrupa/metapixel/components/EventPixel.php` + `components/eventpixel/default.htm` — Template calls `fbq('track', ...)` directly assuming `fbq` is a global. With no loader anywhere in the plugin, EventPixel will throw `ReferenceError: fbq is not defined` at render time when wired by plan 05-06. Confirms gap is system-wide, not just PixelHead.

- `grep -rln "fbevents.js" plugins/logingrupa/metapixel/` returns 0 matches. Loader genuinely absent from v2.0 codebase.

- `plugins/logingrupa/metapixel/Plugin.php:108-113` registers both `EventPixel` and `PixelHead` aliases but nothing else handles the loader.

## v1.x reference (archived)

- `.planning/archive/v1.1.1/phases/02-skeleton-cookie-fix/02-04-PLAN.md:188-244` — v1.x PixelHead component spec:
  > `PixelHead — renders Meta Pixel fbq('init') + fbq('track', 'PageView') with a server-generated event_id matching the CAPI Purchase twin (Phase 4 FUN-01). Includes the fbevents.js loader snippet (!function(f,b,e,v,n,t,s){...}).`
- v1.x emission stack: (1) loader `!function(...)`, (2) `fbq('init', '<pixel_id>')` PII-free, (3) `fbq('track', 'PageView', {}, {eventID: '<uuid>'})`, (4) `<noscript><img src="https://www.facebook.com/tr?id=<id>&ev=PageView&noscript=1"/>`, (5) matching CAPI PageView SendCapiEvent::dispatch with same event_id.
- v2.0 lost (1) (2) (3) (4) (5) entirely. v2.0 re-derive in Phase 3 (THEM-01..07) re-purposed PixelHead as a generic theme-event accumulator without preserving base-pixel responsibility.

## Phase status

- Phase 3 (cart-plugin discovery + checkout-flow events, THEM-01..07): STATE.md shows "Executed — pending verification". Verification never ran. Gap was not caught.
- Phase 5 plans 05-04 (PixelHead wire) and 05-06 (EventPixel wire) both presume base-pixel emission exists. They cannot pass UAT without it.

## Current Focus

- **hypothesis:** Phase 3 (THEM-01..07) re-derive replaced v1.x PixelHead's base-pixel responsibility with a generic theme-event accumulator (`ThemeEventCollector::flush()`) without preserving the v1.x emission of (a) fbevents.js loader, (b) `fbq('init', pixel_id)`, (c) `fbq('track', 'PageView', {}, {eventID})`, (d) `<noscript>` Pixel tag, and (e) matching CAPI PageView dispatch. The bug is by-design omission, not a code defect — the design itself dropped the base-pixel layer.
- **test:** Read every Phase 3 plan + summary touching PixelHead / THEM-* requirements to confirm whether base-pixel emission was (i) explicitly out-of-scope per a written decision, (ii) silently dropped during fresh re-derive, or (iii) lives somewhere unexpected (separate component, middleware, theme partial). Cross-check against `.planning/REQUIREMENTS.md` THEM-01..07 acceptance criteria.
- **expecting:** Either a Phase 3 design decision document explaining why base-pixel was dropped (unlikely — Phase 5 plans assume it exists) OR confirmation that base-pixel responsibility was silently lost during re-derive (most likely — fits the "fresh, not port" rule). Either way, remediation requires a new plan that re-adds base-pixel emission to PixelHead (or a sibling component) before Phase 5 UAT Gates 2 + 3 can pass.
- **next_action:** Spawn gsd-debugger to inspect Phase 3 PLAN.md + SUMMARY.md + RESEARCH.md files + REQUIREMENTS.md THEM-* acceptance criteria + v2.0 PixelHead.php git history, confirm the gap dimension (design decision vs silent loss), then scope a remediation plan with file inventory + LOC estimate.
- **reasoning_checkpoint:** Hypothesis already strong from initial evidence. Want gsd-debugger to confirm whether THEM-* requirements explicitly required base-pixel emission (acceptance criteria), or whether scope was redefined during Phase 3 discussion. That distinction determines whether the fix is "restore lost behavior" (planning artifacts say it was promised) or "add new behavior" (planning artifacts never promised it).

## Evidence

- timestamp: 2026-05-25T00:00:00Z — symptom-curl: `curl -s http://your-staging-host.example/lv/p/virsejais-parklajums-builder-top-coat-uvled-15ml` returns 200, 63647 bytes, zero `fbq\|fbevents\|connect\.facebook` matches in head section. Layout used is `catalog_default` (per `themes/.../pages/product.htm`).
- timestamp: 2026-05-25T00:00:00Z — code-grep: `grep -rln "fbevents.js" plugins/logingrupa/metapixel/` returns no matches. Loader genuinely absent.
- timestamp: 2026-05-25T00:00:00Z — file-read: `components/PixelHead.php` onRun reads only ThemeEventCollector. No PluginGuard / Settings::lookup / SendCapiEvent::dispatch for a base PageView.
- timestamp: 2026-05-25T00:00:00Z — file-read: `components/eventpixel/default.htm` calls `fbq('track', ...)` directly assuming the global exists.
- timestamp: 2026-05-25T00:00:00Z — archive-read: v1.x archive confirms loader + init + PageView + noscript + CAPI twin were ALL part of the v1.x PixelHead deliverable.

## Eliminated

(none yet)

## Resolution

(pending — diagnose + scope first)
