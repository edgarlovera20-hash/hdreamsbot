#!/usr/bin/php
<?php
require __DIR__ . '/../vendor/autoload.php';
use App\Services\LeadScorerIA;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Evitar ejecuciones concurrentes
$lockFile = sys_get_temp_dir() . '/hdreams-recalculate-scores.lock';
$lock = fopen($lockFile, 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    echo "[" . date('Y-m-d H:i:s') . "] Ya hay una instancia corriendo, saliendo.\n";
    exit(0);
}

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
$mysqli->set_charset('utf8mb4');

echo "[" . date('Y-m-d H:i:s') . "] Recalculando scores IA...\n";

$result = $mysqli->query(
    "SELECT id, empresa_id, seccion_id FROM leads
     WHERE estado NOT IN ('contratado','rechazado','no_interesado')
       AND (ultimo_scoring IS NULL OR ultimo_scoring < DATE_SUB(NOW(), INTERVAL 6 HOUR))
     ORDER BY ultima_interaccion DESC
     LIMIT 50"
);

$count = 0;
while ($lead = $result->fetch_assoc()) {
    try {
        $scorer = new LeadScorerIA((int) $lead['empresa_id'], (int) $lead['seccion_id'], $mysqli);
        $scorer->calcularScore((int) $lead['id']);
        echo "✅ Lead {$lead['id']} recalculado\n";
        $count++;
    } catch (\Throwable $e) {
        echo "❌ Error en lead {$lead['id']}: {$e->getMessage()}\n";
    }
    sleep(1);
}

echo "[" . date('Y-m-d H:i:s') . "] Completado — $count leads procesados\n";

flock($lock, LOCK_UN);
fclose($lock);
