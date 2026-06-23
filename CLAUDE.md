# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Deploy

There is no local dev server for the full stack. The only deployment target is the VPS at `root@64.227.25.74`.

```bash
# From the repo root (hdreams-bot/hdreams-bot/):
bash deploy-from-local.sh
```

This tars the source (excluding node_modules, vendor, dist), SSHs it to `/srv/hdreams-bot`, rebuilds Docker images, and runs `docker compose up -d --build`. The script must be run from inside `hdreams-bot/hdreams-bot/`, not from the parent directory.

### Frontend only (build check without deploying)

```bash
cd frontend
npm install
npm run build   # vite build — validates JSX/imports
```

### PHP dependency check

```bash
cd backend
composer install --no-dev
```

There are no test suites. Validation is done by deploying and checking `docker compose ps`.

## Architecture

Five Docker services communicate on an internal bridge network:

```
internet → nginx (80/443)
              ├── /api/*         → php:9000 (PHP-FPM)
              ├── /webhook-*.php → php:9000
              ├── /baileys/*     → baileys:4000 (Node.js)
              └── /auth/*        → baileys:4000
           mysql:3306            ← php, worker, baileys
           redis:6379            ← (available, not yet used in PHP code)
           worker                ← runs cron jobs via dcron
```

**nginx** builds the React frontend (`Dockerfile.nginx`) and serves it as static files. It also reverse-proxies PHP and Baileys. In prod, `nginx.prod.conf` + `docker-compose.prod.yml` override the base compose.

**baileys** (`baileys/server.js`) is a Node.js/Express service that maintains a persistent WhatsApp Web connection via `@whiskeysockets/baileys`. It exposes:
- `GET /status` — returns `{ status, qr }` (status: disconnected/qr/connected, qr: base64 PNG)
- `POST /send` — `{ phone, text }` sends a WhatsApp message
- `POST /disconnect` — logs out and deletes `/app/auth` to force a new QR
- `GET /auth/meta/callback` — Meta OAuth code exchange; saves long-lived token to DB via `webhook-meta-token.php`

When a WhatsApp message arrives, baileys POSTs it to `http://nginx:8080/webhook-baileys.php` with `X-Baileys-Secret` header.

**PHP backend** (`backend/`) is a hand-rolled router in `public/index.php` with no framework. All routes require `Bearer` token auth via `AuthMiddleware` (token stored in `sesiones` table) except `/api/auth/login` and `/api/config`.

**worker** runs PHP cron jobs defined in `backend/docker/crontab` (also via shell loops in prod override).

## Backend patterns

### Routing (`backend/public/index.php`)

Routes are matched in two ways:
1. Exact string match in `$routes` array → `[$ControllerClass, 'method']`
2. `preg_match` for parameterized routes (e.g. `/api/leads/{id}/score`)

Add new controllers by adding `use` statements at the top and entries in `$routes` / pattern blocks.

### Controllers

All controllers receive `$mysqli` in constructor. The pattern for new tables is `ensureTable()` called in the constructor — it runs `CREATE TABLE IF NOT EXISTS` so the table auto-creates on first API call. No migrations needed for controller-owned tables.

```
backend/app/Controllers/
  ABTestsController.php    — a/b tests + variantes (nested JSON response)
  AgentesController.php    — agentes_ia table, LLM agent configs
  BotConfigController.php  — key-value store in bot_config, testLlm()
  CanalesController.php    — channel credentials + test(), graphGet()
  FlujoController.php      — conversation flows with JSON pasos field
  KPIsController.php       — resumen(), horasPico(), abTests(), kpiPorHora()
  LeadController.php       — leads CRUD + recalcularScore(), actualizarEstado()
  PlantillasController.php — message templates
  VacantesController.php   — job postings
```

### Webhooks (`backend/public/webhook-*.php`)

Each channel has its own PHP file, executed directly (not via the router):

| File | Channel | Auth |
|------|---------|------|
| `webhook-whatsapp.php` | Meta WhatsApp Cloud API | `X-Hub-Signature-256` HMAC |
| `webhook-baileys.php` | Baileys (QR WhatsApp) | `X-Baileys-Secret` header |
| `webhook-instagram.php` | Instagram DMs | `X-Hub-Signature-256` HMAC |
| `webhook-messenger.php` | Facebook Messenger | `X-Hub-Signature-256` HMAC |
| `webhook-lead-ads.php` | Facebook Lead Ads | `X-Hub-Signature-256` HMAC |
| `webhook-telegram.php` | Telegram Bot | `X-Telegram-Bot-Api-Secret-Token` |
| `webhook-meta-token.php` | OAuth token storage | `X-Baileys-Secret` |

All webhooks follow the same flow: verify auth → upsert lead → extract data (age, email regex) → `LeadScorerIA::calcularScore()` → `ReclutadorIA::responder()` → insert `ia_logs` → send reply via channel API.

### Services

**`LeadScorerIA`** — calls GPT-4o (`OPENAI_API_KEY`) with a structured prompt, returns JSON scores (0-100), saves to `lead_scoring_ia`, updates `leads.prioridad` (urgente/alta/media/baja based on score ≥80/65/40).

**`ReclutadorIA`** — resolves which `agentes_ia` record to use (tipo='responder'), reads LLM endpoint from `bot_config` table (takes precedence over `.env`), fetches conversation history from `ia_logs`, calls any OpenAI-compatible `/chat/completions` endpoint. Supports Ollama, OpenAI, Groq, etc.

### Workers (`backend/workers/`)

- `ProcessLeadAds.php` — polls Facebook Lead Ads API every 60s
- `PublishFacebookPost.php` — publishes scheduled posts every 5min
- `RecalculateScores.php` — batch recalculates IA scores every 6h
- `EnviarNotificaciones.php` — alerts recruiter via email + WhatsApp when leads score ≥ escalacion_score threshold every 5min

## Frontend patterns

All API calls go through `frontend/src/lib/api.js` which uses a single `axios` instance with `Bearer` token interceptor and 401 → redirect to `/login`. Never call `axios` directly in pages — add to `api.js` and import.

Pages use **React Query** (`useQuery` / `useMutation`) for all data fetching. Cache invalidation after mutations uses `queryClient.invalidateQueries({ queryKey: [...] })`.

UI follows a dark Tailwind theme with CSS variables (`bg-bg`, `bg-surface`, `text-text`, `text-textMuted`, `border-border`, `text-primary`). Use `bg-surface border border-border rounded-2xl p-5` for cards. For form inputs use class `w-full bg-bg border border-border rounded-lg px-3 py-2 text-sm text-text placeholder-textSubtle focus:outline-none focus:ring-1 focus:ring-primary`.

Framer Motion (`motion.div`) is used for staggered page entry animations. Pattern: `<motion.div initial={{ opacity:0, y:16 }} animate={{ opacity:1, y:0 }} transition={{ delay: i*0.08 }}>`.

## Environment variables

Two `.env` files:
- `.env` (root) — Docker build args (`VITE_API_URL`, `VITE_API_SECRET`) + MySQL passwords
- `backend/.env` — Runtime PHP env: `DB_HOST=mysql`, `BAILEYS_URL=http://baileys:4000`, `BAILEYS_SECRET`, `TELEGRAM_BOT_TOKEN`, `LLM_BASE_URL`, `LLM_MODEL`, `OPENAI_API_KEY`, `META_APP_ID`, `META_APP_SECRET`, `RECRUITER_PHONE`, `WA_PHONE_ID`

The `VITE_API_URL` is baked into the frontend bundle at build time as a Docker build arg (not a runtime env var).

## Database

MySQL auto-initialized from `backend/database/migrations/` (mounted as `/docker-entrypoint-initdb.d`). Key tables: `leads`, `ia_logs`, `lead_scoring_ia`, `lead_cola_prioridad`, `agentes_ia`, `bot_config` (key-value), `canales`, `sesiones`, `ab_tests`, `ab_variantes`, `vacantes`, `plantillas`, `flujos`. Controller-owned tables are created by `ensureTable()` on first request.
