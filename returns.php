<?php
$pageTitle = 'Sales Return';
$active = 'returns';
require __DIR__ . '/api/config.php';
require __DIR__ . '/includes/layout-start.php';

$user = current_user();
$customerId = (int)($user['customer_id'] ?? 0);
$pdo = db();
$error = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_return') {
    $orderId = (int)($_POST['order_id'] ?? 0);
    $reason = trim((string)($_POST['reason'] ?? ''));
    $date = $_POST['return_date'] ?? date('Y-m-d');
    $amount = (float)($_POST['total_amount'] ?? 0);

    if ($orderId <= 0 || $reason === '' || $date === '') {
        $error = 'Select an order, enter a reason, and choose a return date.';
    } else {
        $orderStmt = $pdo->prepare('SELECT so_id, order_no, total_amount FROM sales_orders WHERE so_id = ? AND customer_id = ? LIMIT 1');
        $orderStmt->execute([$orderId, $customerId]);
        $order = $orderStmt->fetch();

        if (!$order) {
            $error = 'Selected order was not found for your account.';
        } else {
            $returnNo = 'RET-' . ((int)$pdo->query('SELECT COALESCE(MAX(ret_id),0)+1 FROM sales_returns')->fetchColumn() + 1);
            $insert = $pdo->prepare("INSERT INTO sales_returns (return_no, order_id, return_date, reason, total_amount, status, admin_status) VALUES (?, ?, ?, ?, ?, 'Pending', 'Pending')");
            try {
                $insert->execute([$returnNo, $orderId, $date, $reason, $amount > 0 ? $amount : (float)$order['total_amount']]);
                $notice = 'Return request submitted successfully and sent to the Sales Admin.';
            } catch (Throwable $e) {
                $error = 'Could not submit return request: ' . $e->getMessage();
            }
        }
    }
}

$ordersStmt = $pdo->prepare('SELECT so_id, order_no, total_amount, order_date FROM sales_orders WHERE customer_id = ? ORDER BY so_id DESC');
$ordersStmt->execute([$customerId]);
$orders = $ordersStmt->fetchAll();

$returnStmt = $pdo->prepare(
    "SELECT r.ret_id, r.return_no, r.return_date, r.reason, r.total_amount, r.status, r.admin_status, o.order_no
     FROM sales_returns r
     JOIN sales_orders o ON o.so_id = r.order_id
     WHERE o.customer_id = ?
     ORDER BY r.ret_id DESC"
);
$returnStmt->execute([$customerId]);
$rows = $returnStmt->fetchAll();
?>
          <div class="page-head">
            <div>
              <p class="eyebrow">SALES RETURN</p>
              <h1>Sales Return</h1>
              <p class="muted">Submit a return request and track the Sales Admin review status.</p>
            </div>
          </div>

          <?php if ($notice): ?>
            <div class="notice success"><?= htmlspecialchars($notice) ?></div>
          <?php endif; ?>
          <?php if ($error): ?>
            <div class="notice error"><?= htmlspecialchars($error) ?></div>
          <?php endif; ?>

          <div class="panel" style="margin-bottom:18px; border:1px solid var(--line); border-radius:14px; background:var(--card); padding:18px;">
            <h2 style="margin:0 0 12px;">Create Return Request</h2>
            <form method="POST" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:12px; align-items:end;">
              <input type="hidden" name="action" value="create_return">
              <label style="display:grid; gap:6px; font-weight:600;">
                Sales Order
                <select name="order_id" required>
                  <option value="">Select order</option>
                  <?php foreach ($orders as $order): ?>
                    <option value="<?= (int)$order['so_id'] ?>"><?= htmlspecialchars($order['order_no']) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label style="display:grid; gap:6px; font-weight:600;">
                Return Date
                <input type="date" name="return_date" value="<?= date('Y-m-d') ?>" required>
              </label>
              <label style="display:grid; gap:6px; font-weight:600;">
                Return Amount
                <input type="number" step="0.01" min="0" name="total_amount" value="0">
              </label>
              <label style="display:grid; gap:6px; font-weight:600; grid-column: 1 / -1;">
                Reason
                <input type="text" name="reason" placeholder="Describe the issue or reason for return" required>
              </label>
              <button type="submit" class="btn" style="height:42px;">Send Request</button>
            </form>
          </div>

          <?php if (!$rows): ?>
            <div class="notice">No sales returns yet.</div>
          <?php else: ?>
            <div class="panel" style="overflow-x:auto; border:1px solid var(--line); border-radius:14px; background:var(--card); padding:12px;">
              <table style="width:100%; border-collapse:collapse;">
                <thead>
                  <tr style="text-align:left; border-bottom:1px solid var(--line);">
                    <th style="padding:12px 8px;">Return No.</th>
                    <th style="padding:12px 8px;">Order</th>
                    <th style="padding:12px 8px;">Date</th>
                    <th style="padding:12px 8px;">Reason</th>
                    <th style="padding:12px 8px;">Amount</th>
                    <th style="padding:12px 8px;">Status</th>
                    <th style="padding:12px 8px;">Admin Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($rows as $row): ?>
                    <tr style="border-bottom:1px solid var(--line);">
                      <td style="padding:12px 8px; font-weight:700;"><?= htmlspecialchars($row['return_no']) ?></td>
                      <td style="padding:12px 8px;"><?= htmlspecialchars($row['order_no']) ?></td>
                      <td style="padding:12px 8px;"><?= htmlspecialchars(date('M d, Y', strtotime($row['return_date']))) ?></td>
                      <td style="padding:12px 8px;"><?= htmlspecialchars($row['reason']) ?></td>
                      <td style="padding:12px 8px;">₱<?= number_format((float)$row['total_amount'], 2) ?></td>
                      <td style="padding:12px 8px;">
                        <span class="badge" style="background: <?= strtolower($row['status']) === 'approved' ? '#1f8a4c' : (strtolower($row['status']) === 'rejected' ? '#c62828' : '#d48c00') ?>;">
                          <?= htmlspecialchars($row['status']) ?>
                        </span>
                      </td>
                      <td style="padding:12px 8px;">
                        <span class="badge" style="background: <?= strtolower($row['admin_status'] ?? 'pending') === 'approved' ? '#1f8a4c' : (strtolower($row['admin_status'] ?? 'pending') === 'rejected' ? '#c62828' : '#d48c00') ?>;">
                          <?= htmlspecialchars($row['admin_status'] ?? 'Pending') ?>
                        </span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
