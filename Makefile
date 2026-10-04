COMPOSE := docker compose -f docker/compose.yaml

.PHONY: help up down reset ps logs aws-init check psql test test-api test-contracts test-worker openapi

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

test: test-api test-contracts test-worker ## Run all test suites (needs `make up`)

test-api: ## Run the Laravel test suites
	cd api && php artisan test

test-contracts: ## Validate message schemas and examples (Go)
	cd contracts && go test ./...

test-worker: ## Run the Go media worker tests (SQS tests use the local stack)
	cd media-worker && AWS_ENDPOINT_URL=http://localhost:4566 AWS_ACCESS_KEY_ID=test AWS_SECRET_ACCESS_KEY=test AWS_REGION=us-east-1 go test -race ./...

openapi: ## Regenerate api/openapi.json from the code
	cd api && php artisan scramble:export
