<?php

declare(strict_types=1);

/**
 * Platform RBAC catalog. Business modules add their own files in this directory
 * (e.g. projects.php); `./yii rbac:sync` merges all files and upserts items idempotently.
 *
 * Role "superadmin" implicitly receives EVERY permission declared in any catalog file.
 *
 * Structure:
 *   'permissions' => [name => description]
 *   'roles'       => [role => ['description' => ..., 'permissions' => [...], 'children' => [roles...]]]
 */
return [
    'permissions' => [
        'admin.access' => 'Open the administration panel',
        'profile.self' => 'Manage own profile, password, MFA and sessions',

        'users.view' => 'View users and organizations',
        'users.manage' => 'Create, update, block users and organizations',
        'users.impersonate' => 'Sign in as another user (audited, MFA re-auth)',
        'users.login_history' => 'View the sign-in history of all users (own history is always visible)',
        'sessions.revoke' => 'Terminate other users\' sessions',

        'auth.view' => 'View authentication settings and SSO providers',
        'auth.manage' => 'Configure login methods and SSO providers',

        'rbac.view' => 'View roles and permissions',
        'rbac.manage' => 'Assign roles and edit role permissions',
        'rbac.grant_admin' => 'Grant administrative roles (superadmin only by default)',

        'tenants.view' => 'View tenants',
        'tenants.manage' => 'Create and manage tenants',
        'tenants.cross' => 'Access data across all tenants',

        'api.view' => 'View API clients, tokens and usage',
        'api.manage' => 'Manage API clients and keys',

        'integrations.view' => 'View external integrations',
        'integrations.manage' => 'Configure external integrations and webhooks',

        'notifications.view' => 'View notification channels, templates and deliveries',
        'notifications.manage' => 'Configure notification channels and templates',
        'notifications.send' => 'Send broadcast notifications',

        'workflow.view' => 'View workflows and approval requests',
        'workflow.manage' => 'Define workflows',
        'approvals.decide' => 'Approve or reject approval requests',

        'jobs.view' => 'View jobs, workers and schedules',
        'jobs.manage' => 'Retry/cancel jobs, pause queues, edit schedules',

        'kafka.view' => 'View Kafka topics, consumers and lag',
        'kafka.manage' => 'Provision topics, pause consumers, replay dead letters',

        'db.view' => 'View PostgreSQL health and statistics',
        'db.manage' => 'Run PostgreSQL maintenance operations',

        'redis.view' => 'View Redis health and statistics',
        'redis.manage' => 'Flush caches and manage Redis keys',

        'storage.view' => 'View storage and search status',
        'storage.manage' => 'Manage stored files and search indexes',

        'infra.view' => 'View infrastructure inventory and health',
        'infra.manage' => 'Operate infrastructure (scale, restart)',

        'deploy.view' => 'View deployments',
        'deploy.request' => 'Request a deployment',
        'deploy.approve' => 'Approve a deployment (four-eyes)',

        'monitoring.view' => 'View metrics, traces and alerts',

        'security.view' => 'View security events and posture',
        'security.manage' => 'Manage security policies, lockouts and secrets',

        'backup.view' => 'View backups and restore tests',
        'backup.manage' => 'Start backups and restore verification',

        'audit.view' => 'View the audit log',
        'audit.export' => 'Export and verify the audit log',

        'settings.view' => 'View system settings',
        'settings.manage' => 'Change system settings and feature flags',

        'devtools.use' => 'Use developer tools (Gii, API console) — non-production only',

        'modules.view' => 'View business modules',
        'modules.manage' => 'Enable or disable business modules',

        'incidents.view' => 'View incidents',
        'incidents.manage' => 'Open, update and resolve incidents',
    ],

    'roles' => [
        'user' => [
            'description' => 'Regular authenticated user',
            'permissions' => ['profile.self'],
        ],
        'auditor' => [
            'description' => 'Read-only access to all administration data and the audit log',
            'children' => ['user'],
            'permissions' => [
                'admin.access', 'users.view', 'users.login_history', 'auth.view', 'rbac.view', 'tenants.view', 'api.view', 'integrations.view',
                'notifications.view', 'workflow.view', 'jobs.view', 'kafka.view', 'db.view', 'redis.view', 'storage.view',
                'infra.view', 'deploy.view', 'monitoring.view', 'security.view', 'backup.view', 'audit.view', 'audit.export',
                'settings.view', 'modules.view', 'incidents.view',
            ],
        ],
        'developer' => [
            'description' => 'Engineering access: developer tools, read-only operations',
            'children' => ['user'],
            'permissions' => [
                'admin.access', 'devtools.use', 'api.view', 'integrations.view', 'jobs.view', 'kafka.view', 'db.view',
                'redis.view', 'monitoring.view', 'modules.view', 'incidents.view', 'deploy.view', 'settings.view',
            ],
        ],
        'business_admin' => [
            'description' => 'Manages business data, users of own tenant and approvals',
            'children' => ['user'],
            'permissions' => [
                'admin.access', 'users.view', 'users.manage', 'workflow.view', 'workflow.manage', 'approvals.decide',
                'notifications.view', 'notifications.send', 'modules.view', 'audit.view',
            ],
        ],
        'security_admin' => [
            'description' => 'Security officer: SSO, sessions, security center, audit',
            'children' => ['auditor'],
            'permissions' => [
                'auth.manage', 'sessions.revoke', 'security.manage', 'rbac.manage',
            ],
        ],
        'devops_sre' => [
            'description' => 'Operations: infrastructure, data stores, jobs, deployments, backups, incidents',
            'children' => ['auditor'],
            'permissions' => [
                'jobs.manage', 'kafka.manage', 'db.manage', 'redis.manage', 'storage.manage', 'infra.manage',
                'deploy.request', 'backup.manage', 'incidents.manage', 'devtools.use',
            ],
        ],
        'platform_admin' => [
            'description' => 'Platform administrator (all tenants), without break-glass operations',
            'children' => ['auditor', 'business_admin'],
            'permissions' => [
                'users.manage', 'sessions.revoke', 'auth.manage', 'rbac.manage', 'tenants.manage', 'tenants.cross',
                'api.manage', 'integrations.manage', 'notifications.manage', 'jobs.manage', 'settings.manage',
                'modules.manage', 'incidents.manage', 'deploy.request', 'deploy.approve', 'backup.manage', 'storage.manage',
            ],
        ],
        'superadmin' => [
            'description' => 'Break-glass owner: every permission (all operations are audited)',
            'children' => ['platform_admin', 'devops_sre', 'security_admin', 'developer'],
            'permissions' => ['*'],
        ],
    ],
];
