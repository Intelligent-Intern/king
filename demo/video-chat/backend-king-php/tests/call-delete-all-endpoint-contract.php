<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../http/module_calls.php';

function videochat_call_delete_all_endpoint_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-delete-all-endpoint-contract] FAIL: {$message}\n");
    exit(1);
}

/**
 * @return array<string, mixed>
 */
function videochat_call_delete_all_endpoint_decode(array $response): array
{
    $payload = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($payload) ? $payload : [];
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-call-delete-all-endpoint-' . bin2hex(random_bytes(6)) . '.sqlite';
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
WHERE roles.slug = 'admin'
ORDER BY users.id ASC
LIMIT 1
SQL
    )->fetchColumn();
    videochat_call_delete_all_endpoint_assert($adminUserId > 0, 'expected seeded admin user');

    $standardUserId = (int) $pdo->query(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE roles.slug = 'user'
ORDER BY users.id ASC
LIMIT 1
SQL
    )->fetchColumn();
    videochat_call_delete_all_endpoint_assert($standardUserId > 0, 'expected seeded standard user');

    $firstCreated = videochat_create_call($pdo, $adminUserId, [
        'room_id' => 'lobby',
        'title' => 'Bulk Delete Contract A',
        'starts_at' => '2026-06-12T09:00:00Z',
        'ends_at' => '2026-06-12T10:00:00Z',
        'internal_participant_user_ids' => [$standardUserId],
    ]);
    $secondCreated = videochat_create_call($pdo, $standardUserId, [
        'room_id' => 'lobby',
        'title' => 'Bulk Delete Contract B',
        'starts_at' => '2026-06-13T09:00:00Z',
        'ends_at' => '2026-06-13T10:00:00Z',
        'internal_participant_user_ids' => [$adminUserId],
    ]);
    videochat_call_delete_all_endpoint_assert((bool) ($firstCreated['ok'] ?? false), 'first setup call should succeed');
    videochat_call_delete_all_endpoint_assert((bool) ($secondCreated['ok'] ?? false), 'second setup call should succeed');
    videochat_call_delete_all_endpoint_assert((int) $pdo->query('SELECT COUNT(*) FROM calls')->fetchColumn() === 2, 'setup call count mismatch');
    videochat_call_delete_all_endpoint_assert((int) $pdo->query('SELECT COUNT(*) FROM call_participants')->fetchColumn() > 0, 'setup participants should exist');

    $adminSessionId = 'sess_call_delete_all_endpoint_admin';
    $userSessionId = 'sess_call_delete_all_endpoint_user';
    $insertSession = $pdo->prepare(
        <<<'SQL'
INSERT INTO sessions(id, user_id, issued_at, expires_at, revoked_at, client_ip, user_agent)
VALUES(:id, :user_id, :issued_at, :expires_at, NULL, '127.0.0.1', :user_agent)
SQL
    );
    $insertSession->execute([
        ':id' => $adminSessionId,
        ':user_id' => $adminUserId,
        ':issued_at' => gmdate('c', time() - 60),
        ':expires_at' => gmdate('c', time() + 3600),
        ':user_agent' => 'call-delete-all-endpoint-contract-admin',
    ]);
    $insertSession->execute([
        ':id' => $userSessionId,
        ':user_id' => $standardUserId,
        ':issued_at' => gmdate('c', time() - 60),
        ':expires_at' => gmdate('c', time() + 3600),
        ':user_agent' => 'call-delete-all-endpoint-contract-user',
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

    $adminAuth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'DELETE',
            'uri' => '/api/calls',
            'headers' => ['Authorization' => 'Bearer ' . $adminSessionId],
        ],
        'rest'
    );
    videochat_call_delete_all_endpoint_assert((bool) ($adminAuth['ok'] ?? false), 'expected valid admin auth context');

    $userAuth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'DELETE',
            'uri' => '/api/calls',
            'headers' => ['Authorization' => 'Bearer ' . $userSessionId],
        ],
        'rest'
    );
    videochat_call_delete_all_endpoint_assert((bool) ($userAuth['ok'] ?? false), 'expected valid user auth context');

    $userDelete = videochat_handle_call_routes(
        '/api/calls',
        'DELETE',
        [
            'method' => 'DELETE',
            'uri' => '/api/calls',
            'headers' => ['Authorization' => 'Bearer ' . $userSessionId],
            'remote_address' => '127.0.0.1',
            'body' => json_encode(['confirm' => 'delete_all_calls'], JSON_UNESCAPED_SLASHES),
        ],
        $userAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_delete_all_endpoint_assert(is_array($userDelete), 'user delete response must be an array');
    videochat_call_delete_all_endpoint_assert((int) ($userDelete['status'] ?? 0) === 403, 'user delete all status should be 403');

    $missingConfirm = videochat_handle_call_routes(
        '/api/calls',
        'DELETE',
        [
            'method' => 'DELETE',
            'uri' => '/api/calls',
            'headers' => ['Authorization' => 'Bearer ' . $adminSessionId],
            'remote_address' => '127.0.0.1',
            'body' => json_encode(['confirm' => 'delete'], JSON_UNESCAPED_SLASHES),
        ],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_delete_all_endpoint_assert(is_array($missingConfirm), 'missing-confirm response must be an array');
    videochat_call_delete_all_endpoint_assert((int) ($missingConfirm['status'] ?? 0) === 422, 'missing-confirm status should be 422');

    $adminDelete = videochat_handle_call_routes(
        '/api/calls',
        'DELETE',
        [
            'method' => 'DELETE',
            'uri' => '/api/calls',
            'headers' => ['Authorization' => 'Bearer ' . $adminSessionId],
            'remote_address' => '127.0.0.1',
            'body' => json_encode(['confirm' => 'delete_all_calls'], JSON_UNESCAPED_SLASHES),
        ],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_delete_all_endpoint_assert(is_array($adminDelete), 'admin delete response must be an array');
    videochat_call_delete_all_endpoint_assert((int) ($adminDelete['status'] ?? 0) === 200, 'admin delete all status should be 200');
    $adminDeletePayload = videochat_call_delete_all_endpoint_decode($adminDelete);
    videochat_call_delete_all_endpoint_assert((string) ($adminDeletePayload['status'] ?? '') === 'ok', 'admin delete all payload status mismatch');
    videochat_call_delete_all_endpoint_assert((string) ((($adminDeletePayload['result'] ?? [])['state'] ?? '')) === 'all_deleted', 'admin delete all result state mismatch');
    videochat_call_delete_all_endpoint_assert((int) ((($adminDeletePayload['result'] ?? [])['deleted_count'] ?? 0)) === 2, 'admin delete all count mismatch');
    videochat_call_delete_all_endpoint_assert((int) $pdo->query('SELECT COUNT(*) FROM calls')->fetchColumn() === 0, 'calls should be deleted');
    videochat_call_delete_all_endpoint_assert((int) $pdo->query('SELECT COUNT(*) FROM call_participants')->fetchColumn() === 0, 'participants should cascade-delete');

    fwrite(STDOUT, "[call-delete-all-endpoint-contract] PASS\n");
} catch (Throwable $error) {
    fwrite(STDERR, "[call-delete-all-endpoint-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
} finally {
    if (isset($databasePath) && is_file($databasePath)) {
        @unlink($databasePath);
    }
}
