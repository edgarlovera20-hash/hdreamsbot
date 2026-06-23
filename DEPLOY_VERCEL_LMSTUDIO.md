# Deploy Plan - Vercel + Backend VM + LM Studio

## Objetivo

- `Vercel` para el frontend
- `Backend VM` para PHP, webhooks, inbox, agenda y workers
- `LM Studio / llmster` como motor IA principal

## Dominios sugeridos

- `app.tudominio.com` -> Vercel
- `api.tudominio.com` -> VM backend
- `ai.private` o IP privada -> LM Studio / llmster

## Variables backend

Ejemplo:

```env
APP_ENV=production
DB_HOST=mysql
DB_NAME=hdreams
DB_USER=hdreams
DB_PASS=hdreams_pass
DB_ROOT_PASS=root_pass_seguro

API_SECRET=super-secret
FRONTEND_URL=https://app.tudominio.com

AI_PROVIDER=lmstudio
AI_BASE_URL=http://AI_HOST:1234/v1
AI_API_KEY=lm-studio
AI_MODEL=qwen/qwen3-4b-instruct
AI_TRANSCRIPTION_MODEL=whisper-1
AI_TEMPERATURE=0.3
AI_TIMEOUT_MS=20000
```

## Variables frontend en Vercel

```env
VITE_API_URL=https://api.tudominio.com/api
VITE_API_SECRET=super-secret
```

## Seguridad operativa Fase 4

- la app ya soporta sesión por usuario vía `X-Session-Token`
- el `API_SECRET` global se mantiene para compatibilidad operativa
- en producción cambia inmediatamente las credenciales seeded de [2026_06_19_auth_exec.sql](/C:/Users/Edgar%20Lovera/OneDrive/Desktop/Nueva%20carpeta/(2)%20Meta%20AI_files%20-%20copia/hdreams-bot/backend/database/migrations/2026_06_19_auth_exec.sql)

## Automatización Fase 6

- aplica también [2026_06_19_phase6_automation.sql](/C:/Users/Edgar%20Lovera/OneDrive/Desktop/Nueva%20carpeta/(2)%20Meta%20AI_files%20-%20copia/hdreams-bot/backend/database/migrations/2026_06_19_phase6_automation.sql)
- el worker [WorkflowAutomationWorker.php](/C:/Users/Edgar%20Lovera/OneDrive/Desktop/Nueva%20carpeta/(2)%20Meta%20AI_files%20-%20copia/hdreams-bot/backend/workers/WorkflowAutomationWorker.php) envía:
  - recordatorios 24h
  - recordatorios 2h
  - recuperación de no-show
  - playbooks automáticos por vacante

## Copiloto IA Fase 7

- aplica también [2026_06_19_phase7_ai_ops.sql](/C:/Users/Edgar%20Lovera/OneDrive/Desktop/Nueva%20carpeta/(2)%20Meta%20AI_files%20-%20copia/hdreams-bot/backend/database/migrations/2026_06_19_phase7_ai_ops.sql)
- el servicio [RecruitmentCopilotService.php](/C:/Users/Edgar%20Lovera/OneDrive/Desktop/Nueva%20carpeta/(2)%20Meta%20AI_files%20-%20copia/hdreams-bot/backend/app/Services/RecruitmentCopilotService.php) usa tu endpoint LM Studio para:
  - sugerir respuesta al recruiter
  - resumir la conversación
  - estimar temperatura del candidato
  - recomendar recruiter
- el worker [AutoAssignWorker.php](/C:/Users/Edgar%20Lovera/OneDrive/Desktop/Nueva%20carpeta/(2)%20Meta%20AI_files%20-%20copia/hdreams-bot/backend/workers/AutoAssignWorker.php) asigna leads urgentes/altos sin responsable

## Reporting Fase 8

- aplica también [2026_06_19_phase8_reporting.sql](/C:/Users/Edgar%20Lovera/OneDrive/Desktop/Nueva%20carpeta/(2)%20Meta%20AI_files%20-%20copia/hdreams-bot/backend/database/migrations/2026_06_19_phase8_reporting.sql)
- configura `REPORT_PYTHON_BIN` si el binario de Python en tu VM no es `python`
- el endpoint `GET /api/operations/report-pdf?empresa_id=...` genera el PDF usando [generate_executive_pdf.py](/C:/Users/Edgar%20Lovera/OneDrive/Desktop/Nueva%20carpeta/(2)%20Meta%20AI_files%20-%20copia/hdreams-bot/backend/scripts/generate_executive_pdf.py)

## Supervisor + Voz + RAG Fase 9

- aplica también [2026_06_19_phase9_voice_rag.sql](/C:/Users/Edgar%20Lovera/OneDrive/Desktop/Nueva%20carpeta/(2)%20Meta%20AI_files%20-%20copia/hdreams-bot/backend/database/migrations/2026_06_19_phase9_voice_rag.sql)
- endpoints nuevos:
  - `GET /api/operations/supervisor?empresa_id=...`
  - `POST /api/leads/{id}/voice-note`
  - `GET /api/knowledge/documents?empresa_id=...`
  - `POST /api/knowledge/documents`
  - `POST /api/knowledge/ask`
  - `GET /api/playbooks?empresa_id=...`
  - `POST /api/playbooks`
- monta o preserva estos directorios en la VM:
  - `backend/storage/voice-notes`
  - `backend/storage/knowledge`
- para transcribir audio, tu motor OpenAI-compatible debe exponer `/audio/transcriptions`
- si LM Studio no soporta transcripción en tu versión/modelo, el flujo sigue funcionando con guardado de audio y fallback textual para revisión manual

## Despliegue frontend en Vercel

Proyecto:

- importar el repo completo
- Vercel usará [vercel.json](/C:/Users/Edgar%20Lovera/OneDrive/Desktop/Nueva%20carpeta/(2)%20Meta%20AI_files%20-%20copia/hdreams-bot/vercel.json)
- build command efectivo: `npm run build --prefix frontend`
- output directory efectivo: `frontend/dist`
- dominio: `app.tudominio.com`

## Despliegue backend

Usar:

- [docker-compose.backend.yml](/C:/Users/Edgar Lovera/OneDrive/Desktop/Nueva carpeta/(2) Meta AI_files - copia/hdreams-bot/backend/docker-compose.backend.yml)
- [nginx.api.conf](/C:/Users/Edgar%20Lovera/OneDrive/Desktop/Nueva%20carpeta/(2)%20Meta%20AI_files%20-%20copia/hdreams-bot/backend/nginx.api.conf)

Pasos:

1. copiar `backend/.env.example` a `backend/.env`
2. ajustar valores reales
3. apuntar DNS `api.tudominio.com` a la VM
4. levantar:

```bash
docker compose --env-file backend/.env -f backend/docker-compose.backend.yml up -d --build
```

Checks:

- `https://api.tudominio.com/healthz`
- `https://api.tudominio.com/api/health`

## Despliegue LM Studio

### Recomendado

- Runpod GPU pod
- instalar `llmster`
- cargar el modelo
- arrancar servicio HTTP en `:1234`

### Seguridad

- no exponer LM Studio públicamente
- permitir acceso solo desde backend VM
- usar firewall o red privada

## Integración IA

El backend ya quedó preparado para usar proveedor OpenAI-compatible mediante:

- [ai.php](/C:/Users/Edgar Lovera/OneDrive/Desktop/Nueva carpeta/(2) Meta AI_files - copia/hdreams-bot/backend/config/ai.php)
- [AIClient.php](/C:/Users/Edgar Lovera/OneDrive/Desktop/Nueva carpeta/(2) Meta AI_files - copia/hdreams-bot/backend/app/Services/AIClient.php)

`LeadScorerIA` ya consume esta capa en lugar de estar amarrado a OpenAI.

## Recomendación operativa

### Fase 1

- frontend en Vercel
- backend en VM
- LM Studio en GPU separada

### Fase 2

- Tailscale o red privada entre backend y AI
- observabilidad y logs
- fallback de modelo

### Fase 3

- migrar DB a administrada si crece el tráfico
- colas reales
- tiempo real
