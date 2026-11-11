"""Builds the execution plan (pure computation) and, at Stage B only, materialises worker directories/configs.

Execution adapters (config/scheduler.json -> adapters):
  php  : docker-exec into the EXISTING primeclassy-fpm container (only backend/ is mounted there), or host.
  node : ephemeral `docker run --rm` node image with network none, or host.
Host paths are mapped to container paths by PathMap; every path the container must read/write lives under backend/.
"""
from __future__ import annotations

import os
import xml.etree.ElementTree as ET
from dataclasses import dataclass
from pathlib import Path

from .common import ROOT, read_dotenv, load_json
from .guards import CANONICAL_DB


@dataclass
class PathMap:
    host_backend: Path
    container_backend: str | None  # None => host mode (identity)

    def c(self, host_path) -> str:
        if self.container_backend is None:
            return str(host_path)
        if not Path(host_path).is_absolute():
            raise ValueError(f"path must be absolute: {host_path}")
        rel = Path(host_path).resolve().relative_to(self.host_backend.resolve())
        return f"{self.container_backend}/{rel.as_posix()}".rstrip("/.")


def render_env(template: str, **kw) -> dict:
    env = {}
    for line in template.splitlines():
        line = line.strip()
        if line and not line.startswith("#") and "=" in line:
            k, v = line.split("=", 1)
            env[k] = v.format(**kw)
    return env


def test_run_uuid(run_id: str, index: int) -> str:
    """PRIMECLASSY_TEST_RUN_ID must be a UUIDv4-shaped id (TestRunLock asserts the format). Deterministic per run+worker."""
    import hashlib
    h = hashlib.sha256(f"{run_id}-w{index}".encode()).hexdigest()
    return f"{h[:8]}-{h[8:12]}-4{h[13:16]}-{'89ab'[int(h[16], 16) % 4]}{h[17:20]}-{h[20:32]}"


FORCED_KEYS = ("APP_ENV", "DB_CONNECTION", "DB_HOST", "DB_PORT", "DB_DATABASE", "DB_USERNAME", "LARAVEL_STORAGE_PATH", "TMPDIR",
               "PRIMECLASSY_TEST_RUN_ID", "PRIMECLASSY_PARALLEL_WORKER", "CACHE_PREFIX", "SESSION_COOKIE", "REDIS_PREFIX",
               "DB_URL", "DATABASE_URL", "DB_SOCKET",
               "APP_SERVICES_CACHE", "APP_PACKAGES_CACHE", "APP_CONFIG_CACHE", "APP_ROUTES_CACHE", "APP_EVENTS_CACHE")


def render_phpunit_config(repo: Path, files: list[str], forced: dict, pm: PathMap) -> str:
    """Per-worker PHPUnit XML (CONTAINER paths): explicit <file> list (no directory scan => no silent omission/duplication),
    repo phpunit.xml <php> settings preserved, isolation variables forced."""
    backend = repo / "backend"
    root = ET.Element("phpunit", {"bootstrap": pm.c(backend / "vendor/autoload.php"), "colors": "false", "cacheResult": "false"})
    ts = ET.SubElement(ET.SubElement(root, "testsuites"), "testsuite", {"name": "parallel-worker"})
    for f in sorted(files):
        ET.SubElement(ts, "file").text = pm.c(repo / f)
    php = ET.SubElement(root, "php")
    base = ET.parse(backend / "phpunit.xml").getroot().find("php")
    for child in (list(base) if base is not None else []):
        if child.tag == "env" and child.get("name") in forced:
            continue
        php.append(child)
    for k, v in sorted(forced.items()):
        ET.SubElement(php, "env", {"name": k, "value": v, "force": "true"})
    ET.indent(root)
    return '<?xml version="1.0" encoding="UTF-8"?>\n' + ET.tostring(root, encoding="unicode") + "\n"


def db_connection_defaults(repo: Path, sched: dict) -> dict:
    """host/port come from config (host as seen from the php container); the user from .env.testing (read-only)."""
    e = read_dotenv(repo / "backend/.env.testing")
    return {"host": sched["db"]["host"], "port": sched["db"]["port"], "user": e.get("DB_USERNAME", "")}


def php_argv(sched: dict, inner_env: dict, args: list[str], workdir: str) -> list[str]:
    a = sched["adapters"]["php"]
    if a["mode"] == "host":
        return [a["php_bin"], *args]
    argv = ["docker", "exec", "-e", "DB_PASSWORD", "-w", workdir]
    for k, v in sorted(inner_env.items()):
        argv += ["-e", f"{k}={v}"]
    return [*argv, a["container"], a["php_bin"], *args]


def node_deps_dir(repo: Path, sched: dict) -> Path:
    relative = sched["adapters"]["node"]["deps_dir_rel"]
    if relative != "backend/storage/framework/testing/parallel-node-linux":
        raise ValueError("dependency directory is fixed to the testing workspace")
    path = repo / relative
    for ancestor in [path, *path.parents]:
        if ancestor == repo:
            break
        if ancestor.is_symlink():
            raise ValueError("dependency workspace must not traverse symlinks")
    return path



def node_argv(sched: dict, repo: Path, out_dir: Path, name: str, files: list[str], extra: list[str], mode: str = "run") -> list[str]:
    """Ephemeral `docker run --rm`. The Linux node_modules tree (prepared separately) is mounted OVER /work/node_modules, so the
    host's darwin node_modules is never executed in the container. mode: run | list (runtime discovery, no execution of test bodies)."""
    a = sched["adapters"]["node"]
    rel = [f.removeprefix("frontend/") for f in files]
    marker = node_deps_dir(repo, sched) / ".pc-prepared.json"
    image = load_json(marker).get("image_id", a["image"]) if marker.is_file() else a["image"]
    base = ["docker", "run", "--pull=never", "--rm", "--name", name, "--network", a["network"], "--user", f"{os.getuid()}:{os.getgid()}",
            "-e", "HOME=/tmp", "-e", "CI=1",
            # Source and the audited Linux dependency tree are READ-ONLY (a test run must never change what the marker/digest
            # attests). Vite/Vitest write caches only to throw-away tmpfs mounts on pre-created empty mountpoints.
            "-v", f"{repo / 'frontend'}:{a['container_frontend']}:ro",
            "-v", f"{node_deps_dir(repo, sched) / 'node_modules'}:{a['container_frontend']}/node_modules:ro",
            *sum(([ "--tmpfs", f"{a['container_frontend']}/node_modules/{d}:rw,mode=1777,size=512m"] for d in (".vite", ".vite-temp", ".vitest-cache")), []),
            "-v", f"{out_dir / 'junit'}:{a['container_out']}", "-v", f"{out_dir / 'discovery'}:{a['container_out']}-discovery",
            "-w", a["container_frontend"], image]
    if mode == "list":
        return [*base, "node", "--input-type=module", "-e", (ROOT / "container/discover-vitest.mjs").read_text(),
                f"{a['container_out']}-discovery/frontend.json", *rel]
    return [*base, "node", f"{a['container_frontend']}/node_modules/vitest/vitest.mjs", "run", "--reporter=default", "--reporter=junit",
            f"--outputFile.junit={a['container_out']}/frontend.xml", *extra, *rel]


def forbidden_args_in(argv: list[str], forbidden: list[str]) -> list[str]:
    hits = []
    for tok in argv:
        for f in forbidden:
            if tok == f or tok.startswith(f + "="):
                hits.append(tok)
    return hits


def build_plan(manifest: dict, repo: Path, out_dir: Path, sched: dict, conn: dict, run_id: str) -> dict:
    n = manifest["workers"]
    tpl = (ROOT / "env/worker.env.template").read_text()
    entries = manifest["entries"]
    pa = sched["adapters"]["php"]
    pm = PathMap(repo / "backend", None if pa["mode"] == "host" else pa["container_backend"])
    cb = pm.c(repo / "backend")
    workers, jobs = [], []

    def spec_for(index, db, wdir, files, fe):
        env = render_env(tpl, N=index, DB_NAME=db, DB_HOST=conn["host"], DB_PORT=conn["port"], DB_USERNAME=conn["user"],
                         WORKER_DIR=pm.c(wdir), RUN_ID=run_id, RUN_UUID=test_run_uuid(run_id, index))
        return {"index": index, "dir": str(wdir), "db": db, "env": env, "_junit": str(out_dir / f"junit/worker-{index}.xml"),
                "_artifacts": str(wdir / "artifacts"), "_stdout": str(wdir / "logs/phpunit.stdout.log"), "_stderr": str(wdir / "logs/phpunit.stderr.log"),
                "php_files": files, "frontend_files": fe}

    for i in range(1, n + 1):
        wdir = out_dir / f"worker-{i}"
        php_files = [e["source_file"] for e in entries if e["framework"] == "phpunit" and e["lane"] == "parallel" and e["worker"] == i]
        fe_files = [e["source_file"] for e in entries if e["framework"] == "vitest" and e["worker"] == i]
        w = spec_for(i, sched["worker_database_pattern"].format(N=i), wdir, php_files, fe_files)
        workers.append(w)
        if php_files:
            cfg = wdir / "phpunit.worker.xml"
            jid = f"worker-{i}-phpunit"
            jenv = {**w["env"], "PC_RUN_ID": run_id, "PC_JOB_ID": jid}
            args = [cb + "/" + sched["phpunit"]["bin"], "-c", pm.c(cfg), "--log-junit", pm.c(w["_junit"]), *sched["phpunit"]["extra_args"]]
            dargs = [cb + "/" + sched["phpunit"]["bin"], "-c", pm.c(cfg), "--list-tests-xml", pm.c(out_dir / f"discovery/{jid}.xml")]
            jobs.append({"id": jid, "kind": "phpunit", "phase": "P", "worker": i, "lane": "parallel",
                         "discovery": str(out_dir / f"discovery/{jid}.xml"), "discovery_argv": php_argv(sched, {**jenv, "PC_JOB_ID": jid + "-discovery"}, dargs, cb),
                         "argv": php_argv(sched, jenv, args, cb), "inner_env": jenv, "cwd": str(repo / "backend"),
                         "config_path": str(cfg), "expected_files": php_files, "junit": w["_junit"], "stdout": w["_stdout"], "stderr": w["_stderr"],
                         "timeout": sched["timeouts_seconds"]["worker_job"], "container": None})
        if fe_files:
            name = f"pc-vitest-{run_id}"
            jobs.append({"id": f"worker-{i}-vitest", "kind": "vitest", "phase": "P", "worker": i, "lane": "parallel",
                         "discovery": str(out_dir / "discovery/frontend.json"),
                         "discovery_argv": node_argv(sched, repo, out_dir, name + "-list", fe_files, [], "list"), "discovery_container": name + "-list",
                         "argv": node_argv(sched, repo, out_dir, name, fe_files, sched["vitest"]["extra_args"]), "inner_env": {},
                         "cwd": str(repo / "frontend"), "config_path": None, "expected_files": fe_files,
                         "junit": str(out_dir / "junit/frontend.xml"), "stdout": str(wdir / "logs/vitest.stdout.log"),
                         "stderr": str(wdir / "logs/vitest.stderr.log"), "timeout": sched["timeouts_seconds"]["frontend_job"],
                         "container": name if sched["adapters"]["node"]["mode"] == "docker-run" else None})
    serial = [e["source_file"] for e in entries if e["framework"] == "phpunit" and e["lane"] == "serial"]
    sdir = out_dir / "serial"
    senv = render_env(tpl, N=0, DB_NAME=CANONICAL_DB, DB_HOST=conn["host"], DB_PORT=conn["port"], DB_USERNAME=conn["user"],
                      WORKER_DIR=pm.c(sdir), RUN_ID=run_id, RUN_UUID=test_run_uuid(run_id, 0))
    senv.pop("PRIMECLASSY_PARALLEL_WORKER_DB", None)
    senv["PRIMECLASSY_PARALLEL_WORKER"] = "0"
    serial_spec = {"index": 0, "dir": str(sdir), "db": CANONICAL_DB, "env": senv, "_junit": str(out_dir / "junit/serial.xml"),
                   "_artifacts": str(sdir / "artifacts"), "_stdout": str(sdir / "logs/phpunit.stdout.log"), "_stderr": str(sdir / "logs/phpunit.stderr.log"),
                   "php_files": serial, "frontend_files": []}
    if serial:
        cfg = sdir / "phpunit.serial.xml"
        jenv = {**senv, "PC_RUN_ID": run_id, "PC_JOB_ID": "serial-phpunit"}
        args = [cb + "/" + sched["phpunit"]["serial_runner_script"], "-c", pm.c(cfg), "--log-junit", pm.c(serial_spec["_junit"]), *sched["phpunit"]["extra_args"]]
        dargs = [cb + "/" + sched["phpunit"]["bin"], "-c", pm.c(cfg), "--list-tests-xml", pm.c(out_dir / "discovery/serial-phpunit.xml")]
        jobs.append({"id": "serial-phpunit", "kind": "phpunit_serial", "phase": "S", "worker": 0, "lane": "serial",
                     "discovery": str(out_dir / "discovery/serial-phpunit.xml"), "discovery_argv": php_argv(sched, {**jenv, "PC_JOB_ID": "serial-phpunit-discovery"}, dargs, cb),
                     "argv": php_argv(sched, jenv, args, cb), "inner_env": jenv, "cwd": str(repo / "backend"), "config_path": str(cfg),
                     "expected_files": serial, "junit": serial_spec["_junit"], "stdout": serial_spec["_stdout"], "stderr": serial_spec["_stderr"],
                     "timeout": sched["timeouts_seconds"]["serial_job"], "container": None})
    planned = [f for j in jobs for f in j["expected_files"]]
    all_files = {e["source_file"] for e in entries}
    problems = ([f"DUPLICATE_ASSIGNMENT {f}" for f in sorted({f for f in planned if planned.count(f) != 1})]
                + [f"UNASSIGNED {f}" for f in sorted(all_files - set(planned))]
                + [f"NOT_IN_MANIFEST {f}" for f in sorted(set(planned) - all_files)])
    forb = {"phpunit": sched["phpunit"]["forbidden_args"], "vitest": sched["vitest"]["forbidden_args"]}
    allowed_php_extra = {"--colors=never", "--do-not-cache-result", "--display-skipped", "--display-incomplete"}
    unknown_extra = [x for x in sched["phpunit"]["extra_args"] if x not in allowed_php_extra]
    unknown_extra += list(sched["vitest"]["extra_args"])  # report paths/selection are generated, never caller-overridden
    filters = [f"unapproved extra argument: {x}" for x in unknown_extra] + [f"{j['id']}: {t}" for j in jobs for t in forbidden_args_in(
        [x for x in j["argv"] if not x.startswith("PC_")], forb["vitest" if j["kind"] == "vitest" else "phpunit"])]
    return {"run_id": run_id, "out_dir": str(out_dir), "repo": str(repo), "manifest_digest": manifest["digest"], "workers": workers,
            "serial": serial_spec, "jobs": jobs, "filters_applied": filters, "expected_total_files": len(all_files),
            "assignment_problems": problems, "adapters": {"php": pa["mode"], "node": sched["adapters"]["node"]["mode"]},
            "path_map": {"host_backend": str(pm.host_backend), "container_backend": pm.container_backend}}


def child_env(sched: dict) -> dict:
    """Scrubbed environment of the launched CLI (docker/npm client): whitelisted parent variables only.
    Worker settings are delivered with `docker exec -e` (adapter docker-exec) — never inherited from the shell."""
    return {k: os.environ[k] for k in sched["inherit_env"] if k in os.environ}


def materialise(plan: dict, repo: Path, sched: dict) -> None:
    """Stage B only: create per-worker dirs + generated PHPUnit configs inside the run output directory."""
    out = Path(plan["out_dir"])
    pa = sched["adapters"]["php"]
    pm = PathMap(repo / "backend", None if pa["mode"] == "host" else pa["container_backend"])
    (out / "junit").mkdir(parents=True, exist_ok=True)
    (out / "discovery").mkdir(parents=True, exist_ok=True)
    for spec in [*plan["workers"], plan["serial"]]:
        d = Path(spec["dir"])
        for sub in ("logs", "artifacts", "tmp", "storage/framework/cache/data", "storage/framework/sessions", "storage/framework/views",
                    "storage/framework/testing", "storage/logs", "storage/app/public", "storage/app/private", "bootstrap-cache"):
            (d / sub).mkdir(parents=True, exist_ok=True)
    for job in plan["jobs"]:
        if job["config_path"]:
            spec = plan["serial"] if job["lane"] == "serial" else plan["workers"][job["worker"] - 1]
            forced = {k: spec["env"][k] for k in FORCED_KEYS if k in spec["env"]}
            Path(job["config_path"]).write_text(render_phpunit_config(repo, job["expected_files"], forced, pm))
