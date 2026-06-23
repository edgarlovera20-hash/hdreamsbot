<?php

namespace App\Controllers;

class BotConfigController
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
            "CREATE TABLE IF NOT EXISTS bot_config (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                empresa_id INT NOT NULL,
                seccion_id INT NOT NULL DEFAULT 1,
                clave      VARCHAR(64) NOT NULL,
                valor      TEXT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_bot_cfg (empresa_id, seccion_id, clave)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function index(): void
    {
        $eid = $this->empresa_id;
        $sid = (int) ($_GET['seccion_id'] ?? 1);

        $config = [];
        $r = $this->mysqli->query(
            "SELECT clave, valor FROM bot_config WHERE empresa_id=$eid AND seccion_id=$sid"
        );
        while ($row = $r ? $r->fetch_assoc() : null) {
            if (!$row) break;
            $config[$row['clave']] = $row['valor'];
        }

        // Never return the actual API key — just indicate if it's set
        $keyIsSet = !empty($config['llm_api_key']);
        unset($config['llm_api_key']);

        echo json_encode(array_merge([
            'nombre_bot'          => 'Lic. Gissell',
            'empresa_nombre'      => 'Heavenly Dreams',
            'saludo'              => 'Hola, soy Lic. Gissell de RH en Heavenly Dreams. ¿Qué edad tienes y en qué ciudad estás?',
            'fuera_horario'       => 'Gracias por escribir. Nuestro horario es de 9am a 6pm. Te contactaremos pronto.',
            'horario_inicio'      => '09:00',
            'horario_fin'         => '18:00',
            'horario_dias'        => 'lunes-viernes',
            'escalacion_score'    => '80',
            'telefono_reclutador' => $_ENV['RECRUITER_PHONE'] ?? '',
            'idioma'              => 'es',
            'auto_responder'      => '1',
            'max_mensajes_auto'   => '10',
            'llm_base_url'        => $_ENV['LLM_BASE_URL'] ?? 'http://localhost:11434/v1',
            'llm_model_default'   => $_ENV['LLM_MODEL']    ?? 'llama3.2:3b',
            'llm_api_key_set'     => false,
        ], $config, ['llm_api_key_set' => $keyIsSet]));
    }

    public function store(): void
    {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $eid  = $this->empresa_id;
        $sid  = (int) ($body['seccion_id'] ?? 1);
        unset($body['seccion_id']);

        foreach ($body as $clave => $valor) {
            // Empty API key means "keep the existing one"
            if ($clave === 'llm_api_key' && $valor === '') continue;

            $c = $this->mysqli->real_escape_string((string) $clave);
            $v = $this->mysqli->real_escape_string((string) $valor);
            $this->mysqli->query(
                "INSERT INTO bot_config (empresa_id,seccion_id,clave,valor)
                 VALUES ($eid,$sid,'$c','$v')
                 ON DUPLICATE KEY UPDATE valor='$v'"
            );
        }
        echo json_encode(['ok' => true]);
    }

    public function testLlm(): void
    {
        $body     = json_decode(file_get_contents('php://input'), true) ?? [];
        $base_url = rtrim($body['llm_base_url'] ?? '', '/');
        $api_key  = $body['llm_api_key'] ?? '';
        $modelo   = $body['modelo']      ?? ($body['llm_model_default'] ?? 'llama3.2:3b');

        if (!$base_url) {
            echo json_encode(['ok' => false, 'message' => 'URL requerida']); return;
        }

        $headers = ['Content-Type: application/json'];
        if ($api_key) $headers[] = "Authorization: Bearer $api_key";

        $ch = curl_init("$base_url/chat/completions");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => json_encode([
                'model'      => $modelo,
                'messages'   => [['role' => 'user', 'content' => 'Hi']],
                'max_tokens' => 5,
            ]),
        ]);

        $raw    = curl_exec($ch);
        $err    = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err || !$raw) {
            echo json_encode(['ok' => false, 'message' => "Sin conexión: $err"]); return;
        }

        $r = json_decode($raw, true);
        if ($status === 200 && isset($r['choices'][0]['message']['content'])) {
            echo json_encode(['ok' => true, 'message' => 'Conectado — modelo: ' . ($r['model'] ?? $modelo)]);
        } else {
            $msg = $r['error']['message'] ?? "HTTP $status";
            echo json_encode(['ok' => false, 'message' => $msg]);
        }
    }
}
