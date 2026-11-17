import urllib.request, urllib.parse, http.cookiejar, re, subprocess, datetime
BASE='http://127.0.0.1:8099'
def psql(sql): return subprocess.run(['psql','-d','pmc_test','-tAc',sql],capture_output=True,text=True).stdout.strip()
def get(path):
    try: return urllib.request.urlopen(BASE+path).read().decode()
    except urllib.error.HTTPError as e: return e.read().decode()
fails=0
def ok(c,m):
    global fails; print(('PASS ' if c else 'FAIL ')+m); fails+=0 if c else 1
psql("UPDATE clubs SET stripe_account_id='acct_x', stripe_charges_enabled=true, status='active' WHERE id=1")
tid=psql("select id from coaches where name='Trevor Bayley'"); aid=psql("select id from coaches where name='Adam Ramson'")
psql("delete from coach_availability")
d=datetime.date.today()+datetime.timedelta(days=3)
psql(f"INSERT INTO coach_availability (coach_id, starts_at) VALUES ({tid}, '{d.isoformat()} 10:00:00')")
mon=d-datetime.timedelta(days=d.weekday())
base=f'/club/penshurst-park?type=outdoor&week={mon}'
h=get(base); ok('coachfilter' in h and 'Show only hours with a coach available' in h,'filter shown')
ok('coachy' not in h and 'class="dim"' not in h,'no filter: nothing dimmed')
h=get(base+'&cf=1'); ok(h.count('coachy')==1,'only the hour with a coach is highlighted (%d)'%h.count('coachy'))
ok('class="dim"' in h,'other free hours dimmed')
ok(f'start={d.isoformat()}T10&kind=coach' in h,'link goes to coaching booking')
h=get(base+f'&fc={tid}'); ok(h.count('coachy')==1 and f'&coach={tid}' in h,'filtered to Trevor: his hour, coach preselected')
h=get(base+f'&fc={aid}'); ok('coachy' not in h,'filtered to Adam: nothing (no hours)')
h=get(f'/club/penshurst-park/reserve?type=outdoor&start={d.isoformat()}T10&kind=coach&coach={tid}')
ok(re.search(r'value="coach" checked',h) is not None and f'value="{tid}" selected' in h,'booking form opens on coaching with Trevor selected')
ok(f'cf=1' in get(base+'&cf=1') and 'week='+str(mon-datetime.timedelta(days=7)) in get(base+'&cf=1'),'filter kept in week navigation')
print('FAILS',fails)
