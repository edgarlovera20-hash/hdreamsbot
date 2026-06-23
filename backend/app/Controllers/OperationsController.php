<?php

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Services\RecruitmentFlowService;

class OperationsController
{
    private \mysqli $db;
    private RecruitmentFlowService $flow;

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
        $this->flow = new RecruitmentFlowService();
    }

    public function executive(): void
    {
        $empresaId = (int) ($_GET['empresa_id'] ?? 0);
        if (!$empresaId) {
            $this->json(['error' => 'empresa_id requerido'], 400);
            return;
        }

        AuthMiddleware::assertCompanyAccess($empresaId);
        AuthMiddleware::assertPermission('executive.view', $empresaId);

        $summary = [
            'active_leads' => $this->scalar("SELECT COUNT(*) AS total FROM leads WHERE empresa_id = $empresaId AND estado NOT IN ('rechazado','no_interesado','contratado')"),
            'pending_reply' => $this->scalar("SELECT COUNT(*) AS total FROM leads WHERE empresa_id = $empresaId AND (last_outbound_at IS NULL OR (last_inbound_at IS NOT NULL AND last_inbound_at > last_outbound_at)) AND estado NOT IN ('rechazado','no_interesado','contratado')"),
            'sla_overdue' => $this->scalar("SELECT COUNT(*) AS total FROM leads WHERE empresa_id = $empresaId AND next_action_at IS NOT NULL AND next_action_at < NOW() AND estado NOT IN ('rechazado','no_interesado','contratado')"),
            'interviews_today' => $this->scalar("SELECT COUNT(*) AS total FROM interviews WHERE empresa_id = $empresaId AND interview_date = CURDATE()"),
            'confirmed_today' => $this->scalar("SELECT COUNT(*) AS total FROM interviews WHERE empresa_id = $empresaId AND interview_date = CURDATE() AND status = 'confirmada'"),
            'hires_month' => $this->scalar("SELECT COUNT(*) AS total FROM leads WHERE empresa_id = $empresaId AND estado = 'contratado' AND DATE(fecha_contratado) >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"),
        ];

        $funnel = [];
        $funnelResult = $this->db->query(
            "SELECT current_stage, COUNT(*) AS total
             FROM leads
             WHERE empresa_id = $empresaId
             GROUP BY current_stage
             ORDER BY total DESC"
        );
        while ($row = $funnelResult?->fetch_assoc()) {
            $funnel[] = $row;
        }

        $channels = [];
        $channelResult = $this->db->query(
            "SELECT canal, COUNT(*) AS total
             FROM leads
             WHERE empresa_id = $empresaId
             GROUP BY canal
             ORDER BY total DESC"
        );
        while ($row = $channelResult?->fetch_assoc()) {
            $channels[] = $row;
        }

        $liveQueue = [];
        $liveResult = $this->db->query(
            "SELECT l.id, l.nombre, l.canal, l.prioridad, l.current_stage, l.next_action_at,
                    l.interview_status, r.nombre AS recruiter_nombre
             FROM leads l
             LEFT JOIN recruiters r ON r.id = l.assigned_recruiter_id
             WHERE l.empresa_id = $empresaId
               AND l.estado NOT IN ('rechazado','no_interesado','contratado')
             ORDER BY (l.next_action_at IS NULL) ASC, l.next_action_at ASC, FIELD(l.prioridad,'urgente','alta','media','baja')
             LIMIT 8"
        );
        while ($row = $liveResult?->fetch_assoc()) {
            $liveQueue[] = $row;
        }

        $this->json([
            'summary' => $summary,
            'forecast' => $this->buildForecast($empresaId),
            'finance' => $this->buildFinance($empresaId),
            'sites' => $this->buildSites($empresaId),
            'predictive' => $this->buildPredictiveInsights($empresaId),
            'funnel' => $funnel,
            'channels' => $channels,
            'recruiters' => $this->fetchRecruitersSla($empresaId),
            'live_queue' => $liveQueue,
        ]);
    }

    public function recruitersSla(): void
    {
        $empresaId = (int) ($_GET['empresa_id'] ?? 0);
        if (!$empresaId) {
            $this->json(['error' => 'empresa_id requerido'], 400);
            return;
        }

        AuthMiddleware::assertCompanyAccess($empresaId);
        AuthMiddleware::assertPermission('executive.view', $empresaId);

        $this->json([
            'items' => $this->fetchRecruitersSla($empresaId),
        ]);
    }

    private function fetchRecruitersSla(int $empresaId): array
    {
        $result = $this->db->query(
            "SELECT r.id, r.nombre, r.email, r.telefono, r.activo,
                    COUNT(DISTINCT CASE WHEN l.estado NOT IN ('rechazado','no_interesado','contratado') THEN l.id END) AS active_leads,
                    COUNT(DISTINCT CASE WHEN l.next_action_at IS NOT NULL AND l.next_action_at < NOW() AND l.estado NOT IN ('rechazado','no_interesado','contratado') THEN l.id END) AS sla_overdue,
                    COUNT(DISTINCT CASE WHEN l.next_action_at IS NOT NULL AND l.next_action_at >= NOW() AND l.estado NOT IN ('rechazado','no_interesado','contratado') THEN l.id END) AS upcoming_followups,
                    COUNT(DISTINCT CASE WHEN i.interview_date = CURDATE() THEN i.id END) AS interviews_today,
                    COUNT(DISTINCT CASE WHEN i.interview_date = CURDATE() AND i.status = 'confirmada' THEN i.id END) AS interviews_confirmed,
                    MAX(le.created_at) AS last_event_at
             FROM recruiters r
             LEFT JOIN leads l ON l.assigned_recruiter_id = r.id AND l.empresa_id = r.empresa_id
             LEFT JOIN interviews i ON i.recruiter_id = r.id AND i.empresa_id = r.empresa_id
             LEFT JOIN lead_events le ON le.lead_id = l.id
             WHERE r.empresa_id = $empresaId
             GROUP BY r.id, r.nombre, r.email, r.telefono, r.activo
             ORDER BY sla_overdue DESC, active_leads DESC, r.nombre ASC"
        );

        $items = [];
        while ($row = $result?->fetch_assoc()) {
            $activeLeads = max(0, (int) $row['active_leads']);
            $overdue = max(0, (int) $row['sla_overdue']);
            $row['sla_compliance_pct'] = $activeLeads > 0
                ? round((($activeLeads - $overdue) / $activeLeads) * 100, 1)
                : 100.0;
            $items[] = $row;
        }

        return $items;
    }

    private function buildForecast(int $empresaId): array
    {
        $totals = $this->db->query(
            "SELECT
                SUM(estado IN ('calificado','entrevista_agendada','entrevista_realizada','contratado')) AS qualified_total,
                SUM(estado = 'contratado') AS hires_total
             FROM leads
             WHERE empresa_id = $empresaId
               AND DATE(primera_interaccion) >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)"
        )?->fetch_assoc() ?? [];

        $activeQualified = $this->scalar(
            "SELECT COUNT(*) AS total
             FROM leads
             WHERE empresa_id = $empresaId
               AND estado IN ('calificado','entrevista_agendada','entrevista_realizada')"
        );

        $qualifiedTotal = max(1, (int) ($totals['qualified_total'] ?? 0));
        $hiresTotal = (int) ($totals['hires_total'] ?? 0);
        $conversion = round(($hiresTotal / $qualifiedTotal) * 100, 1);

        return [
            'conversion_qualified_to_hire_pct' => $conversion,
            'active_qualified_pipeline' => $activeQualified,
            'projected_hires_next_30d' => (int) round($activeQualified * ($conversion / 100)),
        ];
    }

    private function buildFinance(int $empresaId): array
    {
        $vacancies = $this->flow->getOverview()['vacancies'] ?? [];
        $items = [];

        foreach ($vacancies as $vacancy) {
            $slug = $this->db->real_escape_string((string) ($vacancy['slug'] ?? ''));
            $name = $vacancy['name'] ?? $slug;
            $items[] = [
                'vacancy' => $name,
                'weekly_salary' => (float) ($vacancy['weekly_salary'] ?? 0),
                'active_leads' => $this->scalar(
                    "SELECT COUNT(*) AS total
                     FROM leads
                     WHERE empresa_id = $empresaId
                       AND estado NOT IN ('rechazado','no_interesado','contratado')
                       AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.vacante')) IN ('$name', '$slug')"
                ),
                'hires_month' => $this->scalar(
                    "SELECT COUNT(*) AS total
                     FROM leads
                     WHERE empresa_id = $empresaId
                       AND estado = 'contratado'
                       AND DATE(fecha_contratado) >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                       AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.vacante')) IN ('$name', '$slug')"
                ),
            ];
        }

        return $items;
    }

    private function buildSites(int $empresaId): array
    {
        $result = $this->db->query(
            "SELECT s.id, s.nombre, s.ciudad, s.direccion,
                    COUNT(DISTINCT l.id) AS leads_total,
                    COUNT(DISTINCT CASE WHEN l.estado = 'contratado' THEN l.id END) AS hires_total,
                    COUNT(DISTINCT CASE WHEN i.status IN ('agendada','confirmada','reagendada') THEN i.id END) AS interviews_pending
             FROM company_sites s
             LEFT JOIN leads l ON l.site_id = s.id
             LEFT JOIN interviews i ON i.site_id = s.id
             WHERE s.empresa_id = $empresaId
             GROUP BY s.id, s.nombre, s.ciudad, s.direccion
             ORDER BY s.nombre ASC"
        );

        $items = [];
        while ($row = $result?->fetch_assoc()) {
            $items[] = $row;
        }

        return $items;
    }

    private function buildPredictiveInsights(int $empresaId): array
    {
        $noShowWatchlist = $this->buildNoShowWatchlist($empresaId);
        $reactivationQueue = $this->buildReactivationQueue($empresaId);
        $topCandidates = $this->buildTopCandidates($empresaId);
        $vacancyBottlenecks = $this->buildVacancyBottlenecks($empresaId);

        return [
            'summary' => [
                'high_risk_no_show' => count(array_filter($noShowWatchlist, fn (array $item) => ($item['risk_bucket'] ?? '') === 'alto')),
                'hot_reactivation' => count(array_filter($reactivationQueue, fn (array $item) => (int) ($item['reactivation_score'] ?? 0) >= 70)),
                'priority_candidates' => count(array_filter($topCandidates, fn (array $item) => (int) ($item['hire_readiness_score'] ?? 0) >= 75)),
                'bottleneck_vacancies' => count(array_filter($vacancyBottlenecks, fn (array $item) => (int) ($item['bottleneck_score'] ?? 0) >= 55)),
            ],
            'no_show_watchlist' => $noShowWatchlist,
            'reactivation_queue' => $reactivationQueue,
            'top_candidates' => $topCandidates,
            'vacancy_bottlenecks' => $vacancyBottlenecks,
            'coach_actions' => $this->buildCoachActions($noShowWatchlist, $reactivationQueue, $topCandidates, $vacancyBottlenecks),
        ];
    }

    private function buildNoShowWatchlist(int $empresaId): array
    {
        $result = $this->db->query(
            "SELECT i.id, i.lead_id, i.interview_date, i.interview_time, i.status,
                    i.reminder_24h_sent_at, i.reminder_2h_sent_at, i.office_location,
                    l.nombre, l.canal, l.prioridad, l.current_stage, l.interview_status,
                    l.last_inbound_at, l.last_outbound_at, l.next_action_at,
                    l.mensajes_recibidos, l.mensajes_enviados,
                    r.nombre AS recruiter_nombre,
                    (
                        SELECT COUNT(*)
                        FROM interviews i2
                        WHERE i2.lead_id = l.id AND i2.status = 'no_show'
                    ) AS previous_no_shows
             FROM interviews i
             JOIN leads l ON l.id = i.lead_id
             LEFT JOIN recruiters r ON r.id = i.recruiter_id
             WHERE i.empresa_id = $empresaId
               AND i.interview_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
               AND i.status IN ('agendada','confirmada','reagendada')
             ORDER BY i.interview_date ASC, i.interview_time ASC
             LIMIT 20"
        );

        $items = [];
        while ($row = $result?->fetch_assoc()) {
            $factors = [];
            $risk = 15;
            $interviewAt = strtotime(($row['interview_date'] ?? '') . ' ' . ($row['interview_time'] ?? '00:00:00'));
            $hoursToInterview = $interviewAt ? max(0, (int) floor(($interviewAt - time()) / 3600)) : null;

            if (($row['status'] ?? '') !== 'confirmada') {
                $risk += 18;
                $factors[] = 'Entrevista sin confirmación final';
            }
            if ((int) ($row['previous_no_shows'] ?? 0) > 0) {
                $risk += 24;
                $factors[] = 'Historial previo de no-show';
            }
            if (empty($row['last_outbound_at'])) {
                $risk += 10;
                $factors[] = 'Sin contacto saliente registrado';
            }
            if ($this->hoursSince($row['last_inbound_at'] ?? null) >= 48) {
                $risk += 12;
                $factors[] = 'Más de 48h sin respuesta del candidato';
            }
            if ((int) ($row['mensajes_recibidos'] ?? 0) <= 1) {
                $risk += 8;
                $factors[] = 'Baja interacción del candidato';
            }
            if (($row['interview_status'] ?? '') === 'reagendada') {
                $risk += 10;
                $factors[] = 'Entrevista reagendada';
            }
            if ($hoursToInterview !== null && $hoursToInterview <= 24 && empty($row['reminder_24h_sent_at'])) {
                $risk += 10;
                $factors[] = 'Sin recordatorio 24h';
            }
            if ($hoursToInterview !== null && $hoursToInterview <= 3 && empty($row['reminder_2h_sent_at'])) {
                $risk += 12;
                $factors[] = 'Sin recordatorio 2h';
            }

            $row['hours_to_interview'] = $hoursToInterview;
            $row['risk_score'] = min(99, $risk);
            $row['risk_bucket'] = $risk >= 70 ? 'alto' : ($risk >= 45 ? 'medio' : 'bajo');
            $row['risk_factors'] = $factors;
            $items[] = $row;
        }

        usort($items, fn (array $a, array $b) => ($b['risk_score'] <=> $a['risk_score']) ?: strcmp((string) $a['interview_date'], (string) $b['interview_date']));
        return array_slice($items, 0, 8);
    }

    private function buildReactivationQueue(int $empresaId): array
    {
        $result = $this->db->query(
            "SELECT l.id, l.nombre, l.canal, l.prioridad, l.current_stage, l.next_action_at,
                    l.last_inbound_at, l.last_outbound_at, l.ultima_interaccion,
                    l.interview_status, l.assigned_recruiter_id,
                    r.nombre AS recruiter_nombre,
                    COALESCE(lsi.score_prioridad, 0) AS score_prioridad,
                    COALESCE(JSON_UNQUOTE(JSON_EXTRACT(l.metadata, '$.vacante')), 'Sin vacante') AS vacante
             FROM leads l
             LEFT JOIN recruiters r ON r.id = l.assigned_recruiter_id
             LEFT JOIN lead_scoring_ia lsi ON lsi.lead_id = l.id
             WHERE l.empresa_id = $empresaId
               AND l.estado NOT IN ('rechazado','no_interesado','contratado')
               AND (
                    l.next_action_at IS NOT NULL AND l.next_action_at < NOW()
                    OR l.ultima_interaccion < DATE_SUB(NOW(), INTERVAL 36 HOUR)
                    OR (l.last_inbound_at IS NOT NULL AND (l.last_outbound_at IS NULL OR l.last_inbound_at > l.last_outbound_at))
               )
             ORDER BY l.ultima_interaccion ASC
             LIMIT 25"
        );

        $items = [];
        while ($row = $result?->fetch_assoc()) {
            $score = 20;
            $factors = [];
            $hoursSilent = $this->hoursSince($row['ultima_interaccion'] ?? null);

            if (!empty($row['next_action_at']) && strtotime((string) $row['next_action_at']) < time()) {
                $score += 22;
                $factors[] = 'Siguiente acción vencida';
            }
            if ($hoursSilent >= 72) {
                $score += 20;
                $factors[] = 'Lead congelado por 72h o más';
            } elseif ($hoursSilent >= 36) {
                $score += 12;
                $factors[] = 'Más de 36h sin movimiento';
            }
            if (!empty($row['last_inbound_at']) && (empty($row['last_outbound_at']) || strtotime((string) $row['last_inbound_at']) > strtotime((string) $row['last_outbound_at']))) {
                $score += 18;
                $factors[] = 'Candidato esperando respuesta';
            }
            if (($row['prioridad'] ?? '') === 'urgente') {
                $score += 15;
                $factors[] = 'Prioridad urgente';
            } elseif (($row['prioridad'] ?? '') === 'alta') {
                $score += 10;
                $factors[] = 'Prioridad alta';
            }
            if (in_array($row['current_stage'] ?? '', ['calificado', 'entrevista_agendada'], true)) {
                $score += 10;
                $factors[] = 'Lead avanzado en el funnel';
            }
            if (empty($row['assigned_recruiter_id'])) {
                $score += 8;
                $factors[] = 'Sin recruiter asignado';
            }

            $row['hours_silent'] = $hoursSilent;
            $row['reactivation_score'] = min(99, $score);
            $row['reactivation_factors'] = $factors;
            $items[] = $row;
        }

        usort($items, fn (array $a, array $b) => ($b['reactivation_score'] <=> $a['reactivation_score']) ?: ($b['score_prioridad'] <=> $a['score_prioridad']));
        return array_slice($items, 0, 8);
    }

    private function buildTopCandidates(int $empresaId): array
    {
        $result = $this->db->query(
            "SELECT l.id, l.nombre, l.canal, l.prioridad, l.current_stage, l.interview_status,
                    l.assigned_recruiter_id, l.next_action_at,
                    COALESCE(lsi.score_candidato, 0) AS score_candidato,
                    COALESCE(lsi.score_contratacion, 0) AS score_contratacion,
                    COALESCE(lsi.score_prioridad, 0) AS score_prioridad,
                    COALESCE(JSON_UNQUOTE(JSON_EXTRACT(l.metadata, '$.vacante')), 'Sin vacante') AS vacante,
                    r.nombre AS recruiter_nombre
             FROM leads l
             LEFT JOIN lead_scoring_ia lsi ON lsi.lead_id = l.id
             LEFT JOIN recruiters r ON r.id = l.assigned_recruiter_id
             WHERE l.empresa_id = $empresaId
               AND l.estado IN ('calificado','entrevista_agendada','entrevista_realizada')
             ORDER BY COALESCE(lsi.score_contratacion, 0) DESC, COALESCE(lsi.score_prioridad, 0) DESC
             LIMIT 20"
        );

        $items = [];
        while ($row = $result?->fetch_assoc()) {
            $score = (int) round(
                ((float) $row['score_contratacion'] * 0.45) +
                ((float) $row['score_candidato'] * 0.30) +
                ((float) $row['score_prioridad'] * 0.15)
            );
            $factors = [];

            if (($row['interview_status'] ?? '') === 'confirmada') {
                $score += 12;
                $factors[] = 'Entrevista confirmada';
            } elseif (($row['interview_status'] ?? '') === 'agendada') {
                $score += 7;
                $factors[] = 'Entrevista agendada';
            }
            if (($row['prioridad'] ?? '') === 'urgente') {
                $score += 8;
                $factors[] = 'Prioridad urgente';
            }
            if (($row['current_stage'] ?? '') === 'entrevista_agendada') {
                $score += 8;
                $factors[] = 'Muy cerca del cierre';
            }
            if (!empty($row['assigned_recruiter_id'])) {
                $factors[] = 'Ya tiene recruiter asignado';
            }

            $row['hire_readiness_score'] = min(99, $score);
            $row['readiness_factors'] = $factors;
            $items[] = $row;
        }

        usort($items, fn (array $a, array $b) => $b['hire_readiness_score'] <=> $a['hire_readiness_score']);
        return array_slice($items, 0, 8);
    }

    private function buildVacancyBottlenecks(int $empresaId): array
    {
        $result = $this->db->query(
            "SELECT
                COALESCE(JSON_UNQUOTE(JSON_EXTRACT(l.metadata, '$.vacante')), 'Sin vacante') AS vacancy,
                COUNT(*) AS active_leads,
                SUM(l.estado IN ('calificado','entrevista_agendada','entrevista_realizada')) AS qualified_leads,
                SUM(l.estado = 'contratado' AND DATE(l.fecha_contratado) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) AS hires_30d,
                SUM(l.next_action_at IS NOT NULL AND l.next_action_at < NOW() AND l.estado NOT IN ('rechazado','no_interesado','contratado')) AS overdue_followups,
                AVG(CASE WHEN l.ultima_interaccion IS NOT NULL THEN TIMESTAMPDIFF(HOUR, l.ultima_interaccion, NOW()) ELSE 0 END) AS avg_idle_hours,
                SUM(l.interview_status = 'no_show') AS no_show_leads
             FROM leads l
             WHERE l.empresa_id = $empresaId
               AND l.estado NOT IN ('rechazado','no_interesado')
             GROUP BY vacancy
             ORDER BY active_leads DESC"
        );

        $items = [];
        while ($row = $result?->fetch_assoc()) {
            $active = (int) ($row['active_leads'] ?? 0);
            $qualified = (int) ($row['qualified_leads'] ?? 0);
            $hires = (int) ($row['hires_30d'] ?? 0);
            $overdue = (int) ($row['overdue_followups'] ?? 0);
            $avgIdle = (float) ($row['avg_idle_hours'] ?? 0);
            $noShow = (int) ($row['no_show_leads'] ?? 0);

            $score = 10;
            if ($active >= 8 && $hires === 0) {
                $score += 26;
            }
            if ($qualified > 0 && $hires === 0) {
                $score += 14;
            }
            if ($overdue >= 3) {
                $score += 18;
            } elseif ($overdue >= 1) {
                $score += 8;
            }
            if ($avgIdle >= 72) {
                $score += 18;
            } elseif ($avgIdle >= 36) {
                $score += 10;
            }
            if ($noShow >= 2) {
                $score += 12;
            }

            $row['avg_idle_hours'] = round($avgIdle, 1);
            $row['bottleneck_score'] = min(99, $score);
            $row['bottleneck_label'] = $score >= 55 ? 'critico' : ($score >= 35 ? 'atencion' : 'estable');
            $items[] = $row;
        }

        usort($items, fn (array $a, array $b) => $b['bottleneck_score'] <=> $a['bottleneck_score']);
        return array_slice($items, 0, 6);
    }

    private function buildCoachActions(array $noShowWatchlist, array $reactivationQueue, array $topCandidates, array $vacancyBottlenecks): array
    {
        $actions = [];

        if (!empty($noShowWatchlist)) {
            $top = $noShowWatchlist[0];
            $actions[] = [
                'type' => 'no_show_prevention',
                'title' => 'Blindar entrevistas con mayor riesgo',
                'detail' => sprintf(
                    '%s presenta riesgo %s (%d/99). Conviene enviar recordatorio y confirmar asistencia hoy.',
                    $top['nombre'] ?? ('Lead ' . ($top['lead_id'] ?? '')),
                    $top['risk_bucket'] ?? 'medio',
                    (int) ($top['risk_score'] ?? 0)
                ),
            ];
        }

        if (!empty($reactivationQueue)) {
            $top = $reactivationQueue[0];
            $actions[] = [
                'type' => 'reactivation',
                'title' => 'Reactivar leads calientes antes de perderlos',
                'detail' => sprintf(
                    '%s lleva %dh sin movimiento y score de reactivación %d/99.',
                    $top['nombre'] ?? ('Lead ' . ($top['id'] ?? '')),
                    (int) ($top['hours_silent'] ?? 0),
                    (int) ($top['reactivation_score'] ?? 0)
                ),
            ];
        }

        if (!empty($topCandidates)) {
            $top = $topCandidates[0];
            $actions[] = [
                'type' => 'closing',
                'title' => 'Empujar cierres probables',
                'detail' => sprintf(
                    '%s para %s tiene hire readiness %d/99. Prioriza cierre y documentación.',
                    $top['nombre'] ?? ('Lead ' . ($top['id'] ?? '')),
                    $top['vacante'] ?? 'vacante activa',
                    (int) ($top['hire_readiness_score'] ?? 0)
                ),
            ];
        }

        if (!empty($vacancyBottlenecks)) {
            $top = $vacancyBottlenecks[0];
            $actions[] = [
                'type' => 'vacancy_bottleneck',
                'title' => 'Destrabar vacante con fricción',
                'detail' => sprintf(
                    '%s tiene bottleneck %d/99 con %d leads activos y %d seguimientos vencidos.',
                    $top['vacancy'] ?? 'Vacante',
                    (int) ($top['bottleneck_score'] ?? 0),
                    (int) ($top['active_leads'] ?? 0),
                    (int) ($top['overdue_followups'] ?? 0)
                ),
            ];
        }

        return array_slice($actions, 0, 4);
    }

    private function hoursSince(?string $datetime): int
    {
        if (!$datetime) {
            return 999;
        }

        $timestamp = strtotime($datetime);
        if (!$timestamp) {
            return 999;
        }

        return max(0, (int) floor((time() - $timestamp) / 3600));
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
