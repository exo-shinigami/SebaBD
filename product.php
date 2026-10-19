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

// A missing product is a real 404, exactly as the document pages report an
// unknown order, invoice or quotation — a friendly page, correct status.
if (!$product) {
    http_response_code(404);
}

$current  = (float) ($product['StandardPrice'] ?? 0);
$wasPrice = !empty($product['sale']) && (float) $product['sale'] > $current ? (float) $product['sale'] : 0.0;
$discount = $wasPrice > 0 ? ($wasPrice - $current) / $wasPrice * 100 : 0.0;

// Buying and quoting are customer actions. A signed-in staff member browsing
// the catalog sees the product page without the purchase controls — the
// write paths refuse them anyway, so hiding here is not the only barrier.
// Quotations are a company flow: an individual customer sees the buy controls
// but no quotation button.
$canBuy   = !is_staff_user(); $canQuote = $canBuy && can_request_quotation();
$orderUrl     = $canBuy ? 'order.php?product=' . $productId . '&qty=1' : '';
$quotationUrl = $canQuote ? 'quotation-request.php?product=' . $productId . '&qty=1' : '';
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
      <a class="btn btn-accent" href="shop.php">Browse the catalog</a>
    </div>
  </div>
</section>
<?php else: ?>
<section class="py-4">
  <div class="container">
    <nav aria-label="breadcrumb">
      <ol class="breadcrumb crumb mb-3">
        <li class="breadcrumb-item"><a href="index.php">Home</a></li>
        <li class="breadcrumb-item"><a href="shop.php?category=<?= (int) $product['CategoryID'] ?>"><?= htmlspecialchars($product['CategoryName']) ?></a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($product['ProductName']) ?></li>
      </ol>
    </nav>

    <div class="product-detail">
      <div class="row g-4 align-items-start">
        <div class="col-lg-5">
          <div class="product-detail-media">
            <?= product_image_html($product, 'product-detail-photo') ?>
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

          <?php if (!empty($product['blurb'])): ?>
          <p class="mb-3"><?= htmlspecialchars($product['blurb']) ?></p>
          <?php endif; ?>

          <div class="d-flex flex-wrap align-items-end gap-3 mb-3">
            <span class="price price-lg"><?= money($product['StandardPrice']) ?></span>
            <?php if ($wasPrice > 0): ?>
            <span class="text-muted"><del><?= money($wasPrice) ?></del> <span class="badge text-bg-success"><?= number_format($discount, 0) ?>% off</span></span>
            <?php endif; ?>
            <?php
            // "Made to order" is an ordinary serialized-product state, not a
            // problem, so it stays neutral instead of being flagged in red.
            $madeToOrder = $available <= 0 && ((int) $product['IsSerialized']) === 1;
            $stockTone   = $available > 0 ? 'text-success' : ($madeToOrder ? 'text-muted' : 'text-danger');
            $stockIcon   = $available > 0 ? 'fa-circle-check' : ($madeToOrder ? 'fa-clock' : 'fa-triangle-exclamation');
            ?>
            <span class="stock-line <?= $stockTone ?>">
              <i class="fa-solid <?= $stockIcon ?> me-1"></i><?= htmlspecialchars(stock_label($product, $available)) ?>
            </span>
          </div>

          <?php if ($canBuy): ?>
          <!-- Buying: add to the basket with a quantity of your own, or go
               straight to the quick-order form for a multi-line order. -->
          <form class="product-buy" method="post" action="cart.php" data-ajax>
            <?= csrf_field() ?>
            <input type="hidden" name="intent" value="add">
            <input type="hidden" name="product_id" value="<?= $productId ?>">
            <input type="hidden" name="return" value="product.php?id=<?= $productId ?>">

            <label class="form-label small mb-1" for="buy-qty">Quantity</label>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
              <input class="form-control text-center" style="max-width:6rem;" type="number"
                     id="buy-qty" name="quantity" value="1" min="1" max="999" step="1">
              <button class="btn btn-accent btn-lg" type="submit">
                <i class="fa-solid fa-cart-plus me-2"></i>Add to basket
              </button>
              <?php if ($canQuote): ?>
              <a class="btn btn-outline-dark btn-lg" href="<?= $quotationUrl ?>">
                <i class="fa-solid fa-file-invoice me-2"></i>Request quotation
              </a>
              <?php endif; ?>
            </div>
            <p class="small text-muted mb-4">
              Prefer a single form for a whole order?
              <a href="<?= $orderUrl ?>">Use the quick order form</a> — it takes many lines at once; you sign in
              when you submit, so the order lands in your account.
            </p>
          </form>
          <?php else: ?>
          <div class="alert alert-light border small mb-4">
            <i class="fa-solid fa-building-shield me-1"></i>
            You are signed in as SebaBD staff — buying and quotation requests are customer actions.
            Stock intake happens through <a href="admin-purchase-orders.php">purchase orders</a>.
          </div>
          <?php endif; ?>

          <div class="report-panel mb-0">
            <div class="report-panel-head">
              <h2 class="report-panel-title">Specifications</h2>
            </div>
            <div class="table-responsive">
              <table class="table table-sm spec-table mb-0">
                <tbody>
                  <tr><th scope="row">Category</th><td><a href="shop.php?category=<?= (int) $product['CategoryID'] ?>"><?= htmlspecialchars($product['CategoryName']) ?></a></td></tr>
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
        <a class="text-decoration-none" href="shop.php?category=<?= (int) $product['CategoryID'] ?>">See the whole category</a>
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
