<?php

declare(strict_types=1);

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDOUT, "[admin-video-operations-contract] SKIP: pdo_sqlite is not available\n");
    exit(0);
}

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../http/module_operations.php';

function videochat_admin_video_ops_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[admin-video-operations-contract] FAIL: {$message}\n");
    exit(1);
}

/** @return array<string, mixed> */
function videochat_admin_video_ops_decode(array $response): array
{
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-admin-video-ops-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $adminUserId = (int) $pdo->query(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE roles.slug = 'admin'
ORDER BY users.id ASC
LIMIT 1
SQL
    )->fetchColumn();
    videochat_admin_video_ops_assert($adminUserId > 0, 'expected seeded admin user');

    $standardUserId = (int) $pdo->query(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE roles.slug = 'user'
ORDER BY users.id ASC
LIMIT 1
SQL
    )->fetchColumn();
    videochat_admin_video_ops_assert($standardUserId > 0, 'expected seeded standard user');

    $userRoleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'user' LIMIT 1")->fetchColumn();
    videochat_admin_video_ops_assert($userRoleId > 0, 'expected seeded user role');
    $insertExternalUser = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', '24h', 'dark', :updated_at)
SQL
    );
    $insertExternalUser->execute([
        ':email' => 'guest-beta@example.test',
        ':display_name' => 'Guest Beta',
        ':password_hash' => password_hash('guest-beta-contract', PASSWORD_DEFAULT),
        ':role_id' => $userRoleId,
        ':updated_at' => '2026-04-21T11:00:00Z',
    ]);
    $externalUserId = (int) $pdo->lastInsertId();
    videochat_admin_video_ops_assert($externalUserId > 0, 'expected external test user');

    $insertCall = $pdo->prepare(
        <<<'SQL'
INSERT INTO calls(id, room_id, title, owner_user_id, status, starts_at, ends_at, created_at, updated_at)
VALUES(:id, :room_id, :title, :owner_user_id, :status, :starts_at, :ends_at, :created_at, :updated_at)
SQL
    );

    $calls = [
        ['call-ops-alpha', 'room-ops-alpha', 'Operations Alpha', $adminUserId, 'scheduled', '2026-04-21T09:55:00Z', '2026-04-21T12:30:00Z'],
        ['call-ops-beta', 'room-ops-beta', 'Operations Beta', $standardUserId, 'active', '2026-04-21T11:00:00Z', '2026-04-21T12:45:00Z'],
        ['call-ops-idle', 'room-ops-idle', 'Idle Assigned Call', $adminUserId, 'scheduled', '2026-04-21T12:30:00Z', '2026-04-21T13:00:00Z'],
        ['call-ops-cancelled', 'room-ops-cancelled', 'Cancelled With Stale Presence', $adminUserId, 'cancelled', '2026-04-21T10:00:00Z', '2026-04-21T10:30:00Z'],
        ['call-ops-ended', 'room-ops-ended', 'Ended With Stale Presence', $adminUserId, 'ended', '2026-04-21T10:00:00Z', '2026-04-21T10:30:00Z'],
    ];

    $insertRoom = $pdo->prepare(
        <<<'SQL'
INSERT INTO rooms(id, name, visibility, status, created_by_user_id, created_at, updated_at)
VALUES(:id, :name, 'private', 'active', :created_by_user_id, :created_at, :updated_at)
SQL
    );
    foreach ($calls as [$id, $roomId, $title, $ownerUserId, $_status, $startsAt]) {
        $insertRoom->execute([
            ':id' => $roomId,
            ':name' => $title . ' Room',
            ':created_by_user_id' => $ownerUserId,
            ':created_at' => $startsAt,
            ':updated_at' => $startsAt,
        ]);
    }

    foreach ($calls as [$id, $roomId, $title, $ownerUserId, $status, $startsAt, $endsAt]) {
        $insertCall->execute([
            ':id' => $id,
            ':room_id' => $roomId,
            ':title' => $title,
            ':owner_user_id' => $ownerUserId,
            ':status' => $status,
            ':starts_at' => $startsAt,
            ':ends_at' => $endsAt,
            ':created_at' => $startsAt,
            ':updated_at' => $startsAt,
        ]);
    }

    $insertParticipant = $pdo->prepare(
        <<<'SQL'
INSERT INTO call_participants(call_id, user_id, email, display_name, source, call_role, invite_state, joined_at, left_at)
VALUES(:call_id, :user_id, :email, :display_name, :source, :call_role, :invite_state, :joined_at, :left_at)
SQL
    );
    $participants = [
        ['call-ops-alpha', $adminUserId, 'admin@intelligent-intern.com', 'Platform Admin', 'internal', 'owner', 'allowed', '2026-04-21T10:00:00Z', null],
        ['call-ops-alpha', $standardUserId, 'user@intelligent-intern.com', 'Call User', 'internal', 'participant', 'allowed', '2026-04-21T10:05:00Z', null],
        ['call-ops-alpha', null, 'guest-alpha@example.test', 'Guest Alpha', 'external', 'participant', 'invited', null, null],
        ['call-ops-beta', $externalUserId, 'guest-beta@example.test', 'Guest Beta', 'external', 'participant', 'allowed', '2026-04-21T11:30:00Z', null],
        ['call-ops-beta', $standardUserId, 'left-beta@example.test', 'Left Beta', 'internal', 'participant', 'allowed', '2026-04-21T11:10:00Z', '2026-04-21T11:15:00Z'],
        ['call-ops-idle', $standardUserId, 'idle@example.test', 'Idle User', 'internal', 'participant', 'allowed', '2026-04-21T11:55:00Z', null],
        ['call-ops-cancelled', $standardUserId, 'cancelled@example.test', 'Cancelled User', 'internal', 'participant', 'allowed', '2026-04-21T10:00:00Z', null],
        ['call-ops-ended', $standardUserId, 'ended@example.test', 'Ended User', 'internal', 'participant', 'allowed', '2026-04-21T10:00:00Z', null],
    ];

    foreach ($participants as [$callId, $userId, $email, $displayName, $source, $callRole, $inviteState, $joinedAt, $leftAt]) {
        $insertParticipant->execute([
            ':call_id' => $callId,
            ':user_id' => $userId,
            ':email' => $email,
            ':display_name' => $displayName,
            ':source' => $source,
            ':call_role' => $callRole,
            ':invite_state' => $inviteState,
            ':joined_at' => $joinedAt,
            ':left_at' => $leftAt,
        ]);
    }

    videochat_realtime_presence_db_bootstrap($pdo);
    videochat_sfu_bootstrap($pdo);
    $brokerDatabasePath = sys_get_temp_dir() . '/videochat-admin-video-ops-broker-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($brokerDatabasePath)) {
        @unlink($brokerDatabasePath);
    }
    putenv('VIDEOCHAT_KING_DB_PATH=' . $databasePath);
    putenv('VIDEOCHAT_KING_SFU_BROKER_DB_PATH=' . $brokerDatabasePath);
    $brokerPdo = videochat_open_sqlite_pdo($brokerDatabasePath);
    videochat_sfu_bootstrap($brokerPdo);
    $snapshotNow = (int) strtotime('2026-04-21T12:00:00Z');
    $snapshotNowMs = $snapshotNow * 1000;
    $insertPresence = $pdo->prepare(
        <<<'SQL'
INSERT INTO realtime_presence_connections(connection_id, session_id, room_id, call_id, user_id, display_name, role, call_role, connected_at, last_seen_at_ms)
VALUES(:connection_id, :session_id, :room_id, :call_id, :user_id, :display_name, :role, :call_role, :connected_at, :last_seen_at_ms)
SQL
    );
    $presenceRows = [
        ['presence-alpha-admin', 'sess-alpha-admin', 'room-ops-alpha', 'call-ops-alpha', $adminUserId, 'Platform Admin', 'admin', 'owner', '2026-04-21T10:00:00Z', $snapshotNowMs],
        ['presence-alpha-user', 'sess-alpha-user', 'room-ops-alpha', 'call-ops-alpha', $standardUserId, 'Call User', 'user', 'participant', '2026-04-21T10:05:00Z', $snapshotNowMs - 1000],
        ['presence-beta-guest', 'sess-beta-guest', 'room-ops-beta', 'call-ops-beta', $externalUserId, 'Guest Beta', 'user', 'participant', '2026-04-21T11:30:00Z', $snapshotNowMs - 2000],
        ['presence-idle-stale', 'sess-idle-stale', 'room-ops-idle', 'call-ops-idle', $standardUserId, 'Idle User', 'user', 'participant', '2026-04-21T11:55:00Z', $snapshotNowMs - 120000],
        ['presence-cancelled-fresh', 'sess-cancelled', 'room-ops-cancelled', 'call-ops-cancelled', $standardUserId, 'Cancelled User', 'user', 'participant', '2026-04-21T10:00:00Z', $snapshotNowMs],
    ];
    foreach ($presenceRows as [$connectionId, $sessionId, $roomId, $callId, $userId, $displayName, $role, $callRole, $connectedAt, $lastSeenAtMs]) {
        $insertPresence->execute([
            ':connection_id' => $connectionId,
            ':session_id' => $sessionId,
            ':room_id' => $roomId,
            ':call_id' => $callId,
            ':user_id' => $userId,
            ':display_name' => $displayName,
            ':role' => $role,
            ':call_role' => $callRole,
            ':connected_at' => $connectedAt,
            ':last_seen_at_ms' => $lastSeenAtMs,
        ]);
    }

    $insertSfuPublisher = $pdo->prepare(
        <<<'SQL'
INSERT INTO sfu_publishers(room_id, publisher_id, user_id, user_name, updated_at_ms)
VALUES(:room_id, :publisher_id, :user_id, :user_name, :updated_at_ms)
SQL
    );
    $sfuPublishers = [
        ['room-ops-alpha', 'pub-alpha-admin', (string) $adminUserId, 'Platform Admin', $snapshotNowMs],
        ['room-ops-alpha', 'pub-alpha-user', (string) $standardUserId, 'Call User', $snapshotNowMs - 1000],
        ['room-ops-beta', 'pub-beta-guest', (string) $externalUserId, 'Guest Beta', $snapshotNowMs - 2000],
        ['room-ops-idle', 'pub-idle-stale', (string) $standardUserId, 'Idle User', $snapshotNowMs - 120000],
    ];
    foreach ($sfuPublishers as [$roomId, $publisherId, $userId, $userName, $updatedAtMs]) {
        $insertSfuPublisher->execute([
            ':room_id' => $roomId,
            ':publisher_id' => $publisherId,
            ':user_id' => $userId,
            ':user_name' => $userName,
            ':updated_at_ms' => $updatedAtMs,
        ]);
    }
    $insertBrokerSfuPublisher = $brokerPdo->prepare(
        <<<'SQL'
INSERT INTO sfu_publishers(room_id, publisher_id, user_id, user_name, updated_at_ms)
VALUES(:room_id, :publisher_id, :user_id, :user_name, :updated_at_ms)
SQL
    );
    $insertBrokerSfuPublisher->execute([
        ':room_id' => 'tenant:1:room:room-ops-beta',
        ':publisher_id' => 'pub-beta-admin-broker',
        ':user_id' => (string) $adminUserId,
        ':user_name' => 'Platform Admin',
        ':updated_at_ms' => $snapshotNowMs - 1000,
    ]);

    $snapshot = videochat_video_operations_snapshot($pdo, $snapshotNow);
    videochat_admin_video_ops_assert((string) ($snapshot['status'] ?? '') === 'ok', 'snapshot status mismatch');
    videochat_admin_video_ops_assert((int) (($snapshot['metrics'] ?? [])['live_calls'] ?? -1) === 2, 'live call metric must count only active presence calls');
    videochat_admin_video_ops_assert((int) (($snapshot['metrics'] ?? [])['concurrent_participants'] ?? -1) === 3, 'concurrent participant metric must count only fresh call presence rows');

    $runningCalls = (array) ($snapshot['running_calls'] ?? []);
    videochat_admin_video_ops_assert(count($runningCalls) === 2, 'running call list should contain two calls');
    videochat_admin_video_ops_assert((string) (($runningCalls[0] ?? [])['id'] ?? '') === 'call-ops-alpha', 'first live call should be alpha by running_since');
    videochat_admin_video_ops_assert((int) (($runningCalls[0]['live_participants'] ?? [])['total'] ?? 0) === 2, 'alpha live participants mismatch');
    videochat_admin_video_ops_assert((int) (($runningCalls[0]['assigned_participants'] ?? [])['total'] ?? 0) === 3, 'alpha assigned participants mismatch');
    videochat_admin_video_ops_assert((int) (($runningCalls[0]['sfu'] ?? [])['publisher_users'] ?? 0) === 2, 'alpha SFU publisher users mismatch');
    videochat_admin_video_ops_assert((int) (($runningCalls[0] ?? [])['uptime_seconds'] ?? 0) === 7200, 'alpha uptime mismatch');
    videochat_admin_video_ops_assert((string) (($runningCalls[0]['gossip'] ?? [])['scope'] ?? '') === 'call', 'alpha gossip map must be scoped to the running call');
    videochat_admin_video_ops_assert((string) (($runningCalls[0]['gossip'] ?? [])['topology_state'] ?? '') === 'spawned', 'alpha gossip topology must spawn from fresh call presence');
    videochat_admin_video_ops_assert((int) (($runningCalls[0]['gossip'] ?? [])['topology_peer_count'] ?? 0) === 2, 'alpha gossip topology peer count mismatch');
    $alphaTopologyByPeer = (array) (($runningCalls[0] ?? [])['gossip_topology_by_peer_id'] ?? []);
    videochat_admin_video_ops_assert(count($alphaTopologyByPeer) === 2, 'alpha must expose one gossip topology payload per fresh peer');
    $alphaFirstTopology = (array) reset($alphaTopologyByPeer);
    videochat_admin_video_ops_assert((string) ($alphaFirstTopology['topology_feature'] ?? '') === 'room_state', 'alpha gossip topology must use room-state feature hints');
    videochat_admin_video_ops_assert(is_array($alphaFirstTopology['admitted_peers'] ?? null), 'alpha gossip topology must expose admitted peers');
    videochat_admin_video_ops_assert(is_array($alphaFirstTopology['assigned_neighbors'] ?? null), 'alpha gossip topology must expose assigned neighbor links');
    videochat_admin_video_ops_assert((int) (($runningCalls[1]['live_participants'] ?? [])['external'] ?? 0) === 1, 'beta live external participants mismatch');
    videochat_admin_video_ops_assert((int) (($runningCalls[1]['sfu'] ?? [])['publishers'] ?? 0) === 2, 'beta SFU publishers must include broker database rows');
    videochat_admin_video_ops_assert((int) (($runningCalls[1]['sfu'] ?? [])['publisher_users'] ?? 0) === 2, 'beta SFU publisher users must include broker database rows');
    videochat_admin_video_ops_assert((string) (($runningCalls[1]['gossip'] ?? [])['lifecycle'] ?? '') === 'running', 'beta gossip map lifecycle must follow the live call');
    videochat_admin_video_ops_assert((int) (($runningCalls[1]['gossip'] ?? [])['topology_peer_count'] ?? 0) === 1, 'beta gossip topology peer count mismatch');
    videochat_admin_video_ops_assert(!in_array('call-ops-idle', array_column($runningCalls, 'id'), true), 'idle call without fresh presence must not be live');

    $routeNowMs = time() * 1000;
    $refreshRoutePresence = $pdo->prepare(
        "UPDATE realtime_presence_connections SET last_seen_at_ms = :last_seen_at_ms WHERE call_id IN ('call-ops-alpha', 'call-ops-beta')"
    );
    $refreshRoutePresence->execute([':last_seen_at_ms' => $routeNowMs]);
    $refreshRouteSfu = $pdo->prepare(
        "UPDATE sfu_publishers SET updated_at_ms = :updated_at_ms WHERE room_id IN ('room-ops-alpha', 'room-ops-beta')"
    );
    $refreshRouteSfu->execute([':updated_at_ms' => $routeNowMs]);
    $refreshRouteBrokerSfu = $brokerPdo->prepare("UPDATE sfu_publishers SET updated_at_ms = :updated_at_ms");
    $refreshRouteBrokerSfu->execute([':updated_at_ms' => $routeNowMs]);

    $jsonResponse = static function (int $status, array $payload): array {
        return [
            'status' => $status,
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    };

    $errorResponse = static function (int $status, string $code, string $message, array $details = []) use ($jsonResponse): array {
        return $jsonResponse($status, [
            'status' => 'error',
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ],
            'time' => gmdate('c'),
        ]);
    };

    $openDatabase = static fn (): PDO => $pdo;

    $methodBlocked = videochat_handle_operations_routes(
        '/api/admin/video-operations',
        'POST',
        $jsonResponse,
        $errorResponse,
        $openDatabase
    );
    videochat_admin_video_ops_assert(is_array($methodBlocked), 'POST should be handled');
    videochat_admin_video_ops_assert((int) ($methodBlocked['status'] ?? 0) === 405, 'POST should be rejected with 405');

    $unrelated = videochat_handle_operations_routes(
        '/api/admin/infrastructure',
        'GET',
        $jsonResponse,
        $errorResponse,
        $openDatabase
    );
    videochat_admin_video_ops_assert($unrelated === null, 'unrelated route should not be handled');

    $routeNowMs = time() * 1000;
    $refreshPresence = $pdo->prepare(
        <<<'SQL'
UPDATE realtime_presence_connections
SET last_seen_at_ms = :last_seen_at_ms
WHERE connection_id IN ('presence-alpha-admin', 'presence-alpha-user', 'presence-beta-guest', 'presence-cancelled-fresh')
SQL
    );
    $refreshPresence->execute([':last_seen_at_ms' => $routeNowMs]);
    $refreshSfu = $pdo->prepare(
        <<<'SQL'
UPDATE sfu_publishers
SET updated_at_ms = :updated_at_ms
WHERE publisher_id IN ('pub-alpha-admin', 'pub-alpha-user', 'pub-beta-guest')
SQL
    );
    $refreshSfu->execute([':updated_at_ms' => $routeNowMs]);

    $response = videochat_handle_operations_routes(
        '/api/admin/video-operations',
        'GET',
        $jsonResponse,
        $errorResponse,
        $openDatabase
    );
    videochat_admin_video_ops_assert(is_array($response), 'GET should be handled');
    videochat_admin_video_ops_assert((int) ($response['status'] ?? 0) === 200, 'GET should return 200');
    $payload = videochat_admin_video_ops_decode($response);
    videochat_admin_video_ops_assert((int) (($payload['metrics'] ?? [])['live_calls'] ?? -1) === 2, 'route live call metric mismatch');
    videochat_admin_video_ops_assert((int) (($payload['metrics'] ?? [])['concurrent_participants'] ?? -1) === 3, 'route concurrent participant metric mismatch');

    @unlink($databasePath);
    fwrite(STDOUT, "[admin-video-operations-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[admin-video-operations-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
