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
# data: second net exists already (Net 2 outdoor). add an indoor net with the same opening hours; payments on
psql("INSERT INTO nets (club_id,name,kind,member_price_pence,nonmember_price_pence,machine_price_pence,machine_coach_only) VALUES (1,'Indoor 1','indoor',0,1500,NULL,true)")
psql("INSERT INTO opening_hours SELECT 3, weekday, is_closed, open_hour, close_hour FROM opening_hours WHERE net_id=1")
psql("UPDATE clubs SET stripe_account_id='acct_x', stripe_charges_enabled=true WHERE id=1")
d=datetime.date.today()+datetime.timedelta(days=9)
start=f'{d.isoformat()}T10'
v=client()
s,h,_=req(v,'/club/penshurst-park')
ok('type=outdoor' in h and 'type=indoor' in h,'visitor sees Outdoor and Indoor tabs')
ok('Outdoor nets diary' in h,'defaults to outdoor')
s,h,_=req(v,'/club/penshurst-park?type=indoor&week='+d.isoformat())
ok('Indoor nets diary' in h and '£15' in h,'indoor view shows indoor price')
ok(f'/reserve?type=indoor&amp;start={start}' in h or f'/reserve?type=indoor&start={start}' in h,'indoor Book link carries the type, not a net')
def book(typ,n):
    s,h,_=req(v,f'/club/penshurst-park/reserve?type={typ}&start={start}')
    if s!=200: return s,h,None
    data={'csrf':csrf(h),'type':typ,'start':start,'kind':'net','customer_name':f'V{n}','customer_email':f'v{n}@x.com','customer_phone':'07123456789','accept_terms':'1'}
    s2,b2,hd=req(v,f'/club/penshurst-park/reserve?type={typ}&start={start}',data); return s2,b2,hd.get('Location')
s,b,loc=book('outdoor',1); ok(s==303,'first outdoor booking proceeds to payment')
n1=psql(f"select net_id from bookings where customer_name='V1'")
s,b,loc=book('outdoor',2); ok(s==303,'second outdoor booking proceeds')
n2=psql(f"select net_id from bookings where customer_name='V2'")
ok({n1,n2}=={'1','2'},f'two outdoor bookings were given different nets ({n1},{n2})')
s,h,hd=req(v,f'/club/penshurst-park/reserve?type=outdoor&start={start}')
ok(s==302 or s==303,'third outdoor hour refused: all outdoor nets taken'); 
s,h,_=req(v,'/club/penshurst-park?type=outdoor&week='+d.isoformat()); 
ok(f'type=outdoor&amp;start={start}' not in h and f'type=outdoor&start={start}' not in h and 'BOOKED' in h,'outdoor diary now shows the hour as BOOKED')
s,h,_=req(v,'/club/penshurst-park?type=indoor&week='+d.isoformat())
ok(f'type=indoor&start={start}' in h or f'type=indoor&amp;start={start}' in h,'indoor hour still free')
s,b,loc=book('indoor',3); ok(s==303 and psql("select net_id from bookings where customer_name='V3'")=='3','indoor booking goes to the indoor net')
print('FAILS',fails)
