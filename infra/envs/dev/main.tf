# Dev environment, applied in the dev (non-prod) account with SSO credentials:
#   terraform init -backend-config=backend.hcl && terraform apply

terraform {
  required_version = ">= 1.10"
  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 6.0"
    }
  }

  # bucket and kms_key_id come from backend.hcl (outputs of infra/bootstrap).
  backend "s3" {
    key          = "envs/dev/terraform.tfstate"
    encrypt      = true
    use_lockfile = true
  }
}

provider "aws" {
  region = var.aws_region
  default_tags {
    tags = {
      Project     = "video-platform"
      Environment = "dev"
      ManagedBy   = "terraform"
    }
  }
}

locals {
  name = "video-platform-dev"
}

module "network" {
  source = "../../modules/network"

  name                    = local.name
  cidr                    = "10.10.0.0/16" # prod: 10.20.0.0/16
  single_nat_gateway      = true           # dev tolerates an AZ outage; saves ~2 NAT gateways
  flow_log_retention_days = 30
}

module "ecr" {
  source = "../../modules/ecr"

  repositories     = ["video-platform/api", "video-platform/media-worker"]
  pull_account_ids = var.prod_account_id == "" ? [] : [var.prod_account_id]
}

module "github_oidc" {
  source = "../../modules/github-oidc"

  github_repository   = var.github_repository
  ecr_repository_arns = module.ecr.repository_arns
  ecr_kms_key_arns    = [module.ecr.kms_key_arn]
  state_bucket_arn    = "arn:aws:s3:::${var.state_bucket}"
  state_kms_key_arn   = var.state_kms_key_arn
}
