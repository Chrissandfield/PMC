import urllib.request, urllib.parse, http.cookiejar, re, subprocess, datetime
BASE='http://127.0.0.1:8099'
class NR(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*a,**k): return None
def client(): return urllib.request.build_opener(NR, urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def req(c,path,data=None):
    body=urllib.parse.urlencode(data).encode() if data is not None else None
    try: r=c.open(BASE+path,body); return r.status,r.read().decode(),r.headers
    except urllib.error.HTTPError as e: return e.code,e.read().decode(),e.headers
def psql(sql): return subprocess.run(['psql','-d','pmc_test','-tAc',sql],capture_output=True,text=True).stdout.strip()
fails=0
def ok(c,m):
    global fails; print(('PASS ' if c else 'FAIL ')+m); fails+=0 if c else 1
def csrf(h): return re.search(r'name="csrf" value="([0-9a-f]+)"',h).group(1)
psql("UPDATE clubs SET stripe_account_id='acct_x', stripe_charges_enabled=true WHERE id=1")
cid=psql("select id from coaches where name='Trevor Bayley'")
d=datetime.date.today()+datetime.timedelta(days=9); start=f'{d.isoformat()}T10'
psql(f"INSERT INTO coach_availability (coach_id, starts_at) VALUES ({cid}, '{d.isoformat()} 10:00:00')")
v=client()
def book(junior):
    s,h,_=req(v,f'/club/penshurst-park/reserve?type=outdoor&start={start}')
    data={'csrf':csrf(h),'type':'outdoor','start':start,'kind':'coach','coach_id':cid,'customer_name':'P','customer_email':'p@x.com','customer_phone':'07123456789','accept_terms':'1'}
    if junior: data.update({'junior':'1','child_first_name':'Sam','child_age':'10','parent_confirmed':'1'})
    return req(v,f'/club/penshurst-park/reserve?type=outdoor&start={start}',data)
psql(f"UPDATE coaches SET dbs_checked=false WHERE id={cid}")
s,h,_=book(True); ok(s==303 and 'checkout.stripe' not in _.get('Location',''),'junior + unconfirmed coach refused, no payment started')
ok(psql("select count(*) from bookings where is_junior")=='0','no junior booking created')
psql(f"UPDATE coaches SET dbs_checked=true WHERE id={cid}")
s,h,_=book(True); ok(s==303 and 'checkout.stripe' in _.get('Location',''),'junior + confirmed coach goes to payment')
print('FAILS',fails)
a=client(); s,h,_=req(a,'/login'); req(a,'/login',{'csrf':csrf(h),'email':'admin@test.local','password':'correct-horse-1'})
s,h,_=req(a,'/admin'); ok('Coaches and DBS checks' in h,'admin shows coach DBS panel')
s,b,_=req(a,'/admin/coach/dbs',{'csrf':csrf(h),'coach_id':cid,'action':'clear'})
ok(psql(f"select dbs_checked::int from coaches where id={cid}")=='0','tick removed')
s,h,_=req(a,'/admin'); req(a,'/admin/coach/dbs',{'csrf':csrf(h),'coach_id':cid,'action':'set','method':'certificate','checked_on':'2026-11-10','note':''})
ok(psql(f"select dbs_checked::int from coaches where id={cid}")=='0','no note refused')
s,h,_=req(a,'/admin'); req(a,'/admin/coach/dbs',{'csrf':csrf(h),'coach_id':cid,'action':'set','method':'certificate','checked_on':'2026-11-10','note':'Jake, saw certificate'})
ok(psql(f"select dbs_checked::int||dbs_method from coaches where id={cid}")=='1certificate','tick set by hand with note')
ok(psql("select count(*) from dbs_confirmations where confirmed_by like 'Administrator:%'")=='1','logged')
print('FAILS',fails)
