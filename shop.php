<?php
/**
 * SebaBD — the shop: the whole catalogue, filtered and paged.
 *
 *   shop.php?q=<text>&category=<id>&brand=<name>&sort=<key>&page=2
 *
 * Every control here is an ordinary GET link or form, so a result set is one
 * shareable URL and the page works with JavaScript switched off. The homepage
 * shows a curated selection; this is where the full range lives.
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/catalog-data.php';
require_once __DIR__ . '/cart-data.php';

$filters   = catalog_filters($_GET);
$total     = catalog_total($filters);
$perPage   = (int) $filters['per_page'];
$pageCount = max(1, (int) ceil($total / $perPage));
// A bookmarked ?page= past the end lands on the last page instead of an empty grid.
$filters['page'] = min($filters['page'], $pageCount);

$products     = catalog_products($filters);
$available    = stock_counts($products);
$categories   = get_categories();
$brands       = catalog_brands();
$categoryName = $filters['category'] > 0 ? category_name($filters['category']) : '';
$filtered     = $filters['q'] !== '' || $filters['category'] > 0 || $filters['brand'] !== '';

$showing = count($products);
$first   = $showing > 0 ? (($filters['page'] - 1) * $perPage) + 1 : 0;
$last    = $first + $showing - 1;

$sorts = [
    'featured'   => 'Featured first',
    'newest'     => 'Newest first',
    'price_asc'  => 'Price: low to high',
    'price_desc' => 'Price: high to low',
    'name'       => 'Name: A to Z',
];

if ($filters['q'] !== '') {
    $heading = 'Search results for “' . htmlspecialchars($filters['q']) . '”';
} elseif ($categoryName !== '') {
    $heading = $categoryName;
} elseif ($filters['brand'] !== '') {
    $heading = $filters['brand'] . ' products';
} else {
    $heading = 'All products';
}

// The pager prints a window of pages around the current one rather than every
// page, so a catalog of a few hundred items does not turn into a wall of links.
$windowStart = max(1, min($filters['page'] - 2, $pageCount - 4));
$windowEnd   = min($pageCount, $windowStart + 4);
?>
<?= partial_render('partials/header.php', ['page_title' => $heading, 'active_nav' => 'shop']) ?>
          <section class="page-head py-4">
            <div class="container">
              <nav aria-label="breadcrumb">
                <ol class="breadcrumb crumb mb-2">
                  <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                  <li class="breadcrumb-item"><a href="shop.php">Shop</a></li>
                  <?php if ($categoryName !== ''): ?>
                  <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($categoryName) ?></li>
                  <?php endif; ?>
                </ol>
              </nav>
              <h1 class="h3 mb-1"><?= $heading ?></h1>
              <p class="text-muted mb-0">
                <?php if ($showing > 0): ?>
                Showing <?= $first ?>–<?= $last ?> of <?= $total ?> product<?= $total === 1 ? '' : 's' ?><?=
                  $filtered ? ' matching your filters' : ' across ' . count($categories) . ' categories' ?>.
                <?php else: ?>
                No products to show.
                <?php endif; ?>
              </p>
            </div>
          </section>

          <section class="pb-5">
            <div class="container">
              <div class="row g-4">
                <aside class="col-lg-3">
                  <div class="sidebar-card mb-3">
                    <h2 class="h5 mb-3">Shop by category</h2>
                    <div class="list-group list-group-flush">
                      <a class="list-group-item list-group-item-action <?= $filters['category'] === 0 ? 'active' : '' ?>"
                         href="<?= htmlspecialchars(shop_url($filters, ['category' => 0, 'page' => 1])) ?>">
                        <i class="fa-solid fa-layer-group me-2 cat-icon"></i>All categories
                      </a>
                      <?php foreach ($categories as $category): ?>
                      <a class="list-group-item list-group-item-action <?= (int) $category['CategoryID'] === $filters['category'] ? 'active' : '' ?>"
                         href="<?= htmlspecialchars(shop_url($filters, ['category' => (int) $category['CategoryID'], 'page' => 1])) ?>">
                        <i class="<?= htmlspecialchars(category_icon($category['CategoryName'])) ?> me-2 cat-icon"></i><?= htmlspecialchars($category['CategoryName']) ?>
                      </a>
                      <?php endforeach; ?>
                    </div>
                  </div>

                  <?php if ($brands): ?>
                  <div class="sidebar-card">
                    <h2 class="h5 mb-3">Brands</h2>
                    <div class="d-flex flex-wrap gap-2">
                      <?php foreach ($brands as $brand): ?>
                      <a class="badge rounded-pill text-decoration-none <?= $filters['brand'] === (string) $brand['Brand'] ? 'text-bg-dark' : 'text-bg-light border' ?>"
                         href="<?= htmlspecialchars(shop_url($filters, [
                             'brand' => $filters['brand'] === (string) $brand['Brand'] ? '' : (string) $brand['Brand'],
                             'page'  => 1,
                         ])) ?>">
                        <?= htmlspecialchars((string) $brand['Brand']) ?>
                        <span class="opacity-75"><?= (int) $brand['Products'] ?></span>
                      </a>
                      <?php endforeach; ?>
                    </div>
                  </div>
                  <?php endif; ?>
                </aside>

                <div class="col-lg-9">
                  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <?php if ($filtered): ?>
                    <a class="text-decoration-none small" href="shop.php">
                      <i class="fa-solid fa-xmark me-1"></i>Clear filters<?= $filters['q'] !== '' || $filters['brand'] !== '' || $filters['category'] > 0 ? ' (' . $total . ' result' . ($total === 1 ? '' : 's') . ')' : '' ?>
                    </a>
                    <?php else: ?>
                    <span class="text-muted small">Every product we stock — the shop leads with the items we recommend.</span>
                    <?php endif; ?>

                    <form class="d-flex align-items-center gap-2" method="get" action="shop.php">
                      <?php if ($filters['q'] !== ''): ?>
                      <input type="hidden" name="q" value="<?= htmlspecialchars($filters['q']) ?>">
                      <?php endif; ?>
                      <?php if ($filters['category'] > 0): ?>
                      <input type="hidden" name="category" value="<?= (int) $filters['category'] ?>">
                      <?php endif; ?>
                      <?php if ($filters['brand'] !== ''): ?>
                      <input type="hidden" name="brand" value="<?= htmlspecialchars($filters['brand']) ?>">
                      <?php endif; ?>
                      <label class="form-label mb-0 small text-muted" for="sort">Sort</label>
                      <select class="form-select form-select-sm" id="sort" name="sort">
                        <?php foreach ($sorts as $key => $label): ?>
                        <option value="<?= htmlspecialchars($key) ?>" <?= $filters['sort'] === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <button class="btn btn-sm btn-outline-dark" type="submit">Apply</button>
                    </form>
                  </div>

                  <?php if (!$products): ?>
                  <div class="alert alert-light border text-center py-5 mb-0">
                    <i class="fa-solid fa-magnifying-glass fa-lg d-block mb-2 text-muted"></i>
                    <p class="mb-2">Nothing matched that selection.</p>
                    <p class="mb-0 small">Try a brand like <em>Dell</em>, a category like <em>monitors</em>,
                      or <a href="shop.php">browse the whole catalogue</a>.</p>
                  </div>
                  <?php else: ?>
                  <div class="row g-4">
                    <?php foreach ($products as $product): ?>
                    <?= partial_render('partials/product-card.php', [
                        'product'   => $product,
                        'available' => $available[(int) $product['ProductID']] ?? 0,
                    ]) ?>
                    <?php endforeach; ?>
                  </div>

                  <?php if ($pageCount > 1): ?>
                  <nav class="mt-4" aria-label="Product pages">
                    <ul class="pagination justify-content-center mb-1">
                      <li class="page-item <?= $filters['page'] <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= htmlspecialchars(shop_url($filters, ['page' => $filters['page'] - 1])) ?>" aria-label="Previous page">
                          <i class="fa-solid fa-chevron-left"></i>
                        </a>
                      </li>
                      <?php if ($windowStart > 1): ?>
                      <li class="page-item"><a class="page-link" href="<?= htmlspecialchars(shop_url($filters, ['page' => 1])) ?>">1</a></li>
                      <?php if ($windowStart > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                      <?php endif; ?>
                      <?php for ($p = $windowStart; $p <= $windowEnd; $p++): ?>
                      <li class="page-item <?= $p === $filters['page'] ? 'active' : '' ?>">
                        <a class="page-link" href="<?= htmlspecialchars(shop_url($filters, ['page' => $p])) ?>" <?= $p === $filters['page'] ? 'aria-current="page"' : '' ?>><?= $p ?></a>
                      </li>
                      <?php endfor; ?>
                      <?php if ($windowEnd < $pageCount): ?>
                      <?php if ($windowEnd < $pageCount - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                      <li class="page-item"><a class="page-link" href="<?= htmlspecialchars(shop_url($filters, ['page' => $pageCount])) ?>"><?= $pageCount ?></a></li>
                      <?php endif; ?>
                      <li class="page-item <?= $filters['page'] >= $pageCount ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= htmlspecialchars(shop_url($filters, ['page' => $filters['page'] + 1])) ?>" aria-label="Next page">
                          <i class="fa-solid fa-chevron-right"></i>
                        </a>
                      </li>
                    </ul>
                    <p class="text-center text-muted small mb-0">Page <?= $filters['page'] ?> of <?= $pageCount ?></p>
                  </nav>
                  <?php endif; ?>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </section>
<?= partial_render('partials/footer.php') ?>
