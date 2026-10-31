<?php
$pageTitle = 'Product';
$active = 'dashboard';
require __DIR__ . '/includes/layout-start.php';
?>
          <div id="pdp"><p class="muted err-page">Loading…</p></div>
          <section class="related" id="relWrap" hidden>
            <div class="section-head"><h2>You might also like</h2></div>
            <div class="grid" id="rel"></div>
          </section>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
