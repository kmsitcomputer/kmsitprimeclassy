# IMP-010 Codex Continuation Checkpoint — 2026-11-20

## Current resumed status — Product & Catalog priority complete

**Execution status: ACTIVE — continuing the remaining 16 Codex-owned PARTIAL endpoints.** The Owner resumed work and prioritized all 19 Product & Catalog PARTIAL endpoints before other areas.

- Branch: `imp010/go-backend`
- Current HEAD: `11daac76da686d1c6fcfe7fdf9b531bb79e167be` (`feat(imp-010): complete product catalog parity`)
- Starting inventory for this resumed priority: 157 GO / 35 PARTIAL / 183 Laravel-only.
- Completed exactly 19/19 assigned Product & Catalog endpoints. Canonical matrix now reports **176 GO / 16 PARTIAL / 183 Laravel-only** (375 total).
- Commit for this batch: `11daac7 feat(imp-010): complete product catalog parity`.
- Differential evidence: `PYTHONPATH=tools/parity python3 -u tools/parity/compare_products.py` — **9 grouped scenarios passed, 0 failed**. This covers the exact 19 routes, including anonymous and Agent/Sub public product stock, CRUD, authorization, validation, management filters, fees, variation writes, image ordering/primary promotion, filesystem storage and cleanup.
- Scoped checks: from `go-backend`, `go test ./internal/api ./internal/catalog ./internal/inventory` — PASS; `go vet ./internal/api ./internal/catalog ./internal/inventory` — PASS; `git diff --check` — PASS.
- The product batch changed the two public catalog read handlers and catalog stock projection only; no schema changes and no GLM resources were touched. No remote push or deployment.
- CMS homepage-block update WIP remains uncommitted and unchanged by the Product & Catalog work. Its original four modified Go files and untracked differential harness were backed up outside the repo at `/tmp/primeclassy-imp010-codex-wip-20261120.2drgfM`; every backup file passed SHA-256 verification. Product test image artifacts were removed after confirming their paths were no longer referenced by `primeclassy_testing`.

### Remaining PARTIAL inventory (16)

- `POST /api/v1/auth/register`
- `POST /api/v1/cms/articles`
- `DELETE /api/v1/cms/articles/{article}`
- `PATCH /api/v1/cms/articles/{article}`
- `PATCH /api/v1/cms/homepage-blocks/{block}`
- `PATCH /api/v1/cms/homepage-blocks/{block}/toggle`
- `GET /api/v1/commissions`
- `GET /api/v1/commissions/summary`
- `GET /api/v1/crm/customers/{customer}`
- `GET /api/v1/crm/customers/{customer}/visits`
- `GET /api/v1/crm/follow-ups`
- `GET /api/v1/homepage`
- `GET /api/v1/referral/{code}`
- `GET /api/v1/reports/transactions`
- `GET /api/v1/sub-stock/my`
- `POST /api/v1/warehouse/opnames`

**Exact next action:** resume inspection and differential verification of the preserved WIP for `PATCH /api/v1/cms/homepage-blocks/{block}`. Read its current diff and harness; run only its scoped differential and Go checks, fix evidenced parity gaps, update the generated matrix only after PASS, then commit the verified endpoint. Keep all later work within the remaining 16 PARTIAL inventory and do not touch GLM-owned Laravel-only routes.

The following safe-pause snapshot is retained as historical context from immediately before the latest Owner resume mandate.

**Execution status: PAUSED BY OWNER — SAFE TO RESUME.** The owner requested an immediate safe checkpoint and pause. No further implementation, tests, or container actions are authorized during this pause.

## Latest pause snapshot

- Branch: `imp010/go-backend`
- HEAD: `f64f4e2b0961a92d932f23b97b3cac31ea9551e0` (`docs(imp-010): checkpoint homepage block deletion`)
- Git status: modified `go-backend/internal/api/handlers_cms_admin.go`, `go-backend/internal/api/homepage_block_form.go`, `go-backend/internal/api/homepage_block_validation.go`, `go-backend/internal/cms/admin.go`; untracked `tools/parity/compare_cms_homepage_block_update.py`.
- The uncommitted changes are WIP for `PATCH /api/v1/cms/homepage-blocks/{block}` and its differential harness. Preserve them; do not reset, revert, clean, or commit until resumed, reviewed, and verified.
- Latest committed matrix: **157 GO / 35 PARTIAL / 183 Laravel-only** (375 total). Three Codex-owned endpoints are completed in this resumed work period. The owner-reported 38 PARTIAL at resume was reduced by three; current WIP is not verified and must not be counted as GO.
- Last fully recorded differential evidence remains the four PASS results below for create, reorder, and delete. The latest update-endpoint harness run was started after adding multipart/slider cases, but its output was truncated and no final PASS/FAIL result is available in this checkpoint. Do not treat it as passing.
- The latest known scoped Go tests and vet passed before the most recent harness-only edits; no new Go test/vet run is recorded after the current WIP snapshot.
- Exact next action on resume: inspect the current diff and harness result/state, then complete verification of the WIP `PATCH /api/v1/cms/homepage-blocks/{block}` against Laravel (including multipart method spoofing, image/slide side effects, validation, RBAC, response and DB state). Fix only evidenced parity gaps; run scoped tests/vet and the differential harness, regenerate the canonical matrix only after parity passes, self-review and commit the verified endpoint. Keep all other assigned PARTIAL work within the owner-mandated scope.

The prior active continuation checkpoint follows for historical evidence and completed-unit details.

## Repository and inventory

- Branch: `imp010/go-backend`
- Previous HEAD: `909276259c2b004a4203c0dd2a25d9308eeb675b`
- Implementation commit: `1705e00 fix(imp-010): verify homepage block creation parity`
- Current HEAD at checkpoint update: `94bfea8` (the checkpoint itself is committed separately).
- Commits since `0279b32`: `f41f830`, `0de69d0`, `af96e4d`, `fe6c92d`, `0db44b8`, `c1bee24`, `94c07b7`, `105c7a8`, `bd1c484`, `a54a159`, `a88d7c2`, `dcc72a3`, `e902f20`, `11f2897`, `9092762`, `1705e00`, `29fc383`, `94bfea8`.
- Resume inventory: 154 GO / 38 PARTIAL / 183 Laravel-only.
- Regenerated matrix after completed units: **157 GO / 35 PARTIAL / 183 Laravel-only** (375 total; 192 served by Go).
- Codex completed three assigned PARTIAL endpoints in this resumed session: `POST /api/v1/cms/homepage-blocks`, `PATCH /api/v1/cms/homepage-blocks/reorder`, and `DELETE /api/v1/cms/homepage-blocks/{block}`. GLM inventory remains 183 Laravel-only; no GLM code, worktree, database, or container was modified.

## Completed endpoint

`POST /api/v1/cms/homepage-blocks` is now GO in the generated matrix. JSON and multipart handling cover Laravel validation/coercion, content rules, HTMLPurifier-compatible custom block sanitization, image storage/path projection, database persistence, error side effects, and Super Admin authorization. Shared integration is limited to homepage-block HTML sanitization behavior and the `validation.regex` i18n catalog generator addition; product/article sanitization behavior remains on its existing code path.

## Verification

- `gofmt -w` on modified Go implementation/test files — completed.
- `cd go-backend && go test ./internal/api ./internal/cms ./internal/i18n` — PASS.
- `cd go-backend && go vet ./internal/api ./internal/cms ./internal/i18n` — PASS.
- `git diff --check` — PASS.
- `PYTHONPATH=tools/parity python3 tools/parity/compare_cms_homepage_block_store.py` — PASS, 1 grouped check / 0 failures; scenarios include required/type/content errors, scalar/null/empty cases, metadata coercion/defaults/date order, RBAC, DB state, and HTML sanitizer cases.
- `PYTHONPATH=tools/parity python3 tools/parity/compare_cms_homepage_block_uploads.py` — PASS, 18 passed / 0 failed, including stored file bytes, response/database state, authorization, validation, and cleanup/orphan behavior.
- `PYTHONPATH=tools/parity python3 tools/parity/compare_cms_homepage_block_reorder.py` — PASS, 1 grouped check / 0 failures; normal/partial/duplicate ordering, ID coercion, missing IDs, malformed shapes, RBAC, response, and persisted order compared.
- `PYTHONPATH=tools/parity python3 tools/parity/compare_cms_homepage_block_delete.py` — PASS, 1 grouped check / 0 failures; top-level and slider images, missing block IDs, denied role, row deletion, file deletion/retention, and responses compared.
- Differential PHP runtime limits remain `upload_max_filesize=2M`, `post_max_size=8M`; runtime testing above those caps is constrained, while the Go unit test still covers the 10 MiB application threshold.
- `pc_go_imp010` was restarted to load the changed Go source for differential tests. Laravel, MariaDB, and GLM containers were not restarted or changed. Harness cleanup removed only its own created rows/files.
- No production access or mutation; no push or deployment.

## Committed unit contents

- Commit `1705e00` contains homepage-block creation, focused Go tests, JSON and upload differential harnesses, i18n generator/catalog update, matrix generator update, and regenerated `docs/21a-IMP010-API-PARITY-MATRIX.md`.
- Commit `29fc383` contains homepage-block reorder validation/parity, its differential harness, generator update, and regenerated matrix.
- Commit `94bfea8` contains homepage-block deletion response/row/filesystem/RBAC differential coverage, generator update, and regenerated matrix.
- The pause/resume checkpoint is committed separately. No unrelated endpoint or GLM-owned route changed.

## Exact next action

Inspect Laravel's request/controller/service and database/filesystem behavior for `PATCH /api/v1/cms/homepage-blocks/{block}`, add a focused Laravel–Go differential harness for partial content/meta updates, image replacement/removal, slides and file cleanup, missing IDs, validation, response, and RBAC. Implement observed parity gaps only; run scoped Go tests/vet and the differential harness, regenerate the matrix only after it passes, review the diff, and commit the verified unit. Continue only through the remaining Codex-owned PARTIAL inventory; do not start non-HTTP migration, GLM integration, frontend work, deploy, or push.
