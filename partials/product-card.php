<?php
/**
 * SebaBD — one storefront product card.
 *
 * Shared by the homepage/storefront grid (partials/product-panel.php) and the
 * related-products strip on product.php, so the card markup lives in exactly
 * one place.
 *
 * Expects:
 *   $product   array  row shaped like get_featured_products()
 *   $available int    units on hand, from stock_counts()
 */
$product   = $product ?? [];
$available = (int) ($available ?? 0);
$productId = (int) ($product['ProductID'] ?? 0);
$productUrl = 'product.php?id=' . $productId;
$orderUrl   = 'order.php?product=' . $productId . '&qty=1';
?>
<div class="col-md-6 col-xl-4">
  <div class="card product-card h-100">
    <img src="<?= htmlspecialchars($product['image'] ?? '') ?>" class="card-img-top product-image" alt="<?= htmlspecialchars($product['alt'] ?? $product['ProductName']) ?>">
    <div class="card-body d-flex flex-column">
      <div class="d-flex justify-content-between align-items-start mb-2">
        <h3 class="h5 card-title mb-0"><a class="product-title-link" href="<?= $productUrl ?>"><?= htmlspecialchars($product['ProductName']) ?></a></h3>
        <?php if (!empty($product['badge'])): ?>
        <span class="badge text-bg-dark"><?= htmlspecialchars($product['badge']) ?></span>
        <?php endif; ?>
      </div>
      <?php if (!empty($product['Brand'])): ?>
      <p class="text-muted small mb-2"><?= htmlspecialchars($product['Brand']) ?><?= !empty($product['Model']) ? ' · ' . htmlspecialchars($product['Model']) : '' ?></p>
      <?php endif; ?>
      <p class="card-text text-muted"><?= htmlspecialchars($product['blurb'] ?? '') ?></p>
      <div class="mt-auto d-flex justify-content-between align-items-center gap-2">
        <div>
          <span class="price"><?= money($product['StandardPrice']) ?><?php if (!empty($product['sale'])): ?> <del class="text-muted fw-normal"><?= money($product['sale']) ?></del><?php endif; ?></span>
          <small class="stock-line text-muted d-block"><?= htmlspecialchars(stock_label($product, $available)) ?></small>
        </div>
        <div class="d-flex gap-1 flex-shrink-0">
          <a href="<?= $productUrl ?>" class="btn btn-sm btn-outline-dark" aria-label="View details for <?= htmlspecialchars($product['ProductName']) ?>"><i class="fa-solid fa-eye"></i></a>
          <a href="<?= $orderUrl ?>" class="btn btn-sm btn-dark">Order</a>
        </div>
      </div>
    </div>
  </div>
</div>
