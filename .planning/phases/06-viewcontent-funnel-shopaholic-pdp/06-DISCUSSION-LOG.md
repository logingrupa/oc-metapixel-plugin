# Phase 6: ViewContent funnel — Discussion Log

**Discussion:** 2026-05-28
**Mode:** discuss (no flags)
**Brief loaded:** `.planning/briefs/2026-05-27-viewcontent-funnel-shopaholic.md`

## Pre-discussion state

Brief locked 6 decisions (D-1..D-6). Phase slot needed assignment. Codebase scout verified ThemeAjaxHandler contract (`Metapixel::onFireEvent`, META_STANDARD allowlist includes ViewContent, returns `{event_id, script}`).

## Slot assignment

**Question:** Where slot ViewContent funnel work in milestone?

**Options presented:**
1. New Phase 6 (Recommended) — cleanest scope boundary, own CONTEXT/PLAN artifacts
2. Plan slot 05-07 — single PLAN inside Phase 5
3. Wave addendum to 05 — 2-3 new plans bumping existing order

**User answer:** New Phase 6

**Rationale captured:** ViewContent funnel is a discrete feature deliverable, not a documentation/launch sub-task. Warrants own discuss/plan/execute cycle. Phase 5 wave 6 plans 05-08..05-14 listed as blockers in next_steps section.

## Gray area 1: AJAX endpoint shape

**Options presented:**
1. Reuse `Metapixel::onFireEvent` (Recommended) — generic ThemeActionEvent path, no new endpoint
2. New `Metapixel::onProductOfferSwitch` — dedicated handler with server-side Product/Offer resolution
3. Hybrid: onFireEvent + adapter routing — extend existing handler with optional `subject_class` field

**User answer:** Hybrid — followed by clarifying question: "will this syntax allow us to also be used to extend our Metapixel with Mall plugin or any other plugin? and extend with custom plugin those guards? for security?"

**Follow-up resolution:** Reframed hybrid to use `subject_type` alias (NOT FQN class string) gated through `AdapterRegistry`.

**Follow-up options:**
1. `subject_type` alias via AdapterRegistry (Recommended) — opaque alias matched against registered adapters
2. Settings-defined allowlist of FQN classes — operator wires Settings textarea
3. `subject_type` alias + signed HMAC token — overengineered

**User final answer:** subject_type alias via AdapterRegistry

**Locked as D-7.** Mall/MelonCart plugins register `AdapterRegistry::register('mall.product', MallProductAdapter::class)` in their own `Plugin::boot()` — same JS path works without metapixel core changes. Security boundary = AdapterRegistry allowlist. Untrusted JS input cannot reach unregistered classes.

## Gray area 2: JS asset delivery

**Options presented:**
1. PixelHead injects inline `<script>` (Recommended) — zero operator config
2. Separate `[offerSwitch]` component — explicit opt-in per layout
3. Bundled JS asset via `addJs` — cacheable external asset

**User answer:** Separate component preferred, but pushed back on name + wants extensibility for Mall/MelonCart plugins.

**Follow-up resolution:** Vendor-neutral component name. Same component handles all product-type adapters via AdapterRegistry alias resolution at runtime.

**Follow-up options:**
1. `[productPixel]` / `Components\ProductPixel` (Recommended) — mirrors `[eventPixel]` naming family
2. `[pdpPixel]` / `Components\PdpPixel` — page-role acronym
3. `[catalogItemPixel]` / `Components\CatalogItemPixel` — maximally vendor-neutral

**User final answer:** `[productPixel]`

**Locked as D-8.** Mall plugin registers its adapter via AdapterRegistry; same `[productPixel]` component resolves alias at runtime — no per-vendor component proliferation.

## Gray area 3: PDP scope gate

**Options presented:**
1. Server emits `data-metapixel-pdp` on `<body>` when ProductPageWatcher fires (Recommended)
2. JS scopes to closest `[data-shopaholic-product-id]` ancestor
3. JS gate on URL pattern `/product/`

**User pushback:** "offer-selector from cart offer-selector I am confused, what is Cart offer-selector? In cart user cannot change or select offers or switch offers"

**Follow-up resolution:** User correct — Shopaholic cart has no offer selector (CartPositionList renders line items, not radio/select with `name="offer_id"`). Brief Risk register row was loose phrasing. D-6 `[name="offer_id"]` is PDP-only by Shopaholic convention. Gate not needed.

**Follow-up options:**
1. Drop scope gate (Recommended) — trust D-6 Shopaholic convention
2. Keep `data-metapixel-pdp` attr — defence-in-depth for theoretical rogue theme

**User final answer:** Drop

**Locked as D-9.** Operator-introduced rogue `[name="offer_id"]` outside PDP = operator-owned theme problem, not plugin core concern.

## Gray area 4: Default-selected offer resolution

**Options presented:**
1. First active offer by `sort_order` asc (Recommended) — matches Shopaholic native cart-add path
2. First in-stock active offer — funnel-accurate but diverges from theme display
3. Read hidden-input `offer_id` from rendered Twig — violates D-2 + D-3, double-fire risk

**User answer:** First active offer by sort_order asc

**Locked as D-10.** Empty offer collection → bare `SKU-{product_id}` fallback per brief test matrix item 4.

## Deferred ideas captured

- InitiateCheckout (v2.1)
- Search event (v2.1)
- Per-currency conversion validation (v2.1)
- AddToCart browser-pixel mirroring on offer add (review post-v2.0)
- MallProductAdapter (operator-published, docs example only per Phase 5 D-14)

## Claude's discretion items (planner resolves)

- `action_key` shape variant for offer-switch ViewContent (planner extends `viewcontent:{pid}:{eid}` → `viewcontent:{pid}:{oid}:{eid}`)
- CHANGELOG entry shape (no breaking-changes subsection per Phase 5 D-22 fresh-v2.0.0 stance; PixelHead timing change documented in PHPDoc instead)
- `AdapterRegistry::resolveByAlias()` index structure (planner picks reverse-lookup vs maintained index — likely (b) at O(1))
- `UnknownSubjectTypeException` placement under `classes/exception/`
- ProductPixel component default.htm Twig structure
- Component placement guidance in README walkthrough

## No scope creep observed.

Discussion stayed inside ViewContent funnel boundary throughout. Mall/MelonCart extension surfaced via D-7 + D-8 was framed as extension-contract validation, not Phase 6 scope expansion (Mall/MelonCart code is operator-published, not Phase 6 deliverable).
</content>
</invoke>