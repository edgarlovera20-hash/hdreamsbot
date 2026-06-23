<?php

namespace App\Controllers;

class CanalesController
{
    private \mysqli $mysqli;
    private int $empresa_id;

    public function __construct(\mysqli $mysqli)
    {
        $this->mysqli     = $mysqli;
        $this->empresa_id = (int) ($_GET['empresa_id'] ?? 1);
    }

    public function index(): void
    {
        $eid  = $this->empresa_id;
        $rows = [];
        $r = $this->mysqli->query(
            "SELECT id, empresa_id, seccion_id, canal, page_id, activo,
                    LEFT(access_token,16) AS token_preview, config, created_at
             FROM canales WHERE empresa_id=$eid ORDER BY canal"
        );
        while ($row = $r ? $r->fetch_assoc() : null) {
            if (!$row) break;
            $row['config'] = json_decode($row['config'] ?? '{}', true) ?: [];
            $rows[] = $row;
        }
        echo json_encode($rows);
    }

    public function store(): void
    {
        $body   = json_decode(file_get_contents('php://input'), true) ?? [];
        $eid    = $this->empresa_id;
        $sid    = (int) ($body['seccion_id'] ?? 1);
        $canal  = $this->mysqli->real_escape_string($body['canal'] ?? '');
        $pageid = $this->mysqli->real_escape_string($body['page_id'] ?? '');
        $token  = $this->mysqli->real_escape_string($body['access_token'] ?? '');
        $cfg    = $this->mysqli->real_escape_string(json_encode($body['config'] ?? new \stdClass()));
        $activo = (int) ($body['activo'] ?? 1);

        if (!$canal) {
            http_response_code(400);
            echo json_encode(['error' => 'canal requerido']);
            return;
        }

        $this->mysqli->query(
            "INSERT INTO canales (empresa_id,seccion_id,canal,page_id,access_token,config,activo)
             VALUES ($eid,$sid,'$canal','$pageid','$token','$cfg',$activo)
             ON DUPLICATE KEY UPDATE page_id='$pageid',access_token='$token',config='$cfg',activo=$activo"
        );
        echo json_encode(['ok' => true, 'id' => $this->mysqli->insert_id ?: 0]);
    }

    public function destroy(int $id): void
    {
        $eid = $this->empresa_id;
        $this->mysqli->query("DELETE FROM canales WHERE id=$id AND empresa_id=$eid");
        echo json_encode(['ok' => true]);
    }

    public function test(int $id): void
    {
        $eid = $this->empresa_id;
        $row = $this->mysqli->query(
            "SELECT * FROM canales WHERE id=$id AND empresa_id=$eid"
        )->fetch_assoc();

        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => 'No encontrado']);
            return;
        }

        [$ok, $msg] = $this->testCanal($row);
        echo json_encode(['ok' => $ok, 'message' => $msg]);
    }

    // -------------------------------------------------------
    private function testCanal(array $row): array
    {
        $canal = $row['canal'];
        $token = $row['access_token'] ?? '';

        switch ($canal) {
            case 'whatsapp':
                $phone_id = $_ENV['WA_PHONE_ID'] ?? '';
                $t        = $_ENV['WA_TOKEN'] ?? $token;
                if (!$phone_id || !$t) return [false, 'WA_PHONE_ID o WA_TOKEN no configurados en .env'];
                $r = $this->graphGet($phone_id, $t);
                return isset($r['id'])
                    ? [true,  'Conectado: ' . ($r['display_phone_number'] ?? $r['id'])]
                    : [false, $r['error']['message'] ?? 'Error de conexión'];

            case 'messenger':
            case 'instagram':
            case 'facebook':
                if (!$token) return [false, 'access_token vacío'];
                $r = $this->graphGet('me', $token);
                return isset($r['id'])
                    ? [true,  'Conectado: ' . ($r['name'] ?? $r['id'])]
                    : [false, $r['error']['message'] ?? 'Error de conexión'];

            case 'telegram':
                $t = $_ENV['TELEGRAM_BOT_TOKEN'] ?? $token;
                if (!$t) return [false, 'TELEGRAM_BOT_TOKEN no configurado en .env'];
                $ch = curl_init("https://api.telegram.org/bot$t/getMe");
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
                $r = json_decode(curl_exec($ch) ?: '{}', true);
                curl_close($ch);
                return ($r['ok'] ?? false)
                    ? [true,  'Bot: @' . ($r['result']['username'] ?? '')]
                    : [false, $r['description'] ?? 'Error'];

            default:
                return [false, 'Test no implementado para este canal'];
        }
    }

    private function graphGet(string $endpoint, string $token): array
    {
        $ch = curl_init("https://graph.facebook.com/v25.0/$endpoint?access_token=" . urlencode($token));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
        $raw = curl_exec($ch);
        curl_close($ch);
        return json_decode($raw ?: '{}', true) ?? [];
    }
}
