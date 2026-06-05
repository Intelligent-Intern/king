<?php

declare(strict_types=1);

function videochat_governance_seed_has_tables(PDO $pdo, array $tableNames): bool
{
    if ($tableNames === []) {
        return true;
    }
    $placeholders = implode(', ', array_fill(0, count($tableNames), '?'));
    $query = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ({$placeholders})");
    $query->execute(array_values($tableNames));
    $found = [];
    foreach ($query->fetchAll(PDO::FETCH_COLUMN) ?: [] as $name) {
        $found[(string) $name] = true;
    }

    foreach ($tableNames as $name) {
        if (!isset($found[$name])) {
            return false;
        }
    }

    return true;
}

function videochat_governance_seed_public_id(int $tenantId, int $bucket, int $index): string
{
    return sprintf('00000000-0000-4000-8000-%012d', $bucket + ($tenantId * 100) + $index);
}

function videochat_governance_seed_resource_type(string $permissionKey): string
{
    $parts = array_values(array_filter(explode('.', $permissionKey), static fn (string $part): bool => trim($part) !== ''));
    if (count($parts) < 2) {
        return 'workspace';
    }
    $segment = strtolower(trim(str_replace('-', '_', $parts[count($parts) - 2])));

    return match ($segment) {
        'groups' => 'group',
        'organizations' => 'organization',
        'users' => 'user',
        'roles' => 'role',
        'grants', 'permission_grants' => 'permission_grant',
        'policies' => 'policy',
        'compliance' => 'compliance_rule',
        'audit_log' => 'audit_log',
        'data_portability' => 'tenant_export_import_job',
        default => rtrim($segment, 's'),
    };
}

function videochat_governance_seed_permission(string $permissionKey): ?array
{
    $parts = array_values(array_filter(explode('.', $permissionKey), static fn (string $part): bool => trim($part) !== ''));
    $action = strtolower(trim((string) end($parts)));
    if (!in_array($action, ['create', 'read', 'update', 'delete', 'share', 'manage'], true)) {
        $action = $permissionKey !== '' ? 'manage' : '';
    }
    if ($action === '') {
        return null;
    }

    return [
        'permission_key' => $permissionKey,
        'resource_type' => videochat_governance_seed_resource_type($permissionKey),
        'action' => $action,
    ];
}

function videochat_governance_seed_role_specs(): array
{
    return [
        [
            'key' => 'administrator',
            'name' => 'Administrator',
            'description' => 'Workspace administrators with account and governance access.',
            'modules' => ['administration', 'calendar', 'calls', 'governance', 'infrastructure', 'localization', 'marketplace', 'theme_editor', 'users', 'workspace_settings'],
            'permissions' => [
                'administration.read',
                'administration.update',
                'calendar.read',
                'calendar.create',
                'calendar.update',
                'calendar.delete',
                'calendar.share',
                'calls.read',
                'calls.create',
                'calls.update',
                'calls.delete',
                'calls.share',
                'governance.read',
                'governance.groups.create',
                'governance.groups.update',
                'governance.groups.delete',
                'governance.organizations.create',
                'governance.organizations.update',
                'governance.organizations.delete',
                'governance.roles.create',
                'governance.roles.update',
                'governance.roles.delete',
                'governance.grants.create',
                'governance.grants.update',
                'governance.grants.delete',
                'governance.policies.create',
                'governance.policies.update',
                'governance.policies.delete',
                'governance.audit_log.read',
                'governance.audit_log.export',
                'governance.data_portability.export',
                'governance.data_portability.import',
                'governance.compliance.read',
                'governance.compliance.create',
                'governance.compliance.update',
                'governance.compliance.delete',
                'localization.admin',
                'marketplace.admin',
                'theme_editor.admin',
                'users.read',
                'users.create',
                'users.update',
                'users.delete',
                'workspace_settings.read',
                'workspace_settings.update',
            ],
        ],
        [
            'key' => 'user',
            'name' => 'User',
            'description' => 'Standard retained user role for governance assignments.',
            'modules' => ['calendar', 'calls', 'users', 'workspace_settings'],
            'permissions' => ['calendar.read', 'calendar.create', 'calendar.update', 'calls.read', 'calls.create', 'users.read', 'workspace_settings.read'],
        ],
        [
            'key' => 'guest',
            'name' => 'Guest',
            'description' => 'Temporary external call participant role.',
            'modules' => ['calls'],
            'permissions' => ['calls.read'],
        ],
    ];
}

function videochat_governance_seed_policy_specs(): array
{
    return [
        [
            'key' => 'default-member-self-service',
            'name' => 'Default Member Self-Service',
            'description' => 'Baseline permissions for retained demo users in the default members group.',
            'groups' => ['Default Members'],
            'permissions' => ['calendar.read', 'calendar.create', 'calls.read', 'calls.create', 'users.read', 'workspace_settings.read'],
        ],
    ];
}

function videochat_governance_seed_compliance_specs(): array
{
    return [
        [
            'key' => 'access-review-cadence',
            'name' => 'Access Review Cadence',
            'description' => 'Review governance roles, grants, and default member access on a recurring cadence.',
            'severity' => 'high',
            'status' => 'active',
            'modules' => ['governance', 'users'],
            'policies' => ['default-member-self-service'],
        ],
        [
            'key' => 'data-portability-evidence',
            'name' => 'Data Portability Evidence',
            'description' => 'Tenant export and import jobs must remain visible for audit and support review.',
            'severity' => 'medium',
            'status' => 'active',
            'modules' => ['governance', 'workspace_settings'],
            'policies' => ['default-member-self-service'],
        ],
        [
            'key' => 'realtime-call-guardrails',
            'name' => 'Realtime Call Guardrails',
            'description' => 'Realtime call admission, presence, and application access stay under governance review.',
            'severity' => 'high',
            'status' => 'draft',
            'modules' => ['calls', 'infrastructure'],
            'policies' => [],
        ],
    ];
}

function videochat_governance_seed_roles(PDO $pdo, int $tenantId, array $roles): array
{
    $now = gmdate('c');
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT OR IGNORE INTO governance_roles(tenant_id, public_id, key, name, description, status, created_at, updated_at)
VALUES(:tenant_id, :public_id, :key, :name, :description, 'active', :created_at, :updated_at)
SQL
    );
    $update = $pdo->prepare(
        <<<'SQL'
UPDATE governance_roles
SET name = :name,
    description = :description,
    status = 'active',
    updated_at = :updated_at
WHERE tenant_id = :tenant_id
  AND lower(key) = lower(:key)
SQL
    );

    foreach ($roles as $index => $role) {
        $insert->execute([
            ':tenant_id' => $tenantId,
            ':public_id' => sprintf('00000000-0000-4000-8000-%012d', 100000 + ($tenantId * 10) + $index + 1),
            ':key' => (string) $role['key'],
            ':name' => (string) $role['name'],
            ':description' => (string) $role['description'],
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $update->execute([
            ':tenant_id' => $tenantId,
            ':key' => (string) $role['key'],
            ':name' => (string) $role['name'],
            ':description' => (string) $role['description'],
            ':updated_at' => $now,
        ]);
    }

    $query = $pdo->prepare('SELECT id, key, name FROM governance_roles WHERE tenant_id = :tenant_id');
    $query->execute([':tenant_id' => $tenantId]);
    $roleIds = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $roleIds[strtolower((string) ($row['key'] ?? ''))] = (int) ($row['id'] ?? 0);
    }

    return $roleIds;
}

function videochat_governance_seed_role_relationships(PDO $pdo, int $tenantId, array $roleIds, array $roles): void
{
    $insertModule = $pdo->prepare(
        'INSERT OR IGNORE INTO governance_role_modules(tenant_id, role_id, module_key) VALUES(:tenant_id, :role_id, :module_key)'
    );
    $insertPermission = $pdo->prepare(
        <<<'SQL'
INSERT OR IGNORE INTO governance_role_permissions(tenant_id, role_id, permission_key, resource_type, action)
VALUES(:tenant_id, :role_id, :permission_key, :resource_type, :action)
SQL
    );

    foreach ($roles as $role) {
        $roleId = $roleIds[strtolower((string) $role['key'])] ?? 0;
        if ($roleId <= 0) {
            continue;
        }
        foreach ((array) ($role['modules'] ?? []) as $moduleKey) {
            $module = trim((string) $moduleKey);
            if ($module !== '') {
                $insertModule->execute([':tenant_id' => $tenantId, ':role_id' => $roleId, ':module_key' => $module]);
            }
        }
        foreach ((array) ($role['permissions'] ?? []) as $permissionKey) {
            $permission = videochat_governance_seed_permission((string) $permissionKey);
            if (!is_array($permission)) {
                continue;
            }
            $insertPermission->execute([
                ':tenant_id' => $tenantId,
                ':role_id' => $roleId,
                ':permission_key' => $permission['permission_key'],
                ':resource_type' => $permission['resource_type'],
                ':action' => $permission['action'],
            ]);
        }
    }
}

function videochat_governance_seed_default_group_id(PDO $pdo, int $tenantId, string $name): int
{
    $query = $pdo->prepare('SELECT id FROM "groups" WHERE tenant_id = :tenant_id AND lower(name) = lower(:name) LIMIT 1');
    $query->execute([':tenant_id' => $tenantId, ':name' => $name]);
    return (int) ($query->fetchColumn() ?: 0);
}

function videochat_governance_seed_policies(PDO $pdo, int $tenantId, array $policies): array
{
    $now = gmdate('c');
    $insertPolicy = $pdo->prepare(
        <<<'SQL'
INSERT OR IGNORE INTO governance_policies(tenant_id, public_id, key, name, description, status, created_at, updated_at)
VALUES(:tenant_id, :public_id, :key, :name, :description, 'active', :created_at, :updated_at)
SQL
    );
    $updatePolicy = $pdo->prepare(
        <<<'SQL'
UPDATE governance_policies
SET name = :name,
    description = :description,
    status = 'active',
    updated_at = :updated_at
WHERE tenant_id = :tenant_id
  AND lower(key) = lower(:key)
SQL
    );
    $insertGroup = $pdo->prepare('INSERT OR IGNORE INTO governance_policy_groups(tenant_id, policy_id, group_id) VALUES(:tenant_id, :policy_id, :group_id)');
    $insertPermission = $pdo->prepare(
        <<<'SQL'
INSERT OR IGNORE INTO governance_policy_permissions(tenant_id, policy_id, permission_key, resource_type, action)
VALUES(:tenant_id, :policy_id, :permission_key, :resource_type, :action)
SQL
    );
    $deletePolicyGrants = $pdo->prepare('DELETE FROM permission_grants WHERE tenant_id = :tenant_id AND source = :source');
    $insertGrant = $pdo->prepare(
        <<<'SQL'
INSERT INTO permission_grants(
    tenant_id, public_id, resource_type, resource_id, action, subject_type, group_id,
    created_by_user_id, label, description, permission_key, source, created_at, updated_at
) VALUES(
    :tenant_id, :public_id, :resource_type, '*', :action, 'group', :group_id,
    NULL, :label, :description, :permission_key, :source, :created_at, :updated_at
)
SQL
    );

    $policyIds = [];
    foreach ($policies as $index => $policy) {
        $publicId = videochat_governance_seed_public_id($tenantId, 300000, $index + 1);
        $insertPolicy->execute([
            ':tenant_id' => $tenantId,
            ':public_id' => $publicId,
            ':key' => (string) $policy['key'],
            ':name' => (string) $policy['name'],
            ':description' => (string) $policy['description'],
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $updatePolicy->execute([
            ':tenant_id' => $tenantId,
            ':key' => (string) $policy['key'],
            ':name' => (string) $policy['name'],
            ':description' => (string) $policy['description'],
            ':updated_at' => $now,
        ]);

        $query = $pdo->prepare('SELECT id, public_id FROM governance_policies WHERE tenant_id = :tenant_id AND lower(key) = lower(:key) LIMIT 1');
        $query->execute([':tenant_id' => $tenantId, ':key' => (string) $policy['key']]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        $policyId = is_array($row) ? (int) ($row['id'] ?? 0) : 0;
        $policyPublicId = is_array($row) ? (string) ($row['public_id'] ?? $publicId) : $publicId;
        if ($policyId <= 0) {
            continue;
        }
        $policyIds[strtolower((string) $policy['key'])] = $policyId;
        $groupIds = [];
        foreach ((array) ($policy['groups'] ?? []) as $groupName) {
            $groupId = videochat_governance_seed_default_group_id($pdo, $tenantId, (string) $groupName);
            if ($groupId > 0) {
                $groupIds[] = $groupId;
                $insertGroup->execute([':tenant_id' => $tenantId, ':policy_id' => $policyId, ':group_id' => $groupId]);
            }
        }
        $permissions = [];
        foreach ((array) ($policy['permissions'] ?? []) as $permissionKey) {
            $permission = videochat_governance_seed_permission((string) $permissionKey);
            if (!is_array($permission)) {
                continue;
            }
            $permissions[] = $permission;
            $insertPermission->execute([
                ':tenant_id' => $tenantId,
                ':policy_id' => $policyId,
                ':permission_key' => $permission['permission_key'],
                ':resource_type' => $permission['resource_type'],
                ':action' => $permission['action'],
            ]);
        }
        $source = 'policy:' . $policyPublicId;
        $deletePolicyGrants->execute([':tenant_id' => $tenantId, ':source' => $source]);
        foreach ($groupIds as $groupIndex => $groupId) {
            foreach ($permissions as $permissionIndex => $permission) {
                $insertGrant->execute([
                    ':tenant_id' => $tenantId,
                    ':public_id' => videochat_governance_seed_public_id($tenantId, 500000 + ($index * 100) + ($groupIndex * 10), $permissionIndex + 1),
                    ':resource_type' => $permission['resource_type'],
                    ':action' => $permission['action'],
                    ':group_id' => $groupId,
                    ':label' => (string) $policy['name'] . ': ' . (string) $permission['permission_key'],
                    ':description' => (string) $policy['description'],
                    ':permission_key' => $permission['permission_key'],
                    ':source' => $source,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);
            }
        }
    }

    return $policyIds;
}

function videochat_governance_seed_compliance(PDO $pdo, int $tenantId, array $policyIds, array $rules): void
{
    $now = gmdate('c');
    $insertRule = $pdo->prepare(
        <<<'SQL'
INSERT OR IGNORE INTO governance_compliance_rules(
    tenant_id, public_id, key, name, description, severity, status, created_at, updated_at
) VALUES(
    :tenant_id, :public_id, :key, :name, :description, :severity, :status, :created_at, :updated_at
)
SQL
    );
    $updateRule = $pdo->prepare(
        <<<'SQL'
UPDATE governance_compliance_rules
SET name = :name,
    description = :description,
    severity = :severity,
    status = :status,
    updated_at = :updated_at
WHERE tenant_id = :tenant_id
  AND lower(key) = lower(:key)
SQL
    );
    $insertModule = $pdo->prepare('INSERT OR IGNORE INTO governance_compliance_rule_modules(tenant_id, rule_id, module_key) VALUES(:tenant_id, :rule_id, :module_key)');
    $insertPolicy = $pdo->prepare('INSERT OR IGNORE INTO governance_compliance_rule_policies(tenant_id, rule_id, policy_id) VALUES(:tenant_id, :rule_id, :policy_id)');

    foreach ($rules as $index => $rule) {
        $insertRule->execute([
            ':tenant_id' => $tenantId,
            ':public_id' => videochat_governance_seed_public_id($tenantId, 400000, $index + 1),
            ':key' => (string) $rule['key'],
            ':name' => (string) $rule['name'],
            ':description' => (string) $rule['description'],
            ':severity' => (string) $rule['severity'],
            ':status' => (string) $rule['status'],
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $updateRule->execute([
            ':tenant_id' => $tenantId,
            ':key' => (string) $rule['key'],
            ':name' => (string) $rule['name'],
            ':description' => (string) $rule['description'],
            ':severity' => (string) $rule['severity'],
            ':status' => (string) $rule['status'],
            ':updated_at' => $now,
        ]);

        $query = $pdo->prepare('SELECT id FROM governance_compliance_rules WHERE tenant_id = :tenant_id AND lower(key) = lower(:key) LIMIT 1');
        $query->execute([':tenant_id' => $tenantId, ':key' => (string) $rule['key']]);
        $ruleId = (int) ($query->fetchColumn() ?: 0);
        if ($ruleId <= 0) {
            continue;
        }
        foreach ((array) ($rule['modules'] ?? []) as $moduleKey) {
            $module = trim((string) $moduleKey);
            if ($module !== '') {
                $insertModule->execute([':tenant_id' => $tenantId, ':rule_id' => $ruleId, ':module_key' => $module]);
            }
        }
        foreach ((array) ($rule['policies'] ?? []) as $policyKey) {
            $policyId = $policyIds[strtolower((string) $policyKey)] ?? 0;
            if ($policyId > 0) {
                $insertPolicy->execute([':tenant_id' => $tenantId, ':rule_id' => $ruleId, ':policy_id' => $policyId]);
            }
        }
    }
}

function videochat_seed_default_governance_data(PDO $pdo): array
{
    $requiredTables = [
        'tenants',
        'governance_roles',
        'governance_role_permissions',
        'governance_role_modules',
        'governance_policies',
        'governance_policy_groups',
        'governance_policy_permissions',
        'permission_grants',
        'governance_compliance_rules',
        'governance_compliance_rule_modules',
        'governance_compliance_rule_policies',
    ];
    if (!videochat_governance_seed_has_tables($pdo, $requiredTables)) {
        return [];
    }

    $roles = videochat_governance_seed_role_specs();
    $policies = videochat_governance_seed_policy_specs();
    $compliance = videochat_governance_seed_compliance_specs();
    $tenantRows = $pdo->query('SELECT id FROM tenants ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $seeded = [];
    foreach ($tenantRows as $tenantRow) {
        $tenantId = (int) ($tenantRow['id'] ?? 0);
        if ($tenantId <= 0) {
            continue;
        }
        $roleIds = videochat_governance_seed_roles($pdo, $tenantId, $roles);
        videochat_governance_seed_role_relationships($pdo, $tenantId, $roleIds, $roles);
        $policyIds = videochat_governance_seed_policies($pdo, $tenantId, $policies);
        videochat_governance_seed_compliance($pdo, $tenantId, $policyIds, $compliance);
        foreach ($roles as $role) {
            $seeded[] = ['tenant_id' => $tenantId, 'key' => (string) $role['key'], 'name' => (string) $role['name']];
        }
    }

    return $seeded;
}
