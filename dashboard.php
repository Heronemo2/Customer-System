<?php
$pageTitle = 'Dashboard';
$active = 'dashboard';
require __DIR__ . '/includes/layout-start.php';
?>
          <div class="page-head">
            <div>
              <p class="eyebrow" id="welcome">DASHBOARD</p>
              <h1>Available products</h1>
              <p class="muted">Browse everything on offer and add items to your request cart.</p>
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
<?php require __DIR__ . '/includes/layout-end.php'; ?>
