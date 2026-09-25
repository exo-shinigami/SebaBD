<?php
/**
 * SebaBD — database access layer.
 *
 * Single shared PDO connection. If MySQL is unreachable or the database has
 * not been imported yet, db() returns null instead of throwing, so pages can
 * fall back to demo data and still render.
 */

function db(): ?PDO
{
    static $pdo = null;
    static $attempted = false;

    if ($pdo instanceof PDO || $attempted) {
        return $pdo;
    }
    $attempted = true;

    $cfg = require __DIR__ . '/config.php';
    $db  = $cfg['db'];

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $db['host'],
        $db['port'],
        $db['name'],
        $db['charset']
    );

    try {
        $pdo = new PDO($dsn, $db['user'], $db['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        // Database not running / not imported — leave $pdo as null.
        if (defined('APP_DEBUG') && APP_DEBUG) {
            error_log('[SebaBD] DB connection failed: ' . $e->getMessage());
        }
        $pdo = null;
    }

    return $pdo;
}

/**
 * Run a prepared statement and fetch all rows (or [] if the DB is down).
 */
function db_fetch_all(string $sql, array $params = []): array
{
    $pdo = db();
    if (!$pdo) {
        return [];
    }
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('[SebaBD] Query failed: ' . $e->getMessage());
        return [];
    }
}
