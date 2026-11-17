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
def login(e):
    c=client(); s,h,_=req(c,'/login'); req(c,'/login',{'csrf':csrf(h),'email':e,'password':'correct-horse-1'}); return c
tid=psql("select id from coaches where name='Trevor Bayley'"); aid=psql("select id from coaches where name='Adam Ramson'")
d=(datetime.date.today()+datetime.timedelta(days=5)).isoformat()
psql(f"""INSERT INTO bookings (reference,net_id,coach_id,booking_type,starts_at,ends_at,with_machine,status,customer_name,customer_email,customer_phone,is_junior,parent_confirmed,child_first_name,child_age,price_pence,commission_pence,stripe_account_id) VALUES
('PMC-ICAL01',1,{tid},'coach','{d} 17:00','{d} 18:00',true,'confirmed','Pat, Smith','pat@secret.com','07123456789',true,true,'Sam',10,3000,300,'acct_x'),
('PMC-ICAL02',1,{aid},'coach','{d} 18:00','{d} 19:00',false,'confirmed','Lee','lee@x.com','07123456780',false,false,null,null,3000,300,'acct_x'),
('PMC-ICAL03',2,null,'nonmember','{d} 12:00','{d} 13:00',false,'refunded','Cancelled Cathy','c@x.com','07123456781',false,false,null,null,1000,100,'acct_x')""")
t=login('trevor@test.local'); s,h,_=req(t,'/coach/availability')
ok('Add bookings to your calendar' in h,'coach sees calendar box')
url=re.search(r'class="feedurl" value="([^"]+)"',h).group(1); path=url.replace('http://127.0.0.1:8099','')
s,ics,hd=req(client(),path)
ok(s==200 and hd['Content-Type'].startswith('text/calendar'),'feed served without login')
ok('PMC-ICAL01@playmorecricket.com' in ics and 'PMC-ICAL02' not in ics,"coach feed has only that coach's booking")
ok('UNDER-18: Sam\, aged 10' in ics.replace('\r\n ','') and 'Bowling machine' in ics.replace('\r\n ',''),'junior and machine noted')
ok('secret.com' not in ics and '0712' not in ics,'no customer email or phone in feed')
ok('Pat\\, Smith' in ics,'text escaped')
ok(all(len(l)<=75 for l in ics.split('\r\n')),'lines folded')
ok(ics.startswith('BEGIN:VCALENDAR') and ics.rstrip().endswith('END:VCALENDAR'),'valid wrapper')
s,_,_=req(client(),'/calendar/coach/'+'0'*32+'.ics'); ok(s==404,'unknown token 404')
j=login('jake@test.local'); s,h,_=req(j,'/club/penshurst-park'); ok('Add bookings to your calendar' in h,'club manager sees calendar box')
curl=re.search(r'class="feedurl" value="([^"]+)"',h).group(1).replace('http://127.0.0.1:8099','')
s,ics,_=req(client(),curl); ok('PMC-ICAL01' in ics and 'PMC-ICAL02' in ics and 'PMC-ICAL03' not in ics,'club feed has all confirmed, not refunded')
ok('Coaching with Trevor Bayley' in ics,'club feed names coach')
a=login('adam@test.local'); s,h,_=req(a,'/club/penshurst-park'); ok('Add bookings to your calendar' not in h,'coach does not see club-wide link')
s,h,_=req(a,'/coach/availability'); 
s,_,hd=req(a,'/calendar/renew',{'csrf':csrf(h),'kind':'coach','id':tid,'return':'/'}); ok(s==403,"a coach cannot renew another coach's link")
s,h,_=req(t,'/coach/availability'); s,_,hd=req(t,'/calendar/renew',{'csrf':csrf(h),'kind':'coach','id':tid,'return':'/coach/availability'})
ok(s==303 and hd['Location']=='/coach/availability','own link renewed')
s,_,_=req(client(),path); ok(s==404,'old link stops working')
s,h,_=req(t,'/coach/availability'); np=re.search(r'class="feedurl" value="([^"]+)"',h).group(1).replace('http://127.0.0.1:8099',''); ok(np!=path,'new link differs')
s,_,hd=req(t,'/calendar/renew',{'csrf':csrf(h),'kind':'coach','id':tid,'return':'//evil.com'}); ok(hd['Location']=='/','open redirect blocked')
print('FAILS',fails)
