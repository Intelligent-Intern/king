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
        ':email' => $email,
        ':display_name' => $displayName,
        ':password_hash' => $passwordHash,
        ':role_id' => $roleId,
        ':updated_at' => gmdate('c'),
    ]);

    return (int) $pdo->lastInsertId();
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

function videochat_iam_anonymous_lobby_connection(
    array &$presenceState,
    PDO $pdo,
    callable $openDatabase,
    string $sessionId,
    string $callId,
    string $connectionId,
    string $socket
): array {
    $auth = videochat_iam_anonymous_lobby_auth($pdo, $sessionId);
    $resolution = videochat_realtime_resolve_connection_rooms($auth, $callId, $openDatabase, $callId);
    videochat_iam_anonymous_lobby_assert((bool) ($resolution['ok'] ?? false), "{$connectionId}: room resolution should succeed");
    $connection = videochat_presence_connection_descriptor(
        (array) ($auth['user'] ?? []),
        $sessionId,
        $connectionId,
        $socket,
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
    $command = videochat_lobby_decode_client_frame(json_encode($payload, JSON_UNESCAPED_SLASHES));
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
    $connection = videochat_iam_anonymous_lobby_connection(
        $presenceState,
        $pdo,
        $openDatabase,
        $sessionId,
        $callId,
        'conn_' . preg_replace('/[^a-z0-9_]+/i', '_', $label),
        'socket_' . preg_replace('/[^a-z0-9_]+/i', '_', $label)
    );
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
    videochat_iam_anonymous_lobby_assert_invite_state($pdo, $callId, $targetUserId, 'invited', $label);
}

function videochat_iam_anonymous_lobby_assert_direct_room(
    PDO $pdo,
    callable $openDatabase,
    string $sessionId,
    string $callId,
    string $label
): void {
    $auth = videochat_iam_anonymous_lobby_auth($pdo, $sessionId);
    $resolution = videochat_realtime_resolve_connection_rooms($auth, $callId, $openDatabase, $callId);
    videochat_iam_anonymous_lobby_assert((bool) ($resolution['ok'] ?? false), "{$label}: room resolution should succeed");
    videochat_iam_anonymous_lobby_assert((string) ($resolution['initial_room_id'] ?? '') === $callId, "{$label}: should enter the target call room");
    videochat_iam_anonymous_lobby_assert((string) ($resolution['pending_room_id'] ?? '') === '', "{$label}: should not keep a pending lobby room");
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
    $queue = is_array($snapshot['queue'] ?? null) ? $snapshot['queue'] : [];
    videochat_iam_anonymous_lobby_assert((int) ($snapshot['queue_count'] ?? 0) >= 1, "{$label}: moderator should see waiting participants");
    videochat_iam_anonymous_lobby_assert(
        count(array_filter(
            $queue,
            static fn (mixed $entry): bool => is_array($entry) && (int) ($entry['user_id'] ?? 0) === $targetUserId
        )) === 1,
        "{$label}: moderator snapshot should expose the waiting participant id"
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
    videochat_iam_anonymous_lobby_assert((int) ($snapshot['queue_count'] ?? -1) === 1, "{$label}: unauthorized viewer should see only their own lobby entry");
    videochat_iam_anonymous_lobby_assert(
        (int) ((($snapshot['queue'] ?? [])[0] ?? [])['user_id'] ?? 0) === $viewerUserId,
        "{$label}: unauthorized viewer own lobby row mismatch"
    );
    foreach ((array) ($snapshot['queue'] ?? []) as $entry) {
        videochat_iam_anonymous_lobby_assert((int) ($entry['user_id'] ?? 0) !== $otherUserId, "{$label}: unauthorized snapshot leaked another waiting user");
    }
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-access-anonymous-lobby-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-call-access-anonymous-lobby-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);
    $openDatabase = static function () use ($pdo): PDO {
        return $pdo;
    };

    $userRoleId = videochat_iam_anonymous_lobby_role_id($pdo, 'user');
    $adminRoleId = videochat_iam_anonymous_lobby_role_id($pdo, 'admin');
    videochat_iam_anonymous_lobby_assert($userRoleId > 0 && $adminRoleId > 0, 'expected user and admin roles');

    $unique = bin2hex(random_bytes(5));
    $now = gmdate('c');
    $tenantInsert = $pdo->prepare(
        <<<'SQL'
INSERT INTO tenants(public_id, slug, label, status, created_at, updated_at)
VALUES(:public_id, :slug, :label, 'active', :created_at, :updated_at)
SQL
    );
    $tenantInsert->execute([
        ':public_id' => 'tenant-iam-anon-lobby-' . $unique,
        ':slug' => 'iam-anon-lobby-' . $unique,
        ':label' => 'IAM Anonymous Lobby ' . $unique,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
    $tenantId = (int) $pdo->lastInsertId();
    videochat_iam_anonymous_lobby_assert($tenantId > 0, 'tenant should be created');

    $orgInsert = $pdo->prepare(
        <<<'SQL'
INSERT INTO organizations(tenant_id, public_id, name, status, created_at, updated_at)
VALUES(:tenant_id, :public_id, :name, 'active', :created_at, :updated_at)
SQL
    );
    $orgInsert->execute([
        ':tenant_id' => $tenantId,
        ':public_id' => 'org-iam-anon-lobby-' . $unique,
        ':name' => 'IAM Anonymous Lobby Org',
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
    $organizationId = (int) $pdo->lastInsertId();
    videochat_iam_anonymous_lobby_assert($organizationId > 0, 'organization should be created');

    $ownerUserId = videochat_iam_anonymous_lobby_create_user($pdo, $userRoleId, 'iam-anon-owner-' . $unique . '@example.test', 'IAM Anonymous Owner');
    $tempModeratorUserId = videochat_iam_anonymous_lobby_create_user($pdo, $userRoleId, 'iam-anon-temp-mod-' . $unique . '@example.test', 'IAM Anonymous Temp Moderator');
    $orgAdminUserId = videochat_iam_anonymous_lobby_create_user($pdo, $userRoleId, 'iam-anon-org-admin-' . $unique . '@example.test', 'IAM Anonymous Org Admin');
    $systemAdminUserId = videochat_iam_anonymous_lobby_create_user($pdo, $adminRoleId, 'iam-anon-system-admin-' . $unique . '@example.test', 'IAM Anonymous System Admin');
    $accountUserId = videochat_iam_anonymous_lobby_create_user($pdo, $userRoleId, 'iam-anon-account-' . $unique . '@example.test', 'IAM Anonymous Account');

    foreach ([$ownerUserId, $tempModeratorUserId, $orgAdminUserId, $systemAdminUserId, $accountUserId] as $userId) {
        videochat_tenant_attach_user($pdo, $userId, $tenantId);
    }
    $organizationMembership = $pdo->prepare(
        <<<'SQL'
INSERT INTO organization_memberships(tenant_id, organization_id, user_id, membership_role, status, created_at, updated_at)
VALUES(:tenant_id, :organization_id, :user_id, :membership_role, 'active', :created_at, :updated_at)
SQL
    );
    foreach ([[$ownerUserId, 'member'], [$tempModeratorUserId, 'member'], [$orgAdminUserId, 'admin']] as [$userId, $role]) {
        $organizationMembership->execute([
            ':tenant_id' => $tenantId,
            ':organization_id' => $organizationId,
            ':user_id' => $userId,
            ':membership_role' => $role,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    $startsAt = gmdate('c', time() - 300);
    $endsAt = gmdate('c', time() + 3600);
    $callCreate = videochat_create_call($pdo, $ownerUserId, [
        'title' => 'IAM Anonymous Open Link Lobby',
        'access_mode' => 'free_for_all',
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'internal_participant_user_ids' => [$tempModeratorUserId],
        'external_participants' => [],
    ], $tenantId);
    videochat_iam_anonymous_lobby_assert((bool) ($callCreate['ok'] ?? false), 'open call should be created');
    $callId = (string) (($callCreate['call'] ?? [])['id'] ?? '');
    videochat_iam_anonymous_lobby_assert($callId !== '', 'open call id should be non-empty');

    $grantTempModerator = videochat_update_call_participant_role(
        $pdo,
        $callId,
        $tempModeratorUserId,
        'moderator',
        $ownerUserId,
        'user',
        $tenantId
    );
    videochat_iam_anonymous_lobby_assert((bool) ($grantTempModerator['ok'] ?? false), 'owner should grant temporary moderator for lobby proof');

    $linkCreate = videochat_create_call_access_link_for_user($pdo, $callId, $ownerUserId, 'user', [
        'link_kind' => 'open',
    ], $tenantId);
    videochat_iam_anonymous_lobby_assert((bool) ($linkCreate['ok'] ?? false), 'open link should be created');
    $accessId = (string) (($linkCreate['access_link'] ?? [])['id'] ?? '');
    videochat_iam_anonymous_lobby_assert($accessId !== '', 'open access id should be non-empty');

    $accountLoginSessionId = videochat_iam_anonymous_lobby_issue_user_session($pdo, $accountUserId, 'sess_iam_anon_lobby_account_login', $tenantId);
    $guestCountBeforeAccountOpen = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE 'guest+%@videochat.local'")->fetchColumn();
    $accountOpenSession = videochat_issue_session_for_call_access(
        $pdo,
        $accessId,
        videochat_iam_anonymous_lobby_issue_session_id('sess_iam_anon_lobby_account_open'),
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-access-anonymous-lobby-contract'],
        [
            'authenticated_user_id' => $accountUserId,
            'authenticated_session_id' => $accountLoginSessionId,
            'verified_user_id' => $accountUserId,
            'verified_session_id' => $accountLoginSessionId,
            'guest_name' => 'Ignored Logged In Guest',
        ]
    );
    videochat_iam_anonymous_lobby_assert((bool) ($accountOpenSession['ok'] ?? false), 'logged-in open session should issue');
    videochat_iam_anonymous_lobby_assert((int) (($accountOpenSession['user'] ?? [])['id'] ?? 0) === $accountUserId, 'logged-in open link should use the authenticated account');
    videochat_iam_anonymous_lobby_assert((bool) (($accountOpenSession['user'] ?? [])['is_guest'] ?? true) === false, 'logged-in open user should not be a guest');
    videochat_iam_anonymous_lobby_assert((string) (($accountOpenSession['user'] ?? [])['account_type'] ?? '') === 'account', 'logged-in open user should keep account type');
    $guestCountAfterAccountOpen = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE 'guest+%@videochat.local'")->fetchColumn();
    videochat_iam_anonymous_lobby_assert($guestCountAfterAccountOpen === $guestCountBeforeAccountOpen, 'logged-in open link should not create a temporary guest');
    videochat_iam_anonymous_lobby_assert_invite_state($pdo, $callId, $accountUserId, 'pending', 'logged-in open link default lobby wait');
    videochat_iam_anonymous_lobby_assert_waiting($pdo, $openDatabase, 'sess_iam_anon_lobby_account_open', $callId, 'logged-in open link');

    $guestCountBeforeLoggedOutOpen = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE 'guest+%@videochat.local'")->fetchColumn();
    $loggedOutOpenSession = videochat_issue_session_for_call_access(
        $pdo,
        $accessId,
        videochat_iam_anonymous_lobby_issue_session_id('sess_iam_anon_lobby_guest_open'),
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-access-anonymous-lobby-contract'],
        ['guest_name' => 'Anonymous Lobby Guest']
    );
    videochat_iam_anonymous_lobby_assert((bool) ($loggedOutOpenSession['ok'] ?? false), 'logged-out open guest session should issue');
    $loggedOutGuestUserId = (int) (($loggedOutOpenSession['user'] ?? [])['id'] ?? 0);
    videochat_iam_anonymous_lobby_assert($loggedOutGuestUserId > 0 && $loggedOutGuestUserId !== $accountUserId, 'logged-out open link should create a separate user');
    videochat_iam_anonymous_lobby_assert((bool) (($loggedOutOpenSession['user'] ?? [])['is_guest'] ?? false) === true, 'logged-out open user should be a guest');
    videochat_iam_anonymous_lobby_assert((string) (($loggedOutOpenSession['user'] ?? [])['account_type'] ?? '') === 'guest', 'logged-out open user should have guest account type');
    $guestCountAfterLoggedOutOpen = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE 'guest+%@videochat.local'")->fetchColumn();
    videochat_iam_anonymous_lobby_assert($guestCountAfterLoggedOutOpen === $guestCountBeforeLoggedOutOpen + 1, 'logged-out open link should create one temporary guest');
    videochat_iam_anonymous_lobby_assert_invite_state($pdo, $callId, $loggedOutGuestUserId, 'pending', 'logged-out open link default lobby wait');
    videochat_iam_anonymous_lobby_assert_waiting($pdo, $openDatabase, 'sess_iam_anon_lobby_guest_open', $callId, 'logged-out open link');

    $tempModeratorGuestSession = videochat_issue_session_for_call_access(
        $pdo,
        $accessId,
        videochat_iam_anonymous_lobby_issue_session_id('sess_iam_anon_lobby_guest_temp_mod'),
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-access-anonymous-lobby-contract'],
        ['guest_name' => 'Temp Moderator Admit Guest']
    );
    videochat_iam_anonymous_lobby_assert((bool) ($tempModeratorGuestSession['ok'] ?? false), 'temporary-moderator target guest session should issue');
    $tempModeratorGuestUserId = (int) (($tempModeratorGuestSession['user'] ?? [])['id'] ?? 0);

    $tempModeratorRejectSession = videochat_issue_session_for_call_access(
        $pdo,
        $accessId,
        videochat_iam_anonymous_lobby_issue_session_id('sess_iam_anon_lobby_guest_temp_mod_reject'),
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-access-anonymous-lobby-contract'],
        ['guest_name' => 'Temp Moderator Reject Guest']
    );
    videochat_iam_anonymous_lobby_assert((bool) ($tempModeratorRejectSession['ok'] ?? false), 'temporary-moderator reject target guest session should issue');
    $tempModeratorRejectUserId = (int) (($tempModeratorRejectSession['user'] ?? [])['id'] ?? 0);

    $orgGuestSession = videochat_issue_session_for_call_access(
        $pdo,
        $accessId,
        videochat_iam_anonymous_lobby_issue_session_id('sess_iam_anon_lobby_guest_org'),
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-access-anonymous-lobby-contract'],
        ['guest_name' => 'Org Admin Admit Guest']
    );
    videochat_iam_anonymous_lobby_assert((bool) ($orgGuestSession['ok'] ?? false), 'org-admin target guest session should issue');
    $orgGuestUserId = (int) (($orgGuestSession['user'] ?? [])['id'] ?? 0);

    $orgRejectGuestSession = videochat_issue_session_for_call_access(
        $pdo,
        $accessId,
        videochat_iam_anonymous_lobby_issue_session_id('sess_iam_anon_lobby_guest_org_reject'),
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-access-anonymous-lobby-contract'],
        ['guest_name' => 'Org Admin Reject Guest']
    );
    videochat_iam_anonymous_lobby_assert((bool) ($orgRejectGuestSession['ok'] ?? false), 'org-admin reject target guest session should issue');
    $orgRejectGuestUserId = (int) (($orgRejectGuestSession['user'] ?? [])['id'] ?? 0);

    $systemGuestSession = videochat_issue_session_for_call_access(
        $pdo,
        $accessId,
        videochat_iam_anonymous_lobby_issue_session_id('sess_iam_anon_lobby_guest_system'),
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-access-anonymous-lobby-contract'],
        ['guest_name' => 'System Admin Admit Guest']
    );
    videochat_iam_anonymous_lobby_assert((bool) ($systemGuestSession['ok'] ?? false), 'system-admin target guest session should issue');
    $systemGuestUserId = (int) (($systemGuestSession['user'] ?? [])['id'] ?? 0);

    $systemRejectGuestSession = videochat_issue_session_for_call_access(
        $pdo,
        $accessId,
        videochat_iam_anonymous_lobby_issue_session_id('sess_iam_anon_lobby_guest_system_reject'),
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-access-anonymous-lobby-contract'],
        ['guest_name' => 'System Admin Reject Guest']
    );
    videochat_iam_anonymous_lobby_assert((bool) ($systemRejectGuestSession['ok'] ?? false), 'system-admin reject target guest session should issue');
    $systemRejectGuestUserId = (int) (($systemRejectGuestSession['user'] ?? [])['id'] ?? 0);

    $rejectGuestSession = videochat_issue_session_for_call_access(
        $pdo,
        $accessId,
        videochat_iam_anonymous_lobby_issue_session_id('sess_iam_anon_lobby_guest_reject'),
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-access-anonymous-lobby-contract'],
        ['guest_name' => 'Rejected Guest']
    );
    videochat_iam_anonymous_lobby_assert((bool) ($rejectGuestSession['ok'] ?? false), 'reject target guest session should issue');
    $rejectGuestUserId = (int) (($rejectGuestSession['user'] ?? [])['id'] ?? 0);

    $ownerSessionId = videochat_iam_anonymous_lobby_issue_user_session($pdo, $ownerUserId, 'sess_iam_anon_lobby_owner', $tenantId);
    $tempModeratorSessionId = videochat_iam_anonymous_lobby_issue_user_session($pdo, $tempModeratorUserId, 'sess_iam_anon_lobby_temp_mod', $tenantId);
    $orgAdminSessionId = videochat_iam_anonymous_lobby_issue_user_session($pdo, $orgAdminUserId, 'sess_iam_anon_lobby_org_admin', $tenantId);
    $systemAdminSessionId = videochat_iam_anonymous_lobby_issue_user_session($pdo, $systemAdminUserId, 'sess_iam_anon_lobby_system_admin', $tenantId);

    $presenceState = videochat_presence_state_init();
    $lobbyState = videochat_lobby_state_init();

    $ownerConnection = videochat_iam_anonymous_lobby_connection($presenceState, $pdo, $openDatabase, $ownerSessionId, $callId, 'conn_owner', 'socket_owner');
    $tempModeratorConnection = videochat_iam_anonymous_lobby_connection($presenceState, $pdo, $openDatabase, $tempModeratorSessionId, $callId, 'conn_temp_mod', 'socket_temp_mod');
    $orgAdminConnection = videochat_iam_anonymous_lobby_connection($presenceState, $pdo, $openDatabase, $orgAdminSessionId, $callId, 'conn_org_admin', 'socket_org_admin');
    $systemAdminConnection = videochat_iam_anonymous_lobby_connection($presenceState, $pdo, $openDatabase, $systemAdminSessionId, $callId, 'conn_system_admin', 'socket_system_admin');

    $accountConnection = videochat_iam_anonymous_lobby_queue($pdo, $openDatabase, $presenceState, $lobbyState, 'sess_iam_anon_lobby_account_open', $callId, 'account_host_admit');
    videochat_iam_anonymous_lobby_assert_visible_snapshot($lobbyState, $ownerConnection, $callId, (int) ($accountConnection['user_id'] ?? 0), 'host waiting snapshot');
    videochat_iam_anonymous_lobby_admit($pdo, $openDatabase, $presenceState, $lobbyState, $ownerConnection, $callId, (int) ($accountConnection['user_id'] ?? 0), 'host admission');
    videochat_iam_anonymous_lobby_assert_direct_room($pdo, $openDatabase, 'sess_iam_anon_lobby_account_open', $callId, 'host admitted logged-in participant');

    $tempModeratorGuestConnection = videochat_iam_anonymous_lobby_queue($pdo, $openDatabase, $presenceState, $lobbyState, 'sess_iam_anon_lobby_guest_temp_mod', $callId, 'guest_temp_mod_admit');
    videochat_iam_anonymous_lobby_assert_visible_snapshot($lobbyState, $tempModeratorConnection, $callId, (int) ($tempModeratorGuestConnection['user_id'] ?? 0), 'temporary moderator waiting snapshot');
    videochat_iam_anonymous_lobby_admit($pdo, $openDatabase, $presenceState, $lobbyState, $tempModeratorConnection, $callId, (int) ($tempModeratorGuestConnection['user_id'] ?? 0), 'temporary moderator admission');
    videochat_iam_anonymous_lobby_assert_direct_room($pdo, $openDatabase, 'sess_iam_anon_lobby_guest_temp_mod', $callId, 'temporary moderator admitted anonymous guest');

    $tempModeratorRejectConnection = videochat_iam_anonymous_lobby_queue($pdo, $openDatabase, $presenceState, $lobbyState, 'sess_iam_anon_lobby_guest_temp_mod_reject', $callId, 'guest_temp_mod_reject');
    videochat_iam_anonymous_lobby_reject($pdo, $openDatabase, $presenceState, $lobbyState, $tempModeratorConnection, $callId, (int) ($tempModeratorRejectConnection['user_id'] ?? 0), 'temporary moderator rejection');
    videochat_iam_anonymous_lobby_assert_waiting($pdo, $openDatabase, 'sess_iam_anon_lobby_guest_temp_mod_reject', $callId, 'temporary moderator rejected anonymous guest');

    $orgGuestConnection = videochat_iam_anonymous_lobby_queue($pdo, $openDatabase, $presenceState, $lobbyState, 'sess_iam_anon_lobby_guest_org', $callId, 'guest_org_admit');
    videochat_iam_anonymous_lobby_assert_visible_snapshot($lobbyState, $orgAdminConnection, $callId, (int) ($orgGuestConnection['user_id'] ?? 0), 'organization admin waiting snapshot');
    $orgAllowCommand = videochat_lobby_decode_client_frame(json_encode([
        'type' => 'lobby/allow',
        'room_id' => $callId,
        'target_user_id' => $orgGuestUserId,
    ], JSON_UNESCAPED_SLASHES));
    $orgAuthority = videochat_realtime_authorize_lobby_moderation_command($orgAdminConnection, $orgAllowCommand, $callId, $openDatabase);
    videochat_iam_anonymous_lobby_assert((bool) ($orgAuthority['ok'] ?? false), 'organization admin should be authorized to moderate own organization lobby');
    videochat_iam_anonymous_lobby_assert((string) ($orgAuthority['call_role'] ?? '') === 'participant', 'organization admin authority should preserve stored participant role');
    videochat_iam_anonymous_lobby_assert((string) ($orgAuthority['effective_call_role'] ?? '') === 'moderator', 'organization admin authority should be scoped as effective moderator');
    videochat_iam_anonymous_lobby_admit($pdo, $openDatabase, $presenceState, $lobbyState, $orgAdminConnection, $callId, (int) ($orgGuestConnection['user_id'] ?? 0), 'organization admin admission');
    videochat_iam_anonymous_lobby_assert_direct_room($pdo, $openDatabase, 'sess_iam_anon_lobby_guest_org', $callId, 'organization admin admitted anonymous guest');

    $orgRejectGuestConnection = videochat_iam_anonymous_lobby_queue($pdo, $openDatabase, $presenceState, $lobbyState, 'sess_iam_anon_lobby_guest_org_reject', $callId, 'guest_org_reject');
    videochat_iam_anonymous_lobby_reject($pdo, $openDatabase, $presenceState, $lobbyState, $orgAdminConnection, $callId, (int) ($orgRejectGuestConnection['user_id'] ?? 0), 'organization admin rejection');
    videochat_iam_anonymous_lobby_assert_waiting($pdo, $openDatabase, 'sess_iam_anon_lobby_guest_org_reject', $callId, 'organization admin rejected anonymous guest');

    $systemGuestConnection = videochat_iam_anonymous_lobby_queue($pdo, $openDatabase, $presenceState, $lobbyState, 'sess_iam_anon_lobby_guest_system', $callId, 'guest_system_admit');
    $systemAdminLobbySnapshot = videochat_lobby_snapshot_payload_for_connection(
        videochat_lobby_snapshot_payload($lobbyState, $callId, 'system_admin_waiting_probe'),
        $systemAdminConnection
    );
    $systemAdminQueue = is_array($systemAdminLobbySnapshot['queue'] ?? null) ? $systemAdminLobbySnapshot['queue'] : [];
    videochat_iam_anonymous_lobby_assert((int) ($systemAdminLobbySnapshot['queue_count'] ?? 0) >= 1, 'system admin should see waiting participants in lobby snapshot');
    videochat_iam_anonymous_lobby_assert(
        count(array_filter(
            $systemAdminQueue,
            static fn (mixed $entry): bool => is_array($entry) && (int) ($entry['user_id'] ?? 0) === $systemGuestUserId
        )) === 1,
        'system admin lobby snapshot should expose the waiting participant id'
    );
    $systemAllowCommand = videochat_lobby_decode_client_frame(json_encode([
        'type' => 'lobby/allow',
        'room_id' => $callId,
        'target_user_id' => $systemGuestUserId,
    ], JSON_UNESCAPED_SLASHES));
    $systemAuthority = videochat_realtime_authorize_lobby_moderation_command($systemAdminConnection, $systemAllowCommand, $callId, $openDatabase);
    videochat_iam_anonymous_lobby_assert((bool) ($systemAuthority['ok'] ?? false), 'system admin should be authorized to moderate lobby without guest-list row');
    videochat_iam_anonymous_lobby_assert((string) ($systemAuthority['call_role'] ?? '') === 'participant', 'system admin authority should preserve stored participant role');
    videochat_iam_anonymous_lobby_assert((string) ($systemAuthority['effective_call_role'] ?? '') === 'owner', 'system admin authority should expose owner-equivalent effective role');
    videochat_iam_anonymous_lobby_admit($pdo, $openDatabase, $presenceState, $lobbyState, $systemAdminConnection, $callId, (int) ($systemGuestConnection['user_id'] ?? 0), 'system admin admission');
    videochat_iam_anonymous_lobby_assert_direct_room($pdo, $openDatabase, 'sess_iam_anon_lobby_guest_system', $callId, 'system admin admitted anonymous guest');

    $systemRejectGuestConnection = videochat_iam_anonymous_lobby_queue($pdo, $openDatabase, $presenceState, $lobbyState, 'sess_iam_anon_lobby_guest_system_reject', $callId, 'guest_system_reject');
    videochat_iam_anonymous_lobby_reject($pdo, $openDatabase, $presenceState, $lobbyState, $systemAdminConnection, $callId, (int) ($systemRejectGuestConnection['user_id'] ?? 0), 'system admin rejection');
    videochat_iam_anonymous_lobby_assert_waiting($pdo, $openDatabase, 'sess_iam_anon_lobby_guest_system_reject', $callId, 'system admin rejected anonymous guest');

    $privacyProbeConnection = videochat_iam_anonymous_lobby_queue($pdo, $openDatabase, $presenceState, $lobbyState, 'sess_iam_anon_lobby_guest_open', $callId, 'guest_privacy_probe');
    $rejectGuestConnection = videochat_iam_anonymous_lobby_queue($pdo, $openDatabase, $presenceState, $lobbyState, 'sess_iam_anon_lobby_guest_reject', $callId, 'guest_reject');
    videochat_iam_anonymous_lobby_assert_redacted_controls($lobbyState, $rejectGuestConnection, $callId, (int) ($privacyProbeConnection['user_id'] ?? 0), 'unauthorized waiting-user lobby controls');
    $selfAllowCommand = videochat_lobby_decode_client_frame(json_encode([
        'type' => 'lobby/allow',
        'room_id' => $callId,
        'target_user_id' => $rejectGuestUserId,
    ], JSON_UNESCAPED_SLASHES));
    $selfAuthority = videochat_realtime_authorize_lobby_moderation_command($rejectGuestConnection, $selfAllowCommand, $callId, $openDatabase);
    videochat_iam_anonymous_lobby_assert(!(bool) ($selfAuthority['ok'] ?? true), 'queued participant must not authorize self admission');
    videochat_iam_anonymous_lobby_assert((string) ($selfAuthority['error'] ?? '') === 'forbidden', 'self admission denial reason mismatch');
    videochat_iam_anonymous_lobby_command(
        $lobbyState,
        $presenceState,
        $rejectGuestConnection,
        $openDatabase,
        ['type' => 'lobby/allow', 'room_id' => $callId, 'target_user_id' => $rejectGuestUserId],
        'unauthorized self-admit'
    );
    videochat_iam_anonymous_lobby_assert_invite_state($pdo, $callId, $rejectGuestUserId, 'pending', 'unauthorized self-admit denial should leave participant pending');

    videochat_iam_anonymous_lobby_command(
        $lobbyState,
        $presenceState,
        $ownerConnection,
        $openDatabase,
        ['type' => 'lobby/reject', 'room_id' => $callId, 'target_user_id' => $rejectGuestUserId],
        'host rejection'
    );
    videochat_iam_anonymous_lobby_assert_invite_state($pdo, $callId, $rejectGuestUserId, 'invited', 'host rejection should return participant to invited');
    videochat_iam_anonymous_lobby_assert_waiting($pdo, $openDatabase, 'sess_iam_anon_lobby_guest_reject', $callId, 'host rejected anonymous guest');

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
