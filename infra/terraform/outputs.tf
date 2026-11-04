output "namespace" {
  description = "Application namespace."
  value       = module.namespace.name
}

output "files_bucket" {
  description = "Bucket for application files (S3_BUCKET)."
  value       = module.storage.files_bucket
}

output "backups_bucket" {
  description = "Bucket for CloudNativePG base backups + WAL archive (barmanObjectStore destinationPath)."
  value       = module.storage.backups_bucket
}

output "app_access_key_id" {
  description = "Access key id for the application (S3_KEY). The secret is only in state — move it to the secret manager."
  value       = module.storage.app_access_key_id
}

output "app_secret_access_key" {
  description = "Secret key for the application (S3_SECRET)."
  value       = module.storage.app_secret_access_key
  sensitive   = true
}

output "backup_access_key_id" {
  description = "Access key id for CNPG backups."
  value       = module.storage.backup_access_key_id
}

output "backup_secret_access_key" {
  description = "Secret key for CNPG backups."
  value       = module.storage.backup_secret_access_key
  sensitive   = true
}
