# HDreams Bot — Multi-Canal con IA

## Stack

| Capa | Tecnología |
|---|---|
| Backend | PHP 8.2 + MySQL 8 + Redis |
| Frontend | React 18 + Vite + TailwindCSS + Framer Motion |
| IA | GPT-4o (Lead Scoring Predictivo) |
| Canales | WhatsApp Cloud API, Messenger, Instagram, Facebook Lead Ads |
| Infra | Docker Compose, Nginx, Supervisor |

## Instalación local (Docker)

```bash
cp backend/.env.example backend/.env
# Editar backend/.env con tus keys de OpenAI y Meta
docker-compose up -d
```

Accesos:
- Frontend: http://localhost:5173
- Backend API: http://localhost:8000/api/kpis?empresa_id=1

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
```

## Variables de entorno

| Variable | Descripción |
|---|---|
| `DB_HOST / DB_NAME / DB_USER / DB_PASS` | Base de datos MySQL |
| `OPENAI_API_KEY` | GPT-4o lead scoring |
| `META_VERIFY_TOKEN` | Token verificación webhooks Meta |
| `WA_PHONE_ID` + `WA_TOKEN` | WhatsApp Cloud API |
| `FB_PAGE_TOKEN` | Token de página Facebook |
| `RECRUITER_PHONE` | Teléfono reclutador (alertas urgentes) |
| `VITE_API_URL` | URL del backend para el frontend |

## Webhooks Meta

| URL | Canal |
|---|---|
| `/webhook-whatsapp.php` | WhatsApp Cloud API |
| `/webhook-messenger.php` | Messenger + Instagram |
| `/webhook-lead-ads.php` | Facebook Lead Ads |

## API Endpoints

```
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
POST /api/leads/{id}/score
PATCH /api/leads/{id}/estado   body: {"estado":"calificado"}
```

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
