<?php
/*
 * Shared customer layout: sidebar navigation + page shell.
 * Set $pageTitle and $active before requiring this file.
 * Every page that uses it is protected by the customer login guard.
 */
require_once __DIR__ . '/customer-page.php';

$pageTitle = $pageTitle ?? 'Quick Step';
$active = $active ?? '';
$root = dirname(__DIR__);

$icons = [
    'cart'      => '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.6 12.4a1 1 0 0 0 1 .8h9.3a1 1 0 0 0 1-.8L21 7H6"/>',
    'ordering'  => '<path d="M4 5h16M4 12h16M4 19h16"/><circle cx="8" cy="5" r="2"/><circle cx="15" cy="12" r="2"/><circle cx="10" cy="19" r="2"/>',
    'dashboard' => '<rect x="3" y="3" width="7.5" height="7.5" rx="1.5"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="1.5"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="1.5"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.5"/>',
    'requests'  => '<path d="M12 7v5l3 2"/><circle cx="12" cy="12" r="9"/>',
    'quotes'    => '<path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5M9 13h7M9 17h7"/>',
    'invoice'   => '<path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5M9 13h7M9 17h7"/>',
    'payment'   => '<path d="M4 7.5A2.5 2.5 0 0 1 6.5 5h11A2.5 2.5 0 0 1 20 7.5v9A2.5 2.5 0 0 1 17.5 19h-11A2.5 2.5 0 0 1 4 16.5z"/><path d="M4 10h16M8 15h4"/>',
    'balance'   => '<path d="M4 6h16M4 12h10M4 18h7"/><circle cx="18" cy="16" r="3"/>',
    'returns'   => '<path d="M7 7h10a3 3 0 0 1 3 3v4a3 3 0 0 1-3 3H7l-3 3V7a3 3 0 0 1 3-3h7"/><path d="M9 11h6M9 15h4"/>',
    'progress'  => '<circle cx="5" cy="6" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="18" r="2"/><path d="M6.5 7.5l4 3M13.5 13.5l4 3"/>',
    'account'   => '<circle cx="12" cy="8" r="4"/><path d="M4 21c1-4 4.5-6 8-6s7 2 8 6"/>',
];

$items = [
    ['dashboard', 'dashboard.php',  'Dashboard',             ''],
    ['ordering',  'ordering.php',   'Ordering',              ''],
    ['requests',  'requests.php',   'Recent Requests',       ''],
    ['quotes',    'quotations.php', 'My Orders', '<span class="badge" id="quoteCount" hidden>0</span>'],
    ['invoice',   'invoices.php',   'Sales Invoices',         ''],
    ['payment',   'payment.php',    'Payment',               ''],
    ['balance',   'balance.php',    'Balance',               ''],
    ['returns',   'returns.php',    'Sales Return',          ''],
    ['progress',  'progress.php',   'Progress Tracker',      ''],
    ['account',   'account.php',    'Account Settings',      ''],
];
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> — Quick Step</title>
    <link rel="icon" href="assets/favicon.svg" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link
      href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@62..125,400..900&display=swap"
      rel="stylesheet"
    />
    <link rel="stylesheet" href="css/style.css?v=<?= filemtime($root . '/css/style.css') ?>" />
  </head>
  <body>
    <div class="app">
      <aside class="sidebar" id="sidebar" aria-label="Customer navigation">
        <a class="logo" href="dashboard.php" aria-label="Quick Step dashboard">Quick Step</a>
        <nav class="side-nav">
<?php foreach ($items as [$key, $href, $label, $extra]): ?>
          <a href="<?= $href ?>" class="<?= $active === $key ? 'on' : '' ?>"<?= $active === $key ? ' aria-current="page"' : '' ?>>
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $icons[$key] ?></svg>
            <span><?= $label ?></span><?= $extra ?>
          </a>
<?php endforeach; ?>
        </nav>
        <div class="side-account">
          <p class="side-label">Account</p>
          <div class="side-user">
            <span class="avatar" id="userAvatar" aria-hidden="true">C</span>
            <div>
              <strong id="userName">Customer</strong>
              <small id="userEmail"></small>
            </div>
          </div>
          <button type="button" class="signout" id="signOut">Sign out</button>
        </div>
      </aside>
      <div class="scrim" id="scrim" hidden></div>
      <div class="main">
        <header class="topbar">
          <button type="button" class="menu-btn" id="menuBtn" aria-label="Open menu" aria-controls="sidebar" aria-expanded="false">
            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
          </button>
          <a class="logo" href="dashboard.php">Quick Step</a>
        </header>
        <main class="content">
