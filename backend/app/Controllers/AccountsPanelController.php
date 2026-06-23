<?php

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Services\AuditService;

class AccountsPanelController
{
    private \mysqli $db;
    private AuditService $audit;

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
        $this->audit = new AuditService($db);
    }

    public function summary(): void
    {
        $accounts = $this->fetchAccounts();
        $apps = $this->fetchApps();

        $this->json([
            'summary' => [
                'accounts' => count($accounts),
                'active_apps' => count(array_filter($apps, fn ($app) => (int) $app['activo'] === 1)),
                'connected_channels' => count($apps),
                'pending_interviews' => $this->scalar(
                    "SELECT COUNT(*) AS total FROM interviews WHERE status IN ('agendada','confirmada','reagendada') AND " . $this->companyScopeSql('empresa_id')
                ),
                'active_leads' => $this->scalar(
                    "SELECT COUNT(*) AS total FROM leads WHERE estado NOT IN ('rechazado','no_interesado','contratado') AND " . $this->companyScopeSql('empresa_id')
                ),
                'urgent_leads' => $this->scalar(
                    "SELECT COUNT(*) AS total FROM leads WHERE prioridad = 'urgente' AND estado NOT IN ('rechazado','no_interesado','contratado') AND " . $this->companyScopeSql('empresa_id')
                ),
            ],
            'accounts' => $accounts,
            'apps' => $apps,
        ]);
    }

    public function apps(): void
    {
        $this->json([
            'apps' => $this->fetchApps(),
        ]);
    }

    public function upsertApp(): void
    {
        $body = json_decode(file_get_contents('php://input'), true);

        $empresaId = (int) ($body['empresa_id'] ?? 0);
        $seccionId = (int) ($body['seccion_id'] ?? 1);
        $canal = $this->safeEnum($body['canal'] ?? '', ['whatsapp','messenger','instagram','facebook','telegram','gmail','outlook','teams']);
        $nombre = trim((string) ($body['nombre_cuenta'] ?? ''));
        $inboxAlias = trim((string) ($body['inbox_alias'] ?? ''));
        $status = $this->safeEnum($body['status'] ?? 'connected', ['connected','warning','disconnected']);
        $pageId = trim((string) ($body['page_id'] ?? ''));
        $activo = isset($body['activo']) ? ((int) !!$body['activo']) : 1;

        if (!$empresaId || !$canal) {
            $this->json(['error' => 'empresa_id y canal son requeridos'], 400);
            return;
        }

        AuthMiddleware::assertCompanyAccess($empresaId);
        AuthMiddleware::assertPermission('accounts.manage', $empresaId);

        $config = array_filter([
            'nombre_cuenta' => $nombre,
            'inbox_alias' => $inboxAlias,
            'status' => $status,
            'last_sync_at' => date('c'),
            'access_token' => $this->cleanString($body['access_token'] ?? null),
            'phone_number' => $this->cleanString($body['phone_number'] ?? null),
            'phone_number_id' => $this->cleanString($body['phone_number_id'] ?? null),
            'verify_token' => $this->cleanString($body['verify_token'] ?? null),
            'app_secret' => $this->cleanString($body['app_secret'] ?? null),
            'telegram_bot_token' => $this->cleanString($body['telegram_bot_token'] ?? null),
            'google_client_id' => $this->cleanString($body['google_client_id'] ?? null),
            'google_client_secret' => $this->cleanString($body['google_client_secret'] ?? null),
            'google_refresh_token' => $this->cleanString($body['google_refresh_token'] ?? null),
            'google_user_email' => $this->cleanString($body['google_user_email'] ?? null),
            'ms_tenant_id' => $this->cleanString($body['ms_tenant_id'] ?? null),
            'ms_client_id' => $this->cleanString($body['ms_client_id'] ?? null),
            'ms_client_secret' => $this->cleanString($body['ms_client_secret'] ?? null),
            'ms_refresh_token' => $this->cleanString($body['ms_refresh_token'] ?? null),
            'ms_user_email' => $this->cleanString($body['ms_user_email'] ?? null),
            'teams_chat_id' => $this->cleanString($body['teams_chat_id'] ?? null),
            'teams_team_id' => $this->cleanString($body['teams_team_id'] ?? null),
            'teams_channel_id' => $this->cleanString($body['teams_channel_id'] ?? null),
            'notes' => $this->cleanString($body['notes'] ?? null),
        ], static fn ($value) => $value !== null && $value !== '');
        $configJson = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $existing = $this->db->query(
            "SELECT id FROM canales
             WHERE empresa_id = $empresaId
               AND seccion_id = $seccionId
               AND canal = '$canal'
             LIMIT 1"
        )->fetch_assoc();

        if ($existing) {
            $stmt = $this->db->prepare(
                "UPDATE canales
                 SET page_id = ?, activo = ?, config = ?
                 WHERE id = ?"
            );
            $id = (int) $existing['id'];
            $stmt->bind_param('sisi', $pageId, $activo, $configJson, $id);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $this->db->prepare(
                "INSERT INTO canales (empresa_id, seccion_id, canal, page_id, activo, config)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('iissis', $empresaId, $seccionId, $canal, $pageId, $activo, $configJson);
            $stmt->execute();
            $stmt->close();
        }

        $user = AuthMiddleware::currentUser();
        $this->audit->log((int) ($user['id'] ?? 0), $empresaId, 'accounts.app_upsert', 'canal', $canal, [
            'seccion_id' => $seccionId,
            'status' => $status,
            'activo' => $activo,
            'has_access_token' => !empty($config['access_token']),
            'has_phone_number_id' => !empty($config['phone_number_id']),
            'has_verify_token' => !empty($config['verify_token']),
            'has_app_secret' => !empty($config['app_secret']),
            'has_telegram_bot_token' => !empty($config['telegram_bot_token']),
        ]);

        $this->json(['ok' => true]);
    }

    private function fetchAccounts(): array
    {
        $result = $this->db->query(
            "SELECT e.id, e.nombre, e.activo, e.created_at,
                    COUNT(DISTINCT c.id) AS apps_total,
                    SUM(c.activo = 1) AS apps_activas,
                    COUNT(DISTINCT l.id) AS leads_total,
                    SUM(l.estado NOT IN ('rechazado','no_interesado','contratado')) AS leads_activos,
                    SUM(l.prioridad = 'urgente' AND l.estado NOT IN ('rechazado','no_interesado','contratado')) AS leads_urgentes,
                    SUM(i.status IN ('agendada','confirmada','reagendada')) AS entrevistas_pendientes
             FROM empresas e
             LEFT JOIN canales c ON c.empresa_id = e.id
             LEFT JOIN leads l ON l.empresa_id = e.id
             LEFT JOIN interviews i ON i.empresa_id = e.id
             WHERE " . $this->companyScopeSql('e.id') . "
             GROUP BY e.id, e.nombre, e.activo, e.created_at
             ORDER BY e.nombre ASC"
        );

        $items = [];
        while ($row = $result?->fetch_assoc()) {
            $items[] = $row;
        }
        return $items;
    }

    private function fetchApps(): array
    {
        $result = $this->db->query(
            "SELECT c.id, c.empresa_id, e.nombre AS empresa_nombre, c.seccion_id, s.nombre AS seccion_nombre,
                    c.canal, c.page_id, c.activo, c.created_at, c.config,
                    COUNT(DISTINCT l.id) AS leads_total,
                    SUM(l.estado NOT IN ('rechazado','no_interesado','contratado')) AS leads_activos,
                    SUM(l.prioridad = 'urgente' AND l.estado NOT IN ('rechazado','no_interesado','contratado')) AS urgentes
             FROM canales c
             JOIN empresas e ON e.id = c.empresa_id
             JOIN secciones s ON s.id = c.seccion_id
             LEFT JOIN leads l ON l.empresa_id = c.empresa_id AND l.canal = c.canal
             WHERE " . $this->companyScopeSql('c.empresa_id') . "
             GROUP BY c.id, c.empresa_id, e.nombre, c.seccion_id, s.nombre, c.canal, c.page_id, c.activo, c.created_at, c.config
             ORDER BY e.nombre ASC, c.canal ASC"
        );

        $items = [];
        while ($row = $result?->fetch_assoc()) {
            $config = json_decode($row['config'] ?? '{}', true) ?: [];
            $credentials = [
                'access_token' => $config['access_token'] ?? '',
                'phone_number' => $config['phone_number'] ?? '',
                'phone_number_id' => $config['phone_number_id'] ?? '',
                'verify_token' => $config['verify_token'] ?? '',
                'app_secret' => $config['app_secret'] ?? '',
                'telegram_bot_token' => $config['telegram_bot_token'] ?? '',
                'google_client_id' => $config['google_client_id'] ?? '',
                'google_client_secret' => $config['google_client_secret'] ?? '',
                'google_refresh_token' => $config['google_refresh_token'] ?? '',
                'google_user_email' => $config['google_user_email'] ?? '',
                'ms_tenant_id' => $config['ms_tenant_id'] ?? '',
                'ms_client_id' => $config['ms_client_id'] ?? '',
                'ms_client_secret' => $config['ms_client_secret'] ?? '',
                'ms_refresh_token' => $config['ms_refresh_token'] ?? '',
                'ms_user_email' => $config['ms_user_email'] ?? '',
                'teams_chat_id' => $config['teams_chat_id'] ?? '',
                'teams_team_id' => $config['teams_team_id'] ?? '',
                'teams_channel_id' => $config['teams_channel_id'] ?? '',
                'notes' => $config['notes'] ?? '',
            ];

            $row['nombre_cuenta'] = $config['nombre_cuenta'] ?? null;
            $row['inbox_alias'] = $config['inbox_alias'] ?? null;
            $row['status'] = $config['status'] ?? ((int) $row['activo'] === 1 ? 'connected' : 'disconnected');
            $row['last_sync_at'] = $config['last_sync_at'] ?? null;
            $row['credentials'] = $credentials;
            unset($row['config']);
            $items[] = $row;
        }
        return $items;
    }

    private function scalar(string $sql): int
    {
        $row = $this->db->query($sql)?->fetch_assoc();
        return (int) ($row['total'] ?? 0);
    }

    private function companyScopeSql(string $column): string
    {
        $user = AuthMiddleware::currentUser();
        if (!$user || !empty($user['all_access'])) {
            return '1=1';
        }

        $ids = array_map(
            fn (array $company) => (int) $company['empresa_id'],
            $user['companies'] ?? []
        );

        if (!$ids) {
            return '1=0';
        }

        return $column . ' IN (' . implode(',', $ids) . ')';
    }

    private function safeEnum(?string $value, array $allowed): ?string
    {
        return ($value && in_array($value, $allowed, true)) ? $value : null;
    }

    private function cleanString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
