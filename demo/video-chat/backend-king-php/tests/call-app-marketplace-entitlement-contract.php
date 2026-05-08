<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../http/module_marketplace.php';
require_once __DIR__ . '/../http/module_call_apps.php';

function videochat_call_app_marketplace_entitlement_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-app-marketplace-entitlement-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_call_app_marketplace_entitlement_decode(array $response): array
{
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

function videochat_call_app_marketplace_entitlement_auth(PDO $pdo, int $userId, string $role): array
{
    $tenant = videochat_tenant_context_for_user($pdo, $userId);
    videochat_call_app_marketplace_entitlement_assert(is_array($tenant), 'tenant context missing');

    return [
        'ok' => true,
        'token' => 'sess_call_app_marketplace_' . $userId,
        'user' => ['id' => $userId, 'role' => $role, 'status' => 'active'],
        'session' => ['id' => 'sess_call_app_marketplace_' . $userId],
        'tenant' => videochat_tenant_auth_payload($tenant),
    ];
}

try {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        fwrite(STDOUT, "[call-app-marketplace-entitlement-contract] SKIP: PDO sqlite driver not available\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-call-app-marketplace-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);
    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);
    $tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    $adminUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $regularUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('user@intelligent-intern.com') LIMIT 1")->fetchColumn();
    videochat_call_app_marketplace_entitlement_assert($tenantId > 0 && $adminUserId > 0 && $regularUserId > 0, 'fixture ids missing');

    $callId = 'call_app_marketplace_install_contract_call';
    $roomId = 'room_call_app_marketplace_install_contract';
    $now = gmdate('c');
    $pdo->prepare(
        <<<'SQL'
INSERT OR IGNORE INTO rooms(id, tenant_id, name, visibility, status, created_at, updated_at)
VALUES(:id, :tenant_id, :name, 'private', 'active', :created_at, :updated_at)
SQL
    )->execute([
        ':id' => $roomId,
        ':tenant_id' => $tenantId,
        ':name' => 'Call App Marketplace Install Room',
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
        ':title' => 'Call App Marketplace Install Contract',
        ':owner_user_id' => $adminUserId,
        ':starts_at' => '2026-05-08T10:00:00Z',
        ':ends_at' => '2026-05-08T10:30:00Z',
        ':schedule_date' => '2026-05-08',
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
    $pdo->prepare(
        <<<'SQL'
INSERT INTO call_participants(call_id, user_id, email, display_name, source, invite_state)
VALUES(:call_id, :user_id, :email, :display_name, 'internal', 'accepted')
SQL
    )->execute([
        ':call_id' => $callId,
        ':user_id' => $adminUserId,
        ':email' => 'admin@intelligent-intern.com',
        ':display_name' => 'Admin',
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
    $decodeJsonBody = static function (array $request): array {
        $body = $request['body'] ?? '';
        if (!is_string($body) || trim($body) === '') {
            return [null, 'empty_body'];
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? [$decoded, null] : [null, 'invalid_json'];
    };
    $openDatabase = static fn (): PDO => videochat_open_sqlite_pdo($databasePath);
    $adminAuth = videochat_call_app_marketplace_entitlement_auth($pdo, $adminUserId, 'admin');
    $userAuth = videochat_call_app_marketplace_entitlement_auth($pdo, $regularUserId, 'user');

    $dispatch = static function (string $method, string $path, array $auth, ?array $payload = null) use (
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    ): array {
        $request = [
            'method' => $method,
            'uri' => $path,
            'path' => $path,
            'body' => is_array($payload) ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '',
        ];
        $response = videochat_handle_marketplace_routes(
            $path,
            $method,
            $request,
            $auth,
            $jsonResponse,
            $errorResponse,
            $decodeJsonBody,
            $openDatabase
        );
        videochat_call_app_marketplace_entitlement_assert(is_array($response), 'route should return a response for ' . $path);
        return $response;
    };

    $dispatchCallApps = static function (string $method, string $uri, array $auth, ?array $payload = null) use (
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    ): array {
        $routePath = (string) (parse_url($uri, PHP_URL_PATH) ?: $uri);
        $request = [
            'method' => $method,
            'uri' => $uri,
            'path' => $routePath,
            'body' => is_array($payload) ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '',
        ];
        $response = videochat_handle_call_app_routes(
            $routePath,
            $method,
            $request,
            $auth,
            $jsonResponse,
            $errorResponse,
            $openDatabase,
            $decodeJsonBody
        );
        videochat_call_app_marketplace_entitlement_assert(is_array($response), 'Call App route should return a response for ' . $uri);
        return $response;
    };

    $catalog = $dispatch('GET', '/api/marketplace/call-apps', $adminAuth);
    $catalogPayload = videochat_call_app_marketplace_entitlement_decode($catalog);
    videochat_call_app_marketplace_entitlement_assert((int) ($catalog['status'] ?? 0) === 200, 'catalog list should return 200');
    videochat_call_app_marketplace_entitlement_assert((string) (($catalogPayload['discovery'] ?? [])['source'] ?? '') === 'semantic_dns_mcp', 'catalog must use Semantic-DNS/MCP discovery');
    $apps = is_array($catalogPayload['apps'] ?? null) ? $catalogPayload['apps'] : [];
    $whiteboardRows = array_values(array_filter($apps, static fn ($row): bool => is_array($row) && (string) ($row['app_key'] ?? '') === 'whiteboard'));
    videochat_call_app_marketplace_entitlement_assert(count($whiteboardRows) === 1, 'catalog must include whiteboard exactly once');
    videochat_call_app_marketplace_entitlement_assert((string) ($whiteboardRows[0]['mcp_endpoint'] ?? '') !== '', 'catalog whiteboard must include MCP endpoint');
    videochat_call_app_marketplace_entitlement_assert((string) ($whiteboardRows[0]['metadata_hash'] ?? '') !== '', 'catalog whiteboard must include metadata hash');
    videochat_call_app_marketplace_entitlement_assert((string) (($whiteboardRows[0]['organization'] ?? [])['status'] ?? '') === 'not_installed', 'catalog whiteboard must start not installed for organization');
    videochat_call_app_marketplace_entitlement_assert(
        (bool) (((($whiteboardRows[0]['organization_actions'] ?? [])['add_to_organization'] ?? [])['available'] ?? false)) === true,
        'catalog whiteboard must expose add-to-organization action before install'
    );

    $catalogCount = (int) $pdo->query("SELECT COUNT(*) FROM call_app_catalog_entries WHERE app_key = 'whiteboard'")->fetchColumn();
    videochat_call_app_marketplace_entitlement_assert($catalogCount === 1, 'catalog refresh must persist one whiteboard catalog entry');

    $single = $dispatch('GET', '/api/marketplace/call-apps/whiteboard', $adminAuth);
    $singlePayload = videochat_call_app_marketplace_entitlement_decode($single);
    videochat_call_app_marketplace_entitlement_assert(
        (int) ($single['status'] ?? 0) === 200,
        'single catalog fetch should return 200, got ' . (string) ($single['status'] ?? 0) . ' ' . (string) ($single['body'] ?? '')
    );
    videochat_call_app_marketplace_entitlement_assert((string) (($singlePayload['app'] ?? [])['app_key'] ?? '') === 'whiteboard', 'single catalog app key mismatch');
    videochat_call_app_marketplace_entitlement_assert((string) ((($singlePayload['app'] ?? [])['organization'] ?? [])['status'] ?? '') === 'not_installed', 'single catalog entry must include organization state');

    $availableBeforeInstall = $dispatchCallApps('GET', '/api/calls/' . rawurlencode($callId) . '/call-apps/available?query=whiteboard&page=1&page_size=8', $adminAuth);
    $availableBeforeInstallPayload = videochat_call_app_marketplace_entitlement_decode($availableBeforeInstall);
    videochat_call_app_marketplace_entitlement_assert((int) ($availableBeforeInstall['status'] ?? 0) === 200, 'pre-install call availability should return 200');
    videochat_call_app_marketplace_entitlement_assert(((array) (($availableBeforeInstallPayload['result'] ?? [])['apps'] ?? [])) === [], 'pre-install Whiteboard must not appear in call availability');

    $forbiddenOrder = $dispatch('POST', '/api/marketplace/call-apps/whiteboard/orders', $userAuth);
    videochat_call_app_marketplace_entitlement_assert((int) ($forbiddenOrder['status'] ?? 0) === 403, 'regular user should not order Call Apps for organization');

    $crossTenantOrder = $dispatch('POST', '/api/marketplace/call-apps/whiteboard/orders', $adminAuth, [
        'tenant_id' => $tenantId + 100,
    ]);
    videochat_call_app_marketplace_entitlement_assert((int) ($crossTenantOrder['status'] ?? 0) === 422, 'client tenant override must fail');
    $crossTenantEntitlementCount = (int) $pdo->query('SELECT COUNT(*) FROM organization_call_app_entitlements')->fetchColumn();
    videochat_call_app_marketplace_entitlement_assert($crossTenantEntitlementCount === 0, 'forbidden tenant override must not create entitlement');

    $installBeforeOrder = $dispatch('POST', '/api/marketplace/call-apps/whiteboard/installations', $adminAuth);
    videochat_call_app_marketplace_entitlement_assert((int) ($installBeforeOrder['status'] ?? 0) === 409, 'installation without entitlement should fail');

    $order = $dispatch('POST', '/api/marketplace/call-apps/whiteboard/orders', $adminAuth);
    $orderPayload = videochat_call_app_marketplace_entitlement_decode($order);
    videochat_call_app_marketplace_entitlement_assert((int) ($order['status'] ?? 0) === 201, 'order should return 201');
    $orderResult = is_array($orderPayload['result'] ?? null) ? $orderPayload['result'] : [];
    $entitlement = is_array($orderResult['entitlement'] ?? null) ? $orderResult['entitlement'] : [];
    videochat_call_app_marketplace_entitlement_assert((string) ($entitlement['app_key'] ?? '') === 'whiteboard', 'entitlement app key mismatch');
    videochat_call_app_marketplace_entitlement_assert((int) ($entitlement['tenant_id'] ?? 0) === $tenantId, 'entitlement must be scoped to active tenant');
    videochat_call_app_marketplace_entitlement_assert((int) ($entitlement['ordered_by_user_id'] ?? 0) === $adminUserId, 'entitlement actor mismatch');
    videochat_call_app_marketplace_entitlement_assert((string) ($entitlement['status'] ?? '') === 'active', 'entitlement should be active');
    $entitlementRows = (int) $pdo->query("SELECT COUNT(*) FROM organization_call_app_entitlements WHERE tenant_id = {$tenantId} AND app_key = 'whiteboard'")->fetchColumn();
    videochat_call_app_marketplace_entitlement_assert($entitlementRows === 1, 'entitlement must persist once for tenant');

    $install = $dispatch('POST', '/api/marketplace/call-apps/whiteboard/installations', $adminAuth, [
        'default_app_policy' => 'allowed_by_default',
        'config' => ['toolbar' => 'default'],
    ]);
    $installPayload = videochat_call_app_marketplace_entitlement_decode($install);
    videochat_call_app_marketplace_entitlement_assert((int) ($install['status'] ?? 0) === 201, 'installation should return 201');
    $installation = is_array(($installPayload['result'] ?? [])['installation'] ?? null) ? ($installPayload['result'] ?? [])['installation'] : [];
    $installationId = (string) ($installation['id'] ?? '');
    videochat_call_app_marketplace_entitlement_assert($installationId !== '', 'installation id missing');
    videochat_call_app_marketplace_entitlement_assert((int) ($installation['tenant_id'] ?? 0) === $tenantId, 'installation must be scoped to active tenant');
    videochat_call_app_marketplace_entitlement_assert((string) ($installation['status'] ?? '') === 'enabled', 'installation should be enabled');
    videochat_call_app_marketplace_entitlement_assert((string) ($installation['default_app_policy'] ?? '') === 'allowed_by_default', 'installation policy mismatch');
    $installationRows = (int) $pdo->query("SELECT COUNT(*) FROM organization_call_app_installations WHERE tenant_id = {$tenantId} AND app_key = 'whiteboard'")->fetchColumn();
    videochat_call_app_marketplace_entitlement_assert($installationRows === 1, 'installation must persist once for tenant');

    $availableAfterInstall = $dispatchCallApps('GET', '/api/calls/' . rawurlencode($callId) . '/call-apps/available?query=whiteboard&page=1&page_size=8', $adminAuth);
    $availableAfterInstallPayload = videochat_call_app_marketplace_entitlement_decode($availableAfterInstall);
    videochat_call_app_marketplace_entitlement_assert((int) ($availableAfterInstall['status'] ?? 0) === 200, 'post-install call availability should return 200');
    $availableApps = is_array(($availableAfterInstallPayload['result'] ?? [])['apps'] ?? null) ? ($availableAfterInstallPayload['result'] ?? [])['apps'] : [];
    videochat_call_app_marketplace_entitlement_assert(count($availableApps) === 1 && (string) ($availableApps[0]['app_key'] ?? '') === 'whiteboard', 'post-install Whiteboard must appear in call availability');
    videochat_call_app_marketplace_entitlement_assert((string) (($availableApps[0]['installation'] ?? [])['status'] ?? '') === 'enabled', 'post-install call availability must use enabled organization installation');

    $sessionCreate = $dispatchCallApps('POST', '/api/calls/' . rawurlencode($callId) . '/call-app-sessions', $adminAuth, [
        'app_key' => 'whiteboard',
        'default_app_policy' => 'allowed_by_default',
    ]);
    $sessionCreatePayload = videochat_call_app_marketplace_entitlement_decode($sessionCreate);
    videochat_call_app_marketplace_entitlement_assert((int) ($sessionCreate['status'] ?? 0) === 201, 'pre-revoke Call App session start should return 201');
    $sessionId = (string) (((($sessionCreatePayload['result'] ?? [])['session'] ?? [])['id'] ?? ''));
    videochat_call_app_marketplace_entitlement_assert($sessionId !== '', 'pre-revoke Call App session id missing');

    $preRevokeLaunch = $dispatchCallApps('POST', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/launch-token', $adminAuth);
    $preRevokeLaunchPayload = videochat_call_app_marketplace_entitlement_decode($preRevokeLaunch);
    $preRevokeLaunchToken = (string) (($preRevokeLaunchPayload['result'] ?? [])['launch_token'] ?? '');
    videochat_call_app_marketplace_entitlement_assert((int) ($preRevokeLaunch['status'] ?? 0) === 201, 'pre-revoke launch token should be issued');
    videochat_call_app_marketplace_entitlement_assert($preRevokeLaunchToken !== '', 'pre-revoke launch token missing');

    $pdo->prepare(
        <<<'SQL'
UPDATE organization_call_app_entitlements
SET status = 'revoked', updated_at = :updated_at
WHERE tenant_id = :tenant_id
  AND app_key = 'whiteboard'
SQL
    )->execute([
        ':updated_at' => gmdate('c'),
        ':tenant_id' => $tenantId,
    ]);

    $availableAfterEntitlementRevoke = $dispatchCallApps('GET', '/api/calls/' . rawurlencode($callId) . '/call-apps/available?query=whiteboard&page=1&page_size=8', $adminAuth);
    $availableAfterEntitlementRevokePayload = videochat_call_app_marketplace_entitlement_decode($availableAfterEntitlementRevoke);
    videochat_call_app_marketplace_entitlement_assert((int) ($availableAfterEntitlementRevoke['status'] ?? 0) === 200, 'post-revoke call availability should return 200');
    videochat_call_app_marketplace_entitlement_assert(((array) (($availableAfterEntitlementRevokePayload['result'] ?? [])['apps'] ?? [])) === [], 'revoked organization entitlement must remove Whiteboard from call availability');

    $sessionAfterEntitlementRevoke = $dispatchCallApps('POST', '/api/calls/' . rawurlencode($callId) . '/call-app-sessions', $adminAuth, [
        'app_key' => 'whiteboard',
        'default_app_policy' => 'allowed_by_default',
    ]);
    $sessionAfterEntitlementRevokePayload = videochat_call_app_marketplace_entitlement_decode($sessionAfterEntitlementRevoke);
    videochat_call_app_marketplace_entitlement_assert((int) ($sessionAfterEntitlementRevoke['status'] ?? 0) === 409, 'revoked organization entitlement must block Call App session start');
    videochat_call_app_marketplace_entitlement_assert((string) (((($sessionAfterEntitlementRevokePayload['error'] ?? [])['details'] ?? [])['reason'] ?? '')) === 'app_not_available', 'post-revoke session start must fail because the app is unavailable');

    $launchAfterEntitlementRevoke = $dispatchCallApps('POST', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/launch-token', $adminAuth);
    $launchAfterEntitlementRevokePayload = videochat_call_app_marketplace_entitlement_decode($launchAfterEntitlementRevoke);
    videochat_call_app_marketplace_entitlement_assert((int) ($launchAfterEntitlementRevoke['status'] ?? 0) === 409, 'revoked organization entitlement must block launch for existing sessions');
    videochat_call_app_marketplace_entitlement_assert((string) (((($launchAfterEntitlementRevokePayload['error'] ?? [])['details'] ?? [])['reason'] ?? '')) === 'app_not_available', 'post-revoke launch must fail because the app is unavailable');

    $validateAfterEntitlementRevoke = $dispatchCallApps('POST', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/launch-token/validate', [], [
        'launch_token' => $preRevokeLaunchToken,
    ]);
    $validateAfterEntitlementRevokePayload = videochat_call_app_marketplace_entitlement_decode($validateAfterEntitlementRevoke);
    videochat_call_app_marketplace_entitlement_assert((int) ($validateAfterEntitlementRevoke['status'] ?? 0) === 401, 'revoked organization entitlement must invalidate existing launch tokens');
    videochat_call_app_marketplace_entitlement_assert((string) (((($validateAfterEntitlementRevokePayload['error'] ?? [])['details'] ?? [])['reason'] ?? '')) === 'app_not_available', 'post-revoke launch-token validation must fail because the app is unavailable');

    $reactivatedOrder = $dispatch('POST', '/api/marketplace/call-apps/whiteboard/orders', $adminAuth);
    $reactivatedOrderPayload = videochat_call_app_marketplace_entitlement_decode($reactivatedOrder);
    videochat_call_app_marketplace_entitlement_assert((int) ($reactivatedOrder['status'] ?? 0) === 201, 're-order after revoke should return 201');
    videochat_call_app_marketplace_entitlement_assert((string) (($reactivatedOrderPayload['result'] ?? [])['state'] ?? '') === 'reactivated', 're-order after revoke must reactivate the entitlement');

    $singleAfterInstall = $dispatch('GET', '/api/marketplace/call-apps/whiteboard', $adminAuth);
    $singleAfterInstallPayload = videochat_call_app_marketplace_entitlement_decode($singleAfterInstall);
    videochat_call_app_marketplace_entitlement_assert((string) ((($singleAfterInstallPayload['app'] ?? [])['organization'] ?? [])['status'] ?? '') === 'installed', 'single catalog entry must show installed organization state after install');
    videochat_call_app_marketplace_entitlement_assert(
        (bool) (((($singleAfterInstallPayload['app'] ?? [])['organization_actions'] ?? [])['verify_installation'] ?? [])['available'] ?? false) === true,
        'single catalog entry must expose verify installation action after install'
    );

    $disable = $dispatch('PATCH', '/api/marketplace/call-apps/whiteboard/installations/' . rawurlencode($installationId), $adminAuth, [
        'status' => 'disabled',
    ]);
    $disablePayload = videochat_call_app_marketplace_entitlement_decode($disable);
    videochat_call_app_marketplace_entitlement_assert((int) ($disable['status'] ?? 0) === 200, 'disable should return 200');
    videochat_call_app_marketplace_entitlement_assert((string) ((($disablePayload['result'] ?? [])['installation'] ?? [])['status'] ?? '') === 'disabled', 'installation should be disabled');

    $enable = $dispatch('PATCH', '/api/marketplace/call-apps/whiteboard/installations/' . rawurlencode($installationId), $adminAuth, [
        'status' => 'enabled',
    ]);
    $enablePayload = videochat_call_app_marketplace_entitlement_decode($enable);
    videochat_call_app_marketplace_entitlement_assert((int) ($enable['status'] ?? 0) === 200, 'enable should return 200');
    videochat_call_app_marketplace_entitlement_assert((string) ((($enablePayload['result'] ?? [])['installation'] ?? [])['status'] ?? '') === 'enabled', 'installation should be enabled');

    $duplicateOrder = $dispatch('POST', '/api/marketplace/call-apps/whiteboard/orders', $adminAuth);
    $duplicateOrderPayload = videochat_call_app_marketplace_entitlement_decode($duplicateOrder);
    videochat_call_app_marketplace_entitlement_assert((int) ($duplicateOrder['status'] ?? 0) === 201, 'duplicate order should be idempotent');
    videochat_call_app_marketplace_entitlement_assert((string) (($duplicateOrderPayload['result'] ?? [])['state'] ?? '') === 'existing', 'duplicate order state mismatch');
    $finalEntitlementRows = (int) $pdo->query("SELECT COUNT(*) FROM organization_call_app_entitlements WHERE tenant_id = {$tenantId} AND app_key = 'whiteboard'")->fetchColumn();
    videochat_call_app_marketplace_entitlement_assert($finalEntitlementRows === 1, 'duplicate order must not create extra entitlement rows');

    fwrite(STDOUT, "[call-app-marketplace-entitlement-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[call-app-marketplace-entitlement-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
