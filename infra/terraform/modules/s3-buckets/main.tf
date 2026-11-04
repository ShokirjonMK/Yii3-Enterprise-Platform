# Yii3 Enterprise Platform — Author: ShokirjonMK — https://github.com/ShokirjonMK/Yii3-Enterprise-Platform

# S3-compatible object storage for the platform:
#   <prefix>-files    application uploads/exports (S3_BUCKET), versioned, private, encrypted
#   <prefix>-backups  CloudNativePG base backups + WAL archive (barmanObjectStore), versioned, lifecycle-expired
#
# Two least-privilege identities:
#   <prefix>-app      read/write objects in the files bucket only (no bucket admin, no delete of versions)
#   <prefix>-backup   read/write under the cnpg/ prefix of the backups bucket only
#
# Notes for S3-compatible stores: MinIO/Ceph implement versioning, SSE-S3, lifecycle and (MinIO/Ceph) IAM-style
# users/policies; public-access-block is AWS-specific — disable it with enable_public_access_block = false there.
terraform {
  required_providers {
    aws = {
      source = "hashicorp/aws"
    }
  }
}

locals {
  files_bucket   = "${var.name_prefix}-files"
  backups_bucket = "${var.name_prefix}-backups"
  sse_algorithm  = var.kms_key_arn == null ? "AES256" : "aws:kms"
  buckets = {
    files   = local.files_bucket
    backups = local.backups_bucket
  }
}

resource "aws_s3_bucket" "this" {
  for_each = local.buckets

  bucket        = each.value
  force_destroy = false
  tags          = merge(var.tags, { purpose = each.key })
}

resource "aws_s3_bucket_versioning" "this" {
  for_each = aws_s3_bucket.this

  bucket = each.value.id
  versioning_configuration {
    status = "Enabled"
  }
}

resource "aws_s3_bucket_server_side_encryption_configuration" "this" {
  for_each = aws_s3_bucket.this

  bucket = each.value.id
  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm     = local.sse_algorithm
      kms_master_key_id = var.kms_key_arn
    }
    bucket_key_enabled = var.kms_key_arn != null
  }
}

resource "aws_s3_bucket_public_access_block" "this" {
  for_each = var.enable_public_access_block ? aws_s3_bucket.this : {}

  bucket                  = each.value.id
  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_ownership_controls" "this" {
  for_each = var.enable_public_access_block ? aws_s3_bucket.this : {}

  bucket = each.value.id
  rule {
    object_ownership = "BucketOwnerEnforced"
  }
}

resource "aws_s3_bucket_lifecycle_configuration" "backups" {
  bucket = aws_s3_bucket.this["backups"].id

  rule {
    id     = "expire-old-backups"
    status = "Enabled"
    filter {
      prefix = "cnpg/"
    }
    # CNPG enforces its own retentionPolicy; this is the safety net for orphaned objects (keep it longer).
    expiration {
      days = var.backup_retention_days
    }
    noncurrent_version_expiration {
      noncurrent_days = 7
    }
    abort_incomplete_multipart_upload {
      days_after_initiation = 2
    }
  }

  depends_on = [aws_s3_bucket_versioning.this]
}

resource "aws_s3_bucket_lifecycle_configuration" "files" {
  bucket = aws_s3_bucket.this["files"].id

  rule {
    id     = "noncurrent-versions"
    status = "Enabled"
    filter {}
    noncurrent_version_expiration {
      noncurrent_days = var.files_noncurrent_retention_days
    }
    abort_incomplete_multipart_upload {
      days_after_initiation = 2
    }
  }

  depends_on = [aws_s3_bucket_versioning.this]
}

# Deny any non-TLS access to both buckets.
data "aws_iam_policy_document" "tls_only" {
  for_each = aws_s3_bucket.this

  statement {
    sid     = "DenyInsecureTransport"
    effect  = "Deny"
    actions = ["s3:*"]
    resources = [
      each.value.arn,
      "${each.value.arn}/*",
    ]
    principals {
      type        = "*"
      identifiers = ["*"]
    }
    condition {
      test     = "Bool"
      variable = "aws:SecureTransport"
      values   = ["false"]
    }
  }
}

resource "aws_s3_bucket_policy" "tls_only" {
  for_each = var.enforce_tls_policy ? aws_s3_bucket.this : {}

  bucket = each.value.id
  policy = data.aws_iam_policy_document.tls_only[each.key].json
}

# ---------------------------------------------------------------- IAM (least privilege)
data "aws_iam_policy_document" "app" {
  statement {
    sid       = "ListFilesBucket"
    actions   = ["s3:ListBucket", "s3:GetBucketLocation"]
    resources = [aws_s3_bucket.this["files"].arn]
  }
  statement {
    sid = "ObjectsInFilesBucket"
    actions = [
      "s3:GetObject",
      "s3:PutObject",
      "s3:DeleteObject",
      "s3:AbortMultipartUpload",
      "s3:ListMultipartUploadParts",
    ]
    resources = ["${aws_s3_bucket.this["files"].arn}/*"]
  }
}

data "aws_iam_policy_document" "backup" {
  statement {
    sid       = "ListBackupsPrefix"
    actions   = ["s3:ListBucket", "s3:GetBucketLocation"]
    resources = [aws_s3_bucket.this["backups"].arn]
    condition {
      test     = "StringLike"
      variable = "s3:prefix"
      values   = ["cnpg/*", "cnpg"]
    }
  }
  statement {
    sid = "ObjectsUnderCnpgPrefix"
    actions = [
      "s3:GetObject",
      "s3:PutObject",
      "s3:DeleteObject",
      "s3:AbortMultipartUpload",
      "s3:ListMultipartUploadParts",
    ]
    resources = ["${aws_s3_bucket.this["backups"].arn}/cnpg/*"]
  }
}

resource "aws_iam_user" "app" {
  count = var.create_iam ? 1 : 0
  name  = "${var.name_prefix}-app"
  tags  = var.tags
}

resource "aws_iam_user_policy" "app" {
  count  = var.create_iam ? 1 : 0
  name   = "${var.name_prefix}-app-files"
  user   = aws_iam_user.app[0].name
  policy = data.aws_iam_policy_document.app.json
}

resource "aws_iam_access_key" "app" {
  count = var.create_iam ? 1 : 0
  user  = aws_iam_user.app[0].name
}

resource "aws_iam_user" "backup" {
  count = var.create_iam ? 1 : 0
  name  = "${var.name_prefix}-backup"
  tags  = var.tags
}

resource "aws_iam_user_policy" "backup" {
  count  = var.create_iam ? 1 : 0
  name   = "${var.name_prefix}-cnpg-backups"
  user   = aws_iam_user.backup[0].name
  policy = data.aws_iam_policy_document.backup.json
}

resource "aws_iam_access_key" "backup" {
  count = var.create_iam ? 1 : 0
  user  = aws_iam_user.backup[0].name
}
