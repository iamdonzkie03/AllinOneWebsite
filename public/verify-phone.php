<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
http_response_code(501);
echo json_encode(['ok'=>false,'error'=>'Phone verification endpoint is being configured.']);
