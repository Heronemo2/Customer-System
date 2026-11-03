<?php
// STRIDE storefront UI connected to the existing Team Sales database.
const DB_HOST = 'localhost';
const DB_NAME = 'team_sales';
const DB_USER = 'root';
const DB_PASS = '';
const CURRENCY = 'PHP';

if (session_status() === PHP_SESSION_NONE) {
    session_name('CUSTOMER_SYSTEM_SESSID');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
if (headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}
function db(): PDO {
    static $pdo = null;
    if (!$pdo) $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}
function respond($data, int $code = 200): void { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function body(): array { $d = json_decode(file_get_contents('php://input'), true); return is_array($d) ? $d : []; }
function current_user(): ?array {
    if (empty($_SESSION['customer_portal_user']) || ($_SESSION['customer_portal_user']['role'] ?? '') !== 'Customer') return null;
    $u = $_SESSION['customer_portal_user'];
    $s = db()->prepare('SELECT u.user_id, u.full_name, u.username, u.role, u.customer_id, c.email, c.phone, c.address, c.city, c.province FROM users u JOIN customers c ON c.cus_id=u.customer_id WHERE u.user_id=? AND u.role="Customer" AND u.status="Active" LIMIT 1');
    $s->execute([(int)$u['id']]); $r = $s->fetch();
    if (!$r) return null;
    $parts = preg_split('/\s+/', trim($r['full_name']), 2);
    return ['id'=>(int)$r['user_id'], 'customer_id'=>(int)$r['customer_id'], 'first_name'=>$parts[0] ?? '', 'last_name'=>$parts[1] ?? '', 'name'=>$r['full_name'], 'username'=>$r['username'], 'email'=>$r['email'] ?? '', 'phone'=>$r['phone'] ?? '', 'address'=>$r['address'] ?? '', 'city'=>$r['city'] ?? '', 'province'=>$r['province'] ?? ''];
}
function customer_id(): int { $u=current_user(); return (int)($u['customer_id'] ?? 0); }
function next_request_no(PDO $pdo): string {
    $next=(int)$pdo->query('SELECT COALESCE(MAX(req_id),0)+1 FROM customer_order_requests')->fetchColumn();
    do { $no='REQ-'.(3000+$next); $q=$pdo->prepare('SELECT 1 FROM customer_order_requests WHERE request_no=? LIMIT 1'); $q->execute([$no]); if (!$q->fetchColumn()) return $no; $next++; } while (true);
}
