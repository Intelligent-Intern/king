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
    $orgAAdminId = videochat_call_access_cross_org_create_user($pdo, 'cross-org-a-admin@example.test', 'Org A Admin');
    $orgAUserId = videochat_call_access_cross_org_create_user($pdo, 'cross-org-a-user@example.test', 'Org A User');
    $orgBOwnerId = videochat_call_access_cross_org_create_user($pdo, 'cross-org-b-owner@example.test', 'Org B Owner');
    $legacyAdminId = videochat_call_access_cross_org_create_user($pdo, 'cross-org-legacy-admin@example.test', 'Legacy Admin', 'admin');

    videochat_call_access_cross_org_attach_user($pdo, $tenantAId, $orgAAdminId, 'admin', true);
    videochat_call_access_cross_org_attach_user($pdo, $tenantAId, $orgAUserId, 'member', true);
    videochat_call_access_cross_org_attach_user($pdo, $tenantAId, $legacyAdminId, 'admin', true);
    videochat_call_access_cross_org_attach_user($pdo, $tenantBId, $orgBOwnerId, 'owner', true);

    $tenantAContext = videochat_tenant_context_for_user($pdo, $orgAAdminId, $tenantAId);
    videochat_call_access_cross_org_assert(is_array($tenantAContext), 'organization A admin should have tenant A context');
    videochat_call_access_cross_org_assert((bool) (($tenantAContext['permissions'] ?? [])['tenant_admin'] ?? false), 'organization A admin should be admin in organization A');
    videochat_call_access_cross_org_assert(videochat_tenant_context_for_user($pdo, $orgAAdminId, $tenantBId) === null, 'organization A admin must not have organization B context');

    $orgACallId = videochat_call_access_cross_org_create_call($pdo, $orgAAdminId, $tenantAId, 'Organization A Own Call', [$orgAUserId]);
    $orgBInviteOnlyCallId = videochat_call_access_cross_org_create_call($pdo, $orgBOwnerId, $tenantBId, 'Organization B Invite Only');
    $orgBOpenCallId = videochat_call_access_cross_org_create_call($pdo, $orgBOwnerId, $tenantBId, 'Organization B Open Link', [], 'free_for_all');

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
