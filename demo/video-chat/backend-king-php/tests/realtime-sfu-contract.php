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
    $gatewaySource = file_get_contents(__DIR__ . '/../domain/realtime/realtime_sfu_gateway.php');
    videochat_realtime_sfu_assert(is_string($gatewaySource), 'SFU gateway source should be readable for static contract checks');
    videochat_realtime_sfu_assert(
        strpos($gatewaySource, '$acceptFrameChunk') === false
        && strpos($gatewaySource, '$pendingFrameChunks') === false,
        'SFU gateway must not buffer or assemble legacy JSON media chunks'
    );
    videochat_realtime_sfu_assert(
        strpos($gatewaySource, "case 'sfu/frame-chunk':\n                    videochat_presence_send_frame(\$websocket") !== false
        && strpos($gatewaySource, "'error' => 'binary_media_required',\n                        'command_type' => 'sfu/frame-chunk'") !== false,
        'SFU gateway must reject JSON media chunks immediately in binary-required mode'
    );
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
    videochat_realtime_sfu_assert(
        function_exists('videochat_sfu_log_runtime_event'),
        'SFU runtime events should be available for HD transport pressure diagnostics'
    );
    $sfuGatewaySource = (string) file_get_contents(__DIR__ . '/../domain/realtime/realtime_sfu_gateway.php');
    videochat_realtime_sfu_assert(
        str_contains($sfuGatewaySource, 'sfu_frame_broker_pressure'),
        'SFU broker pressure diagnostics should be wired'
    );
    videochat_realtime_sfu_assert(
        str_contains($sfuGatewaySource, 'sfu_frame_direct_fanout_binary_required_failed'),
        'SFU direct fanout binary-required diagnostics should be wired'
    );
    $sfuStoreSource = (string) file_get_contents(__DIR__ . '/../domain/realtime/realtime_sfu_store.php');
    $sfuBrokerReplaySource = (string) file_get_contents(__DIR__ . '/../domain/realtime/realtime_sfu_broker_replay.php');
    videochat_realtime_sfu_assert(
        function_exists('videochat_sfu_room_subscriber_targets'),
        'SFU room-scoped subscriber target helper must exist'
    );
    $fanoutTargets = videochat_sfu_room_subscriber_targets([
        'subscribers' => [
            'publisher-a' => ['websocket' => 'socket-a', 'room_id' => 'room-alpha'],
            'subscriber-b' => ['websocket' => 'socket-b', 'room_id' => 'room-alpha'],
            'subscriber-c' => ['websocket' => 'socket-c', 'room_id' => 'room-alpha'],
            'broken-d' => ['room_id' => 'room-alpha'],
        ],
    ], 'publisher-a');
    videochat_realtime_sfu_assert(count($fanoutTargets) === 2, 'SFU direct fanout targets must be room-scoped, self-excluding, and websocket-bound');
    videochat_realtime_sfu_assert(
        array_values(array_map(static fn(array $row): string => (string) ($row['client_id'] ?? ''), $fanoutTargets)) === ['subscriber-b', 'subscriber-c'],
        'SFU direct fanout targets must preserve explicit subscriber ids'
    );
    videochat_realtime_sfu_assert(
        !str_contains($sfuGatewaySource, 'best-effort cross-worker subscribe')
        && !str_contains($sfuGatewaySource, 'best-effort cross-worker unpublish')
        && !str_contains($sfuGatewaySource, 'best-effort cross-worker track advertisement'),
        'SFU cross-worker broker paths must not be described as best-effort semantics'
    );
    videochat_realtime_sfu_assert(
        str_contains($sfuStoreSource, "function videochat_sfu_normalize_codec_id")
        && str_contains($sfuStoreSource, "function videochat_sfu_normalize_runtime_id"),
        'SFU store must normalize codec/runtime ids centrally'
    );
    videochat_realtime_sfu_assert(
        str_contains($sfuBrokerReplaySource, "'codec_id' => (string) (\$storedMetadata['codec_id'] ?? 'wlvc_unknown')")
        && str_contains($sfuBrokerReplaySource, "'runtime_id' => (string) (\$storedMetadata['runtime_id'] ?? 'unknown_runtime')")
        && str_contains($sfuBrokerReplaySource, "'layout_mode' => (string) (\$storedMetadata['layout_mode'] ?? '')"),
        'SFU broker replay legacy frame path must carry codec/runtime/layout metadata from store'
    );
    videochat_realtime_sfu_assert(
        function_exists('videochat_sfu_select_live_broker_replay_frames'),
        'SFU broker replay must select live frames instead of draining stale video backlog'
    );
    $lastBrokerFrameId = 40;
    $selectedBrokerFrames = videochat_sfu_select_live_broker_replay_frames([
        ['id' => 41, 'created_at_ms' => videochat_sfu_now_ms() - 60_000],
        ['id' => 42, 'created_at_ms' => videochat_sfu_now_ms() - 100],
    ], 'room-alpha', $lastBrokerFrameId);
    videochat_realtime_sfu_assert(
        $lastBrokerFrameId === 42
        && count($selectedBrokerFrames) === 1
        && (int) ($selectedBrokerFrames[0]['id'] ?? 0) === 42,
        'SFU broker replay must advance over stale frames and keep only live media'
    );
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
    $jsonMediaCommand = videochat_sfu_decode_client_frame(
        json_encode([
            'type' => 'sfu/frame',
            'room_id' => 'room-alpha',
            'track_id' => 'camera-a',
            'protected_frame' => $protectedFrame,
            'protection_mode' => 'required',
        ], JSON_UNESCAPED_SLASHES),
        'room-alpha'
    );
    videochat_realtime_sfu_assert(!(bool) ($jsonMediaCommand['ok'] ?? true), 'JSON SFU media frame must be rejected in binary-required mode');
    videochat_realtime_sfu_assert((string) ($jsonMediaCommand['error'] ?? '') === 'binary_media_required', 'JSON SFU media rejection reason mismatch');
    $chunkedTransportCommand = videochat_sfu_decode_client_frame(
        json_encode([
            'type' => 'sfu/frame-chunk',
            'room_id' => 'room-alpha',
            'frame_id' => 'frame_alpha_01',
            'track_id' => 'camera-a',
            'timestamp' => 12345,
            'frame_type' => 'keyframe',
            'protocol_version' => 2,
            'frame_sequence' => 41,
            'sender_sent_at_ms' => 1770000000000,
            'payload_chars' => 10,
            'chunk_payload_chars' => 10,
            'chunk_index' => 0,
            'chunk_count' => 2,
            'data_base64_chunk' => 'QUJDREVGRw',
            'protection_mode' => 'transport_only',
        ], JSON_UNESCAPED_SLASHES),
        'room-alpha'
    );
    videochat_realtime_sfu_assert(!(bool) ($chunkedTransportCommand['ok'] ?? true), 'JSON SFU media chunks must be rejected in binary-required mode');
    videochat_realtime_sfu_assert((string) ($chunkedTransportCommand['error'] ?? '') === 'binary_media_required', 'JSON SFU media chunk rejection reason mismatch');
    $chunkedOutboundTransport = videochat_sfu_expand_outbound_frame_payload([
        'type' => 'sfu/frame',
        'publisher_id' => 'publisher-a',
        'publisher_user_id' => '100',
        'track_id' => 'camera-a',
        'timestamp' => 12348,
        'frame_type' => 'delta',
        'protection_mode' => 'transport_only',
        'protocol_version' => 2,
        'frame_sequence' => 99,
        'sender_sent_at_ms' => 1770000000200,
        'codec_id' => 'wlvc_wasm',
        'runtime_id' => 'wlvc_sfu',
        'layout_mode' => 'tile_foreground',
        'layer_id' => 'foreground',
        'cache_epoch' => 7,
        'tile_columns' => 4,
        'tile_rows' => 3,
        'tile_width' => 160,
        'tile_height' => 120,
        'tile_indices' => [0, 3, 5],
        'roi_norm_x' => 0.1,
        'roi_norm_y' => 0.2,
        'roi_norm_width' => 0.3,
        'roi_norm_height' => 0.4,
        'payload_chars' => strlen(str_repeat('QUJDREVGR0g', 1_200)),
        'data_base64' => str_repeat('QUJDREVGR0g', 1_200),
    ]);
    videochat_realtime_sfu_assert(count($chunkedOutboundTransport) === 1, 'large outbound SFU transport frame must not expand to JSON chunks');
    videochat_realtime_sfu_assert(
        (string) ($chunkedOutboundTransport[0]['type'] ?? '') === 'sfu/frame',
        'outbound SFU transport frame must keep binary-envelope message type'
    );
    videochat_realtime_sfu_assert(
        !array_key_exists('data_base64_chunk', $chunkedOutboundTransport[0])
        && !array_key_exists('protected_frame_chunk', $chunkedOutboundTransport[0]),
        'outbound SFU transport frame must not expose JSON chunk fields'
    );
    videochat_realtime_sfu_assert(
        (int) ($chunkedOutboundTransport[0]['protocol_version'] ?? 0) === 2
        && (int) ($chunkedOutboundTransport[0]['frame_sequence'] ?? 0) === 99
        && (int) ($chunkedOutboundTransport[0]['payload_chars'] ?? 0) === strlen(str_repeat('QUJDREVGR0g', 1_200)),
        'outbound SFU transport frame must preserve protocol, sequence, and payload length metadata'
    );
    videochat_realtime_sfu_assert(
        (string) ($chunkedOutboundTransport[0]['codec_id'] ?? '') === 'wlvc_wasm'
        && (string) ($chunkedOutboundTransport[0]['runtime_id'] ?? '') === 'wlvc_sfu'
        && (string) ($chunkedOutboundTransport[0]['layout_mode'] ?? '') === 'tile_foreground'
        && (string) ($chunkedOutboundTransport[0]['layer_id'] ?? '') === 'foreground'
        && (int) ($chunkedOutboundTransport[0]['cache_epoch'] ?? 0) === 7,
        'outbound SFU transport frame must preserve codec/runtime/layout metadata'
    );
    $chunkedOutboundProtected = videochat_sfu_expand_outbound_frame_payload([
        'type' => 'sfu/frame',
        'publisher_id' => 'publisher-a',
        'publisher_user_id' => '100',
        'track_id' => 'camera-a',
        'timestamp' => 12349,
        'frame_type' => 'keyframe',
        'protection_mode' => 'required',
        'codec_id' => 'wlvc_ts',
        'runtime_id' => 'wlvc_sfu',
        'protected_frame' => str_repeat('QUJDREVGR0g', 1_200),
    ]);
    videochat_realtime_sfu_assert(count($chunkedOutboundProtected) === 1, 'large outbound protected SFU frame must not expand to JSON chunks');
    videochat_realtime_sfu_assert(
        !array_key_exists('protected_frame_chunk', $chunkedOutboundProtected[0]),
        'outbound protected SFU frame must not expose protected_frame_chunk'
    );
    videochat_realtime_sfu_assert(
        (string) ($chunkedOutboundProtected[0]['codec_id'] ?? '') === 'wlvc_ts'
        && (string) ($chunkedOutboundProtected[0]['runtime_id'] ?? '') === 'wlvc_sfu',
        'outbound protected SFU frame must preserve codec/runtime metadata'
    );
    $binaryEnvelope = videochat_sfu_encode_binary_frame_envelope([
        'type' => 'sfu/frame',
        'publisher_id' => 'publisher-a',
        'publisher_user_id' => '100',
        'track_id' => 'camera-a',
        'timestamp' => 12350,
        'frame_type' => 'delta',
        'protection_mode' => 'transport_only',
        'protocol_version' => 2,
        'frame_sequence' => 100,
        'sender_sent_at_ms' => 1770000000300,
        'frame_id' => 'frame_binary_codec_layout',
        'codec_id' => 'wlvc_wasm',
        'runtime_id' => 'wlvc_sfu',
        'layout_mode' => 'background_snapshot',
        'layer_id' => 'background',
        'cache_epoch' => 11,
        'tile_columns' => 2,
        'tile_rows' => 2,
        'tile_width' => 320,
        'tile_height' => 180,
        'tile_indices' => [1],
        'roi_norm_x' => 0.05,
        'roi_norm_y' => 0.15,
        'roi_norm_width' => 0.5,
        'roi_norm_height' => 0.6,
        'data_base64' => 'QUJD',
    ]);
    videochat_realtime_sfu_assert(is_string($binaryEnvelope) && $binaryEnvelope !== '', 'binary SFU frame envelope should encode with codec/runtime/layout metadata');
    $decodedBinaryEnvelope = videochat_sfu_decode_binary_client_frame((string) $binaryEnvelope, 'room-alpha');
    videochat_realtime_sfu_assert((bool) ($decodedBinaryEnvelope['ok'] ?? false), 'binary SFU frame envelope with codec/runtime/layout metadata should decode');
    $decodedBinaryPayload = is_array($decodedBinaryEnvelope['payload'] ?? null) ? $decodedBinaryEnvelope['payload'] : [];
    videochat_realtime_sfu_assert(
        (string) ($decodedBinaryPayload['codec_id'] ?? '') === 'wlvc_wasm'
        && (string) ($decodedBinaryPayload['runtime_id'] ?? '') === 'wlvc_sfu'
        && (string) ($decodedBinaryPayload['layout_mode'] ?? '') === 'background_snapshot'
        && (string) ($decodedBinaryPayload['layer_id'] ?? '') === 'background'
        && (int) ($decodedBinaryPayload['cache_epoch'] ?? 0) === 11,
        'binary SFU frame envelope must preserve codec/runtime/layout metadata through decode'
    );
    videochat_realtime_sfu_assert(
        (int) ($decodedBinaryPayload['payload_bytes'] ?? 0) === strlen('ABC')
        && (int) ($decodedBinaryPayload['payload_chars'] ?? 0) === strlen('QUJD'),
        'binary SFU frame envelope must distinguish wire bytes from advertised base64 payload chars'
    );
    $protectedBinaryEnvelope = videochat_sfu_encode_binary_frame_envelope([
        'type' => 'sfu/frame',
        'publisher_id' => 'publisher-a',
        'publisher_user_id' => '100',
        'track_id' => 'camera-a',
        'timestamp' => 12351,
        'frame_type' => 'delta',
        'protection_mode' => 'required',
        'protocol_version' => 2,
        'frame_sequence' => 101,
        'sender_sent_at_ms' => 1770000000400,
        'protected_frame' => $protectedFrame,
    ]);
    videochat_realtime_sfu_assert(is_string($protectedBinaryEnvelope) && $protectedBinaryEnvelope !== '', 'protected binary SFU frame envelope should encode');
    $decodedProtectedBinaryEnvelope = videochat_sfu_decode_binary_client_frame((string) $protectedBinaryEnvelope, 'room-alpha');
    videochat_realtime_sfu_assert((bool) ($decodedProtectedBinaryEnvelope['ok'] ?? false), 'protected binary SFU frame envelope should decode');
    $decodedProtectedBinaryPayload = is_array($decodedProtectedBinaryEnvelope['payload'] ?? null) ? $decodedProtectedBinaryEnvelope['payload'] : [];
    videochat_realtime_sfu_assert(
        (string) ($decodedProtectedBinaryPayload['protected_frame'] ?? '') === $protectedFrame
        && (string) ($decodedProtectedBinaryPayload['protection_mode'] ?? '') === 'required',
        'protected binary SFU frame envelope must preserve protected payload and required mode'
    );
    videochat_realtime_sfu_assert(
        (int) ($decodedProtectedBinaryPayload['payload_chars'] ?? 0) === strlen($protectedFrame),
        'protected binary SFU frame envelope must advertise protected-frame payload chars'
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
    videochat_realtime_sfu_assert(!(bool) ($invalidChunkCommand['ok'] ?? true), 'JSON SFU chunk command must fail closed');
    videochat_realtime_sfu_assert((string) ($invalidChunkCommand['error'] ?? '') === 'binary_media_required', 'JSON SFU chunk command rejection reason mismatch');

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

    videochat_sfu_upsert_publisher($pdo, 'room-gamma', 'publisher-stale', '400', 'Stale Publisher');
    videochat_sfu_upsert_track($pdo, 'room-gamma', 'publisher-stale', 'camera-stale', 'video', 'Camera Stale');
    $pdo->prepare('UPDATE sfu_publishers SET updated_at_ms = :updated_at_ms WHERE room_id = :room_id AND publisher_id = :publisher_id')
        ->execute([
            ':updated_at_ms' => $staleCutoffMs,
            ':room_id' => 'room-gamma',
            ':publisher_id' => 'publisher-stale',
        ]);
    $pdo->prepare('UPDATE sfu_tracks SET updated_at_ms = :updated_at_ms WHERE room_id = :room_id AND publisher_id = :publisher_id')
        ->execute([
            ':updated_at_ms' => $staleCutoffMs,
            ':room_id' => 'room-gamma',
            ':publisher_id' => 'publisher-stale',
        ]);
    videochat_sfu_insert_frame($pdo, 'room-gamma', 'publisher-stale', '400', 'camera-stale', 1000, 'delta', [9], '', '', false);
    videochat_realtime_sfu_assert(
        videochat_sfu_fetch_publishers($pdo, 'room-gamma') === [],
        'SFU frame insert can skip per-frame presence touches to reduce broker write pressure'
    );

    videochat_sfu_insert_frame($pdo, 'room-alpha', 'publisher-a', '100', 'camera-a', 1000, 'keyframe', [1, 2, 3]);
    videochat_sfu_insert_frame($pdo, 'room-alpha', 'publisher-b', '200', 'camera-b', 1001, 'delta', [4, 5, 6]);
    videochat_sfu_insert_frame($pdo, 'room-alpha', 'publisher-b', '200', 'camera-b', 1002, 'delta', [7, 8], '', '', true, [
        'protocol_version' => 2,
        'frame_sequence' => 101,
        'sender_sent_at_ms' => 1770000000400,
        'frame_id' => 'frame_store_codec_layout',
        'codec_id' => 'wlvc_wasm',
        'runtime_id' => 'wlvc_sfu',
        'layout_mode' => 'tile_foreground',
        'layer_id' => 'foreground',
        'cache_epoch' => 13,
        'tile_columns' => 3,
        'tile_rows' => 3,
        'tile_width' => 160,
        'tile_height' => 90,
        'tile_indices' => [0, 4],
        'roi_norm_x' => 0.2,
        'roi_norm_y' => 0.25,
        'roi_norm_width' => 0.35,
        'roi_norm_height' => 0.45,
        'protection_mode' => 'transport_only',
    ]);
    videochat_sfu_insert_frame($pdo, 'room-alpha', 'publisher-b', '200', 'camera-b', 1003, 'delta', [], $protectedFrame);
    videochat_sfu_insert_frame($pdo, 'room-beta', 'publisher-c', '300', 'camera-c', 1002, 'delta', [7, 8, 9]);
    $framesForA = videochat_sfu_fetch_frames_since($pdo, 'room-alpha', 0, 'publisher-a');
    videochat_realtime_sfu_assert(count($framesForA) === 3, 'SFU frame relay must exclude self and cross-room frames');
    videochat_realtime_sfu_assert((string) ($framesForA[0]['publisher_id'] ?? '') === 'publisher-b', 'SFU frame relay should include only remote same-room publisher');
    $storedCodecLayoutPayload = json_decode((string) ($framesForA[1]['data_json'] ?? ''), true);
    videochat_realtime_sfu_assert(is_array($storedCodecLayoutPayload), 'stored SFU codec/layout payload must decode');
    $decodedStoredCodecLayoutPayload = videochat_sfu_decode_stored_frame_payload($storedCodecLayoutPayload);
    $storedCodecLayoutMetadata = is_array($decodedStoredCodecLayoutPayload['metadata'] ?? null) ? $decodedStoredCodecLayoutPayload['metadata'] : [];
    videochat_realtime_sfu_assert(
        (string) ($storedCodecLayoutMetadata['codec_id'] ?? '') === 'wlvc_wasm'
        && (string) ($storedCodecLayoutMetadata['runtime_id'] ?? '') === 'wlvc_sfu'
        && (string) ($storedCodecLayoutMetadata['layout_mode'] ?? '') === 'tile_foreground'
        && (string) ($storedCodecLayoutMetadata['layer_id'] ?? '') === 'foreground'
        && (int) ($storedCodecLayoutMetadata['cache_epoch'] ?? 0) === 13,
        'stored SFU payload metadata must preserve codec/runtime/layout fields'
    );
    $storedCodecLayoutBinary = is_string($framesForA[1]['data_blob'] ?? null) ? (string) $framesForA[1]['data_blob'] : '';
    videochat_realtime_sfu_assert($storedCodecLayoutBinary !== '', 'stored SFU codec/layout payload must persist binary envelope');
    $decodedStoredCodecLayoutBinary = videochat_sfu_decode_binary_client_frame($storedCodecLayoutBinary, 'room-alpha');
    videochat_realtime_sfu_assert((bool) ($decodedStoredCodecLayoutBinary['ok'] ?? false), 'stored SFU binary replay payload must decode');
    $storedBinaryPayload = is_array($decodedStoredCodecLayoutBinary['payload'] ?? null) ? $decodedStoredCodecLayoutBinary['payload'] : [];
    videochat_realtime_sfu_assert(
        (string) ($storedBinaryPayload['codec_id'] ?? '') === 'wlvc_wasm'
        && (string) ($storedBinaryPayload['runtime_id'] ?? '') === 'wlvc_sfu'
        && (string) ($storedBinaryPayload['layout_mode'] ?? '') === 'tile_foreground'
        && (string) ($storedBinaryPayload['layer_id'] ?? '') === 'foreground',
        'stored SFU binary replay payload must preserve codec/runtime/layout metadata'
    );
    $storedProtectedPayload = json_decode((string) ($framesForA[2]['data_json'] ?? ''), true);
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
