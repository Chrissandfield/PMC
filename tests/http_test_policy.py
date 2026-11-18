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
def multipart(fields,fname,fdata,fmime,pname=None,pdata=None,pmime=None):
    b=uuid.uuid4().hex; out=b''
    for k,vs in fields.items():
        for v in (vs if isinstance(vs,list) else [vs]): out+=f'--{b}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode()
    if fdata is not None: out+=f'--{b}\r\nContent-Disposition: form-data; name="crest"; filename="{fname}"\r\nContent-Type: {fmime}\r\n\r\n'.encode()+fdata+b'\r\n'
    if pdata is not None: out+=f'--{b}\r\nContent-Disposition: form-data; name="policy_file"; filename="{pname}"\r\nContent-Type: {pmime}\r\n\r\n'.encode()+pdata+b'\r\n'
    return out+f'--{b}--\r\n'.encode(), f'multipart/form-data; boundary={b}'
def form(club):
    f={'crest_ok':'1','club':club,'town':'Testville','county':'Kent','postcode':'TN1 1AA','cname':'Kay Key','cemail':'kay@test.local','cphone':'01892 555123','sname':'Sam Safe','semail':'sam@test.local',
     'plink':'https://example.com/p','officer_coach':'0','cf1':'1','cf2':'1','cf3':'1','cf4':'1','g_type[]':['outdoor'],'g_member[]':['0'],'g_non[]':['10'],
     'c_name[]':['Pat Coach'],'c_price[]':['25'],'c_qual[]':['ECB Core Coach (Level 3)'],'c_other[]':[''],'c_email[]':['pat@coach.test']}
    for d in range(7): f[f'open[outdoor][{d}]']='9'; f[f'close[outdoor][{d}]']='19'; f[f'open[indoor][{d}]']='10'; f[f'close[indoor][{d}]']='18'
    return f
def submit(club,fname,data,mime):
    c=client(); s,h,_=req(c,'/enrol'); f=form(club); f['csrf']=re.search(rb'name="csrf" value="([0-9a-f]+)"',h).group(1).decode()
    body,ct=multipart(f,fname,data,mime); return req(c,'/enrol',body,ct)

import sys
def submit2(club,policy,pname,pdata,pmime):
    c=client(); s,h,_=req(c,'/enrol'); f=form(club); f['csrf']=re.search(rb'name="csrf" value="([0-9a-f]+)"',h).group(1).decode()
    f['plink']=''
    body,ct=multipart(f,None,None,None,pname,pdata,pmime); return req(c,'/enrol',body,ct)
pdf=b'%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF'
s,h,_=submit2('Pol One CC','attached','policy.pdf',pdf,'application/pdf'); ok(b'Thank you' in h,'PDF policy accepted')
ok(psql("select count(*) from club_documents d join clubs c on c.id=d.club_id where c.slug='pol-one-cc' and d.kind='safeguarding'")=='1','policy stored')
ok(psql("select safeguarding_policy from clubs where slug='pol-one-cc'")=='Uploaded file: policy.pdf','club records the file name')
s,h,_=submit2('Pol Two CC','attached','x.pdf',b'<html>not a pdf</html>','application/pdf'); ok(b'PDF or Word' in h and psql("select count(*) from clubs where slug='pol-two-cc'")=='0','fake PDF refused, nothing saved')
s,h,_=submit2('Pol Three CC','attached','policy.exe',b'MZ\x90\x00','application/octet-stream'); ok(b'PDF or Word' in h,'exe refused')
f4=None
s,h,_=submit2('Pol Four CC','no',None,None,None); ok(b'attach the file or give the web link' in h and psql("select count(*) from clubs where slug='pol-four-cc'")=='0','"I will attach" with no file refused')
s,h,_=submit2('Pol Five CC','attached','big.pdf',pdf+b'0'*2200000,'application/pdf'); ok(b'too big' in h,'over 2 MB refused')
docx=b'PK\x03\x04'+b'0'*100
s,h,_=submit2('Pol Six CC','attached','policy.docx',docx,'application/octet-stream'); ok(b'Thank you' in h,'Word .docx accepted')
s,h,_=submit2('Pol Seven CC','link',None,None,None)
c=client(); s,h,_=req(c,'/enrol'); f=form('Pol Eight CC'); f['csrf']=re.search(rb'name="csrf" value="([0-9a-f]+)"',h).group(1).decode(); b_,ct=multipart(f,None,None,None); s,h,_=req(c,'/enrol',b_,ct)
ok(b'Thank you' in h and psql("select safeguarding_policy from clubs where slug='pol-eight-cc'")=='https://example.com/p','link only (no file) accepted')
ok(b'Thank you' in h and psql("select count(*) from club_documents")=='2','online link still works without a file')
# only admins can download
did=psql("select c.id from clubs c where slug='pol-one-cc'")
s,h,_=req(client(),'/admin/policy?club='+did); ok(s in (302,303,403),'public cannot download the policy (status %s)'%s)
a=client(); s,h,_=req(a,'/login'); req(a,'/login',urllib.parse.urlencode({'csrf':re.search(rb'name="csrf" value="([0-9a-f]+)"',h).group(1).decode(),'email':'admin@test.local','password':'correct-horse-1'}).encode(),'application/x-www-form-urlencoded')
s,h,hd=req(a,'/admin/policy?club='+did); ok(s==200 and h.startswith(b'%PDF') and 'attachment' in hd['Content-Disposition'],'admin can download it as an attachment')
s,h,_=req(a,'/admin'); ok(b'Download the file' in h,'admin list shows download link')
print('FAILS',fails)
