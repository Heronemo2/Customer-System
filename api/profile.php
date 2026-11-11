<?php
require __DIR__ . '/config.php';

$user = current_user();
if (!$user) {
    respond(['error' => 'Sign in to update your profile'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['profile' => $user]);
}

$isMultipart = str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data');
$input = $isMultipart ? $_POST : body();
$name = trim((string)($input['name'] ?? $user['name']));
$phone = '';
try {
    $phone = customer_phone_e164((string)($input['phone'] ?? ''), (string)($input['phone_country_code'] ?? '+63')) ?? '';
} catch (InvalidArgumentException $e) {
    respond(['error' => $e->getMessage()], 422);
}
$email = strtolower(trim((string)($input['email'] ?? '')));
$address = trim((string)($input['address'] ?? ''));
$city = trim((string)($input['city'] ?? ''));
$province = trim((string)($input['province'] ?? ''));

if ($name === '') {
    respond(['error' => 'Name is required'], 422);
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(['error' => 'Enter a valid email address'], 422);
}

$newAvatarPath = null;
$newAvatarFile = null;
$oldAvatarPath = $user['avatar_path'] ?? '';
$avatarUpload = $_FILES['avatar'] ?? null;

if ($avatarUpload && $avatarUpload['error'] !== UPLOAD_ERR_NO_FILE) {
    if ($avatarUpload['error'] !== UPLOAD_ERR_OK) {
        respond(['error' => 'The profile photo could not be uploaded. Try a smaller image.'], 422);
    }
    if ($avatarUpload['size'] > 2 * 1024 * 1024) {
        respond(['error' => 'Profile photos must be 2 MB or smaller.'], 422);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($avatarUpload['tmp_name']);
    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    $imageInfo = @getimagesize($avatarUpload['tmp_name']);
    if (!isset($allowedTypes[$mime]) || $imageInfo === false) {
        respond(['error' => 'Choose a valid JPG, PNG, or WebP image.'], 422);
    }
    if ($imageInfo[0] > 6000 || $imageInfo[1] > 6000 || $imageInfo[0] * $imageInfo[1] > 25000000) {
        respond(['error' => 'Choose an image with dimensions up to 6000 × 6000 pixels.'], 422);
    }

    $uploadDirectory = dirname(__DIR__) . '/assets/uploads/avatars';
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true)) {
        error_log('Could not create customer profile photo directory.');
        respond(['error' => 'Profile photos are temporarily unavailable. Please try again later.'], 500);
    }

    $filename = 'customer_' . (int)$user['id'] . '_' . bin2hex(random_bytes(8)) . '.' . $allowedTypes[$mime];
    $newAvatarFile = $uploadDirectory . '/' . $filename;
    if (!move_uploaded_file($avatarUpload['tmp_name'], $newAvatarFile)) {
        respond(['error' => 'The profile photo could not be saved. Please try again.'], 500);
    }
    $newAvatarPath = 'assets/uploads/avatars/' . $filename;
}

try {
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE customers SET customer_name=?, phone=?, email=?, address=?, city=?, province=? WHERE cus_id=?')
        ->execute([$name, $phone, $email, $address, $city, $province, $user['customer_id']]);
    if ($newAvatarPath !== null) {
        $pdo->prepare('UPDATE users SET full_name=?, avatar_path=? WHERE user_id=? AND role="Customer"')
            ->execute([$name, $newAvatarPath, $user['id']]);
    } else {
        $pdo->prepare('UPDATE users SET full_name=? WHERE user_id=? AND role="Customer"')
            ->execute([$name, $user['id']]);
    }
    $pdo->commit();

    $_SESSION['customer_portal_user']['name'] = $name;

    if ($newAvatarPath !== null && str_starts_with($oldAvatarPath, 'assets/uploads/avatars/')) {
        $oldAvatarFile = dirname(__DIR__) . '/' . $oldAvatarPath;
        if (is_file($oldAvatarFile)) {
            unlink($oldAvatarFile);
        }
    }

    respond([
        'ok' => true,
        'message' => 'Profile updated.',
        'name' => $name,
        'avatar_path' => $newAvatarPath ?? $oldAvatarPath,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($newAvatarFile !== null && is_file($newAvatarFile)) {
        unlink($newAvatarFile);
    }
    error_log('Customer profile update failed: ' . $e->getMessage());
    respond(['error' => 'Could not update your profile. Check that the email is not used by another customer.'], 500);
}
