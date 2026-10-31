COMPOSE = docker compose --env-file .env -f .docker/docker-compose.yml
TEST_COMPOSE = docker compose --env-file .env -f .docker/tests/docker-compose.test.yml

.PHONY: help up down fresh console migrate rollback status seed test

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*## "}; {printf "  %-12s %s\n", $$1, $$2}'

up: ## Build and start the application stack
	$(COMPOSE) up -d --build

down: ## Stop the application stack
	$(COMPOSE) down

fresh: ## Rebuild the stack, then migrate and seed
	$(COMPOSE) down -v && $(COMPOSE) up -d --build && $(COMPOSE) exec php php bin/console migrate && $(COMPOSE) exec php php bin/console seed

console: ## Run a console command: make console ARGS="status"
	$(COMPOSE) exec php php bin/console $(ARGS)

migrate: ## Apply pending migrations
	$(COMPOSE) exec php php bin/console migrate

rollback: ## Roll back batches: make rollback ARGS="2"
	$(COMPOSE) exec php php bin/console rollback $(ARGS)

status: ## Show migration status
	$(COMPOSE) exec php php bin/console status

seed: ## Run all seeders or one: make seed ARGS="users"
	$(COMPOSE) exec php php bin/console seed $(ARGS)

test: ## Install development dependencies and run PHPUnit
	$(TEST_COMPOSE) run --no-deps --rm tests
