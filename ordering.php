<?php
$pageTitle = 'Ordering';
$active = 'ordering';
require __DIR__ . '/includes/layout-start.php';
?>
          <div class="page-head">
            <div>
              <p class="eyebrow" id="welcome">ORDERING</p>
              <h1>Choose a product</h1>
              <p class="muted">Select a product to choose its size and quantity, then request a quotation from Sales.</p>
            </div>
          </div>
          <div class="notice" id="reviewBanner" hidden></div>
          <div class="toolbar">
            <form class="search" id="searchForm" role="search">
              <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="M16 16l5 5"/></svg>
              <input id="q" type="search" placeholder="Search products" aria-label="Search products" />
            </form>
            <label>Sort
              <select id="sort">
                <option value="">Name A–Z</option>
                <option value="price_desc">Name Z–A</option>
                <option value="newest">Newest</option>
              </select>
            </label>
          </div>
          <div class="chips" id="chips" aria-label="Product categories"></div>
          <p class="muted" id="count"></p>
          <div class="grid" id="grid"></div>

          <dialog class="order-dialog" id="orderDialog" aria-labelledby="orderProductName">
            <form id="orderForm">
              <div class="order-dialog-head">
                <div>
                  <p class="eyebrow">ORDER QUOTATION</p>
                  <h2 id="orderProductName">Product</h2>
                </div>
                <button type="button" class="order-dialog-close" id="closeOrderDialog" aria-label="Close">×</button>
              </div>
              <p id="orderProductDescription" class="muted"></p>
              <label>Size
                <select name="variant_id" id="orderVariant" required>
                  <option value="">Select a size</option>
                </select>
              </label>
              <label>Quantity
                <input type="number" name="quantity" id="orderQuantity" min="1" max="1000" value="1" required>
              </label>
              <p id="orderError" class="error" role="alert"></p>
              <button type="submit" class="btn" id="orderSubmit">Order Quotation</button>
            </form>
          </dialog>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
