import urllib.request, urllib.parse, http.cookiejar, re, subprocess, datetime
BASE='http://127.0.0.1:8099'
class NR(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*a,**k): return None
def client(): return urllib.request.build_opener(NR, urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def req(c,path,data=None):
    body=urllib.parse.urlencode(data,doseq=True).encode() if data is not None else None
    try: r=c.open(BASE+path,body); return r.status,r.read().decode(),r.headers
    except urllib.error.HTTPError as e: return e.code,e.read().decode(),e.headers
def psql(sql): return subprocess.run(['psql','-d','pmc_test','-tAc',sql],capture_output=True,text=True).stdout.strip()
fails=0
def ok(c,m):
    global fails; print(('PASS ' if c else 'FAIL ')+m); fails+=0 if c else 1
def csrf(h): return re.search(r'name="csrf" value="([0-9a-f]+)"',h).group(1)
def login(e,pw='correct-horse-1'):
    c=client(); s,h,_=req(c,'/login'); s,b,hd=req(c,'/login',{'csrf':csrf(h),'email':e,'password':pw}); return c,hd.get('Location')
psql("UPDATE clubs SET stripe_account_id='acct_x', stripe_charges_enabled=true, status='active' WHERE id=1")
tid=psql("select id from coaches where name='Trevor Bayley'")
psql("delete from coach_availability"); psql("delete from coach_pattern"); psql("delete from coach_pattern_weeks")
t,loc=login('trevor@test.local'); ok(loc=='/coach/availability','coach lands on availability page (%s)'%loc)
s,h,_=req(t,'/coach/availability'); ok('Welcome' in h,'welcome box when no hours')
ok(h.find('Standing weekly pattern')!=-1 and h.find('Standing weekly pattern')<h.find('Change a single week'),'pattern before single week')
s,h,_=req(t,'/coach/availability'); c=csrf(h)
# book an hour
d=datetime.date.today()+datetime.timedelta(days=4)
psql(f"INSERT INTO coach_availability (coach_id, starts_at) VALUES ({tid}, '{d.isoformat()} 10:00:00'),({tid}, '{d.isoformat()} 11:00:00')")
nid=psql("select id from nets where club_id=1 limit 1")
psql(f"INSERT INTO bookings (reference, net_id, coach_id, booking_type, starts_at, ends_at, status, customer_name, customer_email, customer_phone, price_pence) VALUES ('PMCTEST1',{nid},{tid},'coach','{d.isoformat()} 10:00:00','{d.isoformat()} 11:00:00','confirmed','Cus Tomer','c@x.test','0',2500)")
s,h,_=req(t,'/coach/availability'); ok('Booked' in h and 'disabled' in h,'booked hour shown locked')
ok('My upcoming sessions' in h and 'Cus Tomer' in h,'upcoming sessions listed')
# reset via pattern save with nothing ticked must keep the booked hour
req(t,'/coach/availability',{'csrf':csrf(h),'action':'pattern'})
n0=psql(f"select count(*) from coach_availability where coach_id={tid} and starts_at='{d.isoformat()} 10:00:00'")
ok(n0=='1','booked hour survives pattern save (empty pattern)')
mon=d-datetime.timedelta(days=d.weekday())
req(t,'/coach/availability',{'csrf':csrf(h),'action':'week','week':str(mon)})
ok(psql(f"select count(*) from coach_availability where coach_id={tid} and starts_at='{d.isoformat()} 10:00:00'")=='1','booked hour survives week save with nothing ticked')
ok(psql(f"select count(*) from coach_availability where coach_id={tid} and starts_at='{d.isoformat()} 11:00:00'")=='0','unbooked hour removed')
req(t,'/coach/availability',{'csrf':csrf(h),'action':'reset','week':str(mon)})
n=psql(f"select count(*) from coach_availability where coach_id={tid} and starts_at='{d.isoformat()} 10:00:00'")
# manager panel
j,_=login('jake@test.local'); s,h,_=req(j,'/club/penshurst-park'); ok('Manage coaches' in h,'panel visible to manager')
s,h,_=req(t,'/club/penshurst-park'); ok('Manage coaches' not in h,'panel hidden from coach')
cs=csrf(h) if 'csrf' in h else None
s,h,_=req(j,'/club/penshurst-park'); cs=csrf(h)
def post(data): data.update({'csrf':cs,'club_id':'1'}); return req(j,'/coaches/manage',data)
post({'action':'add','name':'Newbie Coach','price':'22.50','qual':'None (no formal qualification)','email':'newbie@coach.test'})
row=psql("select hourly_price_pence||'|'||dbs_checked::text||'|'||coalesce(email,'') from coaches where name='Newbie Coach'")
ok(row=='2250|false|newbie@coach.test','coach added with no DBS tick (%s)'%row)
ok(psql("select count(*) from users where email='newbie@coach.test'")=='1','coach login created')
nc=psql("select id from coaches where name='Newbie Coach'")
post({'action':'add','name':'Bad','price':'0','qual':'Other','email':'x'}); ok(psql("select count(*) from coaches where name='Bad'")=='0','bad add refused')
post({'action':'save','coach_id':nc,'name':'Newbie Coach','price':'30','qual':'Other','qual_other':'Level 2 badminton','email':'newbie@coach.test'})
ok(psql(f"select hourly_price_pence||qualification from coaches where id={nc}")=='3000Level 2 badminton','edit price and qualification')
post({'action':'confirmlink','coach_id':nc})
ok(psql("select count(*) from email_outbox where subject ilike '%DBS%' or body ilike '%Newbie Coach%'")!='0','officer link emailed')
# rename clears tick
psql(f"update coaches set dbs_checked=true, dbs_checked_on=current_date where id={nc}")
post({'action':'save','coach_id':nc,'name':'Newbie Renamed','price':'30','qual':'Other','qual_other':'Level 2 badminton','email':'newbie@coach.test'})
ok(psql(f"select dbs_checked::text from coaches where id={nc}")=='false','rename clears DBS tick')
# deactivate refused with upcoming booking
post({'action':'deactivate','coach_id':tid}); ok(psql(f"select active::text from coaches where id={tid}")=='true','deactivate refused with upcoming booking')
post({'action':'deactivate','coach_id':nc}); ok(psql(f"select active::text from coaches where id={nc}")=='false','deactivate ok')
s,h,_=req(client(),'/club/penshurst-park'); ok('Newbie Renamed' not in h,'inactive coach hidden publicly')
post({'action':'activate','coach_id':nc}); ok(psql(f"select active::text from coaches where id={nc}")=='true','reactivated')
# other manager cannot
a,_=login('adam@test.local'); s,h,_=req(a,'/club/penshurst-park'); 
s,h2,_=req(a,'/club/penshurst-park'); s,b,_=req(a,'/coaches/manage',{'csrf':csrf(h2) if 'csrf' in h2 else 'x','club_id':'1','action':'deactivate','coach_id':nc}); print(s); ok( psql(f"select active::text from coaches where id={nc}")=='true','coach cannot manage coaches')
print('FAILS',fails)
