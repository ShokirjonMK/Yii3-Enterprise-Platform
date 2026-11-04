# Yii3 Enterprise Platform — Author: ShokirjonMK — https://github.com/ShokirjonMK/Yii3-Enterprise-Platform

# Application namespace with guard rails: Pod Security "restricted", quota, default container limits and a
# default-deny NetworkPolicy. The allow rules (app -> postgres/redis/kafka/s3/otel, ingress -> web) are shipped
# with the workloads in infra/kubernetes/base/networkpolicies.yaml.
terraform {
  required_providers {
    kubernetes = {
      source = "hashicorp/kubernetes"
    }
  }
}

variable "name" {
  description = "Namespace name."
  type        = string
}

variable "environment" {
  description = "Environment label value."
  type        = string
}

variable "quota" {
  description = "ResourceQuota hard limits."
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

resource "kubernetes_namespace_v1" "this" {
  metadata {
    name = var.name
    labels = {
      "app.kubernetes.io/part-of"                  = "yii3-enterprise-platform"
      "environment"                                = var.environment
      "pod-security.kubernetes.io/enforce"         = "restricted"
      "pod-security.kubernetes.io/enforce-version" = "latest"
      "pod-security.kubernetes.io/audit"           = "restricted"
      "pod-security.kubernetes.io/warn"            = "restricted"
    }
    annotations = {
      "author" = "ShokirjonMK"
      "source" = "https://github.com/ShokirjonMK/Yii3-Enterprise-Platform"
    }
  }
}

resource "kubernetes_resource_quota_v1" "this" {
  metadata {
    name      = "platform-quota"
    namespace = kubernetes_namespace_v1.this.metadata[0].name
  }
  spec {
    hard = {
      "requests.cpu"           = var.quota.requests_cpu
      "requests.memory"        = var.quota.requests_memory
      "limits.cpu"             = var.quota.limits_cpu
      "limits.memory"          = var.quota.limits_memory
      "pods"                   = tostring(var.quota.pods)
      "persistentvolumeclaims" = tostring(var.quota.pvcs)
      "requests.storage"       = var.quota.storage
      "services.loadbalancers" = "0"
      "services.nodeports"     = "0"
    }
  }
}

resource "kubernetes_limit_range_v1" "this" {
  metadata {
    name      = "platform-defaults"
    namespace = kubernetes_namespace_v1.this.metadata[0].name
  }
  spec {
    limit {
      type = "Container"
      default = {
        cpu    = "500m"
        memory = "512Mi"
      }
      default_request = {
        cpu    = "100m"
        memory = "128Mi"
      }
      max = {
        cpu    = "4"
        memory = "8Gi"
      }
    }
  }
}

resource "kubernetes_network_policy_v1" "default_deny" {
  metadata {
    name      = "default-deny-all"
    namespace = kubernetes_namespace_v1.this.metadata[0].name
  }
  spec {
    pod_selector {}
    policy_types = ["Ingress", "Egress"]
  }
}

# DNS must be allowed for every pod once egress is denied by default.
resource "kubernetes_network_policy_v1" "allow_dns" {
  metadata {
    name      = "allow-dns-egress"
    namespace = kubernetes_namespace_v1.this.metadata[0].name
  }
  spec {
    pod_selector {}
    policy_types = ["Egress"]
    egress {
      to {
        namespace_selector {
          match_labels = {
            "kubernetes.io/metadata.name" = "kube-system"
          }
        }
        pod_selector {
          match_labels = {
            "k8s-app" = "kube-dns"
          }
        }
      }
      ports {
        protocol = "UDP"
        port     = "53"
      }
      ports {
        protocol = "TCP"
        port     = "53"
      }
    }
  }
}

output "name" {
  description = "Namespace name."
  value       = kubernetes_namespace_v1.this.metadata[0].name
}
