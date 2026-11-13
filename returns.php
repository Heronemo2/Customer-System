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
    $returnQuantity = filter_var($_POST['return_quantity'] ?? null, FILTER_VALIDATE_INT);

    if ($orderId <= 0 || $reason === '' || $date === '' || $amount <= 0 || !$returnQuantity || $returnQuantity < 1) {
        $error = 'Select an order, enter a return quantity and amount, enter a reason, and choose a return date.';
    } else {
        try {
            $pdo->beginTransaction();
            $orderStmt = $pdo->prepare("SELECT o.so_id,o.order_no,o.product_code,o.quantity,o.total_amount,o.status,i.total_amount invoice_total,i.amount_paid FROM sales_orders o JOIN sales_invoices i ON i.order_id=o.so_id AND i.status='Paid' AND i.amount_paid>=i.total_amount WHERE o.so_id=? AND o.customer_id=? LIMIT 1 FOR UPDATE");
            $orderStmt->execute([$orderId, $customerId]);
            $order = $orderStmt->fetch();
            if (!$order) {
                throw new RuntimeException('Selected order was not found for your account.');
            }

            $existingReturn = $pdo->prepare("SELECT return_no FROM sales_returns WHERE order_id=? AND admin_status IN ('Pending','Approved') ORDER BY ret_id DESC LIMIT 1");
            $existingReturn->execute([$orderId]);
            $existingReturnNo = $existingReturn->fetchColumn();
            if ($existingReturnNo) {
                throw new RuntimeException('This order already has a pending or approved Sales return (' . $existingReturnNo . ').');
            }
            if ($returnQuantity > (int)$order['quantity']) {
                throw new RuntimeException('Returned quantity cannot exceed the order quantity of ' . (int)$order['quantity'] . '.');
            }
            if ($amount > (float)$order['invoice_total']) {
                throw new RuntimeException('Return amount cannot exceed the paid invoice amount.');
            }

            $nextReturnId = (int)$pdo->query('SELECT COALESCE(MAX(ret_id),0)+1 FROM sales_returns')->fetchColumn();
            do {
                $returnNo = 'RET-' . $nextReturnId;
                $numberCheck = $pdo->prepare('SELECT 1 FROM sales_returns WHERE return_no=? LIMIT 1');
                $numberCheck->execute([$returnNo]);
                if (!$numberCheck->fetchColumn()) break;
                $nextReturnId++;
            } while (true);
            $insert = $pdo->prepare("INSERT INTO sales_returns (return_no, order_id, return_date, reason, return_quantity, total_amount, status, admin_status) VALUES (?, ?, ?, ?, ?, ?, 'Pending', 'Pending')");
            $insert->execute([$returnNo, $orderId, $date, $reason, $returnQuantity, $amount]);
                $returnId = (int)$pdo->lastInsertId();
                $admins = $pdo->query("
                    SELECT user_id
                    FROM users
                    WHERE role = 'Sales Admin' AND status = 'Active'
                ")->fetchAll(PDO::FETCH_COLUMN);
                $notifyAdmins = $pdo->prepare("
                    INSERT IGNORE INTO user_notifications (user_id, event_key, title, message, link)
                    VALUES (?, ?, 'Sales return awaiting approval', ?, 'sales_return_approval.php')
                ");
                $customerName = trim((string)($user['name'] ?? '')) ?: 'A customer';
                $message = $customerName . ' submitted Sales Return ' . $returnNo
                    . ' for order ' . $order['order_no'] . '.';
                foreach ($admins as $adminId) {
                    $notifyAdmins->execute([
                        (int)$adminId,
                        'sales-return-pending:' . $returnId,
                        $message,
                    ]);
                }
            $pdo->commit();
            $notice = 'Return request submitted successfully and sent to the Sales Admin.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Could not submit return request: ' . $e->getMessage();
        }
    }
}

$ordersStmt = $pdo->prepare("SELECT o.so_id,o.order_no,o.product_code,o.quantity,(SELECT i.total_amount FROM sales_invoices i WHERE i.order_id=o.so_id AND i.status='Paid' AND i.amount_paid>=i.total_amount ORDER BY i.inv_id DESC LIMIT 1) total_amount,o.order_date FROM sales_orders o WHERE o.customer_id=? AND o.quantity>0 AND o.status<>'Returned' AND EXISTS (SELECT 1 FROM sales_invoices i WHERE i.order_id=o.so_id AND i.status='Paid' AND i.amount_paid>=i.total_amount) AND NOT EXISTS (SELECT 1 FROM sales_returns r WHERE r.order_id=o.so_id AND r.admin_status IN ('Pending','Approved')) ORDER BY o.so_id DESC");
$ordersStmt->execute([$customerId]);
$orders = $ordersStmt->fetchAll();

$returnStmt = $pdo->prepare(
    "SELECT r.ret_id, r.return_no, r.return_date, r.reason, r.return_quantity, r.total_amount, r.status, r.admin_status, o.order_no, o.product_code
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
                <select name="order_id" id="returnOrderSelect" required <?= !$orders ? 'disabled' : '' ?>>
                  <option value="">Select order</option>
                  <?php foreach ($orders as $order): ?>
                    <option value="<?= (int)$order['so_id'] ?>" data-max-quantity="<?= (int)$order['quantity'] ?>" data-max-amount="<?= htmlspecialchars((string)$order['total_amount']) ?>"><?= htmlspecialchars($order['order_no'] . ' — ' . $order['product_code'] . ' (' . $order['quantity'] . ' pairs)') ?></option>
                  <?php endforeach; ?>
                </select>
                <?php if (!$orders): ?><small class="muted">There are no eligible paid invoices. Orders with a pending or approved return cannot be selected again.</small><?php endif; ?>
              </label>
              <label style="display:grid; gap:6px; font-weight:600;">
                Returned Quantity
                <input type="number" name="return_quantity" id="returnQuantity" min="1" max="1" value="1" required <?= !$orders ? 'disabled' : '' ?>>
              </label>
              <label style="display:grid; gap:6px; font-weight:600;">
                Return Date
                <input type="date" name="return_date" value="<?= date('Y-m-d') ?>" required>
              </label>
              <label style="display:grid; gap:6px; font-weight:600;">
                Return Amount
                <input type="number" step="0.01" min="0.01" id="returnAmount" name="total_amount" placeholder="Enter return amount" required <?= !$orders ? 'disabled' : '' ?>>
              </label>
              <label style="display:grid; gap:6px; font-weight:600; grid-column: 1 / -1;">
                Reason
                <input type="text" name="reason" placeholder="Describe the issue or reason for return" required>
              </label>
              <button type="submit" class="btn" style="height:42px;" <?= !$orders ? 'disabled' : '' ?>>Send Request</button>
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
                    <th style="padding:12px 8px;">Product / Qty.</th>
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
                      <td style="padding:12px 8px;"><?= htmlspecialchars((string)$row['product_code']) ?> × <?= (int)($row['return_quantity'] ?? 1) ?></td>
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
          <script>
          (() => {
              const order = document.getElementById('returnOrderSelect');
              const quantity = document.getElementById('returnQuantity');
              const amount = document.getElementById('returnAmount');
              if (!order || !quantity || !amount) return;
              const syncLimits = () => {
                  const selected = order.selectedOptions[0];
                  const maxQuantity = Number(selected?.dataset.maxQuantity || 1);
                  quantity.max = String(maxQuantity);
                  amount.max = selected?.dataset.maxAmount || '';
                  if (Number(quantity.value) > maxQuantity) quantity.value = String(maxQuantity);
                  if (amount.value && Number(amount.value) > Number(amount.max)) amount.value = amount.max;
              };
              order.addEventListener('change', syncLimits);
              syncLimits();
          })();
          </script>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
