<?php

namespace App\Services;

class NotificationService
{
    public function __construct(private \mysqli $db)
    {
    }

    public function create(array $payload): void
    {
        $empresaId = (int) ($payload['empresa_id'] ?? 0);
        $userId = isset($payload['user_id']) ? (int) $payload['user_id'] : null;
        $recruiterId = isset($payload['recruiter_id']) ? (int) $payload['recruiter_id'] : null;
        $type = (string) ($payload['type'] ?? 'info');
        $title = (string) ($payload['title'] ?? 'Notificación');
        $message = (string) ($payload['message'] ?? '');
        $severity = (string) ($payload['severity'] ?? 'info');
        $entityType = $payload['entity_type'] ?? null;
        $entityId = isset($payload['entity_id']) ? (int) $payload['entity_id'] : null;
        $actionUrl = $payload['action_url'] ?? null;

        if (!$empresaId || $message === '') {
            return;
        }

        $stmt = $this->db->prepare(
            "INSERT INTO notifications (empresa_id, user_id, recruiter_id, type, title, message, severity, entity_type, entity_id, action_url)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('iiisssssis', $empresaId, $userId, $recruiterId, $type, $title, $message, $severity, $entityType, $entityId, $actionUrl);
        $stmt->execute();
        $stmt->close();
    }
}
