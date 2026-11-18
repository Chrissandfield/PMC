<?php
declare(strict_types=1);

/*
 * Club applications and safeguarding verification (decided 16 Nov 2026).
 * 1. A club applies on /enrol. It is saved as 'pending' and is not visible to the public.
 * 2. The club's safeguarding officer gets a private link (/verify/<token>), valid 14 days.
 *    They confirm the written safeguarding policy and, for each coach, that they saw the DBS certificate
 *    or checked the DBS Update Service, with the date. No certificate numbers are collected or stored.
 * 3. If the officer is also a coach, the key contact gets a second link for that coach only.
 * 4. An administrator reviews the application in /admin and presses "Approve and publish".
 */

const TOKEN_DAYS = 14;
const REMINDER_AFTER_DAYS = 3;
const ADMIN_NOTICE_EMAIL = 'hello@playmorecricket.com';

const CREST_MAX_BYTES = 1048576;
const CREST_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

const DOC_MAX_BYTES = 2097152;

const QUALIFICATIONS = ['', 'ECB Support Coach (Level 1)', 'ECB Foundation Coach (Level 2)', 'ECB Core Coach (Level 3)', 'ECB Advanced Coach (Level 4)', 'ECB Specialist Coach (Level 5)', 'None (no formal qualification)', 'Other'];

function slugify(string $name): string
{
    $s = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', iconv('UTF-8', 'ASCII//TRANSLIT', $name) ?: $name), '-'));
    return $s !== '' ? mb_substr($s, 0, 70) : 'club';
}

function unique_slug(string $name): string
{
    $base = slugify($name); $slug = $base; $i = 2;
    while (q('SELECT 1 FROM clubs WHERE slug = ?', [$slug])->fetch()) { $slug = $base . '-' . $i++; }
    return $slug;
}

/** Check an uploaded crest. Returns [error|null, mime|null, base64|null]. No file at all is fine. */
function crest_from_upload(?array $f): array
{
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { return [null, null, null]; }
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']) && getenv('PMC_TEST_UPLOADS') !== '1') { return ['The crest could not be uploaded. Please try a smaller file.', null, null]; }
    if ($f['size'] > CREST_MAX_BYTES) { return ['The crest is too big. Please use a picture under 1 MB.', null, null]; }
    $raw = (string) file_get_contents($f['tmp_name']);
    $info = @getimagesizefromstring($raw);
    if (!$info || !isset(CREST_TYPES[$info['mime']]) || $info[0] < 16 || $info[1] < 16 || $info[0] > 4000 || $info[1] > 4000) {
        return ['The crest must be a PNG, JPG or WebP picture (not a PDF or Word file).', null, null];
    }
    return [null, $info['mime'], base64_encode($raw)];
}

/** Check an uploaded policy document (PDF, Word). Returns [error|null, mime|null, base64|null, cleanName|null]. */
function document_from_upload(?array $f): array
{
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { return [null, null, null, null]; }
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || ($f['size'] ?? 0) > DOC_MAX_BYTES) { return ['The policy file is too big. Please use a file under 2 MB, or put it online and send the link.', null, null, null]; }
    if ($f['error'] !== UPLOAD_ERR_OK || (!is_uploaded_file($f['tmp_name']) && getenv('PMC_TEST_UPLOADS') !== '1')) { return ['The policy file could not be uploaded. Please try again.', null, null, null]; }
    $raw = (string) file_get_contents($f['tmp_name']);
    $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
    $mime = null;
    if (str_starts_with($raw, '%PDF') && $ext === 'pdf') { $mime = 'application/pdf'; }
    elseif (str_starts_with($raw, "PK\x03\x04") && $ext === 'docx') { $mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'; }
    elseif (str_starts_with($raw, "\xD0\xCF\x11\xE0") && $ext === 'doc') { $mime = 'application/msword'; }
    if ($mime === null) { return ['The policy must be a PDF or Word file (.pdf, .docx or .doc).', null, null, null]; }
    $clean = mb_substr(preg_replace('/[^A-Za-z0-9._ -]+/', '_', basename((string) $f['name'])), 0, 120);
    return [null, $mime, base64_encode($raw), $clean];
}

function pence_from(string $pounds): ?int
{
    if ($pounds === '' || !is_numeric($pounds) || (float) $pounds < 0 || (float) $pounds > 1000) { return null; }
    return (int) round((float) $pounds * 100);
}

/** Make a private link. Only its hash is stored. Returns the raw token to put in an email. */
function make_token(int $clubId, string $role, string $name, string $email, array $coachIds = []): string
{
    $raw = bin2hex(random_bytes(24));
    $expires = utc_string((new DateTimeImmutable('now', london()))->modify('+' . TOKEN_DAYS . ' days'));
    q('INSERT INTO verification_tokens (club_id, token_hash, role, person_name, sent_to, coach_ids, expires_at) VALUES (?,?,?,?,?,?,?)',
      [$clubId, hash('sha256', $raw), $role, $name, $email, $coachIds ? implode(',', $coachIds) : null, $expires]);
    return $raw;
}

function verify_link(string $raw): string { return $GLOBALS['cfg']['app_url'] . '/verify/' . $raw; }

/** Look up a link. Returns [row|null, 'ok'|'invalid'|'expired']. */
function token_lookup(string $raw): array
{
    if (!preg_match('/^[0-9a-f]{48}$/', $raw)) { return [null, 'invalid']; }
    $row = q('SELECT * FROM verification_tokens WHERE token_hash = ?', [hash('sha256', $raw)])->fetch();
    if (!$row) { return [null, 'invalid']; }
    if ($row['expires_at'] < now_utc()) { return [$row, 'expired']; }
    return [$row, 'ok'];
}

function email_officer_link(array $club, string $role, string $name, string $email, string $raw, array $coachNames): void
{
    $link = verify_link($raw);
    $list = $coachNames ? "\nCoaches to confirm:\n- " . implode("\n- ", $coachNames) . "\n" : '';
    if ($role === 'officer') {
        $body = "Hello $name,\n\n{$club['name']} has applied to be listed on PlayMoreCricket, and you are named as the club's safeguarding officer.\n\n"
              . "Before the club goes live we need you to confirm two things:\n"
              . "1. The club has a written safeguarding policy in place.\n"
              . "2. For each coach, that you have seen their DBS certificate, or checked their status on the DBS Update Service, and the date.\n$list\n"
              . "Please use this private link (it works for " . TOKEN_DAYS . " days):\n$link\n\n"
              . "We never ask for or keep DBS certificate numbers. We keep only your name, the date, and how the check was done.\n"
              . "If you are not the club's safeguarding officer, please reply to hello@playmorecricket.com and do not use the link.\n\nPlayMoreCricket";
        $subject = 'Please confirm ' . $club['name'] . "'s safeguarding details";
    } else {
        $body = "Hello $name,\n\n{$club['name']} has applied to be listed on PlayMoreCricket. The club's safeguarding officer is also a coach, so they cannot confirm their own DBS check. We are asking you to confirm it instead.\n$list\n"
              . "Please use this private link (it works for " . TOKEN_DAYS . " days):\n$link\n\n"
              . "You will be asked whether you have seen their DBS certificate, or checked their status on the DBS Update Service, and the date. We never ask for or keep certificate numbers.\n"
              . "If this is not right, please reply to hello@playmorecricket.com and do not use the link.\n\nPlayMoreCricket";
        $subject = 'Please confirm a coach\'s DBS check for ' . $club['name'];
    }
    send_email($email, $subject, $body);
}

/** Validate an application and save it. Returns [ok, message, clubId|null]. */
function enrol_create(array $in): array
{
    $err = [];
    if (trim((string) ($in['website'] ?? '')) !== '') { return [false, 'Something went wrong. Please try again.', null]; }   // honeypot
    $club = trim((string) ($in['club'] ?? '')); $town = trim((string) ($in['town'] ?? '')); $county = trim((string) ($in['county'] ?? 'Kent')) ?: 'Kent';
    $postcode = strtoupper(trim((string) ($in['postcode'] ?? '')));
    $cname = trim((string) ($in['cname'] ?? '')); $cdetail = trim((string) ($in['cemail'] ?? '')); $cphone = trim((string) ($in['cphone'] ?? ''));
    $sname = trim((string) ($in['sname'] ?? '')); $semail = trim((string) ($in['semail'] ?? ''));
    $plink = trim((string) ($in['plink'] ?? '')); $weblink = trim((string) ($in['weblink'] ?? ''));
    if ($weblink !== '' && (!preg_match('#^https?://\S+$#i', $weblink) || strlen($weblink) > 250)) { $err[] = 'The club website or social media link must start with https://'; }
    if ($club === '' || mb_strlen($club) > 160) { $err[] = 'Add your club name.'; }
    if ($town === '') { $err[] = 'Add your town or village.'; }
    if ($cname === '') { $err[] = "Add the key contact's name."; }
    if (!filter_var($cdetail, FILTER_VALIDATE_EMAIL)) { $err[] = "Add the key contact's email address."; }
    if (strlen(preg_replace('/\D/', '', $cphone)) < 7 || strlen($cphone) > 40) { $err[] = "Add the key contact's phone number."; }
    if ($sname === '') { $err[] = "Add the safeguarding officer's name."; }
    if (!filter_var($semail, FILTER_VALIDATE_EMAIL)) { $err[] = "Add the safeguarding officer's email address. We send their confirmation link there."; }
    // The club gives its written safeguarding policy as a file OR a web link (or both).
    [$docErr, $docMime, $docB64, $docName] = document_from_upload($_FILES['policy_file'] ?? null);
    if ($docErr) { $err[] = $docErr; }
    if ($plink !== '' && !preg_match('#^https?://\S+$#i', $plink)) { $err[] = 'The policy link must start with https://'; }
    elseif ($docB64 === null && $plink === '' && !$docErr) { $err[] = 'We need the safeguarding policy before we can list a club: attach the file or give the web link.'; }
    foreach (['cf1', 'cf2', 'cf3', 'cf4', 'cf5'] as $c) { if (empty($in[$c])) { $err[] = 'Please tick all the confirmations at the bottom.'; break; } }

    // Nets: one row per net (type, member price, non-member price). Blank rows are ignored.
    $groups = [];
    foreach (($in['g_type'] ?? []) as $i => $type) {
        $type = (string) $type; $mem = trim((string) ($in['g_member'][$i] ?? '')); $non = trim((string) ($in['g_non'][$i] ?? ''));
        if ($non === '' && $mem === '') { continue; }
        $mp = pence_from($mem === '' ? '0' : $mem); $np = pence_from($non);
        if (!in_array($type, ['outdoor', 'indoor'], true) || $mp === null || $np === null || $np <= 0) { $err[] = 'Check Net ' . ($i + 1) . ': a type and prices (a non-member price is needed).'; continue; }
        $groups[] = ['num' => 1, 'type' => $type, 'member' => $mp, 'non' => $np];
    }
    if (!$groups) { $err[] = 'Add at least one net.'; }
    $machine = !empty($in['machine']); $mprice = $machine ? pence_from(trim((string) ($in['mprice'] ?? ''))) : null;
    if ($machine && ($mprice === null || $mprice <= 0)) { $err[] = 'Add the bowling machine price per hour.'; }
    $mcoach = !empty($in['mcoach']);
    [$crestErr, $crestMime, $crestB64] = crest_from_upload($_FILES['crest'] ?? null);
    if ($crestErr) { $err[] = $crestErr; }
    if ($crestB64 !== null && empty($in['crest_ok'])) { $err[] = 'Please tick the box confirming the club may use the crest and gives us permission to display it.'; }

    // Opening hours are given separately for outdoor and indoor nets; a type only needs hours if the club has nets of that type.
    $hours = [];
    foreach (['outdoor', 'indoor'] as $ht) {
        if (!array_filter($groups, fn($g) => $g['type'] === $ht)) { continue; }
        for ($d = 0; $d < 7; $d++) {
            $closed = !empty($in['closed'][$ht][$d]); $o = (int) ($in['open'][$ht][$d] ?? 8); $c = (int) ($in['close'][$ht][$d] ?? 20);
            if (!$closed && ($o < 0 || $o > 23 || $c < 1 || $c > 24 || $c <= $o)) { $err[] = 'Check the ' . $ht . ' opening times on day ' . ($d + 1) . ': closing must be after opening.'; break; }
            $hours[$ht][$d] = [$closed ? 1 : 0, $closed ? 8 : $o, $closed ? 20 : $c];
        }
    }

    // Coaches: up to 6 lines. Blank lines are ignored.
    $coaches = [];
    $officerCoach = (int) ($in['officer_coach'] ?? 0);   // 1..6 = the safeguarding officer is that coach line
    foreach (($in['c_name'] ?? []) as $i => $name) {
        $name = trim((string) $name); $price = trim((string) ($in['c_price'][$i] ?? '')); $q = (string) ($in['c_qual'][$i] ?? '');
        if ($name === '' && $price === '') { continue; }
        $cemail = trim((string) ($in['c_email'][$i] ?? ''));
        $qual = $q === 'Other' ? trim((string) ($in['c_other'][$i] ?? '')) : $q;
        $pp = pence_from($price);
        if ($name === '' || $pp === null || $pp <= 0 || $qual === '' || !in_array($q, QUALIFICATIONS, true)) { $err[] = 'Check coach line ' . ($i + 1) . ': a name, an hourly price and a qualification.'; continue; }
        if (!filter_var($cemail, FILTER_VALIDATE_EMAIL)) { $err[] = 'Check coach line ' . ($i + 1) . ': an email address is needed for each coach.'; continue; }
        $coaches[] = ['email' => $cemail, 'name' => $name, 'price' => $pp, 'qual' => mb_substr($qual, 0, 160), 'officer' => ($officerCoach === $i + 1)];
    }
    if ($err) { return [false, implode(' ', array_unique($err)), null]; }

    $isEmail = true;
    $slug = unique_slug($club);
    q("INSERT INTO clubs (slug, name, town, county, postcode, phone, email, status, key_contact_name, key_contact_detail, safeguarding_name, safeguarding_contact, safeguarding_policy, submitted_at, web_link, terms_accepted_at)
       VALUES (?,?,?,?,?,?,?,'pending',?,?,?,?,?,?,?,?)",
      [$slug, $club, $town, $county, $postcode === '' ? null : $postcode, $cphone, $cdetail,
       $cname, $cdetail, $sname, $semail, implode(' | ', array_filter([$docB64 !== null ? 'Uploaded file: ' . $docName : '', $plink])), now_utc(), $weblink === '' ? null : $weblink, now_utc()]);
    $clubId = (int) q('SELECT id FROM clubs WHERE slug = ?', [$slug])->fetch()['id'];

    if ($crestB64 !== null) { q('INSERT INTO club_crests (club_id, mime, data_b64, permission_given_by) VALUES (?,?,?,?)', [$clubId, $crestMime, $crestB64, $cname]); }
    if ($docB64 !== null) { q('INSERT INTO club_documents (club_id, kind, filename, mime, data_b64) VALUES (?,?,?,?,?)', [$clubId, 'safeguarding', $docName, $docMime, $docB64]); }
    $outdoorExists = (bool) array_filter($groups, fn($g) => $g['type'] === 'outdoor');
    foreach (['outdoor', 'indoor'] as $type) {
        $n = 0;
        foreach ($groups as $g) {
            if ($g['type'] !== $type) { continue; }
            for ($k = 0; $k < $g['num']; $k++) {
                $n++;
                $withMachine = $machine && ($type === 'outdoor' || !$outdoorExists);
                q('INSERT INTO nets (club_id, name, kind, member_price_pence, nonmember_price_pence, machine_price_pence, machine_coach_only) VALUES (?,?,?,?,?,?,?)',
                  [$clubId, ucfirst($type) . ' net ' . $n, $type, $g['member'], $g['non'], $withMachine ? $mprice : null, $mcoach ? 1 : 0]);
                $netId = (int) q('SELECT MAX(id) AS i FROM nets WHERE club_id = ?', [$clubId])->fetch()['i'];
                foreach ($hours[$type] as $d => $hh) { q('INSERT INTO opening_hours (net_id, weekday, is_closed, open_hour, close_hour) VALUES (?,?,?,?,?)', [$netId, $d, $hh[0], $hh[1], $hh[2]]); }
            }
        }
    }
    $coachIds = []; $officerCoachId = null;
    foreach ($coaches as $c) {
        q('INSERT INTO coaches (club_id, name, hourly_price_pence, qualification, dbs_checked, is_safeguarding_officer, email) VALUES (?,?,?,?,?,?,?)',
          [$clubId, $c['name'], $c['price'], $c['qual'], 0, $c['officer'] ? 1 : 0, $c['email']]);
        $id = (int) q('SELECT MAX(id) AS i FROM coaches WHERE club_id = ?', [$clubId])->fetch()['i'];
        $coachIds[$id] = $c['name']; if ($c['officer']) { $officerCoachId = $id; }
    }
    audit(null, 'club_applied', $slug . ' (' . $club . ')');

    $clubRow = ['name' => $club, 'slug' => $slug];
    $others = array_values(array_diff_key($coachIds, $officerCoachId ? [$officerCoachId => 1] : []));
    email_officer_link($clubRow, 'officer', $sname, $semail, make_token($clubId, 'officer', $sname, $semail), $others);
    if ($officerCoachId && $isEmail && strcasecmp($cname, $coachIds[$officerCoachId]) !== 0) {
        email_officer_link($clubRow, 'second', $cname, $cdetail, make_token($clubId, 'second', $cname, $cdetail, [$officerCoachId]), [$coachIds[$officerCoachId]]);
    }
    if ($isEmail) {
        send_email($cdetail, 'We have received ' . $club . "'s application", "Hello $cname,\n\nThank you for applying to list $club on PlayMoreCricket.\n\n"
            . "We have emailed $sname, your safeguarding officer, a private link to confirm the club's safeguarding policy and your coaches' DBS checks. "
            . "Once that is done we will review your application and let you know when the club is live.\n\n"
            . ($crestB64 === null ? "Please also email your club crest or logo to hello@playmorecricket.com, or we will add it later.\n\n" : '')
            . 'PlayMoreCricket');
    }
    send_email(ADMIN_NOTICE_EMAIL, 'New club application: ' . $club, "$club ($town) has applied. Review it in /admin: " . $GLOBALS['cfg']['app_url'] . "/admin\n");
    return [true, 'ok', $clubId];
}

/** Record that someone confirmed a coach's DBS check. Never takes or stores a certificate number. */
function record_dbs_confirmation(int $coachId, ?int $tokenId, string $by, ?string $byEmail, string $method, string $checkedOn, bool $enhanced = false): void
{
    q('UPDATE coaches SET dbs_checked = ?, dbs_checked_on = ?, dbs_method = ?, dbs_confirmed_by = ?, dbs_enhanced = ? WHERE id = ?', [1, $checkedOn, $method, $by, $enhanced ? 1 : 0, $coachId]);
    q('INSERT INTO dbs_confirmations (coach_id, token_id, confirmed_by, confirmed_by_email, method, checked_on, enhanced_barred) VALUES (?,?,?,?,?,?,?)',
      [$coachId, $tokenId, $by, $byEmail, $method, $checkedOn, $enhanced ? 1 : 0]);
}

/** A check dated more than this many years ago is flagged for the administrator. */
const DBS_OLD_YEARS = 3;

function dbs_is_old(?string $checkedOn): bool
{
    return $checkedOn !== null && $checkedOn !== '' && new DateTimeImmutable($checkedOn, london()) < (new DateTimeImmutable('today', london()))->modify('-' . DBS_OLD_YEARS . ' years');
}

/** Short admin note for one coach's confirmed check: level and age warnings. */
function dbs_flags_html(array $x): string
{
    $o = '';
    if (!empty($x['dbs_checked'])) {
        $o .= !empty($x['dbs_enhanced']) ? ' &middot; Enhanced with barred list' : ' &middot; <strong>level not recorded</strong>';
        if (dbs_is_old($x['dbs_checked_on'] ?? null)) { $o .= ' &middot; <strong>&#9888; checked over ' . DBS_OLD_YEARS . ' years ago, consider asking for a fresh check</strong>'; }
    }
    return $o;
}

function method_label(?string $m): string
{
    return match ($m) { 'certificate' => 'certificate seen', 'update_service' => 'DBS Update Service checked', 'by_hand' => 'confirmed by an administrator', default => '' };
}

/** Reminder emails for links nobody has used after a few days. Safe to call often. Returns how many were sent. */
function send_due_reminders(): int
{
    $cut = utc_string((new DateTimeImmutable('now', london()))->modify('-' . REMINDER_AFTER_DAYS . ' days'));
    $rows = q('SELECT t.*, c.name AS club_name FROM verification_tokens t JOIN clubs c ON c.id = t.club_id
               WHERE t.last_used_at IS NULL AND t.reminded_at IS NULL AND t.created_at < ? AND t.expires_at > ? AND c.status = ?',
              [$cut, now_utc(), 'pending'])->fetchAll();
    foreach ($rows as $t) {
        // The link itself cannot be rebuilt (only its hash is kept), so a reminder asks them to use the original email.
        send_email($t['sent_to'], 'Reminder: please confirm ' . $t['club_name'] . "'s safeguarding details",
            "Hello {$t['person_name']},\n\nThis is a reminder that {$t['club_name']} is waiting for you to confirm its safeguarding details on PlayMoreCricket. "
            . "Your private link is in our earlier email (subject: \"Please confirm...\"). It works until " . from_utc($t['expires_at'])->format('j F Y') . ".\n"
            . "If you cannot find it, reply to hello@playmorecricket.com and we will send a new one.\n\nPlayMoreCricket");
        q('UPDATE verification_tokens SET reminded_at = ? WHERE id = ?', [now_utc(), $t['id']]);
    }
    return count($rows);
}

/** The application form (plain HTML, no scripts needed). $old = values to put back after an error. */
function enrol_form_html(array $old = []): string
{
    $v = fn(string $k, $d = '') => h((string) ($old[$k] ?? $d));
    $sel = fn(string $name, array $opts, string $cur) => '<select name="' . $name . '">' . implode('', array_map(fn($o) => '<option value="' . h($o) . '"' . ($o === $cur ? ' selected' : '') . '>' . h($o === '' ? 'Choose one' : ucfirst($o)) . '</option>', $opts)) . '</select>';
    $b = '<h1>Enrol your club</h1><p class="lead">Tell us about your nets, opening times and coaches. We email your safeguarding officer a private link to confirm the club\'s policy and each coach\'s DBS check, then we review and list your club.</p>'
       . '<form method="post" action="/enrol" class="enrol" enctype="multipart/form-data">' . csrf_field()
       . '<div class="hp" aria-hidden="true"><label>Leave this empty<input name="website" tabindex="-1" autocomplete="off"></label></div>';
    $b .= '<fieldset class="card"><legend>Your club</legend>'
       . '<label>Club name<input name="club" maxlength="160" value="' . $v('club') . '" required></label>'
       . '<label>Town or village<input name="town" maxlength="120" value="' . $v('town') . '" required></label>'
       . '<label>County<input name="county" maxlength="80" value="' . $v('county', 'Kent') . '"></label>'
       . '<label>Postcode<input name="postcode" maxlength="12" value="' . $v('postcode') . '"></label>'
       . '<label>Club website or Facebook or Instagram page (optional)<input name="weblink" maxlength="250" inputmode="url" placeholder="https://" value="' . $v('weblink') . '"><span class="muted">Helps us check the club before we list it.</span></label>'
       . '<label>Club crest or logo (optional)<input type="file" name="crest" accept="image/png,image/jpeg,image/webp"><span class="muted">PNG, JPG or WebP, under 1 MB. It appears on your club page once you go live.</span></label>'
       . '<label class="check"><input type="checkbox" name="crest_ok" value="1"> I confirm the club owns this crest or logo, or has the right to use it, and gives PlayMoreCricket permission to display it on the club\'s page and in the club list for as long as the club is listed.</label>'
       . '<label>Key contact name<input name="cname" maxlength="160" value="' . $v('cname') . '" required></label>'
       . '<label>Key contact email<input type="email" name="cemail" maxlength="190" value="' . $v('cemail') . '" required></label>'
       . '<label>Key contact phone<input type="tel" name="cphone" maxlength="40" inputmode="tel" value="' . $v('cphone') . '" required></label></fieldset>';
    $b .= '<fieldset class="card"><legend>Net hire</legend><p class="muted">List each net on its own line. Visitors choose indoor or outdoor and we give them a free net of that type. Leave unused lines empty.</p>';
    $b .= '<div class="netgrid nethead" aria-hidden="true"><span></span><span>Type</span><span>Members, &pound;/hour</span><span>Non-members, &pound;/hour</span></div>';
    for ($i = 0; $i < 6; $i++) {
        $b .= '<div class="netgrid"><strong>Net ' . ($i + 1) . '</strong>'
            . '<select name="g_type[]" aria-label="Net ' . ($i + 1) . ' type">' . implode('', array_map(fn($o) => '<option value="' . $o . '"' . ($o === (string) ($old['g_type_' . $i] ?? 'outdoor') ? ' selected' : '') . '>' . ucfirst($o) . '</option>', ['outdoor', 'indoor'])) . '</select>'
            . '<input name="g_member[]" inputmode="decimal" value="' . $v('g_member_' . $i) . '" placeholder="0 if free" aria-label="Net ' . ($i + 1) . ' member price per hour">'
            . '<input name="g_non[]" inputmode="decimal" value="' . $v('g_non_' . $i) . '" aria-label="Net ' . ($i + 1) . ' non-member price per hour"></div>';
    }
    $b .= '<label class="check"><input type="checkbox" name="machine" value="1"' . (!empty($old['machine']) ? ' checked' : '') . '> We have a bowling machine</label>'
        . '<div class="row"><label>Machine price, &pound; per hour<input name="mprice" inputmode="decimal" size="5" value="' . $v('mprice') . '"></label>'
        . '<label class="check"><input type="checkbox" name="mcoach" value="1" checked> Coaches only</label></div></fieldset>';
    foreach (['outdoor' => 'Outdoor nets: opening times', 'indoor' => 'Indoor nets: opening times'] as $ht => $legend) {
        $b .= '<fieldset class="card"><legend>' . $legend . '</legend><p class="muted">These repeat every week and apply to all your ' . $ht . ' nets.' . ($ht === 'indoor' ? ' Ignored if you have no indoor nets.' : ' Ignored if you have no outdoor nets.') . '</p>';
        foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d => $name) {
            $b .= '<div class="dayrow"><strong>' . $name . '</strong><label class="check"><input type="checkbox" name="closed[' . $ht . '][' . $d . ']" value="1"> Closed</label>'
                . '<select name="open[' . $ht . '][' . $d . ']" aria-label="' . $ht . ' ' . $name . ' opens">' . implode('', array_map(fn($x) => '<option value="' . $x . '"' . ($x === 8 ? ' selected' : '') . '>' . sprintf('%02d:00', $x) . '</option>', range(5, 22))) . '</select>'
                . '<select name="close[' . $ht . '][' . $d . ']" aria-label="' . $ht . ' ' . $name . ' closes">' . implode('', array_map(fn($x) => '<option value="' . $x . '"' . ($x === 20 ? ' selected' : '') . '>' . sprintf('%02d:00', $x) . '</option>', range(6, 24))) . '</select></div>';
        }
        $b .= '</fieldset>';
    }
    $b .= '<fieldset class="card"><legend>Coaches</legend><p class="muted">Leave unused lines empty. Each coach needs an email address, so they get their booking emails. Do not send DBS certificate numbers: your safeguarding officer confirms each check on a private page.</p>';
    for ($i = 0; $i < 6; $i++) {
        $b .= '<div class="row"><label>Coach name<input name="c_name[]" maxlength="160" value="' . $v('c_name_' . $i) . '"></label>'
            . '<label>&pound; per hour<input name="c_price[]" inputmode="decimal" size="5" value="' . $v('c_price_' . $i) . '"></label>'
            . '<label>Qualification' . $sel('c_qual[]', QUALIFICATIONS, (string) ($old['c_qual_' . $i] ?? '')) . '</label>'
            . '<label>If other, which<input name="c_other[]" maxlength="100" value="' . $v('c_other_' . $i) . '"></label>'
            . '<label>Email<input type="email" name="c_email[]" maxlength="190" value="' . $v('c_email_' . $i) . '"></label></div>';
    }
    $b .= '</fieldset>';
    $b .= '<fieldset class="card"><legend>Safeguarding</legend><p class="muted">Every club must give us its safeguarding officer and written policy before it is listed, because juniors may be booked.</p>'
        . '<label>Safeguarding officer\'s name<input name="sname" maxlength="160" value="' . $v('sname') . '" required></label>'
        . '<label>Their email address<input type="email" name="semail" maxlength="190" value="' . $v('semail') . '" required><span class="muted">We send their private confirmation link here.</span></label>'
        . '<label>Is the safeguarding officer also one of the coaches above?<select name="officer_coach"><option value="0">No</option>'
        . implode('', array_map(fn($i) => '<option value="' . $i . '"' . ((int) ($old['officer_coach'] ?? 0) === $i ? ' selected' : '') . '>Yes, coach line ' . $i . '</option>', range(1, 6))) . '</select>'
        . '<span class="muted">They cannot confirm their own DBS check, so we will ask the key contact to.</span></label>'
        . '<p><strong>Written safeguarding policy</strong><br><span class="muted">Attach the file OR give a link to it online.</span></p>'
        . '<label>Attach the policy (PDF or Word, under 2 MB)<input type="file" name="policy_file" accept=".pdf,.doc,.docx,application/pdf"><span class="muted">Only you and PlayMoreCricket administrators can see this file.</span></label>'
        . '<p class="or">OR</p>'
        . '<label>Link to the policy online<input name="plink" maxlength="200" value="' . $v('plink') . '" placeholder="https://"></label>'
        . '<p class="notice">We do not list a club until we have seen its safeguarding policy.</p></fieldset>';
    $b .= '<fieldset class="card"><legend>Please confirm</legend>'
        . '<label class="check"><input type="checkbox" name="cf1" value="1"> I am authorised to enrol this club.</label>'
        . '<label class="check"><input type="checkbox" name="cf2" value="1"> The club agrees to its name, prices and opening times being shown publicly.</label>'
        . '<label class="check"><input type="checkbox" name="cf3" value="1"> Each coach listed agrees to their name, price and DBS status being shown publicly.</label>'
        . '<label class="check"><input type="checkbox" name="cf4" value="1"> I understand a DBS tick appears only after the club\'s safeguarding officer has confirmed the check.</label>'
        . '<label class="check"><input type="checkbox" name="cf5" value="1"> I have read and agree to the <a href="/club-terms" target="_blank">club terms</a> (commission, card fees and cancellations).</label>'
        . '<button type="submit">Send my application</button></fieldset></form>';
    return $b;
}


// ---- Stripe follow-up: checklist, status refresh, reminders

const STRIPE_CHECKLIST = [
    "A bank account in the club's name (not a personal account)",
    'The name, date of birth and home address of one person who can act for the club (for example the treasurer or chair), and their photo ID',
    "The club's legal type: a CASC (Community Amateur Sports Club), an unincorporated association, a registered charity or a limited company, and its registration number if it has one",
    "The club's website or social media page, and a contact phone number",
];

function stripe_checklist_html(): string
{
    return '<p class="muted">Before you start, have these ready. Stripe will ask for them (it can take 10 to 15 minutes):</p><ul class="muted">'
        . implode('', array_map(fn($t) => '<li>' . h($t) . '</li>', STRIPE_CHECKLIST)) . '</ul>';
}

function stripe_checklist_text(): string { return '- ' . implode("\n- ", STRIPE_CHECKLIST); }

/** Ask Stripe if an unfinished club account can take payments yet (at most every 5 minutes per club). Returns true if it just became ready. */
function refresh_stripe_status(array $club): bool
{
    if (empty($club['stripe_account_id']) || (int) $club['stripe_charges_enabled'] === 1 || !stripe_configured()) { return false; }
    if (!empty($club['stripe_checked_at']) && $club['stripe_checked_at'] > utc_string((new DateTimeImmutable('now', london()))->modify('-5 minutes'))) { return false; }
    try {
        $a = stripe_retrieve_account((string) $club['stripe_account_id']);
        $on = !empty($a['charges_enabled']);
        q('UPDATE clubs SET stripe_charges_enabled = ?, stripe_checked_at = ? WHERE id = ?', [$on ? 1 : 0, now_utc(), $club['id']]);
        if ($on) { audit(null, 'stripe_became_ready', (string) $club['slug']); }
        return $on;
    } catch (Throwable $e) { error_log('PMC stripe refresh failed for club ' . $club['id'] . ': ' . $e->getMessage()); return false; }
}

function refresh_unfinished_stripe(): int
{
    $n = 0;
    foreach (q('SELECT * FROM clubs WHERE stripe_account_id IS NOT NULL AND NOT stripe_charges_enabled')->fetchAll() as $c) { if (refresh_stripe_status($c)) { $n++; } }
    return $n;
}

const STRIPE_REMIND_AFTER_DAYS = 3;
const STRIPE_REMIND_EVERY_DAYS = 7;
const STRIPE_REMIND_MAX = 3;

/** Remind live clubs that have not finished Stripe sign-up: after 3 days, then weekly, at most 3 times. Returns how many were sent. */
function send_stripe_reminders(): int
{
    $cut = utc_string((new DateTimeImmutable('now', london()))->modify('-' . STRIPE_REMIND_AFTER_DAYS . ' days'));
    $again = utc_string((new DateTimeImmutable('now', london()))->modify('-' . STRIPE_REMIND_EVERY_DAYS . ' days'));
    $sent = 0;
    foreach (q("SELECT * FROM clubs WHERE status = 'active' AND NOT sponsored_partner AND NOT stripe_charges_enabled AND approved_at IS NOT NULL AND approved_at < ?
                AND stripe_reminders < ? AND (stripe_reminded_at IS NULL OR stripe_reminded_at < ?)", [$cut, STRIPE_REMIND_MAX, $again])->fetchAll() as $c) {
        $to = (string) $c['key_contact_detail']; if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { continue; }
        send_email($to, 'Finish setting up payments for ' . $c['name'],
            "Hello {$c['key_contact_name']},\n\n{$c['name']} is live on PlayMoreCricket, but visitors cannot book and pay online until the club's payment account is set up.\n\n"
            . "Log in, open your club page and press " . (empty($c['stripe_account_id']) ? 'Set up payments' : 'Continue Stripe sign-up') . ": " . $GLOBALS['cfg']['app_url'] . '/club/' . $c['slug']
            . "\n\nHave these ready:\n" . stripe_checklist_text() . "\n\nIf you are stuck, reply to hello@playmorecricket.com.\n\nPlayMoreCricket");
        q('UPDATE clubs SET stripe_reminded_at = ?, stripe_reminders = stripe_reminders + 1 WHERE id = ?', [now_utc(), $c['id']]);
        $sent++;
    }
    return $sent;
}
