# Yii3 Enterprise Platform — Author: ShokirjonMK — https://github.com/ShokirjonMK/Yii3-Enterprise-Platform
#
# Yii3 Enterprise Platform — infrastructure skeleton (Terraform >= 1.6).
#
# Scope: the cluster-side namespace (quota, limits, default-deny network policy) and S3-compatible object storage
# (files + backups buckets, least-privilege IAM users/policies). The Kubernetes cluster itself, DNS and the data
# operators (CloudNativePG, Strimzi, external-secrets, KEDA) are provisioned outside this module.
#
# Usage:
#   terraform init -backend-config=environments/<env>.backend.hcl     # remote state (S3/GitLab HTTP backend)
#   terraform plan -var-file=environments/<env>.tfvars
#
# Credentials are NEVER put in tfvars: use KUBE_CONFIG_PATH / KUBECONFIG and AWS_ACCESS_KEY_ID /
# AWS_SECRET_ACCESS_KEY (or GitLab CI masked+protected variables) in the environment.
terraform {
  required_version = ">= 1.6.0"

  required_providers {
    kubernetes = {
      source  = "hashicorp/kubernetes"
      version = "~> 2.35"
    }
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.80"
    }
  }

  # Partial configuration — supply bucket/key/endpoint via -backend-config (see environments/*.backend.hcl.example).
  # `terraform init -backend=false` is used for validation in CI.
  backend "s3" {}
}
