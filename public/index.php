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


// One-time setup: only works while there are no users at all, then it disappears.
if ($path === '/setup') {
    $count = (int) q('SELECT COUNT(*) AS n FROM users')->fetch()['n'];
    if ($count > 0) { http_response_code(404); page('Not found', '<p>That page does not exist.</p>'); }
    if ($method === 'POST') {
        csrf_check();
        $email = trim((string) ($_POST['email'] ?? '')); $name = trim((string) ($_POST['name'] ?? '')); $pw = (string) ($_POST['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '' || strlen($pw) < 10) {
            flash('Enter a valid email, your name, and a password of at least 10 characters.', true); redirect('/setup');
        }
        q('INSERT INTO users (email, password_hash, name, role) VALUES (?,?,?,?)', [$email, password_hash($pw, PASSWORD_DEFAULT), $name, 'admin']);
        audit(null, 'first_admin_created', $email);
        flash('Admin login created. Please log in.'); redirect('/login');
    }
    page('Set up', '<h1>Set up PlayMoreCricket</h1><p class="muted">Create the first administrator login. This page stops working once it has been used.</p>'
        . '<form method="post" class="card narrow">' . csrf_field()
        . '<label>Your name<input name="name" required></label><label>Email<input type="email" name="email" required autocomplete="username"></label>'
        . '<label>Password (at least 10 characters)<input type="password" name="password" required minlength="10" autocomplete="new-password"></label>'
        . '<button type="submit">Create admin login</button></form>');
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

if (in_array($path, ['/admin', '/admin/commission', '/admin/migrate', '/admin/users'], true)) {
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
    if ($path === '/admin/migrate' && $method === 'POST') {
        csrf_check();
        [$done, $err] = run_migrations();
        audit((int) $user['id'], 'migrations_run', implode(',', $done) . ($err ? ' ERROR ' . $err : ''));
        flash($err ? 'Update stopped: ' . $err : ($done ? 'Database updated: ' . implode(', ', $done) . '.' : 'Nothing to update. The database is up to date.'), (bool) $err);
        redirect('/admin');
    }
    if ($path === '/admin/users' && $method === 'POST') {
        csrf_check();
        $email = trim((string) ($_POST['email'] ?? '')); $name = trim((string) ($_POST['name'] ?? '')); $pw = (string) ($_POST['password'] ?? '');
        $role = (string) ($_POST['role'] ?? ''); $clubId = (int) ($_POST['club_id'] ?? 0); $coachId = (int) ($_POST['coach_id'] ?? 0);
        $club = $clubId ? q('SELECT id FROM clubs WHERE id = ?', [$clubId])->fetch() : null;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '' || strlen($pw) < 10 || !in_array($role, ['admin', 'club_contact', 'coach'], true) || ($role !== 'admin' && !$club)) {
            flash('Enter a valid email, a name, a role, a club (for non-admins) and a password of at least 10 characters.', true); redirect('/admin');
        }
        if (q('SELECT 1 FROM users WHERE email = ?', [$email])->fetch()) { flash('That email already has a login.', true); redirect('/admin'); }
        q('INSERT INTO users (email, password_hash, name, role, club_id) VALUES (?,?,?,?,?)', [$email, password_hash($pw, PASSWORD_DEFAULT), $name, $role, $role === 'admin' ? null : $clubId]);
        $newId = (int) q('SELECT id FROM users WHERE email = ?', [$email])->fetch()['id'];
        if ($role === 'coach' && $coachId) { q('UPDATE coaches SET user_id = ? WHERE id = ? AND club_id = ? AND user_id IS NULL', [$newId, $coachId, $clubId]); }
        audit((int) $user['id'], 'user_created', $email . ' ' . $role);
        flash('Login created for ' . $name . '.'); redirect('/admin');
    }
    $pending = pending_migrations();
    $b = '<h1>Admin</h1><h2>Database updates</h2><form method="post" action="/admin/migrate" class="card row">' . csrf_field()
       . '<span>' . ($pending ? '<strong>' . count($pending) . ' update(s) waiting:</strong> ' . h(implode(', ', array_keys($pending))) : 'The database is up to date.') . '</span>'
       . '<button type="submit">Apply updates</button></form>';
    $clubsAll = q('SELECT id, name FROM clubs ORDER BY name')->fetchAll();
    $coachesFree = q('SELECT id, club_id, name FROM coaches WHERE user_id IS NULL AND active ORDER BY name')->fetchAll();
    $opts = ''; foreach ($clubsAll as $c) { $opts .= '<option value="' . (int) $c['id'] . '">' . h($c['name']) . '</option>'; }
    $copts = '<option value="0">(none)</option>'; foreach ($coachesFree as $c) { $copts .= '<option value="' . (int) $c['id'] . '">' . h($c['name']) . '</option>'; }
    $b .= '<h2>Logins</h2><ul class="plain">';
    foreach (q('SELECT u.name, u.email, u.role, c.name AS club FROM users u LEFT JOIN clubs c ON c.id = u.club_id ORDER BY u.role, u.name')->fetchAll() as $u2) {
        $b .= '<li><strong>' . h($u2['name']) . '</strong> · ' . h($u2['email']) . ' · ' . h($u2['role']) . ($u2['club'] ? ' · ' . h($u2['club']) : '') . '</li>';
    }
    $b .= '</ul><form method="post" action="/admin/users" class="card narrow"><strong>Add a login</strong>' . csrf_field()
        . '<label>Name<input name="name" required></label><label>Email<input type="email" name="email" required autocomplete="off"></label>'
        . '<label>Role<select name="role"><option value="club_contact">Club contact</option><option value="coach">Coach</option><option value="admin">Administrator</option></select></label>'
        . '<label>Club<select name="club_id">' . $opts . '</select></label>'
        . '<label>Coach record to link (coaches only)<select name="coach_id">' . $copts . '</select></label>'
        . '<label>Password (at least 10 characters)<input type="password" name="password" required minlength="10" autocomplete="new-password"></label>'
        . '<button type="submit">Create login</button></form><h2>Clubs and commission</h2>';
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


// ---- Stripe webhook (must read the raw body; no login, no CSRF: the signature is the proof)
if ($path === '/stripe/webhook' && $method !== 'POST') {
    http_response_code(405); header('Allow: POST'); header('Content-Type: text/plain; charset=utf-8');
    echo 'This address receives messages from Stripe (POST only). It is working if you see this.'; exit;
}
if ($path === '/stripe/webhook' && $method === 'POST') {
    $payload = (string) file_get_contents('php://input');
    $event = stripe_verify_webhook($payload, (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''), (string) $GLOBALS['cfg']['stripe_webhook_secret']);
    if (!$event || $GLOBALS['cfg']['stripe_webhook_secret'] === '') { http_response_code(400); echo 'invalid'; exit; }
    echo handle_stripe_event($event); exit;
}

// ---- Connect a club to Stripe (club contact for their own club, or admin)
if ($path === '/stripe/connect' && $method === 'POST') {
    csrf_check(); $u = require_login();
    $club = q('SELECT * FROM clubs WHERE id = ?', [(int) ($_POST['club_id'] ?? 0)])->fetch();
    if (!$club || !can_manage($u, $club)) { http_response_code(403); page('Not allowed', '<p>You cannot manage payments for that club.</p>'); }
    if (!stripe_configured()) { flash('Stripe is not set up on the server yet.', true); redirect('/club/' . $club['slug']); }
    try {
        if (empty($club['stripe_account_id'])) {
            $acct = stripe_create_club_account($club);
            q('UPDATE clubs SET stripe_account_id = ? WHERE id = ?', [$acct['id'], $club['id']]);
            $club['stripe_account_id'] = $acct['id'];
        }
        $GLOBALS['fake_club_slug'] = $club['slug'];
        $url = stripe_account_link($club['stripe_account_id'], $GLOBALS['cfg']['app_url'] . '/stripe/return?club=' . $club['slug'], $GLOBALS['cfg']['app_url'] . '/stripe/return?club=' . $club['slug'] . '&refresh=1');
    } catch (Throwable $e) { error_log('PMC connect failed: ' . $e->getMessage()); flash('Could not reach Stripe. Please try again.', true); redirect('/club/' . $club['slug']); }
    audit((int) $u['id'], 'stripe_connect_started', $club['slug']);
    header('Location: ' . $url, true, 303); exit;
}
if ($path === '/stripe/return') {
    $u = require_login();
    $club = club_by_slug((string) ($_GET['club'] ?? ''));
    if (!$club || !can_manage($u, $club) || empty($club['stripe_account_id'])) { redirect('/'); }
    try {
        $a = stripe_retrieve_account($club['stripe_account_id']);
        q('UPDATE clubs SET stripe_charges_enabled = ? WHERE id = ?', [!empty($a['charges_enabled']) ? 1 : 0, $club['id']]);
        flash(!empty($a['charges_enabled']) ? 'Payments are switched on. Visitors can now book and pay online.' : 'Stripe sign-up is not finished yet. Use "Set up payments" to carry on where you left off.', empty($a['charges_enabled']));
    } catch (Throwable $e) { error_log('PMC return failed: ' . $e->getMessage()); flash('Could not check with Stripe just now. Please refresh in a minute.', true); }
    redirect('/club/' . $club['slug']);
}

// ---- Customer's booking page (link from the confirmation email; protected by a signed token)
if (preg_match('#^/booking/(PMC-[A-Z0-9]{6})(/cancel)?$#', $path, $bm)) {
    $b = booking_by_ref($bm[1]);
    $t = (string) ($_REQUEST['t'] ?? '');
    if (!$b || !hash_equals(booking_token($b['reference']), $t)) { http_response_code(404); page('Not found', '<p>We could not find that booking.</p>'); }
    if ($bm[2] ?? '') {
        if ($method !== 'POST') { redirect(booking_link($b['reference'])); }
        csrf_check(); [$ok, $msg] = customer_cancel($b); flash($msg, !$ok); redirect(booking_link($b['reference']));
    }
    expire_stale(); $b = booking_by_ref($b['reference']);
    $labels = ['confirmed' => 'Confirmed and paid', 'pending_payment' => 'Waiting for payment', 'refunded' => 'Cancelled and refunded', 'cancelled' => 'Cancelled', 'expired' => 'Expired (not paid)'];
    $body = '<h1>Your booking</h1><div class="card"><p><strong>' . h($labels[$b['status']] ?? $b['status']) . '</strong> · ' . h($b['reference']) . '</p><p>' . h(describe_booking($b)) . '</p>'
          . '<p>Price: ' . money((int) $b['price_pence']) . '</p>' . ($b['is_junior'] ? '<p class="muted">Under-18 booking for ' . h((string) $b['child_first_name']) . ', aged ' . (int) $b['child_age'] . '. A parent/guardian will be present.</p>' : '') . '</div>';
    if ($b['status'] === 'pending_payment') { $body .= '<p class="muted">If you have just paid, this page updates within a minute or so. Refresh to check.</p>'; }
    if ($b['status'] === 'confirmed') {
        $ok24 = refund_allowed(new DateTimeImmutable($b['starts_at'], new DateTimeZone('UTC')));
        $body .= $ok24 ? '<form method="post" action="/booking/' . h($b['reference']) . '/cancel" class="card">' . csrf_field() . '<input type="hidden" name="t" value="' . h($t) . '"><p>You can cancel for a full refund up to 24 hours before the start.</p><button type="submit">Cancel and refund</button></form>'
                       : '<p class="muted">This session starts in less than 24 hours, so the full fee applies. Contact the club if there is a problem: ' . h((string) $b['club_email']) . '</p>';
    }
    page('Your booking', $body);
}

if (preg_match('#^/club/([a-z0-9-]+)(/book|/release|/reserve)?$#', $path, $m)) {
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

    if ($sub === '/reserve') {
        if (!$net) { http_response_code(404); page('Not found', '<p>We could not find that net.</p>'); }
        $start = (string) ($_REQUEST['start'] ?? '');
        if (!online_booking_enabled($club)) { flash('Online booking is not open for this club yet. Please contact the club.', true); redirect($self); }
        if ($method === 'POST') {
            csrf_check();
            [$ok, $msg, $url] = visitor_reserve($club, $net, $start, $_POST);
            if ($ok) { header('Location: ' . $url, true, 303); exit; }
            flash($msg, true);
            $_SESSION['reserve_old'] = array_intersect_key($_POST, array_flip(['kind', 'coach_id', 'machine', 'customer_name', 'customer_email', 'customer_phone', 'junior', 'child_first_name', 'child_age']));
            redirect($self . '/reserve?net=' . $netId . '&start=' . urlencode($start));
        }
        $when = DateTimeImmutable::createFromFormat('!Y-m-d\TH', $start, london());
        if (!$when) { redirect($self); }
        $old = $_SESSION['reserve_old'] ?? []; unset($_SESSION['reserve_old']);
        $v = fn(string $k) => h((string) ($old[$k] ?? ''));
        [$freeC] = coaches_for_hour((int) $club['id'], $when);
        $co = ''; foreach ($freeC as $c) { $co .= '<option value="' . (int) $c['id'] . '"' . ((int) ($old['coach_id'] ?? 0) === (int) $c['id'] ? ' selected' : '') . '>' . h($c['name']) . ' · ' . h($c['qualification']) . ' · ' . money((int) $c['hourly_price_pence']) . '</option>'; }
        $b = '<h1>Book ' . h($net['name']) . '</h1><p class="muted">' . h($when->format('l j F Y, H:i')) . ' · one hour · ' . h($club['name']) . '</p>'
           . '<form method="post" class="card narrow">' . csrf_field() . '<input type="hidden" name="net" value="' . $netId . '"><input type="hidden" name="start" value="' . h($start) . '">'
           . '<fieldset><legend>What would you like?</legend>'
           . '<label class="check"><input type="radio" name="kind" value="net"' . (($old['kind'] ?? 'net') === 'net' ? ' checked' : '') . '> Net hire only · ' . money((int) $net['nonmember_price_pence']) . '</label>'
           . ($freeC ? '<label class="check"><input type="radio" name="kind" value="coach"' . (($old['kind'] ?? '') === 'coach' ? ' checked' : '') . '> Coaching session (net included)</label>'
                . '<label>Coach<select name="coach_id">' . $co . '</select></label>'
                . ($net['machine_price_pence'] !== null ? '<label class="check"><input type="checkbox" name="machine" value="1"' . (!empty($old['machine']) ? ' checked' : '') . '> Add the bowling machine (+' . money((int) $net['machine_price_pence']) . ', coaching sessions only)</label>' : '')
                : '<p class="muted">No coach is available at this time.</p>')
           . '</fieldset>'
           . '<label>Your name<input name="customer_name" value="' . $v('customer_name') . '" required></label>'
           . '<label>Email<input type="email" name="customer_email" value="' . $v('customer_email') . '" required></label>'
           . '<label>Phone<input name="customer_phone" value="' . $v('customer_phone') . '" inputmode="tel" required></label>'
           . '<fieldset><legend>Under 18?</legend><label class="check"><input type="checkbox" name="junior" value="1"' . (!empty($old['junior']) ? ' checked' : '') . '> This booking is for someone under 18</label>'
           . '<label>Child\'s first name<input name="child_first_name" value="' . $v('child_first_name') . '"></label><label>Child\'s age<input name="child_age" value="' . $v('child_age') . '" inputmode="numeric" size="3"></label>'
           . '<label class="check"><input type="checkbox" name="parent_confirmed" value="1"> I am the parent/guardian (or have their permission), and a parent/guardian will be present</label></fieldset>'
           . '<label class="check"><input type="checkbox" name="accept_terms" value="1" required> I accept the booking terms: cancel more than 24 hours before the start for a full refund; within 24 hours the full fee applies. We use your details only to manage your booking.</label>'
           . '<button type="submit">Continue to payment</button></form><p class="muted">Payment is taken securely by Stripe. The hour is held for 30 minutes while you pay.</p>';
        page('Book', $b);
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
        $b .= '<h2>Coaches</h2><ul class="coaches">';
        foreach ($coaches as $c) {
            $yr = ($c['dbs_checked'] && $c['dbs_checked_on']) ? date('Y', strtotime((string) $c['dbs_checked_on'])) : '';
            $b .= '<li><div class="coach-main"><strong>' . h($c['name']) . '</strong>'
                . ($yr !== '' ? '<span class="dbs-right"><span class="tick" aria-hidden="true">✓</span> DBS checked ' . h($yr) . '</span>' : '') . '</div>'
                . '<div class="muted">' . h($c['qualification']) . ' · ' . money((int) $c['hourly_price_pence']) . '/hr</div></li>';
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
    $manager = can_manage($user, $club); $mine = own_coach($user, $club); $now = new DateTimeImmutable('now', london()); $online = online_booking_enabled($club);
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
                          . ($x['coach_name'] ? ' · ' . $x['coach_name'] : '')) : 'BOOKED';
                $canRel = $staff && ($manager || ($mine && (int) $x['coach_id'] === (int) $mine['id']));
                $b .= '<td class="taken">' . $label;
                if ($canRel && !$past) {
                    $b .= '<form method="post" action="' . $self . '/release">' . csrf_field() . '<input type="hidden" name="booking_id" value="' . (int) $x['id'] . '"><input type="hidden" name="net" value="' . $netId . '"><input type="hidden" name="week" value="' . $monday->format('Y-m-d') . '"><button class="link" type="submit">Release</button></form>';
                }
                $b .= '</td>';
            } elseif ($past) { $b .= '<td class="closed">–</td>'; }
            elseif ($staff) { $b .= '<td class="free"><a href="' . $self . '/book?net=' . $netId . '&start=' . $d->format('Y-m-d') . 'T' . sprintf('%02d', $h) . '">Book</a>' . ($nFree($key) ? '<br><small>' . $nFree($key) . ' coach' . ($nFree($key) > 1 ? 'es' : '') . '</small>' : '') . '</td>'; }
            elseif ($online) { $b .= '<td class="free"><a href="' . $self . '/reserve?net=' . $netId . '&start=' . $d->format('Y-m-d') . 'T' . sprintf('%02d', $h) . '">Book</a>' . ($nFree($key) ? '<br><small>' . $nFree($key) . ' coach' . ($nFree($key) > 1 ? 'es' : '') . '</small>' : '') . '</td>'; }
            else { $b .= '<td class="free">Free' . ($nFree($key) ? '<br><small>' . $nFree($key) . ' coach' . ($nFree($key) > 1 ? 'es' : '') . '</small>' : '') . '</td>'; }
        }
        $b .= '</tr>';
    }
    $b .= '</tbody></table></div>';
    // Phone-friendly day list (shown instead of the week table on narrow screens)
    $selDay = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($_GET['day'] ?? ''), london());
    if (!$selDay || $selDay < $monday || $selDay >= $monday->modify('+7 days')) { $t0 = new DateTimeImmutable('today', london()); $selDay = ($t0 >= $monday && $t0 < $monday->modify('+7 days')) ? $t0 : $monday; }
    $b .= '<div class="daylist"><p class="daytabs">';
    for ($i = 0; $i < 7; $i++) {
        $dd = $monday->modify("+$i days");
        $b .= '<a href="' . $self . '?net=' . $netId . '&week=' . $monday->format('Y-m-d') . '&day=' . $dd->format('Y-m-d') . '"' . ($dd->format('Y-m-d') === $selDay->format('Y-m-d') ? ' class="on" aria-current="date"' : '') . '>' . $dd->format('D j') . '</a>';
    }
    $b .= '</p><h3>' . h($selDay->format('l j F')) . '</h3><ul class="plain slots">';
    $si = (int) $selDay->format('N') - 1; $any = false;
    if ($open[$si]) {
        for ($h = $open[$si][0]; $h < $open[$si][1]; $h++) {
            $key = $selDay->format('Y-m-d') . ' ' . sprintf('%02d', $h); $past = $selDay->setTime($h, 0) <= $now; if ($past) { continue; } $any = true;
            $lab = sprintf('%02d:00', $h);
            if (isset($bk[$key])) { $x = $bk[$key]; $b .= '<li class="taken"><strong>' . $lab . '</strong> ' . ($staff ? h(($x['booking_type'] === 'blocked' ? 'Blocked' : ($x['customer_name'] ?: ucfirst($x['booking_type']))) . ($x['coach_name'] ? ' · ' . $x['coach_name'] : '')) : 'BOOKED') . '</li>'; }
            elseif ($staff || $online) { $b .= '<li class="free"><strong>' . $lab . '</strong> <a href="' . $self . ($staff ? '/book' : '/reserve') . '?net=' . $netId . '&start=' . $selDay->format('Y-m-d') . 'T' . sprintf('%02d', $h) . '">Book</a>' . ($nFree($key) ? ' <small>' . $nFree($key) . ' coach' . ($nFree($key) > 1 ? 'es' : '') . '</small>' : '') . '</li>'; }
            else { $b .= '<li class="free"><strong>' . $lab . '</strong> Free' . ($nFree($key) ? ' <small>' . $nFree($key) . ' coach' . ($nFree($key) > 1 ? 'es' : '') . '</small>' : '') . '</li>'; }
        }
    }
    if (!$any) { $b .= '<li class="muted">Nothing available on this day.</li>'; }
    $b .= '</ul></div>';
    if (!$staff && $online) { $b .= '<p class="muted">Pick a free hour to book and pay online. Questions? ' . h((string) $club['phone']) . ' · ' . h((string) $club['email']) . '</p>'; }
    if (!$staff && !$online) { $b .= '<p class="muted">Online booking and payment for visitors is coming soon. Until then, contact the club: ' . h((string) $club['phone']) . ' · ' . h((string) $club['email']) . '</p>'; }
    if ($manager) {
        $state = empty($club['stripe_account_id']) ? 'Not set up yet' : ((int) $club['stripe_charges_enabled'] === 1 ? 'Payments are on' : 'Sign-up started, not finished');
        $b .= '<h2>Online payments</h2><form method="post" action="/stripe/connect" class="card row">' . csrf_field() . '<input type="hidden" name="club_id" value="' . (int) $club['id'] . '">'
            . '<span><strong>' . h($state) . '</strong><br><span class="muted">Customers pay the club directly through Stripe. Commission to PlayMoreCricket: ' . h(rtrim(rtrim((string) $club['commission_pct'], '0'), '.')) . '%.</span></span>'
            . ((int) $club['stripe_charges_enabled'] === 1 ? '' : '<button type="submit">' . (empty($club['stripe_account_id']) ? 'Set up payments' : 'Continue Stripe sign-up') . '</button>') . '</form>';
    }
    page($club['name'], $b);
}

http_response_code(404);
page('Not found', '<p>That page does not exist.</p>');
