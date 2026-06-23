<?php

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Services\RecruitmentFlowService;

class FlowController
{
    private \mysqli $db;
    private RecruitmentFlowService $flow;

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
        $this->flow = new RecruitmentFlowService();
    }

    public function overview(): void
    {
        $this->json($this->flow->getOverview());
    }

    public function funnel(): void
    {
        $empresaId = (int) ($_GET['empresa_id'] ?? 0);
        $desde     = $this->safeDate($_GET['desde'] ?? date('Y-m-01'));
        $hasta     = $this->safeDate($_GET['hasta'] ?? date('Y-m-d'));

        if (!$empresaId) {
            $this->json(['error' => 'empresa_id requerido'], 400);
            return;
        }

        AuthMiddleware::assertCompanyAccess($empresaId);

        $this->json($this->flow->getFunnelStats($this->db, $empresaId, $desde, $hasta));
    }

    private function safeDate(string $d): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : date('Y-m-d');
    }

    private function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
