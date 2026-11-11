"""Result aggregator. Fail-closed: never reports PASS unless every required job completed AND coverage reconciles."""
from __future__ import annotations

import xml.etree.ElementTree as ET
from pathlib import Path

from .common import SCHEMA_VERSION, dump_json, load_json
from .discover import php_actual_from_junit_case, php_expected_from_list_xml, reconcile, vitest_expected_from_list_json, vitest_identity

PASS, PASS_SKIPS, FAIL, INCOMPLETE = "PASS", "PASS_WITH_SKIPS", "FAIL", "INCOMPLETE"


def _norm(path: str, roots: list[str]) -> str:
    p = (path or "").replace("\\", "/")
    for marker in ("backend/tests/", "frontend/src/"):  # host-independent (e.g. Windows-recorded) absolute paths
        i = p.rfind(marker)
        if i >= 0 and not p.startswith(marker):
            return p[i:]
    for r in roots:
        r = r.rstrip("/") + "/"
        if p.startswith(r):
            return p[len(r):]
    return p[2:] if p.startswith("./") else p


def parse_junit(path: Path) -> list[dict]:
    """Returns testcases: {id, file, class, name, status, assertions, time, message}. Raises on malformed XML."""
    cases = []
    root = ET.parse(path).getroot()
    if root.tag not in ("testsuite", "testsuites"):
        raise ValueError("unrecognised JUnit root")
    for suite in root.iter("testsuite"):
        descendants = list(suite.iter("testcase"))
        counts = {"tests": len(descendants),
                  "errors": sum(c.find("error") is not None for c in descendants),
                  "failures": sum(c.find("failure") is not None for c in descendants)}
        for attr, actual in counts.items():
            if suite.get(attr) is not None and int(suite.get(attr)) != actual:
                raise ValueError(f"JUnit {attr} does not match testcase elements")

    def walk(node, suite_file):
        for el in node:
            if el.tag == "testsuite":
                walk(el, el.get("file") or (el.get("name") if (el.get("name") or "").endswith((".ts", ".js", ".php")) else None) or suite_file)
            elif el.tag == "testcase":
                st, msg = "pass", ""
                for ch in el:
                    if ch.tag in ("failure",):
                        st, msg = "fail", (ch.get("message") or ch.text or "")[:2000]
                    elif ch.tag == "error":
                        st, msg = "error", (ch.get("message") or ch.text or "")[:2000]
                    elif ch.tag == "skipped" and st == "pass":
                        st, msg = "skip", (ch.get("message") or ch.text or "")[:500]
                cls = el.get("classname") or el.get("class") or ""
                cases.append({"id": f"{cls}::{el.get('name')}", "file": el.get("file") or suite_file or cls, "class": el.get("class") or cls,
                              "name": el.get("name"), "status": st, "assertions": int(el.get("assertions") or 0),
                              "time": float(el.get("time") or 0), "message": msg})
    walk(root, None)
    return cases


def aggregate(out_dir: Path, manifest: dict, plan: dict) -> dict:
    out_dir = Path(out_dir)
    entries = {e["source_file"]: e for e in manifest["entries"]}
    roots = [plan["repo"]]
    problems: list[dict] = []
    jobs_out, all_ids, per_file = [], {}, {}
    totals = {"pass": 0, "fail": 0, "error": 0, "skip": 0, "assertions": 0}
    failures, skips = [], []
    discovered_total, discovery_ok = 0, True

    def prob(code, msg, severity="incomplete"):
        problems.append({"code": code, "severity": severity, "message": msg})

    try:
        completion = load_json(out_dir / "STOPPED.json")
        if completion.get("run_id") != plan["run_id"] or completion.get("verified_stopped") is not True:
            raise ValueError("wrong termination record")
    except Exception:
        prob("TERMINATION_UNVERIFIED", "no matching verified process-termination record; cannot report PASS")
    terminal = None
    try:
        if (out_dir / "BLOCKED.json").exists():
            terminal = "BLOCKED"
        elif (out_dir / "RUNNER_ERROR.json").exists():
            terminal = "FAIL"
        if (out_dir / "run.json").is_file():
            run_record = load_json(out_dir / "run.json")
            if not isinstance(run_record, dict) or run_record.get("run_id") != plan["run_id"] or type(run_record.get("exit_code")) is not int or run_record["exit_code"] not in (0, 1, 3, 4, 130):
                raise ValueError("wrong run identity or exit code")
            if run_record.get("exit_code") in (1, 4, 130):
                terminal = {1: "FAIL", 4: "BLOCKED", 130: "ABORTED"}[run_record["exit_code"]]
    except Exception:
        prob("INVALID_RUN_STATUS", "run metadata is malformed")
    if terminal:
        prob("RUN_TERMINAL_STATE", terminal, "fail")
    from collections import Counter
    if len(entries) != len(manifest["entries"]):
        prob("DUPLICATE_MANIFEST_FILE", "manifest source files are not unique")
    if not entries or not plan["jobs"]:
        prob("EMPTY_INVENTORY_OR_PLAN", "an empty inventory or execution plan cannot establish full coverage")
    planned = Counter(f for job in plan["jobs"] for f in job["expected_files"])
    if set(planned) != set(entries) or any(count != 1 for count in planned.values()):
        prob("PLAN_COVERAGE_MISMATCH", "jobs must independently assign every manifest file exactly once")
    if len({job["id"] for job in plan["jobs"]}) != len(plan["jobs"]):
        prob("DUPLICATE_JOB_ID", "job/status identities are not unique")
    statuses = []
    if plan.get("assignment_problems"):
        for m in plan["assignment_problems"]:
            prob("INVENTORY_ASSIGNMENT", m)
    if plan.get("filters_applied"):
        prob("FILTERED_RUN", f"filters applied: {plan['filters_applied']}")
    if plan["manifest_digest"] != manifest["digest"]:
        prob("MANIFEST_DIGEST_MISMATCH", "plan was built from a different manifest")

    for job in plan["jobs"]:
        jo = {"job_id": job["id"], "kind": job["kind"], "phase": job["phase"], "worker": job["worker"], "lane": job["lane"],
              "expected_files": len(job["expected_files"]), "junit": job["junit"], "exit_code": None, "duration_seconds": None,
              "executed": 0, "state": "OK"}
        sp = out_dir / "status" / f"{job['id']}.json"
        if not sp.is_file():
            jo["state"] = "NO_STATUS (job never started or runner died)"
            prob("JOB_NOT_RUN", f"{job['id']}: no status file")
            jobs_out.append(jo)
            continue
        try:
            st = load_json(sp)
            if not isinstance(st, dict) or st.get("job_id") != job["id"]:
                raise ValueError("wrong or missing job identity")
            if type(st.get("exit_code")) is not int or st["exit_code"] < 0:
                raise ValueError("invalid exit code")
            if type(st.get("aborted")) is not bool or type(st.get("timed_out")) is not bool:
                raise ValueError("missing terminal flags")
        except Exception as exc:
            jo["state"] = "INVALID_STATUS"
            prob("INVALID_JOB_STATUS", f"{job['id']}: {exc}")
            jobs_out.append(jo)
            continue
        statuses.append(st)
        jo.update(exit_code=st.get("exit_code"), duration_seconds=st.get("duration_seconds"), signal=st.get("signal"),
                  timed_out=st.get("timed_out"), aborted=st.get("aborted"))
        if st.get("aborted") or st.get("timed_out") or st.get("exit_code") is None:
            jo["state"] = "ABORTED/TIMEOUT"
            prob("WORKER_CRASH_OR_TIMEOUT", f"{job['id']}: aborted={st.get('aborted')} timed_out={st.get('timed_out')} exit={st.get('exit_code')}", "fail")
        elif st["exit_code"] != 0:
            jo["state"] = f"EXIT_{st['exit_code']}"
            prob("NONZERO_EXIT", f"{job['id']}: exit code {st['exit_code']}", "fail")
        jp = Path(job["junit"])
        if not jp.is_file():
            jo["state"] += " + MISSING_REPORT"
            prob("MISSING_RESULT_FILE", f"{job['id']}: {jp} not written", "incomplete")
            jobs_out.append(jo)
            continue
        try:
            cases = parse_junit(jp)
        except Exception as e:  # report parsing failure => fail closed
            jo["state"] += " + REPORT_PARSE_ERROR"
            prob("REPORT_PARSE_ERROR", f"{job['id']}: {e}")
            jobs_out.append(jo)
            continue
        jo["executed"] = len(cases)
        expected = set(job["expected_files"])
        seen_files: dict[str, int] = {}
        for c in cases:
            rel = _norm(c["file"], roots)
            if rel not in entries:  # vitest reports paths relative to frontend/, phpunit may report backend-relative ones
                rel = next((x for x in (f"frontend/{rel}", f"backend/{rel}") if x in entries), rel)
            if rel not in entries:  # try the class->file fallback
                rel = next((e["source_file"] for e in entries.values() if e["class"] and e["class"] == c["class"]), rel)
            c["rel"] = rel
            if rel not in entries:
                prob("UNKNOWN_TEST_FILE", f"{job['id']}: result for {rel!r} which is not in the manifest (new/unmanifested test)")
            elif rel not in expected:
                prob("WRONG_JOB_FILE", f"{job['id']}: ran {rel} which is assigned elsewhere (duplicate execution risk)")
            seen_files[rel] = seen_files.get(rel, 0) + 1
            if c["id"] in all_ids:
                prob("DUPLICATE_TEST_RESULT", f"{c['id']} reported by {all_ids[c['id']]} and {job['id']}")
            all_ids[c["id"]] = job["id"]
            k = {"pass": "pass", "fail": "fail", "error": "error", "skip": "skip"}[c["status"]]
            totals[k] += 1
            totals["assertions"] += c["assertions"]
            if c["status"] in ("fail", "error"):
                failures.append({"job": job["id"], "test": c["id"], "status": c["status"], "message": c["message"]})
            if c["status"] == "skip":
                skips.append({"job": job["id"], "test": c["id"], "reason": c["message"]})
        # ---- F02: mandatory runtime-discovery reconciliation (identity sets, not counts) ----
        dpath = job.get("discovery")
        disc = None
        if not dpath or not Path(dpath).is_file():
            jo["state"] += " + NO_DISCOVERY"
            prob("DISCOVERY_MISSING", f"{job['id']}: runtime discovery output missing; coverage cannot be proven")
        else:
            try:
                exp = php_expected_from_list_xml(Path(dpath)) if job["kind"] != "vitest" else vitest_expected_from_list_json(Path(dpath))
                act = ([php_actual_from_junit_case(c["class"], c["name"]) for c in cases] if job["kind"] != "vitest"
                       else [vitest_identity(c["file"], c["name"]) for c in cases])
                disc = reconcile(exp, act)
                jo["discovered"] = disc["expected"]
                discovered_total += disc["expected"]
                for k, code in (("missing", "TESTCASE_MISSING"), ("unexpected", "TESTCASE_UNEXPECTED"),
                                ("duplicate_expected", "TESTCASE_DUPLICATE_IN_DISCOVERY"), ("duplicate_actual", "TESTCASE_DUPLICATE_RESULT")):
                    for ident in disc[k][:200]:
                        prob(code, f"{job['id']}: {ident}")
                    if len(disc[k]) > 200:
                        prob(code, f"{job['id']}: … {len(disc[k]) - 200} more")
            except Exception as e:  # discovery output unparseable => fail closed
                jo["state"] += " + DISCOVERY_PARSE_ERROR"
                prob("DISCOVERY_PARSE_ERROR", f"{job['id']}: {e}")
        if disc is None:
            discovery_ok = False
        elif disc["missing"] or disc["unexpected"] or disc["duplicate_expected"] or disc["duplicate_actual"]:
            discovery_ok = False
        for f in sorted(expected):
            e = entries.get(f)
            got = seen_files.get(f, 0)
            per_file[f] = {"executed": got, "static_lower_bound": e["static_test_methods"] if e else None, "job": job["id"]}
            if got == 0:
                prob("EXPECTED_TEST_NO_RESULT", f"{job['id']}: {f} produced no testcase result")
            elif e and got < e["static_test_methods"]:
                prob("FEWER_RESULTS_THAN_STATIC_LOWER_BOUND", f"{f}: executed {got} < static lower bound {e['static_test_methods']} (informational: static counts are never PASS evidence)", "info")
            elif e and not e["has_datasets"] and e["count_confidence"] == "static_exact_unverified" and got != e["static_test_methods"]:
                prob("COUNT_DIFFERS_FROM_STATIC", f"{f}: executed {got} != static {e['static_test_methods']} (static is a lower bound; informational)", "info")
        jobs_out.append(jo)

    if any(p_["severity"] in ("incomplete", "fail") for p_ in problems):
        discovery_ok = False
    executed = sum(totals[k] for k in ("pass", "fail", "error", "skip"))
    reconciled = not [p for p in problems if p["severity"] in ("incomplete", "fail")]
    fail_cond = totals["fail"] or totals["error"] or any(p["severity"] == "fail" for p in problems)
    verdict = terminal or (FAIL if fail_cond else (INCOMPLETE if not reconciled else (PASS_SKIPS if totals["skip"] else PASS)))
    starts = [s["started_at"] for s in statuses if isinstance(s.get("started_at"), str)]
    ends = [s["ended_at"] for s in statuses if isinstance(s.get("ended_at"), str)]
    summary = {
        "schema_version": SCHEMA_VERSION, "verdict": verdict, "run_id": plan["run_id"], "manifest_digest": manifest["digest"],
        "inventory_completeness": "RECONCILED" if reconciled else "INCOMPLETE",
        "discovered_test_cases": discovered_total if discovery_ok else None,
        "discovered_test_cases_note": "From mandatory runtime discovery (PHPUnit --list-tests-xml / vitest list), reconciled by identity against JUnit. null when discovery is missing/unreconciled. Static manifest counts are never PASS evidence.",
        "static_lower_bound_cases": manifest["counts"]["php_static_test_methods_lower_bound"] + manifest["counts"]["frontend_static_cases_lower_bound"],
        "executed_test_cases": executed, "pass": totals["pass"], "fail": totals["fail"], "error": totals["error"], "skip": totals["skip"],
        "assertions_reported": totals["assertions"], "wall_clock": {"first_start": min(starts) if starts else None, "last_end": max(ends) if ends else None},
        "jobs": jobs_out, "problems": problems, "failures": failures, "skipped_tests": skips, "per_file": per_file,
    }
    dump_json(out_dir / "summary.json", summary)
    lines = [f"# Parallel Full Test summary — {verdict}", "", f"- run: `{plan['run_id']}`  manifest: `{manifest['digest'][:16]}`",
             f"- inventory: **{summary['inventory_completeness']}**  executed: {executed} (pass {totals['pass']}, fail {totals['fail']}, error {totals['error']}, skip {totals['skip']})",
             f"- static lower bound of cases: {summary['static_lower_bound_cases']} (discovered total unknown until runtime)", "", "## Jobs"]
    lines += [f"- {j['job_id']}: {j['state']} exit={j['exit_code']} dur={j['duration_seconds']}s executed={j['executed']}" for j in jobs_out]
    lines += ["", "## Problems"] + ([f"- [{p['code']}] {p['message']}" for p in problems] or ["- none"])
    lines += ["", "## Failures"] + ([f"- {f['test']} ({f['job']}): {f['message'][:200]}" for f in failures] or ["- none"])
    lines += ["", f"## Skipped ({len(skips)})"] + [f"- {s['test']}: {s['reason'][:120]}" for s in skips[:200]]
    (out_dir / "summary.md").write_text("\n".join(lines) + "\n")
    return summary


def exit_code_for(verdict: str) -> int:
    return {PASS: 0, PASS_SKIPS: 0, FAIL: 1, INCOMPLETE: 3, "BLOCKED": 4, "ABORTED": 130}.get(verdict, 3)
