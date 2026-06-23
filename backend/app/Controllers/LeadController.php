<?php

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Services\AuditService;
use App\Services\LeadScorerIA;

class LeadController
{
    private \mysqli $db;
    private AuditService $audit;

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
        $this->audit = new AuditService($db);
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
        AuthMiddleware::assertCompanyAccess($empresa_id);

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
        AuthMiddleware::assertCompanyAccess($empresa_id);

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
                    lcp.estado      AS cola_estado,
                    r.id            AS recruiter_id,
                    r.nombre        AS recruiter_nombre,
                    r.email         AS recruiter_email,
                    r.telefono      AS recruiter_telefono
             FROM leads l
             JOIN secciones s ON l.seccion_id = s.id
             LEFT JOIN lead_scoring_ia lsi    ON lsi.lead_id  = l.id
             LEFT JOIN lead_cola_prioridad lcp ON lcp.lead_id = l.id
             LEFT JOIN recruiters r ON r.id = l.assigned_recruiter_id
             WHERE l.id = $id"
        )->fetch_assoc();

        if (!$result) { $this->json(['error' => 'Lead no encontrado'], 404); return; }
        AuthMiddleware::assertCompanyAccess((int) $result['empresa_id']);

        $result['factores_positivos'] = json_decode($result['factores_positivos'] ?? '[]');
        $result['factores_negativos'] = json_decode($result['factores_negativos'] ?? '[]');
        $result['metadata']           = json_decode($result['metadata'] ?? '{}');
        $leadId = (int) $result['id'];

        $this->json([
            'lead' => $result,
            'notes' => $this->obtenerNotas($leadId),
            'events' => $this->obtenerEventos($leadId),
            'interview' => $this->obtenerEntrevistaActiva($leadId),
            'voice_notes' => $this->obtenerNotasDeVoz($leadId),
        ]);
    }

    // POST /api/leads/{id}/score
    public function recalcularScore(int $id): void
    {
        $lead = $this->db->query("SELECT empresa_id, seccion_id FROM leads WHERE id=$id")->fetch_assoc();

        if (!$lead) { $this->json(['error' => 'Lead no encontrado'], 404); return; }
        AuthMiddleware::assertCompanyAccess((int) $lead['empresa_id']);

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

        $lead = $this->db->query("SELECT empresa_id FROM leads WHERE id = $id")->fetch_assoc();
        if (!$lead) { $this->json(['error' => 'Lead no encontrado'], 404); return; }
        AuthMiddleware::assertCompanyAccess((int) $lead['empresa_id']);

        $extra = $estado === 'contratado' ? ', fecha_contratado = NOW()' : '';
        $this->db->query("UPDATE leads SET estado='$estado'$extra WHERE id=$id");

        if ($estado === 'contratado') {
            $this->db->query("UPDATE lead_cola_prioridad SET estado='cerrado' WHERE lead_id=$id");
        }

        $this->registrarEvento($id, 'stage_changed', 'Estado actualizado', [
            'estado' => $estado,
        ], 'recruiter');

        $this->json(['ok' => true, 'lead_id' => $id, 'estado' => $estado]);
    }

    // POST /api/leads/{id}/notes
    public function crearNota(int $id): void
    {
        $body = json_decode(file_get_contents('php://input'), true);
        $note = trim((string) ($body['note'] ?? ''));
        $recruiterId = (int) ($body['recruiter_id'] ?? 1);

        if ($note === '') { $this->json(['error' => 'note requerida'], 400); return; }

        $exists = $this->db->query("SELECT id, empresa_id FROM leads WHERE id = $id")->fetch_assoc();
        if (!$exists) { $this->json(['error' => 'Lead no encontrado'], 404); return; }
        AuthMiddleware::assertCompanyAccess((int) $exists['empresa_id']);
        AuthMiddleware::assertPermission('leads.note', (int) $exists['empresa_id']);

        $stmt = $this->db->prepare(
            "INSERT INTO lead_notes (lead_id, recruiter_id, note, is_pinned) VALUES (?, ?, ?, 0)"
        );
        $stmt->bind_param('iis', $id, $recruiterId, $note);
        $stmt->execute();
        $noteId = $stmt->insert_id;
        $stmt->close();

        $this->registrarEvento($id, 'note_added', 'Nota agregada', [
            'note_id' => $noteId,
            'excerpt' => mb_substr($note, 0, 120),
        ], 'recruiter', $recruiterId);

        $user = AuthMiddleware::currentUser();
        $this->audit->log((int) ($user['id'] ?? 0), (int) $exists['empresa_id'], 'lead.note_create', 'lead', $id, [
            'note_id' => $noteId,
        ]);

        $notes = $this->obtenerNotas($id, 1);
        $this->json(['ok' => true, 'note' => $notes[0] ?? null], 201);
    }

    // GET /api/leads/{id}/events
    public function eventos(int $id): void
    {
        $lead = $this->db->query("SELECT empresa_id FROM leads WHERE id = $id")->fetch_assoc();
        if (!$lead) { $this->json(['error' => 'Lead no encontrado'], 404); return; }
        AuthMiddleware::assertCompanyAccess((int) $lead['empresa_id']);
        $this->json(['events' => $this->obtenerEventos($id, 100)]);
    }

    // -------------------------------------------------------
    private function obtenerNotas(int $leadId, int $limit = 20): array
    {
        $limit = max(1, min($limit, 100));
        $result = $this->db->query(
            "SELECT n.id, n.note, n.is_pinned, n.created_at, n.updated_at,
                    r.id AS recruiter_id, r.nombre AS recruiter_nombre
             FROM lead_notes n
             JOIN recruiters r ON r.id = n.recruiter_id
             WHERE n.lead_id = $leadId
             ORDER BY n.is_pinned DESC, n.created_at DESC
             LIMIT $limit"
        );

        $notes = [];
        while ($row = $result?->fetch_assoc()) {
            $notes[] = $row;
        }

        return $notes;
    }

    private function obtenerEventos(int $leadId, int $limit = 30): array
    {
        $limit = max(1, min($limit, 100));
        $result = $this->db->query(
            "SELECT id, event_type, event_label, payload, actor_type, actor_id, created_at
             FROM lead_events
             WHERE lead_id = $leadId
             ORDER BY created_at DESC
             LIMIT $limit"
        );

        $events = [];
        while ($row = $result?->fetch_assoc()) {
            $row['payload'] = json_decode($row['payload'] ?? '{}', true);
            $events[] = $row;
        }

        return $events;
    }

    private function obtenerEntrevistaActiva(int $leadId): ?array
    {
        $result = $this->db->query(
            "SELECT i.*,
                    r.nombre AS recruiter_nombre
             FROM interviews i
             LEFT JOIN recruiters r ON r.id = i.recruiter_id
             WHERE i.lead_id = $leadId
             ORDER BY i.interview_date DESC, i.interview_time DESC, i.created_at DESC
             LIMIT 1"
        )?->fetch_assoc();

        return $result ?: null;
    }

    private function obtenerNotasDeVoz(int $leadId, int $limit = 10): array
    {
        $limit = max(1, min($limit, 50));
        $result = $this->db->query(
            "SELECT vn.id, vn.mime_type, vn.transcript, vn.status, vn.created_at, vn.transcribed_at,
                    r.nombre AS recruiter_nombre
             FROM voice_notes vn
             LEFT JOIN recruiters r ON r.id = vn.recruiter_id
             WHERE vn.lead_id = $leadId
             ORDER BY vn.created_at DESC
             LIMIT $limit"
        );

        $items = [];
        while ($row = $result?->fetch_assoc()) {
            $items[] = $row;
        }

        return $items;
    }

    private function registrarEvento(
        int $leadId,
        string $type,
        string $label,
        array $payload = [],
        string $actorType = 'system',
        ?int $actorId = null
    ): void {
        $lead = $this->db->query("SELECT empresa_id FROM leads WHERE id = $leadId")->fetch_assoc();
        if (!$lead) {
            return;
        }

        $empresaId = (int) $lead['empresa_id'];
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $this->db->prepare(
            "INSERT INTO lead_events (lead_id, empresa_id, event_type, event_label, payload, actor_type, actor_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('iissssi', $leadId, $empresaId, $type, $label, $payloadJson, $actorType, $actorId);
        $stmt->execute();
        $stmt->close();
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
