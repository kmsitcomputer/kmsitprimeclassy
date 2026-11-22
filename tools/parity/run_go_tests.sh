#!/usr/bin/env bash
# Provision the isolated test database and run the Go test suite (checkpoint B–C evidence).
#
# What it does, in order:
#   1. refuses to continue unless the target database name contains _test/_testing;
#   2. applies the Go service's own additive migrations (pc_go_sessions, go_migrations);
#   3. loads the synthetic fixtures (users/roles/catalog/CMS/regions) — invented data, never production;
#   4. runs `go vet` and the full Go test suite inside the Go container.
#
# It NEVER touches primeclassy_db (DEV) or any production database. The isolated test database retains the
# imported PrimeClassy schema so tests exercise real constraints rather than hand-written approximations.
#
# Usage: tools/parity/run_go_tests.sh [--no-seed]
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
GO_DIR="$ROOT/backend"
TEST_DB="${TEST_DB:-primeclassy_testing}"
MARIA_CONTAINER="${MARIA_CONTAINER:-primeclassy-mariadb}"
GO_IMAGE="${GO_IMAGE:-golang:1.27.4-alpine}"
NETWORK="${NETWORK:-primeclassy-net}"
GO_MOD_CACHE="${GO_MOD_CACHE:-/tmp/primeclassy-go-modcache}"
GO_BUILD_CACHE="${GO_BUILD_CACHE:-/tmp/primeclassy-go-buildcache}"
DOCKER_CACHE_ARGS=()
if [[ -d "$GO_MOD_CACHE" ]]; then
  DOCKER_CACHE_ARGS+=(-v "$GO_MOD_CACHE:/go/pkg/mod")
fi
if [[ -d "$GO_BUILD_CACHE" ]]; then
  DOCKER_CACHE_ARGS+=(-v "$GO_BUILD_CACHE:/root/.cache/go-build")
fi

case "$TEST_DB" in
  *_test|*_testing) ;;
  *) echo "refusing: TEST_DB=$TEST_DB must contain _test or _testing" >&2; exit 2;;
esac

echo "== test database: $TEST_DB"

if [[ "${1:-}" != "--no-seed" ]]; then
  echo "== Go migrations"
  docker run --rm --network "$NETWORK" -v "$GO_DIR:/src" -w /src "${DOCKER_CACHE_ARGS[@]}" \
    -e APP_ENV=testing -e DB_DATABASE="$TEST_DB" "$GO_IMAGE" \
    sh -lc 'export PATH=$PATH:/usr/local/go/bin; go run ./cmd/migrate'

  echo "== fixtures"
  docker exec -i "$MARIA_CONTAINER" sh -lc \
    "mariadb -uprimeclassy_app -p\"\$(cat \$MARIADB_PASSWORD_FILE)\" $TEST_DB" \
    < "$ROOT/tools/parity/seed_test_fixtures.sql"
  docker exec -i "$MARIA_CONTAINER" sh -lc \
    "mariadb -uprimeclassy_app -p\"\$(cat \$MARIADB_PASSWORD_FILE)\" $TEST_DB" \
    < "$ROOT/tools/parity/seed_inventory_fixtures.sql"
fi

echo "== go vet"
docker run --rm --network "$NETWORK" -v "$GO_DIR:/src" -w /src "${DOCKER_CACHE_ARGS[@]}" \
  -e APP_ENV=testing -e DB_DATABASE="$TEST_DB" -e SESSION_COOKIE=pc_sid \
  -e SESSION_SECURE_COOKIE=false -e SANCTUM_STATEFUL_DOMAINS=127.0.0.1:8899 "$GO_IMAGE" \
  sh -lc 'export PATH=$PATH:/usr/local/go/bin; go vet ./...'

echo "== go test"
docker run --rm --network "$NETWORK" -v "$GO_DIR:/src" -w /src "${DOCKER_CACHE_ARGS[@]}" \
  -e APP_ENV=testing -e DB_DATABASE="$TEST_DB" -e SESSION_COOKIE=pc_sid \
  -e SESSION_SECURE_COOKIE=false -e SANCTUM_STATEFUL_DOMAINS=127.0.0.1:8899 "$GO_IMAGE" \
  sh -lc 'export PATH=$PATH:/usr/local/go/bin; go test -p 1 ./... -count=1'

echo "OK"
