<?php

namespace App\Services;

class LeadScorerIA
{
    private int     $empresa_id;
    private int     $seccion_id;
    private \mysqli $mysqli;
    private RecruitmentFlowService $flow;
    private AIClient $aiClient;

    public function __construct(int $empresa_id, int $seccion_id, \mysqli $mysqli)
    {
        $this->empresa_id = $empresa_id;
        $this->seccion_id = $seccion_id;
        $this->mysqli     = $mysqli;
        $this->flow       = new RecruitmentFlowService();
        $this->aiClient   = new AIClient();
    }

    public function calcularScore(int $lead_id): ?array
    {
        $lead = $this->mysqli
            ->query("SELECT l.*, s.system_prompt, s.nombre AS seccion_nombre, s.slug AS seccion_slug
                     FROM leads l JOIN secciones s ON l.seccion_id=s.id WHERE l.id=$lead_id")
            ->fetch_assoc();

        if (!$lead) return null;

        $historico = $this->obtenerDatosHistoricos();
        $prompt    = $this->construirPrompt($lead, $historico);
        $resultado = $this->llamarModelo($prompt);

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
        $vacancy  = $this->flow->getVacancyProfile($lead['seccion_slug'] ?: $lead['seccion_nombre'] ?: null);
        $vacancyRequirements = $vacancy ? implode(', ', $vacancy['requirements'] ?? []) : 'No especificados';
        $vacancyActivities   = $vacancy ? implode(', ', $vacancy['activities'] ?? []) : 'No especificadas';
        $vacancyAgeMin       = $vacancy['age_min'] ?? 17;
        $vacancyAgeMax       = $vacancy['age_max'] ?? 40;
        $vacancyName         = $vacancy['name'] ?? ($lead['seccion_nombre'] ?? 'General');
        $ciudad              = $meta['ciudad'] ?? 'No especificada';
        $experiencia         = $meta['experiencia'] ?? 'No especificada';

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
- Ciudad: $ciudad
- Experiencia declarada: $experiencia

HISTÓRICO EMPRESA: Edad promedio contratados = $edadProm años
VACANTE OBJETIVO: $vacancyName
RANGO DE EDAD ESPERADO: $vacancyAgeMin a $vacancyAgeMax
REQUISITOS CLAVE: $vacancyRequirements
ACTIVIDADES CLAVE: $vacancyActivities

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

    private function llamarModelo(string $prompt): array
    {
        $fallback = [
            'score_candidato'    => 0,
            'score_contratacion' => 0,
            'score_prioridad'    => 0,
            'factores_positivos' => [],
            'factores_negativos' => ['api_no_disponible'],
            'razonamiento'       => 'Scoring pendiente — API no disponible.',
        ];

        $r = $this->aiClient->chatJson($prompt);
        if (!$r) {
            return $fallback;
        }

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
