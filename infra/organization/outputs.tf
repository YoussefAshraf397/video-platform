output "dev_account_id" {
  value = aws_organizations_account.dev.id
}

output "prod_account_id" {
  description = "Set as prod_account_id in envs/dev so prod can pull images."
  value       = aws_organizations_account.prod.id
}
