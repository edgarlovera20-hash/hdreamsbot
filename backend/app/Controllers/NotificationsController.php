<?php

namespace App\Controllers;

use App\Middleware\AuthMiddleware;

class NotificationsController
{
    private \mysqli $db;

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
    }

    public function index(): void
    {
        $empresaId = (int) ($_GET['empresa_id'] ?? 0);
        if (!$empresaId) {
            $this->json(['error' => 'empresa_id requerido'], 400);
            return;
        }

        AuthMiddleware::assertCompanyAccess($empresaId);
        AuthMiddleware::assertPermission('notifications.view', $empresaId);

        $user = AuthMiddleware::currentUser();
        $companyContext = AuthMiddleware::companyContext($empresaId);
        $recruiterId = (int) ($companyContext['recruiter_id'] ?? 0);
        $userId = (int) ($user['id'] ?? 0);

        $where = [
            "empresa_id = $empresaId",
            "(user_id IS NULL OR user_id = $userId)",
        ];

        if ($recruiterId > 0) {
            $where[] = "(recruiter_id IS NULL OR recruiter_id = $recruiterId)";
        } else {
            $where[] = "recruiter_id IS NULL";
        }

        $result = $this->db->query(
            "SELECT id, type, title, message, severity, entity_type, entity_id, action_url, read_at, delivered_at, created_at
             FROM notifications
             WHERE " . implode(' AND ', $where) . "
             ORDER BY COALESCE(read_at, '9999-12-31') DESC, created_at DESC
             LIMIT 100"
        );

        $items = [];
        while ($row = $result?->fetch_assoc()) {
            $items[] = $row;
        }

        $unread = $this->db->query(
            "SELECT COUNT(*) AS total
             FROM notifications
             WHERE " . implode(' AND ', $where) . " AND read_at IS NULL"
        )?->fetch_assoc();

        $this->json([
            'items' => $items,
            'unread' => (int) ($unread['total'] ?? 0),
        ]);
    }

    public function markRead(int $id): void
    {
        $notification = $this->db->query("SELECT id, empresa_id FROM notifications WHERE id = $id")->fetch_assoc();
        if (!$notification) {
            $this->json(['error' => 'Notificación no encontrada'], 404);
            return;
        }

        $empresaId = (int) $notification['empresa_id'];
        AuthMiddleware::assertCompanyAccess($empresaId);
        AuthMiddleware::assertPermission('notifications.view', $empresaId);

        $this->db->query("UPDATE notifications SET read_at = NOW() WHERE id = $id");
        $this->json(['ok' => true]);
    }

    private function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
