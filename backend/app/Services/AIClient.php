<?php

namespace App\Services;

class AIClient
{
    private array $config;

    public function __construct()
    {
        $this->config = require __DIR__ . '/../../config/ai.php';
    }

    public function chatJson(string $prompt): ?array
    {
        $baseUrl = $this->config['base_url'] ?? '';
        $model = $this->config['model'] ?? '';
        $apiKey = $this->config['api_key'] ?? '';
        $timeoutMs = (int) ($this->config['timeout_ms'] ?? 20000);
        $temperature = (float) ($this->config['temperature'] ?? 0.3);

        if (!$baseUrl || !$model) {
            error_log('[AIClient] base_url o model no configurados');
            return null;
        }

        $url = $baseUrl . ($this->config['chat_completions_path'] ?? '/chat/completions');

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . ($apiKey !== '' ? $apiKey : 'lm-studio'),
        ];

        $payload = [
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'temperature' => $temperature,
            'response_format' => ['type' => 'json_object'],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);

        $raw = curl_exec($ch);
        $curlErr = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErr || !$raw) {
            error_log("[AIClient] curl error: $curlErr");
            return null;
        }

        $response = json_decode($raw, true);
        if ($status < 200 || $status >= 300) {
            $apiErr = $response['error']['message'] ?? "HTTP $status";
            error_log("[AIClient] provider error: $apiErr");
            return null;
        }

        $content = $response['choices'][0]['message']['content'] ?? null;
        if (!$content) {
            error_log('[AIClient] respuesta sin content');
            return null;
        }

        return json_decode($content, true);
    }

    public function getRuntimeInfo(): array
    {
        return [
            'provider' => $this->config['provider'] ?? 'lmstudio',
            'base_url' => $this->config['base_url'] ?? '',
            'model' => $this->config['model'] ?? '',
        ];
    }

    public function transcribeAudio(string $filePath): ?string
    {
        $baseUrl = $this->config['base_url'] ?? '';
        $apiKey = $this->config['api_key'] ?? '';
        $model = $this->config['transcription_model'] ?? ($this->config['model'] ?? '');
        $timeoutMs = (int) ($this->config['timeout_ms'] ?? 20000);
        $path = $this->config['transcriptions_path'] ?? '/audio/transcriptions';

        if (!$baseUrl || !$model || !is_file($filePath)) {
            return null;
        }

        $url = $baseUrl . $path;
        $headers = ['Authorization: Bearer ' . ($apiKey !== '' ? $apiKey : 'lm-studio')];
        $payload = [
            'model' => $model,
            'file' => new \CURLFile($filePath),
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $payload,
        ]);

        $raw = curl_exec($ch);
        $curlErr = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErr || !$raw || $status < 200 || $status >= 300) {
            return null;
        }

        $response = json_decode($raw, true);
        return $response['text'] ?? null;
    }
}
