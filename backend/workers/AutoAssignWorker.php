#!/usr/bin/php
<?php
require __DIR__ . '/../vendor/autoload.php';

use App\Services\RecruitmentCopilotService;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$lockFile = sys_get_temp_dir() . '/hdreams-auto-assign.lock';
$lock = fopen($lockFile, 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    echo "[" . date('Y-m-d H:i:s') . "] Ya hay una instancia corriendo, saliendo.\n";
    exit(0);
}

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
$mysqli->set_charset('utf8mb4');
$copilot = new RecruitmentCopilotService($mysqli);

echo "[" . date('Y-m-d H:i:s') . "] Ejecutando autoasignación IA...\n";

$result = $mysqli->query(
    "SELECT id
     FROM leads
     WHERE assigned_recruiter_id IS NULL
       AND estado NOT IN ('rechazado','no_interesado','contratado')
       AND prioridad IN ('urgente','alta')
     ORDER BY FIELD(prioridad, 'urgente', 'alta'), COALESCE(next_action_at, ultima_interaccion) ASC
     LIMIT 50"
);

$count = 0;
while ($row = $result?->fetch_assoc()) {
    $assignment = $copilot->autoAssignLead((int) $row['id']);
    if ($assignment) {
        $count++;
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Leads autoasignados: $count\n";

flock($lock, LOCK_UN);
fclose($lock);
