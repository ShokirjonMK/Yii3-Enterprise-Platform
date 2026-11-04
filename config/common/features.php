<?php

declare(strict_types=1);

/**
 * Optional platform features — single source of truth for:
 *   - feature flags seeded on install (default = state on a FRESH install: unused things are OFF),
 *   - which route files are switched off (404) when the flag is disabled,
 *   - which admin menu entries are hidden,
 *   - which background processes pause (relay/consumers honour messaging.kafka).
 *
 * Every flag can be toggled in Admin → 21 System Settings → Feature flags. Core controls (users, RBAC, settings,
 * audit, security center, jobs, dashboard) are always on; critical security controls are separate flags that
 * cannot be disabled (security.audit, security.mfa_enforcement).
 *
 * Module flags (module.<code>) are created by each module's own migration and follow the same mechanism.
 *
 * Author: ShokirjonMK
 */
return [
    // ------------------------------------------------------------------ admin sections
    'feature.multitenancy' => ['name' => 'Multi-tenancy administration', 'description' => 'Section 05: tenants management.', 'default' => true,
        'admin' => ['05-tenants.php'], 'menu' => ['admin.tenants']],
    'feature.api_management' => ['name' => 'API management', 'description' => 'Section 06: API clients and personal tokens.', 'default' => true,
        'admin' => ['06-api.php'], 'menu' => ['admin.api']],
    'feature.integrations' => ['name' => 'External integrations', 'description' => 'Section 07: outbound connectors (HTTP client, circuit breaker).', 'default' => false,
        'admin' => ['07-integrations.php'], 'menu' => ['admin.integrations']],
    'feature.webhooks' => ['name' => 'Inbound webhooks', 'description' => 'POST /webhooks/{code} endpoints (HMAC-signed).', 'default' => false,
        'system' => ['webhooks.php']],
    'feature.notifications' => ['name' => 'Notifications administration', 'description' => 'Section 08: channels, templates, deliveries (in-app notifications always work).', 'default' => true,
        'admin' => ['08-notifications.php'], 'menu' => ['admin.notifications']],
    'feature.workflow' => ['name' => 'Workflow & approvals', 'description' => 'Section 09: workflow definitions and approval inbox.', 'default' => false,
        'admin' => ['09-workflow.php'], 'menu' => ['admin.workflow']],
    'messaging.kafka' => ['name' => 'Kafka event streaming', 'description' => 'Section 11 + outbox relay + consumers. When OFF events wait safely in the outbox and consumers pause.', 'default' => false,
        'admin' => ['11-kafka.php'], 'menu' => ['admin.kafka'], 'dependencies' => ['service:kafka']],
    'feature.database_admin' => ['name' => 'PostgreSQL administration', 'description' => 'Section 12.', 'default' => true,
        'admin' => ['12-postgres.php'], 'menu' => ['admin.postgres']],
    'feature.redis_admin' => ['name' => 'Redis administration', 'description' => 'Section 13.', 'default' => true,
        'admin' => ['13-redis.php'], 'menu' => ['admin.redis']],
    'feature.storage' => ['name' => 'File storage & search', 'description' => 'Section 14 (S3 object storage).', 'default' => true,
        'admin' => ['14-storage.php'], 'menu' => ['admin.storage']],
    'feature.infrastructure' => ['name' => 'Infrastructure (Kubernetes)', 'description' => 'Section 15: cluster inventory and scaling.', 'default' => false,
        'admin' => ['15-infrastructure.php'], 'menu' => ['admin.infrastructure']],
    'feature.deployments' => ['name' => 'CI/CD & deployments', 'description' => 'Section 16: deployment requests and GitLab pipelines.', 'default' => false,
        'admin' => ['16-deployments.php'], 'menu' => ['admin.deployments']],
    'feature.monitoring' => ['name' => 'Monitoring dashboard', 'description' => 'Section 17.', 'default' => true,
        'admin' => ['17-monitoring.php'], 'menu' => ['admin.monitoring']],
    'feature.backups' => ['name' => 'Backup & recovery', 'description' => 'Section 19: backups to S3 and restore verification.', 'default' => true,
        'admin' => ['19-backups.php'], 'menu' => ['admin.backups']],
    'feature.incidents' => ['name' => 'Incident management', 'description' => 'Section 24 + automatic incidents from health probes.', 'default' => true,
        'admin' => ['24-incidents.php'], 'menu' => ['admin.incidents']],
    'feature.business_modules' => ['name' => 'Business modules registry', 'description' => 'Section 23.', 'default' => true,
        'admin' => ['23-modules.php'], 'menu' => ['admin.modules']],

    // ------------------------------------------------------------------ API
    'api.v1' => ['name' => 'REST API v1', 'description' => '/api/v1 endpoints.', 'default' => true],
    'api.docs' => ['name' => 'API documentation (Swagger UI)', 'description' => '/api/docs and /api/v1/openapi.json.', 'default' => true],

    // ------------------------------------------------------------------ logging & observability
    'audit.user_activity' => ['name' => 'User activity log', 'description' => 'Records every authenticated request (user, page/API, IP, status, duration) — written after the response is sent.', 'default' => true],
    'logging.database' => ['name' => 'Application logs in admin', 'description' => 'Stores warning/error log records in the database for the Monitoring → Logs viewer (stderr JSON logs are always written).', 'default' => true],
    'observability.metrics' => ['name' => 'Prometheus metrics', 'description' => 'Request metrics and the /metrics endpoint.', 'default' => true,
        'system_routes' => ['system.metrics']],
    'observability.tracing' => ['name' => 'OpenTelemetry tracing', 'description' => 'Per-request spans exported to OTLP (requires OTEL_ENABLED=true and a collector).', 'default' => false],
];
