# IMP-010 — Go-only Main Finalization Report

**Status: COMPLETE — active application is Go-only on `main`; GitHub cleanup verified.** Production was not touched.

## Scope and parity

- REM-11 Historical POS/report-registry remains excluded: 19 historical routes were not ported or tested.
- Canonical matrix regenerated from `backend/internal/api/parity_routes.json`: **375 routes, 375 GO, 0 PARTIAL, 0 Laravel-only, 45/45 areas**.
- The matrix's Laravel differential evidence was collected before Laravel runtime removal. No live Laravel-vs-Go differential was run after cleanup; the comparison harnesses are retired and preserved in Git history.
- Branch `change/gudang-order-fulfillment` was deleted without inspecting, merging or porting it, per Owner instruction.

## GitHub and local Git

- Repository: `/Users/kmsitcomputer/KMSIT-Server/apps/primeclassy-go`
- Remote: `https://github.com/kmsitcomputer/kmsitprimeclassy.git`
- Active branch: `main`
- Source consolidation commit: `a286404f932396371d6e1e41eea60304318622ea` (`chore(imp-010): consolidate local PrimeClassy on Go-only stack`). The final report is committed separately on `main`.
- `origin/HEAD` points to `main`; after report publication, the remote `main` tip was verified against local `main`.
- Local branches: **1 (`main`)**. Remote branches: **1 (`main`)**. The 14 previous non-main remote branches were deleted without force push.
- Active Git worktrees: **1**, at `/Users/kmsitcomputer/KMSIT-Server/apps/primeclassy-go`, branch `main`. The `apps/` directory contains no other PrimeClassy project root.
- Finalization began on `imp010/go-only-local` at `2d1053004a5482245cfe3f70e3a530439c59b98c`; it was committed and fast-forwarded to local `main`, then published as a fast-forward from remote `6a14041c06e6258c9f011fcb11271c7d34d65d17`.
- Current worktree was clean at the source consolidation commit. The final report commit follows it.

## Laravel source and local runtime cleanup

- Removed the active Laravel backend tree (`backend/app`, Laravel routes/bootstrap/config/resources/tests, Composer/PHP runtime files), the old `go-backend/` path after moving Go to `backend/`, Laravel deploy bridge/configuration, Laravel PHP test tooling, the PHP i18n generator, and the Laravel-only Sanctum browser test.
- Removed 126 Laravel differential `compare_*.py` scripts from active source after preserving their results in the matrix and their history in Git. `tools/parity/README.md` explains the remaining Go-only tooling and historical inventory.
- `find` over the active checkout found no `.php`, `artisan`, `composer.json` or `composer.lock` files. There is no PHP/Composer dependency in the Go or frontend runtime.
- The old Laravel worktree and the detached `imp010/go-backend` worktree were moved out of `apps/` into the external finalization backup directory. The Laravel worktree archive, dirty WIP patches, untracked-file archive, and all-ref Git bundle were verified before cleanup. Backups remain under `/Users/kmsitcomputer/KMSIT-Server/backups/primeclassy-go-consolidation-20261122`.
- The pre-cleanup all-ref Git bundle SHA256 is `f3d78d8b182853bab19e41323563aecfa7194d12dad005d49372a50f2ea253c9`. The dirty Laravel tree archive SHA256 is `ef18e52a6f78f587d23ae8fd243e1f9d610625c55b788e4ef71feaad45b5ada0`; the latest detached Go worktree WIP patch SHA256 is `c1d6c9aaed68d586e48734169a255e71629eaf8e59d9b066a632701cc03f0cf9`.
- Removed Laravel containers: `primeclassy-fpm`, `primeclassy-web`, seven exited `pc_laravel_imp010*` containers, and obsolete Go parity/test containers (`pc_go_local`, `imp010-go-*`, `pc_go_imp010`). Removed image: `primeclassy-test-php:8.3-py`.
- Retained Go API, Vue SPA, Nuxt, shared MariaDB, Caddy, and database volume. Generic shared PHP images remain because they are not PrimeClassy-specific. Empty network `pcnet` remains because it has no containers/labels and exclusive ownership could not be established. No Laravel-specific volume was deleted.

## Tests and runtime verification

- `bash tools/parity/run_go_tests.sh` — **PASS**; `go vet ./...` and `go test -p 1 ./... -count=1` passed on `primeclassy_testing`.
- `cd backend && go build ./...` — **PASS**. `docker compose up --build --force-recreate -d api spa nuxt` — **PASS**.
- Frontend Vitest — **56 files / 435 tests PASS** (`--maxWorkers=2`).
- `npm run type-check`, `npm run build-only`, `npm run nuxt:typecheck`, `npm run nuxt:build` — **PASS**.
- Oxlint — **0 warnings / 0 errors**. ESLint — **0 errors, 1 warning** for the existing unused disable in `frontend/src-pwa/sw.js:19`.
- Built Nuxt SSR smoke — **56/56 PASS**; multilingual SSR/i18n — **37/37 PASS**; proxy routing and rollback drill — **42/42 PASS**, all against the mock Go API.
- Local domain `http://primeclassy-go-local.kmsit.localhost`: `/` 200, `/products` 200, `/api/v1/install/status` 200, `/up` 200, anonymous `/api/v1/auth/me` 401.
- Browser E2E was not run; Playwright/Chromium was unavailable. No claim of Browser E2E pass is made.
- `docker compose config --quiet`, canonical matrix generation and `git diff --check` — **PASS**.

## Database, storage and production

- Go tests used only `primeclassy_testing`; they did not target `primeclassy_go_local`.
- The imported `primeclassy_go_local` dump fingerprint changed from pre-cleanup `68c82b2fa12bf250a551d94b222f5ee57dc21dd391343b3476c6ab54c07709e2` to final `9190e45c16dde01f64ec84cbca79ada096460675977aef184b5c3136dacfacca` during local Go runtime verification. Row-level comparison against the preserved import found **no removed rows**: 11 activity-log rows, one Go migration record and five Go session rows were added; all 79 `catalog_skus` rows matched. The final fingerprint is stored outside the repo at `database/primeclassy-go-local-finalization-current.sha256` in the backup directory.
- Storage root remains `storage/`. **113/113 files** matched the preserved checksum manifest; the original archive SHA256 is `bc249c2db1d00cfbd8e55744e82f39c512016551e5100fc85c752dd5b2556cad`.
- No production VPS, production database/storage, DNS or deployment was accessed or changed.

## Remaining limits

- Browser E2E was unavailable; see the test evidence above.
- Differential evidence is historical, recorded before removal of the Laravel runtime. Re-running those exact harnesses requires the archived Laravel baseline and is outside the Go-only active tree.
- `pcnet` and generic PHP images were retained as shared/ambiguous infrastructure to avoid disrupting other KMSIT projects.

**Verdict: `PRIMECLASSY GO-ONLY MAIN CONSOLIDATION PASS`** — active source/runtime and GitHub `main` are Go-only; the 19 REM-11 routes remain excluded. Current exact `main` HEAD is also reported in the completion message because this report is committed as its own final documentation commit.
