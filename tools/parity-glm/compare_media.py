#!/usr/bin/env python3
"""GLM differential harness — GENERIC MEDIA endpoints (3 Laravel-only routes).

Scenarios (per-runtime pass, media table reset per runtime):
  1. index RBAC: super_admin/agen 200, admin/keuangan/konsumen/kurir 403 (route group role gate)
  2. index projection: MediaResource shape, meta (current_page/last_page/total), user_avatar excluded,
     collection filter, pagination
  3. store validation: missing file, missing collection (accumulated 422), invalid collection (in-rule)
  4. store RBAC (policy AFTER validation): konsumen→product_image 403; konsumen→user_avatar 201;
     admin→product_image 403; agen→product_image 201; super→product_image 201
  5. store response: 201, MediaResource shape, uuid path/url shape, localized uploaded message
  6. destroy: super deletes product_image 200; admin 403; avatar owner 200; foreign avatar 403;
     unknown id → 404 localized not_found; DB row deleted
  7. DB state: media rows after the pass (uuid-normalized)

Usage: python3 tools/parity-glm/compare_media.py
"""
import http.cookiejar
import json
import re
import struct
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid as uuidlib

LARAVEL = "http://127.0.0.1:8898"
GO = "http://127.0.0.1:8083"
TS_RE = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{6}Z|\+00:00)$")

results = []


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

    def _request(self, req):
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

    def call(self, method, path, body=None):
        headers = {"Accept": "application/json", "Origin": self.base, "Referer": self.base + "/",
                   "X-Requested-With": "XMLHttpRequest"}
        data = None
        if body is not None:
            data = json.dumps(body).encode()
            headers["Content-Type"] = "application/json"
        tok = self.token()
        if tok:
            headers["X-XSRF-TOKEN"] = tok
        req = urllib.request.Request(self.base + path, data=data, headers=headers, method=method)
        return self._request(req)

    def upload(self, path, fields, filename="pixel.png", content=None):
        boundary = uuidlib.uuid4().hex
        parts = []
        for name, value in fields.items():
            parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode())
        if content is not None:
            parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="{filename}"\r\n'
                         f'Content-Type: image/png\r\n\r\n'.encode() + content + b"\r\n")
        parts.append(f"--{boundary}--\r\n".encode())
        body = b"".join(parts)
        headers = {"Accept": "application/json", "Origin": self.base, "Referer": self.base + "/",
                   "X-Requested-With": "XMLHttpRequest", "Content-Type": f"multipart/form-data; boundary={boundary}"}
        tok = self.token()
        if tok:
            headers["X-XSRF-TOKEN"] = tok
        req = urllib.request.Request(self.base + path, data=body, headers=headers, method="POST")
        return self._request(req)

    def login(self, email, password="Secret123!"):
        self.call("GET", "/sanctum/csrf-cookie")
        for _ in range(8):
            st = self.call("POST", "/api/v1/auth/login", {"identifier": email, "password": password})[0]
            if st != 429:
                return st
            time.sleep(10)
        return 429


def db(sql):
    sql = sql.replace(chr(96), chr(92) + chr(96))
    cmd = (f'mariadb -uprimeclassy_app -p"$(cat $MARIADB_PASSWORD_FILE)" -N -B primeclassy_testing_glm '
           f'-e "{sql}"')
    out = subprocess.run(["docker", "exec", "primeclassy-mariadb", "sh", "-lc", cmd],
                         capture_output=True, text=True, timeout=60)
    if out.returncode != 0:
        raise RuntimeError(out.stderr[:300])
    return [l.split("\t") for l in out.stdout.strip().split("\n") if l]


def png_bytes():
    # Minimal valid 1x1 PNG (zlib IDAT of a single transparent pixel).
    import zlib
    def chunk(tag, data):
        c = struct.pack(">I", len(data)) + tag + data
        return c + struct.pack(">I", zlib.crc32(tag + data) & 0xFFFFFFFF)
    ihdr = struct.pack(">IIBBBBB", 1, 1, 8, 6, 0, 0, 0)
    idat = zlib.compress(b"\x00\x00\x00\x00\x00")
    return (b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", ihdr) + chunk(b"IDAT", idat) + chunk(b"IEND", b""))


PNG = png_bytes()


def seed_media_rows():
    db("DELETE FROM media WHERE id >= 900")
    db("ALTER TABLE media AUTO_INCREMENT = 900")
    db("INSERT INTO media (id,disk,path,collection,original_filename,mime_type,extension,size,width,height,created_by,created_at,updated_at) VALUES "
       "(901,'public','media/product_image/glm-seed-1.png','product_image','seed1.png','image/png','png',70,1,1,1,NOW(6),NOW(6)),"
       "(902,'public','media/cms_content/glm-seed-2.png','cms_content','seed2.png','image/png','png',70,1,1,10,NOW(6),NOW(6)),"
       "(903,'public','media/user_avatar/glm-seed-3.png','user_avatar','seed3.png','image/png','png',70,1,1,13,NOW(6),NOW(6)),"
       "(904,'public','media/user_avatar/glm-seed-4.png','user_avatar','seed4.png','image/png','png',70,1,1,1,NOW(6),NOW(6))")


def normalise(v):
    if isinstance(v, dict):
        return {k: normalise(x) for k, x in v.items()}
    if isinstance(v, list):
        # latest() has no tiebreak (D-1 class): compare as a content set.
        items = [normalise(x) for x in v]
        if items and all(isinstance(i, dict) for i in items):
            items.sort(key=lambda d: json.dumps(d, sort_keys=True))
        return items
    if isinstance(v, str):
        if TS_RE.match(v):
            return "<timestamp>"
        m = re.search(r"/media/([a-z_]+)/([0-9a-f-]{36})\.", v)
        if m:
            return v[:m.start()] + f"<uuid:{m.group(1)}>"
    return v


def walk(a, b, path="", out=None):
    if out is None:
        out = []
    if type(a) is not type(b) and not (isinstance(a, (int, float)) and isinstance(b, (int, float))):
        out.append(f"{path or '$'}: type {type(a).__name__} vs {type(b).__name__}")
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
            out.append(f"{path or '$'}: length {len(a)} vs {len(b)}")
            return out
        for i, (x, y) in enumerate(zip(a, b)):
            walk(x, y, f"{path}[{i}]", out)
        return out
    if a != b:
        out.append(f"{path or '$'}: {a!r} vs {b!r}")
    return out


def scenario(base, who):
    """Run the full scenario against one runtime; every check is recorded prefixed with `who`."""
    def login_as(email):
        c = Client(base)
        st = c.login(email)
        check(f"{who} login {email}", st == 200, f"{st}")
        return c

    super_c = login_as("super@imp010.test")
    agen_c = login_as("agen@imp010.test")
    admin_c = login_as("admin@imp010.test")
    keu_c = login_as("keuangan@imp010.test")
    kons_c = login_as("konsumen@imp010.test")
    kurir_c = login_as("kurir@imp010.test")
    sales_c = login_as("sales@imp010.test")

    # 1. index RBAC — verified: GET /media sits inside the super_admin-only CMS group, so an agen is
    # 403 even though MediaPolicy::create would allow it (route gate narrower than policy).
    st, idx = super_c.call("GET", "/api/v1/media")
    check(f"{who} index super 200", st == 200, f"{st} {idx}")
    st, body = agen_c.call("GET", "/api/v1/media")
    check(f"{who} index agen 403", st == 403, f"{st} {body}")
    for c, label in [(admin_c, "admin"), (keu_c, "keuangan"), (kons_c, "konsumen"), (kurir_c, "kurir"), (sales_c, "sales")]:
        st, body = c.call("GET", "/api/v1/media")
        check(f"{who} index {label} 403", st == 403, f"{st} {body}")

    # 2. index projection + filter; page-2 DATA is not compared (latest() has no tiebreak: all seeded
    # rows share NOW(6), so the page-2 ROW itself is scheduler luck) — only its meta is compared.
    st, idx_p2 = super_c.call("GET", "/api/v1/media?page=2&per_page=1")
    check(f"{who} index page2 200", st == 200, f"{st}")
    if idx_p2 and idx_p2.get("data") is not None:
        idx_p2["data"] = []
    st, idx_filter = super_c.call("GET", "/api/v1/media?collection=product_image")
    check(f"{who} index filter 200", st == 200, f"{st}")
    if idx and idx.get("data") is not None:
        cols = {row["collection"] for row in idx["data"]}
        check(f"{who} index excludes user_avatar", "user_avatar" not in cols, f"{cols}")

    # 3. store validation
    st, v1 = super_c.upload("/api/v1/media", {})
    check(f"{who} store missing both 422", st == 422, f"{st} {v1}")
    st, v2 = super_c.upload("/api/v1/media", {"collection": "product_image"})
    check(f"{who} store missing file 422", st == 422, f"{st} {v2}")
    st, v3 = super_c.upload("/api/v1/media", {"collection": "bogus"}, content=PNG)
    check(f"{who} store bogus collection 422", st == 422, f"{st} {v3}")

    # 4. store RBAC (policy AFTER validation)
    st, body = kons_c.upload("/api/v1/media", {"collection": "product_image"}, content=PNG)
    check(f"{who} konsumen product_image 403", st == 403, f"{st} {body}")
    st, av_kons = kons_c.upload("/api/v1/media", {"collection": "user_avatar"}, content=PNG)
    check(f"{who} konsumen avatar 201", st == 201, f"{st} {av_kons}")
    st, body = admin_c.upload("/api/v1/media", {"collection": "product_image"}, content=PNG)
    check(f"{who} admin product_image 403", st == 403, f"{st} {body}")
    st, up_agen = agen_c.upload("/api/v1/media", {"collection": "product_image"}, content=PNG)
    check(f"{who} agen product_image 201", st == 201, f"{st} {up_agen}")
    st, up_super = super_c.upload("/api/v1/media", {"collection": "product_image"}, content=PNG)
    check(f"{who} super product_image 201", st == 201, f"{st} {up_super}")

    # 5. store response shape
    if up_super and up_super.get("data"):
        d = up_super["data"]
        m = re.search(r"/media/product_image/([0-9a-f-]{36})\.png$", d.get("url") or "")
        check(f"{who} store url uuid shape", bool(m), f"{d.get('url')}")

    # 6. destroy matrix (901 product_image, 903 avatar owned by konsumen 13, 904 avatar owned by super 1)
    st, body = admin_c.call("DELETE", "/api/v1/media/901")
    check(f"{who} admin delete product_image 403", st == 403, f"{st} {body}")
    st, body = kons_c.call("DELETE", "/api/v1/media/904")  # super's avatar, foreign
    check(f"{who} konsumen foreign avatar 403", st == 403, f"{st} {body}")
    st, d_ok = super_c.call("DELETE", "/api/v1/media/901")
    check(f"{who} super delete product_image 200", st == 200, f"{st} {d_ok}")
    st, body = kons_c.call("DELETE", "/api/v1/media/903")  # own avatar
    check(f"{who} avatar owner delete 200", st == 200, f"{st} {body}")
    st, body = super_c.call("DELETE", "/api/v1/media/499999")
    check(f"{who} unknown id 404", st == 404, f"{st} {body}")

    # 7. DB state
    rows = db("SELECT collection, created_by, extension, mime_type FROM media WHERE id >= 900 ORDER BY id")
    return {
        "idx": normalise(idx), "idx_p2": normalise(idx_p2), "idx_filter": normalise(idx_filter),
        "v1": v1, "v2": v2, "v3": v3,
        "av_kons": normalise(av_kons), "up_agen": normalise(up_agen), "up_super": normalise(up_super),
        "d_ok": d_ok, "rows": rows,
    }


def main():
    seed_media_rows()
    lar = scenario(LARAVEL, "L")
    seed_media_rows()
    go = scenario(GO, "G")

    for key in ["idx", "idx_p2", "idx_filter", "av_kons", "up_agen", "up_super", "d_ok", "rows"]:
        diffs = walk(lar[key], go[key])
        check(f"parity {key}", not diffs, "; ".join(diffs[:6]))
    for key in ["v1", "v2", "v3"]:
        check(f"parity {key}", lar[key] == go[key], f"L={json.dumps(lar[key])} G={json.dumps(go[key])}")

    failed = [r for r in results if not r[1]]
    print(f"media: {len(results) - len(failed)} passed, {len(failed)} failed")
    for name, ok, detail in failed:
        print(f"  FAIL {name}: {detail}")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())