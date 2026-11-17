<?php
// Run daily from the host's scheduled jobs (cron): php bin/reminders.php
// Sends club-application reminders and, on Sundays, "set next week's hours" emails to coaches.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
require __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/enrol.php';
$a = send_due_reminders();
$c = send_availability_reminders();
echo "application reminders: " . (is_int($a) ? $a : 'done') . ", coach availability reminders: $c\n";
