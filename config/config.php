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
        'api_key' => 'AIzaSyAnXVP2H3zHNd4Tw2IxPVoou6Xe_qXGJW0',
        'auth_domain' => 'allinoneweb-fd1e3.firebaseapp.com',
        'project_id' => 'allinoneweb-fd1e3',
        'app_id' => '1:213895413580:web:b976884b8d2cf165d0d9e9',
    ],
    // Configure SMTP locally. Do not commit real SMTP credentials.
    'mail' => [
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
