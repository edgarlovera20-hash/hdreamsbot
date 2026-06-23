<?php

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Services\AIClient;
use App\Services\AuditService;

class VoiceController
{
    private array $config;
    private AIClient $ai;
    private AuditService $audit;

    public function __construct(private \mysqli $db)
    {
        $this->config = require __DIR__ . '/../../config/app.php';
        $this->ai = new AIClient();
        $this->audit = new AuditService($db);
    }

    public function uploadForLead(int $leadId): void
    {
        $lead = $this->db->query("SELECT id, empresa_id, assigned_recruiter_id FROM leads WHERE id = $leadId")->fetch_assoc();
        if (!$lead) {
            $this->json(['error' => 'Lead no encontrado'], 404);
            return;
        }
        AuthMiddleware::assertCompanyAccess((int) $lead['empresa_id']);
        AuthMiddleware::assertPermission('inbox.reply', (int) $lead['empresa_id']);

        if (empty($_FILES['audio'])) {
            $this->json(['error' => 'audio requerido'], 400);
            return;
        }

        $dir = $this->config['uploads']['voice_dir'] ?? (__DIR__ . '/../../storage/voice-notes');
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $file = $_FILES['audio'];
        $filename = basename((string) ($file['name'] ?? 'voice-note.webm'));
        $target = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . uniqid('voice-', true) . '-' . preg_replace('/[^A-Za-z0-9._-]/', '-', $filename);
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            $this->json(['error' => 'No se pudo guardar la nota de voz'], 500);
            return;
        }

        $mimeType = mime_content_type($target) ?: ($file['type'] ?? 'application/octet-stream');
        $transcript = $this->ai->transcribeAudio($target) ?: 'Transcripción no disponible en este runtime; audio guardado para revisión manual.';
        $status = str_starts_with($transcript, 'Transcripción no disponible') ? 'failed' : 'transcribed';

        $stmt = $this->db->prepare(
            "INSERT INTO voice_notes (empresa_id, lead_id, recruiter_id, file_path, mime_type, transcript, status, transcribed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $empresaId = (int) $lead['empresa_id'];
        $recruiterId = isset($lead['assigned_recruiter_id']) ? (int) $lead['assigned_recruiter_id'] : null;
        $transcribedAt = $status === 'transcribed' ? date('Y-m-d H:i:s') : null;
        $stmt->bind_param('iiisssss', $empresaId, $leadId, $recruiterId, $target, $mimeType, $transcript, $status, $transcribedAt);
        $stmt->execute();
        $voiceId = (int) $stmt->insert_id;
        $stmt->close();

        $user = AuthMiddleware::currentUser();
        $this->audit->log((int) ($user['id'] ?? 0), $empresaId, 'voice.upload', 'voice_note', $voiceId, ['lead_id' => $leadId, 'status' => $status]);
        $this->json(['ok' => true, 'voice_note_id' => $voiceId, 'transcript' => $transcript, 'status' => $status]);
    }

    private function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
