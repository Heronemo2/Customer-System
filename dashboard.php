<?php
$pageTitle = 'Dashboard';
$active = 'dashboard';
require __DIR__ . '/includes/layout-start.php';
?>
          <div class="page-head">
            <div>
              <p class="eyebrow">CUSTOMER DASHBOARD</p>
              <h1>Welcome to Quick Step</h1>
              <p class="muted">Browse products and send Sales a quotation request, or check the progress of requests you have already submitted.</p>
            </div>
          </div>
          <div class="dashboard-actions">
            <a class="dashboard-action" href="ordering.php">
              <span class="eyebrow">START HERE</span>
              <h2>Order a quotation</h2>
              <p>Choose a product, size, and quantity to request a quotation from Sales.</p>
              <span class="btn">Browse products</span>
            </a>
            <a class="dashboard-action" href="requests.php">
              <span class="eyebrow">REQUESTS</span>
              <h2>Recent Requests</h2>
              <p>Review the status and progress of your product requests.</p>
              <span class="btn dark">View requests</span>
            </a>
            <a class="dashboard-action" href="quotations.php">
              <span class="eyebrow">QUOTATIONS</span>
              <h2>My Orders</h2>
              <p>Review quotations prepared by Sales and respond to them.</p>
              <span class="btn dark">View quotations</span>
            </a>
          </div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
