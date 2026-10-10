<?php
/**
 * SebaBD — Orders (back office).
 *
 * Retail orders placed on the storefront (#4): one customer login, many
 * order_item lines (#5, #6), and at most one invoice (#15).
 *
 *   admin-orders.php                      every order with its lines
 *   admin-orders.php?action=view&id=      one order, its lines and its history
 *   POST intent=status|delete             the writes
 *
 * Order status is the fulfilment story: Pending → Processing → Completed, with
 * Cancelled available until money has been invoiced against the order.
 */
require_once __DIR__ . '/admin-bootstrap.php';

$user   = require_module('admin-orders.php');
$dbUp   = db() !== null;
$action = (string) ($_GET['action'] ?? 'list');
$id     = max(0, (int) ($_GET['id'] ?? 0));
$errors = [];

$filterStatus = trim((string) ($_GET['status'] ?? ''));
// '' = every order, 'Retail' = customer orders only, 'Internal' = SebaBD's own.
$filterChannel = trim((string) ($_GET['channel'] ?? ''));
$search       = trim((string) ($_GET['q'] ?? ''));
if (!in_array($filterChannel, [ORDER_TYPE_RETAIL, ORDER_TYPE_INTERNAL], true)) {
    $filterChannel = '';
}

/** A badge naming the order's channel, for the list and the order page. */
function order_channel_badge(string $orderType): string
{
    return $orderType === ORDER_TYPE_INTERNAL
        ? '<span class="report-badge report-badge-info"><i class="fa-solid fa-building-shield me-1"></i>Internal</span>'
        : '<span class="report-badge report-badge-plain">Retail</span>';
}

// order_statuses() lives in helpers.php: the customer's order page uses the
// same list, and a status means the same thing on both sides of the counter.

/* ------------------------------------------------------------- the writes */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard('admin-orders.php');

    $intent = post_str('intent');
    $pdo    = db();

    if (!$pdo) {
        $errors[] = 'The database is offline, so nothing can be saved right now.';
    } elseif ($intent === 'status') {
        $orderId = post_int('order_id');
        $status  = post_str('status');
        try {
            $order = db_fetch_all('SELECT OrderID, OrderStatus FROM `order` WHERE OrderID = :id', [':id' => $orderId]);
            if (!$order) {
                $errors[] = 'That order no longer exists.';
            } elseif (!in_array($status, order_statuses(), true)) {
                $errors[] = 'Choose one of the listed order statuses.';
            } elseif ($status === 'Cancelled' && db_count('invoice', 'OrderID = :id', [':id' => $orderId]) > 0) {
                $errors[] = 'Order #' . $orderId . ' has been invoiced, so it cannot be cancelled. '
                          . 'The invoice records a sale that has already been billed.';
            } else {
                db_update($pdo, 'order', ['OrderStatus' => $status], ['OrderID' => $orderId]);
                flash_set('success', 'Order #' . $orderId . ' marked ' . $status . '.');
                post_success('admin-orders.php?action=view&id=' . $orderId);
            }
        } catch (Throwable $e) {
            $errors[] = admin_error_message($e, 'Could not change the order status.');
        }
        $action  = 'view';
        $id      = $orderId;
    } elseif ($intent === 'delete') {
        $orderId = post_int('order_id');
        try {
            if ($orderId <= 0) {
                $errors[] = 'That order no longer exists.';
            } elseif (($invoices = db_count('invoice', 'OrderID = :id', [':id' => $orderId])) > 0) {
                $errors[] = 'Order #' . $orderId . ' has been invoiced, so it cannot be deleted — '
                          . 'the invoice is built on it. Delete that invoice first.';
            } else {
                // order_item.OrderID is ON DELETE CASCADE.
                db_delete($pdo, 'order', ['OrderID' => $orderId]);
                flash_set('success', 'Order #' . $orderId . ' and its lines were deleted.');
                post_success('admin-orders.php');
            }
        } catch (Throwable $e) {
            $errors[] = admin_error_message($e, 'Could not delete that order.');
        }
        $action = 'list';
    }
}

/* -------------------------------------------------------------- the reads */

$rows      = [];
$order     = null;
$orderItems = [];
$invoice   = null;
$totals    = [];
$orderPage = null;

if ($dbUp) {
    if ($action === 'view') {
        $found = db_fetch_all(
            'SELECT o.OrderID, o.UserID, o.OrderDate, o.TotalAmount, o.OrderStatus, o.OrderType,
                    o.BranchID, o.ShippingAddress,
                    u.FullName, u.Username, u.Email, u.Phone,
                    c.ClientID, c.CompanyName,
                    b.BranchName, bc.CompanyName AS BranchCompany,
                    (SELECT COUNT(*) FROM order_item oi WHERE oi.OrderID = o.OrderID) AS Items
             FROM `order` o
             JOIN `user` u ON u.UserID = o.UserID
             LEFT JOIN client c ON c.UserID = u.UserID
             LEFT JOIN client_branch b ON b.BranchID = o.BranchID
             LEFT JOIN client bc ON bc.ClientID = b.ClientID
             WHERE o.OrderID = :id',
            [':id' => $id]
        );
        $order = $found[0] ?? null;
        if ($order === null) {
            deny_page(404, 'Order not found', 'Order #' . $id . ' does not exist.', 'admin-orders.php', 'All orders');
        }

        $orderItems = db_fetch_all(
            'SELECT oi.OrderItemID, oi.ProductID, oi.Quantity, oi.UnitPrice, oi.TotalPrice,
                    p.ProductName, p.Brand, p.Model, p.IsSerialized, p.StockQty,
                    (SELECT COUNT(*) FROM product_instance pi
                      WHERE pi.ProductID = oi.ProductID AND pi.Status = \'In Stock\') AS UnitsInStock
             FROM order_item oi
             JOIN product p ON p.ProductID = oi.ProductID
             WHERE oi.OrderID = :id
             ORDER BY oi.OrderItemID',
            [':id' => $id]
        );

        $invoice = db_fetch_all(
            'SELECT InvoiceNo, InvoiceDate, PaymentStatus, TotalAmount, RemainingBalance
             FROM invoice WHERE OrderID = :id',
            [':id' => $id]
        );
        $invoice = $invoice[0] ?? null;
    }

    $where  = [];
    $params = [];
    if ($filterStatus !== '' && in_array($filterStatus, order_statuses(), true)) {
        $where[]           = 'o.OrderStatus = :status';
        $params[':status'] = $filterStatus;
    }
    if ($filterChannel !== '') {
        $where[]            = 'o.OrderType = :channel';
        $params[':channel'] = $filterChannel;
    }
    if ($search !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        $where[] = '(u.FullName LIKE :q1 OR u.Username LIKE :q2 OR o.ShippingAddress LIKE :q3 OR c.CompanyName LIKE :q4)';
        $params[':q1'] = $like;
        $params[':q2'] = $like;
        $params[':q3'] = $like;
        $params[':q4'] = $like;
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $from     = ' FROM `order` o
                  JOIN `user` u ON u.UserID = o.UserID
                  LEFT JOIN client c ON c.UserID = u.UserID';

    $countRow  = db_fetch_all('SELECT COUNT(*) AS n' . $from . $whereSql, $params);
    $orderPage = paginate((int) ($countRow[0]['n'] ?? 0), 20);

    $rows = db_fetch_all(
        'SELECT o.OrderID, o.OrderDate, o.TotalAmount, o.OrderStatus, o.OrderType, o.BranchID, o.ShippingAddress,
                u.UserID, u.FullName, u.Username, c.ClientID, c.CompanyName,
                (SELECT COUNT(*) FROM order_item oi WHERE oi.OrderID = o.OrderID) AS Items,
                (SELECT i.InvoiceNo FROM invoice i WHERE i.OrderID = o.OrderID) AS InvoiceNo'
        . $from . $whereSql . '
         ORDER BY o.OrderDate DESC, o.OrderID DESC
         LIMIT ' . $orderPage['perPage'] . ' OFFSET ' . $orderPage['offset'],
        $params
    );

    $totals = db_fetch_all(
        "SELECT COUNT(*) AS AllOrders,
                COALESCE(SUM(TotalAmount), 0) AS Revenue,
                SUM(CASE WHEN OrderStatus = 'Pending' THEN 1 ELSE 0 END) AS Pending,
                SUM(CASE WHEN OrderStatus = 'Processing' THEN 1 ELSE 0 END) AS Processing,
                SUM(CASE WHEN OrderStatus = 'Completed' THEN 1 ELSE 0 END) AS Completed,
                SUM(CASE WHEN OrderType = 'Internal' THEN 1 ELSE 0 END) AS InternalOrders,
                COALESCE(SUM(CASE WHEN OrderType = 'Internal' THEN TotalAmount ELSE 0 END), 0) AS InternalValue,
                SUM(CASE WHEN OrderStatus NOT IN ('Cancelled') AND NOT EXISTS
                     (SELECT 1 FROM invoice i WHERE i.OrderID = `order`.OrderID) THEN 1 ELSE 0 END) AS Uninvoiced
         FROM `order`"
    );
    $totals = $totals[0] ?? [];
}

ajax_errors($errors);

$filterQs = http_build_query(array_filter(['status' => $filterStatus, 'channel' => $filterChannel !== '' ? $filterChannel : null, 'q' => $search]));
$listUrl  = 'admin-orders.php' . ($filterQs !== '' ? '?' . $filterQs : '');

echo partial_render('partials/header.php', ['page_title' => 'Orders', 'active_nav' => 'admin']);
echo admin_lead('Orders', 'Every order placed on the storefront, with its lines, its fulfilment status and the invoice raised from it.');
?>

<div class="container pb-5">
  <?= render_flashes() ?>

  <?php if (!$dbUp): ?>
  <div class="alert alert-danger mb-0">
    <i class="fa-solid fa-plug-circle-xmark me-2"></i>
    The database is offline, so orders cannot be listed. Start MySQL in the XAMPP control panel and reload.
  </div>
  <?php else: ?>

  <div class="ajax-errors" data-ajax-errors>
    <?php if ($errors): ?>
    <div class="alert alert-danger">
      <ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= htmlspecialchars($err) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($action === 'list'): ?>

  <div class="row g-3 mb-4">
    <?= report_kpi('Orders placed', number_format((int) ($totals['AllOrders'] ?? 0)), money((float) ($totals['Revenue'] ?? 0)) . ' of orders in total', 'fa-receipt') ?>
    <?= report_kpi('To action', number_format((int) ($totals['Pending'] ?? 0)), (int) ($totals['Processing'] ?? 0) . ' already being processed', 'fa-hourglass-half') ?>
    <?= report_kpi('Completed', number_format((int) ($totals['Completed'] ?? 0)), 'Delivered and closed', 'fa-circle-check') ?>
    <?= report_kpi('Not yet invoiced', number_format((int) ($totals['Uninvoiced'] ?? 0)), 'Orders waiting for an invoice', 'fa-file-invoice-dollar') ?>
    <?= report_kpi('Internal', number_format((int) ($totals['InternalOrders'] ?? 0)),
        'SebaBD\'s own orders · ' . money((float) ($totals['InternalValue'] ?? 0)), 'fa-building-shield') ?>
  </div>

  <form class="report-filter mb-3" method="get" action="admin-orders.php">
    <div class="report-filter-fields">
      <label class="report-filter-field">
        <span class="form-label">Customer or address</span>
        <input class="form-control form-control-sm" type="search" name="q" value="<?= htmlspecialchars($search) ?>">
      </label>
      <label class="report-filter-field">
        <span class="form-label">Status</span>
        <select class="form-select form-select-sm" name="status">
          <option value="">Any status</option>
          <?php foreach (order_statuses() as $status): ?>
          <option value="<?= htmlspecialchars($status) ?>" <?= $filterStatus === $status ? 'selected' : '' ?>><?= htmlspecialchars($status) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="report-filter-field">
        <span class="form-label">Channel</span>
        <select class="form-select form-select-sm" name="channel">
          <option value="">Every channel</option>
          <option value="Retail" <?= $filterChannel === ORDER_TYPE_RETAIL ? 'selected' : '' ?>>Customer orders</option>
          <option value="Internal" <?= $filterChannel === ORDER_TYPE_INTERNAL ? 'selected' : '' ?>>Internal (SebaBD's own)</option>
        </select>
      </label>
    </div>
    <div class="report-filter-actions">
      <button class="btn btn-accent btn-sm" type="submit"><i class="fa-solid fa-filter me-1"></i>Apply</button>
      <a class="btn btn-outline-secondary btn-sm" href="admin-orders.php">Reset</a>
    </div>
  </form>

  <?= partial_render('partials/admin-list.php', [
      'panel_title' => 'Orders',
      'panel_sub'   => 'Newest first. An order becomes an invoice once the sale is billed.',
      'columns'     => ['#', 'Placed', 'Customer', 'Ship to', 'Items', 'Total', 'Status', ''],
      'rows'        => array_map(function ($row) {
          $actions = '<a class="btn btn-sm btn-outline-secondary" href="admin-orders.php?action=view&id=' . (int) $row['OrderID'] . '" title="Open">'
                   . '<i class="fa-solid fa-eye"></i><span class="visually-hidden">Open</span></a> ';
          if ($row['InvoiceNo'] === null) {
              $actions .= '<a class="btn btn-sm btn-outline-dark" href="admin-invoices.php?action=new&from_order=' . (int) $row['OrderID'] . '" title="Raise an invoice">'
                        . '<i class="fa-solid fa-file-invoice-dollar"></i><span class="visually-hidden">Invoice</span></a> ';
          } else {
              $actions .= '<a class="btn btn-sm btn-outline-success" href="admin-invoices.php?action=view&no=' . (int) $row['InvoiceNo'] . '" title="Invoice">'
                        . '<i class="fa-solid fa-file-invoice"></i><span class="visually-hidden">Invoice</span></a> ';
          }
          $actions .= '<form method="post" action="admin-orders.php" class="d-inline"'
                    . ' onsubmit="return confirm(\'Delete order #' . (int) $row['OrderID'] . '?\');">'
                    . csrf_field()
                    . '<input type="hidden" name="intent" value="delete">'
                    . '<input type="hidden" name="order_id" value="' . (int) $row['OrderID'] . '">'
                    . '<button class="btn btn-sm btn-outline-danger" type="submit">'
                    . '<i class="fa-solid fa-trash-can"></i><span class="visually-hidden">Delete</span></button></form>';

          return [
              '<a class="text-decoration-none" href="admin-orders.php?action=view&id=' . (int) $row['OrderID'] . '">'
                  . (int) $row['OrderID'] . '</a>',
              format_date($row['OrderDate'], 'd M Y H:i'),
              '<span class="fw-semibold">' . htmlspecialchars($row['FullName']) . '</span>'
                  . order_channel_badge((string) $row['OrderType'])
                  . '<small class="d-block text-muted">' . htmlspecialchars($row['Username'])
                  . ($row['CompanyName'] !== null ? ' · ' . htmlspecialchars($row['CompanyName']) : '') . '</small>',
              '<span class="small">' . htmlspecialchars((string) ($row['ShippingAddress'] ?? '—')) . '</span>',
              (int) $row['Items'],
              '<span class="admin-line-total">' . money($row['TotalAmount']) . '</span>',
              status_badge((string) $row['OrderStatus'])
                  . ($row['InvoiceNo'] !== null
                      ? '<small class="d-block text-muted">invoiced #' . (int) $row['InvoiceNo'] . '</small>'
                      : ''),
              '<div class="admin-actions-cell">' . $actions . '</div>',
          ];
      }, $rows),
      'empty_icon'   => 'fa-receipt',
      'empty_title'  => $filterStatus !== '' || $search !== '' ? 'No orders matched' : 'No orders yet',
      'empty_text'   => $filterStatus !== '' || $search !== ''
          ? 'Clear the filters to see every order.'
          : 'Orders arrive from the storefront — the quick order form and the shopping cart both create them.',
      'empty_action' => $filterStatus !== '' || $search !== ''
          ? '<a class="btn btn-outline-secondary btn-sm" href="admin-orders.php">Clear filters</a>'
          : '<a class="btn btn-accent btn-sm" href="index.php?all=1#featured-products"><i class="fa-solid fa-store me-1"></i>Open the storefront</a>',
      'summary'      => pagination_summary($orderPage, 'order'),
      'pagination'   => pagination_links($orderPage, $listUrl),          'note'         => 'Order lines snapshot the price at order time (<code>UnitPrice</code>), so a later catalog change never rewrites an order. '
                            . '<code>order.OrderType</code> separates customer orders from SebaBD\'s own internal orders, which are booked to the internal branch in <code>order.BranchID</code>.',
  ]) ?>

  <?php else: ?>
  <div class="row g-4">
    <div class="col-lg-8">
      <div class="report-panel">
        <div class="report-panel-head d-flex flex-wrap justify-content-between align-items-start gap-2">
          <div>
            <h2 class="report-panel-title">Order #<?= (int) $order['OrderID'] ?></h2>
            <p class="report-panel-sub">
              Placed <?= format_date($order['OrderDate'], 'd M Y H:i') ?>
              by <?= htmlspecialchars($order['FullName']) ?> (<?= htmlspecialchars($order['Username']) ?>)
              <?= order_channel_badge((string) $order['OrderType']) ?>
            </p>
          </div>
          <?= status_badge((string) $order['OrderStatus']) ?>
        </div>

        <?php if (($order['OrderType'] ?? ORDER_TYPE_RETAIL) === ORDER_TYPE_INTERNAL): ?>
        <div class="alert alert-light border">
          <i class="fa-solid fa-building-shield me-2"></i>
          This is an <strong>internal order</strong> — SebaBD buying for itself, not a customer sale.
          <?php if (!empty($order['BranchName'])): ?>
          It is booked to <?= htmlspecialchars((string) $order['BranchCompany']) ?> —
          <?= htmlspecialchars((string) $order['BranchName']) ?>, so its invoice follows the same branch.
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="table-responsive">
          <table class="table table-sm report-th align-middle mb-0">
            <thead>
              <tr><th scope="col">#</th><th scope="col">Product</th><th scope="col" class="text-end">Qty</th>
                  <th scope="col" class="text-end">Unit price</th><th scope="col" class="text-end">Line total</th>
                  <th scope="col" class="text-end">Stock</th></tr>
            </thead>
            <tbody>
              <?php foreach ($orderItems as $item): ?>
              <tr>
                <td class="text-muted"><?= (int) $item['OrderItemID'] ?></td>
                <td>
                  <a class="text-decoration-none" href="product.php?id=<?= (int) $item['ProductID'] ?>"><?= htmlspecialchars($item['ProductName']) ?></a>
                  <small class="d-block text-muted">
                    <?= htmlspecialchars(trim(($item['Brand'] ?? '') . ($item['Model'] ? ' · ' . $item['Model'] : '')) ?: '—') ?>
                    · product #<?= (int) $item['ProductID'] ?>
                  </small>
                </td>
                <td class="text-end"><?= (int) $item['Quantity'] ?></td>
                <td class="text-end admin-line-total"><?= money($item['UnitPrice']) ?></td>
                <td class="text-end admin-line-total"><?= money($item['TotalPrice']) ?></td>
                <td class="text-end">
                  <?php $onHand = (int) $item['IsSerialized'] === 1 ? (int) $item['UnitsInStock'] : (int) $item['StockQty']; ?>
                  <span class="report-badge <?= $onHand >= (int) $item['Quantity'] ? 'report-badge-ok' : ($onHand > 0 ? 'report-badge-warn' : 'report-badge-danger') ?>">
                    <?= $onHand ?>
                  </span>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr>
                <td colspan="4" class="text-end fw-bold">Order total</td>
                <td class="text-end fw-bold"><?= money($order['TotalAmount']) ?></td>
                <td></td>
              </tr>
            </tfoot>
          </table>
        </div>

        <p class="text-muted small mb-0 mt-3">
          <strong>Ship to:</strong> <?= htmlspecialchars((string) ($order['ShippingAddress'] ?? '—')) ?>
        </p>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="form-card">
        <div class="form-card-head">
          <h2 class="h5 mb-1">Fulfilment</h2>
          <p class="text-muted small mb-0">Move the order along as it is confirmed, packed and delivered.</p>
        </div>
        <form method="post" action="admin-orders.php" data-ajax>
          <?= csrf_field() ?>
          <input type="hidden" name="intent" value="status">
          <input type="hidden" name="order_id" value="<?= (int) $order['OrderID'] ?>">
          <div class="mb-3">
            <label class="form-label" for="order_status">Order status</label>
            <select class="form-select" id="order_status" name="status">
              <?php foreach (order_statuses() as $status): ?>
              <option value="<?= htmlspecialchars($status) ?>" <?= $order['OrderStatus'] === $status ? 'selected' : '' ?>>
                <?= htmlspecialchars($status) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <button class="btn btn-accent w-100" type="submit"><i class="fa-solid fa-arrows-rotate me-1"></i>Update status</button>
        </form>
      </div>

      <div class="report-panel">
        <div class="report-panel-head">
          <h2 class="report-panel-title">Billing</h2>
        </div>
        <?php if ($invoice): ?>
        <div class="alert alert-success">
          <i class="fa-solid fa-file-invoice-dollar me-1"></i>
          Invoiced as <a href="admin-invoices.php?action=view&no=<?= (int) $invoice['InvoiceNo'] ?>">#<?= (int) $invoice['InvoiceNo'] ?></a>
          on <?= format_date($invoice['InvoiceDate']) ?> —
          <?= htmlspecialchars($invoice['PaymentStatus']) ?>, <?= money($invoice['RemainingBalance']) ?> still due.
        </div>
        <a class="btn btn-outline-secondary w-100" href="invoice.php?no=<?= (int) $invoice['InvoiceNo'] ?>">
          <i class="fa-solid fa-print me-2"></i>Printable invoice
        </a>
        <?php elseif ($order['OrderStatus'] === 'Cancelled'): ?>
        <p class="text-muted small mb-0">This order was cancelled, so nothing will be invoiced for it.</p>
        <?php else: ?>
        <p class="text-muted small">
          An order is invoiced once (relationship 15 in the schema), which is what turns a placed order into money owed.
        </p>
        <a class="btn btn-accent w-100" href="admin-invoices.php?action=new&from_order=<?= (int) $order['OrderID'] ?>">
          <i class="fa-solid fa-file-invoice-dollar me-2"></i>Raise an invoice
        </a>
        <?php endif; ?>
      </div>

      <div class="report-panel">
        <div class="report-panel-head">
          <h2 class="report-panel-title">Customer</h2>
        </div>
        <?= admin_detail_row('Name', '<a href="admin-users.php?action=edit&id=' . (int) $order['UserID'] . '">'
            . htmlspecialchars($order['FullName']) . '</a>') ?>
        <?= admin_detail_row('Username', htmlspecialchars($order['Username'])) ?>
        <?= admin_detail_row('Email', htmlspecialchars((string) ($order['Email'] ?? '—'))) ?>
        <?= admin_detail_row('Phone', htmlspecialchars((string) ($order['Phone'] ?? '—'))) ?>
        <?= admin_detail_row('Company', $order['ClientID'] !== null
            ? '<a href="admin-customers.php?action=edit&id=' . (int) $order['ClientID'] . '">' . htmlspecialchars((string) $order['CompanyName']) . '</a>'
            : '<span class="text-muted">no company linked</span>') ?>
      </div>

      <div class="report-panel">
        <div class="report-panel-head">
          <h2 class="report-panel-title">Danger zone</h2>
        </div>
        <form method="post" action="admin-orders.php" class="d-grid"
              onsubmit="return confirm('Delete order #<?= (int) $order['OrderID'] ?> and its lines?');">
          <?= csrf_field() ?>
          <input type="hidden" name="intent" value="delete">
          <input type="hidden" name="order_id" value="<?= (int) $order['OrderID'] ?>">
          <button class="btn btn-outline-danger" type="submit"
              <?= $invoice ? 'disabled title="Invoiced orders cannot be deleted"' : '' ?>>
            <i class="fa-solid fa-trash-can me-1"></i>Delete this order
          </button>
        </form>
        <p class="text-muted small mb-0 mt-2">
          <?= $invoice
              ? 'Refused while an invoice refers to the order.'
              : 'Its lines go with it (cascading delete); stock is not changed.' ?>
        </p>
      </div>

      <a class="btn btn-outline-secondary w-100" href="admin-orders.php">
        <i class="fa-solid fa-arrow-left me-2"></i>All orders
      </a>
    </div>
  </div>
  <?php endif; ?>

  <?php endif; ?>
</div>

<?= partial_render('partials/footer.php') ?>
