<?php
declare(strict_types=1);

function h(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function money(int $pence): string
{
    return '£' . ($pence % 100 === 0 ? (string) intdiv($pence, 100) : number_format($pence / 100, 2));
}

function redirect(string $to): never
{
    header('Location: ' . $to, true, 303);
    exit;
}

function page(string $title, string $body): never
{
    $u = current_user();
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    echo '<!doctype html><html lang="en-GB"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
       . '<title>' . h($title) . ' · PlayMoreCricket</title>'
       . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500;12..96,700&family=Figtree:wght@400;500;600&display=swap">'
       . '<link rel="stylesheet" href="/assets/app.css"></head><body><div class="wrap">'
       . '<header class="top"><a class="brand" href="/">PlayMoreCricket</a><nav>';
    if ($u && $u['role'] === 'admin') { echo '<a href="/admin">Admin</a>'; }
    if ($u) {
        echo '<form method="post" action="/logout" class="inline">' . csrf_field() . '<button class="link" type="submit">Log out (' . h($u['name']) . ')</button></form>';
    } else {
        echo '<a href="/login">Log in</a>';
    }
    echo '</nav></header>';
    if ($flash) {
        echo '<div class="notice' . ($flash['error'] ? ' err' : '') . '" role="status">' . h($flash['msg']) . '</div>';
    }
    echo $body . '<footer class="foot">PlayMoreCricket · Find a net by the hour, add a coach for a session.</footer></div></body></html>';
    exit;
}
