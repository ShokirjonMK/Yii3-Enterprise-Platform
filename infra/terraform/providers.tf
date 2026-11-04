provider "kubernetes" {
  # Path/context come from variables; the kubeconfig file itself is provided by the operator or CI (protected var).
  config_path    = var.kubeconfig_path
  config_context = var.kube_context
}

# Generic S3-compatible provider: AWS S3, MinIO, Ceph RGW, SeaweedFS, Yandex/OVH/Hetzner object storage.
# For non-AWS endpoints the AWS-specific account/region validations are skipped.
provider "aws" {
  region = var.s3_region

  s3_use_path_style           = var.s3_force_path_style
  skip_credentials_validation = var.s3_skip_aws_validations
  skip_metadata_api_check     = var.s3_skip_aws_validations
  skip_region_validation      = var.s3_skip_aws_validations
  skip_requesting_account_id  = var.s3_skip_aws_validations

  endpoints {
    s3  = var.s3_endpoint
    iam = var.iam_endpoint
  }

  default_tags {
    tags = local.common_tags
  }
}
