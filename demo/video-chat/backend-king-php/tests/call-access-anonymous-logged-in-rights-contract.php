<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../domain/realtime/realtime_call_context.php';

function videochat_iam805_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-access-anonymous-logged-in-rights-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_iam805_role_id(PDO $pdo, string $role): int
{
    $query = $pdo->prepare('SELECT id FROM roles WHERE slug = :slug LIMIT 1');
    $query->execute([':slug' => $role]);
    return (int) $query->fetchColumn();
}

function videochat_iam805_create_user(PDO $pdo, string $email, string $displayName): int
{
    $passwordHash = password_hash('call-access-anonymous-logged-in-rights-contract', PASSWORD_DEFAULT);
    videochat_iam805_assert(is_string($passwordHash) && $passwordHash !== '', 'password hash should be available');

    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, date_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', '24h', 'dmy_dot', 'dark', :updated_at)
SQL
    );
    $insert->execute([
        ':email' => strtolower($email),
        ':display_name' => $displayName,
        ':password_hash' => $passwordHash,
        ':role_id' => videochat_iam805_role_id($pdo, 'user'),
        ':updated_at' => gmdate('c'),
    ]);

    $userId = (int) $pdo->lastInsertId();
    videochat_iam805_assert($userId > 0, "{$displayName} user should be created");
    return $userId;
}

function videochat_iam805_create_tenant(PDO $pdo, string $unique, string $suffix): int
{
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO tenants(public_id, slug, label, status, created_at, updated_at)
VALUES(:public_id, :slug, :label, 'active', :created_at, :updated_at)
SQL
    );
    $insert->execute([
        ':public_id' => "tenant-iam805-{$suffix}-{$unique}",
        ':slug' => "iam805-{$suffix}-{$unique}",
        ':label' => "IAM805 {$suffix}",
        ':created_at' => gmdate('c'),
        ':updated_at' => gmdate('c'),
    ]);

    $tenantId = (int) $pdo->lastInsertId();
    videochat_iam805_assert($tenantId > 0, "{$suffix} tenant should be created");
    return $tenantId;
}

function videochat_iam805_create_organization(PDO $pdo, int $tenantId, string $unique, string $suffix): int
{
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO organizations(tenant_id, public_id, name, status, created_at, updated_at)
VALUES(:tenant_id, :public_id, :name, 'active', :created_at, :updated_at)
SQL
    );
    $insert->execute([
        ':tenant_id' => $tenantId,
        ':public_id' => "org-iam805-{$suffix}-{$unique}",
        ':name' => "IAM805 {$suffix}",
        ':created_at' => gmdate('c'),
        ':updated_at' => gmdate('c'),
    ]);

    $organizationId = (int) $pdo->lastInsertId();
    videochat_iam805_assert($organizationId > 0, "{$suffix} organization should be created");
    return $organizationId;
}

function videochat_iam805_attach_user(PDO $pdo, int $tenantId, int $organizationId, int $userId, string $organizationRole): void
{
    videochat_tenant_attach_user($pdo, $userId, $tenantId);

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
        ':membership_role' => $organizationRole,
        ':created_at' => gmdate('c'),
        ':updated_at' => gmdate('c'),
    ]);
}

function videochat_iam805_create_call(PDO $pdo, int $ownerUserId, int $tenantId, string $title, array $participants = []): string
{
    $create = videochat_create_call($pdo, $ownerUserId, [
        'title' => $title,
        'access_mode' => 'invite_only',
        'starts_at' => gmdate('c', time() - 300),
        'ends_at' => gmdate('c', time() + 3600),
        'internal_participant_user_ids' => $participants,
        'external_participants' => [],
    ], $tenantId);
    videochat_iam805_assert((bool) ($create['ok'] ?? false), "{$title} should be created");

    $callId = (string) (($create['call'] ?? [])['id'] ?? '');
    videochat_iam805_assert($callId !== '', "{$title} should return a call id");
    return $callId;
}

function videochat_iam805_insert_open_link(PDO $pdo, int $tenantId, string $callId, int $createdByUserId): string
{
    $accessId = videochat_generate_call_access_uuid();
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO call_access_links(
    id,
    tenant_id,
    call_id,
    participant_user_id,
    participant_email,
    invite_code_id,
    created_by_user_id,
    created_at,
    expires_at,
    last_used_at,
    consumed_at
) VALUES(
    :id,
    :tenant_id,
    :call_id,
    NULL,
    NULL,
    NULL,
    :created_by_user_id,
    :created_at,
    :expires_at,
    NULL,
    NULL
)
SQL
    );
    $insert->execute([
        ':id' => $accessId,
        ':tenant_id' => $tenantId,
        ':call_id' => $callId,
        ':created_by_user_id' => $createdByUserId,
        ':created_at' => gmdate('c'),
        ':expires_at' => gmdate('c', time() + 3600),
    ]);

    return $accessId;
}

function videochat_iam805_issue(PDO $pdo, string $accessId, string $sessionId, int $authenticatedUserId): array
{
    return videochat_issue_session_for_call_access(
        $pdo,
        $accessId,
        static fn (): string => $sessionId,
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-access-anonymous-logged-in-rights-contract'],
        [
            'authenticated_user_id' => $authenticatedUserId,
            'authenticated_session_id' => 'browser_' . $sessionId,
            'verified_user_id' => $authenticatedUserId,
            'verified_session_id' => 'browser_' . $sessionId,
            'guest_name' => 'Ignored Logged In Guest Name',
        ]
    );
}

function videochat_iam805_auth(PDO $pdo, string $sessionId, string $callId): array
{
    $auth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/ws?session=' . rawurlencode($sessionId) . '&room=' . rawurlencode($callId) . '&call_id=' . rawurlencode($callId),
            'headers' => ['Authorization' => 'Bearer ' . $sessionId],
        ],
        'websocket'
    );
    videochat_iam805_assert((bool) ($auth['ok'] ?? false), "{$sessionId} should authenticate");
    return $auth;
}

function videochat_iam805_guest_list_count(PDO $pdo, string $callId): int
{
    $query = $pdo->prepare(
        <<<'SQL'
SELECT COUNT(*)
FROM call_participants
WHERE call_id = :call_id
  AND source = 'internal'
  AND invite_state IN ('invited', 'allowed', 'accepted')
SQL
    );
    $query->execute([':call_id' => $callId]);
    return (int) $query->fetchColumn();
}

function videochat_iam805_participant(PDO $pdo, string $callId, int $userId): ?array
{
    $query = $pdo->prepare(
        <<<'SQL'
SELECT user_id, email, display_name, source, call_role, invite_state
FROM call_participants
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
LIMIT 1
SQL
    );
    $query->execute([
        ':call_id' => $callId,
        ':user_id' => $userId,
    ]);
    $row = $query->fetch();
    return is_array($row) ? $row : null;
}

function videochat_iam805_assert_open_binding(PDO $pdo, string $label, string $accessId, string $sessionId, string $callId, int $expectedUserId): void
{
    $link = videochat_fetch_call_access_link($pdo, $accessId);
    videochat_iam805_assert(is_array($link), "{$label}: open link should remain persisted");
    videochat_iam805_assert(videochat_call_access_link_kind($link) === 'open', "{$label}: link kind should stay open");
    videochat_iam805_assert(($link['participant_user_id'] ?? null) === null, "{$label}: open link must not gain participant_user_id");
    videochat_iam805_assert(trim((string) ($link['participant_email'] ?? '')) === '', "{$label}: open link must not gain participant_email");
    videochat_iam805_assert(trim((string) ($link['consumed_at'] ?? '')) === '', "{$label}: open link must stay reusable");

    $binding = videochat_fetch_call_access_session_binding($pdo, $sessionId);
    videochat_iam805_assert(is_array($binding), "{$label}: call-access session binding should exist");
    videochat_iam805_assert((string) ($binding['access_id'] ?? '') === $accessId, "{$label}: binding access mismatch");
    videochat_iam805_assert((string) ($binding['call_id'] ?? '') === $callId, "{$label}: binding call mismatch");
    videochat_iam805_assert((int) ($binding['user_id'] ?? 0) === $expectedUserId, "{$label}: binding user mismatch");
    videochat_iam805_assert((string) ($binding['link_kind'] ?? '') === 'open', "{$label}: session binding must remain open-link kind");
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-access-anonymous-logged-in-rights-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-call-access-anonymous-logged-in-rights-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);
    $openDatabase = static function () use ($pdo): PDO {
        return $pdo;
    };

    videochat_iam805_assert(videochat_iam805_role_id($pdo, 'user') > 0, 'user role should exist');

    $unique = bin2hex(random_bytes(5));
    $tenantAId = videochat_iam805_create_tenant($pdo, $unique, 'a');
    $tenantBId = videochat_iam805_create_tenant($pdo, $unique, 'b');
    $organizationAId = videochat_iam805_create_organization($pdo, $tenantAId, $unique, 'a');
    $organizationBId = videochat_iam805_create_organization($pdo, $tenantBId, $unique, 'b');

    $alphaAdminId = videochat_iam805_create_user($pdo, "iam805-alpha-admin-{$unique}@example.test", 'IAM805 Alpha Org Admin');
    $alphaOwnerId = videochat_iam805_create_user($pdo, "iam805-alpha-owner-{$unique}@example.test", 'IAM805 Alpha Owner');
    $guestListUserId = videochat_iam805_create_user($pdo, "iam805-guest-list-{$unique}@example.test", 'IAM805 Guest List User');
    $betaOwnerId = videochat_iam805_create_user($pdo, "iam805-beta-owner-{$unique}@example.test", 'IAM805 Beta Owner');

    videochat_iam805_attach_user($pdo, $tenantAId, $organizationAId, $alphaAdminId, 'admin');
    videochat_iam805_attach_user($pdo, $tenantAId, $organizationAId, $alphaOwnerId, 'member');
    videochat_iam805_attach_user($pdo, $tenantAId, $organizationAId, $guestListUserId, 'member');
    videochat_iam805_attach_user($pdo, $tenantBId, $organizationBId, $betaOwnerId, 'member');

    $alphaCallId = videochat_iam805_create_call($pdo, $alphaOwnerId, $tenantAId, 'IAM805 Own Org Rights', [$guestListUserId]);
    $betaCallId = videochat_iam805_create_call($pdo, $betaOwnerId, $tenantBId, 'IAM805 Foreign Org Rights');

    $pdo->prepare(
        <<<'SQL'
UPDATE call_participants
SET invite_state = 'allowed'
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
SQL
    )->execute([
        ':call_id' => $alphaCallId,
        ':user_id' => $guestListUserId,
    ]);

    $alphaOpenAccessId = videochat_iam805_insert_open_link($pdo, $tenantAId, $alphaCallId, $alphaOwnerId);
    $betaOpenAccessId = videochat_iam805_insert_open_link($pdo, $tenantBId, $betaCallId, $betaOwnerId);

    $alphaGuestListBefore = videochat_iam805_guest_list_count($pdo, $alphaCallId);
    $guestUsersBefore = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE 'guest+%@videochat.local'")->fetchColumn();
    $ownOrgSession = videochat_iam805_issue($pdo, $alphaOpenAccessId, 'sess_iam805_org_admin_own', $alphaAdminId);
    videochat_iam805_assert((bool) ($ownOrgSession['ok'] ?? false), 'own organization admin should issue through anonymous open link');
    videochat_iam805_assert((int) (($ownOrgSession['user'] ?? [])['id'] ?? 0) === $alphaAdminId, 'own organization admin session should keep logged-in account');
    videochat_iam805_assert((int) $pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE 'guest+%@videochat.local'")->fetchColumn() === $guestUsersBefore, 'own organization admin link must not create a temporary guest');
    videochat_iam805_assert(videochat_iam805_participant($pdo, $alphaCallId, $alphaAdminId) === null, 'own organization admin anonymous link must not add guest-list row');
    videochat_iam805_assert(videochat_iam805_guest_list_count($pdo, $alphaCallId) === $alphaGuestListBefore, 'own organization admin anonymous link must not modify guest list');
    videochat_iam805_assert_open_binding($pdo, 'own organization admin', $alphaOpenAccessId, 'sess_iam805_org_admin_own', $alphaCallId, $alphaAdminId);

    $ownDecision = videochat_decide_call_access_for_user($pdo, $alphaCallId, $alphaAdminId, 'user', $tenantAId);
    videochat_iam805_assert((bool) ($ownDecision['allowed'] ?? false), 'own org admin direct decision should allow');
    videochat_iam805_assert((string) ($ownDecision['source'] ?? '') === 'organization_admin', 'own org admin decision source mismatch');
    $ownDirect = videochat_user_can_direct_join_call($pdo, $alphaCallId, $alphaAdminId, 'user', $tenantAId);
    videochat_iam805_assert((bool) ($ownDirect['ok'] ?? false), 'own org admin direct join should allow');
    videochat_iam805_assert((string) ($ownDirect['reason'] ?? '') === 'organization_admin', 'own org admin direct join reason mismatch');

    $ownOrgResolution = videochat_realtime_resolve_connection_rooms(videochat_iam805_auth($pdo, 'sess_iam805_org_admin_own', $alphaCallId), $alphaCallId, $openDatabase, $alphaCallId);
    videochat_iam805_assert((string) ($ownOrgResolution['initial_room_id'] ?? '') === $alphaCallId, 'own organization admin should enter own org call room');
    videochat_iam805_assert((string) ($ownOrgResolution['pending_room_id'] ?? '') === '', 'own organization admin should not need lobby admission');

    $guestListSession = videochat_iam805_issue($pdo, $alphaOpenAccessId, 'sess_iam805_guest_list', $guestListUserId);
    videochat_iam805_assert((bool) ($guestListSession['ok'] ?? false), 'guest-list user should issue through anonymous open link');
    videochat_iam805_assert((int) (($guestListSession['user'] ?? [])['id'] ?? 0) === $guestListUserId, 'guest-list anonymous session should keep logged-in account');
    videochat_iam805_assert(videochat_iam805_guest_list_count($pdo, $alphaCallId) === $alphaGuestListBefore, 'guest-list anonymous link must not mutate the guest list');
    videochat_iam805_assert_open_binding($pdo, 'guest-list user', $alphaOpenAccessId, 'sess_iam805_guest_list', $alphaCallId, $guestListUserId);

    $guestResolution = videochat_realtime_resolve_connection_rooms(videochat_iam805_auth($pdo, 'sess_iam805_guest_list', $alphaCallId), $alphaCallId, $openDatabase, $alphaCallId);
    videochat_iam805_assert((string) ($guestResolution['initial_room_id'] ?? '') === $alphaCallId, 'guest-list user should enter through anonymous link');
    videochat_iam805_assert((string) ($guestResolution['pending_room_id'] ?? '') === '', 'guest-list user should not need anonymous-link lobby admission');

    $betaGuestListBefore = videochat_iam805_guest_list_count($pdo, $betaCallId);
    $foreignSession = videochat_iam805_issue($pdo, $betaOpenAccessId, 'sess_iam805_org_admin_foreign', $alphaAdminId);
    videochat_iam805_assert((bool) ($foreignSession['ok'] ?? false), 'foreign organization anonymous link should issue only a lobby session');
    videochat_iam805_assert((int) (($foreignSession['user'] ?? [])['id'] ?? 0) === $alphaAdminId, 'foreign organization anonymous link should keep logged-in account');
    videochat_iam805_assert(videochat_iam805_guest_list_count($pdo, $betaCallId) === $betaGuestListBefore, 'foreign anonymous link must not add a guest-list entry');
    $foreignParticipant = videochat_iam805_participant($pdo, $betaCallId, $alphaAdminId);
    videochat_iam805_assert(is_array($foreignParticipant), 'foreign anonymous link should persist only a pending lobby row');
    videochat_iam805_assert((string) ($foreignParticipant['invite_state'] ?? '') === 'pending', 'foreign anonymous link participant must stay pending');
    videochat_iam805_assert_open_binding($pdo, 'foreign organization admin', $betaOpenAccessId, 'sess_iam805_org_admin_foreign', $betaCallId, $alphaAdminId);

    $foreignDecision = videochat_decide_call_access_for_user($pdo, $betaCallId, $alphaAdminId, 'user', $tenantBId);
    videochat_iam805_assert(!(bool) ($foreignDecision['allowed'] ?? true), 'foreign org admin must not gain direct call decision through anonymous link');
    videochat_iam805_assert((string) ($foreignDecision['source'] ?? '') === 'none', 'foreign org admin denial must not claim an access source');
    $foreignDirect = videochat_user_can_direct_join_call($pdo, $betaCallId, $alphaAdminId, 'user', $tenantBId);
    videochat_iam805_assert(!(bool) ($foreignDirect['ok'] ?? true), 'foreign org admin must not direct-join through pending anonymous link row');
    videochat_iam805_assert((string) ($foreignDirect['reason'] ?? '') === 'not_on_guest_list', 'foreign direct denial reason mismatch');

    $foreignResolution = videochat_realtime_resolve_connection_rooms(videochat_iam805_auth($pdo, 'sess_iam805_org_admin_foreign', $betaCallId), $betaCallId, $openDatabase, $betaCallId);
    videochat_iam805_assert((string) ($foreignResolution['initial_room_id'] ?? '') === videochat_realtime_waiting_room_id(), 'foreign org admin should start in lobby through anonymous link');
    videochat_iam805_assert((string) ($foreignResolution['pending_room_id'] ?? '') === $betaCallId, 'foreign org admin should be pending only for the foreign call');

    @unlink($databasePath);
    fwrite(STDOUT, "[call-access-anonymous-logged-in-rights-contract] PASS\n");
} catch (Throwable $error) {
    fwrite(STDERR, '[call-access-anonymous-logged-in-rights-contract] ERROR: ' . $error->getMessage() . "\n");
    fwrite(STDERR, $error->getTraceAsString() . "\n");
    exit(1);
} finally {
    if (isset($databasePath) && is_string($databasePath) && is_file($databasePath)) {
        @unlink($databasePath);
    }
}
