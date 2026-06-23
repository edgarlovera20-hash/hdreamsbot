<?php
// Script de un solo uso para crear el usuario admin inicial
// Ejecutar: docker compose exec php php /var/www/hdreams-backend/setup_admin.php
// Eliminar este archivo despues de usarlo

require __DIR__ . '/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$db = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);

$email  = 'operaciones@heavenlydreams.mx';
$pass   = 'HdAdmin2026#';
$nombre = 'Admin';
$rol    = 'admin';
$hash   = password_hash($pass, PASSWORD_DEFAULT);

$stmt = $db->prepare(
    'INSERT IGNORE INTO usuarios (email, password_hash, nombre, rol) VALUES (?, ?, ?, ?)'
);
$stmt->bind_param('ssss', $email, $hash, $nombre, $rol);
$stmt->execute();

echo $stmt->affected_rows > 0
    ? "Usuario creado: $email / $pass\n"
    : "Usuario ya existia.\n";
