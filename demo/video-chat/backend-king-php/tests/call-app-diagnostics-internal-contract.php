<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../http/module_call_apps.php';

function videochat_call_app_diagnostics_internal_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[call-app-diagnostics-internal-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_call_app_diagnostics_internal_decode(array $response): array
{
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

function videochat_call_app_diagnostics_internal_auth(PDO $pdo, int $userId, string $role): array
{
    $tenant = videochat_tenant_context_for_user($pdo, $userId);
    videochat_call_app_diagnostics_internal_assert(is_array($tenant), 'tenant context missing');
    return [
        'ok' => true,
        'token' => 'sess_call_app_diagnostics_internal_' . $userId,
        'user' => ['id' => $userId, 'role' => $role, 'status' => 'active'],
        'session' => ['id' => 'sess_call_app_diagnostics_internal_' . $userId],
        'tenant' => videochat_tenant_auth_payload($tenant),
    ];
}

try {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        fwrite(STDOUT, "[call-app-diagnostics-internal-contract] SKIP: PDO sqlite driver not available\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-call-app-diagnostics-internal-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);
    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    $adminUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $regularUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('user@intelligent-intern.com') LIMIT 1")->fetchColumn();
    videochat_call_app_diagnostics_internal_assert($tenantId > 0 && $adminUserId > 0 && $regularUserId > 0, 'fixture ids missing');

    $callId = 'call_app_diagnostics_internal_contract_call';
    $roomId = 'room_call_app_diagnostics_internal_contract';
    $now = gmdate('c');
    $pdo->prepare(
        <<<'SQL'
INSERT OR IGNORE INTO rooms(id, tenant_id, name, visibility, status, created_at, updated_at)
VALUES(:id, :tenant_id, :name, 'private', 'active', :created_at, :updated_at)
SQL
    )->execute([':id' => $roomId, ':tenant_id' => $tenantId, ':name' => 'Call Diagnostics Internal Room', ':created_at' => $now, ':updated_at' => $now]);
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
        ':title' => 'Call Diagnostics Internal Contract',
        ':owner_user_id' => $regularUserId,
        ':starts_at' => '2026-05-09T10:00:00Z',
        ':ends_at' => '2026-05-09T10:30:00Z',
        ':schedule_date' => '2026-05-09',
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
    $participantInsert = $pdo->prepare(
        'INSERT INTO call_participants(call_id, user_id, email, display_name, source, invite_state) VALUES(:call_id, :user_id, :email, :display_name, \'internal\', \'accepted\')'
    );
    $participantInsert->execute([':call_id' => $callId, ':user_id' => $regularUserId, ':email' => 'user@intelligent-intern.com', ':display_name' => 'User']);
    $participantInsert->execute([':call_id' => $callId, ':user_id' => $adminUserId, ':email' => 'admin@intelligent-intern.com', ':display_name' => 'Admin']);

    $jsonResponse = static fn (int $status, array $payload): array => [
        'status' => $status,
        'headers' => ['content-type' => 'application/json; charset=utf-8'],
        'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];
    $errorResponse = static function (int $status, string $code, string $message, array $details = []) use ($jsonResponse): array {
        return $jsonResponse($status, ['status' => 'error', 'error' => ['code' => $code, 'message' => $message, 'details' => $details], 'time' => gmdate('c')]);
    };
    $decodeJsonBody = static function (array $request): array {
        $decoded = json_decode((string) ($request['body'] ?? ''), true);
        return is_array($decoded) ? [$decoded, null] : [null, 'invalid_json'];
    };
    $openDatabase = static fn (): PDO => videochat_open_sqlite_pdo($databasePath);
    $dispatch = static function (string $method, string $uri, array $auth, ?array $payload = null) use ($jsonResponse, $errorResponse, $decodeJsonBody, $openDatabase): array {
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: $uri);
        $response = videochat_handle_call_app_routes(
            $path,
            $method,
            ['method' => $method, 'uri' => $uri, 'path' => $path, 'body' => is_array($payload) ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ''],
            $auth,
            $jsonResponse,
            $errorResponse,
            $openDatabase,
            $decodeJsonBody
        );
        videochat_call_app_diagnostics_internal_assert(is_array($response), 'route should return a response for ' . $uri);
        return $response;
    };

    $adminAuth = videochat_call_app_diagnostics_internal_auth($pdo, $adminUserId, 'admin');
    $userAuth = videochat_call_app_diagnostics_internal_auth($pdo, $regularUserId, 'user');

    videochat_call_app_refresh_catalog($pdo);
    videochat_call_app_diagnostics_internal_assert(videochat_call_app_fetch_catalog_entry($pdo, 'call-diagnostics') === null, 'public catalog fetch must hide Call Diagnostics');
    $internalCatalogEntry = videochat_call_app_fetch_catalog_entry($pdo, 'call-diagnostics', '', true);
    videochat_call_app_diagnostics_internal_assert(is_array($internalCatalogEntry), 'internal catalog fetch should find Call Diagnostics');
    $publicCatalog = videochat_call_app_list_catalog($pdo, 'diagnostics', 'all');
    videochat_call_app_diagnostics_internal_assert(array_values(array_filter($publicCatalog, static fn (array $app): bool => (string) ($app['app_key'] ?? '') === 'call-diagnostics')) === [], 'public catalog list must hide Call Diagnostics');

    $entitlementId = videochat_call_app_marketplace_generate_public_id('cae');
    $installationId = videochat_call_app_marketplace_generate_public_id('cai');
    $pdo->prepare(
        <<<'SQL'
INSERT INTO organization_call_app_entitlements(public_id, tenant_id, app_key, app_version, status, plan_license, ordered_by_user_id, ordered_at, metadata_hash, updated_at)
VALUES(:public_id, :tenant_id, :app_key, :app_version, 'active', 'internal', :ordered_by_user_id, :ordered_at, :metadata_hash, :updated_at)
SQL
    )->execute([
        ':public_id' => $entitlementId,
        ':tenant_id' => $tenantId,
        ':app_key' => 'call-diagnostics',
        ':app_version' => (string) ($internalCatalogEntry['version'] ?? ''),
        ':ordered_by_user_id' => $adminUserId,
        ':ordered_at' => $now,
        ':metadata_hash' => (string) ($internalCatalogEntry['metadata_hash'] ?? ''),
        ':updated_at' => $now,
    ]);
    $entitlementRowId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        <<<'SQL'
INSERT INTO organization_call_app_installations(public_id, tenant_id, entitlement_id, app_key, app_version, status, config_json, default_app_policy, installed_by_user_id, installed_at, updated_at)
VALUES(:public_id, :tenant_id, :entitlement_id, 'call-diagnostics', :app_version, 'enabled', '{}', 'allowed_by_default', :installed_by_user_id, :installed_at, :updated_at)
SQL
    )->execute([
        ':public_id' => $installationId,
        ':tenant_id' => $tenantId,
        ':entitlement_id' => $entitlementRowId,
        ':app_version' => (string) ($internalCatalogEntry['version'] ?? ''),
        ':installed_by_user_id' => $adminUserId,
        ':installed_at' => $now,
        ':updated_at' => $now,
    ]);

    $userAvailability = $dispatch('GET', '/api/calls/' . rawurlencode($callId) . '/call-apps/available?query=diagnostics', $userAuth);
    $userAvailabilityPayload = videochat_call_app_diagnostics_internal_decode($userAvailability);
    videochat_call_app_diagnostics_internal_assert((int) ($userAvailability['status'] ?? 0) === 200, 'regular availability should return 200');
    videochat_call_app_diagnostics_internal_assert(((array) (($userAvailabilityPayload['result'] ?? [])['apps'] ?? [])) === [], 'regular availability must hide Call Diagnostics');

    $adminAvailability = $dispatch('GET', '/api/calls/' . rawurlencode($callId) . '/call-apps/available?query=diagnostics', $adminAuth);
    $adminAvailabilityPayload = videochat_call_app_diagnostics_internal_decode($adminAvailability);
    $adminApps = (array) (($adminAvailabilityPayload['result'] ?? [])['apps'] ?? []);
    videochat_call_app_diagnostics_internal_assert(count($adminApps) === 1 && (string) ($adminApps[0]['app_key'] ?? '') === 'call-diagnostics', 'admin availability should include Call Diagnostics');
    videochat_call_app_diagnostics_internal_assert((bool) ($adminApps[0]['internal'] ?? false), 'admin availability should flag Call Diagnostics as internal');

    $userAttach = $dispatch('POST', '/api/calls/' . rawurlencode($callId) . '/call-app-sessions', $userAuth, ['app_key' => 'call-diagnostics', 'default_app_policy' => 'allowed_by_default']);
    videochat_call_app_diagnostics_internal_assert((int) ($userAttach['status'] ?? 0) === 403, 'regular call owner must not attach Call Diagnostics');

    $adminAttach = $dispatch('POST', '/api/calls/' . rawurlencode($callId) . '/call-app-sessions', $adminAuth, ['app_key' => 'call-diagnostics', 'default_app_policy' => 'allowed_by_default']);
    $adminAttachPayload = videochat_call_app_diagnostics_internal_decode($adminAttach);
    videochat_call_app_diagnostics_internal_assert((int) ($adminAttach['status'] ?? 0) === 201, 'admin attach should create Call Diagnostics session');
    $sessionId = (string) ((($adminAttachPayload['result'] ?? [])['session'] ?? [])['id'] ?? '');
    videochat_call_app_diagnostics_internal_assert($sessionId !== '', 'Call Diagnostics session id missing');

    $userLaunch = $dispatch('POST', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/launch-token', $userAuth);
    videochat_call_app_diagnostics_internal_assert((int) ($userLaunch['status'] ?? 0) === 403, 'regular user must not mint Call Diagnostics launch token');
    $adminLaunch = $dispatch('POST', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/launch-token', $adminAuth);
    $adminLaunchPayload = videochat_call_app_diagnostics_internal_decode($adminLaunch);
    $adminLaunchToken = (string) (($adminLaunchPayload['result'] ?? [])['launch_token'] ?? '');
    videochat_call_app_diagnostics_internal_assert((int) ($adminLaunch['status'] ?? 0) === 201 && $adminLaunchToken !== '', 'admin launch token should be issued');
    $adminValidate = $dispatch('POST', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/launch-token/validate', [], ['launch_token' => $adminLaunchToken]);
    videochat_call_app_diagnostics_internal_assert((int) ($adminValidate['status'] ?? 0) === 200, 'admin launch token should validate');

    $sessionRowId = (int) $pdo->query('SELECT id FROM call_app_sessions WHERE public_id = ' . $pdo->quote($sessionId))->fetchColumn();
    $forgedToken = 'cat_' . str_repeat('a', 64);
    $pdo->prepare(
        <<<'SQL'
INSERT INTO call_app_launch_tokens(public_id, tenant_id, app_session_id, token_hash, issued_to_user_id, issued_at, expires_at, created_at, updated_at)
VALUES(:public_id, :tenant_id, :app_session_id, :token_hash, :issued_to_user_id, :issued_at, :expires_at, :created_at, :updated_at)
SQL
    )->execute([
        ':public_id' => videochat_call_app_marketplace_generate_public_id('ctl'),
        ':tenant_id' => $tenantId,
        ':app_session_id' => $sessionRowId,
        ':token_hash' => videochat_call_app_launch_token_hash($forgedToken),
        ':issued_to_user_id' => $regularUserId,
        ':issued_at' => $now,
        ':expires_at' => gmdate('c', time() + 300),
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
    $forgedValidate = $dispatch('POST', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/launch-token/validate', [], ['launch_token' => $forgedToken]);
    videochat_call_app_diagnostics_internal_assert((int) ($forgedValidate['status'] ?? 0) === 403, 'stale non-admin Call Diagnostics token must not validate');

    $pdo->prepare(
        <<<'SQL'
INSERT INTO client_diagnostics(user_id, session_id, call_id, room_id, category, level, event_type, code, message, payload_json, repeat_count, client_time)
VALUES(:user_id, :session_id, :call_id, :room_id, 'media', 'error', 'diagnostics_redaction_contract', 'sensitive_payload', :message, :payload_json, 1, :client_time)
SQL
    )->execute([
        ':user_id' => $regularUserId,
        ':session_id' => 'sess_sensitive',
        ':call_id' => $callId,
        ':room_id' => $roomId,
        ':message' => 'Bearer secret-token-value should not escape',
        ':payload_json' => json_encode([
            'token' => 'secret-token-value',
            'cookie' => 'session_cookie=do-not-leak',
            'sdp' => "v=0\r\no=- 1 2 IN IP4 127.0.0.1\r\n",
            'candidate' => 'candidate:1 1 udp 2122260223 10.0.0.1 54400 typ host',
            'nested' => ['api_key' => 'nested-secret'],
        ], JSON_UNESCAPED_SLASHES),
        ':client_time' => $now,
    ]);
    $telemetryPath = '/api/calls/' . rawurlencode($callId) . '/call-apps/call-diagnostics/telemetry-snapshot';
    $userTelemetry = $dispatch('GET', $telemetryPath, $userAuth);
    videochat_call_app_diagnostics_internal_assert((int) ($userTelemetry['status'] ?? 0) === 403, 'regular user must not fetch telemetry');
    $adminTelemetry = $dispatch('GET', $telemetryPath, $adminAuth);
    videochat_call_app_diagnostics_internal_assert((int) ($adminTelemetry['status'] ?? 0) === 200, 'admin telemetry should return 200');
    $telemetryBody = (string) ($adminTelemetry['body'] ?? '');
    $adminTelemetryPayload = videochat_call_app_diagnostics_internal_decode($adminTelemetry);
    $telemetry = (array) ((($adminTelemetryPayload['result'] ?? [])['telemetry'] ?? []));
    videochat_call_app_diagnostics_internal_assert((string) ($telemetry['schema_version'] ?? '') === 'king.call_diagnostics.telemetry.snapshot.v1', 'telemetry schema version mismatch');
    videochat_call_app_diagnostics_internal_assert(is_array($telemetry['instances'] ?? null) && count((array) $telemetry['instances']) >= 1, 'telemetry must include instance snapshots');
    videochat_call_app_diagnostics_internal_assert(is_array(($telemetry['resources'] ?? [])['cpu'] ?? null), 'telemetry must include CPU/load snapshot');
    videochat_call_app_diagnostics_internal_assert(is_array(($telemetry['resources'] ?? [])['memory'] ?? null), 'telemetry must include memory snapshot');
    videochat_call_app_diagnostics_internal_assert(array_key_exists('websockets', $telemetry), 'telemetry must include websocket counters');
    videochat_call_app_diagnostics_internal_assert((int) (($telemetry['calls'] ?? [])['active_count'] ?? 0) >= 1, 'telemetry must include active call counters');
    foreach (['secret-token-value', 'session_cookie=do-not-leak', 'v=0', 'candidate:1', 'nested-secret'] as $needle) {
        videochat_call_app_diagnostics_internal_assert(!str_contains($telemetryBody, $needle), 'telemetry leaked sensitive value: ' . $needle);
    }
    videochat_call_app_diagnostics_internal_assert(str_contains($telemetryBody, '[redacted:'), 'telemetry should include redaction markers for sensitive diagnostics');

    fwrite(STDOUT, "[call-app-diagnostics-internal-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[call-app-diagnostics-internal-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
} finally {
    if (isset($databasePath) && is_string($databasePath) && $databasePath !== '') {
        @unlink($databasePath);
    }
}
