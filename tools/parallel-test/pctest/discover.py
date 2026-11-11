"""Runtime test-identity discovery parsing + normalisation (pure; parses files only, runs nothing).

Identity contract (what PASS requires):
  expected identities = what the tool itself lists BEFORE execution (PHPUnit --list-tests-xml / vitest list --json),
  actual identities   = what the JUnit report says was executed.
Both are normalised to the same canonical string. PASS needs set equality (per job), no duplicates, no extras.

PHPUnit 11.5 (verified in vendor source): list id  = "<Class>::<method>" + ("#<dataSetName>" when a data provider is used);
JUnit name = "<method>" or "<method> with data set #<int>" / '<method> with data set "<name>"'.
Canonical: "<Class>::<method>" + ("#<int>" | '"<name>"').
Vitest: list --json entries / JUnit testcase (classname=file, name=full title). Canonical: "<file>::<full name>". The vitest
formats are NOT verified against an installed run; any difference yields INCOMPLETE (fail closed), never PASS.
"""
from __future__ import annotations

import json
import re
import xml.etree.ElementTree as ET
from pathlib import Path

_DS_JUNIT = re.compile(r'^(?P<m>.+?) with data set (?:#(?P<i>\d+)|"(?P<s>.*)")$', re.S)


def _canon_php(cls: str, method: str, dataset: str | None) -> str:
    if dataset is None:
        return f"{cls}::{method}"
    return f"{cls}::{method}#{dataset}" if re.fullmatch(r"\d+", dataset) else f'{cls}::{method}"{dataset}"'


def php_expected_from_list_xml(path: Path) -> list[str]:
    """Parses `phpunit --list-tests-xml`. Raises on malformed/empty output (caller => discovery error)."""
    root = ET.parse(path).getroot()
    ns = {"t": "https://xml.phpunit.de/testSuite"}
    out: list[str] = []
    for cls in root.iterfind(".//t:testClass", ns):
        for m in cls.iterfind("t:testMethod", ns):
            ident = m.get("id") or ""
            head, _, ds = ident.partition("#")
            clsname, _, meth = head.partition("::")
            if not clsname or not meth:
                raise ValueError(f"unparseable test id {ident!r}")
            out.append(_canon_php(clsname, meth, ds if "#" in ident else None))
    if not out:
        raise ValueError("discovery listed zero tests")
    return out


def php_actual_from_junit_case(cls: str, name: str) -> str:
    m = _DS_JUNIT.match(name or "")
    if not m:
        return _canon_php(cls, name, None)
    return _canon_php(cls, m.group("m"), m.group("i") if m.group("i") is not None else m.group("s"))


def vitest_expected_from_list_json(path: Path) -> list[str]:
    data = json.loads(Path(path).read_text())
    if isinstance(data, dict):
        data = data.get("tests") or data.get("files") or []
    out = []
    for e in data:
        if not isinstance(e, dict) or "file" not in e or "name" not in e:
            raise ValueError(f"unrecognised vitest list entry: {str(e)[:80]}")
        out.append(vitest_identity(e["file"], e["name"]))
    if not out:
        raise ValueError("discovery listed zero tests")
    return out


def vitest_identity(file: str, name: str) -> str:
    f = file.replace("\\", "/")
    i = f.rfind("/src/")
    f = f[i + 1:] if i >= 0 else f.lstrip("./")
    return f"{f}::{name}"


def reconcile(expected: list[str], actual: list[str]) -> dict:
    """Pure set arithmetic. Any non-empty key other than counts => coverage NOT proven."""
    from collections import Counter
    e, a = Counter(expected), Counter(actual)
    return {
        "expected": len(expected), "actual": len(actual),
        "missing": sorted(set(e) - set(a)),
        "unexpected": sorted(set(a) - set(e)),
        "duplicate_expected": sorted(k for k, v in e.items() if v > 1),
        "duplicate_actual": sorted(k for k, v in a.items() if v > 1),
    }
