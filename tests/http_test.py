import urllib.request, urllib.parse, http.cookiejar, re, datetime, subprocess, sys
BASE='http://127.0.0.1:8099'
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*a,**k): return None
def client():
    cj=http.cookiejar.CookieJar()
    return urllib.request.build_opener(NoRedirect, urllib.request.HTTPCookieProcessor(cj))
def req(c,path,data=None):
    body=urllib.parse.urlencode(data).encode() if data is not None else None
    try:
        r=c.open(BASE+path,body); return r.status,r.read().decode(),r.headers
    except urllib.error.HTTPError as e:
        return e.code,e.read().decode(),e.headers
def csrf(html):
    m=re.search(r'name="csrf" value="([0-9a-f]+)"',html); return m.group(1)
def login(email,pw='correct-horse-1'):
    c=client(); _,h,_=req(c,'/login'); s,_,hd=req(c,'/login',{'csrf':csrf(h),'email':email,'password':pw}); return c,s,hd.get('Location')
def flash_of(c,path='/club/penshurst-park'):
    s,h,_=req(c,path); m=re.search(r'class="notice[^"]*" role="status">([^<]*)<',h); return m.group(1) if m else None
fails=0
def ok(cond,msg):
    global fails; print(('PASS ' if cond else 'FAIL ')+msg); fails+= 0 if cond else 1
def psql(sql): return subprocess.run(['psql','-d','pmc_test','-tAc',sql],capture_output=True,text=True).stdout.strip()

day=(datetime.date.today()+datetime.timedelta(days=9)); d=day.isoformat()
wk=d
def slot(hh): return f'{d}T{hh:02d}'
def book(c,start,**f):
    _,h,_=req(c,'/club/penshurst-park/book?start='+start)
    data={'csrf':csrf(h),'net':1,'start':start}; data.update(f)
    s,_,hd=req(c,'/club/penshurst-park/book',data); return s,hd.get('Location')

anon=client()
s,h,hd=req(anon,'/'); ok(s==200 and 'Penshurst Park Cricket Club' in h,'home lists the club')
ok("default-src 'self'" in hd.get('Content-Security-Policy',''),'security headers present')
s,h,_=req(anon,f'/club/penshurst-park?week={wk}'); ok(s==200 and 'Free' in h and 'Release' not in h and 'Book</a>' not in h,'anonymous sees Free, no Book/Release links')
ok('Jake Cheeseman' in h and 'DBS checked 2026' in h and 'class="tick"' in h and '3 Nov 2026' not in h and 'ECB Support Coach (Level 1)' in h,'coach qualification and DBS date shown publicly')
s,_,hd=req(anon,'/club/penshurst-park/book?start='+slot(10)); ok(s==303 and hd['Location']=='/login','anonymous booking page sends to login')
s,_,_=req(anon,'/admin'); ok(s==303,'admin needs login')

c,s,loc=login('jake@test.local','wrong-password'); ok(loc=='/login','wrong password rejected')
jake,s,loc=login('jake@test.local'); ok(loc=='/club/penshurst-park','club contact lands on club page')

s,loc=book(jake,slot(10),type='nonmember',customer_name='Sam Smith',customer_email='sam@x.com',customer_phone='07000')
ok(s==303 and 'start=' not in (loc or ''),'club contact books non-member hour'); print('   ',flash_of(jake))
ok(psql(f"select price_pence||','||status from bookings where customer_name='Sam Smith'")=='1000,confirmed','price £10 and confirmed')
s,h,_=req(anon,f'/club/penshurst-park?week={wk}'); ok('Sam Smith' not in h and 'BOOKED' in h,'public sees Booked, not the name')
s,h,_=req(jake,f'/club/penshurst-park?week={wk}'); ok('Sam Smith' in h and 'Release' in h,'staff sees the name and Release')

s,loc=book(jake,slot(10),type='member',customer_name='Dupe'); ok('start=' in (loc or ''),'double-booking refused'); print('   ',flash_of(jake,loc))
ok(psql("select count(*) from bookings where status='confirmed'")=='1','still exactly one booking')

s,loc=book(jake,slot(11),type='nonmember',customer_name='Mach',machine='1'); ok('start=' in (loc or ''),'machine refused without coach'); print('   ',flash_of(jake,loc))
s,loc=book(jake,slot(12),type='coach',coach_id=3,override='1',customer_name='Kid',machine='1',junior='1',child_first_name='Lee',child_age='11')
ok('start=' in (loc or ''),'junior without parent tick refused'); print('   ',flash_of(jake,loc))
s,loc=book(jake,slot(12),type='coach',coach_id=3,override='1',customer_name='Kid',machine='1',junior='1',child_first_name='Lee',child_age='11',parent_confirmed='1')
ok('start=' not in (loc or ''),'junior with parent tick accepted')
ok(psql("select price_pence||','||is_junior||','||child_age from bookings where child_first_name='Lee'") in ('5600,t,11','5600,1,11','5600,true,11'),'coach £44 + machine £12 = £56, junior details stored')
s,loc=book(jake,slot(22),type='member',customer_name='Late'); ok('start=' in (loc or ''),'closed hour refused'); print('   ',flash_of(jake,loc))
past=(datetime.date.today()-datetime.timedelta(days=1)).isoformat()+'T10'
s,loc=book(jake,past,type='member',customer_name='Old'); ok('start=' in (loc or ''),'past time refused'); print('   ',flash_of(jake,loc))
s,loc=book(jake,slot(13),type='blocked'); ok('start=' not in (loc or ''),'manager can block an hour with no name')

tre,_,_=login('trevor@test.local')
s,loc=book(tre,slot(14),type='nonmember',customer_name='X',customer_email='a@b.c',customer_phone='1'); ok('start=' in (loc or ''),'coach cannot make a non-member booking'); print('   ',flash_of(tre,loc))
s,loc=book(tre,slot(14),type='coach',coach_id=3,customer_name='Pupil'); ok('start=' not in (loc or ''),'coach books a coaching session')
ok(psql("select c.name from bookings b join coaches c on c.id=b.coach_id where customer_name='Pupil'")=='Trevor Bailey','coach_id forced to Trevor even when form asks for Adam')
bid=psql("select id from bookings where customer_name='Sam Smith'")
_,h,_=req(tre,f'/club/penshurst-park?week={wk}')
s,_,_=req(tre,'/club/penshurst-park/release',{'csrf':csrf(h),'booking_id':bid,'net':1,'week':wk}); print('   ',flash_of(tre))
ok(psql(f"select status from bookings where id={bid}")=='confirmed',"coach cannot release someone else's booking")
pid=psql("select id from bookings where customer_name='Pupil'")
req(tre,'/club/penshurst-park/release',{'csrf':csrf(h),'booking_id':pid,'net':1,'week':wk})
ok(psql(f"select status from bookings where id={pid}")=='cancelled','coach releases their own booking')
s,loc=book(jake,slot(14),type='member',customer_name='Rebook'); ok('start=' not in (loc or ''),'released hour can be booked again')

_,h,_=req(jake,f'/club/penshurst-park?week={wk}')
s,body,_=req(jake,'/club/penshurst-park/release',{'booking_id':bid,'net':1}); ok(s==400,'missing CSRF token refused')
s,body,_=req(jake,'/admin'); ok(s==403,'club contact cannot open admin')
adm,_,loc=login('admin@test.local'); ok(loc=='/admin','admin lands on admin')
_,h,_=req(adm,'/admin')
s,_,_=req(adm,'/admin/commission',{'csrf':csrf(h),'club_id':1,'commission_pct':'0','commission_free_until':'2027-11-03'})
ok(psql("select commission_pct||','||commission_free_until from clubs where id=1")=='0.00,2027-11-03','admin sets 0% until 3 Nov 2027')
s,_,_=req(adm,'/admin/commission',{'csrf':csrf(h),'club_id':1,'commission_pct':'150','commission_free_until':''}); ok(psql("select commission_pct from clubs where id=1")=='0.00','invalid commission rejected')
print('\nFAILURES:',fails); sys.exit(1 if fails else 0)
