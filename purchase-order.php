<?php
/**
 * SebaBD — printable purchase order.
 *
 *   purchase-order.php?no=<PONo>
 *
 * The paperwork sent to a supplier: what was ordered, at the wholesale price
 * agreed with that vendor (#19, #20, #21), with the delivery instructions in
 * the footer.
 *
 * Purchase orders carry supplier cost prices, so the page is limited to staff
 * who buy: an Admin or an Inventory Manager. A customer never sees what the
 * store pays for the goods it sells them.
 *
 * The schema keeps no received-against flag on purchase_order — receiving is
 * recorded against stock (product.StockQty / product_instance), not against the
 * paperwork — so this document prints the order as placed and the toolbar links
 * to the receiving screen in the back office.
 */
require_once __DIR__ . '/helpers.php';

$user = require_login('purchase-order.php');
require_role(['Admin', 'Inventory Manager'], 'The procurement documents');

$no = max(0, (int) ($_GET['no'] ?? 0));

$rows = db_fetch_all(
    'SELECT po.PONo, po.PODate, po.TotalAmount, po.Terms,
            v.VendorName, v.Location, v.ContactPhone
     FROM purchase_order po
     JOIN vendor v ON v.VendorID = po.VendorID
     WHERE po.PONo = :id',
    [':id' => $no]
);
$order = $rows[0] ?? null;

if ($order === null) {
    deny_page(404, 'Purchase order not found', 'Purchase order #' . $no . ' does not exist.',
        'admin-purchase-orders.php', 'All purchase orders');
}

$items = db_fetch_all(
    'SELECT poi.POItemID, poi.ProductID, poi.Quantity, poi.WholesaleUnitPrice, poi.TotalPrice,
            p.ProductName, p.Brand, p.Model, p.StandardPrice, p.IsSerialized,
            (SELECT COUNT(*) FROM product_instance pi WHERE pi.ProductID = poi.ProductID) AS UnitsOnFile
     FROM po_item poi
     JOIN product p ON p.ProductID = poi.ProductID
     WHERE poi.PONo = :id
     ORDER BY poi.POItemID',
    [':id' => $no]
);

/* ------------------------------------------------------------------ render */

$itemRows = [];
$units    = 0;
$cost     = 0.0;
$retail   = 0.0;

foreach ($items as $item) {
    $units  += (int) $item['Quantity'];
    $cost   += (float) $item['TotalPrice'];
    $retail += (float) $item['Quantity'] * (float) $item['StandardPrice'];

    $itemRows[] = [
        '<span class="text-muted">' . (int) $item['POItemID'] . '</span>',
        '<strong>' . htmlspecialchars($item['ProductName']) . '</strong>'
            . '<span class="document-cell-sub">'
            . htmlspecialchars(trim(($item['Brand'] ?? '') . ($item['Model'] ? ' · ' . $item['Model'] : '')) ?: '—')
            . ' · product #' . (int) $item['ProductID']
            . (((int) $item['IsSerialized']) === 1 ? ' · serialized, ' . (int) $item['UnitsOnFile'] . ' unit(s) on file' : ' · bulk item')
            . '</span>',
        (string) (int) $item['Quantity'],
        money($item['WholesaleUnitPrice']),
        money($item['TotalPrice']),
    ];
}

$margin = $retail - $cost;

$metaRows = [
    ['label' => 'Supplier', 'value' => htmlspecialchars($order['VendorName'])],
    ['label' => 'Lines', 'value' => count($items) . ' product' . (count($items) === 1 ? '' : 's')],
    ['label' => 'Units ordered', 'value' => number_format($units)],
    ['label' => 'Retail value', 'value' => money($retail) . ' <span class="text-muted">(' . money($margin) . ' margin)</span>'],
];

$terms = trim((string) $order['Terms']);
$notes = $terms !== ''
    ? htmlspecialchars($terms)
    : 'Please deliver to ' . htmlspecialchars(company_details()['address'])
      . ' and quote purchase order #' . (int) $order['PONo'] . ' on all invoices and packing slips. '
      . 'Goods are inspected on arrival; serialized items must arrive with their serial numbers intact.';

echo partial_render('partials/header.php', ['page_title' => 'Purchase order #' . (int) $order['PONo'], 'active_nav' => 'admin']);
echo partial_render('partials/document.php', [
    'doc_kind'         => 'Purchase order',
    'doc_no_label'     => 'PO no.',
    'doc_no'           => (int) $order['PONo'],
    'doc_date_label'   => 'Issued',
    'doc_date'         => (string) $order['PODate'],
    'party_label'      => 'Supplier',
    'party_lines'      => array_filter([
        $order['VendorName'],
        $order['Location'],
        $order['ContactPhone'] ? 'Phone: ' . $order['ContactPhone'] : null,
    ]),
    'meta_rows'        => $metaRows,
    'columns'          => [
        ['label' => 'Line'],
        ['label' => 'Product'],
        ['label' => 'Qty', 'class' => 'text-end'],
        ['label' => 'Unit cost', 'class' => 'text-end'],
        ['label' => 'Amount', 'class' => 'text-end'],
    ],
    'items'            => $itemRows,
    'items_sum'        => null,
    'total_label'      => 'Order value',
    'total_amount'     => (float) $order['TotalAmount'],
    'amount_words'     => amount_in_words((float) $order['TotalAmount']),
    'notes'            => $notes,
    'sign_left'        => 'For ' . company_details()['name'],
    'sign_right'       => 'Supplier acknowledgement',
    'actions_html'     => '<a class="btn btn-accent btn-sm" href="admin-purchase-orders.php?action=view&id=' . (int) $order['PONo'] . '">'
        . '<i class="fa-solid fa-truck-ramp-box me-1"></i>Receive stock</a>',
    'download_href'    => 'download.php?doc=purchase-order&no=' . (int) $order['PONo'],
    'download_csv_href' => 'download.php?doc=purchase-order&no=' . (int) $order['PONo'] . '&format=csv',
    'hint'             => 'Supplier cost prices — internal document',
    'back_href'        => 'admin-purchase-orders.php',
    'back_label'       => 'All purchase orders',
]);
echo partial_render('partials/footer.php');
