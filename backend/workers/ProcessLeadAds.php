#!/usr/bin/php
<?php
require __DIR__ . '/../vendor/autoload.php';
use App\Services\LeadScorerIA;
use App\Services\CanalManager;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Evitar ejecuciones concurrentes
$lockFile = sys_get_temp_dir() . '/hdreams-process-lead-ads.lock';
$lock = fopen($lockFile, 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    echo "[" . date('Y-m-d H:i:s') . "] Ya hay una instancia corriendo, saliendo.\n";
    exit(0);
}

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
$mysqli->set_charset('utf8mb4');

echo "[" . date('Y-m-d H:i:s') . "] Procesando Lead Ads pendientes...\n";

$result = $mysqli->query(
    "SELECT fla.*, l.id AS lead_id
     FROM fb_lead_ads fla
     LEFT JOIN leads l ON l.fb_leadgen_id = fla.leadgen_id
     WHERE fla.procesado = 0
     ORDER BY fla.created_at ASC
     LIMIT 20"
);

$count = 0;
while ($row = $result->fetch_assoc()) {
    $empresa_id = (int) $row['empresa_id'];
    $seccion_id = (int) $row['seccion_id'];
    $lead_id    = (int) $row['lead_id'];
    $nombre     = $row['nombre'] ?: 'Candidato';
    $telefono   = $row['telefono'];

    $wa_canal = $mysqli->query(
        "SELECT * FROM canales WHERE empresa_id=$empresa_id AND seccion_id=$seccion_id AND canal='whatsapp' AND activo=1 LIMIT 1"
    )->fetch_assoc();

    if ($wa_canal && $telefono) {
        try {
            $manager = new CanalManager($empresa_id, $seccion_id, $mysqli, $wa_canal);
            $manager->enviarRespuesta(
                $telefono,
                "Hola $nombre, somos Heavenly Dreams. Vimos tu solicitud. ¿Tienes disponibilidad para una entrevista esta semana?"
            );
            echo "✅ Saludo enviado a lead {$row['leadgen_id']}\n";
        } catch (\Throwable $e) {
            echo "❌ Error enviando a {$row['leadgen_id']}: {$e->getMessage()}\n";
        }
    }

    $fla_id = (int) $row['id'];
    $mysqli->query("UPDATE fb_lead_ads SET procesado=1 WHERE id=$fla_id");
    $count++;
}

echo "[" . date('Y-m-d H:i:s') . "] Completado — $count leads procesados\n";

flock($lock, LOCK_UN);
fclose($lock);
