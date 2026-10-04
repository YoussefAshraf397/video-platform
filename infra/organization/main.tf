# AWS Organization (ADR-017): applied once, in the management account, by a platform admin.
# Creates the OUs, the dev and prod accounts, guardrail SCPs, and SSO access.
#
# If the management account already has an Organization, import it first:
#   terraform import aws_organizations_organization.this <org-id>

terraform {
  required_version = ">= 1.10"
  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 6.0"
    }
  }

  backend "s3" {
    key          = "organization/terraform.tfstate"
    encrypt      = true
    use_lockfile = true
  }
}

provider "aws" {
  region = var.aws_region
  default_tags {
    tags = {
      Project   = "video-platform"
      ManagedBy = "terraform"
      Stack     = "organization"
    }
  }
}

resource "aws_organizations_organization" "this" {
  feature_set          = "ALL"
  enabled_policy_types = ["SERVICE_CONTROL_POLICY"]
  aws_service_access_principals = [
    "sso.amazonaws.com",
    "account.amazonaws.com",
  ]

  lifecycle {
    prevent_destroy = true
  }
}

locals {
  root_id = aws_organizations_organization.this.roots[0].id
}

resource "aws_organizations_organizational_unit" "nonprod" {
  name      = "NonProd"
  parent_id = local.root_id
}

resource "aws_organizations_organizational_unit" "prod" {
  name      = "Prod"
  parent_id = local.root_id
}

resource "aws_organizations_account" "dev" {
  name              = "video-platform-dev"
  email             = var.dev_account_email
  parent_id         = aws_organizations_organizational_unit.nonprod.id
  role_name         = "OrganizationAccountAccessRole"
  close_on_deletion = false

  lifecycle {
    prevent_destroy = true
    ignore_changes  = [role_name] # only used at creation
  }
}

resource "aws_organizations_account" "prod" {
  name              = "video-platform-prod"
  email             = var.prod_account_email
  parent_id         = aws_organizations_organizational_unit.prod.id
  role_name         = "OrganizationAccountAccessRole"
  close_on_deletion = false

  lifecycle {
    prevent_destroy = true
    ignore_changes  = [role_name]
  }
}

# --- Guardrails (SCPs apply to member accounts, never to the management account) ---

data "aws_iam_policy_document" "guardrails" {
  statement {
    sid       = "DenyLeavingTheOrganization"
    effect    = "Deny"
    actions   = ["organizations:LeaveOrganization"]
    resources = ["*"]
  }
  statement {
    # Root credentials of member accounts are never needed day to day; humans use SSO.
    sid       = "DenyRootUser"
    effect    = "Deny"
    actions   = ["*"]
    resources = ["*"]
    condition {
      test     = "StringLike"
      variable = "aws:PrincipalArn"
      values   = ["arn:aws:iam::*:root"]
    }
  }
  statement {
    # Humans and CI use SSO and OIDC roles; IAM users with access keys are not allowed.
    sid       = "DenyIAMUserAccessKeys"
    effect    = "Deny"
    actions   = ["iam:CreateUser", "iam:CreateAccessKey"]
    resources = ["*"]
  }
}

resource "aws_organizations_policy" "guardrails" {
  name        = "video-platform-guardrails"
  description = "No leaving the org, no root user, no IAM user access keys"
  type        = "SERVICE_CONTROL_POLICY"
  content     = data.aws_iam_policy_document.guardrails.json
}

resource "aws_organizations_policy_attachment" "guardrails" {
  for_each = {
    nonprod = aws_organizations_organizational_unit.nonprod.id
    prod    = aws_organizations_organizational_unit.prod.id
  }
  policy_id = aws_organizations_policy.guardrails.id
  target_id = each.value
}
