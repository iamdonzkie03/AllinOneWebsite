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
        // Web app configuration values from Firebase Console > Project settings > Your apps.
        // Firebase Web API keys identify the project; do not put service-account private keys here.
        'api_key' => 'your-firebase-web-api-key',
        'auth_domain' => 'your-project-id.firebaseapp.com',
        'project_id' => 'your-firebase-project-id',
        'app_id' => 'your-firebase-web-app-id',
    ],
    'app' => [
        'name' => 'Commonplace',
        'base_url' => 'http://localhost/allinoneweb',
        'session_name' => 'allinoneweb_session',
    ],
];
