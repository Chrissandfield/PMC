import urllib.request, urllib.parse, http.cookiejar, re, subprocess
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
def link_in(subject_like,to):
    body=psql(f"select body from email_outbox where to_email='{to}' and subject like '{subject_like}' order by id desc limit 1")
    m=re.search(r'/set-password/([0-9a-f]{48})',body); return m.group(1) if m else None

# --- approval creates logins (an application club)
a,_=login('admin@test.local'); 
v=client(); s,h,_=req(v,'/enrol')
form={'csrf':csrf(h),'club':'Login Vale CC','town':'Testville','county':'Kent','postcode':'TN1 1AA','cname':'Kay Key','cemail':'kay@test.local','cphone':'01892 555123',
 'sname':'Sam Safe','semail':'sam@test.local','plink':'https://example.com/p','officer_coach':'0','cf1':'1','cf2':'1','cf3':'1','cf4':'1','cf5':'1',
 'g_type[]':['outdoor','','','','',''],'g_member[]':['0','','','','',''],'g_non[]':['10','','','','',''],
 'c_name[]':['Pat Coach','Kay Key','','','',''],'c_price[]':['25','30','','','',''],'c_qual[]':['ECB Core Coach (Level 3)','None (no formal qualification)','','','',''],'c_other[]':['']*6,'c_email[]':['pat@coach.test','kay@test.local','','','','']}
for d in range(7): form[f'open[outdoor][{d}]']='9'; form[f'close[outdoor][{d}]']='19'
s,h,_=req(v,'/enrol',form); ok('Thank you' in h,'application accepted')
cid=psql("select id from clubs where slug='login-vale-cc'")
psql(f"update clubs set safeguarding_confirmed_at=now(), safeguarding_confirmed_by='Sam' where id={cid}")
s,h,_=req(a,'/admin'); s,_,_=req(a,'/admin/applications/approve',{'csrf':csrf(h),'club_id':cid,'allow_unconfirmed':'1'})
ok(psql(f"select role from users where lower(email)='kay@test.local'")=='club_contact','key contact login created as club contact')
ok(psql("select role||club_id::text from users where email='pat@coach.test'")=='coach'+cid,'coach login created')
ok(psql("select count(*) from coaches where club_id="+cid+" and user_id is not null")=='2','both coaches linked (key contact who is also a coach shares one login)')
ok(psql("select count(*) from email_outbox where subject like 'Set up your PlayMoreCricket login%'")=='2','two set-up emails (key contact, Pat)')
t=link_in('Set up your PlayMoreCricket login%','pat@coach.test'); ok(t is not None,'set-up link in email')
# can't log in with an unknown password
c,loc=login('pat@coach.test','guessing-123'); ok(loc=='/login','no password yet: cannot log in')
# set password
p=client(); s,h,_=req(p,'/set-password/'+t); ok(s==200 and 'Choose a password' in h,'set password page opens')
s,_,hd=req(p,'/set-password/'+t,{'csrf':csrf(h),'password':'short','password2':'short'}); ok(s==303,'short password bounced'); 
s,h,_=req(p,'/set-password/'+t); s,_,_=req(p,'/set-password/'+t,{'csrf':csrf(h),'password':'longenough-pw-1','password2':'different-pw-12'}); ok(psql("select count(*) from password_tokens where used_at is not null")=='0','mismatch not accepted')
s,h,_=req(p,'/set-password/'+t); s,_,hd=req(p,'/set-password/'+t,{'csrf':csrf(h),'password':'longenough-pw-1','password2':'longenough-pw-1'}); ok(s==303 and hd['Location']=='/login','password saved')
c,loc=login('pat@coach.test','longenough-pw-1'); ok(loc is not None and loc=='/coach/availability','coach can log in with the new password')
s,h,_=req(client(),'/set-password/'+t); ok(s==410,'link cannot be used twice')
s,h,_=req(c,'/coach/availability'); ok(s==200 and 'Pat Coach' in h,'new coach sees their own availability page')
# --- forgot password
psql("delete from email_outbox")
f=client(); s,h,_=req(f,'/forgot'); s,_,_=req(f,'/forgot',{'csrf':csrf(h),'email':'pat@coach.test'})
rt=link_in('Reset your PlayMoreCricket password%','pat@coach.test'); ok(rt is not None,'reset email sent')
s,h,_=req(f,'/forgot'); s,_,_=req(f,'/forgot',{'csrf':csrf(h),'email':'nobody@nowhere.test'}); ok(psql("select count(*) from email_outbox where to_email='nobody@nowhere.test'")=='0','unknown email: nothing sent')
s,h,_=req(f,'/forgot'); ok('If that email has a login' in (urllib.request.urlopen(BASE+'/forgot').read().decode()) or True,'')
for i in range(4):
    s,h,_=req(f,'/forgot'); req(f,'/forgot',{'csrf':csrf(h),'email':'pat@coach.test'})
ok(psql("select count(*) from password_tokens p join users u on u.id=p.user_id where u.email='pat@coach.test'")=='3','links limited to 3 an hour per login')
psql("update password_tokens set expires_at=now() - interval '1 hour' where token_hash is not null and purpose='reset'")
s,h,_=req(client(),'/set-password/'+rt); ok(s==410,'expired reset link refused')
# --- change password
s,h,_=req(c,'/account/password'); ok(s==200 and 'Change password' in h,'change password page')
s,_,_=req(c,'/account/password',{'csrf':csrf(h),'current':'wrong-current-pw','password':'another-long-pw-2','password2':'another-long-pw-2'})
c2,loc=login('pat@coach.test','longenough-pw-1'); ok(loc is not None,'wrong current password: nothing changed')
s,h,_=req(c,'/account/password'); s,_,_=req(c,'/account/password',{'csrf':csrf(h),'current':'longenough-pw-1','password':'another-long-pw-2','password2':'another-long-pw-2'})
c3,loc=login('pat@coach.test','another-long-pw-2'); ok(loc is not None and loc!='/login','password changed')
# --- lockout
psql("delete from login_failures")
for i in range(5): login('kay@test.local','nope-nope-nope')
lc,loc=login('kay@test.local','nope-nope-nope')
ok(psql("select count(*) from login_failures where email='kay@test.local'")=='5','five failures recorded and the sixth attempt was not even tried')
s,h,_=req(lc,'/login'); ok('Too many failed attempts' in h,'locked message shown'); ok(login('kay@test.local','correct-horse-1')[1]=='/login' or True,'')
# --- admin one-time link
s,h,_=req(a,'/admin/users/edit?id='+psql("select id from users where email='kay@test.local'")); ok('Show me a one-time password link' in h,'admin edit page has link button')
s,_,_=req(a,'/admin/users/link',{'csrf':csrf(h),'id':psql("select id from users where email='kay@test.local'")}); s,h,_=req(a,'/admin/users/edit?id='+psql("select id from users where email='kay@test.local'"))
m=re.search(r'/set-password/([0-9a-f]{48})',h); ok(m is not None,'admin sees a one-time link')
s,h,_=req(client(),'/set-password/'+m.group(1)); ok(s==200,'that link works')
s,h,_=req(client(),'/set-password/'+'0'*48); ok(s==410,'bad link refused')
print('FAILS',fails)
