COMPOSE := docker compose -f docker/compose.yaml

# Terraform and tflint run in containers (pinned versions) as the current user.
TF_IMAGE     := hashicorp/terraform:1.16.5
TFLINT_IMAGE := ghcr.io/terraform-linters/tflint:v0.64.0
TF_ROOTS     := bootstrap organization envs/dev
DOCKER_RUN   := docker run --rm -u $(shell id -u):$(shell id -g) -e HOME=/tmp -v $(CURDIR):/repo
TF           := $(DOCKER_RUN) -v $(HOME)/.terraform.d/plugin-cache:/plugins -e TF_PLUGIN_CACHE_DIR=/plugins \
                -v $(HOME)/.aws:/tmp/.aws -e AWS_PROFILE $(TF_IMAGE)
TFLINT       := $(DOCKER_RUN) -v $(HOME)/.tflint.d:/tflint -e TFLINT_PLUGIN_DIR=/tflint/plugins -w /repo/infra $(TFLINT_IMAGE)

.PHONY: help up down reset ps logs aws-init check psql test test-api test-contracts test-worker openapi tf tf-check tf-emulator-test

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

tf: ## Terraform in a container: make tf DIR=envs/dev ARGS="plan" (uses AWS_PROFILE / ~/.aws SSO login)
	@mkdir -p $(HOME)/.terraform.d/plugin-cache
	$(TF) -chdir=/repo/infra/$(DIR) $(ARGS)

tf-check: ## Terraform fmt + validate + tflint for every root, and module tests (no AWS access needed)
	@mkdir -p $(HOME)/.terraform.d/plugin-cache $(HOME)/.tflint.d
	$(TF) -chdir=/repo/infra fmt -recursive -check -diff
	@for root in $(TF_ROOTS); do \
		$(TF) -chdir=/repo/infra/$$root init -backend=false -input=false >/dev/null && \
		$(TF) -chdir=/repo/infra/$$root validate -no-color || exit 1; \
	done
	$(TFLINT) --init >/dev/null
	$(TFLINT) --recursive --config /repo/infra/.tflint.hcl
	@for tests in infra/modules/*/tests; do \
		module=$${tests%/tests}; module=$${module#infra/}; \
		$(TF) -chdir=/repo/infra/$$module init -backend=false -input=false >/dev/null && \
		$(TF) -chdir=/repo/infra/$$module test -no-color || exit 1; \
	done

tf-emulator-test: ## Apply envs/dev to a throwaway AWS emulator, check no drift, destroy
	infra/scripts/emulator-test.sh
