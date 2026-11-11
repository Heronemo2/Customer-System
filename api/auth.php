<?php
require __DIR__ . '/config.php';
$act = $_GET['action'] ?? 'me';
if ($act === 'me') respond(['user' => current_user()]);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['error' => 'POST only'], 405);
$in = body();
$pdo = db();
if ($act === 'register') {
    $full = trim(($in['first_name'] ?? '') . ' ' . ($in['last_name'] ?? ''));
    $username = is_string($in['username'] ?? null) ? trim($in['username']) : '';
    $email = strtolower(trim($in['email'] ?? ''));
    $pw = (string)($in['password'] ?? '');
    try {
        $phone = customer_phone_e164((string)($in['phone'] ?? ''), (string)($in['phone_country_code'] ?? '+63'));
    } catch (InvalidArgumentException $e) {
        respond(['error' => $e->getMessage()], 422);
    }
    $address = trim($in['address'] ?? '');
    $city = trim($in['city'] ?? '');
    $province = trim($in['province'] ?? '');
    if (trim($in['first_name'] ?? '') === '' || trim($in['last_name'] ?? '') === '') respond(['error' => 'Enter your first and last name'], 422);
    if (preg_match('/^[\p{L}\p{N}._-]{3,60}$/u', $username) !== 1) respond(['error' => 'Enter a username with 3–60 letters or numbers; dots, underscores, and hyphens are also allowed.'], 422);
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
        // Use a temporary unique value, then assign the readable customer
        // number from the generated ID (for example, CUS-007).
        $code = 'TMP-' . strtoupper(bin2hex(random_bytes(8)));
        $s = $pdo->prepare('INSERT INTO customers (customer_code,customer_name,phone,email,address,city,province,status) VALUES (?,?,?,?,?,?,?,"Inactive")');
        $s->execute([$code, $full, $phone, $email, $address, $city, $province]);
        $cid = (int)$pdo->lastInsertId();
        $number = $cid;
        do {
            $code = 'CUS-' . str_pad((string)$number, 3, '0', STR_PAD_LEFT);
            $s = $pdo->prepare('SELECT 1 FROM customers WHERE customer_code=? AND cus_id<>?');
            $s->execute([$code, $cid]);
            if ($s->fetchColumn()) $number++;
            else break;
        } while (true);
        $s = $pdo->prepare('UPDATE customers SET customer_code=? WHERE cus_id=?');
        $s->execute([$code, $cid]);
        $s = $pdo->prepare('INSERT INTO users (full_name,username,password,role,customer_id,status,account_status) VALUES (?,?,?,"Customer",?,"Active","Pending")');
        $s->execute([$full, $username, password_hash($pw, PASSWORD_DEFAULT), $cid]);
        $pdo->commit();
        respond([
            'pending' => true,
            'message' => 'Your account request was submitted. You can sign in after a Sales Admin verifies your account.'
        ], 201);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        respond(['error' => 'Could not create your account. Please try again later.'], 500);
    }
}
if ($act === 'login') {
    $emailOrUser = trim($in['email'] ?? '');
    $pw = (string)($in['password'] ?? '');
    $s = $pdo->prepare('SELECT u.user_id id,u.full_name name,u.username,u.password,u.role,u.customer_id,u.status,u.account_status,c.status customer_status FROM users u LEFT JOIN customers c ON c.cus_id=u.customer_id WHERE (u.username=? OR c.email=?) AND u.role="Customer" LIMIT 1');
    $s->execute([$emailOrUser, strtolower($emailOrUser)]);
    $u = $s->fetch();
    if (!$u || !password_verify($pw, $u['password']) || empty($u['customer_id'])) respond(['error' => 'Username/email or password is incorrect'], 401);
    if ($u['account_status'] === 'Pending') respond(['error' => 'Your account is pending Sales Admin verification. Please try again after it has been approved.'], 403);
    if ($u['account_status'] === 'Rejected') respond(['error' => 'Your account request was not approved. Please contact Sales for assistance.'], 403);
    if ($u['status'] !== 'Active' || $u['customer_status'] !== 'Active') respond(['error' => 'Username/email or password is incorrect'], 401);
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
