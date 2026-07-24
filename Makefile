COMPOSE      := docker compose
COMPOSE_DEMO := docker compose -f docker-compose.yml -f compose.demo.yml

.DEFAULT_GOAL := help

.PHONY: help
help: ## Diese Übersicht
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) \
	  | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

.PHONY: init
init: ## .env aus der Vorlage anlegen und APP_KEY erzeugen
	@test -f .env || cp .env.example .env
	@grep -q '^APP_KEY=.\+' .env || { \
	  key="base64:$$(openssl rand -base64 32)"; \
	  sed -i.bak "s|^APP_KEY=.*|APP_KEY=$$key|" .env && rm -f .env.bak; \
	  echo "APP_KEY erzeugt."; }
	@echo ".env ist bereit. Admin-Zugang darin anpassen, dann: make up"

.PHONY: up
up: ## Stack bauen und starten
	$(COMPOSE) up -d --build

.PHONY: down
down: ## Stack stoppen
	$(COMPOSE) down

.PHONY: reset
reset: ## Stack stoppen und alle Daten verwerfen
	$(COMPOSE_DEMO) down -v

.PHONY: logs
logs: ## Logs folgen
	$(COMPOSE) logs -f --tail=100

.PHONY: shell
shell: ## Shell im App-Container
	$(COMPOSE) exec app sh

.PHONY: artisan
artisan: ## Artisan-Befehl ausführen: make artisan CMD="migrate:status"
	$(COMPOSE) exec app php artisan $(CMD)

.PHONY: index
index: ## Alle aktiven Ordner neu durchlaufen
	$(COMPOSE) exec app php artisan nextsearch:index

.PHONY: reindex
reindex: ## Vollständig neu indizieren, Delta-Erkennung übergehen
	$(COMPOSE) exec app php artisan nextsearch:index --full

.PHONY: test
test: ## Backend- und Frontend-Tests
	$(COMPOSE) exec -T app php artisan test
	cd frontend && npm run test -- --run

.PHONY: lint
lint: ## Statische Prüfungen
	$(COMPOSE) exec -T app ./vendor/bin/pint --test
	cd frontend && npm run lint

.PHONY: demo
demo: ## Stack samt Demo-Nextcloud starten
	$(COMPOSE_DEMO) up -d --build

.PHONY: demo-seed
demo-seed: ## Beispieldateien in die Demo-Nextcloud legen
	./demo/seed.sh

.PHONY: lockfiles
lockfiles: ## Lockfiles unter Linux neu erzeugen (siehe CONTRIBUTING.md)
	docker run --rm -v "$(PWD)/frontend:/app" -w /app node:24-alpine npm install --package-lock-only
	docker run --rm -v "$(PWD)/backend:/app" -w /app -e COMPOSER_HOME=/tmp composer:2 composer update --lock
