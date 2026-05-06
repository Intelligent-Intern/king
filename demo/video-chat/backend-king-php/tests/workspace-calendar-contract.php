<?php

declare(strict_types=1);

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDOUT, "[workspace-calendar-contract] SKIP: pdo_sqlite is not available\n");
    exit(0);
}

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../http/module_workspace_calendars.php';

function videochat_workspace_calendar_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[workspace-calendar-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_workspace_calendar_contract_decode(array $response): array
{
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

function videochat_workspace_calendar_contract_user(PDO $pdo, int $roleId, string $email, string $name, int $tenantId): int
{
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status)
VALUES(:email, :display_name, :password_hash, :role_id, 'active')
SQL
    );
    $insert->execute([
        ':email' => $email,
        ':display_name' => $name,
        ':password_hash' => password_hash('workspace-calendar-contract', PASSWORD_DEFAULT),
        ':role_id' => $roleId,
    ]);
    $userId = (int) $pdo->lastInsertId();
    videochat_tenant_attach_user($pdo, $userId, $tenantId);
    return $userId;
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-workspace-calendar-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);
    $tenantId = videochat_tenant_default_id($pdo);
    $roleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'user' LIMIT 1")->fetchColumn();
    videochat_workspace_calendar_contract_assert($tenantId > 0, 'default tenant missing');
    videochat_workspace_calendar_contract_assert($roleId > 0, 'user role missing');

    $ownerId = videochat_workspace_calendar_contract_user($pdo, $roleId, 'calendar-owner@example.test', 'Calendar Owner', $tenantId);
    $memberId = videochat_workspace_calendar_contract_user($pdo, $roleId, 'calendar-member@example.test', 'Calendar Member', $tenantId);

    $ownerList = videochat_workspace_calendar_list($pdo, $ownerId, $tenantId, '', 1, 10);
    videochat_workspace_calendar_contract_assert((int) ($ownerList['total'] ?? 0) === 1, 'owner should get one auto-created personal calendar');
    videochat_workspace_calendar_contract_assert((bool) (($ownerList['rows'][0] ?? [])['is_personal'] ?? false), 'first owner calendar should be personal');

    $created = videochat_workspace_calendar_save($pdo, $ownerId, $tenantId, [
        'name' => 'Shared Product Calendar',
        'description' => 'Product planning',
        'member_user_ids' => [$memberId],
    ]);
    videochat_workspace_calendar_contract_assert((bool) ($created['ok'] ?? false), 'shared calendar should save');
    $calendar = (array) ($created['calendar'] ?? []);
    videochat_workspace_calendar_contract_assert((string) ($calendar['name'] ?? '') === 'Shared Product Calendar', 'created calendar name mismatch');
    videochat_workspace_calendar_contract_assert((int) ($calendar['member_count'] ?? 0) === 2, 'created calendar should include owner and member');

    $memberList = videochat_workspace_calendar_list($pdo, $memberId, $tenantId, 'product', 1, 10);
    videochat_workspace_calendar_contract_assert((int) ($memberList['total'] ?? 0) === 1, 'member should see shared calendar by access grant');
    videochat_workspace_calendar_contract_assert((string) (($memberList['rows'][0] ?? [])['access_role'] ?? '') === 'viewer', 'member access role mismatch');

    $memberUpdate = videochat_workspace_calendar_save($pdo, $memberId, $tenantId, [
        'name' => 'Hijacked',
        'member_user_ids' => [],
    ], (string) ($calendar['id'] ?? ''));
    videochat_workspace_calendar_contract_assert(!(bool) ($memberUpdate['ok'] ?? true), 'viewer must not update shared calendar');

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
            'error' => ['code' => $code, 'message' => $message, 'details' => $details],
            'time' => gmdate('c'),
        ]);
    };
    $decodeJsonBody = static function (array $request): array {
        $decoded = json_decode((string) ($request['body'] ?? ''), true);
        return [is_array($decoded) ? $decoded : null, null];
    };
    $auth = ['user' => ['id' => $ownerId, 'role' => 'user'], 'tenant' => ['id' => $tenantId]];
    $response = videochat_handle_workspace_calendar_routes(
        '/api/calendars',
        'GET',
        ['uri' => '/api/calendars?query=product&page=1&page_size=10'],
        $auth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        static fn (): PDO => $pdo
    );
    videochat_workspace_calendar_contract_assert(is_array($response), 'calendar route should handle GET');
    videochat_workspace_calendar_contract_assert((int) ($response['status'] ?? 0) === 200, 'calendar GET route status mismatch');
    $payload = videochat_workspace_calendar_contract_decode($response);
    videochat_workspace_calendar_contract_assert((int) (($payload['pagination'] ?? [])['total'] ?? 0) === 1, 'calendar route search total mismatch');

    @unlink($databasePath);
    fwrite(STDOUT, "[workspace-calendar-contract] PASS\n");
} catch (Throwable $error) {
    fwrite(STDERR, "[workspace-calendar-contract] ERROR: {$error->getMessage()}\n");
    exit(1);
}
