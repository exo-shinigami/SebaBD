<?php
/**
 * SebaBD — JSON stock availability (read-only).
 *
 *   GET api/stock.php?product_id=<id>&quantity=<n>
 *
 * Answers "can this line be fulfilled right now?" for the order and quotation
 * forms. It uses the same stock rules as the storefront (serialized products
 * count their product_instance rows, everything else uses product.StockQty),
 * so a line warning here matches the wording on the product card.
 */
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/catalog-data.php';

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    json_response(['ok' => false, 'error' => 'This endpoint is read-only; use GET.'], 405);
}

if (!db()) {
    json_response(['ok' => false, 'error' => 'The catalog database is offline.'], 503);
}

$productId = (int) ($_GET['product_id'] ?? 0);
$quantity  = max(1, min(999, (int) ($_GET['quantity'] ?? 1)));

if ($productId <= 0) {
    json_response(['ok' => false, 'error' => 'A product_id is required.'], 400);
}

$rows = db_fetch_all(
    'SELECT ProductID, ProductName, StandardPrice, IsSerialized, StockQty, DefaultWarrantyMonths
     FROM product
     WHERE ProductID = :id',
    [':id' => $productId]
);

if (!$rows) {
    json_response(['ok' => false, 'error' => 'Unknown product.'], 404);
}

$product   = $rows[0];
$serialized = ((int) $product['IsSerialized']) === 1;
$counts    = stock_counts([$product]);
$available = $counts[$productId] ?? 0;

if ($available <= 0) {
    $status  = 'out';
    $message = $serialized
        ? 'No serialized units on hand — this line would be sourced to order.'
        : 'Out of stock — delivery would be made to order.';
} elseif ($available < $quantity) {
    $status  = 'low';
    $message = 'Only ' . $available . ' available right now — the other '
             . max(0, $quantity - $available) . ' would be back-ordered.';
} else {
    $status  = 'ok';
    $message = $serialized
        ? $available . ' serialized unit' . ($available === 1 ? '' : 's')
            . ' ready to ship, warranty tracked per device.'
        : $available . ' in stock — ships within 48 hours.';
}

json_response([
    'ok'               => true,
    'product_id'       => $productId,
    'name'             => $product['ProductName'],
    'requested'        => $quantity,
    'available'        => $available,
    'sufficient'       => $available >= $quantity,
    'status'           => $status,
    'label'            => stock_label($product, $available),
    'message'          => $message,
    'serialized'       => $serialized,
    'price_formatted'  => money($product['StandardPrice']),
    'warranty_months'  => (int) $product['DefaultWarrantyMonths'],
]);
