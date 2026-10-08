<?php
/**
 * SebaBD — one order, seen by the customer who placed it.
 *
 *   order-detail.php?id=<OrderID>
 *
 * The order side of "my account": what was ordered, where it is going, what it
 * costs, how far along it is, and the invoice raised against it (#15) once the
 * sales team has priced the goods.
 *
 * A customer sees only orders placed with their own login (#4); staff opening
 * the page see the same layout for any order, with a shortcut into the back
 * office. A customer may cancel an order while it is still Pending — after
 * that it is being packed, and a cancellation is a phone call, not a click.
 */
require_once __DIR__ . '/helpers.php';
// "Reorder" puts lines back in the basket, so the cart layer is loaded before
// the POST branches run — header.php would load it too late for that.
require_once __DIR__ . '/cart-data.php';

$user = require_login('order-detail.php');
$id   = max(0, (int) ($_GET['id'] ?? 0));
$justPlaced = isset($_GET['placed']);

$rows = db_fetch_all(
    'SELECT o.OrderID, o.UserID, o.OrderDate, o.TotalAmount, o.OrderStatus, o.ShippingAddress,
            u.FullName, u.Email, u.Phone, u.Username
     FROM `order` o
     JOIN `user` u ON u.UserID = o.UserID
     WHERE o.OrderID = :id',
    [':id' => $id]
);
$order = $rows[0] ?? null;

if ($order === null) {
    deny_page(404, 'Order not found', 'Order #' . $id . ' does not exist.', 'dashboard.php', 'My dashboard');
}

$isStaff = is_staff_user($user);
if (!$isStaff && (int) $order['UserID'] !== (int) $user['UserID']) {
    deny_page(403, 'Not your order',
        'Order #' . $id . ' was placed by another account. Only the person who placed an order can see it.',
        'dashboard.php', 'My dashboard');
}

/* -------------------------------------------------------------- cancelling */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard('order-detail.php?id=' . $id);
    $intent = post_str('intent');

    if ($intent === 'cancel') {
        if ($isStaff) {
            flash_set('error', 'Staff cancel orders from the back office, where the invoice is checked at the same time.');
        } elseif ((string) $order['OrderStatus'] !== 'Pending') {
            flash_set('error', 'This order is already ' . strtolower((string) $order['OrderStatus'])
                . ', so it can no longer be cancelled online. Please contact the sales desk.');
        } else {
            $invoiced = db_fetch_all('SELECT InvoiceNo FROM invoice WHERE OrderID = :id', [':id' => $id]);
            if ($invoiced) {
                flash_set('error', 'Invoice #' . (int) $invoiced[0]['InvoiceNo'] . ' has already been raised, '
                    . 'so this order cannot be cancelled online. Please contact the sales desk.');
            } else {
                $pdo = db();
                try {
                    db_transaction($pdo, function (PDO $tx) use ($id) {
                        return db_update($tx, 'order', ['OrderStatus' => 'Cancelled'], ['OrderID' => $id]);
                    });
                    flash_set('success', 'Order #' . $id . ' was cancelled. Nothing will be shipped or invoiced.');
                } catch (Throwable $e) {
                    flash_set('error', 'The order could not be cancelled: ' . $e->getMessage());
                }
                header('Location: order-detail.php?id=' . $id);
                exit;
            }
        }
        header('Location: order-detail.php?id=' . $id);
        exit;
    }

    if ($intent === 'reorder') {
        $lines = db_fetch_all(
            'SELECT oi.ProductID, oi.Quantity,
                    p.ProductID AS LiveID
             FROM order_item oi
             LEFT JOIN product p ON p.ProductID = oi.ProductID
             WHERE oi.OrderID = :id',
            [':id' => $id]
        );

        $added = 0;
        $gone  = 0;
        foreach ($lines as $line) {
            if ($line['LiveID'] === null) {
                $gone++;
                continue;
            }
            cart_add((int) $line['ProductID'], (int) $line['Quantity']);
            $added++;
        }

        if ($added) {
            flash_set('success', $added . ' line' . ($added === 1 ? '' : 's') . ' from order #' . $id
                . ' are back in your basket' . ($gone ? ', ' . $gone . ' product(s) are no longer sold and were skipped.' : '.'));
        } else {
            flash_set('error', 'None of the products on order #' . $id . ' are still sold, so nothing was added.');
        }
        header('Location: cart.php');
        exit;
    }
}

$items = db_fetch_all(
    'SELECT oi.OrderItemID, oi.ProductID, oi.Quantity, oi.UnitPrice, oi.TotalPrice,
            p.ProductName, p.Brand, p.Model, p.IsSerialized, p.StockQty,
            c.CategoryName
     FROM order_item oi
     JOIN product  p ON p.ProductID  = oi.ProductID
     JOIN category c ON c.CategoryID = p.CategoryID
     WHERE oi.OrderID = :id
     ORDER BY oi.OrderItemID',
    [':id' => $id]
);

$invoices = db_fetch_all(
    'SELECT InvoiceNo, InvoiceDate, PaymentStatus, TotalAmount, RemainingBalance
     FROM invoice WHERE OrderID = :id ORDER BY InvoiceNo',
    [':id' => $id]
);

$counts = stock_counts($items);
$status = (string) $order['OrderStatus'];

/* The fulfilment story, with the steps already behind us marked off. The last
 * entry is Cancelled when that is where the order ended up. */
$timeline = [];
$steps    = $status === 'Cancelled' ? ['Pending', 'Cancelled'] : ['Pending', 'Processing', 'Completed'];
$reached  = array_search($status, $steps, true);
foreach ($steps as $step => $name) {
    $timeline[] = [
        'name'    => $name,
        'done'    => $reached !== false && $step <= $reached,
        'current' => $name === $status,
        'blurb'   => match ($name) {
            'Pending'    => 'Received — the sales desk confirms stock and pricing.',
            'Processing' => 'Confirmed and being prepared for dispatch.',
            'Completed'  => 'Delivered and closed.',
            'Cancelled'  => 'Stopped before dispatch. Nothing was invoiced.',
            default      => '',
        },
    ];
}

$total       = (float) $order['TotalAmount'];
$invoice     = $invoices[0] ?? null;
$page_title  = 'Order #' . $id;
?>
<?= partial_render('partials/header.php', ['page_title' => $page_title, 'active_nav' => 'dashboard']) ?>

<section class="py-4">
  <div class="container">

    <?= render_flashes() ?>

    <nav aria-label="breadcrumb">
      <ol class="breadcrumb crumb mb-3">
        <li class="breadcrumb-item"><a href="index.php">Home</a></li>
        <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
        <li class="breadcrumb-item active" aria-current="page">Order #<?= $id ?></li>
      </ol>
    </nav>

    <?php if ($justPlaced): ?>
    <div class="alert alert-success">
      <strong><i class="fa-solid fa-circle-check me-1"></i>Order placed.</strong>
      Keep this page — the number <strong>#<?= $id ?></strong> is what the sales desk needs.
      You will find every order again under <a href="dashboard.php">your dashboard</a>.
    </div>
    <?php endif; ?>

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
      <div>
        <p class="section-label mb-1">Order</p>
        <h1 class="h3 fw-bold mb-1">#<?= $id ?>
          <?= status_badge($status) ?>
        </h1>
        <p class="text-muted mb-0">
          Placed <?= htmlspecialchars(format_date($order['OrderDate'], 'd M Y, H:i')) ?>
          by <?= htmlspecialchars($order['FullName']) ?>
          <?php if ($isStaff): ?>
            — <span class="report-badge report-badge-plain">visible to you as staff</span>
          <?php endif; ?>
        </p>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <?php if (!$isStaff && $status === 'Pending' && !$invoices): ?>
        <form method="post" action="order-detail.php?id=<?= $id ?>" data-ajax>
          <?= csrf_field() ?>
          <input type="hidden" name="intent" value="cancel">
          <button class="btn btn-outline-danger" type="submit">
            <i class="fa-solid fa-ban me-1"></i>Cancel this order
          </button>
        </form>
        <?php endif; ?>
        <form method="post" action="order-detail.php?id=<?= $id ?>" data-ajax>
          <?= csrf_field() ?>
          <input type="hidden" name="intent" value="reorder">
          <button class="btn btn-outline-dark" type="submit">
            <i class="fa-solid fa-rotate-right me-1"></i>Reorder these items
          </button>
        </form>
        <?php if ($isStaff): ?>
        <a class="btn btn-accent" href="admin-orders.php?action=view&id=<?= $id ?>">
          <i class="fa-solid fa-sliders me-1"></i>Manage in the back office
        </a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary" href="dashboard.php">All my orders</a>
      </div>
    </div>

    <div class="row g-4">
      <div class="col-lg-8">

        <div class="report-panel mb-4">
          <div class="report-panel-head">
            <h2 class="report-panel-title">Items</h2>
            <p class="report-panel-sub">Prices as confirmed when the order was placed.</p>
          </div>
          <div class="table-responsive">
            <table class="table align-middle mb-0">
              <thead>
                <tr>
                  <th scope="col">Product</th>
                  <th scope="col" class="text-end">Unit price</th>
                  <th scope="col" class="text-center">Qty</th>
                  <th scope="col" class="text-end">Line total</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($items as $item): ?>
                <?php $available = $counts[(int) $item['ProductID']] ?? 0; ?>
                <tr>
                  <td>
                    <a class="fw-semibold text-decoration-none" href="product.php?id=<?= (int) $item['ProductID'] ?>">
                      <?= htmlspecialchars($item['ProductName']) ?>
                    </a>
                    <div class="text-muted small">
                      <?= htmlspecialchars($item['CategoryName']) ?>
                      <?= !empty($item['Brand']) ? ' · ' . htmlspecialchars($item['Brand']) : '' ?>
                      <?php if (!empty($item['Model'])): ?> · <?= htmlspecialchars($item['Model']) ?><?php endif; ?>
                      · line #<?= (int) $item['OrderItemID'] ?>
                    </div>
                    <?php if (!$isStaff && !order_is_closed($status)): ?>
                    <div class="small text-muted">
                      <?php if ($available > 0): ?>
                        <i class="fa-solid fa-circle-check text-success me-1"></i><?= (int) $available ?> on hand now
                      <?php else: ?>
                        <i class="fa-solid fa-truck-fast text-warning-emphasis me-1"></i>Being sourced from the supplier
                      <?php endif; ?>
                    </div>
                    <?php endif; ?>
                  </td>
                  <td class="text-end text-nowrap"><?= money($item['UnitPrice']) ?></td>
                  <td class="text-center"><?= (int) $item['Quantity'] ?></td>
                  <td class="text-end text-nowrap fw-semibold"><?= money($item['TotalPrice']) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr>
                  <td colspan="3" class="text-end text-muted">Order total</td>
                  <td class="text-end fw-bold"><?= money($total) ?></td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>

        <div class="report-panel">
          <div class="report-panel-head">
            <h2 class="report-panel-title">Delivery &amp; notes</h2>
            <p class="report-panel-sub">Exactly what the dispatch team reads.</p>
          </div>
          <div class="delivery-note"><?= nl2br(htmlspecialchars((string) $order['ShippingAddress'])) ?: '<span class="text-muted">No delivery address was recorded.</span>' ?></div>
        </div>
      </div>

      <div class="col-lg-4">
        <div class="report-panel mb-4">
          <div class="report-panel-head">
            <h2 class="report-panel-title">Progress</h2>
          </div>
          <ol class="order-timeline mb-0">
            <?php foreach ($timeline as $step): ?>
            <li class="<?= $step['current'] ? 'is-current' : ($step['done'] ? 'is-done' : '') ?>">
              <span class="order-timeline-dot"><i class="fa-solid <?= $step['done'] ? 'fa-check' : 'fa-circle' ?>"></i></span>
              <div>
                <div class="fw-semibold"><?= htmlspecialchars($step['name']) ?></div>
                <div class="small text-muted"><?= htmlspecialchars($step['blurb']) ?></div>
              </div>
            </li>
            <?php endforeach; ?>
          </ol>
        </div>

        <div class="report-panel mb-4">
          <div class="report-panel-head">
            <h2 class="report-panel-title">Paperwork</h2>
          </div>
          <?php if ($invoices): ?>
            <?php foreach ($invoices as $inv): ?>
            <div class="d-flex justify-content-between align-items-center border rounded-3 p-3 mb-2">
              <div>
                <a class="fw-semibold text-decoration-none" href="invoice.php?no=<?= (int) $inv['InvoiceNo'] ?>">
                  Invoice #<?= (int) $inv['InvoiceNo'] ?>
                </a>
                <div class="small text-muted">
                  <?= htmlspecialchars(format_date($inv['InvoiceDate'])) ?> ·
                  <?= status_badge((string) $inv['PaymentStatus']) ?>
                </div>
              </div>
              <div class="text-end small">
                <div><?= money($inv['TotalAmount']) ?></div>
                <?php if ((float) $inv['RemainingBalance'] > 0): ?>
                <div class="text-danger"><?= money($inv['RemainingBalance']) ?> due</div>
                <?php endif; ?>
              </div>
            </div>
            <?php endforeach; ?>
          <?php else: ?>
          <p class="text-muted small mb-0">
            No invoice has been raised yet. The sales desk confirms the order first, then raises the
            invoice against it — it will appear here, with the serial numbers of any serialized units.
          </p>
          <?php endif; ?>
        </div>

        <div class="report-panel">
          <div class="report-panel-head">
            <h2 class="report-panel-title">Placed by</h2>
          </div>
          <table class="table table-sm mb-0">
            <tbody>
              <tr><th scope="row" class="text-muted fw-normal">Name</th><td class="text-end"><?= htmlspecialchars($order['FullName']) ?></td></tr>
              <tr><th scope="row" class="text-muted fw-normal">Account</th><td class="text-end"><?= htmlspecialchars($order['Username']) ?></td></tr>
              <tr><th scope="row" class="text-muted fw-normal">Email</th><td class="text-end"><?= htmlspecialchars($order['Email']) ?></td></tr>
              <tr><th scope="row" class="text-muted fw-normal">Phone</th><td class="text-end"><?= htmlspecialchars((string) ($order['Phone'] ?: '—')) ?></td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</section>

<?= partial_render('partials/footer.php') ?>
