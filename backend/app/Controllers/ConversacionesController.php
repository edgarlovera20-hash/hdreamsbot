<?php

namespace App\Controllers;

class ConversacionesController
{
    private \mysqli $db;

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
    }

    // GET /api/conversaciones
    public function index(): void
    {
        $empresa_id = (int) ($_GET['empresa_id'] ?? 0);
        if (!$empresa_id) { $this->json(['error' => 'empresa_id requerido'], 400); return; }

        $result = $this->db->query(
            "SELECT l.id, l.nombre, l.telefono, l.canal_user_id, l.estado,
                    l.ultima_interaccion, l.mensajes_recibidos,
                    (SELECT il.pregunta FROM ia_logs il
                     WHERE il.wa_id = l.canal_user_id AND il.empresa_id = l.empresa_id
                     ORDER BY il.created_at DESC LIMIT 1) AS ultimo_mensaje,
                    (SELECT il.created_at FROM ia_logs il
                     WHERE il.wa_id = l.canal_user_id AND il.empresa_id = l.empresa_id
                     ORDER BY il.created_at DESC LIMIT 1) AS ultimo_mensaje_at
             FROM leads l
             WHERE l.empresa_id = $empresa_id AND l.canal = 'whatsapp'
             ORDER BY l.ultima_interaccion DESC
             LIMIT 100"
        );

        $rows = [];
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        echo json_encode($rows);
    }

    // GET /api/conversaciones/mensajes?wa_id=521234567890
    public function mensajes(): void
    {
        $empresa_id = (int) ($_GET['empresa_id'] ?? 0);
        $wa_id      = $this->db->real_escape_string($_GET['wa_id'] ?? '');

        if (!$empresa_id || !$wa_id) { $this->json(['error' => 'Parámetros requeridos'], 400); return; }

        $result = $this->db->query(
            "SELECT il.id, il.pregunta, il.respuesta, il.created_at,
                    l.nombre, l.telefono
             FROM ia_logs il
             LEFT JOIN leads l ON l.canal_user_id = il.wa_id AND l.empresa_id = il.empresa_id
             WHERE il.wa_id = '$wa_id' AND il.empresa_id = $empresa_id
             ORDER BY il.created_at ASC
             LIMIT 200"
        );

        $rows = [];
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        echo json_encode($rows);
    }

    private function json(mixed $data, int $code = 200): void
    {
        http_response_code($code);
        echo json_encode($data);
    }
}
