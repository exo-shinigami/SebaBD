<?php
/**
 * SebaBD — category index.
 *
 * The full range by group, with how many products each group holds and the
 * cheapest item in it, so a visitor can choose a department instead of paging
 * through the whole catalogue. Each tile is a link into shop.php?category=<id>.
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/catalog-data.php';
require_once __DIR__ . '/cart-data.php';

$categories = category_overview();
$total      = get_product_count();
?>
<?= partial_render('partials/header.php', ['page_title' => 'Categories', 'active_nav' => 'categories']) ?>
          <section class="py-4">
            <div class="container">
              <nav aria-label="breadcrumb">
                <ol class="breadcrumb crumb mb-3">
                  <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                  <li class="breadcrumb-item active" aria-current="page">Categories</li>
                </ol>
              </nav>

              <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-4">
                <div>
                  <p class="section-label mb-1">Browse the range</p>
                  <h1 class="h3 mb-0">Shop by category</h1>
                </div>
                <a class="text-decoration-none" href="shop.php">
                  All <?= $total ?> products <i class="fa-solid fa-arrow-right ms-1"></i>
                </a>
              </div>

              <div class="row g-4">
                <?php foreach ($categories as $category): ?>
                <div class="col-md-6 col-xl-4">
                  <div class="feature-card h-100 d-flex flex-column">
                    <span class="feature-icon">
                      <i class="<?= htmlspecialchars(category_icon((string) $category['CategoryName'])) ?>"></i>
                    </span>
                    <h2 class="h5 mt-3 mb-1"><?= htmlspecialchars((string) $category['CategoryName']) ?></h2>
                    <p class="text-muted small mb-3">
                      <?= (int) $category['Products'] ?> product<?= (int) $category['Products'] === 1 ? '' : 's' ?>
                      <?php if (!empty($category['FromPrice'])): ?>
                      · from <strong><?= money($category['FromPrice']) ?></strong>
                      <?php endif; ?>
                    </p>
                    <div class="mt-auto">
                      <a class="btn btn-outline-dark btn-sm"
                         href="shop.php?category=<?= (int) $category['CategoryID'] ?>">
                        Shop <?= htmlspecialchars((string) $category['CategoryName']) ?>
                        <i class="fa-solid fa-arrow-right ms-1"></i>
                      </a>
                    </div>
                  </div>
                </div>
                <?php endforeach; ?>
              </div>

              <div class="alert alert-light border mt-5 mb-0 d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                  <p class="mb-1 fw-semibold">Looking for something that is not listed?</p>
                  <p class="mb-0 text-muted small">We supply beyond the catalogue — tell us the specification and we will quote it.</p>
                </div>                 <?php if (can_request_quotation()): ?>
                <a class="btn btn-accent" href="quotation-request.php">
                  <i class="fa-solid fa-file-pen me-2"></i>Request a quotation
                </a>
                <?php endif; ?>
              </div>
            </div>
          </section>
<?= partial_render('partials/footer.php') ?>
