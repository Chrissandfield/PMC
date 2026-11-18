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
v=client()
s,h,_=req(v,'/enrol'); ok(s==200 and 'Enrol your club' in h,'form loads')
form={'csrf':csrf(h),'club':'Test Vale CC','town':'Testville','county':'Kent','postcode':'TN1 1AA','cname':'Kay Key','cemail':'kay@test.local','cphone':'01892 555123','weblink':'https://facebook.com/testvale',
 'sname':'Sam Safe','semail':'sam@test.local','plink':'https://example.com/p','officer_coach':'1','cf1':'1','cf2':'1','cf3':'1','cf4':'1',
 'g_type[]':['outdoor','outdoor','outdoor','indoor','outdoor','outdoor'],'g_member[]':['0','0','0','0','',''],'g_non[]':['10','10','10','15','',''],
 'c_name[]':['Sam Safe','Pat Coach','','','',''],'c_price[]':['30','25','','','',''],'c_qual[]':['ECB Core Coach (Level 3)','ECB Foundation Coach (Level 2)','','','',''],'c_other[]':['']*6,'c_email[]':['sam@coach.test','pat@coach.test','','','','']}
for d in range(7): form[f'open[outdoor][{d}]']='9'; form[f'close[outdoor][{d}]']='19'; form[f'open[indoor][{d}]']='10'; form[f'close[indoor][{d}]']='18'
bad=dict(form); bad['weblink']='javascript:alert(1)'
s,h,_=req(v,'/enrol',bad); ok(s==200 and 'must start with https' in h,'bad website link refused')
s,h,_=req(v,'/enrol',form); ok(s==200 and 'Thank you' in h,'application accepted')
ok(psql("select web_link from clubs where slug='test-vale-cc'")=='https://facebook.com/testvale','website link saved')
psql("INSERT INTO club_crests (club_id, mime, data_b64, permission_given_by) SELECT id, 'image/png', 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'Kay Key' FROM clubs WHERE slug='test-vale-cc'")
a=client(); s,h,_=req(a,'/login'); req(a,'/login',{'csrf':csrf(h),'email':'admin@test.local','password':'correct-horse-1'})
s,h,_=req(a,'/admin')
ok('facebook.com/testvale' in h and 'check this is the real club' in h,'admin sees website link')
ok('preview the club page' in h and 'Outdoor net 1: members' in h and 'Indoor hours: Mon 10-18' in h and 'Outdoor hours: Mon 09-19' in h,'admin sees nets, prices, hours and preview link')
ok('permission to display given by Kay Key' in h,'admin sees crest permission')
s,_,_=req(client(),'/crest/test-vale-cc'); ok(s==404,'crest not public while pending')
r=a.open(BASE+'/crest/test-vale-cc'); ok(r.status==200 and r.headers['Content-Type']=='image/png' and r.read()[:4]==b'\x89PNG','admin can preview pending crest')
cid=psql("select id from clubs where slug='test-vale-cc'")
s,_,_=req(a,'/admin/applications/decline',{'csrf':csrf(h),'club_id':cid,'action':'changes','reason':''}); ok(psql("select count(*) from email_outbox where subject like 'Your application to PlayMoreCricket%'")=='0','reason required')
s,h,_=req(a,'/admin')
req(a,'/admin/applications/decline',{'csrf':csrf(h),'club_id':cid,'action':'changes','reason':'Please send us your policy as a PDF'})
ok(psql("select count(*) from email_outbox where to_email='kay@test.local' and subject like '%needs a change%' and body like '%policy as a PDF%'")=='1','ask-for-changes emailed')
ok(psql("select declined_at is null from clubs where id="+cid)=='t','ask for changes keeps it waiting')
s,h,_=req(a,'/admin')
req(a,'/admin/applications/decline',{'csrf':csrf(h),'club_id':cid,'action':'decline','reason':'We could not confirm the club exists'})
ok(psql("select count(*) from email_outbox where to_email='kay@test.local' and subject like 'Your application to PlayMoreCricket: %' and body like '%could not confirm%'")=='1','decline emailed')
s,h,_=req(a,'/admin'); ok('Club applications waiting' not in h or 'Test Vale CC' not in h.split('All clubs')[0],'declined club leaves the waiting list')
req(a,'/admin/applications/approve',{'csrf':csrf(h),'club_id':cid,'allow_unconfirmed':'1'}); ok(psql("select status from clubs where id="+cid)=='pending','declined club cannot be approved')
# approval email with next steps on a fresh, approvable club
psql("update clubs set declined_at=null, safeguarding_confirmed_at=now(), safeguarding_confirmed_by='Sam' where id="+cid)
s,h,_=req(a,'/admin'); req(a,'/admin/applications/approve',{'csrf':csrf(h),'club_id':cid,'allow_unconfirmed':'1'})
ok(psql("select status from clubs where id="+cid)=='active','approved')
b=psql("select body from email_outbox where to_email='kay@test.local' and subject like '%is now live%'")
ok('Set up payments' in b and 'login details' in b,'approval email explains next steps')
print('FAILS',fails)
