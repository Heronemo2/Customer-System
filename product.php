<?php
$productId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$target = 'ordering.php' . ($productId ? '?product_id=' . $productId : '');
header('Location: ' . $target, true, 302);
exit;
