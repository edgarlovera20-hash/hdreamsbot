<?php

namespace App\Controllers;

use App\Services\LeadScorerIA;

class LeadController
{
    private \mysqli $db;

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
    }

    // GET /api/leads?empresa_id=1&seccion_id=1&estado=nuevo&canal=whatsapp&limite=100
    public function index(): void
    {
        $empresa_id = (int) ($_GET['empresa_id'] ?? 0);
        $seccion_id = (int) ($_GET['seccion_id'] ?? 0);
        $estado     = $this->safeEnum($_GET['estado'] ?? null, ['nuevo','contactado','calificado','entrevista_agendada','entrevista_realizada','contratado','rechazado','no_interesado']);
        $canal      = $this->safeEnum($_GET['canal']  ?? null, ['whatsapp','messenger','instagram','gmail','outlook','teams','facebook','telegram']);
        $limite     = min((int) ($_GET['limite'] ?? 100), 500);

        if (!$empresa_id) { $this->json(['error' => 'empresa_id requerido'], 400); return; }

        $where = "l.empresa_id = $empresa_id";
        if ($seccion_id) $where .= " AND l.seccion_id = $seccion_id";
        if ($estado)     $where .= " AND l.estado = '$estado'";
        if ($canal)      $where .= " AND l.canal = '$canal'";

        $result = $this->db->query(
            "SELECT l.*,
                    s.nombre           AS seccion,
                    lsi.score_prioridad,
                    lsi.razonamiento,
                    lcp.vence_en,
                    lcp.sla_horas,
                    lcp.estado         AS cola_estado
             FROM leads l
             JOIN secciones s ON l.seccion_id = s.id
             LEFT JOIN lead_scoring_ia lsi    ON lsi.lead_id  = l.id
             LEFT JOIN lead_cola_prioridad lcp ON lcp.lead_id = l.id
             WHERE $where
             ORDER BY l.ultima_interaccion DESC
             LIMIT $limite"
        );

        $leads = [];
        while ($row = $result->fetch_assoc()) $leads[] = $row;

        $this->json(['leads' => $leads, 'total' => count($leads)]);
    }

    // GET /api/leads/cola?empresa_id=1&estado_cola=pendiente
    public function colaPrioridad(): void
    {
        $empresa_id  = (int) ($_GET['empresa_id'] ?? 0);
        $estado_cola = $this->safeEnum($_GET['estado_cola'] ?? 'pendiente', ['pendiente','en_proceso','contactado','cerrado']);
        $limite      = min((int) ($_GET['limite'] ?? 20), 100);

        if (!$empresa_id) { $this->json(['error' => 'empresa_id requerido'], 400); return; }

        $result = $this->db->query(
            "SELECT l.id, l.nombre, l.telefono, l.email, l.edad, l.canal, l.canal_user_id,
                    l.estado, l.primera_interaccion, l.ultima_interaccion,
                    s.nombre           AS seccion,
                    lsi.score_candidato,
                    lsi.score_contratacion,
                    lsi.score_prioridad,
                    lsi.factores_positivos,
                    lsi.factores_negativos,
                    lsi.razonamiento,
                    c.prioridad,
                    c.score_prioridad  AS cola_score,
                    c.vence_en,
                    c.sla_horas,
                    c.estado           AS cola_estado,
                    c.asignado_a
             FROM leads l
             JOIN secciones s ON l.seccion_id = s.id
             JOIN lead_cola_prioridad c ON c.lead_id = l.id
             LEFT JOIN lead_scoring_ia lsi ON lsi.lead_id = l.id
             WHERE l.empresa_id = $empresa_id
               AND c.estado = '$estado_cola'
             ORDER BY FIELD(c.prioridad,'urgente','alta','media','baja'),
                      c.score_prioridad DESC,
                      c.vence_en ASC
             LIMIT $limite"
        );

        $leads = [];
        while ($row = $result->fetch_assoc()) {
            $row['factores_positivos'] = json_decode($row['factores_positivos'] ?? '[]');
            $row['factores_negativos'] = json_decode($row['factores_negativos'] ?? '[]');
            $leads[] = $row;
        }

        $this->json(['leads' => $leads, 'total' => count($leads)]);
    }

    // GET /api/leads/{id}
    public function show(int $id): void
    {
        $result = $this->db->query(
            "SELECT l.*,
                    s.nombre       AS seccion,
                    s.system_prompt,
                    lsi.score_candidato,
                    lsi.score_contratacion,
                    lsi.score_prioridad,
                    lsi.factores_positivos,
                    lsi.factores_negativos,
                    lsi.razonamiento,
                    lsi.calculado_en AS scoring_en,
                    lcp.prioridad   AS cola_prioridad,
                    lcp.vence_en,
                    lcp.sla_horas,
                    lcp.estado      AS cola_estado
             FROM leads l
             JOIN secciones s ON l.seccion_id = s.id
             LEFT JOIN lead_scoring_ia lsi    ON lsi.lead_id  = l.id
             LEFT JOIN lead_cola_prioridad lcp ON lcp.lead_id = l.id
             WHERE l.id = $id"
        )->fetch_assoc();

        if (!$result) { $this->json(['error' => 'Lead no encontrado'], 404); return; }

        $result['factores_positivos'] = json_decode($result['factores_positivos'] ?? '[]');
        $result['factores_negativos'] = json_decode($result['factores_negativos'] ?? '[]');
        $result['metadata']           = json_decode($result['metadata'] ?? '{}');

        $this->json($result);
    }

    // POST /api/leads/{id}/score
    public function recalcularScore(int $id): void
    {
        $lead = $this->db->query("SELECT empresa_id, seccion_id FROM leads WHERE id=$id")->fetch_assoc();

        if (!$lead) { $this->json(['error' => 'Lead no encontrado'], 404); return; }

        $scorer = new LeadScorerIA((int) $lead['empresa_id'], (int) $lead['seccion_id'], $this->db);
        $score  = $scorer->calcularScore($id);

        $this->json(['lead_id' => $id, 'score' => $score]);
    }

    // PATCH /api/leads/{id}/estado
    public function actualizarEstado(int $id): void
    {
        $body   = json_decode(file_get_contents('php://input'), true);
        $estado = $this->safeEnum($body['estado'] ?? '', ['nuevo','contactado','calificado','entrevista_agendada','entrevista_realizada','contratado','rechazado','no_interesado']);

        if (!$estado) { $this->json(['error' => 'estado inválido'], 400); return; }

        $extra = $estado === 'contratado' ? ', fecha_contratado = NOW()' : '';
        $this->db->query("UPDATE leads SET estado='$estado'$extra WHERE id=$id");

        if ($estado === 'contratado') {
            $this->db->query("UPDATE lead_cola_prioridad SET estado='cerrado' WHERE lead_id=$id");
        }

        $this->json(['ok' => true, 'lead_id' => $id, 'estado' => $estado]);
    }

    // -------------------------------------------------------
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
