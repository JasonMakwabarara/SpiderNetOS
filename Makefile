.PHONY: dev build up down migrate seed health clean composer-install npm-install codegen deploy-check

dev:              ## Start all services (Docker + Turbo)
	docker compose up -d db redis websocket inference intelligence
	docker compose up -d api horizon scheduler
	cd cockpit && npm run dev

up:               ## Docker compose up all services
	docker compose up --build -d

down:             ## Stop all services
	docker compose down

migrate:          ## Run Laravel migrations
	docker compose exec api php artisan migrate --force

seed:             ## Seed database with core agents + tenant
	docker compose exec api php artisan db:seed

deploy-check:     ## Run deployment verification checks
	docker compose exec api php artisan spidernet:commands:verify
	docker compose exec api php artisan events:verify-chain 00000000-0000-0000-0000-000000000001

health:           ## Check all service health
	curl -s http://localhost:8000/api/health | python -m json.tool
	curl -s http://localhost:9000/health | python -m json.tool

composer-install: ## Install PHP deps locally for IDE
	cd backend && composer install --no-scripts

npm-install:      ## Install JS deps locally for IDE
	npm install && cd cockpit && npm install

codegen:          ## Generate TypeScript types from migrations
	node tools/dev/codegen.js

clean:            ## Remove all containers and volumes
	docker compose down -v --remove-orphans

logs:             ## Tail logs from all services
	docker compose logs -f

logs-api:         ## Tail API logs
	docker compose logs -f api

logs-intelligence: ## Tail intelligence worker logs
	docker compose logs -f intelligence

logs-inference:   ## Tail inference plane logs
	docker compose logs -f inference

restart:          ## Restart all services
	docker compose restart

restart-api:      ## Restart API service
	docker compose restart api

restart-intelligence: ## Restart intelligence worker
	docker compose restart intelligence

status:           ## Show running services
	docker compose ps
