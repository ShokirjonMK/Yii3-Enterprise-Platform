variable "name_prefix" {
  description = "Prefix for bucket and IAM user names (e.g. yep-production)."
  type        = string
}

variable "create_iam" {
  description = "Create IAM users, inline policies and access keys."
  type        = bool
  default     = true
}

variable "enable_public_access_block" {
  description = "Apply AWS public-access-block + BucketOwnerEnforced (disable for stores that do not implement it)."
  type        = bool
  default     = true
}

variable "enforce_tls_policy" {
  description = "Attach a bucket policy denying non-TLS requests."
  type        = bool
  default     = true
}

variable "backup_retention_days" {
  description = "Expiration (days) for objects under cnpg/ in the backups bucket."
  type        = number
  default     = 35

  validation {
    condition     = var.backup_retention_days >= 8
    error_message = "backup_retention_days must be at least 8 (longer than the CNPG retention policy)."
  }
}

variable "files_noncurrent_retention_days" {
  description = "Days to keep non-current versions in the files bucket."
  type        = number
  default     = 90
}

variable "kms_key_arn" {
  description = "KMS key ARN for SSE-KMS; null = SSE-S3 (AES256)."
  type        = string
  default     = null
}

variable "tags" {
  description = "Tags for all resources."
  type        = map(string)
  default     = {}
}
