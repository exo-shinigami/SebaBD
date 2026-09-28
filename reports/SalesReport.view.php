<?php
/**
 * SebaBD — SalesReport view.
 *
 * Rendered by KoolReport with $this bound to the SalesReport instance, so the
 * data stores built in setup() are read through $this->dataStore('name').
 */

if (!defined('SEBABD_REPORTS')) {
    http_response_code(403);
    exit('SebaBD report files are not directly accessible.');
}

use koolreport\widgets\google\ColumnChart;
use koolreport\widgets\google\PieChart;
use koolreport\widgets\koolphp\Table;

$totals  = $this->dataStore('order_totals')->get(0) ?: [];
$lines   = $this->dataStore('line_totals')->get(0) ?: [];
$revenue = (float) ($totals['Revenue'] ?? 0);
$orders  = (int) ($totals['Orders'] ?? 0);
?>

<form class="report-filter" method="get" action="report-sales.php">
    <div class="report-filter-fields">
        <label class="report-filter-field">
            <span>From</span>
            <input type="date" name="from" value="<?= htmlspecialchars($this->from) ?>" class="form-control form-control-sm">
        </label>
        <label class="report-filter-field">
            <span>To</span>
            <input type="date" name="to" value="<?= htmlspecialchars($this->to) ?>" class="form-control form-control-sm">
        </label>
    </div>
    <div class="report-filter-actions">
        <button type="submit" class="btn btn-accent btn-sm"><i class="fa-solid fa-filter me-2"></i>Apply filter</button>
        <a class="btn btn-outline-secondary btn-sm" href="report-sales.php">Reset</a>
        <span class="report-filter-note">Showing: <?= htmlspecialchars($this->rangeLabel()) ?></span>
    </div>
</form>

<div class="row g-3 mb-4">
    <?= report_kpi('Net revenue', money($revenue), $orders . ' order' . ($orders === 1 ? '' : 's') . ' booked', 'fa-sack-dollar') ?>
    <?= report_kpi('Average order', money($totals['AvgOrder'] ?? 0), 'Per completed checkout', 'fa-receipt') ?>
    <?= report_kpi('Units sold', number_format((float) ($lines['Units'] ?? 0)), number_format((float) ($lines['Products'] ?? 0)) . ' distinct products', 'fa-cubes') ?>
    <?= report_kpi('Completed orders', number_format((float) ($totals['Completed'] ?? 0)), 'Buyers: ' . number_format((float) ($totals['Buyers'] ?? 0)), 'fa-circle-check') ?>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <section class="report-panel h-100">
            <?= report_panel_head('Revenue by month', 'Order totals grouped by the month the order was placed.') ?>
            <?php ColumnChart::create([
                'dataStore'  => $this->dataStore('by_month'),
                'columns'    => [
                    'Month'   => ['label' => 'Month'],
                    'Orders'  => SebaBdReport::countColumn('Orders'),
                    'Revenue' => SebaBdReport::moneyColumn('Revenue'),
                ],
                'height'     => '320px',
                'colorScheme' => ['#0ea5b5'],
                'options'    => SebaBdReport::chartOptions([
                    'legend'   => ['position' => 'none'],
                    'bar'      => ['groupWidth' => '45%'],
                    'vAxis'    => ['title' => 'Revenue'],
                ]),
            ]); ?>
        </section>
    </div>
    <div class="col-lg-6">
        <section class="report-panel h-100">
            <?= report_panel_head('Revenue by category', 'Where the money comes from, per product category.') ?>
            <?php PieChart::create([
                'dataStore' => $this->dataStore('by_category'),
                'columns'   => [
                    'Category' => ['label' => 'Category'],
                    'Revenue'  => SebaBdReport::moneyColumn('Revenue'),
                ],
                'height'    => '320px',
                'options'   => SebaBdReport::chartOptions([
                    'pieHole'       => 0.45,
                    'pieSliceText'  => 'percentage',
                    'legend'        => ['position' => 'bottom', 'textStyle' => ['color' => '#1c2430', 'fontSize' => 11]],
                ]),
            ]); ?>
        </section>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <section class="report-panel h-100">
            <?= report_panel_head('Best sellers', 'Top five products by revenue — grouped, ranked and limited with KoolReport data processes.') ?>
            <?php Table::create([
                'dataStore' => $this->dataStore('best_sellers'),
                'columns'   => [
                    '#'         => ['label' => '#'],
                    'Product'   => ['label' => 'Product'],
                    'Brand'     => ['label' => 'Brand'],
                    'Category'  => ['label' => 'Category'],
                    'Quantity'  => SebaBdReport::countColumn('Units'),
                    'Revenue'   => SebaBdReport::moneyColumn('Revenue'),
                ],
                'cssClass'  => ['table' => 'table table-hover align-middle mb-0', 'th' => 'report-th'],
            ]); ?>
        </section>
    </div>
    <div class="col-lg-5">
        <section class="report-panel h-100">
            <?= report_panel_head('Order status mix', 'How much of the booked revenue sits in each status.') ?>
            <?php Table::create([
                'dataStore' => $this->dataStore('by_status'),
                'columns'   => [
                    'Status'   => ['label' => 'Status'],
                    'Orders'   => SebaBdReport::countColumn('Orders', 'sum'),
                    'Revenue'  => SebaBdReport::moneyColumn('Revenue', 'sum'),
                    'SharePct' => ['label' => 'Share', 'type' => 'number', 'decimals' => 1, 'suffix' => ' %'],
                ],
                'showFooter' => true,
                'cssClass'   => ['table' => 'table table-hover align-middle mb-0', 'th' => 'report-th'],
            ]); ?>
        </section>
    </div>
</div>

<section class="report-panel mt-3">
    <?= report_panel_head('Orders in range', 'Every order matching the current filter, newest first.') ?>
    <?php Table::create([
        'dataStore'  => $this->dataStore('recent_orders'),
        'responsive' => true,
        'columns'    => [
            'OrderID'  => ['label' => 'Order #', 'type' => 'number'],
            'Placed'   => ['label' => 'Placed'],
            'Customer' => ['label' => 'Customer'],
            'Items'    => SebaBdReport::countColumn('Items', 'sum'),
            'Status'   => [
                'label' => 'Status',
                'formatValue' => function ($value) {
                    $class = match ($value) {
                        'Completed'  => 'report-badge-ok',
                        'Processing' => 'report-badge-info',
                        'Pending'    => 'report-badge-warn',
                        default      => 'report-badge-plain',
                    };
                    return '<span class="report-badge ' . $class . '">' . htmlspecialchars((string) $value) . '</span>';
                },
            ],
            'Total'    => SebaBdReport::moneyColumn('Total', 'sum'),
        ],
        'showFooter' => true,
        'cssClass'   => ['table' => 'table table-hover align-middle mb-0', 'th' => 'report-th'],
    ]); ?>
</section>
