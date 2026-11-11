import urllib.request, urllib.parse, http.cookiejar, re, subprocess, sys
BASE='http://127.0.0.1:8099'
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*a,**k): return None
def client(): return urllib.request.build_opener(NoRedirect, urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def req(c,path,data=None):
    body=urllib.parse.urlencode(data).encode() if data is not None else None
    try:
        r=c.open(BASE+path,body); return r.status,r.read().decode(),r.headers
    except urllib.error.HTTPError as e: return e.code,e.read().decode(),e.headers
def csrf(h): return re.search(r'name="csrf" value="([0-9a-f]+)"',h).group(1)
def flash(c,path='/admin'):
    _,h,_=req(c,path); m=re.search(r'class="notice[^"]*" role="status">([^<]*)<',h); return m.group(1) if m else ''
def psql(sql): return subprocess.run(['psql','-d','pmc_test','-tAc',sql],capture_output=True,text=True).stdout.strip()
fails=0
def ok(c,m):
    global fails; print(('PASS ' if c else 'FAIL ')+m); fails+=0 if c else 1
a=client()
s,h,_=req(a,'/setup'); ok(s==200 and 'Set up PlayMoreCricket' in h,'setup page open while there are no users')
req(a,'/setup',{'csrf':csrf(h),'name':'Chris','email':'chris@test.local','password':'short'}); ok(psql('select count(*) from users')=='0','short password refused')
req(a,'/setup',{'csrf':csrf(h),'name':'Chris','email':'chris@test.local','password':'a-long-password-1'}); ok(psql("select role from users where email='chris@test.local'")=='admin','first admin created')
s,_,_=req(client(),'/setup'); ok(s==404,'setup page gone once a user exists')
_,h,_=req(a,'/login'); s,_,hd=req(a,'/login',{'csrf':csrf(h),'email':'chris@test.local','password':'a-long-password-1'}); ok(hd.get('Location')=='/admin','admin logs in')
_,h,_=req(a,'/admin'); ok('1 update(s) waiting' in h and '001_coach_availability' in h,'admin sees 1 pending update')
req(a,'/admin/migrate',{'csrf':csrf(h)}); ok('Database updated' in flash(a),'Apply updates runs it')
ok(psql("select to_regclass('coach_availability') is not null")=='t','coach_availability table now exists')
_,h,_=req(a,'/admin'); ok('up to date' in h,'admin shows up to date'); req(a,'/admin/migrate',{'csrf':csrf(h)}); ok('Nothing to update' in flash(a),'running again does nothing')
# tolerant of a migration someone ran by hand
psql("delete from schema_migrations")
_,h,_=req(a,'/admin'); req(a,'/admin/migrate',{'csrf':csrf(h)}); f=flash(a); ok('Database updated' in f and 'stopped' not in f,'already-existing objects are skipped, not an error')
# user management
_,h,_=req(a,'/admin')
def add(**k):
    d={'csrf':csrf(h),'name':'T','email':'t@x.com','role':'coach','club_id':'1','coach_id':'0','password':'a-long-password-1'}; d.update(k); req(a,'/admin/users',d); return flash(a)
ok('at least 10' in add(password='short') and psql("select count(*) from users where email='t@x.com'")=='0','short password refused for new login')
add(name='Trevor Bailey',email='trevor@x.com',coach_id='2'); ok(psql("select u.email from coaches c join users u on u.id=c.user_id where c.id=2")=='trevor@x.com','coach login created and linked to Trevor\'s coach record')
ok('already has a login' in add(email='trevor@x.com'),'duplicate email refused')
add(name='Jake',email='jake@x.com',role='club_contact'); ok(psql("select role||club_id from users where email='jake@x.com'") in ('club_contact1',),'club contact created for Penshurst')
tre=client(); _,h2,_=req(tre,'/login'); s,_,hd=req(tre,'/login',{'csrf':csrf(h2),'email':'trevor@x.com','password':'a-long-password-1'})
s,h3,_=req(tre,'/coach/availability'); ok(s==200 and 'Trevor Bailey' in h3,'new coach can log in and see their availability page')
s,_,_=req(tre,'/admin'); ok(s==403,'coach cannot open admin')
ok(req(client(),'/admin/migrate',{'x':'1'})[0] in (303,),'anonymous cannot run updates')
print('\nFAILURES:',fails); sys.exit(1 if fails else 0)
