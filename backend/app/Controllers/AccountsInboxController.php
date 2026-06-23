<?php

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Services\CanalManager;
use App\Services\AuditService;
use App\Services\RecruitmentFlowService;

class AccountsInboxController
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

    public function conversations(): void
    {
        $empresaId = (int) ($_GET['empresa_id'] ?? 0);
        $canal = $this->safeEnum($_GET['canal'] ?? null, ['whatsapp','messenger','instagram','facebook','telegram','gmail','outlook','teams']);
        $query = trim((string) ($_GET['q'] ?? ''));
        $bucket = $this->safeEnum($_GET['bucket'] ?? null, ['pending', 'attended', 'sla_overdue']);

        if (!$empresaId) {
            $this->json(['error' => 'empresa_id requerido'], 400);
            return;
        }

        AuthMiddleware::assertCompanyAccess($empresaId);
        AuthMiddleware::assertPermission('leads.view', $empresaId);

        $where = ["l.empresa_id = $empresaId"];
        if ($canal) {
            $where[] = "l.canal = '$canal'";
        }
        if ($query !== '') {
            $q = $this->db->real_escape_string($query);
            $where[] = "(l.nombre LIKE '%$q%' OR l.telefono LIKE '%$q%' OR l.email LIKE '%$q%' OR l.canal_user_id LIKE '%$q%')";
        }
        if ($bucket === 'pending') {
            $where[] = "(l.last_outbound_at IS NULL OR (l.last_inbound_at IS NOT NULL AND l.last_inbound_at > l.last_outbound_at))";
        }
        if ($bucket === 'attended') {
            $where[] = "(l.last_outbound_at IS NOT NULL AND (l.last_inbound_at IS NULL OR l.last_outbound_at >= l.last_inbound_at))";
        }
        if ($bucket === 'sla_overdue') {
            $where[] = "l.next_action_at IS NOT NULL AND l.next_action_at < NOW()";
        }

        $result = $this->db->query(
            "SELECT l.id, l.nombre, l.telefono, l.email, l.canal, l.canal_user_id,
                    l.estado, l.current_stage, l.prioridad, l.ultima_interaccion,
                    l.last_inbound_at, l.last_outbound_at, l.next_action_at, l.next_action_type, l.interview_status,
                    l.assigned_recruiter_id,
                    l.metadata,
                    e.nombre AS empresa_nombre,
                    s.nombre AS seccion_nombre,
                    r.nombre AS recruiter_nombre,
                    i.interview_date,
                    i.interview_time,
                    i.status AS interview_record_status,
                    c.page_id, c.config,
                    COUNT(il.id) AS message_count
             FROM leads l
             JOIN empresas e ON e.id = l.empresa_id
             JOIN secciones s ON s.id = l.seccion_id
             LEFT JOIN recruiters r ON r.id = l.assigned_recruiter_id
             LEFT JOIN interviews i ON i.id = (
                SELECT i2.id
                FROM interviews i2
                WHERE i2.lead_id = l.id
                ORDER BY i2.interview_date DESC, i2.interview_time DESC, i2.created_at DESC
                LIMIT 1
             )
             LEFT JOIN canales c ON c.empresa_id = l.empresa_id AND c.seccion_id = l.seccion_id AND c.canal = l.canal
             LEFT JOIN ia_logs il ON il.empresa_id = l.empresa_id AND il.seccion_id = l.seccion_id
                                 AND il.wa_id = l.canal_user_id AND il.canal = l.canal
             WHERE " . implode(' AND ', $where) . "
             GROUP BY l.id, l.nombre, l.telefono, l.email, l.canal, l.canal_user_id,
                      l.estado, l.current_stage, l.prioridad, l.ultima_interaccion,
                      l.last_inbound_at, l.last_outbound_at, l.next_action_at, l.next_action_type, l.interview_status,
                      l.assigned_recruiter_id,
                      l.metadata,
                      e.nombre, s.nombre, r.nombre, i.interview_date, i.interview_time, i.status, c.page_id, c.config
             ORDER BY FIELD(l.prioridad,'urgente','alta','media','baja'),
                      COALESCE(l.last_inbound_at, l.ultima_interaccion) DESC
             LIMIT 200"
        );

        $items = [];
        while ($row = $result?->fetch_assoc()) {
            $config = json_decode($row['config'] ?? '{}', true) ?: [];
            $metadata = json_decode($row['metadata'] ?? '{}', true) ?: [];
            $row['nombre_cuenta'] = $config['nombre_cuenta'] ?? null;
            $row['inbox_alias'] = $config['inbox_alias'] ?? null;
            $row['vacante'] = $metadata['vacante'] ?? $row['seccion_nombre'] ?? null;
            $row['ciudad'] = $metadata['ciudad'] ?? null;
            $row['experiencia'] = $metadata['experiencia'] ?? null;
            $row['edad_label'] = isset($row['edad']) && $row['edad'] !== null ? ((int) $row['edad'] . ' años') : null;
            $row['origen_label'] = $this->buildOriginLabel($row);
            $row['last_contact_label'] = $this->buildLastContactLabel($row);
            unset($row['config']);
            unset($row['metadata']);
            $row['bucket'] = $this->resolveBucket($row);
            $items[] = $row;
        }

        $this->json(['items' => $items, 'total' => count($items)]);
    }

    public function thread(int $leadId): void
    {
        $lead = $this->findLead($leadId);
        if (!$lead) {
            $this->json(['error' => 'Lead no encontrado'], 404);
            return;
        }
        AuthMiddleware::assertPermission('inbox.reply', (int) $lead['empresa_id']);

        $this->json([
            'lead' => $lead,
            'messages' => $this->fetchMessages($lead),
            'templates' => $this->buildTemplates($lead),
            'macros' => $this->flow->getInboxMacros(),
        ]);
    }

    public function reply(int $leadId): void
    {
        $body = json_decode(file_get_contents('php://input'), true);
        $text = trim((string) ($body['text'] ?? ''));

        if ($text === '') {
            $this->json(['error' => 'text requerido'], 400);
            return;
        }

        $lead = $this->findLead($leadId);
        if (!$lead) {
            $this->json(['error' => 'Lead no encontrado'], 404);
            return;
        }

        $channelConfig = $this->db->query(
            "SELECT * FROM canales
             WHERE empresa_id = {$lead['empresa_id']}
               AND seccion_id = {$lead['seccion_id']}
               AND canal = '{$lead['canal']}'
             LIMIT 1"
        )->fetch_assoc();

        if (!$channelConfig) {
            $this->json(['error' => 'Canal no configurado para este lead'], 404);
            return;
        }

        $config = json_decode($channelConfig['config'] ?? '{}', true) ?: [];
        $channelConfig = array_merge($channelConfig, $config);
        $channelConfig['access_token'] = $channelConfig['access_token'] ?? ($config['access_token'] ?? '');
        $channelConfig['phone_number_id'] = $channelConfig['phone_number_id'] ?? ($config['phone_number_id'] ?? '');
        $channelConfig['telegram_bot_token'] = $channelConfig['telegram_bot_token'] ?? ($config['telegram_bot_token'] ?? '');

        $manager = new CanalManager((int) $lead['empresa_id'], (int) $lead['seccion_id'], $this->db, $channelConfig);
        $sent = $manager->enviarRespuesta((string) $lead['canal_user_id'], $text);

        if (!$sent) {
            $this->json(['error' => 'No se pudo enviar el mensaje por el canal configurado'], 502);
            return;
        }

        $stmt = $this->db->prepare(
            "INSERT INTO ia_logs (empresa_id, seccion_id, wa_id, canal, pregunta, respuesta)
             VALUES (?, ?, ?, ?, NULL, ?)"
        );
        $empresaId = (int) $lead['empresa_id'];
        $seccionId = (int) $lead['seccion_id'];
        $userId = (string) $lead['canal_user_id'];
        $canal = (string) $lead['canal'];
        $stmt->bind_param('iisss', $empresaId, $seccionId, $userId, $canal, $text);
        $stmt->execute();
        $stmt->close();

        $this->db->query(
            "UPDATE leads
             SET last_outbound_at = NOW(),
                 ultima_interaccion = NOW(),
                 estado = CASE WHEN estado = 'nuevo' THEN 'contactado' ELSE estado END,
                 current_stage = CASE WHEN current_stage = 'nuevo_lead' THEN 'contactado' ELSE current_stage END
             WHERE id = $leadId"
        );

        $this->registrarEvento($empresaId, $leadId, 'message_outbound', 'Respuesta enviada desde inbox centralizado', [
            'channel' => $canal,
            'text' => $text,
        ]);

        $updatedLead = $this->findLead($leadId);
        $user = AuthMiddleware::currentUser();
        $this->audit->log((int) ($user['id'] ?? 0), (int) $lead['empresa_id'], 'inbox.reply', 'lead', $leadId, [
            'channel' => $canal,
        ]);
        $this->json([
            'ok' => true,
            'lead' => $updatedLead,
            'messages' => $this->fetchMessages($updatedLead),
        ]);
    }

    public function assign(int $leadId): void
    {
        $body = json_decode(file_get_contents('php://input'), true);
        $recruiterId = (int) ($body['recruiter_id'] ?? 0);
        $assignedBy = (int) ($body['assigned_by'] ?? 1);
        $reason = trim((string) ($body['reason'] ?? 'asignacion_manual'));

        $lead = $this->findLead($leadId);
        if (!$lead) {
            $this->json(['error' => 'Lead no encontrado'], 404);
            return;
        }
        if (!$recruiterId) {
            $this->json(['error' => 'recruiter_id requerido'], 400);
            return;
        }
        AuthMiddleware::assertPermission('inbox.assign', (int) $lead['empresa_id']);

        $recruiter = $this->db->query(
            "SELECT id, nombre FROM recruiters
             WHERE id = $recruiterId AND empresa_id = {$lead['empresa_id']} AND activo = 1"
        )?->fetch_assoc();
        if (!$recruiter) {
            $this->json(['error' => 'Recruiter invalido'], 400);
            return;
        }

        $this->db->query("UPDATE lead_assignments SET released_at = NOW() WHERE lead_id = $leadId AND released_at IS NULL");

        $stmt = $this->db->prepare(
            "INSERT INTO lead_assignments (lead_id, recruiter_id, assigned_by, reason)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->bind_param('iiis', $leadId, $recruiterId, $assignedBy, $reason);
        $stmt->execute();
        $stmt->close();

        $this->db->query(
            "UPDATE leads
             SET assigned_recruiter_id = $recruiterId,
                 next_action_type = COALESCE(next_action_type, 'seguimiento_reclutador')
             WHERE id = $leadId"
        );

        $this->registrarEvento((int) $lead['empresa_id'], $leadId, 'conversation_assigned', 'Conversación asignada', [
            'recruiter_id' => $recruiterId,
            'recruiter_nombre' => $recruiter['nombre'],
            'reason' => $reason,
        ]);

        $user = AuthMiddleware::currentUser();
        $this->audit->log((int) ($user['id'] ?? 0), (int) $lead['empresa_id'], 'inbox.assign', 'lead', $leadId, [
            'recruiter_id' => $recruiterId,
            'reason' => $reason,
        ]);

        $this->json([
            'ok' => true,
            'lead' => $this->findLead($leadId),
        ]);
    }

    public function macro(int $leadId): void
    {
        $body = json_decode(file_get_contents('php://input'), true);
        $macro = (string) ($body['macro'] ?? '');

        $lead = $this->findLead($leadId);
        if (!$lead) {
            $this->json(['error' => 'Lead no encontrado'], 404);
            return;
        }

        $updates = match ($macro) {
            'mark_contacted' => [
                'estado' => 'contactado',
                'current_stage' => 'contactado',
                'next_action_type' => 'dar_seguimiento',
                'next_action_at' => date('Y-m-d H:i:s', strtotime('+2 hours')),
                'label' => 'Lead marcado como contactado',
            ],
            'mark_qualified' => [
                'estado' => 'calificado',
                'current_stage' => 'calificado',
                'screening_status' => 'aprobado',
                'next_action_type' => 'agendar_entrevista',
                'next_action_at' => date('Y-m-d H:i:s', strtotime('+2 hours')),
                'label' => 'Lead marcado como calificado',
            ],
            'followup_tomorrow' => [
                'next_action_type' => 'seguimiento_manana',
                'next_action_at' => date('Y-m-d 09:00:00', strtotime('+1 day')),
                'label' => 'Seguimiento programado para mañana',
            ],
            'mark_reagendar' => [
                'current_stage' => 'reagendar',
                'interview_status' => 'reagendada',
                'next_action_type' => 'reagendar_entrevista',
                'next_action_at' => date('Y-m-d H:i:s', strtotime('+1 day')),
                'label' => 'Lead enviado a reagenda',
            ],
            'mark_rejected' => [
                'estado' => 'rechazado',
                'current_stage' => 'rechazado',
                'screening_status' => 'rechazado',
                'next_action_type' => null,
                'next_action_at' => null,
                'label' => 'Lead cerrado como rechazado',
            ],
            default => null,
        };

        if (!$updates) {
            $this->json(['error' => 'Macro inválida'], 400);
            return;
        }
        AuthMiddleware::assertPermission('inbox.reply', (int) $lead['empresa_id']);

        $set = [];
        foreach ($updates as $key => $value) {
            if ($key === 'label') {
                continue;
            }
            if ($value === null) {
                $set[] = "$key = NULL";
            } else {
                $valueEsc = $this->db->real_escape_string((string) $value);
                $set[] = "$key = '$valueEsc'";
            }
        }

        $this->db->query("UPDATE leads SET " . implode(', ', $set) . " WHERE id = $leadId");

        $updated = $this->findLead($leadId);
        $this->registrarEvento((int) $lead['empresa_id'], $leadId, 'macro_applied', $updates['label'], [
            'macro' => $macro,
        ]);

        $this->json([
            'ok' => true,
            'lead' => $updated,
        ]);
    }

    private function findLead(int $leadId): ?array
    {
        $lead = $this->db->query(
            "SELECT l.*, e.nombre AS empresa_nombre, s.nombre AS seccion_nombre,
                    r.nombre AS recruiter_nombre,
                    i.interview_date,
                    i.interview_time,
                    i.status AS interview_record_status
             FROM leads l
             JOIN empresas e ON e.id = l.empresa_id
             JOIN secciones s ON s.id = l.seccion_id
             LEFT JOIN recruiters r ON r.id = l.assigned_recruiter_id
             LEFT JOIN interviews i ON i.id = (
                SELECT i2.id
                FROM interviews i2
                WHERE i2.lead_id = l.id
                ORDER BY i2.interview_date DESC, i2.interview_time DESC, i2.created_at DESC
                LIMIT 1
             )
             WHERE l.id = $leadId"
        )?->fetch_assoc();

        if (!$lead) {
            return null;
        }

        AuthMiddleware::assertCompanyAccess((int) $lead['empresa_id']);

        $metadata = json_decode($lead['metadata'] ?? '{}', true) ?: [];
        $lead['vacante'] = $metadata['vacante'] ?? $lead['seccion_nombre'] ?? null;
        $lead['ciudad'] = $metadata['ciudad'] ?? null;
        $lead['experiencia'] = $metadata['experiencia'] ?? null;
        $lead['edad_label'] = isset($lead['edad']) && $lead['edad'] !== null ? ((int) $lead['edad'] . ' años') : null;
        $lead['origen_label'] = $this->buildOriginLabel($lead);
        $lead['last_contact_label'] = $this->buildLastContactLabel($lead);

        return $lead;
    }

    private function fetchMessages(array $lead): array
    {
        $empresaId = (int) $lead['empresa_id'];
        $seccionId = (int) $lead['seccion_id'];
        $userId = $this->db->real_escape_string((string) $lead['canal_user_id']);
        $canal = $this->db->real_escape_string((string) $lead['canal']);

        $result = $this->db->query(
            "SELECT id, pregunta, respuesta, created_at
             FROM ia_logs
             WHERE empresa_id = $empresaId
               AND seccion_id = $seccionId
               AND wa_id = '$userId'
               AND canal = '$canal'
             ORDER BY created_at ASC, id ASC"
        );

        $messages = [];
        while ($row = $result?->fetch_assoc()) {
            if (!empty($row['pregunta'])) {
                $messages[] = [
                    'id' => 'in-' . $row['id'],
                    'direction' => 'inbound',
                    'text' => $row['pregunta'],
                    'created_at' => $row['created_at'],
                ];
            }
            if (!empty($row['respuesta'])) {
                $messages[] = [
                    'id' => 'out-' . $row['id'],
                    'direction' => 'outbound',
                    'text' => $row['respuesta'],
                    'created_at' => $row['created_at'],
                ];
            }
        }

        usort($messages, fn ($a, $b) => strcmp($a['created_at'], $b['created_at']) ?: strcmp($a['id'], $b['id']));
        return $messages;
    }

    private function buildTemplates(array $lead): array
    {
        $templates = [];
        foreach ($this->flow->getInboxTemplates() as $item) {
            $rendered = $this->flow->renderTemplate($item['key'], $lead);
            if ($rendered) {
                $templates[] = [
                    'key' => $item['key'],
                    'label' => $item['label'],
                    'text' => $rendered,
                ];
            }
        }

        return $templates;
    }

    private function resolveBucket(array $row): string
    {
        $nextActionAt = $row['next_action_at'] ?? null;
        $lastInboundAt = $row['last_inbound_at'] ?? null;
        $lastOutboundAt = $row['last_outbound_at'] ?? null;

        if ($nextActionAt && strtotime($nextActionAt) < time()) {
            return 'sla_overdue';
        }
        if (!$lastOutboundAt || ($lastInboundAt && strtotime($lastInboundAt) > strtotime($lastOutboundAt))) {
            return 'pending';
        }
        return 'attended';
    }

    private function buildOriginLabel(array $row): ?string
    {
        $parts = [];
        if (!empty($row['fuente'])) {
            $parts[] = (string) $row['fuente'];
        }
        if (!empty($row['utm_source'])) {
            $parts[] = (string) $row['utm_source'];
        }
        if (!empty($row['utm_campaign'])) {
            $parts[] = (string) $row['utm_campaign'];
        }

        return $parts ? implode(' · ', $parts) : null;
    }

    private function buildLastContactLabel(array $row): ?string
    {
        $lastInboundAt = $row['last_inbound_at'] ?? null;
        $lastOutboundAt = $row['last_outbound_at'] ?? null;

        if ($lastInboundAt && (!$lastOutboundAt || strtotime($lastInboundAt) >= strtotime($lastOutboundAt))) {
            return 'Último contacto: candidato';
        }
        if ($lastOutboundAt) {
            return 'Último contacto: reclutador';
        }

        return null;
    }

    private function registrarEvento(int $empresaId, int $leadId, string $type, string $label, array $payload): void
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

    private function safeEnum(?string $value, array $allowed): ?string
    {
        return ($value && in_array($value, $allowed, true)) ? $value : null;
    }

    private function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

