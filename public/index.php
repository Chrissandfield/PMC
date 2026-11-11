<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/') ?: '/';
$user = current_user();

// Local `php -S` serves real files itself; this just lets the router script fall through.
if (PHP_SAPI === 'cli-server' && $path !== '/' && is_file(__DIR__ . $path)) { return false; }

if ($path === '/' && $method === 'GET') {
    $clubs = q("SELECT slug, name, town, county, postcode, sponsored_partner, crest_path FROM clubs WHERE status = 'active' OR sponsored_partner ORDER BY name")->fetchAll();
    $b = '<h1>Find a cricket net</h1><p class="lead">Hire a net by the hour and add a coach for a session.</p><ul class="clubs">';
    foreach ($clubs as $c) {
        $b .= '<li><a href="/club/' . h($c['slug']) . '">' . ($c['crest_path'] ? '<img src="/assets/' . h($c['crest_path']) . '" alt="" width="40" height="41">' : '')
            . '<span><strong>' . h($c['name']) . '</strong><br>' . h($c['town']) . ', ' . h($c['county']) . ' ' . h((string) $c['postcode']) . '</span>'
            . ($c['sponsored_partner'] ? '<em class="tag">Sponsored partner</em>' : '') . '</a></li>';
    }
    page('Find a net', $b . '</ul>');
}

if ($path === '/login') {
    if ($method === 'POST') {
        csrf_check();
        if (login((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''))) {
            $u = q('SELECT role, club_id FROM users WHERE id = ?', [$_SESSION['uid']])->fetch();
            $club = $u['club_id'] ? q('SELECT slug FROM clubs WHERE id = ?', [$u['club_id']])->fetch() : null;
            redirect($u['role'] === 'admin' ? '/admin' : ($club ? '/club/' . $club['slug'] : '/'));
        }
        flash('Email or password not recognised.', true);
        redirect('/login');
    }
    page('Log in', '<h1>Log in</h1><form method="post" class="card narrow">' . csrf_field()
        . '<label>Email<input type="email" name="email" required autocomplete="username"></label>'
        . '<label>Password<input type="password" name="password" required autocomplete="current-password"></label>'
        . '<button type="submit">Log in</button></form>');
}

if ($path === '/logout' && $method === 'POST') { csrf_check(); logout(); redirect('/'); }

if ($path === '/admin' || $path === '/admin/commission') {
    require_admin();
    if ($path === '/admin/commission' && $method === 'POST') {
        csrf_check();
        $pct = (float) ($_POST['commission_pct'] ?? 10);
        $free = trim((string) ($_POST['commission_free_until'] ?? ''));
        if ($pct < 0 || $pct > 100 || ($free !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $free))) {
            flash('Commission must be 0 to 100 and the date must look like 2027-11-03.', true); redirect('/admin');
        }
        q('UPDATE clubs SET commission_pct = ?, commission_free_until = ? WHERE id = ?', [number_format($pct, 2, '.', ''), $free !== '' ? $free : null, (int) ($_POST['club_id'] ?? 0)]);
        audit((int) $user['id'], 'commission_changed', 'club ' . (int) $_POST['club_id'] . ' ' . $pct . '% until ' . $free);
        flash('Commission saved.'); redirect('/admin');
    }
    $b = '<h1>Admin</h1><h2>Clubs and commission</h2>';
    foreach (q('SELECT id, name, status, commission_pct, commission_free_until FROM clubs ORDER BY name')->fetchAll() as $c) {
        $b .= '<form method="post" action="/admin/commission" class="card row">' . csrf_field() . '<input type="hidden" name="club_id" value="' . (int) $c['id'] . '">'
            . '<strong>' . h($c['name']) . '</strong> <span class="muted">(' . h($c['status']) . ')</span>'
            . '<label>Commission %<input name="commission_pct" value="' . h((string) $c['commission_pct']) . '" inputmode="decimal" size="5"></label>'
            . '<label>No commission until<input type="date" name="commission_free_until" value="' . h((string) $c['commission_free_until']) . '"></label>'
            . '<button type="submit">Save</button></form>';
    }
    page('Admin', $b);
}

if ($path === '/coach/availability') {
    $u = require_login();
    if (!$u['club_id']) { http_response_code(403); page('Not allowed', '<p>This page is for club staff.</p>'); }
    $club = q('SELECT * FROM clubs WHERE id = ?', [$u['club_id']])->fetch();
    $manager = can_manage($u, $club);
    if ($u['role'] === 'coach') { $coach = own_coach($u, $club); }
    else { $coach = q('SELECT * FROM coaches WHERE id = ? AND club_id = ?', [(int) ($_REQUEST['coach'] ?? 0), $club['id']])->fetch() ?: null; }
    if (!$coach && !$manager) { http_response_code(403); page('Not allowed', '<p>No coach profile is linked to your login.</p>'); }
    $monday = week_start($_REQUEST['week'] ?? null);
    if ($method === 'POST' && $coach) {
        csrf_check();
        save_availability($coach, $monday, (array) ($_POST['slot'] ?? []), (int) ($_POST['repeat'] ?? 0));
        audit((int) $u['id'], 'availability_saved', $coach['name'] . ' week ' . $monday->format('Y-m-d'));
        flash('Availability saved for ' . $coach['name'] . '.');
        redirect('/coach/availability?week=' . $monday->format('Y-m-d') . ($manager ? '&coach=' . (int) $coach['id'] : ''));
    }
    $b = '<h1>Coach availability</h1>';
    if ($manager) {
        $b .= '<form method="get" class="card row"><input type="hidden" name="week" value="' . h($monday->format('Y-m-d')) . '"><label>Coach<select name="coach">';
        foreach (club_coaches((int) $club['id']) as $c) { $b .= '<option value="' . (int) $c['id'] . '"' . ($coach && (int) $c['id'] === (int) $coach['id'] ? ' selected' : '') . '>' . h($c['name']) . '</option>'; }
        $b .= '</select></label><button type="submit">Show</button></form>';
    }
    if (!$coach) { page('Coach availability', $b . '<p>Choose a coach.</p>'); }
    $nets = club_nets((int) $club['id']);
    $have = week_availability((int) $club['id'], $monday)[(int) $coach['id']] ?? [];
    $b .= '<p class="muted">Tick the hours <strong>' . h($coach['name']) . '</strong> is available to coach. Only ticked hours can be booked by visitors.</p>'
        . '<p class="weeknav"><a href="/coach/availability?week=' . $monday->modify('-7 days')->format('Y-m-d') . ($manager ? '&coach=' . (int) $coach['id'] : '') . '">← Previous week</a> <strong>Week of ' . $monday->format('j M Y') . '</strong> <a href="/coach/availability?week=' . $monday->modify('+7 days')->format('Y-m-d') . ($manager ? '&coach=' . (int) $coach['id'] : '') . '">Next week →</a></p>';
    $minH = 24; $maxH = 0; $open = [];
    for ($i = 0; $i < 7; $i++) {
        $d = $monday->modify("+$i days"); $open[$i] = [];
        foreach ($nets as $n) { $hrs = hours_for((int) $n['id'], $d); if ($hrs) { $open[$i][] = $hrs; $minH = min($minH, $hrs[0]); $maxH = max($maxH, $hrs[1]); } }
    }
    $now = new DateTimeImmutable('now', london());
    $b .= '<form method="post">' . csrf_field() . '<input type="hidden" name="week" value="' . h($monday->format('Y-m-d')) . '"><input type="hidden" name="coach" value="' . (int) $coach['id'] . '">'
        . '<div class="scroll"><table class="diary"><thead><tr><th></th>';
    for ($i = 0; $i < 7; $i++) { $b .= '<th>' . $monday->modify("+$i days")->format('D j') . '</th>'; }
    $b .= '</tr></thead><tbody>';
    for ($h = $minH; $h < $maxH; $h++) {
        $b .= '<tr><th>' . sprintf('%02d:00', $h) . '</th>';
        for ($i = 0; $i < 7; $i++) {
            $d = $monday->modify("+$i days"); $isOpen = false;
            foreach ($open[$i] as $hr) { if ($h >= $hr[0] && $h < $hr[1]) { $isOpen = true; } }
            if (!$isOpen || $d->setTime($h, 0) <= $now) { $b .= '<td class="closed">–</td>'; continue; }
            $key = $d->format('Y-m-d') . ' ' . sprintf('%02d', $h);
            $b .= '<td class="' . (isset($have[$key]) ? 'free' : '') . '"><input type="checkbox" name="slot[]" value="' . $i . '-' . $h . '"' . (isset($have[$key]) ? ' checked' : '') . ' aria-label="' . h($d->format('D j') . ' ' . sprintf('%02d:00', $h)) . '"></td>';
        }
        $b .= '</tr>';
    }
    $b .= '</tbody></table></div><label>Also use this pattern for the next <select name="repeat" style="display:inline;width:auto"><option value="0">0 weeks (just this week)</option><option value="1">1 week</option><option value="3">3 weeks</option><option value="7">7 weeks</option><option value="11">11 weeks</option></select></label>'
        . '<button type="submit">Save availability</button></form>';
    page('Coach availability', $b);
}

if (preg_match('#^/club/([a-z0-9-]+)(/book|/release)?$#', $path, $m)) {
    $club = club_by_slug($m[1]);
    if (!$club) { http_response_code(404); page('Not found', '<p>We could not find that club.</p>'); }
    $sub = $m[2] ?? '';
    $nets = club_nets((int) $club['id']);
    $netId = (int) ($_REQUEST['net'] ?? ($nets[0]['id'] ?? 0));
    $net = null; foreach ($nets as $n) { if ((int) $n['id'] === $netId) { $net = $n; } }
    $staff = can_staff($user, $club);
    $self = '/club/' . $club['slug'];

    if ($sub === '/release' && $method === 'POST') {
        csrf_check();
        if (!$user) { redirect('/login'); }
        [$ok, $msg] = release_booking($user, $club, (int) ($_POST['booking_id'] ?? 0));
        flash($msg, !$ok); redirect($self . '?net=' . $netId . '&week=' . urlencode((string) ($_POST['week'] ?? '')));
    }

    if ($sub === '/book') {
        if (!$user) { redirect('/login'); }
        if (!$net || !$staff) { http_response_code(403); page('Not allowed', '<p>You cannot book at this club.</p>'); }
        $start = (string) ($_REQUEST['start'] ?? '');
        if ($method === 'POST') {
            csrf_check();
            [$ok, $msg] = staff_book($user, $club, $net, $start, $_POST);
            flash($msg, !$ok);
            redirect($ok ? $self . '?net=' . $netId . '&week=' . substr($start, 0, 10) : $self . '/book?net=' . $netId . '&start=' . urlencode($start));
        }
        $manager = can_manage($user, $club);
        $mine = own_coach($user, $club);
        $opts = $manager ? '<option value="member">Member</option><option value="coach">Coaching session</option><option value="nonmember">Non-member</option><option value="blocked">Block this hour</option>'
                         : '<option value="coach">Coaching session</option>';
        $co = '';
        $startLocal = DateTimeImmutable::createFromFormat('!Y-m-d\\TH', $start, london()) ?: new DateTimeImmutable('now', london());
        if ($manager) {
            [$freeC, $otherC] = coaches_for_hour((int) $club['id'], $startLocal);
            $opt = fn($c) => '<option value="' . (int) $c['id'] . '">' . h($c['name']) . ' (' . money((int) $c['hourly_price_pence']) . '/hr)</option>';
            $co = ($freeC ? '<optgroup label="Available this hour">' . implode('', array_map($opt, $freeC)) . '</optgroup>' : '')
                . ($otherC ? '<optgroup label="Not marked available (needs override)">' . implode('', array_map($opt, $otherC)) . '</optgroup>' : '');
        }
        $b = '<h1>Book ' . h($net['name']) . '</h1><p class="muted">' . h(DateTimeImmutable::createFromFormat('!Y-m-d\\TH', $start, london())->format('l j F, H:i')) . ' · ' . h($club['name']) . '</p>'
           . '<form method="post" class="card narrow">' . csrf_field() . '<input type="hidden" name="net" value="' . $netId . '"><input type="hidden" name="start" value="' . h($start) . '">'
           . '<label>Type<select name="type">' . $opts . '</select></label>'
           . ($manager ? '<label>Coach (for coaching sessions)<select name="coach_id">' . $co . '</select></label><label class="check"><input type="checkbox" name="override" value="1"> Override: book a coach who has not marked this hour as available</label>' : '<p class="muted">Booking as ' . h((string) ($mine['name'] ?? 'no coach record linked')) . '.</p>')
           . '<label>Name for the booking<input name="customer_name"></label>'
           . '<label>Email<input type="email" name="customer_email"></label><label>Phone<input name="customer_phone"></label>'
           . '<label class="check"><input type="checkbox" name="machine" value="1"> Bowling machine (coaching sessions)</label>'
           . '<fieldset><legend>Under 18?</legend><label class="check"><input type="checkbox" name="junior" value="1"> This booking is for an under-18</label>'
           . '<label>Child\'s first name<input name="child_first_name"></label><label>Child\'s age<input name="child_age" inputmode="numeric" size="3"></label>'
           . '<label class="check"><input type="checkbox" name="parent_confirmed" value="1"> A parent/guardian has agreed and will be present</label></fieldset>'
           . '<label>Note<input name="note" maxlength="500"></label><button type="submit">Confirm booking</button></form>';
        page('Book', $b);
    }

    // Club page with the week diary
    $monday = week_start($_GET['week'] ?? null);
    expire_stale();
    $b = '<div class="clubhead">' . ($club['crest_path'] ? '<img src="/assets/' . h($club['crest_path']) . '" alt="" width="56" height="58">' : '')
       . '<div><h1>' . h($club['name']) . '</h1><p class="muted">' . h($club['town']) . ', ' . h($club['county']) . ' ' . h((string) $club['postcode']) . '</p></div></div>';
    $coaches = club_coaches((int) $club['id']);
    if ($coaches) {
        $b .= '<h2>Coaches</h2><ul class="plain">';
        foreach ($coaches as $c) {
            $b .= '<li><strong>' . h($c['name']) . '</strong> · ' . h($c['qualification']) . ' · ' . money((int) $c['hourly_price_pence']) . '/hr'
                . ($c['dbs_checked'] ? ' · DBS checked ' . h(date('j M Y', strtotime((string) $c['dbs_checked_on']))) : '') . '</li>';
        }
        $b .= '</ul>';
    }
    if (!$net) { page($club['name'], $b . '<p>No nets are listed yet.</p>'); }
    $bk = week_bookings((int) $net['id'], $monday);
    $avail = week_availability((int) $club['id'], $monday); $cbusy = week_coach_busy((int) $club['id'], $monday);
    $nFree = function (string $key) use ($avail, $cbusy): int { $n = 0; foreach ($avail as $cid => $hrs) { if (isset($hrs[$key]) && !isset($cbusy[$cid][$key])) { $n++; } } return $n; };
    $prev = $monday->modify('-7 days')->format('Y-m-d'); $next = $monday->modify('+7 days')->format('Y-m-d');
    $b .= '<h2>' . h($net['name']) . ' diary</h2><p class="muted">Non-member ' . money((int) $net['nonmember_price_pence']) . '/hr'
        . ($net['machine_price_pence'] !== null ? ' · machine +' . money((int) $net['machine_price_pence']) . ' with a coach' : '') . '</p>'
        . '<p class="weeknav"><a href="' . $self . '?net=' . $netId . '&week=' . $prev . '">← Previous week</a> <strong>Week of ' . $monday->format('j M Y') . '</strong> <a href="' . $self . '?net=' . $netId . '&week=' . $next . '">Next week →</a></p>';
    $minH = 24; $maxH = 0; $open = [];
    for ($i = 0; $i < 7; $i++) {
        $d = $monday->modify("+$i days"); $open[$i] = hours_for((int) $net['id'], $d);
        if ($open[$i]) { $minH = min($minH, $open[$i][0]); $maxH = max($maxH, $open[$i][1]); }
    }
    $manager = can_manage($user, $club); $mine = own_coach($user, $club); $now = new DateTimeImmutable('now', london());
    $b .= '<div class="scroll"><table class="diary"><thead><tr><th></th>';
    for ($i = 0; $i < 7; $i++) { $b .= '<th>' . $monday->modify("+$i days")->format('D j') . '</th>'; }
    $b .= '</tr></thead><tbody>';
    for ($h = $minH; $h < $maxH; $h++) {
        $b .= '<tr><th>' . sprintf('%02d:00', $h) . '</th>';
        for ($i = 0; $i < 7; $i++) {
            $d = $monday->modify("+$i days"); $key = $d->format('Y-m-d') . ' ' . sprintf('%02d', $h);
            $isOpen = $open[$i] && $h >= $open[$i][0] && $h < $open[$i][1];
            $past = $d->setTime($h, 0) <= $now;
            if (!$isOpen) { $b .= '<td class="closed">–</td>'; continue; }
            if (isset($bk[$key])) {
                $x = $bk[$key];
                $label = $staff ? h(($x['booking_type'] === 'blocked' ? 'Blocked' : ($x['customer_name'] ?: ucfirst($x['booking_type'])))
                          . ($x['coach_name'] ? ' · ' . $x['coach_name'] : '')) : 'Booked';
                $canRel = $staff && ($manager || ($mine && (int) $x['coach_id'] === (int) $mine['id']));
                $b .= '<td class="taken">' . $label;
                if ($canRel && !$past) {
                    $b .= '<form method="post" action="' . $self . '/release">' . csrf_field() . '<input type="hidden" name="booking_id" value="' . (int) $x['id'] . '"><input type="hidden" name="net" value="' . $netId . '"><input type="hidden" name="week" value="' . $monday->format('Y-m-d') . '"><button class="link" type="submit">Release</button></form>';
                }
                $b .= '</td>';
            } elseif ($past) { $b .= '<td class="closed">–</td>'; }
            elseif ($staff) { $b .= '<td class="free"><a href="' . $self . '/book?net=' . $netId . '&start=' . $d->format('Y-m-d') . 'T' . sprintf('%02d', $h) . '">Book</a>' . ($nFree($key) ? '<br><small>' . $nFree($key) . ' coach' . ($nFree($key) > 1 ? 'es' : '') . '</small>' : '') . '</td>'; }
            else { $b .= '<td class="free">Free' . ($nFree($key) ? '<br><small>' . $nFree($key) . ' coach' . ($nFree($key) > 1 ? 'es' : '') . '</small>' : '') . '</td>'; }
        }
        $b .= '</tr>';
    }
    $b .= '</tbody></table></div>';
    if (!$staff) { $b .= '<p class="muted">Online booking and payment for visitors is coming soon. Until then, contact the club: ' . h((string) $club['phone']) . ' · ' . h((string) $club['email']) . '</p>'; }
    page($club['name'], $b);
}

http_response_code(404);
page('Not found', '<p>That page does not exist.</p>');
