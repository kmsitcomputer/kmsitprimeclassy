#!/usr/bin/env python3
"""Laravel ↔ Go comparison for INVENTORY MUTATIONS (checkpoint H).

A mutation cannot be compared by replaying one request: the second call sees a changed world. This script
therefore drives the SAME scenario against each runtime in turn and compares:

  * the HTTP status + body of each step,
  * the resulting DATABASE STATE (quantities, statuses, movement counts) — the part that actually matters.

It runs each scenario on a freshly re-seeded database (via tools/parity/seed_*.sql) so the two runs start from
identical rows, and it never touches DEV or production data.

Usage:
  python3 tools/parity/compare_inventory_mutations.py \
      --laravel http://127.0.0.1:8899 --go http://127.0.0.1:8082 \
      --db-container primeclassy-mariadb --db primeclassy_testing
"""
import argparse
import http.cookiejar
import json
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request

results = []

# One authenticated client per (runtime, role): the login endpoint is deliberately throttled (10/min in both
# runtimes), so a harness that logged in per step would measure the limiter instead of the domain.
_CLIENTS = {}


def reset_clients():
    """Drop cached sessions: the fixtures recreate the users, and both runtimes lose the session rows
    (Laravel's `sessions.user_id` cascades on the user delete; Go's own table is unrelated but the harness must
    not assume either survived)."""
    _CLIENTS.clear()


def auth(base, email, password="Secret123!"):
    key = (base, email)
    if key not in _CLIENTS:
        c = Client(base)
        st = c.login(email, password)
        if st != 200:
            raise RuntimeError(f"login {email} at {base} failed: {st}")
        _CLIENTS[key] = c
    return _CLIENTS[key]


def check(name, ok, detail=""):
    results.append((name, ok, detail))


class Client:
    def __init__(self, base):
        self.base = base
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))

    def token(self):
        for c in self.jar:
            if c.name == "XSRF-TOKEN":
                return urllib.parse.unquote(c.value)
        return None

    def call(self, method, path, body=None):
        headers = {
            "Accept": "application/json",
            "Origin": self.base,
            "Referer": self.base + "/",
            "X-Requested-With": "XMLHttpRequest",
        }
        data = None
        if body is not None:
            data = json.dumps(body).encode()
            headers["Content-Type"] = "application/json"
        tok = self.token()
        if tok:
            headers["X-XSRF-TOKEN"] = tok
        req = urllib.request.Request(self.base + path, data=data, headers=headers, method=method)
        try:
            with self.opener.open(req, timeout=25) as res:
                raw = res.read().decode()
                return res.status, (json.loads(raw) if raw else None)
        except urllib.error.HTTPError as e:
            raw = e.read().decode()
            try:
                return e.code, (json.loads(raw) if raw else None)
            except json.JSONDecodeError:
                return e.code, {"_raw": raw}

    def login(self, email, password):
        """Log in, respecting the real login throttle (10/min in both runtimes).

        The limiter is a security control we must keep: instead of weakening it for the harness, a 429 is
        waited out and retried (bounded), so a repeated local run still measures the domain.
        """
        import time as _time

        self.call("GET", "/sanctum/csrf-cookie")
        for attempt in range(8):
            status, body = self.call("POST", "/api/v1/auth/login",
                                     {"identifier": email, "password": password})
            if status != 429:
                return status
            wait = 10
            print(f"    (login throttled, waiting {wait}s — attempt {attempt + 1})")
            _time.sleep(wait)
            self.call("GET", "/sanctum/csrf-cookie")
        return 429


class DB:
    """Reads the isolated test database through the MariaDB container (no credentials on the host)."""

    def __init__(self, container, database):
        self.container = container
        self.database = database

    def q(self, sql):
        cmd = (
            f'mariadb -uprimeclassy_app -p"$(cat $MARIADB_PASSWORD_FILE)" -N -B {self.database} -e "{sql}"'
        )
        out = subprocess.run(
            ["docker", "exec", self.container, "sh", "-lc", cmd],
            capture_output=True, text=True, timeout=60,
        )
        if out.returncode != 0:
            raise RuntimeError(out.stderr.strip()[:400])
        return [line.split("\t") for line in out.stdout.strip().split("\n") if line]

    def exec(self, sql):
        cmd = f'mariadb -uprimeclassy_app -p"$(cat $MARIADB_PASSWORD_FILE)" {self.database} -e "{sql}"'
        out = subprocess.run(
            ["docker", "exec", self.container, "sh", "-lc", cmd],
            capture_output=True, text=True, timeout=60,
        )
        if out.returncode != 0:
            raise RuntimeError(out.stderr.strip()[:400])

    def exec_sql(self, sql):
        cmd = f'mariadb -uprimeclassy_app -p"$(cat $MARIADB_PASSWORD_FILE)" {self.database} -e "{sql}"'
        out = subprocess.run(
            ["docker", "exec", self.container, "sh", "-lc", cmd],
            capture_output=True, text=True, timeout=60,
        )
        if out.returncode != 0:
            raise RuntimeError(out.stderr.strip()[:300])

    def snapshot(self):
        """The state a transfer scenario can change."""
        return {
            "warehouse": self.q(
                "SELECT COALESCE(product_id,0), COALESCE(product_variation_id,0), stock_type, "
                "COALESCE(sub_location_id,0), quantity FROM warehouse_stocks ORDER BY 1,2,3,4"
            ),
            "transfers": self.q("SELECT id, status FROM stock_transfers ORDER BY id"),
            "handovers": self.q("SELECT COUNT(*) FROM stock_handovers"),
            "movements": self.q(
                "SELECT type, COUNT(*) FROM stock_movements GROUP BY type ORDER BY type"
            ),
            "commitments": self.q(
                "SELECT COALESCE(product_id,0), quantity_on_hand, quantity_reserved FROM product_stocks ORDER BY 1"
            ),
        }


def reseed(db, root):
    reset_clients()
    for f in ("seed_test_fixtures.sql", "seed_inventory_fixtures.sql"):
        with open(f"{root}/tools/parity/{f}", "rb") as fh:
            cmd = f'mariadb -uprimeclassy_app -p"$(cat $MARIADB_PASSWORD_FILE)" {db.database}'
            out = subprocess.run(
                ["docker", "exec", "-i", db.container, "sh", "-lc", cmd],
                stdin=fh, capture_output=True, text=True, timeout=120,
            )
            if out.returncode != 0:
                raise RuntimeError(f"{f}: {out.stderr.strip()[:300]}")


def scenario_transfer_lifecycle(base, db, label):
    """Create (gudang) → approve (admin) → verify stock moved + movements written."""
    gudang = auth(base, "gudang@imp010.test")
    admin = auth(base, "admin@imp010.test")

    steps = {}

    # A transit -> sub move of product 1 from the branch into sub-location 3.
    steps["create"] = gudang.call("POST", "/api/v1/warehouse/transfers", {
        "source_stock_type": "transit",
        "destination_stock_type": "sub",
        "destination_sub_location_id": 4,
        "note": "compare-run",
        "items": [{"product_id": 1, "quantity": 2}],
    })

    transfer_id = None
    if steps["create"][0] == 200 or steps["create"][0] == 201:
        body = steps["create"][1]
        transfer_id = body.get("data", {}).get("id")

    if transfer_id:
        steps["show"] = admin.call("GET", f"/api/v1/warehouse/transfers/{transfer_id}")
        steps["approve"] = admin.call("POST", f"/api/v1/warehouse/transfers/{transfer_id}/approve")
        # Idempotency: approving twice must not move stock twice.
        steps["approve_again"] = admin.call("POST", f"/api/v1/warehouse/transfers/{transfer_id}/approve")
        steps["list"] = admin.call("GET", "/api/v1/warehouse/transfers")

    return steps, transfer_id


def scenario_cancel_and_reject(base, db):
    """A transfer's non-completing paths must not touch stock."""
    gudang = auth(base, "gudang@imp010.test")
    admin = auth(base, "admin@imp010.test")

    steps = {}
    create = gudang.call("POST", "/api/v1/warehouse/transfers", {
        "source_stock_type": "transit",
        "destination_stock_type": "sub",
        "destination_sub_location_id": 4,
        "items": [{"product_variation_id": 2, "quantity": 1}],
    })
    steps["create"] = create
    tid = create[1].get("data", {}).get("id") if create[0] in (200, 201) else None
    if tid:
        steps["cancel"] = gudang.call("POST", f"/api/v1/warehouse/transfers/{tid}/cancel")
        steps["cancel_again"] = gudang.call("POST", f"/api/v1/warehouse/transfers/{tid}/cancel")

    create2 = gudang.call("POST", "/api/v1/warehouse/transfers", {
        "source_stock_type": "transit",
        "destination_stock_type": "sub",
        "destination_sub_location_id": 4,
        "items": [{"product_id": 1, "quantity": 1}],
    })
    steps["create2"] = create2
    tid2 = create2[1].get("data", {}).get("id") if create2[0] in (200, 201) else None
    if tid2:
        steps["reject"] = admin.call("POST", f"/api/v1/warehouse/transfers/{tid2}/reject", {"reason": "compare"})
    return steps


def scenario_guards(base, db):
    """Role + validation guards: the negative paths matter as much as the happy one."""
    gudang = auth(base, "gudang@imp010.test")
    agen = auth(base, "agen@imp010.test")
    admin = auth(base, "admin@imp010.test")

    steps = {}
    # agen cannot create a transfer (policy: gudang only) — route gate is gudang, so 403.
    steps["agen_create"] = agen.call("POST", "/api/v1/warehouse/transfers", {
        "source_stock_type": "transit", "destination_stock_type": "sub",
        "destination_sub_location_id": 4, "items": [{"product_id": 1, "quantity": 1}],
    })
    # shipping is a closed bucket.
    steps["shipping_create"] = gudang.call("POST", "/api/v1/warehouse/transfers", {
        "source_stock_type": "shipping", "destination_stock_type": "sub",
        "destination_sub_location_id": 4, "items": [{"product_id": 1, "quantity": 1}],
    })
    # factory_plan is not a plain transfer.
    steps["plan_create"] = gudang.call("POST", "/api/v1/warehouse/transfers", {
        "source_stock_type": "factory_plan", "destination_stock_type": "transit",
        "items": [{"product_id": 1, "quantity": 1}],
    })
    # duplicate targets rejected.
    steps["dup_create"] = gudang.call("POST", "/api/v1/warehouse/transfers", {
        "source_stock_type": "transit", "destination_stock_type": "sub",
        "destination_sub_location_id": 4,
        "items": [{"product_id": 1, "quantity": 1}, {"product_id": 1, "quantity": 2}],
    })
    # sub bucket without a sub location.
    steps["sub_no_location"] = gudang.call("POST", "/api/v1/warehouse/transfers", {
        "source_stock_type": "transit", "destination_stock_type": "sub",
        "items": [{"product_id": 1, "quantity": 1}],
    })
    # stock/adjust is a hard 403 in warehouse-authoritative mode (the shipping default).
    steps["adjust"] = agen.call("POST", "/api/v1/stock/adjust",
                                {"product_id": 1, "delta": 5, "reason": "compare"})
    steps["adjust_admin"] = admin.call("POST", "/api/v1/stock/adjust",
                                       {"product_id": 1, "delta": 5, "reason": "compare"})
    # factory plan toggle requires admin.
    steps["plan_toggle_gudang"] = gudang.call("PATCH", "/api/v1/warehouse/settings/factory-plan",
                                              {"factory_plan_enabled": True})
    steps["plan_toggle_admin"] = admin.call("PATCH", "/api/v1/warehouse/settings/factory-plan",
                                            {"factory_plan_enabled": True})
    # unknown transfer status filter.
    steps["bad_status"] = admin.call("GET", "/api/v1/warehouse/transfers?status=bogus")
    # plan-to-transit: allowed for gudang when the plan is enabled.
    steps["plan_transfer"] = gudang.call("POST", "/api/v1/warehouse/transfers/plan-to-transit", {
        "items": [{"product_id": 2, "quantity": 1}],
    })
    steps["plan_transfer_dup"] = gudang.call("POST", "/api/v1/warehouse/transfers/plan-to-transit", {
        "items": [{"product_id": 2, "quantity": 1}, {"product_id": 2, "quantity": 2}],
    })
    # eligible-owners: agen/admin only, and only Sales-Kurir-Sub without a location.
    steps["eligible_agen"] = agen.call("GET", "/api/v1/warehouse/sub-locations/eligible-owners")
    steps["eligible_admin"] = admin.call("GET", "/api/v1/warehouse/sub-locations/eligible-owners")
    steps["eligible_search"] = admin.call("GET", "/api/v1/warehouse/sub-locations/eligible-owners?search=Sub")
    steps["eligible_gudang"] = gudang.call("GET", "/api/v1/warehouse/sub-locations/eligible-owners")
    return steps


_DB = None


def db_exec(sql):
    if _DB is None:
        raise RuntimeError("db not initialised")
    _DB.exec_sql(sql)


def scenario_opname(base):
    """Draft -> count -> submit -> approve, plus the stale-snapshot 409 and the guards."""
    gudang = auth(base, "gudang@imp010.test")
    admin = auth(base, "admin@imp010.test")
    steps = {}

    created = gudang.call("POST", "/api/v1/warehouse/opnames", {
        "opname_type": "physical_opname",
        "stock_type": "transit",
        "notes": "compare",
        "items": [{"product_id": 2}],
    })
    steps["create"] = created
    opname_id = created[1].get("data", {}).get("id") if created[0] == 201 else None
    if not opname_id:
        return steps, None
    body = created[1]["data"]
    item_id = body["items"][0]["id"]

    # Snapshot values (system_quantity for product 2 transit = 12 in the fixtures)
    steps["show"] = gudang.call("GET", f"/api/v1/warehouse/opnames/{opname_id}")
    # Submit before counting must fail (uncounted item).
    steps["submit_early"] = gudang.call("POST", f"/api/v1/warehouse/opnames/{opname_id}/submit")
    steps["count"] = gudang.call("PATCH", f"/api/v1/warehouse/opnames/{opname_id}/count", {
        "counts": [{"item_id": item_id, "counted_quantity": 10}],
    })
    # Approve while still draft must fail.
    steps["approve_draft"] = admin.call("POST", f"/api/v1/warehouse/opnames/{opname_id}/approve")
    steps["submit"] = gudang.call("POST", f"/api/v1/warehouse/opnames/{opname_id}/submit")
    steps["list"] = gudang.call("GET", "/api/v1/warehouse/opnames")
    # Mutate the stock AFTER counting, so approval must answer 409.
    db_exec("UPDATE warehouse_stocks SET quantity = quantity - 1 WHERE agent_id = 10 AND stock_type = 'transit' AND product_id = 2")
    steps["approve_stale"] = admin.call("POST", f"/api/v1/warehouse/opnames/{opname_id}/approve")
    # Put it back to the snapshotted value and approve for real.
    db_exec("UPDATE warehouse_stocks SET quantity = 12 WHERE agent_id = 10 AND stock_type = 'transit' AND product_id = 2")
    steps["approve"] = admin.call("POST", f"/api/v1/warehouse/opnames/{opname_id}/approve")
    steps["approve_again"] = admin.call("POST", f"/api/v1/warehouse/opnames/{opname_id}/approve")
    return steps, opname_id


def scenario_opname_guards(base):
    gudang = auth(base, "gudang@imp010.test")
    agen = auth(base, "agen@imp010.test")
    steps = {}
    # sellable_reconciliation is read-only.
    steps["sellable"] = gudang.call("POST", "/api/v1/warehouse/opnames", {
        "opname_type": "sellable_reconciliation", "stock_type": "transit", "items": [{"product_id": 2}]})
    # sub stock type requires a sub location.
    steps["sub_no_loc"] = gudang.call("POST", "/api/v1/warehouse/opnames", {
        "opname_type": "physical_opname", "stock_type": "sub", "items": [{"product_id": 2}]})
    # plan reconciliation needs factory_plan.
    steps["plan_wrong_type"] = gudang.call("POST", "/api/v1/warehouse/opnames", {
        "opname_type": "plan_reconciliation", "stock_type": "transit", "items": [{"product_id": 2}]})
    # invalid stock type.
    steps["bad_type"] = gudang.call("POST", "/api/v1/warehouse/opnames", {
        "opname_type": "physical_opname", "stock_type": "shippingx", "items": [{"product_id": 2}]})
    # duplicate targets.
    steps["dup"] = gudang.call("POST", "/api/v1/warehouse/opnames", {
        "opname_type": "physical_opname", "stock_type": "transit",
        "items": [{"product_id": 2}, {"product_id": 2}]})
    # a non-gudang role cannot create.
    steps["agen_create"] = agen.call("POST", "/api/v1/warehouse/opnames", {
        "opname_type": "physical_opname", "stock_type": "transit", "items": [{"product_id": 2}]})
    return steps


def compare_steps(label, lsteps, gsteps):
    for key in lsteps:
        if key not in gsteps:
            check(f"{label}.{key}", False, "missing in Go run")
            continue
        ls, lbody = lsteps[key]
        gs, gbody = gsteps[key]
        if ls != gs:
            check(f"{label}.{key}: status", False, f"{ls} vs {gs} ({_msg(lbody)} vs {_msg(gbody)})")
            continue
        check(f"{label}.{key}: status {ls}", True)
        lmsg, gmsg = _msg(lbody), _msg(gbody)
        if lmsg != gmsg:
            check(f"{label}.{key}: message", False, f"{lmsg!r} vs {gmsg!r}")


def _msg(body):
    if isinstance(body, dict):
        return body.get("message", "")
    return ""


def compare_state(label, lstate, gstate):
    for key in lstate:
        if lstate[key] != gstate[key]:
            check(f"{label}.db.{key}", False, f"{lstate[key]} vs {gstate[key]}")
        else:
            check(f"{label}.db.{key}", True)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--laravel", default="http://127.0.0.1:8899")
    ap.add_argument("--go", default="http://127.0.0.1:8082")
    ap.add_argument("--db-container", default="primeclassy-mariadb")
    ap.add_argument("--db", default="primeclassy_testing")
    ap.add_argument("--root", default=".")
    args = ap.parse_args()

    global _DB
    db = DB(args.db_container, args.db)
    _DB = db

    # ---- inventory lifecycle -------------------------------------------------
    reseed(db, args.root)
    lsteps, ltid = scenario_transfer_lifecycle(args.laravel, db, "laravel")
    lstate = db.snapshot()

    reseed(db, args.root)
    gsteps, gtid = scenario_transfer_lifecycle(args.go, db, "go")
    gstate = db.snapshot()

    compare_steps("lifecycle", lsteps, gsteps)
    compare_state("lifecycle", lstate, gstate)
    check("lifecycle: both runs created a transfer", ltid is not None and gtid is not None, f"{ltid} vs {gtid}")
    if ltid and gtid:
        check("lifecycle: same transfer id sequence", ltid == gtid, f"{ltid} vs {gtid}")

    # ---- cancel / reject -----------------------------------------------------
    reseed(db, args.root)
    l2 = scenario_cancel_and_reject(args.laravel, db)
    l2state = db.snapshot()
    reseed(db, args.root)
    g2 = scenario_cancel_and_reject(args.go, db)
    g2state = db.snapshot()
    compare_steps("cancel-reject", l2, g2)
    compare_state("cancel-reject", l2state, g2state)

    # ---- stock opname --------------------------------------------------------
    reseed(db, args.root)
    l4, lopid = scenario_opname(args.laravel)
    l4state = db.snapshot()
    reseed(db, args.root)
    g4, gopid = scenario_opname(args.go)
    g4state = db.snapshot()
    compare_steps("opname", l4, g4)
    compare_state("opname", l4state, g4state)
    check("opname: both runs created an opname", lopid is not None and gopid is not None, f"{lopid} vs {gopid}")

    reseed(db, args.root)
    l5 = scenario_opname_guards(args.laravel)
    reseed(db, args.root)
    g5 = scenario_opname_guards(args.go)
    compare_steps("opname-guards", l5, g5)

    # ---- guards --------------------------------------------------------------
    reseed(db, args.root)
    l3 = scenario_guards(args.laravel, db)
    l3state = db.snapshot()
    reseed(db, args.root)
    g3 = scenario_guards(args.go, db)
    g3state = db.snapshot()
    compare_steps("guards", l3, g3)
    compare_state("guards", l3state, g3state)

    passed = sum(1 for _, ok, _ in results if ok)
    for name, ok, detail in results:
        if not ok:
            print(f"FAIL  {name} — {detail}")
    print(f"\n{passed} passed, {len(results) - passed} failed")
    return 0 if passed == len(results) else 1


if __name__ == "__main__":
    sys.exit(main())
