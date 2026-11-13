<?php
$pageTitle = 'Recent Requests';
$active = 'requests';
require __DIR__ . '/includes/layout-start.php';
?>
          <div class="page-head">
            <div>
              <p class="eyebrow">ORDER ACTIVITY</p>
              <h1>Recent requests</h1>
              <p class="muted">Products you have asked to order, newest first.</p>
            </div>
            <a class="btn" href="ordering.php">Browse products</a>
          </div>
          <div id="requests" class="request-list">
            <p class="muted">Loading your requests…</p>
          </div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
