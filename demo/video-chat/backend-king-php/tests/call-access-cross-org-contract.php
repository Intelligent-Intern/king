<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';

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

function videochat_call_access_cross_org_guest_account_count(PDO $pdo): int
{
    $query = $pdo->query("SELECT COUNT(*) FROM users WHERE lower(email) LIKE 'guest+%@videochat.local'");
    return $query === false ? 0 : (int) $query->fetchColumn();
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
        ':membership_role' => $role,
        ':created_at' => gmdate('c'),
        ':updated_at' => gmdate('c'),
    ]);
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

function videochat_call_access_cross_org_assert_member_context(
    PDO $pdo,
    string $label,
    string $sessionId,
    int $expectedUserId,
    int $expectedTenantId,
    string $expectedCallId
): void {
    $auth = videochat_authenticate_request($pdo, [
        'method' => 'GET',
        'uri' => '/ws?session=' . rawurlencode($sessionId)
            . '&room=' . rawurlencode($expectedCallId)
            . '&call_id=' . rawurlencode($expectedCallId),
        'headers' => ['Authorization' => 'Bearer ' . $sessionId],
    ], 'websocket');
    videochat_call_access_cross_org_assert((bool) ($auth['ok'] ?? false), "{$label} should authenticate");
    videochat_call_access_cross_org_assert((int) (($auth['user'] ?? [])['id'] ?? 0) === $expectedUserId, "{$label} authenticated user mismatch");
    videochat_call_access_cross_org_assert((int) (($auth['tenant'] ?? [])['id'] ?? 0) === $expectedTenantId, "{$label} tenant context mismatch");
    videochat_call_access_cross_org_assert((string) (($auth['tenant'] ?? [])['role'] ?? '') === 'member', "{$label} should resolve least-privilege member context");
    videochat_call_access_cross_org_assert((bool) (((($auth['tenant'] ?? [])['permissions'] ?? [])['tenant_admin'] ?? false)) === false, "{$label} must not receive tenant-admin rights");
    videochat_call_access_cross_org_assert((bool) (((($auth['tenant'] ?? [])['permissions'] ?? [])['platform_admin'] ?? false)) === false, "{$label} must not receive platform-admin rights");

    $roleContext = videochat_call_role_context_for_room_user($pdo, $expectedCallId, $expectedUserId);
    videochat_call_access_cross_org_assert((string) ($roleContext['effective_call_role'] ?? '') === 'participant', "{$label} should remain a call participant");
    videochat_call_access_cross_org_assert((bool) ($roleContext['can_moderate'] ?? false) === false, "{$label} must not receive moderation rights");
    videochat_call_access_cross_org_assert((bool) ($roleContext['can_manage_owner'] ?? false) === false, "{$label} must not receive owner-management rights");
}

function videochat_call_access_cross_org_assert_no_call_rights(
    PDO $pdo,
    string $label,
    string $callId,
    int $userId,
    int $tenantId
): void {
    $fetch = videochat_get_call_for_user($pdo, $callId, $userId, 'user', $tenantId);
    videochat_call_access_cross_org_assert(!(bool) ($fetch['ok'] ?? true), "{$label} must not fetch the foreign invite-only call");
    videochat_call_access_cross_org_assert((string) ($fetch['reason'] ?? '') === 'forbidden', "{$label} fetch denial reason mismatch");

    $decision = videochat_decide_call_access_for_user($pdo, $callId, $userId, 'user', $tenantId);
    videochat_call_access_cross_org_assert(!(bool) ($decision['allowed'] ?? true), "{$label} must not receive a foreign call decision");
    videochat_call_access_cross_org_assert((string) ($decision['source'] ?? '') === 'none', "{$label} denial must not claim an access source");
    videochat_call_access_cross_org_assert((bool) ($decision['can_moderate'] ?? true) === false, "{$label} must not receive moderation rights");

    $directJoin = videochat_user_can_direct_join_call($pdo, $callId, $userId, 'user', $tenantId);
    videochat_call_access_cross_org_assert(!(bool) ($directJoin['ok'] ?? true), "{$label} must not direct-join the foreign invite-only call");
    videochat_call_access_cross_org_assert((string) ($directJoin['reason'] ?? '') === 'not_on_guest_list', "{$label} direct-join denial reason mismatch");

    $call = videochat_fetch_call_for_update($pdo, $callId, $tenantId);
    videochat_call_access_cross_org_assert(is_array($call), "{$label} foreign call fixture should exist");
    videochat_call_access_cross_org_assert(
        videochat_can_administer_call($pdo, $callId, 'user', $userId, (int) ($call['owner_user_id'] ?? 0), $tenantId) === false,
        "{$label} must not administer the foreign call"
    );
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
    $orgAOwnerId = videochat_call_access_cross_org_create_user($pdo, 'cross-org-a-owner@example.test', 'Org A Owner');
    $orgAUserId = videochat_call_access_cross_org_create_user($pdo, 'cross-org-a-user@example.test', 'Org A User');
    $orgAOnlyUserId = videochat_call_access_cross_org_create_user($pdo, 'cross-org-a-only-user@example.test', 'Org A Only User');
    $orgBOwnerId = videochat_call_access_cross_org_create_user($pdo, 'cross-org-b-owner@example.test', 'Org B Owner');
    $legacyAdminId = videochat_call_access_cross_org_create_user($pdo, 'cross-org-legacy-admin@example.test', 'Legacy Admin', 'admin');

    videochat_call_access_cross_org_attach_user($pdo, $tenantAId, $orgAAdminId, 'admin', true);
    videochat_call_access_cross_org_attach_user($pdo, $tenantAId, $orgAMultiTenantAdminId, 'member', true);
    videochat_call_access_cross_org_attach_user($pdo, $tenantAId, $orgAOwnerId, 'member', true);
    videochat_call_access_cross_org_attach_user($pdo, $tenantAId, $orgAUserId, 'member', true);
    videochat_call_access_cross_org_attach_user($pdo, $tenantAId, $orgAOnlyUserId, 'member', true);
    videochat_call_access_cross_org_attach_user($pdo, $tenantAId, $legacyAdminId, 'admin', true);
    videochat_call_access_cross_org_attach_user($pdo, $tenantBId, $orgBOwnerId, 'owner', true);
    videochat_call_access_cross_org_attach_user($pdo, $tenantBId, $orgAMultiTenantAdminId, 'member', false);
    videochat_call_access_cross_org_attach_user($pdo, $tenantBId, $orgAOwnerId, 'member', false);
    videochat_call_access_cross_org_attach_user($pdo, $tenantBId, $orgAUserId, 'member', false);

    videochat_call_access_cross_org_attach_organization($pdo, $tenantAId, $organizationAId, $orgAAdminId, 'admin');
    videochat_call_access_cross_org_attach_organization($pdo, $tenantAId, $organizationAId, $orgAMultiTenantAdminId, 'admin');
    videochat_call_access_cross_org_attach_organization($pdo, $tenantAId, $organizationAId, $orgAOwnerId, 'member');
    videochat_call_access_cross_org_attach_organization($pdo, $tenantAId, $organizationAId, $orgAUserId, 'member');
    videochat_call_access_cross_org_attach_organization($pdo, $tenantAId, $organizationAId, $orgAOnlyUserId, 'member');
    videochat_call_access_cross_org_attach_organization($pdo, $tenantBId, $organizationBId, $orgBOwnerId, 'member');
    videochat_call_access_cross_org_attach_organization($pdo, $tenantBId, $organizationBId, $orgAMultiTenantAdminId, 'member');
    videochat_call_access_cross_org_attach_organization($pdo, $tenantBId, $organizationBId, $orgAOwnerId, 'member');
    videochat_call_access_cross_org_attach_organization($pdo, $tenantBId, $organizationBId, $orgAUserId, 'member');

    $tenantAContext = videochat_tenant_context_for_user($pdo, $orgAAdminId, $tenantAId);
    videochat_call_access_cross_org_assert(is_array($tenantAContext), 'organization A admin should have tenant A context');
    videochat_call_access_cross_org_assert((bool) (($tenantAContext['permissions'] ?? [])['tenant_admin'] ?? false), 'organization A admin should be admin in organization A');
    videochat_call_access_cross_org_assert(videochat_tenant_context_for_user($pdo, $orgAAdminId, $tenantBId) === null, 'organization A admin must not have organization B context');

    $orgACallId = videochat_call_access_cross_org_create_call($pdo, $orgAOwnerId, $tenantAId, 'Organization A Own Call', [$orgAUserId]);
    $orgBInviteOnlyCallId = videochat_call_access_cross_org_create_call($pdo, $orgBOwnerId, $tenantBId, 'Organization B Invite Only');
    $orgBOpenCallId = videochat_call_access_cross_org_create_call($pdo, $orgBOwnerId, $tenantBId, 'Organization B Open Link', [], 'free_for_all');

    videochat_ensure_internal_call_participant(
        $pdo,
        $orgACallId,
        $orgAOnlyUserId,
        'cross-org-a-only-user@example.test',
        'Org A Only User',
        'invited'
    );

    $orgAOwnPersonalLink = videochat_create_call_access_link_for_user($pdo, $orgACallId, $orgAOwnerId, 'user', [
        'link_kind' => 'personal',
        'participant_user_id' => $orgAOnlyUserId,
    ], $tenantAId);
    videochat_call_access_cross_org_assert((bool) ($orgAOwnPersonalLink['ok'] ?? false), 'organization A personalized link for organization A user should be created');
    $orgAOwnPersonalAccessId = (string) (($orgAOwnPersonalLink['access_link'] ?? [])['id'] ?? '');
    videochat_call_access_cross_org_assert($orgAOwnPersonalAccessId !== '', 'organization A personalized link id should be present');
    $orgAOwnPersonalSession = videochat_issue_session_for_call_access(
        $pdo,
        $orgAOwnPersonalAccessId,
        static fn (): string => 'sess_cross_org_a_user_a_personal',
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-access-cross-org-contract'],
        [
            'authenticated_user_id' => $orgAOnlyUserId,
            'authenticated_session_id' => 'sess_cross_org_a_user_browser',
            'verified_user_id' => $orgAOnlyUserId,
            'verified_session_id' => 'sess_cross_org_a_user_browser',
        ]
    );
    videochat_call_access_cross_org_assert((bool) ($orgAOwnPersonalSession['ok'] ?? false), 'organization A user should open own-organization personalized link');
    videochat_call_access_cross_org_assert((int) (($orgAOwnPersonalSession['user'] ?? [])['id'] ?? 0) === $orgAOnlyUserId, 'own-organization personalized link should bind organization A user');
    videochat_call_access_cross_org_assert_member_context($pdo, 'organization A personalized link', 'sess_cross_org_a_user_a_personal', $orgAOnlyUserId, $tenantAId, $orgACallId);

    $multiOrgPersonalAccessId = videochat_call_access_cross_org_insert_link($pdo, $tenantAId, $orgACallId, $orgAMultiTenantAdminId);
    $multiOrgPersonalSession = videochat_issue_session_for_call_access(
        $pdo,
        $multiOrgPersonalAccessId,
        static fn (): string => 'sess_cross_org_multi_context_personal',
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-access-cross-org-contract'],
        [
            'authenticated_user_id' => $orgAMultiTenantAdminId,
            'authenticated_session_id' => 'sess_cross_org_multi_active_b',
            'verified_user_id' => $orgAMultiTenantAdminId,
            'verified_session_id' => 'sess_cross_org_multi_active_b',
        ]
    );
    videochat_call_access_cross_org_assert((bool) ($multiOrgPersonalSession['ok'] ?? false), 'multi-organization account should open organization A personalized link');
    $multiOrgAuth = videochat_authenticate_request($pdo, [
        'method' => 'GET',
        'uri' => '/ws?session=sess_cross_org_multi_context_personal&room=' . $orgACallId . '&call_id=' . $orgACallId,
        'headers' => ['Authorization' => 'Bearer sess_cross_org_multi_context_personal'],
    ], 'websocket');
    videochat_call_access_cross_org_assert((bool) ($multiOrgAuth['ok'] ?? false), 'multi-organization call-access session should authenticate');
    videochat_call_access_cross_org_assert((int) (($multiOrgAuth['tenant'] ?? [])['id'] ?? 0) === $tenantAId, 'multi-organization account must use personalized-link call tenant, not browser active organization');
    videochat_call_access_cross_org_assert((bool) (((($multiOrgAuth['tenant'] ?? [])['permissions'] ?? [])['tenant_admin'] ?? true)) === false, 'multi-organization member context must not grow tenant-admin rights from active organization');
    $multiOrgCallDecision = videochat_decide_call_access_for_user($pdo, $orgACallId, $orgAMultiTenantAdminId, 'user', $tenantAId);
    videochat_call_access_cross_org_assert((bool) ($multiOrgCallDecision['allowed'] ?? false), 'multi-organization account should be checked against organization A call context');
    videochat_call_access_cross_org_assert((string) ($multiOrgCallDecision['source'] ?? '') === 'organization_admin', 'multi-organization account should keep organization A call-admin source only for organization A call');

    videochat_ensure_internal_call_participant(
        $pdo,
        $orgBInviteOnlyCallId,
        $orgAOnlyUserId,
        'cross-org-a-only-user@example.test',
        'Org A Only User',
        'invited'
    );
    $orgBPersonalAccessId = videochat_call_access_cross_org_insert_link($pdo, $tenantBId, $orgBInviteOnlyCallId, $orgAOnlyUserId);
    $orgBPersonalSession = videochat_issue_session_for_call_access(
        $pdo,
        $orgBPersonalAccessId,
        static fn (): string => 'sess_cross_org_a_user_b_personal',
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-access-cross-org-contract'],
        [
            'authenticated_user_id' => $orgAOnlyUserId,
            'authenticated_session_id' => 'sess_cross_org_a_user_browser',
            'verified_user_id' => $orgAOnlyUserId,
            'verified_session_id' => 'sess_cross_org_a_user_browser',
        ]
    );
    videochat_call_access_cross_org_assert((bool) ($orgBPersonalSession['ok'] ?? false), 'organization A user should open explicit organization B personalized link as call-scoped participant');
    videochat_call_access_cross_org_assert(!videochat_tenant_user_is_member($pdo, $orgAOnlyUserId, $tenantBId), 'organization B personalized link must not create organization B tenant membership');
    videochat_call_access_cross_org_assert_member_context($pdo, 'organization B personalized link for organization A user', 'sess_cross_org_a_user_b_personal', $orgAOnlyUserId, $tenantBId, $orgBInviteOnlyCallId);

    $foreignTargetAccessId = videochat_call_access_cross_org_insert_link($pdo, $tenantBId, $orgBInviteOnlyCallId, $orgBOwnerId);
    $foreignTargetSession = videochat_issue_session_for_call_access(
        $pdo,
        $foreignTargetAccessId,
        static fn (): string => 'sess_cross_org_a_user_b_wrong_personal',
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-access-cross-org-contract'],
        [
            'authenticated_user_id' => $orgAOnlyUserId,
            'authenticated_session_id' => 'sess_cross_org_a_user_browser',
            'verified_user_id' => $orgAOnlyUserId,
            'verified_session_id' => 'sess_cross_org_a_user_browser',
            'host_name' => 'Wrong Host',
        ]
    );
    videochat_call_access_cross_org_assert(!(bool) ($foreignTargetSession['ok'] ?? true), 'organization A user must not consume organization B personalized link issued for another account');
    videochat_call_access_cross_org_assert((string) ($foreignTargetSession['reason'] ?? '') === 'conflict', 'foreign personalized-link mismatch denial reason mismatch');
    videochat_call_access_cross_org_assert(videochat_call_access_session_id_available($pdo, 'sess_cross_org_a_user_b_wrong_personal'), 'foreign mismatch denial should not persist a session');

    $ownOrgAccess = videochat_get_call_for_user($pdo, $orgACallId, $orgAUserId, 'user', $tenantAId);
    videochat_call_access_cross_org_assert((bool) ($ownOrgAccess['ok'] ?? false), 'organization A participant should access own organization call');
    videochat_call_access_cross_org_assert((bool) ((($ownOrgAccess['call'] ?? [])['my_participation'] ?? false)), 'own organization call should preserve participant state');

    $orgAdminOwnDecision = videochat_decide_call_access_for_user($pdo, $orgACallId, $orgAAdminId, 'user', $tenantAId);
    videochat_call_access_cross_org_assert((bool) ($orgAdminOwnDecision['allowed'] ?? false), 'organization A admin should receive own-organization call access');
    videochat_call_access_cross_org_assert((string) ($orgAdminOwnDecision['source'] ?? '') === 'organization_admin', 'own-organization admin decision source mismatch');
    videochat_call_access_cross_org_assert((string) ($orgAdminOwnDecision['scope'] ?? '') === 'organization', 'own-organization admin decision scope mismatch');
    videochat_call_access_cross_org_assert((bool) ($orgAdminOwnDecision['can_moderate'] ?? false), 'own-organization admin should receive call moderation rights');
    videochat_call_access_cross_org_assert(!(bool) ($orgAdminOwnDecision['can_manage_owner'] ?? true), 'own-organization admin must not receive owner-management rights');

    $orgAdminForeignDecision = videochat_decide_call_access_for_user($pdo, $orgBInviteOnlyCallId, $orgAAdminId, 'user', $tenantBId);
    videochat_call_access_cross_org_assert(!(bool) ($orgAdminForeignDecision['allowed'] ?? true), 'organization A admin should not receive organization B call access');
    videochat_call_access_cross_org_assert((string) ($orgAdminForeignDecision['reason'] ?? '') === 'forbidden', 'organization A admin foreign denial reason mismatch');
    videochat_call_access_cross_org_assert((string) ($orgAdminForeignDecision['source'] ?? '') === 'none', 'foreign organization admin denial must not claim a source');

    $guestListLeak = videochat_get_call_for_user($pdo, $orgBInviteOnlyCallId, $orgAUserId, 'user', $tenantBId);
    videochat_call_access_cross_org_assert(!(bool) ($guestListLeak['ok'] ?? false), 'organization A participant list entry must not leak into organization B invite-only call');
    videochat_call_access_cross_org_assert((string) ($guestListLeak['reason'] ?? '') === 'forbidden', 'guest-list leakage should fail as forbidden inside organization B context');

    $guestListDirectLeak = videochat_user_can_direct_join_call($pdo, $orgBInviteOnlyCallId, $orgAUserId, 'user', $tenantBId);
    videochat_call_access_cross_org_assert(!(bool) ($guestListDirectLeak['ok'] ?? true), 'organization A guest-list entry must not direct-join organization B call');
    videochat_call_access_cross_org_assert((string) ($guestListDirectLeak['reason'] ?? '') === 'not_on_guest_list', 'cross-organization guest-list direct join denial reason mismatch');

    $ownerRightsLeak = videochat_get_call_for_user($pdo, $orgBInviteOnlyCallId, $orgAOwnerId, 'user', $tenantBId);
    videochat_call_access_cross_org_assert(!(bool) ($ownerRightsLeak['ok'] ?? false), 'organization A owner rights must not leak into organization B call');
    videochat_call_access_cross_org_assert((string) ($ownerRightsLeak['reason'] ?? '') === 'forbidden', 'cross-organization owner fetch denial reason mismatch');

    $ownerDirectLeak = videochat_user_can_direct_join_call($pdo, $orgBInviteOnlyCallId, $orgAOwnerId, 'user', $tenantBId);
    videochat_call_access_cross_org_assert(!(bool) ($ownerDirectLeak['ok'] ?? true), 'organization A owner must not direct-join organization B call through owner rights');
    videochat_call_access_cross_org_assert((string) ($ownerDirectLeak['reason'] ?? '') === 'not_on_guest_list', 'cross-organization owner direct join denial reason mismatch');

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
    $pdo->prepare(
        <<<'SQL'
INSERT INTO sessions(id, user_id, active_tenant_id, issued_at, expires_at, revoked_at, client_ip, user_agent)
VALUES(:id, :user_id, :active_tenant_id, :issued_at, :expires_at, NULL, '127.0.0.1', 'call-access-cross-org-contract')
SQL
    )->execute([
        ':id' => $multiTenantSessionId,
        ':user_id' => $orgAMultiTenantAdminId,
        ':active_tenant_id' => $tenantAId,
        ':issued_at' => gmdate('c'),
        ':expires_at' => gmdate('c', time() + 3600),
    ]);
    $multiTenantActiveAAuth = videochat_authenticate_request($pdo, [
        'method' => 'GET',
        'uri' => '/api/calls/' . $orgACallId,
        'headers' => ['Authorization' => 'Bearer ' . $multiTenantSessionId],
    ], 'http');
    videochat_call_access_cross_org_assert((bool) ($multiTenantActiveAAuth['ok'] ?? false), 'multi-tenant organization A admin should authenticate in organization A');
    videochat_call_access_cross_org_assert((int) (($multiTenantActiveAAuth['tenant'] ?? [])['id'] ?? 0) === $tenantAId, 'multi-tenant organization A admin should keep active organization A');

    $multiTenantOwnOrg = videochat_get_call_for_user($pdo, $orgACallId, $orgAMultiTenantAdminId, 'user', $tenantAId);
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
    videochat_call_access_cross_org_assert((bool) (((($multiTenantSwitchedAuth['tenant'] ?? [])['permissions'] ?? [])['tenant_admin'] ?? true)) === false, 'multi-tenant active switch must not grant organization B tenant admin permissions');

    $multiTenantForeignFetch = videochat_get_call_for_user($pdo, $orgBInviteOnlyCallId, $orgAMultiTenantAdminId, 'user', $tenantBId);
    videochat_call_access_cross_org_assert(!(bool) ($multiTenantForeignFetch['ok'] ?? true), 'multi-tenant active switch must not grant organization B call permission');
    videochat_call_access_cross_org_assert((string) ($multiTenantForeignFetch['reason'] ?? '') === 'forbidden', 'multi-tenant active switch denial reason mismatch');

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

    $guestCountBeforeForeignOpenAccount = videochat_call_access_cross_org_guest_account_count($pdo);
    $foreignOpenAccountSession = videochat_issue_session_for_call_access(
        $pdo,
        $openAccessId,
        static fn (): string => 'sess_cross_org_a_user_b_open_account',
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-access-cross-org-contract'],
        [
            'authenticated_user_id' => $orgAOnlyUserId,
            'authenticated_session_id' => 'sess_cross_org_a_user_browser',
            'verified_user_id' => $orgAOnlyUserId,
            'verified_session_id' => 'sess_cross_org_a_user_browser',
            'guest_name' => 'Org A User Via Anonymous Link',
        ]
    );
    videochat_call_access_cross_org_assert((bool) ($foreignOpenAccountSession['ok'] ?? false), 'organization A user should open organization B anonymous link as call-scoped participant');
    videochat_call_access_cross_org_assert((int) (($foreignOpenAccountSession['user'] ?? [])['id'] ?? 0) === $orgAOnlyUserId, 'foreign anonymous link should keep the logged-in organization A account');
    videochat_call_access_cross_org_assert(videochat_call_access_cross_org_guest_account_count($pdo) === $guestCountBeforeForeignOpenAccount, 'foreign anonymous link for logged-in account must not create another temporary guest');
    videochat_call_access_cross_org_assert(!videochat_tenant_user_is_member($pdo, $orgAOnlyUserId, $tenantBId), 'foreign anonymous link must not create organization B tenant membership');
    videochat_call_access_cross_org_assert_member_context($pdo, 'organization B anonymous link for organization A user', 'sess_cross_org_a_user_b_open_account', $orgAOnlyUserId, $tenantBId, $orgBOpenCallId);

    $temporaryCreate = videochat_create_guest_user_for_call_access($pdo, 'Org A Temporary Invitee', $tenantAId, false);
    videochat_call_access_cross_org_assert((bool) ($temporaryCreate['ok'] ?? false), 'organization A invitation temporary account should be created');
    $temporaryUser = is_array($temporaryCreate['user'] ?? null) ? $temporaryCreate['user'] : [];
    $temporaryUserId = (int) ($temporaryUser['id'] ?? 0);
    videochat_call_access_cross_org_assert($temporaryUserId > 0, 'organization A temporary account id should be present');
    videochat_call_access_cross_org_assert((bool) ($temporaryUser['is_guest'] ?? false) === true, 'organization A temporary account should be a guest account');
    videochat_call_access_cross_org_assert(!videochat_tenant_user_is_member($pdo, $temporaryUserId, $tenantAId), 'organization A temporary invitation account should not receive organization-wide tenant membership');
    videochat_call_access_cross_org_assert(!videochat_tenant_user_is_member($pdo, $temporaryUserId, $tenantBId), 'organization A temporary invitation account should not receive organization B tenant membership');
    videochat_ensure_internal_call_participant(
        $pdo,
        $orgACallId,
        $temporaryUserId,
        (string) ($temporaryUser['email'] ?? ''),
        (string) ($temporaryUser['display_name'] ?? 'Org A Temporary Invitee'),
        'invited'
    );
    $temporaryAccessId = videochat_call_access_cross_org_insert_link($pdo, $tenantAId, $orgACallId, $temporaryUserId);
    $temporarySession = videochat_issue_session_for_call_access(
        $pdo,
        $temporaryAccessId,
        static fn (): string => 'sess_cross_org_a_temporary',
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-access-cross-org-contract']
    );
    videochat_call_access_cross_org_assert((bool) ($temporarySession['ok'] ?? false), 'organization A temporary invite should issue call-scoped session');
    videochat_call_access_cross_org_assert((int) (($temporarySession['user'] ?? [])['id'] ?? 0) === $temporaryUserId, 'organization A temporary invite should bind the temporary account');
    videochat_call_access_cross_org_assert_member_context($pdo, 'organization A temporary invite', 'sess_cross_org_a_temporary', $temporaryUserId, $tenantAId, $orgACallId);
    videochat_call_access_cross_org_assert_no_call_rights($pdo, 'organization A temporary invite account', $orgBInviteOnlyCallId, $temporaryUserId, $tenantBId);

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
