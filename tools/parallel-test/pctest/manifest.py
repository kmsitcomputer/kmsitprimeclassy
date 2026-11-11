"""Static test inventory + deterministic assignment. READS files only; never executes PHP/Node or any test."""
from __future__ import annotations

import re
import subprocess
from datetime import datetime, timezone
from pathlib import Path

from .common import ROOT, SCHEMA_VERSION, canonical_digest, dump_json, load_json, sha256_file

PHP_SUITES = (("Unit", "tests/Unit"), ("Feature", "tests/Feature"))
_PROVIDER = re.compile(r"#\[\s*(DataProvider|DataProviderExternal|TestWith|TestWithJson)\b|@dataProvider|@testWith")
_TEST_METHOD = re.compile(r"^\s*(?:public\s+)?function\s+(test\w*)\s*\(", re.M)
_ATTR_TEST = re.compile(r"(?:#\[\s*Test\s*\]|@test\b)[^{;]*?function\s+(\w+)\s*\(", re.S)
_NS = re.compile(r"^namespace\s+([^;]+);", re.M)
_CLASS = re.compile(r"^(abstract\s+)?(?:final\s+)?(?:readonly\s+)?class\s+(\w+)(?:\s+extends\s+([\\\w]+))?", re.M)
_VITEST_CASE = re.compile(r"\b(?:it|test)(?:\.(?:only|skip|todo|concurrent))?\s*\(")
_VITEST_EACH = re.compile(r"\b(?:it|test|describe)(?:\.\w+)?\.each\b|\bfor\s*\(.*\)\s*\{[^}]*\b(?:it|test)\s*\(")


def strip_comments(text: str) -> str:
    text = re.sub(r"/\*.*?\*/", "", text, flags=re.S)
    return "\n".join(l for l in text.splitlines() if not l.lstrip().startswith(("//", "#", "*")) or l.lstrip().startswith("#["))


def _git(repo: Path, *args: str) -> str | None:
    try:  # read-only git queries only
        return subprocess.run(["git", "-C", str(repo), *args], capture_output=True, text=True, timeout=30).stdout.strip() or None
    except Exception:
        return None


def scan_backend(repo: Path, rules: dict, timing: dict) -> tuple[list[dict], list[str], list[dict]]:
    backend = repo / "backend"
    entries, warnings, excluded = [], [], []
    seen_dirs = set()
    for suite, rel in PHP_SUITES:
        seen_dirs.add((backend / rel).resolve())
        for p in sorted((backend / rel).rglob("*Test.php")):
            relp = p.relative_to(repo).as_posix()
            text = p.read_text(encoding="utf-8", errors="replace")
            ns, cm = _NS.search(text), _CLASS.search(text)
            if not cm:
                warnings.append(f"UNPARSED_CLASS {relp}")
                fqcn, abstract, parent = None, False, None
            else:
                abstract = bool(cm.group(1))
                fqcn = (ns.group(1).strip() + "\\" if ns else "") + cm.group(2)
                parent = cm.group(3)
            if abstract:
                excluded.append({"source_file": relp, "reason": "abstract class (PHPUnit does not run it)"})
                continue
            methods = set(_TEST_METHOD.findall(text)) | set(_ATTR_TEST.findall(text))
            has_ds = bool(_PROVIDER.search(text))
            lower_bound = has_ds or (parent not in (None, "TestCase", "\\Tests\\TestCase", "PHPUnit\\Framework\\TestCase"))
            body = strip_comments(text)
            reasons = [{"rule": r["id"], "reason": r["reason"]} for r in rules["rules"]
                       if re.search(r["regex"], body if r.get("strip_comments") else text)]
            if relp in rules.get("path_overrides", {}):
                reasons.append({"rule": "OVERRIDE", "reason": f"path_overrides -> {rules['path_overrides'][relp]}"})
                if rules["path_overrides"][relp] == "parallel":
                    reasons = []
            w, src = timing.get(fqcn or "", (None, None))
            if w is None:
                w, src = max(1.0, 0.5 * len(methods)), "heuristic: max(1, 0.5 x static test methods)"
            entries.append({
                "test_id": f"php:{relp}", "framework": "phpunit", "suite": suite, "source_file": relp, "class": fqcn,
                "lane": "serial" if reasons else "parallel", "serial_reasons": reasons, "worker": None,
                "isolation_requirements": (["canonical_database_exclusive", "no_other_runner_on_canonical_db"] if reasons else
                                           ["own_database", "own_storage_path", "own_tmpdir", "own_cache_and_session_namespace"]),
                "static_test_methods": len(methods), "count_confidence": "lower_bound" if lower_bound else "static_exact_unverified",
                "has_datasets": has_ds, "weight_seconds": round(float(w), 3), "weight_source": src,
                "sha256": sha256_file(p), "expected_result_artifact": None,
            })
    # Orphans: *Test.php outside the two configured suite directories
    for p in sorted(backend.glob("tests/**/*Test.php")):
        if not any(str(p.resolve()).startswith(str(d) + "/") for d in seen_dirs):
            warnings.append(f"ORPHAN_PHP_TEST_OUTSIDE_SUITES {p.relative_to(repo).as_posix()}")
    return entries, warnings, excluded


def scan_frontend(repo: Path, cfg: dict) -> tuple[list[dict], list[str], dict]:
    fe = repo / "frontend"
    vcfg = fe / "vitest.config.ts"
    warnings: list[str] = []
    m = re.search(r"include\s*:\s*\[([^\]]*)\]", vcfg.read_text(encoding="utf-8")) if vcfg.is_file() else None
    patterns = re.findall(r"['\"]([^'\"]+)['\"]", m.group(1)) if m else []
    if not patterns:
        warnings.append("VITEST_INCLUDE_NOT_PARSED (frontend inventory cannot be trusted)")
    files: set[Path] = set()
    for pat in patterns:
        files |= {p for p in fe.glob(pat) if p.is_file() and "node_modules" not in p.parts}
    all_specs = {p for p in fe.rglob("*") if p.is_file() and re.search(r"\.(spec|test)\.[cm]?[jt]sx?$", p.name)
                 and not ({"node_modules", "dist"} & set(p.parts))}
    for p in sorted(all_specs - files):
        warnings.append(f"ORPHAN_FRONTEND_TEST_OUTSIDE_VITEST_INCLUDE {p.relative_to(repo).as_posix()}")
    entries = []
    for p in sorted(files):
        relp = p.relative_to(repo).as_posix()
        text = p.read_text(encoding="utf-8", errors="replace")
        n = len(_VITEST_CASE.findall(text))
        param = bool(_VITEST_EACH.search(text))
        entries.append({
            "test_id": f"vitest:{relp}", "framework": "vitest", "suite": "frontend", "source_file": relp, "class": None,
            "lane": "parallel", "serial_reasons": [], "worker": None,
            "isolation_requirements": ["no_database", "jsdom_per_file_isolation (vitest default)", "single_invocation_no_sharding"],
            "static_test_methods": n, "count_confidence": "lower_bound", "has_datasets": param,
            "weight_seconds": 0.0, "weight_source": "frontend unit weight assigned below", "sha256": sha256_file(p),
            "expected_result_artifact": None,
        })
    return entries, warnings, {"include_patterns": patterns, "config_sha256": sha256_file(vcfg) if vcfg.is_file() else None}


def assign(items: list[tuple[str, float]], n: int) -> dict[str, int]:
    """Deterministic LPT: heaviest first (ties by key), always onto the least-loaded worker (ties by lowest index)."""
    load = [0.0] * n
    out: dict[str, int] = {}
    for key, w in sorted(items, key=lambda kv: (-kv[1], kv[0])):
        i = min(range(n), key=lambda j: (load[j], j))
        out[key] = i + 1
        load[i] += w
    return out


def load_timing(path: Path | None) -> tuple[dict, str | None]:
    if not path:
        return {}, None
    d = load_json(path)
    classes = d.get("classes") or {}
    rid = d.get("run_id", "unknown")
    return {k: (float(v.get("seconds", 0.0)), f"evidence: run {rid} stats ({path.name})") for k, v in classes.items()}, rid


def build_manifest(repo: Path, workers: int, timing_file: Path | None, sched: dict, rules: dict) -> dict:
    timing, _ = load_timing(timing_file)
    be, w1, excluded = scan_backend(repo, rules, timing)
    fe, w2, fe_meta = scan_frontend(repo, sched)
    par = [e for e in be if e["lane"] == "parallel"]
    fe_weight = float(sched["vitest"]["estimated_seconds"]) if fe else 0.0
    items = [(e["test_id"], e["weight_seconds"]) for e in par] + ([("frontend:*", fe_weight)] if fe else [])
    amap = assign(items, workers)
    for e in par:
        e["worker"] = amap[e["test_id"]]
        e["expected_result_artifact"] = f"junit/worker-{e['worker']}.xml"
    for e in be:
        if e["lane"] == "serial":
            e["expected_result_artifact"] = "junit/serial.xml"
    for e in fe:
        e["worker"] = amap["frontend:*"]
        e["weight_seconds"] = round(fe_weight / len(fe), 3)
        e["expected_result_artifact"] = "junit/frontend.xml"
    entries = be + fe
    load = {str(i): round(sum(e["weight_seconds"] for e in entries if e["worker"] == i), 1) for i in range(1, workers + 1)}
    core = [{k: e[k] for k in ("test_id", "sha256", "lane", "worker")} for e in entries]
    return {
        "schema_version": SCHEMA_VERSION, "kind": "primeclassy-parallel-test-manifest",
        "generated_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        "repo_root": str(repo), "git_head": _git(repo, "rev-parse", "HEAD"),
        "git_dirty_paths": len((_git(repo, "status", "--porcelain") or "").splitlines()),
        "workers": workers, "timing_evidence": str(Path(timing_file).resolve()) if timing_file else None,
        "counts": {"php_files": len(be), "php_parallel_files": len(par), "php_serial_files": len(be) - len(par),
                   "frontend_files": len(fe), "php_static_test_methods_lower_bound": sum(e["static_test_methods"] for e in be),
                   "frontend_static_cases_lower_bound": sum(e["static_test_methods"] for e in fe),
                   "files_with_datasets_or_parameterised_cases": sum(1 for e in entries if e["has_datasets"])},
        "count_disclaimer": "Static counts are LOWER BOUNDS from text scanning. Datasets, parameterised cases and inherited tests are only known at runtime. They are NOT discovered test-case totals.",
        "estimated_parallel_load_seconds": load, "estimated_serial_lane_seconds": round(sum(e["weight_seconds"] for e in be if e["lane"] == "serial"), 1),
        "frontend": fe_meta, "excluded_abstract": excluded, "warnings": w1 + w2,
        "phpunit_config_sha256": sha256_file(repo / "backend/phpunit.xml") if (repo / "backend/phpunit.xml").is_file() else None,
        "entries": entries, "digest": canonical_digest({"workers": workers, "entries": core, "frontend": fe_meta}),
    }


def verify_manifest(manifest: dict, repo: Path, sched: dict, rules: dict) -> list[str]:
    """Re-inventory the live tree and report EVERY difference. Empty list == inventory matches."""
    fresh = build_manifest(repo, manifest["workers"], Path(manifest["timing_evidence"]) if manifest.get("timing_evidence") else None, sched, rules)
    old = {e["test_id"]: e for e in manifest["entries"]}
    new = {e["test_id"]: e for e in fresh["entries"]}
    out = [f"NEW_UNMANIFESTED_TEST {k}" for k in sorted(new.keys() - old.keys())]
    out += [f"MANIFEST_TEST_MISSING_FROM_TREE {k}" for k in sorted(old.keys() - new.keys())]
    for k in sorted(old.keys() & new.keys()):
        for fld in ("sha256", "lane", "worker"):
            if old[k][fld] != new[k][fld]:
                out.append(f"CHANGED_{fld.upper()} {k}")
    if manifest.get("phpunit_config_sha256") != fresh.get("phpunit_config_sha256"):
        out.append("CHANGED_PHPUNIT_XML")
    if manifest["digest"] != fresh["digest"] and not out:
        out.append("DIGEST_MISMATCH (config/frontend include changed)")
    return out


def write_manifest(path: Path, manifest: dict) -> None:
    dump_json(path, manifest)


def default_configs():
    return load_json(ROOT / "config/scheduler.json"), load_json(ROOT / "config/serialized-rules.json")
