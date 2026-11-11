<?php
declare(strict_types=1);

const SLOT_HOLD_MINUTES = 30;   // how long an unpaid online booking holds its hour

function london(): DateTimeZone { return new DateTimeZone('Europe/London'); }

/** Monday of the week containing $date (Y-m-d), in club local time. */
function week_start(?string $date): DateTimeImmutable
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $date, london()) ?: new DateTimeImmutable('today', london());
    return $d->modify('-' . ((int) $d->format('N') - 1) . ' days');
}

function utc_string(DateTimeImmutable $local): string
{
    return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function from_utc(string $utc): DateTimeImmutable
{
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(london());
}

/** Pending online bookings that were never paid stop holding their hour. */
function expire_stale(): void
{
    q("UPDATE bookings SET status = 'expired' WHERE status = 'pending_payment' AND expires_at IS NOT NULL AND expires_at < ?",
      [utc_string(new DateTimeImmutable('now', london()))]);
}

function club_by_slug(string $slug): ?array
{
    return q('SELECT * FROM clubs WHERE slug = ?', [$slug])->fetch() ?: null;
}

function club_nets(int $clubId): array
{
    return q('SELECT * FROM nets WHERE club_id = ? AND active ORDER BY id', [$clubId])->fetchAll();
}

function club_coaches(int $clubId): array
{
    return q('SELECT * FROM coaches WHERE club_id = ? AND active ORDER BY name', [$clubId])->fetchAll();
}

/** Opening hours for one date: [open, close] club-local hours, or null when closed. */
function hours_for(int $netId, DateTimeImmutable $day): ?array
{
    $r = q('SELECT is_closed, open_hour, close_hour FROM opening_hours WHERE net_id = ? AND weekday = ?',
           [$netId, (int) $day->format('N') - 1])->fetch();
    if (!$r || (int) $r['is_closed'] === 1 || $r['is_closed'] === true) { return null; }
    return [(int) $r['open_hour'], (int) $r['close_hour']];
}

/** Live (pending or confirmed) bookings for a net in the week, keyed by local 'Y-m-d H'. */
function week_bookings(int $netId, DateTimeImmutable $monday): array
{
    $from = utc_string($monday->modify('-1 day'));
    $to   = utc_string($monday->modify('+8 days'));
    $rows = q("SELECT b.*, c.name AS coach_name FROM bookings b LEFT JOIN coaches c ON c.id = b.coach_id
               WHERE b.net_id = ? AND b.status IN ('pending_payment','confirmed') AND b.starts_at >= ? AND b.starts_at < ?",
              [$netId, $from, $to])->fetchAll();
    $out = [];
    foreach ($rows as $r) { $out[from_utc($r['starts_at'])->format('Y-m-d H')] = $r; }
    return $out;
}

function new_reference(): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // no 0/O/1/I to avoid mix-ups
    $s = '';
    for ($i = 0; $i < 6; $i++) { $s .= $chars[random_int(0, strlen($chars) - 1)]; }
    return 'PMC-' . $s;
}

/**
 * Staff booking (club contact, admin or a coach). Returns [bool ok, string message].
 * Coaches may only book under their own name; managers may book anything and block time.
 */
function staff_book(array $user, array $club, array $net, string $localStart, array $in): array
{
    $start = DateTimeImmutable::createFromFormat('!Y-m-d\TH', $localStart, london());
    if (!$start || $start->format('Y-m-d\TH') !== $localStart) { return [false, 'That time is not valid.']; }
    if ((int) $net['club_id'] !== (int) $club['id']) { return [false, 'That net does not belong to this club.']; }
    if (!can_staff($user, $club)) { return [false, 'You cannot book at this club.']; }

    $hrs = hours_for((int) $net['id'], $start);
    $h = (int) $start->format('G');
    if (!$hrs || $h < $hrs[0] || $h >= $hrs[1]) { return [false, 'The net is closed at that time.']; }
    if ($start <= new DateTimeImmutable('now', london())) { return [false, 'That time has already started.']; }

    $manager = can_manage($user, $club);
    $type = (string) ($in['type'] ?? '');
    $allowed = $manager ? ['member', 'coach', 'nonmember', 'blocked'] : ['coach'];
    if (!in_array($type, $allowed, true)) { return [false, 'You cannot make that kind of booking.']; }

    $coach = null; $machine = !empty($in['machine']);
    if ($type === 'coach') {
        if ($manager) {
            $coach = q('SELECT * FROM coaches WHERE id = ? AND club_id = ? AND active', [(int) ($in['coach_id'] ?? 0), $club['id']])->fetch() ?: null;
        } else {
            $coach = own_coach($user, $club);   // coaches book only as themselves
        }
        if (!$coach) { return [false, 'Choose a coach for this booking.']; }
        $busy = q('SELECT 1 FROM bookings WHERE coach_id = ? AND starts_at = ? AND status IN ' . LIVE, [$coach['id'], utc_string($start)])->fetch();
        if ($busy) { return [false, $coach['name'] . ' is already booked at that time.']; }
        if ($manager) {
            $avail = q('SELECT 1 FROM coach_availability WHERE coach_id = ? AND starts_at = ?', [$coach['id'], utc_string($start)])->fetch();
            if (!$avail && empty($in['override'])) {
                return [false, $coach['name'] . ' has not marked that hour as available. Tick "override" if you have agreed it with them.'];
            }
        }
    }
    if ($machine && ($type !== 'coach' && (int) $net['machine_coach_only'] === 1)) { return [false, 'The machine is only available with a coach.']; }
    if ($machine && $net['machine_price_pence'] === null) { return [false, 'This net has no machine.']; }

    $price = match ($type) {
        'member'    => (int) $net['member_price_pence'],
        'nonmember' => (int) $net['nonmember_price_pence'],
        'coach'     => (int) $coach['hourly_price_pence'],
        default     => 0,
    };
    if ($machine) { $price += (int) $net['machine_price_pence']; }

    $junior = !empty($in['junior']);
    $childName = trim((string) ($in['child_first_name'] ?? ''));
    $childAge  = (int) ($in['child_age'] ?? 0);
    if ($junior && (empty($in['parent_confirmed']) || $childName === '' || $childAge < 4 || $childAge > 17)) {
        return [false, 'For under-18s, enter the child\'s first name and age (4 to 17) and tick the parent/guardian box.'];
    }

    $name = trim((string) ($in['customer_name'] ?? ''));
    if ($type !== 'blocked' && $name === '') { return [false, 'Enter a name for the booking.']; }
    $note = mb_substr(trim((string) ($in['note'] ?? '')), 0, 500);

    expire_stale();
    $ref = new_reference();
    try {
        q("INSERT INTO bookings (reference, net_id, coach_id, booking_type, starts_at, ends_at, with_machine, status,
                                 customer_name, customer_email, customer_phone, is_junior, parent_confirmed,
                                 child_first_name, child_age, note, price_pence, created_by_user_id)
           VALUES (?,?,?,?,?,?,?,'confirmed',?,?,?,?,?,?,?,?,?,?)",
          [$ref, $net['id'], $coach['id'] ?? null, $type, utc_string($start), utc_string($start->modify('+1 hour')),
           $machine ? 1 : 0, $name !== '' ? $name : null, trim((string) ($in['customer_email'] ?? '')) ?: null,
           trim((string) ($in['customer_phone'] ?? '')) ?: null, $junior ? 1 : 0, $junior ? 1 : 0,
           $junior ? $childName : null, $junior ? $childAge : null, $note !== '' ? $note : null, $price, $user['id']]);
    } catch (PDOException $e) {
        if (is_unique_violation($e)) { return [false, 'Someone has just booked that hour. Pick another.']; }
        throw $e;
    }
    audit((int) $user['id'], 'booking_created', $ref . ' ' . $localStart . ' ' . $type);
    return [true, 'Booked ' . $start->format('D j M, H:i') . ' (' . $ref . ').'];
}

/** Release (cancel) a booking. Managers: any at their club. Coaches: only their own coach bookings. */
function release_booking(array $user, array $club, int $bookingId): array
{
    $b = q('SELECT b.*, n.club_id FROM bookings b JOIN nets n ON n.id = b.net_id WHERE b.id = ?', [$bookingId])->fetch();
    if (!$b || (int) $b['club_id'] !== (int) $club['id'] || !can_staff($user, $club)) { return [false, 'Booking not found.']; }
    if (!in_array($b['status'], ['pending_payment', 'confirmed'], true)) { return [false, 'That booking is already released.']; }
    if (!can_manage($user, $club)) {
        $mine = own_coach($user, $club);
        if (!$mine || (int) $b['coach_id'] !== (int) $mine['id']) { return [false, 'You can only release your own bookings.']; }
    }
    q("UPDATE bookings SET status = 'cancelled', cancelled_at = ?, cancel_reason = 'Released by staff' WHERE id = ?",
      [utc_string(new DateTimeImmutable('now', london())), $bookingId]);
    audit((int) $user['id'], 'booking_released', $b['reference']);
    return [true, 'Released ' . $b['reference'] . '.'];
}

const LIVE = "('pending_payment','confirmed')";

/** Hours coaches at this club have marked available in the week: [coach_id]['Y-m-d H'] => true. */
function week_availability(int $clubId, DateTimeImmutable $monday): array
{
    $rows = q('SELECT a.coach_id, a.starts_at FROM coach_availability a JOIN coaches c ON c.id = a.coach_id
               WHERE c.club_id = ? AND a.starts_at >= ? AND a.starts_at < ?',
              [$clubId, utc_string($monday->modify('-1 day')), utc_string($monday->modify('+8 days'))])->fetchAll();
    $out = [];
    foreach ($rows as $r) { $out[(int) $r['coach_id']][from_utc($r['starts_at'])->format('Y-m-d H')] = true; }
    return $out;
}

/** Hours coaches are already booked in the week (any net): [coach_id]['Y-m-d H'] => true. */
function week_coach_busy(int $clubId, DateTimeImmutable $monday): array
{
    $rows = q('SELECT b.coach_id, b.starts_at FROM bookings b JOIN coaches c ON c.id = b.coach_id
               WHERE c.club_id = ? AND b.status IN ' . LIVE . ' AND b.starts_at >= ? AND b.starts_at < ?',
              [$clubId, utc_string($monday->modify('-1 day')), utc_string($monday->modify('+8 days'))])->fetchAll();
    $out = [];
    foreach ($rows as $r) { $out[(int) $r['coach_id']][from_utc($r['starts_at'])->format('Y-m-d H')] = true; }
    return $out;
}

/** Coaches of the club for one hour, split into free (available and not booked) and the rest. */
function coaches_for_hour(int $clubId, DateTimeImmutable $startLocal): array
{
    $u = utc_string($startLocal);
    $free = []; $other = [];
    foreach (club_coaches($clubId) as $c) {
        $avail = q('SELECT 1 FROM coach_availability WHERE coach_id = ? AND starts_at = ?', [$c['id'], $u])->fetch();
        $busy  = q('SELECT 1 FROM bookings WHERE coach_id = ? AND starts_at = ? AND status IN ' . LIVE, [$c['id'], $u])->fetch();
        if ($busy) { continue; }
        if ($avail) { $free[] = $c; } else { $other[] = $c; }
    }
    return [$free, $other];
}

/**
 * Save a coach's available hours for one week, optionally repeating the same pattern for the next
 * $repeatWeeks weeks. $selected holds 'dayIndex-hour' strings (day 0 = Monday). Only future hours
 * inside the club's opening times are kept.
 */
function save_availability(array $coach, DateTimeImmutable $monday, array $selected, int $repeatWeeks): void
{
    $nets = club_nets((int) $coach['club_id']);
    $now = new DateTimeImmutable('now', london());
    $pattern = [];
    foreach ($selected as $s) {
        if (!is_string($s) || !preg_match('/^([0-6])-(\d{1,2})$/', $s, $m)) { continue; }
        $pattern[] = [(int) $m[1], (int) $m[2]];
    }
    $repeatWeeks = max(0, min(12, $repeatWeeks));
    $pdo = db();
    $pdo->beginTransaction();
    try {
        for ($w = 0; $w <= $repeatWeeks; $w++) {
            $wk = $monday->modify('+' . (7 * $w) . ' days');
            q('DELETE FROM coach_availability WHERE coach_id = ? AND starts_at >= ? AND starts_at < ?',
              [$coach['id'], utc_string($wk->setTime(0, 0)), utc_string($wk->modify('+7 days')->setTime(0, 0))]);
            foreach ($pattern as [$di, $h]) {
                $day = $wk->modify("+$di days");
                $slot = $day->setTime($h, 0);
                if ($slot <= $now) { continue; }
                $open = false;
                foreach ($nets as $n) { $hrs = hours_for((int) $n['id'], $day); if ($hrs && $h >= $hrs[0] && $h < $hrs[1]) { $open = true; } }
                if (!$open) { continue; }
                q('INSERT INTO coach_availability (coach_id, starts_at) VALUES (?, ?)', [$coach['id'], utc_string($slot)]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}
