<?php

namespace App\Middleware;

class AuthMiddleware
{
    public static function verify(\mysqli $db): void
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $token  = str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';

        if (!$token) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'No autenticado']);
            exit;
        }

        $stmt = $db->prepare(
            'SELECT usuario_id, empresa_id FROM sesiones WHERE token = ? AND expires_at > NOW()'
        );
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $session = $stmt->get_result()->fetch_assoc();

        if (!$session) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Sesión inválida o expirada']);
            exit;
        }

        $_ENV['AUTH_USER_ID']    = $session['usuario_id'];
        $_ENV['AUTH_EMPRESA_ID'] = $session['empresa_id'] ?? 1;
    }
}
