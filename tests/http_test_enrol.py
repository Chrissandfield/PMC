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
 'sname':'Sam Safe','semail':'sam@test.local','plink':'https://example.com/p','officer_coach':'1','cf1':'1','cf2':'1','cf3':'1','cf4':'1',
 'g_type[]':['outdoor','outdoor','outdoor','indoor','outdoor','outdoor'],'g_member[]':['0','0','0','0','',''],'g_non[]':['10','10','10','15','',''],
 'c_name[]':['Sam Safe','Pat Coach','','','',''],'c_price[]':['30','25','','','',''],'c_qual[]':['ECB Core Coach (Level 3)','ECB Foundation Coach (Level 2)','','','',''],'c_other[]':['']*6,'c_email[]':['sam@coach.test','pat@coach.test','','','','']}
for d in range(7): form[f'open[outdoor][{d}]']='9'; form[f'close[outdoor][{d}]']='19'; form[f'open[indoor][{d}]']='10'; form[f'close[indoor][{d}]']='18'
bad=dict(form); bad['plink']=''
s,h,_=req(v,'/enrol',bad); ok(s==200 and 'attach the file or give the web link' in h,'no policy refused')
b2=dict(form); b2['cphone']='12'
s,h,_=req(v,'/enrol',b2); ok(s==200 and 'phone number' in h,'key contact phone required')
b2=dict(form); b2['cemail']='not-an-email'
s,h,_=req(v,'/enrol',b2); ok(s==200 and 'email address' in h,'key contact email required')
b2=dict(form); b2['c_email[]']=['','pat@coach.test','','','','']
s,h,_=req(v,'/enrol',b2); ok(s==200 and 'email address is needed for each coach' in h,'coach email required')
s,h,_=req(v,'/enrol',form); ok(s==200 and 'Thank you' in h,'application accepted: '+h[:0])
ok(psql("select status from clubs where slug='test-vale-cc'")=='pending','club saved pending')
ok(psql("select count(*) from nets n join clubs c on c.id=n.club_id where c.slug='test-vale-cc'")=='4','4 nets created')
ok(psql("select count(*) from nets n join clubs c on c.id=n.club_id where c.slug='test-vale-cc' and kind='indoor'")=='1','1 indoor')
ok(psql("select o.open_hour||'-'||o.close_hour from opening_hours o join nets n on n.id=o.net_id join clubs c on c.id=n.club_id where c.slug='test-vale-cc' and n.kind='indoor' and o.weekday=2 limit 1")=='10-18','indoor hours saved separately')
ok(psql("select o.open_hour||'-'||o.close_hour from opening_hours o join nets n on n.id=o.net_id join clubs c on c.id=n.club_id where c.slug='test-vale-cc' and n.kind='outdoor' and o.weekday=2 limit 1")=='9-19','outdoor hours saved separately')
ok(psql("select email from coaches where name='Pat Coach'")=='pat@coach.test','coach email saved')
ok(psql("select phone||'|'||email from clubs where slug='test-vale-cc'")=='01892 555123|kay@test.local','club phone and email saved from key contact')
ok(psql("select count(*) from verification_tokens")=='2','two tokens (officer + second, as officer is a coach)')
ok(psql("select count(*) from email_outbox where to_email='sam@test.local'")=='1','officer emailed')
s,h,_=req(client(),'/club/test-vale-cc'); ok(s==404,'pending club hidden from public')
s,h,_=req(client(),'/'); ok('Test Vale' not in h,'not on home page')
# pull the raw tokens out of the emails
def link_for(addr):
    body=psql(f"select body from email_outbox where to_email='{addr}' and subject like 'Please confirm%' order by id desc limit 1")
    return re.search(r'/verify/([0-9a-f]{48})',body).group(1)
ot=link_for('sam@test.local'); st=link_for('kay@test.local')
s,h,_=req(client(),'/verify/'+'0'*48); ok(s==404,'bad token 404')
o=client(); s,h,_=req(o,'/verify/'+ot); ok(s==200 and 'Pat Coach' in h and 'Sam Safe</legend>' not in h,'officer page lists other coaches, not self')
pid=psql("select id from coaches where name='Pat Coach'")
ok('Web link: <a href="https://example.com/p"' in h,'officer is shown the policy link they are confirming')
s,b,_=req(o,'/verify/'+ot,{'csrf':csrf(h),'policy_ok':'1','m[%s]'%pid:'certificate','d[%s]'%pid:'2026-11-01'})
ok(psql("select dbs_checked::int from coaches where name='Pat Coach'")=='0','no Enhanced tick: coach not confirmed')
s,h,_=req(o,'/verify/'+ot)
s,b,_=req(o,'/verify/'+ot,{'csrf':csrf(h),'policy_ok':'1','m[%s]'%pid:'certificate','d[%s]'%pid:'2020-01-01','e[%s]'%pid:'1'})
ok(s==303 and psql("select dbs_checked::int from coaches where name='Pat Coach'")=='1','Pat confirmed')
ok(psql("select dbs_enhanced::int from coaches where name='Pat Coach'")=='1' and psql("select enhanced_barred::int from dbs_confirmations limit 1")=='1','Enhanced level recorded')
ok(psql("select count(*) from email_outbox where to_email='sam@test.local' and subject like 'Your safeguarding confirmation%' and body like '%Pat Coach%Enhanced check including%'")=='1' and psql("select count(*) from email_outbox where to_email='sam@test.local' and subject like 'Your safeguarding confirmation%' and body like '%written safeguarding policy%'")=='1','officer emailed a record of what they confirmed')
ok('over 3 years old' in psql("select body from email_outbox where subject like 'Your safeguarding confirmation%' and body like '%Pat Coach%' limit 1").replace('\n',' '),'record notes the old check date')
ok(psql("select dbs_method from coaches where name='Pat Coach'")=='certificate' and psql("select count(*) from dbs_confirmations")=='1','method + log row, no number stored')
ok(psql("select safeguarding_confirmed_by from clubs where slug='test-vale-cc'")=='Sam Safe','policy confirmation recorded')
sid=psql("select id from coaches where name='Sam Safe'")
s2=client(); s,h,_=req(s2,'/verify/'+st); ok(s==200 and 'Sam Safe' in h,'second link shows officer-coach')
s,b,_=req(s2,'/verify/'+st,{'csrf':csrf(h),f'm[{sid}]':'update_service',f'd[{sid}]':'2099-01-01',f'e[{sid}]':'1'})
ok(psql("select dbs_checked::int from coaches where name='Sam Safe'")=='0','future date refused')
req(s2,'/verify/'+st,{'csrf':csrf(req(s2,'/verify/'+st)[1]),f'm[{sid}]':'update_service',f'd[{sid}]':'2026-11-10',f'e[{sid}]':'1'})
ok(psql("select dbs_checked::int from coaches where name='Sam Safe'")=='1','second confirmer confirmed the officer-coach')
# expiry
psql("update verification_tokens set expires_at='2020-01-01' where id=1")
s,h,_=req(client(),'/verify/'+ot); ok(s==410,'expired link refused')
# admin
a=client(); s,h,_=req(a,'/login'); req(a,'/login',{'csrf':csrf(h),'email':'admin@test.local','password':'correct-horse-1'})
s,h,_=req(a,'/admin'); ok('Test Vale CC' in h and 'Approve and publish' in h,'admin sees application')
cid=psql("select id from clubs where slug='test-vale-cc'")
s,b,_=req(a,'/admin/applications/approve',{'csrf':csrf(h),'club_id':cid}); 
ok(psql("select status from clubs where id="+cid)=='active','approved')
s,h,_=req(client(),'/club/test-vale-cc'); ok(s==200 and 'DBS checked' in h,'club live with DBS ticks')
s,h,_=req(client(),'/'); ok('Test Vale' in h,'on home page')
print('FAILS',fails)
