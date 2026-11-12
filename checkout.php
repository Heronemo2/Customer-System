<?php
$pageTitle = 'Submit Request';
$active = 'cart';
require __DIR__ . '/includes/layout-start.php';
?>
          <div class="page-head">
            <div>
              <p class="eyebrow">ALMOST DONE</p>
              <h1>Review your order</h1>
            </div>
          </div>
          <div class="two-col" style="padding-top: 0">
            <form id="co" class="box" novalidate>
              <h3>Your details</h3>
              <p id="who" class="muted"></p>
              <p class="muted">Send your order request now. We’ll confirm the final price and availability for you to review before your order is finalized.</p>
              <textarea name="note" rows="4" placeholder="Order notes (optional): preferred details, timing, or questions"></textarea>
            </form>
            <div class="box">
              <h2>Order summary</h2>
              <div id="lines"></div>
              <div class="sumrow"><span>Subtotal</span><span id="sSub"></span></div>
              <div class="sumrow"><span>Shipping</span><span id="sShip"></span></div>
              <div class="sumrow total"><span>Total</span><span id="sTot"></span></div>
              <p id="err" class="error" role="alert"></p>
              <button class="btn" id="place" form="co">Place order request</button>
              <a href="cart.php" class="muted" style="text-align: center">Back to cart</a>
            </div>
          </div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>