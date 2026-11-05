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

function login(string $email, string $password): bool
{
    $row = q('SELECT id, password_hash FROM users WHERE lower(email) = lower(?)', [trim($email)])->fetch();
    // Always run a hash check so a wrong email takes as long as a wrong password.
    $hash = $row['password_hash'] ?? '$2y$10$usesomesillystringforsaltuuQ1ZbQKQwNf4gL4h1mV2s3q9d6bK3j1m';
    $ok = password_verify($password, $hash) && $row;
    if (!$ok) {
        usleep(400000);
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
