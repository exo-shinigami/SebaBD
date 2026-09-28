<?php
/**
 * SebaBD — KoolReport reporting layer.
 *
 * Layout of the module:
 *
 *   reports.php                      hub page, one card per report
 *   report-*.php                     two-line entry page per report
 *   reports/<Name>Report.php         report class: data sources (settings())
 *                                    and the SQL → dataStore pipelines (setup())
 *   reports/<Name>Report.view.php    the report's HTML: charts, tables, KPIs
 *   vendor/koolreport/core           the KoolReport 6.7.1 library (MIT)
 *
 * The reports read the *same* database and currency as the storefront, so the
 * two can never drift apart: connection details come from config.php and the
 * widgets reuse the storefront's own PDO handle (db.php).
 */

/**
 * Report classes and views live under the web root, so they refuse to run
 * unless bootstrap.php has been loaded first — and this file itself refuses to
 * run as the entry script.
 */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('SebaBD report bootstrap is not directly accessible.');
}

define('SEBABD_REPORTS', true);

require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/vendor/koolreport/core/autoload.php';

use koolreport\core\Utility;

/* --------------------------------------------------------------- paths */

/**
 * Absolute filesystem path inside the project (forward slashes on Windows).
 */
function project_path(string $relative = ''): string
{
    $root = str_replace('\\', '/', dirname(__DIR__));

    return $relative === '' ? $root : $root . '/' . ltrim($relative, '/');
}

/* ----------------------------------------------------- base report class */

/**
 * Everything a SebaBD report shares: one data source, one published asset
 * folder and one chart look-and-feel.
 *
 * A report subclass normally only implements setup(), piping SQL queries into
 * named data stores that its view then renders.
 *
 * Note for view authors: the column keys passed to Table::create()/Chart::create()
 * must match the data store's column names (the SQL aliases) exactly. A typo is
 * silent — the widget falls back to its empty value instead of failing.
 */
abstract class SebaBdReport extends \koolreport\KoolReport
{
    /**
     * The "db" data source plus the folder the widgets' js/css is published to.
     */
    protected function settings()
    {
        $cfg = require project_path('config.php');
        $db  = $cfg['db'];

        return [
            'dataSources' => [
                'db' => [
                    'class' => '\koolreport\datasources\PdoDataSource',
                    // Reuse the storefront's single PDO handle so reports get the
                    // same credentials, charset and connection flags as db.php.
                    'connection' => db(),
                    'connectionString' => sprintf(
                        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                        $db['host'],
                        $db['port'],
                        $db['name'],
                        $db['charset']
                    ),
                    'username' => $db['user'],
                    'password' => $db['password'],
                ],
            ],
            'assets' => [
                // Widget js/css is copied here (once) and served by the site
                // instead of from inside the library. The URL is deliberately
                // relative: every report page sits in the project root, so the
                // links stay correct whether the site is served from htdocs
                // ("/Ecommerce Website/...") or from `php -S`.
                'path' => project_path('assets/report-assets'),
                'url'  => 'assets/report-assets',
            ],
        ];
    }

    /**
     * Google Charts options tuned to the SebaBD palette (light page, teal
     * accent) so every chart in the module looks the same.
     */
    public static function chartOptions(array $overrides = []): array
    {
        $axis = [
            'textStyle'      => ['color' => '#66707d', 'fontSize' => 11],
            'titleTextStyle' => ['color' => '#66707d', 'italic' => false, 'bold' => false],
            'gridlines'      => ['color' => '#e6ebf0'],
            'baselineColor'  => '#cbd5e1',
            // Tick labels stay short (12K) so they never clip on narrow panels;
            // tooltips still show the full "$ 12,000.00" formatted value.
            'format'         => 'short',
        ];

        $base = [
            'backgroundColor' => 'transparent',
            'chartArea'       => ['left' => 70, 'top' => 30, 'width' => '84%', 'height' => '68%'],
            'fontName'        => "'Segoe UI', Tahoma, Geneva, Verdana, sans-serif",
            'legend'          => ['position' => 'bottom', 'textStyle' => ['color' => '#1c2430', 'fontSize' => 12]],
            'titleTextStyle'  => ['color' => '#10141b', 'fontSize' => 15, 'bold' => true],
            'tooltip'         => ['textStyle' => ['color' => '#10141b', 'fontSize' => 12]],
            'hAxis'           => $axis,
            'vAxis'           => $axis,
            'colors'          => ['#0ea5b5', '#f0a500', '#3d7ff2', '#e0565b', '#8b5cf6', '#2fa36b'],
        ];

        return Utility::arrayMergeRecursive($base, $overrides);
    }

    /**
     * Column settings for a money column: site currency, two decimals, the
     * same "$ 1,200.00" shape the storefront prints with money().
     *
     * @param string $footer Aggregation for the table footer, e.g. 'sum'.
     */
    public static function moneyColumn(string $label = '', string $footer = ''): array
    {
        return self::withFooter([
            'label'    => $label,
            'type'     => 'number',
            'decimals' => 2,
            'prefix'   => currency_symbol() . ' ',
        ], $footer);
    }

    /**
     * Column settings for a quantity / count column.
     */
    public static function countColumn(string $label = '', string $footer = ''): array
    {
        return self::withFooter(['label' => $label, 'type' => 'number'], $footer);
    }

    /**
     * Attach a footer aggregation to a column definition.
     */
    private static function withFooter(array $column, string $footer): array
    {
        if ($footer !== '') {
            $column['footer'] = $footer;
        }

        return $column;
    }
}

/* ----------------------------------------------------------- catalogue */

/**
 * Every report the module knows about, keyed by its entry page.
 *
 * "roles" is the authorization rule the hub, the entry page and its cards all
 * read from — one place to change who may open what.
 */
function report_catalogue(): array
{
    return [
        'report-sales.php' => [
            'class' => 'SalesReport',
            'title' => 'Sales & revenue',
            'blurb' => 'Order volume, revenue trend, best sellers and the order status mix.',
            'roles' => ['Admin', 'Sales Manager'],
            'icon'  => 'fa-chart-line',
        ],
        'report-invoices.php' => [
            'class' => 'InvoiceReport',
            'title' => 'Invoicing & receivables',
            'blurb' => 'Invoices raised, payments received and what clients still owe.',
            'roles' => ['Admin', 'Sales Manager'],
            'icon'  => 'fa-file-invoice-dollar',
        ],
        'report-quotations.php' => [
            'class' => 'QuotationReport',
            'title' => 'Quotation pipeline',
            'blurb' => 'Quotations by status and branch, with the quoted amount in words.',
            'roles' => ['Admin', 'Sales Manager'],
            'icon'  => 'fa-file-signature',
        ],
        'report-procurement.php' => [
            'class' => 'ProcurementReport',
            'title' => 'Procurement & vendors',
            'blurb' => 'Purchase orders per vendor and what has been bought from each supplier.',
            'roles' => ['Admin', 'Inventory Manager'],
            'icon'  => 'fa-truck-ramp-box',
        ],
        'report-stock.php' => [
            'class' => 'StockReport',
            'title' => 'Stock & warranty',
            'blurb' => 'Stock on hand by category, serialized equipment and warranty cover.',
            'roles' => ['Admin', 'Inventory Manager'],
            'icon'  => 'fa-boxes-stacked',
        ],
    ];
}

/**
 * May the given (or current) user open this catalogue entry?
 */
function report_allowed(array $report, ?array $user = null): bool
{
    $user = $user ?? current_user();

    return $user !== null && in_array($user['RoleName'] ?? '', $report['roles'], true);
}

/**
 * Accept a Y-m-d value coming from a query string, reject anything else.
 * Returns '' when the value is missing or malformed.
 */
function report_date(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    $date = \DateTime::createFromFormat('!Y-m-d', $value);

    return ($date && $date->format('Y-m-d') === $value) ? $value : '';
}

/* ------------------------------------------------------- page helpers */

/**
 * One KPI tile. $value is already formatted/escaped by the caller.
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

/**
 * Standard heading block for a panel inside a report.
 */
function report_panel_head(string $title, string $subtitle = ''): string
{
    return '<header class="report-panel-head">'
        . '<h2 class="report-panel-title">' . $title . '</h2>'
        . ($subtitle !== '' ? '<p class="report-panel-sub">' . $subtitle . '</p>' : '')
        . '</header>';
}

/**
 * Run one report and print it inside the storefront's normal page chrome.
 *
 * @param string $page   Entry page name, a key of report_catalogue().
 * @param array  $params Report parameters (e.g. date filters).
 */
function report_page(string $page, array $params = []): void
{
    $catalogue = report_catalogue();
    $meta      = $catalogue[$page] ?? null;
    if ($meta === null) {
        http_response_code(404);
        echo 'Unknown report.';
        return;
    }

    $user       = require_staff();
    $active_nav = 'reports';

    if (!report_allowed($meta, $user)) {
        http_response_code(403);
        echo partial_render('partials/header.php', ['page_title' => 'Not available', 'active_nav' => $active_nav]);
        echo report_lead('Not available for your role', 'The ' . htmlspecialchars($meta['title']) . ' report is limited to the '
            . htmlspecialchars(implode(' / ', $meta['roles'])) . ' roles. You are signed in as '
            . htmlspecialchars($user['RoleName']) . '.');
        echo '<div class="container pb-5"><a class="btn btn-accent" href="reports.php">'
            . '<i class="fa-solid fa-arrow-left me-2"></i>Back to all reports</a></div>';
        echo partial_render('partials/footer.php');
        return;
    }

    if (!db()) {
        echo partial_render('partials/header.php', ['page_title' => $meta['title'], 'active_nav' => $active_nav]);
        echo report_lead($meta['title'], 'The reports read live data from the store.');
        echo '<div class="container pb-5"><div class="alert alert-danger mb-0">'
            . 'Cannot reach the <strong>e_commerce</strong> database. Start MySQL in the XAMPP control panel and '
            . 'import <code>database/e_commerce.sql</code>, then reload this page.</div></div>';
        echo partial_render('partials/footer.php');
        return;
    }

    require_once __DIR__ . '/' . $meta['class'] . '.php';
    $class  = $meta['class'];
    $report = new $class($params);

    echo partial_render('partials/header.php', ['page_title' => $meta['title'], 'active_nav' => $active_nav]);
    echo report_lead($meta['title'], $meta['blurb']);
    echo '<div class="container pb-5">';
    $report->run()->render();
    echo '</div>';
    echo partial_render('partials/footer.php');
}

/**
 * The coloured heading strip every report page opens with.
 *
 * $title and $subtitle are inserted as-is, so escape any dynamic text first.
 */
function report_lead(string $title, string $subtitle = ''): string
{
    return '<section class="report-lead py-4 mb-4">'
        . '<div class="container d-flex flex-wrap justify-content-between align-items-end gap-3">'
        . '<div><span class="section-label">Reports</span>'
        . '<h1 class="h3 fw-bold mb-1 text-white">' . htmlspecialchars($title) . '</h1>'
        . ($subtitle !== '' ? '<p class="mb-0 report-lead-sub">' . $subtitle . '</p>' : '')
        . '</div><a class="btn btn-light btn-sm" href="reports.php">'
        . '<i class="fa-solid fa-table-list me-2"></i>All reports</a></div></section>';
}
