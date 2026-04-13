<?php

declare(strict_types=1);

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../http/router.php';

function videochat_rbac_middleware_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[rbac-middleware-contract] FAIL: {$message}\n");
    exit(1);
}

/**
 * @return array<string, mixed>
 */
function videochat_rbac_middleware_decode_body(array $response): array
{
    $body = $response['body'] ?? '';
    if (!is_string($body) || trim($body) === '') {
        return [];
    }

    try {
        $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        fwrite(STDERR, "[rbac-middleware-contract] FAIL: response body is not valid JSON: {$error->getMessage()}\n");
        exit(1);
    }

    return is_array($decoded) ? $decoded : [];
}

/**
 * @return array{admin_user_id: int, moderator_user_id: int, user_user_id: int}
 */
function videochat_rbac_middleware_seed_users(PDO $pdo): array
{
    $adminUserId = (int) $pdo->query(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE lower(users.email) = lower('admin@intelligent-intern.com')
LIMIT 1
SQL
    )->fetchColumn();
    videochat_rbac_middleware_assert($adminUserId > 0, 'expected seeded admin user');

    $userUserId = (int) $pdo->query(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE lower(users.email) = lower('user@intelligent-intern.com')
LIMIT 1
SQL
    )->fetchColumn();
    videochat_rbac_middleware_assert($userUserId > 0, 'expected seeded user account');

    $moderatorRoleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'moderator' LIMIT 1")->fetchColumn();
    videochat_rbac_middleware_assert($moderatorRoleId > 0, 'expected moderator role');

    $existingModeratorId = (int) $pdo->query(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE lower(users.email) = lower('moderator@intelligent-intern.com')
LIMIT 1
SQL
    )->fetchColumn();
    if ($existingModeratorId > 0) {
        return [
            'admin_user_id' => $adminUserId,
            'moderator_user_id' => $existingModeratorId,
            'user_user_id' => $userUserId,
        ];
    }

    $insertModerator = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', '24h', 'dark', :updated_at)
SQL
    );
    $insertModerator->execute([
        ':email' => 'moderator@intelligent-intern.com',
        ':display_name' => 'Platform Moderator',
        ':password_hash' => password_hash('moderator123', PASSWORD_DEFAULT),
        ':role_id' => $moderatorRoleId,
        ':updated_at' => gmdate('c'),
    ]);

    $moderatorUserId = (int) $pdo->lastInsertId();
    videochat_rbac_middleware_assert($moderatorUserId > 0, 'failed to create moderator user');

    return [
        'admin_user_id' => $adminUserId,
        'moderator_user_id' => $moderatorUserId,
        'user_user_id' => $userUserId,
    ];
}

function videochat_rbac_middleware_seed_session(PDO $pdo, string $sessionId, int $userId): void
{
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO sessions(id, user_id, issued_at, expires_at, revoked_at, client_ip, user_agent)
VALUES(:id, :user_id, :issued_at, :expires_at, NULL, '127.0.0.1', 'rbac-middleware-contract')
SQL
    );
    $insert->execute([
        ':id' => $sessionId,
        ':user_id' => $userId,
        ':issued_at' => gmdate('c', time() - 120),
        ':expires_at' => gmdate('c', time() + 3600),
    ]);
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-rbac-middleware-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);
    $users = videochat_rbac_middleware_seed_users($pdo);

    videochat_rbac_middleware_seed_session($pdo, 'sess_rbac_admin', (int) $users['admin_user_id']);
    videochat_rbac_middleware_seed_session($pdo, 'sess_rbac_moderator', (int) $users['moderator_user_id']);
    videochat_rbac_middleware_seed_session($pdo, 'sess_rbac_user', (int) $users['user_user_id']);

    $jsonResponse = static function (int $status, array $payload, array $headers = []): array {
        return [
            'status' => $status,
            'headers' => $headers,
            'body' => json_encode($payload, JSON_UNESCAPED_SLASHES),
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

    $methodFromRequest = static fn (array $request): string => strtoupper(trim((string) ($request['method'] ?? 'GET')));
    $decodeJsonBody = static function (array $request): array {
        $body = $request['body'] ?? null;
        if (!is_string($body) || trim($body) === '') {
            return [null, 'missing_body'];
        }

        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [null, 'invalid_json'];
        }

        return [is_array($decoded) ? $decoded : null, is_array($decoded) ? null : 'invalid_json_type'];
    };
    $openDatabase = static fn () => videochat_open_sqlite_pdo($databasePath);
    $issueSessionId = static fn () => 'sess_issue_contract';
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
    $runtimeEnvelope = static fn (): array => [
        'service' => 'video-chat-backend-king-php',
        'runtime' => ['ws_path' => '/ws'],
        'time' => gmdate('c'),
    ];
    $wsPath = '/ws';
    $avatarStorageRoot = sys_get_temp_dir() . '/videochat-rbac-avatar-' . bin2hex(random_bytes(4));
    $avatarMaxBytes = 1024 * 1024;

    $activeWebsocketsBySession = [];
    $presenceState = videochat_presence_state_init();
    $lobbyState = videochat_lobby_state_init();
    $typingState = videochat_typing_state_init();

    $dispatch = static function (string $method, string $path, ?string $sessionToken = null) use (
        &$activeWebsocketsBySession,
        &$presenceState,
        &$lobbyState,
        &$typingState,
        $jsonResponse,
        $errorResponse,
        $methodFromRequest,
        $decodeJsonBody,
        $openDatabase,
        $issueSessionId,
        $pathFromRequest,
        $runtimeEnvelope,
        $wsPath,
        $avatarStorageRoot,
        $avatarMaxBytes
    ): array {
        $headers = [];
        if (is_string($sessionToken) && trim($sessionToken) !== '') {
            $headers['Authorization'] = 'Bearer ' . trim($sessionToken);
        }

        return videochat_dispatch_request(
            [
                'method' => strtoupper($method),
                'uri' => $path,
                'path' => $path,
                'headers' => $headers,
            ],
            $activeWebsocketsBySession,
            $presenceState,
            $lobbyState,
            $typingState,
            $jsonResponse,
            $errorResponse,
            $methodFromRequest,
            $decodeJsonBody,
            $openDatabase,
            $issueSessionId,
            $pathFromRequest,
            $runtimeEnvelope,
            $wsPath,
            $avatarStorageRoot,
            $avatarMaxBytes
        );
    };

    $matrix = videochat_rbac_permission_matrix('/socket');
    $websocketRule = null;
    foreach ($matrix as $rule) {
        if ((string) ($rule['id'] ?? '') === 'websocket_gateway') {
            $websocketRule = $rule;
            break;
        }
    }
    videochat_rbac_middleware_assert(is_array($websocketRule), 'websocket gateway rule missing from RBAC matrix');
    videochat_rbac_middleware_assert((string) ($websocketRule['path'] ?? '') === '/socket', 'RBAC websocket rule should respect configured websocket path');

    $userAdminDenied = $dispatch('GET', '/api/admin/ping', 'sess_rbac_user');
    $userAdminDeniedBody = videochat_rbac_middleware_decode_body($userAdminDenied);
    videochat_rbac_middleware_assert((int) ($userAdminDenied['status'] ?? 0) === 403, 'user should be forbidden from admin scope');
    videochat_rbac_middleware_assert((string) (($userAdminDeniedBody['error'] ?? [])['code'] ?? '') === 'rbac_forbidden', 'user/admin deny error code mismatch');
    videochat_rbac_middleware_assert((string) (($userAdminDeniedBody['error']['details'] ?? [])['rule_id'] ?? '') === 'rest_admin_scope', 'user/admin deny rule_id mismatch');
    videochat_rbac_middleware_assert((string) (($userAdminDeniedBody['error']['details'] ?? [])['role'] ?? '') === 'user', 'user/admin deny role mismatch');
    videochat_rbac_middleware_assert((array) (($userAdminDeniedBody['error']['details'] ?? [])['allowed_roles'] ?? []) === ['admin'], 'user/admin deny allowed_roles mismatch');

    $adminAllowed = $dispatch('GET', '/api/admin/ping', 'sess_rbac_admin');
    $adminAllowedBody = videochat_rbac_middleware_decode_body($adminAllowed);
    videochat_rbac_middleware_assert((int) ($adminAllowed['status'] ?? 0) === 200, 'admin should access admin scope');
    videochat_rbac_middleware_assert((string) ($adminAllowedBody['scope'] ?? '') === 'admin', 'admin scope payload mismatch');

    $moderatorDenied = $dispatch('GET', '/api/admin/ping', 'sess_rbac_moderator');
    $moderatorDeniedBody = videochat_rbac_middleware_decode_body($moderatorDenied);
    videochat_rbac_middleware_assert((int) ($moderatorDenied['status'] ?? 0) === 403, 'moderator should be forbidden from admin scope');
    videochat_rbac_middleware_assert((string) (($moderatorDeniedBody['error']['details'] ?? [])['reason'] ?? '') === 'role_not_allowed', 'moderator/admin deny reason mismatch');

    $moderatorAllowed = $dispatch('GET', '/api/moderation/ping', 'sess_rbac_moderator');
    $moderatorAllowedBody = videochat_rbac_middleware_decode_body($moderatorAllowed);
    videochat_rbac_middleware_assert((int) ($moderatorAllowed['status'] ?? 0) === 200, 'moderator should access moderation scope');
    videochat_rbac_middleware_assert((string) ($moderatorAllowedBody['scope'] ?? '') === 'moderation', 'moderation scope payload mismatch');

    $userModerationDenied = $dispatch('GET', '/api/moderation/ping', 'sess_rbac_user');
    $userModerationDeniedBody = videochat_rbac_middleware_decode_body($userModerationDenied);
    videochat_rbac_middleware_assert((int) ($userModerationDenied['status'] ?? 0) === 403, 'user should be forbidden from moderation scope');
    videochat_rbac_middleware_assert((string) (($userModerationDeniedBody['error']['details'] ?? [])['rule_id'] ?? '') === 'rest_moderation_scope', 'user/moderation deny rule_id mismatch');

    $userAllowed = $dispatch('GET', '/api/user/ping', 'sess_rbac_user');
    $userAllowedBody = videochat_rbac_middleware_decode_body($userAllowed);
    videochat_rbac_middleware_assert((int) ($userAllowed['status'] ?? 0) === 200, 'user should access user scope');
    videochat_rbac_middleware_assert((string) ($userAllowedBody['scope'] ?? '') === 'user', 'user scope payload mismatch');

    $runtimeOpen = $dispatch('GET', '/api/runtime', null);
    $runtimeOpenBody = videochat_rbac_middleware_decode_body($runtimeOpen);
    videochat_rbac_middleware_assert((int) ($runtimeOpen['status'] ?? 0) === 200, 'public runtime endpoint should remain accessible without auth');
    videochat_rbac_middleware_assert((string) ($runtimeOpenBody['service'] ?? '') === 'video-chat-backend-king-php', 'runtime payload mismatch');

    @unlink($databasePath);
    fwrite(STDOUT, "[rbac-middleware-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[rbac-middleware-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
