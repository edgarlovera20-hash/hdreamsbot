#!/usr/bin/php
<?php
require __DIR__ . '/../vendor/autoload.php';

use App\Services\LeadAutomationService;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$lockFile = sys_get_temp_dir() . '/hdreams-workflow-automation.lock';
$lock = fopen($lockFile, 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    echo "[" . date('Y-m-d H:i:s') . "] Ya hay una instancia corriendo, saliendo.\n";
    exit(0);
}

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
$mysqli->set_charset('utf8mb4');
$automation = new LeadAutomationService($mysqli);

echo "[" . date('Y-m-d H:i:s') . "] Ejecutando workflow automation...\n";
$processed = 0;

$result24h = $mysqli->query(
    "SELECT i.id AS interview_id, i.lead_id, i.empresa_id, i.interview_date, i.interview_time,
            l.id, l.seccion_id, l.canal, l.canal_user_id, l.nombre, l.metadata, l.assigned_recruiter_id,
            s.nombre AS seccion_nombre, r.nombre AS recruiter_nombre
     FROM interviews i
     JOIN leads l ON l.id = i.lead_id
     JOIN secciones s ON s.id = l.seccion_id
     LEFT JOIN recruiters r ON r.id = i.recruiter_id
     WHERE i.status IN ('agendada','confirmada','reagendada')
       AND i.reminder_24h_sent_at IS NULL
       AND TIMESTAMP(i.interview_date, i.interview_time) BETWEEN DATE_ADD(NOW(), INTERVAL 22 HOUR) AND DATE_ADD(NOW(), INTERVAL 26 HOUR)
     LIMIT 50"
);
while ($row = $result24h?->fetch_assoc()) {
    if ($automation->sendTemplate($row, 'reminder_24h', ['interview_id' => (int) $row['interview_id']])) {
        $interviewId = (int) $row['interview_id'];
        $mysqli->query("UPDATE interviews SET reminder_24h_sent_at = NOW() WHERE id = $interviewId");
        $processed++;
    }
}

$result2h = $mysqli->query(
    "SELECT i.id AS interview_id, i.lead_id, i.empresa_id, i.interview_date, i.interview_time,
            l.id, l.seccion_id, l.canal, l.canal_user_id, l.nombre, l.metadata, l.assigned_recruiter_id,
            s.nombre AS seccion_nombre, r.nombre AS recruiter_nombre
     FROM interviews i
     JOIN leads l ON l.id = i.lead_id
     JOIN secciones s ON s.id = l.seccion_id
     LEFT JOIN recruiters r ON r.id = i.recruiter_id
     WHERE i.status IN ('agendada','confirmada','reagendada')
       AND i.reminder_2h_sent_at IS NULL
       AND TIMESTAMP(i.interview_date, i.interview_time) BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 HOUR)
     LIMIT 50"
);
while ($row = $result2h?->fetch_assoc()) {
    if ($automation->sendTemplate($row, 'reminder_2h', ['interview_id' => (int) $row['interview_id']])) {
        $interviewId = (int) $row['interview_id'];
        $mysqli->query("UPDATE interviews SET reminder_2h_sent_at = NOW() WHERE id = $interviewId");
        $processed++;
    }
}

$resultNoShow = $mysqli->query(
    "SELECT i.id AS interview_id, i.lead_id, i.empresa_id, i.interview_date, i.interview_time,
            l.id, l.seccion_id, l.canal, l.canal_user_id, l.nombre, l.metadata, l.assigned_recruiter_id,
            s.nombre AS seccion_nombre, r.nombre AS recruiter_nombre
     FROM interviews i
     JOIN leads l ON l.id = i.lead_id
     JOIN secciones s ON s.id = l.seccion_id
     LEFT JOIN recruiters r ON r.id = i.recruiter_id
     WHERE i.status = 'no_show'
       AND i.no_show_followup_sent_at IS NULL
       AND TIMESTAMP(i.interview_date, i.interview_time) <= DATE_SUB(NOW(), INTERVAL 1 HOUR)
     LIMIT 50"
);
while ($row = $resultNoShow?->fetch_assoc()) {
    if ($automation->sendTemplate($row, 'no_show', ['interview_id' => (int) $row['interview_id']])) {
        $interviewId = (int) $row['interview_id'];
        $mysqli->query("UPDATE interviews SET no_show_followup_sent_at = NOW() WHERE id = $interviewId");
        $processed++;
    }
}

$resultPlaybooks = $mysqli->query(
    "SELECT l.id, l.empresa_id, l.seccion_id, l.canal, l.canal_user_id, l.nombre, l.metadata, l.current_stage,
            l.playbook_key, l.playbook_step, l.playbook_last_run_at, l.last_automation_contact_at, l.last_outbound_at,
            l.ultima_interaccion, l.created_at, l.assigned_recruiter_id, s.nombre AS seccion_nombre
     FROM leads l
     JOIN secciones s ON s.id = l.seccion_id
     WHERE l.estado NOT IN ('rechazado','no_interesado','contratado')
       AND l.current_stage IN ('contactado','calificado','reagendar','no_asistio')
       AND (l.interview_status IS NULL OR l.interview_status NOT IN ('agendada','confirmada'))
     ORDER BY COALESCE(l.last_automation_contact_at, l.last_outbound_at, l.ultima_interaccion, l.created_at) ASC
     LIMIT 100"
);
while ($row = $resultPlaybooks?->fetch_assoc()) {
    if ($automation->runVacancyPlaybook($row)) {
        $processed++;
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Automatizaciones ejecutadas: $processed\n";

flock($lock, LOCK_UN);
fclose($lock);
