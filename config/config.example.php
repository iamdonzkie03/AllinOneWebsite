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
    'mail' => [
        'username' => 'your-gmail@gmail.com',
        'app_password' => 'your-16-character-google-app-password',
        'from' => 'your-gmail@gmail.com',
        'from_name' => 'Z.C. commonplace',
    ],
    'app' => [
        'name' => 'Commonplace',
        'base_url' => 'http://localhost/allinoneweb',
        'session_name' => 'allinoneweb_session',
    ],
];
