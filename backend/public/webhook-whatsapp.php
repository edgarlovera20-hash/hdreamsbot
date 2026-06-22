<?php
require __DIR__ . '/../vendor/autoload.php';
use App\Services\LeadScorerIA;
use App\Services\CanalManager;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
$mysqli->set_charset('utf8mb4');

// -------------------------------------------------------
// GET: verificación de webhook Meta
// -------------------------------------------------------
if (isset($_GET['hub_mode']) && $_GET['hub_mode'] === 'subscribe') {
    if ($_GET['hub_verify_token'] === $_ENV['META_VERIFY_TOKEN']) {
        echo $_GET['hub_challenge'];
    } else {
        http_response_code(403);
    }
    exit;
}

// -------------------------------------------------------
// POST: verificar firma X-Hub-Signature-256
// -------------------------------------------------------
$rawBody = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$expected  = 'sha256=' . hash_hmac('sha256', $rawBody, $_ENV['META_APP_SECRET']);

if (!$sigHeader || !hash_equals($expected, $sigHeader)) {
    http_response_code(403);
    exit;
}

// -------------------------------------------------------
// Payload entrante
// -------------------------------------------------------
$input = json_decode($rawBody, true);
if (!isset($input['entry'][0]['changes'][0]['value']['messages'][0])) {
    http_response_code(200);
    exit;
}

$value  = $input['entry'][0]['changes'][0]['value'];
$msg    = $value['messages'][0];
$wa_id  = $msg['from'];
$tipo   = $msg['type'];
$texto  = match ($tipo) {
    'text'  => $msg['text']['body'] ?? '',
    'audio' => '[audio]',
    'image' => '[imagen]',
    default => '[' . $tipo . ']',
};
$nombre = $value['contacts'][0]['profile']['name'] ?? 'Desconocido';

// -------------------------------------------------------
// Resolver empresa/sección por número de teléfono destino
// -------------------------------------------------------
$display_phone = $value['metadata']['display_phone_number'] ?? '';
$phone_esc     = $mysqli->real_escape_string($display_phone);
$canal_row     = $mysqli->query(
    "SELECT * FROM canales WHERE canal='whatsapp' AND activo=1
     AND JSON_UNQUOTE(JSON_EXTRACT(config,'$.phone_number')) = '$phone_esc' LIMIT 1"
)->fetch_assoc();

$empresa_id = (int) ($canal_row['empresa_id'] ?? 1);
$seccion_id = (int) ($canal_row['seccion_id'] ?? 1);

// -------------------------------------------------------
// Upsert lead
// -------------------------------------------------------
$nombre_esc = $mysqli->real_escape_string($nombre);
$wa_esc     = $mysqli->real_escape_string($wa_id);

$mysqli->query(
    "INSERT INTO leads (empresa_id,seccion_id,canal,canal_user_id,nombre,fuente)
     VALUES ($empresa_id,$seccion_id,'whatsapp','$wa_esc','$nombre_esc','organic')
     ON DUPLICATE KEY UPDATE mensajes_recibidos=mensajes_recibidos+1,ultima_interaccion=NOW()"
);

$lead_id = $mysqli->insert_id
    ?: (int) $mysqli->query("SELECT id FROM leads WHERE empresa_id=$empresa_id AND canal='whatsapp' AND canal_user_id='$wa_esc'")->fetch_assoc()['id'];

// -------------------------------------------------------
// Extracción de datos del mensaje
// -------------------------------------------------------
if (preg_match('/\b(1[7-9]|2\d|3[0-5])\b/', $texto, $m)) {
    $mysqli->query("UPDATE leads SET edad={$m[1]} WHERE id=$lead_id AND edad IS NULL");
}
if (preg_match('/\b\d{10}\b/', $texto, $m)) {
    $tel = $m[0];
    $mysqli->query("UPDATE leads SET telefono='$tel' WHERE id=$lead_id AND telefono IS NULL");
}
if (preg_match('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', $texto, $m)) {
    $email = $mysqli->real_escape_string($m[0]);
    $mysqli->query("UPDATE leads SET email='$email' WHERE id=$lead_id AND email IS NULL");
}

// -------------------------------------------------------
// Scoring IA
// -------------------------------------------------------
$scorer = new LeadScorerIA($empresa_id, $seccion_id, $mysqli);
$score  = $scorer->calcularScore($lead_id);

// -------------------------------------------------------
// KPI horario
// -------------------------------------------------------
$hora  = (int) date('H');
$fecha = date('Y-m-d');
$mysqli->query(
    "INSERT INTO kpi_horario (empresa_id,seccion_id,canal,fecha,hora,mensajes_recibidos,leads_nuevos)
     VALUES ($empresa_id,$seccion_id,'whatsapp','$fecha',$hora,1,1)
     ON DUPLICATE KEY UPDATE mensajes_recibidos=mensajes_recibidos+1"
);

// -------------------------------------------------------
// Respuesta IA (placeholder — reemplazar con GPT chain)
// -------------------------------------------------------
$lead_row = $mysqli->query("SELECT * FROM leads WHERE id=$lead_id")->fetch_assoc();

$respuesta = empty($lead_row['edad'])
    ? "Hola $nombre, soy Lic. Gissell de RH en Heavenly Dreams. ¿Qué edad tienes?"
    : "Gracias $nombre. ¿Tienes experiencia en ventas?";

// -------------------------------------------------------
// Enviar respuesta + notificación urgente al reclutador
// -------------------------------------------------------
$canal_cfg = $canal_row ?? $mysqli->query(
    "SELECT * FROM canales WHERE empresa_id=$empresa_id AND seccion_id=$seccion_id AND canal='whatsapp' LIMIT 1"
)->fetch_assoc();

$manager = new CanalManager($empresa_id, $seccion_id, $mysqli, $canal_cfg);
$manager->procesarMensaje($wa_id, $texto);
$manager->enviarRespuesta($wa_id, $respuesta);

if ($score && $score['score_prioridad'] >= 80) {
    $reclutador = $_ENV['RECRUITER_PHONE'] ?? '';
    if ($reclutador) {
        $sp  = $score['score_prioridad'];
        $raz = $score['razonamiento'];
        $manager->enviarRespuesta(
            $reclutador,
            "🚨 LEAD URGENTE\nNombre: $nombre\nScore: $sp/100\n$raz\nVer: /leads/$lead_id"
        );
    }
}

http_response_code(200);
echo json_encode(['status' => 'ok']);
