<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth_rbac.php';
require_once __DIR__ . '/../support/tenant_context.php';
require_once __DIR__ . '/../http/module_tenancy.php';

function videochat_governance_crud_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[governance-crud-api-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_governance_crud_decode(array $response): array
{
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

function videochat_governance_crud_auth(PDO $pdo, int $userId, string $role): array
{
    $tenant = videochat_tenant_context_for_user($pdo, $userId);
    videochat_governance_crud_assert(is_array($tenant), 'tenant context missing');

    return [
        'ok' => true,
        'token' => 'sess_governance_contract_' . $userId,
        'user' => [
            'id' => $userId,
            'role' => $role,
            'status' => 'active',
        ],
        'session' => ['id' => 'sess_governance_contract_' . $userId],
        'tenant' => videochat_tenant_auth_payload($tenant),
    ];
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-governance-crud-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);
    $tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    $adminUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $regularUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('user@intelligent-intern.com') LIMIT 1")->fetchColumn();
    videochat_governance_crud_assert($tenantId > 0 && $adminUserId > 0 && $regularUserId > 0, 'fixture ids missing');

    $jsonResponse = static function (int $status, array $payload): array {
        return [
            'status' => $status,
            'headers' => ['content-type' => 'application/json; charset=utf-8'],
            'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    };
    $errorResponse = static function (int $status, string $code, string $message, array $details = []) use ($jsonResponse): array {
        return $jsonResponse($status, [
            'status' => 'error',
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ],
            'time' => gmdate('c'),
        ]);
    };
    $decodeJsonBody = static function (array $request): array {
        $body = $request['body'] ?? '';
        if (!is_string($body) || trim($body) === '') {
            return [null, 'empty_body'];
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? [$decoded, null] : [null, 'invalid_json'];
    };
    $openDatabase = static fn (): PDO => videochat_open_sqlite_pdo($databasePath);
    $adminAuth = videochat_governance_crud_auth($pdo, $adminUserId, 'admin');
    $userAuth = videochat_governance_crud_auth($pdo, $regularUserId, 'user');

    $governanceRule = videochat_rbac_rule_for_path('/api/governance/groups');
    videochat_governance_crud_assert(is_array($governanceRule), 'governance RBAC rule missing');
    videochat_governance_crud_assert((string) ($governanceRule['id'] ?? '') === 'rest_governance_scope', 'governance RBAC rule id mismatch');
    videochat_governance_crud_assert((array) ($governanceRule['allowed_roles'] ?? []) === ['admin', 'user'], 'governance RBAC should pass authenticated users to route permissions');

    $dispatch = static function (string $method, string $path, array $auth, array $payload = null) use (
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    ): array {
        $request = [
            'method' => $method,
            'uri' => $path,
            'body' => is_array($payload) ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '',
        ];
        $response = videochat_handle_tenancy_routes(
            $path,
            $method,
            $request,
            $auth,
            $jsonResponse,
            $errorResponse,
            $decodeJsonBody,
            $openDatabase
        );
        videochat_governance_crud_assert(is_array($response), 'route should return a response');

        return $response;
    };

    $groupsList = $dispatch('GET', '/api/governance/groups', $adminAuth);
    $groupsPayload = videochat_governance_crud_decode($groupsList);
    videochat_governance_crud_assert((int) ($groupsList['status'] ?? 0) === 200, 'admin should list groups');
    videochat_governance_crud_assert(count((array) (($groupsPayload['result'] ?? [])['rows'] ?? [])) >= 1, 'group list should include seeded group');

    $userSummaries = $dispatch('GET', '/api/governance/users', $adminAuth);
    $userSummaryPayload = videochat_governance_crud_decode($userSummaries);
    $userRows = (array) (($userSummaryPayload['result'] ?? [])['rows'] ?? []);
    videochat_governance_crud_assert((int) ($userSummaries['status'] ?? 0) === 200, 'admin should list governance user summaries');
    videochat_governance_crud_assert(
        count(array_filter($userRows, static fn ($row): bool => is_array($row) && (string) ($row['id'] ?? '') === (string) $regularUserId)) === 1,
        'governance user summaries should include tenant users'
    );
    videochat_governance_crud_assert(!array_key_exists('password_hash', is_array($userRows[0] ?? null) ? $userRows[0] : []), 'governance user summaries must not expose password hashes');

    $invalidMemberCreate = $dispatch('POST', '/api/governance/groups', $adminAuth, [
        'name' => 'Invalid Member Group',
        'relationships' => [
            'members' => [
                ['entity_key' => 'users', 'id' => '999999'],
            ],
        ],
    ]);
    videochat_governance_crud_assert((int) ($invalidMemberCreate['status'] ?? 0) === 422, 'invalid group member reference should fail validation');
    $invalidMemberGroupCount = (int) $pdo->query("SELECT COUNT(*) FROM \"groups\" WHERE name = 'Invalid Member Group'")->fetchColumn();
    videochat_governance_crud_assert($invalidMemberGroupCount === 0, 'invalid member group must not be created');

    $createOrganization = $dispatch('POST', '/api/governance/organizations', $adminAuth, [
        'name' => 'Contract Organization',
        'status' => 'active',
        'relationships' => [
            'users' => [
                ['entity_key' => 'users', 'id' => (string) $regularUserId],
            ],
        ],
    ]);
    $createOrganizationPayload = videochat_governance_crud_decode($createOrganization);
    $createdOrganization = (($createOrganizationPayload['result'] ?? [])['row'] ?? null);
    videochat_governance_crud_assert((int) ($createOrganization['status'] ?? 0) === 201, 'admin should create organization for group relation');
    videochat_governance_crud_assert(is_array($createdOrganization), 'created organization row missing');
    videochat_governance_crud_assert(
        (string) (((($createdOrganization['relationships'] ?? [])['users'] ?? [])[0] ?? [])['id'] ?? '') === (string) $regularUserId,
        'created organization response should include selected user summary'
    );
    $createdOrganizationId = (string) ($createdOrganization['id'] ?? '');
    $organizationMemberCount = (int) $pdo->query("SELECT COUNT(*) FROM organization_memberships INNER JOIN organizations ON organizations.id = organization_memberships.organization_id WHERE organizations.public_id = '{$createdOrganizationId}' AND organization_memberships.user_id = {$regularUserId} AND organization_memberships.status = 'active'")->fetchColumn();
    videochat_governance_crud_assert($organizationMemberCount === 1, 'created organization user should be persisted');

    $clearOrganizationUsers = $dispatch('PATCH', '/api/governance/organizations/' . rawurlencode($createdOrganizationId), $adminAuth, [
        'name' => 'Contract Organization',
        'status' => 'active',
        'relationships' => [
            'users' => [],
        ],
    ]);
    $clearOrganizationUsersPayload = videochat_governance_crud_decode($clearOrganizationUsers);
    videochat_governance_crud_assert((int) ($clearOrganizationUsers['status'] ?? 0) === 200, 'admin should clear organization users');
    videochat_governance_crud_assert(
        count((array) (((($clearOrganizationUsersPayload['result'] ?? [])['row'] ?? [])['relationships'] ?? [])['users'] ?? [])) === 0,
        'cleared organization response should include empty users relationship'
    );
    $clearedOrganizationMemberCount = (int) $pdo->query("SELECT COUNT(*) FROM organization_memberships INNER JOIN organizations ON organizations.id = organization_memberships.organization_id WHERE organizations.public_id = '{$createdOrganizationId}' AND organization_memberships.user_id = {$regularUserId} AND organization_memberships.status = 'active'")->fetchColumn();
    videochat_governance_crud_assert($clearedOrganizationMemberCount === 0, 'cleared organization user should no longer be active');

    $createExportJob = $dispatch('POST', '/api/governance/data-portability-jobs', $adminAuth, [
        'job_type' => 'export',
        'relationships' => [
            'organization' => [
                ['entity_key' => 'organizations', 'id' => $createdOrganizationId],
            ],
        ],
    ]);
    $createExportJobPayload = videochat_governance_crud_decode($createExportJob);
    $createdExportJob = (($createExportJobPayload['result'] ?? [])['row'] ?? null);
    videochat_governance_crud_assert((int) ($createExportJob['status'] ?? 0) === 201, 'admin should create data portability export job');
    videochat_governance_crud_assert(is_array($createdExportJob), 'created export job row missing');
    videochat_governance_crud_assert((string) ($createdExportJob['job_type'] ?? '') === 'export', 'created export job type mismatch');
    videochat_governance_crud_assert((string) ($createdExportJob['status'] ?? '') === 'completed', 'created export job should complete synchronously');
    videochat_governance_crud_assert(
        (string) (((($createdExportJob['relationships'] ?? [])['organization'] ?? [])[0] ?? [])['id'] ?? '') === $createdOrganizationId,
        'created export job response should include selected organization summary'
    );
    $createdExportJobId = (string) ($createdExportJob['id'] ?? '');
    videochat_governance_crud_assert($createdExportJobId !== '', 'created export job id missing');
    $exportJobCount = (int) $pdo->query("SELECT COUNT(*) FROM tenant_export_jobs WHERE tenant_id = {$tenantId} AND id = '{$createdExportJobId}'")->fetchColumn();
    videochat_governance_crud_assert($exportJobCount === 1, 'created export job should be persisted');

    $exportJobList = $dispatch('GET', '/api/governance/data-portability-jobs', $adminAuth);
    $exportJobListPayload = videochat_governance_crud_decode($exportJobList);
    $exportJobRows = (array) (($exportJobListPayload['result'] ?? [])['rows'] ?? []);
    videochat_governance_crud_assert((int) ($exportJobList['status'] ?? 0) === 200, 'admin should list data portability jobs');
    videochat_governance_crud_assert(
        count(array_filter($exportJobRows, static fn ($row): bool => is_array($row) && (string) ($row['id'] ?? '') === $createdExportJobId)) === 1,
        'data portability job list should include created export job'
    );

    $importCountBeforeValidation = (int) $pdo->query("SELECT COUNT(*) FROM tenant_import_jobs WHERE tenant_id = {$tenantId}")->fetchColumn();
    $invalidImportJob = $dispatch('POST', '/api/governance/data-portability-jobs', $adminAuth, [
        'job_type' => 'import',
        'relationships' => [
            'organization' => [
                ['entity_key' => 'organizations', 'id' => $createdOrganizationId],
            ],
        ],
    ]);
    videochat_governance_crud_assert((int) ($invalidImportJob['status'] ?? 0) === 422, 'import job without bundle should fail validation');
    $importCountAfterValidation = (int) $pdo->query("SELECT COUNT(*) FROM tenant_import_jobs WHERE tenant_id = {$tenantId}")->fetchColumn();
    videochat_governance_crud_assert($importCountAfterValidation === $importCountBeforeValidation, 'invalid import request must not create a failed job row');

    $createGroup = $dispatch('POST', '/api/governance/groups', $adminAuth, [
        'name' => 'Contract Group',
        'status' => 'active',
        'relationships' => [
            'organization' => [
                ['entity_key' => 'organizations', 'id' => $createdOrganizationId],
            ],
            'members' => [
                ['entity_key' => 'users', 'id' => (string) $regularUserId],
            ],
            'permissions' => [
                ['entity_key' => 'permissions', 'id' => 'permission:governance:governance.organizations.create', 'key' => 'governance.organizations.create'],
            ],
            'modules' => [
                ['entity_key' => 'modules', 'id' => 'module:governance', 'key' => 'governance'],
            ],
        ],
    ]);
    $createGroupPayload = videochat_governance_crud_decode($createGroup);
    $createdGroup = (($createGroupPayload['result'] ?? [])['row'] ?? null);
    videochat_governance_crud_assert((int) ($createGroup['status'] ?? 0) === 201, 'admin should create group');
    videochat_governance_crud_assert(is_array($createdGroup), 'created group row missing');
    videochat_governance_crud_assert(preg_match('/^[0-9a-f-]{36}$/', (string) ($createdGroup['id'] ?? '')) === 1, 'created group should expose uuid id');
    videochat_governance_crud_assert(!array_key_exists('database_id', $createdGroup), 'created group must not expose internal database id');
    videochat_governance_crud_assert(
        (string) (((($createdGroup['relationships'] ?? [])['members'] ?? [])[0] ?? [])['id'] ?? '') === (string) $regularUserId,
        'created group response should include selected member summary'
    );
    videochat_governance_crud_assert(
        (string) (((($createdGroup['relationships'] ?? [])['organization'] ?? [])[0] ?? [])['id'] ?? '') === $createdOrganizationId,
        'created group response should include selected organization summary'
    );
    videochat_governance_crud_assert(
        (string) (((($createdGroup['relationships'] ?? [])['permissions'] ?? [])[0] ?? [])['key'] ?? '') === 'governance.organizations.create',
        'created group response should include selected permission summary'
    );
    videochat_governance_crud_assert(
        (string) (((($createdGroup['relationships'] ?? [])['modules'] ?? [])[0] ?? [])['key'] ?? '') === 'governance',
        'created group response should include selected module summary'
    );
    $createdGroupId = (string) ($createdGroup['id'] ?? '');
    $memberCount = (int) $pdo->query("SELECT COUNT(*) FROM group_memberships INNER JOIN \"groups\" ON \"groups\".id = group_memberships.group_id WHERE \"groups\".public_id = '{$createdGroupId}' AND group_memberships.user_id = {$regularUserId} AND group_memberships.status = 'active'")->fetchColumn();
    videochat_governance_crud_assert($memberCount === 1, 'created group member should be persisted');

    $updateGroup = $dispatch('PATCH', '/api/governance/groups/' . rawurlencode($createdGroupId), $adminAuth, [
        'name' => 'Contract Group Updated',
        'status' => 'archived',
    ]);
    $updateGroupPayload = videochat_governance_crud_decode($updateGroup);
    videochat_governance_crud_assert((int) ($updateGroup['status'] ?? 0) === 200, 'admin should update group');
    videochat_governance_crud_assert(
        (string) (((($updateGroupPayload['result'] ?? [])['row'] ?? [])['status'] ?? '')) === 'archived',
        'updated group status mismatch'
    );
    videochat_governance_crud_assert(
        (string) (((((($updateGroupPayload['result'] ?? [])['row'] ?? [])['relationships'] ?? [])['members'] ?? [])[0] ?? [])['id'] ?? '') === (string) $regularUserId,
        'field-only update should preserve group members'
    );
    videochat_governance_crud_assert(
        (string) (((((($updateGroupPayload['result'] ?? [])['row'] ?? [])['relationships'] ?? [])['permissions'] ?? [])[0] ?? [])['key'] ?? '') === 'governance.organizations.create',
        'field-only update should preserve group permission grants'
    );
    videochat_governance_crud_assert(
        (string) (((((($updateGroupPayload['result'] ?? [])['row'] ?? [])['relationships'] ?? [])['modules'] ?? [])[0] ?? [])['key'] ?? '') === 'governance',
        'field-only update should preserve group module grants'
    );
    $moduleGrant = videochat_tenancy_user_has_resource_permission($pdo, $tenantId, $regularUserId, 'module', 'governance', 'read');
    videochat_governance_crud_assert((bool) ($moduleGrant['ok'] ?? false), 'group module relation should grant module read access');

    $createRole = $dispatch('POST', '/api/governance/roles', $adminAuth, [
        'name' => 'Contract Role',
        'key' => 'contract.role',
        'status' => 'active',
        'relationships' => [
            'permissions' => [
                ['entity_key' => 'permissions', 'id' => 'permission:governance:governance.groups.create', 'key' => 'governance.groups.create'],
            ],
            'modules' => [
                ['entity_key' => 'modules', 'id' => 'module:governance', 'key' => 'governance'],
            ],
        ],
    ]);
    $createRolePayload = videochat_governance_crud_decode($createRole);
    $createdRole = (($createRolePayload['result'] ?? [])['row'] ?? null);
    videochat_governance_crud_assert((int) ($createRole['status'] ?? 0) === 201, 'admin should create governance role');
    videochat_governance_crud_assert(is_array($createdRole), 'created role row missing');
    videochat_governance_crud_assert(preg_match('/^[0-9a-f-]{36}$/', (string) ($createdRole['id'] ?? '')) === 1, 'created role should expose uuid id');
    videochat_governance_crud_assert(!array_key_exists('database_id', $createdRole), 'created role must not expose internal database id');
    videochat_governance_crud_assert(
        (string) (((($createdRole['relationships'] ?? [])['permissions'] ?? [])[0] ?? [])['key'] ?? '') === 'governance.groups.create',
        'created role response should include selected permission summary'
    );
    videochat_governance_crud_assert(
        (string) (((($createdRole['relationships'] ?? [])['modules'] ?? [])[0] ?? [])['key'] ?? '') === 'governance',
        'created role response should include selected module summary'
    );
    $createdRoleId = (string) ($createdRole['id'] ?? '');
    $rolePermissionCount = (int) $pdo->query("SELECT COUNT(*) FROM governance_role_permissions INNER JOIN governance_roles ON governance_roles.id = governance_role_permissions.role_id WHERE governance_roles.public_id = '{$createdRoleId}' AND governance_role_permissions.permission_key = 'governance.groups.create'")->fetchColumn();
    videochat_governance_crud_assert($rolePermissionCount === 1, 'created role permission should be persisted');

    $assignRoleToGroup = $dispatch('PATCH', '/api/governance/groups/' . rawurlencode($createdGroupId), $adminAuth, [
        'name' => 'Contract Group Updated',
        'status' => 'archived',
        'relationships' => [
            'roles' => [
                ['entity_key' => 'roles', 'id' => $createdRoleId],
            ],
        ],
    ]);
    $assignRoleToGroupPayload = videochat_governance_crud_decode($assignRoleToGroup);
    videochat_governance_crud_assert((int) ($assignRoleToGroup['status'] ?? 0) === 200, 'admin should assign governance role to group');
    videochat_governance_crud_assert(
        (string) (((((($assignRoleToGroupPayload['result'] ?? [])['row'] ?? [])['relationships'] ?? [])['roles'] ?? [])[0] ?? [])['id'] ?? '') === $createdRoleId,
        'updated group response should include selected role summary'
    );
    $groupRoleAssignmentCount = (int) $pdo->query("SELECT COUNT(*) FROM governance_group_roles INNER JOIN \"groups\" ON \"groups\".id = governance_group_roles.group_id INNER JOIN governance_roles ON governance_roles.id = governance_group_roles.role_id WHERE \"groups\".public_id = '{$createdGroupId}' AND governance_roles.public_id = '{$createdRoleId}'")->fetchColumn();
    videochat_governance_crud_assert($groupRoleAssignmentCount === 1, 'group role assignment should be persisted');
    $groupRoleGrantCount = (int) $pdo->query("SELECT COUNT(*) FROM permission_grants INNER JOIN \"groups\" ON \"groups\".id = permission_grants.group_id WHERE \"groups\".public_id = '{$createdGroupId}' AND permission_grants.source = 'group_roles' AND permission_grants.permission_key = 'governance.groups.create'")->fetchColumn();
    videochat_governance_crud_assert($groupRoleGrantCount === 1, 'group role assignment should expand role permission into evaluator grants');
    $roleGrantedCreate = $dispatch('POST', '/api/governance/groups', $userAuth, [
        'name' => 'Role Granted Group',
    ]);
    videochat_governance_crud_assert((int) ($roleGrantedCreate['status'] ?? 0) === 201, 'group role permission relation should allow member group creation');

    $updateRole = $dispatch('PATCH', '/api/governance/roles/' . rawurlencode($createdRoleId), $adminAuth, [
        'name' => 'Contract Role',
        'key' => 'contract.role',
        'status' => 'active',
        'relationships' => [
            'permissions' => [],
            'modules' => [],
        ],
    ]);
    $updateRolePayload = videochat_governance_crud_decode($updateRole);
    videochat_governance_crud_assert((int) ($updateRole['status'] ?? 0) === 200, 'admin should update governance role');
    videochat_governance_crud_assert(
        count((array) (((($updateRolePayload['result'] ?? [])['row'] ?? [])['relationships'] ?? [])['permissions'] ?? [])) === 0,
        'updated role should clear permission relation'
    );
    $clearedGroupRoleGrantCount = (int) $pdo->query("SELECT COUNT(*) FROM permission_grants INNER JOIN \"groups\" ON \"groups\".id = permission_grants.group_id WHERE \"groups\".public_id = '{$createdGroupId}' AND permission_grants.source = 'group_roles'")->fetchColumn();
    videochat_governance_crud_assert($clearedGroupRoleGrantCount === 0, 'clearing role permissions should remove role-sourced group grants');
    $deleteRole = $dispatch('DELETE', '/api/governance/roles/' . rawurlencode($createdRoleId), $adminAuth);
    videochat_governance_crud_assert((int) ($deleteRole['status'] ?? 0) === 200, 'admin should delete governance role');
    $deletedRoleAssignmentCount = (int) $pdo->query("SELECT COUNT(*) FROM governance_group_roles INNER JOIN \"groups\" ON \"groups\".id = governance_group_roles.group_id WHERE \"groups\".public_id = '{$createdGroupId}'")->fetchColumn();
    videochat_governance_crud_assert($deletedRoleAssignmentCount === 0, 'deleting role should remove group role assignments');

    $createOrganizationRole = $dispatch('POST', '/api/governance/roles', $adminAuth, [
        'name' => 'Contract Organization Role',
        'key' => 'contract.organization.role',
        'status' => 'active',
        'relationships' => [
            'permissions' => [
                ['entity_key' => 'permissions', 'id' => 'permission:governance:governance.groups.create', 'key' => 'governance.groups.create'],
            ],
        ],
    ]);
    $createOrganizationRolePayload = videochat_governance_crud_decode($createOrganizationRole);
    $createdOrganizationRole = (($createOrganizationRolePayload['result'] ?? [])['row'] ?? null);
    videochat_governance_crud_assert((int) ($createOrganizationRole['status'] ?? 0) === 201, 'admin should create organization governance role');
    videochat_governance_crud_assert(is_array($createdOrganizationRole), 'created organization role row missing');
    $createdOrganizationRoleId = (string) ($createdOrganizationRole['id'] ?? '');
    $assignRoleToOrganization = $dispatch('PATCH', '/api/governance/organizations/' . rawurlencode($createdOrganizationId), $adminAuth, [
        'name' => 'Contract Organization',
        'status' => 'active',
        'relationships' => [
            'users' => [
                ['entity_key' => 'users', 'id' => (string) $regularUserId],
            ],
            'roles' => [
                ['entity_key' => 'roles', 'id' => $createdOrganizationRoleId],
            ],
        ],
    ]);
    $assignRoleToOrganizationPayload = videochat_governance_crud_decode($assignRoleToOrganization);
    videochat_governance_crud_assert((int) ($assignRoleToOrganization['status'] ?? 0) === 200, 'admin should assign governance role to organization');
    videochat_governance_crud_assert(
        (string) (((((($assignRoleToOrganizationPayload['result'] ?? [])['row'] ?? [])['relationships'] ?? [])['roles'] ?? [])[0] ?? [])['id'] ?? '') === $createdOrganizationRoleId,
        'updated organization response should include selected role summary'
    );
    $organizationRoleAssignmentCount = (int) $pdo->query("SELECT COUNT(*) FROM governance_organization_roles INNER JOIN organizations ON organizations.id = governance_organization_roles.organization_id INNER JOIN governance_roles ON governance_roles.id = governance_organization_roles.role_id WHERE organizations.public_id = '{$createdOrganizationId}' AND governance_roles.public_id = '{$createdOrganizationRoleId}'")->fetchColumn();
    videochat_governance_crud_assert($organizationRoleAssignmentCount === 1, 'organization role assignment should be persisted');
    $organizationRoleGrantCount = (int) $pdo->query("SELECT COUNT(*) FROM permission_grants INNER JOIN organizations ON organizations.id = permission_grants.organization_id WHERE organizations.public_id = '{$createdOrganizationId}' AND permission_grants.source = 'organization_roles' AND permission_grants.permission_key = 'governance.groups.create'")->fetchColumn();
    videochat_governance_crud_assert($organizationRoleGrantCount === 1, 'organization role assignment should expand role permission into evaluator grants');
    $organizationRoleGrantedCreate = $dispatch('POST', '/api/governance/groups', $userAuth, [
        'name' => 'Organization Role Granted Group',
    ]);
    videochat_governance_crud_assert((int) ($organizationRoleGrantedCreate['status'] ?? 0) === 201, 'organization role permission relation should allow member group creation');
    $clearOrganizationRole = $dispatch('PATCH', '/api/governance/roles/' . rawurlencode($createdOrganizationRoleId), $adminAuth, [
        'name' => 'Contract Organization Role',
        'key' => 'contract.organization.role',
        'status' => 'active',
        'relationships' => [
            'permissions' => [],
            'modules' => [],
        ],
    ]);
    videochat_governance_crud_assert((int) ($clearOrganizationRole['status'] ?? 0) === 200, 'admin should clear organization role permissions');
    $clearedOrganizationRoleGrantCount = (int) $pdo->query("SELECT COUNT(*) FROM permission_grants INNER JOIN organizations ON organizations.id = permission_grants.organization_id WHERE organizations.public_id = '{$createdOrganizationId}' AND permission_grants.source = 'organization_roles'")->fetchColumn();
    videochat_governance_crud_assert($clearedOrganizationRoleGrantCount === 0, 'clearing role permissions should remove organization role-sourced grants');
    $deleteOrganizationRole = $dispatch('DELETE', '/api/governance/roles/' . rawurlencode($createdOrganizationRoleId), $adminAuth);
    videochat_governance_crud_assert((int) ($deleteOrganizationRole['status'] ?? 0) === 200, 'admin should delete organization governance role');
    $deletedOrganizationRoleAssignmentCount = (int) $pdo->query("SELECT COUNT(*) FROM governance_organization_roles INNER JOIN organizations ON organizations.id = governance_organization_roles.organization_id WHERE organizations.public_id = '{$createdOrganizationId}'")->fetchColumn();
    videochat_governance_crud_assert($deletedOrganizationRoleAssignmentCount === 0, 'deleting role should remove organization role assignments');

    $groupPermissionCreateOrganization = $dispatch('POST', '/api/governance/organizations', $userAuth, [
        'name' => 'Group Permission Organization',
    ]);
    videochat_governance_crud_assert((int) ($groupPermissionCreateOrganization['status'] ?? 0) === 201, 'group permission relation should allow member organization creation');

    $createPolicy = $dispatch('POST', '/api/governance/policies', $adminAuth, [
        'name' => 'Contract Policy',
        'key' => 'contract.policy',
        'status' => 'active',
        'relationships' => [
            'groups' => [
                ['entity_key' => 'groups', 'id' => $createdGroupId],
            ],
            'organizations' => [
                ['entity_key' => 'organizations', 'id' => $createdOrganizationId],
            ],
            'permissions' => [
                ['entity_key' => 'permissions', 'id' => 'permission:governance:governance.groups.create', 'key' => 'governance.groups.create'],
            ],
        ],
    ]);
    $createPolicyPayload = videochat_governance_crud_decode($createPolicy);
    $createdPolicy = (($createPolicyPayload['result'] ?? [])['row'] ?? null);
    videochat_governance_crud_assert((int) ($createPolicy['status'] ?? 0) === 201, 'admin should create governance policy');
    videochat_governance_crud_assert(is_array($createdPolicy), 'created policy row missing');
    videochat_governance_crud_assert(preg_match('/^[0-9a-f-]{36}$/', (string) ($createdPolicy['id'] ?? '')) === 1, 'created policy should expose uuid id');
    videochat_governance_crud_assert(!array_key_exists('database_id', $createdPolicy), 'created policy must not expose internal database id');
    videochat_governance_crud_assert(
        (string) (((($createdPolicy['relationships'] ?? [])['groups'] ?? [])[0] ?? [])['id'] ?? '') === $createdGroupId,
        'created policy response should include selected group summary'
    );
    videochat_governance_crud_assert(
        (string) (((($createdPolicy['relationships'] ?? [])['permissions'] ?? [])[0] ?? [])['key'] ?? '') === 'governance.groups.create',
        'created policy response should include selected permission summary'
    );
    $createdPolicyId = (string) ($createdPolicy['id'] ?? '');
    $policyGrantCount = (int) $pdo->query("SELECT COUNT(*) FROM permission_grants WHERE tenant_id = {$tenantId} AND source = 'policy:{$createdPolicyId}' AND subject_type = 'group' AND action = 'create'")->fetchColumn();
    videochat_governance_crud_assert($policyGrantCount === 1, 'policy permissions should sync to evaluator grants');
    $policyGrantedCreate = $dispatch('POST', '/api/governance/groups', $userAuth, [
        'name' => 'Policy Granted Group',
    ]);
    videochat_governance_crud_assert((int) ($policyGrantedCreate['status'] ?? 0) === 201, 'policy permission relation should allow member group creation');
    $deletePolicy = $dispatch('DELETE', '/api/governance/policies/' . rawurlencode($createdPolicyId), $adminAuth);
    videochat_governance_crud_assert((int) ($deletePolicy['status'] ?? 0) === 200, 'admin should delete governance policy');
    $deletedPolicyGrantCount = (int) $pdo->query("SELECT COUNT(*) FROM permission_grants WHERE tenant_id = {$tenantId} AND source = 'policy:{$createdPolicyId}'")->fetchColumn();
    videochat_governance_crud_assert($deletedPolicyGrantCount === 0, 'deleting policy should remove policy-sourced grants');

    $createGrant = $dispatch('POST', '/api/governance/grants', $adminAuth, [
        'name' => 'Contract Group Create Grant',
        'subject_type' => 'group',
        'valid_from' => gmdate('c', time() - 60),
        'valid_until' => gmdate('c', time() + 3600),
        'relationships' => [
            'subject' => [
                ['entity_key' => 'groups', 'id' => $createdGroupId],
            ],
            'permission' => [
                ['entity_key' => 'permissions', 'id' => 'permission:governance:governance.groups.create', 'key' => 'governance.groups.create'],
            ],
        ],
    ]);
    $createGrantPayload = videochat_governance_crud_decode($createGrant);
    $createdGrant = (($createGrantPayload['result'] ?? [])['row'] ?? null);
    videochat_governance_crud_assert((int) ($createGrant['status'] ?? 0) === 201, 'admin should create governance grant');
    videochat_governance_crud_assert(is_array($createdGrant), 'created grant row missing');
    videochat_governance_crud_assert(preg_match('/^[0-9a-f-]{36}$/', (string) ($createdGrant['id'] ?? '')) === 1, 'created grant should expose uuid id');
    videochat_governance_crud_assert(!array_key_exists('database_id', $createdGrant), 'created grant must not expose internal database id');
    videochat_governance_crud_assert((string) ($createdGrant['action'] ?? '') === 'create', 'created grant action mismatch');
    videochat_governance_crud_assert((string) ($createdGrant['resource_type'] ?? '') === 'group', 'created grant resource type mismatch');
    videochat_governance_crud_assert(
        (string) (((($createdGrant['relationships'] ?? [])['subject'] ?? [])[0] ?? [])['id'] ?? '') === $createdGroupId,
        'created grant response should include selected group subject'
    );
    $apiGrantedCreate = $dispatch('POST', '/api/governance/groups', $userAuth, [
        'name' => 'API Granted Group',
    ]);
    videochat_governance_crud_assert((int) ($apiGrantedCreate['status'] ?? 0) === 201, 'API-created group grant should allow member to create group');
    $deleteGrant = $dispatch('DELETE', '/api/governance/grants/' . rawurlencode((string) ($createdGrant['id'] ?? '')), $adminAuth);
    videochat_governance_crud_assert((int) ($deleteGrant['status'] ?? 0) === 200, 'admin should delete governance grant');

    $clearMembers = $dispatch('PATCH', '/api/governance/groups/' . rawurlencode($createdGroupId), $adminAuth, [
        'name' => 'Contract Group Updated',
        'status' => 'archived',
        'relationships' => [
            'members' => [],
        ],
    ]);
    $clearMembersPayload = videochat_governance_crud_decode($clearMembers);
    videochat_governance_crud_assert((int) ($clearMembers['status'] ?? 0) === 200, 'admin should clear group members');
    videochat_governance_crud_assert(
        count((array) (((($clearMembersPayload['result'] ?? [])['row'] ?? [])['relationships'] ?? [])['members'] ?? [])) === 0,
        'cleared group response should include an empty members relationship'
    );
    $clearedMemberCount = (int) $pdo->query("SELECT COUNT(*) FROM group_memberships INNER JOIN \"groups\" ON \"groups\".id = group_memberships.group_id WHERE \"groups\".public_id = '{$createdGroupId}' AND group_memberships.user_id = {$regularUserId} AND group_memberships.status = 'active'")->fetchColumn();
    videochat_governance_crud_assert($clearedMemberCount === 0, 'cleared group member should no longer be active');

    $otherTenantPublicId = '00000000-0000-4000-8000-000000009901';
    $otherGroupPublicId = '00000000-0000-4000-8000-000000009902';
    $pdo->exec("INSERT INTO tenants(public_id, slug, label, status) VALUES('{$otherTenantPublicId}', 'other-contract', 'Other Contract', 'active')");
    $otherTenantId = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO \"groups\"(tenant_id, public_id, name, status) VALUES({$otherTenantId}, '{$otherGroupPublicId}', 'Other Tenant Group', 'active')");
    $wrongTenantUpdate = $dispatch('PATCH', '/api/governance/groups/' . $otherGroupPublicId, $adminAuth, [
        'name' => 'Wrong Tenant Mutation',
    ]);
    videochat_governance_crud_assert((int) ($wrongTenantUpdate['status'] ?? 0) === 404, 'wrong-tenant group must not be mutable');

    $regularDenied = $dispatch('POST', '/api/governance/groups', $userAuth, [
        'name' => 'Denied Group',
    ]);
    $regularDeniedPayload = videochat_governance_crud_decode($regularDenied);
    videochat_governance_crud_assert((int) ($regularDenied['status'] ?? 0) === 403, 'regular user without grant should be denied');
    videochat_governance_crud_assert((string) (($regularDeniedPayload['error'] ?? [])['code'] ?? '') === 'tenant_governance_forbidden', 'regular deny error code mismatch');

    $insertGrant = $pdo->prepare(
        <<<'SQL'
INSERT INTO permission_grants(
    tenant_id, resource_type, resource_id, action, subject_type, user_id,
    valid_from, valid_until, revoked_at, created_by_user_id
) VALUES(
    :tenant_id, :resource_type, :resource_id, :action, 'user', :user_id,
    :valid_from, :valid_until, :revoked_at, :created_by_user_id
)
SQL
    );
    $insertGrant->execute([
        ':tenant_id' => $tenantId,
        ':resource_type' => 'group',
        ':resource_id' => '*',
        ':action' => 'create',
        ':user_id' => $regularUserId,
        ':valid_from' => gmdate('c', time() - 3600),
        ':valid_until' => gmdate('c', time() - 60),
        ':revoked_at' => null,
        ':created_by_user_id' => $adminUserId,
    ]);
    $expiredDenied = $dispatch('POST', '/api/governance/groups', $userAuth, [
        'name' => 'Expired Grant Group',
    ]);
    videochat_governance_crud_assert((int) ($expiredDenied['status'] ?? 0) === 403, 'expired create grant should be denied');

    $insertGrant->execute([
        ':tenant_id' => $tenantId,
        ':resource_type' => 'organization',
        ':resource_id' => '*',
        ':action' => 'create',
        ':user_id' => $regularUserId,
        ':valid_from' => gmdate('c', time() - 3600),
        ':valid_until' => gmdate('c', time() + 3600),
        ':revoked_at' => gmdate('c', time() - 60),
        ':created_by_user_id' => $adminUserId,
    ]);
    $revokedDenied = $dispatch('POST', '/api/governance/organizations', $userAuth, [
        'name' => 'Revoked Grant Organization',
    ]);
    videochat_governance_crud_assert((int) ($revokedDenied['status'] ?? 0) === 403, 'revoked create grant should be denied');

    $insertGrant->execute([
        ':tenant_id' => $tenantId,
        ':resource_type' => 'group',
        ':resource_id' => '*',
        ':action' => 'create',
        ':user_id' => $regularUserId,
        ':valid_from' => gmdate('c', time() - 60),
        ':valid_until' => gmdate('c', time() + 3600),
        ':revoked_at' => null,
        ':created_by_user_id' => $adminUserId,
    ]);
    $grantedCreate = $dispatch('POST', '/api/governance/groups', $userAuth, [
        'name' => 'Granted Group',
    ]);
    videochat_governance_crud_assert((int) ($grantedCreate['status'] ?? 0) === 201, 'active resource grant should allow group creation');

    $deleteGroup = $dispatch('DELETE', '/api/governance/groups/' . rawurlencode($createdGroupId), $adminAuth);
    videochat_governance_crud_assert((int) ($deleteGroup['status'] ?? 0) === 200, 'admin should delete group');
    $deletedCount = (int) $pdo->query("SELECT COUNT(*) FROM \"groups\" WHERE public_id = '{$createdGroupId}'")->fetchColumn();
    videochat_governance_crud_assert($deletedCount === 0, 'deleted group should be removed from tenant table');

    @unlink($databasePath);
    fwrite(STDOUT, "[governance-crud-api-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[governance-crud-api-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
