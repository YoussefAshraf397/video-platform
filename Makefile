COMPOSE := docker compose -f docker/compose.yaml

.PHONY: help up down reset ps logs aws-init check psql

help: ## List available targets
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*## "} {printf "  %-10s %s\n", $$1, $$2}'

up: ## Start the local stack and create AWS resources
	$(COMPOSE) up -d --wait
	$(COMPOSE) run --rm aws-init

down: ## Stop the stack (keeps the Postgres volume)
	$(COMPOSE) down

reset: ## Stop the stack and delete all local data
	$(COMPOSE) down -v

ps: ## Show service status
	$(COMPOSE) ps

logs: ## Follow logs from all services
	$(COMPOSE) logs -f

aws-init: ## Re-create buckets/queues/topics (needed if the AWS emulator restarted)
	$(COMPOSE) run --rm aws-init

check: ## Verify all services and AWS resources are reachable
	sh docker/check.sh

psql: ## Open a psql shell on the dev database
	$(COMPOSE) exec postgres psql -U videoplatform -d videoplatform
