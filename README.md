# HDreams Bot — Multi-Canal con IA

## Stack

| Capa | Tecnología |
|---|---|
| Backend | PHP 8.2 + MySQL 8 + Redis |
| Frontend | React 18 + Vite + TailwindCSS + Framer Motion |
| IA | LM Studio / endpoint OpenAI-compatible |
| Canales | WhatsApp Cloud API, Messenger, Instagram, Facebook Lead Ads |
| Infra | Docker Compose, Nginx, Supervisor |

## Instalación local (Docker)

```bash
cp .env.example .env
cp backend/.env.example backend/.env
# Usa el mismo secret en .env -> VITE_API_SECRET y backend/.env -> API_SECRET
# Editar backend/.env con tus credenciales de Meta y tu endpoint IA
npm --prefix frontend install
docker compose up -d --build
```

Accesos:
- App + frontend: http://localhost
- Backend API: http://localhost/api/kpis?empresa_id=1

## Instalación manual (VPS)

```bash
# Dependencias
apt update && apt install -y nginx php8.2-fpm php8.2-mysql php8.2-curl mysql-server redis supervisor

# Clonar y configurar
git clone https://github.com/tu-usuario/hdreams-bot.git /var/www/hdreams-bot
cd /var/www/hdreams-bot/backend
composer install --no-dev --optimize-autoloader
cp .env.example .env   # editar con tus keys
mysql -u root -p < database/migrations/hdreams.sql

# Nginx
cp nginx.conf /etc/nginx/sites-available/hdreams
ln -s /etc/nginx/sites-available/hdreams /etc/nginx/sites-enabled/
systemctl restart nginx php8.2-fpm

# Frontend
cd /var/www/hdreams-bot/frontend
npm install && npm run build
# Servir dist/ con Nginx o Vercel/Netlify
```

## Cron jobs

```cron
# Recalcular scores IA cada 6 horas
0 */6 * * * /usr/bin/php /var/www/hdreams-bot/backend/workers/RecalculateScores.php >> /var/log/hdreams-scoring.log 2>&1

# Procesar Lead Ads cada minuto
* * * * * /usr/bin/php /var/www/hdreams-bot/backend/workers/ProcessLeadAds.php >> /var/log/hdreams-leads.log 2>&1

# Publicar posts de Facebook cada 5 minutos
*/5 * * * * /usr/bin/php /var/www/hdreams-bot/backend/workers/PublishFacebookPost.php >> /var/log/hdreams-posts.log 2>&1

# Alertas SLA cada 10 minutos
*/10 * * * * /usr/bin/php /var/www/hdreams-bot/backend/workers/SlaAlertWorker.php >> /var/log/hdreams-sla-alerts.log 2>&1

# Automatizaciones de entrevistas y playbooks cada 5 minutos
*/5 * * * * /usr/bin/php /var/www/hdreams-bot/backend/workers/WorkflowAutomationWorker.php >> /var/log/hdreams-workflow.log 2>&1

# Autoasignación IA cada 10 minutos
*/10 * * * * /usr/bin/php /var/www/hdreams-bot/backend/workers/AutoAssignWorker.php >> /var/log/hdreams-auto-assign.log 2>&1
```

## Variables de entorno

| Variable | Descripción |
|---|---|
| `DB_NAME / DB_USER / DB_PASS / DB_ROOT_PASS` | Variables de MySQL para Docker Compose |
| `DB_HOST / DB_NAME / DB_USER / DB_PASS` | Base de datos MySQL en backend |
| `AI_PROVIDER / AI_BASE_URL / AI_MODEL` | Proveedor IA OpenAI-compatible, recomendado LM Studio |
| `AI_TRANSCRIPTION_MODEL` | Modelo o alias para `/audio/transcriptions` |
| `META_VERIFY_TOKEN` | Token verificación webhooks Meta |
| `WA_PHONE_ID` + `WA_TOKEN` | WhatsApp Cloud API |
| `FB_PAGE_TOKEN` | Token de página Facebook |
| `RECRUITER_PHONE` | Teléfono reclutador (alertas urgentes) |
| `API_SECRET` | Secret bearer esperado por el backend |
| `VITE_API_URL` | URL del backend para el frontend |
| `VITE_API_SECRET` | Bearer enviado por el frontend; debe coincidir con `API_SECRET` |

## Acceso inicial Fase 4

- Usuario operativo: `operaciones@heavenlydreams.mx`
- Usuario admin: `admin@hdreams.local`
- Password inicial: `Cambio123!`

Estas credenciales se insertan desde [2026_06_19_auth_exec.sql](/C:/Users/Edgar%20Lovera/OneDrive/Desktop/Nueva%20carpeta/(2)%20Meta%20AI_files%20-%20copia/hdreams-bot/backend/database/migrations/2026_06_19_auth_exec.sql). Debes cambiarlas al primer despliegue real.

## Notas de arranque

- El frontend compilado por Docker se sirve desde Nginx en `http://localhost`.
- Si trabajas con `frontend` en modo dev (`npm run dev`), entonces usa `FRONTEND_URL=http://localhost:5173` en `backend/.env`.
- Si trabajas con Docker/Nginx, usa `FRONTEND_URL=http://localhost`.
- Para producción, la arquitectura recomendada es `app.tudominio.com` en Vercel y `api.tudominio.com` en una VM con [backend/nginx.api.conf](/C:/Users/Edgar%20Lovera/OneDrive/Desktop/Nueva%20carpeta/(2)%20Meta%20AI_files%20-%20copia/hdreams-bot/backend/nginx.api.conf).
- El frontend ya queda listo para Vercel con [vercel.json](/C:/Users/Edgar%20Lovera/OneDrive/Desktop/Nueva%20carpeta/(2)%20Meta%20AI_files%20-%20copia/hdreams-bot/vercel.json).
- Si no vas a usar Cloudflare, sigue [DEPLOY_VERCEL_VPS_SIN_CLOUDFLARE.md](/C:/Users/Edgar%20Lovera/OneDrive/Desktop/Nueva%20carpeta/(2)%20Meta%20AI_files%20-%20copia/hdreams-bot/DEPLOY_VERCEL_VPS_SIN_CLOUDFLARE.md).

## Webhooks Meta

| URL | Canal |
|---|---|
| `/webhook-whatsapp.php` | WhatsApp Cloud API |
| `/webhook-messenger.php` | Messenger + Instagram |
| `/webhook-lead-ads.php` | Facebook Lead Ads |

## API Endpoints

```
# Multi cuentas / apps centralizadas
POST /api/auth/login
GET  /api/auth/me
POST /api/auth/logout

GET  /api/accounts/panel
GET  /api/accounts/apps
POST /api/accounts/apps
GET  /api/accounts/inbox
GET  /api/accounts/inbox/{leadId}
POST /api/accounts/inbox/{leadId}/reply
POST /api/accounts/inbox/{leadId}/assign
POST /api/accounts/inbox/{leadId}/macro
GET  /api/recruiters

# Healthcheck
GET  /api/health

# Executive / SLA
GET  /api/operations/executive?empresa_id=1
GET  /api/operations/recruiters-sla?empresa_id=1
GET  /api/operations/automations?empresa_id=1
GET  /api/operations/supervisor?empresa_id=1

# Alertas y auditoría
GET  /api/notifications?empresa_id=1
POST /api/notifications/{id}/read
GET  /api/audit-logs?empresa_id=1

# Copiloto IA
GET  /api/leads/{id}/copilot
POST /api/leads/{id}/auto-assign
POST /api/leads/{id}/voice-note

# Base de conocimiento / RAG
GET  /api/knowledge/documents?empresa_id=1
POST /api/knowledge/documents
POST /api/knowledge/ask

# Playbooks de reclutamiento
GET  /api/playbooks?empresa_id=1
POST /api/playbooks

# Reportería ejecutiva
GET  /api/operations/report?empresa_id=1
GET  /api/operations/report-pdf?empresa_id=1

## Multi-sede y PDF Fase 8

- aplica también [2026_06_19_phase8_reporting.sql](/C:/Users/Edgar%20Lovera/OneDrive/Desktop/Nueva%20carpeta/(2)%20Meta%20AI_files%20-%20copia/hdreams-bot/backend/database/migrations/2026_06_19_phase8_reporting.sql)
- el generador PDF está en [generate_executive_pdf.py](/C:/Users/Edgar%20Lovera/OneDrive/Desktop/Nueva%20carpeta/(2)%20Meta%20AI_files%20-%20copia/hdreams-bot/backend/scripts/generate_executive_pdf.py)
- define `REPORT_PYTHON_BIN` en backend si tu servidor usa una ruta distinta a `python`

# KPIs
GET  /api/kpis?empresa_id=1&desde=2026-06-01&hasta=2026-06-18
GET  /api/kpis/cola?empresa_id=1&prioridad=urgente&limite=20
GET  /api/kpis/horas?empresa_id=1&fecha=2026-06-18
GET  /api/kpis/horas-pico?empresa_id=1&seccion_id=1
GET  /api/kpis/ab?empresa_id=1

# Leads
GET  /api/leads?empresa_id=1&estado=nuevo&canal=whatsapp
GET  /api/leads/cola?empresa_id=1&estado_cola=pendiente
GET  /api/leads/{id}
GET  /api/leads/{id}/events
POST /api/leads/{id}/notes
POST /api/leads/{id}/score
PATCH /api/leads/{id}/estado   body: {"estado":"calificado"}

# Agenda / entrevistas
GET  /api/interview-slots?empresa_id=1&date=2026-06-19
GET  /api/interviews?empresa_id=1&date=2026-06-19
POST /api/interviews
PATCH /api/interviews/{id}
POST /api/interviews/{id}/confirm
POST /api/interviews/{id}/no-show
```

## Supervisor / Voz / RAG Fase 9

- aplica también [2026_06_19_phase9_voice_rag.sql](/C:/Users/Edgar%20Lovera/OneDrive/Desktop/Nueva%20carpeta/(2)%20Meta%20AI_files%20-%20copia/hdreams-bot/backend/database/migrations/2026_06_19_phase9_voice_rag.sql)
- el panel `Supervisor` centraliza alertas críticas, conversaciones activas, cola de notas de voz y playbooks
- las notas de voz se guardan en `backend/storage/voice-notes`
- los documentos internos para RAG se guardan en `backend/storage/knowledge`
- la transcripción usa un endpoint OpenAI-compatible `POST /audio/transcriptions`; si tu proveedor no lo soporta, el sistema guarda el audio y deja texto fallback para revisión manual
- el extractor documental usa [extract_document_text.py](/C:/Users/Edgar%20Lovera/OneDrive/Desktop/Nueva%20carpeta/(2)%20Meta%20AI_files%20-%20copia/hdreams-bot/backend/scripts/extract_document_text.py), con soporte práctico para PDF y texto plano

## Inteligencia predictiva Fase 10

- no requiere migración nueva; corre sobre `leads`, `interviews` y `lead_scoring_ia`
- el endpoint `GET /api/operations/executive?empresa_id=...` ahora devuelve `predictive.summary`
- incluye 4 bloques accionables:
  - `no_show_watchlist`
  - `reactivation_queue`
  - `top_candidates`
  - `vacancy_bottlenecks`
- el cálculo actual es heurístico y transparente:
  - riesgo de no-show por confirmación, recordatorios, historial y nivel de interacción
  - reactivación por SLA vencido, horas sin movimiento, prioridad y etapa
  - cierre probable por scores IA y estado de entrevista
  - cuello de botella por vacante según volumen, hires, vencidos, idle y no-show

## Estructura del proyecto

```
hdreams-bot/
├── backend/
│   ├── app/
│   │   ├── Controllers/
│   │   │   ├── KPIsController.php     # /api/kpis endpoints
│   │   │   └── LeadController.php     # /api/leads endpoints
│   │   └── Services/
│   │       ├── LeadScorerIA.php       # GPT-4o scoring + SLA
│   │       └── CanalManager.php       # Envío multi-canal
│   ├── database/migrations/
│   │   └── hdreams.sql                # 13 tablas + seed data
│   ├── public/
│   │   ├── index.php                  # Router principal
│   │   ├── webhook-whatsapp.php
│   │   ├── webhook-messenger.php
│   │   └── webhook-lead-ads.php
│   └── workers/
│       ├── RecalculateScores.php      # Cron cada 6h
│       ├── ProcessLeadAds.php         # Cron cada 1min
│       └── PublishFacebookPost.php    # Cron cada 5min
├── frontend/
│   ├── index.html
│   └── src/
│       ├── main.jsx
│       ├── index.css
│       ├── App.jsx                    # Router + Sidebar
│       ├── pages/Dashboard.jsx
│       ├── lib/api.js
│       └── components/
│           ├── ui/
│           │   ├── Card.jsx
│           │   ├── Metric.jsx         # Contador animado
│           │   └── Badge.jsx          # Prioridad/estado/canal
│           └── dashboard/
│               ├── KPIGrid.jsx
│               ├── HoursChart.jsx
│               └── LeadQueue.jsx
├── docker-compose.yml
├── nginx.conf
└── README.md
```
