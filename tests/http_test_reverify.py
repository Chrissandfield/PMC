import urllib.request, urllib.parse, http.cookiejar, re, subprocess
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
psql("UPDATE clubs SET status='active', safeguarding_name='Laurie Hudson', safeguarding_contact='laurie@test.local', key_contact_name='Jake Cheeseman', key_contact_detail='jake@test.local', safeguarding_confirmed_at=now(), safeguarding_confirmed_by='Laurie Hudson' WHERE id=1")
psql("UPDATE coaches SET is_safeguarding_officer=false WHERE club_id=1")
psql("UPDATE coaches SET dbs_checked=true, dbs_checked_on='2026-11-03', dbs_method='certificate', dbs_confirmed_by='Laurie Hudson', dbs_enhanced=true WHERE club_id=1")
psql("INSERT INTO verification_tokens (club_id, token_hash, role, person_name, sent_to, expires_at) VALUES (1,'"+'a'*64+"','officer','Laurie Hudson','laurie@test.local', now() + interval '10 days')")
a=login('admin@test.local'); j=login('jake@test.local')
s,h,_=req(a,'/admin'); ok('Re-verify this club' in h and 'Re-verify all clubs' in h,'buttons on the admin page')
c=csrf(h)
s,_,_=req(j,'/admin/reverify',{'csrf':csrf(req(j,'/club/penshurst-park')[1]),'confirm':'1','club_id':'1'}); ok(s in (302,303,403),'club manager cannot use it (%s)'%s)
ok(psql("select count(*) from coaches where club_id=1 and dbs_checked")!='0','nothing changed without admin')
req(a,'/admin/reverify',{'csrf':c,'club_id':'1'}); ok(psql("select count(*) from coaches where club_id=1 and dbs_checked")!='0','needs the confirm tick')
req(a,'/admin/reverify',{'csrf':c,'club_id':'1','confirm':'1'})
ok(psql("select count(*) from coaches where club_id=1 and (dbs_checked or dbs_method is not null or dbs_confirmed_by is not null or dbs_enhanced)")=='0','all coach ticks cleared')
ok(psql("select safeguarding_confirmed_at is null from clubs where id=1")=='t','policy confirmation cleared')
ok(psql("select status from clubs where id=1")=='active','club stays live')
ok(psql("select count(*) from nets where club_id=1")!='0' and psql("select count(*) from coaches where club_id=1 and active")!='0','nothing else removed')
ok(psql("select count(*) from verification_tokens where token_hash='"+'a'*64+"' and expires_at > now()")=='0','old links cancelled')
body=psql("select body from email_outbox where to_email='laurie@test.local' order by id desc limit 1")
ok('re-checking every club' in body and '/verify/' in body and 'Trevor Bayley' in body and 'Adam Ramson' in body and 'Jake Cheeseman' in body,'officer emailed one new link listing every coach (%s)'%body[:60].replace('\n',' '))
ok(psql("select count(*) from email_outbox where to_email='laurie@test.local' and subject like 'Please re-confirm%'")=='1','one email for the officer')
tok=re.search(r'/verify/([0-9a-f]{48})',body).group(1)
v=client(); s,h,_=req(v,'/verify/'+tok); ok(s==200 and 'policy_ok' in h and 'Trevor Bayley' in h,'new link opens with policy tick and the coaches')
# junior booking refused while unticked
# officer who is also a coach
psql("UPDATE coaches SET is_safeguarding_officer=true WHERE name='Adam Ramson'")
req(a,'/admin/reverify',{'csrf':c,'club_id':'1','confirm':'1'})
body2=psql("select body from email_outbox where to_email='jake@test.local' order by id desc limit 1")
ok('cannot confirm their own' in body2 and 'Adam Ramson' in body2 and '/verify/' in body2,'coach-officer: key contact asked to confirm that coach')
ok('Adam Ramson' not in psql("select body from email_outbox where to_email='laurie@test.local' order by id desc limit 1").split('Coaches to confirm:')[1],'officer list excludes the coach who is the officer')
# bulk
n0=psql("select count(*) from email_outbox where to_email='laurie@test.local'")
req(a,'/admin/reverify',{'csrf':c,'confirm':'1'})
ok(int(psql("select count(*) from email_outbox where to_email='laurie@test.local'"))==int(n0)+1,'bulk action emails each live club\'s officer')
ok(psql("select count(*) from audit_log where action='club_reverification_requested'")=='3','each request audited')
print('FAILS',fails)
