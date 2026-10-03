<?php
/**
 * SebaBD — JSON catalog search (read-only).
 *
 *   GET api/products.php?q=<text>&category=<CategoryID>&limit=<n>
 *
 * Powers the live suggestions under the header search box. The storefront grid
 * itself is rendered server-side and fetched as an HTML fragment
 * (index.php?ajax=panel), so the card markup lives in exactly one template.
 *
 * Every value is returned both raw and pre-formatted (money()/stock_label()),
 * so the browser never has to know about currency or stock rules.
 */
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/catalog-data.php';

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    json_response(['ok' => false, 'error' => 'This endpoint is read-only; use GET.'], 405);
}

if (!db()) {
    json_response(['ok' => false, 'error' => 'The catalog database is offline.'], 503);
}

$query      = trim((string) ($_GET['q'] ?? ''));
$categoryId = max(0, (int) ($_GET['category'] ?? 0));
// One extra row: it tells the caller there are more matches than returned.
$limit      = max(1, min(20, (int) ($_GET['limit'] ?? 6)));

$products = search_products($query, $categoryId, $limit + 1);
$more     = count($products) > $limit;
$products = array_slice($products, 0, $limit);
$counts   = stock_counts($products);

$results = [];
foreach ($products as $product) {
    $available = $counts[(int) $product['ProductID']] ?? 0;

    $results[] = [
        'id'               => (int) $product['ProductID'],
        'name'             => $product['ProductName'],
        'brand'            => $product['Brand'],
        'model'            => $product['Model'],
        'category'         => $product['CategoryName'],
        'price'            => (float) $product['StandardPrice'],
        'price_formatted'  => money($product['StandardPrice']),
        'available'        => $available,
        'stock'            => stock_label($product, $available),
        'serialized'       => ((int) $product['IsSerialized']) === 1,
        'warranty_months'  => (int) $product['DefaultWarrantyMonths'],
        'image'            => $product['image'] ?? null,
        'blurb'            => $product['blurb'] ?? '',
        // Suggestions open the product's own detail page.
        'url'              => 'product.php?id=' . (int) $product['ProductID'],
    ];
}

json_response([
    'ok'        => true,
    'query'     => $query,
    'category'  => $categoryId,
    'count'     => count($results),
    'more'      => $more,
    'products'  => $results,
]);
