<?php
/**
 * SebaBD — the shopping cart, kept in the session.
 *
 * A cart is a list of product id => quantity. Nothing is written to the
 * database until the visitor checks out, so a guest can build a basket before
 * signing in, and an abandoned cart costs the store nothing.
 *
 * Only the product id and the quantity are stored; every price, name and stock
 * figure is read fresh from the catalog each time the cart is shown. That is
 * deliberate — a price edited in the back office must apply to the cart the
 * moment the page is reloaded, and the stock warning has to reflect what is
 * actually on the shelf.
 *
 * The storefront header loads this file for its cart badge, so it must stay
 * quiet: no output, no side effects beyond the session.
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/catalog-data.php';

/** The largest quantity a single line may hold. */
const CART_MAX_QTY = 999;

function cart_init(): void
{
    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }
}

/** product id => quantity, cleaned of anything that is no longer a product. */
function cart_items(): array
{
    cart_init();

    $items = [];
    foreach ($_SESSION['cart'] as $productId => $qty) {
        $productId = (int) $productId;
        $qty       = (int) $qty;
        if ($productId > 0 && $qty > 0) {
            $items[$productId] = min(CART_MAX_QTY, $qty);
        }
    }

    return $items;
}

/** How many units are in the cart — shown on the header basket badge. */
function cart_count(): int
{
    return array_sum(cart_items());
}

function cart_add(int $productId, int $qty = 1): void
{
    cart_init();
    if ($productId <= 0 || $qty <= 0) {
        return;
    }

    $items = cart_items();
    $items[$productId] = min(CART_MAX_QTY, ($items[$productId] ?? 0) + $qty);
    $_SESSION['cart'] = $items;
}

/** Set an absolute quantity; 0 removes the line. */
function cart_set(int $productId, int $qty): void
{
    cart_init();
    $items = cart_items();

    if ($qty <= 0) {
        unset($items[$productId]);
    } else {
        $items[$productId] = min(CART_MAX_QTY, $qty);
    }

    $_SESSION['cart'] = $items;
}

function cart_remove(int $productId): void
{
    cart_set($productId, 0);
}

function cart_clear(): void
{
    $_SESSION['cart'] = [];
}

/**
 * The cart as display rows: catalog fields, quantity, line total and the live
 * stock position for that line.
 *
 * @return array{lines: array<int, array>, missing: int[]}
 *         lines  shaped like get_product() plus Quantity / LineTotal / Available / LineState
 *         missing  product ids that vanished from the catalog while in the cart
 */
function cart_lines(): array
{
    $items = cart_items();
    if (!$items) {
        return ['lines' => [], 'missing' => []];
    }

    $ids   = array_keys($items);
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $rows  = db_fetch_all(
        'SELECT p.ProductID, p.ProductName, p.Brand, p.Model, p.StandardPrice, p.IsSerialized,
                p.StockQty, p.DefaultWarrantyMonths, p.CategoryID, c.CategoryName,
                v.VendorName, v.Location AS VendorLocation
         FROM product p
         JOIN category c ON c.CategoryID = p.CategoryID
         JOIN vendor   v ON v.VendorID   = p.VendorID
         WHERE p.ProductID IN (' . $marks . ')
         ORDER BY c.CategoryName, p.ProductName',
        array_values($ids)
    );

    $counts  = stock_counts($rows);
    $lines   = [];
    $found   = [];

    foreach ($rows as $row) {
        $productId = (int) $row['ProductID'];
        $qty       = $items[$productId];
        $available = $counts[$productId] ?? 0;

        // Same presentation metadata the storefront cards use (photo, badge,
        // blurb), so a product looks identical in the basket and the grid.
        $row = product_presentation($row);

        $row['Quantity']  = $qty;
        $row['LineTotal'] = $qty * (float) $row['StandardPrice'];
        $row['Available'] = $available;
        // ok / low / backorder — the wording the cart prints beside the line.
        $row['LineState'] = $available <= 0 ? 'backorder' : ($available < $qty ? 'low' : 'ok');

        $lines[] = $row;
        $found[] = $productId;
    }

    return ['lines' => $lines, 'missing' => array_values(array_diff($ids, $found))];
}

/**
 * Totals for a set of cart lines.
 *
 * @return array{units:int, lines:int, subtotal:float, backordered:int}
 */
function cart_totals(array $lines): array
{
    $units      = 0;
    $subtotal   = 0.0;
    $backordered = 0;

    foreach ($lines as $line) {
        $units    += (int) $line['Quantity'];
        $subtotal += (float) $line['LineTotal'];
        if ($line['LineState'] !== 'ok') {
            $backordered++;
        }
    }

    return [
        'units'       => $units,
        'lines'       => count($lines),
        'subtotal'    => round($subtotal, 2),
        'backordered' => $backordered,
    ];
}

/* cart_return_path() lives in helpers.php: the product card calls it, and the
   card renders on pages that never load this file. */
