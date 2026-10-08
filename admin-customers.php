<?php
/**
 * SebaBD — Customers & branches (back office).
 *
 *   admin-customers.php                        client list, with what each owes
 *   admin-customers.php?action=new             new company
 *   admin-customers.php?action=edit&id=        company + its branches
 *   admin-customers.php?action=branch&client=  one branch's form
 *   POST intent=create|update|delete|branch_create|branch_update|branch_delete
 *
 * Two entities live on one page because they are never used apart: a quotation
 * or an invoice is always addressed to a *branch* (#10, #13), but a company
 * (#3) is what a person thinks in. A client may optionally be linked to one
 * login (#2) — that is the 1:1 in the schema, and `client.UserID` is UNIQUE.
 */
require_once __DIR__ . '/admin-bootstrap.php';

$user   = require_module('admin-customers.php');
$dbUp   = db() !== null;
$action = (string) ($_GET['action'] ?? 'list');
$id     = max(0, (int) ($_GET['id'] ?? 0));
$errors = [];

$form = [
    'ClientID' => 0, 'CompanyName' => '', 'ContactPerson' => '',
    'Email' => '', 'Phone' => '', 'Address' => '', 'UserID' => 0,
];

/* --------------------------------------------------- the client's own write */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard('admin-customers.php');

    $intent = post_str('intent');
    $pdo    = db();

    if (!$pdo) {
        $errors[] = 'The database is offline, so nothing can be saved right now.';
    } elseif ($intent === 'delete') {
        $deleteId = post_int('client_id');
        try {
            // client_branch.ClientID is ON DELETE CASCADE, but the branches
            // themselves may be referenced by quotations and invoices whose
            // foreign keys are RESTRICT. Count first so the person sees the
            // real reason instead of a raw constraint error.
            $openQuotes   = (int) (db_fetch_all(
                'SELECT COUNT(*) AS n FROM quotation q
                 JOIN client_branch b ON b.BranchID = q.BranchID
                 WHERE b.ClientID = :id',
                [':id' => $deleteId]
            )[0]['n'] ?? 0);
            $openInvoices = (int) (db_fetch_all(
                'SELECT COUNT(*) AS n FROM invoice i
                 JOIN client_branch b ON b.BranchID = i.BranchID
                 WHERE b.ClientID = :id',
                [':id' => $deleteId]
            )[0]['n'] ?? 0);

            if ($deleteId <= 0) {
                $errors[] = 'That company no longer exists.';
            } elseif ($openQuotes > 0 || $openInvoices > 0) {
                $parts = [];
                if ($openQuotes > 0) {
                    $parts[] = $openQuotes . ' quotation' . ($openQuotes === 1 ? '' : 's');
                }
                if ($openInvoices > 0) {
                    $parts[] = $openInvoices . ' invoice' . ($openInvoices === 1 ? '' : 's');
                }
                $errors[] = 'That company still has ' . implode(' and ', $parts)
                          . ' on its branches, so it cannot be deleted — the sales history must stay readable.';
            } else {
                db_transaction($pdo, function (PDO $tx) use ($deleteId) {
                    db_delete($tx, 'client_branch', ['ClientID' => $deleteId]);
                    db_delete($tx, 'client', ['ClientID' => $deleteId]);
                });
                flash_set('success', 'Company #' . $deleteId . ' and its branches were deleted.');
                post_success('admin-customers.php');
            }
        } catch (Throwable $e) {
            $errors[] = admin_error_message($e, 'Could not delete that company.');
        }
        $action = 'list';
    } elseif ($intent === 'branch_create' || $intent === 'branch_update') {
        $clientId    = post_int('client_id');
        $branchId    = post_int('branch_id');
        $branchName  = post_str('branch_name');
        $branchAddr  = post_str('branch_address');
        $branchPhone = post_str('branch_phone');

        if ($clientId <= 0) {
            $errors[] = 'Choose the company this branch belongs to.';
        }
        if ($branchName === '') {
            $errors[] = 'Give the branch a name, for example “Head Office” or “Chattogram Branch”.';
        }
        if (mb_strlen($branchName) > 255) {
            $errors[] = 'Branch names are limited to 255 characters.';
        }
        if ($branchPhone !== '' && !is_valid_phone($branchPhone)) {
            $errors[] = 'The branch contact number looks invalid.';
        }
        if (!$errors) {
            $exists = db_fetch_all('SELECT ClientID FROM client WHERE ClientID = :id', [':id' => $clientId]);
            if (!$exists) {
                $errors[] = 'That company no longer exists.';
            }
        }

        if (!$errors) {
            $data = [
                'ClientID'      => $clientId,
                'BranchName'    => $branchName,
                'BranchAddress' => $branchAddr !== '' ? $branchAddr : null,
                'ContactNo'     => $branchPhone !== '' ? $branchPhone : null,
            ];
            try {
                if ($intent === 'branch_update') {
                    $existing = db_fetch_all('SELECT BranchID FROM client_branch WHERE BranchID = :id', [':id' => $branchId]);
                    if (!$existing) {
                        deny_page(404, 'Branch not found', 'Branch #' . $branchId . ' does not exist.');
                    }
                    db_update($pdo, 'client_branch', $data, ['BranchID' => $branchId]);
                    flash_set('success', 'Branch “' . $branchName . '” updated.');
                } else {
                    $newId = db_transaction($pdo, fn(PDO $tx) => db_insert($tx, 'client_branch', $data, 'BranchID'));
                    flash_set('success', 'Branch “' . $branchName . '” added as #' . $newId . '.');
                }
                post_success('admin-customers.php?action=edit&id=' . $clientId);
            } catch (Throwable $e) {
                $errors[] = admin_error_message($e, 'Could not save that branch.');
            }
        }
        $action = 'edit';
        $id     = $clientId;
    } elseif ($intent === 'branch_delete') {
        $branchId = post_int('branch_id');
        $clientId = post_int('client_id');
        try {
            $quotes   = db_count('quotation', 'BranchID = :id', [':id' => $branchId]);
            $invoices = db_count('invoice', 'BranchID = :id', [':id' => $branchId]);

            if ($quotes > 0 || $invoices > 0) {
                $errors[] = 'That branch has ' . ($quotes + $invoices) . ' quotation(s)/invoice(s) addressed to it, '
                          . 'so it cannot be deleted. Keep it on file so past documents still resolve.';
            } else {
                db_delete($pdo, 'client_branch', ['BranchID' => $branchId]);
                flash_set('success', 'Branch #' . $branchId . ' deleted.');
                post_success('admin-customers.php?action=edit&id=' . $clientId);
            }
        } catch (Throwable $e) {
            $errors[] = admin_error_message($e, 'Could not delete that branch.');
        }
        $action = 'edit';
        $id     = $clientId;
    } else {
        $editId     = post_int('client_id');
        $company    = post_str('company_name');
        $contact    = post_str('contact_person');
        $email      = post_str('email');
        $phone      = post_str('phone');
        $address    = post_str('address');
        $userId     = post_int('user_id');

        $form = [
            'ClientID' => $editId, 'CompanyName' => $company, 'ContactPerson' => $contact,
            'Email' => $email, 'Phone' => $phone, 'Address' => $address, 'UserID' => $userId,
        ];

        if (mb_strlen($company) < 2) {
            $errors[] = 'Please enter the company name (at least 2 characters).';
        }
        if ($contact === '') {
            $errors[] = 'Please name the contact person at the company.';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'That email address does not look valid.';
        }
        if ($phone !== '' && !is_valid_phone($phone)) {
            $errors[] = 'The phone number looks invalid.';
        }
        if (!$errors && $userId > 0) {
            // The 1:1 link: the login must be a customer account, and must not
            // already point at a different company.
            $login = db_fetch_all(
                'SELECT u.UserID, u.FullName, r.RoleName, c.ClientID
                 FROM `user` u
                 JOIN `role` r ON r.RoleID = u.RoleID
                 LEFT JOIN client c ON c.UserID = u.UserID
                 WHERE u.UserID = :id',
                [':id' => $userId]
            );
            if (!$login) {
                $errors[] = 'That login account does not exist.';
            } elseif (!in_array($login[0]['RoleName'], client_roles(), true)) {
                $errors[] = 'Only customer accounts (Client Account / B2B Client Account) can be linked to a company. '
                          . $login[0]['FullName'] . ' holds the ' . $login[0]['RoleName'] . ' role.';
            } elseif ((int) ($login[0]['ClientID'] ?? 0) > 0 && (int) $login[0]['ClientID'] !== $editId) {
                $errors[] = 'That login is already linked to another company (#'
                          . (int) $login[0]['ClientID'] . '). A login can belong to one company only.';
            }
        }

        if (!$errors) {
            $data = [
                'CompanyName'   => $company,
                'ContactPerson' => $contact,
                'Email'         => $email !== '' ? $email : null,
                'Phone'         => $phone !== '' ? $phone : null,
                'Address'       => $address !== '' ? $address : null,
                'UserID'        => $userId > 0 ? $userId : null,
            ];
            try {
                if ($editId > 0) {
                    if (!db_fetch_all('SELECT ClientID FROM client WHERE ClientID = :id', [':id' => $editId])) {
                        deny_page(404, 'Company not found', 'Company #' . $editId . ' does not exist.');
                    }
                    db_update($pdo, 'client', $data, ['ClientID' => $editId]);
                    flash_set('success', 'Company “' . $company . '” updated.');
                } else {
                    $newId = db_transaction($pdo, fn(PDO $tx) => db_insert($tx, 'client', $data, 'ClientID'));
                    flash_set('success', 'Company “' . $company . '” created as #' . $newId
                        . '. Add at least one branch so quotations and invoices can be addressed to it.');
                    post_success('admin-customers.php?action=edit&id=' . $newId);
                }
                post_success('admin-customers.php?action=edit&id=' . $editId);
            } catch (Throwable $e) {
                $errors[] = admin_error_message($e);
            }
        }

        $action = $editId > 0 ? 'edit' : 'new';
        $id     = $editId;
    }
}

/* -------------------------------------------------------------- the reads */

$rows        = [];
$edit        = null;
$branches    = [];
$editBranch  = null;
$nextFreeId  = 0;
$candidates  = [];
$clientPage  = null;
$customerQs  = trim((string) ($_GET['q'] ?? ''));

if ($dbUp) {
    // ---- list
    $where  = [];
    $params = [];
    if ($customerQs !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $customerQs) . '%';
        $where[] = '(c.CompanyName LIKE :q1 OR c.ContactPerson LIKE :q2 OR c.Email LIKE :q3)';
        $params[':q1'] = $like;
        $params[':q2'] = $like;
        $params[':q3'] = $like;
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $total      = db_fetch_all('SELECT COUNT(*) AS n FROM client c' . $whereSql, $params);
    $clientPage = paginate((int) ($total[0]['n'] ?? 0), 20);

    $rows = db_fetch_all(
        'SELECT c.ClientID, c.CompanyName, c.ContactPerson, c.Email, c.Phone, c.UserID,
                u.Username, u.FullName,
                (SELECT COUNT(*) FROM client_branch b WHERE b.ClientID = c.ClientID) AS Branches,
                (SELECT COUNT(*) FROM quotation q JOIN client_branch b ON b.BranchID = q.BranchID
                  WHERE b.ClientID = c.ClientID) AS Quotes,
                (SELECT COUNT(*) FROM invoice i JOIN client_branch b ON b.BranchID = i.BranchID
                  WHERE b.ClientID = c.ClientID) AS Invoices,
                (SELECT COALESCE(SUM(i.RemainingBalance), 0) FROM invoice i
                  JOIN client_branch b ON b.BranchID = i.BranchID
                  WHERE b.ClientID = c.ClientID AND i.RemainingBalance > 0) AS Outstanding
         FROM client c
         LEFT JOIN `user` u ON u.UserID = c.UserID' . $whereSql . '
         ORDER BY c.CompanyName
         LIMIT ' . $clientPage['perPage'] . ' OFFSET ' . $clientPage['offset'],
        $params
    );

    // ---- login accounts that may be linked to a company
    $candidates = db_fetch_all(
        'SELECT u.UserID, u.FullName, u.Username, r.RoleName, c.ClientID AS LinkedTo
         FROM `user` u
         JOIN `role` r ON r.RoleID = u.RoleID
         LEFT JOIN client c ON c.UserID = u.UserID
         WHERE r.RoleName IN ("Client Account", "B2B Client Account")
         ORDER BY u.FullName'
    );

    if ($action === 'edit' || $action === 'branch') {
        $found = db_fetch_all(
            'SELECT ClientID, CompanyName, ContactPerson, Email, Phone, Address, UserID
             FROM client WHERE ClientID = :id',
            [':id' => $action === 'branch' ? max(0, (int) ($_GET['client'] ?? 0)) : $id]
        );
        $edit = $found[0] ?? null;
        if ($edit === null) {
            deny_page(404, 'Company not found', 'That company does not exist.', 'admin-customers.php', 'All customers');
        }
        $id       = (int) $edit['ClientID'];
        $branches = db_fetch_all(
            'SELECT b.BranchID, b.BranchName, b.BranchAddress, b.ContactNo,
                    (SELECT COUNT(*) FROM quotation q WHERE q.BranchID = b.BranchID) AS Quotes,
                    (SELECT COUNT(*) FROM invoice i WHERE i.BranchID = b.BranchID) AS Invoices
             FROM client_branch b
             WHERE b.ClientID = :cid
             ORDER BY b.BranchID',
            [':cid' => $id]
        );

        if ($action === 'branch') {
            $branchId = max(0, (int) ($_GET['id'] ?? 0));
            if ($branchId > 0) {
                foreach ($branches as $branch) {
                    if ((int) $branch['BranchID'] === $branchId) {
                        $editBranch = $branch;
                    }
                }
                if ($editBranch === null) {
                    deny_page(404, 'Branch not found', 'Branch #' . $branchId . ' does not belong to this company.',
                        'admin-customers.php?action=edit&id=' . $id, 'Back to the company');
                }
            }
        }
    }

    if ($action === 'new') {
        $preview    = db_fetch_all('SELECT COALESCE(MAX(ClientID), 0) + 1 AS n FROM client');
        $nextFreeId = (int) ($preview[0]['n'] ?? 1);
    }
}

ajax_errors($errors);

$listUrl = 'admin-customers.php' . ($customerQs !== '' ? '?q=' . urlencode($customerQs) : '');

echo partial_render('partials/header.php', ['page_title' => 'Customers', 'active_nav' => 'admin']);
echo admin_lead('Customers &amp; branches', 'A company owns one or more branch offices, and every quotation and invoice is addressed to a specific branch. A login may be linked to one company.');
?>

<div class="container pb-5">
  <?= render_flashes() ?>

  <?php if (!$dbUp): ?>
  <div class="alert alert-danger mb-0">
    <i class="fa-solid fa-plug-circle-xmark me-2"></i>
    The database is offline, so customers cannot be listed. Start MySQL in the XAMPP control panel and reload.
  </div>
  <?php else: ?>

  <?= admin_tabs(
      ['All customers' => 'admin-customers.php', 'New company' => 'admin-customers.php?action=new'],
      $action === 'list' ? 'All customers' : ($action === 'new' ? 'New company' : '')
  ) ?>

  <div class="ajax-errors" data-ajax-errors>
    <?php if ($errors): ?>
    <div class="alert alert-danger">
      <ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= htmlspecialchars($err) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($action === 'list'): ?>

  <form class="report-filter mb-3" method="get" action="admin-customers.php">
    <div class="report-filter-fields">
      <label class="report-filter-field">
        <span class="form-label">Company, contact or email</span>
        <input class="form-control form-control-sm" type="search" name="q" value="<?= htmlspecialchars($customerQs) ?>"
               placeholder="e.g. Meghna">
      </label>
    </div>
    <div class="report-filter-actions">
      <button class="btn btn-accent btn-sm" type="submit"><i class="fa-solid fa-magnifying-glass me-1"></i>Search</button>
      <a class="btn btn-outline-secondary btn-sm" href="admin-customers.php">Reset</a>
    </div>
  </form>

  <?= partial_render('partials/admin-list.php', [
      'panel_title' => 'Customers',
      'panel_sub'   => 'Companies, their branch offices, and what they still owe.',
      'columns'     => ['#', 'Company', 'Contact', 'Branches', 'Documents', 'Outstanding', ''],
      'rows'        => array_map(fn($row) => [
          '<span class="text-muted">' . (int) $row['ClientID'] . '</span>',
          '<a class="fw-semibold text-decoration-none" href="admin-customers.php?action=edit&id=' . (int) $row['ClientID'] . '">'
              . htmlspecialchars($row['CompanyName']) . '</a>'
              . ($row['UserID'] !== null
                  ? '<small class="d-block text-muted"><i class="fa-solid fa-user-check me-1"></i>login: ' . htmlspecialchars((string) $row['Username']) . '</small>'
                  : '<small class="d-block text-muted">no online login</small>'),
          htmlspecialchars((string) ($row['ContactPerson'] ?? '—'))
              . ($row['Phone'] ? '<small class="d-block text-muted">' . htmlspecialchars($row['Phone']) . '</small>' : ''),
          (int) $row['Branches'] > 0
              ? admin_count_pill((int) $row['Branches'], 'branch')
              : '<span class="report-badge report-badge-danger">none</span>',
          '<a class="text-decoration-none" href="admin-quotations.php?client=' . (int) $row['ClientID'] . '">'
              . (int) $row['Quotes'] . ' quotation' . ((int) $row['Quotes'] === 1 ? '' : 's') . '</a>'
              . '<small class="d-block text-muted"><a href="admin-invoices.php?client=' . (int) $row['ClientID'] . '">'
              . (int) $row['Invoices'] . ' invoice' . ((int) $row['Invoices'] === 1 ? '' : 's') . '</a></small>',
          (float) $row['Outstanding'] > 0
              ? '<span class="text-danger fw-semibold">' . money($row['Outstanding']) . '</span>'
              : '<span class="text-success"><i class="fa-solid fa-circle-check me-1"></i>settled</span>',
          '<div class="admin-actions-cell">'
              . '<a class="btn btn-sm btn-outline-secondary" href="admin-customers.php?action=edit&id=' . (int) $row['ClientID'] . '">'
                  . '<i class="fa-solid fa-pen"></i><span class="visually-hidden">Edit</span></a> '
              . '<a class="btn btn-sm btn-outline-secondary" href="admin-quotations.php?action=new&client=' . (int) $row['ClientID'] . '" title="New quotation">'
                  . '<i class="fa-solid fa-file-signature"></i><span class="visually-hidden">Quote</span></a> '
              . '<form method="post" action="admin-customers.php" class="d-inline"'
                  . ' onsubmit="return confirm(\'Delete company “' . htmlspecialchars($row['CompanyName'], ENT_QUOTES) . '”?\');">'
                  . csrf_field()
                  . '<input type="hidden" name="intent" value="delete">'
                  . '<input type="hidden" name="client_id" value="' . (int) $row['ClientID'] . '">'
                  . '<button class="btn btn-sm btn-outline-danger" type="submit">'
                  . '<i class="fa-solid fa-trash-can"></i><span class="visually-hidden">Delete</span></button>'
              . '</form>'
              . '</div>',
      ], $rows),
      'empty_icon'   => 'fa-building-user',
      'empty_title'  => $customerQs !== '' ? 'No company matched that search' : 'No customers yet',
      'empty_text'   => $customerQs !== ''
          ? 'Try part of the company name, the contact person or their email address.'
          : 'Companies arrive from the B2B registration form, or you can add one here — quotations and invoices are always addressed to a company branch.',
      'empty_action' => $customerQs !== ''
          ? '<a class="btn btn-outline-secondary btn-sm" href="admin-customers.php">Clear search</a>'
          : '<a class="btn btn-accent btn-sm" href="admin-customers.php?action=new"><i class="fa-solid fa-plus me-1"></i>New company</a>',
      'actions'      => admin_actions([
          ['href' => 'admin-customers.php?action=new', 'label' => 'New company', 'icon' => 'fa-plus'],
      ]),
      'summary'      => pagination_summary($clientPage, 'company'),
      'pagination'   => pagination_links($clientPage, $listUrl),
      'note'         => 'Walk-in quotations create a shared <em>Walk-in Customers</em> company automatically, which is why it has no linked login.',
  ]) ?>

  <?php elseif ($action === 'branch'):
      $isNewBranch = $editBranch === null;
      $branchVals  = $isNewBranch ? ['BranchID' => 0, 'BranchName' => '', 'BranchAddress' => '', 'ContactNo' => ''] : $editBranch;
  ?>
  <div class="row g-4">
    <div class="col-lg-7">
      <div class="form-card">
        <div class="form-card-head">
          <h2 class="h5 mb-1"><?= $isNewBranch ? 'Add a branch' : 'Edit branch #' . (int) $editBranch['BranchID'] ?></h2>
          <p class="text-muted small mb-0">Branch of <strong><?= htmlspecialchars($edit['CompanyName']) ?></strong>.</p>
        </div>
        <form method="post" action="admin-customers.php" novalidate data-ajax>
          <?= csrf_field() ?>
          <input type="hidden" name="intent" value="<?= $isNewBranch ? 'branch_create' : 'branch_update' ?>">
          <input type="hidden" name="client_id" value="<?= (int) $edit['ClientID'] ?>">
          <input type="hidden" name="branch_id" value="<?= (int) $branchVals['BranchID'] ?>">
          <div class="mb-3">
            <label class="form-label" for="branch_name">Branch name <span class="req">*</span></label>
            <input class="form-control" id="branch_name" name="branch_name" maxlength="255"
                   value="<?= htmlspecialchars((string) $branchVals['BranchName']) ?>" required autofocus
                   placeholder="e.g. Head Office, Chattogram Branch">
          </div>
          <div class="mb-3">
            <label class="form-label" for="branch_phone">Contact number</label>
            <input class="form-control" id="branch_phone" name="branch_phone" maxlength="50"
                   value="<?= htmlspecialchars((string) ($branchVals['ContactNo'] ?? '')) ?>">
          </div>
          <div class="mb-3">
            <label class="form-label" for="branch_address">Branch address</label>
            <textarea class="form-control" id="branch_address" name="branch_address" rows="3"><?= htmlspecialchars((string) ($branchVals['BranchAddress'] ?? '')) ?></textarea>
            <small class="text-muted">Printed on quotations and invoices addressed to this branch.</small>
          </div>
          <div class="d-grid gap-2 d-sm-flex">
            <button class="btn btn-accent" type="submit"><?= $isNewBranch ? 'Add branch' : 'Save branch' ?></button>
            <a class="btn btn-outline-secondary" href="admin-customers.php?action=edit&id=<?= (int) $edit['ClientID'] ?>">Cancel</a>
          </div>
        </form>
      </div>
    </div>
    <div class="col-lg-5">
      <div class="report-panel">
        <div class="report-panel-head">
          <h2 class="report-panel-title">Current branches</h2>
          <p class="report-panel-sub"><?= htmlspecialchars($edit['CompanyName']) ?></p>
        </div>
        <ul class="list-unstyled small mb-0 dash-list">
          <?php foreach ($branches as $branch): ?>
          <li>
            <a class="text-decoration-none fw-semibold" href="admin-customers.php?action=branch&client=<?= (int) $edit['ClientID'] ?>&id=<?= (int) $branch['BranchID'] ?>">
              <?= htmlspecialchars($branch['BranchName']) ?>
            </a>
            <small class="text-muted d-block"><?= (int) $branch['Quotes'] ?> quotations · <?= (int) $branch['Invoices'] ?> invoices</small>
          </li>
          <?php endforeach; ?>
          <?php if (!$branches): ?>
          <li class="text-muted">This company has no branches yet.</li>
          <?php endif; ?>
        </ul>
      </div>
    </div>
  </div>

  <?php else:
      $isEdit = $action === 'edit' && $edit !== null;
      $values = $isEdit ? [
          'CompanyName'   => (string) $edit['CompanyName'],
          'ContactPerson' => (string) ($edit['ContactPerson'] ?? ''),
          'Email'         => (string) ($edit['Email'] ?? ''),
          'Phone'         => (string) ($edit['Phone'] ?? ''),
          'Address'       => (string) ($edit['Address'] ?? ''),
          'UserID'        => (int) ($edit['UserID'] ?? 0),
      ] : $form;
      $shownId = $isEdit ? (int) $edit['ClientID'] : $nextFreeId;
  ?>

  <div class="row g-4">
    <div class="col-lg-6">
      <div class="form-card">
        <div class="form-card-head">
          <h2 class="h5 mb-1"><?= $isEdit ? 'Edit company #' . (int) $edit['ClientID'] : 'New company' ?></h2>
          <p class="text-muted small mb-0">
            <?= $isEdit
                ? 'Company details appear on every quotation and invoice addressed to its branches.'
                : 'The next free key is allocated when you save; branches are added on the next screen.' ?>
          </p>
        </div>

        <form method="post" action="admin-customers.php" novalidate data-ajax>
          <?= csrf_field() ?>
          <input type="hidden" name="intent" value="<?= $isEdit ? 'update' : 'create' ?>">
          <input type="hidden" name="client_id" value="<?= $shownId ?>">

          <div class="mb-3">
            <label class="form-label" for="company_name">Company name <span class="req">*</span></label>
            <input class="form-control" id="company_name" name="company_name" maxlength="255"
                   value="<?= htmlspecialchars($values['CompanyName']) ?>" required autofocus>
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="contact_person">Contact person <span class="req">*</span></label>
              <input class="form-control" id="contact_person" name="contact_person" maxlength="255"
                     value="<?= htmlspecialchars($values['ContactPerson']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="phone">Phone</label>
              <input class="form-control" id="phone" name="phone" maxlength="50" value="<?= htmlspecialchars($values['Phone']) ?>">
            </div>
            <div class="col-12">
              <label class="form-label" for="email">Email</label>
              <input class="form-control" id="email" name="email" type="email" maxlength="255" value="<?= htmlspecialchars($values['Email']) ?>">
            </div>
            <div class="col-12">
              <label class="form-label" for="address">Address</label>
              <textarea class="form-control" id="address" name="address" rows="2"><?= htmlspecialchars($values['Address']) ?></textarea>
            </div>
          </div>

          <h3 class="form-section-title">Linked login <span class="text-muted small fw-normal">(optional, one login per company)</span></h3>
          <div class="mb-3">
            <label class="form-label" for="user_id">Customer account</label>
            <select class="form-select" id="user_id" name="user_id">
              <option value="0">— no online login —</option>
              <?php foreach ($candidates as $candidate):
                  $linkedElsewhere = (int) ($candidate['LinkedTo'] ?? 0) > 0 && (int) $candidate['LinkedTo'] !== $shownId;
              ?>
              <option value="<?= (int) $candidate['UserID'] ?>"
                  <?= $values['UserID'] === (int) $candidate['UserID'] ? 'selected' : '' ?>
                  <?= $linkedElsewhere ? 'disabled' : '' ?>>
                <?= htmlspecialchars($candidate['FullName']) ?> (<?= htmlspecialchars($candidate['Username']) ?>)
                — <?= htmlspecialchars($candidate['RoleName']) ?>
                <?= $linkedElsewhere ? '· already linked to company #' . (int) $candidate['LinkedTo'] : '' ?>
              </option>
              <?php endforeach; ?>
            </select>
            <small class="text-muted">
              Linking a login lets that customer see this company's quotations and invoices on their dashboard.
              Staff roles cannot be linked. <a href="admin-users.php?action=new">Create a customer login</a> if needed.
            </small>
          </div>

          <div class="d-grid gap-2 d-sm-flex">
            <button class="btn btn-accent" type="submit"><?= $isEdit ? 'Save company' : 'Create company' ?></button>
            <a class="btn btn-outline-secondary" href="admin-customers.php">Cancel</a>
          </div>
        </form>
      </div>
    </div>

    <div class="col-lg-6">
      <?php if (!$isEdit): ?>
      <div class="report-panel">
        <div class="report-panel-head">
          <h2 class="report-panel-title">Why branches matter</h2>
        </div>
        <ul class="list-unstyled small mb-0 dash-list">
          <li><i class="fa-solid fa-arrow-right me-2 text-muted"></i>Every <code>quotation</code> is addressed to <strong>one</strong> branch.</li>
          <li><i class="fa-solid fa-arrow-right me-2 text-muted"></i>Every <code>invoice</code> is billed to <strong>one</strong> branch.</li>
          <li><i class="fa-solid fa-arrow-right me-2 text-muted"></i>A company can therefore be invoiced at several offices without mixing their paperwork.</li>
        </ul>
      </div>
      <?php else: ?>
      <div class="report-panel">
        <div class="report-panel-head d-flex flex-wrap justify-content-between align-items-start gap-2">
          <div>
            <h2 class="report-panel-title">Branches</h2>
            <p class="report-panel-sub">Where quotations and invoices are addressed.</p>
          </div>
          <a class="btn btn-accent btn-sm" href="admin-customers.php?action=branch&client=<?= (int) $edit['ClientID'] ?>">
            <i class="fa-solid fa-plus me-1"></i>Add branch
          </a>
        </div>

        <?php if (!$branches): ?>
        <?= admin_empty_state(
            'fa-code-branch',
            'No branches yet',
            'A company needs at least one branch before a quotation or an invoice can be addressed to it.',
            '<a class="btn btn-accent btn-sm" href="admin-customers.php?action=branch&client=' . (int) $edit['ClientID'] . '">'
            . '<i class="fa-solid fa-plus me-1"></i>Add the first branch</a>'
        ) ?>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm report-th align-middle mb-0">
            <thead>
              <tr><th scope="col">#</th><th scope="col">Branch</th><th scope="col">Documents</th><th scope="col"></th></tr>
            </thead>
            <tbody>
              <?php foreach ($branches as $branch): ?>
              <tr>
                <td class="text-muted"><?= (int) $branch['BranchID'] ?></td>
                <td>
                  <span class="fw-semibold"><?= htmlspecialchars($branch['BranchName']) ?></span>
                  <?php if (!empty($branch['BranchAddress'])): ?>
                  <small class="d-block text-muted"><?= htmlspecialchars($branch['BranchAddress']) ?></small>
                  <?php endif; ?>
                  <?php if (!empty($branch['ContactNo'])): ?>
                  <small class="d-block text-muted"><i class="fa-solid fa-phone me-1"></i><?= htmlspecialchars($branch['ContactNo']) ?></small>
                  <?php endif; ?>
                </td>
                <td class="small">
                  <a class="text-decoration-none" href="admin-quotations.php?branch=<?= (int) $branch['BranchID'] ?>"><?= (int) $branch['Quotes'] ?> quotations</a><br>
                  <a class="text-decoration-none" href="admin-invoices.php?branch=<?= (int) $branch['BranchID'] ?>"><?= (int) $branch['Invoices'] ?> invoices</a>
                </td>
                <td class="admin-actions-cell">
                  <a class="btn btn-sm btn-outline-secondary" href="admin-customers.php?action=branch&client=<?= (int) $edit['ClientID'] ?>&id=<?= (int) $branch['BranchID'] ?>">
                    <i class="fa-solid fa-pen"></i><span class="visually-hidden">Edit</span></a>
                  <form method="post" action="admin-customers.php" class="d-inline"
                        onsubmit="return confirm('Delete branch “<?= htmlspecialchars($branch['BranchName'], ENT_QUOTES) ?>”?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="intent" value="branch_delete">
                    <input type="hidden" name="client_id" value="<?= (int) $edit['ClientID'] ?>">
                    <input type="hidden" name="branch_id" value="<?= (int) $branch['BranchID'] ?>">
                    <button class="btn btn-sm btn-outline-danger" type="submit"
                        <?= ((int) $branch['Quotes'] + (int) $branch['Invoices']) > 0 ? ' disabled title="Has documents on file"' : '' ?>>
                      <i class="fa-solid fa-trash-can"></i><span class="visually-hidden">Delete</span></button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

      <div class="report-panel">
        <div class="report-panel-head">
          <h2 class="report-panel-title">This company on file</h2>
        </div>
        <?php
        $totals = db_fetch_all(
            'SELECT (SELECT COUNT(*) FROM quotation q JOIN client_branch b ON b.BranchID = q.BranchID
                      WHERE b.ClientID = :id) AS Quotes,
                    (SELECT COUNT(*) FROM invoice i JOIN client_branch b ON b.BranchID = i.BranchID
                      WHERE b.ClientID = :id) AS Invoices,
                    (SELECT COALESCE(SUM(i.TotalAmount), 0) FROM invoice i JOIN client_branch b ON b.BranchID = i.BranchID
                      WHERE b.ClientID = :id) AS Billed,
                    (SELECT COALESCE(SUM(i.RemainingBalance), 0) FROM invoice i JOIN client_branch b ON b.BranchID = i.BranchID
                      WHERE b.ClientID = :id) AS Due',
            [':id' => (int) $edit['ClientID']]
        );
        $t = $totals[0] ?? [];
        ?>
        <?= admin_detail_row('Quotations', (int) ($t['Quotes'] ?? 0)) ?>
        <?= admin_detail_row('Invoices', (int) ($t['Invoices'] ?? 0)) ?>
        <?= admin_detail_row('Billed to date', money((float) ($t['Billed'] ?? 0))) ?>
        <?= admin_detail_row('Still outstanding', (float) ($t['Due'] ?? 0) > 0
            ? '<span class="text-danger fw-semibold">' . money((float) $t['Due']) . '</span>'
            : '<span class="text-success">settled</span>') ?>
        <?= admin_detail_row('Linked login', $values['UserID'] > 0
            ? htmlspecialchars((string) ($edit['UserID'])) . ' — <a href="admin-users.php?action=edit&id=' . (int) $edit['UserID'] . '">manage</a>'
            : 'none') ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php endif; ?>
</div>

<?= partial_render('partials/footer.php') ?>
