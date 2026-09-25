<?php
/**
 * SebaBD — catalog data layer.
 *
 * Storefront presentation data (images, marketing copy, deal badges) is NOT
 * part of the DBMS schema, so it lives here keyed by ProductID. The database
 * remains the single source of truth for products, categories, stock and
 * prices; this file only decorates what the database returns.
 *
 * If MySQL is unreachable, these arrays double as a demo fallback so the
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
            'alt'   => 'HP ProBook Enterprise 15 business laptop',
            'badge' => 'Hot',
            'blurb' => 'HP business notebook (PB-15-2024) supplied as a serialized unit — 24-month warranty tracked per device.',
            'sale'  => 1320.00,
        ],
        2 => [
            'image' => 'images/products/monitor.jpg',
            'alt'   => 'Dell UltraSharp 27-inch 4K monitor',
            'badge' => 'Deal',
            'blurb' => 'Dell UltraSharp 4K IPS panel (U2723QE) with USB-C docking and factory colour calibration — 12-month warranty.',
            'sale'  => 620.00,
        ],
        3 => [
            'image' => 'images/products/switch.jpg',
            'alt'   => 'Cisco 24-port managed network switch',
            'badge' => 'New',
            'blurb' => 'Cisco CBS350 24-port managed gigabit switch — serialized stock with a 36-month manufacturer warranty.',
            'sale'  => null,
        ],
        4 => [
            'image' => 'images/products/mouse.png',
            'alt'   => 'Logitech MX Master 3S wireless mouse',
            'badge' => 'Deal',
            'blurb' => 'Logitech MX Master 3S wireless mouse — quiet clicks, 8,000 DPI tracking and multi-device switching.',
            'sale'  => 119.99,
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
 * back to a demo catalog when the database is unreachable.
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
        [1, 'ProBook Enterprise 15',              'HP',       'Laptops & Computers',  '1200.00', 1, 0],
        [2, 'UltraSharp 27" 4K Monitor',          'Dell',     'Monitors & Displays',   '550.00', 0, 45],
        [3, 'Enterprise Managed Switch 24-Port',  'Cisco',    'Networking Equipment',  '850.00', 1, 0],
        [4, 'Ergonomic Wireless Mouse',           'Logitech', 'Accessories',            '99.99', 0, 120],
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
 * Human stock line: serialized products track stock via product_instance.
 */
function stock_line(array $product): string
{
    if (!empty($product['IsSerialized'])) {
        $inStock = db_fetch_all(
            "SELECT COUNT(*) AS n
             FROM product_instance
             WHERE ProductID = :pid AND Status = 'In Stock'",
            [':pid' => (int) $product['ProductID']]
        );
        $n = (int) ($inStock[0]['n'] ?? 0);
        return $n > 0 ? $n . ' in stock' : 'Made to order';
    }

    $qty = (int) ($product['StockQty'] ?? 0);
    if ($qty > 10) return 'In stock';
    if ($qty > 0)  return 'Only ' . $qty . ' left';
    return 'Out of stock';
}
