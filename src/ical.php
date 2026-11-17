<?php
declare(strict_types=1);
// Private calendar feeds (iCal). A club or coach pastes the link into Google/Outlook/Apple Calendar once.
// The link carries a secret token; "new link" replaces it. Bookings only; no phone numbers or emails.

function feed_token(string $table, int $id, bool $renew = false): string
{
    if ($table !== 'clubs' && $table !== 'coaches') { throw new InvalidArgumentException('table'); }
    $cur = (string) (q("SELECT feed_token FROM $table WHERE id = ?", [$id])->fetch()['feed_token'] ?? '');
    if ($cur !== '' && !$renew) { return $cur; }
    $t = bin2hex(random_bytes(16));
    q("UPDATE $table SET feed_token = ? WHERE id = ?", [$t, $id]);
    return $t;
}

function feed_url(string $kind, string $token): string { return $GLOBALS['cfg']['app_url'] . '/calendar/' . $kind . '/' . $token . '.ics'; }

function ics_escape(string $s): string { return str_replace(["\\", ";", ",", "\r\n", "\n", "\r"], ["\\\\", "\;", "\\,", "\\n", "\\n", "\\n"], $s); }

function ics_fold(string $line): string
{
    $out = ''; $len = 0;
    foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        $n = strlen($ch);
        if ($len + $n > 74) { $out .= "\r\n "; $len = 1; }
        $out .= $ch; $len += $n;
    }
    return $out;
}

/** Build the calendar text. $rows are booking rows joined with net_name, club_name, coach_name. */
function ics_calendar(string $name, array $rows, bool $clubView): string
{
    $L = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//PlayMoreCricket//Bookings//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
          'X-WR-CALNAME:' . ics_escape($name), 'X-PUBLISHED-TTL:PT1H', 'REFRESH-INTERVAL;VALUE=DURATION:PT1H'];
    foreach ($rows as $b) {
        $st = new DateTimeImmutable($b['starts_at'], new DateTimeZone('UTC')); $en = new DateTimeImmutable($b['ends_at'], new DateTimeZone('UTC'));
        if ($b['booking_type'] === 'blocked') { $sum = 'Blocked' . ($clubView ? ' · ' . $b['net_name'] : ''); }
        elseif ($b['coach_id']) { $sum = 'Coaching' . ($clubView && $b['coach_name'] ? ' with ' . $b['coach_name'] : '') . ($b['customer_name'] ? ' · ' . $b['customer_name'] : ''); }
        else { $sum = 'Net hire' . ($b['customer_name'] ? ' · ' . $b['customer_name'] : ''); }
        $desc = [$b['net_name'] . ' · ' . $b['club_name']];
        if ($b['with_machine']) { $desc[] = 'Bowling machine'; }
        if ($b['is_junior']) { $desc[] = 'UNDER-18: ' . ($b['child_first_name'] ?: 'child') . ($b['child_age'] ? ', aged ' . $b['child_age'] : '') . '. Parent/guardian present.'; }
        $desc[] = 'Reference ' . $b['reference'];
        array_push($L, 'BEGIN:VEVENT', 'UID:' . $b['reference'] . '@playmorecricket.com', 'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'DTSTART:' . $st->format('Ymd\THis\Z'), 'DTEND:' . $en->format('Ymd\THis\Z'),
            'SUMMARY:' . ics_escape($sum), 'LOCATION:' . ics_escape($b['club_name'] . ' · ' . $b['net_name']), 'DESCRIPTION:' . ics_escape(implode("\n", $desc)),
            'STATUS:CONFIRMED', 'END:VEVENT');
    }
    $L[] = 'END:VCALENDAR';
    return implode("\r\n", array_map('ics_fold', $L)) . "\r\n";
}

/** Confirmed bookings from 30 days ago onwards, for a club ('club', whole club) or a coach ('coach'). */
function feed_bookings(string $kind, int $id): array
{
    $from = utc_string(new DateTimeImmutable('-30 days', london()));
    $where = $kind === 'club' ? 'n.club_id = ?' : 'b.coach_id = ?';
    return q("SELECT b.*, n.name AS net_name, c.name AS club_name, co.name AS coach_name
              FROM bookings b JOIN nets n ON n.id = b.net_id JOIN clubs c ON c.id = n.club_id LEFT JOIN coaches co ON co.id = b.coach_id
              WHERE $where AND b.status = 'confirmed' AND b.starts_at >= ? ORDER BY b.starts_at", [$id, $from])->fetchAll();
}

function serve_feed(string $kind, string $token): void
{
    $table = $kind === 'club' ? 'clubs' : 'coaches';
    $r = $token !== '' ? q("SELECT id, name FROM $table WHERE feed_token = ?", [$token])->fetch() : false;
    if (!$r) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo "Not found\n"; exit; }
    header('Content-Type: text/calendar; charset=utf-8'); header('Cache-Control: private, max-age=900'); header('X-Robots-Tag: noindex');
    echo ics_calendar($r['name'] . ' · PlayMoreCricket', feed_bookings($kind, (int) $r['id']), $kind === 'club');
    exit;
}

/** The "add to your calendar" box shown to a club manager or coach. */
function calendar_box(string $kind, int $id, string $csrfField, string $returnTo): string
{
    $url = feed_url($kind, feed_token($kind === 'club' ? 'clubs' : 'coaches', $id));
    return '<h2>Add bookings to your calendar</h2><div class="card"><p class="muted">Paste this private link into Google Calendar (Other calendars, From URL), Outlook (Add calendar, From internet) or Apple Calendar (New calendar subscription). New and cancelled bookings then appear there by themselves, usually within an hour or two. Anyone with the link can see these bookings, so keep it private.</p>'
        . '<input readonly class="feedurl" value="' . h($url) . '" aria-label="Calendar link">'
        . '<form method="post" action="/calendar/renew" class="inlineform">' . $csrfField . '<input type="hidden" name="kind" value="' . h($kind) . '"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="return" value="' . h($returnTo) . '"><button type="submit" class="secondary">Make a new link (stops the old one working)</button></form></div>';
}
