import urllib.request, urllib.parse, http.cookiejar, re, datetime, subprocess, sys
BASE='http://127.0.0.1:8099'
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*a,**k): return None
def client():
    return urllib.request.build_opener(NoRedirect, urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def req(c,path,data=None):
    body=urllib.parse.urlencode(data,doseq=True).encode() if data is not None else None
    try:
        r=c.open(BASE+path,body); return r.status,r.read().decode(),r.headers
    except urllib.error.HTTPError as e: return e.code,e.read().decode(),e.headers
def csrf(h): return re.search(r'name="csrf" value="([0-9a-f]+)"',h).group(1)
def login(email):
    c=client(); _,h,_=req(c,'/login'); req(c,'/login',{'csrf':csrf(h),'email':email,'password':'correct-horse-1'}); return c
def flash(c,path='/club/penshurst-park'):
    _,h,_=req(c,path); m=re.search(r'class="notice[^"]*" role="status">([^<]*)<',h); return m.group(1) if m else ''
def psql(sql): return subprocess.run(['psql','-d','pmc_test','-tAc',sql],capture_output=True,text=True)
fails=0
def ok(cond,msg):
    global fails; print(('PASS ' if cond else 'FAIL ')+msg); fails+=0 if cond else 1

day=datetime.date.today()+datetime.timedelta(days=9); d=day.isoformat(); di=day.weekday()
nextday=(day+datetime.timedelta(days=7)).isoformat()
def book(c,net,hh,**f):
    start=f'{d}T{hh:02d}'
    _,h,_=req(c,f'/club/penshurst-park/book?net={net}&start={start}')
    data={'csrf':csrf(h),'net':net,'start':start}; data.update(f)
    s,_,hd=req(c,'/club/penshurst-park/book',data); return hd.get('Location') or ''
def avail_count(): return psql("select count(*) from coach_availability a join coaches c on c.id=a.coach_id where c.name='Trevor Bailey'").stdout.strip()

anon=client(); s,_,hd=req(anon,'/coach/availability'); ok(s==303 and hd['Location']=='/login','availability page needs login')
tre=login('trevor@test.local'); adam=login('adam@test.local'); jake=login('jake@test.local')

_,h,_=req(tre,f'/coach/availability?week={d}')
s,_,_=req(tre,'/coach/availability',{'csrf':csrf(h),'week':d,'slot[]':[f'{di}-15',f'{di}-16',f'{di}-17',f'{di}-22',f'{(di+1)%7}-3'],'repeat':'1'})
ok(avail_count()=='6','Trevor ticks 3 hours + repeats next week = 6 rows (22:00 and 03:00 outside opening hours ignored)')
ok(psql(f"select count(*) from coach_availability a join coaches c on c.id=a.coach_id where c.name='Trevor Bailey' and starts_at::date='{nextday}'").stdout.strip()=='3','repeat copied to the following week')
_,h,_=req(tre,f'/coach/availability?week={d}'); ok(h.count('checked')>=3,'saved hours show ticked when reopened')

# Adam may not edit Trevor's availability: the coach field is ignored for coach logins
_,h,_=req(adam,f'/coach/availability?week={d}')
req(adam,'/coach/availability',{'csrf':csrf(h),'week':d,'coach':'2','slot[]':[f'{di}-9'],'repeat':'0'})
ok(avail_count()=='6','coach login cannot change another coach (Trevor still 6)')
ok(psql("select count(*) from coach_availability a join coaches c on c.id=a.coach_id where c.name='Adam Ramson'").stdout.strip()=='1','...it saved to Adam himself instead')

s,h,_=req(anon,f'/club/penshurst-park?week={d}'); ok('1 coach' in h and 'Free' in h,'public diary shows "1 coach" on available hours')
s,h,_=req(jake,f'/club/penshurst-park/book?net=1&start={d}T16')
ok('Available this hour' in h and h.index('Trevor')<h.index('needs override') ,'booking form lists Trevor as available')
ok('Not marked available' in h and 'Jake Cheeseman' in h.split('needs override')[1],'...and Jake as needing override')

loc=book(jake,1,16,type='coach',coach_id=3,customer_name='P1'); ok('start=' in loc,'manager booking an unavailable coach is refused'); print('   ',flash(jake,loc))
loc=book(jake,1,16,type='coach',coach_id=3,customer_name='P1',override='1'); ok('start=' not in loc,'...allowed with override')
loc=book(jake,1,17,type='coach',coach_id=2,customer_name='P2'); ok('start=' not in loc,'manager books available Trevor at 17:00 (Net 1)')
s,h,_=req(anon,f'/club/penshurst-park?week={d}'); 
loc=book(jake,2,17,type='coach',coach_id=2,customer_name='P3'); ok('start=' in loc,'same coach cannot be booked on Net 2 at the same hour'); print('   ',flash(jake,loc))
r=psql(f"insert into bookings (reference,net_id,coach_id,booking_type,starts_at,ends_at,status,created_by_user_id) select 'DUP1',2,coach_id,'coach',starts_at,ends_at,'confirmed',2 from bookings where coach_id=2 and net_id=1 limit 1")
ok('unique' in (r.stderr or '').lower() or 'duplicate' in (r.stderr or '').lower(),'database itself refuses a second booking for the same coach and hour')
loc=book(tre,2,15,type='coach',customer_name='Own'); ok('start=' not in loc,'Trevor books himself on Net 2 at 15:00 (own availability)')
loc=book(jake,1,15,type='coach',coach_id=2,customer_name='Clash'); ok('start=' in loc,'...and now Net 1 at 15:00 is refused for Trevor'); print('   ',flash(jake,loc))
s,h,_=req(anon,f'/club/penshurst-park?week={d}'); ok('1 coach' in h,'public count still correct for other hours')

# manager sets Adam's availability
_,h,_=req(jake,f'/coach/availability?week={d}&coach=3')
req(jake,'/coach/availability',{'csrf':csrf(h),'week':d,'coach':'3','slot[]':[f'{di}-18'],'repeat':'0'})
ok(psql(f"select count(*) from coach_availability a where a.coach_id=3 and starts_at::date='{d}'").stdout.strip()=='1','manager can set a coach\'s availability')
print('\nFAILURES:',fails); sys.exit(1 if fails else 0)
