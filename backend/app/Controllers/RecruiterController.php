<?php

namespace App\Controllers;

use App\Middleware\AuthMiddleware;

class RecruiterController
{
    private \mysqli $db;

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
    }

    public function index(): void
    {
        $empresaId = (int) ($_GET['empresa_id'] ?? 0);
        if (!$empresaId) {
            $this->json(['error' => 'empresa_id requerido'], 400);
            return;
        }

        AuthMiddleware::assertCompanyAccess($empresaId);

        $result = $this->db->query(
            "SELECT id, empresa_id, nombre, email, telefono, activo, created_at
             FROM recruiters
             WHERE empresa_id = $empresaId
             ORDER BY activo DESC, nombre ASC"
        );

        $items = [];
        while ($row = $result?->fetch_assoc()) {
            $items[] = $row;
        }

        $this->json(['items' => $items, 'total' => count($items)]);
    }

    private function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
