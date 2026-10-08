<?php
/**
 * SebaBD — back-office hub.
 *
 * The staff landing page behind dashboard.php: a live snapshot of the business
 * on one side of the storefront, then every module this role may open as a
 * card. Cards the signed-in role may not open are not listed at all here (the
 * hub is a shortcut list, not a permission map) — but opening one directly
 * still answers 403, because every module checks its own roles.
 */
require_once __DIR__ . '/admin-bootstrap.php';

$user = require_staff();
$dbUp = db() !== null;

$salesSide  = array_intersect(['Admin', 'Sales Manager'], [$user['RoleName']]);
$supplySide = array_intersect(['Admin', 'Inventory Manager'], [$user['RoleName']]);

$kpis = [];

if ($dbUp) {
    if ($salesSide) {
        $orderStats = db_fetch_all(
            "SELECT COUNT(*) AS Orders, COALESCE(SUM(TotalAmount), 0) AS Revenue FROM `order`"
        );
        $openQuotes = db_count('quotation', "Status = 'Pending'");
        $dueStats   = db_fetch_all(
            'SELECT COUNT(*) AS Invoices, COALESCE(SUM(RemainingBalance), 0) AS Due
             FROM invoice WHERE RemainingBalance > 0'
        );
        $pendingOrders = db_count('order', "OrderStatus = 'Pending'");

        $kpis[] = [
            'icon'  => 'fa-sack-dollar',
            'label' => 'Order revenue',
            'value' => money((float) ($orderStats[0]['Revenue'] ?? 0)),
            'hint'  => number_format((int) ($orderStats[0]['Orders'] ?? 0)) . ' orders placed',
        ];
        $kpis[] = [
            'icon'  => 'fa-file-signature',
            'label' => 'Quotations open',
            'value' => number_format($openQuotes),
            'hint'  => 'Waiting for a customer decision',
        ];
        $kpis[] = [
            'icon'  => 'fa-hand-holding-dollar',
            'label' => 'Still to collect',
            'value' => money((float) ($dueStats[0]['Due'] ?? 0)),
            'hint'  => number_format((int) ($dueStats[0]['Invoices'] ?? 0)) . ' unpaid invoices',
        ];
        $kpis[] = [
            'icon'  => 'fa-hourglass-half',
            'label' => 'Orders to confirm',
            'value' => number_format($pendingOrders),
            'hint'  => 'Placed online, not yet processed',
        ];
    }

    if ($supplySide) {
        $stockRows = db_fetch_all(
            "SELECT p.ProductID, p.IsSerialized, p.StockQty,
                    (SELECT COUNT(*) FROM product_instance pi
                      WHERE pi.ProductID = p.ProductID AND pi.Status = 'In Stock') AS SerialStock
             FROM product p"
        );
        $low = 0;
        foreach ($stockRows as $row) {
            $onHand = ((int) $row['IsSerialized']) === 1 ? (int) $row['SerialStock'] : (int) $row['StockQty'];
            if ($onHand <= 5) {
                $low++;
            }
        }
        $poStats = db_fetch_all(
            'SELECT COUNT(*) AS Orders, COALESCE(SUM(TotalAmount), 0) AS Value FROM purchase_order'
        );

        $kpis[] = [
            'icon'  => 'fa-boxes-stacked',
            'label' => 'Products listed',
            'value' => number_format(db_count('product')),
            'hint'  => db_count('category') . ' categories · ' . db_count('vendor') . ' suppliers',
        ];
        $kpis[] = [
            'icon'  => 'fa-triangle-exclamation',
            'label' => 'Stock to reorder',
            'value' => number_format($low),
            'hint'  => 'Five units or fewer on hand',
        ];
        $kpis[] = [
            'icon'  => 'fa-truck-ramp-box',
            'label' => 'Purchase orders',
            'value' => number_format((int) ($poStats[0]['Orders'] ?? 0)),
            'hint'  => money((float) ($poStats[0]['Value'] ?? 0)) . ' committed to vendors',
        ];
    }

    if (is_admin_user($user)) {
        $kpis[] = [
            'icon'  => 'fa-user-shield',
            'label' => 'User accounts',
            'value' => number_format(db_count('user')),
            'hint'  => db_count('role') . ' roles · ' . db_count('client') . ' companies',
        ];
    }
}

$grouped = back_office_modules_by_group($user);

echo partial_render('partials/header.php', ['page_title' => 'Back office', 'active_nav' => 'admin']);
?>

<section class="report-lead py-4 mb-4">
    <div class="container d-flex flex-wrap justify-content-between align-items-end gap-3">
        <div>
            <span class="section-label">Back office</span>
            <h1 class="h3 fw-bold text-white mb-1">Run the store</h1>
            <p class="mb-0 report-lead-sub">
                You are signed in as <strong><?= htmlspecialchars($user['FullName']) ?></strong>
                (<?= htmlspecialchars($user['RoleName']) ?>), so the modules below are the ones your role owns.
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-light btn-sm" href="dashboard.php"><i class="fa-solid fa-gauge-high me-1"></i>Dashboard</a>
            <?php if (is_staff_user($user)): ?>
            <a class="btn btn-outline-light btn-sm" href="reports.php"><i class="fa-solid fa-chart-line me-1"></i>Reports</a>
            <?php endif; ?>
            <a class="btn btn-outline-light btn-sm" href="index.php"><i class="fa-solid fa-store me-1"></i>Storefront</a>
        </div>
    </div>
</section>

<div class="container pb-5">
    <?= render_flashes() ?>

    <?php if (!$dbUp): ?>
    <div class="alert alert-danger">
        <i class="fa-solid fa-plug-circle-xmark me-2"></i>
        The <strong>e_commerce</strong> database is offline, so nothing can be listed or saved.
        Start MySQL in the XAMPP control panel and import <code>database/e_commerce.sql</code>, then
        <a href="admin.php">reload this page</a>.
    </div>
    <?php else: ?>

    <?php if ($kpis): ?>
    <div class="row g-3 mb-4">
        <?php foreach ($kpis as $kpi): ?>
        <div class="col-sm-6 col-xl-3">
            <div class="report-kpi">
                <span class="report-kpi-icon"><i class="fa-solid <?= htmlspecialchars($kpi['icon']) ?>"></i></span>
                <span class="report-kpi-label"><?= htmlspecialchars($kpi['label']) ?></span>
                <span class="report-kpi-value"><?= $kpi['value'] ?></span>
                <span class="report-kpi-hint"><?= htmlspecialchars($kpi['hint']) ?></span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php foreach ($grouped as $group => $modules): ?>
    <h2 class="admin-group-title"><?= htmlspecialchars($group) ?></h2>
    <div class="row g-3 mb-4">
        <?php foreach ($modules as $page => $module): ?>
        <div class="col-md-6 col-xl-4">
            <a class="report-hub-card admin-hub-card" href="<?= htmlspecialchars($page) ?>">
                <span class="report-hub-icon"><i class="fa-solid <?= htmlspecialchars($module['icon']) ?>"></i></span>
                <h3 class="h5 fw-bold mb-0"><?= htmlspecialchars($module['title']) ?></h3>
                <p class="text-muted small mb-0"><?= htmlspecialchars($module['blurb']) ?></p>
                <span class="admin-hub-tables">
                    <i class="fa-solid fa-database me-1"></i><?= htmlspecialchars($module['tables']) ?>
                </span>
                <span class="fw-semibold small mt-auto report-hub-link">
                    Open module <i class="fa-solid fa-arrow-right ms-1"></i>
                </span>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <p class="text-muted small mb-0">
        Each module writes to the tables listed on its card, through the same PDO connection and the
        same transactions as the storefront — see <code>README.md</code> for the full table-by-table map.
    </p>

    <?php endif; ?>
</div>

<?= partial_render('partials/footer.php') ?>
