#!/usr/bin/env python3
"""Laravel ↔ Go differential comparison for homepage block JSON creation."""
import json, sys
import compare_fulfillment as f
from compare_fulfillment import LARAVEL, GO, SUPER, AGEN, check, client_for, db, diff, norm_body, seed_full

CASES = [
    ("missing type", {}),
    ("null type", {"type":None}),
    ("empty type", {"type":""}),
    ("non-string type", {"type":7}),
    ("unknown type", {"type":"not_a_block"}),
    ("content must be object", {"type":"text","content":"invalid"}),
    ("null content", {"type":"text","content":None}),
    ("null metadata", {"type":"text","content":{"body":"Text"},"sort_order":None,"starts_at":None,"ends_at":None}),
    ("empty nullable dates", {"type":"text","content":{"body":"Text"},"starts_at":"","ends_at":""}),
    ("invalid sort order type", {"type":"text","content":{"body":"Text"},"sort_order":"many"}),
    ("negative sort order", {"type":"text","content":{"body":"Text"},"sort_order":-1}),
    ("fractional sort order", {"type":"text","content":{"body":"Text"},"sort_order":1.5}),
    ("numeric string sort order", {"type":"text","content":{"body":"Text"},"sort_order":"6"}),
    ("invalid active boolean", {"type":"text","content":{"body":"Text"},"is_active":"true"}),
    ("null active boolean", {"type":"text","content":{"body":"Text"},"is_active":None}),
    ("date must be valid", {"type":"text","content":{"body":"Text"},"starts_at":123}),
    ("end date ordering", {"type":"text","content":{"body":"Text"},"starts_at":"2026-11-22","ends_at":"2026-11-21"}),
    ("required hero heading", {"type":"hero","content":{}}),
    ("empty category ids", {"type":"category","content":{"category_ids":[]}}),
    ("missing bestseller fields", {"type":"bestseller","content":{}}),
    ("CTA required fields", {"type":"cta","content":{}}),
    ("social media required fields", {"type":"social_media","content":{}}),
    ("WhatsApp required fields", {"type":"whatsapp_chat","content":{}}),
    ("slider required fields", {"type":"banner_slider","content":{}}),
    ("unsafe hero link", {"type":"hero","content":{"heading":"x","cta_url":"javascript:alert(1)"}}),
    ("unsafe promotional link", {"type":"promotional","content":{"heading":"x","url":"data:text/html,x"}}),
    ("unsafe CTA link", {"type":"cta","content":{"heading":"x","button_label":"Go","button_url":"//evil.example"}}),
    ("unknown category id", {"type":"category","content":{"category_ids":[999999999]}}),
    ("custom HTML sanitization", {"type":"custom","content":{"html":"<p style='text-align:center'>safe <script>x()</script><a href='javascript:alert(1)'>link</a></p>"}}),
    ("custom editor allowlist", {"type":"custom","content":{"html":"<figure><img src='https://example.test/a.png' alt='cake' onerror='bad()'></figure><h3 style='text-align:right;color:red'>Title</h3><blockquote><strong>Quote</strong></blockquote><iframe src='https://evil.test'>x</iframe>"}}),
    ("custom safe external link", {"type":"custom","content":{"html":"<p><a href='https://example.test/path' target='_blank'>Visit</a><a href='mailto:help@example.test'>Mail</a></p>"}}),
    ("custom unsafe URI schemes", {"type":"custom","content":{"html":"<p><a href='data:text/html,x'>data</a><a href='vbscript:msgbox(1)'>vb</a><img src='javascript:alert(1)'></p>"}}),
    ("custom malformed markup", {"type":"custom","content":{"html":"<p><strong>one</p><p>two<!--secret--></p>"}}),
    ("product block metadata", {"type":"product","content":{"heading":"New","product_ids":[1],"limit":4},"sort_order":4,"is_active":False,"starts_at":"2026-11-20T00:00:00Z","ends_at":"2026-11-21T00:00:00Z"}),
    ("admin role gate", {"type":"text","content":{"body":"Text"}}, AGEN),
]

def rows(): return db("SELECT id,type,sort_order,is_active,starts_at,ends_at FROM cms_homepage_blocks ORDER BY id")

def snapshot(created):
    result=[]
    for row in created:
        content={}
        if row[1]=="custom": content={"html":db(f"SELECT JSON_UNQUOTE(JSON_EXTRACT(content,'$.html')) FROM cms_homepage_blocks WHERE id={row[0]}")[0][0]}
        if row[1]=="product":
            values=db(f"SELECT JSON_UNQUOTE(JSON_EXTRACT(content,'$.heading')),JSON_UNQUOTE(JSON_EXTRACT(content,'$.product_ids')),JSON_UNQUOTE(JSON_EXTRACT(content,'$.limit')) FROM cms_homepage_blocks WHERE id={row[0]}")[0]
            content={"heading":values[0],"product_ids":json.loads(values[1]),"limit":json.loads(values[2])}
        result.append({"type":row[1],"content":content,"sort_order":row[2],"is_active":row[3],"starts_at":row[4],"ends_at":row[5]})
    return result

def run(client, body):
    before={row[0] for row in rows()}
    try:
        response=client.call("POST","/api/v1/cms/homepage-blocks",body)
        created=[row for row in rows() if row[0] not in before]
        return response,snapshot(created),created
    finally:
        created=[row for row in rows() if row[0] not in before]
        if created: db("DELETE FROM cms_homepage_blocks WHERE id IN ("+",".join(row[0] for row in created)+")")

def main():
    seed_full(); clients={base:{email:client_for(host,email) for email in (SUPER,AGEN)} for base,host in (("L",LARAVEL),("G",GO))}; failures=[]
    for case in CASES:
        label,body,*override=case; role=override[0] if override else SUPER
        left,db_l,_=run(clients["L"][role],body); right,db_g,_=run(clients["G"][role],body)
        if left[0]!=right[0]: failures.append(f"{label} status {left[0]} vs {right[0]}")
        failures += [f"{label} response {x}" for x in diff(norm_body(left[1]),norm_body(right[1]))[:8]]
        failures += [f"{label} DB {x}" for x in diff(db_l,db_g)[:8]]
    check("CMS homepage-block POST JSON (validation + HTML + metadata + RBAC)",not failures,"; ".join(failures[:24]))
    failed=[item for item in f.results if not item[1]]; print(f"{len(f.results)-len(failed)} passed, {len(failed)} failed"); return 1 if failed else 0

if __name__=="__main__": sys.exit(main())
