<?php
require __DIR__ . '/../vendor/autoload.php';
use App\Controllers\AuthController;
use App\Controllers\KPIsController;
use App\Controllers\LeadController;
use App\Controllers\ConversacionesController;
use App\Middleware\AuthMiddleware;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

header('Content-Type: application/json');

// CORS
$allowedOrigin = $_ENV['FRONTEND_URL'] ?? '*';
header("Access-Control-Allow-Origin: $allowedOrigin");
header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Vary: Origin');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
$mysqli->set_charset('utf8mb4');

$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// ── Rutas públicas (sin sesión) ────────────────────────
if ($method === 'POST' && $uri === '/api/auth/login') {
    (new AuthController($mysqli))->login(); exit;
}

if ($method === 'GET' && $uri === '/api/config') {
    echo json_encode([
        'meta_app_id'      => $_ENV['META_APP_ID']       ?? '',
        'meta_redirect_uri'=> $_ENV['META_REDIRECT_URI']  ?? 'https://bot.heavenlydreams.com.mx/auth/meta/callback',
        'meta_scopes'      => 'pages_show_list,pages_messaging,leads_retrieval,ads_read,pages_read_engagement',
    ]);
    exit;
}

// ── Todas las demás rutas requieren sesión ─────────────
AuthMiddleware::verify($mysqli);

$_GET['empresa_id'] = (int)($_ENV['AUTH_EMPRESA_ID'] ?? 1);

$key = "$method $uri";

// Auth
if ($key === 'GET /api/auth/me')       { (new AuthController($mysqli))->me();           exit; }
if ($key === 'POST /api/auth/logout')  { (new AuthController($mysqli))->logout();        exit; }
if ($key === 'POST /api/auth/empresa') { (new AuthController($mysqli))->switchEmpresa(); exit; }

// Meta OAuth status
if ($key === 'GET /api/meta-status') {
    $row = $mysqli->query(
        "SELECT user_name, expires_at,
                TIMESTAMPDIFF(DAY, NOW(), expires_at) AS dias_restantes,
                pages_json
         FROM meta_oauth_tokens ORDER BY updated_at DESC LIMIT 1"
    )->fetch_assoc();
    echo json_encode($row ? [
        'connected'      => true,
        'user_name'      => $row['user_name'],
        'expires_at'     => $row['expires_at'],
        'dias_restantes' => (int) $row['dias_restantes'],
        'pages'          => json_decode($row['pages_json'] ?? '[]', true) ?? [],
    ] : ['connected' => false]);
    exit;
}

// API routes
$routes = [
    'GET /api/kpis'                    => [KPIsController::class,            'resumen'],
    'GET /api/kpis/cola'               => [KPIsController::class,            'colaLeads'],
    'GET /api/kpis/horas'              => [KPIsController::class,            'kpiPorHora'],
    'GET /api/kpis/horas-pico'         => [KPIsController::class,            'horasPico'],
    'GET /api/kpis/ab'                 => [KPIsController::class,            'abTests'],
    'GET /api/leads'                   => [LeadController::class,            'index'],
    'GET /api/leads/cola'              => [LeadController::class,            'colaPrioridad'],
    'GET /api/conversaciones'          => [ConversacionesController::class,  'index'],
    'GET /api/conversaciones/mensajes' => [ConversacionesController::class,  'mensajes'],
];

if (isset($routes[$key])) {
    [$class, $method_name] = $routes[$key];
    (new $class($mysqli))->$method_name();
} elseif (preg_match('#^/api/leads/(\d+)(/score|/estado)?$#', $uri, $m)) {
    $id         = (int) $m[1];
    $action     = $m[2] ?? '';
    $controller = new LeadController($mysqli);

    match (true) {
        $action === '/score'  && $method === 'POST'  => $controller->recalcularScore($id),
        $action === '/estado' && $method === 'PATCH' => $controller->actualizarEstado($id),
        $action === ''        && $method === 'GET'   => $controller->show($id),
        default => (function () use ($uri) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found', 'uri' => $uri]);
        })(),
    };
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Not found', 'uri' => $uri]);
}
