<?php

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Services\AuditService;
use App\Services\RecruitmentCopilotService;

class CopilotController
{
    private RecruitmentCopilotService $copilot;
    private AuditService $audit;

    public function __construct(private \mysqli $db)
    {
        $this->copilot = new RecruitmentCopilotService($db);
        $this->audit = new AuditService($db);
    }

    public function analyzeLead(int $leadId): void
    {
        $lead = $this->db->query("SELECT empresa_id FROM leads WHERE id = $leadId")->fetch_assoc();
        if (!$lead) {
            $this->json(['error' => 'Lead no encontrado'], 404);
            return;
        }

        $empresaId = (int) $lead['empresa_id'];
        AuthMiddleware::assertCompanyAccess($empresaId);
        AuthMiddleware::assertPermission('leads.view', $empresaId);

        $analysis = $this->copilot->analyzeLead($leadId);
        if (!$analysis) {
            $this->json(['error' => 'No se pudo analizar el lead'], 500);
            return;
        }

        $user = AuthMiddleware::currentUser();
        $this->audit->log((int) ($user['id'] ?? 0), $empresaId, 'copilot.analyze', 'lead', $leadId);

        $this->json(['analysis' => $analysis]);
    }

    public function autoAssign(int $leadId): void
    {
        $lead = $this->db->query("SELECT empresa_id FROM leads WHERE id = $leadId")->fetch_assoc();
        if (!$lead) {
            $this->json(['error' => 'Lead no encontrado'], 404);
            return;
        }

        $empresaId = (int) $lead['empresa_id'];
        AuthMiddleware::assertCompanyAccess($empresaId);
        AuthMiddleware::assertPermission('inbox.assign', $empresaId);

        $result = $this->copilot->autoAssignLead($leadId);
        if (!$result) {
            $this->json(['error' => 'No se pudo autoasignar el lead'], 422);
            return;
        }

        $user = AuthMiddleware::currentUser();
        $this->audit->log((int) ($user['id'] ?? 0), $empresaId, 'copilot.auto_assign', 'lead', $leadId, $result);

        $this->json(['ok' => true, 'assignment' => $result]);
    }

    private function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
