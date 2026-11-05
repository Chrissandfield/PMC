<?php
require __DIR__ . '/../src/stripe.php';
$GLOBALS['cfg'] = ['app_url' => 'https://example.test'];
$fail = 0;
function ok(bool $c, string $m): void { global $fail; echo ($c ? 'PASS ' : 'FAIL ') . $m . "\n"; if (!$c) { $fail++; } }
$d = fn($s) => new DateTimeImmutable($s, new DateTimeZone('Europe/London'));

ok(commission_pence(1000, 10, null, $d('2026-11-03')) === 100, '10% of £10 = 100p');
ok(commission_pence(3300, 10, null, $d('2026-11-03')) === 330, '10% of £33 = 330p');
ok(commission_pence(1205, 10, null, $d('2026-11-03')) === 121, 'rounds 120.5 up to 121');
ok(commission_pence(1000, 0, null, $d('2026-11-03')) === 0, '0% = 0');
ok(commission_pence(1000, 10, '2027-11-03', $d('2026-11-03')) === 0, 'free until a future date = 0');
ok(commission_pence(1000, 10, '2027-11-03', $d('2027-11-03')) === 0, 'free on the last day');
ok(commission_pence(1000, 10, '2027-11-03', $d('2027-11-04')) === 100, 'charged the day after');
ok(commission_pence(1000, 250, null, $d('2026-11-03')) === 1000, 'percentage is capped at 100');

$enc = stripe_encode(['a' => ['b' => 1, 'c' => [['d' => 'x']]], 'f' => true, 'n' => null]);
ok($enc === ['a[b]' => '1', 'a[c][0][d]' => 'x', 'f' => 'true'], 'form encoding nests and drops nulls');

$secret = 'whsec_test'; $payload = '{"id":"evt_1","type":"checkout.session.completed"}'; $t = 1000000;
$sig = hash_hmac('sha256', $t . '.' . $payload, $secret);
ok(stripe_verify_webhook($payload, "t=$t,v1=$sig", $secret, 300, $t + 10)['id'] === 'evt_1', 'valid signature accepted');
ok(stripe_verify_webhook($payload, "t=$t,v1=" . str_repeat('0', 64), $secret, 300, $t + 10) === null, 'wrong signature refused');
ok(stripe_verify_webhook($payload . ' ', "t=$t,v1=$sig", $secret, 300, $t + 10) === null, 'tampered body refused');
ok(stripe_verify_webhook($payload, "t=$t,v1=$sig", $secret, 300, $t + 9999) === null, 'old signature refused');
ok(stripe_verify_webhook($payload, '', $secret) === null, 'missing header refused');

$now = new DateTimeImmutable('2026-11-10 10:00:00', new DateTimeZone('UTC'));
ok(refund_allowed(new DateTimeImmutable('2026-11-11 10:00:01', new DateTimeZone('UTC')), $now), '24h+1s ahead can be refunded');
ok(refund_allowed(new DateTimeImmutable('2026-11-11 10:00:00', new DateTimeZone('UTC')), $now), 'exactly 24h ahead can be refunded');
ok(!refund_allowed(new DateTimeImmutable('2026-11-11 09:59:59', new DateTimeZone('UTC')), $now), 'under 24h = full fee kept');
exit($fail ? 1 : 0);
