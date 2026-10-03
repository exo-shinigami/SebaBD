<?php
/**
 * SebaBD — product detail page.
 *
 *   product.php?id=<ProductID>
 *
 * Reads one product from the catalog (product + category + vendor), shows the
 * live stock position and the warranty default, and hands the visitor straight
 * into the order or quotation form with that product pre-selected.
 *
 * Staff additionally see the product_instance rows, because serialized stock is
 * tracked unit by unit — a customer only needs the availability count.
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/catalog-data.php';

$productId = max(0, (int) ($_GET['id'] ?? 0));
$product   = get_product($productId);

$available = 0;
$related   = [];
$units     = [];

if ($product) {
    $counts    = stock_counts([$product]);
    $available = $counts[$productId] ?? 0;
    $related   = related_products($productId, (int) $product['CategoryID'], 3);
    if (is_staff_user()) {
        $units = product_units($productId);
    }
}

$page_title = $product ? $product['ProductName'] : 'Product not found';

$current  = (float) ($product['StandardPrice'] ?? 0);
$wasPrice = !empty($product['sale']) && (float) $product['sale'] > $current ? (float) $product['sale'] : 0.0;
$discount = $wasPrice > 0 ? ($wasPrice - $current) / $wasPrice * 100 : 0.0;

$orderUrl     = 'order.php?product=' . $productId . '&qty=1';
$quotationUrl = 'quotation-request.php?product=' . $productId . '&qty=1';
?>
<?= partial_render('partials/header.php', ['page_title' => $page_title, 'active_nav' => 'shop']) ?>

<?php if (!$product): ?>
<section class="py-5">
  <div class="container">
    <div class="report-panel text-center py-5">
      <i class="fa-solid fa-box-open fa-2x text-muted d-block mb-3"></i>
      <h1 class="h4 mb-2">We could not find that product</h1>
      <p class="text-muted mb-4">
        <?= $productId > 0 ? 'Product #' . $productId . ' does not exist in the catalog.' : 'No product was requested.' ?>
        It may have been removed, or the link may be out of date.
      </p>
      <a class="btn btn-accent" href="index.php?all=1#featured-products">Browse the catalog</a>
    </div>
  </div>
</section>
<?php else: ?>
<section class="py-4">
  <div class="container">
    <nav aria-label="breadcrumb">
      <ol class="breadcrumb crumb mb-3">
        <li class="breadcrumb-item"><a href="index.php">Home</a></li>
        <li class="breadcrumb-item"><a href="index.php?category=<?= (int) $product['CategoryID'] ?>#featured-products"><?= htmlspecialchars($product['CategoryName']) ?></a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($product['ProductName']) ?></li>
      </ol>
    </nav>

    <div class="product-detail">
      <div class="row g-4 align-items-start">
        <div class="col-lg-5">
          <div class="product-detail-media">
            <img src="<?= htmlspecialchars($product['image'] ?? '') ?>" alt="<?= htmlspecialchars($product['alt'] ?? $product['ProductName']) ?>">
            <?php if (!empty($product['badge'])): ?>
            <span class="badge text-bg-dark product-detail-badge"><?= htmlspecialchars($product['badge']) ?></span>
            <?php endif; ?>
          </div>
        </div>

        <div class="col-lg-7">
          <p class="section-label mb-1"><?= htmlspecialchars($product['CategoryName']) ?></p>
          <h1 class="h2 fw-bold mb-2"><?= htmlspecialchars($product['ProductName']) ?></h1>
          <p class="text-muted mb-3">
            <?= htmlspecialchars($product['Brand'] ?? '') ?><?= !empty($product['Model']) ? ' · Model ' . htmlspecialchars($product['Model']) : '' ?>
            <span class="text-muted">· Product ID #<?= (int) $product['ProductID'] ?></span>
          </p>

          <p class="mb-3"><?= htmlspecialchars($product['blurb'] ?? '') ?></p>

          <div class="d-flex flex-wrap align-items-end gap-3 mb-3">
            <span class="price price-lg"><?= money($product['StandardPrice']) ?></span>
            <?php if ($wasPrice > 0): ?>
            <span class="text-muted"><del><?= money($wasPrice) ?></del> <span class="badge text-bg-success"><?= number_format($discount, 0) ?>% off</span></span>
            <?php endif; ?>
            <span class="stock-line <?= $available > 0 ? 'text-success' : 'text-danger' ?>">
              <i class="fa-solid <?= $available > 0 ? 'fa-circle-check' : 'fa-triangle-exclamation' ?> me-1"></i><?= htmlspecialchars(stock_label($product, $available)) ?>
            </span>
          </div>

          <div class="d-flex flex-wrap gap-2 mb-4">
            <a class="btn btn-accent btn-lg" href="<?= $orderUrl ?>"><i class="fa-solid fa-cart-shopping me-2"></i>Order this product</a>
            <a class="btn btn-outline-dark btn-lg" href="<?= $quotationUrl ?>"><i class="fa-solid fa-file-invoice me-2"></i>Request quotation</a>
          </div>

          <div class="report-panel mb-0">
            <div class="report-panel-head">
              <h2 class="report-panel-title">Specifications</h2>
            </div>
            <div class="table-responsive">
              <table class="table table-sm spec-table mb-0">
                <tbody>
                  <tr><th scope="row">Category</th><td><a href="index.php?category=<?= (int) $product['CategoryID'] ?>#featured-products"><?= htmlspecialchars($product['CategoryName']) ?></a></td></tr>
                  <tr><th scope="row">Brand / model</th><td><?= htmlspecialchars($product['Brand'] ?? '—') ?> / <?= htmlspecialchars($product['Model'] ?? '—') ?></td></tr>
                  <tr><th scope="row">Default warranty</th><td><?= (int) $product['DefaultWarrantyMonths'] ?> months</td></tr>
                  <tr><th scope="row">Stock type</th><td><?= ((int) $product['IsSerialized']) === 1 ? 'Each unit tracked by serial number' : 'Sold from bulk stock' ?></td></tr>
                  <tr><th scope="row">In stock</th><td><?= (int) $available ?></td></tr>
                  <tr><th scope="row">Supplier</th><td><?= htmlspecialchars($product['VendorName']) ?><?= !empty($product['VendorLocation']) ? ' — ' . htmlspecialchars($product['VendorLocation']) : '' ?></td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>

    <?php if ($units): ?>
    <div class="report-panel mt-4">
      <div class="report-panel-head">
        <h2 class="report-panel-title">Units in stock <span class="report-badge report-badge-plain">Internal</span></h2>
        <p class="report-panel-sub">Serial number and warranty dates recorded for each unit.</p>
      </div>
      <div class="table-responsive">
        <table class="table table-sm report-th mb-0">
          <thead>
            <tr>
              <th>Unit</th>
              <th>Serial number</th>
              <th>Purchased</th>
              <th>Supplier warranty</th>
              <th>Customer warranty</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($units as $unit): ?>
            <tr>
              <td><?= (int) $unit['EquipmentID'] ?></td>
              <td><code><?= htmlspecialchars($unit['SerialNumber']) ?></code></td>
              <td><?= htmlspecialchars((string) ($unit['PurchaseDate'] ?? '—')) ?></td>
              <td><?= htmlspecialchars((string) ($unit['VendorWarrantyExpiry'] ?? '—')) ?></td>
              <td><?= htmlspecialchars((string) ($unit['ClientWarrantyExpiry'] ?? '—')) ?></td>
              <td>
                <span class="report-badge <?= $unit['Status'] === 'In Stock' ? 'report-badge-ok' : 'report-badge-plain' ?>"><?= htmlspecialchars($unit['Status']) ?></span>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($related): ?>
    <section class="mt-5">
      <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
          <p class="section-label mb-1">More in <?= htmlspecialchars($product['CategoryName']) ?></p>
          <h2 class="h4 mb-0">Related products</h2>
        </div>
        <a class="text-decoration-none" href="index.php?category=<?= (int) $product['CategoryID'] ?>#featured-products">See the whole category</a>
      </div>
      <div class="row g-4">
        <?php $relatedCounts = stock_counts($related); ?>
        <?php foreach ($related as $relatedProduct): ?>
          <?= partial_render('partials/product-card.php', [
              'product'   => $relatedProduct,
              'available' => $relatedCounts[(int) $relatedProduct['ProductID']] ?? 0,
          ]) ?>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<?= partial_render('partials/footer.php') ?>
