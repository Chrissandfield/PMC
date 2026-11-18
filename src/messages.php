<?php
declare(strict_types=1);
// Messages to players who agreed to hear from us (or from a club). Only people currently opted in to the chosen list
// receive anything; withdrawals are re-checked at the moment each batch is sent. Every message carries an unsubscribe link.

const MESSAGE_BATCH = 50;        // sent per click
const MESSAGE_CRON_BATCH = 200;  // sent per daily job run

function scope_label(string $scope): string
{
    if ($scope === 'pmc') { return 'PlayMoreCricket and clubs (news and updates)'; }
    if ($scope === 'events') { return 'Cricket activities, events and offers'; }
    if (preg_match('/^club-(\d+)$/', $scope, $m)) { $c = q('SELECT name FROM clubs WHERE id = ?', [(int) $m[1]])->fetch(); return ($c['name'] ?? 'Club') . ' (club players)'; }
    return $scope;
}

/** Which lists this user may write to. */
function sendable_scopes(array $u): array
{
    $out = [];
    if ($u['role'] === 'admin') {
        $out['pmc'] = scope_label('pmc'); $out['events'] = scope_label('events');
        foreach (q('SELECT id FROM clubs ORDER BY name')->fetchAll() as $c) { $out['club-' . $c['id']] = scope_label('club-' . $c['id']); }
    } elseif (!empty($u['club_id'])) {
        $club = q('SELECT * FROM clubs WHERE id = ?', [(int) $u['club_id']])->fetch();
        if ($club && can_manage($u, $club)) { $out['club-' . $club['id']] = scope_label('club-' . $club['id']); }
    }
    return $out;
}

function message_footer(string $scope, string $email): string
{
    $why = $scope === 'pmc' ? 'You agreed to hear news and updates from PlayMoreCricket and clubs on PlayMoreCricket.'
         : ($scope === 'events' ? 'You agreed to hear about cricket activities, events and offers.'
         : 'You agreed to hear from ' . preg_replace('/ \(club players\)$/', '', scope_label($scope)) . ' when you booked through PlayMoreCricket.');
    return "\n\n--\n{$why}\nTo stop these emails: " . unsubscribe_link($email) . "\nPlayMoreCricket, hello@playmorecricket.com";
}

function message_text(string $scope, string $name, string $body, string $email): string
{
    $first = trim(explode(' ', trim($name))[0] ?? '');
    return ($first !== '' ? "Hello $first,\n\n" : "Hello,\n\n") . $body . message_footer($scope, $email);
}

function message_send_one(string $scope, string $email, string $name, string $subject, string $body): void
{
    send_email($email, $subject, message_text($scope, $name, $body, $email), null, 'List-Unsubscribe: <' . unsubscribe_link($email) . ">");
}

function queue_campaign(string $scope, string $subject, string $body, int $userId): int
{
    $list = marketing_list($scope);
    q('INSERT INTO marketing_campaigns (scope, subject, body, created_by, total) VALUES (?,?,?,?,?)', [$scope, $subject, $body, $userId, count($list)]);
    $id = (int) q('SELECT MAX(id) AS i FROM marketing_campaigns WHERE created_by = ?', [$userId])->fetch()['i'];
    foreach ($list as $r) { q('INSERT INTO marketing_queue (campaign_id, email, name) VALUES (?,?,?)', [$id, $r['email'], $r['name']]); }
    audit($userId, 'campaign_created', $scope . ' to ' . count($list) . ' people');
    return $id;
}

/** Send up to $limit queued recipients. Anyone who has withdrawn since the campaign was created is skipped. */
function send_campaign_batch(int $campaignId, int $limit = MESSAGE_BATCH): int
{
    $c = q('SELECT * FROM marketing_campaigns WHERE id = ?', [$campaignId])->fetch();
    if (!$c) { return 0; }
    $rows = q("SELECT * FROM marketing_queue WHERE campaign_id = ? AND status = 'queued' ORDER BY id LIMIT " . (int) $limit, [$campaignId])->fetchAll();
    $sent = 0;
    foreach ($rows as $r) {
        $still = q('SELECT 1 FROM marketing_optins WHERE email = ? AND scope = ? AND withdrawn_at IS NULL', [mb_strtolower($r['email']), $c['scope']])->fetch();
        if (!$still) { q("UPDATE marketing_queue SET status = 'skipped', done_at = ? WHERE id = ?", [now_utc(), $r['id']]); continue; }
        message_send_one($c['scope'], $r['email'], $r['name'], $c['subject'], $c['body']);
        q("UPDATE marketing_queue SET status = 'sent', done_at = ? WHERE id = ?", [now_utc(), $r['id']]);
        $sent++;
    }
    return $sent;
}

/** Daily job: carry on with any campaign that still has people waiting. */
function send_pending_campaigns(): int
{
    $left = MESSAGE_CRON_BATCH; $total = 0;
    foreach (q("SELECT DISTINCT campaign_id FROM marketing_queue WHERE status = 'queued' ORDER BY campaign_id")->fetchAll() as $r) {
        if ($left <= 0) { break; }
        $n = send_campaign_batch((int) $r['campaign_id'], $left); $left -= $n; $total += $n;
    }
    return $total;
}

function campaign_progress(int $campaignId): array
{
    $o = ['queued' => 0, 'sent' => 0, 'skipped' => 0];
    foreach (q('SELECT status, COUNT(*) AS n FROM marketing_queue WHERE campaign_id = ? GROUP BY status', [$campaignId])->fetchAll() as $r) { $o[$r['status']] = (int) $r['n']; }
    return $o;
}
