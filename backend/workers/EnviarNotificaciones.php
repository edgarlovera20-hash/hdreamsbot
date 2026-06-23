<?php
/**
 * Worker: Enviar alertas cuando un lead supera el score de escalación.
 * Corre cada 5 minutos (configura en docker/crontab).
 * Usa PHP mail() o SMTP si SMTP_HOST está configurado.
 */
require __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
$mysqli->set_charset('utf8mb4');

// ── Leer umbral de escalación de bot_config ────────────────────
$cfgRow = $mysqli->query(
    "SELECT valor FROM bot_config WHERE clave='escalacion_score' AND empresa_id=1 LIMIT 1"
)->fetch_assoc();
$umbral = (int) ($cfgRow['valor'] ?? 80);

// ── Teléfono / email del reclutador ───────────────────────────
$telRow = $mysqli->query(
    "SELECT valor FROM bot_config WHERE clave='telefono_reclutador' AND empresa_id=1 LIMIT 1"
)->fetch_assoc();
$telefonoReclutador = $telRow['valor'] ?? ($_ENV['RECRUITER_PHONE'] ?? '');
$emailReclutador    = $_ENV['RECRUITER_EMAIL'] ?? '';

// ── Leads urgentes sin notificar aún ──────────────────────────
$result = $mysqli->query(
    "SELECT l.id, l.nombre, l.telefono, l.email, l.canal, l.canal_user_id,
            l.score_ia_candidato, l.score_ia_contratacion, l.estado,
            lsi.razonamiento
     FROM leads l
     LEFT JOIN lead_scoring_ia lsi ON lsi.lead_id = l.id
     WHERE l.empresa_id = 1
       AND l.prioridad = 'urgente'
       AND l.score_ia_candidato >= $umbral
       AND l.estado NOT IN ('contratado','rechazado','no_interesado')
       AND (l.notificacion_enviada IS NULL OR l.notificacion_enviada = 0)
     ORDER BY l.score_ia_candidato DESC
     LIMIT 20"
);

$enviados = 0;

while ($lead = $result->fetch_assoc()) {
    $id         = (int) $lead['id'];
    $nombre     = $lead['nombre'];
    $score      = (int) $lead['score_ia_candidato'];
    $razon      = $lead['razonamiento'] ?? 'Score alto detectado por IA';
    $canal      = $lead['canal'];
    $canal_user = $lead['canal_user_id'];
    $tel        = $lead['telefono'] ?? $canal_user;

    $asunto  = "🚨 Candidato urgente: $nombre (score $score)";
    $cuerpo  = "HDreams Bot — Alerta de candidato prioritario\n\n"
             . "Nombre   : $nombre\n"
             . "Teléfono : $tel\n"
             . "Canal    : $canal ($canal_user)\n"
             . "Score IA : $score / 100\n"
             . "Estado   : {$lead['estado']}\n\n"
             . "Razón IA :\n$razon\n\n"
             . "Ver en el dashboard: https://bot.heavenlydreams.com.mx/leads\n";

    $notificado = false;

    // ── Email ──────────────────────────────────────────────────
    if ($emailReclutador) {
        $ok = mail(
            $emailReclutador,
            $asunto,
            $cuerpo,
            "From: bot@heavenlydreams.com.mx\r\nContent-Type: text/plain; charset=utf-8"
        );
        if ($ok) $notificado = true;
    }

    // ── WhatsApp al reclutador via Baileys ─────────────────────
    if ($telefonoReclutador) {
        $baileysUrl = $_ENV['BAILEYS_URL'] ?? 'http://baileys:4000';
        $ch = curl_init("$baileysUrl/send");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Baileys-Secret: ' . ($_ENV['BAILEYS_SECRET'] ?? ''),
            ],
            CURLOPT_POSTFIELDS     => json_encode([
                'phone' => $telefonoReclutador,
                'text'  => "🚨 *Candidato urgente*: $nombre\nScore: *$score*/100\nTeléfono: $tel\nCanal: $canal\n\n$razon",
            ]),
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        $r = json_decode($raw ?: '{}', true);
        if ($r['ok'] ?? false) $notificado = true;
    }

    // ── Marcar como notificado ─────────────────────────────────
    if ($notificado) {
        // Ensure column exists (safe to run repeatedly)
        $mysqli->query(
            "ALTER TABLE leads ADD COLUMN IF NOT EXISTS notificacion_enviada TINYINT(1) DEFAULT 0"
        );
        $mysqli->query("UPDATE leads SET notificacion_enviada=1 WHERE id=$id");
        $enviados++;
        echo "[OK] Notificado: $nombre (lead #$id, score $score)\n";
    } else {
        echo "[WARN] No se pudo notificar: $nombre (lead #$id)\n";
    }
}

echo "[DONE] $enviados notificaciones enviadas. Umbral: $umbral\n";
