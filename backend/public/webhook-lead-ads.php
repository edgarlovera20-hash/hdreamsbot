<?php
require __DIR__ . '/../vendor/autoload.php';
use App\Services\LeadScorerIA;
use App\Services\CanalManager;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
$mysqli->set_charset('utf8mb4');

// Verificación webhook
if (isset($_GET['hub_mode']) && $_GET['hub_mode'] === 'subscribe') {
    if ($_GET['hub_verify_token'] === $_ENV['META_VERIFY_TOKEN']) {
        echo $_GET['hub_challenge'];
    } else {
        http_response_code(403);
    }
    exit;
}

// -------------------------------------------------------
// Verificar firma X-Hub-Signature-256
// -------------------------------------------------------
$rawBody   = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$expected  = 'sha256=' . hash_hmac('sha256', $rawBody, $_ENV['META_APP_SECRET']);

if (!$sigHeader || !hash_equals($expected, $sigHeader)) {
    http_response_code(403);
    exit;
}

$input = json_decode($rawBody, true);

if (!isset($input['entry'][0]['changes'][0]['value'])) {
    http_response_code(200);
    exit;
}

$data = $input['entry'][0]['changes'][0]['value'];

if (($data['item'] ?? '') !== 'leadgen') {
    http_response_code(200);
    exit;
}

$leadgen_id = $data['leadgen_id'];
$page_id    = $data['page_id'];
$form_id    = $data['form_id'] ?? '';
$ad_id      = $data['ad_id'] ?? '';

// Resolver canal por page_id
$page_esc  = $mysqli->real_escape_string($page_id);
$canal_row = $mysqli->query("SELECT * FROM canales WHERE page_id='$page_esc' AND canal='facebook' AND activo=1")->fetch_assoc();

if (!$canal_row) {
    http_response_code(200);
    exit;
}

$empresa_id = (int) $canal_row['empresa_id'];
$seccion_id = (int) $canal_row['seccion_id'];
$token      = $canal_row['access_token'];

// Obtener datos del lead desde Graph API
$leadgen_esc = $mysqli->real_escape_string($leadgen_id);
$url         = "https://graph.facebook.com/v25.0/{$leadgen_id}?access_token={$token}";
$ch          = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = json_decode(curl_exec($ch), true);
curl_close($ch);

$datos = [];
foreach ($response['field_data'] ?? [] as $field) {
    $datos[$field['name']] = $field['values'][0] ?? '';
}

$nombre   = $datos['full_name']     ?? $datos['first_name'] ?? '';
$email    = $datos['email']         ?? '';
$telefono = $datos['phone_number']  ?? '';
$datos_json = $mysqli->real_escape_string(json_encode($datos));
$ad_id_esc  = $mysqli->real_escape_string($ad_id);
$form_esc   = $mysqli->real_escape_string($form_id);
$nom_esc    = $mysqli->real_escape_string($nombre);
$email_esc  = $mysqli->real_escape_string($email);
$tel_esc    = $mysqli->real_escape_string($telefono);

// Guardar en fb_lead_ads
$mysqli->query(
    "INSERT IGNORE INTO fb_lead_ads
       (empresa_id,seccion_id,leadgen_id,ad_id,form_id,nombre,email,telefono,datos_adicionales)
     VALUES ($empresa_id,$seccion_id,'$leadgen_esc','$ad_id_esc','$form_esc','$nom_esc','$email_esc','$tel_esc','$datos_json')"
);
$fla_id = $mysqli->insert_id;

// Crear o actualizar lead
$canal_user = $leadgen_id; // identificador único para leads de Facebook
$mysqli->query(
    "INSERT INTO leads (empresa_id,seccion_id,canal,canal_user_id,nombre,email,telefono,fuente,fb_leadgen_id)
     VALUES ($empresa_id,$seccion_id,'facebook','$leadgen_esc','$nom_esc','$email_esc','$tel_esc','fb_lead_ad','$leadgen_esc')
     ON DUPLICATE KEY UPDATE mensajes_recibidos=mensajes_recibidos+1,ultima_interaccion=NOW()"
);
$lead_id = $mysqli->insert_id
    ?: (int) $mysqli->query("SELECT id FROM leads WHERE fb_leadgen_id='$leadgen_esc'")->fetch_assoc()['id'];

// Vincular
if ($fla_id) {
    $mysqli->query("UPDATE fb_lead_ads SET lead_id=$lead_id,procesado=1 WHERE id=$fla_id");
}

// Scoring IA
$scorer = new LeadScorerIA($empresa_id, $seccion_id, $mysqli);
$score  = $scorer->calcularScore($lead_id);

// KPI
$hora  = (int) date('H');
$fecha = date('Y-m-d');
$mysqli->query(
    "INSERT INTO kpi_horario (empresa_id,seccion_id,canal,fecha,hora,leads_nuevos)
     VALUES ($empresa_id,$seccion_id,'facebook','$fecha',$hora,1)
     ON DUPLICATE KEY UPDATE leads_nuevos=leads_nuevos+1"
);

// Notificación urgente al reclutador por WhatsApp
if ($score && $score['score_prioridad'] >= 80) {
    $wa_canal = $mysqli->query(
        "SELECT * FROM canales WHERE empresa_id=$empresa_id AND seccion_id=$seccion_id AND canal='whatsapp' AND activo=1 LIMIT 1"
    )->fetch_assoc();

    if ($wa_canal) {
        $manager = new CanalManager($empresa_id, $seccion_id, $mysqli, $wa_canal);
        $sp      = $score['score_prioridad'];
        $raz     = $score['razonamiento'];
        $reclutador = $_ENV['RECRUITER_PHONE'] ?? '';
        if ($reclutador) {
            $manager->enviarRespuesta(
                $reclutador,
                "🚨 LEAD FB URGENTE\nNombre: $nombre\nEmail: $email\nTel: $telefono\nScore: $sp/100\n$raz"
            );
        }
    }
}

http_response_code(200);
echo json_encode(['status' => 'ok', 'lead_id' => $lead_id]);
