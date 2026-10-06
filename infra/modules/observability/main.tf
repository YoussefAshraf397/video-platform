# Observability baseline (S3-10): one dashboard for the API's rate/errors/duration (RED) and the
# queues, and an alarm on every dead-letter queue. Traces go through OpenTelemetry (ADOT), not here.

terraform {
  required_version = ">= 1.10"
  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 6.0"
    }
  }
}

data "aws_region" "current" {}

locals {
  region = data.aws_region.current.region
  dlqs   = { for q in var.queues : q => "${q}-dlq" }

  # API RED from the ALB (only once it exists): requests, errors, and latency percentiles.
  api_widget_defs = [
    {
      type = "metric", x = 0, y = 0, width = 8, height = 6
      properties = {
        title   = "API requests / min", region = local.region, view = "timeSeries", stat = "Sum", period = 60
        metrics = [["AWS/ApplicationELB", "RequestCount", "LoadBalancer", var.alb_arn_suffix]]
      }
    },
    {
      type = "metric", x = 8, y = 0, width = 8, height = 6
      properties = {
        title = "API errors / min", region = local.region, view = "timeSeries", stat = "Sum", period = 60
        metrics = [
          ["AWS/ApplicationELB", "HTTPCode_Target_5XX_Count", "LoadBalancer", var.alb_arn_suffix, { label = "5xx" }],
          ["AWS/ApplicationELB", "HTTPCode_Target_4XX_Count", "LoadBalancer", var.alb_arn_suffix, { label = "4xx" }],
          ["AWS/ApplicationELB", "HTTPCode_ELB_5XX_Count", "LoadBalancer", var.alb_arn_suffix, { label = "ALB 5xx (no healthy target)" }],
        ]
      }
    },
    {
      type = "metric", x = 16, y = 0, width = 8, height = 6
      properties = {
        title = "API latency (s)", region = local.region, view = "timeSeries", period = 60
        metrics = [for p in ["p50", "p95", "p99"] :
          ["AWS/ApplicationELB", "TargetResponseTime", "LoadBalancer", var.alb_arn_suffix, { stat = p, label = p }]
        ]
      }
    },
  ]
  api_widgets = [for w in local.api_widget_defs : w if var.alb_arn_suffix != null]

  queue_widgets = [
    {
      type = "metric", x = 0, y = 6, width = 8, height = 6
      properties = {
        title   = "Queue depth (visible messages)", region = local.region, view = "timeSeries", stat = "Maximum", period = 60
        metrics = [for q in var.queues : ["AWS/SQS", "ApproximateNumberOfMessagesVisible", "QueueName", q]]
      }
    },
    {
      type = "metric", x = 8, y = 6, width = 8, height = 6
      properties = {
        title   = "Oldest message age (s)", region = local.region, view = "timeSeries", stat = "Maximum", period = 60
        metrics = [for q in var.queues : ["AWS/SQS", "ApproximateAgeOfOldestMessage", "QueueName", q]]
      }
    },
    {
      type = "metric", x = 16, y = 6, width = 8, height = 6
      properties = {
        title   = "Dead-letter queues (should be 0)", region = local.region, view = "timeSeries", stat = "Maximum", period = 60
        metrics = [for q, dlq in local.dlqs : ["AWS/SQS", "ApproximateNumberOfMessagesVisible", "QueueName", dlq]]
      }
    },
    {
      type = "alarm", x = 0, y = 12, width = 24, height = 3
      properties = {
        title  = "Alarms"
        alarms = [for a in aws_cloudwatch_metric_alarm.dlq_not_empty : a.arn]
      }
    },
  ]
}

resource "aws_cloudwatch_dashboard" "main" {
  dashboard_name = var.name
  dashboard_body = jsonencode({ widgets = concat(local.api_widgets, local.queue_widgets) })
}

# Alarms publish here. Subscriptions (email, chat) are added by ops; email needs confirming.
resource "aws_sns_topic" "alarms" {
  name              = "${var.name}-alarms"
  kms_master_key_id = "alias/aws/sns"
}

# A message in a DLQ means something failed five times and needs a human (ADR-005).
resource "aws_cloudwatch_metric_alarm" "dlq_not_empty" {
  for_each = local.dlqs

  alarm_name          = "${var.name}-${each.value}-not-empty"
  alarm_description   = "Messages in ${each.value}: ${each.key} failed 5 times. Inspect them, fix the cause, then redrive (runbook: docs/)."
  namespace           = "AWS/SQS"
  metric_name         = "ApproximateNumberOfMessagesVisible"
  dimensions          = { QueueName = each.value }
  statistic           = "Maximum"
  period              = 60
  evaluation_periods  = 1
  comparison_operator = "GreaterThanThreshold"
  threshold           = 0
  treat_missing_data  = "notBreaching"
  alarm_actions       = [aws_sns_topic.alarms.arn]
  ok_actions          = [aws_sns_topic.alarms.arn]
}
