<?php
return [
    'name'     => 'HDreams Bot',
    'version'  => '1.0.0',
    'env'      => $_ENV['APP_ENV'] ?? 'production',
    'debug'    => ($_ENV['APP_ENV'] ?? 'production') === 'local',

    'db' => [
        'host'    => $_ENV['DB_HOST'] ?? 'mysql',
        'name'    => $_ENV['DB_NAME'] ?? 'hdreams',
        'user'    => $_ENV['DB_USER'] ?? '',
        'pass'    => $_ENV['DB_PASS'] ?? '',
        'charset' => 'utf8mb4',
    ],

    'openai' => [
        'key'         => $_ENV['OPENAI_API_KEY'] ?? '',
        'model'       => 'gpt-4o',
        'temperature' => 0.3,
        'timeout'     => 20,
    ],

    'ai' => require __DIR__ . '/ai.php',

    'meta' => [
        'verify_token' => $_ENV['META_VERIFY_TOKEN'] ?? '',
        'app_secret'   => $_ENV['META_APP_SECRET']   ?? '',
        'graph_url'    => 'https://graph.facebook.com/v25.0',
    ],

    'reporting' => [
        'python_bin' => $_ENV['REPORT_PYTHON_BIN'] ?? 'python',
        'output_dir' => __DIR__ . '/../output/pdf',
        'tmp_dir' => __DIR__ . '/../tmp/pdfs',
    ],

    'uploads' => [
        'voice_dir' => __DIR__ . '/../storage/voice-notes',
        'knowledge_dir' => __DIR__ . '/../storage/knowledge',
    ],

    'whatsapp' => [
        'phone_id' => $_ENV['WA_PHONE_ID'] ?? '',
        'token'    => $_ENV['WA_TOKEN']    ?? '',
    ],

    'scoring' => [
        'urgente_min' => 80,
        'alta_min'    => 65,
        'media_min'   => 40,
        'sla_horas'   => ['urgente' => 2, 'alta' => 6, 'media' => 24, 'baja' => 72],
    ],
];
