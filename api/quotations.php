<?php
require __DIR__ . '/config.php';
$user = current_user();
if (!$user) respond(['error' => 'Sign in to review quotations'], 401);
$pdo = db();
$customerId = (int)$user['customer_id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = body();
    $quotationId = (int)($input['quotation_id'] ?? 0);
    $decision = $input['decision'] ?? '';
    if ($quotationId <= 0 || !in_array($decision, ['Approved', 'Rejected'], true)) respond(['error' => 'Invalid quotation decision'], 422);
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT q.*, r.request_no, r.quotation_id request_quotation_id FROM sales_quotations q LEFT JOIN customer_order_requests r ON r.req_id=q.source_request_id AND r.customer_id=q.customer_id WHERE q.sq_id=? AND q.customer_id=? AND q.admin_status='Approved' FOR UPDATE");
        $stmt->execute([$quotationId, $customerId]);
        $q = $stmt->fetch();
        if (!$q) throw new RuntimeException('Quotation not found or not yet released by Sales.');
        if ($q['customer_status'] !== 'Pending') throw new RuntimeException('This quotation already has a decision.');
        if (!empty($q['valid_until']) && $q['valid_until'] < date('Y-m-d')) throw new RuntimeException('This quotation has expired.');
        $stmt = $pdo->prepare("UPDATE sales_quotations SET customer_status=?, status=? WHERE sq_id=? AND customer_id=? AND admin_status='Approved' AND customer_status='Pending'");
        $stmt->execute([$decision, $decision, $quotationId, $customerId]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('Quotation status changed. Refresh and try again.');
        if (
            $decision === 'Rejected' &&
            !empty($q['source_request_id']) &&
            (int)($q['request_quotation_id'] ?? 0) === $quotationId
        ) {
            $stmt = $pdo->prepare("UPDATE customer_order_requests SET status='Pending Quotation', quotation_id=NULL WHERE req_id=? AND customer_id=? AND quotation_id=? AND status<>'Converted'");
            $stmt->execute([(int)$q['source_request_id'], $customerId, $quotationId]);
        }
        $poNo = null;
        if ($decision === 'Approved') {
            $stmt = $pdo->prepare('SELECT po_no FROM purchase_orders WHERE quotation_id=? LIMIT 1');
            $stmt->execute([$quotationId]);
            $poNo = $stmt->fetchColumn();
            if (!$poNo) {
                $next = (int)$pdo->query('SELECT COALESCE(MAX(po_id),0)+1 FROM purchase_orders')->fetchColumn();
                do {
                    $poNo = 'PO-' . (4000 + $next);
                    $check = $pdo->prepare('SELECT 1 FROM purchase_orders WHERE po_no=? LIMIT 1');
                    $check->execute([$poNo]);
                    if (!$check->fetchColumn()) break;
                    $next++;
                } while (true);
                $stmt = $pdo->prepare("INSERT INTO purchase_orders (po_no,quotation_id,customer_id,product_code,inventory_product_id,quantity,total_amount,order_date,status) VALUES (?,?,?,?,?,?,?,CURDATE(),'Open')");
                $stmt->execute([$poNo, $quotationId, $customerId, $q['product_code'], $q['fg_id'] ?? null, (int)$q['quantity'], (float)$q['total_amount']]);
            }
            if (!empty($q['source_request_id'])) {
                $stmt = $pdo->prepare("UPDATE customer_order_requests SET status='Converted', quotation_id=? WHERE req_id=? AND customer_id=?");
                $stmt->execute([$quotationId, (int)$q['source_request_id'], $customerId]);
            }
        }
        $pdo->commit();
        respond(['ok'=>true, 'message'=>$decision === 'Approved' ? 'Quotation accepted. Purchase order '.$poNo.' has been created.' : 'Quotation declined.', 'purchase_order'=>$poNo]);
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        respond(['error'=>$e->getMessage()], 409);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        respond(['error'=>'Could not save your decision. Please try again later.'], 500);
    }
}
$stmt = $pdo->prepare("SELECT q.sq_id,q.quotation_no,q.product_code,q.quantity,q.total_amount,q.quotation_date,q.valid_until,q.inventory_status,q.customer_status,r.request_no FROM sales_quotations q LEFT JOIN customer_order_requests r ON r.req_id=q.source_request_id WHERE q.customer_id=? AND q.admin_status='Approved' ORDER BY q.sq_id DESC");
$stmt->execute([$customerId]);
respond(['quotations'=>$stmt->fetchAll()]);
