<?php

namespace App\Middleware;

class AuthMiddleware
{
    private const ROLE_PERMISSIONS = [
        'viewer' => [
            'dashboard.view',
            'leads.view',
            'flow.view',
            'notifications.view',
        ],
        'recruiter' => [
            'dashboard.view',
            'leads.view',
            'flow.view',
            'notifications.view',
            'leads.note',
            'inbox.reply',
            'interviews.manage',
        ],
        'manager' => [
            'dashboard.view',
            'leads.view',
            'flow.view',
            'notifications.view',
            'leads.note',
            'inbox.reply',
            'interviews.manage',
            'inbox.assign',
            'accounts.manage',
            'executive.view',
        ],
        'admin' => ['*'],
    ];

    private static ?array $authUser = null;
    private static ?string $sessionToken = null;

    public static function roleCatalog(): array
    {
        return [
            'viewer' => ['label' => 'Viewer'],
            'recruiter' => ['label' => 'Recruiter'],
            'manager' => ['label' => 'Manager'],
            'admin' => ['label' => 'Admin'],
        ];
    }

    public static function permissionCatalog(): array
    {
        return [
            ['key' => 'dashboard.view', 'label' => 'Dashboard', 'description' => 'Ver dashboard operativo'],
            ['key' => 'leads.view', 'label' => 'Leads', 'description' => 'Ver leads y detalle'],
            ['key' => 'flow.view', 'label' => 'Flujo', 'description' => 'Ver funnel y flujo'],
            ['key' => 'notifications.view', 'label' => 'Alertas', 'description' => 'Ver notificaciones y alertas'],
            ['key' => 'leads.note', 'label' => 'Notas', 'description' => 'Crear notas en leads'],
            ['key' => 'inbox.reply', 'label' => 'Inbox reply', 'description' => 'Responder conversaciones y voz'],
            ['key' => 'interviews.manage', 'label' => 'Agenda', 'description' => 'Crear y actualizar entrevistas'],
            ['key' => 'inbox.assign', 'label' => 'Asignación', 'description' => 'Asignar leads y autoasignar'],
            ['key' => 'accounts.manage', 'label' => 'Cuentas y permisos', 'description' => 'Administrar apps, cuentas y permisos'],
            ['key' => 'executive.view', 'label' => 'Executive', 'description' => 'Ver panel ejecutivo, supervisor y reportes'],
        ];
    }

    public static function permissionsForRole(string $role): array
    {
        return self::ROLE_PERMISSIONS[$role] ?? self::ROLE_PERMISSIONS['viewer'];
    }

    public static function applyPermissionOverrides(array $basePermissions, array $overrides): array
    {
        if (in_array('*', $basePermissions, true)) {
            return ['*'];
        }

        $permissions = array_values(array_unique($basePermissions));
        foreach ($overrides as $override) {
            $key = (string) ($override['key'] ?? '');
            $effect = (string) ($override['effect'] ?? 'allow');

            if ($key === '') {
                continue;
            }

            if ($effect === 'allow' && !in_array($key, $permissions, true)) {
                $permissions[] = $key;
            }

            if ($effect === 'deny') {
                $permissions = array_values(array_filter($permissions, fn (string $item) => $item !== $key));
            }
        }

        sort($permissions);
        return $permissions;
    }

    public static function verify(\mysqli $db, string $uri): void
    {
        if ($uri === '/api/auth/login') {
            return;
        }

        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $secret = $_ENV['API_SECRET'] ?? '';

        if ($secret && $header && hash_equals("Bearer $secret", $header)) {
            self::$authUser = [
                'id' => 0,
                'nombre' => 'system',
                'email' => 'system@local',
                'is_superadmin' => 1,
                'all_access' => true,
                'companies' => [],
            ];
            self::$sessionToken = null;
            return;
        }

        $token = trim((string) ($_SERVER['HTTP_X_SESSION_TOKEN'] ?? ''));
        if ($token === '' && preg_match('/^Session\s+(.+)$/i', $header, $matches)) {
            $token = trim((string) ($matches[1] ?? ''));
        }

        if ($token === '') {
            self::unauthorized();
        }

        $tokenEsc = $db->real_escape_string($token);
        $user = $db->query(
            "SELECT u.id, u.nombre, u.email, u.is_superadmin
             FROM auth_sessions s
             JOIN app_users u ON u.id = s.user_id
             WHERE s.session_token = '$tokenEsc'
               AND u.activo = 1
               AND s.expires_at > NOW()
             LIMIT 1"
        )?->fetch_assoc();

        if (!$user) {
            self::unauthorized();
        }

        $userId = (int) $user['id'];
        $companies = [];
        $result = $db->query(
            "SELECT auc.empresa_id, auc.role, auc.recruiter_id,
                    auc.id AS user_company_id,
                    e.nombre AS empresa_nombre,
                    r.nombre AS recruiter_nombre
             FROM app_user_companies auc
             JOIN empresas e ON e.id = auc.empresa_id
             LEFT JOIN recruiters r ON r.id = auc.recruiter_id
             WHERE auc.user_id = $userId"
        );
        while ($row = $result?->fetch_assoc()) {
            $userCompanyId = (int) ($row['user_company_id'] ?? 0);
            $overrides = self::fetchPermissionOverrides($db, $userCompanyId);
            $rolePermissions = self::permissionsForRole((string) ($row['role'] ?? 'viewer'));
            $row['permissions'] = self::applyPermissionOverrides($rolePermissions, $overrides);
            $row['permission_overrides'] = $overrides;
            $companies[] = $row;
        }

        $db->query("UPDATE auth_sessions SET last_seen_at = NOW() WHERE session_token = '$tokenEsc'");

        self::$authUser = [
            'id' => $userId,
            'nombre' => $user['nombre'],
            'email' => $user['email'],
            'is_superadmin' => (int) $user['is_superadmin'],
            'all_access' => (int) $user['is_superadmin'] === 1,
            'companies' => $companies,
        ];
        self::$sessionToken = $token;
    }

    public static function currentUser(): ?array
    {
        return self::$authUser;
    }

    public static function currentSessionToken(): ?string
    {
        return self::$sessionToken;
    }

    public static function assertCompanyAccess(int $empresaId): void
    {
        $user = self::$authUser;
        if (!$user) {
            self::unauthorized();
        }

        if (!empty($user['all_access'])) {
            return;
        }

        foreach ($user['companies'] as $company) {
            if ((int) $company['empresa_id'] === $empresaId) {
                return;
            }
        }

        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Sin acceso a esta empresa']);
        exit;
    }

    public static function companyContext(int $empresaId): ?array
    {
        $user = self::$authUser;
        if (!$user) {
            return null;
        }

        if (!empty($user['all_access'])) {
            return [
                'empresa_id' => $empresaId,
                'role' => 'admin',
                'recruiter_id' => null,
            ];
        }

        foreach ($user['companies'] as $company) {
            if ((int) $company['empresa_id'] === $empresaId) {
                return $company;
            }
        }

        return null;
    }

    public static function assertPermission(string $permission, int $empresaId): void
    {
        if (self::hasPermission($permission, $empresaId)) {
            return;
        }

        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Sin permiso para esta acción', 'permission' => $permission]);
        exit;
    }

    public static function hasPermission(string $permission, int $empresaId): bool
    {
        self::assertCompanyAccess($empresaId);
        $user = self::$authUser;
        if (!$user) {
            self::unauthorized();
        }

        if (!empty($user['all_access'])) {
            return true;
        }

        $context = self::companyContext($empresaId);
        $permissions = $context['permissions'] ?? self::permissionsForRole((string) ($context['role'] ?? 'viewer'));

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    private static function fetchPermissionOverrides(\mysqli $db, int $userCompanyId): array
    {
        if ($userCompanyId <= 0) {
            return [];
        }

        $exists = $db->query("SHOW TABLES LIKE 'app_user_company_permissions'")?->num_rows ?? 0;
        if (!$exists) {
            return [];
        }

        $result = $db->query(
            "SELECT permission_key AS `key`, effect
             FROM app_user_company_permissions
             WHERE user_company_id = $userCompanyId"
        );

        $items = [];
        while ($row = $result?->fetch_assoc()) {
            $items[] = $row;
        }

        return $items;
    }

    private static function unauthorized(): void
    {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}
