<?php
/**
 * SebaBD — StockReport view.
 */

if (!defined('SEBABD_REPORTS')) {
    http_response_code(403);
    exit('SebaBD report files are not directly accessible.');
}

use koolreport\widgets\google\BarChart;
use koolreport\widgets\koolphp\Table;

$rows          = $this->dataStore('products')->data() ?? [];
$unitsOnHand   = 0;
$stockValue    = 0.0;
$serialised    = 0;
foreach ($rows as $row) {
    $unitsOnHand += (int) $row['OnHand'];
    $stockValue  += (float) $row['StockValue'];
    $serialised  += (int) $row['SerialisedQty'];
}
?>

<div class="row g-3 mb-4">
    <?= report_kpi('Products listed', number_format(count($rows)), number_format($this->dataStore('by_category')->countData()) . ' categories', 'fa-boxes-stacked') ?>
    <?= report_kpi('Units on hand', number_format($unitsOnHand), number_format($serialised) . ' serialized units', 'fa-warehouse') ?>
    <?= report_kpi('Stock value', money($stockValue), 'Valued at standard selling price', 'fa-sack-dollar') ?>
    <?= report_kpi('Restock alerts', number_format($this->dataStore('restock')->countData()), 'Products at or below 5 units', 'fa-triangle-exclamation') ?>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <section class="report-panel h-100">
            <?= report_panel_head('Stock value per category', 'What the shelves are worth, per product category.') ?>
            <?php BarChart::create([
                'dataStore' => $this->dataStore('by_category'),
                'columns'   => [
                    'Category'   => ['label' => 'Category'],
                    'StockValue' => SebaBdReport::moneyColumn('Stock value'),
                ],
                'height'  => '320px',
                'options' => SebaBdReport::chartOptions([
                    'legend' => ['position' => 'none'],
                    'hAxis'  => ['title' => 'Stock value'],
                ]),
            ]); ?>
        </section>
    </div>
    <div class="col-lg-6">
        <section class="report-panel h-100">
            <?= report_panel_head('Units per category', 'Boxed units plus serialized units marked "In Stock".') ?>
            <?php Table::create([
                'dataStore' => $this->dataStore('by_category'),
                'columns'   => [
                    'Category'    => ['label' => 'Category'],
                    'Products'    => SebaBdReport::countColumn('Products', 'sum'),
                    'UnitsOnHand' => SebaBdReport::countColumn('Units', 'sum'),
                    'StockValue'  => SebaBdReport::moneyColumn('Value', 'sum'),
                ],
                'showFooter' => true,
                'cssClass'   => ['table' => 'table table-hover align-middle mb-0', 'th' => 'report-th'],
            ]); ?>
        </section>
    </div>
</div>

<section class="report-panel">
    <?= report_panel_head(
        'Stock position per product',
        'On hand combines product.StockQty with the product_instance rows that are still In Stock; stock value and reorder quantity are computed by data processes.'
    ) ?>
    <?php Table::create([
        'dataStore'  => $this->dataStore('products'),
        'responsive' => true,
        'columns'    => [
            'Product'         => ['label' => 'Product'],
            'Brand'           => ['label' => 'Brand'],
            'Category'        => ['label' => 'Category'],
            'Vendor'          => ['label' => 'Supplier'],
            'Serialised'      => [
                'label' => 'Tracked',
                'formatValue' => fn ($value) => ((int) $value) === 1
                    ? '<span class="report-badge report-badge-info">Serialized</span>'
                    : '<span class="report-badge report-badge-plain">Bulk</span>',
            ],
            'BoxedQty'        => SebaBdReport::countColumn('Bulk qty', 'sum'),
            'SerialisedQty'   => SebaBdReport::countColumn('Serial qty', 'sum'),
            'OnHand'          => SebaBdReport::countColumn('On hand', 'sum'),
            'WarrantyMonths'  => ['label' => 'Warranty', 'type' => 'number', 'suffix' => ' mo'],
            'Retail'          => SebaBdReport::moneyColumn('Retail'),
            'StockValue'      => SebaBdReport::moneyColumn('Stock value', 'sum'),
            'Reorder'         => [
                'label' => 'To order',
                'formatValue' => fn ($value) => ((int) $value) > 0
                    ? '<span class="report-badge report-badge-warn">' . (int) $value . ' units</span>'
                    : '<span class="text-muted">—</span>',
            ],
        ],
        'showFooter' => true,
        'cssClass'   => ['table' => 'table table-hover align-middle mb-0', 'th' => 'report-th'],
    ]); ?>
</section>

<section class="report-panel mt-3">
    <?= report_panel_head('Restock watchlist', 'Anything at or below the 5-unit threshold, with the supplier to call.') ?>
    <?php Table::create([
        'dataStore' => $this->dataStore('restock'),
        'columns'   => [
            'Product'       => ['label' => 'Product'],
            'Vendor'        => ['label' => 'Supplier'],
            'Phone'         => ['label' => 'Contact'],
            'OnHand'        => SebaBdReport::countColumn('On hand'),
            'SuggestedQty'  => SebaBdReport::countColumn('Suggested order'),
            'Retail'        => SebaBdReport::moneyColumn('Retail'),
        ],
        'cssClass' => ['table' => 'table table-hover align-middle mb-0', 'th' => 'report-th'],
    ]); ?>
</section>

<div class="row g-3 mt-1">
    <div class="col-lg-7">
        <section class="report-panel h-100">
            <?= report_panel_head('Serialized equipment register', 'Every tracked unit with its serial number and warranty cover (against today\'s date).') ?>
            <?php Table::create([
                'dataStore' => $this->dataStore('instances'),
                'columns'   => [
                    'EquipmentID'  => ['label' => 'Unit #', 'type' => 'number'],
                    'SerialNumber' => ['label' => 'Serial number'],
                    'Product'      => ['label' => 'Product'],
                    'Status'       => [
                        'label' => 'Status',
                        'formatValue' => fn ($value) => ((string) $value) === 'In Stock'
                            ? '<span class="report-badge report-badge-ok">In Stock</span>'
                            : '<span class="report-badge report-badge-plain">' . htmlspecialchars((string) $value) . '</span>',
                    ],
                    'PurchasedOn'  => ['label' => 'Purchased'],
                    'VendorCover'  => ['label' => 'Vendor cover to'],
                    'ClientCover'  => ['label' => 'Client cover to'],
                    'DaysLeft'     => ['label' => 'Days left', 'type' => 'number'],
                    'CoverState'   => [
                        'label' => 'Warranty',
                        'formatValue' => function ($value) {
                            $class = match ($value) {
                                'Covered' => 'report-badge-ok',
                                'Expired' => 'report-badge-danger',
                                default   => 'report-badge-plain',
                            };
                            return '<span class="report-badge ' . $class . '">' . htmlspecialchars((string) $value) . '</span>';
                        },
                    ],
                ],
                'cssClass' => ['table' => 'table table-hover align-middle mb-0', 'th' => 'report-th'],
            ]); ?>
        </section>
    </div>
    <div class="col-lg-5">
        <section class="report-panel h-100">
            <?= report_panel_head('Warranty cover per product', 'How many serialized units are still covered and how many have lapsed.') ?>
            <?php Table::create([
                'dataStore' => $this->dataStore('warranty'),
                'columns'   => [
                    'Product'        => ['label' => 'Product'],
                    'Units'          => SebaBdReport::countColumn('Units', 'sum'),
                    'InStock'        => SebaBdReport::countColumn('In stock', 'sum'),
                    'Sold'           => SebaBdReport::countColumn('Sold', 'sum'),
                    'Covered'        => SebaBdReport::countColumn('Covered', 'sum'),
                    'Expired'        => SebaBdReport::countColumn('Expired', 'sum'),
                    'EarliestExpiry' => ['label' => 'Earliest expiry'],
                ],
                'showFooter' => true,
                'cssClass'   => ['table' => 'table table-hover align-middle mb-0', 'th' => 'report-th'],
            ]); ?>
        </section>
    </div>
</div>
