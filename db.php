<?php
/**
 * SebaBD — database access layer.
 *
 * Single shared PDO connection. If MySQL is unreachable or the database has
 * not been imported yet, db() returns null instead of throwing, so pages can
 * fall back to the offline catalogue and still render.
 */

/**
 * Verbose diagnostics switch. Every page that reports a database or write
 * failure reads this constant, so it is defined here — the lowest-level
 * include, loaded by db.php's own callers and by helpers.php — with a safe
 * default of off.
 *
 * To see raw MySQL error text while developing, add this as the first line of
 * your local config.php (see config.example.php):
 *
 *     define('APP_DEBUG', true);
 */
if (!defined('APP_DEBUG')) {
    define('APP_DEBUG', false);
}

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
 * Run a statement that returns at most one row and return it as an array, or null.
 *
 * A small shared helper for diagnostic and lookup code that only needs one row
 * (for example a SHOW CREATE TABLE or a single configuration row). It reuses
 * the same PDO connection and fetch mode as the rest of the application.
 */
function db_query_one(PDO $pdo, string $sql, array $params = []): ?array
{
    $rows = db_fetch_all($sql, $params);

    return $rows[0] ?? null;
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
