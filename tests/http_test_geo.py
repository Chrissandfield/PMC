import urllib.request, urllib.parse, http.cookiejar, subprocess
BASE='http://127.0.0.1:8099'
def get(path):
    try: r=urllib.request.urlopen(BASE+path); return r.status,r.read().decode()
    except urllib.error.HTTPError as e: return e.code,e.read().decode()
def psql(sql): return subprocess.run(['psql','-d','pmc_test','-tAc',sql],capture_output=True,text=True).stdout.strip()
fails=0
def ok(c,m):
    global fails; print(('PASS ' if c else 'FAIL ')+m); fails+=0 if c else 1
psql("UPDATE clubs SET postcode='TN11 8DG', lat=NULL, lng=NULL WHERE id=1")
psql("INSERT INTO clubs (slug,name,town,county,postcode,status) VALUES ('far-cc','Far CC','Canterbury','Kent','CT1 2AA','active')")
s,h=get('/'); ok(s==200 and 'Find near me' in h,'postcode box on home page')
s,h=get('/?pc=TN1+1AA'); ok(s==200 and 'miles</em>' in h,'search shows distances')
ok(h.index('Penshurst Park Cricket Club</strong>')<h.index('Far CC</strong>'),'nearest club first')
ok(psql("select lat is not null from clubs where id=1") in ('t','1'),'club map point saved after first search')
s,h=get('/?pc=TN1+1AA&within=10'); ok('Far CC' not in h and 'Penshurst Park Cricket Club</strong>' in h,'within 10 miles keeps near club, drops far one')
s,h=get('/?pc=TN1+1AA&within=5'); ok('No clubs within that distance' in h,'friendly message when none in range')
s,h=get('/?pc=banana'); ok('does not look like a UK postcode' in h and 'Far CC' in h,'bad postcode: message, list still shown')
s,h=get('/?pc=ZZ9+9ZZ'); ok('could not look up that postcode' in h,'unknown postcode message')
ok(psql("select count(*) from audit_log where detail like '%TN1 1AA%'")=='0','postcode typed is not stored')
print('FAILS',fails)
