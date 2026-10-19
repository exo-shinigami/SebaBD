<?php
/**
 * SebaBD — homepage.
 *
 * A landing page, not the whole store: the hero, the six categories, a curated
 * selection of products, the current offer and the service promises. Everything
 * that needs its own address lives on its own page — shop.php for the catalogue,
 * categories.php for the departments, deals.php for the offers, contact.php for
 * the sales desk — so each destination is one click and one URL.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/catalog-data.php';
require_once __DIR__ . '/helpers.php';
// The product cards post their Add-to-cart form with a return URL, so the
// storefront needs the cartridge helpers even before header.php loads them.
require_once __DIR__ . '/cart-data.php';

$featured   = get_featured_products(12);
$available  = stock_counts($featured);
$categories = category_overview();
$deals      = deal_products(24);
$total      = get_product_count();

$maxDiscount = 0.0;
foreach ($deals as $deal) {
    $maxDiscount = max($maxDiscount, discount_percent($deal));
}
$dealsPreview = array_slice($deals, 0, 3);
$dealCounts   = stock_counts($dealsPreview);
?>
<?= partial_render('partials/header.php', ['page_title' => '', 'active_nav' => 'home']) ?>
          <section class="hero-section py-5">
            <div class="container py-3 py-lg-4">
              <div class="row align-items-center g-4">
                <div class="col-lg-7">
                  <span class="section-pill"><i class="fa-solid fa-bolt me-1"></i>Total IT System Solution</span>
                  <h1 class="display-5 fw-bold mt-3 mb-3">Complete IT solutions for homes and offices.</h1>
                  <p class="lead text-white-50 mb-4">From business laptops and 4K monitors to managed network switches and everyday accessories — SebaBD supplies, installs and services the technology your business runs on.</p>
                  <div class="d-flex flex-wrap gap-3">
                    <a class="btn btn-accent btn-lg" href="shop.php">Shop products</a>
                    <a class="btn btn-outline-light btn-lg" href="categories.php">Browse categories</a>
                  </div>
                  <div class="d-flex flex-wrap gap-4 mt-4 hero-trust small text-white-50">
                    <span><i class="fa-solid fa-shield-halved me-2"></i>Genuine products with warranty</span>
                    <span><i class="fa-solid fa-truck-fast me-2"></i>Delivery across Bangladesh</span>
                    <span><i class="fa-solid fa-handshake me-2"></i>Corporate &amp; B2B supply</span>
                  </div>
                </div>
                <div class="col-lg-5">
                  <div class="hero-card">
                    <p class="text-uppercase small text-white-50 mb-1">Why SebaBD</p>
                    <h2 class="h3 mb-3">Sales, service &amp; support</h2>
                    <p class="text-white-50 mb-4">Supply, installation and after-sales service for offices, schools and enterprises — all from one trusted local partner.</p>
                    <div class="d-flex align-items-center justify-content-between border-top border-white border-opacity-10 pt-3">
                      <div>
                        <div class="fw-semibold"><?= count($categories) ?></div>
                        <small class="text-white-50">Categories</small>
                      </div>
                      <div>
                        <div class="fw-semibold"><?= $total ?></div>
                        <small class="text-white-50">Products</small>
                      </div>
                      <div>
                        <div class="fw-semibold">24/7</div>
                        <small class="text-white-50">Support</small>
                      </div>
                      <div>
                        <div class="fw-semibold">2-Day</div>
                        <small class="text-white-50">Delivery</small>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </section>

          <section class="py-4">
            <div class="container">
              <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
                <div>
                  <p class="section-label mb-1">Departments</p>
                  <h2 class="h4 mb-0">Shop by category</h2>
                </div>
                <a class="text-decoration-none small" href="categories.php">All categories <i class="fa-solid fa-arrow-right ms-1"></i></a>
              </div>
              <div class="row g-3">
                <?php foreach ($categories as $category): ?>
                <div class="col-6 col-md-4 col-xl-2">
                  <a class="feature-card h-100 d-block text-decoration-none p-3"
                     href="shop.php?category=<?= (int) $category['CategoryID'] ?>">
                    <span class="feature-icon"><i class="<?= htmlspecialchars(category_icon((string) $category['CategoryName'])) ?>"></i></span>
                    <p class="fw-semibold small mb-0 mt-3"><?= htmlspecialchars((string) $category['CategoryName']) ?></p>
                    <p class="text-muted small mb-0"><?= (int) $category['Products'] ?> item<?= (int) $category['Products'] === 1 ? '' : 's' ?></p>
                  </a>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
          </section>

          <section class="py-5">
            <div class="container">
              <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-4">
                <div>
                  <p class="section-label mb-1">Featured products</p>
                  <h2 class="h3 mb-0">Popular right now</h2>
                  <p class="text-muted small mb-0">A cross-section of the range — the full catalogue is one click away.</p>
                </div>
                <a class="btn btn-outline-dark" href="shop.php">
                  View all <?= $total ?> products <i class="fa-solid fa-arrow-right ms-1"></i>
                </a>
              </div>
              <div class="row g-4">
                <?php foreach ($featured as $product): ?>
                <?= partial_render('partials/product-card.php', [
                    'product'   => $product,
                    'available' => $available[(int) $product['ProductID']] ?? 0,
                ]) ?>
                <?php endforeach; ?>
              </div>
            </div>
          </section>

          <?php if ($dealsPreview): ?>
          <section class="deals-section py-5">
            <div class="container">
              <div class="row g-4 align-items-center mb-4">
                <div class="col-lg-7">
                  <p class="section-label mb-1">This week only</p>
                  <h2 class="h3 mb-3 text-white">Deals of the week — save on business essentials</h2>
                  <p class="text-white-50 mb-4">Business laptops, 4K monitors, managed switches and accessories from HP, Dell, Cisco and Logitech at reduced prices until Sunday night.</p>
                  <a href="deals.php" class="btn btn-accent btn-lg">See all <?= count($deals) ?> deals</a>
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
                      <div class="display-6 fw-bold">Sun</div>
                      <small class="text-white-50 d-block">Ends midnight</small>
                    </div>
                  </div>
                </div>
              </div>

              <div class="row g-4">
                <?php foreach ($dealsPreview as $product): ?>
                <?= partial_render('partials/product-card.php', [
                    'product'   => $product,
                    'available' => $dealCounts[(int) $product['ProductID']] ?? 0,
                ]) ?>
                <?php endforeach; ?>
              </div>
            </div>
          </section>
          <?php endif; ?>

          <section class="py-5">
            <div class="container">
              <div class="row g-4">
                <div class="col-md-4">
                  <div class="feature-card h-100">
                    <span class="feature-icon"><i class="fa-solid fa-truck-fast"></i></span>
                    <h3 class="h5 mt-3 mb-2">Nationwide delivery</h3>
                    <p class="text-muted mb-0">We deliver all over Bangladesh, with cash-on-delivery options for retail customers.</p>
                  </div>
                </div>
                <div class="col-md-4">
                  <div class="feature-card h-100">
                    <span class="feature-icon"><i class="fa-solid fa-shield-halved"></i></span>
                    <h3 class="h5 mt-3 mb-2">Genuine products, warrantied</h3>
                    <p class="text-muted mb-0">Every product is sourced from authorized distributors and carries a recorded warranty period in our system.</p>
                  </div>
                </div>
                <div class="col-md-4">
                  <div class="feature-card h-100">
                    <span class="feature-icon"><i class="fa-solid fa-headset"></i></span>
                    <h3 class="h5 mt-3 mb-2">Service after the sale</h3>
                    <p class="text-muted mb-0">Serialized equipment is tracked per unit, so warranty claims and servicing follow the exact device you bought.</p>
                  </div>
                </div>
              </div>
            </div>
          </section>
<?= partial_render('partials/footer.php') ?>
