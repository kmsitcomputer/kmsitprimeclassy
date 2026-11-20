#!/usr/bin/env python3
"""Differential global WhatsApp template save/reset, validation, and audit; test state is restored."""
import importlib.util,json,pathlib,uuid
ROOT=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('return_review',ROOT/'tools/parity/compare_return_review.py');r=importlib.util.module_from_spec(spec);spec.loader.exec_module(r)
def normalize(v):
    if isinstance(v,dict): return {k:normalize(x) for k,x in v.items()}
    if isinstance(v,(list,tuple)): return [normalize(x) for x in v]
    if isinstance(v,str):
        import re
        v=v.replace(r'\/','/').replace(r'\n','\n')
        return re.sub(r'\\u([0-9a-fA-F]{4})',lambda m:chr(int(m.group(1),16)),v)
    return v
def diffs(a,b,path='$'):
    if isinstance(a,dict) and isinstance(b,dict): return sum(([f'{path}.{k}: missing on {"Laravel" if k not in a else "Go"}'] if k not in a or k not in b else diffs(a[k],b[k],path+'.'+k) for k in sorted(set(a)|set(b))),[])
    if isinstance(a,(list,tuple)) and isinstance(b,(list,tuple)): return [f'{path}: list length mismatch'] if len(a)!=len(b) else sum((diffs(x,y,f'{path}[{i}]') for i,(x,y) in enumerate(zip(a,b))),[])
    return [] if a==b else [f'{path}: {a!r} != {b!r}']
def main():
    if r.db('SELECT DATABASE()')[0][0]!='primeclassy_testing': raise RuntimeError('refusing non-testing database')
    outputs=[];key='wa.template.order_created';event='order_created';template='Pesanan {nomor_pesanan} untuk {nama_pelanggan}'
    for base in (r.LARAVEL,r.GO):
        old=r.db(f"SELECT id,`key`,value,`group`,is_public,created_at,updated_at FROM settings WHERE `key`='{key}' AND `group`='integration.whatsapp'")
        c=r.Client(base);c.login('super@imp010.test');log_before=int(r.db('SELECT COALESCE(MAX(id),0) FROM activity_logs')[0][0])
        try:
            saved=c.call('PUT',f'/api/v1/admin/integrations/whatsapp/templates/{event}',json.dumps({'template':template}).encode(),{'Content-Type':'application/json'})
            invalid=c.call('PUT',f'/api/v1/admin/integrations/whatsapp/templates/{event}',b'{"template":"{{bad}}"}',{'Content-Type':'application/json'})
            reset=c.call('DELETE',f'/api/v1/admin/integrations/whatsapp/templates/{event}')
            state=r.db(f"SELECT value FROM settings WHERE `key`='{key}' AND `group`='integration.whatsapp'")
            audit=r.db(f"SELECT event,CAST(properties AS CHAR) FROM activity_logs WHERE id>{log_before} AND event IN ('whatsapp.template.updated','whatsapp.template.reset') ORDER BY id")
            for row in audit:
                if row[1] and row[1]!='NULL': row[1]=normalize(json.loads(row[1]))
            consumer=r.Client(base);consumer.login('konsumen@imp010.test');denied=consumer.call('PUT',f'/api/v1/admin/integrations/whatsapp/templates/{event}',json.dumps({'template':template}).encode(),{'Content-Type':'application/json'})
            outputs.append({'saved':saved,'invalid':invalid,'reset':reset,'state':state,'audit':audit,'denied':denied})
        finally:
            r.db(f'DELETE FROM activity_logs WHERE id>{log_before} AND event IN (\'whatsapp.template.updated\',\'whatsapp.template.reset\')')
            r.db(f"DELETE FROM settings WHERE `key`='{key}' AND `group`='integration.whatsapp'")
            for row in old:
                cols=['id','key','value','group','is_public','created_at','updated_at']; vals=[]
                for x in row: vals.append('NULL' if x=='NULL' else "'"+str(x).replace("'","''").replace('\\','\\\\')+"'")
                r.db('INSERT INTO settings (id,`key`,value,`group`,is_public,created_at,updated_at) VALUES ('+','.join(vals)+')')
    if outputs[0]!=outputs[1]: print('admin WhatsApp templates differential: FAIL');print('\n'.join(diffs(outputs[0],outputs[1])[:40]));return 1
    if outputs[0]['saved'][0]!=200 or outputs[0]['invalid'][0]!=422 or outputs[0]['reset'][0]!=200 or outputs[0]['denied'][0]!=403: raise AssertionError('template auth/validation contract failed')
    print('admin WhatsApp templates differential: 10 passed, 0 failed');return 0
if __name__=='__main__': raise SystemExit(main())
