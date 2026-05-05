<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../support/tenant_context.php';
require_once __DIR__ . '/../domain/users/user_management.php';
require_once __DIR__ . '/../http/module_users.php';

function videochat_admin_user_create_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[admin-user-create-contract] FAIL: {$message}\n");
    exit(1);
}

/**
 * @return array<string, mixed>
 */
function videochat_admin_user_create_decode_response(array $response): array
{
    $payload = json_decode((string) ($response['body'] ?? ''), true);
    if (!is_array($payload)) {
        return [];
    }

    return $payload;
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-admin-user-create-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $authPdo = videochat_open_sqlite_pdo($databasePath);
    $tenantId = (int) $authPdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    $adminTenant = videochat_tenant_context_for_user($authPdo, 1);
    videochat_admin_user_create_assert($tenantId > 0 && is_array($adminTenant), 'tenant auth fixture missing');

    $jsonResponse = static function (int $status, array $payload): array {
        return [
            'status' => $status,
            'headers' => ['content-type' => 'application/json; charset=utf-8'],
            'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    };

    $errorResponse = static function (int $status, string $code, string $message, array $details = []) use ($jsonResponse): array {
        $error = [
            'code' => $code,
            'message' => $message,
        ];
        if ($details !== []) {
            $error['details'] = $details;
        }

        return $jsonResponse($status, [
            'status' => 'error',
            'error' => $error,
            'time' => gmdate('c'),
        ]);
    };

    $decodeJsonBody = static function (array $request): array {
        $body = $request['body'] ?? '';
        if (!is_string($body) || trim($body) === '') {
            return [null, 'empty_body'];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return [null, 'invalid_json'];
        }

        return [$decoded, null];
    };

    $openDatabase = static function () use ($databasePath): PDO {
        return videochat_open_sqlite_pdo($databasePath);
    };

    $apiAuthContext = [
        'ok' => true,
        'token' => 'sess_admin_contract',
        'user' => [
            'id' => 1,
            'email' => 'admin@intelligent-intern.com',
            'display_name' => 'Admin',
            'role' => 'admin',
            'status' => 'active',
        ],
        'session' => ['id' => 'sess_admin_contract'],
        'tenant' => videochat_tenant_auth_payload($adminTenant),
    ];

    $invalidJson = videochat_handle_user_routes(
        '/api/admin/users',
        'POST',
        ['method' => 'POST', 'uri' => '/api/admin/users', 'body' => 'not-json'],
        $apiAuthContext,
        [],
        sys_get_temp_dir(),
        512000,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_admin_user_create_assert(is_array($invalidJson), 'invalid-json response must be an array');
    videochat_admin_user_create_assert((int) ($invalidJson['status'] ?? 0) === 400, 'invalid-json status should be 400');
    $invalidJsonPayload = videochat_admin_user_create_decode_response($invalidJson);
    videochat_admin_user_create_assert((string) ($invalidJsonPayload['status'] ?? '') === 'error', 'invalid-json payload should be error');
    videochat_admin_user_create_assert(
        (string) (($invalidJsonPayload['error'] ?? [])['code'] ?? '') === 'admin_user_invalid_request_body',
        'invalid-json error code mismatch'
    );

    $invalidPayload = videochat_handle_user_routes(
        '/api/admin/users',
        'POST',
        [
            'method' => 'POST',
            'uri' => '/api/admin/users',
            'body' => json_encode([
                'email' => 'not-an-email',
                'display_name' => '',
                'password' => '123',
                'role' => 'bad-role',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $apiAuthContext,
        [],
        sys_get_temp_dir(),
        512000,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_admin_user_create_assert(is_array($invalidPayload), 'invalid payload response must be an array');
    videochat_admin_user_create_assert((int) ($invalidPayload['status'] ?? 0) === 422, 'invalid payload status should be 422');
    $invalidPayloadBody = videochat_admin_user_create_decode_response($invalidPayload);
    videochat_admin_user_create_assert(
        (string) (($invalidPayloadBody['error'] ?? [])['code'] ?? '') === 'admin_user_validation_failed',
        'invalid payload error code mismatch'
    );
    videochat_admin_user_create_assert(
        (string) (((($invalidPayloadBody['error'] ?? [])['details'] ?? [])['fields'] ?? [])['role'] ?? '') === 'required_valid_role',
        'invalid payload role error mismatch'
    );

    $createdResponse = videochat_handle_user_routes(
        '/api/admin/users',
        'POST',
        [
            'method' => 'POST',
            'uri' => '/api/admin/users',
            'body' => json_encode([
                'email' => 'create-contract-user@intelligent-intern.com',
                'display_name' => 'Create Contract User',
                'password' => 'create-contract-password',
                'theme_editor_enabled' => true,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $apiAuthContext,
        [],
        sys_get_temp_dir(),
        512000,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_admin_user_create_assert(is_array($createdResponse), 'create response must be an array');
    videochat_admin_user_create_assert((int) ($createdResponse['status'] ?? 0) === 201, 'create status should be 201');

    $createdPayload = videochat_admin_user_create_decode_response($createdResponse);
    videochat_admin_user_create_assert((string) ($createdPayload['status'] ?? '') === 'ok', 'create payload status mismatch');
    videochat_admin_user_create_assert(
        (string) (($createdPayload['result'] ?? [])['state'] ?? '') === 'created',
        'create payload state mismatch'
    );
    $createdUser = ($createdPayload['result'] ?? [])['user'] ?? null;
    videochat_admin_user_create_assert(is_array($createdUser), 'create payload user should be an array');
    videochat_admin_user_create_assert(
        (string) ($createdUser['role'] ?? '') === 'user',
        'create payload role should default to user'
    );
    videochat_admin_user_create_assert(
        ($createdUser['theme_editor_enabled'] ?? null) === true,
        'create payload should expose theme editor permission'
    );

    $pdo = videochat_open_sqlite_pdo($databasePath);
    $persistedUser = $pdo->prepare(
        <<<'SQL'
SELECT users.email, users.theme_editor_enabled, roles.slug AS role_slug
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE lower(users.email) = lower(:email)
LIMIT 1
SQL
    );
    $persistedUser->execute([':email' => 'create-contract-user@intelligent-intern.com']);
    $persistedRow = $persistedUser->fetch();
    videochat_admin_user_create_assert(is_array($persistedRow), 'created user should be persisted');
    videochat_admin_user_create_assert(
        (string) ($persistedRow['role_slug'] ?? '') === 'user',
        'persisted user role should default to user'
    );
    videochat_admin_user_create_assert(
        (int) ($persistedRow['theme_editor_enabled'] ?? 0) === 1,
        'persisted user should keep theme editor permission'
    );

    $rolePublicId = '00000000-0000-4000-8000-00000000a501';
    $now = gmdate('c');
    $insertRole = $pdo->prepare(
        <<<'SQL'
INSERT INTO governance_roles(tenant_id, public_id, key, name, status, created_by_user_id, created_at, updated_at)
VALUES(:tenant_id, :public_id, 'admin.user.create.contract', 'Admin User Create Contract Role', 'active', 1, :created_at, :updated_at)
SQL
    );
    $insertRole->execute([
        ':tenant_id' => $tenantId,
        ':public_id' => $rolePublicId,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
    $roleId = (int) $pdo->lastInsertId();
    $insertRolePermission = $pdo->prepare(
        <<<'SQL'
INSERT INTO governance_role_permissions(tenant_id, role_id, permission_key, resource_type, action)
VALUES(:tenant_id, :role_id, 'governance.groups.create', 'group', 'create')
SQL
    );
    $insertRolePermission->execute([':tenant_id' => $tenantId, ':role_id' => $roleId]);

    $createdRoleUserResponse = videochat_handle_user_routes(
        '/api/admin/users',
        'POST',
        [
            'method' => 'POST',
            'uri' => '/api/admin/users',
            'body' => json_encode([
                'email' => 'governance-role-user@intelligent-intern.com',
                'display_name' => 'Governance Role User',
                'password' => 'governance-role-password',
                'relationships' => [
                    'roles' => [
                        ['entity_key' => 'roles', 'id' => $rolePublicId],
                    ],
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $apiAuthContext,
        [],
        sys_get_temp_dir(),
        512000,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_admin_user_create_assert(is_array($createdRoleUserResponse), 'create role user response must be an array');
    videochat_admin_user_create_assert((int) ($createdRoleUserResponse['status'] ?? 0) === 201, 'create role user status should be 201');
    $createdRoleUserPayload = videochat_admin_user_create_decode_response($createdRoleUserResponse);
    $createdRoleUser = ($createdRoleUserPayload['result'] ?? [])['user'] ?? null;
    videochat_admin_user_create_assert(is_array($createdRoleUser), 'created role user payload missing');
    $createdRoleUserId = (int) ($createdRoleUser['id'] ?? 0);
    videochat_admin_user_create_assert(
        (string) (((($createdRoleUser['relationships'] ?? [])['roles'] ?? [])[0] ?? [])['id'] ?? '') === $rolePublicId,
        'created role user should expose selected governance role'
    );
    $userRoleAssignmentCount = (int) $pdo->query("SELECT COUNT(*) FROM governance_user_roles WHERE tenant_id = {$tenantId} AND user_id = {$createdRoleUserId} AND role_id = {$roleId}")->fetchColumn();
    videochat_admin_user_create_assert($userRoleAssignmentCount === 1, 'created user governance role should be persisted');
    $userRoleGrantCount = (int) $pdo->query("SELECT COUNT(*) FROM permission_grants WHERE tenant_id = {$tenantId} AND source = 'user_roles' AND subject_type = 'user' AND user_id = {$createdRoleUserId} AND permission_key = 'governance.groups.create'")->fetchColumn();
    videochat_admin_user_create_assert($userRoleGrantCount === 1, 'created user governance role should expand into evaluator grant');
    $userRoleGrant = videochat_tenancy_user_has_resource_permission($pdo, $tenantId, $createdRoleUserId, 'group', '*', 'create');
    videochat_admin_user_create_assert((bool) ($userRoleGrant['ok'] ?? false), 'created user role grant should be evaluated');

    $clearRole = videochat_tenancy_update_governance_role($pdo, $tenantId, 1, $rolePublicId, [
        'name' => 'Admin User Create Contract Role',
        'key' => 'admin.user.create.contract',
        'status' => 'active',
        'relationships' => [
            'permissions' => [],
            'modules' => [],
        ],
    ]);
    videochat_admin_user_create_assert((bool) ($clearRole['ok'] ?? false), 'clearing user role permissions should succeed');
    $clearedUserRoleGrantCount = (int) $pdo->query("SELECT COUNT(*) FROM permission_grants WHERE tenant_id = {$tenantId} AND source = 'user_roles' AND subject_type = 'user' AND user_id = {$createdRoleUserId}")->fetchColumn();
    videochat_admin_user_create_assert($clearedUserRoleGrantCount === 0, 'clearing role permissions should remove user role grants');
    $deleteRole = videochat_tenancy_delete_governance_role($pdo, $tenantId, $rolePublicId);
    videochat_admin_user_create_assert((bool) ($deleteRole['ok'] ?? false), 'deleting user role should succeed');
    $deletedUserRoleAssignmentCount = (int) $pdo->query("SELECT COUNT(*) FROM governance_user_roles WHERE tenant_id = {$tenantId} AND user_id = {$createdRoleUserId}")->fetchColumn();
    videochat_admin_user_create_assert($deletedUserRoleAssignmentCount === 0, 'deleting role should remove user role assignments');

    $duplicateResponse = videochat_handle_user_routes(
        '/api/admin/users',
        'POST',
        [
            'method' => 'POST',
            'uri' => '/api/admin/users',
            'body' => json_encode([
                'email' => 'create-contract-user@intelligent-intern.com',
                'display_name' => 'Create Contract User Duplicate',
                'password' => 'create-contract-password',
                'role' => 'moderator',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $apiAuthContext,
        [],
        sys_get_temp_dir(),
        512000,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_admin_user_create_assert(is_array($duplicateResponse), 'duplicate response must be an array');
    videochat_admin_user_create_assert((int) ($duplicateResponse['status'] ?? 0) === 409, 'duplicate status should be 409');

    $duplicatePayload = videochat_admin_user_create_decode_response($duplicateResponse);
    videochat_admin_user_create_assert(
        (string) (($duplicatePayload['error'] ?? [])['code'] ?? '') === 'admin_user_conflict',
        'duplicate error code mismatch'
    );
    videochat_admin_user_create_assert(
        (string) (((($duplicatePayload['error'] ?? [])['details'] ?? [])['fields'] ?? [])['email'] ?? '') === 'already_exists',
        'duplicate email field mismatch'
    );

    @unlink($databasePath);
    fwrite(STDOUT, "[admin-user-create-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[admin-user-create-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
