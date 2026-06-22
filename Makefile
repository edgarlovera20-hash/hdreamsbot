.PHONY: up down build logs shell-php shell-mysql migrate

up: ## Levantar en producción
	cp -n .env.example .env || true
	cp -n backend/.env.example backend/.env || true
	docker compose up -d --build

down: ## Detener todos los servicios
	docker compose down

build: ## Reconstruir imágenes sin caché
	docker compose build --no-cache

logs: ## Ver logs en tiempo real
	docker compose logs -f

logs-php: ## Logs del backend PHP
	docker compose logs -f php worker

logs-nginx: ## Logs de Nginx
	docker compose logs -f nginx

shell-php: ## Abrir shell en el contenedor PHP
	docker compose exec php sh

shell-mysql: ## Abrir MySQL CLI
	docker compose exec mysql mysql -u$${DB_USER} -p$${DB_PASS} $${DB_NAME}

migrate: ## Correr migraciones SQL manualmente
	docker compose exec mysql mysql -u$${DB_USER} -p$${DB_PASS} $${DB_NAME} \
		< backend/database/migrations/hdreams.sql

worker-logs: ## Ver logs de todos los workers
	docker compose exec worker tail -f \
		/var/log/hdreams-scoring.log \
		/var/log/hdreams-leads.log \
		/var/log/hdreams-posts.log 2>/dev/null

restart: ## Reiniciar sin reconstruir
	docker compose restart
