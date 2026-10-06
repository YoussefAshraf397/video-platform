variable "name" {
  description = "Prefix for the dashboard, alarms and alarm topic, e.g. videoplatform-dev."
  type        = string
}

variable "queues" {
  description = "SQS queues to watch. Each has a DLQ named <queue>-dlq (ADR-005)."
  type        = list(string)
}

variable "alb_arn_suffix" {
  description = "ARN suffix of the API's ALB (app/<name>/<id>) for the API widgets. Null until the ALB exists (S2-01)."
  type        = string
  default     = null
}
