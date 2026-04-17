<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/users/user_management.php';
require_once __DIR__ . '/../http/module_users.php';

function videochat_admin_user_create_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[admin-user-create-contract] FAIL: {$message}\n");
    exit(1);
}

/**
 * @return array<string, mixed>
 */
function videochat_admin_user_create_decode_response(array $response): array
{
    $payload = json_decode((string) ($response['body'] ?? ''), true);
    if (!is_array($payload)) {
        return [];
    }

    return $payload;
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-admin-user-create-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);

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
        'token' => 'sess_admin_contract',
        'user' => [
            'id' => 1,
            'email' => 'admin@intelligent-intern.com',
            'display_name' => 'Admin',
            'role' => 'admin',
            'status' => 'active',
        ],
        'session' => ['id' => 'sess_admin_contract'],
    ];

    $invalidJson = videochat_handle_user_routes(
        '/api/admin/users',
        'POST',
        ['method' => 'POST', 'uri' => '/api/admin/users', 'body' => 'not-json'],
        $apiAuthContext,
        [],
        sys_get_temp_dir(),
        512000,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_admin_user_create_assert(is_array($invalidJson), 'invalid-json response must be an array');
    videochat_admin_user_create_assert((int) ($invalidJson['status'] ?? 0) === 400, 'invalid-json status should be 400');
    $invalidJsonPayload = videochat_admin_user_create_decode_response($invalidJson);
    videochat_admin_user_create_assert((string) ($invalidJsonPayload['status'] ?? '') === 'error', 'invalid-json payload should be error');
    videochat_admin_user_create_assert(
        (string) (($invalidJsonPayload['error'] ?? [])['code'] ?? '') === 'admin_user_invalid_request_body',
        'invalid-json error code mismatch'
    );

    $invalidPayload = videochat_handle_user_routes(
        '/api/admin/users',
        'POST',
        [
            'method' => 'POST',
            'uri' => '/api/admin/users',
            'body' => json_encode([
                'email' => 'not-an-email',
                'display_name' => '',
                'password' => '123',
                'role' => 'bad-role',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $apiAuthContext,
        [],
        sys_get_temp_dir(),
        512000,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_admin_user_create_assert(is_array($invalidPayload), 'invalid payload response must be an array');
    videochat_admin_user_create_assert((int) ($invalidPayload['status'] ?? 0) === 422, 'invalid payload status should be 422');
    $invalidPayloadBody = videochat_admin_user_create_decode_response($invalidPayload);
    videochat_admin_user_create_assert(
        (string) (($invalidPayloadBody['error'] ?? [])['code'] ?? '') === 'admin_user_validation_failed',
        'invalid payload error code mismatch'
    );
    videochat_admin_user_create_assert(
        (string) (((($invalidPayloadBody['error'] ?? [])['details'] ?? [])['fields'] ?? [])['role'] ?? '') === 'required_valid_role',
        'invalid payload role error mismatch'
    );

    $createdResponse = videochat_handle_user_routes(
        '/api/admin/users',
        'POST',
        [
            'method' => 'POST',
            'uri' => '/api/admin/users',
            'body' => json_encode([
                'email' => 'create-contract-user@intelligent-intern.com',
                'display_name' => 'Create Contract User',
                'password' => 'create-contract-password',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $apiAuthContext,
        [],
        sys_get_temp_dir(),
        512000,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_admin_user_create_assert(is_array($createdResponse), 'create response must be an array');
    videochat_admin_user_create_assert((int) ($createdResponse['status'] ?? 0) === 201, 'create status should be 201');

    $createdPayload = videochat_admin_user_create_decode_response($createdResponse);
    videochat_admin_user_create_assert((string) ($createdPayload['status'] ?? '') === 'ok', 'create payload status mismatch');
    videochat_admin_user_create_assert(
        (string) (($createdPayload['result'] ?? [])['state'] ?? '') === 'created',
        'create payload state mismatch'
    );
    $createdUser = ($createdPayload['result'] ?? [])['user'] ?? null;
    videochat_admin_user_create_assert(is_array($createdUser), 'create payload user should be an array');
    videochat_admin_user_create_assert(
        (string) ($createdUser['role'] ?? '') === 'user',
        'create payload role should default to user'
    );

    $pdo = videochat_open_sqlite_pdo($databasePath);
    $persistedUser = $pdo->prepare(
        <<<'SQL'
SELECT users.email, roles.slug AS role_slug
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE lower(users.email) = lower(:email)
LIMIT 1
SQL
    );
    $persistedUser->execute([':email' => 'create-contract-user@intelligent-intern.com']);
    $persistedRow = $persistedUser->fetch();
    videochat_admin_user_create_assert(is_array($persistedRow), 'created user should be persisted');
    videochat_admin_user_create_assert(
        (string) ($persistedRow['role_slug'] ?? '') === 'user',
        'persisted user role should default to user'
    );

    $duplicateResponse = videochat_handle_user_routes(
        '/api/admin/users',
        'POST',
        [
            'method' => 'POST',
            'uri' => '/api/admin/users',
            'body' => json_encode([
                'email' => 'create-contract-user@intelligent-intern.com',
                'display_name' => 'Create Contract User Duplicate',
                'password' => 'create-contract-password',
                'role' => 'moderator',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $apiAuthContext,
        [],
        sys_get_temp_dir(),
        512000,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_admin_user_create_assert(is_array($duplicateResponse), 'duplicate response must be an array');
    videochat_admin_user_create_assert((int) ($duplicateResponse['status'] ?? 0) === 409, 'duplicate status should be 409');

    $duplicatePayload = videochat_admin_user_create_decode_response($duplicateResponse);
    videochat_admin_user_create_assert(
        (string) (($duplicatePayload['error'] ?? [])['code'] ?? '') === 'admin_user_conflict',
        'duplicate error code mismatch'
    );
    videochat_admin_user_create_assert(
        (string) (((($duplicatePayload['error'] ?? [])['details'] ?? [])['fields'] ?? [])['email'] ?? '') === 'already_exists',
        'duplicate email field mismatch'
    );

    @unlink($databasePath);
    fwrite(STDOUT, "[admin-user-create-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[admin-user-create-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
