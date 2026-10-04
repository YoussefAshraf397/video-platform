variable "repositories" {
  description = "Repository names."
  type        = list(string)
}

variable "pull_account_ids" {
  description = "Other AWS accounts (e.g. prod) allowed to pull images."
  type        = list(string)
  default     = []
}

variable "keep_images" {
  description = "Newest images kept per repository."
  type        = number
  default     = 50
}

variable "untagged_expiry_days" {
  description = "Days before untagged images (failed or superseded pushes) are deleted."
  type        = number
  default     = 7
}
