<?php

declare(strict_types=1);

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../http/module_auth_session.php';

function videochat_session_logout_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[session-logout-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-session-logout-' . bin2hex(random_bytes(6)) . '.sqlite';
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
WHERE lower(users.email) = lower('admin@intelligent-intern.com')
LIMIT 1
SQL
    )->fetchColumn();
    videochat_session_logout_assert($adminUserId > 0, 'expected seeded admin user');

    $insertSession = $pdo->prepare(
        <<<'SQL'
INSERT INTO sessions(id, user_id, issued_at, expires_at, revoked_at, client_ip, user_agent)
VALUES(:id, :user_id, :issued_at, :expires_at, NULL, :client_ip, :user_agent)
SQL
    );
    $insertSession->execute([
        ':id' => 'sess_logout_contract',
        ':user_id' => $adminUserId,
        ':issued_at' => gmdate('c', time() - 30),
        ':expires_at' => gmdate('c', time() + 3600),
        ':client_ip' => '127.0.0.1',
        ':user_agent' => 'session-logout-contract',
    ]);

    $activeWebsocketsBySession = [
        'sess_logout_contract' => [
            'conn-a' => 'ws-a',
            'conn-b' => 'ws-b',
        ],
        'sess_other_contract' => [
            'conn-c' => 'ws-c',
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

    $issueSessionId = static fn (): string => 'sess_unused_contract';

    $logoutRequest = [
        'method' => 'POST',
        'uri' => '/api/auth/logout',
        'headers' => [
            'Authorization' => 'Bearer sess_logout_contract',
            'User-Agent' => 'session-logout-contract',
        ],
        'remote_address' => '127.0.0.1',
        'body' => '',
    ];

    $apiAuthContext = videochat_authenticate_request($pdo, $logoutRequest, 'rest');
    videochat_session_logout_assert((bool) ($apiAuthContext['ok'] ?? false), 'session should authenticate before logout');

    $wrongMethod = videochat_handle_auth_session_routes(
        '/api/auth/logout',
        'GET',
        $logoutRequest,
        $apiAuthContext,
        $activeWebsocketsBySession,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase,
        $issueSessionId
    );
    videochat_session_logout_assert(is_array($wrongMethod), 'wrong method response must be an array');
    videochat_session_logout_assert((int) ($wrongMethod['status'] ?? 0) === 405, 'wrong method should return 405');
    $wrongMethodPayload = json_decode((string) ($wrongMethod['body'] ?? ''), true);
    videochat_session_logout_assert(is_array($wrongMethodPayload), 'wrong method payload should decode');
    videochat_session_logout_assert((string) ($wrongMethodPayload['status'] ?? '') === 'error', 'wrong method payload status mismatch');
    videochat_session_logout_assert(
        (string) (($wrongMethodPayload['error'] ?? [])['code'] ?? '') === 'method_not_allowed',
        'wrong method error code mismatch'
    );

    $logoutResponse = videochat_handle_auth_session_routes(
        '/api/auth/logout',
        'POST',
        $logoutRequest,
        $apiAuthContext,
        $activeWebsocketsBySession,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase,
        $issueSessionId
    );
    videochat_session_logout_assert(is_array($logoutResponse), 'logout response must be an array');
    videochat_session_logout_assert((int) ($logoutResponse['status'] ?? 0) === 200, 'logout status should be 200');

    $logoutPayload = json_decode((string) ($logoutResponse['body'] ?? ''), true);
    videochat_session_logout_assert(is_array($logoutPayload), 'logout payload should decode');
    videochat_session_logout_assert((string) ($logoutPayload['status'] ?? '') === 'ok', 'logout payload status mismatch');
    videochat_session_logout_assert(
        (string) (($logoutPayload['result'] ?? [])['session_id'] ?? '') === 'sess_logout_contract',
        'logout payload session_id mismatch'
    );
    videochat_session_logout_assert(
        (string) (($logoutPayload['result'] ?? [])['revocation_state'] ?? '') === 'revoked',
        'logout payload revocation_state mismatch'
    );
    videochat_session_logout_assert(
        is_string(($logoutPayload['result'] ?? [])['revoked_at'] ?? null)
        && trim((string) (($logoutPayload['result'] ?? [])['revoked_at'] ?? '')) !== '',
        'logout payload revoked_at should be a non-empty string'
    );
    videochat_session_logout_assert(
        (int) (($logoutPayload['result'] ?? [])['websocket_disconnects'] ?? -1) === 0,
        'logout payload websocket_disconnects should remain deterministic for non-resource sockets'
    );

    videochat_session_logout_assert(
        !isset($activeWebsocketsBySession['sess_logout_contract']),
        'logout should clear websocket tracking for revoked session'
    );
    videochat_session_logout_assert(
        isset($activeWebsocketsBySession['sess_other_contract']),
        'logout should not clear websocket tracking for other sessions'
    );

    $revokedRow = $pdo->prepare('SELECT revoked_at FROM sessions WHERE id = :id LIMIT 1');
    $revokedRow->execute([':id' => 'sess_logout_contract']);
    $row = $revokedRow->fetch();
    videochat_session_logout_assert(is_array($row), 'revoked session row should exist');
    videochat_session_logout_assert(
        is_string($row['revoked_at'] ?? null) && trim((string) $row['revoked_at']) !== '',
        'logout should persist revoked_at metadata in sessions table'
    );

    $authAfterLogout = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/api/auth/session',
            'headers' => ['Authorization' => 'Bearer sess_logout_contract'],
        ],
        'rest'
    );
    videochat_session_logout_assert($authAfterLogout['ok'] === false, 'revoked session must fail auth');
    videochat_session_logout_assert($authAfterLogout['reason'] === 'revoked_session', 'post-logout auth reason mismatch');

    $logoutAgain = videochat_handle_auth_session_routes(
        '/api/auth/logout',
        'POST',
        $logoutRequest,
        $apiAuthContext,
        $activeWebsocketsBySession,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase,
        $issueSessionId
    );
    videochat_session_logout_assert(is_array($logoutAgain), 'second logout response must be an array');
    videochat_session_logout_assert((int) ($logoutAgain['status'] ?? 0) === 200, 'second logout should remain idempotent');
    $logoutAgainPayload = json_decode((string) ($logoutAgain['body'] ?? ''), true);
    videochat_session_logout_assert(is_array($logoutAgainPayload), 'second logout payload should decode');
    videochat_session_logout_assert(
        (string) (($logoutAgainPayload['result'] ?? [])['revocation_state'] ?? '') === 'already_revoked',
        'second logout revocation_state mismatch'
    );

    $missingAuthContext = ['token' => '', 'session' => null, 'user' => null];
    $missingAuthResponse = videochat_handle_auth_session_routes(
        '/api/auth/logout',
        'POST',
        ['method' => 'POST', 'uri' => '/api/auth/logout', 'headers' => [], 'body' => ''],
        $missingAuthContext,
        $activeWebsocketsBySession,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase,
        $issueSessionId
    );
    videochat_session_logout_assert(is_array($missingAuthResponse), 'missing auth response must be an array');
    videochat_session_logout_assert((int) ($missingAuthResponse['status'] ?? 0) === 401, 'missing auth should return 401');

    @unlink($databasePath);
    fwrite(STDOUT, "[session-logout-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[session-logout-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
