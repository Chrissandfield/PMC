<?php
declare(strict_types=1);

/* Visitor bookings: reserve an hour, pay through Stripe, confirm by webhook, cancel with the 24-hour rule. */

const HOLD_MINUTES = 32;   // a little over Stripe's 30-minute minimum checkout lifetime

function online_booking_enabled(array $club): bool
{
    return !empty($club['stripe_account_id']) && (int) $club['stripe_charges_enabled'] === 1 && stripe_configured();
}

function booking_token(string $reference): string
{
    $c = $GLOBALS['cfg'];
    $key = (string) ($c['app_secret'] ?: ($c['stripe_webhook_secret'] ?: $c['db_pass']));
    return substr(hash_hmac('sha256', 'booking:' . $reference, $key ?: 'pmc-dev'), 0, 24);
}

function booking_link(string $reference): string
{
    return $GLOBALS['cfg']['app_url'] . '/booking/' . rawurlencode($reference) . '?t=' . booking_token($reference);
}

function now_utc(): string { return utc_string(new DateTimeImmutable('now', london())); }

/** Send (or at least record) an email. Failures are logged on the outbox row and never break a booking. */
function send_email(string $to, string $subject, string $body, ?int $bookingId = null): void
{
    q('INSERT INTO email_outbox (to_email, subject, body, booking_id) VALUES (?,?,?,?)', [$to, $subject, $body, $bookingId]);
    $id = (int) q('SELECT MAX(id) AS i FROM email_outbox WHERE to_email = ?', [$to])->fetch()['i'];
    $err = null;
    if (getenv('PMC_MAIL_FAKE') !== '1') {
        $headers = "From: PlayMoreCricket <hello@playmorecricket.com>\r\nReply-To: hello@playmorecricket.com\r\nContent-Type: text/plain; charset=UTF-8";
        $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
        if (!$ok) { $err = 'mail() returned false'; }
    }
    if ($err === null) { q('UPDATE email_outbox SET sent_at = ?, attempts = attempts + 1 WHERE id = ?', [now_utc(), $id]); }
    else { q('UPDATE email_outbox SET attempts = attempts + 1, last_error = ? WHERE id = ?', [$err, $id]); }
}

function booking_by_ref(string $ref): ?array
{
    return q('SELECT b.*, n.name AS net_name, n.club_id, c.name AS club_name, c.slug AS club_slug, c.email AS club_email, c.stripe_account_id AS club_stripe,
                     co.name AS coach_name
              FROM bookings b JOIN nets n ON n.id = b.net_id JOIN clubs c ON c.id = n.club_id LEFT JOIN coaches co ON co.id = b.coach_id
              WHERE b.reference = ?', [$ref])->fetch() ?: null;
}

function describe_booking(array $b): string
{
    $when = from_utc($b['starts_at'])->format('l j F Y, H:i');
    return ($b['coach_id'] ? 'Coaching session with ' . $b['coach_name'] : 'Net hire') . ' · ' . $b['net_name'] . ' · ' . $when . ' · ' . $b['club_name'];
}

/**
 * Reserve an hour for a visitor and start payment.
 * Returns [ok, message, redirectUrl|null]. On success the booking is pending_payment and held for HOLD_MINUTES.
 */
function visitor_reserve(array $club, array $net, string $localStart, array $in): array
{
    if (!online_booking_enabled($club)) { return [false, 'Online booking is not open for this club yet.', null]; }
    $start = DateTimeImmutable::createFromFormat('!Y-m-d\TH', $localStart, london());
    if (!$start || $start->format('Y-m-d\TH') !== $localStart) { return [false, 'That time is not valid.', null]; }
    if ((int) $net['club_id'] !== (int) $club['id']) { return [false, 'That net does not belong to this club.', null]; }
    $hrs = hours_for((int) $net['id'], $start); $h = (int) $start->format('G');
    if (!$hrs || $h < $hrs[0] || $h >= $hrs[1]) { return [false, 'The net is closed at that time.', null]; }
    if ($start <= new DateTimeImmutable('now', london())) { return [false, 'That time has already started.', null]; }

    $kind = (string) ($in['kind'] ?? 'net');
    $name = trim((string) ($in['customer_name'] ?? '')); $email = trim((string) ($in['customer_email'] ?? '')); $phone = trim((string) ($in['customer_phone'] ?? ''));
    if ($name === '' || mb_strlen($name) > 160) { return [false, 'Please enter your name.', null]; }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { return [false, 'Please enter a valid email address.', null]; }
    if (strlen(preg_replace('/\D/', '', $phone)) < 7) { return [false, 'Please enter a phone number we can reach you on.', null]; }
    if (empty($in['accept_terms'])) { return [false, 'Please tick to accept the booking and cancellation terms.', null]; }

    $junior = !empty($in['junior']); $childName = trim((string) ($in['child_first_name'] ?? '')); $childAge = (int) ($in['child_age'] ?? 0);
    if ($junior && (empty($in['parent_confirmed']) || $childName === '' || $childAge < 4 || $childAge > 17)) {
        return [false, "For under-18s, enter the child's first name and age (4 to 17) and tick the parent/guardian box.", null];
    }

    $coach = null; $machine = !empty($in['machine']);
    if ($kind === 'coach') {
        $coach = q('SELECT * FROM coaches WHERE id = ? AND club_id = ? AND active', [(int) ($in['coach_id'] ?? 0), $club['id']])->fetch() ?: null;
        if (!$coach) { return [false, 'Please choose a coach.', null]; }
        $avail = q('SELECT 1 FROM coach_availability WHERE coach_id = ? AND starts_at = ?', [$coach['id'], utc_string($start)])->fetch();
        $busy  = q('SELECT 1 FROM bookings WHERE coach_id = ? AND starts_at = ? AND status IN ' . LIVE, [$coach['id'], utc_string($start)])->fetch();
        if (!$avail || $busy) { return [false, $coach['name'] . ' is not available at that time.', null]; }
        $price = (int) $coach['hourly_price_pence'];
        if ($machine) {
            if ($net['machine_price_pence'] === null) { return [false, 'This net has no bowling machine.', null]; }
            $price += (int) $net['machine_price_pence'];
        }
        $type = 'coach';
    } elseif ($kind === 'net') {
        if ($machine) { return [false, 'The bowling machine is only available with a coach.', null]; }
        $price = (int) $net['nonmember_price_pence']; $type = 'nonmember';
    } else { return [false, 'Choose net hire or a coaching session.', null]; }
    if ($price <= 0) { return [false, 'This booking has no price set. Please contact the club.', null]; }

    expire_stale();
    $commission = commission_pence($price, (float) $club['commission_pct'], $club['commission_free_until']);
    $ref = new_reference();
    try {
        q("INSERT INTO bookings (reference, net_id, coach_id, booking_type, starts_at, ends_at, with_machine, status, customer_name, customer_email, customer_phone,
                                 is_junior, parent_confirmed, child_first_name, child_age, price_pence, commission_pence, stripe_account_id, expires_at)
           VALUES (?,?,?,?,?,?,?,'pending_payment',?,?,?,?,?,?,?,?,?,?,?)",
          [$ref, $net['id'], $coach['id'] ?? null, $type, utc_string($start), utc_string($start->modify('+1 hour')), $machine ? 1 : 0, $name, $email, $phone,
           $junior ? 1 : 0, $junior ? 1 : 0, $junior ? $childName : null, $junior ? $childAge : null, $price, $commission, $club['stripe_account_id'],
           utc_string(new DateTimeImmutable('+' . HOLD_MINUTES . ' minutes', london()))]);
    } catch (PDOException $e) {
        if (is_unique_violation($e)) { return [false, 'That hour has just been BOOKED by someone else. Please pick another.', null]; }
        throw $e;
    }
    $b = booking_by_ref($ref);
    try {
        $s = stripe_checkout_session($b, $club['stripe_account_id'], describe_booking($b),
            booking_link($ref) . '&paid=1', $GLOBALS['cfg']['app_url'] . '/club/' . $club['slug']);
        q('UPDATE bookings SET stripe_checkout_session_id = ? WHERE id = ?', [$s['id'], $b['id']]);
    } catch (Throwable $e) {
        error_log('PMC checkout failed for ' . $ref . ': ' . $e->getMessage());
        q("UPDATE bookings SET status = 'expired' WHERE id = ?", [$b['id']]);   // free the hour straight away
        return [false, 'We could not start the payment. Nothing has been charged. Please try again in a moment.', null];
    }
    audit(null, 'booking_reserved', $ref . ' ' . $localStart . ' ' . $type);
    return [true, 'Reserved', (string) $s['url']];
}

function queue_confirmation_emails(array $b): void
{
    $link = booking_link($b['reference']);
    $when = describe_booking($b);
    $paid = money((int) $b['price_pence']);
    send_email($b['customer_email'], 'Booking confirmed: ' . $b['reference'],
        "Hello {$b['customer_name']},\n\nYour booking is confirmed and paid ({$paid}).\n\n{$when}\nReference: {$b['reference']}\n\n"
        . "View or cancel your booking: {$link}\n\nCancelling more than 24 hours before the start gives a full refund. Within 24 hours the full fee applies.\n\nPlayMoreCricket", (int) $b['id']);
    if (!empty($b['club_email'])) {
        send_email($b['club_email'], 'New booking: ' . $b['reference'],
            "A new paid booking.\n\n{$when}\nName: {$b['customer_name']}\nPhone: {$b['customer_phone']}\nEmail: {$b['customer_email']}\nPaid: {$paid}"
            . ($b['is_junior'] ? "\nUNDER-18: {$b['child_first_name']}, aged {$b['child_age']}. A parent/guardian has confirmed they agree and will be present." : '')
            . "\nReference: {$b['reference']}\n\nPlayMoreCricket", (int) $b['id']);
    }
}

/**
 * Handle a verified Stripe event. Returns a short result string for logging.
 * Duplicate deliveries are ignored (stripe_events keeps every id it has seen).
 */
function handle_stripe_event(array $event): string
{
    $id = (string) ($event['id'] ?? '');
    if ($id === '') { return 'no id'; }
    try { q('INSERT INTO stripe_events (id, type) VALUES (?, ?)', [$id, (string) ($event['type'] ?? '')]); }
    catch (PDOException $e) { if (is_unique_violation($e)) { return 'duplicate'; } throw $e; }

    $type = (string) ($event['type'] ?? ''); $obj = $event['data']['object'] ?? [];
    $result = 'ignored';
    if ($type === 'checkout.session.completed' && ($obj['payment_status'] ?? '') === 'paid') {
        $b = booking_by_ref((string) ($obj['client_reference_id'] ?? ''));
        if (!$b) { $result = 'unknown booking'; }
        elseif ($b['status'] === 'confirmed') { $result = 'already confirmed'; }
        elseif ((int) ($obj['amount_total'] ?? -1) !== (int) $b['price_pence']) {
            error_log('PMC amount mismatch on ' . $b['reference']); $result = 'amount mismatch';
        } else {
            try {
                q("UPDATE bookings SET status = 'confirmed', paid_at = ?, stripe_payment_intent_id = ?, expires_at = NULL, cancelled_at = NULL, cancel_reason = NULL WHERE id = ?",
                  [now_utc(), $obj['payment_intent'] ?? null, $b['id']]);
                queue_confirmation_emails(booking_by_ref($b['reference']));
                $result = 'confirmed';
            } catch (PDOException $e) {
                if (!is_unique_violation($e)) { throw $e; }
                // The customer paid after the hold ran out and someone else now has the hour: refund automatically.
                q('UPDATE bookings SET stripe_payment_intent_id = ?, paid_at = ? WHERE id = ?', [$obj['payment_intent'] ?? null, now_utc(), $b['id']]);
                [$ok] = refund_booking(booking_by_ref($b['reference']), 'Paid after the hold expired and the hour was taken');
                send_email($b['customer_email'], 'Booking not possible: ' . $b['reference'],
                    "Hello {$b['customer_name']},\n\nSorry: the hour you paid for was taken by someone else before your payment completed. "
                    . ($ok ? 'You have been refunded in full.' : 'We are refunding you; please contact the club if it does not arrive within 5 working days.') . "\n\nPlayMoreCricket", (int) $b['id']);
                $result = 'refunded (slot taken)';
            }
        }
    } elseif ($type === 'checkout.session.expired') {
        q("UPDATE bookings SET status = 'expired' WHERE reference = ? AND status = 'pending_payment'", [(string) ($obj['client_reference_id'] ?? '')]);
        $result = 'expired';
    } elseif ($type === 'account.updated' && !empty($obj['id'])) {
        q('UPDATE clubs SET stripe_charges_enabled = ? WHERE stripe_account_id = ?', [!empty($obj['charges_enabled']) ? 1 : 0, $obj['id']]);
        $result = 'account updated';
    }
    q('UPDATE stripe_events SET processed_at = ? WHERE id = ?', [now_utc(), $id]);
    return $result;
}

/** Refund a paid booking in full (also gives back our commission) and mark it. Returns [ok, message]. */
function refund_booking(array $b, string $reason): array
{
    if (empty($b['stripe_payment_intent_id']) || empty($b['club_stripe'])) { return [false, 'This booking has no online payment to refund.']; }
    try { stripe_refund($b['stripe_payment_intent_id'], $b['club_stripe'], $b['reference']); }
    catch (Throwable $e) { error_log('PMC refund failed ' . $b['reference'] . ': ' . $e->getMessage()); return [false, 'The refund could not be processed. Nothing has changed; please contact the club.']; }
    q("UPDATE bookings SET status = 'refunded', refunded_at = ?, refund_pence = ?, cancelled_at = ?, cancel_reason = ? WHERE id = ?",
      [now_utc(), $b['price_pence'], now_utc(), mb_substr($reason, 0, 300), $b['id']]);
    return [true, 'Refunded ' . money((int) $b['price_pence']) . '.'];
}

/** Customer cancels from their booking page. 24+ hours ahead: automatic refund; otherwise the full fee applies. */
function customer_cancel(array $b): array
{
    if ($b['status'] !== 'confirmed') { return [false, 'This booking cannot be cancelled online.']; }
    $startsUtc = new DateTimeImmutable($b['starts_at'], new DateTimeZone('UTC'));
    if (!refund_allowed($startsUtc)) {
        return [false, 'This session starts in less than 24 hours, so the full fee applies and it cannot be cancelled online. Please contact the club if you have a problem.'];
    }
    [$ok, $msg] = refund_booking($b, 'Cancelled by customer');
    if (!$ok) { return [false, $msg]; }
    audit(null, 'booking_cancelled_by_customer', $b['reference']);
    send_email($b['customer_email'], 'Booking cancelled: ' . $b['reference'],
        "Hello {$b['customer_name']},\n\nYour booking has been cancelled and refunded in full (" . money((int) $b['price_pence']) . "). Refunds usually take 5 to 10 working days to reach your card.\n\n" . describe_booking($b) . "\n\nPlayMoreCricket", (int) $b['id']);
    if (!empty($b['club_email'])) {
        send_email($b['club_email'], 'Booking cancelled: ' . $b['reference'], 'The customer cancelled and was refunded. ' . describe_booking($b) . "\n\nThe hour is free again.", (int) $b['id']);
    }
    return [true, 'Your booking has been cancelled and refunded in full.'];
}
