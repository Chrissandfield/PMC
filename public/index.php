<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/') ?: '/';
$user = current_user();

// Local `php -S` serves real files itself; this just lets the router script fall through.
if (PHP_SAPI === 'cli-server' && $path !== '/' && is_file(__DIR__ . $path)) { return false; }

if ($path === '/' && $method === 'GET') {
    $clubs = q("SELECT id, slug, name, town, county, postcode, lat, lng, sponsored_partner, crest_path, EXISTS(SELECT 1 FROM club_crests k WHERE k.club_id = clubs.id) AS has_crest FROM clubs WHERE status = 'active' OR sponsored_partner ORDER BY name")->fetchAll();
    $crest = fn(array $c, int $w) => $c['crest_path'] ? '<img src="/assets/' . h($c['crest_path']) . '" alt="" width="' . $w . '">' : ($c['has_crest'] ? '<img src="/crest/' . h($c['slug']) . '" alt="" width="' . $w . '">' : '');
    $partner = null; foreach ($clubs as $c) { if ($c['sponsored_partner']) { $partner = $c; break; } }
    $b = '<section class="hero"><h1>Book cricket nets and coaches near you</h1><p>Find a net by the hour, add a coach for a session.</p><div class="crease"></div>'
       . ($partner ? '<a class="partner" href="/club/' . h($partner['slug']) . '">' . $crest($partner, 52) . '<div><span>Sponsored partner</span><strong>' . h($partner['name']) . '</strong></div></a>' : '')
       . '</section>';
    $pcIn = trim((string) ($_GET['pc'] ?? '')); $within = (string) ($_GET['within'] ?? ''); $geoMsg = null; $searched = false;
    if ($pcIn !== '') {
        $searched = true;
        [$clubs, $geoMsg] = clubs_near($clubs, $pcIn, in_array($within, ['5', '10', '25', '50'], true) ? (float) $within : null);
    }
    $b .= '<h2>Find a club</h2><form method="get" action="/" class="card"><div class="row"><label>Your postcode<input name="pc" value="' . h($pcIn) . '" placeholder="e.g. TN11 8XX" autocomplete="postal-code" maxlength="10"></label>'
        . '<label>Within<select name="within"><option value="">Any distance</option>' . implode('', array_map(fn($m) => '<option value="' . $m . '"' . ($within === (string) $m ? ' selected' : '') . '>' . $m . ' miles</option>', [5, 10, 25, 50])) . '</select></label>'
        . '<button type="submit">Find near me</button></div>'
        . '<p class="muted">We use your postcode only to work out distances. It is looked up with postcodes.io and not stored.</p></form>'
        . ($geoMsg ? '<div class="notice err" role="status">' . h($geoMsg) . '</div>' : '')
        . '<div class="card"><label>Town or club<input type="search" id="club-filter" placeholder="e.g. Penshurst" autocomplete="off"></label></div><ul class="clubs" id="club-list">';
    foreach ($clubs as $c) {
        $b .= '<li data-find="' . h(strtolower($c['name'] . ' ' . $c['town'] . ' ' . $c['county'] . ' ' . (string) $c['postcode'])) . '"><a href="/club/' . h($c['slug']) . '">' . $crest($c, 44)
            . '<span><strong>' . h($c['name']) . '</strong><br>' . h($c['town']) . ', ' . h($c['county']) . ' ' . h((string) $c['postcode']) . '</span>'
            . (isset($c['miles']) ? '<em class="tag">' . number_format($c['miles'], 1) . ' miles</em>' : ($c['sponsored_partner'] ? '<em class="tag">Sponsored partner</em>' : '')) . '</a></li>';
    }
    page('Find a net', $b . '</ul>' . ($searched && !$geoMsg && !$clubs ? '<p class="muted">No clubs within that distance yet. More clubs are joining soon.</p>' : '') . '<p class="muted" id="club-none" hidden>No clubs match. More clubs are joining soon.</p><p class="muted">Is your club missing? <a href="/enrol">Enrol your club</a>.</p>');
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
        if (login_locked((string) ($_POST['email'] ?? ''))) { flash('Too many failed attempts. Please wait ' . LOGIN_WINDOW_MINUTES . ' minutes and try again, or use Forgot your password.', true); redirect('/login'); }
        if (login((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''))) {
            $u = q('SELECT role, club_id FROM users WHERE id = ?', [$_SESSION['uid']])->fetch();
            $club = $u['club_id'] ? q('SELECT slug FROM clubs WHERE id = ?', [$u['club_id']])->fetch() : null;
            redirect($u['role'] === 'admin' ? '/admin' : ($u['role'] === 'coach' ? '/coach/availability' : ($club ? '/club/' . $club['slug'] : '/')));
        }
        flash('Email or password not recognised.', true);
        redirect('/login');
    }
    page('Log in', '<h1>Log in</h1><form method="post" class="card narrow">' . csrf_field()
        . '<label>Email<input type="email" name="email" required autocomplete="username"></label>'
        . '<label>Password<input type="password" name="password" required autocomplete="current-password"></label>'
        . '<button type="submit">Log in</button></form><p class="muted"><a href="/forgot">Forgot your password?</a></p>');
}

if ($path === '/forgot') {
    if ($method === 'POST') {
        csrf_check();
        $em = trim((string) ($_POST['email'] ?? ''));
        $u = filter_var($em, FILTER_VALIDATE_EMAIL) ? q('SELECT id, email, name FROM users WHERE lower(email) = lower(?)', [$em])->fetch() : null;
        if ($u) {
            $recent = (int) q('SELECT COUNT(*) AS n FROM password_tokens WHERE user_id = ? AND created_at >= ?', [$u['id'], utc_string((new DateTimeImmutable('now', london()))->modify('-1 hour'))])->fetch()['n'];
            if ($recent < 3) { send_password_link($u, false); audit((int) $u['id'], 'password_reset_requested'); }
        }
        flash('If that email has a login, we have sent it a link to choose a new password. It works for ' . PW_RESET_HOURS . ' hours.');
        redirect('/forgot');
    }
    page('Forgot your password', '<h1>Forgot your password?</h1><p class="muted">Enter the email you log in with and we will send you a private link to choose a new password.</p>'
        . '<form method="post" class="card narrow">' . csrf_field() . '<label>Email<input type="email" name="email" required autocomplete="username"></label><button type="submit">Send me a link</button></form>');
}

if (preg_match('#^/set-password/([0-9a-f]{48})$#', $path, $pm)) {
    $tk = password_token_lookup($pm[1]);
    if (!$tk) { http_response_code(410); page('Link not valid', '<h1>This link is not valid</h1><p>It may have expired or already been used. <a href="/forgot">Get a new link</a>.</p>'); }
    if ($method === 'POST') {
        csrf_check();
        $pw = (string) ($_POST['password'] ?? ''); $pw2 = (string) ($_POST['password2'] ?? '');
        if (strlen($pw) < PW_MIN) { flash('Please use at least ' . PW_MIN . ' characters.', true); redirect($path); }
        if ($pw !== $pw2) { flash('The two passwords do not match.', true); redirect($path); }
        $tu = q('SELECT id, email FROM users WHERE id = ?', [$tk['user_id']])->fetch();
        if (!$tu || strcasecmp($pw, (string) $tu['email']) === 0) { flash('Please choose a password that is not your email address.', true); redirect($path); }
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $tk['user_id']]);
        q('UPDATE password_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [utc_string(new DateTimeImmutable('now', london())), $tk['user_id']]);
        q('DELETE FROM login_failures WHERE lower(email) = lower(?)', [$tu['email']]);
        audit((int) $tk['user_id'], 'password_set', $tk['purpose']);
        flash('Your password is saved. Please log in.'); redirect('/login');
    }
    page('Choose a password', '<h1>Choose a password</h1><form method="post" class="card narrow">' . csrf_field()
        . '<label>New password (at least ' . PW_MIN . ' characters)<input type="password" name="password" required minlength="' . PW_MIN . '" autocomplete="new-password"></label>'
        . '<label>Type it again<input type="password" name="password2" required minlength="' . PW_MIN . '" autocomplete="new-password"></label>'
        . '<button type="submit">Save password</button></form>');
}

if ($path === '/account/password') {
    $u = require_login();
    if ($method === 'POST') {
        csrf_check();
        $row = q('SELECT password_hash, email FROM users WHERE id = ?', [$u['id']])->fetch();
        $cur = (string) ($_POST['current'] ?? ''); $pw = (string) ($_POST['password'] ?? ''); $pw2 = (string) ($_POST['password2'] ?? '');
        if (login_locked((string) $row['email'])) { flash('Too many failed attempts. Please wait ' . LOGIN_WINDOW_MINUTES . ' minutes.', true); redirect('/account/password'); }
        if (!password_verify($cur, (string) $row['password_hash'])) {
            q('INSERT INTO login_failures (email, ip, attempted_at) VALUES (?,?,?)', [$row['email'], client_ip(), utc_string(new DateTimeImmutable('now', london()))]);
            flash('Your current password is not right.', true); redirect('/account/password');
        }
        if (strlen($pw) < PW_MIN) { flash('Please use at least ' . PW_MIN . ' characters.', true); redirect('/account/password'); }
        if ($pw !== $pw2) { flash('The two new passwords do not match.', true); redirect('/account/password'); }
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $u['id']]);
        q('UPDATE password_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [utc_string(new DateTimeImmutable('now', london())), $u['id']]);
        audit((int) $u['id'], 'password_changed');
        flash('Your password has been changed.'); redirect('/account/password');
    }
    page('Change password', '<h1>Change password</h1><form method="post" class="card narrow">' . csrf_field()
        . '<label>Current password<input type="password" name="current" required autocomplete="current-password"></label>'
        . '<label>New password (at least ' . PW_MIN . ' characters)<input type="password" name="password" required minlength="' . PW_MIN . '" autocomplete="new-password"></label>'
        . '<label>Type the new one again<input type="password" name="password2" required minlength="' . PW_MIN . '" autocomplete="new-password"></label>'
        . '<button type="submit">Change password</button></form>');
}

if ($path === '/logout' && $method === 'POST') { csrf_check(); logout(); redirect('/'); }

if (in_array($path, ['/admin', '/admin/commission', '/admin/migrate', '/admin/users', '/admin/users/edit', '/admin/users/update', '/admin/users/link', '/admin/stripe/disconnect', '/admin/applications/approve', '/admin/applications/resend', '/admin/applications/link', '/admin/applications/decline', '/admin/applications/confirm', '/admin/coach/dbs', '/admin/policy'], true)) {
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
    if ($path === '/admin/stripe/disconnect' && $method === 'POST') {
        csrf_check();
        $club = q('SELECT * FROM clubs WHERE id = ?', [(int) ($_POST['club_id'] ?? 0)])->fetch();
        if (!$club) { flash('That club does not exist.', true); redirect('/admin'); }
        if (empty($_POST['confirm'])) { flash('Tick the box to confirm you want to disconnect payments.', true); redirect('/admin'); }
        $open = (int) q("SELECT COUNT(*) AS n FROM bookings b JOIN nets n ON n.id = b.net_id WHERE n.club_id = ? AND b.stripe_checkout_session_id IS NOT NULL AND b.status IN ('pending_payment','confirmed') AND b.starts_at > ?", [$club['id'], utc_string(new DateTimeImmutable('now', london()))])->fetch()['n'];
        if ($open > 0) { flash('Not disconnected: ' . $open . ' upcoming booking(s) were paid through this Stripe account. Cancel or refund them first.', true); redirect('/admin'); }
        q('UPDATE clubs SET stripe_account_id = NULL, stripe_charges_enabled = ? WHERE id = ?', [0, $club['id']]);
        audit((int) $user['id'], 'stripe_disconnected', $club['slug'] . ' was ' . (string) $club['stripe_account_id']);
        flash('Payments disconnected for ' . $club['name'] . '. The club can now connect a new Stripe account.'); redirect('/admin');
    }
    if ($path === '/admin/applications/approve' && $method === 'POST') {
        csrf_check();
        $club = q('SELECT * FROM clubs WHERE id = ?', [(int) ($_POST['club_id'] ?? 0)])->fetch();
        if (!$club || $club['status'] !== 'pending' || !empty($club['declined_at'])) { flash('That application is not waiting for approval.', true); redirect('/admin'); }
        if (empty($club['safeguarding_confirmed_at'])) { flash('Not approved: the safeguarding officer has not yet confirmed the club\'s safeguarding policy.', true); redirect('/admin'); }
        $un = (int) q('SELECT COUNT(*) AS n FROM coaches WHERE club_id = ? AND NOT dbs_checked', [$club['id']])->fetch()['n'];
        if ($un > 0 && empty($_POST['allow_unconfirmed'])) { flash($un . ' coach(es) have no confirmed DBS check. Tick the box to approve anyway (they will show with no DBS tick), or wait.', true); redirect('/admin'); }
        q('UPDATE clubs SET status = ?, approved_at = ?, approved_by_user_id = ? WHERE id = ?', ['active', now_utc(), $user['id'], $club['id']]);
        audit((int) $user['id'], 'club_approved', $club['slug']);
        if (filter_var((string) $club['key_contact_detail'], FILTER_VALIDATE_EMAIL)) {
            send_email((string) $club['key_contact_detail'], $club['name'] . ' is now live on PlayMoreCricket', "Hello " . $club['key_contact_name'] . ",\n\nYour club is now live: " . $GLOBALS['cfg']['app_url'] . '/club/' . $club['slug']
                . "\n\nWhat happens next:\n1. Online payments: so customers can book and pay online, your club connects its own Stripe account. Log in, open your club page and press Set up payments. Until then your page shows your diary but visitors cannot pay online.\n"
                . "   Have these ready (Stripe asks for them, about 10 to 15 minutes):\n" . stripe_checklist_text() . "\n"
                . "2. Logins: you and each of your coaches will get a separate email with a private link to choose a password. Coaches use their login to set the hours they are available.\n"
                . "3. Check your club page and tell us if anything is wrong: " . $GLOBALS['cfg']['app_url'] . '/club/' . $club['slug'] . "\n\nQuestions? Reply to hello@playmorecricket.com.\n\nPlayMoreCricket");
        }
        [$madeLogins, $loginNotes] = create_club_logins($club);
        flash($club['name'] . ' is now live. ' . $madeLogins . ' login(s) created and emailed a set-up link.' . ($loginNotes ? ' Note: ' . implode('; ', $loginNotes) . '.' : '')); redirect('/admin');
    }
    if ($path === '/admin/applications/resend' && $method === 'POST') {
        csrf_check();
        $club = q('SELECT * FROM clubs WHERE id = ?', [(int) ($_POST['club_id'] ?? 0)])->fetch();
        if (!$club || $club['status'] !== 'pending') { flash('That application is not pending.', true); redirect('/admin'); }
        $others = q('SELECT name FROM coaches WHERE club_id = ? AND NOT is_safeguarding_officer AND active', [$club['id']])->fetchAll(PDO::FETCH_COLUMN);
        email_officer_link($club, 'officer', (string) $club['safeguarding_name'], (string) $club['safeguarding_contact'], make_token((int) $club['id'], 'officer', (string) $club['safeguarding_name'], (string) $club['safeguarding_contact']), $others);
        audit((int) $user['id'], 'verification_resent', $club['slug']);
        flash('A new confirmation link has been emailed to ' . $club['safeguarding_name'] . '.'); redirect('/admin');
    }
    if ($path === '/admin/applications/link' && $method === 'POST') {
        csrf_check();
        $club = q('SELECT * FROM clubs WHERE id = ?', [(int) ($_POST['club_id'] ?? 0)])->fetch();
        if (!$club || $club['status'] !== 'pending') { flash('That application is not pending.', true); redirect('/admin'); }
        if (($_POST['who'] ?? '') === 'second') {
            $oc = q('SELECT id FROM coaches WHERE club_id = ? AND is_safeguarding_officer', [$club['id']])->fetch();
            if (!$oc) { flash('This club has no coach who is also the safeguarding officer, so there is no second link.', true); redirect('/admin'); }
            $raw = make_token((int) $club['id'], 'second', (string) $club['key_contact_name'], (string) $club['key_contact_detail'], [(int) $oc['id']]);
            $who = 'the key contact (' . $club['key_contact_name'] . ')';
        } else {
            $raw = make_token((int) $club['id'], 'officer', (string) $club['safeguarding_name'], (string) $club['safeguarding_contact']);
            $who = 'the safeguarding officer (' . $club['safeguarding_name'] . ')';
        }
        audit((int) $user['id'], 'verification_link_shown', $club['slug'] . ' ' . ($_POST['who'] ?? 'officer'));
        flash('Private link for ' . $who . ', valid ' . TOKEN_DAYS . ' days. Send it only to them: ' . verify_link($raw));
        redirect('/admin');
    }
    if ($path === '/admin/applications/decline' && $method === 'POST') {
        csrf_check();
        $club = q('SELECT * FROM clubs WHERE id = ?', [(int) ($_POST['club_id'] ?? 0)])->fetch();
        if (!$club || $club['status'] !== 'pending' || !empty($club['declined_at'])) { flash('That application is not waiting for a decision.', true); redirect('/admin'); }
        $reason = trim((string) ($_POST['reason'] ?? '')); $act = (string) ($_POST['action'] ?? '');
        if ($reason === '' || mb_strlen($reason) > 500) { flash('Write a short reason (up to 500 characters). It is emailed to the key contact.', true); redirect('/admin'); }
        $to = (string) $club['key_contact_detail'];
        if ($act === 'decline') {
            q('UPDATE clubs SET declined_at = ?, decline_reason = ? WHERE id = ?', [now_utc(), $reason, $club['id']]);
            send_email($to, 'Your application to PlayMoreCricket: ' . $club['name'], "Hello {$club['key_contact_name']},\n\nThank you for applying to list {$club['name']} on PlayMoreCricket. We are not able to list the club at the moment.\n\nReason: $reason\n\nIf you think this is a mistake, or things change, please reply to hello@playmorecricket.com.\n\nPlayMoreCricket");
            audit((int) $user['id'], 'club_declined', $club['slug'] . ': ' . $reason);
            flash($club['name'] . ' was declined and the key contact has been emailed.');
        } else {
            send_email($to, 'Your application to PlayMoreCricket needs a change: ' . $club['name'], "Hello {$club['key_contact_name']},\n\nThank you for applying to list {$club['name']} on PlayMoreCricket. Before we can list the club we need the following:\n\n$reason\n\nPlease reply to hello@playmorecricket.com and we will update your application.\n\nPlayMoreCricket");
            audit((int) $user['id'], 'club_changes_requested', $club['slug'] . ': ' . $reason);
            flash('Changes requested. The key contact has been emailed and the application stays in the waiting list.');
        }
        redirect('/admin');
    }
    if ($path === '/admin/applications/confirm' && $method === 'POST') {
        csrf_check();
        $club = q('SELECT * FROM clubs WHERE id = ?', [(int) ($_POST['club_id'] ?? 0)])->fetch();
        if (!$club || $club['status'] !== 'pending') { flash('That application is not pending.', true); redirect('/admin'); }
        $note = trim((string) ($_POST['note'] ?? ''));
        if ($note === '') { flash('Add a short note saying who confirmed it and how (for example "phone call with the officer, 3 Dec").', true); redirect('/admin'); }
        q('UPDATE clubs SET safeguarding_confirmed_at = ?, safeguarding_confirmed_by = ? WHERE id = ?', [now_utc(), mb_substr('Administrator: ' . $note, 0, 160), $club['id']]);
        foreach (q('SELECT id FROM coaches WHERE club_id = ? AND NOT dbs_checked', [$club['id']])->fetchAll() as $co) {
            record_dbs_confirmation((int) $co['id'], null, mb_substr('Administrator: ' . $note, 0, 160), null, 'by_hand', (new DateTimeImmutable('today', london()))->format('Y-m-d'));
        }
        audit((int) $user['id'], 'safeguarding_confirmed_by_hand', $club['slug'] . ': ' . $note);
        flash('Recorded as confirmed by hand.'); redirect('/admin');
    }
    if ($path === '/admin/coach/dbs' && $method === 'POST') {
        csrf_check();
        $co = q('SELECT c.*, cl.slug AS club_slug FROM coaches c JOIN clubs cl ON cl.id = c.club_id WHERE c.id = ?', [(int) ($_POST['coach_id'] ?? 0)])->fetch();
        if (!$co) { flash('That coach does not exist.', true); redirect('/admin'); }
        if (($_POST['action'] ?? '') === 'clear') {
            q('UPDATE coaches SET dbs_checked = ?, dbs_checked_on = NULL, dbs_method = NULL, dbs_confirmed_by = NULL, dbs_enhanced = ? WHERE id = ?', [0, 0, $co['id']]);
            audit((int) $user['id'], 'dbs_cleared', $co['name'] . ' (' . $co['club_slug'] . ')');
            flash('The DBS tick was removed for ' . $co['name'] . '.'); redirect('/admin');
        }
        $note = trim((string) ($_POST['note'] ?? '')); $method_ = (string) ($_POST['method'] ?? '');
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($_POST['checked_on'] ?? ''), london());
        if ($note === '' || !in_array($method_, ['certificate', 'update_service', 'by_hand'], true) || !$dt || $dt > new DateTimeImmutable('today', london())) {
            flash('Choose how it was checked, a date that is not in the future, and add a note saying who confirmed it.', true); redirect('/admin');
        }
        record_dbs_confirmation((int) $co['id'], null, mb_substr('Administrator: ' . $note, 0, 160), null, $method_, $dt->format('Y-m-d'));
        audit((int) $user['id'], 'dbs_set_by_hand', $co['name'] . ' (' . $co['club_slug'] . '): ' . $note);
        flash('DBS check recorded for ' . $co['name'] . '.'); redirect('/admin');
    }
    if ($path === '/admin/policy' && $method === 'GET') {
        $d = q("SELECT filename, mime, data_b64 FROM club_documents WHERE club_id = ? AND kind = 'safeguarding' ORDER BY id DESC LIMIT 1", [(int) ($_GET['club'] ?? 0)])->fetch();
        if (!$d) { http_response_code(404); page('Not found', '<p>No policy file for that club.</p>'); }
        audit((int) $user['id'], 'policy_downloaded', 'club ' . (int) $_GET['club']);
        header('Content-Type: ' . $d['mime']); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $d['filename']) . '"');
        echo base64_decode($d['data_b64']); exit;
    }
    if ($path === '/admin/users/link' && $method === 'POST') {
        csrf_check();
        $tu = q('SELECT id, email, name FROM users WHERE id = ?', [(int) ($_POST['id'] ?? 0)])->fetch();
        if (!$tu) { flash('That login does not exist.', true); redirect('/admin'); }
        $raw = password_token_make((int) $tu['id'], 'reset', 48);
        audit((int) $user['id'], 'password_link_shown', $tu['email']);
        flash('One-time password link for ' . $tu['name'] . ' (works for 48 hours, send it only to them): ' . password_link($raw)); redirect('/admin/users/edit?id=' . (int) $tu['id']);
    }
    if ($path === '/admin/users/update' && $method === 'POST') {
        csrf_check();
        $id = (int) ($_POST['id'] ?? 0); $email = trim((string) ($_POST['email'] ?? '')); $name = trim((string) ($_POST['name'] ?? '')); $pw = (string) ($_POST['password'] ?? '');
        $role = (string) ($_POST['role'] ?? ''); $clubId = (int) ($_POST['club_id'] ?? 0); $coachId = (int) ($_POST['coach_id'] ?? 0);
        $target = q('SELECT * FROM users WHERE id = ?', [$id])->fetch();
        if (!$target) { flash('That login does not exist.', true); redirect('/admin'); }
        $back = '/admin/users/edit?id=' . $id;
        $club = $clubId ? q('SELECT id FROM clubs WHERE id = ?', [$clubId])->fetch() : null;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '' || ($pw !== '' && strlen($pw) < 10) || !in_array($role, ['admin', 'club_contact', 'coach'], true) || ($role !== 'admin' && !$club)) {
            flash('Enter a name, a valid email, a role, a club (for non-admins), and either leave the password blank or use at least 10 characters.', true); redirect($back);
        }
        if (q('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, $id])->fetch()) { flash('Another login already uses that email.', true); redirect($back); }
        if ($target['role'] === 'admin' && $role !== 'admin' && (int) q("SELECT COUNT(*) AS n FROM users WHERE role = 'admin'")->fetch()['n'] < 2) {
            flash('This is the only administrator, so its role cannot be changed. Make another administrator first.', true); redirect($back);
        }
        $newClub = $role === 'admin' ? null : $clubId;
        q('UPDATE users SET name = ?, email = ?, role = ?, club_id = ? WHERE id = ?', [$name, $email, $role, $newClub, $id]);
        if ($pw !== '') { q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $id]); }
        // A coach login stays linked to one coach record at the same club. Any other change removes the link.
        if ($role !== 'coach' || (int) $target['club_id'] !== (int) $newClub) { q('UPDATE coaches SET user_id = NULL WHERE user_id = ?', [$id]); }
        if ($role === 'coach' && $coachId) {
            q('UPDATE coaches SET user_id = NULL WHERE user_id = ? AND id <> ?', [$id, $coachId]);
            q('UPDATE coaches SET user_id = ? WHERE id = ? AND club_id = ? AND (user_id IS NULL OR user_id = ?)', [$id, $coachId, $clubId, $id]);
        }
        audit((int) $user['id'], 'user_updated', $target['email'] . ' (' . $target['role'] . ') -> ' . $email . ' (' . $role . ')' . ($pw !== '' ? ' password reset' : ''));
        flash('Login updated for ' . $name . '.'); redirect('/admin');
    }
    if ($path === '/admin/users/edit') {
        $id = (int) ($_GET['id'] ?? 0);
        $target = q('SELECT id, name, email, role, club_id FROM users WHERE id = ?', [$id])->fetch();
        if (!$target) { flash('That login does not exist.', true); redirect('/admin'); }
        $copts = '<option value="0">(none)</option>';
        foreach (q('SELECT c.id, c.name, c.club_id, c.user_id, cl.name AS club FROM coaches c JOIN clubs cl ON cl.id = c.club_id WHERE c.active AND (c.user_id IS NULL OR c.user_id = ?) ORDER BY cl.name, c.name', [$id])->fetchAll() as $c) {
            $copts .= '<option value="' . (int) $c['id'] . '"' . ((int) $c['user_id'] === $id ? ' selected' : '') . '>' . h($c['name']) . ' (' . h($c['club']) . ')</option>';
        }
        $ropts = ''; foreach (['club_contact' => 'Club contact', 'coach' => 'Coach', 'admin' => 'Administrator'] as $k => $lbl) { $ropts .= '<option value="' . $k . '"' . ($target['role'] === $k ? ' selected' : '') . '>' . $lbl . '</option>'; }
        $clubopts = ''; foreach (q('SELECT id, name FROM clubs ORDER BY name')->fetchAll() as $c) { $clubopts .= '<option value="' . (int) $c['id'] . '"' . ((int) $target['club_id'] === (int) $c['id'] ? ' selected' : '') . '>' . h($c['name']) . '</option>'; }
        page('Edit login', '<h1>Edit login</h1><form method="post" action="/admin/users/update" class="card narrow">' . csrf_field() . '<input type="hidden" name="id" value="' . (int) $target['id'] . '">'
            . '<label>Name<input name="name" value="' . h($target['name']) . '" required></label>'
            . '<label>Email<input type="email" name="email" value="' . h($target['email']) . '" required autocomplete="off"></label>'
            . '<label>Role<select name="role">' . $ropts . '</select></label>'
            . '<label>Club (ignored for administrators)<select name="club_id">' . $clubopts . '</select></label>'
            . '<label>Coach record to link (coaches only, must be at the chosen club)<select name="coach_id">' . $copts . '</select></label>'
            . '<label>New password (leave blank to keep the current one)<input type="password" name="password" minlength="10" autocomplete="new-password"></label>'
            . '<button type="submit">Save</button> <a href="/admin">Cancel</a></form>'
            . '<form method="post" action="/admin/users/link" class="card narrow">' . csrf_field() . '<input type="hidden" name="id" value="' . (int) $target['id'] . '"><p class="muted">Email not working yet, or they cannot find it? Make a one-time link to send them yourself.</p><button type="submit" class="secondary">Show me a one-time password link</button></form>');
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
    foreach (q('SELECT u.id, u.name, u.email, u.role, c.name AS club FROM users u LEFT JOIN clubs c ON c.id = u.club_id ORDER BY u.role, u.name')->fetchAll() as $u2) {
        $b .= '<li><strong>' . h($u2['name']) . '</strong> · ' . h($u2['email']) . ' · ' . h($u2['role']) . ($u2['club'] ? ' · ' . h($u2['club']) : '') . ' · <a href="/admin/users/edit?id=' . (int) $u2['id'] . '">Edit</a></li>';
    }
    $b .= '</ul><form method="post" action="/admin/users" class="card narrow"><strong>Add a login</strong>' . csrf_field()
        . '<label>Name<input name="name" required></label><label>Email<input type="email" name="email" required autocomplete="off"></label>'
        . '<label>Role<select name="role"><option value="club_contact">Club contact</option><option value="coach">Coach</option><option value="admin">Administrator</option></select></label>'
        . '<label>Club<select name="club_id">' . $opts . '</select></label>'
        . '<label>Coach record to link (coaches only)<select name="coach_id">' . $copts . '</select></label>'
        . '<label>Password (at least 10 characters)<input type="password" name="password" required minlength="10" autocomplete="new-password"></label>'
        . '<button type="submit">Create login</button></form><h2>Clubs and commission</h2>';
    send_due_reminders(); refresh_unfinished_stripe(); send_stripe_reminders();
    $pend = q("SELECT * FROM clubs WHERE status = 'pending' AND submitted_at IS NOT NULL AND declined_at IS NULL ORDER BY submitted_at")->fetchAll();
    if ($pend) {
        $b .= '<h2>Club applications waiting</h2>';
        foreach ($pend as $c) {
            $co = q('SELECT name, dbs_checked, dbs_method, dbs_confirmed_by, dbs_checked_on, dbs_enhanced FROM coaches WHERE club_id = ? ORDER BY id', [$c['id']])->fetchAll();
            $summary = '';
            foreach (q('SELECT id, name, kind, member_price_pence, nonmember_price_pence, machine_price_pence FROM nets WHERE club_id = ? ORDER BY kind, id', [$c['id']])->fetchAll() as $nn) {
                $summary .= '<li>' . h($nn['name']) . ': members ' . money((int) $nn['member_price_pence']) . ', non-members ' . money((int) $nn['nonmember_price_pence']) . '/hr' . ($nn['machine_price_pence'] !== null ? ', machine +' . money((int) $nn['machine_price_pence']) : '') . '</li>';
            }
            $hoursTxt = ''; $dn7 = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
            foreach (['outdoor', 'indoor'] as $hk) {
                $fn = q('SELECT id FROM nets WHERE club_id = ? AND kind = ? ORDER BY id LIMIT 1', [$c['id'], $hk])->fetch();
                if (!$fn) { continue; }
                $parts = [];
                foreach (q('SELECT weekday, is_closed, open_hour, close_hour FROM opening_hours WHERE net_id = ? ORDER BY weekday', [$fn['id']])->fetchAll() as $hr) {
                    $parts[] = $dn7[(int) $hr['weekday']] . ' ' . ($hr['is_closed'] ? 'closed' : sprintf('%02d-%02d', $hr['open_hour'], $hr['close_hour']));
                }
                $hoursTxt .= '<li>' . ucfirst($hk) . ' hours: ' . h(implode(', ', $parts)) . '</li>';
            }
            $crestRow = q('SELECT permission_given_by, uploaded_at FROM club_crests WHERE club_id = ?', [$c['id']])->fetch();
            $b .= '<div class="card"><strong>' . h($c['name']) . '</strong> <span class="muted">' . h($c['town']) . ' · applied ' . h(from_utc($c['submitted_at'])->format('j M Y')) . '</span>'
                . '<p>Key contact: ' . h((string) $c['key_contact_name']) . ', ' . h((string) $c['key_contact_detail']) . ' · ' . h((string) $c['phone']) . '<br>Safeguarding officer: ' . h((string) $c['safeguarding_name']) . ', ' . h((string) $c['safeguarding_contact']) . '<br>Website or social page: ' . (!empty($c['web_link']) ? '<a href="' . h((string) $c['web_link']) . '" target="_blank" rel="noopener noreferrer">' . h((string) $c['web_link']) . '</a> (check this is the real club)' : '<strong>none given</strong>') . '<br>Policy: ' . h((string) $c['safeguarding_policy']) . (q("SELECT 1 FROM club_documents WHERE club_id = ? AND kind = 'safeguarding'", [$c['id']])->fetch() ? ' &middot; <a href="/admin/policy?club=' . (int) $c['id'] . '">Download the file</a>' : '') . '</p>'
                . '<p><strong>What will be published</strong> (<a href="/club/' . h($c['slug']) . '" target="_blank">preview the club page</a>)</p><ul>' . $summary . $hoursTxt . '</ul>'
                . ($crestRow ? '<p><img src="/crest/' . h($c['slug']) . '" alt="Crest" width="48" height="48"> Crest uploaded; permission to display given by ' . h((string) $crestRow['permission_given_by']) . ' on ' . h(from_utc($crestRow['uploaded_at'])->format('j M Y')) . '.</p>' : '<p>No crest uploaded.</p>')
                . '<p>' . (!empty($c['safeguarding_confirmed_at']) ? '&#10003; Policy confirmed by ' . h((string) $c['safeguarding_confirmed_by']) . ' on ' . h(from_utc($c['safeguarding_confirmed_at'])->format('j M Y')) : 'Safeguarding policy: <strong>not yet confirmed</strong>') . '</p><ul>';
            foreach ($co as $x) {
                $b .= '<li>' . h($x['name']) . ': ' . ($x['dbs_checked'] ? '&#10003; ' . h(method_label($x['dbs_method'])) . ' on ' . h(date('j M Y', strtotime((string) $x['dbs_checked_on']))) . ' (confirmed by ' . h((string) $x['dbs_confirmed_by']) . ')' . dbs_flags_html($x) : '<strong>not yet confirmed</strong>') . '</li>';
            }
            $b .= '</ul><form method="post" action="/admin/applications/approve" class="row">' . csrf_field() . '<input type="hidden" name="club_id" value="' . (int) $c['id'] . '">'
                . '<label class="check"><input type="checkbox" name="allow_unconfirmed" value="1"> Approve even if some coaches are unconfirmed</label><button type="submit">Approve and publish</button></form>'
                . '<form method="post" action="/admin/applications/resend" class="row">' . csrf_field() . '<input type="hidden" name="club_id" value="' . (int) $c['id'] . '"><button type="submit">Send the officer a new link</button></form>'
                . '<form method="post" action="/admin/applications/decline" class="row">' . csrf_field() . '<input type="hidden" name="club_id" value="' . (int) $c['id'] . '"><label>Reason or what we need (emailed to the key contact)<input name="reason" maxlength="500"></label><button type="submit" name="action" value="changes" class="secondary">Ask for changes</button><button type="submit" name="action" value="decline" class="secondary">Decline</button></form>'
                . '<form method="post" action="/admin/applications/link" class="row">' . csrf_field() . '<input type="hidden" name="club_id" value="' . (int) $c['id'] . '"><button type="submit" name="who" value="officer" class="secondary">Show me the officer link to send myself</button>'
                . (q('SELECT 1 FROM coaches WHERE club_id = ? AND is_safeguarding_officer', [$c['id']])->fetch() ? '<button type="submit" name="who" value="second" class="secondary">Show me the key contact link</button>' : '') . '</form>'
                . '<form method="post" action="/admin/applications/confirm" class="row">' . csrf_field() . '<input type="hidden" name="club_id" value="' . (int) $c['id'] . '"><label>Confirmed by hand, note<input name="note" maxlength="120" placeholder="who, how, when"></label><button type="submit">Record</button></form></div>';
        }
        $b .= '<h2>All clubs</h2>';
    }
    foreach (q('SELECT id, slug, name, status, commission_pct, commission_free_until, stripe_account_id, stripe_charges_enabled FROM clubs ORDER BY name')->fetchAll() as $c) {
        $b .= '<form method="post" action="/admin/commission" class="card row">' . csrf_field() . '<input type="hidden" name="club_id" value="' . (int) $c['id'] . '">'
            . '<strong>' . h($c['name']) . '</strong> <span class="muted">(' . h($c['status']) . ')</span>'
            . '<label>Commission %<input name="commission_pct" value="' . h((string) $c['commission_pct']) . '" inputmode="decimal" size="5"></label>'
            . '<label>No commission until<input type="date" name="commission_free_until" value="' . h((string) $c['commission_free_until']) . '"></label>'
            . '<button type="submit">Save</button></form>';
        $has = !empty($c['stripe_account_id']);
        $on = (int) $c['stripe_charges_enabled'] === 1;
        $b .= '<div class="card row"><span class="muted">Payments: ' . ($has ? 'Stripe account ' . h((string) $c['stripe_account_id']) . ($on ? ' (payments on)' : ' (sign-up not finished)') : 'not set up yet') . '</span>';
        if (!$on) {
            $b .= '<form method="post" action="/stripe/connect">' . csrf_field() . '<input type="hidden" name="club_id" value="' . (int) $c['id'] . '">'
                . '<button type="submit">' . ($has ? 'Continue Stripe sign-up' : 'Set up payments') . '</button></form>';
        }
        $b .= '</div>';
        $b .= '<details class="card"><summary>Coaches and DBS checks</summary>';
        foreach (q('SELECT id, name, dbs_checked, dbs_checked_on, dbs_method, dbs_confirmed_by, dbs_enhanced FROM coaches WHERE club_id = ? ORDER BY name', [$c['id']])->fetchAll() as $x) {
            $b .= '<div class="row"><strong>' . h($x['name']) . '</strong> <span class="muted">' . ($x['dbs_checked'] ? '&#10003; ' . h(method_label($x['dbs_method'])) . ' on ' . h(date('j M Y', strtotime((string) $x['dbs_checked_on']))) . ', ' . h((string) $x['dbs_confirmed_by']) . dbs_flags_html($x) : 'no confirmed check') . '</span>'
                . '<form method="post" action="/admin/coach/dbs" class="row">' . csrf_field() . '<input type="hidden" name="coach_id" value="' . (int) $x['id'] . '">'
                . '<select name="method"><option value="certificate">Certificate seen</option><option value="update_service">Update Service checked</option><option value="by_hand">Other (say in note)</option></select>'
                . '<input type="date" name="checked_on" max="' . (new DateTimeImmutable('today', london()))->format('Y-m-d') . '" aria-label="Date checked">'
                . '<input name="note" maxlength="120" placeholder="who confirmed, how" aria-label="Note"><button type="submit" name="action" value="set">Record check</button>'
                . ($x['dbs_checked'] ? '<button type="submit" name="action" value="clear">Remove tick</button>' : '') . '</form></div>';
        }
        $b .= '</details>';
        if ($has) {
            $b .= '<form method="post" action="/admin/stripe/disconnect" class="card row">' . csrf_field() . '<input type="hidden" name="club_id" value="' . (int) $c['id'] . '">'
                . '<label><input type="checkbox" name="confirm" value="1"> Disconnect (use before switching from sandbox to live)</label>'
                . '<button type="submit">Disconnect payments</button></form>';
        }
    }
    page('Admin', $b);
}

if ($method === 'GET' && preg_match('#^/calendar/(club|coach)/([0-9a-f]{32})\.ics$#', $path, $fm)) { serve_feed($fm[1], $fm[2]); }
if ($path === '/calendar/renew' && $method === 'POST') {
    $u = require_login(); csrf_check();
    $kind = (string) ($_POST['kind'] ?? ''); $id = (int) ($_POST['id'] ?? 0);
    if ($kind === 'club') {
        $club = q('SELECT * FROM clubs WHERE id = ?', [$id])->fetch();
        if (!$club || !can_manage($u, $club)) { http_response_code(403); page('Not allowed', '<p>Not allowed.</p>'); }
        feed_token('clubs', $id, true);
    } elseif ($kind === 'coach') {
        $co = q('SELECT * FROM coaches WHERE id = ?', [$id])->fetch(); $club = $co ? q('SELECT * FROM clubs WHERE id = ?', [$co['club_id']])->fetch() : null;
        $own = $club ? own_coach($u, $club) : null;
        if (!$co || !(can_manage($u, $club) || ($own && (int) $own['id'] === $id))) { http_response_code(403); page('Not allowed', '<p>Not allowed.</p>'); }
        feed_token('coaches', $id, true);
    } else { http_response_code(400); page('Error', '<p>Bad request.</p>'); }
    audit((int) $u['id'], 'calendar_link_renewed', $kind . ' ' . $id);
    flash('New calendar link made. Add the new link to your calendar; the old one no longer works.');
    $ret = (string) ($_POST['return'] ?? '/'); redirect(preg_match('#^/[A-Za-z0-9/_?=&.-]*$#', $ret) && strpos($ret, '//') === false ? $ret : '/');
}
if ($path === '/coaches/manage' && $method === 'POST') {
    $u = require_login(); csrf_check();
    $club = q('SELECT * FROM clubs WHERE id = ?', [(int) ($_POST['club_id'] ?? 0)])->fetch();
    if (!$club || !can_manage($u, $club)) { http_response_code(403); page('Not allowed', '<p>You cannot manage coaches at that club.</p>'); }
    $back = '/club/' . $club['slug'] . '#coaches';
    $act = (string) ($_POST['action'] ?? '');
    $coachRow = null;
    if ($act !== 'add') { $coachRow = q('SELECT * FROM coaches WHERE id = ? AND club_id = ?', [(int) ($_POST['coach_id'] ?? 0), $club['id']])->fetch(); if (!$coachRow) { flash('That coach was not found.', true); redirect($back); } }
    if ($act === 'add' || $act === 'save') {
        $name = trim((string) ($_POST['name'] ?? '')); $email = trim((string) ($_POST['email'] ?? '')); $pp = pence_from(trim((string) ($_POST['price'] ?? '')));
        $q = (string) ($_POST['qual'] ?? ''); $qual = $q === 'Other' ? trim((string) ($_POST['qual_other'] ?? '')) : $q;
        if ($name === '' || mb_strlen($name) > 160 || $pp === null || $pp <= 0 || $qual === '' || !in_array($q, QUALIFICATIONS, true) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('Please give the coach a name, an hourly price, a qualification and a valid email address.', true); redirect($back);
        }
        $qual = mb_substr($qual, 0, 160);
        if ($act === 'add') {
            q('INSERT INTO coaches (club_id, name, hourly_price_pence, qualification, dbs_checked, is_safeguarding_officer, email) VALUES (?,?,?,?,?,?,?)', [$club['id'], $name, $pp, $qual, 0, 0, $email]);
            audit((int) $u['id'], 'coach_added', $name . ' (' . $club['slug'] . ')');
            [$made] = create_club_logins($club);
            flash($name . ' was added with no DBS tick. ' . ($made ? 'A login set-up link was emailed to them. ' : '') . 'Use "Ask the safeguarding officer to confirm" so they can show a DBS tick.');
        } else {
            $renamed = $name !== $coachRow['name'];
            q('UPDATE coaches SET name = ?, hourly_price_pence = ?, qualification = ?, email = ? WHERE id = ?', [$name, $pp, $qual, $email, $coachRow['id']]);
            if ($renamed && $coachRow['dbs_checked']) {
                q('UPDATE coaches SET dbs_checked = ?, dbs_checked_on = NULL, dbs_method = NULL, dbs_confirmed_by = NULL, dbs_enhanced = ? WHERE id = ?', [0, 0, $coachRow['id']]);
            }
            audit((int) $u['id'], 'coach_updated', $coachRow['name'] . ' -> ' . $name . ' (' . $club['slug'] . ')');
            flash('Saved ' . $name . '.' . ($renamed && $coachRow['dbs_checked'] ? ' Because the name changed, the DBS tick was removed until the safeguarding officer confirms it again.' : ' New prices apply to bookings made from now on.'));
        }
        redirect($back);
    }
    if ($act === 'deactivate') {
        $n = (int) q("SELECT COUNT(*) AS n FROM bookings WHERE coach_id = ? AND status IN ('pending_payment','confirmed') AND starts_at > ?", [$coachRow['id'], utc_string(new DateTimeImmutable('now', london()))])->fetch()['n'];
        if ($n > 0) { flash($coachRow['name'] . ' still has ' . $n . ' upcoming booking(s). Cancel or release them first, then stop the coach.', true); redirect($back); }
        q('UPDATE coaches SET active = ? WHERE id = ?', [0, $coachRow['id']]);
        q('DELETE FROM coach_availability WHERE coach_id = ? AND starts_at > ?', [$coachRow['id'], utc_string(new DateTimeImmutable('now', london()))]);
        audit((int) $u['id'], 'coach_deactivated', $coachRow['name'] . ' (' . $club['slug'] . ')');
        flash($coachRow['name'] . ' no longer appears on the club page and cannot be booked.'); redirect($back);
    }
    if ($act === 'activate') {
        q('UPDATE coaches SET active = ? WHERE id = ?', [1, $coachRow['id']]);
        audit((int) $u['id'], 'coach_reactivated', $coachRow['name'] . ' (' . $club['slug'] . ')');
        flash($coachRow['name'] . ' is back on the club page. Set their hours again on the Coach availability page.'); redirect($back);
    }
    if ($act === 'confirmlink') {
        if ($coachRow['dbs_checked']) { flash($coachRow['name'] . ' already has a confirmed DBS check.', true); redirect($back); }
        $sname = (string) $club['safeguarding_name']; $semail = (string) $club['safeguarding_contact'];
        if (!filter_var($semail, FILTER_VALIDATE_EMAIL)) { flash('The club has no safeguarding officer email on record.', true); redirect($back); }
        if ($coachRow['is_safeguarding_officer']) {
            email_officer_link($club, 'second', (string) $club['key_contact_name'], (string) $club['key_contact_detail'], make_token((int) $club['id'], 'second', (string) $club['key_contact_name'], (string) $club['key_contact_detail'], [(int) $coachRow['id']]), [$coachRow['name']]);
            flash('Because ' . $coachRow['name'] . ' is the safeguarding officer, the key contact was emailed a link to confirm the check.');
        } else {
            email_officer_link($club, 'officer', $sname, $semail, make_token((int) $club['id'], 'officer', $sname, $semail), [$coachRow['name']]);
            flash('The safeguarding officer, ' . $sname . ', was emailed a private link to confirm ' . $coachRow['name'] . "'s DBS check.");
        }
        audit((int) $u['id'], 'coach_confirm_link_sent', $coachRow['name'] . ' (' . $club['slug'] . ')');
        redirect($back);
    }
    redirect($back);
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
        $act = (string) ($_POST['action'] ?? 'week');
        if ($act === 'pattern') {
            save_pattern($coach, (array) ($_POST['pat'] ?? []));
            audit((int) $u['id'], 'pattern_saved', $coach['name']);
            flash('Standing weekly pattern saved for ' . $coach['name'] . '. The next ' . PATTERN_WEEKS . ' weeks are filled in; weeks you edited by hand are left alone.');
        } elseif ($act === 'reset') {
            reset_week_to_pattern($coach, $monday);
            audit((int) $u['id'], 'week_reset_to_pattern', $coach['name'] . ' week ' . $monday->format('Y-m-d'));
            flash('That week is back to the standing pattern.');
        } else {
            save_availability($coach, $monday, (array) ($_POST['slot'] ?? []), (int) ($_POST['repeat'] ?? 0));
            audit((int) $u['id'], 'availability_saved', $coach['name'] . ' week ' . $monday->format('Y-m-d'));
            flash('Availability saved for ' . $coach['name'] . '.');
        }
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
    apply_pattern($coach);
    $have = week_availability((int) $club['id'], $monday)[(int) $coach['id']] ?? [];
    $booked = week_coach_busy((int) $club['id'], $monday)[(int) $coach['id']] ?? [];
    $wk = ''; $wk .= '<h2>Change a single week</h2><p class="muted">Tick the hours <strong>' . h($coach['name']) . '</strong> is available to coach. Only ticked hours can be booked by visitors.</p>'
        . '<p class="weeknav"><a href="/coach/availability?week=' . $monday->modify('-7 days')->format('Y-m-d') . ($manager ? '&coach=' . (int) $coach['id'] : '') . '">← Previous week</a> <strong>Week of ' . $monday->format('j M Y') . '</strong> <a href="/coach/availability?week=' . $monday->modify('+7 days')->format('Y-m-d') . ($manager ? '&coach=' . (int) $coach['id'] : '') . '">Next week →</a></p>';
    $minH = 24; $maxH = 0; $open = [];
    for ($i = 0; $i < 7; $i++) {
        $d = $monday->modify("+$i days"); $open[$i] = [];
        foreach ($nets as $n) { $hrs = hours_for((int) $n['id'], $d); if ($hrs) { $open[$i][] = $hrs; $minH = min($minH, $hrs[0]); $maxH = max($maxH, $hrs[1]); } }
    }
    $now = new DateTimeImmutable('now', london());
    $wk .= '<form method="post">' . csrf_field() . '<input type="hidden" name="week" value="' . h($monday->format('Y-m-d')) . '"><input type="hidden" name="coach" value="' . (int) $coach['id'] . '">'
        . '<div class="scroll"><table class="diary"><thead><tr><th></th>';
    for ($i = 0; $i < 7; $i++) { $b .= '<th>' . $monday->modify("+$i days")->format('D j') . '</th>'; }
    $wk .= '</tr></thead><tbody>';
    for ($h = $minH; $h < $maxH; $h++) {
        $wk .= '<tr><th>' . sprintf('%02d:00', $h) . '</th>';
        for ($i = 0; $i < 7; $i++) {
            $d = $monday->modify("+$i days"); $isOpen = false;
            foreach ($open[$i] as $hr) { if ($h >= $hr[0] && $h < $hr[1]) { $isOpen = true; } }
            if (!$isOpen || $d->setTime($h, 0) <= $now) { $b .= '<td class="closed">–</td>'; continue; }
            $key = $d->format('Y-m-d') . ' ' . sprintf('%02d', $h);
            if (isset($booked[$key])) { $wk .= '<td class="free booked">Booked<input type="checkbox" checked disabled aria-label="' . h($d->format('D j') . ' ' . sprintf('%02d:00', $h)) . ' booked"></td>'; continue; }
            $wk .= '<td class="' . (isset($have[$key]) ? 'free' : '') . '"><input type="checkbox" name="slot[]" value="' . $i . '-' . $h . '"' . (isset($have[$key]) ? ' checked' : '') . ' aria-label="' . h($d->format('D j') . ' ' . sprintf('%02d:00', $h)) . '"></td>';
        }
        $wk .= '</tr>';
    }
    $wk .= '</tbody></table></div><label>Also use this pattern for the next <select name="repeat" class="inline"><option value="0">0 weeks (just this week)</option><option value="1">1 week</option><option value="3">3 weeks</option><option value="7">7 weeks</option><option value="11">11 weeks</option></select></label>'
        . '<button type="submit">Save availability</button></form>';
    $wk .= '<form method="post" class="inlineform">' . csrf_field() . '<input type="hidden" name="week" value="' . h($monday->format('Y-m-d')) . '"><input type="hidden" name="coach" value="' . (int) $coach['id'] . '"><input type="hidden" name="action" value="reset"><button type="submit" class="secondary">Put this week back to my standing pattern</button></form>';
    // Standing weekly pattern: same weekday and hour every week, filled in automatically for the next weeks.
    $pat = []; foreach (coach_pattern((int) $coach['id']) as [$pd, $ph]) { $pat[$pd . '-' . $ph] = true; }
    $pmin = 24; $pmax = 0;
    foreach ($nets as $n) { foreach (q('SELECT weekday, MIN(open_hour) AS a, MAX(close_hour) AS z FROM opening_hours WHERE net_id = ? AND NOT is_closed GROUP BY weekday', [$n['id']])->fetchAll() as $r) { $pmin = min($pmin, (int) $r['a']); $pmax = max($pmax, (int) $r['z']); } }
    if ($pmin >= $pmax) { $pmin = 8; $pmax = 21; }
    $dn = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    $pt = ''; $pt .= '<h2>Standing weekly pattern</h2><p class="muted">Set your usual hours once, for example Tuesdays and Thursdays 5 to 8pm. They fill in automatically for each new week, and you can still change any single week above. We email you on Sundays if next week is empty.</p>'
        . '<form method="post">' . csrf_field() . '<input type="hidden" name="week" value="' . h($monday->format('Y-m-d')) . '"><input type="hidden" name="coach" value="' . (int) $coach['id'] . '"><input type="hidden" name="action" value="pattern">'
        . '<div class="scroll"><table class="diary"><thead><tr><th></th>';
    foreach ($dn as $d) { $b .= '<th>' . $d . '</th>'; }
    $pt .= '</tr></thead><tbody>';
    for ($h = $pmin; $h < $pmax; $h++) {
        $pt .= '<tr><th>' . sprintf('%02d:00', $h) . '</th>';
        for ($i = 0; $i < 7; $i++) { $k = $i . '-' . $h; $b .= '<td class="' . (isset($pat[$k]) ? 'free' : '') . '"><input type="checkbox" name="pat[]" value="' . $k . '"' . (isset($pat[$k]) ? ' checked' : '') . ' aria-label="' . $dn[$i] . ' ' . sprintf('%02d:00', $h) . '"></td>'; }
        $pt .= '</tr>';
    }
    $pt .= '</tbody></table></div><button type="submit">Save standing pattern</button></form>';
    // Welcome (first time), upcoming sessions, then the standing pattern (easiest way) before the single-week grid
    $nowU = utc_string(new DateTimeImmutable('now', london()));
    $hasAny = (int) q('SELECT COUNT(*) AS n FROM coach_availability WHERE coach_id = ? AND starts_at >= ?', [$coach['id'], $nowU])->fetch()['n'] > 0 || coach_pattern((int) $coach['id']);
    $welcome = $hasAny ? '' : '<div class="notice"><strong>Welcome, ' . h($coach['name']) . '.</strong> Visitors cannot book you until you say when you are free. The quickest way: in <em>Standing weekly pattern</em> below, tick the hours you usually coach (for example Tuesdays and Thursdays 5 to 8pm) and press Save. It fills in the next ' . PATTERN_WEEKS . ' weeks, and you can change any single week afterwards.</div>';
    $up = q("SELECT b.reference, b.starts_at, b.with_machine, b.is_junior, b.child_first_name, b.child_age, b.customer_name, n.name AS net_name FROM bookings b JOIN nets n ON n.id = b.net_id
             WHERE b.coach_id = ? AND b.status = 'confirmed' AND b.starts_at >= ? ORDER BY b.starts_at LIMIT 15", [$coach['id'], $nowU])->fetchAll();
    $upHtml = '<h2>My upcoming sessions</h2>';
    if (!$up) { $upHtml .= '<p class="muted">No paid sessions booked yet.</p>'; }
    else {
        $upHtml .= '<ul class="plain slots">';
        foreach ($up as $x) {
            $upHtml .= '<li><strong>' . h(from_utc($x['starts_at'])->format('D j M, H:i')) . '</strong> · ' . h($x['net_name']) . ' · ' . h((string) $x['customer_name'])
                . ($x['with_machine'] ? ' · bowling machine' : '') . ($x['is_junior'] ? ' · <strong>under-18: ' . h((string) $x['child_first_name']) . ', ' . (int) $x['child_age'] . '</strong>' : '') . '</li>';
        }
        $upHtml .= '</ul>';
    }
    $b .= $welcome . $upHtml . $pt . $wk;
    $b .= calendar_box('coach', (int) $coach['id'], csrf_field(), '/coach/availability?coach=' . (int) $coach['id']);
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
    } catch (Throwable $e) { error_log('PMC connect failed: ' . $e->getMessage()); flash('Could not reach Stripe. Please try again.' . ($u['role'] === 'admin' ? ' (Detail for administrators: ' . $e->getMessage() . ')' : ''), true); redirect('/club/' . $club['slug']); }
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
if (preg_match('#^/booking/(PMC-[A-Z0-9]{6})\\.ics$#', $path, $im)) {
    $b = booking_by_ref($im[1]);
    if (!$b || $b['status'] !== 'confirmed' || !hash_equals(booking_token($b['reference']), (string) ($_REQUEST['t'] ?? ''))) { http_response_code(404); page('Not found', '<p>We could not find that booking.</p>'); }
    $b['customer_name'] = '';
    header('Content-Type: text/calendar; charset=utf-8'); header('Content-Disposition: attachment; filename="' . $b['reference'] . '.ics"');
    echo ics_calendar('PlayMoreCricket booking', [$b], true); exit;
}
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

if (preg_match('#^/crest/([a-z0-9-]+)$#', $path, $m)) {
    $row = q("SELECT k.mime, k.data_b64 FROM club_crests k JOIN clubs c ON c.id = k.club_id WHERE c.slug = ? AND (c.status = 'active' OR c.sponsored_partner" . (($user && $user['role'] === 'admin') ? ' OR 1=1' : '') . ')', [$m[1]])->fetch();
    if (!$row || !isset(CREST_TYPES[$row['mime']])) { http_response_code(404); exit; }
    header('Content-Type: ' . $row['mime']); header('X-Content-Type-Options: nosniff'); header('Cache-Control: ' . ($user ? 'private, no-store' : 'public, max-age=86400'));
    echo base64_decode($row['data_b64']); exit;
}

if ($path === '/club-terms') {
    page('Club terms (draft)', '<h1>Club terms</h1><p class="notice"><strong>DRAFT.</strong> This wording is a working draft and has not yet been reviewed by a solicitor. It will change before clubs go live.</p>'
        . '<h2>Listing and bookings</h2><p>PlayMoreCricket lists the club\'s nets and coaches and lets visitors book and pay online. The club decides its own prices, opening hours and coaches, and is responsible for running each session safely.</p>'
        . '<h2>Payments and commission</h2><p>Visitors pay the club directly through Stripe, using the club\'s own Stripe account. PlayMoreCricket takes a commission on each booking (10% unless we have agreed a commission-free period with the club in writing). Stripe\'s card processing fee is paid by the club. The club must keep its Stripe account in good standing.</p>'
        . '<h2>Cancellations and refunds</h2><p>A visitor who cancels more than 24 hours before the start gets a full refund automatically. Within 24 hours the full fee applies. If the club cancels a booking (for example because of weather), the visitor is refunded in full.</p>'
        . '<h2>Safeguarding</h2><p>The club confirms that it has a written safeguarding policy and that its safeguarding officer has checked each listed coach\'s DBS status (an Enhanced check including the children\'s barred list). PlayMoreCricket does not check this independently and shows a DBS tick only after the club\'s officer has confirmed it. The club must keep these details up to date and tell us straight away if a check lapses or a concern arises.</p>'
        . '<h2>Insurance</h2><p>The club confirms it has suitable public liability insurance for the sessions booked through PlayMoreCricket.</p>'
        . '<h2>Removal</h2><p>We may pause or remove a club from the site if safeguarding or payment details are not kept up to date, or for serious complaints. The club may leave at any time by telling us; upcoming paid bookings must be honoured or refunded.</p>'
        . '<p class="muted">Questions: hello@playmorecricket.com</p>');
}

if ($path === '/enrol') {
    if ($method === 'POST') {
        csrf_check();
        $in = $_POST;
        foreach (($in['g_type'] ?? []) as $i => $x) { $in['g_type_' . $i] = $x; $in['g_member_' . $i] = $in['g_member'][$i] ?? ''; $in['g_non_' . $i] = $in['g_non'][$i] ?? ''; }
        foreach (($in['c_name'] ?? []) as $i => $x) { $in['c_name_' . $i] = $x; $in['c_price_' . $i] = $in['c_price'][$i] ?? ''; $in['c_qual_' . $i] = $in['c_qual'][$i] ?? ''; $in['c_other_' . $i] = $in['c_other'][$i] ?? ''; $in['c_email_' . $i] = $in['c_email'][$i] ?? ''; }
        [$ok, $msg] = enrol_create($_POST);
        if ($ok) { page('Application received', '<h1>Thank you</h1><p class="lead">We have emailed your safeguarding officer a private link to confirm the club\'s safeguarding details. When that is done we will review the application and let you know.</p><p><a href="/">Back to the home page</a></p>'); }
        flash($msg, true);
        page('Enrol your club', enrol_form_html($in));
    }
    page('Enrol your club', enrol_form_html());
}

if (preg_match('#^/verify/([0-9a-f]{48})$#', $path, $m)) {
    [$tok, $state] = token_lookup($m[1]);
    if ($state === 'invalid') { http_response_code(404); page('Link not found', '<h1>Link not found</h1><p>This link is not valid. Please use the link in the email, or ask hello@playmorecricket.com for a new one.</p>'); }
    if ($state === 'expired') { http_response_code(410); page('Link expired', '<h1>This link has expired</h1><p>Links work for ' . TOKEN_DAYS . ' days. Please email hello@playmorecricket.com and we will send a new one.</p>'); }
    $club = q('SELECT * FROM clubs WHERE id = ?', [$tok['club_id']])->fetch();
    if ($tok['role'] === 'second') { $ids = array_map('intval', explode(',', (string) $tok['coach_ids'])); $list = $ids ? q('SELECT * FROM coaches WHERE club_id = ? AND id IN (' . implode(',', $ids) . ') ORDER BY id', [$club['id']])->fetchAll() : []; }
    else { $list = q('SELECT * FROM coaches WHERE club_id = ? AND NOT is_safeguarding_officer AND active ORDER BY id', [$club['id']])->fetchAll(); }
    if ($method === 'POST') {
        csrf_check();
        $by = $tok['person_name']; $done = 0; $recorded = []; $today = new DateTimeImmutable('today', london()); $errs = [];
        if ($tok['role'] === 'officer' && !empty($_POST['policy_ok']) && empty($club['safeguarding_confirmed_at'])) {
            q('UPDATE clubs SET safeguarding_confirmed_at = ?, safeguarding_confirmed_by = ? WHERE id = ?', [now_utc(), $by, $club['id']]);
            audit(null, 'safeguarding_policy_confirmed', $club['slug'] . ' by ' . $by); $done++;
            $recorded[] = 'The club has a written safeguarding policy in place, and you are its safeguarding officer.';
        }
        foreach ($list as $co) {
            $cid = (int) $co['id']; $method_ = (string) ($_POST['m'][$cid] ?? ''); $date = (string) ($_POST['d'][$cid] ?? '');
            if ($method_ === '' || (int) $co['dbs_checked'] === 1) { continue; }
            $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date, london());
            if (!in_array($method_, ['certificate', 'update_service'], true) || !$dt || $dt > $today) { $errs[] = $co['name'] . ': choose how it was checked and a date that is not in the future.'; continue; }
            if (empty($_POST['e'][$cid])) { $errs[] = $co['name'] . ': coaches working with children need an Enhanced DBS check including the children\'s barred list. Tick the box if that is what you checked, otherwise leave this coach as not checked yet.'; continue; }
            record_dbs_confirmation($cid, (int) $tok['id'], $by, (string) $tok['sent_to'], $method_, $dt->format('Y-m-d'), true);
            audit(null, 'dbs_confirmed', $co['name'] . ' (' . $club['slug'] . ') by ' . $by . ' ' . $method_ . ' enhanced+barred'); $done++;
            $recorded[] = $co['name'] . ': ' . method_label($method_) . ' on ' . $dt->format('j F Y') . ', Enhanced check including the children\'s barred list' . (dbs_is_old($dt->format('Y-m-d')) ? ' (this check is over ' . DBS_OLD_YEARS . ' years old, so the administrator may ask for a fresh one)' : '');
        }
        q('UPDATE verification_tokens SET last_used_at = ? WHERE id = ?', [now_utc(), $tok['id']]);
        if ($recorded) {
            send_email((string) $tok['sent_to'], 'Your safeguarding confirmation for ' . $club['name'], 'Hello ' . $by . ",\n\nThis is a record of what you confirmed for " . $club['name'] . ' on ' . $today->format('j F Y') . ":\n\n- " . implode("\n- ", $recorded)
                . "\n\nWe never ask for or keep DBS certificate numbers. If anything here is wrong, reply to hello@playmorecricket.com straight away.\n\nPlayMoreCricket");
        }
        if ($done) { send_email(ADMIN_NOTICE_EMAIL, 'Safeguarding confirmation received: ' . $club['name'], $by . ' has confirmed details for ' . $club['name'] . ". Review in /admin.\n"); }
        flash($errs ? implode(' ', $errs) : ($done ? 'Thank you. Your confirmation has been recorded.' : 'Nothing was ticked, so nothing was recorded.'), (bool) $errs || !$done);
        redirect('/verify/' . $m[1]);
    }
    $club = q('SELECT * FROM clubs WHERE id = ?', [$tok['club_id']])->fetch();
    $b = '<h1>Safeguarding confirmation</h1><p class="lead">' . h($club['name']) . ' · for ' . h($tok['person_name']) . '</p>'
       . '<p class="muted">We never ask for or keep DBS certificate numbers. We record your name, the date and how you checked. The tick on the club\'s page means the club confirmed the check; PlayMoreCricket does not verify it independently.</p>'
       . '<form method="post">' . csrf_field();
    if ($tok['role'] === 'officer') {
        $pol = ''; foreach (explode(' | ', (string) $club['safeguarding_policy']) as $part) {
            if (preg_match('#^https?://\S+$#i', $part)) { $pol .= '<li>Web link: <a href="' . h($part) . '" target="_blank" rel="noopener noreferrer">' . h($part) . '</a></li>'; }
            elseif (str_starts_with($part, 'Uploaded file: ')) { $pol .= '<li>File you attached: ' . h(substr($part, 15)) . '</li>'; }
        }
        $b .= '<div class="card"><h2>Written safeguarding policy</h2>' . ($pol !== '' ? '<p>This is the policy the club gave us:</p><ul>' . $pol . '</ul>' : '') . (!empty($club['safeguarding_confirmed_at']) ? '<p>&#10003; Confirmed on ' . h(from_utc($club['safeguarding_confirmed_at'])->format('j F Y')) . '.</p>'
            : '<label class="check"><input type="checkbox" name="policy_ok" value="1"> I confirm that ' . h($club['name']) . ' has a written safeguarding policy in place, and that I am its safeguarding officer.</label>') . '</div>';
    }
    $b .= '<div class="card"><h2>Coaches</h2>';
    if (!$list) { $b .= '<p>No coaches need confirming.</p>'; }
    foreach ($list as $co) {
        $cid = (int) $co['id'];
        if ((int) $co['dbs_checked'] === 1) { $b .= '<p>&#10003; ' . h($co['name']) . ': ' . h(method_label($co['dbs_method'])) . ' on ' . h(date('j F Y', strtotime((string) $co['dbs_checked_on']))) . ' (' . h((string) $co['dbs_confirmed_by']) . ')</p>'; continue; }
        $b .= '<fieldset><legend>' . h($co['name']) . '</legend><label>How did you check?<select name="m[' . $cid . ']"><option value="">Not checked yet</option>'
            . '<option value="certificate">I have seen their DBS certificate</option><option value="update_service">I checked the DBS Update Service</option></select></label>'
            . '<label>Date checked<input type="date" name="d[' . $cid . ']" max="' . (new DateTimeImmutable('today', london()))->format('Y-m-d') . '"></label>'
            . '<label class="check"><input type="checkbox" name="e[' . $cid . ']" value="1"> This was an Enhanced DBS check including the children\'s barred list.</label>'
            . '<p class="muted">Checks dated more than ' . DBS_OLD_YEARS . ' years ago are flagged for review by the administrator.</p></fieldset>';
    }
    page('Safeguarding confirmation', $b . '</div><button type="submit">Save confirmation</button></form>');
}

if (preg_match('#^/club/([a-z0-9-]+)(/book|/release|/reserve)?$#', $path, $m)) {
    $club = club_by_slug($m[1]);
    if (!$club || ($club['status'] !== 'active' && !$club['sponsored_partner'] && !($user && ($user['role'] === 'admin' || can_staff($user, $club))))) { http_response_code(404); page('Not found', '<p>We could not find that club.</p>'); }
    $sub = $m[2] ?? '';
    if ($user && can_manage($user, $club) && refresh_stripe_status($club)) { $club = club_by_slug($m[1]); }
    $nets = club_nets((int) $club['id']);
    $netId = (int) ($_REQUEST['net'] ?? ($nets[0]['id'] ?? 0));
    $net = null; foreach ($nets as $n) { if ((int) $n['id'] === $netId) { $net = $n; } }
    $staff = can_staff($user, $club);
    $self = '/club/' . $club['slug'];
    // Visitors choose a type of net (outdoor / indoor) and the system picks a free net of that type.
    // Staff see one named net at a time.
    $types = net_types($nets);
    $type = null;
    if (!$staff && $types) { $type = (string) ($_REQUEST['type'] ?? ''); if (!in_array($type, $types, true)) { $type = $types[0]; } }
    $group = $type !== null ? nets_of_type($nets, $type) : ($net ? [$net] : []);
    $typeQ = $type !== null ? 'type=' . $type : 'net=' . $netId;

    if ($sub === '/release' && $method === 'POST') {
        csrf_check();
        if (!$user) { redirect('/login'); }
        [$ok, $msg] = release_booking($user, $club, (int) ($_POST['booking_id'] ?? 0));
        flash($msg, !$ok); redirect($self . '?net=' . $netId . '&week=' . urlencode((string) ($_POST['week'] ?? '')));
    }

    if ($sub === '/reserve') {
        if (!$net) { http_response_code(404); page('Not found', '<p>We could not find that net.</p>'); }
        $start = (string) ($_REQUEST['start'] ?? '');
        if ($type !== null) {
            $whenPick = DateTimeImmutable::createFromFormat('!Y-m-d\TH', $start, london());
            $picked = $whenPick ? free_net_at($group, $whenPick) : null;
            if (!$picked) { flash('Sorry, no ' . $type . ' net is free at that time. Please choose another hour.', true); redirect($self . '?type=' . $type); }
            $net = $picked; $netId = (int) $net['id'];
        }
        if (!online_booking_enabled($club)) { flash('Online booking is not open for this club yet. Please contact the club.', true); redirect($self); }
        if ($method === 'POST') {
            csrf_check();
            [$ok, $msg, $url] = visitor_reserve($club, $net, $start, $_POST);
            if ($ok) { header('Location: ' . $url, true, 303); exit; }
            flash($msg, true);
            $_SESSION['reserve_old'] = array_intersect_key($_POST, array_flip(['kind', 'coach_id', 'machine', 'customer_name', 'customer_email', 'customer_phone', 'junior', 'child_first_name', 'child_age']));
            redirect($self . '/reserve?' . $typeQ . '&start=' . urlencode($start));
        }
        $when = DateTimeImmutable::createFromFormat('!Y-m-d\TH', $start, london());
        if (!$when) { redirect($self); }
        $old = $_SESSION['reserve_old'] ?? []; unset($_SESSION['reserve_old']);
        if (!$old && ($_GET['kind'] ?? '') === 'coach') { $old = ['kind' => 'coach', 'coach_id' => (int) ($_GET['coach'] ?? 0)]; }
        $v = fn(string $k) => h((string) ($old[$k] ?? ''));
        [$freeC] = coaches_for_hour((int) $club['id'], $when);
        $co = ''; foreach ($freeC as $c) { $co .= '<option value="' . (int) $c['id'] . '"' . ((int) ($old['coach_id'] ?? 0) === (int) $c['id'] ? ' selected' : '') . '>' . h($c['name']) . ' · ' . h($c['qualification']) . ' · ' . money((int) $c['hourly_price_pence']) . '</option>'; }
        $b = '<h1>Book ' . h($type !== null ? ucfirst($type) . ' net' : $net['name']) . '</h1><p class="muted">' . h($when->format('l j F Y, H:i')) . ' · one hour · ' . h($club['name']) . '</p>'
           . '<form method="post" class="card narrow">' . csrf_field() . '<input type="hidden" name="net" value="' . $netId . '">' . ($type !== null ? '<input type="hidden" name="type" value="' . h($type) . '">' : '') . '<input type="hidden" name="start" value="' . h($start) . '">'
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
    $b = '<div class="clubhead">' . ($club['crest_path'] ? '<img src="/assets/' . h($club['crest_path']) . '" alt="" width="56" height="58">' : (q('SELECT 1 FROM club_crests WHERE club_id = ?', [$club['id']])->fetch() ? '<img src="/crest/' . h($club['slug']) . '" alt="" width="56" height="56">' : ''))
       . '<div><h1>' . h($club['name']) . '</h1><p class="muted">' . h($club['town']) . ', ' . h($club['county']) . ' ' . h((string) $club['postcode']) . '</p></div></div>';
    $coaches = club_coaches((int) $club['id']);
    if ($coaches) {
        $b .= '<h2>Coaches</h2><ul class="coaches">';
        foreach ($coaches as $c) {
            $yr = ($c['dbs_checked'] && $c['dbs_checked_on']) ? date('Y', strtotime((string) $c['dbs_checked_on'])) : '';
            $b .= '<li><div class="coach-main"><strong>' . h($c['name']) . '</strong>'
                . ($yr !== '' ? '<span class="dbs-right"><span class="tick" aria-hidden="true">✓</span> DBS checked ' . h($yr) . (!empty($c['dbs_confirmed_by']) ? ', confirmed by ' . h(preg_replace('/^Administrator: .*/', 'the club', (string) $c['dbs_confirmed_by'])) : '') . '</span>' : '') . '</div>'
                . '<div class="muted">' . h($c['qualification']) . ' · ' . money((int) $c['hourly_price_pence']) . '/hr</div></li>';
        }
        $b .= '</ul>';
    }
    if (!$group) { page($club['name'], $b . '<p>No nets are listed yet.</p>'); }
    apply_patterns_for_club((int) $club['id']);
    $avail = week_availability((int) $club['id'], $monday); $cbusy = week_coach_busy((int) $club['id'], $monday);
    $fcId = (int) ($_GET['fc'] ?? 0); $fcOnly = !empty($_GET['cf']) || $fcId > 0;
    if ($fcId && !isset(array_column($coaches, null, 'id')[$fcId])) { $fcId = 0; }
    $fq = ($fcOnly ? '&cf=1' : '') . ($fcId ? '&fc=' . $fcId : '');
    $nFree = function (string $key) use ($avail, $cbusy, $fcId): int { $n = 0; foreach ($avail as $cid => $hrs) { if ($fcId && $cid !== $fcId) { continue; } if (isset($hrs[$key]) && !isset($cbusy[$cid][$key])) { $n++; } } return $n; };
    $prev = $monday->modify('-7 days')->format('Y-m-d'); $next = $monday->modify('+7 days')->format('Y-m-d');
    $nonmember = array_map(fn($n) => (int) $n['nonmember_price_pence'], $group);
    $priceTxt = min($nonmember) === max($nonmember) ? money(min($nonmember)) : 'from ' . money(min($nonmember));
    $machineNet = null; foreach ($group as $gn) { if ($gn['machine_price_pence'] !== null) { $machineNet = $gn; break; } }
    // Choosers: visitors pick a type of net, staff pick a named net (only shown when there is a choice)
    $tabs = '';
    if ($type !== null && count($types) > 1) {
        foreach ($types as $t) { $tabs .= '<a href="' . $self . '?type=' . $t . '&week=' . $monday->format('Y-m-d') . $fq . '"' . ($t === $type ? ' class="on" aria-current="page"' : '') . '>' . ucfirst($t) . '</a> '; }
    } elseif ($type === null && count($nets) > 1) {
        foreach ($nets as $tn) { $tabs .= '<a href="' . $self . '?net=' . (int) $tn['id'] . '&week=' . $monday->format('Y-m-d') . $fq . '"' . ((int) $tn['id'] === $netId ? ' class="on" aria-current="page"' : '') . '>' . h($tn['name']) . '</a> '; }
    }
    $title = $type !== null ? ucfirst($type) . ' nets' : $net['name'];
    $b .= '<h2>' . h($title) . ' diary</h2>' . ($tabs !== '' ? '<p class="daytabs">' . $tabs . '</p>' : '')
        . '<p class="muted">Non-member ' . $priceTxt . '/hr'
        . ($machineNet ? ' · machine +' . money((int) $machineNet['machine_price_pence']) . ' with a coach' : '') . '</p>'
        . '<p class="weeknav"><a href="' . $self . '?' . $typeQ . '&week=' . $prev . $fq . '">← Previous week</a> <strong>Week of ' . $monday->format('j M Y') . '</strong> <a href="' . $self . '?' . $typeQ . '&week=' . $next . $fq . '">Next week →</a></p>';
    if ($coaches) {
        $fo = '<option value="0">Any coach</option>'; foreach ($coaches as $c) { $fo .= '<option value="' . (int) $c['id'] . '"' . ($fcId === (int) $c['id'] ? ' selected' : '') . '>' . h($c['name']) . '</option>'; }
        $b .= '<form method="get" class="card row coachfilter">' . ($type !== null ? '<input type="hidden" name="type" value="' . h($type) . '">' : '<input type="hidden" name="net" value="' . $netId . '">')
            . '<input type="hidden" name="week" value="' . h($monday->format('Y-m-d')) . '"><label class="check"><input type="checkbox" name="cf" value="1"' . ($fcOnly ? ' checked' : '') . '> Show only hours with a coach available</label>'
            . '<label>Coach<select name="fc">' . $fo . '</select></label><button type="submit">Apply</button></form>';
    }
    // Bookings and opening hours for every net in view
    $bkn = []; $hn = [];
    foreach ($group as $gn) {
        $gid = (int) $gn['id']; $bkn[$gid] = week_bookings($gid, $monday);
        for ($i = 0; $i < 7; $i++) { $hn[$gid][$i] = hours_for($gid, $monday->modify("+$i days")); }
    }
    // State of one hour: closed (no net open), taken (every open net booked) or free (with the first free net)
    $cell = function (int $i, int $h, string $key) use ($group, $bkn, $hn): array {
        $openAny = false; $firstTaken = null;
        foreach ($group as $gn) {
            $gid = (int) $gn['id']; $r = $hn[$gid][$i];
            if (!$r || $h < $r[0] || $h >= $r[1]) { continue; }
            $openAny = true;
            if (!isset($bkn[$gid][$key])) { return ['free', null, $gn]; }
            $firstTaken ??= $bkn[$gid][$key];
        }
        return $openAny ? ['taken', $firstTaken, null] : ['closed', null, null];
    };
    $minH = 24; $maxH = 0; $open = [];
    for ($i = 0; $i < 7; $i++) {
        $open[$i] = null;
        foreach ($group as $gn) {
            $r = $hn[(int) $gn['id']][$i];
            if ($r) { $open[$i] = $open[$i] ? [min($open[$i][0], $r[0]), max($open[$i][1], $r[1])] : $r; }
        }
        if ($open[$i]) { $minH = min($minH, $open[$i][0]); $maxH = max($maxH, $open[$i][1]); }
    }
    $manager = can_manage($user, $club); $mine = own_coach($user, $club); $now = new DateTimeImmutable('now', london()); $online = online_booking_enabled($club);
    $link = function (DateTimeImmutable $d, int $h, array $freeNet) use ($self, $staff, $type, $netId, $fcOnly, $fcId): string {
        $q = ($type !== null ? 'type=' . $type : 'net=' . (int) $freeNet['id']) . '&start=' . $d->format('Y-m-d') . 'T' . sprintf('%02d', $h) . (!$staff && $fcOnly ? '&kind=coach' . ($fcId ? '&coach=' . $fcId : '') : '');
        return $self . ($staff ? '/book' : '/reserve') . '?' . $q;
    };
    $coachTxt = function (string $key) use ($nFree): string { $n = $nFree($key); return $n ? '<small>' . $n . ' coach' . ($n > 1 ? 'es' : '') . '</small>' : ''; };
    $b .= '<div class="scroll"><table class="diary"><thead><tr><th></th>';
    for ($i = 0; $i < 7; $i++) { $b .= '<th>' . $monday->modify("+$i days")->format('D j') . '</th>'; }
    $b .= '</tr></thead><tbody>';
    for ($h = $minH; $h < $maxH; $h++) {
        $b .= '<tr><th>' . sprintf('%02d:00', $h) . '</th>';
        for ($i = 0; $i < 7; $i++) {
            $d = $monday->modify("+$i days"); $key = $d->format('Y-m-d') . ' ' . sprintf('%02d', $h);
            $past = $d->setTime($h, 0) <= $now;
            [$state, $x, $freeNet] = $cell($i, $h, $key);
            if ($state === 'closed') { $b .= '<td class="closed">–</td>'; continue; }
            if ($state === 'taken') {
                $label = $staff ? h(($x['booking_type'] === 'blocked' ? 'Blocked' : ($x['customer_name'] ?: ucfirst($x['booking_type'])))
                          . ($x['coach_name'] ? ' · ' . $x['coach_name'] : '')) : 'BOOKED';
                $canRel = $staff && ($manager || ($mine && (int) $x['coach_id'] === (int) $mine['id']));
                $b .= '<td class="taken">' . $label;
                if ($canRel && !$past) {
                    $b .= '<form method="post" action="' . $self . '/release">' . csrf_field() . '<input type="hidden" name="booking_id" value="' . (int) $x['id'] . '"><input type="hidden" name="net" value="' . $netId . '"><input type="hidden" name="week" value="' . $monday->format('Y-m-d') . '"><button class="link" type="submit">Release</button></form>';
                }
                $b .= '</td>';
            } elseif ($past) { $b .= '<td class="closed">–</td>'; }
            elseif ($fcOnly && !$nFree($key)) { $b .= '<td class="dim">–</td>'; }
            elseif ($staff || $online) { $b .= '<td class="free' . ($fcOnly ? ' coachy' : '') . '"><a href="' . $link($d, $h, $freeNet) . '">Book</a>' . ($coachTxt($key) !== '' ? '<br>' . $coachTxt($key) : '') . '</td>'; }
            else { $b .= '<td class="free' . ($fcOnly ? ' coachy' : '') . '">Free' . ($coachTxt($key) !== '' ? '<br>' . $coachTxt($key) : '') . '</td>'; }
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
        $b .= '<a href="' . $self . '?' . $typeQ . '&week=' . $monday->format('Y-m-d') . '&day=' . $dd->format('Y-m-d') . $fq . '"' . ($dd->format('Y-m-d') === $selDay->format('Y-m-d') ? ' class="on" aria-current="date"' : '') . '>' . $dd->format('D j') . '</a>';
    }
    $b .= '</p><h3>' . h($selDay->format('l j F')) . '</h3><ul class="plain slots">';
    $si = (int) $selDay->format('N') - 1; $any = false;
    if ($open[$si]) {
        for ($h = $open[$si][0]; $h < $open[$si][1]; $h++) {
            $key = $selDay->format('Y-m-d') . ' ' . sprintf('%02d', $h); $past = $selDay->setTime($h, 0) <= $now; if ($past) { continue; }
            [$state, $x, $freeNet] = $cell($si, $h, $key);
            if ($state === 'closed') { continue; }
            if ($fcOnly && $state === 'free' && !$nFree($key)) { continue; }
            $any = true; $lab = sprintf('%02d:00', $h);
            if ($state === 'taken') { $b .= '<li class="taken"><strong>' . $lab . '</strong> ' . ($staff ? h(($x['booking_type'] === 'blocked' ? 'Blocked' : ($x['customer_name'] ?: ucfirst($x['booking_type']))) . ($x['coach_name'] ? ' · ' . $x['coach_name'] : '')) : 'BOOKED') . '</li>'; }
            elseif ($staff || $online) { $b .= '<li class="free"><strong>' . $lab . '</strong> <a href="' . $link($selDay, $h, $freeNet) . '">Book</a> ' . $coachTxt($key) . '</li>'; }
            else { $b .= '<li class="free"><strong>' . $lab . '</strong> Free ' . $coachTxt($key) . '</li>'; }
        }
    }
    if (!$any) { $b .= '<li class="muted">Nothing available on this day.</li>'; }
    $b .= '</ul></div>';
    if (!$staff && $online) { $b .= '<p class="muted">Pick a free hour to book and pay online. Questions? ' . h((string) $club['phone']) . ' · ' . h((string) $club['email']) . '</p>'; }
    if (!$staff && !$online) { $b .= '<p class="muted">Online booking and payment for visitors is coming soon. Until then, contact the club: ' . h((string) $club['phone']) . ' · ' . h((string) $club['email']) . '</p>'; }
    if ($manager) {
        $state = empty($club['stripe_account_id']) ? 'Not set up yet' : ((int) $club['stripe_charges_enabled'] === 1 ? 'Payments are on' : 'Sign-up started, not finished');
        $b .= '<h2>Online payments</h2>' . ((int) $club['stripe_charges_enabled'] === 1 ? '' : '<div class="card">' . stripe_checklist_html() . '</div>') . '<form method="post" action="/stripe/connect" class="card row">' . csrf_field() . '<input type="hidden" name="club_id" value="' . (int) $club['id'] . '">'
            . '<span><strong>' . h($state) . '</strong><br><span class="muted">Customers pay the club directly through Stripe. Commission to PlayMoreCricket: ' . h(rtrim(rtrim((string) $club['commission_pct'], '0'), '.')) . '%.</span></span>'
            . ((int) $club['stripe_charges_enabled'] === 1 ? '' : '<button type="submit">' . (empty($club['stripe_account_id']) ? 'Set up payments' : 'Continue Stripe sign-up') . '</button>') . '</form>';
    }
    if ($manager) {
        $qopts = function (string $cur): string {
            $o = ''; foreach (QUALIFICATIONS as $qq) { if ($qq === '') { continue; } $o .= '<option value="' . h($qq) . '"' . ($qq === $cur ? ' selected' : '') . '>' . h($qq) . '</option>'; }
            return $o;
        };
        $b .= '<h2 id="coaches">Manage coaches</h2><p class="muted">Changes to a price apply to bookings made from now on. Changing a coach\'s name removes their DBS tick until the safeguarding officer confirms it again.</p>';
        foreach (q('SELECT * FROM coaches WHERE club_id = ? ORDER BY active DESC, name', [$club['id']])->fetchAll() as $mc) {
            $isKnown = in_array((string) $mc['qualification'], QUALIFICATIONS, true);
            $b .= '<form method="post" action="/coaches/manage" class="card">' . csrf_field() . '<input type="hidden" name="club_id" value="' . (int) $club['id'] . '"><input type="hidden" name="coach_id" value="' . (int) $mc['id'] . '">'
                . '<div class="row"><label>Name<input name="name" value="' . h($mc['name']) . '" required></label>'
                . '<label>&pound; per hour<input name="price" inputmode="decimal" size="5" value="' . h(number_format($mc['hourly_price_pence'] / 100, 2, '.', '')) . '" required></label>'
                . '<label>Qualification<select name="qual">' . $qopts($isKnown ? (string) $mc['qualification'] : 'Other') . '</select></label>'
                . '<label>If other, which<input name="qual_other" maxlength="100" value="' . h($isKnown ? '' : (string) $mc['qualification']) . '"></label>'
                . '<label>Email<input type="email" name="email" value="' . h((string) ($mc['email'] ?? '')) . '" required></label></div>'
                . '<p class="muted">' . ($mc['active'] ? ($mc['dbs_checked'] ? '&#10003; DBS confirmed' : '<strong>No DBS tick yet</strong>') : '<strong>Not currently coaching</strong>') . '</p>'
                . '<button type="submit" name="action" value="save">Save</button> '
                . (!$mc['dbs_checked'] && $mc['active'] ? '<button type="submit" name="action" value="confirmlink" class="secondary">Ask the safeguarding officer to confirm</button> ' : '')
                . ($mc['active'] ? '<button type="submit" name="action" value="deactivate" class="secondary">No longer coaching here</button>' : '<button type="submit" name="action" value="activate" class="secondary">Coaching here again</button>')
                . '</form>';
        }
        $b .= '<form method="post" action="/coaches/manage" class="card"><strong>Add a coach</strong>' . csrf_field() . '<input type="hidden" name="club_id" value="' . (int) $club['id'] . '"><input type="hidden" name="action" value="add">'
            . '<div class="row"><label>Name<input name="name" required></label><label>&pound; per hour<input name="price" inputmode="decimal" size="5" required></label>'
            . '<label>Qualification<select name="qual">' . $qopts('') . '</select></label><label>If other, which<input name="qual_other" maxlength="100"></label>'
            . '<label>Email<input type="email" name="email" required></label></div><button type="submit">Add coach</button></form>';
    }
    if ($manager) { $b .= calendar_box('club', (int) $club['id'], csrf_field(), '/club/' . $club['slug']); }
    page($club['name'], $b);
}

http_response_code(404);
page('Not found', '<p>That page does not exist.</p>');
