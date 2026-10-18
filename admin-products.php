<?php
/**
 * SebaBD — Products (back office).
 *
 *   admin-products.php                        list, with search + filters
 *   admin-products.php?q=&category=&vendor=   filtered list
 *   admin-products.php?action=new             create form
 *   admin-products.php?action=edit&id=        edit form
 *   POST intent=create|update|delete          the writes
 *
 * A product is the hub of the catalog: it is classified by one category (#7),
 * supplied by one vendor (#8), can appear on many order/quotation/invoice/PO
 * lines (#6, #12, #21) and owns many product_instance rows (#9). So the form
 * edits the product's own attributes while the *stock* of a serialized product
 * is managed in admin-stock.php, where each unit is a row of its own.
 */
require_once __DIR__ . '/admin-bootstrap.php';

$user   = require_module('admin-products.php');
$dbUp   = db() !== null;
$action = (string) ($_GET['action'] ?? 'list');
$id     = max(0, (int) ($_GET['id'] ?? 0));
$errors = [];

// Filters survive pagination and form submits.
$filterQuery    = trim((string) ($_GET['q'] ?? ''));
$filterCategory = max(0, (int) ($_GET['category'] ?? 0));
$filterVendor   = max(0, (int) ($_GET['vendor'] ?? 0));

$form = [
    'ProductID' => 0, 'ProductName' => '', 'Brand' => '', 'Model' => '',
    'CategoryID' => 0, 'VendorID' => 0, 'StandardPrice' => '',
    'IsSerialized' => 0, 'StockQty' => 0, 'DefaultWarrantyMonths' => 12,
];

/* ------------------------------------------------------------- the writes */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard('admin-products.php');

    $intent = post_str('intent');
    $pdo    = db();

    if (!$pdo) {
        $errors[] = 'The database is offline, so nothing can be saved right now.';
    } elseif ($intent === 'delete') {
        $deleteId = post_int('product_id');
        try {
            if ($deleteId <= 0) {
                $errors[] = 'That product no longer exists.';
            } else {
                // Everything that can point at a product, checked before the
                // delete so the message names what is in the way.
                $links = [
                    'order line'      => db_count('order_item', 'ProductID = :id', [':id' => $deleteId]),
                    'quotation line'  => db_count('quotation_item', 'ProductID = :id', [':id' => $deleteId]),
                    'invoice line'    => db_count('invoice_item', 'ProductID = :id', [':id' => $deleteId]),
                    'purchase order line' => db_count('po_item', 'ProductID = :id', [':id' => $deleteId]),
                    'serialized unit' => db_count('product_instance', 'ProductID = :id', [':id' => $deleteId]),
                ];
                $blockers = [];
                foreach ($links as $label => $count) {
                    if ($count > 0) {
                        $blockers[] = $count . ' ' . $label . ($count === 1 ? '' : 's');
                    }
                }

                if ($blockers) {
                    $errors[] = 'That product is still used by ' . implode(', ', $blockers)
                              . ', so it cannot be deleted. Retire it by setting its price to 0, or remove those records first.';
                } else {
                    db_delete($pdo, 'product', ['ProductID' => $deleteId]);
                    flash_set('success', 'Product #' . $deleteId . ' deleted.');
                    post_success('admin-products.php');
                }
            }
        } catch (Throwable $e) {
            $errors[] = admin_error_message($e, 'Could not delete that product.');
        }
        $action = 'list';
    } else {
        $editId     = post_int('product_id');
        $name       = post_str('product_name');
        $brand      = post_str('brand');
        $model      = post_str('model');
        $categoryId = post_int('category_id');
        $vendorId   = post_int('vendor_id');
        $priceRaw   = post_str('standard_price');
        $serialized = post_str('is_serialized') === '1' ? 1 : 0;
        $stockQty   = post_int('stock_qty');
        $warranty   = post_int('warranty_months');

        $form = [
            'ProductID' => $editId, 'ProductName' => $name, 'Brand' => $brand, 'Model' => $model,
            'CategoryID' => $categoryId, 'VendorID' => $vendorId, 'StandardPrice' => $priceRaw,
            'IsSerialized' => $serialized, 'StockQty' => $stockQty, 'DefaultWarrantyMonths' => $warranty,
        ];

        // ---- validation
        if (mb_strlen($name) < 2) {
            $errors[] = 'Please enter the product name (at least 2 characters).';
        }
        if ($categoryId <= 0) {
            $errors[] = 'Choose the category this product belongs to.';
        }
        if ($vendorId <= 0) {
            $errors[] = 'Choose the supplier this product comes from.';
        }
        if (!is_numeric($priceRaw) || (float) $priceRaw < 0) {
            $errors[] = 'Enter the list price as a number of 0 or more.';
        }
        if ($warranty < 0 || $warranty > 240) {
            $errors[] = 'Warranty must be between 0 and 240 months.';
        }
        if ($stockQty < 0 || $stockQty > 1000000) {
            $errors[] = 'Bulk stock quantity must be between 0 and 1,000,000.';
        }
        if (!$errors) {
            $known = db_fetch_all(
                'SELECT (SELECT COUNT(*) FROM category WHERE CategoryID = :cid) AS C,
                        (SELECT COUNT(*) FROM vendor   WHERE VendorID   = :vid) AS V',
                [':cid' => $categoryId, ':vid' => $vendorId]
            );
            if ((int) ($known[0]['C'] ?? 0) === 0) {
                $errors[] = 'That category no longer exists.';
            }
            if ((int) ($known[0]['V'] ?? 0) === 0) {
                $errors[] = 'That supplier no longer exists.';
            }
        }

        if (!$errors) {
            $data = [
                'ProductName'            => $name,
                'Brand'                  => $brand !== '' ? $brand : null,
                'Model'                  => $model !== '' ? $model : null,
                'CategoryID'             => $categoryId,
                'VendorID'               => $vendorId,
                'StandardPrice'          => (float) $priceRaw,
                'IsSerialized'           => $serialized,
                // A serialized product counts its stock from product_instance
                // rows, so the bulk column stays at zero and cannot drift.
                'StockQty'               => $serialized ? 0 : $stockQty,
                'DefaultWarrantyMonths'  => $warranty,
            ];

            try {
                if ($editId > 0) {
                    if (!db_fetch_all('SELECT ProductID FROM product WHERE ProductID = :id', [':id' => $editId])) {
                        deny_page(404, 'Product not found', 'Product #' . $editId . ' does not exist.');
                    }
                    db_update($pdo, 'product', $data, ['ProductID' => $editId]);
                    flash_set('success', 'Product “' . $name . '” updated.');
                } else {
                    $newId = db_transaction($pdo, fn(PDO $tx) => db_insert($tx, 'product', $data, 'ProductID'));
                    flash_set(
                        'success',
                        'Product “' . $name . '” created as #' . $newId . '.'
                        . ($serialized ? ' Add its serialized units in Stock & serialized units.' : '')
                    );
                }
                post_success('admin-products.php');
            } catch (Throwable $e) {
                $errors[] = admin_error_message($e);
            }
        }

        $action = $editId > 0 ? 'edit' : 'new';
        $id     = $editId;
    }
}

/* -------------------------------------------------------------- the reads */

$categories  = $dbUp ? db_fetch_all('SELECT CategoryID, CategoryName FROM category ORDER BY CategoryName') : [];
$vendors     = $dbUp ? db_fetch_all('SELECT VendorID, VendorName FROM vendor ORDER BY VendorName') : [];
$rows        = [];
$edit        = null;
$nextFreeId  = 0;
$pagination  = null;

if ($dbUp) {
    // ---- list query: filters are bound, never interpolated
    $where  = [];
    $params = [];

    if ($filterQuery !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filterQuery) . '%';
        $where[] = '(p.ProductName LIKE :q1 OR p.Brand LIKE :q2 OR p.Model LIKE :q3)';
        $params[':q1'] = $like;
        $params[':q2'] = $like;
        $params[':q3'] = $like;
    }
    if ($filterCategory > 0) {
        $where[]             = 'p.CategoryID = :cid';
        $params[':cid']      = $filterCategory;
    }
    if ($filterVendor > 0) {
        $where[]             = 'p.VendorID = :vid';
        $params[':vid']      = $filterVendor;
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $totalRows  = db_fetch_all('SELECT COUNT(*) AS n FROM product p' . $whereSql, $params);
    $pagination = paginate((int) ($totalRows[0]['n'] ?? 0), 15);
    $limitSql   = ' LIMIT ' . $pagination['perPage'] . ' OFFSET ' . $pagination['offset'];

    $rows = db_fetch_all(
        'SELECT p.ProductID, p.ProductName, p.Brand, p.Model, p.StandardPrice, p.IsSerialized,
                p.StockQty, p.DefaultWarrantyMonths,
                c.CategoryName, v.VendorName,
                (SELECT COUNT(*) FROM product_instance pi WHERE pi.ProductID = p.ProductID) AS Units,
                (SELECT COUNT(*) FROM product_instance pi
                  WHERE pi.ProductID = p.ProductID AND pi.Status = \'In Stock\') AS UnitsInStock
         FROM product p
         JOIN category c ON c.CategoryID = p.CategoryID
         JOIN vendor   v ON v.VendorID   = p.VendorID' . $whereSql . '
         ORDER BY p.ProductID' . $limitSql,
        $params
    );

    if ($action === 'edit' && $id > 0) {
        $found = db_fetch_all(
            'SELECT ProductID, ProductName, Brand, Model, CategoryID, VendorID, StandardPrice,
                    IsSerialized, StockQty, DefaultWarrantyMonths
             FROM product WHERE ProductID = :id',
            [':id' => $id]
        );
        $edit = $found[0] ?? null;
        if ($edit === null) {
            deny_page(404, 'Product not found', 'Product #' . $id . ' does not exist.', 'admin-products.php', 'All products');
        }
    }

    if ($action === 'new') {
        $preview = db_fetch_all('SELECT COALESCE(MAX(ProductID), 0) + 1 AS n FROM product');
        $nextFreeId = (int) ($preview[0]['n'] ?? 1);
    }
}

ajax_errors($errors);

// Filters carried through list links and into the form's cancel link.
$filterQs = http_build_query(array_filter([
    'q'        => $filterQuery,
    'category' => $filterCategory ?: null,
    'vendor'   => $filterVendor ?: null,
]));
$listUrl  = 'admin-products.php' . ($filterQs !== '' ? '?' . $filterQs : '');

$pageTitle = $action === 'list' ? 'Products' : ($action === 'edit' ? 'Edit product' : 'New product');
echo partial_render('partials/header.php', ['page_title' => $pageTitle, 'active_nav' => 'admin']);
echo admin_lead('Products', 'The sellable catalog. Each product has exactly one category and one supplier, and can appear on any number of order, quotation, invoice and purchase-order lines.');
?>

<div class="container pb-5">
  <?= render_flashes() ?>

  <?php if (!$dbUp): ?>
  <div class="alert alert-danger mb-0">
    <i class="fa-solid fa-plug-circle-xmark me-2"></i>
    The database is offline, so products cannot be listed. Start MySQL in the XAMPP control panel and reload.
  </div>
  <?php else: ?>

  <?= admin_tabs(
      ['All products' => 'admin-products.php', 'New product' => 'admin-products.php?action=new'],
      $action === 'list' ? 'All products' : ($action === 'new' ? 'New product' : '')
  ) ?>

  <div class="ajax-errors" data-ajax-errors>
    <?php if ($errors): ?>
    <div class="alert alert-danger">
      <ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= htmlspecialchars($err) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($action === 'list'): ?>

  <form class="report-filter mb-3" method="get" action="admin-products.php">
    <div class="report-filter-fields">
      <label class="report-filter-field">
        <span class="form-label">Product</span>
        <input class="form-control form-control-sm" type="search" name="q" value="<?= htmlspecialchars($filterQuery) ?>"
               placeholder="Name, brand or model">
      </label>
      <label class="report-filter-field">
        <span class="form-label">Category</span>
        <select class="form-select form-select-sm" name="category">
          <option value="0">All categories</option>
          <?php foreach ($categories as $category): ?>
          <option value="<?= (int) $category['CategoryID'] ?>" <?= $filterCategory === (int) $category['CategoryID'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($category['CategoryName']) ?>
          </option>
          <?php endforeach; ?>
        </select>
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
      <a class="btn btn-outline-secondary btn-sm" href="admin-products.php">Reset</a>
    </div>
  </form>

  <?= partial_render('partials/admin-list.php', [
      'panel_title' => 'Catalog',
      'panel_sub'   => 'Serialized products count their units individually; everything else counts its bulk quantity.',
      'columns'     => ['#', 'Product', 'Category', 'Supplier', 'List price', 'Warranty', 'Stock', ''],
      'rows'        => array_map(function ($row) {
          $serialized = ((int) $row['IsSerialized']) === 1;
          $onHand     = $serialized ? (int) $row['UnitsInStock'] : (int) $row['StockQty'];

          return [
              '<span class="text-muted">' . (int) $row['ProductID'] . '</span>',
              '<a class="fw-semibold text-decoration-none" href="admin-products.php?action=edit&id=' . (int) $row['ProductID'] . '">'
                  . htmlspecialchars($row['ProductName']) . '</a>'
                  . '<small class="d-block text-muted">'
                  . htmlspecialchars(trim(($row['Brand'] ?? '') . ($row['Model'] ? ' · ' . $row['Model'] : '')) ?: '—')
                  . '</small>',
              htmlspecialchars($row['CategoryName']),
              htmlspecialchars($row['VendorName']),
              '<span class="admin-line-total">' . money($row['StandardPrice']) . '</span>',
              (int) $row['DefaultWarrantyMonths'] . ' mo',
              $serialized
                  ? '<span class="report-badge report-badge-info">serialized</span>'
                      . '<small class="d-block text-muted">' . $onHand . ' of ' . (int) $row['Units'] . ' in stock</small>'
                  : ($onHand <= 0
                      ? '<span class="report-badge report-badge-danger">out of stock</span>'
                      : '<span class="report-badge ' . ($onHand <= 5 ? 'report-badge-warn' : 'report-badge-ok') . '">'
                          . $onHand . ' on hand</span>'),
              '<div class="admin-actions-cell">'
                  . '<a class="btn btn-sm btn-outline-secondary" href="product.php?id=' . (int) $row['ProductID'] . '" title="View on the storefront">'
                      . '<i class="fa-solid fa-eye"></i><span class="visually-hidden">View</span></a> '
                  . '<a class="btn btn-sm btn-outline-secondary" href="admin-stock.php?product=' . (int) $row['ProductID'] . '" title="Stock">'
                      . '<i class="fa-solid fa-warehouse"></i><span class="visually-hidden">Stock</span></a> '
                  . '<a class="btn btn-sm btn-outline-secondary" href="admin-products.php?action=edit&id=' . (int) $row['ProductID'] . '">'
                      . '<i class="fa-solid fa-pen"></i><span class="visually-hidden">Edit</span></a> '
                  . '<form method="post" action="admin-products.php" class="d-inline"'
                      . ' onsubmit="return confirm(\'Delete product “' . htmlspecialchars($row['ProductName'], ENT_QUOTES) . '”?\');">'
                      . csrf_field()
                      . '<input type="hidden" name="intent" value="delete">'
                      . '<input type="hidden" name="product_id" value="' . (int) $row['ProductID'] . '">'
                      . '<button class="btn btn-sm btn-outline-danger" type="submit">'
                      . '<i class="fa-solid fa-trash-can"></i><span class="visually-hidden">Delete</span></button>'
                  . '</form>'
                  . '</div>',
          ];
      }, $rows),
      'empty_icon'   => 'fa-box',
      'empty_title'  => $filterQuery !== '' || $filterCategory > 0 || $filterVendor > 0
          ? 'No products matched those filters'
          : 'No products yet',
      'empty_text'   => $filterQuery !== '' || $filterCategory > 0 || $filterVendor > 0
          ? 'Try a different name, or clear the filters to see the whole catalog.'
          : 'Add the first product and it appears on the storefront immediately.',
      'empty_action' => ($filterQuery !== '' || $filterCategory > 0 || $filterVendor > 0)
          ? '<a class="btn btn-outline-secondary btn-sm" href="admin-products.php">Clear filters</a>'
          : '<a class="btn btn-accent btn-sm" href="admin-products.php?action=new"><i class="fa-solid fa-plus me-1"></i>New product</a>',
      'actions'      => admin_actions([
          ['href' => 'admin-products.php?action=new', 'label' => 'New product', 'icon' => 'fa-plus'],
      ]),
      'summary'      => pagination_summary($pagination, 'product'),
      'pagination'   => pagination_links($pagination, $listUrl),
      'note'         => 'Deleting is refused while any order, quotation, invoice, purchase order or serialized unit still refers to the product.',
  ]) ?>

  <?php else:
      $isEdit = $action === 'edit' && $edit !== null;
      $values = $isEdit ? [
          'ProductName'           => (string) $edit['ProductName'],
          'Brand'                 => (string) ($edit['Brand'] ?? ''),
          'Model'                 => (string) ($edit['Model'] ?? ''),
          'CategoryID'            => (int) $edit['CategoryID'],
          'VendorID'              => (int) $edit['VendorID'],
          'StandardPrice'         => (string) $edit['StandardPrice'],
          'IsSerialized'          => (int) $edit['IsSerialized'],
          'StockQty'              => (int) $edit['StockQty'],
          'DefaultWarrantyMonths' => (int) $edit['DefaultWarrantyMonths'],
      ] : $form;
      $shownId = $isEdit ? (int) $edit['ProductID'] : $nextFreeId;
  ?>

  <div class="row g-4">
    <div class="col-lg-8">
      <div class="form-card">
        <div class="form-card-head">
          <h2 class="h5 mb-1"><?= $isEdit ? 'Edit product #' . (int) $edit['ProductID'] : 'New product' ?></h2>
          <p class="text-muted small mb-0">
            <?= $isEdit
                ? 'Changes to the name or price affect quotations and orders raised after this save, never the ones already on file.'
                : 'The storefront shows the product as soon as it is saved.' ?>
          </p>
        </div>

        <form method="post" action="admin-products.php" novalidate data-ajax>
          <?= csrf_field() ?>
          <input type="hidden" name="intent" value="<?= $isEdit ? 'update' : 'create' ?>">
          <input type="hidden" name="product_id" value="<?= $shownId ?>">

          <h3 class="form-section-title">Identification</h3>
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label" for="product_name">Product name <span class="req">*</span></label>
              <input class="form-control" id="product_name" name="product_name" maxlength="255"
                     value="<?= htmlspecialchars($values['ProductName']) ?>" required autofocus>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="brand">Brand</label>
              <input class="form-control" id="brand" name="brand" maxlength="100" value="<?= htmlspecialchars($values['Brand']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="model">Model</label>
              <input class="form-control" id="model" name="model" maxlength="100" value="<?= htmlspecialchars($values['Model']) ?>">
            </div>
          </div>

          <h3 class="form-section-title">Classification</h3>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="category_id">Category <span class="req">*</span></label>
              <select class="form-select" id="category_id" name="category_id" required>
                <option value="">— select a category —</option>
                <?php foreach ($categories as $category): ?>
                <option value="<?= (int) $category['CategoryID'] ?>" <?= (int) $values['CategoryID'] === (int) $category['CategoryID'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($category['CategoryName']) ?>
                </option>
                <?php endforeach; ?>
              </select>
              <small class="text-muted"><a href="admin-categories.php?action=new">Add a category</a> if it is missing.</small>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="vendor_id">Supplier <span class="req">*</span></label>
              <select class="form-select" id="vendor_id" name="vendor_id" required>
                <option value="">— select a supplier —</option>
                <?php foreach ($vendors as $vendor): ?>
                <option value="<?= (int) $vendor['VendorID'] ?>" <?= (int) $values['VendorID'] === (int) $vendor['VendorID'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($vendor['VendorName']) ?>
                </option>
                <?php endforeach; ?>
              </select>
              <small class="text-muted"><a href="admin-vendors.php?action=new">Add a supplier</a> if it is missing.</small>
            </div>
          </div>

          <h3 class="form-section-title">Pricing &amp; warranty</h3>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="standard_price">List price (<span><?= htmlspecialchars(currency_symbol()) ?></span>) <span class="req">*</span></label>
              <input class="form-control" id="standard_price" name="standard_price" type="number" step="0.01" min="0"
                     value="<?= htmlspecialchars($values['StandardPrice']) ?>" required>
              <small class="text-muted">The price the storefront quotes; order and invoice lines copy it at document time.</small>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="warranty_months">Default warranty (months)</label>
              <input class="form-control" id="warranty_months" name="warranty_months" type="number" min="0" max="240"
                     value="<?= (int) $values['DefaultWarrantyMonths'] ?>">
            </div>
          </div>

          <h3 class="form-section-title">Stock tracking</h3>
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" id="is_serialized" name="is_serialized" value="1"
                   <?= (int) $values['IsSerialized'] === 1 ? 'checked' : '' ?>>
            <label class="form-check-label" for="is_serialized">
              Track every unit by serial number (<code>product_instance</code>)
            </label>
          </div>

          <div class="mb-3" id="bulk-stock-group">
            <label class="form-label" for="stock_qty">Bulk quantity on hand</label>
            <input class="form-control" id="stock_qty" name="stock_qty" type="number" min="0" max="1000000"
                   value="<?= (int) $values['StockQty'] ?>">
            <small class="text-muted">
              Used when the product is <strong>not</strong> serialized. Serialized stock comes from the
              individual units in <a href="admin-stock.php">Stock &amp; serialized units</a>, so this field is ignored for them.
            </small>
          </div>

          <div class="mb-3">
            <label class="form-label" for="product_id_preview">Product ID</label>
            <input class="form-control" id="product_id_preview" value="<?= $shownId ?>" disabled>
          </div>

          <div class="d-grid gap-2 d-sm-flex">
            <button class="btn btn-accent" type="submit"><?= $isEdit ? 'Save changes' : 'Create product' ?></button>
            <a class="btn btn-outline-secondary" href="<?= htmlspecialchars($listUrl) ?>">Cancel</a>
            <?php if ($isEdit): ?>
            <a class="btn btn-outline-dark ms-sm-auto" href="admin-stock.php?product=<?= (int) $edit['ProductID'] ?>">
              <i class="fa-solid fa-warehouse me-1"></i>Manage stock
            </a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="report-panel">
        <div class="report-panel-head">
          <h2 class="report-panel-title">How a product is connected</h2>
          <p class="report-panel-sub">What this page writes, and what depends on it.</p>
        </div>
        <ul class="list-unstyled small mb-0 dash-list">
          <li><i class="fa-solid fa-arrow-right me-2 text-muted"></i>belongs to <strong>one</strong> <code>category</code></li>
          <li><i class="fa-solid fa-arrow-right me-2 text-muted"></i>supplied by <strong>one</strong> <code>vendor</code></li>
          <li><i class="fa-solid fa-arrow-right me-2 text-muted"></i>may appear on <strong>many</strong> <code>order_item</code>, <code>quotation_item</code>, <code>invoice_item</code> and <code>po_item</code> lines</li>
          <li><i class="fa-solid fa-arrow-right me-2 text-muted"></i>owns <strong>many</strong> <code>product_instance</code> units when serialized</li>
        </ul>
      </div>

      <?php if ($isEdit): ?>
      <div class="report-panel">
        <div class="report-panel-head">
          <h2 class="report-panel-title">Where it has been used</h2>
        </div>
        <?php
        $usage = db_fetch_all(
            // One placeholder per subquery: native prepared statements reject a
            // statement that reuses the same named parameter (SQLSTATE[HY093]).
            'SELECT (SELECT COUNT(*) FROM order_item     WHERE ProductID = :oid) AS OrderLines,
                    (SELECT COUNT(*) FROM quotation_item WHERE ProductID = :qid) AS QuoteLines,
                    (SELECT COUNT(*) FROM invoice_item   WHERE ProductID = :iid) AS InvoiceLines,
                    (SELECT COUNT(*) FROM po_item        WHERE ProductID = :pid) AS PoLines,
                    (SELECT COUNT(*) FROM product_instance WHERE ProductID = :uid) AS Units',
            [
                ':oid' => (int) $edit['ProductID'],
                ':qid' => (int) $edit['ProductID'],
                ':iid' => (int) $edit['ProductID'],
                ':pid' => (int) $edit['ProductID'],
                ':uid' => (int) $edit['ProductID'],
            ]
        );
        $u = $usage[0] ?? [];
        ?>
        <?= admin_detail_row('Order lines',   (int) ($u['OrderLines'] ?? 0)) ?>
        <?= admin_detail_row('Quotation lines', (int) ($u['QuoteLines'] ?? 0)) ?>
        <?= admin_detail_row('Invoice lines', (int) ($u['InvoiceLines'] ?? 0)) ?>
        <?= admin_detail_row('PO lines',      (int) ($u['PoLines'] ?? 0)) ?>
        <?= admin_detail_row('Serialized units', '<a href="admin-stock.php?product=' . (int) $edit['ProductID'] . '">'
            . (int) ($u['Units'] ?? 0) . ' unit(s)</a>') ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <script>
    // A serialized product keeps no bulk quantity, so the field is disabled
    // while the switch is on — matching what the handler stores.
    document.addEventListener('DOMContentLoaded', function () {
      var toggle = document.getElementById('is_serialized');
      var group  = document.getElementById('bulk-stock-group');
      var input  = document.getElementById('stock_qty');
      if (!toggle || !group || !input) return;

      function sync() {
        var serialized = toggle.checked;
        group.style.opacity = serialized ? '0.55' : '1';
        input.disabled = serialized;
      }
      toggle.addEventListener('change', sync);
      sync();
    });
  </script>
  <?php endif; ?>

  <?php endif; ?>
</div>

<?= partial_render('partials/footer.php') ?>
