<?php

declare(strict_types=1);

require_once __DIR__ . '/../audit/audit_events.php';
require_once __DIR__ . '/../../support/auth_request.php';
require_once __DIR__ . '/../users/user_email_identity.php';
require_once __DIR__ . '/call_access_contract.php';

function videochat_call_access_account_confirmation_bootstrap(PDO $pdo): bool
{
    try {
        $pdo->exec(
            <<<'SQL'
CREATE TABLE IF NOT EXISTS call_access_account_update_confirmations (
    id TEXT PRIMARY KEY,
    tenant_id INTEGER,
    call_id TEXT NOT NULL DEFAULT '',
    access_fingerprint TEXT NOT NULL DEFAULT '',
    user_id INTEGER NOT NULL,
    recipient_email_fingerprint TEXT NOT NULL,
    pending_payload_json TEXT NOT NULL DEFAULT '{}',
    expires_at TEXT NOT NULL,
    consumed_at TEXT,
    created_at TEXT NOT NULL
)
SQL
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_call_access_account_confirm_user ON call_access_account_update_confirmations(user_id, created_at DESC)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_call_access_account_confirm_access ON call_access_account_update_confirmations(access_fingerprint, created_at DESC)');
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

function videochat_call_access_account_confirmation_ttl_seconds(): int
{
    $seconds = (int) (getenv('VIDEOCHAT_CALL_ACCESS_ACCOUNT_CONFIRMATION_TTL_SECONDS') ?: 3600);
    return max(300, min(86_400, $seconds));
}

function videochat_call_access_account_confirmation_normalize_origin(string $origin): string
{
    $candidate = trim($origin);
    if ($candidate === '') {
        return '';
    }
    if (!preg_match('#^https?://#i', $candidate)) {
        $candidate = 'https://' . $candidate;
    }

    $parts = parse_url($candidate);
    if (!is_array($parts)) {
        return '';
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower(trim((string) ($parts['host'] ?? '')));
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
        return '';
    }

    $origin = $scheme . '://' . $host;
    if (is_numeric($parts['port'] ?? null)) {
        $origin .= ':' . (int) $parts['port'];
    }

    return $origin;
}

function videochat_call_access_account_confirmation_is_loopback_host(string $host): bool
{
    $value = strtolower(trim($host, " \t\n\r\0\x0B[]"));
    return $value === 'localhost' || $value === '127.0.0.1' || $value === '::1';
}

function videochat_call_access_account_confirmation_is_secure_origin(string $origin): bool
{
    $parts = parse_url($origin);
    if (!is_array($parts)) {
        return false;
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if ($scheme === 'https') {
        return true;
    }
    if ($scheme !== 'http') {
        return false;
    }

    return videochat_call_access_account_confirmation_is_loopback_host((string) ($parts['host'] ?? ''));
}

function videochat_call_access_account_confirmation_frontend_origin(array $options = []): string
{
    $configured = is_string($options['frontend_origin'] ?? null) ? trim((string) $options['frontend_origin']) : '';
    $candidates = [
        $configured,
        (string) (getenv('VIDEOCHAT_CALL_ACCESS_ACCOUNT_CONFIRMATION_ORIGIN') ?: ''),
        (string) (getenv('VIDEOCHAT_FRONTEND_ORIGIN') ?: ''),
        'https://app.kingrt.com',
    ];

    foreach ($candidates as $candidate) {
        $origin = videochat_call_access_account_confirmation_normalize_origin($candidate);
        if ($origin !== '' && videochat_call_access_account_confirmation_is_secure_origin($origin)) {
            return $origin;
        }
    }

    return 'https://app.kingrt.com';
}

function videochat_build_call_access_account_confirmation_url(string $token, array $options = []): string
{
    $trimmedToken = trim($token);
    if ($trimmedToken === '') {
        return '';
    }

    return videochat_call_access_account_confirmation_frontend_origin($options)
        . '/account-update-confirmation?'
        . http_build_query([
            'call_access_account_update_confirmation_token' => $trimmedToken,
        ], '', '&', PHP_QUERY_RFC3986);
}

function videochat_call_access_account_confirmation_outbox_path(): string
{
    return trim((string) (getenv('VIDEOCHAT_EMAIL_OUTBOX_PATH') ?: (__DIR__ . '/../../.local/email-outbox.log')));
}

function videochat_call_access_account_confirmation_truthy_env(string $name): bool
{
    $value = strtolower(trim((string) (getenv($name) ?: '')));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

/**
 * @return array{sent: bool, channel: string}
 */
function videochat_send_call_access_account_update_confirmation_mail(
    string $recipientEmail,
    string $recipientName,
    string $confirmationUrl,
    string $expiresAt
): array {
    $to = strtolower(trim($recipientEmail));
    if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false || trim($confirmationUrl) === '') {
        return ['sent' => false, 'channel' => 'none'];
    }

    $displayName = trim($recipientName);
    if ($displayName === '') {
        $displayName = 'there';
    }
    $expiresAtText = trim($expiresAt) !== '' ? trim($expiresAt) : 'the recorded expiration time';

    $subject = 'Confirm your account update';
    $body = "Hello {$displayName},\n\n"
        . "Confirm your account update by opening this secure confirmation link:\n"
        . "{$confirmationUrl}\n\n"
        . "The link expires at {$expiresAtText} and can only be used once from your account.\n";
    $headers = "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "From: no-reply@intelligent-intern.local\r\n";

    $forceOutbox = videochat_call_access_account_confirmation_truthy_env('VIDEOCHAT_EMAIL_FORCE_OUTBOX')
        || videochat_call_access_account_confirmation_truthy_env('VIDEOCHAT_CALL_ACCESS_ACCOUNT_CONFIRMATION_FORCE_OUTBOX');
    if (!$forceOutbox && function_exists('mail')) {
        try {
            if (@mail($to, $subject, $body, $headers)) {
                return ['sent' => true, 'channel' => 'mail'];
            }
        } catch (Throwable) {
            // Fall through to the local outbox so confirmation remains inspectable.
        }
    }

    $outboxPath = videochat_call_access_account_confirmation_outbox_path();
    $outboxDir = dirname($outboxPath);
    if (!is_dir($outboxDir)) {
        @mkdir($outboxDir, 0775, true);
    }
    $entry = '[' . gmdate('c') . "] TO={$to}\nSUBJECT={$subject}\n{$body}\n---\n";
    @file_put_contents($outboxPath, $entry, FILE_APPEND | LOCK_EX);

    return ['sent' => false, 'channel' => 'outbox'];
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
    $createdAt = gmdate('c');
    $ttlSeconds = videochat_call_access_account_confirmation_ttl_seconds();
    $expiresAt = gmdate('c', time() + $ttlSeconds);
    $payloadJson = json_encode($pendingPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($payloadJson) || $payloadJson === '') {
        $payloadJson = '{}';
    }
    $recipientEmail = strtolower(trim((string) ($user['email'] ?? '')));
    $confirmationUrl = videochat_build_call_access_account_confirmation_url($token, $options);

    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO call_access_account_update_confirmations(
    id, tenant_id, call_id, access_fingerprint, user_id, recipient_email_fingerprint,
    pending_payload_json, expires_at, consumed_at, created_at
) VALUES(
    :id, :tenant_id, :call_id, :access_fingerprint, :user_id, :recipient_email_fingerprint,
    :pending_payload_json, :expires_at, NULL, :created_at
)
SQL
    );
    try {
        $insert->execute([
            ':id' => $token,
            ':tenant_id' => is_numeric($accessLink['tenant_id'] ?? null) ? (int) $accessLink['tenant_id'] : null,
            ':call_id' => (string) ($accessLink['call_id'] ?? ''),
            ':access_fingerprint' => videochat_audit_fingerprint($normalizedAccessId),
            ':user_id' => $authenticatedUserId,
            ':recipient_email_fingerprint' => videochat_audit_fingerprint($recipientEmail),
            ':pending_payload_json' => $payloadJson,
            ':expires_at' => $expiresAt,
            ':created_at' => $createdAt,
        ]);
    } catch (Throwable) {
        return [
            'ok' => false,
            'reason' => 'internal_error',
            'errors' => [],
            'token' => null,
            'recipient_email' => null,
        ];
    }

    $delivery = videochat_send_call_access_account_update_confirmation_mail(
        $recipientEmail,
        (string) ($user['display_name'] ?? ''),
        $confirmationUrl,
        $expiresAt
    );

    videochat_audit_record_event($pdo, [
        'tenant_id' => is_numeric($accessLink['tenant_id'] ?? null) ? (int) $accessLink['tenant_id'] : null,
        'event_type' => 'call_access_account_update_confirmation_requested',
        'actor_user_id' => $authenticatedUserId,
        'target_user_id' => $authenticatedUserId,
        'call_id' => (string) ($accessLink['call_id'] ?? ''),
        'resource_type' => 'call_access_account_update_confirmation',
        'resource_fingerprint' => videochat_audit_fingerprint($normalizedAccessId),
        'session_fingerprint' => videochat_audit_fingerprint((string) ($options['session_id'] ?? '')),
        'payload' => [
            'manual_reentry_required' => true,
            'sent_to_logged_in_account' => true,
            'sent_to_link_account' => false,
            'pending_fields' => array_keys($pendingPayload),
            'secure_confirmation_link_sent' => videochat_call_access_account_confirmation_is_secure_origin(
                videochat_call_access_account_confirmation_frontend_origin($options)
            ),
            'expires_at' => $expiresAt,
            'raw_link_identifier_logged' => false,
            'recipient_email_logged' => false,
            'confirmation_token_logged' => false,
        ],
    ]);

    return [
        'ok' => true,
        'reason' => 'pending_confirmation',
        'errors' => [],
        'token' => $token,
        'expires_at' => $expiresAt,
        'expires_in_seconds' => $ttlSeconds,
        'confirmation_url' => $confirmationUrl,
        'recipient_email' => $recipientEmail,
        'recipient_user_id' => $authenticatedUserId,
        'sent_to_logged_in_account' => true,
        'sent_to_link_account' => false,
        'email_delivery' => $delivery,
    ];
}

function videochat_call_access_confirm_account_update(PDO $pdo, string $token, int $authenticatedUserId = 0): array
{
    $trimmedToken = trim($token);
    if ($trimmedToken === '') {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['token' => 'required'],
            'user' => null,
            'consumed_at' => null,
        ];
    }
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
SELECT id, tenant_id, call_id, access_fingerprint, user_id, pending_payload_json, expires_at, consumed_at
FROM call_access_account_update_confirmations
WHERE id = :id
LIMIT 1
SQL
    );
    $query->execute([':id' => $trimmedToken]);
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
        return [
            'ok' => false,
            'reason' => 'forbidden',
            'errors' => ['token' => 'account_bound'],
            'user' => null,
            'consumed_at' => null,
        ];
    }

    $existingConsumedAt = is_string($row['consumed_at'] ?? null) ? trim((string) $row['consumed_at']) : '';
    if ($existingConsumedAt !== '') {
        return [
            'ok' => false,
            'reason' => 'conflict',
            'errors' => ['token' => 'already_consumed'],
            'user' => null,
            'consumed_at' => null,
        ];
    }

    $expiresAtUnix = strtotime((string) ($row['expires_at'] ?? ''));
    if (!is_int($expiresAtUnix) || $expiresAtUnix <= time()) {
        return [
            'ok' => false,
            'reason' => 'expired',
            'errors' => ['token' => 'expired'],
            'user' => null,
            'consumed_at' => null,
        ];
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
            'UPDATE call_access_account_update_confirmations SET consumed_at = :consumed_at WHERE id = :id AND (consumed_at IS NULL OR trim(consumed_at) = \'\')'
        );
        $consume->execute([
            ':consumed_at' => $consumedAt,
            ':id' => $trimmedToken,
        ]);
        if ($consume->rowCount() !== 1) {
            throw new RuntimeException('confirmation_already_consumed');
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
        if (!is_array($user)) {
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
            'session_fingerprint' => '',
            'payload' => [
                'updated_fields' => ['display_name'],
                'token_logged' => false,
                'raw_link_identifier_logged' => false,
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
