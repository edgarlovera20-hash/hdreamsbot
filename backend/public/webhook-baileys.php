<?php
require __DIR__ . '/../vendor/autoload.php';
use App\Services\LeadScorerIA;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Validar secreto interno
$secret = $_SERVER['HTTP_X_BAILEYS_SECRET'] ?? '';
if (!$secret || $secret !== ($_ENV['BAILEYS_SECRET'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
$mysqli->set_charset('utf8mb4');

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$phone = preg_replace('/\D/', '', $input['phone'] ?? '');
$texto = trim($input['text'] ?? '');

if (!$phone || !$texto) {
    http_response_code(200);
    echo json_encode(['ok' => true]);
    exit;
}

// Empresa/sección por defecto (se puede extender si hay múltiples canales Baileys)
$empresa_id = 1;
$seccion_id = 1;

$phone_esc = $mysqli->real_escape_string($phone);

// Upsert lead
$mysqli->query(
    "INSERT INTO leads (empresa_id,seccion_id,canal,canal_user_id,fuente)
     VALUES ($empresa_id,$seccion_id,'whatsapp','$phone_esc','baileys_qr')
     ON DUPLICATE KEY UPDATE mensajes_recibidos=mensajes_recibidos+1, ultima_interaccion=NOW()"
);

$lead_id = $mysqli->insert_id
    ?: (int) $mysqli->query(
        "SELECT id FROM leads WHERE empresa_id=$empresa_id AND canal='whatsapp' AND canal_user_id='$phone_esc'"
    )->fetch_assoc()['id'];

// Extraer datos del mensaje
if (preg_match('/\b(1[7-9]|2\d|3[0-5])\b/', $texto, $m))
    $mysqli->query("UPDATE leads SET edad={$m[1]} WHERE id=$lead_id AND edad IS NULL");

if (preg_match('/\b\d{10}\b/', $texto, $m))
    $mysqli->query("UPDATE leads SET telefono='{$m[0]}' WHERE id=$lead_id AND telefono IS NULL");

if (preg_match('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', $texto, $m)) {
    $email = $mysqli->real_escape_string($m[0]);
    $mysqli->query("UPDATE leads SET email='$email' WHERE id=$lead_id AND email IS NULL");
}

// Respuesta IA
$lead_row = $mysqli->query("SELECT * FROM leads WHERE id=$lead_id")->fetch_assoc();
$nombre   = $lead_row['nombre'] ?? 'amigo';

$respuesta = empty($lead_row['edad'])
    ? "Hola $nombre, soy Lic. Gissell de RH en Heavenly Dreams. ¿Qué edad tienes?"
    : "Gracias $nombre. ¿Tienes experiencia en ventas o atención al cliente?";

// Guardar en ia_logs
$texto_esc     = $mysqli->real_escape_string($texto);
$respuesta_esc = $mysqli->real_escape_string($respuesta);
$mysqli->query(
    "INSERT INTO ia_logs (empresa_id,seccion_id,wa_id,canal,pregunta,respuesta)
     VALUES ($empresa_id,$seccion_id,'$phone_esc','whatsapp','$texto_esc','$respuesta_esc')"
);

// KPI horario
$hora  = (int) date('H');
$fecha = date('Y-m-d');
$mysqli->query(
    "INSERT INTO kpi_horario (empresa_id,seccion_id,canal,fecha,hora,mensajes_recibidos,leads_nuevos)
     VALUES ($empresa_id,$seccion_id,'whatsapp','$fecha',$hora,1,1)
     ON DUPLICATE KEY UPDATE mensajes_recibidos=mensajes_recibidos+1"
);

// Scoring IA
(new LeadScorerIA($empresa_id, $seccion_id, $mysqli))->calcularScore($lead_id);

// Enviar respuesta via Baileys
$baileys_url = getenv('BAILEYS_URL') ?: 'http://baileys:4000';
$ch = curl_init("$baileys_url/send");
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode(['phone' => $phone, 'text' => $respuesta]),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
]);
curl_exec($ch);
curl_close($ch);

http_response_code(200);
echo json_encode(['ok' => true]);
