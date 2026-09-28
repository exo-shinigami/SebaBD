<?php
/**
 * SebaBD — ProcurementReport view.
 */

if (!defined('SEBABD_REPORTS')) {
    http_response_code(403);
    exit('SebaBD report files are not directly accessible.');
}

use koolreport\widgets\google\BarChart;
use koolreport\widgets\google\ColumnChart;
use koolreport\widgets\koolphp\Table;

$totals    = $this->dataStore('totals')->get(0) ?: [];
$purchased = (float) ($totals['Purchased'] ?? 0);
$orders    = (int) ($totals['PurchaseOrders'] ?? 0);
?>

<div class="row g-3 mb-4">
    <?= report_kpi('Total purchased', money($purchased), $orders . ' purchase order' . ($orders === 1 ? '' : 's'), 'fa-truck-ramp-box') ?>
    <?= report_kpi('Average order', money($totals['AvgOrder'] ?? 0), 'Per purchase order', 'fa-file-invoice') ?>
    <?= report_kpi('Active vendors', number_format((int) ($totals['Vendors'] ?? 0)), 'Suppliers with an order', 'fa-handshake') ?>
    <?= report_kpi('Order window', ($totals['FirstOrder'] ?? '—') . ' → ' . ($totals['LastOrder'] ?? '—'), 'First to last purchase order', 'fa-calendar-days') ?>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-7">
        <section class="report-panel h-100">
            <?= report_panel_head('Spend per vendor', 'Who SebaBD buys from, biggest supplier first.') ?>
            <?php BarChart::create([
                'dataStore' => $this->dataStore('by_vendor'),
                'columns'   => [
                    'Vendor'    => ['label' => 'Vendor'],
                    'Purchased' => SebaBdReport::moneyColumn('Purchased'),
                ],
                'height'  => '320px',
                'options' => SebaBdReport::chartOptions([
                    'legend' => ['position' => 'none'],
                    'hAxis'  => ['title' => 'Purchased'],
                ]),
            ]); ?>
        </section>
    </div>
    <div class="col-lg-5">
        <section class="report-panel h-100">
            <?= report_panel_head('Spend per month', 'Purchase orders grouped by the month they were raised.') ?>
            <?php ColumnChart::create([
                'dataStore' => $this->dataStore('by_month'),
                'columns'   => [
                    'Month'          => ['label' => 'Month'],
                    'PurchaseOrders' => SebaBdReport::countColumn('Orders'),
                    'Purchased'      => SebaBdReport::moneyColumn('Purchased'),
                ],
                'height'  => '320px',
                'colorScheme' => ['#0ea5b5'],
                'options' => SebaBdReport::chartOptions([
                    'legend' => ['position' => 'none'],
                    'bar'    => ['groupWidth' => '45%'],
                    'vAxis'  => ['title' => 'Purchased'],
                ]),
            ]); ?>
        </section>
    </div>
</div>

<section class="report-panel">
    <?= report_panel_head('Purchase orders', 'Every order placed with a supplier, with its payment terms.') ?>
    <?php Table::create([
        'dataStore'  => $this->dataStore('purchase_orders'),
        'responsive' => true,
        'columns'    => [
            'PONo'    => ['label' => 'PO #', 'type' => 'number'],
            'PONDate' => ['label' => 'Date'],
            'Vendor'  => ['label' => 'Vendor'],
            'Location' => ['label' => 'Location'],
            'Items'   => SebaBdReport::countColumn('Items', 'sum'),
            'Terms'   => ['label' => 'Terms'],
            'Total'   => SebaBdReport::moneyColumn('Total', 'sum'),
        ],
        'showFooter' => true,
        'cssClass'   => ['table' => 'table table-hover align-middle mb-0', 'th' => 'report-th'],
    ]); ?>
</section>

<section class="report-panel mt-3">
    <?= report_panel_head(
        'Bought products & their margin',
        'Wholesale cost against the current standard selling price — the margin column is computed with a KoolReport data process.'
    ) ?>
    <?php Table::create([
        'dataStore'  => $this->dataStore('purchased_products'),
        'responsive' => true,
        'columns'    => [
            'Product'          => ['label' => 'Product'],
            'Category'         => ['label' => 'Category'],
            'Vendor'           => ['label' => 'Vendor'],
            'Units'            => SebaBdReport::countColumn('Units', 'sum'),
            'AvgCost'          => SebaBdReport::moneyColumn('Avg unit cost'),
            'Retail'           => SebaBdReport::moneyColumn('Sells for'),
            'MarginPct'        => [
                'label'    => 'Margin',
                'type'     => 'number',
                'decimals' => 1,
                'suffix'   => ' %',
                'formatValue' => function ($value) {
                    $class = ((float) $value) >= 25 ? 'report-badge-ok' : 'report-badge-warn';
                    return '<span class="report-badge ' . $class . '">' . number_format((float) $value, 1) . ' %</span>';
                },
            ],
            'Purchased'        => SebaBdReport::moneyColumn('Purchased', 'sum'),
            'PotentialRevenue' => SebaBdReport::moneyColumn('If resold', 'sum'),
        ],
        'showFooter' => true,
        'cssClass'   => ['table' => 'table table-hover align-middle mb-0', 'th' => 'report-th'],
    ]); ?>
</section>
