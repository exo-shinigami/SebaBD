<?php
/**
 * SebaBD — Purchase orders (back office).
 *
 * The buying side of the system. A purchase order is issued to one vendor
 * (#19) and carries many po_item lines (#20, #21), each naming the product and
 * the wholesale price agreed with that supplier.
 *
 *   admin-purchase-orders.php                       list + filters
 *   admin-purchase-orders.php?action=new            raise an order
 *   admin-purchase-orders.php?action=edit&id=       change it
 *   admin-purchase-orders.php?action=view&id=       order, lines, receiving
 *   POST intent=create|update|delete|receive
 *
 * Receiving is the bridge from procurement back into sellable stock:
 *   bulk product        the line quantity is added to product.StockQty
 *   serialized product  one product_instance row is created per serial number
 *                       entered, with its warranty clock started from the
 *                       purchase date — which is what makes the units sellable
 *                       and warrantiable later.
 *
 * The schema deliberately has no "received" flag on purchase_order, so the page
 * states plainly that each confirmation adds stock; the current stock position
 * is shown next to every line so a double entry is obvious.
 */
require_once __DIR__ . '/admin-bootstrap.php';

$user   = require_module('admin-purchase-orders.php');
$dbUp   = db() !== null;
$action = admin_action(['list', 'new', 'edit', 'view']);
$id     = max(0, (int) ($_GET['id'] ?? 0));
$errors = [];

$filterVendor = max(0, (int) ($_GET['vendor'] ?? 0));
$search       = trim((string) ($_GET['q'] ?? ''));

/* ------------------------------------------------------------- the writes */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard('admin-purchase-orders.php');

    $intent = post_str('intent');
    $pdo    = db();

    if (!$pdo) {
        $errors[] = 'The database is offline, so nothing can be saved right now.';
    } elseif ($intent === 'receive') {
        /* --------------------------------------------- receive stock */
        $poNo   = post_int('po_no');
        $itemId = post_int('po_item_id');
        $mode   = post_str('mode');
        $serials = post_str('serial_numbers');
        $receiveDate = post_str('receive_date', date('Y-m-d'));

        try {
            $line = db_fetch_all(
                'SELECT po.PONo, po.PODate, po.VendorID,
                        poi.POItemID, poi.ProductID, poi.Quantity, poi.ReceivedQty, poi.WholesaleUnitPrice,
                        p.ProductName, p.IsSerialized, p.StockQty, p.DefaultWarrantyMonths,
                        v.VendorName
                 FROM po_item poi
                 JOIN purchase_order po ON po.PONo = poi.PONo
                 JOIN product p ON p.ProductID = poi.ProductID
                 JOIN vendor  v ON v.VendorID  = po.VendorID
                 WHERE poi.POItemID = :id AND poi.PONo = :po',
                [':id' => $itemId, ':po' => $poNo]
            );

            $received    = (int) ($line[0]['ReceivedQty'] ?? 0);
            $outstanding = $line ? (int) $line[0]['Quantity'] - $received : 0;

            if (!$line) {
                $errors[] = 'That purchase-order line no longer exists.';
            } elseif (strtotime($receiveDate) === false) {
                $errors[] = 'The receiving date is not valid.';
            } elseif ($outstanding <= 0) {
                $errors[] = 'That line is already fully received (' . $received . ' of '
                          . (int) $line[0]['Quantity'] . '). Nothing is outstanding, so receiving it again '
                          . 'would invent stock.';
            } elseif ($mode === 'units') {
                $product = $line[0];
                if ((int) $product['IsSerialized'] !== 1) {
                    $errors[] = '“' . $product['ProductName'] . '” is not tracked by serial number, so it is received '
                              . 'as a bulk quantity instead.';
                } else {
                    $serials = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $serials))));
                    if (!$serials) {
                        $errors[] = 'Enter at least one serial number to receive, one per line.';
                    }
                    if (count($serials) > $outstanding) {
                        $errors[] = 'This line still has ' . $outstanding . ' unit(s) outstanding but '
                                  . count($serials) . ' serial numbers were entered.';
                    }
                    if (!array_filter($serials, fn($s) => strlen($s) <= 100)) {
                        $errors[] = 'Serial numbers are limited to 100 characters each.';
                    }

                    // SerialNumber carries a UNIQUE key, so a duplicate is a
                    // real conflict: report it with the unit that already owns it.
                    foreach ($serials as $serial) {
                        if (strlen($serial) > 100) {
                            $errors[] = 'Serial “' . mb_substr($serial, 0, 20) . '…” is longer than 100 characters.';
                            continue;
                        }
                        $dupe = db_fetch_all(
                            'SELECT EquipmentID FROM product_instance WHERE SerialNumber = :sn LIMIT 1',
                            [':sn' => $serial]
                        );
                        if ($dupe) {
                            $errors[] = 'Serial “' . $serial . '” is already recorded on unit #'
                                      . (int) $dupe[0]['EquipmentID'] . '.';
                        }
                    }
                    $duplicates = array_diff_assoc($serials, array_unique($serials));
                    if ($duplicates) {
                        $errors[] = 'The same serial number appears twice in the list: '
                                  . htmlspecialchars(implode(', ', array_unique($duplicates))) . '.';
                    }

                    if (!$errors) {
                        $months = (int) $product['DefaultWarrantyMonths'];
                        $vendorExpiry = date('Y-m-d', strtotime($receiveDate . ' +' . $months . ' months'));

                        $created = db_transaction($pdo, function (PDO $tx) use ($serials, $product, $receiveDate, $vendorExpiry, $received) {
                            $made = 0;
                            foreach ($serials as $serial) {
                                db_insert($tx, 'product_instance', [
                                    'ProductID'            => (int) $product['ProductID'],
                                    'SerialNumber'         => $serial,
                                    'PurchaseDate'         => $receiveDate,
                                    'VendorWarrantyExpiry' => $vendorExpiry,
                                    // Customer cover starts when the unit is
                                    // invoiced; until then it mirrors the
                                    // supplier cover so nothing is blank.
                                    'ClientWarrantyExpiry' => $vendorExpiry,
                                    'Status'               => 'In Stock',
                                ], 'EquipmentID');
                                $made++;
                            }

                            // Record how much of the line has now arrived, so the
                            // same serials cannot produce a second batch of stock.
                            db_update($tx, 'po_item',
                                ['ReceivedQty' => $received + $made],
                                ['POItemID' => (int) $product['POItemID']]);

                            return $made;
                        });

                        flash_set('success', $created . ' serialized unit(s) of “' . $product['ProductName']
                            . '” received from ' . $product['VendorName'] . ' on PO #' . $poNo
                            . '. They are now available on the storefront.');
                        post_success('admin-purchase-orders.php?action=view&id=' . $poNo);
                    }
                }
            } elseif ($mode === 'bulk') {
                $product = $line[0];
                if ((int) $product['IsSerialized'] === 1) {
                    $errors[] = '“' . $product['ProductName'] . '” is tracked by serial number, so its units must be '
                              . 'received one by one with their serial numbers.';
                } else {
                    // A bulk line can arrive in several deliveries. The form sends
                    // how much arrived this time; leaving it blank means the rest.
                    $qty = post_int('receive_qty', 0);
                    if ($qty <= 0) {
                        $qty = $outstanding;
                    }
                    if ($qty > $outstanding) {
                        $errors[] = 'This line still has ' . $outstanding . ' unit(s) outstanding, so '
                                  . $qty . ' cannot be received. Enter ' . $outstanding . ' or fewer.';
                    } elseif ($qty <= 0) {
                        $errors[] = 'Enter how many units arrived — it must be at least 1.';
                    } else {
                        $before = (int) $product['StockQty'];
                        $after  = $before + $qty;

                        db_transaction($pdo, function (PDO $tx) use ($product, $received, $qty) {
                            // Increment in SQL, not by writing back a number read
                            // before the transaction, so two receivers cannot
                            // overwrite each other's delivery.
                            adjust_product_stock($tx, (int) $product['ProductID'], $qty);
                            db_update($tx, 'po_item',
                                ['ReceivedQty' => $received + $qty],
                                ['POItemID' => (int) $product['POItemID']]);
                        });

                        flash_set('success', 'Received ' . $qty . ' × “' . $product['ProductName'] . '” from '
                            . $product['VendorName'] . ' — bulk stock went from ' . $before . ' to ' . $after . '.');
                        post_success('admin-purchase-orders.php?action=view&id=' . $poNo);
                    }
                }
            } else {
                $errors[] = 'Choose how this line is being received.';
            }
        } catch (Throwable $e) {
            $errors[] = admin_error_message($e, 'Could not receive that line.');
        }
        // A failed receive must re-render the order with its error list, so the
        // page has to load that order: $id drives the read further down, not
        // just $_GET['id'].
        $action = 'view';
        $id     = $poNo;
    } elseif ($intent === 'delete') {
        $poNo = post_int('po_no');
        try {
            $receivedRow = $poNo > 0
                ? db_fetch_all('SELECT COALESCE(SUM(ReceivedQty), 0) AS n FROM po_item WHERE PONo = :id', [':id' => $poNo])
                : [];
            $receivedSum = (int) ($receivedRow[0]['n'] ?? 0);

            if ($poNo <= 0) {
                $errors[] = 'That purchase order no longer exists.';
            } elseif ($receivedSum > 0) {
                // Deleting the order now would leave the stock it delivered
                // with no paperwork behind it.
                $errors[] = 'Purchase order #' . $poNo . ' has already delivered ' . $receivedSum
                          . ' unit(s) into stock, so it cannot be deleted. Keep it as the receiving record.';
            } else {
                // po_item.PONo is ON DELETE CASCADE, so the lines go with the
                // header; nothing else references a purchase order.
                db_delete($pdo, 'purchase_order', ['PONo' => $poNo]);
                flash_set('success', 'Purchase order #' . $poNo . ' and its lines were deleted.');
                post_success('admin-purchase-orders.php');
            }
        } catch (Throwable $e) {
            $errors[] = admin_error_message($e, 'Could not delete that purchase order.');
        }
        $action = 'list';
    } else {
        $editNo    = post_int('po_no');
        // The create form prefills po_no with the next free number, so a
        // positive value alone does not mean "edit" — the posted intent does.
        // Zeroing it on create keeps the "already received" lock and the
        // update branch below reading "new order", and the save picks the
        // real next number.
        if (post_str('intent') === 'create') {
            $editNo = 0;
        }
        // Lines that have delivered stock are history: rewriting the quantities
        // would contradict what is already on the shelf.
        if ($editNo > 0) {
            $receivedRow = db_fetch_all(
                'SELECT COALESCE(SUM(ReceivedQty), 0) AS n FROM po_item WHERE PONo = :id',
                [':id' => $editNo]
            );
            if ((int) ($receivedRow[0]['n'] ?? 0) > 0) {
                $errors[] = 'Purchase order #' . $editNo . ' has already received stock, so its lines are locked. '
                          . 'Raise a new purchase order for anything else you need.';
            }
        }
        $vendorId  = post_int('vendor_id');
        $poDate    = post_str('po_date', date('Y-m-d'));
        $terms     = post_str('terms');
        $productIds = post_rows('product_id');
        $quantities = post_rows('quantity');
        $prices     = post_rows('unit_price');

        if ($vendorId <= 0) {
            $errors[] = 'Choose the supplier this order is issued to.';
        }
        if (strtotime($poDate) === false) {
            $errors[] = 'The order date is not valid.';
        }

        $lines          = [];
        $catalogPrices  = [];
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
                    'SELECT p.ProductID, p.ProductName, p.StandardPrice, p.VendorID, v.VendorName
                     FROM product p JOIN vendor v ON v.VendorID = p.VendorID
                     WHERE p.ProductID = :id',
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
            $lines[] = ['product' => $product, 'qty' => min(99999, $quantity), 'price' => $price];
        }
        if (!$lines && !$errors) {
            $errors[] = 'Add at least one product line — an order with no lines has no value.';
        }

        if (!$errors) {
            $total = round(array_sum(array_map(fn($l) => $l['price'] * $l['qty'], $lines)), 2);

            try {
                $poNo = db_transaction($pdo, function (PDO $tx) use ($editNo, $vendorId, $poDate, $terms, $lines, $total) {
                    $header = [
                        'VendorID'    => $vendorId,
                        'PODate'      => $poDate,
                        'TotalAmount' => $total,
                        'Terms'       => $terms !== '' ? $terms : null,
                    ];

                    if ($editNo > 0) {
                        if (!db_fetch_all('SELECT PONo FROM purchase_order WHERE PONo = :id', [':id' => $editNo])) {
                            throw new RuntimeException('missing-po');
                        }
                        db_update($tx, 'purchase_order', $header, ['PONo' => $editNo]);
                        db_delete($tx, 'po_item', ['PONo' => $editNo]);
                        $poNo = $editNo;
                    } else {
                        $poNo = db_insert($tx, 'purchase_order', $header, 'PONo');
                    }

                    $itemId = next_id($tx, 'po_item', 'POItemID');
                    foreach ($lines as $line) {
                        db_insert($tx, 'po_item', [
                            'POItemID'           => $itemId++,
                            'PONo'               => $poNo,
                            'ProductID'          => (int) $line['product']['ProductID'],
                            'Quantity'           => $line['qty'],
                            'WholesaleUnitPrice' => $line['price'],
                            'TotalPrice'         => round($line['price'] * $line['qty'], 2),
                        ]);
                    }

                    return $poNo;
                });

                flash_set('success', ($editNo > 0 ? 'Purchase order #' . $poNo . ' updated' : 'Purchase order #' . $poNo . ' raised')
                    . ' with ' . $lines[0]['product']['VendorName'] . ' for ' . money($total) . '.');
                post_success('admin-purchase-orders.php?action=view&id=' . $poNo);
            } catch (RuntimeException $e) {
                deny_page(404, 'Purchase order not found', 'Purchase order #' . $editNo . ' does not exist.');
            } catch (Throwable $e) {
                $errors[] = admin_error_message($e, 'Could not save the purchase order.');
            }
        }

        $action = $editNo > 0 ? 'edit' : 'new';
        keep_old([
            'vendor_id'    => $vendorId,
            'po_date'      => $poDate,
            'terms'        => $terms,
            'product_id'   => $productIds,
            'quantity'     => $quantities,
            'unit_price'   => $prices,
        ]);
    }
}

/* -------------------------------------------------------------- the reads */

$rows        = [];
$order       = null;
$orderLines  = [];
$catalog     = [];
$vendors     = [];
$nextFreeNo  = 0;
$totals      = [];
$orderPage   = null;

if ($dbUp) {
    $catalog = db_fetch_all(
        'SELECT p.ProductID, p.ProductName, p.StandardPrice, p.DefaultWarrantyMonths, p.IsSerialized,
                p.StockQty, v.VendorName, c.CategoryName
         FROM product p
         JOIN category c ON c.CategoryID = p.CategoryID
         JOIN vendor   v ON v.VendorID   = p.VendorID
         ORDER BY c.CategoryName, p.ProductName'
    );

    $vendors = db_fetch_all('SELECT VendorID, VendorName, Location FROM vendor ORDER BY VendorName');

    if ($action === 'view' || $action === 'edit') {
        $found = db_fetch_all(
            'SELECT po.PONo, po.VendorID, po.PODate, po.TotalAmount, po.Terms,
                    v.VendorName, v.Location, v.ContactPhone,
                    (SELECT COUNT(*) FROM po_item poi WHERE poi.PONo = po.PONo) AS `Lines`,
                    (SELECT COALESCE(SUM(poi.Quantity), 0) FROM po_item poi WHERE poi.PONo = po.PONo) AS `Units`,
                    (SELECT COALESCE(SUM(poi.ReceivedQty), 0) FROM po_item poi WHERE poi.PONo = po.PONo) AS `Received`
             FROM purchase_order po
             JOIN vendor v ON v.VendorID = po.VendorID
             WHERE po.PONo = :id',
            [':id' => $id]
        );
        $order = $found[0] ?? null;
        if ($order === null) {
            deny_page(404, 'Purchase order not found', 'Purchase order #' . $id . ' does not exist.',
                'admin-purchase-orders.php', 'All purchase orders');
        }

        $orderLines = db_fetch_all(
            'SELECT poi.POItemID, poi.ProductID, poi.Quantity, poi.ReceivedQty,
                    poi.WholesaleUnitPrice, poi.TotalPrice,
                    p.ProductName, p.Brand, p.Model, p.IsSerialized, p.StockQty, p.StandardPrice,
                    p.DefaultWarrantyMonths
             FROM po_item poi
             JOIN product p ON p.ProductID = poi.ProductID
             WHERE poi.PONo = :id
             ORDER BY poi.POItemID',
            [':id' => $id]
        );

        if ($action === 'edit' && !empty(array_filter($orderLines, fn($l) => (int) $l['ReceivedQty'] > 0))) {
            flash_set('error', 'Purchase order #' . $id . ' has already received stock, so its lines are locked.');
            header('Location: admin-purchase-orders.php?action=view&id=' . $id);
            exit;
        }
    }

    if ($action === 'new') {
        $preview    = db_fetch_all('SELECT COALESCE(MAX(PONo), 0) + 1 AS n FROM purchase_order');
        $nextFreeNo = (int) ($preview[0]['n'] ?? 9001);
    }

    $where  = [];
    $params = [];
    if ($filterVendor > 0) {
        $where[]           = 'po.VendorID = :vendor';
        $params[':vendor'] = $filterVendor;
    }
    if ($search !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        $where[] = '(v.VendorName LIKE :q1 OR po.Terms LIKE :q2)';
        $params[':q1'] = $like;
        $params[':q2'] = $like;
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $from     = ' FROM purchase_order po JOIN vendor v ON v.VendorID = po.VendorID';

    $countRow  = db_fetch_all('SELECT COUNT(*) AS n' . $from . $whereSql, $params);
    $orderPage = paginate((int) ($countRow[0]['n'] ?? 0), 20);

    $rows = db_fetch_all(
        'SELECT po.PONo, po.PODate, po.TotalAmount, po.Terms,
                v.VendorID, v.VendorName, v.Location,
                (SELECT COUNT(*) FROM po_item poi WHERE poi.PONo = po.PONo) AS `Lines`,
                (SELECT COALESCE(SUM(poi.Quantity), 0) FROM po_item poi WHERE poi.PONo = po.PONo) AS `Units`,
                (SELECT COALESCE(SUM(poi.ReceivedQty), 0) FROM po_item poi WHERE poi.PONo = po.PONo) AS `Received`'
        . $from . $whereSql . '
         ORDER BY po.PODate DESC, po.PONo DESC
         LIMIT ' . $orderPage['perPage'] . ' OFFSET ' . $orderPage['offset'],
        $params
    );

    $totals = db_fetch_all(
        'SELECT COUNT(*) AS AllOrders,
                COALESCE(SUM(TotalAmount), 0) AS Committed,
                COUNT(DISTINCT VendorID) AS Vendors
         FROM purchase_order'
    );
    $totals = $totals[0] ?? [];
}

ajax_errors($errors);

$filterQs = http_build_query(array_filter([
    'vendor' => $filterVendor ?: null,
    'q'      => $search,
]));
$listUrl  = 'admin-purchase-orders.php' . ($filterQs !== '' ? '?' . $filterQs : '');

echo partial_render('partials/header.php', ['page_title' => 'Purchase orders', 'active_nav' => 'admin']);
echo admin_lead('Purchase orders', 'Your own procurement record: order stock from a supplier, print the order, then receive it into the inventory — as a bulk quantity or as individual serialized units. The vendor never confirms or ships in the system; receiving is recorded by your staff.');
?>

<div class="container pb-5">
  <?= render_flashes() ?>

  <?php if (!$dbUp): ?>
  <div class="alert alert-danger mb-0">
    <i class="fa-solid fa-plug-circle-xmark me-2"></i>
    The database is offline, so purchase orders cannot be listed. Start MySQL in the XAMPP control panel and reload.
  </div>
  <?php else: ?>

  <?= admin_tabs([
      'All purchase orders' => 'admin-purchase-orders.php',
      'New purchase order'  => 'admin-purchase-orders.php?action=new',
  ], $action === 'list' ? 'All purchase orders' : ($action === 'new' ? 'New purchase order' : '')) ?>

  <div class="ajax-errors" data-ajax-errors>
    <?php if ($errors): ?>
    <div class="alert alert-danger">
      <ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= htmlspecialchars($err) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($action === 'list'): ?>

  <div class="row g-3 mb-4">
    <?= report_kpi('Purchase orders', number_format((int) ($totals['AllOrders'] ?? 0)), 'Issued to suppliers so far', 'fa-truck-ramp-box') ?>
    <?= report_kpi('Committed', money((float) ($totals['Committed'] ?? 0)), 'Total value ordered from vendors', 'fa-sack-dollar') ?>
    <?= report_kpi('Suppliers used', number_format((int) ($totals['Vendors'] ?? 0)), 'of ' . count($vendors) . ' on file', 'fa-truck-field') ?>
    <?= report_kpi('Products to order', number_format(max(0, count(array_filter($catalog, fn($p) => ((int) $p['IsSerialized'] === 1 ? 0 : (int) $p['StockQty']) <= 5)))), 'Five units or fewer below the supplier line', 'fa-triangle-exclamation') ?>
  </div>

  <form class="report-filter mb-3" method="get" action="admin-purchase-orders.php">
    <div class="report-filter-fields">
      <label class="report-filter-field">
        <span class="form-label">Supplier or terms</span>
        <input class="form-control form-control-sm" type="search" name="q" value="<?= htmlspecialchars($search) ?>">
      </label>
      <label class="report-filter-field">
        <span class="form-label">Supplier</span>
        <select class="form-select form-select-sm" name="vendor">
          <option value="0">All suppliers</option>
          <?php foreach ($vendors as $vendor): ?>
          <option value="<?= (int) $vendor['VendorID'] ?>" <?= $filterVendor === (int) $vendor['VendorID'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($vendor['VendorName']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <div class="report-filter-actions">
      <button class="btn btn-accent btn-sm" type="submit"><i class="fa-solid fa-filter me-1"></i>Apply</button>
      <a class="btn btn-outline-secondary btn-sm" href="admin-purchase-orders.php">Reset</a>
    </div>
  </form>

  <?= partial_render('partials/admin-list.php', [
      'panel_title' => 'Purchase orders',
      'panel_sub'   => 'What has been ordered from each supplier, and what it committed the business to.',
      'columns'     => ['#', 'Date', 'Supplier', 'Lines', 'Units (received)', 'Value', 'Terms', ''],
      'rows'        => array_map(fn($row) => [
          '<a class="text-decoration-none" href="admin-purchase-orders.php?action=view&id=' . (int) $row['PONo'] . '">'
              . (int) $row['PONo'] . '</a>',
          format_date($row['PODate']),
          '<a class="text-decoration-none" href="admin-vendors.php?action=edit&id=' . (int) $row['VendorID'] . '">'
              . htmlspecialchars($row['VendorName']) . '</a>'
              . ($row['Location'] ? '<small class="d-block text-muted">' . htmlspecialchars($row['Location']) . '</small>' : ''),
          (int) $row['Lines'],
          number_format((int) $row['Units'])
              . '<small class="d-block ' . ((int) $row['Received'] >= (int) $row['Units'] ? 'text-success' : 'text-muted') . '">'
              . number_format((int) $row['Received']) . ' in</small>',
          '<span class="admin-line-total">' . money($row['TotalAmount']) . '</span>',
          htmlspecialchars((string) ($row['Terms'] ?? '—')),
          '<div class="admin-actions-cell">'
              . '<a class="btn btn-sm btn-outline-secondary" href="admin-purchase-orders.php?action=view&id=' . (int) $row['PONo'] . '" title="Open">'
                  . '<i class="fa-solid fa-eye"></i><span class="visually-hidden">Open</span></a> '
              . '<a class="btn btn-sm btn-outline-secondary" href="purchase-order.php?no=' . (int) $row['PONo'] . '" title="Printable">'
                  . '<i class="fa-solid fa-print"></i><span class="visually-hidden">Print</span></a> '
              . '<a class="btn btn-sm btn-outline-secondary" href="admin-purchase-orders.php?action=edit&id=' . (int) $row['PONo'] . '" title="Edit">'
                  . '<i class="fa-solid fa-pen"></i><span class="visually-hidden">Edit</span></a> '
              . '<form method="post" action="admin-purchase-orders.php" class="d-inline"'
                  . ' onsubmit="return confirm(\'Delete purchase order #' . (int) $row['PONo'] . '?\');">'
                  . csrf_field()
                  . '<input type="hidden" name="intent" value="delete">'
                  . '<input type="hidden" name="po_no" value="' . (int) $row['PONo'] . '">'
                  . '<button class="btn btn-sm btn-outline-danger" type="submit">'
                  . '<i class="fa-solid fa-trash-can"></i><span class="visually-hidden">Delete</span></button>'
              . '</form>'
              . '</div>',
      ], $rows),
      'empty_icon'   => 'fa-truck-ramp-box',
      'empty_title'  => $filterVendor > 0 || $search !== '' ? 'No purchase orders matched' : 'No purchase orders yet',
      'empty_text'   => $filterVendor > 0 || $search !== ''
          ? 'Clear the filters to see every order placed with suppliers.'
          : 'Raise the first order to bring stock in from one of your suppliers.',
      'empty_action' => $filterVendor > 0 || $search !== ''
          ? '<a class="btn btn-outline-secondary btn-sm" href="admin-purchase-orders.php">Clear filters</a>'
          : '<a class="btn btn-accent btn-sm" href="admin-purchase-orders.php?action=new"><i class="fa-solid fa-plus me-1"></i>New purchase order</a>',
      'actions'      => admin_actions([['href' => 'admin-purchase-orders.php?action=new', 'label' => 'New purchase order', 'icon' => 'fa-plus']]),
      'summary'      => pagination_summary($orderPage, 'purchase order'),
      'pagination'   => pagination_links($orderPage, $listUrl),
      'note'         => '<code>po_item</code> snapshots the <code>WholesaleUnitPrice</code> agreed at order time, so a later '
                      . 'catalog change never rewrites what was ordered.',
  ]) ?>

  <?php elseif ($action === 'view'): ?>
  <div class="row g-4">
    <div class="col-lg-8">
      <div class="report-panel">
        <div class="report-panel-head d-flex flex-wrap justify-content-between align-items-start gap-2">
          <div>
            <h2 class="report-panel-title">Purchase order #<?= (int) $order['PONo'] ?></h2>
            <p class="report-panel-sub">
              <?= htmlspecialchars($order['VendorName']) ?>
              <?= $order['Location'] ? ' · ' . htmlspecialchars($order['Location']) : '' ?>
              · <?= format_date($order['PODate']) ?>
            </p>
          </div>
          <span class="report-badge report-badge-info">
            <?= (int) $order['Lines'] ?> line<?= (int) $order['Lines'] === 1 ? '' : 's' ?> · <?= number_format((int) $order['Units']) ?> unit<?= (int) $order['Units'] === 1 ? '' : 's' ?>
          </span>
        </div>

        <div class="table-responsive">
          <table class="table table-sm report-th align-middle mb-0">
            <thead>
              <tr><th scope="col">#</th><th scope="col">Product</th><th scope="col" class="text-end">Qty</th>
                  <th scope="col" class="text-end">Wholesale</th><th scope="col" class="text-end">Line total</th>
                  <th scope="col" class="text-end">Now in stock</th></tr>
            </thead>
            <tbody>
              <?php foreach ($orderLines as $line):
                  $onHand = (int) $line['StockQty'];
              ?>
              <tr>
                <td class="text-muted"><?= (int) $line['POItemID'] ?></td>
                <td>
                  <?= htmlspecialchars($line['ProductName']) ?>
                  <small class="d-block text-muted">
                    product #<?= (int) $line['ProductID'] ?>
                    · <?= (int) $line['IsSerialized'] === 1 ? 'serialized' : 'bulk' ?>
                    · list <?= money($line['StandardPrice']) ?>
                  </small>
                </td>
                <td class="text-end">
                  <?= (int) $line['Quantity'] ?>
                  <small class="d-block text-muted">
                    <?= (int) $line['ReceivedQty'] ?> received
                  </small>
                </td>
                <td class="text-end admin-line-total"><?= money($line['WholesaleUnitPrice']) ?></td>
                <td class="text-end admin-line-total"><?= money($line['TotalPrice']) ?></td>
                <td class="text-end">
                  <?php if ((int) $line['IsSerialized'] === 1): ?>
                  <?php
                  $unitsInStock = db_fetch_all(
                      "SELECT COUNT(*) AS n FROM product_instance WHERE ProductID = :id AND Status = 'In Stock'",
                      [':id' => (int) $line['ProductID']]
                  );
                  $inStock = (int) ($unitsInStock[0]['n'] ?? 0);
                  ?>
                  <span class="report-badge <?= $inStock > 0 ? 'report-badge-ok' : 'report-badge-plain' ?>">
                    <?= $inStock ?> unit<?= $inStock === 1 ? '' : 's' ?>
                  </span>
                  <?php else: ?>
                  <span class="report-badge <?= $onHand <= 5 ? 'report-badge-warn' : 'report-badge-ok' ?>"><?= $onHand ?></span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr>
                <td colspan="4" class="text-end fw-bold">Order total</td>
                <td class="text-end fw-bold"><?= money($order['TotalAmount']) ?></td>
                <td></td>
              </tr>
            </tfoot>
          </table>
        </div>

        <?php if ($order['Terms']): ?>
        <p class="text-muted small mb-0 mt-3"><strong>Terms:</strong> <?= htmlspecialchars((string) $order['Terms']) ?></p>
        <?php endif; ?>
      </div>

      <div class="report-panel">
        <div class="report-panel-head">
          <h2 class="report-panel-title">Receive these lines into stock</h2>
          <p class="report-panel-sub">
            Each line remembers how much has arrived, so a delivery can be recorded in parts and the same
            units can never be received twice.
          </p>
        </div>

        <?php foreach ($orderLines as $line):
            $serialized  = (int) $line['IsSerialized'] === 1;
            $outstanding = max(0, (int) $line['Quantity'] - (int) $line['ReceivedQty']);
            $inStock = $serialized
                ? db_fetch_all("SELECT COUNT(*) AS n FROM product_instance WHERE ProductID = :id AND Status = 'In Stock'", [':id' => (int) $line['ProductID']])[0]['n'] ?? 0
                : (int) $line['StockQty'];
        ?>
        <div class="admin-detail-row">
          <span class="admin-detail-label">Line #<?= (int) $line['POItemID'] ?></span>
          <span class="admin-detail-value">
            <strong><?= htmlspecialchars($line['ProductName']) ?></strong>
            — ordered <?= (int) $line['Quantity'] ?>, received <?= (int) $line['ReceivedQty'] ?>
            <?= $outstanding > 0
                ? ', outstanding <strong>' . $outstanding . '</strong>'
                : ', <span class="report-badge report-badge-ok">fully received</span>' ?>
            · <?= (int) $inStock ?> in stock
            (<?= $serialized ? 'serialized units' : 'bulk quantity' ?>)
          </span>
        </div>
        <?php if ($outstanding > 0): ?>
        <form method="post" action="admin-purchase-orders.php" class="mb-4 mt-2" data-ajax>
          <?= csrf_field() ?>
          <input type="hidden" name="intent" value="receive">
          <input type="hidden" name="po_no" value="<?= (int) $order['PONo'] ?>">
          <input type="hidden" name="po_item_id" value="<?= (int) $line['POItemID'] ?>">
          <input type="hidden" name="mode" value="<?= $serialized ? 'units' : 'bulk' ?>">

          <?php if ($serialized): ?>
          <label class="form-label" for="serials_<?= (int) $line['POItemID'] ?>">
            Serial numbers received <span class="text-muted fw-normal">(one per line, up to <?= $outstanding ?> outstanding)</span>
          </label>
          <textarea class="form-control mb-2" id="serials_<?= (int) $line['POItemID'] ?>" name="serial_numbers" rows="3"
                    placeholder="SN-XXXX-0001&#10;SN-XXXX-0002"></textarea>
          <?php else: ?>
          <label class="form-label" for="receive_qty_<?= (int) $line['POItemID'] ?>">
            Units received this delivery <span class="text-muted fw-normal">(outstanding: <?= $outstanding ?>)</span>
          </label>
          <input class="form-control form-control-sm mb-2" style="max-width:10rem"
                 id="receive_qty_<?= (int) $line['POItemID'] ?>" name="receive_qty" type="number"
                 min="1" max="<?= $outstanding ?>" value="<?= $outstanding ?>" required>
          <?php endif; ?>

          <div class="d-flex flex-wrap gap-2 align-items-end">
            <div>
              <label class="form-label" for="receive_date_<?= (int) $line['POItemID'] ?>">Receiving date</label>
              <input class="form-control form-control-sm" id="receive_date_<?= (int) $line['POItemID'] ?>"
                     name="receive_date" type="date" value="<?= htmlspecialchars((string) $order['PODate']) ?>">
            </div>
            <button class="btn btn-accent btn-sm" type="submit">
              <i class="fa-solid fa-arrow-down-to-bracket me-1"></i>
              <?= $serialized ? 'Record units' : 'Receive into stock' ?>
            </button>
          </div>
        </form>
        <?php else: ?>
        <div class="mb-4 mt-2"><span class="text-muted small">Nothing outstanding on this line.</span></div>
        <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="report-panel">
        <div class="report-panel-head">
          <h2 class="report-panel-title">Actions</h2>
        </div>
        <div class="d-grid gap-2">
          <a class="btn btn-dark" href="purchase-order.php?no=<?= (int) $order['PONo'] ?>">
            <i class="fa-solid fa-print me-2"></i>Printable purchase order
          </a>
          <a class="btn btn-outline-secondary" href="admin-purchase-orders.php?action=edit&id=<?= (int) $order['PONo'] ?>">
            <i class="fa-solid fa-pen me-2"></i>Edit lines &amp; prices
          </a>
          <a class="btn btn-outline-secondary" href="admin-purchase-orders.php">
            <i class="fa-solid fa-arrow-left me-2"></i>All purchase orders
          </a>
        </div>
      </div>

      <div class="report-panel">
        <div class="report-panel-head">
          <h2 class="report-panel-title">Supplier</h2>
        </div>
        <?= admin_detail_row('Company', '<a href="admin-vendors.php?action=edit&id=' . (int) $order['VendorID'] . '">'
            . htmlspecialchars($order['VendorName']) . '</a>') ?>
        <?= admin_detail_row('Location', htmlspecialchars((string) ($order['Location'] ?? '—'))) ?>
        <?= admin_detail_row('Contact', htmlspecialchars((string) ($order['ContactPhone'] ?? '—'))) ?>
        <?= admin_detail_row('Order date', format_date($order['PODate'])) ?>
        <?= admin_detail_row('Terms', htmlspecialchars((string) ($order['Terms'] ?? '—'))) ?>
        <?= admin_detail_row('Value', money($order['TotalAmount'])) ?>
        <?= admin_detail_row('Products supplied', htmlspecialchars((string) db_count('product', 'VendorID = :v', [':v' => (int) $order['VendorID']]))) ?>
      </div>
    </div>
  </div>

  <?php else:
      $isEdit  = $action === 'edit' && $order !== null;
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
          foreach ($orderLines as $line) {
              $oldLines[] = [
                  'id'    => (int) $line['ProductID'],
                  'qty'   => (int) $line['Quantity'],
                  'price' => (string) $line['WholesaleUnitPrice'],
              ];
          }
      }

      $formValues = [
          'PONo'     => $isEdit ? (int) $order['PONo'] : $nextFreeNo,
          'VendorID' => $isEdit ? (int) $order['VendorID'] : max(0, (int) ($_GET['vendor'] ?? 0)),
          'PODate'   => $isEdit ? (string) $order['PODate'] : date('Y-m-d'),
          'Terms'    => $isEdit ? (string) ($order['Terms'] ?? '') : 'Net 30',
      ];
      if ($errors) {
          $formValues['VendorID'] = post_int('vendor_id');
          $formValues['PODate']   = post_str('po_date', $formValues['PODate']);
          $formValues['Terms']    = post_str('terms');
      }
  ?>
  <div class="row g-4">
    <div class="col-xl-9">
      <div class="form-card">
        <div class="form-card-head">
          <h2 class="h5 mb-1"><?= $isEdit ? 'Edit purchase order #' . (int) $order['PONo'] : 'New purchase order' ?></h2>
          <p class="text-muted small mb-0">
            The wholesale price defaults to the catalog list price — override it with what the supplier actually quoted.
          </p>
        </div>

        <?php if (!$vendors || !$catalog): ?>
        <div class="alert alert-warning mb-0">
          You need at least one supplier and one product before an order can be raised.
          <a href="admin-vendors.php?action=new">Add a supplier</a> or
          <a href="admin-products.php?action=new">add a product</a> first.
        </div>
        <?php else: ?>
        <form method="post" action="admin-purchase-orders.php" novalidate data-ajax>
          <?= csrf_field() ?>
          <input type="hidden" name="intent" value="<?= $isEdit ? 'update' : 'create' ?>">
          <input type="hidden" name="po_no" value="<?= (int) $formValues['PONo'] ?>">

          <h3 class="form-section-title">Supplier &amp; terms</h3>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="vendor_id">Supplier <span class="req">*</span></label>
              <select class="form-select" id="vendor_id" name="vendor_id" required>
                <option value="">— select a supplier —</option>
                <?php foreach ($vendors as $vendor): ?>
                <option value="<?= (int) $vendor['VendorID'] ?>" <?= (int) $formValues['VendorID'] === (int) $vendor['VendorID'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($vendor['VendorName']) ?><?= $vendor['Location'] ? ' — ' . htmlspecialchars($vendor['Location']) : '' ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label" for="po_date">Order date <span class="req">*</span></label>
              <input class="form-control" id="po_date" name="po_date" type="date"
                     value="<?= htmlspecialchars($formValues['PODate']) ?>" required>
            </div>
            <div class="col-md-3">
              <label class="form-label" for="terms">Terms</label>
              <input class="form-control" id="terms" name="terms" maxlength="255"
                     value="<?= htmlspecialchars($formValues['Terms']) ?>" placeholder="Net 30">
            </div>
          </div>

          <h3 class="form-section-title">Ordered lines</h3>
          <?= partial_render('partials/line-items.php', [
              'catalog'       => $catalog,
              'lines'         => $oldLines,
              'price_key'     => 'StandardPrice',
              'price_label'   => 'Wholesale price',
              'qty_label'     => 'Order qty',
              'show_warranty' => false,
              'show_price'    => true,
              'blank_rows'    => 3,
          ]) ?>

          <div class="d-grid gap-2 d-sm-flex mt-3">
            <button class="btn btn-accent" type="submit"><?= $isEdit ? 'Save purchase order' : 'Raise purchase order' ?></button>
            <a class="btn btn-outline-secondary" href="admin-purchase-orders.php">Cancel</a>
          </div>
        </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="col-xl-3">
      <div class="report-panel">
        <div class="report-panel-head">
          <h2 class="report-panel-title">What gets stored</h2>
        </div>
        <ul class="list-unstyled small mb-0 dash-list">
          <li><i class="fa-solid fa-arrow-right me-2 text-muted"></i><code>purchase_order</code> — supplier, date, total, terms</li>
          <li><i class="fa-solid fa-arrow-right me-2 text-muted"></i><code>po_item</code> — product, quantity, wholesale price, line total</li>
          <li><i class="fa-solid fa-arrow-right me-2 text-muted"></i>Receiving creates <code>product_instance</code> rows for serialized products and raises <code>StockQty</code> for bulk ones.</li>
        </ul>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php endif; ?>
</div>

<script src="assets/form-items.js"></script>
<?= partial_render('partials/footer.php') ?>
