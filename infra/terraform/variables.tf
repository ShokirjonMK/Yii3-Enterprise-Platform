variable "environment" {
  description = "Environment name (staging | production)."
  type        = string

  validation {
    condition     = contains(["staging", "production"], var.environment)
    error_message = "environment must be staging or production."
  }
}

variable "project" {
  description = "Short project slug used in resource names."
  type        = string
  default     = "yep"
}

# ---------------------------------------------------------------- Kubernetes
variable "kubeconfig_path" {
  description = "Path to the kubeconfig file (null = provider defaults / KUBE_CONFIG_PATH)."
  type        = string
  default     = null
}

variable "kube_context" {
  description = "kubeconfig context to use (null = current context)."
  type        = string
  default     = null
}

variable "namespace" {
  description = "Application namespace (matches infra/kubernetes overlays)."
  type        = string
}

variable "quota" {
  description = "ResourceQuota hard limits for the namespace."
  type = object({
    requests_cpu    = string
    requests_memory = string
    limits_cpu      = string
    limits_memory   = string
    pods            = number
    pvcs            = number
    storage         = string
  })
}

# ---------------------------------------------------------------- Object storage
variable "s3_endpoint" {
  description = "S3 API endpoint (null = AWS default endpoint)."
  type        = string
  default     = null
}

variable "iam_endpoint" {
  description = "IAM API endpoint for S3-compatible stores that implement it (MinIO/Ceph); null = AWS IAM."
  type        = string
  default     = null
}

variable "s3_region" {
  description = "Region for the S3 provider."
  type        = string
  default     = "us-east-1"
}

variable "s3_force_path_style" {
  description = "Use path-style addressing (required by most self-hosted S3 implementations)."
  type        = bool
  default     = true
}

variable "s3_skip_aws_validations" {
  description = "Skip AWS-only account/region/metadata checks (true for non-AWS S3-compatible endpoints)."
  type        = bool
  default     = true
}

variable "create_iam" {
  description = "Create IAM users/policies/access keys (disable when the store has no IAM API)."
  type        = bool
  default     = true
}

variable "backup_retention_days" {
  description = "Days to keep PostgreSQL base backups / WAL in the backups bucket (must exceed CNPG retentionPolicy)."
  type        = number
  default     = 35
}

variable "files_noncurrent_retention_days" {
  description = "Days to keep non-current object versions in the files bucket."
  type        = number
  default     = 90
}

variable "kms_key_arn" {
  description = "KMS key for SSE-KMS (null = SSE-S3 AES256, which is what S3-compatible stores support)."
  type        = string
  default     = null
}

variable "extra_tags" {
  description = "Additional tags for cloud resources."
  type        = map(string)
  default     = {}
}

variable "s3_aws_bucket_controls" {
  description = "Apply AWS-only bucket controls (public-access-block, ownership controls, TLS-only bucket policy). Set false for MinIO/Ceph/SeaweedFS."
  type        = bool
  default     = true
}
