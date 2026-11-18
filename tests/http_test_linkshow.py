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
form={'csrf':csrf(h),'club':'Test Vale CC','town':'Testville','county':'Kent','postcode':'TN1 1AA','cname':'Kay Key','cemail':'kay@test.local','cphone':'01892 555123',
 'sname':'Sam Safe','semail':'sam@test.local','plink':'https://example.com/p','officer_coach':'1','cf1':'1','cf2':'1','cf3':'1','cf4':'1','cf5':'1',
 'g_type[]':['outdoor','outdoor','outdoor','indoor','outdoor','outdoor'],'g_member[]':['0','0','0','0','',''],'g_non[]':['10','10','10','15','',''],
 'c_name[]':['Sam Safe','Pat Coach','','','',''],'c_price[]':['30','25','','','',''],'c_qual[]':['ECB Core Coach (Level 3)','ECB Foundation Coach (Level 2)','','','',''],'c_other[]':['']*6,'c_email[]':['sam@coach.test','pat@coach.test','','','','']}
for d in range(7): form[f'open[outdoor][{d}]']='9'; form[f'close[outdoor][{d}]']='19'; form[f'open[indoor][{d}]']='10'; form[f'close[indoor][{d}]']='18'
bad=dict(form); bad['plink']=''
s,h,_=req(v,'/enrol',bad); ok(s==200 and 'attach the file or give the web link' in h,'no policy refused')
s,h,_=req(v,'/enrol',form); ok(s==200 and 'Thank you' in h,'application accepted: '+h[:0])
ok(psql("select status from clubs where slug='test-vale-cc'")=='pending','club saved pending')
ok(psql("select count(*) from nets n join clubs c on c.id=n.club_id where c.slug='test-vale-cc'")=='4','4 nets created')
ok(psql("select count(*) from nets n join clubs c on c.id=n.club_id where c.slug='test-vale-cc' and kind='indoor'")=='1','1 indoor')
ok(psql("select count(*) from verification_tokens")=='2','two tokens (officer + second, as officer is a coach)')
ok(psql("select count(*) from email_outbox where to_email='sam@test.local'")=='1','officer emailed')
s,h,_=req(client(),'/club/test-vale-cc'); ok(s==404,'pending club hidden from public')
s,h,_=req(client(),'/'); ok('Test Vale' not in h,'not on home page')

a=client(); s,h,_=req(a,'/login'); req(a,'/login',{'csrf':csrf(h),'email':'admin@test.local','password':'correct-horse-1'})
s,h,_=req(a,'/admin'); ok('Show me the officer link' in h and 'Show me the key contact link' in h,'admin sees both link buttons')
cid=psql("select id from clubs where slug='test-vale-cc'"); n0=psql("select count(*) from verification_tokens")
s,_,_=req(a,'/admin/applications/link',{'csrf':csrf(h),'club_id':cid,'who':'officer'})
s,h,_=req(a,'/admin'); m=re.search(r'/verify/([0-9a-f]{48})',h)
ok(m is not None and 'Sam Safe' in h,'officer link shown on screen')
ok(psql("select count(*) from verification_tokens")==str(int(n0)+1),'a new token was made')
ok(psql("select count(*) from email_outbox where body like '%"+(m.group(1) if m else 'zz')+"%'")=='0','link was not emailed')
o=client(); s,hh,_=req(o,'/verify/'+m.group(1)); ok(s==200 and 'Pat Coach' in hh,'the shown link works')
s,_,_=req(a,'/admin/applications/link',{'csrf':csrf(h),'club_id':cid,'who':'second'}); s,h,_=req(a,'/admin')
m2=re.search(r'/verify/([0-9a-f]{48})',h); ok(m2 is not None and 'Kay Key' in h,'key contact link shown')
s,h2,_=req(client(),'/admin/applications/link',{'club_id':cid}); ok(s in (302,303,403),'not available when logged out')
print('FAILS',fails)
