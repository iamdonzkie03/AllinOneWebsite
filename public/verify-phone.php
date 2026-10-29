<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function json_response(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(405, ['ok'=>false,'error'=>'Method not allowed.']);
try { verify_csrf(); } catch (Throwable $e) { json_response(419, ['ok'=>false,'error'=>'Your session expired. Refresh registration and try again.']); }

$firebase = $config['firebase'] ?? [];
$apiKey = (string)($firebase['api_key'] ?? '');
if ($apiKey === '' || !function_exists('curl_init')) json_response(503, ['ok'=>false,'error'=>'Phone verification is not configured on the server.']);
$idToken = trim((string)($_POST['firebase_id_token'] ?? ''));
if ($idToken === '' || strlen($idToken) > 10000) json_response(400, ['ok'=>false,'error'=>'Phone verification token is missing.']);

$lastName = trim((string)($_POST['lastname'] ?? ''));
$firstName = trim((string)($_POST['firstname'] ?? ''));
$middleName = trim((string)($_POST['middlename'] ?? ''));
$suffix = trim((string)($_POST['suffix'] ?? ''));
$fullName = implode(', ', array_values(array_filter([$lastName, $firstName, $middleName, $suffix], static fn(string $part): bool => $part !== '')));
$mobile = trim((string)($_POST['mobile_no'] ?? ''));
$nickname = trim((string)($_POST['nickname'] ?? ''));
$birthdate = trim((string)($_POST['birthdate'] ?? ''));
$birthplace = trim((string)($_POST['birthplace'] ?? ''));
$credentials = trim((string)($_POST['credential_notes'] ?? ''));
$date = DateTime::createFromFormat('!Y-m-d', $birthdate);
$validDate = $date instanceof DateTime && $date->format('Y-m-d') === $birthdate && $birthdate <= date('Y-m-d');
if ($lastName === '' || strlen($lastName) > 80 || $firstName === '' || strlen($firstName) > 80 || strlen($middleName) > 80 || strlen($suffix) > 20 || $fullName === '' || strlen($fullName) > 200 || $nickname === '' || strlen($nickname) > 80 ||
    !$validDate || $birthplace === '' || strlen($birthplace) > 180 ||
    strlen($credentials) < 20 || strlen($credentials) > 3000 ||
    !preg_match('/^\+639[0-9]{9}$/', $mobile)) {
    json_response(422, ['ok'=>false,'error'=>'Please complete all fields and use a valid Philippine mobile number (+63). Contributor credentials/purpose must be at least 20 characters.']);
}

// Firebase validates the ID token and returns the phone number associated with that verified session.
$ch = curl_init('https://identitytoolkit.googleapis.com/v1/accounts:lookup?key=' . rawurlencode($apiKey));
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode(['idToken'=>$idToken]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 12,
]);
$response = curl_exec($ch);
$httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$identity = is_string($response) ? json_decode($response, true) : null;
$firebaseUser = is_array($identity) ? ($identity['users'][0] ?? null) : null;
$verifiedPhone = is_array($firebaseUser) ? (string)($firebaseUser['phoneNumber'] ?? '') : '';
if ($httpStatus !== 200 || !is_array($firebaseUser) || !preg_match('/^\+639[0-9]{9}$/', $verifiedPhone) || !hash_equals($mobile, $verifiedPhone)) {
    json_response(401, ['ok'=>false,'error'=>'Firebase could not confirm that mobile number. Verify the SMS code and try again.']);
}

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE mobile_no = ? LIMIT 1');
    $stmt->execute([$verifiedPhone]);
    if ($stmt->fetch()) {
        $pdo->rollBack();
        json_response(409, ['ok'=>false,'error'=>'That mobile number is already registered.']);
    }
    // The current database schema requires a unique email, so phone-only accounts use a non-contact placeholder.
    $email = preg_replace('/[^0-9]/', '', $verifiedPhone) . '@phone.local';
    $temporaryPasswordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users(full_name,email,password_hash,role,account_status,credential_notes,mobile_no,nickname,birthdate,birthplace,email_verified_at,phone_verified_at) VALUES(?,?,?,'creator','pending',?,?,?,?,?,NULL,NOW())");
    $stmt->execute([$fullName,$email,$temporaryPasswordHash,$credentials,$verifiedPhone,$nickname,$birthdate,$birthplace]);
    $userId = (int)$pdo->lastInsertId();
    $pdo->commit();
    session_regenerate_id(true);
    $_SESSION['password_setup_user_id'] = $userId;
    json_response(200, ['ok'=>true,'redirect'=>'set-password.php']);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(409, ['ok'=>false,'error'=>'That mobile number may already be registered, or registration is temporarily unavailable.']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(500, ['ok'=>false,'error'=>'Registration could not be completed. Please try again.']);
}
