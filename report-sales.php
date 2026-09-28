<?php
/**
 * SebaBD — Sales & revenue report (staff only).
 *
 * The date filter travels in the query string; report_date() throws away
 * anything that is not a real Y-m-d date before it reaches the report.
 */
require_once __DIR__ . '/reports/bootstrap.php';

report_page('report-sales.php', [
    'from' => report_date($_GET['from'] ?? ''),
    'to'   => report_date($_GET['to'] ?? ''),
]);
