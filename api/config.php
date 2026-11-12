<?php
// STRIDE storefront UI connected to the existing Team Sales database.
const DB_HOST = 'localhost';
const DB_NAME = 'team_sales';
const DB_USER = 'root';
const DB_PASS = '';
const CURRENCY = 'PHP';

function customer_inventory_environment_value(string $name): string {
    $value = getenv($name);
    if ($value !== false && trim((string)$value) !== '') return trim((string)$value);
    foreach ([$_SERVER[$name] ?? null, $_ENV[$name] ?? null] as $value) {
        if (is_string($value) && trim($value) !== '') return trim($value);
    }
    return '';
}

function customer_inventory_api_request(string $endpoint): array {
    $baseUrl = customer_inventory_environment_value('INVENTORY_API_BASE_URL') ?: 'http://localhost/Inventory_System/api';
    $apiKey = customer_inventory_environment_value('INVENTORY_API_KEY');
    if ($apiKey === '' || !preg_match('#^https?://#i', $baseUrl)) {
        throw new RuntimeException('Inventory catalog is not configured. Set INVENTORY_API_BASE_URL and INVENTORY_API_KEY.');
    }
    if (!function_exists('curl_init')) throw new RuntimeException('Inventory catalog is unavailable on this server.');
    $url = rtrim($baseUrl, '/') . '/' . $endpoint;
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-API-Key: ' . $apiKey, 'X-API-Team: Sales'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if ($body === false) throw new RuntimeException('Could not reach the Inventory catalog: ' . $error);
    $response = json_decode($body, true);
    if ($status < 200 || $status >= 300 || !is_array($response) || empty($response['success'])) {
        $message = is_array($response) ? (string)($response['message'] ?? '') : '';
        throw new RuntimeException($message !== '' ? $message : 'Inventory catalog request failed (HTTP ' . $status . ').');
    }
    return $response;
}

function customer_inventory_finished_goods(): array {
    static $items = null;
    if ($items !== null) return $items;
    $data = customer_inventory_api_request('items.php')['data'] ?? null;
    if (!is_array($data) || !is_array($data['items'] ?? null)) throw new RuntimeException('Inventory returned an invalid item catalog.');
    $items = array_values(array_filter($data['items'], static fn($item) =>
        ($item['item_type'] ?? '') === 'Finished Good' && ($item['status'] ?? '') === 'Active'
    ));
    return $items;
}

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
function customer_ors_request(string $url, string $apiKey, ?array $requestBody = null): array {
    if (!function_exists('curl_init')) throw new RuntimeException('Road-distance service is unavailable on this server.');
    $curl = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Authorization: ' . $apiKey, 'Accept: application/json'],
    ];
    if ($requestBody !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        $options[CURLOPT_POSTFIELDS] = json_encode($requestBody, JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($curl, $options);
    $responseBody = curl_exec($curl);
    $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);
    if ($responseBody === false || $httpCode < 200 || $httpCode >= 300) {
        error_log('openrouteservice request failed (' . $httpCode . '): ' . $curlError);
        throw new RuntimeException('Could not calculate a road route to your barangay. Check the address or try again later.');
    }
    $data = json_decode($responseBody, true);
    if (!is_array($data)) throw new RuntimeException('The road-distance service returned an invalid response.');
    return $data;
}
function customer_geocode_address(string $address, string $apiKey): array {
    $url = 'https://api.heigit.org/pelias/v1/search?' . http_build_query([
        'text' => $address,
        'boundary.country' => 'PH',
        'size' => 1,
    ]);
    $data = customer_ors_request($url, $apiKey);
    $coordinates = $data['features'][0]['geometry']['coordinates'] ?? null;
    if (!is_array($coordinates) || count($coordinates) < 2) {
        throw new RuntimeException('Could not locate the saved delivery address. Check the address and try again.');
    }
    return [(float)$coordinates[0], (float)$coordinates[1]];
}
function customer_phone_e164(string $phone, string $countryCode): ?string {
    $phone = trim($phone);
    if ($phone === '') return null;
    $digits = preg_replace('/\D+/', '', $phone);
    if (str_starts_with($phone, '+')) {
        $international = $digits;
    } else {
        $code = preg_replace('/\D+/', '', $countryCode);
        if ($code === '' || strlen($code) > 4) throw new InvalidArgumentException('Choose a valid country calling code.');
        if (str_starts_with($digits, $code) && strlen($digits) >= strlen($code) + 7) {
            $digits = substr($digits, strlen($code));
        }
        // Philippine mobile numbers are commonly entered with a local leading zero.
        if ($code === '63' && str_starts_with($digits, '0')) $digits = substr($digits, 1);
        $international = $code . $digits;
    }
    if (strlen($international) < 8 || strlen($international) > 15) {
        throw new InvalidArgumentException('Enter a valid phone number with its country code.');
    }
    return '+' . $international;
}
function customer_shipping_quote(PDO $pdo, array $user): array {
    $rateQuery = $pdo->prepare('SELECT setting_value FROM sales_settings WHERE setting_key=? LIMIT 1');
    $rateQuery->execute(['shipping_rate_per_km']);
    $rate = $rateQuery->fetchColumn();
    if ($rate === false || (float)$rate < 0) throw new RuntimeException('The shipping rate per kilometer has not been configured by Sales.');

    $barangay = trim((string)($user['address'] ?? ''));
    $city = trim((string)($user['city'] ?? ''));
    $province = trim((string)($user['province'] ?? ''));
    if ($barangay === '' || $city === '' || $province === '') {
        throw new RuntimeException('Complete your barangay, city/municipality, and province in account settings to calculate shipping.');
    }
    $destination = $barangay . ', ' . $city . ', ' . $province . ', Philippines';
    $cacheKey = hash('sha256', strtolower('San Pablo City, Laguna, Philippines|' . $destination));
    $cacheQuery = $pdo->prepare('SELECT distance_meters FROM sales_shipping_distance_cache WHERE cache_key=? LIMIT 1');
    $cacheQuery->execute([$cacheKey]);
    $distanceMeters = $cacheQuery->fetchColumn();

    if ($distanceMeters === false) {
        $apiKey = trim((string)getenv('OPENROUTESERVICE_API_KEY'));
        if ($apiKey === '') {
            throw new RuntimeException('Road-distance shipping is not configured. Set OPENROUTESERVICE_API_KEY for the Customer System.');
        }
        $originCoordinates = customer_geocode_address('San Pablo City, Laguna, Philippines', $apiKey);
        $destinationCoordinates = customer_geocode_address($destination, $apiKey);
        $routeData = customer_ors_request(
            'https://api.heigit.org/openrouteservice/v2/directions/driving-car',
            $apiKey,
            ['coordinates' => [$originCoordinates, $destinationCoordinates], 'units' => 'm']
        );
        $distanceMeters = (int)round((float)($routeData['routes'][0]['summary']['distance'] ?? 0));
        if ($distanceMeters < 1) throw new RuntimeException('No drivable route was found for the saved delivery location.');
        $saveCache = $pdo->prepare('INSERT INTO sales_shipping_distance_cache (cache_key,distance_meters) VALUES (?,?) ON DUPLICATE KEY UPDATE distance_meters=VALUES(distance_meters)');
        $saveCache->execute([$cacheKey, $distanceMeters]);
    }

    $distanceKm = (int)$distanceMeters / 1000;
    return [
        'distance_km' => round($distanceKm, 2),
        'rate_per_km' => (float)$rate,
        'shipping_fee' => round($distanceKm * (float)$rate, 2),
    ];
}
function current_user(): ?array {
    if (empty($_SESSION['customer_portal_user']) || ($_SESSION['customer_portal_user']['role'] ?? '') !== 'Customer') return null;
    $u = $_SESSION['customer_portal_user'];
    $s = db()->prepare('SELECT u.user_id, u.full_name, u.username, u.role, u.customer_id, u.avatar_path, c.email, c.phone, c.address, c.city, c.province FROM users u JOIN customers c ON c.cus_id=u.customer_id WHERE u.user_id=? AND u.role="Customer" AND u.status="Active" AND u.account_status="Approved" AND c.status="Active" LIMIT 1');
    $s->execute([(int)$u['id']]); $r = $s->fetch();
    if (!$r) return null;
    $parts = preg_split('/\s+/', trim($r['full_name']), 2);
    return ['id'=>(int)$r['user_id'], 'customer_id'=>(int)$r['customer_id'], 'first_name'=>$parts[0] ?? '', 'last_name'=>$parts[1] ?? '', 'name'=>$r['full_name'], 'username'=>$r['username'], 'avatar_path'=>$r['avatar_path'] ?? '', 'email'=>$r['email'] ?? '', 'phone'=>$r['phone'] ?? '', 'address'=>$r['address'] ?? '', 'city'=>$r['city'] ?? '', 'province'=>$r['province'] ?? ''];
}
function customer_id(): int { $u=current_user(); return (int)($u['customer_id'] ?? 0); }
function next_request_no(PDO $pdo): string {
    $next=(int)$pdo->query('SELECT COALESCE(MAX(req_id),0)+1 FROM customer_order_requests')->fetchColumn();
    do { $no='REQ-'.(3000+$next); $q=$pdo->prepare('SELECT 1 FROM customer_order_requests WHERE request_no=? LIMIT 1'); $q->execute([$no]); if (!$q->fetchColumn()) return $no; $next++; } while (true);
}
