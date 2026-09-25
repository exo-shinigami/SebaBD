<?php
require_once __DIR__ . '/helpers.php';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $company       = trim($_POST['company_name'] ?? '');
    $contactPerson = trim($_POST['contact_person'] ?? '');
    $email         = trim($_POST['email'] ?? '');
    $phone         = trim($_POST['phone'] ?? '');
    $address       = trim($_POST['address'] ?? '');
    $branchName    = trim($_POST['branch_name'] ?? '');
    $branchAddress = trim($_POST['branch_address'] ?? '');
    $branchPhone   = trim($_POST['branch_phone'] ?? '');
    $createLogin   = isset($_POST['create_login']) && $_POST['create_login'] === '1';
    $username      = trim($_POST['username'] ?? '');
    $password      = $_POST['password'] ?? '';

    keep_old(array_filter([
        'company_name' => $company, 'contact_person' => $contactPerson,
        'email' => $email, 'phone' => $phone, 'address' => $address,
        'branch_name' => $branchName, 'branch_address' => $branchAddress,
        'branch_phone' => $branchPhone, 'username' => $username,
    ]));

    // ---- validation -------------------------------------------------------
    if (mb_strlen($company) < 3) $errors[] = 'Company name is required (at least 3 characters).';
    if ($contactPerson === '')   $errors[] = 'Contact person is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid company email is required.';
    if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{6,20}$/', $phone)) $errors[] = 'Phone number looks invalid.';
    if (mb_strlen($address) < 8) $errors[] = 'Head office address is required.';
    if ($branchName === '')      $errors[] = 'First branch name is required (e.g. “Head Office”).';
    if ($createLogin) {
        if (!preg_match('/^[A-Za-z0-9_.]{3,100}$/', $username)) {
            $errors[] = 'Username must be 3–100 characters: letters, numbers, dot or underscore.';
        }
        if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';
    }

    $pdo = db();
    if (!$pdo) {
        $errors[] = 'Database is offline right now — please try again shortly.';
    } elseif (!$errors) {
        try {
            if ($createLogin) {
                $dup = db_fetch_all(
                    'SELECT UserID FROM `user` WHERE Username = :u OR Email = :e',
                    [':u' => $username, ':e' => $email]
                );
                if ($dup) {
                    $errors[] = 'That username or email is already taken for the linked login.';
                }
            }
        } catch (Throwable $e) {
            $errors[] = 'Could not verify the requested login.';
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();

                // Optional linked user account (role 4 = Client Account).
                $linkUserId = null;
                if ($createLogin) {
                    $linkUserId = next_id($pdo, 'user', 'UserID');
                    $pdo->prepare(
                        'INSERT INTO `user` (UserID, RoleID, Username, PasswordHash, Email, FullName, Phone)
                         VALUES (:id, 4, :u, :p, :e, :f, :ph)'
                    )->execute([
                        ':id' => $linkUserId, ':u' => $username,
                        ':p'  => password_hash($password, PASSWORD_DEFAULT),
                        ':e'  => $email, ':f' => $contactPerson, ':ph' => $phone ?: null,
                    ]);
                }

                $clientId = next_id($pdo, 'client', 'ClientID');
                $pdo->prepare(
                    'INSERT INTO client (ClientID, UserID, CompanyName, ContactPerson, Email, Phone, Address)
                     VALUES (:id, :uid, :cn, :cp, :e, :ph, :ad)'
                )->execute([
                    ':id' => $clientId, ':uid' => $linkUserId, ':cn' => $company,
                    ':cp' => $contactPerson, ':e' => $email,
                    ':ph' => $phone ?: null, ':ad' => $address,
                ]);

                $branchId = next_id($pdo, 'client_branch', 'BranchID');
                $pdo->prepare(
                    'INSERT INTO client_branch (BranchID, ClientID, BranchName, BranchAddress, ContactNo)
                     VALUES (:id, :cid, :bn, :ba, :bc)'
                )->execute([
                    ':id' => $branchId, ':cid' => $clientId, ':bn' => $branchName,
                    ':ba' => $branchAddress !== '' ? $branchAddress : $address,
                    ':bc' => $branchPhone !== '' ? $branchPhone : $phone,
                ]);

                $pdo->commit();
                clear_old();
                if ($linkUserId) {
                    $_SESSION['user_id'] = $linkUserId;
                }
                flash_set('success', 'B2B account registered — company #' . $clientId . ', branch “' . $branchName . '” created. Our sales team will reach out shortly.');
                header('Location: index.php');
                exit;
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = 'Could not register the company. ' . (APP_DEBUG ? $e->getMessage() : 'Please try again.');
            }
        }
    }
}
?>
<?= partial_render('partials/header.php', ['page_title' => 'B2B registration', 'active_nav' => '']) ?>
<section class="auth-page py-5">
  <div class="container">
    <div class="row justify-content-center">
      <div class="col-lg-9 col-xl-8">
        <?= render_flashes() ?>
        <div class="form-card">
          <div class="form-card-head">
            <h1 class="h4 mb-1">Corporate / B2B registration</h1>
            <p class="text-muted small mb-0">Register your organization for quotations, corporate pricing and dedicated support.</p>
          </div>

          <?php if ($errors): ?>
          <div class="alert alert-danger mb-0">
            <ul class="mb-0">
              <?php foreach ($errors as $err): ?><li><?= htmlspecialchars($err) ?></li><?php endforeach; ?>
            </ul>
          </div>
          <?php endif; ?>

          <form method="post" action="b2b-registration.php" novalidate>
            <h2 class="form-section-title">Company (head office)</h2>
            <div class="row g-3">
              <div class="col-md-7">
                <label class="form-label" for="company_name">Company name <span class="req">*</span></label>
                <input class="form-control" id="company_name" name="company_name" value="<?= old('company_name') ?>" required>
              </div>
              <div class="col-md-5">
                <label class="form-label" for="contact_person">Contact person <span class="req">*</span></label>
                <input class="form-control" id="contact_person" name="contact_person" value="<?= old('contact_person') ?>" required>
              </div>
              <div class="col-md-7">
                <label class="form-label" for="email">Company email <span class="req">*</span></label>
                <div class="input-group">
                  <span class="input-group-text"><i class="fa-solid fa-envelope"></i></span>
                  <input class="form-control" type="email" id="email" name="email" value="<?= old('email') ?>" required>
                </div>
              </div>
              <div class="col-md-5">
                <label class="form-label" for="phone">Phone</label>
                <div class="input-group">
                  <span class="input-group-text"><i class="fa-solid fa-phone"></i></span>
                  <input class="form-control" id="phone" name="phone" value="<?= old('phone') ?>" placeholder="+880 1XXX XXXXXX">
                </div>
              </div>
              <div class="col-12">
                <label class="form-label" for="address">Head office address <span class="req">*</span></label>
                <textarea class="form-control" id="address" name="address" rows="2" required><?= old('address') ?></textarea>
              </div>
            </div>

            <h2 class="form-section-title">First branch</h2>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label" for="branch_name">Branch name <span class="req">*</span></label>
                <input class="form-control" id="branch_name" name="branch_name" value="<?= old('branch_name') ?: 'Head Office' ?>" required>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="branch_phone">Branch phone</label>
                <input class="form-control" id="branch_phone" name="branch_phone" value="<?= old('branch_phone') ?>">
              </div>
              <div class="col-12">
                <label class="form-label" for="branch_address">Branch address</label>
                <textarea class="form-control" id="branch_address" name="branch_address" rows="2" placeholder="Leave empty to reuse the head office address"><?= old('branch_address') ?></textarea>
              </div>
            </div>

            <h2 class="form-section-title">Online account <span class="text-muted small fw-normal">(optional)</span></h2>
            <div class="row g-3">
              <div class="col-12">
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" role="switch" id="create_login" name="create_login" value="1"
                         <?= isset($_POST['create_login']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="create_login">Create an online login for the contact person</label>
                </div>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="username">Username</label>
                <div class="input-group">
                  <span class="input-group-text"><i class="fa-solid fa-user"></i></span>
                  <input class="form-control" id="username" name="username" value="<?= old('username') ?>">
                </div>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="password">Password</label>
                <div class="input-group">
                  <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
                  <input class="form-control" type="password" id="password" name="password" minlength="6">
                </div>
              </div>
            </div>

            <div class="d-grid gap-2 d-sm-flex mt-4">
              <button class="btn btn-accent" type="submit">Register company</button>
              <a class="btn btn-outline-secondary" href="index.php">Cancel</a>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</section>
<?= partial_render('partials/footer.php') ?>
