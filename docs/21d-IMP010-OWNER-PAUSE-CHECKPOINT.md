# IMP-010 Codex Continuation Checkpoint — 2026-11-20

**Execution status: ACTIVE — Codex-owned PARTIAL endpoints only.** Owner authorized resume from the prior pause checkpoint.

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
