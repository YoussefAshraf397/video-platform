variable "aws_region" {
  description = "Region for the provider (Organizations and Identity Center are configured in the primary region)."
  type        = string
}

variable "dev_account_email" {
  description = "Root email of the dev account. Must be unique across AWS; use a team mailbox, e.g. aws-dev@company.com."
  type        = string
}

variable "prod_account_email" {
  description = "Root email of the prod account, e.g. aws-prod@company.com."
  type        = string
}

variable "enable_identity_center" {
  description = "Manage SSO groups and permission sets. Enable Identity Center in the console first."
  type        = bool
  default     = false
}
