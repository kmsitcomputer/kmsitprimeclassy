"""Shared helpers. Standard library only (no third-party dependencies)."""
from __future__ import annotations

import hashlib
import json
import re
from pathlib import Path

SCHEMA_VERSION = 1
STAGE_B_PHRASE = "RUN PRIMECLASSY PARALLEL FULL TEST"
PROVISION_PHRASE = "CREATE PRIMECLASSY TEST WORKER DATABASES"
CLEANUP_PHRASE = "DROP PRIMECLASSY TEST WORKER DATABASES"
PREPARE_PHRASE = "PREPARE PRIMECLASSY LINUX FRONTEND DEPENDENCIES"
ROOT = Path(__file__).resolve().parent.parent


def load_json(path: Path | str):
    with open(path, encoding="utf-8") as fh:
        return json.load(fh)


def dump_json(path: Path | str, data) -> None:
    Path(path).parent.mkdir(parents=True, exist_ok=True)
    with open(path, "w", encoding="utf-8") as fh:
        json.dump(data, fh, indent=2, sort_keys=False, ensure_ascii=False)
        fh.write("\n")


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with open(path, "rb") as fh:
        for chunk in iter(lambda: fh.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def canonical_digest(obj) -> str:
    return sha256_bytes(json.dumps(obj, sort_keys=True, separators=(",", ":")).encode())


_SECRET_KEY = re.compile(r"(PASSWORD|SECRET|TOKEN|KEY)", re.I)


def redact_env(env: dict) -> dict:
    return {k: ("<redacted>" if _SECRET_KEY.search(k) else v) for k, v in env.items()}


def read_dotenv(path: Path) -> dict:
    """Minimal read-only .env parser (no interpolation). Values are never logged by callers."""
    out: dict = {}
    if not path.is_file():
        return out
    for line in path.read_text(encoding="utf-8", errors="replace").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        out[k.strip()] = v.strip().strip("\"'")
    return out


def incomplete_runs(repo: Path, out_root_rel: str) -> list[str]:
    """A crashed runner may have lost its lease while descendants live. Only verified termination clears this barrier."""
    if out_root_rel != "backend/storage/framework/testing/parallel":
        raise ValueError("run history root is fixed to the testing workspace")
    root = (repo / out_root_rel).resolve()
    import os
    if root.is_symlink():
        raise ValueError("run history must not be a symlink")
    if not root.exists():
        return []
    blocked = []
    # scandir errors propagate; unreadable state must never masquerade as an empty history.
    with os.scandir(root) as entries:
        directories = [Path(entry.path) for entry in entries if entry.name.startswith("run-")]
    for directory in directories:
        if not directory.is_dir():
            continue
        try:
            record = load_json(directory / "STOPPED.json")
            if record.get("run_id") != directory.name.removeprefix("run-") or record.get("verified_stopped") is not True:
                raise ValueError("invalid completion record")
        except Exception:
            blocked.append(directory.name)
    return sorted(blocked)


def dependency_tree_digest(root: Path) -> str:
    """Fingerprint the prepared tree; reject symlinks escaping it. No package code is imported/executed."""
    if root.is_symlink():
        raise ValueError("dependency tree root must not be a symlink")
    root = root.resolve()
    if not root.is_dir():
        raise ValueError("dependency tree missing")
    records = []
    for path in sorted(root.rglob("*")):
        path.resolve().relative_to(root)
        if path.is_symlink():
            records.append((path.relative_to(root).as_posix(), "link", str(path.readlink())))
        elif path.is_file():
            records.append((path.relative_to(root).as_posix(), "file", sha256_file(path)))
    if not records:
        raise ValueError("dependency tree empty")
    return canonical_digest(records)


from contextlib import contextmanager


@contextmanager
def dependency_lock(repo: Path, relative: str):
    """Owner-only call sites. Serialize preparation and execution without inheriting the fd into children."""
    import fcntl
    import os
    root = (repo / "backend/storage/framework/testing").resolve()
    directory = (repo / relative).resolve()
    directory.relative_to(root)
    root.mkdir(parents=True, exist_ok=True)
    descriptor = os.open(root / ".parallel-dependencies.lock", os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    try:
        fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
        yield
    finally:
        os.close(descriptor)
