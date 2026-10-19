<?php
/**
 * SebaBD — printable quotation.
 *
 *   quotation.php?no=<QuotationNo>
 *
 * A read-only document view: staff can open any quotation, and a customer can
 * open the ones addressed to a branch of their own company. Nothing here
 * writes, so the page is safe to hand to a browser's print dialog.
 *
 * It renders through partials/document.php, the same template the invoice and
 * purchase order use, so all three documents look like one company's paperwork.
 */
require_once __DIR__ . '/helpers.php';

$user = require_login('quotation.php');
$no   = max(0, (int) ($_GET['no'] ?? 0));

$rows = db_fetch_all(
    'SELECT q.QuotationNo, q.BranchID, q.ClientID, q.QuotationDate, q.Subject,
            q.ContactName, q.ContactEmail, q.ContactPhone, q.TotalAmount,
            q.AmountInWords, q.Status,
            b.BranchName, b.BranchAddress, b.ContactNo,
            c.ClientID AS OwnerClientID, c.CompanyName, c.ContactPerson, c.Email, c.Phone, c.Address
     FROM quotation q
     LEFT JOIN client_branch b ON b.BranchID = q.BranchID
     LEFT JOIN client c ON c.ClientID = q.ClientID
     WHERE q.QuotationNo = :id',
    [':id' => $no]
);
$quotation = $rows[0] ?? null;

if ($quotation === null) {
    deny_page(404, 'Quotation not found', 'Quotation #' . $no . ' does not exist.', 'index.php', 'Back to the storefront');
}

/* -------------------------------------------------------------- access rule
 * Staff may open any quotation. A customer may open one addressed to their
 * own company — quotation.ClientID, the customer the document was prepared
 * for, is the ownership link (an individual's quotations carry no branch at
 * all, so the branch was never the owner). The same rule the dashboard uses
 * to decide which quotations to list. */
$mayView = is_staff_user($user);
if (!$mayView) {
    $mine = customer_client($user);
    $mayView = $mine !== null && (int) $quotation['ClientID'] === (int) $mine['ClientID'];
}
if (!$mayView) {
    deny_page(403, 'Not your document',
        'Quotation #' . $no . ' is addressed to another customer. Ask the sales team to send you a copy.');
}

$items = db_fetch_all(
    'SELECT qi.QuotationItemID, qi.ProductID, qi.Quantity, qi.UnitPrice, qi.TotalPrice, qi.WarrantyMonths,
            p.ProductName, p.Brand, p.Model
     FROM quotation_item qi
     JOIN product p ON p.ProductID = qi.ProductID
     WHERE qi.QuotationNo = :id
     ORDER BY qi.QuotationItemID',
    [':id' => $no]
);

$invoice = db_fetch_all(
    'SELECT InvoiceNo, InvoiceDate, PaymentStatus, TotalAmount FROM invoice WHERE QuotationNo = :id',
    [':id' => $no]
);
$invoice = $invoice[0] ?? null;

/* ------------------------------------------------------------------ render */

$itemRows = [];
$itemsSum = 0.0;
foreach ($items as $item) {
    $itemsSum += (float) $item['TotalPrice'];
    $itemRows[] = [
        '<span class="text-muted">' . (int) $item['QuotationItemID'] . '</span>',
        '<strong>' . htmlspecialchars($item['ProductName']) . '</strong>'
            . '<span class="document-cell-sub">'
            . htmlspecialchars(trim(($item['Brand'] ?? '') . ($item['Model'] ? ' · ' . $item['Model'] : '')) ?: '—')
            . ' · product #' . (int) $item['ProductID'] . '</span>',
        (string) (int) $item['Quantity'],
        money($item['UnitPrice']),
        (int) $item['WarrantyMonths'] . ' months',
        money($item['TotalPrice']),
    ];
}

$metaRows = [
    ['label' => 'Subject', 'value' => htmlspecialchars((string) $quotation['Subject'])],
    ['label' => 'Prepared for', 'value' => htmlspecialchars($quotation['CompanyName'])],
    ['label' => 'Line items', 'value' => count($items) . ' line' . (count($items) === 1 ? '' : 's')],
];

$notes = 'This quotation is valid for 30 days from the date above and is subject to stock availability at the time of order. '
       . 'Prices are in Bangladeshi taka and exclude any applicable taxes. Warranty months are counted from the '
       . 'delivery date recorded on the invoice.';

$actionsHtml = '';
if (is_staff_user($user)) {
    $actionsHtml = '<a class="btn btn-outline-secondary btn-sm" href="admin-quotations.php?action=view&id=' . (int) $quotation['QuotationNo'] . '">'
                 . '<i class="fa-solid fa-pen-to-square me-1"></i>Manage in the back office</a>';
    if (!$invoice) {
        $actionsHtml .= ' <a class="btn btn-accent btn-sm" href="admin-invoices.php?action=new&from_quotation=' . (int) $quotation['QuotationNo'] . '">'
                      . '<i class="fa-solid fa-file-invoice-dollar me-1"></i>Convert to invoice</a>';
    }
}

echo partial_render('partials/header.php', ['page_title' => 'Quotation #' . (int) $quotation['QuotationNo'], 'active_nav' => '']);
echo partial_render('partials/document.php', [
    'doc_kind'         => 'Quotation',
    'doc_no_label'     => 'Quotation no.',
    'doc_no'           => (int) $quotation['QuotationNo'],
    'doc_date_label'   => 'Quotation date',
    'doc_date'         => (string) $quotation['QuotationDate'],
    'doc_status'       => (string) $quotation['Status'],
    'doc_status_label' => (string) $quotation['Status'],
    'party_label'      => 'Quotation for',
    'party_lines'      => array_filter([
        $quotation['CompanyName'],
        $quotation['BranchName'],
        $quotation['BranchAddress'] ?: $quotation['Address'],
        // The snapshotted requester wins over the client record: a walk-in
        // visitor has no client contact of their own, and a B2B contact may
        // differ from the company's main contact person.
        ($quotation['ContactName'] ?: $quotation['ContactPerson'])
            ? 'Attn: ' . ($quotation['ContactName'] ?: $quotation['ContactPerson']) : null,
        trim(($quotation['ContactPhone'] ?: $quotation['Phone'] ?: $quotation['ContactNo'] ?: '')
            . ' ' . (($quotation['ContactEmail'] ?: $quotation['Email']) ? '· ' . ($quotation['ContactEmail'] ?: $quotation['Email']) : '')),
    ]),
    'meta_rows'        => $metaRows,
    'columns'          => [
        ['label' => 'Line'],
        ['label' => 'Product'],
        ['label' => 'Qty', 'class' => 'text-end'],
        ['label' => 'Unit price', 'class' => 'text-end'],
        ['label' => 'Warranty', 'class' => 'text-end'],
        ['label' => 'Amount', 'class' => 'text-end'],
    ],
    'items'            => $itemRows,
    'items_sum'        => $itemsSum,
    'total_label'      => 'Quotation total',
    'total_amount'     => (float) $quotation['TotalAmount'],
    'amount_words'     => (string) $quotation['AmountInWords'],
    'notes'            => $notes,
    'actions_html'     => $actionsHtml,
    'extra_html'       => $invoice
        ? '<div class="alert alert-success mb-0"><i class="fa-solid fa-circle-check me-1"></i>'
          . 'This quotation is already invoiced as <a href="invoice.php?no=' . (int) $invoice['InvoiceNo'] . '">#'
          . (int) $invoice['InvoiceNo'] . '</a> (' . htmlspecialchars($invoice['PaymentStatus']) . ').</div>'
        : '',
    'download_href'    => 'download.php?doc=quotation&no=' . (int) $quotation['QuotationNo'],
    'download_csv_href' => 'download.php?doc=quotation&no=' . (int) $quotation['QuotationNo'] . '&format=csv',
    'back_href'        => is_staff_user($user) ? 'admin-quotations.php' : 'dashboard.php',
    'back_label'       => is_staff_user($user) ? 'All quotations' : 'My dashboard',
]);
echo partial_render('partials/footer.php');
