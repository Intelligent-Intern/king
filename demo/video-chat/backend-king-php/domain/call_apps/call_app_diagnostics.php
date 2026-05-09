<?php

declare(strict_types=1);

require_once __DIR__ . '/../calls/call_management.php';
require_once __DIR__ . '/../../support/tenant_context.php';

function videochat_call_diagnostics_app_key(): string
{
    return 'call-diagnostics';
}

function videochat_call_app_diagnostics_decode_json(mixed $value, mixed $fallback): mixed
{
    if (is_array($value)) {
        return $value;
    }
    if (!is_string($value) || trim($value) === '') {
        return $fallback;
    }
    $decoded = json_decode($value, true);
    return $decoded === null && strtolower(trim($value)) !== 'null' ? $fallback : $decoded;
}

function videochat_call_app_is_internal_admin_app_key(string $appKey): bool
{
    return strtolower(trim($appKey)) === videochat_call_diagnostics_app_key();
}

function videochat_call_app_catalog_entry_is_internal_admin(array $entry): bool
{
    $listing = videochat_call_app_diagnostics_decode_json($entry['listing'] ?? ($entry['listing_json'] ?? []), []);
    $listing = is_array($listing) ? $listing : [];
    $appKey = (string) ($entry['app_key'] ?? $entry['appKey'] ?? '');
    $visibility = strtolower(trim((string) ($entry['visibility'] ?? ($listing['visibility'] ?? ''))));
    $access = strtolower(trim((string) ($listing['access'] ?? ($listing['audience'] ?? ''))));

    return videochat_call_app_is_internal_admin_app_key($appKey)
        || in_array($visibility, ['internal', 'admin', 'admin_only', 'system'], true)
        || in_array($access, ['internal', 'admin', 'admin_only', 'system'], true)
        || (bool) ($listing['internal'] ?? false)
        || (bool) ($listing['admin_only'] ?? false);
}

function videochat_call_app_actor_can_use_internal_admin_apps(array $apiAuthContext): bool
{
    $user = is_array($apiAuthContext['user'] ?? null) ? $apiAuthContext['user'] : [];
    $tenant = is_array($apiAuthContext['tenant'] ?? null) ? $apiAuthContext['tenant'] : [];
    $permissions = is_array($tenant['permissions'] ?? null) ? $tenant['permissions'] : [];
    $role = strtolower(trim((string) ($user['role'] ?? '')));
    $status = strtolower(trim((string) ($user['status'] ?? 'active')));

    return $status === 'active' && ($role === 'admin' || (bool) ($permissions['platform_admin'] ?? false));
}

function videochat_call_app_user_can_use_internal_admin_apps(PDO $pdo, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }
    if (function_exists('videochat_user_has_system_admin_call_rights')) {
        return videochat_user_has_system_admin_call_rights($pdo, $userId, 'admin');
    }

    $query = $pdo->prepare(
        <<<'SQL'
SELECT roles.slug, users.status
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE users.id = :user_id
LIMIT 1
SQL
    );
    $query->execute([':user_id' => $userId]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    return is_array($row)
        && strtolower(trim((string) ($row['slug'] ?? ''))) === 'admin'
        && strtolower(trim((string) ($row['status'] ?? ''))) === 'active';
}

function videochat_call_diagnostics_redaction_reason(string $key, mixed $value): string
{
    $normalizedKey = strtolower(str_replace(['-', '.'], '_', trim($key)));
    if (preg_match('/(^|_)(session|token|cookie|connection|participant|call_app_session)_?(count|total)s?$/', $normalizedKey) === 1) {
        return '';
    }
    if (preg_match('/(^|_)(token|authorization|cookie|set_cookie|password|secret|credential|api_key|apikey|private_key|jwt)(_|$)/', $normalizedKey) === 1) {
        return 'secret';
    }
    if (preg_match('/(^|_)(sdp|session_description|local_description|remote_description)(_|$)/', $normalizedKey) === 1) {
        return 'sdp';
    }
    if (preg_match('/(^|_)(candidate|ice_candidate|raw_ice)(_|$)/', $normalizedKey) === 1) {
        return 'ice';
    }
    if (is_string($value)) {
        $sample = strtolower($value);
        if (str_contains($sample, 'a=candidate:') || preg_match('/candidate:\d+ \d+ (udp|tcp) /i', $value) === 1) {
            return 'ice';
        }
        if (preg_match('/(^|\R)v=0(\R|$)/', $value) === 1 || str_contains($sample, 'a=fingerprint:')) {
            return 'sdp';
        }
        if (preg_match('/bearer\s+[a-z0-9._~+\/=-]+|token=|session=|cookie:/i', $value) === 1) {
            return 'secret';
        }
    }
    return '';
}

function videochat_call_diagnostics_redact_value(mixed $value, string $key = '', int $depth = 0): mixed
{
    $reason = videochat_call_diagnostics_redaction_reason($key, $value);
    if ($reason !== '') {
        return '[redacted:' . $reason . ']';
    }
    if ($depth >= 5) {
        return '[depth_limited]';
    }
    if (is_null($value) || is_bool($value) || is_int($value) || is_float($value)) {
        return $value;
    }
    if (is_string($value)) {
        return strlen($value) > 1200 ? substr($value, 0, 1200) : $value;
    }
    if (is_array($value)) {
        $redacted = [];
        $count = 0;
        foreach ($value as $childKey => $childValue) {
            if ($count >= 40) {
                $redacted['__truncated__'] = true;
                break;
            }
            $redacted[(string) $childKey] = videochat_call_diagnostics_redact_value($childValue, (string) $childKey, $depth + 1);
            $count++;
        }
        return $redacted;
    }
    if ($value instanceof Throwable) {
        return ['type' => 'throwable', 'class' => get_class($value), 'message' => 'redacted'];
    }
    if (is_object($value)) {
        return videochat_call_diagnostics_redact_value(get_object_vars($value), $key, $depth + 1);
    }
    return '[redacted:unsupported]';
}

function videochat_call_diagnostics_table_has_column(PDO $pdo, string $table, string $column): bool
{
    try {
        $statement = $pdo->query('PRAGMA table_info(' . preg_replace('/[^A-Za-z0-9_]/', '', $table) . ')');
        foreach ($statement ? $statement->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
            if (is_array($row) && strtolower((string) ($row['name'] ?? '')) === strtolower($column)) {
                return true;
            }
        }
    } catch (Throwable) {
        return false;
    }
    return false;
}

function videochat_call_diagnostics_scalar_query(PDO $pdo, string $sql, array $params = [], mixed $fallback = 0): mixed
{
    try {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $value = $statement->fetchColumn();
        return $value === false ? $fallback : $value;
    } catch (Throwable) {
        return $fallback;
    }
}

function videochat_call_diagnostics_memory_snapshot(): array
{
    $snapshot = [
        'php_usage_bytes' => memory_get_usage(true),
        'php_peak_bytes' => memory_get_peak_usage(true),
        'system_total_bytes' => null,
        'system_available_bytes' => null,
    ];
    $meminfo = is_readable('/proc/meminfo') ? file('/proc/meminfo', FILE_IGNORE_NEW_LINES) : false;
    foreach (is_array($meminfo) ? $meminfo : [] as $line) {
        if (preg_match('/^(MemTotal|MemAvailable):\s+(\d+)\s+kB$/', (string) $line, $match) !== 1) {
            continue;
        }
        $key = $match[1] === 'MemTotal' ? 'system_total_bytes' : 'system_available_bytes';
        $snapshot[$key] = ((int) $match[2]) * 1024;
    }
    return $snapshot;
}

function videochat_call_diagnostics_runtime_status(): array
{
    if (!function_exists('king_system_get_status')) {
        return ['available' => false, 'source' => 'king_system_get_status_unavailable'];
    }
    try {
        $status = king_system_get_status();
        return ['available' => true, 'status' => videochat_call_diagnostics_redact_value($status)];
    } catch (Throwable) {
        return ['available' => true, 'status' => 'unavailable'];
    }
}

function videochat_call_diagnostics_recent_errors(PDO $pdo, string $callId, int $limit = 12): array
{
    $since = gmdate('c', time() - 900);
    $callFilter = trim($callId) !== '' ? 'call_id = :call_id AND ' : '';
    $params = trim($callId) !== '' ? [':call_id' => trim($callId), ':since' => $since] : [':since' => $since];
    $byLevel = [];
    $byEvent = [];
    try {
        $levels = $pdo->prepare("SELECT level, COUNT(*) AS total FROM client_diagnostics WHERE {$callFilter}created_at >= :since GROUP BY level");
        $levels->execute($params);
        foreach ($levels->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $byLevel[(string) ($row['level'] ?? '')] = (int) ($row['total'] ?? 0);
        }
        $events = $pdo->prepare("SELECT event_type, COUNT(*) AS total FROM client_diagnostics WHERE {$callFilter}created_at >= :since GROUP BY event_type ORDER BY total DESC, event_type ASC LIMIT 12");
        $events->execute($params);
        foreach ($events->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $byEvent[(string) ($row['event_type'] ?? '')] = (int) ($row['total'] ?? 0);
        }
        $recent = $pdo->prepare("SELECT level, category, event_type, code, message, payload_json, repeat_count, client_time, created_at FROM client_diagnostics WHERE {$callFilter}1=1 ORDER BY created_at DESC, id DESC LIMIT :limit");
        if (trim($callId) !== '') {
            $recent->bindValue(':call_id', trim($callId), PDO::PARAM_STR);
        }
        $recent->bindValue(':limit', max(1, min(25, $limit)), PDO::PARAM_INT);
        $recent->execute();
        $entries = [];
        foreach ($recent->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $payload = videochat_call_app_diagnostics_decode_json($row['payload_json'] ?? '{}', []);
            $entries[] = videochat_call_diagnostics_redact_value([
                'level' => (string) ($row['level'] ?? ''),
                'category' => (string) ($row['category'] ?? ''),
                'event_type' => (string) ($row['event_type'] ?? ''),
                'code' => (string) ($row['code'] ?? ''),
                'message' => (string) ($row['message'] ?? ''),
                'payload' => is_array($payload) ? $payload : [],
                'repeat_count' => (int) ($row['repeat_count'] ?? 1),
                'client_time' => (string) ($row['client_time'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
            ]);
        }
    } catch (Throwable) {
        return ['window_seconds' => 900, 'total' => 0, 'by_level' => [], 'by_event_type' => [], 'entries' => []];
    }
    return [
        'window_seconds' => 900,
        'total' => array_sum($byLevel),
        'by_level' => $byLevel,
        'by_event_type' => $byEvent,
        'entries' => $entries ?? [],
    ];
}

function videochat_call_diagnostics_telemetry_snapshot(PDO $pdo, int $tenantId, string $callId, array $call = []): array
{
    $callTenantId = is_numeric($call['tenant_id'] ?? null) ? (int) $call['tenant_id'] : $tenantId;
    $tenantFilter = videochat_call_diagnostics_table_has_column($pdo, 'calls', 'tenant_id') && $callTenantId > 0 ? ' AND tenant_id = :tenant_id' : '';
    $tenantParams = $tenantFilter !== '' ? [':tenant_id' => $callTenantId] : [];
    $activeCallCount = (int) videochat_call_diagnostics_scalar_query($pdo, "SELECT COUNT(*) FROM calls WHERE status = 'active'{$tenantFilter}", $tenantParams, 0);
    $participantCount = (int) videochat_call_diagnostics_scalar_query($pdo, 'SELECT COUNT(*) FROM call_participants WHERE call_id = :call_id', [':call_id' => $callId], 0);
    $catalogCount = (int) videochat_call_diagnostics_scalar_query($pdo, 'SELECT COUNT(*) FROM call_app_catalog_entries', [], 0);
    $sessionCount = (int) videochat_call_diagnostics_scalar_query($pdo, 'SELECT COUNT(*) FROM call_app_sessions WHERE tenant_id = :tenant_id AND status = \'active\'', [':tenant_id' => $callTenantId], 0);
    $activeWebsocketCount = videochat_call_diagnostics_scalar_query($pdo, 'SELECT COUNT(*) FROM realtime_presence_connections WHERE status = \'active\'', [], null);
    $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
    $loadValues = is_array($load) ? array_values($load) : [];
    $cpuSnapshot = [
        'load_1m' => isset($loadValues[0]) ? (float) $loadValues[0] : null,
        'load_5m' => isset($loadValues[1]) ? (float) $loadValues[1] : null,
        'load_15m' => isset($loadValues[2]) ? (float) $loadValues[2] : null,
    ];
    $memorySnapshot = videochat_call_diagnostics_memory_snapshot();
    $containerSnapshot = [
        'hostname' => gethostname() ?: '',
        'service' => getenv('VIDEOCHAT_SERVICE_NAME') ?: (getenv('K_SERVICE') ?: ''),
        'revision' => getenv('VIDEOCHAT_RELEASE') ?: (getenv('K_REVISION') ?: ''),
        'status' => 'running',
    ];
    $instanceSnapshot = [
        'id' => getenv('VIDEOCHAT_INSTANCE_ID') ?: (gethostname() ?: 'local'),
        'role' => getenv('VIDEOCHAT_SERVER_ROLE') ?: (getenv('VIDEOCHAT_SERVER_MODE') ?: 'http'),
        'health' => 'ok',
        'pid' => getmypid(),
        'php_version' => PHP_VERSION,
        'sapi' => PHP_SAPI,
        'cpu' => $cpuSnapshot,
        'memory' => $memorySnapshot,
        'container' => $containerSnapshot,
        'websocket_connections' => is_numeric($activeWebsocketCount) ? (int) $activeWebsocketCount : null,
        'active_calls' => $activeCallCount,
    ];

    return videochat_call_diagnostics_redact_value([
        'schema_version' => 'king.call_diagnostics.telemetry.snapshot.v1',
        'recorded_at' => gmdate('c'),
        'scope' => ['tenant_id' => $callTenantId, 'call_id' => trim($callId)],
        'instance' => $instanceSnapshot,
        'instances' => [$instanceSnapshot],
        'health' => [
            'status' => 'ok',
            'database' => ['ok' => true, 'driver' => (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)],
            'catalog' => ['entry_count' => $catalogCount],
            'runtime' => videochat_call_diagnostics_runtime_status(),
        ],
        'resources' => [
            'cpu' => $cpuSnapshot,
            'load_average' => $loadValues,
            'memory' => $memorySnapshot,
        ],
        'container' => $containerSnapshot,
        'calls' => [
            'active_count' => $activeCallCount,
            'current' => [
                'id' => trim($callId),
                'status' => (string) ($call['status'] ?? ''),
                'room_id' => (string) ($call['room_id'] ?? ''),
                'participant_count' => $participantCount,
                'active_call_app_session_count' => $sessionCount,
            ],
        ],
        'websockets' => [
            'known' => is_numeric($activeWebsocketCount),
            'active_count' => is_numeric($activeWebsocketCount) ? (int) $activeWebsocketCount : null,
            'source' => is_numeric($activeWebsocketCount) ? 'realtime_presence_connections' : 'not_exposed_by_php_process',
        ],
        'recent_errors' => videochat_call_diagnostics_recent_errors($pdo, trim($callId)),
    ]);
}

function videochat_call_diagnostics_handle_telemetry_snapshot_route(
    string $path,
    string $method,
    array $apiAuthContext,
    callable $jsonResponse,
    callable $errorResponse,
    callable $openDatabase
): ?array {
    if ($path === '/api/admin/call-diagnostics/telemetry') {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/admin/call-diagnostics/telemetry.', ['allowed_methods' => ['GET']]);
        }
        $actorUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
        $tenantId = videochat_tenant_id_from_auth_context($apiAuthContext);
        if ($actorUserId <= 0 || $tenantId <= 0) {
            return $errorResponse(401, 'auth_failed', 'A valid administrator session and tenant context are required.', ['reason' => 'invalid_user_or_tenant_context']);
        }
        if (!videochat_call_app_actor_can_use_internal_admin_apps($apiAuthContext)) {
            return $errorResponse(403, 'call_diagnostics_admin_required', 'Call Diagnostics telemetry requires a platform administrator session.', ['reason' => 'platform_admin_required']);
        }
        try {
            $snapshot = videochat_call_diagnostics_telemetry_snapshot($openDatabase(), $tenantId, '', []);
        } catch (Throwable) {
            return $errorResponse(503, 'call_diagnostics_telemetry_unavailable', 'Call Diagnostics telemetry is temporarily unavailable.', ['reason' => 'telemetry_snapshot_unavailable']);
        }
        return $jsonResponse(200, ['status' => 'ok', 'result' => $snapshot, 'time' => gmdate('c')]);
    }
    if (preg_match('#^/api/calls/([A-Za-z0-9._-]{1,200})/call-apps/call-diagnostics/telemetry-snapshot$#', $path, $match) !== 1) {
        return null;
    }
    if ($method !== 'GET') {
        return $errorResponse(405, 'method_not_allowed', 'Use GET for Call Diagnostics telemetry snapshots.', ['allowed_methods' => ['GET']]);
    }
    $actorUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
    $tenantId = videochat_tenant_id_from_auth_context($apiAuthContext);
    if ($actorUserId <= 0 || $tenantId <= 0) {
        return $errorResponse(401, 'auth_failed', 'A valid administrator session and tenant context are required.', ['reason' => 'invalid_user_or_tenant_context']);
    }
    if (!videochat_call_app_actor_can_use_internal_admin_apps($apiAuthContext)) {
        return $errorResponse(403, 'call_diagnostics_admin_required', 'Call Diagnostics telemetry requires a platform administrator session.', ['reason' => 'platform_admin_required']);
    }

    $callId = (string) ($match[1] ?? '');
    try {
        $pdo = $openDatabase();
        $callResolution = videochat_get_call_for_user($pdo, $callId, $actorUserId, (string) (($apiAuthContext['user']['role'] ?? 'user')), $tenantId);
        if (!(bool) ($callResolution['ok'] ?? false)) {
            $reason = (string) ($callResolution['reason'] ?? 'internal_error');
            return $errorResponse($reason === 'forbidden' ? 403 : 404, $reason === 'forbidden' ? 'calls_forbidden' : 'calls_not_found', $reason === 'forbidden' ? 'You are not allowed to view this call.' : 'The requested call does not exist.', ['call_id' => $callId]);
        }
        $call = is_array($callResolution['call'] ?? null) ? $callResolution['call'] : [];
        $snapshot = videochat_call_diagnostics_telemetry_snapshot($pdo, (int) ($call['tenant_id'] ?? $tenantId), $callId, $call);
    } catch (Throwable) {
        return $errorResponse(503, 'call_diagnostics_telemetry_unavailable', 'Call Diagnostics telemetry is temporarily unavailable.', ['reason' => 'telemetry_snapshot_unavailable']);
    }

    return $jsonResponse(200, ['status' => 'ok', 'result' => ['telemetry' => $snapshot], 'time' => gmdate('c')]);
}
