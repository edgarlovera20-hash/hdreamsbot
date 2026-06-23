<?php

namespace App\Services;

class RecruitmentCopilotService
{
    private AIClient $ai;
    private RecruitmentFlowService $flow;

    public function __construct(private \mysqli $db)
    {
        $this->ai = new AIClient();
        $this->flow = new RecruitmentFlowService();
    }

    public function analyzeLead(int $leadId): ?array
    {
        $lead = $this->fetchLead($leadId);
        if (!$lead) {
            return null;
        }

        $messages = $this->fetchMessages($lead);
        $recruiters = $this->fetchRecruiters((int) $lead['empresa_id']);
        $fallback = $this->fallbackAnalysis($lead, $messages, $recruiters);
        $analysis = $this->callModel($lead, $messages, $recruiters) ?? $fallback;

        $this->persistAnalysis($leadId, $analysis);
        return $analysis;
    }

    public function autoAssignLead(int $leadId): ?array
    {
        $lead = $this->fetchLead($leadId);
        if (!$lead) {
            return null;
        }

        $recruiters = $this->fetchRecruiters((int) $lead['empresa_id']);
        if (!$recruiters) {
            return null;
        }

        $best = null;
        foreach ($recruiters as $recruiter) {
            $workloadScore = ((int) $recruiter['active_leads'] * 2) + ((int) $recruiter['sla_overdue'] * 6) + ((int) $recruiter['interviews_today'] * 3);
            if (!$best || $workloadScore < $best['workload_score']) {
                $best = $recruiter + ['workload_score' => $workloadScore];
            }
        }

        if (!$best) {
            return null;
        }

        $recruiterId = (int) $best['id'];
        $leadId = (int) $lead['id'];
        $this->db->query("UPDATE leads SET assigned_recruiter_id = $recruiterId WHERE id = $leadId");
        $this->db->query(
            "INSERT INTO lead_assignments (lead_id, recruiter_id, assigned_by, reason)
             VALUES ($leadId, $recruiterId, NULL, 'auto_assign_ia')"
        );

        $payload = [
            'recommended_recruiter_id' => $recruiterId,
            'recommended_recruiter' => $best['nombre'],
            'workload_score' => $best['workload_score'],
        ];
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->db->query(
            "INSERT INTO ai_copilot_runs (empresa_id, lead_id, recruiter_id, run_type, payload)
             VALUES ({$lead['empresa_id']}, $leadId, $recruiterId, 'auto_assign', '{$this->db->real_escape_string($payloadJson)}')"
        );

        return [
            'lead_id' => $leadId,
            'recruiter_id' => $recruiterId,
            'recruiter_nombre' => $best['nombre'],
            'reason' => 'Menor saturación operativa y mejor cumplimiento SLA',
        ];
    }

    private function callModel(array $lead, array $messages, array $recruiters): ?array
    {
        $messagesSummary = array_slice(array_map(
            fn (array $message) => strtoupper($message['direction']) . ': ' . $message['text'],
            $messages
        ), -10);

        $recruiterSummary = array_map(static fn (array $recruiter) => [
            'nombre' => $recruiter['nombre'],
            'active_leads' => (int) $recruiter['active_leads'],
            'sla_overdue' => (int) $recruiter['sla_overdue'],
            'interviews_today' => (int) $recruiter['interviews_today'],
        ], $recruiters);

        $prompt = <<<PROMPT
Eres copiloto de reclutamiento. Analiza este lead y responde JSON válido.

LEAD:
- nombre: {$lead['nombre']}
- vacante: {$lead['vacante']}
- etapa: {$lead['current_stage']}
- prioridad: {$lead['prioridad']}
- entrevista: {$lead['interview_record_status']}
- ciudad: {$lead['ciudad']}
- experiencia: {$lead['experiencia']}

MENSAJES RECIENTES:
{$this->toBulletText($messagesSummary)}

RECLUTADORES DISPONIBLES:
{$this->toBulletText(array_map(fn ($r) => "{$r['nombre']} | leads={$r['active_leads']} | sla_vencido={$r['sla_overdue']} | entrevistas_hoy={$r['interviews_today']}", $recruiterSummary))}

Devuelve JSON con estas llaves:
- summary
- conversation_score (0-100)
- candidate_temperature (cold|warm|hot)
- recommended_action
- suggested_reply
- recommended_recruiter_name
- recruiter_reason
- risks (array)

PROMPT;

        $response = $this->ai->chatJson($prompt);
        if (!$response) {
            return null;
        }

        return [
            'summary' => (string) ($response['summary'] ?? ''),
            'conversation_score' => (float) ($response['conversation_score'] ?? 0),
            'candidate_temperature' => (string) ($response['candidate_temperature'] ?? 'warm'),
            'recommended_action' => (string) ($response['recommended_action'] ?? 'dar seguimiento'),
            'suggested_reply' => (string) ($response['suggested_reply'] ?? ''),
            'recommended_recruiter_name' => (string) ($response['recommended_recruiter_name'] ?? ''),
            'recruiter_reason' => (string) ($response['recruiter_reason'] ?? ''),
            'risks' => is_array($response['risks'] ?? null) ? $response['risks'] : [],
        ];
    }

    private function fallbackAnalysis(array $lead, array $messages, array $recruiters): array
    {
        $lastInbound = null;
        foreach (array_reverse($messages) as $message) {
            if ($message['direction'] === 'inbound') {
                $lastInbound = $message['text'];
                break;
            }
        }

        $temperature = $lead['interview_record_status'] === 'confirmada' ? 'hot' : ((count($messages) >= 4 || ($lead['current_stage'] ?? '') === 'calificado') ? 'warm' : 'cold');
        $score = $temperature === 'hot' ? 88 : ($temperature === 'warm' ? 67 : 42);
        $bestRecruiter = $recruiters[0]['nombre'] ?? 'Sin sugerencia';

        return [
            'summary' => $lastInbound ? "Último mensaje del candidato: {$lastInbound}" : 'Sin contexto conversacional suficiente; se recomienda seguimiento humano.',
            'conversation_score' => $score,
            'candidate_temperature' => $temperature,
            'recommended_action' => ($lead['current_stage'] ?? '') === 'calificado' ? 'agendar entrevista' : 'retomar conversación',
            'suggested_reply' => $this->flow->renderTemplate('qualification_success', $lead) ?? 'Hola, continúo con tu proceso. ¿Sigues disponible para avanzar hoy?',
            'recommended_recruiter_name' => $bestRecruiter,
            'recruiter_reason' => 'Sugerencia heurística por disponibilidad operativa.',
            'risks' => $lastInbound ? [] : ['sin_contexto_mensajes'],
        ];
    }

    private function persistAnalysis(int $leadId, array $analysis): void
    {
        $summaryEsc = $this->db->real_escape_string((string) ($analysis['summary'] ?? ''));
        $actionEsc = $this->db->real_escape_string((string) ($analysis['recommended_action'] ?? ''));
        $score = (float) ($analysis['conversation_score'] ?? 0);
        $this->db->query(
            "UPDATE leads
             SET conversation_score = $score,
                 ai_recommended_action = '$actionEsc',
                 ai_summary = '$summaryEsc',
                 ai_last_analysis_at = NOW()
             WHERE id = $leadId"
        );

        $payloadJson = json_encode($analysis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $payloadEsc = $this->db->real_escape_string((string) $payloadJson);
        $lead = $this->fetchLead($leadId);
        if ($lead) {
            $empresaId = (int) $lead['empresa_id'];
            $recruiterId = $lead['assigned_recruiter_id'] ? (int) $lead['assigned_recruiter_id'] : 'NULL';
            $this->db->query(
                "INSERT INTO ai_copilot_runs (empresa_id, lead_id, recruiter_id, run_type, payload)
                 VALUES ($empresaId, $leadId, $recruiterId, 'analysis', '$payloadEsc')"
            );
        }
    }

    private function fetchLead(int $leadId): ?array
    {
        $lead = $this->db->query(
            "SELECT l.*, s.nombre AS seccion_nombre,
                    r.nombre AS recruiter_nombre,
                    i.status AS interview_record_status,
                    i.interview_date,
                    i.interview_time
             FROM leads l
             JOIN secciones s ON s.id = l.seccion_id
             LEFT JOIN recruiters r ON r.id = l.assigned_recruiter_id
             LEFT JOIN interviews i ON i.id = (
                SELECT i2.id FROM interviews i2 WHERE i2.lead_id = l.id
                ORDER BY i2.interview_date DESC, i2.interview_time DESC, i2.created_at DESC LIMIT 1
             )
             WHERE l.id = $leadId"
        )?->fetch_assoc();

        if (!$lead) {
            return null;
        }

        $metadata = json_decode($lead['metadata'] ?? '{}', true) ?: [];
        $lead['vacante'] = $metadata['vacante'] ?? ($lead['seccion_nombre'] ?? 'vacante');
        $lead['ciudad'] = $metadata['ciudad'] ?? null;
        $lead['experiencia'] = $metadata['experiencia'] ?? null;

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
                $messages[] = ['direction' => 'inbound', 'text' => $row['pregunta'], 'created_at' => $row['created_at']];
            }
            if (!empty($row['respuesta'])) {
                $messages[] = ['direction' => 'outbound', 'text' => $row['respuesta'], 'created_at' => $row['created_at']];
            }
        }

        return $messages;
    }

    private function fetchRecruiters(int $empresaId): array
    {
        $result = $this->db->query(
            "SELECT r.id, r.nombre,
                    COUNT(DISTINCT CASE WHEN l.estado NOT IN ('rechazado','no_interesado','contratado') THEN l.id END) AS active_leads,
                    COUNT(DISTINCT CASE WHEN l.next_action_at IS NOT NULL AND l.next_action_at < NOW() THEN l.id END) AS sla_overdue,
                    COUNT(DISTINCT CASE WHEN i.interview_date = CURDATE() THEN i.id END) AS interviews_today
             FROM recruiters r
             LEFT JOIN leads l ON l.assigned_recruiter_id = r.id AND l.empresa_id = r.empresa_id
             LEFT JOIN interviews i ON i.recruiter_id = r.id AND i.empresa_id = r.empresa_id
             WHERE r.empresa_id = $empresaId AND r.activo = 1
             GROUP BY r.id, r.nombre
             ORDER BY active_leads ASC, sla_overdue ASC, interviews_today ASC, r.nombre ASC"
        );

        $items = [];
        while ($row = $result?->fetch_assoc()) {
            $items[] = $row;
        }

        return $items;
    }

    private function toBulletText(array $items): string
    {
        if (!$items) {
            return "- sin datos";
        }

        return implode("\n", array_map(static fn ($item) => '- ' . $item, $items));
    }
}
