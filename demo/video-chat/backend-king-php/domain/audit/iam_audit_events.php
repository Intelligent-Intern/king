<?php

declare(strict_types=1);

function videochat_audit_event_already_recorded(PDO $pdo, string $eventType, string $callId, string $resourceFingerprint): bool
{
    $normalizedType = videochat_audit_canonical_iam_event_type($eventType);
    $normalizedCallId = trim($callId);
    $normalizedFingerprint = trim($resourceFingerprint);
    if ($normalizedType === '' || $normalizedCallId === '' || $normalizedFingerprint === '') {
        return false;
    }
    if (!videochat_audit_bootstrap($pdo)) {
        return false;
    }

    try {
        $query = $pdo->prepare(
            <<<'SQL'
SELECT COUNT(*)
FROM videochat_audit_events
WHERE event_type = :event_type
  AND call_id = :call_id
  AND resource_fingerprint = :resource_fingerprint
SQL
        );
        $query->execute([
            ':event_type' => $normalizedType,
            ':call_id' => $normalizedCallId,
            ':resource_fingerprint' => $normalizedFingerprint,
        ]);

        return (int) $query->fetchColumn() > 0;
    } catch (Throwable) {
        return false;
    }
}

function videochat_audit_owner_absence_resource_fingerprint(array $snapshot): string
{
    $callId = trim((string) ($snapshot['call_id'] ?? ''));
    if ($callId === '') {
        return '';
    }

    $absentSinceMs = (int) ($snapshot['absent_since_ms'] ?? 0);
    if ($absentSinceMs <= 0) {
        $absentSinceUnix = strtotime((string) ($snapshot['absent_since'] ?? ''));
        if (is_int($absentSinceUnix) && $absentSinceUnix > 0) {
            $absentSinceMs = $absentSinceUnix * 1000;
        }
    }

    return videochat_audit_fingerprint($callId . ':owner_absence:' . max(0, $absentSinceMs));
}

function videochat_audit_latest_owner_absence_lifecycle_event(PDO $pdo, string $callId): ?array
{
    $normalizedCallId = trim($callId);
    if ($normalizedCallId === '' || !videochat_audit_bootstrap($pdo)) {
        return null;
    }

    try {
        $query = $pdo->prepare(
            <<<'SQL'
SELECT event_type, resource_fingerprint, payload_json
FROM videochat_audit_events
WHERE call_id = :call_id
  AND event_type IN (
      'call_owner_absence_timer_started',
      'call_owner_absence_timer_cancelled',
      'call_implicitly_ended'
  )
ORDER BY id DESC
LIMIT 1
SQL
        );
        $query->execute([':call_id' => $normalizedCallId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return null;
    }
    if (!is_array($row)) {
        return null;
    }

    $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true);
    return [
        'event_type' => (string) ($row['event_type'] ?? ''),
        'resource_fingerprint' => (string) ($row['resource_fingerprint'] ?? ''),
        'payload' => is_array($payload) ? $payload : [],
    ];
}

function videochat_audit_iam_tenant_id(array $snapshot, array $context): ?int
{
    if (is_numeric($context['tenant_id'] ?? null) && (int) $context['tenant_id'] > 0) {
        return (int) $context['tenant_id'];
    }
    if (is_numeric($snapshot['tenant_id'] ?? null) && (int) $snapshot['tenant_id'] > 0) {
        return (int) $snapshot['tenant_id'];
    }

    return null;
}

function videochat_audit_record_owner_absence_timer_started(PDO $pdo, array $snapshot, array $context = []): array
{
    $callId = trim((string) ($snapshot['call_id'] ?? ''));
    $ownerUserId = (int) ($snapshot['owner_user_id'] ?? 0);
    $resourceFingerprint = videochat_audit_owner_absence_resource_fingerprint($snapshot);
    if ($callId === '' || $ownerUserId <= 0 || $resourceFingerprint === '') {
        return ['ok' => false, 'reason' => 'validation_failed', 'errors' => [], 'event' => null];
    }
    if (videochat_audit_event_already_recorded($pdo, 'call_owner_absence_timer_started', $callId, $resourceFingerprint)) {
        return ['ok' => true, 'reason' => 'already_recorded', 'errors' => [], 'event' => null];
    }

    $roomId = trim((string) ($snapshot['room_id'] ?? ''));
    return videochat_audit_record_event($pdo, [
        'tenant_id' => videochat_audit_iam_tenant_id($snapshot, $context),
        'event_type' => 'call_owner_absence_timer_started',
        'target_user_id' => $ownerUserId,
        'call_id' => $callId,
        'resource_type' => 'call_owner_absence_timer',
        'resource_fingerprint' => $resourceFingerprint,
        'payload' => [
            'audit_scope' => 'iam_owner_absence',
            'action' => 'timer_started',
            'owner_user_id' => $ownerUserId,
            'active_non_owner_count' => max(0, (int) ($snapshot['active_non_owner_count'] ?? 0)),
            'timer_ms' => max(0, (int) ($snapshot['timer_ms'] ?? 0)),
            'countdown_ms' => max(0, (int) ($snapshot['countdown_ms'] ?? 0)),
            'absent_since' => (string) ($snapshot['absent_since'] ?? ''),
            'countdown_starts_at' => (string) ($snapshot['countdown_starts_at'] ?? ''),
            'ends_at' => (string) ($snapshot['ends_at'] ?? ''),
            'room_fingerprint' => $roomId === '' ? '' : videochat_audit_fingerprint($roomId),
            'raw_credential_identifier_logged' => false,
        ],
    ]);
}

function videochat_audit_record_owner_absence_timer_cancelled(PDO $pdo, array $snapshot, array $context = []): array
{
    $callId = trim((string) ($snapshot['call_id'] ?? ($context['call_id'] ?? '')));
    if ($callId === '') {
        return ['ok' => false, 'reason' => 'validation_failed', 'errors' => [], 'event' => null];
    }

    $latest = videochat_audit_latest_owner_absence_lifecycle_event($pdo, $callId);
    if (!is_array($latest) || (string) ($latest['event_type'] ?? '') !== 'call_owner_absence_timer_started') {
        return ['ok' => true, 'reason' => 'no_active_timer', 'errors' => [], 'event' => null];
    }

    $resourceFingerprint = trim((string) ($latest['resource_fingerprint'] ?? ''));
    if ($resourceFingerprint === '') {
        return ['ok' => false, 'reason' => 'validation_failed', 'errors' => [], 'event' => null];
    }
    if (videochat_audit_event_already_recorded($pdo, 'call_owner_absence_timer_cancelled', $callId, $resourceFingerprint)) {
        return ['ok' => true, 'reason' => 'already_recorded', 'errors' => [], 'event' => null];
    }

    $roomId = trim((string) ($snapshot['room_id'] ?? ''));
    $ownerUserId = (int) ($snapshot['owner_user_id'] ?? 0);
    $cancelReason = strtolower(trim((string) ($context['cancel_reason'] ?? 'owner_returned')));
    if (!in_array($cancelReason, ['owner_returned', 'no_remaining_participants'], true)) {
        $cancelReason = 'owner_returned';
    }

    return videochat_audit_record_event($pdo, [
        'tenant_id' => videochat_audit_iam_tenant_id($snapshot, $context),
        'event_type' => 'call_owner_absence_timer_cancelled',
        'target_user_id' => $ownerUserId > 0 ? $ownerUserId : null,
        'call_id' => $callId,
        'resource_type' => 'call_owner_absence_timer',
        'resource_fingerprint' => $resourceFingerprint,
        'payload' => [
            'audit_scope' => 'iam_owner_absence',
            'action' => 'timer_cancelled',
            'cancel_reason' => $cancelReason,
            'owner_present' => (bool) ($snapshot['owner_present'] ?? false),
            'active_non_owner_count' => max(0, (int) ($snapshot['active_non_owner_count'] ?? 0)),
            'room_fingerprint' => $roomId === '' ? '' : videochat_audit_fingerprint($roomId),
            'raw_credential_identifier_logged' => false,
        ],
    ]);
}

function videochat_audit_record_call_implicitly_ended(PDO $pdo, array $snapshot, array $context = []): array
{
    $callId = trim((string) ($snapshot['call_id'] ?? ''));
    $ownerUserId = (int) ($snapshot['owner_user_id'] ?? 0);
    $resourceFingerprint = videochat_audit_owner_absence_resource_fingerprint($snapshot);
    if ($callId === '' || $resourceFingerprint === '') {
        return ['ok' => false, 'reason' => 'validation_failed', 'errors' => [], 'event' => null];
    }
    if (videochat_audit_event_already_recorded($pdo, 'call_implicitly_ended', $callId, $resourceFingerprint)) {
        return ['ok' => true, 'reason' => 'already_recorded', 'errors' => [], 'event' => null];
    }

    $endedReason = strtolower(trim((string) ($snapshot['ended_reason'] ?? 'owner_absent_timeout')));
    if ($endedReason === '' || preg_match('/^[a-z0-9_.:-]{1,120}$/', $endedReason) !== 1) {
        $endedReason = 'owner_absent_timeout';
    }

    $roomId = trim((string) ($snapshot['room_id'] ?? ''));
    return videochat_audit_record_event($pdo, [
        'tenant_id' => videochat_audit_iam_tenant_id($snapshot, $context),
        'event_type' => 'call_implicitly_ended',
        'target_user_id' => $ownerUserId > 0 ? $ownerUserId : null,
        'call_id' => $callId,
        'resource_type' => 'call',
        'resource_fingerprint' => $resourceFingerprint,
        'payload' => [
            'audit_scope' => 'iam_owner_absence',
            'action' => 'implicit_end',
            'ended_reason' => $endedReason,
            'owner_user_id' => $ownerUserId,
            'active_non_owner_count' => max(0, (int) ($snapshot['active_non_owner_count'] ?? 0)),
            'transitioned' => (bool) ($context['transitioned'] ?? true),
            'ended_at' => (string) ($snapshot['ended_at'] ?? ''),
            'room_fingerprint' => $roomId === '' ? '' : videochat_audit_fingerprint($roomId),
            'raw_credential_identifier_logged' => false,
        ],
    ]);
}
