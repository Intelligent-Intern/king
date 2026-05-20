<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../http/module_realtime.php';

if (!function_exists('king_server_upgrade_to_websocket')) {
    function king_server_upgrade_to_websocket(mixed $session, int $streamId): mixed
    {
        return false;
    }
}

function videochat_realtime_websocket_gateway_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[realtime-websocket-gateway-contract] FAIL: {$message}\n");
    exit(1);
}

/**
 * @return array<string, mixed>
 */
function videochat_realtime_websocket_gateway_decode(array $response): array
{
    $payload = json_decode((string) ($response['body'] ?? ''), true);
    if (!is_array($payload)) {
        return [];
    }

    return $payload;
}

try {
    $realtimeWebsocketSource = (string) file_get_contents(__DIR__ . '/../http/module_realtime_websocket.php');
    $gossipMediaRelaySource = (string) file_get_contents(__DIR__ . '/../http/module_realtime_gossip_media_relay.php');
    videochat_realtime_websocket_gateway_assert(
        str_contains($realtimeWebsocketSource, '@king_client_websocket_receive($websocket, 250)'),
        'realtime websocket receive loop must suppress disconnect-time Broken pipe notices'
    );
    videochat_realtime_websocket_gateway_assert(
        str_contains($gossipMediaRelaySource, '@king_client_websocket_receive($websocket, 250)'),
        'gossip media relay receive loop must suppress disconnect-time Broken pipe notices'
    );

    $wsPath = '/ws';
    $validKey = base64_encode(random_bytes(16));
    videochat_realtime_websocket_gateway_assert(is_string($validKey) && $validKey !== '', 'valid websocket key must be generated');

    $validRequest = [
        'method' => 'GET',
        'uri' => '/ws?session=sess_valid',
        'path' => '/ws',
        'headers' => [
            'Connection' => 'keep-alive, Upgrade',
            'Upgrade' => 'websocket',
            'Sec-WebSocket-Key' => $validKey,
            'Sec-WebSocket-Version' => '13',
        ],
    ];

    $normalizedPath = videochat_realtime_normalize_ws_path('ws');
    videochat_realtime_websocket_gateway_assert($normalizedPath === '/ws', 'normalized ws path should prefix slash');
    videochat_realtime_websocket_gateway_assert(
        videochat_realtime_connection_has_upgrade_token('keep-alive, Upgrade') === true,
        'connection header should detect upgrade token'
    );
    videochat_realtime_websocket_gateway_assert(
        videochat_realtime_connection_has_upgrade_token('keep-alive') === false,
        'connection header should reject missing upgrade token'
    );

    $validHandshake = videochat_realtime_validate_websocket_handshake($validRequest, $wsPath);
    videochat_realtime_websocket_gateway_assert((bool) ($validHandshake['ok'] ?? false), 'valid websocket handshake should pass');

    $pathMismatch = videochat_realtime_validate_websocket_handshake(
        ['method' => 'GET', 'uri' => '/socket', 'headers' => []],
        $wsPath
    );
    videochat_realtime_websocket_gateway_assert(!(bool) ($pathMismatch['ok'] ?? true), 'path mismatch handshake should fail');
    videochat_realtime_websocket_gateway_assert((int) ($pathMismatch['status'] ?? 0) === 400, 'path mismatch status should be 400');
    videochat_realtime_websocket_gateway_assert(
        (string) (($pathMismatch['details'] ?? [])['reason'] ?? '') === 'ws_path_mismatch',
        'path mismatch reason should be ws_path_mismatch'
    );

    $invalidMethod = videochat_realtime_validate_websocket_handshake(
        [...$validRequest, 'method' => 'POST'],
        $wsPath
    );
    videochat_realtime_websocket_gateway_assert(!(bool) ($invalidMethod['ok'] ?? true), 'invalid method handshake should fail');
    videochat_realtime_websocket_gateway_assert((int) ($invalidMethod['status'] ?? 0) === 405, 'invalid method status should be 405');
    videochat_realtime_websocket_gateway_assert(
        (string) (($invalidMethod['details'] ?? [])['reason'] ?? '') === 'invalid_method',
        'invalid method reason should be invalid_method'
    );

    $missingUpgrade = videochat_realtime_validate_websocket_handshake(
        [
            ...$validRequest,
            'headers' => [
                'Connection' => 'Upgrade',
                'Sec-WebSocket-Key' => $validKey,
                'Sec-WebSocket-Version' => '13',
            ],
        ],
        $wsPath
    );
    videochat_realtime_websocket_gateway_assert(!(bool) ($missingUpgrade['ok'] ?? true), 'missing upgrade handshake should fail');
    videochat_realtime_websocket_gateway_assert((int) ($missingUpgrade['status'] ?? 0) === 400, 'missing upgrade status should be 400');
    videochat_realtime_websocket_gateway_assert(
        (string) (($missingUpgrade['details'] ?? [])['reason'] ?? '') === 'missing_upgrade_header',
        'missing upgrade reason mismatch'
    );

    $unsupportedVersion = videochat_realtime_validate_websocket_handshake(
        [
            ...$validRequest,
            'headers' => [
                ...$validRequest['headers'],
                'Sec-WebSocket-Version' => '12',
            ],
        ],
        $wsPath
    );
    videochat_realtime_websocket_gateway_assert(!(bool) ($unsupportedVersion['ok'] ?? true), 'unsupported version handshake should fail');
    videochat_realtime_websocket_gateway_assert((int) ($unsupportedVersion['status'] ?? 0) === 426, 'unsupported version status should be 426');
    videochat_realtime_websocket_gateway_assert(
        (string) (($unsupportedVersion['details'] ?? [])['reason'] ?? '') === 'unsupported_sec_websocket_version',
        'unsupported version reason mismatch'
    );

    $closeAuthBackend = videochat_realtime_close_descriptor_for_reason('auth_backend_error');
    videochat_realtime_websocket_gateway_assert((int) ($closeAuthBackend['close_code'] ?? 0) === 1011, 'auth backend close code should be 1011');
    videochat_realtime_websocket_gateway_assert((string) ($closeAuthBackend['close_reason'] ?? '') === 'auth_backend_error', 'auth backend close reason mismatch');
    videochat_realtime_websocket_gateway_assert((string) ($closeAuthBackend['close_category'] ?? '') === 'internal', 'auth backend close category mismatch');

    $closeRevoked = videochat_realtime_close_descriptor_for_reason('revoked_session');
    videochat_realtime_websocket_gateway_assert((int) ($closeRevoked['close_code'] ?? 0) === 1008, 'revoked close code should be 1008');
    videochat_realtime_websocket_gateway_assert((string) ($closeRevoked['close_reason'] ?? '') === 'session_invalidated', 'revoked close reason mismatch');
    videochat_realtime_websocket_gateway_assert((string) ($closeRevoked['close_category'] ?? '') === 'policy', 'revoked close category mismatch');

    $authCallCount = 0;
    $authenticateRequest = static function (array $request, string $transport) use (&$authCallCount): array {
        $authCallCount++;
        return [
            'ok' => true,
            'reason' => 'ok',
            'token' => 'sess_valid',
            'session' => ['id' => 'sess_valid'],
            'user' => [
                'id' => 1,
                'role' => 'admin',
                'display_name' => 'Admin',
            ],
        ];
    };

    $authFailureResponse = static function (string $transport, string $reason): array {
        return [
            'status' => 401,
            'headers' => ['content-type' => 'application/json; charset=utf-8'],
            'body' => json_encode([
                'status' => 'error',
                'error' => [
                    'code' => $transport === 'websocket' ? 'websocket_auth_failed' : 'auth_failed',
                    'message' => 'Auth failed.',
                    'details' => ['reason' => $reason],
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    };

    $rbacFailureResponse = static function (string $transport, array $rbacDecision, string $requestPath): array {
        return [
            'status' => 403,
            'headers' => ['content-type' => 'application/json; charset=utf-8'],
            'body' => json_encode([
                'status' => 'error',
                'error' => [
                    'code' => $transport === 'websocket' ? 'websocket_forbidden' : 'rbac_forbidden',
                    'message' => 'Forbidden.',
                    'details' => ['path' => $requestPath],
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    };

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

    $openDatabase = static function (): PDO {
        throw new RuntimeException('database access must not happen in handshake rejection path');
    };

    $activeWebsocketsBySession = [];
    $presenceState = [];
    $lobbyState = [];
    $typingState = [];
    $reactionState = [];

    $invalidMethodRouteResponse = videochat_handle_realtime_routes(
        '/ws',
        [...$validRequest, 'method' => 'POST'],
        '/ws',
        $activeWebsocketsBySession,
        $presenceState,
        $lobbyState,
        $typingState,
        $reactionState,
        $authenticateRequest,
        $authFailureResponse,
        $rbacFailureResponse,
        $jsonResponse,
        $errorResponse,
        $openDatabase
    );
    videochat_realtime_websocket_gateway_assert(is_array($invalidMethodRouteResponse), 'invalid method route response must be an array');
    videochat_realtime_websocket_gateway_assert((int) ($invalidMethodRouteResponse['status'] ?? 0) === 405, 'invalid method route status should be 405');
    $invalidMethodRoutePayload = videochat_realtime_websocket_gateway_decode($invalidMethodRouteResponse);
    videochat_realtime_websocket_gateway_assert(
        (string) (($invalidMethodRoutePayload['error'] ?? [])['code'] ?? '') === 'websocket_invalid_method',
        'invalid method route error code mismatch'
    );
    videochat_realtime_websocket_gateway_assert(
        (string) (((($invalidMethodRoutePayload['error'] ?? [])['details'] ?? [])['reason'] ?? '')) === 'invalid_method',
        'invalid method route reason mismatch'
    );
    videochat_realtime_websocket_gateway_assert(
        (bool) (((($invalidMethodRoutePayload['error'] ?? [])['details'] ?? [])['auto_reconnect'] ?? true)) === false,
        'invalid method route must disable auto reconnect'
    );
    videochat_realtime_websocket_gateway_assert(
        (int) (((($invalidMethodRoutePayload['error'] ?? [])['details'] ?? [])['connect_cycle_max_ms'] ?? 0)) === 300_000,
        'invalid method route must expose 5-minute connect cycle limit'
    );
    videochat_realtime_websocket_gateway_assert($authCallCount === 0, 'auth callback must not run for handshake method failure');

    $missingUpgradeRouteResponse = videochat_handle_realtime_routes(
        '/ws',
        [
            ...$validRequest,
            'headers' => [
                'Connection' => 'Upgrade',
                'Sec-WebSocket-Key' => $validKey,
                'Sec-WebSocket-Version' => '13',
            ],
        ],
        '/ws',
        $activeWebsocketsBySession,
        $presenceState,
        $lobbyState,
        $typingState,
        $reactionState,
        $authenticateRequest,
        $authFailureResponse,
        $rbacFailureResponse,
        $jsonResponse,
        $errorResponse,
        $openDatabase
    );
    videochat_realtime_websocket_gateway_assert(is_array($missingUpgradeRouteResponse), 'missing-upgrade route response must be an array');
    videochat_realtime_websocket_gateway_assert((int) ($missingUpgradeRouteResponse['status'] ?? 0) === 400, 'missing-upgrade route status should be 400');
    $missingUpgradeRoutePayload = videochat_realtime_websocket_gateway_decode($missingUpgradeRouteResponse);
    videochat_realtime_websocket_gateway_assert(
        (string) (($missingUpgradeRoutePayload['error'] ?? [])['code'] ?? '') === 'websocket_handshake_invalid',
        'missing-upgrade route error code mismatch'
    );
    videochat_realtime_websocket_gateway_assert(
        (string) (((($missingUpgradeRoutePayload['error'] ?? [])['details'] ?? [])['reason'] ?? '')) === 'missing_upgrade_header',
        'missing-upgrade route reason mismatch'
    );
    videochat_realtime_websocket_gateway_assert($authCallCount === 0, 'auth callback must not run for missing upgrade header');

    $upgradeFailureAuth = static fn (): array => [
        'ok' => true,
        'reason' => 'ok',
        'user' => [
            'id' => 2,
            'role' => 'admin',
            'display_name' => 'Upgrade Tester',
        ],
    ];
    $upgradeFailureRouteResponse = videochat_handle_realtime_routes(
        '/ws',
        [
            ...$validRequest,
            'uri' => '/ws',
        ],
        '/ws',
        $activeWebsocketsBySession,
        $presenceState,
        $lobbyState,
        $typingState,
        $reactionState,
        $upgradeFailureAuth,
        $authFailureResponse,
        $rbacFailureResponse,
        $jsonResponse,
        $errorResponse,
        $openDatabase
    );
    $upgradeFailurePayload = videochat_realtime_websocket_gateway_decode($upgradeFailureRouteResponse ?? []);
    $upgradeFailureDetails = is_array(($upgradeFailurePayload['error'] ?? [])['details'] ?? null)
        ? (array) (($upgradeFailurePayload['error'] ?? [])['details'] ?? [])
        : [];
    videochat_realtime_websocket_gateway_assert((int) (($upgradeFailureRouteResponse ?? [])['status'] ?? 0) === 503, 'failed websocket upgrade must return clear backend status');
    videochat_realtime_websocket_gateway_assert((string) (($upgradeFailurePayload['error'] ?? [])['code'] ?? '') === 'websocket_upgrade_failed', 'failed websocket upgrade code mismatch');
    videochat_realtime_websocket_gateway_assert((string) ($upgradeFailureDetails['phase'] ?? '') === 'upgrade', 'failed websocket upgrade phase mismatch');
    videochat_realtime_websocket_gateway_assert((bool) ($upgradeFailureDetails['auto_reconnect'] ?? true) === false, 'failed websocket upgrade must disable auto reconnect');
    videochat_realtime_websocket_gateway_assert((string) ($upgradeFailureDetails['connect_cycle_restart_policy'] ?? '') === 'new_participant_only', 'failed websocket upgrade restart policy mismatch');

    $quorumState = videochat_presence_state_init();
    $ownerConnection = videochat_presence_connection_descriptor(
        ['id' => 10, 'role' => 'user', 'display_name' => 'Quorum Owner'],
        'sess-quorum-owner',
        'conn-quorum-owner',
        'socket-quorum-owner',
        'room-quorum'
    );
    $ownerConnection['active_call_id'] = 'call-quorum';
    $ownerConnection['requested_call_id'] = 'call-quorum';
    $ownerJoin = videochat_presence_join_room($quorumState, $ownerConnection, 'room-quorum');
    $ownerConnection = (array) ($ownerJoin['connection'] ?? $ownerConnection);
    $beforePeerJoin = videochat_realtime_websocket_room_user_ids($quorumState, 'room-quorum');
    $peerConnection = videochat_presence_connection_descriptor(
        ['id' => 11, 'role' => 'user', 'display_name' => 'Quorum Peer'],
        'sess-quorum-peer',
        'conn-quorum-peer',
        'socket-quorum-peer',
        'room-quorum'
    );
    $peerConnection['active_call_id'] = 'call-quorum';
    $peerConnection['requested_call_id'] = 'call-quorum';
    $peerJoin = videochat_presence_join_room($quorumState, $peerConnection, 'room-quorum');
    $peerConnection = (array) ($peerJoin['connection'] ?? $peerConnection);
    $connectQuorum = videochat_realtime_websocket_connect_quorum($quorumState, $peerConnection, $beforePeerJoin);
    videochat_realtime_websocket_gateway_assert((string) ($connectQuorum['schema_version'] ?? '') === 'king.video.connect_quorum.v1', 'connect quorum schema mismatch');
    videochat_realtime_websocket_gateway_assert((int) ($connectQuorum['participant_count'] ?? 0) === 2, 'connect quorum participant count mismatch');
    videochat_realtime_websocket_gateway_assert((bool) ($connectQuorum['quorum_met'] ?? false), 'connect quorum must be met for two participants');
    videochat_realtime_websocket_gateway_assert((bool) ($connectQuorum['new_participant'] ?? false), 'second user must be marked as the new participant');
    videochat_realtime_websocket_gateway_assert((bool) (($connectQuorum['connect_cycle'] ?? [])['allowed'] ?? false), 'new participant may trigger the connect cycle');
    videochat_realtime_websocket_gateway_assert((bool) (($connectQuorum['connect_cycle'] ?? [])['auto_reconnect'] ?? true) === false, 'connect cycle must not enable auto reconnect');
    videochat_realtime_websocket_gateway_assert((int) (($connectQuorum['connect_cycle'] ?? [])['max_ms'] ?? 0) === 300_000, 'connect cycle must allow up to 5 minutes');
    videochat_realtime_websocket_gateway_assert((string) (($connectQuorum['connect_cycle'] ?? [])['trigger'] ?? '') === 'new_participant', 'connect cycle trigger mismatch');
    $quorumOpsState = is_array($connectQuorum['gossip_ops_state'] ?? null) ? (array) $connectQuorum['gossip_ops_state'] : [];
    videochat_realtime_websocket_gateway_assert((string) ($quorumOpsState['kind'] ?? '') === 'gossip_server_head_ops_state', 'connect quorum must publish server-head Gossip ops state');
    videochat_realtime_websocket_gateway_assert((bool) ($quorumOpsState['server_head_authoritative'] ?? false), 'connect quorum Gossip ops state must be server-head authoritative');
    videochat_realtime_websocket_gateway_assert((bool) ($quorumOpsState['client_topology_repair'] ?? true) === false, 'connect quorum must not require client topology repair');
    videochat_realtime_websocket_gateway_assert((bool) ($quorumOpsState['client_recovery_request'] ?? true) === false, 'connect quorum must not require client recovery requests');
    $existingParticipantQuorum = videochat_realtime_websocket_connect_quorum($quorumState, $peerConnection, [11 => true]);
    videochat_realtime_websocket_gateway_assert(!(bool) (($existingParticipantQuorum['connect_cycle'] ?? [])['allowed'] ?? true), 'existing participant must not start a new connect cycle');

    $welcomeFrame = videochat_realtime_websocket_welcome_frame($upgradeFailureAuth(), $peerConnection, 'conn-quorum-peer', $connectQuorum);
    videochat_realtime_websocket_gateway_assert(is_array($welcomeFrame['connect_quorum'] ?? null), 'welcome frame must include connect quorum');
    videochat_realtime_websocket_gateway_assert((string) ((($welcomeFrame['connect_quorum'] ?? [])['connect_cycle'] ?? [])['restart_policy'] ?? '') === 'new_participant_only', 'welcome connect quorum policy mismatch');
    $welcomeOpsState = is_array($welcomeFrame['gossip_ops_state'] ?? null) ? (array) $welcomeFrame['gossip_ops_state'] : [];
    videochat_realtime_websocket_gateway_assert((string) ($welcomeOpsState['authority'] ?? '') === 'server_head', 'welcome frame must publish server-head Gossip ops authority');
    videochat_realtime_websocket_gateway_assert((bool) ($welcomeOpsState['client_repair_request_required'] ?? true) === false, 'welcome frame must not require client repair requests');

    $moduleSource = file_get_contents(__DIR__ . '/../http/module_realtime.php');
    videochat_realtime_websocket_gateway_assert(
        is_string($moduleSource) && !str_contains($moduleSource, 'videochat_lobby_queue_connection_for_room('),
        'websocket attach must not queue pending admission automatically'
    );

    fwrite(STDOUT, "[realtime-websocket-gateway-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[realtime-websocket-gateway-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
