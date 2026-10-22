<?php
/**
 * SebaBD — Invoices & payments (back office).
 *
 * The money side of the system. An invoice is billed to one customer branch
 * (#13) and may be built from an accepted quotation (#14) or a retail order
 * (#15) — at most one invoice for each. Its lines are invoice_item rows (#16),
 * each of which can carry the individual serialized device it sold (#17), and
 * every payment received against it is a payment_receipt row (#18).
 *
 *   admin-invoices.php                                  list + filters
 *   admin-invoices.php?action=new                       blank invoice
 *   admin-invoices.php?action=new&from_quotation=<no>   converted from a quotation
 *   admin-invoices.php?action=new&from_order=<id>       raised for an order
 *   admin-invoices.php?action=view&no=                  invoice, payments, units
 *   admin-invoices.php?action=edit&no=                  change lines (before payment)
 *   POST intent=create|update|delete|receipt|assign_units
 *
 * Two rules the page enforces rather than documents:
 *   - the stored TotalAmount is always the sum of its lines, and
 *   - RemainingBalance / PaymentStatus are always derived from the receipts,
 *     never typed by hand.
 */
require_once __DIR__ . '/admin-bootstrap.php';

$user   = require_module('admin-invoices.php');
$dbUp   = db() !== null;
$action = admin_action(['list', 'new', 'edit', 'view']);
$errors = [];

$filterStatus = trim((string) ($_GET['status'] ?? ''));
$filterClient = max(0, (int) ($_GET['client'] ?? 0));
$filterBranch = max(0, (int) ($_GET['branch'] ?? 0));
// Customer records for the filter dropdown — companies and individuals,
// house accounts excluded.
$clientsForFilter = [];
$search       = trim((string) ($_GET['q'] ?? ''));

/** The payment statuses an invoice can hold. */
function invoice_statuses(): array
{
    return ['DUE', 'PARTIAL', 'PAID'];
}

/** The payment methods offered when receipting money. */
function payment_methods(): array
{
    return ['Cash', 'Bank Transfer', 'bKash', 'Nagad', 'Credit Card', 'Cheque', 'Adjustment'];
}

/**
 * Derive the payment status from what has actually been collected, so the
 * column can never disagree with the receipts.
 */
function derive_payment_status(float $total, float $received): string
{
    if ($received <= 0.0) {
        return 'DUE';
    }

    return $received + 0.009 >= $total ? 'PAID' : 'PARTIAL';
}

/* ------------------------------------------------------------- the writes */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard('admin-invoices.php');

    $intent = post_str('intent');
    $pdo    = db();

    if (!$pdo) {
        $errors[] = 'The database is offline, so nothing can be saved right now.';
    } elseif ($intent === 'receipt') {
        /* ---------------------------------------------------- record payment */
        $invoiceNo = post_int('invoice_no');
        $amount    = post_float('amount_received', -1);
        $method    = post_str('payment_method', 'Cash');
        $date      = post_str('receipt_date', date('Y-m-d'));

        try {
            $invoice = db_fetch_all(
                'SELECT InvoiceNo, InvoiceDate, TotalAmount, RemainingBalance, PaymentStatus
                 FROM invoice WHERE InvoiceNo = :id',
                [':id' => $invoiceNo]
            );
            $receipts = db_fetch_all(
                'SELECT COALESCE(SUM(AmountReceived), 0) AS Received FROM payment_receipt WHERE InvoiceNo = :id',
                [':id' => $invoiceNo]
            );
            $received = (float) ($receipts[0]['Received'] ?? 0);

            if (!$invoice) {
                $errors[] = 'That invoice does not exist.';
            } elseif ($amount <= 0) {
                $errors[] = 'Enter the amount received — it must be more than zero.';
            } elseif (strtotime($date) === false) {
                $errors[] = 'The receipt date is not valid.';
            } elseif (strtotime($date) > strtotime(date('Y-m-d'))) {
                $errors[] = 'A payment cannot be received in the future — choose today or an earlier date.';
            } elseif ($received + $amount - 0.009 > (float) $invoice[0]['TotalAmount']) {
                $over = $received + $amount - (float) $invoice[0]['TotalAmount'];
                $errors[] = 'That receipt is more than the invoice is worth by ' . money($over)
                          . '. The invoice total is ' . money($invoice[0]['TotalAmount'])
                          . ' and ' . money($received) . ' has already been received.';
            } elseif (!in_array($method, payment_methods(), true)) {
                $errors[] = 'Choose one of the listed payment methods.';
            } else {
                $remainingAfter = round((float) $invoice[0]['TotalAmount'] - $received - $amount, 2);
                $status         = derive_payment_status((float) $invoice[0]['TotalAmount'], $received + $amount);

                db_transaction($pdo, function (PDO $tx) use ($invoiceNo, $date, $amount, $method, $remainingAfter, $status, $user) {
                    db_insert($tx, 'payment_receipt', [
                        'InvoiceNo'                 => $invoiceNo,
                        'ReceiptDate'               => $date,
                        'AmountReceived'            => $amount,
                        'PaymentMethod'             => $method,
                        'RemainingDueAfterReceipt'  => $remainingAfter,
                        'ReceivedBy'                => $user['FullName'],
                    ], 'ReceiptNo');

                    db_update($tx, 'invoice', [
                        'RemainingBalance' => $remainingAfter,
                        'PaymentStatus'    => $status,
                    ], ['InvoiceNo' => $invoiceNo]);
                });

                flash_set('success', 'Payment of ' . money($amount) . ' recorded against invoice #' . $invoiceNo
                    . '. Balance now ' . money($remainingAfter) . ' (' . $status . ').');
                post_success('admin-invoices.php?action=view&no=' . $invoiceNo);
            }
        } catch (Throwable $e) {
            $errors[] = admin_error_message($e, 'Could not record the payment.');
        }
        $action = 'view';
        $_GET['no'] = (string) $invoiceNo;
    } elseif ($intent === 'receipt_delete') {
        /* -------------------------------------------- reverse a mis-keyed payment
         * A receipt is the record of money received, so it is corrected by
         * deleting the row and recomputing the invoice from the receipts that
         * remain — never by typing a negative payment. Later receipts' running
         * balances are recalculated too, so the statement reads correctly.
         */
        $receiptNo = post_int('receipt_no');
        $invoiceNo = post_int('invoice_no');

        try {
            $receipt = db_fetch_all(
                'SELECT r.ReceiptNo, r.InvoiceNo, r.AmountReceived, i.TotalAmount
                 FROM payment_receipt r JOIN invoice i ON i.InvoiceNo = r.InvoiceNo
                 WHERE r.ReceiptNo = :id AND r.InvoiceNo = :inv',
                [':id' => $receiptNo, ':inv' => $invoiceNo]
            );

            if (!$receipt) {
                $errors[] = 'That receipt no longer exists.';
            } else {
                $total = (float) $receipt[0]['TotalAmount'];
                db_transaction($pdo, function (PDO $tx) use ($receiptNo, $invoiceNo, $total) {
                    db_delete($tx, 'payment_receipt', ['ReceiptNo' => $receiptNo]);

                    $left = db_fetch_all(
                        'SELECT ReceiptNo, AmountReceived FROM payment_receipt
                         WHERE InvoiceNo = :id ORDER BY ReceiptDate, ReceiptNo',
                        [':id' => $invoiceNo]
                    );
                    $running  = $total;
                    $received = 0.0;
                    foreach ($left as $row) {
                        $running  = round($running - (float) $row['AmountReceived'], 2);
                        $received += (float) $row['AmountReceived'];
                        db_update($tx, 'payment_receipt',
                            ['RemainingDueAfterReceipt' => $running],
                            ['ReceiptNo' => (int) $row['ReceiptNo']]);
                    }

                    db_update($tx, 'invoice', [
                        'RemainingBalance' => $running,
                        'PaymentStatus'    => derive_payment_status($total, $received),
                    ], ['InvoiceNo' => $invoiceNo]);
                });

                flash_set('success', 'Receipt #' . $receiptNo . ' reversed — invoice #' . $invoiceNo
                    . ' now shows its true balance again.');
                post_success('admin-invoices.php?action=view&no=' . $invoiceNo);
            }
        } catch (Throwable $e) {
            $errors[] = admin_error_message($e, 'Could not reverse that receipt.');
        }
        $action = 'view';
        $_GET['no'] = (string) $invoiceNo;
    } elseif ($intent === 'assign_units') {
        /* ------------------------------------------- attach serialized units */
        $invoiceNo = post_int('invoice_no');
        $itemId    = post_int('invoice_item_id');
        $unitIds   = post_list('equipment_ids', true);

        try {
            $line = db_fetch_all(
                'SELECT ii.InvoiceItemID, ii.InvoiceNo, ii.ProductID, ii.Quantity, ii.SoldUnitPrice,
                        ii.EquipmentID, p.ProductName, p.IsSerialized
                 FROM invoice_item ii
                 JOIN product p ON p.ProductID = ii.ProductID
                 WHERE ii.InvoiceItemID = :id AND ii.InvoiceNo = :inv',
                [':id' => $itemId, ':inv' => $invoiceNo]
            );

            if (!$line) {
                $errors[] = 'That invoice line no longer exists.';
            } elseif ((int) $line[0]['IsSerialized'] !== 1) {
                $errors[] = '“' . $line[0]['ProductName'] . '” is not tracked by serial number, so no units can be attached.';
            } elseif (!$unitIds) {
                $errors[] = 'Select at least one unit to attach.';
            } elseif (count($unitIds) > (int) $line[0]['Quantity']) {
                $errors[] = 'That line only has ' . (int) $line[0]['Quantity'] . ' unit(s) to assign, but '
                          . count($unitIds) . ' were selected.';
            } else {
                // Only the placeholders that appear in the statement below may
                // be bound: PDO rejects a parameter the query never uses, and
                // db_fetch_all() would swallow that error as an empty result.
                $holders = [];
                $params  = [];
                foreach ($unitIds as $index => $unitId) {
                    $holders[]             = ':u' . $index;
                    $params[':u' . $index] = $unitId;
                }
                $units = db_fetch_all(
                    'SELECT pi.EquipmentID, pi.SerialNumber, pi.ProductID, pi.Status,
                            (SELECT ii.InvoiceNo FROM invoice_item ii WHERE ii.EquipmentID = pi.EquipmentID) AS OnInvoice
                     FROM product_instance pi
                     WHERE pi.EquipmentID IN (' . implode(', ', $holders) . ')',
                    $params
                );

                $valid = [];
                foreach ($units as $unit) {
                    if ((int) $unit['ProductID'] !== (int) $line[0]['ProductID']) {
                        $errors[] = 'Unit ' . $unit['SerialNumber'] . ' belongs to a different product.';
                    } elseif ($unit['OnInvoice'] !== null) {
                        $errors[] = 'Unit ' . $unit['SerialNumber'] . ' is already on invoice #' . (int) $unit['OnInvoice'] . '.';
                    } elseif ($unit['Status'] !== 'In Stock') {
                        $errors[] = 'Unit ' . $unit['SerialNumber'] . ' is marked “' . $unit['Status'] . '”, not In Stock.';
                    } else {
                        $valid[] = $unit;
                    }
                }
                if (count($units) !== count($unitIds)) {
                    $errors[] = 'One of the selected units does not exist.';
                }

                if (!$errors) {
                    db_transaction($pdo, function (PDO $tx) use ($line, $valid, $invoiceNo) {
                        $source     = $line[0];
                        $unitPrice  = (float) $source['SoldUnitPrice'];
                        $remaining  = (int) $source['Quantity'] - count($valid);

                        // Each device becomes a line of its own, because the
                        // schema records one EquipmentID per invoice_item — that
                        // is how "many units on one line" is represented.
                        foreach ($valid as $unit) {
                            db_insert($tx, 'invoice_item', [
                                'InvoiceNo'     => $invoiceNo,
                                'ProductID'     => (int) $source['ProductID'],
                                'EquipmentID'   => (int) $unit['EquipmentID'],
                                'Quantity'      => 1,
                                'SoldUnitPrice' => $unitPrice,
                                'TotalPrice'    => round($unitPrice, 2),
                            ], 'InvoiceItemID');

                            // A device that has been sold leaves available stock.
                            db_update($tx, 'product_instance', ['Status' => 'Sold'],
                                ['EquipmentID' => (int) $unit['EquipmentID']]);
                        }

                        if ($remaining > 0) {
                            db_update($tx, 'invoice_item', [
                                'Quantity'   => $remaining,
                                'TotalPrice' => round($unitPrice * $remaining, 2),
                            ], ['InvoiceItemID' => (int) $source['InvoiceItemID']]);
                        } else {
                            db_delete($tx, 'invoice_item', ['InvoiceItemID' => (int) $source['InvoiceItemID']]);
                        }
                    });

                    flash_set('success', count($valid) . ' serialized unit(s) attached to invoice #' . $invoiceNo
                        . ' and marked Sold.');
                    post_success('admin-invoices.php?action=view&no=' . $invoiceNo);
                }
            }
        } catch (Throwable $e) {
            $errors[] = admin_error_message($e, 'Could not attach those units.');
        }
        $action = 'view';
        $_GET['no'] = (string) $invoiceNo;
    } elseif ($intent === 'delete') {
        /* -------------------------------------------------------- delete */
        $invoiceNo = post_int('invoice_no');
        try {
            $receipts = db_count('payment_receipt', 'InvoiceNo = :id', [':id' => $invoiceNo]);
            if ($invoiceNo <= 0) {
                $errors[] = 'That invoice no longer exists.';
            } elseif ($receipts > 0) {
                $errors[] = 'Invoice #' . $invoiceNo . ' has ' . $receipts . ' payment receipt'
                          . ($receipts === 1 ? '' : 's') . ' against it, so it cannot be deleted. '
                          . 'The receipts are the record of money received.';
            } else {
                $units = db_fetch_all(
                    'SELECT EquipmentID FROM invoice_item WHERE InvoiceNo = :id AND EquipmentID IS NOT NULL',
                    [':id' => $invoiceNo]
                );
                $bulk = db_fetch_all(
                    'SELECT ii.ProductID, ii.Quantity, p.IsSerialized
                     FROM invoice_item ii JOIN product p ON p.ProductID = ii.ProductID
                     WHERE ii.InvoiceNo = :id AND ii.EquipmentID IS NULL',
                    [':id' => $invoiceNo]
                );
                $flagRow = db_fetch_all(
                    'SELECT StockApplied FROM invoice WHERE InvoiceNo = :id',
                    [':id' => $invoiceNo]
                );
                $applied = (int) ($flagRow[0]['StockApplied'] ?? 0);
                db_transaction($pdo, function (PDO $tx) use ($invoiceNo, $units, $bulk, $applied) {
                    // Returning the devices to stock keeps the stock position
                    // honest when a sale is undone.
                    foreach ($units as $unit) {
                        db_update($tx, 'product_instance', ['Status' => 'In Stock'],
                            ['EquipmentID' => (int) $unit['EquipmentID']]);
                    }
                    // Bulk lines put their quantity back, but only for invoices
                    // this application moved stock for (see StockApplied).
                    if ($applied === 1) {
                        foreach ($bulk as $line) {
                            if ((int) $line['IsSerialized'] === 0) {
                                adjust_product_stock($tx, (int) $line['ProductID'], (int) $line['Quantity']);
                            }
                        }
                    }
                    db_delete($tx, 'invoice_item', ['InvoiceNo' => $invoiceNo]);
                    db_delete($tx, 'invoice', ['InvoiceNo' => $invoiceNo]);
                });
                flash_set('success', 'Invoice #' . $invoiceNo . ' deleted'
                    . ($units ? ' and ' . count($units) . ' serialized unit(s) returned to stock' : '')
                    . ($applied === 1 && $bulk ? ' and its bulk quantities put back on the shelf' : '') . '.');
                post_success('admin-invoices.php');
            }
        } catch (Throwable $e) {
            $errors[] = admin_error_message($e, 'Could not delete that invoice.');
        }
        $action = 'list';
    } else {
        /* ------------------------------------------------ create / update */
        $editNo     = post_int('invoice_no');
        // The create form prefills invoice_no with the next free number, so a
        // positive value alone does not mean "edit" — the posted intent does.
        // Zeroing it on create keeps every check below ($editNo === 0 / > 0)
        // reading "new document", and the save picks the real next number.
        if (post_str('intent') === 'create') {
            $editNo = 0;
        }
        $branchId   = post_int('branch_id');
        $quotation  = post_int('quotation_no');
        $orderId    = post_int('order_id');
        $invoiceDate = post_str('invoice_date', date('Y-m-d'));
        $productIds = post_rows('product_id');
        $quantities = post_rows('quantity');
        $prices     = post_rows('unit_price');

        if ($branchId <= 0 && $orderId <= 0 && $quotation <= 0) {
            $errors[] = 'Raise the invoice for a customer branch, a retail order, or convert a quotation.';
        }
        if (strtotime($invoiceDate) === false) {
            $errors[] = 'The invoice date is not valid.';
        }

        $lines         = [];
        $catalogPrices = [];
        foreach ($productIds as $index => $productIdRaw) {
            $productId = (int) $productIdRaw;
            $quantity  = (int) ($quantities[$index] ?? 0);
            if ($productId === 0 && $quantity === 0 && trim((string) ($prices[$index] ?? '')) === '') {
                continue;
            }
            if ($productId <= 0) {
                $errors[] = 'Line ' . ($index + 1) . ' has a quantity but no product.';
                continue;
            }
            if ($quantity <= 0) {
                $errors[] = 'Line ' . ($index + 1) . ' needs a quantity of at least 1.';
                continue;
            }
            if (!isset($catalogPrices[$productId])) {
                $found = db_fetch_all(
                    'SELECT ProductID, ProductName, StandardPrice, IsSerialized FROM product WHERE ProductID = :id',
                    [':id' => $productId]
                );
                $catalogPrices[$productId] = $found[0] ?? null;
            }
            $product = $catalogPrices[$productId];
            if (!$product) {
                $errors[] = 'Line ' . ($index + 1) . ' refers to a product that no longer exists.';
                continue;
            }
            $rawPrice = trim((string) ($prices[$index] ?? ''));
            $price    = $rawPrice === '' ? (float) $product['StandardPrice'] : (float) $rawPrice;
            if ($price < 0) {
                $errors[] = 'Line ' . ($index + 1) . ' has a negative price.';
                continue;
            }
            $lines[] = ['product' => $product, 'qty' => min(9999, $quantity), 'price' => $price];
        }
        if (!$lines && !$errors) {
            $errors[] = 'Add at least one product line — an invoice with no lines has no value.';
        }

        // A quotation can only become one invoice, it must still exist, and a
        // quotation the customer rejected or let expire is not a sale.
        if (!$errors && $quotation > 0) {
            if ($editNo === 0 && db_count('invoice', 'QuotationNo = :q', [':q' => $quotation]) > 0) {
                $errors[] = 'Quotation #' . $quotation . ' already has an invoice. A quotation converts once.';
            }
            $quoteRow = db_fetch_all(
                'SELECT QuotationNo, Status, ClientID, COALESCE(BranchID, 0) AS BranchID
                 FROM quotation WHERE QuotationNo = :id',
                [':id' => $quotation]
            );
            if (!$quoteRow) {
                $errors[] = 'Quotation #' . $quotation . ' does not exist.';
            } elseif (in_array((string) $quoteRow[0]['Status'], ['Rejected', 'Expired'], true)) {
                $errors[] = 'Quotation #' . $quotation . ' is ' . strtolower((string) $quoteRow[0]['Status'])
                          . ', so it cannot be invoiced. Re-quote the customer, or set the quotation to Accepted first.';
            }
        }
        if (!$errors && $orderId > 0) {
            $existingOrder = db_fetch_all(
                'SELECT o.OrderID, o.OrderStatus, c.ClientID
                 FROM `order` o LEFT JOIN client c ON c.UserID = o.UserID
                 WHERE o.OrderID = :id',
                [':id' => $orderId]
            );
            if (!$existingOrder) {
                $errors[] = 'Order #' . $orderId . ' does not exist.';
            } elseif ((string) $existingOrder[0]['OrderStatus'] === 'Cancelled') {
                $errors[] = 'Order #' . $orderId . ' was cancelled, so it cannot be invoiced. '
                          . 'Ask the customer to place a new order.';
            } elseif ($editNo === 0 && db_count('invoice', 'OrderID = :o', [':o' => $orderId]) > 0) {
                // On an edit the order already points at this same invoice — only
                // a *second* invoice for the order is a conflict.
                $errors[] = 'Order #' . $orderId . ' already has an invoice. An order is invoiced once.';
            }
        }

        // The billed branch must belong to the customer the document is for.
        // Without this check a tampered form could bill one company's office
        // while the invoice is owned by another company.
        if (!$errors && $branchId > 0) {
            $branchOwner = db_fetch_all(
                'SELECT ClientID FROM client_branch WHERE BranchID = :id',
                [':id' => $branchId]
            );
            if (!$branchOwner) {
                $errors[] = 'The billing branch does not exist.';
            } else {
                $expectedOwner = null;
                if ($quotation > 0 && !empty($quoteRow)) {
                    $expectedOwner = (int) $quoteRow[0]['ClientID'];
                } elseif ($orderId > 0 && !empty($existingOrder)) {
                    $expectedOwner = (int) ($existingOrder[0]['ClientID'] ?? 0) ?: null;
                }
                if ($expectedOwner !== null && (int) $branchOwner[0]['ClientID'] !== $expectedOwner) {
                    $errors[] = 'That branch belongs to a different customer than the document. '
                              . 'Choose a branch of the same company.';
                }
            }
        }

        if (!$errors) {
            $total = round(array_sum(array_map(fn($l) => $l['price'] * $l['qty'], $lines)), 2);

            try {
                $invoiceNo = db_transaction($pdo, function (PDO $tx) use ($editNo, $branchId, $quotation, $orderId, $invoiceDate, $lines, $total) {
                    // The owner (invoice.ClientID): from the quotation being
                    // converted, else from the retail order's buying customer,
                    // else from the billed branch. An individual's invoice has
                    // no branch but still needs its owner on record.
                    if ($quotation > 0) {
                        $owner = db_fetch_all('SELECT ClientID, COALESCE(BranchID, 0) AS B FROM quotation WHERE QuotationNo = :id', [':id' => $quotation]);
                        $ownerClientId = (int) ($owner[0]['ClientID'] ?? 0);
                        if (!$branchId) { $branchId = (int) ($owner[0]['B'] ?? 0); }
                    } elseif ($orderId > 0) {
                        $owner = db_fetch_all(
                            'SELECT c.ClientID FROM `order` o LEFT JOIN client c ON c.UserID = o.UserID WHERE o.OrderID = :id',
                            [':id' => $orderId]
                        );
                        $ownerClientId = (int) ($owner[0]['ClientID'] ?? 0) ?: null;
                    } else {
                        $owner = db_fetch_all('SELECT ClientID FROM client_branch WHERE BranchID = :id', [':id' => $branchId]);
                        $ownerClientId = (int) ($owner[0]['ClientID'] ?? 0) ?: null;
                    }

                    $header = [
                        'BranchID'         => $branchId > 0 ? $branchId : null,
                        'ClientID'         => $ownerClientId,
                        'QuotationNo'      => $quotation > 0 ? $quotation : null,
                        'OrderID'          => $orderId > 0 ? $orderId : null,
                        'InvoiceDate'      => $invoiceDate,
                        'StockApplied'     => 1,
                        'TotalAmount'      => $total,
                        'RemainingBalance' => $total,
                        'PaymentStatus'    => 'DUE',
                        'AmountInWords'    => amount_in_words($total),
                    ];

                    if ($editNo > 0) {
                        $existing = db_fetch_all(
                            'SELECT InvoiceNo, TotalAmount, StockApplied FROM invoice WHERE InvoiceNo = :id',
                            [':id' => $editNo]
                        );
                        if (!$existing) {
                            throw new RuntimeException('missing-invoice');
                        }
                        $receipts = db_fetch_all(
                            'SELECT COALESCE(SUM(AmountReceived), 0) AS Received FROM payment_receipt WHERE InvoiceNo = :id',
                            [':id' => $editNo]
                        );
                        $received = (float) ($receipts[0]['Received'] ?? 0);
                        if ($received > 0) {
                            throw new RuntimeException('has-receipts');
                        }

                        // Attached devices are tied to individual lines, so
                        // rewriting the lines would orphan the serial numbers
                        // and their warranty dates.
                        $attached = db_fetch_all(
                            'SELECT COUNT(*) AS n FROM invoice_item
                             WHERE InvoiceNo = :id AND EquipmentID IS NOT NULL',
                            [':id' => $editNo]
                        );
                        if ((int) ($attached[0]['n'] ?? 0) > 0) {
                            throw new RuntimeException('has-units');
                        }

                        // The old lines are about to disappear: put their bulk
                        // quantities back first, but only if this invoice took
                        // them out (StockApplied). Then the new lines are taken
                        // out below, so an edit is a net stock correction.
                        if ((int) $existing[0]['StockApplied'] === 1) {
                            $oldLines = db_fetch_all(
                                'SELECT ii.ProductID, ii.Quantity, p.IsSerialized
                                 FROM invoice_item ii JOIN product p ON p.ProductID = ii.ProductID
                                 WHERE ii.InvoiceNo = :id',
                                [':id' => $editNo]
                            );
                            foreach ($oldLines as $old) {
                                if ((int) $old['IsSerialized'] === 0) {
                                    adjust_product_stock($tx, (int) $old['ProductID'], (int) $old['Quantity']);
                                }
                            }
                        }

                        // Changing the lines changes what is owed, so the
                        // balance and status are recomputed rather than kept.
                        $header['RemainingBalance'] = $total;
                        $header['PaymentStatus']    = 'DUE';

                        db_update($tx, 'invoice', $header, ['InvoiceNo' => $editNo]);
                        // Safe to replace wholesale: the guards above proved no
                        // payment and no device is attached to these lines.
                        db_delete($tx, 'invoice_item', ['InvoiceNo' => $editNo]);
                        $invoiceNo = $editNo;
                    } else {
                        $invoiceNo = db_insert($tx, 'invoice', $header, 'InvoiceNo');
                    }

                    $itemId = next_id($tx, 'invoice_item', 'InvoiceItemID');
                    foreach ($lines as $line) {
                        db_insert($tx, 'invoice_item', [
                            'InvoiceItemID' => $itemId++,
                            'InvoiceNo'     => $invoiceNo,
                            'ProductID'     => (int) $line['product']['ProductID'],
                            'EquipmentID'   => null,
                            'Quantity'      => $line['qty'],
                            'SoldUnitPrice' => $line['price'],
                            'TotalPrice'    => round($line['price'] * $line['qty'], 2),
                        ]);

                        // A bulk product leaves the shelf when it is invoiced.
                        // Serialized products do not: their units are counted by
                        // product_instance, and the invoice page marks the exact
                        // device Sold when it is attached to the line.
                        if ((int) ($line['product']['IsSerialized'] ?? 0) === 0) {
                            adjust_product_stock($tx, (int) $line['product']['ProductID'], -$line['qty']);
                        }
                    }

                    // Converting a quotation is the moment the customer said
                    // yes, so the document is recorded as accepted with it.
                    if ($quotation > 0) {
                        db_update($tx, 'quotation', ['Status' => 'Accepted'], ['QuotationNo' => $quotation]);
                    }

                    return $invoiceNo;
                });

                flash_set('success', ($editNo > 0 ? 'Invoice #' . $invoiceNo . ' updated' : 'Invoice #' . $invoiceNo . ' raised')
                    . ' for ' . money($total) . '.');
                post_success('admin-invoices.php?action=view&no=' . $invoiceNo);
            } catch (RuntimeException $e) {
                $errors[] = match ($e->getMessage()) {
                    'has-receipts'    => 'That invoice already has payments recorded, so its lines can no longer be changed. '
                                       . 'Delete nothing — the receipts are the record of money received.',
                    'has-units'       => 'That invoice already has serialized devices attached to its lines, so the lines '
                                       . 'can no longer be rewritten. Delete the invoice instead, which returns those '
                                       . 'devices to stock, then raise it again.',
                    'missing-invoice' => 'Invoice #' . $editNo . ' no longer exists.',
                    default           => 'Could not save the invoice.',
                };
            } catch (Throwable $e) {
                $errors[] = admin_error_message($e, 'Could not save the invoice.');
            }
        }

        $action = $editNo > 0 ? 'edit' : 'new';
        keep_old([
            'branch_id'    => $branchId,
            'invoice_date' => $invoiceDate,
            'product_id'   => $productIds,
            'quantity'     => $quantities,
            'unit_price'   => $prices,
            'quotation_no' => $quotation,
            'order_id'     => $orderId,
        ]);
    }
}

/* -------------------------------------------------------------- the reads */

$rows        = [];
$invoice     = null;
$invoiceLines = [];
$receipts    = [];
$catalog     = [];
$branches    = [];
$orders      = [];
$nextFreeNo  = 0;
$totals      = [];
$invoicePage = null;
$availableUnits = [];
$fromQuotation  = null;
$fromOrder      = null;

if ($dbUp) {
    $catalog = db_fetch_all(
        'SELECT p.ProductID, p.ProductName, p.StandardPrice, p.DefaultWarrantyMonths, c.CategoryName
         FROM product p JOIN category c ON c.CategoryID = p.CategoryID
         ORDER BY c.CategoryName, p.ProductName'
    );

    $branches = db_fetch_all(
        'SELECT b.BranchID, b.BranchName, c.ClientID, c.CompanyName
         FROM client_branch b JOIN client c ON c.ClientID = b.ClientID
         ORDER BY c.CompanyName, b.BranchName'
    );

    $clientsForFilter = db_fetch_all(
        "SELECT ClientID, CompanyName FROM client
         WHERE IFNULL(AccountType, 'b2b') <> 'house'
         ORDER BY CompanyName"
    );

    // Orders that do not have an invoice yet — the candidates for "raise an
    // invoice for this order", which is the 1:1 in relationship #15.
    $orders = db_fetch_all(
        'SELECT o.OrderID, o.OrderDate, o.TotalAmount, o.OrderStatus, u.FullName, u.Username,
                (SELECT COUNT(*) FROM order_item oi WHERE oi.OrderID = o.OrderID) AS Items
         FROM `order` o
         JOIN `user` u ON u.UserID = o.UserID
         WHERE NOT EXISTS (SELECT 1 FROM invoice i WHERE i.OrderID = o.OrderID)
         ORDER BY o.OrderDate DESC'
    );

    if ($action === 'view' || $action === 'edit') {
        $no      = max(0, (int) ($_GET['no'] ?? 0));
        $found   = db_fetch_all(
            'SELECT i.InvoiceNo, i.BranchID, i.ClientID, i.QuotationNo, i.OrderID, i.InvoiceDate, i.PaymentStatus,
                    i.TotalAmount, i.RemainingBalance, i.AmountInWords,
                    b.BranchName, b.BranchAddress, b.ContactNo,
                    c.CompanyName, c.ContactPerson, c.Email, c.Phone, c.Address,
                    o.OrderDate, o.ShippingAddress, o.OrderStatus, o.PaymentMethod AS OrderPaymentMethod, o.PaymentReference AS OrderPaymentReference,
                    ou.FullName AS OrderCustomer, ou.Username AS OrderUsername, ou.Email AS OrderEmail, ou.Phone AS OrderPhone
             FROM invoice i
             LEFT JOIN client_branch b ON b.BranchID = i.BranchID
             LEFT JOIN client        c ON c.ClientID = i.ClientID
             LEFT JOIN `order`       o ON o.OrderID  = i.OrderID
             LEFT JOIN `user`       ou ON ou.UserID  = o.UserID
             WHERE i.InvoiceNo = :id',
            [':id' => $no]
        );
        $invoice = $found[0] ?? null;
        if ($invoice === null) {
            deny_page(404, 'Invoice not found', 'Invoice #' . $no . ' does not exist.', 'admin-invoices.php', 'All invoices');
        }

        $invoiceLines = db_fetch_all(
            'SELECT ii.InvoiceItemID, ii.ProductID, ii.EquipmentID, ii.Quantity, ii.SoldUnitPrice, ii.TotalPrice,
                    p.ProductName, p.Brand, p.Model, p.IsSerialized,
                    pi.SerialNumber, pi.Status AS UnitStatus, pi.ClientWarrantyExpiry
             FROM invoice_item ii
             JOIN product p ON p.ProductID = ii.ProductID
             LEFT JOIN product_instance pi ON pi.EquipmentID = ii.EquipmentID
             WHERE ii.InvoiceNo = :id
             ORDER BY ii.InvoiceItemID',
            [':id' => $no]
        );

        $receipts = db_fetch_all(
            'SELECT ReceiptNo, ReceiptDate, AmountReceived, PaymentMethod, RemainingDueAfterReceipt, ReceivedBy
             FROM payment_receipt
             WHERE InvoiceNo = :id
             ORDER BY ReceiptDate, ReceiptNo',
            [':id' => $no]
        );

        // The line editor is only honest while nothing depends on those lines.
        // Both conditions are enforced again on submit; this stops the form
        // from being opened in the first place.
        if ($action === 'edit') {
            $receivedSum = array_sum(array_map(fn($r) => (float) $r['AmountReceived'], $receipts));
            $unitCount   = count(array_filter($invoiceLines, fn($l) => $l['EquipmentID'] !== null));
            if ($receivedSum > 0 || $unitCount > 0) {
                flash_set('error', $receivedSum > 0
                    ? 'Invoice #' . $no . ' has payments recorded against it, so its lines are locked.'
                    : 'Invoice #' . $no . ' has serialized devices attached, so its lines are locked.');
                header('Location: admin-invoices.php?action=view&no=' . $no);
                exit;
            }
        }

        // Units that could still be attached to a serialized line on this invoice.
        $availableUnits = db_fetch_all(
            "SELECT pi.EquipmentID, pi.SerialNumber, pi.ProductID, pi.Status, pi.ClientWarrantyExpiry
             FROM product_instance pi
             WHERE pi.Status = 'In Stock'
               AND NOT EXISTS (SELECT 1 FROM invoice_item ii WHERE ii.EquipmentID = pi.EquipmentID)
             ORDER BY pi.ProductID, pi.SerialNumber"
        );
    }

    if ($action === 'new') {
        $preview    = db_fetch_all('SELECT COALESCE(MAX(InvoiceNo), 0) + 1 AS n FROM invoice');
        $nextFreeNo = (int) ($preview[0]['n'] ?? 5001);

        // ---- converted from an accepted quotation
        $quoteNo = max(0, (int) ($_GET['from_quotation'] ?? 0));
        if ($quoteNo > 0) {
            $q = db_fetch_all(
                'SELECT q.QuotationNo, q.BranchID, q.ClientID, q.Subject, q.TotalAmount, q.Status,
                        b.BranchName, c.CompanyName,
                        (SELECT i.InvoiceNo FROM invoice i WHERE i.QuotationNo = q.QuotationNo) AS InvoiceNo
                 FROM quotation q
                 LEFT JOIN client_branch b ON b.BranchID = q.BranchID
                 LEFT JOIN client c ON c.ClientID = q.ClientID
                 WHERE q.QuotationNo = :id',
                [':id' => $quoteNo]
            );
            if (!$q) {
                deny_page(404, 'Quotation not found', 'Quotation #' . $quoteNo . ' does not exist.', 'admin-quotations.php', 'All quotations');
            } elseif ($q[0]['InvoiceNo'] !== null) {
                flash_set('error', 'Quotation #' . $quoteNo . ' is already invoiced as #' . (int) $q[0]['InvoiceNo'] . '.');
                header('Location: admin-invoices.php');
                exit;
            } elseif (in_array((string) $q[0]['Status'], ['Rejected', 'Expired'], true)) {
                flash_set('error', 'Quotation #' . $quoteNo . ' is ' . strtolower((string) $q[0]['Status'])
                    . ', so it cannot be invoiced. Set it to Accepted first if the customer has changed their mind.');
                header('Location: admin-quotations.php?action=view&id=' . $quoteNo);
                exit;
            } else {
                $fromQuotation = $q[0];
                $fromQuotation['lines'] = db_fetch_all(
                    'SELECT ProductID, Quantity, UnitPrice FROM quotation_item WHERE QuotationNo = :id ORDER BY QuotationItemID',
                    [':id' => $quoteNo]
                );
            }
        }

        // ---- raised for a retail order
        $orderNo = max(0, (int) ($_GET['from_order'] ?? 0));
        if ($orderNo > 0) {
            // The branch to bill: the order's own branch when it has one (an
            // internal order names the SebaBD branch it was placed for), and
            // otherwise the first branch of the customer's company.
            $order = db_fetch_all(
                'SELECT o.OrderID, o.OrderDate, o.TotalAmount, o.OrderStatus, o.OrderType,
                        o.PaymentMethod, o.PaymentReference,
                        o.BranchID AS OrderBranchID, o.ShippingAddress,
                        u.UserID, u.FullName, u.Email, u.Phone,
                        c.ClientID, c.CompanyName,
                        COALESCE(o.BranchID,
                                 (SELECT MIN(b.BranchID) FROM client_branch b WHERE b.ClientID = c.ClientID)) AS BranchID,
                        (SELECT i.InvoiceNo FROM invoice i WHERE i.OrderID = o.OrderID) AS InvoiceNo
                 FROM `order` o
                 JOIN `user` u ON u.UserID = o.UserID
                 LEFT JOIN client c ON c.UserID = u.UserID
                 WHERE o.OrderID = :id',
                [':id' => $orderNo]
            );
            if (!$order) {
                deny_page(404, 'Order not found', 'Order #' . $orderNo . ' does not exist.', 'admin-orders.php', 'All orders');
            } elseif ($order[0]['InvoiceNo'] !== null) {
                flash_set('error', 'Order #' . $orderNo . ' already has invoice #' . (int) $order[0]['InvoiceNo'] . '.');
                header('Location: admin-invoices.php');
                exit;
            } else {
                $fromOrder = $order[0];
                $fromOrder['lines'] = db_fetch_all(
                    'SELECT ProductID, Quantity, UnitPrice FROM order_item WHERE OrderID = :id ORDER BY OrderItemID',
                    [':id' => $orderNo]
                );
            }
        }
    }

    // ---- list
    $where  = [];
    $params = [];
    if ($filterStatus !== '' && in_array($filterStatus, invoice_statuses(), true)) {
        $where[]           = 'i.PaymentStatus = :status';
        $params[':status'] = $filterStatus;
    }
    if ($filterClient > 0) {
        $where[]           = 'c.ClientID = :client';
        $params[':client'] = $filterClient;
    }
    if ($filterBranch > 0) {
        $where[]           = 'i.BranchID = :branch';
        $params[':branch'] = $filterBranch;
    }
    if ($search !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        $where[] = '(c.CompanyName LIKE :q1 OR b.BranchName LIKE :q2 OR ou.FullName LIKE :q3)';
        $params[':q1'] = $like;
        $params[':q2'] = $like;
        $params[':q3'] = $like;
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $from     = ' FROM invoice i
                  LEFT JOIN client_branch b ON b.BranchID = i.BranchID
                  LEFT JOIN client        c ON c.ClientID = i.ClientID
                  LEFT JOIN `order`       o ON o.OrderID  = i.OrderID
                  LEFT JOIN `user`       ou ON ou.UserID  = o.UserID';

    $countRow    = db_fetch_all('SELECT COUNT(*) AS n' . $from . $whereSql, $params);
    $invoicePage = paginate((int) ($countRow[0]['n'] ?? 0), 20);

    $rows = db_fetch_all(
        'SELECT i.InvoiceNo, i.InvoiceDate, i.PaymentStatus, i.TotalAmount, i.RemainingBalance,
                i.QuotationNo, i.OrderID, i.BranchID, i.ClientID AS OwnerClientID,
                b.BranchName, c.CompanyName,
                ou.FullName AS OrderCustomer,
                (SELECT COUNT(*) FROM invoice_item ii WHERE ii.InvoiceNo = i.InvoiceNo) AS `Lines`,
                (SELECT COUNT(*) FROM payment_receipt pr WHERE pr.InvoiceNo = i.InvoiceNo) AS Receipts'
        . $from . $whereSql . '
         ORDER BY i.InvoiceDate DESC, i.InvoiceNo DESC
         LIMIT ' . $invoicePage['perPage'] . ' OFFSET ' . $invoicePage['offset'],
        $params
    );

    $totals = db_fetch_all(
        "SELECT COUNT(*) AS AllInvoices,
                COALESCE(SUM(TotalAmount), 0) AS Billed,
                COALESCE(SUM(TotalAmount - RemainingBalance), 0) AS Collected,
                COALESCE(SUM(RemainingBalance), 0) AS Outstanding,
                SUM(CASE WHEN PaymentStatus <> 'PAID' THEN 1 ELSE 0 END) AS Unpaid
         FROM invoice"
    );
    $totals = $totals[0] ?? [];
}

ajax_errors($errors);

$filterQs = http_build_query(array_filter([
    'status' => $filterStatus,
    'client' => $filterClient ?: null,
    'branch' => $filterBranch ?: null,
    'q'      => $search,
]));
$listUrl  = 'admin-invoices.php' . ($filterQs !== '' ? '?' . $filterQs : '');

echo partial_render('partials/header.php', ['page_title' => 'Invoices & payments', 'active_nav' => 'admin']);
echo admin_lead('Invoices &amp; payments', 'Raise an invoice from a quotation or an order, attach the serialized devices it sold, and receipt every payment against it.');
?>

<div class="container pb-5">
  <?= render_flashes() ?>

  <?php if (!$dbUp): ?>
  <div class="alert alert-danger mb-0">
    <i class="fa-solid fa-plug-circle-xmark me-2"></i>
    The database is offline, so invoices cannot be listed. Start MySQL in the XAMPP control panel and reload.
  </div>
  <?php else: ?>

  <?= admin_tabs([
      'All invoices' => 'admin-invoices.php',
      'New invoice'  => 'admin-invoices.php?action=new',
  ], $action === 'list' ? 'All invoices' : ($action === 'new' ? 'New invoice' : '')) ?>

  <div class="ajax-errors" data-ajax-errors>
    <?php if ($errors): ?>
    <div class="alert alert-danger">
      <ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= htmlspecialchars($err) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($action === 'list'): ?>

  <div class="row g-3 mb-4">
    <?= report_kpi('Invoices raised', number_format((int) ($totals['AllInvoices'] ?? 0)), money((float) ($totals['Billed'] ?? 0)) . ' billed in total', 'fa-file-invoice-dollar') ?>
    <?= report_kpi('Collected', money((float) ($totals['Collected'] ?? 0)), 'Matched by payment receipts', 'fa-sack-dollar') ?>
    <?= report_kpi('Outstanding', money((float) ($totals['Outstanding'] ?? 0)), 'Across ' . number_format((int) ($totals['Unpaid'] ?? 0)) . ' open invoices', 'fa-hand-holding-dollar') ?>
    <?= report_kpi('Collection rate', ((float) ($totals['Billed'] ?? 0)) > 0
        ? number_format((float) $totals['Collected'] / (float) $totals['Billed'] * 100, 1) . '%'
        : '—', 'Collected as a share of billed', 'fa-percent') ?>
  </div>

  <form class="report-filter mb-3" method="get" action="admin-invoices.php">
    <div class="report-filter-fields">
      <label class="report-filter-field">
        <span class="form-label">Company, branch or customer</span>
        <input class="form-control form-control-sm" type="search" name="q" value="<?= htmlspecialchars($search) ?>">
      </label>
      <label class="report-filter-field">
        <span class="form-label">Payment status</span>
        <select class="form-select form-select-sm" name="status">
          <option value="">Any status</option>
          <?php foreach (invoice_statuses() as $status): ?>
          <option value="<?= htmlspecialchars($status) ?>" <?= $filterStatus === $status ? 'selected' : '' ?>><?= htmlspecialchars($status) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="report-filter-field">
        <span class="form-label">Customer</span>
        <select class="form-select form-select-sm" name="client">
          <option value="0">All customers</option>
          <?php
          $companies = [];
          foreach ($clientsForFilter as $clientOpt) {
              $companies[(int) $clientOpt['ClientID']] = $clientOpt['CompanyName'];
          }
          ksort($companies);
          foreach ($companies as $companyId => $companyName): ?>
          <option value="<?= $companyId ?>" <?= $filterClient === $companyId ? 'selected' : '' ?>><?= htmlspecialchars($companyName) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="report-filter-field">
        <span class="form-label">Branch</span>
        <select class="form-select form-select-sm" name="branch">
          <option value="0">All branches</option>
          <?php foreach ($branches as $branch): ?>
          <option value="<?= (int) $branch['BranchID'] ?>" <?= $filterBranch === (int) $branch['BranchID'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($branch['CompanyName']) ?> — <?= htmlspecialchars($branch['BranchName']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <div class="report-filter-actions">
      <button class="btn btn-accent btn-sm" type="submit"><i class="fa-solid fa-filter me-1"></i>Apply</button>
      <a class="btn btn-outline-secondary btn-sm" href="admin-invoices.php">Reset</a>
    </div>
  </form>

  <?= partial_render('partials/admin-list.php', [
      'panel_title' => 'Invoices',
      'panel_sub'   => 'Newest first, with the balance still owed on each one.',
      'columns'     => ['#', 'Date', 'Billed to', 'Source', 'Lines', 'Total', 'Balance', 'Status', ''],
      'rows'        => array_map(function ($row) {
          $billedTo = $row['CompanyName'] !== null
              ? '<a class="text-decoration-none" href="admin-customers.php?action=edit&id=' . (int) $row['OwnerClientID'] . '">'
                  . htmlspecialchars($row['CompanyName']) . '</a>'
                  . '<small class="d-block text-muted">' . (!empty($row['BranchName']) ? htmlspecialchars($row['BranchName']) : 'individual — no branch') . '</small>'
              : '<span class="fw-semibold">' . htmlspecialchars((string) ($row['OrderCustomer'] ?? 'Walk-in customer')) . '</span>'
                  . '<small class="d-block text-muted">retail order — no customer record</small>';

          $source = $row['QuotationNo'] !== null
              ? '<a class="text-decoration-none" href="admin-quotations.php?action=view&id=' . (int) $row['QuotationNo'] . '">Quotation #' . (int) $row['QuotationNo'] . '</a>'
              : ($row['OrderID'] !== null
                  ? '<a class="text-decoration-none" href="admin-orders.php?action=view&id=' . (int) $row['OrderID'] . '">Order #' . (int) $row['OrderID'] . '</a>'
                  : '<span class="text-muted small">raised directly</span>');

          return [
              '<a class="text-decoration-none" href="admin-invoices.php?action=view&no=' . (int) $row['InvoiceNo'] . '">'
                  . (int) $row['InvoiceNo'] . '</a>',
              format_date($row['InvoiceDate']),
              $billedTo,
              $source,
              ((int) $row['Lines']) . ' · ' . ((int) $row['Receipts']) . ' receipt' . ((int) $row['Receipts'] === 1 ? '' : 's'),
              '<span class="admin-line-total">' . money($row['TotalAmount']) . '</span>',
              (float) $row['RemainingBalance'] > 0
                  ? '<span class="admin-line-total text-danger fw-semibold">' . money($row['RemainingBalance']) . '</span>'
                  : '<span class="text-success admin-line-total">' . money(0) . '</span>',
              status_badge((string) $row['PaymentStatus']),
              '<div class="admin-actions-cell">'
                  . '<a class="btn btn-sm btn-outline-secondary" href="admin-invoices.php?action=view&no=' . (int) $row['InvoiceNo'] . '" title="Open">'
                      . '<i class="fa-solid fa-eye"></i><span class="visually-hidden">Open</span></a> '
                  . '<a class="btn btn-sm btn-outline-secondary" href="invoice.php?no=' . (int) $row['InvoiceNo'] . '" title="Printable">'
                      . '<i class="fa-solid fa-print"></i><span class="visually-hidden">Print</span></a> '
                  . '<a class="btn btn-sm btn-outline-secondary" href="admin-invoices.php?action=edit&no=' . (int) $row['InvoiceNo'] . '" title="Edit">'
                      . '<i class="fa-solid fa-pen"></i><span class="visually-hidden">Edit</span></a> '
                  . '<form method="post" action="admin-invoices.php" class="d-inline"'
                      . ' onsubmit="return confirm(\'Delete invoice #' . (int) $row['InvoiceNo'] . '?\');">'
                      . csrf_field()
                      . '<input type="hidden" name="intent" value="delete">'
                      . '<input type="hidden" name="invoice_no" value="' . (int) $row['InvoiceNo'] . '">'
                      . '<button class="btn btn-sm btn-outline-danger" type="submit">'
                      . '<i class="fa-solid fa-trash-can"></i><span class="visually-hidden">Delete</span></button>'
                      . '</form>'
                  . '</div>',
          ];
      }, $rows),
      'empty_icon'   => 'fa-file-invoice-dollar',
      'empty_title'  => $filterStatus !== '' || $filterClient > 0 || $filterBranch > 0 || $search !== ''
          ? 'No invoices matched those filters'
          : 'No invoices yet',
      'empty_text'   => $filterStatus !== '' || $filterClient > 0 || $filterBranch > 0 || $search !== ''
          ? 'Clear the filters to see every invoice.'
          : 'Convert an accepted quotation or raise an invoice for an order — both links are on this page.',
      'empty_action' => $filterStatus !== '' || $filterClient > 0 || $filterBranch > 0 || $search !== ''
          ? '<a class="btn btn-outline-secondary btn-sm" href="admin-invoices.php">Clear filters</a>'
          : '<a class="btn btn-accent btn-sm" href="admin-invoices.php?action=new"><i class="fa-solid fa-plus me-1"></i>New invoice</a>',
      'actions'      => admin_actions([['href' => 'admin-invoices.php?action=new', 'label' => 'New invoice', 'icon' => 'fa-plus']]),
      'summary'      => pagination_summary($invoicePage, 'invoice'),
      'pagination'   => pagination_links($invoicePage, $listUrl),
      'note'         => '<code>RemainingBalance</code> and <code>PaymentStatus</code> are derived from the '
                      . '<code>payment_receipt</code> rows — they are never typed in by hand.',
  ]) ?>

  <?php elseif ($action === 'view'):
      $received = 0.0;
      foreach ($receipts as $receipt) {
          $received += (float) $receipt['AmountReceived'];
      }
      $hasUnits = array_filter($invoiceLines, fn($l) => $l['EquipmentID'] !== null);
      $serializedLines = array_filter($invoiceLines, fn($l) => (int) $l['IsSerialized'] === 1 && $l['EquipmentID'] === null);
      $unitsByProduct = [];
      foreach ($availableUnits as $unit) {
          $unitsByProduct[(int) $unit['ProductID']][] = $unit;
      }
  ?>
  <div class="row g-4">
    <div class="col-lg-8">
      <div class="report-panel">
        <div class="report-panel-head d-flex flex-wrap justify-content-between align-items-start gap-2">
          <div>
            <h2 class="report-panel-title">Invoice #<?= (int) $invoice['InvoiceNo'] ?></h2>
            <p class="report-panel-sub">
              <?= format_date($invoice['InvoiceDate']) ?>
              <?php if ($invoice['CompanyName'] !== null): ?>
              · <?= htmlspecialchars($invoice['CompanyName']) ?> — <?= htmlspecialchars((string) $invoice['BranchName']) ?>
              <?php else: ?>
              · retail order for <?= htmlspecialchars((string) ($invoice['OrderCustomer'] ?? 'walk-in customer')) ?>
              <?php endif; ?>
            </p>
          </div>
          <?= status_badge((string) $invoice['PaymentStatus']) ?>
        </div>

        <div class="table-responsive">
          <table class="table table-sm report-th align-middle mb-0">
            <thead>
              <tr><th scope="col">#</th><th scope="col">Product</th><th scope="col">Serialized unit</th>
                  <th scope="col" class="text-end">Qty</th><th scope="col" class="text-end">Unit price</th>
                  <th scope="col" class="text-end">Total</th></tr>
            </thead>
            <tbody>
              <?php foreach ($invoiceLines as $line): ?>
              <tr>
                <td class="text-muted"><?= (int) $line['InvoiceItemID'] ?></td>
                <td>
                  <?= htmlspecialchars($line['ProductName']) ?>
                  <small class="d-block text-muted">
                    <?= htmlspecialchars(trim(($line['Brand'] ?? '') . ($line['Model'] ? ' · ' . $line['Model'] : '')) ?: '—') ?>
                    · product #<?= (int) $line['ProductID'] ?>
                    <?= (int) $line['IsSerialized'] === 1 ? ' · serialized' : '' ?>
                  </small>
                </td>
                <td>
                  <?php if ($line['EquipmentID'] !== null): ?>
                  <code><?= htmlspecialchars((string) $line['SerialNumber']) ?></code>
                  <small class="d-block text-muted">
                    unit #<?= (int) $line['EquipmentID'] ?>
                    · warranty to <?= format_date($line['ClientWarrantyExpiry']) ?>
                  </small>
                  <?php elseif ((int) $line['IsSerialized'] === 1): ?>
                  <span class="report-badge report-badge-warn">not assigned</span>
                  <?php else: ?>
                  <span class="text-muted small">bulk stock</span>
                  <?php endif; ?>
                </td>
                <td class="text-end"><?= (int) $line['Quantity'] ?></td>
                <td class="text-end admin-line-total"><?= money($line['SoldUnitPrice']) ?></td>
                <td class="text-end admin-line-total"><?= money($line['TotalPrice']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr>
                <td colspan="3" class="text-end">
                  <span class="text-muted fw-normal small">sum of lines</span>
                </td>
                <td class="text-end" colspan="2"><?= number_format((int) array_sum(array_map(fn($l) => (int) $l['Quantity'], $invoiceLines))) ?> units</td>
                <td class="text-end"><?= money(array_sum(array_map(fn($l) => (float) $l['TotalPrice'], $invoiceLines))) ?></td>
              </tr>
              <tr>
                <td colspan="5" class="text-end fw-bold">Invoice total</td>
                <td class="text-end fw-bold"><?= money($invoice['TotalAmount']) ?></td>
              </tr>
              <tr>
                <td colspan="5" class="text-end text-success">Received</td>
                <td class="text-end text-success"><?= money($received) ?></td>
              </tr>
              <tr>
                <td colspan="5" class="text-end text-danger">Still due</td>
                <td class="text-end text-danger fw-bold"><?= money($invoice['RemainingBalance']) ?></td>
              </tr>
            </tfoot>
          </table>
        </div>

        <p class="text-muted small mb-0 mt-3">
          <strong>In words:</strong> <?= htmlspecialchars((string) $invoice['AmountInWords']) ?>
        </p>
      </div>

      <?= partial_render('partials/admin-list.php', [
          'panel_title' => 'Payment receipts',
          'panel_sub'   => 'Every payment recorded against this invoice, oldest first. A mistyped payment is reversed here, never by entering a negative amount.',
          'columns'     => ['#', 'Date', 'Method', 'Received by', 'Amount', 'Balance after', ''],
          'rows'        => array_map(fn($r) => [
              '<span class="text-muted">' . (int) $r['ReceiptNo'] . '</span>',
              format_date($r['ReceiptDate']),
              htmlspecialchars((string) $r['PaymentMethod']),
              htmlspecialchars((string) $r['ReceivedBy']),
              '<span class="admin-line-total text-success">' . money($r['AmountReceived']) . '</span>',
              '<span class="admin-line-total">' . money($r['RemainingDueAfterReceipt']) . '</span>',
              '<form method="post" action="admin-invoices.php" class="d-inline"'
                  . ' onsubmit="return confirm(\'Reverse receipt #' . (int) $r['ReceiptNo'] . ' for '
                  . htmlspecialchars(money($r['AmountReceived']), ENT_QUOTES) . '? The balance will be recalculated.\');">'
                  . csrf_field()
                  . '<input type="hidden" name="intent" value="receipt_delete">'
                  . '<input type="hidden" name="receipt_no" value="' . (int) $r['ReceiptNo'] . '">'
                  . '<input type="hidden" name="invoice_no" value="' . (int) $invoice['InvoiceNo'] . '">'
                  . '<button class="btn btn-sm btn-outline-danger" type="submit">'
                  . '<i class="fa-solid fa-rotate-left"></i><span class="visually-hidden">Reverse payment</span>'
                  . '</button></form>',
          ], $receipts),
          'empty_icon'  => 'fa-receipt',
          'empty_title' => 'No payments recorded yet',
          'empty_text'  => 'Record the first payment on the right and the balance updates here.',
          'table_class' => 'mb-0',
      ]) ?>

      <?php if ($hasUnits || $serializedLines): ?>
      <div class="report-panel">
        <div class="report-panel-head">
          <h2 class="report-panel-title">Serialized devices on this invoice</h2>
          <p class="report-panel-sub">
            Attaching a unit links the physical device to this invoice line, marks it Sold and starts its warranty clock.
          </p>
        </div>
        <?php foreach ($serializedLines as $line):
            $units = $unitsByProduct[(int) $line['ProductID']] ?? [];
        ?>
        <form method="post" action="admin-invoices.php" class="mb-3" data-ajax>
          <?= csrf_field() ?>
          <input type="hidden" name="intent" value="assign_units">
          <input type="hidden" name="invoice_no" value="<?= (int) $invoice['InvoiceNo'] ?>">
          <input type="hidden" name="invoice_item_id" value="<?= (int) $line['InvoiceItemID'] ?>">
          <label class="form-label" for="units_<?= (int) $line['InvoiceItemID'] ?>">
            <?= htmlspecialchars($line['ProductName']) ?> — line #<?= (int) $line['InvoiceItemID'] ?>,
            <?= (int) $line['Quantity'] ?> unit(s) to assign
          </label>
          <?php if (!$units): ?>
          <div class="alert alert-warning mb-0">
            No free serialized units of this product are on hand. Record units in
            <a href="admin-stock.php?product=<?= (int) $line['ProductID'] ?>">Stock &amp; serialized units</a>,
            or receive the purchase order that delivered them.
          </div>
          <?php else: ?>
          <div class="d-flex flex-wrap gap-2 align-items-end">
            <select class="form-select form-select-sm" style="max-width:32rem" multiple size="<?= min(5, max(2, count($units))) ?>"
                    id="units_<?= (int) $line['InvoiceItemID'] ?>" name="equipment_ids[]">
              <?php foreach ($units as $unit): ?>
              <option value="<?= (int) $unit['EquipmentID'] ?>">
                <?= htmlspecialchars($unit['SerialNumber']) ?>
                — warranty to <?= format_date($unit['ClientWarrantyExpiry']) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-accent btn-sm" type="submit">
              <i class="fa-solid fa-link me-1"></i>Attach selected
            </button>
          </div>
          <small class="text-muted">Hold Ctrl / Cmd to select more than one unit.</small>
          <?php endif; ?>
        </form>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <div class="col-lg-4">
      <?php if ((float) $invoice['RemainingBalance'] > 0): ?>
      <div class="form-card">
        <div class="form-card-head">
          <h2 class="h5 mb-1">Record a payment</h2>
          <p class="text-muted small mb-0">
            Outstanding: <strong><?= money($invoice['RemainingBalance']) ?></strong>
            of <?= money($invoice['TotalAmount']) ?>.
          </p>
        </div>
        <form method="post" action="admin-invoices.php" novalidate data-ajax>
          <?= csrf_field() ?>
          <input type="hidden" name="intent" value="receipt">
          <input type="hidden" name="invoice_no" value="<?= (int) $invoice['InvoiceNo'] ?>">
          <div class="mb-3">
            <label class="form-label" for="amount_received">Amount received <span class="req">*</span></label>
            <input class="form-control" id="amount_received" name="amount_received" type="number" step="0.01" min="0.01"
                   max="<?= htmlspecialchars((string) $invoice['RemainingBalance']) ?>"
                   value="<?= htmlspecialchars((string) $invoice['RemainingBalance']) ?>" required>
          </div>
          <div class="mb-3">
            <?php
            $suggestedMethod = match ($invoice['OrderPaymentMethod'] ?? '') {
                'Cash on Delivery' => 'Cash',
                'bKash'            => 'bKash',
                'Nagad'            => 'Nagad',
                'Bank Transfer'    => 'Bank Transfer',
                default            => 'Cash',
            };
            ?>
            <label class="form-label" for="payment_method">Method</label>
            <select class="form-select" id="payment_method" name="payment_method">
              <?php foreach (payment_methods() as $method): ?>
              <option value="<?= htmlspecialchars($method) ?>" <?= $suggestedMethod === $method ? 'selected' : '' ?>><?= htmlspecialchars($method) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (!empty($invoice['OrderPaymentReference'])): ?>
            <div class="form-text text-primary small">
              <i class="fa-solid fa-circle-info me-1"></i>Customer reference: <strong><?= htmlspecialchars($invoice['OrderPaymentReference']) ?></strong>
            </div>
            <?php endif; ?>
          </div>
          <div class="mb-3">
            <label class="form-label" for="receipt_date">Receipt date</label>
            <input class="form-control" id="receipt_date" name="receipt_date" type="date" value="<?= date('Y-m-d') ?>">
          </div>
          <button class="btn btn-accent w-100" type="submit">
            <i class="fa-solid fa-money-bill-transfer me-1"></i>Record payment
          </button>
        </form>
      </div>
      <?php else: ?>
      <div class="alert alert-success">
        <i class="fa-solid fa-circle-check me-1"></i>
        Invoice #<?= (int) $invoice['InvoiceNo'] ?> is fully settled — <?= money($received) ?> received.
      </div>
      <?php endif; ?>

      <div class="report-panel">
        <div class="report-panel-head">
          <h2 class="report-panel-title">Actions</h2>
        </div>
        <div class="d-grid gap-2">
          <a class="btn btn-dark" href="invoice.php?no=<?= (int) $invoice['InvoiceNo'] ?>">
            <i class="fa-solid fa-print me-2"></i>Printable invoice
          </a>
          <?php if ($received <= 0 && !$hasUnits): ?>
          <a class="btn btn-outline-secondary" href="admin-invoices.php?action=edit&no=<?= (int) $invoice['InvoiceNo'] ?>">
            <i class="fa-solid fa-pen me-2"></i>Edit lines
          </a>
          <?php endif; ?>
          <?php if ($received > 0 || $hasUnits): ?>
          <span class="text-muted small">
            Lines are locked because
            <?= $received > 0 ? 'payments have been recorded' : 'serialized devices are attached' ?>.
          </span>
          <?php endif; ?>
          <a class="btn btn-outline-secondary" href="admin-invoices.php">
            <i class="fa-solid fa-arrow-left me-2"></i>All invoices
          </a>
        </div>
        <?php if ($received > 0): ?>
        <p class="text-muted small mb-0 mt-3">
          Lines are locked because payments have been recorded against this total.
          Deleting the invoice would also delete its receipts, so it is refused.
        </p>
        <?php endif; ?>
      </div>

      <div class="report-panel">
        <div class="report-panel-head">
          <h2 class="report-panel-title">Billed to</h2>
        </div>
        <?php if ($invoice['CompanyName'] !== null): ?>
        <?= admin_detail_row('Company', htmlspecialchars($invoice['CompanyName'])) ?>
        <?= admin_detail_row('Branch', htmlspecialchars((string) $invoice['BranchName'])) ?>
        <?= admin_detail_row('Address', htmlspecialchars((string) ($invoice['BranchAddress'] ?? $invoice['Address'] ?? '—'))) ?>
        <?= admin_detail_row('Contact', htmlspecialchars((string) ($invoice['ContactPerson'] ?? '—'))) ?>
        <?php else: ?>
        <?= admin_detail_row('Customer', htmlspecialchars((string) ($invoice['OrderCustomer'] ?? 'Walk-in customer'))) ?>
        <?= admin_detail_row('Login', htmlspecialchars((string) ($invoice['OrderUsername'] ?? '—'))) ?>
        <?= admin_detail_row('Email', htmlspecialchars((string) ($invoice['OrderEmail'] ?? '—'))) ?>
        <?= admin_detail_row('Ship to', htmlspecialchars((string) ($invoice['ShippingAddress'] ?? '—'))) ?>
        <?php endif; ?>
        <?= admin_detail_row('Invoice date', format_date($invoice['InvoiceDate'])) ?>
        <?= admin_detail_row('Source', $invoice['QuotationNo'] !== null
            ? '<a href="admin-quotations.php?action=view&id=' . (int) $invoice['QuotationNo'] . '">Quotation #' . (int) $invoice['QuotationNo'] . '</a>'
            : ($invoice['OrderID'] !== null
                ? '<a href="admin-orders.php?action=view&id=' . (int) $invoice['OrderID'] . '">Order #' . (int) $invoice['OrderID'] . '</a>'
                : 'raised directly')) ?>
      </div>
    </div>
  </div>

  <?php else:
      /* ------------------------------------------- create / edit form */
      $isEdit = $action === 'edit' && $invoice !== null;
      $oldLines = [];
      if ($errors && old_array('product_id')) {
          $oldProducts = old_array('product_id');
          $oldQtys     = old_array('quantity');
          $oldPrices   = old_array('unit_price');
          foreach ($oldProducts as $index => $productId) {
              $oldLines[] = [
                  'id'    => (int) $productId,
                  'qty'   => max(1, (int) ($oldQtys[$index] ?? 1)),
                  'price' => $oldPrices[$index] ?? '',
              ];
          }
      } elseif ($isEdit) {
          foreach ($invoiceLines as $line) {
              $oldLines[] = [
                  'id'    => (int) $line['ProductID'],
                  'qty'   => (int) $line['Quantity'],
                  'price' => (string) $line['SoldUnitPrice'],
              ];
          }
      } elseif ($fromQuotation) {
          foreach ($fromQuotation['lines'] as $line) {
              $oldLines[] = ['id' => (int) $line['ProductID'], 'qty' => (int) $line['Quantity'], 'price' => (string) $line['UnitPrice']];
          }
      } elseif ($fromOrder) {
          foreach ($fromOrder['lines'] as $line) {
              $oldLines[] = ['id' => (int) $line['ProductID'], 'qty' => (int) $line['Quantity'], 'price' => (string) $line['UnitPrice']];
          }
      }

      $formValues = [
          'InvoiceNo'   => $isEdit ? (int) $invoice['InvoiceNo'] : $nextFreeNo,
          'BranchID'    => $isEdit ? (int) ($invoice['BranchID'] ?? 0) : ($fromQuotation['BranchID'] ?? $fromOrder['BranchID'] ?? 0),
          'InvoiceDate' => $isEdit ? (string) $invoice['InvoiceDate'] : date('Y-m-d'),
          'QuotationNo' => $isEdit ? (int) ($invoice['QuotationNo'] ?? 0) : (int) ($fromQuotation['QuotationNo'] ?? 0),
          'OrderID'     => $isEdit ? (int) ($invoice['OrderID'] ?? 0) : (int) ($fromOrder['OrderID'] ?? 0),
      ];
      if ($errors) {
          $formValues['BranchID']    = post_int('branch_id');
          $formValues['InvoiceDate'] = post_str('invoice_date', $formValues['InvoiceDate']);
          $formValues['QuotationNo'] = post_int('quotation_no');
          $formValues['OrderID']     = post_int('order_id');
      }
  ?>
  <div class="row g-4">
    <div class="col-xl-9">
      <div class="form-card">
        <div class="form-card-head">
          <h2 class="h5 mb-1"><?= $isEdit ? 'Edit invoice #' . (int) $invoice['InvoiceNo'] : 'New invoice' ?></h2>
          <p class="text-muted small mb-0">
            <?php if ($fromQuotation): ?>
            Converting <strong>quotation #<?= (int) $fromQuotation['QuotationNo'] ?></strong>
            (<?= htmlspecialchars($fromQuotation['Subject']) ?>) — its lines are copied below and the invoice will be
            linked to it permanently.
            <?php elseif ($fromOrder): ?>
            Raising an invoice for <strong>order #<?= (int) $fromOrder['OrderID'] ?></strong>
            <?php if (!empty($fromOrder['PaymentMethod'])): ?>
            · <span class="badge bg-secondary"><?= htmlspecialchars($fromOrder['PaymentMethod']) ?><?php if (!empty($fromOrder['PaymentReference'])): ?>: <?= htmlspecialchars($fromOrder['PaymentReference']) ?><?php endif; ?></span>
            <?php endif; ?>
            placed by <?= htmlspecialchars($fromOrder['FullName']) ?> — the order lines are copied below.
            <?php else: ?>
            Billed to one customer branch, or to a retail customer when no branch applies.
            <?php endif; ?>
          </p>
        </div>

        <?php if (!$branches && !$orders): ?>
        <div class="alert alert-warning mb-0">
          There are no customer branches and no open orders, so there is nothing to invoice yet.
          <a href="admin-customers.php?action=new">Create a company with a branch</a> first.
        </div>
        <?php else: ?>
        <form method="post" action="admin-invoices.php" novalidate data-ajax>
          <?= csrf_field() ?>
          <input type="hidden" name="intent" value="<?= $isEdit ? 'update' : 'create' ?>">
          <input type="hidden" name="invoice_no" value="<?= (int) $formValues['InvoiceNo'] ?>">
          <input type="hidden" name="quotation_no" value="<?= (int) $formValues['QuotationNo'] ?>">
          <input type="hidden" name="order_id" value="<?= (int) $formValues['OrderID'] ?>">

          <h3 class="form-section-title">Billing</h3>
          <div class="row g-3">
            <div class="col-md-7">
              <label class="form-label" for="branch_id">Customer branch</label>
              <select class="form-select" id="branch_id" name="branch_id">
                <option value="0">— no branch (individual customer / retail order) —</option>
                <?php $lastCompany = null; foreach ($branches as $branch):
                    if ($lastCompany !== $branch['CompanyName']) {
                        if ($lastCompany !== null) echo '</optgroup>';
                        echo '<optgroup label="' . htmlspecialchars($branch['CompanyName']) . '">';
                        $lastCompany = $branch['CompanyName'];
                    }
                ?>
                <option value="<?= (int) $branch['BranchID'] ?>" <?= (int) $formValues['BranchID'] === (int) $branch['BranchID'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($branch['BranchName']) ?>
                </option>
                <?php endforeach; ?></optgroup>
              </select>
            </div>
            <div class="col-md-5">
              <label class="form-label" for="invoice_date">Invoice date <span class="req">*</span></label>
              <input class="form-control" id="invoice_date" name="invoice_date" type="date"
                     value="<?= htmlspecialchars($formValues['InvoiceDate']) ?>" required>
            </div>
          </div>

          <?php if ($fromQuotation || $fromOrder): ?>
          <div class="form-note mt-3">
            <i class="fa-solid fa-link me-2"></i>
            <?= $fromQuotation ? 'Linked to quotation #' . (int) $fromQuotation['QuotationNo'] : 'Linked to order #' . (int) $fromOrder['OrderID'] ?>
            — the schema allows one invoice per source, so this link is permanent.
          </div>
          <?php endif; ?>

          <h3 class="form-section-title">Lines</h3>
          <?= partial_render('partials/line-items.php', [
              'catalog'       => $catalog,
              'lines'         => $oldLines,
              'price_key'     => 'StandardPrice',
              'price_label'   => 'Sold unit price',
              'show_warranty' => false,
              'show_price'    => true,
              'blank_rows'    => 3,
          ]) ?>

          <div class="form-note mt-3">
            <i class="fa-solid fa-circle-info me-2"></i>
            A serialized product is invoiced by quantity here, then each physical device is attached to its line from
            the invoice page — that is what records the serial number and starts the warranty.
          </div>

          <div class="d-grid gap-2 d-sm-flex mt-3">
            <button class="btn btn-accent" type="submit"><?= $isEdit ? 'Save invoice' : 'Raise invoice' ?></button>
            <a class="btn btn-outline-secondary" href="admin-invoices.php">Cancel</a>
          </div>
        </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="col-xl-3">
      <?php if (!$isEdit && $orders): ?>
      <div class="report-panel">
        <div class="report-panel-head">
          <h2 class="report-panel-title">Orders without an invoice</h2>
          <p class="report-panel-sub">Raise one directly from the order.</p>
        </div>
        <ul class="list-unstyled small mb-0 dash-list">
          <?php foreach (array_slice($orders, 0, 6) as $order): ?>
          <li>
            <a class="text-decoration-none fw-semibold" href="admin-invoices.php?action=new&from_order=<?= (int) $order['OrderID'] ?>">
              Order #<?= (int) $order['OrderID'] ?> — <?= money($order['TotalAmount']) ?>
            </a>
            <small class="text-muted d-block">
              <?= htmlspecialchars($order['FullName']) ?> ·
              <?= format_date($order['OrderDate']) ?> ·
              <?= (int) $order['Items'] ?> line(s)
            </small>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>

      <div class="report-panel">
        <div class="report-panel-head">
          <h2 class="report-panel-title">What gets stored</h2>
        </div>
        <ul class="list-unstyled small mb-0 dash-list">
          <li><i class="fa-solid fa-arrow-right me-2 text-muted"></i><code>invoice</code> — branch, quotation or order, date, total, balance, status, words</li>
          <li><i class="fa-solid fa-arrow-right me-2 text-muted"></i><code>invoice_item</code> — product, optional serialized unit, quantity, sold price</li>
          <li><i class="fa-solid fa-arrow-right me-2 text-muted"></i><code>payment_receipt</code> — one row per payment, with the balance it left behind</li>
        </ul>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php endif; ?>
</div>

<script src="assets/form-items.js"></script>
<?= partial_render('partials/footer.php') ?>
