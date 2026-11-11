"""Entry point. Stage A commands (static, no processes, no DB): manifest-generate, manifest-verify, static-validate, plan, aggregate, provision plan.
Stage B commands (OWNER ONLY, explicit gates): run, provision create|cleanup."""
from __future__ import annotations

import argparse
import os
import re
import shutil
import subprocess
import sys
import uuid
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
sys.dont_write_bytecode = True

from pctest import guards  # noqa: E402
from pctest.common import (PREPARE_PHRASE, CLEANUP_PHRASE, PROVISION_PHRASE, ROOT, STAGE_B_PHRASE, dump_json, load_json, read_dotenv, redact_env, incomplete_runs, dependency_tree_digest, dependency_lock)  # noqa: E402
from pctest.manifest import build_manifest, default_configs, verify_manifest  # noqa: E402
from pctest.plan import build_plan, db_connection_defaults, render_phpunit_config  # noqa: F401  # noqa: E402


def die(message: str, code: int = 2):
    """Refusals go to stderr with the documented exit code (2 = refused, nothing started; see config/scheduler.json exit_codes)."""
    print(message, file=sys.stderr)
    sys.exit(code)


def _repo(a) -> Path:
    r = Path(a.repo).resolve()
    if not (r / "backend/phpunit.xml").is_file() or not (r / "frontend/vitest.config.ts").is_file():
        die(f"REFUSED: {r} is not a PrimeClassy checkout (backend/phpunit.xml or frontend/vitest.config.ts missing)")
    return r


def cmd_manifest_generate(a):
    sched, rules = default_configs()
    m = build_manifest(_repo(a), a.workers, Path(a.timing_file) if a.timing_file else None, sched, rules)
    out = Path(a.out)
    if out.exists() and not a.overwrite_manifest:
        die(f"REFUSED: {out} exists (pass --overwrite-manifest to replace a manifest you own)")
    dump_json(out, m)
    print(f"manifest written: {out}\ndigest: {m['digest']}\ncounts: {m['counts']}\nwarnings: {len(m['warnings'])}")
    for w in m["warnings"]:
        print("  WARN", w)


def cmd_manifest_verify(a):
    sched, rules = default_configs()
    diffs = verify_manifest(load_json(a.manifest), _repo(a), sched, rules)
    for d in diffs:
        print("MISMATCH", d)
    print("INVENTORY MATCHES MANIFEST" if not diffs else f"INVENTORY MISMATCH ({len(diffs)})")
    sys.exit(0 if not diffs else 1)


def _make_plan(a, run_id="DRYRUN", out_dir=None):
    sched, rules = default_configs()
    repo = _repo(a)
    m = load_json(a.manifest)
    if a.workers and a.workers != m["workers"]:
        die(f"REFUSED: --workers {a.workers} != manifest workers {m['workers']}; regenerate the manifest (assignment is baked into it)")
    if m["workers"] > sched["max_workers_allowed"] or m["workers"] > 5:
        die("REFUSED: worker count above the allowlisted maximum (TestDatabaseGuard::WORKER_DATABASES has w1..w5)")
    out = Path(out_dir or a.out_dir).resolve()
    return sched, rules, repo, m, build_plan(m, repo, out, sched, db_connection_defaults(repo, sched), run_id)


def cmd_plan(a):
    """Stage A dry run: prints the schedule; writes nothing, starts nothing."""
    sched, rules, repo, m, plan = _make_plan(a)
    print(f"manifest {m['digest'][:16]}  workers={m['workers']}  serial lane runs AFTER parallel phase on {guards.CANONICAL_DB}")
    for w in plan["workers"]:
        print(f"  worker {w['index']}: db={w['db']}  php_files={len(w['php_files'])}  frontend_files={len(w['frontend_files'])}  est={m['estimated_parallel_load_seconds'][str(w['index'])]}s")
        errs = guards.check_worker_env(w["env"], "parallel", tuple(sched["allowed_db_hosts"]))
        for e in errs:
            print("     GUARD", e.code, e.message)
    print(f"  serial lane: {len(plan['serial']['php_files'])} files, est={m['estimated_serial_lane_seconds']}s")
    print("assignment problems:", plan["assignment_problems"] or "none")
    print("adapters:", plan["adapters"], " path map:", plan["path_map"])
    print("worker-1 argv:", " ".join(next((j["argv"] for j in plan["jobs"] if j["id"] == "worker-1-phpunit"), [])[:12]), "...")
    print("worker-1 env (redacted):", redact_env(plan["workers"][0]["env"]))


def cmd_aggregate(a):
    from pctest.aggregate import aggregate, exit_code_for
    run = Path(a.run_dir)
    s = aggregate(run, load_json(a.manifest), load_json(run / "plan.json"))
    print("VERDICT:", s["verdict"])
    sys.exit(exit_code_for(s["verdict"]))


def cmd_static_validate(a):
    ok, lines = True, []

    def rec(good, msg):
        nonlocal ok
        ok &= bool(good)
        lines.append(("PASS  " if good else "FAIL  ") + msg)

    for p in sorted(ROOT.rglob("*.py")):
        try:
            compile(p.read_text(), str(p), "exec")
            rec(True, f"syntax {p.relative_to(ROOT)}")
        except SyntaxError as e:
            rec(False, f"syntax {p.relative_to(ROOT)}: {e}")
    for p in sorted(ROOT.rglob("*.json")):
        try:
            load_json(p)
            rec(True, f"json {p.relative_to(ROOT)}")
        except Exception as e:
            rec(False, f"json {p.relative_to(ROOT)}: {e}")
    tpl = (ROOT / "env/worker.env.template").read_text()
    rec(set(re.findall(r"\{(\w+)\}", tpl)) <= {"N", "DB_NAME", "DB_HOST", "DB_PORT", "DB_USERNAME", "WORKER_DIR", "RUN_ID", "RUN_UUID"}, "env template placeholders known")
    rec(not [l for l in tpl.splitlines() if not l.lstrip().startswith("#") and re.search(r"PASSWORD|SECRET|TOKEN", l, re.I)], "env template contains no password/secret keys")
    # guard self-checks with synthetic values (no DB, no I/O)
    refuse = ["primeclassy_db", "primeclassy", "primeclassy_dev", "primeclassy_testing", "primeclassy_testing_w10", "primeclassy_testing_w0",
              "prod_testing_w1", "primeclassy_testing_w1; DROP", ""]
    for n in refuse:
        rec(any(x.level == "error" for x in guards.check_database_name(n, "parallel")), f"guard refuses parallel db {n!r}")
    rec(not guards.check_database_name("primeclassy_testing_w3", "parallel"), "guard accepts primeclassy_testing_w3 (parallel)")
    rec(not guards.check_database_name("primeclassy_testing", "serial"), "guard accepts canonical db only in serial lane")
    rec(guards.check_database_name("primeclassy_testing_w1", "serial"), "guard refuses worker db in serial lane")
    for h in ("db.example.com", "10.0.0.5", "prod-mysql", ""):
        rec(guards.check_host(h, "3306"), f"guard refuses host {h!r}")
    rec(not guards.check_host("127.0.0.1", "3306"), "guard accepts 127.0.0.1")
    rec(not guards.check_host("primeclassy-mariadb", "3306"), "guard accepts primeclassy-mariadb (DEV compose service)")
    app_guard = (_repo(a) / "backend/tests/Support/TestDatabaseGuard.php").read_text()
    sc, _r = default_configs()
    rec(all(f"'{k}' => 'primeclassy_testing_w{k}'" in app_guard for k in "12345"), "app TestDatabaseGuard lists exactly w1..w5 explicitly")
    rec("$database !== $expected" in app_guard and "self::WORKER_DATABASES[$worker]" in app_guard, "database allowlist uses exact comparison and explicit worker map")
    rec("ConfigurationUrlParser" in app_guard and "URL_ENV_VARIABLES" in app_guard and "unix_socket" in app_guard, "app TestDatabaseGuard resolves the EFFECTIVE config (URL parser) and refuses URL/socket overrides")
    lock_src = (_repo(a) / "backend/tests/Support/TestRunLock.php").read_text()
    rec("$config = TestDatabaseGuard::assertApplicationSafe($app);" in lock_src, "app TestRunLock locks the guard-validated effective target")
    tpl2 = (ROOT / "env/worker.env.template").read_text()
    rec(all(k + "=" in tpl2 for k in ("DB_URL", "DATABASE_URL", "DB_SOCKET", "APP_SERVICES_CACHE", "APP_PACKAGES_CACHE", "APP_CONFIG_CACHE", "APP_ROUTES_CACHE", "APP_EVENTS_CACHE")), "env template blanks connection overrides and isolates bootstrap caches")
    rb = (ROOT / "docs/OWNER-RUNBOOK.md").read_text()
    rec(all(f"`{c}`" in rb or f"{c} " in rb for c in sc["exit_codes"] if c != "130") and "4" in sc["exit_codes"], "runbook documents the exit-code contract of config/scheduler.json")
    rec(set(sc["allowed_db_hosts"]) == set(re.findall(r"ALLOWED_HOSTS = \[([^\]]*)\]", app_guard)[0].replace("'", "").replace(" ", "").split(",")), "runner host allowlist == app guard ALLOWED_HOSTS")
    gate = (ROOT / "pctest/cli.py").read_text()
    rec("STAGE_B_PHRASE" in gate and "--i-am-the-owner" in gate, "Stage B gate present in cli.py")
    rec(all("runner" not in l for l in (ROOT / "pctest/manifest.py").read_text().splitlines() if l.startswith(("import", "from"))), "manifest.py does not import runner (Stage A cannot spawn)")
    if a.manifest:
        sched, rules = default_configs()
        d = verify_manifest(load_json(a.manifest), _repo(a), sched, rules)
        rec(not d, f"manifest matches live inventory ({len(d)} differences)")
        for x in d[:30]:
            lines.append("      " + x)
    print("\n".join(lines))
    print("\nSTATIC VALIDATION:", "OK (static only; runtime behaviour UNVERIFIED)" if ok else "FAILED")
    sys.exit(0 if ok else 1)


def _cmd_run_locked(a):
    # ---------- STAGE B GATE: three independent confirmations ----------
    if not a.i_am_the_owner or a.stage_b_confirm != STAGE_B_PHRASE:
        die(f"REFUSED (Stage A only). Stage B needs --i-am-the-owner and --stage-b-confirm '{STAGE_B_PHRASE}'. Use `plan` for a dry run.")
    sched, rules, repo, m, _ = _make_plan(a)
    if not a.approve_manifest or not m["digest"].startswith(a.approve_manifest) or len(a.approve_manifest) < 12:
        die("REFUSED: --approve-manifest must be >=12 hex chars of the manifest digest the Owner reviewed")
    from pctest import runner
    run_id = uuid.uuid4().hex[:12]
    out_dir = Path(a.out_dir).resolve() / f"run-{run_id}"
    sched, rules, repo, m, plan = _make_plan(a, run_id, out_dir)
    try:
        findings = runner.preflight(plan, m, repo, sched, rules, out_dir)
    except Exception as exc:
        die(f"PREFLIGHT REFUSED: {type(exc).__name__}: {exc}")
    password = read_dotenv(repo / "backend/.env.testing").get("DB_PASSWORD")
    if not any(f.level == "error" for f in findings):
        try:
            findings += runner.db_live_checks(plan, repo, sched, password or "")
        except Exception as e:
            findings.append(guards.Finding("error", "DB_CHECK_FAILED", str(e)))
    for f in findings:
        print(f.level.upper(), f.code, f.message)
    if any(f.level == "error" for f in findings):
        die("PREFLIGHT REFUSED — nothing was started.")  # exit code 2 (documented)
    sys.exit(runner.execute(plan, m, repo, sched, rules, password or ""))


def _cmd_prepare_frontend_locked(a):
    """OWNER-ONLY, network-using: builds the Linux (musl) dependency tree OUTSIDE the host node_modules. Never run by Stage A."""
    if not a.i_am_the_owner or a.confirm != PREPARE_PHRASE:
        die(f"REFUSED: needs --i-am-the-owner --confirm '{PREPARE_PHRASE}'")
    sched, _ = default_configs()
    repo = _repo(a)
    from pctest.plan import node_deps_dir
    ad = sched["adapters"]["node"]
    deps = node_deps_dir(repo, sched)
    if incomplete_runs(repo, sched["out_root_rel"]):
        die("REFUSED: unresolved run history; orphan processes must be recovered before dependency preparation")
    lock = repo / "frontend/package-lock.json"
    if not lock.is_file():
        die("REFUSED: frontend/package-lock.json missing")
    img = subprocess.run(["docker", "image", "inspect", "-f", "{{.Architecture}} {{.Id}}", ad["image"]], capture_output=True, text=True)
    if img.returncode != 0:
        die(f"REFUSED: image {ad['image']} not present locally (the tool never pulls images)")
    preparing = deps / ".pc-preparing.json"
    if preparing.exists():
        die("BLOCKED: previous frontend preparation has not been proven stopped", 4)
    marker = deps / ".pc-prepared.json"
    if marker.exists():
        marker.unlink()  # a failed/interrupted prepare must never leave an old success marker
    deps.mkdir(parents=True, exist_ok=True)
    (deps / "node_modules").mkdir(exist_ok=True)
    name = f"pc-npmci-{uuid.uuid4().hex[:12]}"
    import hashlib
    lock_hash = hashlib.sha256(lock.read_bytes()).hexdigest()
    package_hash = hashlib.sha256((repo / "frontend/package.json").read_bytes()).hexdigest()
    dump_json(preparing, {"container": name, "image": ad["image"]})
    argv = ["docker", "run", "--pull=never", "--rm", "--name", name, "--user", f"{os.getuid()}:{os.getgid()}", "-e", "HOME=/tmp",
            "-v", f"{repo / 'frontend'}:{ad['container_frontend']}:ro", "-v", f"{deps / 'node_modules'}:{ad['container_frontend']}/node_modules",
            "-w", ad["container_frontend"], img.stdout.split()[1], "npm", "ci", "--ignore-scripts", "--engine-strict", "--no-audit", "--no-fund"]
    try:
        r = subprocess.run(argv)
    finally:
        # Marker survives a crash or an unverifiable shutdown. Never blindly overwrite a previous preparation.
        subprocess.run(["docker", "rm", "-f", name], capture_output=True, timeout=60)
        check = subprocess.run(["docker", "ps", "-a", "--filter", f"name={name}", "--format", "{{.Names}}"], capture_output=True, text=True, timeout=30)
        if check.returncode != 0 or name in check.stdout.split():
            die("BLOCKED: dependency-preparation container termination unverified", 4)
        preparing.unlink()
    if r.returncode != 0:
        die(f"FAILED: npm ci exited {r.returncode}; no marker written", 1)
    if lock_hash != hashlib.sha256(lock.read_bytes()).hexdigest() or package_hash != hashlib.sha256((repo / "frontend/package.json").read_bytes()).hexdigest():
        die("REFUSED: package files changed during dependency preparation")
    dump_json(marker, {"package_lock_sha256": lock_hash, "package_json_sha256": package_hash, "image": ad["image"],
                       "architecture": img.stdout.split()[0], "image_id": img.stdout.split()[1],
                       "tree_sha256": dependency_tree_digest(deps / "node_modules"),
                       "prepared_at": __import__("datetime").datetime.now(__import__("datetime").timezone.utc).isoformat()})
    from pctest.runner import check_frontend_deps
    bad = [f for f in check_frontend_deps(repo, sched) if f.level == "error"]
    for f in bad:
        print("ERROR", f.code, f.message, file=sys.stderr)
    if bad:
        marker.unlink()
    sys.exit(0 if not bad else 1)


def cmd_run(a):
    if not a.i_am_the_owner or a.stage_b_confirm != STAGE_B_PHRASE:
        die("REFUSED: explicit Owner activation required")
    sched, _ = default_configs()
    with dependency_lock(_repo(a), sched["adapters"]["node"]["deps_dir_rel"]):
        return _cmd_run_locked(a)


def cmd_prepare_frontend(a):
    if not a.i_am_the_owner or a.confirm != PREPARE_PHRASE:
        die("REFUSED: explicit Owner dependency preparation required")
    sched, _ = default_configs()
    with dependency_lock(_repo(a), sched["adapters"]["node"]["deps_dir_rel"]):
        return _cmd_prepare_frontend_locked(a)


def cmd_provision(a):
    from pctest import provision
    sched, _ = default_configs()
    dbs = provision.names(a.workers, sched["worker_database_pattern"])
    repo = _repo(a)
    user = a.db_user or db_connection_defaults(repo, sched)["user"]
    if a.action == "cleanup":
        sql = provision.cleanup_sql(dbs)
    elif a.user_host:
        sql = provision.create_sql(dbs, user, a.user_host, sched["required_db_privileges"])
    else:  # plan preview only; `create` refuses without --user-host
        sql = provision.create_sql(dbs, user, "USER_HOST_PLACEHOLDER", sched["required_db_privileges"])
    print("-- " + ("PLAN ONLY (nothing executed)" if a.action == "plan" else a.action.upper()) + "\n" + "\n".join(sql))
    if a.action == "plan":
        return
    phrase = PROVISION_PHRASE if a.action == "create" else CLEANUP_PHRASE
    if a.confirm != phrase or not a.i_am_the_owner:
        die(f"REFUSED: needs --i-am-the-owner --confirm '{phrase}'" + (" --user-host <host>" if a.action == "create" else ""))
    if a.action == "create" and not a.user_host:
        die("REFUSED: --user-host is required to create grants")
    if a.action == "cleanup":  # secondary, host-side signal; the authoritative coordination is the lock set inside the SQL session
        root = repo / sched["out_root_rel"]
        live = incomplete_runs(repo, sched["out_root_rel"])
        if live:
            die(f"REFUSED: run directories without a completion record exist ({live}); a run may be active")
    mdb = sched["adapters"]["mariadb"]
    # preflight: everything must hold or NOTHING is executed
    host = db_connection_defaults(repo, sched)
    guards.raise_on_errors(guards.check_host(host["host"], host["port"], tuple(sched["allowed_db_hosts"])))
    chk = subprocess.run(["docker", "inspect", "-f", "{{.State.Running}} {{.Config.Image}}", mdb["container"]], capture_output=True, text=True)
    if chk.returncode != 0 or not chk.stdout.startswith("true mariadb"):
        die(f"PREFLIGHT REFUSED: {mdb['container']} is not a running mariadb container ({chk.stdout.strip() or chk.stderr.strip()})")
    r = subprocess.run(provision.exec_argv(mdb["container"], mdb["root_secret_path"]), input="\n".join(sql) + "\n", text=True, capture_output=True)
    if r.returncode != 0:
        print(("REFUSED/FAILED: " + ((r.stderr or "").strip().splitlines() or ["unknown error"])[-1][:300]
               + ("  (a held run lock/lease aborts the batch before any DROP)" if a.action == "cleanup" else "")), file=sys.stderr)
        sys.exit(2 if a.action == "cleanup" else 1)
    print("done")


def main(argv=None):
    ap = argparse.ArgumentParser(prog="pc", description=__doc__)
    sub = ap.add_subparsers(dest="cmd", required=True)

    def common(p, run=False):
        p.add_argument("--repo", default=str(ROOT.parent.parent), help="PrimeClassy checkout root (default: this package's repo; read-only for Stage A)")
        if run:
            p.add_argument("--manifest", required=True)
            p.add_argument("--out-dir", required=True, help="Stage B writes only under <out-dir>/run-<id>/ (and the serial lane's repo storage/framework/testing/runs)")
            p.add_argument("--workers", type=int, default=0)

    p = sub.add_parser("manifest-generate"); common(p); p.add_argument("--workers", type=int, default=5); p.add_argument("--timing-file"); p.add_argument("--out", required=True); p.add_argument("--overwrite-manifest", action="store_true"); p.set_defaults(f=cmd_manifest_generate)
    p = sub.add_parser("manifest-verify"); common(p); p.add_argument("--manifest", required=True); p.set_defaults(f=cmd_manifest_verify)
    p = sub.add_parser("static-validate"); common(p); p.add_argument("--manifest"); p.set_defaults(f=cmd_static_validate)
    p = sub.add_parser("plan"); common(p, True); p.set_defaults(f=cmd_plan)
    p = sub.add_parser("aggregate"); p.add_argument("--run-dir", required=True); p.add_argument("--manifest", required=True); p.set_defaults(f=cmd_aggregate)
    p = sub.add_parser("run"); common(p, True); p.add_argument("--i-am-the-owner", action="store_true"); p.add_argument("--stage-b-confirm"); p.add_argument("--approve-manifest"); p.set_defaults(f=cmd_run)
    p = sub.add_parser("prepare-frontend"); common(p); p.add_argument("--i-am-the-owner", action="store_true"); p.add_argument("--confirm"); p.set_defaults(f=cmd_prepare_frontend)
    p = sub.add_parser("provision"); common(p); p.add_argument("action", choices=["plan", "create", "cleanup"]); p.add_argument("--workers", type=int, default=5)
    p.add_argument("--db-user"); p.add_argument("--user-host"); p.add_argument("--i-am-the-owner", action="store_true"); p.add_argument("--confirm"); p.set_defaults(f=cmd_provision)
    a = ap.parse_args(argv)
    try:
        a.f(a)
    except KeyboardInterrupt:
        die("BLOCKED: interrupted outside verified runner shutdown", 4)
    except Exception as exc:
        die(f"{('BLOCKED' if a.cmd == 'run' else 'REFUSED')}: {type(exc).__name__}: {exc}", 4 if a.cmd == 'run' else 2)


if __name__ == "__main__":
    main()
