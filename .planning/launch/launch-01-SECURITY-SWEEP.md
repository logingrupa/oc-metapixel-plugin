---
plan: 05-13
phase: 05-documentation-marketplace-launch
status: COMPLETE — Step A + Step B executed
mode: read-only audit (Step A) + destructive redaction (Step B)
date: 2026-05-27 (Step A); 2026-07-03 (Step B)
operator: Rolands Zeltins
launch_blocker: true
launch_scheduled: false
step_b_executed: 2026-07-03
step_b_trigger: plan 05-21 operator REDACT-FIRST decision (pre-push)
---

# Plan 05-13 — Pre-flip Security Sweep

Per user direction 2026-05-27: Step A (read-only scans) run first; Step B (`.planning/` redactions) deferred until launch is scheduled.

**Step B executed 2026-07-03** under plan 05-21: the operator chose REDACT-FIRST before the outward push to the already-public `origin`. All sweep-target strings were redacted across tracked non-archive `.planning/` files (archive/ left untouched per D-26). See `## Step B Execution (2026-07-03)` below.

## Scope of this run

Step A — Read-only audit:
- A1: secrets-in-git-history grep (strict regex per plan)
- A2: D-24 remote legacy tag check
- A3: D-24 remote legacy branch check
- Additional informational scans for situational awareness:
  - Real pixel ID `<pixel-id-redacted>` reference count in `.planning/`
  - CAPI access token (`EAA*`) plaintext check in `.planning/`
  - Test Events Code (`<test-event-code-redacted>`) reference count in `.planning/`
  - Operator-specific infra refs (`your-staging-host.example`, Forge paths, RFC1918 IPs) in `.planning/`

Step B — Destructive redactions (executed 2026-07-03; see execution record at end):
- Redact the staging hostname → `your-staging-host.example` across tracked non-archive `.planning/` files
- Redact real pixel ID → `<pixel-id-redacted>`
- Redact Test Events Codes → `<test-event-code-redacted>`

## Step A Results

### A1 — Secret-history grep (strict regex per plan must_haves)

```bash
git log --all -p | grep -iE 'pixel_id\s*[=:]\s*[0-9]{10,}|access_token\s*[=:]\s*EAA[A-Za-z0-9]{20,}' \
  | grep -vE '1234567890|000000000000000|REDACTED_FOR_DEMO|placeholder'
```

**Result:** 0 lines matched. **PASS.**

Pitfall 1 mitigation holds: no `pixel_id = <real>` or `access_token = EAA<…>` shape ever committed inside the plugin repo.

`git-filter-repo` NOT invoked (per RESEARCH Q3 RESOLVED: opportunistic install only on hits).

### A2 — Remote legacy tag check (D-24 lock)

```bash
git ls-remote --tags origin 'v1*'
```

**Result:** empty. **PASS.** Legacy v1.x tag stays local-only per D-24.

### A3 — Remote legacy branch check (D-24 lock)

```bash
git ls-remote --heads origin 'legacy/*'
```

**Result:** empty. **PASS.** `legacy/v1.1.1` branch stays local-only per D-24.

### Informational scans (Step B preparation)

These scans are **not** part of the launch-blocker grep set; recorded here so Step B has a concrete worklist when launch is rescheduled.

#### Real pixel ID `<pixel-id-redacted>` references

19 files in `.planning/` contain the literal value:

- `PROJECT.md`, `PLAN.md`, `PLAN-v2-original.md`
- `archive/v1.1.1/phases/02-skeleton-cookie-fix/{02-01,02-02,02-04}-PLAN.md`
- `archive/v1.1.1/phases/02-skeleton-cookie-fix/{02-04-SUMMARY,02-PATTERNS}.md`
- `archive/v1.1.1/phases/03-purchase-end-to-end/{03-06-PLAN,03-06-SUMMARY}.md`
- `archive/v1.1.1/phases/03.1-event-log-refactor/03.1-06-PLAN.md`
- `archive/v1.1.1/phases/03.1-08-dead-code-cleanup/03.1-08-03-SUMMARY.md`
- `phases/05-documentation-marketplace-launch/05-02-LEGACY-INVENTORY.md`
- `phases/05-documentation-marketplace-launch/05-02-SUMMARY.md`
- `phases/05-documentation-marketplace-launch/05-02-RESPAWN-SUMMARY.md`
- `phases/05-documentation-marketplace-launch/05-03-UAT-GATE-1.md`
- `phases/05-documentation-marketplace-launch/05-UAT.md`
- `debug/pixelhead-no-base-pageview.md`
- `debug/settings-save-host-resolver-di.md`

Step B redaction option: `find .planning -name '*.md' -exec sed -i 's/<pixel-id-redacted>/<pixel-id-redacted>/g' {} +`.

Note: A meta pixel ID is not strictly a credential — public sites embed it client-side in `fbq('init', …)`. Treat as PII / operator branding rather than secret. Redaction nice-to-have, not required for launch safety per CONTEXT D-26.

#### CAPI access token (`EAA*` prefix) plaintext

Grep `EAA[A-Za-z0-9]{30,}` against `.planning/`: **0 matches.** **PASS.** Operator only typed CAPI tokens into the backend Settings UI; none were ever committed in any form.

#### Test Events Code `<test-event-code-redacted>` references

4 files in `.planning/` contain the literal value:

- `phases/05-documentation-marketplace-launch/05-03-UAT-GATE-1.md`
- `phases/05-documentation-marketplace-launch/05-06-UAT-GATE-3.md`
- `phases/05-documentation-marketplace-launch/05-UAT.md`
- `debug/settings-save-host-resolver-di.md`

Test Events Codes route events into the Meta Test Events tab; they are throw-away identifiers (not auth). Redaction nice-to-have for cleanliness.

#### Operator-specific infra refs

Grep `new\.nailscosmetics\.lv|forge\.laravel\.com|\b10\.[0-9]|\b192\.168\.` against `.planning/` (excluding `archive/`): **20+ files** carry hits. Primary references are the staging URL `https://your-staging-host.example` (UAT logs + plan files + debug sessions). No private IPs found in the in-scope tree.

Step B redaction strategy when launch is scheduled:
- Replace `your-staging-host.example` → `your-staging-host.example`
- Replace any RFC1918 host paths with generic placeholders
- Leave `archive/v1.1.1/` untouched (per CONTEXT D-26 archive scope rule)

## Verdict

**A1, A2, A3 PASS.** No launch-blocker secrets in repo history; no remote leakage of legacy refs. The strict-regex grep that gates plan 05-14 Task 3 Step E is GREEN.

**Step B deferred** until launch is rescheduled. The redaction worklist above is the queue.

## Resume signal

This file's status flips to `PASS` and Step B redactions run + commit when the operator types the resume signal `SWEEP PASS` AND `LAUNCH SCHEDULED`. Until then plan 05-13 stays open.

_Audited 2026-05-27._

---

## Step B Execution (2026-07-03) — plan 05-21 REDACT-FIRST

**Trigger:** Plan 05-21 push checkpoint. Operator chose REDACT-FIRST — run the deferred Step B redaction before the outward `git push origin master`.

**Scope rule:** Redact tracked non-archive `.planning/` files only. `.planning/archive/**` left untouched per D-26.

### Redaction map (HEAD / working tree)

| String | Replacement | Non-archive files redacted | Archive files kept (D-26) |
|--------|-------------|----------------------------|----------------------------|
| staging hostname (`new.`-prefixed) | `your-staging-host.example` | 27 | 11 |
| real pixel ID | `<pixel-id-redacted>` | 11 | 9 |
| Test Events Code A | `<test-event-code-redacted>` | 5 | 0 |
| Test Events Code B | `<test-event-code-redacted>` | 1 | 0 |

Post-redaction grep of non-archive `.planning/` for all four strings: **0 hits.** 33 unique files changed, all inside `.planning/`, none in `.planning/archive/`, none in plugin code/tests/config (the strings never appeared outside `.planning/`).

### History-exposure analysis (remote master `41bdf3c` vs local HEAD)

The redaction cleans HEAD only. Remote `master` is `41bdf3c` (2026-05-21); local HEAD is 154 commits ahead. What a plain (non-force) push newly exposes:

| String | In already-pushed history (`41bdf3c`) | Class | Marginal new exposure via push |
|--------|----------------------------------------|-------|--------------------------------|
| staging hostname | 24 files — **already public** | internal host, already leaked | none of a new class (already public; also neutralised at HEAD tip) |
| real pixel ID | 14 files — **already public** | inherently public (renders in live site HTML) | low, already-public class |
| personal / operator emails | present, **identical pushed vs HEAD counts** | PII, already public | **zero** new email references added by the 154 unpushed commits |
| real FB CAPI token (`EAA…`, ≥30) | **0** | secret | **0** — none tracked anywhere (case-sensitive scan clean; earlier "hit" was a git SHA `eaab74b…`) |
| Test Events Codes | **0** — NOT previously public | throwaway identifier, **not auth** (Step A classification) | new-but-non-secret; residual in intermediate commit history even after HEAD redaction |

**Decision — proceed with a plain push (no force, no history rewrite):**
- No genuine secret (live token/credential) appears anywhere in the unpushed range — the STOP condition (sensitive string only in unpushed commits) is **not** met.
- The already-public strings (staging host, pixel ID, emails) add no new *class* of exposure; the tip is additionally cleaned by this redaction.
- The only strings genuinely new to the remote are the Meta Test Events Codes, which Step A classifies as throwaway, non-auth identifiers (useless without the operator's pixel access token, which is not exposed). Their residual presence in intermediate commit history is accepted; a history rewrite / force-push is explicitly **out of scope** and was not performed.

_Step B executed 2026-07-03 under plan 05-21._
