#!/usr/bin/env python3
"""Laravel ↔ Go contract comparison for the public read surface.

Runs the same requests against the Laravel reference implementation and the Go service (both pointed at the
same database and the same APP_URL) and diffs the normalised JSON.

It compares MEANING, not bytes: object key order is irrelevant, and nothing is "approved" by simply being
close — a mismatch is printed with the exact JSON path that differs.

Usage:
    LARAVEL_URL=http://127.0.0.1:8899 GO_URL=http://127.0.0.1:8082 \
        python3 tools/parity/compare_public_api.py

Exit code 0 only when every compared endpoint matches.
"""
import json
import os
import sys
import urllib.error
import urllib.request

LARAVEL = os.environ.get("LARAVEL_URL", "http://127.0.0.1:8899").rstrip("/")
GO = os.environ.get("GO_URL", "http://127.0.0.1:8082").rstrip("/")

# Public GET endpoints this build implements. Paths are identical for both runtimes.
PATHS = [
    "/api/v1/settings",
    "/api/v1/languages",
    "/api/v1/categories",
    "/api/v1/products",
    "/api/v1/products?per_page=1&page=2",
    "/api/v1/products?sort=price_asc",
    "/api/v1/products?search=lumpur",
    "/api/v1/products?category=kue",
    "/api/v1/products?min_price=100000",
    "/api/v1/products/kue-lumpur",
    "/api/v1/products/kue-ulang-tahun",
    "/api/v1/articles",
    "/api/v1/articles?type=article",
    "/api/v1/articles/tips-kue-imp010",
    "/api/v1/pages/tentang-kami-imp010",
    "/api/v1/homepage",
    "/api/v1/agents",
    "/api/v1/regions/provinces",
    "/api/v1/regions/regencies?province_id=32",
    "/api/v1/regions/districts?regency_id=3273",
    "/api/v1/regions/villages?district_id=327301",
    # Negative cases: the status code AND the envelope must agree.
    "/api/v1/products/tidak-ada",
    "/api/v1/articles/tidak-ada",
    "/api/v1/pages/tidak-ada",
    "/api/v1/regions/regencies?province_id=99",
    "/api/v1/products?stock_source=bogus",
]

# Fields whose value legitimately differs between two independent runtimes and carries no contract meaning.
IGNORED_PATHS = {
    # Laravel's default locale message differs only if the catalogues drift; they are asserted separately by
    # internal/i18n. Nothing else is ignored: a mismatch must be investigated, not silenced.
}


def fetch(base, path):
    req = urllib.request.Request(base + path, headers={"Accept": "application/json", "X-Locale": "id"})
    try:
        with urllib.request.urlopen(req, timeout=20) as res:
            return res.status, json.loads(res.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        body = e.read().decode("utf-8")
        try:
            return e.code, json.loads(body)
        except json.JSONDecodeError:
            return e.code, {"_non_json_body": body}


def walk(a, b, path="", out=None):
    """Collect the leaf-level differences between two JSON values."""
    if out is None:
        out = []
    if type(a) is not type(b) and not (isinstance(a, (int, float)) and isinstance(b, (int, float))):
        out.append(f"{path or '$'}: type {type(a).__name__} vs {type(b).__name__} ({a!r} vs {b!r})")
        return out
    if isinstance(a, dict):
        for key in sorted(set(a) | set(b)):
            child = f"{path}.{key}" if path else key
            if key not in a:
                out.append(f"{child}: missing in Laravel")
            elif key not in b:
                out.append(f"{child}: missing in Go")
            else:
                walk(a[key], b[key], child, out)
        return out
    if isinstance(a, list):
        if len(a) != len(b):
            out.append(f"{path or '$'}: list length {len(a)} vs {len(b)}")
            return out
        for i, (x, y) in enumerate(zip(a, b)):
            walk(x, y, f"{path}[{i}]", out)
        return out
    if a != b:
        out.append(f"{path or '$'}: {a!r} vs {b!r}")
    return out


def main():
    print(f"Laravel: {LARAVEL}\nGo:      {GO}\n")
    failures = 0
    for path in PATHS:
        ls, lbody = fetch(LARAVEL, path)
        gs, gbody = fetch(GO, path)
        if ls != gs:
            print(f"FAIL {path}\n  status {ls} vs {gs}")
            failures += 1
            continue
        diffs = walk(lbody, gbody)
        diffs = [d for d in diffs if not any(d.startswith(p) for p in IGNORED_PATHS)]
        if diffs:
            failures += 1
            print(f"FAIL {path} (status {ls} both)")
            for d in diffs[:12]:
                print(f"   {d}")
            if len(diffs) > 12:
                print(f"   … {len(diffs) - 12} more")
        else:
            print(f"PASS {path} (status {ls})")

    print(f"\n{len(PATHS) - failures} passed, {failures} failed")
    return 0 if failures == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
