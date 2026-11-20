#!/usr/bin/env python3
"""Differential admin/agent WhatsApp template preview, validation and RBAC."""
import importlib.util,pathlib,json
ROOT=pathlib.Path(__file__).resolve().parents[2]
def load(name,path):
    spec=importlib.util.spec_from_file_location(name,ROOT/path);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
events=load('wa_events',pathlib.Path('tools/parity/compare_whatsapp_events.py'))
def main():
    if events.db('SELECT DATABASE()')[0][0]!='primeclassy_testing': raise RuntimeError('refusing non-testing database')
    outs=[];template='Kurir {kurir}\nTelepon: {nomor_telepon_kurir}\nLacak: {link_pelacakan}'
    for base in (events.LARAVEL,events.GO):
        cs=events.clients(base)
        admin=cs[events.SUPER].call('POST','/api/v1/admin/integrations/whatsapp/templates/order_shipped/preview',{'template':template})
        bad=cs[events.SUPER].call('POST','/api/v1/admin/integrations/whatsapp/templates/order_shipped/preview',{'template':'Hi {unknown}'})
        agent=cs[events.AGEN].call('POST','/api/v1/agent/whatsapp/events/order_shipped/preview',{'template':template})
        denied=cs[events.SALES].call('POST','/api/v1/agent/whatsapp/events/order_shipped/preview',{'template':template})
        outs.append({'admin':admin,'bad':bad,'agent':agent,'denied':denied})
    if outs[0]!=outs[1]:
        print('admin WhatsApp preview differential: FAIL')
        for key in outs[0]:
            if outs[0][key]!=outs[1][key]: print(key,outs[0][key],outs[1][key])
        return 1
    if outs[0]['admin'][0]!=200 or outs[0]['agent'][0]!=200 or outs[0]['bad'][0]!=422 or outs[0]['denied'][0]!=403: raise AssertionError('preview status or RBAC mismatch')
    print('admin/agent WhatsApp preview differential: 8 passed, 0 failed');return 0
if __name__=='__main__':raise SystemExit(main())
