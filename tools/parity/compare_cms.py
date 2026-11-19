#!/usr/bin/env python3
"""Laravel <-> Go differential parity for the CMS admin write surface (articles first).

    GET    /api/v1/cms/articles
    POST   /api/v1/cms/articles
    PATCH  /api/v1/cms/articles/{article}
    DELETE /api/v1/cms/articles/{article}
"""
import json
import sys

import compare_fulfillment as f
from compare_fulfillment import (LARAVEL, GO, SUPER, AGEN, check, client_for, db, diff, norm_body,
                                 reset, rows, seed_full)

SLUG_RE = re.compile(r"^(.+)-[0-9a-f]{13}$") if (re := __import__("re")) else None


def purge_cms():
    for t in ("cms_articles_translations", "cms_articles", "cms_pages_translations", "cms_pages",
              "cms_homepage_blocks_translations", "cms_homepage_blocks", "media"):
        try:
            db(f"DELETE FROM {t}")
        except RuntimeError as exc:
            if "1451" not in str(exc) and "1146" not in str(exc):
                raise
    db("""INSERT INTO cms_articles (id, type, slug, author_id, status, published_at, created_at, updated_at)
        VALUES (1,'article','tips-kue-imp010',1,'published','2026-11-01 00:00:00',NOW(),NOW())""")
    db("""INSERT INTO cms_articles_translations (cms_article_id, language_id, title, excerpt, body, created_at, updated_at)
        VALUES (1,1,'Tips Kue','Cuplikan','<p>Isi artikel</p>',NOW(),NOW())""")
    db("DELETE FROM activity_logs WHERE event LIKE 'cms.%'")


def state():
    return {
        "articles": rows("SELECT type, slug, status, published_at IS NOT NULL, author_id = 1 "
                         "FROM cms_articles ORDER BY id"),
        "translations": rows("SELECT (SELECT slug FROM cms_articles a WHERE a.id = t.cms_article_id), language_id, "
                             "title, IFNULL(excerpt,'-'), IFNULL(body,'-') FROM cms_articles_translations t "
                             "ORDER BY t.id"),
    }


def scrub(value):
    if isinstance(value, dict):
        # created_at/updated_at differ by definition; sort keys are already handled by norm_body.
        return {k: scrub(v) for k, v in value.items() if k not in ("created_at", "updated_at")}
    if isinstance(value, list):
        return [scrub(v) for v in value]
    return value


def scenario(name, run):
    out = {}
    for label, base in (("L", LARAVEL), ("G", GO)):
        reset()
        purge_cms()
        out[label] = (run(base), state())
    pairs_l, db_l = out["L"]
    pairs_g, db_g = out["G"]
    st_l, bodies_l = [p[0] for p in pairs_l], [p[1] for p in pairs_l]
    st_g, bodies_g = [p[0] for p in pairs_g], [p[1] for p in pairs_g]
    if st_l != st_g:
        check(name, False, f"statuses {st_l} vs {st_g} :: {json.dumps(bodies_l)[:280]} || {json.dumps(bodies_g)[:280]}")
        return
    for i, (bl, bg) in enumerate(zip(bodies_l, bodies_g)):
        diffs = diff(norm_body(scrub(bl)), norm_body(scrub(bg)))
        if diffs:
            check(f"{name} [response {i + 1}]", False, "; ".join(diffs[:6]))
            return
    diffs = diff(db_l, db_g)
    if diffs:
        check(f"{name} [db]", False, "; ".join(diffs[:6]))
        return
    check(name, True)


def main():
    seed_full()

    def article_matrix(base):
        c = client_for(base, SUPER)
        res = [c.call("GET", "/api/v1/cms/articles"),
               c.call("POST", "/api/v1/cms/articles", {}),
               c.call("POST", "/api/v1/cms/articles", {"type": "article", "slug": "uji-artikel",
                                                       "translations": {"1": {"title": "Judul",
                                                                              "body": "<p>Isi <script>x()</script></p>"}}}),
               c.call("POST", "/api/v1/cms/articles", {"type": "article", "slug": "uji-artikel-2",
                                                       "translations": [{"language_id": 1, "title": "Judul",
                                                                         "body": "<p>Isi aman</p>", "excerpt": "Cuplik"}]}),
               c.call("POST", "/api/v1/cms/articles", {"type": "video", "slug": "uji-3",
                                                       "translations": [{"language_id": 1, "title": "J", "body": "b"}]}),
               c.call("POST", "/api/v1/cms/articles", {"type": "article", "slug": "uji-4",
                                                       "translations": [{"language_id": 1, "title": "J", "body": "b"},
                                                                         {"language_id": 1, "title": "J2", "body": "b2"}]}),
               c.call("POST", "/api/v1/cms/articles", {"type": "article", "slug": "uji-5",
                                                       "translations": [{"language_id": 999, "title": "J", "body": "b"}]}),
               c.call("POST", "/api/v1/cms/articles", {"type": "article", "slug": "uji-6",
                                                       "translations": [{"language_id": 1, "body": "b"}]}),
               c.call("POST", "/api/v1/cms/articles", {"type": "article", "slug": "kue-lumpur",
                                                       "translations": [{"language_id": 1, "title": "J", "body": "b"}]})]
        aid = None
        for st, b in res:
            if st == 201 and (b.get("data") or {}).get("slug") == "uji-artikel-2":
                aid = b["data"]["id"]
        if aid:
            res.append(c.call("PATCH", f"/api/v1/cms/articles/{aid}", {"type": "news"}))
            res.append(c.call("DELETE", f"/api/v1/cms/articles/{aid}"))
            res.append(c.call("DELETE", f"/api/v1/cms/articles/{aid}"))
        res.append(c.call("PATCH", "/api/v1/cms/articles/999999", {"type": "news"}))
        res.append(client_for(base, AGEN).call("POST", "/api/v1/cms/articles",
                                               {"type": "article", "slug": "no-super",
                                                "translations": [{"language_id": 1, "title": "J", "body": "b"}]}))
        return res

    scenario("CMS article matrix (RBAC + validation + sanitisation)", article_matrix)

    failed = [r for r in f.results if not r[1]]
    print(f"{len(f.results) - len(failed)} passed, {len(failed)} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
