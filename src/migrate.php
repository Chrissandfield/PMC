<?php
declare(strict_types=1);

/*
 * Database updates. Each file in db/migrations/ runs once, in name order, and is remembered in
 * the schema_migrations table. Fresh installs from schema.sql already include every migration
 * (schema.sql records them), so only older databases have anything pending.
 */

function migrations_dir(): string
{
    return getenv('PMC_MIGRATIONS_DIR') ?: __DIR__ . '/../db/migrations';
}

function ensure_migrations_table(): void
{
    q('CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(120) NOT NULL PRIMARY KEY, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)');
}

/** Names of migration files that have not been applied yet. */
function pending_migrations(): array
{
    ensure_migrations_table();
    $done = array_column(q('SELECT name FROM schema_migrations')->fetchAll(), 'name');
    $files = glob(migrations_dir() . '/*.sql') ?: [];
    sort($files);
    $out = [];
    foreach ($files as $f) {
        $name = basename($f, '.sql');
        if (!in_array($name, $done, true)) { $out[$name] = $f; }
    }
    return $out;
}

/** Split an SQL file into statements (our files use only simple statements, `--` comments, no procedures). */
function split_sql(string $sql): array
{
    $lines = array_filter(explode("\n", $sql), static fn($l) => !str_starts_with(ltrim($l), '--'));
    return array_values(array_filter(array_map('trim', explode(';', implode("\n", $lines))), static fn($s) => $s !== ''));
}

/** True for "already exists" errors, so a migration someone ran by hand is not run twice. */
function already_exists(PDOException $e): bool
{
    $my = (int) ($e->errorInfo[1] ?? 0);
    return in_array($my, [1050, 1060, 1061], true) || in_array($e->errorInfo[0] ?? '', ['42P07', '42701'], true);
}

/** Apply every pending migration. Returns [list of applied names, error message or null]. */
function run_migrations(): array
{
    $applied = [];
    foreach (pending_migrations() as $name => $file) {
        foreach (split_sql((string) file_get_contents($file)) as $stmt) {
            try { db()->exec($stmt); }
            catch (PDOException $e) {
                if (already_exists($e)) { continue; }
                return [$applied, $name . ': ' . $e->getMessage()];
            }
        }
        q('INSERT INTO schema_migrations (name) VALUES (?)', [$name]);
        $applied[] = $name;
    }
    return [$applied, null];
}
