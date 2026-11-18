<?php
// Run daily from the host's scheduled jobs (cron): php bin/reminders.php
// Sends club-application reminders and, on Sundays, "set next week's hours" emails to coaches, and day-before booking reminders.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
require __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/enrol.php';
$a = send_due_reminders();
$c = send_availability_reminders();
$br = send_booking_reminders();
$sr = refresh_unfinished_stripe() . ' became ready, ' . send_stripe_reminders() . ' reminded';
echo "application reminders: " . (is_int($a) ? $a : 'done') . ", coach availability reminders: $c, Stripe: $sr, booking reminders: $br\n";
