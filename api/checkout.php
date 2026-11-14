<?php
require __DIR__ . '/config.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['error' => 'POST only'], 405);
$user = current_user();
if (!$user) respond(['error' => 'Sign in to submit a request', 'auth' => true], 401);
$input = body();
$items = $input['items'] ?? [];
$note = substr(trim((string)($input['note'] ?? '')), 0, 500);
if (!is_array($items) || !$items) respond(['error' => 'Your request cart is empty'], 422);
$pdo = db();
try {
    $shippingFee = (float)customer_shipping_quote($pdo, $user)['shipping_fee'];
    $catalogById = [];
    foreach (customer_inventory_finished_goods() as $catalogItem) $catalogById[(int)$catalogItem['item_id']] = $catalogItem;
    $pdo->beginTransaction();
    $valid = [];
    $quantitiesByProduct = [];
    foreach ($items as $item) {
        $variantId = (int)($item['variant_id'] ?? 0);
        $rawQuantity = $item['quantity'] ?? $item['qty'] ?? 0;
        if (!is_numeric($rawQuantity) || floor((float)$rawQuantity) !== (float)$rawQuantity) {
            throw new RuntimeException('Product quantity must be a whole number.');
        }
        $quantity = (int)$rawQuantity;
        if ($variantId < 1) throw new RuntimeException('Select a size for each product before submitting your request.');
        if ($quantity < 1) continue;
        $quantitiesByProduct[$variantId] = ($quantitiesByProduct[$variantId] ?? 0) + $quantity;
        if ($quantitiesByProduct[$variantId] > 1000) {
            throw new RuntimeException('You can request up to 1,000 units of each product.');
        }
        $catalogItem = $catalogById[$variantId] ?? null;
        if (!$catalogItem) throw new RuntimeException('A product in your cart is no longer available.');
        $size = trim((string)($catalogItem['size'] ?? ''));
        if ($size === '') throw new RuntimeException('A size is required for Finished Goods. Select a size and submit your request again.');
        $stmt = $pdo->prepare("SELECT product_code item_code,product_name item_name,stock_quantity quantity,unit_price FROM inventory WHERE BINARY product_code=BINARY ? AND status='Active' LIMIT 1");
        $stmt->execute([(string)$catalogItem['item_code']]);
        $product = $stmt->fetch();
        if (!$product) throw new RuntimeException('A product in your cart is no longer available.');
        if ((float)$product['unit_price'] <= 0) throw new RuntimeException('A fixed price is not set for a selected item. Please remove it from your cart.');
        // Customer requests are quotations, so an order may exceed current Sales stock.
        // Sales checks availability later and requests replenishment after PO creation.
        $valid[] = ['code' => $product['item_code'], 'qty' => $quantity, 'name' => $product['item_name'], 'size' => $size];
    }
    if (!$valid) throw new RuntimeException('Add at least one valid product and quantity.');

    // customer_order_requests.request_no is UNIQUE and each row represents one product line.
    // Give every product its own request number to prevent duplicate-key failures on multi-item carts.
    $requestNumbers = [];
    $insert = $pdo->prepare("INSERT INTO customer_order_requests (request_no,customer_id,request_date,product_code,size,quantity,shipping_fee,notes,status) VALUES (?,?,CURDATE(),?,?,?,?,?,'Pending Quotation')");
    $staffIds = $pdo->query("
        SELECT user_id
        FROM users
        WHERE role = 'Sales Staff' AND status = 'Active'
    ")->fetchAll(PDO::FETCH_COLUMN);
    $notifyStaff = $pdo->prepare("
        INSERT IGNORE INTO user_notifications (user_id, event_key, title, message, link)
        VALUES (?, ?, 'New customer order', ?, 'quotations.php')
    ");
    $customerName = trim((string)($user['name'] ?? '')) ?: 'A customer';
    foreach ($valid as $lineIndex => $line) {
        $next = (int)$pdo->query('SELECT COALESCE(MAX(req_id),0)+1 FROM customer_order_requests')->fetchColumn();
        do {
            $requestNo = 'REQ-' . (3000 + $next);
            $check = $pdo->prepare('SELECT 1 FROM customer_order_requests WHERE request_no=? LIMIT 1');
            $check->execute([$requestNo]);
            if (!$check->fetchColumn()) break;
            $next++;
        } while (true);
        $insert->execute([$requestNo, (int)$user['customer_id'], $line['code'], $line['size'], $line['qty'], $lineIndex === 0 ? $shippingFee : 0, $note]);
        $requestId = (int)$pdo->lastInsertId();
        $message = $customerName . ' ordered ' . $line['qty'] . ' x ' . $line['name'] . ' (' . $requestNo . ').';
        if (function_exists('mb_substr')) {
            $message = mb_substr($message, 0, 255, 'UTF-8');
        } else {
            $message = substr($message, 0, 255);
        }
        foreach ($staffIds as $staffId) {
            $notifyStaff->execute([
                (int)$staffId,
                'customer-order-request:' . $requestId,
                $message,
            ]);
        }
        $requestNumbers[] = $requestNo;
    }
    $pdo->commit();
    respond([
        'order_number' => $requestNumbers[0] ?? null,
        'request_number' => $requestNumbers[0] ?? null,
        'request_numbers' => $requestNumbers,
        'items_count' => count($valid)
    ], 201);
} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    respond(['error' => $e->getMessage()], 409);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    respond(['error' => 'Could not submit your request. Please try again later.'], 500);
}
