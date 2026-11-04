# Yii3 Enterprise Platform — Author: ShokirjonMK — https://github.com/ShokirjonMK/Yii3-Enterprise-Platform

locals {
  name_prefix = "${var.project}-${var.environment}"

  common_tags = merge({
    project     = var.project
    environment = var.environment
    managed-by  = "terraform"
    Author      = "ShokirjonMK"
    source      = "https://github.com/ShokirjonMK/Yii3-Enterprise-Platform"
  }, var.extra_tags)
}

module "namespace" {
  source = "./modules/namespace"

  name        = var.namespace
  environment = var.environment
  quota       = var.quota
}

module "storage" {
  source = "./modules/s3-buckets"

  name_prefix                     = local.name_prefix
  create_iam                      = var.create_iam
  backup_retention_days           = var.backup_retention_days
  files_noncurrent_retention_days = var.files_noncurrent_retention_days
  kms_key_arn                     = var.kms_key_arn
  enable_public_access_block      = var.s3_aws_bucket_controls
  enforce_tls_policy              = var.s3_aws_bucket_controls
  tags                            = local.common_tags
}
