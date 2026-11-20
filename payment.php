<?php
$pageTitle = 'Payment';
$active = 'payment';
require __DIR__ . '/api/config.php';
require_once __DIR__ . '/includes/customer-page.php';

$user = current_user();
$customerId = (int)($user['customer_id'] ?? 0);
$pdo = db();
$error = '';
$notice = $_SESSION['payment_flash'] ?? '';
unset($_SESSION['payment_flash']);

function customer_payment_centavos(string $amount): ?int
{
    if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $amount)) {
        return null;
    }

    [$pesos, $centavos] = array_pad(explode('.', $amount, 2), 2, '0');
    return ((int)$pesos * 100) + (int)str_pad($centavos, 2, '0');
}

if (empty($_SESSION['payment_csrf_token'])) {
    $_SESSION['payment_csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_paid') {
    $csrfValue = $_POST['csrf_token'] ?? '';
    $invoiceValue = $_POST['invoice_id'] ?? '';
    $methodValue = $_POST['method'] ?? '';
    $csrfToken = is_string($csrfValue) ? $csrfValue : '';
    $invoiceId = is_string($invoiceValue) && ctype_digit($invoiceValue) ? (int)$invoiceValue : 0;
    $method = is_string($methodValue) ? $methodValue : '';

    if (!hash_equals($_SESSION['payment_csrf_token'], $csrfToken)) {
        http_response_code(400);
        $error = 'Your payment form expired. Reload the page and try again.';
    } elseif ($invoiceId <= 0 || !in_array($method, ['GCash', 'Debit/Credit Card'], true)) {
        $error = 'Select an invoice and a valid payment method.';
    } else {
        try {
            $pdo->beginTransaction();
            $invoiceStmt = $pdo->prepare("
                SELECT i.inv_id, i.invoice_no, i.invoice_date, i.total_amount, i.amount_paid, i.status,
                       o.so_id, o.status AS order_status
                FROM sales_invoices i
                JOIN sales_orders o ON o.so_id = i.order_id
                WHERE i.inv_id = ? AND o.customer_id = ?
                FOR UPDATE
            ");
            $invoiceStmt->execute([$invoiceId, $customerId]);
            $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);

            if (!$invoice || in_array($invoice['status'], ['Paid', 'Returned'], true)
                || in_array($invoice['order_status'], ['Cancelled', 'Returned'], true)) {
                throw new DomainException('Select an unpaid invoice with a remaining balance.');
            }

            $totalCentavos = customer_payment_centavos((string)$invoice['total_amount']);
            $paidCentavos = customer_payment_centavos((string)$invoice['amount_paid']);
            if ($totalCentavos === null || $paidCentavos === null) {
                throw new RuntimeException('Invoice balance could not be calculated.');
            }
            $balanceCentavos = max(0, $totalCentavos - $paidCentavos);
            if ($balanceCentavos <= 0) {
                throw new DomainException('This invoice has no remaining balance.');
            }

            $collectionNo = 'COL-CUS-' . strtoupper(bin2hex(random_bytes(8)));
            $dueDate = date('Y-m-d', strtotime((string)$invoice['invoice_date'] . ' +30 days'));
            $amount = intdiv($balanceCentavos, 100) . '.' . str_pad((string)($balanceCentavos % 100), 2, '0', STR_PAD_LEFT);
            $insertCollection = $pdo->prepare("
                INSERT INTO collections
                    (collection_no, invoice_id, collection_date, due_date, amount, method)
                VALUES (?, ?, CURDATE(), ?, ?, ?)
            ");
            $insertCollection->execute([$collectionNo, $invoiceId, $dueDate, $amount, $method]);

            $updateInvoice = $pdo->prepare("
                UPDATE sales_invoices
                SET amount_paid = total_amount, status = 'Paid'
                WHERE inv_id = ?
            ");
            $updateInvoice->execute([$invoiceId]);

            $updateOrder = $pdo->prepare("
                UPDATE sales_orders
                SET payment_method = ?, payment_status = 'Paid',
                    payment_reference = NULL, paid_at = COALESCE(paid_at, NOW())
                WHERE so_id = ?
            ");
            $updateOrder->execute([$method, (int)$invoice['so_id']]);

            $completeOrder = $pdo->prepare("
                UPDATE sales_orders
                SET status = 'Completed'
                WHERE so_id = ?
                  AND status NOT IN ('Cancelled', 'Returned')
                  AND EXISTS (
                      SELECT 1 FROM deliveries
                      WHERE order_id = ? AND status = 'Delivered'
                  )
            ");
            $completeOrder->execute([(int)$invoice['so_id'], (int)$invoice['so_id']]);

            $pdo->commit();
            $_SESSION['payment_flash'] = 'Payment recorded. Invoice ' . $invoice['invoice_no'] . ' is now Paid.';
            header('Location: payment.php', true, 303);
            exit;
        } catch (DomainException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Customer payment recording failed: ' . $e->getMessage());
            $error = 'Unable to record the payment right now. Please try again or contact support.';
        }
    }
}

$stmt = $pdo->prepare("
    SELECT i.inv_id, i.invoice_no, i.invoice_date, i.total_amount, i.amount_paid, i.status, o.order_no,
           GREATEST(i.total_amount - i.amount_paid, 0) AS balance
    FROM sales_invoices i
    JOIN sales_orders o ON o.so_id = i.order_id
    WHERE o.customer_id = ?
    ORDER BY i.inv_id DESC
");
$stmt->execute([$customerId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$payableStmt = $pdo->prepare("
    SELECT i.inv_id, i.invoice_no, o.order_no,
           GREATEST(i.total_amount - i.amount_paid, 0) AS balance
    FROM sales_invoices i
    JOIN sales_orders o ON o.so_id = i.order_id
    WHERE o.customer_id = ?
      AND i.status NOT IN ('Paid', 'Returned')
      AND o.status NOT IN ('Cancelled', 'Returned')
      AND COALESCE(i.amount_paid, 0) < i.total_amount
    ORDER BY i.inv_id DESC
");
$payableStmt->execute([$customerId]);
$payableInvoices = $payableStmt->fetchAll(PDO::FETCH_ASSOC);

require __DIR__ . '/includes/layout-start.php';
?>
          <div class="page-head">
            <div>
              <p class="eyebrow">PAYMENT</p>
              <h1>Payment</h1>
              <p class="muted">Record the full remaining invoice balance after you have paid.</p>
            </div>
          </div>

          <?php if ($notice): ?>
            <div class="notice success"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></div>
          <?php endif; ?>
          <?php if ($error): ?>
            <div class="notice error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
          <?php endif; ?>

          <div class="panel" style="margin-bottom:18px; border:1px solid var(--line); border-radius:14px; background:var(--card); padding:18px;">
            <h2 style="margin:0 0 12px;">Record Full Payment</h2>
            <?php if (!$payableInvoices): ?>
              <div class="notice">There are no unpaid invoices with a remaining balance.</div>
            <?php else: ?>
            <form method="POST" onsubmit="return confirm('Have you completed payment? Confirming will immediately mark the full invoice balance as Paid.');" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:12px; align-items:end;">
              <input type="hidden" name="action" value="mark_paid">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['payment_csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
              <label style="display:grid; gap:6px; font-weight:600;">
                Invoice (full balance)
                <select name="invoice_id" required>
                  <option value="">Select invoice</option>
                  <?php foreach ($payableInvoices as $invoice): ?>
                    <option value="<?= (int)$invoice['inv_id'] ?>">
                      <?= htmlspecialchars($invoice['invoice_no'], ENT_QUOTES, 'UTF-8') ?> ·
                      <?= htmlspecialchars($invoice['order_no'], ENT_QUOTES, 'UTF-8') ?> ·
                      ₱<?= number_format((float)$invoice['balance'], 2) ?> due
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label style="display:grid; gap:6px; font-weight:600;">
                Method
                <select name="method" required>
                  <option value="GCash">GCash</option>
                  <option value="Debit/Credit Card">Debit/Credit Card</option>
                </select>
              </label>
              <button type="submit" class="btn" style="height:42px;">I've Paid — Mark as Paid</button>
            </form>
            <?php endif; ?>
          </div>

          <?php if (!$rows): ?>
            <div class="notice">No invoices found.</div>
          <?php else: ?>
            <div class="panel" style="overflow-x:auto; border:1px solid var(--line); border-radius:14px; background:var(--card); padding:12px;">
              <h2 style="margin:8px;">My Invoices</h2>
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
                      <td style="padding:12px 8px; font-weight:700;"><?= htmlspecialchars($row['invoice_no'], ENT_QUOTES, 'UTF-8') ?></td>
                      <td style="padding:12px 8px;"><?= htmlspecialchars($row['order_no'], ENT_QUOTES, 'UTF-8') ?></td>
                      <td style="padding:12px 8px;"><?= htmlspecialchars(date('M d, Y', strtotime($row['invoice_date'])), ENT_QUOTES, 'UTF-8') ?></td>
                      <td style="padding:12px 8px;">₱<?= number_format((float)$row['total_amount'], 2) ?></td>
                      <td style="padding:12px 8px;">₱<?= number_format((float)$row['amount_paid'], 2) ?></td>
                      <td style="padding:12px 8px;">₱<?= number_format((float)$row['balance'], 2) ?></td>
                      <td style="padding:12px 8px;"><?= htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8') ?></td>
                      <td style="padding:12px 8px;"><a href="invoices.php?id=<?= (int)$row['inv_id'] ?>">View Invoice</a></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
