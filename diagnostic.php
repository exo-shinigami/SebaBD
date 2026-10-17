<?php
/**
 * SebaBD — local diagnostic helper for the registration / DB connection issue.
 *
 * This page is a LOCAL DEBUG TOOL. It exists only to show, in one place, which
 * MySQL instance and which database the application is actually talking to, and
 * to let you run a live INSERT test that reproduces the client-insert failure
 * point. It is not part of the storefront.
 *
 * Because it prints connection details AND writes a throw-away row, it is not
 * public: a request is served only from the machine running the site
 * (127.0.0.1 / ::1) or to a signed-in Admin. Anyone else gets a plain 404, so
 * deploying the folder cannot expose the database internals.
 *
 * It reuses the application's existing bootstrap (db.php loads config.php and
 * exposes db() and db_fetch_all()). No database logic is reimplemented here.
 */

// Hard failure mode for this helper only: if the app cannot even include its
// own bootstrap, the page tells you that plainly instead of falling back to a
// broken blank page.
$configProblem = null;
try {
    // helpers.php loads db.php, and also gives this page the shared partial and
    // DB-write helpers it uses (partial_render(), db_insert(), db_delete()).
    require_once __DIR__ . '/helpers.php';
} catch (Throwable $e) {
    $configProblem = 'Could not load the application bootstrap: ' . $e->getMessage();
}

/* ------------------------------------------------------------ who may look
 * The helper shows the database host, user and schema and can insert a test
 * row, so it must never be a public page. Serving it to loopback keeps the
 * local XAMPP workflow unchanged; an Admin may also open it after a deploy,
 * and everyone else gets the ordinary 404 rather than a hint that it exists. */
$diagnosticLocal = in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true);
$diagnosticUser  = function_exists('current_user') ? current_user() : null;
$diagnosticAdmin = $diagnosticUser !== null && ($diagnosticUser['RoleName'] ?? '') === 'Admin';

if (!$diagnosticLocal && !$diagnosticAdmin) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Not found.');
}

$connected       = false;
$connectionError = null;
$serverInfo      = null;
$pdo             = null;

if (!$configProblem) {
    $pdo = db();
    if (!$pdo) {
        $connectionError = 'The application bootstrap loaded, but the PDO connection returned null.';

        // Try to give a more specific reason when the app has hidden it.
        if (defined('APP_DEBUG') && APP_DEBUG) {
            $connectionError .= ' When APP_DEBUG is on, the app logs the raw PDO error to its error log.';
        }
    } else {
        $connected = true;

        $serverInfo = [
            'PDO driver'           => $pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
            'Configured host'      => null,
            'Configured port'      => null,
            'Configured database'  => null,
            'Server hostname'      => null,
            'Server port'          => null,
            'Connected database'   => null,
            'Server version'       => null,
        ];

        try {
            // What the application *asked for* (same file db() reads) ...
            $cfg = require __DIR__ . '/config.php';
            $serverInfo['Configured host']     = (string) ($cfg['db']['host'] ?? '?');
            $serverInfo['Configured port']     = (string) ($cfg['db']['port'] ?? '?');
            $serverInfo['Configured database'] = (string) ($cfg['db']['name'] ?? '?');

            // ... and what the server says it actually is. `SELECT DATABASE()`
            // is the authoritative answer to "which database am I in?": it is
            // what every query on this connection resolves against.
            $row = db_query_one($pdo,
                'SELECT @@hostname AS h, @@port AS p, DATABASE() AS db, VERSION() AS v'
            );
            $serverInfo['Server hostname']    = (string) ($row['h'] ?? '(unable to read)');
            $serverInfo['Server port']        = (string) ($row['p'] ?? '(unable to read)');
            $serverInfo['Connected database'] = (string) ($row['db'] ?? '(none selected)');
            $serverInfo['Server version']     = (string) ($row['v'] ?? '(unable to read)');
        } catch (Throwable $e) {
            foreach (array_keys($serverInfo) as $key) {
                if ($serverInfo[$key] === null) {
                    $serverInfo[$key] = '(unable to read)';
                }
            }
            $serverInfo['_readError'] = $e->getMessage();
        }
    }
}

/** --------------------------------------------------------------------------
 *  Small helpers used by this page only.
 * -------------------------------------------------------------------------- */

/**
 * A Throwable's message, with anything that looks like a password removed.
 * Returns plain text — the result_*() helpers escape it for HTML.
 */
function pdo_message(Throwable $e): string
{
    return (string) preg_replace('/(password=[^\s&]*)/i', 'password=***', $e->getMessage());
}

/** Escape text for HTML output. */
function esc(?string $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/** Table existence check using information_schema.tables. */
function table_exists(PDO $pdo, string $table): bool
{
    $row = db_query_one($pdo,
        'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1',
        [':t' => $table]
    );

    return $row !== null;
}

/** Columns for a table, from INFORMATION_SCHEMA.COLUMNS. */
function table_columns(PDO $pdo, string $table): array
{
    return db_fetch_all(
        'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t
         ORDER BY ORDINAL_POSITION',
        [':t' => $table]
    );
}

/** One row from a SHOW CREATE TABLE call. */
function show_create_table(PDO $pdo, string $table): ?array
{
    return db_query_one($pdo, "SHOW CREATE TABLE `{$table}`");
}

/** The live INSERT test row marker, generated once per request so the cleanup
 *  path can identify the row we may have just created. */
$testRowMarker = bin2hex(random_bytes(8));

$testRowExists  = false;
$testRowError   = null;
$testRowResult  = null;

/** Run the requested action. POST buttons drive this; there is no automatic
 *  action on GET. */
$action       = $_POST['action'] ?? '';
$actionError  = null;

if ($connected && $action !== '') {
    if ($action === 'test_insert') {
        if (!table_exists($pdo, 'client')) {
            $actionError = 'The live INSERT test was not run because the `client` table does not exist in the connected database.';
        } else {
            $token      = $testRowMarker;
            $testName   = 'DIAGNOSTIC TEST — ' . date('Y-m-d H:i:s');
            $testEmail  = 'diag-' . $token . '@local.debug';

            try {
                $pdo->beginTransaction();

                $clientId = db_insert($pdo, 'client', [
                    'UserID'        => null,
                    'CompanyName'   => $testName,
                    'ContactPerson' => 'Diagnostic helper',
                    'Email'         => $testEmail,
                    'Phone'         => null,
                    'Address'       => 'Local diagnostic test row',
                    'AccountType'   => 'individual',
                ], 'ClientID');

                $pdo->commit();

                $testRowExists = true;
                $testRowResult = 'Inserted a test `client` row with ClientID = ' . (string) $clientId . ' and Email = ' . $testEmail;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $testRowError  = pdo_message($e);
                $testRowResult = 'Insert failed — rolled back, no row left behind.';
            }
        }
    }

    if ($action === 'clear_test_row') {
        // Remove any diagnostic row we may have created. We look for the email
        // pattern we used for the test so the cleanup is specific.
        $rows = db_fetch_all(
            "SELECT ClientID FROM client WHERE Email LIKE 'diag-%' LIMIT 1"
        );
        if (!$rows) {
            $actionError = 'No diagnostic test row was found to remove.';
        } else {
            try {
                $pdo->beginTransaction();
                db_delete($pdo, 'client', ['ClientID' => (int) $rows[0]['ClientID']]);
                $pdo->commit();
                $testRowExists = false;
                $testRowResult = 'Removed the diagnostic test row.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $actionError = 'The test row looks removable, but the delete failed: ' . pdo_message($e);
            }
        }
    }
}

if ($connected && $testRowExists) {
    // Refresh the marker so the page does not lie about the cleanup state.
    $testRowExists = db_fetch_all(
        "SELECT ClientID FROM client WHERE Email LIKE 'diag-%' LIMIT 1"
    ) !== [];
}

// ---- Result helpers ---------------------------------------------------------

function result_pass(string $label, ?string $detail = null): string
{
    $out = '<div class="diagnostic-pass"><span class="diagnostic-badge diagnostic-pass-badge">PASS</span>'
        . '<span class="diagnostic-label">' . esc($label) . '</span></div>';
    if ($detail !== null) {
        $out .= '<div class="diagnostic-detail">' . esc($detail) . '</div>';
    }

    return $out;
}

function result_fail(string $label, string $detail): string
{
    return '<div class="diagnostic-fail"><span class="diagnostic-badge diagnostic-fail-badge">FAIL</span>'
        . '<span class="diagnostic-label">' . esc($label) . '</span>'
        . '<div class="diagnostic-detail">' . $detail . '</div></div>';
}

function result_info(string $label, string $detail): string
{
    return '<div class="diagnostic-info"><span class="diagnostic-label">' . esc($label) . '</span>'
        . '<div class="diagnostic-detail">' . $detail . '</div></div>';
}

function result_raw(string $label, string $raw): string
{
    return '<div class="diagnostic-raw"><span class="diagnostic-label">' . esc($label) . '</span>'
        . '<pre class="diagnostic-pre">' . esc($raw) . '</pre></div>';
}

// ---- Build the results block -----------------------------------------------

$results = [];

if ($configProblem) {
    $results[] = result_fail('Application bootstrap', $configProblem);

} elseif (!$connected) {
    $results[] = result_fail('Database connection', $connectionError);
    $results[] = result_info('Config path', 'db.php loaded config.php from the same directory as this diagnostic page.');

} else {
    // Server info
    $infoLines = [];
    foreach ($serverInfo as $key => $value) {
        if ($key === '_readError') {
            continue;
        }
        $infoLines[] = $key . ': ' . (string) $value;
    }
    $results[] = result_info('PDO server info', implode("\n", $infoLines));

    if (!empty($serverInfo['_readError'])) {
        $results[] = result_fail('Server info read', pdo_message(new PDOException($serverInfo['_readError'])));
    }

    // Table existence checks
    $tablesToCheck = ['client', 'user', 'client_branch', 'quotation', 'invoice', 'order'];
    foreach ($tablesToCheck as $table) {
        if (table_exists($pdo, $table)) {
            $results[] = result_pass("Table exists: {$table}");
        } else {
            $results[] = result_fail("Table exists: {$table}", 'This table was not found in the connected database.');
        }
    }

    // client detail
    if (table_exists($pdo, 'client')) {
        $create = show_create_table($pdo, 'client');
        if ($create) {
            $results[] = result_raw('SHOW CREATE TABLE client', (string) ($create['Create Table'] ?? ''));
        } else {
            $results[] = result_fail('SHOW CREATE TABLE client', 'The statement did not return a result.');
        }

        $columns = table_columns($pdo, 'client');
        if ($columns) {
            $columnLines = [];
            foreach ($columns as $c) {
                $columnLines[] = implode('  |  ', [
                    (string) ($c['COLUMN_NAME'] ?? ''),
                    (string) ($c['COLUMN_TYPE'] ?? ''),
                    (string) ($c['IS_NULLABLE'] ?? ''),
                    (string) ($c['COLUMN_DEFAULT'] ?? ''),
                    (string) ($c['EXTRA'] ?? ''),
                ]);
            }
            $results[] = result_raw('client columns', implode("\n", $columnLines));
        } else {
            $results[] = result_fail('client columns', 'Could not list columns.');
        }
    }

    // user detail
    if (table_exists($pdo, 'user')) {
        $create = show_create_table($pdo, 'user');
        if ($create) {
            $results[] = result_raw('SHOW CREATE TABLE user', (string) ($create['Create Table'] ?? ''));
        } else {
            $results[] = result_fail('SHOW CREATE TABLE user', 'The statement did not return a result.');
        }

        $columns = table_columns($pdo, 'user');
        if ($columns) {
            $columnLines = [];
            foreach ($columns as $c) {
                $columnLines[] = implode('  |  ', [
                    (string) ($c['COLUMN_NAME'] ?? ''),
                    (string) ($c['COLUMN_TYPE'] ?? ''),
                    (string) ($c['IS_NULLABLE'] ?? ''),
                    (string) ($c['COLUMN_DEFAULT'] ?? ''),
                    (string) ($c['EXTRA'] ?? ''),
                ]);
            }
            $results[] = result_raw('user columns', implode("\n", $columnLines));
        } else {
            $results[] = result_fail('user columns', 'Could not list columns.');
        }
    }

    // Live INSERT test
    $results[] = result_info(
        'Live test status',
        $testRowExists
            ? 'A diagnostic test row is present in the connected database.'
            : 'No diagnostic test row is present in the connected database.'
    );

    if ($testRowResult !== null) {
        $results[] = result_info('Live test result', $testRowResult);
    }
    if ($testRowError !== null) {
        $results[] = result_fail('Live test error', $testRowError);
    }
    if ($actionError !== null) {
        $results[] = result_fail('Action', $actionError);
    }
}

// ---- HTML ------------------------------------------------------------------

echo partial_render('partials/header.php', [
    'page_title' => 'DB diagnostic',
    'active_nav' => '',
]);

$debugVisible = defined('APP_DEBUG') && APP_DEBUG;

?>
<nav aria-label="Breadcrumb" class="mb-2">
  <ol class="breadcrumb breadcrumb-dense mb-0">
    <li class="breadcrumb-item"><a href="index.php">Home</a></li>
    <li class="breadcrumb-item active" aria-current="page">DB diagnostic</li>
  </ol>
</nav>

<div class="container diagnostic-container">
  <div class="diagnostic-card">
    <div class="diagnostic-card-head">
      <h1 class="h3 mb-1">Local DB diagnostic</h1>
      <p class="text-muted mb-0 small">
        A local debug helper for the registration issue. It shows exactly which
        MySQL instance and which database the application is connected to, and
        lets you run the live INSERT test that reproduces the client-insert
        failure.
      </p>
    </div>

    <div class="alert alert-warning d-flex align-items-start">
      <i class="fa-solid fa-triangle-exclamation fa-fw me-2 fs-6"></i>
      <div>
        <strong>Local debug tool — do not leave this page publicly accessible.</strong>
        It exposes database connection details and lets anyone run a write test
        against the connected database.
        <?php if ($debugVisible): ?>
        <span class="text-muted fs-6">APP_DEBUG is on, so raw database errors are shown on this page.</span>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($configProblem): ?>
      <div class="alert alert-danger mb-3">
        <i class="fa-solid fa-circle-exclamation fa-fw me-2"></i>
        <?= esc($configProblem) ?>
      </div>

    <?php elseif (!$connected): ?>
      <div class="alert alert-danger mb-3">
        <i class="fa-solid fa-circle-exclamation fa-fw me-2"></i>
        <?= esc($connectionError) ?>
      </div>

      <div class="card mb-3">
        <div class="card-body py-3">
          <h2 class="h5 mb-2">What to check first</h2>
          <ol class="list-numbered list-spaced mb-0">
            <li>Open your <code>config.php</code> and confirm the <code>'db'</code> values match the MySQL instance phpMyAdmin uses.</li>
            <li>If phpMyAdmin is connected to a non-standard port or a non-default host, update this page’s config to match.</li>
            <li>If the app and phpMyAdmin are on different machines or different MySQL installs, they are not looking at the same database.</li>
          </ol>
        </div>
      </div>

    <?php else: ?>
      <div class="diagnostic-section">
        <h2 class="h5 mb-3">1. Where the application is connected</h2>
        <?php foreach ($results as $result): ?>
          <?= $result ?>
        <?php endforeach; ?>
      </div>

      <div class="diagnostic-section">
        <h2 class="h5 mb-3">2. Live test</h2>
        <p class="text-muted small mb-3">
          If the <code>client</code> table exists in the connected database, this inserts a throwaway row and reports whatever MySQL says.
          The row is created inside a transaction and rolled back on failure, so a failed test does not leave junk behind.
        </p>

        <form method="post" class="row g-3 align-items-end">
          <input type="hidden" name="action" value="test_insert">
          <div class="col-12">
            <button type="submit" name="test_insert" value="1" class="btn btn-accent">Run live INSERT test on client</button>
          </div>
        </form>

        <?php if ($testRowExists): ?>
          <hr class="my-4">
          <form method="post" class="row g-3 align-items-end">
            <input type="hidden" name="action" value="clear_test_row">
            <div class="col-12">
              <button type="submit" name="clear_test_row" value="1" class="btn btn-outline-danger">Remove the diagnostic test row</button>
            </div>
          </form>
        <?php endif; ?>
      </div>

      <div class="diagnostic-section">
        <h2 class="h5 mb-3">3. How to read this</h2>
        <ul class="list-unstyled mb-0">
          <li>If the connected database does <strong>not</strong> contain <code>client</code>, that is why registration fails with “Could not create the account.”</li>
          <li>If it <strong>does</strong> contain <code>client</code>, the live test shows whether the application can actually insert into it, and the exact error if it cannot.</li>
          <li>If phpMyAdmin shows tables that this page does not, the app and phpMyAdmin are connected to different MySQL instances or different databases.</li>
        </ul>
      </div>

    <?php endif; ?>
  </div>
</div>

<?= partial_render('partials/footer.php') ?>
