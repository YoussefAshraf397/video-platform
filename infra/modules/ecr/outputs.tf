output "repository_urls" {
  description = "Map of repository name to URL."
  value       = { for name, repo in aws_ecr_repository.this : name => repo.repository_url }
}

output "repository_arns" {
  value = [for repo in aws_ecr_repository.this : repo.arn]
}

output "kms_key_arn" {
  value = aws_kms_key.ecr.arn
}
