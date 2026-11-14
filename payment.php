<?php
$pageTitle = 'Payment';
$active = 'payment';
require __DIR__ . '/api/config.php';
require_once __DIR__ . '/includes/customer-page.php';

$user = current_user();
$customerId = (int)($user['customer_id'] ?? 0);
$pdo = db();
$error = '';
$notice = '';

function customer_payment_environment_value(string $name): string {
    $value = getenv($name);
    if ($value !== false && trim((string)$value) !== '') return trim((string)$value);
    foreach ([$_SERVER[$name] ?? null, $_ENV[$name] ?? null] as $value) {
        if (is_string($value) && trim($value) !== '') return trim($value);
    }
    return '';
}

function customer_payment_centavos(string $amount): ?int {
    if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $amount)) return null;
    [$pesos, $centavos] = array_pad(explode('.', $amount, 2), 2, '0');
    return ((int)$pesos * 100) + (int)str_pad($centavos, 2, '0');
}

function customer_payment_gateway_request(array $payload, string $idempotencyKey): array {
    $baseUrl = customer_payment_environment_value('PAYMENT_GATEWAY_BASE_URL')
        ?: 'http://localhost/payment-gateway/public/api/v1';
    $apiKey = customer_payment_environment_value('PAYMENT_GATEWAY_API_KEY');
    if ($apiKey === '') {
        throw new RuntimeException('Online payments are not configured. Please contact support.');
    }
    if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($baseUrl, PHP_URL_SCHEME)), ['http', 'https'], true)) {
        throw new RuntimeException('Online payments are not configured correctly. Please contact support.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Online payments are unavailable on this server.');
    }

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($json === false) throw new RuntimeException('Could not prepare the payment request.');

    $curl = curl_init(rtrim($baseUrl, '/') . '/create-payment.php');
    if ($curl === false) throw new RuntimeException('Could not initialize the payment request.');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-API-Key: ' . $apiKey,
            'Idempotency-Key: ' . $idempotencyKey,
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($curl);
    $curlError = curl_error($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if ($body === false) throw new RuntimeException('Could not reach the payment gateway: ' . $curlError);
    $response = json_decode($body, true);
    if ($status < 200 || $status >= 300 || !is_array($response) || empty($response['success'])) {
        $message = is_array($response) ? (string)($response['message'] ?? '') : '';
        throw new RuntimeException($message !== '' ? $message : 'The payment gateway could not start checkout.');
    }
    return $response;
}

if (empty($_SESSION['payment_csrf_token'])) {
    $_SESSION['payment_csrf_token'] = bin2hex(random_bytes(32));
}
if (!isset($_SESSION['payment_gateway_attempts']) || !is_array($_SESSION['payment_gateway_attempts'])) {
    $_SESSION['payment_gateway_attempts'] = [];
}
if (isset($_GET['gateway'])) {
    $notice = match ((string)($_GET['result'] ?? '')) {
        'success' => 'The simulator reported success. Your invoice changes only after Sales receives the signed confirmation.',
        'failed' => 'The simulator reported a failed payment. No collection was recorded; you can try again.',
        default => 'Your payment result is being confirmed. The invoice balance changes only after the gateway confirms payment.',
    };
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay_invoice') {
    $csrfValue = $_POST['csrf_token'] ?? '';
    $invoiceValue = $_POST['invoice_id'] ?? '';
    $amountValue = $_POST['amount'] ?? '';
    $methodValue = $_POST['method'] ?? '';
    $csrfToken = is_string($csrfValue) ? $csrfValue : '';
    $invoiceId = is_string($invoiceValue) && ctype_digit($invoiceValue) ? (int)$invoiceValue : 0;
    $amount = is_string($amountValue) ? trim($amountValue) : '';
    $method = is_string($methodValue) ? $methodValue : '';

    if (!hash_equals($_SESSION['payment_csrf_token'], $csrfToken)) {
        http_response_code(400);
        $error = 'Your payment form expired. Reload the page and try again.';
    } elseif ($invoiceId <= 0 || !in_array($method, ['COD', 'GCASH', 'CARD'], true)) {
        $error = 'Select an invoice and a valid payment method.';
    } else {
        $invoiceStmt = $pdo->prepare("
            SELECT i.inv_id, i.invoice_no, i.total_amount, i.amount_paid, i.status,
                   o.so_id, o.customer_id
            FROM sales_invoices i
            JOIN sales_orders o ON o.so_id = i.order_id
            WHERE i.inv_id = ? AND o.customer_id = ?
              AND i.status NOT IN ('Paid', 'Returned')
              AND COALESCE(i.amount_paid, 0) < i.total_amount
            LIMIT 1
        ");
        $invoiceStmt->execute([$invoiceId, $customerId]);
        $invoice = $invoiceStmt->fetch();

        if (!$invoice) {
            $error = 'Select an unpaid invoice with a remaining balance.';
        } else {
            $remainingCentavos = max(
                0,
                (customer_payment_centavos((string)$invoice['total_amount']) ?? 0)
                    - (customer_payment_centavos((string)$invoice['amount_paid']) ?? 0)
            );
            if ($remainingCentavos <= 0) {
                $error = 'This invoice has no remaining balance.';
            } elseif ($method === 'COD') {
                $paymentStatus = (float)$invoice['amount_paid'] > 0 ? 'Partially Paid' : 'Pending';
                $pdo->prepare('UPDATE sales_orders SET payment_method = ?, payment_status = ?, payment_reference = NULL WHERE so_id = ?')
                    ->execute(['Cash on Delivery', $paymentStatus, (int)$invoice['so_id']]);
                $notice = 'Cash on delivery selected. The balance remains due until the rider confirms collection.';
            } else {
                $amountCentavos = customer_payment_centavos($amount);
                if ($amountCentavos === null || $amountCentavos <= 0) {
                    $error = 'Enter a valid payment amount.';
                } elseif ($amountCentavos > $remainingCentavos) {
                    $error = 'The payment amount cannot exceed the remaining balance.';
                } else {
                    try {
                        $methodLabel = $method === 'GCASH' ? 'GCash' : 'Debit/Credit Card';
                        $fingerprint = $method . ':' . $amountCentavos;
                        $attempt = $_SESSION['payment_gateway_attempts'][$invoiceId] ?? null;
                        if (!is_array($attempt) || ($attempt['fingerprint'] ?? '') !== $fingerprint) {
                            $attempt = [
                                'fingerprint' => $fingerprint,
                                'key' => bin2hex(random_bytes(16)),
                            ];
                            $_SESSION['payment_gateway_attempts'][$invoiceId] = $attempt;
                        }

                        $response = customer_payment_gateway_request([
                            'amount' => intdiv($amountCentavos, 100) . '.' . str_pad((string)($amountCentavos % 100), 2, '0', STR_PAD_LEFT),
                            'external_reference' => (string)$invoice['invoice_no'],
                            'description' => 'Invoice ' . (string)$invoice['invoice_no'],
                            'customer_email' => (string)($user['email'] ?? ''),
                        ], (string)$attempt['key']);
                        $payment = $response['data'] ?? null;
                        $checkoutUrl = is_array($payment) ? (string)($payment['checkout_url'] ?? '') : '';
                        $urlParts = $checkoutUrl !== '' ? parse_url($checkoutUrl) : false;
                        if ($checkoutUrl !== '' && filter_var($checkoutUrl, FILTER_VALIDATE_URL)
                            && is_array($urlParts)
                            && in_array(strtolower((string)($urlParts['scheme'] ?? '')), ['http', 'https'], true)
                            && isset($payment['transaction_ref'])) {
                            $paymentStatus = (float)$invoice['amount_paid'] > 0 ? 'Partially Paid' : 'Pending';
                            $pdo->prepare('UPDATE sales_orders SET payment_method = ?, payment_status = ?, payment_reference = ? WHERE so_id = ?')
                                ->execute([$methodLabel, $paymentStatus, (string)$payment['transaction_ref'], (int)$invoice['so_id']]);
                            unset($_SESSION['payment_gateway_attempts'][$invoiceId]);
                            header('Location: ' . $checkoutUrl, true, 303);
                            exit;
                        }
                        if (is_array($payment) && ($payment['status'] ?? '') === 'SUCCESS') {
                            unset($_SESSION['payment_gateway_attempts'][$invoiceId]);
                            $notice = 'This payment was already completed. The invoice will refresh after confirmation.';
                        } else {
                            unset($_SESSION['payment_gateway_attempts'][$invoiceId]);
                            throw new RuntimeException('This checkout is no longer available. Please start a new payment.');
                        }
                    } catch (Throwable $e) {
                        error_log('Customer payment setup failed: ' . $e->getMessage());
                        $error = $e->getMessage();
                    }
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

require __DIR__ . '/includes/layout-start.php';
?>
          <div class="page-head">
            <div>
              <p class="eyebrow">PAYMENT</p>
              <h1>Payment</h1>
              <p class="muted">Choose cash on delivery, GCash, or debit/credit card for an unpaid invoice.</p>
            </div>
          </div>

          <?php if ($notice): ?>
            <div class="notice success"><?= htmlspecialchars($notice) ?></div>
          <?php endif; ?>
          <?php if ($error): ?>
            <div class="notice error"><?= htmlspecialchars($error) ?></div>
          <?php endif; ?>

          <div class="panel" style="margin-bottom:18px; border:1px solid var(--line); border-radius:14px; background:var(--card); padding:18px;">
            <h2 style="margin:0 0 12px;">Choose Payment Method</h2>
            <div class="notice">GCash and debit/credit card checkout runs in a simulator. It does not process real payments. Never enter real payment credentials.</div>
            <?php if (!$payableInvoices): ?>
              <div class="notice">There are no unpaid invoices with a remaining balance.</div>
            <?php else: ?>
            <form method="POST" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:12px; align-items:end;">
              <input type="hidden" name="action" value="pay_invoice">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['payment_csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
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
                Amount to pay (leave blank for COD)
                <input type="number" step="0.01" min="0.01" name="amount" id="paymentAmount">
              </label>
              <label style="display:grid; gap:6px; font-weight:600;">
                Method
                <select name="method" id="paymentMethod" required>
                  <option value="COD">Cash on Delivery</option>
                  <option value="GCASH">GCash</option>
                  <option value="CARD">Debit/Credit Card</option>
                </select>
              </label>
              <button type="submit" class="btn" style="height:42px;">Pay Now</button>
            </form>
            <script>
              const paymentMethod = document.getElementById('paymentMethod');
              const paymentAmount = document.getElementById('paymentAmount');
              const updatePaymentAmountRequirement = () => {
                paymentAmount.required = paymentMethod.value !== 'COD';
                paymentAmount.disabled = paymentMethod.value === 'COD';
              };
              paymentMethod.addEventListener('change', updatePaymentAmountRequirement);
              updatePaymentAmountRequirement();
            </script>
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
