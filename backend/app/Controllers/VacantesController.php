<?php

namespace App\Controllers;

class VacantesController
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
            "CREATE TABLE IF NOT EXISTS vacantes (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                empresa_id  INT NOT NULL,
                titulo      VARCHAR(150) NOT NULL,
                descripcion TEXT,
                ubicacion   VARCHAR(100),
                modalidad   ENUM('presencial','remoto','hibrido') DEFAULT 'presencial',
                salario_min DECIMAL(10,2),
                salario_max DECIMAL(10,2),
                requisitos  TEXT,
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
            "SELECT * FROM vacantes WHERE empresa_id=$eid ORDER BY activo DESC, created_at DESC"
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
        $b      = json_decode(file_get_contents('php://input'), true) ?? [];
        $eid    = $this->empresa_id;
        $titulo = $this->db->real_escape_string($b['titulo'] ?? '');

        if (!$titulo) { http_response_code(400); echo json_encode(['error' => 'titulo requerido']); return; }

        $desc   = $this->db->real_escape_string($b['descripcion']  ?? '');
        $ubic   = $this->db->real_escape_string($b['ubicacion']    ?? '');
        $modal  = $this->db->real_escape_string($b['modalidad']    ?? 'presencial');
        $smin   = (float) ($b['salario_min'] ?? 0);
        $smax   = (float) ($b['salario_max'] ?? 0);
        $req    = $this->db->real_escape_string($b['requisitos']   ?? '');
        $activo = (int)   ($b['activo'] ?? 1);

        $this->db->query(
            "INSERT INTO vacantes
               (empresa_id,titulo,descripcion,ubicacion,modalidad,salario_min,salario_max,requisitos,activo)
             VALUES ($eid,'$titulo','$desc','$ubic','$modal',$smin,$smax,'$req',$activo)"
        );
        echo json_encode(['ok' => true, 'id' => $this->db->insert_id]);
    }

    public function update(int $id): void
    {
        $b      = json_decode(file_get_contents('php://input'), true) ?? [];
        $eid    = $this->empresa_id;
        $titulo = $this->db->real_escape_string($b['titulo']      ?? '');
        $desc   = $this->db->real_escape_string($b['descripcion'] ?? '');
        $ubic   = $this->db->real_escape_string($b['ubicacion']   ?? '');
        $modal  = $this->db->real_escape_string($b['modalidad']   ?? 'presencial');
        $smin   = (float) ($b['salario_min'] ?? 0);
        $smax   = (float) ($b['salario_max'] ?? 0);
        $req    = $this->db->real_escape_string($b['requisitos']  ?? '');
        $activo = (int)   ($b['activo'] ?? 1);

        $this->db->query(
            "UPDATE vacantes
             SET titulo='$titulo', descripcion='$desc', ubicacion='$ubic',
                 modalidad='$modal', salario_min=$smin, salario_max=$smax,
                 requisitos='$req', activo=$activo
             WHERE id=$id AND empresa_id=$eid"
        );
        echo json_encode(['ok' => true]);
    }

    public function destroy(int $id): void
    {
        $eid = $this->empresa_id;
        $this->db->query("DELETE FROM vacantes WHERE id=$id AND empresa_id=$eid");
        echo json_encode(['ok' => true]);
    }
}
