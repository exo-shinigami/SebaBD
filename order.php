<?php
require_once __DIR__ . '/helpers.php';

$user = require_login('order.php');
$errors = [];

$catalog = db_fetch_all(
    'SELECT p.ProductID, p.ProductName, p.StandardPrice, p.StockQty, p.IsSerialized, c.CategoryName
     FROM product p JOIN category c ON c.CategoryID = p.CategoryID
     ORDER BY c.CategoryName, p.ProductName'
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $shipping   = trim($_POST['shipping_address'] ?? '');
    $productIds = array_map('intval', $_POST['product_id'] ?? []);
    $quantities = array_map('intval', $_POST['quantity'] ?? []);

    keep_old(['shipping_address' => $shipping]);

    if (mb_strlen($shipping) < 10) {
        $errors[] = 'Please enter a full delivery address (street, area, city).';
    }

    $lines = [];
    foreach ($productIds as $i => $pid) {
        $qty = $quantities[$i] ?? 0;
        if ($pid <= 0 || $qty <= 0) continue;
        $prod = db_fetch_all(
            'SELECT ProductID, ProductName, StandardPrice FROM product WHERE ProductID = :pid',
            [':pid' => $pid]
        );
        if (!$prod) {
            $errors[] = 'Unknown product selected on one of the lines.';
            continue;
        }
        $lines[] = ['product' => $prod[0], 'qty' => min(999, $qty)];
    }
    if (!$lines) {
        $errors[] = 'Add at least one product with a quantity.';
    }

    $pdo = db();
    if (!$pdo) {
        $errors[] = 'Database is offline right now — please try again shortly.';
    } elseif (!$errors) {
        try {
            $total = 0.0;
            foreach ($lines as $l) {
                $total += (float) $l['product']['StandardPrice'] * $l['qty'];
            }

            $pdo->beginTransaction();
            $orderId = next_id($pdo, 'order', 'OrderID');
            $pdo->prepare(
                'INSERT INTO `order` (OrderID, UserID, TotalAmount, OrderStatus, ShippingAddress)
                 VALUES (:id, :uid, :total, :status, :addr)'
            )->execute([
                ':id' => $orderId, ':uid' => (int) $user['UserID'],
                ':total' => $total, ':status' => 'Pending', ':addr' => $shipping,
            ]);

            $itemStmt = $pdo->prepare(
                'INSERT INTO order_item (OrderItemID, OrderID, ProductID, Quantity, UnitPrice, TotalPrice)
                 VALUES (:id, :oid, :pid, :qty, :up, :tp)'
            );
            $itemId = next_id($pdo, 'order_item', 'OrderItemID');
            foreach ($lines as $l) {
                $unit  = (float) $l['product']['StandardPrice']; // snapshot at order time
                $itemStmt->execute([
                    ':id' => $itemId++, ':oid' => $orderId,
                    ':pid' => (int) $l['product']['ProductID'], ':qty' => $l['qty'],
                    ':up' => $unit, ':tp' => $unit * $l['qty'],
                ]);
            }
            $pdo->commit();
            clear_old();

            flash_set('success', 'Order #' . $orderId . ' placed — total ' . money($total) . '. We will call to confirm delivery.');
            // AJAX callers get {ok:true, redirect:…} instead of a 302.
            post_success('order.php');
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'Could not place the order. ' . (APP_DEBUG ? $e->getMessage() : 'Please try again.');
        }
    }
}

// A fetch() submit stops here with the error list; a normal submit renders them.
ajax_errors($errors);
?>
<?= partial_render('partials/header.php', ['page_title' => 'Quick order', 'active_nav' => 'shop']) ?>
<section class="auth-page py-5">
  <div class="container">
    <div class="row justify-content-center">
      <div class="col-lg-10 col-xl-9">
        <?= render_flashes() ?>
        <div class="form-card">
          <div class="form-card-head">
            <h1 class="h4 mb-1">Quick order</h1>
            <p class="text-muted small mb-0">Ordering as <strong><?= htmlspecialchars($user['FullName']) ?></strong> — items are priced at the current list price when the order is placed.</p>
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

          <form method="post" action="order.php" novalidate data-ajax>
            <h2 class="form-section-title">Delivery details</h2>
            <div class="mb-3">
              <label class="form-label" for="shipping_address">Shipping address <span class="req">*</span></label>
              <textarea class="form-control" id="shipping_address" name="shipping_address" rows="2"
                        placeholder="House / Road / Area, City, Postcode" required><?= old('shipping_address') ?></textarea>
            </div>

            <h2 class="form-section-title">Products</h2>
            <div class="line-items" data-max-rows="10" data-currency="<?= htmlspecialchars(currency_symbol()) ?>">
              <div class="line-rows">
                <?php for ($i = 0; $i < 2; $i++): ?>
                <div class="line-item-row">
                  <div class="line-item-grid">
                    <div class="line-cell line-cell-product">
                      <label class="form-label">Product</label>
                      <select class="form-select line-product" name="product_id[]">
                        <option value="">— select a product —</option>
                        <?php $lastCat = null;
                        foreach ($catalog as $p):
                            if ($lastCat !== $p['CategoryName']) {
                                if ($lastCat !== null) echo '</optgroup>';
                                echo '<optgroup label="' . htmlspecialchars($p['CategoryName']) . '">';
                                $lastCat = $p['CategoryName'];
                            }
                            $stockTag = ((int) $p['IsSerialized']) === 0 && (int) $p['StockQty'] <= 5 && (int) $p['StockQty'] > 0 ? ' — low stock' : '';
                        ?>
                        <option value="<?= (int) $p['ProductID'] ?>" data-price="<?= (float) $p['StandardPrice'] ?>">
                          <?= htmlspecialchars($p['ProductName']) . $stockTag ?>
                        </option>
                        <?php endforeach; ?>
                        </optgroup>
                      </select>
                      <small class="line-stock text-muted" data-stock-note></small>
                    </div>
                    <div class="line-cell line-cell-qty">
                      <label class="form-label">Qty</label>
                      <input class="form-control line-qty" type="number" name="quantity[]" min="1" max="999" value="1">
                    </div>
                    <div class="line-cell line-cell-total">
                      <label class="form-label">Line total</label>
                      <span class="line-total price"><?= money(0) ?></span>
                    </div>
                    <div class="line-cell line-cell-remove">
                      <label class="form-label">&nbsp;</label>
                      <button type="button" class="btn btn-outline-danger remove-row" aria-label="Remove line"><i class="fa-solid fa-trash-can"></i></button>
                    </div>
                  </div>
                </div>
                <?php endfor; ?>
              </div>

              <template id="line-item-template">
                <div class="line-item-row">
                  <div class="line-item-grid">
                    <div class="line-cell line-cell-product">
                      <label class="form-label">Product</label>
                      <select class="form-select line-product" name="product_id[]">
                        <option value="">— select a product —</option>
                        <?php $lastCat = null;
                        foreach ($catalog as $p):
                            if ($lastCat !== $p['CategoryName']) {
                                if ($lastCat !== null) echo '</optgroup>';
                                echo '<optgroup label="' . htmlspecialchars($p['CategoryName']) . '">';
                                $lastCat = $p['CategoryName'];
                            }
                        ?>
                        <option value="<?= (int) $p['ProductID'] ?>" data-price="<?= (float) $p['StandardPrice'] ?>">
                          <?= htmlspecialchars($p['ProductName']) ?>
                        </option>
                        <?php endforeach; ?>
                        </optgroup>
                      </select>
                      <small class="line-stock text-muted" data-stock-note></small>
                    </div>
                    <div class="line-cell line-cell-qty">
                      <label class="form-label">Qty</label>
                      <input class="form-control line-qty" type="number" name="quantity[]" min="1" max="999" value="1">
                    </div>
                    <div class="line-cell line-cell-total">
                      <label class="form-label">Line total</label>
                      <span class="line-total price"><?= money(0) ?></span>
                    </div>
                    <div class="line-cell line-cell-remove">
                      <label class="form-label">&nbsp;</label>
                      <button type="button" class="btn btn-outline-danger remove-row" aria-label="Remove line"><i class="fa-solid fa-trash-can"></i></button>
                    </div>
                  </div>
                </div>
              </template>

              <button type="button" class="btn btn-outline-primary btn-sm add-row"><i class="fa-solid fa-plus me-1"></i>Add another product</button>
            </div>

            <div class="totals-box">
              <div class="d-flex justify-content-between align-items-center">
                <span class="text-muted">Order total (<span id="line-count">2</span> lines)</span>
                <span class="price h5 mb-0" id="grand-total"><?= money(0) ?></span>
              </div>
              <small class="text-muted d-block mt-1">Payment is collected on delivery or via bank transfer — an invoice will be issued for this order.</small>
            </div>

            <div class="d-grid gap-2 d-sm-flex mt-3">
              <button class="btn btn-accent" type="submit">Place order</button>
              <a class="btn btn-outline-secondary" href="index.php">Cancel</a>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</section>
<script src="assets/form-items.js"></script>
<?= partial_render('partials/footer.php') ?>
