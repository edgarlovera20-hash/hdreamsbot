<?php

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Services\AuditService;

class AccessController
{
    private \mysqli $db;
    private AuditService $audit;

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
        $this->audit = new AuditService($db);
    }

    public function index(): void
    {
        $empresaId = (int) ($_GET['empresa_id'] ?? 0);
        if (!$empresaId) {
            $this->json(['error' => 'empresa_id requerido'], 400);
            return;
        }

        AuthMiddleware::assertPermission('accounts.manage', $empresaId);

        $this->json([
            'catalog' => AuthMiddleware::permissionCatalog(),
            'roles' => AuthMiddleware::roleCatalog(),
            'users' => $this->fetchUsers($empresaId),
        ]);
    }

    public function update(): void
    {
        $body = json_decode(file_get_contents('php://input'), true);
        $empresaId = (int) ($body['empresa_id'] ?? 0);
        $userId = (int) ($body['user_id'] ?? 0);
        $role = trim((string) ($body['role'] ?? 'viewer'));
        $permissions = $body['permissions'] ?? [];

        if (!$empresaId || !$userId) {
            $this->json(['error' => 'empresa_id y user_id son requeridos'], 400);
            return;
        }

        if (!array_key_exists($role, AuthMiddleware::roleCatalog())) {
            $this->json(['error' => 'role inválido'], 400);
            return;
        }

        AuthMiddleware::assertPermission('accounts.manage', $empresaId);

        $userCompany = $this->db->query(
            "SELECT id
             FROM app_user_companies
             WHERE user_id = $userId AND empresa_id = $empresaId
             LIMIT 1"
        )?->fetch_assoc();

        if (!$userCompany) {
            $this->json(['error' => 'El usuario no pertenece a esta empresa'], 404);
            return;
        }

        $userCompanyId = (int) $userCompany['id'];
        $roleEsc = $this->db->real_escape_string($role);
        $this->db->query("UPDATE app_user_companies SET role = '$roleEsc' WHERE id = $userCompanyId");
        $this->db->query("DELETE FROM app_user_company_permissions WHERE user_company_id = $userCompanyId");

        $catalogKeys = array_column(AuthMiddleware::permissionCatalog(), 'key');
        foreach ($permissions as $item) {
            $key = trim((string) ($item['key'] ?? ''));
            $effect = trim((string) ($item['effect'] ?? 'allow'));

            if ($key === '' || !in_array($key, $catalogKeys, true)) {
                continue;
            }

            if (!in_array($effect, ['allow', 'deny'], true)) {
                continue;
            }

            $keyEsc = $this->db->real_escape_string($key);
            $effectEsc = $this->db->real_escape_string($effect);
            $this->db->query(
                "INSERT INTO app_user_company_permissions (user_company_id, permission_key, effect)
                 VALUES ($userCompanyId, '$keyEsc', '$effectEsc')"
            );
        }

        $current = AuthMiddleware::currentUser();
        $this->audit->log((int) ($current['id'] ?? 0), $empresaId, 'access.permissions_update', 'app_user', $userId, [
            'role' => $role,
            'permissions' => $permissions,
        ]);

        $this->json([
            'ok' => true,
            'users' => $this->fetchUsers($empresaId),
        ]);
    }

    private function fetchUsers(int $empresaId): array
    {
        $result = $this->db->query(
            "SELECT auc.id AS user_company_id, auc.user_id, auc.empresa_id, auc.role, auc.recruiter_id,
                    u.nombre, u.email, u.activo, u.is_superadmin,
                    r.nombre AS recruiter_nombre
             FROM app_user_companies auc
             JOIN app_users u ON u.id = auc.user_id
             LEFT JOIN recruiters r ON r.id = auc.recruiter_id
             WHERE auc.empresa_id = $empresaId
             ORDER BY u.nombre ASC"
        );

        $users = [];
        while ($row = $result?->fetch_assoc()) {
            $userCompanyId = (int) $row['user_company_id'];
            $row['role_permissions'] = AuthMiddleware::permissionsForRole((string) $row['role']);
            $row['permission_overrides'] = $this->fetchOverrides($userCompanyId);
            $row['effective_permissions'] = AuthMiddleware::applyPermissionOverrides(
                $row['role_permissions'],
                $row['permission_overrides']
            );
            $users[] = $row;
        }

        return $users;
    }

    private function fetchOverrides(int $userCompanyId): array
    {
        $result = $this->db->query(
            "SELECT permission_key AS `key`, effect
             FROM app_user_company_permissions
             WHERE user_company_id = $userCompanyId
             ORDER BY permission_key ASC"
        );

        $items = [];
        while ($row = $result?->fetch_assoc()) {
            $items[] = $row;
        }

        return $items;
    }

    private function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
