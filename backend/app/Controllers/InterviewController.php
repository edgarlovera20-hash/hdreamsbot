<?php

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Services\AuditService;
use App\Services\RecruitmentFlowService;

class InterviewController
{
    private \mysqli $db;
    private RecruitmentFlowService $flow;
    private AuditService $audit;

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
        $this->flow = new RecruitmentFlowService();
        $this->audit = new AuditService($db);
    }

    public function slots(): void
    {
        $empresaId = (int) ($_GET['empresa_id'] ?? 0);
        $date = $this->safeDate($_GET['date'] ?? date('Y-m-d'));

        if (!$empresaId) {
            $this->json(['error' => 'empresa_id requerido'], 400);
            return;
        }

        AuthMiddleware::assertCompanyAccess($empresaId);

        $this->ensureSlots($empresaId, $date);

        $result = $this->db->query(
            "SELECT s.id, s.slot_date, s.slot_time, s.capacity, s.reserved, s.active,
                    r.id AS recruiter_id, r.nombre AS recruiter_nombre
             FROM interview_slots s
             LEFT JOIN recruiters r ON r.id = s.recruiter_id
             WHERE s.empresa_id = $empresaId
               AND s.slot_date = '$date'
               AND s.active = 1
             ORDER BY s.slot_time ASC, r.nombre ASC"
        );

        $items = [];
        while ($row = $result?->fetch_assoc()) {
            $row['available'] = max(0, (int) $row['capacity'] - (int) $row['reserved']);
            $items[] = $row;
        }

        $this->json([
            'date' => $date,
            'slots' => $items,
        ]);
    }

    public function index(): void
    {
        $empresaId = (int) ($_GET['empresa_id'] ?? 0);
        $date = $this->safeDate($_GET['date'] ?? date('Y-m-d'));

        if (!$empresaId) {
            $this->json(['error' => 'empresa_id requerido'], 400);
            return;
        }

        AuthMiddleware::assertCompanyAccess($empresaId);

        $result = $this->db->query(
            "SELECT i.*,
                    l.nombre AS lead_nombre,
                    l.telefono AS lead_telefono,
                    l.estado AS lead_estado,
                    l.prioridad AS lead_prioridad,
                    r.nombre AS recruiter_nombre
             FROM interviews i
             JOIN leads l ON l.id = i.lead_id
             LEFT JOIN recruiters r ON r.id = i.recruiter_id
             WHERE i.empresa_id = $empresaId
               AND i.interview_date = '$date'
             ORDER BY i.interview_time ASC, i.created_at ASC"
        );

        $items = [];
        while ($row = $result?->fetch_assoc()) {
            $items[] = $row;
        }

        $this->json([
            'date' => $date,
            'items' => $items,
            'total' => count($items),
        ]);
    }

    public function create(): void
    {
        $body = json_decode(file_get_contents('php://input'), true);

        $leadId = (int) ($body['lead_id'] ?? 0);
        $slotId = (int) ($body['slot_id'] ?? 0);
        $officeLocation = trim((string) ($body['office_location'] ?? $this->flow->getOfficeAddress()));

        if (!$leadId || !$slotId || $officeLocation === '') {
            $this->json(['error' => 'lead_id, slot_id y office_location son requeridos'], 400);
            return;
        }

        $lead = $this->db->query("SELECT id, empresa_id, assigned_recruiter_id, nombre FROM leads WHERE id = $leadId")->fetch_assoc();
        if (!$lead) {
            $this->json(['error' => 'Lead no encontrado'], 404);
            return;
        }
        AuthMiddleware::assertCompanyAccess((int) $lead['empresa_id']);
        AuthMiddleware::assertPermission('interviews.manage', (int) $lead['empresa_id']);

        $slot = $this->db->query(
            "SELECT id, empresa_id, recruiter_id, slot_date, slot_time, capacity, reserved, active
             FROM interview_slots
             WHERE id = $slotId"
        )->fetch_assoc();

        if (!$slot || (int) $slot['empresa_id'] !== (int) $lead['empresa_id'] || !(int) $slot['active']) {
            $this->json(['error' => 'Slot invalido'], 400);
            return;
        }

        if ((int) $slot['reserved'] >= (int) $slot['capacity']) {
            $this->json(['error' => 'Slot sin disponibilidad'], 409);
            return;
        }

        $existing = $this->db->query(
            "SELECT id FROM interviews
             WHERE lead_id = $leadId
               AND status IN ('agendada','confirmada','reagendada')
             ORDER BY created_at DESC LIMIT 1"
        )->fetch_assoc();

        if ($existing) {
            $this->json(['error' => 'El lead ya tiene una entrevista activa'], 409);
            return;
        }

        $recruiterId = (int) ($slot['recruiter_id'] ?: $lead['assigned_recruiter_id'] ?: 1);
        $empresaId = (int) $lead['empresa_id'];
        $slotDate = (string) $slot['slot_date'];
        $slotTime = (string) $slot['slot_time'];
        $stmt = $this->db->prepare(
            "INSERT INTO interviews
                (lead_id, empresa_id, recruiter_id, office_location, interview_date, interview_time, status, confirmation_channel)
             VALUES (?, ?, ?, ?, ?, ?, 'agendada', 'manual')"
        );
        $stmt->bind_param(
            'iiisss',
            $leadId,
            $empresaId,
            $recruiterId,
            $officeLocation,
            $slotDate,
            $slotTime
        );
        $stmt->execute();
        $interviewId = $stmt->insert_id;
        $stmt->close();

        $this->db->query("UPDATE interview_slots SET reserved = reserved + 1 WHERE id = $slotId");
        $this->db->query(
            "UPDATE leads
             SET estado='entrevista_agendada',
                 current_stage='entrevista_agendada',
                 interview_status='agendada',
                 next_action_type='confirmar_entrevista',
                 next_action_at=TIMESTAMP('{$slot['slot_date']}', '{$slot['slot_time']}')
             WHERE id = $leadId"
        );

        $this->registrarEvento((int) $lead['empresa_id'], $leadId, 'interview_scheduled', 'Entrevista agendada', [
            'interview_id' => $interviewId,
            'slot_id' => $slotId,
            'date' => $slot['slot_date'],
            'time' => $slot['slot_time'],
            'recruiter_id' => $recruiterId,
        ]);

        $interview = $this->db->query(
            "SELECT i.*, r.nombre AS recruiter_nombre
             FROM interviews i
             LEFT JOIN recruiters r ON r.id = i.recruiter_id
             WHERE i.id = $interviewId"
        )->fetch_assoc();

        $user = AuthMiddleware::currentUser();
        $this->audit->log((int) ($user['id'] ?? 0), $empresaId, 'interview.create', 'interview', $interviewId, [
            'lead_id' => $leadId,
            'slot_id' => $slotId,
        ]);

        $this->json(['ok' => true, 'interview' => $interview], 201);
    }

    public function update(int $id): void
    {
        $body = json_decode(file_get_contents('php://input'), true);
        $status = $this->safeEnum($body['status'] ?? '', ['agendada','confirmada','reagendada','realizada','cancelada','no_show']);

        $interview = $this->db->query("SELECT * FROM interviews WHERE id = $id")->fetch_assoc();
        if (!$interview) {
            $this->json(['error' => 'Entrevista no encontrada'], 404);
            return;
        }
        AuthMiddleware::assertCompanyAccess((int) $interview['empresa_id']);
        AuthMiddleware::assertPermission('interviews.manage', (int) $interview['empresa_id']);

        $updates = [];
        if ($status) {
            $statusEsc = $this->db->real_escape_string($status);
            $updates[] = "status='$statusEsc'";
        }

        if (isset($body['notes'])) {
            $notesEsc = $this->db->real_escape_string((string) $body['notes']);
            $updates[] = "notes='$notesEsc'";
        }

        if (!$updates) {
            $this->json(['error' => 'Sin cambios para aplicar'], 400);
            return;
        }

        if ($status === 'realizada') {
            $updates[] = "attended_at=NOW()";
        }

        $this->db->query("UPDATE interviews SET " . implode(', ', $updates) . " WHERE id = $id");

        if ($status) {
            $leadStageMap = [
                'confirmada' => ['estado' => 'entrevista_agendada', 'stage' => 'confirmado', 'interview_status' => 'confirmada'],
                'reagendada' => ['estado' => 'entrevista_agendada', 'stage' => 'reagendar', 'interview_status' => 'reagendada'],
                'realizada'  => ['estado' => 'entrevista_realizada', 'stage' => 'confirmado', 'interview_status' => 'realizada'],
                'no_show'    => ['estado' => 'contactado', 'stage' => 'no_asistio', 'interview_status' => 'no_show'],
                'cancelada'  => ['estado' => 'contactado', 'stage' => 'reagendar', 'interview_status' => 'sin_agendar'],
            ];

            if (isset($leadStageMap[$status])) {
                $map = $leadStageMap[$status];
                $this->db->query(
                    "UPDATE leads
                     SET estado='{$map['estado']}',
                         current_stage='{$map['stage']}',
                         interview_status='{$map['interview_status']}'
                     WHERE id = {$interview['lead_id']}"
                );
            }

            $this->registrarEvento((int) $interview['empresa_id'], (int) $interview['lead_id'], 'interview_updated', 'Entrevista actualizada', [
                'interview_id' => $id,
                'status' => $status,
            ]);
        }

        $updated = $this->db->query(
            "SELECT i.*, r.nombre AS recruiter_nombre
             FROM interviews i
             LEFT JOIN recruiters r ON r.id = i.recruiter_id
             WHERE i.id = $id"
        )->fetch_assoc();

        $user = AuthMiddleware::currentUser();
        $this->audit->log((int) ($user['id'] ?? 0), (int) $interview['empresa_id'], 'interview.update', 'interview', $id, [
            'status' => $status,
        ]);

        $this->json(['ok' => true, 'interview' => $updated]);
    }

    public function confirm(int $id): void
    {
        $this->updateWithStatus($id, 'confirmada', 'interview_confirmed', 'Entrevista confirmada');
    }

    public function noShow(int $id): void
    {
        $this->updateWithStatus($id, 'no_show', 'interview_no_show', 'Lead marcado como no show');
    }

    private function updateWithStatus(int $id, string $status, string $eventType, string $label): void
    {
        $interview = $this->db->query("SELECT * FROM interviews WHERE id = $id")->fetch_assoc();
        if (!$interview) {
            $this->json(['error' => 'Entrevista no encontrada'], 404);
            return;
        }
        AuthMiddleware::assertCompanyAccess((int) $interview['empresa_id']);
        AuthMiddleware::assertPermission('interviews.manage', (int) $interview['empresa_id']);

        $this->db->query("UPDATE interviews SET status='$status' WHERE id = $id");

        $map = $status === 'confirmada'
            ? ['estado' => 'entrevista_agendada', 'stage' => 'confirmado', 'interview_status' => 'confirmada']
            : ['estado' => 'contactado', 'stage' => 'no_asistio', 'interview_status' => 'no_show'];

        $this->db->query(
            "UPDATE leads
             SET estado='{$map['estado']}',
                 current_stage='{$map['stage']}',
                 interview_status='{$map['interview_status']}'
             WHERE id = {$interview['lead_id']}"
        );

        $this->registrarEvento((int) $interview['empresa_id'], (int) $interview['lead_id'], $eventType, $label, [
            'interview_id' => $id,
            'status' => $status,
        ]);

        $updated = $this->db->query(
            "SELECT i.*, r.nombre AS recruiter_nombre
             FROM interviews i
             LEFT JOIN recruiters r ON r.id = i.recruiter_id
             WHERE i.id = $id"
        )->fetch_assoc();

        $this->json(['ok' => true, 'interview' => $updated]);
    }

    private function ensureSlots(int $empresaId, string $date): void
    {
        $count = $this->db->query(
            "SELECT COUNT(*) AS total FROM interview_slots WHERE empresa_id = $empresaId AND slot_date = '$date'"
        )->fetch_assoc();

        if ((int) ($count['total'] ?? 0) > 0) {
            return;
        }

        $recruiters = [];
        $result = $this->db->query("SELECT id FROM recruiters WHERE empresa_id = $empresaId AND activo = 1 ORDER BY id ASC");
        while ($row = $result?->fetch_assoc()) {
            $recruiters[] = (int) $row['id'];
        }
        if (!$recruiters) {
            $recruiters = [null];
        }

        foreach ($recruiters as $recruiterId) {
            foreach ($this->flow->getInterviewSlots24h() as $time) {
                $rid = $recruiterId === null ? 'NULL' : (string) $recruiterId;
                $this->db->query(
                    "INSERT INTO interview_slots
                        (empresa_id, recruiter_id, slot_date, slot_time, capacity, reserved, active)
                     VALUES ($empresaId, $rid, '$date', '$time', 1, 0, 1)"
                );
            }
        }
    }

    private function safeDate(string $value): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : date('Y-m-d');
    }

    private function safeEnum(?string $value, array $allowed): ?string
    {
        return ($value && in_array($value, $allowed, true)) ? $value : null;
    }

    private function registrarEvento(int $empresaId, int $leadId, string $type, string $label, array $payload = []): void
    {
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $actorType = 'recruiter';
        $actorId = 1;
        $stmt = $this->db->prepare(
            "INSERT INTO lead_events (lead_id, empresa_id, event_type, event_label, payload, actor_type, actor_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('iissssi', $leadId, $empresaId, $type, $label, $payloadJson, $actorType, $actorId);
        $stmt->execute();
        $stmt->close();
    }

    private function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
