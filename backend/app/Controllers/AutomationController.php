<?php

namespace App\Controllers;

use App\Middleware\AuthMiddleware;

class AutomationController
{
    public function __construct(private \mysqli $db)
    {
    }

    public function overview(): void
    {
        $empresaId = (int) ($_GET['empresa_id'] ?? 0);
        if (!$empresaId) {
            $this->json(['error' => 'empresa_id requerido'], 400);
            return;
        }

        AuthMiddleware::assertCompanyAccess($empresaId);
        AuthMiddleware::assertPermission('executive.view', $empresaId);

        $pending24h = $this->scalar(
            "SELECT COUNT(*) AS total
             FROM interviews
             WHERE empresa_id = $empresaId
               AND status IN ('agendada','confirmada','reagendada')
               AND reminder_24h_sent_at IS NULL
               AND TIMESTAMP(interview_date, interview_time) BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 26 HOUR)"
        );
        $pending2h = $this->scalar(
            "SELECT COUNT(*) AS total
             FROM interviews
             WHERE empresa_id = $empresaId
               AND status IN ('agendada','confirmada','reagendada')
               AND reminder_2h_sent_at IS NULL
               AND TIMESTAMP(interview_date, interview_time) BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 HOUR)"
        );
        $pendingNoShow = $this->scalar(
            "SELECT COUNT(*) AS total
             FROM interviews
             WHERE empresa_id = $empresaId
               AND status = 'no_show'
               AND no_show_followup_sent_at IS NULL"
        );
        $pendingPlaybooks = $this->scalar(
            "SELECT COUNT(*) AS total
             FROM leads
             WHERE empresa_id = $empresaId
               AND estado NOT IN ('rechazado','no_interesado','contratado')
               AND current_stage IN ('contactado','calificado','reagendar','no_asistio')"
        );

        $runs = [];
        $runResult = $this->db->query(
            "SELECT id, automation_key, status, payload, created_at, lead_id, interview_id
             FROM automation_runs
             WHERE empresa_id = $empresaId
             ORDER BY created_at DESC
             LIMIT 100"
        );
        while ($row = $runResult?->fetch_assoc()) {
            $row['payload'] = json_decode($row['payload'] ?? '{}', true);
            $runs[] = $row;
        }

        $this->json([
            'summary' => [
                'pending_24h' => $pending24h,
                'pending_2h' => $pending2h,
                'pending_no_show' => $pendingNoShow,
                'pending_playbooks' => $pendingPlaybooks,
            ],
            'runs' => $runs,
        ]);
    }

    private function scalar(string $sql): int
    {
        $row = $this->db->query($sql)?->fetch_assoc();
        return (int) ($row['total'] ?? 0);
    }

    private function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
