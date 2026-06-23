<?php

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Services\AuditService;

class PlaybookController
{
    private AuditService $audit;

    public function __construct(private \mysqli $db)
    {
        $this->audit = new AuditService($db);
    }

    public function index(): void
    {
        $empresaId = (int) ($_GET['empresa_id'] ?? 0);
        if (!$empresaId) {
            $this->json(['error' => 'empresa_id requerido'], 400);
            return;
        }
        AuthMiddleware::assertCompanyAccess($empresaId);
        AuthMiddleware::assertPermission('executive.view', $empresaId);

        $items = [];
        $result = $this->db->query(
            "SELECT rp.id, rp.name, rp.trigger_stage, rp.message_template, rp.active, rp.created_at,
                    rp.recruiter_id, r.nombre AS recruiter_nombre
             FROM recruiter_playbooks rp
             JOIN recruiters r ON r.id = rp.recruiter_id
             WHERE rp.empresa_id = $empresaId
             ORDER BY r.nombre ASC, rp.name ASC"
        );
        while ($row = $result?->fetch_assoc()) {
            $items[] = $row;
        }

        $this->json(['items' => $items]);
    }

    public function upsert(): void
    {
        $body = json_decode(file_get_contents('php://input'), true);
        $empresaId = (int) ($body['empresa_id'] ?? 0);
        $recruiterId = (int) ($body['recruiter_id'] ?? 0);
        $name = trim((string) ($body['name'] ?? ''));
        $triggerStage = trim((string) ($body['trigger_stage'] ?? ''));
        $messageTemplate = trim((string) ($body['message_template'] ?? ''));
        $active = isset($body['active']) ? (int) !!$body['active'] : 1;

        if (!$empresaId || !$recruiterId || $name === '' || $triggerStage === '' || $messageTemplate === '') {
            $this->json(['error' => 'Faltan campos requeridos'], 400);
            return;
        }

        AuthMiddleware::assertCompanyAccess($empresaId);
        AuthMiddleware::assertPermission('executive.view', $empresaId);

        $stmt = $this->db->prepare(
            "INSERT INTO recruiter_playbooks (empresa_id, recruiter_id, name, trigger_stage, message_template, active)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('iisssi', $empresaId, $recruiterId, $name, $triggerStage, $messageTemplate, $active);
        $stmt->execute();
        $playbookId = (int) $stmt->insert_id;
        $stmt->close();

        $user = AuthMiddleware::currentUser();
        $this->audit->log((int) ($user['id'] ?? 0), $empresaId, 'playbook.create', 'recruiter_playbook', $playbookId, [
            'recruiter_id' => $recruiterId,
            'trigger_stage' => $triggerStage,
        ]);

        $this->json(['ok' => true, 'playbook_id' => $playbookId], 201);
    }

    private function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
