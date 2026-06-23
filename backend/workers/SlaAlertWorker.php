#!/usr/bin/php
<?php
require __DIR__ . '/../vendor/autoload.php';

use App\Services\CanalManager;
use App\Services\NotificationService;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$lockFile = sys_get_temp_dir() . '/hdreams-sla-alerts.lock';
$lock = fopen($lockFile, 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    echo "[" . date('Y-m-d H:i:s') . "] Ya hay una instancia corriendo, saliendo.\n";
    exit(0);
}

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
$mysqli->set_charset('utf8mb4');
$notifications = new NotificationService($mysqli);

echo "[" . date('Y-m-d H:i:s') . "] Revisando alertas SLA...\n";

$result = $mysqli->query(
    "SELECT l.id, l.empresa_id, l.seccion_id, l.nombre, l.prioridad, l.current_stage, l.next_action_at,
            l.assigned_recruiter_id, r.nombre AS recruiter_nombre, r.telefono AS recruiter_telefono
     FROM leads l
     LEFT JOIN recruiters r ON r.id = l.assigned_recruiter_id
     WHERE l.estado NOT IN ('rechazado','no_interesado','contratado')
       AND l.next_action_at IS NOT NULL
       AND l.next_action_at < NOW()
       AND NOT EXISTS (
         SELECT 1
         FROM notifications n
         WHERE n.empresa_id = l.empresa_id
           AND n.entity_type = 'lead'
           AND n.entity_id = l.id
           AND n.type = 'sla_overdue'
           AND n.created_at >= DATE_SUB(NOW(), INTERVAL 12 HOUR)
       )
     ORDER BY l.next_action_at ASC
     LIMIT 50"
);

$count = 0;
while ($lead = $result?->fetch_assoc()) {
    $leadId = (int) $lead['id'];
    $empresaId = (int) $lead['empresa_id'];
    $recruiterId = (int) ($lead['assigned_recruiter_id'] ?? 0);

    $message = sprintf(
        'Lead %s (%d) venció SLA en etapa %s con prioridad %s. Acción pendiente desde %s.',
        $lead['nombre'] ?: 'Sin nombre',
        $leadId,
        $lead['current_stage'] ?: 'sin_etapa',
        $lead['prioridad'] ?: 'media',
        $lead['next_action_at']
    );

    $notifications->create([
        'empresa_id' => $empresaId,
        'recruiter_id' => $recruiterId ?: null,
        'type' => 'sla_overdue',
        'title' => 'SLA vencido',
        'message' => $message,
        'severity' => 'critical',
        'entity_type' => 'lead',
        'entity_id' => $leadId,
        'action_url' => "/leads/$leadId",
    ]);

    $telegramToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
    $recruiterPhone = $lead['recruiter_telefono'] ?? ($_ENV['RECRUITER_PHONE'] ?? '');

    if ($telegramToken && $recruiterPhone) {
        try {
            $manager = new CanalManager($empresaId, (int) $lead['seccion_id'], $mysqli, ['canal' => 'telegram']);
            $manager->enviarRespuesta((string) $recruiterPhone, $message);
        } catch (\Throwable $e) {
            echo "❌ Error notificando lead {$leadId}: {$e->getMessage()}\n";
        }
    }

    $count++;
}

echo "[" . date('Y-m-d H:i:s') . "] Alertas generadas: $count\n";

flock($lock, LOCK_UN);
fclose($lock);
