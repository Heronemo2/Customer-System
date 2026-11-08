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
    $st = $pdo->prepare("SELECT q.quotation_no,q.quotation_date,q.valid_until,q.product_code,q.fg_id,q.quantity,q.total_amount,c.customer_name,c.email,c.phone,c.address,c.city,c.province,COALESCE(fg.item_name,q.product_code,'Product') product_name FROM sales_quotations q JOIN customers c ON c.cus_id=q.customer_id LEFT JOIN inventory_system.finished_goods fg ON fg.fg_id=q.fg_id WHERE q.sq_id=? AND q.customer_id=? AND q.admin_status='Approved' LIMIT 1");
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
    <title><?= h($q['quotation_no']) ?> — Sales Quotation</title>
    <style>
        * {
            box-sizing: border-box
        }

        body {
            margin: 0;
            background: #e9ecef;
            color: #17191c;
            font: 14px/1.5 Arial, sans-serif
        }

        .toolbar {
            max-width: 768px;
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
            background: #17191c;
            color: white;
            text-decoration: none;
            font-weight: bold;
            cursor: pointer
        }

        .sheet {
            max-width: 768px;
            margin: 0 auto 28px;
            background: #fff;
            padding: 48px 62px;
            box-shadow: 0 5px 24px #18243314
        }

        .head {
            display: grid;
            grid-template-columns: 1fr 190px;
            gap: 25px;
            border-bottom: 1px solid #333;
            padding-bottom: 16px
        }

        .brand {
            font-size: 30px;
            font-weight: 800;
            letter-spacing: .3px
        }

        .label {
            font-size: 14px;
            letter-spacing: .2px;
            color: #17191c;
            font-weight: 700
        }

        .meta {
            text-align: left;
            min-width: 0
        }

        .meta strong {
            display: block;
            font-size: 14px;
            margin-bottom: 5px
        }

        .valid-until {
            grid-column: 1 / -1;
            margin-top: -14px
        }

        .details {
            display: grid;
            grid-template-columns: 1fr;
            gap: 28px;
            margin: 20px 0 28px
        }

        .details h2 {
            font-size: 12px;
            letter-spacing: .2px;
            color: #17191c;
            margin: 0 0 6px
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 0
        }

        th {
            background: #eee;
            text-align: left;
            font-size: 12px;
            text-transform: uppercase
        }

        th,
        td {
            padding: 12px;
            border-bottom: 1px solid #999
        }

        .num {
            text-align: right
        }

        .total {
            margin: 16px 0 0 auto;
            max-width: 330px;
            text-align: right
        }

        .grand {
            font-size: 20px;
            font-weight: 800;
            margin-top: 6px
        }

        .note {
            margin-top: 34px;
            color: #17191c;
            font-size: 13px
        }

        .note p {
            margin: 8px 0
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
                grid-template-columns: 1fr;
                gap: 14px
            }

            .meta {
                text-align: left
            }

            .valid-until {
                grid-column: 1;
                margin-top: 0
            }

            .details {
                grid-template-columns: 1fr
            }

            th,
            td {
                padding: 9px 6px;
                font-size: 12px
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
                padding: 48px 62px;
                box-shadow: none
            }
        }
    </style>
</head>

<body>
    <div class="toolbar"><a href="quotations.php">← Back to My Orders</a><button onclick="window.print()" type="button">Print / Save as PDF</button></div>
    <main class="sheet">
        <header class="head">
            <div>
                <div class="brand">TEAM SALES</div>
                <div class="label">SALES QUOTATION</div>
            </div>
            <div class="meta"><strong><?= h($q['quotation_no']) ?></strong>
                <div>Date: <?= qdate($q['quotation_date']) ?></div>
            </div>
            <div class="valid-until">Valid Until: <?= qdate($q['valid_until']) ?></div>
        </header>
        <section class="details">
            <div>
                <h2>CUSTOMER</h2><strong><?= h($q['customer_name']) ?></strong><br><?= h(trim(($q['address'] ?? '') . ' ' . ($q['city'] ?? '') . ' ' . ($q['province'] ?? ''))) ?><br>Email: <?= h($q['email'] ?? '—') ?> &nbsp; Phone: <?= h($q['phone'] ?? '—') ?>
            </div>
        </section>
        <table>
            <thead>
                <tr>
                    <th>Item</th>
                    <th class="num">Qty</th>
                    <th class="num">Unit Price</th>
                    <th class="num">Total</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong><?= h($q['product_name']) ?></strong></td>
                    <td class="num"><?= number_format($qty) ?></td>
                    <td class="num">PHP <?= number_format($unitPrice, 2) ?></td>
                    <td class="num"><strong>PHP <?= number_format($total, 2) ?></strong></td>
                </tr>
            </tbody>
        </table>
        <div class="total">
            <div class="grand">TOTAL: PHP <?= number_format($total, 2) ?></div>
        </div>
        <div class="note">
            <strong>CUSTOMER APPROVAL</strong>
            <p>This quotation is subject to customer approval. Once approved,<br> a Purchase Order will be generated in the Sales module.</p>
            <p>Review and respond to this quotation in your customer account.</p>
            <p>Thank you for choosing Team Sales.</p>
        </div>
    </main>
</body>

</html>