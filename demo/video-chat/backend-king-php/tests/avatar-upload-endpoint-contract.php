<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/users/avatar_upload.php';
require_once __DIR__ . '/../http/module_users.php';
require_once __DIR__ . '/../http/module_auth_session.php';

function videochat_avatar_upload_endpoint_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[avatar-upload-endpoint-contract] FAIL: {$message}\n");
    exit(1);
}

/**
 * @return array<string, mixed>
 */
function videochat_avatar_upload_endpoint_decode(array $response): array
{
    $payload = json_decode((string) ($response['body'] ?? ''), true);
    if (!is_array($payload)) {
        return [];
    }

    return $payload;
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-avatar-endpoint-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }
    $storageRoot = sys_get_temp_dir() . '/videochat-avatar-endpoint-storage-' . bin2hex(random_bytes(6));

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $userQuery = $pdo->query(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE lower(users.email) = lower('user@intelligent-intern.com')
LIMIT 1
SQL
    );
    $userId = (int) $userQuery->fetchColumn();
    videochat_avatar_upload_endpoint_assert($userId > 0, 'expected seeded user account');

    $sessionId = 'sess_user_avatar_endpoint_contract';
    $insertSession = $pdo->prepare(
        <<<'SQL'
INSERT INTO sessions(id, user_id, issued_at, expires_at, revoked_at, client_ip, user_agent)
VALUES(:id, :user_id, :issued_at, :expires_at, NULL, '127.0.0.1', 'avatar-upload-endpoint-contract')
SQL
    );
    $insertSession->execute([
        ':id' => $sessionId,
        ':user_id' => $userId,
        ':issued_at' => gmdate('c', time() - 60),
        ':expires_at' => gmdate('c', time() + 3600),
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

    $requestTemplate = [
        'uri' => '/api/user/avatar',
        'headers' => ['Authorization' => 'Bearer ' . $sessionId],
        'remote_address' => '127.0.0.1',
    ];

    $apiAuthContext = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'POST',
            'uri' => '/api/user/avatar',
            'headers' => ['Authorization' => 'Bearer ' . $sessionId],
        ],
        'rest'
    );
    videochat_avatar_upload_endpoint_assert((bool) ($apiAuthContext['ok'] ?? false), 'auth context should be valid');

    $invalidJson = videochat_handle_user_routes(
        '/api/user/avatar',
        'POST',
        [...$requestTemplate, 'method' => 'POST', 'body' => 'not-json'],
        $apiAuthContext,
        [],
        $storageRoot,
        1024 * 1024,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_avatar_upload_endpoint_assert(is_array($invalidJson), 'invalid-json avatar response must be an array');
    videochat_avatar_upload_endpoint_assert((int) ($invalidJson['status'] ?? 0) === 400, 'invalid-json avatar status should be 400');
    $invalidJsonPayload = videochat_avatar_upload_endpoint_decode($invalidJson);
    videochat_avatar_upload_endpoint_assert(
        (string) (($invalidJsonPayload['error'] ?? [])['code'] ?? '') === 'user_avatar_invalid_request_body',
        'invalid-json avatar error code mismatch'
    );

    $onePixelPngBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO3Z8iUAAAAASUVORK5CYII=';
    $pngBinary = base64_decode($onePixelPngBase64, true);
    videochat_avatar_upload_endpoint_assert(is_string($pngBinary) && $pngBinary !== '', 'expected PNG binary fixture');

    $declaredTypeMismatch = videochat_handle_user_routes(
        '/api/user/avatar',
        'POST',
        [
            ...$requestTemplate,
            'method' => 'POST',
            'body' => json_encode([
                'content_type' => 'image/jpeg',
                'content_base64' => $onePixelPngBase64,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $apiAuthContext,
        [],
        $storageRoot,
        1024 * 1024,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_avatar_upload_endpoint_assert(is_array($declaredTypeMismatch), 'mismatch avatar response must be an array');
    videochat_avatar_upload_endpoint_assert((int) ($declaredTypeMismatch['status'] ?? 0) === 422, 'declared-type mismatch status should be 422');
    $declaredTypeMismatchPayload = videochat_avatar_upload_endpoint_decode($declaredTypeMismatch);
    videochat_avatar_upload_endpoint_assert(
        (string) (($declaredTypeMismatchPayload['error'] ?? [])['code'] ?? '') === 'user_avatar_validation_failed',
        'declared-type mismatch error code mismatch'
    );
    videochat_avatar_upload_endpoint_assert(
        (string) (((($declaredTypeMismatchPayload['error'] ?? [])['details'] ?? [])['fields'] ?? [])['content_type'] ?? '') === 'declared_type_mismatch',
        'declared-type mismatch field details mismatch'
    );

    $uploadFirst = videochat_handle_user_routes(
        '/api/user/avatar',
        'POST',
        [
            ...$requestTemplate,
            'method' => 'POST',
            'body' => json_encode([
                'content_type' => 'image/png',
                'content_base64' => $onePixelPngBase64,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $apiAuthContext,
        [],
        $storageRoot,
        1024 * 1024,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_avatar_upload_endpoint_assert(is_array($uploadFirst), 'first avatar upload response must be an array');
    videochat_avatar_upload_endpoint_assert((int) ($uploadFirst['status'] ?? 0) === 201, 'first avatar upload status should be 201');
    $uploadFirstPayload = videochat_avatar_upload_endpoint_decode($uploadFirst);
    videochat_avatar_upload_endpoint_assert((string) ($uploadFirstPayload['status'] ?? '') === 'ok', 'first avatar upload payload status mismatch');
    videochat_avatar_upload_endpoint_assert(
        (string) (((($uploadFirstPayload['result'] ?? [])['state'] ?? ''))) === 'uploaded',
        'first avatar upload state mismatch'
    );

    $firstFileName = (string) (((($uploadFirstPayload['result'] ?? [])['file_name'] ?? '')));
    videochat_avatar_upload_endpoint_assert(
        preg_match('/^[A-Za-z0-9._-]{1,200}$/', $firstFileName) === 1,
        'first avatar filename must match storage-safe pattern'
    );
    $firstAvatarPath = (string) (((($uploadFirstPayload['result'] ?? [])['avatar_path'] ?? '')));
    videochat_avatar_upload_endpoint_assert(
        $firstAvatarPath === '/api/user/avatar-files/' . rawurlencode($firstFileName),
        'first avatar path should deterministically map to filename'
    );
    videochat_avatar_upload_endpoint_assert(
        (string) (((($uploadFirstPayload['result'] ?? [])['content_type'] ?? ''))) === 'image/png',
        'first avatar content_type mismatch'
    );
    videochat_avatar_upload_endpoint_assert(
        (int) (((($uploadFirstPayload['result'] ?? [])['bytes'] ?? 0))) === strlen($pngBinary),
        'first avatar byte count mismatch'
    );

    $firstResolvedPath = videochat_avatar_resolve_read_path($storageRoot, $firstFileName);
    videochat_avatar_upload_endpoint_assert(is_string($firstResolvedPath), 'first avatar file should resolve in storage root');

    $uploadSecond = videochat_handle_user_routes(
        '/api/user/avatar',
        'POST',
        [
            ...$requestTemplate,
            'method' => 'POST',
            'body' => json_encode([
                'data_url' => 'data:image/png;base64,' . $onePixelPngBase64,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $apiAuthContext,
        [],
        $storageRoot,
        1024 * 1024,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_avatar_upload_endpoint_assert(is_array($uploadSecond), 'second avatar upload response must be an array');
    videochat_avatar_upload_endpoint_assert((int) ($uploadSecond['status'] ?? 0) === 201, 'second avatar upload status should be 201');
    $uploadSecondPayload = videochat_avatar_upload_endpoint_decode($uploadSecond);
    $secondFileName = (string) (((($uploadSecondPayload['result'] ?? [])['file_name'] ?? '')));
    videochat_avatar_upload_endpoint_assert($secondFileName !== '' && $secondFileName !== $firstFileName, 'second avatar filename should rotate');
    $secondAvatarPath = (string) (((($uploadSecondPayload['result'] ?? [])['avatar_path'] ?? '')));
    videochat_avatar_upload_endpoint_assert(
        $secondAvatarPath === '/api/user/avatar-files/' . rawurlencode($secondFileName),
        'second avatar path should deterministically map to filename'
    );

    $firstResolvedAfterReplacement = videochat_avatar_resolve_read_path($storageRoot, $firstFileName);
    videochat_avatar_upload_endpoint_assert($firstResolvedAfterReplacement === null, 'first avatar file should be removed after replacement');

    $fetchSecondAvatar = videochat_handle_user_routes(
        '/api/user/avatar-files/' . rawurlencode($secondFileName),
        'GET',
        [...$requestTemplate, 'method' => 'GET', 'uri' => '/api/user/avatar-files/' . rawurlencode($secondFileName), 'body' => ''],
        $apiAuthContext,
        [],
        $storageRoot,
        1024 * 1024,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_avatar_upload_endpoint_assert(is_array($fetchSecondAvatar), 'avatar fetch response must be an array');
    videochat_avatar_upload_endpoint_assert((int) ($fetchSecondAvatar['status'] ?? 0) === 200, 'avatar fetch status should be 200');
    videochat_avatar_upload_endpoint_assert(
        (string) (($fetchSecondAvatar['headers']['content-type'] ?? '')) === 'image/png',
        'avatar fetch content-type header mismatch'
    );
    videochat_avatar_upload_endpoint_assert(
        (string) (($fetchSecondAvatar['headers']['cache-control'] ?? '')) === 'private, max-age=60',
        'avatar fetch cache-control header mismatch'
    );
    videochat_avatar_upload_endpoint_assert(
        (string) ($fetchSecondAvatar['body'] ?? '') === $pngBinary,
        'avatar fetch body should equal uploaded PNG binary'
    );

    $missingAvatar = videochat_handle_user_routes(
        '/api/user/avatar-files/not-found-avatar.png',
        'GET',
        [...$requestTemplate, 'method' => 'GET', 'uri' => '/api/user/avatar-files/not-found-avatar.png', 'body' => ''],
        $apiAuthContext,
        [],
        $storageRoot,
        1024 * 1024,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_avatar_upload_endpoint_assert(is_array($missingAvatar), 'missing avatar response must be an array');
    videochat_avatar_upload_endpoint_assert((int) ($missingAvatar['status'] ?? 0) === 404, 'missing avatar status should be 404');
    $missingAvatarPayload = videochat_avatar_upload_endpoint_decode($missingAvatar);
    videochat_avatar_upload_endpoint_assert(
        (string) (($missingAvatarPayload['error'] ?? [])['code'] ?? '') === 'user_avatar_not_found',
        'missing avatar error code mismatch'
    );

    $traversalRouteMatch = videochat_handle_user_routes(
        '/api/user/avatar-files/..%2Fsecret.png',
        'GET',
        [...$requestTemplate, 'method' => 'GET', 'uri' => '/api/user/avatar-files/..%2Fsecret.png', 'body' => ''],
        $apiAuthContext,
        [],
        $storageRoot,
        1024 * 1024,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_avatar_upload_endpoint_assert(
        $traversalRouteMatch === null,
        'encoded traversal avatar path must not match avatar-files route contract'
    );

    $reauth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/api/auth/session',
            'headers' => ['Authorization' => 'Bearer ' . $sessionId],
        ],
        'rest'
    );
    videochat_avatar_upload_endpoint_assert((bool) ($reauth['ok'] ?? false), 'reauth should stay valid after avatar upload');

    $activeWebsocketsBySession = [];
    $issueSessionId = static fn (): string => 'sess_unused_avatar_endpoint';
    $sessionResponse = videochat_handle_auth_session_routes(
        '/api/auth/session',
        'GET',
        ['method' => 'GET', 'uri' => '/api/auth/session', 'headers' => ['Authorization' => 'Bearer ' . $sessionId]],
        $reauth,
        $activeWebsocketsBySession,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase,
        $issueSessionId
    );
    videochat_avatar_upload_endpoint_assert(is_array($sessionResponse), 'session-check response must be an array');
    videochat_avatar_upload_endpoint_assert((int) ($sessionResponse['status'] ?? 0) === 200, 'session-check status should be 200');
    $sessionPayload = videochat_avatar_upload_endpoint_decode($sessionResponse);
    videochat_avatar_upload_endpoint_assert((string) ($sessionPayload['status'] ?? '') === 'ok', 'session-check payload status mismatch');
    videochat_avatar_upload_endpoint_assert(
        (string) ((($sessionPayload['user'] ?? [])['avatar_path'] ?? '')) === $secondAvatarPath,
        'session-check should reflect latest avatar_path'
    );

    $invalidContextResponse = videochat_handle_user_routes(
        '/api/user/avatar',
        'POST',
        [...$requestTemplate, 'method' => 'POST', 'body' => '{}'],
        ['user' => ['id' => 0]],
        [],
        $storageRoot,
        1024 * 1024,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_avatar_upload_endpoint_assert(is_array($invalidContextResponse), 'invalid-context avatar response must be an array');
    videochat_avatar_upload_endpoint_assert((int) ($invalidContextResponse['status'] ?? 0) === 401, 'invalid-context avatar status should be 401');

    $invalidMethodUpload = videochat_handle_user_routes(
        '/api/user/avatar',
        'GET',
        [...$requestTemplate, 'method' => 'GET', 'body' => ''],
        $apiAuthContext,
        [],
        $storageRoot,
        1024 * 1024,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_avatar_upload_endpoint_assert(is_array($invalidMethodUpload), 'avatar invalid-method response must be an array');
    videochat_avatar_upload_endpoint_assert((int) ($invalidMethodUpload['status'] ?? 0) === 405, 'avatar invalid-method status should be 405');

    $invalidMethodFetch = videochat_handle_user_routes(
        '/api/user/avatar-files/' . rawurlencode($secondFileName),
        'POST',
        [...$requestTemplate, 'method' => 'POST', 'uri' => '/api/user/avatar-files/' . rawurlencode($secondFileName), 'body' => ''],
        $apiAuthContext,
        [],
        $storageRoot,
        1024 * 1024,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_avatar_upload_endpoint_assert(is_array($invalidMethodFetch), 'avatar-files invalid-method response must be an array');
    videochat_avatar_upload_endpoint_assert((int) ($invalidMethodFetch['status'] ?? 0) === 405, 'avatar-files invalid-method status should be 405');

    if (is_dir($storageRoot)) {
        $entries = scandir($storageRoot);
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                @unlink($storageRoot . DIRECTORY_SEPARATOR . $entry);
            }
        }
        @rmdir($storageRoot);
    }
    @unlink($databasePath);

    fwrite(STDOUT, "[avatar-upload-endpoint-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[avatar-upload-endpoint-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
