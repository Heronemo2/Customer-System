<?php
$pageTitle = 'My Orders / Quotations';
$active = 'quotes';
require __DIR__ . '/includes/layout-start.php';
?>
          <div class="page-head">
            <div>
              <p class="eyebrow">PRICE &amp; ORDER HISTORY</p>
              <h1>My orders / quotations</h1>
              <p class="muted">Review confirmed prices, accept or decline them, and see the orders that follow.</p>
            </div>
          </div>
          <div class="tabs-row" id="qTabs" role="tablist" aria-label="Filter quotations"></div>
          <div id="quotations" class="request-list">
            <p class="muted">Loading your quotations…</p>
          </div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
