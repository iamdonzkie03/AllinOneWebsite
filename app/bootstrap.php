<?php
declare(strict_types=1);

$configPath = __DIR__ . '/../config/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    exit('Configuration missing. Copy config/config.example.php to config/config.php.');
}
$config = require $configPath;

// Keep administrator and creator authentication in separate browser cookies.
// Each protected area selects its own session based on the current route.
$scriptName = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
$requestedContext = (string)($_GET['session_context'] ?? $_GET['scope'] ?? '');
if (in_array($requestedContext, ['admin', 'creator', 'public'], true)) {
    $sessionContext = $requestedContext;
} elseif ($scriptName === 'admin.php') {
    $sessionContext = 'admin';
} elseif (in_array($scriptName, ['workspace.php', 'content-edit.php', 'content-delete.php'], true)) {
    $sessionContext = 'creator';
} else {
    $sessionContext = 'public';
}

function configure_app_session(string $context): void {
    global $config;
    $baseName = (string)$config['app']['session_name'];
    session_name(substr($baseName . '_' . strtoupper($context), 0, 128));
    session_set_cookie_params([
        'path' => '/',
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
    ]);
}
configure_app_session($sessionContext);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/**
 * Switch from the public sign-in session into the independent role session.
 * The public session remains separate, so logging out of one role cannot
 * invalidate the other role's cookie or server-side session.
 */
function switch_app_session_context(string $context): void {
    if (!in_array($context, ['admin', 'creator'], true)) {
        throw new InvalidArgumentException('Invalid session context.');
    }
    global $config;
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    configure_app_session($context);
    session_id('');
    session_start();
    session_regenerate_id(true);
}

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
