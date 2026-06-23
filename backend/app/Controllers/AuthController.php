<?php

namespace App\Controllers;

class AuthController
{
    public function __construct(private \mysqli $db) {}

    public function login(): void
    {
        $body  = json_decode(file_get_contents('php://input'), true) ?? [];
        $email = trim($body['email'] ?? '');
        $pass  = $body['password'] ?? '';

        if (!$email || !$pass) {
            http_response_code(400);
            echo json_encode(['error' => 'Email y contraseña requeridos']);
            return;
        }

        $stmt = $this->db->prepare(
            'SELECT id, password_hash, nombre, rol FROM usuarios WHERE email = ? AND activo = 1'
        );
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if (!$user || !password_verify($pass, $user['password_hash'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Credenciales incorrectas']);
            return;
        }

        $stmt2 = $this->db->prepare('SELECT id, nombre FROM empresas WHERE activo = 1 ORDER BY nombre');
        $stmt2->execute();
        $empresas   = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
        $empresa_id = $empresas[0]['id'] ?? 1;

        $token   = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+8 hours'));

        $stmt3 = $this->db->prepare(
            'INSERT INTO sesiones (token, usuario_id, empresa_id, expires_at) VALUES (?, ?, ?, ?)'
        );
        $stmt3->bind_param('siis', $token, $user['id'], $empresa_id, $expires);
        $stmt3->execute();

        echo json_encode([
            'token'      => $token,
            'user'       => ['id' => $user['id'], 'nombre' => $user['nombre'], 'rol' => $user['rol']],
            'empresas'   => $empresas,
            'empresa_id' => $empresa_id,
        ]);
    }

    public function logout(): void
    {
        $token = $this->extractToken();
        if ($token) {
            $stmt = $this->db->prepare('DELETE FROM sesiones WHERE token = ?');
            $stmt->bind_param('s', $token);
            $stmt->execute();
        }
        echo json_encode(['ok' => true]);
    }

    public function me(): void
    {
        $token = $this->extractToken();
        if (!$token) {
            http_response_code(401);
            echo json_encode(['error' => 'No autenticado']);
            return;
        }

        $stmt = $this->db->prepare(
            'SELECT u.id, u.nombre, u.email, u.rol, s.empresa_id
             FROM sesiones s JOIN usuarios u ON u.id = s.usuario_id
             WHERE s.token = ? AND s.expires_at > NOW()'
        );
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Sesión expirada']);
            return;
        }

        $stmt2 = $this->db->prepare('SELECT id, nombre FROM empresas WHERE activo = 1 ORDER BY nombre');
        $stmt2->execute();
        $empresas = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);

        echo json_encode(['user' => $user, 'empresas' => $empresas]);
    }

    public function switchEmpresa(): void
    {
        $token      = $this->extractToken();
        $body       = json_decode(file_get_contents('php://input'), true) ?? [];
        $empresa_id = (int)($body['empresa_id'] ?? 0);

        if (!$token || !$empresa_id) {
            http_response_code(400);
            echo json_encode(['error' => 'Datos requeridos']);
            return;
        }

        $stmt = $this->db->prepare('UPDATE sesiones SET empresa_id = ? WHERE token = ?');
        $stmt->bind_param('is', $empresa_id, $token);
        $stmt->execute();

        echo json_encode(['ok' => true, 'empresa_id' => $empresa_id]);
    }

    private function extractToken(): ?string
    {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        return str_starts_with($h, 'Bearer ') ? substr($h, 7) : null;
    }
}
