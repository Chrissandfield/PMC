<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = $GLOBALS['cfg'];
        $pdo = new PDO($c['db_dsn'], $c['db_user'], $c['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $pdo->exec("SET time_zone = '+00:00'");
            $pdo->exec("SET NAMES utf8mb4");
        } else {
            $pdo->exec("SET TIME ZONE 'UTC'");   // PostgreSQL: only used for local testing
        }
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function audit(?int $userId, string $action, string $detail = ''): void
{
    q('INSERT INTO audit_log (user_id, action, detail) VALUES (?, ?, ?)', [$userId, $action, $detail]);
}

/** True when an insert failed because the net hour is already held (duplicate key). */
function is_unique_violation(PDOException $e): bool
{
    return ($e->errorInfo[0] ?? '') === '23505' || (int) ($e->errorInfo[1] ?? 0) === 1062;
}
