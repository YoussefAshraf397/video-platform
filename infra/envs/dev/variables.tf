variable "aws_region" {
  description = "Primary region (ADR-017: single region, multi-AZ)."
  type        = string
}

variable "github_repository" {
  description = "owner/name of the GitHub repository whose Actions may assume the CI roles."
  type        = string
}

variable "prod_account_id" {
  description = "Prod account ID, allowed to pull images. Empty until the prod account exists."
  type        = string
  default     = ""

  validation {
    condition     = var.prod_account_id == "" || can(regex("^[0-9]{12}$", var.prod_account_id))
    error_message = "prod_account_id must be a 12-digit AWS account ID or empty."
  }
}

variable "state_bucket" {
  description = "State bucket name (infra/bootstrap output state_bucket)."
  type        = string
}

variable "state_kms_key_arn" {
  description = "State bucket KMS key (infra/bootstrap output state_kms_key_arn)."
  type        = string
}
