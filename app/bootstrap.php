<?php
declare(strict_types=1);

$configPath = __DIR__ . '/../config/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    exit('Configuration missing. Copy config/config.example.php to config/config.php.');
}
$config = require $configPath;
session_name($config['app']['session_name']);
session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$db = $config['db'];
$dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $db['host'], $db['name'], $db['charset']);
$pdo = new PDO($dsn, $db['user'], $db['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

function e(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function current_user(): ?array { return $_SESSION['user'] ?? null; }
function require_login(): array {
    $user = current_user();
    if (!$user) { header('Location: login.php'); exit; }
    return $user;
}
function require_admin(): array {
    $user = require_login();
    if (($user['role'] ?? '') !== 'system_admin') { http_response_code(403); exit('Forbidden'); }
    return $user;
}
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function verify_csrf(): void {
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419); exit('Invalid security token. Refresh and try again.');
    }
}
