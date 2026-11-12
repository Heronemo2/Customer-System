<?php
// Preserve legacy product links while the storefront loads its catalog through api/products.php.
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    header('Location: shop.php', true, 302);
    exit;
}
header('Location: product.php?id=' . rawurlencode((string)$id), true, 302);
exit;
