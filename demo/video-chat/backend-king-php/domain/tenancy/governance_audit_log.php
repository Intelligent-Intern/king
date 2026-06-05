<?php

declare(strict_types=1);

require_once __DIR__ . '/../audit/audit_events.php';
require_once __DIR__ . '/permission_grants.php';

function videochat_tenancy_governance_audit_permission_decision(
    PDO $pdo,
    array $authContext,
    string $action
): array {
    $tenant = is_array($authContext['tenant'] ?? null) ? $authContext['tenant'] : [];
    $permissions = is_array($tenant['permissions'] ?? null) ? $tenant['permissions'] : [];
    $tenantId = (int) ($tenant['id'] ?? ($tenant['tenant_id'] ?? 0));
    $userId = (int) (($authContext['user']['id'] ?? 0));
    $normalizedAction = strtolower(trim($action)) === 'export' ? 'export' : 'read';
    if ($tenantId <= 0 || $userId <= 0) {
        return ['ok' => false, 'reason' => 'invalid_context'];
    }

    if (
        (bool) ($permissions['platform_admin'] ?? false)
        || (bool) ($permissions['tenant_admin'] ?? false)
        || (bool) ($permissions['governance.audit_log.' . $normalizedAction] ?? false)
        || ($normalizedAction === 'read' && (bool) ($permissions['governance.read'] ?? false))
    ) {
        return ['ok' => true, 'reason' => 'tenant_permission_alias'];
    }

    if ($normalizedAction === 'read' || $normalizedAction === 'export') {
        foreach (['*', 'audit_log'] as $resourceId) {
            foreach ($normalizedAction === 'export' ? ['manage'] : ['read', 'manage'] as $grantAction) {
                $grant = videochat_tenancy_user_has_resource_permission(
                    $pdo,
                    $tenantId,
                    $userId,
                    'audit_log',
                    $resourceId,
                    $grantAction
                );
                if ((bool) ($grant['ok'] ?? false)) {
                    return ['ok' => true, 'reason' => 'resource_grant', 'grant' => $grant['grant'] ?? null];
                }
            }
        }
    }

    return ['ok' => false, 'reason' => 'not_granted'];
}

function videochat_tenancy_governance_audit_user_labels(PDO $pdo, array $events): array
{
    $ids = [];
    foreach ($events as $event) {
        foreach (['actor_user_id', 'target_user_id'] as $key) {
            $id = (int) ($event[$key] ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
    }
    if ($ids === []) {
        return [];
    }

    $params = [];
    $placeholders = [];
    foreach (array_keys($ids) as $index => $id) {
        $name = ':user_id_' . $index;
        $placeholders[] = $name;
        $params[$name] = $id;
    }
    $query = $pdo->prepare(
        'SELECT id, email, display_name FROM users WHERE id IN (' . implode(', ', $placeholders) . ')'
    );
    $query->execute($params);

    $labels = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $name = trim((string) ($row['display_name'] ?? ''));
        $email = trim((string) ($row['email'] ?? ''));
        $labels[$id] = $name !== '' ? $name : ($email !== '' ? $email : 'User #' . $id);
    }

    return $labels;
}

function videochat_tenancy_governance_audit_title(string $eventType): string
{
    $normalized = trim(str_replace(['_', '.', ':', '-'], ' ', $eventType));
    $normalized = preg_replace('/\s+/', ' ', $normalized) ?: $eventType;
    return ucwords($normalized);
}

function videochat_tenancy_governance_audit_resource(array $event): string
{
    $resourceType = trim((string) ($event['resource_type'] ?? ''));
    $resourceId = trim((string) ($event['resource_id'] ?? ''));
    $callId = trim((string) ($event['call_id'] ?? ''));
    if ($resourceType !== '' && $resourceId !== '') {
        return $resourceType . ':' . $resourceId;
    }
    if ($resourceType !== '') {
        return $resourceType;
    }
    if ($callId !== '') {
        return 'call:' . $callId;
    }
    return 'workspace';
}

function videochat_tenancy_governance_audit_description(array $event): string
{
    $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
    $parts = [];
    foreach (['audit_scope', 'stage', 'reason', 'result', 'cleanup_result', 'review_status'] as $key) {
        $value = trim((string) ($payload[$key] ?? ''));
        if ($value !== '' && !in_array($value, $parts, true)) {
            $parts[] = str_replace('_', ' ', $value);
        }
    }
    if ($parts !== []) {
        return implode(' - ', $parts);
    }

    $fingerprint = trim((string) ($event['resource_fingerprint'] ?? ''));
    if ($fingerprint !== '') {
        return 'Resource fingerprint ' . $fingerprint;
    }

    return 'System activity was recorded.';
}

function videochat_tenancy_governance_audit_public_rows(PDO $pdo, array $events): array
{
    $userLabels = videochat_tenancy_governance_audit_user_labels($pdo, $events);
    $rows = [];
    foreach ($events as $event) {
        $eventId = trim((string) ($event['id'] ?? ''));
        if ($eventId === '') {
            continue;
        }
        $actorId = (int) ($event['actor_user_id'] ?? 0);
        $targetId = (int) ($event['target_user_id'] ?? 0);
        $actor = $actorId > 0 ? ($userLabels[$actorId] ?? ('User #' . $actorId)) : 'System';
        $target = $targetId > 0 ? ($userLabels[$targetId] ?? ('User #' . $targetId)) : '';
        $rows[] = [
            'id' => $eventId,
            'key' => trim((string) ($event['event_type'] ?? '')),
            'name' => videochat_tenancy_governance_audit_title((string) ($event['event_type'] ?? '')),
            'event' => videochat_tenancy_governance_audit_title((string) ($event['event_type'] ?? '')),
            'actor' => $actor,
            'target' => $target,
            'resource' => videochat_tenancy_governance_audit_resource($event),
            'description' => videochat_tenancy_governance_audit_description($event),
            'status' => 'active',
            'createdAt' => (string) ($event['created_at'] ?? ''),
            'updatedAt' => (string) ($event['created_at'] ?? ''),
            'readonly' => true,
        ];
    }

    return $rows;
}

function videochat_handle_governance_audit_log_routes(
    string $method,
    array $request,
    array $apiAuthContext,
    callable $jsonResponse,
    callable $errorResponse,
    callable $openDatabase
): array {
    if ($method !== 'GET') {
        return $errorResponse(405, 'method_not_allowed', 'Use GET for governance audit log.', [
            'allowed_methods' => ['GET'],
        ]);
    }

    try {
        $pdo = $openDatabase();
        $tenantId = videochat_tenant_id_from_auth_context($apiAuthContext);
        $actorUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
        if ($tenantId <= 0 || $actorUserId <= 0) {
            return $errorResponse(401, 'auth_failed', 'A valid tenant session is required.', [
                'reason' => 'invalid_tenant_context',
            ]);
        }

        $permission = videochat_tenancy_governance_audit_permission_decision($pdo, $apiAuthContext, 'read');
        if (!(bool) ($permission['ok'] ?? false)) {
            return videochat_tenancy_governance_forbidden_response($errorResponse, $permission);
        }

        $query = function_exists('videochat_request_query_params') ? videochat_request_query_params($request) : [];
        $limit = max(1, min(200, (int) ($query['limit'] ?? 100)));
        $events = videochat_audit_fetch_events($pdo, ['tenant_id' => $tenantId, 'limit' => $limit]);
        $rows = array_reverse(videochat_tenancy_governance_audit_public_rows($pdo, $events));

        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => [
                'rows' => $rows,
                'included' => ['audit-log' => $rows],
            ],
            'audit-log' => $rows,
            'time' => gmdate('c'),
        ]);
    } catch (Throwable) {
        return $errorResponse(500, 'governance_operation_failed', 'Governance operation failed.', [
            'reason' => 'internal_error',
        ]);
    }
}
