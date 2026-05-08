<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/realtime/realtime_lobby.php';
require_once __DIR__ . '/../http/module_realtime.php';

function videochat_realtime_reconnect_backfill_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[realtime-reconnect-backfill-contract] FAIL: {$message}\n");
    exit(1);
}

/**
 * @return array<string, mixed>
 */
function videochat_realtime_reconnect_backfill_decode(array $response): array
{
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

try {
    $liveness = videochat_realtime_validate_session_liveness(
        static function (): array {
            throw new RuntimeException('sqlite busy');
        },
        'sess-reconnect',
        '/ws'
    );
    videochat_realtime_reconnect_backfill_assert(!(bool) ($liveness['ok'] ?? true), 'thrown auth lookup must fail liveness');
    videochat_realtime_reconnect_backfill_assert((string) ($liveness['reason'] ?? '') === 'auth_backend_error', 'thrown auth lookup must normalize to auth_backend_error');
    videochat_realtime_reconnect_backfill_assert((bool) ($liveness['retryable'] ?? false), 'auth backend liveness failure must be retryable');

    $transientPolicy = videochat_realtime_session_liveness_failure_policy('auth_backend_error', 1, 1000, 5000);
    videochat_realtime_reconnect_backfill_assert((bool) ($transientPolicy['retryable'] ?? false), 'transient auth failure must be retryable');
    videochat_realtime_reconnect_backfill_assert(!(bool) ($transientPolicy['close'] ?? true), 'transient auth failure must stay open inside grace');
    $expiredTransientPolicy = videochat_realtime_session_liveness_failure_policy('auth_backend_error', 2, 5000, 5000);
    videochat_realtime_reconnect_backfill_assert((bool) ($expiredTransientPolicy['close'] ?? false), 'transient auth failure must close after bounded grace');
    videochat_realtime_reconnect_backfill_assert((int) (($expiredTransientPolicy['close_descriptor'] ?? [])['close_code'] ?? 0) === 1011, 'expired transient auth failure must close as internal retryable');
    $revokedPolicy = videochat_realtime_session_liveness_failure_policy('revoked_session', 1, 0, 5000);
    videochat_realtime_reconnect_backfill_assert(!(bool) ($revokedPolicy['retryable'] ?? true), 'revoked session must not be retryable');
    videochat_realtime_reconnect_backfill_assert((int) (($revokedPolicy['close_descriptor'] ?? [])['close_code'] ?? 0) === 1008, 'revoked session must remain policy close');

    $validKey = base64_encode(random_bytes(16));
    $request = [
        'method' => 'GET',
        'path' => '/ws',
        'uri' => '/ws?room=room-reconnect&call_id=call-reconnect',
        'headers' => [
            'Connection' => 'keep-alive, Upgrade',
            'Upgrade' => 'websocket',
            'Sec-WebSocket-Key' => $validKey,
            'Sec-WebSocket-Version' => '13',
        ],
    ];
    $jsonResponse = static function (int $status, array $payload): array {
        return [
            'status' => $status,
            'headers' => ['content-type' => 'application/json; charset=utf-8'],
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
        ]);
    };
    $authFailureResponse = static function (string $transport, string $reason) use ($jsonResponse): array {
        return $jsonResponse(401, [
            'status' => 'error',
            'error' => [
                'code' => $transport === 'websocket' ? 'websocket_auth_failed' : 'auth_failed',
                'message' => 'Auth failed.',
                'details' => ['reason' => $reason],
            ],
        ]);
    };
    $rbacFailureResponse = static fn (string $transport, array $decision, string $path): array => $jsonResponse(403, [
        'status' => 'error',
        'error' => [
            'code' => 'rbac_forbidden',
            'message' => 'Forbidden.',
            'details' => ['path' => $path],
        ],
    ]);

    $activeWebsocketsBySession = [];
    $presenceState = videochat_presence_state_init();
    $lobbyState = [];
    $typingState = [];
    $reactionState = [];
    $authBackendFailure = videochat_handle_realtime_routes(
        '/ws',
        $request,
        '/ws',
        $activeWebsocketsBySession,
        $presenceState,
        $lobbyState,
        $typingState,
        $reactionState,
        static fn (): array => ['ok' => false, 'reason' => 'auth_backend_error', 'retryable' => true],
        $authFailureResponse,
        $rbacFailureResponse,
        $jsonResponse,
        $errorResponse,
        static function (): PDO {
            throw new RuntimeException('database must not open for auth backend failure');
        }
    );
    $authBackendPayload = videochat_realtime_reconnect_backfill_decode($authBackendFailure ?? []);
    videochat_realtime_reconnect_backfill_assert((int) (($authBackendFailure ?? [])['status'] ?? 0) === 503, 'auth backend reconnect failure must return retryable status');
    videochat_realtime_reconnect_backfill_assert((string) (($authBackendPayload['error'] ?? [])['code'] ?? '') === 'websocket_auth_temporarily_unavailable', 'auth backend reconnect failure code mismatch');
    videochat_realtime_reconnect_backfill_assert((bool) (((($authBackendPayload['error'] ?? [])['details'] ?? [])['retryable'] ?? false)), 'auth backend reconnect failure must be marked retryable');

    $auth = [
        'ok' => true,
        'token' => 'sess-reconnect',
        'session' => ['id' => 'sess-reconnect'],
        'user' => [
            'id' => 10,
            'role' => 'user',
            'display_name' => 'Reconnect Owner',
        ],
    ];
    $failingOpenDatabase = static function (): PDO {
        throw new RuntimeException('database temporarily unavailable');
    };
    $failedResolution = videochat_realtime_resolve_connection_rooms($auth, 'room-reconnect', $failingOpenDatabase, 'call-reconnect');
    videochat_realtime_reconnect_backfill_assert((bool) ($failedResolution['ok'] ?? true) === false, 'requested call reconnect must not fall back to lobby when backfill lookup fails');
    videochat_realtime_reconnect_backfill_assert((bool) ($failedResolution['retryable'] ?? false), 'requested call backfill failure must be retryable');
    videochat_realtime_reconnect_backfill_assert((string) ($failedResolution['requested_room_id'] ?? 'unexpected') === '', 'failed reconnect backfill must not bind a room');

    $backfillUnavailable = videochat_handle_realtime_routes(
        '/ws',
        $request,
        '/ws',
        $activeWebsocketsBySession,
        $presenceState,
        $lobbyState,
        $typingState,
        $reactionState,
        static fn (): array => $auth,
        $authFailureResponse,
        $rbacFailureResponse,
        $jsonResponse,
        $errorResponse,
        $failingOpenDatabase
    );
    $backfillUnavailablePayload = videochat_realtime_reconnect_backfill_decode($backfillUnavailable ?? []);
    videochat_realtime_reconnect_backfill_assert((int) (($backfillUnavailable ?? [])['status'] ?? 0) === 503, 'unavailable reconnect backfill must return retryable status before upgrade');
    videochat_realtime_reconnect_backfill_assert((string) (($backfillUnavailablePayload['error'] ?? [])['code'] ?? '') === 'websocket_reconnect_backfill_unavailable', 'unavailable reconnect backfill code mismatch');
    videochat_realtime_reconnect_backfill_assert((bool) (((($backfillUnavailablePayload['error'] ?? [])['details'] ?? [])['retryable'] ?? false)), 'unavailable reconnect backfill must be retryable');

    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        fwrite(STDOUT, "[realtime-reconnect-backfill-contract] SKIP: pdo_sqlite unavailable after non-sqlite checks\n");
        exit(0);
    }

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, slug TEXT NOT NULL UNIQUE)');
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL, display_name TEXT NOT NULL, role_id INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE rooms (id TEXT PRIMARY KEY, name TEXT NOT NULL, status TEXT NOT NULL)');
    $pdo->exec(
        <<<'SQL'
CREATE TABLE calls (
    id TEXT PRIMARY KEY,
    room_id TEXT NOT NULL,
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
    $pdo->exec("INSERT INTO roles(id, slug) VALUES(1, 'user')");
    $pdo->exec(
        <<<'SQL'
INSERT INTO users(id, email, display_name, role_id) VALUES
    (10, 'owner@example.test', 'Reconnect Owner', 1),
    (11, 'waiting@example.test', 'Waiting User', 1)
SQL
    );
    $pdo->exec("INSERT INTO rooms(id, name, status) VALUES('lobby', 'Lobby', 'active'), ('room-reconnect', 'Reconnect Room', 'active')");
    $pdo->exec(
        <<<'SQL'
INSERT INTO calls(id, room_id, access_mode, owner_user_id, status, starts_at, created_at)
VALUES('call-reconnect', 'room-reconnect', 'invite_only', 10, 'active', '2026-05-08T10:00:00Z', '2026-05-08T09:00:00Z')
SQL
    );
    $pdo->exec(
        <<<'SQL'
INSERT INTO call_participants(call_id, user_id, email, display_name, source, call_role, invite_state, joined_at, left_at) VALUES
    ('call-reconnect', 10, 'owner@example.test', 'Reconnect Owner', 'internal', 'owner', 'allowed', '2026-05-08T10:01:00Z', NULL),
    ('call-reconnect', 11, 'waiting@example.test', 'Waiting User', 'internal', 'participant', 'pending', NULL, NULL)
SQL
    );
    $openDatabase = static fn (): PDO => $pdo;

    $resolved = videochat_realtime_resolve_connection_rooms($auth, 'room-reconnect', $openDatabase, 'call-reconnect');
    videochat_realtime_reconnect_backfill_assert((bool) ($resolved['ok'] ?? false), 'available reconnect backfill must resolve');
    videochat_realtime_reconnect_backfill_assert((string) ($resolved['initial_room_id'] ?? '') === 'room-reconnect', 'available reconnect must return to requested room');
    videochat_realtime_reconnect_backfill_assert((string) ($resolved['pending_room_id'] ?? 'unexpected') === '', 'authorized reconnect must not be routed to lobby');

    $connection = videochat_presence_connection_descriptor($auth['user'], 'sess-reconnect', 'conn-reconnect', 'socket-reconnect', 'room-reconnect');
    $connection['requested_room_id'] = 'room-reconnect';
    $connection['pending_room_id'] = '';
    $connection['requested_call_id'] = 'call-reconnect';
    $connection = videochat_realtime_connection_with_call_context($connection, $openDatabase);
    videochat_realtime_reconnect_backfill_assert((string) ($connection['active_call_id'] ?? '') === 'call-reconnect', 'reconnected connection must keep active call scope');
    videochat_realtime_reconnect_backfill_assert((bool) ($connection['can_moderate_call'] ?? false), 'reconnected owner must keep call moderation context');

    $presenceState = videochat_presence_state_init();
    $join = videochat_presence_join_room($presenceState, $connection, 'room-reconnect');
    $connection = (array) ($join['connection'] ?? $connection);
    $frames = [];
    $sender = static function (mixed $socket, array $payload) use (&$frames): bool {
        $frames[] = $payload;
        return true;
    };
    $lobbySnapshot = videochat_realtime_send_synced_lobby_snapshot_to_connection($lobbyState, $connection, $openDatabase, 'reconnect_backfill', $sender);
    $roomSnapshot = videochat_realtime_send_room_snapshot($presenceState, $connection, $openDatabase, 'reconnect_backfill', $sender);
    videochat_realtime_reconnect_backfill_assert((string) ($lobbySnapshot['room_id'] ?? '') === 'room-reconnect', 'lobby snapshot backfill must use requested call room');
    $roomPayload = is_array($roomSnapshot['payload'] ?? null) ? $roomSnapshot['payload'] : [];
    videochat_realtime_reconnect_backfill_assert((string) ($roomPayload['room_id'] ?? '') === 'room-reconnect', 'room snapshot backfill room mismatch');
    videochat_realtime_reconnect_backfill_assert((string) ((($roomPayload['viewer'] ?? [])['call_id'] ?? '')) === 'call-reconnect', 'room snapshot viewer must keep call scope');
    $lobbyFrame = $frames[0] ?? [];
    $roomFrame = $frames[1] ?? [];
    videochat_realtime_reconnect_backfill_assert((string) ($lobbyFrame['type'] ?? '') === 'lobby/snapshot', 'reconnect must send lobby snapshot');
    videochat_realtime_reconnect_backfill_assert((string) ($roomFrame['type'] ?? '') === 'room/snapshot', 'reconnect must send room snapshot');
    videochat_realtime_reconnect_backfill_assert((string) ($roomFrame['reason'] ?? '') === 'reconnect_backfill', 'room snapshot must carry reconnect backfill reason');
    videochat_realtime_reconnect_backfill_assert((int) ($roomFrame['participant_count'] ?? 0) >= 1, 'room snapshot must include active reconnect participant');

    fwrite(STDOUT, "[realtime-reconnect-backfill-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[realtime-reconnect-backfill-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
