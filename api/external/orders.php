<?php
/**
 * Integration endpoint for the external order-processing system.
 * Auth header:  X-API-Key: <your key>
 *
 * GET  orders.php?sync_status=pending  -> orders (with items + customer) to import; marks them "sent"
 * POST orders.php                      -> update an order, JSON body:
 *   { "order_number":"SO-...", "external_ref":"ERP-123", "status":"shipped",
 *     "payment_status":"paid", "tracking_number":"...", "courier":"...", "note":"..." }
 */
require __DIR__ . '/../config.php';
$pdo = db();

$key = $_SERVER['HTTP_X_API_KEY'] ?? '';
$st = $pdo->prepare("SELECT name FROM api_clients WHERE api_key_hash = ? AND is_active = 1");
$st->execute([hash('sha256', $key)]);
$client = $st->fetchColumn();
if (!$client) respond(['error' => 'Unauthorized'], 401);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $sync = $_GET['sync_status'] ?? 'pending';
    $q = $pdo->prepare("SELECT o.*, c.first_name, c.last_name, c.email, c.phone FROM orders o
                        JOIN customers c ON c.id = o.customer_id WHERE o.sync_status = ? ORDER BY o.id LIMIT 100");
    $q->execute([$sync]);
    $orders = $q->fetchAll();
    $it = $pdo->prepare("SELECT sku, product_name, size, color, unit_price, quantity, line_total FROM order_items WHERE order_id = ?");
    foreach ($orders as &$o) { $it->execute([$o['id']]); $o['items'] = $it->fetchAll(); }
    if ($sync === 'pending' && $orders) {
        $ids = implode(',', array_map('intval', array_column($orders, 'id')));
        $pdo->exec("UPDATE orders SET sync_status='sent', synced_at=NOW() WHERE id IN ($ids)");
    }
    respond(['count' => count($orders), 'orders' => $orders]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = body();
    $statuses = ['pending','confirmed','processing','packed','shipped','delivered','cancelled','refunded'];
    $pays = ['unpaid','paid','failed','refunded'];
    if (empty($in['order_number'])) respond(['error' => 'order_number required'], 422);
    $f = []; $v = [];
    if (!empty($in['status'])) { if (!in_array($in['status'], $statuses, true)) respond(['error' => 'Bad status'], 422); $f[] = 'status=?'; $v[] = $in['status']; }
    if (!empty($in['payment_status'])) { if (!in_array($in['payment_status'], $pays, true)) respond(['error' => 'Bad payment_status'], 422); $f[] = 'payment_status=?'; $v[] = $in['payment_status']; }
    foreach (['external_ref','tracking_number','courier'] as $k) if (isset($in[$k])) { $f[] = "$k=?"; $v[] = $in[$k]; }
    $f[] = "sync_status='acknowledged'";
    $v[] = $in['order_number'];
    $u = $pdo->prepare("UPDATE orders SET " . implode(',', $f) . " WHERE order_number=?");
    $u->execute($v);
    if (!$u->rowCount()) respond(['error' => 'Order not found'], 404);
    if (!empty($in['status'])) {
        $pdo->prepare("INSERT INTO order_status_history (order_id,status,note,changed_by) SELECT id,?,?,? FROM orders WHERE order_number=?")
            ->execute([$in['status'], $in['note'] ?? null, $client, $in['order_number']]);
    }
    respond(['ok' => true]);
}
respond(['error' => 'Method not allowed'], 405);
