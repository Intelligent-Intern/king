<?php

declare(strict_types=1);

require_once __DIR__ . '/../audit/audit_events.php';

function videochat_call_access_review_public_id(string $prefix): string
{
    try {
        $bytes = random_bytes(16);
    } catch (Throwable) {
        $bytes = hash('sha256', uniqid($prefix, true) . microtime(true), true);
        if (!is_string($bytes) || strlen($bytes) < 16) {
            $bytes = str_repeat("\0", 16);
        }
        $bytes = substr($bytes, 0, 16);
    }

    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);

    return sprintf(
        '%s_%s-%s-%s-%s-%s',
        $prefix,
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

function videochat_call_access_review_bootstrap(PDO $pdo): bool
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
CREATE TABLE IF NOT EXISTS call_access_review_flags (
    {$idColumn},
    public_id TEXT NOT NULL UNIQUE,
    tenant_id INTEGER,
    call_id TEXT NOT NULL DEFAULT '',
    access_fingerprint TEXT NOT NULL,
    reason TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'open',
    subject_user_id INTEGER,
    target_user_id INTEGER,
    first_seen_user_id INTEGER,
    first_seen_at TEXT,
    payload_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL
)
SQL
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_call_access_review_flags_call ON call_access_review_flags(call_id, created_at DESC)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_call_access_review_flags_subject ON call_access_review_flags(subject_user_id, created_at DESC)');
        $pdo->exec(
            <<<'SQL'
CREATE UNIQUE INDEX IF NOT EXISTS idx_call_access_review_flags_unique_duplicate
ON call_access_review_flags(access_fingerprint, reason, subject_user_id)
SQL
        );

        $pdo->exec(
            <<<SQL
CREATE TABLE IF NOT EXISTS call_access_host_verification_attempts (
    {$idColumn},
    tenant_id INTEGER,
    call_id TEXT NOT NULL DEFAULT '',
    access_fingerprint TEXT NOT NULL,
    actor_user_id INTEGER,
    host_name_fingerprint TEXT NOT NULL DEFAULT '',
    outcome TEXT NOT NULL,
    created_at TEXT NOT NULL
)
SQL
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_call_access_host_attempts_actor ON call_access_host_verification_attempts(access_fingerprint, actor_user_id, created_at DESC)');
    } catch (Throwable) {
        return false;
    }

    return true;
}

function videochat_call_access_review_tenant_id(array $accessLink, array $call = []): ?int
{
    if (is_numeric($accessLink['tenant_id'] ?? null) && (int) $accessLink['tenant_id'] > 0) {
        return (int) $accessLink['tenant_id'];
    }
    if (is_numeric($call['tenant_id'] ?? null) && (int) $call['tenant_id'] > 0) {
        return (int) $call['tenant_id'];
    }
    return null;
}

function videochat_call_access_review_call_id(array $accessLink, array $call = []): string
{
    $callId = trim((string) ($call['id'] ?? ''));
    if ($callId !== '') {
        return $callId;
    }

    return trim((string) ($accessLink['call_id'] ?? ''));
}

function videochat_call_access_review_access_fingerprint(array $accessLink): string
{
    return videochat_audit_fingerprint((string) ($accessLink['id'] ?? ''));
}

function videochat_call_access_review_fetch_first_seen_user(PDO $pdo, string $accessId, int $actorUserId, int $linkedUserId): array
{
    try {
        $query = $pdo->prepare(
            <<<'SQL'
SELECT user_id, issued_at
FROM call_access_sessions
WHERE access_id = :access_id
  AND user_id <> :actor_user_id
ORDER BY issued_at ASC
LIMIT 1
SQL
        );
        $query->execute([
            ':access_id' => $accessId,
            ':actor_user_id' => $actorUserId,
        ]);
        $row = $query->fetch();
    } catch (Throwable) {
        $row = false;
    }

    if (is_array($row) && is_numeric($row['user_id'] ?? null)) {
        return [
            'user_id' => (int) $row['user_id'],
            'seen_at' => is_string($row['issued_at'] ?? null) ? (string) $row['issued_at'] : '',
        ];
    }

    return [
        'user_id' => $linkedUserId,
        'seen_at' => '',
    ];
}

function videochat_call_access_record_duplicate_personalized_link_review(
    PDO $pdo,
    array $accessLink,
    array $call,
    ?array $linkedUser,
    int $actorUserId,
    string $stage,
    array $options = []
): array {
    $linkKind = function_exists('videochat_call_access_link_kind')
        ? videochat_call_access_link_kind($accessLink)
        : 'personal';
    if ($linkKind !== 'personal') {
        return ['ok' => true, 'reason' => 'not_personal_link', 'flag_created' => false, 'flag' => null];
    }

    $linkedUserId = is_array($linkedUser) && is_numeric($linkedUser['id'] ?? null) ? (int) $linkedUser['id'] : 0;
    if ($actorUserId <= 0 || $linkedUserId <= 0) {
        return ['ok' => true, 'reason' => 'missing_account_context', 'flag_created' => false, 'flag' => null];
    }
    if ($actorUserId === $linkedUserId) {
        return ['ok' => true, 'reason' => 'same_account', 'flag_created' => false, 'flag' => null];
    }
    if (!videochat_call_access_review_bootstrap($pdo)) {
        return ['ok' => false, 'reason' => 'review_unavailable', 'flag_created' => false, 'flag' => null];
    }

    $accessId = trim((string) ($accessLink['id'] ?? ''));
    $accessFingerprint = videochat_call_access_review_access_fingerprint($accessLink);
    $tenantId = videochat_call_access_review_tenant_id($accessLink, $call);
    $callId = videochat_call_access_review_call_id($accessLink, $call);
    $firstSeen = videochat_call_access_review_fetch_first_seen_user($pdo, $accessId, $actorUserId, $linkedUserId);
    $sessionId = trim((string) ($options['session_id'] ?? ''));
    $createdAt = gmdate('c');
    $payload = [
        'flag' => 'duplicate_personalized_link',
        'stage' => strtolower(trim($stage)) ?: 'unknown',
        'link_kind' => 'personal',
        'review_status' => 'manual_review_required',
        'raw_link_identifier_logged' => false,
        'account_email_logged' => false,
        'host_name_logged' => false,
    ];
    $payloadJson = json_encode(videochat_audit_sanitize_payload($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($payloadJson) || $payloadJson === '') {
        $payloadJson = '{}';
    }

    $existing = null;
    $existingQuery = $pdo->prepare(
        <<<'SQL'
SELECT *
FROM call_access_review_flags
WHERE access_fingerprint = :access_fingerprint
  AND reason = 'duplicate_personalized_link'
  AND subject_user_id = :subject_user_id
LIMIT 1
SQL
    );
    $existingQuery->execute([
        ':access_fingerprint' => $accessFingerprint,
        ':subject_user_id' => $actorUserId,
    ]);
    $existingRow = $existingQuery->fetch();
    if (is_array($existingRow)) {
        $existing = $existingRow;
    }

    $flagCreated = false;
    if (!is_array($existing)) {
        try {
            $insert = $pdo->prepare(
                <<<'SQL'
INSERT INTO call_access_review_flags(
    public_id, tenant_id, call_id, access_fingerprint, reason, status,
    subject_user_id, target_user_id, first_seen_user_id, first_seen_at,
    payload_json, created_at
) VALUES(
    :public_id, :tenant_id, :call_id, :access_fingerprint, 'duplicate_personalized_link', 'open',
    :subject_user_id, :target_user_id, :first_seen_user_id, :first_seen_at,
    :payload_json, :created_at
)
SQL
            );
            $insert->execute([
                ':public_id' => videochat_call_access_review_public_id('review'),
                ':tenant_id' => $tenantId,
                ':call_id' => $callId,
                ':access_fingerprint' => $accessFingerprint,
                ':subject_user_id' => $actorUserId,
                ':target_user_id' => $linkedUserId,
                ':first_seen_user_id' => (int) ($firstSeen['user_id'] ?? 0) > 0 ? (int) $firstSeen['user_id'] : null,
                ':first_seen_at' => trim((string) ($firstSeen['seen_at'] ?? '')) ?: null,
                ':payload_json' => $payloadJson,
                ':created_at' => $createdAt,
            ]);
            $flagCreated = true;
        } catch (Throwable) {
            $existingQuery->execute([
                ':access_fingerprint' => $accessFingerprint,
                ':subject_user_id' => $actorUserId,
            ]);
            $existing = $existingQuery->fetch();
        }
    }

    videochat_audit_record_event($pdo, [
        'tenant_id' => $tenantId,
        'event_type' => 'call_access_duplicate_personalized_link_review',
        'actor_user_id' => $actorUserId,
        'target_user_id' => $linkedUserId,
        'call_id' => $callId,
        'resource_type' => 'call_access_link',
        'resource_fingerprint' => $accessFingerprint,
        'session_fingerprint' => $sessionId === '' ? '' : videochat_audit_fingerprint($sessionId),
        'payload' => $payload + [
            'flag_created' => $flagCreated,
            'first_seen_user_id' => (int) ($firstSeen['user_id'] ?? 0),
        ],
    ]);

    $flag = null;
    if ($flagCreated) {
        $existingQuery->execute([
            ':access_fingerprint' => $accessFingerprint,
            ':subject_user_id' => $actorUserId,
        ]);
        $flagRow = $existingQuery->fetch();
        $flag = is_array($flagRow) ? $flagRow : null;
    } elseif (is_array($existing)) {
        $flag = $existing;
    }

    return [
        'ok' => true,
        'reason' => 'duplicate_personalized_link',
        'flag_created' => $flagCreated,
        'flag' => $flag,
    ];
}

function videochat_call_access_host_verification_limit(): int
{
    $limit = (int) (getenv('VIDEOCHAT_CALL_ACCESS_HOST_VERIFICATION_LIMIT') ?: 5);
    return max(1, min(30, $limit));
}

function videochat_call_access_host_verification_window_seconds(): int
{
    $seconds = (int) (getenv('VIDEOCHAT_CALL_ACCESS_HOST_VERIFICATION_WINDOW_SECONDS') ?: 900);
    return max(60, min(86_400, $seconds));
}

function videochat_call_access_host_verification_rate_limit(
    PDO $pdo,
    array $accessLink,
    array $call,
    int $actorUserId
): array {
    if ($actorUserId <= 0) {
        return ['ok' => true, 'reason' => 'anonymous_or_missing_actor', 'remaining' => videochat_call_access_host_verification_limit()];
    }
    if (!videochat_call_access_review_bootstrap($pdo)) {
        return ['ok' => true, 'reason' => 'review_unavailable', 'remaining' => videochat_call_access_host_verification_limit()];
    }

    $limit = videochat_call_access_host_verification_limit();
    $windowSeconds = videochat_call_access_host_verification_window_seconds();
    $cutoff = gmdate('c', time() - $windowSeconds);
    $query = $pdo->prepare(
        <<<'SQL'
SELECT COUNT(*)
FROM call_access_host_verification_attempts
WHERE access_fingerprint = :access_fingerprint
  AND actor_user_id = :actor_user_id
  AND created_at >= :cutoff
SQL
    );
    $query->execute([
        ':access_fingerprint' => videochat_call_access_review_access_fingerprint($accessLink),
        ':actor_user_id' => $actorUserId,
        ':cutoff' => $cutoff,
    ]);
    $count = (int) $query->fetchColumn();
    if ($count >= $limit) {
        videochat_call_access_record_host_verification_attempt($pdo, $accessLink, $call, $actorUserId, '', 'rate_limited');
        return [
            'ok' => false,
            'reason' => 'rate_limited',
            'remaining' => 0,
            'retry_after_seconds' => $windowSeconds,
        ];
    }

    return ['ok' => true, 'reason' => 'allowed', 'remaining' => max(0, $limit - $count - 1)];
}

function videochat_call_access_record_host_verification_attempt(
    PDO $pdo,
    array $accessLink,
    array $call,
    int $actorUserId,
    string $hostName,
    string $outcome
): array {
    if (!videochat_call_access_review_bootstrap($pdo)) {
        return ['ok' => false, 'reason' => 'review_unavailable'];
    }

    $normalizedOutcome = strtolower(trim($outcome));
    if (!in_array($normalizedOutcome, ['wrong_host_name', 'correct_host_name', 'rate_limited'], true)) {
        $normalizedOutcome = 'wrong_host_name';
    }
    $normalizedHostName = strtolower(trim($hostName));
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO call_access_host_verification_attempts(
    tenant_id, call_id, access_fingerprint, actor_user_id, host_name_fingerprint, outcome, created_at
) VALUES(
    :tenant_id, :call_id, :access_fingerprint, :actor_user_id, :host_name_fingerprint, :outcome, :created_at
)
SQL
    );
    try {
        $insert->execute([
            ':tenant_id' => videochat_call_access_review_tenant_id($accessLink, $call),
            ':call_id' => videochat_call_access_review_call_id($accessLink, $call),
            ':access_fingerprint' => videochat_call_access_review_access_fingerprint($accessLink),
            ':actor_user_id' => $actorUserId > 0 ? $actorUserId : null,
            ':host_name_fingerprint' => $normalizedHostName === '' ? '' : videochat_audit_fingerprint($normalizedHostName),
            ':outcome' => $normalizedOutcome,
            ':created_at' => gmdate('c'),
        ]);
    } catch (Throwable) {
        return ['ok' => false, 'reason' => 'attempt_write_failed'];
    }

    return ['ok' => true, 'reason' => 'recorded'];
}
