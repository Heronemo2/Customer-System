<?php
require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') respond(['error' => 'GET only'], 405);
$user = current_user();
if (!$user) respond(['error' => 'Sign in to view your requests'], 401);

try {
    $pdo = db();
    $sql = "SELECT r.request_no,r.request_date,r.product_code,r.quantity,r.status,
                   q.quotation_no,po.po_no,so.order_no,
                   COALESCE(so.total_amount,po.total_amount,q.total_amount) total_amount,
                   fg.item_name product_name,fg.item_code image_code
            FROM customer_order_requests r
            LEFT JOIN sales_quotations q ON q.sq_id=COALESCE(
                r.quotation_id,
                (SELECT q2.sq_id FROM sales_quotations q2
                 WHERE q2.source_request_id=r.req_id ORDER BY q2.sq_id DESC LIMIT 1)
            )
            LEFT JOIN purchase_orders po ON po.quotation_id=q.sq_id AND po.customer_id=r.customer_id
            LEFT JOIN sales_orders so ON so.quotation_id=q.sq_id AND so.customer_id=r.customer_id
            LEFT JOIN inventory_system.finished_goods fg ON BINARY fg.item_code=BINARY r.product_code
            WHERE r.customer_id=?";
    $params = [(int)$user['customer_id']];
    if (isset($_GET['number']) && trim((string)$_GET['number']) !== '') {
        $sql .= ' AND r.request_no=?';
        $params[] = trim((string)$_GET['number']);
    }
    $sql .= ' ORDER BY r.req_id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll();
    foreach ($orders as &$order) {
        $code = (string)($order['image_code'] ?? '');
        $safeCode = preg_match('/^[A-Za-z0-9_-]+$/', $code) ? $code : '';
        $order['items'] = [[
            'product_name' => $order['product_name'] ?: $order['product_code'],
            'quantity' => (int)$order['quantity'],
            'image_url' => $safeCode && is_file(__DIR__ . '/../assets/shoes/cut/' . $safeCode . '.svg')
                ? 'assets/shoes/cut/' . $safeCode . '.svg'
                : 'assets/shoes/cut/RUN-001.svg',
        ]];
        unset($order['product_name'], $order['image_code']);
    }
    unset($order);
    respond(['orders' => $orders]);
} catch (Throwable $e) {
    respond(['error' => 'Could not load your requests. Please try again later.'], 500);
}
