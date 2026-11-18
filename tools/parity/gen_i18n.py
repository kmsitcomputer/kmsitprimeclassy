#!/usr/bin/env python3
"""Generate the Go message catalogue from the Laravel lang files.

The Go service returns a small set of human-readable messages (401/403/404/419/422/500 and the auth
messages). They must be byte-identical to what Laravel returns for the same locale, otherwise the
frontend shows different text depending on which runtime answered.

This script extracts exactly the keys the Go service uses and writes
`go-backend/internal/i18n/catalog.json`. A Go test (`TestCatalogMatchesLaravelMessages`) re-runs the same
extraction and fails if the committed catalogue is stale, so drift is caught in CI rather than in UAT.

Usage: python3 tools/parity/gen_i18n.py
"""
import json
import os
import re
import sys

# Keys the Go implementation can emit. Keep in sync with internal/i18n/i18n.go.
KEYS = {
    "system": [
        "validation_failed",
        "unauthorized_action",
        "please_login",
        "not_found",
        "endpoint_not_found",
        "generic_error",
        "server_error",
        "agent_not_linked",
        "field_required",
        "field_invalid",
    ],
    "auth": [
        "login_failed",
        "register_success",
        "login_success",
        "logout_success",
        "registration_unavailable",
    ],
    "stock": [
        "agent_id_required",
        "invalid_agent",
    ],
    "profile": [
        "current_password_incorrect",
        "password_updated",
    ],
    "referral": [
        "not_linked_to_agent",
        "invalid_code",
        "code_not_found",
    ],
}

# Laravel ships these rules in lang/<locale>/validation.php. The Go service reproduces the exact sentences for
# the rules it enforces, so a 422 body is identical across runtimes.
VALIDATION_KEYS = ["in", "exists", "required"]

LOCALES = ["id", "en", "ar", "zh"]


def block(src: str, name: str) -> str:
    """Return the body of `'name' => [ ... ]` (bracket-balanced)."""
    i = src.find("'" + name + "' => [")
    if i < 0:
        raise SystemExit(f"block {name} not found")
    start = src.index("[", i)
    depth, k = 0, start
    while k < len(src):
        if src[k] == "[":
            depth += 1
        elif src[k] == "]":
            depth -= 1
            if depth == 0:
                return src[start : k + 1]
        k += 1
    raise SystemExit(f"unbalanced block {name}")


PAIR = re.compile(r"'([a-z_0-9]+)'\s*=>\s*'((?:[^'\\]|\\.)*)'", re.S)


def main() -> int:
    root = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
    out = {}
    for loc in LOCALES:
        path = os.path.join(root, "backend", "lang", loc, "messages.php")
        if not os.path.exists(path):
            raise SystemExit(f"missing lang file: {path}")
        src = open(path, encoding="utf-8").read()
        out[loc] = {}
        for group, keys in KEYS.items():
            body = block(src, group)
            pairs = dict(PAIR.findall(body))
            for key in keys:
                if key not in pairs:
                    raise SystemExit(f"{loc}: missing key {group}.{key}")
                # PHP single-quoted strings only escape \\ and \' — normalise to plain text.
                value = pairs[key].replace("\\'", "'").replace("\\\\", "\\")
                out[loc][f"{group}.{key}"] = value

    # Validation sentences (separate file in Laravel, but the same catalogue for Go).
    for loc in LOCALES:
        vpath = os.path.join(root, "backend", "lang", loc, "validation.php")
        if not os.path.exists(vpath):
            raise SystemExit(f"missing validation file: {vpath}")
        vsrc = open(vpath, encoding="utf-8").read()
        for key in VALIDATION_KEYS:
            m = re.search(r"'%s'\s*=>\s*'((?:[^'\\]|\\.)*)'" % key, vsrc)
            if not m:
                raise SystemExit(f"{loc}: missing validation.{key}")
            out[loc][f"validation.{key}"] = m.group(1).replace("\\'", "'").replace("\\\\", "\\")

    dest = os.path.join(root, "go-backend", "internal", "i18n", "catalog.json")
    os.makedirs(os.path.dirname(dest), exist_ok=True)
    with open(dest, "w", encoding="utf-8") as fh:
        json.dump(out, fh, ensure_ascii=False, indent=1, sort_keys=True)
        fh.write("\n")
    print(f"wrote {dest} ({sum(len(v) for v in out.values())} messages, {len(LOCALES)} locales)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
