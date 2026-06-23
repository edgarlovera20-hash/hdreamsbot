<?php

namespace App\Controllers;

class AgentesController
{
    private \mysqli $mysqli;
    private int $empresa_id;

    public function __construct(\mysqli $mysqli)
    {
        $this->mysqli     = $mysqli;
        $this->empresa_id = (int) ($_GET['empresa_id'] ?? 1);
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        $this->mysqli->query(
            "CREATE TABLE IF NOT EXISTS agentes_ia (
                id             INT AUTO_INCREMENT PRIMARY KEY,
                empresa_id     INT NOT NULL,
                seccion_id     INT NOT NULL DEFAULT 1,
                nombre         VARCHAR(64) NOT NULL,
                tipo           ENUM('scorer','responder','classifier','extractor') NOT NULL DEFAULT 'responder',
                modelo         VARCHAR(64) NOT NULL DEFAULT 'gpt-4o',
                prompt_sistema TEXT,
                activo         TINYINT(1) DEFAULT 1,
                config_json    TEXT,
                created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function index(): void
    {
        $eid  = $this->empresa_id;
        $rows = [];
        $r = $this->mysqli->query(
            "SELECT * FROM agentes_ia WHERE empresa_id=$eid ORDER BY tipo, nombre"
        );
        while ($row = $r ? $r->fetch_assoc() : null) {
            if (!$row) break;
            $row['config_json'] = json_decode($row['config_json'] ?? '{}', true) ?: [];
            $row['activo']      = (bool) $row['activo'];
            $rows[] = $row;
        }
        echo json_encode($rows);
    }

    public function store(): void
    {
        $body   = json_decode(file_get_contents('php://input'), true) ?? [];
        $eid    = $this->empresa_id;
        $sid    = (int) ($body['seccion_id'] ?? 1);
        $nombre = $this->mysqli->real_escape_string($body['nombre'] ?? '');
        $tipo   = $this->mysqli->real_escape_string($body['tipo']   ?? 'responder');
        $modelo = $this->mysqli->real_escape_string($body['modelo'] ?? 'gpt-4o');
        $prompt = $this->mysqli->real_escape_string($body['prompt_sistema'] ?? '');
        $activo = (int) ($body['activo'] ?? 1);
        $cfg    = $this->mysqli->real_escape_string(json_encode($body['config_json'] ?? new \stdClass()));

        if (!$nombre) {
            http_response_code(400);
            echo json_encode(['error' => 'nombre requerido']);
            return;
        }

        $this->mysqli->query(
            "INSERT INTO agentes_ia
               (empresa_id,seccion_id,nombre,tipo,modelo,prompt_sistema,activo,config_json)
             VALUES ($eid,$sid,'$nombre','$tipo','$modelo','$prompt',$activo,'$cfg')"
        );
        echo json_encode(['ok' => true, 'id' => $this->mysqli->insert_id]);
    }

    public function update(int $id): void
    {
        $body   = json_decode(file_get_contents('php://input'), true) ?? [];
        $eid    = $this->empresa_id;
        $nombre = $this->mysqli->real_escape_string($body['nombre'] ?? '');
        $tipo   = $this->mysqli->real_escape_string($body['tipo']   ?? 'responder');
        $modelo = $this->mysqli->real_escape_string($body['modelo'] ?? 'gpt-4o');
        $prompt = $this->mysqli->real_escape_string($body['prompt_sistema'] ?? '');
        $activo = (int) ($body['activo'] ?? 1);
        $cfg    = $this->mysqli->real_escape_string(json_encode($body['config_json'] ?? new \stdClass()));

        $this->mysqli->query(
            "UPDATE agentes_ia
             SET nombre='$nombre', tipo='$tipo', modelo='$modelo',
                 prompt_sistema='$prompt', activo=$activo, config_json='$cfg'
             WHERE id=$id AND empresa_id=$eid"
        );
        echo json_encode(['ok' => true]);
    }

    public function destroy(int $id): void
    {
        $eid = $this->empresa_id;
        $this->mysqli->query("DELETE FROM agentes_ia WHERE id=$id AND empresa_id=$eid");
        echo json_encode(['ok' => true]);
    }
}
