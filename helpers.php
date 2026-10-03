<?php
/**
 * SebaBD — shared helpers: session/auth, flash messages, DB insert helpers.
 */
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

const ROLE_CLIENT_ACCOUNT = 4;
const ROLE_B2B_CLIENT_ACCOUNT = 5;

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

/* ---------------------------------------------------------- staff roles */

/**
 * SebaBD staff roles — the accounts allowed into the reporting module.
 * Client Account users only ever see the storefront.
 */
function staff_roles(): array
{
    return ['Admin', 'Sales Manager', 'Inventory Manager'];
}

function is_staff_user(?array $user = null): bool
{
    $user = $user ?? current_user();

    return $user !== null && in_array($user['RoleName'] ?? '', staff_roles(), true);
}

/**
 * SebaBD customer roles — accounts that belong to the storefront rather than
 * the staff/reporting side.
 */
function client_roles(): array
{
    return ['Client Account', 'B2B Client Account'];
}

function is_client_user(?array $user = null): bool
{
    $user = $user ?? current_user();

    return $user !== null && in_array($user['RoleName'] ?? '', client_roles(), true);
}

/**
 * Gate for the reports area: the visitor must be signed in *and* be staff.
 */
function require_staff(): array
{
    $user = require_login();
    if (!is_staff_user($user)) {
        flash_set('error', 'The reports area is limited to SebaBD staff accounts.');
        header('Location: index.php');
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

/* ------------------------------------------------------------ ajax */

/**
 * Did this request come from assets/ajax.js rather than a normal form post?
 *
 * The script always sends `X-Requested-With: fetch` (a plain XMLHttpRequest
 * field would also match KoolReport's own jQuery calls, hence the custom
 * value) and asks for JSON.
 */
function json_request(): bool
{
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch'
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
}

/**
 * Send a JSON payload and stop. Used by the api/ endpoints and by the form
 * handlers when the browser asked for JSON.
 */
function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Finish a form POST that ended in validation errors.
 *
 * For a normal browser submit this does nothing at all — the page then renders
 * the errors exactly as it always did, so every form keeps working with
 * JavaScript disabled. For a fetch() submit it answers 422 + the error list.
 */
function ajax_errors(array $errors): void
{
    if ($errors && json_request()) {
        json_response(['ok' => false, 'errors' => array_values($errors)], 422);
    }
}

/**
 * Finish a successful form POST.
 *
 * fetch() callers get the redirect target (they navigate themselves, which
 * keeps the flash message flow identical), everyone else gets a Location header.
 */
function post_success(string $location): void
{
    if (json_request()) {
        json_response(['ok' => true, 'redirect' => $location]);
    }
    header('Location: ' . $location);
    exit;
}

/* -------------------------------------------------------- validation */

/**
 * Username rule shared by register.php, b2b-registration.php and the live
 * availability checker in api/check-account.php.
 */
function is_valid_username(string $username): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_.]{3,100}$/', $username);
}

function username_rule_text(): string
{
    return 'Username must be 3–100 characters: letters, numbers, dot or underscore.';
}

/**
 * Optional phone number; empty is allowed, anything else has to look like one.
 */
function is_valid_phone(string $phone): bool
{
    return $phone === '' || (bool) preg_match('/^[0-9+\-\s()]{6,20}$/', $phone);
}

/* --------------------------------------------------------- old input */

function old(string $key, string $default = ''): string
{
    return htmlspecialchars($_SESSION['old'][$key] ?? $default, ENT_QUOTES);
}

/**
 * Raw old input as an array — used to rebuild repeated rows (product_id[],
 * quantity[] …) after a validation error, so the customer does not lose a
 * multi-line order or quotation.
 */
function old_array(string $key): array
{
    $value = $_SESSION['old'][$key] ?? [];

    return is_array($value) ? $value : [];
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
 * Currency symbol from config.php (the e_commerce catalog is priced in
 * Bangladeshi taka).
 */
function currency_symbol(): string
{
    static $symbol = null;
    if ($symbol === null) {
        $cfg = require __DIR__ . '/config.php';
        $symbol = $cfg['site']['currency'] ?? '৳';
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
 *   1750.00 -> "One Thousand Seven Hundred Fifty Taka"
 *    499.95 -> "Four Hundred Ninety Nine Taka and Ninety Five Poisha"
 *       0.00 -> "Zero Taka"
 *
 * The currency name comes from config.php.
 */
function amount_in_words(float $amount): string
{
    $cfg         = require __DIR__ . '/config.php';
    $unitName    = $cfg['site']['currency_name'] ?? 'Taka';
    $subunitName = $cfg['site']['currency_subunit'] ?? 'Poisha';

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
