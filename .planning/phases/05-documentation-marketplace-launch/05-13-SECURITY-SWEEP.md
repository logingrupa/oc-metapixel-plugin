---
plan: 05-13
phase: 05-documentation-marketplace-launch
status: PARTIAL — Step A complete, Step B deferred (launch not scheduled)
mode: read-only audit
date: 2026-05-27
operator: Rolands Zeltins
launch_blocker: true
launch_scheduled: false
---

# Plan 05-13 — Pre-flip Security Sweep (Step A only)

Per user direction 2026-05-27: run Step A (read-only scans) only. Step B (`.planning/` redactions) deferred until launch is scheduled. Plan 05-14 launch sequence is on hold.

## Scope of this run

Step A — Read-only audit:
- A1: secrets-in-git-history grep (strict regex per plan)
- A2: D-24 remote legacy tag check
- A3: D-24 remote legacy branch check
- Additional informational scans for situational awareness:
  - Real pixel ID `2291486191076331` reference count in `.planning/`
  - CAPI access token (`EAA*`) plaintext check in `.planning/`
  - Test Events Code (`TEST58466`) reference count in `.planning/`
  - Operator-specific infra refs (`new.nailscosmetics.lv`, Forge paths, RFC1918 IPs) in `.planning/`

Step B — Destructive redactions (NOT run this session):
- `sed`/edit redact `new.nailscosmetics.lv` → `your-staging-host.example` (or remove) in `.planning/STATE.md`, `.planning/phases/.../05-CONTEXT.md`, `.planning/phases/.../05-DISCUSSION-LOG.md`, `.planning/research/PITFALLS.md`
- Decide on real-pixel-ID redaction strategy (19 files contain literal `2291486191076331`)
- Decide on Test Events Code redaction strategy (4 files contain literal `TEST58466`)

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

#### Real pixel ID `2291486191076331` references

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

Step B redaction option: `find .planning -name '*.md' -exec sed -i 's/2291486191076331/<pixel-id-redacted>/g' {} +`.

Note: A meta pixel ID is not strictly a credential — public sites embed it client-side in `fbq('init', …)`. Treat as PII / operator branding rather than secret. Redaction nice-to-have, not required for launch safety per CONTEXT D-26.

#### CAPI access token (`EAA*` prefix) plaintext

Grep `EAA[A-Za-z0-9]{30,}` against `.planning/`: **0 matches.** **PASS.** Operator only typed CAPI tokens into the backend Settings UI; none were ever committed in any form.

#### Test Events Code `TEST58466` references

4 files in `.planning/` contain the literal value:

- `phases/05-documentation-marketplace-launch/05-03-UAT-GATE-1.md`
- `phases/05-documentation-marketplace-launch/05-06-UAT-GATE-3.md`
- `phases/05-documentation-marketplace-launch/05-UAT.md`
- `debug/settings-save-host-resolver-di.md`

Test Events Codes route events into the Meta Test Events tab; they are throw-away identifiers (not auth). Redaction nice-to-have for cleanliness.

#### Operator-specific infra refs

Grep `new\.nailscosmetics\.lv|forge\.laravel\.com|\b10\.[0-9]|\b192\.168\.` against `.planning/` (excluding `archive/`): **20+ files** carry hits. Primary references are the staging URL `https://new.nailscosmetics.lv` (UAT logs + plan files + debug sessions). No private IPs found in the in-scope tree.

Step B redaction strategy when launch is scheduled:
- Replace `new.nailscosmetics.lv` → `your-staging-host.example`
- Replace any RFC1918 host paths with generic placeholders
- Leave `archive/v1.1.1/` untouched (per CONTEXT D-26 archive scope rule)

## Verdict

**A1, A2, A3 PASS.** No launch-blocker secrets in repo history; no remote leakage of legacy refs. The strict-regex grep that gates plan 05-14 Task 3 Step E is GREEN.

**Step B deferred** until launch is rescheduled. The redaction worklist above is the queue.

## Resume signal

This file's status flips to `PASS` and Step B redactions run + commit when the operator types the resume signal `SWEEP PASS` AND `LAUNCH SCHEDULED`. Until then plan 05-13 stays open.

_Audited 2026-05-27._
