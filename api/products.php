<?php
require __DIR__ . '/config.php';

try {
    $pdo = db();
    $shippingQuote = null;
    $shippingError = null;
    $customer = current_user();
    if ($customer) {
        try { $shippingQuote = customer_shipping_quote($pdo, $customer); }
        catch (Throwable $shippingException) { $shippingError = $shippingException->getMessage(); }
    }

    $catalog = customer_inventory_finished_goods();
    $stocks = $pdo->query("SELECT product_code,product_name,brand,size,unit,stock_quantity,unit_price FROM inventory WHERE status='Active'")->fetchAll();
    $stockByCode = [];
    foreach ($stocks as $stock) $stockByCode[(string)$stock['product_code']] = $stock;

    $hasIdFilter = isset($_GET['id']) || isset($_GET['ids']);
    $requestedIds = [];
    if (isset($_GET['id']) && (int)$_GET['id'] > 0) $requestedIds[(int)$_GET['id']] = true;
    if (isset($_GET['ids'])) foreach (array_filter(array_map('intval', explode(',', (string)$_GET['ids']))) as $id) $requestedIds[$id] = true;
    $products = [];
    $groupVariants = [];
    $catalogRows = [];
    foreach ($catalog as $item) {
        $code = (string)($item['item_code'] ?? '');
        if ($code === '' || !isset($stockByCode[$code])) continue;
        $stock = $stockByCode[$code];
        $item['brand'] = (string)($stock['brand'] ?? '');
        $item['size'] = (string)($stock['size'] ?: ($item['size'] ?? ''));
        $item['unit'] = (string)($stock['unit'] ?: ($item['unit'] ?? 'pcs'));
        $item['sales_quantity'] = (float)$stock['stock_quantity'];
        $item['unit_price'] = (float)$stock['unit_price'];
        $catalogRows[] = $item;
        $groupKey = (string)$item['item_name'] . "\0" . $item['brand'];
        if ($item['sales_quantity'] > 0) {
            $groupVariants[$groupKey][] = [
                'id' => (int)$item['item_id'],
                'size' => trim($item['size'] . ' ' . $item['unit']) ?: 'Standard',
                'stock' => (int)$item['sales_quantity'],
                'price' => $item['unit_price'],
            ];
        }
    }

    $query = trim((string)($_GET['q'] ?? ''));
    $category = trim((string)($_GET['category'] ?? ''));
    $sort = (string)($_GET['sort'] ?? '');
    $limit = isset($_GET['limit']) ? max(1, min(60, (int)$_GET['limit'])) : 60;
    foreach ($catalogRows as $row) {
        $id = (int)$row['item_id'];
        if ($hasIdFilter && !isset($requestedIds[$id])) continue;
        $brandSlug = strtolower(str_replace(['&', ' '], ['and', '-'], (string)$row['brand']));
        if ($category !== '' && $category !== $brandSlug) continue;
        if ($query !== '' && stripos($row['item_name'] . ' ' . $row['item_code'] . ' ' . $row['brand'], $query) === false) continue;
        $code = (string)$row['item_code'];
        $safeCode = preg_match('/^[A-Za-z0-9_-]+$/', $code) ? $code : '';
        $groupKey = (string)$row['item_name'] . "\0" . (string)$row['brand'];
        $products[] = [
            'id' => $id,
            'sku' => $code,
            'name' => $row['item_name'],
            'description' => 'Brand: ' . $row['brand'] . ' | Size: ' . $row['size'] . ' | Unit: ' . $row['unit'],
            'price' => (float)($groupVariants[$groupKey][0]['price'] ?? 0),
            'sale_price' => null,
            'gender' => 'all',
            'is_new' => false,
            'image_url' => $safeCode && is_file(__DIR__ . '/../assets/shoes/cut/' . $safeCode . '.svg') ? 'assets/shoes/cut/' . $safeCode . '.svg' : 'assets/shoes/cut/RUN-001.svg',
            'category' => $row['brand'] ?: 'Products',
            'category_slug' => $brandSlug ?: 'products',
            'brand' => $row['brand'],
            'size' => $row['size'],
            'unit' => $row['unit'],
            'variants' => $groupVariants[$groupKey] ?? [],
        ];
        if (count($products) >= $limit) break;
    }
    if ($sort === 'newest') $products = array_reverse($products);
    elseif ($sort === 'price_asc') usort($products, static fn($a, $b) => $a['price'] <=> $b['price']);
    elseif ($sort === 'price_desc') usort($products, static fn($a, $b) => $b['price'] <=> $a['price']);

    $categoryNames = [];
    foreach ($catalogRows as $row) if ($row['brand'] !== '') $categoryNames[$row['brand']] = true;
    $categories = [];
    foreach (array_keys($categoryNames) as $name) $categories[] = ['name' => $name, 'slug' => strtolower(str_replace(['&', ' '], ['and', '-'], $name))];
    respond(['currency' => CURRENCY, 'shipping_quote' => $shippingQuote, 'shipping_error' => $shippingError, 'products' => $products, 'categories' => $categories]);
} catch (Throwable $e) {
    error_log('Customer product catalog error: ' . $e->getMessage());
    respond(['error' => 'Could not load products from Inventory. Check the Inventory API configuration and Sales pricing data.'], 502);
}
