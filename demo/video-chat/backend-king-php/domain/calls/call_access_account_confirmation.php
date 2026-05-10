<?php

declare(strict_types=1);

require_once __DIR__ . '/../audit/audit_events.php';
require_once __DIR__ . '/../users/user_email_identity.php';
require_once __DIR__ . '/../../support/auth_request.php';
require_once __DIR__ . '/call_access_contract.php';

function videochat_call_access_account_confirmation_public_id(): string
{
    try {
        return 'cauc_' . bin2hex(random_bytes(16));
    } catch (Throwable) {
        return 'cauc_' . hash('sha256', uniqid('call-access-account-confirmation', true) . microtime(true));
    }
}

function videochat_call_access_account_confirmation_has_column(PDO $pdo, string $columnName): bool
{
    if (function_exists('videochat_tenant_table_has_column')) {
        return videochat_tenant_table_has_column($pdo, 'call_access_account_update_confirmations', $columnName);
    }

    $allowed = [
        'requesting_session_fingerprint' => true,
        'superseded_at' => true,
        'superseded_by_fingerprint' => true,
    ];
    if (!isset($allowed[$columnName])) {
        return false;
    }

    try {
        $pdo->query('SELECT ' . $columnName . ' FROM call_access_account_update_confirmations WHERE 1 = 0');
        return true;
    } catch (Throwable) {
        return false;
    }
}

function videochat_call_access_account_confirmation_bootstrap(PDO $pdo): bool
{
    try {
        $pdo->exec(
            <<<'SQL'
CREATE TABLE IF NOT EXISTS call_access_account_update_confirmations (
    id TEXT PRIMARY KEY,
    token_fingerprint TEXT NOT NULL UNIQUE,
    tenant_id INTEGER,
    call_id TEXT NOT NULL DEFAULT '',
    access_fingerprint TEXT NOT NULL DEFAULT '',
    user_id INTEGER NOT NULL,
    recipient_email_fingerprint TEXT NOT NULL,
    requesting_session_fingerprint TEXT NOT NULL DEFAULT '',
    pending_payload_json TEXT NOT NULL DEFAULT '{}',
    expires_at TEXT NOT NULL,
    consumed_at TEXT,
    superseded_at TEXT,
    superseded_by_fingerprint TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
)
SQL
        );
        $columns = [
            'requesting_session_fingerprint' => "ALTER TABLE call_access_account_update_confirmations ADD COLUMN requesting_session_fingerprint TEXT NOT NULL DEFAULT ''",
            'superseded_at' => 'ALTER TABLE call_access_account_update_confirmations ADD COLUMN superseded_at TEXT',
            'superseded_by_fingerprint' => "ALTER TABLE call_access_account_update_confirmations ADD COLUMN superseded_by_fingerprint TEXT NOT NULL DEFAULT ''",
        ];
        foreach ($columns as $columnName => $alterSql) {
            if (!videochat_call_access_account_confirmation_has_column($pdo, $columnName)) {
                $pdo->exec($alterSql);
            }
        }
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_call_access_account_confirm_user ON call_access_account_update_confirmations(user_id, created_at DESC)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_call_access_account_confirm_access ON call_access_account_update_confirmations(access_fingerprint, created_at DESC)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_call_access_account_confirm_token ON call_access_account_update_confirmations(token_fingerprint)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_call_access_account_confirm_pending ON call_access_account_update_confirmations(user_id, access_fingerprint, consumed_at, superseded_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_call_access_account_confirm_session ON call_access_account_update_confirmations(requesting_session_fingerprint, created_at DESC)');
    } catch (Throwable) {
        return false;
    }

    return true;
}

function videochat_call_access_account_confirmation_token(): string
{
    try {
        return 'cau_' . bin2hex(random_bytes(24));
    } catch (Throwable) {
        return 'cau_' . hash('sha256', uniqid('call-access-account-update', true) . microtime(true));
    }
}

function videochat_call_access_account_confirmation_token_fingerprint(string $token): string
{
    return videochat_audit_fingerprint(trim($token));
}

function videochat_call_access_account_confirmation_session_fingerprint(string $sessionId): string
{
    $trimmedSessionId = trim($sessionId);
    return $trimmedSessionId === '' ? '' : videochat_audit_fingerprint($trimmedSessionId);
}

function videochat_call_access_account_confirmation_ttl_seconds(): int
{
    $seconds = (int) (getenv('VIDEOCHAT_CALL_ACCESS_ACCOUNT_CONFIRMATION_TTL_SECONDS') ?: 3600);
    return max(300, min(86_400, $seconds));
}

function videochat_call_access_account_confirmation_rate_limit(): int
{
    $limit = (int) (getenv('VIDEOCHAT_CALL_ACCESS_ACCOUNT_UPDATE_CONFIRMATION_LIMIT') ?: 3);
    return max(1, min(20, $limit));
}

function videochat_call_access_account_confirmation_rate_window_seconds(): int
{
    $seconds = (int) (getenv('VIDEOCHAT_CALL_ACCESS_ACCOUNT_UPDATE_CONFIRMATION_WINDOW_SECONDS') ?: 900);
    return max(60, min(86_400, $seconds));
}

function videochat_call_access_account_confirmation_invalidate_older_enabled(array $options = []): bool
{
    if (array_key_exists('invalidate_older', $options)) {
        return (bool) $options['invalidate_older'];
    }

    $value = getenv('VIDEOCHAT_CALL_ACCESS_ACCOUNT_CONFIRMATION_INVALIDATE_OLDER');
    if ($value === false || trim((string) $value) === '') {
        return true;
    }

    return !in_array(strtolower(trim((string) $value)), ['0', 'false', 'no', 'off'], true);
}

function videochat_call_access_account_confirmation_pending_payload(array $manualData): array
{
    $displayName = trim((string) ($manualData['display_name'] ?? ''));
    if ($displayName === '') {
        $firstName = trim((string) ($manualData['first_name'] ?? ''));
        $lastName = trim((string) ($manualData['last_name'] ?? ''));
        $displayName = trim($firstName . ' ' . $lastName);
    }
    if ($displayName === '' || strlen($displayName) > 160) {
        return [];
    }

    return ['display_name' => $displayName];
}

function videochat_call_access_account_confirmation_rate_state(PDO $pdo, int $userId): array
{
    $limit = videochat_call_access_account_confirmation_rate_limit();
    if ($userId <= 0) {
        return ['ok' => false, 'reason' => 'invalid_user', 'remaining' => 0];
    }

    $cutoff = gmdate('c', time() - videochat_call_access_account_confirmation_rate_window_seconds());
    $query = $pdo->prepare(
        <<<'SQL'
SELECT COUNT(*)
FROM call_access_account_update_confirmations
WHERE user_id = :user_id
  AND created_at >= :cutoff
SQL
    );
    $query->execute([
        ':user_id' => $userId,
        ':cutoff' => $cutoff,
    ]);
    $count = (int) $query->fetchColumn();
    if ($count >= $limit) {
        return [
            'ok' => false,
            'reason' => 'rate_limited',
            'remaining' => 0,
            'retry_after_seconds' => videochat_call_access_account_confirmation_rate_window_seconds(),
        ];
    }

    return ['ok' => true, 'reason' => 'allowed', 'remaining' => max(0, $limit - $count - 1)];
}

function videochat_call_access_request_account_update_confirmation(
    PDO $pdo,
    string $accessId,
    int $authenticatedUserId,
    array $manualData,
    array $options = []
): array {
    $normalizedAccessId = videochat_normalize_call_access_id($accessId);
    if ($normalizedAccessId === '') {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['access_id' => 'invalid_access_id'],
            'token' => null,
            'recipient_email' => null,
        ];
    }
    if ($authenticatedUserId <= 0) {
        return [
            'ok' => false,
            'reason' => 'forbidden',
            'errors' => ['auth' => 'invalid_user_context'],
            'token' => null,
            'recipient_email' => null,
        ];
    }
    if (!videochat_call_access_account_confirmation_bootstrap($pdo)) {
        return [
            'ok' => false,
            'reason' => 'confirmation_unavailable',
            'errors' => [],
            'token' => null,
            'recipient_email' => null,
        ];
    }

    $accessLink = videochat_fetch_call_access_link($pdo, $normalizedAccessId);
    if (!is_array($accessLink)) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => ['access_id' => 'not_found'],
            'token' => null,
            'recipient_email' => null,
        ];
    }
    $linkKind = function_exists('videochat_call_access_link_kind') ? videochat_call_access_link_kind($accessLink) : 'personal';
    if ($linkKind !== 'personal') {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['access_id' => 'personalized_link_required'],
            'token' => null,
            'recipient_email' => null,
        ];
    }

    $user = videochat_fetch_user_auth_snapshot($pdo, $authenticatedUserId);
    if (!is_array($user) || (string) ($user['status'] ?? '') !== 'active') {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => ['user' => 'not_found_or_inactive'],
            'token' => null,
            'recipient_email' => null,
        ];
    }

    $pendingPayload = videochat_call_access_account_confirmation_pending_payload($manualData);
    if ($pendingPayload === []) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['display_name' => 'required_manual_reentry'],
            'token' => null,
            'recipient_email' => null,
        ];
    }

    $rate = videochat_call_access_account_confirmation_rate_state($pdo, $authenticatedUserId);
    if (!(bool) ($rate['ok'] ?? false)) {
        videochat_audit_record_event($pdo, [
            'tenant_id' => is_numeric($accessLink['tenant_id'] ?? null) ? (int) $accessLink['tenant_id'] : null,
            'event_type' => 'call_access_account_update_confirmation_rate_limited',
            'actor_user_id' => $authenticatedUserId,
            'target_user_id' => $authenticatedUserId,
            'call_id' => (string) ($accessLink['call_id'] ?? ''),
            'resource_type' => 'call_access_account_update_confirmation',
            'resource_fingerprint' => videochat_audit_fingerprint($normalizedAccessId),
            'session_fingerprint' => videochat_audit_fingerprint((string) ($options['session_id'] ?? '')),
            'payload' => [
                'reason' => 'rate_limited',
                'raw_link_identifier_logged' => false,
                'confirmation_identifier_logged' => false,
                'recipient_email_logged' => false,
            ],
        ]);

        return [
            'ok' => false,
            'reason' => 'rate_limited',
            'errors' => ['confirmation' => 'rate_limited'],
            'token' => null,
            'recipient_email' => null,
            'retry_after_seconds' => (int) ($rate['retry_after_seconds'] ?? 0),
        ];
    }

    $token = videochat_call_access_account_confirmation_token();
    $tokenFingerprint = videochat_call_access_account_confirmation_token_fingerprint($token);
    $createdAt = gmdate('c');
    $expiresAt = gmdate('c', time() + videochat_call_access_account_confirmation_ttl_seconds());
    $accessFingerprint = videochat_audit_fingerprint($normalizedAccessId);
    $sessionFingerprint = videochat_call_access_account_confirmation_session_fingerprint((string) ($options['session_id'] ?? ''));
    $payloadJson = json_encode($pendingPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($payloadJson) || $payloadJson === '') {
        $payloadJson = '{}';
    }
    $recipientEmail = strtolower(trim((string) ($user['email'] ?? '')));

    $startedTransaction = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTransaction = true;
    }
    $supersededPendingCount = 0;
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO call_access_account_update_confirmations(
    id, token_fingerprint, tenant_id, call_id, access_fingerprint, user_id, recipient_email_fingerprint,
    requesting_session_fingerprint, pending_payload_json, expires_at, consumed_at, superseded_at,
    superseded_by_fingerprint, created_at
) VALUES(
    :id, :token_fingerprint, :tenant_id, :call_id, :access_fingerprint, :user_id, :recipient_email_fingerprint,
    :requesting_session_fingerprint, :pending_payload_json, :expires_at, NULL, NULL,
    '', :created_at
)
SQL
    );
    try {
        $insert->execute([
            ':id' => videochat_call_access_account_confirmation_public_id(),
            ':token_fingerprint' => $tokenFingerprint,
            ':tenant_id' => is_numeric($accessLink['tenant_id'] ?? null) ? (int) $accessLink['tenant_id'] : null,
            ':call_id' => (string) ($accessLink['call_id'] ?? ''),
            ':access_fingerprint' => $accessFingerprint,
            ':user_id' => $authenticatedUserId,
            ':recipient_email_fingerprint' => videochat_audit_fingerprint($recipientEmail),
            ':requesting_session_fingerprint' => $sessionFingerprint,
            ':pending_payload_json' => $payloadJson,
            ':expires_at' => $expiresAt,
            ':created_at' => $createdAt,
        ]);

        if (videochat_call_access_account_confirmation_invalidate_older_enabled($options)) {
            $supersede = $pdo->prepare(
                <<<'SQL'
UPDATE call_access_account_update_confirmations
SET superseded_at = :superseded_at,
    superseded_by_fingerprint = :superseded_by_fingerprint
WHERE user_id = :user_id
  AND access_fingerprint = :access_fingerprint
  AND token_fingerprint <> :token_fingerprint
  AND (consumed_at IS NULL OR trim(consumed_at) = '')
  AND (superseded_at IS NULL OR trim(superseded_at) = '')
SQL
            );
            $supersede->execute([
                ':superseded_at' => $createdAt,
                ':superseded_by_fingerprint' => $tokenFingerprint,
                ':user_id' => $authenticatedUserId,
                ':access_fingerprint' => $accessFingerprint,
                ':token_fingerprint' => $tokenFingerprint,
            ]);
            $supersededPendingCount = max(0, $supersede->rowCount());
        }

        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [
            'ok' => false,
            'reason' => 'internal_error',
            'errors' => [],
            'token' => null,
            'recipient_email' => null,
        ];
    }

    if ($supersededPendingCount > 0) {
        videochat_audit_record_event($pdo, [
            'tenant_id' => is_numeric($accessLink['tenant_id'] ?? null) ? (int) $accessLink['tenant_id'] : null,
            'event_type' => 'call_access_account_update_confirmation_superseded',
            'actor_user_id' => $authenticatedUserId,
            'target_user_id' => $authenticatedUserId,
            'call_id' => (string) ($accessLink['call_id'] ?? ''),
            'resource_type' => 'call_access_account_update_confirmation',
            'resource_fingerprint' => $accessFingerprint,
            'session_fingerprint' => $sessionFingerprint,
            'payload' => [
                'superseded_pending_count' => $supersededPendingCount,
                'newer_change_invalidates_older' => true,
                'confirmation_identifier_logged' => false,
                'raw_link_identifier_logged' => false,
                'recipient_email_logged' => false,
                'session_identifier_logged' => false,
            ],
        ]);
    }

    videochat_audit_record_event($pdo, [
        'tenant_id' => is_numeric($accessLink['tenant_id'] ?? null) ? (int) $accessLink['tenant_id'] : null,
        'event_type' => 'call_access_account_update_confirmation_requested',
        'actor_user_id' => $authenticatedUserId,
        'target_user_id' => $authenticatedUserId,
        'call_id' => (string) ($accessLink['call_id'] ?? ''),
        'resource_type' => 'call_access_account_update_confirmation',
        'resource_fingerprint' => $accessFingerprint,
        'session_fingerprint' => $sessionFingerprint,
        'payload' => [
            'manual_reentry_required' => true,
            'sent_to_logged_in_account' => true,
            'sent_to_link_account' => false,
            'pending_fields' => array_keys($pendingPayload),
            'request_session_bound' => $sessionFingerprint !== '',
            'newer_request_invalidates_older' => videochat_call_access_account_confirmation_invalidate_older_enabled($options),
            'raw_link_identifier_logged' => false,
            'confirmation_identifier_logged' => false,
            'recipient_email_logged' => false,
            'session_identifier_logged' => false,
        ],
    ]);

    return [
        'ok' => true,
        'reason' => 'pending_confirmation',
        'errors' => [],
        'token' => $token,
        'expires_at' => $expiresAt,
        'recipient_email' => $recipientEmail,
        'recipient_user_id' => $authenticatedUserId,
        'sent_to_logged_in_account' => true,
        'sent_to_link_account' => false,
        'superseded_pending_count' => $supersededPendingCount,
    ];
}

function videochat_call_access_record_account_confirmation_failure(
    PDO $pdo,
    array $row,
    string $reason,
    int $actorUserId = 0,
    string $sessionFingerprint = ''
): void
{
    $rowUserId = is_numeric($row['user_id'] ?? null) ? (int) $row['user_id'] : null;
    videochat_audit_record_event($pdo, [
        'tenant_id' => is_numeric($row['tenant_id'] ?? null) ? (int) $row['tenant_id'] : null,
        'event_type' => 'call_access_account_update_confirmation_failed',
        'actor_user_id' => $actorUserId > 0 ? $actorUserId : $rowUserId,
        'target_user_id' => $rowUserId,
        'call_id' => (string) ($row['call_id'] ?? ''),
        'resource_type' => 'call_access_account_update_confirmation',
        'resource_fingerprint' => (string) ($row['access_fingerprint'] ?? ''),
        'session_fingerprint' => $sessionFingerprint,
        'payload' => [
            'reason' => $reason,
            'token_logged' => false,
            'raw_link_identifier_logged' => false,
            'session_identifier_logged' => false,
        ],
    ]);
}

function videochat_call_access_confirm_account_update(PDO $pdo, string $token, int $authenticatedUserId = 0, array $options = []): array
{
    $trimmedToken = trim($token);
    if ($trimmedToken === '' || preg_match('/^cau_[A-Za-z0-9._-]{20,200}$/', $trimmedToken) !== 1) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['token' => 'required'],
            'user' => null,
            'consumed_at' => null,
        ];
    }
    $sessionFingerprint = videochat_call_access_account_confirmation_session_fingerprint((string) ($options['session_id'] ?? ''));
    $enforceSessionBinding = array_key_exists('session_id', $options);
    if (!videochat_call_access_account_confirmation_bootstrap($pdo)) {
        return [
            'ok' => false,
            'reason' => 'confirmation_unavailable',
            'errors' => [],
            'user' => null,
            'consumed_at' => null,
        ];
    }

    $query = $pdo->prepare(
        <<<'SQL'
SELECT id, token_fingerprint, tenant_id, call_id, access_fingerprint, user_id, requesting_session_fingerprint,
       pending_payload_json, expires_at, consumed_at, superseded_at, superseded_by_fingerprint
FROM call_access_account_update_confirmations
WHERE token_fingerprint = :token_fingerprint
LIMIT 1
SQL
    );
    $query->execute([':token_fingerprint' => videochat_call_access_account_confirmation_token_fingerprint($trimmedToken)]);
    $row = $query->fetch();
    if (!is_array($row)) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => ['token' => 'invalid_or_unknown'],
            'user' => null,
            'consumed_at' => null,
        ];
    }

    $userId = (int) ($row['user_id'] ?? 0);
    if ($authenticatedUserId > 0 && $authenticatedUserId !== $userId) {
        videochat_call_access_record_account_confirmation_failure($pdo, $row, 'account_bound', $authenticatedUserId, $sessionFingerprint);
        return [
            'ok' => false,
            'reason' => 'forbidden',
            'errors' => ['token' => 'account_bound'],
            'user' => null,
            'consumed_at' => null,
        ];
    }

    $requestingSessionFingerprint = is_string($row['requesting_session_fingerprint'] ?? null)
        ? trim((string) $row['requesting_session_fingerprint'])
        : '';
    if (
        $enforceSessionBinding
        && $requestingSessionFingerprint !== ''
        && ($sessionFingerprint === '' || !hash_equals($requestingSessionFingerprint, $sessionFingerprint))
    ) {
        videochat_call_access_record_account_confirmation_failure($pdo, $row, 'session_bound', $authenticatedUserId, $sessionFingerprint);
        return [
            'ok' => false,
            'reason' => 'forbidden',
            'errors' => ['token' => 'session_bound'],
            'user' => null,
            'consumed_at' => null,
        ];
    }

    $stateError = videochat_call_access_confirmation_consumed_error($pdo, $row, $authenticatedUserId, $sessionFingerprint);
    if (!(bool) ($stateError['ok'] ?? false)) {
        return $stateError;
    }

    $pendingPayload = json_decode((string) ($row['pending_payload_json'] ?? '{}'), true);
    if (!is_array($pendingPayload)) {
        $pendingPayload = [];
    }
    $displayName = trim((string) ($pendingPayload['display_name'] ?? ''));
    if ($userId <= 0 || $displayName === '') {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['token' => 'invalid_pending_payload'],
            'user' => null,
            'consumed_at' => null,
        ];
    }

    $startedTransaction = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTransaction = true;
    }

    try {
        $consumedAt = gmdate('c');
        $consume = $pdo->prepare(
            <<<'SQL'
UPDATE call_access_account_update_confirmations
SET consumed_at = :consumed_at
WHERE token_fingerprint = :token_fingerprint
  AND (consumed_at IS NULL OR trim(consumed_at) = '')
  AND (superseded_at IS NULL OR trim(superseded_at) = '')
  AND expires_at > :now
SQL
        );
        $consume->execute([
            ':consumed_at' => $consumedAt,
            ':token_fingerprint' => (string) ($row['token_fingerprint'] ?? ''),
            ':now' => gmdate('c'),
        ]);
        if ($consume->rowCount() !== 1) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $fresh = $pdo->prepare(
                <<<'SQL'
SELECT id, token_fingerprint, tenant_id, call_id, access_fingerprint, user_id, requesting_session_fingerprint,
       pending_payload_json, expires_at, consumed_at, superseded_at, superseded_by_fingerprint
FROM call_access_account_update_confirmations
WHERE token_fingerprint = :token_fingerprint
LIMIT 1
SQL
            );
            $fresh->execute([':token_fingerprint' => (string) ($row['token_fingerprint'] ?? '')]);
            $freshRow = $fresh->fetch();
            return videochat_call_access_confirmation_consumed_error(
                $pdo,
                is_array($freshRow) ? $freshRow : $row,
                $authenticatedUserId,
                $sessionFingerprint,
                true
            );
        }

        $updateUser = $pdo->prepare('UPDATE users SET display_name = :display_name, updated_at = :updated_at WHERE id = :id AND status = :status');
        $updateUser->execute([
            ':display_name' => $displayName,
            ':updated_at' => $consumedAt,
            ':id' => $userId,
            ':status' => 'active',
        ]);
        if ($updateUser->rowCount() !== 1) {
            throw new RuntimeException('confirmation_user_update_failed');
        }

        $user = videochat_fetch_user_auth_snapshot($pdo, $userId);
        if (!is_array($user) || (string) ($user['status'] ?? '') !== 'active') {
            throw new RuntimeException('confirmation_user_missing');
        }

        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }

        videochat_audit_record_event($pdo, [
            'tenant_id' => is_numeric($row['tenant_id'] ?? null) ? (int) $row['tenant_id'] : null,
            'event_type' => 'call_access_account_update_confirmed',
            'actor_user_id' => $userId,
            'target_user_id' => $userId,
            'call_id' => (string) ($row['call_id'] ?? ''),
            'resource_type' => 'call_access_account_update_confirmation',
            'resource_fingerprint' => (string) ($row['access_fingerprint'] ?? ''),
            'session_fingerprint' => $sessionFingerprint,
            'payload' => [
                'updated_fields' => ['display_name'],
                'token_logged' => false,
                'raw_link_identifier_logged' => false,
                'session_identifier_logged' => false,
            ],
        ]);

        return [
            'ok' => true,
            'reason' => 'confirmed',
            'errors' => [],
            'user' => $user,
            'consumed_at' => $consumedAt,
        ];
    } catch (Throwable) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [
            'ok' => false,
            'reason' => 'internal_error',
            'errors' => [],
            'user' => null,
            'consumed_at' => null,
        ];
    }
}

function videochat_call_access_confirmation_consumed_error(
    PDO $pdo,
    array $row,
    int $actorUserId,
    string $sessionFingerprint,
    bool $raceFallback = false
): array
{
    $existingConsumedAt = is_string($row['consumed_at'] ?? null) ? trim((string) $row['consumed_at']) : '';
    if ($existingConsumedAt !== '') {
        videochat_call_access_record_account_confirmation_failure($pdo, $row, 'already_consumed', $actorUserId, $sessionFingerprint);
        return [
            'ok' => false,
            'reason' => 'conflict',
            'errors' => ['token' => 'already_consumed'],
            'user' => null,
            'consumed_at' => null,
        ];
    }

    $supersededAt = is_string($row['superseded_at'] ?? null) ? trim((string) $row['superseded_at']) : '';
    if ($supersededAt !== '') {
        videochat_call_access_record_account_confirmation_failure($pdo, $row, 'superseded', $actorUserId, $sessionFingerprint);
        return [
            'ok' => false,
            'reason' => 'conflict',
            'errors' => ['token' => 'superseded'],
            'user' => null,
            'consumed_at' => null,
        ];
    }

    $expiresAtUnix = strtotime((string) ($row['expires_at'] ?? ''));
    if (!is_int($expiresAtUnix) || $expiresAtUnix <= time()) {
        videochat_call_access_record_account_confirmation_failure($pdo, $row, 'expired', $actorUserId, $sessionFingerprint);
        return [
            'ok' => false,
            'reason' => 'expired',
            'errors' => ['token' => 'expired'],
            'user' => null,
            'consumed_at' => null,
        ];
    }

    if (!$raceFallback) {
        return ['ok' => true, 'reason' => 'pending', 'errors' => [], 'user' => null, 'consumed_at' => null];
    }

    videochat_call_access_record_account_confirmation_failure($pdo, $row, 'consume_race', $actorUserId, $sessionFingerprint);
    return [
        'ok' => false,
        'reason' => 'conflict',
        'errors' => ['token' => 'confirmation_raced'],
        'user' => null,
        'consumed_at' => null,
    ];
}
