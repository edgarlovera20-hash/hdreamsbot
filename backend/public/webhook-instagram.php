<?php
// ponytail: identical pattern to webhook-messenger.php, forced canal='instagram'
require __DIR__ . '/../vendor/autoload.php';
use App\Services\LeadScorerIA;
use App\Services\CanalManager;
use App\Services\ReclutadorIA;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
$mysqli->set_charset('utf8mb4');

if (isset($_GET['hub_mode']) && $_GET['hub_mode'] === 'subscribe') {
    echo ($_GET['hub_verify_token'] === $_ENV['META_VERIFY_TOKEN'])
        ? $_GET['hub_challenge']
        : http_response_code(403);
    exit;
}

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

        $psid     = $event['sender']['id'];
        $texto    = $event['message']['text'];
        $page_id  = $entry['id'] ?? '';
        $page_esc = $mysqli->real_escape_string($page_id);

        $canal_row = $mysqli->query(
            "SELECT * FROM canales WHERE page_id='$page_esc' AND canal='instagram' AND activo=1 LIMIT 1"
        )->fetch_assoc();

        $empresa_id = (int) ($canal_row['empresa_id'] ?? 1);
        $seccion_id = (int) ($canal_row['seccion_id'] ?? 1);

        $psid_esc = $mysqli->real_escape_string($psid);
        $mysqli->query(
            "INSERT INTO leads (empresa_id,seccion_id,canal,canal_user_id,fuente)
             VALUES ($empresa_id,$seccion_id,'instagram','$psid_esc','instagram')
             ON DUPLICATE KEY UPDATE mensajes_recibidos=mensajes_recibidos+1,ultima_interaccion=NOW()"
        );
        $lead_id = $mysqli->insert_id
            ?: (int) $mysqli->query(
                "SELECT id FROM leads WHERE empresa_id=$empresa_id AND canal='instagram' AND canal_user_id='$psid_esc'"
            )->fetch_assoc()['id'];

        (new LeadScorerIA($empresa_id, $seccion_id, $mysqli))->calcularScore($lead_id);

        $hora  = (int) date('H');
        $fecha = date('Y-m-d');
        $mysqli->query(
            "INSERT INTO kpi_horario (empresa_id,seccion_id,canal,fecha,hora,mensajes_recibidos,leads_nuevos)
             VALUES ($empresa_id,$seccion_id,'instagram','$fecha',$hora,1,1)
             ON DUPLICATE KEY UPDATE mensajes_recibidos=mensajes_recibidos+1"
        );

        $manager    = new CanalManager($empresa_id, $seccion_id, $mysqli, $canal_row ?? []);
        $reclutador = new ReclutadorIA($empresa_id, $seccion_id, $mysqli);

        $log_id   = $manager->procesarMensaje($psid, $texto);
        $respuesta = $reclutador->responder($lead_id, $texto, $log_id);
        $manager->enviarRespuesta($psid, $respuesta);
    }
}

http_response_code(200);
echo json_encode(['status' => 'ok']);
