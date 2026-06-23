<?php

namespace App\Services;

class RecruitmentFlowService
{
    private array $config;

    public function __construct()
    {
        $this->config = require __DIR__ . '/../../config/recruitment_flow.php';
    }

    public function getOverview(): array
    {
        return $this->config;
    }

    public function getInterviewSlots24h(): array
    {
        return array_map(function (string $slot): string {
            $time = \DateTime::createFromFormat('h:i A', $slot);
            return $time ? $time->format('H:i:s') : '09:30:00';
        }, $this->config['interview_slots'] ?? []);
    }

    public function getOfficeAddress(): string
    {
        return implode(', ', $this->config['company']['office_address'] ?? []);
    }

    public function getInboxTemplates(): array
    {
        return [
            ['key' => 'initial_greeting', 'label' => 'Saludo inicial'],
            ['key' => 'qualification_success', 'label' => 'Perfil compatible'],
            ['key' => 'interview_confirmation', 'label' => 'Confirmación entrevista'],
            ['key' => 'reminder_24h', 'label' => 'Recordatorio 24h'],
            ['key' => 'reminder_2h', 'label' => 'Recordatorio 2h'],
            ['key' => 'no_show', 'label' => 'No show'],
            ['key' => 'hired', 'label' => 'Contratación'],
        ];
    }

    public function renderTemplate(string $key, array $lead): ?string
    {
        $template = $this->config['messages'][$key] ?? null;
        if (!$template) {
            return null;
        }

        $metadata = json_decode($lead['metadata'] ?? '{}', true) ?: [];
        $vars = [
            '{{nombre}}' => $lead['nombre'] ?? 'candidato',
            '{{edad}}' => (string) ($lead['edad'] ?? ''),
            '{{telefono}}' => $lead['telefono'] ?? '',
            '{{vacante}}' => $metadata['vacante'] ?? ($lead['seccion_nombre'] ?? $lead['seccion'] ?? 'vacante'),
            '{{experiencia}}' => $metadata['experiencia'] ?? '',
            '{{ciudad}}' => $metadata['ciudad'] ?? '',
            '{{fecha_entrevista}}' => $this->formatInterviewDate($lead['interview_date'] ?? null),
            '{{hora_entrevista}}' => $this->formatInterviewTime($lead['interview_time'] ?? null),
            '{{reclutador}}' => $lead['recruiter_nombre'] ?? ($this->config['company']['contact_name'] ?? 'reclutador'),
            '{{estatus}}' => $lead['estado'] ?? '',
            '{{horarios_disponibles}}' => implode(', ', $this->config['interview_slots'] ?? []),
        ];

        return strtr($template, $vars);
    }

    public function getInboxMacros(): array
    {
        return [
            ['key' => 'mark_contacted', 'label' => 'Marcar contactado'],
            ['key' => 'mark_qualified', 'label' => 'Marcar calificado'],
            ['key' => 'followup_tomorrow', 'label' => 'Seguimiento mañana'],
            ['key' => 'mark_reagendar', 'label' => 'Pedir reagenda'],
            ['key' => 'mark_rejected', 'label' => 'Cerrar rechazado'],
        ];
    }

    public function getVacancyPlaybook(?string $slugOrName): array
    {
        if (!$slugOrName) {
            return [];
        }

        $needle = $this->normalize($slugOrName);
        foreach (($this->config['vacancy_playbooks'] ?? []) as $key => $steps) {
            if ($needle === $this->normalize((string) $key)) {
                return $steps;
            }
        }

        $vacancy = $this->getVacancyProfile($slugOrName);
        if (!$vacancy) {
            return [];
        }

        $slug = $this->normalize((string) ($vacancy['slug'] ?? ''));
        foreach (($this->config['vacancy_playbooks'] ?? []) as $key => $steps) {
            if ($slug === $this->normalize((string) $key)) {
                return $steps;
            }
        }

        return [];
    }

    public function renderPlaybookMessage(array $step, array $lead): ?string
    {
        $template = (string) ($step['message'] ?? '');
        if ($template === '') {
            return null;
        }

        $metadata = json_decode($lead['metadata'] ?? '{}', true) ?: [];
        $vars = [
            '{{nombre}}' => $lead['nombre'] ?? 'candidato',
            '{{vacante}}' => $metadata['vacante'] ?? ($lead['seccion_nombre'] ?? $lead['seccion'] ?? 'vacante'),
            '{{horarios_disponibles}}' => implode(', ', $this->config['interview_slots'] ?? []),
        ];

        return strtr($template, $vars);
    }

    private function formatInterviewDate(?string $date): string
    {
        if (!$date) {
            return '';
        }
        $dt = \DateTime::createFromFormat('Y-m-d', $date);
        return $dt ? $dt->format('d/m/Y') : '';
    }

    private function formatInterviewTime(?string $time): string
    {
        if (!$time) {
            return '';
        }
        $dt = \DateTime::createFromFormat('H:i:s', $time);
        return $dt ? $dt->format('h:i A') : '';
    }

    public function getVacancyProfile(?string $slugOrName): ?array
    {
        if (!$slugOrName) {
            return null;
        }

        $needle = $this->normalize($slugOrName);
        foreach ($this->config['vacancies'] as $vacancy) {
            $slug = $this->normalize($vacancy['slug'] ?? '');
            $name = $this->normalize($vacancy['name'] ?? '');
            if ($needle === $slug || $needle === $name) {
                return $vacancy;
            }
        }

        return null;
    }

    public function getFunnelStats(\mysqli $db, int $empresaId, string $desde, string $hasta): array
    {
        $summary = $db->query(
            "SELECT
                COUNT(*) AS leads_recibidos,
                SUM(estado IN ('contactado','calificado','entrevista_agendada','entrevista_realizada','contratado')) AS contactados,
                SUM(mensajes_recibidos > 0 OR mensajes_enviados > 0) AS interesados,
                SUM(estado IN ('calificado','entrevista_agendada','entrevista_realizada','contratado')) AS calificados,
                SUM(estado IN ('entrevista_agendada','entrevista_realizada','contratado')) AS entrevistas,
                SUM(estado IN ('entrevista_realizada','contratado')) AS asistieron,
                SUM(estado = 'contratado') AS capacitacion,
                SUM(estado = 'contratado') AS contratados,
                SUM(estado = 'contratado') AS activos
             FROM leads
             WHERE empresa_id = $empresaId
               AND DATE(primera_interaccion) BETWEEN '$desde' AND '$hasta'"
        )->fetch_assoc() ?? [];

        $stages = [];
        foreach ($this->config['funnel'] as $item) {
            $key = $item['key'];
            $stages[] = [
                'key' => $key,
                'label' => $item['label'],
                'value' => (int) ($summary[$key] ?? 0),
            ];
        }

        return [
            'periodo' => ['desde' => $desde, 'hasta' => $hasta],
            'stages' => $stages,
        ];
    }

    private function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        return str_replace([' ', '_'], '-', $value);
    }
}
