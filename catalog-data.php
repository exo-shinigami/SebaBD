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
 * Units on hand for several products in one round trip.
 *
 * Serialized products track stock through product_instance rows marked
 * 'In Stock'; everything else uses product.StockQty. Returns [ProductID => n].
 *
 * Batched because the AJAX endpoints and the product grid ask for many
 * products at once — one query beats one query per card.
 */
function stock_counts(array $products): array
{
    $counts     = [];
    $serialized = [];

    foreach ($products as $product) {
        $id = (int) ($product['ProductID'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        if (!empty($product['IsSerialized'])) {
            $serialized[$id] = $id;
        } else {
            $counts[$id] = (int) ($product['StockQty'] ?? 0);
        }
    }

    if ($serialized) {
        $params = [];
        $holders = [];
        foreach (array_values($serialized) as $i => $id) {
            $holders[] = ':pid' . $i;
            $params[':pid' . $i] = $id;
        }

        $rows = db_fetch_all(
            'SELECT ProductID, COUNT(*) AS n
             FROM product_instance
             WHERE Status = \'In Stock\' AND ProductID IN (' . implode(', ', $holders) . ')
             GROUP BY ProductID',
            $params
        );
        foreach ($rows as $row) {
            $counts[(int) $row['ProductID']] = (int) $row['n'];
        }
        // A serialized product with no instances at all is simply out of stock.
        foreach ($serialized as $id) {
            $counts[$id] ??= 0;
        }
    }

    return $counts;
}

/**
 * The storefront's wording for a product's stock, given its units on hand.
 * Separate from the query so every page and endpoint says the same thing.
 */
function stock_label(array $product, int $available): string
{
    if (!empty($product['IsSerialized'])) {
        return $available > 0 ? $available . ' in stock' : 'Made to order';
    }

    if ($available > 10) return 'In stock';
    if ($available > 0)  return 'Only ' . $available . ' left';

    return 'Out of stock';
}

/**
 * Human stock line for a single product (the storefront cards use this).
 */
function stock_line(array $product): string
{
    $counts = stock_counts([$product]);

    return stock_label($product, $counts[(int) ($product['ProductID'] ?? 0)] ?? 0);
}

/**
 * Products matching a free-text query and/or a category, shaped exactly like
 * get_featured_products() so the same card partial renders both.
 *
 * Used by index.php for storefront search and by api/products.php for the
 * live search suggestions.
 */
function search_products(string $query = '', int $categoryId = 0, int $limit = 24): array
{
    $query = trim($query);
    $limit = max(1, min(60, $limit));

    $where  = [];
    $params = [];

    if ($query !== '') {
        // Escape LIKE's own wildcards so "100%" doesn't match everything.
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query) . '%';
        $where[] = '(p.ProductName LIKE :qName OR p.Brand LIKE :qBrand
                     OR p.Model LIKE :qModel OR c.CategoryName LIKE :qCat)';
        $params[':qName']  = $like;
        $params[':qBrand'] = $like;
        $params[':qModel'] = $like;
        $params[':qCat']   = $like;
    }

    if ($categoryId > 0) {
        $where[]           = 'p.CategoryID = :cid';
        $params[':cid']    = $categoryId;
    }

    $rows = db_fetch_all(
        'SELECT p.ProductID, p.ProductName, p.Brand, p.Model, p.StandardPrice,
                p.IsSerialized, p.StockQty, p.DefaultWarrantyMonths, c.CategoryName
         FROM product p
         JOIN category c ON c.CategoryID = p.CategoryID
         ' . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . '
         ORDER BY c.CategoryID, p.ProductName
         LIMIT ' . $limit,
        $params
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

    return $products;
}

/**
 * One product with its category and vendor, shaped like get_featured_products()
 * plus VendorID / VendorName / VendorLocation / VendorPhone.
 * Returns null when the id does not exist (product.php then shows a friendly
 * "not found" panel instead of an error).
 */
function get_product(int $productId): ?array
{
    if ($productId <= 0) {
        return null;
    }

    $rows = db_fetch_all(
        'SELECT p.ProductID, p.ProductName, p.Brand, p.Model, p.StandardPrice,
                p.IsSerialized, p.StockQty, p.DefaultWarrantyMonths,
                p.CategoryID, c.CategoryName,
                v.VendorID, v.VendorName, v.Location AS VendorLocation,
                v.ContactPhone AS VendorPhone
         FROM product p
         JOIN category c ON c.CategoryID = p.CategoryID
         JOIN vendor   v ON v.VendorID   = p.VendorID
         WHERE p.ProductID = :id',
        [':id' => $productId]
    );

    if (!$rows) {
        return null;
    }

    return array_merge($rows[0], product_meta()[$productId] ?? [
        'image' => null,
        'alt'   => $rows[0]['ProductName'],
        'badge' => null,
        'blurb' => '',
        'sale'  => null,
    ]);
}

/**
 * Other products in the same category, for the "Related products" strip on the
 * product page. Same shape as get_featured_products().
 */
function related_products(int $productId, int $categoryId, int $limit = 3): array
{
    $limit = max(1, min(12, $limit));
    if ($categoryId <= 0) {
        return [];
    }

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
