<?php
require_once __DIR__ . '/helpers.php';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard('register.php');

    $fullName = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['password_confirm'] ?? '';

    keep_old(array_filter([
        'full_name' => $fullName, 'username' => $username,
        'email' => $email, 'phone' => $phone,
    ]));

    // ---- validation -------------------------------------------------------
    if ($fullName === '' || mb_strlen($fullName) < 3) {
        $errors[] = 'Please enter your full name (at least 3 characters).';
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
    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }

    // Staff accounts are created by the administrator in the back office; a
    // public registration creates a customer. The customer identity itself —
    // the individual's client record — is created together with the login in
    // the transaction below. The login holds no role (user.RoleID is NULL:
    // only SebaBD staff have roles).

    $pdo = db();
    if (!$pdo) {
        $errors[] = 'Database is offline right now — please try again shortly.';
    } elseif (!$errors) {
        try {
            $dup = db_fetch_all(
                'SELECT Username, Email FROM `user` WHERE Username = :u OR Email = :e',
                [':u' => $username, ':e' => $email]
            );
            if ($dup) {
                $field = strcasecmp($dup[0]['Username'], $username) === 0 ? 'username' : 'email';
                $errors[] = "That $field is already registered. Try signing in instead.";
            }
        } catch (Throwable $e) {
            // Do not throw away the diagnostic here either: if the duplicate
            // check itself fails, the registration attempt will continue to the
            // insert step, where the same database problem will usually fail
            // again. Keep the message user-facing, but allow the real reason
            // out when debugging is enabled.
            $errors[] = 'Could not check for duplicate accounts.'
                . (APP_DEBUG ? ' ' . $e->getMessage() : '');
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();
                $userId = next_id($pdo, 'user', 'UserID');
                $stmt = $pdo->prepare(
                    'INSERT INTO `user` (UserID, RoleID, Username, PasswordHash, Email, FullName, Phone)
                     VALUES (:id, NULL, :u, :p, :e, :f, :ph)'
                );
                $stmt->execute([
                    ':id'   => $userId,
                    ':u'    => $username,
                    ':p'    => password_hash($password, PASSWORD_DEFAULT),
                    ':e'    => $email,
                    ':f'    => $fullName,
                    ':ph'   => $phone !== '' ? $phone : null,
                ]);

                // The individual's customer record: who documents are prepared
                // for. Individuals have no branch — branches belong to B2B
                // companies only.
                $clientId = next_id($pdo, 'client', 'ClientID');
                $pdo->prepare(
                    "INSERT INTO client (ClientID, UserID, CompanyName, ContactPerson, Email, Phone, Address, AccountType)
                     VALUES (:id, :uid, :cn, :cp, :e, :ph, NULL, 'individual')"
                )->execute([
                    ':id' => $clientId,
                    ':uid' => $userId,
                    ':cn' => $fullName,
                    ':cp' => $fullName,
                    ':e'  => $email,
                    ':ph' => $phone !== '' ? $phone : null,
                ]);
                $pdo->commit();

                clear_old();
                $_SESSION['user_id'] = $userId; // log the new user straight in
                flash_set('success', 'Welcome to SebaBD, ' . $fullName . '! Your account is ready.');
                // AJAX callers get {ok:true, redirect:…} instead of a 302.
                // A new customer lands on the dashboard: it shows the account,
                // the basket shortcut and what happens next, instead of
                // dropping them back on the shopfront with no orientation.
                post_success('dashboard.php');
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                // Tell the user where the database refused the insert, without
                // leaking raw SQL in production. The application currently
                // creates each customer as two rows (user, then client), so the
                // most useful diagnostic is which of those two statements actually
                // failed.
                $insertedUser = false;
                try {
                    $insertedUser = (bool) db_fetch_all(
                        'SELECT UserID FROM `user` WHERE UserID = :id LIMIT 1',
                        [':id' => $userId]
                    );
                } catch (Throwable $probeError) {
                    // If even that probe fails, treat the user row as not
                    // inserted for diagnostic purposes.
                    $insertedUser = false;
                }

                if ($insertedUser) {
                    $errors[] = 'Could not create your customer record.'
                        . (APP_DEBUG ? ' ' . $e->getMessage() : ' Please try again.');
                } else {
                    $errors[] = 'Could not create your account.'
                        . (APP_DEBUG ? ' ' . $e->getMessage() : ' Please try again.');
                }
            }
        }
    }
}

// A fetch() submit stops here with the error list; a normal submit renders them.
ajax_errors($errors);
?>
<?= partial_render('partials/header.php', ['page_title' => 'Create account', 'active_nav' => '']) ?>
<section class="auth-page py-5">
  <div class="container">
    <div class="row justify-content-center">
      <div class="col-md-8 col-lg-7">
        <?= render_flashes() ?>
        <div class="form-card">
          <div class="form-card-head">
            <h1 class="h4 mb-1">Create your account</h1>
            <p class="text-muted small mb-0">One account to shop, track orders and request quotations.</p>
          </div>

          <div class="ajax-errors" data-ajax-errors>
            <?php if ($errors): ?>
            <div class="alert alert-danger mb-0">
              <ul class="mb-0">
                <?php foreach ($errors as $err): ?><li><?= htmlspecialchars($err) ?></li><?php endforeach; ?>
              </ul>
            </div>
            <?php endif; ?>
          </div>

          <form method="post" action="register.php" novalidate data-ajax data-check-account>
            <?= csrf_field() ?>
            <div class="row g-3">
              <div class="col-12">
                <label class="form-label" for="full_name">Full name <span class="req">*</span></label>
                <input class="form-control" id="full_name" name="full_name" value="<?= old('full_name') ?>" required>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="username">Username <span class="req">*</span></label>
                <div class="input-group">
                  <span class="input-group-text"><i class="fa-solid fa-user"></i></span>
                  <input class="form-control" id="username" name="username" value="<?= old('username') ?>" required>
                </div>
                <small class="field-hint" data-field-hint="username" aria-live="polite"></small>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="phone">Phone</label>
                <div class="input-group">
                  <span class="input-group-text"><i class="fa-solid fa-phone"></i></span>
                  <input class="form-control" id="phone" name="phone" value="<?= old('phone') ?>" placeholder="+880 1XXX XXXXXX">
                </div>
              </div>
              <div class="col-12">
                <label class="form-label" for="email">Email <span class="req">*</span></label>
                <div class="input-group">
                  <span class="input-group-text"><i class="fa-solid fa-envelope"></i></span>
                  <input class="form-control" type="email" id="email" name="email" value="<?= old('email') ?>" required>
                </div>
                <small class="field-hint" data-field-hint="email" aria-live="polite"></small>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="password">Password <span class="req">*</span></label>
                <input class="form-control" type="password" id="password" name="password" minlength="6" required>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="password_confirm">Confirm password <span class="req">*</span></label>
                <input class="form-control" type="password" id="password_confirm" name="password_confirm" minlength="6" required>
              </div>
              <div class="col-12">
                <div class="form-note"><i class="fa-solid fa-circle-info me-2"></i>Your account is a <strong>customer account</strong> — it holds no SebaBD staff role. Buying is simple: shop, place orders, request quotations. Companies that buy for an organisation should use <a href="b2b-registration.php" class="fw-semibold">B2B registration</a> instead.</div>
              </div>
              <div class="col-12 d-grid gap-2 d-sm-flex">
                <button class="btn btn-accent" type="submit">Create account</button>
                <a class="btn btn-outline-secondary" href="login.php">I already have an account</a>
              </div>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</section>
<?= partial_render('partials/footer.php') ?>
