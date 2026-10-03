<?php
/**
 * SebaBD — account dashboard (role aware).
 *
 * One page, two audiences:
 *
 *   staff  (Admin / Sales Manager / Inventory Manager)
 *          the store at a glance — revenue, open orders and quotations,
 *          receivables, stock alerts and best-selling products, plus links
 *          into the KoolReport module.
 *
 *   client (Client Account / B2B Client Account)
 *          their own records: orders placed with their user account,
 *          quotations and invoices linked through their company branches,
 *          and the company/branch details stored against their login.
 *
 * Every figure comes from the store's own records; the page does not load
 * KoolReport, so it stays fast.
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/catalog-data.php';

$user    = require_login('dashboard.php');
$isStaff = is_staff_user($user);
$dbUp    = db() !== null;

/** Badge colour for a status value stored in the database. */
function dash_badge(string $status): string
{
    return match (strtolower(trim($status))) {
        'completed', 'paid', 'accepted' => 'report-badge-ok',
        'processing', 'partial', 'pending' => 'report-badge-warn',
        'cancelled', 'rejected', 'due' => 'report-badge-danger',
        default => 'report-badge-plain',
    };
}

/* ------------------------------------------------------------ staff data */

$kpis            = [];
$recentOrders    = [];
$stockAlerts     = [];
$receivables     = [];
$latestQuotes    = [];
$topProducts     = [];
$snapshot        = [];

if ($isStaff && $dbUp) {
    $orderStats   = db_fetch_all('SELECT COUNT(*) AS n, COALESCE(SUM(TotalAmount), 0) AS revenue FROM `order`');
    $pendingStats = db_fetch_all("SELECT COUNT(*) AS n FROM `order` WHERE OrderStatus = 'Pending'");
    $quoteStats   = db_fetch_all("SELECT COUNT(*) AS n FROM quotation WHERE Status = 'Pending'");
    $dueStats     = db_fetch_all('SELECT COUNT(*) AS n, COALESCE(SUM(RemainingBalance), 0) AS bal FROM invoice WHERE RemainingBalance > 0');

    $orderCount = (int) ($orderStats[0]['n'] ?? 0);

    $kpis = [
        [
            'icon'  => 'fa-solid fa-sack-dollar',
            'label' => 'Revenue',
            'value' => money((float) ($orderStats[0]['revenue'] ?? 0)),
            'hint'  => 'From ' . $orderCount . ' order' . ($orderCount === 1 ? '' : 's') . ' placed',
        ],
        [
            'icon'  => 'fa-solid fa-receipt',
            'label' => 'Orders',
            'value' => number_format($orderCount),
            'hint'  => (int) ($pendingStats[0]['n'] ?? 0) . ' awaiting confirmation',
        ],
        [
            'icon'  => 'fa-solid fa-file-signature',
            'label' => 'Quotations open',
            'value' => number_format((int) ($quoteStats[0]['n'] ?? 0)),
            'hint'  => 'Waiting for a sales decision',
        ],
        [
            'icon'  => 'fa-solid fa-hand-holding-dollar',
            'label' => 'Payments due',
            'value' => money((float) ($dueStats[0]['bal'] ?? 0)),
            'hint'  => (int) ($dueStats[0]['n'] ?? 0) . ' unpaid invoice' . ((int) ($dueStats[0]['n'] ?? 0) === 1 ? '' : 's'),
        ],
    ];

    $recentOrders = db_fetch_all(
        'SELECT o.OrderID, o.OrderDate, o.TotalAmount, o.OrderStatus, u.FullName, u.Username,
                (SELECT COUNT(*) FROM order_item oi WHERE oi.OrderID = o.OrderID) AS Items
         FROM `order` o
         JOIN `user` u ON u.UserID = o.UserID
         ORDER BY o.OrderDate DESC, o.OrderID DESC
         LIMIT 6'
    );

    $stockRows = db_fetch_all(
        "SELECT p.ProductID, p.ProductName, p.IsSerialized, p.StockQty, c.CategoryName,
                (SELECT COUNT(*) FROM product_instance pi
                  WHERE pi.ProductID = p.ProductID AND pi.Status = 'In Stock') AS SerialStock
         FROM product p
         JOIN category c ON c.CategoryID = p.CategoryID
         ORDER BY c.CategoryID, p.ProductName"
    );
    foreach ($stockRows as $row) {
        $onHand = ((int) $row['IsSerialized']) === 1 ? (int) $row['SerialStock'] : (int) $row['StockQty'];
        if ($onHand <= 5) {
            $row['OnHand'] = $onHand;
            $stockAlerts[] = $row;
        }
    }

    $receivables = db_fetch_all(
        'SELECT i.InvoiceNo, i.InvoiceDate, i.PaymentStatus, i.TotalAmount, i.RemainingBalance,
                b.BranchName, c.CompanyName
         FROM invoice i
         JOIN client_branch b ON b.BranchID = i.BranchID
         JOIN client        c ON c.ClientID = b.ClientID
         WHERE i.RemainingBalance > 0
         ORDER BY i.RemainingBalance DESC, i.InvoiceNo
         LIMIT 6'
    );

    $latestQuotes = db_fetch_all(
        'SELECT q.QuotationNo, q.QuotationDate, q.Subject, q.TotalAmount, q.Status,
                b.BranchName, c.CompanyName
         FROM quotation q
         JOIN client_branch b ON b.BranchID = q.BranchID
         JOIN client        c ON c.ClientID  = b.ClientID
         ORDER BY q.QuotationDate DESC, q.QuotationNo DESC
         LIMIT 5'
    );

    $topProducts = db_fetch_all(
        'SELECT p.ProductID, p.ProductName, c.CategoryName,
                SUM(oi.Quantity)   AS Units,
                SUM(oi.TotalPrice) AS Revenue
         FROM order_item oi
         JOIN product  p ON p.ProductID  = oi.ProductID
         JOIN category c ON c.CategoryID = p.CategoryID
         GROUP BY p.ProductID, p.ProductName, c.CategoryName
         ORDER BY Revenue DESC
         LIMIT 5'
    );

    $snapshot = db_fetch_all(
        "SELECT (SELECT COUNT(*) FROM product)  AS Products,
                (SELECT COUNT(*) FROM category) AS Categories,
                (SELECT COUNT(*) FROM client)   AS Customers,
                (SELECT COUNT(*) FROM client_branch) AS Branches,
                (SELECT COUNT(*) FROM product_instance WHERE Status = 'In Stock') AS SerialUnits,
                (SELECT COALESCE(SUM(StockQty), 0) FROM product WHERE IsSerialized = 0) AS BulkUnits,
                (SELECT COUNT(*) FROM vendor)   AS Suppliers"
    );
    $snapshot = $snapshot[0] ?? [];
}

/* ----------------------------------------------------------- client data */

$profile      = [];
$client       = null;
$branches     = [];
$myOrders     = [];
$myQuotes     = [];
$myInvoices   = [];
$clientKpis   = [];

if (!$isStaff && $dbUp) {
    $profileRows = db_fetch_all(
        'SELECT Username, Email, Phone, CreatedAt FROM `user` WHERE UserID = :id',
        [':id' => (int) $user['UserID']]
    );
    $profile = $profileRows[0] ?? [];

    $clientRows = db_fetch_all(
        'SELECT ClientID, CompanyName, ContactPerson, Email, Phone, Address
         FROM client WHERE UserID = :uid LIMIT 1',
        [':uid' => (int) $user['UserID']]
    );
    $client = $clientRows[0] ?? null;

    if ($client) {
        $branches = db_fetch_all(
            'SELECT BranchID, BranchName, BranchAddress, ContactNo
             FROM client_branch WHERE ClientID = :cid ORDER BY BranchID',
            [':cid' => (int) $client['ClientID']]
        );
    }

    $myOrders = db_fetch_all(
        "SELECT o.OrderID, o.OrderDate, o.TotalAmount, o.OrderStatus, o.ShippingAddress,
                (SELECT COUNT(*) FROM order_item oi WHERE oi.OrderID = o.OrderID) AS Items
         FROM `order` o
         WHERE o.UserID = :uid
         ORDER BY o.OrderDate DESC, o.OrderID DESC",
        [':uid' => (int) $user['UserID']]
    );

    if ($client) {
        $myQuotes = db_fetch_all(
            'SELECT q.QuotationNo, q.QuotationDate, q.Subject, q.TotalAmount, q.Status, b.BranchName
             FROM quotation q
             JOIN client_branch b ON b.BranchID = q.BranchID
             WHERE b.ClientID = :cid
             ORDER BY q.QuotationDate DESC, q.QuotationNo DESC',
            [':cid' => (int) $client['ClientID']]
        );

        $myInvoices = db_fetch_all(
            'SELECT i.InvoiceNo, i.InvoiceDate, i.PaymentStatus, i.TotalAmount, i.RemainingBalance, b.BranchName,
                    (SELECT COUNT(*) FROM payment_receipt pr WHERE pr.InvoiceNo = i.InvoiceNo) AS Receipts
             FROM invoice i
             JOIN client_branch b ON b.BranchID = i.BranchID
             WHERE b.ClientID = :cid
             ORDER BY i.InvoiceDate DESC, i.InvoiceNo DESC',
            [':cid' => (int) $client['ClientID']]
        );
    }

    $openQuotes = 0;
    foreach ($myQuotes as $q) {
        if (strcasecmp((string) $q['Status'], 'Pending') === 0) {
            $openQuotes++;
        }
    }
    $due = 0.0;
    foreach ($myInvoices as $invoice) {
        $due += (float) $invoice['RemainingBalance'];
    }

    $clientKpis = [
        ['icon' => 'fa-solid fa-receipt', 'label' => 'My orders', 'value' => number_format(count($myOrders)), 'hint' => 'Placed with your login'],
        ['icon' => 'fa-solid fa-file-signature', 'label' => 'Open quotations', 'value' => number_format($openQuotes), 'hint' => count($myQuotes) . ' total on file'],
        ['icon' => 'fa-solid fa-hand-holding-dollar', 'label' => 'Balance due', 'value' => money($due), 'hint' => count($myInvoices) . ' invoice(s)'],
        ['icon' => 'fa-solid fa-building', 'label' => 'Company branches', 'value' => $client ? number_format(count($branches)) : '—', 'hint' => $client ? (string) $client['CompanyName'] : 'Retail account'],
    ];
}
?>
<?= partial_render('partials/header.php', ['page_title' => 'Dashboard', 'active_nav' => 'dashboard']) ?>

<section class="report-lead py-4 mb-4">
  <div class="container">
    <span class="section-label"><?= $isStaff ? 'Store overview' : 'My account' ?></span>
    <h1 class="h3 fw-bold text-white mb-1">Welcome back, <?= htmlspecialchars($user['FullName']) ?></h1>
    <p class="mb-0 report-lead-sub">
      <?php if ($isStaff): ?>
        Here is what is happening across the store today — orders, quotations, stock and payments.
      <?php elseif ($client): ?>
        Your orders, quotations and invoices with <strong><?= htmlspecialchars($client['CompanyName']) ?></strong>,
        all in one place.
      <?php else: ?>
        Your orders and quotations with SebaBD, all in one place.
      <?php endif; ?>
    </p>
  </div>
</section>

<div class="container pb-5">
  <?= render_flashes() ?>

  <?php if (!$dbUp): ?>
  <div class="alert alert-warning">
    <i class="fa-solid fa-triangle-exclamation me-2"></i>The database is offline, so the dashboard has nothing to read.
    Start MySQL in the XAMPP control panel, then <a href="dashboard.php">reload</a>.
  </div>
  <?php endif; ?>

  <?php $cards = $isStaff ? $kpis : $clientKpis; ?>
  <?php if ($cards): ?>
  <div class="row g-3 mb-2">
    <?php foreach ($cards as $kpi): ?>
    <div class="col-sm-6 col-xl-3">
      <div class="report-kpi">
        <span class="report-kpi-icon"><i class="<?= htmlspecialchars($kpi['icon']) ?>"></i></span>
        <span class="report-kpi-label"><?= htmlspecialchars($kpi['label']) ?></span>
        <span class="report-kpi-value"><?= $kpi['value'] ?></span>
        <span class="report-kpi-hint"><?= htmlspecialchars($kpi['hint']) ?></span>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($isStaff): ?>
  <div class="row g-3 mt-1">
    <div class="col-lg-7">
      <div class="report-panel h-100">
        <div class="report-panel-head">
          <h2 class="report-panel-title">Recent orders</h2>
          <p class="report-panel-sub">The newest orders customers have placed with us.</p>
        </div>
        <?php if (!$recentOrders): ?>
        <p class="text-muted mb-0">No orders have come in yet. You can place one from <a href="order.php">Quick order</a>.</p>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0 report-th">
            <thead><tr><th>Order</th><th>Customer</th><th>Date</th><th>Items</th><th>Total</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($recentOrders as $row): ?>
              <tr>
                <td>#<?= (int) $row['OrderID'] ?></td>
                <td><?= htmlspecialchars($row['FullName']) ?><br><small class="text-muted">@<?= htmlspecialchars($row['Username']) ?></small></td>
                <td><?= htmlspecialchars(date('d M Y', strtotime((string) $row['OrderDate']))) ?></td>
                <td><?= (int) $row['Items'] ?></td>
                <td><?= money($row['TotalAmount']) ?></td>
                <td><span class="report-badge <?= dash_badge((string) $row['OrderStatus']) ?>"><?= htmlspecialchars($row['OrderStatus']) ?></span></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="col-lg-5">
      <div class="report-panel h-100">
        <div class="report-panel-head">
          <h2 class="report-panel-title">Stock alerts</h2>
          <p class="report-panel-sub">Products that are out of stock or close to running out.</p>
        </div>
        <?php if (!$stockAlerts): ?>
        <p class="text-muted mb-0">Every product is comfortably in stock (more than 5 units available).</p>
        <?php else: ?>
        <ul class="list-unstyled mb-0 dash-list">
          <?php foreach ($stockAlerts as $row): ?>
          <li class="d-flex justify-content-between align-items-center gap-2">
            <span>
              <a class="text-decoration-none" href="product.php?id=<?= (int) $row['ProductID'] ?>"><?= htmlspecialchars($row['ProductName']) ?></a>
              <small class="text-muted d-block"><?= htmlspecialchars($row['CategoryName']) ?></small>
            </span>
            <span class="report-badge <?= (int) $row['OnHand'] === 0 ? 'report-badge-danger' : 'report-badge-warn' ?>">
              <?= (int) $row['OnHand'] === 0 ? 'Out of stock' : (int) $row['OnHand'] . ' left' ?>
            </span>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="row g-3 mt-1">
    <div class="col-lg-5">
      <div class="report-panel h-100 mb-0">
        <div class="report-panel-head">
          <h2 class="report-panel-title">Top-selling products</h2>
          <p class="report-panel-sub">Best performers by order value.</p>
        </div>
        <?php if (!$topProducts): ?>
        <p class="text-muted mb-0">Nothing has been ordered yet.</p>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0 report-th">
            <thead><tr><th>Product</th><th>Units</th><th>Revenue</th></tr></thead>
            <tbody>
              <?php foreach ($topProducts as $row): ?>
              <tr>
                <td>
                  <a class="text-decoration-none" href="product.php?id=<?= (int) $row['ProductID'] ?>"><?= htmlspecialchars($row['ProductName']) ?></a>
                  <small class="text-muted d-block"><?= htmlspecialchars($row['CategoryName']) ?></small>
                </td>
                <td><?= (int) $row['Units'] ?></td>
                <td class="fw-semibold"><?= money($row['Revenue']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="col-lg-7">
      <div class="report-panel">
        <div class="report-panel-head">
          <h2 class="report-panel-title">Payments due</h2>
          <p class="report-panel-sub">Invoices our customers still need to pay.</p>
        </div>
        <?php if (!$receivables): ?>
        <p class="text-muted mb-0">Everything is paid up — no invoice has an outstanding balance.</p>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0 report-th">
            <thead><tr><th>Invoice</th><th>Company / branch</th><th>Date</th><th>Total</th><th>Balance</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($receivables as $row): ?>
              <tr>
                <td>#<?= (int) $row['InvoiceNo'] ?></td>
                <td><?= htmlspecialchars($row['CompanyName']) ?><br><small class="text-muted"><?= htmlspecialchars($row['BranchName']) ?></small></td>
                <td><?= htmlspecialchars(date('d M Y', strtotime((string) $row['InvoiceDate']))) ?></td>
                <td><?= money($row['TotalAmount']) ?></td>
                <td class="fw-semibold"><?= money($row['RemainingBalance']) ?></td>
                <td><span class="report-badge <?= dash_badge((string) $row['PaymentStatus']) ?>"><?= htmlspecialchars($row['PaymentStatus']) ?></span></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

      <div class="report-panel mb-0">
        <div class="report-panel-head">
          <h2 class="report-panel-title">Latest quotations</h2>
          <p class="report-panel-sub">Recent quotation requests from our customers.</p>
        </div>
        <?php if (!$latestQuotes): ?>
        <p class="text-muted mb-0">No quotations have been requested yet.</p>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0 report-th">
            <thead><tr><th>#</th><th>Subject</th><th>Company</th><th>Date</th><th>Total</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($latestQuotes as $row): ?>
              <tr>
                <td><?= (int) $row['QuotationNo'] ?></td>
                <td><?= htmlspecialchars((string) $row['Subject']) ?><br><small class="text-muted"><?= htmlspecialchars($row['BranchName']) ?></small></td>
                <td><?= htmlspecialchars($row['CompanyName']) ?></td>
                <td><?= htmlspecialchars(date('d M Y', strtotime((string) $row['QuotationDate']))) ?></td>
                <td><?= money($row['TotalAmount']) ?></td>
                <td><span class="report-badge <?= dash_badge((string) $row['Status']) ?>"><?= htmlspecialchars($row['Status']) ?></span></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="report-panel mt-3 mb-0">
    <div class="report-panel-head">
      <h2 class="report-panel-title">Store snapshot</h2>
      <p class="report-panel-sub">Everything live on the site right now.</p>
    </div>
    <?php
    $chips = [
        ['value' => (int) ($snapshot['Products'] ?? 0),   'label' => 'Products on sale'],
        ['value' => (int) ($snapshot['Categories'] ?? 0), 'label' => 'Categories'],
        ['value' => (int) ($snapshot['Customers'] ?? 0),  'label' => 'Customers'],
        ['value' => (int) ($snapshot['Branches'] ?? 0),   'label' => 'Offices served'],
        ['value' => (int) ($snapshot['SerialUnits'] ?? 0) + (int) ($snapshot['BulkUnits'] ?? 0), 'label' => 'Units in stock'],
        ['value' => (int) ($snapshot['Suppliers'] ?? 0),  'label' => 'Suppliers'],
    ];
    ?>
    <div class="row g-3 text-center">
      <?php foreach ($chips as $chip): ?>
      <div class="col-6 col-md-2">
        <div class="h4 fw-bold mb-0"><?= number_format($chip['value']) ?></div>
        <small class="text-muted"><?= htmlspecialchars($chip['label']) ?></small>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="report-panel mt-3">
    <div class="report-panel-head">
      <h2 class="report-panel-title">Quick actions</h2>
      <p class="report-panel-sub">Jump straight to the tools you use most.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
      <a class="btn btn-accent" href="reports.php"><i class="fa-solid fa-chart-line me-2"></i>Reports hub</a>
      <a class="btn btn-outline-dark" href="report-sales.php">Sales</a>
      <a class="btn btn-outline-dark" href="report-invoices.php">Invoices</a>
      <a class="btn btn-outline-dark" href="report-quotations.php">Quotations</a>
      <a class="btn btn-outline-dark" href="report-procurement.php">Procurement</a>
      <a class="btn btn-outline-dark" href="report-stock.php">Stock</a>
      <a class="btn btn-outline-secondary" href="order.php"><i class="fa-solid fa-cart-shopping me-2"></i>Quick order</a>
      <a class="btn btn-outline-secondary" href="index.php">Storefront</a>
    </div>
  </div>

  <?php else: ?>
  <div class="row g-3 mt-1">
    <div class="col-lg-7">
      <div class="report-panel h-100 mb-0">
        <div class="report-panel-head">
          <h2 class="report-panel-title">My orders</h2>
          <p class="report-panel-sub">Orders you have placed with us.</p>
        </div>
        <?php if (!$myOrders): ?>
        <p class="text-muted mb-3">You have not placed an order yet.</p>
        <a class="btn btn-accent btn-sm" href="index.php?all=1#featured-products"><i class="fa-solid fa-cart-shopping me-2"></i>Browse products</a>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0 report-th">
            <thead><tr><th>Order</th><th>Date</th><th>Items</th><th>Total</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($myOrders as $row): ?>
              <tr>
                <td>#<?= (int) $row['OrderID'] ?></td>
                <td><?= htmlspecialchars(date('d M Y', strtotime((string) $row['OrderDate']))) ?></td>
                <td><?= (int) $row['Items'] ?></td>
                <td><?= money($row['TotalAmount']) ?></td>
                <td><span class="report-badge <?= dash_badge((string) $row['OrderStatus']) ?>"><?= htmlspecialchars($row['OrderStatus']) ?></span></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="text-muted small mb-0 mt-3">Delivery address on the newest order: <?= htmlspecialchars((string) ($myOrders[0]['ShippingAddress'] ?? '—')) ?></p>
        <?php endif; ?>
      </div>
    </div>

    <div class="col-lg-5">
      <div class="report-panel h-100 mb-0">
        <div class="report-panel-head">
          <h2 class="report-panel-title">Account &amp; company</h2>
          <p class="report-panel-sub">Your contact and delivery details on file.</p>
        </div>
        <dl class="row mb-0 dash-dl">
          <dt class="col-sm-4">Username</dt><dd class="col-sm-8"><?= htmlspecialchars((string) ($profile['Username'] ?? $user['Username'])) ?></dd>
          <dt class="col-sm-4">Email</dt><dd class="col-sm-8"><?= htmlspecialchars((string) ($profile['Email'] ?? $user['Email'])) ?></dd>
          <dt class="col-sm-4">Phone</dt><dd class="col-sm-8"><?= htmlspecialchars((string) ($profile['Phone'] ?? '—')) ?></dd>
          <dt class="col-sm-4">Role</dt><dd class="col-sm-8"><?= htmlspecialchars($user['RoleName']) ?></dd>
          <dt class="col-sm-4">Member since</dt><dd class="col-sm-8"><?= htmlspecialchars((string) (isset($profile['CreatedAt']) ? date('d M Y', strtotime((string) $profile['CreatedAt'])) : '—')) ?></dd>
          <?php if ($client): ?>
          <dt class="col-sm-4">Company</dt><dd class="col-sm-8"><?= htmlspecialchars($client['CompanyName']) ?></dd>
          <dt class="col-sm-4">Contact</dt><dd class="col-sm-8"><?= htmlspecialchars((string) ($client['ContactPerson'] ?? '—')) ?><?= !empty($client['Phone']) ? ' · ' . htmlspecialchars((string) $client['Phone']) : '' ?></dd>
          <dt class="col-sm-4">Address</dt><dd class="col-sm-8"><?= htmlspecialchars((string) ($client['Address'] ?? '—')) ?></dd>
          <?php endif; ?>
        </dl>
        <?php if ($branches): ?>
        <div class="border-top pt-3 mt-3">
          <h3 class="h6 text-uppercase small fw-bold mb-2">Branches</h3>
          <ul class="list-unstyled mb-0 dash-list">
            <?php foreach ($branches as $branch): ?>
            <li>
              <span class="fw-semibold"><?= htmlspecialchars($branch['BranchName']) ?></span>
              <small class="text-muted d-block"><?= htmlspecialchars((string) ($branch['BranchAddress'] ?? '—')) ?><?= !empty($branch['ContactNo']) ? ' · ' . htmlspecialchars((string) $branch['ContactNo']) : '' ?></small>
            </li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="row g-3 mt-1">
    <div class="col-lg-7">
      <div class="report-panel h-100 mb-0">
        <div class="report-panel-head">
          <h2 class="report-panel-title">My quotations</h2>
          <p class="report-panel-sub">Quotation requests we have prepared for your company.</p>
        </div>
        <?php if (!$myQuotes): ?>
        <p class="text-muted mb-3"><?= $client ? 'No quotations have been issued to your company yet.' : 'Quotations are linked to a company record — ask us to link your account, or request a quotation as a retail customer.' ?></p>
        <a class="btn btn-outline-dark btn-sm" href="quotation-request.php">Request a quotation</a>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0 report-th">
            <thead><tr><th>#</th><th>Subject</th><th>Branch</th><th>Date</th><th>Total</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($myQuotes as $row): ?>
              <tr>
                <td><?= (int) $row['QuotationNo'] ?></td>
                <td><?= htmlspecialchars((string) $row['Subject']) ?></td>
                <td><?= htmlspecialchars($row['BranchName']) ?></td>
                <td><?= htmlspecialchars(date('d M Y', strtotime((string) $row['QuotationDate']))) ?></td>
                <td><?= money($row['TotalAmount']) ?></td>
                <td><span class="report-badge <?= dash_badge((string) $row['Status']) ?>"><?= htmlspecialchars($row['Status']) ?></span></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="col-lg-5">
      <div class="report-panel h-100 mb-0">
        <div class="report-panel-head">
          <h2 class="report-panel-title">Invoices &amp; payments</h2>
          <p class="report-panel-sub">What you have been billed and what is still outstanding.</p>
        </div>
        <?php if (!$myInvoices): ?>
        <p class="text-muted mb-0">No invoices are linked to your company yet.</p>
        <?php else: ?>
        <ul class="list-unstyled mb-0 dash-list">
          <?php foreach ($myInvoices as $row): ?>
          <li class="d-flex justify-content-between align-items-start gap-2">
            <span>
              <span class="fw-semibold">Invoice #<?= (int) $row['InvoiceNo'] ?></span>
              <small class="text-muted d-block">
                <?= htmlspecialchars(date('d M Y', strtotime((string) $row['InvoiceDate']))) ?>
                · <?= htmlspecialchars($row['BranchName']) ?>
                · <?= (int) $row['Receipts'] ?> receipt(s)
              </small>
              <small class="text-muted d-block">Total <?= money($row['TotalAmount']) ?> · balance <?= money($row['RemainingBalance']) ?></small>
            </span>
            <span class="report-badge <?= dash_badge((string) $row['PaymentStatus']) ?>"><?= htmlspecialchars($row['PaymentStatus']) ?></span>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="report-panel mt-3 mb-0">
    <div class="report-panel-head">
      <h2 class="report-panel-title">Quick actions</h2>
      <p class="report-panel-sub">Handy shortcuts to the pages you use most.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
      <a class="btn btn-accent" href="order.php"><i class="fa-solid fa-cart-shopping me-2"></i>Quick order</a>
      <a class="btn btn-outline-dark" href="quotation-request.php"><i class="fa-solid fa-file-invoice me-2"></i>Request quotation</a>
      <a class="btn btn-outline-dark" href="index.php?all=1#featured-products">Browse the catalog</a>
      <?php if (!$client): ?>
      <a class="btn btn-outline-dark" href="b2b-registration.php">Register a company</a>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<?= partial_render('partials/footer.php') ?>
