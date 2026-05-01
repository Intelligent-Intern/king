<?php

declare(strict_types=1);

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDOUT, "[user-directory-contract] SKIP: pdo_sqlite is not available\n");
    exit(0);
}

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../http/module_users.php';

function videochat_user_directory_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[user-directory-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_user_directory_decode(array $response): array
{
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-user-directory-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $roleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'user' LIMIT 1")->fetchColumn();
    videochat_user_directory_contract_assert($roleId > 0, 'seeded user role missing');

    $password = password_hash('directory-contract', PASSWORD_DEFAULT);
    videochat_user_directory_contract_assert(is_string($password) && $password !== '', 'password hash failed');

    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, :status, '24h', 'dark', :updated_at)
SQL
    );
    $insert->execute([
        ':email' => 'mobile-invite-alpha@example.test',
        ':display_name' => 'Mobile Invite Alpha',
        ':password_hash' => $password,
        ':role_id' => $roleId,
        ':status' => 'active',
        ':updated_at' => gmdate('c'),
    ]);
    $alphaUserId = (int) $pdo->lastInsertId();
    $insert->execute([
        ':email' => 'mobile-invite-disabled@example.test',
        ':display_name' => 'Mobile Invite Disabled',
        ':password_hash' => $password,
        ':role_id' => $roleId,
        ':status' => 'disabled',
        ':updated_at' => gmdate('c'),
    ]);

    $filters = videochat_user_directory_filters([
        'query' => 'mobile invite',
        'page' => '1',
        'page_size' => '10',
    ]);
    videochat_user_directory_contract_assert((bool) ($filters['ok'] ?? false), 'valid directory filters should pass');

    $listing = videochat_user_directory_list(
        $pdo,
        (string) $filters['query'],
        (int) $filters['page'],
        (int) $filters['page_size'],
        (string) $filters['order']
    );
    videochat_user_directory_contract_assert((int) ($listing['total'] ?? 0) === 1, 'directory must include only active matching users');
    videochat_user_directory_contract_assert(
        (string) (($listing['rows'][0] ?? [])['email'] ?? '') === 'mobile-invite-alpha@example.test',
        'directory active user mismatch'
    );
    videochat_user_directory_contract_assert(
        !array_key_exists('status', (array) ($listing['rows'][0] ?? [])),
        'standard user directory must not expose account status'
    );

    $excludedListing = videochat_user_directory_list(
        $pdo,
        (string) $filters['query'],
        (int) $filters['page'],
        (int) $filters['page_size'],
        (string) $filters['order'],
        $alphaUserId
    );
    videochat_user_directory_contract_assert(
        (int) ($excludedListing['total'] ?? -1) === 0,
        'directory must exclude the requesting user from totals and rows'
    );

    $jsonResponse = static function (int $status, array $payload): array {
        return [
            'status' => $status,
            'headers' => ['content-type' => 'application/json'],
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

    $request = ['uri' => '/api/user/directory?query=mobile%20invite&page=1&page_size=10'];
    $response = videochat_handle_user_routes(
        '/api/user/directory',
        'GET',
        $request,
        ['user' => ['id' => 2, 'role' => 'user']],
        [],
        sys_get_temp_dir(),
        1024,
        $jsonResponse,
        $errorResponse,
        static fn (): array => [[], null],
        static fn (): PDO => $pdo
    );
    videochat_user_directory_contract_assert(is_array($response), 'directory route should return a response');
    videochat_user_directory_contract_assert((int) ($response['status'] ?? 0) === 200, 'directory route status mismatch');
    $payload = videochat_user_directory_decode($response);
    videochat_user_directory_contract_assert((string) ($payload['status'] ?? '') === 'ok', 'directory route payload status mismatch');
    videochat_user_directory_contract_assert(count((array) ($payload['users'] ?? [])) === 1, 'directory route user count mismatch');

    $selfExcludedResponse = videochat_handle_user_routes(
        '/api/user/directory',
        'GET',
        $request,
        ['user' => ['id' => $alphaUserId, 'role' => 'user']],
        [],
        sys_get_temp_dir(),
        1024,
        $jsonResponse,
        $errorResponse,
        static fn (): array => [[], null],
        static fn (): PDO => $pdo
    );
    $selfExcludedPayload = videochat_user_directory_decode($selfExcludedResponse ?? []);
    videochat_user_directory_contract_assert(
        (int) (($selfExcludedPayload['pagination'] ?? [])['total'] ?? -1) === 0,
        'directory route must exclude the requesting user'
    );

    $methodResponse = videochat_handle_user_routes(
        '/api/user/directory',
        'POST',
        ['query' => []],
        ['user' => ['id' => 2, 'role' => 'user']],
        [],
        sys_get_temp_dir(),
        1024,
        $jsonResponse,
        $errorResponse,
        static fn (): array => [[], null],
        static fn (): PDO => $pdo
    );
    videochat_user_directory_contract_assert((int) (($methodResponse ?? [])['status'] ?? 0) === 405, 'directory route should reject POST');

    @unlink($databasePath);
    fwrite(STDOUT, "[user-directory-contract] PASS\n");
} catch (Throwable $error) {
    fwrite(STDERR, "[user-directory-contract] ERROR: {$error->getMessage()}\n");
    exit(1);
}
