<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/realtime/realtime_presence.php';
require_once __DIR__ . '/../domain/realtime/realtime_lobby.php';
require_once __DIR__ . '/../http/module_realtime.php';

function videochat_realtime_call_scope_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[realtime-call-scope-contract] FAIL: {$message}\n");
    exit(1);
}

/**
 * @return array<string, mixed>
 */
function videochat_realtime_call_scope_decode_response(array $response): array
{
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

try {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        fwrite(STDOUT, "[realtime-call-scope-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        <<<'SQL'
CREATE TABLE roles (
    id INTEGER PRIMARY KEY,
    slug TEXT NOT NULL UNIQUE
)
SQL
    );
    $pdo->exec(
        <<<'SQL'
CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    email TEXT NOT NULL,
    display_name TEXT NOT NULL,
    role_id INTEGER NOT NULL
)
SQL
    );
    $pdo->exec(
        <<<'SQL'
CREATE TABLE rooms (
    id TEXT PRIMARY KEY,
    tenant_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    visibility TEXT NOT NULL DEFAULT 'private',
    status TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
)
SQL
    );
    $pdo->exec(
        <<<'SQL'
CREATE TABLE calls (
    id TEXT PRIMARY KEY,
    tenant_id INTEGER NOT NULL,
    room_id TEXT NOT NULL,
    title TEXT NOT NULL,
    access_mode TEXT NOT NULL,
    owner_user_id INTEGER NOT NULL,
    status TEXT NOT NULL,
    starts_at TEXT NOT NULL,
    created_at TEXT NOT NULL
)
SQL
    );
    $pdo->exec(
        <<<'SQL'
CREATE TABLE call_participants (
    call_id TEXT NOT NULL,
    user_id INTEGER,
    email TEXT NOT NULL,
    display_name TEXT NOT NULL,
    source TEXT NOT NULL,
    call_role TEXT NOT NULL,
    invite_state TEXT NOT NULL DEFAULT 'invited',
    joined_at TEXT,
    left_at TEXT
)
SQL
    );

    $pdo->exec("INSERT INTO roles(id, slug) VALUES(1, 'user'), (2, 'admin')");
    $pdo->exec(
        <<<'SQL'
INSERT INTO users(id, email, display_name, role_id) VALUES
    (101, 'tenant-a-owner@example.test', 'Tenant A Owner', 1),
    (102, 'tenant-a-other@example.test', 'Tenant A Other', 1),
    (201, 'tenant-b-owner@example.test', 'Tenant B Owner', 1),
    (202, 'tenant-b-waiting@example.test', 'Tenant B Waiting', 1)
SQL
    );
    $pdo->exec(
        <<<'SQL'
INSERT INTO rooms(id, tenant_id, name, status, created_at, updated_at) VALUES
    ('lobby', 10, 'Tenant A Lobby', 'active', '2026-05-08T08:00:00Z', '2026-05-08T08:00:00Z'),
    ('room-a', 10, 'Tenant A Call', 'active', '2026-05-08T08:00:00Z', '2026-05-08T08:00:00Z'),
    ('room-c', 10, 'Tenant A Other Call', 'active', '2026-05-08T08:00:00Z', '2026-05-08T08:00:00Z'),
    ('room-b', 20, 'Tenant B Call', 'active', '2026-05-08T08:00:00Z', '2026-05-08T08:00:00Z')
SQL
    );
    $pdo->exec(
        <<<'SQL'
INSERT INTO calls(id, tenant_id, room_id, title, access_mode, owner_user_id, status, starts_at, created_at) VALUES
    ('call-a', 10, 'room-a', 'Tenant A Call', 'invite_only', 101, 'active', '2026-05-08T08:05:00Z', '2026-05-08T08:00:00Z'),
    ('call-c', 10, 'room-c', 'Tenant A Other Call', 'invite_only', 102, 'active', '2026-05-08T08:05:00Z', '2026-05-08T08:00:00Z'),
    ('call-b', 20, 'room-b', 'Tenant B Call', 'invite_only', 201, 'active', '2026-05-08T08:05:00Z', '2026-05-08T08:00:00Z')
SQL
    );
    $pdo->exec(
        <<<'SQL'
INSERT INTO call_participants(call_id, user_id, email, display_name, source, call_role, invite_state, joined_at, left_at) VALUES
    ('call-a', 101, 'tenant-a-owner@example.test', 'Tenant A Owner', 'internal', 'owner', 'allowed', '2026-05-08T08:10:00Z', NULL),
    ('call-c', 102, 'tenant-a-other@example.test', 'Tenant A Other', 'internal', 'owner', 'allowed', '2026-05-08T08:10:00Z', NULL),
    ('call-b', 201, 'tenant-b-owner@example.test', 'Tenant B Owner', 'internal', 'owner', 'allowed', '2026-05-08T08:10:00Z', NULL),
    ('call-b', 202, 'tenant-b-waiting@example.test', 'Tenant B Waiting', 'internal', 'participant', 'pending', NULL, NULL)
SQL
    );

    $openDatabase = static function () use ($pdo): PDO {
        return $pdo;
    };

    $authTenantA = [
        'ok' => true,
        'token' => 'sess-tenant-a-owner',
        'session' => ['id' => 'sess-tenant-a-owner'],
        'tenant' => ['id' => 10],
        'user' => [
            'id' => 101,
            'role' => 'user',
            'display_name' => 'Tenant A Owner',
            'tenant' => ['id' => 10],
        ],
    ];

    $ownContext = videochat_realtime_call_role_context_for_room_user($pdo, 'room-a', 101, 'call-a', 'user', 10);
    videochat_realtime_call_scope_assert((string) ($ownContext['call_id'] ?? '') === 'call-a', 'own call context must resolve');
    videochat_realtime_call_scope_assert((bool) ($ownContext['can_moderate'] ?? false), 'own call context must carry moderation');

    $sameTenantForeignContext = videochat_realtime_call_role_context_for_room_user($pdo, 'room-c', 101, 'call-c', 'user', 10);
    videochat_realtime_call_scope_assert((string) ($sameTenantForeignContext['call_id'] ?? '') === '', 'foreign same-tenant call must not resolve without membership');
    $activeRoomC = videochat_fetch_active_room_context($pdo, 'room-c', 10);
    videochat_realtime_call_scope_assert(is_array($activeRoomC), 'same-tenant active room lookup must resolve room-c');
    $wrongCallForOwnRoom = videochat_realtime_user_has_sfu_room_admission($openDatabase, 101, 'user', 'room-a', 'call-c', 10);
    videochat_realtime_call_scope_assert(!$wrongCallForOwnRoom, 'foreign call id must not admit against an owned room');
    $ownCallForForeignRoom = videochat_realtime_user_has_sfu_room_admission($openDatabase, 101, 'user', 'room-c', 'call-a', 10);
    videochat_realtime_call_scope_assert(!$ownCallForForeignRoom, 'owned call id must not admit against a forged room');
    $foreignTenantAdmission = videochat_realtime_user_has_sfu_room_admission($openDatabase, 101, 'user', 'room-b', 'call-b', 10);
    videochat_realtime_call_scope_assert(!$foreignTenantAdmission, 'foreign tenant call must not grant persistent SFU admission');

    $sameTenantForeignResolution = videochat_realtime_resolve_connection_rooms($authTenantA, 'room-c', $openDatabase, 'call-c');
    videochat_realtime_call_scope_assert(
        (string) ($sameTenantForeignResolution['initial_room_id'] ?? '') === videochat_realtime_waiting_room_id(),
        'foreign same-tenant websocket resolution must start in the waiting room'
    );
    videochat_realtime_call_scope_assert(
        (string) ($sameTenantForeignResolution['pending_room_id'] ?? '') === 'room-c',
        'foreign same-tenant websocket resolution may only queue for the requested room: '
            . json_encode($sameTenantForeignResolution, JSON_UNESCAPED_SLASHES)
    );

    $foreignTenantResolution = videochat_realtime_resolve_connection_rooms($authTenantA, 'room-b', $openDatabase, 'call-b');
    videochat_realtime_call_scope_assert(
        (string) ($foreignTenantResolution['requested_room_id'] ?? '') !== 'room-b'
        && (string) ($foreignTenantResolution['pending_room_id'] ?? '') !== 'room-b',
        'foreign tenant websocket resolution must not bind the foreign room'
    );

    $tenantAConnection = [
        'connection_id' => 'conn-a',
        'session_id' => 'sess-tenant-a-owner',
        'socket' => 'socket-a',
        'tenant_id' => 10,
        'user_id' => 101,
        'display_name' => 'Tenant A Owner',
        'role' => 'user',
        'room_id' => 'room-a',
        'requested_room_id' => 'room-a',
        'pending_room_id' => '',
        'requested_call_id' => 'call-a',
        'active_call_id' => 'call-a',
        'call_role' => 'owner',
        'invite_state' => 'allowed',
        'joined_at' => '2026-05-08T08:10:00Z',
        'left_at' => '',
        'can_moderate_call' => true,
    ];
    videochat_realtime_call_scope_assert(
        videochat_realtime_connection_can_join_call_scoped_room($tenantAConnection, 'room-a', $openDatabase),
        'current room should remain joinable'
    );
    videochat_realtime_call_scope_assert(
        !videochat_realtime_connection_can_join_call_scoped_room($tenantAConnection, 'room-c', $openDatabase),
        'websocket room/join must reject a forged same-tenant call room'
    );
    videochat_realtime_call_scope_assert(
        !videochat_realtime_connection_can_join_call_scoped_room($tenantAConnection, 'room-b', $openDatabase),
        'websocket room/join must reject a foreign tenant call room'
    );

    $lobbyState = [];
    $foreignLobbySync = videochat_realtime_sync_lobby_room_from_database($lobbyState, $openDatabase, 'room-b', 'call-b', null, 10);
    videochat_realtime_call_scope_assert(!(bool) ($foreignLobbySync['ok'] ?? true), 'lobby sync must not hydrate foreign tenant call data');
    videochat_realtime_call_scope_assert(!isset($lobbyState['rooms']['room-b']), 'foreign tenant lobby sync must not create a room snapshot');
    $tenantBLobbySync = videochat_realtime_sync_lobby_room_from_database($lobbyState, $openDatabase, 'room-b', 'call-b', null, 20);
    videochat_realtime_call_scope_assert((bool) ($tenantBLobbySync['ok'] ?? false), 'matching tenant lobby sync should still hydrate');
    videochat_realtime_call_scope_assert((int) ($tenantBLobbySync['queue_count'] ?? 0) === 1, 'matching tenant lobby sync should preserve queued users');

    $presenceState = videochat_presence_state_init();
    $presenceJoin = videochat_presence_join_room($presenceState, $tenantAConnection, 'room-a');
    $joinedConnection = (array) ($presenceJoin['connection'] ?? $tenantAConnection);
    videochat_realtime_call_scope_assert(
        videochat_realtime_presence_has_room_membership($presenceState, 'room-a', 101, 'sess-tenant-a-owner', 10),
        'presence membership must exist only for the joined call room'
    );
    videochat_realtime_call_scope_assert(
        !videochat_realtime_presence_has_room_membership($presenceState, 'room-c', 101, 'sess-tenant-a-owner', 10),
        'presence membership must not imply same-tenant foreign room subscription'
    );
    videochat_realtime_call_scope_assert(
        !videochat_realtime_presence_has_room_membership($presenceState, 'room-b', 101, 'sess-tenant-a-owner', 10),
        'presence membership must not imply foreign tenant room subscription'
    );
    $forgedLobbyManage = videochat_lobby_apply_command(
        $lobbyState,
        $presenceState,
        $joinedConnection,
        [
            'ok' => true,
            'type' => 'lobby/allow_all',
            'room_id' => 'room-c',
            'target_user_id' => 0,
        ]
    );
    videochat_realtime_call_scope_assert(
        !(bool) ($forgedLobbyManage['ok'] ?? true)
        && (string) ($forgedLobbyManage['error'] ?? '') === 'sender_not_in_room',
        'lobby management must reject a forged call room'
    );

    $sfuMismatch = videochat_sfu_decode_client_frame(
        json_encode(['type' => 'sfu/subscribe', 'room_id' => 'room-c'], JSON_UNESCAPED_SLASHES),
        'room-a'
    );
    videochat_realtime_call_scope_assert(!(bool) ($sfuMismatch['ok'] ?? true), 'SFU command room override must fail');
    videochat_realtime_call_scope_assert((string) ($sfuMismatch['error'] ?? '') === 'sfu_room_mismatch', 'SFU command room override error mismatch');

    $errorResponse = static function (int $status, string $code, string $message, array $details = []): array {
        return [
            'status' => $status,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'details' => $details,
                ],
            ], JSON_UNESCAPED_SLASHES),
        ];
    };
    $authFailureResponse = static fn (string $surface, string $reason): array => $errorResponse(
        401,
        'authentication_required',
        'Authentication required.',
        ['surface' => $surface, 'reason' => $reason]
    );
    $rbacFailureResponse = static fn (string $surface, array $decision, string $path): array => $errorResponse(
        403,
        'forbidden',
        'Access denied.',
        ['surface' => $surface, 'path' => $path, 'reason' => (string) ($decision['reason'] ?? '')]
    );
    $authenticateTenantA = static fn (array $request, string $surface): array => $authTenantA;
    $sfuForeignResponse = videochat_handle_sfu_routes(
        '/sfu',
        [
            'method' => 'GET',
            'path' => '/sfu',
            'uri' => '/sfu?room_id=room-c&call_id=call-c',
            'headers' => [
                'Connection' => 'keep-alive, Upgrade',
                'Upgrade' => 'websocket',
                'Sec-WebSocket-Key' => base64_encode(str_repeat('a', 16)),
                'Sec-WebSocket-Version' => '13',
            ],
        ],
        $presenceState,
        $authenticateTenantA,
        $authFailureResponse,
        $rbacFailureResponse,
        $errorResponse,
        $openDatabase
    );
    $sfuForeignBody = videochat_realtime_call_scope_decode_response($sfuForeignResponse);
    videochat_realtime_call_scope_assert((int) ($sfuForeignResponse['status'] ?? 0) === 403, 'SFU route must reject a forged call room');
    videochat_realtime_call_scope_assert(
        (string) (($sfuForeignBody['error'] ?? [])['code'] ?? '') === 'sfu_room_admission_required',
        'SFU route forged room rejection code mismatch'
    );

    unset($joinedConnection);
    fwrite(STDOUT, "[realtime-call-scope-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[realtime-call-scope-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
