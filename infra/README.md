# infra — AWS infrastructure (Terraform)

AWS foundations for the platform ([ADR-017](../docs/adr/ADR-017-aws-ecs.md)): one Organization with a **dev** (non-prod) and a **prod** account, a single region, three AZs.

**Access rule: no long-lived keys.** People sign in with IAM Identity Center (SSO). GitHub Actions assumes roles with OIDC tokens. An SCP blocks creating IAM users and access keys in member accounts.

## Layout

| Path | Applied in | What |
|---|---|---|
| [`bootstrap/`](bootstrap/) | each account (management, dev, prod), once | S3 state bucket: versioned, KMS-encrypted, TLS-only, public access blocked. Locking uses S3's native lock file, so there's no DynamoDB table. |
| [`organization/`](organization/) | management account | Organization, `NonProd`/`Prod` OUs, dev + prod accounts, guardrail SCP, SSO groups and permission sets |
| [`envs/dev/`](envs/dev/) | dev account | VPC, ECR repositories, GitHub OIDC roles, CloudWatch dashboard + alarms |
| [`modules/network/`](modules/network/) | — | VPC with public / private / database subnets across 3 AZs, NAT, S3 gateway endpoint, flow logs |
| [`modules/ecr/`](modules/ecr/) | — | `video-platform/api`, `video-platform/media-worker`: immutable tags, scan on push, KMS, lifecycle, cross-account pull for prod |
| [`modules/github-oidc/`](modules/github-oidc/) | — | OIDC provider; `github-actions-ecr-push` (main branch only) and `github-actions-terraform-plan` (read-only, PRs + main) |
| [`modules/observability/`](modules/observability/) | — | CloudWatch dashboard (API requests/errors/latency p50-p99 from the ALB; per-queue depth, oldest-message age, DLQ depth), an alarm on every DLQ (> 0 messages), and the SNS topic alarms notify. Subscribe on-call to the `alarm_topic_arn` output. |

`envs/prod` arrives with the production environment work (it reuses the same modules with `single_nat_gateway = false` and CIDR `10.20.0.0/16`).

## First-time setup (platform admin)

You need admin access to the AWS **management** account. Run Terraform via `make tf ARGS="..."` from the repo root, or install Terraform 1.16+.

1. **State bucket in the management account**
   - `cd infra/bootstrap && terraform init && terraform apply -var aws_region=<region>`
   - Note the outputs. Bootstrap state is tiny and local; keep `terraform.tfstate` somewhere safe, or migrate it into the new bucket with a `backend "s3"` block and `terraform init -migrate-state`.
2. **Organization and accounts**
   - `cd infra/organization`, then create `backend.hcl` (see `envs/dev/backend.hcl.example`) and `terraform.tfvars` (`aws_region`, `dev_account_email`, `prod_account_email`).
   - `terraform init -backend-config=backend.hcl && terraform apply`
   - If the account already has an Organization, `terraform import aws_organizations_organization.this <org-id>` first.
3. **SSO**
   - Enable IAM Identity Center in the console (management account), then set `enable_identity_center = true` and apply again.
   - Add people to the `platform-admins`, `developers` and `read-only` groups.
4. **State bucket in the dev account**
   - Sign in to dev through SSO (`aws sso login --profile video-platform-dev`), then repeat step 1 there.
5. **Dev environment**
   - `cd infra/envs/dev`, copy `backend.hcl.example` → `backend.hcl` and `terraform.tfvars.example` → `terraform.tfvars`, and fill them in from the bootstrap outputs.
   - `terraform init -backend-config=backend.hcl && terraform plan && terraform apply`
6. **GitHub**
   - Add the `github_ecr_push_role_arn` output as the repository variable `AWS_ECR_PUSH_ROLE_ARN`, used by CD in S2-02.
   - No AWS secrets go into GitHub.

`backend.hcl` and `terraform.tfvars` are gitignored: they hold account-specific values. `.terraform.lock.hcl` files **are** committed, with provider hashes for Linux and macOS on amd64 and arm64.

## What dev costs while idle

Roughly **$35–45/month**, almost all of it the single NAT gateway plus flow logs and KMS keys. Prod uses one NAT gateway per AZ (about 3× the NAT cost) so it survives an AZ outage.

## Checks

No AWS account is needed for any of these. CI runs all of them on every PR.

| Command | What it proves |
|---|---|
| `make tf-check` | `fmt`, `validate` for every root, `tflint` with the AWS ruleset, and module tests (`terraform test` with a mocked provider, e.g. one alarm per DLQ, dashboard widgets with and without an ALB) |
| `make tf-emulator-test` | `envs/dev` **plans** in full and **applies** to a throwaway AWS emulator (52 resources), a second `plan` shows **no changes**, and `destroy` is clean. The emulator can't speak the protocol the AWS provider uses for CloudWatch, so the apply stubs the dashboard and alarms (they're planned, and covered by the module tests). |
| Trivy (CI security job) | No HIGH/CRITICAL misconfigurations |

The emulator catches apply-time mistakes (wrong references, dependency cycles, module misuse) but not every AWS rule (IAM policy evaluation, quotas, region availability). The first real `terraform plan` in the dev account is still the final check.

## Guardrails worth knowing

- The SCP denies `iam:CreateUser`/`iam:CreateAccessKey` in member accounts. If a third-party tool ever truly needs an IAM user (rare; e.g. SES **SMTP** credentials), change the SCP deliberately. The app sends mail through the SES **API** with its task role instead.
- State buckets, the Organization and the accounts have `prevent_destroy`; removing them is a manual, deliberate act.
