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

if (in_array($path, ['/admin', '/admin/commission', '/admin/migrate', '/admin/users', '/admin/users/edit', '/admin/users/update', '/admin/stripe/disconnect', '/admin/applications/approve', '/admin/applications/resend', '/admin/applications/confirm', '/admin/coach/dbs'], true)) {
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
        if (!$club || $club['status'] !== 'pending') { flash('That application is not waiting for approval.', true); redirect('/admin'); }
        if (empty($club['safeguarding_confirmed_at'])) { flash('Not approved: the safeguarding officer has not yet confirmed the club\'s safeguarding policy.', true); redirect('/admin'); }
        $un = (int) q('SELECT COUNT(*) AS n FROM coaches WHERE club_id = ? AND NOT dbs_checked', [$club['id']])->fetch()['n'];
        if ($un > 0 && empty($_POST['allow_unconfirmed'])) { flash($un . ' coach(es) have no confirmed DBS check. Tick the box to approve anyway (they will show with no DBS tick), or wait.', true); redirect('/admin'); }
        q('UPDATE clubs SET status = ?, approved_at = ?, approved_by_user_id = ? WHERE id = ?', ['active', now_utc(), $user['id'], $club['id']]);
        audit((int) $user['id'], 'club_approved', $club['slug']);
        if (filter_var((string) $club['key_contact_detail'], FILTER_VALIDATE_EMAIL)) {
            send_email((string) $club['key_contact_detail'], $club['name'] . ' is now live on PlayMoreCricket', "Hello " . $club['key_contact_name'] . ",\n\nYour club is now live: " . $GLOBALS['cfg']['app_url'] . '/club/' . $club['slug'] . "\n\nPlayMoreCricket");
        }
        flash($club['name'] . ' is now live.'); redirect('/admin');
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
            q('UPDATE coaches SET dbs_checked = ?, dbs_checked_on = NULL, dbs_method = NULL, dbs_confirmed_by = NULL WHERE id = ?', [0, $co['id']]);
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
            . '<button type="submit">Save</button> <a href="/admin">Cancel</a></form>');
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
    send_due_reminders();
    $pend = q("SELECT * FROM clubs WHERE status = 'pending' AND submitted_at IS NOT NULL ORDER BY submitted_at")->fetchAll();
    if ($pend) {
        $b .= '<h2>Club applications waiting</h2>';
        foreach ($pend as $c) {
            $co = q('SELECT name, dbs_checked, dbs_method, dbs_confirmed_by, dbs_checked_on FROM coaches WHERE club_id = ? ORDER BY id', [$c['id']])->fetchAll();
            $b .= '<div class="card"><strong>' . h($c['name']) . '</strong> <span class="muted">' . h($c['town']) . ' · applied ' . h(from_utc($c['submitted_at'])->format('j M Y')) . '</span>'
                . '<p>Key contact: ' . h((string) $c['key_contact_name']) . ', ' . h((string) $c['key_contact_detail']) . '<br>Safeguarding officer: ' . h((string) $c['safeguarding_name']) . ', ' . h((string) $c['safeguarding_contact']) . '<br>Policy: ' . h((string) $c['safeguarding_policy']) . '</p>'
                . '<p>' . (!empty($c['safeguarding_confirmed_at']) ? '&#10003; Policy confirmed by ' . h((string) $c['safeguarding_confirmed_by']) . ' on ' . h(from_utc($c['safeguarding_confirmed_at'])->format('j M Y')) : 'Safeguarding policy: <strong>not yet confirmed</strong>') . '</p><ul>';
            foreach ($co as $x) {
                $b .= '<li>' . h($x['name']) . ': ' . ($x['dbs_checked'] ? '&#10003; ' . h(method_label($x['dbs_method'])) . ' on ' . h(date('j M Y', strtotime((string) $x['dbs_checked_on']))) . ' (confirmed by ' . h((string) $x['dbs_confirmed_by']) . ')' : '<strong>not yet confirmed</strong>') . '</li>';
            }
            $b .= '</ul><form method="post" action="/admin/applications/approve" class="row">' . csrf_field() . '<input type="hidden" name="club_id" value="' . (int) $c['id'] . '">'
                . '<label class="check"><input type="checkbox" name="allow_unconfirmed" value="1"> Approve even if some coaches are unconfirmed</label><button type="submit">Approve and publish</button></form>'
                . '<form method="post" action="/admin/applications/resend" class="row">' . csrf_field() . '<input type="hidden" name="club_id" value="' . (int) $c['id'] . '"><button type="submit">Send the officer a new link</button></form>'
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
        foreach (q('SELECT id, name, dbs_checked, dbs_checked_on, dbs_method, dbs_confirmed_by FROM coaches WHERE club_id = ? ORDER BY name', [$c['id']])->fetchAll() as $x) {
            $b .= '<div class="row"><strong>' . h($x['name']) . '</strong> <span class="muted">' . ($x['dbs_checked'] ? '&#10003; ' . h(method_label($x['dbs_method'])) . ' on ' . h(date('j M Y', strtotime((string) $x['dbs_checked_on']))) . ', ' . h((string) $x['dbs_confirmed_by']) : 'no confirmed check') . '</span>'
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

if ($path === '/enrol') {
    if ($method === 'POST') {
        csrf_check();
        $in = $_POST;
        foreach (($in['g_num'] ?? []) as $i => $x) { $in['g_num_' . $i] = $x; $in['g_type_' . $i] = $in['g_type'][$i] ?? ''; $in['g_member_' . $i] = $in['g_member'][$i] ?? ''; $in['g_non_' . $i] = $in['g_non'][$i] ?? ''; }
        foreach (($in['c_name'] ?? []) as $i => $x) { $in['c_name_' . $i] = $x; $in['c_price_' . $i] = $in['c_price'][$i] ?? ''; $in['c_qual_' . $i] = $in['c_qual'][$i] ?? ''; $in['c_other_' . $i] = $in['c_other'][$i] ?? ''; }
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
        $by = $tok['person_name']; $done = 0; $today = new DateTimeImmutable('today', london()); $errs = [];
        if ($tok['role'] === 'officer' && !empty($_POST['policy_ok']) && empty($club['safeguarding_confirmed_at'])) {
            q('UPDATE clubs SET safeguarding_confirmed_at = ?, safeguarding_confirmed_by = ? WHERE id = ?', [now_utc(), $by, $club['id']]);
            audit(null, 'safeguarding_policy_confirmed', $club['slug'] . ' by ' . $by); $done++;
        }
        foreach ($list as $co) {
            $cid = (int) $co['id']; $method_ = (string) ($_POST['m'][$cid] ?? ''); $date = (string) ($_POST['d'][$cid] ?? '');
            if ($method_ === '' || (int) $co['dbs_checked'] === 1) { continue; }
            $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date, london());
            if (!in_array($method_, ['certificate', 'update_service'], true) || !$dt || $dt > $today) { $errs[] = $co['name'] . ': choose how it was checked and a date that is not in the future.'; continue; }
            record_dbs_confirmation($cid, (int) $tok['id'], $by, (string) $tok['sent_to'], $method_, $dt->format('Y-m-d'));
            audit(null, 'dbs_confirmed', $co['name'] . ' (' . $club['slug'] . ') by ' . $by . ' ' . $method_); $done++;
        }
        q('UPDATE verification_tokens SET last_used_at = ? WHERE id = ?', [now_utc(), $tok['id']]);
        if ($done) { send_email(ADMIN_NOTICE_EMAIL, 'Safeguarding confirmation received: ' . $club['name'], $by . ' has confirmed details for ' . $club['name'] . ". Review in /admin.\n"); }
        flash($errs ? implode(' ', $errs) : ($done ? 'Thank you. Your confirmation has been recorded.' : 'Nothing was ticked, so nothing was recorded.'), (bool) $errs || !$done);
        redirect('/verify/' . $m[1]);
    }
    $club = q('SELECT * FROM clubs WHERE id = ?', [$tok['club_id']])->fetch();
    $b = '<h1>Safeguarding confirmation</h1><p class="lead">' . h($club['name']) . ' · for ' . h($tok['person_name']) . '</p>'
       . '<p class="muted">We never ask for or keep DBS certificate numbers. We record your name, the date and how you checked. The tick on the club\'s page means the club confirmed the check; PlayMoreCricket does not verify it independently.</p>'
       . '<form method="post">' . csrf_field();
    if ($tok['role'] === 'officer') {
        $b .= '<div class="card"><h2>Written safeguarding policy</h2>' . (!empty($club['safeguarding_confirmed_at']) ? '<p>&#10003; Confirmed on ' . h(from_utc($club['safeguarding_confirmed_at'])->format('j F Y')) . '.</p>'
            : '<label class="check"><input type="checkbox" name="policy_ok" value="1"> I confirm that ' . h($club['name']) . ' has a written safeguarding policy in place, and that I am its safeguarding officer.</label>') . '</div>';
    }
    $b .= '<div class="card"><h2>Coaches</h2>';
    if (!$list) { $b .= '<p>No coaches need confirming.</p>'; }
    foreach ($list as $co) {
        $cid = (int) $co['id'];
        if ((int) $co['dbs_checked'] === 1) { $b .= '<p>&#10003; ' . h($co['name']) . ': ' . h(method_label($co['dbs_method'])) . ' on ' . h(date('j F Y', strtotime((string) $co['dbs_checked_on']))) . ' (' . h((string) $co['dbs_confirmed_by']) . ')</p>'; continue; }
        $b .= '<fieldset><legend>' . h($co['name']) . '</legend><label>How did you check?<select name="m[' . $cid . ']"><option value="">Not checked yet</option>'
            . '<option value="certificate">I have seen their DBS certificate</option><option value="update_service">I checked the DBS Update Service</option></select></label>'
            . '<label>Date checked<input type="date" name="d[' . $cid . ']" max="' . (new DateTimeImmutable('today', london()))->format('Y-m-d') . '"></label></fieldset>';
    }
    page('Safeguarding confirmation', $b . '</div><button type="submit">Save confirmation</button></form>');
}

if (preg_match('#^/club/([a-z0-9-]+)(/book|/release|/reserve)?$#', $path, $m)) {
    $club = club_by_slug($m[1]);
    if (!$club || ($club['status'] !== 'active' && !$club['sponsored_partner'] && !($user && ($user['role'] === 'admin' || can_staff($user, $club))))) { http_response_code(404); page('Not found', '<p>We could not find that club.</p>'); }
    $sub = $m[2] ?? '';
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
    $b = '<div class="clubhead">' . ($club['crest_path'] ? '<img src="/assets/' . h($club['crest_path']) . '" alt="" width="56" height="58">' : '')
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
    $avail = week_availability((int) $club['id'], $monday); $cbusy = week_coach_busy((int) $club['id'], $monday);
    $nFree = function (string $key) use ($avail, $cbusy): int { $n = 0; foreach ($avail as $cid => $hrs) { if (isset($hrs[$key]) && !isset($cbusy[$cid][$key])) { $n++; } } return $n; };
    $prev = $monday->modify('-7 days')->format('Y-m-d'); $next = $monday->modify('+7 days')->format('Y-m-d');
    $nonmember = array_map(fn($n) => (int) $n['nonmember_price_pence'], $group);
    $priceTxt = min($nonmember) === max($nonmember) ? money(min($nonmember)) : 'from ' . money(min($nonmember));
    $machineNet = null; foreach ($group as $gn) { if ($gn['machine_price_pence'] !== null) { $machineNet = $gn; break; } }
    // Choosers: visitors pick a type of net, staff pick a named net (only shown when there is a choice)
    $tabs = '';
    if ($type !== null && count($types) > 1) {
        foreach ($types as $t) { $tabs .= '<a href="' . $self . '?type=' . $t . '&week=' . $monday->format('Y-m-d') . '"' . ($t === $type ? ' class="on" aria-current="page"' : '') . '>' . ucfirst($t) . '</a> '; }
    } elseif ($type === null && count($nets) > 1) {
        foreach ($nets as $tn) { $tabs .= '<a href="' . $self . '?net=' . (int) $tn['id'] . '&week=' . $monday->format('Y-m-d') . '"' . ((int) $tn['id'] === $netId ? ' class="on" aria-current="page"' : '') . '>' . h($tn['name']) . '</a> '; }
    }
    $title = $type !== null ? ucfirst($type) . ' nets' : $net['name'];
    $b .= '<h2>' . h($title) . ' diary</h2>' . ($tabs !== '' ? '<p class="daytabs">' . $tabs . '</p>' : '')
        . '<p class="muted">Non-member ' . $priceTxt . '/hr'
        . ($machineNet ? ' · machine +' . money((int) $machineNet['machine_price_pence']) . ' with a coach' : '') . '</p>'
        . '<p class="weeknav"><a href="' . $self . '?' . $typeQ . '&week=' . $prev . '">← Previous week</a> <strong>Week of ' . $monday->format('j M Y') . '</strong> <a href="' . $self . '?' . $typeQ . '&week=' . $next . '">Next week →</a></p>';
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
    $link = function (DateTimeImmutable $d, int $h, array $freeNet) use ($self, $staff, $type, $netId): string {
        $q = ($type !== null ? 'type=' . $type : 'net=' . (int) $freeNet['id']) . '&start=' . $d->format('Y-m-d') . 'T' . sprintf('%02d', $h);
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
            elseif ($staff || $online) { $b .= '<td class="free"><a href="' . $link($d, $h, $freeNet) . '">Book</a>' . ($coachTxt($key) !== '' ? '<br>' . $coachTxt($key) : '') . '</td>'; }
            else { $b .= '<td class="free">Free' . ($coachTxt($key) !== '' ? '<br>' . $coachTxt($key) : '') . '</td>'; }
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
        $b .= '<a href="' . $self . '?' . $typeQ . '&week=' . $monday->format('Y-m-d') . '&day=' . $dd->format('Y-m-d') . '"' . ($dd->format('Y-m-d') === $selDay->format('Y-m-d') ? ' class="on" aria-current="date"' : '') . '>' . $dd->format('D j') . '</a>';
    }
    $b .= '</p><h3>' . h($selDay->format('l j F')) . '</h3><ul class="plain slots">';
    $si = (int) $selDay->format('N') - 1; $any = false;
    if ($open[$si]) {
        for ($h = $open[$si][0]; $h < $open[$si][1]; $h++) {
            $key = $selDay->format('Y-m-d') . ' ' . sprintf('%02d', $h); $past = $selDay->setTime($h, 0) <= $now; if ($past) { continue; }
            [$state, $x, $freeNet] = $cell($si, $h, $key);
            if ($state === 'closed') { continue; }
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
        $b .= '<h2>Online payments</h2><form method="post" action="/stripe/connect" class="card row">' . csrf_field() . '<input type="hidden" name="club_id" value="' . (int) $club['id'] . '">'
            . '<span><strong>' . h($state) . '</strong><br><span class="muted">Customers pay the club directly through Stripe. Commission to PlayMoreCricket: ' . h(rtrim(rtrim((string) $club['commission_pct'], '0'), '.')) . '%.</span></span>'
            . ((int) $club['stripe_charges_enabled'] === 1 ? '' : '<button type="submit">' . (empty($club['stripe_account_id']) ? 'Set up payments' : 'Continue Stripe sign-up') . '</button>') . '</form>';
    }
    page($club['name'], $b);
}

http_response_code(404);
page('Not found', '<p>That page does not exist.</p>');
