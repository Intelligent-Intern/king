<?php

declare(strict_types=1);

function videochat_audit_event_public_id(): string
{
    try {
        $bytes = random_bytes(16);
    } catch (Throwable) {
        $bytes = hash('sha256', uniqid((string) mt_rand(), true) . microtime(true), true);
        if (!is_string($bytes) || strlen($bytes) < 16) {
            $bytes = str_repeat("\0", 16);
        }
        $bytes = substr($bytes, 0, 16);
    }

    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

    $hex = bin2hex($bytes);
    return sprintf(
        'audit_%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

function videochat_audit_fingerprint(mixed $value): string
{
    $normalized = trim((string) $value);
    if ($normalized === '') {
        return '';
    }

    return 'sha256:' . hash('sha256', $normalized);
}

function videochat_audit_payload_key_is_sensitive(string $key): bool
{
    $normalized = strtolower(trim($key));
    if ($normalized === '') {
        return false;
    }

    if (preg_match(
        '/(^|[_-])(access[_-]?id|authorization|candidate|cookie|dtls|frame|ice|media|offer|answer|password|rtp|sdp|secret|session([_-]?id)?|srtp|stream|token|track|webrtc)[_-]?(count|total)([_-]|$)/',
        $normalized
    ) === 1) {
        return false;
    }

    return preg_match(
        '/(^|[_-])(access[_-]?id|authorization|candidate|cookie|dtls|frame|ice|media|offer|answer|password|rtp|sdp|secret|session([_-]?id)?|srtp|stream|token|track|webrtc)([_-]|$)/',
        $normalized
    ) === 1;
}

function videochat_audit_sanitize_scalar(mixed $value): mixed
{
    if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
        return $value;
    }

    $text = trim((string) $value);
    if (strlen($text) > 500) {
        return substr($text, 0, 500);
    }

    return $text;
}

function videochat_audit_sanitize_payload(mixed $value, int $depth = 0): mixed
{
    if ($depth > 6) {
        return '[truncated]';
    }
    if (is_object($value)) {
        $value = get_object_vars($value);
    }
    if (!is_array($value)) {
        return videochat_audit_sanitize_scalar($value);
    }

    $sanitized = [];
    $index = 0;
    foreach ($value as $key => $entry) {
        if ($index >= 80) {
            $sanitized['truncated'] = true;
            break;
        }
        $index++;
        $stringKey = is_string($key) ? $key : (string) $key;
        if (is_string($key) && videochat_audit_payload_key_is_sensitive($stringKey)) {
            continue;
        }
        $sanitized[$key] = videochat_audit_sanitize_payload($entry, $depth + 1);
    }

    return $sanitized;
}

function videochat_audit_bootstrap(PDO $pdo): bool
{
    try {
        $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    } catch (Throwable) {
        $driver = '';
    }

    $idColumn = $driver === 'pgsql' ? 'id BIGSERIAL PRIMARY KEY' : 'id INTEGER PRIMARY KEY AUTOINCREMENT';

    try {
        $pdo->exec(
            <<<SQL
CREATE TABLE IF NOT EXISTS videochat_audit_events (
    {$idColumn},
    public_id TEXT NOT NULL UNIQUE,
    tenant_id INTEGER,
    event_type TEXT NOT NULL,
    actor_user_id INTEGER,
    target_user_id INTEGER,
    call_id TEXT NOT NULL DEFAULT '',
    resource_type TEXT NOT NULL DEFAULT '',
    resource_id TEXT NOT NULL DEFAULT '',
    resource_fingerprint TEXT NOT NULL DEFAULT '',
    session_fingerprint TEXT NOT NULL DEFAULT '',
    payload_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL
)
SQL
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_videochat_audit_events_tenant_created ON videochat_audit_events(tenant_id, created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_videochat_audit_events_call_created ON videochat_audit_events(call_id, created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_videochat_audit_events_type_created ON videochat_audit_events(event_type, created_at)');
    } catch (Throwable) {
        return false;
    }

    return true;
}

function videochat_audit_record_event(PDO $pdo, array $event): array
{
    $eventType = strtolower(trim((string) ($event['event_type'] ?? '')));
    if ($eventType === '' || preg_match('/^[a-z0-9_.:-]{1,120}$/', $eventType) !== 1) {
        return ['ok' => false, 'reason' => 'validation_failed', 'errors' => ['event_type' => 'invalid'], 'event' => null];
    }
    if (!videochat_audit_bootstrap($pdo)) {
        return ['ok' => false, 'reason' => 'audit_unavailable', 'errors' => [], 'event' => null];
    }

    $payload = videochat_audit_sanitize_payload($event['payload'] ?? []);
    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($payloadJson) || $payloadJson === '') {
        $payloadJson = '{}';
    }

    $publicId = videochat_audit_event_public_id();
    $createdAt = gmdate('c');
    $row = [
        'public_id' => $publicId,
        'tenant_id' => is_numeric($event['tenant_id'] ?? null) && (int) $event['tenant_id'] > 0 ? (int) $event['tenant_id'] : null,
        'event_type' => $eventType,
        'actor_user_id' => is_numeric($event['actor_user_id'] ?? null) && (int) $event['actor_user_id'] > 0 ? (int) $event['actor_user_id'] : null,
        'target_user_id' => is_numeric($event['target_user_id'] ?? null) && (int) $event['target_user_id'] > 0 ? (int) $event['target_user_id'] : null,
        'call_id' => trim((string) ($event['call_id'] ?? '')),
        'resource_type' => strtolower(trim((string) ($event['resource_type'] ?? ''))),
        'resource_id' => trim((string) ($event['resource_id'] ?? '')),
        'resource_fingerprint' => trim((string) ($event['resource_fingerprint'] ?? '')),
        'session_fingerprint' => trim((string) ($event['session_fingerprint'] ?? '')),
        'payload' => $payload,
        'payload_json' => $payloadJson,
        'created_at' => $createdAt,
    ];

    try {
        $statement = $pdo->prepare(
            <<<'SQL'
INSERT INTO videochat_audit_events(
    public_id, tenant_id, event_type, actor_user_id, target_user_id, call_id,
    resource_type, resource_id, resource_fingerprint, session_fingerprint,
    payload_json, created_at
) VALUES(
    :public_id, :tenant_id, :event_type, :actor_user_id, :target_user_id, :call_id,
    :resource_type, :resource_id, :resource_fingerprint, :session_fingerprint,
    :payload_json, :created_at
)
SQL
        );
        $statement->execute([
            ':public_id' => $row['public_id'],
            ':tenant_id' => $row['tenant_id'],
            ':event_type' => $row['event_type'],
            ':actor_user_id' => $row['actor_user_id'],
            ':target_user_id' => $row['target_user_id'],
            ':call_id' => $row['call_id'],
            ':resource_type' => $row['resource_type'],
            ':resource_id' => $row['resource_id'],
            ':resource_fingerprint' => $row['resource_fingerprint'],
            ':session_fingerprint' => $row['session_fingerprint'],
            ':payload_json' => $row['payload_json'],
            ':created_at' => $row['created_at'],
        ]);
    } catch (Throwable) {
        return ['ok' => false, 'reason' => 'audit_write_failed', 'errors' => [], 'event' => null];
    }

    unset($row['payload_json']);
    return ['ok' => true, 'reason' => 'recorded', 'errors' => [], 'event' => $row];
}

function videochat_audit_record_membership_removal(PDO $pdo, int $tenantId, int $targetUserId, ?int $actorUserId = null, array $context = []): array
{
    $scopes = [];
    foreach ((array) ($context['removed_scopes'] ?? ['tenant']) as $scope) {
        $normalized = strtolower(trim((string) $scope));
        if (in_array($normalized, ['tenant', 'organization', 'group'], true)) {
            $scopes[$normalized] = $normalized;
        }
    }

    return videochat_audit_record_event($pdo, [
        'tenant_id' => $tenantId,
        'event_type' => 'membership_removed',
        'actor_user_id' => $actorUserId,
        'target_user_id' => $targetUserId,
        'call_id' => trim((string) ($context['call_id'] ?? '')),
        'resource_type' => 'tenant_membership',
        'resource_id' => (string) $targetUserId,
        'resource_fingerprint' => videochat_audit_fingerprint((string) ($context['access_id'] ?? '')),
        'payload' => [
            'removed_membership_scopes' => array_values($scopes),
            'membership_state' => 'removed',
            'call_scoped_invitation_preserved' => (bool) ($context['call_scoped_invitation_preserved'] ?? false),
            'organization_rights_preserved' => false,
            'tenant_admin_preserved' => false,
        ],
    ]);
}

function videochat_audit_record_call_access_link_open(PDO $pdo, array $accessLink, array $call, ?array $targetUser = null): array
{
    $accessId = (string) ($accessLink['id'] ?? '');
    return videochat_audit_record_event($pdo, [
        'tenant_id' => is_numeric($accessLink['tenant_id'] ?? null) ? (int) $accessLink['tenant_id'] : null,
        'event_type' => 'call_access_link_opened',
        'target_user_id' => is_array($targetUser) && is_numeric($targetUser['id'] ?? null) ? (int) $targetUser['id'] : null,
        'call_id' => (string) ($call['id'] ?? ($accessLink['call_id'] ?? '')),
        'resource_type' => 'call_access_link',
        'resource_fingerprint' => videochat_audit_fingerprint($accessId),
        'payload' => [
            'link_kind' => function_exists('videochat_call_access_link_kind') ? videochat_call_access_link_kind($accessLink) : 'unknown',
            'call_status' => (string) ($call['status'] ?? ''),
            'target_user_resolved' => is_array($targetUser),
            'raw_link_identifier_logged' => false,
        ],
    ]);
}

function videochat_audit_record_call_scoped_access_continued(PDO $pdo, array $accessLink, array $call, array $targetUser, string $sessionId): array
{
    return videochat_audit_record_event($pdo, [
        'tenant_id' => is_numeric($accessLink['tenant_id'] ?? null) ? (int) $accessLink['tenant_id'] : null,
        'event_type' => 'call_scoped_access_continued',
        'target_user_id' => is_numeric($targetUser['id'] ?? null) ? (int) $targetUser['id'] : null,
        'call_id' => (string) ($call['id'] ?? ($accessLink['call_id'] ?? '')),
        'resource_type' => 'call_access_session',
        'resource_fingerprint' => videochat_audit_fingerprint((string) ($accessLink['id'] ?? '')),
        'session_fingerprint' => videochat_audit_fingerprint($sessionId),
        'payload' => [
            'access_basis' => 'call_scoped_invitation',
            'link_kind' => function_exists('videochat_call_access_link_kind') ? videochat_call_access_link_kind($accessLink) : 'unknown',
            'tenant_membership_active' => false,
            'organization_rights_preserved' => false,
            'tenant_admin_preserved' => false,
            'raw_session_identifier_logged' => false,
        ],
    ]);
}

function videochat_audit_fetch_events(PDO $pdo, array $filters = []): array
{
    if (!videochat_audit_bootstrap($pdo)) {
        return [];
    }

    $where = [];
    $params = [];
    if (is_numeric($filters['tenant_id'] ?? null) && (int) $filters['tenant_id'] > 0) {
        $where[] = 'tenant_id = :tenant_id';
        $params[':tenant_id'] = (int) $filters['tenant_id'];
    }
    if (is_string($filters['event_type'] ?? null) && trim((string) $filters['event_type']) !== '') {
        $where[] = 'event_type = :event_type';
        $params[':event_type'] = strtolower(trim((string) $filters['event_type']));
    }
    if (is_string($filters['call_id'] ?? null) && trim((string) $filters['call_id']) !== '') {
        $where[] = 'call_id = :call_id';
        $params[':call_id'] = trim((string) $filters['call_id']);
    }

    $limit = max(1, min(200, (int) ($filters['limit'] ?? 100)));
    $whereSql = $where === [] ? '' : ('WHERE ' . implode(' AND ', $where));
    $statement = $pdo->prepare(
        <<<SQL
SELECT *
FROM videochat_audit_events
{$whereSql}
ORDER BY created_at ASC, id ASC
LIMIT {$limit}
SQL
    );
    $statement->execute($params);

    $events = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true);
        $events[] = [
            'id' => (string) ($row['public_id'] ?? ''),
            'tenant_id' => is_numeric($row['tenant_id'] ?? null) ? (int) $row['tenant_id'] : null,
            'event_type' => (string) ($row['event_type'] ?? ''),
            'actor_user_id' => is_numeric($row['actor_user_id'] ?? null) ? (int) $row['actor_user_id'] : null,
            'target_user_id' => is_numeric($row['target_user_id'] ?? null) ? (int) $row['target_user_id'] : null,
            'call_id' => (string) ($row['call_id'] ?? ''),
            'resource_type' => (string) ($row['resource_type'] ?? ''),
            'resource_id' => (string) ($row['resource_id'] ?? ''),
            'resource_fingerprint' => (string) ($row['resource_fingerprint'] ?? ''),
            'session_fingerprint' => (string) ($row['session_fingerprint'] ?? ''),
            'payload' => is_array($payload) ? $payload : [],
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    return $events;
}
