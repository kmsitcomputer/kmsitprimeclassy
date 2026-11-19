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
    "agent": [
        "profile_updated",
        "not_an_agent",
        "profile_already_exists",
        "profile_deleted",
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
        "invalid_status_transition",
        "status_endpoint_required",
        "payment_not_verified",
        "sub_item_requires_owner_shipment",
    ],
    "shipping": [
        "method_not_available",
    ],
    "media": [
        "unknown_collection",
        "upload_failed",
        "invalid_type",
        "too_large",
        "dimensions_too_large",
        "uploaded",
        "deleted",
    ],
    "crm": [
        "customer_created",
        "customer_updated",
        "visit_created",
        "visit_updated",
        "phone_duplicate",
        "fu_use_plan_next_date",
        "customer_archived",
        "link_not_allowed",
        "already_linked",
        "account_already_linked",
        "not_linked",
        "linked",
        "unlinked",
        "consent_channel_unavailable",
        "owner_invalid",
        "address_book_not_allowed",
        "not_crm_order",
        "migration_pending",
    ],
    "consent": [
        "otp_sent",
        "captured",
        "email_not_verified",
        "challenge_not_usable",
        "otp_invalid",
        "otp_expired",
        "otp_locked",
    ],
    "fulfillment": [
        "shortage_reason_required",
        "shortage_item_invalid",
        "shortage_item_duplicate",
        "invalid_quantity",
        "window_closed",
        "adjusted",
        "rescheduled",
        "reschedule_courier_online_only",
        "split_stock_request_locked",
        "cannot_increase_cancelled",
        "pending_refund_blocks_increase",
        "pending_additional_payment_blocks_reduction",
        "pending_additional_payment_blocks_increase",
    ],
    "cancellation": [
        "not_eligible",
        "stale",
        "discrepancy_unresolved",
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
        "proof_locked",
        "on_behalf_forbidden",
        "order_not_awaiting_proof",
        "proof_submitted",
        "verified",
        "rejected",
        "already_verified",
        "order_not_cod",
        "cod_status_updated",
        "cod_proof_submitted",
        "cod_proof_confirmed",
        "cod_proof_already_processed",
        "cod_proof_not_found",
        "not_dp_order",
        "nothing_to_settle",
        "self_verification_forbidden",
        "verified_amount_exceeds_remaining",
        "verified_amount_exceeds_submitted",
        "adjustment_reason_required",
        "installment_not_for_cod",
        "payment_limit_reached",
        "third_payment_must_settle",
        "overpayment_refused",
        "expedition_full_payment_only",
        "cod_undo_not_allowed",
        "installment_not_allowed_yet",
        "installment_amount_invalid",
        "gateway_toggled",
        "gateway_config_saved",
        "gateway_environment_updated",
        "settlement_requested",
        "settlement_replayed",
        "already_processed",
    ],
    "product": [
        "variation_required",
        "variation_not_allowed",
        "category_deleted",
        "deleted",
        "image_deleted",
        "variation_deleted",
        "variation_requires_attribute",
        "variation_not_enabled",
        "stock_uses_variation",
    ],
    "fee": [
        "updated_product",
        "updated_variation",
    ],

    "profile": [
        "current_password_incorrect",
        "password_updated",
        "referral_code_updated",
        "referral_code_format",
    ],
    "whatsapp": [
        "scope_forbidden",
        "saved",
        "event_not_configurable",
        "template_length",
        "template_chars",
        "template_braces",
        "template_unknown_vars",
    ],
    "user": [
        "created",
        "role_not_authorized",
        "unsupported_role_combination",
        "agent_id_required_for_role",
        "invalid_agent",
        "invalid_korsal",
        "deleted",
        "cannot_delete_super_admin",
    ],
    "cms": [
        "block_deleted",
        "block_activated",
        "block_deactivated",
        "reorder_saved",
        "unknown_type",
        "type_rejects_image",
        "slide_image_required",
        "article_deleted",
        "page_deleted",
    ],
    "referral": [
        "not_linked_to_agent",
        "invalid_code",
        "code_not_found",
        "unsupported_role",
        "invalid_target",
    ],
}

# Laravel ships these rules in lang/<locale>/validation.php. The Go service reproduces the exact sentences for
# the rules it enforces, so a 422 body is identical across runtimes.
VALIDATION_KEYS = ["in", "exists", "required", "required_with", "required_without", "integer", "date",
                   "string", "array", "unique", "after_or_equal", "not_in", "boolean", "numeric", "mimes",
                   "image", "file", "required_if", "before_or_equal", "email", "confirmed", "prohibited_if",
                   "prohibited_if_accepted", "prohibited_if_declined", "accepted", "distinct"]
# Nested variants: "rule.kind" (e.g. max.string).
VALIDATION_NESTED = [("max", "string"), ("max", "numeric"), ("max", "array"), ("min", "numeric"),
                     ("min", "string"), ("between", "numeric"), ("gt", "numeric"), ("gt", "file"),
                     ("max", "file")]

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


def block(src: str, name: str, occurrence: int = 1) -> str:
    """Return the body of the Nth `'name' => [ ... ]` (bracket-balanced)."""
    needle = "'" + name + "' => ["
    i = -1
    for _ in range(occurrence):
        i = src.find(needle, i + 1)
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
# PHP also allows double-quoted strings (used for sentences that need an inline "\"..." quote). Only the
# characters that can appear in these message files are unescaped — that is enough to keep the catalogue
# byte-identical without pulling in a PHP lexer.
PAIR_DQ = re.compile(r"'([a-z_0-9]+)'\s*=>\s*\"((?:[^\"\\]|\\.)*)\"", re.S)


def unescape_double(v: str) -> str:
    return (v.replace("\\\"", "\"").replace("\\\\", "\\")
             .replace("\\$", "$").replace("\\n", "\n").replace("\\t", "\t"))


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
            try:
                if group == "referral":
                    # The lang file declares TWO referral groups (self-service referral lookup at the top,
                    # the audited reassignment vocabulary further down); merge them.
                    body = block(src, group, 1) + block(src, group, 2)
                else:
                    body = block(src, group)
            except SystemExit:
                # A locale may omit an entire group; Laravel then falls back to the default locale, which
                # internal/i18n mirrors. A gap in the default locale still fails loudly below.
                body = ""
            pairs = {k: unescape(v) for k, v in PAIR.findall(body)}
            for dq_key, dq_value in PAIR_DQ.findall(body):
                if dq_key not in pairs:
                    pairs[dq_key] = unescape_double(dq_value)
            for key in keys:
                if key not in pairs:
                    # Laravel falls back to the default locale for a missing translation, and internal/i18n does
                    # the same, so a gap in a secondary locale is not drift — only a gap in the DEFAULT locale
                    # would make the Go output diverge.
                    if loc != "id":
                        continue
                    raise SystemExit(f"{loc}: missing key {group}.{key}")
                out[loc][f"{group}.{key}"] = pairs[key]

    for loc in LOCALES:
        out[loc].update(RAW_KEY_FALLBACKS)

    # Validation sentences (separate file in Laravel, but the same catalogue for Go).
    for loc in LOCALES:
        vpath = os.path.join(root, "backend", "lang", loc, "validation.php")
        if not os.path.exists(vpath):
            raise SystemExit(f"missing validation file: {vpath}")
        vsrc = open(vpath, encoding="utf-8").read()
        for key in VALIDATION_KEYS:
            m = re.search(r"(?m)^    '%s'\s*=>\s*'((?:[^'\\]|\\.)*)'" % key, vsrc)
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
