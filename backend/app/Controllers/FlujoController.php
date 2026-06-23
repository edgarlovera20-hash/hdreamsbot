<?php

namespace App\Controllers;

class FlujoController
{
    private \mysqli $db;
    private int $empresa_id;

    public function __construct(\mysqli $db)
    {
        $this->db         = $db;
        $this->empresa_id = (int) ($_GET['empresa_id'] ?? 1);
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS flujos (
                id              INT AUTO_INCREMENT PRIMARY KEY,
                empresa_id      INT NOT NULL,
                nombre          VARCHAR(100) NOT NULL,
                trigger_keyword VARCHAR(255),
                pasos           JSON,
                activo          TINYINT(1) DEFAULT 1,
                created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function index(): void
    {
        $eid  = $this->empresa_id;
        $rows = [];
        $r    = $this->db->query(
            "SELECT * FROM flujos WHERE empresa_id=$eid ORDER BY activo DESC, nombre"
        );
        while ($row = $r ? $r->fetch_assoc() : null) {
            if (!$row) break;
            $row['pasos']  = json_decode($row['pasos']  ?? '[]', true) ?: [];
            $row['activo'] = (bool) $row['activo'];
            $rows[] = $row;
        }
        echo json_encode($rows);
    }

    public function store(): void
    {
        $b       = json_decode(file_get_contents('php://input'), true) ?? [];
        $eid     = $this->empresa_id;
        $nombre  = $this->db->real_escape_string($b['nombre']          ?? '');
        $trigger = $this->db->real_escape_string($b['trigger_keyword'] ?? '');
        $pasos   = $this->db->real_escape_string(json_encode($b['pasos'] ?? []));
        $activo  = (int) ($b['activo'] ?? 1);

        if (!$nombre) { http_response_code(400); echo json_encode(['error' => 'nombre requerido']); return; }

        $this->db->query(
            "INSERT INTO flujos (empresa_id,nombre,trigger_keyword,pasos,activo)
             VALUES ($eid,'$nombre','$trigger','$pasos',$activo)"
        );
        echo json_encode(['ok' => true, 'id' => $this->db->insert_id]);
    }

    public function update(int $id): void
    {
        $b       = json_decode(file_get_contents('php://input'), true) ?? [];
        $eid     = $this->empresa_id;
        $nombre  = $this->db->real_escape_string($b['nombre']          ?? '');
        $trigger = $this->db->real_escape_string($b['trigger_keyword'] ?? '');
        $pasos   = $this->db->real_escape_string(json_encode($b['pasos'] ?? []));
        $activo  = (int) ($b['activo'] ?? 1);

        $this->db->query(
            "UPDATE flujos
             SET nombre='$nombre', trigger_keyword='$trigger', pasos='$pasos', activo=$activo
             WHERE id=$id AND empresa_id=$eid"
        );
        echo json_encode(['ok' => true]);
    }

    public function destroy(int $id): void
    {
        $eid = $this->empresa_id;
        $this->db->query("DELETE FROM flujos WHERE id=$id AND empresa_id=$eid");
        echo json_encode(['ok' => true]);
    }
}
