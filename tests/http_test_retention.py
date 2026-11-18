import subprocess, datetime, os
def psql(sql): return subprocess.run(['psql','-d','pmc_test','-qtAc',sql],capture_output=True,text=True).stdout.strip()
fails=0
def ok(c,m):
    global fails; print(('PASS ' if c else 'FAIL ')+m); fails+=0 if c else 1
tid=psql("select id from coaches where name='Trevor Bayley'"); nid=psql("select id from nets where club_id=1 order by id limit 1")
today=datetime.date.today()
old=today-datetime.timedelta(days=365*4); mid=today-datetime.timedelta(days=365*2)
n=[0]
def mk(ref,day,email,hour=10,junior=False,paid=2500):
    cols="reference, net_id, booking_type, starts_at, ends_at, status, customer_name, customer_email, customer_phone, price_pence, note"
    vals=f"'{ref}',{nid},'nonmember','{day} {hour}:00:00','{day} {hour+1}:00:00','confirmed','Real Name','{email}','07123456789',{paid},'my note'"
    if junior: cols+=", is_junior, parent_confirmed, child_first_name, child_age"; vals+=", true, true, 'Sam', 9"
    psql(f"INSERT INTO bookings ({cols}) VALUES ({vals})")
mk('PMC-OLD001',old,'gone@x.test')
mk('PMC-OLD002',old,'gone@x.test',hour=12)
mk('PMC-REP001',old,'repeat@x.test',hour=14)
mk('PMC-REP002',mid,'repeat@x.test')            # 2 years ago: inside the 3 years, so the old one must stay too
mk('PMC-JUN001',old,'parent@x.test',hour=16,junior=True)
mk('PMC-NEW001',today+datetime.timedelta(days=5),'newer@x.test')
psql(f"insert into marketing_optins (email,name,scope,wording,consented_at) values ('gone@x.test','Real Name','pmc','w','{old} 10:00:00'),('repeat@x.test','Rep','pmc','w','{old} 10:00:00'),('fresh@x.test','Fresh','pmc','w',now())")
psql(f"insert into marketing_optins (email,name,scope,wording,consented_at,withdrawn_at) values ('wd@x.test','Wd','pmc','w','{old} 10:00:00','{old} 11:00:00')")
psql(f"insert into email_outbox (to_email,subject,body,created_at) values ('gone@x.test','old','hello Real Name','{old} 10:00:00'),('gone@x.test','new','hello',now())")
env=dict(os.environ)
out=subprocess.run(['php','bin/reminders.php'],capture_output=True,text=True,env=env).stdout
ok('old data anonymised: 3' in out,'three old bookings anonymised (%s)'%out.strip()[-60:])
row=lambda ref: psql(f"select customer_name||'|'||customer_email||'|'||customer_phone||'|'||coalesce(note,'-')||'|'||coalesce(child_first_name,'-')||'|'||price_pence from bookings where reference='{ref}'")
ok(row('PMC-OLD001')=='Deleted|deleted@invalid|deleted|-|-|2500','old booking anonymised, amount kept (%s)'%row('PMC-OLD001'))
ok(row('PMC-OLD002').startswith('Deleted|'),'second old booking by same person anonymised')
ok(row('PMC-REP001').startswith('Real Name|repeat@x.test'),'old booking kept while the customer booked again within 3 years')
ok(row('PMC-REP002').startswith('Real Name|'),'recent booking untouched')
ok(row('PMC-JUN001')=='Deleted|deleted@invalid|deleted|-|Deleted|2500','junior record anonymised, child name removed, amount kept (%s)'%row('PMC-JUN001'))
ok(psql("select child_age from bookings where reference='PMC-JUN001'")=='9','child age (not identifying) kept')
ok(row('PMC-NEW001').startswith('Real Name|newer@x.test'),'future booking untouched')
ok(psql("select count(*) from marketing_optins where email='gone@x.test'")=='0','stale marketing record removed')
ok(psql("select count(*) from marketing_optins where email='repeat@x.test'")=='1','marketing record kept when customer still booking')
ok(psql("select count(*) from marketing_optins where email='fresh@x.test'")=='1','fresh consent kept')
ok(psql("select count(*) from marketing_optins where email='wd@x.test'")=='0','old withdrawn record removed')
ok(psql("select count(*) from email_outbox where subject='old'")=='0' and psql("select count(*) from email_outbox where subject='new'")=='1','old emails removed, new kept')
out=subprocess.run(['php','bin/reminders.php'],capture_output=True,text=True,env=env).stdout
ok('old data anonymised: 0' in out,'second run changes nothing')
ok(psql("select count(*) from audit_log where action='retention_run'")=='1','run recorded in the audit log (counts only)')
print('FAILS',fails)
