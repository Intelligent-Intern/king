<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../domain/realtime/realtime_lobby.php';
require_once __DIR__ . '/../http/module_realtime.php';

function videochat_iam_anonymous_temp_rights_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-access-anonymous-temp-rights-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_iam_anonymous_temp_rights_role_id(PDO $pdo, string $role): int
{
    $query = $pdo->prepare('SELECT id FROM roles WHERE slug = :slug LIMIT 1');
    $query->execute([':slug' => $role]);
    return (int) $query->fetchColumn();
}

function videochat_iam_anonymous_temp_rights_create_user(PDO $pdo, int $roleId, string $email, string $displayName): int
{
    $passwordHash = password_hash('call-access-anonymous-temp-rights-contract', PASSWORD_DEFAULT);
    videochat_iam_anonymous_temp_rights_assert(is_string($passwordHash) && $passwordHash !== '', 'password hash should be available');
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
    videochat_iam_anonymous_temp_rights_assert($userId > 0, "{$displayName} user should be created");
    return $userId;
}

function videochat_iam_anonymous_temp_rights_create_tenant(PDO $pdo, string $unique): int
{
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO tenants(public_id, slug, label, status, created_at, updated_at)
VALUES(:public_id, :slug, :label, 'active', :created_at, :updated_at)
SQL
    );
    $insert->execute([
        ':public_id' => 'tenant-anon-temp-rights-' . $unique,
        ':slug' => 'anon-temp-rights-' . $unique,
        ':label' => 'Anonymous Temporary Rights ' . $unique,
        ':created_at' => gmdate('c'),
        ':updated_at' => gmdate('c'),
    ]);

    $tenantId = (int) $pdo->lastInsertId();
    videochat_iam_anonymous_temp_rights_assert($tenantId > 0, 'tenant should be created');
    return $tenantId;
}

function videochat_iam_anonymous_temp_rights_create_organization(PDO $pdo, int $tenantId, string $unique): int
{
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO organizations(tenant_id, public_id, name, status, created_at, updated_at)
VALUES(:tenant_id, :public_id, :name, 'active', :created_at, :updated_at)
SQL
    );
    $insert->execute([
        ':tenant_id' => $tenantId,
        ':public_id' => 'org-anon-temp-rights-' . $unique,
        ':name' => 'Anonymous Temporary Rights Org',
        ':created_at' => gmdate('c'),
        ':updated_at' => gmdate('c'),
    ]);

    $organizationId = (int) $pdo->lastInsertId();
    videochat_iam_anonymous_temp_rights_assert($organizationId > 0, 'organization should be created');
    return $organizationId;
}

function videochat_iam_anonymous_temp_rights_attach_organization(PDO $pdo, int $tenantId, int $organizationId, int $userId, string $role): void
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
        ':membership_role' => $role,
        ':created_at' => gmdate('c'),
        ':updated_at' => gmdate('c'),
    ]);
}

function videochat_iam_anonymous_temp_rights_create_invite_only_call(PDO $pdo, int $ownerUserId, int $tenantId): string
{
    $call = videochat_create_call($pdo, $ownerUserId, [
        'title' => 'Anonymous Temporary Rights Proof',
        'access_mode' => 'invite_only',
        'starts_at' => gmdate('c', time() - 300),
        'ends_at' => gmdate('c', time() + 3600),
        'internal_participant_user_ids' => [],
        'external_participants' => [],
    ], $tenantId);
    videochat_iam_anonymous_temp_rights_assert((bool) ($call['ok'] ?? false), 'invite-only call should be created');

    $callId = (string) (($call['call'] ?? [])['id'] ?? '');
    videochat_iam_anonymous_temp_rights_assert($callId !== '', 'call id should be non-empty');
    return $callId;
}

function videochat_iam_anonymous_temp_rights_insert_open_link(PDO $pdo, int $tenantId, string $callId, int $ownerUserId): string
{
    $accessId = videochat_generate_call_access_uuid();
    $tenantColumn = videochat_tenant_table_has_column($pdo, 'call_access_links', 'tenant_id') ? ', tenant_id' : '';
    $tenantValue = $tenantColumn !== '' ? ', :tenant_id' : '';
    $insert = $pdo->prepare(
        <<<SQL
INSERT INTO call_access_links(id, call_id, participant_user_id, participant_email, invite_code_id, created_by_user_id, created_at, expires_at{$tenantColumn})
VALUES(:id, :call_id, NULL, NULL, NULL, :created_by_user_id, :created_at, :expires_at{$tenantValue})
SQL
    );
    $params = [
        ':id' => $accessId,
        ':call_id' => $callId,
        ':created_by_user_id' => $ownerUserId,
        ':created_at' => gmdate('c'),
        ':expires_at' => gmdate('c', time() + 3600),
    ];
    if ($tenantColumn !== '') {
        $params[':tenant_id'] = $tenantId;
    }
    $insert->execute($params);

    return $accessId;
}

function videochat_iam_anonymous_temp_rights_issue(PDO $pdo, string $accessId, string $sessionId, array $options): array
{
    return videochat_issue_session_for_call_access(
        $pdo,
        $accessId,
        static fn (): string => $sessionId,
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-access-anonymous-temp-rights-contract'],
        $options
    );
}

function videochat_iam_anonymous_temp_rights_guest_list_count(PDO $pdo, string $callId): int
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

function videochat_iam_anonymous_temp_rights_participant(PDO $pdo, string $callId, int $userId): ?array
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

function videochat_iam_anonymous_temp_rights_assert_open_binding(
    PDO $pdo,
    string $label,
    string $accessId,
    string $sessionId,
    string $callId,
    int $expectedUserId
): void {
    $link = videochat_fetch_call_access_link($pdo, $accessId);
    videochat_iam_anonymous_temp_rights_assert(is_array($link), "{$label}: open link should remain persisted");
    videochat_iam_anonymous_temp_rights_assert(videochat_call_access_link_kind($link) === 'open', "{$label}: link kind should stay open");
    videochat_iam_anonymous_temp_rights_assert(($link['participant_user_id'] ?? null) === null, "{$label}: open link must not gain participant_user_id");
    videochat_iam_anonymous_temp_rights_assert(trim((string) ($link['participant_email'] ?? '')) === '', "{$label}: open link must not gain participant_email");
    videochat_iam_anonymous_temp_rights_assert(trim((string) ($link['consumed_at'] ?? '')) === '', "{$label}: open link must stay reusable");

    $binding = videochat_fetch_call_access_session_binding($pdo, $sessionId);
    videochat_iam_anonymous_temp_rights_assert(is_array($binding), "{$label}: call-access session binding should exist");
    videochat_iam_anonymous_temp_rights_assert((string) ($binding['access_id'] ?? '') === $accessId, "{$label}: binding access mismatch");
    videochat_iam_anonymous_temp_rights_assert((string) ($binding['call_id'] ?? '') === $callId, "{$label}: binding call mismatch");
    videochat_iam_anonymous_temp_rights_assert((int) ($binding['user_id'] ?? 0) === $expectedUserId, "{$label}: binding user mismatch");
    videochat_iam_anonymous_temp_rights_assert((string) ($binding['link_kind'] ?? '') === 'open', "{$label}: session binding must stay open-link kind");
}

function videochat_iam_anonymous_temp_rights_auth_connection(
    PDO $pdo,
    callable $openDatabase,
    string $sessionId,
    string $callId,
    string $connectionId
): array {
    $auth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/ws?session=' . rawurlencode($sessionId) . '&room=' . rawurlencode($callId) . '&call_id=' . rawurlencode($callId),
            'headers' => ['Authorization' => 'Bearer ' . $sessionId],
        ],
        'websocket'
    );
    videochat_iam_anonymous_temp_rights_assert((bool) ($auth['ok'] ?? false), "{$connectionId}: session should authenticate");

    $resolution = videochat_realtime_resolve_connection_rooms($auth, $callId, $openDatabase, $callId);
    videochat_iam_anonymous_temp_rights_assert((bool) ($resolution['ok'] ?? false), "{$connectionId}: room resolution should succeed");
    videochat_iam_anonymous_temp_rights_assert((string) ($resolution['initial_room_id'] ?? '') === videochat_realtime_waiting_room_id(), "{$connectionId}: anonymous/open user should start in lobby");
    videochat_iam_anonymous_temp_rights_assert((string) ($resolution['pending_room_id'] ?? '') === $callId, "{$connectionId}: pending room should stay call-bound");

    $connection = videochat_presence_connection_descriptor(
        (array) ($auth['user'] ?? []),
        $sessionId,
        $connectionId,
        'socket-' . $connectionId,
        (string) ($resolution['initial_room_id'] ?? videochat_realtime_waiting_room_id())
    );
    $connection['requested_room_id'] = (string) ($resolution['requested_room_id'] ?? '');
    $connection['pending_room_id'] = (string) ($resolution['pending_room_id'] ?? '');
    $connection['requested_call_id'] = $callId;

    return videochat_realtime_connection_with_call_context($connection, $openDatabase);
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-access-anonymous-temp-rights-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-call-access-anonymous-temp-rights-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);
    $openDatabase = static function () use ($pdo): PDO {
        return $pdo;
    };

    $userRoleId = videochat_iam_anonymous_temp_rights_role_id($pdo, 'user');
    videochat_iam_anonymous_temp_rights_assert($userRoleId > 0, 'expected user role');

    $unique = bin2hex(random_bytes(5));
    $tenantId = videochat_iam_anonymous_temp_rights_create_tenant($pdo, $unique);
    $organizationId = videochat_iam_anonymous_temp_rights_create_organization($pdo, $tenantId, $unique);
    $ownerUserId = videochat_iam_anonymous_temp_rights_create_user($pdo, $userRoleId, 'anon-temp-owner-' . $unique . '@example.test', 'IAM Temporary Rights Owner');
    $standardUserId = videochat_iam_anonymous_temp_rights_create_user($pdo, $userRoleId, 'anon-temp-user-' . $unique . '@example.test', 'IAM Temporary Rights User');
    videochat_iam_anonymous_temp_rights_attach_organization($pdo, $tenantId, $organizationId, $ownerUserId, 'member');
    videochat_iam_anonymous_temp_rights_attach_organization($pdo, $tenantId, $organizationId, $standardUserId, 'member');

    $callId = videochat_iam_anonymous_temp_rights_create_invite_only_call($pdo, $ownerUserId, $tenantId);
    $accessId = videochat_iam_anonymous_temp_rights_insert_open_link($pdo, $tenantId, $callId, $ownerUserId);
    $guestListBefore = videochat_iam_anonymous_temp_rights_guest_list_count($pdo, $callId);
    $guestUsersBefore = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE 'guest+%@videochat.local'")->fetchColumn();

    $loggedInSession = videochat_iam_anonymous_temp_rights_issue($pdo, $accessId, 'sess_anon_temp_rights_logged_in', [
        'authenticated_user_id' => $standardUserId,
        'authenticated_session_id' => 'browser_anon_temp_rights_logged_in',
        'verified_user_id' => $standardUserId,
        'verified_session_id' => 'browser_anon_temp_rights_logged_in',
        'guest_name' => 'Ignored Logged In Guest Name',
    ]);
    videochat_iam_anonymous_temp_rights_assert((bool) ($loggedInSession['ok'] ?? false), 'logged-in anonymous/open session should issue');
    videochat_iam_anonymous_temp_rights_assert((int) (($loggedInSession['user'] ?? [])['id'] ?? 0) === $standardUserId, 'logged-in anonymous/open link should keep the account user');
    videochat_iam_anonymous_temp_rights_assert((bool) (($loggedInSession['user'] ?? [])['is_guest'] ?? true) === false, 'logged-in anonymous/open link must not create a guest identity');
    videochat_iam_anonymous_temp_rights_assert(
        (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE 'guest+%@videochat.local'")->fetchColumn() === $guestUsersBefore,
        'logged-in anonymous/open link must not create a temporary user'
    );
    videochat_iam_anonymous_temp_rights_assert(
        videochat_iam_anonymous_temp_rights_guest_list_count($pdo, $callId) === $guestListBefore,
        'logged-in anonymous/open link must not add a guest-list entry'
    );
    $loggedInParticipant = videochat_iam_anonymous_temp_rights_participant($pdo, $callId, $standardUserId);
    videochat_iam_anonymous_temp_rights_assert($loggedInParticipant === null, 'logged-in anonymous/open link must not create a participant row before queueing');
    videochat_iam_anonymous_temp_rights_assert_open_binding($pdo, 'logged-in anonymous/open link', $accessId, 'sess_anon_temp_rights_logged_in', $callId, $standardUserId);

    $impersonatingGuestSession = videochat_iam_anonymous_temp_rights_issue($pdo, $accessId, 'sess_anon_temp_rights_owner_name', [
        'guest_name' => 'IAM Temporary Rights Owner',
    ]);
    videochat_iam_anonymous_temp_rights_assert((bool) ($impersonatingGuestSession['ok'] ?? false), 'owner-name anonymous guest session should issue');
    $impersonatingGuestId = (int) (($impersonatingGuestSession['user'] ?? [])['id'] ?? 0);
    videochat_iam_anonymous_temp_rights_assert($impersonatingGuestId > 0 && $impersonatingGuestId !== $ownerUserId, 'owner-name guest must be a separate temporary user');
    videochat_iam_anonymous_temp_rights_assert((bool) (($impersonatingGuestSession['user'] ?? [])['is_guest'] ?? false), 'owner-name guest should be marked as guest');
    videochat_iam_anonymous_temp_rights_assert((string) (($impersonatingGuestSession['user'] ?? [])['role'] ?? '') === 'user', 'owner-name guest should keep normal user role');
    $impersonatingParticipant = videochat_iam_anonymous_temp_rights_participant($pdo, $callId, $impersonatingGuestId);
    videochat_iam_anonymous_temp_rights_assert($impersonatingParticipant === null, 'owner-name guest should not create a participant row before queueing');
    videochat_iam_anonymous_temp_rights_assert_open_binding($pdo, 'owner-name anonymous guest', $accessId, 'sess_anon_temp_rights_owner_name', $callId, $impersonatingGuestId);

    $directDecision = videochat_decide_call_access_for_user($pdo, $callId, $impersonatingGuestId, 'user', $tenantId);
    videochat_iam_anonymous_temp_rights_assert(!(bool) ($directDecision['allowed'] ?? true), 'display-name spoof must not gain direct call access');
    videochat_iam_anonymous_temp_rights_assert((string) ($directDecision['source'] ?? '') === 'none', 'display-name spoof denial source mismatch');
    $directJoin = videochat_user_can_direct_join_call($pdo, $callId, $impersonatingGuestId, 'user', $tenantId);
    videochat_iam_anonymous_temp_rights_assert(!(bool) ($directJoin['ok'] ?? true), 'display-name spoof must not gain guest-list direct join');
    videochat_iam_anonymous_temp_rights_assert((string) ($directJoin['reason'] ?? '') === 'not_on_guest_list', 'display-name spoof direct-join denial reason mismatch');

    $secondGuestSession = videochat_iam_anonymous_temp_rights_issue($pdo, $accessId, 'sess_anon_temp_rights_second_guest', [
        'guest_name' => 'IAM Temporary Rights Second Guest',
    ]);
    videochat_iam_anonymous_temp_rights_assert((bool) ($secondGuestSession['ok'] ?? false), 'second anonymous guest session should issue');
    $secondGuestId = (int) (($secondGuestSession['user'] ?? [])['id'] ?? 0);
    videochat_iam_anonymous_temp_rights_assert($secondGuestId > 0 && $secondGuestId !== $impersonatingGuestId, 'same anonymous link must allocate separate temporary users');
    $secondParticipant = videochat_iam_anonymous_temp_rights_participant($pdo, $callId, $secondGuestId);
    videochat_iam_anonymous_temp_rights_assert($secondParticipant === null, 'second anonymous guest should not create a participant row before queueing');
    videochat_iam_anonymous_temp_rights_assert_open_binding($pdo, 'second anonymous guest', $accessId, 'sess_anon_temp_rights_second_guest', $callId, $secondGuestId);

    $impersonatingConnection = videochat_iam_anonymous_temp_rights_auth_connection(
        $pdo,
        $openDatabase,
        'sess_anon_temp_rights_owner_name',
        $callId,
        'conn-anon-temp-rights-owner-name'
    );
    $secondConnection = videochat_iam_anonymous_temp_rights_auth_connection(
        $pdo,
        $openDatabase,
        'sess_anon_temp_rights_second_guest',
        $callId,
        'conn-anon-temp-rights-second-guest'
    );
    videochat_iam_anonymous_temp_rights_assert(
        videochat_realtime_mark_call_participant_pending_for_queue($openDatabase, $impersonatingConnection),
        'owner-name anonymous guest should create a pending row only when queueing'
    );
    videochat_iam_anonymous_temp_rights_assert(
        videochat_realtime_mark_call_participant_pending_for_queue($openDatabase, $secondConnection),
        'second anonymous guest should create a pending row only when queueing'
    );
    $impersonatingParticipant = videochat_iam_anonymous_temp_rights_participant($pdo, $callId, $impersonatingGuestId);
    videochat_iam_anonymous_temp_rights_assert(is_array($impersonatingParticipant), 'owner-name queued guest should have a lobby participant row');
    videochat_iam_anonymous_temp_rights_assert((string) ($impersonatingParticipant['display_name'] ?? '') === 'IAM Temporary Rights Owner', 'display-name spoof should be stored only as display text');
    videochat_iam_anonymous_temp_rights_assert((string) ($impersonatingParticipant['call_role'] ?? '') === 'participant', 'display-name spoof must not grant owner or moderator role');
    videochat_iam_anonymous_temp_rights_assert((string) ($impersonatingParticipant['invite_state'] ?? '') === 'pending', 'display-name spoof should remain pending');
    $secondParticipant = videochat_iam_anonymous_temp_rights_participant($pdo, $callId, $secondGuestId);
    videochat_iam_anonymous_temp_rights_assert(is_array($secondParticipant), 'second queued guest should have a separate participant row');
    videochat_iam_anonymous_temp_rights_assert((string) ($secondParticipant['invite_state'] ?? '') === 'pending', 'second queued guest should remain pending');

    $pendingGuestRows = $pdo->prepare(
        <<<'SQL'
SELECT COUNT(*)
FROM call_participants
WHERE call_id = :call_id
  AND user_id IN (:guest_a, :guest_b)
  AND source = 'internal'
  AND call_role = 'participant'
  AND invite_state = 'pending'
SQL
    );
    $pendingGuestRows->execute([
        ':call_id' => $callId,
        ':guest_a' => $impersonatingGuestId,
        ':guest_b' => $secondGuestId,
    ]);
    videochat_iam_anonymous_temp_rights_assert((int) $pendingGuestRows->fetchColumn() === 2, 'same anonymous link must keep separate pending participants');
    videochat_iam_anonymous_temp_rights_assert(
        videochat_iam_anonymous_temp_rights_guest_list_count($pdo, $callId) === $guestListBefore,
        'anonymous/open sessions must not add invited or allowed guest-list entries'
    );

    $impersonatingConnection = videochat_realtime_connection_with_call_context($impersonatingConnection, $openDatabase);
    videochat_iam_anonymous_temp_rights_assert((string) ($impersonatingConnection['call_role'] ?? '') === 'participant', 'display-name spoof connection call role mismatch');
    videochat_iam_anonymous_temp_rights_assert((string) ($impersonatingConnection['effective_call_role'] ?? '') === 'participant', 'display-name spoof connection effective role mismatch');
    videochat_iam_anonymous_temp_rights_assert(!(bool) ($impersonatingConnection['can_moderate_call'] ?? true), 'display-name spoof connection must not moderate');
    videochat_iam_anonymous_temp_rights_assert(!(bool) ($impersonatingConnection['can_manage_call_owner'] ?? true), 'display-name spoof connection must not manage owner');

    $allowCommand = videochat_lobby_decode_client_frame(json_encode([
        'type' => 'lobby/allow',
        'room_id' => $callId,
        'target_user_id' => $secondGuestId,
    ], JSON_UNESCAPED_SLASHES));
    videochat_iam_anonymous_temp_rights_assert((bool) ($allowCommand['ok'] ?? false), 'lobby allow command should decode');
    $authority = videochat_realtime_authorize_lobby_moderation_command($impersonatingConnection, $allowCommand, $callId, $openDatabase);
    videochat_iam_anonymous_temp_rights_assert(!(bool) ($authority['ok'] ?? true), 'display-name spoof must not authorize lobby admission');
    videochat_iam_anonymous_temp_rights_assert((string) ($authority['error'] ?? '') === 'forbidden', 'display-name spoof lobby denial reason mismatch');

    @unlink($databasePath);
    fwrite(STDOUT, "[call-access-anonymous-temp-rights-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[call-access-anonymous-temp-rights-contract] ERROR: ' . $error->getMessage() . "\n");
    fwrite(STDERR, $error->getTraceAsString() . "\n");
    exit(1);
} finally {
    if (isset($databasePath) && is_string($databasePath) && is_file($databasePath)) {
        @unlink($databasePath);
    }
}
