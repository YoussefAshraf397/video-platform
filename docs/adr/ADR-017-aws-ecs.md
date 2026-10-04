# ADR-017: AWS with ECS (Fargate + EC2 Spot)

- **Status:** Accepted
- **Date:** 2026-10-04

## Context
There is no dedicated DevOps engineer, so infrastructure must be managed services that one Laravel engineer can operate.

## Decision
- **Cloud:** AWS, single region, 2–3 AZs. Separate AWS accounts for **non-prod** and **prod** under one AWS Organization.
- **Compute:** ECS.
  - **Fargate** runs the Laravel services `api` (Octane), `worker`, `scheduler` and `outbox-relay`.
  - **EC2 Spot capacity provider** runs the Go `media-worker`.
- **Data:** RDS PostgreSQL Multi-AZ, ElastiCache Redis (Multi-AZ), S3 (`uploads`, `media`, `images`, `exports`, `logs`).
- **Edge:** Route 53, CloudFront, AWS WAF (managed rules), Shield Standard, ACM.
- **Delivery:** CloudFront serves media with **signed cookies** and Origin Access Control (OAC), so the media bucket is never public.
- **Messaging:** SQS, SNS, Firehose.
- **Other:** SES (email), Secrets Manager, KMS, ECR, CloudWatch + OpenTelemetry, CloudTrail.
- **Infrastructure as code:** Terraform *or* AWS CDK. Pick one in week 1; Terraform is the default, being cloud-neutral and widely known.
- **Not used at the MVP:** EKS, API Gateway (ALB is enough), Lambda for core logic, Kafka, OpenSearch, Aurora.

## Alternatives considered
- **EKS:** more power but much more ops.
- **Laravel Vapor (Lambda):** convenient for PHP, but a poor fit for long-running work and PG connection patterns, and it doesn't cover the Go worker.
- **Plain EC2 VMs:** more manual ops.

## Consequences
- ➕ Minimal ops, autoscaling, and pay-per-use in dev.
- ➖ AWS lock-in at the infrastructure level. The application code stays portable (Laravel, Go, PostgreSQL, S3 API).

## Revisit when
The team grows past about 15 engineers with a platform team (consider EKS), or multi-region becomes necessary.
