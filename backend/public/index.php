<?php
require __DIR__ . '/../vendor/autoload.php';
use App\Controllers\AuthController;
use App\Controllers\AutomationController;
use App\Controllers\CopilotController;
use App\Controllers\AuditController;
use App\Controllers\KPIsController;
use App\Controllers\KnowledgeController;
use App\Controllers\LeadController;
use App\Controllers\FlowController;
use App\Controllers\InterviewController;
use App\Controllers\AccountsPanelController;
use App\Controllers\AccountsInboxController;
use App\Controllers\AccessController;
use App\Controllers\NotificationsController;
use App\Controllers\OperationsController;
use App\Controllers\PlaybookController;
use App\Controllers\RecruiterController;
use App\Controllers\ReportController;
use App\Controllers\SupervisorController;
use App\Controllers\VoiceController;
use App\Middleware\AuthMiddleware;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

if ($uri === '/healthz' || $uri === '/api/health') {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'ok',
        'service' => 'hdreams-backend',
        'timestamp' => date(DATE_ATOM),
    ]);
    exit;
}

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
$mysqli->set_charset('utf8mb4');

// CORS
$allowedOrigin = $_ENV['FRONTEND_URL'] ?? '*';
header("Access-Control-Allow-Origin: $allowedOrigin");
header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Session-Token');
header('Vary: Origin');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

AuthMiddleware::verify($mysqli, $uri);

$routes = [
    'POST /api/auth/login'      => [AuthController::class, 'login'],
    'GET /api/auth/me'          => [AuthController::class, 'me'],
    'POST /api/auth/logout'     => [AuthController::class, 'logout'],
    'GET /api/kpis'            => [KPIsController::class, 'resumen'],
    'GET /api/kpis/cola'       => [KPIsController::class, 'colaLeads'],
    'GET /api/kpis/horas'      => [KPIsController::class, 'kpiPorHora'],
    'GET /api/kpis/horas-pico' => [KPIsController::class, 'horasPico'],
    'GET /api/kpis/ab'         => [KPIsController::class, 'abTests'],
    'GET /api/flow'            => [FlowController::class, 'overview'],
    'GET /api/flow/funnel'     => [FlowController::class, 'funnel'],
    'GET /api/interview-slots' => [InterviewController::class, 'slots'],
    'GET /api/interviews'      => [InterviewController::class, 'index'],
    'POST /api/interviews'     => [InterviewController::class, 'create'],
    'GET /api/accounts/panel'  => [AccountsPanelController::class, 'summary'],
    'GET /api/accounts/apps'   => [AccountsPanelController::class, 'apps'],
    'POST /api/accounts/apps'  => [AccountsPanelController::class, 'upsertApp'],
    'GET /api/accounts/inbox'  => [AccountsInboxController::class, 'conversations'],
    'GET /api/access/permissions' => [AccessController::class, 'index'],
    'POST /api/access/permissions' => [AccessController::class, 'update'],
    'GET /api/recruiters'      => [RecruiterController::class, 'index'],
    'GET /api/operations/executive' => [OperationsController::class, 'executive'],
    'GET /api/operations/recruiters-sla' => [OperationsController::class, 'recruitersSla'],
    'GET /api/operations/automations' => [AutomationController::class, 'overview'],
    'GET /api/operations/report' => [ReportController::class, 'executiveData'],
    'GET /api/operations/report-pdf' => [ReportController::class, 'executivePdf'],
    'GET /api/operations/supervisor' => [SupervisorController::class, 'realtime'],
    'GET /api/audit-logs'      => [AuditController::class, 'index'],
    'GET /api/notifications'   => [NotificationsController::class, 'index'],
    'GET /api/knowledge/documents' => [KnowledgeController::class, 'documents'],
    'POST /api/knowledge/documents' => [KnowledgeController::class, 'upload'],
    'POST /api/knowledge/ask' => [KnowledgeController::class, 'ask'],
    'GET /api/playbooks' => [PlaybookController::class, 'index'],
    'POST /api/playbooks' => [PlaybookController::class, 'upsert'],
    'GET /api/leads'           => [LeadController::class, 'index'],
    'GET /api/leads/cola'      => [LeadController::class, 'colaPrioridad'],
];

// Rutas con parámetro numérico: /api/leads/{id}[/action]
$key = "$method $uri";

if (isset($routes[$key])) {
    [$class, $method_name] = $routes[$key];
    $controller = new $class($mysqli);
    $controller->$method_name();
} elseif (preg_match('#^/api/leads/(\d+)(/score|/estado|/notes|/events|/copilot|/auto-assign|/voice-note)?$#', $uri, $m)) {
    $id         = (int) $m[1];
        $action     = $m[2] ?? '';
        $controller = new LeadController($mysqli);
        $copilotController = new CopilotController($mysqli);
        $voiceController = new VoiceController($mysqli);

        match (true) {
        $action === '/score'  && $method === 'POST'  => $controller->recalcularScore($id),
        $action === '/estado' && $method === 'PATCH' => $controller->actualizarEstado($id),
        $action === '/notes'  && $method === 'POST'  => $controller->crearNota($id),
        $action === '/events' && $method === 'GET'   => $controller->eventos($id),
        $action === '/copilot' && $method === 'GET'  => $copilotController->analyzeLead($id),
        $action === '/auto-assign' && $method === 'POST' => $copilotController->autoAssign($id),
        $action === '/voice-note' && $method === 'POST' => $voiceController->uploadForLead($id),
        $action === ''        && $method === 'GET'   => $controller->show($id),
        default => (function() use ($uri) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Not found', 'uri' => $uri]);
        })(),
    };
} elseif (preg_match('#^/api/interviews/(\d+)(/confirm|/no-show)?$#', $uri, $m)) {
    $id = (int) $m[1];
    $action = $m[2] ?? '';
    $controller = new InterviewController($mysqli);

    match (true) {
        $action === '/confirm' && $method === 'POST' => $controller->confirm($id),
        $action === '/no-show' && $method === 'POST' => $controller->noShow($id),
        $action === ''         && $method === 'PATCH' => $controller->update($id),
        default => (function() use ($uri) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Not found', 'uri' => $uri]);
        })(),
    };
} elseif (preg_match('#^/api/accounts/inbox/(\d+)(/reply|/assign|/macro)?$#', $uri, $m)) {
    $id = (int) $m[1];
    $action = $m[2] ?? '';
    $controller = new AccountsInboxController($mysqli);

    match (true) {
        $action === '/reply' && $method === 'POST' => $controller->reply($id),
        $action === '/assign' && $method === 'POST' => $controller->assign($id),
        $action === '/macro' && $method === 'POST' => $controller->macro($id),
        $action === ''       && $method === 'GET'  => $controller->thread($id),
        default => (function() use ($uri) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Not found', 'uri' => $uri]);
        })(),
    };
} elseif (preg_match('#^/api/notifications/(\d+)/read$#', $uri, $m)) {
    $id = (int) $m[1];
    $controller = new NotificationsController($mysqli);

    match (true) {
        $method === 'POST' => $controller->markRead($id),
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
