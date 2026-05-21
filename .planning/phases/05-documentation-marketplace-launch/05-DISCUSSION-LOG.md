# Phase 5: Documentation + marketplace launch - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-21
**Phase:** 05-documentation-marketplace-launch
**Areas discussed:** README structure, Legacy JS strip + cutover gates, Live smoke validation, Custom-adapter example shape, Screenshot capture strategy, Release-tag flow + public flip

---

## Initial scope-expansion blocker (surfaced mid-discussion)

| Option | Description | Selected |
|--------|-------------|----------|
| Insert Phase 4.5 | New phase between 4 and 5 — clean separation of validation vs docs | |
| Add validation + strip as Phase 5 plans | Front-load Phase 5 with strip + smoke plans before docs | ✓ |
| Roadmap reassess | Re-derive phase boundaries entirely | |
| Continue Phase 5 docs anyway | Document intended walkthrough, validate later | |

**User's choice:** Add validation + strip as Phase 5 plans.
**Notes:** User flagged that (a) live tracking has not been end-to-end tested yet on a real install with real CAPI + Meta Test Events round-trip, and (b) theme `themes/logingrupa-naisstore/` still carries legacy v1.x JS pixel code. Both block the DOCS-01 launch acceptance gate (10-min dry-run). Phase 5 absorbs the strip + smoke work as pre-doc plans.

---

## README structure

| Option | Description | Selected |
|--------|-------------|----------|
| Single README.md | One linear walkthrough; marketplace pages render it directly | ✓ |
| README index + docs/ deep dives | Short index README, depth in docs/ files | |
| Hybrid | Linear README to first verified event, then `## Further reading` to docs/ | |

**User's choice:** Single README.md.
**Notes:** Risk of file getting long (800-1200 lines) accepted; cleaner buyer flow + matches Lovata convention.

| Option | Description | Selected |
|--------|-------------|----------|
| Shopaholic + Theme paths | Twin walkthroughs in main README | ✓ |
| Shopaholic primary, Theme appendix | Main = Shopaholic, Theme as `## Headless` section | |
| Theme primary, Shopaholic appendix | Main = Theme, Shopaholic as `## With Shopaholic` | |

**User's choice:** Shopaholic + Theme paths.
**Notes:** Matches MKT-05 dual CI matrix (Run A full-Lovata + Run B minimal-install).

| Option | Description | Selected |
|--------|-------------|----------|
| Step-by-step with screenshots | Annotated Meta UI screenshots | |
| Step-by-step text-only | Numbered text steps, no screenshots | ✓ |
| Link out to Meta docs | Defer to developers.facebook.com | |

**User's choice:** Step-by-step text-only.
**Notes:** Survives Meta UI redesigns. Accepted higher friction for non-marketers.

| Option | Description | Selected |
|--------|-------------|----------|
| Symptom → log line → fix table | Markdown table mapping user-visible symptom to operator fix | ✓ |
| Decision-tree flowchart | ASCII flowchart, covers compound failures | |
| FAQ-style | Q&A format | |

**User's choice:** Symptom → log line → fix table.
**Notes:** Matches DOCS-02 wording ("keyed to `Log::*` context arrays").

---

## Legacy JS strip + cutover gates

| Option | Description | Selected |
|--------|-------------|----------|
| Inline fbq() in layouts/pages/partials | Raw `<script>fbq(...)` blocks | ✓ |
| Bundled in common.js / webpack entry | Pixel logic in webpack-compiled JS | ✓ |
| Twig {% set %} dataLayer + dispatcher partial | dataLayer pattern via Twig | ✓ |
| Don't know — needs inventory spike first | First plan greps + reports | ✓ |

**User's choice:** All four selected — inventory plan scans ALL three patterns; strip plan removes only confirmed matches.
**Notes:** Multi-select; user wants thorough inventory pass before committing to strip plan.

| Option | Description | Selected |
|--------|-------------|----------|
| PixelHead in layout + EventPixel per event | Phase 3 components; browser + server event_id-synced | ✓ |
| Server-side CAPI only (no browser pixel) | Strip JS, no replacement; loses pixel-only signals | |
| Twig API only on event pages | `{% metapixel_track %}` per page, no PixelHead | |

**User's choice:** PixelHead in layout + EventPixel per event.
**Notes:** Standard v2.0 wiring.

| Option | Description | Selected |
|--------|-------------|----------|
| Atomic cutover in one PR | Strip + rewire ship together, no coexistence | |
| Feature flag | Both paths in code, env-var toggle | |
| Coexist + Meta dedup absorbs duplicate | Leave legacy, add v2.0, rely on dedup | |

**User's choice:** Free-text — "First we strip, I test if 100% all events are removed, and nothing fires pixel events from frontend, only then after I confirm I will embed in layout our MetaPixel component to test."
**Notes:** Operator-gated two-step cutover. Strip → operator UAT (zero events) → PixelHead → operator UAT (PageView present, event_id-synced) → EventPixel per event → operator UAT. Three named gates with explicit operator confirmation between each plan.

| Option | Description | Selected |
|--------|-------------|----------|
| Meta Pixel Helper Chrome extension | Fastest manual signal | ✓ |
| DevTools Network panel — filter facebook.com | Catches non-standard loaders | |
| Meta Test Events live view (Events Manager) | Highest signal; confirms dedup | ✓ |
| EventLog DB table grep | Confirms server-side CAPI fired | ✓ |

**User's choice:** Pixel Helper + Meta Test Events + EventLog DB.
**Notes:** DevTools Network skipped (Pixel Helper covers same surface).

---

## Live smoke validation

| Option | Description | Selected |
|--------|-------------|----------|
| Production nailscosmetics.lv | Real install + real orders | |
| Staging instance | Mirror of prod | |
| Local docker / Sail install | Disposable OctoberCMS 4.x | |
| Both: docker dry-run + prod canary | Two-environment validation | |

**User's choice:** Free-text — "this is staging. Production new.nailscosmetics.lv — Real install, real Lovata stack, real orders. Set Meta `test_event_code` so events route to Test Events live view (NOT production Pixel analytics). Captures real-world env vars, Laravel FORGE."
**Notes:** `new.nailscosmetics.lv` is the smoke target — Forge-hosted staging-shaped install with real orders + Settings `test_event_code`.

| Option | Description | Selected |
|--------|-------------|----------|
| Purchase (primary) | Shopaholic Order → Purchase CAPI + browser pixel | ✓ |
| PageView + ViewContent | Head-tag sanity + content_ids format verify | ✓ |
| AddToCart | ShopaholicCartPositionAdapter | |
| Theme custom event (Twig API) | ThemeActionAdapter no-cart path | |

**User's choice:** Purchase + PageView + ViewContent.
**Notes:** MVP smoke set for DOCS-01. AddToCart + Theme Twig deferred to post-launch.

| Option | Description | Selected |
|--------|-------------|----------|
| Markdown smoke log in .planning/ | `05-SMOKE-LOG.md` audit trail | ✓ |
| Inline notes in README draft | Faster but no audit | |
| Video screencast + transcript | Loom / OBS recording | |

**User's choice:** Markdown smoke log.
**Notes:** Doubles as audit trail; README copies validated step sequence verbatim.

| Option | Description | Selected |
|--------|-------------|----------|
| /gsd-debug session | Systematic investigation per bug | ✓ |
| Hotfix plan inside Phase 5 | Atomic fix-plan, no debug overhead | |
| Backport to originating phase | Fix in source phase (Phase 3 / 4) | |

**User's choice:** /gsd-debug session.
**Notes:** Standard rigor for smoke-found bugs.

---

## Custom-adapter example shape

| Option | Description | Selected |
|--------|-------------|----------|
| Fully synthetic Acme\Cart\Models\Order | Made-up cart in made-up namespace | |
| OFFLINE\Mall\Models\Order (real preview) | Real Mall plugin model | ✓ |
| Both — synthetic primary + Mall sidebar | Both forms | |

**User's choice:** OFFLINE\Mall\Models\Order.
**Notes:** Pulls v2.1 MALL-01 backlog item forward as doc example only.

| Option | Description | Selected |
|--------|-------------|----------|
| docs/CUSTOM-ADAPTERS.md only | Inline code blocks; reader copies | ✓ |
| Live in examples/ dir + docs link | Working code + smoke test | |
| Ship as production MallAdapter | Move MALL-01 into Phase 5 scope | |

**User's choice:** docs/CUSTOM-ADAPTERS.md only.
**Notes:** Zero composer dep on OFFLINE\Mall. Plugin stays Shopaholic-only at v2.0.0.

| Option | Description | Selected |
|--------|-------------|----------|
| Show all 3 hooks with realistic use cases | before_dispatch / after_dispatch / dead_letter examples | ✓ |
| Show 1 hook + link to source | Most-asked-about only | |
| Mention hooks, defer to docs/HOOKS.md | Single paragraph + separate file | |

**User's choice:** Show all 3 with concrete use cases.
**Notes:** test_event_code injection + analytics mirror + Slack alert.

| Option | Description | Selected |
|--------|-------------|----------|
| Yes — dedicated `## Testing your adapter` section | Documents EventSubjectAdapterContractTestCase | ✓ |
| Mention contract test exists | Link to FakeAdapterContractTest.php | |
| Skip contract test in DOCS-03 | Testing is reader's problem | |

**User's choice:** Dedicated `## Testing your adapter` section.
**Notes:** Locks marketplace contract for v2.x.

---

## Screenshot capture strategy

| Option | Description | Selected |
|--------|-------------|----------|
| Capture from live smoke on new.nailscosmetics.lv | Real env, redact via dummy row | ✓ |
| Synthetic disposable install screenshots | Fresh docker OctoberCMS | |
| Mockup composites (Figma) | Designer mockups | |

**User's choice:** Capture from live smoke.
**Notes:** Smoke produces them as side-effect — single source of truth.

| Option | Description | Selected |
|--------|-------------|----------|
| Black-box overlay before commit | Image-editor redaction | |
| Use redact-friendly Settings record on staging | Dummy placeholder values; no post-processing | ✓ |
| Both — dummy row + overlay | Belt-and-suspenders | |

**User's choice:** Redact-friendly Settings record.
**Notes:** `pixel_id = 000000000000000`, `access_token = REDACTED_FOR_DEMO_DO_NOT_USE`.

| Option | Description | Selected |
|--------|-------------|----------|
| assets/marketplace/ | October standard convention | |
| docs/screenshots/ | Doc-coupled | ✓ |
| Top-level images/ | Theme convention, risk collision | |

**User's choice:** docs/screenshots/.
**Notes:** Tighter doc coupling.

| Option | Description | Selected |
|--------|-------------|----------|
| Keep icon-bullseye Font Awesome ref | Backend FA glyph, no PNG | ✓ |
| Custom PNG icon | Designer-supplied 256/512 PNG | |
| Both — FA backend + PNG marketplace tile | Two surfaces | |

**User's choice:** Keep icon-bullseye.
**Notes:** Zero binary asset commitment. PNG decision deferred if marketplace listing requires it.

---

## Release-tag flow + public flip

| Option | Description | Selected |
|--------|-------------|----------|
| Annotated tag from master HEAD | Single source of truth | ✓ |
| release/v2.0.0 branch + cherry-pick + tag | Release-branch cherry-pick | |
| Tag + long-lived v2.x branch | Maintenance branch | |

**User's choice:** Annotated tag from master HEAD.
**Notes:** Matches v1.1.1 precedent.

| Option | Description | Selected |
|--------|-------------|----------|
| v2.0.0-only vs legacy/v1.1.1 baseline | Breaking changes + added + removed subsections | |
| Full Keep-a-Changelog from v1.0 | Every milestone documented | |
| v2.0.0 + link to v1.x milestones doc | Clean cut, archived elsewhere | |

**User's choice:** Free-text — "Remove info about legacy this is fresh repo no one uses! no legacy no dead code! v1 branch is only local I hope!"
**Notes:** CHANGELOG.md = fresh v2.0.0 initial release entry only. ZERO v1.x diff. Verified v1.1.1 tag + legacy/v1.1.1 branch exist local-only (`git ls-remote --tags origin 'v1*'` returns empty).

| Option | Description | Selected |
|--------|-------------|----------|
| Keep local-only as personal archive | Local branch + tag, never pushed | ✓ |
| Delete branch + tag locally too | Zero local trace | |
| Move to offline archive | git bundle export, delete from repo | |

**User's choice:** Keep local-only as personal archive.
**Notes:** Inspection capability retained.

| Option | Description | Selected |
|--------|-------------|----------|
| Private GitHub repo + Composer VCS | Buyer needs invite or repo flipped | |
| Public GitHub repo at v2.0.0 launch | Flip public, Composer VCS no-auth | ✓ |
| Packagist publication | Public marketplace flow | |

**User's choice:** Free-text — "Lets make it now public."
**Notes:** Repo flips public at v2.0.0. Composer VCS without auth.

| Option | Description | Selected |
|--------|-------------|----------|
| Real pixel_id / access_token in git history | Secrets sweep | ✓ |
| Internal hostnames / IPs in .planning/ docs | Infra refs sweep | ✓ |
| Customer PII in EventLog seed data | Test fixtures sweep | |
| Operator-specific config in lang / docblocks | Phase N markers, .lv-specific refs | |

**User's choice:** Secrets + internal hostnames. Other notes: "theme is private, so from theme no, and also all logs are in DB."
**Notes:** Theme not in this repo (separate private repo). Application logs DB-only (never committed). Sweep scope = secrets in git history + internal infra refs in `.planning/` only.

| Option | Description | Selected |
|--------|-------------|----------|
| Keep .planning/ in public repo + scrub infra refs | Public GSD audit trail | ✓ |
| .gitignore .planning/ + git rm --cached | Private planning only | |
| Split repos — private planning + public plugin | Two-repo split | |
| Squash entire history + force-push fresh | Nuclear option | |

**User's choice:** Keep .planning/ in public repo + scrub.
**Notes:** Shows GSD workflow rigor to marketplace audience.

---

## Claude's Discretion

- Plan-level sequencing within the 14-plan Phase 5 (planner refines waves + parallelism).
- Exact `composer.json` `keywords` array.
- README ordering of FailedEvents section relative to Troubleshoot.
- CHANGELOG date format.
- Exact wording of UAT gate pass/fail criteria (planner drafts; operator reviews).
- `composer.json` license re-evaluation (currently `"proprietary"`; public flip implies decision).

## Deferred Ideas

- MallAdapter as production code — stays v2.1 backlog (MALL-01).
- AddToCart + Theme Twig API smoke events — post-launch.
- Video screencast / Loom buyer onboarding asset — marketing backlog.
- PNG plugin icon — v2.0.1 if marketplace requires.
- Long-lived `v2.x` maintenance branch — cut when first v2.0.x patch surfaces.
- Packagist publication — post-launch if buyer demand surfaces.
- Docker dry-run as second smoke env — reconsider if buyer UX research suggests docker-compose-up demo.
- DevTools Network panel UAT verifier — adopt if Pixel Helper misses an event class.
