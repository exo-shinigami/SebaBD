<?php
require_once __DIR__ . '/helpers.php';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');
    $password   = $_POST['password'] ?? '';
    $next       = $_POST['next'] ?? 'index.php';

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
            clear_old();
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $rows[0]['UserID'];
            flash_set('success', 'Signed in as ' . $rows[0]['FullName'] . '.');
            // Only allow internal redirects. AJAX callers receive the same
            // target inside the JSON reply and navigate themselves.
            $target = str_starts_with($next, '/') || !str_contains($next, '://') ? $next : 'index.php';
            post_success($target);
        }
        $errors[] = 'Invalid credentials — check your username/email and password.';
    }
    $next = $_POST['next'] ?? 'index.php';
} else {
    $next = $_GET['next'] ?? 'index.php';
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

          <div class="form-note mt-3">
            <i class="fa-solid fa-flask me-2"></i>Demo logins — <code>admin_john/admin123</code>, <code>sales_sarah/sales123</code>, <code>inv_mike/stock123</code>, <code>client_acme/client123</code>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
<?= partial_render('partials/footer.php') ?>
