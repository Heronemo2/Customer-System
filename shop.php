<?php
// The product catalogue now lives on the dashboard. Keep old links working.
$qs = (string)($_SERVER['QUERY_STRING'] ?? '');
header('Location: ordering.php' . ($qs !== '' ? '?' . $qs : ''));
exit;
