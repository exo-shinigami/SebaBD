<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/catalog-data.php';
require_once __DIR__ . '/helpers.php';

$categories = get_categories();
$products   = get_featured_products();

// Deal stats are derived from what the DB actually returned, not hard-coded.
$onSale      = array_filter($products, fn($p) => !empty($p['sale']) && (float) $p['sale'] > (float) $p['StandardPrice']);
$maxDiscount = 0.0;
foreach ($onSale as $p) {
    $was = (float) $p['sale'];
    $maxDiscount = max($maxDiscount, ($was - (float) $p['StandardPrice']) / $was * 100);
}
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
                    <a class="btn btn-accent btn-lg" href="#featured-products">Shop Products</a>
                    <a class="btn btn-outline-light btn-lg" href="#categories">Browse Categories</a>
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
                        <div class="fw-semibold"><?= get_product_count() ?></div>
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

          <section class="py-5">
            <div class="container">
              <div class="row g-4">
                <aside class="col-lg-3" id="categories">
                  <div class="sidebar-card h-100">
                    <h3 class="h5 mb-3">Shop by category</h3>
                    <div class="list-group list-group-flush">
                      <?php foreach ($categories as $category): ?>
                      <a class="list-group-item list-group-item-action" href="#featured-products"><i class="<?= htmlspecialchars(category_icon($category['CategoryName'])) ?> me-2 cat-icon"></i><?= htmlspecialchars($category['CategoryName']) ?></a>
                      <?php endforeach; ?>
                    </div>
                  </div>
                </aside>

                <div class="col-lg-9" id="featured-products">
                  <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                      <p class="section-label mb-1">Featured products</p>
                      <h2 class="h3 mb-0">Popular right now</h2>
                    </div>
                    <a class="text-decoration-none" href="#">View all</a>
                  </div>

                  <div class="row g-4">
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
                              <small class="stock-line text-muted d-block"><?= htmlspecialchars(stock_line($product)) ?></small>
                            </div>
                            <a href="order.php" class="btn btn-sm btn-dark">Order</a>
                          </div>
                        </div>
                      </div>
                    </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            </div>
          </section>

          <section class="deals-section py-5" id="deals">
            <div class="container">
              <div class="row g-4 align-items-center">
                <div class="col-lg-7">
                  <p class="section-label mb-1">This week only</p>
                  <h2 class="h3 mb-3 text-white">Deals of the week — save on business essentials</h2>
                  <p class="text-white-50 mb-4">Business laptops, 4K monitors, managed switches and accessories from HP, Dell, Cisco and Logitech at reduced prices until Sunday night.</p>
                  <a href="#featured-products" class="btn btn-accent btn-lg">Grab the deals</a>
                </div>
                <div class="col-lg-5">
                  <div class="deals-card d-flex align-items-center justify-content-around gap-3">
                    <div class="text-center">
                      <div class="display-6 fw-bold"><?= number_format($maxDiscount, 1) ?>%</div>
                      <small class="text-white-50 d-block">Max discount</small>
                    </div>
                    <div class="vr"></div>
                    <div class="text-center">
                      <div class="display-6 fw-bold"><?= count($onSale) ?></div>
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
            </div>
          </section>

          <section class="py-5" id="why-us">
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
