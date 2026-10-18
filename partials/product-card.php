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

// Buying is a customer action: a signed-in staff member browsing the catalog
// sees the card without the purchase controls (the write paths refuse them
// too — this is presentation only).
$canBuy    = !is_staff_user();
$orderUrl   = $canBuy ? 'order.php?product=' . $productId . '&qty=1' : '';

// Adding from the grid should put the shopper back on the same selection,
// search term and all, so the card posts the page it is standing on.
$returnUrl = cart_return_path($_SERVER['REQUEST_URI'] ?? 'index.php', 'index.php');
?>
<div class="col-md-6 col-xl-4">
  <div class="card product-card h-100">
    <?= product_image_html($product, 'card-img-top product-image') ?>
    <div class="card-body d-flex flex-column">
      <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
        <h3 class="h5 card-title mb-0"><a class="product-title-link" href="<?= $productUrl ?>"><?= htmlspecialchars($product['ProductName']) ?></a></h3>
        <?php if (!empty($product['badge'])): ?>
        <span class="badge text-bg-dark"><?= htmlspecialchars($product['badge']) ?></span>
        <?php endif; ?>
      </div>
      <?php if (!empty($product['Brand'])): ?>
      <p class="text-muted small mb-2"><?= htmlspecialchars($product['Brand']) ?><?= !empty($product['Model']) ? ' · ' . htmlspecialchars($product['Model']) : '' ?></p>
      <?php endif; ?>
      <?php if (!empty($product['blurb'])): ?>
      <p class="card-text text-muted"><?= htmlspecialchars($product['blurb']) ?></p>
      <?php endif; ?>
      <div class="mt-auto d-flex justify-content-between align-items-center gap-2">
        <div>
          <span class="price"><?= money($product['StandardPrice']) ?><?php if (!empty($product['sale'])): ?> <del class="text-muted fw-normal"><?= money($product['sale']) ?></del><?php endif; ?></span>
          <small class="stock-line text-muted d-block"><?= htmlspecialchars(stock_label($product, $available)) ?></small>
          <?php if ($canBuy): ?>
          <small class="d-block"><a class="text-decoration-none small" href="<?= $orderUrl ?>">Quick order form</a></small>
          <?php endif; ?>
        </div>
        <div class="d-flex gap-1 flex-shrink-0">
          <a href="<?= $productUrl ?>" class="btn btn-sm btn-outline-dark" aria-label="View details for <?= htmlspecialchars($product['ProductName']) ?>"><i class="fa-solid fa-eye"></i></a>
          <?php if ($canBuy): ?>
          <form class="d-inline" method="post" action="cart.php" data-ajax>
            <?= csrf_field() ?>
            <input type="hidden" name="intent" value="add">
            <input type="hidden" name="product_id" value="<?= $productId ?>">
            <input type="hidden" name="quantity" value="1">
            <input type="hidden" name="return" value="<?= htmlspecialchars($returnUrl) ?>">
            <button class="btn btn-sm btn-accent" type="submit"
                    aria-label="Add <?= htmlspecialchars($product['ProductName']) ?> to the basket">
              <i class="fa-solid fa-cart-plus"></i><span class="d-none d-xl-inline ms-1">Add</span>
            </button>
          </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
