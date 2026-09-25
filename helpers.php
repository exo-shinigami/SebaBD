<?php
/**
 * SebaBD — shared helpers: session/auth, flash messages, DB insert helpers.
 */
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ------------------------------------------------------------------ auth */

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    static $user = null;
    static $loaded = false;
    if (!$loaded) {
        $rows = db_fetch_all(
            'SELECT u.UserID, u.Username, u.FullName, u.Email, r.RoleName
             FROM `user` u JOIN `role` r ON r.RoleID = u.RoleID
             WHERE u.UserID = :id',
            [':id' => (int) $_SESSION['user_id']]
        );
        $user = $rows[0] ?? null;
        $loaded = true;
    }
    return $user;
}

function require_login(string $redirectBack = ''): array
{
    $user = current_user();
    if (!$user) {
        $back = $redirectBack !== '' ? $redirectBack : ($_SERVER['REQUEST_URI'] ?? 'index.php');
        header('Location: login.php?next=' . urlencode($back));
        exit;
    }
    return $user;
}

/* -------------------------------------------------------------- partials */

/**
 * Render a partial template with isolated variables and return its HTML.
 * $path is relative to the project root, e.g. 'partials/header.php'.
 */
function partial_render(string $path, array $vars = []): string
{
    extract($vars, EXTR_SKIP);
    ob_start();
    require __DIR__ . '/' . ltrim($path, '/');
    return (string) ob_get_clean();
}

/* -------------------------------------------------------------- flash */

function flash_set(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_take(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

function render_flashes(): string
{
    $out = '';
    foreach (flash_take() as $f) {
        $cls = $f['type'] === 'error' ? 'alert-danger' : 'alert-success';
        $out .= '<div class="alert ' . $cls . ' alert-dismissible fade show" role="alert">'
              . htmlspecialchars($f['message'])
              . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>'
              . '</div>';
    }
    return $out;
}

/* --------------------------------------------------------- old input */

function old(string $key, string $default = ''): string
{
    return htmlspecialchars($_SESSION['old'][$key] ?? $default, ENT_QUOTES);
}

function keep_old(array $data): void
{
    $_SESSION['old'] = $data;
}

function clear_old(): void
{
    unset($_SESSION['old']);
}

/* ---------------------------------------------------------- DB writes */

/**
 * Next free integer ID for a table (schema uses explicit INT primary keys).
 * Good enough for lab use; wrap the caller's inserts in a transaction.
 */
function next_id(PDO $pdo, string $table, string $column): int
{
    return (int) $pdo->query("SELECT COALESCE(MAX($column), 0) + 1 AS n FROM `$table`")
                     ->fetch()['n'];
}

/**
 * Escape a string literal for embedding in SQL (single quotes, backslashes,
 * and the utf8mb4-safe NUL handling). Only used where prepared statement
 * parameters can't be used (e.g. ON DUPLICATE constructions); values are
 * still escaped here.
 */
function sql_str(?string $value): string
{
    $pdo = db();
    if ($pdo) {
        return $pdo->quote((string) $value);
    }
    return "'" . addcslashes((string) $value, "'\\") . "'";
}

/* ---------------------------------------------------------- formatting */

/**
 * Currency symbol from config.php (the e_commerce catalog is priced in USD).
 */
function currency_symbol(): string
{
    static $symbol = null;
    if ($symbol === null) {
        $cfg = require __DIR__ . '/config.php';
        $symbol = $cfg['site']['currency'] ?? '$';
    }
    return $symbol;
}

/**
 * Format a price the way the site quotes it: $ 1,200.00
 */
function money(float|string|null $amount): string
{
    return currency_symbol() . ' ' . number_format((float) $amount, 2);
}

/* ------------------------------------------------------- amount words */

/**
 * Amount in words for the stored document snapshots (QUOTATION.AmountInWords,
 * INVOICE.AmountInWords). Matches the wording used by the sample data in
 * database/e_commerce.sql:
 *
 *   1750.00 -> "One Thousand Seven Hundred Fifty Dollars"
 *    499.95 -> "Four Hundred Ninety Nine Dollars and Ninety Five Cents"
 *       0.00 -> "Zero Dollars"
 *
 * The currency name comes from config.php.
 */
function amount_in_words(float $amount): string
{
    $cfg         = require __DIR__ . '/config.php';
    $unitName    = $cfg['site']['currency_name'] ?? 'Dollars';
    $subunitName = $cfg['site']['currency_subunit'] ?? 'Cents';

    $amount = round($amount, 2);
    $units  = (int) $amount;
    $cents  = (int) round(($amount - $units) * 100);

    $ones = [
        '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
        'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
        'Seventeen', 'Eighteen', 'Nineteen',
    ];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    $toWords = function (int $n) use ($ones, $tens, &$toWords): string {
        if ($n === 0) return '';
        if ($n < 20) return $ones[$n];
        if ($n < 100) return trim($tens[intdiv($n, 10)] . ' ' . $toWords($n % 10));
        return trim($ones[intdiv($n, 100)] . ' Hundred ' . $toWords($n % 100));
    };

    $parts = [];
    $millions  = intdiv($units, 1000000);
    $thousands = intdiv($units % 1000000, 1000);
    $rest      = $units % 1000;

    if ($millions > 0)  $parts[] = $toWords($millions) . ' Million';
    if ($thousands > 0) $parts[] = $toWords($thousands) . ' Thousand';
    if ($rest > 0)      $parts[] = $toWords($rest);

    $words = ($parts ? implode(' ', $parts) : 'Zero') . ' ' . $unitName;
    if ($cents > 0) {
        $words .= ' and ' . $toWords($cents) . ' ' . $subunitName;
    }

    return $words;
}
