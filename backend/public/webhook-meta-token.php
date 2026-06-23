<?php
require __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Solo accesible desde Baileys via nginx:8080
$secret = $_SERVER['HTTP_X_BAILEYS_SECRET'] ?? '';
if (!$secret || $secret !== ($_ENV['BAILEYS_SECRET'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
$mysqli->set_charset('utf8mb4');

$input     = json_decode(file_get_contents('php://input'), true) ?? [];
$user_id   = $mysqli->real_escape_string($input['user_id']      ?? '');
$user_name = $mysqli->real_escape_string($input['user_name']    ?? '');
$token     = $mysqli->real_escape_string($input['access_token'] ?? '');
$expires   = (int) ($input['expires_in']  ?? 5184000); // 60 días por defecto
$pages_esc = $mysqli->real_escape_string(json_encode($input['pages'] ?? []));

if (!$user_id || !$token) {
    http_response_code(400);
    echo json_encode(['error' => 'Datos incompletos']);
    exit;
}

$mysqli->query(
    "INSERT INTO meta_oauth_tokens (user_id, user_name, access_token, expires_at, pages_json)
     VALUES ('$user_id','$user_name','$token', DATE_ADD(NOW(), INTERVAL $expires SECOND), '$pages_esc')
     ON DUPLICATE KEY UPDATE
       user_name    = VALUES(user_name),
       access_token = VALUES(access_token),
       expires_at   = VALUES(expires_at),
       pages_json   = VALUES(pages_json)"
);

http_response_code(200);
echo json_encode(['ok' => true, 'pages' => count($input['pages'] ?? [])]);
