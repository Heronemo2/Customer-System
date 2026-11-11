<?php
require __DIR__ . '/config.php';

try {
    $pdo = db();
    $shippingQuote = null;
    $shippingError = null;
    $customer = current_user();
    if ($customer) {
        try {
            $shippingQuote = customer_shipping_quote($pdo, $customer);
        } catch (Throwable $shippingException) {
            $shippingError = $shippingException->getMessage();
        }
    }
    $where = ["status = 'Active'"];
    $params = [];

    if (!empty($_GET['id'])) {
        $where[] = 'fg_id = ?';
        $params[] = (int)$_GET['id'];
    }
    if (!empty($_GET['ids'])) {
        $ids = array_values(array_filter(array_map('intval', explode(',', $_GET['ids']))));
        if (!$ids) {
            respond(['currency' => CURRENCY, 'products' => [], 'categories' => []]);
        }
        $where[] = 'fg_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $params = array_merge($params, $ids);
    }
    if (!empty($_GET['category'])) {
        $where[] = "LOWER(REPLACE(REPLACE(brand, ' ', '-'), '&', 'and')) = ?";
        $params[] = $_GET['category'];
    }
    foreach (preg_split('/\s+/', trim($_GET['q'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $word) {
        $where[] = '(item_name LIKE ? OR item_code LIKE ? OR brand LIKE ?)';
        array_push($params, "%$word%", "%$word%", "%$word%");
    }

    $sort = [
        'price_asc' => 'item_name ASC',
        'price_desc' => 'item_name DESC',
        'newest' => 'fg_id DESC',
    ][$_GET['sort'] ?? ''] ?? 'item_name ASC';
    $limit = isset($_GET['limit']) ? max(1, min(60, (int)$_GET['limit'])) : 60;
    $stmt = $pdo->prepare(
        'SELECT fg_id,item_code,item_name,brand,size,unit,quantity,description,status
         FROM inventory_system.finished_goods
         WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $sort . ' LIMIT ' . $limit
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Sales inventory owns sellable stock and fixed pricing. Expose stocked
    // sizes, including unpriced ones so staff can see what still needs a price.
    $variantRows = $pdo->query(
        "SELECT fg.fg_id,fg.item_name,fg.brand,
                COALESCE(NULLIF(si.size, ''), fg.size) AS size,
                COALESCE(NULLIF(si.unit, ''), fg.unit) AS unit,
                si.stock_quantity AS quantity,si.unit_price
         FROM inventory_system.finished_goods fg
         JOIN inventory si ON BINARY si.product_code = BINARY fg.item_code
         WHERE fg.status = 'Active' AND si.status = 'Active'
           AND si.stock_quantity > 0
         ORDER BY size"
    )->fetchAll();
    $availableVariants = [];
    foreach ($variantRows as $variantRow) {
        $groupKey = (string)$variantRow['item_name'] . "\0" . (string)($variantRow['brand'] ?? '');
        $availableVariants[$groupKey][] = [
            'id' => (int)$variantRow['fg_id'],
            'size' => trim(($variantRow['size'] ?? '') . ' ' . ($variantRow['unit'] ?? '')) ?: 'Standard',
            'stock' => (int)$variantRow['quantity'],
            'price' => (float)$variantRow['unit_price'],
        ];
    }

    $products = [];
    foreach ($rows as $row) {
        $id = (int)$row['fg_id'];
        $code = (string)$row['item_code'];
        $safeCode = preg_match('/^[A-Za-z0-9_-]+$/', $code) ? $code : '';
        $image = $safeCode && is_file(__DIR__ . '/../assets/shoes/cut/' . $safeCode . '.svg')
            ? 'assets/shoes/cut/' . $safeCode . '.svg'
            : 'assets/shoes/cut/RUN-001.svg';
        $groupKey = (string)$row['item_name'] . "\0" . (string)($row['brand'] ?? '');
        $products[] = [
            'id' => $id,
            'sku' => $code,
            'name' => $row['item_name'],
            'description' => $row['description'] ?? ('Brand: ' . ($row['brand'] ?? '') . ' | Size: ' . ($row['size'] ?? '') . ' | Unit: ' . ($row['unit'] ?? '')),
            'price' => (float)($availableVariants[$groupKey][0]['price'] ?? 0),
            'sale_price' => null,
            'gender' => 'all',
            'is_new' => false,
            'image_url' => $image,
            'category' => $row['brand'] ?: 'Products',
            'category_slug' => 'products',
            'brand' => $row['brand'],
            'size' => $row['size'],
            'unit' => $row['unit'],
            'variants' => $availableVariants[$groupKey] ?? [],
        ];
    }

    $categories = $pdo->query(
        "SELECT DISTINCT brand name, LOWER(REPLACE(REPLACE(brand, ' ', '-'), '&', 'and')) slug
         FROM inventory_system.finished_goods
         WHERE status = 'Active' AND brand IS NOT NULL AND brand <> ''
         ORDER BY brand"
    )->fetchAll();
    respond(['currency' => CURRENCY, 'shipping_quote' => $shippingQuote, 'shipping_error' => $shippingError, 'products' => $products, 'categories' => $categories]);
} catch (Throwable $e) {
    respond(['error' => 'Could not load products. Check the Inventory System database connection and finished_goods table.'], 500);
}
