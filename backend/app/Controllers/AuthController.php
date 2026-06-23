<?php

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Services\AuditService;

class AuthController
{
    private \mysqli $db;
    private AuditService $audit;

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
        $this->audit = new AuditService($db);
    }

    public function login(): void
    {
        $body = json_decode(file_get_contents('php://input'), true);
        $email = mb_strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');

        if ($email === '' || $password === '') {
            $this->json(['error' => 'email y password son requeridos'], 400);
            return;
        }

        $emailEsc = $this->db->real_escape_string($email);
        $user = $this->db->query(
            "SELECT id, nombre, email, password_sha256, activo, is_superadmin
             FROM app_users
             WHERE email = '$emailEsc'
             LIMIT 1"
        )?->fetch_assoc();

        if (!$user || !(int) $user['activo']) {
            $this->json(['error' => 'Credenciales inválidas'], 401);
            return;
        }

        $passwordHash = hash('sha256', $password);
        if (!hash_equals((string) $user['password_sha256'], $passwordHash)) {
            $this->json(['error' => 'Credenciales inválidas'], 401);
            return;
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
        $stmt = $this->db->prepare(
            "INSERT INTO auth_sessions (user_id, session_token, expires_at, last_seen_at)
             VALUES (?, ?, ?, NOW())"
        );
        $userId = (int) $user['id'];
        $stmt->bind_param('iss', $userId, $token, $expiresAt);
        $stmt->execute();
        $stmt->close();

        $this->audit->log($userId, null, 'auth.login', 'app_user', $userId, [
            'email' => $email,
        ]);

        $this->json($this->buildSessionPayload($userId, $token));
    }

    public function me(): void
    {
        $current = AuthMiddleware::currentUser();
        if (!$current) {
            $this->json(['error' => 'No autenticado'], 401);
            return;
        }

        $token = AuthMiddleware::currentSessionToken();
        $this->json($this->buildSessionPayload((int) $current['id'], $token));
    }

    public function logout(): void
    {
        $token = AuthMiddleware::currentSessionToken();
        if ($token) {
            $tokenEsc = $this->db->real_escape_string($token);
            $this->db->query("DELETE FROM auth_sessions WHERE session_token = '$tokenEsc'");
        }

        $current = AuthMiddleware::currentUser();
        $this->audit->log((int) ($current['id'] ?? 0), null, 'auth.logout', 'app_user', $current['id'] ?? null);

        $this->json(['ok' => true]);
    }

    private function buildSessionPayload(int $userId, ?string $sessionToken): array
    {
        $user = $this->db->query(
            "SELECT id, nombre, email, is_superadmin
             FROM app_users
             WHERE id = $userId
             LIMIT 1"
        )?->fetch_assoc();

        $companies = [];
        $result = $this->db->query(
            "SELECT auc.id AS user_company_id, auc.empresa_id, auc.role, auc.recruiter_id,
                    e.nombre AS empresa_nombre,
                    r.nombre AS recruiter_nombre
             FROM app_user_companies auc
             JOIN empresas e ON e.id = auc.empresa_id
             LEFT JOIN recruiters r ON r.id = auc.recruiter_id
             WHERE auc.user_id = $userId
             ORDER BY e.nombre ASC"
        );

        while ($row = $result?->fetch_assoc()) {
            $overrides = [];
            $userCompanyId = (int) ($row['user_company_id'] ?? 0);
            $overridesResult = $this->db->query(
                "SELECT permission_key AS `key`, effect
                 FROM app_user_company_permissions
                 WHERE user_company_id = $userCompanyId"
            );
            while ($override = $overridesResult?->fetch_assoc()) {
                $overrides[] = $override;
            }

            $row['permissions'] = AuthMiddleware::applyPermissionOverrides(
                AuthMiddleware::permissionsForRole((string) ($row['role'] ?? 'viewer')),
                $overrides
            );
            $row['permission_overrides'] = $overrides;
            $companies[] = $row;
        }

        return [
            'session_token' => $sessionToken,
            'user' => $user,
            'companies' => $companies,
            'default_company_id' => (int) ($companies[0]['empresa_id'] ?? 0),
        ];
    }

    private function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
