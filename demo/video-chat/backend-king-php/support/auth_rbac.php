<?php

declare(strict_types=1);

function videochat_normalize_role_slug(string $role): string
{
    $normalized = strtolower(trim($role));
    return in_array($normalized, ['admin', 'user'], true) ? $normalized : 'unknown';
}

/**
 * @return array<int, array{
 *   id: string,
 *   transport: string,
 *   matcher: string,
 *   allowed_roles: array<int, string>,
 *   path?: string,
 *   paths?: array<int, string>,
 *   prefix?: string
 * }>
 */
function videochat_rbac_permission_matrix(string $wsPath = '/ws'): array
{
    $normalizedWsPath = trim($wsPath);
    if ($normalizedWsPath === '') {
        $normalizedWsPath = '/ws';
    }
    if ($normalizedWsPath[0] !== '/') {
        $normalizedWsPath = '/' . $normalizedWsPath;
    }

    $authenticatedRoles = ['admin', 'user'];

    return [
        [
            'id' => 'rest_auth_session',
            'transport' => 'rest',
            'matcher' => 'exact_any',
            'paths' => ['/api/auth/session', '/api/auth/refresh', '/api/auth/logout', '/api/auth/tenant'],
            'allowed_roles' => $authenticatedRoles,
        ],
        [
            'id' => 'websocket_gateway',
            'transport' => 'websocket',
            'matcher' => 'exact',
            'path' => $normalizedWsPath,
            'allowed_roles' => $authenticatedRoles,
        ],
        [
            'id' => 'rest_workspace_administration',
            'transport' => 'rest',
            'matcher' => 'exact',
            'path' => '/api/admin/workspace-administration',
            'allowed_roles' => $authenticatedRoles,
        ],
        [
            'id' => 'rest_workspace_administration_items',
            'transport' => 'rest',
            'matcher' => 'prefix',
            'prefix' => '/api/admin/workspace-administration/',
            'allowed_roles' => $authenticatedRoles,
        ],
        [
            'id' => 'rest_tenant_administration',
            'transport' => 'rest',
            'matcher' => 'prefix',
            'prefix' => '/api/admin/tenancy/',
            'allowed_roles' => $authenticatedRoles,
        ],
        [
            'id' => 'rest_admin_scope',
            'transport' => 'rest',
            'matcher' => 'prefix',
            'prefix' => '/api/admin/',
            'allowed_roles' => ['admin'],
        ],
        [
            'id' => 'rest_moderation_scope',
            'transport' => 'rest',
            'matcher' => 'prefix',
            'prefix' => '/api/moderation/',
            'allowed_roles' => ['admin'],
        ],
        [
            'id' => 'rest_calls_collection',
            'transport' => 'rest',
            'matcher' => 'exact',
            'path' => '/api/calls',
            'allowed_roles' => $authenticatedRoles,
        ],
        [
            'id' => 'rest_calls_items',
            'transport' => 'rest',
            'matcher' => 'prefix',
            'prefix' => '/api/calls/',
            'allowed_roles' => $authenticatedRoles,
        ],
        [
            'id' => 'rest_invite_codes_collection',
            'transport' => 'rest',
            'matcher' => 'exact',
            'path' => '/api/invite-codes',
            'allowed_roles' => $authenticatedRoles,
        ],
        [
            'id' => 'rest_invite_codes_items',
            'transport' => 'rest',
            'matcher' => 'prefix',
            'prefix' => '/api/invite-codes/',
            'allowed_roles' => $authenticatedRoles,
        ],
        [
            'id' => 'rest_call_access_scope',
            'transport' => 'rest',
            'matcher' => 'prefix',
            'prefix' => '/api/call-access/',
            'allowed_roles' => $authenticatedRoles,
        ],
        [
            'id' => 'rest_appointment_calendar_scope',
            'transport' => 'rest',
            'matcher' => 'prefix',
            'prefix' => '/api/appointment-calendar/',
            'allowed_roles' => $authenticatedRoles,
        ],
        [
            'id' => 'rest_user_scope',
            'transport' => 'rest',
            'matcher' => 'prefix',
            'prefix' => '/api/user/',
            'allowed_roles' => $authenticatedRoles,
        ],
    ];
}

/**
 * @return array{
 *   id: string,
 *   transport: string,
 *   matcher: string,
 *   allowed_roles: array<int, string>,
 *   path?: string,
 *   paths?: array<int, string>,
 *   prefix?: string
 * }|null
 */
function videochat_rbac_rule_for_path(string $path, string $wsPath = '/ws'): ?array
{
    $trimmedPath = trim($path);
    if ($trimmedPath === '') {
        return null;
    }

    foreach (videochat_rbac_permission_matrix($wsPath) as $rule) {
        $matcher = (string) ($rule['matcher'] ?? '');
        if ($matcher === 'exact') {
            if ($trimmedPath === (string) ($rule['path'] ?? '')) {
                return $rule;
            }
            continue;
        }

        if ($matcher === 'exact_any') {
            $paths = is_array($rule['paths'] ?? null) ? $rule['paths'] : [];
            if (in_array($trimmedPath, $paths, true)) {
                return $rule;
            }
            continue;
        }

        if ($matcher === 'prefix') {
            $prefix = (string) ($rule['prefix'] ?? '');
            if ($prefix !== '' && str_starts_with($trimmedPath, $prefix)) {
                return $rule;
            }
        }
    }

    return null;
}

/**
 * @return array<int, string>
 */
function videochat_rbac_allowed_roles_for_path(string $path, string $wsPath = '/ws'): array
{
    $rule = videochat_rbac_rule_for_path($path, $wsPath);
    if (!is_array($rule)) {
        return [];
    }

    $allowedRoles = $rule['allowed_roles'] ?? [];
    return is_array($allowedRoles) ? array_values($allowedRoles) : [];
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   rule_id: string,
 *   role: string,
 *   allowed_roles: array<int, string>
 * }
 */
function videochat_authorize_role_for_path(array $user, string $path, string $wsPath = '/ws'): array
{
    $rule = videochat_rbac_rule_for_path($path, $wsPath);
    $allowedRoles = is_array($rule['allowed_roles'] ?? null) ? array_values($rule['allowed_roles']) : [];
    $ruleId = is_string($rule['id'] ?? null) ? (string) $rule['id'] : 'not_applicable';
    if ($allowedRoles === []) {
        return [
            'ok' => true,
            'reason' => 'not_applicable',
            'rule_id' => $ruleId,
            'role' => videochat_normalize_role_slug((string) ($user['role'] ?? '')),
            'allowed_roles' => [],
        ];
    }

    $role = videochat_normalize_role_slug((string) ($user['role'] ?? ''));
    if ($role === 'unknown') {
        return [
            'ok' => false,
            'reason' => 'invalid_role',
            'rule_id' => $ruleId,
            'role' => $role,
            'allowed_roles' => $allowedRoles,
        ];
    }
    if (!in_array($role, $allowedRoles, true)) {
        return [
            'ok' => false,
            'reason' => 'role_not_allowed',
            'rule_id' => $ruleId,
            'role' => $role,
            'allowed_roles' => $allowedRoles,
        ];
    }

    return [
        'ok' => true,
        'reason' => 'ok',
        'rule_id' => $ruleId,
        'role' => $role,
        'allowed_roles' => $allowedRoles,
    ];
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   revoked_at: ?string
 * }
 */
