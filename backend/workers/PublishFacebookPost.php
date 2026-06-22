#!/usr/bin/php
<?php
require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Evitar ejecuciones concurrentes
$lockFile = sys_get_temp_dir() . '/hdreams-publish-posts.lock';
$lock = fopen($lockFile, 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    echo "[" . date('Y-m-d H:i:s') . "] Ya hay una instancia corriendo, saliendo.\n";
    exit(0);
}

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
$mysqli->set_charset('utf8mb4');

echo "[" . date('Y-m-d H:i:s') . "] 📢 Publicando posts programados...\n";

$posts = $mysqli->query(
    "SELECT p.*, s.fb_page_id, s.fb_page_token
     FROM facebook_posts p
     JOIN secciones s ON p.seccion_id = s.id
     WHERE p.estado = 'programado'
       AND p.programado_para <= NOW()
     LIMIT 10"
);

$count = 0;

while ($p = $posts->fetch_assoc()) {
    $token    = $p['fb_page_token'];
    $page_id  = $p['fb_page_id'];
    $post_id  = $p['id'];

    if (!$token || !$page_id) {
        echo "⚠️  Post $post_id sin token/page_id, saltando\n";
        $mysqli->query("UPDATE facebook_posts SET estado='error',error_msg='Missing token or page_id' WHERE id=$post_id");
        continue;
    }

    // Endpoint: foto si hay imagen, feed si no
    if ($p['imagen_url']) {
        $endpoint = "https://graph.facebook.com/v25.0/{$page_id}/photos";
        $payload  = [
            'url'          => $p['imagen_url'],
            'message'      => $p['mensaje'],
            'access_token' => $token,
        ];
    } else {
        $endpoint = "https://graph.facebook.com/v25.0/{$page_id}/feed";
        $payload  = [
            'message'      => $p['mensaje'],
            'access_token' => $token,
        ];
        if ($p['link_url']) $payload['link'] = $p['link_url'];
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST          => true,
        CURLOPT_POSTFIELDS    => $payload,
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (isset($res['id']) || isset($res['post_id'])) {
        $fb_id = $res['id'] ?? $res['post_id'];
        $fb_esc = $mysqli->real_escape_string($fb_id);
        $mysqli->query(
            "UPDATE facebook_posts
             SET fb_post_id='$fb_esc', estado='publicado', publicado_en=NOW()
             WHERE id=$post_id"
        );
        echo "✅ Post $post_id publicado → FB ID: $fb_id\n";
        $count++;
    } else {
        $msg = $mysqli->real_escape_string($res['error']['message'] ?? json_encode($res));
        $mysqli->query("UPDATE facebook_posts SET estado='error',error_msg='$msg' WHERE id=$post_id");
        echo "❌ Error post $post_id: {$res['error']['message']}\n";
    }

    sleep(5); // respetar rate limit
}

echo "[" . date('Y-m-d H:i:s') . "] ✅ Completado — $count posts publicados\n";
