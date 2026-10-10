<?php
/**
 * SebaBD — Users & roles (back office, administrator only).
 *
 * Every login carries exactly one role (#1), and some logins are linked to a
 * company (#2 — the optional 1:1). This page is where staff accounts are
 * created, because the public registration forms deliberately only ever grant
 * customer roles.
 *
 *   admin-users.php                       every account, the role it holds, active or not
 *   admin-users.php?action=new            create a staff or customer login
 *   admin-users.php?action=edit&id=       change details, role or password
 *   POST intent=create|update|password|delete|activation
 *
 * Account state is a column, not a deletion: `user.IsActive` (0 = cannot sign
 * in). Turning it off removes the person's access on their very next request,
 * while every order, quotation, invoice and receipt that names them stays
 * readable — which is the whole reason the account is not deleted.
 *
 * Safeguards that are easy to get wrong in an admin screen:
 *   - an administrator cannot remove their own access,
 *   - the last active administrator cannot be deactivated or demoted, and
 *   - an account that still has records (orders, a linked company) is not
 *     deleted out from under them.
 */
require_once __DIR__ . '/admin-bootstrap.php';

$user   = require_module('admin-users.php');
$dbUp   = db() !== null;
// Is the activation column in place? (database/migrate-internal-workflow.sql)
// Until it is, every account lists as active and the toggle stays hidden, so
// this page keeps working on a database that has not been migrated yet.
$activationSupported = $dbUp && user_activation_supported();
// One fragment for both account queries below: on a migrated database the real
// column, otherwise a constant, so the pages read identically either way.
$activeSelect = $activationSupported ? 'u.IsActive' : '1 AS IsActive';
$action = (string) ($_GET['action'] ?? 'list');
$id     = max(0, (int) ($_GET['id'] ?? 0));
$errors = [];

$filterRole = max(0, (int) ($_GET['role'] ?? 0));
$search     = trim((string) ($_GET['q'] ?? ''));

/* ------------------------------------------------------------- the writes */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard('admin-users.php');

    $intent = post_str('intent');
    $pdo    = db();

    if (!$pdo) {
        $errors[] = 'The database is offline, so nothing can be saved right now.';
    } elseif ($intent === 'password') {
        $userId  = post_int('user_id');
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['new_password_confirm'] ?? '';

        try {
            if (!db_fetch_all('SELECT UserID FROM `user` WHERE UserID = :id', [':id' => $userId])) {
                $errors[] = 'That account no longer exists.';
            } elseif (strlen($new) < 6) {
                $errors[] = 'The new password must be at least 6 characters.';
            } elseif ($new !== $confirm) {
                $errors[] = 'The two passwords do not match.';
            } else {
                db_update($pdo, 'user', ['PasswordHash' => password_hash($new, PASSWORD_DEFAULT)], ['UserID' => $userId]);
                flash_set('success', 'Password reset for account #' . $userId . '.');
                post_success('admin-users.php?action=edit&id=' . $userId);
            }
        } catch (Throwable $e) {
            $errors[] = admin_error_message($e, 'Could not reset the password.');
        }
        $action = 'edit';
        $id     = $userId;
    } elseif ($intent === 'activation') {
        // Close or reopen an account. Deactivating is the safe alternative to
        // deleting: the person can no longer sign in, but nothing they created
        // loses its owner.
        $userId   = post_int('user_id');
        $activate = post_int('is_active') === 1;

        try {
            $target = db_fetch_all(
                'SELECT u.UserID, u.Username, u.FullName, r.RoleName
                 FROM `user` u JOIN `role` r ON r.RoleID = u.RoleID
                 WHERE u.UserID = :id',
                [':id' => $userId]
            );
            $activeAdmins = (int) (db_fetch_all(
                "SELECT COUNT(*) AS n FROM `user` u JOIN `role` r ON r.RoleID = u.RoleID
                 WHERE r.RoleName = 'Admin' AND u.IsActive = 1"
            )[0]['n'] ?? 0);

            if (!$activationSupported) {
                $errors[] = 'The account state column is missing. Run '
                          . '<code>database/migrate-internal-workflow.sql</code>, then reload this page.';
            } elseif (!$target) {
                $errors[] = 'That account no longer exists.';
            } elseif (!$activate && $userId === (int) $user['UserID']) {
                $errors[] = 'You cannot deactivate the account you are signed in with — sign in as another administrator first.';
            } elseif (!$activate && $target[0]['RoleName'] === 'Admin' && $activeAdmins <= 1) {
                $errors[] = 'Account “' . $target[0]['Username'] . '” is the only active administrator. '
                          . 'Deactivating it would leave nobody able to manage users.';
            } else {
                db_update($pdo, 'user', ['IsActive' => $activate ? 1 : 0], ['UserID' => $userId]);
                flash_set('success', 'Account “' . $target[0]['Username'] . '” was '
                    . ($activate ? 'reactivated — they can sign in again.' : 'deactivated — they can no longer sign in.'));
                post_success('admin-users.php?action=edit&id=' . $userId);
            }
        } catch (Throwable $e) {
            $errors[] = admin_error_message($e, 'Could not change the account state.');
        }
        $action = 'edit';
        $id     = $userId;
    } elseif ($intent === 'delete') {
        $userId = post_int('user_id');
        try {
            $target = db_fetch_all(
                'SELECT u.UserID, u.Username, u.FullName, r.RoleName,
                        (SELECT COUNT(*) FROM `order` o WHERE o.UserID = u.UserID) AS Orders,
                        (SELECT c.ClientID FROM client c WHERE c.UserID = u.UserID LIMIT 1) AS ClientID
                 FROM `user` u JOIN `role` r ON r.RoleID = u.RoleID
                 WHERE u.UserID = :id',
                [':id' => $userId]
            );

            if (!$target) {
                $errors[] = 'That account no longer exists.';
            } elseif ($userId === (int) $user['UserID']) {
                $errors[] = 'You cannot delete the account you are signed in with.';
            } elseif ((int) $target[0]['Orders'] > 0) {
                $errors[] = 'Account “' . $target[0]['Username'] . '” has placed ' . (int) $target[0]['Orders']
                          . ' order(s), so it cannot be deleted — the order history names it. '
                          . ($activationSupported
                              ? 'Deactivate it instead if the person has left.'
                              : 'Change the account instead if it is no longer needed.');
            } elseif ($target[0]['ClientID'] !== null) {
                // client.UserID is ON DELETE SET NULL, so deleting would silently
                // orphan the company's login. Ask for the link to be removed first.
                $errors[] = 'Account “' . $target[0]['Username'] . '” is the login for company #'
                          . (int) $target[0]['ClientID'] . '. Unlink it in '
                          . '<a class="alert-link" href="admin-customers.php?action=edit&id=' . (int) $target[0]['ClientID'] . '">that company</a>'
                          . ' before deleting the account.';
            } else {
                db_delete($pdo, 'user', ['UserID' => $userId]);
                flash_set('success', 'Account “' . $target[0]['Username'] . '” deleted.');
                post_success('admin-users.php');
            }
        } catch (Throwable $e) {
            $errors[] = admin_error_message($e, 'Could not delete that account.');
        }
        $action = 'list';
    } else {
        $editId   = post_int('user_id');
        $fullName = post_str('full_name');
        $username = post_str('username');
        $email    = post_str('email');
        $phone    = post_str('phone');
        $roleId   = post_int('role_id');
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['password_confirm'] ?? '';

        if (mb_strlen($fullName) < 3) {
            $errors[] = 'Please enter the person’s full name (at least 3 characters).';
        }
        if (!is_valid_username($username)) {
            $errors[] = username_rule_text();
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
        if (!is_valid_phone($phone)) {
            $errors[] = 'Phone number looks invalid.';
        }
        if ($editId === 0) {
            if (strlen($password) < 6) {
                $errors[] = 'Give the new account a password of at least 6 characters.';
            } elseif ($password !== $confirm) {
                $errors[] = 'The two passwords do not match.';
            }
        }

        // The role must exist, and an administrator must not lock themselves out.
        $role = null;
        if (!$errors) {
            $found = db_fetch_all('SELECT RoleID, RoleName FROM `role` WHERE RoleID = :id', [':id' => $roleId]);
            $role  = $found[0] ?? null;
            if ($role === null) {
                $errors[] = 'Choose one of the listed roles.';
            } elseif ($editId === (int) $user['UserID'] && $role['RoleName'] !== 'Admin') {
                $errors[] = 'You cannot change your own role away from Admin — sign in as another administrator first.';
            } elseif ($editId > 0 && $role['RoleName'] !== 'Admin' && $activationSupported) {
                // Demoting the last administrator would lock the back office out
                // just as surely as deactivating it.
                $wasAdmin = db_fetch_all(
                    'SELECT u.IsActive, r.RoleName FROM `user` u JOIN `role` r ON r.RoleID = u.RoleID
                     WHERE u.UserID = :id',
                    [':id' => $editId]
                );
                $activeAdmins = (int) (db_fetch_all(
                    "SELECT COUNT(*) AS n FROM `user` u JOIN `role` r ON r.RoleID = u.RoleID
                     WHERE r.RoleName = 'Admin' AND u.IsActive = 1"
                )[0]['n'] ?? 0);
                if (($wasAdmin[0]['RoleName'] ?? '') === 'Admin'
                    && (int) ($wasAdmin[0]['IsActive'] ?? 1) === 1
                    && $activeAdmins <= 1) {
                    $errors[] = 'This is the only active administrator, so it has to keep the Admin role. '
                              . 'Give another account the Admin role first.';
                }
            }
        }

        if (!$errors) {
            $dupes = db_fetch_all(
                'SELECT UserID, Username, Email FROM `user`
                 WHERE (Username = :u OR Email = :e) AND UserID <> :id',
                [':u' => $username, ':e' => $email, ':id' => $editId]
            );
            if ($dupes) {
                $field = strcasecmp($dupes[0]['Username'], $username) === 0 ? 'username' : 'email';
                $errors[] = 'Account #' . (int) $dupes[0]['UserID'] . ' already uses that ' . $field . '.';
            }
        }

        if (!$errors) {
            $data = [
                'RoleID'   => (int) $role['RoleID'],
                'Username' => $username,
                'Email'    => $email,
                'FullName' => $fullName,
                'Phone'    => $phone !== '' ? $phone : null,
            ];
            try {
                if ($editId > 0) {
                    if (!db_fetch_all('SELECT UserID FROM `user` WHERE UserID = :id', [':id' => $editId])) {
                        deny_page(404, 'Account not found', 'Account #' . $editId . ' does not exist.');
                    }
                    db_update($pdo, 'user', $data, ['UserID' => $editId]);
                    flash_set('success', 'Account “' . $username . '” updated — role ' . $role['RoleName'] . '.');
                } else {
                    $data['PasswordHash'] = password_hash($password, PASSWORD_DEFAULT);
                    $newId = db_transaction($pdo, fn(PDO $tx) => db_insert($tx, 'user', $data, 'UserID'));
                    flash_set('success', 'Account “' . $username . '” created as #' . $newId . ' (' . $role['RoleName'] . ').');
                    post_success('admin-users.php?action=edit&id=' . $newId);
                }
                post_success('admin-users.php?action=edit&id=' . $editId);
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
$roles      = [];
$nextFreeId = 0;
$totals     = [];
$userPage   = null;
$linkedTo   = 0;
$orderCount = 0;
$assignedUnits = 0;

if ($dbUp) {
    $roles = db_fetch_all(
        'SELECT r.RoleID, r.RoleName,
                (SELECT COUNT(*) FROM `user` u WHERE u.RoleID = r.RoleID) AS Accounts
         FROM `role` r ORDER BY r.RoleID'
    );

    if ($action === 'edit' && $id > 0) {
        $found = db_fetch_all(
            'SELECT u.UserID, u.RoleID, u.Username, u.Email, u.FullName, u.Phone, u.CreatedAt,
                    ' . $activeSelect . ', r.RoleName
             FROM `user` u JOIN `role` r ON r.RoleID = u.RoleID
             WHERE u.UserID = :id',
            [':id' => $id]
        );
        $edit = $found[0] ?? null;
        if ($edit === null) {
            deny_page(404, 'Account not found', 'Account #' . $id . ' does not exist.', 'admin-users.php', 'All accounts');
        }

        // What is standing in the way of deleting this account.
        $linked  = db_fetch_all('SELECT ClientID, CompanyName FROM client WHERE UserID = :id', [':id' => $id]);
        $linkedTo = $linked ? (int) $linked[0]['ClientID'] : 0;
        $orderCount = db_count('order', 'UserID = :id', [':id' => $id]);
        $assignedUnits = 0;
    }

    if ($action === 'new') {
        $preview    = db_fetch_all('SELECT COALESCE(MAX(UserID), 0) + 1 AS n FROM `user`');
        $nextFreeId = (int) ($preview[0]['n'] ?? 1);
    }

    $where  = [];
    $params = [];
    if ($filterRole > 0) {
        $where[]         = 'u.RoleID = :role';
        $params[':role'] = $filterRole;
    }
    if ($search !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        $where[] = '(u.FullName LIKE :q1 OR u.Username LIKE :q2 OR u.Email LIKE :q3)';
        $params[':q1'] = $like;
        $params[':q2'] = $like;
        $params[':q3'] = $like;
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $countRow = db_fetch_all('SELECT COUNT(*) AS n FROM `user` u' . $whereSql, $params);
    $userPage = paginate((int) ($countRow[0]['n'] ?? 0), 20);

    $rows = db_fetch_all(
        'SELECT u.UserID, u.RoleID, u.Username, u.Email, u.FullName, u.Phone, u.CreatedAt,
                ' . $activeSelect . ', r.RoleName,
                c.ClientID, c.CompanyName,
                (SELECT COUNT(*) FROM `order` o WHERE o.UserID = u.UserID) AS Orders,
                (SELECT COALESCE(SUM(o.TotalAmount), 0) FROM `order` o WHERE o.UserID = u.UserID) AS Spent
         FROM `user` u
         JOIN `role` r ON r.RoleID = u.RoleID
         LEFT JOIN client c ON c.UserID = u.UserID' . $whereSql . '
         ORDER BY ' . ($activationSupported ? 'u.IsActive' : '1') . ' DESC, u.RoleID, u.FullName
         LIMIT ' . $userPage['perPage'] . ' OFFSET ' . $userPage['offset'],
        $params
    );

    $totals = db_fetch_all(
        "SELECT (SELECT COUNT(*) FROM `user`) AS Accounts,
                (SELECT COUNT(*) FROM `user` u JOIN `role` r ON r.RoleID = u.RoleID
                  WHERE r.RoleName IN ('Admin', 'Sales Manager', 'Inventory Manager')) AS Staff,
                (SELECT COUNT(*) FROM `user` u JOIN `role` r ON r.RoleID = u.RoleID
                  WHERE r.RoleName IN ('Client Account', 'B2B Client Account')) AS Customers,
                (SELECT COUNT(*) FROM client WHERE UserID IS NOT NULL) AS Linked,
                (SELECT COUNT(*) FROM `user` u WHERE " . ($activationSupported ? 'u.IsActive = 0' : '1 = 0') . ") AS Disabled"
    );
    $totals = $totals[0] ?? [];
}

ajax_errors($errors);

$filterQs = http_build_query(array_filter(['role' => $filterRole ?: null, 'q' => $search]));
$listUrl  = 'admin-users.php' . ($filterQs !== '' ? '?' . $filterQs : '');

echo partial_render('partials/header.php', ['page_title' => 'Users & roles', 'active_nav' => 'admin']);
echo admin_lead('Users &amp; roles', 'Every login holds exactly one role, and a customer login may additionally be linked to one company. Staff roles are granted here and nowhere else.');
?>

<div class="container pb-5">
  <?= render_flashes() ?>

  <?php if (!$dbUp): ?>
  <div class="alert alert-danger mb-0">
    <i class="fa-solid fa-plug-circle-xmark me-2"></i>
    The database is offline, so accounts cannot be listed. Start MySQL in the XAMPP control panel and reload.
  </div>
  <?php else: ?>

  <?= admin_tabs([
      'All accounts' => 'admin-users.php',
      'New account'  => 'admin-users.php?action=new',
  ], $action === 'list' ? 'All accounts' : ($action === 'new' ? 'New account' : '')) ?>

  <div class="ajax-errors" data-ajax-errors>
    <?php if ($errors): ?>
    <div class="alert alert-danger">
      <ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= $err ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($action === 'list'): ?>

  <div class="row g-3 mb-4">
    <?= report_kpi('Accounts', number_format((int) ($totals['Accounts'] ?? 0)), 'Every login in the system', 'fa-users') ?>
    <?= report_kpi('Staff', number_format((int) ($totals['Staff'] ?? 0)), 'Admin, sales and inventory', 'fa-user-shield') ?>
    <?= report_kpi('Customers', number_format((int) ($totals['Customers'] ?? 0)), 'Retail and corporate logins', 'fa-user-tie') ?>
    <?= report_kpi('Linked to a company', number_format((int) ($totals['Linked'] ?? 0)), 'The optional 1:1 in the schema', 'fa-link') ?>
    <?= report_kpi('Deactivated', number_format((int) ($totals['Disabled'] ?? 0)), 'Sign-in closed, history kept', 'fa-user-slash') ?>
  </div>

  <?php if (!$activationSupported): ?>
  <div class="alert alert-warning">
    <i class="fa-solid fa-database me-2"></i>
    Accounts cannot be deactivated yet: the <code>user</code> table has no <code>IsActive</code> column.
    Run <code>database/migrate-internal-workflow.sql</code> once, then reload this page.
  </div>
  <?php endif; ?>

  <form class="report-filter mb-3" method="get" action="admin-users.php">
    <div class="report-filter-fields">
      <label class="report-filter-field">
        <span class="form-label">Name, username or email</span>
        <input class="form-control form-control-sm" type="search" name="q" value="<?= htmlspecialchars($search) ?>">
      </label>
      <label class="report-filter-field">
        <span class="form-label">Role</span>
        <select class="form-select form-select-sm" name="role">
          <option value="0">Every role</option>
          <?php foreach ($roles as $role): ?>
          <option value="<?= (int) $role['RoleID'] ?>" <?= $filterRole === (int) $role['RoleID'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($role['RoleName']) ?> (<?= (int) $role['Accounts'] ?>)
          </option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <div class="report-filter-actions">
      <button class="btn btn-accent btn-sm" type="submit"><i class="fa-solid fa-filter me-1"></i>Apply</button>
      <a class="btn btn-outline-secondary btn-sm" href="admin-users.php">Reset</a>
    </div>
  </form>

  <?= partial_render('partials/admin-list.php', [
      'panel_title' => 'Accounts',
      'panel_sub'   => 'Grouped by role. A customer account can be linked to one company; staff accounts never are.',
      'columns'     => ['#', 'Person', 'Contact', 'Role', 'State', 'Company', 'Orders', ''],
      'rows'        => array_map(function ($row) use ($user, $activationSupported) {
          $isSelf = (int) $row['UserID'] === (int) $user['UserID'];
          $active = (int) ($row['IsActive'] ?? 1) === 1;

          return [
              '<span class="text-muted">' . (int) $row['UserID'] . '</span>',
              '<a class="fw-semibold text-decoration-none" href="admin-users.php?action=edit&id=' . (int) $row['UserID'] . '">'
                  . htmlspecialchars($row['FullName']) . '</a>'
                  . '<small class="d-block text-muted">' . htmlspecialchars($row['Username'])
                  . ($isSelf ? ' · <span class="text-success">you</span>' : '') . '</small>',
              htmlspecialchars($row['Email'])
                  . ($row['Phone'] ? '<small class="d-block text-muted">' . htmlspecialchars($row['Phone']) . '</small>' : ''),
              status_badge((string) $row['RoleName'], htmlspecialchars((string) $row['RoleName'])),
              $active
                  ? '<span class="report-badge report-badge-ok">Active</span>'
                  : '<span class="report-badge report-badge-danger">Deactivated</span>',
              $row['ClientID'] !== null
                  ? '<a class="text-decoration-none" href="admin-customers.php?action=edit&id=' . (int) $row['ClientID'] . '">'
                      . htmlspecialchars((string) $row['CompanyName']) . '</a>'
                  : '<span class="text-muted small">—</span>',
              (int) $row['Orders'] > 0
                  ? '<a class="text-decoration-none" href="admin-orders.php?q=' . urlencode($row['Username']) . '">'
                      . (int) $row['Orders'] . ' order' . ((int) $row['Orders'] === 1 ? '' : 's') . '</a>'
                      . '<small class="d-block text-muted">' . money($row['Spent']) . '</small>'
                  : '<span class="text-muted small">none</span>',
              '<div class="admin-actions-cell">'
                  . '<a class="btn btn-sm btn-outline-secondary" href="admin-users.php?action=edit&id=' . (int) $row['UserID'] . '">'
                      . '<i class="fa-solid fa-pen"></i><span class="visually-hidden">Edit</span></a> '
                  . ($activationSupported
                      ? '<form method="post" action="admin-users.php" class="d-inline"'
                          . ' onsubmit="return confirm(\'' . ($active ? 'Deactivate' : 'Reactivate') . ' account “'
                          . htmlspecialchars($row['Username'], ENT_QUOTES) . '”?\');">'
                          . csrf_field()
                          . '<input type="hidden" name="intent" value="activation">'
                          . '<input type="hidden" name="user_id" value="' . (int) $row['UserID'] . '">'
                          . '<input type="hidden" name="is_active" value="' . ($active ? 0 : 1) . '">'
                          . '<button class="btn btn-sm btn-outline-' . ($active ? 'warning' : 'success') . '" type="submit"'
                          . ($isSelf ? ' disabled title="You cannot deactivate your own account"' : '')
                          . '><i class="fa-solid fa-' . ($active ? 'user-slash' : 'user-check') . '"></i>'
                          . '<span class="visually-hidden">' . ($active ? 'Deactivate' : 'Reactivate') . '</span></button>'
                          . '</form> '
                      : '')
                  . '<form method="post" action="admin-users.php" class="d-inline"'
                      . ' onsubmit="return confirm(\'Delete account “' . htmlspecialchars($row['Username'], ENT_QUOTES) . '”?\');">'
                      . csrf_field()
                      . '<input type="hidden" name="intent" value="delete">'
                      . '<input type="hidden" name="user_id" value="' . (int) $row['UserID'] . '">'
                      . '<button class="btn btn-sm btn-outline-danger" type="submit"'
                      . ($isSelf ? ' disabled title="You cannot delete your own account"' : '')
                      . '><i class="fa-solid fa-trash-can"></i><span class="visually-hidden">Delete</span></button>'
                  . '</form>'
                  . '</div>',
          ];
      }, $rows),
      'empty_icon'   => 'fa-users',
      'empty_title'  => $filterRole > 0 || $search !== '' ? 'No accounts matched' : 'No accounts yet',
      'empty_text'   => $filterRole > 0 || $search !== ''
          ? 'Try another role or clear the search box.'
          : 'Create the first staff login, or let customers register themselves on the storefront.',
      'empty_action' => $filterRole > 0 || $search !== ''
          ? '<a class="btn btn-outline-secondary btn-sm" href="admin-users.php">Clear filters</a>'
          : '<a class="btn btn-accent btn-sm" href="admin-users.php?action=new"><i class="fa-solid fa-plus me-1"></i>New account</a>',
      'actions'      => admin_actions([['href' => 'admin-users.php?action=new', 'label' => 'New account', 'icon' => 'fa-plus']]),
      'summary'      => pagination_summary($userPage, 'account'),
      'pagination'   => pagination_links($userPage, $listUrl),
      'note'         => '<code>user.RoleID</code> points at exactly one <code>role</code> row, and <code>client.UserID</code> is a '
                      . '<code>UNIQUE</code> key — which is how “one login, at most one company” is enforced by the database rather '
                      . 'than by this page. Deactivating sets <code>user.IsActive = 0</code>: the account keeps every order and '
                      . 'document it created, but the person cannot sign in.',
  ]) ?>

  <?php else:
      $isEdit = $action === 'edit' && $edit !== null;
      $values = $isEdit ? [
          'FullName' => (string) $edit['FullName'],
          'Username' => (string) $edit['Username'],
          'Email'    => (string) $edit['Email'],
          'Phone'    => (string) ($edit['Phone'] ?? ''),
          'RoleID'   => (int) $edit['RoleID'],
      ] : [
          'FullName' => post_str('full_name'),
          'Username' => post_str('username'),
          'Email'    => post_str('email'),
          'Phone'    => post_str('phone'),
          'RoleID'   => post_int('role_id', ROLE_CLIENT_ACCOUNT),
      ];
      $shownId = $isEdit ? (int) $edit['UserID'] : $nextFreeId;
      $isSelf  = $isEdit && $shownId === (int) $user['UserID'];
      $editIsActive = $isEdit && (int) ($edit['IsActive'] ?? 1) === 1;
  ?>

  <div class="row g-4">
    <div class="col-lg-7">
      <div class="form-card">
        <div class="form-card-head">
          <h2 class="h5 mb-1"><?= $isEdit ? 'Account #' . (int) $edit['UserID'] . ' — ' . htmlspecialchars($edit['Username']) : 'New account' ?></h2>
          <p class="text-muted small mb-0">
            <?= $isEdit
                ? 'Created ' . format_date($edit['CreatedAt']) . '. Changing the role changes what the person can open immediately.'
                : 'Set up a login for a staff member or a customer. Passwords are stored hashed, never in plain text.' ?>
          </p>
        </div>

        <?php if ($isSelf): ?>
        <div class="alert alert-light border">
          <i class="fa-solid fa-circle-info me-1"></i>
          This is the account you are signed in with, so its role is locked to Admin and it cannot be deleted
          or deactivated.
        </div>
        <?php endif; ?>

        <?php if ($isEdit && !$editIsActive): ?>
        <div class="alert alert-warning">
          <i class="fa-solid fa-user-slash me-2"></i>
          <strong>This account is deactivated.</strong> It cannot sign in, and a session it already holds is ended on
          its next request. Everything it created — orders, quotations, invoices, receipts — is untouched.
        </div>
        <?php endif; ?>

        <form method="post" action="admin-users.php" novalidate data-ajax>
          <?= csrf_field() ?>
          <input type="hidden" name="intent" value="<?= $isEdit ? 'update' : 'create' ?>">
          <input type="hidden" name="user_id" value="<?= $shownId ?>">

          <div class="row g-3">
            <div class="col-md-7">
              <label class="form-label" for="full_name">Full name <span class="req">*</span></label>
              <input class="form-control" id="full_name" name="full_name" value="<?= htmlspecialchars($values['FullName']) ?>" required autofocus>
            </div>
            <div class="col-md-5">
              <label class="form-label" for="phone">Phone</label>
              <input class="form-control" id="phone" name="phone" value="<?= htmlspecialchars($values['Phone']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="username">Username <span class="req">*</span></label>
              <input class="form-control" id="username" name="username" value="<?= htmlspecialchars($values['Username']) ?>" required>
              <small class="text-muted">Letters, numbers, dot and underscore.</small>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="email">Email <span class="req">*</span></label>
              <input class="form-control" id="email" name="email" type="email" value="<?= htmlspecialchars($values['Email']) ?>" required>
            </div>
            <div class="col-12">
              <label class="form-label" for="role_id">Role <span class="req">*</span></label>
              <select class="form-select" id="role_id" name="role_id" required <?= $isSelf ? 'disabled' : '' ?>>
                <?php foreach ($roles as $role): ?>
                <option value="<?= (int) $role['RoleID'] ?>" <?= $values['RoleID'] === (int) $role['RoleID'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($role['RoleName']) ?> — <?= (int) $role['Accounts'] ?> account(s)
                </option>
                <?php endforeach; ?>
              </select>
              <?php if ($isSelf): ?>
              <input type="hidden" name="role_id" value="<?= (int) $values['RoleID'] ?>">
              <?php endif; ?>
              <small class="text-muted">
                Staff roles (Admin, Sales Manager, Inventory Manager) can open the back office and the reports;
                customer roles see the storefront and their own documents only.
              </small>
            </div>
          </div>

          <?php if (!$isEdit): ?>
          <h3 class="form-section-title">Password</h3>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="password">Password <span class="req">*</span></label>
              <input class="form-control" id="password" name="password" type="password" minlength="6" required>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="password_confirm">Confirm password <span class="req">*</span></label>
              <input class="form-control" id="password_confirm" name="password_confirm" type="password" minlength="6" required>
            </div>
          </div>
          <?php endif; ?>

          <div class="d-grid gap-2 d-sm-flex mt-3">
            <button class="btn btn-accent" type="submit"><?= $isEdit ? 'Save account' : 'Create account' ?></button>
            <a class="btn btn-outline-secondary" href="admin-users.php">Cancel</a>
          </div>
        </form>
      </div>

      <?php if ($isEdit): ?>
      <div class="form-card">
        <div class="form-card-head">
          <h2 class="h5 mb-1">Reset password</h2>
          <p class="text-muted small mb-0">
            Use this when someone is locked out. The new password is hashed with PHP’s <code>password_hash()</code>
            and the old one stops working immediately.
          </p>
        </div>
        <form method="post" action="admin-users.php" novalidate data-ajax>
          <?= csrf_field() ?>
          <input type="hidden" name="intent" value="password">
          <input type="hidden" name="user_id" value="<?= (int) $edit['UserID'] ?>">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="new_password">New password <span class="req">*</span></label>
              <input class="form-control" id="new_password" name="new_password" type="password" minlength="6" required>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="new_password_confirm">Confirm <span class="req">*</span></label>
              <input class="form-control" id="new_password_confirm" name="new_password_confirm" type="password" minlength="6" required>
            </div>
          </div>
          <button class="btn btn-outline-dark mt-3" type="submit"><i class="fa-solid fa-key me-1"></i>Set new password</button>
        </form>
      </div>
      <?php endif; ?>
    </div>

    <div class="col-lg-5">
      <?php if ($isEdit): ?>
      <div class="report-panel">
        <div class="report-panel-head">
          <h2 class="report-panel-title">What depends on this account</h2>
          <p class="report-panel-sub">What would be affected if it were removed.</p>
        </div>
        <?= admin_detail_row('Role', status_badge((string) $edit['RoleName'], htmlspecialchars((string) $edit['RoleName']))) ?>
        <?= admin_detail_row('Orders placed', $orderCount > 0
            ? '<a href="admin-orders.php?q=' . urlencode((string) $edit['Username']) . '">' . $orderCount . ' order(s)</a>'
            : 'none') ?>
        <?= admin_detail_row('Linked company', $linkedTo > 0
            ? '<a href="admin-customers.php?action=edit&id=' . $linkedTo . '">Company #' . $linkedTo . '</a>'
            : 'none') ?>
        <?= admin_detail_row('Created', format_date($edit['CreatedAt'])) ?>
        <?= admin_detail_row('Can open', implode(' · ', array_map('htmlspecialchars', in_array($edit['RoleName'], staff_roles(), true)
            ? ['Storefront', 'Dashboard', 'Back office', 'Reports']
            : ['Storefront', 'Dashboard', 'Own documents']))) ?>
        <?= admin_detail_row('Account state', $editIsActive
            ? '<span class="report-badge report-badge-ok">Active — may sign in</span>'
            : '<span class="report-badge report-badge-danger">Deactivated — sign-in closed</span>') ?>

        <?php if ($activationSupported && !$isSelf): ?>
        <form method="post" action="admin-users.php" class="mt-3" data-ajax>
          <?= csrf_field() ?>
          <input type="hidden" name="intent" value="activation">
          <input type="hidden" name="user_id" value="<?= (int) $edit['UserID'] ?>">
          <input type="hidden" name="is_active" value="<?= $editIsActive ? 0 : 1 ?>">
          <button class="btn btn-<?= $editIsActive ? 'outline-warning' : 'outline-success' ?> w-100" type="submit">
            <i class="fa-solid fa-<?= $editIsActive ? 'user-slash' : 'user-check' ?> me-2"></i>
            <?= $editIsActive ? 'Deactivate this account' : 'Reactivate this account' ?>
          </button>
          <p class="text-muted small mb-0 mt-2">
            <?= $editIsActive
                ? 'Deactivation keeps the account and its history, but closes sign-in for good until someone reopens it.'
                : 'Sign-in works again immediately, with the password already on file.' ?>
          </p>
        </form>
        <?php elseif ($activationSupported && $isSelf): ?>
        <p class="text-muted small mb-0 mt-3">
          Your own account stays active. Another administrator can close it if you leave.
        </p>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <div class="report-panel">
        <div class="report-panel-head">
          <h2 class="report-panel-title">Roles in this system</h2>
        </div>
        <div class="table-responsive">
          <table class="table table-sm report-th align-middle mb-0">
            <thead><tr><th scope="col">Role</th><th scope="col" class="text-end">Accounts</th></tr></thead>
            <tbody>
              <?php foreach ($roles as $role): ?>
              <tr>
                <td><?= status_badge((string) $role['RoleName'], htmlspecialchars((string) $role['RoleName'])) ?></td>
                <td class="text-end"><?= (int) $role['Accounts'] ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="text-muted small mb-0 mt-3">
          Roles come from the <code>role</code> table, so a new one can be added in SQL and it appears here immediately.
        </p>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php endif; ?>
</div>

<?= partial_render('partials/footer.php') ?>
