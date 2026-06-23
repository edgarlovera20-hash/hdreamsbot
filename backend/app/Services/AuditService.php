<?php

namespace App\Services;

class AuditService
{
    public function __construct(private \mysqli $db)
    {
    }

    public function log(?int $userId, ?int $empresaId, string $action, string $entityType, string|int|null $entityId = null, array $details = []): void
    {
        $detailsJson = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $entityIdString = $entityId !== null ? (string) $entityId : null;
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'cli';

        $stmt = $this->db->prepare(
            "INSERT INTO audit_logs (user_id, empresa_id, action, entity_type, entity_id, details, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('iisssss', $userId, $empresaId, $action, $entityType, $entityIdString, $detailsJson, $ipAddress);
        $stmt->execute();
        $stmt->close();
    }
}
