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
 * Per-product presentation extras, keyed by ProductID: a corner badge,
 * hand-written copy and a "was" price for the deals pages.
 *
 * The database stays the single source of truth for what exists, what it costs
 * and how many are on hand; this map only decorates what it returns. A product
 * with no entry here still renders: product_presentation() gives it a photo
 * from product_images() and a description built from the row itself.
 */
function product_meta(): array
{
    return [
        1 => [
            'badge' => 'Hot',
            'blurb' => 'HP ProBook 450 G10 business notebook (Core i5, 16 GB) — 24-month warranty tracked per serial number.',
            'sale'  => 108000.00,
        ],
        2 => [
            'badge' => 'Deal',
            'blurb' => 'Dell UltraSharp 4K IPS panel (U2723QE) with USB-C docking and factory colour calibration — 36-month warranty.',
            'sale'  => 138000.00,
        ],
        3 => [
            'badge' => 'Hot',
            'blurb' => 'Cisco CBS350-24T 24-port managed gigabit switch — 36-month manufacturer warranty tracked per serial number.',
            'sale'  => null,
        ],
        4 => [
            'badge' => 'Deal',
            'blurb' => 'Logitech MX Master 3S wireless mouse — quiet clicks, 8,000 DPI tracking and multi-device switching.',
            'sale'  => 11000.00,
        ],
        // The rest of the range is merchandised from the same map: a badge puts
        // the item in the "new arrivals" spotlight, a sale price puts it on the
        // deals pages. Anything listed here without a photo falls back to the
        // photo for its category.
        5  => ['badge' => 'Deal', 'sale' => 68000.00],
        6  => ['sale' => 74500.00],
        7  => ['badge' => 'New'],
        8  => ['sale' => 76000.00],
        10 => ['badge' => 'Hot', 'sale' => 110000.00],
        13 => ['badge' => 'New'],
        14 => ['badge' => 'Deal', 'sale' => 104000.00],
        19 => ['sale' => 16000.00],
        21 => ['badge' => 'New'],
        22 => ['sale' => 12000.00],
        27 => ['badge' => 'Deal', 'sale' => 3900.00],
        31 => ['sale' => 20500.00],
        42 => ['sale' => 5200.00],
        45 => ['badge' => 'New'],
        46 => ['badge' => 'Deal', 'sale' => 25500.00],
        56 => ['badge' => 'New'],
    ];
}

/**
 * The photo a product falls back to when product_images() names none: its
 * category, with a couple of name-level exceptions so a battery backup is not
 * illustrated with a mouse.
 */
function category_image(string $categoryName, string $productName = ''): string
{
    $byName = [
        'UPS'      => 'images/products/accessories/walton-600va-ups.jpg',
        'AVR'      => 'images/products/accessories/walton-600va-ups.jpg',
        'Camera'   => 'images/products/accessories/hikvision-2mp-dome-camera-ds-2ce56d0t.jpg',
        'Keyboard' => 'images/products/accessories/logitech-k380-multi-device-keyboard.jpg',
        'Headset'  => 'images/products/accessories/logitech-h340-usb-headset.jpg',
        'Webcam'   => 'images/products/accessories/logitech-c270-hd-webcam.jpg',
        'Hub'      => 'images/products/accessories/anker-5-in-1-usb-c-hub-a8346.jpg',
        'Cable'    => 'images/products/accessories/ugreen-usb-c-to-hdmi-cable-1-5m-cm315.jpg',
    ];
    foreach ($byName as $needle => $image) {
        if ($productName !== '' && mb_stripos($productName, $needle) !== false) {
            return $image;
        }
    }

    $byCategory = [
        'Laptops & Computers'  => 'images/products/laptops/hp-probook-450-g10.jpg',
        'Monitors & Displays'  => 'images/products/monitors/dell-ultrasharp-27-4k-monitor-u2723qe.jpg',
        'Networking Equipment' => 'images/products/networking/tp-link-archer-c6-ac1200-router.jpg',
        'Accessories'          => 'images/products/accessories/logitech-mx-master-3s-wireless-mouse.jpg',
        'Printers & Scanners'  => 'images/products/printers/hp-laserjet-pro-m404dn-printer.jpg',
        'Storage & Components' => 'images/products/storage/samsung-980-500gb-nvme-ssd-mz-v8v500.jpg',
    ];

    return $byCategory[$categoryName] ?? '';
}

/**
 * A one-line description for a product that has no hand-written copy: what it
 * is for, plus the warranty the shop records against it. Built from the row so
 * every catalog item — including ones added in the back office — reads as a
 * real listing instead of a bare name.
 */
function product_blurb(array $row): string
{
    $lead = trim(implode(' ', array_filter([
        trim((string) ($row['Brand'] ?? '')),
        trim((string) ($row['ProductName'] ?? '')),
    ])));
    if ($lead === '') {
        return '';
    }

    // A device-level phrase where the name says what the thing is, so a UPS is
    // not described as a desk accessory and an SSD is not a printer part.
    $byName = [
        'UPS'            => 'battery backup for desks and small server rooms',
        'AVR'            => 'voltage regulator that protects equipment from surges',
        'Camera'         => 'security camera for shop and office coverage',
        'SSD'            => 'solid-state drive for faster boot and loading times',
        'HDD'            => 'hard disk for bulk storage and backups',
        'DDR'            => 'memory module for upgrading a desktop or laptop',
        'Router'         => 'router for home and small-office networks',
        'Switch'         => 'network switch for wired office networks',
        'Range Extender' => 'range extender that clears Wi-Fi dead spots',
        'Access Point'   => 'ceiling access point for wider office Wi-Fi coverage',
        'Webcam'         => 'webcam for meetings and online classes',
        'Headset'        => 'headset for calls and online meetings',
        'Keyboard'       => 'keyboard for everyday office work',
        'Mouse'          => 'mouse for everyday office work',
        'Hub'            => 'USB-C hub that adds the ports a laptop lacks',
        'Cable'          => 'cable for connecting a display or dock',
        'Printer'        => 'office printer with consumables available locally',
        'All-in-One'     => 'print, scan and copy in one desktop unit',
        'Monitor'        => 'desktop display for office and home work',
    ];

    $name = (string) ($row['ProductName'] ?? '');
    $use  = '';
    foreach ($byName as $needle => $phrase) {
        if (mb_stripos($name, $needle) !== false) {
            $use = $phrase;
            break;
        }
    }

    if ($use === '') {
        $use = match ((string) ($row['CategoryName'] ?? '')) {
            'Laptops & Computers'  => 'business notebook for office and home use',
            'Monitors & Displays'  => 'desktop display for office and home work',
            'Networking Equipment' => 'networking gear for offices and small businesses',
            'Accessories'          => 'everyday accessory that ships from stock today',
            'Printers & Scanners'  => 'office printer with consumables available locally',
            'Storage & Components' => 'upgrade component, fitted by our service desk on request',
            default                => 'item from the SebaBD catalogue',
        };
    }

    $months  = (int) ($row['DefaultWarrantyMonths'] ?? 0);
    // "an 8-month", "an 11-month", "an 18-month" — the vowel sound the number
    // starts with decides the article.
    $article = preg_match('/^(8|11|18)$/', (string) $months) === 1 ? 'an' : 'a';
    $warranty = $months > 0
        ? ' Backed by ' . $article . ' ' . $months . '-month warranty tracked against the item you buy.'
        : '';

    return $lead . ' — ' . $use . '.' . $warranty;
}

/**
 * The photo of every catalogue item, keyed by ProductID.
 *
 * Files sit in one folder per category — laptops/, monitors/, networking/,
 * accessories/, printers/, storage/ — and are named after the product itself
 * (brand plus model), e.g. images/products/laptops/hp-probook-450-g10.jpg.
 * Every file is a 900×900 JPEG on a white canvas, so cards, the detail page
 * and the cart thumbnails all crop the same way and no photo looks out of
 * place next to its neighbours.
 *
 * A product created in the back office has no entry here until someone adds a
 * photo for it; the storefront then falls back to its category photo rather
 * than showing a broken image. To change a picture, drop the replacement in
 * the product's category folder and point this map at it.
 */
function product_images(): array
{
    return [
        1 => ['image' => 'images/products/laptops/hp-probook-450-g10.jpg', 'alt' => 'HP ProBook 450 G10'],
        2 => ['image' => 'images/products/monitors/dell-ultrasharp-27-4k-monitor-u2723qe.jpg', 'alt' => 'Dell UltraSharp 27" 4K Monitor (U2723QE)'],
        3 => ['image' => 'images/products/networking/cisco-cbs350-24t-24-port-managed-switch.jpg', 'alt' => 'Cisco CBS350-24T 24-Port Managed Switch'],
        4 => ['image' => 'images/products/accessories/logitech-mx-master-3s-wireless-mouse.jpg', 'alt' => 'Logitech MX Master 3S Wireless Mouse'],
        5 => ['image' => 'images/products/laptops/hp-250-g9.jpg', 'alt' => 'HP 250 G9'],
        6 => ['image' => 'images/products/laptops/dell-inspiron-15-3530.jpg', 'alt' => 'Dell Inspiron 15 3530'],
        7 => ['image' => 'images/products/laptops/lenovo-ideapad-slim-3-15irh10.jpg', 'alt' => 'Lenovo IdeaPad Slim 3 (15IRH10)'],
        8 => ['image' => 'images/products/laptops/asus-vivobook-15-x1504za.jpg', 'alt' => 'Asus VivoBook 15 (X1504ZA)'],
        9 => ['image' => 'images/products/laptops/acer-aspire-5-a515-56g.jpg', 'alt' => 'Acer Aspire 5 (A515-56G)'],
        10 => ['image' => 'images/products/laptops/apple-macbook-air-m2-13-m2-8gb-256gb.jpg', 'alt' => 'Apple MacBook Air M2 13" (M2 8GB/256GB)'],
        11 => ['image' => 'images/products/laptops/hp-15s-fd0841tu.jpg', 'alt' => 'HP 15s fd0841TU (15s-fd0841TU)'],
        12 => ['image' => 'images/products/laptops/dell-vostro-3520.jpg', 'alt' => 'Dell Vostro 3520'],
        13 => ['image' => 'images/products/laptops/lenovo-thinkpad-e14-gen-5.jpg', 'alt' => 'Lenovo ThinkPad E14 Gen 5'],
        14 => ['image' => 'images/products/laptops/asus-tuf-gaming-f15-fx506hf.jpg', 'alt' => 'Asus TUF Gaming F15 (FX506HF)'],
        15 => ['image' => 'images/products/laptops/hp-elitebook-640-g9.jpg', 'alt' => 'HP EliteBook 640 G9'],
        16 => ['image' => 'images/products/laptops/dell-latitude-3440.jpg', 'alt' => 'Dell Latitude 3440'],
        17 => ['image' => 'images/products/laptops/lenovo-v15-g4-iru.jpg', 'alt' => 'Lenovo V15 G4 IRU'],
        18 => ['image' => 'images/products/monitors/dell-s2421hn-24-ips-monitor.jpg', 'alt' => 'Dell S2421HN 24" IPS Monitor'],
        19 => ['image' => 'images/products/monitors/hp-m22f-21-5-fhd-monitor.jpg', 'alt' => 'HP M22f 21.5" FHD Monitor'],
        20 => ['image' => 'images/products/monitors/samsung-ls24c310-24-fhd-monitor.jpg', 'alt' => 'Samsung LS24C310 24" FHD Monitor'],
        21 => ['image' => 'images/products/monitors/lg-27up600-w-27-4k-monitor.jpg', 'alt' => 'LG 27UP600-W 27" 4K Monitor'],
        22 => ['image' => 'images/products/monitors/xiaomi-redmi-rmmnt215nf-monitor.jpg', 'alt' => 'Xiaomi Redmi RMMNT215NF Monitor'],
        23 => ['image' => 'images/products/monitors/benq-gw2480-24-ips-monitor.jpg', 'alt' => 'BenQ GW2480 24" IPS Monitor'],
        24 => ['image' => 'images/products/monitors/asus-va24ehe-24-fhd-monitor.jpg', 'alt' => 'Asus VA24EHE 24" FHD Monitor'],
        25 => ['image' => 'images/products/monitors/hp-v22v-21-5-monitor.jpg', 'alt' => 'HP V22v 21.5" Monitor'],
        26 => ['image' => 'images/products/networking/cisco-cbs350-8t-8-port-managed-switch.jpg', 'alt' => 'Cisco CBS350-8T 8-Port Managed Switch'],
        27 => ['image' => 'images/products/networking/tp-link-archer-c6-ac1200-router.jpg', 'alt' => 'TP-Link Archer C6 AC1200 Router'],
        28 => ['image' => 'images/products/networking/tp-link-tl-sg108-8-port-gigabit-switch.jpg', 'alt' => 'TP-Link TL-SG108 8-Port Gigabit Switch'],
        29 => ['image' => 'images/products/networking/d-link-dir-825-ac1200-router.jpg', 'alt' => 'D-Link DIR-825 AC1200 Router'],
        30 => ['image' => 'images/products/networking/mikrotik-hex-s-rb760igs-router.jpg', 'alt' => 'MikroTik hEX S RB760iGS Router'],
        31 => ['image' => 'images/products/networking/ubiquiti-unifi-u6-access-point.jpg', 'alt' => 'Ubiquiti UniFi U6+ Access Point'],
        32 => ['image' => 'images/products/networking/tp-link-tl-wa855re-range-extender.jpg', 'alt' => 'TP-Link TL-WA855RE Range Extender'],
        33 => ['image' => 'images/products/networking/netgear-gs308-8-port-gigabit-switch.jpg', 'alt' => 'Netgear GS308 8-Port Gigabit Switch'],
        34 => ['image' => 'images/products/networking/tp-link-omada-eap225-access-point.jpg', 'alt' => 'TP-Link Omada EAP225 Access Point'],
        35 => ['image' => 'images/products/accessories/logitech-k380-multi-device-keyboard.jpg', 'alt' => 'Logitech K380 Multi-Device Keyboard'],
        36 => ['image' => 'images/products/accessories/a4tech-op-620d-wired-mouse.jpg', 'alt' => 'A4Tech OP-620D Wired Mouse'],
        37 => ['image' => 'images/products/accessories/dell-kb216-wired-keyboard.jpg', 'alt' => 'Dell KB216 Wired Keyboard'],
        38 => ['image' => 'images/products/accessories/anker-5-in-1-usb-c-hub-a8346.jpg', 'alt' => 'Anker 5-in-1 USB-C Hub (A8346)'],
        39 => ['image' => 'images/products/accessories/ugreen-usb-c-to-hdmi-cable-1-5m-cm315.jpg', 'alt' => 'UGREEN USB-C to HDMI Cable 1.5m (CM315)'],
        40 => ['image' => 'images/products/accessories/logitech-c270-hd-webcam.jpg', 'alt' => 'Logitech C270 HD Webcam'],
        41 => ['image' => 'images/products/accessories/logitech-h340-usb-headset.jpg', 'alt' => 'Logitech H340 USB Headset'],
        42 => ['image' => 'images/products/accessories/walton-600va-ups.jpg', 'alt' => 'Walton 600VA UPS'],
        43 => ['image' => 'images/products/accessories/hikvision-2mp-dome-camera-ds-2ce56d0t.jpg', 'alt' => 'Hikvision 2MP Dome Camera (DS-2CE56D0T)'],
        45 => ['image' => 'images/products/printers/hp-laserjet-pro-m404dn-printer.jpg', 'alt' => 'HP LaserJet Pro M404dn Printer'],
        46 => ['image' => 'images/products/printers/epson-ecotank-l3250-all-in-one.jpg', 'alt' => 'Epson EcoTank L3250 All-in-One'],
        47 => ['image' => 'images/products/printers/canon-pixma-g2010-all-in-one.jpg', 'alt' => 'Canon PIXMA G2010 All-in-One'],
        48 => ['image' => 'images/products/printers/hp-laserjet-m111a-printer.jpg', 'alt' => 'HP LaserJet M111a Printer'],
        49 => ['image' => 'images/products/printers/epson-ecotank-l3210-all-in-one.jpg', 'alt' => 'Epson EcoTank L3210 All-in-One'],
        50 => ['image' => 'images/products/printers/canon-pixma-g3010-all-in-one.jpg', 'alt' => 'Canon PIXMA G3010 All-in-One'],
        51 => ['image' => 'images/products/storage/samsung-980-500gb-nvme-ssd-mz-v8v500.jpg', 'alt' => 'Samsung 980 500GB NVMe SSD (MZ-V8V500)'],
        52 => ['image' => 'images/products/storage/kingston-a400-480gb-sata-ssd-sa400s37.jpg', 'alt' => 'Kingston A400 480GB SATA SSD (SA400S37)'],
        53 => ['image' => 'images/products/storage/seagate-barracuda-1tb-hdd-st1000dm010.jpg', 'alt' => 'Seagate Barracuda 1TB HDD (ST1000DM010)'],
        54 => ['image' => 'images/products/storage/wd-green-240gb-sata-ssd-wds240g3g0a.jpg', 'alt' => 'WD Green 240GB SATA SSD (WDS240G3G0A)'],
        55 => ['image' => 'images/products/storage/kingston-fury-8gb-ddr4-3200mhz-kf432c16bb-8.jpg', 'alt' => 'Kingston FURY 8GB DDR4 3200MHz (KF432C16BB/8)'],
        56 => ['image' => 'images/products/storage/samsung-980-pro-1tb-nvme-ssd-mz-v8p1t0.jpg', 'alt' => 'Samsung 980 PRO 1TB NVMe SSD (MZ-V8P1T0)'],
        57 => ['image' => 'images/products/storage/seagate-barracuda-2tb-hdd-st2000dm008.jpg', 'alt' => 'Seagate Barracuda 2TB HDD (ST2000DM008)'],
    ];
}

/**
 * The row a storefront card renders from: the database columns, the
 * hand-written extras from product_meta(), and the derived photo/description
 * for anything left over.
 */
function product_presentation(array $row): array
{
    $id   = (int) ($row['ProductID'] ?? 0);
    $name = (string) ($row['ProductName'] ?? 'Product');

    return array_merge(
        [
            'image' => category_image((string) ($row['CategoryName'] ?? ''), $name),
            'alt'   => trim((string) ($row['Brand'] ?? '') . ' ' . $name),
            'badge' => null,
            'blurb' => product_blurb($row),
            'sale'  => null,
        ],
        $row,
        product_images()[$id] ?? [],
        product_meta()[$id] ?? []
    );
}

/**
 * Products the homepage leads with — a curated cross-section of the range
 * rather than "whatever was entered first". Ids are listed in display order;
 * anything missing from the database is skipped.
 */
function featured_product_ids(): array
{
    return [1, 2, 3, 4, 6, 10, 21, 27, 35, 46, 51, 56];
}

/**
 * The "was" price of a product, or null when it is not on offer.
 */
function sale_price(array $product): ?float
{
    $sale = $product['sale'] ?? null;
    if ($sale === null || $sale === '') {
        return null;
    }

    $was = (float) $sale;

    return $was > (float) ($product['StandardPrice'] ?? 0) ? $was : null;
}

/**
 * Discount percentage of an item on offer, rounded to one decimal.
 */
function discount_percent(array $product): float
{
    $was = sale_price($product);
    if ($was === null) {
        return 0.0;
    }

    return round(($was - (float) $product['StandardPrice']) / $was * 100, 1);
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

    return $n > 0 ? $n : count(offline_catalog());
}

/**
 * Featured products for the storefront grid: one representative product per
 * category (lowest ProductID in each), so the homepage shows the full range.
 * Reads live data from the DB and merges in the presentation metadata; falls
 * back to the offline catalogue when the database is unreachable.
 */
function get_featured_products(int $limit = 12): array
{
    $limit = max(1, min(50, $limit));
    $ids   = array_slice(featured_product_ids(), 0, $limit);
    $rows  = [];

    if ($ids) {
        $holders = implode(',', array_fill(0, count($ids), '?'));
        $rows    = db_fetch_all(
            "SELECT p.ProductID, p.ProductName, p.Brand, p.Model, p.StandardPrice,
                    p.IsSerialized, p.StockQty, p.DefaultWarrantyMonths,
                    c.CategoryName
             FROM product p
             JOIN category c ON c.CategoryID = p.CategoryID
             WHERE p.ProductID IN ($holders)",
            $ids
        );
    }

    $byId = [];
    foreach ($rows as $row) {
        $byId[(int) $row['ProductID']] = product_presentation($row);
    }

    // Keep the curated order, and skip anything the database does not have.
    $products = [];
    foreach ($ids as $id) {
        if (isset($byId[$id])) {
            $products[] = $byId[$id];
        }
    }

    if ($products) {
        return array_slice($products, 0, $limit);
    }

    // Offline fallback: the curated items straight from the seed values.
    return array_slice(offline_catalog(), 0, $limit);
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
        $products[] = product_presentation($row);
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
        $products[] = product_presentation($row);
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

    return product_presentation($row);
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
        $products[] = product_presentation($row);
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

/* ------------------------------------------------------- shop / catalog
 *
 * The storefront pages read the catalog through the functions below: shop.php
 * (filters, sorting, paging), categories.php (the category index) and deals.php
 * (everything on offer). They all go through product_presentation(), so a card
 * looks the same wherever it appears.
 */

/**
 * Normalised filter arguments from a query string: search text, category,
 * brand, sort key and page number. Parsed in one place so every page agrees on
 * what ?q=&category=&brand=&sort=&page= means.
 */
function catalog_filters(array $input): array
{
    $sorts = ['featured', 'newest', 'price_asc', 'price_desc', 'name'];
    $sort  = (string) ($input['sort'] ?? 'featured');

    return [
        'q'        => trim((string) ($input['q'] ?? '')),
        'category' => max(0, (int) ($input['category'] ?? 0)),
        'brand'    => trim((string) ($input['brand'] ?? '')),
        'sort'     => in_array($sort, $sorts, true) ? $sort : 'featured',
        'page'     => max(1, (int) ($input['page'] ?? 1)),
        'per_page' => 12,
    ];
}

/**
 * WHERE clause plus bound parameters shared by the count and the page query.
 * One placeholder per column: the connection runs native prepares, which reject
 * a statement that names the same placeholder twice (SQLSTATE HY093).
 */
function catalog_where(array $filters): array
{
    $where  = [];
    $params = [];

    if ($filters['q'] !== '') {
        $like          = '%' . $filters['q'] . '%';
        $where[]       = '(p.ProductName LIKE :q1 OR p.Brand LIKE :q2 OR p.Model LIKE :q3)';
        $params[':q1'] = $like;
        $params[':q2'] = $like;
        $params[':q3'] = $like;
    }
    if ($filters['category'] > 0) {
        $where[]         = 'p.CategoryID = :cid';
        $params[':cid']  = $filters['category'];
    }
    if ($filters['brand'] !== '') {
        $where[]          = 'p.Brand = :brand';
        $params[':brand'] = $filters['brand'];
    }

    return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
}

/**
 * ORDER BY for a sort key — a whitelist match, never the raw request value.
 */
function catalog_order_by(string $sort): string
{
    // "Featured" is the default shop order: the homepage's curated picks first,
    // then everything else newest-first. The ranks come from that helper, so the
    // two pages always agree on what the shop leads with.
    if ($sort === 'featured') {
        $ids  = featured_product_ids();
        $case = 'CASE p.ProductID';
        foreach ($ids as $rank => $id) {
            $case .= ' WHEN ' . (int) $id . ' THEN ' . (int) $rank;
        }
        $case .= ' ELSE ' . count($ids) . ' END';

        return $case . ', p.ProductID DESC';
    }

    return match ($sort) {
        'price_asc'  => 'p.StandardPrice ASC, p.ProductName ASC',
        'price_desc' => 'p.StandardPrice DESC, p.ProductName ASC',
        'name'       => 'p.ProductName ASC',
        default      => 'p.ProductID DESC',
    };
}

/**
 * One page of the catalog, with presentation data merged in.
 */
function catalog_products(array $filters): array
{
    [$where, $params] = catalog_where($filters);
    $limit  = max(1, min(48, (int) $filters['per_page']));
    $offset = max(0, ((int) $filters['page'] - 1) * $limit);

    $rows = db_fetch_all(
        "SELECT p.ProductID, p.ProductName, p.Brand, p.Model, p.StandardPrice,
                p.IsSerialized, p.StockQty, p.DefaultWarrantyMonths,
                c.CategoryName
         FROM product p
         JOIN category c ON c.CategoryID = p.CategoryID
         $where
         ORDER BY " . catalog_order_by((string) $filters['sort']) . "
         LIMIT $limit OFFSET $offset",
        $params
    );

    $products = [];
    foreach ($rows as $row) {
        $products[] = product_presentation($row);
    }

    return $products;
}

/**
 * How many products the filters match in total, so shop.php can page.
 */
function catalog_total(array $filters): int
{
    [$where, $params] = catalog_where($filters);
    $rows = db_fetch_all(
        "SELECT COUNT(*) AS n
         FROM product p
         JOIN category c ON c.CategoryID = p.CategoryID
         $where",
        $params
    );

    return (int) ($rows[0]['n'] ?? 0);
}

/**
 * Distinct brands with product counts, for the shop sidebar.
 */
function catalog_brands(): array
{
    return db_fetch_all(
        "SELECT Brand, COUNT(*) AS Products
         FROM product
         WHERE Brand IS NOT NULL AND Brand <> ''
         GROUP BY Brand
         ORDER BY Brand"
    );
}

/**
 * Category rows with a product count and an entry price, for categories.php.
 */
function category_overview(): array
{
    $rows = db_fetch_all(
        'SELECT c.CategoryID, c.CategoryName, COUNT(p.ProductID) AS Products,
                MIN(p.StandardPrice) AS FromPrice
         FROM category c
         LEFT JOIN product p ON p.CategoryID = c.CategoryID
         GROUP BY c.CategoryID, c.CategoryName
         ORDER BY c.CategoryID'
    );

    if ($rows) {
        return $rows;
    }

    // Offline: the category list without counts.
    $fallback = [];
    foreach (get_categories() as $category) {
        $fallback[] = $category + ['Products' => 0, 'FromPrice' => null];
    }

    return $fallback;
}

/**
 * Products on offer, biggest saving first — the deals pages.
 *
 * The "was" price is storefront merchandising, not a database column, so the
 * shortlist is filtered and sorted here rather than in SQL. The catalog is
 * small enough that reading it whole costs nothing; a bigger range would want
 * a SalePrice column and a query-level filter.
 */
function deal_products(int $limit = 24): array
{
    $rows = db_fetch_all(
        "SELECT p.ProductID, p.ProductName, p.Brand, p.Model, p.StandardPrice,
                p.IsSerialized, p.StockQty, p.DefaultWarrantyMonths,
                c.CategoryName
         FROM product p
         JOIN category c ON c.CategoryID = p.CategoryID
         ORDER BY p.ProductID"
    );

    $deals = [];
    foreach ($rows as $row) {
        $product = product_presentation($row);
        if (sale_price($product) !== null) {
            $deals[] = $product;
        }
    }

    usort($deals, fn(array $a, array $b) => discount_percent($b) <=> discount_percent($a));

    if (!$deals) {
        // Offline: whatever the seed's presentation map marks as on offer.
        foreach (offline_catalog() as $product) {
            if (sale_price($product) !== null) {
                $deals[] = $product;
            }
        }
        usort($deals, fn(array $a, array $b) => discount_percent($b) <=> discount_percent($a));
    }

    return array_slice($deals, 0, max(1, $limit));
}

/**
 * shop.php URL for a filter set, with overrides applied — the link builder
 * behind the sort control, the brand/category facets and the pager. Defaults
 * are dropped so the URL stays readable and shareable.
 */
function shop_url(array $filters, array $overrides = []): string
{
    $merged = array_merge([
        'q'        => $filters['q'] ?? '',
        'category' => $filters['category'] ?? 0,
        'brand'    => $filters['brand'] ?? '',
        'sort'     => $filters['sort'] ?? 'featured',
        'page'     => $filters['page'] ?? 1,
    ], $overrides);

    $query = [];
    foreach ($merged as $key => $value) {
        if ($key === 'page' && (int) $value <= 1) {
            continue;
        }
        if ($key === 'sort' && $value === 'featured') {
            continue;
        }
        if ($value === '' || $value === 0 || $value === null) {
            continue;
        }
        $query[$key] = $value;
    }

    return 'shop.php' . ($query ? '?' . http_build_query($query) : '');
}
