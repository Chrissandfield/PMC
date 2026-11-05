<?php
// Usage: php bin/create-user.php email "Full Name" role [club-slug]
// role: admin | club_contact | coach. Prompts for a password (not shown on screen history).
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
require __DIR__ . '/../src/bootstrap.php';
[$self, $email, $name, $role] = array_pad($argv, 4, null);
$slug = $argv[4] ?? null;
if (!$email || !$name || !in_array($role, ['admin', 'club_contact', 'coach'], true)) { fwrite(STDERR, "Usage: php bin/create-user.php email \"Name\" admin|club_contact|coach [club-slug]\n"); exit(1); }
$pw = getenv('PMC_NEW_PASSWORD') ?: '';
if ($pw === '') { fwrite(STDOUT, 'Password (min 10 characters): '); $pw = trim((string) fgets(STDIN)); }
if (strlen($pw) < 10) { fwrite(STDERR, "Password too short.\n"); exit(1); }
$club = $slug ? club_by_slug($slug) : null;
if ($role !== 'admin' && !$club) { fwrite(STDERR, "A club slug is needed for this role.\n"); exit(1); }
q('INSERT INTO users (email, password_hash, name, role, club_id) VALUES (?,?,?,?,?)',
  [$email, password_hash($pw, PASSWORD_DEFAULT), $name, $role, $club['id'] ?? null]);
echo "Created $role $email\n";
