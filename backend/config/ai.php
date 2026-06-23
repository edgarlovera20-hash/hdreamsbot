<?php

return [
    'provider' => $_ENV['AI_PROVIDER'] ?? 'lmstudio',
    'base_url' => rtrim($_ENV['AI_BASE_URL'] ?? 'http://127.0.0.1:1234/v1', '/'),
    'api_key' => $_ENV['AI_API_KEY'] ?? 'lm-studio',
    'model' => $_ENV['AI_MODEL'] ?? 'qwen/qwen3-4b-instruct',
    'temperature' => (float) ($_ENV['AI_TEMPERATURE'] ?? 0.3),
    'timeout_ms' => (int) ($_ENV['AI_TIMEOUT_MS'] ?? 20000),
    'chat_completions_path' => '/chat/completions',
    'transcriptions_path' => '/audio/transcriptions',
    'transcription_model' => $_ENV['AI_TRANSCRIPTION_MODEL'] ?? ($_ENV['AI_MODEL'] ?? 'qwen/qwen3-4b-instruct'),
];
