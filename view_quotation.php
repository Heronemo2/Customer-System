<?php
require_once __DIR__ . '/api/config.php';
header('Content-Type: text/html; charset=utf-8');
$user = current_user();
if (!$user) {
    http_response_code(401);
    echo '<title>Sign in required</title><p>Please sign in to view your quotation. <a href="login.php">Sign in</a></p>';
    exit;
}
$id = (int)($_GET['id'] ?? 0);
if ($id < 1) {
    http_response_code(400);
    exit('Invalid quotation ID.');
}
try {
    $pdo = db();
    $st = $pdo->prepare("SELECT q.quotation_no,q.quotation_date,q.valid_until,q.product_code,q.fg_id,q.quantity,q.total_amount,q.inventory_status,q.customer_status,q.admin_status,c.customer_name,c.customer_code,c.email,c.phone,c.address,c.city,c.province,COALESCE(fg.item_name,q.product_code,'Product') product_name,COALESCE(fg.unit,'pcs') unit FROM sales_quotations q JOIN customers c ON c.cus_id=q.customer_id LEFT JOIN inventory_system.finished_goods fg ON fg.fg_id=q.fg_id WHERE q.sq_id=? AND q.customer_id=? AND q.admin_status='Approved' LIMIT 1");
    $st->execute([$id, (int)$user['customer_id']]);
    $q = $st->fetch();
} catch (Throwable $e) {
    http_response_code(500);
    exit('The quotation could not be loaded. Please contact Sales support.');
}
if (!$q) {
    http_response_code(404);
    exit('Quotation not found or not yet released by Sales.');
}
function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function qdate($v): string
{
    if (!$v) return '—';
    $t = strtotime((string)$v);
    return $t ? date('M j, Y', $t) : h($v);
}
$qty = (int)$q['quantity'];
$total = (float)$q['total_amount'];
$unitPrice = $qty > 0 ? $total / $qty : 0;
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= h($q['quotation_no']) ?> — STRIDE Quotation</title>
    <style>
        * {
            box-sizing: border-box
        }

        body {
            margin: 0;
            background: #f3f5f7;
            color: #20252b;
            font: 15px/1.5 Arial, sans-serif
        }

        .toolbar {
            max-width: 900px;
            margin: 22px auto 12px;
            display: flex;
            justify-content: space-between;
            gap: 12px
        }

        .toolbar a,
        .toolbar button {
            border: 0;
            border-radius: 7px;
            padding: 10px 15px;
            background: #1f4e79;
            color: white;
            text-decoration: none;
            font-weight: bold;
            cursor: pointer
        }

        .sheet {
            max-width: 900px;
            margin: 0 auto 28px;
            background: #fff;
            padding: 48px 54px;
            box-shadow: 0 5px 24px #18243314
        }

        .head {
            display: flex;
            justify-content: space-between;
            gap: 25px;
            border-bottom: 2px solid #20252b;
            padding-bottom: 24px
        }

        .brand {
            font-size: 25px;
            font-weight: 800;
            letter-spacing: 1px
        }

        .label {
            font-size: 12px;
            letter-spacing: 2px;
            color: #626b75;
            font-weight: 700
        }

        h1 {
            margin: 10px 0 0;
            font-size: 28px
        }

        .meta {
            text-align: right
        }

        .meta strong {
            display: block;
            font-size: 18px
        }

        .details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 28px;
            margin: 30px 0
        }

        .details h2 {
            font-size: 12px;
            letter-spacing: 1px;
            color: #626b75;
            margin: 0 0 8px
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 28px
        }

        th {
            background: #f0f3f6;
            text-align: left;
            font-size: 12px;
            text-transform: uppercase
        }

        th,
        td {
            padding: 13px 12px;
            border-bottom: 1px solid #d9dee4
        }

        .num {
            text-align: right
        }

        .total {
            margin: 20px 0 0 auto;
            max-width: 300px
        }

        .total div {
            display: flex;
            justify-content: space-between;
            padding: 7px 0
        }

        .grand {
            border-top: 2px solid #20252b;
            font-size: 20px;
            font-weight: 800;
            margin-top: 6px;
            padding-top: 14px !important
        }

        .status {
            display: inline-block;
            border: 1px solid #d9dee4;
            border-radius: 20px;
            padding: 3px 10px;
            font-size: 12px
        }

        .note {
            margin-top: 38px;
            padding-top: 18px;
            border-top: 1px solid #d9dee4;
            color: #626b75;
            font-size: 13px
        }

        @media(max-width:650px) {
            .toolbar {
                margin: 12px;
                flex-wrap: wrap
            }

            .sheet {
                margin: 0;
                padding: 25px 18px
            }

            .head {
                flex-direction: column
            }

            .meta {
                text-align: left
            }

            .details {
                grid-template-columns: 1fr
            }
        }

        @media print {
            body {
                background: white
            }

            .toolbar {
                display: none
            }

            .sheet {
                max-width: none;
                margin: 0;
                padding: 0;
                box-shadow: none
            }
        }
    </style>
</head>

<body>
    <div class="toolbar"><a href="account.php">← Back to My Account</a><button onclick="window.print()" type="button">Print / Save as PDF</button></div>
    <main class="sheet">
        <header class="head">
            <div>
                <div class="brand">STRIDE</div>
                <div class="label">CUSTOMER QUOTATION</div>
                <h1>Quotation</h1>
            </div>
            <div class="meta"><strong><?= h($q['quotation_no']) ?></strong>
                <div>Date: <?= qdate($q['quotation_date']) ?></div>
                <div>Valid until: <?= qdate($q['valid_until']) ?></div>
                <div style="margin-top:9px"><span class="status"><?= h($q['customer_status']) ?></span></div>
            </div>
        </header>
        <section class="details">
            <div>
                <h2>PREPARED FOR</h2><strong><?= h($q['customer_name']) ?></strong><br><?= h($q['customer_code']) ?><br><?= h(trim(($q['address'] ?? '') . ' ' . ($q['city'] ?? '') . ' ' . ($q['province'] ?? ''))) ?><br><?= h($q['email'] ?? '') ?><br><?= h($q['phone'] ?? '') ?>
            </div>
            <div>
                <h2>QUOTATION DETAILS</h2>Product code: <strong><?= h($q['product_code']) ?></strong><br>Inventory review: <span class="status"><?= h($q['inventory_status']) ?></span><br>Prepared by: <strong>Sales Department</strong>
            </div>
        </section>
        <table>
            <thead>
                <tr>
                    <th>Product / Description</th>
                    <th class="num">Quantity</th>
                    <th class="num">Unit price</th>
                    <th class="num">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong><?= h($q['product_name']) ?></strong><br><small>Code: <?= h($q['product_code']) ?> · Unit: <?= h($q['unit']) ?></small></td>
                    <td class="num"><?= number_format($qty) ?></td>
                    <td class="num">₱<?= number_format($unitPrice, 2) ?></td>
                    <td class="num">₱<?= number_format($total, 2) ?></td>
                </tr>
            </tbody>
        </table>
        <div class="total">
            <div><span>Subtotal</span><strong>₱<?= number_format($total, 2) ?></strong></div>
            <div class="grand"><span>Total</span><span>₱<?= number_format($total, 2) ?></span></div>
        </div>
        <div class="note">Review this quotation in your account and use the Accept or Decline action there. Once accepted, your order will continue through processing.</div>
    </main>
</body>

</html>