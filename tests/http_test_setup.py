import urllib.request, urllib.parse, http.cookiejar, re, subprocess
BASE='http://127.0.0.1:8099'
def psql(sql): return subprocess.run(['psql','-d','pmc_test','-qtAc',sql],capture_output=True,text=True).stdout.strip()
class NR(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*a,**k): return None
def client(): return urllib.request.build_opener(NR, urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def req(c,path,data=None):
    body=urllib.parse.urlencode(data).encode() if data is not None else None
    try: r=c.open(BASE+path,body); return r.status,r.read().decode()
    except urllib.error.HTTPError as e: return e.code,e.read().decode()
fails=0
def ok(c,m):
    global fails; print(('PASS ' if c else 'FAIL ')+m); fails+=0 if c else 1
c=client(); s,h=req(c,'/setup'); ok(s==404,'setup closed while users exist')
psql('TRUNCATE users CASCADE')
s,h=req(c,'/setup'); ok(s==200 and 'Set up PlayMoreCricket' in h,'setup open while there are no users')
tok=re.search(r'name="csrf" value="([0-9a-f]+)"',h).group(1)
req(c,'/setup',{'csrf':tok,'name':'First Admin','email':'first@test.local','password':'correct-horse-1'})
ok(psql("select role from users where email='first@test.local'")=='admin','first admin created')
s,h=req(c,'/setup'); ok(s==404,'setup closes after first admin')
print('FAILS',fails)
