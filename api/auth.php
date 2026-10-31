<?php
require __DIR__ . '/config.php';
$act = $_GET['action'] ?? 'me';
if ($act === 'me') respond(['user' => current_user()]);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['error' => 'POST only'], 405);
$in = body();
$pdo = db();
if ($act === 'register') {
    $full = trim(($in['first_name'] ?? '') . ' ' . ($in['last_name'] ?? ''));
    $username = trim($in['username'] ?? '');
    $email = strtolower(trim($in['email'] ?? ''));
    $pw = (string)($in['password'] ?? '');
    $phone = trim($in['phone'] ?? '');
    $address = trim($in['address'] ?? '');
    $city = trim($in['city'] ?? '');
    $province = trim($in['province'] ?? '');
    if (trim($in['first_name'] ?? '') === '' || trim($in['last_name'] ?? '') === '') respond(['error' => 'Enter your first and last name'], 422);
    if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username)) respond(['error' => 'Username must be 3–60 characters using letters, numbers, dots, underscores or hyphens'], 422);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond(['error' => 'Enter a valid email address'], 422);
    if (strlen($pw) < 8) respond(['error' => 'Password must be at least 8 characters'], 422);
    if (!$phone || !$address || !$city || !$province) respond(['error' => 'Complete your phone and address details'], 422);
    $c = $pdo->prepare('SELECT user_id FROM users WHERE username=? LIMIT 1');
    $c->execute([$username]);
    if ($c->fetch()) respond(['error' => 'That username is already taken'], 409);
    $c = $pdo->prepare('SELECT cus_id FROM customers WHERE email=? LIMIT 1');
    $c->execute([$email]);
    if ($c->fetch()) respond(['error' => 'That email already has a customer record'], 409);
    try {
        $pdo->beginTransaction();
        do {
            $code = 'CUS-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
            $s = $pdo->prepare('SELECT 1 FROM customers WHERE customer_code=?');
            $s->execute([$code]);
        } while ($s->fetchColumn());
        $s = $pdo->prepare('INSERT INTO customers (customer_code,customer_name,phone,email,address,city,province,status) VALUES (?,?,?,?,?,?,?,"Active")');
        $s->execute([$code, $full, $phone, $email, $address, $city, $province]);
        $cid = (int)$pdo->lastInsertId();
        $s = $pdo->prepare('INSERT INTO users (full_name,username,password,role,customer_id,status) VALUES (?,?,?,"Customer",?,"Active")');
        $s->execute([$full, $username, password_hash($pw, PASSWORD_DEFAULT), $cid]);
        $uid = (int)$pdo->lastInsertId();
        $pdo->commit();
        session_regenerate_id(true);
        $_SESSION['customer_portal_user'] = ['id' => $uid, 'customer_id' => $cid, 'role' => 'Customer'];
        respond(['user' => current_user()], 201);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        respond(['error' => 'Could not create your account. Please try again later.'], 500);
    }
}
if ($act === 'login') {
    $emailOrUser = trim($in['email'] ?? '');
    $pw = (string)($in['password'] ?? '');
    $s = $pdo->prepare('SELECT u.user_id id,u.full_name name,u.username,u.password,u.role,u.customer_id FROM users u LEFT JOIN customers c ON c.cus_id=u.customer_id WHERE (u.username=? OR c.email=?) AND u.status="Active" AND u.role="Customer" LIMIT 1');
    $s->execute([$emailOrUser, strtolower($emailOrUser)]);
    $u = $s->fetch();
    if (!$u || !password_verify($pw, $u['password']) || empty($u['customer_id'])) respond(['error' => 'Username/email or password is incorrect'], 401);
    session_regenerate_id(true);
    unset($u['password']);
    $_SESSION['customer_portal_user'] = $u;
    respond(['user' => current_user()]);
}
if ($act === 'logout') {
    unset($_SESSION['customer_portal_user']);
    session_regenerate_id(true);
    respond(['ok' => true]);
}
respond(['error' => 'Unknown action'], 404);
