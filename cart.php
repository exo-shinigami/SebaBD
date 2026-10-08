<?php
/**
 * SebaBD — the shopping cart and checkout.
 *
 *   cart.php                        the basket
 *   POST intent=add                 add a product (the storefront's Add to cart)
 *   POST intent=update              change quantities, remove, or empty it
 *   POST intent=checkout            turn the basket into an order
 *
 * Checkout writes a real order: one `order` row for the signed-in user and one
 * `order_item` row per line, inside a single transaction so a half-saved order
 * cannot exist. Stock is not decremented here — that happens when the invoice
 * is raised and the units are attached (see admin-invoices.php), because the
 * money and the goods have to move together.
 *
 * A basket may hold more than is on the shelf: the store sources to order, so
 * the cart says so plainly instead of refusing the sale, and the back office
 * sees exactly which lines need buying in.
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/cart-data.php';

$user   = current_user();
$errors = [];
$notice = '';

/* ---------------------------------------------------------------- add to cart
 * Kept before any layout so the button works from the catalog, a product page
 * or the suggestions list, and so a fetch() caller gets JSON rather than HTML. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_str('intent') === 'add') {
    csrf_guard('cart.php');

    $productId = post_int('product_id');
    $qty       = post_int('quantity', 1);
    $back      = cart_return_path(post_str('return'), 'cart.php');

    $rows = db_fetch_all(
        'SELECT ProductID, ProductName, IsSerialized FROM product WHERE ProductID = :id',
        [':id' => $productId]
    );

    if (!$rows) {
        ajax_errors(['That product is no longer in the catalog.']);
        flash_set('error', 'That product is no longer in the catalog.');
        header('Location: ' . $back);
        exit;
    }
    if ($qty < 1 || $qty > CART_MAX_QTY) {
        $errors[] = 'Choose a quantity between 1 and ' . CART_MAX_QTY . '.';
    }
    if ($errors) {
        ajax_errors($errors);
        flash_set('error', implode(' ', $errors));
        header('Location: ' . $back);
        exit;
    }

    cart_add($productId, $qty);
    $message = $rows[0]['ProductName'] . ' × ' . $qty . ' added to your basket.';
    flash_set('success', $message);

    // A fetch() caller stays on the page: it gets the new count and a one-line
    // message and updates the badge itself, so adding an item never costs a
    // reload. Everything the response carries is also in the session, so the
    // no-JavaScript path (a normal redirect) shows exactly the same thing.
    if (json_request()) {
        json_response([
            'ok'       => true,
            'redirect' => $back,
            'cart'     => [
                'count'   => cart_count(),
                'message' => $message,
                'lines'   => cart_totals(cart_lines()['lines'])['lines'],
            ],
        ]);
    }

    header('Location: ' . $back);
    exit;
}

$lines  = cart_lines();
$totals = cart_totals($lines['lines']);

/* --------------------------------------------------------------- the updates */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard('cart.php');
    $intent = post_str('intent');

    if ($intent === 'update') {
        $quantities = (array) ($_POST['quantity'] ?? []);
        $removed    = 0;
        foreach ($quantities as $productId => $qty) {
            $productId = (int) $productId;
            $qty       = (int) $qty;
            if ($productId <= 0) {
                continue;
            }
            if ($qty <= 0) {
                $removed++;
            }
            cart_set($productId, $qty);
        }

        flash_set('success', 'Your basket was updated' . ($removed ? ' — ' . $removed . ' line removed.' : '.'));
        post_success('cart.php');
    }

    if ($intent === 'remove') {
        cart_remove(post_int('product_id'));
        flash_set('success', 'That line was removed from your basket.');
        post_success('cart.php');
    }

    if ($intent === 'clear') {
        cart_clear();
        flash_set('success', 'Your basket is empty.');
        post_success('cart.php');
    }

    if ($intent === 'checkout') {
        if (!$user) {
            // Signing in is the only thing standing between the basket and an
            // order, so send them to the form and straight back here.
            flash_set('error', 'Please sign in to place your order — your basket is saved.');
            header('Location: login.php?next=' . urlencode('cart.php'));
            exit;
        }

        $lines       = cart_lines();
        $totals      = cart_totals($lines['lines']);
        $address     = trim((string) post_str('shipping_address'));
        $note        = trim((string) post_str('order_note'));

        // Held for the redisplay below, so a rejected checkout does not wipe
        // the address the customer just typed.
        keep_old(['shipping_address' => $address, 'order_note' => $note]);

        if (!$lines['lines']) {
            $errors[] = 'Your basket is empty — add a product before checking out.';
        }
        if ($lines['missing']) {
            $errors[] = 'Some products left the catalog while you were shopping (' . implode(', ', $lines['missing'])
                      . '). Please remove those lines and try again.';
        }
        if ($address === '') {
            $errors[] = 'Please give a delivery address so we know where the order goes.';
        } elseif (mb_strlen($address) > 500) {
            $errors[] = 'The delivery address is longer than 500 characters.';
        }
        if (mb_strlen($note) > 500) {
            $errors[] = 'The order note is longer than 500 characters.';
        }
        if (post_str('terms') === '') {
            $errors[] = 'Please confirm the order summary before placing the order.';
        }
        if ($errors) {
            ajax_errors($errors);
        } else {
            $pdo = db();
            if (!$pdo) {
                $errors[] = 'The database is offline, so the order could not be saved. Please try again shortly.';
                ajax_errors($errors);
            } else {
                try {
                    $orderId = db_transaction($pdo, function (PDO $tx) use ($user, $lines, $totals, $address, $note) {
                        $orderId = db_insert($tx, 'order', [
                            'UserID'          => (int) $user['UserID'],
                            'OrderDate'       => date('Y-m-d H:i:s'),
                            'TotalAmount'     => $totals['subtotal'],
                            'OrderStatus'     => 'Pending',
                            'ShippingAddress' => $address . ($note !== '' ? "\n\nNote: " . $note : ''),
                        ], 'OrderID');

                        foreach ($lines['lines'] as $line) {
                            db_insert($tx, 'order_item', [
                                'OrderID'   => $orderId,
                                'ProductID' => (int) $line['ProductID'],
                                'Quantity'  => (int) $line['Quantity'],
                                'UnitPrice' => (float) $line['StandardPrice'],
                                'TotalPrice' => (float) $line['LineTotal'],
                            ], 'OrderItemID');
                        }

                        return $orderId;
                    });

                    cart_clear();
                    clear_old();
                    flash_set('success', 'Thank you — order #' . $orderId . ' has been placed. '
                        . 'The sales team confirms it and raises the invoice next.');
                    post_success('order-detail.php?id=' . $orderId . '&placed=1');
                } catch (Throwable $e) {
                    $errors[] = 'The order could not be saved: ' . $e->getMessage();
                    ajax_errors($errors);
                }
            }
        }
    }
}

/* Re-read after any successful write so the page always shows what is stored. */
$lines  = cart_lines();
$totals = cart_totals($lines['lines']);

/* The default delivery address: the account's own, so a returning customer
 * does not retype it. Client accounts get their company address too. */
$defaultAddress = '';
if ($user) {
    $rows = db_fetch_all('SELECT FullName, Email, Phone FROM `user` WHERE UserID = :id', [':id' => (int) $user['UserID']]);
    $me   = $rows[0] ?? [];
    $companyRows = db_fetch_all(
        'SELECT c.CompanyName, c.Address, c.Phone FROM client c WHERE c.UserID = :uid LIMIT 1',
        [':uid' => (int) $user['UserID']]
    );
    $company = $companyRows[0] ?? [];

    $defaultAddress = trim(implode(', ', array_filter([
        $company['CompanyName'] ?? '',
        $me['FullName'] ?? '',
        $company['Address'] ?? '',
        $company['Phone'] ?? ($me['Phone'] ?? ''),
    ])), ', ');
}

$page_title = 'Your basket';
?>
<?= partial_render('partials/header.php', ['page_title' => $page_title, 'active_nav' => 'shop']) ?>

<section class="py-4">
  <div class="container">

    <?= render_flashes() ?>

    <nav aria-label="breadcrumb">
      <ol class="breadcrumb crumb mb-3">
        <li class="breadcrumb-item"><a href="index.php">Home</a></li>
        <li class="breadcrumb-item active" aria-current="page">Basket</li>
      </ol>
    </nav>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <div>
        <p class="section-label mb-1">Step 1 of 2</p>
        <h1 class="h3 fw-bold mb-0">Your basket</h1>
        <p class="text-muted mb-0">
          <?= $totals['lines'] ?> line<?= $totals['lines'] === 1 ? '' : 's' ?>,
          <?= $totals['units'] ?> unit<?= $totals['units'] === 1 ? '' : 's' ?>.
          Prices and stock are re-checked every time this page loads.
        </p>
      </div>
      <a class="text-decoration-none" href="index.php?all=1#featured-products">
        <i class="fa-solid fa-cart-plus me-1"></i>Keep shopping
      </a>
    </div>

    <?php if ($errors && post_str('intent') === 'checkout'): ?>
    <div class="alert alert-danger">
      <strong>The order was not placed.</strong>
      <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>

    <?php if (!$lines['lines']): ?>

    <div class="report-panel text-center py-5">
      <i class="fa-solid fa-basket-shopping fa-2x text-muted d-block mb-3"></i>
      <h2 class="h5 mb-2">Your basket is empty</h2>
      <p class="text-muted mb-4">Browse the catalog and add what you need — nothing is ordered until you check out.</p>
      <div class="d-flex flex-wrap gap-2 justify-content-center">
        <a class="btn btn-accent" href="index.php?all=1#featured-products">Browse products</a>
        <a class="btn btn-outline-dark" href="quotation-request.php">Request a quotation instead</a>
      </div>
    </div>

    <?php else: ?>

    <div class="row g-4">
      <div class="col-lg-8">
        <div class="report-panel mb-4">
          <div class="report-panel-head">
            <h2 class="report-panel-title">Items</h2>
            <p class="report-panel-sub">Change a quantity to 0 to remove a line.</p>
          </div>

          <form method="post" action="cart.php" data-ajax>
            <?= csrf_field() ?>
            <input type="hidden" name="intent" value="update">
            <div class="table-responsive">
              <table class="table align-middle cart-table mb-0">
                <thead>
                  <tr>
                    <th scope="col">Product</th>
                    <th scope="col" class="text-end">Unit price</th>
                    <th scope="col" class="text-center" style="width:8.5rem;">Quantity</th>
                    <th scope="col" class="text-end">Line total</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($lines['lines'] as $line): ?>
                  <tr>
                    <td>
                      <div class="d-flex align-items-start gap-3">
                        <div class="cart-thumb"><?= product_image_html($line, 'cart-thumb-img') ?></div>
                        <div>
                          <a class="fw-semibold text-decoration-none" href="product.php?id=<?= (int) $line['ProductID'] ?>">
                            <?= htmlspecialchars($line['ProductName']) ?>
                          </a>
                          <div class="text-muted small">
                            <?= htmlspecialchars($line['CategoryName']) ?>
                            <?= !empty($line['Brand']) ? ' · ' . htmlspecialchars($line['Brand']) : '' ?>
                            <?php if (!empty($line['Model'])): ?> · <?= htmlspecialchars($line['Model']) ?><?php endif; ?>
                          </div>
                          <div class="small mt-1">
                            <?php if ($line['LineState'] === 'ok'): ?>
                              <span class="text-success"><i class="fa-solid fa-circle-check me-1"></i><?= htmlspecialchars(stock_label($line, $line['Available'])) ?></span>
                            <?php elseif ($line['LineState'] === 'low'): ?>
                              <span class="text-warning-emphasis"><i class="fa-solid fa-triangle-exclamation me-1"></i>
                                Only <?= (int) $line['Available'] ?> on hand — the other
                                <?= (int) $line['Quantity'] - (int) $line['Available'] ?> would be sourced to order.
                              </span>
                            <?php else: ?>
                              <span class="text-warning-emphasis"><i class="fa-solid fa-truck-fast me-1"></i>
                                Made to order — this line ships from the next supplier delivery.
                              </span>
                            <?php endif; ?>
                          </div>
                        </div>
                      </div>
                    </td>
                    <td class="text-end text-nowrap"><?= money($line['StandardPrice']) ?></td>
                    <td class="text-center">
                      <label class="visually-hidden" for="qty-<?= (int) $line['ProductID'] ?>">Quantity for <?= htmlspecialchars($line['ProductName']) ?></label>
                      <input class="form-control form-control-sm text-center mx-auto" style="max-width:5.5rem;"
                             type="number" min="0" max="<?= CART_MAX_QTY ?>" step="1"
                             id="qty-<?= (int) $line['ProductID'] ?>"
                             name="quantity[<?= (int) $line['ProductID'] ?>]"
                             value="<?= (int) $line['Quantity'] ?>">
                    </td>
                    <td class="text-end text-nowrap fw-semibold"><?= money($line['LineTotal']) ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
                <tfoot>
                  <tr>
                    <td colspan="3" class="text-end text-muted">Subtotal (excl. delivery)</td>
                    <td class="text-end fw-bold"><?= money($totals['subtotal']) ?></td>
                  </tr>
                </tfoot>
              </table>
            </div>

            <div class="d-flex flex-wrap gap-2 mt-3">
              <button class="btn btn-accent" type="submit"><i class="fa-solid fa-rotate me-1"></i>Update basket</button>
            </div>
          </form>
        </div>

        <!-- Removing a line is its own small form: it needs no quantity field,
             and it keeps working when JavaScript is off. -->
        <div class="report-panel">
          <div class="report-panel-head">
            <h2 class="report-panel-title">Remove lines</h2>
            <p class="report-panel-sub">Or start over with an empty basket.</p>
          </div>
          <div class="d-flex flex-wrap gap-2">
            <?php foreach ($lines['lines'] as $line): ?>
            <form method="post" action="cart.php" data-ajax class="d-inline">
              <?= csrf_field() ?>
              <input type="hidden" name="intent" value="remove">
              <input type="hidden" name="product_id" value="<?= (int) $line['ProductID'] ?>">
              <button class="btn btn-sm btn-outline-danger" type="submit">
                <i class="fa-solid fa-xmark me-1"></i><?= htmlspecialchars(mb_strimwidth($line['ProductName'], 0, 28, '…')) ?>
              </button>
            </form>
            <?php endforeach; ?>
            <form method="post" action="cart.php" data-ajax class="d-inline">
              <?= csrf_field() ?>
              <input type="hidden" name="intent" value="clear">
              <button class="btn btn-sm btn-outline-secondary" type="submit">
                <i class="fa-solid fa-trash me-1"></i>Empty basket
              </button>
            </form>
          </div>
        </div>
      </div>

      <div class="col-lg-4">
        <div class="report-panel cart-summary">
          <div class="report-panel-head">
            <h2 class="report-panel-title">Checkout</h2>
            <p class="report-panel-sub">Order now, invoice and delivery follow.</p>
          </div>

          <table class="table table-sm mb-3">
            <tbody>
              <tr><th scope="row" class="text-muted fw-normal">Units</th><td class="text-end"><?= (int) $totals['units'] ?></td></tr>
              <tr><th scope="row" class="text-muted fw-normal">Subtotal</th><td class="text-end"><?= money($totals['subtotal']) ?></td></tr>
              <tr><th scope="row" class="text-muted fw-normal">Delivery</th><td class="text-end text-muted">Quoted after confirmation</td></tr>
              <tr class="border-top"><th scope="row">Payable</th><td class="text-end fw-bold"><?= money($totals['subtotal']) ?></td></tr>
            </tbody>
          </table>

          <?php if ($totals['backordered']): ?>
          <div class="alert alert-warning py-2 small mb-3">
            <i class="fa-solid fa-truck-fast me-1"></i>
            <?= $totals['backordered'] ?> line<?= $totals['backordered'] === 1 ? ' needs' : 's need' ?> stock we do not hold yet.
            The order is still accepted and sourced from the supplier.
          </div>
          <?php endif; ?>

          <?php if (!$user): ?>
          <div class="alert alert-light border small">
            <i class="fa-solid fa-user-lock me-1"></i>You are not signed in. Your basket is kept — sign in
            and you will come straight back here to finish.
          </div>
          <a class="btn btn-accent w-100" href="login.php?next=<?= urlencode('cart.php') ?>">
            <i class="fa-solid fa-right-to-bracket me-1"></i>Sign in to place the order
          </a>
          <a class="btn btn-outline-dark w-100 mt-2" href="register.php">Create an account</a>
          <?php else: ?>
          <form method="post" action="cart.php" data-ajax>
            <?= csrf_field() ?>
            <input type="hidden" name="intent" value="checkout">

            <div class="mb-3">
              <label class="form-label" for="shipping_address">Delivery address <span class="text-danger">*</span></label>
              <textarea class="form-control" id="shipping_address" name="shipping_address" rows="3"
                        maxlength="500" required
                        placeholder="Where should this be delivered?"><?= old('shipping_address', $defaultAddress) ?></textarea>
              <div class="form-text">Street, area, city and a contact number for the courier.</div>
            </div>

            <div class="mb-3">
              <label class="form-label" for="order_note">Order note <span class="text-muted">(optional)</span></label>
              <textarea class="form-control" id="order_note" name="order_note" rows="2" maxlength="500"
                        placeholder="Purchase order number, delivery window, installation needs…"><?= old('order_note') ?></textarea>
            </div>

            <div class="form-check mb-3">
              <input class="form-check-input" type="checkbox" value="1" id="terms" name="terms" required>
              <label class="form-check-label small" for="terms">
                I confirm the <strong><?= $totals['units'] ?></strong> unit<?= $totals['units'] === 1 ? '' : 's' ?>
                totalling <strong><?= money($totals['subtotal']) ?></strong> above, and I accept the
                <a href="terms.php" target="_blank" rel="noopener">terms of sale</a>.
              </label>
            </div>

            <button class="btn btn-accent w-100" type="submit">
              <i class="fa-solid fa-bag-shopping me-1"></i>Place the order
            </button>
          </form>
          <?php endif; ?>

          <hr class="my-4">
          <div class="small text-muted">
            <p class="mb-1"><i class="fa-solid fa-shield-halved me-1"></i>Signed in as staff? Orders placed here belong to your own account.</p>
            <p class="mb-0">Need a formal quote first? <a href="quotation-request.php">Request a quotation</a> and a sales manager will price it for your company.</p>
          </div>
        </div>
      </div>
    </div>

    <?php endif; ?>
  </div>
</section>

<?= partial_render('partials/footer.php') ?>
