output "state_bucket" {
  description = "Put this in envs/<env>/backend.hcl as `bucket`."
  value       = aws_s3_bucket.state.bucket
}

output "state_kms_key_arn" {
  description = "Put this in envs/<env>/backend.hcl as `kms_key_id`."
  value       = aws_kms_key.state.arn
}
