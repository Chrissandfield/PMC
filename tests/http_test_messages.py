import urllib.request, urllib.parse, http.cookiejar, re, subprocess, datetime, os
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
# 120 opted-in players on the PlayMoreCricket list, 3 on the club list
for i in range(120): psql(f"insert into marketing_optins (email,name,scope,wording,consented_at) values ('p{i}@x.test','Player{i} Surname','pmc','w',now())")
psql("insert into marketing_optins (email,name,scope,wording,consented_at) values ('c1@x.test','Club One','club-1','w',now()),('c2@x.test','Club Two','club-1','w',now()),('c3@x.test','Club Three','club-1','w',now())")
a=login('admin@test.local'); j=login('jake@test.local'); t=login('trevor@test.local'); anon=client()
s,h,_=req(t,'/messages'); ok(s==403,'coach cannot send messages')
s,h,_=req(anon,'/messages'); ok(s in (302,303),'login needed')
s,h,_=req(a,'/messages?scope=pmc'); ok(s==200 and '120' in h and 'Cricket activities, events and offers' in h,'admin sees lists and counts')
s,h,_=req(j,'/messages'); ok(s==200 and 'Penshurst' in h and 'value="pmc"' not in h and 'value="events"' not in h,'club manager only sees their own club list')
c=csrf(h)
# manager cannot target another list by forcing scope
s,h2,_=req(j,'/messages?scope=pmc'); ok('value="pmc"' not in h2,'forced scope ignored for manager')
# test send goes only to the sender
s,h,_=req(a,'/messages?scope=pmc'); c=csrf(h)
req(a,'/messages',{'csrf':c,'scope':'pmc','action':'test','subject':'Winter nets','body':'Nets are open all winter.'})
ok(psql("select count(*) from email_outbox where subject='[TEST] Winter nets' and to_email='admin@test.local'")=='1','test copy sent to the sender')
ok(psql("select count(*) from email_outbox where subject='Winter nets'")=='0','test sent to nobody else')
# send without confirm refused
s,h,_=req(a,'/messages?scope=pmc'); c=csrf(h)
req(a,'/messages',{'csrf':c,'scope':'pmc','action':'send','subject':'Winter nets','body':'Nets are open all winter.'})
ok(psql("select count(*) from marketing_campaigns")=='0','send needs the confirm tick')
# real send: first batch of 50
req(a,'/messages',{'csrf':c,'scope':'pmc','action':'send','confirm':'1','subject':'Winter nets','body':'Nets are open all winter.'})
ok(psql("select count(*) from email_outbox where subject='Winter nets'")=='50','first batch of 50 sent')
who=psql("select to_email from email_outbox where subject='Winter nets' limit 1"); nm=psql(f"select name from marketing_optins where email='{who}' and scope='pmc'").split(' ')[0]
body=psql(f"select body from email_outbox where subject='Winter nets' and to_email='{who}'")
ok(body.startswith('Hello '+nm+',') and 'Nets are open all winter.' in body and '/unsubscribe?e='+who.replace('@','%40')+'&t=' in body and 'You agreed to hear news and updates' in body,'personal greeting, message, reason and unsubscribe link')
ok(body.count('@x.test')==0 or body.count('@')<=2,'no other recipients visible')
# someone unsubscribes before the next batch
gone=psql("select email from marketing_queue where status='queued' limit 1"); psql(f"update marketing_optins set withdrawn_at=now() where email='{gone}'")
cid=psql("select id from marketing_campaigns")
s,h,_=req(a,'/messages?scope=pmc'); c=csrf(h)
req(a,'/messages',{'csrf':c,'scope':'pmc','campaign_id':cid,'action':'batch'})
req(a,'/messages',{'csrf':c,'scope':'pmc','campaign_id':cid,'action':'batch'})
ok(psql("select count(*) from email_outbox where subject='Winter nets'")=='119','all but the unsubscribed person were sent')
ok(psql(f"select count(*) from email_outbox where subject='Winter nets' and to_email='{gone}'")=='0','withdrawn person not emailed')
ok(psql(f"select status from marketing_queue where email='{gone}'")=='skipped','recorded as skipped')
# daily job continues anything left
psql("insert into marketing_optins (email,name,scope,wording,consented_at) values ('late@x.test','Late','events','w',now())")
req(a,'/messages',{'csrf':c,'scope':'events','action':'send','confirm':'1','subject':'Event','body':'Hello all'})
ok(psql("select count(*) from email_outbox where to_email='late@x.test' and subject='Event'")=='1','events list works')
# club manager sends to own list; cannot batch another list's campaign
s,h,_=req(j,'/messages'); cj=csrf(h)
req(j,'/messages',{'csrf':cj,'scope':'club-1','action':'send','confirm':'1','subject':'Club night','body':'Friday night.'})
ok(psql("select count(*) from email_outbox where subject='Club night'")=='3','club manager sent to their 3 players')
ok('Penshurst Park Cricket Club' in psql("select body from email_outbox where subject='Club night' and to_email='c1@x.test'"),'club message names the club')
req(j,'/messages',{'csrf':cj,'scope':'pmc','action':'send','confirm':'1','subject':'Sneaky','body':'x'})
ok(psql("select count(*) from email_outbox where subject='Sneaky'")=='0' and psql("select count(*) from marketing_campaigns where subject='Sneaky'")=='0','manager cannot send to the PlayMoreCricket list')
req(j,'/messages',{'csrf':cj,'scope':'club-1','campaign_id':cid,'action':'batch'})
ok(psql("select count(*) from email_outbox where subject='Winter nets'")=='119','manager cannot send another list\'s campaign')
# daily job sweeps remaining
psql("insert into marketing_optins (email,name,scope,wording,consented_at) values ('z1@x.test','Z','events','w',now())")
cid2=psql("select max(id) from marketing_campaigns where scope='events'")
psql(f"insert into marketing_queue (campaign_id,email,name) values ({cid2},'z1@x.test','Z')")
out=subprocess.run(['php','bin/reminders.php'],capture_output=True,text=True,env=dict(os.environ)).stdout
ok('campaign emails sent: 1' in out and psql("select count(*) from email_outbox where to_email='z1@x.test'")=='1','daily job sends what is still waiting')
s,h,_=req(a,'/messages?scope=pmc'); ok('Recent messages' in h and 'Winter nets' in h,'recent messages shown')
print('FAILS',fails)
