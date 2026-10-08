<?php
/**
 * SebaBD — shared helpers: session/auth, flash messages, DB insert helpers.
 */
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    // Harden the session cookie before it is issued: the browser cannot read
    // it from JavaScript, and SameSite=Lax keeps a form on another site from
    // riding along with the visitor's session. 'secure' follows the request
    // scheme, so plain-HTTP development on XAMPP keeps working.
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
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
 * Minimum password rule, shared by registration, the account page and the
 * administrator's password reset so every entry point asks for the same thing.
 * Returns an error message, or '' when the password is acceptable.
 */
function password_problem(string $password): string
{
    if (strlen($password) < 6) {
        return 'Password must be at least 6 characters.';
    }
    if (strlen($password) > 72) {
        // bcrypt silently truncates past 72 bytes, so refuse the input instead
        // of storing a password that is not the one the user typed.
        return 'Password must be 72 characters or fewer.';
    }

    return '';
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
 * Next free integer ID for a table (the schema uses explicit INT primary keys,
 * so nothing is AUTO_INCREMENT and the application allocates its own keys).
 *
 * Inside a transaction the row is locked with FOR UPDATE: a second session
 * asking for the same key waits for the first to commit instead of reading the
 * same MAX() and colliding on the primary key. Always call this inside
 * db_transaction().
 */
function next_id(PDO $pdo, string $table, string $column): int
{
    $sql = "SELECT COALESCE(MAX(`$column`), 0) + 1 AS n FROM `$table`"
         . ($pdo->inTransaction() ? ' FOR UPDATE' : '');

    return (int) $pdo->query($sql)->fetch()['n'];
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

/* ================================================================ access */

/**
 * Is the signed-in user the administrator?
 */
function is_admin_user(?array $user = null): bool
{
    $user = $user ?? current_user();

    return $user !== null && ($user['RoleName'] ?? '') === 'Admin';
}

/**
 * Stop the request unless the visitor holds one of $roles.
 *
 * Signed-out visitors are sent to the login form (via require_login); a
 * signed-in account that simply is not allowed sees a real 403 page rather
 * than a blank redirect, so it is obvious why the page did not open.
 */
function require_role(array $roles, string $what = 'That area'): array
{
    $user = require_login();
    if (in_array($user['RoleName'] ?? '', $roles, true)) {
        return $user;
    }

    deny_page(
        403,
        'Not available for your role',
        $what . ' is limited to <strong>' . htmlspecialchars(implode(' / ', $roles)) . '</strong> accounts.\n'
        . 'You are signed in as <strong>' . htmlspecialchars($user['RoleName']) . '</strong>.'
    );
}

/**
 * Render a friendly stand-alone error page (403 / 404 / 500) inside the normal
 * site chrome and stop. Used anywhere a request cannot be served.
 *
 * $message may contain markup; escape any dynamic text before passing it in.
 */
function deny_page(int $status, string $title, string $message, string $backHref = '', string $backLabel = ''): void
{
    http_response_code($status);
    $backHref  = $backHref !== '' ? $backHref : 'index.php';
    $backLabel = $backLabel !== '' ? $backLabel : 'Back to the storefront';

    // A fetch() caller asked for JSON, not a page.
    if (json_request()) {
        json_response(['ok' => false, 'errors' => [strip_tags($title) . ' — ' . strip_tags($message)]], $status);
    }

    echo partial_render('partials/header.php', ['page_title' => $title, 'active_nav' => '']);
    echo '<div class="container py-5"><div class="row justify-content-center"><div class="col-lg-7">'
        . '<div class="report-panel text-center py-5">'
        . '<i class="fa-solid fa-' . ($status === 404 ? 'circle-question' : 'lock') . ' fa-2x text-muted d-block mb-3"></i>'
        . '<h1 class="h4 mb-2">' . htmlspecialchars($title) . '</h1>'
        . '<p class="text-muted mb-4" style="white-space:pre-line">' . $message . '</p>'
        . '<div class="d-flex flex-wrap gap-2 justify-content-center">'
        . '<a class="btn btn-accent" href="' . htmlspecialchars($backHref) . '">' . htmlspecialchars($backLabel) . '</a>'
        . (current_user() ? '<a class="btn btn-outline-secondary" href="dashboard.php">My dashboard</a>' : '')
        . '</div></div></div></div></div>';
    echo partial_render('partials/footer.php');
    exit;
}

/* ================================================================== CSRF */

/**
 * Per-session CSRF token.
 *
 * Every state-changing form carries the token in a hidden `_csrf` field (see
 * csrf_field()) and the handler checks it with csrf_guard() before touching
 * the database. The token lives in the session, so it survives page reloads
 * but dies with the session — which is exactly the "your session expired"
 * case csrf_guard() reports.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Hidden field for a form. Add it inside every <form method="post">.
 */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

function csrf_valid(): bool
{
    $sent = $_POST['_csrf'] ?? '';

    return is_string($sent) && $sent !== '' && hash_equals(csrf_token(), $sent);
}

/**
 * Refuse a POST whose token is missing or stale.
 *
 * AJAX callers get a 419 they can print next to the form; a normal browser
 * submit is bounced back to $fallback (a GET) with a flash message, so the
 * visitor lands on a working page instead of a dead end.
 */
function csrf_guard(string $fallback = ''): void
{
    if (csrf_valid()) {
        return;
    }

    $message = 'Your session expired before the form was sent. '
             . 'Please check the details you entered and submit again.';

    if (json_request()) {
        json_response(['ok' => false, 'errors' => [$message]], 419);
    }

    flash_set('error', $message);
    header('Location: ' . ($fallback !== '' ? $fallback : ($_SERVER['REQUEST_URI'] ?? 'index.php')));
    exit;
}

/* ------------------------------------------------------------- requests */

/** Trimmed string from POST, or $default. */
function post_str(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? null;

    return is_string($value) ? trim($value) : $default;
}

/** Integer from POST (or GET when $from is 'get'), or $default. */
function post_int(string $key, int $default = 0, string $from = 'post'): int
{
    $source = $from === 'get' ? $_GET : $_POST;
    $value  = $source[$key] ?? null;

    return is_numeric($value) ? (int) $value : $default;
}

/** Float from POST, or $default. */
function post_float(string $key, float $default = 0.0): float
{
    $value = $_POST[$key] ?? null;

    return is_numeric($value) ? (float) $value : $default;
}

/**
 * A repeated field (product_id[], quantity[], …) as a list of trimmed strings
 * or ints, with the empty rows dropped.
 */
function post_list(string $key, bool $asInt = false): array
{
    $out = [];
    foreach (post_rows($key) as $value) {
        if ($value === '') {
            continue;
        }
        $out[] = $asInt ? (int) $value : $value;
    }

    return $out;
}

/**
 * A repeated field kept exactly in step with its siblings.
 *
 * Document lines post several parallel arrays (product_id[], quantity[],
 * unit_price[], …), so position matters: element 2 of one array belongs to
 * element 2 of the others. Dropping an empty value — as post_list() does for
 * convenience — would shift every later row onto the wrong product, so this
 * keeps the original array keys and only trims the values.
 */
function post_rows(string $key): array
{
    $raw = $_POST[$key] ?? [];
    if (!is_array($raw)) {
        return [];
    }

    $out = [];
    foreach ($raw as $index => $value) {
        $out[$index] = is_array($value) ? '' : trim((string) $value);
    }

    return $out;
}

/* -------------------------------------------------------------- display */

/**
 * Colour class for a status value stored in the database. One mapping shared
 * by the dashboard, the back office and the printed documents, so a
 * "PARTIAL" invoice looks the same everywhere.
 */
function status_badge_class(string $status): string
{
    return match (strtolower(trim($status))) {
        'completed', 'paid', 'accepted', 'received', 'active', 'ok' => 'report-badge-ok',
        'processing', 'partial', 'pending', 'sent', 'issued', 'draft', 'low' => 'report-badge-warn',
        'cancelled', 'rejected', 'due', 'overdue', 'out', 'inactive' => 'report-badge-danger',
        default => 'report-badge-plain',
    };
}

/**
 * The statuses an order can hold, in fulfilment order.
 *
 * Shared by the back office (admin-orders.php) and the customer's own order
 * page (order-detail.php) so both sides agree on what a status means and which
 * ones count as finished.
 */
function order_statuses(): array
{
    return ['Pending', 'Processing', 'Completed', 'Cancelled'];
}

/** Has this order reached a state where nothing more will happen? */
function order_is_closed(string $status): bool
{
    return in_array($status, ['Completed', 'Cancelled'], true);
}

/**
 * A status pill. $status is escaped; pass pre-escaped HTML in $label when the
 * stored value needs friendlier wording.
 */
function status_badge(string $status, string $label = ''): string
{
    $text = $label !== '' ? $label : htmlspecialchars($status);

    return '<span class="report-badge ' . status_badge_class($status) . '">' . $text . '</span>';
}

/**
 * A database DATE / DATETIME as readable text. Empty values print as an em
 * dash so tables keep their shape.
 */
function format_date(?string $value, string $format = 'd M Y'): string
{
    $value = trim((string) $value);
    if ($value === '' || str_starts_with($value, '0000')) {
        return '—';
    }
    $time = strtotime($value);

    return $time === false ? htmlspecialchars($value) : date($format, $time);
}

/**
 * Day count between a stored date and today (negative = in the past).
 * Used by the warranty and ageing panels.
 */
function days_from_today(?string $date): ?int
{
    $date = trim((string) $date);
    if ($date === '' || str_starts_with($date, '0000')) {
        return null;
    }
    $time = strtotime($date);
    if ($time === false) {
        return null;
    }

    return (int) floor(($time - strtotime(date('Y-m-d'))) / 86400);
}

/**
 * One KPI tile: a label, a big value and a one-line hint.
 *
 * Lives here rather than in the reporting module because the back office, the
 * dashboard and the reports all present figures the same way — $value is
 * already formatted/escaped by the caller (it may carry markup, e.g. money()).
 */
function report_kpi(string $label, string $value, string $hint = '', string $icon = ''): string
{
    $iconHtml = $icon !== ''
        ? '<span class="report-kpi-icon"><i class="fa-solid ' . htmlspecialchars($icon) . '"></i></span>'
        : '';

    return '<div class="col-6 col-xl-3"><div class="report-kpi">'
        . $iconHtml
        . '<span class="report-kpi-label">' . htmlspecialchars($label) . '</span>'
        . '<span class="report-kpi-value">' . $value . '</span>'
        . ($hint !== '' ? '<span class="report-kpi-hint">' . htmlspecialchars($hint) . '</span>' : '')
        . '</div></div>';
}

/* ----------------------------------------------------------- company data */

/**
 * The seller's own details, printed on quotations, invoices and purchase
 * orders. Defaults live here so the documents render on a fresh checkout;
 * override any key from a 'company' array in config.php.
 */
function company_details(): array
{
    static $details = null;
    if ($details !== null) {
        return $details;
    }

    $cfg = require __DIR__ . '/config.php';
    $details = array_merge([
        'name'     => $cfg['site']['name'] ?? 'SebaBD',
        'legal'    => 'SebaBD Technologies Ltd.',
        'address'  => 'House 27, Road 11, Banani, Dhaka 1213, Bangladesh',
        'phone'    => '+880 1711 100100',
        'email'    => 'sales@sebabd.com',
        'web'      => 'www.sebabd.com',
        'bin'      => 'BIN 003421987-0105',
        'tagline'  => $cfg['site']['tagline'] ?? 'Total IT System Solution',
    ], is_array($cfg['company'] ?? null) ? $cfg['company'] : []);

    return $details;
}

/* --------------------------------------------------------- DB write utils */

/**
 * Run $work inside a transaction and roll back on any failure.
 *
 * All multi-table writes (an order with its items, a quotation converted to an
 * invoice) go through this, so a half-saved document can never happen.
 *
 * @throws Throwable re-thrown after the rollback, for the caller to report.
 */
function db_transaction(PDO $pdo, callable $work): mixed
{
    $pdo->beginTransaction();
    try {
        $result = $work($pdo);
        $pdo->commit();

        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * INSERT one row built from an associative array.
 *
 * The schema uses explicit integer primary keys (no AUTO_INCREMENT), so when
 * $idColumn is given the next free key is allocated first and the caller gets
 * it back. Column names come from the array keys — never from user input.
 *
 * @return int the new primary key, or 0 when the table has no surrogate key.
 */
function db_insert(PDO $pdo, string $table, array $data, string $idColumn = ''): int
{
    $id = 0;
    if ($idColumn !== '') {
        $id  = next_id($pdo, $table, $idColumn);
        $data = [$idColumn => $id] + $data;
    }

    $columns = array_keys($data);
    $sql = 'INSERT INTO `' . $table . '` (`' . implode('`, `', $columns) . '`) VALUES ('
         . implode(', ', array_map(fn($c) => ':' . $c, $columns)) . ')';

    $params = [];
    foreach ($data as $column => $value) {
        $params[':' . $column] = $value;
    }

    $pdo->prepare($sql)->execute($params);

    return $id;
}

/**
 * UPDATE one row matched by an associative $where (all conditions ANDed).
 *
 * @return int rows affected.
 */
function db_update(PDO $pdo, string $table, array $data, array $where): int
{
    if (!$data || !$where) {
        return 0;
    }

    $sets   = [];
    $params = [];
    foreach ($data as $column => $value) {
        $sets[]                    = '`' . $column . '` = :set_' . $column;
        $params[':set_' . $column] = $value;
    }
    foreach ($where as $column => $value) {
        $params[':w_' . $column] = $value;
    }
    $conditions = array_map(fn($c) => '`' . $c . '` = :w_' . $c, array_keys($where));

    $stmt = $pdo->prepare(
        'UPDATE `' . $table . '` SET ' . implode(', ', $sets)
        . ' WHERE ' . implode(' AND ', $conditions)
    );
    $stmt->execute($params);

    return $stmt->rowCount();
}

/**
 * DELETE rows matched by an associative $where. Callers check dependent rows
 * first, because several foreign keys are RESTRICT on purpose.
 */
function db_delete(PDO $pdo, string $table, array $where): int
{
    if (!$where) {
        return 0;
    }

    $params     = [];
    foreach ($where as $column => $value) {
        $params[':w_' . $column] = $value;
    }
    $conditions = array_map(fn($c) => '`' . $c . '` = :w_' . $c, array_keys($where));

    $stmt = $pdo->prepare('DELETE FROM `' . $table . '` WHERE ' . implode(' AND ', $conditions));
    $stmt->execute($params);

    return $stmt->rowCount();
}

/**
 * Count rows matching a simple condition (used before deleting a parent row).
 */
function db_count(string $table, string $whereSql = '', array $params = []): int
{
    $rows = db_fetch_all(
        'SELECT COUNT(*) AS n FROM `' . $table . '`'
        . ($whereSql !== '' ? ' WHERE ' . $whereSql : ''),
        $params
    );

    return (int) ($rows[0]['n'] ?? 0);
}

/* ------------------------------------------------------------ pagination */

/**
 * Work out the current page and OFFSET for a list query.
 *
 * @return array{page:int, perPage:int, offset:int, pages:int, total:int}
 */
function paginate(int $total, int $perPage = 20): array
{
    $perPage = max(1, $perPage);
    $pages   = max(1, (int) ceil($total / $perPage));
    $page    = min($pages, max(1, (int) ($_GET['page'] ?? 1)));

    return [
        'page'    => $page,
        'perPage' => $perPage,
        'offset'  => ($page - 1) * $perPage,
        'pages'   => $pages,
        'total'   => $total,
    ];
}

/**
 * Previous / page x of y / next links. $baseUrl keeps the page's other query
 * parameters; the page number is appended.
 */
function pagination_links(array $pagination, string $baseUrl = ''): string
{
    if ($pagination['pages'] <= 1) {
        return '';
    }

    $baseUrl = $baseUrl !== '' ? $baseUrl : strtok($_SERVER['REQUEST_URI'] ?? '', '?');
    $join    = str_contains($baseUrl, '?') ? '&' : '?';
    $page    = $pagination['page'];

    $link = function (int $target, string $label, bool $disabled, bool $active = false) use ($baseUrl, $join): string {
        if ($disabled) {
            return '<li class="page-item disabled"><span class="page-link">' . $label . '</span></li>';
        }
        $href = $baseUrl . $join . 'page=' . $target;

        return '<li class="page-item' . ($active ? ' active' : '') . '">'
             . '<a class="page-link" href="' . htmlspecialchars($href) . '">' . $label . '</a></li>';
    };

    $out = '<nav aria-label="Pagination"><ul class="pagination pagination-sm mb-0">';
    $out .= $link($page - 1, '&laquo; Prev', $page <= 1);

    // A short window around the current page keeps the control tidy on long lists.
    $start = max(1, $page - 2);
    $end   = min($pagination['pages'], $start + 4);
    $start = max(1, $end - 4);
    for ($i = $start; $i <= $end; $i++) {
        $out .= $link($i, (string) $i, false, $i === $page);
    }

    $out .= $link($page + 1, 'Next &raquo;', $page >= $pagination['pages']);

    return $out . '</ul></nav>';
}

/**
 * "Showing 1–20 of 57" line that accompanies the lists.
 */
function pagination_summary(array $pagination, string $noun = 'record'): string
{
    if ($pagination['total'] === 0) {
        return '';
    }
    $first = $pagination['offset'] + 1;
    $last  = min($pagination['total'], $pagination['offset'] + $pagination['perPage']);

    return 'Showing <strong>' . $first . '–' . $last . '</strong> of <strong>'
         . number_format($pagination['total']) . '</strong> ' . $noun
         . ($pagination['total'] === 1 ? '' : 's');
}
