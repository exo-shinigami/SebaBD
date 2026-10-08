<?php
/**
 * SebaBD — Stock & serialized units (back office).
 *
 * The schema tracks stock two different ways, and this page owns both:
 *
 *   bulk         product.StockQty           a count for products sold in boxes
 *   serialized   product_instance rows      one row per physical device, whose
 *                                           serial number, warranty dates and
 *                                           the invoice it was sold on matter
 *                                           individually (#9, #17)
 *
 *   admin-stock.php                      both tables, side by side
 *   admin-stock.php?product=<id>         focus the serialized list on one product
 *   admin-stock.php?status=<status>      filter the serialized list by status
 *   POST intent=set_stock|unit_create|unit_update|unit_delete
 */
require_once __DIR__ . '/admin-bootstrap.php';

$user   = require_module('admin-stock.php');
$dbUp   = db() !== null;
$errors = [];

$focusProduct = max(0, (int) ($_GET['product'] ?? 0));
$focusStatus  = trim((string) ($_GET['status'] ?? ''));
$view         = ($_GET['view'] ?? '') === 'bulk' ? 'bulk' : 'units';

/** The statuses a unit can hold, with the badge colour each one prints in. */
function unit_statuses(): array
{
    return ['In Stock', 'Reserved', 'Sold', 'Returned', 'Damaged'];
}

/* ------------------------------------------------------------- the writes */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard('admin-stock.php');

    $intent = post_str('intent');
    $pdo    = db();

    if (!$pdo) {
        $errors[] = 'The database is offline, so stock cannot be changed right now.';
    } elseif ($intent === 'set_stock') {
        $productId = post_int('product_id');
        $quantity  = post_int('stock_qty', -1);
        try {
            $product = db_fetch_all(
                'SELECT ProductID, ProductName, IsSerialized FROM product WHERE ProductID = :id',
                [':id' => $productId]
            );
            if (!$product) {
                $errors[] = 'That product no longer exists.';
            } elseif ((int) $product[0]['IsSerialized'] === 1) {
                $errors[] = '“' . $product[0]['ProductName'] . '” is tracked by serial number — '
                          . 'add or remove individual units below instead of setting a bulk quantity.';
            } elseif ($quantity < 0 || $quantity > 1000000) {
                $errors[] = 'Enter a quantity between 0 and 1,000,000.';
            } else {
                db_update($pdo, 'product', ['StockQty' => $quantity], ['ProductID' => $productId]);
                flash_set('success', '“' . $product[0]['ProductName'] . '” bulk stock set to ' . $quantity . ' units.');
                post_success('admin-stock.php?view=bulk');
            }
        } catch (Throwable $e) {
            $errors[] = admin_error_message($e, 'Could not update the stock quantity.');
        }
        $view = 'bulk';
    } elseif ($intent === 'unit_create' || $intent === 'unit_update') {
        $unitId     = post_int('equipment_id');
        $productId  = post_int('product_id');
        $serial     = post_str('serial_number');
        $purchase   = post_str('purchase_date');
        $vendorEnd  = post_str('vendor_warranty_expiry');
        $clientEnd  = post_str('client_warranty_expiry');
        $status     = post_str('status', 'In Stock');

        if ($productId <= 0) {
            $errors[] = 'Choose the product this unit belongs to.';
        }
        if ($serial === '') {
            $errors[] = 'Every serialized unit needs its serial number.';
        } elseif (mb_strlen($serial) > 100) {
            $errors[] = 'Serial numbers are limited to 100 characters.';
        }
        if (!in_array($status, unit_statuses(), true)) {
            $errors[] = 'Choose one of the listed unit statuses.';
        }
        foreach (['Purchase date' => $purchase, 'Supplier warranty expiry' => $vendorEnd, 'Customer warranty expiry' => $clientEnd] as $label => $value) {
            if ($value !== '' && strtotime($value) === false) {
                $errors[] = $label . ' is not a valid date.';
            }
        }

        // The warranty dates are derived from the product's default when the
        // person leaves them blank: vendor cover and customer cover both run
        // for DefaultWarrantyMonths from the purchase date, which is what the
        // sample data does too.
        if (!$errors && ($vendorEnd === '' || $clientEnd === '')) {
            $product = db_fetch_all(
                'SELECT ProductName, DefaultWarrantyMonths, IsSerialized FROM product WHERE ProductID = :id',
                [':id' => $productId]
            );
            if (!$product) {
                $errors[] = 'That product no longer exists.';
            } elseif ((int) $product[0]['IsSerialized'] === 0) {
                $errors[] = '“' . $product[0]['ProductName'] . '” is not serialized, so it cannot own individual units. '
                          . 'Turn on “Track every unit by serial number” on the product first.';
            } else {
                $months = (int) $product[0]['DefaultWarrantyMonths'];
                $base   = $purchase !== '' ? $purchase : date('Y-m-d');
                if ($vendorEnd === '') {
                    $vendorEnd = date('Y-m-d', strtotime($base . ' +' . $months . ' months'));
                }
                if ($clientEnd === '') {
                    $clientEnd = date('Y-m-d', strtotime($base . ' +' . $months . ' months'));
                }
            }
        }

        // SerialNumber carries a UNIQUE key in the schema; catch the clash here
        // so the message names the problem instead of showing a SQL error.
        if (!$errors) {
            $dupe = db_fetch_all(
                'SELECT EquipmentID FROM product_instance WHERE SerialNumber = :sn AND EquipmentID <> :id LIMIT 1',
                [':sn' => $serial, ':id' => $unitId]
            );
            if ($dupe) {
                $errors[] = 'Serial number “' . $serial . '” is already recorded on unit #' . (int) $dupe[0]['EquipmentID'] . '.';
            }
        }

        if (!$errors) {
            $data = [
                'ProductID'            => $productId,
                'SerialNumber'         => $serial,
                'PurchaseDate'         => $purchase !== '' ? $purchase : null,
                'VendorWarrantyExpiry' => $vendorEnd !== '' ? $vendorEnd : null,
                'ClientWarrantyExpiry' => $clientEnd !== '' ? $clientEnd : null,
                'Status'               => $status,
            ];
            try {
                if ($intent === 'unit_update') {
                    $existing = db_fetch_all(
                        'SELECT EquipmentID FROM product_instance WHERE EquipmentID = :id',
                        [':id' => $unitId]
                    );
                    if (!$existing) {
                        deny_page(404, 'Unit not found', 'Serialized unit #' . $unitId . ' does not exist.');
                    }
                    db_update($pdo, 'product_instance', $data, ['EquipmentID' => $unitId]);
                    flash_set('success', 'Unit #' . $unitId . ' (' . $serial . ') updated.');
                } else {
                    $newId = db_transaction($pdo, fn(PDO $tx) => db_insert($tx, 'product_instance', $data, 'EquipmentID'));
                    flash_set('success', 'Unit #' . $newId . ' recorded with serial ' . $serial . '.');
                }
                post_success('admin-stock.php?product=' . $productId . '&status=' . urlencode($status));
            } catch (Throwable $e) {
                $errors[] = admin_error_message($e, 'Could not save that unit.');
            }
        }
        $view          = 'units';
        $focusProduct  = $productId;
    } elseif ($intent === 'unit_delete') {
        $unitId = post_int('equipment_id');
        try {
            $unit = db_fetch_all(
                'SELECT EquipmentID, ProductID, SerialNumber, Status FROM product_instance WHERE EquipmentID = :id',
                [':id' => $unitId]
            );
            if (!$unit) {
                $errors[] = 'That unit no longer exists.';
            } else {
                // invoice_item.EquipmentID is ON DELETE SET NULL, which would
                // quietly unlink a sold device from the invoice that recorded
                // it. Refuse instead, and say what to do about it.
                $soldOn = db_count('invoice_item', 'EquipmentID = :id', [':id' => $unitId]);
                if ($soldOn > 0) {
                    $errors[] = 'Unit #' . $unitId . ' (' . $unit[0]['SerialNumber'] . ') is recorded on '
                              . $soldOn . ' invoice line' . ($soldOn === 1 ? '' : 's') . '. Deleting it would unlink the '
                              . 'device from that invoice, so it is refused — mark the unit "Sold", "Returned" '
                              . 'or "Damaged" instead.';
                } else {
                    db_delete($pdo, 'product_instance', ['EquipmentID' => $unitId]);
                    flash_set('success', 'Unit #' . $unitId . ' (' . $unit[0]['SerialNumber'] . ') removed.');
                    post_success('admin-stock.php?product=' . (int) $unit[0]['ProductID']);
                }
            }
        } catch (Throwable $e) {
            $errors[] = admin_error_message($e, 'Could not delete that unit.');
        }
        $view = 'units';
    }
}

/* -------------------------------------------------------------- the reads */

$products      = [];
$bulkRows      = [];
$units         = [];
$editUnit      = null;
$nextUnitId    = 0;
$summary       = [];
$unitPage      = null;

if ($dbUp) {
    $products = db_fetch_all(
        'SELECT ProductID, ProductName, Brand, IsSerialized, StockQty, DefaultWarrantyMonths
         FROM product ORDER BY ProductName'
    );

    // ---- bulk table
    $bulkRows = db_fetch_all(
        "SELECT p.ProductID, p.ProductName, p.StockQty, p.StandardPrice, c.CategoryName,
                (SELECT COALESCE(SUM(oi.Quantity), 0) FROM order_item oi WHERE oi.ProductID = p.ProductID) AS Ordered
         FROM product p
         JOIN category c ON c.CategoryID = p.CategoryID
         WHERE p.IsSerialized = 0
         ORDER BY p.StockQty ASC, p.ProductName"
    );

    // ---- serialized units, filtered
    $where  = [];
    $params = [];
    if ($focusProduct > 0) {
        $where[]            = 'pi.ProductID = :pid';
        $params[':pid']     = $focusProduct;
    }
    if ($focusStatus !== '' && in_array($focusStatus, unit_statuses(), true)) {
        $where[]            = 'pi.Status = :status';
        $params[':status']  = $focusStatus;
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $unitTotal  = db_fetch_all('SELECT COUNT(*) AS n FROM product_instance pi' . $whereSql, $params);
    $unitPage   = paginate((int) ($unitTotal[0]['n'] ?? 0), 20);

    $units = db_fetch_all(
        'SELECT pi.EquipmentID, pi.ProductID, pi.SerialNumber, pi.PurchaseDate,
                pi.VendorWarrantyExpiry, pi.ClientWarrantyExpiry, pi.Status,
                p.ProductName, p.Brand,
                ii.InvoiceNo
         FROM product_instance pi
         JOIN product p ON p.ProductID = pi.ProductID
         LEFT JOIN invoice_item ii ON ii.EquipmentID = pi.EquipmentID' . $whereSql . '
         ORDER BY pi.Status, pi.EquipmentID DESC
         LIMIT ' . $unitPage['perPage'] . ' OFFSET ' . $unitPage['offset'],
        $params
    );

    if (($actionId = max(0, (int) ($_GET['edit'] ?? 0))) > 0) {
        $found = db_fetch_all(
            'SELECT EquipmentID, ProductID, SerialNumber, PurchaseDate, VendorWarrantyExpiry,
                    ClientWarrantyExpiry, Status
             FROM product_instance WHERE EquipmentID = :id',
            [':id' => $actionId]
        );
        $editUnit = $found[0] ?? null;
        if ($editUnit === null) {
            deny_page(404, 'Unit not found', 'Serialized unit #' . $actionId . ' does not exist.', 'admin-stock.php', 'Stock');
        }
        $focusProduct = (int) $editUnit['ProductID'];
    }

    $preview    = db_fetch_all('SELECT COALESCE(MAX(EquipmentID), 0) + 1 AS n FROM product_instance');
    $nextUnitId = (int) ($preview[0]['n'] ?? 1);

    $summary = db_fetch_all(
        "SELECT (SELECT COALESCE(SUM(StockQty), 0) FROM product WHERE IsSerialized = 0) AS BulkUnits,
                (SELECT COUNT(*) FROM product_instance WHERE Status = 'In Stock') AS UnitsInStock,
                (SELECT COUNT(*) FROM product_instance WHERE Status = 'Sold') AS UnitsSold,
                (SELECT COUNT(*) FROM product_instance) AS UnitsTotal,
                (SELECT COALESCE(SUM(StockQty), 0) FROM product WHERE IsSerialized = 0 AND StockQty <= 5) AS LowBulk,
                (SELECT COUNT(*) FROM product WHERE IsSerialized = 1) AS SerializedProducts"
    );
    $summary = $summary[0] ?? [];
}

ajax_errors($errors);

$serializedProducts = array_values(array_filter($products, fn($p) => (int) $p['IsSerialized'] === 1));
$filterQs = http_build_query(array_filter([
    'product' => $focusProduct ?: null,
    'status'  => $focusStatus !== '' ? $focusStatus : null,
    'view'    => $view,
]));

echo partial_render('partials/header.php', ['page_title' => 'Stock & serialized units', 'active_nav' => 'admin']);
echo admin_lead(
    'Stock &amp; serialized units',
    'Bulk quantities for products sold in boxes, and one row per device for products tracked by serial number. '
    . 'Receiving a purchase order tops these up automatically.'
);
?>

<div class="container pb-5">
  <?= render_flashes() ?>

  <?php if (!$dbUp): ?>
  <div class="alert alert-danger mb-0">
    <i class="fa-solid fa-plug-circle-xmark me-2"></i>
    The database is offline, so stock cannot be listed. Start MySQL in the XAMPP control panel and reload.
  </div>
  <?php else: ?>

  <div class="row g-3 mb-4">
    <?= report_kpi('Bulk units on hand', number_format((int) ($summary['BulkUnits'] ?? 0)), 'Across ' . count($bulkRows) . ' quantity-tracked products', 'fa-boxes-stacked') ?>
    <?= report_kpi('Serialized units', number_format((int) ($summary['UnitsInStock'] ?? 0)), 'In stock of ' . number_format((int) ($summary['UnitsTotal'] ?? 0)) . ' recorded', 'fa-barcode') ?>
    <?= report_kpi('Units sold', number_format((int) ($summary['UnitsSold'] ?? 0)), 'Marked Sold on a device record', 'fa-truck-fast') ?>
    <?= report_kpi('Lines to watch', number_format((int) ($summary['LowBulk'] ?? 0)), 'Bulk quantity of five or fewer', 'fa-triangle-exclamation') ?>
  </div>

  <div class="ajax-errors" data-ajax-errors>
    <?php if ($errors): ?>
    <div class="alert alert-danger">
      <?php /* Messages are plain text; only the wording carries emphasis. */ ?>
      <ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= htmlspecialchars($err) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>
  </div>

  <?= admin_tabs([
      'Serialized units' => 'admin-stock.php?view=units' . ($focusProduct ? '&product=' . $focusProduct : ''),
      'Bulk quantities'  => 'admin-stock.php?view=bulk',
  ], $view === 'bulk' ? 'Bulk quantities' : 'Serialized units') ?>

  <?php if ($view === 'bulk'): ?>

    <?= partial_render('partials/admin-list.php', [
        'panel_title' => 'Bulk quantities',
        'panel_sub'   => 'Products sold by quantity rather than by device. Set the number on hand and save the row.',
        'panel_note'  => null,
        'columns'     => ['#', 'Product', 'Category', 'Ordered so far', 'On hand', 'Set quantity', ''],
        'rows'        => array_map(fn($row) => [
            '<span class="text-muted">' . (int) $row['ProductID'] . '</span>',
            '<a class="fw-semibold text-decoration-none" href="admin-products.php?action=edit&id=' . (int) $row['ProductID'] . '">'
                . htmlspecialchars($row['ProductName']) . '</a>',
            htmlspecialchars($row['CategoryName']),
            number_format((int) $row['Ordered']) . ' units',
            (int) $row['StockQty'] <= 0
                ? '<span class="report-badge report-badge-danger">out of stock</span>'
                : ((int) $row['StockQty'] <= 5
                    ? '<span class="report-badge report-badge-warn">only ' . (int) $row['StockQty'] . ' left</span>'
                    : '<span class="report-badge report-badge-ok">' . (int) $row['StockQty'] . ' on hand</span>'),
            '<form method="post" action="admin-stock.php" class="d-flex gap-2 align-items-center">'
                . csrf_field()
                . '<input type="hidden" name="intent" value="set_stock">'
                . '<input type="hidden" name="product_id" value="' . (int) $row['ProductID'] . '">'
                . '<input class="form-control form-control-sm" style="width:7rem" type="number" min="0" max="1000000"'
                    . ' name="stock_qty" value="' . (int) $row['StockQty'] . '" aria-label="Quantity on hand">'
                . '<button class="btn btn-sm btn-accent" type="submit">Save</button>'
                . '</form>',
            '<a class="btn btn-sm btn-outline-secondary" href="product.php?id=' . (int) $row['ProductID'] . '">'
                . '<i class="fa-solid fa-eye"></i><span class="visually-hidden">View</span></a>',
        ], $bulkRows),
        'empty_icon'   => 'fa-boxes-stacked',
        'empty_title'  => 'No quantity-tracked products',
        'empty_text'   => 'Every product in the catalog is currently tracked by serial number, so stock lives in the device list instead.',
        'empty_action' => '<a class="btn btn-accent btn-sm" href="admin-products.php?action=new"><i class="fa-solid fa-plus me-1"></i>New product</a>',
        'note'         => 'Bulk stock is a single number on <code>product.StockQty</code>. Serialized products ignore it and '
                        . 'count their <code>product_instance</code> rows marked <em>In Stock</em> instead.',
    ]) ?>

  <?php else: ?>

  <form class="report-filter mb-3" method="get" action="admin-stock.php">
    <input type="hidden" name="view" value="units">
    <div class="report-filter-fields">
      <label class="report-filter-field">
        <span class="form-label">Product</span>
        <select class="form-select form-select-sm" name="product">
          <option value="0">All serialized products</option>
          <?php foreach ($serializedProducts as $product): ?>
          <option value="<?= (int) $product['ProductID'] ?>" <?= $focusProduct === (int) $product['ProductID'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($product['ProductName']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="report-filter-field">
        <span class="form-label">Status</span>
        <select class="form-select form-select-sm" name="status">
          <option value="">Any status</option>
          <?php foreach (unit_statuses() as $status): ?>
          <option value="<?= htmlspecialchars($status) ?>" <?= $focusStatus === $status ? 'selected' : '' ?>>
            <?= htmlspecialchars($status) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <div class="report-filter-actions">
      <button class="btn btn-accent btn-sm" type="submit"><i class="fa-solid fa-filter me-1"></i>Apply</button>
      <a class="btn btn-outline-secondary btn-sm" href="admin-stock.php">Reset</a>
    </div>
  </form>

  <div class="row g-4">
    <div class="col-xl-7">
      <?= partial_render('partials/admin-list.php', [
          'panel_title' => 'Serialized units',
          'panel_sub'   => 'One row per physical device, with the serial number and both warranty dates.',
          'columns'     => ['#', 'Serial number', 'Product', 'Purchased', 'Warranty ends', 'Status', ''],
          'rows'        => array_map(function ($unit) {
              $clientDays = days_from_today($unit['ClientWarrantyExpiry']);
              $warranty   = format_date($unit['ClientWarrantyExpiry']);
              if ($clientDays !== null && $clientDays >= 0 && $unit['ClientWarrantyExpiry']) {
                  $warranty .= '<small class="d-block text-muted">' . $clientDays . ' days left</small>';
              } elseif ($clientDays !== null && $unit['ClientWarrantyExpiry']) {
                  $warranty .= '<small class="d-block text-muted">expired</small>';
              }

              return [
                  '<span class="text-muted">' . (int) $unit['EquipmentID'] . '</span>',
                  '<code>' . htmlspecialchars($unit['SerialNumber']) . '</code>'
                      . ($unit['InvoiceNo'] !== null
                          ? '<small class="d-block text-muted">on <a href="invoice.php?no=' . (int) $unit['InvoiceNo'] . '">invoice #' . (int) $unit['InvoiceNo'] . '</a></small>'
                          : ''),
                  '<a class="text-decoration-none" href="admin-products.php?action=edit&id=' . (int) $unit['ProductID'] . '">'
                      . htmlspecialchars($unit['ProductName']) . '</a>'
                      . '<small class="d-block text-muted">' . htmlspecialchars((string) ($unit['Brand'] ?? '')) . '</small>',
                  format_date($unit['PurchaseDate']),
                  $warranty,
                  status_badge((string) $unit['Status']),
                  '<div class="admin-actions-cell">'
                      . '<a class="btn btn-sm btn-outline-secondary" href="admin-stock.php?view=units&edit=' . (int) $unit['EquipmentID'] . '">'
                          . '<i class="fa-solid fa-pen"></i><span class="visually-hidden">Edit</span></a> '
                      . '<form method="post" action="admin-stock.php" class="d-inline"'
                          . ' onsubmit="return confirm(\'Remove unit ' . htmlspecialchars($unit['SerialNumber'], ENT_QUOTES) . '?\');">'
                          . csrf_field()
                          . '<input type="hidden" name="intent" value="unit_delete">'
                          . '<input type="hidden" name="equipment_id" value="' . (int) $unit['EquipmentID'] . '">'
                          . '<button class="btn btn-sm btn-outline-danger" type="submit">'
                          . '<i class="fa-solid fa-trash-can"></i><span class="visually-hidden">Remove</span></button>'
                      . '</form>'
                      . '</div>',
              ];
          }, $units),
          'empty_icon'   => 'fa-barcode',
          'empty_title'  => $focusProduct > 0 || $focusStatus !== ''
              ? 'No units matched those filters'
              : 'No serialized units recorded',
          'empty_text'   => 'A serialized product is only sellable through its individual device records. Add the first unit on the right.',
          'empty_action' => ($focusProduct > 0 || $focusStatus !== '')
              ? '<a class="btn btn-outline-secondary btn-sm" href="admin-stock.php">Clear filters</a>'
              : '',
          'summary'      => pagination_summary($unitPage, 'unit'),
          'pagination'   => pagination_links($unitPage, 'admin-stock.php?' . $filterQs),
          'note'         => 'A unit that appears on an invoice cannot be deleted — the record is the warranty trail for that device.',
      ]) ?>
    </div>

    <div class="col-xl-5">
      <?php
      $isEditUnit = $editUnit !== null;
      $unitValues = $isEditUnit ? [
          'ProductID'            => (int) $editUnit['ProductID'],
          'SerialNumber'         => (string) $editUnit['SerialNumber'],
          'PurchaseDate'         => (string) ($editUnit['PurchaseDate'] ?? ''),
          'VendorWarrantyExpiry' => (string) ($editUnit['VendorWarrantyExpiry'] ?? ''),
          'ClientWarrantyExpiry' => (string) ($editUnit['ClientWarrantyExpiry'] ?? ''),
          'Status'               => (string) $editUnit['Status'],
      ] : [
          'ProductID'            => $focusProduct,
          'SerialNumber'         => '',
          'PurchaseDate'         => date('Y-m-d'),
          'VendorWarrantyExpiry' => '',
          'ClientWarrantyExpiry' => '',
          'Status'               => 'In Stock',
      ];
      ?>
      <div class="form-card">
        <div class="form-card-head">
          <h2 class="h5 mb-1"><?= $isEditUnit ? 'Edit unit #' . (int) $editUnit['EquipmentID'] : 'Record a serialized unit' ?></h2>
          <p class="text-muted small mb-0">
            <?= $isEditUnit
                ? 'Change the serial number, warranty dates or status of this device.'
                : 'Leave the warranty dates blank to derive them from the product’s default warranty length.' ?>
          </p>
        </div>

        <?php if (!$serializedProducts): ?>
        <div class="alert alert-light border mb-0">
          No product is tracked by serial number yet. Open a product in
          <a href="admin-products.php">Products</a>, switch on
          “Track every unit by serial number” and save — then units can be recorded here.
        </div>
        <?php else: ?>
        <form method="post" action="admin-stock.php" novalidate data-ajax>
          <?= csrf_field() ?>
          <input type="hidden" name="intent" value="<?= $isEditUnit ? 'unit_update' : 'unit_create' ?>">
          <input type="hidden" name="equipment_id" value="<?= $isEditUnit ? (int) $editUnit['EquipmentID'] : $nextUnitId ?>">

          <div class="mb-3">
            <label class="form-label" for="unit_product">Product <span class="req">*</span></label>
            <select class="form-select" id="unit_product" name="product_id" required>
              <option value="">— select a serialized product —</option>
              <?php foreach ($serializedProducts as $product): ?>
              <option value="<?= (int) $product['ProductID'] ?>" <?= (int) $unitValues['ProductID'] === (int) $product['ProductID'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($product['ProductName']) ?>
                <?= $product['Brand'] ? '(' . htmlspecialchars($product['Brand']) . ')' : '' ?>
                — <?= (int) $product['DefaultWarrantyMonths'] ?> mo warranty
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label" for="serial_number">Serial number <span class="req">*</span></label>
            <input class="form-control" id="serial_number" name="serial_number" maxlength="100"
                   value="<?= htmlspecialchars($unitValues['SerialNumber']) ?>" required>
            <small class="text-muted">Unique across the whole system — it is how a device is identified years later.</small>
          </div>

          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label" for="purchase_date">Purchased</label>
              <input class="form-control" id="purchase_date" name="purchase_date" type="date"
                     value="<?= htmlspecialchars($unitValues['PurchaseDate']) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label" for="vendor_warranty_expiry">Supplier warranty</label>
              <input class="form-control" id="vendor_warranty_expiry" name="vendor_warranty_expiry" type="date"
                     value="<?= htmlspecialchars($unitValues['VendorWarrantyExpiry']) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label" for="client_warranty_expiry">Customer warranty</label>
              <input class="form-control" id="client_warranty_expiry" name="client_warranty_expiry" type="date"
                     value="<?= htmlspecialchars($unitValues['ClientWarrantyExpiry']) ?>">
            </div>
          </div>

          <div class="mb-3 mt-3">
            <label class="form-label" for="unit_status">Status</label>
            <select class="form-select" id="unit_status" name="status">
              <?php foreach (unit_statuses() as $status): ?>
              <option value="<?= htmlspecialchars($status) ?>" <?= $unitValues['Status'] === $status ? 'selected' : '' ?>>
                <?= htmlspecialchars($status) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="d-grid gap-2 d-sm-flex">
            <button class="btn btn-accent" type="submit"><?= $isEditUnit ? 'Save unit' : 'Record unit' ?></button>
            <?php if ($isEditUnit): ?>
            <a class="btn btn-outline-secondary" href="admin-stock.php?view=units&product=<?= (int) $editUnit['ProductID'] ?>">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
        <?php endif; ?>
      </div>

      <div class="report-panel">
        <div class="report-panel-head">
          <h2 class="report-panel-title">How stock is counted</h2>
        </div>
        <ul class="list-unstyled small mb-0 dash-list">
          <li><i class="fa-solid fa-arrow-right me-2 text-muted"></i><strong>Bulk</strong> — one number in <code>product.StockQty</code>.</li>
          <li><i class="fa-solid fa-arrow-right me-2 text-muted"></i><strong>Serialized</strong> — one <code>product_instance</code> row per device; the storefront counts the rows marked <em>In Stock</em>.</li>
          <li><i class="fa-solid fa-arrow-right me-2 text-muted"></i>A device sold on an invoice keeps its <code>EquipmentID</code> on the <code>invoice_item</code> line, which is what makes warranty claims traceable.</li>
        </ul>
      </div>
    </div>
  </div>

  <?php endif; ?>
  <?php endif; ?>
</div>

<?= partial_render('partials/footer.php') ?>
