<?php
declare(strict_types=1);
// Keep customer details for three years after the customer's last booking, then anonymise them.
// The booking row itself stays (dates, amounts, references) because payment records may need to be kept for accounting.
// DRAFT period, pending legal approval: change RETENTION_YEARS here (and the privacy notice) if the advice differs.
// Under-18 booking records may need to be kept longer: JUNIOR_RETENTION_YEARS lets that be set separately.

const RETENTION_YEARS = 3;
const JUNIOR_RETENTION_YEARS = 3;
const ANON_EMAIL = 'deleted@invalid';

/** @return array{bookings:int, optins:int, emails:int} */
function purge_old_data(): array
{
    $now = new DateTimeImmutable('now', london());
    $cut = utc_string($now->modify('-' . RETENTION_YEARS . ' years'));
    $cutJ = utc_string($now->modify('-' . JUNIOR_RETENTION_YEARS . ' years'));
    $recent = 'SELECT e FROM (SELECT DISTINCT LOWER(customer_email) AS e FROM bookings WHERE starts_at >= ? AND customer_email IS NOT NULL) recent';

    $n = q("UPDATE bookings SET customer_name = 'Deleted', customer_email = '" . ANON_EMAIL . "', customer_phone = 'deleted', note = NULL,
                   child_first_name = CASE WHEN child_first_name IS NULL THEN NULL ELSE 'Deleted' END
            WHERE customer_email IS NOT NULL AND customer_email <> '" . ANON_EMAIL . "' AND starts_at < ?
              AND (NOT is_junior OR starts_at < ?)
              AND LOWER(customer_email) NOT IN ($recent)", [$cut, $cutJ, $cut])->rowCount();

    $o = q("DELETE FROM marketing_optins WHERE COALESCE(withdrawn_at, consented_at) < ? AND email NOT IN ($recent)", [$cut, $cut])->rowCount();
    $m = q('DELETE FROM email_outbox WHERE created_at < ?', [$cut])->rowCount();

    // housekeeping: short-lived security records
    $short = utc_string($now->modify('-30 days'));
    q('DELETE FROM login_failures WHERE attempted_at < ?', [$short]);
    q('DELETE FROM password_tokens WHERE expires_at < ?', [$short]);

    if ($n + $o + $m > 0) { audit(null, 'retention_run', "anonymised $n booking(s), removed $o marketing record(s), $m email record(s)"); }
    return ['bookings' => $n, 'optins' => $o, 'emails' => $m];
}
