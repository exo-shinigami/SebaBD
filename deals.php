<?php
/**
 * SebaBD — deals of the week.
 *
 * Everything the store currently marks down, biggest saving first. The discount
 * comes from the same presentation map the product cards use, so a price shown
 * here is the price shown everywhere else.
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/catalog-data.php';
require_once __DIR__ . '/cart-data.php';

$deals    = deal_products(24);
$counts   = stock_counts($deals);
$categories = get_categories();

$maxDiscount = 0.0;
$bestSaving  = 0.0;
foreach ($deals as $deal) {
    $maxDiscount = max($maxDiscount, discount_percent($deal));
    $was         = sale_price($deal);
    if ($was !== null) {
        $bestSaving = max($bestSaving, $was - (float) $deal['StandardPrice']);
    }
}
?>
<?= partial_render('partials/header.php', ['page_title' => 'Deals of the week', 'active_nav' => 'deals']) ?>
          <section class="deals-section py-5">
            <div class="container">
              <nav aria-label="breadcrumb">
                <ol class="breadcrumb crumb mb-3">
                  <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                  <li class="breadcrumb-item active" aria-current="page">Deals</li>
                </ol>
              </nav>
              <div class="row g-4 align-items-center">
                <div class="col-lg-7">
                  <p class="section-label mb-1">This week only</p>
                  <h1 class="h3 mb-3 text-white">Deals of the week — save on business essentials</h1>
                  <p class="text-white-50 mb-0">
                    <?= count($deals) ?> line<?= count($deals) === 1 ? '' : 's' ?> marked down across
                    <?= count($categories) ?> categories, from laptops and 4K monitors to routers
                    and printers. Prices return to list when the offer ends on Sunday night.
                  </p>
                </div>
                <div class="col-lg-5">
                  <div class="deals-card d-flex align-items-center justify-content-around gap-3">
                    <div class="text-center">
                      <div class="display-6 fw-bold"><?= number_format($maxDiscount, 1) ?>%</div>
                      <small class="text-white-50 d-block">Max discount</small>
                    </div>
                    <div class="vr"></div>
                    <div class="text-center">
                      <div class="display-6 fw-bold"><?= count($deals) ?></div>
                      <small class="text-white-50 d-block">Items on sale</small>
                    </div>
                    <div class="vr"></div>
                    <div class="text-center">
                      <div class="display-6 fw-bold"><?= money($bestSaving) ?></div>
                      <small class="text-white-50 d-block">Best saving</small>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </section>

          <section class="py-5">
            <div class="container">
              <?php if (!$deals): ?>
              <div class="alert alert-light border text-center py-5 mb-0">
                <i class="fa-solid fa-tags fa-lg d-block mb-2 text-muted"></i>
                <p class="mb-2">No deals are running right now.</p>
                <p class="mb-0 small"><a href="shop.php">Browse the full catalogue</a> — list prices apply.</p>
              </div>
              <?php else: ?>
              <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-4">
                <div>
                  <p class="section-label mb-1">On offer</p>
                  <h2 class="h3 mb-0">Biggest savings first</h2>
                </div>
                <a class="text-decoration-none" href="shop.php">
                  Shop everything <i class="fa-solid fa-arrow-right ms-1"></i>
                </a>
              </div>
              <div class="row g-4">
                <?php foreach ($deals as $deal): ?>
                <?= partial_render('partials/product-card.php', [
                    'product'   => $deal,
                    'available' => $counts[(int) $deal['ProductID']] ?? 0,
                ]) ?>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>

              <div class="alert alert-light border mt-5 mb-0">
                <p class="mb-1 fw-semibold"><i class="fa-solid fa-circle-info me-2"></i>How these prices work</p>
                <p class="mb-0 text-muted small">
                  Every offer is shown against the list price it is reduced from, and the saving is the
                  difference between the two — the same figure the invoice and quotation use. Corporate
                  and bulk orders can be quoted below these prices; <a href="quotation-request.php">ask for a quotation</a>.
                </p>
              </div>
            </div>
          </section>
<?= partial_render('partials/footer.php') ?>
