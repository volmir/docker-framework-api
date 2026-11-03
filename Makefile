COMPOSE = docker compose

.PHONY: help check-env install up down restart logs sh migrate rollback status seed routes routes-check migration command controller middleware domain fresh test lint analyse check prod-check

help: ## Show available commands
	@grep -E '^[a-zA-Z_-]+:.*## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*## "}; {printf "  %-12s %s\n", $$1, $$2}'

check-env:
	@test -f .env || { echo 'Missing .env: run cp .env.example .env'; exit 1; }

install: check-env ## Install Composer dependencies inside the PHP container
	$(COMPOSE) build php
	$(COMPOSE) run --rm --no-deps php composer install --no-interaction --no-progress

up: check-env ## Build and start the application; wait for healthy containers
	$(COMPOSE) up -d --build --wait

down: check-env ## Stop the stack
	$(COMPOSE) --profile test down

restart: check-env ## Recreate MySQL, PHP and Nginx, apply .env and wait for health; keep database data
	$(COMPOSE) up -d --force-recreate --wait

logs: check-env ## Follow container logs
	$(COMPOSE) logs -f

sh: check-env ## Open a PHP container shell
	$(COMPOSE) exec php sh

migrate: check-env ## Apply pending migrations
	$(COMPOSE) exec -T php php bin/console migrate

rollback: check-env ## Roll back batches: make rollback ARGS="2"
	$(COMPOSE) exec -T php php bin/console rollback $(ARGS)

status: check-env ## Show migration status
	$(COMPOSE) exec -T php php bin/console status

routes: check-env ## Show routes and middleware without a database connection
	$(COMPOSE) run --rm --no-deps php php bin/console routes

routes-check: check-env ## Check route classes, methods, middleware and duplicate keys
	$(COMPOSE) run --rm --no-deps php php bin/console routes --check

migration: check-env ## Generate a migration: make migration name=create_posts_table
	$(COMPOSE) run --rm --no-deps --user "$$(id -u):$$(id -g)" php php bin/console make:migration "$(name)"

command: check-env ## Generate a console command: make command name=report:users
	$(COMPOSE) run --rm --no-deps --user "$$(id -u):$$(id -g)" php php bin/console make:command "$(name)"

controller: check-env ## Generate a controller: make controller name=Post actions="index show" routes=1
	$(COMPOSE) run --rm --no-deps --user "$$(id -u):$$(id -g)" php php bin/console make:controller "$(name)" $(actions) $(if $(filter 1,$(routes)),--routes,)

middleware: check-env ## Generate middleware: make middleware name=Throttle
	$(COMPOSE) run --rm --no-deps --user "$$(id -u):$$(id -g)" php php bin/console make:middleware "$(name)"

domain: check-env ## Generate a domain and its test: make domain name=Posts
	$(COMPOSE) run --rm --no-deps --user "$$(id -u):$$(id -g)" php php bin/console make:domain "$(name)"

prod-check: check-env ## Check production environment safety without starting the database
	$(COMPOSE) run --rm --no-deps -e APP_ENV=production php php bin/console env:check

seed: check-env ## Run all seeders or one: make seed ARGS="users"
	$(COMPOSE) exec -T php php bin/console seed $(ARGS)

fresh: check-env ## Recreate the database, migrate and seed
	$(COMPOSE) --profile test down -v
	$(MAKE) up
	$(MAKE) migrate
	$(MAKE) seed

test: check-env ## Run PHPUnit against the isolated test environment
	$(COMPOSE) --profile test build tests
	$(COMPOSE) --profile test run --rm tests

lint: check-env ## Check PHP syntax inside the container
	$(COMPOSE) run --rm --no-deps php composer lint
	$(COMPOSE) run --rm --no-deps php php -l bin/console

analyse: check-env ## Run PHPStan level 8 inside the test container
	$(COMPOSE) --profile test build tests
	$(COMPOSE) --profile test run --rm --no-deps tests composer analyse

check: ## Run lint, static analysis, route validation and tests in order
	$(MAKE) lint
	$(MAKE) analyse
	$(MAKE) routes-check
	$(MAKE) test
