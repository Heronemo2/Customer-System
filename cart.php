<?php
$pageTitle = 'Request Cart';
$active = 'cart';
require __DIR__ . '/includes/layout-start.php';
?>
          <div class="page-head">
            <div>
              <p class="eyebrow">YOUR REQUEST</p>
              <h1>Request cart</h1>
            </div>
          </div>
          <div class="two-col" style="padding-top: 0">
            <div id="lines"><p class="muted">Loading…</p></div>
            <div class="box">
              <h2>Order summary</h2>
              <div class="sumrow"><span>Subtotal</span><span id="sSub"></span></div>
              <div class="sumrow"><span>Shipping</span><span id="sShip"></span></div>
              <div class="sumrow total"><span>Total</span><span id="sTot"></span></div>
              <p id="note" class="muted"></p>
              <a class="btn" id="go" href="checkout.php">Continue to review</a>
            </div>
          </div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
