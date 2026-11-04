output "files_bucket" {
  description = "Files bucket name."
  value       = aws_s3_bucket.this["files"].id
}

output "backups_bucket" {
  description = "Backups bucket name."
  value       = aws_s3_bucket.this["backups"].id
}

output "app_access_key_id" {
  description = "Application access key id (null when create_iam = false)."
  value       = var.create_iam ? aws_iam_access_key.app[0].id : null
}

output "app_secret_access_key" {
  description = "Application secret key."
  value       = var.create_iam ? aws_iam_access_key.app[0].secret : null
  sensitive   = true
}

output "backup_access_key_id" {
  description = "CNPG backup access key id."
  value       = var.create_iam ? aws_iam_access_key.backup[0].id : null
}

output "backup_secret_access_key" {
  description = "CNPG backup secret key."
  value       = var.create_iam ? aws_iam_access_key.backup[0].secret : null
  sensitive   = true
}
