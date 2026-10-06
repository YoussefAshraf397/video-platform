#!/usr/bin/env bash
# Applies envs/dev to a throwaway local AWS emulator (Moto), checks a second plan shows no
# changes, then destroys everything. Catches apply-time errors (dependency cycles, invalid
# references, broken modules) without an AWS account. Runs on a copy of infra/; the repo
# and real AWS are never touched.
#
# Usage: infra/scripts/emulator-test.sh   (or: make tf-emulator-test)
set -euo pipefail

REPO=$(cd "$(dirname "$0")/../.." && pwd)
TF_IMAGE=${TF_IMAGE:-hashicorp/terraform:1.16.5}
MOTO_IMAGE=${MOTO_IMAGE:-motoserver/moto:5.2.3}
NAME=tf-emulator-$$
WORK=$(mktemp -d)
mkdir -p "$HOME/.terraform.d/plugin-cache"

cleanup() {
  docker rm -f "$NAME" >/dev/null 2>&1 || true
  docker network rm "$NAME" >/dev/null 2>&1 || true
  rm -rf "$WORK"
}
trap cleanup EXIT

docker network create "$NAME" >/dev/null
# Managed policies (ReadOnlyAccess, ...) must exist for role attachments to succeed.
docker run -d --name "$NAME" --network "$NAME" -e MOTO_IAM_LOAD_MANAGED_POLICIES=true "$MOTO_IMAGE" >/dev/null
until docker exec "$NAME" python -c "import urllib.request; urllib.request.urlopen('http://localhost:5000/moto-api/')" 2>/dev/null; do
  sleep 1
done

cp -r "$REPO/infra" "$WORK/"
find "$WORK/infra" -name .terraform -type d -prune -exec rm -rf {} +
DEV="$WORK/infra/envs/dev"
ENDPOINT="http://$NAME:5000"

# Every AWS service envs/dev uses must be listed, or that call goes to real AWS
# (it fails there: the credentials are fake).
cat > "$DEV/emulator_override.tf" <<EOF
terraform {
  backend "local" {}
}

provider "aws" {
  region                      = "us-east-1"
  access_key                  = "test"
  secret_key                  = "test"
  skip_credentials_validation = true
  skip_metadata_api_check     = true
  s3_use_path_style           = true

  endpoints {
    cloudwatch = "$ENDPOINT"
    sns        = "$ENDPOINT"
    ec2  = "$ENDPOINT"
    ecr  = "$ENDPOINT"
    iam  = "$ENDPOINT"
    kms  = "$ENDPOINT"
    logs = "$ENDPOINT"
    rds  = "$ENDPOINT"
    s3   = "$ENDPOINT"
    sts  = "$ENDPOINT"
  }
}
EOF

cat > "$DEV/terraform.tfvars" <<EOF
aws_region        = "us-east-1"
github_repository = "example-org/video-platform"
prod_account_id   = "210987654321"
state_bucket      = "video-platform-tfstate-123456789012"
state_kms_key_arn = "arn:aws:kms:us-east-1:123456789012:key/00000000-0000-0000-0000-000000000000"
EOF

tf() {
  docker run --rm -u "$(id -u):$(id -g)" -e HOME=/tmp --network "$NAME" \
    -v "$WORK:/w" -v "$HOME/.terraform.d/plugin-cache:/plugins" -e TF_PLUGIN_CACHE_DIR=/plugins \
    "$TF_IMAGE" -chdir=/w/infra/envs/dev "$@" -input=false -no-color
}

echo "--- init"
tf init >/dev/null

# Moto can't decode the CBOR protocol AWS provider v6 uses for CloudWatch (dashboards, alarms).
# So: plan everything first, which evaluates the real observability module with real values,
# then apply with that module swapped for a stub with the same outputs. CloudWatch accepting the
# dashboard and alarms is only checked by a real apply.
echo "--- plan everything (including CloudWatch)"
tf plan >"$WORK/full-plan.log" 2>&1 || { cat "$WORK/full-plan.log"; echo "FAIL: full plan"; exit 1; }
grep -q "module.observability.aws_cloudwatch_dashboard.main will be created" "$WORK/full-plan.log" \
  || { echo "FAIL: the dashboard is missing from the plan"; exit 1; }
mkdir -p "$WORK/infra/modules/observability-stub"
cat > "$WORK/infra/modules/observability-stub/main.tf" <<EOF
variable "name" { type = string }
variable "queues" { type = list(string) }
variable "alb_arn_suffix" {
  type    = string
  default = null
}
resource "aws_sns_topic" "alarms" { name = "\${var.name}-alarms" }
output "alarm_topic_arn" { value = aws_sns_topic.alarms.arn }
EOF
cat >> "$DEV/emulator_override.tf" <<EOF

module "observability" {
  source = "../../modules/observability-stub"
}
EOF
tf init >/dev/null

echo "--- apply (CloudWatch stubbed)"
tf apply -auto-approve | grep -E "^(Apply complete|Error)" || true
echo "--- plan again (must show no changes)"
set +e
tf plan -detailed-exitcode >"$WORK/plan.log" 2>&1
status=$?
set -e
if [ "$status" -ne 0 ]; then
  cat "$WORK/plan.log"
  echo "FAIL: second plan exited $status (2 = perpetual changes, 1 = error)"
  exit 1
fi
echo "No changes."
echo "--- destroy"
tf destroy -auto-approve | grep -E "^(Destroy complete|Error)"
echo "PASS"
