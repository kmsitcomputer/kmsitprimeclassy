#!/usr/bin/env python3
"""Differential SMTP config write/secret-preservation test; settings and audit rows are restored."""
import importlib.util,json,pathlib,uuid
ROOT=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location("return_review",ROOT/"tools/parity/compare_return_review.py")
r=importlib.util.module_from_spec(spec);spec.loader.exec_module(r)
def quote(v): return "NULL" if v is None or v=="NULL" else "'"+str(v).replace("'","''").replace("\\","\\\\")+"'"
def save(rows):
    r.db("DELETE FROM settings WHERE `group`='integration.smtp'")
    for row in rows: r.db("INSERT INTO settings (id,`key`,value,`group`,is_public,created_at,updated_at) VALUES ("+",".join(quote(x) for x in row)+")")
def normalize_cli(value):
    if isinstance(value,dict): return {k:normalize_cli(v) for k,v in value.items()}
    if isinstance(value,list): return [normalize_cli(v) for v in value]
    if isinstance(value,str):
        import re
        value=value.replace(r'\/','/').replace(r'\n','\n')
        return re.sub(r'\\u([0-9a-fA-F]{4})',lambda m:chr(int(m.group(1),16)),value)
    return value
def differences(a,b,path='$'):
    if isinstance(a,dict) and isinstance(b,dict):
        out=[]
        for k in sorted(set(a)|set(b)):
            if k not in a or k not in b: out.append(f'{path}.{k}: missing')
            else: out.extend(differences(a[k],b[k],path+'.'+k))
        return out
    if isinstance(a,list) and isinstance(b,list):
        if len(a)!=len(b): return [f'{path}: len {len(a)} != {len(b)}']
        return sum((differences(x,y,f'{path}[{i}]') for i,(x,y) in enumerate(zip(a,b))),[])
    return [] if a==b else [f'{path}: {a!r} != {b!r}']
def main():
    if r.db('SELECT DATABASE()')[0][0]!='primeclassy_testing': raise RuntimeError('refusing non-testing database')
    token=uuid.uuid4().hex[:8]; secret='IMP010-SMTP-SYNTHETIC-'+token; outputs=[]
    for base in (r.LARAVEL,r.GO):
        old=r.db("SELECT id,`key`,value,`group`,is_public,created_at,updated_at FROM settings WHERE `group`='integration.smtp' ORDER BY id")
        client=r.Client(base); client.login('super@imp010.test')
        log_before=int(r.db('SELECT COALESCE(MAX(id),0) FROM activity_logs')[0][0])
        try:
            invalid=client.call('PUT','/api/v1/admin/integrations/smtp',json.dumps({'host':'https://invalid'}).encode(),{'Content-Type':'application/json'})
            payload={'enabled':False,'transactional_enabled':True,'host':'smtp.synthetic.invalid','port':2525,'encryption':'tls','username':'synthetic-user','password':secret,'from_address':'smtp-test@example.invalid','from_name':'Synthetic','timeout':12}
            saved=client.call('PUT','/api/v1/admin/integrations/smtp',json.dumps(payload).encode(),{'Content-Type':'application/json'})
            retained=client.call('PUT','/api/v1/admin/integrations/smtp',b'{"password":""}',{'Content-Type':'application/json'})
            cleared=client.call('PUT','/api/v1/admin/integrations/smtp',b'{"clear_password":true}',{'Content-Type':'application/json'})
            state=r.db("SELECT `key`,value FROM settings WHERE `group`='integration.smtp' ORDER BY `key`")
            for row in state:
                if row[0]=='smtp.password' and row[1]: row[1]='<encrypted>'
            audit=r.db(f"SELECT event,CAST(properties AS CHAR) FROM activity_logs WHERE id>{log_before} AND event='smtp.settings.updated' ORDER BY id")
            for row in audit:
                if row[1] and row[1]!='NULL': row[1]=normalize_cli(json.loads(row[1]))
            if secret in json.dumps({'state':state,'audit':audit},ensure_ascii=False): raise AssertionError('SMTP password leaked')
            consumer=r.Client(base); consumer.login('konsumen@imp010.test')
            denied=consumer.call('PUT','/api/v1/admin/integrations/smtp',b'{"enabled":false}',{'Content-Type':'application/json'})
            outputs.append({'invalid':invalid,'saved':saved,'retained':retained,'cleared':cleared,'state':state,'audit':audit,'denied':denied})
        finally:
            ids=r.db(f"SELECT id FROM activity_logs WHERE id>{log_before} AND event='smtp.settings.updated'")
            for row in ids: r.db(f'DELETE FROM activity_logs WHERE id={row[0]}')
            save(old)
    if outputs[0]!=outputs[1]:
        print('admin SMTP update differential: FAIL'); print('\n'.join(differences(outputs[0],outputs[1])[:40])); return 1
    if [outputs[0][k][0] for k in ('invalid','saved','retained','cleared','denied')]!=[422,200,200,200,403]: raise AssertionError('SMTP status/auth contract failed')
    if outputs[0]['saved'][1]['data']['has_password'] is not True or outputs[0]['retained'][1]['data']['has_password'] is not True or outputs[0]['cleared'][1]['data']['has_password'] is not False: raise AssertionError('SMTP secret semantics failed')
    print('admin SMTP update differential: 9 passed, 0 failed'); return 0
if __name__=='__main__': raise SystemExit(main())
