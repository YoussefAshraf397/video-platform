# terraform test (mocked AWS provider, no account or emulator needed): one alarm per DLQ, and the
# dashboard has the queue widgets always and the API (RED) widgets once an ALB is given.

mock_provider "aws" {
  mock_data "aws_region" {
    defaults = { region = "eu-west-1" }
  }
  mock_resource "aws_sns_topic" {
    defaults = { arn = "arn:aws:sns:eu-west-1:123456789012:vp-test-alarms" }
  }
  mock_resource "aws_cloudwatch_metric_alarm" {
    defaults = { arn = "arn:aws:cloudwatch:eu-west-1:123456789012:alarm:vp-test" }
  }
}

variables {
  name   = "vp-test"
  queues = ["media-process", "media-results"]
}

run "queues_only" {
  command = apply

  assert {
    condition     = length(aws_cloudwatch_metric_alarm.dlq_not_empty) == 2
    error_message = "Expected one alarm per queue's DLQ."
  }
  assert {
    condition     = aws_cloudwatch_metric_alarm.dlq_not_empty["media-process"].dimensions.QueueName == "media-process-dlq"
    error_message = "The alarm must watch the DLQ, not the queue."
  }
  assert {
    condition = alltrue([for a in aws_cloudwatch_metric_alarm.dlq_not_empty :
    a.threshold == 0 && a.comparison_operator == "GreaterThanThreshold" && a.treat_missing_data == "notBreaching"])
    error_message = "DLQ alarms must fire on the first message and stay quiet when there is no data."
  }
  assert {
    condition     = length(jsondecode(aws_cloudwatch_dashboard.main.dashboard_body).widgets) == 4
    error_message = "Without an ALB the dashboard has the 3 queue widgets and the alarm widget."
  }
  assert {
    condition     = !strcontains(aws_cloudwatch_dashboard.main.dashboard_body, "ApplicationELB")
    error_message = "No API widgets without an ALB."
  }
}

run "with_alb" {
  command = apply

  variables {
    alb_arn_suffix = "app/videoplatform-api/0123456789abcdef"
  }

  assert {
    condition     = length(jsondecode(aws_cloudwatch_dashboard.main.dashboard_body).widgets) == 7
    error_message = "With an ALB the dashboard adds requests, errors and latency."
  }
  assert {
    condition = alltrue([for p in ["p50", "p95", "p99"] :
    strcontains(aws_cloudwatch_dashboard.main.dashboard_body, "\"stat\":\"${p}\"")])
    error_message = "Latency must show p50, p95 and p99."
  }
  assert {
    condition     = jsondecode(aws_cloudwatch_dashboard.main.dashboard_body).widgets[0].properties.region == "eu-west-1"
    error_message = "Widgets use the provider's region."
  }
}
