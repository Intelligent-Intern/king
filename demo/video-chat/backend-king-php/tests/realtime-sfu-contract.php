<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/realtime/realtime_presence.php';
require_once __DIR__ . '/../http/module_realtime.php';

function videochat_realtime_sfu_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[realtime-sfu-contract] FAIL: {$message}\n");
    exit(1);
}

/**
 * @return array<string, mixed>
 */
function videochat_realtime_sfu_decode_response(array $response): array
{
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

function videochat_realtime_sfu_base64url(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function videochat_realtime_sfu_protected_frame(array $header, string $ciphertext): string
{
    $headerJson = json_encode($header, JSON_UNESCAPED_SLASHES);
    videochat_realtime_sfu_assert(is_string($headerJson), 'protected frame header JSON must encode');
    return videochat_realtime_sfu_base64url('KPMF' . pack('N', strlen($headerJson)) . $headerJson . $ciphertext);
}

try {
    putenv('VIDEOCHAT_KING_DB_PATH=/tmp/video-chat-main.sqlite');
    putenv('VIDEOCHAT_KING_SFU_BROKER_DB_PATH');
    videochat_realtime_sfu_assert(
        videochat_sfu_broker_database_path() === '/tmp/video-chat-sfu-broker.sqlite',
        'SFU broker path should default to a sibling sqlite file'
    );
    putenv('VIDEOCHAT_KING_SFU_BROKER_DB_PATH=/tmp/video-chat-custom-sfu-broker.sqlite');
    videochat_realtime_sfu_assert(
        videochat_sfu_broker_database_path() === '/tmp/video-chat-custom-sfu-broker.sqlite',
        'SFU broker path should honor the dedicated environment override'
    );

    $validKey = base64_encode(random_bytes(16));
    $baseRequest = [
        'method' => 'GET',
        'path' => '/sfu',
        'uri' => '/sfu?session=sess_sfu_valid&room_id=room-alpha&room=room-alpha&call_id=call-alpha',
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
            'time' => gmdate('c'),
        ]);
    };
    $authFailureResponse = static function (string $transport, string $reason) use ($errorResponse): array {
        return $errorResponse(401, $transport === 'websocket' ? 'websocket_auth_failed' : 'auth_failed', 'Auth failed.', [
            'reason' => $reason,
        ]);
    };
    $rbacFailureResponse = static function (string $transport, array $rbacDecision, string $requestPath) use ($errorResponse): array {
        return $errorResponse(403, $transport === 'websocket' ? 'websocket_forbidden' : 'rbac_forbidden', 'Forbidden.', [
            'reason' => (string) ($rbacDecision['reason'] ?? ''),
            'path' => $requestPath,
        ]);
    };

    $authCallCount = 0;
    $authenticateOk = static function (array $request, string $transport) use (&$authCallCount): array {
        $authCallCount++;
        return [
            'ok' => true,
            'reason' => 'ok',
            'token' => 'sess_sfu_valid',
            'session' => ['id' => 'sess_sfu_valid'],
            'user' => [
                'id' => 100,
                'role' => 'admin',
                'display_name' => 'SFU Admin',
            ],
        ];
    };

    $activeWebsocketsBySession = [];
    $presenceState = [];
    $lobbyState = [];
    $typingState = [];
    $reactionState = [];
    $openDatabaseFail = static function (): PDO {
        throw new RuntimeException('database access must not happen in pre-upgrade failure path');
    };

    $invalidMethod = videochat_handle_realtime_routes(
        '/sfu',
        [...$baseRequest, 'method' => 'POST'],
        '/ws',
        $activeWebsocketsBySession,
        $presenceState,
        $lobbyState,
        $typingState,
        $reactionState,
        $authenticateOk,
        $authFailureResponse,
        $rbacFailureResponse,
        $jsonResponse,
        $errorResponse,
        $openDatabaseFail
    );
    videochat_realtime_sfu_assert((int) ($invalidMethod['status'] ?? 0) === 405, 'SFU handshake invalid method should fail before auth');
    videochat_realtime_sfu_assert($authCallCount === 0, 'SFU auth callback must not run for invalid handshake');

    $authMissing = static function (array $request, string $transport): array {
        return [
            'ok' => false,
            'reason' => 'missing_session',
            'token' => '',
            'session' => null,
            'user' => null,
        ];
    };
    $missingSession = videochat_handle_realtime_routes(
        '/sfu',
        [...$baseRequest, 'uri' => '/sfu?room_id=room-alpha'],
        '/ws',
        $activeWebsocketsBySession,
        $presenceState,
        $lobbyState,
        $typingState,
        $reactionState,
        $authMissing,
        $authFailureResponse,
        $rbacFailureResponse,
        $jsonResponse,
        $errorResponse,
        $openDatabaseFail
    );
    videochat_realtime_sfu_assert((int) ($missingSession['status'] ?? 0) === 401, 'SFU missing session should fail auth');
    $missingSessionBody = videochat_realtime_sfu_decode_response($missingSession);
    videochat_realtime_sfu_assert(
        (string) (($missingSessionBody['error'] ?? [])['code'] ?? '') === 'websocket_auth_failed',
        'SFU missing session error code mismatch'
    );

    $missingRoom = videochat_handle_realtime_routes(
        '/sfu',
        [...$baseRequest, 'uri' => '/sfu?session=sess_sfu_valid'],
        '/ws',
        $activeWebsocketsBySession,
        $presenceState,
        $lobbyState,
        $typingState,
        $reactionState,
        $authenticateOk,
        $authFailureResponse,
        $rbacFailureResponse,
        $jsonResponse,
        $errorResponse,
        $openDatabaseFail
    );
    videochat_realtime_sfu_assert((int) ($missingRoom['status'] ?? 0) === 400, 'SFU missing room_id should fail closed');
    $missingRoomBody = videochat_realtime_sfu_decode_response($missingRoom);
    videochat_realtime_sfu_assert(
        (string) (($missingRoomBody['error'] ?? [])['code'] ?? '') === 'sfu_room_binding_invalid',
        'SFU missing room error code mismatch'
    );
    videochat_realtime_sfu_assert(
        (string) (((($missingRoomBody['error'] ?? [])['details'] ?? [])['reason'] ?? '')) === 'missing_room_id',
        'SFU missing room reason mismatch'
    );

    $roomMismatch = videochat_sfu_resolve_bound_room(['room_id' => 'room-alpha', 'room' => 'room-beta']);
    videochat_realtime_sfu_assert(!(bool) ($roomMismatch['ok'] ?? true), 'SFU room query mismatch must fail');
    videochat_realtime_sfu_assert((string) ($roomMismatch['error'] ?? '') === 'room_query_mismatch', 'SFU room query mismatch reason mismatch');

    $joinSnake = videochat_sfu_decode_client_frame(
        json_encode(['type' => 'sfu/join', 'room_id' => 'room-alpha'], JSON_UNESCAPED_SLASHES),
        'room-alpha'
    );
    videochat_realtime_sfu_assert((bool) ($joinSnake['ok'] ?? false), 'SFU join with matching room_id should pass');
    $joinLegacy = videochat_sfu_decode_client_frame(
        json_encode(['type' => 'sfu/join', 'roomId' => 'room-alpha'], JSON_UNESCAPED_SLASHES),
        'room-alpha'
    );
    videochat_realtime_sfu_assert((bool) ($joinLegacy['ok'] ?? false), 'SFU join with legacy roomId should stay compatible');
    $joinMismatch = videochat_sfu_decode_client_frame(
        json_encode(['type' => 'sfu/join', 'room_id' => 'room-beta'], JSON_UNESCAPED_SLASHES),
        'room-alpha'
    );
    videochat_realtime_sfu_assert(!(bool) ($joinMismatch['ok'] ?? true), 'SFU join room mismatch must fail');
    videochat_realtime_sfu_assert((string) ($joinMismatch['error'] ?? '') === 'sfu_room_mismatch', 'SFU join mismatch reason mismatch');
    $publishMismatch = videochat_sfu_decode_client_frame(
        json_encode(['type' => 'sfu/publish', 'room_id' => 'room-beta', 'track_id' => 'cam-1'], JSON_UNESCAPED_SLASHES),
        'room-alpha'
    );
    videochat_realtime_sfu_assert(!(bool) ($publishMismatch['ok'] ?? true), 'SFU publish room mismatch must fail');
    $protectedHeader = [
        'contract_name' => 'king-video-chat-protected-media-frame',
        'contract_version' => 'v1.0.0',
        'magic' => 'KPMF',
        'version' => 1,
        'runtime_path' => 'wlvc_sfu',
        'track_kind' => 'video',
        'frame_kind' => 'delta',
        'kex_suite' => 'x25519_hkdf_sha256_v1',
        'media_suite' => 'aes_256_gcm_v1',
        'epoch' => 1,
        'sender_key_id' => 'c2VuZGVyLWtleS1pZC0xMjM0',
        'sequence' => 1,
        'nonce' => 'AQIDBAUGBwgJCgsMDQ4PEBESExQVFhcY',
        'aad_length' => 128,
        'ciphertext_length' => 3,
        'tag_length' => 16,
    ];
    $protectedFrame = videochat_realtime_sfu_protected_frame($protectedHeader, "\x0b\x0c\x0d");
    $protectedFrameCommand = videochat_sfu_decode_client_frame(
        json_encode([
            'type' => 'sfu/frame',
            'room_id' => 'room-alpha',
            'track_id' => 'camera-a',
            'protected_frame' => $protectedFrame,
            'protection_mode' => 'required',
        ], JSON_UNESCAPED_SLASHES),
        'room-alpha'
    );
    videochat_realtime_sfu_assert((bool) ($protectedFrameCommand['ok'] ?? false), 'protected SFU frame envelope should decode');
    videochat_realtime_sfu_assert(
        (($protectedFrameCommand['payload'] ?? [])['protected_frame'] ?? '') === $protectedFrame,
        'protected SFU frame envelope must survive decode unchanged'
    );
    videochat_realtime_sfu_assert(
        (($protectedFrameCommand['payload'] ?? [])['protection_mode'] ?? '') === 'required',
        'required protection mode must survive decode'
    );
    $protectedDataConflictCommand = videochat_sfu_decode_client_frame(
        json_encode([
            'type' => 'sfu/frame',
            'room_id' => 'room-alpha',
            'track_id' => 'camera-a',
            'data' => [11, 12, 13],
            'protected_frame' => $protectedFrame,
        ], JSON_UNESCAPED_SLASHES),
        'room-alpha'
    );
    videochat_realtime_sfu_assert(!(bool) ($protectedDataConflictCommand['ok'] ?? true), 'SFU must reject protected frame plus plaintext data');
    videochat_realtime_sfu_assert((string) ($protectedDataConflictCommand['error'] ?? '') === 'protected_frame_data_conflict', 'protected frame data conflict reason mismatch');
    $requiredPlaintextFallbackCommand = videochat_sfu_decode_client_frame(
        json_encode([
            'type' => 'sfu/frame',
            'room_id' => 'room-alpha',
            'track_id' => 'camera-a',
            'data' => [11, 12, 13],
            'protection_mode' => 'required',
        ], JSON_UNESCAPED_SLASHES),
        'room-alpha'
    );
    videochat_realtime_sfu_assert(!(bool) ($requiredPlaintextFallbackCommand['ok'] ?? true), 'SFU must reject plaintext fallback in required mode');
    videochat_realtime_sfu_assert((string) ($requiredPlaintextFallbackCommand['error'] ?? '') === 'protected_frame_required', 'required plaintext fallback reason mismatch');
    $legacyProtectedMetadataCommand = videochat_sfu_decode_client_frame(
        json_encode([
            'type' => 'sfu/frame',
            'room_id' => 'room-alpha',
            'track_id' => 'camera-a',
            'data' => [11, 12, 13],
            'protected' => ['plaintext_frame' => 'nope'],
        ], JSON_UNESCAPED_SLASHES),
        'room-alpha'
    );
    videochat_realtime_sfu_assert(!(bool) ($legacyProtectedMetadataCommand['ok'] ?? true), 'SFU must reject legacy protected metadata');
    $chunkedTransportCommand = videochat_sfu_decode_client_frame(
        json_encode([
            'type' => 'sfu/frame-chunk',
            'room_id' => 'room-alpha',
            'frame_id' => 'frame_alpha_01',
            'track_id' => 'camera-a',
            'timestamp' => 12345,
            'frame_type' => 'keyframe',
            'chunk_index' => 0,
            'chunk_count' => 2,
            'data_base64_chunk' => 'QUJDREVGRw',
            'protection_mode' => 'transport_only',
        ], JSON_UNESCAPED_SLASHES),
        'room-alpha'
    );
    videochat_realtime_sfu_assert((bool) ($chunkedTransportCommand['ok'] ?? false), 'chunked SFU transport frame should decode');
    videochat_realtime_sfu_assert(
        (($chunkedTransportCommand['payload'] ?? [])['data_base64_chunk'] ?? '') === 'QUJDREVGRw',
        'chunked SFU transport payload must preserve the chunk'
    );
    $chunkedProtectedCommand = videochat_sfu_decode_client_frame(
        json_encode([
            'type' => 'sfu/frame-chunk',
            'room_id' => 'room-alpha',
            'frame_id' => 'frame_alpha_02',
            'track_id' => 'camera-a',
            'timestamp' => 12346,
            'frame_type' => 'delta',
            'chunk_index' => 1,
            'chunk_count' => 3,
            'protected_frame_chunk' => 'QUJDREVGR0g',
            'protection_mode' => 'required',
        ], JSON_UNESCAPED_SLASHES),
        'room-alpha'
    );
    videochat_realtime_sfu_assert((bool) ($chunkedProtectedCommand['ok'] ?? false), 'chunked protected SFU frame should decode');
    videochat_realtime_sfu_assert(
        (($chunkedProtectedCommand['payload'] ?? [])['protection_mode'] ?? '') === 'required',
        'chunked protected SFU frame must preserve required mode'
    );
    $invalidChunkCommand = videochat_sfu_decode_client_frame(
        json_encode([
            'type' => 'sfu/frame-chunk',
            'room_id' => 'room-alpha',
            'frame_id' => 'bad frame id',
            'track_id' => 'camera-a',
            'timestamp' => 12347,
            'frame_type' => 'delta',
            'chunk_index' => 0,
            'chunk_count' => 1,
            'data_base64_chunk' => 'QUJD',
            'protection_mode' => 'transport_only',
        ], JSON_UNESCAPED_SLASHES),
        'room-alpha'
    );
    videochat_realtime_sfu_assert(!(bool) ($invalidChunkCommand['ok'] ?? true), 'invalid SFU chunk frame id must fail closed');

    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[realtime-sfu-contract] SKIP: pdo_sqlite is not available for persistence relay checks\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-realtime-sfu-contract-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }
    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);
    videochat_sfu_bootstrap($pdo);

    videochat_sfu_upsert_publisher($pdo, 'room-alpha', 'publisher-a', '100', 'Publisher A');
    videochat_sfu_upsert_publisher($pdo, 'room-alpha', 'publisher-b', '200', 'Publisher B');
    videochat_sfu_upsert_publisher($pdo, 'room-beta', 'publisher-c', '300', 'Publisher C');
    videochat_sfu_upsert_track($pdo, 'room-alpha', 'publisher-a', 'camera-a', 'video', 'Camera A');
    videochat_sfu_upsert_track($pdo, 'room-alpha', 'publisher-b', 'camera-b', 'video', 'Camera B');
    videochat_sfu_upsert_track($pdo, 'room-alpha', 'publisher-b', 'mic-b', 'audio', 'Mic B');
    videochat_sfu_upsert_track($pdo, 'room-beta', 'publisher-c', 'camera-c', 'video', 'Camera C');

    $publishersAlpha = videochat_sfu_fetch_publishers($pdo, 'room-alpha');
    videochat_realtime_sfu_assert(count($publishersAlpha) === 2, 'SFU publisher list must be room-scoped');
    $tracksB = videochat_sfu_fetch_tracks($pdo, 'room-alpha', 'publisher-b');
    videochat_realtime_sfu_assert(count($tracksB) === 2, 'SFU subscribe should return publisher tracks');
    videochat_realtime_sfu_assert((string) ($tracksB[0]['id'] ?? '') === 'camera-b', 'SFU tracks should be ordered deterministically');

    $pdoReconnect = videochat_open_sqlite_pdo($databasePath);
    videochat_sfu_bootstrap($pdoReconnect);
    $reconnectedPublishers = videochat_sfu_fetch_publishers($pdoReconnect, 'room-alpha');
    videochat_realtime_sfu_assert(count($reconnectedPublishers) === 2, 'SFU reconnect should recover publishers from store');
    $reconnectedTracks = videochat_sfu_fetch_tracks($pdoReconnect, 'room-alpha', 'publisher-b');
    videochat_realtime_sfu_assert(count($reconnectedTracks) === 2, 'SFU reconnect should recover track list from store');

    $staleCutoffMs = videochat_sfu_now_ms() - videochat_sfu_presence_ttl_ms() - 1000;
    $pdo->prepare('UPDATE sfu_publishers SET updated_at_ms = :updated_at_ms WHERE room_id = :room_id AND publisher_id = :publisher_id')
        ->execute([
            ':updated_at_ms' => $staleCutoffMs,
            ':room_id' => 'room-alpha',
            ':publisher_id' => 'publisher-a',
        ]);
    $pdo->prepare('UPDATE sfu_tracks SET updated_at_ms = :updated_at_ms WHERE room_id = :room_id AND publisher_id = :publisher_id')
        ->execute([
            ':updated_at_ms' => $staleCutoffMs,
            ':room_id' => 'room-alpha',
            ':publisher_id' => 'publisher-a',
        ]);
    videochat_realtime_sfu_assert(
        count(videochat_sfu_fetch_publishers($pdo, 'room-alpha')) === 1,
        'SFU broker fetch must ignore stale publishers'
    );
    videochat_realtime_sfu_assert(
        videochat_sfu_fetch_tracks($pdo, 'room-alpha', 'publisher-a') === [],
        'SFU broker fetch must ignore stale tracks'
    );
    videochat_sfu_cleanup_stale_presence($pdo);
    $remainingPublisherRows = (int) $pdo->query("SELECT COUNT(*) FROM sfu_publishers WHERE room_id = 'room-alpha' AND publisher_id = 'publisher-a'")->fetchColumn();
    $remainingTrackRows = (int) $pdo->query("SELECT COUNT(*) FROM sfu_tracks WHERE room_id = 'room-alpha' AND publisher_id = 'publisher-a'")->fetchColumn();
    videochat_realtime_sfu_assert($remainingPublisherRows === 0, 'SFU stale publisher cleanup must remove expired publishers');
    videochat_realtime_sfu_assert($remainingTrackRows === 0, 'SFU stale publisher cleanup must remove expired tracks');

    videochat_sfu_insert_frame($pdo, 'room-alpha', 'publisher-a', '100', 'camera-a', 1000, 'keyframe', [1, 2, 3]);
    videochat_sfu_insert_frame($pdo, 'room-alpha', 'publisher-b', '200', 'camera-b', 1001, 'delta', [4, 5, 6]);
    videochat_sfu_insert_frame($pdo, 'room-alpha', 'publisher-b', '200', 'camera-b', 1003, 'delta', [], $protectedFrame);
    videochat_sfu_insert_frame($pdo, 'room-beta', 'publisher-c', '300', 'camera-c', 1002, 'delta', [7, 8, 9]);
    $framesForA = videochat_sfu_fetch_frames_since($pdo, 'room-alpha', 0, 'publisher-a');
    videochat_realtime_sfu_assert(count($framesForA) === 2, 'SFU frame relay must exclude self and cross-room frames');
    videochat_realtime_sfu_assert((string) ($framesForA[0]['publisher_id'] ?? '') === 'publisher-b', 'SFU frame relay should include only remote same-room publisher');
    $storedProtectedPayload = json_decode((string) ($framesForA[1]['data_json'] ?? ''), true);
    videochat_realtime_sfu_assert(is_array($storedProtectedPayload), 'stored protected SFU payload must decode');
    $decodedStoredProtectedPayload = videochat_sfu_decode_stored_frame_payload($storedProtectedPayload);
    videochat_realtime_sfu_assert($decodedStoredProtectedPayload['data'] === [], 'stored protected SFU payload must not expose legacy data array');
    videochat_realtime_sfu_assert($decodedStoredProtectedPayload['protected_frame'] === $protectedFrame, 'stored protected SFU envelope mismatch');
    videochat_realtime_sfu_assert(!array_key_exists('protected', $storedProtectedPayload), 'stored protected SFU payload must not expose ad-hoc metadata');

    videochat_sfu_remove_track($pdo, 'room-alpha', 'publisher-b', 'mic-b');
    videochat_realtime_sfu_assert(count(videochat_sfu_fetch_tracks($pdo, 'room-alpha', 'publisher-b')) === 1, 'SFU unpublish should remove only target track');
    videochat_sfu_remove_publisher($pdo, 'room-alpha', 'publisher-b');
    videochat_realtime_sfu_assert(videochat_sfu_fetch_tracks($pdo, 'room-alpha', 'publisher-b') === [], 'SFU publisher removal should remove tracks');

    @unlink($databasePath);
    fwrite(STDOUT, "[realtime-sfu-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[realtime-sfu-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
