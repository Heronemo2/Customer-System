<?php

$pageTitle = 'Product Details';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
requireCustomer();
require_once __DIR__ . '/includes/functions.php';

$pdo = getDB();

/*
|--------------------------------------------------------------------------
| Get Product ID
|--------------------------------------------------------------------------
| The products.php page sends the finished_goods primary key:
|
| product_details.php?id=FG_ID
|
*/

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    header('Location: products.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| Get Product
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        fg_id,
        item_code,
        item_name,
        brand,
        size,
        unit,
        quantity,
        description,
        reorder_level,
        status
    FROM inventory_system.finished_goods
    WHERE fg_id = ?
      AND status = 'Active'
    LIMIT 1
");

$stmt->execute([$id]);

$p = $stmt->fetch();

if (!$p) {
    http_response_code(404);
    exit('Product not found.');
}


/*
|--------------------------------------------------------------------------
| Initialize Messages
|--------------------------------------------------------------------------
*/

$error = '';
$notice = '';


/*
|--------------------------------------------------------------------------
| Add Product To Request
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        verifyCsrf();

        $quantity = (int)($_POST['quantity'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');

        if ($quantity < 1) {

            $error = 'Please enter a valid quantity.';

        } else {

            // Use the actual finished goods code
            $productCode = $p['item_code'];

            // Initialize cart
            if (!isset($_SESSION['portal_cart']) || !is_array($_SESSION['portal_cart'])) {
                $_SESSION['portal_cart'] = [];
            }

            if (!isset($_SESSION['portal_cart_notes']) || !is_array($_SESSION['portal_cart_notes'])) {
                $_SESSION['portal_cart_notes'] = [];
            }

            // Add quantity to existing cart quantity
            if (isset($_SESSION['portal_cart'][$productCode])) {

                $_SESSION['portal_cart'][$productCode] += $quantity;

            } else {

                $_SESSION['portal_cart'][$productCode] = $quantity;

            }

            // Save notes
            if ($notes !== '') {
                $_SESSION['portal_cart_notes'][$productCode] = $notes;
            }

            // Go to the actual request/cart page
            header('Location: cart.php');
            exit;
        }

    } catch (Throwable $e) {

        $error = 'Unable to add this product to your request. Please try again.';

    }
}


/*
|--------------------------------------------------------------------------
| Current Cart Quantity
|--------------------------------------------------------------------------
*/

$cartQuantity = 0;

if (
    isset($_SESSION['portal_cart']) &&
    isset($_SESSION['portal_cart'][$p['item_code']]) &&
    is_numeric($_SESSION['portal_cart'][$p['item_code']])
) {
    $cartQuantity = (int)$_SESSION['portal_cart'][$p['item_code']];
}


/*
|--------------------------------------------------------------------------
| Product Initial
|--------------------------------------------------------------------------
*/

$cleanName = preg_replace(
    '/[^A-Za-z0-9]/',
    '',
    $p['item_name']
);

$initial = strtoupper(
    substr($cleanName, 0, 2)
);

if (!$initial) {
    $initial = 'PR';
}


include __DIR__ . '/includes/header.php';

?>

<div class="section-head">

    <div>

        <h2>Product Details</h2>

        <p>
            Review the product before adding it to your order request.
        </p>

    </div>

</div>


<?php if ($error): ?>

    <div class="notice error">
        <?= e($error) ?>
    </div>

<?php endif; ?>


<?php if ($notice): ?>

    <div class="notice success">
        <?= e($notice) ?>
    </div>

<?php endif; ?>


<div class="detail-layout">


    <!-- Product Symbol -->

    <div class="detail-symbol">

        <?= e($initial) ?>

    </div>


    <!-- Product Information -->

    <div class="card">

        <!-- Product Code -->

        <span class="product-code">

            <?= e($p['item_code']) ?>

        </span>


        <!-- Product Name -->

        <h2 style="font:28px 'DM Serif Display',serif;margin:4px 0">

            <?= e($p['item_name']) ?>

        </h2>


        <!-- Brand -->

        <?php if (!empty($p['brand'])): ?>

            <p class="muted">

                <strong>Brand:</strong>

                <?= e($p['brand']) ?>

            </p>

        <?php endif; ?>


        <!-- Size -->

        <?php if (!empty($p['size'])): ?>

            <p class="muted">

                <strong>Size:</strong>

                <?= e($p['size']) ?>

            </p>

        <?php endif; ?>


        <!-- Unit -->

        <p class="muted">

            <strong>Unit:</strong>

            <?= e($p['unit']) ?>

        </p>


        <!-- Description -->

        <?php if (!empty($p['description'])): ?>

            <div
                class="help"
                style="margin-top:14px;line-height:1.6"
            >

                <strong>Description</strong>

                <br>

                <?= nl2br(e($p['description'])) ?>

            </div>

        <?php endif; ?>


        <!-- Price -->

        <div
            class="price"
            style="font-size:25px;margin-top:18px"
        >

            Price:

            <strong>To be quoted</strong>

        </div>


        <!-- Price Explanation -->

        <div
            class="help"
            style="margin-top:8px"
        >

            The product price is not fixed in the customer catalog.

            Sales will provide the final selling price through the
            quotation process.

        </div>


        <!-- Existing Cart Quantity -->

        <?php if ($cartQuantity > 0): ?>

            <div
                class="notice success"
                style="margin-top:16px"
            >

                <?= e($cartQuantity) ?>

                <?= $cartQuantity == 1 ? 'unit' : 'units' ?>

                of this product are already in your request.

            </div>

        <?php endif; ?>


        <!-- Add To Request -->

        <form
            method="post"
            style="margin-top:18px"
        >

            <input
                type="hidden"
                name="csrf"
                value="<?= e(csrfToken()) ?>"
            >


            <!-- Quantity -->

            <div class="field">

                <label for="quantity">
                    Quantity
                </label>

                <input
                    id="quantity"
                    type="number"
                    name="quantity"
                    min="1"
                    value="1"
                    required
                >

            </div>


            <!-- Notes -->

            <div
                class="field"
                style="margin-top:12px"
            >

                <label for="notes">
                    Notes for Sales
                </label>

                <textarea
                    id="notes"
                    name="notes"
                    rows="4"
                    placeholder="Optional request, size preference, delivery note, or other instructions."
                ></textarea>

            </div>


            <!-- Buttons -->

            <div
                style="
                    display:flex;
                    flex-wrap:wrap;
                    gap:6px;
                    margin-top:14px;
                "
            >

                <button
                    class="btn primary"
                    type="submit"
                >
                    Add to Request
                </button>


                <a
                    class="btn secondary"
                    href="products.php"
                >
                    Back to Products
                </a>


                <?php if ($cartQuantity > 0): ?>

                    <a
                        class="btn secondary"
                        href="cart.php"
                    >
                        View My Request
                    </a>

                <?php endif; ?>

            </div>

        </form>

    </div>

</div>


<?php include __DIR__ . '/includes/footer.php'; ?>

