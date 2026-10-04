variable "name" {
  description = "Name prefix, e.g. video-platform-dev."
  type        = string
}

variable "cidr" {
  description = "VPC CIDR. Use a /16 that doesn't overlap other environments (peering, VPN later)."
  type        = string

  validation {
    condition     = can(cidrhost(var.cidr, 0)) && endswith(var.cidr, "/16")
    error_message = "cidr must be a /16, e.g. 10.10.0.0/16."
  }
}

variable "single_nat_gateway" {
  description = "One shared NAT gateway (dev: cheaper) instead of one per AZ (prod: survives an AZ outage)."
  type        = bool
}

variable "flow_log_retention_days" {
  description = "How long VPC flow logs are kept."
  type        = number
}
