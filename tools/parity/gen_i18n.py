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
    "language": [
        "cannot_deactivate_default",
        "default_must_be_active",
    ],
    "settings": [
        "updated",
    ],
    "address": [
        "created",
        "updated",
        "deleted",
    ],
    # Checkout / order preview (Checkpoint O). Rendering the reference's own sentences here is mandatory: the
    # stock warning and voucher rejection are shown to the customer verbatim.
    "order": [
        "no_agent_branch",
        "empty_items",
        "agent_profile_incomplete",
        "invalid_quantity",
        "insufficient_stock",
        "invalid_village",
        "invalid_region_hierarchy",
        "created",
        "status_updated",
        "cancelled",
    ],
    "shipping": [
        "method_not_available",
    ],
    "payment": [
        "method_required",
        "invalid_method",
        "idempotency_key_required",
        "invalid_dp_amount",
        "method_not_configured",
        "method_not_available_for_role",
        "method_not_available_for_shipping",
        "unsupported_method_type",
    ],
    "product": [
        "variation_required",
        "variation_not_allowed",
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
VALIDATION_KEYS = ["in", "exists", "required", "required_with", "required_without", "integer", "date",
                   "array", "unique", "after_or_equal", "not_in"]
# Nested variants: "rule.kind" (e.g. max.string).
VALIDATION_NESTED = [("max", "string"), ("max", "numeric"), ("max", "array"), ("min", "numeric"),
                     ("min", "string"), ("between", "numeric")]

LOCALES = ["id", "en", "ar", "zh"]

# Keys the reference ASKS FOR but has no translation for. Laravel's __() returns the key itself in that case, so
# the observable API response really is "messages.voucher.invalid" — this is not a Go guess, it is the verified
# output of the running reference (see docs/21c §4-2). Emitting a nicer sentence here would BREAK parity.
RAW_KEY_FALLBACKS = {
    "voucher.invalid": "messages.voucher.invalid",
    "voucher.not_applicable": "messages.voucher.not_applicable",
}


def unescape(v: str) -> str:
    """PHP single-quoted strings only escape \\ and \'."""
    return v.replace("\\'", "'").replace("\\\\", "\\")


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
                    # Laravel falls back to the default locale for a missing translation, and internal/i18n does
                    # the same, so a gap in a secondary locale is not drift — only a gap in the DEFAULT locale
                    # would make the Go output diverge.
                    if loc != "id":
                        continue
                    raise SystemExit(f"{loc}: missing key {group}.{key}")
                # PHP single-quoted strings only escape \\ and \' — normalise to plain text.
                value = pairs[key].replace("\\'", "'").replace("\\\\", "\\")
                out[loc][f"{group}.{key}"] = value

    for loc in LOCALES:
        out[loc].update(RAW_KEY_FALLBACKS)

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
        # Attribute display names: Laravel renders ":attribute" through validation.attributes[field] when
        # present, so "recipient_name" becomes "nama penerima". Without this the sentences differ.
        attrs_body = block(vsrc, "attributes")
        for field, display in PAIR.findall(attrs_body):
            out[loc][f"attributes.{field}"] = unescape(display)

        for rule, kind in VALIDATION_NESTED:
            body = block(vsrc, rule)  # bracket-balanced `'rule' => [ ... ]`
            m = re.search(r"'%s'\s*=>\s*'((?:[^'\\]|\\.)*)'" % kind, body)
            if not m:
                raise SystemExit(f"{loc}: missing validation.{rule}.{kind}")
            out[loc][f"validation.{rule}.{kind}"] = unescape(m.group(1))

    dest = os.path.join(root, "go-backend", "internal", "i18n", "catalog.json")
    os.makedirs(os.path.dirname(dest), exist_ok=True)
    with open(dest, "w", encoding="utf-8") as fh:
        json.dump(out, fh, ensure_ascii=False, indent=1, sort_keys=True)
        fh.write("\n")
    print(f"wrote {dest} ({sum(len(v) for v in out.values())} messages, {len(LOCALES)} locales)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
