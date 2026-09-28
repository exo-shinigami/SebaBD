<?php
/**
 * SebaBD — QuotationReport view.
 */

if (!defined('SEBABD_REPORTS')) {
    http_response_code(403);
    exit('SebaBD report files are not directly accessible.');
}

use koolreport\widgets\google\ColumnChart;
use koolreport\widgets\google\PieChart;
use koolreport\widgets\koolphp\Table;

$totals  = $this->dataStore('totals')->get(0) ?: [];
$quoted  = (float) ($totals['Quoted'] ?? 0);
$count   = (int) ($totals['Quotations'] ?? 0);
?>

<div class="row g-3 mb-4">
    <?= report_kpi('Quoted value', money($quoted), $count . ' quotation' . ($count === 1 ? '' : 's'), 'fa-file-signature') ?>
    <?= report_kpi('Average quotation', money($count > 0 ? $quoted / $count : 0), 'Value per document raised', 'fa-calculator') ?>
    <?= report_kpi('Accepted / pending', number_format((int) ($totals['Accepted'] ?? 0)) . ' / ' . number_format((int) ($totals['Pending'] ?? 0)), number_format((int) ($totals['Rejected'] ?? 0)) . ' rejected', 'fa-scale-balanced') ?>
    <?= report_kpi('Converted to invoice', $this->conversionRate() . ' %', number_format((int) ($totals['Converted'] ?? 0)) . ' invoices worth ' . money($totals['ConvertedValue'] ?? 0), 'fa-arrow-right-to-city') ?>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <section class="report-panel h-100">
            <?= report_panel_head('Quoted vs invoiced per month', 'A quotation only becomes revenue once its invoice exists (invoice.QuotationNo).') ?>
            <?php ColumnChart::create([
                'dataStore' => $this->dataStore('by_month'),
                'columns'   => [
                    'Month'      => ['label' => 'Month'],
                    'Quotations' => SebaBdReport::countColumn('Quotations'),
                    'Quoted'     => SebaBdReport::moneyColumn('Quoted'),
                    'Converted'  => SebaBdReport::moneyColumn('Converted'),
                ],
                'height'  => '320px',
                'options' => SebaBdReport::chartOptions([
                    'bar'    => ['groupWidth' => '55%'],
                    'vAxis'  => ['title' => 'Quoted value'],
                    'colors' => ['#0ea5b5', '#2fa36b'],
                ]),
            ]); ?>
        </section>
    </div>
    <div class="col-lg-4">
        <section class="report-panel h-100">
            <?= report_panel_head('Pipeline by status', 'Where the quoted value currently sits.') ?>
            <?php PieChart::create([
                'dataStore' => $this->dataStore('by_status'),
                'columns'   => [
                    'Status' => ['label' => 'Status'],
                    'Quoted' => SebaBdReport::moneyColumn('Quoted'),
                ],
                'height'  => '320px',
                'options' => SebaBdReport::chartOptions([
                    'pieHole'      => 0.45,
                    'pieSliceText' => 'value',
                    'colors'       => ['#2fa36b', '#f0a500', '#e0565b'],
                    'legend'       => ['position' => 'bottom', 'textStyle' => ['color' => '#1c2430', 'fontSize' => 11]],
                ]),
            ]); ?>
        </section>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <section class="report-panel h-100">
            <?= report_panel_head('Quotations per branch', 'B2B demand grouped by the client branch the quotation was raised for.') ?>
            <?php Table::create([
                'dataStore' => $this->dataStore('by_branch'),
                'columns'   => [
                    'Client'     => ['label' => 'Client'],
                    'Branch'     => ['label' => 'Branch'],
                    'Quotations' => SebaBdReport::countColumn('Quotations', 'sum'),
                    'Quoted'     => SebaBdReport::moneyColumn('Quoted', 'sum'),
                ],
                'showFooter' => true,
                'cssClass'   => ['table' => 'table table-hover align-middle mb-0', 'th' => 'report-th'],
            ]); ?>
        </section>
    </div>
    <div class="col-lg-7">
        <section class="report-panel h-100">
            <?= report_panel_head('Most quoted products', 'Quantity, negotiated unit price and the warranty the quotation offered.') ?>
            <?php Table::create([
                'dataStore' => $this->dataStore('top_items'),
                'columns'   => [
                    'Product'      => ['label' => 'Product'],
                    'Category'     => ['label' => 'Category'],
                    'Quotations'   => SebaBdReport::countColumn('Quotations', 'sum'),
                    'Units'        => SebaBdReport::countColumn('Units', 'sum'),
                    'AvgUnitPrice' => SebaBdReport::moneyColumn('Avg unit price'),
                    'Warranty'     => ['label' => 'Warranty', 'type' => 'number', 'suffix' => ' mo'],
                    'Quoted'       => SebaBdReport::moneyColumn('Quoted', 'sum'),
                ],
                'showFooter' => true,
                'cssClass'   => ['table' => 'table table-hover align-middle mb-0', 'th' => 'report-th'],
            ]); ?>
        </section>
    </div>
</div>

<section class="report-panel mt-3">
    <?= report_panel_head('Quotation register', 'Each quotation with its subject, stored amount in words and the invoice it produced.') ?>
    <?php Table::create([
        'dataStore'  => $this->dataStore('quotations'),
        'responsive' => true,
        'columns'    => [
            'QuotationNo' => ['label' => 'Quotation #', 'type' => 'number'],
            'Raised'      => ['label' => 'Date'],
            'Subject'     => ['label' => 'Subject'],
            'Client'      => ['label' => 'Client'],
            'Status'      => [
                'label' => 'Status',
                'formatValue' => function ($status) {
                    $class = match ($status) {
                        'Accepted' => 'report-badge-ok',
                        'Pending'  => 'report-badge-warn',
                        'Rejected' => 'report-badge-danger',
                        default    => 'report-badge-plain',
                    };
                    return '<span class="report-badge ' . $class . '">' . htmlspecialchars((string) $status) . '</span>';
                },
            ],
            'Quoted'      => [
                'label' => 'Quoted',
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
            'InvoiceNo'   => [
                'label' => 'Became invoice',
                // A NULL from the LEFT JOIN arrives as 0 (the widget's empty
                // value), so test the number rather than null.
                'formatValue' => function ($value) {
                    return ((int) $value) > 0
                        ? '<span class="report-badge report-badge-info">Invoice #' . (int) $value . '</span>'
                        : '<span class="report-badge report-badge-plain">Not invoiced</span>';
                },
            ],
        ],
        'showFooter' => true,
        'cssClass'   => ['table' => 'table table-hover align-middle mb-0', 'th' => 'report-th'],
    ]); ?>
</section>
