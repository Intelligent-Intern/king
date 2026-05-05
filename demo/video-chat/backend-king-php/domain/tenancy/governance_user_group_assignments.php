<?php

declare(strict_types=1);

require_once __DIR__ . '/governance_permission_grants.php';

function videochat_tenancy_governance_user_payload_has_groups(array $payload): bool
{
    $relationships = is_array($payload['relationships'] ?? null) ? $payload['relationships'] : [];
    return array_key_exists('groups', $relationships) || array_key_exists('groups', $payload);
}

function videochat_tenancy_governance_user_group_values(array $payload): array
{
    $relationships = is_array($payload['relationships'] ?? null) ? $payload['relationships'] : [];
    $groups = array_key_exists('groups', $relationships) ? $relationships['groups'] : ($payload['groups'] ?? []);
    return is_array($groups) ? $groups : [];
}

function videochat_tenancy_governance_user_group_identifier(mixed $value): string
{
    if (is_scalar($value)) {
        return trim((string) $value);
    }
    if (is_array($value)) {
        return videochat_tenancy_governance_relation_text($value, ['id', 'key', 'value', 'name']);
    }
    return '';
}

function videochat_tenancy_governance_group_summary(array $row): array
{
    $publicId = trim((string) ($row['public_id'] ?? ($row['id'] ?? '')));
    return [
        'entity_key' => 'groups',
        'id' => $publicId,
        'key' => $publicId,
        'name' => (string) ($row['name'] ?? $publicId),
        'status' => (string) ($row['status'] ?? 'active'),
    ];
}

function videochat_tenancy_governance_validate_user_groups(PDO $pdo, int $tenantId, array $payload): array
{
    if (!videochat_tenancy_governance_user_payload_has_groups($payload)) {
        return ['ok' => true, 'group_ids' => []];
    }

    $groupIds = [];
    foreach (videochat_tenancy_governance_user_group_values($payload) as $value) {
        $identifier = videochat_tenancy_governance_user_group_identifier($value);
        $group = videochat_tenancy_fetch_governance_group($pdo, $tenantId, $identifier);
        if (!is_array($group)) {
            return ['ok' => false, 'errors' => ['groups' => 'not_found']];
        }
        $groupId = (int) ($group['database_id'] ?? ($group['id'] ?? 0));
        if ($groupId > 0) {
            $groupIds[$groupId] = true;
        }
    }

    return ['ok' => true, 'group_ids' => array_keys($groupIds)];
}

function videochat_tenancy_governance_sync_user_groups(PDO $pdo, int $tenantId, int $userId, array $payload): array
{
    if (!videochat_tenancy_governance_user_payload_has_groups($payload)) {
        return ['ok' => true];
    }
    $validation = videochat_tenancy_governance_validate_user_groups($pdo, $tenantId, $payload);
    if (!(bool) ($validation['ok'] ?? false)) {
        return $validation;
    }

    $now = gmdate('c');
    $disable = $pdo->prepare(
        <<<'SQL'
UPDATE group_memberships
SET status = 'disabled',
    updated_at = :updated_at
WHERE tenant_id = :tenant_id
  AND subject_type = 'user'
  AND user_id = :user_id
SQL
    );
    $disable->execute([
        ':updated_at' => $now,
        ':tenant_id' => $tenantId,
        ':user_id' => $userId,
    ]);

    $activate = $pdo->prepare(
        <<<'SQL'
UPDATE group_memberships
SET status = 'active',
    updated_at = :updated_at
WHERE tenant_id = :tenant_id
  AND group_id = :group_id
  AND subject_type = 'user'
  AND user_id = :user_id
SQL
    );
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO group_memberships(tenant_id, group_id, subject_type, user_id, organization_id, status, created_at, updated_at)
VALUES(:tenant_id, :group_id, 'user', :user_id, NULL, 'active', :created_at, :updated_at)
SQL
    );
    foreach ((array) ($validation['group_ids'] ?? []) as $groupId) {
        $activate->execute([
            ':updated_at' => $now,
            ':tenant_id' => $tenantId,
            ':group_id' => (int) $groupId,
            ':user_id' => $userId,
        ]);
        if ($activate->rowCount() > 0) {
            continue;
        }
        $insert->execute([
            ':tenant_id' => $tenantId,
            ':group_id' => (int) $groupId,
            ':user_id' => $userId,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    return ['ok' => true];
}

function videochat_tenancy_governance_user_group_rows(PDO $pdo, int $tenantId, array $userIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0)));
    if ($tenantId <= 0 || $ids === []) {
        return [];
    }
    $params = [':tenant_id' => $tenantId];
    $placeholders = [];
    foreach ($ids as $index => $id) {
        $name = ':user_id_' . $index;
        $placeholders[] = $name;
        $params[$name] = $id;
    }
    $query = $pdo->prepare(sprintf(
        <<<'SQL'
SELECT group_memberships.user_id, "groups".public_id, "groups".name, "groups".status
FROM group_memberships
INNER JOIN "groups" ON "groups".id = group_memberships.group_id
WHERE group_memberships.tenant_id = :tenant_id
  AND group_memberships.subject_type = 'user'
  AND group_memberships.status = 'active'
  AND group_memberships.user_id IN (%s)
ORDER BY lower("groups".name) ASC, "groups".id ASC
SQL,
        implode(', ', $placeholders)
    ));
    $query->execute($params);
    $groupsByUser = array_fill_keys($ids, []);
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $userId = (int) ($row['user_id'] ?? 0);
        if (!array_key_exists($userId, $groupsByUser)) {
            continue;
        }
        $groupsByUser[$userId][] = videochat_tenancy_governance_group_summary($row);
    }

    return $groupsByUser;
}

function videochat_tenancy_governance_enrich_user_group_relationships(PDO $pdo, int $tenantId, array $row): array
{
    $userId = (int) ($row['id'] ?? ($row['user_id'] ?? 0));
    $groups = videochat_tenancy_governance_user_group_rows($pdo, $tenantId, [$userId]);
    $row['relationships'] = [
        ...(is_array($row['relationships'] ?? null) ? $row['relationships'] : []),
        'groups' => $groups[$userId] ?? [],
    ];
    return $row;
}

function videochat_tenancy_governance_enrich_user_group_rows(PDO $pdo, int $tenantId, array $rows): array
{
    $userIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? ($row['user_id'] ?? 0)), $rows);
    $groups = videochat_tenancy_governance_user_group_rows($pdo, $tenantId, $userIds);
    return array_map(static function (array $row) use ($groups): array {
        $userId = (int) ($row['id'] ?? ($row['user_id'] ?? 0));
        $row['relationships'] = [
            ...(is_array($row['relationships'] ?? null) ? $row['relationships'] : []),
            'groups' => $groups[$userId] ?? [],
        ];
        return $row;
    }, $rows);
}
