import urllib.request, urllib.parse, http.cookiejar, re, subprocess
BASE='http://127.0.0.1:8099'
class NR(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*a,**k): return None
def client(): return urllib.request.build_opener(NR, urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def req(c,path,data=None):
    body=urllib.parse.urlencode(data).encode() if data is not None else None
    try: r=c.open(BASE+path,body); return r.status,r.read().decode(),r.headers
    except urllib.error.HTTPError as e: return e.code,e.read().decode(),e.headers
def psql(sql): return subprocess.run(['psql','-d','pmc_test','-tAc',sql],capture_output=True,text=True).stdout.strip()
def php(code): return subprocess.run(['php','-r','require "src/bootstrap.php"; require_once "src/enrol.php"; '+code],capture_output=True,text=True).stdout.strip()
fails=0
def ok(c,m):
    global fails; print(('PASS ' if c else 'FAIL ')+m); fails+=0 if c else 1
def csrf(h): return re.search(r'name="csrf" value="([0-9a-f]+)"',h).group(1)
def login(e):
    c=client(); s,h,_=req(c,'/login'); req(c,'/login',{'csrf':csrf(h),'email':e,'password':'correct-horse-1'}); return c
s,h,_=req(client(),'/club-terms'); ok(s==200 and 'DRAFT' in h and '10%' in h and '24 hours' in h,'club terms page (draft) shows commission and cancellation rules')
# manager sees the checklist before payments are on
psql("UPDATE clubs SET status='active', sponsored_partner=false, stripe_account_id=NULL, stripe_charges_enabled=false, stripe_checked_at=NULL WHERE id=1")
j=login('jake@test.local'); s,h,_=req(j,'/club/penshurst-park')
ok("A bank account in the club" in h and 'Set up payments' in h,'checklist shown next to Set up payments')
# status refresh: account exists but flag is stale
psql("UPDATE clubs SET stripe_account_id='acct_fake_1', stripe_charges_enabled=false, stripe_checked_at=NULL WHERE id=1")
s,h,_=req(j,'/club/penshurst-park'); ok('Payments are on' in h,'club page refreshes an unfinished Stripe account and shows it ready')
ok(psql("select stripe_charges_enabled::int from clubs where id=1")=='1','ready flag saved')
psql("UPDATE clubs SET stripe_charges_enabled=false, stripe_checked_at=NULL WHERE id=1")
a=login('admin@test.local'); req(a,'/admin'); ok(psql("select stripe_charges_enabled::int from clubs where id=1")=='1','admin page also refreshes')
psql("UPDATE clubs SET stripe_charges_enabled=false, stripe_checked_at=now() WHERE id=1")
req(a,'/admin'); ok(psql("select stripe_charges_enabled::int from clubs where id=1")=='0','refresh is throttled to once per 5 minutes')
# reminders
psql("UPDATE clubs SET stripe_account_id=NULL, stripe_charges_enabled=false, approved_at=now() - interval '5 days', stripe_reminded_at=NULL, stripe_reminders=0, key_contact_name='Kay', key_contact_detail='kay@test.local' WHERE id=1; DELETE FROM email_outbox")
ok(php('echo send_stripe_reminders();')=='1','first reminder sent after 3 days')
ok("A bank account in the club" in psql("select body from email_outbox where to_email='kay@test.local' and subject like 'Finish setting up payments%'"),'reminder carries the checklist')
ok(php('echo send_stripe_reminders();')=='0','not reminded again straight away')
psql("UPDATE clubs SET stripe_reminded_at=now() - interval '8 days' WHERE id=1"); ok(php('echo send_stripe_reminders();')=='1','second reminder a week later')
psql("UPDATE clubs SET stripe_reminded_at=now() - interval '8 days' WHERE id=1"); ok(php('echo send_stripe_reminders();')=='1','third reminder')
psql("UPDATE clubs SET stripe_reminded_at=now() - interval '8 days' WHERE id=1"); ok(php('echo send_stripe_reminders();')=='0','no more than three reminders')
psql("UPDATE clubs SET approved_at=now() - interval '1 days', stripe_reminders=0, stripe_reminded_at=NULL WHERE id=1"); ok(php('echo send_stripe_reminders();')=='0','not reminded before 3 days')
psql("UPDATE clubs SET approved_at=now() - interval '5 days', stripe_charges_enabled=true WHERE id=1"); ok(php('echo send_stripe_reminders();')=='0','no reminder once payments are on')
print('FAILS',fails)
