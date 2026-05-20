<?php

declare(strict_types=1);

require_once __DIR__ . '/../audit/audit_events.php';
require_once __DIR__ . '/call_management_query.php';

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

function videochat_call_access_review_flag_has_column(PDO $pdo, string $columnName): bool
{
    $allowed = [
        'handled_by_user_id' => true,
        'handled_at' => true,
        'handled_note' => true,
    ];
    if (!isset($allowed[$columnName])) {
        return false;
    }

    if (function_exists('videochat_tenant_table_has_column') && videochat_tenant_table_has_column($pdo, 'call_access_review_flags', $columnName)) {
        return true;
    }

    try {
        $pdo->query('SELECT ' . $columnName . ' FROM call_access_review_flags WHERE 1 = 0');
        return true;
    } catch (Throwable) {
        return false;
    }
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
    handled_by_user_id INTEGER,
    handled_at TEXT,
    handled_note TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
)
SQL
        );
        $columns = [
            'handled_by_user_id' => 'ALTER TABLE call_access_review_flags ADD COLUMN handled_by_user_id INTEGER',
            'handled_at' => 'ALTER TABLE call_access_review_flags ADD COLUMN handled_at TEXT',
            'handled_note' => "ALTER TABLE call_access_review_flags ADD COLUMN handled_note TEXT NOT NULL DEFAULT ''",
        ];
        foreach ($columns as $columnName => $alterSql) {
            if (!videochat_call_access_review_flag_has_column($pdo, $columnName)) {
                $pdo->exec($alterSql);
            }
        }
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_call_access_review_flags_call ON call_access_review_flags(call_id, created_at DESC)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_call_access_review_flags_subject ON call_access_review_flags(subject_user_id, created_at DESC)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_call_access_review_flags_status ON call_access_review_flags(status, tenant_id, created_at DESC)');
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

function videochat_call_access_review_normalize_status(string $status, string $fallback = 'open'): string
{
    $normalized = strtolower(trim($status));
    if (in_array($normalized, ['open', 'resolved', 'dismissed'], true)) {
        return $normalized;
    }

    return $fallback;
}

function videochat_call_access_review_decode_payload(mixed $payloadJson): array
{
    $decoded = json_decode((string) $payloadJson, true);
    if (!is_array($decoded)) {
        return [];
    }

    $payload = videochat_audit_sanitize_payload($decoded);
    return is_array($payload) ? $payload : [];
}

function videochat_call_access_review_public_flag(array $row): array
{
    $flag = [
        'public_id' => (string) ($row['public_id'] ?? ''),
        'tenant_id' => is_numeric($row['tenant_id'] ?? null) ? (int) $row['tenant_id'] : null,
        'call_id' => (string) ($row['call_id'] ?? ''),
        'reason' => (string) ($row['reason'] ?? ''),
        'status' => videochat_call_access_review_normalize_status((string) ($row['status'] ?? 'open')),
        'subject_user_id' => is_numeric($row['subject_user_id'] ?? null) ? (int) $row['subject_user_id'] : null,
        'target_user_id' => is_numeric($row['target_user_id'] ?? null) ? (int) $row['target_user_id'] : null,
        'first_seen_user_id' => is_numeric($row['first_seen_user_id'] ?? null) ? (int) $row['first_seen_user_id'] : null,
        'first_seen_at' => is_string($row['first_seen_at'] ?? null) ? (string) $row['first_seen_at'] : null,
        'payload' => videochat_call_access_review_decode_payload($row['payload_json'] ?? '{}'),
        'created_at' => (string) ($row['created_at'] ?? ''),
    ];

    if (array_key_exists('handled_by_user_id', $row)) {
        $flag['handled_by_user_id'] = is_numeric($row['handled_by_user_id'] ?? null) ? (int) $row['handled_by_user_id'] : null;
    }
    if (array_key_exists('handled_at', $row)) {
        $flag['handled_at'] = is_string($row['handled_at'] ?? null) ? (string) $row['handled_at'] : null;
    }
    if (array_key_exists('handled_note', $row)) {
        $flag['handled_note'] = trim((string) ($row['handled_note'] ?? ''));
    }

    return $flag;
}

function videochat_call_access_review_user_can_administer(PDO $pdo, int $authUserId, string $authRole): bool
{
    return videochat_user_has_system_admin_call_rights($pdo, $authUserId, $authRole);
}

function videochat_call_access_list_review_flags_for_user(PDO $pdo, int $authUserId, string $authRole, array $filters = []): array
{
    if (!videochat_call_access_review_user_can_administer($pdo, $authUserId, $authRole)) {
        return ['ok' => false, 'reason' => 'forbidden', 'flags' => [], 'total' => 0];
    }
    if (!videochat_call_access_review_bootstrap($pdo)) {
        return ['ok' => false, 'reason' => 'review_unavailable', 'flags' => [], 'total' => 0];
    }

    $status = videochat_call_access_review_normalize_status((string) ($filters['status'] ?? 'open'));
    $limit = is_numeric($filters['limit'] ?? null) ? (int) $filters['limit'] : 50;
    $limit = max(1, min(100, $limit));
    $conditions = ['status = :status'];
    $params = [':status' => $status];
    if (is_numeric($filters['tenant_id'] ?? null) && (int) $filters['tenant_id'] > 0) {
        $conditions[] = 'tenant_id = :tenant_id';
        $params[':tenant_id'] = (int) $filters['tenant_id'];
    }
    if (is_string($filters['call_id'] ?? null) && trim((string) $filters['call_id']) !== '') {
        $conditions[] = 'call_id = :call_id';
        $params[':call_id'] = trim((string) $filters['call_id']);
    }

    $whereSql = implode(' AND ', $conditions);
    try {
        $count = $pdo->prepare('SELECT COUNT(*) FROM call_access_review_flags WHERE ' . $whereSql);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $query = $pdo->prepare(
            'SELECT * FROM call_access_review_flags WHERE ' . $whereSql . ' ORDER BY created_at DESC, id DESC LIMIT :limit'
        );
        foreach ($params as $name => $value) {
            $query->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $query->bindValue(':limit', $limit, PDO::PARAM_INT);
        $query->execute();
        $rows = $query->fetchAll();
    } catch (Throwable) {
        return ['ok' => false, 'reason' => 'review_query_failed', 'flags' => [], 'total' => 0];
    }

    $flags = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        if (is_array($row)) {
            $flags[] = videochat_call_access_review_public_flag($row);
        }
    }

    return ['ok' => true, 'reason' => 'listed', 'flags' => $flags, 'total' => $total];
}

function videochat_call_access_handle_review_flag_for_user(
    PDO $pdo,
    string $publicId,
    int $authUserId,
    string $authRole,
    string $status,
    array $context = []
): array {
    if (!videochat_call_access_review_user_can_administer($pdo, $authUserId, $authRole)) {
        return ['ok' => false, 'reason' => 'forbidden', 'flag' => null];
    }
    if (!videochat_call_access_review_bootstrap($pdo)) {
        return ['ok' => false, 'reason' => 'review_unavailable', 'flag' => null];
    }

    $normalizedPublicId = trim($publicId);
    $normalizedStatus = strtolower(trim($status));
    $errors = [];
    if ($normalizedPublicId === '' || preg_match('/^[A-Za-z0-9._:-]{1,120}$/', $normalizedPublicId) !== 1) {
        $errors['id'] = 'invalid';
    }
    if (!in_array($normalizedStatus, ['open', 'resolved', 'dismissed'], true)) {
        $errors['status'] = 'invalid';
    }
    if ($errors !== []) {
        return ['ok' => false, 'reason' => 'validation_failed', 'errors' => $errors, 'flag' => null];
    }

    try {
        $query = $pdo->prepare('SELECT * FROM call_access_review_flags WHERE public_id = :public_id LIMIT 1');
        $query->execute([':public_id' => $normalizedPublicId]);
        $existing = $query->fetch();
    } catch (Throwable) {
        return ['ok' => false, 'reason' => 'review_query_failed', 'flag' => null];
    }
    if (!is_array($existing)) {
        return ['ok' => false, 'reason' => 'not_found', 'flag' => null];
    }

    $previousStatus = videochat_call_access_review_normalize_status((string) ($existing['status'] ?? 'open'));
    $handledAt = gmdate('c');
    $handledNote = trim((string) ($context['note'] ?? ''));
    if (strlen($handledNote) > 500) {
        $handledNote = substr($handledNote, 0, 500);
    }

    try {
        $update = $pdo->prepare(
            <<<'SQL'
UPDATE call_access_review_flags
SET status = :status,
    handled_by_user_id = :handled_by_user_id,
    handled_at = :handled_at,
    handled_note = :handled_note
WHERE public_id = :public_id
SQL
        );
        $update->execute([
            ':status' => $normalizedStatus,
            ':handled_by_user_id' => $authUserId,
            ':handled_at' => $handledAt,
            ':handled_note' => $handledNote,
            ':public_id' => $normalizedPublicId,
        ]);

        $query->execute([':public_id' => $normalizedPublicId]);
        $updated = $query->fetch();
    } catch (Throwable) {
        return ['ok' => false, 'reason' => 'review_update_failed', 'flag' => null];
    }
    if (!is_array($updated)) {
        return ['ok' => false, 'reason' => 'not_found', 'flag' => null];
    }

    videochat_audit_record_event($pdo, [
        'tenant_id' => is_numeric($updated['tenant_id'] ?? null) ? (int) $updated['tenant_id'] : null,
        'event_type' => 'call_access_review_flag_handled',
        'actor_user_id' => $authUserId,
        'target_user_id' => is_numeric($updated['subject_user_id'] ?? null) ? (int) $updated['subject_user_id'] : null,
        'call_id' => (string) ($updated['call_id'] ?? ''),
        'resource_type' => 'call_access_review_flag',
        'resource_fingerprint' => videochat_audit_fingerprint($normalizedPublicId),
        'payload' => [
            'audit_scope' => 'iam_call_access',
            'action' => 'handle_review_flag',
            'review_flag_public_id' => $normalizedPublicId,
            'previous_status' => $previousStatus,
            'review_status' => $normalizedStatus,
            'note_logged' => $handledNote !== '',
            'raw_link_identifier_logged' => false,
            'account_email_logged' => false,
            'host_name_logged' => false,
            'foreign_account_data_logged' => false,
        ],
    ]);

    return [
        'ok' => true,
        'reason' => 'handled',
        'flag' => videochat_call_access_review_public_flag($updated),
    ];
}

function videochat_call_access_review_tenant_id(array $accessLink, array $call = []): ?int
{
    if (is_numeric($call['tenant_id'] ?? null) && (int) $call['tenant_id'] > 0) {
        return (int) $call['tenant_id'];
    }
    if (is_numeric($accessLink['tenant_id'] ?? null) && (int) $accessLink['tenant_id'] > 0) {
        return (int) $accessLink['tenant_id'];
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

function videochat_call_access_record_identity_mismatch_review(
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
    if (!videochat_call_access_review_bootstrap($pdo)) {
        return ['ok' => false, 'reason' => 'review_unavailable', 'flag_created' => false, 'flag' => null];
    }

    $linkedUserId = is_array($linkedUser) && is_numeric($linkedUser['id'] ?? null) ? (int) $linkedUser['id'] : 0;
    $accessFingerprint = videochat_call_access_review_access_fingerprint($accessLink);
    $tenantId = videochat_call_access_review_tenant_id($accessLink, $call);
    $callId = videochat_call_access_review_call_id($accessLink, $call);
    $sessionId = trim((string) ($options['session_id'] ?? ''));
    $denialReason = strtolower(trim((string) ($options['denial_reason'] ?? 'session_context_changed'))) ?: 'session_context_changed';
    $createdAt = gmdate('c');
    $payload = [
        'flag' => 'identity_mismatch_review',
        'mismatch' => 'strong_personalized_link',
        'stage' => strtolower(trim($stage)) ?: 'unknown',
        'link_kind' => 'personal',
        'review_status' => 'manual_review_required',
        'denial_reason' => $denialReason,
        'raw_link_identifier_logged' => false,
        'raw_session_identifier_logged' => false,
        'account_email_logged' => false,
        'host_name_logged' => false,
        'foreign_account_data_logged' => false,
    ];
    $payloadJson = json_encode(videochat_audit_sanitize_payload($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($payloadJson) || $payloadJson === '') {
        $payloadJson = '{}';
    }

    $subjectSql = $actorUserId > 0 ? 'subject_user_id = :subject_user_id' : 'subject_user_id IS NULL';
    $existingQuery = $pdo->prepare(
        <<<SQL
SELECT *
FROM call_access_review_flags
WHERE access_fingerprint = :access_fingerprint
  AND reason = 'identity_mismatch_review'
  AND {$subjectSql}
LIMIT 1
SQL
    );
    $existingParams = [':access_fingerprint' => $accessFingerprint];
    if ($actorUserId > 0) {
        $existingParams[':subject_user_id'] = $actorUserId;
    }
    $existingQuery->execute($existingParams);
    $existing = $existingQuery->fetch();
    $existing = is_array($existing) ? $existing : null;

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
    :public_id, :tenant_id, :call_id, :access_fingerprint, 'identity_mismatch_review', 'open',
    :subject_user_id, :target_user_id, :first_seen_user_id, NULL,
    :payload_json, :created_at
)
SQL
            );
            $insert->execute([
                ':public_id' => videochat_call_access_review_public_id('review'),
                ':tenant_id' => $tenantId,
                ':call_id' => $callId,
                ':access_fingerprint' => $accessFingerprint,
                ':subject_user_id' => $actorUserId > 0 ? $actorUserId : null,
                ':target_user_id' => $linkedUserId > 0 ? $linkedUserId : null,
                ':first_seen_user_id' => $linkedUserId > 0 ? $linkedUserId : null,
                ':payload_json' => $payloadJson,
                ':created_at' => $createdAt,
            ]);
            $flagCreated = true;
        } catch (Throwable) {
            $existingQuery->execute($existingParams);
            $existing = $existingQuery->fetch();
            $existing = is_array($existing) ? $existing : null;
        }
    }

    videochat_audit_record_event($pdo, [
        'tenant_id' => $tenantId,
        'event_type' => 'call_access_identity_mismatch_review',
        'actor_user_id' => $actorUserId > 0 ? $actorUserId : null,
        'target_user_id' => $linkedUserId > 0 ? $linkedUserId : null,
        'call_id' => $callId,
        'resource_type' => 'call_access_link',
        'resource_fingerprint' => $accessFingerprint,
        'session_fingerprint' => $sessionId === '' ? '' : videochat_audit_fingerprint($sessionId),
        'payload' => $payload + ['flag_created' => $flagCreated],
    ]);

    if ($flagCreated) {
        $existingQuery->execute($existingParams);
        $existing = $existingQuery->fetch();
        $existing = is_array($existing) ? $existing : null;
    }

    return [
        'ok' => true,
        'reason' => 'identity_mismatch_review',
        'flag_created' => $flagCreated,
        'flag' => $existing,
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
    $hostNameVerified = $normalizedOutcome === 'correct_host_name';
    $canonicalEventType = $hostNameVerified
        ? 'call_access_host_name_verified'
        : 'call_access_host_name_verification_failed';
    $legacyEventTypes = $hostNameVerified
        ? ['call_access_host_verification_succeeded']
        : ['call_access_host_verification_failed', 'call_access_host_name_rejected'];
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

    $hostNameVerified = $normalizedOutcome === 'correct_host_name';
    $canonicalEventType = $hostNameVerified
        ? 'call_access_host_name_verified'
        : 'call_access_host_name_verification_failed';
    $legacyEventTypes = $hostNameVerified
        ? ['call_access_host_verification_succeeded']
        : ['call_access_host_verification_failed', 'call_access_host_name_rejected'];

    videochat_audit_record_event($pdo, [
        'tenant_id' => videochat_call_access_review_tenant_id($accessLink, $call),
        'event_type' => $canonicalEventType,
        'actor_user_id' => $actorUserId > 0 ? $actorUserId : null,
        'call_id' => videochat_call_access_review_call_id($accessLink, $call),
        'resource_type' => 'call_access_host_verification',
        'resource_fingerprint' => videochat_call_access_review_access_fingerprint($accessLink),
        'payload' => [
            'audit_scope' => 'iam_call_access',
            'action' => 'verify_host_name',
            'outcome' => $normalizedOutcome,
            'link_kind' => function_exists('videochat_call_access_link_kind') ? videochat_call_access_link_kind($accessLink) : 'unknown',
            'host_name_verified' => $hostNameVerified,
            'canonical_event_type' => $canonicalEventType,
            'legacy_event_types' => $legacyEventTypes,
            'host_name_logged' => false,
            'raw_link_identifier_logged' => false,
            'raw_session_identifier_logged' => false,
            'foreign_account_data_logged' => false,
        ],
    ]);

    return ['ok' => true, 'reason' => 'recorded'];
}
