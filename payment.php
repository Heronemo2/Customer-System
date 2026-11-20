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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_payment') {
    $csrfValue = $_POST['csrf_token'] ?? '';
    $invoiceValue = $_POST['invoice_id'] ?? '';
    $amountValue = $_POST['amount'] ?? '';
    $methodValue = $_POST['method'] ?? '';
    $referenceValue = $_POST['reference_no'] ?? '';
    $csrfToken = is_string($csrfValue) ? $csrfValue : '';
    $invoiceId = is_string($invoiceValue) && ctype_digit($invoiceValue) ? (int)$invoiceValue : 0;
    $amount = is_string($amountValue) ? trim($amountValue) : '';
    $method = is_string($methodValue) ? $methodValue : '';
    $reference = is_string($referenceValue) ? trim($referenceValue) : '';
    $uploadedFile = $_FILES['receipt'] ?? null;
    $storedFilename = null;
    $destination = null;

    if (!hash_equals($_SESSION['payment_csrf_token'], $csrfToken)) {
        http_response_code(400);
        $error = 'Your payment form expired. Reload the page and try again.';
    } elseif ($invoiceId <= 0 || !in_array($method, ['GCash', 'Debit/Credit Card'], true)) {
        $error = 'Select an invoice and a valid payment method.';
    } elseif ($reference === '' || strlen($reference) > 100 || preg_match('/[\x00-\x1F\x7F]/', $reference)) {
        $error = 'Enter a valid payment reference number (up to 100 characters).';
    } elseif (customer_payment_centavos($amount) === null || customer_payment_centavos($amount) <= 0) {
        $error = 'Enter a valid payment amount.';
    } elseif (!is_array($uploadedFile) || ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $error = 'Upload a receipt image or PDF to continue.';
    } elseif ((int)($uploadedFile['size'] ?? 0) <= 0 || (int)$uploadedFile['size'] > 5 * 1024 * 1024) {
        $error = 'The receipt must be no larger than 5 MB.';
    } elseif (!is_uploaded_file((string)($uploadedFile['tmp_name'] ?? ''))) {
        $error = 'The uploaded receipt could not be verified. Please try again.';
    } else {
        $allowedMimes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
        ];
        $fileInfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $fileInfo->file((string)$uploadedFile['tmp_name']);

        if (!is_string($mime) || !isset($allowedMimes[$mime])) {
            $error = 'Receipt must be a JPG, PNG, WebP, or PDF file.';
        } else {
            $uploadDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'payment-proofs';
            try {
                if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0700, true) && !is_dir($uploadDirectory)) {
                    throw new RuntimeException('Receipt storage is unavailable.');
                }

                $pdo->beginTransaction();
                $invoiceStmt = $pdo->prepare("
                    SELECT i.inv_id, i.invoice_no, i.total_amount, i.amount_paid, i.status,
                           o.customer_id, o.status AS order_status
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

                $pendingStmt = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM customer_payment_submissions
                    WHERE invoice_id = ? AND status = 'Pending'
                ");
                $pendingStmt->execute([$invoiceId]);
                if ((int)$pendingStmt->fetchColumn() > 0) {
                    throw new DomainException('A payment for this invoice is already waiting for Sales review.');
                }

                $remainingCentavos = max(
                    0,
                    (customer_payment_centavos((string)$invoice['total_amount']) ?? 0)
                        - (customer_payment_centavos((string)$invoice['amount_paid']) ?? 0)
                );
                $amountCentavos = customer_payment_centavos($amount);
                if ($remainingCentavos <= 0 || $amountCentavos === null || $amountCentavos > $remainingCentavos) {
                    throw new DomainException('The payment amount cannot exceed the invoice balance.');
                }

                $storedFilename = bin2hex(random_bytes(32)) . '.' . $allowedMimes[$mime];
                $destination = $uploadDirectory . DIRECTORY_SEPARATOR . $storedFilename;
                if (file_exists($destination) || !move_uploaded_file((string)$uploadedFile['tmp_name'], $destination)) {
                    throw new RuntimeException('The receipt could not be saved.');
                }

                $originalName = str_replace('\\', '/', (string)($uploadedFile['name'] ?? 'receipt'));
                $originalName = substr(basename($originalName), 0, 255);
                $formattedAmount = intdiv($amountCentavos, 100) . '.' . str_pad((string)($amountCentavos % 100), 2, '0', STR_PAD_LEFT);
                $insert = $pdo->prepare("
                    INSERT INTO customer_payment_submissions
                        (invoice_id, customer_id, amount, payment_method, reference_no,
                         receipt_name, receipt_path, receipt_mime, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending')
                ");
                $insert->execute([
                    $invoiceId,
                    $customerId,
                    $formattedAmount,
                    $method,
                    $reference,
                    $originalName,
                    $storedFilename,
                    $mime,
                ]);
                $pdo->commit();

                $_SESSION['payment_flash'] = 'Your payment proof was submitted. Sales will verify it before the invoice is updated.';
                header('Location: payment.php', true, 303);
                exit;
            } catch (DomainException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($destination !== null && is_file($destination)) {
                    unlink($destination);
                }
                $error = $e->getMessage();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($destination !== null && is_file($destination)) {
                    unlink($destination);
                }
                if ($e instanceof PDOException && str_starts_with((string)$e->getCode(), '23')) {
                    $error = 'That payment reference has already been submitted.';
                } else {
                    error_log('Customer payment submission failed: ' . $e->getMessage());
                    $error = 'Unable to submit your payment right now. Please try again or contact support.';
                }
            }
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
      AND NOT EXISTS (
          SELECT 1 FROM customer_payment_submissions s
          WHERE s.invoice_id = i.inv_id AND s.status = 'Pending'
      )
    ORDER BY i.inv_id DESC
");
$payableStmt->execute([$customerId]);
$payableInvoices = $payableStmt->fetchAll(PDO::FETCH_ASSOC);

$submissionsStmt = $pdo->prepare("
    SELECT s.submission_id, s.amount, s.payment_method, s.reference_no, s.status,
           s.review_note, s.created_at, i.invoice_no
    FROM customer_payment_submissions s
    JOIN sales_invoices i ON i.inv_id = s.invoice_id
    WHERE s.customer_id = ?
    ORDER BY s.submission_id DESC
    LIMIT 20
");
$submissionsStmt->execute([$customerId]);
$submissions = $submissionsStmt->fetchAll(PDO::FETCH_ASSOC);
$pendingCountStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM customer_payment_submissions
    WHERE customer_id = ? AND status = 'Pending'
");
$pendingCountStmt->execute([$customerId]);
$hasPendingSubmission = (int)$pendingCountStmt->fetchColumn() > 0;

require __DIR__ . '/includes/layout-start.php';
?>
          <div class="page-head">
            <div>
              <p class="eyebrow">PAYMENT</p>
              <h1>Payment</h1>
              <p class="muted">Submit payment details and a receipt for Sales to verify.</p>
            </div>
          </div>

          <?php if ($notice): ?>
            <div class="notice success"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></div>
          <?php endif; ?>
          <?php if ($error): ?>
            <div class="notice error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
          <?php endif; ?>

          <div class="notice" style="margin-bottom:18px;">
            This page does not charge cards or wallets. Do not enter a card number, expiry date, or CVV.
            Submit your payment reference and proof; Sales will verify the payment before updating your invoice.
          </div>

          <div class="panel" style="margin-bottom:18px; border:1px solid var(--line); border-radius:14px; background:var(--card); padding:18px;">
            <h2 style="margin:0 0 12px;">Submit Payment Proof</h2>
            <?php if (!$payableInvoices): ?>
              <div class="notice">
                <?= $hasPendingSubmission
                    ? 'A payment is waiting for Sales review. You can submit another payment after it is reviewed.'
                    : 'There are no unpaid invoices with a remaining balance.' ?>
              </div>
            <?php else: ?>
            <form method="POST" enctype="multipart/form-data" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:12px; align-items:end;">
              <input type="hidden" name="action" value="submit_payment">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['payment_csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
              <label style="display:grid; gap:6px; font-weight:600;">
                Invoice
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
              <label style="display:grid; gap:6px; font-weight:600;">
                Amount paid (₱)
                <input type="number" step="0.01" min="0.01" name="amount" required>
              </label>
              <label style="display:grid; gap:6px; font-weight:600;">
                Payment reference
                <input type="text" name="reference_no" maxlength="100" required>
              </label>
              <label style="display:grid; gap:6px; font-weight:600;">
                Receipt (JPG, PNG, WebP, PDF; max 5 MB)
                <input type="file" name="receipt" accept="image/jpeg,image/png,image/webp,application/pdf" required>
              </label>
              <button type="submit" class="btn" style="height:42px;">Submit for Verification</button>
            </form>
            <?php endif; ?>
          </div>

          <div class="panel" style="overflow-x:auto; border:1px solid var(--line); border-radius:14px; background:var(--card); padding:12px; margin-bottom:18px;">
            <h2 style="margin:8px;">My Payment Submissions</h2>
            <table style="width:100%; border-collapse:collapse;">
              <thead>
                <tr style="text-align:left; border-bottom:1px solid var(--line);">
                  <th style="padding:12px 8px;">Invoice</th>
                  <th style="padding:12px 8px;">Amount</th>
                  <th style="padding:12px 8px;">Method</th>
                  <th style="padding:12px 8px;">Reference</th>
                  <th style="padding:12px 8px;">Submitted</th>
                  <th style="padding:12px 8px;">Status / Note</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$submissions): ?>
                  <tr><td colspan="6" style="padding:12px 8px;">No payment proof submitted yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($submissions as $submission): ?>
                  <tr style="border-bottom:1px solid var(--line);">
                    <td style="padding:12px 8px; font-weight:700;"><?= htmlspecialchars($submission['invoice_no'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td style="padding:12px 8px;">₱<?= number_format((float)$submission['amount'], 2) ?></td>
                    <td style="padding:12px 8px;"><?= htmlspecialchars($submission['payment_method'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td style="padding:12px 8px;"><?= htmlspecialchars($submission['reference_no'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td style="padding:12px 8px;"><?= htmlspecialchars(date('M d, Y', strtotime($submission['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                    <td style="padding:12px 8px;">
                      <strong><?= htmlspecialchars($submission['status'], ENT_QUOTES, 'UTF-8') ?></strong>
                      <?php if (!empty($submission['review_note'])): ?>
                        <br><small><?= htmlspecialchars($submission['review_note'], ENT_QUOTES, 'UTF-8') ?></small>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
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
