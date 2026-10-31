<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

// Only clear the session selected by ?scope=admin or ?scope=creator.
// The other role uses a different cookie name and remains signed in.
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'] ?: '/',
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => 'Lax',
    ]);
}
session_destroy();
header('Location: ../index.php?signin=1');
exit;
