#!/usr/bin/env python3
"""GLM differential harness — INSTALLER WIZARD (7 Laravel-only routes).

  GET/POST /install/requirements|database/test|database/save|configure-app|run|finalize|lock

The testing DB already has a super_admin, so the write steps are refused exactly like
the reference's defence in depth. The lock flow is exercised end-to-end and the lock
files are removed afterwards (Laravel: storage/framework/testing/installed.lock,
Go: storage/app/installed.lock) so the isolated env returns to its prior state.

Environment-specific values (php.version, storage_linked) are scrubbed.

Usage: python3 tools/parity/compare_install.py
"""
import json
import re
import subprocess
import sys

import compare_fulfillment as f
from compare_fulfillment import LARAVEL, GO, norm_body

LARAVEL_LOCK = "/app/storage/framework/testing/installed.lock"
GO_LOCK = "/src/storage/app/installed.lock"


def rm(container, path):
    subprocess.run(["docker", "exec", container, "rm", "-f", path], capture_output=True)


def has(container, path):
    r = subprocess.run(["docker", "exec", container, "test", "-f", path], capture_output=True)
    return r.returncode == 0


def scenario(base):
    out = {}
    c = f.Client(base)  # unauthenticated — installer is pre-auth by design
    out["requirements"] = c.call("GET", "/api/v1/install/requirements")

    # database/test — real connection attempt (correct creds then wrong creds)
    dbpwd = f.db("SELECT 1")  # warm
    creds = {"host": "primeclassy-mariadb", "port": 3306, "database": "primeclassy_testing",
             "username": "primeclassy_app", "password": "wrong-password"}
    out["db_test_bad"] = c.call("POST", "/api/v1/install/database/test", creds)
    good = dict(creds)
    # the real password: read from the Laravel container env file
    r = subprocess.run(["docker", "exec", "pc_laravel_imp010", "sh", "-lc",
                        "grep '^DB_PASSWORD=' /app/.env.testing | cut -d= -f2"], capture_output=True, text=True)
    good["password"] = r.stdout.strip()
    out["db_test_ok"] = c.call("POST", "/api/v1/install/database/test", good)
    out["db_test_missing_fields"] = c.call("POST", "/api/v1/install/database/test", {"host": "x"})

    # database/save — super_admin exists → 403 already installed
    out["db_save"] = c.call("POST", "/api/v1/install/database/save", good)
    # configure-app — same gate
    out["configure_app"] = c.call("POST", "/api/v1/install/configure-app",
                                  {"app_name": "PrimeClassy", "app_url": "http://127.0.0.1:8899",
                                   "frontend_url": "http://127.0.0.1:8899"})
    # run — super_admin exists → 422 admin already exists
    out["run"] = c.call("POST", "/api/v1/install/run",
                        {"name": "Admin", "email": "admin2@imp010.test", "phone": "0811",
                         "password": "Secret123!", "password_confirmation": "Secret123!"})
    # finalize — summary
    out["finalize"] = c.call("POST", "/api/v1/install/finalize")
    # lock — writes the lock file, then everything is 403; removed afterwards.
    out["lock"] = c.call("POST", "/api/v1/install/lock")
    lock_container = "pc_laravel_imp010" if base == LARAVEL else "pc_go_imp010"
    lock_path = LARAVEL_LOCK if base == LARAVEL else GO_LOCK
    out["lock_file_exists"] = has(lock_container, lock_path)
    out["after_lock_requirements"] = c.call("GET", "/api/v1/install/requirements")
    rm(lock_container, lock_path)
    out["restored_requirements"] = c.call("GET", "/api/v1/install/requirements")
    return out


def scrub(v):
    if isinstance(v, tuple):
        return [scrub(x) for x in v]
    if isinstance(v, list):
        return sorted((scrub(x) for x in v), key=lambda d: json.dumps(d, sort_keys=True)) if v and all(isinstance(i, dict) for i in v) else [scrub(x) for x in v]
    if isinstance(v, dict):
        # php.version + storage_linked are environment-specific (PHP version string,
        # symlink state); the contract (keys, labels, ok flags, minimum) is compared.
        if set(v.keys()) == {"version", "ok", "minimum"}:
            v = dict(v)
            v["version"] = "<ver>"
        return {k: ("<linked>" if k == "storage_linked" else scrub(x))
                for k, x in v.items()}
    return v


def main():
    rm("pc_laravel_imp010", LARAVEL_LOCK)
    rm("pc_go_imp010", GO_LOCK)
    lar = scenario(LARAVEL)
    rm("pc_laravel_imp010", LARAVEL_LOCK)
    rm("pc_go_imp010", GO_LOCK)
    go = scenario(GO)
    rm("pc_laravel_imp010", LARAVEL_LOCK)
    rm("pc_go_imp010", GO_LOCK)
    failed = 0
    total = 0
    for key in sorted(lar):
        total += 1
        lv, gv = scrub(norm_body(lar[key])), scrub(norm_body(go[key]))
        ok = lv == gv
        if not ok:
            failed += 1
        print(("PASS " if ok else "FAIL ") + key)
        if not ok:
            print("  L=" + json.dumps(lv, default=str)[:600])
            print("  G=" + json.dumps(gv, default=str)[:600])
    print(f"install: {total - failed} passed, {failed} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
