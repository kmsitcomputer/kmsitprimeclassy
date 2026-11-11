"""STAGE B runner. Spawns test processes. Must only be invoked by the Owner (see cli.py activation gate).

Foreground only, no daemonising, no retries, no fallback to a subset. Exit codes:
0 PASS / PASS_WITH_SKIPS, 1 FAIL, 2 preflight refused (nothing ran), 3 INCOMPLETE, 4 BLOCKED (process termination unverified), 130 aborted by signal.
Nothing in this module runs at import time.
"""
from __future__ import annotations

import fnmatch
import hashlib
import json
import select
import os
import re
import shutil
import signal
import subprocess
import time
from datetime import datetime, timezone
from pathlib import Path

from .aggregate import aggregate, exit_code_for
from .common import ROOT, dump_json, read_dotenv, incomplete_runs, dependency_tree_digest
from .guards import CANONICAL_DB, Finding, check_inherited_env, check_no_sharing, check_out_dir, check_worker_env
from .manifest import verify_manifest
from .plan import child_env, materialise, node_deps_dir

now = lambda: datetime.now(timezone.utc).isoformat(timespec="seconds")  # noqa: E731


def _docker(*args: str, timeout: int = 60, input: str | None = None, env: dict | None = None) -> subprocess.CompletedProcess:
    return subprocess.run(["docker", *args], capture_output=True, text=True, timeout=timeout, input=input, env=env)


def preflight(plan: dict, manifest: dict, repo: Path, sched: dict, rules: dict, out_dir: Path) -> list[Finding]:
    f: list[Finding] = []
    err = lambda c, m: f.append(Finding("error", c, m))  # noqa: E731
    warn = lambda c, m: f.append(Finding("warn", c, m))  # noqa: E731
    hosts = tuple(sched["allowed_db_hosts"])
    if sched["adapters"]["php"]["mode"] != "docker-exec":
        err("PHP_ADAPTER_UNSUPPORTED", "host execution has no container ownership barrier; refusing")
    if incomplete_runs(repo, sched["out_root_rel"]):
        err("UNRESOLVED_RUN", "a previous run lacks verified process termination; recover it before another run")
    f += check_inherited_env(dict(os.environ))
    f += check_out_dir(out_dir, repo, sched["out_root_rel"])
    for w in plan["workers"]:
        if w["php_files"]:
            f += check_worker_env(w["env"], "parallel", hosts)
    f += check_worker_env(plan["serial"]["env"], "serial", hosts)
    f += check_no_sharing([w for w in plan["workers"] if w["php_files"]])
    for p in plan["assignment_problems"]:
        err("INVENTORY_ASSIGNMENT", p)
    for d in verify_manifest(manifest, repo, sched, rules):  # live inventory must equal what the Owner approved
        err("INVENTORY_MISMATCH", d)
    be = repo / "backend"
    env_testing = read_dotenv(be / ".env.testing")
    if not env_testing.get("DB_USERNAME") or not env_testing.get("DB_PASSWORD"):
        err("ENV_TESTING_INCOMPLETE", "backend/.env.testing must define DB_USERNAME and DB_PASSWORD (values are never printed)")
    if env_testing.get("DB_DATABASE") != CANONICAL_DB or env_testing.get("APP_ENV") != "testing":
        err("ENV_TESTING_NOT_CANONICAL", f"backend/.env.testing must have APP_ENV=testing and DB_DATABASE={CANONICAL_DB}")
    for fn in (".env", ".env.testing"):
        for k, v in read_dotenv(be / fn).items():
            if k in ("DB_URL", "DATABASE_URL", "DB_SOCKET", "PC_RUN_ID", "PC_JOB_ID") and v:
                err("ENV_FILE_CONNECTION_OVERRIDE", f"backend/{fn} defines {k}; a URL/socket override is not allowed for testing")
    for line in plan["filters_applied"]:
        err("FORBIDDEN_ARGUMENT", f"coverage-reducing argument refused: {line}")
    # repo dependencies (host view of the bind mount)
    if not (be / "vendor/phpunit/phpunit/phpunit").is_file():
        err("DEP_MISSING", "backend/vendor/phpunit/phpunit/phpunit not found")
    if any(j["kind"] == "phpunit_serial" for j in plan["jobs"]) and not (be / sched["phpunit"]["serial_runner_script"]).is_file():
        err("DEP_MISSING", f"backend/{sched['phpunit']['serial_runner_script']} not found")
    if sched["adapters"]["node"]["mode"] != "docker-run":
        err("NODE_ADAPTER_UNSUPPORTED", "only the docker-run node adapter is supported (host node_modules is darwin-only)")
    f += check_frontend_deps(repo, sched)
    # the application's own guard must allow worker databases (integrated change in tests/Support/TestDatabaseGuard.php)
    guard = (be / "tests/Support/TestDatabaseGuard.php")
    gtxt = guard.read_text() if guard.is_file() else ""
    if "PRIMECLASSY_PARALLEL_WORKER" not in gtxt or "WORKER_DATABASES" not in gtxt:
        err("APP_GUARD_NOT_PATCHED", "tests/Support/TestDatabaseGuard.php does not support worker databases; refusing (no silent fallback)")
    # execution adapters
    ad = sched["adapters"]
    needs_docker = ad["php"]["mode"] == "docker-exec" or ad["node"]["mode"] == "docker-run"
    if needs_docker and not shutil.which("docker"):
        err("DEP_MISSING", "docker CLI not on PATH")
    elif needs_docker:
        try:
            if ad["php"]["mode"] == "docker-exec":
                f += _check_php_container(repo, sched)
                try:
                    process_control(sched, "000000000000", action="probe")
                except Exception:
                    err("PROCESS_CONTROL_UNAVAILABLE", "Python >=3.9 and Linux pidfd/proc access are required inside the PHP container; no fallback")
            if ad["node"]["mode"] == "docker-run":
                r = _docker("image", "inspect", ad["node"]["image"])
                if r.returncode != 0:
                    err("NODE_IMAGE_MISSING", f"image {ad['node']['image']} not present locally (the runner never pulls images)")
            deps_marker = json.loads((node_deps_dir(repo, sched) / ".pc-prepared.json").read_text()) if (node_deps_dir(repo, sched) / ".pc-prepared.json").is_file() else {}
            architecture = _docker("image", "inspect", "-f", "{{.Architecture}} {{.Id}}", ad["node"]["image"])
            if architecture.returncode != 0 or architecture.stdout.strip().split() != [deps_marker.get("architecture"), deps_marker.get("image_id")]:
                err("FRONTEND_IMAGE_ARCH", "dependency marker does not match image architecture")
            node_env = _docker("image", "inspect", "-f", "{{json .Config.Env}}", ad["node"]["image"])
            variables = dict(line.split("=", 1) for line in json.loads(node_env.stdout or "[]") if "=" in line)
            version = variables.get("NODE_VERSION", "")
            match = re.fullmatch(r"(\d+)\.(\d+)\.(\d+)", version)
            numbers = tuple(map(int, match.groups())) if match else ()
            if node_env.returncode != 0 or not numbers or not (numbers >= (24, 12, 0) or (22, 18, 0) <= numbers < (23, 0, 0)):
                err("NODE_VERSION_UNVERIFIED", "image NODE_VERSION does not meet the repository Node requirement")
            f += _no_other_runner_docker(sched)
        except subprocess.TimeoutExpired:
            err("DOCKER_TIMEOUT", "docker did not answer within the preflight timeout")
    ps = subprocess.run(["ps", "-axo", "command"], capture_output=True, text=True).stdout.splitlines()
    for line in ps:
        if re.search(r"vendor/phpunit/phpunit/phpunit|artisan test|run-tests-serialized|vitest", line) and "pctest" not in line and "pc-parallel" not in line:
            err("OTHER_RUNNER_ACTIVE", f"host test process running: {line[:140]}")
    return f


def _check_php_container(repo: Path, sched: dict) -> list[Finding]:
    f: list[Finding] = []
    a = sched["adapters"]["php"]
    m = sched["adapters"]["mariadb"]
    for name in (a["container"], m["container"]):
        r = _docker("inspect", "-f", "{{.State.Running}}", name)
        if r.returncode != 0 or r.stdout.strip() != "true":
            f.append(Finding("error", "CONTAINER_NOT_RUNNING", f"container {name} is not running (the runner never starts containers)"))
    if f:
        return f
    mounts = json.loads(_docker("inspect", "-f", "{{json .Mounts}}", a["container"]).stdout or "[]")
    host_be = str((repo / "backend").resolve())
    ok = any(x.get("Destination") == a["container_backend"] and str(Path(x.get("Source", "")).resolve()) == host_be and x.get("RW") for x in mounts)
    if not ok:
        f.append(Finding("error", "MOUNT_MISMATCH", f"{a['container']} does not bind-mount {host_be} read-write at {a['container_backend']}"))
    nets_a = set(json.loads(_docker("inspect", "-f", "{{json .NetworkSettings.Networks}}", a["container"]).stdout or "{}"))
    nets_m = set(json.loads(_docker("inspect", "-f", "{{json .NetworkSettings.Networks}}", m["container"]).stdout or "{}"))
    if not (nets_a & nets_m):
        f.append(Finding("error", "NETWORK_MISMATCH", f"{a['container']} and {m['container']} share no docker network"))
    env_keys = [e.split("=", 1) for e in _docker("inspect", "-f", "{{range .Config.Env}}{{println .}}{{end}}", a["container"]).stdout.splitlines() if "=" in e]
    for k, v in env_keys:
        if k in ("DB_URL", "DATABASE_URL", "DB_SOCKET", "PC_RUN_ID", "PC_JOB_ID") and v:
            f.append(Finding("error", "CONTAINER_CONNECTION_OVERRIDE", f"{a['container']} exports {k}; refusing (workers also blank it explicitly, but a hidden override is not accepted)"))
    mi = _docker("inspect", "-f", "{{.Config.Image}}", m["container"]).stdout.strip()
    if not mi.startswith("mariadb"):
        f.append(Finding("error", "DB_CONTAINER_IDENTITY", f"{m['container']} image is {mi!r}, expected a mariadb image"))
    return f


def _no_other_runner_docker(sched: dict) -> list[Finding]:
    f: list[Finding] = []
    a = sched["adapters"]["php"]
    if a["mode"] == "docker-exec":
        top = _docker("top", a["container"], "-o", "pid,args")
        for line in top.stdout.splitlines()[1:]:
            if re.search(r"phpunit|artisan test|run-tests-serialized|\.phpunit-concurrency-actor", line):
                f.append(Finding("error", "OTHER_RUNNER_ACTIVE", f"test process inside {a['container']}: {line.strip()[:140]}"))
    ps = _docker("ps", "--filter", "name=pc-vitest-", "--format", "{{.Names}}")
    for n in ps.stdout.split():
        f.append(Finding("error", "OTHER_RUNNER_ACTIVE", f"leftover vitest container {n}"))
    return f


def _grants_cover(grants: list[str], db: str, needed: list[str]) -> bool:
    have: set[str] = set()
    for g in grants:
        m = re.match(r"GRANT (.+?) ON (.+?) TO ", g.strip(), re.I)
        if not m:
            continue
        privs, target = m.group(1), m.group(2).replace("`", "")
        dbpart = target.rsplit(".", 1)[0]
        pat = dbpart.replace("\\_", "_")
        if dbpart == "*" or fnmatch.fnmatchcase(db, pat.replace("%", "*")):
            if target.endswith(".*") or target == "*.*":
                have |= {p.strip().upper() for p in privs.split(",")}
    return "ALL PRIVILEGES" in have or all(p in have for p in needed)


def unsafe_grants(grants: list[str]) -> bool:
    """Test credentials must not carry global/role grants or schema wildcard grants outside the exact testing schemas."""
    allowed = {CANONICAL_DB, *(f"primeclassy_testing_w{i}" for i in range(1, 6))}
    for grant in grants:
        match = re.match(r"GRANT (.+?) ON (.+?) TO ", grant.strip(), re.I)
        if not match or "WITH GRANT OPTION" in grant.upper():
            return True
        privileges, target = match.group(1).upper(), match.group(2).replace("`", "")
        if target == "*.*" and privileges == "USAGE":
            continue
        schema = target.rsplit(".", 1)[0]
        # MariaDB treats unescaped '_' and '%' in database GRANTs as wildcard patterns.
        literal = schema.replace("\\_", "")
        if "_" in literal or "%" in literal or "\\" in literal:
            return True
        if schema.replace("\\_", "_") not in allowed:
            return True
    return False


def db_live_checks(plan: dict, repo: Path, sched: dict, password: str) -> list[Finding]:
    """Stage B only. READ-ONLY probe run inside the php container (the same network path the tests use):
    databases must already exist (never auto-created), run-locks must be free, test user needs privileges."""
    f: list[Finding] = []
    probe = (ROOT / "php/db-preflight.php").read_text()
    env = plan["serial"]["env"]
    dbs = [CANONICAL_DB] + [w["db"] for w in plan["workers"] if w["php_files"]]
    penv = {**child_env(sched), "DB_PASSWORD": password}
    a = sched["adapters"]["php"]
    inner = {"PC_HOST": env["DB_HOST"], "PC_PORT": env["DB_PORT"], "PC_USER": env["DB_USERNAME"], "PC_DBS": ",".join(dbs)}
    if a["mode"] == "docker-exec":
        argv = ["docker", "exec", "-i", "-e", "DB_PASSWORD", *sum((["-e", f"{k}={v}"] for k, v in inner.items()), []), a["container"], a["php_bin"]]
        r = subprocess.run(argv, input=probe, capture_output=True, text=True, timeout=60, env=penv)
    else:
        r = subprocess.run([a["php_bin"]], input=probe, capture_output=True, text=True, timeout=60, env={**penv, **inner})
    line = next((l for l in reversed(r.stdout.splitlines()) if l.startswith("{")), "")
    try:
        out = json.loads(line)
    except Exception:
        return [Finding("error", "DB_CHECK_FAILED", "preflight probe produced no JSON (see runner output); nothing was started")]
    if not out.get("ok"):
        return [Finding("error", "DB_CHECK_FAILED", str(out.get("error")))]
    if "mariadb" not in str(out.get("server", "")).lower():
        f.append(Finding("warn", "DB_SERVER_FLAVOUR", f"server reports {out.get('server')!r} (expected MariaDB 10.11)"))
    if unsafe_grants(out["grants"]):
        f.append(Finding("error", "TEST_USER_OVERPRIVILEGED", "test user has global, role, wildcard, or non-testing grants; Owner must review privileges separately"))
    for db in dbs:
        d = out["dbs"].get(db, {})
        if not d.get("exists"):
            f.append(Finding("error", "DB_MISSING", f"database {db} does not exist (provisioning is a separate Owner step)"))
            continue
        if d.get("lock_holder") not in (None, "", "NULL") or d.get("lease_holder") not in (None, "", "NULL"):
            f.append(Finding("error", "DB_LOCK_HELD", f"{db}: another runner/cleanup holds its run lock or lease"))
        if not _grants_cover(out["grants"], db, sched["required_db_privileges"]):
            f.append(Finding("error", "DB_PRIVILEGES", f"test user lacks required privileges on {db}: {sched['required_db_privileges']}"))
    return f


def check_frontend_deps(repo: Path, sched: dict) -> list[Finding]:
    """F07: the Linux dependency tree must exist, match package-lock.json and carry a native binding for EVERY native package the host
    tree has a darwin build of, for the image architecture + musl. The host node_modules is never mounted into the container."""
    f: list[Finding] = []
    a = sched["adapters"]["node"]
    deps = node_deps_dir(repo, sched)
    if (deps / ".pc-preparing.json").exists():
        return [Finding("error", "FRONTEND_PREPARATION_UNRESOLVED", "an interrupted dependency preparation requires Owner recovery")]
    marker = deps / ".pc-prepared.json"
    if not marker.is_file():
        return [Finding("error", "FRONTEND_DEPS_NOT_PREPARED", f"{deps} has no .pc-prepared.json — run the Owner step `cli.py prepare-frontend` (see OWNER-RUNBOOK)")]
    try:
        info = json.loads(marker.read_text())
    except Exception:
        return [Finding("error", "FRONTEND_DEPS_MARKER_INVALID", f"{marker} unreadable")]
    lock = repo / "frontend/package-lock.json"
    if not lock.is_file():
        return [Finding("error", "FRONTEND_LOCKFILE_MISSING", "frontend/package-lock.json missing")]
    if info.get("package_lock_sha256") != hashlib.sha256(lock.read_bytes()).hexdigest():
        f.append(Finding("error", "FRONTEND_DEPS_STALE", "package-lock.json changed since the Linux dependency tree was prepared; re-run prepare-frontend"))
    package_json = repo / "frontend/package.json"
    if info.get("package_json_sha256") != hashlib.sha256(package_json.read_bytes()).hexdigest():
        f.append(Finding("error", "FRONTEND_PACKAGE_STALE", "package.json changed since dependency preparation"))
    if not re.fullmatch(r"sha256:[a-f0-9]{64}", info.get("image_id", "")):
        f.append(Finding("error", "FRONTEND_IMAGE_UNPINNED", "dependency marker lacks an immutable image ID"))
    if info.get("image") != a["image"]:
        f.append(Finding("error", "FRONTEND_DEPS_IMAGE_MISMATCH", f"deps prepared with {info.get('image')!r}, adapter uses {a['image']!r}"))
    arch = {"amd64": "x64", "arm64": "arm64"}.get(info.get("architecture", ""))
    if not arch:
        return f + [Finding("error", "FRONTEND_DEPS_ARCH_UNKNOWN", f"marker architecture {info.get('architecture')!r} unsupported")]
    nm = deps / "node_modules"
    try:
        if info.get("tree_sha256") != dependency_tree_digest(nm):
            f.append(Finding("error", "FRONTEND_DEPS_CONTENT_CHANGED", "prepared dependency content no longer matches its marker"))
    except Exception:
        f.append(Finding("error", "FRONTEND_DEPS_UNSAFE", "dependency tree missing or escapes its root"))
    host = repo / "frontend/node_modules"
    families = set()
    if host.is_dir():  # every native package family the host tree resolved for darwin must have a linux-musl twin
        for d in list(host.glob("*darwin-*")) + list(host.glob("@*/*darwin-*")):
            families.add(re.sub(r"darwin-(x64|arm64)$", "", d.name) if d.parent == host else f"{d.parent.name}/" + re.sub(r"darwin-(x64|arm64)$", "", d.name))
    families |= {"@rolldown/binding-"}  # always required (vite/vitest bundler)
    locked_packages = json.loads(lock.read_text()).get("packages", {})
    for fam in sorted(families):
        name = f"{fam}linux-{arch}-musl"
        package = nm / name
        if not package.is_dir():
            f.append(Finding("error", "NATIVE_BINDING_MISMATCH", f"Linux tree lacks {name}"))
            continue
        try:
            metadata = json.loads((package / "package.json").read_text())
            if metadata.get("name") != name or metadata.get("version") != locked_packages["node_modules/" + name]["version"]:
                raise ValueError("native package metadata differs from lock")
            binaries = list(package.rglob("*.node"))
            if not binaries:
                raise ValueError("native addon binary missing")
            for binary in binaries:
                with binary.open("rb") as stream:
                    header = stream.read(20)
                if len(header) != 20 or header[:6] != b"\x7fELF\x02\x01" or int.from_bytes(header[18:20], "little") != {"x64": 62, "arm64": 183}[arch]:
                    raise ValueError("native binary is not matching Linux ELF64")
        except Exception:
            f.append(Finding("error", "NATIVE_BINDING_INVALID", f"{name} metadata/binary does not match the lock and architecture"))
    if any(nm.glob("@rolldown/binding-darwin-*")):
        f.append(Finding("error", "NATIVE_BINDING_MISMATCH", "darwin bindings found inside the Linux dependency tree"))
    return f


# ----------------------------------------------------------------- lifecycle (F03)
class Blocked(RuntimeError):
    """Process termination could not be verified: fail closed, keep artifacts, start nothing further."""


def _exec_sh(sched: dict, script: str, timeout: int = 30) -> subprocess.CompletedProcess:
    return subprocess.run(["docker", "exec", sched["adapters"]["php"]["container"], "sh", "-c", script], capture_output=True, text=True, timeout=timeout)


def process_control(sched: dict, run_id: str, job_id: str = "", action: str = "scan") -> list[str]:
    """Requires a verified Linux pidfd controller; scanning errors never mean an empty process set."""
    if sched["adapters"]["php"]["mode"] != "docker-exec":
        raise Blocked("only the docker-exec PHP adapter has a verified ownership controller")
    helper = (ROOT / "container/process_control.py").read_text()
    r = subprocess.run(["docker", "exec", sched["adapters"]["php"]["container"], "python3", "-B", "-c", helper,
                        run_id, job_id, action], capture_output=True, text=True, timeout=30)
    try:
        result = json.loads(r.stdout)
        if r.returncode != 0 or result.get("ok") is not True or not isinstance(result.get("pids"), list):
            raise ValueError("controller refused")
        if any(type(pid) is not int or pid <= 0 for pid in result["pids"]):
            raise ValueError("invalid PID response")
        return [str(pid) for pid in result["pids"]]
    except (ValueError, TypeError, AttributeError) as exc:
        raise Blocked("process ownership/pidfd control unavailable; no safe fallback") from exc


def owned_pids(sched: dict, marker: str, run_id: str | None = None) -> list[str]:
    key, _, value = marker.partition("=")
    if key == "PC_RUN_ID" and not re.fullmatch(r"[a-f0-9]{12}", value):
        raise ValueError("invalid run marker")
    if key == "PC_RUN_ID":
        return process_control(sched, value)
    if key == "PC_JOB_ID" and run_id:
        return process_control(sched, run_id, value)
    raise ValueError("job ownership requires both run and job markers")


def stop_owned(sched: dict, marker: str, run_id: str | None = None) -> bool:
    key, _, value = marker.partition("=")
    target_run = value if key == "PC_RUN_ID" else run_id
    job_id = value if key == "PC_JOB_ID" else ""
    if not target_run:
        raise Blocked("missing run ownership")
    for sig, wait in (("TERM", sched["kill_grace_seconds"]), ("KILL", sched["kill_verify_seconds"])):
        if not owned_pids(sched, marker, run_id):
            return True
        process_control(sched, target_run, job_id, sig)
        end = time.monotonic() + wait
        while time.monotonic() < end:
            time.sleep(0.2)
            if not owned_pids(sched, marker, run_id):
                return True
    return not owned_pids(sched, marker, run_id)


def _containers_named(prefix: str) -> list[str]:
    r = subprocess.run(["docker", "ps", "-a", "--filter", f"name={prefix}", "--format", "{{.Names}}"], capture_output=True, text=True, timeout=30)
    if r.returncode != 0:
        raise Blocked("docker container enumeration failed")
    return [name for name in r.stdout.split() if name in (prefix, prefix + "-list")]


def _kill_host(proc: subprocess.Popen, grace: int):
    """Host-side client (docker exec/run). Own process group (start_new_session) so descendants of the client die with it."""
    if proc.poll() is None:
        try:
            os.killpg(proc.pid, signal.SIGTERM)
        except ProcessLookupError:
            pass
        end = time.time() + grace
        while time.time() < end and proc.poll() is None:
            time.sleep(0.2)
        if proc.poll() is None:
            try:
                os.killpg(proc.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            proc.wait(timeout=grace)


class Lease:
    """Runner-wide lease on the worker databases (see php/db-lease.php). Released by closing stdin; the server frees it on disconnect."""

    def __init__(self, plan: dict, sched: dict, password: str):
        self.plan, self.sched, self.password, self.proc = plan, sched, password, None

    def acquire(self, timeout: int = 45):
        a = self.sched["adapters"]["php"]
        env = {**child_env(self.sched), "DB_PASSWORD": self.password}
        spec = self.plan["serial"]["env"]
        dbs = ",".join([CANONICAL_DB] + [w["db"] for w in self.plan["workers"] if w["php_files"]])
        code = (ROOT / "php/db-lease.php").read_text().removeprefix("<?php").lstrip()
        inner = {"PC_HOST": spec["DB_HOST"], "PC_PORT": spec["DB_PORT"], "PC_USER": spec["DB_USERNAME"], "PC_DBS": dbs}
        if a["mode"] == "docker-exec":
            argv = ["docker", "exec", "-i", "-e", "DB_PASSWORD", *sum((["-e", f"{k}={v}"] for k, v in inner.items()), []),
                    "-e", f"PC_LEASE_RUN_ID={self.plan['run_id']}", a["container"], a["php_bin"], "-r", code]
        else:
            argv, env = [a["php_bin"], "-r", code], {**env, **inner}
        self.proc = subprocess.Popen(argv, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True, env=env, start_new_session=True)
        ready, _, _ = select.select([self.proc.stdout], [], [], timeout)
        line = self.proc.stdout.readline().strip() if ready else ""
        if line != "LOCKED":
            self.release()
            raise Blocked(f"could not acquire the runner lease on the worker databases ({line or 'timeout'}); another runner or cleanup owns them")

    def assert_owned(self):
        if self.proc is None or self.proc.poll() is not None:
            raise Blocked("runner lease process exited")
        try:
            self.proc.stdin.write("PING\n")
            self.proc.stdin.flush()
            ready, _, _ = select.select([self.proc.stdout], [], [], 5)
            if not ready or self.proc.stdout.readline().strip() != "OWNED":
                raise Blocked("runner lease ownership lost or unprovable")
        except (OSError, ValueError) as exc:
            raise Blocked("runner lease connection lost") from exc

    def alive(self) -> bool:
        self.assert_owned()
        return True

    def release(self):
        if self.proc is None:
            return
        try:
            self.proc.stdin.close()
        except Exception:
            pass
        try:
            self.proc.wait(timeout=15)
        except subprocess.TimeoutExpired:
            _kill_host(self.proc, 5)
        self.proc = None


class _Abort(Exception):
    pass


def _launch(job: dict, plan: dict, sched: dict, argv_key: str = "argv", password: str = ""):
    Path(job["stdout"]).parent.mkdir(parents=True, exist_ok=True)
    env = child_env(sched)
    if job["kind"] != "vitest":
        env["DB_PASSWORD"] = password  # inherited by docker exec -e DB_PASSWORD; never placed in argv/artifacts
    if job["kind"] != "vitest" and sched["adapters"]["php"]["mode"] == "host":
        env = {**env, **job["inner_env"]}
    so, se = open(job["stdout"], "ab"), open(job["stderr"], "ab")
    try:
        proc = subprocess.Popen(job[argv_key], cwd=job["cwd"], env=env, stdout=so, stderr=se, stdin=subprocess.DEVNULL, start_new_session=True)
    except BaseException:
        so.close()
        se.close()
        raise
    st = {"job_id": job["id"], "kind": job["kind"], "worker": job["worker"], "phase": job["phase"], "started_at": now(),
          "exit_code": None, "signal": None, "timed_out": False, "aborted": False, "launch_error": None, "argv": job[argv_key]}
    return {"proc": proc, "st": st, "t0": time.time(), "files": (so, se), "job": job}


def _finish(h: dict, out_dir: Path):
    st, proc = h["st"], h["proc"]
    for fh in h["files"]:
        fh.close()
    rc = proc.returncode
    st["exit_code"] = None if rc is None else (rc if rc >= 0 else 128 - rc)
    st["signal"] = -rc if rc is not None and rc < 0 else None
    st["ended_at"], st["duration_seconds"] = now(), round(time.time() - h["t0"], 2)
    dump_json(out_dir / "status" / f"{h['job']['id']}.json", st)


def _stop_job(h: dict, plan: dict, sched: dict):
    """Stop ONE job and prove it: host client group, ephemeral node container, and every owned process inside the php container."""
    _kill_host(h["proc"], sched["kill_grace_seconds"])
    if h["job"].get("container"):
        subprocess.run(["docker", "rm", "-f", h["job"]["container"]], capture_output=True, timeout=60)
        if _containers_named(h["job"]["container"]):
            raise Blocked(f"container {h['job']['container']} still exists after docker rm -f")
    if h["job"]["kind"] != "vitest" and not stop_owned(sched, f"PC_JOB_ID={h['job']['id']}", plan["run_id"]):
        raise Blocked(f"processes of {h['job']['id']} inside {sched['adapters']['php']['container']} could not be proven stopped")


def run_phase(slots: list[list[dict]], plan: dict, sched: dict, out_dir: Path, aborted: dict, active: list, lease) -> None:
    """Each slot is a worker's ordered job queue; at most len(slots) processes run at once. `active` is owned by the caller so
    ANY exception (launch failure included) leaves the running handles visible to the caller's finally/cleanup."""
    queues = [list(s) for s in slots]
    while any(queues) or any(active):
        lease.assert_owned()
        if aborted["flag"]:
            raise _Abort()
        for i, q in enumerate(queues):
            if active[i] is None and q:
                job = q.pop(0)
                try:
                    active[i] = _launch(job, plan, sched, password=lease.password)
                except Exception as e:  # partial launch: record, then let the caller stop everything already running
                    dump_json(out_dir / "status" / f"{job['id']}.json", {"job_id": job["id"], "exit_code": None, "launch_error": f"{type(e).__name__}: {e}",
                                                                          "started_at": now(), "ended_at": now(), "duration_seconds": 0, "aborted": True, "timed_out": False})
                    raise
                print(f"[{now()}] START {job['id']}", flush=True)
            elif active[i] is not None:
                h = active[i]
                rc = h["proc"].poll()
                if rc is None and time.time() - h["t0"] > h["job"]["timeout"]:
                    h["st"]["timed_out"] = True
                    _stop_job(h, plan, sched)  # raises Blocked when termination cannot be proven
                    rc = h["proc"].returncode
                if rc is not None:
                    if h["job"]["kind"] != "vitest" and not h["st"]["timed_out"] and owned_pids(sched, f"PC_JOB_ID={h['job']['id']}", plan["run_id"]):
                        _stop_job(h, plan, sched)  # client exited but owned descendants survive: stop and prove
                    _finish(h, out_dir)
                    print(f"[{now()}] END   {h['job']['id']} exit={h['st']['exit_code']} {h['st']['duration_seconds']}s", flush=True)
                    active[i] = None
        time.sleep(1.0)


def stop_everything(active: list, plan: dict, sched: dict, out_dir: Path, aborted_flag: bool) -> None:
    """Attempt every cleanup even if one fails; success requires independent proof of no run-owned processes."""
    errors = []
    for h in [x for x in active if x]:
        try:
            _kill_host(h["proc"], sched["kill_grace_seconds"])
            h["st"]["aborted"] = h["st"]["aborted"] or aborted_flag
            _finish(h, out_dir)
        except Exception as exc:
            errors.append(str(exc))
    active[:] = [None] * len(active)
    try:
        for name in _containers_named("pc-vitest-" + plan["run_id"]):
            subprocess.run(["docker", "rm", "-f", name], capture_output=True, timeout=60)
        if _containers_named("pc-vitest-" + plan["run_id"]):
            errors.append("node container termination unverified")
    except Exception as exc:
        errors.append(str(exc))
    try:
        if not stop_owned(sched, f"PC_RUN_ID={plan['run_id']}"):
            errors.append("PHP termination unverified")
    except Exception as exc:
        errors.append(str(exc))
    if errors:
        raise Blocked("; ".join(errors))


def barrier(plan: dict, sched: dict) -> None:
    """Serial lane may start only when NOTHING of this run is alive (verified, not assumed)."""
    pids = owned_pids(sched, f"PC_RUN_ID={plan['run_id']}")
    if pids is None or pids:
        raise Blocked(f"serial-lane barrier: owned processes still alive or undeterminable: {pids}")
    if _containers_named("pc-vitest-" + plan["run_id"]):
        raise Blocked("serial-lane barrier: a node container of this run still exists")


def run_discovery(plan: dict, sched: dict, out_dir: Path, aborted: dict, active: list, lease) -> list[str]:
    """Collect AND validate identities before any test; discovery processes use the same ownership/abort discipline."""
    from .discover import php_expected_from_list_xml, vitest_expected_from_list_json, reconcile
    problems = []
    for job in plan["jobs"]:
        if aborted["flag"]:
            raise _Abort()
        lease.assert_owned()
        Path(job["discovery"]).parent.mkdir(parents=True, exist_ok=True)
        discovery_job = {**job, "id": job["id"] + "-discovery", "phase": "D", "timeout": 600,
                         "container": job.get("discovery_container"),
                         "stdout": str(out_dir / "discovery" / (job["id"] + ".stdout.log")),
                         "stderr": str(out_dir / "discovery" / (job["id"] + ".stderr.log"))}
        active[0] = _launch(discovery_job, plan, sched, "discovery_argv", lease.password)
        h = active[0]
        while h["proc"].poll() is None:
            if aborted["flag"]:
                raise _Abort()
            lease.assert_owned()
            if time.time() - h["t0"] > 600:
                _stop_job(h, plan, sched)
                raise Blocked("discovery timed out; descendants stopped, no tests started")
            time.sleep(0.2)
        if job["kind"] != "vitest" and owned_pids(sched, f"PC_JOB_ID={discovery_job['id']}", plan["run_id"]):
            _stop_job(h, plan, sched)
        _finish(h, out_dir)
        active[0] = None
        try:
            if h["proc"].returncode != 0:
                raise ValueError("discovery process failed")
            parser = vitest_expected_from_list_json if job["kind"] == "vitest" else php_expected_from_list_xml
            identities = parser(Path(job["discovery"]))
            if reconcile(identities, identities)["duplicate_expected"]:
                raise ValueError("duplicate discovery identities")
        except Exception as exc:
            problems.append(f"{job['id']}: discovery invalid: {type(exc).__name__}: {exc}")
            break
    barrier(plan, sched)
    return problems


def execute(plan: dict, manifest: dict, repo: Path, sched: dict, rules: dict, password: str = "") -> int:
    out_dir = Path(plan["out_dir"])
    aborted = {"flag": False}
    handler = lambda s, f: aborted.__setitem__("flag", True)  # noqa: E731
    old = {s: signal.signal(s, handler) for s in (signal.SIGINT, signal.SIGTERM)}
    code = 3
    active: list = [None] * max(1, len(plan["workers"]))
    lease = Lease(plan, sched, password)
    verified_stopped = False
    completed_execution = False
    try:
        if len(plan["workers"]) > sched["max_workers_allowed"]:
            raise SystemExit("worker count above allowed maximum")
        out_dir.mkdir(parents=True, exist_ok=False)  # never reuse/overwrite an earlier run directory
        (out_dir / "status").mkdir()
        materialise(plan, repo, sched)
        public_workers = [{k: v for k, v in w.items() if k != "env"} | {"env_keys": sorted(w["env"])} for w in plan["workers"]]
        dump_json(out_dir / "plan.json", {**plan, "workers": public_workers, "serial": {k: v for k, v in plan["serial"].items() if k != "env"},
                                           "jobs": [{k: v for k, v in j.items() if k != "inner_env"} for j in plan["jobs"]]})
        dump_json(out_dir / "plan.env.json", {"note": "worker env values as passed with docker exec -e (contains no secrets)", "workers": {w["index"]: w["env"] for w in plan["workers"]}, "serial": plan["serial"]["env"]})
        t0 = now()
        try:
            lease.acquire()  # databases are marked IN USE before anything starts; cleanup refuses while this session lives
            problems = run_discovery(plan, sched, out_dir, aborted, active, lease)
            if problems:
                print("DISCOVERY FAILED — no test was started:", *problems, sep="\n  ", flush=True)
                summary = {"schema_version": 1, "verdict": "INCOMPLETE", "run_id": plan["run_id"], "manifest_digest": manifest["digest"],
                           "inventory_completeness": "INCOMPLETE", "discovered_test_cases": None, "executed_test_cases": 0, "pass": 0, "fail": 0, "error": 0, "skip": 0,
                           "jobs": [], "problems": [{"code": "DISCOVERY_FAILED", "severity": "incomplete", "message": m} for m in problems], "failures": [], "skipped_tests": []}
                dump_json(out_dir / "summary.json", summary)
                (out_dir / "summary.md").write_text("# INCOMPLETE — runtime discovery failed; no test was executed\n" + "\n".join(f"- {m}" for m in problems) + "\n")
                code = 3
            else:
                parallel_jobs = [j for j in plan["jobs"] if j["phase"] == "P"]
                slots = [s for s in ([j for j in parallel_jobs if j["worker"] == i] for i in range(1, len(plan["workers"]) + 1)) if s]
                active[:] = [None] * len(slots)
                run_phase(slots, plan, sched, out_dir, aborted, active, lease)  # PHASE P (<= N concurrent)
                if not lease.alive():
                    raise Blocked("runner lease was lost during the parallel phase")
                barrier(plan, sched)  # PHASE S only after P is PROVEN stopped
                serial_jobs = [j for j in plan["jobs"] if j["phase"] == "S"]
                if serial_jobs:
                    active[:] = [None]
                    run_phase([serial_jobs], plan, sched, out_dir, aborted, active, lease)
                completed_execution = True
                code = 0  # no PASS artifact is written until termination has been proven below
            # Final completion is recorded only after process termination has been proven.
        except (KeyboardInterrupt, _Abort):
            print("ABORTED by signal; stopping everything this run owns…", flush=True)
            code = 130
        except Blocked as e:
            print(f"BLOCKED: {e}", flush=True)
            code = 4
            dump_json(out_dir / "BLOCKED.json", {"reason": str(e), "at": now(), "note": "artifacts kept for diagnosis; nothing further was started"})
        except Exception as e:  # partial launch / unexpected: stop everything, report, never PASS
            print(f"RUNNER ERROR: {type(e).__name__}: {e}", flush=True)
            code = 1
            dump_json(out_dir / "RUNNER_ERROR.json", {"error": f"{type(e).__name__}: {e}", "at": now()})
        # ---- every path (success, abort, failure, partial launch): prove nothing of this run is alive ----
        try:
            stop_everything(active, plan, sched, out_dir, code == 130)
            verified_stopped = True
            dump_json(out_dir / "STOPPED.json", {"run_id": plan["run_id"], "verified_stopped": True, "at": now()})
        except Exception as e:
            print(f"BLOCKED: {e}", flush=True)
            code = 4
            dump_json(out_dir / "BLOCKED.json", {"reason": str(e), "at": now(), "note": "termination unverified; artifacts kept, runner lease released only after kill attempts"})
        if code in (130, 1, 4) and (out_dir / "status").is_dir() and not (out_dir / "summary.json").exists():
            try:
                aggregate(out_dir, manifest, plan)  # partial results are reported (never PASS)
            except Exception:
                pass
        if aborted["flag"] and code != 4:
            code = 130
        if completed_execution and code == 0 and verified_stopped:
            try:
                lease.assert_owned()
                summary = aggregate(out_dir, manifest, plan)
                code = exit_code_for(summary["verdict"])
                print(f"VERDICT: {summary['verdict']}  executed={summary['executed_test_cases']}", flush=True)
            except Exception as exc:
                code = 4
                dump_json(out_dir / "BLOCKED.json", {"reason": f"finalization failed: {exc}", "at": now()})
        if aborted["flag"] and code != 4:
            code = 130
        if code in (1, 4, 130):
            # Never leave an earlier PASS artifact behind when final cleanup/abort fails.
            terminal = {1: "FAIL", 4: "BLOCKED", 130: "ABORTED"}[code]
            try:
                summary = json.loads((out_dir / "summary.json").read_text())
            except Exception:
                summary = {"schema_version": 1, "run_id": plan["run_id"], "manifest_digest": manifest["digest"], "problems": []}
            if not isinstance(summary, dict):
                summary = {}
            if code != 1 or summary.get("verdict") != "FAIL":
                summary.update(inventory_completeness="INCOMPLETE", discovered_test_cases=None)
            summary.update(schema_version=1, run_id=plan["run_id"], manifest_digest=manifest["digest"], verdict=terminal)
            for key in ("executed_test_cases", "pass", "fail", "error", "skip"):
                summary.setdefault(key, None)  # unknown accounting is never invented as zero
            for key in ("jobs", "problems", "failures", "skipped_tests"):
                summary.setdefault(key, [])
            summary.setdefault("problems", []).append({"code": terminal, "severity": "fail", "message": "runner terminal state overrides partial results"})
            dump_json(out_dir / "summary.json", summary)
            (out_dir / "summary.md").write_text(f"# {terminal} — see summary.json and runner diagnostics\n")
        dump_json(out_dir / "run.json", {"run_id": plan["run_id"], "manifest_digest": manifest["digest"], "started_at": t0, "ended_at": now(), "exit_code": code,
                   "verdict": summary["verdict"], "verified_stopped": verified_stopped, "aborted": code == 130})
    finally:
        for s, h in old.items():
            signal.signal(s, h)
        lease.release()
        if verified_stopped:  # shared temp resources are removed ONLY after the run is proven stopped
            for spec in [*plan["workers"], plan["serial"]]:
                t = Path(spec["dir"]) / "tmp"
                if t.is_dir() and str(t.resolve()).startswith(str(out_dir.resolve()) + os.sep):
                    shutil.rmtree(t, ignore_errors=True)
    return code
