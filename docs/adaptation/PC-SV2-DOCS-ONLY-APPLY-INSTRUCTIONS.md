# PrimeClassy ← SimpleV2 — DOCS-ONLY Canonical Alignment

**Status:** Owner authorized documentation alignment only (2026-10-28). **No application implementation authorized.**

## Authority and evidence
- Repository: `C:\ServBay\www\primeclassy` (local Windows); previously audited branch `feat/refund-ledger-v1`, HEAD `218db8cd28cce53f8252379b9899722c96cb5dd8`; these values are historical, **verify current status**.
- Existing read-only audit: `PC-SV2-WORKFLOW-001 / PC-SV2-RBAC-001` (29 workflow groups, 11 roles, 14 gaps; source-only, runtime untested).
- Owner decision pack: `PC-SV2-DOCS-ONLY-CONSOLIDATION-20261028.md` (already placed by Owner in repo; locate exact path without assuming).
- Visual authority: `PrimeClassy-Premium-Maroon-Final-Mockdesign-v1` design package (Owner has placed locally; locate exact path). **Premium maroon inspired by Prime Cake & Cookies logo**; Inspinia is a visual reference only, not authorization to import copyrighted theme/assets or add dependencies.
- Existing PrimeClassy code, business policies, workflows, data authority, and security **override visual prototypes**. SimpleV2 report/Google Sheets layout is presentation/operational-format authority, not finance/security authority.

## Mandatory preflight (bounded, no new broad RECON)
1. Read repository `AGENTS.md`/governance, active specs, most recent checkpoint, and `git status --short`/branch/HEAD. Respect existing dirty files; never reset/clean/stash/checkout/commit.
2. Locate the Owner-provided consolidation and mockdesign files. If unavailable, **STOP and report missing input**, do not invent content.
3. Read only relevant sections/diffs in `docs/BUSINESS-RULES.md`, `docs/ARCHITECTURE.md`, `docs/MASTER-SYSTEM.md`, and existing design/governance docs; identify the authoritative paths before editing. Do not create competing canonical documents.
4. Prepare a change map `document → section → source/decision → intended delta`; resolve genuine conflicts with an Owner-decision ledger, not silent rewriting.

## Allowed edits
- Documentation (`.md`) in existing canonical documentation structure, **only** for source-backed, Owner-approved deltas.
- Add a bounded design reference/index/manifest document if necessary to link the **separate** mockdesign package. Do not overwrite or rename any frontend assets.
- Preserve unrelated content and existing documentation references; update links only where necessary and verifiable.

## Prohibited
- Any modifications under `frontend/`, `backend/`, app source, routes, API contracts in code, tests, migrations, database, lockfiles, dependencies, runtime configuration, secrets, CI, production, or remote systems.
- No test execution, build, npm install, migrations, commit, push, deployment, cleanup, broad formatting, or unapproved deletion.
- No claim of runtime PASS, UI completeness, real Google Sheets visual parity, or finished refund ledger.

## Canonical content to align
1. **Decisions OD-01..OD-06 (LOCKED):** Agen branch CRM read; Admin operational branch CRM read; owning Sales/SKS may initiate CRM-only returns with Customer consent; CRM↔Konsumen link requires verified account control + OTP on verified channel + audit; authorized audited unlink preserves historical records; refund commission adjustment formula **not approved** (design options only); adapt all relevant SimpleV2 reporting/Sheets formats with role/Agent scope.
2. **11 fixed roles** (`super_admin`, `agen`, `admin`, `keuangan`, `gudang`, `kurir`, `koordinator-kurir`, `korsal`, `sales`, `sales-kurir-sub`, `konsumen`), each with distinct dashboard UX and preserved authorized workflow. Frontend visibility never grants backend authority.
3. **29 existing workflow groups** preserved with source evidence and before→after page/action/policy mapping; audit source verification != runtime verification. Include SC-03 Admin-only, Gudang proposal→Admin approval, Keuangan/Super Admin payment verification, Admin/Super Admin delivery verification, SKS parity + Sub stock/delivery/self-purchase, immutable snapshots and Agent isolation.
4. **CRM buyer origin**: CRM Customer without login, not fake User or public guest checkout; account-origin vs CRM-origin exactly one buyer context and immutable identity snapshot. Explicit downstream coverage: order `konsumen_id`/idempotency, policies, payments, stock, commissions, shipping, returns, refunds, reporting joins, Sheets; no simplistic nullable FK shortcut. Features are approved **NOT IMPLEMENTED**.
5. **Reporting & Sheets**: SimpleV2 43 transaction-export columns and 47 Sheets payload columns as source-level baselines; PrimeClassy 17 dataset keys; real workbook layout/header/format not verified. Require per-column mapping, format parity gates, manual-column preservation (separate system/manual tabs or approved safe sync), scoped export. Outstanding formula and 'posisi uang' semantics unresolved.
6. **Premium Maroon mockdesign**: logo-inspired maroon/burgundy, warm cream/gold, status colors; Inspinia-inspired layout. Treat as **DESIGN REFERENCE ONLY**: interactive prototype is not an existing Vue frontend or complete operational screens. All assets/dependency versions/licenses require repo verification. **Do not change any existing frontend page**.
7. **Known gaps**: AU-01 required HTTP 503 `registration_unavailable` conflicts with current source; refund ledger frontend incomplete; CRM/Visit/assisted-order/linking not implemented; refund commission formula pending Owner; `FU`/`D` meanings unverified. Record defects, don't fix in docs-only task.

## Required outputs
A. Exact changed-doc inventory and before→after section map.
B. Summary of locked decisions, preserved workflows and role-specific UX design reference.
C. Conflict/gap ledger with `VERIFIED_SOURCE`, `PARTIALLY_VERIFIED`, `NOT_VERIFIED`, `OWNER_DECISION_REQUIRED` statuses.
D. `git diff --check` and scoped `git diff -- <changed-doc-paths>` review evidence; do **not** run application tests.
E. Confirmation that no non-doc files changed **by this task**, no existing changes were overwritten, and no commit/deployment occurred.
F. Final verdict: `DOCS-ONLY COMPLETE`, `PARTIAL`, or `BLOCKED` with precise reason and next action. Do not mark overall implementation PASS.

## Stop condition
Stop after documentation and evidence report. Ask Owner before any code, frontend, asset, dependency, database, testing, commit, or production work.
