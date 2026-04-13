<?php

declare(strict_types=1);

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../http/module_auth_session.php';

function videochat_session_refresh_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[session-refresh-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-session-refresh-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $adminUserQuery = $pdo->prepare(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE lower(users.email) = lower('admin@intelligent-intern.com')
LIMIT 1
SQL
    );
    $adminUserQuery->execute();
    $adminUserId = (int) $adminUserQuery->fetchColumn();
    videochat_session_refresh_assert($adminUserId > 0, 'expected seeded admin user');

    $insertSession = $pdo->prepare(
        <<<'SQL'
INSERT INTO sessions(id, user_id, issued_at, expires_at, revoked_at, client_ip, user_agent)
VALUES(:id, :user_id, :issued_at, :expires_at, NULL, :client_ip, :user_agent)
SQL
    );
    $insertSession->execute([
        ':id' => 'sess_refresh_source',
        ':user_id' => $adminUserId,
        ':issued_at' => gmdate('c', time() - 30),
        ':expires_at' => gmdate('c', time() + 3600),
        ':client_ip' => '127.0.0.1',
        ':user_agent' => 'session-refresh-contract',
    ]);

    $activeWebsocketsBySession = [
        'sess_refresh_source' => [
            'conn-a' => 'ws-resource-a',
            'conn-b' => 'ws-resource-b',
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
        $error = [
            'code' => $code,
            'message' => $message,
        ];
        if ($details !== []) {
            $error['details'] = $details;
        }

        return $jsonResponse($status, [
            'status' => 'error',
            'error' => $error,
            'time' => gmdate('c'),
        ]);
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

    $openDatabase = static function () use ($databasePath): PDO {
        return videochat_open_sqlite_pdo($databasePath);
    };

    $issuedSessionIds = ['sess_refresh_rotated'];
    $issueSessionId = static function () use (&$issuedSessionIds): string {
        if ($issuedSessionIds !== []) {
            return (string) array_shift($issuedSessionIds);
        }

        return 'sess_' . bin2hex(random_bytes(20));
    };

    $refreshRequest = [
        'method' => 'POST',
        'uri' => '/api/auth/refresh',
        'headers' => [
            'Authorization' => 'Bearer sess_refresh_source',
            'User-Agent' => 'session-refresh-contract',
        ],
        'remote_address' => '127.0.0.1',
        'body' => '',
    ];

    $apiAuthContext = videochat_authenticate_request($pdo, $refreshRequest, 'rest');
    videochat_session_refresh_assert((bool) ($apiAuthContext['ok'] ?? false), 'source session should authenticate before refresh');

    $wrongMethodResponse = videochat_handle_auth_session_routes(
        '/api/auth/refresh',
        'GET',
        $refreshRequest,
        $apiAuthContext,
        $activeWebsocketsBySession,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase,
        $issueSessionId
    );
    videochat_session_refresh_assert(is_array($wrongMethodResponse), 'wrong method response must be an array');
    videochat_session_refresh_assert((int) ($wrongMethodResponse['status'] ?? 0) === 405, 'refresh wrong method status mismatch');

    $refreshResponse = videochat_handle_auth_session_routes(
        '/api/auth/refresh',
        'POST',
        $refreshRequest,
        $apiAuthContext,
        $activeWebsocketsBySession,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase,
        $issueSessionId
    );
    videochat_session_refresh_assert(is_array($refreshResponse), 'refresh response must be an array');
    videochat_session_refresh_assert((int) ($refreshResponse['status'] ?? 0) === 200, 'refresh status should be 200');

    $refreshPayload = json_decode((string) ($refreshResponse['body'] ?? ''), true);
    videochat_session_refresh_assert(is_array($refreshPayload), 'refresh payload must decode');
    videochat_session_refresh_assert((string) ($refreshPayload['status'] ?? '') === 'ok', 'refresh payload status mismatch');
    videochat_session_refresh_assert(
        (string) (($refreshPayload['session'] ?? [])['id'] ?? '') === 'sess_refresh_rotated',
        'refresh payload should return rotated session id'
    );
    videochat_session_refresh_assert(
        (string) (($refreshPayload['session'] ?? [])['replaces_session_id'] ?? '') === 'sess_refresh_source',
        'refresh payload should expose replaced session id'
    );
    videochat_session_refresh_assert(
        (int) (($refreshPayload['result'] ?? [])['websocket_disconnects'] ?? -1) === 0,
        'refresh should report zero websocket disconnects for non-resource test sockets'
    );

    videochat_session_refresh_assert(
        !isset($activeWebsocketsBySession['sess_refresh_source']),
        'refresh should clear tracked websocket entries for the replaced session'
    );

    $oldSessionAuth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/api/auth/session',
            'headers' => ['Authorization' => 'Bearer sess_refresh_source'],
        ],
        'rest'
    );
    videochat_session_refresh_assert($oldSessionAuth['ok'] === false, 'replaced session token must be invalid');
    videochat_session_refresh_assert($oldSessionAuth['reason'] === 'revoked_session', 'replaced session failure reason mismatch');

    $newSessionAuth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/api/auth/session',
            'headers' => ['Authorization' => 'Bearer sess_refresh_rotated'],
        ],
        'rest'
    );
    videochat_session_refresh_assert($newSessionAuth['ok'] === true, 'rotated session token should authenticate');

    $staleReplayResponse = videochat_handle_auth_session_routes(
        '/api/auth/refresh',
        'POST',
        $refreshRequest,
        $apiAuthContext,
        $activeWebsocketsBySession,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase,
        $issueSessionId
    );
    videochat_session_refresh_assert(is_array($staleReplayResponse), 'stale replay response must be an array');
    videochat_session_refresh_assert((int) ($staleReplayResponse['status'] ?? 0) === 409, 'stale replay should fail with conflict');
    $stalePayload = json_decode((string) ($staleReplayResponse['body'] ?? ''), true);
    videochat_session_refresh_assert(is_array($stalePayload), 'stale replay payload must decode');
    videochat_session_refresh_assert(
        (string) (($stalePayload['error'] ?? [])['code'] ?? '') === 'auth_refresh_conflict',
        'stale replay error code mismatch'
    );

    $rotationConflict = videochat_rotate_session_token(
        $pdo,
        'sess_refresh_source',
        $adminUserId,
        static fn (): string => 'sess_refresh_unused'
    );
    videochat_session_refresh_assert($rotationConflict['ok'] === false, 'direct replay rotation should fail');
    videochat_session_refresh_assert($rotationConflict['reason'] === 'session_not_rotatable', 'direct replay reason mismatch');

    @unlink($databasePath);
    fwrite(STDOUT, "[session-refresh-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[session-refresh-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
