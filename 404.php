<?php
/**
 * SebaBD — page not found.
 *
 * Wired as Apache's error document through .htaccess, so a mistyped or expired
 * address lands here instead of on a bare server page. It answers 404 (the
 * status matters to search engines and to anyone watching the network tab) and
 * then helps the visitor get somewhere real: search, the catalog, the pages
 * people actually want, and their own dashboard if they are signed in.
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/catalog-data.php';

// Apache keeps this status even though the page below renders normally.
http_response_code(404);

// A fetch() caller gets JSON rather than a whole page of HTML.
if (json_request()) {
    json_response(['ok' => false, 'errors' => ['That page does not exist.']], 404);
}

$user   = current_user();
$wanted = (string) ($_SERVER['REQUEST_URI'] ?? '');

$categories = db() ? db_fetch_all(
    'SELECT c.CategoryID, c.CategoryName, COUNT(p.ProductID) AS Products
     FROM category c
     LEFT JOIN product p ON p.CategoryID = c.CategoryID
     GROUP BY c.CategoryID, c.CategoryName
     ORDER BY c.CategoryID'
) : [];

echo partial_render('partials/header.php', ['page_title' => 'Page not found', 'active_nav' => '']);
?>
<section class="py-5">
  <div class="container">
    <div class="row justify-content-center">
      <div class="col-lg-8">
        <div class="report-panel text-center py-5">
          <i class="fa-solid fa-compass fa-2x text-muted d-block mb-3"></i>
          <p class="section-label mb-1">Error 404</p>
          <h1 class="h3 mb-2">We could not find that page</h1>
          <p class="text-muted mb-4">
            <?php if ($wanted !== ''): ?>
              <code><?= htmlspecialchars(mb_strimwidth($wanted, 0, 60, '…')) ?></code> is not a page on this site.
            <?php endif; ?>
            It may have been renamed, or the link may be out of date — especially for a quotation or
            invoice, which are opened by number rather than by a fixed address.
          </p>

          <form class="search-form mx-auto mb-4" role="search" action="index.php" method="get" data-live-search
                style="max-width: 28rem;">
            <div class="input-group">
              <input class="form-control" type="search" name="q" placeholder="Search the catalog…"
                     aria-label="Search products" autocomplete="off" aria-expanded="false"
                     aria-controls="search-suggestions">
              <button class="btn btn-accent" type="submit"><i class="fa-solid fa-magnifying-glass"></i></button>
              <div class="search-suggestions d-none" id="search-suggestions" role="listbox" aria-label="Product suggestions"></div>
            </div>
          </form>

          <div class="d-flex flex-wrap gap-2 justify-content-center">
            <a class="btn btn-accent" href="index.php"><i class="fa-solid fa-house me-1"></i>Home</a>
            <a class="btn btn-outline-dark" href="index.php?all=1#featured-products">Full catalog</a>
            <?php if ($user): ?>
            <a class="btn btn-outline-dark" href="dashboard.php">My dashboard</a>
            <a class="btn btn-outline-dark" href="account.php">My account</a>
            <?php else: ?>
            <a class="btn btn-outline-dark" href="login.php">Sign in</a>
            <?php endif; ?>
            <?php if (can_request_quotation()): ?>
            <a class="btn btn-outline-secondary" href="quotation-request.php">Request a quotation</a>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($categories): ?>
        <div class="report-panel mt-3">
          <div class="report-panel-head">
            <h2 class="report-panel-title">Shop by category</h2>
            <p class="report-panel-sub">Every category we stock, with what is in it.</p>
          </div>
          <div class="list-group list-group-flush" data-category-list>
            <?php foreach ($categories as $category): ?>
            <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
               href="index.php?category=<?= (int) $category['CategoryID'] ?>#featured-products">
              <span>
                <i class="<?= htmlspecialchars(category_icon($category['CategoryName'])) ?> me-2 cat-icon"></i><?= htmlspecialchars($category['CategoryName']) ?>
              </span>
              <span class="badge text-bg-light"><?= (int) $category['Products'] ?></span>
            </a>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>
<?= partial_render('partials/footer.php') ?>
