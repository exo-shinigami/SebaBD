<?php
/**
 * SebaBD — catalog data layer.
 *
 * Storefront presentation data (images, marketing copy, deal badges) is NOT
 * part of the DBMS schema, so it lives here keyed by ProductID. The database
 * remains the single source of truth for products, categories, stock and
 * prices; this file only decorates what the database returns.
 *
 * If MySQL is unreachable, these arrays double as an offline fallback so the
 * storefront still renders.
 */

// This file is a library, never an endpoint: it declares functions and emits
// nothing. A few hosts include it before helpers.php, so pull the two
// dependencies in when they are not already loaded.
if (!function_exists('db_fetch_all')) {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/helpers.php';
}

/**
 * Image path, deal badge and short blurb for each product, keyed by ProductID.
 *
 * Images are local files under images/products/ so the storefront never
 * depends on an external host:
 *   - 1, 2, 4  → manufacturer product photos from the sebabd.com catalog
 *   - 3        → Wikimedia Commons, "19-inch rackmount Ethernet switches and
 *                patch panels" (CC BY-SA), used for the Cisco switch
 * Drop a replacement file in images/products/ and update the path here.
 */
function product_meta(): array
{
    return [
        1 => [
            'image' => 'images/products/laptop.jpg',
            'alt'   => 'HP ProBook 450 G10 business laptop',
            'badge' => 'Hot',
            'blurb' => 'HP ProBook 450 G10 business notebook (Core i5, 16 GB) — 24-month warranty tracked per serial number.',
            'sale'  => 158400.00,
        ],
        2 => [
            'image' => 'images/products/monitor.jpg',
            'alt'   => 'Dell UltraSharp 27-inch 4K monitor',
            'badge' => 'Deal',
            'blurb' => 'Dell UltraSharp 4K IPS panel (U2723QE) with USB-C docking and factory colour calibration — 12-month warranty.',
            'sale'  => 74400.00,
        ],
        3 => [
            'image' => 'images/products/switch.jpg',
            'alt'   => 'Cisco 24-port managed network switch',
            'badge' => 'New',
            'blurb' => 'Cisco CBS350-24T 24-port managed gigabit switch — 36-month manufacturer warranty tracked per serial number.',
            'sale'  => null,
        ],
        4 => [
            'image' => 'images/products/mouse.png',
            'alt'   => 'Logitech MX Master 3S wireless mouse',
            'badge' => 'Deal',
            'blurb' => 'Logitech MX Master 3S wireless mouse — quiet clicks, 8,000 DPI tracking and multi-device switching.',
            'sale'  => 14400.00,
        ],
    ];
}

/**
 * The product's picture, or a branded placeholder when no photo has been mapped
 * for it in product_meta().
 *
 * Products created in the back office have no entry in product_meta() by
 * design — the database, not this file, decides whether a product exists. The
 * placeholder keeps those products looking deliberate on the storefront
 * instead of showing a broken image icon.
 *
 * @param string $class CSS class for the <img>, e.g. 'card-img-top product-image'.
 */
function product_image_html(array $product, string $class = 'product-image'): string
{
    $image = trim((string) ($product['image'] ?? ''));
    $alt   = (string) ($product['alt'] ?? $product['ProductName'] ?? 'Product');

    $classAttr = trim($class) !== '' ? ' class="' . htmlspecialchars($class) . '"' : '';

    if ($image !== '') {
        return '<img src="' . htmlspecialchars($image) . '"' . $classAttr
             . ' alt="' . htmlspecialchars($alt) . '">';
    }

    // No photo: a tile in the same space, carrying the category icon and the
    // product name so the card still reads at a glance.
    return '<div' . ($classAttr !== '' ? ' class="' . htmlspecialchars(trim($class) . ' product-image-fallback') . '"' : '')
         . ' role="img" aria-label="' . htmlspecialchars($alt) . '">'
         . '<i class="' . htmlspecialchars(category_icon((string) ($product['CategoryName'] ?? ''))) . '"></i>'
         . '<span>' . htmlspecialchars((string) ($product['ProductName'] ?? '')) . '</span>'
         . '</div>';
}

/**
 * Font Awesome icon per category name (used when a category has no icon mapping).
 */
function category_icon(string $name): string
{
    $map = [
        'Laptops & Computers'  => 'fa-solid fa-laptop',
        'Monitors & Displays'  => 'fa-solid fa-tv',
        'Networking Equipment' => 'fa-solid fa-network-wired',
        'Accessories'          => 'fa-solid fa-computer-mouse',
        'Printers & Scanners'  => 'fa-solid fa-print',
        'Storage'              => 'fa-solid fa-hard-drive',
        'Power & UPS'          => 'fa-solid fa-car-battery',
        'Projectors'           => 'fa-solid fa-video',
        'Components'           => 'fa-solid fa-microchip',
        'Access Control'       => 'fa-solid fa-fingerprint',
    ];

    return $map[$name] ?? 'fa-solid fa-tag';
}

/**
 * Categories for the sidebar. Reads from the DB; falls back to the same list
 * when the database is unavailable.
 */
function get_categories(): array
{
    $rows = db_fetch_all('SELECT CategoryID, CategoryName FROM category ORDER BY CategoryID');
    if ($rows) {
        return $rows;
    }

    // Fallback mirrors the sample data in database/e_commerce.sql.
    return [
        ['CategoryID' => 1, 'CategoryName' => 'Laptops & Computers'],
        ['CategoryID' => 2, 'CategoryName' => 'Monitors & Displays'],
        ['CategoryID' => 3, 'CategoryName' => 'Networking Equipment'],
        ['CategoryID' => 4, 'CategoryName' => 'Accessories'],
    ];
}

/**
 * Total number of products in the catalog (for the homepage stats strip).
 */
function get_product_count(): int
{
    $rows = db_fetch_all('SELECT COUNT(*) AS n FROM product');
    $n = (int) ($rows[0]['n'] ?? 0);

    return $n > 0 ? $n : count(product_meta());
}

/**
 * Featured products for the storefront grid: one representative product per
 * category (lowest ProductID in each), so the homepage shows the full range.
 * Reads live data from the DB and merges in the presentation metadata; falls
 * back to the offline catalogue when the database is unreachable.
 */
function get_featured_products(int $limit = 12): array
{
    $limit = max(1, min(50, $limit)); // sanitized — interpolated, not bound
    $rows = db_fetch_all(
        "SELECT p.ProductID, p.ProductName, p.Brand, p.Model, p.StandardPrice,
                p.IsSerialized, p.StockQty, p.DefaultWarrantyMonths,
                c.CategoryName
         FROM product p
         JOIN category c ON c.CategoryID = p.CategoryID
         JOIN (SELECT MIN(ProductID) AS PickID FROM product GROUP BY CategoryID) pick
              ON pick.PickID = p.ProductID
         ORDER BY c.CategoryID
         LIMIT $limit"
    );

    $products = [];
    foreach ($rows as $row) {
        $meta = product_meta()[(int) $row['ProductID']] ?? [
            'image' => null,
            'alt'   => $row['ProductName'],
            'badge' => null,
            'blurb' => '',
            'sale'  => null,
        ];
        $products[] = array_merge($row, $meta);
    }

    if ($products) {
        return array_slice($products, 0, $limit);
    }

    // Offline fallback: one representative item per category, mirroring the
    // live query's per-category pick. [id, name, brand, category, price, serialized, qty]
    $fallback = [
        [1, 'ProBook 450 G10',                    'HP',       'Laptops & Computers',   '144000.00', 1, 0],
        [2, 'UltraSharp 27" 4K Monitor',          'Dell',     'Monitors & Displays',   '66000.00',  0, 45],
        [3, 'CBS350-24T 24-Port Managed Switch',  'Cisco',    'Networking Equipment',  '102000.00', 1, 0],
        [4, 'MX Master 3S Wireless Mouse',        'Logitech', 'Accessories',           '12000.00',  0, 120],
    ];

    $products = [];
    foreach ($fallback as [$id, $name, $brand, $cat, $price, $serialized, $qty]) {
        $products[] = array_merge(
            [
                'ProductID' => $id, 'ProductName' => $name, 'Brand' => $brand,
                'CategoryName' => $cat, 'StandardPrice' => $price,
                'StockQty' => $qty, 'IsSerialized' => $serialized,
            ],
            product_meta()[$id]
        );
    }

    return array_slice($products, 0, $limit);
}

/**
 * Products in one category, newest ProductID first, with presentation metadata
 * merged in. Reads live data; falls back to the offline catalogue when the
 * database is unreachable.
 */
function get_products_by_category(int $categoryId, int $limit = 50): array
{
    $limit = max(1, min(100, $limit)); // sanitized — interpolated, not bound
    $rows = db_fetch_all(
        "SELECT p.ProductID, p.ProductName, p.Brand, p.Model, p.StandardPrice,
                p.IsSerialized, p.StockQty, p.DefaultWarrantyMonths,
                c.CategoryName
         FROM product p
         JOIN category c ON c.CategoryID = p.CategoryID
         WHERE p.CategoryID = :cid
         ORDER BY p.ProductID DESC
         LIMIT $limit",
        [':cid' => $categoryId]
    );

    $products = [];
    foreach ($rows as $row) {
        $products[] = array_merge($row, product_meta()[(int) $row['ProductID']] ?? [
            'image' => null,
            'alt'   => $row['ProductName'],
            'badge' => null,
            'blurb' => '',
            'sale'  => null,
        ]);
    }

    return $products;
}

/**
 * Products related to one already on the page (same category, different item).
 */
function related_products(int $productId, int $categoryId, int $limit = 4): array
{
    $limit = max(1, min(12, $limit)); // sanitized — interpolated, not bound
    $rows = db_fetch_all(
        'SELECT p.ProductID, p.ProductName, p.Brand, p.Model, p.StandardPrice,
                p.IsSerialized, p.StockQty, p.DefaultWarrantyMonths,
                c.CategoryName
         FROM product p
         JOIN category c ON c.CategoryID = p.CategoryID
         WHERE p.CategoryID = :cid AND p.ProductID <> :id
         ORDER BY p.ProductID
         LIMIT ' . $limit,
        [':cid' => $categoryId, ':id' => $productId]
    );

    $products = [];
    foreach ($rows as $row) {
        $products[] = array_merge($row, product_meta()[(int) $row['ProductID']] ?? [
            'image' => null,
            'alt'   => $row['ProductName'],
            'badge' => null,
            'blurb' => '',
            'sale'  => null,
        ]);
    }

    return $products;
}

/**
 * Serialized units of a product (the product_instance table). Only staff see
 * this list; the storefront shows the available count instead.
 */
function product_units(int $productId): array
{
    return db_fetch_all(
        'SELECT EquipmentID, SerialNumber, PurchaseDate, VendorWarrantyExpiry,
                ClientWarrantyExpiry, Status
         FROM product_instance
         WHERE ProductID = :id
         ORDER BY Status, EquipmentID',
        [':id' => $productId]
    );
}

/**
 * One category row by id (used to label storefront search results).
 */
function category_name(int $categoryId): string
{
    if ($categoryId <= 0) {
        return '';
    }
    $rows = db_fetch_all(
        'SELECT CategoryName FROM category WHERE CategoryID = :id',
        [':id' => $categoryId]
    );

    return (string) ($rows[0]['CategoryName'] ?? '');
}

/**
 * One product row by id, with presentation metadata merged in. Returns an
 * empty array when the product does not exist or the database is unreachable.
 *
 * Shape: ProductID, CategoryID, ProductName, Brand, Model, StandardPrice,
 *        IsSerialized, StockQty, DefaultWarrantyMonths, CategoryName,
 *        VendorName, VendorLocation, plus the keys from product_meta() when
 *        present (image, alt, badge, blurb, sale).
 *
 * CategoryID and the vendor fields are part of the row because the detail page
 * links back into the category and prints the supplier; leaving them out made
 * the breadcrumb point at category 0 and the related-products strip empty.
 */
function get_product(int $productId): array
{
    if ($productId <= 0) {
        return [];
    }

    $rows = db_fetch_all(
        "SELECT p.ProductID, p.CategoryID, p.ProductName, p.Brand, p.Model,
                p.StandardPrice, p.IsSerialized, p.StockQty,
                p.DefaultWarrantyMonths, c.CategoryName,
                v.VendorName, v.Location AS VendorLocation
         FROM product p
         JOIN category c ON c.CategoryID = p.CategoryID
         JOIN vendor   v ON v.VendorID   = p.VendorID
         WHERE p.ProductID = :id",
        [':id' => $productId]
    );

    if (!$rows) {
        return [];
    }

    $row = $rows[0];
    $meta = product_meta()[(int) $row['ProductID']] ?? [
        'image' => null,
        'alt'   => $row['ProductName'],
        'badge' => null,
        'blurb' => '',
        'sale'  => null,
    ];

    return array_merge($row, $meta);
}

/**
 * Units on hand, keyed by ProductID: [ProductID => available count].
 *
 * A serialized product is counted from its product_instance rows (Status
 * 'In Stock'), because product.StockQty stays 0 for those — the units are the
 * stock. Everything else uses product.StockQty. The numbers are read from the
 * database here rather than from the rows the caller passed in, so a caller
 * that selected only the display columns still gets the right answer.
 */
function stock_counts(array $products): array
{
    if (!$products) {
        return [];
    }

    $ids = [];
    foreach ($products as $p) {
        $id = (int) ($p['ProductID'] ?? 0);
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    if (!$ids) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params       = array_values($ids);

    $rows = db_fetch_all(
        "SELECT ProductID, IsSerialized, StockQty FROM product WHERE ProductID IN ($placeholders)",
        $params
    );

    $serialized = [];
    $bulk       = [];
    foreach ($rows as $row) {
        $id = (int) $row['ProductID'];
        if ((int) $row['IsSerialized'] === 1) {
            $serialized[$id] = 0;
        } else {
            $bulk[$id] = (int) $row['StockQty'];
        }
    }

    if ($serialized) {
        $marks = implode(',', array_fill(0, count($serialized), '?'));
        $units = db_fetch_all(
            "SELECT ProductID, COUNT(*) AS n FROM product_instance
             WHERE Status = 'In Stock' AND ProductID IN ($marks)
             GROUP BY ProductID",
            array_keys($serialized)
        );
        foreach ($units as $unit) {
            $serialized[(int) $unit['ProductID']] = (int) $unit['n'];
        }
    }

    $result = [];
    foreach ($ids as $id) {
        $result[$id] = $serialized[$id] ?? $bulk[$id] ?? 0;
    }

    return $result;
}

/**
 * Human-readable stock line for one product card. Receives the product row and
 * the available unit count from stock_counts() and returns a short label:
 * "In stock", "Low stock", "Made to order", or "Out of stock".
 *
 * A serialized product with no units on hand is not out of stock — it is
 * ordered per device and its warranty is tracked per serial number, which is
 * what "Made to order" tells the shopper.
 */
function stock_label(array $product, int $available): string
{
    if ((int) ($product['IsSerialized'] ?? 0) === 1) {
        return $available > 0 ? $available . ' in stock' : 'Made to order';
    }

    if ($available <= 0) {
        return 'Out of stock';
    }

    if ($available <= 5) {
        return 'Low stock';
    }

    return 'In stock';
}

/**
 * Free-text product search for the header search box. Searches product name,
 * brand and model so a customer can find items without typing the full name.
 *
 * When the database is unavailable this falls back to a substring match over
 * the offline product catalogue.
 */
function search_products(string $query, ?int $categoryId, int $limit): array
{
    $query = trim($query);
    $limit = max(1, min(100, $limit)); // sanitized — interpolated, not bound

    if ($query === '') {
        if ($categoryId && $categoryId > 0) {
            return get_products_by_category($categoryId, $limit);
        }

        return get_featured_products($limit);
    }

    // Live search against the real catalogue. One placeholder per column: the
    // connection runs native prepared statements (EMULATE_PREPARES off), and
    // MySQL rejects a statement that names the same placeholder twice with
    // SQLSTATE[HY093], which made every search silently fall back offline.
    $like = '%' . $query . '%';
    $rows = db_fetch_all(
        "SELECT p.ProductID, p.ProductName, p.Brand, p.Model, p.StandardPrice,
                p.IsSerialized, p.StockQty, p.DefaultWarrantyMonths,
                c.CategoryName
         FROM product p
         JOIN category c ON c.CategoryID = p.CategoryID
         WHERE (p.ProductName LIKE :q1 OR p.Brand LIKE :q2 OR p.Model LIKE :q3)
           " . ($categoryId && $categoryId > 0 ? "AND p.CategoryID = :cid" : "") . "
         ORDER BY p.ProductID
         LIMIT $limit",
        array_merge(
            [':q1' => $like, ':q2' => $like, ':q3' => $like],
            $categoryId && $categoryId > 0 ? [':cid' => $categoryId] : []
        )
    );

    $products = [];
    foreach ($rows as $row) {
        $products[] = array_merge($row, product_meta()[(int) $row['ProductID']] ?? [
            'image' => null,
            'alt'   => $row['ProductName'],
            'badge' => null,
            'blurb' => '',
            'sale'  => null,
        ]);
    }

    if ($products) {
        return $products;
    }

    // Fallback: substring match over the offline catalogue so the search box
    // still returns something when the database is down.
    $q = mb_strtolower($query);
    $matched = [];
    foreach (product_meta() as $id => $meta) {
        if (mb_strpos(mb_strtolower($meta['alt'] ?? ''), $q) !== false) {
            $matched[$id] = $meta;
        }
    }

    if ($matched) {
        $fallbackRows = db_fetch_all(
            "SELECT p.ProductID, p.ProductName, p.Brand, p.Model, p.StandardPrice,
                    p.IsSerialized, p.StockQty, p.DefaultWarrantyMonths,
                    c.CategoryName
             FROM product p
             JOIN category c ON c.CategoryID = p.CategoryID
             WHERE p.ProductID IN (" . implode(',', array_keys($matched)) . ")
             ORDER BY p.ProductID"
        );

        $byId = [];
        foreach ($fallbackRows as $row) {
            $byId[(int) $row['ProductID']] = $row;
        }

        $products = [];
        foreach ($matched as $id => $meta) {
            $row = $byId[$id] ?? [];
            $products[] = array_merge($row, $meta);
        }

        return array_slice($products, 0, $limit);
    }

    // Nothing matched anywhere — empty is the honest response.
    return [];
}
