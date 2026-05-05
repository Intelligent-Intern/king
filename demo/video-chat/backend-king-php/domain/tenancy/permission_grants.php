<?php

declare(strict_types=1);

function videochat_tenancy_normalize_resource_type(string $resourceType): string
{
    return strtolower(trim($resourceType));
}

function videochat_tenancy_normalize_grant_action(string $action): string
{
    $normalized = strtolower(trim($action));
    return in_array($normalized, ['create', 'read', 'update', 'delete', 'share', 'manage'], true) ? $normalized : '';
}

function videochat_tenancy_timestamp_is_active(?string $validFrom, ?string $validUntil, string $now): bool
{
    $nowUnix = strtotime($now);
    if (!is_int($nowUnix)) {
        return false;
    }

    $from = is_string($validFrom) ? trim($validFrom) : '';
    if ($from !== '') {
        $fromUnix = strtotime($from);
        if (!is_int($fromUnix) || $fromUnix > $nowUnix) {
            return false;
        }
    }

    $until = is_string($validUntil) ? trim($validUntil) : '';
    if ($until !== '') {
        $untilUnix = strtotime($until);
        if (!is_int($untilUnix) || $untilUnix <= $nowUnix) {
            return false;
        }
    }

    return true;
}

function videochat_tenancy_user_has_resource_permission(
    PDO $pdo,
    int $tenantId,
    int $userId,
    string $resourceType,
    string $resourceId,
    string $action,
    ?string $now = null
): array {
    $normalizedResourceType = videochat_tenancy_normalize_resource_type($resourceType);
    $trimmedResourceId = trim($resourceId);
    $normalizedAction = videochat_tenancy_normalize_grant_action($action);
    $effectiveNow = trim((string) ($now ?? gmdate('c')));
    if ($tenantId <= 0 || $userId <= 0 || $normalizedResourceType === '' || $trimmedResourceId === '' || $normalizedAction === '') {
        return ['ok' => false, 'reason' => 'invalid_request', 'grant' => null];
    }
    if (strtotime($effectiveNow) === false) {
        return ['ok' => false, 'reason' => 'invalid_time', 'grant' => null];
    }

    $query = $pdo->prepare(
        <<<'SQL'
SELECT permission_grants.*
FROM permission_grants
WHERE permission_grants.tenant_id = :tenant_id
  AND permission_grants.resource_type = :resource_type
  AND permission_grants.resource_id = :resource_id
  AND permission_grants.action = :action
  AND (permission_grants.revoked_at IS NULL OR permission_grants.revoked_at = '')
  AND (
      (permission_grants.subject_type = 'user' AND permission_grants.user_id = :user_id)
      OR (
          permission_grants.subject_type = 'group'
          AND EXISTS (
              SELECT 1
              FROM group_memberships
              WHERE group_memberships.tenant_id = permission_grants.tenant_id
                AND group_memberships.group_id = permission_grants.group_id
                AND group_memberships.subject_type = 'user'
                AND group_memberships.user_id = :user_id
                AND group_memberships.status = 'active'
          )
      )
      OR (
          permission_grants.subject_type = 'organization'
          AND EXISTS (
              SELECT 1
              FROM organization_memberships
              WHERE organization_memberships.tenant_id = permission_grants.tenant_id
                AND organization_memberships.organization_id = permission_grants.organization_id
                AND organization_memberships.user_id = :user_id
                AND organization_memberships.status = 'active'
          )
      )
  )
ORDER BY permission_grants.id ASC
SQL
    );
    $query->execute([
        ':tenant_id' => $tenantId,
        ':resource_type' => $normalizedResourceType,
        ':resource_id' => $trimmedResourceId,
        ':action' => $normalizedAction,
        ':user_id' => $userId,
    ]);

    foreach ($query as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (!videochat_tenancy_timestamp_is_active(
            is_string($row['valid_from'] ?? null) ? (string) $row['valid_from'] : null,
            is_string($row['valid_until'] ?? null) ? (string) $row['valid_until'] : null,
            $effectiveNow
        )) {
            continue;
        }

        return [
            'ok' => true,
            'reason' => 'granted',
            'grant' => [
                'id' => (int) ($row['id'] ?? 0),
                'tenant_id' => (int) ($row['tenant_id'] ?? 0),
                'resource_type' => (string) ($row['resource_type'] ?? ''),
                'resource_id' => (string) ($row['resource_id'] ?? ''),
                'action' => (string) ($row['action'] ?? ''),
                'subject_type' => (string) ($row['subject_type'] ?? ''),
            ],
        ];
    }

    return ['ok' => false, 'reason' => 'not_granted', 'grant' => null];
}
