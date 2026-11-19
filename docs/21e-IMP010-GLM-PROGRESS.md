# 21e — IMP-010 GLM Laravel-only progress (GLM branch — NOT the canonical matrix)

Branch `imp010/glm-laravel-only`, worktree `/Users/kmsitcomputer/KMSIT-Server/apps/primeclassy-imp010-glm`,
baseline `imp010/go-backend@94c07b7`. This file is GLM-owned progress evidence; the canonical matrix
(`docs/21a`) is updated only by Codex at integration.

## Isolated environment (Owner mandate §9)

| Component | Value |
|---|---|
| Laravel reference container | `pc_laravel_glm`, port 8898, mounts GLM worktree `backend/` + shared vendor (read) |
| Go container | `pc_go_glm`, port 8083, mounts GLM worktree `go-backend/` |
| Test database | `primeclassy_testing_glm` (shared MariaDB `primeclassy-mariadb`; additive DB, separate from Codex's `primeclassy_testing`) |
| Harnesses | `tools/parity-glm/` (ports 8898/8083, DB `primeclassy_testing_glm`) |
| Migration | Laravel `php artisan migrate` + Go `cmd/migrate` + `seed_test_fixtures.sql` + `seed_inventory_fixtures.sql` applied |
| Codex env | untouched: `pc_go_imp010`, `pc_laravel_imp010`, `primeclassy_testing`, its worktree, and its uncommitted files were never modified |

## Domains completed

### Operator notification centre — 4 Laravel-only routes (AdminNotificationController) — commit 4d9a2f7

| Route | Status |
|---|---|
| GET `/api/v1/notifications/admin` | **GO (GLM)** |
| GET `/api/v1/notifications/admin/unread-count` | **GO (GLM)** |
| POST `/api/v1/notifications/admin/read-all` | **GO (GLM)** |
| POST `/api/v1/notifications/admin/{id}/read` | **GO (GLM)** |

Evidence: `tools/parity-glm/compare_notifications_admin.py` — **97 passed, 0 failed** (RBAC matrix 4 roles × 4
routes + unauth 401, index projection/pagination/unread filter, validation byte-parity incl. strict `boolean`
rule and `getSize` between semantics, read-all/idempotent mark-read, foreign-recipient 404, non-numeric id
404, DB read_at state). Scoped `go test ./internal/api ./internal/i18n` green, `go vet` clean, gofmt clean.

Verified Laravel behaviours worth knowing for integration:
- `boolean` rule is strict (`is_bool || in [0,1,'0','1']`) — the string "true" is a 422.
- Query keys are validated whenever present, including empty values (`?per_page` → 422 with BOTH integer and
  between messages); ConvertEmptyStringsToNull does not shield GET input.
- `between:1,50` uses getSize(): numeric → numeric compare, else strlen.
- `toIso8601String()` renders `Y-m-d\TH:i:sP` (`2026-...+00:00`, second precision).
- The route-not-found fallback (non-numeric `{id}` with `whereNumber`) renders BEFORE the api middleware
  group, so its message is the app default locale (`en`, "Endpoint not found.") — unlike in-group localized
  404s. Recorded here, NOT in docs/21b (that register stays Codex-owned).

## Shared-file changes for Codex integration (minimal, §5)

| File | Diff | Reason |
|---|---|---|
| `go-backend/internal/api/server.go` | +1 line: `s.registerGLMLaravelOnlyRoutes()` after `registerDomainERoutes()` | route registration entry point |
| `go-backend/internal/api/inventory.go` | 1 line: `|| matchesGLMLaravelOnlyRoute(method, path)` in `implementsRoute` | 501-vs-404 decision and `/_parity` report must include the GLM slice |
| `go-backend/internal/notifications/notifications.go` | +additive `TableReady(ctx)` method | ports `AdminNotification::tableReady()` for the `migration_pending` meta path |

New GLM-owned files: `go-backend/internal/api/glm_laravel_only_routes.go`, `tools/parity-glm/compare_notifications_admin.py`.

## Remaining GLM inventory

176 Laravel-only endpoints (see `tools/parity-glm/laravel_only_inventory.tsv`, committed as the baseline
inventory). Candidate next domains by
independence: Reporting reads (16), admin agents CRUD (5), audit-logs (1), regions import/export (2).

## EXACT NEXT ACTION

Implement the **admin agents CRUD** domain (5 Laravel-only routes: GET/POST `/admin/agents`,
PATCH/DELETE `/admin/agents/{agentProfile}`, PATCH `/admin/agents/{agent}/toggle-status`): read the Laravel
AgentAdminController/policies, port with the GLM slice pattern, add `tools/parity-glm/compare_admin_agents.py`,
run against the GLM isolated environment, commit. Then continue with Reporting reads.

### Generic media — 3 Laravel-only routes (MediaController) — commit <this>

| Route | Status |
|---|---|
| GET `/api/v1/media` | **GO (GLM)** |
| POST `/api/v1/media` | **GO (GLM)** |
| DELETE `/api/v1/media/{media}` | **GO (GLM)** |

Evidence: `tools/parity-glm/compare_media.py` — **73 passed, 0 failed** (index RBAC incl. the verified
super_admin-only route gate, projection/filter/pagination, accumulated store validation, policy-after-validation
ordering, `user_avatar` open-collection policy for upload AND owner-only delete, foreign 403, unknown id →
localized 404, uuid path/url shape, DB state). `internal/media` (pre-existing port of MediaService) is wired via
new handlers in the GLM slice; scoped `go test ./internal/api ./internal/media ./internal/i18n` green, `go vet`
clean, gofmt clean, `git diff --check` clean.

Verified Laravel behaviours worth knowing for integration:
- GET `/media` route gate is **super_admin only** (inside the super_admin CMS group) — narrower than
  MediaPolicy, so an AGEN is 403 on index even though the policy itself allows it. POST/DELETE are
  auth-only at the route level (policy per collection).
- Store validation runs BEFORE MediaPolicy::create (FormRequest authorize() is true) — an unauthorized role
  with an invalid payload gets 422, with a valid payload 403 `system.unauthorized_action`.
- `user_avatar`: every authenticated role may upload; only the OWNER may delete; other collections are
  super_admin/agen for both.
- Missing media id → route-model binding → localized `system.not_found` 404 (in-group, not the en default).
- Shared-file changes for THIS unit: `cmd/api/main.go` (+import, `media.SetRoot(cfg.MediaRoot)`),
  `internal/config/config.go` (+`MediaRoot`), `tools/parity/gen_i18n.py` (+`media.uploaded`/`media.deleted`
  keys → catalog.json regenerated, 822 messages), `go-backend/.env` APP_URL aligned to the harness origin
  (local-only, gitignored).
