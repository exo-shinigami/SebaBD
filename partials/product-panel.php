<?php
/**
 * SebaBD — storefront product panel: heading, product cards, empty state.
 *
 * Rendered twice with the same code path:
 *   - inside index.php for the first paint (works with JavaScript off)
 *   - on its own for index.php?ajax=panel when the search box or a category
 *     link changes the selection in place
 *
 * Expects:
 *   $products     array  rows shaped like get_featured_products()
 *   $query        string the active search text ('' when none)
 *   $categoryName string the active category name ('' when none)
 *   $showAll      bool   true when the visitor asked for the whole catalog
 */
$query        = $query ?? '';
$categoryName = $categoryName ?? '';
$showAll      = $showAll ?? false;

$filtered = $query !== '' || $categoryName !== '' || $showAll;

if ($query !== '') {
    $label   = 'Search results';
    $heading = 'Results for “' . htmlspecialchars($query) . '”';
} elseif ($categoryName !== '') {
    $label   = 'Category';
    $heading = htmlspecialchars($categoryName);
} elseif ($showAll) {
    $label   = 'Full catalog';
    $heading = 'All products';
} else {
    $label   = 'Featured products';
    $heading = 'Popular right now';
}

$counts = stock_counts($products);
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <p class="section-label mb-1"><?= $label ?></p>
    <h2 class="h3 mb-0" id="product-heading"><?= $heading ?></h2>
    <p class="text-muted small mb-0" id="product-count">
      <?= count($products) ?> product<?= count($products) === 1 ? '' : 's' ?><?= $filtered ? ' matched' : ' featured' ?>
    </p>
  </div>
  <?php if ($filtered): ?>
  <a class="text-decoration-none" href="index.php#featured-products" data-filter-link><i class="fa-solid fa-xmark me-1"></i>Clear filter</a>
  <?php else: ?>
  <a class="text-decoration-none" href="index.php?all=1#featured-products" data-filter-link>View all products</a>
  <?php endif; ?>
</div>

<?php if (!$products): ?>
<div class="alert alert-light border text-center py-4 mb-0">
  <i class="fa-solid fa-magnifying-glass fa-lg d-block mb-2 text-muted"></i>
  No products matched that search. Try a brand name like <em>Dell</em>, a category like
  <em>monitors</em>, or <a href="index.php?all=1#featured-products" data-filter-link>browse the whole catalog</a>.
</div>
<?php endif; ?>

<div class="row g-4" id="product-grid">
  <?php foreach ($products as $product): ?>
  <div class="col-md-6 col-xl-4">
    <div class="card product-card h-100">
      <img src="<?= htmlspecialchars($product['image'] ?? '') ?>" class="card-img-top product-image" alt="<?= htmlspecialchars($product['alt'] ?? $product['ProductName']) ?>">
      <div class="card-body d-flex flex-column">
        <div class="d-flex justify-content-between align-items-start mb-2">
          <h3 class="h5 card-title mb-0"><?= htmlspecialchars($product['ProductName']) ?></h3>
          <?php if (!empty($product['badge'])): ?>
          <span class="badge text-bg-dark"><?= htmlspecialchars($product['badge']) ?></span>
          <?php endif; ?>
        </div>
        <?php if (!empty($product['Brand'])): ?>
        <p class="text-muted small mb-2"><?= htmlspecialchars($product['Brand']) ?><?= !empty($product['Model']) ? ' · ' . htmlspecialchars($product['Model']) : '' ?></p>
        <?php endif; ?>
        <p class="card-text text-muted"><?= htmlspecialchars($product['blurb'] ?? '') ?></p>
        <div class="mt-auto d-flex justify-content-between align-items-center">
          <div>
            <span class="price"><?= money($product['StandardPrice']) ?><?php if (!empty($product['sale'])): ?> <del class="text-muted fw-normal"><?= money($product['sale']) ?></del><?php endif; ?></span>
            <small class="stock-line text-muted d-block"><?= htmlspecialchars(stock_label($product, $counts[(int) $product['ProductID']] ?? 0)) ?></small>
          </div>
          <a href="order.php" class="btn btn-sm btn-dark">Order</a>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
