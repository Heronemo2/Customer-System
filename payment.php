<?php
$pageTitle = 'Payment';
$active = 'payment';
require __DIR__ . '/api/config.php';
require __DIR__ . '/includes/layout-start.php';

$user = current_user();
$customerId = (int)($user['customer_id'] ?? 0);
$pdo = db();
$error = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay_invoice') {
    $invoiceId = (int)($_POST['invoice_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $method = in_array($_POST['method'] ?? '', ['Cash', 'GCash', 'Bank Transfer'], true) ? $_POST['method'] : 'Cash';
    $date = $_POST['payment_date'] ?? date('Y-m-d');

    if ($invoiceId <= 0 || $amount <= 0 || $date === '') {
        $error = 'Select an invoice, enter a valid amount, and choose a date.';
    } else {
        $invoiceStmt = $pdo->prepare("
            SELECT i.inv_id, i.invoice_no, i.total_amount, i.amount_paid, i.status, o.customer_id
            FROM sales_invoices i
            JOIN sales_orders o ON o.so_id = i.order_id
            WHERE i.inv_id = ? AND o.customer_id = ?
              AND i.status <> 'Paid'
              AND COALESCE(i.amount_paid, 0) < i.total_amount
            LIMIT 1
        ");
        $invoiceStmt->execute([$invoiceId, $customerId]);
        $invoice = $invoiceStmt->fetch();

        if (!$invoice) {
            $error = 'Select an unpaid invoice with a remaining balance.';
        } else {
            $remaining = max(0, (float)$invoice['total_amount'] - (float)$invoice['amount_paid']);
            if ($amount > $remaining) {
                $error = 'The payment amount cannot exceed the remaining balance.';
            } else {
                $collectionNo = 'COL-' . ((int)$pdo->query('SELECT COALESCE(MAX(col_id),0)+1 FROM collections')->fetchColumn() + 500);
                $pdo->beginTransaction();
                try {
                    $pdo->prepare("INSERT INTO collections (collection_no, invoice_id, collection_date, due_date, amount, method) VALUES (?, ?, ?, ?, ?, ?)")
                        ->execute([$collectionNo, $invoiceId, $date, $date, $amount, $method]);

                    $newPaid = (float)$invoice['amount_paid'] + $amount;
                    $newStatus = $newPaid >= (float)$invoice['total_amount'] ? 'Paid' : 'Partially Paid';
                    $pdo->prepare("UPDATE sales_invoices SET amount_paid = ?, status = ? WHERE inv_id = ?")
                        ->execute([$newPaid, $newStatus, $invoiceId]);

                    $orderStmt = $pdo->prepare('SELECT order_id FROM sales_invoices WHERE inv_id = ? LIMIT 1');
                    $orderStmt->execute([$invoiceId]);
                    $orderId = (int)$orderStmt->fetchColumn();
                    if ($orderId > 0) {
                        $pdo->prepare("UPDATE sales_orders SET payment_status = ? WHERE so_id = ?")
                            ->execute([$newStatus === 'Paid' ? 'Paid' : 'Partially Paid', $orderId]);
                    }

                    $pdo->commit();
                    $notice = 'Payment recorded successfully.';
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = 'Could not save payment: ' . $e->getMessage();
                }
            }
        }
    }
}

$stmt = $pdo->prepare(
    "SELECT i.inv_id, i.invoice_no, i.invoice_date, i.total_amount, i.amount_paid, i.status, o.order_no,
            GREATEST(i.total_amount - i.amount_paid, 0) AS balance
     FROM sales_invoices i
     JOIN sales_orders o ON o.so_id = i.order_id
     WHERE o.customer_id = ?
     ORDER BY i.inv_id DESC"
);
$stmt->execute([$customerId]);
$rows = $stmt->fetchAll();

$payableStmt = $pdo->prepare("
    SELECT i.inv_id, i.invoice_no, i.status, o.order_no
    FROM sales_invoices i
    JOIN sales_orders o ON o.so_id = i.order_id
    WHERE o.customer_id = ?
      AND i.status NOT IN ('Paid','Returned')
      AND COALESCE(i.amount_paid, 0) < i.total_amount
    ORDER BY i.inv_id DESC
");
$payableStmt->execute([$customerId]);
$payableInvoices = $payableStmt->fetchAll();
?>
          <div class="page-head">
            <div>
              <p class="eyebrow">PAYMENT</p>
              <h1>Payment</h1>
              <p class="muted">Review your invoices and pay any remaining balance directly from the customer portal.</p>
            </div>
          </div>

          <?php if ($notice): ?>
            <div class="notice success"><?= htmlspecialchars($notice) ?></div>
          <?php endif; ?>
          <?php if ($error): ?>
            <div class="notice error"><?= htmlspecialchars($error) ?></div>
          <?php endif; ?>

          <div class="panel" style="margin-bottom:18px; border:1px solid var(--line); border-radius:14px; background:var(--card); padding:18px;">
            <h2 style="margin:0 0 12px;">Record Payment</h2>
            <?php if (!$payableInvoices): ?>
              <div class="notice">There are no unpaid invoices with a remaining balance.</div>
            <?php else: ?>
            <form method="POST" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:12px; align-items:end;">
              <input type="hidden" name="action" value="pay_invoice">
              <label style="display:grid; gap:6px; font-weight:600;">
                Invoice
                <select name="invoice_id" required>
                  <option value="">Select invoice</option>
                  <?php foreach ($payableInvoices as $row): ?>
                    <option value="<?= (int)$row['inv_id'] ?>"><?= htmlspecialchars($row['invoice_no']) ?> · <?= htmlspecialchars($row['order_no']) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label style="display:grid; gap:6px; font-weight:600;">
                Amount
                <input type="number" step="0.01" min="0.01" name="amount" required>
              </label>
              <label style="display:grid; gap:6px; font-weight:600;">
                Payment Date
                <input type="date" name="payment_date" value="<?= date('Y-m-d') ?>" required>
              </label>
              <label style="display:grid; gap:6px; font-weight:600;">
                Method
                <select name="method">
                  <option>Cash</option>
                  <option>GCash</option>
                  <option>Bank Transfer</option>
                </select>
              </label>
              <button type="submit" class="btn" style="height:42px;">Pay Now</button>
            </form>
            <?php endif; ?>
          </div>

          <?php if (!$rows): ?>
            <div class="notice">No payment records yet.</div>
          <?php else: ?>
            <div class="panel" style="overflow-x:auto; border:1px solid var(--line); border-radius:14px; background:var(--card); padding:12px;">
              <table style="width:100%; border-collapse:collapse;">
                <thead>
                  <tr style="text-align:left; border-bottom:1px solid var(--line);">
                    <th style="padding:12px 8px;">Invoice</th>
                    <th style="padding:12px 8px;">Order</th>
                    <th style="padding:12px 8px;">Date</th>
                    <th style="padding:12px 8px;">Total</th>
                    <th style="padding:12px 8px;">Paid</th>
                    <th style="padding:12px 8px;">Balance</th>
                    <th style="padding:12px 8px;">Status</th>
                    <th style="padding:12px 8px;">Invoice</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($rows as $row): ?>
                    <tr style="border-bottom:1px solid var(--line);">
                      <td style="padding:12px 8px; font-weight:700;"><?= htmlspecialchars($row['invoice_no']) ?></td>
                      <td style="padding:12px 8px;"><?= htmlspecialchars($row['order_no']) ?></td>
                      <td style="padding:12px 8px;"><?= htmlspecialchars(date('M d, Y', strtotime($row['invoice_date']))) ?></td>
                      <td style="padding:12px 8px;">₱<?= number_format((float)$row['total_amount'], 2) ?></td>
                      <td style="padding:12px 8px;">₱<?= number_format((float)$row['amount_paid'], 2) ?></td>
                      <td style="padding:12px 8px;">₱<?= number_format((float)$row['balance'], 2) ?></td>
                      <td style="padding:12px 8px;">
                        <span class="badge" style="background: <?= strtolower($row['status']) === 'returned' ? '#6b5b95' : (strtolower($row['status']) === 'paid' ? '#1f8a4c' : (strtolower($row['status']) === 'partially paid' ? '#d48c00' : '#c62828')) ?>;">
                          <?= htmlspecialchars($row['status']) ?>
                        </span>
                      </td>
                      <td style="padding:12px 8px;"><a href="invoices.php?id=<?= (int)$row['inv_id'] ?>">View Invoice</a></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
