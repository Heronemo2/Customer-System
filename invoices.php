<?php
require __DIR__ . '/api/config.php';
$user = current_user();
if (!$user) {
    $next = basename($_SERVER['SCRIPT_NAME']) . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
    header('Location: login.php?next=' . rawurlencode($next) . '&msg=' . rawurlencode('Sign in to continue'));
    exit;
}

$customerId = (int)$user['customer_id'];
$invoiceId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$pdo = db();
if ($invoiceId) {
    $stmt = $pdo->prepare("
        SELECT i.inv_id, i.invoice_no, i.invoice_date, i.total_amount, i.amount_paid, i.status,
               o.order_no, o.product_code, o.quantity,
               c.customer_name, c.customer_code, c.email, c.phone, c.address, c.city, c.province
        FROM sales_invoices i
        JOIN sales_orders o ON o.so_id = i.order_id
        JOIN customers c ON c.cus_id = o.customer_id
        WHERE i.inv_id = ? AND o.customer_id = ?
        LIMIT 1
    ");
    $stmt->execute([$invoiceId, $customerId]);
    $invoice = $stmt->fetch();
    if (!$invoice) {
        http_response_code(404);
        exit('Invoice not found.');
    }
}

$pageTitle = 'Sales Invoices';
$active = 'invoice';
require __DIR__ . '/includes/layout-start.php';
?>
          <div class="page-head">
            <div>
              <p class="eyebrow">BILLING</p>
              <h1><?= isset($invoice) ? 'Sales Invoice' : 'Sales Invoices' ?></h1>
              <p class="muted"><?= isset($invoice) ? 'View and print your sales invoice.' : 'Sales invoices issued for your customer account.' ?></p>
            </div>
            <?php if (isset($invoice)): ?>
              <button type="button" class="btn no-print" onclick="window.print()">Print / Save as PDF</button>
            <?php endif; ?>
          </div>
          <?php if (isset($invoice)): ?>
            <?php $balance = max(0, (float)$invoice['total_amount'] - (float)$invoice['amount_paid']); ?>
            <article class="order invoice-print">
              <header>
                <div><strong>TEAM SALES</strong><br><span class="muted">SALES INVOICE</span></div>
                <div style="text-align:right"><strong><?= htmlspecialchars($invoice['invoice_no']) ?></strong><br><span class="muted"><?= htmlspecialchars(date('M d, Y', strtotime($invoice['invoice_date']))) ?></span></div>
              </header>
              <h2><?= htmlspecialchars($invoice['customer_name']) ?></h2>
              <p class="muted"><?= htmlspecialchars($invoice['address'] ?? '') ?><?= !empty($invoice['city']) ? ', ' . htmlspecialchars($invoice['city']) : '' ?><?= !empty($invoice['province']) ? ', ' . htmlspecialchars($invoice['province']) : '' ?></p>
              <p class="muted"><?= htmlspecialchars($invoice['email'] ?? '') ?><?= !empty($invoice['phone']) ? ' · ' . htmlspecialchars($invoice['phone']) : '' ?></p>
              <hr>
              <p><strong>Sales Order:</strong> <?= htmlspecialchars($invoice['order_no']) ?></p>
              <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:12px;padding:12px 0;border-bottom:1px solid var(--line)">
                <strong>Product</strong><strong>Quantity</strong><strong>Amount</strong>
                <span><?= htmlspecialchars($invoice['product_code'] ?? '—') ?></span>
                <span><?= (int)($invoice['quantity'] ?? 0) ?></span>
                <span>₱<?= number_format((float)$invoice['total_amount'], 2) ?></span>
              </div>
              <p><strong>Total:</strong> ₱<?= number_format((float)$invoice['total_amount'], 2) ?></p>
              <p><strong>Paid:</strong> ₱<?= number_format((float)$invoice['amount_paid'], 2) ?></p>
              <p><strong>Balance Due:</strong> ₱<?= number_format($balance, 2) ?></p>
              <p><strong>Status:</strong> <?= htmlspecialchars($invoice['status']) ?></p>
              <p class="no-print"><a href="invoices.php">Back to invoices</a> · <a href="payment.php">Go to payments</a></p>
            </article>
          <?php else: ?>
            <?php
              $stmt = $pdo->prepare("
                  SELECT i.inv_id, i.invoice_no, i.invoice_date, i.total_amount, i.amount_paid, i.status, o.order_no
                  FROM sales_invoices i
                  JOIN sales_orders o ON o.so_id = i.order_id
                  WHERE o.customer_id = ? AND i.total_amount > 0
                  ORDER BY i.invoice_date DESC, i.inv_id DESC
              ");
              $stmt->execute([$customerId]);
              $invoices = $stmt->fetchAll();
            ?>
            <?php if (!$invoices): ?>
              <div class="notice">No sales invoices have been issued for your account.</div>
            <?php else: ?>
              <div class="panel" style="overflow-x:auto;border:1px solid var(--line);border-radius:14px;background:var(--card);padding:12px">
                <table style="width:100%;border-collapse:collapse">
                  <thead><tr style="text-align:left;border-bottom:1px solid var(--line)"><th style="padding:12px 8px">Invoice</th><th style="padding:12px 8px">Order</th><th style="padding:12px 8px">Date</th><th style="padding:12px 8px">Total</th><th style="padding:12px 8px">Balance</th><th style="padding:12px 8px">Status</th><th style="padding:12px 8px">Action</th></tr></thead>
                  <tbody>
                    <?php foreach ($invoices as $row): ?>
                      <tr style="border-bottom:1px solid var(--line)">
                        <td style="padding:12px 8px"><strong><?= htmlspecialchars($row['invoice_no']) ?></strong></td>
                        <td style="padding:12px 8px"><?= htmlspecialchars($row['order_no']) ?></td>
                        <td style="padding:12px 8px"><?= htmlspecialchars(date('M d, Y', strtotime($row['invoice_date']))) ?></td>
                        <td style="padding:12px 8px">₱<?= number_format((float)$row['total_amount'], 2) ?></td>
                        <td style="padding:12px 8px">₱<?= number_format(max(0, (float)$row['total_amount'] - (float)$row['amount_paid']), 2) ?></td>
                        <td style="padding:12px 8px"><?= htmlspecialchars($row['status']) ?></td>
                        <td style="padding:12px 8px"><a class="btn" href="invoices.php?id=<?= (int)$row['inv_id'] ?>">View Invoice</a></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          <?php endif; ?>
          <style>
            @media print {
              .sidebar, .topbar, .page-head, .no-print { display: none !important; }
              .main, .content, .invoice-print { margin: 0 !important; padding: 0 !important; width: 100% !important; box-shadow: none !important; }
            }
          </style>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
