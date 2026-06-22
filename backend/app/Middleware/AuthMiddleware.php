<?php

namespace App\Middleware;

class AuthMiddleware
{
    public static function verify(): void
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $secret = $_ENV['API_SECRET'] ?? '';

        if (!$secret || !$header || !hash_equals("Bearer $secret", $header)) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
    }
}
