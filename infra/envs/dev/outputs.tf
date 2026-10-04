output "vpc_id" {
  value = module.network.vpc_id
}

output "private_subnet_ids" {
  value = module.network.private_subnet_ids
}

output "database_subnet_group_name" {
  value = module.network.database_subnet_group_name
}

output "ecr_repository_urls" {
  value = module.ecr.repository_urls
}

output "github_ecr_push_role_arn" {
  description = "Set as the AWS_ECR_PUSH_ROLE_ARN variable in GitHub (used by CD in S2-02)."
  value       = module.github_oidc.ecr_push_role_arn
}

output "github_terraform_plan_role_arn" {
  value = module.github_oidc.terraform_plan_role_arn
}
