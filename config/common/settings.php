<?php

declare(strict_types=1);

/**
 * Typed settings registry. Values resolve in this order:
 *   environment variable (when `env` is set and defined) → user scope → tenant scope → global → default
 *
 * Types: string, text, int, bool, enum, json, secret (stored encrypted, never displayed).
 * `critical` settings require a superadmin and step-up re-authentication to change.
 */
return [
    // ---------------------------------------------------------------- General
    'app.name' => ['type' => 'string', 'default' => 'Yii3 Enterprise Platform', 'group' => 'General', 'label' => 'Application name', 'env' => 'APP_NAME', 'max' => 120],
    'app.support_email' => ['type' => 'string', 'default' => '', 'group' => 'General', 'label' => 'Support e-mail', 'format' => 'email'],
    'app.default_locale' => ['type' => 'enum', 'default' => 'uz', 'options' => ['uz', 'ru', 'en'], 'group' => 'General', 'label' => 'Default language', 'scopes' => ['global', 'tenant']],
    'app.timezone' => ['type' => 'string', 'default' => 'Asia/Tashkent', 'group' => 'General', 'label' => 'Default time zone', 'scopes' => ['global', 'tenant', 'user']],
    'app.maintenance_mode' => ['type' => 'bool', 'default' => false, 'group' => 'General', 'label' => 'Maintenance mode (only administrators can sign in)', 'critical' => true],
    'app.maintenance_message' => ['type' => 'text', 'default' => '', 'group' => 'General', 'label' => 'Maintenance banner text'],

    // ---------------------------------------------------------------- Security
    'security.session_idle_minutes' => ['type' => 'int', 'default' => 30, 'min' => 5, 'max' => 480, 'group' => 'Security', 'label' => 'Session idle timeout (minutes)', 'critical' => true],
    'security.password_max_age_days' => ['type' => 'int', 'default' => 180, 'min' => 0, 'max' => 3650, 'group' => 'Security', 'label' => 'Password maximum age (days, 0 = never)'],
    'security.login_max_attempts' => ['type' => 'int', 'default' => 5, 'min' => 3, 'max' => 20, 'group' => 'Security', 'label' => 'Failed logins before lockout', 'critical' => true],
    'security.allowed_admin_networks' => ['type' => 'json', 'default' => [], 'group' => 'Security', 'label' => 'Admin panel IP allowlist (CIDR list, empty = any)', 'critical' => true],
    'security.new_login_notification' => ['type' => 'bool', 'default' => true, 'group' => 'Security', 'label' => 'Notify users about new sign-ins'],

    // ---------------------------------------------------------------- API
    'api.default_page_size' => ['type' => 'int', 'default' => 25, 'min' => 1, 'max' => 200, 'group' => 'API', 'label' => 'Default page size'],
    'api.rate_limit_per_minute' => ['type' => 'int', 'default' => 600, 'min' => 10, 'max' => 100000, 'group' => 'API', 'label' => 'Default rate limit per client (requests/minute)'],
    'api.cors_origins' => ['type' => 'json', 'default' => [], 'group' => 'API', 'label' => 'CORS allowed origins (exact list)'],

    // ---------------------------------------------------------------- Notifications
    'notifications.smtp_dsn' => ['type' => 'secret', 'default' => null, 'group' => 'Notifications', 'label' => 'SMTP connection (DSN, e.g. smtp://user:pass@smtp.example.com:587)', 'env' => 'MAILER_DSN'],
    'notifications.mail_from' => ['type' => 'string', 'default' => 'noreply@platform.local', 'group' => 'Notifications', 'label' => 'Sender address (From)', 'format' => 'email', 'env' => 'MAIL_FROM'],
    'notifications.telegram_bot_token' => ['type' => 'secret', 'default' => null, 'group' => 'Notifications', 'label' => 'Telegram bot token', 'env' => 'TELEGRAM_BOT_TOKEN'],
    'notifications.telegram_alert_chat' => ['type' => 'string', 'default' => '', 'group' => 'Notifications', 'label' => 'Telegram alert chat id', 'env' => 'TELEGRAM_ALERT_CHAT_ID'],
    'notifications.sms_gateway_url' => ['type' => 'string', 'default' => '', 'group' => 'Notifications', 'label' => 'SMS gateway endpoint (HTTPS)', 'format' => 'url'],
    'notifications.sms_gateway_token' => ['type' => 'secret', 'default' => null, 'group' => 'Notifications', 'label' => 'SMS gateway token'],
    'notifications.sms_sender' => ['type' => 'string', 'default' => '', 'group' => 'Notifications', 'label' => 'SMS sender id', 'max' => 11],
    'notifications.vapid_public_key' => ['type' => 'string', 'default' => '', 'group' => 'Notifications', 'label' => 'Web Push VAPID public key'],
    'notifications.vapid_private_key' => ['type' => 'secret', 'default' => null, 'group' => 'Notifications', 'label' => 'Web Push VAPID private key'],

    // ---------------------------------------------------------------- Retention
    'retention.audit_months' => ['type' => 'int', 'default' => 84, 'min' => 12, 'max' => 240, 'group' => 'Retention', 'label' => 'Audit log retention (months)', 'critical' => true],
    'retention.integration_logs_days' => ['type' => 'int', 'default' => 30, 'min' => 1, 'max' => 365, 'group' => 'Retention', 'label' => 'Integration log retention (days)'],
    'retention.jobs_days' => ['type' => 'int', 'default' => 14, 'min' => 1, 'max' => 365, 'group' => 'Retention', 'label' => 'Finished job retention (days)'],
    'retention.login_attempts_days' => ['type' => 'int', 'default' => 90, 'min' => 7, 'max' => 730, 'group' => 'Retention', 'label' => 'Login attempt retention (days)'],
    'retention.activity_days' => ['type' => 'int', 'default' => 90, 'min' => 1, 'max' => 3650, 'group' => 'Retention', 'label' => 'User activity log retention (days)'],
    'retention.app_logs_days' => ['type' => 'int', 'default' => 30, 'min' => 1, 'max' => 3650, 'group' => 'Retention', 'label' => 'Application log retention (days)'],
    'retention.backups_days' => ['type' => 'int', 'default' => 30, 'min' => 1, 'max' => 3650, 'group' => 'Retention', 'label' => 'Backup retention (days)'],

    // ---------------------------------------------------------------- Operations
    'ops.gitlab_url' => ['type' => 'string', 'default' => '', 'group' => 'Operations', 'label' => 'GitLab base URL', 'format' => 'url', 'env' => 'GITLAB_URL'],
    'ops.gitlab_project_id' => ['type' => 'string', 'default' => '', 'group' => 'Operations', 'label' => 'GitLab project id', 'env' => 'GITLAB_PROJECT_ID'],
    'ops.gitlab_token' => ['type' => 'secret', 'default' => null, 'group' => 'Operations', 'label' => 'GitLab API token (trigger pipelines)', 'env' => 'GITLAB_TOKEN', 'critical' => true],
    'ops.kubernetes_api' => ['type' => 'string', 'default' => '', 'group' => 'Operations', 'label' => 'Kubernetes API URL', 'format' => 'url', 'env' => 'K8S_API_URL'],
    'ops.kubernetes_namespace' => ['type' => 'string', 'default' => 'platform', 'group' => 'Operations', 'label' => 'Kubernetes namespace', 'env' => 'K8S_NAMESPACE'],
    'ops.kubernetes_token' => ['type' => 'secret', 'default' => null, 'group' => 'Operations', 'label' => 'Kubernetes service-account token', 'env' => 'K8S_TOKEN', 'critical' => true],
    // ---------------------------------------------------------------- Observability (env value wins when set)
    'observability.prometheus_url' => ['type' => 'string', 'default' => '', 'group' => 'Observability', 'label' => 'Prometheus API URL (internal)', 'format' => 'url', 'env' => 'PROMETHEUS_URL'],
    'observability.alertmanager_url' => ['type' => 'string', 'default' => '', 'group' => 'Observability', 'label' => 'Alertmanager API URL (internal)', 'format' => 'url', 'env' => 'ALERTMANAGER_URL'],
    'observability.grafana_url' => ['type' => 'string', 'default' => '', 'group' => 'Observability', 'label' => 'Grafana URL (link)', 'format' => 'url', 'env' => 'GRAFANA_URL'],
    'observability.jaeger_url' => ['type' => 'string', 'default' => '', 'group' => 'Observability', 'label' => 'Jaeger UI URL (link)', 'format' => 'url', 'env' => 'JAEGER_URL'],
    'observability.prometheus_public_url' => ['type' => 'string', 'default' => '', 'group' => 'Observability', 'label' => 'Prometheus UI URL (link)', 'format' => 'url', 'env' => 'PROMETHEUS_PUBLIC_URL'],
    'observability.alertmanager_public_url' => ['type' => 'string', 'default' => '', 'group' => 'Observability', 'label' => 'Alertmanager UI URL (link)', 'format' => 'url', 'env' => 'ALERTMANAGER_PUBLIC_URL'],
    'ops.backup_offsite_bucket' => ['type' => 'string', 'default' => '', 'group' => 'Operations', 'label' => 'Off-site backup bucket (S3)', 'env' => 'BACKUP_OFFSITE_BUCKET'],
];
