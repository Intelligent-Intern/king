<?php

declare(strict_types=1);

require_once __DIR__ . '/governance_group_memberships.php';

function videochat_tenancy_governance_payload_has_users(array $payload): bool
{
    if (array_key_exists('users', $payload)) {
        return true;
    }
    $relationships = is_array($payload['relationships'] ?? null) ? $payload['relationships'] : [];

    return array_key_exists('users', $relationships);
}

function videochat_tenancy_governance_user_id_result_for_key(array $payload, string $key): array
{
    $relationships = is_array($payload['relationships'] ?? null) ? $payload['relationships'] : [];
    $rawUsers = array_key_exists($key, $relationships) ? $relationships[$key] : ($payload[$key] ?? []);
    if (!is_array($rawUsers)) {
        return ['ok' => false, 'ids' => []];
    }

    $ids = [];
    $invalid = false;
    foreach ($rawUsers as $user) {
        $raw = '';
        if (is_scalar($user)) {
            $raw = trim((string) $user);
        } elseif (is_array($user)) {
            foreach (['id', 'user_id', 'database_id', 'value'] as $field) {
                if (array_key_exists($field, $user)) {
                    $raw = trim((string) $user[$field]);
                    break;
                }
            }
        }

        if ($raw === '' || !ctype_digit($raw) || (int) $raw <= 0) {
            $invalid = true;
            continue;
        }
        $ids[(int) $raw] = true;
    }

    return [
        'ok' => !$invalid,
        'ids' => array_keys($ids),
    ];
}

function videochat_tenancy_governance_validate_user_ids(PDO $pdo, int $tenantId, array $ids, string $field): array
{
    $userIds = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
    if ($userIds === []) {
        return ['ok' => true, 'ids' => []];
    }

    $placeholders = [];
    $params = [':tenant_id' => $tenantId];
    foreach ($userIds as $index => $id) {
        $name = ':user_id_' . $index;
        $placeholders[] = $name;
        $params[$name] = $id;
    }
    $query = $pdo->prepare(
        sprintf(
            <<<'SQL'
SELECT users.id
FROM users
INNER JOIN tenant_memberships ON tenant_memberships.user_id = users.id
WHERE tenant_memberships.tenant_id = :tenant_id
  AND tenant_memberships.status = 'active'
  AND users.id IN (%s)
SQL,
            implode(', ', $placeholders)
        )
    );
    $query->execute($params);
    $validIds = array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN) ?: []);
    sort($validIds);
    $expectedIds = $userIds;
    sort($expectedIds);
    if ($validIds !== $expectedIds) {
        return ['ok' => false, 'errors' => [$field => 'not_found_or_not_in_tenant']];
    }

    return ['ok' => true, 'ids' => $userIds];
}

function videochat_tenancy_governance_validate_organization_users(PDO $pdo, int $tenantId, array $payload): array
{
    if (!videochat_tenancy_governance_payload_has_users($payload)) {
        return ['ok' => true, 'ids' => []];
    }

    $userIds = videochat_tenancy_governance_user_id_result_for_key($payload, 'users');
    if (!(bool) ($userIds['ok'] ?? false)) {
        return ['ok' => false, 'errors' => ['users' => 'invalid_user_reference']];
    }

    return videochat_tenancy_governance_validate_user_ids($pdo, $tenantId, (array) ($userIds['ids'] ?? []), 'users');
}

function videochat_tenancy_governance_sync_organization_users(PDO $pdo, int $tenantId, int $organizationId, array $payload): array
{
    if (!videochat_tenancy_governance_payload_has_users($payload)) {
        return ['ok' => true];
    }

    $validation = videochat_tenancy_governance_validate_organization_users($pdo, $tenantId, $payload);
    if (!(bool) ($validation['ok'] ?? false)) {
        return $validation;
    }

    $now = gmdate('c');
    $disable = $pdo->prepare(
        <<<'SQL'
UPDATE organization_memberships
SET status = 'disabled',
    updated_at = :updated_at
WHERE tenant_id = :tenant_id
  AND organization_id = :organization_id
SQL
    );
    $disable->execute([
        ':updated_at' => $now,
        ':tenant_id' => $tenantId,
        ':organization_id' => $organizationId,
    ]);

    $activate = $pdo->prepare(
        <<<'SQL'
UPDATE organization_memberships
SET status = 'active',
    updated_at = :updated_at
WHERE tenant_id = :tenant_id
  AND organization_id = :organization_id
  AND user_id = :user_id
SQL
    );
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO organization_memberships(tenant_id, organization_id, user_id, membership_role, status, created_at, updated_at)
VALUES(:tenant_id, :organization_id, :user_id, 'member', 'active', :created_at, :updated_at)
SQL
    );
    foreach ((array) ($validation['ids'] ?? []) as $userId) {
        $activate->execute([
            ':updated_at' => $now,
            ':tenant_id' => $tenantId,
            ':organization_id' => $organizationId,
            ':user_id' => (int) $userId,
        ]);
        if ($activate->rowCount() > 0) {
            continue;
        }
        $insert->execute([
            ':tenant_id' => $tenantId,
            ':organization_id' => $organizationId,
            ':user_id' => (int) $userId,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    return ['ok' => true];
}

function videochat_tenancy_governance_organization_user_map(PDO $pdo, int $tenantId, array $organizationIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $organizationIds), static fn (int $id): bool => $id > 0)));
    if ($tenantId <= 0 || $ids === []) {
        return [];
    }

    $placeholders = [];
    $params = [':tenant_id' => $tenantId];
    foreach ($ids as $index => $id) {
        $name = ':organization_id_' . $index;
        $placeholders[] = $name;
        $params[$name] = $id;
    }
    $query = $pdo->prepare(
        sprintf(
            <<<'SQL'
SELECT
    organization_memberships.organization_id,
    users.id,
    users.email,
    users.display_name,
    users.status,
    users.updated_at,
    roles.slug AS role
FROM organization_memberships
INNER JOIN users ON users.id = organization_memberships.user_id
INNER JOIN roles ON roles.id = users.role_id
WHERE organization_memberships.tenant_id = :tenant_id
  AND organization_memberships.organization_id IN (%s)
  AND organization_memberships.status = 'active'
ORDER BY lower(users.display_name) ASC, users.id ASC
SQL,
            implode(', ', $placeholders)
        )
    );
    $query->execute($params);

    $usersByOrganization = array_fill_keys($ids, []);
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $organizationId = (int) ($row['organization_id'] ?? 0);
        if ($organizationId <= 0) {
            continue;
        }
        $usersByOrganization[$organizationId][] = videochat_tenancy_governance_user_summary_row($row);
    }

    return $usersByOrganization;
}

function videochat_tenancy_governance_enrich_organization_relationships(PDO $pdo, int $tenantId, array $row): array
{
    $organizationId = (int) ($row['database_id'] ?? ($row['id'] ?? 0));
    $parentId = (int) ($row['parent_organization_database_id'] ?? 0);
    $users = videochat_tenancy_governance_organization_user_map($pdo, $tenantId, [$organizationId]);
    $parents = videochat_tenancy_governance_organization_summary_map($pdo, $tenantId, [$parentId]);
    $row['relationships'] = [
        ...(is_array($row['relationships'] ?? null) ? $row['relationships'] : []),
        'parent_organization' => isset($parents[$parentId]) ? [$parents[$parentId]] : [],
        'users' => $users[$organizationId] ?? [],
    ];

    return $row;
}

function videochat_tenancy_governance_enrich_organization_rows(PDO $pdo, int $tenantId, array $rows): array
{
    $organizationIds = array_map(static fn (array $row): int => (int) ($row['database_id'] ?? 0), $rows);
    $parentIds = array_map(static fn (array $row): int => (int) ($row['parent_organization_database_id'] ?? 0), $rows);
    $users = videochat_tenancy_governance_organization_user_map($pdo, $tenantId, $organizationIds);
    $parents = videochat_tenancy_governance_organization_summary_map($pdo, $tenantId, $parentIds);

    return array_map(static function (array $row) use ($users, $parents): array {
        $organizationId = (int) ($row['database_id'] ?? 0);
        $parentId = (int) ($row['parent_organization_database_id'] ?? 0);
        $row['relationships'] = [
            ...(is_array($row['relationships'] ?? null) ? $row['relationships'] : []),
            'parent_organization' => isset($parents[$parentId]) ? [$parents[$parentId]] : [],
            'users' => $users[$organizationId] ?? [],
        ];
        return $row;
    }, $rows);
}
