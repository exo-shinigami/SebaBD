<?php
/**
 * SebaBD — InvoiceReport view.
 */

if (!defined('SEBABD_REPORTS')) {
    http_response_code(403);
    exit('SebaBD report files are not directly accessible.');
}

use koolreport\widgets\google\ComboChart;
use koolreport\widgets\google\PieChart;
use koolreport\widgets\koolphp\Table;

$totals   = $this->dataStore('invoice_totals')->get(0) ?: [];
$billed   = (float) ($totals['Billed'] ?? 0);
$due      = (float) ($totals['Outstanding'] ?? 0);
$invoices = (int) ($totals['Invoices'] ?? 0);
$collectionRate = $billed > 0 ? round(100 * ($billed - $due) / $billed, 1) : 0;
?>

<div class="row g-3 mb-4">
    <?= report_kpi('Billed', money($billed), $invoices . ' invoice' . ($invoices === 1 ? '' : 's') . ' raised', 'fa-file-invoice-dollar') ?>
    <?= report_kpi('Collected', money($totals['Collected'] ?? 0), $collectionRate . ' % of everything billed', 'fa-hand-holding-dollar') ?>
    <?= report_kpi('Outstanding', money($due), 'Still to be recovered', 'fa-hourglass-half') ?>
    <?= report_kpi('Awaiting payment', number_format((int) ($totals['Due'] ?? 0) + (int) ($totals['Partial'] ?? 0)), 'DUE + PARTIAL invoices', 'fa-circle-exclamation') ?>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <section class="report-panel h-100">
            <?= report_panel_head('Billed vs outstanding by month', 'Bars are what was invoiced, the line is what stayed unpaid.') ?>
            <?php ComboChart::create([
                'dataStore' => $this->dataStore('by_month'),
                'columns'   => [
                    'Month'       => ['label' => 'Month'],
                    'Billed'      => SebaBdReport::moneyColumn('Billed'),
                    'Outstanding' => SebaBdReport::moneyColumn('Outstanding'),
                ],
                'height'    => '320px',
                'options'   => SebaBdReport::chartOptions([
                    'seriesType' => 'bars',
                    'bar'        => ['groupWidth' => '55%'],
                    // Series 1 (Outstanding) is the line; series 0 (Billed) stays bars.
                    'series'     => [1 => ['type' => 'line', 'lineWidth' => 3, 'pointSize' => 6]],
                    'vAxis'      => ['title' => 'Amount'],
                    'colors'     => ['#0ea5b5', '#e0565b'],
                ]),
            ]); ?>
        </section>
    </div>
    <div class="col-lg-4">
        <section class="report-panel h-100">
            <?= report_panel_head('Payment status', 'Share of the billed value per status.') ?>
            <?php PieChart::create([
                'dataStore' => $this->dataStore('by_status'),
                'columns'   => [
                    'Status' => ['label' => 'Status'],
                    'Billed' => SebaBdReport::moneyColumn('Billed'),
                ],
                'height'  => '320px',
                'options' => SebaBdReport::chartOptions([
                    'pieHole'      => 0.45,
                    'pieSliceText' => 'percentage',
                    'colors'       => ['#2fa36b', '#f0a500', '#e0565b'],
                    'legend'       => ['position' => 'bottom', 'textStyle' => ['color' => '#1c2430', 'fontSize' => 11]],
                ]),
            ]); ?>
        </section>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <section class="report-panel h-100">
            <?= report_panel_head('Owner of each invoice', 'Billed, collected and still owed per client, worst first.') ?>
            <?php Table::create([
                'dataStore' => $this->dataStore('collection'),
                'columns'   => [
                    'Client'       => ['label' => 'Client'],
                    'Billed'       => SebaBdReport::moneyColumn('Billed', 'sum'),
                    'Outstanding'  => SebaBdReport::moneyColumn('Outstanding', 'sum'),
                    'Collected'    => SebaBdReport::moneyColumn('Collected', 'sum'),
                    'CollectedPct' => ['label' => 'Collected %', 'type' => 'number', 'decimals' => 1, 'suffix' => ' %'],
                ],
                'sorting'    => ['Outstanding' => 'desc'],
                'showFooter' => true,
                'cssClass'   => ['table' => 'table table-hover align-middle mb-0', 'th' => 'report-th'],
            ]); ?>
        </section>
    </div>
    <div class="col-lg-5">
        <section class="report-panel h-100">
            <?= report_panel_head('Receipts by method', 'How the collected money actually arrived.') ?>
            <?php Table::create([
                'dataStore' => $this->dataStore('by_method'),
                'columns'   => [
                    'Method'   => ['label' => 'Payment method'],
                    'Receipts' => SebaBdReport::countColumn('Receipts', 'sum'),
                    'Received' => SebaBdReport::moneyColumn('Received', 'sum'),
                ],
                'showFooter' => true,
                'cssClass'   => ['table' => 'table table-hover align-middle mb-0', 'th' => 'report-th'],
            ]); ?>
        </section>
    </div>
</div>

<section class="report-panel mt-3">
    <?= report_panel_head('Invoices in the books', 'The stored amount-in-words is shown under every total, exactly as it is saved in the invoice table.') ?>
    <?php Table::create([
        'dataStore'  => $this->dataStore('invoices'),
        'responsive' => true,
        'columns'    => [
            'InvoiceNo'   => ['label' => 'Invoice #', 'type' => 'number'],
            'Raised'      => ['label' => 'Date'],
            'Client'      => ['label' => 'Client'],
            'Status'      => [
                'label' => 'Status',
                'formatValue' => function ($status) {
                    $class = match ($status) {
                        'PAID'    => 'report-badge-ok',
                        'PARTIAL' => 'report-badge-warn',
                        'DUE'     => 'report-badge-danger',
                        default   => 'report-badge-plain',
                    };
                    return '<span class="report-badge ' . $class . '">' . htmlspecialchars((string) $status) . '</span>';
                },
            ],
            'Total'       => [
                'label' => 'Invoiced',
                'type'  => 'number',
                'decimals' => 2,
                'prefix' => currency_symbol() . ' ',
                'footer' => 'sum',
                // In the table footer $row is the column key, not a data row.
                'formatValue' => function ($value, $row) {
                    $words = is_array($row) ? (string) ($row['AmountInWords'] ?? '') : '';

                    return money($value) . ($words !== ''
                        ? '<br><small class="text-muted">' . htmlspecialchars($words) . '</small>'
                        : '');
                },
            ],
            'Outstanding' => SebaBdReport::moneyColumn('Balance due', 'sum'),
        ],
        'showFooter' => true,
        'cssClass'   => ['table' => 'table table-hover align-middle mb-0', 'th' => 'report-th'],
    ]); ?>
</section>

<div class="row g-3 mt-1">
    <div class="col-lg-6">
        <section class="report-panel h-100">
            <?= report_panel_head('Money still owed', 'Unpaid balances bucketed by how long they have been outstanding (against today\'s date).') ?>
            <?php Table::create([
                'dataStore' => $this->dataStore('outstanding'),
                'columns'   => [
                    'AgeBucket'   => ['label' => 'Age bucket'],
                    'InvoiceNo'   => ['label' => 'Invoice #', 'type' => 'number'],
                    'Client'      => ['label' => 'Client'],
                    'Raised'      => ['label' => 'Raised'],
                    'AgeDays'     => SebaBdReport::countColumn('Days'),
                    'Outstanding' => SebaBdReport::moneyColumn('Outstanding', 'sum'),
                ],
                'showFooter' => true,
                'cssClass'   => ['table' => 'table table-hover align-middle mb-0', 'th' => 'report-th'],
            ]); ?>
        </section>
    </div>
    <div class="col-lg-6">
        <section class="report-panel h-100">
            <?= report_panel_head('Payment receipts', 'Every receipt issued, newest first, with who took the payment.') ?>
            <?php Table::create([
                'dataStore' => $this->dataStore('receipts'),
                'columns'   => [
                    'ReceiptNo'  => ['label' => 'Receipt #', 'type' => 'number'],
                    'Received'   => ['label' => 'Date'],
                    'InvoiceNo'  => ['label' => 'Invoice #', 'type' => 'number'],
                    'Client'     => ['label' => 'Client'],
                    'Method'     => ['label' => 'Method'],
                    'Amount'     => SebaBdReport::moneyColumn('Amount', 'sum'),
                    'ReceivedBy' => ['label' => 'Received by'],
                ],
                'showFooter' => true,
                'cssClass'   => ['table' => 'table table-hover align-middle mb-0', 'th' => 'report-th'],
            ]); ?>
        </section>
    </div>
</div>
