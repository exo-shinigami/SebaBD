<?php
/**
 * SebaBD — Vendors / suppliers (back office).
 *
 *   admin-vendors.php                    list, with what each supplier carries
 *   admin-vendors.php?action=new         create form
 *   admin-vendors.php?action=edit&id=    edit form
 *   POST intent=create|update|delete     the writes
 *
 * A vendor supplies many products and receives many purchase orders, so the
 * list shows both counts and the delete rule refuses to remove a supplier that
 * either is still attached to (both foreign keys are RESTRICT on purpose).
 */
require_once __DIR__ . '/admin-bootstrap.php';

$user   = require_module('admin-vendors.php');
$dbUp   = db() !== null;
$action = (string) ($_GET['action'] ?? 'list');
$id     = max(0, (int) ($_GET['id'] ?? 0));
$errors = [];
$form   = ['VendorID' => 0, 'VendorName' => '', 'Location' => '', 'ContactPhone' => ''];

/* ------------------------------------------------------------- the writes */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard('admin-vendors.php');

    $intent = post_str('intent');
    $pdo    = db();

    if (!$pdo) {
        $errors[] = 'The database is offline, so nothing can be saved right now.';
    } elseif ($intent === 'delete') {
        $deleteId = post_int('vendor_id');
        try {
            $products = db_count('product', 'VendorID = :id', [':id' => $deleteId]);
            $orders   = db_count('purchase_order', 'VendorID = :id', [':id' => $deleteId]);

            if ($deleteId <= 0) {
                $errors[] = 'That supplier no longer exists.';
            } elseif ($products > 0 || $orders > 0) {
                $parts = [];
                if ($products > 0) {
                    $parts[] = $products . ' product' . ($products === 1 ? '' : 's');
                }
                if ($orders > 0) {
                    $parts[] = $orders . ' purchase order' . ($orders === 1 ? '' : 's');
                }
                $errors[] = 'That supplier is still linked to ' . implode(' and ', $parts)
                          . ', so it cannot be deleted. Move those records to another supplier first.';
            } else {
                db_delete($pdo, 'vendor', ['VendorID' => $deleteId]);
                flash_set('success', 'Supplier deleted.');
                post_success('admin-vendors.php');
            }
        } catch (Throwable $e) {
            $errors[] = admin_error_message($e, 'Could not delete that supplier.');
        }
        $action = 'list';
    } else {
        $name    = post_str('vendor_name');
        $place   = post_str('location');
        $phone   = post_str('contact_phone');
        $editId  = post_int('vendor_id');

        $form = [
            'VendorID' => $editId, 'VendorName' => $name,
            'Location' => $place, 'ContactPhone' => $phone,
        ];

        if (mb_strlen($name) < 2) {
            $errors[] = 'Please enter the supplier name (at least 2 characters).';
        }
        if ($phone !== '' && !is_valid_phone($phone)) {
            $errors[] = 'The contact number looks invalid.';
        }
        if (!$errors) {
            $dupe = db_fetch_all(
                'SELECT VendorID FROM vendor WHERE VendorName = :name AND VendorID <> :id LIMIT 1',
                [':name' => $name, ':id' => $editId]
            );
            if ($dupe) {
                $errors[] = 'Another supplier already uses that name.';
            }
        }

        if (!$errors) {
            $data = [
                'VendorName'   => $name,
                'Location'     => $place !== '' ? $place : null,
                'ContactPhone' => $phone !== '' ? $phone : null,
            ];
            try {
                if ($editId > 0) {
                    if (!db_fetch_all('SELECT VendorID FROM vendor WHERE VendorID = :id', [':id' => $editId])) {
                        deny_page(404, 'Supplier not found', 'Supplier #' . $editId . ' does not exist.');
                    }
                    db_update($pdo, 'vendor', $data, ['VendorID' => $editId]);
                    flash_set('success', 'Supplier #' . $editId . ' updated.');
                } else {
                    $newId = db_transaction($pdo, fn(PDO $tx) => db_insert($tx, 'vendor', $data, 'VendorID'));
                    flash_set('success', 'Supplier “' . $name . '” created as #' . $newId . '.');
                }
                post_success('admin-vendors.php');
            } catch (Throwable $e) {
                $errors[] = admin_error_message($e);
            }
        }

        $action = $editId > 0 ? 'edit' : 'new';
        $id     = $editId;
    }
}

/* -------------------------------------------------------------- the reads */

$rows       = [];
$edit       = null;
$nextFreeId = 0;

if ($dbUp) {
    $rows = db_fetch_all(
        'SELECT v.VendorID, v.VendorName, v.Location, v.ContactPhone,
                (SELECT COUNT(*) FROM product p WHERE p.VendorID = v.VendorID) AS Products,
                (SELECT COUNT(*) FROM purchase_order po WHERE po.VendorID = v.VendorID) AS Orders,
                (SELECT COALESCE(SUM(po.TotalAmount), 0) FROM purchase_order po WHERE po.VendorID = v.VendorID) AS Ordered
         FROM vendor v
         ORDER BY v.VendorName'
    );

    if ($action === 'edit' && $id > 0) {
        $found = db_fetch_all(
            'SELECT VendorID, VendorName, Location, ContactPhone FROM vendor WHERE VendorID = :id',
            [':id' => $id]
        );
        $edit = $found[0] ?? null;
        if ($edit === null) {
            deny_page(404, 'Supplier not found', 'Supplier #' . $id . ' does not exist.', 'admin-vendors.php', 'All suppliers');
        }
    }

    if ($action === 'new') {
        $preview    = db_fetch_all('SELECT COALESCE(MAX(VendorID), 0) + 1 AS n FROM vendor');
        $nextFreeId = (int) ($preview[0]['n'] ?? 1);
    }
}

ajax_errors($errors);

$pageTitle = $action === 'list' ? 'Suppliers' : ($action === 'edit' ? 'Edit supplier' : 'New supplier');
echo partial_render('partials/header.php', ['page_title' => $pageTitle, 'active_nav' => 'admin']);
echo admin_lead('Vendors', 'The suppliers behind the catalog. A vendor supplies many products and receives many purchase orders; every product has exactly one supplier.');
?>

<div class="container pb-5">
  <?= render_flashes() ?>

  <?php if (!$dbUp): ?>
  <div class="alert alert-danger mb-0">
    <i class="fa-solid fa-plug-circle-xmark me-2"></i>
    The database is offline, so suppliers cannot be listed. Start MySQL in the XAMPP control panel and reload.
  </div>
  <?php else: ?>

  <?= admin_tabs(
      ['All suppliers' => 'admin-vendors.php', 'New supplier' => 'admin-vendors.php?action=new'],
      $action === 'list' ? 'All suppliers' : ($action === 'new' ? 'New supplier' : '')
  ) ?>

  <div class="ajax-errors" data-ajax-errors>
    <?php if ($errors): ?>
    <div class="alert alert-danger">
      <ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= htmlspecialchars($err) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>
  </div>

  <div class="row g-4">
    <div class="col-xl-8">
      <?= partial_render('partials/admin-list.php', [
          'panel_title' => 'Suppliers',
          'panel_sub'   => 'Product range and what has been ordered from each one.',
          'columns'     => ['#', 'Supplier', 'Location', 'Contact', 'Range', 'Purchase orders', ''],
          'rows'        => array_map(fn($row) => [
              '<span class="text-muted">' . (int) $row['VendorID'] . '</span>',
              '<a class="fw-semibold text-decoration-none" href="admin-products.php?vendor=' . (int) $row['VendorID'] . '">'
                  . htmlspecialchars($row['VendorName']) . '</a>',
              htmlspecialchars((string) ($row['Location'] ?? '—')),
              htmlspecialchars((string) ($row['ContactPhone'] ?? '—')),
              (int) $row['Products'] > 0
                  ? admin_count_pill((int) $row['Products'], 'product')
                  : '<span class="text-muted small">nothing yet</span>',
              (int) $row['Orders'] > 0
                  ? '<a class="text-decoration-none" href="admin-purchase-orders.php?vendor=' . (int) $row['VendorID'] . '">'
                      . admin_count_pill((int) $row['Orders'], 'order') . '</a>'
                      . '<small class="d-block text-muted">' . money($row['Ordered']) . '</small>'
                  : '<span class="text-muted small">none</span>',
              '<div class="admin-actions-cell">'
                  . '<a class="btn btn-sm btn-outline-secondary" href="admin-vendors.php?action=edit&id=' . (int) $row['VendorID'] . '">'
                      . '<i class="fa-solid fa-pen"></i><span class="visually-hidden">Edit</span></a> '
                  . '<form method="post" action="admin-vendors.php" class="d-inline"'
                      . ' onsubmit="return confirm(\'Delete supplier “' . htmlspecialchars($row['VendorName'], ENT_QUOTES) . '”?\');">'
                      . csrf_field()
                      . '<input type="hidden" name="intent" value="delete">'
                      . '<input type="hidden" name="vendor_id" value="' . (int) $row['VendorID'] . '">'
                      . '<button class="btn btn-sm btn-outline-danger" type="submit"'
                      . ((int) $row['Products'] > 0 || (int) $row['Orders'] > 0 ? ' disabled title="Still linked to products or purchase orders"' : '')
                      . '><i class="fa-solid fa-trash-can"></i><span class="visually-hidden">Delete</span></button>'
                  . '</form>'
                  . '</div>',
          ], $rows),
          'empty_icon'   => 'fa-truck-field',
          'empty_title'  => 'No suppliers yet',
          'empty_text'   => 'Every product must name the supplier it comes from. Add the first one to start building the catalog.',
          'empty_action' => '<a class="btn btn-accent btn-sm" href="admin-vendors.php?action=new">'
                          . '<i class="fa-solid fa-plus me-1"></i>New supplier</a>',
          'note'         => '<code>product.VendorID</code> and <code>purchase_order.VendorID</code> both point here and are '
                          . '<code>RESTRICT</code>, so the page disables delete while either still references the supplier.',
      ]) ?>
    </div>

    <div class="col-xl-4">
      <?php if ($action === 'list'): ?>
      <div class="report-panel">
        <div class="report-panel-head">
          <h2 class="report-panel-title">Add a supplier</h2>
          <p class="report-panel-sub">Name, location and a contact number.</p>
        </div>
        <a class="btn btn-accent" href="admin-vendors.php?action=new"><i class="fa-solid fa-plus me-1"></i>New supplier</a>
      </div>
      <?php else:
          $isEdit = $action === 'edit' && $edit !== null;
          $values = $isEdit
              ? ['VendorName' => (string) $edit['VendorName'], 'Location' => (string) ($edit['Location'] ?? ''), 'ContactPhone' => (string) ($edit['ContactPhone'] ?? '')]
              : ['VendorName' => $form['VendorName'], 'Location' => $form['Location'], 'ContactPhone' => $form['ContactPhone']];
          $shownId = $isEdit ? (int) $edit['VendorID'] : $nextFreeId;
      ?>
      <div class="form-card">
        <div class="form-card-head">
          <h2 class="h5 mb-1"><?= $isEdit ? 'Edit supplier #' . (int) $edit['VendorID'] : 'New supplier' ?></h2>
          <p class="text-muted small mb-0">Location and contact number are optional, but the purchasing pages show them next to every order.</p>
        </div>

        <form method="post" action="admin-vendors.php" novalidate data-ajax>
          <?= csrf_field() ?>
          <input type="hidden" name="intent" value="<?= $isEdit ? 'update' : 'create' ?>">
          <input type="hidden" name="vendor_id" value="<?= $shownId ?>">

          <div class="mb-3">
            <label class="form-label" for="vendor_name">Supplier name <span class="req">*</span></label>
            <input class="form-control" id="vendor_name" name="vendor_name" maxlength="255"
                   value="<?= htmlspecialchars($values['VendorName']) ?>" required autofocus>
          </div>
          <div class="mb-3">
            <label class="form-label" for="location">Location</label>
            <input class="form-control" id="location" name="location" maxlength="255"
                   value="<?= htmlspecialchars($values['Location']) ?>" placeholder="City / country">
          </div>
          <div class="mb-3">
            <label class="form-label" for="contact_phone">Contact number</label>
            <input class="form-control" id="contact_phone" name="contact_phone" maxlength="50"
                   value="<?= htmlspecialchars($values['ContactPhone']) ?>" placeholder="+880 1XXX XXXXXX">
          </div>
          <div class="mb-3">
            <label class="form-label" for="vendor_id_preview">Supplier ID</label>
            <input class="form-control" id="vendor_id_preview" value="<?= $shownId ?>" disabled>
            <small class="text-muted">Allocated automatically when you save.</small>
          </div>

          <div class="d-grid gap-2 d-sm-flex">
            <button class="btn btn-accent" type="submit"><?= $isEdit ? 'Save changes' : 'Create supplier' ?></button>
            <a class="btn btn-outline-secondary" href="admin-vendors.php">Cancel</a>
          </div>
        </form>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <?php endif; ?>
</div>

<?= partial_render('partials/footer.php') ?>
