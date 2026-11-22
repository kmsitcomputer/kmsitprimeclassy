# Go parity and test tooling

- `run_go_tests.sh` runs Go vet and the serialized Go suite against `primeclassy_testing` only.
- `gen_matrix.py` builds the canonical matrix from `backend/internal/api/parity_routes.json` and the Go route map.
- `laravel-routes.json` is a frozen historical route inventory. The 19 REM-11 Historical POS/report-registry routes
  remain excluded from the 375-route Go target.
- The `compare_*.py` Laravel differential harnesses were removed after their evidence was recorded in the matrix;
  they are preserved in Git history. Do not treat the historical notes as a live Laravel test dependency.
- `seed_test_fixtures.sql` and `seed_inventory_fixtures.sql` provide synthetic data for Go tests. They must only be
  used with a database whose name contains `_test` or `_testing`.

The active backend and frontend require no PHP or Composer runtime.
