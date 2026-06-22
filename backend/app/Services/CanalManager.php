<?php

namespace App\Services;

class CanalManager
{
    private int     $empresa_id;
    private int     $seccion_id;
    private \mysqli $mysqli;
    private array   $canal_config;
    private string  $canal;

    public function __construct(int $empresa_id, int $seccion_id, \mysqli $mysqli, array $canal_config)
    {
        $this->empresa_id   = $empresa_id;
        $this->seccion_id   = $seccion_id;
        $this->mysqli       = $mysqli;
        $this->canal_config = $canal_config;
        $this->canal        = $canal_config['canal'] ?? '';
    }

    public function procesarMensaje(string $user_id, string $texto, string $tipo = 'text'): string
    {
        $stmt = $this->mysqli->prepare(
            "INSERT INTO ia_logs (empresa_id,seccion_id,wa_id,canal,pregunta) VALUES (?,?,?,?,?)"
        );
        $stmt->bind_param('iisss', $this->empresa_id, $this->seccion_id, $user_id, $this->canal, $texto);
        $stmt->execute();
        $stmt->close();

        return 'ok';
    }

    public function enviarRespuesta(string $user_id, string $texto): bool
    {
        $sent = match ($this->canal) {
            'messenger', 'instagram' => $this->enviarMessenger($user_id, $texto),
            'whatsapp'               => $this->enviarWhatsApp($user_id, $texto),
            'telegram'               => $this->enviarTelegram($user_id, $texto),
            default                  => false,
        };

        if ($sent) {
            $uid_esc = $this->mysqli->real_escape_string($user_id);
            $c_esc   = $this->mysqli->real_escape_string($this->canal);
            $this->mysqli->query(
                "UPDATE leads SET mensajes_enviados=mensajes_enviados+1
                 WHERE canal_user_id='$uid_esc' AND canal='$c_esc'"
            );
        }

        return $sent;
    }

    // -------------------------------------------------------
    private function enviarMessenger(string $psid, string $texto): bool
    {
        return $this->graphPost('me/messages', [
            'recipient'      => ['id' => $psid],
            'message'        => ['text' => $texto],
            'messaging_type' => 'RESPONSE',
        ], $this->canal_config['access_token'] ?? '');
    }

    private function enviarWhatsApp(string $wa_id, string $texto): bool
    {
        $phone_id = $_ENV['WA_PHONE_ID'] ?? '';
        $token    = $_ENV['WA_TOKEN'] ?? '';

        if (!$phone_id || !$token) {
            error_log('[CanalManager] WA_PHONE_ID o WA_TOKEN no configurados');
            return false;
        }

        return $this->graphPost("$phone_id/messages", [
            'messaging_product' => 'whatsapp',
            'to'                => $wa_id,
            'type'              => 'text',
            'text'              => ['body' => $texto],
        ], $token);
    }

    private function enviarTelegram(string $chat_id, string $texto): bool
    {
        $token = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
        if (!$token) return false;

        $ch = curl_init("https://api.telegram.org/bot$token/sendMessage");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_POSTFIELDS     => json_encode(['chat_id' => $chat_id, 'text' => $texto]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err || !$raw) {
            error_log("[CanalManager] Telegram error: $err");
            return false;
        }

        $res = json_decode($raw, true);
        return $res['ok'] ?? false;
    }

    private function graphPost(string $endpoint, array $data, string $token): bool
    {
        if (!$token) {
            error_log("[CanalManager] Token vacío para endpoint $endpoint");
            return false;
        }

        $ch = curl_init("https://graph.facebook.com/v25.0/$endpoint");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer $token",
                'Content-Type: application/json',
            ],
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err || !$raw) {
            error_log("[CanalManager] curl error on $endpoint: $err");
            return false;
        }

        $res = json_decode($raw, true);
        if (isset($res['error'])) {
            error_log("[CanalManager] Graph API error on $endpoint: " . json_encode($res['error']));
            return false;
        }

        return $status >= 200 && $status < 300;
    }
}
