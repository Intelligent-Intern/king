<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/audit/audit_events.php';

$label = 'audit-call-access-privacy-minimization-contract';

function videochat_audit_privacy_assert(bool $condition, string $message): void
{
    global $label;
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[{$label}] FAIL: {$message}\n");
    exit(1);
}

final class VideochatAuditPrivacyMemoryPdo extends PDO
{
    /** @var array<int, array<string, mixed>> */
    public static array $executions = [];

    public function __construct()
    {
    }

    public function getAttribute(int $attribute): mixed
    {
        if ($attribute === PDO::ATTR_DRIVER_NAME) {
            return 'sqlite';
        }

        return null;
    }

    public function exec(string $statement): int|false
    {
        return 0;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new VideochatAuditPrivacyMemoryStatement();
    }
}

final class VideochatAuditPrivacyMemoryStatement extends PDOStatement
{
    public function execute(?array $params = null): bool
    {
        VideochatAuditPrivacyMemoryPdo::$executions[] = $params ?? [];
        return true;
    }
}

function videochat_audit_privacy_assert_no_raw_text(string $encoded, array $forbiddenValues, string $context): void
{
    foreach ($forbiddenValues as $value) {
        $needle = trim((string) $value);
        if ($needle === '') {
            continue;
        }
        videochat_audit_privacy_assert(!str_contains($encoded, $needle), "{$context} leaked raw value: {$needle}");
    }
}

try {
    $pdo = new VideochatAuditPrivacyMemoryPdo();
    $accessId = 'privacy-access-raw-id';
    $sessionId = 'sess_privacy_raw_session';
    $deniedSessionId = 'sess_privacy_denied_should_not_issue';
    $hostName = 'Private Foreign Host Person';
    $targetEmail = 'foreign-link-invitee@example.invalid';
    $targetName = 'Foreign Link Invitee Person';
    $callTitle = 'Private Foreign Link Call';
    $rawToken = 'token_privacy_should_not_log';
    $rawSdp = 'v=0 raw-sdp-privacy';
    $rawIce = 'candidate:raw-ice-privacy';

    $accessLink = [
        'id' => $accessId,
        'tenant_id' => 17,
        'call_id' => 'privacy-call-id',
        'link_kind' => 'personal',
    ];
    $call = [
        'id' => 'privacy-call-id',
        'title' => $callTitle,
        'status' => 'active',
    ];
    $targetUser = [
        'id' => 23,
        'email' => $targetEmail,
        'display_name' => $targetName,
    ];

    $mismatchAudit = videochat_audit_record_call_access_strong_mismatch(
        $pdo,
        $accessLink,
        $call,
        $targetUser,
        41,
        'session_host_verification',
        [
            'session_id' => $sessionId,
            'denial_reason' => 'wrong_host_name',
            'host_name' => $hostName,
            'host_name_verified' => false,
            'denied_session_id' => $deniedSessionId,
            'token' => $rawToken,
            'sdp' => $rawSdp,
            'ice_candidate' => $rawIce,
        ]
    );
    videochat_audit_privacy_assert((bool) ($mismatchAudit['ok'] ?? false), 'strong mismatch audit should be recorded');
    $event = (array) ($mismatchAudit['event'] ?? []);
    $payload = (array) ($event['payload'] ?? []);
    videochat_audit_privacy_assert((string) ($event['event_type'] ?? '') === 'call_access_strong_mismatch_denied', 'strong mismatch event type mismatch');
    videochat_audit_privacy_assert((string) ($event['resource_id'] ?? '') === '', 'raw access id must not be stored as resource id');
    videochat_audit_privacy_assert((string) ($event['resource_fingerprint'] ?? '') === videochat_audit_fingerprint($accessId), 'access id fingerprint mismatch');
    videochat_audit_privacy_assert((string) ($event['session_fingerprint'] ?? '') === videochat_audit_fingerprint($sessionId), 'session id fingerprint mismatch');
    videochat_audit_privacy_assert((bool) ($payload['host_name_logged'] ?? true) === false, 'host name must be explicitly omitted');
    videochat_audit_privacy_assert((bool) ($payload['foreign_account_data_logged'] ?? true) === false, 'foreign account data must be explicitly omitted');
    videochat_audit_privacy_assert((bool) ($payload['raw_link_identifier_logged'] ?? true) === false, 'raw link id must be explicitly omitted');
    videochat_audit_privacy_assert((bool) ($payload['raw_credential_identifier_logged'] ?? true) === false, 'raw credential id must be explicitly omitted');

    $encodedMismatch = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    videochat_audit_privacy_assert(is_string($encodedMismatch), 'strong mismatch event should encode');
    videochat_audit_privacy_assert_no_raw_text($encodedMismatch, [
        $accessId,
        $sessionId,
        $deniedSessionId,
        $hostName,
        $targetEmail,
        $targetName,
        $callTitle,
        $rawToken,
        $rawSdp,
        $rawIce,
    ], 'strong mismatch audit');

    $invalidationAudit = videochat_audit_record_call_access_invitation_invalidated(
        $pdo,
        $accessLink,
        $call,
        $targetUser,
        41,
        [
            'session_id' => $sessionId,
            'invalidation_reason' => 'privacy_contract_cancelled',
            'invite_state' => 'cancelled',
            'access_session_count' => 1,
            'guest_email' => $targetEmail,
            'guest_name' => $targetName,
        ]
    );
    videochat_audit_privacy_assert((bool) ($invalidationAudit['ok'] ?? false), 'invite invalidation audit should be recorded');
    $invalidationEvent = (array) ($invalidationAudit['event'] ?? []);
    $invalidationPayload = (array) ($invalidationEvent['payload'] ?? []);
    videochat_audit_privacy_assert((string) ($invalidationEvent['resource_id'] ?? '') === '', 'invite invalidation must omit raw access id resource id');
    videochat_audit_privacy_assert((string) ($invalidationEvent['resource_fingerprint'] ?? '') === videochat_audit_fingerprint($accessId), 'invite invalidation access fingerprint mismatch');
    videochat_audit_privacy_assert((string) ($invalidationEvent['session_fingerprint'] ?? '') === videochat_audit_fingerprint($sessionId), 'invite invalidation session fingerprint mismatch');
    videochat_audit_privacy_assert((bool) ($invalidationPayload['raw_link_identifier_logged'] ?? true) === false, 'invite invalidation must omit raw link id');
    videochat_audit_privacy_assert((bool) ($invalidationPayload['raw_credential_identifier_logged'] ?? true) === false, 'invite invalidation must omit raw credential data');
    videochat_audit_privacy_assert((bool) ($invalidationPayload['raw_guest_identity_logged'] ?? true) === false, 'invite invalidation must omit raw guest identity');
    videochat_audit_privacy_assert((int) ($invalidationPayload['access_session_count'] ?? 0) === 1, 'safe access session count should remain');

    $encodedInvalidation = json_encode($invalidationEvent, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    videochat_audit_privacy_assert(is_string($encodedInvalidation), 'invite invalidation event should encode');
    videochat_audit_privacy_assert_no_raw_text($encodedInvalidation, [
        $accessId,
        $sessionId,
        $targetEmail,
        $targetName,
        $callTitle,
    ], 'invite invalidation audit');

    $sanitized = videochat_audit_sanitize_payload([
        'access_id' => $accessId,
        'session_id' => $sessionId,
        'token' => $rawToken,
        'password' => 'raw-password',
        'sdp' => $rawSdp,
        'ice_candidate' => $rawIce,
        'access_session_count' => 2,
        'nested' => [
            'raw_token' => $rawToken,
            'media_track_count' => 3,
        ],
    ]);
    videochat_audit_privacy_assert(is_array($sanitized), 'sanitized payload should be an array');
    foreach (['access_id', 'session_id', 'token', 'password', 'sdp', 'ice_candidate'] as $key) {
        videochat_audit_privacy_assert(!array_key_exists($key, $sanitized), "sanitizer must remove {$key}");
    }
    videochat_audit_privacy_assert((int) ($sanitized['access_session_count'] ?? 0) === 2, 'sanitizer should keep safe count fields');
    videochat_audit_privacy_assert((int) (($sanitized['nested'] ?? [])['media_track_count'] ?? 0) === 3, 'sanitizer should keep safe media count fields');
    videochat_audit_privacy_assert(!array_key_exists('raw_token', (array) ($sanitized['nested'] ?? [])), 'sanitizer must remove nested raw token');

    $encodedExecutions = json_encode(VideochatAuditPrivacyMemoryPdo::$executions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    videochat_audit_privacy_assert(is_string($encodedExecutions), 'captured audit insert parameters should encode');
    videochat_audit_privacy_assert(str_contains($encodedExecutions, videochat_audit_fingerprint($accessId)), 'captured audit insert should retain access fingerprint');
    videochat_audit_privacy_assert(str_contains($encodedExecutions, videochat_audit_fingerprint($sessionId)), 'captured audit insert should retain session fingerprint');
    videochat_audit_privacy_assert_no_raw_text($encodedExecutions, [
        $accessId,
        $sessionId,
        $deniedSessionId,
        $hostName,
        $targetEmail,
        $targetName,
        $callTitle,
        $rawToken,
        $rawSdp,
        $rawIce,
    ], 'captured audit insert');

    fwrite(STDOUT, "[{$label}] PASS e2e_privacy_006_audit_logs_minimize_sensitive_data\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[{$label}] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
