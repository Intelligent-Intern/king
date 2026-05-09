<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../http/module_call_apps.php';

function videochat_call_app_availability_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-app-availability-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_call_app_availability_decode(array $response): array
{
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

function videochat_call_app_availability_auth(PDO $pdo, int $userId, string $role): array
{
    $tenant = videochat_tenant_context_for_user($pdo, $userId);
    videochat_call_app_availability_assert(is_array($tenant), 'tenant context missing');

    return [
        'ok' => true,
        'token' => 'sess_call_app_availability_' . $userId,
        'user' => ['id' => $userId, 'role' => $role, 'status' => 'active'],
        'session' => ['id' => 'sess_call_app_availability_' . $userId],
        'tenant' => videochat_tenant_auth_payload($tenant),
    ];
}

try {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        fwrite(STDOUT, "[call-app-availability-contract] SKIP: PDO sqlite driver not available\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-call-app-availability-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);
    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    $adminUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    videochat_call_app_availability_assert($tenantId > 0 && $adminUserId > 0, 'fixture ids missing');
    $callId = 'call_app_availability_contract_call';
    $roomId = 'room_call_app_availability_contract';
    $now = gmdate('c');
    $pdo->prepare(
        <<<'SQL'
INSERT OR IGNORE INTO rooms(id, tenant_id, name, visibility, status, created_at, updated_at)
VALUES(:id, :tenant_id, :name, 'private', 'active', :created_at, :updated_at)
SQL
    )->execute([
        ':id' => $roomId,
        ':tenant_id' => $tenantId,
        ':name' => 'Call App Availability Room',
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
    $pdo->prepare(
        <<<'SQL'
INSERT INTO calls(
    id, tenant_id, room_id, title, access_mode, owner_user_id, status,
    starts_at, ends_at, schedule_timezone, schedule_date,
    schedule_duration_minutes, schedule_all_day, created_at, updated_at
) VALUES(
    :id, :tenant_id, :room_id, :title, 'invite_only', :owner_user_id, 'active',
    :starts_at, :ends_at, 'UTC', :schedule_date,
    30, 0, :created_at, :updated_at
)
SQL
    )->execute([
        ':id' => $callId,
        ':tenant_id' => $tenantId,
        ':room_id' => $roomId,
        ':title' => 'Call App Availability Contract',
        ':owner_user_id' => $adminUserId,
        ':starts_at' => '2026-05-07T09:00:00Z',
        ':ends_at' => '2026-05-07T09:30:00Z',
        ':schedule_date' => '2026-05-07',
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    $jsonResponse = static function (int $status, array $payload): array {
        return [
            'status' => $status,
            'headers' => ['content-type' => 'application/json; charset=utf-8'],
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
    $openDatabase = static fn (): PDO => videochat_open_sqlite_pdo($databasePath);
    $adminAuth = videochat_call_app_availability_auth($pdo, $adminUserId, 'admin');

    $dispatch = static function (string $method, string $uri, array $auth, ?callable $databaseOpener = null) use (
        $jsonResponse,
        $errorResponse,
        $openDatabase
    ): array {
        $routePath = (string) (parse_url($uri, PHP_URL_PATH) ?: $uri);
        $request = [
            'method' => $method,
            'uri' => $uri,
            'path' => $routePath,
            'body' => '',
        ];
        $response = videochat_handle_call_app_routes(
            $routePath,
            $method,
            $request,
            $auth,
            $jsonResponse,
            $errorResponse,
            $databaseOpener ?? $openDatabase
        );
        videochat_call_app_availability_assert(is_array($response), 'route should return a response for ' . $uri);
        return $response;
    };

    $empty = $dispatch('GET', '/api/calls/' . rawurlencode($callId) . '/call-apps/available', $adminAuth);
    $emptyPayload = videochat_call_app_availability_decode($empty);
    videochat_call_app_availability_assert((int) ($empty['status'] ?? 0) === 200, 'empty availability should return 200');
    videochat_call_app_availability_assert(((array) (($emptyPayload['result'] ?? [])['apps'] ?? [])) === [], 'uninstalled apps must not appear');

    videochat_call_app_refresh_catalog($pdo);
    $order = videochat_call_app_create_organization_order($pdo, $tenantId, $adminUserId, 'whiteboard');
    videochat_call_app_availability_assert((bool) ($order['ok'] ?? false), 'organization order should succeed');
    $install = videochat_call_app_create_organization_installation($pdo, $tenantId, $adminUserId, 'whiteboard', [
        'default_app_policy' => 'allowed_by_default',
    ]);
    videochat_call_app_availability_assert((bool) ($install['ok'] ?? false), 'organization installation should succeed');

    $available = $dispatch('GET', '/api/calls/' . rawurlencode($callId) . '/call-apps/available?query=white&page=1&page_size=6', $adminAuth);
    $availablePayload = videochat_call_app_availability_decode($available);
    videochat_call_app_availability_assert((int) ($available['status'] ?? 0) === 200, 'installed availability should return 200');
    $apps = is_array(($availablePayload['result'] ?? [])['apps'] ?? null) ? ($availablePayload['result'] ?? [])['apps'] : [];
    videochat_call_app_availability_assert(count($apps) === 1, 'exactly one healthy installed app should appear');
    $app = $apps[0];
    videochat_call_app_availability_assert((string) ($app['app_key'] ?? '') === 'whiteboard', 'available app key mismatch');
    videochat_call_app_availability_assert((string) ($app['health_status'] ?? '') === 'healthy', 'available app must be healthy');
    videochat_call_app_availability_assert((bool) (($app['availability'] ?? [])['installed'] ?? false), 'available app must be marked installed');
    videochat_call_app_availability_assert((bool) (($app['availability'] ?? [])['healthy'] ?? false), 'available app must be marked healthy');
    videochat_call_app_availability_assert((string) (($app['installation'] ?? [])['status'] ?? '') === 'enabled', 'available app installation must be enabled');
    videochat_call_app_availability_assert((string) (($app['installation'] ?? [])['default_app_policy'] ?? '') === 'allowed_by_default', 'available app policy mismatch');

    $sentinelVerifiedAt = '2099-01-01T00:00:00+00:00';
    $pdo->prepare("UPDATE call_app_catalog_entries SET verified_at = :verified_at, updated_at = :verified_at")
        ->execute([':verified_at' => $sentinelVerifiedAt]);
    $warmCatalog = $dispatch('GET', '/api/calls/' . rawurlencode($callId) . '/call-apps/available?query=white&page=1&page_size=6', $adminAuth);
    $warmCatalogPayload = videochat_call_app_availability_decode($warmCatalog);
    $warmDiscovery = is_array(($warmCatalogPayload['result'] ?? [])['discovery'] ?? null) ? ($warmCatalogPayload['result'] ?? [])['discovery'] : [];
    $warmApps = is_array(($warmCatalogPayload['result'] ?? [])['apps'] ?? null) ? ($warmCatalogPayload['result'] ?? [])['apps'] : [];
    $verifiedAfterWarmAvailability = (string) $pdo->query("SELECT verified_at FROM call_app_catalog_entries WHERE app_key = 'whiteboard' LIMIT 1")->fetchColumn();
    videochat_call_app_availability_assert((int) ($warmCatalog['status'] ?? 0) === 200, 'warm availability should return 200');
    videochat_call_app_availability_assert(count($warmApps) === 1, 'warm catalog availability should keep installed apps visible');
    videochat_call_app_availability_assert((bool) ($warmDiscovery['refresh_skipped'] ?? false) === true, 'warm availability must not rewrite the catalog on every sidebar load');
    videochat_call_app_availability_assert((string) ($warmDiscovery['cache_status'] ?? '') === 'warm', 'warm availability must report warm catalog cache status');
    videochat_call_app_availability_assert($verifiedAfterWarmAvailability === $sentinelVerifiedAt, 'warm availability must preserve catalog verified_at instead of forcing write-refresh');

    $staleVerifiedAt = '2020-01-01T00:00:00+00:00';
    $pdo->prepare("UPDATE call_app_catalog_entries SET verified_at = :verified_at, updated_at = :verified_at")
        ->execute([':verified_at' => $staleVerifiedAt]);
    $lockedOpenDatabase = static function () use ($databasePath): PDO {
        $lockedPdo = videochat_open_sqlite_pdo($databasePath);
        $lockedPdo->exec('PRAGMA busy_timeout = 50');
        return $lockedPdo;
    };
    $writeLock = videochat_open_sqlite_pdo($databasePath);
    $writeLock->exec('BEGIN IMMEDIATE');
    try {
        $staleFallback = $dispatch(
            'GET',
            '/api/calls/' . rawurlencode($callId) . '/call-apps/available?query=white&page=1&page_size=6',
            $adminAuth,
            $lockedOpenDatabase
        );
    } finally {
        $writeLock->exec('ROLLBACK');
    }
    $staleFallbackPayload = videochat_call_app_availability_decode($staleFallback);
    $staleDiscovery = is_array(($staleFallbackPayload['result'] ?? [])['discovery'] ?? null) ? ($staleFallbackPayload['result'] ?? [])['discovery'] : [];
    $staleApps = is_array(($staleFallbackPayload['result'] ?? [])['apps'] ?? null) ? ($staleFallbackPayload['result'] ?? [])['apps'] : [];
    $staleInvalid = is_array($staleDiscovery['invalid'] ?? null) ? $staleDiscovery['invalid'] : [];
    $staleRefreshError = is_array(($staleInvalid[0] ?? [])['errors'] ?? null) ? ($staleInvalid[0] ?? [])['errors'] : [];
    $verifiedAfterStaleFallback = (string) $pdo->query("SELECT verified_at FROM call_app_catalog_entries WHERE app_key = 'whiteboard' LIMIT 1")->fetchColumn();
    videochat_call_app_availability_assert((int) ($staleFallback['status'] ?? 0) === 200, 'stale fallback availability should return 200 under transient refresh lock');
    videochat_call_app_availability_assert(count($staleApps) === 1 && (string) ($staleApps[0]['app_key'] ?? '') === 'whiteboard', 'stale fallback availability should keep installed app visible');
    videochat_call_app_availability_assert((bool) ($staleDiscovery['ok'] ?? true) === false, 'stale fallback discovery should report failed refresh');
    videochat_call_app_availability_assert((bool) ($staleDiscovery['refresh_skipped'] ?? false) === true, 'stale fallback should skip the failed refresh and use persisted catalog');
    videochat_call_app_availability_assert((string) ($staleDiscovery['cache_status'] ?? '') === 'stale_fallback', 'stale fallback cache status mismatch');
    videochat_call_app_availability_assert((string) ($staleDiscovery['fallback_reason'] ?? '') === 'transient_refresh_failed', 'stale fallback reason mismatch');
    videochat_call_app_availability_assert((string) ($staleRefreshError['catalog_refresh'] ?? '') === 'transient_unavailable', 'stale fallback refresh error mismatch');
    videochat_call_app_availability_assert($verifiedAfterStaleFallback === $staleVerifiedAt, 'stale fallback must not rewrite catalog verified_at after failed refresh');

    $pdo->exec("UPDATE call_app_catalog_entries SET health_status = 'unhealthy' WHERE app_key = 'whiteboard'");
    $availabilityFilters = videochat_call_app_availability_filters([]);
    $unhealthy = videochat_call_app_list_available_for_tenant($pdo, $tenantId, $availabilityFilters);
    videochat_call_app_availability_assert(((array) ($unhealthy['apps'] ?? [])) === [], 'unhealthy apps must be hidden');

    $pdo->exec("UPDATE call_app_catalog_entries SET health_status = 'healthy' WHERE app_key = 'whiteboard'");
    $pdo->exec("UPDATE organization_call_app_installations SET status = 'disabled' WHERE app_key = 'whiteboard'");
    $disabled = $dispatch('GET', '/api/calls/' . rawurlencode($callId) . '/call-apps/available', $adminAuth);
    $disabledPayload = videochat_call_app_availability_decode($disabled);
    videochat_call_app_availability_assert(((array) (($disabledPayload['result'] ?? [])['apps'] ?? [])) === [], 'disabled installations must be hidden');

    $missing = $dispatch('GET', '/api/calls/missing_call_app_availability/call-apps/available', $adminAuth);
    videochat_call_app_availability_assert((int) ($missing['status'] ?? 0) === 404, 'missing call should return 404');

    fwrite(STDOUT, "[call-app-availability-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[call-app-availability-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
