# IMP-010 Go-only Main Finalization — Interim Checkpoint

**Status: IN PROGRESS — NOT ACCEPTED.** This file records the current finalization attempt; it does not claim a Go-only or single-main result.

## Current Git state

- Repository: `/Users/kmsitcomputer/KMSIT-Server/apps/primeclassy-go`
- Remote: `origin https://github.com/kmsitcomputer/kmsitprimeclassy.git`
- Branch: `imp010/go-only-local`
- HEAD: `2d1053004a5482245cfe3f70e3a530439c59b98c`
- Local/remote main: local `main` at `d7997c4`; `origin/main` at `6a14041`. Current Go HEAD is 236 commits ahead of the remote main ancestor.
- Working tree has uncommitted Go-only runtime, compose, frontend, and Historical POS UI changes. No implementation commit or push was made in this attempt.
- Inventory at this check: 11 worktrees and 42 local/remote branch refs. No branch or worktree was deleted.

## Application and data

- Go API, Vue SPA, Nuxt storefront, and the local Caddy site are running on `http://primeclassy-go-local.kmsit.localhost`.
- Current HTTP checks: `/` 200, `/products` 200, `/api/v1/install/status` 200, `/up` 200, unauthenticated `/api/v1/auth/me` 401.
- Compose configuration validation passed. Caddy routes API and public media to Go, public pages to Nuxt, and SPA pages to the Vue static service.
- Database target is `primeclassy_go_local`; storage root is the project `storage/`. No production data was changed.
- Prior verified backups remain outside the repository at `/Users/kmsitcomputer/KMSIT-Server/backups/primeclassy-go-consolidation-20261122`: database dump SHA256 `45a5a7a6359909e82e1ad58c62608dfebd1a8210cbedd634b794eb8f39d641e0`, storage archive SHA256 `bc249c2db1d00cfbd8e55744e82f39c512016551e5100fc85c752dd5b2556cad`, dirty Laravel worktree archive SHA256 `1839e65a04315e680f0283fd7ffa6a3de95d367de12292b7fd4817d5fadae6a6`. Database and storage remain untouched by finalization.

## Parity and blockers

- Go's checked-in `go-backend/internal/api/parity_routes.json` contains 375 routes.
- Read-only `php artisan route:list --json --path=api/v1` against active Laravel source produced 394 entries. Owner clarification: the 19 REM-11 Historical POS / report-registry routes are intentionally excluded from the Go migration. Thus 394 source routes minus those 19 excluded routes equals the 375-route Go target; their absence in Go is expected, not a parity defect.
- The Historical POS UI from the dirty Laravel worktree was copied into this worktree and its SPA-only routes were wired before this scope clarification. Its routes and menu entry have now been removed from the active frontend; all 26 copied files are preserved at `/Users/kmsitcomputer/KMSIT-Server/backups/primeclassy-go-consolidation-20261122/source/historical-pos-ui-wip`.
- `origin/change/gudang-order-fulfillment` has 15 unique commits. Its routes include grouped delivery invoices/receipts and fulfillment change proposals. They must be compared against the agreed current Laravel source and Go implementation before deciding if any are part of the 375-route target; unique Git history alone does not prove an outstanding API gap.
- The active Laravel worktree (`apps/primeclassy`) has 234 dirty paths and has been preserved in the verified external archive. The GLM worktree has two dirty files and the release worktree has one; neither was modified. Other worktrees were not removed.
- The 375-entry inventory is consistent with the agreed exclusion count but still needs regeneration from the repository's canonical generator during final acceptance.
- No differential comparison was run for the grouped fulfillment branch delta. Historical POS differential tests are out of scope per Owner clarification.

## Test evidence

- `bash tools/parity/run_go_tests.sh`: PASS; `go vet ./...` and `go test -p 1 ./... -count=1` passed against `primeclassy_testing`. Go migrations reported the existing migration applied; fixtures were loaded into that isolated test DB.
- `go build ./...` in `golang:1.27.4-alpine`: PASS.
- Frontend full Vitest in isolated Node 22 container, bounded worker count: **66 files / 542 tests PASS**. An initial unbounded run hit 63 worker-start timeouts; rerun with `--maxWorkers=2` passed.
- `npm run type-check`: PASS.
- `npm run build-only`: PASS (Vite build and PWA service worker generation).
- `npm run nuxt:typecheck`: PASS.
- `npm run nuxt:build`: PASS.
- Oxlint: PASS, 0 warnings / 0 errors.
- ESLint: 0 errors, 1 warning at `frontend/src-pwa/sw.js:19` for an unused `eslint-disable` directive.
- Focused ProductForm tests after the latest small edit: 2 files / 8 tests PASS.
- After honoring the Historical POS exclusion: SPA/Nuxt route boundary test 1 file / 8 tests PASS and Vue type-check PASS.
- `git diff --check`: PASS.
- Prior checkpoint evidence: Nuxt SSR smoke 56/56 and SSR/i18n 37/37 passed against the mock API. Browser E2E remains unavailable because Playwright/Chromium was not installed.
- Full Laravel test suite was not run. No production deploy, remote push, Laravel removal, or GitHub branch cleanup occurred.

## Worktree and backup notes

- The original dirty Laravel worktree archive and database/storage backups were verified before use; active source worktrees were left intact.
- A frontend dependency installation attempt temporarily replaced the target worktree's `frontend/node_modules` symlink with generated local dependencies. That generated directory was preserved at `/tmp/primeclassy-go-generated-node-modules-20261122`, and the original symlink to `apps/primeclassy-imp010-go/frontend/node_modules` was restored. No source WIP was removed.
- Compose test containers were ephemeral. PrimeClassy Go, Laravel, shared database, Caddy, and other project containers were not stopped or removed.

## Exact next action

1. Keep the 19 Historical POS/report-registry routes excluded from Go API parity; their copied frontend WIP has been removed from active routes/menu and preserved outside the repository.
2. Compare `origin/change/gudang-order-fulfillment` against the current in-scope Laravel routes and Go handlers. Port only behavior proven to belong to the agreed 375-route target; record evidence for behavior already equivalent.
3. Regenerate the canonical route matrix with the repository generator and confirm that the only 19-route delta from Laravel is the Owner-excluded Historical POS/report-registry set.
4. Run route-scoped Go-Laravel differential checks for any required fulfillment changes, rerun the Go/frontend gates, and review the complete diff.
5. Once in-scope parity and unique required history are reconciled, integrate on local `main`, run Go-only independence checks, remove Laravel source/runtime and local Git refs/worktrees, push `main`, and clean remote branches. Preserve dirty/shared worktrees until their WIP is reconciled.

## Final verdict

**NOT PASS — finalization is still in progress.** The 19 Historical POS routes are Owner-excluded and are not a blocker; the out-of-scope UI has been removed from active navigation/routes and preserved externally. Remaining work: reconcile unique fulfillment history, regenerate the canonical matrix, and complete main/runtime/GitHub finalization. Accurate status: **LOCAL GO STACK RUNNING — MAIN CONSOLIDATION PENDING**.
