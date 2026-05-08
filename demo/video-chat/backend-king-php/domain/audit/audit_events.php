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

function videochat_audit_record_call_created(PDO $pdo, array $call, int $actorUserId, array $context = []): array
{
    $callId = trim((string) ($call['id'] ?? ''));
    $owner = is_array($call['owner'] ?? null) ? $call['owner'] : [];
    $participants = is_array($call['participants'] ?? null) ? $call['participants'] : [];
    $totals = is_array($participants['totals'] ?? null) ? $participants['totals'] : [];
    return videochat_audit_record_event($pdo, [
        'tenant_id' => is_numeric($call['tenant_id'] ?? null) ? (int) $call['tenant_id'] : null,
        'event_type' => 'call_created',
        'actor_user_id' => $actorUserId > 0 ? $actorUserId : null,
        'target_user_id' => is_numeric($owner['user_id'] ?? null) ? (int) $owner['user_id'] : null,
        'call_id' => $callId,
        'resource_type' => 'call',
        'resource_id' => $callId,
        'resource_fingerprint' => videochat_audit_fingerprint($callId),
        'payload' => [
            'audit_scope' => 'iam_call_lifecycle',
            'action' => 'create_call',
            'access_mode' => strtolower(trim((string) ($call['access_mode'] ?? 'invite_only'))) ?: 'invite_only',
            'call_status' => strtolower(trim((string) ($call['status'] ?? 'scheduled'))) ?: 'scheduled',
            'internal_participant_count' => max(0, (int) (($totals['internal'] ?? null) ?: 0)),
            'external_participant_count' => max(0, (int) (($totals['external'] ?? null) ?: 0)),
            'title_logged' => false,
            'raw_guest_identifiers_logged' => false,
        ] + videochat_audit_sanitize_payload($context),
    ]);
}

function videochat_audit_record_call_access_invitation_created(PDO $pdo, array $accessLink, array $call, ?int $actorUserId = null, ?array $targetUser = null, array $context = []): array
{
    $accessId = trim((string) ($accessLink['id'] ?? ''));
    return videochat_audit_record_event($pdo, [
        'tenant_id' => is_numeric($accessLink['tenant_id'] ?? null) ? (int) $accessLink['tenant_id'] : null,
        'event_type' => 'call_access_invitation_created',
        'actor_user_id' => $actorUserId,
        'target_user_id' => is_array($targetUser) && is_numeric($targetUser['id'] ?? null) ? (int) $targetUser['id'] : null,
        'call_id' => (string) ($call['id'] ?? ($accessLink['call_id'] ?? '')),
        'resource_type' => 'call_access_link',
        'resource_fingerprint' => videochat_audit_fingerprint($accessId),
        'payload' => [
            'audit_scope' => 'iam_call_access',
            'action' => 'create_invitation',
            'link_kind' => function_exists('videochat_call_access_link_kind') ? videochat_call_access_link_kind($accessLink) : 'unknown',
            'call_status' => strtolower(trim((string) ($call['status'] ?? ''))) ?: 'unknown',
            'target_user_resolved' => is_array($targetUser),
            'raw_link_identifier_logged' => false,
            'raw_guest_identity_logged' => false,
        ] + videochat_audit_sanitize_payload($context),
    ]);
}

function videochat_audit_record_temporary_account_created(PDO $pdo, array $user, ?int $tenantId, array $context = []): array
{
    $userId = is_numeric($user['id'] ?? null) ? (int) $user['id'] : 0;
    return videochat_audit_record_event($pdo, [
        'tenant_id' => is_int($tenantId) && $tenantId > 0 ? $tenantId : null,
        'event_type' => 'temporary_account_created',
        'target_user_id' => $userId > 0 ? $userId : null,
        'call_id' => trim((string) ($context['call_id'] ?? '')),
        'resource_type' => 'temporary_call_account',
        'resource_fingerprint' => videochat_audit_fingerprint('temporary-account:' . $userId),
        'payload' => [
            'audit_scope' => 'iam_call_access',
            'action' => 'create_temporary_account',
            'account_type' => 'guest',
            'source' => strtolower(trim((string) ($context['source'] ?? 'call_access'))) ?: 'call_access',
            'tenant_membership_attached' => (bool) ($context['tenant_membership_attached'] ?? false),
            'raw_guest_identity_logged' => false,
            'raw_link_identifier_logged' => false,
        ],
    ]);
}

function videochat_audit_record_call_access_account_compared(PDO $pdo, array $accessLink, array $call, ?array $targetUser, int $actorUserId, string $outcome, array $context = []): array
{
    $sessionId = trim((string) ($context['session_id'] ?? ''));
    return videochat_audit_record_event($pdo, [
        'tenant_id' => is_numeric($accessLink['tenant_id'] ?? null) ? (int) $accessLink['tenant_id'] : null,
        'event_type' => 'call_access_account_compared',
        'actor_user_id' => $actorUserId > 0 ? $actorUserId : null,
        'target_user_id' => is_array($targetUser) && is_numeric($targetUser['id'] ?? null) ? (int) $targetUser['id'] : null,
        'call_id' => (string) ($call['id'] ?? ($accessLink['call_id'] ?? '')),
        'resource_type' => 'call_access_link',
        'resource_fingerprint' => videochat_audit_fingerprint((string) ($accessLink['id'] ?? '')),
        'session_fingerprint' => $sessionId === '' ? '' : videochat_audit_fingerprint($sessionId),
        'payload' => [
            'audit_scope' => 'iam_call_access',
            'action' => 'compare_link_account_to_session',
            'comparison_outcome' => strtolower(trim($outcome)) ?: 'unknown',
            'stage' => strtolower(trim((string) ($context['stage'] ?? 'session_issue'))) ?: 'session_issue',
            'link_kind' => function_exists('videochat_call_access_link_kind') ? videochat_call_access_link_kind($accessLink) : 'unknown',
            'target_user_resolved' => is_array($targetUser),
            'host_name_verified' => (bool) ($context['host_name_verified'] ?? false),
            'raw_link_identifier_logged' => false,
            'raw_credential_identifier_logged' => false,
            'foreign_account_data_logged' => false,
        ],
    ]);
}

function videochat_audit_record_call_access_host_verification(PDO $pdo, array $accessLink, array $call, int $actorUserId, string $outcome, array $context = []): array
{
    $normalized = strtolower(trim($outcome));
    $eventType = $normalized === 'correct_host_name'
        ? 'call_access_host_name_verified'
        : ($normalized === 'rate_limited' ? 'call_access_host_name_verification_rate_limited' : 'call_access_host_name_verification_failed');
    return videochat_audit_record_event($pdo, [
        'tenant_id' => is_numeric($accessLink['tenant_id'] ?? null) ? (int) $accessLink['tenant_id'] : null,
        'event_type' => $eventType,
        'actor_user_id' => $actorUserId > 0 ? $actorUserId : null,
        'call_id' => (string) ($call['id'] ?? ($accessLink['call_id'] ?? '')),
        'resource_type' => 'call_access_link',
        'resource_fingerprint' => videochat_audit_fingerprint((string) ($accessLink['id'] ?? '')),
        'payload' => [
            'audit_scope' => 'iam_call_access',
            'action' => 'verify_host_name',
            'outcome' => $normalized ?: 'wrong_host_name',
            'host_name_logged' => false,
            'raw_link_identifier_logged' => false,
            'raw_credential_identifier_logged' => false,
        ] + videochat_audit_sanitize_payload($context),
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

function videochat_audit_record_call_access_invitation_invalidated(
    PDO $pdo,
    array $accessLink,
    array $call = [],
    ?array $targetUser = null,
    ?int $actorUserId = null,
    array $context = []
): array {
    $accessId = trim((string) ($accessLink['id'] ?? ''));
    $sessionId = trim((string) ($context['session_id'] ?? ''));
    $inviteState = strtolower(trim((string) ($context['invite_state'] ?? 'cancelled')));
    if (!in_array($inviteState, ['cancelled', 'declined'], true)) {
        $inviteState = 'cancelled';
    }
    $reason = strtolower(trim((string) ($context['invalidation_reason'] ?? 'participant_invite_cancelled')));
    if ($reason === '' || preg_match('/^[a-z0-9_.:-]{1,120}$/', $reason) !== 1) {
        $reason = 'participant_invite_cancelled';
    }

    return videochat_audit_record_event($pdo, [
        'tenant_id' => is_numeric($accessLink['tenant_id'] ?? null) ? (int) $accessLink['tenant_id'] : null,
        'event_type' => 'call_access_invitation_invalidated',
        'actor_user_id' => $actorUserId,
        'target_user_id' => is_array($targetUser) && is_numeric($targetUser['id'] ?? null) ? (int) $targetUser['id'] : null,
        'call_id' => (string) ($call['id'] ?? ($accessLink['call_id'] ?? '')),
        'resource_type' => 'call_access_link',
        'resource_fingerprint' => videochat_audit_fingerprint($accessId),
        'session_fingerprint' => $sessionId === '' ? '' : videochat_audit_fingerprint($sessionId),
        'payload' => [
            'audit_scope' => 'iam_call_access',
            'action' => 'invalidate_invitation',
            'invalidation_reason' => $reason,
            'invite_state' => $inviteState,
            'link_kind' => function_exists('videochat_call_access_link_kind') ? videochat_call_access_link_kind($accessLink) : 'unknown',
            'call_status' => strtolower(trim((string) ($call['status'] ?? ''))) ?: 'unknown',
            'target_user_resolved' => is_array($targetUser),
            'had_effect' => (bool) ($context['had_effect'] ?? true),
            'access_session_count' => max(0, (int) ($context['access_session_count'] ?? 0)),
            'raw_link_identifier_logged' => false,
            'raw_credential_identifier_logged' => false,
            'raw_guest_identity_logged' => false,
        ],
    ]);
}

function videochat_audit_record_call_access_link_disabled(
    PDO $pdo,
    array $accessLink,
    array $call = [],
    ?int $actorUserId = null,
    array $context = []
): array {
    $accessId = trim((string) ($accessLink['id'] ?? ''));
    $reason = strtolower(trim((string) ($context['invalidation_reason'] ?? 'anonymous_link_disabled')));
    if ($reason === '' || preg_match('/^[a-z0-9_.:-]{1,120}$/', $reason) !== 1) {
        $reason = 'anonymous_link_disabled';
    }

    return videochat_audit_record_event($pdo, [
        'tenant_id' => is_numeric($accessLink['tenant_id'] ?? null) ? (int) $accessLink['tenant_id'] : null,
        'event_type' => 'call_access_link_disabled',
        'actor_user_id' => $actorUserId,
        'call_id' => (string) ($call['id'] ?? ($accessLink['call_id'] ?? '')),
        'resource_type' => 'call_access_link',
        'resource_fingerprint' => videochat_audit_fingerprint($accessId),
        'payload' => [
            'audit_scope' => 'iam_call_access',
            'action' => 'disable_anonymous_link',
            'invalidation_reason' => $reason,
            'link_kind' => function_exists('videochat_call_access_link_kind') ? videochat_call_access_link_kind($accessLink) : 'unknown',
            'call_status' => strtolower(trim((string) ($call['status'] ?? ''))) ?: 'unknown',
            'had_effect' => (bool) ($context['had_effect'] ?? true),
            'access_session_count' => max(0, (int) ($context['access_session_count'] ?? 0)),
            'raw_link_identifier_logged' => false,
            'raw_credential_identifier_logged' => false,
            'raw_guest_identity_logged' => false,
        ],
    ]);
}

function videochat_audit_record_call_participant_presence(
    PDO $pdo,
    string $eventType,
    int $tenantId,
    string $callId,
    int $targetUserId,
    ?int $actorUserId = null,
    array $context = []
): array {
    $normalizedEventType = in_array($eventType, [
        'call_participant_joined',
        'call_participant_rejoined',
        'call_participant_left',
    ], true) ? $eventType : 'call_participant_joined';
    $sessionId = trim((string) ($context['session_id'] ?? ''));
    $roomId = trim((string) ($context['room_id'] ?? ''));

    return videochat_audit_record_event($pdo, [
        'tenant_id' => $tenantId,
        'event_type' => $normalizedEventType,
        'actor_user_id' => $actorUserId,
        'target_user_id' => $targetUserId,
        'call_id' => $callId,
        'resource_type' => 'call_participant',
        'resource_id' => (string) $targetUserId,
        'resource_fingerprint' => videochat_audit_fingerprint($callId . ':' . $targetUserId),
        'session_fingerprint' => $sessionId === '' ? '' : videochat_audit_fingerprint($sessionId),
        'payload' => [
            'audit_scope' => 'iam_call_participant',
            'action' => str_replace('call_participant_', '', $normalizedEventType),
            'call_role' => strtolower(trim((string) ($context['call_role'] ?? 'participant'))) ?: 'participant',
            'room_fingerprint' => $roomId === '' ? '' : videochat_audit_fingerprint($roomId),
            'presence_reason' => strtolower(trim((string) ($context['reason'] ?? 'manual_proof'))) ?: 'manual_proof',
            'rejoin' => $normalizedEventType === 'call_participant_rejoined',
            'raw_credential_identifier_logged' => false,
        ],
    ]);
}

function videochat_audit_record_call_participant_joined(
    PDO $pdo,
    int $tenantId,
    string $callId,
    int $targetUserId,
    ?int $actorUserId = null,
    array $context = []
): array {
    return videochat_audit_record_call_participant_presence(
        $pdo,
        'call_participant_joined',
        $tenantId,
        $callId,
        $targetUserId,
        $actorUserId,
        $context
    );
}

function videochat_audit_record_call_participant_rejoined(
    PDO $pdo,
    int $tenantId,
    string $callId,
    int $targetUserId,
    ?int $actorUserId = null,
    array $context = []
): array {
    return videochat_audit_record_call_participant_presence(
        $pdo,
        'call_participant_rejoined',
        $tenantId,
        $callId,
        $targetUserId,
        $actorUserId,
        $context
    );
}

function videochat_audit_record_call_participant_left(
    PDO $pdo,
    int $tenantId,
    string $callId,
    int $targetUserId,
    ?int $actorUserId = null,
    array $context = []
): array {
    return videochat_audit_record_call_participant_presence(
        $pdo,
        'call_participant_left',
        $tenantId,
        $callId,
        $targetUserId,
        $actorUserId,
        $context
    );
}

function videochat_audit_record_call_participant_kicked(
    PDO $pdo,
    int $tenantId,
    string $callId,
    int $actorUserId,
    int $targetUserId,
    array $context = []
): array {
    $sessionId = trim((string) ($context['session_id'] ?? ''));
    $roomId = trim((string) ($context['room_id'] ?? ''));

    return videochat_audit_record_event($pdo, [
        'tenant_id' => $tenantId,
        'event_type' => 'call_participant_kicked',
        'actor_user_id' => $actorUserId,
        'target_user_id' => $targetUserId,
        'call_id' => $callId,
        'resource_type' => 'call_participant',
        'resource_id' => (string) $targetUserId,
        'resource_fingerprint' => videochat_audit_fingerprint($callId . ':' . $targetUserId),
        'session_fingerprint' => $sessionId === '' ? '' : videochat_audit_fingerprint($sessionId),
        'payload' => [
            'audit_scope' => 'iam_call_moderation',
            'action' => 'kick',
            'lobby_action' => strtolower(trim((string) ($context['lobby_action'] ?? 'lobby/remove'))) ?: 'lobby/remove',
            'previous_state' => strtolower(trim((string) ($context['previous_state'] ?? 'admitted'))) ?: 'admitted',
            'room_fingerprint' => $roomId === '' ? '' : videochat_audit_fingerprint($roomId),
            'raw_credential_identifier_logged' => false,
        ],
    ]);
}

function videochat_audit_record_call_owner_transferred(
    PDO $pdo,
    int $tenantId,
    string $callId,
    int $actorUserId,
    int $previousOwnerUserId,
    int $nextOwnerUserId,
    array $context = []
): array {
    return videochat_audit_record_event($pdo, [
        'tenant_id' => $tenantId,
        'event_type' => 'call_owner_transferred',
        'actor_user_id' => $actorUserId,
        'target_user_id' => $nextOwnerUserId,
        'call_id' => $callId,
        'resource_type' => 'call_owner',
        'resource_id' => $callId,
        'resource_fingerprint' => videochat_audit_fingerprint($callId),
        'payload' => [
            'audit_scope' => 'iam_owner_transfer',
            'previous_owner_user_id' => $previousOwnerUserId,
            'new_owner_user_id' => $nextOwnerUserId,
            'actor_role' => strtolower(trim((string) ($context['actor_role'] ?? 'user'))) ?: 'user',
            'exactly_one_owner_required' => true,
            'old_owner_admin_preserved' => (bool) ($context['old_owner_admin_preserved'] ?? false),
            'raw_credential_identifier_logged' => false,
        ],
    ]);
}

function videochat_audit_record_call_access_strong_mismatch(
    PDO $pdo,
    array $accessLink,
    array $call,
    ?array $targetUser,
    int $actorUserId,
    string $stage,
    array $context = []
): array {
    $accessId = trim((string) ($accessLink['id'] ?? ''));
    $sessionId = trim((string) ($context['session_id'] ?? ''));
    $targetUserId = is_array($targetUser) && is_numeric($targetUser['id'] ?? null) ? (int) $targetUser['id'] : null;

    return videochat_audit_record_event($pdo, [
        'tenant_id' => is_numeric($accessLink['tenant_id'] ?? null) ? (int) $accessLink['tenant_id'] : null,
        'event_type' => 'call_access_strong_mismatch_denied',
        'actor_user_id' => $actorUserId,
        'target_user_id' => $targetUserId,
        'call_id' => (string) ($call['id'] ?? ($accessLink['call_id'] ?? '')),
        'resource_type' => 'call_access_link',
        'resource_fingerprint' => videochat_audit_fingerprint($accessId),
        'session_fingerprint' => $sessionId === '' ? '' : videochat_audit_fingerprint($sessionId),
        'payload' => [
            'audit_scope' => 'iam_call_access',
            'mismatch' => 'strong_personalized_link',
            'stage' => strtolower(trim($stage)) ?: 'unknown',
            'link_kind' => function_exists('videochat_call_access_link_kind') ? videochat_call_access_link_kind($accessLink) : 'unknown',
            'denial_reason' => strtolower(trim((string) ($context['denial_reason'] ?? 'not_bound_to_current_user'))) ?: 'not_bound_to_current_user',
            'host_name_verified' => (bool) ($context['host_name_verified'] ?? false),
            'host_name_logged' => false,
            'foreign_account_data_logged' => false,
            'raw_link_identifier_logged' => false,
            'raw_credential_identifier_logged' => false,
        ],
    ]);
}

function videochat_audit_record_guest_list_entry_change(
    PDO $pdo,
    array $call,
    int $targetUserId,
    ?int $actorUserId,
    string $action,
    array $context = []
): array {
    $normalizedAction = strtolower(trim($action));
    if (!in_array($normalizedAction, ['added', 'merged', 'restored', 'removed'], true)) {
        $normalizedAction = 'merged';
    }

    $callId = trim((string) ($call['id'] ?? ''));
    $tenantId = is_numeric($call['tenant_id'] ?? null) && (int) $call['tenant_id'] > 0
        ? (int) $call['tenant_id']
        : null;

    return videochat_audit_record_event($pdo, [
        'tenant_id' => $tenantId,
        'event_type' => 'guest_list_entry_' . $normalizedAction,
        'actor_user_id' => $actorUserId,
        'target_user_id' => $targetUserId,
        'call_id' => $callId,
        'resource_type' => 'call_guest_list_entry',
        'resource_id' => (string) $targetUserId,
        'resource_fingerprint' => videochat_audit_fingerprint($callId . ':' . $targetUserId),
        'payload' => [
            'audit_scope' => 'iam_guest_list',
            'action' => $normalizedAction,
            'call_role' => strtolower(trim((string) ($context['call_role'] ?? 'participant'))) ?: 'participant',
            'invite_state' => strtolower(trim((string) ($context['invite_state'] ?? 'invited'))) ?: 'invited',
            'had_prior_entry' => (bool) ($context['had_prior_entry'] ?? false),
            'call_scoped' => true,
            'raw_guest_identifiers_logged' => false,
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
