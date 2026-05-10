<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../domain/tenancy/tenant_administration.php';
require_once __DIR__ . '/../domain/realtime/realtime_presence.php';
require_once __DIR__ . '/../domain/realtime/realtime_call_context.php';
require_once __DIR__ . '/../domain/realtime/realtime_room_snapshot.php';

function videochat_iam719_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-access-org-removal-active-privilege-downgrade-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_iam719_user_role_id(PDO $pdo): int
{
    $query = $pdo->query("SELECT id FROM roles WHERE slug = 'user' LIMIT 1");
    return (int) ($query !== false ? $query->fetchColumn() : 0);
}

function videochat_iam719_seed_user(
    PDO $pdo,
    string $email,
    string $displayName,
    int $tenantId,
    int $organizationId,
    string $tenantRole = 'member',
    string $organizationRole = 'member'
): int {
    $roleId = videochat_iam719_user_role_id($pdo);
    videochat_iam719_assert($roleId > 0, 'expected seeded user role');

    $now = gmdate('c');
    $passwordHash = password_hash('iam719-contract-password', PASSWORD_DEFAULT);
    videochat_iam719_assert(is_string($passwordHash) && $passwordHash !== '', 'password hash should be generated');

    $insertUser = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, date_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', '24h', 'dmy_dot', 'dark', :updated_at)
SQL
    );
    $insertUser->execute([
        ':email' => strtolower($email),
        ':display_name' => $displayName,
        ':password_hash' => $passwordHash,
        ':role_id' => $roleId,
        ':updated_at' => $now,
    ]);
    $userId = (int) $pdo->lastInsertId();
    videochat_iam719_assert($userId > 0, 'inserted user id should be positive');

    $insertTenantMembership = $pdo->prepare(
        <<<'SQL'
INSERT INTO tenant_memberships(tenant_id, user_id, membership_role, status, permissions_json, default_membership, created_at, updated_at)
VALUES(:tenant_id, :user_id, :membership_role, 'active', :permissions_json, 1, :created_at, :updated_at)
SQL
    );
    $insertTenantMembership->execute([
        ':tenant_id' => $tenantId,
        ':user_id' => $userId,
        ':membership_role' => videochat_tenant_normalize_role($tenantRole),
        ':permissions_json' => json_encode([], JSON_THROW_ON_ERROR),
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    $insertOrganizationMembership = $pdo->prepare(
        <<<'SQL'
INSERT INTO organization_memberships(tenant_id, organization_id, user_id, membership_role, status, created_at, updated_at)
VALUES(:tenant_id, :organization_id, :user_id, :membership_role, 'active', :created_at, :updated_at)
SQL
    );
    $insertOrganizationMembership->execute([
        ':tenant_id' => $tenantId,
        ':organization_id' => $organizationId,
        ':user_id' => $userId,
        ':membership_role' => strtolower(trim($organizationRole)) === 'admin' ? 'admin' : 'member',
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    return $userId;
}

function videochat_iam719_set_invite_state(PDO $pdo, string $callId, int $userId, string $inviteState): void
{
    $statement = $pdo->prepare(
        <<<'SQL'
UPDATE call_participants
SET invite_state = :invite_state
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
SQL
    );
    $statement->execute([
        ':invite_state' => videochat_normalize_call_invite_state($inviteState),
        ':call_id' => $callId,
        ':user_id' => $userId,
    ]);
}

function videochat_iam719_disable_organization_membership(PDO $pdo, int $tenantId, int $organizationId, int $userId): void
{
    $statement = $pdo->prepare(
        <<<'SQL'
UPDATE organization_memberships
SET status = 'disabled',
    updated_at = :updated_at
WHERE tenant_id = :tenant_id
  AND organization_id = :organization_id
  AND user_id = :user_id
SQL
    );
    $statement->execute([
        ':updated_at' => gmdate('c'),
        ':tenant_id' => $tenantId,
        ':organization_id' => $organizationId,
        ':user_id' => $userId,
    ]);
}

function videochat_iam719_disable_tenant_membership(PDO $pdo, int $tenantId, int $userId): void
{
    $updatedAt = gmdate('c');
    $tenantMembership = $pdo->prepare(
        <<<'SQL'
UPDATE tenant_memberships
SET status = 'disabled',
    updated_at = :updated_at
WHERE tenant_id = :tenant_id
  AND user_id = :user_id
SQL
    );
    $tenantMembership->execute([
        ':updated_at' => $updatedAt,
        ':tenant_id' => $tenantId,
        ':user_id' => $userId,
    ]);

    $organizationMemberships = $pdo->prepare(
        <<<'SQL'
UPDATE organization_memberships
SET status = 'disabled',
    updated_at = :updated_at
WHERE tenant_id = :tenant_id
  AND user_id = :user_id
SQL
    );
    $organizationMemberships->execute([
        ':updated_at' => $updatedAt,
        ':tenant_id' => $tenantId,
        ':user_id' => $userId,
    ]);
}

function videochat_iam719_issue_user_session(
    PDO $pdo,
    int $userId,
    int $tenantId,
    string $sessionId,
    string $label
): array {
    $session = videochat_issue_session_for_user(
        $pdo,
        $userId,
        static fn (): string => $sessionId,
        3600,
        '127.0.0.1',
        $label,
        time(),
        $tenantId
    );
    videochat_iam719_assert((bool) ($session['ok'] ?? false), "{$sessionId} should issue");

    $auth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/ws?session=' . $sessionId,
            'headers' => ['Authorization' => 'Bearer ' . $sessionId],
        ],
        'websocket'
    );
    videochat_iam719_assert((bool) ($auth['ok'] ?? false), "{$sessionId} should authenticate");
    return $auth;
}

function videochat_iam719_connection(
    PDO $pdo,
    array &$presenceState,
    string $roomId,
    string $callId,
    int $userId,
    string $displayName,
    int $tenantId,
    string $sessionId,
    string $suffix
): array {
    $connection = videochat_presence_connection_descriptor(
        [
            'id' => $userId,
            'display_name' => $displayName,
            'role' => 'user',
            'tenant' => ['id' => $tenantId],
        ],
        $sessionId,
        'conn-iam719-' . $suffix,
        'socket-iam719-' . $suffix,
        $roomId
    );
    $connection['requested_call_id'] = $callId;
    $openDatabase = static fn (): PDO => $pdo;
    $connection = videochat_realtime_connection_with_call_context($connection, $openDatabase);
    $join = videochat_presence_join_room($presenceState, $connection, $roomId);

    return (array) ($join['connection'] ?? $connection);
}

$label = 'call-access-org-removal-active-privilege-downgrade-contract';
$databasePath = null;

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[{$label}] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-iam719-org-removal-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);
    $openDatabase = static fn (): PDO => $pdo;

    $tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    $organizationRow = $pdo->query("SELECT id FROM organizations WHERE tenant_id = {$tenantId} ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $defaultOwnerUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('user@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $adminUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    videochat_iam719_assert($tenantId > 0, 'expected default tenant');
    videochat_iam719_assert(is_array($organizationRow), 'expected default organization');
    videochat_iam719_assert($defaultOwnerUserId > 0, 'expected seeded default owner');
    videochat_iam719_assert($adminUserId > 0, 'expected seeded admin user');
    $organizationId = (int) ($organizationRow['id'] ?? 0);
    videochat_iam719_assert($organizationId > 0, 'expected organization id');

    $orgAdminUserId = videochat_iam719_seed_user(
        $pdo,
        'iam719-org-admin-active-removal@example.test',
        'IAM719 Org Admin Active Removal',
        $tenantId,
        $organizationId,
        'member',
        'admin'
    );
    $orgAdminCall = videochat_create_call($pdo, $defaultOwnerUserId, [
        'title' => 'IAM719 Org Admin Removal',
        'access_mode' => 'invite_only',
        'starts_at' => '2026-11-03T09:00:00Z',
        'ends_at' => '2026-11-03T10:00:00Z',
        'internal_participant_user_ids' => [],
        'external_participants' => [],
    ], $tenantId);
    videochat_iam719_assert((bool) ($orgAdminCall['ok'] ?? false), 'org-admin call should be created');
    $orgAdminCallId = (string) (($orgAdminCall['call'] ?? [])['id'] ?? '');
    $orgAdminRoomId = (string) (($orgAdminCall['call'] ?? [])['room_id'] ?? '');
    videochat_iam719_assert($orgAdminCallId !== '' && $orgAdminRoomId !== '', 'org-admin call ids should be present');

    $orgAdminAuth = videochat_iam719_issue_user_session($pdo, $orgAdminUserId, $tenantId, 'sess_iam719_org_admin_active_removal', $label);
    $orgAdminBeforeAccess = videochat_get_call_for_user($pdo, $orgAdminCallId, $orgAdminUserId, 'user', $tenantId);
    videochat_iam719_assert((bool) ($orgAdminBeforeAccess['ok'] ?? false), 'org admin should access same-organization invite-only call before removal');
    videochat_iam719_assert(
        videochat_can_administer_call($pdo, $orgAdminCallId, 'user', $orgAdminUserId, $defaultOwnerUserId, $tenantId),
        'org admin should administer same-organization call before removal'
    );
    $orgAdminResolution = videochat_realtime_resolve_connection_rooms($orgAdminAuth, $orgAdminRoomId, $openDatabase, $orgAdminCallId);
    videochat_iam719_assert((string) ($orgAdminResolution['initial_room_id'] ?? '') === $orgAdminRoomId, 'org admin should bypass lobby before removal');

    $orgAdminPresence = videochat_presence_state_init();
    $orgAdminConnection = videochat_iam719_connection(
        $pdo,
        $orgAdminPresence,
        $orgAdminRoomId,
        $orgAdminCallId,
        $orgAdminUserId,
        'IAM719 Org Admin Active Removal',
        $tenantId,
        'sess_iam719_org_admin_active_removal',
        'org-admin-before'
    );
    videochat_iam719_assert((string) ($orgAdminConnection['active_call_id'] ?? '') === $orgAdminCallId, 'org admin connection should bind active call before removal');
    videochat_iam719_assert((string) ($orgAdminConnection['effective_call_role'] ?? '') === 'moderator', 'org admin should resolve moderator-equivalent role before removal');
    videochat_iam719_assert((bool) ($orgAdminConnection['can_moderate_call'] ?? false), 'org admin should moderate before removal');

    videochat_iam719_disable_organization_membership($pdo, $tenantId, $organizationId, $orgAdminUserId);
    $orgAdminAuthAfterRemoval = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/api/auth/session',
            'headers' => ['Authorization' => 'Bearer sess_iam719_org_admin_active_removal'],
        ],
        'http'
    );
    videochat_iam719_assert((bool) ($orgAdminAuthAfterRemoval['ok'] ?? false), 'organization removal alone should not invalidate tenant session');
    videochat_iam719_assert(!videochat_user_is_organization_admin_for_call($pdo, $orgAdminCallId, $orgAdminUserId, $tenantId), 'removed org member must lose org-admin call rights');
    videochat_iam719_assert(!videochat_can_administer_call($pdo, $orgAdminCallId, 'admin', $orgAdminUserId, $defaultOwnerUserId, $tenantId), 'forged admin role must not restore removed org-admin controls');
    $orgAdminAfterAccess = videochat_get_call_for_user($pdo, $orgAdminCallId, $orgAdminUserId, 'user', $tenantId);
    videochat_iam719_assert(!(bool) ($orgAdminAfterAccess['ok'] ?? true), 'removed org member must not access hidden invite-only call');
    videochat_iam719_assert((string) ($orgAdminAfterAccess['reason'] ?? '') === 'forbidden', 'removed org member hidden-call reason mismatch');

    $staleOrgAdminConnection = $orgAdminConnection;
    $staleOrgAdminConnection['call_role'] = 'moderator';
    $staleOrgAdminConnection['effective_call_role'] = 'moderator';
    $staleOrgAdminConnection['can_moderate_call'] = true;
    $revalidatedOrgAdmin = videochat_realtime_connection_with_call_context($staleOrgAdminConnection, $openDatabase);
    videochat_iam719_assert((string) ($revalidatedOrgAdmin['active_call_id'] ?? '') === '', 'stale org-admin connection must lose active call binding after removal');
    videochat_iam719_assert((string) ($revalidatedOrgAdmin['effective_call_role'] ?? '') === 'participant', 'stale org-admin connection should downgrade to participant role');
    videochat_iam719_assert(!(bool) ($revalidatedOrgAdmin['can_moderate_call'] ?? true), 'stale org-admin connection must lose moderation after removal');
    videochat_iam719_assert(
        !videochat_realtime_is_user_moderator_for_room($openDatabase, $orgAdminUserId, 'user', $orgAdminRoomId, $orgAdminCallId, $tenantId),
        'removed org admin must not moderate from current backend state'
    );
    videochat_iam719_assert(
        !videochat_realtime_connection_can_bypass_admission_for_room($staleOrgAdminConnection, $orgAdminRoomId, $openDatabase),
        'removed org admin direct-room bypass must fail closed against stale connection fields'
    );
    $orgAdminAfterResolution = videochat_realtime_resolve_connection_rooms($orgAdminAuthAfterRemoval, $orgAdminRoomId, $openDatabase, $orgAdminCallId);
    videochat_iam719_assert((string) ($orgAdminAfterResolution['initial_room_id'] ?? '') === videochat_realtime_waiting_room_id(), 'removed org admin should route to waiting room');
    videochat_iam719_assert((string) ($orgAdminAfterResolution['pending_room_id'] ?? '') === $orgAdminRoomId, 'removed org admin should keep only pending admission request');

    $callScopedOrgAdminUserId = videochat_iam719_seed_user(
        $pdo,
        'iam719-call-scoped-org-admin-removal@example.test',
        'IAM719 Call Scoped Org Admin Removal',
        $tenantId,
        $organizationId,
        'member',
        'admin'
    );
    $callScopedCall = videochat_create_call($pdo, $defaultOwnerUserId, [
        'title' => 'IAM719 Explicit Call Scope After Org Removal',
        'access_mode' => 'invite_only',
        'starts_at' => '2026-11-04T09:00:00Z',
        'ends_at' => '2026-11-04T10:00:00Z',
        'internal_participant_user_ids' => [$callScopedOrgAdminUserId],
        'external_participants' => [],
    ], $tenantId);
    videochat_iam719_assert((bool) ($callScopedCall['ok'] ?? false), 'call-scoped org-admin call should be created');
    $callScopedCallId = (string) (($callScopedCall['call'] ?? [])['id'] ?? '');
    $callScopedRoomId = (string) (($callScopedCall['call'] ?? [])['room_id'] ?? '');
    videochat_iam719_assert($callScopedCallId !== '' && $callScopedRoomId !== '', 'call-scoped call ids should be present');
    videochat_iam719_set_invite_state($pdo, $callScopedCallId, $callScopedOrgAdminUserId, 'allowed');
    $callScopedAuth = videochat_iam719_issue_user_session($pdo, $callScopedOrgAdminUserId, $tenantId, 'sess_iam719_call_scoped_org_admin', $label);
    $callScopedBeforeResolution = videochat_realtime_resolve_connection_rooms($callScopedAuth, $callScopedRoomId, $openDatabase, $callScopedCallId);
    videochat_iam719_assert((string) ($callScopedBeforeResolution['initial_room_id'] ?? '') === $callScopedRoomId, 'call-scoped org admin should enter invited call before removal');

    $callScopedPresence = videochat_presence_state_init();
    $callScopedConnection = videochat_iam719_connection(
        $pdo,
        $callScopedPresence,
        $callScopedRoomId,
        $callScopedCallId,
        $callScopedOrgAdminUserId,
        'IAM719 Call Scoped Org Admin Removal',
        $tenantId,
        'sess_iam719_call_scoped_org_admin',
        'call-scoped-before'
    );
    videochat_iam719_assert((string) ($callScopedConnection['active_call_id'] ?? '') === $callScopedCallId, 'call-scoped org admin should start inside call');
    videochat_iam719_assert((bool) ($callScopedConnection['can_moderate_call'] ?? false), 'call-scoped org admin should moderate before removal');

    videochat_iam719_disable_organization_membership($pdo, $tenantId, $organizationId, $callScopedOrgAdminUserId);
    $callScopedRevalidated = videochat_realtime_connection_with_call_context($callScopedConnection, $openDatabase);
    videochat_iam719_assert((string) ($callScopedRevalidated['active_call_id'] ?? '') === $callScopedCallId, 'explicitly invited removed org member should keep active call binding');
    videochat_iam719_assert((string) ($callScopedRevalidated['effective_call_role'] ?? '') === 'participant', 'removed org admin should downgrade to participant when only call scope remains');
    videochat_iam719_assert(!(bool) ($callScopedRevalidated['can_moderate_call'] ?? true), 'removed org admin must lose realtime moderator controls while staying admitted');
    videochat_iam719_assert(
        videochat_realtime_connection_can_bypass_admission_for_room($callScopedRevalidated, $callScopedRoomId, $openDatabase),
        'explicit call-scoped access should preserve room admission after org removal'
    );
    videochat_iam719_assert(
        !videochat_realtime_is_user_moderator_for_room($openDatabase, $callScopedOrgAdminUserId, 'user', $callScopedRoomId, $callScopedCallId, $tenantId),
        'explicit call-scoped removed org admin must not moderate from backend state'
    );
    $callScopedSnapshot = videochat_realtime_room_snapshot_payload($callScopedPresence, $callScopedRevalidated, $openDatabase, 'iam719_org_removed_revalidation');
    $callScopedViewer = is_array($callScopedSnapshot['viewer'] ?? null) ? $callScopedSnapshot['viewer'] : [];
    videochat_iam719_assert((string) ($callScopedViewer['call_id'] ?? '') === $callScopedCallId, 'snapshot should keep explicit call scope');
    videochat_iam719_assert((string) ($callScopedViewer['effective_call_role'] ?? '') === 'participant', 'snapshot should publish downgraded participant role');
    videochat_iam719_assert(!(bool) ($callScopedViewer['can_moderate'] ?? true), 'snapshot should remove stale org-admin controls');

    $tenantRemovedUserId = videochat_iam719_seed_user(
        $pdo,
        'iam719-tenant-removal-active-call@example.test',
        'IAM719 Tenant Removal Active Call',
        $tenantId,
        $organizationId
    );
    $tenantRemovalCall = videochat_create_call($pdo, $adminUserId, [
        'title' => 'IAM719 Tenant Membership Removal',
        'access_mode' => 'invite_only',
        'starts_at' => '2026-11-05T09:00:00Z',
        'ends_at' => '2026-11-05T10:00:00Z',
        'internal_participant_user_ids' => [$tenantRemovedUserId],
        'external_participants' => [],
    ], $tenantId);
    videochat_iam719_assert((bool) ($tenantRemovalCall['ok'] ?? false), 'tenant-removal call should be created');
    $tenantRemovalCallId = (string) (($tenantRemovalCall['call'] ?? [])['id'] ?? '');
    $tenantRemovalRoomId = (string) (($tenantRemovalCall['call'] ?? [])['room_id'] ?? '');
    videochat_iam719_assert($tenantRemovalCallId !== '' && $tenantRemovalRoomId !== '', 'tenant-removal call ids should be present');
    videochat_iam719_set_invite_state($pdo, $tenantRemovalCallId, $tenantRemovedUserId, 'allowed');
    videochat_iam719_issue_user_session($pdo, $tenantRemovedUserId, $tenantId, 'sess_iam719_tenant_removed_active_call', $label);

    $tenantRemovalPresence = videochat_presence_state_init();
    $tenantRemovalConnection = videochat_iam719_connection(
        $pdo,
        $tenantRemovalPresence,
        $tenantRemovalRoomId,
        $tenantRemovalCallId,
        $tenantRemovedUserId,
        'IAM719 Tenant Removal Active Call',
        $tenantId,
        'sess_iam719_tenant_removed_active_call',
        'tenant-member-before'
    );
    videochat_iam719_assert((string) ($tenantRemovalConnection['active_call_id'] ?? '') === $tenantRemovalCallId, 'tenant member should be active in call before removal');

    videochat_iam719_disable_tenant_membership($pdo, $tenantId, $tenantRemovedUserId);
    $livenessAfterRemoval = videochat_realtime_validate_session_liveness(
        static fn (array $request, string $transport): array => videochat_authenticate_request($pdo, $request, $transport),
        'sess_iam719_tenant_removed_active_call',
        '/ws'
    );
    videochat_iam719_assert(!(bool) ($livenessAfterRemoval['ok'] ?? true), 'tenant membership removal should fail active websocket liveness');
    videochat_iam719_assert((string) ($livenessAfterRemoval['reason'] ?? '') === 'tenant_membership_inactive', 'tenant removal liveness reason mismatch');
    $livenessPolicy = videochat_realtime_session_liveness_failure_policy((string) ($livenessAfterRemoval['reason'] ?? ''), 1, 0, 5000);
    videochat_iam719_assert((bool) ($livenessPolicy['close'] ?? false), 'tenant removal liveness failure should close websocket');
    videochat_iam719_assert((int) (($livenessPolicy['close_descriptor'] ?? [])['close_code'] ?? 0) === 1008, 'tenant removal close code should be policy violation');

    $pdo->prepare('DELETE FROM sessions WHERE id = :session_id')->execute([':session_id' => 'sess_iam719_tenant_removed_active_call']);
    $cachedAuthAfterRemoval = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/api/auth/session',
            'headers' => ['Authorization' => 'Bearer sess_iam719_tenant_removed_active_call'],
        ],
        'http'
    );
    videochat_iam719_assert(!(bool) ($cachedAuthAfterRemoval['ok'] ?? true), 'cached stale tenant token must not survive membership removal');
    videochat_iam719_assert((string) ($cachedAuthAfterRemoval['reason'] ?? '') === 'tenant_membership_inactive', 'cached stale tenant denial reason mismatch');

    @unlink($databasePath);
    fwrite(STDOUT, "[{$label}] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[{$label}] ERROR: " . $error->getMessage() . "\n");
    fwrite(STDERR, $error->getTraceAsString() . "\n");
    exit(1);
} finally {
    if (is_string($databasePath) && is_file($databasePath)) {
        @unlink($databasePath);
    }
}
