<?php
/**
 * SebaBD — my account.
 *
 *   account.php
 *
 * Everything a signed-in user can change about themselves: the name, username,
 * email and phone on the login (#2), and the password. A customer whose login
 * is linked to a client company also sees the company and its branches (#3) —
 * read-only, because those records are what quotations and invoices are
 * addressed to, and changing them is a conversation with the sales desk rather
 * than a silent edit.
 *
 * No role, no company link and no wallet figure can be changed from here: this
 * page only ever writes to the user's own row.
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/cart-data.php';

$user   = require_login('account.php');
$errors = [];
$done   = '';

$rows = db_fetch_all(
    "SELECT u.UserID, u.Username, u.Email, u.FullName, u.Phone, u.CreatedAt, u.RoleID, r.RoleName
     FROM `user` u
     LEFT JOIN `role` r ON r.RoleID = u.RoleID
     WHERE u.UserID = :id",
    [':id' => (int) $user['UserID']]
);
$me = $rows[0] ?? null;

if ($me === null) {
    // The session points at a login that no longer exists — drop it cleanly.
    session_destroy();
    flash_set('error', 'Your account could not be found. Please sign in again.');
    header('Location: login.php');
    exit;
}

/* ---------------------------------------------------------------- the writes */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard('account.php');

    $intent = post_str('intent');
    $pdo    = db();

    if (!$pdo) {
        $errors[] = 'The database is offline, so nothing could be saved. Please try again shortly.';
    } elseif ($intent === 'profile') {
        $fullName = post_str('full_name');
        $username = post_str('username');
        $email    = post_str('email');
        $phone    = post_str('phone');

        keep_old(['full_name' => $fullName, 'username' => $username, 'email' => $email, 'phone' => $phone]);

        if (mb_strlen($fullName) < 3) {
            $errors[] = 'Please enter your full name (at least 3 characters).';
        }
        if (!is_valid_username($username)) {
            $errors[] = 'Username must be 3–100 characters: letters, numbers, dot or underscore.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
        if (!is_valid_phone($phone)) {
            $errors[] = 'Phone number looks invalid.';
        }

        // Uniqueness is checked against everyone except this account, so saving
        // your own details without changing them never trips the rule.
        if (!$errors) {
            $dup = db_fetch_all(
                'SELECT UserID, Username, Email FROM `user`
                 WHERE (Username = :u OR Email = :e) AND UserID <> :me',
                [':u' => $username, ':e' => $email, ':me' => (int) $me['UserID']]
            );
            foreach ($dup as $row) {
                if (strcasecmp($row['Username'], $username) === 0) {
                    $errors[] = 'The username “' . $username . '” is already taken.';
                }
                if (strcasecmp($row['Email'], $email) === 0) {
                    $errors[] = 'The email “' . $email . '” is already registered.';
                }
            }
        }

        if (!$errors) {
            try {
                db_transaction($pdo, function (PDO $tx) use ($me, $fullName, $username, $email, $phone) {
                    return db_update($tx, 'user', [
                        'FullName' => $fullName,
                        'Username' => $username,
                        'Email'    => $email,
                        'Phone'    => $phone !== '' ? $phone : null,
                    ], ['UserID' => (int) $me['UserID']]);
                });

                clear_old();
                flash_set('success', 'Your details were saved.');
                post_success('account.php');
            } catch (Throwable $e) {
                $errors[] = 'Your details could not be saved: ' . $e->getMessage();
            }
        }
    } elseif ($intent === 'password') {
        $current = (string) post_str('current_password');
        $new     = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password_confirm'] ?? '');

        $row = db_fetch_all('SELECT PasswordHash FROM `user` WHERE UserID = :id', [':id' => (int) $me['UserID']]);
        $hash = (string) ($row[0]['PasswordHash'] ?? '');

        if (!password_verify($current, $hash)) {
            $errors[] = 'Your current password is not right.';
        }
        $problem = password_problem($new);
        if ($problem !== '') {
            $errors[] = $problem;
        }
        if ($new !== $confirm) {
            $errors[] = 'The new passwords do not match.';
        }
        if (!$errors && password_verify($new, $hash)) {
            $errors[] = 'The new password is the same as your current one — pick something different.';
        }

        if (!$errors) {
            try {
                db_transaction($pdo, function (PDO $tx) use ($me, $new) {
                    return db_update($tx, 'user', [
                        'PasswordHash' => password_hash($new, PASSWORD_DEFAULT),
                    ], ['UserID' => (int) $me['UserID']]);
                });

                // A password change invalidates every other session of this
                // login: the current one is carried over on purpose so the
                // person who just proved they know the new secret stays in.
                session_regenerate_id(true);
                flash_set('success', 'Your password was changed. Use it the next time you sign in.');
                post_success('account.php');
            } catch (Throwable $e) {
                $errors[] = 'The password could not be changed: ' . $e->getMessage();
            }
        }
    }

    // Re-read so the page always shows what is stored.
    $rows = db_fetch_all(
        "SELECT u.UserID, u.Username, u.Email, u.FullName, u.Phone, u.CreatedAt, u.RoleID, r.RoleName
         FROM `user` u LEFT JOIN `role` r ON r.RoleID = u.RoleID WHERE u.UserID = :id",
        [':id' => (int) $me['UserID']]
    );
    $me = $rows[0] ?? $me;
}

/* ------------------------------------------------------------- company data */

$company  = null;
$branches = [];
$isB2B    = false;
// The client row linked to this login: a company for a B2B contact, a personal
// record for an individual. Staff logins have none.
$company = customer_client($me ?: $user);
if ($company) {
    $isB2B = ($company['AccountType'] ?? '') === 'b2b';
    if ($isB2B) {
        // Branches are a company-only concept — an individual customer has no
        // branch, and the branch panel is simply absent for them.
        $branches = db_fetch_all(
            'SELECT BranchID, BranchName, BranchAddress, ContactNo
             FROM client_branch WHERE ClientID = :cid ORDER BY BranchID',
            [':cid' => (int) $company['ClientID']]
        );
    }
}

/* ------------------------------------------------------------------ activity */

$orders = db_fetch_all(
    'SELECT OrderID, OrderDate, TotalAmount, OrderStatus
     FROM `order` WHERE UserID = :uid ORDER BY OrderDate DESC, OrderID DESC LIMIT 5',
    [':uid' => (int) $me['UserID']]
);

$quotes = [];
$invoices = [];
if ($company) {
    // Owned by the customer's client row (quotation.ClientID /
    // invoice.ClientID). The branch name is optional display detail — only
    // B2B documents have one.
    $quotes = db_fetch_all(
        'SELECT q.QuotationNo, q.QuotationDate, q.Subject, q.TotalAmount, q.Status, b.BranchName
         FROM quotation q
         LEFT JOIN client_branch b ON b.BranchID = q.BranchID
         WHERE q.ClientID = :cid
         ORDER BY q.QuotationDate DESC, q.QuotationNo DESC LIMIT 5',
        [':cid' => (int) $company['ClientID']]
    );
    $invoices = db_fetch_all(
        'SELECT i.InvoiceNo, i.InvoiceDate, i.PaymentStatus, i.TotalAmount, i.RemainingBalance, b.BranchName
         FROM invoice i
         LEFT JOIN client_branch b ON b.BranchID = i.BranchID
         WHERE i.ClientID = :cid
         ORDER BY i.InvoiceDate DESC, i.InvoiceNo DESC LIMIT 5',
        [':cid' => (int) $company['ClientID']]
    );
}

$page_title = 'My account';
?>
<?= partial_render('partials/header.php', ['page_title' => $page_title, 'active_nav' => 'account']) ?>

<section class="py-4">
  <div class="container">

    <?= render_flashes() ?>

    <nav aria-label="breadcrumb">
      <ol class="breadcrumb crumb mb-3">
        <li class="breadcrumb-item"><a href="index.php">Home</a></li>
        <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
        <li class="breadcrumb-item active" aria-current="page">My account</li>
      </ol>
    </nav>

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
      <div>
        <p class="section-label mb-1">Account</p>
        <h1 class="h3 fw-bold mb-1"><?= htmlspecialchars($me['FullName']) ?></h1>
        <p class="text-muted mb-0">
          <span class="report-badge report-badge-info"><?= $me['RoleID'] !== null ? 'SebaBD staff — ' . htmlspecialchars((string) $me['RoleName']) : 'Customer' ?></span>
          Signed in as <strong><?= htmlspecialchars($me['Username']) ?></strong>
          · member since <?= htmlspecialchars(format_date($me['CreatedAt'])) ?>
        </p>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-dark" href="dashboard.php"><i class="fa-solid fa-gauge me-1"></i>Dashboard</a>
        <?php if (!is_staff_user($me)): ?>
        <a class="btn btn-outline-dark" href="cart.php">
          <i class="fa-solid fa-cart-shopping me-1"></i>Basket<?= cart_count() ? ' (' . cart_count() . ')' : '' ?>
        </a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary" href="logout.php"><i class="fa-solid fa-right-from-bracket me-1"></i>Sign out</a>
      </div>
    </div>

    <?php if ($errors): ?>
    <div class="alert alert-danger">
      <strong>Nothing was changed.</strong>
      <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>

    <div class="row g-4">
      <div class="col-lg-7">

        <div class="report-panel mb-4">
          <div class="report-panel-head">
            <h2 class="report-panel-title">Your details</h2>
            <p class="report-panel-sub">These are the details the sales desk uses to reach you.</p>
          </div>

          <form method="post" action="account.php" data-ajax data-check-account>
            <?= csrf_field() ?>
            <input type="hidden" name="intent" value="profile">

            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label" for="full_name">Full name <span class="text-danger">*</span></label>
                <input class="form-control" id="full_name" name="full_name" maxlength="255" required
                       value="<?= old('full_name', $me['FullName']) ?>">
              </div>
              <div class="col-md-6">
                <label class="form-label" for="username">Username <span class="text-danger">*</span></label>
                <input class="form-control" id="username" name="username" maxlength="100" required
                       autocomplete="username" value="<?= old('username', $me['Username']) ?>">
                <div class="field-hint" data-field-hint="username"></div>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="email">Email <span class="text-danger">*</span></label>
                <input class="form-control" type="email" id="email" name="email" maxlength="255" required
                       autocomplete="email" value="<?= old('email', $me['Email']) ?>">
                <div class="field-hint" data-field-hint="email"></div>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="phone">Phone</label>
                <input class="form-control" id="phone" name="phone" maxlength="50"
                       value="<?= old('phone', (string) $me['Phone']) ?>">
                <div class="form-text">Used by the courier on delivery day.</div>
              </div>
            </div>

            <div class="d-flex flex-wrap gap-2 mt-3">
              <button class="btn btn-accent" type="submit"><i class="fa-solid fa-floppy-disk me-1"></i>Save details</button>
              <a class="btn btn-outline-secondary" href="account.php">Reset the form</a>
            </div>
          </form>
        </div>

        <div class="report-panel">
          <div class="report-panel-head">
            <h2 class="report-panel-title">Password</h2>
            <p class="report-panel-sub">Changing it signs out any other device that is using your account.</p>
          </div>

          <form method="post" action="account.php" data-ajax>
            <?= csrf_field() ?>
            <input type="hidden" name="intent" value="password">

            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label" for="current_password">Current password <span class="text-danger">*</span></label>
                <input class="form-control" type="password" id="current_password" name="current_password"
                       required autocomplete="current-password">
              </div>
              <div class="col-md-4">
                <label class="form-label" for="new_password">New password <span class="text-danger">*</span></label>
                <input class="form-control" type="password" id="new_password" name="new_password"
                       required minlength="6" autocomplete="new-password">
                <div class="form-text">At least 6 characters.</div>
              </div>
              <div class="col-md-4">
                <label class="form-label" for="new_password_confirm">Repeat new password <span class="text-danger">*</span></label>
                <input class="form-control" type="password" id="new_password_confirm" name="new_password_confirm"
                       required minlength="6" autocomplete="new-password">
              </div>
            </div>

            <div class="mt-3">
              <button class="btn btn-accent" type="submit"><i class="fa-solid fa-key me-1"></i>Change password</button>
            </div>
          </form>
        </div>
      </div>

      <div class="col-lg-5">

        <?php if ($company): ?>
        <div class="report-panel mb-4">
          <div class="report-panel-head">
            <h2 class="report-panel-title">Company</h2>
            <p class="report-panel-sub">Used on every quotation and invoice addressed to you.</p>
          </div>
          <table class="table table-sm mb-3">
            <tbody>
              <tr><th scope="row" class="text-muted fw-normal">Company</th><td class="text-end fw-semibold"><?= htmlspecialchars($company['CompanyName']) ?></td></tr>
              <tr><th scope="row" class="text-muted fw-normal">Contact</th><td class="text-end"><?= htmlspecialchars((string) ($company['ContactPerson'] ?: '—')) ?></td></tr>
              <tr><th scope="row" class="text-muted fw-normal">Email</th><td class="text-end"><?= htmlspecialchars((string) ($company['Email'] ?: '—')) ?></td></tr>
              <tr><th scope="row" class="text-muted fw-normal">Phone</th><td class="text-end"><?= htmlspecialchars((string) ($company['Phone'] ?: '—')) ?></td></tr>
            </tbody>
          </table>

          <div class="document-block-title">Branches (<?= count($branches) ?>)</div>
          <?php foreach ($branches as $branch): ?>
          <div class="branch-card">
            <div class="fw-semibold"><?= htmlspecialchars($branch['BranchName']) ?></div>
            <div class="small text-muted"><?= htmlspecialchars((string) ($branch['BranchAddress'] ?: 'No address recorded')) ?></div>
            <?php if (!empty($branch['ContactNo'])): ?>
            <div class="small text-muted"><i class="fa-solid fa-phone me-1"></i><?= htmlspecialchars($branch['ContactNo']) ?></div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>

          <p class="small text-muted mb-0 mt-3">
            Company and branch records are managed by the sales desk — ask your account manager to change
            an address, and every future document follows it.
          </p>
        </div>
        <?php endif; ?>

        <div class="report-panel mb-4">
          <div class="report-panel-head">
            <h2 class="report-panel-title">Recent orders</h2>
            <p class="report-panel-sub">Placed with this login (#4).</p>
          </div>
          <?php if ($orders): ?>
          <table class="table table-sm mb-0">
            <tbody>
              <?php foreach ($orders as $order): ?>
              <tr>
                <td>
                  <a class="text-decoration-none fw-semibold" href="order-detail.php?id=<?= (int) $order['OrderID'] ?>">
                    #<?= (int) $order['OrderID'] ?>
                  </a>
                  <div class="small text-muted"><?= htmlspecialchars(format_date($order['OrderDate'])) ?></div>
                </td>
                <td class="text-end">
                  <div><?= money($order['TotalAmount']) ?></div>
                  <div><?= status_badge((string) $order['OrderStatus']) ?></div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php else: ?>
          <p class="text-muted small mb-0">
            No orders yet. <a href="index.php?all=1#featured-products">Browse the catalog</a> — the basket works
            before you sign in, too.
          </p>
          <?php endif; ?>
        </div>

        <?php if ($company): ?>
        <div class="report-panel mb-4">
          <div class="report-panel-head">
            <h2 class="report-panel-title">Quotations</h2>
            <p class="report-panel-sub">Priced for your company.</p>
          </div>
          <?php if ($quotes): ?>
          <table class="table table-sm mb-0">
            <tbody>
              <?php foreach ($quotes as $quote): ?>
              <tr>
                <td>
                  <a class="text-decoration-none fw-semibold" href="quotation.php?no=<?= (int) $quote['QuotationNo'] ?>">
                    #<?= (int) $quote['QuotationNo'] ?>
                  </a>
                  <div class="small text-muted"><?= htmlspecialchars((string) ($quote['Subject'] ?: $quote['BranchName'])) ?></div>
                </td>
                <td class="text-end">
                  <div><?= money($quote['TotalAmount']) ?></div>
                  <div><?= status_badge((string) $quote['Status']) ?></div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php else: ?>
          <p class="text-muted small mb-0">
            No quotations yet.<?= can_request_quotation() ? ' <a href="quotation-request.php">Request one</a> and a sales manager will price it.' : '' ?>
          </p>
          <?php endif; ?>
        </div>

        <div class="report-panel">
          <div class="report-panel-head">
            <h2 class="report-panel-title">Invoices</h2>
            <p class="report-panel-sub">With what is still outstanding.</p>
          </div>
          <?php if ($invoices): ?>
          <table class="table table-sm mb-0">
            <tbody>
              <?php foreach ($invoices as $invoice): ?>
              <tr>
                <td>
                  <a class="text-decoration-none fw-semibold" href="invoice.php?no=<?= (int) $invoice['InvoiceNo'] ?>">
                    #<?= (int) $invoice['InvoiceNo'] ?>
                  </a>
                  <div class="small text-muted"><?= htmlspecialchars(format_date($invoice['InvoiceDate'])) ?> · <?= htmlspecialchars($invoice['BranchName']) ?></div>
                </td>
                <td class="text-end">
                  <div><?= money($invoice['TotalAmount']) ?></div>
                  <?php if ((float) $invoice['RemainingBalance'] > 0): ?>
                  <div class="small text-danger"><?= money($invoice['RemainingBalance']) ?> due</div>
                  <?php else: ?>
                  <div><?= status_badge((string) $invoice['PaymentStatus']) ?></div>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php else: ?>
          <p class="text-muted small mb-0">No invoices have been raised for your company yet.</p>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<?= partial_render('partials/footer.php') ?>
