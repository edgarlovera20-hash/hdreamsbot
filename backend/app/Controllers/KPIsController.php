<?php

namespace App\Controllers;

use App\Middleware\AuthMiddleware;

class KPIsController
{
    private \mysqli $db;

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
    }

    // GET /api/kpis?empresa_id=1&desde=2026-06-01&hasta=2026-06-18&canal=whatsapp
    public function resumen(): void
    {
        $empresa_id = (int) ($_GET['empresa_id'] ?? 0);
        $desde      = $this->safeDate($_GET['desde'] ?? date('Y-m-01'));
        $hasta      = $this->safeDate($_GET['hasta'] ?? date('Y-m-d'));
        $canal      = $this->safeEnum($_GET['canal'] ?? null, ['whatsapp','messenger','instagram','facebook','gmail','outlook','teams','telegram']);

        if (!$empresa_id) { $this->json(['error' => 'empresa_id requerido'], 400); return; }
        AuthMiddleware::assertCompanyAccess($empresa_id);

        $this->json([
            'periodo'       => ['desde' => $desde, 'hasta' => $hasta, 'canal' => $canal],
            'totales'       => $this->totalesLeads($empresa_id, $desde, $hasta, $canal),
            'por_hora'      => $this->leadsPorHora($empresa_id, $desde, $hasta, $canal),
            'por_prioridad' => $this->distribucionPrioridad($empresa_id, $desde, $hasta),
            'por_canal'     => $this->leadsPorCanal($empresa_id, $desde, $hasta),
            'ab_tests'      => $this->resumenAbTests($empresa_id),
        ]);
    }

    // GET /api/kpis/cola?empresa_id=1&prioridad=urgente&limite=20
    public function colaLeads(): void
    {
        $empresa_id = (int) ($_GET['empresa_id'] ?? 0);
        $prioridad  = $this->safeEnum($_GET['prioridad'] ?? null, ['urgente','alta','media','baja']);
        $limite     = min((int) ($_GET['limite'] ?? 20), 100);

        if (!$empresa_id) { $this->json(['error' => 'empresa_id requerido'], 400); return; }
        AuthMiddleware::assertCompanyAccess($empresa_id);

        $where = "l.empresa_id = $empresa_id AND l.estado NOT IN ('rechazado','no_interesado','contratado')";
        if ($prioridad) $where .= " AND l.prioridad = '$prioridad'";

        $sql = "SELECT l.id, l.nombre, l.telefono, l.canal, l.canal_user_id,
                       l.prioridad, l.estado, l.score_ia_candidato, l.score_ia_contratacion,
                       l.mensajes_recibidos, l.primera_interaccion, l.ultima_interaccion,
                       s.nombre AS seccion,
                       lsi.razonamiento,
                       lcp.vence_en, lcp.sla_horas
                FROM leads l
                JOIN secciones s ON l.seccion_id = s.id
                LEFT JOIN lead_scoring_ia lsi ON lsi.lead_id = l.id
                LEFT JOIN lead_cola_prioridad lcp ON lcp.lead_id = l.id
                WHERE $where
                ORDER BY FIELD(l.prioridad,'urgente','alta','media','baja'),
                         lsi.score_prioridad DESC,
                         l.ultima_interaccion DESC
                LIMIT $limite";

        $result = $this->db->query($sql);
        $leads  = [];
        while ($row = $result->fetch_assoc()) $leads[] = $row;

        $this->json(['leads' => $leads, 'total' => count($leads)]);
    }

    // GET /api/kpis/horas?empresa_id=1&fecha=2026-06-18&canal=whatsapp
    public function kpiPorHora(): void
    {
        $empresa_id = (int) ($_GET['empresa_id'] ?? 0);
        $fecha      = $this->safeDate($_GET['fecha'] ?? date('Y-m-d'));
        $canal      = $this->safeEnum($_GET['canal'] ?? null, ['whatsapp','messenger','instagram','facebook','gmail','outlook','teams']);

        if (!$empresa_id) { $this->json(['error' => 'empresa_id requerido'], 400); return; }
        AuthMiddleware::assertCompanyAccess($empresa_id);

        $where = "empresa_id = $empresa_id AND fecha = '$fecha'";
        if ($canal) $where .= " AND canal = '$canal'";

        $result = $this->db->query("SELECT hora, canal, mensajes_recibidos, mensajes_enviados,
                                           leads_nuevos, leads_calificados, tiempo_respuesta_promedio, tasa_conversion
                                    FROM kpi_horario WHERE $where ORDER BY hora, canal");
        $horas  = [];
        while ($row = $result->fetch_assoc()) $horas[] = $row;

        $this->json(['fecha' => $fecha, 'horas' => $horas]);
    }

    // GET /api/kpis/ab?empresa_id=1
    public function abTests(): void
    {
        $empresa_id = (int) ($_GET['empresa_id'] ?? 0);
        if (!$empresa_id) { $this->json(['error' => 'empresa_id requerido'], 400); return; }
        AuthMiddleware::assertCompanyAccess($empresa_id);

        $sql = "SELECT t.id, t.nombre, t.tipo, t.activo, t.fecha_inicio, t.fecha_fin,
                       v.id AS variante_id, v.nombre AS variante, v.porcentaje_trafico,
                       v.impresiones, v.respuestas, v.leads_generados, v.contratados,
                       v.tasa_respuesta, v.tasa_conversion
                FROM ab_tests t
                JOIN ab_variantes v ON v.test_id = t.id
                WHERE t.empresa_id = $empresa_id
                ORDER BY t.id, v.id";

        $result = $this->db->query($sql);
        $tests  = [];
        while ($row = $result->fetch_assoc()) {
            $tid = $row['id'];
            if (!isset($tests[$tid])) {
                $tests[$tid] = [
                    'id'          => $tid,
                    'nombre'      => $row['nombre'],
                    'tipo'        => $row['tipo'],
                    'activo'      => (bool) $row['activo'],
                    'fecha_inicio' => $row['fecha_inicio'],
                    'fecha_fin'   => $row['fecha_fin'],
                    'variantes'   => [],
                ];
            }
            $tests[$tid]['variantes'][] = [
                'id'                => $row['variante_id'],
                'nombre'            => $row['variante'],
                'porcentaje_trafico' => $row['porcentaje_trafico'],
                'impresiones'       => $row['impresiones'],
                'respuestas'        => $row['respuestas'],
                'leads_generados'   => $row['leads_generados'],
                'contratados'       => $row['contratados'],
                'tasa_respuesta'    => $row['tasa_respuesta'],
                'tasa_conversion'   => $row['tasa_conversion'],
            ];
        }

        $this->json(['tests' => array_values($tests)]);
    }

    // -------------------------------------------------------
    // Internos
    // -------------------------------------------------------

    private function totalesLeads(int $eid, string $desde, string $hasta, ?string $canal): array
    {
        $where = "empresa_id=$eid AND DATE(primera_interaccion) BETWEEN '$desde' AND '$hasta'";
        if ($canal) $where .= " AND canal='$canal'";

        return $this->db->query(
            "SELECT COUNT(*) AS total,
                    SUM(estado='nuevo') AS nuevos,
                    SUM(estado='contactado') AS contactados,
                    SUM(estado='calificado') AS calificados,
                    SUM(estado='entrevista_agendada') AS entrevistas_agendadas,
                    SUM(estado='contratado') AS contratados,
                    SUM(estado IN ('rechazado','no_interesado')) AS descartados,
                    ROUND(AVG(score_ia_candidato),1) AS score_candidato_avg,
                    ROUND(AVG(score_ia_contratacion),1) AS score_contratacion_avg,
                    ROUND(AVG(tiempo_respuesta_seg),0) AS tiempo_respuesta_avg
             FROM leads WHERE $where"
        )->fetch_assoc() ?? [];
    }

    private function leadsPorHora(int $eid, string $desde, string $hasta, ?string $canal): array
    {
        $where = "empresa_id=$eid AND fecha BETWEEN '$desde' AND '$hasta'";
        if ($canal) $where .= " AND canal='$canal'";

        $result = $this->db->query(
            "SELECT hora, SUM(leads_nuevos) AS leads, SUM(mensajes_recibidos) AS mensajes
             FROM kpi_horario WHERE $where GROUP BY hora ORDER BY hora"
        );
        $rows = [];
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        return $rows;
    }

    private function distribucionPrioridad(int $eid, string $desde, string $hasta): array
    {
        $result = $this->db->query(
            "SELECT prioridad, COUNT(*) AS total
             FROM leads
             WHERE empresa_id=$eid AND DATE(primera_interaccion) BETWEEN '$desde' AND '$hasta'
             GROUP BY prioridad ORDER BY FIELD(prioridad,'urgente','alta','media','baja')"
        );
        $rows = [];
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        return $rows;
    }

    private function leadsPorCanal(int $eid, string $desde, string $hasta): array
    {
        $result = $this->db->query(
            "SELECT canal, COUNT(*) AS total,
                    ROUND(AVG(score_ia_candidato),1) AS score_avg,
                    SUM(estado='contratado') AS contratados
             FROM leads
             WHERE empresa_id=$eid AND DATE(primera_interaccion) BETWEEN '$desde' AND '$hasta'
             GROUP BY canal ORDER BY total DESC"
        );
        $rows = [];
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        return $rows;
    }

    private function resumenAbTests(int $eid): array
    {
        $result = $this->db->query(
            "SELECT t.id, t.nombre, t.tipo, t.activo,
                    COUNT(v.id) AS variantes,
                    SUM(v.impresiones) AS impresiones_total,
                    SUM(v.leads_generados) AS leads_total
             FROM ab_tests t LEFT JOIN ab_variantes v ON v.test_id=t.id
             WHERE t.empresa_id=$eid AND t.activo=1
             GROUP BY t.id ORDER BY t.created_at DESC LIMIT 5"
        );
        $rows = [];
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        return $rows;
    }

    // GET /api/kpis/horas-pico?empresa_id=1&seccion_id=1
    public function horasPico(): void
    {
        $empresa_id = (int) ($_GET['empresa_id'] ?? 0);
        $seccion_id = (int) ($_GET['seccion_id'] ?? 0);

        if (!$empresa_id) { $this->json(['error' => 'empresa_id requerido'], 400); return; }
        AuthMiddleware::assertCompanyAccess($empresa_id);

        $where = "empresa_id = $empresa_id AND fecha >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        if ($seccion_id) $where .= " AND seccion_id = $seccion_id";

        $result = $this->db->query(
            "SELECT hora,
                    SUM(leads_nuevos)       AS leads,
                    SUM(mensajes_recibidos) AS mensajes,
                    ROUND(AVG(tasa_conversion),2) AS tasa_conversion
             FROM kpi_horario WHERE $where GROUP BY hora ORDER BY hora"
        );
        $rows = [];
        while ($row = $result->fetch_assoc()) $rows[] = $row;

        $this->json($rows);
    }

    private function safeDate(string $d): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : date('Y-m-d');
    }

    private function safeEnum(?string $v, array $allowed): ?string
    {
        return ($v && in_array($v, $allowed, true)) ? $v : null;
    }

    private function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
