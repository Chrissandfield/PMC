import urllib.request, urllib.parse, http.cookiejar, re, datetime, subprocess, sys, hmac, hashlib, json, time
BASE='http://127.0.0.1:8099'; WHSEC='whsec_test_secret'
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*a,**k): return None
def client(): return urllib.request.build_opener(NoRedirect, urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def req(c,path,data=None,headers=None):
    body=urllib.parse.urlencode(data).encode() if isinstance(data,dict) else data
    r=urllib.request.Request(BASE+path,body,headers or {})
    try:
        x=c.open(r); return x.status,x.read().decode(),x.headers
    except urllib.error.HTTPError as e: return e.code,e.read().decode(),e.headers
def csrf(h): return re.search(r'name="csrf" value="([0-9a-f]+)"',h).group(1)
def login(email):
    c=client(); _,h,_=req(c,'/login'); req(c,'/login',{'csrf':csrf(h),'email':email,'password':'correct-horse-1'}); return c
def flash(c,path):
    _,h,_=req(c,path); m=re.search(r'class="notice[^"]*" role="status">([^<]*)<',h); return (m.group(1) if m else '').replace('&quot;','"').replace('&#039;',"'")
def psql(sql): return subprocess.run(['psql','-d','pmc_test','-tAc',sql],capture_output=True,text=True).stdout.strip()
fails=0
def ok(c,m):
    global fails; print(('PASS ' if c else 'FAIL ')+m); fails+=0 if c else 1
def webhook(ev, secret=WHSEC, tamper=False):
    payload=json.dumps(ev); t=int(time.time()); sig=hmac.new(secret.encode(),f'{t}.{payload}'.encode(),hashlib.sha256).hexdigest()
    if tamper: payload=payload.replace('paid','pa1d')
    s,b,_=req(client(),'/stripe/webhook',payload.encode(),{'Stripe-Signature':f't={t},v1={sig}','Content-Type':'application/json'}); return s,b
eid=[0]
def completed(ref, amount, status='paid'):
    eid[0]+=1; return {'id':f'evt_t{eid[0]}_{ref}','type':'checkout.session.completed','data':{'object':{'client_reference_id':ref,'payment_status':status,'payment_intent':'pi_'+ref,'amount_total':amount}}}

day=datetime.date.today()+datetime.timedelta(days=9); d=day.isoformat(); di=day.weekday()
def start(h): return f'{d}T{h:02d}'
def reserve(c,h,net=1,**f):
    data={'net':net,'start':start(h),'kind':'net','customer_name':'Pat Visitor','customer_email':'pat@example.com','customer_phone':'07123 456789','accept_terms':'1'}
    _,hh,_=req(c,f'/club/penshurst-park/reserve?net={net}&start={start(h)}') if False else (0,'',0)
    data.update(f); data={k:v for k,v in data.items() if v is not None}
    # a visitor needs a session cookie + csrf: get it from the login page
    _,page,_=req(c,'/login'); data['csrf']=csrf(page)
    s,b,hd=req(c,'/club/penshurst-park/reserve',data); return s,hd.get('Location') or ''
def booking_row(ref=None,where=''):
    return psql(f"select reference||'|'||status||'|'||price_pence||'|'||commission_pence from bookings {where} order by id desc limit 1")

visitor=client(); jake=login('jake@test.local'); tre=login('trevor@test.local')
s,h,_=req(visitor,f'/club/penshurst-park?week={d}')
ok('/reserve' not in h and 'coming soon' in h,'before payments are connected: no Book links for visitors, "coming soon" shown')
s,loc=reserve(visitor,10); ok(s==303 and '/club/penshurst-park' == loc,'reserve is refused while payments are off'); print('   ',flash(visitor,'/club/penshurst-park'))
ok(psql('select count(*) from bookings')=='0','...and nothing was created')

_,h,_=req(jake,'/club/penshurst-park'); ok('Not set up yet' in h and 'Set up payments' in h,'club contact sees "Set up payments"')
s,_,hd=req(jake,'/stripe/connect',{'csrf':csrf(h),'club_id':1}); ok(s==303 and '/stripe/return' in hd['Location'],'connect sends the contact to Stripe sign-up')
ok(psql('select stripe_account_id from clubs where id=1').startswith('acct_fake_'),'club account id stored')
s,_,hd=req(jake,'/stripe/return?club=penshurst-park'); ok('Payments are switched on' in flash(jake,'/club/penshurst-park') or psql('select stripe_charges_enabled from clubs where id=1') in ('t','1'),'return from Stripe switches payments on')
ok(psql('select stripe_charges_enabled from clubs where id=1') in ('t','1'),'club marked charges-enabled')
s,_,_=req(tre,'/stripe/connect',{'csrf':csrf(req(tre,'/login')[1]),'club_id':1}); ok(s==403,'a coach cannot connect payments')

# Trevor opens availability for the day
_,h,_=req(tre,f'/coach/availability?week={d}')
req(tre,'/coach/availability',{'csrf':csrf(h),'week':d,'slot[]':[f'{di}-16',f'{di}-17',f'{di}-18'],'repeat':'0'}) if False else None
body=urllib.parse.urlencode([('csrf',csrf(h)),('week',d),('repeat','0')]+[('slot[]',f'{di}-{x}') for x in (16,17,18)]).encode(); req(tre,'/coach/availability',body)
s,h,_=req(visitor,f'/club/penshurst-park?week={d}')
ok('/reserve?net=1&amp;start='+start(10) in h or '/reserve?net=1&start='+start(10) in h,'visitors now see Book links on free hours')
ok('class="daylist"' in h and 'daytabs' in h,'phone-friendly day list is on the page')
s,h,_=req(visitor,f'/club/penshurst-park/reserve?net=1&start={start(10)}'); ok(s==200 and 'Continue to payment' in h and 'Net hire only' in h,'reserve page loads')
ok('Coaching session' not in h,'...and offers no coaching when no coach is free at 10:00')
s,h,_=req(visitor,f'/club/penshurst-park/reserve?net=1&start={start(16)}'); ok('Coaching session' in h and 'Trevor Bailey' in h and 'Adam Ramson' not in h,'at 16:00 only Trevor (the available coach) is offered')

# validation: nothing should be created
bad=[('no terms',dict(accept_terms=None)),('bad email',dict(customer_email='nope')),('short phone',dict(customer_phone='12')),('no name',dict(customer_name='')),
     ('junior without parent tick',dict(junior='1',child_first_name='Sam',child_age='10')),('machine with net only',dict(machine='1')),('coach not available',dict(kind='coach',coach_id='3')),('closed hour',None)]
for name,f in bad:
    if f is None: s,loc=reserve(visitor,22)
    else: s,loc=reserve(visitor,10 if 'coach' not in name else 16,**f)
    ok('start=' in loc,'refused: '+name)
ok(psql('select count(*) from bookings')=='0','no booking rows created by invalid requests')

# happy path: net hire
s,loc=reserve(visitor,10); ok(s==303 and loc.startswith('https://checkout.stripe.test/pay/PMC-'),'valid reservation goes to the Stripe payment page')
r=booking_row(); ref=r.split('|')[0]; ok(r.endswith('|pending_payment|1000|100'),'booking held pending payment: £10, commission 100p'); print('   ',r)
ok(psql(f"select stripe_account_id||'|'||(expires_at is not null) from bookings where reference='{ref}'").startswith('acct_fake_'),'hold expiry and club account recorded')
s,loc=reserve(client(),10); ok('start=' in loc,'a second visitor cannot take the held hour'); print('   ',flash(visitor,'/club/penshurst-park') or '')
s,h,_=req(visitor,f'/club/penshurst-park?week={d}'); ok('BOOKED' in h,'held hour shows BOOKED to the public')

# webhook
s,b=webhook(completed(ref,1000),tamper=True); ok(s==400,'webhook with a tampered body is refused')
s,b=webhook(completed(ref,1000),secret='whsec_wrong'); ok(s==400,'webhook signed with the wrong secret is refused')
ok(booking_row().split('|')[1]=='pending_payment','...booking still pending')
s,b=webhook(completed(ref,999)); ok(b=='amount mismatch' and booking_row().split('|')[1]=='pending_payment','wrong amount is not accepted')
ev=completed(ref,1000); s,b=webhook(ev); ok(s==200 and b=='confirmed' and booking_row().split('|')[1]=='confirmed','paid event confirms the booking')
ok(psql(f"select count(*) from email_outbox where booking_id=(select id from bookings where reference='{ref}')")=='2','two emails queued (customer and club)')
ok(psql("select count(*) from email_outbox where sent_at is not null")=='2','...and marked sent')
s,b=webhook(ev); ok(b=='duplicate' and psql('select count(*) from email_outbox')=='2','the same event delivered twice does nothing the second time')

# booking page
tok=re.search(r'booking/'+ref+r'\?t=([0-9a-f]+)',psql("select body from email_outbox where to_email='pat@example.com' limit 1")).group(1)
s,_,_=req(visitor,f'/booking/{ref}?t=badtoken'); ok(s==404,'booking page refuses a wrong token')
s,_,_=req(visitor,f'/booking/{ref}'); ok(s==404,'...and a missing token')
s,h,_=req(visitor,f'/booking/{ref}?t={tok}'); ok(s==200 and 'Confirmed and paid' in h and 'Cancel and refund' in h,'booking page shows confirmed with a Cancel button')
ok('Sam' not in h,'no other customers\' details on it')
tkn=csrf(h)
s,_,_=req(visitor,f'/booking/{ref}/cancel?t={tok}',{'csrf':'wrong','t':tok}); ok(s==400,'cancel without a valid form token is refused')
s,_,hd=req(visitor,f'/booking/{ref}/cancel',{'csrf':tkn,'t':tok}); ok(booking_row().split('|')[1]=='refunded' and psql(f"select refund_pence from bookings where reference='{ref}'")=='1000','cancel more than 24h ahead: refunded in full')
ok(psql("select count(*) from email_outbox where subject like 'Booking cancelled%'")=='2','cancellation emails sent to customer and club')
s,h,_=req(visitor,f'/club/penshurst-park?week={d}'); ok('/reserve?net=1&amp;start='+start(10) in h or '/reserve?net=1&start='+start(10) in h,'the hour is free to book again')

# coach session with machine, commission-free period
psql("update clubs set commission_free_until=(current_date + 30) where id=1")
s,loc=reserve(visitor,16,kind='coach',coach_id='2',machine='1'); ok(s==303 and loc.startswith('https://checkout'),'coach + machine reservation accepted')
r=booking_row(); ref2=r.split('|')[0]; ok(r.endswith('|pending_payment|4500|0'),'£33 coach + £12 machine = £45, no commission during the free period'); print('   ',r)
s,loc=reserve(client(),16,kind='coach',coach_id='2'); ok('start=' in loc,'the same coach cannot be reserved twice for that hour')
s,b=webhook(completed(ref2,4500)); ok(b=='confirmed','coach booking confirmed')
# under 24h: move the booking to 2 hours from now, try to cancel
psql(f"update bookings set starts_at=now()+interval '2 hours', ends_at=now()+interval '3 hours' where reference='{ref2}'")
tok2=re.search(r'booking/'+ref2+r'\?t=([0-9a-f]+)',psql(f"select body from email_outbox where body like '%{ref2}%' and to_email='pat@example.com' limit 1")).group(1)
s,h,_=req(visitor,f'/booking/{ref2}?t={tok2}'); ok('full fee applies' in h and 'Cancel and refund' not in h,'under 24h: no cancel button, full fee applies')
s,_,_=req(visitor,f'/booking/{ref2}/cancel',{'csrf':csrf(req(visitor,'/login')[1]),'t':tok2}); ok(booking_row().split('|')[1]=='confirmed','...and a forced cancel request is refused')
psql(f"update bookings set starts_at='{d} 16:00:00+00', ends_at='{d} 17:00:00+00' where reference='{ref2}'")

# staff release refunds an online payment
_,h,_=req(jake,f'/club/penshurst-park?week={d}'); bid=psql(f"select id from bookings where reference='{ref2}'")
s,_,_=req(jake,'/club/penshurst-park/release',{'csrf':csrf(h),'booking_id':bid,'net':1,'week':d}); ok(booking_row().split('|')[1]=='refunded','club releasing a paid booking refunds the customer')

# paid after the hold expired, hour taken by someone else: auto refund
s,loc=reserve(visitor,11); refA=booking_row().split('|')[0]
psql(f"update bookings set status='expired' where reference='{refA}'")
s,loc=reserve(client(),11,customer_email='second@example.com'); refB=booking_row().split('|')[0]; webhook(completed(refB,1000))
s,b=webhook(completed(refA,1000)); ok(b=='refunded (slot taken)' and psql(f"select status from bookings where reference='{refA}'")=='refunded' and psql(f"select status from bookings where reference='{refB}'")=='confirmed','late payment for a taken hour is refunded automatically; the other booking is untouched')

# junior booking
s,loc=reserve(visitor,12,junior='1',child_first_name='Alex',child_age='11',parent_confirmed='1'); refJ=booking_row().split('|')[0]; webhook(completed(refJ,1000))
ok(psql(f"select is_junior::text||'|'||child_age from bookings where reference='{refJ}'") in ('true|11','1|11','t|11'),'junior booking stored with child details')
ok('UNDER-18' in psql(f"select body from email_outbox where to_email='jake_cheeseman@yahoo.com' and body like '%{refJ}%' limit 1"),'club email flags the under-18 booking')

# expiry
s,loc=reserve(visitor,13); refE=booking_row().split('|')[0]
psql(f"update bookings set expires_at=now()-interval '1 minute' where reference='{refE}'")
req(visitor,f'/club/penshurst-park?week={d}'); ok(psql(f"select status from bookings where reference='{refE}'")=='expired','unpaid hold expires and frees the hour')
ev={'id':'evt_exp1','type':'checkout.session.expired','data':{'object':{'client_reference_id':refE}}}; s,b=webhook(ev); ok(b=='expired','checkout.session.expired is handled')
ev={'id':'evt_acct1','type':'account.updated','data':{'object':{'id':psql('select stripe_account_id from clubs where id=1'),'charges_enabled':False}}}; webhook(ev)
ok(psql('select stripe_charges_enabled from clubs where id=1') in ('f','0'),'account.updated can switch payments off')
print('\nFAILURES:',fails); sys.exit(1 if fails else 0)
