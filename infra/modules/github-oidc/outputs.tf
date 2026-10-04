output "ecr_push_role_arn" {
  description = "Use in GitHub Actions: aws-actions/configure-aws-credentials role-to-assume."
  value       = aws_iam_role.ecr_push.arn
}

output "terraform_plan_role_arn" {
  value = aws_iam_role.terraform_plan.arn
}
