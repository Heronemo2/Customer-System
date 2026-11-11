<?php
$pageTitle = 'Balance';
$active = 'balance';
require __DIR__ . '/api/config.php';
require __DIR__ . '/includes/layout-start.php';

$user = current_user();
$customerId = (int)($user['customer_id'] ?? 0);
$pdo = db();
$stmt = $pdo->prepare(
    "SELECT r.ret_id, r.return_no, r.return_date, r.reason, r.total_amount, o.order_no
     FROM sales_returns r
     JOIN sales_orders o ON o.so_id = r.order_id
     WHERE o.customer_id = ?
       AND r.admin_status = 'Approved'
     ORDER BY r.return_date DESC, r.ret_id DESC"
);
$stmt->execute([$customerId]);
$approvedReturns = $stmt->fetchAll();
$totalBalance = array_sum(array_map(static fn($row) => (float)$row['total_amount'], $approvedReturns));
?>
          <div class="page-head">
            <div>
              <p class="eyebrow">BILLING</p>
              <h1>Return Balance</h1>
              <p class="muted">Review the value of sales returns accepted by the Sales Admin.</p>
            </div>
            <a class="btn" href="payment.php">Go to Payment</a>
          </div>

          <div class="panel" style="margin-bottom:18px;border:1px solid var(--line);border-radius:14px;background:var(--card);padding:18px;">
            <p class="eyebrow" style="margin:0 0 8px;">TOTAL APPROVED RETURN VALUE</p>
            <h2 style="margin:0;">₱<?= number_format($totalBalance, 2) ?></h2>
          </div>

          <?php if (!$approvedReturns): ?>
            <div class="notice">You have no approved sales returns yet. Accepted return amounts will appear here.</div>
          <?php else: ?>
            <div class="panel" style="overflow-x:auto;border:1px solid var(--line);border-radius:14px;background:var(--card);padding:12px;">
              <table style="width:100%;border-collapse:collapse;">
                <thead>
                  <tr style="text-align:left;border-bottom:1px solid var(--line);">
                    <th style="padding:12px 8px;">Return No.</th>
                    <th style="padding:12px 8px;">Order</th>
                    <th style="padding:12px 8px;">Date</th>
                    <th style="padding:12px 8px;">Reason</th>
                    <th style="padding:12px 8px;">Approved Return Amount</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($approvedReturns as $row): ?>
                    <tr style="border-bottom:1px solid var(--line);">
                      <td style="padding:12px 8px;font-weight:700;"><?= htmlspecialchars($row['return_no']) ?></td>
                      <td style="padding:12px 8px;"><?= htmlspecialchars($row['order_no']) ?></td>
                      <td style="padding:12px 8px;"><?= htmlspecialchars(date('M d, Y', strtotime($row['return_date']))) ?></td>
                      <td style="padding:12px 8px;"><?= htmlspecialchars($row['reason'] ?? '') ?></td>
                      <td style="padding:12px 8px;font-weight:700;">₱<?= number_format((float)$row['total_amount'], 2) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
