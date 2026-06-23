<?php

namespace App\Controllers;

class ABTestsController
{
    private \mysqli $db;
    private int $empresa_id;

    public function __construct(\mysqli $db)
    {
        $this->db         = $db;
        $this->empresa_id = (int) ($_GET['empresa_id'] ?? 1);
    }

    // GET /api/ab-tests
    public function index(): void
    {
        $eid    = $this->empresa_id;
        $result = $this->db->query(
            "SELECT t.id, t.nombre, t.tipo, t.activo, t.fecha_inicio, t.fecha_fin, t.ganador_id,
                    t.created_at,
                    v.id AS vid, v.nombre AS vnombre, v.mensaje, v.porcentaje_trafico,
                    v.impresiones, v.respuestas, v.leads_generados, v.entrevistas_agendadas,
                    v.contratados, v.tasa_respuesta, v.tasa_conversion
             FROM ab_tests t
             LEFT JOIN ab_variantes v ON v.test_id = t.id
             WHERE t.empresa_id = $eid
             ORDER BY t.id DESC, v.id ASC"
        );

        $tests = [];
        while ($row = $result ? $result->fetch_assoc() : null) {
            if (!$row) break;
            $tid = $row['id'];
            if (!isset($tests[$tid])) {
                $tests[$tid] = [
                    'id'          => $tid,
                    'nombre'      => $row['nombre'],
                    'tipo'        => $row['tipo'],
                    'activo'      => (bool) $row['activo'],
                    'fecha_inicio'=> $row['fecha_inicio'],
                    'fecha_fin'   => $row['fecha_fin'],
                    'ganador_id'  => $row['ganador_id'],
                    'created_at'  => $row['created_at'],
                    'variantes'   => [],
                ];
            }
            if ($row['vid']) {
                $tests[$tid]['variantes'][] = [
                    'id'                  => $row['vid'],
                    'nombre'              => $row['vnombre'],
                    'mensaje'             => $row['mensaje'],
                    'porcentaje_trafico'  => (int) $row['porcentaje_trafico'],
                    'impresiones'         => (int) $row['impresiones'],
                    'respuestas'          => (int) $row['respuestas'],
                    'leads_generados'     => (int) $row['leads_generados'],
                    'entrevistas_agendadas'=> (int) $row['entrevistas_agendadas'],
                    'contratados'         => (int) $row['contratados'],
                    'tasa_respuesta'      => (float) $row['tasa_respuesta'],
                    'tasa_conversion'     => (float) $row['tasa_conversion'],
                ];
            }
        }
        echo json_encode(array_values($tests));
    }

    // POST /api/ab-tests  { nombre, tipo, fecha_inicio, fecha_fin?, variantes: [{nombre, mensaje, porcentaje_trafico}] }
    public function store(): void
    {
        $b      = json_decode(file_get_contents('php://input'), true) ?? [];
        $eid    = $this->empresa_id;
        $nombre = $this->db->real_escape_string($b['nombre'] ?? '');
        $tipo   = $this->db->real_escape_string($b['tipo']   ?? 'bienvenida');
        $fi     = $this->db->real_escape_string($b['fecha_inicio'] ?? date('Y-m-d'));
        $ff     = isset($b['fecha_fin']) && $b['fecha_fin']
                    ? "'" . $this->db->real_escape_string($b['fecha_fin']) . "'"
                    : 'NULL';
        $activo = (int) ($b['activo'] ?? 1);

        if (!$nombre) { http_response_code(400); echo json_encode(['error' => 'nombre requerido']); return; }

        $this->db->query(
            "INSERT INTO ab_tests (empresa_id,seccion_id,nombre,tipo,fecha_inicio,fecha_fin,activo)
             VALUES ($eid,1,'$nombre','$tipo','$fi',$ff,$activo)"
        );
        $test_id = $this->db->insert_id;

        foreach (($b['variantes'] ?? []) as $v) {
            $vn  = $this->db->real_escape_string($v['nombre']   ?? 'Variante');
            $vm  = $this->db->real_escape_string($v['mensaje']  ?? '');
            $pct = (int) ($v['porcentaje_trafico'] ?? 50);
            $this->db->query(
                "INSERT INTO ab_variantes (test_id,nombre,mensaje,porcentaje_trafico)
                 VALUES ($test_id,'$vn','$vm',$pct)"
            );
        }

        echo json_encode(['ok' => true, 'id' => $test_id]);
    }

    // PATCH /api/ab-tests/{id}
    public function update(int $id): void
    {
        $b      = json_decode(file_get_contents('php://input'), true) ?? [];
        $eid    = $this->empresa_id;
        $nombre = $this->db->real_escape_string($b['nombre'] ?? '');
        $tipo   = $this->db->real_escape_string($b['tipo']   ?? 'bienvenida');
        $fi     = $this->db->real_escape_string($b['fecha_inicio'] ?? date('Y-m-d'));
        $ff     = isset($b['fecha_fin']) && $b['fecha_fin']
                    ? "'" . $this->db->real_escape_string($b['fecha_fin']) . "'"
                    : 'NULL';
        $activo     = (int) ($b['activo'] ?? 1);
        $ganador_id = isset($b['ganador_id']) ? (int) $b['ganador_id'] : 'NULL';

        $this->db->query(
            "UPDATE ab_tests
             SET nombre='$nombre', tipo='$tipo', fecha_inicio='$fi',
                 fecha_fin=$ff, activo=$activo, ganador_id=$ganador_id
             WHERE id=$id AND empresa_id=$eid"
        );
        echo json_encode(['ok' => true]);
    }

    // DELETE /api/ab-tests/{id}
    public function destroy(int $id): void
    {
        $eid = $this->empresa_id;
        $this->db->query("DELETE FROM ab_tests WHERE id=$id AND empresa_id=$eid");
        echo json_encode(['ok' => true]);
    }

    // PATCH /api/ab-variantes/{id}
    public function updateVariante(int $id): void
    {
        $b      = json_decode(file_get_contents('php://input'), true) ?? [];
        $nombre = $this->db->real_escape_string($b['nombre']  ?? '');
        $msg    = $this->db->real_escape_string($b['mensaje'] ?? '');
        $pct    = (int) ($b['porcentaje_trafico'] ?? 50);

        $this->db->query(
            "UPDATE ab_variantes
             SET nombre='$nombre', mensaje='$msg', porcentaje_trafico=$pct
             WHERE id=$id"
        );
        echo json_encode(['ok' => true]);
    }

    // DELETE /api/ab-variantes/{id}
    public function destroyVariante(int $id): void
    {
        $this->db->query("DELETE FROM ab_variantes WHERE id=$id");
        echo json_encode(['ok' => true]);
    }

    // POST /api/ab-tests/{id}/variante
    public function addVariante(int $id): void
    {
        $b   = json_decode(file_get_contents('php://input'), true) ?? [];
        $vn  = $this->db->real_escape_string($b['nombre']  ?? 'Nueva variante');
        $vm  = $this->db->real_escape_string($b['mensaje'] ?? '');
        $pct = (int) ($b['porcentaje_trafico'] ?? 50);

        $this->db->query(
            "INSERT INTO ab_variantes (test_id,nombre,mensaje,porcentaje_trafico)
             VALUES ($id,'$vn','$vm',$pct)"
        );
        echo json_encode(['ok' => true, 'id' => $this->db->insert_id]);
    }
}
