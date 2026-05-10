<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/realtime/realtime_presence.php';
require_once __DIR__ . '/../http/module_realtime.php';

$label = 'call-access-authorized-rejoin-contract';

function videochat_authorized_rejoin_assert(bool $condition, string $message, string $label): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[{$label}] FAIL: {$message}\n");
    exit(1);
}

/**
 * @return array{0: string, 1: PDO}
 */
function videochat_authorized_rejoin_bootstrap_database(string $label): array
{
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[{$label}] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-call-access-authorized-rejoin-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);
    videochat_bootstrap_sqlite($databasePath);

    return [$databasePath, videochat_open_sqlite_pdo($databasePath)];
}

/**
 * @return array{tenant_id: int, organization_id: int, system_admin_user_id: int}
 */
function videochat_authorized_rejoin_fixture_ids(PDO $pdo, string $label): array
{
    $tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    $organizationId = (int) $pdo->query("SELECT id FROM organizations WHERE tenant_id = {$tenantId} ORDER BY id ASC LIMIT 1")->fetchColumn();
    $systemAdminUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();

    videochat_authorized_rejoin_assert($tenantId > 0, 'expected default tenant', $label);
    videochat_authorized_rejoin_assert($organizationId > 0, 'expected seeded organization', $label);
    videochat_authorized_rejoin_assert($systemAdminUserId > 0, 'expected seeded system admin user', $label);

    return [
        'tenant_id' => $tenantId,
        'organization_id' => $organizationId,
        'system_admin_user_id' => $systemAdminUserId,
    ];
}

function videochat_authorized_rejoin_seed_user(
    PDO $pdo,
    string $email,
    string $displayName,
    int $tenantId,
    int $organizationId,
    string $organizationRole = 'member'
): int {
    $roleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'user' LIMIT 1")->fetchColumn();
    if ($roleId <= 0) {
        throw new RuntimeException('expected user role fixture');
    }

    $now = gmdate('c');
    $insertUser = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, date_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', '24h', 'dmy_dot', 'dark', :updated_at)
SQL
    );
    $insertUser->execute([
        ':email' => strtolower($email),
        ':display_name' => $displayName,
        ':password_hash' => password_hash('authorized-rejoin-contract', PASSWORD_DEFAULT),
        ':role_id' => $roleId,
        ':updated_at' => $now,
    ]);
    $userId = (int) $pdo->lastInsertId();
    if ($userId <= 0) {
        throw new RuntimeException('seeded user id must be positive');
    }

    $tenantInsert = $pdo->prepare(
        <<<'SQL'
INSERT INTO tenant_memberships(tenant_id, user_id, membership_role, status, permissions_json, default_membership, created_at, updated_at)
VALUES(:tenant_id, :user_id, 'member', 'active', '{}', 1, :created_at, :updated_at)
SQL
    );
    $tenantInsert->execute([
        ':tenant_id' => $tenantId,
        ':user_id' => $userId,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    $organizationInsert = $pdo->prepare(
        <<<'SQL'
INSERT INTO organization_memberships(tenant_id, organization_id, user_id, membership_role, status, created_at, updated_at)
VALUES(:tenant_id, :organization_id, :user_id, :membership_role, 'active', :created_at, :updated_at)
SQL
    );
    $organizationInsert->execute([
        ':tenant_id' => $tenantId,
        ':organization_id' => $organizationId,
        ':user_id' => $userId,
        ':membership_role' => strtolower(trim($organizationRole)) === 'admin' ? 'admin' : 'member',
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    return $userId;
}

/**
 * @param array<int, int> $participantUserIds
 * @return array{call_id: string, room_id: string}
 */
function videochat_authorized_rejoin_create_call(
    PDO $pdo,
    int $ownerUserId,
    array $participantUserIds,
    int $tenantId,
    string $title
): array {
    $created = videochat_create_call($pdo, $ownerUserId, [
        'title' => $title,
        'access_mode' => 'invite_only',
        'starts_at' => gmdate('c', time() - 60),
        'ends_at' => gmdate('c', time() + 3600),
        'internal_participant_user_ids' => $participantUserIds,
        'external_participants' => [],
    ], $tenantId);
    if (!(bool) ($created['ok'] ?? false)) {
        throw new RuntimeException('could not create call: ' . (string) ($created['reason'] ?? 'unknown'));
    }

    $callId = (string) (($created['call'] ?? [])['id'] ?? '');
    $roomId = (string) (($created['call'] ?? [])['room_id'] ?? '');
    if ($callId === '' || $roomId === '') {
        throw new RuntimeException('created call is missing ids');
    }

    return ['call_id' => $callId, 'room_id' => $roomId];
}

function videochat_authorized_rejoin_set_invite_state(PDO $pdo, string $callId, int $userId, string $state): void
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
        ':invite_state' => $state,
        ':call_id' => $callId,
        ':user_id' => $userId,
    ]);
}

function videochat_authorized_rejoin_issue_auth(PDO $pdo, int $userId, int $tenantId, string $sessionId, string $label): array
{
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
    videochat_authorized_rejoin_assert((bool) ($session['ok'] ?? false), 'expected user session issue to succeed', $label);

    $auth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/ws?session=' . rawurlencode($sessionId),
            'headers' => ['Authorization' => 'Bearer ' . $sessionId],
        ],
        'websocket'
    );
    videochat_authorized_rejoin_assert((bool) ($auth['ok'] ?? false), 'expected issued session to authenticate', $label);

    return $auth;
}

function videochat_authorized_rejoin_assert_direct_join(
    PDO $pdo,
    string $callId,
    int $userId,
    string $authRole,
    int $tenantId,
    string $expectedReason,
    string $caseKey,
    string $label
): void {
    $decision = videochat_user_can_direct_join_call($pdo, $callId, $userId, $authRole, $tenantId);
    videochat_authorized_rejoin_assert((bool) ($decision['ok'] ?? false), "{$caseKey}: direct join should allow the authorized user", $label);
    videochat_authorized_rejoin_assert((string) ($decision['reason'] ?? '') === $expectedReason, "{$caseKey}: direct join reason mismatch", $label);
    videochat_authorized_rejoin_assert((string) ($decision['call_id'] ?? '') === $callId, "{$caseKey}: direct join call id mismatch", $label);
}

function videochat_authorized_rejoin_assert_direct_join_denied(
    PDO $pdo,
    string $callId,
    int $userId,
    string $authRole,
    int $tenantId,
    string $expectedReason,
    string $caseKey,
    string $label
): void {
    $decision = videochat_user_can_direct_join_call($pdo, $callId, $userId, $authRole, $tenantId);
    videochat_authorized_rejoin_assert(!(bool) ($decision['ok'] ?? true), "{$caseKey}: direct join should be denied", $label);
    videochat_authorized_rejoin_assert((string) ($decision['reason'] ?? '') === $expectedReason, "{$caseKey}: direct join denial reason mismatch", $label);
}

function videochat_authorized_rejoin_participant_left_at(PDO $pdo, string $callId, int $userId): string
{
    $statement = $pdo->prepare('SELECT left_at FROM call_participants WHERE call_id = :call_id AND user_id = :user_id LIMIT 1');
    $statement->execute([':call_id' => $callId, ':user_id' => $userId]);
    return trim((string) ($statement->fetchColumn() ?: ''));
}

function videochat_authorized_rejoin_connection(
    PDO $pdo,
    array &$presenceState,
    string $roomId,
    string $callId,
    int $userId,
    string $displayName,
    string $globalRole,
    string $suffix,
    int $tenantId,
    string $sessionId
): array {
    $connection = videochat_presence_connection_descriptor(
        [
            'id' => $userId,
            'display_name' => $displayName,
            'role' => $globalRole,
            'tenant' => ['id' => $tenantId],
        ],
        $sessionId,
        'conn-' . $suffix,
        'socket-' . $suffix,
        $roomId
    );
    $connection['tenant_id'] = $tenantId;
    $connection['requested_call_id'] = $callId;
    $connection = videochat_realtime_connection_with_call_context($connection, static fn (): PDO => $pdo);
    $joined = videochat_presence_join_room($presenceState, $connection, $roomId);
    $connection = (array) ($joined['connection'] ?? $connection);
    videochat_realtime_mark_call_participant_joined(static fn (): PDO => $pdo, $connection);

    return $connection;
}

function videochat_authorized_rejoin_assert_room_resolution(
    PDO $pdo,
    array $auth,
    string $roomId,
    string $callId,
    string $caseKey,
    string $label
): void {
    $resolution = videochat_realtime_resolve_connection_rooms($auth, $roomId, static fn (): PDO => $pdo, $callId);
    videochat_authorized_rejoin_assert((bool) ($resolution['ok'] ?? false), "{$caseKey}: websocket room resolution should succeed", $label);
    videochat_authorized_rejoin_assert((string) ($resolution['initial_room_id'] ?? '') === $roomId, "{$caseKey}: authorized rejoin should enter the call room directly", $label);
    videochat_authorized_rejoin_assert((string) ($resolution['pending_room_id'] ?? '') === '', "{$caseKey}: authorized rejoin must not return to lobby", $label);
}

function videochat_authorized_rejoin_assert_leave_and_rejoin(
    PDO $pdo,
    string $roomId,
    string $callId,
    int $tenantId,
    int $userId,
    string $displayName,
    string $authRole,
    string $sessionId,
    string $expectedEffectiveRole,
    bool $expectedModeration,
    string $caseKey,
    string $label
): void {
    $presenceState = videochat_presence_state_init();
    $auth = videochat_authorized_rejoin_issue_auth($pdo, $userId, $tenantId, $sessionId, $label);

    videochat_authorized_rejoin_assert_room_resolution($pdo, $auth, $roomId, $callId, "{$caseKey}: initial join", $label);
    $connection = videochat_authorized_rejoin_connection(
        $pdo,
        $presenceState,
        $roomId,
        $callId,
        $userId,
        $displayName,
        $authRole,
        $caseKey . '-before-leave',
        $tenantId,
        $sessionId
    );
    videochat_authorized_rejoin_assert((string) ($connection['active_call_id'] ?? '') === $callId, "{$caseKey}: joined connection should bind the call", $label);
    videochat_authorized_rejoin_assert((string) ($connection['effective_call_role'] ?? '') === $expectedEffectiveRole, "{$caseKey}: effective call role mismatch before leave", $label);
    videochat_authorized_rejoin_assert((bool) ($connection['can_moderate_call'] ?? false) === $expectedModeration, "{$caseKey}: moderation capability mismatch before leave", $label);
    videochat_authorized_rejoin_assert(videochat_authorized_rejoin_participant_left_at($pdo, $callId, $userId) === '', "{$caseKey}: joined participant should not have left_at", $label);

    videochat_presence_remove_connection($presenceState, (string) ($connection['connection_id'] ?? ''), static fn (): bool => true);
    videochat_realtime_remove_call_presence(static fn (): PDO => $pdo, $connection);
    videochat_realtime_mark_call_participant_left(static fn (): PDO => $pdo, $connection, $presenceState);
    videochat_authorized_rejoin_assert(videochat_authorized_rejoin_participant_left_at($pdo, $callId, $userId) !== '', "{$caseKey}: leave should persist left_at", $label);

    videochat_authorized_rejoin_assert_room_resolution($pdo, $auth, $roomId, $callId, "{$caseKey}: rejoin after leave", $label);
    $rejoinConnection = videochat_authorized_rejoin_connection(
        $pdo,
        $presenceState,
        $roomId,
        $callId,
        $userId,
        $displayName,
        $authRole,
        $caseKey . '-after-rejoin',
        $tenantId,
        $sessionId
    );
    videochat_authorized_rejoin_assert((string) ($rejoinConnection['active_call_id'] ?? '') === $callId, "{$caseKey}: rejoined connection should bind the call", $label);
    videochat_authorized_rejoin_assert((string) ($rejoinConnection['effective_call_role'] ?? '') === $expectedEffectiveRole, "{$caseKey}: effective call role mismatch after rejoin", $label);
    videochat_authorized_rejoin_assert((bool) ($rejoinConnection['can_moderate_call'] ?? false) === $expectedModeration, "{$caseKey}: moderation capability mismatch after rejoin", $label);
    videochat_authorized_rejoin_assert(videochat_authorized_rejoin_participant_left_at($pdo, $callId, $userId) === '', "{$caseKey}: rejoin should clear stale left_at", $label);
}

try {
    [$databasePath, $pdo] = videochat_authorized_rejoin_bootstrap_database($label);
    $fixtureIds = videochat_authorized_rejoin_fixture_ids($pdo, $label);
    $tenantId = $fixtureIds['tenant_id'];
    $organizationId = $fixtureIds['organization_id'];
    $systemAdminUserId = $fixtureIds['system_admin_user_id'];

    $ownerUserId = videochat_authorized_rejoin_seed_user($pdo, 'iam-authorized-rejoin-owner@example.test', 'IAM Authorized Rejoin Owner', $tenantId, $organizationId);
    $registeredUserId = videochat_authorized_rejoin_seed_user($pdo, 'iam-authorized-rejoin-registered@example.test', 'IAM Authorized Rejoin Registered', $tenantId, $organizationId);
    $organizationAdminUserId = videochat_authorized_rejoin_seed_user($pdo, 'iam-authorized-rejoin-org-admin@example.test', 'IAM Authorized Rejoin Org Admin', $tenantId, $organizationId, 'admin');
    $normalUserId = videochat_authorized_rejoin_seed_user($pdo, 'iam-authorized-rejoin-normal@example.test', 'IAM Authorized Rejoin Normal', $tenantId, $organizationId);

    $guestListCall = videochat_authorized_rejoin_create_call($pdo, $ownerUserId, [$registeredUserId], $tenantId, 'Authorized Rejoin Guest List');
    videochat_authorized_rejoin_set_invite_state($pdo, $guestListCall['call_id'], $registeredUserId, 'allowed');
    videochat_authorized_rejoin_assert_direct_join($pdo, $guestListCall['call_id'], $registeredUserId, 'user', $tenantId, 'guest_list', 'registered_guest_can_rejoin_after_leaving', $label);
    videochat_authorized_rejoin_assert_leave_and_rejoin(
        $pdo,
        $guestListCall['room_id'],
        $guestListCall['call_id'],
        $tenantId,
        $registeredUserId,
        'IAM Authorized Rejoin Registered',
        'user',
        'sess_iam_authorized_rejoin_registered',
        'participant',
        false,
        'registered_guest_can_rejoin_after_leaving',
        $label
    );

    $adminCall = videochat_authorized_rejoin_create_call($pdo, $ownerUserId, [], $tenantId, 'Authorized Rejoin System Admin');
    videochat_authorized_rejoin_assert_direct_join($pdo, $adminCall['call_id'], $systemAdminUserId, 'admin', $tenantId, 'system_admin', 'system_admin_can_rejoin_after_leaving', $label);
    videochat_authorized_rejoin_assert_direct_join_denied($pdo, $adminCall['call_id'], $normalUserId, 'admin', $tenantId, 'not_on_guest_list', 'forged_admin_role_does_not_rejoin', $label);
    videochat_authorized_rejoin_assert_leave_and_rejoin(
        $pdo,
        $adminCall['room_id'],
        $adminCall['call_id'],
        $tenantId,
        $systemAdminUserId,
        'Call System Admin',
        'admin',
        'sess_iam_authorized_rejoin_admin',
        'owner',
        true,
        'system_admin_can_rejoin_after_leaving',
        $label
    );

    $organizationAdminCall = videochat_authorized_rejoin_create_call($pdo, $ownerUserId, [], $tenantId, 'Authorized Rejoin Org Admin');
    videochat_authorized_rejoin_assert_direct_join($pdo, $organizationAdminCall['call_id'], $organizationAdminUserId, 'user', $tenantId, 'organization_admin', 'organization_admin_can_rejoin_after_leaving', $label);
    videochat_authorized_rejoin_assert_leave_and_rejoin(
        $pdo,
        $organizationAdminCall['room_id'],
        $organizationAdminCall['call_id'],
        $tenantId,
        $organizationAdminUserId,
        'IAM Authorized Rejoin Org Admin',
        'user',
        'sess_iam_authorized_rejoin_org_admin',
        'moderator',
        true,
        'organization_admin_can_rejoin_after_leaving',
        $label
    );

    $pendingCall = videochat_authorized_rejoin_create_call($pdo, $ownerUserId, [$normalUserId], $tenantId, 'Authorized Rejoin Pending Guard');
    videochat_authorized_rejoin_set_invite_state($pdo, $pendingCall['call_id'], $normalUserId, 'pending');
    videochat_authorized_rejoin_assert_direct_join_denied($pdo, $pendingCall['call_id'], $normalUserId, 'user', $tenantId, 'not_on_guest_list', 'pending_lobby_user_must_not_bypass_admission', $label);

    @unlink($databasePath);
    fwrite(STDOUT, "[{$label}] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[{$label}] ERROR: " . $error->getMessage() . "\n");
    fwrite(STDERR, $error->getTraceAsString() . "\n");
    exit(1);
} finally {
    if (isset($databasePath) && is_string($databasePath) && is_file($databasePath)) {
        @unlink($databasePath);
    }
}
