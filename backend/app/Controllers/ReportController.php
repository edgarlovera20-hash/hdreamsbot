<?php

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Services\RecruitmentFlowService;

class ReportController
{
    private array $config;
    private RecruitmentFlowService $flow;

    public function __construct(private \mysqli $db)
    {
        $this->config = require __DIR__ . '/../../config/app.php';
        $this->flow = new RecruitmentFlowService();
    }

    public function executiveData(): void
    {
        $empresaId = (int) ($_GET['empresa_id'] ?? 0);
        if (!$empresaId) {
            $this->json(['error' => 'empresa_id requerido'], 400);
            return;
        }

        AuthMiddleware::assertCompanyAccess($empresaId);
        AuthMiddleware::assertPermission('executive.view', $empresaId);

        $this->json($this->buildReportData($empresaId));
    }

    public function executivePdf(): void
    {
        $empresaId = (int) ($_GET['empresa_id'] ?? 0);
        if (!$empresaId) {
            $this->json(['error' => 'empresa_id requerido'], 400);
            return;
        }

        AuthMiddleware::assertCompanyAccess($empresaId);
        AuthMiddleware::assertPermission('executive.view', $empresaId);

        $reporting = $this->config['reporting'] ?? [];
        $pythonBin = (string) ($reporting['python_bin'] ?? 'python');
        $tmpDir = (string) ($reporting['tmp_dir'] ?? (__DIR__ . '/../../tmp/pdfs'));
        $outputDir = (string) ($reporting['output_dir'] ?? (__DIR__ . '/../../output/pdf'));

        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0777, true);
        }
        if (!is_dir($outputDir)) {
            @mkdir($outputDir, 0777, true);
        }

        $slug = 'empresa-' . $empresaId . '-' . date('Ymd-His');
        $inputPath = $tmpDir . DIRECTORY_SEPARATOR . $slug . '.json';
        $outputPath = $outputDir . DIRECTORY_SEPARATOR . $slug . '.pdf';
        $scriptPath = realpath(__DIR__ . '/../../scripts/generate_executive_pdf.py');

        file_put_contents($inputPath, json_encode($this->buildReportData($empresaId), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        $command = escapeshellarg($pythonBin) . ' ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($inputPath) . ' ' . escapeshellarg($outputPath);
        @shell_exec($command);

        if (!is_file($outputPath)) {
            $this->json(['error' => 'No se pudo generar el PDF'], 500);
            return;
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . basename($outputPath) . '"');
        readfile($outputPath);
    }

    private function buildReportData(int $empresaId): array
    {
        $company = $this->db->query("SELECT id, nombre FROM empresas WHERE id = $empresaId LIMIT 1")?->fetch_assoc() ?? ['id' => $empresaId, 'nombre' => 'Empresa'];

        return [
            'generated_at' => date(DATE_ATOM),
            'company' => $company,
            'summary' => [
                'active_leads' => $this->scalar("SELECT COUNT(*) AS total FROM leads WHERE empresa_id = $empresaId AND estado NOT IN ('rechazado','no_interesado','contratado')"),
                'hires_month' => $this->scalar("SELECT COUNT(*) AS total FROM leads WHERE empresa_id = $empresaId AND estado = 'contratado' AND DATE(fecha_contratado) >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"),
                'qualified_month' => $this->scalar("SELECT COUNT(*) AS total FROM leads WHERE empresa_id = $empresaId AND estado IN ('calificado','entrevista_agendada','entrevista_realizada','contratado') AND DATE(primera_interaccion) >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"),
                'interviews_week' => $this->scalar("SELECT COUNT(*) AS total FROM interviews WHERE empresa_id = $empresaId AND interview_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)"),
            ],
            'forecast' => $this->buildForecast($empresaId),
            'finance' => $this->buildFinance($empresaId),
            'sites' => $this->buildSites($empresaId),
            'channels' => $this->collectRows(
                "SELECT canal, COUNT(*) AS total
                 FROM leads
                 WHERE empresa_id = $empresaId
                 GROUP BY canal
                 ORDER BY total DESC"
            ),
            'recruiters' => $this->collectRows(
                "SELECT r.nombre,
                        COUNT(DISTINCT CASE WHEN l.estado NOT IN ('rechazado','no_interesado','contratado') THEN l.id END) AS active_leads,
                        COUNT(DISTINCT CASE WHEN l.estado = 'contratado' AND DATE(l.fecha_contratado) >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN l.id END) AS hires_month
                 FROM recruiters r
                 LEFT JOIN leads l ON l.assigned_recruiter_id = r.id
                 WHERE r.empresa_id = $empresaId
                 GROUP BY r.id, r.nombre
                 ORDER BY hires_month DESC, active_leads DESC, r.nombre ASC"
            ),
        ];
    }

    private function buildForecast(int $empresaId): array
    {
        $totals = $this->db->query(
            "SELECT
                COUNT(*) AS leads_total,
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
        $projectedHires = (int) round($activeQualified * ($conversion / 100));

        return [
            'conversion_qualified_to_hire_pct' => $conversion,
            'active_qualified_pipeline' => $activeQualified,
            'projected_hires_next_30d' => $projectedHires,
            'assumption' => 'Proyección basada en conversión histórica de calificados a contratados de los últimos 60 días.',
        ];
    }

    private function buildFinance(int $empresaId): array
    {
        $vacancies = $this->flow->getOverview()['vacancies'] ?? [];
        $items = [];

        foreach ($vacancies as $vacancy) {
            $slug = $this->db->real_escape_string((string) ($vacancy['slug'] ?? ''));
            $name = $vacancy['name'] ?? $slug;
            $activeLeads = $this->scalar(
                "SELECT COUNT(*) AS total
                 FROM leads
                 WHERE empresa_id = $empresaId
                   AND estado NOT IN ('rechazado','no_interesado','contratado')
                   AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.vacante')) IN ('$name', '$slug')"
            );
            $hiresMonth = $this->scalar(
                "SELECT COUNT(*) AS total
                 FROM leads
                 WHERE empresa_id = $empresaId
                   AND estado = 'contratado'
                   AND DATE(fecha_contratado) >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                   AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.vacante')) IN ('$name', '$slug')"
            );
            $salary = (float) ($vacancy['weekly_salary'] ?? 0);
            $projectedWeeklyPayroll = $salary * max(1, $hiresMonth);

            $items[] = [
                'vacancy' => $name,
                'weekly_salary' => $salary,
                'active_leads' => $activeLeads,
                'hires_month' => $hiresMonth,
                'projected_weekly_payroll' => $projectedWeeklyPayroll,
            ];
        }

        return $items;
    }

    private function buildSites(int $empresaId): array
    {
        return $this->collectRows(
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
    }

    private function collectRows(string $sql): array
    {
        $result = $this->db->query($sql);
        $rows = [];
        while ($row = $result?->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
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
