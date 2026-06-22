<?php
require __DIR__ . '/../vendor/autoload.php';
use App\Controllers\KPIsController;
use App\Controllers\LeadController;
use App\Middleware\AuthMiddleware;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// CORS
$allowedOrigin = $_ENV['FRONTEND_URL'] ?? '*';
header("Access-Control-Allow-Origin: $allowedOrigin");
header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Vary: Origin');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

AuthMiddleware::verify();

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
$mysqli->set_charset('utf8mb4');

$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

$routes = [
    'GET /api/kpis'            => [KPIsController::class, 'resumen'],
    'GET /api/kpis/cola'       => [KPIsController::class, 'colaLeads'],
    'GET /api/kpis/horas'      => [KPIsController::class, 'kpiPorHora'],
    'GET /api/kpis/horas-pico' => [KPIsController::class, 'horasPico'],
    'GET /api/kpis/ab'         => [KPIsController::class, 'abTests'],
    'GET /api/leads'           => [LeadController::class, 'index'],
    'GET /api/leads/cola'      => [LeadController::class, 'colaPrioridad'],
];

// Rutas con parámetro numérico: /api/leads/{id}[/action]
$key = "$method $uri";

if (isset($routes[$key])) {
    [$class, $method_name] = $routes[$key];
    $controller = new $class($mysqli);
    $controller->$method_name();
} elseif (preg_match('#^/api/leads/(\d+)(/score|/estado)?$#', $uri, $m)) {
    $id         = (int) $m[1];
    $action     = $m[2] ?? '';
    $controller = new LeadController($mysqli);

    match (true) {
        $action === '/score'  && $method === 'POST'  => $controller->recalcularScore($id),
        $action === '/estado' && $method === 'PATCH' => $controller->actualizarEstado($id),
        $action === ''        && $method === 'GET'   => $controller->show($id),
        default => (function() use ($uri) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Not found', 'uri' => $uri]);
        })(),
    };
} else {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not found', 'uri' => $uri]);
}
