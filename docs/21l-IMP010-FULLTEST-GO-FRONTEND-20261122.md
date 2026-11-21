# IMP-010 Go + Frontend Full Test Checkpoint — 2026-11-22

## Scope

Owner authorized the full Go backend suite and frontend verification/remediation. Laravel Full Test, production access, deployment, and remote push were excluded. Tests ran against the isolated `primeclassy_testing` database or frontend fixture APIs only.

## Repository state

- Branch: `imp010/go-backend`
- Starting test baseline recorded at session start: `3608390476eba0383e5ebf2586f6a62ece180a7b`
- The shared branch advanced during this run. Commits observed since that baseline, oldest first:
  - `dbb9b91 feat(imp-010): serve Laravel public disk at /storage + prod-snapshot integration report`
  - `b3497db chore: ignore local go build artifact go-backend/api`
  - `06551b5 docs: record local env grant fix in snapshot report`
- Verification/remediation commit: `e082178 test(imp-010): verify full Go and frontend suites`
- Canonical matrix regenerated with `python3 tools/parity/gen_matrix.py`: **375 GO / 0 PARTIAL / 0 Laravel-only**, 45/45 areas.
- `git diff --check`: passed before checkpoint creation.

## Changes made

- Made the Go test runner deterministic for a shared test DB: serial package execution, both API and inventory fixtures, explicit `APP_ENV=testing` / `DB_DATABASE`, and fixture-compatible session settings. Optional Go module/build caches are mounted when present.
- Made fixture seeding clear test-owned notifications, queued messages/jobs, and Go sessions before recreating user fixtures.
- Updated stale Go API assertions: invoice endpoint now expects anonymous 401, and CSRF mutation checks use the stateful first-party Origin.
- Remediated frontend lint/type issues found during the requested checks; fixed the order reschedule test selector so it targets the actual item's date input.
- Excluded generated Nuxt build/type output from ESLint so a production build does not make source lint fail.
- Made the SSR smoke harness accept `NUXT_URL`, allowing isolated testing on a port other than an already occupied default port.

## Verification evidence

| Check | Result |
|---|---|
| `bash -n tools/parity/run_go_tests.sh` | PASS |
| `bash tools/parity/run_go_tests.sh` | PASS; test DB guard confirmed `primeclassy_testing`; Go migration already applied; base and inventory fixtures seeded; `go vet ./...` passed; `go test -p 1 ./... -count=1` passed across all test-bearing packages |
| `npm run test -- --reporter=dot` | PASS; 56 files, 428 tests |
| `npm run type-check` | PASS |
| `npm run nuxt:typecheck` | PASS |
| `npm exec -- oxlint .` | PASS |
| `npm exec -- eslint .` | PASS; 0 errors, 1 existing warning for an unused eslint-disable in `src-pwa/sw.js:19` |
| `npm run build-only` | PASS; Vite + PWA build |
| `npm run nuxt:build` | PASS; Nuxt client, server and Nitro production bundle |
| SSR smoke (`NUXT_URL=http://127.0.0.1:3010 node nuxt-storefront/tests/e2e/smoke.mjs`) | PASS; 56 passed, 0 failed |
| SSR/i18n integration (`NUXT_URL=http://127.0.0.1:3010 node nuxt-storefront/tests/e2e/i18n-ssr.mjs`) | PASS; 37 passed, 0 failed, including 60 concurrent mixed-language requests |
| Browser E2E (Playwright/Chromium) | NOT RUN; Playwright packages/browser runtime are unavailable in the installed frontend dependencies |
| Laravel↔Go differential harnesses | NOT RUN in this mandate; PHP Full Test and Laravel execution were excluded. The generated matrix reports the committed parity inventory, not fresh differential evidence from this session. |

Expected non-failing output observed: Vue Router test warnings, missing translation fallback warnings, one ESLint warning noted above, and a Nuxt/Rollup annotation warning during production build.

## Safety and remaining limitations

- Only `primeclassy_testing` was migrated/seeded for Go tests; no production or DEV database was targeted.
- The temporary mock API, Nuxt preview process, and their owned host sessions were stopped after SSR checks. Existing Docker services/containers were not stopped or removed.
- No Laravel Full Test was run or resumed. No remote push or deployment occurred.
- This evidence does not prove browser hydration or fresh Laravel↔Go response/database-state parity. Those remain the next verification gaps because browser dependencies are absent and Laravel execution was out of scope.
- A failed first Go rerun exposed test-runner environment assumptions; the runner was corrected and the final run above passed. No application production source behavior was changed by that correction.

## Exact next action

This full Go/frontend testing mandate is complete and locally committed. Leave execution paused for the next Owner mandate; do not run Laravel Full Test, push, deploy, or stop existing project containers.
