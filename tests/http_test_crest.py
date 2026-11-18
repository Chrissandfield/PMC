import urllib.request, urllib.parse, http.cookiejar, re, subprocess, zlib, struct, uuid
BASE='http://127.0.0.1:8099'
class NR(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*a,**k): return None
def client(): return urllib.request.build_opener(NR, urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def req(c,path,data=None,ct=None):
    if isinstance(data,dict): data=urllib.parse.urlencode(data,doseq=True).encode()
    r_=urllib.request.Request(BASE+path,data=data,headers={'Content-Type':ct} if ct else {})
    try: r=c.open(r_); return r.status,r.read(),r.headers
    except urllib.error.HTTPError as e: return e.code,e.read(),e.headers
def psql(sql): return subprocess.run(['psql','-d','pmc_test','-tAc',sql],capture_output=True,text=True).stdout.strip()
fails=0
def ok(c,m):
    global fails; print(('PASS ' if c else 'FAIL ')+m); fails+=0 if c else 1
def png(w=20,h=20):
    raw=b''.join(b'\x00'+b'\x20\x80\x40'*w for _ in range(h))
    def ch(t,d): c=struct.pack('>I',len(d))+t+d; return c+struct.pack('>I',zlib.crc32(t+d)&0xffffffff)
    return b'\x89PNG\r\n\x1a\n'+ch(b'IHDR',struct.pack('>IIBBBBB',w,h,8,2,0,0,0))+ch(b'IDAT',zlib.compress(raw))+ch(b'IEND',b'')
def multipart(fields,fname,fdata,fmime):
    b=uuid.uuid4().hex; out=b''
    for k,vs in fields.items():
        for v in (vs if isinstance(vs,list) else [vs]): out+=f'--{b}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode()
    if fdata is not None: out+=f'--{b}\r\nContent-Disposition: form-data; name="crest"; filename="{fname}"\r\nContent-Type: {fmime}\r\n\r\n'.encode()+fdata+b'\r\n'
    return out+f'--{b}--\r\n'.encode(), f'multipart/form-data; boundary={b}'
def form(club):
    f={'crest_ok':'1','club':club,'town':'Testville','county':'Kent','postcode':'TN1 1AA','cname':'Kay Key','cemail':'kay@test.local','cphone':'01892 555123','sname':'Sam Safe','semail':'sam@test.local',
     'plink':'https://example.com/p','officer_coach':'0','cf1':'1','cf2':'1','cf3':'1','cf4':'1','cf5':'1','g_type[]':['outdoor','outdoor'],'g_member[]':['0','0'],'g_non[]':['10','10'],
     'c_name[]':['Pat Coach'],'c_price[]':['25'],'c_qual[]':['ECB Core Coach (Level 3)'],'c_other[]':[''],'c_email[]':['pat@coach.test']}
    for d in range(7): f[f'open[outdoor][{d}]']='9'; f[f'close[outdoor][{d}]']='19'; f[f'open[indoor][{d}]']='10'; f[f'close[indoor][{d}]']='18'
    return f
def submit(club,fname,data,mime):
    c=client(); s,h,_=req(c,'/enrol'); f=form(club); f['csrf']=re.search(rb'name="csrf" value="([0-9a-f]+)"',h).group(1).decode()
    body,ct=multipart(f,fname,data,mime); return req(c,'/enrol',body,ct)
s,h,_=submit('Crest One CC','c.png',png(),'image/png'); ok(s==200 and b'Thank you' in h,'application with crest accepted')
ok(psql("select count(*) from club_crests k join clubs c on c.id=k.club_id where c.slug='crest-one-cc'")=='1','crest stored')
s,h,_=req(client(),'/crest/crest-one-cc'); ok(s==404,'crest hidden while club pending')
psql("update clubs set status='active' where slug='crest-one-cc'")
s,h,hd=req(client(),'/crest/crest-one-cc'); ok(s==200 and hd['Content-Type']=='image/png' and h[:4]==b'\x89PNG','crest served once live')
s,h,_=req(client(),'/'); ok(b'/crest/crest-one-cc' in h,'crest on home list')
s,h,_=req(client(),'/club/crest-one-cc'); ok(b'/crest/crest-one-cc' in h,'crest on club page')
s,h,_=submit('Crest Two CC','x.png',b'<html>not an image</html>','image/png'); ok(b'PNG, JPG or WebP' in h and psql("select count(*) from clubs where slug='crest-two-cc'")=='0','fake image refused, no club saved')
s,h,_=submit('Crest Three CC','big.png',png()+b'0'*1100000,'image/png'); ok(b'too big' in h and psql("select count(*) from clubs where slug='crest-three-cc'")=='0','oversize refused')
c=client(); s,h,_=req(c,'/enrol'); f=form('Crest Five CC'); del f['crest_ok']; f['csrf']=re.search(rb'name="csrf" value="([0-9a-f]+)"',h).group(1).decode()
b,ct=multipart(f,'c.png',png(),'image/png'); s,h,_=req(c,'/enrol',b,ct)
ok(b'gives us permission' in h and psql("select count(*) from clubs where slug='crest-five-cc'")=='0','crest without permission tick refused')
ok(psql("select permission_given_by from club_crests k join clubs c on c.id=k.club_id where c.slug='crest-one-cc'")=='Kay Key','permission recorded against the key contact')
s,h,_=submit('Crest Four CC',None,None,None); ok(b'Thank you' in h and psql("select count(*) from club_crests")=='1','no crest is fine')
print('FAILS',fails)
