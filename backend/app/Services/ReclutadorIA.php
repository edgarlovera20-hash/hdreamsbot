<?php

namespace App\Services;

class ReclutadorIA
{
    private \mysqli $mysqli;
    private int     $empresa_id;
    private int     $seccion_id;

    // ponytail: default prompt when agentes_ia has no active responder
    private const PROMPT_DEFAULT = <<<PROMPT
Eres {{nombre_bot}}, reclutadora senior de {{empresa_nombre}}.
Tu misión: entrevistar y calificar candidatos para nuestras vacantes de forma conversacional.

VACANTES DISPONIBLES:
- Promotor de ventas (CDMX, Monterrey, Guadalajara) — sueldo base + comisiones atractivas
- Asesor comercial (Nacional) — excelentes comisiones
- Ejecutivo de atención al cliente (CDMX) — prestaciones completas

DATOS A RECOPILAR (uno por mensaje, con naturalidad):
1. Nombre completo (si no lo tienes)
2. Edad — requisito: 18 a 35 años
3. Ciudad de residencia
4. Experiencia en ventas o atención al cliente
5. Disponibilidad de horario (completo / medio tiempo)
6. Teléfono de contacto (si no está registrado)

INSTRUCCIONES IMPORTANTES:
- Mensajes CORTOS: máx 2-3 líneas. Nunca párrafos largos.
- Tono cálido, profesional y motivador.
- Si el candidato no cumple requisito de edad, declinar amablemente.
- Si ya tienes un dato del candidato, NO lo vuelvas a pedir.
- No inventes información que no esté en este prompt.
- Responde SIEMPRE en español.
- Cuando hayas recopilado todos los datos, indica que su perfil fue registrado y que un reclutador le contactará pronto.
PROMPT;

    public function __construct(int $empresa_id, int $seccion_id, \mysqli $mysqli)
    {
        $this->empresa_id = $empresa_id;
        $this->seccion_id = $seccion_id;
        $this->mysqli     = $mysqli;
    }

    public function responder(int $lead_id, string $mensaje, int $log_id = 0): string
    {
        $fallback = "Gracias por tu mensaje. Un momento, te atendemos pronto.";

        [$agente, $modelo, $base_url, $api_key] = $this->resolverAgente();

        $lead      = $this->getLead($lead_id);
        $config    = $this->getBotConfig();
        $historial = $this->getHistorial($lead['canal_user_id'] ?? '', 12);
        $system    = $this->buildSystemPrompt($agente, $lead, $config);
        $messages  = array_merge($historial, [['role' => 'user', 'content' => $mensaje]]);

        $respuesta = $this->llamarLLM($system, $messages, $modelo, $base_url, $api_key) ?? $fallback;

        // Guardar respuesta en ia_logs para historial de conversación
        if ($log_id > 0) {
            $r_esc = $this->mysqli->real_escape_string($respuesta);
            $this->mysqli->query("UPDATE ia_logs SET respuesta='$r_esc' WHERE id=$log_id");
        }

        return $respuesta;
    }

    // ── Privados ──────────────────────────────────────────────────

    private function resolverAgente(): array
    {
        $eid = $this->empresa_id;
        $sid = $this->seccion_id;

        $row = $this->mysqli->query(
            "SELECT * FROM agentes_ia
             WHERE empresa_id=$eid AND seccion_id=$sid AND tipo='responder' AND activo=1
             ORDER BY id DESC LIMIT 1"
        );
        $agente = $row ? $row->fetch_assoc() : null;
        $modelo = $agente['modelo'] ?? ($_ENV['LLM_MODEL'] ?? 'llama3.2:3b');

        // LLM endpoint: bot_config (UI) takes precedence over .env
        $cfgRow = $this->mysqli->query(
            "SELECT clave, valor FROM bot_config
             WHERE empresa_id=$eid AND seccion_id=$sid AND clave IN ('llm_base_url','llm_api_key')"
        );
        $llmCfg = [];
        while ($r = $cfgRow ? $cfgRow->fetch_assoc() : null) {
            if (!$r) break;
            $llmCfg[$r['clave']] = $r['valor'];
        }

        $base_url = rtrim($llmCfg['llm_base_url'] ?? $_ENV['LLM_BASE_URL'] ?? 'http://localhost:11434/v1', '/');
        $api_key  = $llmCfg['llm_api_key']  ?? $_ENV['OPENAI_API_KEY'] ?? '';

        // Local endpoints don't need auth
        if (str_contains($base_url, 'localhost') || str_contains($base_url, '127.0.0.1')) {
            $api_key = '';
        }

        return [$agente, $modelo, $base_url, $api_key];
    }

    private function getLead(int $lead_id): array
    {
        $r = $this->mysqli->query("SELECT * FROM leads WHERE id=$lead_id");
        return $r ? ($r->fetch_assoc() ?? []) : [];
    }

    private function getBotConfig(): array
    {
        $eid = $this->empresa_id;
        $sid = $this->seccion_id;
        $cfg = [];

        $r = $this->mysqli->query(
            "SELECT clave, valor FROM bot_config WHERE empresa_id=$eid AND seccion_id=$sid"
        );
        while ($row = $r ? $r->fetch_assoc() : null) {
            if (!$row) break;
            $cfg[$row['clave']] = $row['valor'];
        }

        return array_merge([
            'nombre_bot'     => 'Lic. Gissell',
            'empresa_nombre' => 'Heavenly Dreams',
        ], $cfg);
    }

    private function getHistorial(string $wa_id, int $limit = 12): array
    {
        if (!$wa_id) return [];

        $eid   = $this->empresa_id;
        $w_esc = $this->mysqli->real_escape_string($wa_id);

        $r = $this->mysqli->query(
            "SELECT pregunta, respuesta FROM ia_logs
             WHERE wa_id='$w_esc' AND empresa_id=$eid
             ORDER BY created_at DESC LIMIT $limit"
        );

        $rows = [];
        while ($row = $r ? $r->fetch_assoc() : null) {
            if (!$row) break;
            $rows[] = $row;
        }

        // Cronológico ascendente → formato messages
        $historial = [];
        foreach (array_reverse($rows) as $m) {
            if (!empty($m['pregunta'])) {
                $historial[] = ['role' => 'user',      'content' => $m['pregunta']];
            }
            if (!empty($m['respuesta'])) {
                $historial[] = ['role' => 'assistant', 'content' => $m['respuesta']];
            }
        }

        return $historial;
    }

    private function buildSystemPrompt(?array $agente, array $lead, array $config): string
    {
        // Usar prompt del agente configurado en DB, o el default
        $base = (!empty($agente['prompt_sistema']))
            ? $agente['prompt_sistema']
            : self::PROMPT_DEFAULT;

        // Reemplazar placeholders del prompt
        $base = str_replace(
            ['{{nombre_bot}}', '{{empresa_nombre}}'],
            [$config['nombre_bot'] ?? 'Lic. Gissell', $config['empresa_nombre'] ?? 'Heavenly Dreams'],
            $base
        );

        // Inyectar contexto del lead para no re-preguntar datos conocidos
        $ctx  = "\n\n--- CONTEXTO DEL CANDIDATO (datos ya registrados) ---";
        $ctx .= "\nNombre: " . ($lead['nombre'] ?? 'Desconocido');

        if (!empty($lead['edad']))     $ctx .= "\nEdad: {$lead['edad']} años [NO preguntar de nuevo]";
        if (!empty($lead['telefono'])) $ctx .= "\nTeléfono: {$lead['telefono']} [NO preguntar de nuevo]";
        if (!empty($lead['email']))    $ctx .= "\nEmail: {$lead['email']} [NO preguntar de nuevo]";

        $meta = json_decode($lead['metadata'] ?? '{}', true) ?: [];
        if (!empty($meta['ciudad']))        $ctx .= "\nCiudad: {$meta['ciudad']} [NO preguntar de nuevo]";
        if (!empty($meta['experiencia']))   $ctx .= "\nExperiencia: {$meta['experiencia']} [NO preguntar de nuevo]";
        if (!empty($meta['disponibilidad'])) $ctx .= "\nDisponibilidad: {$meta['disponibilidad']} [NO preguntar de nuevo]";

        $ctx .= "\nMensajes recibidos: " . ($lead['mensajes_recibidos'] ?? 0);

        return $base . $ctx;
    }

    private function llamarLLM(
        string $system,
        array  $messages,
        string $modelo,
        string $base_url,
        string $api_key
    ): ?string {
        $headers = ['Content-Type: application/json'];
        if ($api_key) {
            $headers[] = "Authorization: Bearer $api_key";
        }

        $payload = [
            'model'       => $modelo,
            'messages'    => array_merge(
                [['role' => 'system', 'content' => $system]],
                $messages
            ),
            'temperature' => 0.7,
            'max_tokens'  => 200,  // mensajes cortos para WhatsApp
        ];

        $ch = curl_init("$base_url/chat/completions");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => json_encode($payload),
        ]);

        $raw     = curl_exec($ch);
        $curlErr = curl_error($ch);
        $status  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErr || !$raw) {
            error_log("[ReclutadorIA] curl error ($modelo @ $base_url): $curlErr");
            return null;
        }

        $r = json_decode($raw, true);

        if ($status !== 200 || !isset($r['choices'][0]['message']['content'])) {
            $err = $r['error']['message'] ?? "HTTP $status";
            error_log("[ReclutadorIA] LLM error ($modelo @ $base_url): $err");
            return null;
        }

        return trim($r['choices'][0]['message']['content']);
    }
}
