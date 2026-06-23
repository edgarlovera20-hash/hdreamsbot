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
            'gmail'                  => $this->enviarGmail($user_id, $texto),
            'outlook'                => $this->enviarOutlook($user_id, $texto),
            'teams'                  => $this->enviarTeams($user_id, $texto),
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

    private function enviarMessenger(string $psid, string $texto): bool
    {
        return $this->graphPost('me/messages', [
            'recipient'      => ['id' => $psid],
            'message'        => ['text' => $texto],
            'messaging_type' => 'RESPONSE',
        ], $this->configValue('access_token', 'FB_PAGE_TOKEN'));
    }

    private function enviarWhatsApp(string $wa_id, string $texto): bool
    {
        $phoneId = $this->configValue('phone_number_id', 'WA_PHONE_ID');
        $token = $this->configValue('access_token', 'WA_TOKEN');

        if (!$phoneId || !$token) {
            error_log('[CanalManager] WhatsApp sin phone_number_id o access_token');
            return false;
        }

        return $this->graphPost("$phoneId/messages", [
            'messaging_product' => 'whatsapp',
            'to'                => $wa_id,
            'type'              => 'text',
            'text'              => ['body' => $texto],
        ], $token);
    }

    private function enviarTelegram(string $chat_id, string $texto): bool
    {
        $token = $this->configValue('telegram_bot_token', 'TELEGRAM_BOT_TOKEN');
        if (!$token) {
            error_log('[CanalManager] Telegram sin token configurado');
            return false;
        }

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

    private function enviarGmail(string $email, string $texto): bool
    {
        $token = $this->resolveGoogleAccessToken();
        $from = $this->configValue('google_user_email', 'GOOGLE_USER_EMAIL');
        $userId = $from !== '' ? $from : 'me';

        if ($token === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            error_log('[CanalManager] Gmail sin token o destinatario valido');
            return false;
        }

        $raw = $this->base64UrlEncode(
            "To: $email\r\n" .
            ($from !== '' ? "From: $from\r\n" : '') .
            "Subject: Seguimiento de reclutamiento Heavenly Dreams\r\n" .
            "MIME-Version: 1.0\r\n" .
            "Content-Type: text/plain; charset=UTF-8\r\n\r\n" .
            $texto
        );

        return $this->jsonPost(
            'https://gmail.googleapis.com/gmail/v1/users/' . rawurlencode($userId) . '/messages/send',
            ['raw' => $raw],
            $token
        );
    }

    private function enviarOutlook(string $email, string $texto): bool
    {
        $token = $this->resolveMicrosoftAccessToken();
        $sender = $this->configValue('ms_user_email', 'MS_USER_EMAIL');

        if ($token === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            error_log('[CanalManager] Outlook sin token o destinatario valido');
            return false;
        }

        $endpointUser = $sender !== '' ? 'users/' . rawurlencode($sender) : 'me';

        return $this->jsonPost(
            "https://graph.microsoft.com/v1.0/$endpointUser/sendMail",
            [
                'message' => [
                    'subject' => 'Seguimiento de reclutamiento Heavenly Dreams',
                    'body' => [
                        'contentType' => 'Text',
                        'content' => $texto,
                    ],
                    'toRecipients' => [[
                        'emailAddress' => ['address' => $email],
                    ]],
                ],
                'saveToSentItems' => true,
            ],
            $token,
            [200, 202]
        );
    }

    private function enviarTeams(string $target, string $texto): bool
    {
        $token = $this->resolveMicrosoftAccessToken();
        if ($token === '') {
            error_log('[CanalManager] Teams sin token Microsoft Graph');
            return false;
        }

        $chatId = $this->configValue('teams_chat_id', 'TEAMS_CHAT_ID') ?: $target;
        $teamId = $this->configValue('teams_team_id', 'TEAMS_TEAM_ID');
        $channelId = $this->configValue('teams_channel_id', 'TEAMS_CHANNEL_ID');

        if ($teamId !== '' && $channelId !== '') {
            $url = 'https://graph.microsoft.com/v1.0/teams/' . rawurlencode($teamId)
                . '/channels/' . rawurlencode($channelId) . '/messages';
        } elseif ($chatId !== '') {
            $url = 'https://graph.microsoft.com/v1.0/chats/' . rawurlencode($chatId) . '/messages';
        } else {
            error_log('[CanalManager] Teams requiere chat_id o team_id/channel_id');
            return false;
        }

        return $this->jsonPost($url, [
            'body' => [
                'contentType' => 'text',
                'content' => $texto,
            ],
        ], $token, [200, 201]);
    }

    private function graphPost(string $endpoint, array $data, string $token): bool
    {
        if (!$token) {
            error_log("[CanalManager] Token vacio para endpoint $endpoint");
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
            error_log('[CanalManager] Graph API error on ' . $endpoint . ': ' . json_encode($res['error']));
            return false;
        }

        return $status >= 200 && $status < 300;
    }

    private function jsonPost(string $url, array $data, string $token, array $okStatuses = [200]): bool
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
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

        if ($err) {
            error_log("[CanalManager] HTTP error on $url: $err");
            return false;
        }

        if (!in_array($status, $okStatuses, true)) {
            error_log("[CanalManager] HTTP status $status on $url: " . (string) $raw);
            return false;
        }

        return true;
    }

    private function resolveGoogleAccessToken(): string
    {
        $token = $this->configValue('access_token', 'GOOGLE_ACCESS_TOKEN');
        if ($token !== '') {
            return $token;
        }

        $refreshToken = $this->configValue('google_refresh_token', 'GOOGLE_REFRESH_TOKEN');
        $clientId = $this->configValue('google_client_id', 'GOOGLE_CLIENT_ID');
        $clientSecret = $this->configValue('google_client_secret', 'GOOGLE_CLIENT_SECRET');

        if ($refreshToken === '' || $clientId === '' || $clientSecret === '') {
            return '';
        }

        return $this->refreshOAuthToken('https://oauth2.googleapis.com/token', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);
    }

    private function resolveMicrosoftAccessToken(): string
    {
        $token = $this->configValue('access_token', 'MS_GRAPH_ACCESS_TOKEN');
        if ($token !== '') {
            return $token;
        }

        $tenantId = $this->configValue('ms_tenant_id', 'MS_TENANT_ID') ?: 'common';
        $refreshToken = $this->configValue('ms_refresh_token', 'MS_REFRESH_TOKEN');
        $clientId = $this->configValue('ms_client_id', 'MS_CLIENT_ID');
        $clientSecret = $this->configValue('ms_client_secret', 'MS_CLIENT_SECRET');

        if ($refreshToken === '' || $clientId === '' || $clientSecret === '') {
            return '';
        }

        return $this->refreshOAuthToken("https://login.microsoftonline.com/$tenantId/oauth2/v2.0/token", [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
            'scope' => 'offline_access Mail.Send ChatMessage.Send ChannelMessage.Send',
        ]);
    }

    private function refreshOAuthToken(string $url, array $fields): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err || $status < 200 || $status >= 300) {
            error_log("[CanalManager] OAuth refresh error $status: " . ($err ?: (string) $raw));
            return '';
        }

        $data = json_decode((string) $raw, true);
        return (string) ($data['access_token'] ?? '');
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function configValue(string $key, string $envKey): string
    {
        $value = trim((string) ($this->canal_config[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }

        return trim((string) ($_ENV[$envKey] ?? ''));
    }
}
