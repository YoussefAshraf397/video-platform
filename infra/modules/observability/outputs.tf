output "alarm_topic_arn" {
  description = "SNS topic every alarm notifies. Subscribe on-call email or chat to it."
  value       = aws_sns_topic.alarms.arn
}

output "dashboard_name" {
  description = "CloudWatch dashboard name."
  value       = aws_cloudwatch_dashboard.main.dashboard_name
}

output "dlq_alarm_names" {
  description = "One alarm per dead-letter queue."
  value       = [for a in aws_cloudwatch_metric_alarm.dlq_not_empty : a.alarm_name]
}
