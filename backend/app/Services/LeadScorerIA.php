<?php

namespace App\Services;

class LeadScorerIA
{
    private int     $empresa_id;
    private int     $seccion_id;
    private \mysqli $mysqli;
    private string  $openai_key;

    public function __construct(int $empresa_id, int $seccion_id, \mysqli $mysqli)
    {
        $this->empresa_id = $empresa_id;
        $this->seccion_id = $seccion_id;
        $this->mysqli     = $mysqli;
        $this->openai_key = $_ENV['OPENAI_API_KEY'] ?? '';
    }

    public function calcularScore(int $lead_id): ?array
    {
        $lead = $this->mysqli
            ->query("SELECT l.*, s.system_prompt FROM leads l JOIN secciones s ON l.seccion_id=s.id WHERE l.id=$lead_id")
            ->fetch_assoc();

        if (!$lead) return null;

        $historico = $this->obtenerDatosHistoricos();
        $prompt    = $this->construirPrompt($lead, $historico);
        $resultado = $this->llamarGPT4($prompt);

        $this->guardarScores($lead_id, $resultado);
        $this->asignarColaPrioridad($lead_id, $resultado);

        return $resultado;
    }

    // -------------------------------------------------------
    private function obtenerDatosHistoricos(): array
    {
        $eid = $this->empresa_id;
        $sid = $this->seccion_id;

        $r = @$this->mysqli
            ->query("SELECT AVG(CASE WHEN fue_contratado=1 THEN edad END) AS edad_prom, COUNT(*) AS total
                     FROM lead_historico_ml
                     WHERE empresa_id=$eid AND seccion_id=$sid")
            ?->fetch_assoc();

        return $r ?? ['edad_prom' => 26, 'total' => 0];
    }

    private function construirPrompt(array $lead, array $hist): string
    {
        $edad     = $lead['edad'] ?? 0;
        $canal    = $lead['canal'];
        $tiempo   = $lead['tiempo_respuesta_seg'];
        $msgs     = $lead['mensajes_recibidos'];
        $meta     = json_decode($lead['metadata'] ?? '{}', true);
        $edadProm = round($hist['edad_prom'] ?? 26);

        return <<<PROMPT
Analiza este lead de reclutamiento y asigna scores 0-100:

DATOS LEAD:
- Edad: $edad
- Canal: $canal
- Tiempo respuesta: {$tiempo}s
- Mensajes recibidos: $msgs
- Teléfono registrado: {$this->yesNo(!empty($lead['telefono']))}
- Email registrado: {$this->yesNo(!empty($lead['email']))}
- CV adjunto: {$this->yesNo(isset($meta['cv']))}

HISTÓRICO EMPRESA: Edad promedio contratados = $edadProm años

INSTRUCCIONES:
1. score_candidato (0-100): probabilidad de que cumpla requisitos básicos
2. score_contratacion (0-100): probabilidad de ser contratado
3. score_prioridad = score_candidato*0.4 + score_contratacion*0.6
4. factores_positivos: array con máx 3 razones clave
5. factores_negativos: array con riesgos identificados
6. razonamiento: 1-2 líneas

Responde ÚNICAMENTE JSON válido:
{"score_candidato":85,"score_contratacion":72,"score_prioridad":77,"factores_positivos":["edad_ideal","responde_rapido"],"factores_negativos":[],"razonamiento":"Lead calificado, edad óptima y alta interacción."}
PROMPT;
    }

    private function llamarGPT4(string $prompt): array
    {
        $fallback = [
            'score_candidato'    => 0,
            'score_contratacion' => 0,
            'score_prioridad'    => 0,
            'factores_positivos' => [],
            'factores_negativos' => ['api_no_disponible'],
            'razonamiento'       => 'Scoring pendiente — API no disponible.',
        ];

        if (!$this->openai_key) return $fallback;

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->openai_key,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model'           => 'gpt-4o',
                'messages'        => [['role' => 'user', 'content' => $prompt]],
                'temperature'     => 0.3,
                'response_format' => ['type' => 'json_object'],
            ]),
        ]);

        $raw    = curl_exec($ch);
        $curlErr = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErr || !$raw) {
            error_log("[LeadScorerIA] curl error: $curlErr");
            return $fallback;
        }

        $response = json_decode($raw, true);

        if ($status !== 200 || !isset($response['choices'][0]['message']['content'])) {
            $apiErr = $response['error']['message'] ?? "HTTP $status";
            error_log("[LeadScorerIA] OpenAI error: $apiErr");
            return $fallback;
        }

        $r = json_decode($response['choices'][0]['message']['content'], true) ?? [];

        return [
            'score_candidato'    => (float) ($r['score_candidato']    ?? 0),
            'score_contratacion' => (float) ($r['score_contratacion'] ?? 0),
            'score_prioridad'    => (float) ($r['score_prioridad']    ?? 0),
            'factores_positivos' => $r['factores_positivos'] ?? [],
            'factores_negativos' => $r['factores_negativos'] ?? [],
            'razonamiento'       => $r['razonamiento']       ?? '',
        ];
    }

    private function guardarScores(int $lead_id, array $r): void
    {
        $fp  = $this->mysqli->real_escape_string(json_encode($r['factores_positivos']));
        $fn  = $this->mysqli->real_escape_string(json_encode($r['factores_negativos']));
        $raz = $this->mysqli->real_escape_string($r['razonamiento']);
        $sc  = $r['score_candidato'];
        $sco = $r['score_contratacion'];
        $sp  = $r['score_prioridad'];

        $this->mysqli->query(
            "INSERT INTO lead_scoring_ia
               (lead_id,score_candidato,score_contratacion,score_prioridad,factores_positivos,factores_negativos,razonamiento)
             VALUES ($lead_id,$sc,$sco,$sp,'$fp','$fn','$raz')
             ON DUPLICATE KEY UPDATE
               score_candidato=$sc,score_contratacion=$sco,score_prioridad=$sp,
               factores_positivos='$fp',factores_negativos='$fn',razonamiento='$raz',calculado_en=NOW()"
        );

        $prioridad = $this->calcularPrioridad($sp);
        $this->mysqli->query(
            "UPDATE leads SET score_ia_candidato=$sc,score_ia_contratacion=$sco,
             prioridad='$prioridad',ultimo_scoring=NOW() WHERE id=$lead_id"
        );
    }

    private function asignarColaPrioridad(int $lead_id, array $r): void
    {
        $prioridad = $this->calcularPrioridad($r['score_prioridad']);
        $sla       = ['urgente' => 2, 'alta' => 6, 'media' => 24, 'baja' => 72][$prioridad];
        $sp        = $r['score_prioridad'];

        $this->mysqli->query(
            "INSERT INTO lead_cola_prioridad (lead_id,prioridad,score_prioridad,sla_horas,vence_en)
             VALUES ($lead_id,'$prioridad',$sp,$sla,DATE_ADD(NOW(),INTERVAL $sla HOUR))
             ON DUPLICATE KEY UPDATE prioridad='$prioridad',score_prioridad=$sp,
             sla_horas=$sla,vence_en=DATE_ADD(NOW(),INTERVAL $sla HOUR)"
        );
    }

    private function calcularPrioridad(float $score): string
    {
        if ($score >= 80) return 'urgente';
        if ($score >= 65) return 'alta';
        if ($score >= 40) return 'media';
        return 'baja';
    }

    private function yesNo(bool $v): string
    {
        return $v ? 'Sí' : 'No';
    }
}
