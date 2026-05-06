<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/users/user_management.php';
require_once __DIR__ . '/../http/module_users.php';

function videochat_admin_user_update_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[admin-user-update-contract] FAIL: {$message}\n");
    exit(1);
}

/**
 * @return array<string, mixed>
 */
function videochat_admin_user_update_decode_response(array $response): array
{
    $payload = json_decode((string) ($response['body'] ?? ''), true);
    if (!is_array($payload)) {
        return [];
    }

    return $payload;
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-admin-user-update-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $targetCreate = videochat_admin_create_user($pdo, [
        'email' => 'patch-user@intelligent-intern.com',
        'display_name' => 'Patch User',
        'password' => 'patch-user-password',
        'role' => 'user',
    ]);
    videochat_admin_user_update_assert((bool) ($targetCreate['ok'] ?? false), 'target create should succeed');
    $targetUserId = (int) (($targetCreate['user'] ?? [])['id'] ?? 0);
    videochat_admin_user_update_assert($targetUserId > 0, 'target user id should be positive');

    $conflictCreate = videochat_admin_create_user($pdo, [
        'email' => 'patch-conflict@intelligent-intern.com',
        'display_name' => 'Patch Conflict',
        'password' => 'patch-conflict-password',
        'role' => 'user',
    ]);
    videochat_admin_user_update_assert((bool) ($conflictCreate['ok'] ?? false), 'conflict create should succeed');

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
        'token' => 'sess_admin_update_contract',
        'user' => [
            'id' => 1,
            'email' => 'admin@intelligent-intern.com',
            'display_name' => 'Admin',
            'role' => 'admin',
            'status' => 'active',
        ],
        'session' => ['id' => 'sess_admin_update_contract'],
    ];

    $invalidJsonResponse = videochat_handle_user_routes(
        '/api/admin/users/' . $targetUserId,
        'PATCH',
        ['method' => 'PATCH', 'uri' => '/api/admin/users/' . $targetUserId, 'body' => 'invalid-json'],
        $apiAuthContext,
        [],
        sys_get_temp_dir(),
        512000,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_admin_user_update_assert(is_array($invalidJsonResponse), 'invalid-json response must be an array');
    videochat_admin_user_update_assert((int) ($invalidJsonResponse['status'] ?? 0) === 400, 'invalid-json status should be 400');
    $invalidJsonPayload = videochat_admin_user_update_decode_response($invalidJsonResponse);
    videochat_admin_user_update_assert(
        (string) (($invalidJsonPayload['error'] ?? [])['code'] ?? '') === 'admin_user_invalid_request_body',
        'invalid-json error code mismatch'
    );

    $unknownFieldResponse = videochat_handle_user_routes(
        '/api/admin/users/' . $targetUserId,
        'PATCH',
        [
            'method' => 'PATCH',
            'uri' => '/api/admin/users/' . $targetUserId,
            'body' => json_encode([
                'created_at' => '2026-01-01T00:00:00Z',
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
    videochat_admin_user_update_assert(is_array($unknownFieldResponse), 'unknown-field response must be an array');
    videochat_admin_user_update_assert((int) ($unknownFieldResponse['status'] ?? 0) === 422, 'unknown-field status should be 422');
    $unknownFieldPayload = videochat_admin_user_update_decode_response($unknownFieldResponse);
    videochat_admin_user_update_assert(
        (string) (($unknownFieldPayload['error'] ?? [])['code'] ?? '') === 'admin_user_validation_failed',
        'unknown-field error code mismatch'
    );
    videochat_admin_user_update_assert(
        (string) (((($unknownFieldPayload['error'] ?? [])['details'] ?? [])['fields'] ?? [])['created_at'] ?? '') === 'field_not_updatable',
        'unknown-field validation mismatch'
    );

    $mixedFailClosedResponse = videochat_handle_user_routes(
        '/api/admin/users/' . $targetUserId,
        'PATCH',
        [
            'method' => 'PATCH',
            'uri' => '/api/admin/users/' . $targetUserId,
            'body' => json_encode([
                'display_name' => 'Should Not Persist',
                'created_at' => '2026-01-01T00:00:00Z',
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
    videochat_admin_user_update_assert(is_array($mixedFailClosedResponse), 'mixed fail-closed response must be an array');
    videochat_admin_user_update_assert((int) ($mixedFailClosedResponse['status'] ?? 0) === 422, 'mixed fail-closed status should be 422');

    $afterMixedFailClosed = videochat_admin_fetch_user_by_id($pdo, $targetUserId);
    videochat_admin_user_update_assert(is_array($afterMixedFailClosed), 'target user should still exist after mixed fail-closed');
    videochat_admin_user_update_assert(
        (string) ($afterMixedFailClosed['display_name'] ?? '') === 'Patch User',
        'mixed fail-closed should not persist valid fields when unsupported field is present'
    );

    $validUpdateResponse = videochat_handle_user_routes(
        '/api/admin/users/' . $targetUserId,
        'PATCH',
        [
            'method' => 'PATCH',
            'uri' => '/api/admin/users/' . $targetUserId,
            'body' => json_encode([
                'email' => '  PATCH-USER-UPDATED@Intelligent-Intern.com  ',
                'display_name' => '  Patch User Updated  ',
                'role' => 'moderator',
                'status' => 'disabled',
                'time_format' => '12h',
                'theme' => 'light',
                'theme_editor_enabled' => true,
                'avatar_path' => ' /avatars/patch-updated.png ',
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
    videochat_admin_user_update_assert(is_array($validUpdateResponse), 'valid update response must be an array');
    videochat_admin_user_update_assert((int) ($validUpdateResponse['status'] ?? 0) === 200, 'valid update status should be 200');
    $validUpdatePayload = videochat_admin_user_update_decode_response($validUpdateResponse);
    videochat_admin_user_update_assert((string) ($validUpdatePayload['status'] ?? '') === 'ok', 'valid update payload status mismatch');
    videochat_admin_user_update_assert(
        (string) (($validUpdatePayload['result'] ?? [])['state'] ?? '') === 'updated',
        'valid update state mismatch'
    );
    $updatedUser = ($validUpdatePayload['result'] ?? [])['user'] ?? null;
    videochat_admin_user_update_assert(is_array($updatedUser), 'valid update user payload should be an array');
    videochat_admin_user_update_assert(
        (string) ($updatedUser['email'] ?? '') === 'patch-user-updated@intelligent-intern.com',
        'updated email should be normalized'
    );
    videochat_admin_user_update_assert(
        (string) ($updatedUser['display_name'] ?? '') === 'Patch User Updated',
        'updated display_name should be normalized'
    );
    videochat_admin_user_update_assert(
        (string) ($updatedUser['role'] ?? '') === 'moderator',
        'updated role mismatch'
    );
    videochat_admin_user_update_assert(
        (string) ($updatedUser['status'] ?? '') === 'disabled',
        'updated status mismatch'
    );
    videochat_admin_user_update_assert(
        (string) ($updatedUser['time_format'] ?? '') === '12h',
        'updated time_format mismatch'
    );
    videochat_admin_user_update_assert(
        (string) ($updatedUser['theme'] ?? '') === 'light',
        'updated theme mismatch'
    );
    videochat_admin_user_update_assert(
        ($updatedUser['theme_editor_enabled'] ?? null) === true,
        'updated theme editor permission mismatch'
    );
    videochat_admin_user_update_assert(
        (string) ($updatedUser['avatar_path'] ?? '') === '/avatars/patch-updated.png',
        'updated avatar_path should be trimmed'
    );

    $selfThemeEditorResponse = videochat_handle_user_routes(
        '/api/admin/users/1',
        'PATCH',
        [
            'method' => 'PATCH',
            'uri' => '/api/admin/users/1',
            'body' => json_encode([
                'theme_editor_enabled' => false,
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
    videochat_admin_user_update_assert(is_array($selfThemeEditorResponse), 'self theme-editor response must be an array');
    videochat_admin_user_update_assert((int) ($selfThemeEditorResponse['status'] ?? 0) === 409, 'self theme-editor status should be 409');
    $selfThemeEditorPayload = videochat_admin_user_update_decode_response($selfThemeEditorResponse);
    videochat_admin_user_update_assert(
        (string) (((($selfThemeEditorPayload['error'] ?? [])['details'] ?? [])['fields'] ?? [])['theme_editor_enabled'] ?? '') === 'cannot_change_own_theme_editor_access',
        'self theme-editor conflict field mismatch'
    );

    $duplicateResponse = videochat_handle_user_routes(
        '/api/admin/users/' . $targetUserId,
        'PATCH',
        [
            'method' => 'PATCH',
            'uri' => '/api/admin/users/' . $targetUserId,
            'body' => json_encode([
                'email' => 'patch-conflict@intelligent-intern.com',
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
    videochat_admin_user_update_assert(is_array($duplicateResponse), 'duplicate response must be an array');
    videochat_admin_user_update_assert((int) ($duplicateResponse['status'] ?? 0) === 409, 'duplicate status should be 409');
    $duplicatePayload = videochat_admin_user_update_decode_response($duplicateResponse);
    videochat_admin_user_update_assert(
        (string) (($duplicatePayload['error'] ?? [])['code'] ?? '') === 'admin_user_conflict',
        'duplicate error code mismatch'
    );
    videochat_admin_user_update_assert(
        (string) (((($duplicatePayload['error'] ?? [])['details'] ?? [])['fields'] ?? [])['email'] ?? '') === 'already_exists',
        'duplicate email conflict field mismatch'
    );

    $notFoundResponse = videochat_handle_user_routes(
        '/api/admin/users/999999',
        'PATCH',
        [
            'method' => 'PATCH',
            'uri' => '/api/admin/users/999999',
            'body' => json_encode([
                'display_name' => 'Should Not Exist',
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
    videochat_admin_user_update_assert(is_array($notFoundResponse), 'not-found response must be an array');
    videochat_admin_user_update_assert((int) ($notFoundResponse['status'] ?? 0) === 404, 'not-found status should be 404');
    $notFoundPayload = videochat_admin_user_update_decode_response($notFoundResponse);
    videochat_admin_user_update_assert(
        (string) (($notFoundPayload['error'] ?? [])['code'] ?? '') === 'admin_user_not_found',
        'not-found error code mismatch'
    );

    @unlink($databasePath);
    fwrite(STDOUT, "[admin-user-update-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[admin-user-update-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
