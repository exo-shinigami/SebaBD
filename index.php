<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/catalog-data.php';
require_once __DIR__ . '/helpers.php';

/* ------------------------------------------------------ storefront view
 *
 * One code path serves both the first paint and the AJAX refresh:
 *
 *   index.php                            full page, and search / category also
 *                                        work with JavaScript disabled because
 *                                        they are ordinary GET links
 *   index.php?ajax=panel&q=…             just the product panel, which
 *                                        assets/ajax.js swaps in place
 *
 * Filters: ?q=<text>  ?category=<CategoryID>  ?all=1
 */
$query      = trim((string) ($_GET['q'] ?? ''));
$categoryId = max(0, (int) ($_GET['category'] ?? 0));
$showAll    = isset($_GET['all']);
$filtered   = $query !== '' || $categoryId > 0 || $showAll;

if ($filtered) {
    $products   = search_products($query, $categoryId, 24);
    // The deals strip keeps advertising the featured range, not the search hit.
    $dealSource = get_featured_products();
} else {
    $products   = get_featured_products();
    $dealSource = $products;
}

$categories   = get_categories();
$categoryName = $categoryId > 0 ? category_name($categoryId) : '';

$panelVars = [
    'products'     => $products,
    'query'        => $query,
    'categoryName' => $categoryName,
    'showAll'      => $showAll,
];

// Deal stats are derived from what the DB actually returned, not hard-coded.
$onSale      = array_filter($dealSource, fn($p) => !empty($p['sale']) && (float) $p['sale'] > (float) $p['StandardPrice']);
$maxDiscount = 0.0;
foreach ($onSale as $p) {
    $was = (float) $p['sale'];
    $maxDiscount = max($maxDiscount, ($was - (float) $p['StandardPrice']) / $was * 100);
}

// AJAX refresh: hand back only the panel, without the page chrome.
if (($_GET['ajax'] ?? '') === 'panel') {
    echo partial_render('partials/product-panel.php', $panelVars);
    exit;
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
                    <div class="list-group list-group-flush" data-category-list>
                      <?php foreach ($categories as $category): ?>
                      <a class="list-group-item list-group-item-action <?= (int) $category['CategoryID'] === $categoryId ? 'active' : '' ?>"
                         href="index.php?category=<?= (int) $category['CategoryID'] ?>#featured-products" data-filter-link>
                        <i class="<?= htmlspecialchars(category_icon($category['CategoryName'])) ?> me-2 cat-icon"></i><?= htmlspecialchars($category['CategoryName']) ?>
                      </a>
                      <?php endforeach; ?>
                      <a class="list-group-item list-group-item-action <?= $showAll ? 'active' : '' ?>"
                         href="index.php?all=1#featured-products" data-filter-link>
                        <i class="fa-solid fa-layer-group me-2 cat-icon"></i>All products
                      </a>
                    </div>
                    <p class="text-muted small mb-0 mt-3">
                      <i class="fa-solid fa-wand-magic-sparkles me-1"></i>Search or pick a category — the grid refreshes without a page reload.
                    </p>
                  </div>
                </aside>

                <div class="col-lg-9" id="featured-products" data-product-panel>
                  <?= partial_render('partials/product-panel.php', $panelVars) ?>
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
