<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../http/module_calls_access.php';

function videochat_call_access_cross_org_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-access-cross-org-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_call_access_cross_org_role_id(PDO $pdo, string $role): int
{
    $query = $pdo->prepare('SELECT id FROM roles WHERE slug = :slug LIMIT 1');
    $query->execute([':slug' => $role]);
    return (int) $query->fetchColumn();
}

function videochat_call_access_cross_org_create_user(PDO $pdo, string $email, string $name, string $role = 'user'): int
{
    $roleId = videochat_call_access_cross_org_role_id($pdo, $role);
    videochat_call_access_cross_org_assert($roleId > 0, "expected {$role} role");

    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, date_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', '24h', 'dmy_dot', 'dark', :updated_at)
SQL
    );
    $insert->execute([
        ':email' => strtolower($email),
        ':display_name' => $name,
        ':password_hash' => password_hash('contract-password', PASSWORD_DEFAULT),
        ':role_id' => $roleId,
        ':updated_at' => gmdate('c'),
    ]);

    return (int) $pdo->lastInsertId();
}

function videochat_call_access_cross_org_create_tenant(PDO $pdo, string $slug, string $label): int
{
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO tenants(public_id, slug, label, status, created_at, updated_at)
VALUES(:public_id, :slug, :label, 'active', :created_at, :updated_at)
SQL
    );
    $insert->execute([
        ':public_id' => videochat_generate_call_access_uuid(),
        ':slug' => $slug,
        ':label' => $label,
        ':created_at' => gmdate('c'),
        ':updated_at' => gmdate('c'),
    ]);

    return (int) $pdo->lastInsertId();
}

function videochat_call_access_cross_org_create_organization(PDO $pdo, int $tenantId, string $publicId, string $name): int
{
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO organizations(tenant_id, parent_organization_id, public_id, name, status, created_at, updated_at)
VALUES(:tenant_id, NULL, :public_id, :name, 'active', :created_at, :updated_at)
SQL
    );
    $insert->execute([
        ':tenant_id' => $tenantId,
        ':public_id' => $publicId,
        ':name' => $name,
        ':created_at' => gmdate('c'),
        ':updated_at' => gmdate('c'),
    ]);

    $organizationId = (int) $pdo->lastInsertId();
    videochat_call_access_cross_org_assert($organizationId > 0, "{$name} organization should be created");

    return $organizationId;
}

function videochat_call_access_cross_org_attach_user(PDO $pdo, int $tenantId, int $userId, string $role, bool $default): void
{
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO tenant_memberships(tenant_id, user_id, membership_role, permissions_json, status, default_membership, created_at, updated_at)
VALUES(:tenant_id, :user_id, :membership_role, '{}', 'active', :default_membership, :created_at, :updated_at)
SQL
    );
    $insert->execute([
        ':tenant_id' => $tenantId,
        ':user_id' => $userId,
        ':membership_role' => $role,
        ':default_membership' => $default ? 1 : 0,
        ':created_at' => gmdate('c'),
        ':updated_at' => gmdate('c'),
    ]);
}

function videochat_call_access_cross_org_attach_organization(PDO $pdo, int $tenantId, int $organizationId, int $userId, string $role): void
{
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
        ':membership_role' => strtolower(trim($role)) === 'admin' ? 'admin' : 'member',
        ':created_at' => gmdate('c'),
        ':updated_at' => gmdate('c'),
    ]);
}

function videochat_call_access_cross_org_insert_auth_session(PDO $pdo, string $sessionId, int $tenantId, int $userId): void
{
    $pdo->prepare(
        <<<'SQL'
INSERT INTO sessions(id, user_id, active_tenant_id, issued_at, expires_at, revoked_at, client_ip, user_agent)
VALUES(:id, :user_id, :active_tenant_id, :issued_at, :expires_at, NULL, '127.0.0.1', 'call-access-cross-org-contract')
SQL
    )->execute([
        ':id' => $sessionId,
        ':user_id' => $userId,
        ':active_tenant_id' => $tenantId,
        ':issued_at' => gmdate('c'),
        ':expires_at' => gmdate('c', time() + 3600),
    ]);
}

/**
 * @param array<int, string> $needles
 */
function videochat_call_access_cross_org_assert_no_body_needles(array $response, array $needles, string $label): void
{
    $body = strtolower((string) ($response['body'] ?? ''));
    foreach ($needles as $needle) {
        $needle = strtolower(trim($needle));
        if ($needle === '') {
            continue;
        }
        videochat_call_access_cross_org_assert(!str_contains($body, $needle), "{$label} leaked {$needle}");
    }
}

function videochat_call_access_cross_org_create_call(PDO $pdo, int $ownerUserId, int $tenantId, string $title, array $participants = [], string $accessMode = 'invite_only'): string
{
    $create = videochat_create_call($pdo, $ownerUserId, [
        'title' => $title,
        'access_mode' => $accessMode,
        'starts_at' => '2026-09-21T09:00:00Z',
        'ends_at' => '2026-09-21T10:00:00Z',
        'internal_participant_user_ids' => $participants,
        'external_participants' => [],
    ], $tenantId);
    videochat_call_access_cross_org_assert((bool) ($create['ok'] ?? false), "{$title} should be created");

    $callId = (string) (($create['call'] ?? [])['id'] ?? '');
    videochat_call_access_cross_org_assert($callId !== '', "{$title} should expose a call id");

    return $callId;
}

function videochat_call_access_cross_org_insert_link(PDO $pdo, int $tenantId, string $callId, ?int $participantUserId): string
{
    $accessId = videochat_generate_call_access_uuid();
    $tenantColumn = videochat_tenant_table_has_column($pdo, 'call_access_links', 'tenant_id') ? ', tenant_id' : '';
    $tenantValue = $tenantColumn !== '' ? ', :tenant_id' : '';
    $insert = $pdo->prepare(
        <<<SQL
INSERT INTO call_access_links(id, call_id, participant_user_id, participant_email, invite_code_id, created_by_user_id, created_at, expires_at{$tenantColumn})
VALUES(:id, :call_id, :participant_user_id, NULL, NULL, NULL, :created_at, :expires_at{$tenantValue})
SQL
    );
    $params = [
        ':id' => $accessId,
        ':call_id' => $callId,
        ':participant_user_id' => $participantUserId,
        ':created_at' => gmdate('c'),
        ':expires_at' => '2026-09-21T10:00:00Z',
    ];
    if ($tenantColumn !== '') {
        $params[':tenant_id'] = $tenantId;
    }
    $insert->execute($params);

    return $accessId;
}

function videochat_call_access_cross_org_insert_session(PDO $pdo, int $tenantId, string $sessionId, string $accessId, string $callId, int $userId): void
{
    $issuedAt = gmdate('c');
    $expiresAt = gmdate('c', time() + 3600);
    $pdo->prepare(
        <<<'SQL'
INSERT INTO sessions(id, user_id, active_tenant_id, issued_at, expires_at, revoked_at, client_ip, user_agent)
VALUES(:id, :user_id, :active_tenant_id, :issued_at, :expires_at, NULL, '127.0.0.1', 'call-access-cross-org-contract')
SQL
    )->execute([
        ':id' => $sessionId,
        ':user_id' => $userId,
        ':active_tenant_id' => $tenantId,
        ':issued_at' => $issuedAt,
        ':expires_at' => $expiresAt,
    ]);

    $tenantColumn = videochat_tenant_table_has_column($pdo, 'call_access_sessions', 'tenant_id') ? ', tenant_id' : '';
    $tenantValue = $tenantColumn !== '' ? ', :tenant_id' : '';
    $insert = $pdo->prepare(
        <<<SQL
INSERT INTO call_access_sessions(session_id, access_id, call_id, room_id, user_id, link_kind, issued_at, expires_at{$tenantColumn})
VALUES(:session_id, :access_id, :call_id, :room_id, :user_id, 'personal', :issued_at, :expires_at{$tenantValue})
SQL
    );
    $params = [
        ':session_id' => $sessionId,
        ':access_id' => $accessId,
        ':call_id' => $callId,
        ':room_id' => $callId,
        ':user_id' => $userId,
        ':issued_at' => $issuedAt,
        ':expires_at' => $expiresAt,
    ];
    if ($tenantColumn !== '') {
        $params[':tenant_id'] = $tenantId;
    }
    $insert->execute($params);
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-access-cross-org-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-call-access-cross-org-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $tenantAId = videochat_call_access_cross_org_create_tenant($pdo, 'contract-org-a', 'Contract Organization A');
    $tenantBId = videochat_call_access_cross_org_create_tenant($pdo, 'contract-org-b', 'Contract Organization B');
    $organizationAId = videochat_call_access_cross_org_create_organization($pdo, $tenantAId, 'contract-organization-a', 'Contract Organization A Unit');
    $organizationBId = videochat_call_access_cross_org_create_organization($pdo, $tenantBId, 'contract-organization-b', 'Contract Organization B Unit');
    $orgAAdminId = videochat_call_access_cross_org_create_user($pdo, 'cross-org-a-admin@example.test', 'Org A Admin');
    $orgAMultiTenantAdminId = videochat_call_access_cross_org_create_user($pdo, 'cross-org-a-admin-beta-member@example.test', 'Org A Admin Beta Member');
    $orgAUserId = videochat_call_access_cross_org_create_user($pdo, 'cross-org-a-user@example.test', 'Org A User');
    $orgBOwnerId = videochat_call_access_cross_org_create_user($pdo, 'cross-org-b-owner@example.test', 'Org B Owner');
    $legacyAdminId = videochat_call_access_cross_org_create_user($pdo, 'cross-org-legacy-admin@example.test', 'Legacy Admin', 'admin');

    videochat_call_access_cross_org_attach_user($pdo, $tenantAId, $orgAAdminId, 'admin', true);
    videochat_call_access_cross_org_attach_user($pdo, $tenantAId, $orgAMultiTenantAdminId, 'admin', true);
    videochat_call_access_cross_org_attach_user($pdo, $tenantBId, $orgAMultiTenantAdminId, 'member', false);
    videochat_call_access_cross_org_attach_user($pdo, $tenantAId, $orgAUserId, 'member', true);
    videochat_call_access_cross_org_attach_user($pdo, $tenantAId, $legacyAdminId, 'admin', true);
    videochat_call_access_cross_org_attach_user($pdo, $tenantBId, $orgBOwnerId, 'owner', true);
    videochat_call_access_cross_org_attach_organization($pdo, $tenantAId, $organizationAId, $orgAAdminId, 'admin');
    videochat_call_access_cross_org_attach_organization($pdo, $tenantAId, $organizationAId, $orgAMultiTenantAdminId, 'admin');
    videochat_call_access_cross_org_attach_organization($pdo, $tenantBId, $organizationBId, $orgAMultiTenantAdminId, 'member');
    videochat_call_access_cross_org_attach_organization($pdo, $tenantAId, $organizationAId, $orgAUserId, 'member');
    videochat_call_access_cross_org_attach_organization($pdo, $tenantBId, $organizationBId, $orgBOwnerId, 'member');

    $tenantAContext = videochat_tenant_context_for_user($pdo, $orgAAdminId, $tenantAId);
    videochat_call_access_cross_org_assert(is_array($tenantAContext), 'organization A admin should have tenant A context');
    videochat_call_access_cross_org_assert((bool) (($tenantAContext['permissions'] ?? [])['tenant_admin'] ?? false), 'organization A admin should be admin in organization A');
    videochat_call_access_cross_org_assert(videochat_tenant_context_for_user($pdo, $orgAAdminId, $tenantBId) === null, 'organization A admin must not have organization B context');

    $orgACallId = videochat_call_access_cross_org_create_call($pdo, $orgAAdminId, $tenantAId, 'Organization A Own Call', [$orgAUserId]);
    $orgBInviteOnlyCallId = videochat_call_access_cross_org_create_call($pdo, $orgBOwnerId, $tenantBId, 'Organization B Invite Only');
    $orgBOpenCallId = videochat_call_access_cross_org_create_call($pdo, $orgBOwnerId, $tenantBId, 'Organization B Open Link', [], 'free_for_all');
    $orgAdminManagedCallId = videochat_call_access_cross_org_create_call($pdo, $orgAUserId, $tenantAId, 'Organization A Admin Managed Call');

    $orgAdminAccess = videochat_get_call_for_user($pdo, $orgAdminManagedCallId, $orgAAdminId, 'user', $tenantAId);
    videochat_call_access_cross_org_assert((bool) ($orgAdminAccess['ok'] ?? false), 'organization A admin should access same-organization call');
    videochat_call_access_cross_org_assert(
        videochat_can_administer_call($pdo, $orgAdminManagedCallId, 'user', $orgAAdminId, $orgAUserId, $tenantAId),
        'organization A admin should administer same-organization call'
    );
    videochat_call_access_cross_org_assert(
        !videochat_can_administer_call($pdo, $orgBInviteOnlyCallId, 'user', $orgAAdminId, $orgBOwnerId, $tenantBId),
        'organization A admin rights must not cross into organization B calls'
    );

    $pdo->prepare(
        <<<'SQL'
UPDATE organization_memberships
SET status = 'disabled',
    updated_at = :updated_at
WHERE tenant_id = :tenant_id
  AND organization_id = :organization_id
  AND user_id = :user_id
SQL
    )->execute([
        ':updated_at' => gmdate('c'),
        ':tenant_id' => $tenantAId,
        ':organization_id' => $organizationAId,
        ':user_id' => $orgAAdminId,
    ]);
    videochat_call_access_cross_org_assert(
        !videochat_user_is_organization_admin_for_call($pdo, $orgAdminManagedCallId, $orgAAdminId, $tenantAId),
        'stale organization admin membership must be re-read before call administration'
    );
    $staleOrgAccess = videochat_get_call_for_user($pdo, $orgAdminManagedCallId, $orgAAdminId, 'user', $tenantAId);
    videochat_call_access_cross_org_assert(!(bool) ($staleOrgAccess['ok'] ?? true), 'stale organization admin must not keep invite-only call access');
    videochat_call_access_cross_org_assert((string) ($staleOrgAccess['reason'] ?? '') === 'forbidden', 'stale organization admin denial reason mismatch');

    $ownOrgAccess = videochat_get_call_for_user($pdo, $orgACallId, $orgAUserId, 'user', $tenantAId);
    videochat_call_access_cross_org_assert((bool) ($ownOrgAccess['ok'] ?? false), 'organization A participant should access own organization call');
    videochat_call_access_cross_org_assert((bool) ((($ownOrgAccess['call'] ?? [])['my_participation'] ?? false)), 'own organization call should preserve participant state');

    $guestListLeak = videochat_get_call_for_user($pdo, $orgBInviteOnlyCallId, $orgAUserId, 'user', $tenantBId);
    videochat_call_access_cross_org_assert(!(bool) ($guestListLeak['ok'] ?? false), 'organization A participant list entry must not leak into organization B invite-only call');
    videochat_call_access_cross_org_assert((string) ($guestListLeak['reason'] ?? '') === 'forbidden', 'guest-list leakage should fail as forbidden inside organization B context');

    $wrongActiveOrg = videochat_get_call_for_user($pdo, $orgBInviteOnlyCallId, $orgAAdminId, 'user', $tenantAId);
    videochat_call_access_cross_org_assert(!(bool) ($wrongActiveOrg['ok'] ?? false), 'active organization A context must not fetch organization B call');
    videochat_call_access_cross_org_assert((string) ($wrongActiveOrg['reason'] ?? '') === 'not_found', 'organization B call must be hidden from organization A context');

    $normalSessionId = 'sess_cross_org_active_a';
    $pdo->prepare(
        <<<'SQL'
INSERT INTO sessions(id, user_id, active_tenant_id, issued_at, expires_at, revoked_at, client_ip, user_agent)
VALUES(:id, :user_id, :active_tenant_id, :issued_at, :expires_at, NULL, '127.0.0.1', 'call-access-cross-org-contract')
SQL
    )->execute([
        ':id' => $normalSessionId,
        ':user_id' => $orgAAdminId,
        ':active_tenant_id' => $tenantAId,
        ':issued_at' => gmdate('c'),
        ':expires_at' => gmdate('c', time() + 3600),
    ]);
    $activeAAuth = videochat_authenticate_request($pdo, [
        'method' => 'GET',
        'uri' => '/api/calls/' . $orgACallId,
        'headers' => ['Authorization' => 'Bearer ' . $normalSessionId],
    ], 'http');
    videochat_call_access_cross_org_assert((bool) ($activeAAuth['ok'] ?? false), 'organization A admin session should authenticate in organization A');
    videochat_call_access_cross_org_assert((int) (($activeAAuth['tenant'] ?? [])['id'] ?? 0) === $tenantAId, 'organization A admin session should keep organization A active tenant');

    $pdo->prepare('UPDATE sessions SET active_tenant_id = :tenant_id WHERE id = :id')->execute([
        ':tenant_id' => $tenantBId,
        ':id' => $normalSessionId,
    ]);
    $switchedAuth = videochat_authenticate_request($pdo, [
        'method' => 'GET',
        'uri' => '/api/calls/' . $orgBInviteOnlyCallId,
        'headers' => ['Authorization' => 'Bearer ' . $normalSessionId],
    ], 'http');
    videochat_call_access_cross_org_assert(!(bool) ($switchedAuth['ok'] ?? false), 'active organization switch must not mint organization B membership');
    videochat_call_access_cross_org_assert((string) ($switchedAuth['reason'] ?? '') === 'tenant_membership_inactive', 'cross-organization active switch should fail at tenant membership');

    $multiTenantSessionId = 'sess_cross_org_multi_active_switch';
    videochat_call_access_cross_org_insert_auth_session($pdo, $multiTenantSessionId, $tenantAId, $orgAMultiTenantAdminId);
    $multiTenantActiveAAuth = videochat_authenticate_request($pdo, [
        'method' => 'GET',
        'uri' => '/api/calls/' . $orgACallId,
        'headers' => ['Authorization' => 'Bearer ' . $multiTenantSessionId],
    ], 'http');
    videochat_call_access_cross_org_assert((bool) ($multiTenantActiveAAuth['ok'] ?? false), 'multi-tenant organization A admin should authenticate in organization A');
    videochat_call_access_cross_org_assert((int) (($multiTenantActiveAAuth['tenant'] ?? [])['id'] ?? 0) === $tenantAId, 'multi-tenant organization A admin should keep active organization A');
    videochat_call_access_cross_org_assert((bool) (((($multiTenantActiveAAuth['tenant'] ?? [])['permissions'] ?? [])['tenant_admin'] ?? false)) === true, 'multi-tenant organization A admin should keep organization A admin permissions');

    $multiTenantOwnOrg = videochat_get_call_for_user($pdo, $orgAdminManagedCallId, $orgAMultiTenantAdminId, 'user', $tenantAId);
    videochat_call_access_cross_org_assert((bool) ($multiTenantOwnOrg['ok'] ?? false), 'multi-tenant organization A admin should access own organization call');

    $pdo->prepare('UPDATE sessions SET active_tenant_id = :tenant_id WHERE id = :id')->execute([
        ':tenant_id' => $tenantBId,
        ':id' => $multiTenantSessionId,
    ]);
    $multiTenantSwitchedAuth = videochat_authenticate_request($pdo, [
        'method' => 'GET',
        'uri' => '/api/calls/' . $orgBInviteOnlyCallId,
        'headers' => ['Authorization' => 'Bearer ' . $multiTenantSessionId],
    ], 'http');
    videochat_call_access_cross_org_assert((bool) ($multiTenantSwitchedAuth['ok'] ?? false), 'multi-tenant organization A admin should authenticate as organization B member after active switch');
    videochat_call_access_cross_org_assert((int) (($multiTenantSwitchedAuth['tenant'] ?? [])['id'] ?? 0) === $tenantBId, 'multi-tenant active switch should expose organization B tenant context');
    videochat_call_access_cross_org_assert((string) (($multiTenantSwitchedAuth['tenant'] ?? [])['role'] ?? '') === 'member', 'multi-tenant active switch should keep organization B member role');
    videochat_call_access_cross_org_assert((bool) (((($multiTenantSwitchedAuth['tenant'] ?? [])['permissions'] ?? [])['tenant_admin'] ?? true)) === false, 'multi-tenant active switch must not grant organization B tenant-admin permissions');
    videochat_call_access_cross_org_assert((bool) (((($multiTenantSwitchedAuth['tenant'] ?? [])['permissions'] ?? [])['platform_admin'] ?? true)) === false, 'multi-tenant active switch must not grant platform-admin permissions');

    $multiTenantForeignFetch = videochat_get_call_for_user($pdo, $orgBInviteOnlyCallId, $orgAMultiTenantAdminId, 'user', $tenantBId);
    videochat_call_access_cross_org_assert(!(bool) ($multiTenantForeignFetch['ok'] ?? true), 'multi-tenant active switch must not grant organization B call permission');
    videochat_call_access_cross_org_assert((string) ($multiTenantForeignFetch['reason'] ?? '') === 'forbidden', 'multi-tenant active switch denial reason mismatch');

    $multiTenantForeignDirectJoin = videochat_user_can_direct_join_call($pdo, $orgBInviteOnlyCallId, $orgAMultiTenantAdminId, 'user', $tenantBId);
    videochat_call_access_cross_org_assert(!(bool) ($multiTenantForeignDirectJoin['ok'] ?? true), 'multi-tenant active switch must not direct-join organization B call');
    videochat_call_access_cross_org_assert((string) ($multiTenantForeignDirectJoin['reason'] ?? '') === 'not_on_guest_list', 'multi-tenant active switch direct-join denial reason mismatch');

    $multiTenantForeignDecision = videochat_decide_call_access_for_user($pdo, $orgBInviteOnlyCallId, $orgAMultiTenantAdminId, 'user', $tenantBId);
    videochat_call_access_cross_org_assert(!(bool) ($multiTenantForeignDecision['allowed'] ?? true), 'multi-tenant active switch must not alter server-side call-access decision');
    videochat_call_access_cross_org_assert((string) ($multiTenantForeignDecision['source'] ?? '') === 'none', 'multi-tenant active switch denial must not claim an access source');

    $stalePersonalAccessId = videochat_call_access_cross_org_insert_link($pdo, $tenantBId, $orgBInviteOnlyCallId, $orgAAdminId);
    $staleResolution = videochat_resolve_call_access_public($pdo, $stalePersonalAccessId);
    videochat_call_access_cross_org_assert((bool) ($staleResolution['ok'] ?? false), 'stale personalized organization B link should resolve public metadata');
    $staleSession = videochat_issue_session_for_call_access(
        $pdo,
        $stalePersonalAccessId,
        static fn (): string => 'sess_cross_org_stale_personal',
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-access-cross-org-contract']
    );
    videochat_call_access_cross_org_assert(!(bool) ($staleSession['ok'] ?? false), 'stale personalized organization B link alone must not grant organization A admin call access');
    videochat_call_access_cross_org_assert((string) ($staleSession['reason'] ?? '') === 'forbidden', 'stale personalized link denial should come from call permission');

    videochat_call_access_cross_org_insert_auth_session($pdo, 'sess_cross_org_a_user_browser', $tenantAId, $orgAUserId);
    $foreignTargetAccessId = videochat_call_access_cross_org_insert_link($pdo, $tenantBId, $orgBInviteOnlyCallId, $orgBOwnerId);
    $jsonResponse = static fn (int $status, array $payload): array => [
        'status' => $status,
        'headers' => ['content-type' => 'application/json; charset=utf-8'],
        'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];
    $errorResponse = static fn (int $status, string $code, string $message, array $details = []) => $jsonResponse($status, [
        'status' => 'error',
        'error' => [
            'code' => $code,
            'message' => $message,
            'details' => $details,
        ],
        'time' => gmdate('c'),
    ]);
    $decodeJsonBody = static function (array $request): array {
        $decoded = json_decode((string) ($request['body'] ?? ''), true);
        return [is_array($decoded) ? $decoded : null, is_array($decoded) ? null : 'invalid_json'];
    };
    $openDatabase = static fn (): PDO => videochat_open_sqlite_pdo($databasePath);
    $foreignVerifiedResponse = videochat_handle_call_access_routes(
        '/api/call-access/' . $foreignTargetAccessId . '/session',
        'POST',
        [
            'method' => 'POST',
            'uri' => '/api/call-access/' . $foreignTargetAccessId . '/session',
            'headers' => [
                'Authorization' => 'Bearer sess_cross_org_a_user_browser',
                'Content-Type' => 'application/json',
                'User-Agent' => 'call-access-cross-org-contract',
            ],
            'remote_address' => '127.0.0.1',
            'body' => json_encode([
                'verified_user_id' => $orgAUserId,
                'verified_session_id' => 'sess_cross_org_a_user_browser',
            ], JSON_UNESCAPED_SLASHES),
        ],
        [],
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase,
        static fn (): string => 'sess_cross_org_foreign_verified_should_not_issue'
    );
    videochat_call_access_cross_org_assert(is_array($foreignVerifiedResponse), 'foreign verified context should produce a route response');
    videochat_call_access_cross_org_assert((int) ($foreignVerifiedResponse['status'] ?? 0) === 409, 'foreign verified context should conflict');
    videochat_call_access_cross_org_assert_no_body_needles($foreignVerifiedResponse, [
        $foreignTargetAccessId,
        $orgBInviteOnlyCallId,
        'Organization B Invite Only',
        'cross-org-b-owner@example.test',
        'Org B Owner',
    ], 'foreign verified context response');
    videochat_call_access_cross_org_assert(
        videochat_call_access_session_id_available($pdo, 'sess_cross_org_foreign_verified_should_not_issue'),
        'foreign verified context denial must not persist a call access session'
    );

    $openLink = videochat_create_call_access_link_for_user($pdo, $orgBOpenCallId, $orgBOwnerId, 'user', [
        'link_kind' => 'open',
    ], $tenantBId);
    videochat_call_access_cross_org_assert((bool) ($openLink['ok'] ?? false), 'organization B owner should create open link');
    $openAccessId = (string) (($openLink['access_link'] ?? [])['id'] ?? '');
    videochat_call_access_cross_org_assert($openAccessId !== '', 'organization B open link id should be present');

    $openSession = videochat_issue_session_for_call_access(
        $pdo,
        $openAccessId,
        static fn (): string => 'sess_cross_org_open_guest',
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-access-cross-org-contract'],
        ['guest_name' => 'External Guest']
    );
    videochat_call_access_cross_org_assert((bool) ($openSession['ok'] ?? false), 'organization B open link should issue a guest session');
    $guestUserId = (int) (($openSession['user'] ?? [])['id'] ?? 0);
    videochat_call_access_cross_org_assert($guestUserId > 0 && $guestUserId !== $orgAUserId && $guestUserId !== $orgAAdminId, 'open link should create an isolated guest identity instead of reusing organization A users');
    videochat_call_access_cross_org_assert(videochat_tenant_user_is_member($pdo, $guestUserId, $tenantBId), 'open-link guest should be scoped to organization B tenant');
    videochat_call_access_cross_org_assert(!videochat_tenant_user_is_member($pdo, $guestUserId, $tenantAId), 'open-link guest must not receive organization A membership');

    $orgAAfterOpen = videochat_get_call_for_user($pdo, $orgBInviteOnlyCallId, $orgAAdminId, 'user', $tenantBId);
    videochat_call_access_cross_org_assert(!(bool) ($orgAAfterOpen['ok'] ?? false), 'organization B open link must not grant organization A admin access to another B invite-only call');

    $openAuth = videochat_authenticate_request($pdo, [
        'method' => 'GET',
        'uri' => '/ws?session=sess_cross_org_open_guest&room=' . $orgBOpenCallId . '&call_id=' . $orgBOpenCallId,
        'headers' => ['Authorization' => 'Bearer sess_cross_org_open_guest'],
    ], 'websocket');
    videochat_call_access_cross_org_assert((bool) ($openAuth['ok'] ?? false), 'open-link guest session should authenticate');
    videochat_call_access_cross_org_assert((int) (($openAuth['tenant'] ?? [])['id'] ?? 0) === $tenantBId, 'open-link guest session should use organization B tenant');
    videochat_call_access_cross_org_assert((bool) (((($openAuth['tenant'] ?? [])['permissions'] ?? [])['tenant_admin'] ?? false)) === false, 'open-link guest must not receive organization B admin rights');

    $legacyAccessId = videochat_call_access_cross_org_insert_link($pdo, $tenantBId, $orgBInviteOnlyCallId, $legacyAdminId);
    videochat_call_access_cross_org_insert_session($pdo, $tenantBId, 'sess_cross_org_legacy_admin_fallback', $legacyAccessId, $orgBInviteOnlyCallId, $legacyAdminId);
    $legacyFallback = videochat_tenant_context_for_call_access_session($pdo, $legacyAdminId, 'sess_cross_org_legacy_admin_fallback');
    videochat_call_access_cross_org_assert(is_array($legacyFallback), 'legacy admin call-access fallback should resolve');
    videochat_call_access_cross_org_assert((int) ($legacyFallback['tenant_id'] ?? 0) === $tenantBId, 'legacy admin fallback should be bound to organization B call tenant');
    videochat_call_access_cross_org_assert((string) ($legacyFallback['role'] ?? '') === 'member', 'legacy admin fallback should be least-privilege member');
    videochat_call_access_cross_org_assert((bool) ((($legacyFallback['permissions'] ?? [])['tenant_admin'] ?? false)) === false, 'legacy admin fallback must not become organization B admin');
    videochat_call_access_cross_org_assert((bool) ((($legacyFallback['permissions'] ?? [])['platform_admin'] ?? false)) === false, 'legacy admin fallback must not preserve platform admin through call access');

    @unlink($databasePath);
    fwrite(STDOUT, "[call-access-cross-org-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[call-access-cross-org-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
