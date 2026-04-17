<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/users/user_management.php';
require_once __DIR__ . '/../http/module_users.php';

function videochat_admin_user_status_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[admin-user-status-contract] FAIL: {$message}\n");
    exit(1);
}

/**
 * @return array<string, mixed>
 */
function videochat_admin_user_status_decode_response(array $response): array
{
    $payload = json_decode((string) ($response['body'] ?? ''), true);
    if (!is_array($payload)) {
        return [];
    }

    return $payload;
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-admin-user-status-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $created = videochat_admin_create_user($pdo, [
        'email' => 'status-contract-user@intelligent-intern.com',
        'display_name' => 'Status Contract User',
        'password' => 'status-contract-password',
        'role' => 'user',
    ]);
    videochat_admin_user_status_assert((bool) ($created['ok'] ?? false), 'seed user create should succeed');
    $targetUser = $created['user'] ?? null;
    videochat_admin_user_status_assert(is_array($targetUser), 'seed user payload missing');
    $targetUserId = (int) ($targetUser['id'] ?? 0);
    videochat_admin_user_status_assert($targetUserId > 0, 'seed user id should be positive');

    $sessionInsert = $pdo->prepare(
        <<<'SQL'
INSERT INTO sessions(id, user_id, issued_at, expires_at, revoked_at, client_ip, user_agent)
VALUES(:id, :user_id, :issued_at, :expires_at, NULL, :client_ip, :user_agent)
SQL
    );
    $sessionInsert->execute([
        ':id' => 'sess_status_contract',
        ':user_id' => $targetUserId,
        ':issued_at' => gmdate('c', time() - 30),
        ':expires_at' => gmdate('c', time() + 3600),
        ':client_ip' => '127.0.0.1',
        ':user_agent' => 'admin-user-status-contract',
    ]);

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

    $apiAuthContext = [
        'ok' => true,
        'token' => 'sess_admin_status_contract',
        'user' => [
            'id' => 1,
            'email' => 'admin@intelligent-intern.com',
            'display_name' => 'Admin',
            'role' => 'admin',
            'status' => 'active',
        ],
        'session' => ['id' => 'sess_admin_status_contract'],
    ];

    $deactivateWrongMethod = videochat_handle_user_routes(
        '/api/admin/users/' . $targetUserId . '/deactivate',
        'GET',
        ['method' => 'GET', 'uri' => '/api/admin/users/' . $targetUserId . '/deactivate', 'body' => ''],
        $apiAuthContext,
        [],
        sys_get_temp_dir(),
        512000,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_admin_user_status_assert(is_array($deactivateWrongMethod), 'deactivate wrong-method response must be array');
    videochat_admin_user_status_assert((int) ($deactivateWrongMethod['status'] ?? 0) === 405, 'deactivate wrong-method should return 405');

    $deactivateResponse = videochat_handle_user_routes(
        '/api/admin/users/' . $targetUserId . '/deactivate',
        'POST',
        ['method' => 'POST', 'uri' => '/api/admin/users/' . $targetUserId . '/deactivate', 'body' => ''],
        $apiAuthContext,
        [],
        sys_get_temp_dir(),
        512000,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_admin_user_status_assert(is_array($deactivateResponse), 'deactivate response must be array');
    videochat_admin_user_status_assert((int) ($deactivateResponse['status'] ?? 0) === 200, 'deactivate status should be 200');
    $deactivatePayload = videochat_admin_user_status_decode_response($deactivateResponse);
    videochat_admin_user_status_assert((string) ($deactivatePayload['status'] ?? '') === 'ok', 'deactivate payload status mismatch');
    videochat_admin_user_status_assert(
        (string) (($deactivatePayload['result'] ?? [])['state'] ?? '') === 'deactivated',
        'deactivate state mismatch'
    );
    videochat_admin_user_status_assert(
        (string) ((($deactivatePayload['result'] ?? [])['user'] ?? [])['status'] ?? '') === 'disabled',
        'deactivate user status mismatch'
    );
    videochat_admin_user_status_assert(
        (int) (($deactivatePayload['result'] ?? [])['revoked_sessions'] ?? 0) >= 1,
        'deactivate should revoke at least one session'
    );

    $sessionStateAfterDeactivate = videochat_validate_session_token($pdo, 'sess_status_contract');
    videochat_admin_user_status_assert(
        (bool) ($sessionStateAfterDeactivate['ok'] ?? true) === false,
        'deactivated user session must fail validation'
    );
    videochat_admin_user_status_assert(
        (string) ($sessionStateAfterDeactivate['reason'] ?? '') === 'revoked_session',
        'deactivated user session should be revoked'
    );

    $deactivateAgainResponse = videochat_handle_user_routes(
        '/api/admin/users/' . $targetUserId . '/deactivate',
        'POST',
        ['method' => 'POST', 'uri' => '/api/admin/users/' . $targetUserId . '/deactivate', 'body' => ''],
        $apiAuthContext,
        [],
        sys_get_temp_dir(),
        512000,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_admin_user_status_assert(is_array($deactivateAgainResponse), 'deactivate-again response must be array');
    videochat_admin_user_status_assert((int) ($deactivateAgainResponse['status'] ?? 0) === 200, 'deactivate-again status should be 200');
    $deactivateAgainPayload = videochat_admin_user_status_decode_response($deactivateAgainResponse);
    videochat_admin_user_status_assert(
        (string) (($deactivateAgainPayload['result'] ?? [])['state'] ?? '') === 'already_disabled',
        'deactivate-again state mismatch'
    );

    $reactivateWrongMethod = videochat_handle_user_routes(
        '/api/admin/users/' . $targetUserId . '/reactivate',
        'GET',
        ['method' => 'GET', 'uri' => '/api/admin/users/' . $targetUserId . '/reactivate', 'body' => ''],
        $apiAuthContext,
        [],
        sys_get_temp_dir(),
        512000,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_admin_user_status_assert(is_array($reactivateWrongMethod), 'reactivate wrong-method response must be array');
    videochat_admin_user_status_assert((int) ($reactivateWrongMethod['status'] ?? 0) === 405, 'reactivate wrong-method should return 405');

    $reactivateResponse = videochat_handle_user_routes(
        '/api/admin/users/' . $targetUserId . '/reactivate',
        'POST',
        ['method' => 'POST', 'uri' => '/api/admin/users/' . $targetUserId . '/reactivate', 'body' => ''],
        $apiAuthContext,
        [],
        sys_get_temp_dir(),
        512000,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_admin_user_status_assert(is_array($reactivateResponse), 'reactivate response must be array');
    videochat_admin_user_status_assert((int) ($reactivateResponse['status'] ?? 0) === 200, 'reactivate status should be 200');
    $reactivatePayload = videochat_admin_user_status_decode_response($reactivateResponse);
    videochat_admin_user_status_assert((string) ($reactivatePayload['status'] ?? '') === 'ok', 'reactivate payload status mismatch');
    videochat_admin_user_status_assert(
        (string) (($reactivatePayload['result'] ?? [])['state'] ?? '') === 'reactivated',
        'reactivate state mismatch'
    );
    videochat_admin_user_status_assert(
        (string) ((($reactivatePayload['result'] ?? [])['user'] ?? [])['status'] ?? '') === 'active',
        'reactivate user status mismatch'
    );

    $sessionStateAfterReactivate = videochat_validate_session_token($pdo, 'sess_status_contract');
    videochat_admin_user_status_assert(
        (bool) ($sessionStateAfterReactivate['ok'] ?? true) === false,
        'previously revoked session must stay invalid after reactivate'
    );
    videochat_admin_user_status_assert(
        (string) ($sessionStateAfterReactivate['reason'] ?? '') === 'revoked_session',
        'reactivate should not reinstate revoked sessions'
    );

    $reactivateAgainResponse = videochat_handle_user_routes(
        '/api/admin/users/' . $targetUserId . '/reactivate',
        'POST',
        ['method' => 'POST', 'uri' => '/api/admin/users/' . $targetUserId . '/reactivate', 'body' => ''],
        $apiAuthContext,
        [],
        sys_get_temp_dir(),
        512000,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_admin_user_status_assert(is_array($reactivateAgainResponse), 'reactivate-again response must be array');
    videochat_admin_user_status_assert((int) ($reactivateAgainResponse['status'] ?? 0) === 200, 'reactivate-again status should be 200');
    $reactivateAgainPayload = videochat_admin_user_status_decode_response($reactivateAgainResponse);
    videochat_admin_user_status_assert(
        (string) (($reactivateAgainPayload['result'] ?? [])['state'] ?? '') === 'already_active',
        'reactivate-again state mismatch'
    );

    $deactivateMissingResponse = videochat_handle_user_routes(
        '/api/admin/users/999999/deactivate',
        'POST',
        ['method' => 'POST', 'uri' => '/api/admin/users/999999/deactivate', 'body' => ''],
        $apiAuthContext,
        [],
        sys_get_temp_dir(),
        512000,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_admin_user_status_assert(is_array($deactivateMissingResponse), 'deactivate-missing response must be array');
    videochat_admin_user_status_assert((int) ($deactivateMissingResponse['status'] ?? 0) === 404, 'deactivate-missing status should be 404');
    $deactivateMissingPayload = videochat_admin_user_status_decode_response($deactivateMissingResponse);
    videochat_admin_user_status_assert(
        (string) (($deactivateMissingPayload['error'] ?? [])['code'] ?? '') === 'admin_user_not_found',
        'deactivate-missing error code mismatch'
    );

    $reactivateMissingResponse = videochat_handle_user_routes(
        '/api/admin/users/999999/reactivate',
        'POST',
        ['method' => 'POST', 'uri' => '/api/admin/users/999999/reactivate', 'body' => ''],
        $apiAuthContext,
        [],
        sys_get_temp_dir(),
        512000,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_admin_user_status_assert(is_array($reactivateMissingResponse), 'reactivate-missing response must be array');
    videochat_admin_user_status_assert((int) ($reactivateMissingResponse['status'] ?? 0) === 404, 'reactivate-missing status should be 404');
    $reactivateMissingPayload = videochat_admin_user_status_decode_response($reactivateMissingResponse);
    videochat_admin_user_status_assert(
        (string) (($reactivateMissingPayload['error'] ?? [])['code'] ?? '') === 'admin_user_not_found',
        'reactivate-missing error code mismatch'
    );

    @unlink($databasePath);
    fwrite(STDOUT, "[admin-user-status-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[admin-user-status-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
