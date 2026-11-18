import urllib.request, urllib.parse, http.cookiejar, re, subprocess, datetime, hmac, hashlib
BASE='http://127.0.0.1:8099'
class NR(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*a,**k): return None
def client(): return urllib.request.build_opener(NR, urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def req(c,path,data=None):
    body=urllib.parse.urlencode(data).encode() if data is not None else None
    try: r=c.open(BASE+path,body); return r.status,r.read().decode(),r.headers
    except urllib.error.HTTPError as e: return e.code,e.read().decode(),e.headers
def psql(sql): return subprocess.run(['psql','-d','pmc_test','-qtAc',sql],capture_output=True,text=True).stdout.strip()
fails=0
def ok(c,m):
    global fails; print(('PASS ' if c else 'FAIL ')+m); fails+=0 if c else 1
def csrf(h): return re.search(r'name="csrf" value="([0-9a-f]+)"',h).group(1)
def login(e):
    c=client(); s,h,_=req(c,'/login'); req(c,'/login',{'csrf':csrf(h),'email':e,'password':'correct-horse-1'}); return c
psql("UPDATE clubs SET stripe_account_id='acct_x', stripe_charges_enabled=true, status='active' WHERE id=1")
d=datetime.date.today()+datetime.timedelta(days=9)
v=client()
def book(hour,email,name,**extra):
    start=f'{d.isoformat()}T{hour}'
    s,h,_=req(v,f'/club/penshurst-park/reserve?type=outdoor&start={start}')
    data={'csrf':csrf(h),'type':'outdoor','start':start,'kind':'net','customer_name':name,'customer_email':email,'customer_phone':'07123456789','accept_terms':'1'}; data.update(extra)
    return req(v,f'/club/penshurst-park/reserve?type=outdoor&start={start}',data),h
(r,h)=book('10','Ann@Example.com','Ann One',mk_pmc='1',mk_club='1',mk_events='1')
ok('name="mk_pmc"' in h and 'name="mk_club"' in h and 'name="mk_events"' in h and 'checked' not in re.search(r'name="mk_pmc"[^>]*>',h).group(0),'boxes shown and unticked by default')
ok('events from Penshurst Park Cricket Club (the club will see' in h,'club named in the wording')
ok('never sell or give your details' in h,'no-third-party statement on the form')
(r,_h)=book('11','bob@example.com','Bob Two')
ok(psql("select count(*) from marketing_optins where email='bob@example.com'")=='0','nothing saved when boxes left unticked')
(r,_h)=book('12','cat@example.com','Cat Three',mk_club='1')
ok(psql("select scope from marketing_optins where email='cat@example.com'")=='club-1','club-only consent saved to the club list only')
ok(psql("select count(*) from marketing_optins where email='ann@example.com'")=='3','all three lists saved, email lowercased')
ok('other clubs on PlayMoreCricket' in psql("select wording from marketing_optins where email='ann@example.com' and scope='pmc'"),'exact wording stored')
ok(psql("select wording from marketing_optins where email='ann@example.com' and scope='events'")=='Email me about cricket activities, events and offers.','events wording stored')
# make Ann's booking confirmed for count
psql("update bookings set status='confirmed' where customer_email='Ann@Example.com'")
j=login('jake@test.local'); s,h,_=req(j,'/club/penshurst-park')
ok('Players who agreed to hear from' in h and 'Ann One' in h and 'Cat Three' in h and 'Bob Two' not in h,'manager sees only opted-in players')
s,b,hd=req(j,'/players/penshurst-park.csv'); ok(s==200 and 'text/csv' in hd.get('Content-Type','') and 'ann@example.com' in b and 'bob@example.com' not in b,'club CSV has opted-in only')
s,b,hd=req(client(),'/players/penshurst-park.csv'); ok(s in (302,303,403),'CSV needs login (%s)'%s)
t=login('trevor@test.local'); s,b,_=req(t,'/players/penshurst-park.csv'); ok(s==403,'coach cannot download list')
a=login('admin@test.local'); s,b,_=req(a,'/admin/players.csv'); ok(s==200 and 'ann@example.com' in b and 'cat@example.com' not in b,'admin PlayMoreCricket list has only PMC opt-ins')
s,h,_=req(a,'/admin'); ok('agreed to hear from PlayMoreCricket' in h and 'activities, events and offers' in h,'admin sees both counts')
s,b,_=req(a,'/admin/players.csv?scope=events'); ok('ann@example.com' in b and 'cat@example.com' not in b,'events list separate')
# formula injection
psql("insert into marketing_optins (email,name,scope,wording,consented_at) values ('f@x.test','=HYPERLINK(1)','pmc','w',now())")
s,b,_=req(a,'/admin/players.csv'); ok('"\'=HYPERLINK(1)"' in b,'spreadsheet formulas neutralised')
# unsubscribe
key='test-app-secret'
tok=hmac.new(key.encode(),b'unsub:ann@example.com',hashlib.sha256).hexdigest()[:24]
u=client(); s,h,_=req(u,f'/unsubscribe?e=ann@example.com&t=wrong'); ok(s==404,'bad unsubscribe token refused')
s,h,_=req(u,f'/unsubscribe?e=ann@example.com&t={tok}'); ok(s==200 and 'Yes, unsubscribe' in h,'unsubscribe page opens (no change on GET)')
ok(psql("select count(*) from marketing_optins where email='ann@example.com' and withdrawn_at is null")=='3','GET alone does not unsubscribe')
s,h,_=req(u,f'/unsubscribe?e=ann@example.com&t={tok}',{'csrf':csrf(h),'e':'ann@example.com','t':tok}); ok('You are unsubscribed' in h,'unsubscribed')
ok(psql("select count(*) from marketing_optins where email='ann@example.com' and withdrawn_at is null")=='0','all of Ann\'s lists withdrawn')
s,b,_=req(j,'/players/penshurst-park.csv'); ok('ann@example.com' not in b and 'cat@example.com' in b,'withdrawn player drops off the list')
# re-consent clears withdrawal
(r,_h)=book('13','ann@example.com','Ann One',mk_pmc='1'); ok(psql("select count(*) from marketing_optins where email='ann@example.com' and withdrawn_at is null")=='1','ticking again re-consents')
s,h,_=req(client(),'/privacy'); ok(s==200 and 'Privacy notice' in h and 'DRAFT' in h and 'any external party or other third party' in h,'privacy notice page says lists are not given to third parties')
s,h,_=req(client(),'/'); ok('href="/privacy"' in h,'privacy link in footer')
print('FAILS',fails)
