# Deploy Sin Cloudflare

Arquitectura recomendada para esta app:

- `frontend` en `Vercel`
- `backend PHP + MySQL + workers` en `VPS`
- `LM Studio` privado en la misma VPS o en otra máquina accesible solo por red privada

Dominios sugeridos:

- `app.tudominio.com` -> Vercel
- `api.tudominio.com` -> VPS

## 1. Preparar dominio

En el panel donde administras tu dominio:

- crea un registro `CNAME` para `app` apuntando a `cname.vercel-dns.com`
- crea un registro `A` para `api` apuntando a la IP pública de tu VPS

No necesitas Cloudflare para esto.

## 2. Subir frontend a Vercel

El proyecto ya está listo con [vercel.json](C:/Users/Edgar%20Lovera/OneDrive/Desktop/Nueva%20carpeta/(2)%20Meta%20AI_files%20-%20copia/hdreams-bot/vercel.json):

- install command: `npm install --prefix frontend`
- build command: `npm run build --prefix frontend`
- output: `frontend/dist`

Variables en Vercel:

```env
VITE_API_URL=https://api.tudominio.com/api
VITE_API_SECRET=pon-aqui-el-mismo-api-secret-del-backend
```

Pasos:

1. entra a [Vercel](https://vercel.com/)
2. importa este repo o súbelo desde GitHub
3. agrega las variables anteriores
4. asigna el dominio `app.tudominio.com`

## 3. Subir backend a VPS

El backend ya está listo con Docker en [backend/docker-compose.backend.yml](C:/Users/Edgar%20Lovera/OneDrive/Desktop/Nueva%20carpeta/(2)%20Meta%20AI_files%20-%20copia/hdreams-bot/backend/docker-compose.backend.yml) y Nginx en [backend/nginx.api.conf](C:/Users/Edgar%20Lovera/OneDrive/Desktop/Nueva%20carpeta/(2)%20Meta%20AI_files%20-%20copia/hdreams-bot/backend/nginx.api.conf).

En la VPS instala:

```bash
sudo apt update
sudo apt install -y docker.io docker-compose-plugin git
sudo systemctl enable docker
sudo systemctl start docker
```

Clona el proyecto:

```bash
git clone TU_REPO /opt/hdreams-bot
cd /opt/hdreams-bot
```

Prepara el `.env` backend:

```bash
cp backend/.env.example backend/.env
```

Variables mínimas recomendadas en `backend/.env`:

```env
APP_ENV=production
API_PORT=80

DB_HOST=mysql
DB_NAME=hdreams
DB_USER=hdreams
DB_PASS=cambia-esta-password
DB_ROOT_PASS=cambia-esta-root-password

AI_PROVIDER=lmstudio
AI_BASE_URL=http://127.0.0.1:1234/v1
AI_API_KEY=lm-studio
AI_MODEL=qwen/qwen3-4b-instruct
AI_TRANSCRIPTION_MODEL=whisper-1
AI_TEMPERATURE=0.3
AI_TIMEOUT_MS=20000
REPORT_PYTHON_BIN=python

META_VERIFY_TOKEN=define-tu-token
META_APP_SECRET=define-tu-app-secret
WA_PHONE_ID=
WA_TOKEN=
FB_PAGE_TOKEN=
TELEGRAM_BOT_TOKEN=

RECRUITER_PHONE=521XXXXXXXXXX
API_SECRET=usa-un-secret-largo-y-unico
FRONTEND_URL=https://app.tudominio.com
```

Levanta backend:

```bash
docker compose --env-file backend/.env -f backend/docker-compose.backend.yml up -d --build
```

## 4. Migraciones y datos

El contenedor MySQL carga automáticamente los `.sql` montados desde:

- [backend/database/migrations](C:/Users/Edgar%20Lovera/OneDrive/Desktop/Nueva%20carpeta/(2)%20Meta%20AI_files%20-%20copia/hdreams-bot/backend/database/migrations)

Incluye:

- auth y sesiones
- rbac y notificaciones
- reporting
- supervisor/voz/rag
- permisos configurables por empresa

Importante:

- si ya tenías una base corriendo, la nueva migración de permisos es [2026_06_19_phase11_permissions.sql](C:/Users/Edgar%20Lovera/OneDrive/Desktop/Nueva%20carpeta/(2)%20Meta%20AI_files%20-%20copia/hdreams-bot/backend/database/migrations/2026_06_19_phase11_permissions.sql)

## 5. TLS / HTTPS

Sin Cloudflare, usa `Nginx Proxy Manager`, `Caddy` o `Certbot`.

Ruta más simple:

- instala `Nginx Proxy Manager` en la VPS
- publica el backend como `api.tudominio.com`
- emite certificado Let's Encrypt

Si prefieres Certbot sobre Nginx del host, también sirve. Lo importante es que `https://api.tudominio.com/api/health` responda bien.

## 6. LM Studio

Opciones:

- correr LM Studio o servidor OpenAI-compatible en la misma VPS si tiene GPU
- correrlo en otra máquina y permitir acceso solo desde la VPS

No lo expongas públicamente.

Si no tienes GPU en la VPS:

- deja LM Studio en otra máquina
- cambia `AI_BASE_URL` a la IP privada o túnel seguro de esa máquina

## 7. Verificaciones

Backend:

```bash
curl http://127.0.0.1/healthz
curl http://127.0.0.1/api/health
```

Producción:

```bash
curl https://api.tudominio.com/healthz
curl https://api.tudominio.com/api/health
```

Frontend:

- abre `https://app.tudominio.com`
- inicia sesión con:
  - `operaciones@heavenlydreams.mx`
  - `Cambio123!`

Luego cambia esa contraseña en cuanto entres.

## 8. Flujo operativo recomendado

1. primero levanta la VPS
2. valida `api.tudominio.com`
3. después configura `Vercel`
4. por último conecta Meta, WhatsApp, Facebook y LM Studio

## 9. Lo que ya quedó preparado

- frontend listo para Vercel
- backend listo para Docker Compose
- workers cronizados
- reportes PDF
- supervisor + RAG + voz
- permisos por empresa en `/permissions`
- panel multi-cuentas

## 10. Lo que aún requiere tus accesos

- crear proyecto en Vercel
- apuntar dominio
- crear VPS
- llenar secretos reales
- emitir HTTPS
- conectar cuentas Meta
