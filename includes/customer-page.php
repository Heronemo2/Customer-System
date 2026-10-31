<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('CUSTOMER_SYSTEM_SESSID');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

$customerPageUser = $_SESSION['customer_portal_user'] ?? null;
if (!is_array($customerPageUser) || ($customerPageUser['role'] ?? '') !== 'Customer') {
    $next = basename($_SERVER['SCRIPT_NAME']);
    $query = (string)($_SERVER['QUERY_STRING'] ?? '');
    if ($query !== '') {
        $next .= '?' . $query;
    }

    header(
        'Location: login.php?next=' . rawurlencode($next) .
        '&msg=' . rawurlencode('Sign in to continue')
    );
    exit;
}
