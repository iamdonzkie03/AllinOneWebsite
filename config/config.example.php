<?php
declare(strict_types=1);

return [
    'db' => [
        'host' => '127.0.0.1',
        'name' => 'allinoneweb',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
    'firebase' => [
        // Retained for any other Firebase features; registration no longer uses Firebase SMS.
        'api_key' => '',
        'auth_domain' => '',
        'project_id' => '',
        'app_id' => '',
    ],
    'mail' => [
        // Gmail example: smtp.gmail.com, port 587, encryption tls.
        // Use a Gmail App Password, not your normal Google account password.
        'host' => 'smtp.gmail.com',
        'port' => 587,
        'username' => '',
        'password' => '',
        'encryption' => 'tls',
        'from' => '',
        'from_name' => 'Z.C. commonplace',
    ],
    'app' => [
        'name' => 'Commonplace',
        'base_url' => 'http://localhost/allinoneweb',
        'session_name' => 'allinoneweb_session',
    ],
];
