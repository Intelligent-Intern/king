<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../domain/realtime/realtime_lobby.php';
require_once __DIR__ . '/../http/module_realtime.php';

function videochat_iam_anonymous_lobby_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-access-anonymous-lobby-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_iam_anonymous_lobby_role_id(PDO $pdo, string $role): int
{
    $query = $pdo->prepare('SELECT id FROM roles WHERE slug = :slug LIMIT 1');
    $query->execute([':slug' => $role]);
    return (int) $query->fetchColumn();
}

function videochat_iam_anonymous_lobby_create_user(PDO $pdo, int $roleId, string $email, string $displayName): int
{
    $passwordHash = password_hash('iam-anonymous-lobby-contract', PASSWORD_DEFAULT);
    videochat_iam_anonymous_lobby_assert(is_string($passwordHash) && $passwordHash !== '', 'password hash should be available');

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
        ':role_id' => $roleId,
        ':updated_at' => gmdate('c'),
    ]);

    $userId = (int) $pdo->lastInsertId();
    videochat_iam_anonymous_lobby_assert($userId > 0, "{$displayName} should be created");
    return $userId;
}

function videochat_iam_anonymous_lobby_create_tenant(PDO $pdo, string $unique): int
{
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO tenants(public_id, slug, label, status, created_at, updated_at)
VALUES(:public_id, :slug, :label, 'active', :created_at, :updated_at)
SQL
    );
    $insert->execute([
        ':public_id' => "tenant-iam-anon-lobby-{$unique}",
        ':slug' => "iam-anon-lobby-{$unique}",
        ':label' => "IAM Anonymous Lobby {$unique}",
        ':created_at' => gmdate('c'),
        ':updated_at' => gmdate('c'),
    ]);

    $tenantId = (int) $pdo->lastInsertId();
    videochat_iam_anonymous_lobby_assert($tenantId > 0, 'tenant should be created');
    return $tenantId;
}

function videochat_iam_anonymous_lobby_create_organization(PDO $pdo, int $tenantId, string $unique): int
{
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO organizations(tenant_id, public_id, name, status, created_at, updated_at)
VALUES(:tenant_id, :public_id, :name, 'active', :created_at, :updated_at)
SQL
    );
    $insert->execute([
        ':tenant_id' => $tenantId,
        ':public_id' => "org-iam-anon-lobby-{$unique}",
        ':name' => 'IAM Anonymous Lobby Org',
        ':created_at' => gmdate('c'),
        ':updated_at' => gmdate('c'),
    ]);

    $organizationId = (int) $pdo->lastInsertId();
    videochat_iam_anonymous_lobby_assert($organizationId > 0, 'organization should be created');
    return $organizationId;
}

function videochat_iam_anonymous_lobby_attach_user(
    PDO $pdo,
    int $tenantId,
    int $organizationId,
    int $userId,
    ?string $membershipRole = null
): void {
    videochat_tenant_attach_user($pdo, $userId, $tenantId);
    if ($membershipRole === null) {
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
        ':membership_role' => $membershipRole,
        ':created_at' => gmdate('c'),
        ':updated_at' => gmdate('c'),
    ]);
}

function videochat_iam_anonymous_lobby_issue_session_id(string $id): callable
{
    return static fn (): string => $id;
}

function videochat_iam_anonymous_lobby_issue_user_session(PDO $pdo, int $userId, string $sessionId, int $tenantId): string
{
    $issued = videochat_issue_session_for_user(
        $pdo,
        $userId,
        videochat_iam_anonymous_lobby_issue_session_id($sessionId),
        43_200,
        '127.0.0.1',
        'call-access-anonymous-lobby-contract',
        null,
        $tenantId
    );
    videochat_iam_anonymous_lobby_assert((bool) ($issued['ok'] ?? false), "user session {$sessionId} should issue");

    return (string) (($issued['session'] ?? [])['id'] ?? $sessionId);
}

function videochat_iam_anonymous_lobby_auth(PDO $pdo, string $sessionId): array
{
    $auth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/ws?session=' . rawurlencode($sessionId),
            'headers' => ['Authorization' => 'Bearer ' . $sessionId],
        ],
        'websocket'
    );
    videochat_iam_anonymous_lobby_assert((bool) ($auth['ok'] ?? false), "session {$sessionId} should authenticate");

    return $auth;
}

/**
 * @param array<int, int> $internalParticipantUserIds
 */
function videochat_iam_anonymous_lobby_create_call(
    PDO $pdo,
    int $ownerUserId,
    int $tenantId,
    string $title,
    string $accessMode,
    array $internalParticipantUserIds = []
): string
{
    $created = videochat_create_call($pdo, $ownerUserId, [
        'title' => $title,
        'access_mode' => $accessMode,
        'starts_at' => gmdate('c', time() - 300),
        'ends_at' => gmdate('c', time() + 3600),
        'internal_participant_user_ids' => $internalParticipantUserIds,
        'external_participants' => [],
    ], $tenantId);
    videochat_iam_anonymous_lobby_assert((bool) ($created['ok'] ?? false), "{$title} should be created");

    $callId = (string) (($created['call'] ?? [])['id'] ?? '');
    videochat_iam_anonymous_lobby_assert($callId !== '', "{$title} id should be present");
    return $callId;
}

function videochat_iam_anonymous_lobby_insert_open_link(PDO $pdo, int $tenantId, string $callId, int $createdByUserId): string
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

function videochat_iam_anonymous_lobby_participant(PDO $pdo, string $callId, int $userId): ?array
{
    $query = $pdo->prepare(
        <<<'SQL'
SELECT user_id, email, display_name, source, call_role, invite_state, joined_at, left_at
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

function videochat_iam_anonymous_lobby_assert_invite_state(PDO $pdo, string $callId, int $userId, string $state, string $label): void
{
    $participant = videochat_iam_anonymous_lobby_participant($pdo, $callId, $userId);
    videochat_iam_anonymous_lobby_assert(is_array($participant), "{$label}: participant row should exist");
    videochat_iam_anonymous_lobby_assert((string) ($participant['invite_state'] ?? '') === $state, "{$label}: invite state should be {$state}");
}

function videochat_iam_anonymous_lobby_assert_waiting(
    PDO $pdo,
    callable $openDatabase,
    string $sessionId,
    string $callId,
    string $label
): array {
    $auth = videochat_iam_anonymous_lobby_auth($pdo, $sessionId);
    $resolution = videochat_realtime_resolve_connection_rooms($auth, $callId, $openDatabase, $callId);
    videochat_iam_anonymous_lobby_assert((bool) ($resolution['ok'] ?? false), "{$label}: room resolution should succeed");
    videochat_iam_anonymous_lobby_assert((string) ($resolution['initial_room_id'] ?? '') === videochat_realtime_waiting_room_id(), "{$label}: should start in waiting room");
    videochat_iam_anonymous_lobby_assert((string) ($resolution['requested_room_id'] ?? '') === $callId, "{$label}: requested room should stay bound to call");
    videochat_iam_anonymous_lobby_assert((string) ($resolution['pending_room_id'] ?? '') === $callId, "{$label}: pending room should stay bound to call");

    return $auth;
}

function videochat_iam_anonymous_lobby_assert_direct(
    PDO $pdo,
    callable $openDatabase,
    string $sessionId,
    string $callId,
    string $label
): void {
    $auth = videochat_iam_anonymous_lobby_auth($pdo, $sessionId);
    $resolution = videochat_realtime_resolve_connection_rooms($auth, $callId, $openDatabase, $callId);
    videochat_iam_anonymous_lobby_assert((bool) ($resolution['ok'] ?? false), "{$label}: room resolution should succeed");
    videochat_iam_anonymous_lobby_assert((string) ($resolution['initial_room_id'] ?? '') === $callId, "{$label}: should enter bound room");
    videochat_iam_anonymous_lobby_assert((string) ($resolution['pending_room_id'] ?? '') === '', "{$label}: should not wait for admission");
}

function videochat_iam_anonymous_lobby_assert_rejected_session(
    PDO $pdo,
    string $sessionId,
    int $userId,
    string $label
): void {
    $binding = videochat_validate_call_access_session_binding($pdo, $sessionId, $userId);
    videochat_iam_anonymous_lobby_assert((bool) ($binding['is_call_access_session'] ?? false), "{$label}: binding should remain auditable");
    videochat_iam_anonymous_lobby_assert(!(bool) ($binding['ok'] ?? true), "{$label}: rejected session should fail closed");
    videochat_iam_anonymous_lobby_assert(
        (string) ($binding['reason'] ?? '') === 'call_access_participant_removed',
        "{$label}: rejected session reason mismatch"
    );

    $auth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/ws?session=' . rawurlencode($sessionId),
            'headers' => ['Authorization' => 'Bearer ' . $sessionId],
        ],
        'websocket'
    );
    videochat_iam_anonymous_lobby_assert(!(bool) ($auth['ok'] ?? true), "{$label}: rejected session must not authenticate");
}

function videochat_iam_anonymous_lobby_connection(
    array &$presenceState,
    PDO $pdo,
    callable $openDatabase,
    string $sessionId,
    string $callId,
    string $connectionId
): array {
    $auth = videochat_iam_anonymous_lobby_auth($pdo, $sessionId);
    $resolution = videochat_realtime_resolve_connection_rooms($auth, $callId, $openDatabase, $callId);
    videochat_iam_anonymous_lobby_assert((bool) ($resolution['ok'] ?? false), "{$connectionId}: room resolution should succeed");

    $connection = videochat_presence_connection_descriptor(
        (array) ($auth['user'] ?? []),
        $sessionId,
        $connectionId,
        'socket_' . $connectionId,
        (string) ($resolution['initial_room_id'] ?? videochat_realtime_waiting_room_id())
    );
    $connection['requested_room_id'] = (string) ($resolution['requested_room_id'] ?? '');
    $connection['pending_room_id'] = (string) ($resolution['pending_room_id'] ?? '');
    $connection['requested_call_id'] = $callId;
    $connection = videochat_realtime_connection_with_call_context($connection, $openDatabase);

    $join = videochat_presence_join_room($presenceState, $connection, (string) ($connection['room_id'] ?? 'lobby'));
    $connection = (array) ($join['connection'] ?? $connection);
    $connection = videochat_realtime_connection_with_call_context($connection, $openDatabase);
    $presenceState['connections'][(string) ($connection['connection_id'] ?? $connectionId)] = $connection;

    return $connection;
}

function videochat_iam_anonymous_lobby_command(
    array &$lobbyState,
    array &$presenceState,
    array $connection,
    callable $openDatabase,
    array $payload,
    string $label
): void {
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
    videochat_iam_anonymous_lobby_assert(is_string($encoded), "{$label}: lobby command should encode");
    $command = videochat_lobby_decode_client_frame($encoded);
    videochat_iam_anonymous_lobby_assert((bool) ($command['ok'] ?? false), "{$label}: lobby command should decode");
    $handled = videochat_realtime_handle_lobby_websocket_command(
        $command,
        $connection['socket'] ?? null,
        $lobbyState,
        $presenceState,
        $connection,
        $openDatabase
    );
    videochat_iam_anonymous_lobby_assert(is_array($handled), "{$label}: lobby command should be handled");
}

function videochat_iam_anonymous_lobby_queue(
    PDO $pdo,
    callable $openDatabase,
    array &$presenceState,
    array &$lobbyState,
    string $sessionId,
    string $callId,
    string $label
): array {
    $connection = videochat_iam_anonymous_lobby_connection($presenceState, $pdo, $openDatabase, $sessionId, $callId, 'conn_' . $label);
    videochat_iam_anonymous_lobby_command(
        $lobbyState,
        $presenceState,
        $connection,
        $openDatabase,
        ['type' => 'lobby/queue/join', 'room_id' => $callId],
        "{$label}: queue"
    );
    videochat_iam_anonymous_lobby_assert_invite_state($pdo, $callId, (int) ($connection['user_id'] ?? 0), 'pending', "{$label}: queued participant");

    return $connection;
}

function videochat_iam_anonymous_lobby_admit(
    PDO $pdo,
    callable $openDatabase,
    array &$presenceState,
    array &$lobbyState,
    array $moderatorConnection,
    string $callId,
    int $targetUserId,
    string $label
): void {
    videochat_iam_anonymous_lobby_command(
        $lobbyState,
        $presenceState,
        $moderatorConnection,
        $openDatabase,
        ['type' => 'lobby/allow', 'room_id' => $callId, 'target_user_id' => $targetUserId],
        $label
    );
    videochat_iam_anonymous_lobby_assert_invite_state($pdo, $callId, $targetUserId, 'allowed', $label);
}

function videochat_iam_anonymous_lobby_reject(
    PDO $pdo,
    callable $openDatabase,
    array &$presenceState,
    array &$lobbyState,
    array $moderatorConnection,
    string $callId,
    int $targetUserId,
    string $label
): void {
    videochat_iam_anonymous_lobby_command(
        $lobbyState,
        $presenceState,
        $moderatorConnection,
        $openDatabase,
        ['type' => 'lobby/reject', 'room_id' => $callId, 'target_user_id' => $targetUserId],
        $label
    );
    videochat_iam_anonymous_lobby_assert_invite_state($pdo, $callId, $targetUserId, 'cancelled', $label);
}

function videochat_iam_anonymous_lobby_assert_visible_snapshot(
    array $lobbyState,
    array $moderatorConnection,
    string $callId,
    int $targetUserId,
    string $label
): void {
    $snapshot = videochat_lobby_snapshot_payload_for_connection(
        videochat_lobby_snapshot_payload($lobbyState, $callId, $label),
        $moderatorConnection
    );
    videochat_iam_anonymous_lobby_assert((int) ($snapshot['queue_count'] ?? 0) === 1, "{$label}: moderator should see one waiting participant");
    videochat_iam_anonymous_lobby_assert(
        (int) ((($snapshot['queue'] ?? [])[0] ?? [])['user_id'] ?? 0) === $targetUserId,
        "{$label}: moderator snapshot should expose waiting participant"
    );
}

function videochat_iam_anonymous_lobby_assert_redacted_controls(
    array $lobbyState,
    array $viewerConnection,
    string $callId,
    int $otherUserId,
    string $label
): void {
    $snapshot = videochat_lobby_snapshot_payload_for_connection(
        videochat_lobby_snapshot_payload($lobbyState, $callId, $label),
        $viewerConnection
    );
    $viewerUserId = (int) ($viewerConnection['user_id'] ?? 0);
    videochat_iam_anonymous_lobby_assert((int) ($snapshot['queue_count'] ?? -1) === 1, "{$label}: unauthorized viewer should see only their own lobby row");
    videochat_iam_anonymous_lobby_assert(
        (int) ((($snapshot['queue'] ?? [])[0] ?? [])['user_id'] ?? 0) === $viewerUserId,
        "{$label}: unauthorized viewer own row mismatch"
    );
    foreach ((array) ($snapshot['queue'] ?? []) as $entry) {
        videochat_iam_anonymous_lobby_assert((int) ($entry['user_id'] ?? 0) !== $otherUserId, "{$label}: unauthorized snapshot leaked another waiting user");
    }
}

function videochat_iam_anonymous_lobby_issue_open_session(
    PDO $pdo,
    string $accessId,
    string $sessionId,
    string $guestName,
    array $options = []
): array {
    $session = videochat_issue_session_for_call_access(
        $pdo,
        $accessId,
        videochat_iam_anonymous_lobby_issue_session_id($sessionId),
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-access-anonymous-lobby-contract'],
        ['guest_name' => $guestName] + $options
    );
    videochat_iam_anonymous_lobby_assert((bool) ($session['ok'] ?? false), "{$sessionId} should issue");

    return $session;
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-access-anonymous-lobby-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-call-access-anonymous-lobby-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);
    $openDatabase = static function () use ($pdo): PDO {
        return $pdo;
    };

    $userRoleId = videochat_iam_anonymous_lobby_role_id($pdo, 'user');
    $adminRoleId = videochat_iam_anonymous_lobby_role_id($pdo, 'admin');
    videochat_iam_anonymous_lobby_assert($userRoleId > 0 && $adminRoleId > 0, 'expected user and admin roles');

    $unique = bin2hex(random_bytes(5));
    $tenantId = videochat_iam_anonymous_lobby_create_tenant($pdo, $unique);
    $organizationId = videochat_iam_anonymous_lobby_create_organization($pdo, $tenantId, $unique);
    $ownerUserId = videochat_iam_anonymous_lobby_create_user($pdo, $userRoleId, "iam-anon-owner-{$unique}@example.test", 'IAM Anonymous Owner');
    $tempModeratorUserId = videochat_iam_anonymous_lobby_create_user($pdo, $userRoleId, "iam-anon-temp-mod-{$unique}@example.test", 'IAM Anonymous Temp Moderator');
    $orgAdminUserId = videochat_iam_anonymous_lobby_create_user($pdo, $userRoleId, "iam-anon-org-admin-{$unique}@example.test", 'IAM Anonymous Org Admin');
    $systemAdminUserId = videochat_iam_anonymous_lobby_create_user($pdo, $adminRoleId, "iam-anon-system-admin-{$unique}@example.test", 'IAM Anonymous System Admin');
    $accountUserId = videochat_iam_anonymous_lobby_create_user($pdo, $userRoleId, "iam-anon-account-{$unique}@example.test", 'IAM Anonymous Account');

    videochat_iam_anonymous_lobby_attach_user($pdo, $tenantId, $organizationId, $ownerUserId, 'member');
    videochat_iam_anonymous_lobby_attach_user($pdo, $tenantId, $organizationId, $tempModeratorUserId, 'member');
    videochat_iam_anonymous_lobby_attach_user($pdo, $tenantId, $organizationId, $orgAdminUserId, 'admin');
    videochat_iam_anonymous_lobby_attach_user($pdo, $tenantId, $organizationId, $accountUserId, 'member');
    videochat_iam_anonymous_lobby_attach_user($pdo, $tenantId, $organizationId, $systemAdminUserId);

    $freeForAllCallId = videochat_iam_anonymous_lobby_create_call($pdo, $ownerUserId, $tenantId, 'IAM Anonymous FFA Open Link', 'free_for_all');
    $freeForAllOpenLink = videochat_create_call_access_link_for_user($pdo, $freeForAllCallId, $ownerUserId, 'user', [
        'link_kind' => 'open',
    ], $tenantId);
    videochat_iam_anonymous_lobby_assert((bool) ($freeForAllOpenLink['ok'] ?? false), 'FFA open link should be created through current API');
    $freeForAllAccessId = (string) (($freeForAllOpenLink['access_link'] ?? [])['id'] ?? '');
    videochat_iam_anonymous_lobby_assert($freeForAllAccessId !== '', 'FFA open access id should be present');

    $accountLoginSessionId = videochat_iam_anonymous_lobby_issue_user_session($pdo, $accountUserId, 'sess_iam_anon_lobby_account_login', $tenantId);
    $freeForAllSession = videochat_iam_anonymous_lobby_issue_open_session(
        $pdo,
        $freeForAllAccessId,
        'sess_iam_anon_lobby_ffa_open',
        'Logged In FFA Guest',
        ['authenticated_user_id' => $accountUserId, 'authenticated_session_id' => $accountLoginSessionId]
    );
    $freeForAllGuestId = (int) (($freeForAllSession['user'] ?? [])['id'] ?? 0);
    videochat_iam_anonymous_lobby_assert($freeForAllGuestId > 0 && $freeForAllGuestId !== $accountUserId, 'logged-in FFA open link should issue an isolated guest');
    videochat_iam_anonymous_lobby_assert((bool) (($freeForAllSession['user'] ?? [])['is_guest'] ?? false), 'FFA open link user should be a guest');
    videochat_iam_anonymous_lobby_assert_invite_state($pdo, $freeForAllCallId, $freeForAllGuestId, 'allowed', 'FFA open guest');
    videochat_iam_anonymous_lobby_assert_direct($pdo, $openDatabase, 'sess_iam_anon_lobby_ffa_open', $freeForAllCallId, 'FFA open guest');

    $inviteOnlyCallId = videochat_iam_anonymous_lobby_create_call(
        $pdo,
        $ownerUserId,
        $tenantId,
        'IAM Anonymous Invite Only Open Link',
        'invite_only',
        [$tempModeratorUserId]
    );
    $grantTempModerator = videochat_update_call_participant_role(
        $pdo,
        $inviteOnlyCallId,
        $tempModeratorUserId,
        'moderator',
        $ownerUserId,
        'user',
        $tenantId
    );
    videochat_iam_anonymous_lobby_assert((bool) ($grantTempModerator['ok'] ?? false), 'owner should grant temporary moderator for lobby proof');
    $inviteOnlyAccessId = videochat_iam_anonymous_lobby_insert_open_link($pdo, $tenantId, $inviteOnlyCallId, $ownerUserId);

    $loggedInOpenSession = videochat_iam_anonymous_lobby_issue_open_session(
        $pdo,
        $inviteOnlyAccessId,
        'sess_iam_anon_lobby_logged_in_open',
        'Logged In Waiting Guest',
        ['authenticated_user_id' => $accountUserId, 'authenticated_session_id' => $accountLoginSessionId]
    );
    $loggedInGuestId = (int) (($loggedInOpenSession['user'] ?? [])['id'] ?? 0);
    videochat_iam_anonymous_lobby_assert($loggedInGuestId > 0 && $loggedInGuestId !== $accountUserId, 'logged-in invite-only open link should not promote the account');
    videochat_iam_anonymous_lobby_assert((bool) (($loggedInOpenSession['user'] ?? [])['is_guest'] ?? false), 'logged-in invite-only open link should issue a guest');
    videochat_iam_anonymous_lobby_assert(videochat_iam_anonymous_lobby_participant($pdo, $inviteOnlyCallId, $loggedInGuestId) === null, 'logged-in open issuance must not grant guest-list rights');
    videochat_iam_anonymous_lobby_assert_waiting($pdo, $openDatabase, 'sess_iam_anon_lobby_logged_in_open', $inviteOnlyCallId, 'logged-in invite-only open link');

    $loggedOutOpenSession = videochat_iam_anonymous_lobby_issue_open_session(
        $pdo,
        $inviteOnlyAccessId,
        'sess_iam_anon_lobby_logged_out_open',
        'Logged Out Waiting Guest'
    );
    $loggedOutGuestId = (int) (($loggedOutOpenSession['user'] ?? [])['id'] ?? 0);
    videochat_iam_anonymous_lobby_assert($loggedOutGuestId > 0 && $loggedOutGuestId !== $loggedInGuestId && $loggedOutGuestId !== $accountUserId, 'logged-out invite-only open link should create a separate guest');
    videochat_iam_anonymous_lobby_assert((bool) (($loggedOutOpenSession['user'] ?? [])['is_guest'] ?? false), 'logged-out invite-only open link should issue a guest');
    videochat_iam_anonymous_lobby_assert(videochat_iam_anonymous_lobby_participant($pdo, $inviteOnlyCallId, $loggedOutGuestId) === null, 'logged-out open issuance must not grant guest-list rights');
    videochat_iam_anonymous_lobby_assert_waiting($pdo, $openDatabase, 'sess_iam_anon_lobby_logged_out_open', $inviteOnlyCallId, 'logged-out invite-only open link');

    $tempModeratorAdmitSession = videochat_iam_anonymous_lobby_issue_open_session(
        $pdo,
        $inviteOnlyAccessId,
        'sess_iam_anon_lobby_temp_mod_admit',
        'Temporary Moderator Admit Guest'
    );
    $tempModeratorAdmitUserId = (int) (($tempModeratorAdmitSession['user'] ?? [])['id'] ?? 0);
    $tempModeratorRejectSession = videochat_iam_anonymous_lobby_issue_open_session(
        $pdo,
        $inviteOnlyAccessId,
        'sess_iam_anon_lobby_temp_mod_reject',
        'Temporary Moderator Reject Guest'
    );
    $tempModeratorRejectUserId = (int) (($tempModeratorRejectSession['user'] ?? [])['id'] ?? 0);
    $orgRejectSession = videochat_iam_anonymous_lobby_issue_open_session(
        $pdo,
        $inviteOnlyAccessId,
        'sess_iam_anon_lobby_org_reject',
        'Organization Admin Reject Guest'
    );
    $orgRejectUserId = (int) (($orgRejectSession['user'] ?? [])['id'] ?? 0);
    $systemRejectSession = videochat_iam_anonymous_lobby_issue_open_session(
        $pdo,
        $inviteOnlyAccessId,
        'sess_iam_anon_lobby_system_reject',
        'System Admin Reject Guest'
    );
    $systemRejectUserId = (int) (($systemRejectSession['user'] ?? [])['id'] ?? 0);
    $privacyProbeSession = videochat_iam_anonymous_lobby_issue_open_session(
        $pdo,
        $inviteOnlyAccessId,
        'sess_iam_anon_lobby_privacy_probe',
        'Privacy Probe Guest'
    );
    $privacyProbeUserId = (int) (($privacyProbeSession['user'] ?? [])['id'] ?? 0);

    $ownerSessionId = videochat_iam_anonymous_lobby_issue_user_session($pdo, $ownerUserId, 'sess_iam_anon_lobby_owner', $tenantId);
    $tempModeratorSessionId = videochat_iam_anonymous_lobby_issue_user_session($pdo, $tempModeratorUserId, 'sess_iam_anon_lobby_temp_mod', $tenantId);
    $orgAdminSessionId = videochat_iam_anonymous_lobby_issue_user_session($pdo, $orgAdminUserId, 'sess_iam_anon_lobby_org_admin', $tenantId);
    $systemAdminSessionId = videochat_iam_anonymous_lobby_issue_user_session($pdo, $systemAdminUserId, 'sess_iam_anon_lobby_system_admin', $tenantId);
    $presenceState = videochat_presence_state_init();
    $lobbyState = videochat_lobby_state_init();

    $ownerConnection = videochat_iam_anonymous_lobby_connection($presenceState, $pdo, $openDatabase, $ownerSessionId, $inviteOnlyCallId, 'conn_owner');
    $tempModeratorConnection = videochat_iam_anonymous_lobby_connection($presenceState, $pdo, $openDatabase, $tempModeratorSessionId, $inviteOnlyCallId, 'conn_temp_mod');
    $orgAdminConnection = videochat_iam_anonymous_lobby_connection($presenceState, $pdo, $openDatabase, $orgAdminSessionId, $inviteOnlyCallId, 'conn_org_admin');
    $systemAdminConnection = videochat_iam_anonymous_lobby_connection($presenceState, $pdo, $openDatabase, $systemAdminSessionId, $inviteOnlyCallId, 'conn_system_admin');
    videochat_iam_anonymous_lobby_assert((bool) ($tempModeratorConnection['can_moderate_call'] ?? false), 'temporary moderator connection should carry lobby moderation authority');
    videochat_iam_anonymous_lobby_assert(!(bool) ($tempModeratorConnection['can_manage_call_owner'] ?? true), 'temporary moderator must not inherit owner-management authority');
    videochat_iam_anonymous_lobby_assert((string) ($tempModeratorConnection['effective_call_role'] ?? '') === 'moderator', 'temporary moderator effective role should stay moderator');

    $loggedInGuestConnection = videochat_iam_anonymous_lobby_queue($pdo, $openDatabase, $presenceState, $lobbyState, 'sess_iam_anon_lobby_logged_in_open', $inviteOnlyCallId, 'logged_in_guest');
    $selfAllowCommand = videochat_lobby_decode_client_frame(json_encode([
        'type' => 'lobby/allow',
        'room_id' => $inviteOnlyCallId,
        'target_user_id' => $loggedInGuestId,
    ], JSON_UNESCAPED_SLASHES));
    $selfAuthority = videochat_realtime_authorize_lobby_moderation_command($loggedInGuestConnection, $selfAllowCommand, $inviteOnlyCallId, $openDatabase);
    videochat_iam_anonymous_lobby_assert(!(bool) ($selfAuthority['ok'] ?? true), 'queued open-link guest must not authorize self admission');
    videochat_iam_anonymous_lobby_assert((string) ($selfAuthority['error'] ?? '') === 'forbidden', 'self admission denial reason mismatch');
    videochat_iam_anonymous_lobby_command(
        $lobbyState,
        $presenceState,
        $loggedInGuestConnection,
        $openDatabase,
        ['type' => 'lobby/allow', 'room_id' => $inviteOnlyCallId, 'target_user_id' => $loggedInGuestId],
        'unauthorized self-admit'
    );
    videochat_iam_anonymous_lobby_assert_invite_state($pdo, $inviteOnlyCallId, $loggedInGuestId, 'pending', 'self-admit denial should leave participant pending');
    videochat_iam_anonymous_lobby_assert_visible_snapshot($lobbyState, $ownerConnection, $inviteOnlyCallId, $loggedInGuestId, 'host waiting snapshot');
    videochat_iam_anonymous_lobby_admit($pdo, $openDatabase, $presenceState, $lobbyState, $ownerConnection, $inviteOnlyCallId, $loggedInGuestId, 'owner admission');
    videochat_iam_anonymous_lobby_assert_direct($pdo, $openDatabase, 'sess_iam_anon_lobby_logged_in_open', $inviteOnlyCallId, 'host admitted logged-in participant');

    $tempModeratorAdmitConnection = videochat_iam_anonymous_lobby_queue($pdo, $openDatabase, $presenceState, $lobbyState, 'sess_iam_anon_lobby_temp_mod_admit', $inviteOnlyCallId, 'temp_mod_admit_guest');
    videochat_iam_anonymous_lobby_assert((int) ($tempModeratorAdmitConnection['user_id'] ?? 0) === $tempModeratorAdmitUserId, 'temporary moderator admit target mismatch');
    videochat_iam_anonymous_lobby_assert_visible_snapshot($lobbyState, $tempModeratorConnection, $inviteOnlyCallId, $tempModeratorAdmitUserId, 'temporary moderator waiting snapshot');
    videochat_iam_anonymous_lobby_admit($pdo, $openDatabase, $presenceState, $lobbyState, $tempModeratorConnection, $inviteOnlyCallId, $tempModeratorAdmitUserId, 'temporary moderator admission');
    videochat_iam_anonymous_lobby_assert_direct($pdo, $openDatabase, 'sess_iam_anon_lobby_temp_mod_admit', $inviteOnlyCallId, 'temporary moderator admitted anonymous guest');

    $tempModeratorRejectConnection = videochat_iam_anonymous_lobby_queue($pdo, $openDatabase, $presenceState, $lobbyState, 'sess_iam_anon_lobby_temp_mod_reject', $inviteOnlyCallId, 'temp_mod_reject_guest');
    videochat_iam_anonymous_lobby_assert((int) ($tempModeratorRejectConnection['user_id'] ?? 0) === $tempModeratorRejectUserId, 'temporary moderator reject target mismatch');
    videochat_iam_anonymous_lobby_reject($pdo, $openDatabase, $presenceState, $lobbyState, $tempModeratorConnection, $inviteOnlyCallId, $tempModeratorRejectUserId, 'temporary moderator rejection');
    videochat_iam_anonymous_lobby_assert_rejected_session($pdo, 'sess_iam_anon_lobby_temp_mod_reject', $tempModeratorRejectUserId, 'temporary moderator rejected anonymous guest');

    $orgAuthorityCommand = videochat_lobby_decode_client_frame(json_encode([
        'type' => 'lobby/allow',
        'room_id' => $inviteOnlyCallId,
        'target_user_id' => $loggedOutGuestId,
    ], JSON_UNESCAPED_SLASHES));
    $orgAuthority = videochat_realtime_authorize_lobby_moderation_command($orgAdminConnection, $orgAuthorityCommand, $inviteOnlyCallId, $openDatabase);
    videochat_iam_anonymous_lobby_assert((bool) ($orgAuthority['ok'] ?? false), 'organization admin should moderate own-organization open-link lobby');
    videochat_iam_anonymous_lobby_assert((bool) ($orgAdminConnection['can_moderate_call'] ?? false), 'organization admin connection should carry lobby moderation authority');
    videochat_iam_anonymous_lobby_assert((string) ($orgAdminConnection['effective_call_role'] ?? '') === 'moderator', 'organization admin effective lobby role should be moderator');
    videochat_iam_anonymous_lobby_queue($pdo, $openDatabase, $presenceState, $lobbyState, 'sess_iam_anon_lobby_logged_out_open', $inviteOnlyCallId, 'logged_out_guest');
    videochat_iam_anonymous_lobby_assert_visible_snapshot($lobbyState, $orgAdminConnection, $inviteOnlyCallId, $loggedOutGuestId, 'organization admin waiting snapshot');
    videochat_iam_anonymous_lobby_admit($pdo, $openDatabase, $presenceState, $lobbyState, $orgAdminConnection, $inviteOnlyCallId, $loggedOutGuestId, 'organization admin admission');
    videochat_iam_anonymous_lobby_assert_direct($pdo, $openDatabase, 'sess_iam_anon_lobby_logged_out_open', $inviteOnlyCallId, 'organization admin admitted anonymous guest');

    $orgRejectConnection = videochat_iam_anonymous_lobby_queue($pdo, $openDatabase, $presenceState, $lobbyState, 'sess_iam_anon_lobby_org_reject', $inviteOnlyCallId, 'org_reject_guest');
    videochat_iam_anonymous_lobby_assert((int) ($orgRejectConnection['user_id'] ?? 0) === $orgRejectUserId, 'organization admin reject target mismatch');
    videochat_iam_anonymous_lobby_reject($pdo, $openDatabase, $presenceState, $lobbyState, $orgAdminConnection, $inviteOnlyCallId, $orgRejectUserId, 'organization admin rejection');
    videochat_iam_anonymous_lobby_assert_rejected_session($pdo, 'sess_iam_anon_lobby_org_reject', $orgRejectUserId, 'organization admin rejected anonymous guest');

    $systemGuestSession = videochat_iam_anonymous_lobby_issue_open_session(
        $pdo,
        $inviteOnlyAccessId,
        'sess_iam_anon_lobby_system_target',
        'System Admin Waiting Guest'
    );
    $systemGuestId = (int) (($systemGuestSession['user'] ?? [])['id'] ?? 0);
    $systemGuestConnection = videochat_iam_anonymous_lobby_queue($pdo, $openDatabase, $presenceState, $lobbyState, 'sess_iam_anon_lobby_system_target', $inviteOnlyCallId, 'system_guest');
    videochat_iam_anonymous_lobby_assert((int) ($systemGuestConnection['user_id'] ?? 0) === $systemGuestId, 'system target connection user mismatch');
    videochat_iam_anonymous_lobby_assert_visible_snapshot($lobbyState, $systemAdminConnection, $inviteOnlyCallId, $systemGuestId, 'system admin waiting snapshot');
    videochat_iam_anonymous_lobby_admit($pdo, $openDatabase, $presenceState, $lobbyState, $systemAdminConnection, $inviteOnlyCallId, $systemGuestId, 'system admin admission');
    videochat_iam_anonymous_lobby_assert_direct($pdo, $openDatabase, 'sess_iam_anon_lobby_system_target', $inviteOnlyCallId, 'system admin admitted anonymous guest');

    $systemRejectConnection = videochat_iam_anonymous_lobby_queue($pdo, $openDatabase, $presenceState, $lobbyState, 'sess_iam_anon_lobby_system_reject', $inviteOnlyCallId, 'system_reject_guest');
    videochat_iam_anonymous_lobby_assert((int) ($systemRejectConnection['user_id'] ?? 0) === $systemRejectUserId, 'system admin reject target mismatch');
    videochat_iam_anonymous_lobby_reject($pdo, $openDatabase, $presenceState, $lobbyState, $systemAdminConnection, $inviteOnlyCallId, $systemRejectUserId, 'system admin rejection');
    videochat_iam_anonymous_lobby_assert_rejected_session($pdo, 'sess_iam_anon_lobby_system_reject', $systemRejectUserId, 'system admin rejected anonymous guest');

    $privacyProbeConnection = videochat_iam_anonymous_lobby_queue($pdo, $openDatabase, $presenceState, $lobbyState, 'sess_iam_anon_lobby_privacy_probe', $inviteOnlyCallId, 'privacy_probe_guest');
    videochat_iam_anonymous_lobby_assert((int) ($privacyProbeConnection['user_id'] ?? 0) === $privacyProbeUserId, 'privacy probe target mismatch');
    $rejectGuestSession = videochat_iam_anonymous_lobby_issue_open_session(
        $pdo,
        $inviteOnlyAccessId,
        'sess_iam_anon_lobby_reject_probe',
        'Rejected Probe Guest'
    );
    $rejectGuestUserId = (int) (($rejectGuestSession['user'] ?? [])['id'] ?? 0);
    $rejectGuestConnection = videochat_iam_anonymous_lobby_queue($pdo, $openDatabase, $presenceState, $lobbyState, 'sess_iam_anon_lobby_reject_probe', $inviteOnlyCallId, 'reject_probe_guest');
    videochat_iam_anonymous_lobby_assert_redacted_controls($lobbyState, $rejectGuestConnection, $inviteOnlyCallId, $privacyProbeUserId, 'unauthorized waiting-user lobby controls');

    $rejectSelfAllowCommand = videochat_lobby_decode_client_frame(json_encode([
        'type' => 'lobby/allow',
        'room_id' => $inviteOnlyCallId,
        'target_user_id' => $rejectGuestUserId,
    ], JSON_UNESCAPED_SLASHES));
    $rejectSelfAuthority = videochat_realtime_authorize_lobby_moderation_command($rejectGuestConnection, $rejectSelfAllowCommand, $inviteOnlyCallId, $openDatabase);
    videochat_iam_anonymous_lobby_assert(!(bool) ($rejectSelfAuthority['ok'] ?? true), 'queued participant must not authorize self admission');
    videochat_iam_anonymous_lobby_command(
        $lobbyState,
        $presenceState,
        $rejectGuestConnection,
        $openDatabase,
        ['type' => 'lobby/allow', 'room_id' => $inviteOnlyCallId, 'target_user_id' => $rejectGuestUserId],
        'unauthorized self-admit denial'
    );
    videochat_iam_anonymous_lobby_assert_invite_state($pdo, $inviteOnlyCallId, $rejectGuestUserId, 'pending', 'unauthorized self-admit denial should leave participant pending');

    @unlink($databasePath);
    fwrite(STDOUT, "[call-access-anonymous-lobby-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[call-access-anonymous-lobby-contract] ERROR: ' . $error->getMessage() . "\n");
    fwrite(STDERR, $error->getTraceAsString() . "\n");
    exit(1);
} finally {
    if (isset($databasePath) && is_string($databasePath) && is_file($databasePath)) {
        @unlink($databasePath);
    }
}
