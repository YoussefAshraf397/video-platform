# VPC for one environment (ADR-017): three tiers across three AZs.
#   public    load balancers, NAT gateways
#   private   ECS tasks (api, workers)
#   database  RDS, ElastiCache (no route to the internet at all)

terraform {
  required_version = ">= 1.10"
  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 6.0"
    }
  }
}

data "aws_availability_zones" "available" {
  state = "available"
}

locals {
  azs = slice(data.aws_availability_zones.available.names, 0, 3)
  # /16 split into /20s: public 0-2, private 3-5 (largest consumer of IPs: Fargate tasks), database 6-8.
  public_subnets   = [for i in range(3) : cidrsubnet(var.cidr, 4, i)]
  private_subnets  = [for i in range(3) : cidrsubnet(var.cidr, 4, i + 3)]
  database_subnets = [for i in range(3) : cidrsubnet(var.cidr, 4, i + 6)]
}

module "vpc" {
  source  = "terraform-aws-modules/vpc/aws"
  version = "~> 6.7"

  name = var.name
  cidr = var.cidr
  azs  = local.azs

  public_subnets   = local.public_subnets
  private_subnets  = local.private_subnets
  database_subnets = local.database_subnets

  create_database_subnet_group       = true
  create_database_subnet_route_table = true # isolated: no NAT route for data stores

  enable_nat_gateway     = true
  single_nat_gateway     = var.single_nat_gateway
  one_nat_gateway_per_az = !var.single_nat_gateway

  enable_dns_hostnames = true
  enable_dns_support   = true

  # Lock down the default security group so nothing can accidentally use it.
  manage_default_security_group  = true
  default_security_group_ingress = []
  default_security_group_egress  = []

  enable_flow_log                                 = true
  create_flow_log_cloudwatch_log_group            = true
  create_flow_log_cloudwatch_iam_role             = true
  flow_log_max_aggregation_interval               = 60
  flow_log_cloudwatch_log_group_retention_in_days = var.flow_log_retention_days
}

# Gateway endpoint: S3 traffic (uploads, media, ECR image layers) stays on the AWS network
# instead of going through, and paying for, the NAT gateway. Free.
resource "aws_vpc_endpoint" "s3" {
  vpc_id            = module.vpc.vpc_id
  service_name      = "com.amazonaws.${data.aws_region.current.region}.s3"
  vpc_endpoint_type = "Gateway"
  route_table_ids   = concat(module.vpc.private_route_table_ids, module.vpc.database_route_table_ids)
  tags              = { Name = "${var.name}-s3" }
}

data "aws_region" "current" {}
