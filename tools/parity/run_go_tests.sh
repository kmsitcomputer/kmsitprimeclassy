#!/usr/bin/env bash
# Provision the isolated test database and run the Go test suite (checkpoint B–C evidence).
#
# What it does, in order:
#   1. refuses to continue unless the target database name contains _test/_testing;
#   2. applies the Go service's own additive migrations (pc_go_sessions, go_migrations);
#   3. loads the synthetic fixtures (users/roles/catalog/CMS/regions) — invented data, never production;
#   4. runs `go vet` and the full Go test suite inside the Go container.
#
# It NEVER touches primeclassy_db (DEV) or any production database. The Laravel schema of the test database is
# created once with `php artisan migrate` (see docs/21 §Verification) so the tests run against the real schema
# rather than hand-written approximations.
#
# Usage: tools/parity/run_go_tests.sh [--no-seed]
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
GO_DIR="$ROOT/go-backend"
TEST_DB="${TEST_DB:-primeclassy_testing}"
MARIA_CONTAINER="${MARIA_CONTAINER:-primeclassy-mariadb}"
GO_IMAGE="${GO_IMAGE:-golang:1.27.4-alpine}"
NETWORK="${NETWORK:-primeclassy-net}"

case "$TEST_DB" in
  *_test|*_testing) ;;
  *) echo "refusing: TEST_DB=$TEST_DB must contain _test or _testing" >&2; exit 2;;
esac

echo "== test database: $TEST_DB"

if [[ "${1:-}" != "--no-seed" ]]; then
  echo "== Go migrations"
  docker run --rm --network "$NETWORK" -v "$GO_DIR:/src" -w /src \
    -e DB_DATABASE="$TEST_DB" "$GO_IMAGE" \
    sh -lc 'export PATH=$PATH:/usr/local/go/bin; go run ./cmd/migrate'

  echo "== fixtures"
  docker exec -i "$MARIA_CONTAINER" sh -lc \
    "mariadb -uprimeclassy_app -p\"\$(cat \$MARIADB_PASSWORD_FILE)\" $TEST_DB" \
    < "$ROOT/tools/parity/seed_test_fixtures.sql"
fi

echo "== go vet"
docker run --rm --network "$NETWORK" -v "$GO_DIR:/src" -w /src "$GO_IMAGE" \
  sh -lc 'export PATH=$PATH:/usr/local/go/bin; go vet ./...'

echo "== go test"
docker run --rm --network "$NETWORK" -v "$GO_DIR:/src" -w /src "$GO_IMAGE" \
  sh -lc 'export PATH=$PATH:/usr/local/go/bin; go test ./... -count=1'

echo "OK"
