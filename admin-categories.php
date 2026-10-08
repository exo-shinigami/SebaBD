<?php
/**
 * SebaBD — Categories (back office).
 *
 * One page for the whole lifetime of a `category` row: list, create, rename,
 * delete. The category name is the only attribute the table carries, so the
 * page concentrates on the thing that actually matters — how many products
 * each category classifies, and refusing to delete one that is still in use.
 *
 *   admin-categories.php                 list
 *   admin-categories.php?action=new      create form
 *   admin-categories.php?action=edit&id= edit form
 *   POST intent=create|update|delete     the writes
 */
require_once __DIR__ . '/admin-bootstrap.php';

$user   = require_module('admin-categories.php');
$dbUp   = db() !== null;
$action = (string) ($_GET['action'] ?? 'list');
$id     = max(0, (int) ($_GET['id'] ?? 0));
$errors = [];
$form   = ['CategoryID' => 0, 'CategoryName' => ''];

/* ------------------------------------------------------------- the writes */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard('admin-categories.php');

    $intent = post_str('intent');
    $pdo    = db();

    if (!$pdo) {
        $errors[] = 'The database is offline, so nothing can be saved right now.';
    } elseif ($intent === 'delete') {
        $deleteId = post_int('category_id');
        try {
            $used = db_count('product', 'CategoryID = :cid', [':cid' => $deleteId]);
            if ($deleteId <= 0) {
                $errors[] = 'That category no longer exists.';
            } elseif ($used > 0) {
                // product.CategoryID is RESTRICT, and rightly so: a category
                // that still classifies products must not silently vanish.
                $errors[] = 'That category still classifies ' . $used . ' product'
                          . ($used === 1 ? '' : 's') . ', so it cannot be deleted. '
                          . 'Move those products to another category first.';
            } else {
                db_delete($pdo, 'category', ['CategoryID' => $deleteId]);
                flash_set('success', 'Category deleted.');
                post_success('admin-categories.php');
            }
        } catch (Throwable $e) {
            $errors[] = admin_error_message($e, 'Could not delete that category.');
        }
        $action = 'list';
    } else {
        $name     = post_str('category_name');
        $editId   = post_int('category_id');
        $form     = ['CategoryID' => $editId, 'CategoryName' => $name];

        if ($name === '') {
            $errors[] = 'Please give the category a name.';
        } elseif (mb_strlen($name) > 255) {
            $errors[] = 'Category names are limited to 255 characters.';
        }
        if (!$errors) {
            $dupe = db_fetch_all(
                'SELECT CategoryID FROM category WHERE CategoryName = :name AND CategoryID <> :id LIMIT 1',
                [':name' => $name, ':id' => $editId]
            );
            if ($dupe) {
                $errors[] = 'Another category already uses that name.';
            }
        }

        if (!$errors) {
            try {
                if ($editId > 0) {
                    // Verify the row is real before updating it.
                    if (!db_fetch_all('SELECT CategoryID FROM category WHERE CategoryID = :id', [':id' => $editId])) {
                        deny_page(404, 'Category not found', 'Category #' . $editId . ' does not exist.');
                    }
                    db_update($pdo, 'category', ['CategoryName' => $name], ['CategoryID' => $editId]);
                    flash_set('success', 'Category #' . $editId . ' renamed to “' . $name . '”.');
                } else {
                    $newId = db_transaction($pdo, fn(PDO $tx) => db_insert($tx, 'category', ['CategoryName' => $name], 'CategoryID'));
                    flash_set('success', 'Category “' . $name . '” created as #' . $newId . '.');
                }
                clear_old();
                post_success('admin-categories.php');
            } catch (Throwable $e) {
                $errors[] = admin_error_message($e);
            }
        }

        keep_old($form);
        $action = $editId > 0 ? 'edit' : 'new';
        $id     = $editId;
    }
}

/* -------------------------------------------------------------- the reads */

$rows       = [];
$edit       = null;
$nextFreeId = 0;

if ($dbUp) {
    // One query for the list, with the product count per category so the page
    // shows why a row can or cannot be deleted.
    $rows = db_fetch_all(
        'SELECT c.CategoryID, c.CategoryName,
                (SELECT COUNT(*) FROM product p WHERE p.CategoryID = c.CategoryID) AS Products
         FROM category c
         ORDER BY c.CategoryID'
    );

    if ($action === 'edit' && $id > 0) {
        $found = db_fetch_all(
            'SELECT CategoryID, CategoryName FROM category WHERE CategoryID = :id',
            [':id' => $id]
        );
        $edit = $found[0] ?? null;
        if ($edit === null) {
            deny_page(404, 'Category not found', 'Category #' . $id . ' does not exist.', 'admin-categories.php', 'All categories');
        }
    }

    if ($action === 'new') {
        $preview = db_fetch_all('SELECT COALESCE(MAX(CategoryID), 0) + 1 AS n FROM category');
        $nextFreeId = (int) ($preview[0]['n'] ?? 1);
    }
}

// A fetch() submit stops here with the error list; a normal submit renders them.
ajax_errors($errors);

$pageTitle = $action === 'list' ? 'Categories' : ($action === 'edit' ? 'Edit category' : 'New category');
echo partial_render('partials/header.php', ['page_title' => $pageTitle, 'active_nav' => 'admin']);
echo admin_lead('Categories', 'How the catalog is grouped. A category can classify many products; every product belongs to exactly one.');
?>

<div class="container pb-5">
  <?= render_flashes() ?>

  <?php if (!$dbUp): ?>
  <div class="alert alert-danger mb-0">
    <i class="fa-solid fa-plug-circle-xmark me-2"></i>
    The database is offline, so categories cannot be listed. Start MySQL in the XAMPP control panel and reload.
  </div>
  <?php else: ?>

  <?= admin_tabs(
      ['All categories' => 'admin-categories.php', 'New category' => 'admin-categories.php?action=new'],
      $action === 'list' ? 'All categories' : ($action === 'new' ? 'New category' : '')
  ) ?>

  <div class="ajax-errors" data-ajax-errors>
    <?php if ($errors): ?>
    <div class="alert alert-danger">
      <ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= htmlspecialchars($err) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>
  </div>

  <div class="row g-4">
    <div class="col-lg-7">
      <?= partial_render('partials/admin-list.php', [
          'panel_title'  => 'Categories',
          'panel_sub'    => 'Each product is classified by exactly one of these.',
          'columns'      => ['#', 'Category', 'Products', ''],
          'rows'         => array_map(fn($row) => [
              '<span class="text-muted">' . (int) $row['CategoryID'] . '</span>',
              '<a class="fw-semibold text-decoration-none" href="admin-products.php?category=' . (int) $row['CategoryID'] . '">'
                  . htmlspecialchars($row['CategoryName']) . '</a>',
              (int) $row['Products'] > 0
                  ? '<a class="text-decoration-none" href="admin-products.php?category=' . (int) $row['CategoryID'] . '">'
                      . admin_count_pill((int) $row['Products'], 'product') . '</a>'
                  : '<span class="text-muted small">none yet</span>',
              '<div class="admin-actions-cell">'
                  . '<a class="btn btn-sm btn-outline-secondary" href="admin-categories.php?action=edit&id=' . (int) $row['CategoryID'] . '">'
                      . '<i class="fa-solid fa-pen"></i><span class="visually-hidden">Edit</span></a> '
                  . '<form method="post" action="admin-categories.php" class="d-inline"'
                      . ' onsubmit="return confirm(\'Delete category “' . htmlspecialchars($row['CategoryName'], ENT_QUOTES) . '”?\');">'
                      . csrf_field()
                      . '<input type="hidden" name="intent" value="delete">'
                      . '<input type="hidden" name="category_id" value="' . (int) $row['CategoryID'] . '">'
                      . '<button class="btn btn-sm btn-outline-danger" type="submit"'
                      . ((int) $row['Products'] > 0 ? ' disabled title="Move its products first"' : '')
                      . '><i class="fa-solid fa-trash-can"></i><span class="visually-hidden">Delete</span></button>'
                  . '</form>'
                  . '</div>',
          ], $rows),
          'empty_icon'   => 'fa-tags',
          'empty_title'  => 'No categories yet',
          'empty_text'   => 'A category groups products on the storefront. Create the first one — for example <em>Laptops &amp; Computers</em>.',
          'empty_action' => '<a class="btn btn-accent btn-sm" href="admin-categories.php?action=new">'
                          . '<i class="fa-solid fa-plus me-1"></i>New category</a>',
          'note'         => 'A category with products cannot be deleted — the foreign key from '
                          . '<code>product</code> is <code>RESTRICT</code>, so the page offers to move them first.',
      ]) ?>
    </div>

    <div class="col-lg-5">
      <?php if ($action === 'list'): ?>
      <div class="report-panel">
        <div class="report-panel-head">
          <h2 class="report-panel-title">Add a category</h2>
          <p class="report-panel-sub">Jump straight to the form.</p>
        </div>
        <a class="btn btn-accent" href="admin-categories.php?action=new"><i class="fa-solid fa-plus me-1"></i>New category</a>
      </div>
      <?php else:
          $isEdit = $action === 'edit' && $edit !== null;
          $value  = $isEdit ? (string) $edit['CategoryName'] : $form['CategoryName'];
          $shownId = $isEdit ? (int) $edit['CategoryID'] : $nextFreeId;
      ?>
      <div class="form-card">
        <div class="form-card-head">
          <h2 class="h5 mb-1"><?= $isEdit ? 'Edit category #' . (int) $edit['CategoryID'] : 'New category' ?></h2>
          <p class="text-muted small mb-0">
            <?= $isEdit
                ? 'Renaming a category updates it everywhere it is used, because products point at the key, not the name.'
                : 'The next free key is allocated automatically when you save.' ?>
          </p>
        </div>

        <form method="post" action="admin-categories.php" novalidate data-ajax>
          <?= csrf_field() ?>
          <input type="hidden" name="intent" value="<?= $isEdit ? 'update' : 'create' ?>">
          <input type="hidden" name="category_id" value="<?= $shownId ?>">

          <div class="mb-3">
            <label class="form-label" for="category_name">Category name <span class="req">*</span></label>
            <input class="form-control" id="category_name" name="category_name" maxlength="255"
                   value="<?= htmlspecialchars($value) ?>" required autofocus>
          </div>

          <div class="mb-3">
            <label class="form-label" for="category_id_preview">Category ID</label>
            <input class="form-control" id="category_id_preview" value="<?= $shownId ?>" disabled>
            <small class="text-muted">Primary key, allocated by the application — the schema does not use AUTO_INCREMENT.</small>
          </div>

          <div class="d-grid gap-2 d-sm-flex">
            <button class="btn btn-accent" type="submit"><?= $isEdit ? 'Save changes' : 'Create category' ?></button>
            <a class="btn btn-outline-secondary" href="admin-categories.php">Cancel</a>
            <?php if ($isEdit): ?>
            <a class="btn btn-outline-dark ms-sm-auto" href="admin-products.php?category=<?= (int) $edit['CategoryID'] ?>">
              <i class="fa-solid fa-box me-1"></i>View its products
            </a>
            <?php endif; ?>
          </div>
        </form>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <?php endif; ?>
</div>

<?= partial_render('partials/footer.php') ?>
