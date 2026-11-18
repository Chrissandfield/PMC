import subprocess
def psql(sql): return subprocess.run(['psql','-d','pmc_test','-tAc',sql],capture_output=True,text=True).stdout.strip()
fails=0
def ok(c,m):
    global fails; print(('PASS ' if c else 'FAIL ')+m); fails+=0 if c else 1
php='''require "src/bootstrap.php"; require_once "src/booking.php";
$co=q("SELECT id FROM coaches WHERE name='Trevor Bayley'")->fetch();
q("DELETE FROM email_outbox");
q("INSERT INTO bookings (reference, net_id, coach_id, booking_type, starts_at, ends_at, with_machine, status, customer_name, customer_email, customer_phone, is_junior, parent_confirmed, child_first_name, child_age, price_pence, commission_pence, stripe_account_id) VALUES ('PMC-CMAIL1',1,?,'coach','2030-01-07 10:00','2030-01-07 11:00',false,'confirmed','Pat','pat@x.com','07123456789',true,true,'Sam',10,3000,300,'acct_x')",[$co["id"]]);
queue_confirmation_emails(booking_by_ref("PMC-CMAIL1"));'''
subprocess.run(['php','-r',php],capture_output=True,text=True)
ok(psql("select count(*) from email_outbox where to_email='trevor@test.local' and subject like 'You have been booked%'")=='1','coach emailed when booked')
b=psql("select body from email_outbox where to_email='trevor@test.local'")
ok('UNDER-18' in b and 'Sam' in b and 'pat@x.com' not in b and '0712' not in b,'coach email has junior notice, no customer contact details')
ok(psql("select count(*) from email_outbox")=='3','customer, club and coach emailed')
# coach with no login but an email from the application form
psql("update coaches set email='jake.coach@test.local' where name='Jake Cheeseman'")
php2=php.replace("WHERE name='Trevor Bayley'","WHERE name='Jake Cheeseman'").replace("PMC-CMAIL1","PMC-CMAIL2").replace("2030-01-07 10:00","2030-01-08 10:00").replace("2030-01-07 11:00","2030-01-08 11:00")
subprocess.run(['php','-r',php2],capture_output=True,text=True)
ok(psql("select count(*) from email_outbox where to_email='jake.coach@test.local' and subject like 'You have been booked%'")=='1','coach with no login is emailed at the address from the application')
print('FAILS',fails)
