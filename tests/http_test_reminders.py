import urllib.request, re, subprocess, datetime
BASE='http://127.0.0.1:8099'
def psql(sql): return subprocess.run(['psql','-d','pmc_test','-qtAc',sql],capture_output=True,text=True).stdout.strip()
def get(path):
    try: r=urllib.request.urlopen(BASE+path); return r.status,r.read().decode(),r.headers
    except urllib.error.HTTPError as e: return e.code,e.read().decode(),e.headers
fails=0
def ok(c,m):
    global fails; print(('PASS ' if c else 'FAIL ')+m); fails+=0 if c else 1
tid=psql("select id from coaches where name='Trevor Bayley'"); nid=psql("select id from nets where club_id=1 order by id limit 1")
today=datetime.date.today()
def mk(ref,day,coach):
    psql(f"INSERT INTO bookings (reference, net_id, coach_id, booking_type, starts_at, ends_at, status, customer_name, customer_email, customer_phone, price_pence) VALUES ('{ref}',{nid},{coach},'{'coach' if coach!='NULL' else 'member'}','{day} 10:00:00','{day} 11:00:00','confirmed','Rem Inder','rem@x.test','0',2500)")
mk('PMC-REMT01',today+datetime.timedelta(days=1),tid)
mk('PMC-REMT02',today+datetime.timedelta(days=2),'NULL')
env=dict(__import__('os').environ)
out=subprocess.run(['php','bin/reminders.php'],capture_output=True,text=True,env=env).stdout
ok('booking reminders: 1' in out,'one reminder run for tomorrow only (%s)'%out.strip())
ok(psql("select count(*) from email_outbox where subject like 'Reminder: your session tomorrow (PMC-REMT01)%' and to_email='rem@x.test'")=='1','customer reminded')
ok(psql("select count(*) from email_outbox where subject like 'Reminder: coaching session tomorrow (PMC-REMT01)%'")=='1','coach reminded')
ok(psql("select count(*) from email_outbox where subject like '%PMC-REMT02%'")=='0','day-after-tomorrow booking not reminded')
out=subprocess.run(['php','bin/reminders.php'],capture_output=True,text=True,env=env).stdout
ok('booking reminders: 0' in out,'not reminded twice')
# calendar link
sys_path='/home/claude/pmc'
code=subprocess.run(['php','-r',"require 'src/bootstrap.php'; require_once 'src/booking.php'; echo calendar_link('PMC-REMT01');"],capture_output=True,text=True,env=env).stdout.strip()
ok('/booking/PMC-REMT01.ics?t=' in code,'calendar link built')
path=code.replace(env.get('PMC_APP_URL','http://127.0.0.1:8099'),'')
s,b,h=get(path); b=b.replace('\r\n ','')
ok(s==200 and 'BEGIN:VEVENT' in b and 'PMC-REMT01' in b and 'Rem Inder' not in b and 'text/calendar' in h.get('Content-Type',''),'calendar file served without the customer name')
s,b,h=get(path[:-3]+'xx'); ok(s==404,'wrong token refused')
s,b,h=get('/booking/PMC-REMT02.ics'); ok(s==404,'no token refused')
print('FAILS',fails)
