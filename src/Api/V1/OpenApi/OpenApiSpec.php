<?php

declare(strict_types=1);

namespace App\Api\V1\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Root OpenAPI 3.1 definitions for REST API v1 (info, security, shared schemas and responses).
 * Operations are documented with attributes on the action classes in src/Api/V1/Action.
 */
#[OA\OpenApi(
    openapi: '3.1.0',
    info: new OA\Info(
        version: '1.0.0',
        title: 'Yii3 Enterprise Platform API',
        description: "REST API v1. Stateless (Bearer tokens, no cookies). Success envelope: `{data, meta, links}`; errors: `{error: {code, message, details, correlation_id}}`.\n\nSee docs/API.md for auth flows, pagination, rate limits and the versioning policy.",
        contact: new OA\Contact(name: 'ShokirjonMK', url: 'https://github.com/ShokirjonMK'),
        x: ['author' => 'ShokirjonMK'],
    ),
    servers: [new OA\Server(url: '/', description: 'This deployment')],
    security: [['bearerAuth' => []]],
    tags: [
        new OA\Tag(name: 'Auth', description: 'Token issuance, refresh, revocation, current principal'),
        new OA\Tag(name: 'System', description: 'Health and API description'),
        new OA\Tag(name: 'Users', description: 'User administration (users.view / users.manage)'),
        new OA\Tag(name: 'Projects', description: 'Projects business module (module.projects flag)'),
    ],
)]
#[OA\Components(
    securitySchemes: [
        new OA\SecurityScheme(securityScheme: 'bearerAuth', type: 'http', scheme: 'bearer', bearerFormat: 'opaque', description: 'Access token from POST /api/v1/auth/token (prefix yep_a_ / yep_p_).'),
    ],
    parameters: [
        new OA\Parameter(parameter: 'page', name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
        new OA\Parameter(parameter: 'per_page', name: 'per_page', in: 'query', required: false, description: 'Page size (max 100, default = setting api.default_page_size)', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100)),
        new OA\Parameter(parameter: 'q', name: 'q', in: 'query', required: false, description: 'Free-text search', schema: new OA\Schema(type: 'string', maxLength: 200)),
        new OA\Parameter(parameter: 'id', name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(parameter: 'If-Match', name: 'If-Match', in: 'header', required: false, description: 'ETag (version) of the resource for optimistic locking, e.g. "3"', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 'BadRequest', description: 'Malformed request (invalid JSON)', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 'Unauthorized', description: 'Missing, invalid or expired Bearer token', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 'Forbidden', description: 'Missing scope (insufficient_scope) or permission (forbidden)', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 'NotFound', description: 'Resource does not exist or belongs to another tenant', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 'Conflict', description: 'Stale version (stale_version) or forbidden state transition', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 'UnsupportedMediaType', description: 'Body is not application/json', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 'ValidationFailed', description: 'Validation errors per field in error.details', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 'PreconditionRequired', description: 'If-Match / version is required for updates', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 'TooManyRequests', description: 'Rate limit exceeded (see Retry-After)', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
    schemas: [
        new OA\Schema(
            schema: 'Error',
            required: ['error'],
            properties: [
                new OA\Property(property: 'error', required: ['code', 'message', 'correlation_id'], properties: [
                    new OA\Property(property: 'code', type: 'string', example: 'validation_failed'),
                    new OA\Property(property: 'message', type: 'string', example: 'Validation failed.'),
                    new OA\Property(property: 'details', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'array', items: new OA\Items(type: 'string')), example: ['name' => ['Name must be between 2 and 200 characters.']]),
                    new OA\Property(property: 'correlation_id', type: 'string', example: '5f0c1c8e9b2d4a8c9a1e2f3d4c5b6a79'),
                ], type: 'object'),
            ],
            type: 'object',
        ),
        new OA\Schema(
            schema: 'PaginationMeta',
            properties: [
                new OA\Property(property: 'pagination', properties: [
                    new OA\Property(property: 'page', type: 'integer'),
                    new OA\Property(property: 'per_page', type: 'integer'),
                    new OA\Property(property: 'total', type: 'integer'),
                    new OA\Property(property: 'pages', type: 'integer'),
                    new OA\Property(property: 'sort', type: 'string', example: '-created_at'),
                ], type: 'object'),
            ],
            type: 'object',
        ),
        new OA\Schema(
            schema: 'Links',
            properties: [
                new OA\Property(property: 'self', type: 'string'),
                new OA\Property(property: 'first', type: 'string'),
                new OA\Property(property: 'last', type: 'string'),
                new OA\Property(property: 'prev', type: ['string', 'null']),
                new OA\Property(property: 'next', type: ['string', 'null']),
            ],
            type: 'object',
        ),
        new OA\Schema(
            schema: 'TokenPair',
            properties: [
                new OA\Property(property: 'access_token', type: 'string', example: 'yep_a_…'),
                new OA\Property(property: 'refresh_token', type: 'string', example: 'yep_r_…'),
                new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
                new OA\Property(property: 'expires_in', type: 'integer', example: 3600),
                new OA\Property(property: 'scope', type: 'string', example: 'projects.read projects.write'),
            ],
            type: 'object',
        ),
        new OA\Schema(
            schema: 'User',
            properties: [
                new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'tenant_id', type: ['string', 'null'], format: 'uuid'),
                new OA\Property(property: 'organization_id', type: ['string', 'null'], format: 'uuid'),
                new OA\Property(property: 'username', type: 'string'),
                new OA\Property(property: 'email', type: ['string', 'null']),
                new OA\Property(property: 'phone', type: ['string', 'null']),
                new OA\Property(property: 'full_name', type: 'string'),
                new OA\Property(property: 'status', type: 'string', enum: ['active', 'blocked', 'pending', 'archived']),
                new OA\Property(property: 'auth_policy', type: 'string'),
                new OA\Property(property: 'mfa_enabled', type: 'boolean'),
                new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'string')),
                new OA\Property(property: 'version', type: 'integer'),
                new OA\Property(property: 'last_login_at', type: ['string', 'null'], format: 'date-time'),
                new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
            ],
            type: 'object',
        ),
        new OA\Schema(
            schema: 'UserInput',
            properties: [
                new OA\Property(property: 'username', type: 'string', example: 'j.doe'),
                new OA\Property(property: 'email', type: 'string', format: 'email'),
                new OA\Property(property: 'phone', type: 'string'),
                new OA\Property(property: 'full_name', type: 'string'),
                new OA\Property(property: 'auth_policy', type: 'string', enum: ['any', 'local_only', 'sso_only']),
                new OA\Property(property: 'password', type: 'string', format: 'password', description: 'Optional; a temporary password is generated when omitted'),
                new OA\Property(property: 'locale', type: 'string', enum: ['uz', 'ru', 'en']),
                new OA\Property(property: 'mfa_required', type: 'boolean'),
                new OA\Property(property: 'version', type: 'integer', description: 'PATCH: alternative to If-Match'),
            ],
            type: 'object',
        ),
        new OA\Schema(
            schema: 'Project',
            properties: [
                new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'code', type: 'string', example: 'CRM-2026'),
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'description', type: 'string'),
                new OA\Property(property: 'status', type: 'string', enum: ['draft', 'active', 'on_hold', 'completed', 'archived']),
                new OA\Property(property: 'priority', type: 'string', enum: ['low', 'normal', 'high', 'critical']),
                new OA\Property(property: 'owner_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'owner_name', type: ['string', 'null']),
                new OA\Property(property: 'budget', type: ['string', 'null'], example: '125000.00', description: 'Decimal string, 2 fraction digits'),
                new OA\Property(property: 'starts_on', type: ['string', 'null'], format: 'date'),
                new OA\Property(property: 'due_on', type: ['string', 'null'], format: 'date'),
                new OA\Property(property: 'tags', type: 'array', items: new OA\Items(type: 'string')),
                new OA\Property(property: 'version', type: 'integer'),
                new OA\Property(property: 'allowed_transitions', type: 'array', items: new OA\Items(type: 'string')),
                new OA\Property(property: 'created_by', type: ['string', 'null'], format: 'uuid'),
                new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                new OA\Property(property: 'updated_at', type: ['string', 'null'], format: 'date-time'),
                new OA\Property(property: 'archived_at', type: ['string', 'null'], format: 'date-time'),
            ],
            type: 'object',
        ),
        new OA\Schema(
            schema: 'ProjectInput',
            required: ['code', 'name'],
            properties: [
                new OA\Property(property: 'code', type: 'string', pattern: '^[A-Za-z0-9][A-Za-z0-9_-]{1,31}$', example: 'CRM-2026'),
                new OA\Property(property: 'name', type: 'string', maxLength: 200, minLength: 2, example: 'CRM modernisation'),
                new OA\Property(property: 'description', type: 'string', maxLength: 5000),
                new OA\Property(property: 'priority', type: 'string', enum: ['low', 'normal', 'high', 'critical']),
                new OA\Property(property: 'owner_id', type: 'string', format: 'uuid', description: 'Defaults to the caller; other owners need projects.manage_all'),
                new OA\Property(property: 'budget', type: ['string', 'number', 'null'], example: '125000.00'),
                new OA\Property(property: 'starts_on', type: ['string', 'null'], format: 'date'),
                new OA\Property(property: 'due_on', type: ['string', 'null'], format: 'date', description: 'Must be on or after starts_on'),
                new OA\Property(property: 'tags', type: 'array', maxItems: 10, items: new OA\Items(type: 'string')),
                new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid', description: 'Only for callers with tenants.cross'),
            ],
            type: 'object',
        ),
        new OA\Schema(
            schema: 'Report',
            properties: [
                new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'status', type: 'string', enum: ['pending', 'running', 'done', 'failed']),
                new OA\Property(property: 'job_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'job_status', type: ['string', 'null']),
                new OA\Property(property: 'rows', type: ['integer', 'null']),
                new OA\Property(property: 'size_bytes', type: ['integer', 'null']),
                new OA\Property(property: 'path', type: ['string', 'null']),
                new OA\Property(property: 'error', type: ['string', 'null']),
                new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                new OA\Property(property: 'finished_at', type: ['string', 'null'], format: 'date-time'),
            ],
            type: 'object',
        ),
    ],
)]
final class OpenApiSpec
{
}
