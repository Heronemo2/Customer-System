<?php
// The product catalogue now lives on the dashboard. Keep old links working.
$qs = (string)($_SERVER['QUERY_STRING'] ?? '');
header('Location: dashboard.php' . ($qs !== '' ? '?' . $qs : ''));
exit;
