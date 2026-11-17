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
cid=psql("select id from coaches where name='Trevor Bayley'")
psql(f"delete from coach_availability where coach_id={cid}; delete from coach_pattern_weeks where coach_id={cid}; delete from coach_pattern where coach_id={cid}")
t=client(); s,h,_=req(t,'/login'); req(t,'/login',{'csrf':csrf(h),'email':'trevor@test.local','password':'correct-horse-1'})
s,h,_=req(t,'/coach/availability'); ok(s==200 and 'Standing weekly pattern' in h,'pattern grid shown')
# Tuesdays and Thursdays 17,18,19
slots=[f'{d}-{x}' for d in (1,3) for x in (17,18,19)]
s,h,_=req(t,'/coach/availability',{'csrf':csrf(h),'action':'pattern','pat[]':slots})
ok(s==303,'pattern saved')
ok(psql(f"select count(*) from coach_pattern where coach_id={cid}")=='6','6 pattern hours stored')
n=int(psql(f"select count(*) from coach_availability where coach_id={cid}"))
ok(40<=n<=72,f'future weeks filled automatically ({n} hours)')
ok(psql(f"select count(*) from coach_availability where coach_id={cid} and starts_at < now()")=='0','nothing in the past')
ok(psql(f"select count(*) from coach_pattern_weeks where coach_id={cid} and source='pattern'")=='12','12 weeks marked pattern')
# hand edit of a future week marks it manual and survives pattern change
mon=datetime.date.today(); mon=mon-datetime.timedelta(days=mon.weekday())+datetime.timedelta(days=14)
s,h,_=req(t,f'/coach/availability?week={mon}')
s,h2,_=req(t,'/coach/availability',{'csrf':csrf(h),'action':'week','week':mon.isoformat(),'slot[]':['0-9'],'repeat':'0'})
ok(psql(f"select source from coach_pattern_weeks where coach_id={cid} and week_start='{mon}'")=='manual','hand-edited week marked manual')
s,h,_=req(t,'/coach/availability')
req(t,'/coach/availability',{'csrf':csrf(h),'action':'pattern','pat[]':['2-18']})
ok(psql(f"select count(*) from coach_availability where coach_id={cid} and starts_at >= '{mon} 00:00' and starts_at < '{mon+datetime.timedelta(days=7)} 00:00'")=='1','manual week untouched by new pattern')
ok(psql(f"select count(*) from coach_pattern where coach_id={cid}")=='1','pattern replaced')
s,h,_=req(t,f'/coach/availability?week={mon}')
req(t,'/coach/availability',{'csrf':csrf(h),'action':'reset','week':mon.isoformat()})
ok(psql(f"select source from coach_pattern_weeks where coach_id={cid} and week_start='{mon}'")=='pattern','reset puts week back to pattern')
# reminders
psql(f"delete from coach_availability where coach_id={cid}; delete from coach_pattern_weeks where coach_id={cid}; delete from coach_pattern where coach_id={cid}; update coaches set availability_reminded_on=null")
psql("delete from email_outbox")
r=subprocess.run(['php','-r','require "src/bootstrap.php"; echo send_availability_reminders(true);'],capture_output=True,text=True,env={**__import__("os").environ,'PMC_MAIL_FAKE':'1'})
ok(r.stdout.strip().endswith('2'),'both coaches with logins reminded when next week is empty '+r.stdout.strip()+r.stderr[:100])
ok(psql("select count(*) from email_outbox where subject like 'Please set your coaching hours%'")=='2','reminder emails recorded')
r=subprocess.run(['php','-r','require "src/bootstrap.php"; echo send_availability_reminders(true);'],capture_output=True,text=True,env={**__import__("os").environ,'PMC_MAIL_FAKE':'1'})
ok(r.stdout.strip().endswith('0'),'not reminded twice the same day')
psql("update coaches set availability_reminded_on=null")
psql(f"insert into coach_pattern values ({cid},0,9),({cid},1,9),({cid},2,9),({cid},3,9),({cid},4,9),({cid},5,9),({cid},6,9)")
psql("delete from email_outbox")
r=subprocess.run(['php','-r','require "src/bootstrap.php"; echo send_availability_reminders(true);'],capture_output=True,text=True,env={**__import__("os").environ,'PMC_MAIL_FAKE':'1'})
ok(r.stdout.strip().endswith('1'),'coach with a pattern is not reminded')
print('FAILS',fails)
