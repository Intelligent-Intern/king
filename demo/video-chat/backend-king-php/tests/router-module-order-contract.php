<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../http/router.php';

function videochat_router_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[router-module-order-contract] FAIL: {$message}\n");
    exit(1);
}

/** @return array<string, mixed> */
function videochat_router_contract_json_decode(string $raw): array
{
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

try {
    $expectedOrder = [
        'runtime',
        'auth_session',
        'infrastructure',
        'operations',
        'marketplace',
        'tenancy',
        'users',
        'workspace_administration',
        'invites',
        'calls',
        'appointment_calendar',
        'realtime',
    ];
    $actualOrder = videochat_dispatch_route_module_order();
    videochat_router_contract_assert($actualOrder === $expectedOrder, 'module order does not match expected deterministic sequence');
    videochat_router_contract_assert(count(array_unique($actualOrder)) === count($actualOrder), 'module order contains duplicates');

    $openDatabaseCalls = 0;
    $openDatabase = static function () use (&$openDatabaseCalls): PDO {
        $openDatabaseCalls++;
        throw new RuntimeException('openDatabase should not be reached for this request.');
    };

    $jsonResponse = static function (int $status, array $payload): array {
        return [
            'status' => $status,
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    };

    $errorResponse = static function (int $status, string $code, string $message, array $details = []) use ($jsonResponse): array {
        $payload = [
            'status' => 'error',
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
            'time' => gmdate('c'),
        ];
        if ($details !== []) {
            $payload['error']['details'] = $details;
        }
        return $jsonResponse($status, $payload);
    };

    $methodFromRequest = static function (array $request): string {
        $method = strtoupper(trim((string) ($request['method'] ?? 'GET')));
        return $method === '' ? 'GET' : $method;
    };

    $decodeJsonBody = static function (array $request): array {
        $body = $request['body'] ?? '';
        if (!is_string($body) || trim($body) === '') {
            return [null, 'empty_body'];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return [null, 'invalid_json'];
        }

        return [$decoded, null];
    };

    $issueSessionId = static function (): string {
        return 'sess-contract';
    };

    $pathFromRequest = static function (array $request): string {
        $path = $request['path'] ?? null;
        if (is_string($path) && $path !== '') {
            return $path;
        }

        $uri = $request['uri'] ?? null;
        if (is_string($uri) && $uri !== '') {
            return (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
        }

        return '/';
    };

    $runtimeEnvelope = static function (): array {
        return [
            'service' => 'video-chat-backend-king-php',
            'app' => ['name' => 'king-video-chat-backend', 'version' => 'contract', 'environment' => 'test'],
            'runtime' => [
                'king_version' => 'test',
                'health' => [
                    'build' => 'test-build',
                    'module_version' => 'test-module-version',
                ],
            ],
            'time' => gmdate('c'),
        ];
    };

    $activeWebsocketsBySession = [];
    $presenceState = [];
    $lobbyState = [];
    $typingState = [];
    $reactionState = [];

    $runtimeResponse = videochat_dispatch_request(
        ['method' => 'GET', 'path' => '/api/runtime', 'uri' => '/api/runtime', 'headers' => []],
        $activeWebsocketsBySession,
        $presenceState,
        $lobbyState,
        $typingState,
        $reactionState,
        $jsonResponse,
        $errorResponse,
        $methodFromRequest,
        $decodeJsonBody,
        $openDatabase,
        $issueSessionId,
        $pathFromRequest,
        $runtimeEnvelope,
        '/ws',
        '/tmp',
        1024
    );
    videochat_router_contract_assert((int) ($runtimeResponse['status'] ?? 0) === 200, 'runtime endpoint should return 200');
    $runtimePayload = videochat_router_contract_json_decode((string) ($runtimeResponse['body'] ?? ''));
    videochat_router_contract_assert((string) ($runtimePayload['status'] ?? '') === 'ok', 'runtime payload status mismatch');
    videochat_router_contract_assert($openDatabaseCalls === 0, 'runtime path should not open database');

    $authBlockedResponse = videochat_dispatch_request(
        ['method' => 'GET', 'path' => '/api/user/ping', 'uri' => '/api/user/ping', 'headers' => []],
        $activeWebsocketsBySession,
        $presenceState,
        $lobbyState,
        $typingState,
        $reactionState,
        $jsonResponse,
        $errorResponse,
        $methodFromRequest,
        $decodeJsonBody,
        $openDatabase,
        $issueSessionId,
        $pathFromRequest,
        $runtimeEnvelope,
        '/ws',
        '/tmp',
        1024
    );
    videochat_router_contract_assert((int) ($authBlockedResponse['status'] ?? 0) === 500, 'protected path should fail with auth backend error when DB is unavailable');
    $authBlockedPayload = videochat_router_contract_json_decode((string) ($authBlockedResponse['body'] ?? ''));
    videochat_router_contract_assert((string) (($authBlockedPayload['error'] ?? [])['code'] ?? '') === 'auth_failed', 'auth failure code mismatch');
    videochat_router_contract_assert($openDatabaseCalls === 1, 'protected path should attempt one auth database check');

    fwrite(STDOUT, "[router-module-order-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[router-module-order-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
