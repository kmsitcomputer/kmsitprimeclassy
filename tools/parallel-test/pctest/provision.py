"""Worker-database provisioning — DESIGN ONLY. Default = print SQL. Executing needs explicit Owner flags AND a typed phrase.
Never creates/alters/drops anything outside primeclassy_testing_w1..w5. Never touches primeclassy_testing (canonical) or primeclassy_db.
Privileges are a minimal explicit list on the worker database only (no *.*, no GRANT OPTION). Runs in the EXISTING MariaDB container;
the root secret is read INSIDE the container (never passed through the host shell or printed)."""
from __future__ import annotations

import re
import shlex
from pathlib import PurePosixPath

from .guards import check_database_name, check_host, raise_on_errors

WORKER_RE = re.compile(r"^primeclassy_testing_w[1-5]$")
CHARSET, COLLATION = "utf8mb4", "utf8mb4_unicode_ci"  # backend/config/database.php defaults


def names(n: int, pattern: str) -> list[str]:
    if not 1 <= n <= 5:
        raise ValueError("worker databases are limited to w1..w5 (TestDatabaseGuard::WORKER_DATABASES)")
    out = [pattern.format(N=i) for i in range(1, n + 1)]
    for o in out:
        raise_on_errors(check_database_name(o, "parallel"))
        if not WORKER_RE.fullmatch(o):
            raise ValueError("invalid worker database")
    return out


def _safe_ident(v: str, what: str) -> str:
    if not re.fullmatch(r"[A-Za-z0-9_.%:\-]+", v):
        raise ValueError(f"unsafe {what}: {v!r}")
    return v


def create_sql(dbs: list[str], user: str, user_host: str, privileges: list[str]) -> list[str]:
    user, user_host = _safe_ident(user, "user"), _safe_ident(user_host, "user host")
    allowed_privileges = {"SELECT", "INSERT", "UPDATE", "DELETE", "CREATE", "DROP", "ALTER", "INDEX", "REFERENCES", "CREATE TEMPORARY TABLES", "LOCK TABLES", "TRIGGER", "CREATE VIEW", "SHOW VIEW"}
    if not privileges or any(privilege not in allowed_privileges for privilege in privileges):
        raise ValueError("unapproved privilege in provisioning plan")
    stmts = []
    for d in dbs:
        if not WORKER_RE.fullmatch(d):
            raise ValueError("invalid worker database")
        stmts.append(f"CREATE DATABASE IF NOT EXISTS `{d}` CHARACTER SET {CHARSET} COLLATE {COLLATION};")
        grant_database = d.replace("_", "\\_")
        stmts.append(f"GRANT {', '.join(privileges)} ON `{grant_database}`.* TO '{user}'@'{user_host}';")
    stmts.append("FLUSH PRIVILEGES;")
    return stmts


LOCK_PREFIXES = ("primeclassy:parallelrun:", "primeclassy:testrun:")  # runner lease + TestRunLock (tests/Support/TestRunLock.php)
_FAIL = "(SELECT 1 UNION ALL SELECT 2)"  # "Subquery returns more than 1 row" => ERROR => the batch client aborts before any DROP


def cleanup_sql(dbs: list[str]) -> list[str]:
    """Lifecycle-coordinated cleanup in ONE server session (F06): every runner lease and every TestRunLock of every target
    database is taken with GET_LOCK(...,0) FIRST; if any is held by another session the statement raises and the batch client
    stops (no --force) before the first DROP. The locks are held across the DDL (no check-to-DDL race) and die with the
    session (no stale lock, nothing to delete). Worker names only, by regex; the canonical/DEV/production names can never match."""
    targets = [d for d in dbs if WORKER_RE.fullmatch(d)]
    if not targets or len(targets) != len(dbs):
        raise ValueError("cleanup targets must all be primeclassy_testing_w1..w5")
    stmts = [f"SELECT IF(GET_LOCK('{pre}{d}',0)=1,1,{_FAIL});" for d in targets for pre in LOCK_PREFIXES]
    stmts += [f"DROP DATABASE IF EXISTS `{d}`;" for d in targets]
    stmts.append("SELECT RELEASE_ALL_LOCKS();")
    return stmts


def exec_argv(container: str, secret_path: str) -> list[str]:
    """Reads the SQL on stdin; the root password is read inside the container from its secret file."""
    path = PurePosixPath(secret_path)
    if not path.is_absolute() or path.parent != PurePosixPath("/run/secrets") or ".." in path.parts:
        raise ValueError("root secret must be a file directly under /run/secrets")
    return ["docker", "exec", "-i", container, "sh", "-c", f'exec mariadb --batch --skip-column-names -uroot -p"$(cat {shlex.quote(secret_path)})"']
