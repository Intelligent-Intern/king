<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../domain/tenancy/tenant_administration.php';
require_once __DIR__ . '/../http/module_calls_access.php';

function videochat_stale_org_role_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-access-stale-organization-role-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_stale_org_role_user_role_id(PDO $pdo): int
{
    $query = $pdo->prepare("SELECT id FROM roles WHERE slug = 'user' LIMIT 1");
    $query->execute();
    return (int) $query->fetchColumn();
}

function videochat_stale_org_role_create_user(PDO $pdo, string $email, string $displayName): int
{
    $roleId = videochat_stale_org_role_user_role_id($pdo);
    videochat_stale_org_role_assert($roleId > 0, 'expected user role fixture');

    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, date_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', '24h', 'dmy_dot', 'dark', :updated_at)
SQL
    );
    $insert->execute([
        ':email' => strtolower($email),
        ':display_name' => $displayName,
        ':password_hash' => password_hash('contract-password', PASSWORD_DEFAULT),
        ':role_id' => $roleId,
        ':updated_at' => gmdate('c'),
    ]);

    return (int) $pdo->lastInsertId();
}

function videochat_stale_org_role_set_tenant_role(PDO $pdo, int $tenantId, int $userId, string $role, array $permissions = []): void
{
    $normalizedRole = videochat_tenant_normalize_role($role);
    $permissionsJson = json_encode($permissions, JSON_THROW_ON_ERROR);
    $now = gmdate('c');

    $update = $pdo->prepare(
        <<<'SQL'
UPDATE tenant_memberships
SET membership_role = :membership_role,
    permissions_json = :permissions_json,
    status = 'active',
    updated_at = :updated_at
WHERE tenant_id = :tenant_id
  AND user_id = :user_id
SQL
    );
    $update->execute([
        ':membership_role' => $normalizedRole,
        ':permissions_json' => $permissionsJson,
        ':updated_at' => $now,
        ':tenant_id' => $tenantId,
        ':user_id' => $userId,
    ]);
    if ($update->rowCount() > 0) {
        return;
    }

    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO tenant_memberships(tenant_id, user_id, membership_role, status, permissions_json, default_membership, created_at, updated_at)
VALUES(:tenant_id, :user_id, :membership_role, 'active', :permissions_json, 1, :created_at, :updated_at)
SQL
    );
    $insert->execute([
        ':tenant_id' => $tenantId,
        ':user_id' => $userId,
        ':membership_role' => $normalizedRole,
        ':permissions_json' => $permissionsJson,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
}

function videochat_stale_org_role_set_organization_role(PDO $pdo, int $tenantId, int $organizationId, int $userId, string $role): void
{
    $normalizedRole = strtolower(trim($role)) === 'admin' ? 'admin' : 'member';
    $now = gmdate('c');
    $update = $pdo->prepare(
        <<<'SQL'
UPDATE organization_memberships
SET membership_role = :membership_role,
    status = 'active',
    updated_at = :updated_at
WHERE tenant_id = :tenant_id
  AND organization_id = :organization_id
  AND user_id = :user_id
SQL
    );
    $update->execute([
        ':membership_role' => $normalizedRole,
        ':updated_at' => $now,
        ':tenant_id' => $tenantId,
        ':organization_id' => $organizationId,
        ':user_id' => $userId,
    ]);
    if ($update->rowCount() > 0) {
        return;
    }

    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO organization_memberships(tenant_id, organization_id, user_id, membership_role, status, created_at, updated_at)
VALUES(:tenant_id, :organization_id, :user_id, :membership_role, 'active', :created_at, :updated_at)
SQL
    );
    $insert->execute([
        ':tenant_id' => $tenantId,
        ':organization_id' => $organizationId,
        ':user_id' => $userId,
        ':membership_role' => $normalizedRole,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
}

function videochat_stale_org_role_auth_request(string $sessionId): array
{
    return [
        'method' => 'GET',
        'uri' => '/api/auth/session',
        'headers' => ['Authorization' => 'Bearer ' . $sessionId],
    ];
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-access-stale-organization-role-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-stale-organization-role-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    $ownerUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('user@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $organizationRow = $pdo->query("SELECT id, public_id FROM organizations WHERE tenant_id = {$tenantId} ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    videochat_stale_org_role_assert($tenantId > 0, 'expected default tenant');
    videochat_stale_org_role_assert($ownerUserId > 0, 'expected seeded owner user');
    videochat_stale_org_role_assert(is_array($organizationRow), 'expected default organization');
    $organizationId = (int) ($organizationRow['id'] ?? 0);
    videochat_stale_org_role_assert($organizationId > 0, 'expected default organization id');

    $actorUserId = videochat_stale_org_role_create_user($pdo, 'stale-org-role-admin@example.test', 'Stale Org Role Admin');
    videochat_stale_org_role_assert($actorUserId > 0, 'expected contract actor user');
    videochat_stale_org_role_set_tenant_role($pdo, $tenantId, $actorUserId, 'admin', ['manage_organizations' => true]);
    videochat_stale_org_role_set_organization_role($pdo, $tenantId, $organizationId, $actorUserId, 'admin');

    $sessionId = 'sess_stale_organization_role_revalidation';
    $session = videochat_issue_session_for_user(
        $pdo,
        $actorUserId,
        static fn (): string => $sessionId,
        3600,
        '127.0.0.1',
        'call-access-stale-organization-role-contract',
        time(),
        $tenantId
    );
    videochat_stale_org_role_assert((bool) ($session['ok'] ?? false), 'admin actor should receive a session before role downgrade');

    $authBefore = videochat_authenticate_request($pdo, videochat_stale_org_role_auth_request($sessionId), 'rest');
    videochat_stale_org_role_assert((bool) ($authBefore['ok'] ?? false), 'session should authenticate before role downgrade');
    videochat_stale_org_role_assert((string) (($authBefore['tenant'] ?? [])['role'] ?? '') === 'admin', 'session should expose current tenant admin role before downgrade');
    videochat_stale_org_role_assert(
        (bool) (((($authBefore['tenant'] ?? [])['permissions'] ?? [])['tenant_admin'] ?? false)) === true,
        'tenant admin permission should be true before downgrade'
    );

    $call = videochat_create_call($pdo, $ownerUserId, [
        'title' => 'Stale Organization Role Revalidation',
        'access_mode' => 'invite_only',
        'starts_at' => '2026-10-15T09:00:00Z',
        'ends_at' => '2026-10-15T10:00:00Z',
        'internal_participant_user_ids' => [],
        'external_participants' => [],
    ], $tenantId);
    videochat_stale_org_role_assert((bool) ($call['ok'] ?? false), 'owner call should be created');
    $callId = (string) (($call['call'] ?? [])['id'] ?? '');
    videochat_stale_org_role_assert($callId !== '', 'call id should be present');

    $beforeCallAccess = videochat_get_call_for_user($pdo, $callId, $actorUserId, 'user', $tenantId);
    videochat_stale_org_role_assert((bool) ($beforeCallAccess['ok'] ?? false), 'organization admin should access same-organization call before downgrade');
    videochat_stale_org_role_assert(
        videochat_can_administer_call($pdo, $callId, 'user', $actorUserId, $ownerUserId, $tenantId),
        'organization admin should administer same-organization call before downgrade'
    );

    videochat_stale_org_role_set_tenant_role($pdo, $tenantId, $actorUserId, 'member');
    videochat_stale_org_role_set_organization_role($pdo, $tenantId, $organizationId, $actorUserId, 'member');

    $authAfter = videochat_authenticate_request($pdo, videochat_stale_org_role_auth_request($sessionId), 'rest');
    videochat_stale_org_role_assert((bool) ($authAfter['ok'] ?? false), 'same session should remain valid after role downgrade');
    videochat_stale_org_role_assert((string) (($authAfter['tenant'] ?? [])['role'] ?? '') === 'member', 'same session must re-read downgraded tenant role');
    videochat_stale_org_role_assert(
        (bool) (((($authAfter['tenant'] ?? [])['permissions'] ?? [])['tenant_admin'] ?? true)) === false,
        'same session must not keep stale tenant admin permission'
    );
    videochat_stale_org_role_assert(
        (bool) (videochat_tenancy_require_admin($authAfter)['ok'] ?? true) === false,
        'tenant-admin checks must reject the revalidated downgraded session'
    );

    $pdo->prepare('DELETE FROM sessions WHERE id = :session_id')->execute([':session_id' => $sessionId]);
    $cachedAuthAfter = videochat_authenticate_request($pdo, videochat_stale_org_role_auth_request($sessionId), 'rest');
    videochat_stale_org_role_assert((bool) ($cachedAuthAfter['ok'] ?? false), 'locally cached session fallback should remain valid after role downgrade');
    videochat_stale_org_role_assert((string) (($cachedAuthAfter['tenant'] ?? [])['role'] ?? '') === 'member', 'locally cached session fallback must re-read downgraded tenant role');
    videochat_stale_org_role_assert(
        (bool) (((($cachedAuthAfter['tenant'] ?? [])['permissions'] ?? [])['tenant_admin'] ?? true)) === false,
        'locally cached session fallback must not retain stale tenant admin permission'
    );

    videochat_stale_org_role_assert(
        !videochat_user_is_organization_admin_for_call($pdo, $callId, $actorUserId, $tenantId),
        'downgraded organization member must not retain organization-admin call rights'
    );
    $afterCallAccess = videochat_get_call_for_user($pdo, $callId, $actorUserId, 'user', $tenantId);
    videochat_stale_org_role_assert(!(bool) ($afterCallAccess['ok'] ?? true), 'downgraded organization member must not access invite-only call by stale role');
    videochat_stale_org_role_assert((string) ($afterCallAccess['reason'] ?? '') === 'forbidden', 'downgraded call access should be forbidden');
    videochat_stale_org_role_assert(
        !videochat_can_administer_call($pdo, $callId, 'user', $actorUserId, $ownerUserId, $tenantId),
        'downgraded organization member must not administer call'
    );
    videochat_stale_org_role_assert(
        !videochat_can_administer_call($pdo, $callId, 'admin', $actorUserId, $ownerUserId, $tenantId),
        'forged global admin role must be revalidated against the backend user role'
    );
    $forgedAdminAccess = videochat_get_call_for_user($pdo, $callId, $actorUserId, 'admin', $tenantId);
    videochat_stale_org_role_assert(!(bool) ($forgedAdminAccess['ok'] ?? true), 'forged auth role must not restore call access after downgrade');
    $callRoleContext = videochat_call_role_context_for_room_user($pdo, $callId, $actorUserId);
    videochat_stale_org_role_assert((bool) ($callRoleContext['can_moderate'] ?? true) === false, 'downgraded organization member must not retain moderation context');
    videochat_stale_org_role_assert((string) ($callRoleContext['call_id'] ?? '') === '', 'downgraded nonparticipant must not resolve call context');

    $jsonResponse = static fn (int $status, array $payload): array => [
        'status' => $status,
        'headers' => ['content-type' => 'application/json'],
        'body' => json_encode($payload, JSON_UNESCAPED_SLASHES),
    ];
    $errorResponse = static fn (int $status, string $code, string $message, array $details = []) => $jsonResponse($status, [
        'status' => 'error',
        'error' => ['code' => $code, 'message' => $message, 'details' => $details],
        'time' => gmdate('c'),
    ]);
    $decodeJsonBody = static function (array $request): array {
        $decoded = json_decode((string) ($request['body'] ?? ''), true);
        return [is_array($decoded) ? $decoded : null, is_array($decoded) ? null : 'invalid_json'];
    };
    $openDatabase = static fn (): PDO => videochat_open_sqlite_pdo($databasePath);

    $staleClientRequest = [
        'method' => 'GET',
        'uri' => '/api/call-access/' . $callId . '?organization_role=admin&tenant_admin=1&role=admin',
        'headers' => [
            'Authorization' => 'Bearer ' . $sessionId,
            'X-Organization-Role' => 'admin',
            'X-Tenant-Admin' => '1',
        ],
        'body' => json_encode([
            'organization_role' => 'admin',
            'tenant' => ['role' => 'admin', 'permissions' => ['tenant_admin' => true]],
        ], JSON_UNESCAPED_SLASHES),
    ];
    $clientCacheResponse = videochat_handle_call_access_routes(
        '/api/call-access/' . $callId,
        'GET',
        $staleClientRequest,
        $cachedAuthAfter,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_stale_org_role_assert(is_array($clientCacheResponse), 'stale client role request should produce a response');
    videochat_stale_org_role_assert((int) ($clientCacheResponse['status'] ?? 0) === 404, 'stale client role cache must not resolve hidden invite-only call');

    $staleDecodedSessionContext = $authBefore;
    $staleDecodedSessionContext['tenant']['role'] = 'admin';
    $staleDecodedSessionContext['tenant']['permissions']['tenant_admin'] = true;
    $staleDecodedSessionContext['user']['role'] = 'admin';
    $staleSessionContextResponse = videochat_handle_call_access_routes(
        '/api/call-access/' . $callId,
        'GET',
        $staleClientRequest,
        $staleDecodedSessionContext,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_stale_org_role_assert(is_array($staleSessionContextResponse), 'stale decoded session context request should produce a response');
    videochat_stale_org_role_assert((int) ($staleSessionContextResponse['status'] ?? 0) === 404, 'call access must revalidate stale decoded role context against backend state');

    @unlink($databasePath);
    fwrite(STDOUT, "[call-access-stale-organization-role-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[call-access-stale-organization-role-contract] ERROR: ' . $error->getMessage() . "\n");
    fwrite(STDERR, $error->getTraceAsString() . "\n");
    exit(1);
} finally {
    if (isset($databasePath) && is_string($databasePath) && is_file($databasePath)) {
        @unlink($databasePath);
    }
}
