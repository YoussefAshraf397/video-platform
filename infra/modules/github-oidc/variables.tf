variable "github_repository" {
  description = "GitHub repository allowed to assume the roles, as owner/name."
  type        = string

  validation {
    condition     = can(regex("^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$", var.github_repository))
    error_message = "github_repository must look like owner/name."
  }
}

variable "ecr_repository_arns" {
  description = "Repositories the push role may write to."
  type        = list(string)
}

variable "ecr_kms_key_arns" {
  description = "KMS keys that encrypt those repositories."
  type        = list(string)
}

variable "state_bucket_arn" {
  description = "Terraform state bucket the plan role reads."
  type        = string
}

variable "state_kms_key_arn" {
  description = "KMS key of the state bucket."
  type        = string
}
