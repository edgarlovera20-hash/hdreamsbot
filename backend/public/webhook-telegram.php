<?php
require __DIR__ . '/../vendor/autoload.php';
use App\Services\LeadScorerIA;
use App\Services\ReclutadorIA;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
$mysqli->set_charset('utf8mb4');

$botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
if (!$botToken) { http_response_code(200); exit; }

// ── Verificar secret_token en header ──────────────────────────
$secretHeader = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
$configuredSecret = $_ENV['TELEGRAM_WEBHOOK_SECRET'] ?? '';
if ($configuredSecret && $secretHeader !== $configuredSecret) {
    http_response_code(403); exit;
}

$rawBody = file_get_contents('php://input');
$update  = json_decode($rawBody, true);

if (!isset($update['message'])) { http_response_code(200); exit; }

$msg    = $update['message'];
$chat   = $msg['chat'];
$from   = $msg['from'] ?? [];
$chatId = (string) $chat['id'];
$texto  = $msg['text'] ?? ('[' . ($msg['type'] ?? 'media') . ']');
$nombre = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? '')) ?: 'Candidato';

// ── Resolver empresa/sección via canal Telegram ────────────────
$canal_row = $mysqli->query(
    "SELECT * FROM canales WHERE canal='telegram' AND activo=1 LIMIT 1"
)->fetch_assoc();

$empresa_id = (int) ($canal_row['empresa_id'] ?? 1);
$seccion_id = (int) ($canal_row['seccion_id'] ?? 1);

// ── Upsert lead ────────────────────────────────────────────────
$nombre_esc = $mysqli->real_escape_string($nombre);
$chat_esc   = $mysqli->real_escape_string($chatId);

$mysqli->query(
    "INSERT INTO leads (empresa_id,seccion_id,canal,canal_user_id,nombre,fuente)
     VALUES ($empresa_id,$seccion_id,'telegram','$chat_esc','$nombre_esc','organic')
     ON DUPLICATE KEY UPDATE mensajes_recibidos=mensajes_recibidos+1, ultima_interaccion=NOW()"
);
$lead_id = $mysqli->insert_id
    ?: (int) $mysqli->query(
        "SELECT id FROM leads WHERE empresa_id=$empresa_id AND canal='telegram' AND canal_user_id='$chat_esc'"
    )->fetch_assoc()['id'];

// ── Extracción pasiva ──────────────────────────────────────────
if (preg_match('/\b(1[7-9]|2\d|3[0-5])\b/', $texto, $m)) {
    $mysqli->query("UPDATE leads SET edad={$m[1]} WHERE id=$lead_id AND edad IS NULL");
}
if (preg_match('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', $texto, $m)) {
    $email_esc = $mysqli->real_escape_string($m[0]);
    $mysqli->query("UPDATE leads SET email='$email_esc' WHERE id=$lead_id AND email IS NULL");
}

// ── Scoring IA ─────────────────────────────────────────────────
$scorer = new LeadScorerIA($empresa_id, $seccion_id, $mysqli);
$score  = $scorer->calcularScore($lead_id);

// ── KPI horario ────────────────────────────────────────────────
$hora  = (int) date('H');
$fecha = date('Y-m-d');
$mysqli->query(
    "INSERT INTO kpi_horario (empresa_id,seccion_id,canal,fecha,hora,mensajes_recibidos,leads_nuevos)
     VALUES ($empresa_id,$seccion_id,'telegram','$fecha',$hora,1,1)
     ON DUPLICATE KEY UPDATE mensajes_recibidos=mensajes_recibidos+1"
);

// ── Respuesta IA ───────────────────────────────────────────────
$texto_esc = $mysqli->real_escape_string($texto);
$lead_row  = $mysqli->query("SELECT * FROM leads WHERE id=$lead_id")->fetch_assoc();

$reclutador = new ReclutadorIA($empresa_id, $seccion_id, $mysqli);
$respuesta  = $reclutador->responder($lead_id, $texto, $lead_row ?? []);

// ── Log ────────────────────────────────────────────────────────
$resp_esc = $mysqli->real_escape_string($respuesta);
$mysqli->query(
    "INSERT INTO ia_logs (empresa_id,seccion_id,wa_id,pregunta,respuesta,score_antes)
     VALUES ($empresa_id,$seccion_id,'$chat_esc','$texto_esc','$resp_esc',$score)"
);

// ── Enviar respuesta via Telegram Bot API ──────────────────────
$resp_esc_json = json_encode([
    'chat_id' => $chatId,
    'text'    => $respuesta,
    'parse_mode' => 'Markdown',
]);
$ch = curl_init("https://api.telegram.org/bot{$botToken}/sendMessage");
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => $resp_esc_json,
]);
curl_exec($ch);
curl_close($ch);

http_response_code(200);
echo json_encode(['ok' => true]);
