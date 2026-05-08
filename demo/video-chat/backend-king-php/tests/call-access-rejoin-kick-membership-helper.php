<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../domain/tenancy/tenant_administration.php';
require_once __DIR__ . '/../domain/realtime/realtime_lobby.php';
require_once __DIR__ . '/../http/module_realtime.php';

function videochat_iam_rejoin_contract_assert(bool $condition, string $message, string $label): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[{$label}] FAIL: {$message}\n");
    exit(1);
}

function videochat_iam_rejoin_contract_skip_without_sqlite(string $label): void
{
    if (extension_loaded('pdo_sqlite')) {
        return;
    }

    fwrite(STDOUT, "[{$label}] SKIP: pdo_sqlite unavailable\n");
    exit(0);
}

/**
 * @return array{0: string, 1: PDO}
 */
function videochat_iam_rejoin_contract_bootstrap_database(string $prefix): array
{
    $databasePath = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);
    videochat_bootstrap_sqlite($databasePath);
    return [$databasePath, videochat_open_sqlite_pdo($databasePath)];
}

/**
 * @return array{tenant_id: int, admin_user_id: int, default_user_id: int, organization_id: int, organization_public_id: string}
 */
function videochat_iam_rejoin_contract_fixture_ids(PDO $pdo, string $label): array
{
    $tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    $adminUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $defaultUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('user@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $organizationRow = $pdo->query("SELECT id, public_id FROM organizations WHERE tenant_id = {$tenantId} ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    videochat_iam_rejoin_contract_assert($tenantId > 0, 'expected default tenant', $label);
    videochat_iam_rejoin_contract_assert($adminUserId > 0, 'expected seeded admin user', $label);
    videochat_iam_rejoin_contract_assert($defaultUserId > 0, 'expected seeded default user', $label);
    videochat_iam_rejoin_contract_assert(is_array($organizationRow), 'expected seeded organization', $label);

    return [
        'tenant_id' => $tenantId,
        'admin_user_id' => $adminUserId,
        'default_user_id' => $defaultUserId,
        'organization_id' => (int) ($organizationRow['id'] ?? 0),
        'organization_public_id' => (string) ($organizationRow['public_id'] ?? ''),
    ];
}

function videochat_iam_rejoin_contract_seed_user(
    PDO $pdo,
    string $email,
    string $displayName,
    int $tenantId,
    int $organizationId = 0,
    string $tenantRole = 'member',
    string $organizationRole = 'member'
): int {
    $roleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'user' LIMIT 1")->fetchColumn();
    if ($roleId <= 0) {
        throw new RuntimeException('expected user role fixture');
    }

    $now = gmdate('c');
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, date_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', '24h', 'dmy_dot', 'dark', :updated_at)
SQL
    );
    $insert->execute([
        ':email' => strtolower($email),
        ':display_name' => $displayName,
        ':password_hash' => password_hash('iam-rejoin-contract', PASSWORD_DEFAULT),
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
VALUES(:tenant_id, :user_id, :membership_role, 'active', '{}', 1, :created_at, :updated_at)
SQL
    );
    $tenantInsert->execute([
        ':tenant_id' => $tenantId,
        ':user_id' => $userId,
        ':membership_role' => videochat_tenant_normalize_role($tenantRole),
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    if ($organizationId > 0) {
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
    }

    return $userId;
}

/**
 * @return array{call_id: string, room_id: string}
 */
function videochat_iam_rejoin_contract_create_active_call(
    PDO $pdo,
    int $ownerUserId,
    array $participantUserIds,
    int $tenantId,
    string $title,
    string $accessMode = 'invite_only'
): array {
    $created = videochat_create_call($pdo, $ownerUserId, [
        'title' => $title,
        'access_mode' => videochat_normalize_call_access_mode($accessMode),
        'starts_at' => gmdate('c', time() - 60),
        'ends_at' => gmdate('c', time() + 3600),
        'internal_participant_user_ids' => $participantUserIds,
        'external_participants' => [],
    ], $tenantId);

    if (!(bool) ($created['ok'] ?? false)) {
        throw new RuntimeException('could not create active call: ' . (string) ($created['reason'] ?? 'unknown'));
    }

    $callId = (string) (($created['call'] ?? [])['id'] ?? '');
    $roomId = (string) (($created['call'] ?? [])['room_id'] ?? '');
    if ($callId === '' || $roomId === '') {
        throw new RuntimeException('active call is missing ids');
    }

    return ['call_id' => $callId, 'room_id' => $roomId];
}

function videochat_iam_rejoin_contract_set_invite_state(PDO $pdo, string $callId, int $userId, string $state): void
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

function videochat_iam_rejoin_contract_issue_user_session(
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
    videochat_iam_rejoin_contract_assert((bool) ($session['ok'] ?? false), 'expected user session issue to succeed', $label);

    $auth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/ws?session=' . rawurlencode($sessionId),
            'headers' => ['Authorization' => 'Bearer ' . $sessionId],
        ],
        'websocket'
    );
    videochat_iam_rejoin_contract_assert((bool) ($auth['ok'] ?? false), 'expected issued session to authenticate', $label);

    return $auth;
}

function videochat_iam_rejoin_contract_issue_open_guest_session(
    PDO $pdo,
    string $callId,
    int $ownerUserId,
    int $tenantId,
    string $sessionId,
    string $guestName,
    string $label
): array {
    $link = videochat_create_call_access_link_for_user(
        $pdo,
        $callId,
        $ownerUserId,
        'admin',
        ['link_kind' => 'open'],
        $tenantId
    );
    videochat_iam_rejoin_contract_assert((bool) ($link['ok'] ?? false), 'open guest access link should be created', $label);
    $accessId = (string) (($link['access_link'] ?? [])['id'] ?? '');
    videochat_iam_rejoin_contract_assert($accessId !== '', 'open guest access link id should be present', $label);

    $issued = videochat_issue_session_for_call_access(
        $pdo,
        $accessId,
        static fn (): string => $sessionId,
        ['client_ip' => '127.0.0.1', 'user_agent' => $label],
        ['guest_name' => $guestName]
    );
    videochat_iam_rejoin_contract_assert((bool) ($issued['ok'] ?? false), 'open guest call-access session should issue', $label);
    videochat_iam_rejoin_contract_assert((bool) (($issued['user'] ?? [])['is_guest'] ?? false), 'open guest session should use a temporary guest user', $label);

    $auth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/ws?session=' . rawurlencode($sessionId),
            'headers' => ['Authorization' => 'Bearer ' . $sessionId],
        ],
        'websocket'
    );
    videochat_iam_rejoin_contract_assert((bool) ($auth['ok'] ?? false), 'open guest call-access session should authenticate', $label);

    return [
        'auth' => $auth,
        'session' => $issued['session'] ?? [],
        'user' => $issued['user'] ?? [],
        'access_link' => $issued['access_link'] ?? [],
        'call' => $issued['call'] ?? [],
    ];
}

function videochat_iam_rejoin_contract_connection(
    PDO $pdo,
    array &$presenceState,
    string $roomId,
    string $callId,
    int $userId,
    string $displayName,
    string $globalRole,
    string $suffix,
    int $tenantId,
    bool $markJoined = true,
    string $sessionId = ''
): array {
    $connection = videochat_presence_connection_descriptor(
        [
            'id' => $userId,
            'display_name' => $displayName,
            'role' => $globalRole,
        ],
        $sessionId !== '' ? $sessionId : ('sess-' . $suffix),
        'conn-' . $suffix,
        'socket-' . $suffix,
        $roomId
    );
    $connection['tenant_id'] = $tenantId;
    $connection['requested_call_id'] = $callId;
    $connection = videochat_realtime_connection_with_call_context($connection, static fn (): PDO => $pdo);
    $join = videochat_presence_join_room($presenceState, $connection, $roomId);
    $connection = (array) ($join['connection'] ?? $connection);
    if ($markJoined) {
        videochat_realtime_mark_call_participant_joined(static fn (): PDO => $pdo, $connection);
    } else {
        videochat_realtime_touch_call_presence(static fn (): PDO => $pdo, $connection);
    }

    return $connection;
}

function videochat_iam_rejoin_contract_waiting_connection(
    PDO $pdo,
    array &$presenceState,
    string $roomId,
    string $callId,
    int $userId,
    string $displayName,
    string $suffix,
    int $tenantId,
    string $sessionId
): array {
    $connection = videochat_presence_connection_descriptor(
        [
            'id' => $userId,
            'display_name' => $displayName,
            'role' => 'user',
            'tenant' => ['id' => $tenantId],
        ],
        $sessionId,
        'conn-' . $suffix,
        'socket-' . $suffix,
        videochat_realtime_waiting_room_id()
    );
    $connection['tenant_id'] = $tenantId;
    $connection['requested_room_id'] = $roomId;
    $connection['pending_room_id'] = $roomId;
    $connection['requested_call_id'] = $callId;
    $connection = videochat_realtime_connection_with_call_context($connection, static fn (): PDO => $pdo);
    $join = videochat_presence_join_room($presenceState, $connection, videochat_realtime_waiting_room_id());
    $connection = (array) ($join['connection'] ?? $connection);
    $connection['requested_room_id'] = $roomId;
    $connection['pending_room_id'] = $roomId;
    $connection['requested_call_id'] = $callId;
    $connection = videochat_realtime_connection_with_call_context($connection, static fn (): PDO => $pdo);
    $presenceState['connections'][(string) ($connection['connection_id'] ?? ('conn-' . $suffix))] = $connection;

    return $connection;
}

function videochat_iam_rejoin_contract_lobby_command(string $type, string $roomId, int $targetUserId, string $label): array
{
    $command = videochat_lobby_decode_client_frame(json_encode([
        'type' => $type,
        'room_id' => $roomId,
        'target_user_id' => $targetUserId,
    ], JSON_UNESCAPED_SLASHES));
    videochat_iam_rejoin_contract_assert((bool) ($command['ok'] ?? false), "{$type} should decode", $label);
    return $command;
}

function videochat_iam_rejoin_contract_apply_lobby_command(
    array &$lobbyState,
    array &$presenceState,
    array $connection,
    callable $openDatabase,
    string $type,
    string $roomId,
    int $targetUserId,
    string $label
): array {
    $frames = [];
    $sender = static function (mixed $socket, array $payload) use (&$frames): bool {
        $key = is_scalar($socket) ? (string) $socket : 'unknown';
        $frames[$key] ??= [];
        $frames[$key][] = $payload;
        return true;
    };

    videochat_realtime_sync_lobby_room_from_database(
        $lobbyState,
        $openDatabase,
        $roomId,
        videochat_realtime_connection_call_id($connection),
        null,
        videochat_realtime_connection_tenant_id($connection)
    );
    $result = videochat_lobby_apply_command(
        $lobbyState,
        $presenceState,
        $connection,
        videochat_iam_rejoin_contract_lobby_command($type, $roomId, $targetUserId, $label),
        $sender
    );
    if ((bool) ($result['ok'] ?? false)) {
        videochat_realtime_apply_successful_lobby_command(
            $result,
            $lobbyState,
            $presenceState,
            $connection,
            $openDatabase
        );
    }

    $result['frames'] = $frames;
    return $result;
}

function videochat_iam_rejoin_contract_queue_user(array &$lobbyState, string $roomId, int $userId, string $displayName): void
{
    videochat_lobby_ensure_room_state($lobbyState, $roomId);
    $lobbyState['rooms'][$roomId]['queued_by_user'][$userId] = [
        'user_id' => $userId,
        'display_name' => $displayName,
        'role' => 'user',
        'requested_unix_ms' => 1_778_000_000_000,
        'requested_at' => '2026-05-08T12:00:00+00:00',
    ];
}

function videochat_iam_rejoin_contract_admit_user(array &$lobbyState, string $roomId, int $userId, string $displayName): void
{
    videochat_lobby_ensure_room_state($lobbyState, $roomId);
    $lobbyState['rooms'][$roomId]['admitted_by_user'][$userId] = [
        'user_id' => $userId,
        'display_name' => $displayName,
        'role' => 'user',
        'admitted_unix_ms' => 1_778_000_001_000,
        'admitted_at' => '2026-05-08T12:00:01+00:00',
        'admitted_by' => ['user_id' => 0, 'display_name' => 'setup', 'role' => 'user'],
    ];
}

function videochat_iam_rejoin_contract_disable_tenant_membership(PDO $pdo, int $tenantId, int $userId): void
{
    $updatedAt = gmdate('c');
    foreach (['group_memberships', 'organization_memberships', 'tenant_memberships'] as $table) {
        $statement = $pdo->prepare("UPDATE {$table} SET status = 'disabled', updated_at = :updated_at WHERE tenant_id = :tenant_id AND user_id = :user_id");
        $statement->execute([
            ':updated_at' => $updatedAt,
            ':tenant_id' => $tenantId,
            ':user_id' => $userId,
        ]);
    }
}

function videochat_iam_rejoin_contract_disable_organization_membership(PDO $pdo, int $tenantId, int $organizationId, int $userId): void
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

function videochat_iam_rejoin_contract_participant_left_at(PDO $pdo, string $callId, int $userId): string
{
    $statement = $pdo->prepare('SELECT left_at FROM call_participants WHERE call_id = :call_id AND user_id = :user_id LIMIT 1');
    $statement->execute([':call_id' => $callId, ':user_id' => $userId]);
    return trim((string) ($statement->fetchColumn() ?: ''));
}

function videochat_iam_rejoin_contract_participant_invite_state(PDO $pdo, string $callId, int $userId): string
{
    $statement = $pdo->prepare('SELECT invite_state FROM call_participants WHERE call_id = :call_id AND user_id = :user_id LIMIT 1');
    $statement->execute([':call_id' => $callId, ':user_id' => $userId]);
    return videochat_realtime_normalize_call_invite_state($statement->fetchColumn() ?: 'invited');
}
