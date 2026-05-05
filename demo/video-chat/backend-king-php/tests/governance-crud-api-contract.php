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
    ]);
    $createOrganizationPayload = videochat_governance_crud_decode($createOrganization);
    $createdOrganization = (($createOrganizationPayload['result'] ?? [])['row'] ?? null);
    videochat_governance_crud_assert((int) ($createOrganization['status'] ?? 0) === 201, 'admin should create organization for group relation');
    videochat_governance_crud_assert(is_array($createdOrganization), 'created organization row missing');
    $createdOrganizationId = (string) ($createdOrganization['id'] ?? '');

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
