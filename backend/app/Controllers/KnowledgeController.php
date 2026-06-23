<?php

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Services\AuditService;
use App\Services\KnowledgeBaseService;

class KnowledgeController
{
    private array $config;
    private KnowledgeBaseService $knowledge;
    private AuditService $audit;

    public function __construct(private \mysqli $db)
    {
        $this->config = require __DIR__ . '/../../config/app.php';
        $this->knowledge = new KnowledgeBaseService($db);
        $this->audit = new AuditService($db);
    }

    public function documents(): void
    {
        $empresaId = (int) ($_GET['empresa_id'] ?? 0);
        if (!$empresaId) {
            $this->json(['error' => 'empresa_id requerido'], 400);
            return;
        }
        AuthMiddleware::assertCompanyAccess($empresaId);
        AuthMiddleware::assertPermission('executive.view', $empresaId);
        $this->json(['items' => $this->knowledge->recentDocuments($empresaId)]);
    }

    public function upload(): void
    {
        $empresaId = (int) ($_POST['empresa_id'] ?? 0);
        if (!$empresaId || empty($_FILES['document'])) {
            $this->json(['error' => 'empresa_id y document son requeridos'], 400);
            return;
        }
        AuthMiddleware::assertCompanyAccess($empresaId);
        AuthMiddleware::assertPermission('executive.view', $empresaId);

        $uploadDir = $this->config['uploads']['knowledge_dir'] ?? (__DIR__ . '/../../storage/knowledge');
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0777, true);
        }

        $file = $_FILES['document'];
        $filename = basename((string) ($file['name'] ?? 'document.txt'));
        $target = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . uniqid('knowledge-', true) . '-' . preg_replace('/[^A-Za-z0-9._-]/', '-', $filename);
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            $this->json(['error' => 'No se pudo guardar el documento'], 500);
            return;
        }

        $title = trim((string) ($_POST['title'] ?? pathinfo($filename, PATHINFO_FILENAME)));
        $mimeType = mime_content_type($target) ?: ($file['type'] ?? 'application/octet-stream');
        $titleEsc = $this->db->real_escape_string($title);
        $filenameEsc = $this->db->real_escape_string($filename);
        $mimeEsc = $this->db->real_escape_string($mimeType);
        $this->db->query(
            "INSERT INTO knowledge_documents (empresa_id, title, source_filename, mime_type, status)
             VALUES ($empresaId, '$titleEsc', '$filenameEsc', '$mimeEsc', 'uploaded')"
        );
        $documentId = (int) $this->db->insert_id;

        $pythonBin = (string) (($this->config['reporting']['python_bin'] ?? 'python'));
        $scriptPath = realpath(__DIR__ . '/../../scripts/extract_document_text.py');
        $cmd = escapeshellarg($pythonBin) . ' ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($target);
        $text = trim((string) @shell_exec($cmd));
        if ($text === '') {
            $text = @file_get_contents($target) ?: '';
        }

        $this->knowledge->indexDocument($empresaId, $documentId, $title, $text);
        $user = AuthMiddleware::currentUser();
        $this->audit->log((int) ($user['id'] ?? 0), $empresaId, 'knowledge.upload', 'knowledge_document', $documentId, ['title' => $title]);
        $this->json(['ok' => true, 'document_id' => $documentId]);
    }

    public function ask(): void
    {
        $body = json_decode(file_get_contents('php://input'), true);
        $empresaId = (int) ($body['empresa_id'] ?? 0);
        $question = trim((string) ($body['question'] ?? ''));
        if (!$empresaId || $question === '') {
            $this->json(['error' => 'empresa_id y question son requeridos'], 400);
            return;
        }
        AuthMiddleware::assertCompanyAccess($empresaId);
        AuthMiddleware::assertPermission('executive.view', $empresaId);
        $this->json($this->knowledge->ask($empresaId, $question));
    }

    private function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
