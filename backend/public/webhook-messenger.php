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
    echo ($_GET['hub_verify_token'] === $_ENV['META_VERIFY_TOKEN'])
        ? $_GET['hub_challenge']
        : http_response_code(403);
    exit;
}

// -------------------------------------------------------
// POST: verificar firma X-Hub-Signature-256
// -------------------------------------------------------
$rawBody   = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$expected  = 'sha256=' . hash_hmac('sha256', $rawBody, $_ENV['META_APP_SECRET']);

if (!$sigHeader || !hash_equals($expected, $sigHeader)) {
    http_response_code(403);
    exit;
}

$input = json_decode($rawBody, true);

foreach ($input['entry'] ?? [] as $entry) {
    foreach ($entry['messaging'] ?? [] as $event) {
        if (!isset($event['message']['text'])) continue;

        $psid  = $event['sender']['id'];
        $texto = $event['message']['text'];
        $canal = $input['object'] === 'instagram' ? 'instagram' : 'messenger';

        // Resolver empresa/sección por page_id
        $page_id  = $entry['id'] ?? '';
        $page_esc = $mysqli->real_escape_string($page_id);
        $canal_row = $mysqli->query(
            "SELECT * FROM canales WHERE page_id='$page_esc' AND canal='$canal' AND activo=1 LIMIT 1"
        )->fetch_assoc();

        $empresa_id = (int) ($canal_row['empresa_id'] ?? 1);
        $seccion_id = (int) ($canal_row['seccion_id'] ?? 1);

        $psid_esc = $mysqli->real_escape_string($psid);
        $mysqli->query(
            "INSERT INTO leads (empresa_id,seccion_id,canal,canal_user_id,fuente)
             VALUES ($empresa_id,$seccion_id,'$canal','$psid_esc','$canal')
             ON DUPLICATE KEY UPDATE mensajes_recibidos=mensajes_recibidos+1,ultima_interaccion=NOW()"
        );
        $lead_id = $mysqli->insert_id
            ?: (int) $mysqli->query("SELECT id FROM leads WHERE empresa_id=$empresa_id AND canal='$canal' AND canal_user_id='$psid_esc'")->fetch_assoc()['id'];

        $scorer = new LeadScorerIA($empresa_id, $seccion_id, $mysqli);
        $scorer->calcularScore($lead_id);

        $hora  = (int) date('H');
        $fecha = date('Y-m-d');
        $mysqli->query(
            "INSERT INTO kpi_horario (empresa_id,seccion_id,canal,fecha,hora,mensajes_recibidos,leads_nuevos)
             VALUES ($empresa_id,$seccion_id,'$canal','$fecha',$hora,1,1)
             ON DUPLICATE KEY UPDATE mensajes_recibidos=mensajes_recibidos+1"
        );

        $manager   = new CanalManager($empresa_id, $seccion_id, $mysqli, $canal_row);
        $respuesta = "Hola, soy Lic. Gissell de RH. ¿Qué edad tienes y en qué ciudad estás?";
        $manager->procesarMensaje($psid, $texto);
        $manager->enviarRespuesta($psid, $respuesta);
    }
}

http_response_code(200);
echo json_encode(['status' => 'ok']);
