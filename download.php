<?php
/**
 * SebaBD — take a document away as a file.
 *
 *   download.php?doc=invoice&no=5003&format=pdf
 *   download.php?doc=quotation&no=1002&format=csv
 *   download.php?doc=purchase-order&no=7&format=pdf      staff who buy
 *   download.php?doc=order&no=3&format=pdf
 *
 * The pages stay the place to read a document; this endpoint exists so the same
 * paperwork can be filed or emailed. Access is the rule the matching page
 * applies — the owning customer for an invoice, quotation or order, and staff
 * with a buying role for the procurement paperwork, because purchase orders
 * carry supplier cost prices.
 *
 * Both formats are built from one payload, so a PDF and its CSV always carry
 * the same lines, prices and totals.
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/pdf-document.php';

$user   = require_login('download.php');
$kind   = strtolower(trim((string) ($_GET['doc'] ?? '')));
$no     = max(0, (int) ($_GET['no'] ?? 0));
$format = strtolower(trim((string) ($_GET['format'] ?? 'pdf')));
$format = in_array($format, ['pdf', 'csv'], true) ? $format : 'pdf';

switch ($kind) {
    case 'invoice':
        $doc = download_invoice($no, $user);
        break;
    case 'quotation':
        $doc = download_quotation($no, $user);
        break;
    case 'purchase-order':
        $doc = download_purchase_order($no, $user);
        break;
    case 'order':
        $doc = download_order($no, $user);
        break;
    default:
        deny_page(404, 'Nothing to download',
            'That document type does not exist. Invoices, quotations, purchase orders and orders can be downloaded.',
            'dashboard.php', 'My dashboard');
}

if ($doc === null) {
    // The builders call deny_page() themselves; this is the belt-and-braces path.
    deny_page(404, 'Document not found', 'The requested document does not exist.', 'dashboard.php', 'My dashboard');
}

if ($format === 'csv') {
    document_csv_output($doc);
} else {
    document_pdf_output($doc);
}
exit;

/* ---------------------------------------------------------------- builders */

/**
 * One invoice, with its lines, the serialized units sold on each line and the
 * receipts recorded against it.
 */
function download_invoice(int $no, array $user): ?array
{
    $rows = db_fetch_all(
        'SELECT i.InvoiceNo, i.InvoiceDate, i.BranchID, i.ClientID, i.OrderID, i.QuotationNo,
                i.TotalAmount, i.PaymentStatus, i.RemainingBalance, i.AmountInWords,
                b.BranchName, b.BranchAddress, b.ContactNo,
                c.CompanyName, c.ContactPerson, c.Email, c.Phone, c.Address,
                o.UserID AS OrderUserID, o.ShippingAddress,
                u.FullName AS BuyerName
         FROM invoice i
         LEFT JOIN client_branch b ON b.BranchID = i.BranchID
         LEFT JOIN client c ON c.ClientID = i.ClientID
         LEFT JOIN `order` o ON o.OrderID = i.OrderID
         LEFT JOIN `user` u ON u.UserID = o.UserID
         WHERE i.InvoiceNo = :id',
        [':id' => $no]
    );
    $invoice = $rows[0] ?? null;

    if ($invoice === null) {
        deny_page(404, 'Invoice not found', 'Invoice #' . $no . ' does not exist.', 'index.php', 'Back to the storefront');
    }

    // Same ownership test as invoice.php: staff, the billing company, or the
    // person who placed the order the invoice was raised for.
    $mayView = is_staff_user($user);
    if (!$mayView) {
        $mine      = customer_client($user);
        $ownClient = $mine !== null && (int) $invoice['ClientID'] === (int) $mine['ClientID'];
        $ownOrder  = (int) ($invoice['OrderUserID'] ?? 0) === (int) $user['UserID'];
        $mayView   = $ownClient || $ownOrder;
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

    $units = db_fetch_all(
        'SELECT ii.InvoiceItemID, pi.EquipmentID, pi.SerialNumber, pi.ClientWarrantyExpiry
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

    $itemRows = [];
    $sum      = 0.0;
    foreach ($items as $item) {
        $sum += (float) $item['TotalPrice'];

        $detail = trim(($item['Brand'] ?? '') . ($item['Model'] ? ' · ' . $item['Model'] : '')) ?: 'Item';
        if ((int) $item['DefaultWarrantyMonths'] > 0) {
            $detail .= ' · ' . (int) $item['DefaultWarrantyMonths'] . ' mo warranty';
        }

        $serials = $unitsByItem[(int) $item['InvoiceItemID']] ?? [];
        if ($serials) {
            $labels = [];
            foreach ($serials as $unit) {
                $labels[] = $unit['SerialNumber']
                    . ($unit['ClientWarrantyExpiry'] ? ' (cover to ' . format_date($unit['ClientWarrantyExpiry']) . ')' : '');
            }
            $detail .= "\nUnits: " . implode(', ', $labels);
        } elseif ((int) $item['IsSerialized'] === 1) {
            $detail .= "\nNo serialized unit attached yet";
        }

        $itemRows[] = [
            (string) (int) $item['InvoiceItemID'],
            $item['ProductName'] . "\n" . $detail,
            (string) (int) $item['Quantity'],
            money($item['SoldUnitPrice']),
            money($item['TotalPrice']),
        ];
    }

    $balance = (float) $invoice['RemainingBalance'];
    $paid    = max(0.0, round((float) $invoice['TotalAmount'] - $balance, 2));

    $metaRows = [
        ['label' => 'Billed to', 'value' => (string) $invoice['CompanyName']],
        ['label' => 'Branch', 'value' => (string) $invoice['BranchName']],
        ['label' => 'Raised for', 'value' => (string) ($invoice['BuyerName'] ?: $invoice['ContactPerson'] ?: '—')],
        ['label' => 'Receipts issued', 'value' => (string) count($receipts)],
    ];
    if ((int) $invoice['QuotationNo'] > 0) {
        $metaRows[] = ['label' => 'From quotation', 'value' => '#' . (int) $invoice['QuotationNo']];
    }
    if ((int) ($invoice['OrderID'] ?? 0) > 0) {
        $metaRows[] = ['label' => 'From order', 'value' => '#' . (int) $invoice['OrderID']];
    }

    $notes = $balance > 0
        ? 'Payment of ' . money($balance) . ' remains outstanding as of ' . date('d M Y')
          . '. Please quote invoice #' . (int) $invoice['InvoiceNo'] . ' with any transfer. '
          . 'Warranty support is provided against the serial numbers listed above.'
        : 'This invoice is paid in full. Warranty support is provided against the serial numbers listed above.';

    $tables = [];
    if ($receipts) {
        $receiptRows = [];
        foreach ($receipts as $receipt) {
            $receiptRows[] = [
                '#' . (int) $receipt['ReceiptNo'],
                format_date($receipt['ReceiptDate']),
                (string) ($receipt['PaymentMethod'] ?: '—'),
                (string) ($receipt['ReceivedBy'] ?: '—'),
                money($receipt['AmountReceived']),
                money($receipt['RemainingDueAfterReceipt']),
            ];
        }
        $tables[] = [
            'title'   => 'Payments received',
            'columns' => [
                ['label' => 'Receipt', 'weight' => 1],
                ['label' => 'Date', 'weight' => 1.4],
                ['label' => 'Method', 'weight' => 1.6],
                ['label' => 'Received by', 'weight' => 1.8],
                ['label' => 'Amount', 'weight' => 1.6, 'align' => 'R'],
                ['label' => 'Balance after', 'weight' => 1.7, 'align' => 'R'],
            ],
            'rows'    => $receiptRows,
        ];
    }

    return [
        'kind'         => 'Invoice',
        'number_label' => 'Invoice no.',
        'number'       => (string) (int) $invoice['InvoiceNo'],
        'date_label'   => 'Invoice date',
        'date'         => format_date((string) $invoice['InvoiceDate']),
        'status'       => (string) $invoice['PaymentStatus'],
        'party_label'  => 'Billed to',
        'party_lines'  => array_filter([
            $invoice['CompanyName'],
            $invoice['BranchName'],
            $invoice['BranchAddress'] ?: $invoice['Address'],
            $invoice['ContactPerson'] ? 'Attn: ' . $invoice['ContactPerson'] : null,
            trim(($invoice['Phone'] ?: $invoice['ContactNo'] ?: '') . ' ' . ($invoice['Email'] ? '· ' . $invoice['Email'] : '')),
            $invoice['ShippingAddress'] ? 'Deliver to: ' . $invoice['ShippingAddress'] : null,
        ]),
        'meta_rows'    => $metaRows,
        'columns'      => [
            ['label' => 'Line', 'weight' => 0.7],
            ['label' => 'Product & units', 'weight' => 4.6],
            ['label' => 'Qty', 'weight' => 0.7, 'align' => 'R'],
            ['label' => 'Unit price', 'weight' => 1.4, 'align' => 'R'],
            ['label' => 'Amount', 'weight' => 1.5, 'align' => 'R'],
        ],
        'rows'         => $itemRows,
        'sum_row'      => count($itemRows) > 1 ? ['label' => 'Subtotal', 'value' => money($sum)] : null,
        'total_label'  => 'Invoice total',
        'total'        => money($invoice['TotalAmount']),
        'amount_words' => (string) $invoice['AmountInWords'],
        'balances'     => [
            ['label' => 'Invoice total', 'value' => money($invoice['TotalAmount'])],
            ['label' => 'Amount paid', 'value' => money($paid)],
            ['label' => 'Balance due', 'value' => money($balance)],
        ],
        'tables'       => $tables,
        'notes'        => $notes,
        'title'        => 'Invoice #' . (int) $invoice['InvoiceNo'],
        'filename'     => 'sebabd-invoice-' . (int) $invoice['InvoiceNo'] . '.pdf',
    ];
}

/**
 * One quotation, with the warranty months quoted per line.
 */
function download_quotation(int $no, array $user): ?array
{
    $rows = db_fetch_all(
        'SELECT q.QuotationNo, q.BranchID, q.ClientID, q.QuotationDate, q.Subject,
                q.ContactName, q.ContactEmail, q.ContactPhone, q.TotalAmount,
                q.AmountInWords, q.Status,
                b.BranchName, b.BranchAddress, b.ContactNo,
                c.CompanyName, c.ContactPerson, c.Email, c.Phone, c.Address
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

    $mayView = is_staff_user($user);
    if (!$mayView) {
        $mine    = customer_client($user);
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

    $itemRows = [];
    $sum      = 0.0;
    foreach ($items as $item) {
        $sum += (float) $item['TotalPrice'];
        $itemRows[] = [
            (string) (int) $item['QuotationItemID'],
            $item['ProductName'] . "\n" . (trim(($item['Brand'] ?? '') . ($item['Model'] ? ' · ' . $item['Model'] : '')) ?: 'Item')
                . ' · product #' . (int) $item['ProductID'],
            (string) (int) $item['Quantity'],
            money($item['UnitPrice']),
            (int) $item['WarrantyMonths'] . ' months',
            money($item['TotalPrice']),
        ];
    }

    return [
        'kind'         => 'Quotation',
        'number_label' => 'Quotation no.',
        'number'       => (string) (int) $quotation['QuotationNo'],
        'date_label'   => 'Quotation date',
        'date'         => format_date((string) $quotation['QuotationDate']),
        'status'       => (string) $quotation['Status'],
        'party_label'  => 'Quotation for',
        'party_lines'  => array_filter([
            $quotation['CompanyName'],
            $quotation['BranchName'],
            $quotation['BranchAddress'] ?: $quotation['Address'],
            ($quotation['ContactName'] ?: $quotation['ContactPerson'])
                ? 'Attn: ' . ($quotation['ContactName'] ?: $quotation['ContactPerson']) : null,
            trim(($quotation['ContactPhone'] ?: $quotation['Phone'] ?: $quotation['ContactNo'] ?: '')
                . ' ' . (($quotation['ContactEmail'] ?: $quotation['Email']) ? '· ' . ($quotation['ContactEmail'] ?: $quotation['Email']) : '')),
        ]),
        'meta_rows'    => [
            ['label' => 'Subject', 'value' => (string) $quotation['Subject']],
            ['label' => 'Prepared for', 'value' => (string) $quotation['CompanyName']],
            ['label' => 'Line items', 'value' => count($items) . ' line' . (count($items) === 1 ? '' : 's')],
        ],
        'columns'      => [
            ['label' => 'Line', 'weight' => 0.7],
            ['label' => 'Product', 'weight' => 4.2],
            ['label' => 'Qty', 'weight' => 0.7, 'align' => 'R'],
            ['label' => 'Unit price', 'weight' => 1.4, 'align' => 'R'],
            ['label' => 'Warranty', 'weight' => 1.4, 'align' => 'R'],
            ['label' => 'Amount', 'weight' => 1.6, 'align' => 'R'],
        ],
        'rows'         => $itemRows,
        'sum_row'      => count($itemRows) > 1 ? ['label' => 'Subtotal', 'value' => money($sum)] : null,
        'total_label'  => 'Quotation total',
        'total'        => money($quotation['TotalAmount']),
        'amount_words' => (string) $quotation['AmountInWords'],
        'notes'        => 'This quotation is valid for 30 days from the date above and is subject to stock availability at the time of order. '
            . 'Prices are in Bangladeshi taka and exclude any applicable taxes. Warranty months are counted from the '
            . 'delivery date recorded on the invoice.',
        'title'        => 'Quotation #' . (int) $quotation['QuotationNo'],
        'filename'     => 'sebabd-quotation-' . (int) $quotation['QuotationNo'] . '.pdf',
    ];
}

/**
 * One purchase order. Staff who buy only — the document carries cost prices.
 */
function download_purchase_order(int $no, array $user): ?array
{
    require_role(['Admin', 'Inventory Manager'], 'The procurement documents');

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

    $itemRows = [];
    $units    = 0;
    $cost     = 0.0;
    $retail   = 0.0;
    foreach ($items as $item) {
        $units  += (int) $item['Quantity'];
        $cost   += (float) $item['TotalPrice'];
        $retail += (float) $item['Quantity'] * (float) $item['StandardPrice'];

        $detail = trim(($item['Brand'] ?? '') . ($item['Model'] ? ' · ' . $item['Model'] : '')) ?: 'Item';
        $detail .= ' · product #' . (int) $item['ProductID'];
        $detail .= (int) $item['IsSerialized'] === 1
            ? "\nSerialized — " . (int) $item['UnitsOnFile'] . ' unit(s) on file'
            : "\nBulk item";

        $itemRows[] = [
            (string) (int) $item['POItemID'],
            $item['ProductName'] . "\n" . $detail,
            (string) (int) $item['Quantity'],
            money($item['WholesaleUnitPrice']),
            money($item['TotalPrice']),
        ];
    }

    $terms = trim((string) $order['Terms']);
    $notes = $terms !== ''
        ? $terms
        : 'Please deliver to ' . company_details()['address']
          . ' and quote purchase order #' . (int) $order['PONo'] . ' on all invoices and packing slips. '
          . 'Goods are inspected on arrival; serialized items must arrive with their serial numbers intact.';

    return [
        'kind'         => 'Purchase order',
        'number_label' => 'PO no.',
        'number'       => (string) (int) $order['PONo'],
        'date_label'   => 'Issued',
        'date'         => format_date((string) $order['PODate']),
        'status'       => 'Supplier cost prices — internal document',
        'party_label'  => 'Supplier',
        'party_lines'  => array_filter([
            $order['VendorName'],
            $order['Location'],
            $order['ContactPhone'] ? 'Phone: ' . $order['ContactPhone'] : null,
        ]),
        'meta_rows'    => [
            ['label' => 'Supplier', 'value' => (string) $order['VendorName']],
            ['label' => 'Lines', 'value' => count($items) . ' product' . (count($items) === 1 ? '' : 's')],
            ['label' => 'Units ordered', 'value' => number_format($units)],
            ['label' => 'Retail value', 'value' => money($retail) . ' (' . money($retail - $cost) . ' margin)'],
        ],
        'columns'      => [
            ['label' => 'Line', 'weight' => 0.7],
            ['label' => 'Product', 'weight' => 4.6],
            ['label' => 'Qty', 'weight' => 0.7, 'align' => 'R'],
            ['label' => 'Unit cost', 'weight' => 1.5, 'align' => 'R'],
            ['label' => 'Amount', 'weight' => 1.6, 'align' => 'R'],
        ],
        'rows'         => $itemRows,
        'total_label'  => 'Order value',
        'total'        => money($order['TotalAmount']),
        'amount_words' => amount_in_words((float) $order['TotalAmount']),
        'notes'        => $notes,
        'signatures'   => ['For ' . company_details()['name'], 'Supplier acknowledgement'],
        'title'        => 'Purchase order #' . (int) $order['PONo'],
        'filename'     => 'sebabd-purchase-order-' . (int) $order['PONo'] . '.pdf',
    ];
}

/**
 * One customer order: what was ordered, where it goes, and the invoice raised
 * against it once the sales desk has priced the goods.
 */
function download_order(int $no, array $user): ?array
{
    $rows = db_fetch_all(
        'SELECT o.OrderID, o.UserID, o.OrderDate, o.TotalAmount, o.OrderStatus, o.OrderType,
                o.PaymentMethod, o.PaymentReference,
                o.BranchID, o.ShippingAddress,
                b.BranchName, u.FullName, u.Email, u.Phone, u.Username
         FROM `order` o
         JOIN `user` u ON u.UserID = o.UserID
         LEFT JOIN client_branch b ON b.BranchID = o.BranchID
         WHERE o.OrderID = :id',
        [':id' => $no]
    );
    $order = $rows[0] ?? null;

    if ($order === null) {
        deny_page(404, 'Order not found', 'Order #' . $no . ' does not exist.', 'dashboard.php', 'My dashboard');
    }

    if (!is_staff_user($user) && (int) $order['UserID'] !== (int) $user['UserID']) {
        deny_page(403, 'Not your order',
            'Order #' . $no . ' was placed by another account. Only the person who placed an order can download it.',
            'dashboard.php', 'My dashboard');
    }

    $items = db_fetch_all(
        'SELECT oi.OrderItemID, oi.ProductID, oi.Quantity, oi.UnitPrice, oi.TotalPrice,
                p.ProductName, p.Brand, p.Model, p.IsSerialized,
                c.CategoryName
         FROM order_item oi
         JOIN product  p ON p.ProductID  = oi.ProductID
         JOIN category c ON c.CategoryID = p.CategoryID
         WHERE oi.OrderID = :id
         ORDER BY oi.OrderItemID',
        [':id' => $no]
    );

    $invoices = db_fetch_all(
        'SELECT InvoiceNo, InvoiceDate, PaymentStatus, TotalAmount, RemainingBalance
         FROM invoice WHERE OrderID = :id ORDER BY InvoiceNo',
        [':id' => $no]
    );

    $itemRows = [];
    $sum      = 0.0;
    foreach ($items as $item) {
        $sum += (float) $item['TotalPrice'];
        $itemRows[] = [
            (string) (int) $item['OrderItemID'],
            $item['ProductName'] . "\n" . (trim(($item['Brand'] ?? '') . ($item['Model'] ? ' · ' . $item['Model'] : '')) ?: 'Item')
                . ' · product #' . (int) $item['ProductID'],
            (string) (int) $item['Quantity'],
            money($item['UnitPrice']),
            money($item['TotalPrice']),
        ];
    }

    $tables = [];
    if ($invoices) {
        $invoiceRows = [];
        foreach ($invoices as $invoice) {
            $invoiceRows[] = [
                '#' . (int) $invoice['InvoiceNo'],
                format_date($invoice['InvoiceDate']),
                (string) $invoice['PaymentStatus'],
                money($invoice['TotalAmount']),
                money($invoice['RemainingBalance']),
            ];
        }
        $tables[] = [
            'title'   => 'Invoices raised for this order',
            'columns' => [
                ['label' => 'Invoice', 'weight' => 1],
                ['label' => 'Date', 'weight' => 1.5],
                ['label' => 'Payment status', 'weight' => 1.6],
                ['label' => 'Total', 'weight' => 1.4, 'align' => 'R'],
                ['label' => 'Balance', 'weight' => 1.4, 'align' => 'R'],
            ],
            'rows'    => $invoiceRows,
        ];
    }

    $isInternal = ($order['OrderType'] ?? ORDER_TYPE_RETAIL) === ORDER_TYPE_INTERNAL;
    $notes      = $isInternal
        ? 'Internal order — SebaBD buying for its own office. Not a customer sale; no delivery charge was applied.'
        : 'This is the order summary, not a tax invoice. Prices are confirmed when the sales desk raises the invoice, '
          . 'and warranty cover is counted from the delivery date recorded on that invoice.';

    return [
        'kind'         => 'Order',
        'number_label' => 'Order no.',
        'number'       => (string) (int) $order['OrderID'],
        'date_label'   => 'Placed',
        'date'         => format_date((string) $order['OrderDate'], 'd M Y, H:i'),
        'status'       => (string) $order['OrderStatus'],
        'party_label'  => 'Ordered by',
        'party_lines'  => array_filter([
            $order['FullName'],
            $isInternal && $order['BranchName'] ? 'SebaBD — ' . $order['BranchName'] : null,
            $order['Email'],
            $order['Phone'] ? 'Phone: ' . $order['Phone'] : null,
            $order['ShippingAddress'] ? 'Deliver to: ' . $order['ShippingAddress'] : null,
        ]),
        'meta_rows'    => [
            ['label' => 'Customer', 'value' => (string) $order['FullName']],
            ['label' => 'Account', 'value' => (string) $order['Username']],
            ['label' => 'Channel', 'value' => $isInternal ? 'Internal (SebaBD\'s own purchase)' : 'Customer order'],
            ['label' => 'Payment', 'value' => (string) ($order['PaymentMethod'] ?? 'Cash on Delivery') . (!empty($order['PaymentReference']) ? ' (Ref: ' . $order['PaymentReference'] . ')' : '')],
            ['label' => 'Status', 'value' => (string) $order['OrderStatus']],
            ['label' => 'Lines', 'value' => count($items) . ' product' . (count($items) === 1 ? '' : 's')],
        ],
        'columns'      => [
            ['label' => 'Line', 'weight' => 0.7],
            ['label' => 'Product', 'weight' => 4.6],
            ['label' => 'Qty', 'weight' => 0.7, 'align' => 'R'],
            ['label' => 'Unit price', 'weight' => 1.5, 'align' => 'R'],
            ['label' => 'Amount', 'weight' => 1.6, 'align' => 'R'],
        ],
        'rows'         => $itemRows,
        'sum_row'      => count($itemRows) > 1 ? ['label' => 'Subtotal', 'value' => money($sum)] : null,
        'total_label'  => 'Order total',
        'total'        => money($order['TotalAmount']),
        'amount_words' => amount_in_words((float) $order['TotalAmount']),
        'tables'       => $tables,
        'notes'        => $notes,
        'title'        => 'Order #' . (int) $order['OrderID'],
        'filename'     => 'sebabd-order-' . (int) $order['OrderID'] . '.pdf',
    ];
}

/* -------------------------------------------------------------------- CSV */

/**
 * The same payload as a spreadsheet: a header block, then the line items and
 * the totals. Written as UTF-8 with a BOM so Excel opens the taka sign and the
 * em dashes correctly instead of turning them into mojibake.
 */
function document_csv_output(array $doc): void
{
    $filename = preg_replace('/\.pdf$/', '.csv', (string) ($doc['filename'] ?? 'sebabd-document.pdf'));

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
    header('Cache-Control: private, no-store');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");

    $company = company_details();
    fputcsv($out, [$company['name'], $company['legal']]);
    fputcsv($out, [$company['address']]);
    fputcsv($out, [$company['phone'], $company['email'], $company['web']]);
    fputcsv($out, []);
    fputcsv($out, [$doc['kind'] ?? 'Document', '#' . ($doc['number'] ?? '')]);
    fputcsv($out, [$doc['date_label'] ?? 'Date', $doc['date'] ?? '']);
    if (trim((string) ($doc['status'] ?? '')) !== '') {
        fputcsv($out, ['Status', $doc['status']]);
    }
    fputcsv($out, []);
    fputcsv($out, [$doc['party_label'] ?? 'Party']);
    foreach ((array) ($doc['party_lines'] ?? []) as $line) {
        fputcsv($out, [$line]);
    }
    fputcsv($out, []);
    foreach ((array) ($doc['meta_rows'] ?? []) as $row) {
        fputcsv($out, [$row['label'] ?? '', $row['value'] ?? '']);
    }

    $columns = (array) ($doc['columns'] ?? []);
    $labels  = [];
    foreach ($columns as $column) {
        $labels[] = $column['label'] ?? '';
    }

    $writeTable = function (array $rows, array $labels) use ($out): void {
        fputcsv($out, []);
        fputcsv($out, $labels);
        foreach ($rows as $row) {
            // A cell can carry an explicit line break (product name + details);
            // in a spreadsheet that belongs in one cell, so it is joined.
            fputcsv($out, array_map(
                fn($cell) => trim((string) preg_replace('/\s*\R\s*/', ' — ', (string) $cell)),
                (array) $row
            ));
        }
    };

    $writeTable((array) ($doc['rows'] ?? []), $labels);

    $sumRow = $doc['sum_row'] ?? null;
    if (is_array($sumRow)) {
        fputcsv($out, ['', (string) ($sumRow['label'] ?? ''), '', '', (string) ($sumRow['value'] ?? '')]);
    }
    fputcsv($out, ['', (string) ($doc['total_label'] ?? 'Total'), '', '', (string) ($doc['total'] ?? '')]);
    if (trim((string) ($doc['amount_words'] ?? '')) !== '') {
        fputcsv($out, [(string) $doc['amount_words']]);
    }

    foreach ((array) ($doc['balances'] ?? []) as $balance) {
        fputcsv($out, [(string) ($balance['label'] ?? ''), (string) ($balance['value'] ?? '')]);
    }

    foreach ((array) ($doc['tables'] ?? []) as $table) {
        $tableLabels = [];
        foreach ((array) ($table['columns'] ?? []) as $column) {
            $tableLabels[] = $column['label'] ?? '';
        }
        fputcsv($out, []);
        fputcsv($out, [(string) ($table['title'] ?? '')]);
        $writeTable((array) ($table['rows'] ?? []), $tableLabels);
    }

    if (trim((string) ($doc['notes'] ?? '')) !== '') {
        fputcsv($out, []);
        fputcsv($out, [(string) $doc['notes']]);
    }

    fclose($out);
}
