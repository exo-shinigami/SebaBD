<?php
/**
 * SebaBD — printable invoice.
 *
 *   invoice.php?no=<InvoiceNo>
 *
 * Shows the tax-style paperwork for one invoice: the billed branch, every line,
 * the serialized units actually attached to each line, and the receipts that
 * have been recorded against it with the running balance.
 *
 * Staff can open any invoice. A customer may open one billed to a branch of
 * their own company, which is the same rule the dashboard uses when it lists
 * "my invoices" — so a customer cannot read another company's paperwork by
 * guessing an invoice number.
 */
require_once __DIR__ . '/helpers.php';

$user = require_login('invoice.php');
$no   = max(0, (int) ($_GET['no'] ?? 0));

$rows = db_fetch_all(
    'SELECT i.InvoiceNo, i.InvoiceDate, i.BranchID, i.OrderID, i.QuotationNo,
            i.TotalAmount, i.PaymentStatus, i.RemainingBalance, i.AmountInWords,
            b.BranchName, b.BranchAddress, b.ContactNo,
            c.ClientID, c.CompanyName, c.ContactPerson, c.Email, c.Phone, c.Address,
            o.UserID, o.ShippingAddress,
            u.FullName AS BuyerName
     FROM invoice i
     JOIN client_branch b ON b.BranchID = i.BranchID
     JOIN client c ON c.ClientID = b.ClientID
     LEFT JOIN `order` o ON o.OrderID = i.OrderID
     LEFT JOIN `user` u ON u.UserID = o.UserID
     WHERE i.InvoiceNo = :id',
    [':id' => $no]
);
$invoice = $rows[0] ?? null;

if ($invoice === null) {
    deny_page(404, 'Invoice not found', 'Invoice #' . $no . ' does not exist.', 'index.php', 'Back to the storefront');
}

/* -------------------------------------------------------------- access rule */
$mayView = is_staff_user($user);
if (!$mayView && is_client_user($user)) {
    $mine = db_fetch_all(
        'SELECT b.BranchID
         FROM client c
         JOIN client_branch b ON b.ClientID = c.ClientID
         WHERE c.UserID = :uid',
        [':uid' => (int) $user['UserID']]
    );
    $mayView = in_array((int) $invoice['BranchID'], array_map('intval', array_column($mine, 'BranchID')), true);
}
if (!$mayView) {
    deny_page(403, 'Not your document',
        'Invoice #' . $no . ' is billed to another customer. Ask the sales team to send you a copy.');
}

$items = db_fetch_all(
    'SELECT ii.InvoiceItemID, ii.ProductID, ii.Quantity, ii.SoldUnitPrice, ii.TotalPrice, ii.EquipmentID,
            p.ProductName, p.Brand, p.Model, p.IsSerialized, p.DefaultWarrantyMonths
     FROM invoice_item ii
     JOIN product p ON p.ProductID = ii.ProductID
     WHERE ii.InvoiceNo = :id
     ORDER BY ii.InvoiceItemID',
    [':id' => $no]
);

/* A serialized line points at the exact unit sold: invoice_item.EquipmentID is
 * the link the invoicing screen writes when a unit is attached to a line. */
$units = db_fetch_all(
    'SELECT ii.InvoiceItemID, pi.EquipmentID, pi.SerialNumber, pi.Status,
            pi.ClientWarrantyExpiry, pi.VendorWarrantyExpiry
     FROM invoice_item ii
     JOIN product_instance pi ON pi.EquipmentID = ii.EquipmentID
     WHERE ii.InvoiceNo = :id
     ORDER BY pi.EquipmentID',
    [':id' => $no]
);
$unitsByItem = [];
foreach ($units as $unit) {
    $unitsByItem[(int) $unit['InvoiceItemID']][] = $unit;
}

$receipts = db_fetch_all(
    'SELECT ReceiptNo, ReceiptDate, AmountReceived, PaymentMethod,
            RemainingDueAfterReceipt, ReceivedBy
     FROM payment_receipt
     WHERE InvoiceNo = :id
     ORDER BY ReceiptDate, ReceiptNo',
    [':id' => $no]
);

/* ------------------------------------------------------------------ render */

$itemRows = [];
$itemsSum = 0.0;
foreach ($items as $item) {
    $itemsSum += (float) $item['TotalPrice'];

    // The unit numbers sold on this line, with the cover that left the shop
    // with them — that is what a customer quotes when a device needs service.
    $serials = $unitsByItem[(int) $item['InvoiceItemID']] ?? [];
    $unitHtml = '';
    if ($serials) {
        $labels = [];
        foreach ($serials as $unit) {
            $labels[] = $unit['SerialNumber']
                . ($unit['ClientWarrantyExpiry'] ? ' (cover to ' . format_date($unit['ClientWarrantyExpiry']) . ')' : '');
        }
        $unitHtml = '<span class="document-cell-sub text-muted">Units: '
                  . htmlspecialchars(implode(', ', $labels)) . '</span>';
    } elseif (((int) $item['IsSerialized']) === 1) {
        $unitHtml = '<span class="document-cell-sub text-muted">No serialized unit attached yet</span>';
    }

    $itemRows[] = [
        '<span class="text-muted">' . (int) $item['InvoiceItemID'] . '</span>',
        '<strong>' . htmlspecialchars($item['ProductName']) . '</strong>'
            . '<span class="document-cell-sub">'
            . htmlspecialchars(trim(($item['Brand'] ?? '') . ($item['Model'] ? ' · ' . $item['Model'] : '')) ?: '—')
            . ($item['DefaultWarrantyMonths'] ? ' · ' . (int) $item['DefaultWarrantyMonths'] . ' mo warranty' : '')
            . '</span>' . $unitHtml,
        (string) (int) $item['Quantity'],
        money($item['SoldUnitPrice']),
        money($item['TotalPrice']),
    ];
}

/* invoice stores no separate "amount paid" column: what is owed is the
 * remaining balance, so everything paid so far is the difference. */
$balance = (float) $invoice['RemainingBalance'];
$paid    = max(0.0, round((float) $invoice['TotalAmount'] - $balance, 2));
$status  = (string) $invoice['PaymentStatus'];

$metaRows = [
    ['label' => 'Billed to', 'value' => htmlspecialchars($invoice['CompanyName'])],
    ['label' => 'Branch', 'value' => htmlspecialchars($invoice['BranchName'])],
    ['label' => 'Raised for', 'value' => htmlspecialchars((string) ($invoice['BuyerName'] ?: $invoice['ContactPerson'] ?: '—'))],
    ['label' => 'Receipts issued', 'value' => count($receipts)],
];

if ((int) $invoice['QuotationNo'] > 0) {
    $metaRows[] = [
        'label' => 'From quotation',
        'value' => '<a href="quotation.php?no=' . (int) $invoice['QuotationNo'] . '">#' . (int) $invoice['QuotationNo'] . '</a>',
    ];
}
if ((int) $invoice['OrderID'] > 0) {
    $metaRows[] = ['label' => 'From order', 'value' => '#' . (int) $invoice['OrderID']];
}

/* Receipts, printed under the item table so the payment history travels with
 * the document when it is filed or emailed. */
$paymentsHtml = '';
if ($receipts) {
    $rowsHtml = '';
    foreach ($receipts as $receipt) {
        $rowsHtml .= '<tr>'
            . '<td>#' . (int) $receipt['ReceiptNo'] . '</td>'
            . '<td>' . htmlspecialchars(format_date($receipt['ReceiptDate'])) . '</td>'
            . '<td>' . htmlspecialchars((string) ($receipt['PaymentMethod'] ?: '—')) . '</td>'
            . '<td>' . htmlspecialchars((string) ($receipt['ReceivedBy'] ?: '—')) . '</td>'
            . '<td class="text-end">' . money($receipt['AmountReceived']) . '</td>'
            . '<td class="text-end">' . money($receipt['RemainingDueAfterReceipt']) . '</td>'
            . '</tr>';
    }
    $paymentsHtml = '<div class="document-block-title mt-4">Payments received</div>'
        . '<table class="table table-sm document-items"><thead><tr>'
        . '<th>Receipt</th><th>Date</th><th>Method</th><th>Received by</th>'
        . '<th class="text-end">Amount</th><th class="text-end">Balance after</th>'
        . '</tr></thead><tbody>' . $rowsHtml . '</tbody></table>';
}

$notes = $balance > 0
    ? 'Payment of ' . money($balance) . ' remains outstanding as of ' . date('d M Y')
      . '. Please quote invoice #' . (int) $invoice['InvoiceNo'] . ' with any transfer. '
      . 'Warranty support is provided against the serial numbers listed above.'
    : 'This invoice is paid in full. Warranty support is provided against the serial numbers listed above.';

$actionsHtml = '';
if (is_staff_user($user) && $balance > 0) {
    // The invoice module addresses one invoice with ?no= (quotations and
    // purchase orders use ?id=) — match each module's own convention.
    $actionsHtml = '<a class="btn btn-accent btn-sm" href="admin-invoices.php?action=view&no=' . (int) $invoice['InvoiceNo'] . '">'
                 . '<i class="fa-solid fa-hand-holding-dollar me-1"></i>Record a payment</a>';
}

echo partial_render('partials/header.php', ['page_title' => 'Invoice #' . (int) $invoice['InvoiceNo'], 'active_nav' => '']);
echo partial_render('partials/document.php', [
    'doc_kind'         => 'Invoice',
    'doc_no_label'     => 'Invoice no.',
    'doc_no'           => (int) $invoice['InvoiceNo'],
    'doc_date_label'   => 'Invoice date',
    'doc_date'         => (string) $invoice['InvoiceDate'],
    'doc_status'       => $status,
    'party_label'      => 'Billed to',
    'party_lines'      => array_filter([
        $invoice['CompanyName'],
        $invoice['BranchName'],
        $invoice['BranchAddress'] ?: $invoice['Address'],
        $invoice['ContactPerson'] ? 'Attn: ' . $invoice['ContactPerson'] : null,
        trim(($invoice['Phone'] ?: $invoice['ContactNo'] ?: '') . ' ' . ($invoice['Email'] ? '· ' . $invoice['Email'] : '')),
        $invoice['ShippingAddress'] ? 'Deliver to: ' . $invoice['ShippingAddress'] : null,
    ]),
    'meta_rows'        => $metaRows,
    'columns'          => [
        ['label' => 'Line'],
        ['label' => 'Product & units'],
        ['label' => 'Qty', 'class' => 'text-end'],
        ['label' => 'Unit price', 'class' => 'text-end'],
        ['label' => 'Amount', 'class' => 'text-end'],
    ],
    'items'            => $itemRows,
    'items_sum'        => count($itemRows) > 1 ? $itemsSum : null,
    'total_label'      => 'Invoice total',
    'total_amount'     => (float) $invoice['TotalAmount'],
    'amount_words'     => (string) $invoice['AmountInWords'],
    'notes'            => $notes,
    'payments_html'    => $paymentsHtml,
    'actions_html'     => $actionsHtml,
    'extra_html'       => '<div class="row g-3 document-balance-band mt-3">'
        . '<div class="col-sm-4"><div class="document-block-title mb-1">Invoice total</div><div class="fw-bold">' . money($invoice['TotalAmount']) . '</div></div>'
        . '<div class="col-sm-4"><div class="document-block-title mb-1">Amount paid</div><div class="fw-bold">' . money($paid) . '</div></div>'
        . '<div class="col-sm-4"><div class="document-block-title mb-1">Balance due</div><div class="fw-bold">' . money($balance) . '</div></div>'
        . '</div>',
    'back_href'        => is_staff_user($user) ? 'admin-invoices.php' : 'dashboard.php',
    'back_label'       => is_staff_user($user) ? 'All invoices' : 'My dashboard',
]);
echo partial_render('partials/footer.php');
