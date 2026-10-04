# IAM Identity Center (SSO): the only way people sign in to the dev and prod accounts.
#
# Identity Center has to be enabled once in the console (management account → IAM Identity
# Center → Enable) before setting enable_identity_center = true. Add people to the groups
# in Identity Center (or connect an external identity provider).

locals {
  sso_enabled = var.enable_identity_center
  accounts = {
    dev  = aws_organizations_account.dev.id
    prod = aws_organizations_account.prod.id
  }

  permission_sets = {
    Administrator = { policy = "arn:aws:iam::aws:policy/AdministratorAccess", session = "PT4H" }
    Developer     = { policy = "arn:aws:iam::aws:policy/PowerUserAccess", session = "PT8H" }
    ReadOnly      = { policy = "arn:aws:iam::aws:policy/ReadOnlyAccess", session = "PT8H" }
  }

  groups = {
    platform-admins = "Platform engineers: full access to dev and prod"
    developers      = "Engineers: build in dev, read-only in prod"
    read-only       = "QA, support, managers: read-only everywhere"
  }

  # Who gets which permission set in which account.
  assignments = {
    "platform-admins/dev/Administrator"  = { group = "platform-admins", account = "dev", set = "Administrator" }
    "platform-admins/prod/Administrator" = { group = "platform-admins", account = "prod", set = "Administrator" }
    "developers/dev/Developer"           = { group = "developers", account = "dev", set = "Developer" }
    "developers/prod/ReadOnly"           = { group = "developers", account = "prod", set = "ReadOnly" }
    "read-only/dev/ReadOnly"             = { group = "read-only", account = "dev", set = "ReadOnly" }
    "read-only/prod/ReadOnly"            = { group = "read-only", account = "prod", set = "ReadOnly" }
  }
}

data "aws_ssoadmin_instances" "this" {
  count = local.sso_enabled ? 1 : 0
}

locals {
  sso_instance_arn  = local.sso_enabled ? tolist(data.aws_ssoadmin_instances.this[0].arns)[0] : ""
  identity_store_id = local.sso_enabled ? tolist(data.aws_ssoadmin_instances.this[0].identity_store_ids)[0] : ""
}

resource "aws_identitystore_group" "this" {
  for_each = local.sso_enabled ? local.groups : {}

  identity_store_id = local.identity_store_id
  display_name      = each.key
  description       = each.value
}

resource "aws_ssoadmin_permission_set" "this" {
  for_each = local.sso_enabled ? local.permission_sets : {}

  instance_arn     = local.sso_instance_arn
  name             = each.key
  session_duration = each.value.session
}

resource "aws_ssoadmin_managed_policy_attachment" "this" {
  for_each = local.sso_enabled ? local.permission_sets : {}

  instance_arn       = local.sso_instance_arn
  permission_set_arn = aws_ssoadmin_permission_set.this[each.key].arn
  managed_policy_arn = each.value.policy
}

resource "aws_ssoadmin_account_assignment" "this" {
  for_each = local.sso_enabled ? local.assignments : {}

  instance_arn       = local.sso_instance_arn
  permission_set_arn = aws_ssoadmin_permission_set.this[each.value.set].arn
  principal_type     = "GROUP"
  principal_id       = aws_identitystore_group.this[each.value.group].group_id
  target_type        = "AWS_ACCOUNT"
  target_id          = local.accounts[each.value.account]
}
