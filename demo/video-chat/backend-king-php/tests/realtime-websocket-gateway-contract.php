<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../http/module_realtime.php';

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
