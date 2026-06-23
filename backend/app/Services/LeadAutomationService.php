<?php

namespace App\Services;

class LeadAutomationService
{
    private RecruitmentFlowService $flow;
    private NotificationService $notifications;

    public function __construct(private \mysqli $db)
    {
        $this->flow = new RecruitmentFlowService();
        $this->notifications = new NotificationService($db);
    }

    public function sendTemplate(array $lead, string $templateKey, array $context = []): bool
    {
        $message = $this->flow->renderTemplate($templateKey, $lead);
        if (!$message) {
            return false;
        }

        return $this->sendMessage($lead, $message, $templateKey, $context);
    }

    public function sendPlaybookStep(array $lead, array $step): bool
    {
        $message = $this->flow->renderPlaybookMessage($step, $lead);
        if (!$message) {
            return false;
        }

        return $this->sendMessage($lead, $message, 'playbook:' . ($step['key'] ?? 'unknown'), [
            'playbook_step' => $step['key'] ?? null,
            'trigger_stage' => $step['trigger_stage'] ?? null,
        ]);
    }

    public function runVacancyPlaybook(array $lead): bool
    {
        $metadata = json_decode($lead['metadata'] ?? '{}', true) ?: [];
        $vacancyKey = $metadata['vacante'] ?? ($lead['seccion_nombre'] ?? $lead['seccion'] ?? null);
        $steps = $this->flow->getVacancyPlaybook($vacancyKey);
        if (!$steps) {
            return false;
        }

        foreach ($steps as $step) {
            $triggerStage = (string) ($step['trigger_stage'] ?? '');
            $stepKey = (string) ($step['key'] ?? '');
            $delayHours = (int) ($step['delay_hours'] ?? 0);
            if ($triggerStage === '' || $stepKey === '') {
                continue;
            }
            if (($lead['current_stage'] ?? '') !== $triggerStage) {
                continue;
            }
            if (($lead['playbook_step'] ?? '') === $stepKey) {
                continue;
            }

            $lastReference = $lead['last_automation_contact_at'] ?? $lead['last_outbound_at'] ?? $lead['ultima_interaccion'] ?? $lead['created_at'] ?? null;
            if ($lastReference && strtotime($lastReference) > strtotime("-{$delayHours} hours")) {
                continue;
            }

            $sent = $this->sendPlaybookStep($lead, $step);
            if ($sent) {
                $leadId = (int) $lead['id'];
                $stepEsc = $this->db->real_escape_string($stepKey);
                $vacancyEsc = $this->db->real_escape_string((string) $vacancyKey);
                $nextActionAt = date('Y-m-d H:i:s', strtotime('+6 hours'));
                $this->db->query(
                    "UPDATE leads
                     SET playbook_key = '$vacancyEsc',
                         playbook_step = '$stepEsc',
                         playbook_last_run_at = NOW(),
                         next_action_type = 'automation_followup',
                         next_action_at = '$nextActionAt'
                     WHERE id = $leadId"
                );
                return true;
            }
        }

        return false;
    }

    public function createRun(int $empresaId, ?int $leadId, ?int $interviewId, string $automationKey, string $status, array $payload = []): void
    {
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $this->db->prepare(
            "INSERT INTO automation_runs (empresa_id, lead_id, interview_id, automation_key, status, payload)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('iiisss', $empresaId, $leadId, $interviewId, $automationKey, $status, $payloadJson);
        $stmt->execute();
        $stmt->close();
    }

    public function createNotification(int $empresaId, ?int $recruiterId, string $type, string $title, string $message, ?int $leadId = null): void
    {
        $this->notifications->create([
            'empresa_id' => $empresaId,
            'recruiter_id' => $recruiterId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'severity' => 'info',
            'entity_type' => $leadId ? 'lead' : 'system',
            'entity_id' => $leadId,
            'action_url' => $leadId ? "/leads/$leadId" : null,
        ]);
    }

    private function sendMessage(array $lead, string $message, string $automationKey, array $context = []): bool
    {
        $empresaId = (int) ($lead['empresa_id'] ?? 0);
        $seccionId = (int) ($lead['seccion_id'] ?? 0);
        $leadId = (int) ($lead['id'] ?? 0);
        $canal = $this->db->real_escape_string((string) ($lead['canal'] ?? ''));
        $channelConfig = $this->db->query(
            "SELECT * FROM canales
             WHERE empresa_id = $empresaId
               AND seccion_id = $seccionId
               AND canal = '$canal'
               AND activo = 1
             LIMIT 1"
        )?->fetch_assoc();

        if (!$channelConfig) {
            return false;
        }

        $config = json_decode($channelConfig['config'] ?? '{}', true) ?: [];
        $channelConfig['access_token'] = $channelConfig['access_token'] ?? ($config['access_token'] ?? '');

        $manager = new CanalManager($empresaId, $seccionId, $this->db, $channelConfig);
        $sent = $manager->enviarRespuesta((string) ($lead['canal_user_id'] ?? ''), $message);
        $status = $sent ? 'sent' : 'failed';

        $this->createRun($empresaId, $leadId ?: null, null, $automationKey, $status, $context + ['channel' => $lead['canal'] ?? null]);

        if (!$sent) {
            return false;
        }

        $stmt = $this->db->prepare(
            "INSERT INTO ia_logs (empresa_id, seccion_id, wa_id, canal, pregunta, respuesta)
             VALUES (?, ?, ?, ?, NULL, ?)"
        );
        $userId = (string) ($lead['canal_user_id'] ?? '');
        $canalValue = (string) ($lead['canal'] ?? '');
        $stmt->bind_param('iisss', $empresaId, $seccionId, $userId, $canalValue, $message);
        $stmt->execute();
        $stmt->close();

        $automationKeyEsc = $this->db->real_escape_string($automationKey);
        $messageEsc = $this->db->real_escape_string($message);
        $this->db->query(
            "UPDATE leads
             SET last_outbound_at = NOW(),
                 ultima_interaccion = NOW(),
                 last_automation_contact_at = NOW(),
                 next_action_type = CASE
                    WHEN '$automationKeyEsc' LIKE 'playbook:%' THEN 'awaiting_candidate_reply'
                    ELSE next_action_type
                 END
             WHERE id = $leadId"
        );

        $payloadJson = json_encode($context + ['automation_key' => $automationKey], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $actorType = 'bot';
        $actorId = 0;
        $label = 'Automatización enviada';
        $type = 'automation_sent';
        $eventStmt = $this->db->prepare(
            "INSERT INTO lead_events (lead_id, empresa_id, event_type, event_label, payload, actor_type, actor_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $eventStmt->bind_param('iissssi', $leadId, $empresaId, $type, $label, $payloadJson, $actorType, $actorId);
        $eventStmt->execute();
        $eventStmt->close();

        $this->createNotification(
            $empresaId,
            isset($lead['assigned_recruiter_id']) ? (int) $lead['assigned_recruiter_id'] : null,
            'automation_sent',
            'Automatización ejecutada',
            "Se envió '$automationKeyEsc' al lead " . ($lead['nombre'] ?? "#$leadId"),
            $leadId
        );

        return true;
    }
}
