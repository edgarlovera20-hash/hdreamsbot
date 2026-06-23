<?php

namespace App\Controllers;

use App\Middleware\AuthMiddleware;

class AuditController
{
    public function __construct(private \mysqli $db)
    {
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

        $result = $this->db->query(
            "SELECT a.id, a.action, a.entity_type, a.entity_id, a.details, a.ip_address, a.created_at,
                    u.nombre AS user_nombre, u.email AS user_email
             FROM audit_logs a
             LEFT JOIN app_users u ON u.id = a.user_id
             WHERE a.empresa_id = $empresaId
             ORDER BY a.created_at DESC
             LIMIT 150"
        );

        $items = [];
        while ($row = $result?->fetch_assoc()) {
            $row['details'] = json_decode($row['details'] ?? '{}', true);
            $items[] = $row;
        }

        $this->json(['items' => $items]);
    }

    private function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
