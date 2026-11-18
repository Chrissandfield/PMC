<?php
declare(strict_types=1);

function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = null;
        if (!empty($_SESSION['uid'])) {
            $user = q('SELECT id, email, name, role, club_id FROM users WHERE id = ?', [$_SESSION['uid']])->fetch() ?: null;
        }
    }
    return $user;
}

const LOGIN_MAX_FAILS_EMAIL = 5;
const LOGIN_MAX_FAILS_IP = 20;
const LOGIN_WINDOW_MINUTES = 15;

function client_ip(): string { return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45); }

/** Too many recent failed logins for this email or from this address? */
function login_locked(string $email): bool
{
    $since = utc_string((new DateTimeImmutable('now', london()))->modify('-' . LOGIN_WINDOW_MINUTES . ' minutes'));
    $e = (int) q('SELECT COUNT(*) AS n FROM login_failures WHERE lower(email) = lower(?) AND attempted_at >= ?', [trim($email), $since])->fetch()['n'];
    $i = (int) q('SELECT COUNT(*) AS n FROM login_failures WHERE ip = ? AND attempted_at >= ?', [client_ip(), $since])->fetch()['n'];
    return $e >= LOGIN_MAX_FAILS_EMAIL || $i >= LOGIN_MAX_FAILS_IP;
}

function login(string $email, string $password): bool
{
    $row = q('SELECT id, password_hash FROM users WHERE lower(email) = lower(?)', [trim($email)])->fetch();
    // Always run a hash check so a wrong email takes as long as a wrong password.
    $hash = $row['password_hash'] ?? '$2y$10$usesomesillystringforsaltuuQ1ZbQKQwNf4gL4h1mV2s3q9d6bK3j1m';
    $ok = password_verify($password, $hash) && $row;
    if (!$ok) {
        usleep(400000);
        q('INSERT INTO login_failures (email, ip, attempted_at) VALUES (?,?,?)', [substr(trim($email), 0, 190), client_ip(), utc_string(new DateTimeImmutable('now', london()))]);
        audit(null, 'login_failed', substr(trim($email), 0, 120));
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $row['id'];
    audit((int) $row['id'], 'login');
    return true;
}

function logout(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        page('Request not accepted', '<p>That form has expired or is not valid. Go back, refresh the page and try again.</p>');
    }
}

function flash(string $msg, bool $error = false): void
{
    $_SESSION['flash'] = ['msg' => $msg, 'error' => $error];
}

function require_login(): array
{
    $u = current_user();
    if (!$u) { redirect('/login'); }
    return $u;
}

function require_admin(): array
{
    $u = require_login();
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        page('Not allowed', '<p>This area is for administrators only.</p>');
    }
    return $u;
}

/** May this user see names and make bookings at this club? */
function can_staff(?array $u, array $club): bool
{
    return $u !== null && ($u['role'] === 'admin' || ((int) $u['club_id'] === (int) $club['id'] && in_array($u['role'], ['club_contact', 'coach'], true)));
}

/** May this user book for anyone and release any booking at this club? */
function can_manage(?array $u, array $club): bool
{
    return $u !== null && ($u['role'] === 'admin' || ((int) $u['club_id'] === (int) $club['id'] && $u['role'] === 'club_contact'));
}

/** The coach record belonging to a coach login at this club, if any. */
function own_coach(?array $u, array $club): ?array
{
    if ($u === null || $u['role'] !== 'coach') { return null; }
    return q('SELECT * FROM coaches WHERE user_id = ? AND club_id = ?', [$u['id'], $club['id']])->fetch() ?: null;
}


// ---- One-time password links (first set-up and "forgot password")

const PW_MIN = 10;
const PW_SETUP_HOURS = 168;   // a new login's link works for 7 days
const PW_RESET_HOURS = 2;

function password_token_make(int $userId, string $purpose, int $hours): string
{
    $raw = bin2hex(random_bytes(24));
    q('INSERT INTO password_tokens (user_id, token_hash, purpose, expires_at) VALUES (?,?,?,?)',
      [$userId, hash('sha256', $raw), $purpose, utc_string((new DateTimeImmutable('now', london()))->modify("+$hours hours"))]);
    return $raw;
}

function password_link(string $raw): string { return $GLOBALS['cfg']['app_url'] . '/set-password/' . $raw; }

/** Returns the token row if the raw token is valid, unused and not expired, else null. */
function password_token_lookup(string $raw): ?array
{
    if (!preg_match('/^[0-9a-f]{48}$/', $raw)) { return null; }
    $r = q('SELECT * FROM password_tokens WHERE token_hash = ?', [hash('sha256', $raw)])->fetch();
    if (!$r || !empty($r['used_at']) || $r['expires_at'] < utc_string(new DateTimeImmutable('now', london()))) { return null; }
    return $r;
}

/** Email the one-time link. $setup = a brand-new login (longer wording and validity), otherwise a reset. */
function send_password_link(array $user, bool $setup, ?string $clubName = null): string
{
    $raw = password_token_make((int) $user['id'], $setup ? 'setup' : 'reset', $setup ? PW_SETUP_HOURS : PW_RESET_HOURS);
    $link = password_link($raw);
    if ($setup) {
        send_email((string) $user['email'], 'Set up your PlayMoreCricket login' . ($clubName ? ' for ' . $clubName : ''),
            "Hello {$user['name']},\n\n" . ($clubName ? "$clubName is on PlayMoreCricket. " : '') . "Please choose a password for your login using this private link (it works for 7 days):\n\n$link\n\nYour login email is {$user['email']}. "
            . "If the link has expired, go to " . $GLOBALS['cfg']['app_url'] . "/forgot and enter that email to get a new one.\n\nPlayMoreCricket");
    } else {
        send_email((string) $user['email'], 'Reset your PlayMoreCricket password',
            "Hello {$user['name']},\n\nSomeone (hopefully you) asked to reset the password for this login. Use this private link within " . PW_RESET_HOURS . " hours:\n\n$link\n\n"
            . "If you did not ask for this, ignore this email: your password has not changed.\n\nPlayMoreCricket");
    }
    return $link;
}

/** Create logins for a club's key contact and each coach with an email, and email each a set-up link. Returns [created, skippedNotes]. */
function create_club_logins(array $club): array
{
    $made = 0; $notes = [];
    $findOrMake = function (string $email, string $name, string $role) use ($club, &$made, &$notes): ?array {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { return null; }
        $u = q('SELECT id, email, name, role, club_id FROM users WHERE lower(email) = lower(?)', [$email])->fetch();
        if ($u) { $notes[] = "$email already had a login"; return $u; }
        q('INSERT INTO users (email, password_hash, name, role, club_id) VALUES (?,?,?,?,?)', [$email, password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), $name, $role, $club['id']]);
        $u = q('SELECT id, email, name, role, club_id FROM users WHERE lower(email) = lower(?)', [$email])->fetch();
        send_password_link($u, true, (string) $club['name']);
        audit(null, 'login_created', $email . ' ' . $role . ' (' . $club['slug'] . ')');
        $made++;
        return $u;
    };
    $kc = $findOrMake((string) $club['key_contact_detail'], (string) $club['key_contact_name'], 'club_contact');
    foreach (q('SELECT id, name, email FROM coaches WHERE club_id = ? AND active AND user_id IS NULL AND email IS NOT NULL', [$club['id']])->fetchAll() as $co) {
        if ($kc && strcasecmp((string) $co['email'], (string) $kc['email']) === 0) { q('UPDATE coaches SET user_id = ? WHERE id = ?', [$kc['id'], $co['id']]); continue; }
        $cu = $findOrMake((string) $co['email'], (string) $co['name'], 'coach');
        if ($cu && $cu['role'] === 'coach' && (int) $cu['club_id'] === (int) $club['id']) { q('UPDATE coaches SET user_id = ? WHERE id = ? AND user_id IS NULL', [$cu['id'], $co['id']]); }
    }
    return [$made, $notes];
}
