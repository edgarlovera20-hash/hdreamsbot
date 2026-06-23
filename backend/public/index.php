<?php
require __DIR__ . '/../vendor/autoload.php';
use App\Controllers\AuthController;
use App\Controllers\KPIsController;
use App\Controllers\LeadController;
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

// Login — ruta pública (no requiere sesión)
if ($method === 'POST' && $uri === '/api/auth/login') {
    (new AuthController($mysqli))->login();
    exit;
}

// Todas las demás rutas requieren sesión válida
AuthMiddleware::verify($mysqli);

// empresa_id viene de la sesión (sobreescribe cualquier param del cliente)
$_GET['empresa_id'] = (int)($_ENV['AUTH_EMPRESA_ID'] ?? 1);

$key = "$method $uri";

// Rutas de autenticación
if ($key === 'GET /api/auth/me') {
    (new AuthController($mysqli))->me();
    exit;
}
if ($key === 'POST /api/auth/logout') {
    (new AuthController($mysqli))->logout();
    exit;
}
if ($key === 'POST /api/auth/empresa') {
    (new AuthController($mysqli))->switchEmpresa();
    exit;
}

// Rutas de API
$routes = [
    'GET /api/kpis'            => [KPIsController::class, 'resumen'],
    'GET /api/kpis/cola'       => [KPIsController::class, 'colaLeads'],
    'GET /api/kpis/horas'      => [KPIsController::class, 'kpiPorHora'],
    'GET /api/kpis/horas-pico' => [KPIsController::class, 'horasPico'],
    'GET /api/kpis/ab'         => [KPIsController::class, 'abTests'],
    'GET /api/leads'           => [LeadController::class, 'index'],
    'GET /api/leads/cola'      => [LeadController::class, 'colaPrioridad'],
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
