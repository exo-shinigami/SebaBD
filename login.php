<?php
require_once __DIR__ . '/helpers.php';
// cart_return_path() guards the ?next= target; it lives with the cart because
// that is the other place a visitor-supplied path is followed.
require_once __DIR__ . '/cart-data.php';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard('login.php');

    $identifier = trim($_POST['identifier'] ?? '');
    $password   = $_POST['password'] ?? '';
    $next       = $_POST['next'] ?? 'dashboard.php';

    keep_old(['identifier' => $identifier]);

    if ($identifier === '' || $password === '') {
        $errors[] = 'Please enter your username/email and password.';
    }

    $pdo = db();
    if (!$pdo) {
        $errors[] = 'Database is offline right now — please try again shortly.';
    } elseif (!$errors) {
        // Note: distinct placeholders — PDO native prepares don't allow
        // reusing the same named parameter twice in one statement.
        $rows = db_fetch_all(
            'SELECT UserID, PasswordHash, FullName FROM `user`
             WHERE Username = :uid OR Email = :email LIMIT 1',
            [':uid' => $identifier, ':email' => $identifier]
        );
        if ($rows && password_verify($password, $rows[0]['PasswordHash'])) {
            // The password is right, but an administrator may have closed the
            // account (user.IsActive = 0). That is a different answer from "wrong
            // password", so it gets its own message instead of sending someone
            // off to reset a password that works.
            if (!account_is_active((int) $rows[0]['UserID'])) {
                $errors[] = 'This account has been deactivated. Please contact the SebaBD administrator.';
            } else {
                clear_old();
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $rows[0]['UserID'];
                flash_set('success', 'Signed in as ' . $rows[0]['FullName'] . '.');
                // Only allow internal redirects: cart_return_path() refuses full
                // URLs, protocol-relative //host targets and paths that climb out,
                // so ?next= can never be turned into an open redirect. AJAX callers
                // receive the same target inside the JSON reply and navigate themselves.
                $target = cart_return_path($next, 'dashboard.php');
                post_success($target);
            }
        } else {
            $errors[] = 'Invalid credentials — check your username/email and password.';
        }
    }
    $next = $_POST['next'] ?? 'dashboard.php';
} else {
    // Shown in the form so it survives the round trip; validated on the way out.
    $next = cart_return_path((string) ($_GET['next'] ?? ''), 'dashboard.php');
}

// A fetch() submit stops here with the error list; a normal submit renders them.
ajax_errors($errors);
?>
<?= partial_render('partials/header.php', ['page_title' => 'Sign in', 'active_nav' => '']) ?>
<section class="auth-page py-5">
  <div class="container">
    <div class="row justify-content-center">
      <div class="col-md-7 col-lg-5">
        <?= render_flashes() ?>
        <div class="form-card">
          <div class="form-card-head">
            <h1 class="h4 mb-1">Sign in</h1>
            <p class="text-muted small mb-0">Welcome back to SebaBD.</p>
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

          <form method="post" action="login.php" novalidate data-ajax>
            <?= csrf_field() ?>
            <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">
            <div class="mb-3">
              <label class="form-label" for="identifier">Username or email</label>
              <div class="input-group">
                <span class="input-group-text"><i class="fa-solid fa-user"></i></span>
                <input class="form-control" id="identifier" name="identifier" value="<?= old('identifier') ?>" autofocus required>
              </div>
            </div>
            <div class="mb-3">
              <label class="form-label" for="password">Password</label>
              <div class="input-group">
                <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
                <input class="form-control" type="password" id="password" name="password" required>
              </div>
            </div>
            <div class="d-grid gap-2 d-sm-flex">
              <button class="btn btn-accent" type="submit">Sign in</button>
              <a class="btn btn-outline-secondary" href="register.php">Create account</a>
            </div>
          </form>

        </div>
      </div>
    </div>
  </div>
</section>
<?= partial_render('partials/footer.php') ?>
