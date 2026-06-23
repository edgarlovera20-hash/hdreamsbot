<?php

namespace App\Controllers;

class PlantillasController
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
            "CREATE TABLE IF NOT EXISTS plantillas (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                empresa_id  INT NOT NULL,
                nombre      VARCHAR(100) NOT NULL,
                tipo        ENUM('saludo','seguimiento','oferta','rechazo','entrevista','otro') DEFAULT 'otro',
                contenido   TEXT NOT NULL,
                activo      TINYINT(1) DEFAULT 1,
                created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function index(): void
    {
        $eid  = $this->empresa_id;
        $rows = [];
        $r    = $this->db->query(
            "SELECT * FROM plantillas WHERE empresa_id=$eid ORDER BY tipo, nombre"
        );
        while ($row = $r ? $r->fetch_assoc() : null) {
            if (!$row) break;
            $row['activo'] = (bool) $row['activo'];
            $rows[] = $row;
        }
        echo json_encode($rows);
    }

    public function store(): void
    {
        $b        = json_decode(file_get_contents('php://input'), true) ?? [];
        $eid      = $this->empresa_id;
        $nombre   = $this->db->real_escape_string($b['nombre']    ?? '');
        $tipo     = $this->db->real_escape_string($b['tipo']      ?? 'otro');
        $contenido= $this->db->real_escape_string($b['contenido'] ?? '');
        $activo   = (int) ($b['activo'] ?? 1);

        if (!$nombre || !$contenido) {
            http_response_code(400);
            echo json_encode(['error' => 'nombre y contenido requeridos']);
            return;
        }

        $this->db->query(
            "INSERT INTO plantillas (empresa_id,nombre,tipo,contenido,activo)
             VALUES ($eid,'$nombre','$tipo','$contenido',$activo)"
        );
        echo json_encode(['ok' => true, 'id' => $this->db->insert_id]);
    }

    public function update(int $id): void
    {
        $b         = json_decode(file_get_contents('php://input'), true) ?? [];
        $eid       = $this->empresa_id;
        $nombre    = $this->db->real_escape_string($b['nombre']    ?? '');
        $tipo      = $this->db->real_escape_string($b['tipo']      ?? 'otro');
        $contenido = $this->db->real_escape_string($b['contenido'] ?? '');
        $activo    = (int) ($b['activo'] ?? 1);

        $this->db->query(
            "UPDATE plantillas
             SET nombre='$nombre', tipo='$tipo', contenido='$contenido', activo=$activo
             WHERE id=$id AND empresa_id=$eid"
        );
        echo json_encode(['ok' => true]);
    }

    public function destroy(int $id): void
    {
        $eid = $this->empresa_id;
        $this->db->query("DELETE FROM plantillas WHERE id=$id AND empresa_id=$eid");
        echo json_encode(['ok' => true]);
    }
}
