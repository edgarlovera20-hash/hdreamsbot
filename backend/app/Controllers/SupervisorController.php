<?php

namespace App\Controllers;

use App\Middleware\AuthMiddleware;

class SupervisorController
{
    public function __construct(private \mysqli $db)
    {
    }

    public function realtime(): void
    {
        $empresaId = (int) ($_GET['empresa_id'] ?? 0);
        if (!$empresaId) {
            $this->json(['error' => 'empresa_id requerido'], 400);
            return;
        }
        AuthMiddleware::assertCompanyAccess($empresaId);
        AuthMiddleware::assertPermission('executive.view', $empresaId);

        $criticalNotifications = [];
        $notifResult = $this->db->query(
            "SELECT id, title, message, created_at
             FROM notifications
             WHERE empresa_id = $empresaId
               AND severity = 'critical'
               AND read_at IS NULL
             ORDER BY created_at DESC
             LIMIT 10"
        );
        while ($row = $notifResult?->fetch_assoc()) {
            $criticalNotifications[] = $row;
        }

        $voiceQueue = [];
        $voiceResult = $this->db->query(
            "SELECT vn.id, vn.status, vn.created_at, l.nombre AS lead_nombre, r.nombre AS recruiter_nombre
             FROM voice_notes vn
             JOIN leads l ON l.id = vn.lead_id
             LEFT JOIN recruiters r ON r.id = vn.recruiter_id
             WHERE vn.empresa_id = $empresaId
             ORDER BY vn.created_at DESC
             LIMIT 20"
        );
        while ($row = $voiceResult?->fetch_assoc()) {
            $voiceQueue[] = $row;
        }

        $playbooks = [];
        $playResult = $this->db->query(
            "SELECT rp.id, rp.name, rp.trigger_stage, rp.active, r.nombre AS recruiter_nombre
             FROM recruiter_playbooks rp
             JOIN recruiters r ON r.id = rp.recruiter_id
             WHERE rp.empresa_id = $empresaId
             ORDER BY r.nombre ASC, rp.name ASC"
        );
        while ($row = $playResult?->fetch_assoc()) {
            $playbooks[] = $row;
        }

        $activeConversations = [];
        $convResult = $this->db->query(
            "SELECT l.id, l.nombre, l.current_stage, l.prioridad, l.last_inbound_at, r.nombre AS recruiter_nombre
             FROM leads l
             LEFT JOIN recruiters r ON r.id = l.assigned_recruiter_id
             WHERE l.empresa_id = $empresaId
               AND l.estado NOT IN ('rechazado','no_interesado','contratado')
             ORDER BY COALESCE(l.last_inbound_at, l.ultima_interaccion) DESC
             LIMIT 20"
        );
        while ($row = $convResult?->fetch_assoc()) {
            $activeConversations[] = $row;
        }

        $this->json([
            'critical_notifications' => $criticalNotifications,
            'voice_queue' => $voiceQueue,
            'recruiter_playbooks' => $playbooks,
            'active_conversations' => $activeConversations,
        ]);
    }

    private function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
