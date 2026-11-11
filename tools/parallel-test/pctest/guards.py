"""Fail-closed safety guards. Pure functions: values in, findings out. No I/O, no DB access."""
from __future__ import annotations

import re
from dataclasses import dataclass

CANONICAL_DB = "primeclassy_testing"
WORKER_DB_RE = re.compile(r"^primeclassy_testing_w([1-5])$")
FORBIDDEN_EXACT = {
    "primeclassy", "primeclassy_db", "primeclassy_dev", "primeclassy_prod", "primeclassy_production",
    "sql_prime", "mysql", "sys", "information_schema", "performance_schema", "",
}
FORBIDDEN_SUBSTR = ("prod", "live", "dev", "staging", "backup")
DEFAULT_HOSTS = ("primeclassy-mariadb", "127.0.0.1", "localhost", "::1")  # mirrors TestDatabaseGuard::ALLOWED_HOSTS

REQUIRED_WORKER_ENV = (
    "APP_ENV", "DB_CONNECTION", "DB_HOST", "DB_PORT", "DB_DATABASE", "DB_USERNAME",
    "LARAVEL_STORAGE_PATH", "TMPDIR", "PRIMECLASSY_TEST_RUN_ID", "PRIMECLASSY_PARALLEL_WORKER",
    "CACHE_PREFIX", "SESSION_COOKIE", "REDIS_PREFIX", "CACHE_STORE", "SESSION_DRIVER", "QUEUE_CONNECTION",
    "APP_SERVICES_CACHE", "APP_PACKAGES_CACHE", "APP_CONFIG_CACHE", "APP_ROUTES_CACHE", "APP_EVENTS_CACHE",
)
BLANK_OVERRIDES = ("DB_URL", "DATABASE_URL", "DB_SOCKET")  # must be present AND empty
BOOTSTRAP_CACHE_VARS = ("APP_SERVICES_CACHE", "APP_PACKAGES_CACHE", "APP_CONFIG_CACHE", "APP_ROUTES_CACHE", "APP_EVENTS_CACHE")
# Values that must be pairwise-distinct across parallel workers.
UNIQUE_PER_WORKER = (
    "DB_DATABASE", "LARAVEL_STORAGE_PATH", "TMPDIR", "PRIMECLASSY_TEST_RUN_ID", "CACHE_PREFIX",
    "SESSION_COOKIE", "REDIS_PREFIX", "APP_SERVICES_CACHE", "APP_PACKAGES_CACHE", "APP_CONFIG_CACHE", "APP_ROUTES_CACHE", "APP_EVENTS_CACHE", "_junit", "_artifacts", "_stdout", "_stderr",
)


class GuardError(RuntimeError):
    pass


@dataclass
class Finding:
    level: str  # "error" | "warn"
    code: str
    message: str

    def as_dict(self):
        return {"level": self.level, "code": self.code, "message": self.message}


def check_database_name(name: str, lane: str, max_workers: int = 5) -> list[Finding]:
    f: list[Finding] = []
    if name in FORBIDDEN_EXACT:
        return [Finding("error", "DB_FORBIDDEN", f"database {name!r} is forbidden")]
    if lane == "serial":
        if name != CANONICAL_DB:
            f.append(Finding("error", "DB_NOT_CANONICAL", f"serial lane must use exactly {CANONICAL_DB!r}, got {name!r}"))
        return f
    m = WORKER_DB_RE.fullmatch(name or "")
    if not m:
        return [Finding("error", "DB_NOT_ALLOWLISTED", f"parallel worker database must match {WORKER_DB_RE.pattern}, got {name!r}")]
    if int(m.group(1)) > max_workers:
        f.append(Finding("error", "DB_INDEX_RANGE", f"worker index {m.group(1)} exceeds max_workers_allowed={max_workers}"))
    # Defence in depth: the allowlist regex already excludes these, kept explicit for auditability.
    if any(s in name for s in FORBIDDEN_SUBSTR):
        f.append(Finding("error", "DB_PRODLIKE", f"database {name!r} contains a production-like token"))
    return f


def check_host(host: str, port: str, allowed=DEFAULT_HOSTS) -> list[Finding]:
    f: list[Finding] = []
    if host not in allowed:
        f.append(Finding("error", "HOST_NOT_LOCAL", f"DB host {host!r} is not an approved local endpoint {list(allowed)}"))
    if str(port) != "3306":
        f.append(Finding("error", "PORT_INVALID", f"DB port {port!r} invalid"))
    return f


def check_worker_env(env: dict, lane: str, hosts=DEFAULT_HOSTS) -> list[Finding]:
    f: list[Finding] = []
    for k in REQUIRED_WORKER_ENV:
        if not env.get(k):
            f.append(Finding("error", "ENV_MISSING", f"required isolation variable {k} is absent/empty"))
    if env.get("APP_ENV") != "testing":
        f.append(Finding("error", "ENV_NOT_TESTING", f"APP_ENV must be exactly 'testing', got {env.get('APP_ENV')!r}"))
    if env.get("DB_CONNECTION") not in ("mysql", "mariadb"):
        f.append(Finding("error", "DB_CONNECTION", f"DB_CONNECTION {env.get('DB_CONNECTION')!r} not allowed"))
    for k in BLANK_OVERRIDES:
        if k not in env or env[k] != "":
            f.append(Finding("error", "CONNECTION_OVERRIDE_NOT_NEUTRALISED", f"{k} must be passed explicitly EMPTY (a URL/socket would silently change the effective DB target)"))
    from pathlib import PurePosixPath
    base = env.get("TMPDIR", "").rsplit("/tmp", 1)[0]
    for key in (*BOOTSTRAP_CACHE_VARS, "TMPDIR", "LARAVEL_STORAGE_PATH"):
        if ".." in PurePosixPath(env.get(key, "")).parts:
            f.append(Finding("error", "PATH_TRAVERSAL", f"{key} must not contain parent traversal"))
    for k in BOOTSTRAP_CACHE_VARS:
        v = env.get(k, "")
        if not v.startswith("/") or "/bootstrap/cache" in v.replace("\\", "/") or not base or not v.startswith(base + "/"):
            f.append(Finding("error", "BOOTSTRAP_CACHE_NOT_ISOLATED", f"{k}={v!r} must be an absolute per-run path inside {base!r}, never backend/bootstrap/cache"))
    f += check_database_name(env.get("DB_DATABASE", ""), lane)
    f += check_host(env.get("DB_HOST", ""), env.get("DB_PORT", ""), hosts)
    if env.get("CACHE_STORE") != "array" or env.get("SESSION_DRIVER") != "array" or env.get("QUEUE_CONNECTION") != "sync":
        f.append(Finding("error", "SHARED_STATE_DRIVER", "CACHE_STORE/SESSION_DRIVER must be 'array' and QUEUE_CONNECTION 'sync' (the repo's phpunit.xml semantics); any shared driver breaks isolation"))
    return f


def check_no_sharing(workers: list[dict]) -> list[Finding]:
    """workers: list of dicts with env + _junit/_artifacts/_stdout/_stderr. Refuse any shared mutable resource."""
    f: list[Finding] = []
    for key in UNIQUE_PER_WORKER:
        seen: dict = {}
        for w in workers:
            v = w["env"].get(key) if not key.startswith("_") else w.get(key)
            if v is None:
                continue
            if v in seen:
                f.append(Finding("error", "WORKER_SHARES_RESOURCE", f"{key}={v!r} shared by worker {seen[v]} and worker {w['index']}"))
            seen[v] = w["index"]
    return f


def check_inherited_env(parent_env: dict) -> list[Finding]:
    """Warn (children are scrubbed anyway) when the launching shell exports DB/APP variables."""
    return [Finding("warn", "PARENT_ENV_SCRUBBED", f"parent shell exports {k}; it is NOT inherited by workers")
            for k in sorted(parent_env) if k.startswith(("DB_", "APP_", "CACHE_", "SESSION_", "REDIS_", "QUEUE_"))]


def raise_on_errors(findings: list[Finding]) -> None:
    errs = [x for x in findings if x.level == "error"]
    if errs:
        raise GuardError("; ".join(f"[{e.code}] {e.message}" for e in errs))


def check_out_dir(out_dir, repo, out_root_rel: str) -> list[Finding]:
    """Stage B output must live under <repo>/backend/storage/framework/testing/parallel (container-visible, git-ignored)."""
    from pathlib import Path
    if out_root_rel != "backend/storage/framework/testing/parallel":
        return [Finding("error", "OUT_ROOT_INVALID", "output root is fixed to the testing workspace")]
    lexical = Path(repo) / out_root_rel
    for ancestor in [lexical, *lexical.parents]:
        if ancestor == Path(repo):
            break
        if ancestor.is_symlink():
            return [Finding("error", "OUT_ROOT_SYMLINK", "output workspace must not traverse symlinks")]
    root = lexical.resolve()
    target = Path(out_dir).resolve()
    if target.parent != root or not re.fullmatch(r"run-[a-f0-9]{12}", target.name):
        return [Finding("error", "OUT_DIR_NESTED", "run output must be a fresh direct run-<id> child of the testing output root")]
    try:
        Path(out_dir).resolve().relative_to(root)
    except ValueError:
        return [Finding("error", "OUT_DIR_OUTSIDE_WORKSPACE", f"--out-dir must be inside {root} (the only path the php container can see and git ignores)")]
    return []
