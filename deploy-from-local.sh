#!/usr/bin/env bash
set -euo pipefail

KEY="$HOME/.ssh/id_ed25519"
VPS="root@64.227.25.74"
APP_DIR="/srv/hdreams-bot"
LOCAL_DIR="$(cd "$(dirname "$0")" && pwd)"

vssh() { ssh -o StrictHostKeyChecking=no -i "$KEY" "$@"; }

echo "==> [1/4] Subiendo archivos a $VPS:$APP_DIR ..."
vssh "$VPS" "mkdir -p $APP_DIR"
tar -czf - \
  --exclude='./.git' \
  --exclude='./node_modules' \
  --exclude='./frontend/node_modules' \
  --exclude='./frontend/dist' \
  --exclude='./backend/vendor' \
  --exclude='./baileys/node_modules' \
  --exclude='./*.log' \
  -C "$LOCAL_DIR" . \
  | vssh "$VPS" "cd $APP_DIR && tar -xzf -"

echo "==> [2/4] Construyendo imagenes y levantando contenedores ..."
vssh "$VPS" "cd $APP_DIR && docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build --remove-orphans"

echo "==> [3/4] Instalando dependencias PHP ..."
vssh "$VPS" "cd $APP_DIR && docker compose exec -T php composer install --no-dev --optimize-autoloader --no-interaction"

echo "==> [4/4] Verificando estado ..."
vssh "$VPS" "cd $APP_DIR && docker compose ps"

echo ""
echo "Listo. Abre https://bot.heavenlydreams.com.mx"
echo ""
echo "WhatsApp QR: https://bot.heavenlydreams.com.mx/whatsapp"
