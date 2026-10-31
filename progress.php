<?php
$pageTitle = 'Progress Tracker';
$active = 'progress';
require __DIR__ . '/includes/layout-start.php';
?>
          <div class="page-head">
            <div>
              <p class="eyebrow">TRACK YOUR ORDERS</p>
              <h1>Progress tracker</h1>
              <p class="muted">See where each request stands, from submission to confirmed order.</p>
            </div>
          </div>
          <div id="tracker" class="request-list">
            <p class="muted">Loading your requests…</p>
          </div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
