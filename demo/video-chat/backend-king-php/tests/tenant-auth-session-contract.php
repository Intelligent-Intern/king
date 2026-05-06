<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../http/module_auth_session.php';

function tenant_auth_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[tenant-auth-session-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-tenant-auth-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);
    $userId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $defaultTenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    tenant_auth_assert($userId > 0 && $defaultTenantId > 0, 'default user/tenant fixture missing');

    $pdo->exec("INSERT INTO tenants(public_id, slug, label, status) VALUES('00000000-0000-4000-8000-000000000003', 'tenant-b', 'Tenant B', 'active')");
    $tenantBId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO tenant_memberships(tenant_id, user_id, membership_role, status, permissions_json, default_membership) VALUES(:tenant_id, :user_id, \'admin\', \'active\', \'{}\', 0)'
    )->execute([':tenant_id' => $tenantBId, ':user_id' => $userId]);
    $pdo->prepare(
        'INSERT INTO sessions(id, user_id, active_tenant_id, issued_at, expires_at, revoked_at, client_ip, user_agent) VALUES(:id, :user_id, :tenant_id, :issued_at, :expires_at, NULL, NULL, NULL)'
    )->execute([
        ':id' => 'sess_tenant_switch',
        ':user_id' => $userId,
        ':tenant_id' => $defaultTenantId,
        ':issued_at' => gmdate('c', time() - 10),
        ':expires_at' => gmdate('c', time() + 3600),
    ]);

    $authContext = videochat_authenticate_request($pdo, [
        'method' => 'GET',
        'uri' => '/api/auth/session',
        'headers' => ['Authorization' => 'Bearer sess_tenant_switch'],
    ], 'rest');
    tenant_auth_assert((bool) ($authContext['ok'] ?? false), 'session should authenticate');
    tenant_auth_assert((int) (($authContext['tenant'] ?? [])['id'] ?? 0) === $defaultTenantId, 'session should start in default tenant');
    tenant_auth_assert((string) (($authContext['tenant'] ?? [])['role'] ?? '') === 'owner', 'platform admin should be default tenant owner');

    $jsonResponse = static fn (int $status, array $payload): array => [
        'status' => $status,
        'headers' => ['content-type' => 'application/json'],
        'body' => json_encode($payload, JSON_UNESCAPED_SLASHES),
    ];
    $errorResponse = static fn (int $status, string $code, string $message, array $details = []) => $jsonResponse($status, [
        'status' => 'error',
        'error' => ['code' => $code, 'message' => $message, 'details' => $details],
        'time' => gmdate('c'),
    ]);
    $decodeJsonBody = static function (array $request): array {
        $decoded = json_decode((string) ($request['body'] ?? ''), true);
        return [is_array($decoded) ? $decoded : null, is_array($decoded) ? null : 'invalid_json'];
    };
    $openDatabase = static fn (): PDO => videochat_open_sqlite_pdo($databasePath);
    $activeWebsockets = [];

    $switchResponse = videochat_handle_auth_session_routes(
        '/api/auth/tenant',
        'POST',
        [
            'method' => 'POST',
            'uri' => '/api/auth/tenant',
            'headers' => ['Authorization' => 'Bearer sess_tenant_switch'],
            'body' => json_encode(['tenant_uuid' => '00000000-0000-4000-8000-000000000003'], JSON_UNESCAPED_SLASHES),
        ],
        $authContext,
        $activeWebsockets,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase,
        static fn (): string => 'sess_unused'
    );
    tenant_auth_assert(is_array($switchResponse) && (int) ($switchResponse['status'] ?? 0) === 200, 'tenant switch should return 200');
    $switchPayload = json_decode((string) ($switchResponse['body'] ?? ''), true);
    tenant_auth_assert((int) (($switchPayload['tenant'] ?? [])['id'] ?? 0) === $tenantBId, 'tenant switch payload should expose Tenant B');

    $blockedSwitch = videochat_handle_auth_session_routes(
        '/api/auth/tenant',
        'POST',
        ['body' => json_encode(['tenant_uuid' => '00000000-0000-4000-8000-000000000099'], JSON_UNESCAPED_SLASHES)],
        $authContext,
        $activeWebsockets,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase,
        static fn (): string => 'sess_unused'
    );
    tenant_auth_assert(is_array($blockedSwitch) && (int) ($blockedSwitch['status'] ?? 0) === 403, 'unknown tenant switch must be forbidden');

    @unlink($databasePath);
    fwrite(STDOUT, "[tenant-auth-session-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[tenant-auth-session-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
